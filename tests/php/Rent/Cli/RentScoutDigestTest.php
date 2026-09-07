<?php

declare(strict_types=1);

namespace Scout\Tests\Rent\Cli;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Scout\Rent\Cli\RentScout;
use Scout\Core\Notify\ConsoleChannel;
use Scout\Core\Notify\Notifier;
use Scout\Core\Notify\NotificationKind;
use Scout\Tests\Support\DeliveringChannel;
use Scout\Rent\Core\RawListing;
use Scout\Rent\Core\Tenure;
use Scout\Rent\Store\Store;

/**
 * `scout digest` — the ON-DEMAND half of Q34.
 *
 * The automatic half has worked since the pipeline was written: any pass producing new digest
 * entries emits them, marks them only after the channel confirms, and retries next run. This
 * command exists for the case that retry CANNOT reach — the pipeline re-offers an undelivered entry
 * only while the ad is still published, so a digest entry whose listing is delisted between two
 * passes is lost with nothing anywhere saying so. Reading the STORE rather than the pass is the
 * whole difference.
 *
 * **The load-bearing test in this file is {@see testARowWithoutASnapshotIsAnnouncedNeverSkipped}**
 * — a row with a verdict, an outcome and no snapshot is ANNOUNCED, from the columns `listings`
 * does hold, never skipped.
 *
 * The reason first written here for that rule was wrong, and the correction is worth keeping. It
 * said every row this command rescues predates schema v7, so skipping evidence-less rows would
 * skip precisely the backlog it exists for. A review panel migrated a real v4 and a real v6
 * database and showed the premise fails the other way: `outcome` is a v7 column too and is not
 * backfilled either, so a pre-v7 row has `outcome = NULL` and `pendingDigest()` never returns it.
 * That backlog is not skipped for lack of a snapshot — it is never selected, and widening the query
 * to reach it would pull REJECTED listings into §1's landing zone, because nothing stored tells a
 * pre-v7 digest apart from a pre-v7 rejection.
 *
 * The shape is reachable in production all the same, for an unrelated reason: since 2026-08-24 a
 * listing whose own text is not valid UTF-8 has its verdict stored without a snapshot, because
 * nothing can JSON-encode it. That listing is judged, digested, and has a title.
 *
 * That is NOT the rule `scout reclassify` follows, and the asymmetry is deliberate rather than an
 * inconsistency: reclassify FORMS a verdict, so running it on less evidence than the original saw
 * is the §1 breach schema v7 was built to prevent. This command only ANNOUNCES a verdict already
 * formed and already stored. Announcing a stored `DIGEST` from the stored title cannot promote
 * anything into a match.
 */
final class RentScoutDigestTest extends TestCase
{
    private const NOW = '2026-08-23T21:00:00+02:00';

    /** @var list<string> */
    private array $roots = [];

