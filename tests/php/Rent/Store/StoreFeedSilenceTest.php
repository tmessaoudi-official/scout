<?php

declare(strict_types=1);

namespace Scout\Tests\Rent\Store;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Core\SourceStatus;
use Scout\Rent\Store\Store;

/**
 * A source can keep REPORTING while its feed has stopped DELIVERING, and nothing saw it.
 *
 * **Measured on the live watcher, 2026-08-28.** `leboncoin` had reported `item_count = 3` on 263
 * consecutive passes. Behind that steady figure was ONE email, dated 26 August: the source was
 * re-reading the same message every fifteen minutes because `SEARCH SINCE 7 days` kept matching it.
 * Every existing verdict said healthy, and each was right on its own terms — the baseline is 3, the
 * last count is 3, so there is no drop; no run failed, so nothing is flaky; the schedule never
 * stopped, so it is not `STALE`. `CLAUDE.md` hard rule 2's exact shape, reached without a single
 * `catch` and with no test able to see it.
 *
 * **It self-corrects in the worst possible way.** On or about 2 September the 26 August message
 * falls out of the `SEARCH SINCE` window, the count goes 3 → 0 with nothing changing in the world,
 * and `BROKEN` finally fires naming the wrong day and the wrong cause — a week after the fact.
 *
 * **Why the obvious signal is the wrong one, and this is the whole design.** *"Warn when no NEW
 * listing has arrived for N days"* is exactly what a quiet rental market looks like, so it restates
 * hard rule 2's ambiguity instead of resolving it: Logirep legitimately returns the same 113
 * listings on every pass. The age of the newest MESSAGE is different in kind — it is a fact about
 * whether the portal sent anything, never an inference about the market. *"leboncoin has not sent
 * anything in three days"* is checkable; *"no new listings"* is not.
 */
#[CoversClass(Store::class)]
final class StoreFeedSilenceTest extends TestCase
{
    private Store $store;

    protected function setUp(): void
    {
        $this->store = Store::open(':memory:');
    }

    /**
     * The leboncoin case, reproduced: a steady non-zero count off one ageing message.
     *
     * Every figure here is the live one. Three listings a pass, from a message dated 26 August,
     * judged on 29 August — the day a three-day threshold first bites, and four days before the
     * count would have collapsed on its own.
     */
    public function testASteadyCountOffAnAgeingMessageIsFeedSilentRatherThanOk(): void
    {
        for ($i = 0; $i < 20; ++$i) {
            $this->store->recordRun(
                sourceName: 'leboncoin',
                itemCount: 3,
                ok: true,
                error: null,
                atIso: sprintf('2026-08-%02dT%02d:00:00Z', 26 + intdiv($i, 8), $i % 8 + 8),
                feedNewestAt: '2026-08-26T07:33:06Z',
            );
        }

        $health = $this->store->health('leboncoin', '2026-08-29T09:00:00Z', 3);

        self::assertSame(SourceStatus::FEED_SILENT, $health->status);
        self::assertStringContainsString('26', $health->detail, 'the detail must name the last message');
        self::assertTrue($health->status->isAlerting(), 'a silent feed that never reaches the user is worthless');
    }

    /** Inside the threshold the feed is simply quiet, and quiet is not broken. */
    public function testAMessageInsideTheThresholdIsStillOk(): void
    {
        $this->store->recordRun('leboncoin', 3, true, null, '2026-08-28T09:00:00Z', feedNewestAt: '2026-08-27T07:33:06Z');

        self::assertSame(SourceStatus::OK, $this->store->health('leboncoin', '2026-08-28T09:00:00Z', 3)->status);
    }

    /**
     * Hard rule 9 at the health layer: an unknown message date is UNKNOWN, never old.
     *
     * `FileMailbox` reports `null` on purpose — a directory of frozen fixtures is not a feed — and
     * every row written before schema v11 reads `null` too. Either read as "old" would turn the
     * documented `MAILBOX_DIR=…` workflow, and the entire historic run log, into a permanent alert.
     */
    public function testAnUnknownMessageDateYieldsNoVerdict(): void
    {
        // Judged three days after the run — past the threshold, and well inside the rolling window
        // so `STALE` (the schedule stopped) cannot fire and steal the verdict. A first draft used a
        // month, which tripped STALE and proved nothing about this branch.
        $this->store->recordRun('inli', 3, true, null, '2026-08-29T09:00:00Z', feedNewestAt: null);

        self::assertSame(SourceStatus::OK, $this->store->health('inli', '2026-09-01T09:00:00Z', 3)->status);
    }

