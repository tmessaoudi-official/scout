<?php

declare(strict_types=1);

namespace Scout\Tests\Rent\Cli;

use PHPUnit\Framework\TestCase;
use Scout\Rent\Adapters\HtmlSource;
use Scout\Rent\Store\Store;
use Scout\Rent\Cli\RentScout;
use Scout\Core\Notify\ConsoleChannel;
use Scout\Core\Notify\Notifier;
use Scout\Tests\Support\DeliveringChannel;
use Scout\Rent\Core\RawListing;

/**
 * The CLI, driven end to end against the committed fixture source.
 *
 * Every assertion here reads REAL stdout. `CLAUDE.md` § Completion gate is explicit that a claim
 * about `scout` output is evidenced by its actual output — *"the doctor command is implemented"* is
 * not evidence, and a test that asserted on a mocked writer would be the same claim in a different
 * costume.
 *
 * Categories: **exit codes** (this runs under a supervisor) · **doctor** (§8's four columns) ·
 * **seed** (Q36) · **refusals** (Q26, Q28, Q36) · **payload** (what actually reaches a phone).
 */
final class RentScoutTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../../..';

    private ?string $dbPath = null;

    /** @var list<string> */
    private array $tempRoots = [];

    private ?string $demoRootPath = null;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/rentwatch-cli-' . bin2hex(random_bytes(8)) . '.sqlite3';
        putenv('RENT_SCOUT_DB=' . $this->dbPath);
        // `--watch` never returns on its own. Every test here that reaches it expects to be stopped
        // BEFORE the loop starts, so if that expectation is ever wrong the test would block rather
        // than fail — and block the suite, and the sabotage ledger behind it. One pass is enough to
        // prove any of them.
        putenv('SCOUT_MAX_PASSES=1');
    }

    protected function tearDown(): void
    {
        putenv('RENT_SCOUT_DB');
        putenv('SCOUT_MAX_PASSES');
        putenv('RENT_NTFY_TOPIC');
        putenv('NTFY_SERVER');
        putenv('SMTP_TO');
        putenv('SMTP_TRANSPORT');

        if ($this->dbPath !== null) {
            foreach (['', '-wal', '-shm'] as $suffix) {
                @unlink($this->dbPath . $suffix);
            }
        }

        foreach ($this->tempRoots as $root) {
            self::removeTree($root);
        }
        $this->tempRoots = [];
    }

    /**
     * A channel that COUNTS and always succeeds, composed with `console` inside the helpers.
     *
     * These tests assert that a listing was marked notified, which requires a real delivery, and
     * no offline CONFIGURATION can provide one: `console` cannot reach anyone and neither can
     * `email` over `SMTP_TRANSPORT=file` — which is what these four classes used for one review
     * round, making every such assertion pass for the reason that was itself the round-8 P0.
     *
     * It returns a CHANNEL rather than a `Notifier` on purpose. The helper composes it with a
     * `ConsoleChannel` bound to the test's own `$out` stream, so stdout assertions keep working
     * and the shape matches production: one channel to read, one that delivers.
     */
    private function delivering(): DeliveringChannel
    {
        return new DeliveringChannel();
    }

    /**
     * `console` plus the delivering double, or `null` to let `RentScout` build from config.
     *
     * @param resource $out
     */
    private static function compose(mixed $out, ?DeliveringChannel $delivering): ?Notifier
    {
        return $delivering === null ? null : new Notifier([new ConsoleChannel($out), $delivering]);
    }

    /**
     * @param list<string> $argv
     *
     * @return array{code: int, out: string, err: string}
     */
    private function scout(array $argv, ?DeliveringChannel $delivering = null): array
    {
        $out = fopen('php://memory', 'r+');
        $err = fopen('php://memory', 'r+');
        self::assertIsResource($out);
        self::assertIsResource($err);

        // ── scoped to the fixture source ──────────────────────────────────────────────────────
        //
        // These tests exercise the RUN LOOP — seeding, notification, exit codes, doctor's columns —
        // against the real repo root, and until 2026-08-19 that was harmless because every source
        // in the shipped config was disabled. Enabling In'li made every one of them depend on a
        // live landlord's website: the run's exit code became 1 because a network source could not
        // be reached, which says nothing about the loop under test.
        //
        // `--source=fixture_demo` pins them to the frozen payload they were always really about.
        // Which sources ship ENABLED is a different question with its own test — see
        // `ConfigTest::testEveryEnabledSourceCarriesTheEvidenceThatItWasVerified` — and it belongs
        // there, not smeared across twenty CLI assertions that would each fail for the same reason.
        $command = $argv[0] ?? '';
        $names = array_filter($argv, static fn (string $a): bool => str_starts_with($a, '--source='));
        if (in_array($command, ['run', 'doctor'], true) && $names === []) {
            $argv[] = '--source=fixture_demo';
        }

        // A FIXED clock. Without one, `STALE` and the counting window depend on wall time, and a
        // test that passes at 23:59 and fails at 00:01 teaches people to re-run rather than to read.
        $code = (new RentScout(self::ROOT, $out, $err, '2026-08-07T12:00:00+02:00', null, self::compose($out, $delivering)))->run($argv);

        rewind($out);
        rewind($err);

        return [
            'code' => $code,
            'out' => (string) stream_get_contents($out),
            'err' => (string) stream_get_contents($err),
        ];
    }

    /**
     * A throwaway repo root with its own config, so the CLI's config-dependent refusals can be
     * exercised.
     *
     * Added because a sabotage run showed four of them were unreachable from the committed config
     * alone: nothing in `config/rent/sources.json` is a pollable private portal, so the scraping gate
     * never ran; nothing names a bad channel, so that refusal never ran. A guard no test can reach
     * is a guard that will be deleted by someone who sees no failure.
     *
     * @param array<string,mixed> $criteria
     * @param array<string,mixed> $sources
     */
    public function testSourceFlagLimitsTheRunToTheNamedSource(): void
    {
        // In'li is enabled in the shipped config and would be polled without this flag — which is
        // exactly why the flag exists: onboarding a source needs a run against one block, and the
        // alternative was editing committed config to disable the others.
        $r = $this->scout(['doctor', '--source=fixture_demo']);

        self::assertStringContainsString('fixture_demo', $r['out']);
        self::assertStringNotContainsString('inli', $r['out']);
    }

    public function testAnUnknownSourceNameIsAWarningRatherThanASilentEmptyRun(): void
    {
        // The worst outcome for a debugging flag is a typo that reports a clean, fast, empty pass.
        // The developer reads "0 problems" and concludes the source is fine, when it was never run.
        $r = $this->scout(['doctor', '--source=inlii']);

        self::assertStringContainsString('inconnue', $r['out'] . $r['err']);
    }

    private function tempRoot(array $criteria = [], array $sources = []): string
    {
        $root = sys_get_temp_dir() . '/rentwatch-root-' . bin2hex(random_bytes(8));
        mkdir($root . '/config/rent', 0o775, true);
        $this->tempRoots[] = $root;

        file_put_contents($root . '/config/rent/criteria.json', json_encode($criteria + [
            'communes' => ['Sartrouville'],
            'postcode_prefixes' => ['78'],
            'min_rooms' => 4,
            'max_rent_cc' => 1800,
        ], JSON_THROW_ON_ERROR));

        file_put_contents($root . '/config/rent/sources.json', json_encode([
            'sources' => $sources === [] ? ['demo' => [
                'enabled' => false,
                'family' => 'institutional',
                'type' => 'json',
                'mixed_tenure' => true,
                'map' => ['ref' => 'id'],
            ]] : $sources,
        ], JSON_THROW_ON_ERROR));

        return $root;
    }

    /**
     * @param list<string> $argv
     *
     * @return array{code: int, out: string, err: string}
     */
    private function scoutIn(string $root, array $argv, ?DeliveringChannel $delivering = null): array
    {
        $out = fopen('php://memory', 'r+');
        $err = fopen('php://memory', 'r+');
        self::assertIsResource($out);
        self::assertIsResource($err);

        $code = (new RentScout($root, $out, $err, '2026-08-07T12:00:00+02:00', null, self::compose($out, $delivering)))->run($argv);
        rewind($out);
        rewind($err);

        return ['code' => $code, 'out' => (string) stream_get_contents($out), 'err' => (string) stream_get_contents($err)];
    }

    // ---------------------------------------------------------------- doctor

    public function testDoctorReportsAllFourColumnsSpecAsksFor(): void
    {
        // spec §8: "run every source once, report status, TIMING and item counts". Three of the four
        // were implemented for a long time and the fourth was aspirational, which is what Q25 was
        // raised about. All four now appear.
        $r = $this->scout(['doctor']);

        self::assertSame(0, $r['code'], $r['err']);
        self::assertStringContainsString('SOURCE', $r['out']);
        self::assertStringContainsString('ÉTAT', $r['out']);
        self::assertStringContainsString('ITEMS', $r['out']);
        self::assertStringContainsString('DURÉE', $r['out']);
        self::assertMatchesRegularExpression('~fixture_demo\s+ok\s+10\s+\d+ ms~', $r['out']);
    }

    public function testDoctorPrintsTheJournalMode(): void
    {
        // CLAUDE.md § Testing requires it: WAL can be silently refused on a network mount, and a
        // store in rollback-journal mode makes two processes contend instead of share. Both
        // failures are silent.
        $r = $this->scout(['doctor']);

        self::assertMatchesRegularExpression('~journal (wal|delete|memory|truncate|persist|off)~', $r['out']);
    }

    /**
     * Hard rule 2 reaches the one failure no other column can show.
     *
     * A verdict whose snapshot could not be encoded is invisible to BOTH commands that walk the
     * store: `staleVerdicts()` selects undetermined verdicts, so a row that classified `LLI` and
     * failed to encode is not skipped by `reclassify` — it is unreachable by it — and
     * `pendingDigest()` walks digest outcomes only. This line is the entire audit trail, and a
     * review panel deleted the whole report block and watched the suite stay green (1804/1804).
     * A count nobody reads is not a signal; this is the assertion that it is read.
     */
    public function testDoctorReportsVerdictsWhoseEvidenceWasNeverCaptured(): void
    {
        // A root with an ENABLED source, because `doctor` reports the refusal and returns before
        // any store column when nothing is enabled.
        $root = $this->fixtureRoot(enabled: true);

        // The suite pins `RENT_SCOUT_DB` in setUp, and that is the store `doctor` will open.
        $store = Store::open((string) $this->dbPath);
        $listing = new RawListing(
            sourceName: 'cdc_habitat',
            externalId: 'NO-SNAP-1',
            title: 'Appartement T4',
            description: 'Logement intermédiaire',
            commune: 'Sartrouville',
            postcode: '78500',
            rentCc: 1450,
            surfaceM2: 82.0,
            rooms: 4,
        );
        $key = $store->record($listing, 1450, '2026-08-24T12:00:00+02:00')->dedupKey;
        $store->recordVerdict($key, 'LLI', 9000, ['label explicite'], $listing);

        // Exactly the shape `recordVerdict()` leaves behind when `ListingSnapshot::encode()`
        // refuses the payload — the verdict stands, the evidence does not.
        (new \PDO('sqlite:' . (string) $this->dbPath))->exec('UPDATE listings SET evidence_json = NULL');

        $r = $this->scoutIn($root, ['doctor']);

        self::assertStringContainsString('preuves', $r['out']);
        self::assertStringContainsString('1 verdict(s) sans instantané', $r['out']);
    }

    /**
     * A WATCHER'S CLOCK MUST NOT BE FROZEN AT PROCESS START.
     *
     * `$sources` is built ONCE, before the watch loop — deliberately, and the loop's docblock says
     * which three things are re-done per pass and why. An `HtmlSource` handed a resolved timestamp
     * at construction therefore carries the moment the PROCESS started for its entire lifetime,
     * which for a service running for weeks means:
     *
     * - the detail-page backoff computes `now - since` as zero for ever, so a failed page is never
     *   retried until somebody restarts the container, and
     * - every `fetched_at` written to `listing_detail` records process start rather than the fetch.
     *
     * Neither is visible in a `--once` run, which is the only mode the rest of the suite exercises.
     * It is the same class as the heartbeat's in-loop `TypeError`: the path only a long-running
     * watcher takes is the path no test was taking.
     *
     * So the CLOCK is propagated, not a reading of it: `null` in production, which makes the source
     * read real time on each pass, and the fixed value when a test injects one.
     */
    public function testASourceBuiltForAWatchRunCarriesNoFrozenClock(): void
    {
        $clock = new \ReflectionProperty(HtmlSource::class, 'nowIso');

        foreach ([['2026-08-23T10:00:00+02:00', '2026-08-23T10:00:00+02:00'], [null, null]] as [$injected, $expected]) {
            $scout = new RentScout(self::ROOT, fopen('php://memory', 'w+'), fopen('php://memory', 'w+'), $injected);
            $build = new \ReflectionMethod($scout, 'sources');
            $sources = $build->invoke($scout, Store::open((string) $this->dbPath), ['inli'], null);

            self::assertCount(1, $sources, "inli must build, or this asserts nothing");
            self::assertInstanceOf(HtmlSource::class, $sources[0]);
            self::assertSame(
                $expected,
                $clock->getValue($sources[0]),
                'a resolved timestamp baked in at construction freezes the detail backoff for the '
                    . 'entire lifetime of a --watch process, and stamps every fetched_at with the '
                    . 'moment the process started',
            );
        }
    }

    public function testDoctorReportsDetailPagesItHasGivenUpOn(): void
    {
        $store = \Scout\Rent\Store\Store::open((string) $this->dbPath);

        for ($attempt = 0; $attempt < \Scout\Rent\Adapters\DetailHydrator::DETAIL_ATTEMPT_CAP; ++$attempt) {
            $store->recordDetailFailure('fixture_demo', 'ANN-1', 'HTTP 404', '2026-08-23T10:0' . $attempt . ':00+02:00');
        }

        unset($store);
        $r = $this->scout(['doctor', '--source=fixture_demo']);

        self::assertStringContainsString('illisible', $r['out']);
    }

    /**
     * A threshold at or above the IMAP window is REPORTED BY `doctor`, never refused.
     *
     * **This test asserted the opposite until 2026-08-29, and the panel showed why that was wrong.**
     * The refusal's premise was *"the newest message `SEARCH SINCE` can match is by definition at
     * most `IMAP_SINCE_DAYS` old"* — false, because `SEARCH SINCE` filters on INTERNALDATE while the
     * threshold is measured against the message's own `Date:` header, so a message delivered today
     * and stamped weeks ago is inside the window and arbitrarily old.
     *
     * And the refusal LOCKED THE TOOL OUT: at `IMAP_SINCE_DAYS=1` no integer satisfies
     * `1 <= days < 1`, so every store-opening verb exited 2 — including on deployments with no email
     * source at all, which is a regression, since the value was previously just clamped.
     *
     * `doctor` DIAGNOSES, it does not refuse: the same shape this class already applies to an
     * unusable `TZ`.
     */
    public function testAThresholdAtOrAboveTheImapWindowIsReportedNotRefused(): void
    {
        putenv('RENT_FEED_SILENT_DAYS=7');
        putenv('IMAP_SINCE_DAYS=7');

        try {
            $r = $this->scout(['doctor', '--source=fixture_demo']);
        } finally {
            putenv('RENT_FEED_SILENT_DAYS');
            putenv('IMAP_SINCE_DAYS');
        }

        self::assertStringContainsString('RENT_FEED_SILENT_DAYS', $r['out'] . $r['err']);
        self::assertStringContainsString('bande observable', $r['out'] . $r['err']);
    }

    /**
     * THE LOCKOUT ITSELF, pinned — a window of 1 must not make the tool unusable.
     *
     * The counterweight to the test above, and the one that would have caught the regression: it is
     * not enough that `doctor` warns, it must also still RUN. `.env.example` documents
     * `IMAP_SINCE_DAYS=1` as meaningful and `ImapMailbox` clamps to it happily.
     */
    public function testAWindowOfOneDayDoesNotLockTheToolOut(): void
    {
        putenv('IMAP_SINCE_DAYS=1');

        try {
            $r = $this->scout(['doctor', '--source=fixture_demo']);
        } finally {
            putenv('IMAP_SINCE_DAYS');
        }

        self::assertSame(0, $r['code'], 'a one-day window must not refuse every store-opening verb');
    }

    /** Zero disables the one signal that tells a dead alert from a quiet market, so it is refused. */
    public function testAThresholdOfZeroIsRefused(): void
    {
        putenv('RENT_FEED_SILENT_DAYS=0');

        try {
            $r = $this->scout(['doctor']);
        } finally {
            putenv('RENT_FEED_SILENT_DAYS');
        }

        self::assertNotSame(0, $r['code']);
        self::assertStringContainsString('au moins 1 jour', $r['out'] . $r['err']);
    }

    /**
     * THE DEFAULT THRESHOLD REACHES THE STORE WITH NO ENVIRONMENT SET, end to end.
     *
     * **This is the counterweight, and the weak version of it was itself the hole.** The first
     * draft only asserted that an unset variable printed no error — an absence, which stays true
     * however thoroughly the feature is disconnected. Trace the coverage without this test: the two
     * refusal tests above pin only that `feedSilentDays()` is CALLED, and every `Store` test passes
     * the threshold EXPLICITLY. So deleting `$feedSilentDays ??= $this->feedSilentDays;` from
     * `Store::health()` left the whole suite green while making `FEED_SILENT` unreachable in
     * production under default config — dead config for the third time, inside the change built to
     * kill exactly that shape.
     *
     * Nothing is put in the environment on purpose. The run's own fresh row reports no feed date
     * (the fixture source is not a `FeedFreshness`), so the seeded one still decides — which is the
     * `null`-contributes-nothing rule doing its job as a side effect.
     */
    public function testTheDefaultThresholdReachesTheStoreWithNoEnvironmentSet(): void
    {
        putenv('RENT_FEED_SILENT_DAYS');

        // Anchored on the harness's FIXED clock (2026-08-07T12:00+02:00), not on wall time. A first
        // draft used `now`, which is four days AHEAD of that clock — so the run reported
        // "toutes les dates de message sont dans le futur" instead. Wrong branch, right machinery:
        // it proved the future-date guard end to end by accident.
        $store = \Scout\Rent\Store\Store::open((string) $this->dbPath);

        $store->recordRun(
            'fixture_demo',
            3,
            true,
            null,
            '2026-08-07T11:59:00+02:00',
            feedNewestAt: '2026-08-03T09:00:00+02:00',
        );

        unset($store);
        $r = $this->scout(['doctor', '--source=fixture_demo']);

        self::assertStringContainsString('feed_silent', $r['out']);
        self::assertStringContainsString("n'a rien envoyé depuis", $r['out']);
    }

    public function testDoctorPrintsTheSchemaVersionAndTheDigestTimezone(): void
    {
        $r = $this->scout(['doctor']);

        // Symbolic, not the literal `v4` this used to hold: what the test is for is that doctor
        // PRINTS the version, and a literal makes every migration edit a CLI test to restate a
        // number three other tests already assert bare.
        self::assertStringContainsString('schéma v' . Store::SCHEMA_VERSION, $r['out']);

        // THE CADENCE THAT RUNS — all three of Q34's paths, now that the third exists. This line has
        // been rewritten three times and each version was true when written: `digest à 8h` promised
        // a daily emission nothing scheduled; `AUCUN planificateur` named that gap honestly; the
        // floor now exists, so it says so. The rule the three versions share is that doctor prints
        // BEHAVIOUR, never settings — which is why this asserts the running cadence rather than the
        // config key.
        self::assertStringContainsString('sur demande', $r['out'], 'Q34: doctor must state the cadence that actually runs');
        self::assertStringContainsString('plancher quotidien', $r['out'], 'Q34: the daily floor now runs and must be named');
        // THE SCOPE TRAVELS WITH THE PROMISE. The floor runs under `--watch` ONLY, so a cron-driven
        // `--once` deployment has the two event-driven paths and no floor. Naming the floor without
        // naming its scope is the hard-rule-2 shape one narrower: an operator reads a daily rollup
        // they will never receive. Asserted separately from the floor itself, because the ledger
        // proved deleting the clause leaves every other assertion on this line green.
        // THE FRAGMENT, NOT THE WORD. A bare '`--watch`' is satisfied by other lines of this same
        // report — the startup-refusal note and the rollup line both name the flag — so the
        // sabotage deleted the clause from the digest line and this assertion stayed green.
        // Measured: it reported `undetected` with the word asserted and red with the clause.
        self::assertStringContainsString('en `--watch` (Q34)', $r['out'], 'Q34: the floor is --watch only and doctor must say so');
        self::assertStringContainsString('8h', $r['out'], 'the configured hour is shown');
        self::assertStringContainsString(
            'silencieux si rien',
            $r['out'],
            'and the ruled empty-day behaviour is stated, so an operator does not read silence as a fault',
        );
        self::assertStringContainsString(
            '--watch',
            $r['out'],
            'the floor runs in the watch loop ONLY — promising it to a cron-driven --once deployment '
            . 'is the same shape as the promise this line was rewritten to stop making',
        );
        self::assertStringNotContainsString(
            'AUCUN planificateur',
            $r['out'],
            'the gap note must go when the gap does — a stale diagnostic is how doctor stops being believed',
        );

        // THE ZONE THE FLOOR ACTUALLY USES, printed SEPARATELY from the process default, because the
        // two CAN disagree: `bin/scout:44` sets the default from `TZ`, but a `TZ` PHP cannot parse
        // leaves it at UTC with only a Notice — and then the floor's zone and the process zone
        // differ and doctor is the only thing that would say so.
        //
        // `PHP=` is asserted as a LABEL, not as `PHP=UTC`. Under PHPUnit the default is UTC because
        // the bootstrap does not run `bin/scout`; under the real CLI it is Europe/Paris. Pinning the
        // value would pin a harness artefact and go red the day the bootstrap changed.
        self::assertStringContainsString('fuseau  : PHP=', $r['out'], 'the process default is named');
        self::assertStringContainsString(
            'récapitulatif=Europe/Paris',
            $r['out'],
            'Q34 rules the RESOLVED local time is printed; it must come from the same resolution the floor uses',
        );
    }

    /**
     * Make the fixture's listings look newly published — WITHOUT emptying the seen-set.
     *
     * Emptying it is what these tests used to do, and an empty seen-set is now precisely the state
     * `scout run` refuses to notify on (Q36), because it is what a missing volume mount looks like.
     * So the stored rows are RENAMED instead: the seen-set still holds listings, just no longer
     * these ones — which is what "the source published something" actually means.
     *
     * The raw connection leaves `foreign_keys` off, so the price-history rows are orphaned rather
     * than cascaded. That is deliberate and harmless here: the point of these tests is the run
     * loop's output, and a listing seen for the first time has no price history to carry.
     */
    private function republishEverything(): void
    {
        (new \PDO('sqlite:' . (string) $this->dbPath))->exec(
            "UPDATE listings SET dedup_key = 'ancienne:' || dedup_key, external_id = 'ancienne-' || external_id",
        );
    }

    // ---------------------------------------------------------------- seed (Q36)

    public function testRunRefusesOnAFreshlyCreatedDatabase(): void
    {
        // Q8 rules out GitHub Actions BECAUSE no persistent disk means re-notifying everything, then
        // adopts Docker-on-a-VPS, which has the identical failure mode. A typo in `-v` produces a
        // valid, empty, migrated database indistinguishable from a healthy one.
        $r = $this->scout(['run', '--once']);

        self::assertSame(2, $r['code']);
        self::assertStringContainsString('base vide', $r['err']);
        self::assertStringContainsString('--seed', $r['err'], 'the refusal must name the way through');
        self::assertSame('', $r['out'], 'nothing may be notified on a fresh database');
    }

    /**
     * ANY earlier command that merely OPENS the database creates the file, and the guard used to
     * read exactly that fact — "did `open()` create this?" — so a single `scout doctor` disarmed it
     * and the next run notified the entire back catalogue at once. `doctor` is the natural first
     * command to type on a new machine, which made this the likeliest path through the guard rather
     * than an exotic one.
     *
     * The fact the guard reads is now the one Q36 is about: whether anything has ever been recorded.
     */
    public function testAnEarlierDoctorDoesNotDisarmTheEmptyDatabaseGuard(): void
    {
        $doctor = $this->scout(['doctor']);
        self::assertSame(0, $doctor['code'], $doctor['err']);
        self::assertFileExists((string) $this->dbPath, 'doctor is expected to create the database — that is the trap');

        $r = $this->scout(['run', '--once']);

        self::assertSame(2, $r['code'], 'an existing but empty database is still an empty database');
        self::assertStringContainsString('base vide', $r['err']);
        self::assertSame('', $r['out'], 'nothing may be notified on an empty seen-set');
    }

    public function testSeedPopulatesTheSeenSetWithoutNotifying(): void
    {
        $r = $this->scout(['run', '--seed']);

        self::assertSame(0, $r['code'], $r['err']);
        self::assertStringContainsString('seen-set amorcé', $r['out']);
        self::assertStringNotContainsString('[MATCH]', $r['out']);
    }

    public function testTheRunAFTERSeedIsSilentRatherThanFloodingOneRunLater(): void
    {
        // THE BUG THIS TEST EXISTS FOR. Recording the sighting alone was not enough: `notified_at`
        // stayed NULL, so the next run notified all six matches and the flood simply moved one run
        // later — exactly what Q36 exists to prevent. `--seed` means "already seen AND already told
        // about"; only what appears afterwards is news.
        $this->scout(['run', '--seed']);
        $second = $this->scout(['run', '--once']);

        self::assertSame(0, $second['code'], $second['err']);
        self::assertStringNotContainsString('[MATCH]', $second['out']);
        self::assertStringNotContainsString('[DIGEST]', $second['out']);
    }

    public function testTheDigestDoesNotRepeatItselfOnEveryPass(): void
    {
        // Q34: entries are marked emitted only after delivery. Without that the digest re-sends its
        // whole contents every pass, which is the alert fatigue it was designed to avoid — and a
        // digest the developer has learned to skip costs the fail-closed rule its only landing zone.
        $this->scout(['run', '--seed']);
        $this->scout(['run', '--once']);
        $third = $this->scout(['run', '--once']);

        self::assertStringNotContainsString('[DIGEST]', $third['out']);
    }

    // ---------------------------------------------------------------- payload

    public function testAFirstRealRunEmitsTheNotificationPayload(): void
    {
        // Seeded, then the stored rows are renamed so the fixture's listings are new again — which
        // is the closest an offline test gets to "a source published something".
        $root = $this->demoRoot();
        $this->scoutIn($root, ['run', '--seed'], $this->delivering());
        $this->republishEverything();

        $r = $this->scoutIn($root, ['run', '--once'], $this->delivering());

        self::assertSame(0, $r['code'], $r['err']);
        // The high-priority match, with its score, its commune, its rent and its reasons — the
        // shape that has to be readable on a lock screen.
        // THE SOURCE LEADS THE TITLE (developer ruling, 2026-08-29: "I want the source visible in
        // the title/subject so I know what's more priority, by the source"). On a phone the title
        // is what is read first, and which portal a flat came from decides how fast to act on it.
        self::assertStringContainsString('!! [MATCH] fixture_demo · 75/100 — Sartrouville 78500 · T4 88 m² · 1450 € CC', $r['out']);
        self::assertStringContainsString('champ structuré financement = « LLI »', $r['out']);
        self::assertStringContainsString('1450 € CC — 350 € sous le plafond', $r['out']);
        self::assertStringContainsString('https://example.test/annonces/demo-0001', $r['out']);
    }

    /**
     * A5, at the operator's surface. The push gate holds a match back for the daily rollup, and the
     * PASS must say so: a phone that stays quiet while the store fills is otherwise indistinguishable
     * from a quiet market. The pipeline field alone is not the guarantee — the round-7 P2 above
     * (`digestOverflow`) was the remainder line deletable with the whole suite green.
     */
    public function testAPassThatHoldsAMatchBackForTheRollupSaysSo(): void
    {
        $root = $this->fixtureRootWithPushGate(100);
        $this->scoutIn($root, ['run', '--seed'], $this->delivering());
        $this->republishEverything();

        $r = $this->scoutIn($root, ['run', '--once'], $this->delivering());

        self::assertSame(0, $r['code'], $r['err']);
        self::assertStringContainsString('6 correspondance(s) sous le seuil de notification individuelle', $r['out'], 'the pass names what it held back — every one of the six demo matches');
        self::assertStringNotContainsString('[MATCH]', $r['out'], 'held back, not pushed');
        self::assertSame(6, Store::open((string) $this->dbPath)->pendingLowScoreCount(), 'queued for the rollup');
        // AND IT NAMES THE DRAIN THIS RUN MODE HAS. The daily floor lives inside the watch loop, so
        // under `--once` the queue empties when the operator's cron runs the verb and never
        // otherwise; promising a *récapitulatif quotidien* here reads as reassurance while the
        // queue grows for ever (C2 round 6, completeness lens).
        self::assertStringContainsString('scout --domain=rent digest', $r['out'], 'the --once drain is the verb');
        self::assertStringContainsString('ne tourne que sous --watch', $r['out'], 'and the floor\'s scope is stated');
    }

    /** And `doctor` names the gate and the queue — the place the developer looks first (the car side already did). */
    public function testDoctorNamesThePushGateAndTheLowScoreQueue(): void
    {
        $root = $this->fixtureRootWithPushGate(100);
        $this->scoutIn($root, ['run', '--seed'], $this->delivering());
        $this->republishEverything();
        $this->scoutIn($root, ['run', '--once'], $this->delivering());

        $r = $this->scoutIn($root, ['doctor']);

        self::assertSame(0, $r['code'], $r['err']);
        self::assertStringContainsString('rollup  : seuil 100 — 6 correspondance(s) en attente', $r['out'], 'the gate and the queue, on one line');
        self::assertStringContainsString('ne tourne que sous --watch', $r['out'], 'and the floor\'s scope, which a cron deployment does not have');
    }

    public function testTheDigestEntryExplainsItselfRatherThanBeingABareLink(): void
    {
        $root = $this->demoRoot();
        $this->scoutIn($root, ['run', '--seed']);
        $this->republishEverything();

        $r = $this->scoutIn($root, ['run', '--once']);

        self::assertStringContainsString('[DIGEST] À vérifier : 1 annonce(s)', $r['out']);
        self::assertStringContainsString('aucun signal dans l\'annonce', $r['out']);
    }

    public function testNothingWithAnExcludedTenureAppearsInTheOutput(): void
    {
        // §1, at the only surface the developer actually sees. The fixture carries a PLAI listing.
        $root = $this->demoRoot();
        $this->scoutIn($root, ['run', '--seed'], $this->delivering());
        $this->republishEverything();

        $r = $this->scoutIn($root, ['run', '--once'], $this->delivering());

        // COUNTERWEIGHT FIRST. Two assertions of absence pass perfectly on a run that was refused
        // and printed nothing at all — which is exactly what happened when the Q36 guard started
        // reading the seen-set: this test stayed green while its siblings went red, and a §1 test
        // that cannot fail is worse than no test. The match proves the pass actually ran.
        self::assertSame(0, $r['code'], $r['err']);
        self::assertStringContainsString('[MATCH]', $r['out'], 'the pass must have produced output for the absences below to mean anything');
        self::assertStringNotContainsString('demo-0002', $r['out'], 'the PLAI listing must not be surfaced');
        self::assertStringNotContainsString('PLAI', $r['out']);
    }

    public function testVerboseNamesEveryRejectionSoOverFilteringIsVisible(): void
    {
        // A hard disqualifier rejects silently and is logged only (hard rule 8), so `-v` is the ONLY
        // way a mis-scoped filter is ever noticed — nothing arrives either way.
        $root = $this->demoRoot();
        $this->scoutIn($root, ['run', '--seed']);
        $this->republishEverything();

        $r = $this->scoutIn($root, ['run', '--once', '-v']);

        self::assertStringContainsString('écartée fixture_demo:demo-0002 — tenure: PLAI', $r['out']);
        self::assertStringContainsString('écartée fixture_demo:demo-0009 — commune: Nanterre', $r['out']);
    }

    // ---------------------------------------------------------------- dump

    public function testDumpShowsTheRawItemAndTheMappedResult(): void
    {
        // spec §10: what makes onboarding a source take five minutes. It prints the MAPPED listing
        // too, so a field map that silently reads nothing shows as a row of (null) here rather than
        // being discovered weeks later as a quiet source.
        $r = $this->scout(['dump', 'fixture_demo']);

        self::assertSame(0, $r['code'], $r['err']);
        self::assertStringContainsString('annonce brute', $r['out']);
        self::assertStringContainsString('après application du field map', $r['out']);
        self::assertStringContainsString('effectiveRentCc', $r['out']);
        self::assertStringContainsString('tenure           LLI', $r['out']);
    }

    public function testDumpNamesTheKnownSourcesWhenAskedForOneThatDoesNotExist(): void
    {
        $r = $this->scout(['dump', 'nope']);

        self::assertSame(2, $r['code']);
        self::assertStringContainsString('source inconnue', $r['err']);
        self::assertStringContainsString('fixture_demo', $r['err'], 'a refusal should say what IS available');
    }

    public function testDumpWithoutASourceIsAUsageError(): void
    {
        $r = $this->scout(['dump']);

        self::assertSame(2, $r['code']);
        self::assertStringContainsString('usage', $r['err']);
    }

    // ---------------------------------------------------------------- refusals

    public function testAnUnknownCommandIsRefusedWithTheUsage(): void
    {
        $r = $this->scout(['frobnicate']);

        self::assertSame(2, $r['code']);
        self::assertStringContainsString('commande inconnue', $r['err']);
        self::assertStringContainsString('scout --domain=rent doctor', $r['out']);
    }

    /**
     * `--watch` used to refuse outright, because an unpaced loop over many sources from one IP is
     * what a scraper looks like (hard rule 5). It now runs, paced by {@see \Scout\Core\Pacer} to
     * the Q37 ruling — so what remains to assert here is that it is still subject to every guard
     * `--once` is subject to, rather than having quietly become a second, laxer entry point.
     *
     * The cadence itself is asserted in `PacerTest`, and the loop's survival of a failing pass in
     * `WatchLoopTest`. Neither belongs here: reaching them through the CLI would mean a real
     * database, a real config and a fifteen-minute wait.
     */
    public function testWatchIsStillSubjectToTheUnseededDatabaseGuard(): void
    {
        // Q36's guard. A missing volume mount yields a valid, empty, migrated database that is
        // indistinguishable from a healthy one — and with nothing batched, every historic listing
        // would push at once. A watcher would do that on its very first pass and then keep running.
        $r = $this->scout(['run', '--watch']);

        self::assertSame(2, $r['code']);
        self::assertStringContainsString('base vide', $r['err']);
    }

    /**
     * THE SUITE MUST NOT BE ABLE TO HANG HERE, and until 2026-08-19 it could.
     *
     * `--watch` is the one CLI verb whose success case never returns: the guard above is what stops
     * these tests from entering a real fifteen-minute loop. So the moment the guard is what breaks —
     * the exact regression the case above exists for — the test does not fail, it BLOCKS, and the
     * sabotage ledger stalls for hours reporting nothing instead of printing one red line. That was
     * observed, not imagined: a sabotage run sat on this file for eleven minutes on its first case.
     *
     * `SCOUT_MAX_PASSES` bounds the loop, and `setUp()` sets it for every test in this class,
     * so a broken guard now produces a fast wrong answer — which a test can catch.
     */
    public function testTheWatchLoopIsBoundedSoABrokenGuardFailsRatherThanHanging(): void
    {
        $this->scout(['run', '--seed']);

        $r = $this->scout(['run', '--watch']);

        self::assertSame(0, $r['code'], $r['err']);
        self::assertStringContainsString('surveillance active', $r['out']);
        self::assertStringContainsString('SCOUT_MAX_PASSES', $r['out'], 'a bounded watcher must say so — it is not the documented behaviour');
    }

    public function testWatchRefusesToBeCombinedWithSeed(): void
    {
        // `--seed` marks everything as already seen WITHOUT notifying, to bootstrap the seen-set
        // once. Repeated every fifteen minutes it would suppress every notification forever while
        // the process reported itself perfectly healthy — a watcher that watches and never speaks,
        // which is the silent failure hard rule 2 exists to make impossible.
        $r = $this->scout(['run', '--watch', '--seed']);

        self::assertSame(2, $r['code']);
        self::assertStringContainsString('--seed', $r['err']);
        self::assertStringContainsString('--once', $r['err'], 'the refusal must name what to use instead');
    }

    public function testHelpListsEveryDocumentedVerb(): void
    {
        $r = $this->scout(['help']);

        self::assertSame(0, $r['code']);
        foreach (['doctor', 'dump', 'run --once', '--seed', 'run --watch', 'digest', 'reclassify', 'test-notify'] as $verb) {
            self::assertStringContainsString($verb, $r['out'], "spec §10 lists `{$verb}`");
        }
        self::assertStringContainsString('--i-accept-legal-risk', $r['out'], 'hard rule 4 / Q26');
    }

    public function testPollingAPrivatePortalIsSKIPPEDWithoutTheLegalRiskFlag(): void
    {
        // Hard rule 4 / Q26. Direct scraping of a private portal is opt-in and must REFUSE to run
        // without an explicit flag — and the flag is never persisted in config, so starting a
        // scrape stays a deliberate act each time rather than a boolean somebody flipped once.
        $root = $this->tempRoot(sources: ['portal' => [
            'enabled' => true,
            'family' => 'private',
            'type' => 'json',
            'mixed_tenure' => true,
            'url' => 'https://example.test/search',
            'map' => ['ref' => 'id'],
        ]]);

        $r = $this->scoutIn($root, ['doctor']);

        self::assertStringContainsString('--i-accept-legal-risk', $r['err']);
        self::assertStringContainsString('ignorée', $r['err'], 'skipping must be LOUD — a silent skip is a source that quietly does not run');
    }


    /**
     * And the flag ACTUALLY WORKS — the permitting branch, which no test reached.
     *
     * `scrapingAllowed()` read `$_SERVER['argv']` alone, a different source of truth from every
     * other flag in `RentScout`, so the accepting branch was unreachable through the seam every test
     * here uses: all three existing cases assert refusal. Nothing proved the opt-in was honourable,
     * and nothing would have gone red if this literal drifted from the one printed by `help`.
     *
     * The failure direction was closed — over-refusal, never over-permission — which is why round 8
     * rated it P2. But hard rule 4's gate is the one place in this tree where "no test covers the
     * permitting path" is worth saying out loud.
     */
    public function testTheLegalRiskFlagActuallyPermitsTheSource(): void
    {
        $root = $this->tempRoot(sources: ['portal' => [
            'enabled' => true,
            'family' => 'private',
            'type' => 'json',
            'mixed_tenure' => true,
            'url' => 'https://example.test/search',
            'map' => ['ref' => 'id'],
        ]]);

        $r = $this->scoutIn($root, ['doctor', '--i-accept-legal-risk']);

        self::assertStringNotContainsString(
            'ignorée',
            $r['err'],
            'with the flag the source must be RUN, not skipped — otherwise the opt-in is a refusal '
            . 'with extra steps and nobody would know',
        );
    }

    // ── `--source=<name>` is an instruction, not a filter ─────────────────────────────────────────

    public function testAnExplicitlyNamedSourceRunsEvenThoughItIsDisabled(): void
    {
        // `/add-source` step 5 says to run `scout doctor` against a new block and only flip
        // `enabled: true` once it is green. That was IMPOSSIBLE until 2026-08-22: `sources()`
        // dropped every disabled definition before `--source` was consulted, so the documented
        // onboarding order could not be followed and the only way to get a run against one block
        // was to edit committed config — the exact edit the flag exists to avoid.
        //
        // `dump` has always worked this way, so this makes the two verbs agree rather than
        // inventing a rule.
        $root = $this->fixtureRoot(enabled: false);

        $r = $this->scoutIn($root, ['doctor', '--source=fixture_demo']);

        self::assertSame(0, $r['code'], $r['err']);
        self::assertMatchesRegularExpression('~fixture_demo\s+ok\s+10~', $r['out']);
        self::assertStringContainsString(
            'désactivée',
            $r['err'],
            'force-running a disabled source must SAY so — otherwise a --source left behind in a '
                . 'deployment looks exactly like a source somebody enabled on purpose',
        );
    }

    public function testADisabledSourceStaysOutOfAnOrdinaryRun(): void
    {
        // The other half, and the half that matters: naming a source force-runs it, and NOT naming
        // one must still honour `enabled: false`. Without this assertion the change above is
        // indistinguishable from deleting the enabled check outright.
        $root = $this->fixtureRoot(enabled: false);

        $r = $this->scoutIn($root, ['doctor']);

        self::assertDoesNotMatchRegularExpression('~fixture_demo\s+ok~', $r['out']);
    }

    public function testAnExplicitlyNamedSourceWithAnUnverifiedUrlIsRefused(): void
    {
        // Hard rule 1. The loader refuses `enabled: true` next to a REMPLACER placeholder, and that
        // was the whole guard for as long as a disabled source could never run. Force-running one
        // brings the placeholder back within reach, so the refusal moves with it — into
        // `buildSource()`, the single funnel `dump`, `doctor` and `run` all pass through.
        $root = $this->tempRoot(sources: ['demo' => [
            'enabled' => false,
            'family' => 'institutional',
            'type' => 'json',
            'mixed_tenure' => true,
            'url' => 'REMPLACER',
            'map' => ['ref' => 'id'],
        ]]);

        $r = $this->scoutIn($root, ['doctor', '--source=demo']);

        self::assertNotSame(0, $r['code'], 'a named source that cannot run must FAIL, never report a clean empty pass');
        self::assertStringContainsString('REMPLACER', $r['err']);
    }

    /**
     * Same lesson, second instance: a force-run email source must still name its sender.
     *
     * The loader refuses a `from`-less `email_alert` only when it is `enabled: true` — and
     * `--source=<name>` force-runs a disabled one, which is precisely the gap the REMPLACER refusal
     * above was moved into `buildSource()` to close. A drafted block force-run without a sender
     * reads EVERY message in the shared label within the window and ingests other portals' alerts
     * as its own, under its own `default_tenure`.
     *
     * Nothing is exposed today — leboncoin went live 2026-08-26 (no REMPLACER anywhere since) and `email_demo` names a
     * sender — which is exactly the state the REMPLACER guard was in on the day it was found.
     */
    public function testAnExplicitlyNamedEmailSourceStillNeedsItsSender(): void
    {
        $root = $this->tempRoot(sources: ['demo' => [
            'enabled' => false,
            'family' => 'private',
            'type' => 'email_alert',
            'mixed_tenure' => false,
            'default_tenure' => 'LIBRE',
            'params' => ['link_host' => 'example.test/annonce/'],
            'map' => ['ref' => 'url', 'charges_included' => true],
        ]]);

        $r = $this->scoutIn($root, ['doctor', '--source=demo']);

        self::assertNotSame(0, $r['code'], 'a named source that cannot run must FAIL, never report a clean empty pass');
        self::assertStringContainsString('from', $r['err']);
    }

    public function testAnExplicitlyNamedPrivatePortalStillNeedsTheLegalRiskFlag(): void
    {
        // Ordering, asserted because it is load-bearing: the enabled check sits ABOVE the scraping
        // gate, so force-running has to fall THROUGH to that gate rather than past it. A disabled
        // private portal named on the command line is the one path that could otherwise have
        // skipped hard rule 4 entirely.
        $root = $this->tempRoot(sources: ['portal' => [
            'enabled' => false,
            'family' => 'private',
            'type' => 'json',
            'mixed_tenure' => true,
            'url' => 'https://example.test/search',
            'map' => ['ref' => 'id'],
        ]]);

        $r = $this->scoutIn($root, ['doctor', '--source=portal']);

        self::assertStringContainsString('--i-accept-legal-risk', $r['err'], 'hard rule 4 outranks an explicit --source');
    }

    /**
     * A temp root whose only source is the demo fixture, at a chosen `enabled` state.
     *
     * The payload is COPIED into the root rather than referenced back at the repo's own tree: a
     * fixture path is resolved against the root the CLI was given, so a test reaching back would
     * keep passing while the resolution it claims to exercise was broken.
     */
    private function fixtureRoot(bool $enabled): string
    {
        // The SHIPPED block, with only `enabled` overridden — never a hand-written copy of it.
        //
        // It was a copy for one afternoon, and the copy silently dropped `elevator`, `floor`,
        // `description` and `tenure_field` from the field map. Nothing errored: the listings still
        // parsed, the run still passed, and the demo flat simply scored 55 instead of 75 because it
        // had lost its lift. A test fixture that is a partial duplicate of production config does
        // not fail, it drifts — and it drifts into asserting the behaviour of a mapping nobody
        // ships.
        $shipped = json_decode(
            (string) file_get_contents(self::ROOT . '/config/rent/sources.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($shipped);
        $block = $shipped['sources']['fixture_demo'] ?? null;
        self::assertIsArray($block, 'the committed config must carry a fixture_demo block');
        $block['enabled'] = $enabled;

        $root = $this->tempRoot(sources: ['fixture_demo' => $block]);

        mkdir($root . '/tests/fixtures/rent/fixture_demo', 0o775, true);
        copy(
            self::ROOT . '/tests/fixtures/rent/fixture_demo/search.json',
            $root . '/tests/fixtures/rent/fixture_demo/search.json',
        );

        // FROZEN criteria, not the shipped file. `tests/fixtures/rent/fixture_demo/search.json` was
        // authored against a 1800 € ceiling and a 78/95 region: demo-0001 at 1450 € is the match,
        // demo-0009 (Nanterre, 92000) exists to be rejected on location. Read the shipped file
        // instead and one preference change — the region widened to all of Île-de-France, the
        // ceiling dropped to 1200 € on 2026-08-22 — silently turns every one of those into a
        // different listing, and four tests about the NOTIFICATION PAYLOAD start failing for a
        // reason that has nothing to do with payloads.
        copy(
            self::ROOT . '/tests/fixtures/rent/criteria/pipeline.json',
            $root . '/config/rent/criteria.json',
        );

        return $root;
    }

    /**
     * The demo root, built once per test so `run --seed` and the `run --once` after it share a
     * config. Memoised rather than rebuilt, because a second root would mean a second seen-set and
     * the second command would find nothing.
     */
    private function demoRoot(): string
    {
        return $this->demoRootPath ??= $this->fixtureRoot(enabled: true);
    }

    public function testAnUnknownNotificationChannelIsRefusedRatherThanDropped(): void
    {
        // A typo in `notify.channels` that silently yielded nothing would be a channel the developer
        // believes is enabled and is not — which is the "computed and never sent" failure hard rule
        // 2 calls worse than no alert at all.
        $root = $this->tempRoot(['notify' => ['channels' => ['ntfyy']]]);

        $r = $this->scoutIn($root, ['test-notify']);

        self::assertSame(2, $r['code']);
        self::assertStringContainsString('canal inconnu', $r['err']);
        self::assertStringContainsString('ntfyy', $r['err']);
    }

    public function testAMalformedCriteriaFileStopsEverything(): void
    {
        // Q28: a validation error in criteria.json IS a startup refusal, because the criteria decide
        // what is filtered and a wrong filter is invisible — the user sees plausible results forever.
        $root = $this->tempRoot();
        file_put_contents($root . '/config/rent/criteria.json', '{"communes": [');

        $r = $this->scoutIn($root, ['doctor']);

        self::assertSame(2, $r['code']);
        self::assertStringContainsString('configuration', $r['err']);
    }

    public function testDoctorRecordsAMeasuredDurationForEverySource(): void
    {
        // Q25. The printed column can read "0 ms" on a fast fixture, so the assertion is on what was
        // STORED: null means nobody measured, and that is the state the column was added to end.
        $this->scout(['doctor']);

        $duration = (new \PDO('sqlite:' . (string) $this->dbPath))
            ->query("SELECT duration_ms FROM source_runs WHERE source = 'fixture_demo'")
            ->fetchColumn();

        self::assertNotFalse($duration);
        self::assertNotNull($duration, 'doctor must measure, not merely print a column');
    }

    public function testDoctorPassesTheClockSoStaleCanFire(): void
    {
        // Without `$nowIso`, `Store::health()` has no clock and ONE verdict becomes underivable:
        // STALE never fires at all. That is the clock's only job, and CLAUDE.md § Testing makes
        // passing it a requirement of `scout doctor` in as many words.
        //
        // AND THIS TEST DOES NOT PROVE DOCTOR PASSES IT — stated plainly, because an earlier version
        // of this comment claimed it did. A sabotage run showed the claim was false: dropping the
        // argument in `RentScout` leaves the suite green, and it cannot be otherwise. `doctor` records
        // its own successful run immediately before asking for health, so the clock and the newest
        // stamp always agree and no verdict can differ. The argument is defensive there rather than
        // load-bearing; it is load-bearing in the run loop and in any read that is not preceded by a
        // run, which is what this asserts.
        $root = $this->tempRoot(sources: ['stale_demo' => [
            'enabled' => true,
            'family' => 'institutional',
            'type' => 'fixture',
            'mixed_tenure' => true,
            'fixture' => 'tests/fixtures/rent/fixture_demo/search.json',
            'items_path' => 'results.items',
            'map' => ['ref' => 'id'],
        ]]);

        // A run log whose newest entry is two months before the fixed clock. `doctor` records its
        // own run too, so the backdated rows must be inserted with a stamp the health window still
        // sees as the latest — which is what makes the clock the deciding input.
        $pdo = new \PDO('sqlite:' . (string) $this->dbPath);
        \Scout\Rent\Store\Store::open((string) $this->dbPath);
        $pdo = new \PDO('sqlite:' . (string) $this->dbPath);
        $pdo->exec("INSERT INTO source_runs (source, item_count, ok, error, at, at_epoch, duration_ms)
                    VALUES ('stale_demo', 10, 1, NULL, '2026-06-01T12:00:00+02:00', 1780308000, 5)");

        $store = \Scout\Rent\Store\Store::open((string) $this->dbPath);

        self::assertSame(
            \Scout\Core\SourceStatus::STALE,
            $store->health('stale_demo', '2026-08-07T12:00:00+02:00')->status,
            'with a clock, a source last seen in June is STALE in August',
        );
        self::assertNotSame(
            \Scout\Core\SourceStatus::STALE,
            $store->health('stale_demo')->status,
            'and WITHOUT a clock the same rows cannot produce that verdict — which is exactly why '
            . 'doctor must pass one, and why asserting on the store alone proves nothing about doctor',
        );

        unset($root);
    }

    /**
     * The console-only run is not refused, so the warning is the ONLY thing standing between a
     * misconfigured deployment and a watcher that announces to a log for ever while marking
     * nothing notified. An unpinned operator line is what lens C found three of in round 7.
     */
    public function testAConsoleOnlyRunWarnsThatNothingWillBeMarkedNotified(): void
    {
        $root = $this->fixtureRootWithChannels(['console']);
        $this->scoutIn($root, ['run', '--seed']);

        $r = $this->scoutIn($root, ['run', '--once']);

        self::assertStringContainsString('aucun canal n\'atteint de destinataire', $r['err']);
        self::assertStringContainsString('RIEN ne sera marqué notifié', $r['err']);
    }

    /**
     * `--seed` is exempt, and that exemption is a decision rather than an oversight.
     *
     * Seeding deliberately notifies nothing, so having no delivering channel is not a problem for
     * that run — warning there would be the noise that teaches an operator to skip the line. Round
     * 8 found the `!$seed` clause unpinned: removing it left the whole suite green.
     */
    public function testSeedingDoesNotWarnAboutDeliveryBecauseItNotifiesNothing(): void
    {
        $root = $this->fixtureRootWithChannels(['console']);

        $r = $this->scoutIn($root, ['run', '--seed']);

        self::assertStringNotContainsString('atteint de destinataire', $r['err']);
    }

    /**
     * `digest` and `reclassify` have the identical property and printed a retry promise that is
     * unconditionally FALSE without a delivering channel — "elles seront réessayées", for ever,
     * while the §1 digest backlog never drains. The warning lived only on `run` for one round.
     */
    public function testDigestAndReclassifyWarnWhenNothingCanDeliver(): void
    {
        // Both verbs need something to act on, or they return before a notifier is even built —
        // which is correct (no work, no warning) and would make this test pass vacuously.
        $root = $this->fixtureRootWithChannels(['console']);
        $this->scoutIn($root, ['run', '--seed']);
        $this->republishEverything();
        $this->scoutIn($root, ['run', '--once']);

        foreach (['digest', 'reclassify'] as $verb) {
            $r = $this->scoutIn($root, [$verb]);

            self::assertStringContainsString(
                'aucun canal n\'atteint de destinataire',
                $r['err'],
                $verb . ' must say so too — it marks nothing and promises a retry that cannot work',
            );
        }
    }

    public function testARunWithARemoteChannelDoesNotWarnAboutIt(): void
    {
        // The counterweight: a warning that fires always is furniture, and an operator stops
        // reading it. This is the direction that makes the assertion above mean something.
        //
        // Built from CONFIG, not injected — the warning asks `hasRemoteChannel()`, which is about
        // what the configuration produced. `ntfy` is the channel that counts; the topic is enough
        // for `check()` to pass, and the send failing is irrelevant to whether the warning fires.
        putenv('RENT_NTFY_TOPIC=rent-watch-test');
        putenv('NTFY_SERVER=http://127.0.0.1:1');
        $root = $this->fixtureRootWithChannels(['console', 'ntfy']);
        $this->scoutIn($root, ['run', '--seed']);

        $r = $this->scoutIn($root, ['run', '--once']);

        self::assertStringNotContainsString('aucun canal distant', $r['err']);
    }

    /**
     * The pipeline's digest cap must NAME its remainder to the operator.
     *
     * Both sibling caps assert their line by string — `scout digest`'s *"N autre(s) en attente"* and
     * `reclassify`'s *"N promotion(s) au-delà du lot"*. This one asserted only the `RunResult`
     * field, so round 7 deleted the whole `if` block an operator actually reads and the full suite
     * stayed green. It is the highest-traffic of the three: it runs unattended every fifteen
     * minutes, and it was the only one whose remainder could vanish silently.
     */
    public function testThePipelineNamesTheDigestRemainderToTheOperator(): void
    {
        $over = Store::DIGEST_BATCH + 7;
        $root = $this->doubtfulRoot($over);

        // Seeded first (Q36 refuses to notify on an empty seen-set), then everything is new again.
        $this->scoutIn($root, ['run', '--seed', '--source=bulk']);
        $this->republishEverything();

        $r = $this->scoutIn($root, ['run', '--once', '--source=bulk']);

        self::assertStringContainsString('à vérifier non émise(s)', $r['err']);
        self::assertStringContainsString((string) ($over - Store::DIGEST_BATCH), $r['err']);
        self::assertStringContainsString('scout --domain=rent digest', $r['err'], 'and it must say how to drain it — with the domain, or the command it names is refused');
    }

    /** A temp root carrying one fixture source of `$count` listings with NO tenure signal at all. */
    private function doubtfulRoot(int $count): string
    {
        $items = [];
        for ($i = 0; $i < $count; ++$i) {
            $items[] = [
                'id' => 'bulk-' . $i,
                'title' => 'T4 Sartrouville',
                'url' => 'https://example.test/bulk-' . $i,
                'city' => 'Sartrouville',
                'zipCode' => '78500',
                // Distinct rent and surface per item so Dedup cannot cluster them into one entry.
                'rent' => ['total' => 1400 + $i * 7],
                'surface' => 80.0 + $i,
                'rooms' => 4,
                'description' => 'Appartement lumineux, cuisine equipee.',
            ];
        }

        $root = $this->tempRoot(sources: ['bulk' => [
            'enabled' => true,
            'family' => 'institutional',
            'type' => 'fixture',
            'mixed_tenure' => true,
            'fixture' => 'payload.json',
            'items_path' => 'results.items',
            'map' => [
                'ref' => 'id',
                'title' => 'title',
                'url' => 'url',
                'commune' => 'city',
                'cp' => 'zipCode',
                'rent' => 'rent.total',
                'charges_included' => true,
                'surface' => 'surface',
                'rooms' => 'rooms',
                'description' => 'description',
            ],
        ]]);

        file_put_contents(
            $root . '/payload.json',
            json_encode(['results' => ['items' => $items]], JSON_THROW_ON_ERROR),
        );

        return $root;
    }

    /**
     * `replay` is an alias of `dump`, and `help` has to say so.
     *
     * Round 7 found a THREE-WAY disagreement: README and the spec both said
     * `scout replay <fixture>`, the code is `'replay' => $this->dump($flags)` — which takes a
     * source NAME — and `scout help` did not mention the verb at all, so the tool's own help denied
     * it existed. A documented verb absent from help is how a three-way drift survives.
     */
    public function testReplayIsAnAliasOfDumpAndSaysSoInHelp(): void
    {
        $help = $this->scout(['help']);

        self::assertStringContainsString('scout --domain=rent replay', $help['out'], 'a documented verb must be listed');

        $r = $this->scout(['replay', 'fixture_demo']);
        self::assertSame(0, $r['code'], $r['err']);

        // And the shape the docs used to promise, so the disagreement cannot come back silently.
        $byPath = $this->scout(['replay', 'tests/fixtures/rent/fixture_demo/search.json']);
        self::assertSame(2, $byPath['code'], 'a fixture PATH is not what this verb takes');
        self::assertStringContainsString('source inconnue', $byPath['err']);
    }

    /**
     * `scout replay <source> --file=<payload>` — the half spec §10 asked for and the alias never
     * delivered: a frozen payload run through a NETWORK source's own field map, with no request
     * made. `dump` against In'li polls In'li; this reads the fixture as though In'li had answered
     * with it, which is what onboarding a source from a captured page needs.
     *
     * Three guarantees, each of which the obvious implementation gets wrong:
     *  - no network at all — the suite runs `SCOUT_OFFLINE=1`, so a stray request throws;
     *  - the field map that runs is the SOURCE'S (`inli` maps `ref` from the card link, so the
     *    first listing's externalId is the fixture's first card, not the demo source's);
     *  - NOTHING is written to the real store. `dump` hydrates through the detail cache, and a
     *    replay whose detail pages cannot be fetched would otherwise record a failure row per
     *    listing in the production database for pages nobody fetched.
     */
    public function testReplayWithAFileRunsAFrozenPayloadThroughANetworkSourcesFieldMap(): void
    {
        $started = microtime(true);
        $r = $this->scout(['replay', 'inli', '--file=tests/fixtures/rent/inli/search.html']);
        $elapsed = microtime(true) - $started;

        self::assertSame(0, $r['code'], $r['err']);
        // In'li's block carries `rate_limit_ms: 2000` and a `detail_map` with a budget of 20, so a
        // replay that kept the throttle sleeps ≥ 40 s answering a file (43 s measured). There is no
        // host to protect; the replay must run the source UNTHROTTLED. Twenty seconds is a 2x
        // margin under the sabotaged floor, and an order of magnitude over the real cost.
        self::assertLessThan(20.0, $elapsed, 'the replay kept the adapter\'s rate limit and slept through 20 simulated fetches');
        self::assertStringContainsString('après application du field map', $r['out']);
        self::assertMatchesRegularExpression('~externalId\s+\S+~', $r['out'], 'the source\'s own ref mapping ran');
        self::assertStringContainsString('inli', $r['out']);

        self::assertSame(
            0,
            Store::open($this->dbPath)->detailFailureCount('inli'),
            'a replay must leave no trace in the real store — its detail pages were never fetched',
        );
    }

    /**
     * `doctor` gives a per-source `feed_silent_days` the same advice the global one gets: at or
     * past `IMAP_SINCE_DAYS` the count collapses to zero (broken) before the threshold is ever
     * reached, so the verdict cannot fire. A diagnostic, never a refusal — same ruling as the
     * global one on 2026-08-29. Counterweight in the same root: a threshold inside the window says
     * nothing.
     */
    public function testDoctorWarnsWhenASourcesOwnThresholdReachesTheImapWindow(): void
    {
        $block = static fn (int $days): array => [
            'enabled' => true,
            'family' => 'private',
            'type' => 'email_alert',
            'mixed_tenure' => true,
            'feed_silent_days' => $days,
            'params' => ['from' => 'alerts@portal.test', 'link_host' => 'portal.test'],
            'map' => ['ref' => 'url', 'charges_included' => true],
        ];
        $root = $this->tempRoot([], ['tooslow' => $block(7), 'fine' => $block(2)]);

        $r = $this->scoutIn($root, ['doctor']);

        self::assertStringContainsString('feed_silent_days de tooslow (7) >= IMAP_SINCE_DAYS (7)', $r['err']);
        self::assertStringNotContainsString('feed_silent_days de fine', $r['err'], 'inside the window: no advice');
    }

    /** An email source's payloads are `.eml` files, and that seam already exists: say which. */
    public function testReplayWithAFileOnAnEmailSourceNamesTheMailboxSeam(): void
    {
        $r = $this->scout(['replay', 'seloger', '--file=tests/fixtures/rent/seloger/2026-08-25-001-alert.eml']);

        self::assertSame(2, $r['code']);
        // Both words, because "ni MAILBOX_DIR ni IMAP_HOST" is what an unconfigured mailbox already
        // says — a refusal that does not name the flag it is refusing would pass on that alone.
        self::assertStringContainsString('--file', $r['err']);
        self::assertStringContainsString('MAILBOX_DIR', $r['err']);
    }

    /** @param list<string> $channels */
    /** The demo root with `notify.push_min_score` set — the frozen criteria, one key added. */
    private function fixtureRootWithPushGate(int $pushMinScore): string
    {
        $root = $this->fixtureRoot(enabled: true);
        $criteria = json_decode(
            (string) file_get_contents($root . '/config/rent/criteria.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($criteria);
        /** @var array<string,mixed> $notify */
        $notify = $criteria['notify'] ?? [];
        $notify['push_min_score'] = $pushMinScore;
        $criteria['notify'] = $notify;
        file_put_contents(
            $root . '/config/rent/criteria.json',
            json_encode($criteria, JSON_THROW_ON_ERROR),
        );

        return $root;
    }

    private function fixtureRootWithChannels(array $channels): string
    {
        $root = $this->fixtureRoot(enabled: true);
        $criteria = json_decode(
            (string) file_get_contents($root . '/config/rent/criteria.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($criteria);
        /** @var array<string,mixed> $notify */
        $notify = $criteria['notify'] ?? [];
        $notify['channels'] = $channels;
        $criteria['notify'] = $notify;
        file_put_contents(
            $root . '/config/rent/criteria.json',
            json_encode($criteria, JSON_THROW_ON_ERROR),
        );

        return $root;
    }

    /**
     * `test-notify` is the documented proof that a DEPLOYED image can reach the user, so its exit
     * code has to mean that and nothing weaker.
     *
     * This test used to run against the repo root and assert exit 0 with `console` as the only
     * channel — the round-7 P0 stated as a guarantee: one print to a container log satisfying the
     * one command whose entire job is proving the channel works. It is three tests now, and the
     * root is a TEMP one: reading the repo's own config made the outcome depend on
     * `config/rent/criteria.local.json`, which is gitignored, so it passed locally and would have gone
     * red in CI.
     *
     * The docblock sat above an UNRELATED test for a round, because it was left where the split
     * happened rather than moved with the tests it describes. Found by a review lens.
     */
    public function testTestNotifyFailsWhenConsoleIsTheOnlyChannel(): void
    {
        $root = $this->tempRoot(['notify' => ['channels' => ['console']]]);

        $r = $this->scoutIn($root, ['test-notify']);

        self::assertSame(1, $r['code'], 'a container log is not proof the channel works');
        self::assertStringContainsString('test de notification', $r['out'], 'it still prints');
    }

    /**
     * THE ROUND-8 P0, at the surface that matters: `email` over `SMTP_TRANSPORT=file` is not a
     * channel either.
     *
     * It writes an `.eml` and sends nothing — `.env.example` says so — and under Q8's Docker
     * deployment the outbox is image-local, destroyed by the next rebuild. It nonetheless voted as
     * a delivery for a whole review round, because the previous fix filtered on the literal name
     * `console`. `test-notify` returned 0 for a message that went to a file, which is the exact
     * opposite of what that command exists to prove.
     */
    public function testTestNotifyFailsWhenTheOnlyOtherChannelWritesToAFile(): void
    {
        putenv('SMTP_TO=watcher@example.test');
        putenv('SMTP_TRANSPORT=file');
        $root = $this->tempRoot(['notify' => ['channels' => ['console', 'email']]]);

        $r = $this->scoutIn($root, ['test-notify']);

        self::assertSame(1, $r['code'], 'a file on this machine is not proof the channel works');
        self::assertStringContainsString('test de notification', $r['out'], 'it still prints');
    }

    public function testTestNotifySucceedsThroughARemoteChannel(): void
    {
        // A channel that genuinely reaches a recipient, injected — see delivering().
        $root = $this->tempRoot();

        $r = $this->scoutIn($root, ['test-notify'], $this->delivering());

        self::assertSame(0, $r['code'], $r['err']);
        self::assertStringContainsString('test de notification', $r['out']);
    }

    public function testReclassifyRejudgesRowsWithAnUndeterminedVerdict(): void
    {
        // This test used to assert the STUB's own disclaimer — that the classifier needed the ad's
        // text and `listings` did not keep it. Schema v7 keeps it, the verb is real, and the
        // disclaimer would now be the lie. What replaces it is the behaviour it disclaimed.
        $this->scout(['run', '--seed']);
        $r = $this->scout(['reclassify']);

        self::assertSame(0, $r['code'], $r['err']);
        self::assertStringContainsString('verdict indéterminé', $r['out']);
        self::assertStringContainsString('re-jugée(s)', $r['out']);
        self::assertStringNotContainsString(
            'attend le stockage',
            $r['out'],
            'the stub said it was waiting on storage that now exists',
        );
    }

    /**
     * Delete a temp root and everything under it.
     *
     * `@rmdir($root)` was the whole cleanup, and it cannot remove a non-empty tree — it just fails
     * silently behind the `@`. Once these tests started building a `var/outbox`, every run left
     * the root behind: round 8 measured **10334 leftover roots and 2752 `.eml` files** accumulated
     * on one machine. Unbounded growth in CI and on the dev box, plus one more suppressed failure
     * reporting success. Ported from ScoutReclassifyTest, which had it already.
     */
    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            /** @var \SplFileInfo $entry */
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($path);
    }
}
