<?php

declare(strict_types=1);

namespace Scout\Job\Cli;

use Scout\Adapters\FeedFreshness;
use Scout\Adapters\Http\HttpClient;
use Scout\Adapters\Mail\FileMailbox;
use Scout\Adapters\Mail\ImapMailbox;
use Scout\Adapters\Mail\Mailbox;
use Scout\Adapters\SourceError;
use Scout\Cli\ChannelFactory;
use Scout\Cli\WatchLoop;
use Scout\Config\ConfigError;
use Scout\Core\CountsPatternMisses;
use Scout\Core\Heartbeat;
use Scout\Core\Notify\Notification;
use Scout\Core\Notify\NotificationKind;
use Scout\Core\Notify\Notifier;
use Scout\Core\Notify\Priority;
use Scout\Core\Pacer;
use Scout\Core\Redact;
use Scout\Core\SourceStatus;
use Scout\Job\JobClassifier;
use Scout\Job\JobCriteria;
use Scout\Job\JobCriteriaLoader;
use Scout\Job\JobEmailSource;
use Scout\Job\JobFormatter;
use Scout\Job\JobListing;
use Scout\Job\JobOutcome;
use Scout\Job\JobPipeline;
use Scout\Job\JobRunResult;
use Scout\Job\JobScorer;
use Scout\Job\JobSource;
use Scout\Job\JobSourceDefinition;
use Scout\Job\JobSourceLoader;
use Scout\Job\JobStore;
use Scout\Job\JobVerdict;
use Scout\Rent\Core\DigestSchedule;

/**
 * `scout --domain=job …` — the job watcher's verbs, on its own database, config, mailbox folder and
 * push topic. Rulings: `docs/plans/job-domain.plan.md`.
 *
 * `CarScout` is the template, verb for verb, and what it keeps it keeps EXACTLY: the Q36 refusal on
 * an empty seen-set, the Q27 refusal note (cleared only once a beat delivered it, with a forced beat
 * under `--once`), the Q37 pacer with the heartbeat and the rollup floor in `finally`,
 * `SCOUT_MAX_PASSES`, the injected Notifier seam, and `--source=` force-running a disabled block. Each
 * of those rules was paid for on the rent or car side, most of them twice; the reasoning is written
 * beside the car copy and not restated here.
 *
 * What differs: no web source (slice 1 reads email only); a drain that records what an offer was
 * announced AS — `ROLLUP` for the rollup, `MATCH` for a retry — so a rolled-up offer can still be
 * promoted to a push; and a `dump` that lists the model's fields by reflection.
 */
