<?php

declare(strict_types=1);

namespace Scout\Tests\Rent\Cli;

use PHPUnit\Framework\TestCase;
use Scout\Rent\Cli\RentScout;
use Scout\Core\Notify\ConsoleChannel;
use Scout\Core\Notify\Notifier;
use Scout\Rent\Core\RawListing;
use Scout\Rent\Core\Tenure;
use Scout\Rent\Store\Store;
use Scout\Tests\Support\DeliveringChannel;

/**
 * Q34's DAILY FLOOR — the third emission path, and the one that was ruled and never built.
 *
 * The other two are event-driven: the pipeline emits at the end of a pass that produced NEW entries,
 * and `scout digest` emits when a human types it. Neither reaches a backlog that failed to send, or
 * one left over by the batch cap, on a day that produced nothing new. That backlog sits in §1's only
 * landing zone — everything the classifier could not resolve confidently — so a bin nobody drains is
 * the fail-closed rule quietly costing the user listings.
 *
 * **Two tests here carry guarantees nothing else can.**
 *
 * {@see testOneWindowEmitsExactlyOneBatchAndLeavesTheRemainder} is what the marker is FOR. Without
 * it the floor re-fires every fifteen-minute pass until the backlog drains — a per-pass drain, not a
 * daily floor.
 *
 * {@see testTheInLoopFloorRunsAndIsNotJustTheStartupOneAgain} exists because of a documented trap
 * this class inherits wholesale. The startup emission writes the marker at `NOW`, and the suite runs
 * on a FIXED clock, so every later `isDue(NOW, NOW)` is false — which means the in-loop call site is
 * never executed by any ordinary test. That is exactly how `beat()`'s in-loop call site went
 * unexercised: the argument it needed was missing from the closure's `use` list, and the first
 * genuinely due beat would have thrown a `TypeError` and killed the watcher a day into an unattended
 * run. The way in is the one already documented for `beat()` — make the marker file UNWRITABLE (a
 * directory where the file goes), so `@file_put_contents` fails silently, `is_file()` reports no
 * marker, and every check is due.
 */
final class RentScoutDigestFloorTest extends TestCase
{
    /** 21:00 Paris — comfortably after the shipped 08:00 window, so "today" is unambiguous. */
    private const NOW = '2026-08-23T21:00:00+02:00';

    /** @var list<string> */
    private array $roots = [];

    protected function setUp(): void
    {
        // `--watch` is the one verb whose success case never returns. Without this a test that
        // expects a refusal and is wrong does not fail — it blocks, and takes the suite and the
        // sabotage ledger with it.
        putenv('SCOUT_MAX_PASSES=1');
        putenv('TZ=Europe/Paris');
    }

    protected function tearDown(): void
    {
        putenv('SCOUT_MAX_PASSES');
        putenv('TZ');
        putenv('RENT_SCOUT_DB');

        foreach ($this->roots as $root) {
            $this->rmrf($root);
        }
        $this->roots = [];
    }

    // ── the floor fires ──────────────────────────────────────────────────────────────────────────

    public function testAPendingBacklogIsDrainedOnStartup(): void
    {
        // The case the floor exists for: entries sitting in the bin from a previous run, on a day
        // that produces nothing new. Before this, nothing drained them but a human typing the
        // command.
        $root = $this->tempRoot();
        $key = $this->seedDigestRow($root, 'ANCIEN-1');

        $r = $this->watch($root);

        self::assertSame(0, $r['code'], $r['err']);
        self::assertStringContainsString('récapitulatif quotidien', $r['out'], 'Q34: the floor must drain a waiting backlog');
        self::assertSame([], $this->pending($root), 'a delivered entry is marked, so the bin empties');
        self::assertNotNull($this->notifiedAt($root, $key), 'and the row records that it was announced');
    }

    public function testTheMarkerIsWrittenOnlyAfterTheChannelConfirms(): void
    {
        $root = $this->tempRoot();
        $this->seedDigestRow($root, 'ANCIEN-1');

        $this->watch($root);

        self::assertFileExists($root . '/state/rent-digest.txt', 'a delivered emission records its window as served');
    }

