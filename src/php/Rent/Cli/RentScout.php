<?php

declare(strict_types=1);

namespace Scout\Rent\Cli;

use Scout\Rent\Adapters\EmailAlertSource;
use Scout\Rent\Adapters\FixtureSource;
use Scout\Adapters\Http\CurlHttpClient;
use Scout\Adapters\Http\HttpClient;
use Scout\Adapters\Http\ReplayHttpClient;
use Scout\Adapters\Http\Robots;
use Scout\Adapters\Http\RobotsResolver;
use Scout\Rent\Adapters\DetailHydrator;
use Scout\Rent\Adapters\HtmlSource;
use Scout\Rent\Adapters\HttpJsonSource;
use Scout\Adapters\Mail\FileMailbox;
use Scout\Adapters\Mail\ImapMailbox;
use Scout\Adapters\Mail\Mailbox;
use Scout\Rent\Adapters\PacedSource;
use Scout\Rent\Adapters\FeedDate;
use Scout\Rent\Adapters\Source;
use Scout\Adapters\SourceError;
use Scout\Config\ConfigError;
use Scout\Config\LegacyEnv;
use Scout\Rent\Config\ConfigLoader;
use Scout\Rent\Config\Criteria;
use Scout\Rent\Config\SourceDefinition;
use Scout\Rent\Core\CriteriaEngine;
use Scout\Rent\Core\DigestSchedule;
use Scout\Core\CountsPatternMisses;
use Scout\Core\Heartbeat;
use Scout\Rent\Core\ListingSnapshot;
use Scout\Core\Notify\Channel;
use Scout\Rent\Notify\Formatter;
use Scout\Core\Notify\MailTransport;
use Scout\Core\Notify\Notifier;
use Scout\Core\Notify\Priority;
use Scout\Core\Pacer;
use Scout\Rent\Core\RawListing;
use Scout\Rent\Core\SourceProfile;
use Scout\Rent\Core\Dedup;
use Scout\Rent\Core\ExcludedDwellings;
use Scout\Rent\Core\DigestCause;
use Scout\Rent\Core\Classification;
use Scout\Rent\Core\Outcome;
use Scout\Rent\Core\Tenure;
use Scout\Core\Redact;
use Scout\Core\SourceStatus;
use Scout\Rent\Core\TenureClassifier;
use Scout\Rent\Core\Verdict;
use Scout\Rent\Enrich\CommutePlanner;
use Scout\Rent\Enrich\NavitiaCommute;
use Scout\Rent\Store\Store;
use Scout\Cli\WatchLoop;
use Scout\Cli\ChannelFactory;

/**
 * The `scout` command — `spec/PROJECT_BRIEF.md` §10.
 *
 * Exit codes are part of the contract, because this runs unattended under a supervisor:
 * **0** nothing to report · **1** something the developer must act on (a failed source, an
 * undelivered notification) · **2** the tool refused to start (bad config, no usable channel, an
 * unseeded database).
 *
 * The refusals are scoped per Q28 and Q36. A bad `criteria.json` stops everything, because the
 * criteria decide what is filtered and a wrong filter is invisible. One bad source block does not.
 */
