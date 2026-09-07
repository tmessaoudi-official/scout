<?php

declare(strict_types=1);

namespace Scout\Tests\Car\Cli;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Cli\Scout;
use Scout\Car\Cli\CarScout;
use Scout\Core\Notify\Notification;
use Scout\Core\Notify\NotificationKind;
use Scout\Core\Notify\Notifier;
use Scout\Tests\Car\CarRecordingChannel;

/**
 * `scout --domain=car …` against the ParuVendu fixtures and a throwaway database. Every run is
 * pinned to `--source=paruvendu`: Autohero polls a live site, and the suite runs SCOUT_OFFLINE=1.
 */
#[CoversClass(CarScout::class)]
final class CarScoutTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../../..';
    private string $db;

    protected function setUp(): void
    {
        $this->db = sys_get_temp_dir() . '/scout-car-cli-' . bin2hex(random_bytes(6)) . '.sqlite3';
        putenv('CAR_SCOUT_DB=' . $this->db);
        putenv('MAILBOX_DIR=' . self::ROOT . '/tests/fixtures/car/paruvendu');
    }

    protected function tearDown(): void
    {
        putenv('CAR_SCOUT_DB');
        putenv('MAILBOX_DIR');
        foreach (glob($this->db . '*') ?: [] as $f) {
            @unlink($f);
        }
        @unlink(\dirname($this->db) . '/car-heartbeat.txt');
        @unlink(\dirname($this->db) . '/car-last-refusal.txt');
        @unlink(\dirname($this->db) . '/car-rollup.txt');
    }

    public function testTheDomainFlagIsDispatchedFromTheGenericEntryPoint(): void
    {
        $r = $this->scout(['--domain=car', 'help']);

        self::assertSame(0, $r['code']);
        self::assertStringContainsString('scout --domain=car doctor', $r['out']);
        self::assertStringNotContainsString('digest', $r['out'], 'no digest verb in the car domain');
    }

    public function testRunRefusesOnAnEmptyVehicleSeenSetUntilSeeded(): void
    {
        $refused = $this->scout(['--domain=car', 'run', '--once', '--source=paruvendu']);
        self::assertSame(2, $refused['code']);
        self::assertStringContainsString('--seed', $refused['err'], 'Q36, the car analog');

        $seed = $this->scout(['--domain=car', 'run', '--once', '--seed', '--source=paruvendu']);
        self::assertSame(0, $seed['code'], $seed['err']);
        self::assertStringContainsString('mode --seed', $seed['out']);

        $channel = new CarRecordingChannel();
        $again = $this->scout(['--domain=car', 'run', '--once', '--source=paruvendu'], $channel);
        self::assertSame(0, $again['code'], $again['err']);
        self::assertSame([], array_filter($channel->sent, static fn ($n) => $n->kind === NotificationKind::MATCH), 'seeded cards are never pushed');
        self::assertStringContainsString('3 annonce(s) analysées', $again['out']);
    }

    public function testAnUnseededRunPushesEveryMatchOnceWithTheSourceLeadingTheTitle(): void
    {
        // Seed with an EMPTY folder, then run against the real one: three novel cards.
        $empty = sys_get_temp_dir() . '/scout-car-empty-' . bin2hex(random_bytes(4));
        mkdir($empty);
        putenv('MAILBOX_DIR=' . $empty);
        // A seed over nothing leaves the seen-set empty, so record one throwaway car by hand.
        \Scout\Car\VehicleStore::open($this->db)->record(new \Scout\Car\VehicleListing(sourceName: 'paruvendu', externalId: 'seed'), '2026-08-01T00:00:00Z');
        putenv('MAILBOX_DIR=' . self::ROOT . '/tests/fixtures/car/paruvendu');

        $channel = new CarRecordingChannel();
        $r = $this->scout(['--domain=car', 'run', '--once', '-v', '--source=paruvendu'], $channel);
        $r2 = $this->scout(['--domain=car', 'run', '--once', '--source=paruvendu'], $channel);

        self::assertSame(0, $r['code'], $r['err']);
        $matches = array_values(array_filter($channel->sent, static fn ($n) => $n->kind === NotificationKind::MATCH));
        // A5 (2026-09-05): the shipped gate is 73 and the three cards score 80 / 73 / 46 under the
        // shipped criteria — two pushed, none twice, and the 2008 (46) QUEUED for the rollup.
        self::assertCount(2, $matches, 'two cards at or over the gate, two pushes, none twice');
        self::assertStringStartsWith('paruvendu · ', $matches[0]->title);
        self::assertStringContainsString('Renault Austral 2023 · 26 000 km · 21 000 €', $matches[0]->title);
        self::assertStringContainsString('1 correspondance(s) sous le seuil', $r['out'], 'the pass says what it held back');
        self::assertSame(1, \Scout\Car\VehicleStore::open($this->db)->pendingRollupCount());
        rmdir($empty);
    }

    // ── Row 6 / A5 (2026-09-05): the rollup verb and its daily floor ──

    public function testRollupAnnouncesTheQueuedMatchesOnceUnderItsOwnKindAndMarksThemOnDelivery(): void
    {
        $this->queueTheWeakParuVenduCard();
        $channel = new CarRecordingChannel();

        $r = $this->scout(['--domain=car', 'rollup'], $channel);

        self::assertSame(0, $r['code'], $r['out'] . $r['err']);
        $rollups = array_values(array_filter($channel->sent, static fn ($n) => $n->kind === NotificationKind::ROLLUP));
        self::assertCount(1, $rollups, 'one rollup, not a push per car');
        self::assertStringContainsString('score bas', $rollups[0]->title);
        self::assertStringContainsString('Peugeot 2008', implode("\n", $rollups[0]->reasons));
        self::assertSame([], array_filter($channel->sent, static fn ($n) => $n->kind === NotificationKind::MATCH), 'never a MATCH push');
        self::assertSame(0, \Scout\Car\VehicleStore::open($this->db)->pendingRollupCount(), 'marked on delivery');

        $again = $this->scout(['--domain=car', 'rollup'], $channel);
        self::assertStringContainsString('Aucune correspondance en attente', $again['out'], 'drained once');
    }

    public function testRollupDryRunAnnouncesNothingAndMarksNothing(): void
    {
        $this->queueTheWeakParuVenduCard();
        $channel = new CarRecordingChannel();

        $r = $this->scout(['--domain=car', 'rollup', '--dry-run'], $channel);

        self::assertSame(0, $r['code'], $r['out'] . $r['err']);
        self::assertStringContainsString('Peugeot 2008', $r['out'], 'printed');
        self::assertSame([], $channel->sent, 'sent nowhere');
        self::assertSame(1, \Scout\Car\VehicleStore::open($this->db)->pendingRollupCount(), 'still queued');
    }

    /** The floor at startup, like the rent digest's: a queue left when the container stopped is drained before the first pass. */
    public function testTheRollupFloorDrainsTheQueueAtStartupUnderWatchAndWritesItsMarkerOnDelivery(): void
    {
        $this->queueTheWeakParuVenduCard();
        $channel = new CarRecordingChannel();
        putenv('SCOUT_MAX_PASSES=1');
        try {
            // 20:00 local with rollup_hour 8 and no marker: the window is due.
            $r = $this->scout(['--domain=car', 'run', '--watch', '--source=paruvendu'], $channel);
        } finally {
            putenv('SCOUT_MAX_PASSES');
        }

        self::assertSame(0, $r['code'], $r['out'] . $r['err']);
        self::assertCount(1, array_filter($channel->sent, static fn ($n) => $n->kind === NotificationKind::ROLLUP));
        self::assertFileExists(\dirname($this->db) . '/car-rollup.txt', 'the marker is written after delivery');
        self::assertSame(0, \Scout\Car\VehicleStore::open($this->db)->pendingRollupCount());
    }

    /** The marker is written AFTER the channel confirms: a rollup the channel refused leaves the window open and the queue intact. */
    public function testTheRollupFloorWritesNoMarkerAndMarksNothingWhenTheChannelRefuses(): void
    {
        $this->queueTheWeakParuVenduCard();
        $channel = new CarRecordingChannel();
        $channel->down = true;
        putenv('SCOUT_MAX_PASSES=1');
        try {
            $r = $this->scout(['--domain=car', 'run', '--watch', '--source=paruvendu'], $channel);
        } finally {
            putenv('SCOUT_MAX_PASSES');
        }

        self::assertSame([], $channel->sent);
        self::assertFileDoesNotExist(\dirname($this->db) . '/car-rollup.txt', 'no delivery, no marker — the window stays open');
        self::assertSame(1, \Scout\Car\VehicleStore::open($this->db)->pendingRollupCount(), 'still queued for the next window');
        self::assertStringContainsString('non délivré', $r['out'] . $r['err']);
    }

    /**
     * The held-back line names the drain THIS run mode has. The daily floor lives inside the watch
     * loop, so under `--once` the queue empties when the operator's cron runs `rollup` and never
     * otherwise — promising a daily rollup there reads as reassurance while the queue grows.
     */
    public function testAOncePassNamesTheVerbRatherThanAFloorItDoesNotHave(): void
    {
        \Scout\Car\VehicleStore::open($this->db)->record(new \Scout\Car\VehicleListing(sourceName: 'paruvendu', externalId: 'seed'), '2026-08-01T00:00:00Z');

        $r = $this->scout(['--domain=car', 'run', '--once', '--source=paruvendu'], new CarRecordingChannel());

        self::assertSame(0, $r['code'], $r['err']);
        self::assertStringContainsString('sous le seuil de notification individuelle', $r['out']);
        self::assertStringContainsString('scout --domain=car rollup', $r['out'], 'the --once drain is the verb');
        self::assertStringContainsString('ne tourne que sous --watch', $r['out'], 'and the floor\'s scope is stated');
    }

    // ── C2 round 6 (2026-09-05): a queued car AT the line never went out, so it is re-pushed ─────

    /**
     * The queue cannot tell a car HELD BACK by the gate from one whose push FAILED — both leave a
     * `MATCH` outcome with no `notified_at`. A car at or over the line is therefore re-pushed as
     * the individual match it is, never filed under a heading that says it scored badly.
     */
    public function testTheRollupVerbRePushesAQueuedMatchAtOrOverTheGate(): void
    {
        $key = $this->queueACarOverTheGate();
        $channel = new CarRecordingChannel();

        $r = $this->scout(['--domain=car', 'rollup'], $channel);

        self::assertSame(0, $r['code'], $r['out'] . $r['err']);
        self::assertStringContainsString('réémise(s) individuellement', $r['out']);
        self::assertCount(1, array_filter($channel->sent, static fn ($n) => $n->kind === NotificationKind::MATCH), 'pushed as a match');
        self::assertSame([], array_filter($channel->sent, static fn ($n) => $n->kind === NotificationKind::ROLLUP), 'and no rollup beside it');
        self::assertTrue(\Scout\Car\VehicleStore::open($this->db)->wasNotified($key), 'marked on delivery');
    }

    /** The floor is the only automatic drain under `--watch`, so it re-pushes exactly as the verb does. */
    public function testTheRollupFloorRePushesAQueuedMatchAtOrOverTheGate(): void
    {
        $key = $this->queueACarOverTheGate();
        $channel = new CarRecordingChannel();
        putenv('SCOUT_MAX_PASSES=1');
        try {
            $r = $this->scout(['--domain=car', 'run', '--watch', '--source=paruvendu'], $channel);
        } finally {
            putenv('SCOUT_MAX_PASSES');
        }

        self::assertSame(0, $r['code'], $r['out'] . $r['err']);
        self::assertStringContainsString('réémise(s) individuellement', $r['out']);
        self::assertCount(1, array_filter($channel->sent, static fn ($n) => $n->kind === NotificationKind::MATCH && str_contains($n->title, 'Mercedes')));
        self::assertTrue(\Scout\Car\VehicleStore::open($this->db)->wasNotified($key));
    }

    /** A refused retry is left EXACTLY where it was: nothing marked, offered again next drain. */
    public function testARefusedRetryIsLeftQueuedAndSaidOutLoud(): void
    {
        $key = $this->queueACarOverTheGate();
        $channel = new CarRecordingChannel();
        $channel->down = true;

        $r = $this->scout(['--domain=car', 'rollup'], $channel);

        self::assertStringContainsString('réémission non délivrée', $r['out'] . $r['err']);
        self::assertFalse(\Scout\Car\VehicleStore::open($this->db)->wasNotified($key), 'nothing marked on a refused send');
        self::assertSame(1, \Scout\Car\VehicleStore::open($this->db)->pendingRollupCount(), 'still queued');
    }

    /**
     * C2 ROUND 8 P1, THE CAR HALF — the remainder line, inverted.
     *
     * Round 7 fixed the over-report (counting only `$entries` left every retry reported as still
     * pending) and introduced the under-report in the same change: the arithmetic subtracted every
     * retry whether or not the channel took it, while `pushRetries()` leaves a refused one queued
     * by design. With no `push_min_score` — where every queued row is a retry — a drain against a
     * dead channel claimed the backlog was empty when nothing had moved.
     *
     * The counterweight is `testARefusedRetryIsLeftQueuedAndSaidOutLoud` plus the delivered path
     * above: a retry the channel TOOK must claim no remainder.
     */
    public function testARefusedCarRetryIsStillReportedAsARemainder(): void
    {
        $this->queueACarOverTheGate();
        $channel = new CarRecordingChannel();
        $channel->down = true;

        $r = $this->scout(['--domain=car', 'rollup'], $channel);

        self::assertSame(1, \Scout\Car\VehicleStore::open($this->db)->pendingRollupCount(), 'premise: it did not drain');
        self::assertStringContainsString('1 autre(s) en attente', $r['out'] . $r['err']);
    }

    /**
     * A COMPUTED `REJECT` IS AN ANSWER, AND THE DRAIN USED TO DISCARD IT.
     *
     * The re-judge kept `$verdict = null` on a rejection and fell back to the STORED score, so a
     * queued car that today's classifier calls `accidenté` — the non-overridable excluded vehicle
     * set — was announced as an individual MATCH carrying the reason *« réémission — score
     * conservé, instantané absent »*, which was false: the snapshot was present and decoded.
     *
     * Reachable with no forged state: the excluded set was WIDENED in code on 2026-08-31
     * (`opposition`, the `[ _-]` multi-word fix), and `max_price_eur` / `brand_avoid` have both
     * changed since — each flips previously-MATCH rows. The rent drain refuses the identical case;
     * this is the other of the two symmetric surfaces.
     */
    public function testAQueuedCarTodaysRulesRejectIsLeftWaitingAndNeverAnnounced(): void
    {
        $key = $this->queueACarTheRulesNowReject();
        $channel = new CarRecordingChannel();

        $r = $this->scout(['--domain=car', 'rollup'], $channel);

        self::assertSame([], $channel->sent, 'neither pushed nor rolled up');
        self::assertStringContainsString('re-jugée REJECT', $r['out'] . $r['err'], 'and the refusal is said out loud');
        self::assertFalse(\Scout\Car\VehicleStore::open($this->db)->wasNotified($key), 'nothing marked');
        self::assertSame(1, \Scout\Car\VehicleStore::open($this->db)->pendingRollupCount(), 'still queued for a human to look at');
    }

    /** The daily floor refuses it too — one implementation, both call sites. */
    public function testTheFloorAlsoRefusesACarTodaysRulesReject(): void
    {
        $key = $this->queueACarTheRulesNowReject();
        $channel = new CarRecordingChannel();
        putenv('SCOUT_MAX_PASSES=1');
        try {
            $r = $this->scout(['--domain=car', 'run', '--watch', '--source=paruvendu'], $channel);
        } finally {
            putenv('SCOUT_MAX_PASSES');
        }

        self::assertSame([], array_filter($channel->sent, static fn ($n) => str_contains($n->title, 'accident')));
        self::assertStringContainsString('re-jugée REJECT', $r['out'] . $r['err'], 'the floor voices it even though the batch ends empty');
        self::assertFalse(\Scout\Car\VehicleStore::open($this->db)->wasNotified($key));
    }

    /**
     * THE FLOOR is the deployed drain, and it kept the round-7 arithmetic in its guard.
     *
     * `testARefusedCarRetryIsStillReportedAsARemainder` drives the VERB. The floor inlined the same
     * clause with `$waiting > count($entries) + count($retries)` as its guard while its VALUE took
     * the round-8 `$drained` fix — and that guard is false on every uncapped drain, so any refused
     * retry silenced the clause under a summary reading `N véhicule(s) émis`. Found by all three
     * lenses of the C2 milestone panel; the verb-side test asserted around it.
     */
    public function testTheCarFloorAlsoReportsARefusedRetryAsARemainder(): void
    {
        $this->queueACarOverTheGate();
        $channel = new CarRecordingChannel();
        // The rollup mail must LAND and an individual retry must be refused: a wholly-down
        // channel short-circuits before the clause and would prove nothing.
        $channel->refuseKind = \Scout\Core\Notify\NotificationKind::MATCH;
        putenv('SCOUT_MAX_PASSES=1');
        try {
            $r = $this->scout(['--domain=car', 'run', '--watch', '--source=paruvendu'], $channel);
        } finally {
            putenv('SCOUT_MAX_PASSES');
        }

        // The floor runs a real pass, so it queues more rows than the one seeded — the premise is
        // that the refused retry is STILL queued, not that it is alone.
        self::assertGreaterThan(0, \Scout\Car\VehicleStore::open($this->db)->pendingRollupCount(), 'premise: it did not drain');
        self::assertStringContainsString('autre(s) en attente', $r['out'] . $r['err'], 'the floor must say the backlog did not empty');
    }

    /** And a drain that emptied the queue claims no remainder — the counterweight the line never had. */
    public function testACarDrainThatEmptiedTheQueueClaimsNoRemainder(): void
    {
        $this->queueACarOverTheGate();

        $r = $this->scout(['--domain=car', 'rollup'], new CarRecordingChannel());

        self::assertStringContainsString('réémise(s) individuellement', $r['out']);
        self::assertStringNotContainsString('autre(s) en attente', $r['out'], 'there is no suite');
    }

    /**
     * A queued MATCH whose snapshot decodes and whose facts today's rules REJECT: a car recorded
     * before the excluded set was widened, whose push failed or whose score sat under the gate.
     */
    private function queueACarTheRulesNowReject(): string
    {
        $store = \Scout\Car\VehicleStore::open($this->db);
        $car = new \Scout\Car\VehicleListing(
            sourceName: 'paruvendu',
            externalId: 'REJET-1',
            title: 'Renault Clio V accidente',
            priceEur: 9000,
            year: 2021,
            mileageKm: 40000,
        );
        $sighting = $store->record($car, '2026-08-01T00:00:00Z');
        $store->recordVerdict($sighting->dedupKey, \Scout\Car\VehicleVerdict::matched(90, ['jugée avant l\'élargissement de la liste'], false), $car);

        return $sighting->dedupKey;
    }

    /**
     * A queued MATCH whose STORED score clears the shipped gate and whose snapshot could not be
     * encoded — the production shape of a push that failed, and the arm that has to fall back to
     * the stored score because nothing can be re-judged.
     */
    private function queueACarOverTheGate(): string
    {
        $store = \Scout\Car\VehicleStore::open($this->db);
        $car = new \Scout\Car\VehicleListing(sourceName: 'paruvendu', externalId: 'HAUT-1', title: 'Mercedes Classe B 180 d', priceEur: 12000);
        $sighting = $store->record($car, '2026-08-01T00:00:00Z');
        $store->recordVerdict($sighting->dedupKey, \Scout\Car\VehicleVerdict::matched(90, ['jugée au-dessus du seuil'], false), $car);
        $pdo = new \PDO('sqlite:' . $this->db);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->prepare('UPDATE vehicle_listings SET snapshot_json = NULL WHERE dedup_key = :k')->execute(['k' => $sighting->dedupKey]);

        return $sighting->dedupKey;
    }

    /** One `run --once` over the ParuVendu fixtures under the shipped gate queues exactly the 46-point 2008. */
    private function queueTheWeakParuVenduCard(): void
    {
        \Scout\Car\VehicleStore::open($this->db)->record(new \Scout\Car\VehicleListing(sourceName: 'paruvendu', externalId: 'seed'), '2026-08-01T00:00:00Z');
        $r = $this->scout(['--domain=car', 'run', '--once', '--source=paruvendu'], new CarRecordingChannel());
        self::assertSame(0, $r['code'], $r['err']);
        self::assertSame(1, \Scout\Car\VehicleStore::open($this->db)->pendingRollupCount());
    }

    public function testDoctorReportsTheSourceAndTheSeenSet(): void
    {
        $r = $this->scout(['--domain=car', 'doctor', '--source=paruvendu']);

        self::assertStringContainsString('VIDE', $r['out'], 'an empty seen-set is named, with the seed command');
        self::assertStringContainsString('paruvendu', $r['out']);
        self::assertStringContainsString('car-watch', $r['out']);
        // The floor's SCOPE, not just its hour: it runs inside the watch loop, so a cron-driven
        // `--once` deployment has the verb and nothing else (C2 round 6, completeness lens).
        self::assertStringContainsString('sous --watch UNIQUEMENT', $r['out']);
    }

    /**
     * THE CAR DOCTOR WARNS WHEN A THRESHOLD SITS AT OR PAST THE IMAP WINDOW — which it never did.
     *
     * `IMAP_SINCE_DAYS` is SHARED between the two domains (`CarScout` says so in its own env
     * listing), and the check lived only in `RentScout`. So `config/car/sources.json` could ship —
     * and did ship — `leboncoin: feed_silent_days 7` against the default 7-day window, and nothing
     * said a word.
     *
     * The band is (threshold, IMAP_SINCE_DAYS]. At 7 against 7 it is EMPTY: a genuinely silent feed
     * returns no messages at all, the count falls to zero and the source reports `broken` — the
     * vague verdict — before the threshold can ever report `feed_silent`, the precise one. The
     * misconfiguration hides exactly the diagnosis it was set to sharpen.
     *
     * Found 2026-09-01 by the RENT doctor warning about a value copied FROM this side. Asserted
     * here on BOTH surfaces the rent side covers — the global env threshold and a per-source one —
     * because a check landing on one of two symmetric surfaces is what put this here.
     */
    public function testDoctorWarnsWhenTheFeedSilenceThresholdReachesTheImapWindow(): void
    {
        $before = getenv('CAR_FEED_SILENT_DAYS');
        putenv('CAR_FEED_SILENT_DAYS=9');

        try {
            $r = $this->scout(['--domain=car', 'doctor', '--source=paruvendu']);

            self::assertStringContainsString('CAR_FEED_SILENT_DAYS', $r['err'] . $r['out']);
            self::assertStringContainsString('IMAP_SINCE_DAYS', $r['err'] . $r['out']);
            self::assertStringContainsString('bande observable', $r['err'] . $r['out']);
        } finally {
            $before === false ? putenv('CAR_FEED_SILENT_DAYS') : putenv('CAR_FEED_SILENT_DAYS=' . $before);
        }
    }

    /** The counterweight: a threshold INSIDE the window is silent, or the warning is just noise. */
    public function testDoctorIsSilentWhenTheThresholdSitsInsideTheWindow(): void
    {
        $before = getenv('CAR_FEED_SILENT_DAYS');
        putenv('CAR_FEED_SILENT_DAYS=3');

        try {
            $r = $this->scout(['--domain=car', 'doctor', '--source=paruvendu']);

            self::assertStringNotContainsString('bande observable', $r['err'] . $r['out']);
        } finally {
            $before === false ? putenv('CAR_FEED_SILENT_DAYS') : putenv('CAR_FEED_SILENT_DAYS=' . $before);
        }
    }

    public function testDumpShowsTheFirstCardAndItsVerdict(): void
    {
        $r = $this->scout(['--domain=car', 'dump', 'paruvendu']);

        self::assertSame(0, $r['code'], $r['err']);
        self::assertStringContainsString('priceEur     21000', $r['out']);
        self::assertStringContainsString('verdict      MATCH', $r['out']);
    }

    public function testAnUnknownSourceIsAWarningNotASilentEmptyRun(): void
    {
        $r = $this->scout(['--domain=car', 'doctor', '--source=nope']);

        self::assertStringContainsString('source inconnue', $r['err']);
    }

    public function testTheHeartbeatSeesAVerdictOnlyTheClockCanDerive(): void
    {
        // The beat must read health WITH the clock. `FEED_SILENT` and `STALE` are underivable
        // without one, so a portal that stopped sending — or a watcher that did — counted as
        // healthy on the one channel whose job is to say otherwise: the rent side's 2026-08-29
        // defect, unfixed on the car twin until a review panel proved it at the store on
        // 2026-08-30. Pinned through `STALE` rather than `FEED_SILENT` because the offline
        // `FileMailbox` deliberately reports no message date (an unknown date yields no verdict);
        // in production the IMAP mailbox does, and the same clock carries both. Seeded on one day,
        // watched a month later (outside the 7-day rolling window): the startup beat (cold start, no marker) must name the source.
        putenv('SCOUT_MAX_PASSES=1');
        try {
            $seed = $this->scout(['--domain=car', 'run', '--once', '--seed', '--source=paruvendu']);
            self::assertSame(0, $seed['code'], $seed['err']);

            $r = $this->scout(['--domain=car', 'run', '--watch', '--source=paruvendu'], null, '2026-10-01T20:00:00+02:00');

            self::assertStringContainsString('[HEARTBEAT]', $r['out'], 'a cold start beats');
            self::assertStringContainsString('en alerte : paruvendu (stale)', $r['out'], 'the beat sees what only the clock can derive');
            self::assertStringNotContainsString('toutes les sources sont OK', substr($r['out'], 0, (int) strpos($r['out'], 'annonce(s) analysées')), 'the STARTUP beat, before any pass, must not call a week-old run healthy');
        } finally {
            putenv('SCOUT_MAX_PASSES');
        }
    }
    /**
     * A THROWING PASS MUST STILL BEAT (round-4 panel, 2026-08-31).
     *
     * The beat sat after the work INSIDE the pass closure, and `WatchLoop` wraps that closure in its
     * own `try` — so any throw from `runOnce()` or `report()` skipped it. A car watcher losing every
     * pass then emitted nothing to any channel, which is exactly the state the beat exists to
     * distinguish from a quiet market. The rent side carries the same `finally` and its comment
     * records a panel finding the identical defect there on 2026-08-24; the car twin claimed to
     * mirror it and did not.
     *
     * A read-only database is the lever: it throws INSIDE the pass, past `VehiclePipeline`'s own
     * per-source catch, which is precisely the class of failure that was silencing the watcher.
     *
     * TWO beats are asserted, not one, and that is the whole design of this test. A cold start beats
     * BEFORE the loop, so "a beat appeared" is satisfied by the startup beat alone and stays green
     * with the `finally` deleted — a first version asserted exactly that and passed against a landed
     * mutation. The marker is therefore made UNWRITABLE (a directory where the file goes): `beat()`
     * writes it with `@file_put_contents` so a full volume cannot crash a liveness signal, and
     * `lastHeartbeat()` reads `is_file()`, so every check is due and the in-loop beat becomes
     * reachable. Two is then the CORRECT count, per the documented one-beat-too-many bias.
     */
    public function testAPassThatThrowsStillEmitsTheHeartbeat(): void
    {
        putenv('SCOUT_MAX_PASSES=1');
        $marker = \dirname($this->db) . '/car-heartbeat.txt';
        try {
            $seed = $this->scout(['--domain=car', 'run', '--once', '--seed', '--source=paruvendu']);
            self::assertSame(0, $seed['code'], $seed['err']);

            @unlink($marker);
            self::assertTrue(mkdir($marker), 'the marker must be genuinely unwritable, or the in-loop beat is unreachable');
            self::assertTrue(chmod($this->db, 0o444), 'the store must actually become read-only, or this test proves nothing');

            $r = $this->scout(['--domain=car', 'run', '--watch', '--source=paruvendu']);
            chmod($this->db, 0o644);

            self::assertStringContainsString('passe échouée', $r['err'], 'the pass genuinely threw');
            self::assertSame(
                2,
                substr_count($r['out'], '[HEARTBEAT]'),
                'the startup beat AND the in-loop one — a pass that throws must not take the liveness signal with it',
            );

            // AND IT MUST SAY SO (round-5 panel, 2026-08-31). Emitting the beat was only half the
            // fix: without the failed-pass count it rendered `0 exécution(s)` beside `toutes les
            // sources sont OK` while every pass was dying — an affirmative all-clear, which is
            // strictly WORSE than the silence it replaced, because silence past the interval is
            // itself the documented signal.
            self::assertStringContainsString('1 passe(s) EN ÉCHEC', $r['out'], 'the beat names the failure instead of reporting an all-clear');
        } finally {
            @chmod($this->db, 0o644);
            @rmdir($marker);
            putenv('SCOUT_MAX_PASSES');
        }
    }

    /**
     * `doctor` REPORTS A PENDING REFUSAL WITHOUT CONSUMING IT — the rent side's round-4 fix, which
     * the car side did not get (round-5 panel, 2026-08-31). `car-last-refusal.txt` is written by
     * `failRun()` on every refused `--once` and was read at exactly one place, inside the beat,
     * which exists only under `--watch`. On a cron-driven `--once` deployment Q27's second half was
     * therefore dead: the note was written for ever and read by nothing.
     */
    public function testDoctorReportsAPendingRefusalWithoutConsumingIt(): void
    {
        $marker = \dirname($this->db) . '/car-last-refusal.txt';
        file_put_contents($marker, '2026-08-29T22:00:00+02:00 — canal ntfy sans CAR_NTFY_TOPIC');

        $r = $this->scout(['--domain=car', 'doctor', '--source=paruvendu']);

        self::assertStringContainsString('CAR_NTFY_TOPIC', $r['out'], 'doctor names the refusal');
        self::assertFileExists($marker, 'and leaves it for the beat — a diagnostic must not consume what a channel still owes');
    }

    /**
     * AND IT IS REPORTED BEFORE THE BOOTSTRAP THAT THE REFUSAL WOULD BLOCK. Placed after
     * `criteria()`, the line is reachable only while `doctor`'s own start-up succeeds — never for a
     * refusal whose cause still blocks it, which is the commonest kind there is.
     */
    public function testAPendingRefusalIsReportedEvenWhenTheConfigItselfIsUnusable(): void
    {
        // A root with no config/car at all: `criteria()` cannot load and the verb refuses. The note
        // goes in THAT root's state dir, because `stateFile()` resolves from the database path.
        $broken = sys_get_temp_dir() . '/scout-car-brokenroot-' . bin2hex(random_bytes(4));
        mkdir($broken . '/state', 0o775, true);
        file_put_contents($broken . '/state/car-last-refusal.txt', '2026-08-29T22:00:00+02:00 — configuration illisible');

        try {
            $out = fopen('php://memory', 'r+');
            $err = fopen('php://memory', 'r+');
            self::assertIsResource($out);
            self::assertIsResource($err);
            putenv('CAR_SCOUT_DB=' . $broken . '/state/car.sqlite3');
            $code = (new CarScout($broken, $out, $err))->run(['doctor']);
            rewind($out);

            self::assertNotSame(0, $code, 'the config is genuinely unusable');
            self::assertStringContainsString(
                'configuration illisible',
                (string) stream_get_contents($out),
                'and the refusal is still reported — it is read before the bootstrap that would block it',
            );
        } finally {
            @unlink($broken . '/state/car-last-refusal.txt');
            @rmdir($broken . '/state');
            @rmdir($broken);
            putenv('CAR_SCOUT_DB=' . $this->db);
        }
    }

    /**
     * AN IN-MEMORY DATABASE STILL PUTS ITS STATE FILES UNDER `<root>/state` (round-5 panel,
     * 2026-08-31). `dbPath()` passes `:memory:` through deliberately and `dirname(':memory:')` is
     * `.`, so without the branch every marker — heartbeat, digest, refusal — lands in whatever the
     * process cwd happens to be. The branch shipped in round 4 with NO coverage on either CLI:
     * reverting it left all 2 339 tests green.
     *
     * Exercised through behaviour rather than by reaching for the private method: the refusal note
     * is placed in `<root>/state`, and `doctor` can only report it if `stateFile()` looked there.
     */
    public function testStateFilesOfAnInMemoryDatabaseResolveUnderTheRoot(): void
    {
        $root = sys_get_temp_dir() . '/scout-car-memroot-' . bin2hex(random_bytes(4));
        mkdir($root . '/state', 0o775, true);
        mkdir($root . '/config/car', 0o775, true);
        copy(self::ROOT . '/config/car/criteria.json', $root . '/config/car/criteria.json');
        copy(self::ROOT . '/config/car/sources.json', $root . '/config/car/sources.json');
        file_put_contents($root . '/state/car-last-refusal.txt', '2026-08-29T22:00:00+02:00 — marqueur en mémoire');

        try {
            putenv('CAR_SCOUT_DB=:memory:');
            $out = fopen('php://memory', 'r+');
            $err = fopen('php://memory', 'r+');
            self::assertIsResource($out);
            self::assertIsResource($err);
            (new CarScout($root, $out, $err))->run(['doctor']);
            rewind($out);

            self::assertStringContainsString(
                'marqueur en mémoire',
                (string) stream_get_contents($out),
                'the marker was looked for under <root>/state, not in the process cwd',
            );
        } finally {
            putenv('CAR_SCOUT_DB=' . $this->db);
            @unlink($root . '/state/car-last-refusal.txt');
            @unlink($root . '/config/car/criteria.json');
            @unlink($root . '/config/car/sources.json');
            @rmdir($root . '/config/car');
            @rmdir($root . '/config');
            @rmdir($root . '/state');
            @rmdir($root);
        }
    }

    /**
     * THE CAR HALF OF THE FORCED `--once` BEAT, which shipped with no test at all (round-6 panel).
     *
     * `ccc8498` said "on both CLIs … the test asserts both halves" and its diffstat touched only
     * `RentScoutHeartbeatTest`. A reviewer replaced the whole car branch with `if (false)` and all
     * 2 385 tests stayed green — the milestone's characteristic defect, with the missing symmetric
     * surface being the TEST rather than the code.
     *
     * Three things asserted, because only together do they distinguish this from spam: the note
     * reaches a channel on a deployment that never watches, it is cleared once delivered, and the
     * beat reports what the pass ACTUALLY pushed rather than a hard-coded 0.
     */
    public function testASuccessfulOncePassCarriesAPendingRefusalAndClearsIt(): void
    {
        $note = \dirname($this->db) . '/car-last-refusal.txt';

        $seed = $this->scout(['--domain=car', 'run', '--once', '--seed', '--source=paruvendu']);
        self::assertSame(0, $seed['code'], $seed['err']);
        file_put_contents($note, '2026-08-29T22:00:00+02:00 — canal ntfy sans CAR_NTFY_TOPIC');

        $channel = new CarRecordingChannel();
        $r = $this->scout(['--domain=car', 'run', '--once', '--source=paruvendu'], $channel);

        self::assertSame(0, $r['code'], $r['err']);

        $beats = array_values(array_filter($channel->sent, static fn (Notification $n): bool => $n->kind === NotificationKind::HEARTBEAT));
        self::assertCount(1, $beats, 'a pending note forces exactly one beat on a verb that never beats');
        self::assertStringContainsString('CAR_NTFY_TOPIC', implode(' | ', $beats[0]->reasons), 'and it carries the refusal');
        self::assertFileDoesNotExist($note, 'delivered, therefore cleared — it cannot become furniture');

        // And it does not repeat once there is nothing pending.
        $again = new CarRecordingChannel();
        $this->scout(['--domain=car', 'run', '--once', '--source=paruvendu'], $again);
        self::assertSame(
            [],
            array_filter($again->sent, static fn (Notification $n): bool => $n->kind === NotificationKind::HEARTBEAT),
            'one push, not one per run',
        );
    }

    public function testAnUnusableHeartbeatHoursRefusalNamesTheCarKey(): void
    {
        // Dropping the key argument at the call site left the suite green (round-2 panel): the
        // refusal then named `HEARTBEAT_HOURS`, a legacy name the tool itself refuses at startup.
        putenv('CAR_HEARTBEAT_HOURS=0');
        putenv('SCOUT_MAX_PASSES=1');
        try {
            $seed = $this->scout(['--domain=car', 'run', '--once', '--seed', '--source=paruvendu']);
            self::assertSame(0, $seed['code'], $seed['err']);

            $r = $this->scout(['--domain=car', 'run', '--watch', '--source=paruvendu']);

            self::assertNotSame(0, $r['code'], 'zero would disable the only liveness signal');
            self::assertStringContainsString('CAR_HEARTBEAT_HOURS', $r['out'] . $r['err']);
        } finally {
            putenv('CAR_HEARTBEAT_HOURS');
            putenv('SCOUT_MAX_PASSES');
        }
    }
    public function testARunRefusalIsRecordedAndReportedOnTheNextBeat(): void
    {
        // Q27's second half, the car twin (round-3 panel): under `restart: unless-stopped` a
        // startup refusal is a crash loop whose stderr nobody reads. The note goes on the mounted
        // volume and the next successful start says it on the beat, then clears it.
        putenv('SCOUT_MAX_PASSES=1');
        try {
            $refused = $this->scout(['--domain=car', 'run', '--once', '--source=paruvendu']);
            self::assertSame(2, $refused['code']);
            $note = \dirname($this->db) . '/car-last-refusal.txt';
            self::assertFileExists($note, 'the refusal is written where the next start can find it');
            self::assertStringContainsString('seen-set', (string) file_get_contents($note));

            $seed = $this->scout(['--domain=car', 'run', '--once', '--seed', '--source=paruvendu']);
            self::assertSame(0, $seed['code'], $seed['err']);
            // A DELIVERING channel, since round 5: the note is cleared only once a beat has actually
            // been DELIVERED, and `console` alone reaches no recipient. Passing none used to pass
            // because the old code cleared unconditionally — which is the defect itself, a beat that
            // reached nobody still consuming the note.
            $channel = new CarRecordingChannel();
            $this->scout(['--domain=car', 'run', '--watch', '--source=paruvendu'], $channel);

            // Asserted on the CHANNEL, not on stdout: passing a recipient-reaching double replaces
            // the console one, so the beat is delivered rather than printed — which is the whole
            // point of the case.
            $reasons = implode(' | ', array_merge(...array_map(
                static fn (Notification $n): array => $n->reasons,
                array_values(array_filter($channel->sent, static fn (Notification $n): bool => $n->kind === NotificationKind::HEARTBEAT)),
            )));

            self::assertStringContainsString('démarrage précédent refusé', $reasons, 'the beat reports it');
            self::assertStringContainsString('seen-set', $reasons);
            self::assertFileDoesNotExist($note, 'reported AND delivered, therefore cleared');
        } finally {
            putenv('SCOUT_MAX_PASSES');
        }
    }

    /**
     * A rollback image opening a store a newer image migrated: the schema check throws, and that
     * must land as a refusal (exit 2, one line, the note written), never a stack trace.
     *
     * BOTH gates are exercised, which this test did not do before 2026-09-01. It bumped the RENT
     * store's `schema_meta` — a table that existed in the car database only because `VehicleStore`
     * composed the housing store wholesale, and which a car database no longer has at all. So the
     * one gate it drove was the incidental one, and neither of the two real ones was covered:
     * `vehicle_meta` (this domain's own tables) and `run_meta` (the generic run log). A rollback can
     * land on either, and each has its own message naming which store refused.
     */
    public function testANewerStoreIsARecordedRefusalNotATrace(): void
    {
        $seed = $this->scout(['--domain=car', 'run', '--once', '--seed', '--source=paruvendu']);
        self::assertSame(0, $seed['code'], $seed['err']);

        $note = \dirname($this->db) . '/car-last-refusal.txt';

        foreach ([
            'vehicle_meta' => 'base véhicules au schéma 99',
            'run_meta' => 'journal des exécutions au schéma 99',
        ] as $table => $expected) {
            $pdo = new \PDO('sqlite:' . $this->db);
            $pdo->exec("UPDATE {$table} SET value = '99' WHERE key = 'schema_version'");

            $r = $this->scout(['--domain=car', 'run', '--once', '--source=paruvendu']);

            self::assertSame(2, $r['code'], "a newer {$table} must refuse: " . $r['err']);
            self::assertStringContainsString($expected, $r['err']);
            self::assertStringNotContainsString('Stack trace', $r['err'], 'a refusal, never a trace');
            self::assertFileExists($note);

            // Restore, so the next gate is tested on its own rather than behind this one.
            $pdo->exec("UPDATE {$table} SET value = '1' WHERE key = 'schema_version'");
            @unlink($note);
        }
    }
    /** @return array{code: int, out: string, err: string} */
    private function scout(array $argv, ?CarRecordingChannel $channel = null, string $now = '2026-08-29T20:00:00+02:00'): array
    {
        $out = fopen('php://memory', 'r+');
        $err = fopen('php://memory', 'r+');
        self::assertIsResource($out);
        self::assertIsResource($err);

        $code = (new Scout(self::ROOT, $out, $err, $now, null, $channel === null ? null : new Notifier([$channel])))->run($argv);

        rewind($out);
        rewind($err);

        return ['code' => $code, 'out' => (string) stream_get_contents($out), 'err' => (string) stream_get_contents($err)];
    }
}
