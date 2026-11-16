<?php

declare(strict_types=1);

namespace Scout\Tests\Core;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Core\RunStore;
use Scout\Core\SourceStatus;
use Scout\Rent\Store\Store;

/**
 * TRACK 6-A1 — a failure rate that CLIMBS is invisible against a seven-day denominator.
 *
 * `WARN_FLAKY` was already here and already right about the phenomenon. What it was wrong about is
 * the TIMESCALE, and that was measured on the live watcher rather than reasoned:
 *
 *     In'li, 2026-09-01           runs  failed   rate    fires at > 30 %?
 *       last 24 h                  100      23   23.0 %   no
 *       last 7 d (the rule's)      706      58    8.2 %   nowhere near
 *
 * The daily rate went 2.0 % (08-29) → 11.3 % → 11.7 % → 22.8 % (09-01) while the seven-day
 * denominator held the reported figure at 8.2 %, and `doctor` said `ok` throughout — because the
 * interleaved successes still return ~165 items, so no other verdict can see it either. That is
 * hard rule 2's shape reached from a direction the existing rule averages away: **the window that
 * makes a sustained fault visible is the window that hides a climbing one.**
 *
 * The same class as `STALE` / `FEED_SILENT` — a correct rule whose blind spot is one axis of the
 * thing it measures — so the repair follows the same shape: a SECOND window beside the first, each
 * with its own ratio and its own minimum, and a detail line NAMING which one fired. Without the
 * name the operator cannot tell "failing hard today" from "failing steadily all week", which are
 * different faults with different responses.
 *
 * THE MINIMUM IS THE HALF THAT KEEPS IT HONEST. At ~100 passes a day a 24-hour window is dense;
 * on a cron-driven `--once` deployment it holds four runs, where a single failure is 25 % and would
 * alarm on a source doing nothing wrong. So the short rule requires a dense window and stays
 * deliberately inert on sparse deployments — the seven-day rule still covers those, which is the
 * counterweight that makes the minimum something other than a hole.
 */
#[CoversClass(RunStore::class)]
final class RunStoreFlakyWindowTest extends TestCase
{
    private Store $store;

    protected function setUp(): void
    {
        $this->store = Store::open(':memory:');
        $this->store->migrate();
    }

    /**
     * Lay down `$n` runs ending at `$endEpoch`, one every 15 minutes (the Q37 cadence), of which
     * the first `$failed` are failures.
     */
    private function passes(string $source, int $n, int $failed, int $endEpoch): void
    {
        for ($i = 0; $i < $n; ++$i) {
            $at = gmdate('Y-m-d\TH:i:s\Z', $endEpoch - ($n - 1 - $i) * 900);
            $isFailure = $i < $failed;
            $this->store->recordRun($source, $isFailure ? 0 : 165, !$isFailure, $isFailure ? 'inli: Connection timed out after 20002 milliseconds' : null, $at);
        }
    }

    /**
     * THE IN'LI SHAPE, to the measured numbers: 23 of the last 100 passes failed, while the
     * seven-day rate sits at 8.2 %. Before this rule the verdict was `ok`.
     */
    public function testASourceFailingAQuarterOfTodaysPassesIsFlakyEvenWhenTheWeekLooksFine(): void
    {
        $now = strtotime('2026-09-01T22:00:00Z');
        // Six quiet days first — 600 passes, 35 failures — so the 7-day rate stays far under 30 %.
        $this->passes('inli', 600, 35, $now - 86400 - 900);
        // Then today: 100 passes, 23 of them failed.
        $this->passes('inli', 100, 23, $now);

        $health = $this->store->runs()->health('inli', gmdate('Y-m-d\TH:i:s\Z', $now));

        self::assertSame(SourceStatus::WARN_FLAKY, $health->status);
        self::assertTrue($health->status->isAlerting());
        // The seven-day rule genuinely could not have fired — assert the premise, not just the
        // conclusion, or this test would still pass if someone simply lowered the long ratio.
        self::assertLessThan(
            RunStore::FLAKY_FAILURE_RATIO,
            $health->failedRunsInWindow / $health->runsInWindow,
            'the seven-day rate must be under the long threshold, or this proves nothing about the short window',
        );
    }

    /**
     * The detail line NAMES the window. Two faults, two responses: a source failing hard today is
     * a live incident, one failing steadily all week is a configuration or a host to replace.
     */
    public function testTheDetailSaysWhichWindowFired(): void
    {
        $now = strtotime('2026-09-01T22:00:00Z');
        $this->passes('inli', 600, 35, $now - 86400 - 900);
        $this->passes('inli', 100, 23, $now);

        $detail = $this->store->runs()->health('inli', gmdate('Y-m-d\TH:i:s\Z', $now))->detail;

        self::assertStringContainsString('24 h', $detail, 'the short-window verdict must name its window');
        // And it must carry the SEVEN-DAY figure beside it. Without the contrast the reader cannot
        // tell this verdict from the long one, and the contrast IS the finding: the weekly average
        // is the number that looked fine while the source degraded.
        self::assertStringContainsString('7 jours', $detail);
        self::assertMatchesRegularExpression('~\d+ échecs sur \d+ runs sur les dernières 24 h~', $detail);
        self::assertStringContainsString('dégradation récente', $detail);
    }

