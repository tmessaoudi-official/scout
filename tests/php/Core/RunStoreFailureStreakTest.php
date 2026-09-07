<?php

declare(strict_types=1);

namespace Scout\Tests\Core;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Core\RunStore;
use Scout\Core\SourceStatus;
use Scout\Rent\Store\Store;

/**
 * A SINGLE FAILED RUN IS NOT A BROKEN SOURCE — measured on the live watcher, 2026-09-07.
 *
 * `if (!$lastOk) return BROKEN` fired on ONE failed run, while the empty path had required three
 * since it was written. `alertOnHealth()` then read the next successful pass as recovery, sent
 * *rétablie* and CLEARED the cooldown row, so the cooldown could never damp anything. Measured over
 * four days: in'li alone sent **29 broken + 30 rétablie**, out of 428 runs, while returning 165
 * annonces on the passes either side. The cause was in'li's, not ours — 44 of its 59 failures are
 * an HTTP 302 to its own `/maintenance`, and cityloger, logirep and seloger failed 0 times in 632+
 * runs through the identical stack.
 *
 * THE FIX IS NOT A THRESHOLD, and a threshold alone would have moved the noise rather than removed
 * it. Two branches downstream read the failed run's `item_count` of **0** as an observation:
 *
 *   - `WARN_DROP` fires at `lastCount < rollingMean * 0.3`, and 0 < 49.5 for a mean of 165. It is
 *     alerting, so the same flap continues under a different subject line.
 *   - `!isAlerting()` reads as RECOVERY. A source with a real standing alert — leboncoin's
 *     empty-streak `BROKEN` — that suffers one isolated timeout would announce *rétablie* for a
 *     condition that never changed, and re-alert on the next pass with its cooldown wiped.
 *
 * Both are hard rule 9 at the health layer: a failed run's zero is **unknown**, not *zero annonces*
 * — and it is no more evidence of recovery than it is of a drop. `rollingMeanBefore()` already knew
 * this and filters on `ok = 1`; nothing else did.
 *
 * So a trailing failure below the threshold is not an observation, and the count-based verdicts
 * judge the log with those runs REMOVED. `STALE` and `WARN_FLAKY` keep the whole log on purpose:
 * they are about ATTEMPTS, and a failure is a perfectly good attempt. That is also what stops a
 * single hiccup resetting a long empty streak, which `trailingEmptyRuns()` did.
 *
 * The strip needs something behind it: a source whose entire history is failures has no observation
 * to fall back on, and reporting OK there would hide a misconfigured source on its first day.
 */
#[CoversClass(RunStore::class)]
final class RunStoreFailureStreakTest extends TestCase
{
    private Store $store;

    protected function setUp(): void
    {
        $this->store = Store::open(':memory:');
        $this->store->migrate();
    }

    /**
     * Lay down runs at the Q37 cadence, one every 15 minutes, ENDING at `$endEpoch`.
     *
     * @param list<array{int, bool}> $spec one [itemCount, ok] per run, oldest first
     */
    private function seed(string $source, array $spec, int $endEpoch): void
    {
        $n = \count($spec);

        foreach ($spec as $i => [$count, $ok]) {
            $this->store->recordRun(
                $source,
                $count,
                $ok,
                $ok ? null : 'inli: HTTP 302 from https://www.inli.fr/locations/offres/ile-de-france-region_r:11 → Location: /maintenance',
                gmdate('Y-m-d\TH:i:s\Z', $endEpoch - ($n - 1 - $i) * 900),
            );
        }
    }

    /** @return list<array{int, bool}> */
    private static function healthy(int $n): array
    {
        return array_fill(0, $n, [165, true]);
    }

    /**
     * THE IN'LI SHAPE. One 302 in a run of healthy passes must not reach the developer's phone.
     *
     * Asserted on `isAlerting()` rather than on a named status, because the status this used to
     * return and the status a threshold ALONE would return are different (`BROKEN`, then
     * `WARN_DROP`) and both are wrong in the same way. Naming either one would let the test pass
     * against half a fix.
     */
    public function testOneFailedRunAmongHealthyPassesDoesNotAlert(): void
    {
        $now = strtotime('2026-09-07T09:00:00Z');
        $this->seed('inli', [...self::healthy(20), [0, false]], $now);

        $health = $this->store->health('inli', gmdate('Y-m-d\TH:i:s\Z', $now));

        self::assertFalse(
            $health->status->isAlerting(),
            'a single failed run among healthy passes must not alert, and got: ' . $health->status->value . ' — ' . $health->detail,
        );
    }