    /**
     * A FUTURE-dated message must not mask silence for ever.
     *
     * The verdict reduces every reported date to their maximum, so one portal with a skewed clock —
     * or one message stamped 2030 — would otherwise WIN that maximum and report the feed as fresh
     * for ever, suppressing the only signal that distinguishes a dead alert from a quiet market.
     * `Core/Heartbeat`'s bias applies unchanged: one alert too many, never one suppressed.
     */
    public function testAFutureDatedMessageCannotMaskAnAgeingFeed(): void
    {
        // The real shape of the risk, and the one a first draft missed: the verdict reduces many
        // reported dates to their MAXIMUM, so a single bogus 2030 stamp beside a genuine 26 August
        // one wins that maximum and reports the feed as fresh for ever. Clamping the bogus date to
        // `now` does not help — it makes it read as fresher still.
        $this->store->recordRun('leboncoin', 3, true, null, '2026-08-27T09:00:00Z', feedNewestAt: '2026-08-26T07:33:06Z');
        $this->store->recordRun('leboncoin', 3, true, null, '2026-08-29T09:00:00Z', feedNewestAt: '2030-01-01T00:00:00Z');

        $health = $this->store->health('leboncoin', '2026-08-29T09:00:00Z', 3);

        self::assertSame(SourceStatus::FEED_SILENT, $health->status);
        self::assertStringContainsString('26', $health->detail, 'the credible date decides, not the newest');
        self::assertStringNotContainsString('2030', $health->detail);
    }

    /**
     * When NOTHING reported is believable, say so rather than fall silent.
     *
     * A portal with a permanently fast clock would otherwise disable this verdict for ever, which is
     * the suppressed direction `Core/Heartbeat` rules against. The existing `STALE` branch says the
     * same thing about run timestamps; this mirrors it for message dates.
     */
    public function testAllFutureDatesAreReportedRatherThanIgnored(): void
    {
        $this->store->recordRun('leboncoin', 3, true, null, '2026-08-29T09:00:00Z', feedNewestAt: '2030-01-01T00:00:00Z');

        $health = $this->store->health('leboncoin', '2026-08-29T09:00:00Z', 3);

        self::assertSame(SourceStatus::FEED_SILENT, $health->status);
        self::assertStringContainsString('horloge', $health->detail);
    }

    /**
     * A silent feed is a verdict about a WORKING source, so it never outranks a broken one.
     *
     * The failure paths know a cause; this one only knows an absence. Reporting `FEED_SILENT` on a
     * source whose last run threw would name the symptom and bury the exception.
     */
    public function testAFailedRunOutranksASilentFeed(): void
    {
        $this->store->recordRun('leboncoin', 3, true, null, '2026-08-27T09:00:00Z', feedNewestAt: '2026-08-26T07:33:06Z');

        // THREE failures, not one, since 2026-09-07: a single failed run is tolerated rather than
        // announced. The ordering this test exists for is unchanged — once the failure is a verdict
        // at all, it outranks the silence.
        foreach (['2026-08-29T07:00:00Z', '2026-08-29T08:00:00Z', '2026-08-29T09:00:00Z'] as $at) {
            $this->store->recordRun('leboncoin', 0, false, 'IMAP connection timed out', $at, feedNewestAt: null);
        }

        self::assertSame(SourceStatus::BROKEN, $this->store->health('leboncoin', '2026-08-29T09:00:00Z', 3)->status);
    }