    /**
     * THE COUNTERWEIGHT, measured on the same day across eleven sources in two domains: In'li was
     * at 23.0 %, cdc_habitat at 3.0 %, car leboncoin at 1.0 % and the other eight at 0.0 %. A
     * verdict that fires on everything is indistinguishable from one that fires on nothing.
     */
    public function testTheBusiestHealthySourceOfTheSameDayIsNotFlagged(): void
    {
        $now = strtotime('2026-09-01T22:00:00Z');
        $this->passes('cdc_habitat', 600, 12, $now - 86400 - 900);
        $this->passes('cdc_habitat', 100, 3, $now);   // 3.0 % — the real figure

        self::assertSame(SourceStatus::OK, $this->store->runs()->health('cdc_habitat', gmdate('Y-m-d\TH:i:s\Z', $now))->status);
    }

    /**
     * A cron-driven `--once` deployment polls four times a day. One failure of four is 25 % — over
     * any rate threshold worth having — and means nothing at all. The short rule requires a dense
     * window, and says so.
     */
    public function testOneFailureOnASparseDeploymentIsNotAnIncident(): void
    {
        $now = strtotime('2026-09-01T22:00:00Z');

        // The hour offset is LOAD-BEARING, and it was missing in the first draft of this test. On a
        // flat six-hour grid the day-1 run lands EXACTLY on the 24 h cutoff, which the window
        // includes — so the short window held five runs, one failure read 20 %, and the rule's
        // `> 0.2` declined on the ratio rather than on the minimum. The test passed for arithmetic
        // reasons and proved nothing: the sabotage ledger caught it as UNDETECTED when
        // `MIN_RUNS_FOR_SHORT_FLAKY` was dropped to 1 and the suite stayed green. Offset by an hour
        // the window holds exactly the four passes of one day, one failure is an unambiguous 25 %,
        // and only the minimum can be what refuses.
        for ($d = 6; $d >= 0; --$d) {
            for ($h = 0; $h < 4; ++$h) {
                $at = gmdate('Y-m-d\TH:i:s\Z', $now - $d * 86400 - $h * 21600 - 3600);
                $failed = $d === 0 && $h === 0;
                $this->store->recordRun('pap', $failed ? 0 : 9, !$failed, $failed ? 'timeout' : null, $at);
            }
        }

        $health = $this->store->runs()->health('pap', gmdate('Y-m-d\TH:i:s\Z', $now));

        // Assert the PREMISE as well as the conclusion: a rate over the threshold, refused only
        // because the window is too thin to mean anything.
        self::assertSame(SourceStatus::OK, $health->status);
    }

    /**
     * And the seven-day rule is untouched — asserted separately, because "add a short window" is
     * exactly the change that quietly replaces the long one. A sparse deployment failing steadily
     * is still caught, which is what makes the minimum above a scope limit rather than a hole.
     */
    public function testTheSevenDayRuleStillCatchesASparseSourceFailingSteadily(): void
    {
        $now = strtotime('2026-09-01T22:00:00Z');
        for ($d = 6; $d >= 0; --$d) {
            for ($h = 0; $h < 4; ++$h) {
                $at = gmdate('Y-m-d\TH:i:s\Z', $now - $d * 86400 - $h * 21600);
                $failed = $h < 2;   // half of every day, for a week
                $this->store->recordRun('pap', $failed ? 0 : 9, !$failed, $failed ? 'HTTP 502' : null, $at);
            }
        }

        $health = $this->store->runs()->health('pap', gmdate('Y-m-d\TH:i:s\Z', $now));

        self::assertSame(SourceStatus::WARN_FLAKY, $health->status);
        self::assertStringContainsString('7 jours', $health->detail);
    }

    /**
     * The short window must be bounded ABOVE by the clock, exactly as the long one is. A
     * future-stamped run has not happened yet, and a batch of them inflating the denominator is
     * the documented way this class's ratio was silenced before.
     */
    public function testFutureStampedSuccessesCannotDiluteTheShortWindow(): void
    {
        $now = strtotime('2026-09-01T22:00:00Z');
        $this->passes('inli', 600, 35, $now - 86400 - 900);
        $this->passes('inli', 100, 23, $now);
        // A hundred successes stamped a year ahead.
        $this->passes('inli', 100, 0, $now + 365 * 86400);

        self::assertSame(
            SourceStatus::WARN_FLAKY,
            $this->store->runs()->health('inli', gmdate('Y-m-d\TH:i:s\Z', $now))->status,
        );
    }
}
