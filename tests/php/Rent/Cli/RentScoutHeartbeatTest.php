<?php

declare(strict_types=1);

namespace Scout\Tests\Rent\Cli;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Scout\Rent\Cli\RentScout;
use Scout\Rent\Store\Store;
use Scout\Core\Notify\ConsoleChannel;
use Scout\Core\Notify\Notifier;
use Scout\Core\Notify\NotificationKind;
use Scout\Tests\Support\DeliveringChannel;

/**
 * Q27 in the loop, rather than in the policy object.
 *
 * {@see \Scout\Tests\Core\HeartbeatTest} proves the interval arithmetic. This proves the thing
 * that arithmetic is for: that `scout run --watch` actually emits the beat, that it emits it
 * **whether or not anything matched**, and that it does not emit one per pass. Before this suite
 * existed, `RENT_HEARTBEAT_HOURS` was documented in `.env.example` and read by no code at all — the beat
 * existed only as a `NotificationKind` used by the manual `test-notify` one-shot, so a watcher that
 * died at 03:00 was indistinguishable from one watching a quiet market until somebody thought to
 * look.
 *
 * The clock is FIXED (RentScout takes `$nowIso`), which is what makes "exactly one beat" assertable
 * without waiting 24 hours: the startup beat writes a marker at time T, and every later check in the
 * same run asks `isDue(T, T)`, which is false. A second beat appearing anyway would mean the marker
 * is not being written or not being read — the two ways this feature rots into a per-pass spam.
 */
#[CoversNothing]
final class RentScoutHeartbeatTest extends TestCase
{
    private const string NOW = '2026-08-22T12:00:00+02:00';

    /** @var list<string> */
    private array $roots = [];

    protected function setUp(): void
    {
        // `--watch`'s success case never returns, so the bound is not optional: without it a wrong
        // expectation does not fail, it hangs — and it hangs the suite and the sabotage ledger
        // behind it.
        //
        // ONE, not two. `WatchLoop` only breaks after the LAST pass, so a bound of 2 makes it sleep
        // out the real Q37 inter-pass interval — fifteen minutes — between them. Written as 2 first,
        // and the run had to be killed at 300 s.
        putenv('SCOUT_MAX_PASSES=1');
    }

    protected function tearDown(): void
    {
        putenv('SCOUT_MAX_PASSES');
        putenv('RENT_HEARTBEAT_HOURS');
        putenv('RENT_SCOUT_DB');

        foreach ($this->roots as $root) {
            self::removeTree($root);
        }

        $this->roots = [];
    }

    public function testTheWatcherAnnouncesItsHeartbeatIntervalOnStartup(): void
    {
        // An interval nobody was told about cannot be used to judge whether the watcher has gone
        // quiet, which is the entire mechanism.
        $r = $this->watch();

        self::assertStringContainsString('battement de cœur', $r['out']);
        self::assertStringContainsString('24 h', $r['out'], 'the ruled default must be visible');
    }

    public function testAHeartbeatIsEmittedEvenThoughNothingMatched(): void
    {
        // The whole point of Q27. The fixture source matches nothing against these criteria, so a
        // watcher that only spoke when it had news would say nothing at all here.
        $r = $this->watch();

        self::assertStringContainsString('toujours actif', $r['out']);
    }

    public function testTheMarkerSuppressesASecondBeatAcrossARESTART(): void
    {
        // The failure this guards is a beat every time the watcher starts or passes: at the Q37
        // cadence that is one every fifteen minutes, which trains the operator to ignore them, which
        // removes the signal as surely as never sending it.
        //
        // Tested across two RUNS rather than two passes, and that is the better test rather than a
        // workaround: the loop only breaks after its last pass, so a two-pass bound would sleep out
        // the real inter-pass interval. Two runs against one `state/` directory is also the shape
        // this actually takes in production — a container that restarts is the common case, and the
        // marker surviving it is exactly what Q8's mounted volume is for.
        $root = $this->tempRoot();

        $first = $this->watchIn($root, $this->delivering());
        $second = $this->scoutIn($root, ['run', '--watch'], null, $this->delivering());

        self::assertSame(1, substr_count($first['out'], 'toujours actif'), 'the first start beats');
        self::assertStringNotContainsString(
            'toujours actif',
            $second['out'],
            'the marker must suppress the next start — one beat per interval, not one per restart',
        );
    }

    public function testTheHeartbeatCarriesWhatQ27SaysItMustCarry(): void
    {
        // "stating runs completed, sources OK and matches sent".
        $r = $this->watch();

        self::assertStringContainsString('surveillance', $r['out']);
        self::assertMatchesRegularExpression('~\d+ annonce\(s\) notifiée\(s\)~', $r['out']);
        self::assertMatchesRegularExpression('~\d+/\d+ source\(s\) en bon état~', $r['out']);
    }

    public function testTheHeartbeatReportsTheNumberItActuallyPushed(): void
    {
        // **This field was the literal `0` at both call sites**, so the one number separating a
        // producing watcher from a mute one was constant: on the day matching genuinely stopped,
        // the beat read byte-for-byte identical to the day it pushed 33. `CLAUDE.md` and `beat()`'s
        // own docblock both claimed it carried this. Found by a review panel on 2026-08-24.
        //
        // The sibling test above asserts the SHAPE with `\d+`, which matches `0` — so it accepted
        // the constant for as long as the constant existed. Shape is not value; this one demands a
        // number the pass actually produced.
        //
        // Reaching the in-loop beat needs the documented unwritable-marker seam: under a fixed
        // clock the startup beat writes the marker at NOW and every later check asks
        // `isDue(NOW, NOW)`. A DIRECTORY where the file goes makes every check due — `beat()` writes
        // with `@file_put_contents` precisely so a full volume cannot crash a liveness signal — and
        // two beats is then the CORRECT result, per Q27's documented one-too-many bias.
        $root = $this->tempRoot([
            // Region mode, and wide open: the point is to produce matches, not to test filtering.
            'communes' => [],
            'postcode_prefixes' => ['75', '77', '78', '91', '92', '93', '94', '95'],
            'min_rooms' => 1,
            'min_surface_m2' => 1,
            'max_rent_cc' => 100000,
        ]);

        // SEEDED ON A SUBSET, then the full payload restored. `watchIn()` seeds the whole fixture
        // first — Q36 refuses to notify on an empty seen-set — and after that nothing is new, so
        // the watch pass would legitimately push zero and the test would assert nothing. Here the
        // seed sees one listing and the watched pass sees the rest, which is what a real pass does.
        $full = (string) file_get_contents($root . '/tests/fixtures/rent/fixture_demo/search.json');
        /** @var array{results: array{items: list<array<string, mixed>>}} $payload */
        $payload = json_decode($full, true, 512, JSON_THROW_ON_ERROR);
        $subset = $payload;
        $subset['results']['items'] = \array_slice($payload['results']['items'], 0, 1);
        file_put_contents($root . '/tests/fixtures/rent/fixture_demo/search.json', json_encode($subset, JSON_THROW_ON_ERROR));

        $this->scoutIn($root, ['run', '--once', '--seed'], null, $this->delivering());

        file_put_contents($root . '/tests/fixtures/rent/fixture_demo/search.json', $full);
        mkdir($root . '/state/rent-heartbeat.txt', 0o775, true);

        $r = $this->scoutIn($root, ['run', '--watch'], null, $this->delivering());

        self::assertMatchesRegularExpression(
            '~[1-9]\d* annonce\(s\) notifiée\(s\)~',
            $r['out'],
            'the beat must report what the pass actually pushed — a hard-coded 0 conveys nothing at any time',
        );
    }