final readonly class RentScout
{
    /**
     * Days of portal silence, absent `RENT_FEED_SILENT_DAYS`. See {@see feedSilentDays()} for the
     * measurement behind the number and for why it must stay under `IMAP_SINCE_DAYS`.
     */
    private const int DEFAULT_FEED_SILENT_DAYS = 3;

    /**
     * Typed `mixed` because PHP has no `resource` type declaration and a readonly property must be
     * typed. Streams are ARGUMENTS rather than globals so a test can assert on real stdout — this
     * project's completion gate requires the actual output, not a description of it.
     *
     * @var resource
     */
    private mixed $out;

    /** @var resource */
    private mixed $err;

    /**
     * The one HTTP client this process uses, shared by every adapter AND by the robots resolver.
     *
     * Sharing is not an optimisation: {@see CurlHttpClient} pins the honest User-Agent and refuses
     * a caller-supplied override, so a robots request made through the same client is guaranteed to
     * identify as the same tool that polls the page. A second client built somewhere else would be
     * one edit away from asking permission as somebody different.
     */
    private HttpClient $http;

    /**
     * Turns an HTTP answer for `/robots.txt` into a verdict. Stateless — the once-per-host cache is
     * a local in {@see sources()}, so that its lifetime is one build of the source list and this
     * class stays `readonly` without taking a {@see \Scout\Core\MutableByDesign} exemption it
     * does not qualify for.
     */
    private RobotsResolver $robotsResolver;

    /**
     * @param resource|null $out
     * @param resource|null $err
     * @param string|null   $nowIso a FIXED clock for tests. Without one, `STALE` and the counting
     *                              window depend on wall time, and a test that passes at 23:59 and
     *                              fails at 00:01 teaches people to re-run rather than to read.
     * @param ?HttpClient   $http   a TEST SEAM, and the reason hard rule 5 is enforceable at all.
     *                              Until it existed the two network adapters were built with
     *                              `new CurlHttpClient()` inline, so nothing could observe what the
     *                              CLI requested — and what it requested, on every real poll, did
     *                              not include `robots.txt`. See
     *                              {@see \Scout\Tests\Rent\Cli\RentScoutRobotsTest}.
     */
    public function __construct(
        private string $rootDir,
        mixed $out = null,
        mixed $err = null,
        private ?string $nowIso = null,
        ?HttpClient $http = null,
        /**
         * A ready-built notifier, used INSTEAD of the one this class assembles from config.
         *
         * The same kind of seam as `$http` above, and here for the same reason: without it, a test
         * that needs a delivery to SUCCEED has to configure a channel that really delivers, and
         * the only offline candidate — `email` over `SMTP_TRANSPORT=file` — cannot, by
         * {@see Channel::reachesRecipient()}. Four CLI test classes used exactly that as their
         * "remote" channel for one review round, so every assertion about a listing being marked
         * notified passed for the reason that was itself the round-8 P0.
         *
         * Tests about CHANNEL BUILDING — an unknown name, a missing credential, the console-only
         * warning — must NOT inject, or they would stop exercising the thing they are about.
         */
        private ?Notifier $notifier = null,
    ) {
        $this->out = $out ?? STDOUT;
        $this->err = $err ?? STDERR;
        // Built eagerly because the class is readonly and cannot memoise later. Neither
        // constructor touches the network — the resolver fetches on first `forUrl()` — so a
        // command that never polls (`dump`, `digest`, `reclassify`) still makes no request.
        $this->http = $http ?? new CurlHttpClient();
        $this->robotsResolver = new RobotsResolver($this->http);
    }

    /** @param list<string> $argv command-line arguments, WITHOUT the program name */
    public function run(array $argv): int
    {
        // Domain dispatch lives in `Scout\Cli\Scout` (the generic entry point, 2026-08-29): by the
        // time this runs, `--domain=rent` has been consumed and this class is the rent domain only.
        $this->migrateLegacyMarkers();

        $command = $argv[0] ?? 'help';
        $flags = array_slice($argv, 1);

        try {
            // The `RENT_WATCH_*` → `SCOUT_*` rename of 2026-08-27. HERE rather than in `bin/scout`,
            // and the placement is the whole value: a refusal thrown inside this try is recorded to
            // `state/rent-last-refusal.txt` by `failRun()` and reported on the next heartbeat, whereas one
            // thrown in the entry point is stderr only. The audience for this guard is a container
            // crash-looping under a restart policy on a host whose `.env` still says `RENT_WATCH_DB`
            // — which is precisely the reader whose stderr scrolls past unread (Q27).
            LegacyEnv::checkProcess();

            return match ($command) {
                'doctor' => $this->doctor($flags),
                'dump' => $this->dump($flags),
                'run' => $this->runCommand($flags),
                'digest' => $this->digest($flags),
                'reclassify' => $this->reclassify($flags),
                'test-notify' => $this->testNotify(),
                'replay' => $this->replay($flags),
                'help', '--help', '-h' => $this->help(0),
                // `$this->fail(...) ?? $this->help(2)` was the first shape and it was WRONG: `fail()`
                // returns an int, never null, so `??` short-circuited and the usage was never
                // printed. A refusal that does not say what IS valid is a refusal the reader has to
                // guess their way out of.
                default => $this->unknownCommand($command),
            };
        } catch (ConfigError $e) {
            // Caught and printed, never a stack trace. A malformed config is an ordinary,
            // expected, user-caused condition — see ConfigError's own docblock.
            //
            // RECORDED when it refused `run`, because this is the commonest startup refusal there
            // is and Q27's note exists for exactly it: under Docker the process exits before any
            // channel is used and its stderr scrolls past in a log nobody reads, so without the note
            // a container that has been refusing to start since Tuesday is indistinguishable from a
            // quiet market. A ConfigError message quotes the offending VALUE, which is why
            // `recordRefusal()` redacts before writing.
            $text = 'configuration : ' . $e->getMessage();

            return $command === 'run' ? $this->failRun($text) : $this->fail($text);
        } catch (SourceError $e) {
            return $command === 'run' ? $this->failRun($e->getMessage()) : $this->fail($e->getMessage());
        } catch (\PDOException $e) {
            // The store could not be opened, and under Q8's deployment that is the LIKELIEST failure
            // in production rather than an exotic one: `state/` is bind-mounted from the host, the
            // image runs as a non-root uid, and a host directory owned by somebody else is simply
            // not writable. Measured by running the real container, where it produced a fatal
            // PDOException and a stack trace.
            //
            // Named path, named cause, no stack trace — the same treatment `ConfigError` already
            // gets for being "an ordinary, expected, user-caused condition". Redacted because a DSN
            // can carry credentials, even though SQLite's does not today.
            $text = 'base de données inutilisable (' . $this->dbPath() . ') : ' . Redact::text($e->getMessage())
                . '. Le volume est-il monté et accessible en écriture par l\'utilisateur du conteneur ?';

            return $command === 'run' ? $this->failRun($text) : $this->fail($text);
        } catch (\RuntimeException $e) {
            // The store's own refusals — a database a NEWER image migrated, opened by this older one
            // after a rollback (round-3 panel: an uncaught trace and no note, on exactly the path
            // Q27's note exists for). Recorded when it refused `run`, like a ConfigError.
            $text = Redact::text($e->getMessage()) ?? 'erreur';

            return $command === 'run' ? $this->failRun($text) : $this->fail($text);
        }
    }

    // ── doctor ────────────────────────────────────────────────────────────────────────────────────

    /**
     * Run every enabled source once and report status, timing and item counts — spec §8.
     *
     * Prints the store's **journal mode**, which `CLAUDE.md` § Testing requires: WAL can be silently
     * refused on a network mount, and a store in rollback-journal mode makes two processes contend
     * instead of share. And it passes `$nowIso` to `Store::health()`, without which `STALE` — the
     * verdict that catches the schedule itself having stopped — can never fire at all.
     */
    private function doctor(array $flags = []): int
    {
        // A PENDING STARTUP REFUSAL IS REPORTED BEFORE ANYTHING ELSE IS LOADED (round-5 panel,
        // 2026-08-31). It used to print below `criteria()`, `store()` and `sources()` — so it was
        // reachable only while `doctor`'s OWN bootstrap succeeded, which is never the case for a
        // refusal whose cause still blocks bootstrap. `CLAUDE.md` says a malformed config is the
        // commonest startup refusal there is, and that was precisely the one this line could not
        // report: the config throws at `criteria()` and the note is never reached. Reading it first
        // costs nothing and is the whole point of Q27's second half.
        //
        // Read-only: consuming it here would let a diagnostic swallow the note before the beat that
        // is supposed to push it.
        $pending = $this->pendingRefusal();

        if ($pending !== null) {
            $this->line('  refus   : ' . $pending);
            $this->line('            (refus au démarrage précédent — sera repris au prochain battement de cœur sous `--watch`)');
        }

        $criteria = $this->criteria();
        $store = $this->store();
        $sources = $this->sources($store, $this->onlySources($flags), $criteria, $flags);
        $now = $this->now();

        $this->line('scout --domain=rent doctor · ' . $now);
        $this->line('  base    : ' . $this->dbPath() . ' (schéma v' . $store->schemaVersion()
            . ', journal ' . $store->journalMode() . ')');

        if ($store->journalMode() !== 'wal' && $this->dbPath() !== ':memory:') {
            $this->line('  ⚠ le mode journal n\'est pas WAL : deux processus se bloqueront au lieu de partager');
        }


        // THE ONLY PLACE A NOTIFIED MATCH WITH NO EVIDENCE CAN BE SEEN. `staleVerdicts()` selects
        // undetermined verdicts, so a row that classified LLI and failed to encode is not skipped by
        // `reclassify` — it is invisible to it; `pendingDigest()` walks digest outcomes only. Before
        // this line the sole report was one stdout line on the pass that caused it, which under Q8's
        // deployment scrolls past in a log nobody reads. This is the §1 audit trail: a verdict with
        // no evidence is one nobody can ever re-examine.
        $eviless = $store->evidencelessVerdictCount();
        if ($eviless > 0) {
            $this->line('  preuves : ' . $eviless . ' verdict(s) sans instantané — non re-jugeables '
                . '(antérieurs au schéma v7, ou charge utile non encodable)');
        }

        $notifier = $this->notifier($criteria);
        // NAMED, not summarised. "au moins un canal distant" was the whole report, and it cannot
        // distinguish a push to a phone from an `.eml` written into a directory the container
        // destroys on rebuild — which is precisely the round-8 P0, and this is where it would have
        // been visible. `compte` is the answer to `Channel::reachesRecipient()`: whether a
        // successful send through it means a listing may be marked notified for ever.
        $this->line('  canaux  : ' . ($notifier->hasRemoteChannel()
            ? 'au moins un canal atteint un destinataire'
            : 'AUCUN canal n\'atteint de destinataire — rien ne sera marqué notifié'));
        foreach ($notifier->inventory() as $channel) {
            $this->line(sprintf(
                '            - %-8s %s [%s]',
                $channel['name'],
                $channel['describe'],
                $channel['counts'] ? 'compte comme délivré' : 'NE COMPTE PAS',
            ));
        }
        foreach ($notifier->disabledReport() as $name => $problem) {
            $this->line('  ⚠ canal ' . $name . ' désactivé : ' . $problem);
        }
        // The digest cadence is stated as what RUNS, not as what is configured. This line once read
        // `digest à 8h`, promising a daily emission nothing scheduled; then it said so, naming the
        // gap; the floor now exists, so it says that instead. All three versions describe the same
        // config key, which is why the rule is to print behaviour rather than settings.
        //
        // THE RESOLVED LOCAL TIME IS PRINTED, per Q34's own ruling, and BOTH zones are shown because
        // they can disagree. `bin/scout:44` sets the process default from `TZ`, so normally they
        // match — but a `TZ` PHP cannot parse leaves the default at UTC with only a Notice, and then
        // the two differ and the operator can see it. Resolved by the same function the floor uses:
        // a second implementation here is how a diagnostic ends up disagreeing with the behaviour it
        // reports.
        try {
            $digestZone = DigestSchedule::zoneFromEnv(($tz = getenv('TZ')) === false ? null : $tz);
            $zoneNote = $digestZone->getName() . ' (il y est '
                . (new \DateTimeImmutable($this->now()))->setTimezone($digestZone)->format('H\hi') . ')';
        } catch (\InvalidArgumentException $e) {
            // Doctor DIAGNOSES, it does not refuse — but it must not print a plausible hour derived
            // from a zone that would stop `run` at startup.
            $zoneNote = 'TZ INUTILISABLE — `scout --domain=rent run --watch` refusera de démarrer : ' . $e->getMessage();
        }

        $this->line('  fuseau  : PHP=' . date_default_timezone_get() . ' · récapitulatif=' . $zoneNote);

        // Diagnostic, never a refusal — see feedSilentWindowNote() for why that distinction cost a
        // review round. A misconfiguration that merely makes a verdict unlikely is advice; the tool
        // still runs, and on a deployment with no email source at all it is not even advice.
        $windowNote = ImapMailbox::feedSilentWindowNote(self::feedSilentDays());

        if ($windowNote !== null) {
            $this->warn($windowNote);
        }

        // The same advice per source: a `feed_silent_days` at or past the IMAP window has exactly
        // the collapse the global one has, and a source-level number is easier to set past the
        // window because nothing beside it says what the window is.
        foreach (ConfigLoader::loadSources($this->rootDir . '/config/rent/sources.json') as $definition) {
            if ($definition->feedSilentDays === null) {
                continue;
            }
            $note = ImapMailbox::feedSilentWindowNote($definition->feedSilentDays, 'feed_silent_days de ' . $definition->name);
            if ($note !== null) {
                $this->warn($note);
            }
        }

        // `en --watch` is not a detail. The floor lives in the watch loop, so a cron-driven `--once`
        // deployment gets the two event-driven paths and no floor — and a line promising a daily
        // rollup to an operator who runs `--once` repeats the hard-rule-2 shape this line was
        // rewritten to stop repeating, one scope narrower.
        $this->line('  digest  : à la fin de toute passe produisant du nouveau, sur demande (`scout --domain=rent digest`), '
            . 'et en plancher quotidien à ' . $criteria->notify->digestHour . 'h en `--watch` (Q34) '
            . '— silencieux si rien n\'est en attente');
        // A5: the low-score queue is a fact the developer will look for HERE first — a quiet phone
        // under a gate is not a quiet market. The car side says the same on its own `rollup` line.
        $waitingLowScore = $store->pendingLowScoreCount();
        if ($criteria->notify->pushMinScore !== null) {
            $this->line(sprintf('  rollup  : seuil %d — %d correspondance(s) en attente du récapitulatif « vérifié, score bas » (drainées par `scout --domain=rent digest` ; le plancher quotidien ne tourne que sous --watch)', $criteria->notify->pushMinScore, $waitingLowScore));
        } elseif ($waitingLowScore > 0) {
            $this->line(sprintf('  rollup  : %d correspondance(s) jugée(s) et jamais notifiée(s) — aucun seuil configuré, `scout --domain=rent digest` les émet', $waitingLowScore));
        }
        $this->line('');

        if ($sources === []) {
            $this->line('  aucune source activée. Toutes les sources de config/rent/sources.json sont');
            $this->line('  `enabled: false` tant que leur endpoint n\'a pas été vérifié (règle 1).');

            return 0;
        }

        $problems = 0;
        $this->line(sprintf('  %-16s %-16s %8s %8s  %s', 'SOURCE', 'ÉTAT', 'ITEMS', 'DURÉE', 'DÉTAIL'));

        foreach ($sources as $source) {
            $startedAt = hrtime(true);
            $count = 0;
            $error = null;

            try {
                $count = count($source->fetch());
            } catch (\Throwable $e) {
                $error = $e instanceof SourceError ? $e->getMessage() : (new SourceError($source->name(), $e->getMessage(), $e))->getMessage();
            }

            $durationMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);
            // The feed date is recorded HERE too, not only in `Pipeline`. `doctor` writes a real run
            // row, so omitting it would leave a deployment that only ever runs `doctor` with a
            // permanently null column — the feed-silence verdict present in the code and unreachable
            // in practice, which is the shape this whole change exists to remove. Only on the
            // success path, and only after the fetch: on a failure the mailbox never saw a message.
            $store->recordRun(
                $source->name(),
                $count,
                $error === null,
                $error,
                $now,
                $durationMs,
                $error === null ? FeedDate::of($source) : null,
            );

            $health = $source->health($now);
            if ($health->status->isAlerting()) {
                ++$problems;
            }

            // Hard rule 2, for the one failure nothing else in this table can show. A detail page
            // that stops parsing does not change the source's COUNT and does not fail its run, so
            // `ok / 168 annonces` stays true and correct while every listing quietly loses its
            // title — the broken-selector-forever shape, one layer down. It is reported as a count
            // rather than as a status because one dead page is noise: what means something is the
            // proportion, and the operator is the one who can see it against the item count.
            $detailFailures = $store->detailFailureCount($source->name(), DetailHydrator::DETAIL_ATTEMPT_CAP);
            $note = $error ?? ($health->detail ?? '');

            if ($detailFailures > 0) {
                $note = rtrim($note . ' · ' . $detailFailures . ' page(s) de détail illisible(s), abandonnée(s)', ' ·');
            }

            $this->line(sprintf(
                '  %-16s %-16s %8d %6d ms  %s',
                $source->name(),
                $health->status->value,
                $count,
                $durationMs,
                $note,
            ));

            // THE PER-PATTERN MISS COUNTS (Track 1h). Printed only when something actually missed,
            // so a healthy source stays one line: a table that prints four zero rows per source is
            // a table nobody reads, and this exists to be noticed. A PARTIAL miss rate does not
            // reach `health()` — which only speaks at 100% — but it is the early warning that a
            // portal is drifting, and `doctor` is where an operator goes to look.
            if ($source instanceof CountsPatternMisses) {
                foreach ($source->patternMisses()->counts() as $key => $c) {
                    if ($c['misses'] === 0) {
                        continue;
                    }

                    $this->line(sprintf(
                        '                     %-18s %d/%d carte(s) sans résultat%s',
                        $key,
                        $c['misses'],
                        $c['calls'],
                        $c['misses'] === $c['calls'] ? '  ← AUCUNE : gabarit changé ?' : '',
                    ));
                }
            }
        }

        return $problems > 0 ? 1 : 0;
    }

    // ── dump ──────────────────────────────────────────────────────────────────────────────────────

    /**
     * Print the first raw item of a source, for building a field map.
     *
     * `spec` §10: *"what makes onboarding a new source take five minutes instead of an hour."* It
     * prints the MAPPED listing too, so a field map that silently reads nothing is visible as a row
     * of nulls rather than discovered weeks later as a quiet source.
     *
     * @param list<string> $flags
     */
    /**
     * `scout --domain=rent replay <source> [--file=<payload>]`.
     *
     * Bare, it is the alias of `dump` it has always been. With `--file`, it is the half spec §10
     * asked for and the alias never delivered: a frozen page run through a NETWORK source's own
     * adapter and field map, with no request made — what developing a map from a captured page
     * needs, and what `dump` against a live source cannot give (it polls).
     *
     * Two things make it safe rather than merely convenient. The client is a {@see ReplayHttpClient}
     * (search URL → the payload, `/robots.txt` → 404 = allow, everything else → 404), so the replay
     * is offline by construction and a `detail_map` is never handed the search page as a detail
     * page. And the store is a THROWAWAY: `dump` hydrates through the detail cache, so a replay
     * against the real database would record one fetch-failure row per listing for pages nobody
     * fetched — a repair tool leaving damage in the thing it was diagnosing.
     *
     * An `email_alert` source is refused with the seam that already exists for it: `MAILBOX_DIR`.
     *
     * @param list<string> $flags
     */
    private function replay(array $flags): int
    {
        $file = null;
        foreach ($flags as $flag) {
            if (str_starts_with($flag, '--file=')) {
                $file = substr($flag, \strlen('--file='));
            }
        }

        if ($file === null || $file === '') {
            return $this->dump($flags);
        }

        $name = self::firstBareArgument($flags);
        if ($name === null) {
            return $this->fail('usage : scout --domain=rent replay <source> --file=<charge utile figée>');
        }

        $definitions = ConfigLoader::loadSources($this->rootDir . '/config/rent/sources.json');
        if (!isset($definitions[$name])) {
            return $this->fail('source inconnue : ' . $name . ' (connues : ' . implode(', ', array_keys($definitions)) . ')');
        }
        $definition = $definitions[$name];

        if ($definition->type === 'email_alert') {
            return $this->fail('--file ne s\'applique qu\'aux sources html/json : pour une source email_alert, '
                . 'relisez un dossier de .eml avec MAILBOX_DIR=<dossier> scout --domain=rent dump ' . $name);
        }
        if ($definition->type === 'fixture') {
            return $this->fail('la source ' . $name . ' est de type `fixture` : `scout --domain=rent dump ' . $name
                . '` relit déjà sa charge utile figée (--file est pour les sources html/json)');
        }

        $searchUrl = $definition->url ?? $definition->baseUrl;
        if ($searchUrl === null) {
            return $this->fail('la source ' . $name . ' ne déclare ni url ni base_url — rien à servir en relecture');
        }

        $path = str_starts_with($file, '/') ? $file : $this->rootDir . '/' . $file;
        $body = @file_get_contents($path);
        if ($body === false) {
            return $this->fail('fichier illisible : ' . $file);
        }

        $client = new ReplayHttpClient(
            $searchUrl,
            $body,
            $definition->type === 'json' ? 'application/json' : 'text/html; charset=utf-8',
        );

        // A sibling instance over the replay client: this class is readonly, so the client cannot
        // be swapped on `$this`, and `dump` on the sibling is the SAME code path a real dump takes —
        // gate, hydration, classifier — which is the point.
        $replay = new self($this->rootDir, $this->out, $this->err, $this->nowIso, $client, $this->notifier);

        return $replay->dump(
            [$name],
            Store::open(':memory:'),
            '— relecture de ' . $file . ' à travers la source ' . $name
                . ' (aucune requête réseau ; pages de détail : 404 simulé ; base de données jetable) —',
            // Unthrottled: the adapter sleeps `rate_limit_ms` between fetches whatever answers
            // them, and there is no host here to protect — 43 s of sleeping for one dump, measured.
            $definition->unthrottled(),
        );
    }

    /** @param list<string> $flags */
    private static function firstBareArgument(array $flags): ?string
    {
        foreach ($flags as $flag) {
            if (!str_starts_with($flag, '-')) {
                return $flag;
            }
        }

        return null;
    }

    /**
     * @param list<string> $flags
     * @param ?Store       $store  a replay's throwaway store; `null` is the real one
     * @param ?string      $banner printed once the source is resolved, so a replay says it is one
     * @param ?SourceDefinition $override the definition to build instead of the configured one — a
     *                                    replay's unthrottled copy; `null` is the configured block
     */
    private function dump(array $flags, ?Store $store = null, ?string $banner = null, ?SourceDefinition $override = null): int
    {
        $name = self::firstBareArgument($flags);

        if ($name === null) {
            return $this->fail('usage : scout --domain=rent dump <source>');
        }

        $store ??= $this->store();
        $definitions = ConfigLoader::loadSources($this->rootDir . '/config/rent/sources.json');

        if (!isset($definitions[$name])) {
            return $this->fail('source inconnue : ' . $name . ' (connues : ' . implode(', ', array_keys($definitions)) . ')');
        }

        if ($banner !== null) {
            $this->line($banner);
        }

        $definition = $override ?? $definitions[$name];

        // `dump` builds the gate too: a dump that hydrated nothing would show a different listing
        // than a run does, which makes it useless for the one job it has — seeing what the pipeline
        // will see.
        $source = $this->buildSource($definition, $store, $this->criteria());
        if ($source === null) {
            return $this->fail('la source ' . $name . ' est de type `' . $definition->type
                . '`, pour lequel aucun adaptateur n\'existe encore');
        }

        $listings = $source->fetch();
        if ($listings === []) {
            $this->line('la source a répondu sans erreur mais n\'a produit aucune annonce.');

            return 1;
        }

        $first = $listings[0];
        $this->line('— annonce brute (' . count($listings) . ' au total) —');
        $this->line((string) json_encode($first->fields, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->line('');
        $this->line('— après application du field map —');

        foreach ([
            'externalId' => $first->externalId,
            'title' => $first->title,
            'url' => $first->url,
            'commune' => $first->commune,
            'postcode' => $first->postcode,
            'rentCc' => $first->rentCc,
            'rentHc' => $first->rentHc,
            'charges' => $first->charges,
            'effectiveRentCc' => $first->effectiveRentCc(),
            'surfaceM2' => $first->surfaceM2,
            'rooms' => $first->rooms,
            'floor' => $first->floor,
            'hasElevator' => $first->hasElevator,
        ] as $field => $value) {
            $this->line(sprintf('  %-16s %s', $field, $this->show($value)));
        }

        $classification = $this->classifier()->classify($first, $source->profile());
        $this->line('');
        $this->line('  tenure           ' . $classification->tenure->value
            . ' (' . $classification->confidenceBp . '/100, ' . $classification->outcome->value . ')');
        foreach ($classification->reasons() as $reason) {
            $this->line('                   · ' . $reason);
        }

        return 0;
    }

    // ── run ───────────────────────────────────────────────────────────────────────────────────────

    /** @param list<string> $flags */
    private function runCommand(array $flags): int
    {
        $verbose = in_array('-v', $flags, true) || in_array('--verbose', $flags, true);
        $seed = in_array('--seed', $flags, true);

        $watch = in_array('--watch', $flags, true);

        if ($watch && $seed) {
            // `--seed` marks everything already seen WITHOUT notifying, to bootstrap the seen-set.
            // Repeating that every fifteen minutes would suppress every notification forever while
            // the process looked perfectly healthy — a watcher that watches and never speaks.
            return $this->failRun(
                '`--seed` amorce le seen-set en une passe ; combiné à `--watch` il n\'émettrait '
                . 'jamais aucune notification. Lancez `scout --domain=rent run --once --seed`, puis `--watch`.',
            );
        }

        $criteria = $this->criteria();
        $store = $this->store();

        // Q36. A missing volume mount produces a valid, empty, migrated database indistinguishable
        // from a healthy one — and with nothing batched, every historic listing would push at once.
        //
        // The question asked is whether anything has ever been RECORDED, not whether this process
        // created the file: `scout --domain=rent doctor` opens the database, and while the guard read the latter,
        // running doctor once — the first thing a new machine invites you to do — let the next run
        // notify the entire back catalogue.
        if ($store->isSeenSetEmpty() && !$seed) {
            return $this->failRun(
                'base vide : première exécution, ou volume non monté. Rien n\'a été notifié. '
                . 'Relancez avec `--seed` pour amorcer le seen-set sans notifier, puis sans le flag.',
            );
        }

        $notifier = $this->notifier($criteria);
        $fatal = $notifier->fatalProblem();
        if ($fatal !== null && !$seed) {
            return $this->failRun($fatal);
        }

        foreach ($notifier->disabledReport() as $name => $problem) {
            // Reported, and the process still starts (Q28). One expired credential must not take
            // down the sources, the seen-set and the price history to punish a single channel.
            $this->warn('canal ' . $name . ' désactivé : ' . $problem);
        }

        if (!$seed) {
            // Skipped under `--seed` because seeding deliberately notifies nothing, so having no
            // delivering channel is not a problem for that run — warning there would be the noise
            // that teaches an operator to skip this line. Pinned in both directions.
            $this->warnIfNothingDelivers($notifier);
        }

        $sources = $this->sources($store, $this->onlySources($flags), $criteria, $flags);
        if ($sources === []) {
            $this->line('aucune source activée — rien à faire.');

            return 0;
        }

        if ($watch) {
            return $this->watch($criteria, $store, $notifier, $sources, $verbose);
        }

        // `$pushed` is captured, not discarded: the forced beat below reports it. Passing a literal
        // `0` there is the defect `tests/sabotage-check.sh` already pins on the WATCH path — "the
        // beat's notified count goes back to a hard-coded 0 (constant at any traffic level)" — and
        // the `--once` path re-introduced it nineteen lines from the out-parameter that exists for
        // exactly this (round-6 panel, two lenses).
        $pushed = null;
        $code = $this->onePass($criteria, $store, $notifier, $sources, $seed, $verbose, false, $pushed);

        // A PENDING REFUSAL FORCES A BEAT ON `--once` (round-5 panel, 2026-08-31). Q27's promise is
        // that "the next successful start reports it on the beat and clears it" — and `--once` has
        // no beat at all, so on the cron-driven deployment `CLAUDE.md` names as supported, the note
        // was written by every refused run and read by nothing. `doctor` reports it now, but a
        // diagnostic nobody runs is not a report, and since the note is only cleared on delivery it
        // would sit there for ever: "an alert nobody retracts becomes furniture", from the other end.
        //
        // Ignoring the interval is deliberate and is not the per-pass spam Q27 guards against. This
        // fires only when a note is actually pending — i.e. after a refusal, which is rare and is
        // exactly the event worth one push — and delivering it is what clears it, so it cannot
        // repeat. `--seed` is excluded because a seeding run notifies nothing by construction.
        if ($code === 0 && !$seed && $this->pendingRefusal() !== null) {
            $watched = [];
            foreach ($sources as $source) {
                $watched[$source->name()] = $source;
            }

            $this->beat($notifier, $store, 1, $pushed ?? 0, $this->pendingRefusal(), $watched);
        }

        return $code;
    }

    /**
     * The Q37 watch loop.
     *
     * Three things are re-done on EVERY pass, and each was a way to get this wrong:
     *
     * - `PacedSource::wrapAll()` is called per pass, not once, because Q37 requires the order to be
     *   shuffled *each* pass. Wrapping once would fix one random order for the lifetime of the
     *   process — which for a service running for weeks is simply a fixed order, and being polled
     *   first every fifteen minutes is itself a fingerprint.
     * - `$this->now()` is re-read per pass. Hoisting it would stamp every run in the log with the
     *   moment the process started, and `Store::health()` derives `STALE` from those timestamps —
     *   so the one verdict that catches "the schedule has stopped" would be computed against a clock
     *   that never moves. That is the failure mode of a monitor monitoring itself with a dead clock.
     * - The pacer is built ONCE and shared, because its whole job is remembering across passes.
     *
     * @param list<Source> $sources
     */
    private function watch(
        Criteria $criteria,
        Store $store,
        Notifier $notifier,
        array $sources,
        bool $verbose,
    ): int {
        $loop = null;
        $pacer = new Pacer(
            clock: static fn (): float => hrtime(true) / 1_000_000_000.0,
            sleeper: WatchLoop::interruptibleSleeper(
                static function () use (&$loop): bool {
                    return $loop !== null && $loop->isStopping();
                },
            ),
            rand: static fn (int $min, int $max): int => random_int($min, $max),
        );

        // Q27. Built BEFORE the loop so an unusable RENT_HEARTBEAT_HOURS refuses at startup rather than
        // on the first beat — which, on the default, would be a day into an unattended run.
        //
        // Converted to a REFUSAL rather than allowed to propagate: a bad env value is an ordinary,
        // expected, user-caused condition, and `ConfigError`'s docblock already rules that those are
        // caught and printed, never shown as a stack trace. It goes through `failRun()` so the next
        // successful start reports it, which is the case Q27's refusal note exists for — an operator
        // who typo'd this in a compose file would otherwise see nothing at all.
        try {
            $heartbeat = Heartbeat::fromEnv(($raw = getenv('RENT_HEARTBEAT_HOURS')) === false ? null : $raw, 'RENT_HEARTBEAT_HOURS');

            // Q34's daily floor, built here for the same reason and refusing at the same moment: an
            // unusable `digest_hour` or `TZ` must stop the watcher at startup, not a day into an
            // unattended run.
            //
            // The zone is resolved explicitly rather than read from `date_default_timezone_get()`.
            // NOT because the default is wrong — `bin/scout:44` already sets it from `TZ` — but
            // because `date_default_timezone_set()` answers a bad zone name with `false` and a
            // Notice, leaving UTC standing. That is a typo in a compose file quietly moving the
            // floor two hours all summer, with nothing but a notice in a container log to show for
            // it. `zoneFromEnv()` refuses instead, here, before the loop starts.
            $digestSchedule = new DigestSchedule($criteria->notify->digestHour);
            $digestZone = DigestSchedule::zoneFromEnv(($tz = getenv('TZ')) === false ? null : $tz);
        } catch (\InvalidArgumentException $e) {
            return $this->failRun($e->getMessage());
        }
        $passes = 0;
        $failedPasses = 0;

        // Q27's "listings notified" figure. It was the LITERAL `0` at both call sites below, so the
        // one number that separates a producing watcher from a mute one was constant — on the day
        // matching genuinely stopped, the beat read byte-for-byte identical to the day it pushed 33.
        // `CLAUDE.md` and `beat()`'s own docblock both claimed it carried this. Found by a review
        // panel on 2026-08-24, one line from the field round 3 had just repaired.
        //
        // Cumulative over the process, like `$passes`, because a beat reporting only the last pass
        // would read as zero on any quiet interval and re-open the same ambiguity.
        $notified = 0;

        // What this run actually polls, which is NOT the same as what the config enables whenever
        // `--source` is in play. Taken from the built list rather than re-read from the config,
        // because the built list is the thing the loop will iterate: any future reason a source is
        // dropped between config and loop is then reflected here for free, rather than silently
        // reintroducing the mismatch this replaced.
        // Keyed by name, holding the SOURCE — the beat reads health through `$source->health()`,
        // the same funnel `doctor` and the pipeline use, so a per-source `feed_silent_days` is
        // honoured in one place rather than re-derived at every call site (2026-08-29).
        $watched = [];
        foreach ($sources as $source) {
            $watched[$source->name()] = $source;
        }

        // CONSUMED WHERE IT IS REPORTED, never before (round-4 panel, 2026-08-31). This used to read
        // and delete the note unconditionally, ABOVE the `isDue()` test, while the in-loop beat below
        // passed a literal `null` — so on any restart inside `RENT_HEARTBEAT_HOURS`, which is the
        // ordinary state of a fix-and-redeploy, the startup beat did not fire, the note was destroyed
        // and no beat ever carried it. Q27's "the next successful start reports it on the beat and
        // clears it" was half true, and the half that was missing is the one that reaches a human:
        // a container crash-looping all night on a bad config, fixed eleven hours later, announced
        // the outage to nobody.
        //
        // Read here and CLEARED only once the beat has delivered ({@see clearLastRefusal()}), so the
        // note survives both a process that dies before the first beat is due and a beat whose
        // channel is the very thing the refusal was about.
        if ($heartbeat->isDue($this->lastHeartbeat(), $this->now())) {
            // Genuinely zero here: no pass has run yet in this process.
            $this->beat($notifier, $store, $passes, $notified, $this->pendingRefusal(), $watched);
        }

        // Before the first pass, deliberately. A backlog left undelivered when the container was
        // last stopped is exactly what a restart should drain, and waiting for the first pass to
        // finish would delay it by a full Q37 cadence for no benefit. Silent if the bin is empty,
        // which on an ordinary restart it is.
        if ($digestSchedule->isDue($this->lastDigestEmission(), $this->now(), $digestZone)) {
            $this->floorDigest($notifier, $store, $this->now());
        }

        $loop = new WatchLoop(
            pass: function () use ($criteria, $store, $notifier, $sources, $verbose, $pacer, $heartbeat, $digestSchedule, $digestZone, $watched, &$passes, &$failedPasses, &$notified): void {
                // `finally`, and it is the whole point rather than a style choice. This claimed to
                // be "outside its try/catch by construction" and was not: the heartbeat sat in the
                // same closure as the pass, and `WatchLoop` wraps that closure in its own `try` — so
                // any throw from `onePass` skipped both `++$passes` AND the beat. A review panel
                // found it on 2026-08-24, next to a pass-aborting defect that made it reachable: a
                // watcher losing every pass to one bad listing would also have gone silent, which is
                // precisely the state the beat exists to distinguish from a quiet market.
                //
                // The common case it was right about still holds — all sources 503ing is caught per
                // source INSIDE `Pipeline` and never reaches here — which is exactly why nobody
                // re-checked the mechanism.
                $threw = true;

                try {
                    // The RESULT is used now rather than discarded: it carries what the pass
                    // actually pushed, which is the figure the beat reports.
                    $pushed = null;
                    $this->onePass($criteria, $store, $notifier, PacedSource::wrapAll($sources, $pacer), false, $verbose, true, $pushed);
                    ++$passes;
                    $notified += $pushed ?? 0;
                    $threw = false;
                } finally {
                    if ($threw) {
                        ++$failedPasses;
                    }

                    // A heartbeat is the one message that must still go out when passes are failing,
                    // since a watcher whose sources are all broken is exactly the state silence
                    // would hide. Its own failure must not mask the pass's: a liveness signal that
                    // can replace the diagnosis is worse than one that is late.
                    try {
                        if ($heartbeat->isDue($this->lastHeartbeat(), $this->now())) {
                            // `pendingRefusal()`, not `null`: the startup beat only fires on a cold
                            // start or past the interval, so on every other restart THIS is the first
                            // beat there is and the note has nowhere else to be reported.
                            $this->beat($notifier, $store, $passes, $notified, $this->pendingRefusal(), $watched, $failedPasses);
                        }
                    } catch (\Throwable $beatFailure) {
                        $this->warn('battement de cœur non émis : ' . Redact::text($beatFailure->getMessage()));
                    }

                    // Q34's floor, in the `finally` for the same reason the beat is: the bin it
                    // drains is §1's only landing zone, and a pass that threw is exactly when a
                    // backlog is most likely to be sitting in it. Its own failure is contained for
                    // the same reason too — a rollup that cannot send must not replace the
                    // diagnosis of whatever made the pass fail.
                    try {
                        if ($digestSchedule->isDue($this->lastDigestEmission(), $this->now(), $digestZone)) {
                            $this->floorDigest($notifier, $store, $this->now());
                        }
                    } catch (\Throwable $digestFailure) {
                        $this->warn('récapitulatif quotidien non émis : ' . Redact::text($digestFailure->getMessage()));
                    }
                }
            },
            pacer: $pacer,
            onError: function (\Throwable $e): void {
                // Reported, never hidden — hard rule 3 at the loop level. The loop survives so the
                // next pass can succeed; saying nothing would make a watcher that has stopped
                // fetching indistinguishable from one watching a quiet market.
                $this->warn('passe en échec : ' . Redact::text($e->getMessage()));
            },
        );

        $handlers = $loop->installSignalHandlers();

        $maxPasses = $this->maxPasses();

        $this->line(sprintf(
            'surveillance active · %d source(s) · toutes les %d min ± %d (Q37) · %s%s',
            count($sources),
            (int) (Pacer::PASS_INTERVAL_SECONDS / 60),
            (int) (Pacer::JITTER_SECONDS / 60),
            // Said out loud rather than assumed: without ext-pcntl a SIGTERM kills the process
            // wherever it happens to be, and the operator should not learn that from a duplicate
            // notification storm after the first `docker stop`.
            $handlers
                ? 'arrêt propre sur SIGINT/SIGTERM (la passe en cours se termine)'
                : 'ext-pcntl absent : pas d\'arrêt propre, la passe en cours sera interrompue',
            // Said out loud, every pass, because a watcher that stops after N passes looks exactly
            // like one that is still watching — right up until the listing it missed.
            $maxPasses === null ? '' : sprintf(' · SCOUT_MAX_PASSES=%d : arrêt après %d passe(s)', $maxPasses, $maxPasses),
        ));

        // Said out loud so the operator knows what silence will mean. An interval nobody was told
        // about cannot be used to judge whether the watcher has gone quiet.
        $this->line(sprintf('battement de cœur toutes les %d h (Q27) — le silence au-delà est un signal', $heartbeat->intervalHours));
        $this->line(sprintf(
            'plancher quotidien du récapitulatif « à vérifier » à %dh %s (Q34) — silencieux si rien n\'est en attente',
            $digestSchedule->hour,
            $digestZone->getName(),
        ));

        return $loop->run($maxPasses);
    }

    /**
     * One complete pass. Shared by `--once` and by every iteration of `--watch`.
     *
     * @param list<Source> $sources
     */
    private function onePass(
        Criteria $criteria,
        Store $store,
        Notifier $notifier,
        array $sources,
        bool $seed,
        bool $verbose,
        /**
         * Is this pass inside the watch loop?
         *
         * It changes ONE sentence — the one naming what will empty the low-score queue. The daily
         * floor runs under `--watch` only, so telling a cron-driven `--once` deployment to wait for
         * a *récapitulatif quotidien* names a drain that will never run there, and the queue grows
         * for ever while the line reads as reassurance (C2 round 6, completeness lens).
         */
        bool $watching = false,
        /**
         * What the pass ACTUALLY ANNOUNCED — confirmed deliveries, for the Q27 beat. See
         * {@see RunResult::$notified} for why this is not `matches`. An out-parameter rather than a
         * changed return type, so the exit-code contract every caller reads stays exactly as it
         * was — and so a caller that does not care about the figure is not forced to unpack a
         * result object.
         */
        ?int &$matchesOut = null,
    ): int {
        $result = (new Pipeline(
            $criteria,
            $store,
            $notifier,
            classifier: $this->classifier(),
            commute: $this->commutePlanner($criteria, $store),
        ))->runOnce($sources, $this->now(), $seed);

        // `notified`, NOT `matches`. `matches` counts what the engine JUDGED, before the
        // already-announced gate and before the channel confirms — so in steady state, where
        // everything published has already been announced, it is the full standing count while the
        // pass sends nothing. Wiring the beat to it replaced a hard-coded `0` with a number that
        // was wrong in the other direction, and grew fastest when delivery was broken.
        $matchesOut = $result->notified;

        $this->line(sprintf(
            '%d source(s), %d annonce(s) analysées · %d correspondance(s), %d à vérifier, %d écartée(s), %d doublon(s)%s',
            $result->sourcesRun,
            $result->itemsParsed,
            $result->matches,
            $result->digested,
            $result->rejectedCount,
            $result->duplicates,
            // Only when it happened: a permanent ", 0 doublon(s) inter-pistes" is noise on every pass.
            $result->twinsSuppressed > 0 ? sprintf(', %d copie(s) d\'agence non poussée(s) (bien déjà annoncé par sa voie directe)', $result->twinsSuppressed) : '',
        ));

        if ($seed) {
            $this->line('mode --seed : seen-set amorcé, aucune notification envoyée.');
        }

        if ($verbose) {
            foreach ($result->rejected as $line) {
                $this->line('  écartée ' . $line);
            }
        }

        foreach ($result->errors as $error) {
            $this->warn($error);
        }
        // Row 41 — what the pass noticed, beside the failures and never counted as one.
        foreach ($result->warnings as $warning) {
            $this->warn($warning);
        }

        if ($result->unencodable > 0) {
            // Named on the pass that causes it, rather than left to be discovered from a skip
            // counter months later.
            //
            // **This comment used to say those rows are "judged and digested normally" and that
            // `reclassify` "will skip them for ever". Both halves are false**, and the code
            // contradicting them is a few files away: a structured field alone can fail to encode
            // on a listing whose prose is clean, so the row classifies normally and can be a
            // NOTIFIED MATCH — and `staleVerdicts()` selects `tenure IS NULL OR tenure = 'UNKNOWN'`,
            // so such a row is not SKIPPED by reclassify, it is INVISIBLE to it, and invisible to
            // `pendingDigest()` too. `scout --domain=rent doctor`'s `preuves` line is the only thing that ever
            // surfaces it. This was the FOURTH live copy of that premise; the previous three were
            // corrected in three separate review rounds, and a fifth round found this one sitting
            // directly above the operator line it explains.
            // "charge utile", not "texte illisible". `ListingSnapshot::encode()` refuses three
            // distinct things — malformed UTF-8 anywhere in the listing (including a STRUCTURED
            // FIELD whose title and description are perfectly clean), a nesting depth over 512, and
            // Inf/NaN — and only the first is unreadable prose. A review panel produced a NOTIFIED
            // MATCH that silently lost its snapshot while the operator was told the listing was
            // illisible. Substituting U+FFFD instead of refusing is not the answer and was checked:
            // it would delete an excluded label out of the middle of `conventionné`.
            $this->warn($result->unencodable . ' annonce(s) à la charge utile non encodable — verdict enregistré sans instantané, non re-jugeable');
        }

        if ($result->digestOverflow > 0) {
            $this->warn(sprintf(
                '%d annonce(s) à vérifier non émise(s) ce passage (lot de %d) — `scout --domain=rent digest` pour la suite.',
                $result->digestOverflow,
                Store::DIGEST_BATCH,
            ));
        }

        if ($result->undelivered > 0) {
            $this->warn($result->undelivered . ' notification(s) non délivrée(s) — elles seront réessayées');
        }
        if ($result->queuedLowScore > 0) {
            // A5: said on every pass that holds something back, so a quiet phone is never read as
            // a quiet market — the flats exist, they are waiting for the daily rollup.
            //
            // And it names the drain THIS run mode actually has: the floor is `--watch` only, so
            // under `--once` the queue empties when the operator's cron runs the verb, and nothing
            // else will ever do it.
            $this->line(sprintf(
                '%d correspondance(s) sous le seuil de notification individuelle — %s',
                $result->queuedLowScore,
                $watching
                    ? 'en attente du récapitulatif quotidien (« vérifié, score bas »)'
                    : 'en attente de `scout --domain=rent digest` (« vérifié, score bas ») — le plancher quotidien ne tourne que sous --watch',
            ));
        }

        return $result->hasProblems() ? 1 : 0;
    }

    // ── digest / reclassify / test-notify ─────────────────────────────────────────────────────────

    /**
     * Emit the "à vérifier" digest on demand (Q34's other half).
     *
     * **It reads the STORE, not the last pass, and that is the whole reason it exists.** The
     * pipeline already emits every new digest entry at the end of any pass that produces one, marks
     * them only after the channel confirms, and re-offers an undelivered batch next run. What that
     * retry cannot reach is a listing that stops being published in between: it is no longer in any
     * pass's results, so nothing re-offers it, and a digest entry — unlike a match — has no second
     * chance from anywhere else. Selecting on `outcome = 'DIGEST' AND notified_at IS NULL` asks the
     * question the pipeline cannot: what has been judged doubtful and never told to anyone.
     *
     * **A row with no snapshot is announced, never skipped.** It announces such a row from
     * `listings`' own columns — stored facts, not invented ones — and says how many were degraded
     * that way.
     *
     * THE REASON FIRST GIVEN FOR THAT RULE WAS WRONG, and the correction is worth more than the
     * rule. It said: every row in the standing backlog predates schema v7, so skipping
     * evidence-less rows would skip exactly what this command was ruled to rescue. A review panel
     * ran a real v4 and a real v6 database through the migration and showed the premise is false in
     * the other direction — `outcome` is a v7 column too, and is not backfilled either, so a pre-v7
     * row has `outcome = NULL` and this query never returns it at all. The pre-v7 backlog is not
     * skipped for lack of a snapshot; it is never selected. A true rule with an invented cause is
     * worse than a wrong one, because nobody re-checks it.
     *
     * That backlog is deliberately NOT reachable, and widening the query is the wrong fix: a pre-v7
     * row with `tenure = 'UNKNOWN'` might have been digested, or might have been REJECTED by a hard
     * disqualifier before the tenure branch was ever reached, and nothing stored tells them apart.
     * Selecting on tenure would put rejected listings — wrong commune, wrong size — into the one
     * channel §1 uses as its landing zone, wearing the clothes of a doubtful match. A pre-v7 row
     * that is still PUBLISHED gets its outcome from the next ordinary pass; one already delisted
     * stays unreachable, and that is a stated cost rather than a hidden one.
     *
     * The evidence-less path is still live, and since 2026-08-24 it is reachable in production for
     * a reason that has nothing to do with v7: a listing whose PAYLOAD cannot be JSON-encoded —
     * malformed UTF-8 anywhere in it, not necessarily its prose — has its verdict stored without a
     * snapshot. Such a listing is judged and carries a title, which is exactly the row this branch
     * announces when it digests.
     *
     * That is deliberately NOT the rule {@see reclassify()} follows. Reclassify FORMS a verdict, so
     * running it on less evidence than the original saw is the §1 breach schema v7 exists to
     * prevent. This command only ANNOUNCES a verdict already formed and already stored; announcing
     * a stored `DIGEST` from a stored title cannot promote anything into a match.
     *
     * @param list<string> $flags
     */
    private function digest(array $flags): int
    {
        $dryRun = in_array('--dry-run', $flags, true);
        foreach ($flags as $flag) {
            if ($flag !== '--dry-run') {
                return $this->fail('option inconnue : ' . $flag . ' (connue : --dry-run)');
            }
        }

        $store = $this->store();
        $batch = $this->collectDigest($store);

        foreach ($batch->warnings as $warning) {
            $this->warn($warning);
        }

        if ($batch->isEmpty()) {
            $this->line('Aucune annonce en attente — ni « à vérifier », ni « vérifié, score bas ».');

            return 0;
        }

        $entries = $batch->entries;
        $withoutSnapshot = $batch->withoutSnapshot;
        $unreadable = $batch->unreadable();
        $waiting = $batch->waiting;

        $notification = (new Formatter())->digest($entries, $batch->lowScore);

        if ($entries !== [] || $batch->lowScore !== []) {
            $this->line($notification->title);
            foreach ($notification->reasons as $reason) {
                $this->line($reason);
            }
        }
        foreach ($batch->retries as $retry) {
            $this->line('[RETRY] ' . (new Formatter())->match($retry['listing'], $retry['verdict'])->title);
        }

        if ($withoutSnapshot > 0) {
            // Counted out loud. A backlog announced without its full detail otherwise reads as a
            // set of sources that publish nothing but titles.
            // THE CAUSE IT NAMES MUST BE ONE THAT CAN HAPPEN. This said "antérieures au schéma v7"
            // and a review panel showed a row created seconds ago at v7 being reported that way —
            // `pendingDigest()` filters on `outcome`, itself a v7 column, so a pre-v7 row is never
            // returned here at all. The only reachable cause is a listing whose own payload could
            // not be JSON-encoded. Pointing an operator at a migration hides an encoding fault in a
            // live source, which is the one thing this line could have surfaced.
            $this->line(sprintf(
                '%d annonce(s) sans instantané (charge utile non encodable) — annoncées depuis les colonnes conservées.',
                $withoutSnapshot,
            ));
        }
        if ($unreadable > 0) {
            $this->line($unreadable . ' instantané(s) illisible(s) — voir les avertissements ci-dessus.');
        }

        if ($dryRun) {
            // Nothing was attempted, so nothing drained.
            $this->reportRemainder($batch, 0, false);
            $this->line('--dry-run : rien n\'a été envoyé, rien n\'a été marqué comme émis.');

            return 0;
        }

        $notifier = $this->notifier($this->criteria());

        $fatal = $notifier->fatalProblem();
        if ($fatal !== null) {
            return $this->fail($fatal);
        }

        foreach ($notifier->disabledReport() as $name => $problem) {
            $this->warn('canal ' . $name . ' désactivé : ' . $problem);
        }

        $this->warnIfNothingDelivers($notifier);

        $now = $this->nowIso ?? date('c');
        // The retries first, as the individual pushes they are (C2 round 6, resilience P2).
        //
        // AND THE REMAINDER IS REPORTED AFTER THEM, never before (C2 round 8, P1): a refused retry
        // stays queued by design, so a line printed before the attempt cannot know whether the
        // backlog moved and claimed it had.
        $drainedKeys = $this->pushRetries($notifier, $store, $batch->retries, $now);

        if ($entries === [] && $batch->lowScore === []) {
            // Nothing to send, so nothing can land: the retries are the whole story.
            $this->reportRemainder($batch, $drainedKeys, false);

            return 0;
        }

        $failures = $notifier->send($notification);
        foreach ($failures as $failure) {
            // `Redact::text` here is DEFENCE IN DEPTH, not the guarantee. A round-7 lens reported
            // three sites in this file as unredacted while `CarScout` redacted at all four of its
            // own; the reading was right about the syntax and wrong about the leak, because
            // `ChannelError::__construct()` redacts the message it is built with and
            // `Notifier::send()` returns nothing else — measured, and now pinned by
            // `ChannelErrorRedactionTest`. Do not remove the constructor's redaction on the
            // strength of these calls: they cover the call sites, it covers everything.
            $this->warn(Redact::text($failure->getMessage()));
        }

        if (!$notifier->delivered($failures)) {
            // Nothing marked. Marking first would consume the batch permanently on a failed send,
            // and these entries have no other route to the developer — the same asymmetry the
            // pipeline's digest branch is built on.
            // AND THE REMAINDER SAYS SO. It used to be printed before the send, subtracting every
            // announced and rolled-up row unconditionally — so a refused mail marked nothing, left
            // the whole batch queued, and reported it as drained (C2 milestone panel, P2).
            $this->reportRemainder($batch, $drainedKeys, false);
            $this->warn('récapitulatif non délivré — rien n\'a été marqué comme émis, il sera réessayé.');

            return 1;
        }

        $this->reportRemainder($batch, $drainedKeys, true);

        foreach ($entries as $entry) {
            $store->markNotified($entry['key'], $now, 'DIGEST');
        }
        // A5: a rolled-up match is marked ROLLUP, never DIGEST (it is no tenure doubt) and never
        // MATCH (it was not pushed — the promotion over the gate stays reachable). EVERY key of a
        // collapsed twin pair, so the other route is not re-announced tomorrow.
        foreach ($batch->lowScore as $entry) {
            foreach ($entry['keys'] as $key) {
                $store->markNotified($key, $now, 'ROLLUP');
            }
        }

        $this->line($batch->count() . ' annonce(s) émise(s)' . ($batch->lowScore !== [] ? sprintf(' (dont %d « vérifié, score bas »)', count($batch->lowScore)) : '') . '.');

        return 0;
    }

    /**
     * Collect one drain of the *à vérifier* bin — the half `scout --domain=rent digest` and Q34's daily floor share.
     *
     * CAPPED, and the remainder is reported by {@see DigestBatch::overflow()}. The query was once
     * unbounded and the send is all-or-nothing, so a rejection that is a function of payload size
     * re-sent a strictly LARGER batch every time and the bin — §1's only landing zone — hardened
     * into permanent undeliverability, with one warning line to show for it. Found by a review panel
     * on 2026-08-24. A cap makes the backlog drain instead.
     *
     * **Never throws, and never prints.** Both properties are load-bearing. The floor calls this
     * from inside the watch loop's `finally`, where a throw would be caught by `WatchLoop` and
     * counted as a failed pass — one damaged row reporting every source as broken. And printing here
     * would put the command's phrasing in the loop's output, so warnings are returned for the caller
     * to voice.
     */
    private function collectDigest(Store $store): DigestBatch
    {
        $rows = $store->pendingDigest();
        $waiting = $store->pendingDigestCount();

        $entries = [];
        $warnings = [];
        $withoutSnapshot = 0;

        foreach ($rows as $row) {
            $listing = null;
            $json = $row['evidence_json'];

            if (is_string($json) && $json !== '') {
                try {
                    $listing = ListingSnapshot::decode($json);
                } catch (\JsonException | \InvalidArgumentException $e) {
                    // Per-row, which is why the store hands these back RAW. Decoding the batch in
                    // one query would let the first damaged row take every readable entry with it —
                    // one bad row costing the whole digest, the opposite of degrading it and saying
                    // so. Redacted: a snapshot quotes the listing payload back, and an adapter
                    // error message is a secrets channel.
                    $warnings[] = sprintf(
                        'instantané illisible pour %s — annonce dégradée : %s',
                        $row['dedup_key'],
                        Redact::text($e->getMessage()),
                    );
                }
            } else {
                ++$withoutSnapshot;
            }

            $listing ??= new RawListing(
                sourceName: $row['source'],
                externalId: $row['external_id'],
                title: $row['title'],
                url: $row['url'],
                rentCc: $row['rent_cc'],
            );

            /** @var list<string> $reasons */
            $reasons = $this->decodeSignals($row['signals_json']);

            // WHY it is in the bin, read off the stored verdict rather than re-formed. A row whose
            // tenure is `UNKNOWN` — or was never recorded at all — is the §1 landing zone proper;
            // a row digested with its regime already settled took some other route in, and the
            // rollup title must not announce it as undetermined. `OTHER` rather than naming the
            // price branch: the store records that this is not a tenure doubt and says nothing
            // about which route it was, so claiming one would be a verdict formed here.
            $storedTenure = $row['tenure'];
            $cause = ($storedTenure === null || $storedTenure === Tenure::UNKNOWN->value)
                ? DigestCause::TENURE_UNDETERMINED
                : DigestCause::OTHER;

            $entries[] = [
                'listing' => $listing,
                'verdict' => Verdict::digest($reasons, $cause),
                'key' => $row['dedup_key'],
                'keys' => [$row['dedup_key']],
            ];
        }

        // A5 — THE SECOND QUEUE (row 6, 2026-09-05). Each queued match is re-SCORED from its
        // snapshot under today's criteria so the rollup carries a real score (the store keeps
        // none) — with the tenure classification it was STORED with, never a fresh one: this
        // command announces verdicts, it does not re-form a §1 verdict (the rule the docblock of
        // its own test states). Never throws, never prints — the same contract as above.
        $lowScore = [];
        $retries = [];
        /** @var list<array{listing: RawListing, verdict: Verdict, key: string, keys: list<string>}> $queued */
        $queued = [];
        $waitingLowScore = $store->pendingLowScoreCount();
        if ($waitingLowScore > 0) {
            $criteria = $this->criteria();
            $engine = new CriteriaEngine($criteria);
            $pushMin = $criteria->notify->pushMinScore;

            // THE FOURTH PERSISTED ROUTE (C2 round 8, completeness P0; renumbered by the C2
            // milestone panel, which found this comment and `Store::excludedDwellings()` disagreeing
            // on the count). `Pipeline` judges §1 from FOUR readings — the row's own durable one,
            // the group, the twin and this; round 7 gave this drain the group and the twin only.
            // This one is the only route that catches a portal RE-ADVERTISING the same flat under
            // a NEW ad id: there is no group edge and no twin, so the other two see nothing at all,
            // and the flat is announced as a match while an excluded reading of the same dwelling
            // sits one row away. Loaded ONCE — it is one indexed scan of the excluded rows.
            $excludedDwellings = $store->excludedDwellings();
            $dwellingDedup = new Dedup();

            foreach ($store->pendingLowScore() as $row) {
                $key = $row['dedup_key'];
                /** @var list<string> $storedReasons */
                $storedReasons = $this->decodeSignals($row['signals_json']);

                // §1 FIRST, AND FROM EVERY PERSISTED READING — not from this row's own `tenure`
                // column alone (C2 round 7, correctness P0). `pendingLowScore()` selects that
                // column and nothing else, so a flat whose TWIN on the other track was judged
                // `PLS`, or whose CLUSTER holds a `PLS` sibling, reached the retry arm and was
                // PUSHED as an individual MATCH — the exact state schema v12 persists the veto
                // for: *a pass seeing the agency copy alone pushed the PLS flat*. `Pipeline` and
                // `reclassify` both refuse on these two, and this drain is the third surface —
                // the one that reaches the phone.
                //
                // ABOVE THE SPLIT deliberately: both arms announce, so a guard inside
                // `pushRetries()` would be *a fix landing on one of two symmetric surfaces*, this
                // repo's named recurring defect, committed inside the fix for it.
                //
                // The UNDETERMINED twin is refused as well, exactly as `reclassify` refuses to
                // PROMOTE on one: a doubt on the other track is not something this command may
                // announce away, and it has no route to re-file the row as a doubt.
                $groupVeto = $store->groupExcludedTenure($key);
                if ($groupVeto !== null) {
                    $warnings[] = sprintf(
                        '%s : régime « %s » retenu contre son groupe — laissée en attente, `scout --domain=rent reclassify` la revoit',
                        $key,
                        $groupVeto->value,
                    );

                    continue;
                }
                $twin = $store->twinTenure($key);
                if ($twin !== null && ($twin['tenure']->isExcluded() || $twin['tenure'] === Tenure::UNKNOWN)) {
                    $warnings[] = sprintf(
                        '%s : l\'autre voie (%s) la dit « %s » — laissée en attente, `scout --domain=rent reclassify` la revoit',
                        $key,
                        (string) $twin['source'],
                        $twin['tenure']->value,
                    );

                    continue;
                }

                // THE ROW'S OWN READING, AND IT BELONGS ABOVE THE SPLIT WITH THE OTHER TWO (C2
                // round 8, correctness + resilience P0). It used to sit ten lines below, past the
                // snapshot-less arm's `continue` — so two arms of one loop gave opposite answers to
                // the same row, and a flat stored `PLS` whose payload will not encode was pushed as
                // an individual MATCH and marked `notified_as = 'MATCH'`, which cannot be demoted.
                // Round 7 lifted the group and twin vetoes for exactly this reason and left the
                // third behind: *a fix landing on one of two symmetric surfaces*, in the fix for it.
                //
                // The ROUTING below is deliberately unchanged — a ledger case pins it, and before
                // round 7 this row went out as a demotable ROLLUP rather than a permanent MATCH.
                $tenure = is_string($row['tenure']) ? Tenure::tryFrom($row['tenure']) : null;
                if ($tenure === null || $tenure->isExcluded() || $tenure === Tenure::UNKNOWN) {
                    // A MATCH outcome beside a tenure that could not have produced one is a row
                    // nobody should announce from here; `reclassify` owns it.
                    $warnings[] = sprintf('%s : régime stocké « %s » incompatible avec un MATCH — laissée en attente', $key, (string) $row['tenure']);
                    continue;
                }

                try {
                    $listing = $store->evidence($key);
                } catch (\JsonException | \InvalidArgumentException $e) {
                    $warnings[] = sprintf('instantané illisible pour %s — laissée en attente : %s', $key, Redact::text($e->getMessage()));
                    continue;
                }

                if ($listing === null) {
                    // An unencodable payload: announced from the stored columns with the reasons
                    // the verdict recorded, and WITHOUT a score — nothing can re-score it (§1).
                    //
                    // WHICH ARM IT TAKES IS THE GATE'S QUESTION, NOT THE SNAPSHOT'S (C2 round 7,
                    // completeness P2). This row used to go to the rollup unconditionally, so on a
                    // deployment with NO gate — where nothing is ever held back — a failed push
                    // whose payload will not encode was announced under a heading asserting it
                    // fell short of a threshold that does not exist, and marked ROLLUP, which
                    // takes it out of `pendingLowScore()` for ever. The car drain already read it
                    // the other way, and the documented rule is the car one.
                    ++$withoutSnapshot;
                    $queued[] = [
                        'listing' => new RawListing(sourceName: $row['source'], externalId: $row['external_id'], title: $row['title'], url: $row['url'], rentCc: $row['rent_cc']),
                        'verdict' => Verdict::matched(0, $storedReasons, false),
                        'key' => $key,
                        'keys' => [$key],
                    ];
                    continue;
                }

                $dwellingVeto = ExcludedDwellings::match($listing, $excludedDwellings, $dwellingDedup);
                if ($dwellingVeto !== null) {
                    $warnings[] = sprintf(
                        '%s : régime exclu (%s) déjà relevé sur le même logement — %s — laissée en attente, `scout --domain=rent reclassify` la revoit',
                        $key,
                        $dwellingVeto['tenure']->value,
                        $dwellingVeto['source'] . ' ' . $dwellingVeto['externalId'],
                    );

                    continue;
                }

                $verdict = $engine->judge(
                    $listing,
                    new Classification($tenure, (int) ($row['confidence_bp'] ?? 0), [], Outcome::MATCH),
                    $this->ageSeconds($store, $key),
                );

                if ($verdict->outcome !== Outcome::MATCH) {
                    // Today's criteria no longer match it: not the rollup's to announce, and not
                    // its to re-file either — `scout reclassify` owns verdict changes.
                    $warnings[] = sprintf('%s re-jugée %s aujourd\'hui — laissée en attente, `scout --domain=rent reclassify` la revoit', $key, $verdict->outcome->value);
                    continue;
                }

                $queued[] = [
                    'listing' => $listing,
                    'verdict' => Verdict::matched($verdict->score ?? 0, [...$storedReasons, ...$verdict->reasons], $verdict->highPriority),
                    'key' => $key,
                    'keys' => [$key],
                ];
            }

            // TWO TRACKS, ONE ANNOUNCEMENT — COLLAPSE FIRST, THEN SPLIT (C2 round 7). The
            // pipeline's twin cover fires only on a copy that was PUSHED, so two queued copies of
            // one flat were both announced. Round 6 collapsed each list separately, which left the
            // case where the twins STRADDLE the gate: one entry in each list, `collapseTwins()`
            // returns at `count < 2`, and the flat is announced twice in one drain — an individual
            // MATCH push AND a rollup line, neither naming the other route. Measured, both ways
            // round, and with the agency copy taking the headline when it scores higher — the
            // opposite of *the better route is never hidden*.
            //
            // Collapsing over the UNION fixes both: one flat, one entry, one score (the surviving
            // route's), and that score decides which arm it takes.
            $queued = $this->collapseTwins($queued, $warnings);

            // A RETRY, NOT A ROLLUP (C2 round 6, resilience P2): the queue cannot tell a match
            // held back by the gate from one whose push failed, and on a deployment with no gate
            // every queued row is the latter. The split is the gate's own comparison — a row at or
            // over the line is pushed as the match it is, never announced under a heading that
            // says it fell short.
            foreach ($queued as $entry) {
                if ($pushMin === null || ($entry['verdict']->score ?? 0) >= $pushMin) {
                    $retries[] = $entry;
                } else {
                    $lowScore[] = $entry;
                }
            }
        }

        return new DigestBatch(
            entries: $entries,
            waiting: $waiting,
            withoutSnapshot: $withoutSnapshot,
            warnings: $warnings,
            lowScore: $lowScore,
            waitingLowScore: $waitingLowScore,
            retries: $retries,
        );
    }

    /**
     * Merge cross-track twins inside one drained list: the direct route survives (a private
     * copy only when there is no direct one), the other copy's key joins `keys` so delivery marks
     * both, and the survivor's reasons name the other route with its link — the push's own rule,
     * *the better route is never hidden*. `Dedup::twinReason()` is the same test the pipeline
     * applies, so the two cannot disagree about what a twin is.
     *
     * **SCOPE: the QUEUE, never the tenure bin.** It is applied to the queued matches — which the
     * caller collapses BEFORE splitting them into retries and rollup, so a pair straddling the gate
     * is still one flat — and deliberately not to `entries`. A cross-track twin pair in the *à
     * vérifier* bin is therefore still announced twice, which is noise rather than a §1 fault: both
     * are doubts, both carry their own `DigestCause`, and merging two doubts would decide which
     * cause the survivor keeps — a judgement this command does not make (C2 round 7, P3).
     *
     * The family map is the ONE thing this reads outside the store, and a config it cannot read
     * must not take the drain down with it: the digest is §1's only landing zone, and this command
     * exists precisely for the backlog no pass will re-offer. So a `ConfigError` is VOICED and the
     * entries pass through uncollapsed — the behaviour of the day before this method existed,
     * costing a duplicate announcement rather than a bin that never empties. Every other verb still
     * refuses on the same error, loudly, where sources are what it is about.
     *
     * @param list<array{listing: RawListing, verdict: Verdict, key: string, keys: list<string>}> $entries
     * @param list<string>                                                                       $warnings
     *
     * @return list<array{listing: RawListing, verdict: Verdict, key: string, keys: list<string>}>
     */
    private function collapseTwins(array $entries, array &$warnings): array
    {
        if (count($entries) < 2) {
            return $entries;
        }
        $family = [];
        try {
            foreach (ConfigLoader::loadSources($this->rootDir . '/config/rent/sources.json') as $definition) {
                $family[$definition->name] = $definition->family;
            }
        } catch (ConfigError $e) {
            $warnings[] = 'familles des sources illisibles — les copies croisées ne sont pas fusionnées : ' . Redact::text($e->getMessage());

            return $entries;
        }
        $dedup = new Dedup();
        $out = [];
        foreach ($entries as $entry) {
            $listing = $entry['listing'];
            $mine = $family[$listing->sourceName] ?? 'private';
            foreach ($out as $i => $kept) {
                $theirs = $family[$kept['listing']->sourceName] ?? 'private';
                if ($dedup->twinReason($kept['listing'], $listing, $theirs, $mine) === null) {
                    continue;
                }
                // The direct route wins the headline; whichever loses is named beneath it.
                [$winner, $loser] = $mine === 'institutional' && $theirs !== 'institutional' ? [$entry, $kept] : [$kept, $entry];
                $route = ($family[$loser['listing']->sourceName] ?? 'private') === 'institutional' ? 'voie directe' : 'agence / portail';
                $link = $loser['listing']->url === null ? '' : ' : ' . $loser['listing']->url;
                $out[$i] = [
                    'listing' => $winner['listing'],
                    'verdict' => Verdict::matched($winner['verdict']->score ?? 0, [sprintf('aussi via %s (%s)%s', $loser['listing']->sourceName, $route, $link), ...$winner['verdict']->reasons], $winner['verdict']->highPriority),
                    'key' => $winner['key'],
                    'keys' => array_values(array_unique([...$kept['keys'], ...$entry['keys']])),
                ];
                continue 2;
            }
            $out[] = $entry;
        }

        return array_values($out);
    }

    /**
     * Push the drain's retries as the individual matches they are, marking MATCH on delivery.
     * Shared by the `digest` verb and the daily floor — one implementation, so the floor cannot
     * forget what the verb does (the repo's named recurring defect).
     *
     * @param list<array{listing: RawListing, verdict: Verdict, key: string, keys: list<string>}> $retries
     *
     * @return int pushes confirmed delivered
     */
    /**
     * The remainder line — ONE implementation, called by the verb and by the daily floor.
     *
     * Said out loud, because a capped batch that stayed silent about the remainder would look like
     * the whole backlog and the operator would stop running the command; silent when there is none,
     * because a line claiming a backlog it has just emptied is how they learn to stop reading it.
     * Both directions have cost a round.
     */
    private function reportRemainder(DigestBatch $batch, int $drainedKeys, bool $delivered): void
    {
        $remaining = $batch->overflow($drainedKeys, $delivered);
        if ($remaining > 0) {
            $this->line(sprintf(
                '%d autre(s) en attente — relancer `scout --domain=rent digest` pour la suite (lot de %d).',
                $remaining,
                Store::DIGEST_BATCH,
            ));
        }
    }

    private function pushRetries(Notifier $notifier, Store $store, array $retries, string $now): int
    {
        $delivered = 0;
        $drainedKeys = 0;
        foreach ($retries as $entry) {
            $failures = $notifier->send((new Formatter())->match($entry['listing'], $entry['verdict']));
            foreach ($failures as $failure) {
                $this->warn(Redact::text($failure->getMessage()));
            }
            if (!$notifier->delivered($failures)) {
                $this->warn(sprintf('réémission non délivrée pour %s — laissée en attente.', $entry['key']));
                continue;
            }
            foreach ($entry['keys'] as $key) {
                $store->markNotified($key, $now, 'MATCH');
                ++$drainedKeys;
            }
            ++$delivered;
        }
        if ($retries !== []) {
            $this->line(sprintf('%d correspondance(s) réémise(s) individuellement — au niveau du seuil ou sans seuil, jamais « score bas » (%d délivrée(s)).', count($retries), $delivered));
        }

        // KEYS, not entries: a collapsed twin leaves the queue as two rows, and the remainder line
        // is counted in rows. `$delivered` stays the ENTRY count because that is what the message
        // above says out loud — one push, however many keys it retires.
        return $drainedKeys;
    }

    /**
     * The stored `reasons[]`, or an empty list when the column holds something that is not one.
     *
     * Tolerant on purpose, and it is the one place in this command that is: these are explanatory
     * strings shown next to an entry, so a damaged `signals_json` costs a sentence. The LISTING is
     * what must always survive intact, and it is decoded separately and counted.
     *
     * @return list<string>
     */
    private function decodeSignals(?string $json): array
    {
        if (!is_string($json) || $json === '') {
            return [];
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        $reasons = [];
        foreach ($decoded as $reason) {
            if (is_string($reason)) {
                $reasons[] = $reason;
            }
        }

        return $reasons;
    }

    /** @param list<string> $flags */
    /**
     * Re-run the classifier and the criteria engine over stored verdicts (Q35).
     *
     * The problem is a PERMANENT silent miss. The seen-set guarantees a listing is new exactly
     * once, so a listing digested as `UNKNOWN` under a classifier that is later improved is never
     * surfaced again by anything at all — and Q18 (PLI) and Q21 (a shouted `PLUS`) both route there
     * deliberately, so the bin is not small.
     *
     * **`reclassify runs on evidence ⊇ original, never ⊂`**, and that is a §1 rule rather than a
     * quality preference. A card whose structured field says `PLS` while its title says *logement
     * intermédiaire* classifies `UNKNOWN` today BY CONFLICT; re-run on the title alone it becomes a
     * MATCH. So re-judging a row whose evidence has shrunk does not make a smaller improvement — it
     * manufactures the one outcome §1 forbids, preferentially on the listings most likely to be
     * social, because those are the ones whose evidence conflicts. A row with no stored snapshot is
     * therefore SKIPPED and counted out loud, never degraded to whatever text is lying around.
     *
     * **It runs on the schema-v7 snapshot ALONE, and does not merge the detail cache on top.** That
     * refines the mechanic sketched when v7 was planned, and the reason is in the pipeline: every
     * pass rewrites the snapshot with the member exactly as the classifier consumed it, AFTER any
     * detail merge. So a detail page fetched in pass N is already inside the snapshot written in
     * pass N, and re-mapping `listing_detail.fields_json` here would buy no evidence while making a
     * stored verdict depend on `ListingMapper` code and a `detail_map` that have since changed —
     * precisely the drift the snapshot column exists to escape.
     *
     * It re-JUDGES rather than merely re-classifying, because Q35's promotion test is on `Outcome`
     * and only the criteria engine produces one. That means TODAY's criteria: a row whose tenure now
     * resolves cleanly can still fail a ceiling lowered since it was stored, and records `REJECT`.
     *
     * @param list<string> $flags
     */
    private function reclassify(array $flags): int
    {
        $dryRun = false;
        $reopen = null;

        foreach ($flags as $flag) {
            if ($flag === '--dry-run') {
                $dryRun = true;
                continue;
            }

            // F20 / Q39 (row 40): the one way back for a durably-excluded row. The key is named
            // in full — never a pattern, never "all" — because the cost of a wrong re-open is a
            // social-housing flat pushed as a match.
            if (str_starts_with($flag, '--reopen=') && strlen($flag) > 9) {
                $reopen = substr($flag, 9);
                continue;
            }

            if ($flag === '--since' || str_starts_with($flag, '--since=')) {
                // REFUSED, not answered with something else. Q35's staleness mechanism is a stored
                // classifier version, and there is no such column — answering `--since` against
                // `last_seen_at` would substitute a different mechanism for the ruled one while
                // looking like it. Re-running the whole bin costs seconds, so nothing is lost.
                return $this->fail(
                    '--since n\'est pas implémenté : il suppose une version de classifieur stockée '
                    . 'avec le verdict, colonne qui n\'existe pas. Relancer sans option revoit tout le lot.',
                );
            }

            return $this->fail('option inconnue : ' . $flag . ' (connues : --dry-run, --reopen=<clé>)');
        }

        $store = $this->store();

        if ($reopen !== null) {
            $provenance = $store->reopen($reopen, $dryRun);
            if ($provenance === null) {
                return $this->fail('--reopen : aucune annonce ne porte la clé ' . $reopen . ' — rien n\'a été touché');
            }
            $twin = $provenance['twin'];
            $dwelling = $provenance['dwelling'];
            $this->line(sprintf(
                '%s %s — lecture propre : %s · jumeau : %s · groupe : %s · même logement : %s',
                $dryRun ? 'réouverture (simulation, rien n\'est effacé)' : 'réouverture',
                $reopen,
                $provenance['own']?->value ?? 'aucune',
                $twin === null ? 'aucun' : $twin['tenure']->value . ' (' . $twin['source'] . ')',
                $provenance['group']?->value ?? 'aucun',
                $dwelling === null
                    ? 'aucun'
                    : $dwelling['tenure']->value . ' (' . $dwelling['source'] . ' ' . $dwelling['externalId'] . ')',
            ));
            if ($provenance['group'] !== null) {
                $this->warn('le veto de groupe vient des lectures propres des annonces liées et n\'est PAS effacé : '
                    . 'la prochaine passe rejettera de nouveau cette annonce tant qu\'une annonce liée dit ' . $provenance['group']->value);
            }
            if ($dwelling !== null) {
                // Same shape as the group warning, and for the same reason: this reading belongs to
                // ANOTHER row. Without it `--reopen` looks like it worked and the next pass rejects
                // the row again with nothing said — the repair verb's own silent failure.
                $this->warn('le régime exclu relevé sur le même logement (' . $dwelling['source'] . ' ' . $dwelling['externalId']
                    . ') vient de la lecture propre de CETTE annonce-là et n\'est PAS effacé : rouvrez-la aussi si elle est erronée');
            }
        }

        $criteria = $this->criteria();
        $rows = $store->staleVerdicts();

        // Read ONCE, above the loop, exactly as the digest drain reads it: the candidate set is a
        // property of the store, not of the row being judged, and re-querying per row would make
        // this command's cost quadratic in a backlog that is already the largest thing it touches.
        $excludedDwellings = $store->excludedDwellings();
        $dwellingDedup = new Dedup();

        if ($rows === []) {
            $this->line('Aucun verdict indéterminé à revoir.');

            return 0;
        }

        $this->line(count($rows) . ' annonce(s) au verdict indéterminé ou antérieur au schéma v3.');

        $profiles = [];
        foreach (ConfigLoader::loadSources($this->rootDir . '/config/rent/sources.json') as $definition) {
            $profiles[$definition->name] = $definition->profile();
        }

        // The advertiser substitution needs the same profile map this loop already built, so a
        // re-judged row is judged under exactly the profile a live pass would have used. A row
        // captured before `advertiser` existed carries `null` and is unaffected — no backfill, by
        // ruling, and a backfilled advertiser would be indistinguishable from a read one.
        $classifier = new TenureClassifier(profileFor: static fn (string $key): ?SourceProfile
            => $profiles[$key] ?? null);
        $engine = new CriteriaEngine($criteria);

        // THE NOTIFIER IS BUILT BEFORE ANY ROW IS TOUCHED, and that ordering is the fix to a defect
        // a review panel proved on 2026-08-24. It used to be constructed after the loop: a deploy
        // whose `RENT_NTFY_TOPIC` was not yet filled in ran the whole re-judge, rewrote every verdict,
        // and only then hit `fatalProblem()` — consuming the entire promotable backlog in one run
        // while printing a message about an environment variable. Refuse before the work, as
        // `run`, `digest` and `test-notify` all do.
        $notifier = null;
        if (!$dryRun) {
            $notifier = $this->notifier($criteria);

            $fatal = $notifier->fatalProblem();
            if ($fatal !== null) {
                return $this->fail($fatal);
            }

            foreach ($notifier->disabledReport() as $name => $problem) {
                $this->warn('canal ' . $name . ' désactivé : ' . $problem);
            }

            $this->warnIfNothingDelivers($notifier);
        }

        $skipped = 0;
        $vetoed = 0;
        $unreadable = 0;
        $unencodable = 0;
        $rejudged = 0;
        $changed = 0;
        /** @var list<array{listing: RawListing, verdict: Verdict, key: string, classification: \Scout\Rent\Core\Classification}> $promotions */
        $promotions = [];

        foreach ($rows as $row) {
            $key = $row['dedup_key'];

            try {
                $evidence = $store->evidence($key);
            } catch (\JsonException | \InvalidArgumentException $e) {
                // Per row. `Store::evidence()` throws on a damaged snapshot by design — degrading it
                // to `null` would make data loss indistinguishable from a row that never had one —
                // but letting that throw escape would void the whole run, which is the blast-radius
                // mistake detail hydration already made once. Loud AND scoped. Redacted, because a
                // snapshot quotes the listing payload back.
                ++$unreadable;
                $this->warn('instantané illisible pour ' . $key . ' — verdict inchangé : ' . Redact::text($e->getMessage()));
                continue;
            }

            if ($evidence === null) {
                // THE §1 RULE. Not backfilled, so there is nothing honest to judge on.
                ++$skipped;
                continue;
            }

            $profile = $profiles[$evidence->sourceName] ?? new \Scout\Rent\Core\SourceProfile(
                // A source removed from `sources.json` leaves its listings behind. Fail CLOSED:
                // `mixedTenure: true` with no default digests a listing with no signal instead of
                // matching it, which is the direction §1 requires when we do not know what we are
                // looking at.
                $evidence->sourceName,
                'institutional',
                null,
                true,
            );

            // §1 ACROSS THE CLUSTER, the same rule the pipeline judges by — checked BEFORE the
            // classifier runs, because a listing this command cannot legitimately judge should not
            // be judged at all.
            //
            // The pipeline judges a cluster on its most restrictive member but stores each member's
            // OWN tenure and OWN snapshot. So a vetoed survivor whose card states no tenure sits at
            // `tenure = 'UNKNOWN'`, `outcome = 'REJECT'` — and `staleVerdicts()` selects on `tenure`
            // alone, so this command picked it up and re-judged it on a snapshot in which the
            // sibling's `PLS` cannot appear. A review panel drove it end to end on 2026-08-24: the
            // REJECT vanished after one run, and in the promotion case the row was PUSHED as a
            // match while the store still held `PLS` under its own `group_key`.
            //
            // That is this command's own invariant read exactly — **evidence ⊇ original, never ⊂**
            // — because the cluster's evidence is part of the original.
            $groupVeto = $store->groupExcludedTenure($key);

            if ($groupVeto !== null) {
                ++$vetoed;

                continue;
            }

            // The other track's word (schema v12) is part of the original evidence in exactly the
            // sense the group's is: a row vetoed by its twin must not be re-judged on a snapshot in
            // which the twin cannot appear, and a row the twin left in doubt must not be PROMOTED.
            $twin = $store->twinTenure($key);

            if ($twin !== null && ($twin['tenure']->isExcluded() || $twin['tenure'] === Tenure::UNKNOWN)) {
                ++$vetoed;

                continue;
            }

            // THE FOURTH PERSISTED ROUTE — the same dwelling on record under ANOTHER ad id.
            //
            // `ExcludedDwellings` said it "has TWO callers that must never disagree", meaning the
            // pipeline and the digest drain. It has THREE: this command forms a verdict, promotes
            // `DIGEST -> MATCH` and pushes it. The route is precisely the one the two vetoes above
            // cannot see — a portal re-advertising a flat under a new ad id acquires no group edge
            // (same source, so `Dedup` refuses one) and no twin — so this surface was the only one
            // of the three left judging §1 on three readings out of four.
            //
            // Worse than an ordinary gap while it stood: the drain REFUSES such a row with a
            // warning naming this command as the remedy, so the documented repair route was the
            // one that pushed it. And it is terminal — after a push the row holds a resolved
            // tenure and `outcome = MATCH`, so neither `staleVerdicts()` nor `pendingDigest()`
            // ever returns it again.
            $dwellingVeto = ExcludedDwellings::match($evidence, $excludedDwellings, $dwellingDedup);

            if ($dwellingVeto !== null) {
                ++$vetoed;

                continue;
            }

            $classification = $classifier->classify($evidence, $profile);
            $before = $store->outcome($key);

            if ($before === null) {
                // Every dedup MEMBER is classified; only the SURVIVOR is judged. `NULL` is exactly
                // what distinguishes "never judged" from "judged and rejected", and manufacturing an
                // outcome here would destroy that distinction for a row the engine never saw.
                if (!$dryRun && !$this->writeVerdict($store, $key, $classification, $evidence)) {
                    ++$unencodable;
                }

                continue;
            }

            $verdict = $engine->judge($evidence, $classification, $this->ageSeconds($store, $key));
            $after = $verdict->outcome->value;
            ++$rejudged;

            if ($after === 'MATCH' && $before !== 'MATCH') {
                // A PROMOTION, AND NOTHING IS WRITTEN YET. This row is left exactly as it was until
                // the channel confirms, and that deferral is the whole point rather than an
                // optimisation: writing the verdict here removes the row from `staleVerdicts()`
                // (its tenure resolves) AND from `pendingDigest()` (its outcome is no longer
                // DIGEST), so a failed send left a MATCH nobody was told about that NO command
                // could reach again — while the warning below promised a retry. There is no third
                // selector: `grep "notified_at IS NULL"` finds those two and nothing else.
                //
                // The population is exactly the one that cannot be rescued by the pipeline either:
                // a still-published listing is re-judged next pass, but these commands exist for
                // the listing that has since been delisted.
                //
                // Widened from `DIGEST -> MATCH` for the same reason. REJECT -> MATCH is not a
                // demotion, it is reachable the moment the criteria widen — Q1-Q3 widened three
                // filters in one day — and `docs/OPEN-QUESTIONS.md` already rules that a listing
                // which was disqualified and now qualifies IS a new match. Recording it silently
                // stranded it in the same unreachable state.
                $promotions[] = [
                    'listing' => $evidence,
                    'verdict' => $verdict,
                    'key' => $key,
                    'classification' => $classification,
                ];

                continue;
            }

            if (!$dryRun) {
                if (!$this->writeVerdict($store, $key, $classification, $evidence)) {
                    ++$unencodable;
                }
                $store->recordOutcome($key, $after);
            }

            if ($after !== $before) {
                ++$changed;
            }
        }

        // CAPPED HERE, before the summary, so the two numbers describe the same set. The cap used
        // to live inside `announcePromotions()`, which meant the summary said 54 while 50 were
        // announced — the same "counts what it found, not what it sent" defect the Q27 beat carried
        // twice this session, rebuilt in a third place.
        $overflow = max(0, \count($promotions) - Store::DIGEST_BATCH);
        $promotions = \array_slice($promotions, 0, Store::DIGEST_BATCH);

        if ($overflow > 0) {
            $this->line(sprintf(
                '%d promotion(s) au-delà du lot de %d — relancer `scout --domain=rent reclassify` pour la suite.',
                $overflow,
                Store::DIGEST_BATCH,
            ));
        }

        $this->line(sprintf(
            '%d annonce(s) re-jugée(s), %d verdict(s) modifié(s), %d promotion(s) vers MATCH.',
            $rejudged,
            // Promotions are NOT counted here: nothing has been written for them yet, and a
            // "modifié" that a failed send then rolls back would be a number the store contradicts.
            $changed,
            count($promotions),
        ));

        if ($vetoed > 0) {
            // Counted out loud, because a silent skip is indistinguishable from a bug — and this
            // one skips a listing the operator can see sitting in the store as undetermined.
            $this->line(sprintf(
                '%d annonce(s) écartée(s) par un doublon, un jumeau (autre voie) ou le même logement relevé sous une autre '
                . 'référence, au régime exclu ou indéterminé — leur verdict a été formé sur une preuve que leur propre '
                . 'instantané ne contient pas.',
                $vetoed,
            ));
        }

        if ($skipped > 0) {
            // Both causes are reachable HERE, unlike in `digest()`: `staleVerdicts()` selects
            // `tenure IS NULL`, which a genuine pre-v7 row has, AND `tenure = 'UNKNOWN'`, which a
            // listing whose payload could not be encoded has. Naming only the migration would be
            // the same invented cause the digest line carried.
            $this->line(sprintf(
                '%d annonce(s) sans instantané (antérieures au schéma v7, ou charge utile non encodable) '
                . '— ignorées : les re-juger sur moins de preuves que l\'originale est exactement la '
                . 'brèche que le §1 interdit.',
                $skipped,
            ));
        }
        if ($unreadable > 0) {
            $this->line($unreadable . ' instantané(s) illisible(s) — voir les avertissements ci-dessus.');
        }
        if ($unencodable > 0) {
            $this->line($unencodable . ' instantané(s) non ré-encodable(s) — ces annonces ne seront plus re-jugeables.');
        }

        if ($dryRun) {
            $this->line('--dry-run : aucun verdict réécrit, aucune notification envoyée.');

            return 0;
        }

        if ($promotions === []) {
            return 0;
        }

        // `$notifier` is non-null here: it is built above whenever `$dryRun` is false, and the
        // dry-run path returned already.
        return $this->announcePromotions($store, $notifier ?? $this->notifier($criteria), $promotions);
    }

    /**
     * Write a verdict and the evidence it was formed from, in the one statement the store provides.
     *
     * Extracted so the three call sites cannot drift (it was written for two; `announcePromotions()`
     * added the third and this line was not updated): a verdict written from a different snapshot
     * than the one just judged is the divergence `evidence_json` exists to make impossible.
     */
    private function writeVerdict(
        Store $store,
        string $key,
        \Scout\Rent\Core\Classification $classification,
        RawListing $evidence,
    ): bool {
        // The bool is RETURNED rather than discarded, even though it cannot currently be `false`
        // here: reclassify's evidence came out of `json_decode`, so it is valid UTF-8 of bounded
        // depth by construction and cannot fail to re-encode. `Store::recordVerdict()`'s docblock
        // says "the caller must say so out loud", and a caller typed `void` contradicts that in
        // writing — which is the sentence the next reader trusts.
        return $store->recordVerdict(
            $key,
            $classification->tenure->value,
            $classification->confidenceBp,
            $classification->reasons(),
            $evidence,
        );
    }

    /**
     * Push every DIGEST -> MATCH promotion as a new match.
     *
     * Being told a flat is DOUBTFUL is not being told it is a MATCH, so a row already carried in a
     * delivered digest is notified again here — that is the miss Q35 exists to recover, not a
     * duplicate.
     *
     * @param list<array{listing: RawListing, verdict: Verdict, key: string, classification: \Scout\Rent\Core\Classification}> $promotions
     */
    private function announcePromotions(Store $store, Notifier $notifier, array $promotions): int
    {
        $formatter = new Formatter();
        $now = $this->nowIso ?? date('c');
        $undelivered = 0;

        // The list arrives already capped — see the caller. Capping HERE made the summary count a
        // different set from the one announced.

        foreach ($promotions as $promotion) {
            $failures = $notifier->send($formatter->match($promotion['listing'], $promotion['verdict']));
            foreach ($failures as $failure) {
                $this->warn(Redact::text($failure->getMessage()));
            }

            if (!$notifier->delivered($failures)) {
                // NOTHING IS WRITTEN. The row keeps its old tenure and its old outcome, so it is
                // still selected by `staleVerdicts()` next run and the retry the warning promises
                // is real. Writing first — which this did until 2026-08-24 — removed it from every
                // selector at once and left a MATCH nobody was told about and nothing could reach.
                ++$undelivered;
                continue;
            }

            // ONLY NOW, and in this order: the verdict and its evidence, then the outcome, then the
            // delivery mark. Each is a fact that is true because the one before it is.
            if (!$this->writeVerdict($store, $promotion['key'], $promotion['classification'], $promotion['listing'])) {
                $this->warn('instantané non capturé pour ' . $promotion['key'] . ' — annonce non re-jugeable ensuite');
            }
            $store->recordOutcome($promotion['key'], $promotion['verdict']->outcome->value);
            $store->markNotified($promotion['key'], $now, 'MATCH');
        }

        if ($undelivered > 0) {
            $this->warn($undelivered . ' promotion(s) non délivrée(s) — rien n\'a été écrit, elles seront réessayées.');

            return 1;
        }

        return 0;
    }

    /** How long ago this listing was first seen, for the criteria engine's freshness component. */
    private function ageSeconds(Store $store, string $dedupKey): ?int
    {
        $snapshot = $store->snapshot($dedupKey);
        if ($snapshot === null) {
            return null;
        }

        try {
            $first = new \DateTimeImmutable($snapshot->firstSeenAt);
            $now = new \DateTimeImmutable($this->nowIso ?? 'now');
        } catch (\Exception) {
            return null;
        }

        return max(0, $now->getTimestamp() - $first->getTimestamp());
    }

    private function testNotify(): int
    {
        $criteria = $this->criteria();
        $notifier = $this->notifier($criteria);

        $fatal = $notifier->fatalProblem();
        if ($fatal !== null) {
            return $this->fail($fatal);
        }

        foreach ($notifier->disabledReport() as $name => $problem) {
            $this->warn('canal ' . $name . ' désactivé : ' . $problem);
        }

        $failures = $notifier->send(new \Scout\Core\Notify\Notification(
            kind: \Scout\Core\Notify\NotificationKind::HEARTBEAT,
            priority: Priority::NORMAL,
            title: 'rent-watch : test de notification',
            reasons: ['Si vous lisez ceci, le canal fonctionne.'],
        ));

        foreach ($failures as $failure) {
            $this->warn(Redact::text($failure->getMessage()));
        }

        return $notifier->delivered($failures) ? 0 : 1;
    }

    // ── plumbing ──────────────────────────────────────────────────────────────────────────────────

    /**
     * The commute planner, or `null` — which is the shipped state and must stay cheap to be in.
     *
     * `null` whenever commute is switched off in config OR `IDFM_API_KEY` is absent, and the two are
     * checked independently on purpose: a key with no destination is as useless as a destination
     * with no key, and neither is an error worth refusing a start over. The score component simply
     * does not run, `commuteMinutes` stays null, and the reasons say the trajet is unknown.
     *
     * **The reference departure is FIXED**, not "now". Cached durations must be measured against one
     * timetable or they are not comparable, and this is the heaviest component in the score — a
     * commune resolved at 02:00 against one resolved at 08:30 would reorder the whole list by the
     * hour a pass happened to run. Next Monday 08:30 is representative of the journey the user
     * actually cares about. Stated cost: it is a one-time sample of that timetable.
     */
    /**
     * The classifier, wired so a recognised ADVERTISER inherits that landlord's own profile.
     *
     * Without the resolver the classifier still substitutes — to {@see LandlordRegistry}'s
     * fail-closed unknown-landlord profile — so forgetting this cannot re-open the §1 hole; what it
     * restores is the landlord's own `default_tenure` hint, which is the difference between "In'li,
     * probably LLI, unverified" and "an institutional bailleur, nothing known". Both digest a bare
     * card, so the shipped default is safe and this is the accurate one.
     *
     * Reads `sources.json` afresh rather than taking a cached map: every other verb in this class
     * does the same, and a resolver holding a stale definition would judge a listing under a
     * `mixed_tenure` the operator has since revised.
     */
    private function classifier(): TenureClassifier
    {
        $definitions = ConfigLoader::loadSources($this->rootDir . '/config/rent/sources.json');

        return new TenureClassifier(profileFor: static fn (string $key): ?SourceProfile
            => isset($definitions[$key]) ? $definitions[$key]->profile() : null);
    }

    private function commutePlanner(Criteria $criteria, Store $store): ?CommutePlanner
    {
        if (!$criteria->commuteEnabled) {
            return null;
        }

        $key = getenv('IDFM_API_KEY');

        if (!is_string($key) || trim($key) === '') {
            return null;
        }

        return new NavitiaCommute(
            new CurlHttpClient(),
            $store,
            $criteria,
            trim($key),
            self::nextWeekdayAt('08:30'),
            $this->now(),
        );
    }

    /** The next weekday at `$time`, as Navitia's `YYYYMMDDTHHMMSS`. */
    private static function nextWeekdayAt(string $time): string
    {
        $at = new \DateTimeImmutable('tomorrow ' . $time);

        while (in_array($at->format('N'), ['6', '7'], true)) {
            $at = $at->modify('+1 day');
        }

        return $at->format('Ymd\\THis');
    }

    private function criteria(): Criteria
    {
        return ConfigLoader::loadCriteria(
            $this->rootDir . '/config/rent/criteria.json',
            $this->rootDir . '/config/rent/criteria.local.json',
        );
    }

    /**
     * A bound on the number of `--watch` passes, from `SCOUT_MAX_PASSES`. Absent — the normal
     * case, and the documented behaviour — means the loop runs until it is stopped.
     *
     * A TEST SEAM, in the same shape as `SCOUT_OFFLINE`, and it exists because `--watch` is the
     * one verb whose success case never returns. Without a bound, a test that expects the run to be
     * REFUSED and is wrong does not fail: it blocks, and takes the whole suite with it. The sabotage
     * ledger sat on exactly that for eleven minutes before it was noticed, and a gate that stalls
     * silently is worse than one that reports a failure.
     *
     * Anything that is not a positive integer is ignored rather than refused: this must never be the
     * reason a watcher will not start on a machine where somebody exported a stray value.
     */
    private function maxPasses(): ?int
    {
        $configured = getenv('SCOUT_MAX_PASSES');

        if (!is_string($configured) || preg_match('/^[1-9]\d*$/', trim($configured)) !== 1) {
            return null;
        }

        return (int) trim($configured);
    }

    /**
     * Where the Q27 liveness marker and the last startup refusal live.
     *
     * Beside the database, under `state/`, because Q8 rules that `state/` is the MOUNTED VOLUME on
     * the VPS — the one directory that survives a container being replaced. A marker in the image
     * would reset on every deploy, which for the refusal note means losing exactly the message it
     * exists to carry across a restart.
     *
     * Files rather than store rows, deliberately. A lost marker costs one extra low-priority
     * heartbeat, which is the benign direction; and `src/php/Rent/Store/**` is on
     * `tests/sabotage-check.sh`'s mandatory trigger list, so putting a liveness marker there would
     * owe a multi-hour ledger run for a timestamp. Q27 already rules `last-refusal.txt` to be a file
     * in `state/`, so its sibling matches the ruling's own shape.
     */
    /**
     * The state markers are per domain since the generic-scout restructuring (2026-08-29):
     * `rent-heartbeat.txt`, `rent-digest.txt`, `rent-last-refusal.txt`, beside the car domain's
     * `car-*`. A deployment that still carries the old unprefixed files is renamed ONCE, here, before
     * any command reads them — otherwise the first start after the redeploy finds no marker, beats
     * immediately, and re-serves a digest window it already served. Only when the new name is absent:
     * a marker written under the new name is newer than anything under the old one by construction.
     * `@rename` because a read-only volume must not turn a liveness signal into a crash (same rule as
     * the `@file_put_contents` in `beat()`).
     */
    private function migrateLegacyMarkers(): void
    {
        foreach (['heartbeat.txt' => 'rent-heartbeat.txt', 'digest.txt' => 'rent-digest.txt', 'last-refusal.txt' => 'rent-last-refusal.txt'] as $old => $new) {
            $from = $this->stateFile($old);
            $to = $this->stateFile($new);
            if (is_file($from) && !is_file($to)) {
                @rename($from, $to);
            }
        }
    }
    private function stateFile(string $name): string
    {
        $db = $this->dbPath();
        $dir = $db === ':memory:' ? $this->rootDir . '/state' : \dirname($db);

        return $dir . '/' . $name;
    }

    /** The instant of the last heartbeat, or `null` when none has been sent by this deployment. */
    private function lastHeartbeat(): ?string
    {
        $path = $this->stateFile('rent-heartbeat.txt');

        if (!is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);

        return \is_string($raw) && trim($raw) !== '' ? trim($raw) : null;
    }

    /**
     * Emit the Q27 heartbeat if it is due, and record that it was.
     *
     * Q27: low priority, *"stating runs completed, sources OK and matches sent"*, and emitted
     * **whether or not anything matched** — that is the entire point, since a watcher that only
     * speaks when it has news is silent in exactly the two cases that must be told apart.
     *
     * The marker is written only after a successful send. A heartbeat that failed to deliver must
     * not mark itself done, or a broken channel would be papered over by its own bookkeeping.
     */
    /** @param array<string, Source> $watched the sources this run actually polls, by name — see below */
    private function beat(
        Notifier $notifier,
        Store $store,
        int $passes,
        int $matches,
        ?string $refusal,
        array $watched,
        int $failedPasses = 0,
    ): void {
        $now = $this->now();

        // A BEAT AFTER A FAILING PASS MUST NOT READ LIKE A HEALTHY ONE, and until 2026-08-24 it did
        // — byte for byte. Two review lenses found it independently, which is the strongest signal
        // this panel produces.
        //
        // The sequence that produced it: the beat used to sit inside the pass's try, so a throwing
        // pass emitted NO beat, and Q27's banner says in as many words that silence past the
        // interval is the signal. Moving it to a `finally` fixed the silence and created something
        // worse — `++$passes` stays inside the try, so `$passes` was still 0 and rendered as
        // "démarrage de la surveillance"; `$matches` is passed 0; and the health figure reads the
        // run log, which `Pipeline`'s per-source loop already committed `ok = 1` to. An operator
        // whose watcher had thrown every pass for a week got a daily push saying it had just
        // started, notified nothing, and all sources were healthy. The only trace of the failure
        // was a stderr line, which under Docker is the log CLAUDE.md says nobody reads.
        //
        // So the beat now carries the vocabulary for it. A failed pass is named FIRST, because it
        // is the one thing on this notification that asks for action, and "startup" is never
        // printed once a pass has been attempted — starting is not what a watcher losing every
        // pass is doing.
        $reasons = [];

        if ($failedPasses > 0) {
            $reasons[] = $failedPasses . ' passe(s) EN ÉCHEC — voir les journaux';
        }

        $reasons[] = match (true) {
            $passes > 0 => $passes . ' passe(s) terminée(s)',
            $failedPasses > 0 => 'aucune passe terminée',
            default => 'démarrage de la surveillance',
        };
        $reasons[] = $matches . ' annonce(s) notifiée(s)';

        // The health figure counts WHAT THIS RUN WATCHES, not what the config enables. It used to
        // read every enabled source, so `--watch --source=x` against the shipped config reported
        // "1/5 source(s) en bon état" — four faults that did not exist, in the one channel whose
        // whole value is that it can be believed. It also degrades: an unpolled source's health
        // record goes STALE, so the beat would eventually alarm every day about sources nobody
        // asked it to watch, and a health line that always reads "4 broken" is a line its reader
        // learns to skip.
        $ok = 0;
        foreach ($watched as $source) {
            if ($source->health($now)->status === SourceStatus::OK) {
                ++$ok;
            }
        }
        $reasons[] = $ok . '/' . \count($watched) . ' source(s) en bon état';

        // But silence about the scope would replace one wrong reading with another: a deployment
        // carrying a forgotten `--source` would report a flawless 1/1 forever while four landlords
        // went unwatched. The startup banner already states the count, and it is a log line nobody
        // re-reads; the beat is what reaches the phone, so the scope has to travel WITH the figure.
        // Only when it is true — a permanent parenthetical on every beat is noise, and noise on the
        // liveness channel costs the same as a false alarm.
        $configured = \count($this->sourceNames());

        if ($configured !== \count($watched)) {
            $reasons[] = 'limité par --source : ' . $configured . ' configurée(s), '
                . \count($watched) . ' surveillée(s)';
        }

        if ($refusal !== null) {
            // Q27's other half: "the next successful start can report what happened while it was
            // down". Carried on the startup beat, because a refusal that only sat in a file would
            // be read by somebody who already knew to look.
            $reasons[] = 'refus au démarrage précédent : ' . $refusal;
        }

        $failures = $notifier->send(new \Scout\Core\Notify\Notification(
            kind: \Scout\Core\Notify\NotificationKind::HEARTBEAT,
            priority: Priority::LOW,
            title: 'rent-watch : toujours actif',
            reasons: $reasons,
        ));

        foreach ($failures as $failure) {
            $this->warn(Redact::text($failure->getMessage()));
        }

        if ($notifier->delivered($failures)) {
            @file_put_contents($this->stateFile('rent-heartbeat.txt'), $now . "\n");

            // THE NOTE IS CONSUMED ON DELIVERY, NOT ON COMPOSITION (round-5 panel, 2026-08-31).
            // Round 4 moved the read from startup into the beat and claimed that made it "survive
            // until a beat actually carries it". It survived until a beat was ATTEMPTED: the marker
            // above is deliberately not written when delivery fails, so the beat RETRIES — but the
            // note had already been unlinked, so the retry carried nothing and the refusal was lost
            // for good.
            //
            // The correlation is the whole point rather than a corner case: the commonest startup
            // refusal there is IS a channel misconfiguration (this repo's own example note reads
            // `canal ntfy sans RENT_NTFY_TOPIC`), so the beat that ought to carry the note is
            // precisely the beat most likely to fail. Consuming it here puts it on the same
            // all-or-nothing footing as the marker beside it.
            $this->clearLastRefusal();
        }
    }

    /** The instant of the last digest emission, or `null` when this deployment has sent none. */
    private function lastDigestEmission(): ?string
    {
        $path = $this->stateFile('rent-digest.txt');

        if (!is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);

        return \is_string($raw) && trim($raw) !== '' ? trim($raw) : null;
    }

    /**
     * Q34's DAILY FLOOR: drain the *à vérifier* bin if the local `digest_hour` has come round again.
     *
     * The gap this closes: both other emission paths are event-driven — the pipeline emits at the end
     * of a pass that produced NEW entries, and `scout --domain=rent digest` emits when a human types it. So a
     * backlog that failed to send, or that was left over by the batch cap, sat in §1's only landing
     * zone until somebody thought to look. Q34 rules a daily floor for exactly that, and it was the
     * one third of the ruling never built.
     *
     * **SILENT WHEN THE BIN IS EMPTY, and the marker is NOT written in that case** (developer ruling,
     * 2026-08-26). Two consequences, both wanted. A quiet day costs nothing at all — no push, and
     * `Core/Heartbeat` already carries the daily liveness signal, so an unconditional emission would
     * be a second scheduled push saying nothing the beat did not. And because no marker is written,
     * the window stays open: an entry whose send failed earlier is retried on the next pass rather
     * than waiting for tomorrow, which is what Q34's *"an unsent digest is retried"* asks for.
     *
     * The marker is written ONLY after the channel confirms delivery, exactly as {@see beat()} does
     * and for a sharper reason: marking a window served on a failed send would consume the day's
     * floor without anything reaching the developer.
     *
     * Takes the loop's notifier rather than building one, because building a `Notifier` per check
     * would re-print every `disabledReport()` warning on every pass of a run that lasts weeks.
     */
    private function floorDigest(Notifier $notifier, Store $store, string $now): void
    {
        $batch = $this->collectDigest($store);

        // WARNINGS FIRST, BECAUSE AN EMPTY BATCH CAN CARRY THEM (C2 round 7, resilience P2). Every
        // queued row can be skipped — re-judged REJECT today, vetoed by its twin or its group, an
        // unreadable snapshot — while the tenure bin is empty, and the floor returned before this
        // loop. `--watch` is the DEPLOYED mode, so a row stuck in the queue for ever was reported
        // only if a human happened to type the verb.
        foreach ($batch->warnings as $warning) {
            $this->warn($warning);
        }

        if ($batch->isEmpty()) {
            // Nothing to say, so nothing is said, and the window stays open for whatever arrives.
            return;
        }

        if ($batch->withoutSnapshot > 0) {
            // Voiced here too, not only by `scout --domain=rent digest`. This count does not mean "old rows": the
            // query filters on `outcome`, a v7 column that is not backfilled, so a pre-v7 row is
            // never returned at all. The only reachable cause is a listing whose own payload could
            // not be JSON-encoded — a LIVE SOURCE FAULT. A floor that announced those rows silently
            // would drain the bin while losing the one signal that says a source is emitting
            // payloads nothing can encode.
            $this->warn(sprintf(
                '%d annonce(s) sans instantané (charge utile non encodable) — annoncées depuis les colonnes conservées.',
                $batch->withoutSnapshot,
            ));
        }

        // The retries first, as individual pushes: the floor is the only automatic drain a
        // `--watch` deployment has, so a failed push that outlived its source's re-emission is
        // recovered HERE or nowhere. The verb does the same through the same method.
        //
        // STATED COST (C2 round 7, resilience P3): a retries-ONLY batch returns below without
        // writing the window marker, so the "daily" floor is re-entered on the next pass — every
        // ~15 minutes — draining another `Store::DIGEST_BATCH` of 50. That is the intended retry
        // semantics for a failed send and it is capped, but on a no-gate deployment whose channel
        // has been down it turns a backlog of N queued matches into N individual pushes at 50 per
        // quarter-hour, where the pre-A5 behaviour was one capped mail per drain. `Pacer` covers
        // source fetches, not notification sends, so there is no jitter between them.
        $drainedKeys = $this->pushRetries($notifier, $store, $batch->retries, $now);

        if ($batch->entries === [] && $batch->lowScore === []) {
            return;
        }

        $notification = (new Formatter())->digest($batch->entries, $batch->lowScore);
        $failures = $notifier->send($notification);

        foreach ($failures as $failure) {
            $this->warn(Redact::text($failure->getMessage()));
        }

        if (!$notifier->delivered($failures)) {
            // Nothing marked and no marker written: the batch survives, the window stays open, and
            // the next pass retries. Marking first would consume these entries permanently on a
            // failed send, and they have no other route to the developer.
            $this->warn('récapitulatif quotidien non délivré — rien marqué, nouvel essai au prochain passage.');

            return;
        }

        foreach ($batch->entries as $entry) {
            $store->markNotified($entry['key'], $now, 'DIGEST');
        }
        foreach ($batch->lowScore as $entry) {
            foreach ($entry['keys'] as $key) {
                $store->markNotified($key, $now, 'ROLLUP');
            }
        }

        $this->line(sprintf(
            'récapitulatif quotidien « à vérifier » : %d annonce(s) émise(s)%s.',
            $batch->count(),
            $batch->overflow($drainedKeys, true) > 0
                // Named, like every other cap in this file. A floor that drains one batch a day
                // without saying so reads as the whole backlog having been dealt with — and it is
                // counted AFTER the retries were attempted, because a refused one stays queued.
                ? sprintf(' — %d autre(s) en attente (lot de %d)', $batch->overflow($drainedKeys, true), Store::DIGEST_BATCH)
                : '',
        ));

        @file_put_contents($this->stateFile('rent-digest.txt'), $now . "\n");
    }

    /** @return list<string> */
    private function sourceNames(): array
    {
        $names = [];
        foreach (ConfigLoader::loadSources($this->rootDir . '/config/rent/sources.json') as $definition) {
            if ($definition->enabled) {
                $names[] = $definition->name;
            }
        }

        return $names;
    }

    private function dbPath(): string
    {
        $configured = getenv('RENT_SCOUT_DB');

        return is_string($configured) && trim($configured) !== ''
            ? $configured
            : $this->rootDir . '/state/rent-watch.sqlite3';
    }

    private function store(): Store
    {
        return Store::open($this->dbPath(), self::feedSilentDays());
    }

    /**
     * How many days of portal silence make a still-producing source `FEED_SILENT`.
     *
     * **The threshold MUST be strictly under the IMAP window, and that is a reachability
     * constraint rather than a preference.** The newest message `SEARCH SINCE` can match is by
     * definition at most `IMAP_SINCE_DAYS` old, so while the count is non-zero the feed's age is
     * BOUNDED by that window: at `RENT_FEED_SILENT_DAYS >= IMAP_SINCE_DAYS` the count collapses to zero —
     * and the existing empty-streak machinery takes the verdict — before the age can ever reach the
     * threshold. The status would be unreachable *by construction*, which is precisely how
     * `high_priority_score: 70` sat dead for weeks while looking configured. Refused loudly instead.
     *
     * The observable band is `(threshold, window)`. On the defaults, 3 and 7, that is roughly four
     * days of early warning before the count would have collapsed on its own.
     *
     * **The default is MEASURED, not chosen.** Firing cadences over 14 days on the live mailbox,
     * 2026-08-28: Bien'ici ~30/day with a longest gap of about 4.5 hours, PAP ~8/day, SeLoger 160 in
     * seven days — none of which has ever been quiet for a day, let alone three — against
     * `leboncoin`, which has sent exactly ONE alert since its creation. Three days catches leboncoin
     * and cannot false-fire on the others.
     *
     * **Stated cost:** a source firing thirty times a day is only noticed after three days of
     * silence, which is ~90 missed alerts. A per-source override is the obvious answer and **does
     * not exist** — there is no such key in `config/rent/sources.json`, and because unknown source keys
     * are a `ConfigError`, an operator who added one would get a refusal rather than a setting.
     * (An earlier version of this docblock claimed the override had shipped. It had not.) The
     * measurement above is the argument FOR building it, not a record that it was built.
     */
    private static function feedSilentDays(): ?int
    {
        // Every refusal below is a `ConfigError`, NOT an `InvalidArgumentException`. Only
        // `ConfigError` is caught at the top of `run()`, so the other would reach the user as a
        // stack trace and — worse — would skip `recordRefusal()`, the Q27 machinery that makes a
        // startup refusal visible on the next successful beat. Under Docker the process exits
        // before any channel exists and its stderr scrolls past in a log nobody reads.

        $raw = getenv('RENT_FEED_SILENT_DAYS');

        if ($raw === false || trim((string) $raw) === '') {
            $days = self::DEFAULT_FEED_SILENT_DAYS;
        } elseif (preg_match('~^\d+$~', trim((string) $raw)) !== 1) {
            throw new ConfigError(sprintf(
                'RENT_FEED_SILENT_DAYS doit être un entier de jours, reçu : %s',
                trim((string) $raw),
            ));
        } else {
            $days = (int) trim((string) $raw);
        }

        if ($days < 1) {
            // Refused, not clamped. Zero would disable the only signal that distinguishes a dead
            // alert from a quiet market — the same asymmetry as `RENT_HEARTBEAT_HOURS`, where an omitted
            // value is benign and an explicit unusable one is a configuration error in the shape of
            // a setting.
            throw new ConfigError(
                'RENT_FEED_SILENT_DAYS doit valoir au moins 1 jour — 0 désactiverait la détection de flux muet',
            );
        }

        return $days;
    }


    /**
     * Every source that is enabled AND has an adapter.
     *
     * A source of a type with no adapter yet is skipped with a warning rather than crashing the
     * run — but it is NOT silent, because a source that quietly does not run is the exact shape of
     * failure the health subsystem exists to make impossible.
     *
     * @return list<Source>
     */
    /** @param list<string> $argv the flags this invocation was handed, for hard rule 4's opt-in */
    private function sources(Store $store, ?array $only = null, ?Criteria $criteria = null, array $argv = []): array
    {
        $out = [];
        $known = [];
        // One `robots.txt` per host per build, and the cache is a LOCAL rather than a property so
        // its lifetime is exactly this call. Hard rule 5 is about load as well as permission:
        // re-reading robots for every page of a four-page walk is a request the site did not need
        // to serve. See {@see RobotsResolver} on why the resolver itself does not hold this.
        /** @var array<string, Robots> $robotsByOrigin */
        $robotsByOrigin = [];

        foreach (ConfigLoader::loadSources($this->rootDir . '/config/rent/sources.json') as $definition) {
            if ($only !== null && !in_array($definition->name, $only, true)) {
                continue;
            }
            $known[] = $definition->name;

            if (!$definition->enabled) {
                // `--source=<name>` FORCE-RUNS a disabled source, and only an explicit name does.
                //
                // Without this, `/add-source` step 5 — "run `scout --domain=rent doctor` against the new block,
                // flip `enabled: true` once it is green" — could not be followed in that order: the
                // block had to be enabled before anything would run it, which is the edit to
                // committed config the flag exists to avoid. `dump` has always resolved a source by
                // name regardless of `enabled`, so this makes the verbs agree rather than inventing
                // a rule. What it is NOT is a loosening of the enabled check: an ordinary run still
                // skips the source, which `testADisabledSourceStaysOutOfAnOrdinaryRun` pins.
                if ($only === null) {
                    continue;
                }

                // LOUD, because the failure this could become is a `--source` left behind in a
                // deployment: a source nobody enabled, running every fifteen minutes, indis-
                // tinguishable in the output from one somebody turned on deliberately.
                $this->warn('source ' . $definition->name . ' est désactivée dans la config, '
                    . 'exécutée parce qu\'elle est nommée explicitement');
            }

            if ($definition->requiresScrapingOptIn() && !$this->scrapingAllowed($argv)) {
                // Hard rule 4 / Q26. Direct scraping of a private portal refuses to run without an
                // explicit flag, and the flag is never persisted in config — so starting a scrape is
                // a deliberate act each time rather than a boolean somebody flipped once.
                $this->warn('source ' . $definition->name . ' ignorée : le scraping direct d\'un portail privé '
                    . 'exige --i-accept-legal-risk sur cette invocation');

                continue;
            }

            $source = $this->buildSource($definition, $store, $criteria, $robotsByOrigin);
            if ($source === null) {
                $this->warn('source ' . $definition->name . ' ignorée : aucun adaptateur pour le type `'
                    . $definition->type . '`');

                continue;
            }

            $out[] = $source;
        }

        // A `--source` naming nothing is a typo, and a typo that silently runs zero sources is the
        // worst possible outcome of a debugging flag: it reports a clean, fast, empty pass. Name
        // what was asked for and what exists, and let the caller decide.
        if ($only !== null) {
            $absent = array_values(array_diff($only, $known));
            if ($absent !== []) {
                $this->warn('source(s) inconnue(s) : ' . implode(', ', $absent));
            }
        }

        return $out;
    }

    /**
     * `--source=<name>`, repeatable. `null` means every enabled source.
     *
     * The flag exists for the moment a new source is onboarded: `/add-source` asks for a run
     * against exactly one block, and without this the only way to get one was to disable the
     * others — an edit to committed config, made under time pressure, easy to forget to undo.
     *
     * @param list<string> $flags
     *
     * @return list<string>|null
     */
    private function onlySources(array $flags): ?array
    {
        $names = [];
        foreach ($flags as $flag) {
            if (str_starts_with($flag, '--source=')) {
                $name = trim(substr($flag, strlen('--source=')));
                if ($name !== '') {
                    $names[] = $name;
                }
            }
        }

        return $names === [] ? null : $names;
    }

    /**
     * @param ?Criteria $criteria the run's own filters, from which the hydration RANKING is built.
     *        No longer a gate — novelty is the gate and it lives in the store's detail cache; this
     *        only decides who goes first when a pass's budget cannot cover every candidate.
     */
    private function buildSource(
        SourceDefinition $definition,
        Store $store,
        ?Criteria $criteria = null,
        array &$robotsByOrigin = [],
    ): ?Source {
        // THE RANKING, and rank 0 is the one that matters.
        //
        // A listing NOT YET IN THE SEEN-SET is about to be notified on this very pass, and it is
        // the only candidate for which hydration can still change the outcome. Ranked any lower, it
        // loses its slot to backlog — and by the time backlog's slot comes round it has already
        // been notified unhydrated, with no title checked and no floor, so hydrating it then buys
        // nothing at all. That is the pass-2 bypass this whole design exists to close, rebuilt out
        // of a budget instead of a missing cache.
        //
        // Rank 1 is the geographic filter, and WHY that one and not the whole criteria set:
        // `matchesCommune()` is the only filter whose inputs a CARD already carries in full.
        // Ranking on rent or surface would deprioritise on a field the detail page might have been
        // the one to supply — and while ordering can only delay a listing rather than reject it,
        // delaying on unknown-because-unfetched is the same reasoning error hard rule 8 names.
        //
        // Rank 2 is everything else: hydrated eventually, in source order, out of the backlog.
        $priority = $criteria === null
            ? null
            : static function (RawListing $listing) use ($criteria, $store): int {
                if ($store->snapshot($store->dedupKey($listing)) === null) {
                    return 0;
                }

                return $criteria->matchesCommune($listing->commune, $listing->postcode) ? 1 : 2;
            };

        // Hard rule 1, enforced at the single funnel every verb passes through. The LOADER refuses
        // an `enabled: true` source carrying the placeholder, and that was the whole guard for as
        // long as a disabled source could never run; `--source=<name>` force-running one, and
        // `dump` having always done so, both bring it back within reach. Refused rather than
        // attempted: fetching the literal string fails anyway, but with a curl error that says
        // nothing about why.
        if ($definition->url === ConfigLoader::UNVERIFIED_URL) {
            throw ConfigError::at(
                'sources.' . $definition->name . '.url',
                'still the ' . ConfigLoader::UNVERIFIED_URL . ' placeholder — its endpoint has never '
                    . 'been verified against the live site (CLAUDE.md hard rule 1), so there is '
                    . 'nothing to poll',
            );
        }

        return match ($definition->type) {
            'fixture' => new FixtureSource($definition, $store, $this->rootDir),
            // The ADAPTER exists and is tested; what waits on the developer is the URL in
            // `config/rent/sources.json`. Hard rule 1 forbids writing an endpoint from memory, and the
            // loader refuses `enabled: true` next to a REMPLACER placeholder — so this can never
            // poll something nobody verified, while still being ready the moment a capture lands.
            'json' => new HttpJsonSource($definition, $store, $this->http, $this->robotsFor($definition, $robotsByOrigin)),
            // Same shape as `json`, one step different in the middle: the payload is parsed as
            // HTML5 by the language's own `Dom\HTMLDocument` and the field map is read as CSS
            // selectors. In'li is the first real source to use it — its search page is
            // server-rendered, so there is no JSON endpoint to prefer.
            'html' => new HtmlSource(
                $definition,
                $store,
                $this->http,
                $this->robotsFor($definition, $robotsByOrigin),
                $priority,
                // The CLOCK, not a reading of it. `$sources` is built ONCE, before the watch loop,
                // so `now()` here would hand every source the moment the PROCESS started and keep
                // it for weeks: the detail backoff would compute `now - since` as zero for ever and
                // never retry a failed page, and every `fetched_at` in `listing_detail` would
                // record process start rather than the fetch. `null` in production means the source
                // reads real time on each pass; an injected value still propagates for tests.
                $this->nowIso,
            ),
            'email_alert' => $this->buildEmailSource($definition, $store),
            default => null,
        };
    }

    /**
     * The `robots.txt` verdict governing this source, read once per host per process.
     *
     * Never `null`. A `null` here is precisely the defect this method was added to close: both
     * adapters guard every robots check with `$this->robots !== null`, so passing `null` does not
     * mean *"check later"* — it means *"never check"*, silently, on every real poll. A source whose
     * url carries no usable host therefore gets a fail-closed verdict rather than an exemption.
     */
    private function robotsFor(SourceDefinition $definition, array &$robotsByOrigin): Robots
    {
        $url = $definition->url;

        if ($url === null || trim($url) === '') {
            return Robots::unavailable('la source ne déclare aucune url');
        }

        $origin = $this->robotsResolver->originFor($url);

        if ($origin === null) {
            return $this->robotsResolver->forUrl($url);   // yields the fail-closed verdict, with its reason
        }

        return $robotsByOrigin[$origin] ??= $this->robotsResolver->forUrl($url);
    }

    /**
     * The email-alert path, with its mailbox chosen by `.env`.
     *
     * `MAILBOX_DIR` points at a directory of `.eml` files and needs no credentials at all — which is
     * how the whole parse → classify → notify path is exercisable before an IMAP account exists, and
     * how a real alert becomes a regression fixture once one does. `IMAP_*` switches to the live
     * mailbox with no other change.
     */
    private function buildEmailSource(SourceDefinition $definition, Store $store): ?Source
    {
        // THE SENDER, re-checked here for the same reason the REMPLACER refusal moved into this
        // funnel. The loader refuses a `from`-less `email_alert` only when it is `enabled: true`,
        // and `--source=<name>` force-runs a disabled one — so a drafted block would read EVERY
        // message in the shared label within the window and ingest other portals' alerts as its
        // own, under its own `default_tenure`.
        //
        // Above the mailbox check on purpose: this is a fault in the config, not in the
        // environment, and reporting it as *"no mailbox configured"* would send the reader to
        // `.env` for a fault in JSON.
        $from = $definition->params['from'] ?? null;

        if (!\is_string($from) || trim($from) === '') {
            throw ConfigError::at(
                'sources.' . $definition->name . '.params.from',
                'an email_alert source must name the sender it reads. One mailbox serves every '
                    . 'portal, so without this it ingests other portals\' alerts as its own and '
                    . 'reports a plausible count while doing it',
            );
        }

        $mailbox = $this->buildMailbox($definition);

        if ($mailbox === null) {
            $this->warn('source ' . $definition->name . ' ignorée : ni MAILBOX_DIR ni IMAP_HOST ne sont '
                . 'définis, donc aucune boîte aux lettres à lire');

            return null;
        }

        return new EmailAlertSource(
            $definition,
            $store,
            $mailbox,
            $this->criteria()->communeLabels,
            // Measured 2026-08-26: SeLoger matched 107 messages in the seven-day window against the
            // default 50, so `IMAP_SINCE_DAYS=7` was buying that source about three days of
            // catch-up. Raising this is not free — it is also the number of bodies fetched on every
            // one of ~96 daily passes — which is why the knob ships beside a warning that says when
            // it is biting rather than a larger default that hides it.
            ImapMailbox::maxMessages(getenv('IMAP_MAX_MESSAGES') ?: null),
            // An identity collision inside one message drops a card instead of taking the source
            // down (2026-08-26). Dropping it silently is the failure the old throw existed to
            // prevent, so this channel is what keeps the ruling honest.
            warn: fn (string $message): mixed => $this->warn('source ' . $definition->name . ' : ' . $message),
        );
    }

    /**
     * The mailbox a source reads from — scoped to that source, not to the deployment.
     *
     * The definition is a parameter because `params.from` is pushed INTO the IMAP query. One
     * mailbox serves every email source, so a single shared fetch window is a shared budget: the
     * day the developer pointed five portals at one Gmail label, SeLoger's alerts stopped fitting
     * in it and the source read zero listings while publishing normally. A per-source `FROM` gives
     * each one its own window. See {@see ImapMailbox::searchCommand()}.
     */
    private function buildMailbox(SourceDefinition $definition): ?Mailbox
    {
        $directory = (string) (getenv('MAILBOX_DIR') ?: '');
        if ($directory !== '') {
            return new FileMailbox($directory);
        }

        $host = (string) (getenv('IMAP_HOST') ?: '');
        if ($host === '') {
            return null;
        }

        $from = $definition->params['from'] ?? null;

        return new ImapMailbox(
            host: $host,
            user: (string) (getenv('IMAP_USER') ?: ''),
            password: (string) (getenv('IMAP_PASSWORD') ?: ''),
            folder: (string) (getenv('RENT_IMAP_MAILBOX') ?: 'INBOX'),
            port: (int) (getenv('IMAP_PORT') ?: 993),
            fromFilter: \is_string($from) && $from !== '' ? $from : null,
            sinceDays: (int) (getenv('IMAP_SINCE_DAYS') ?: 7),
            // The mailbox has no channel of its own — `fetchRecent()` returns messages and nothing
            // else. Handing it `warn()` puts the line wherever the operator is already looking
            // rather than inventing a second place to look.
            warn: fn (string $message): mixed => $this->warn('source ' . $definition->name . ' : ' . $message),
        );
    }

    /**
     * Hard rule 4's opt-in: has the operator accepted the legal risk of scraping a private portal?
     *
     * Reads BOTH the argv this invocation was handed and the process argv, and that is deliberate
     * rather than belt-and-braces. It used to read `$_SERVER['argv']` alone, which is a different
     * source of truth from every other flag in this class — so the PERMITTING branch was
     * unreachable through the seam every test uses, and all three existing tests assert refusal.
     * Nothing proved the flag actually works, and nothing would have gone red if this literal
     * drifted from the one in `help`.
     *
     * The failure direction was closed (over-refusal, never over-permission), which is why this is
     * a P2 and not the other kind. But hard rule 4's gate is the one place in the tree where "no
     * test covers the permitting path" is worth saying out loud.
     */
    /** @param list<string> $argv */
    private function scrapingAllowed(array $argv): bool
    {
        $flag = '--i-accept-legal-risk';

        return in_array($flag, $argv, true) || in_array($flag, $_SERVER['argv'] ?? [], true);
    }

    /**
     * Say so when NOTHING in the notifier can reach a recipient.
     *
     * `console` cannot deliver, and neither can `email` over `SMTP_TRANSPORT=file` — so a run in
     * that state announces to this terminal and marks NOTHING notified. Deliberately not fatal:
     * `run --once` at a terminal is exactly that shape, and refusing would take a working local
     * run away to punish a deployment mistake. But it must not be QUIET, because under Q8 the
     * process is headless and the container log is nobody's notification channel.
     *
     * Shared by `run`, `digest` and `reclassify`. It lived only on `run` for one review round,
     * and the other two do not merely lack the warning — they print a retry promise that is
     * unconditionally FALSE in that configuration ("elles seront réessayées"), for ever, while the
     * §1 digest backlog never drains.
     */
    private function warnIfNothingDelivers(Notifier $notifier): void
    {
        if ($notifier->hasRemoteChannel()) {
            return;
        }

        $this->warn(
            'aucun canal n\'atteint de destinataire : les annonces seront écrites ici et RIEN '
            . 'ne sera marqué notifié. `console` et `email` via SMTP_TRANSPORT=file ne '
            . 'comptent pas — voir `scout --domain=rent doctor`. Configurez `ntfy`, ou `email` via '
            . 'SMTP_TRANSPORT=smtp|sendmail.',
        );
    }

    private function notifier(Criteria $criteria): Notifier
    {
        if ($this->notifier !== null) {
            return $this->notifier;
        }

        $channels = [];

        foreach ($criteria->notify->channels as $name) {
            $channel = $this->buildChannel($name);
            if ($channel !== null) {
                $channels[] = $channel;
            }
        }

        return new Notifier($channels);
    }

    private function buildChannel(string $name): ?Channel
    {
        // ONE factory for both domains (2026-08-29): the car CLI builds the same three channels
        // with its own prefix, From default and ntfy topic, and two copies of this logic is how
        // one drifts. The unknown-name refusal — hard rule 2's "computed and never sent" — lives
        // there now, still as a separate one-line guard a sabotage can remove.
        return ChannelFactory::build($name, $this->out, $this->rootDir, '[rent-watch]', 'rent-watch@localhost', (string) (getenv('RENT_NTFY_TOPIC') ?: ''), 'RENT_NTFY_TOPIC', '🏠 RENT ·', 'house');
    }

    /**
     * How email leaves, chosen by `SMTP_TRANSPORT`.
     *
     * `file` writes `.eml` files and needs nothing — it is how `scout test-notify` produces a real,
     * readable message with no server and no credential. `smtp` speaks the protocol with `.env`
     * credentials. `sendmail` hands off to a host MTA, which is right under a Docker compose stack
     * that runs one.
     *
     * The default is `smtp` WHEN `SMTP_HOST` is set and `sendmail` otherwise, rather than a fixed
     * choice: a developer who filled in the SMTP block expects it to be used, and one who did not
     * has an MTA or does not want email at all.
     */
    private function buildMailTransport(): MailTransport
    {
        return ChannelFactory::mailTransport($this->rootDir, 'rent-watch@localhost');
    }

    private function now(): string
    {
        return $this->nowIso ?? (new \DateTimeImmutable())->format('Y-m-d\TH:i:sP');
    }

    private function unknownCommand(string $command): int
    {
        $this->warn('commande inconnue : ' . $command);
        $this->help(2);

        return 2;
    }

    private function help(int $code): int
    {
        foreach ([
            'scout --domain=rent — le rent-watch : veille sur les annonces de location en Île-de-France',
            '',
            '  scout --domain=rent doctor                  état, durée et volume de chaque source',
            '  scout --domain=rent dump <source>           première annonce brute + field map appliqué
  scout --domain=rent replay <source>         alias de `dump` (prend un NOM de source, pas un fichier)
  … --file=<charge utile>                     relit un fichier figé à travers le field map d\'une source html/json,
                                              sans réseau et sans toucher la base (email_alert : MAILBOX_DIR)',
            '  scout --domain=rent run --once [-v]         une passe complète',
            '  scout --domain=rent run --seed              amorce le seen-set sans notifier',
            '  scout --domain=rent run --watch [-v]        boucle : 15 min ± 5 de jitter (Q37), battement Q27 (state/rent-heartbeat.txt)',
            '  … --source=<nom>                            limite `doctor` / `run` à une source (répétable)',
            '  scout --domain=rent digest [--dry-run]      émet le récapitulatif « à vérifier » en attente',
            '  scout --domain=rent reclassify [--dry-run]  re-juge les verdicts indéterminés stockés',
            '  scout --domain=rent test-notify             vérifie les canaux de notification',
            '',
            '  --i-accept-legal-risk                       requis pour toute source `legal_risk` (règle 4)',
            '',
            '  config : config/rent/criteria.json (+ criteria.local.json), config/rent/sources.json',
            '  env    : RENT_SCOUT_DB, RENT_IMAP_MAILBOX, RENT_NTFY_TOPIC, RENT_HEARTBEAT_HOURS, RENT_FEED_SILENT_DAYS',
            '           (les identifiants IMAP/SMTP, NTFY_SERVER, IMAP_SINCE_DAYS et IMAP_MAX_MESSAGES sont partagés)',
        ] as $line) {
            $this->line($line);
        }

        return $code;
    }

    private function show(mixed $value): string
    {
        return match (true) {
            $value === null => '(null)',
            $value === true => 'true',
            $value === false => 'false',
            default => (string) $value,
        };
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
        $this->warn($text);

        return 2;
    }

    /**
     * A startup refusal from `scout --domain=rent run`: reported now, and RECORDED for the next start (Q27).
     *
     * Scoped to `run` on purpose. A `doctor` refusal is read by whoever typed it, in the terminal
     * they typed it in, so recording it would make the next watch start report noise the operator
     * already saw and already acted on.
     */
    private function failRun(string $text): int
    {
        $this->recordRefusal($text);

        return $this->fail($text);
    }

    /**
     * Record why `scout --domain=rent run` refused to start, for the next successful start to report (Q27).
     *
     * A startup refusal is the one failure that reaches nobody: the process exits before any
     * notification channel is used, and under Docker its stderr scrolls past in a log the operator
     * is not reading. Q27's answer is to leave it on the mounted volume so the next start can say
     * what happened while it was down.
     *
     * **Redacted before it touches the disk.** A refusal text can carry a channel credential problem
     * or a URL with a key in it — `Store::recordRun()` redacts at its funnel for the same reason,
     * and this is a second disk-write funnel, so it needs the same guard rather than trusting the
     * caller. Failure to write is swallowed on purpose: a full or read-only volume must not turn a
     * refusal-with-a-reason into a crash with none.
     */
    private function recordRefusal(string $text): void
    {
        @file_put_contents($this->stateFile('rent-last-refusal.txt'), $this->now() . ' — ' . Redact::text($text) . "\n");
    }

    /** The previous startup refusal, removed as it is read so it is reported exactly once. */
    /**
     * Cleared only once a beat has DELIVERED — see the call site. Kept separate from
     * {@see pendingRefusal()} so that reading the note and consuming it are two decisions.
     */
    private function clearLastRefusal(): void
    {
        @unlink($this->stateFile('rent-last-refusal.txt'));
    }

    /** The pending note WITHOUT consuming it — `doctor` reports, the heartbeat consumes. */
    private function pendingRefusal(): ?string
    {
        $path = $this->stateFile('rent-last-refusal.txt');

        if (!is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);

        return \is_string($raw) && trim($raw) !== '' ? trim($raw) : null;
    }
}