    /**
     * THE COUNTERWEIGHT, and it is the half that keeps the rule from being "never alert". Green
     * before this change and after it — it is not the red test, it is what stops the fix becoming
     * a hole.
     */
    public function testThreeConsecutiveFailedRunsStillReportBroken(): void
    {
        $now = strtotime('2026-09-07T09:00:00Z');
        $this->seed('inli', [...self::healthy(20), [0, false], [0, false], [0, false]], $now);

        $health = $this->store->health('inli', gmdate('Y-m-d\TH:i:s\Z', $now));

        self::assertSame(SourceStatus::BROKEN, $health->status);
    }

    /**
     * A source whose WHOLE history is failures has no observation behind the strip. Reporting OK
     * here would hide a source that was misconfigured the day it was added — the failure has to be
     * survivable, not invisible.
     */
    public function testASourceThatHasOnlyEverFailedIsBrokenOnItsFirstRun(): void
    {
        $now = strtotime('2026-09-07T09:00:00Z');
        $this->seed('newcomer', [[0, false]], $now);

        $health = $this->store->health('newcomer', gmdate('Y-m-d\TH:i:s\Z', $now));

        self::assertSame(SourceStatus::BROKEN, $health->status);
    }

    /**
     * The advisor's case, and the one that makes this more than a threshold: a STANDING alert must
     * survive a hiccup. Without it the isolated failure reads as recovery, `clearAlerts()` fires
     * *rétablie* for a condition that never changed, and the cooldown it wipes lets the real alert
     * fire again immediately.
     */
    public function testAStandingDropWarningSurvivesOneIsolatedFailure(): void
    {
        $now = strtotime('2026-09-07T09:00:00Z');
        $this->seed('inli', [...self::healthy(20), [10, true], [0, false]], $now);

        $health = $this->store->health('inli', gmdate('Y-m-d\TH:i:s\Z', $now));

        self::assertSame(SourceStatus::WARN_DROP, $health->status, 'a real drop must not be announced as recovered by a later failure');
    }

    /**
     * A genuine collapse in supply is still a `WARN_DROP`. The rule above removes a failed run's
     * zero from the count-based verdicts; it must not remove a SUCCESSFUL run's low count, which is
     * the observation `WARN_DROP` exists to read.
     */
    public function testALowButSuccessfulCountStillWarns(): void
    {
        $now = strtotime('2026-09-07T09:00:00Z');
        $this->seed('inli', [...self::healthy(20), [10, true]], $now);

        $health = $this->store->health('inli', gmdate('Y-m-d\TH:i:s\Z', $now));

        self::assertSame(SourceStatus::WARN_DROP, $health->status);
    }

    /**
     * THE LEBONCOIN SHAPE. A long empty streak is a real diagnosis; one failed run in the middle of
     * it must not reset the count back to zero and buy the dead source another three passes of
     * silence. `trailingEmptyRuns()` breaks on a failed run, so before this change it did.
     */
    public function testAnIsolatedFailureDoesNotResetALongEmptyStreak(): void
    {
        $now = strtotime('2026-09-07T09:00:00Z');
        $this->seed('leboncoin', [
            ...self::healthy(10),
            ...array_fill(0, 12, [0, true]),
            [0, false],
        ], $now);

        $health = $this->store->health('leboncoin', gmdate('Y-m-d\TH:i:s\Z', $now));

        self::assertSame(SourceStatus::BROKEN, $health->status);
        self::assertStringContainsString('runs consécutifs à vide', $health->detail);
    }