    public function testTheBeatSaysZeroOnASteadyStatePassThatPushedNothing(): void
    {
        // **The round-4 fix replaced a constant `0` with a number that is wrong the other way.**
        // The beat was wired to `RunResult::matches`, which `Pipeline` increments BEFORE the
        // `wasNotifiedAs(..., 'MATCH')` gate and before `delivered()` — so it counts every
        // match-outcome survivor, including every listing already announced on an earlier pass.
        //
        // Steady state is the ordinary mode of a `--watch` deployment: everything published has
        // already been notified, nothing is pushed, and the beat claimed the full standing match
        // count anyway — cumulatively, growing by that count every pass. At Q37 cadence and the
        // live criteria (83 matches of 478) a day-two beat would read ~8000 pushes having sent
        // none. Found by a review panel on 2026-08-24, one round after the constant.
        //
        // Seeding is exactly this state: `--seed` marks everything currently published as already
        // announced. The sibling test above seeds a ONE-ITEM subset, so every match in its watched
        // pass is genuinely new — `judged` and `pushed` are equal there, which is why `[1-9]\d*`
        // could not tell them apart.
        $root = $this->tempRoot([
            'communes' => [],
            'postcode_prefixes' => ['75', '77', '78', '91', '92', '93', '94', '95'],
            'min_rooms' => 1,
            'min_surface_m2' => 1,
            'max_rent_cc' => 100000,
        ]);

        $seed = $this->scoutIn($root, ['run', '--once', '--seed']);
        self::assertMatchesRegularExpression('~[1-9]\d* correspondance~', $seed['out'], 'the seed must have matched something');

        mkdir($root . '/state/rent-heartbeat.txt', 0o775, true);

        $r = $this->scoutIn($root, ['run', '--watch']);

        // THE IN-LOOP BEAT, not the startup one. The startup beat legitimately says 0 — no pass has
        // run yet — so a bare `assertStringContainsString('0 annonce')` passes on the broken code
        // by matching the wrong beat. Written that way first, and it went green against the very
        // defect it was added for.
        $inLoop = strstr($r['out'], '1 passe(s) terminée(s)');
        self::assertIsString($inLoop, 'the watched pass must have completed and beaten');

        self::assertStringContainsString(
            '0 annonce(s) notifiée(s)',
            $inLoop,
            'a pass that pushed nothing must not claim pushes — the beat reports DELIVERIES, not verdicts',
        );
    }

    public function testTheMarkerIsWrittenToTheStateDirectorySoItSurvivesARestart(): void
    {
        // Q8 puts `state/` on the mounted volume. A marker written anywhere else resets on every
        // deploy, and a marker that resets makes every restart emit a beat — which is harmless, and
        // makes the interval meaningless.
        $root = $this->tempRoot();
        $this->watchIn($root, $this->delivering());

        self::assertFileExists($root . '/state/rent-heartbeat.txt');
        self::assertStringContainsString(
            '2026-08-22',
            (string) file_get_contents($root . '/state/rent-heartbeat.txt'),
        );
    }

    public function testAnUnprefixedMarkerFromBeforeTheSplitIsRenamedOnceAndStillSuppressesTheBeat(): void
    {
        // The markers became per-domain (`rent-heartbeat.txt`) in the generic-scout restructuring.
        // A deployment carrying the old `heartbeat.txt` must NOT beat on its first start after the
        // redeploy — the old marker is renamed before anything reads it, and keeps its content.
        $root = $this->tempRoot();
        file_put_contents($root . '/state/heartbeat.txt', '2026-08-22T11:00:00+02:00');

        $r = $this->watchIn($root, $this->delivering());

        self::assertStringNotContainsString('toujours actif', $r['out'], 'a marker one hour old must suppress the startup beat, whatever it is called');
        self::assertFileDoesNotExist($root . '/state/heartbeat.txt');
        self::assertSame('2026-08-22T11:00:00+02:00', file_get_contents($root . '/state/rent-heartbeat.txt'));
    }