    protected function setUp(): void
    {
    }

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            self::removeTree($root);
        }
        $this->roots = [];
        putenv('RENT_SCOUT_DB');
        putenv('RENT_NTFY_TOPIC');
        putenv('NTFY_SERVER');
    }

    public function testNothingPendingIsSaidOutLoudAndSendsNothing(): void
    {
        $root = $this->tempRoot();

        $result = $this->scout($root, ['digest']);

        self::assertSame(0, $result['code']);
        self::assertStringContainsString('aucune annonce', mb_strtolower($result['out']));
    }

    public function testAStoredVerdictIsAnnouncedFromItsSnapshotAndMarkedOnlyAfterDelivery(): void
    {
        $root = $this->tempRoot();
        $key = $this->seedDigestRow($root, new RawListing(
            sourceName: 'cdc_habitat',
            externalId: 'A-1',
            title: 'Appartement T4',
            description: 'Logement conventionné',
            commune: 'Sartrouville',
            postcode: '78500',
            rentCc: 1450,
            surfaceM2: 88.0,
            rooms: 4,
        ));

        $result = $this->scout($root, ['digest'], $this->delivering());

        self::assertSame(0, $result['code'], $result['err']);
        // Everything on this line comes from the SNAPSHOT, not from the `listings` columns — which
        // hold neither commune, nor postcode, nor surface. If the snapshot stopped being read, the
        // entry would degrade to "commune inconnue" and still pass a laxer assertion.
        self::assertStringContainsString('Sartrouville 78500', $result['out']);
        self::assertStringContainsString('88', $result['out']);
        self::assertStringContainsString('1450 € CC', $result['out']);

        $store = Store::open($root . '/state/rent-watch.sqlite3');
        self::assertTrue($store->wasNotified($key), 'a delivered entry must be marked');

        // And it must not repeat: a digest that re-announces its whole contents every invocation is
        // the alert fatigue Q34 was ruled to avoid.
        $second = $this->scout($root, ['digest']);
        self::assertStringContainsString('aucune annonce', mb_strtolower($second['out']));
    }

    public function testARowWithoutASnapshotIsAnnouncedNeverSkipped(): void
    {
        $root = $this->tempRoot();
        $key = $this->seedDigestRow($root, new RawListing(
            sourceName: 'inli',
            externalId: 'B-2',
            title: 'T3 à Longjumeau',
            commune: 'Longjumeau',
            rentCc: 1005,
        ));
        $this->stripSnapshot($root, $key);

        $result = $this->scout($root, ['digest'], $this->delivering());

        self::assertSame(0, $result['code'], $result['err']);
        // Announced from the columns `listings` does hold. This is the entire point of the command.
        self::assertStringContainsString('T3 à Longjumeau', $result['out']);
        self::assertStringContainsString('1005 € CC', $result['out']);
        // And counted, so a backlog announced without its full detail says so rather than looking
        // like a source that publishes nothing but titles.
        self::assertStringContainsString('sans instantané', $result['out']);

        $store = Store::open($root . '/state/rent-watch.sqlite3');
        self::assertTrue($store->wasNotified($key));
    }

    public function testACorruptSnapshotIsAnnouncedAndLoudlyCounted(): void
    {
        $root = $this->tempRoot();
        $key = $this->seedDigestRow($root, new RawListing(
            sourceName: 'cityloger',
            externalId: 'C-3',
            title: 'T4 Antony',
            rentCc: 1300,
        ));
        $this->corruptSnapshot($root, $key);

        $result = $this->scout($root, ['digest'], $this->delivering());

        // Loud, because a corrupt snapshot is data damage rather than an expected pre-v7 shape —
        // but the entry is still announced, because dropping it would lose the listing entirely and
        // nothing else will ever surface it.
        self::assertSame(0, $result['code'], $result['err']);
        self::assertStringContainsString('T4 Antony', $result['out']);
        self::assertStringContainsString('illisible', $result['out'] . $result['err']);

        $store = Store::open($root . '/state/rent-watch.sqlite3');
        self::assertTrue($store->wasNotified($key));
    }

    public function testNothingIsMarkedWhenNoChannelAccepts(): void
    {
        // `ntfy` against a closed loopback port: `check()` passes, `send()` fails at the socket. No
        // third-party host is contacted, which the offline guarantee requires.
        $root = $this->tempRoot(['notify' => ['channels' => ['ntfy']]]);
        putenv('RENT_NTFY_TOPIC=rent-watch-test');
        putenv('NTFY_SERVER=http://127.0.0.1:1');

        $key = $this->seedDigestRow($root, new RawListing(
            sourceName: 'inli',
            externalId: 'D-4',
            title: 'T3 Massy',
            rentCc: 1100,
        ));

        $result = $this->scout($root, ['digest']);

        self::assertSame(1, $result['code'], 'an undelivered digest must exit non-zero');

        $store = Store::open($root . '/state/rent-watch.sqlite3');
        self::assertFalse(
            $store->wasNotified($key),
            'marking before the channel confirms loses the whole batch permanently — a digest entry has no second chance',
        );
        // Still pending, so the next invocation retries it.
        self::assertCount(1, $store->pendingDigest());
    }

    public function testDryRunAnnouncesNothingAndMarksNothing(): void
    {
        $root = $this->tempRoot();
        $key = $this->seedDigestRow($root, new RawListing(
            sourceName: 'inli',
            externalId: 'E-5',
            title: 'T3 Palaiseau',
            rentCc: 1080,
        ));

        $result = $this->scout($root, ['digest', '--dry-run']);

        self::assertSame(0, $result['code'], $result['err']);
        self::assertStringContainsString('T3 Palaiseau', $result['out']);

        $store = Store::open($root . '/state/rent-watch.sqlite3');
        self::assertFalse($store->wasNotified($key), '--dry-run must not consume the backlog');
    }

    // ── harness ───────────────────────────────────────────────────────────────────────────────────

    /** Writes one listing judged DIGEST and never delivered, and returns its dedup key. */
    public function testTheBacklogIsSentInBoundedBatchesThatSayWhatIsLeft(): void
    {
        // **The query was unbounded and the send is all-or-nothing.** Any rejection that is a
        // function of payload size was therefore permanently self-perpetuating: the batch that
        // failed came back next time with MORE rows in it, so the *à vérifier* bin — §1's only
        // landing zone — hardened into permanent undeliverability while the command printed a
        // single warning to a log nobody reads. Measured by a review panel on 2026-08-24 at ~95
        // bytes per entry, linear and unbounded.
        //
        // The remainder must be ANNOUNCED as well as bounded: a cap that stayed quiet about what it
        // left behind would read as the whole backlog, and an operator who believes the bin is
        // empty stops running the command.
        $root = $this->tempRoot();
        $over = Store::DIGEST_BATCH + 7;

        for ($i = 0; $i < $over; $i++) {
            $this->seedDigestRow($root, new RawListing(
                sourceName: 'cdc_habitat',
                externalId: 'BATCH-' . $i,
                title: 'Appartement T4',
                description: 'Aucun régime annoncé',
                commune: 'Sartrouville',
                postcode: '78500',
                rentCc: 1450,
                surfaceM2: 82.0,
                rooms: 4,
            ));
        }

        $result = $this->scout($root, ['digest', '--dry-run']);

        self::assertSame(0, $result['code']);
        self::assertSame(
            Store::DIGEST_BATCH,
            substr_count($result['out'], 'Sartrouville'),
            'one batch, capped — an unbounded backlog in one all-or-nothing send can never drain',
        );
        self::assertStringContainsString(
            '7 autre(s) en attente',
            $result['out'],
            'the remainder must be named, or a capped batch reads as the whole bin',
        );
    }

    /**
     * THE DRAIN MUST READ WHY THE ROW IS IN THE BIN, NOT ASSUME IT.
     *
     * Until Track 1f the *à vérifier* bin had one entrance and the rollup title could speak for
     * every entry. The price-per-m² plausibility branch opened a second: a listing digested because
     * its rent and its surface do not describe the same dwelling is typically `LLI` at FULL
     * confidence, its regime as settled as it gets. Announcing it *"au régime indéterminé"* asserts
     * as doubtful something the classifier decided.
     *
     * `scout digest` reads the STORE rather than the pass, so the cause has to come off the stored
     * verdict — which is why `pendingDigest()` now selects `tenure`. This is the test that
     * `DigestTitleTest` cannot be: that one hands the formatter verdicts built by hand, and the
     * sabotage ledger proved the gap by making the drain call every row a tenure doubt and watching
     * the whole suite stay green.
     */
    public function testTheRollupDropsTheRegimeClauseWhenARowsTenureWasDetermined(): void
    {
        $root = $this->tempRoot();
        $this->seedDigestRow($root, $this->digestListing('A-1'));                    // a real doubt
        $this->seedDigestRow($root, $this->digestListing('A-2'), tenure: 'LLI');     // the price branch

        $result = $this->scout($root, ['digest'], $this->delivering());

        self::assertSame(0, $result['code'], $result['err']);
        self::assertStringContainsString('2 annonce(s)', $result['out']);
        self::assertStringNotContainsString(
            'régime indéterminé',
            $result['out'],
            'one entry whose regime IS determined removes the clause for the batch — the entry '
                . 'bodies still carry every reason, so it says less rather than something untrue',
        );
    }

    /**
     * THE COUNTERWEIGHT, and without it the assertion above is satisfied by deleting the clause.
     * A batch that is nothing but tenure doubts must still be named as one: the digest is §1's only
     * landing zone, and the title is what decides whether it is opened on a phone at all.
     */
    public function testARollupOfPureTenureDoubtsKeepsTheRegimeClause(): void
    {
        $root = $this->tempRoot();
        $this->seedDigestRow($root, $this->digestListing('A-1'));
        $this->seedDigestRow($root, $this->digestListing('A-2'));

        $result = $this->scout($root, ['digest'], $this->delivering());

        self::assertSame(0, $result['code'], $result['err']);
        self::assertStringContainsString('2 annonce(s)', $result['out']);
        self::assertStringContainsString('régime indéterminé', $result['out']);
    }

    private function digestListing(string $id): RawListing
    {
        return new RawListing(
            sourceName: 'cdc_habitat',
            externalId: $id,
            title: 'Appartement T4',
            description: 'Logement conventionné',
            commune: 'Sartrouville',
            postcode: '78500',
            rentCc: 1450,
            surfaceM2: 88.0,
            rooms: 4,
        );
    }

    // ── Row 6 / A5 (2026-09-05): the low-score queue drains through the SAME command, under its own heading ──

    /**
     * ONE drain, two queues, separated in the mail: a low-score match is *vérifié, score bas*, never
     * *à vérifier* — the rent digest MEANS tenure doubt, and announcing a settled LLI under that
     * heading would misreport its §1 status. A drained entry is marked ROLLUP, so a later rent drop
     * over the gate is still a promotion.
     */
    public function testALowScoreMatchIsRolledUpUnderItsOwnHeadingAndMarkedRollup(): void
    {
        // A gate ABOVE any score the row can reach: with no gate configured every queued row is a
        // RETRY (a push that failed), pushed individually — the rollup exists only under a gate.
        $root = $this->tempRoot(['notify' => ['push_min_score' => 100]]);
        $key = $this->seedQueuedMatch($root, new RawListing(
            sourceName: 'inli',
            externalId: 'LOW-1',
            title: 'Appartement 3 pièces',
            description: 'Logement intermédiaire (LLI).',
            fields: ['financement' => 'LLI'],
            // Inside the temp root's criteria: the command re-SCORES a queued row under today's
            // criteria, and a row they reject is left waiting for `reclassify`, never announced.
            commune: 'Sartrouville',
            postcode: '78500',
            rentCc: 1450,
            surfaceM2: 88.0,
            rooms: 4,
        ));

        $result = $this->scout($root, ['digest'], $this->delivering());

        self::assertSame(0, $result['code'], $result['out'] . $result['err']);
        self::assertStringContainsString('score bas', $result['out'], 'its own heading');
        self::assertStringContainsString('Sartrouville 78500', $result['out'], 'announced from the snapshot; ERR=' . $result['err']);
        self::assertStringNotContainsString('régime indéterminé', $result['out'], 'a settled LLI is never announced as a tenure doubt');

        $store = Store::open($root . '/state/rent-watch.sqlite3');
        self::assertTrue($store->wasNotifiedAs($key, 'ROLLUP'), 'marked as rolled up, on delivery');
        self::assertFalse($store->wasNotifiedAs($key, 'MATCH'), 'not as pushed — the promotion stays reachable');

        $second = $this->scout($root, ['digest']);
        self::assertStringContainsString('aucune annonce', mb_strtolower($second['out']), 'drained once');
    }

    /** Both queues in one mail: the tenure doubts keep their heading and their clause, the rollup keeps its own. */
    public function testBothQueuesShareOneMailWithTwoHeadings(): void
    {
        $root = $this->tempRoot(['notify' => ['push_min_score' => 100]]);
        $this->seedDigestRow($root, new RawListing(sourceName: 'cdc_habitat', externalId: 'D-1', title: 'T4', description: 'Logement conventionné', commune: 'Sartrouville', postcode: '78500', rentCc: 1450, surfaceM2: 88.0, rooms: 4));
        $this->seedQueuedMatch($root, new RawListing(sourceName: 'inli', externalId: 'LOW-2', title: 'T3', description: 'LLI', fields: ['financement' => 'LLI'], commune: 'Sartrouville', postcode: '78500', rentCc: 1450, surfaceM2: 88.0, rooms: 4));

        $result = $this->scout($root, ['digest'], $this->delivering());

        self::assertSame(0, $result['code'], $result['out'] . $result['err']);
        self::assertStringContainsString('À vérifier : 1 annonce(s) au régime indéterminé', $result['out']);
        self::assertStringContainsString('score bas', $result['out']);
        self::assertLessThan(strpos($result['out'], 'score bas'), strpos($result['out'], 'À vérifier'), 'the §1 bin comes first');
    }

    // ── C2 round 6 (2026-09-05): a queued row AT the line is a failed push, not a low score ──────

    /**
     * The queue cannot tell a match HELD BACK by the gate from one whose push FAILED — both leave a
     * `MATCH` outcome with no `notified_at`. So the drain re-scores and splits: at or over the
     * line, the row is pushed as the individual match it is and marked MATCH; announcing it under
     * *« vérifié, score bas »* would report the opposite of what the gate decided.
     */
    public function testAQueuedMatchAtOrOverTheGateIsPushedIndividuallyAndMarkedMatch(): void
    {
        $root = $this->tempRoot(['notify' => ['push_min_score' => 1]]);
        $key = $this->seedQueuedMatch($root, $this->queueable('inli', 'RETRY-1'));

        $channel = $this->delivering();
        $result = $this->scout($root, ['digest'], $channel);

        self::assertSame(0, $result['code'], $result['out'] . $result['err']);
        self::assertStringContainsString('[RETRY]', $result['out'], 'pushed, not rolled up');
        self::assertStringContainsString('réémise(s) individuellement', $result['out']);
        // The rollup HEADING, not the phrase: the retry line itself says « jamais score bas ».
        self::assertStringNotContainsString('Vérifié, score bas', $result['out'], 'it did not fall short — it never went out');

        self::assertCount(1, $channel->sent, 'one push, and no rollup mail beside it');
        self::assertSame(NotificationKind::MATCH, $channel->sent[0]->kind);

        $store = Store::open($root . '/state/rent-watch.sqlite3');
        // MATCH is rank 3 and `wasNotifiedAs()` asks *at least*, so this says it was pushed rather
        // than rolled up — a ROLLUP row answers false here.
        self::assertTrue($store->wasNotifiedAs($key, 'MATCH'), 'marked as pushed, not rolled up');
    }

    /**
     * The deployment with NO gate is the one this branch exists for: nothing there is ever held
     * back, so a queued row can only be a push that failed, and leaving it to a rollup that will
     * never be configured is how it is lost.
     */
    public function testWithNoGateConfiguredEveryQueuedRowIsRetriedRatherThanRolledUp(): void
    {
        $root = $this->tempRoot();
        $key = $this->seedQueuedMatch($root, $this->queueable('inli', 'RETRY-2'));

        $channel = $this->delivering();
        $result = $this->scout($root, ['digest'], $channel);

        self::assertSame(0, $result['code'], $result['out'] . $result['err']);
        self::assertStringContainsString('réémise(s) individuellement', $result['out']);
        self::assertStringNotContainsString('Vérifié, score bas', $result['out']);
        self::assertSame(NotificationKind::MATCH, $channel->sent[0]->kind);
        self::assertTrue(Store::open($root . '/state/rent-watch.sqlite3')->wasNotifiedAs($key, 'MATCH'));
    }

    /**
     * A failed push whose channel is still refusing is left EXACTLY where it was: nothing marked,
     * so the next drain offers it again. Marking first would consume it permanently.
     */
    public function testARetryTheChannelRefusesIsLeftWaitingAndSaidOutLoud(): void
    {
        $root = $this->tempRoot();
        $key = $this->seedQueuedMatch($root, $this->queueable('inli', 'RETRY-3'));

        $channel = $this->delivering();
        $channel->refuses = [NotificationKind::MATCH];
        $result = $this->scout($root, ['digest'], $channel);

        self::assertStringContainsString('réémission non délivrée', $result['out'] . $result['err']);
        $store = Store::open($root . '/state/rent-watch.sqlite3');
        self::assertFalse($store->wasNotifiedAs($key, 'MATCH'), 'nothing marked on a refused send');
        self::assertSame(1, $store->pendingLowScoreCount(), 'still queued for the next drain');
    }

    /**
     * C2 ROUND 8 P1 — THE REMAINDER LINE, INVERTED.
     *
     * Round 7 fixed the OVER-report (`overflow()` counted every queued row against the rollup list
     * alone, so a drained retry read as still pending) and introduced the UNDER-report in the same
     * change: it now subtracts every retry whether or not the channel took it, while
     * `pushRetries()` leaves a refused one queued BY DESIGN. On a deployment with no
     * `push_min_score` — where every queued row is a retry — a drain against a dead channel says
     * `0 autre(s) en attente` for a backlog that did not move at all.
     *
     * Both existing assertions set `push_min_score => 100`, so nothing in the tree exercised
     * `count()` or `overflow()` with a non-empty retry list. The counterweight — a DELIVERED retry
     * claims no remainder — is `testADrainThatEmptiedTheQueueClaimsNoRemainder` above.
     */
    public function testARefusedRetryIsStillReportedAsARemainder(): void
    {
        $root = $this->tempRoot();
        $this->seedQueuedMatch($root, $this->queueable('inli', 'REFUSED-REM'));

        $channel = $this->delivering();
        $channel->refuses = [NotificationKind::MATCH];
        $result = $this->scout($root, ['digest'], $channel);

        self::assertSame(1, Store::open($root . '/state/rent-watch.sqlite3')->pendingLowScoreCount(), 'premise: it did not drain');
        self::assertStringContainsString('1 autre(s) en attente', $result['out'] . $result['err']);
    }

    // ── C2 round 7 (2026-09-05): §1 IS JUDGED FROM EVERY PERSISTED READING, not from this row ────

    /**
     * A PLS TWIN ON THE OTHER TRACK VETOES THE DRAIN, and until round 7 it did not.
     *
     * `pendingLowScore()` selects the row's OWN `tenure` and nothing else, so the drain judged §1
     * from that column alone while `Pipeline` (the push path) and `reclassify` both refuse on the
     * PERSISTED twin and group readings. The retry arm then sent a full `MATCH` push and marked the
     * row `MATCH`, which cannot be demoted — so the flat left the queue for ever.
     *
     * This is the exact state schema v12 persists the veto for: *a pass seeing the agency copy alone
     * pushed the PLS flat*. The direct copy is queued; a later pass fetches only the agency copy,
     * judges it `PLS`, and `recordTwin()` writes that onto the direct row; the direct row is never
     * fetched again (delisted, a failed source, a `--source=` run). The drain is then the only thing
     * that will ever look at it.
     */
    public function testAnExcludedTwinOnTheOtherTrackIsNeverAnnouncedByTheDrain(): void
    {
        $root = $this->tempRoot();
        $key = $this->seedQueuedMatch($root, $this->queueable('inli', 'VETO-TWIN'));
        Store::open($root . '/state/rent-watch.sqlite3')->recordTwin($key, Tenure::PLS, 'seloger', 9000);

        $channel = $this->delivering();
        $result = $this->scout($root, ['digest'], $channel);

        self::assertSame([], $channel->sent, 'nothing announced, on either arm');
        self::assertStringContainsString('autre voie', $result['out'] . $result['err'], 'and the veto is said out loud');
        $store = Store::open($root . '/state/rent-watch.sqlite3');
        self::assertFalse($store->wasNotified($key), 'nothing marked — `reclassify` owns verdict changes');
        self::assertSame(1, $store->pendingLowScoreCount(), 'left in the queue, not consumed');
    }

    /** An UNDETERMINED twin is refused too — `reclassify` refuses to PROMOTE on one, and this drain cannot re-file. */
    public function testAnUndeterminedTwinIsRefusedAsWell(): void
    {
        $root = $this->tempRoot();
        $key = $this->seedQueuedMatch($root, $this->queueable('inli', 'VETO-TWIN-UNK'));
        Store::open($root . '/state/rent-watch.sqlite3')->recordTwin($key, Tenure::UNKNOWN, 'seloger', 3000);

        $channel = $this->delivering();
        $this->scout($root, ['digest'], $channel);

        self::assertSame([], $channel->sent);
        self::assertFalse(Store::open($root . '/state/rent-watch.sqlite3')->wasNotified($key));
    }

    /** The group veto is durable and cluster-wide: an excluded SIBLING stops the drain announcing the survivor. */
    /**
     * §1, C2 ROUND 8 P0 (a) — TWO ARMS OF ONE LOOP GAVE OPPOSITE ANSWERS TO THE SAME ROW.
     *
     * Round 7 lifted the group and twin vetoes above the retry/rollup split, for the reason its own
     * comment gives: both arms announce, so a guard inside `pushRetries()` would be *a fix landing
     * on one of two symmetric surfaces*. It then left the row's OWN `tenure` reading below the
     * snapshot branch — and that branch `continue`s, so a row stored `PLS` whose payload will not
     * encode was queued as a match, pushed individually, and marked `notified_as = 'MATCH'`, which
     * cannot be demoted. The row left the queue for ever.
     *
     * Reached with no forged state: `reclassify --reopen` clears `tenure` while `outcome` stays
     * `MATCH`, and the unencodable-payload row is the documented production shape.
     */
    public function testASnapshotLessRowIsRefusedOnItsOwnExcludedReading(): void
    {
        $root = $this->tempRoot();
        $key = $this->seedQueuedMatch($root, $this->queueable('inli', 'NOSNAP-PLS'));
        $this->stripSnapshot($root, $key);
        $this->pdo($root)
            ->prepare('UPDATE listings SET tenure = :t WHERE dedup_key = :key')
            ->execute(['t' => 'PLS', 'key' => $key]);

        $channel = $this->delivering();
        $result = $this->scout($root, ['digest'], $channel);

        self::assertSame([], $channel->sent, 'a PLS row must not be announced because its payload would not encode');
        self::assertStringContainsString('incompatible avec un MATCH', $result['out'] . $result['err']);
        self::assertFalse(
            Store::open($root . '/state/rent-watch.sqlite3')->wasNotifiedAs($key, 'MATCH'),
            'and it must stay in the queue rather than be marked undemotably',
        );
    }

    /**
     * §1, C2 ROUND 8 P0 (b) — THE FOURTH PERSISTED ROUTE, WHICH THE DRAIN NEVER READ.
     *
     * `Pipeline` judges §1 from THREE persisted readings; round 7 gave this drain two of them.
     * `Store::excludedDwellings()` is the third, and it is the only one that catches a portal
     * RE-ADVERTISING the same flat under a new ad id: there is no group edge and no twin, so the
     * other two see nothing at all. Its only consumers were `Pipeline`'s two call sites.
     *
     * The counterweight is in the test below — an unrelated excluded row must not veto anything,
     * or this guard is satisfied by refusing the whole queue.
     */
    public function testAFlatRecordedExcludedUnderAnotherAdIdVetoesTheDrain(): void
    {
        $root = $this->tempRoot();
        $key = $this->seedQueuedMatch($root, $this->queueable('inli', 'READVERT-NEW'));
        $this->seedExcludedDwelling($root, $this->queueable('inli', 'READVERT-OLD'), Tenure::PLS);

        $channel = $this->delivering();
        $result = $this->scout($root, ['digest'], $channel);

        self::assertSame([], $channel->sent, 'the same flat is on record as PLS under its previous ad id');
        self::assertStringContainsString('même logement', $result['out'] . $result['err'], 'the DWELLING route, not the group or twin one');
        self::assertFalse(Store::open($root . '/state/rent-watch.sqlite3')->wasNotifiedAs($key, 'MATCH'));
    }

    /** THE COUNTERWEIGHT: a stored PLS row that is a DIFFERENT flat must veto nothing. */
    public function testAnUnrelatedExcludedRowDoesNotVetoTheDrain(): void
    {
        $root = $this->tempRoot();
        $other = new RawListing(
            sourceName: 'inli',
            externalId: 'ELSEWHERE-1',
            title: 'Appartement 2 pièces',
            commune: 'Dourdan',
            postcode: '91410',
            rentCc: 700,
            surfaceM2: 41.0,
            rooms: 2,
        );
        $key = $this->seedQueuedMatch($root, $this->queueable('inli', 'UNRELATED-NEW'));
        $this->seedExcludedDwelling($root, $other, Tenure::PLS);

        $channel = $this->delivering();
        $this->scout($root, ['digest'], $channel);

        self::assertCount(1, $channel->sent, 'a PLS flat in another commune is not this flat');
        self::assertTrue(Store::open($root . '/state/rent-watch.sqlite3')->wasNotifiedAs($key, 'MATCH'));
    }

    public function testAnExcludedSiblingInTheClusterVetoesTheDrain(): void
    {
        $root = $this->tempRoot();
        $key = $this->seedQueuedMatch($root, $this->queueable('inli', 'VETO-GROUP'));

        $store = Store::open($root . '/state/rent-watch.sqlite3');
        $sibling = $this->queueable('seloger', 'VETO-GROUP-SIB');
        $sighting = $store->record($sibling, $sibling->effectiveRentCc(), self::NOW);
        $store->recordVerdict($sighting->dedupKey, 'PLS', 9000, ['plafond de ressources PLS'], $sibling);
        $store->recordOutcome($sighting->dedupKey, 'REJECT');
        $store->assignGroup([$key, $sighting->dedupKey]);

        $channel = $this->delivering();
        $result = $this->scout($root, ['digest'], $channel);

        self::assertSame([], $channel->sent, 'the cluster holds an excluded member');
        self::assertStringContainsString('groupe', $result['out'] . $result['err']);
        self::assertFalse(Store::open($root . '/state/rent-watch.sqlite3')->wasNotified($key));
    }

    // ── C2 round 6 (2026-09-05): two tracks, ONE rollup entry ────────────────────────────────────

    /**
     * The pipeline's twin cover fires on a copy that was PUSHED; two copies both held BACK never
     * met it, so one flat was announced twice in the same mail. Collapsed at the drain, which is
     * the only place that holds both — and in BOTH orders, because survivorship must not depend on
     * which pass queued which copy.
     *
     * @param bool $directFirst seed the institutional copy first, or the agency copy first
     */
    #[DataProvider('twinOrders')]
    public function testTwinsInTheRollupCollapseToOneEntryNamingTheOtherRoute(bool $directFirst): void
    {
        $root = $this->tempRoot(['notify' => ['push_min_score' => 100]]);
        $direct = $this->queueable('inli', 'TWIN-D');
        $agency = $this->queueable('seloger', 'TWIN-A');

        $first = $this->seedQueuedMatch($root, $directFirst ? $direct : $agency);
        $second = $this->seedQueuedMatch($root, $directFirst ? $agency : $direct);

        $channel = $this->delivering();
        $result = $this->scout($root, ['digest'], $channel);

        self::assertSame(0, $result['code'], $result['out'] . $result['err']);
        self::assertStringContainsString('1 annonce(s) émise(s)', $result['out'], 'one entry, not two');
        self::assertStringContainsString('aussi via seloger (agence / portail)', $result['out'], 'the direct route keeps the headline and names the other');
        self::assertStringNotContainsString('aussi via inli', $result['out'], 'whichever copy was queued first');

        // BOTH keys marked, or tomorrow's drain announces the survivor's twin on its own.
        $store = Store::open($root . '/state/rent-watch.sqlite3');
        self::assertTrue($store->wasNotifiedAs($first, 'ROLLUP'), 'first-queued copy marked');
        self::assertTrue($store->wasNotifiedAs($second, 'ROLLUP'), 'second-queued copy marked too');
        self::assertSame(0, $store->pendingLowScoreCount(), 'the queue is drained, both sides of it');
    }

    /**
     * A `sources.json` this command cannot read must NOT take the drain down with it.
     *
     * The collapse is the one thing here that reads outside the store, and the digest is §1's only
     * landing zone — a backlog no pass will re-offer. So the failure is VOICED and the entries pass
     * through uncollapsed: a duplicate announcement, which is the behaviour of the day before the
     * collapse existed, rather than a bin that can never empty.
     */
    public function testAnUnreadableSourceConfigIsVoicedAndStillDrainsTheQueue(): void
    {
        $root = $this->tempRoot(['notify' => ['push_min_score' => 100]]);
        $this->seedQueuedMatch($root, $this->queueable('inli', 'BROKEN-D'));
        $this->seedQueuedMatch($root, $this->queueable('seloger', 'BROKEN-A'));
        file_put_contents($root . '/config/rent/sources.json', '{"sources": {"inli": {"enabled": 42}}}');

        $result = $this->scout($root, ['digest'], $this->delivering());

        self::assertSame(0, $result['code'], $result['out'] . $result['err']);
        self::assertStringContainsString('familles des sources illisibles', $result['out'] . $result['err'], 'said out loud');
        self::assertStringContainsString('2 annonce(s) émise(s)', $result['out'], 'uncollapsed, and both are still announced');
        self::assertSame(0, Store::open($root . '/state/rent-watch.sqlite3')->pendingLowScoreCount(), 'the bin still empties');
    }

    /** @return iterable<string, array{bool}> */
    public static function twinOrders(): iterable
    {
        yield 'the direct route was queued first' => [true];
        yield 'the agency copy was queued first' => [false];
    }

    /**
     * TWINS THAT STRADDLE THE GATE ARE ONE FLAT, ONE ANNOUNCEMENT — and round 6 got this wrong.
     *
     * The collapse ran on each list AFTER the split, so a direct copy scoring over the gate and its
     * agency twin scoring under it held one entry each, `collapseTwins()` returned at `count < 2`,
     * and the same flat went out twice in one drain: an individual MATCH push AND a rollup line,
     * neither naming the other route. Collapsing over the UNION before the split is what fixes it,
     * and the surviving route's score is then what decides the arm.
     */
    public function testTwinsStraddlingTheGateAreCollapsedBeforeTheSplit(): void
    {
        // A gate BETWEEN the two scores: the elevator bonus lifts the direct copy over it.
        $root = $this->tempRoot(['notify' => ['push_min_score' => 60]]);
        $direct = $this->seedQueuedMatch($root, new RawListing(
            sourceName: 'inli', externalId: 'STRADDLE-D', title: 'Appartement 3 pièces',
            description: 'Logement intermédiaire (LLI).', fields: ['financement' => 'LLI'],
            commune: 'Sartrouville', postcode: '78500', rentCc: 1450, surfaceM2: 88.0, rooms: 4,
            floor: 2, hasElevator: true,
        ));
        $agency = $this->seedQueuedMatch($root, new RawListing(
            sourceName: 'seloger', externalId: 'STRADDLE-A', title: 'Appartement 3 pièces',
            description: 'Logement intermédiaire (LLI).', fields: ['financement' => 'LLI'],
            commune: 'Sartrouville', postcode: '78500', rentCc: 1450, surfaceM2: 88.0, rooms: 4,
        ));

        $channel = $this->delivering();
        $result = $this->scout($root, ['digest'], $channel);

        self::assertSame(0, $result['code'], $result['out'] . $result['err']);
        self::assertCount(1, $channel->sent, 'ONE announcement for one flat, not a push and a rollup');
        self::assertStringContainsString('aussi via seloger', $result['out'], 'and it names the other route');

        $store = Store::open($root . '/state/rent-watch.sqlite3');
        self::assertSame(0, $store->pendingLowScoreCount(), 'both keys drained together');
        self::assertTrue($store->wasNotified($direct));
        self::assertTrue($store->wasNotified($agency));
    }

    /**
     * WHICH ARM A SNAPSHOT-LESS ROW TAKES IS THE GATE'S QUESTION, NOT THE SNAPSHOT'S.
     *
     * An unencodable payload cannot be re-scored, and the rent drain used to file it in the rollup
     * unconditionally — so on a deployment with NO gate, where nothing is ever held back, a failed
     * push was announced under a heading asserting it fell short of a threshold that does not
     * exist, and marked ROLLUP, which takes it out of the queue for ever. The car drain already
     * read it the other way.
     */
    public function testASnapshotLessRowIsRetriedWhenNoGateIsConfigured(): void
    {
        $root = $this->tempRoot();
        $key = $this->seedQueuedMatch($root, $this->queueable('inli', 'NOSNAP-1'));
        $this->stripSnapshot($root, $key);

        $channel = $this->delivering();
        $result = $this->scout($root, ['digest'], $channel);

        self::assertStringContainsString('réémise(s) individuellement', $result['out']);
        self::assertSame(NotificationKind::MATCH, $channel->sent[0]->kind, 'pushed, not filed under a threshold that does not exist');
        self::assertTrue(Store::open($root . '/state/rent-watch.sqlite3')->wasNotifiedAs($key, 'MATCH'));
    }

    /**
     * THE COUNTERWEIGHT THE REMAINDER LINE NEVER HAD: it must stay SILENT when nothing is left.
     *
     * `overflow()` counted `waitingLowScore` — every queued row, retries included — against the
     * rollup list alone, so every row that became a retry was reported as still pending. On a
     * deployment with no gate that is a phantom backlog on every single drain, and a line that
     * claims a backlog it has just emptied is how an operator learns to stop reading it. Every
     * existing assertion proved the line FIRES when there IS a remainder; none proved it silent.
     */
    public function testADrainThatEmptiedTheQueueClaimsNoRemainder(): void
    {
        $root = $this->tempRoot();
        $this->seedQueuedMatch($root, $this->queueable('inli', 'NOREM-1'));

        $result = $this->scout($root, ['digest'], $this->delivering());

        self::assertStringContainsString('réémise(s) individuellement', $result['out']);
        self::assertStringNotContainsString('autre(s) en attente', $result['out'], 'there is no suite');
        self::assertSame(0, Store::open($root . '/state/rent-watch.sqlite3')->pendingLowScoreCount());
    }

    /**
     * A mail carrying ONLY the rollup is a `ROLLUP` notification, never a `DIGEST`.
     *
     * The kind is what a channel routes and prioritises on, and *digest* here means §1's tenure
     * bin. A settled LLI held back for its score is not a tenure doubt, so announcing it under the
     * digest kind misreports its §1 status at the one layer that leaves the process.
     */
    public function testARollupOnlyMailIsAnnouncedUnderTheRollupKind(): void
    {
        $root = $this->tempRoot(['notify' => ['push_min_score' => 100]]);
        $this->seedQueuedMatch($root, $this->queueable('inli', 'KIND-1'));

        $channel = $this->delivering();
        $result = $this->scout($root, ['digest'], $channel);

        self::assertSame(0, $result['code'], $result['out'] . $result['err']);
        self::assertCount(1, $channel->sent);
        self::assertSame(NotificationKind::ROLLUP, $channel->sent[0]->kind);
    }

    /** A mail carrying a tenure doubt keeps the DIGEST kind, rollup beside it or not. */
    public function testAMailCarryingATenureDoubtKeepsTheDigestKind(): void
    {
        $root = $this->tempRoot(['notify' => ['push_min_score' => 100]]);
        $this->seedDigestRow($root, $this->digestListing('D-KIND'));
        $this->seedQueuedMatch($root, $this->queueable('inli', 'KIND-2'));

        $channel = $this->delivering();
        $this->scout($root, ['digest'], $channel);

        self::assertCount(1, $channel->sent);
        self::assertSame(NotificationKind::DIGEST, $channel->sent[0]->kind);
    }

    /**
     * HARD RULE 7 AT THE ONE PLACE IT IS EASIEST TO FORGET: a channel failure carries the URL it
     * failed on, and an ntfy topic, an SMTP DSN or a Telegram bot token lives in that URL.
     *
     * `CarScout` redacts at all four of its sites; `RentScout` redacted at three of six, including
     * one seven lines below the redacting sibling this round added. These warnings go to stderr,
     * which under `compose.yaml` is the container log.
     */
    public function testAChannelFailureIsRedactedBeforeItReachesTheLog(): void
    {
        $root = $this->tempRoot(['notify' => ['push_min_score' => 100]]);
        $this->seedQueuedMatch($root, $this->queueable('inli', 'REDACT-1'));

        $channel = $this->delivering();
        $channel->refuses = [NotificationKind::ROLLUP];
        $channel->refusalMessage = 'ntfy: POST https://ntfy.sh/scout-9f2ac?auth=tk_abc123 a répondu 401';

        $result = $this->scout($root, ['digest'], $channel);

        $said = $result['out'] . $result['err'];
        self::assertStringContainsString('401', $said, 'the diagnosis survives');
        self::assertStringNotContainsString('tk_abc123', $said, 'the credential does not');
        self::assertStringNotContainsString('scout-9f2ac', $said, 'nor the topic that addresses it');
    }

    /**
     * A listing the temp root's criteria accept: the drain RE-SCORES from the snapshot, and a row
     * today's criteria reject is left waiting rather than announced.
     */
    private function queueable(string $source, string $id): RawListing
    {
        return new RawListing(
            sourceName: $source,
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
    }

    /**
     * A REFUSED MAIL DRAINED NOTHING, AND THE REMAINDER SAID IT HAD.
     *
     * `digest` called `reportRemainder()` BEFORE `$notifier->send()`, and `overflow()` subtracted
     * every announced and rolled-up row unconditionally — so against a dead channel the verb
     * marked nothing, left the whole batch queued, and printed a remainder computed as though the
     * batch had gone. The adjacent *"rien n'a été marqué"* warning is why this is P2 rather than
     * P1, but a number that contradicts the warning beside it is how an operator learns to read
     * neither.
     *
     * The rent daily floor was already right — it counts after marking — so this is the repo's
     * *fix landing on one of two symmetric surfaces* once more, and the counterweight below pins
     * the delivered direction so the fix cannot be "always report everything as waiting".
     */
    public function testARefusedDigestMailReportsTheWholeBatchAsStillWaiting(): void
    {
        $root = $this->tempRoot();
        $this->seedDigestRow($root, $this->queueable('inli', 'PENDING-1'));

        $channel = new DeliveringChannel();
        $channel->refuses = [NotificationKind::DIGEST];
        $result = $this->scout($root, ['digest'], $channel);

        self::assertSame(1, $this->pendingDigestCount($root), 'premise: nothing was marked');
        self::assertStringContainsString(
            'autre(s) en attente',
            $result['out'] . $result['err'],
            'a refused mail drained nothing, and the remainder must say so',
        );
    }

    /** The counterweight: a DELIVERED mail really did drain the batch, and claims no remainder. */
    public function testADeliveredDigestMailClaimsNoRemainder(): void
    {
        $root = $this->tempRoot();
        $this->seedDigestRow($root, $this->queueable('inli', 'PENDING-1'));

        $result = $this->scout($root, ['digest'], new DeliveringChannel());

        self::assertSame(0, $this->pendingDigestCount($root), 'premise: it really drained');
        self::assertStringNotContainsString('autre(s) en attente', $result['out'], 'there is no suite');
    }

    private function pendingDigestCount(string $root): int
    {
        return Store::open($root . '/state/rent-watch.sqlite3')->pendingDigestCount();
    }

    private function seedQueuedMatch(string $root, RawListing $listing): string
    {
        $store = Store::open($root . '/state/rent-watch.sqlite3');
        $sighting = $store->record($listing, $listing->effectiveRentCc(), self::NOW);
        $store->recordVerdict($sighting->dedupKey, 'LLI', 90, ['champ structuré financement = « LLI »'], $listing);
        $store->recordOutcome($sighting->dedupKey, 'MATCH');

        return $sighting->dedupKey;
    }

    private function seedDigestRow(string $root, RawListing $listing, string $tenure = 'UNKNOWN'): string
    {
        $store = Store::open($root . '/state/rent-watch.sqlite3');
        $sighting = $store->record($listing, $listing->effectiveRentCc(), self::NOW);
        // `$tenure` defaults to UNKNOWN — the §1 landing zone proper. Pass a DETERMINED tenure to
        // seed the other entrance: a row digested with its regime already settled.
        $store->recordVerdict(
            $sighting->dedupKey,
            $tenure,
            $tenure === 'UNKNOWN' ? 0 : 90,
            [$tenure === 'UNKNOWN' ? 'aucun signal de régime' : 'loyer implausible pour la surface annoncée'],
            $listing,
        );
        $store->recordOutcome($sighting->dedupKey, 'DIGEST');

        return $sighting->dedupKey;
    }

    /**
     * Turns a row into the shape that has a verdict, an outcome and NO snapshot.
     *
     * This comment used to read *"the pre-v7 shape, exactly as production holds"* and that was
     * wrong twice over, caught by a review panel: a genuine pre-v7 row has `outcome` NULL as well
     * — `outcome` is a v7 column and is not backfilled either — so `pendingDigest()` never returns
     * one, and the seed below calls `recordOutcome()`, which no pre-v7 code ever did.
     *
     * The shape is real all the same, and since 2026-08-24 it is the one production reaches: a
     * listing whose own text is not valid UTF-8 cannot be snapshotted, so its verdict is stored
     * without one while its outcome is recorded normally.
     */
    /** A row on record with an EXCLUDED tenure and a snapshot — what `excludedDwellings()` returns. */
    private function seedExcludedDwelling(string $root, RawListing $listing, Tenure $tenure): string
    {
        $store = Store::open($root . '/state/rent-watch.sqlite3');
        $sighting = $store->record($listing, $listing->effectiveRentCc(), self::NOW);
        $store->recordVerdict($sighting->dedupKey, $tenure->value, 90, ['régime exclu relevé'], $listing);
        $store->recordOutcome($sighting->dedupKey, 'REJECT');

        return $sighting->dedupKey;
    }

    private function stripSnapshot(string $root, string $key): void
    {
        $this->pdo($root)
            ->prepare('UPDATE listings SET evidence_json = NULL WHERE dedup_key = :key')
            ->execute(['key' => $key]);
    }

    private function corruptSnapshot(string $root, string $key): void
    {
        $this->pdo($root)
            ->prepare('UPDATE listings SET evidence_json = :json WHERE dedup_key = :key')
            ->execute(['json' => '{not json at all', 'key' => $key]);
    }

    private function pdo(string $root): \PDO
    {
        $pdo = new \PDO('sqlite:' . $root . '/state/rent-watch.sqlite3');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return $pdo;
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
    private function scout(string $root, array $argv, ?DeliveringChannel $delivering = null): array
    {
        $out = fopen('php://memory', 'r+');
        $err = fopen('php://memory', 'r+');
        self::assertIsResource($out);
        self::assertIsResource($err);

        putenv('RENT_SCOUT_DB=' . $root . '/state/rent-watch.sqlite3');

        $code = (new RentScout($root, $out, $err, self::NOW, null, self::compose($out, $delivering)))->run($argv);
        rewind($out);
        rewind($err);

        return ['code' => $code, 'out' => (string) stream_get_contents($out), 'err' => (string) stream_get_contents($err)];
    }

    /** @param array<string,mixed> $criteria */
    private function tempRoot(array $criteria = []): string
    {
        $root = sys_get_temp_dir() . '/rentwatch-digest-' . bin2hex(random_bytes(8));
        mkdir($root . '/config/rent', 0o775, true);
        mkdir($root . '/state', 0o775, true);
        $this->roots[] = $root;

        file_put_contents($root . '/config/rent/criteria.json', json_encode($criteria + [
            'communes' => ['Sartrouville'],
            'postcode_prefixes' => ['78'],
            'min_rooms' => 3,
            'max_rent_cc' => 1800,
        ], JSON_THROW_ON_ERROR));

        $block = [
            'enabled' => false,
            'family' => 'institutional',
            'mixed_tenure' => true,
            'type' => 'fixture',
            'fixture' => 'tests/fixtures/rent/fixture_demo/search.json',
            'items_path' => 'results.items',
            'map' => ['ref' => 'id', 'title' => 'title'],
        ];

        file_put_contents($root . '/config/rent/sources.json', json_encode([
            'sources' => [
                'fixture_demo' => $block,
                // The two FAMILIES the twin collapse needs. `collapseTwins()` reads the family off
                // the config by source NAME and falls back to `private` for a name it does not
                // know — so a test seeding two unknown names would put both on one track, where
                // `twinReason()` refuses by design, and would pass while proving nothing.
                'inli' => $block,
                'seloger' => ['family' => 'private'] + $block,
            ],
        ], JSON_THROW_ON_ERROR));

        return $root;
    }

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