    /**
     * THE OTHER HALF, and the one a tolerated failure could quietly take away: below the threshold
     * the verdict is the feed's, but the exception is NOT buried. The note rides on every verdict,
     * not just on OK — the first cut put it in the OK branch alone and this case is what caught it.
     */
    public function testAToleratedFailureIsStillNamedOnASilentFeedVerdict(): void
    {
        $this->store->recordRun('leboncoin', 3, true, null, '2026-08-27T09:00:00Z', feedNewestAt: '2026-08-26T07:33:06Z');
        $this->store->recordRun('leboncoin', 0, false, 'IMAP connection timed out', '2026-08-29T09:00:00Z', feedNewestAt: null);

        $health = $this->store->health('leboncoin', '2026-08-29T09:00:00Z', 3);

        self::assertSame(SourceStatus::FEED_SILENT, $health->status);
        self::assertStringContainsString('toléré', $health->detail, 'a tolerated failure must not vanish behind another verdict');
    }

    /**
     * When the count DOES collapse, the detail names the day the feed actually went quiet.
     *
     * This is the other half of the leboncoin fix and it is the one that survives the threshold
     * being wrong. Even if `FEED_SILENT` never fires, `BROKEN` must stop blaming the day the window
     * expired: the message is three days older than the collapse, and the operator needs the former.
     */
    public function testABrokenSourceNamesTheLastMessageItSaw(): void
    {
        $this->store->recordRun('leboncoin', 3, true, null, '2026-08-27T09:00:00Z', feedNewestAt: '2026-08-26T07:33:06Z');

        for ($i = 0; $i < 4; ++$i) {
            $this->store->recordRun('leboncoin', 0, true, null, sprintf('2026-09-0%dT09:00:00Z', 2 + $i), feedNewestAt: null);
        }

        $health = $this->store->health('leboncoin', '2026-09-06T09:00:00Z', 3);

        self::assertSame(SourceStatus::BROKEN, $health->status);
        self::assertStringContainsString('26', $health->detail, 'BROKEN must name the last message, not just the empty streak');
    }

    /**
     * A ZERO-COUNT run belongs to the empty-streak rule, not to this one — and nothing pinned that.
     *
     * Widening the gate to `>= 0` let `FEED_SILENT` preempt the `BROKEN` verdict the comment says
     * owns the zero case, with the suite green: the only zero-count test had a four-run streak, so
     * `BROKEN` fired from the earlier branch regardless and the gate was never the deciding factor.
     * Here the streak is ONE run, so the empty-streak rule declines and the gate alone decides.
     */
    public function testAZeroCountRunIsNotJudgedOnFeedSilence(): void
    {
        $this->store->recordRun('leboncoin', 3, true, null, '2026-08-27T09:00:00Z', feedNewestAt: '2026-08-26T07:33:06Z');
        $this->store->recordRun('leboncoin', 0, true, null, '2026-08-29T09:00:00Z', feedNewestAt: '2026-08-26T07:33:06Z');

        self::assertNotSame(
            SourceStatus::FEED_SILENT,
            $this->store->health('leboncoin', '2026-08-29T09:00:00Z', 3)->status,
            'at zero items the empty-streak rule owns the verdict',
        );
    }

    /**
     * An unreadable feed date is refused AT WRITE TIME, beside the caller that produced it.
     *
     * Dead safety code until this test: no caller ever wrote an invalid one, so deleting the
     * validation left the suite green. Deferring it to `health()` would turn an unreadable date
     * into a permanent ABSENCE of verdict — the source would look watched and be unwatched.
     */
    public function testAnUnreadableFeedDateIsRefusedWhenItIsWritten(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->store->recordRun('leboncoin', 3, true, null, '2026-08-29T09:00:00Z', feedNewestAt: 'la semaine dernière');
    }