    public function testAMarkerAlreadyUnderTheNewNameIsNeverOverwrittenByAnOldOne(): void
    {
        // Both present: the new one is newer by construction and the old one is left alone.
        $root = $this->tempRoot();
        file_put_contents($root . '/state/heartbeat.txt', '2026-08-01T11:00:00+02:00');
        file_put_contents($root . '/state/rent-heartbeat.txt', '2026-08-22T11:00:00+02:00');

        $this->watchIn($root, $this->delivering());

        self::assertSame('2026-08-22T11:00:00+02:00', file_get_contents($root . '/state/rent-heartbeat.txt'));
    }
    public function testAllThreePreSplitMarkersAreRenamedNotOnlyTheHeartbeat(): void
    {
        // A review panel deleted the `digest.txt` entry of the migration map and the suite stayed
        // green: only the heartbeat half was pinned. A deployment carrying `digest.txt` would
        // re-serve today's digest window on its first start after the redeploy.
        // No heartbeat marker: a cold start beats, and that beat is what REPORTS the migrated
        // refusal note (Q27) and then clears it — so the note's migration is proven by the report.
        $root = $this->tempRoot();
        file_put_contents($root . '/state/digest.txt', '2026-08-22T08:00:00+02:00');
        file_put_contents($root . '/state/last-refusal.txt', '2026-08-21T07:00:00+02:00 — refus précédent');

        $r = $this->watchIn($root, $this->delivering());

        self::assertStringContainsString('refus précédent', $r['out'], 'the pre-split refusal note is read under its new name and reported');
        foreach (['digest', 'last-refusal'] as $name) {
            self::assertFileDoesNotExist($root . "/state/$name.txt", "$name.txt must be renamed away");
        }
        self::assertSame('2026-08-22T08:00:00+02:00', (string) file_get_contents($root . '/state/rent-digest.txt'), 'the digest window survives under its new name');
        self::assertFileDoesNotExist($root . '/state/rent-last-refusal.txt', 'reported, therefore cleared');
    }
    public function testANewerStoreIsARecordedRefusalNotATrace(): void
    {
        // Round-3 panel: a rollback image opening a v12 store died with an uncaught RuntimeException
        // and no note — the one failure Q27's note exists for, on the deployment path that
        // produces it (a redeploy that migrated, then a rollback).
        $root = $this->tempRoot();
        Store::open($root . '/state/rent-watch.sqlite3');
        (new \PDO('sqlite:' . $root . '/state/rent-watch.sqlite3'))->exec("UPDATE schema_meta SET value = '99' WHERE key = 'schema_version'");

        $r = $this->watchIn($root, $this->delivering());

        self::assertSame(2, $r['code']);
        self::assertStringContainsString('version 99', $r['err']);
        self::assertStringNotContainsString('Stack trace', $r['err'] . $r['out']);
        self::assertFileExists($root . '/state/rent-last-refusal.txt');
    }
    public function testAnExistingRecentMarkerSuppressesTheStartupBeat(): void
    {
        // A container restarted twice in a minute must not beat twice.
        $root = $this->tempRoot();
        file_put_contents($root . '/state/rent-heartbeat.txt', '2026-08-22T11:00:00+02:00');

        $r = $this->watchIn($root, $this->delivering());

        self::assertStringNotContainsString('toujours actif', $r['out'], 'one hour is inside a 24h interval');
    }

    public function testAnOldMarkerDoesNotSuppressIt(): void
    {
        $root = $this->tempRoot();
        file_put_contents($root . '/state/rent-heartbeat.txt', '2026-08-20T11:00:00+02:00');

        $r = $this->watchIn($root);

        self::assertStringContainsString('toujours actif', $r['out'], 'two days is well past the interval');
    }

    public function testAnUnusableHeartbeatHoursRefusesAtStartupRatherThanADayLater(): void
    {
        // Refused before the loop, deliberately. Discovering a bad value on the first due beat would
        // mean discovering it a day into an unattended run — and by then the operator has already
        // concluded the market is quiet.
        putenv('RENT_HEARTBEAT_HOURS=0');

        $r = $this->watch();

        self::assertNotSame(0, $r['code']);
        self::assertStringContainsString('RENT_HEARTBEAT_HOURS', $r['out'] . $r['err']);
    }

    public function testAShorterIntervalIsHonoured(): void
    {
        putenv('RENT_HEARTBEAT_HOURS=1');
        $root = $this->tempRoot();
        file_put_contents($root . '/state/rent-heartbeat.txt', '2026-08-22T10:30:00+02:00');

        $r = $this->watchIn($root);

        self::assertStringContainsString('toujours actif', $r['out'], '90 minutes is past a 1h interval');
    }

    // ── the other half of Q27: what happened while it was down ───────────────────────────────────

    public function testAPreviousStartupRefusalIsReportedOnTheNextStartAndThenCleared(): void
    {
        // A startup refusal reaches nobody: the process exits before any channel is used, and under
        // Docker its stderr scrolls past in a log nobody is reading.
        //
        // A DELIVERING channel, since round 5: the note is now cleared only once a beat has actually
        // been delivered, and `console` alone does not count as reaching a recipient. Passing none
        // here used to pass because the old code cleared unconditionally — which is precisely the
        // defect (a beat that reached nobody still consumed the note).
        $root = $this->tempRoot();
        file_put_contents($root . '/state/rent-last-refusal.txt', '2026-08-21T22:00:00+02:00 — canal ntfy sans RENT_NTFY_TOPIC');

        $r = $this->watchIn($root, $this->delivering());

        self::assertStringContainsString('refus au démarrage précédent', $r['out']);
        self::assertStringContainsString('ntfy', $r['out']);
        self::assertFileDoesNotExist(
            $root . '/state/rent-last-refusal.txt',
            'reported once, not on every start for the life of the deployment',
        );
    }

    /**
     * A REFUSAL SURVIVES A RESTART INSIDE THE HEARTBEAT INTERVAL (round-4 panel, 2026-08-31).
     *
     * The test above uses a COLD start — no marker — so the startup beat is always due and the note
     * is always reported. The ordinary state of a fix-and-redeploy is the opposite: a marker written
     * hours ago, well inside `RENT_HEARTBEAT_HOURS`. The note was read and DELETED above the
     * `isDue()` test while the in-loop beat passed a literal `null`, so in that state it was
     * destroyed and no beat ever carried it — a container crash-looping all night on a bad config,
     * fixed the next morning, announced the outage to nobody.
     *
     * The note must therefore still be on disk when no beat has carried it.
     */
    public function testARefusalIsNotDestroyedByAStartThatSendsNoHeartbeat(): void
    {
        $root = $this->tempRoot();
        // One hour before `self::NOW`, against the 24 h default: no beat is due in this process at
        // all. Anchored to the CLI's FIXED clock, not to real time — a marker written from
        // `new \DateTimeImmutable('-1 hour')` is in the FUTURE relative to `self::NOW`, and a future
        // marker is documented as DUE (the bias is always one beat too many). A first version of this
        // test did exactly that and read as a code defect.
        file_put_contents($root . '/state/rent-heartbeat.txt', (new \DateTimeImmutable(self::NOW))->modify('-1 hour')->format('Y-m-d\TH:i:sP'));
        file_put_contents($root . '/state/rent-last-refusal.txt', '2026-08-21T22:00:00+02:00 — canal ntfy sans RENT_NTFY_TOPIC');

        $r = $this->watchIn($root);

        self::assertStringNotContainsString('refus au démarrage précédent', $r['out'], 'no beat was due, so nothing reported it');
        self::assertFileExists(
            $root . '/state/rent-last-refusal.txt',
            'and it is still on disk, waiting for the beat that will carry it — not silently consumed',
        );
    }