    /**
     * A REFUSED FLOOR EMISSION MARKS NOTHING AND WRITES NO MARKER — and NOTHING covered it.
     *
     * Mutating this guard alone left the whole suite green (C2 round 2, P2): the two ledger cases
     * labelled for it share an unscoped `sed` that also disables `pushRetries()` and the reclassify
     * promotion, so both passed on those and neither reached the floor. The floor is the DEPLOYED
     * drain, and marking before the channel confirms consumes a backlog whose own comment says
     * *"these entries have no other route to the developer"*. The car floor's twin was covered; the
     * rent floor's was not.
     */
    public function testARefusedFloorEmissionMarksNothingAndWritesNoMarker(): void
    {
        $root = $this->tempRoot();
        $key = $this->seedDigestRow($root, 'REFUS-1');

        $this->watch($root, 1, [\Scout\Core\Notify\NotificationKind::DIGEST]);

        self::assertFileDoesNotExist(
            $root . '/state/rent-digest.txt',
            'a refused emission records no window as served, so the next pass retries',
        );
        self::assertFalse(
            Store::open($root . '/state/rent-watch.sqlite3')->wasNotified($key),
            'and nothing is marked — the backlog has no other route to the developer',
        );
    }

    public function testASnapshotLessRowIsAnnouncedAndTheFaultIsVOICED(): void
    {
        // The row is ANNOUNCED, never skipped — it has a verdict, an outcome and a title, and
        // announcing a stored DIGEST from stored columns cannot promote anything into a match.
        //
        // But the COUNT must be said out loud, and the floor did not say it until a review round
        // asked. It does not mean "an old row": `pendingDigest()` filters on `outcome`, itself a v7
        // column that is not backfilled, so a pre-v7 row has `outcome = NULL` and is never returned
        // here at all. The only reachable cause is a listing whose own payload could not be
        // JSON-encoded — a LIVE SOURCE FAULT. A floor that drained those rows silently would lose
        // the one signal that says a source is emitting payloads nothing can encode.
        $root = $this->tempRoot();
        $key = $this->seedDigestRow($root, 'SANS-INSTANTANE');

        $pdo = new \PDO('sqlite:' . $root . '/state/rent-watch.sqlite3');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->prepare('UPDATE listings SET evidence_json = NULL WHERE dedup_key = :key')
            ->execute(['key' => $key]);

        $r = $this->watch($root);

        self::assertStringContainsString('récapitulatif quotidien', $r['out'], 'the row is announced, not skipped');
        self::assertStringContainsString(
            'sans instantané',
            $r['out'] . $r['err'],
            'and the source fault it indicates is named — draining it silently loses that signal',
        );
    }

    // ── the floor stays silent ───────────────────────────────────────────────────────────────────

    public function testAnEmptyBinEmitsNothingAndLeavesTheWindowOpen(): void
    {
        // THE RULED EMPTY-DAY BEHAVIOUR (developer ruling, 2026-08-26). `Core/Heartbeat` already
        // carries the daily liveness signal, so an unconditional emission would be a second
        // scheduled push saying nothing the beat did not — and a channel that speaks daily with
        // nothing to say is one its reader learns to swipe away.
        $root = $this->tempRoot();
        $this->seedSeenButNotDigested($root);

        $r = $this->watch($root);

        self::assertSame(0, $r['code'], $r['err']);
        self::assertStringNotContainsString('récapitulatif quotidien', $r['out'], 'nothing pending, nothing said');

        // AND NO MARKER, which is the half that is easy to get wrong. Leaving the window open is
        // what makes Q34's "an unsent digest is retried next run" work: an entry that appears — or
        // whose send failed — later the same day is picked up on the next pass rather than waiting
        // for tomorrow.
        self::assertFileDoesNotExist(
            $root . '/state/rent-digest.txt',
            'a silent floor must not consume the day\'s window',
        );
    }

    public function testAWindowAlreadyServedTodayDoesNotFireAgain(): void
    {
        $root = $this->tempRoot();
        $this->seedDigestRow($root, 'ANCIEN-1');

        // Emitted at 08:05 this morning; NOW is 21:00 the same day, so the window has not come
        // round again even though the bin is not empty.
        file_put_contents($root . '/state/rent-digest.txt', "2026-08-23T08:05:00+02:00\n");

        $r = $this->watch($root);

        self::assertStringNotContainsString('récapitulatif quotidien', $r['out'], 'one floor emission per window');
        self::assertCount(1, $this->pending($root), 'and the entry is still waiting for tomorrow');
    }