final readonly class JobScout
{
    private const string DEFAULT_DB = 'state/job-watch.sqlite3';
    private const string DEFAULT_FOLDER = 'job-watch/portails';

    /** @var resource */
    private mixed $out;
    /** @var resource */
    private mixed $err;

    public function __construct(
        private string $rootDir,
        mixed $out = null,
        mixed $err = null,
        private ?string $nowIso = null,
        /** The dispatcher's seam, kept in its position: slice 1 has no web source, so nothing reads it yet. */
        ?HttpClient $http = null,
        private ?Notifier $notifier = null,
    ) {
        $this->out = $out ?? STDOUT;
        $this->err = $err ?? STDERR;
    }

    /** @param list<string> $argv */
    public function run(array $argv): int
    {
        $command = $argv[0] ?? 'help';
        $flags = array_slice($argv, 1);

        try {
            return match ($command) {
                'doctor' => $this->doctor($flags),
                'dump' => $this->dump($flags),
                'run' => $this->runCommand($flags),
                'test-notify' => $this->testNotify(),
                'rollup' => $this->rollup($flags),
                'help', '--help', '-h' => $this->help(0),
                default => $this->fail('commande inconnue : ' . $command) + $this->help(2) - 2,
            };
        } catch (ConfigError $e) {
            return $this->refuse($command, 'configuration : ' . $e->getMessage());
        } catch (SourceError $e) {
            return $this->refuse($command, 'source ' . $e->sourceName . ' : ' . Redact::text($e->getMessage()));
        } catch (\RuntimeException $e) {
            return $this->refuse($command, Redact::text($e->getMessage()) ?? 'erreur');
        }
    }

    // ── verbs ───────────────────────────────────────────────────────────────────────────────────

    /** @param list<string> $flags */
    private function doctor(array $flags): int
    {
        // Before `criteria()`: a malformed config is the commonest startup refusal, and a report placed
        // after the bootstrap could never reach it. Read-only, so a diagnostic cannot swallow what a
        // channel still owes.
        $pending = $this->pendingRefusal();

        if ($pending !== null) {
            $this->line('  refus    : ' . $pending);
            $this->line('             (refus au démarrage précédent — repris au prochain battement de cœur : sous --watch, ou après une passe --once réussie)');
        }

        $criteria = $this->criteria();
        $store = $this->store();
        $now = $this->now();
        $this->line('job-watch · base ' . $this->dbPath() . ' · journal ' . $store->journalMode() . ' · schéma offres ' . JobStore::SCHEMA_VERSION);
        $counts = $store->counts();
        $this->line(sprintf('  seen-set : %d offre(s), %d notifiée(s), %d correspondance(s)%s', $counts['count'], $counts['notified'], $counts['matches'], $store->isSeenSetEmpty() ? ' — VIDE : `run --once --seed` avant `--watch` (Q36)' : ''));

        // The queue is a fact the developer looks for HERE first: a quiet phone under a gate is not a quiet market.
        $queued = $store->pendingRollupCount();
        $notify = $criteria->notify;
        if ($notify->pushMinScore !== null) {
            $this->line(sprintf('  rollup   : seuil %d — %d correspondance(s) en attente du récapitulatif « vérifié, score bas »%s', $notify->pushMinScore, $queued, $notify->rollupHour === null ? ' (verbe `rollup` seulement, pas de plancher quotidien)' : sprintf(' (plancher quotidien à %dh sous --watch UNIQUEMENT, marqueur state/job-rollup.txt ; sous --once, planifier le verbe)', $notify->rollupHour)));
        } elseif ($queued > 0) {
            $this->line(sprintf('  rollup   : %d correspondance(s) jugée(s) et jamais notifiée(s) — aucun seuil configuré, `scout --domain=job rollup` les émet', $queued));
        }

        $notifier = $this->notifier($criteria);
        $this->line('  canaux   : ' . ($notifier->hasRemoteChannel() ? 'au moins un canal atteint un destinataire' : 'AUCUN canal n\'atteint de destinataire'));
        foreach ($notifier->inventory() as $channel) {
            $this->line(sprintf('             - %-8s %s [%s]', $channel['name'], $channel['describe'], $channel['counts'] ? 'compte comme délivré' : 'NE COMPTE PAS'));
        }
        $this->line(sprintf(
            '  critères : salaire ≥ %s € brut/an · TJM ≥ %d € · contrats écartés %s · nationalité française %s',
            number_format($criteria->salaryFloorEur, 0, ',', ' '),
            $criteria->tjmFloorEur,
            $criteria->rejectedContracts === [] ? '(aucun)' : implode(', ', $criteria->rejectedContracts),
            $criteria->frenchNationality ? 'oui' : 'non',
        ));
        $this->line('');

        // The band advice both other doctors give: `IMAP_SINCE_DAYS` is shared, so a threshold at or
        // past the window leaves no observable band between `feed_silent` and `broken`.
        $windowNote = ImapMailbox::feedSilentWindowNote($this->feedSilentDays(), 'JOB_FEED_SILENT_DAYS');
        if ($windowNote !== null) {
            $this->warn($windowNote);
        }
        foreach ($this->definitions() as $definition) {
            if ($definition->feedSilentDays === null) {
                continue;
            }
            $note = ImapMailbox::feedSilentWindowNote($definition->feedSilentDays, 'feed_silent_days de ' . $definition->name);
            if ($note !== null) {
                $this->warn($note);
            }
        }

        $sources = $this->sources($store, $this->onlySources($flags));
        if ($sources === []) {
            $this->line('  aucune source activée.');

            return 0;
        }
        $this->line(sprintf('  %-12s %-13s %6s %8s  %s', 'SOURCE', 'ÉTAT', 'ITEMS', 'DURÉE', 'DÉTAIL'));
        $problems = 0;
        foreach ($sources as $source) {
            $started = microtime(true);
            try {
                $items = count($source->fetch());
                $ms = (int) ((microtime(true) - $started) * 1000);
                $store->runs()->recordRun($source->name(), $items, true, null, $now, $ms, $source instanceof FeedFreshness ? $source->newestFeedItemAt() : null);
            } catch (\Throwable $e) {
                $ms = (int) ((microtime(true) - $started) * 1000);
                $store->runs()->recordRun($source->name(), 0, false, $e->getMessage(), $now, $ms);
                $this->line(sprintf('  %-12s %-13s %6s %6d ms  %s', $source->name(), 'ERREUR', '-', $ms, Redact::text($e->getMessage())));
                ++$problems;
                continue;
            }
            $health = $source->health($now);
            if ($health->status !== SourceStatus::OK) {
                ++$problems;
            }
            $this->line(sprintf('  %-12s %-13s %6d %6d ms  %s', $source->name(), $health->status->value, $items, $ms, $health->detail));

            // Per-pattern misses (F27), printed only when something missed, gated on the INTERFACE.
            if ($source instanceof CountsPatternMisses) {
                foreach ($source->patternMisses()->counts() as $key => $c) {
                    if ($c['misses'] === 0) {
                        continue;
                    }
                    $this->line(sprintf(
                        '                     %-18s %d/%d sans résultat%s',
                        $key,
                        $c['misses'],
                        $c['calls'],
                        $c['misses'] === $c['calls'] ? '  ← AUCUN : gabarit changé ?' : '',
                    ));
                }
            }
        }

        return $problems === 0 ? 0 : 1;
    }

    /** @param list<string> $flags */
    private function dump(array $flags): int
    {
        $name = self::firstBare($flags);
        if ($name === null) {
            return $this->fail('usage : scout --domain=job dump <source>');
        }
        $definitions = $this->definitions();
        if (!isset($definitions[$name])) {
            return $this->fail('source inconnue : ' . $name . ' (connues : ' . implode(', ', array_keys($definitions)) . ')');
        }
        $offers = $this->buildSource($definitions[$name], $this->store())->fetch();
        if ($offers === []) {
            $this->line('la source a répondu sans erreur mais n\'a produit aucune offre.');

            return 1;
        }
        $offer = $offers[0];
        $this->line('— offre lue (' . count($offers) . ' au total) —');
        // BY REFLECTION, so a field added to the model cannot silently vanish from the one command that
        // shows what a source read.
        foreach ((new \ReflectionClass(JobListing::class))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $this->line(sprintf('  %-13s %s', $property->getName(), self::show($property->getValue($offer))));
        }

        $facts = (new JobClassifier())->read($offer);
        $this->line('');
        $this->line('— lecture —');
        foreach (get_object_vars($facts) as $field => $value) {
            $this->line(sprintf('  %-13s %s', $field, self::show($value)));
        }

        $verdict = (new JobScorer())->judge($offer, $facts, $this->criteria(), new \DateTimeImmutable($this->now()));
        $this->line('');
        $this->line('  verdict       ' . $verdict->outcome->value . ($verdict->score === null ? '' : ' ' . $verdict->score . '/100'));
        foreach ($verdict->reasons as $reason) {
            $this->line('                · ' . $reason);
        }

        return 0;
    }

    /** @param list<string> $flags */
    private function runCommand(array $flags): int
    {
        $verbose = in_array('-v', $flags, true) || in_array('--verbose', $flags, true);
        $seed = in_array('--seed', $flags, true);
        $watch = in_array('--watch', $flags, true);
        if ($watch && $seed) {
            return $this->failRun('`--seed` amorce le seen-set en une passe ; combiné à `--watch` il n\'émettrait jamais rien. Lancez `run --once --seed`, puis `--watch`.');
        }
        $criteria = $this->criteria();
        $store = $this->store();

        // Q36, the job analog: an empty seen-set is a fresh file or a missing mount, and the
        // alternative is pushing a whole week of alerts at once.
        if (!$seed && $store->isSeenSetEmpty()) {
            return $this->failRun('seen-set offres VIDE : `scout --domain=job run --once --seed` d\'abord — sinon toute la fenêtre d\'alertes serait notifiée d\'un coup (Q36)');
        }

        $notifier = $this->notifier($criteria);
        $sources = $this->sources($store, $this->onlySources($flags));
        if ($sources === []) {
            return $this->failRun('aucune source activée');
        }
        $pipeline = new JobPipeline($criteria, $store, $notifier);

        if (!$watch) {
            $result = $pipeline->runOnce($sources, $this->now(), $seed);
            $this->report($result, $seed, $verbose);
            $code = $result->sourcesFailed > 0 && $result->sourcesRun === 0 ? 1 : 0;

            // A PENDING REFUSAL FORCES ONE BEAT: `--once` has no beat of its own, so on a cron
            // deployment the note would otherwise be written by every refused run and read by nothing.
            // Delivering it clears it, so it cannot repeat; `--seed` notifies nothing by construction.
            if ($code === 0 && !$seed && $this->pendingRefusal() !== null) {
                $health = array_map(fn (JobSource $s) => $s->health($this->now()), $sources);
                $failures = $notifier->send((new JobFormatter())->heartbeat(1, $result->notified, $health, $this->now(), $this->pendingRefusal()));
                foreach ($failures as $failure) {
                    $this->warn(Redact::text($failure->getMessage()));
                }
                if ($notifier->delivered($failures)) {
                    $this->clearLastRefusal();
                }
            }

            return $code;
        }

        return $this->watch($pipeline, $sources, $store, $notifier, $criteria, $verbose);
    }

    /** @param list<JobSource> $sources */
    private function watch(JobPipeline $pipeline, array $sources, JobStore $store, Notifier $notifier, JobCriteria $criteria, bool $verbose): int
    {
        // Every job source is email (`host(): null`), so pass-level pacing is the whole of Q37 here —
        // the car domain's reasoning, which names when a web source would change that.
        $loop = null;
        $pacer = new Pacer(
            clock: static fn (): float => hrtime(true) / 1_000_000_000.0,
            sleeper: WatchLoop::interruptibleSleeper(static function () use (&$loop): bool {
                return $loop !== null && $loop->isStopping();
            }),
            rand: static fn (int $min, int $max): int => random_int($min, $max),
        );
        try {
            $heartbeat = Heartbeat::fromEnv(($raw = getenv('JOB_HEARTBEAT_HOURS')) === false ? null : $raw, 'JOB_HEARTBEAT_HOURS');
        } catch (\InvalidArgumentException $e) {
            // A LOUD REFUSAL: `0` would disable the one signal that tells a dead watcher from a quiet market.
            return $this->failRun($e->getMessage());
        }
        $maxPasses = $this->maxPasses();
        $passes = 0;
        $notified = 0;
        $failedPasses = 0;
        $formatter = new JobFormatter();

        $this->line(sprintf('job-watch · surveillance active · %d source(s) · toutes les %d min ± %d (Q37)%s', count($sources), (int) (Pacer::PASS_INTERVAL_SECONDS / 60), (int) (Pacer::JITTER_SECONDS / 60), $maxPasses === null ? '' : ' · SCOUT_MAX_PASSES=' . $maxPasses));

        // The refusal note is read inside the beat and cleared only once the beat is DELIVERED: the
        // marker is not written on a failed delivery so the beat retries, and the retry must carry it.
        $beat = function () use (&$passes, &$notified, &$failedPasses, $sources, $notifier, $formatter): void {
            $health = array_map(fn (JobSource $s) => $s->health($this->now()), $sources);
            $n = $formatter->heartbeat($passes, $notified, $health, $this->now(), $this->pendingRefusal(), $failedPasses);
            if ($notifier->delivered($notifier->send($n))) {
                @file_put_contents($this->stateFile('job-heartbeat.txt'), $this->now());
                $this->clearLastRefusal();
            }
        };
        if ($heartbeat->isDue($this->lastHeartbeat(), $this->now())) {
            $beat();
        }

        // THE ROLLUP FLOOR, before the first pass — a queue left when the container last stopped is
        // exactly what a restart should drain — silent on a day with nothing queued, and its marker
        // written only after the channel confirms.
        $rollupSchedule = $criteria->notify->rollupHour === null ? null : new DigestSchedule($criteria->notify->rollupHour);
        $rollupZone = DigestSchedule::zoneFromEnv(($tz = getenv('TZ')) === false ? null : $tz);
        if ($rollupSchedule !== null && $rollupSchedule->isDue($this->lastRollup(), $this->now(), $rollupZone)) {
            $this->floorRollup($notifier, $store, $this->now());
        }

        $loop = new WatchLoop(
            pass: function () use ($pipeline, $sources, &$passes, &$notified, &$failedPasses, $verbose, $heartbeat, $beat, $rollupSchedule, $rollupZone, $notifier, $store): void {
                // `finally`, because `WatchLoop` catches what this closure throws: a beat placed after
                // the work would be skipped by every pass that dies, which is exactly the state the beat
                // exists to tell apart from a quiet market.
                $threw = true;

                try {
                    $result = $pipeline->runOnce($sources, $this->now());
                    ++$passes;
                    $notified += $result->notified;
                    $this->report($result, false, $verbose, true);
                    $threw = false;
                } finally {
                    if ($threw) {
                        ++$failedPasses;
                    }

                    // The beat's own failure must not mask the pass's.
                    try {
                        if ($heartbeat->isDue($this->lastHeartbeat(), $this->now())) {
                            $beat();
                        }
                    } catch (\Throwable $beatFailure) {
                        $this->warn('battement de cœur non émis : ' . Redact::text($beatFailure->getMessage()));
                    }

                    try {
                        if ($rollupSchedule !== null && $rollupSchedule->isDue($this->lastRollup(), $this->now(), $rollupZone)) {
                            $this->floorRollup($notifier, $store, $this->now());
                        }
                    } catch (\Throwable $rollupFailure) {
                        $this->warn('récapitulatif quotidien non émis : ' . Redact::text($rollupFailure->getMessage()));
                    }
                }
            },
            pacer: $pacer,
            onError: function (\Throwable $e): void {
                $this->warn('passe échouée : ' . Redact::text($e->getMessage()));
            },
        );

        return $loop->run($maxPasses);
    }

    private function testNotify(): int
    {
        $notifier = $this->notifier($this->criteria());
        $failures = $notifier->send(new Notification(
            kind: NotificationKind::HEARTBEAT,
            priority: Priority::LOW,
            title: 'job-watch : test de notification',
            reasons: ['si ce message vous parvient, le canal du job-watch est utilisable'],
        ));
        if (!$notifier->hasRemoteChannel()) {
            return $this->fail('aucun canal n\'atteint un destinataire : la console seule ne prouve rien');
        }

        return $notifier->delivered($failures) ? 0 : $this->fail('envoi échoué : ' . implode(' ; ', array_map(static fn ($f) => (string) $f, $failures)));
    }

    private function help(int $code): int
    {
        $this->line('scout --domain=job — veille sur les offres d\'emploi');
        $this->line('');
        $this->line('  scout --domain=job doctor                 état de chaque source, seen-set, canaux');
        $this->line('  scout --domain=job dump <source>          première offre lue + lecture + verdict');
        $this->line('  scout --domain=job run --once [-v]        une passe');
        $this->line('  scout --domain=job run --once --seed      amorce le seen-set sans notifier (obligatoire avant --watch)');
        $this->line('  scout --domain=job run --watch [-v]       boucle Q37, battement Q27 (state/job-heartbeat.txt)');
        $this->line('  … --source=<nom>                          limite à une source (répétable ; force une source désactivée)');
        $this->line('  scout --domain=job test-notify            vérifie le canal du job-watch');
        $this->line('  scout --domain=job rollup [--dry-run]     émet le récapitulatif « vérifié, score bas » en attente');
        $this->line('');
        $this->line('  config : config/job/criteria.json (+ criteria.local.json), config/job/sources.json');
        $this->line('  env    : JOB_SCOUT_DB, JOB_IMAP_MAILBOX, JOB_NTFY_TOPIC, JOB_HEARTBEAT_HOURS, JOB_FEED_SILENT_DAYS');
        $this->line('           (les identifiants IMAP/SMTP, NTFY_SERVER, IMAP_SINCE_DAYS et IMAP_MAX_MESSAGES sont partagés)');

        return $code;
    }

    // ── the rollup: the verb and its daily floor ───────────────────────────────────────────────

    /**
     * `scout --domain=job rollup [--dry-run]` — the on-demand drain: every MATCH no announcement
     * covers, re-judged, pushed again when it clears the gate and rolled up otherwise, each marked
     * only after the channel confirms.
     *
     * @param list<string> $flags
     */
    private function rollup(array $flags): int
    {
        $dryRun = in_array('--dry-run', $flags, true);
        foreach ($flags as $flag) {
            if ($flag !== '--dry-run') {
                return $this->fail('option inconnue : ' . $flag . ' (connue : --dry-run)');
            }
        }
        $store = $this->store();
        [$entries, $waiting, $warnings, $retries] = $this->collectRollup($store);
        foreach ($warnings as $warning) {
            $this->warn($warning);
        }
        if ($entries === [] && $retries === []) {
            $this->line('Aucune offre en attente du récapitulatif « vérifié, score bas ».');

            return 0;
        }
        $formatter = new JobFormatter();
        foreach ($retries as $retry) {
            $this->line('[RETRY] ' . $formatter->match($retry['offer'], $retry['verdict'])->title);
        }
        $notification = $formatter->rollup($entries);
        if ($entries !== []) {
            $this->line($notification->title);
            foreach ($notification->reasons as $line) {
                $this->line($line);
            }
        }
        if ($dryRun) {
            // Nothing attempted, but the batch AND the retries were both listed above: the remainder
            // means "beyond what you were just shown".
            $this->reportRollupRemainder($waiting, $entries, count($retries), true);
            $this->line('--dry-run : rien n\'a été envoyé, rien n\'a été marqué comme émis.');

            return 0;
        }
        $notifier = $this->notifier($this->criteria());
        $fatal = $notifier->fatalProblem();
        if ($fatal !== null) {
            return $this->fail($fatal);
        }
        $drained = $this->pushRetries($notifier, $store, $retries, $this->now());
        if ($entries === []) {
            $this->reportRollupRemainder($waiting, $entries, $drained, false);

            return 0;
        }
        $failures = $notifier->send($notification);
        foreach ($failures as $failure) {
            $this->warn(Redact::text($failure->getMessage()));
        }
        if (!$notifier->delivered($failures)) {
            $this->reportRollupRemainder($waiting, $entries, $drained, false);
            $this->warn('récapitulatif non délivré — rien n\'a été marqué comme émis, il sera réessayé.');

            return 1;
        }
        $this->reportRollupRemainder($waiting, $entries, $drained, true);
        foreach ($entries as $entry) {
            $store->markNotified($entry['key'], $this->now(), JobStore::AS_ROLLUP);
        }
        $this->line(count($entries) . ' offre(s) émise(s).');

        return 0;
    }

    /**
     * The queue, decoded and RE-JUDGED. It never prints. It can throw — it reads the config and runs
     * the scorer — and the floor's call site catches that, exactly as the car floor's does.
     *
     * @return array{0: list<array{offer: JobListing, score: ?int, key: string}>, 1: int, 2: list<string>, 3: list<array{offer: JobListing, verdict: JobVerdict, key: string}>}
     */
    private function collectRollup(JobStore $store): array
    {
        $entries = [];
        $retries = [];
        $warnings = [];
        $criteria = $this->criteria();
        $pushMin = $criteria->notify->pushMinScore;
        $scorer = new JobScorer();
        $classifier = new JobClassifier();
        $now = new \DateTimeImmutable($this->now());

        foreach ($store->pendingRollup() as $row) {
            $offer = null;
            $unreadable = false;
            try {
                $offer = $store->snapshot($row['dedup_key']);
            } catch (\RuntimeException $e) {
                $unreadable = true;
                $warnings[] = sprintf('instantané illisible pour %s — offre annoncée depuis les colonnes stockées, score conservé (%s)', $row['dedup_key'], Redact::text($e->getMessage()));
            }

            // A COMPUTED REJECT IS AN ANSWER, NOT A MISSING ONE (the car drain's C2 round-7 P0): an
            // offer today's rules reject is left waiting and said out loud, never announced on its
            // stored score.
            $verdict = null;
            if ($offer !== null) {
                $judged = $scorer->judge($offer, $classifier->read($offer), $criteria, $now);
                if ($judged->outcome !== JobOutcome::MATCH) {
                    $warnings[] = sprintf('%s re-jugée %s aujourd\'hui — laissée en attente', $row['dedup_key'], $judged->outcome->value);

                    continue;
                }
                $verdict = $judged;
            }

            $offer ??= new JobListing(sourceName: $row['source'], externalId: $row['external_id'], title: $row['title'], company: $row['company'], url: $row['url']);
            $score = $verdict?->score ?? ($row['score'] === null ? null : (int) $row['score']);

            // A RETRY, NOT A ROLLUP: the queue cannot tell an offer held back by the gate from one
            // whose push failed, and with no gate every queued offer is the latter. At or over the
            // line it goes out as the match it is, never filed under « score bas ».
            if ($pushMin === null || ($score !== null && $score >= $pushMin)) {
                $retries[] = [
                    'offer' => $offer,
                    'verdict' => $verdict ?? JobVerdict::matched($score ?? 0, [$unreadable ? 'réémission — instantané illisible, score conservé' : 'réémission — instantané absent, score conservé']),
                    'key' => $row['dedup_key'],
                ];

                continue;
            }
            $entries[] = ['offer' => $offer, 'score' => $score, 'key' => $row['dedup_key']];
        }

        return [$entries, $store->pendingRollupCount(), $warnings, $retries];
    }

    /**
     * Push the drain's retries as the individual matches they are, marked `MATCH` on delivery. Shared
     * by the verb and the floor, so the floor cannot forget what the verb does.
     *
     * @param list<array{offer: JobListing, verdict: JobVerdict, key: string}> $retries
     *
     * @return int how many the channel took — never how many were attempted
     */
    private function pushRetries(Notifier $notifier, JobStore $store, array $retries, string $now): int
    {
        $delivered = 0;
        $formatter = new JobFormatter();
        foreach ($retries as $entry) {
            $failures = $notifier->send($formatter->match($entry['offer'], $entry['verdict']));
            foreach ($failures as $failure) {
                $this->warn(Redact::text($failure->getMessage()));
            }
            if (!$notifier->delivered($failures)) {
                $this->warn(sprintf('réémission non délivrée pour %s — laissée en attente.', $entry['key']));

                continue;
            }
            $store->markNotified($entry['key'], $now, JobStore::AS_MATCH);
            ++$delivered;
        }
        if ($retries !== []) {
            $this->line(sprintf('%d correspondance(s) réémise(s) individuellement — au niveau du seuil ou sans seuil, jamais « score bas » (%d délivrée(s)).', count($retries), $delivered));
        }

        return $delivered;
    }

    /** The daily floor — silent when nothing is queued, its marker written only after delivery. */
    private function floorRollup(Notifier $notifier, JobStore $store, string $now): void
    {
        [$entries, $waiting, $warnings, $retries] = $this->collectRollup($store);
        // WARNINGS FIRST: an empty batch can carry them, and `--watch` is the deployed mode.
        foreach ($warnings as $warning) {
            $this->warn($warning);
        }
        if ($entries === [] && $retries === []) {
            return;
        }
        $drained = $this->pushRetries($notifier, $store, $retries, $now);
        if ($entries === []) {
            return;
        }
        $failures = $notifier->send((new JobFormatter())->rollup($entries));
        foreach ($failures as $failure) {
            $this->warn(Redact::text($failure->getMessage()));
        }
        if (!$notifier->delivered($failures)) {
            $this->warn('récapitulatif quotidien non délivré — rien marqué, nouvel essai au prochain passage.');

            return;
        }
        foreach ($entries as $entry) {
            $store->markNotified($entry['key'], $now, JobStore::AS_ROLLUP);
        }
        @file_put_contents($this->stateFile('job-rollup.txt'), $now);
        $remaining = $this->remainingAfterDrain($waiting, $entries, $drained, true);
        $this->line(sprintf('récapitulatif quotidien « vérifié, score bas » : %d offre(s) émise(s)%s.', count($entries), $remaining > 0 ? sprintf(' — %d autre(s) en attente', $remaining) : ''));
    }

    /** @param list<array<string, mixed>> $entries */
    private function reportRollupRemainder(int $waiting, array $entries, int $drained, bool $announced): void
    {
        $remaining = $this->remainingAfterDrain($waiting, $entries, $drained, $announced);
        if ($remaining > 0) {
            $this->line(sprintf('%d autre(s) en attente — relancer `scout --domain=job rollup` pour la suite.', $remaining));
        }
    }

    /**
     * ONE arithmetic for the verb and the floor. `$waiting` counts every queued row, retries included,
     * so what the mail announced and what the retries actually drained both come off it. `$announced`
     * is a DELIVERY fact with no default, because both defaults lie in one direction.
     *
     * @param list<array<string, mixed>> $entries
     */
    private function remainingAfterDrain(int $waiting, array $entries, int $drained, bool $announced): int
    {
        return $waiting - ($announced ? count($entries) : 0) - $drained;
    }

    // ── wiring ──────────────────────────────────────────────────────────────────────────────────

    private function criteria(): JobCriteria
    {
        return JobCriteriaLoader::load($this->rootDir . '/config/job/criteria.json', $this->rootDir . '/config/job/criteria.local.json');
    }

    /** @return array<string, JobSourceDefinition> */
    private function definitions(): array
    {
        return JobSourceLoader::load($this->rootDir . '/config/job/sources.json');
    }

    private function store(): JobStore
    {
        return JobStore::open($this->dbPath(), $this->feedSilentDays());
    }

    /** `JOB_FEED_SILENT_DAYS` — the domain's feed-silence threshold; a source block's own `feed_silent_days` outranks it. */
    private function feedSilentDays(): int
    {
        $raw = getenv('JOB_FEED_SILENT_DAYS');
        if ($raw === false || trim($raw) === '') {
            return 3;
        }
        if (!ctype_digit(trim($raw)) || (int) $raw < 1) {
            throw ConfigError::at('JOB_FEED_SILENT_DAYS', 'doit être un entier de jours ≥ 1 — 0 désactiverait la détection de flux muet, reçu ' . var_export($raw, true));
        }

        return (int) $raw;
    }

    private function dbPath(): string
    {
        $raw = (string) (getenv('JOB_SCOUT_DB') ?: '');
        if ($raw === '') {
            return $this->rootDir . '/' . self::DEFAULT_DB;
        }

        return str_starts_with($raw, '/') || $raw === ':memory:' ? $raw : $this->rootDir . '/' . $raw;
    }

    private function notifier(JobCriteria $criteria): Notifier
    {
        if ($this->notifier !== null) {
            return $this->notifier;
        }
        $channels = [];
        foreach ($criteria->notify->channels as $name) {
            $channels[] = ChannelFactory::build($name, $this->out, $this->rootDir, '[job-watch]', 'job-watch@localhost', (string) (getenv('JOB_NTFY_TOPIC') ?: ''), 'JOB_NTFY_TOPIC', '💼 JOB ·', 'briefcase');
        }

        return new Notifier($channels);
    }

    /**
     * @param ?list<string> $only
     *
     * @return list<JobSource>
     */
    private function sources(JobStore $store, ?array $only): array
    {
        $definitions = $this->definitions();
        $out = [];
        foreach ($definitions as $name => $definition) {
            $forced = $only !== null && in_array($name, $only, true);
            if ($only !== null && !$forced) {
                continue;
            }
            if (!$definition->enabled && !$forced) {
                continue;
            }
            if (!$definition->enabled) {
                $this->warn('source ' . $name . ' est `enabled: false` — forcée par --source=');
            }
            $out[] = $this->buildSource($definition, $store);
        }
        foreach ($only ?? [] as $name) {
            if (!isset($definitions[$name])) {
                $this->warn('source inconnue ignorée : ' . $name);
            }
        }

        return $out;
    }

    /** The loader refuses any type but `email_alert`, so this match has exactly the arms that can arrive. */
    private function buildSource(JobSourceDefinition $definition, JobStore $store): JobSource
    {
        return match ($definition->type) {
            'email_alert' => new JobEmailSource(
                $definition,
                $store,
                $this->mailbox($definition),
                fn (string $m) => $this->warn($m),
                ImapMailbox::maxMessages(getenv('IMAP_MAX_MESSAGES') ?: null),
            ),
        };
    }

    private function mailbox(JobSourceDefinition $definition): Mailbox
    {
        $dir = (string) (getenv('MAILBOX_DIR') ?: '');
        if ($dir !== '') {
            return new FileMailbox(str_starts_with($dir, '/') ? $dir : $this->rootDir . '/' . $dir);
        }
        $host = (string) (getenv('IMAP_HOST') ?: '');
        if ($host === '') {
            throw ConfigError::at('.env', 'ni MAILBOX_DIR ni IMAP_HOST ne sont définis — la source ' . $definition->name . ' ne peut lire aucun courrier');
        }

        return new ImapMailbox(
            host: $host,
            user: (string) (getenv('IMAP_USER') ?: ''),
            password: (string) (getenv('IMAP_PASSWORD') ?: ''),
            folder: (string) (getenv('JOB_IMAP_MAILBOX') ?: self::DEFAULT_FOLDER),
            port: (int) (getenv('IMAP_PORT') ?: 993),
            fromFilter: $definition->param('from'),
            sinceDays: max(1, (int) (getenv('IMAP_SINCE_DAYS') ?: 7)),
            warn: fn (string $m) => $this->warn($m),
        );
    }

    /**
     * @param list<string> $flags
     *
     * @return ?list<string>
     */
    private function onlySources(array $flags): ?array
    {
        $only = [];
        foreach ($flags as $flag) {
            if (str_starts_with($flag, '--source=')) {
                $only[] = substr($flag, 9);
            }
        }

        return $only === [] ? null : $only;
    }

    /** @param bool $watching the daily floor runs under `--watch` only, so the held-back line names the drain THIS mode has */
    private function report(JobRunResult $r, bool $seed, bool $verbose, bool $watching = false): void
    {
        $this->line(sprintf('%d source(s), %d offre(s) analysée(s) · %d correspondance(s), %d écartée(s), %d notifiée(s)%s', $r->sourcesRun, $r->itemsParsed, $r->matches, $r->rejectedCount, $r->notified, $r->undelivered > 0 ? ', ' . $r->undelivered . ' NON délivrée(s)' : ''));
        if ($seed) {
            $this->line('mode --seed : seen-set amorcé, aucune notification envoyée.');
        }
        foreach ($r->errors as $error) {
            $this->warn($error);
        }
        foreach ($r->warnings as $warning) {
            $this->warn($warning);
        }
        if ($r->queuedLowScore > 0) {
            // Said on every pass that holds something back, so a quiet phone is never read as a quiet market.
            $this->line(sprintf(
                '%d correspondance(s) sous le seuil de notification individuelle — %s',
                $r->queuedLowScore,
                $watching
                    ? 'en attente du récapitulatif (« vérifié, score bas »)'
                    : 'en attente de `scout --domain=job rollup` (« vérifié, score bas ») — le plancher quotidien ne tourne que sous --watch',
            ));
        }
        if ($verbose) {
            foreach ($r->rejected as $line) {
                $this->line('  ' . $line);
            }
        }
    }

    private function maxPasses(): ?int
    {
        $raw = getenv('SCOUT_MAX_PASSES');
        if ($raw === false || trim($raw) === '') {
            return null;
        }
        if (!ctype_digit(trim($raw)) || (int) $raw < 1) {
            throw ConfigError::at('SCOUT_MAX_PASSES', 'doit être un entier ≥ 1, reçu ' . var_export($raw, true));
        }

        return (int) $raw;
    }

    private function lastRollup(): ?string
    {
        return $this->marker('job-rollup.txt');
    }

    private function lastHeartbeat(): ?string
    {
        return $this->marker('job-heartbeat.txt');
    }

    private function marker(string $name): ?string
    {
        $path = $this->stateFile($name);
        if (!is_file($path)) {
            return null;
        }
        $v = @file_get_contents($path);

        return $v === false || trim($v) === '' ? null : trim($v);
    }

    private function stateFile(string $name): string
    {
        // `dbPath()` passes `:memory:` through, and `dirname(':memory:')` is `.` — without this the
        // markers would land in whatever the process cwd happens to be.
        $db = $this->dbPath();
        $dir = $db === ':memory:' ? $this->rootDir . '/state' : \dirname($db);

        return $dir . '/' . $name;
    }

    private function now(): string
    {
        return $this->nowIso ?? (new \DateTimeImmutable())->format('Y-m-d\TH:i:sP');
    }

    private static function show(mixed $value): string
    {
        return match (true) {
            $value === null => '(null)',
            is_bool($value) => var_export($value, true),
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        };
    }

    /** @param list<string> $flags */
    private static function firstBare(array $flags): ?string
    {
        foreach ($flags as $f) {
            if (!str_starts_with($f, '-')) {
                return $f;
            }
        }

        return null;
    }

    private function line(string $text): void
    {
        fwrite($this->out, $text . "\n");
    }

    private function warn(string $text): void
    {
        fwrite($this->err, '⚠ ' . $text . "\n");
    }

    private function fail(string $text): int
    {
        fwrite($this->err, $text . "\n");

        return 2;
    }

    /** A refusal of `run` is RECORDED for the next successful start to report (Q27); any other verb's is stderr only. */
    private function refuse(string $command, string $text): int
    {
        return $command === 'run' ? $this->failRun($text) : $this->fail($text);
    }

    /** Q27: under `restart: unless-stopped` a startup refusal is a crash loop whose stderr nobody reads. Redacted before it touches the disk. */
    private function failRun(string $text): int
    {
        @file_put_contents($this->stateFile('job-last-refusal.txt'), $this->now() . ' — ' . Redact::text($text) . "\n");

        return $this->fail($text);
    }

    /** Cleared only once a beat has DELIVERED the note. */
    private function clearLastRefusal(): void
    {
        @unlink($this->stateFile('job-last-refusal.txt'));
    }

    /** The pending note WITHOUT consuming it — `doctor` reports, the heartbeat consumes. */
    private function pendingRefusal(): ?string
    {
        $path = $this->stateFile('job-last-refusal.txt');
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);

        return \is_string($raw) && trim($raw) !== '' ? trim($raw) : null;
    }
}