    /**
     * `doctor` REPORTS A PENDING REFUSAL WITHOUT CONSUMING IT (round-4 panel, 2026-08-31). The note
     * had exactly one reader — the heartbeat, which exists only under `--watch` — while `CLAUDE.md`
     * names cron-driven `--once` as a supported deployment. There, `failRun()` wrote the note on
     * every refused run and nothing ever read it. Read-only, so a diagnostic cannot swallow the note
     * before the beat that is supposed to push it.
     */
    public function testDoctorReportsAPendingRefusalWithoutConsumingIt(): void
    {
        $root = $this->tempRoot();
        file_put_contents($root . '/state/rent-last-refusal.txt', '2026-08-21T22:00:00+02:00 — canal ntfy sans RENT_NTFY_TOPIC');

        $r = $this->scoutIn($root, ['doctor']);

        self::assertStringContainsString('ntfy', $r['out'], 'doctor names the refusal');
        self::assertFileExists(
            $root . '/state/rent-last-refusal.txt',
            'and leaves it for the heartbeat — a diagnostic must not consume what a channel still owes',
        );
    }

    /**
     * AND IT IS REPORTED BEFORE THE BOOTSTRAP THAT THE REFUSAL WOULD BLOCK (round-5 panel,
     * 2026-08-31). The line above used to print after `criteria()`, `store()` and `sources()`, so it
     * was reachable only while `doctor`'s OWN start-up succeeded — never for a refusal whose cause
     * still blocks start-up. `CLAUDE.md` names a malformed config as the commonest startup refusal
     * there is, which is exactly the case it could not report.
     */
    public function testAPendingRefusalIsReportedEvenWhenTheConfigItselfIsUnusable(): void
    {
        $root = $this->tempRoot();
        file_put_contents($root . '/state/rent-last-refusal.txt', '2026-08-21T22:00:00+02:00 — configuration illisible');
        // The config the verb loads FIRST, made unusable. `doctor` must still say what happened.
        file_put_contents($root . '/config/rent/criteria.json', '{"bogus_key": 1}');

        $r = $this->scoutIn($root, ['doctor']);

        self::assertNotSame(0, $r['code'], 'the config is genuinely unusable');
        self::assertStringContainsString(
            'configuration illisible',
            $r['out'],
            'and the refusal is still reported — read before the bootstrap that would block it',
        );
    }

    /**
     * A BEAT THAT DOES NOT DELIVER MUST NOT CONSUME THE NOTE (round-5 panel, 2026-08-31).
     *
     * Round 4 moved the read from startup into the beat and claimed that made the note "survive
     * until a beat actually carries it". It survived until a beat was ATTEMPTED: `beat()`
     * deliberately does not write the heartbeat marker when delivery fails, so the beat RETRIES —
     * and the note had already been unlinked, so the retry carried nothing.
     *
     * The correlation is the whole point rather than a corner case: the commonest startup refusal
     * there is IS a channel misconfiguration — this repo's own example note reads `canal ntfy sans
     * RENT_NTFY_TOPIC` — so the beat that ought to carry the note is precisely the one most likely
     * to fail.
     */
    public function testAnUndeliveredBeatLeavesTheRefusalNoteForTheNextOne(): void
    {
        $root = $this->tempRoot();
        file_put_contents($root . '/state/rent-last-refusal.txt', '2026-08-21T22:00:00+02:00 — canal ntfy sans RENT_NTFY_TOPIC');

        $channel = $this->delivering();
        $channel->refuses = [NotificationKind::HEARTBEAT];

        $this->watchIn($root, $channel);

        self::assertFileExists(
            $root . '/state/rent-last-refusal.txt',
            'nothing was delivered, so the note is still owed and must still be on disk',
        );
        self::assertFileDoesNotExist(
            $root . '/state/rent-heartbeat.txt',
            'and the beat marker is not written either — the note and the marker rise and fall together',
        );
    }

    /**
     * ON A CRON `--once` DEPLOYMENT THE NOTE STILL GETS OUT (round-5 panel, 2026-08-31).
     *
     * Q27's promise is that the next successful start reports the refusal and clears it — and
     * `--once` has no beat at all, so on the deployment `CLAUDE.md` names as supported the note was
     * written by every refused run and read by nothing. Since round 5 the note is also only cleared
     * on delivery, so it would have sat there for ever: "an alert nobody retracts becomes
     * furniture", from the other end.
     *
     * A pending note therefore forces ONE beat after a successful `--once`. That is not the
     * per-pass spam Q27 guards against: it fires only when a note is actually pending, and
     * delivering it is what clears it, so it cannot repeat.
     */
    public function testASuccessfulOncePassCarriesAPendingRefusalAndClearsIt(): void
    {
        $root = $this->tempRoot();
        $channel = $this->delivering();

        // Seed first, or Q36 refuses and there is no successful pass to hang the beat on.
        $this->scoutIn($root, ['run', '--once', '--seed'], null, $channel);
        file_put_contents($root . '/state/rent-last-refusal.txt', '2026-08-21T22:00:00+02:00 — canal ntfy sans RENT_NTFY_TOPIC');

        $r = $this->scoutIn($root, ['run', '--once'], null, $channel);

        self::assertSame(0, $r['code'], $r['err']);
        self::assertStringContainsString('refus au démarrage précédent', $r['out'], 'the note reaches a channel on a deployment that never watches');
        self::assertFileDoesNotExist($root . '/state/rent-last-refusal.txt', 'delivered, therefore cleared — it cannot become furniture');

        // And it does not repeat: a second successful pass with no note pending must not beat.
        $second = $this->scoutIn($root, ['run', '--once'], null, $channel);
        self::assertStringNotContainsString('refus au démarrage précédent', $second['out'], 'one push, not one per run');

        // AND IT REPORTS WHAT THE PASS ACTUALLY PUSHED. The call site passed a literal `0` while the
        // out-parameter that carries the real figure sat nineteen lines above it — which is the very
        // defect `tests/sabotage-check.sh` pins on the WATCH path ("the beat's notified count goes
        // back to a hard-coded 0, constant at any traffic level"), re-introduced on the `--once`
        // path (round-6 panel, found by two lenses). A beat that always says 0 cannot distinguish a
        // producing watcher from a mute one, which is the one thing it exists to do.
        self::assertStringContainsString('0 annonce(s) notifiée(s)', $r['out'], 'this pass pushed nothing, and the beat says so honestly');
    }