    // ── the marker's one job ─────────────────────────────────────────────────────────────────────

    public function testOneWindowEmitsExactlyOneBatchAndLeavesTheRemainder(): void
    {
        // WHAT THE MARKER IS FOR, and the test that goes red if its write is deleted. The bin is
        // capped per emission, so a backlog larger than one batch survives a successful send —
        // without the marker the floor would fire again on the very next pass, and again, draining
        // the bin every fifteen minutes rather than once a day. That is not a floor, it is a second
        // pipeline.
        $root = $this->tempRoot();
        $over = Store::DIGEST_BATCH + 1;

        for ($i = 0; $i < $over; $i++) {
            $this->seedDigestRow($root, 'BATCH-' . $i);
        }

        // ONE pass, and that is not a weaker test than several — it is the only affordable one.
        // `SCOUT_MAX_PASSES` bounds how many passes run; it does NOT shorten the Q37 wait
        // BETWEEN them, so `passes: 3` sleeps two real fifteen-minute cadences and the suite hangs.
        // (Observed while writing this file. Every other `--watch` test in the tree uses one pass,
        // so nobody had met it.) One pass still exercises BOTH call sites — the startup check before
        // it and the in-loop check after it — which is exactly the pair this test is about: the
        // first must emit and the second must not.
        $r = $this->watch($root, passes: 1);

        self::assertSame(
            1,
            substr_count($r['out'], 'récapitulatif quotidien'),
            'two due-checks in one run, one window: the marker must suppress the second',
        );
        self::assertCount(
            $over - Store::DIGEST_BATCH,
            $this->pending($root),
            'the remainder waits for the next window rather than draining every pass',
        );
        self::assertStringContainsString(
            'autre(s) en attente',
            $r['out'],
            'and the remainder is NAMED — a capped batch that stays quiet reads as the whole bin',
        );
    }

    // ── the in-loop call site, which a fixed clock hides ─────────────────────────────────────────

    public function testTheInLoopFloorRunsAndIsNotJustTheStartupOneAgain(): void
    {
        // THE DOCUMENTED TRAP, INHERITED. Under a fixed clock the startup emission writes the marker
        // at NOW and every later check asks isDue(NOW, NOW) — false. So the in-loop call site is
        // unreachable by any ordinary test, which is precisely how `beat()`'s went unexercised until
        // a review found that its closure's `use` list was missing an argument: the first genuinely
        // due beat would have thrown a TypeError and killed the watcher a day into an unattended run.
        //
        // The way in is `beat()`'s own: make the marker UNWRITABLE. `floorDigest()` writes it with
        // `@file_put_contents` precisely so a full volume cannot crash a scheduled emission, and
        // `lastDigestEmission()` reads `is_file()` — so a DIRECTORY at that path means the write
        // fails silently and every check is due. With a backlog over one batch, the startup drain
        // sends batch 1 and the IN-LOOP check sends batch 2, executing that call site for real,
        // `use` list and all.
        $root = $this->tempRoot();
        mkdir($root . '/state/rent-digest.txt', 0o775, true);

        $over = Store::DIGEST_BATCH + 1;
        for ($i = 0; $i < $over; $i++) {
            $this->seedDigestRow($root, 'BATCH-' . $i);
        }

        $r = $this->watch($root, passes: 1);

        self::assertSame(
            2,
            substr_count($r['out'], 'récapitulatif quotidien'),
            'startup drained batch 1; the IN-LOOP call site drained batch 2 — if this reads 1, that call site never ran',
        );
        self::assertSame([], $this->pending($root), 'and between them the whole bin drained');
    }

    // ── refusals at startup ──────────────────────────────────────────────────────────────────────

    public function testAnUnusableTimezoneRefusesAtStartupRatherThanMisfiringForever(): void
    {
        // Same rule as RENT_HEARTBEAT_HOURS, and for a sharper reason: a silently-defaulted zone puts the
        // floor two hours out in summer on a deployment whose operator believes they configured it,
        // and nothing about that looks wrong from outside.
        $root = $this->tempRoot();

        // Seeded, or Q36's flood guard refuses FIRST — an empty seen-set is what a missing volume
        // mount looks like — and this test would assert its exit code against the wrong refusal.
        // It did exactly that until the message assertion below caught it: both refusals exit 2.
        $this->seedSeenButNotDigested($root);
        putenv('TZ=Europe/Pariss');

        $r = $this->watch($root);

        self::assertSame(2, $r['code'], 'an unusable TZ must stop the watcher, not be guessed at');
        self::assertStringContainsString('TZ', $r['err']);

        // Recorded for the next successful start, like every other startup refusal (Q27). The
        // process exits before any channel exists, so under Docker this message is the only thing
        // that survives — its stderr scrolls past in a log nobody reads.
        self::assertFileExists($root . '/state/rent-last-refusal.txt');
    }