    /**
     * THE CASE THE FIXTURES COULD NOT REACH, found by running the new verdict against a copy of the
     * live car store on 2026-09-07 — not by any test.
     *
     * leboncoin's failure sat THREE runs from the end, so a trailing-only rule still let it truncate
     * a 308-run empty streak to 3. That is not cosmetic: the streak rebuilding through 1 and 2
     * reports OK, and OK is *rétablie* plus a wiped cooldown, then BROKEN again at 3 — the flap the
     * whole change exists to remove, surviving on the one source it was not about.
     *
     * A tolerated failure is therefore dropped WHEREVER it sits, and the streak spans it.
     */
    public function testAnInteriorFailureDoesNotTruncateALongEmptyStreak(): void
    {
        $now = strtotime('2026-09-07T09:00:00Z');
        $this->seed('leboncoin', [
            ...self::healthy(5),
            ...array_fill(0, 12, [0, true]),
            [0, false],
            ...array_fill(0, 3, [0, true]),
        ], $now);

        $health = $this->store->health('leboncoin', gmdate('Y-m-d\TH:i:s\Z', $now));

        self::assertSame(SourceStatus::BROKEN, $health->status);
        self::assertSame(15, $health->consecutiveEmptyRuns, 'the streak spans the tolerated failure rather than restarting after it');
    }

    /**
     * THE COUNTERWEIGHT to the case above, and it is what stops "tolerate" becoming "ignore": a
     * failure episode AT the threshold is a real outage, is kept, and breaks the streak exactly as
     * it always did. Without this, dropping every failure everywhere would pass the test above.
     */
    public function testARealOutageStillBreaksTheEmptyStreak(): void
    {
        $now = strtotime('2026-09-07T09:00:00Z');
        $this->seed('leboncoin', [
            ...self::healthy(5),
            ...array_fill(0, 12, [0, true]),
            ...array_fill(0, 3, [0, false]),
            ...array_fill(0, 3, [0, true]),
        ], $now);

        $health = $this->store->health('leboncoin', gmdate('Y-m-d\TH:i:s\Z', $now));

        self::assertSame(3, $health->consecutiveEmptyRuns, 'three consecutive failures are an outage, not a hiccup, and the streak restarts after them');
    }

    /**
     * THE BASELINE IS MEASURED FROM THE RUN IMMEDIATELY BEFORE THE STREAK, and a tolerated failure
     * must not shift that index.
     *
     * `$emptyStreak` is counted in `$observed`, so `$streakStart` has to be counted there too.
     * Indexing it into `$runs` lands one row late and averages a run the streak already contains —
     * a real defect, made and caught while writing this change, which reported a 25-listing
     * baseline as 12.5.
     *
     * IT NEEDS ITS OWN FIXTURE. The existing case that caught it has a month-long gap, so under a
     * one-sided mutation the rolling window comes back empty and `lastProductiveCount()` rescues
     * the answer — the ledger case reported detection it did not have until this test existed.
     * Here every run is inside the window, so the shift changes the number instead of erasing it.
     */
    public function testAToleratedFailureDoesNotShiftTheBaselineWindow(): void
    {
        $now = strtotime('2026-09-07T09:00:00Z');
        $this->seed('inli', [[25, true], [30, true], [0, false], [0, true], [0, true], [0, true]], $now);

        $health = $this->store->health('inli', gmdate('Y-m-d\TH:i:s\Z', $now));

        self::assertSame(SourceStatus::BROKEN, $health->status);
        // 27.5 is the mean of the two PRODUCTIVE runs before the streak. One row late it is 18.3,
        // because the first empty run of the streak joins its own baseline.
        self::assertStringContainsString('27.5', $health->detail, 'the baseline must not include a run the streak already contains');
    }

    /**
     * `doctor` must not say "165 annonces au dernier run" when the last run FAILED. The count is
     * real and it is the last one observed — the detail line has to say that is what it is, or this
     * change buys quiet by making the operator's own instrument lie.
     */
    public function testTheDetailAdmitsTheFailureItIsTolerating(): void
    {
        $now = strtotime('2026-09-07T09:00:00Z');
        $this->seed('inli', [...self::healthy(20), [0, false]], $now);

        $health = $this->store->health('inli', gmdate('Y-m-d\TH:i:s\Z', $now));

        self::assertSame(SourceStatus::OK, $health->status);
        self::assertStringContainsString('échec', $health->detail);
        self::assertNotNull($health->lastFailureAt, 'the failure stays on the record even when it is tolerated');
    }
}