    /** The other half of that: a pass that DID push must not have its count hard-coded away. */
    public function testTheForcedOnceBeatReportsWhatThePassActuallyPushed(): void
    {
        $root = $this->tempRoot([
            'communes' => [],
            'postcode_prefixes' => ['75', '77', '78', '91', '92', '93', '94', '95'],
            'min_rooms' => 1,
            'min_surface_m2' => 1,
            'max_rent_cc' => 100000,
        ]);
        $channel = $this->delivering();

        // Seed on a SUBSET so the next pass has genuinely new listings to announce, exactly as
        // testTheHeartbeatReportsTheNumberItActuallyPushed does.
        $full = (string) file_get_contents($root . '/tests/fixtures/rent/fixture_demo/search.json');
        /** @var array{results: array{items: list<array<string, mixed>>}} $payload */
        $payload = json_decode($full, true, 512, JSON_THROW_ON_ERROR);
        $subset = $payload;
        $subset['results']['items'] = \array_slice($payload['results']['items'], 0, 1);
        file_put_contents($root . '/tests/fixtures/rent/fixture_demo/search.json', json_encode($subset, JSON_THROW_ON_ERROR));
        $this->scoutIn($root, ['run', '--once', '--seed'], null, $channel);
        file_put_contents($root . '/tests/fixtures/rent/fixture_demo/search.json', $full);

        file_put_contents($root . '/state/rent-last-refusal.txt', '2026-08-21T22:00:00+02:00 — canal ntfy sans RENT_NTFY_TOPIC');

        $r = $this->scoutIn($root, ['run', '--once'], null, $channel);

        self::assertSame(0, $r['code'], $r['err']);
        self::assertStringContainsString('refus au démarrage précédent', $r['out'], 'the forced beat fired');
        self::assertDoesNotMatchRegularExpression(
            '~· 0 annonce\(s\) notifiée\(s\)~',
            $r['out'],
            'the pass pushed listings, so a hard-coded 0 here would make the beat constant at any traffic level',
        );
    }

    public function testARunRefusalWritesTheNoteForTheNextStart(): void
    {
        // The producing half. An empty seen-set is Q36's refusal and is the easiest to provoke.
        $root = $this->tempRoot();
        putenv('RENT_SCOUT_DB=' . $root . '/state/rent-watch.sqlite3');

        $r = $this->scoutIn($root, ['run', '--once']);

        self::assertSame(2, $r['code'], 'an empty seen-set is a startup refusal (Q36)');
        self::assertFileExists($root . '/state/rent-last-refusal.txt');
        self::assertStringContainsString('base vide', (string) file_get_contents($root . '/state/rent-last-refusal.txt'));
    }

    public function testTheRefusalNoteIsRedactedBeforeItTouchesTheDisk(): void
    {
        // Found by sabotage: removing `Redact::text()` from the write left the suite green, so the
        // guard was untested. A startup refusal is exactly the text that carries a credential, and
        // this is a second disk-write funnel beside `Store::recordRun()`, which redacts for the
        // same reason.
        //
        // A CONFIG error is the vehicle, because it is the only startup refusal whose message
        // quotes back a value the operator supplied — which is precisely how a secret ends up in
        // one. Here the bad value is a mailbox URL with a password in it, the shape somebody
        // actually pastes into a config file by mistake.
        $root = $this->tempRoot(['min_rooms' => 'imap://user:hunter2@mail.example.test/INBOX']);
        putenv('RENT_SCOUT_DB=' . $root . '/state/rent-watch.sqlite3');

        $r = $this->scoutIn($root, ['run', '--once']);

        self::assertSame(2, $r['code'], 'a malformed criteria.json is a startup refusal');
        self::assertFileExists($root . '/state/rent-last-refusal.txt', 'a config refusal during `run` must be recorded');

        $note = (string) file_get_contents($root . '/state/rent-last-refusal.txt');
        self::assertStringNotContainsString('hunter2', $note, 'the password must never reach the disk');
        self::assertStringContainsString('masqué', $note, 'and the masking must be visible, not a silent drop');
    }

    public function testTheRecordedRefusalRunsThroughRedactAtAll(): void
    {
        // The direct assertion on the funnel, independent of which refusal happens to fire: whatever
        // reaches that file must have been through Redact, and Redact demonstrably masks this shape.
        self::assertStringNotContainsString(
            'hunter2',
            (string) \Scout\Core\Redact::text('échec IMAP sur imap://user:hunter2@mail.example.test/INBOX'),
        );
    }

    public function testAnUnwritableDatabaseIsARefusalRatherThanAStackTrace(): void
    {
        // Root ignores a 0500 directory, so the premise cannot be built: a claude.ai cloud session runs
        // everything as root (measured 2026-11-16, uid 0: the store opened and this test read "base vide").
        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('running as root: a 0500 directory does not refuse root, so the store cannot be made unwritable');
        }

        // The first thing a new deployment hits, and it was a fatal PDOException with a stack trace:
        // Q8 bind-mounts `state/` from the host, the image runs as a non-root uid, and a host
        // directory owned by somebody else is not writable — measured, running the real container.
        //
        // A malformed config is already ruled "an ordinary, expected, user-caused condition — caught
        // and printed, never a stack trace" (ConfigError). An unmounted or unwritable volume is the
        // same kind of condition and the likeliest one in production, so it gets the same treatment,
        // and the message has to name the PATH — that is the whole diagnosis.
        // An UNWRITABLE directory, not a missing one: `Store::open()` creates missing directories,
        // and in the container the directory existed and was owned by another uid. Getting this
        // wrong the first time is the point — the reproduction has to match the real failure.
        $root = $this->tempRoot();
        $locked = $root . '/locked';
        mkdir($locked, 0o500, true);
        $r = $this->scoutIn($root, ['run', '--once'], $locked . '/rent-watch.sqlite3');
        $text = $r['out'] . $r['err'];
        chmod($locked, 0o700);