    public function testAnUnusableDigestHourRefusesRatherThanBeingClamped(): void
    {
        // The refusal comes from the CONFIG layer, one level above this feature: `digest_hour` is
        // read with `optInt('digest_hour', 8, 0, 23)` and `Reader::asInt` throws on a value outside
        // that range rather than clamping it. Pinned here anyway, because the guarantee belongs to
        // the floor now that something reads the key: `digest_hour: 24` is somebody meaning
        // midnight, and a clamp to 23 would emit an hour early for ever while looking configured.
        //
        // `DigestSchedule`'s own constructor check is therefore a SECOND line, unreachable from a
        // config file today. It stays for the reason `Heartbeat` documents for its own: a
        // precondition expressed in the signature breaks loudly when removed, where a comment does
        // not — and `DigestSchedule` is constructible from somewhere other than config tomorrow.
        $root = $this->tempRoot(['digest_hour' => 24]);

        $r = $this->watch($root);

        self::assertSame(2, $r['code']);
        self::assertStringContainsString('digest_hour', $r['err']);
    }

    // ── C2 round 6 (2026-09-05): the floor drains the low-score queue TOO, both ways ─────────────

    /**
     * The floor is the only automatic drain a `--watch` deployment has, so a push that FAILED and
     * outlived its source's re-emission is recovered here or nowhere. With no gate configured —
     * the default — nothing is ever held back, so every queued row is exactly that.
     */
    public function testTheFloorRePushesAQueuedMatchWhenNoGateIsConfigured(): void
    {
        $root = $this->tempRoot();
        $key = $this->seedQueuedMatch($root, 'RETRY-FLOOR');

        $r = $this->watch($root);

        self::assertSame(0, $r['code'], $r['err']);
        self::assertStringContainsString('réémise(s) individuellement', $r['out'], 'pushed by the floor, not left waiting');
        self::assertStringNotContainsString('Vérifié, score bas', $r['out'], 'no gate held it back — it is not a low score');
        self::assertTrue(Store::open($root . '/state/rent-watch.sqlite3')->wasNotifiedAs($key, 'MATCH'));
    }

    /**
     * §1 ON THE DEPLOYED DRAIN, AND THE ALL-REFUSED DAY — one scenario carrying both halves.
     *
     * The floor is the drain that runs unattended, so of the two it is the one that matters. A
     * queued MATCH whose CROSS-TRACK TWIN says `PLS` must not be rolled up: the twin is one of §1's
     * four persisted routes, and nothing about a row's own `MATCH` outcome releases it. What this
     * pins is that the floor re-reads the gate AT SEND TIME — a collect-time read alone misses a
     * twin written by a concurrent `run --watch` in the window between the two.
     *
     * AND WHEN THE GATE REFUSES THE WHOLE ROLLUP, THE FLOOR STAYS SILENT. It used to test the
     * UNFILTERED list for emptiness while the filter ran seven lines below, so an all-refused rollup
     * fell through, sent `Vérifié, score bas : 0 annonce(s)` — a mail saying nothing — and then
     * wrote the marker, recording the window as SERVED. Q34's ruling is verbatim the opposite, and a
     * doubt arriving later that same day would then have waited until tomorrow.
     *
     * A first pass at this milestone REMOVED the all-refused test as unreachable, reasoning that
     * `collectDigest()` refuses such a row upstream through all four routes. That reasoning holds
     * for ONE process; the twin is written by another, which is the documented shape that made the
     * retry case reachable two rounds earlier. **Failing to construct a case is not evidence that
     * none exists** — this repo has now paid for that inference three times.
     */
    public function testAFlatWhoseTwinSaysPLSIsNeitherRolledUpNorAnnouncedAsAnEmptyMail(): void
    {
        $root = $this->tempRoot(['notify' => ['push_min_score' => 100]]);
        $key = $this->seedQueuedMatch($root, 'TWIN-PLS-FLOOR');
        Store::open($root . '/state/rent-watch.sqlite3')->recordTwin($key, Tenure::PLS, 'cdc_habitat', 9000);

        $r = $this->watch($root);

        self::assertSame(0, $r['code'], $r['err']);
        self::assertStringNotContainsString('score bas', $r['out'], '§1 refuses the only rollup row');
        self::assertStringNotContainsString(
            'récapitulatif quotidien',
            $r['out'],
            'and an all-refused rollup sends no mail at all, not an empty one',
        );

        $store = Store::open($root . '/state/rent-watch.sqlite3');
        self::assertFalse($store->wasNotifiedAs($key, 'ROLLUP'), 'never announced');
        self::assertFalse($store->wasNotifiedAs($key, 'MATCH'), 'and never promoted over the gate either');

        self::assertFileDoesNotExist(
            $root . '/state/rent-digest.txt',
            'a floor that announced nothing must not consume the day\'s window',
        );
    }