    /**
     * `max()` over feed dates must compare INSTANTS, not strings.
     *
     * The store accepts any RFC 3339 offset — its own CLI test writes `+02:00` — so a lexical
     * maximum picks the wrong element across mixed offsets. Here the FIRST value is the newer
     * instant by 25 hours and sorts lexically LAST, so a string comparison reports a feed one hour
     * old as a day silent, naming the wrong message.
     */
    public function testTheNewestFeedDateIsChosenByInstantNotByString(): void
    {
        // THE SPREAD HAS TO CROSS THE THRESHOLD, and a first version of this test missed that: its
        // two dates were three hours apart against a one-day threshold, so the correct answer and
        // the broken one were BOTH `OK` and the sabotage went undetected. The offsets have to be
        // pushed to the ends of the range (-12:00 and +14:00, 26 hours apart) so the lexical pick
        // lands on the far side of the threshold from the true one.
        $newerInstant = '2026-08-29T23:00:00-12:00'; // 2026-08-30T11:00Z — ONE HOUR old
        $olderInstant = '2026-08-30T00:00:00+14:00'; // 2026-08-29T10:00Z — 26 HOURS old, sorts LATER

        $this->store->recordRun('portalX', 3, true, null, '2026-08-30T11:30:00Z', feedNewestAt: $newerInstant);
        $this->store->recordRun('portalX', 3, true, null, '2026-08-30T11:40:00Z', feedNewestAt: $olderInstant);

        $health = $this->store->health('portalX', '2026-08-30T12:00:00Z', 1);

        self::assertSame(SourceStatus::OK, $health->status, 'the feed is one hour old, not 26 hours silent');
    }

    /**
     * A message stamped slightly AFTER the pass began is pacing, not a broken clock.
     *
     * `Pipeline::runOnce()` captures one `$nowIso` at pass start, and Q37 pacing puts 5 s between
     * hosts and 60 s per host — so a source polled minutes later legitimately sees a message
     * stamped after that instant. Without a grace, a healthy source's FIRST pass reported
     * "vérifiez l'horloge du portail" on a source that had just delivered, on a fresh database,
     * which is exactly when someone is watching a `--seed` run.
     */
    public function testASmallForwardSkewIsPacingRatherThanABrokenClock(): void
    {
        $this->store->recordRun('bienici', 5, true, null, '2026-08-29T09:00:00Z', feedNewestAt: '2026-08-29T09:00:30Z');

        self::assertSame(SourceStatus::OK, $this->store->health('bienici', '2026-08-29T09:00:00Z', 3)->status);
    }

    /** The grace is far below any threshold, so it can never mask real silence. */
    public function testTheGraceDoesNotExtendToAnImplausibleFutureDate(): void
    {
        $this->store->recordRun('bienici', 5, true, null, '2026-08-29T09:00:00Z', feedNewestAt: '2030-01-01T00:00:00Z');

        self::assertSame(SourceStatus::FEED_SILENT, $this->store->health('bienici', '2026-08-29T09:00:00Z', 3)->status);
    }

    /**
     * The BROKEN detail must name a date the verdict would BELIEVE.
     *
     * It used an unfiltered `max()` while the verdict filtered, so a single 2030-stamped message
     * would have been printed to the operator as the portal's last message — the very value
     * `testAFutureDatedMessageCannotMaskAnAgeingFeed` exists to reject, surfacing on another branch.
     * A diagnostic that contradicts its own conclusion is worse than none.
     */
    public function testTheBrokenDetailNamesACredibleDateNotAFutureOne(): void
    {
        $this->store->recordRun('leboncoin', 3, true, null, '2026-08-27T09:00:00Z', feedNewestAt: '2026-08-26T07:33:06Z');
        $this->store->recordRun('leboncoin', 3, true, null, '2026-08-27T10:00:00Z', feedNewestAt: '2030-01-01T00:00:00Z');

        for ($i = 0; $i < 4; ++$i) {
            $this->store->recordRun('leboncoin', 0, true, null, sprintf('2026-09-0%dT09:00:00Z', 2 + $i));
        }

        $health = $this->store->health('leboncoin', '2026-09-06T09:00:00Z', 3);

        self::assertSame(SourceStatus::BROKEN, $health->status);
        self::assertStringContainsString('2026-08-26', $health->detail);
        self::assertStringNotContainsString('2030', $health->detail);
    }

    /** A threshold of zero disables the one signal that distinguishes a dead alert from a quiet market. */
    public function testAThresholdOfZeroIsRefused(): void
    {
        $this->store->recordRun('leboncoin', 3, true, null, '2026-08-29T09:00:00Z', feedNewestAt: '2026-08-26T07:33:06Z');

        $this->expectException(\InvalidArgumentException::class);
        $this->store->health('leboncoin', '2026-08-29T09:00:00Z', 0);
    }
}