        self::assertSame(2, $r['code'], 'an unusable store is a startup refusal');
        self::assertStringNotContainsString('Stack trace', $text);
        self::assertStringNotContainsString('PDOException', $text);
        self::assertStringContainsString('locked', $text, 'the refusal must name the path it could not open');
    }

    public function testAScopedWatcherReportsHealthForTheSourcesItActuallyWatches(): void
    {
        // Observed on a real container, running `--watch --source=fixture_demo` against the shipped
        // five-source config: the startup beat said "1/5 source(s) en bon état". Four of those five
        // were never polled — the operator excluded them — so the beat was reporting four faults
        // that did not exist, in the ONE channel whose entire value is that it can be believed.
        //
        // It gets worse the longer it runs: an unpolled source's health record goes STALE, so the
        // beat would eventually alarm about sources nobody asked it to watch, every single day. A
        // health line that always reads "4 broken" trains its reader to skip the health line.
        $root = $this->tempRoot();
        $this->enableASecondSource($root);

        // Seeded UNSCOPED, so the excluded source has a healthy record in the store before the
        // scoped run starts. That is the real sequence — an operator adds `--source` to a
        // deployment that was already running — and it is what makes the assertion sharp in BOTH
        // directions: counting configured sources then yields a nonsensical "2/1", where an
        // excluded-but-unhealthy source would have coincidentally produced the correct "1/1".
        // The first version of this test seeded scoped and could not tell the two apart; the
        // mutation in `tests/sabotage-check.sh` stayed green and said so.
        $this->scoutIn($root, ['run', '--once', '--seed']);
        $r = $this->scoutIn($root, ['run', '--watch', '--source=fixture_demo']);

        self::assertStringContainsString('1/1 source(s) en bon état', $r['out']);
        self::assertStringNotContainsString('2/1 source(s)', $r['out'], 'an excluded source is not counted as healthy either');
        self::assertStringNotContainsString('1/2 source(s)', $r['out'], 'an excluded source is not a fault');
    }

    /**
     * THE STARTUP BEAT IS THE ONE HEALTH READ NO PASS PRECEDES (step 8e-2).
     *
     * Every other read of a source's health happens after a pass has recorded a run at the same
     * instant, so a reader that judged "now" as the last run's time would still look right there. The
     * startup beat fires BEFORE the first pass ("no pass has run yet in this process"), so it is the
     * one read where a missing or wrong clock shows: a watcher restarted after eight dark days must
     * say its source is STALE, not count it healthy on the strength of a week-old run. That is the
     * case `STALE` exists for — the schedule itself having stopped — and since 8e-2 the clock is
     * required by `SourceHealthService::of()` rather than optional on each source.
     *
     * The counterweight is `testAnUnscopedWatcherSaysNothingAboutScope`: seeded at the same instant
     * the watch starts, the same beat reads `1/1`.
     */
    public function testTheStartupBeatCountsASourceStaleAfterEightDarkDays(): void
    {
        $root = $this->tempRoot();

        $this->scoutIn($root, ['run', '--once', '--seed'], now: '2026-08-14T12:00:00+02:00');
        $r = $this->scoutIn($root, ['run', '--watch']);

        self::assertStringContainsString('0/1 source(s) en bon état', $r['out']);
    }

    public function testAScopedWatcherSaysThatItIsScoped(): void
    {
        // The other half, and the reason the fix above is not simply "count fewer sources". If the
        // beat silently reported 1/1, a deployment that carries a forgotten `--source` would look
        // perfect forever while four landlords went unwatched — and the beat is what reaches the
        // phone, whereas the startup banner is a log line nobody re-reads. So the scope has to
        // travel WITH the health figure, not merely be absent from it.
        $root = $this->tempRoot();
        $this->enableASecondSource($root);

        $this->scoutIn($root, ['run', '--once', '--seed', '--source=fixture_demo']);
        $r = $this->scoutIn($root, ['run', '--watch', '--source=fixture_demo']);

        self::assertStringContainsString('--source', $r['out'], 'the beat must disclose that it is scoped');
        self::assertStringContainsString('2 configurée', $r['out'], 'and say how many it is NOT watching');
    }

    public function testAnUnscopedWatcherSaysNothingAboutScope(): void
    {
        // The counterweight: the note must appear only when it is true. A permanent parenthetical
        // on every beat is noise, and noise on the liveness channel is the same defect as a false
        // alarm — one more line the reader learns to skim past.
        $r = $this->watch();

        self::assertStringContainsString('1/1 source(s) en bon état', $r['out']);
        self::assertStringNotContainsString('--source', $r['out']);
    }

    public function testTheINLOOPBeatIsExercisedAtAllAndAnUnwritableMarkerDoesNotSuppressIt(): void
    {
        // Two guarantees, and they are inseparable because the second is the only way to reach the
        // first with a FIXED clock.
        //
        // The in-loop beat — the one that fires on day two of an unattended run — is unreachable in
        // every other test here: the startup beat writes the marker at NOW, and every later check
        // asks `isDue(NOW, NOW)`. That is deliberate and it is what makes "exactly one beat"
        // assertable, but it means the loop's own call site is never executed. It was ALSO wrong:
        // the call gained an argument that lives outside the closure's `use` list, so the first
        // real due beat would have thrown a TypeError and killed the watcher 24 hours in. Nothing
        // in this file could see it.
        //
        // Making the marker unwritable is the way in, and it is a real scenario rather than a
        // contrivance: `beat()` writes it with `@file_put_contents`, deliberately swallowing
        // failure so a full or read-only volume cannot turn a liveness signal into a crash. A
        // directory where the file goes fails that write while leaving the database alone, and
        // `lastHeartbeat()` reads `is_file()`, so every check is due. The documented bias is one
        // beat too many, never one suppressed — so two beats here is the CORRECT behaviour, and
        // asserting it is what proves the loop's call site runs at all.
        $root = $this->tempRoot();
        mkdir($root . '/state/rent-heartbeat.txt', 0o775, true);

        $this->scoutIn($root, ['run', '--once', '--seed']);
        $r = $this->scoutIn($root, ['run', '--watch']);

        self::assertSame(
            2,
            substr_count($r['out'], 'toujours actif'),
            'startup beat, then the in-loop beat — an unwritable marker suppresses neither',
        );
        self::assertStringNotContainsString('TypeError', $r['out'] . $r['err']);
        self::assertDirectoryExists($root . '/state/rent-heartbeat.txt', 'the write failed, as the setup intends');
    }

    /**
     * A second enabled source in the same root, reading the same frozen payload.
     *
     * Deliberately a copy of the first rather than a new fixture: what is under test is the
     * arithmetic between "configured" and "watched", and a second source that behaved differently
     * would let a health difference explain a count difference.
     */
    private function enableASecondSource(string $root): void
    {
        $path = $root . '/config/rent/sources.json';
        $config = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($config);
        $config['sources']['fixture_other'] = $config['sources']['fixture_demo'];
        file_put_contents($path, json_encode($config, JSON_THROW_ON_ERROR));
    }

    public function testADoctorRefusalDoesNotWriteTheNote(): void
    {
        // Scoped to `run`. A doctor refusal is read by whoever typed it, in the terminal they typed
        // it in — recording it would make the next watch start report noise they already acted on.
        $root = $this->tempRoot(['communes' => 'not-a-list']);

        $this->scoutIn($root, ['doctor']);

        self::assertFileDoesNotExist($root . '/state/rent-last-refusal.txt');
    }

    public function testABeatAfterAFailingPassDoesNotReadLikeAHealthyOne(): void
    {
        // TWO REVIEW LENSES FOUND THIS INDEPENDENTLY, which is the strongest signal the panel gives.
        //
        // The sequence: the beat used to sit inside the pass's try, so a throwing pass emitted NO
        // beat — and Q27's own banner says silence past the interval IS the signal. Moving it to a
        // `finally` fixed the silence and created something worse: `++$passes` stays inside the try,
        // so `$passes` was still 0 and rendered as "démarrage de la surveillance"; `$matches` is
        // passed 0; and the health figure reads the run log, which `Pipeline`'s per-source loop had
        // already committed `ok = 1` to. The result was byte-identical to a healthy startup beat.
        // An operator whose watcher had thrown every pass for a week would get a daily push saying
        // it had just started and every source was fine.
        //
        // A SQLITE TRIGGER is how the pass is made to throw, and it is the realistic shape rather
        // than a contrivance: store writes in `Pipeline`'s cluster loop sit OUTSIDE the per-source
        // try/catch, and a write failing at runtime — SQLITE_FULL or SQLITE_IOERR on Q8's mounted
        // volume — is an event, not a state. `Store::open()` still succeeds, because migration is
        // idempotent and inserts nothing.
        $root = $this->tempRoot();
        $db = $root . '/state/rent-watch.sqlite3';

        $this->scoutIn($root, ['run', '--once', '--seed']);

        $pdo = new \PDO('sqlite:' . $db);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        // BOTH verbs. `Store::record()` INSERTs a listing it has not seen and UPDATEs one it has,
        // and the seed pass above means every listing is already on record — so an INSERT-only
        // trigger never fires and the pass succeeds, which is what a first draft of this test
        // asserted against.
        $pdo->exec(
            "CREATE TRIGGER boom_i BEFORE INSERT ON listings
             BEGIN SELECT RAISE(ABORT, 'disk I/O error'); END;",
        );
        $pdo->exec(
            "CREATE TRIGGER boom_u BEFORE UPDATE ON listings
             BEGIN SELECT RAISE(ABORT, 'disk I/O error'); END;",
        );
        unset($pdo);

        // The marker is made unwritable so every check is due — the documented technique, since a
        // fixed clock makes the in-loop beat otherwise unreachable.
        mkdir($root . '/state/rent-heartbeat.txt', 0o775, true);

        $r = $this->scoutIn($root, ['run', '--watch']);

        self::assertStringContainsString('toujours actif', $r['out'], 'the beat must still go out — silence is what the finally fixed');
        self::assertStringContainsString(
            'EN ÉCHEC',
            $r['out'],
            'and it must SAY the pass failed — a beat identical to a healthy one is worse than no beat',
        );
        // The STARTUP beat legitimately says "démarrage" — no pass has run at that point, and a cold
        // start is exactly what it is. What must not say it is the beat AFTER the failure, so the
        // assertion is scoped to the tail rather than the whole run.
        $afterFailure = substr($r['out'], (int) strpos($r['out'], 'EN ÉCHEC'));

        self::assertStringNotContainsString(
            'démarrage de la surveillance',
            $afterFailure,
            'starting is not what a watcher losing every pass is doing',
        );
        self::assertStringContainsString(
            'aucune passe terminée',
            $afterFailure,
            'and it says so plainly rather than reporting a count that would read as progress',
        );
    }

    public function testTheBeatIsEmittedFromAFinallySoAThrowingPassCannotSkipIt(): void
    {
        // THE CLAIM THIS PINS was in a comment and was false: the beat was said to be "outside its
        // try/catch by construction", and it sat in the same closure as the pass, which `WatchLoop`
        // wraps in its own `try`. Any throw from `onePass` skipped `++$passes` AND the beat. A
        // review panel found it on 2026-08-24 beside a defect that made it reachable — one
        // badly-encoded listing was aborting whole passes — so a watcher losing every pass would
        // ALSO have gone silent, which is the exact state the beat exists to distinguish from a
        // quiet market. The comment was right about the common case (all sources failing is caught
        // per source inside `Pipeline` and never reaches the loop), which is why nobody re-checked
        // the mechanism.
        //
        // **This asserts the SOURCE, not the behaviour, and that is a stated compromise rather than
        // a preference.** A behavioural test needs a pass that throws, and every path that could
        // throw out of `onePass` was closed in the same session that found this: malformed text in
        // the snapshot encoder, in `excludedBy()`, in `communeKey()` (via both `rankOf()` and
        // `Dedup`). A read-only database — the one remaining real scenario — fails at
        // `Store::open()`, before the loop exists, and is already covered by its own test. So the
        // guarantee is real, reachable only through a future regression, and untestable by
        // execution today. Asserting the structure keeps it from being silently undone; the
        // sabotage ledger carries the matching case.
        $source = file_get_contents(dirname(__DIR__, 4) . '/src/php/Rent/Cli/RentScout.php');
        self::assertIsString($source);

        $watchLoop = strstr($source, 'new WatchLoop(');
        self::assertIsString($watchLoop, 'the watch loop construction moved — this check must follow it');
        $closure = substr($watchLoop, 0, strpos($watchLoop, 'pacer: $pacer,') ?: strlen($watchLoop));

        self::assertMatchesRegularExpression(
            '~\}\s*finally\s*\{(?:[^}]|\}(?!\s*\}))*?isDue\(~s',
            $closure,
            'the heartbeat must be emitted from a `finally`, or a throwing pass silences the one signal that says the watcher is alive',
        );
    }

    /**
     * The beat's OWN failure must not mask the pass's.
     *
     * *"A liveness signal that can replace the diagnosis is worse than one that is late."* The beat
     * runs in the pass's `finally`, so an exception raised there propagates INSTEAD of the pass's —
     * `WatchLoop::onError` would then report the beat's cause as the pass's, on the one channel this
     * repo says can be believed. The `catch` is what prevents it, and round 7 showed nothing pinned
     * it: a reviewer turned the catch into `throw $beatFailure;` — the exact stated failure — and
     * the full suite stayed green. This is the third defect in the same six lines across rounds 2–4
     * (silence, then false-healthy, then the wrong number); the catch added to end that series was
     * the one part nothing checked.
     *
     * **Structural, and the compromise is stated** — the same trade this file already makes for
     * `testTheBeatIsEmittedFromAFinallySoAThrowingPassCannotSkipIt`, for the same reason. A
     * behavioural test needs the pass AND the beat to fail with DISTINGUISHABLE causes in one
     * process, and every clean trigger collides: the beat's two throwing surfaces are
     * `RunStore::health()` (reads `source_runs`, which `Pipeline::recordRun()` writes, so breaking it
     * fails the pass first with the same message) and `sourceNames()` (re-reads
     * `config/rent/sources.json`, which startup already read, so a test cannot corrupt it after the loop
     * begins). Asserting the structure keeps the guarantee from being silently undone.
     *
     * It asserts the catch BODY, not merely that a catch exists: the reviewer's mutation kept the
     * `catch (\Throwable $beatFailure)` line intact and changed what is inside it.
     */
    public function testTheBeatsOwnFailureIsCaughtRatherThanReplacingThePasssDiagnosis(): void
    {
        $source = file_get_contents(dirname(__DIR__, 4) . '/src/php/Rent/Cli/RentScout.php');
        self::assertIsString($source);

        $watchLoop = strstr($source, 'new WatchLoop(');
        self::assertIsString($watchLoop, 'the watch loop construction moved — this check must follow it');
        $closure = substr($watchLoop, 0, strpos($watchLoop, 'pacer: $pacer,') ?: strlen($watchLoop));

        $catchAt = strpos($closure, 'catch (\Throwable $beatFailure)');
        self::assertIsInt(
            $catchAt,
            'the beat must be attempted inside its own try/catch, or its failure replaces the pass\'s',
        );

        $body = substr($closure, $catchAt);
        $body = substr($body, 0, strpos($body, "\n                    }") ?: strlen($body));

        self::assertStringContainsString(
            'battement de cœur non émis',
            $body,
            'the beat failure has to be REPORTED — swallowing it silently would trade one hidden '
            . 'failure for another',
        );
        self::assertStringNotContainsString(
            'throw',
            $body,
            'the catch must not rethrow: that is the masking this guarantee exists to prevent, and '
            . 'it is exactly the mutation a round-7 reviewer made with the suite staying green',
        );
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

    /** @return array{code: int, out: string, err: string} */
    private function watch(?DeliveringChannel $delivering = null): array
    {
        return $this->watchIn($this->tempRoot(), $delivering);
    }

    /** @return array{code: int, out: string, err: string} */
    private function watchIn(string $root, ?DeliveringChannel $delivering = null): array
    {
        // Seeded first, because `run` refuses to notify on an empty seen-set (Q36) and would never
        // reach the loop.
        $this->scoutIn($root, ['run', '--once', '--seed'], null, $delivering);

        return $this->scoutIn($root, ['run', '--watch'], null, $delivering);
    }

    /**
     * @param list<string> $argv
     *
     * @return array{code: int, out: string, err: string}
     */
    private function scoutIn(string $root, array $argv, ?string $db = null, ?DeliveringChannel $delivering = null, ?string $now = null): array
    {
        $out = fopen('php://memory', 'r+');
        $err = fopen('php://memory', 'r+');
        self::assertIsResource($out);
        self::assertIsResource($err);

        // The override exists because this helper used to set the path unconditionally, which
        // silently discarded the one a test had just chosen — the unwritable-store test then
        // exercised a perfectly good database and failed for a reason unrelated to its subject.
        putenv('RENT_SCOUT_DB=' . ($db ?? $root . '/state/rent-watch.sqlite3'));

        $code = (new RentScout($root, $out, $err, $now ?? self::NOW, null, self::compose($out, $delivering)))->run($argv);
        rewind($out);
        rewind($err);

        return ['code' => $code, 'out' => (string) stream_get_contents($out), 'err' => (string) stream_get_contents($err)];
    }

    /** @param array<string,mixed> $criteria */
    private function tempRoot(array $criteria = []): string
    {
        $root = sys_get_temp_dir() . '/rentwatch-hb-' . bin2hex(random_bytes(8));
        mkdir($root . '/config/rent', 0o775, true);
        mkdir($root . '/state', 0o775, true);
        mkdir($root . '/tests/fixtures/rent/fixture_demo', 0o775, true);
        $this->roots[] = $root;

        copy(
            dirname(__DIR__, 4) . '/tests/fixtures/rent/fixture_demo/search.json',
            $root . '/tests/fixtures/rent/fixture_demo/search.json',
        );

        file_put_contents($root . '/config/rent/criteria.json', json_encode($criteria + [
            'communes' => ['Sartrouville'],
            'postcode_prefixes' => ['78'],
            'min_rooms' => 4,
            'max_rent_cc' => 1800,
        ], JSON_THROW_ON_ERROR));

        file_put_contents($root . '/config/rent/sources.json', json_encode([
            'sources' => [
                'fixture_demo' => [
                    'enabled' => true,
                    'family' => 'institutional',
                    'type' => 'fixture',
                    'mixed_tenure' => true,
                    'fixture' => 'tests/fixtures/rent/fixture_demo/search.json',
                    // Mirrors the shipped block. A hand-written map here drifts from the payload the
                    // moment either changes, and a source that maps nothing seeds nothing — which
                    // makes every heartbeat assertion below fail for a reason that has nothing to do
                    // with heartbeats.
                    'items_path' => 'results.items',
                    'map' => [
                        'ref' => 'id',
                        'title' => 'title',
                        'commune' => ['city', 'address.city'],
                        'cp' => ['zipCode', 'address.postalCode'],
                        'rent' => ['rent.total', 'price'],
                        'charges_included' => true,
                        'surface' => 'surface',
                        'rooms' => ['rooms', 'nbRooms'],
                        'description' => 'description',
                        'tenure_field' => 'financement',
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        return $root;
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);

            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }

        @rmdir($path);
    }

}