    /** Under a gate it really fell short of, the same floor rolls it up and marks it ROLLUP. */
    public function testTheFloorRollsUpAMatchThatReallyFellShortOfTheGate(): void
    {
        $root = $this->tempRoot(['notify' => ['push_min_score' => 100]]);
        $key = $this->seedQueuedMatch($root, 'ROLLUP-FLOOR');

        $r = $this->watch($root);

        self::assertSame(0, $r['code'], $r['err']);
        self::assertStringContainsString('score bas', $r['out']);
        self::assertStringNotContainsString('réémise(s) individuellement', $r['out']);
        $store = Store::open($root . '/state/rent-watch.sqlite3');
        self::assertTrue($store->wasNotifiedAs($key, 'ROLLUP'));
        self::assertFalse($store->wasNotifiedAs($key, 'MATCH'), 'the promotion over the gate stays reachable');
    }

    /**
     * A queued MATCH: the shape the push gate and a failed send both leave behind — a `MATCH`
     * outcome with no `notified_at`. Its facts sit inside this class's criteria, because the drain
     * re-scores from the snapshot and leaves a row today's criteria reject waiting.
     */
    private function seedQueuedMatch(string $root, string $id): string
    {
        $listing = new RawListing(
            sourceName: 'inli',
            externalId: $id,
            title: 'Appartement 3 pièces',
            description: 'Logement intermédiaire (LLI).',
            fields: ['financement' => 'LLI'],
            commune: 'Sartrouville',
            postcode: '78500',
            rentCc: 1450,
            surfaceM2: 88.0,
            rooms: 4,
        );

        $store = Store::open($root . '/state/rent-watch.sqlite3');
        $sighting = $store->record($listing, $listing->effectiveRentCc(), self::NOW);
        $store->recordVerdict($sighting->dedupKey, 'LLI', 90, ['champ structuré financement = « LLI »'], $listing);
        $store->recordOutcome($sighting->dedupKey, 'MATCH');

        return $sighting->dedupKey;
    }

    // ── harness ──────────────────────────────────────────────────────────────────────────────────

    /** @return array{code: int, out: string, err: string} */
    /**
     * @param list<\Scout\Core\Notify\NotificationKind> $refuses kinds the channel will not deliver
     */
    private function watch(string $root, int $passes = 1, array $refuses = []): array
    {
        putenv('SCOUT_MAX_PASSES=' . $passes);

        $out = fopen('php://memory', 'r+');
        $err = fopen('php://memory', 'r+');
        self::assertIsResource($err);
        self::assertIsResource($out);

        putenv('RENT_SCOUT_DB=' . $root . '/state/rent-watch.sqlite3');

        $channel = new DeliveringChannel();
        $channel->refuses = $refuses;
        $notifier = new Notifier([new ConsoleChannel($out), $channel]);
        $code = (new RentScout($root, $out, $err, self::NOW, null, $notifier))->run(['run', '--watch']);

        rewind($out);
        rewind($err);

        return ['code' => $code, 'out' => (string) stream_get_contents($out), 'err' => (string) stream_get_contents($err)];
    }

    private function seedDigestRow(string $root, string $id): string
    {
        $listing = new RawListing(
            sourceName: 'cdc_habitat',
            externalId: $id,
            title: 'Appartement T4',
            description: 'Aucun régime annoncé',
            commune: 'Sartrouville',
            postcode: '78500',
            rentCc: 1450,
            surfaceM2: 82.0,
            rooms: 4,
        );

        $store = Store::open($root . '/state/rent-watch.sqlite3');
        $sighting = $store->record($listing, $listing->effectiveRentCc(), self::NOW);
        $store->recordVerdict($sighting->dedupKey, 'UNKNOWN', 0, ['aucun signal de régime'], $listing);
        $store->recordOutcome($sighting->dedupKey, 'DIGEST');

        return $sighting->dedupKey;
    }

    /**
     * A row that is SEEN but not digested.
     *
     * The empty-bin test needs a non-empty seen-set: Q36 makes `scout run` refuse to notify while
     * the seen-set is empty, because that is what a missing volume mount looks like. Seeding a
     * MATCH rather than nothing keeps the guard satisfied without putting anything in the bin.
     */
    private function seedSeenButNotDigested(string $root): void
    {
        $listing = new RawListing(
            sourceName: 'cdc_habitat',
            externalId: 'DEJA-VU',
            title: 'Appartement T4',
            commune: 'Sartrouville',
            postcode: '78500',
            rentCc: 1450,
            surfaceM2: 82.0,
            rooms: 4,
        );

        $store = Store::open($root . '/state/rent-watch.sqlite3');
        $sighting = $store->record($listing, $listing->effectiveRentCc(), self::NOW);
        $store->recordVerdict($sighting->dedupKey, 'LLI', 90, ['intermédiaire'], $listing);
        $store->recordOutcome($sighting->dedupKey, 'MATCH');
        $store->markNotified($sighting->dedupKey, self::NOW, 'MATCH');
    }

    /** @return list<array<string, mixed>> */
    private function pending(string $root): array
    {
        return Store::open($root . '/state/rent-watch.sqlite3')->pendingDigest(1000);
    }

    private function notifiedAt(string $root, string $key): ?string
    {
        $pdo = new \PDO('sqlite:' . $root . '/state/rent-watch.sqlite3');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $stmt = $pdo->prepare('SELECT notified_at FROM listings WHERE dedup_key = :key');
        $stmt->execute(['key' => $key]);
        $value = $stmt->fetchColumn();

        return is_string($value) ? $value : null;
    }

    /** @param array<string, mixed> $criteria */
    private function tempRoot(array $criteria = []): string
    {
        $root = sys_get_temp_dir() . '/rentwatch-floor-' . bin2hex(random_bytes(8));
        mkdir($root . '/config/rent', 0o775, true);
        mkdir($root . '/state', 0o775, true);
        $this->roots[] = $root;

        file_put_contents($root . '/config/rent/criteria.json', json_encode($criteria + [
            'communes' => ['Sartrouville'],
            'postcode_prefixes' => ['78'],
            'min_rooms' => 3,
            'max_rent_cc' => 1800,
        ], JSON_THROW_ON_ERROR));

        // ONE ENABLED SOURCE, and it has to be enabled: `run` short-circuits with "aucune source
        // activée — rien à faire" before it ever reaches the watch loop, so a config with nothing
        // enabled tests nothing about the floor.
        //
        // It is safe to enable precisely because its `map` carries no commune and no postcode:
        // `matchesCommune()` rejects every fixture listing before the tenure branch is reached, so
        // the pass contributes REJECT outcomes and nothing at all to the digest bin. Every count in
        // this class therefore describes only the rows it seeded. If that map ever gains a commune,
        // these counts start describing something else.
        file_put_contents($root . '/config/rent/sources.json', json_encode([
            'sources' => [
                'fixture_demo' => [
                    'enabled' => true,
                    'family' => 'institutional',
                    // Required, and `run` is the verb that validates it — unlike `digest`, which
                    // never loads sources at all. It is the flag that arms §1's fail-closed rule.
                    'mixed_tenure' => true,
                    'type' => 'fixture',
                    'fixture' => 'tests/fixtures/rent/fixture_demo/search.json',
                    'items_path' => 'results.items',
                    'map' => ['ref' => 'id', 'title' => 'title'],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        return $root;
    }

    private function rmrf(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        if (is_dir($path)) {
            foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
                $this->rmrf($path . '/' . $entry);
            }
            rmdir($path);

            return;
        }

        unlink($path);
    }
}
