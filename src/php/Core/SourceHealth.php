<?php

declare(strict_types=1);

namespace Scout\Core;

/**
 * The health verdict for one source, derived from its recorded run history.
 *
 * Every field beyond `status` exists so the verdict can be argued with. `CLAUDE.md` hard rule 2 asks
 * for last-success, last-count and a rolling mean to be persisted; carrying them here means
 * `scout doctor` can show WHY a source is `BROKEN` instead of asserting it, and a false alarm is
 * diagnosable without opening the database.
 *
 * The failure fields were added after a reviewer pointed out the obvious: a source failing half its
 * fetches has no way to say so, because `status` reads only the LAST run and the streak resets on
 * any success. Data that cannot reach the verdict object cannot reach the user either.
 */
/**
 * TWO OF THESE FIELDS ARE COUNTED OVER DIFFERENT SETS OF RUNS, and that is deliberate rather than an
 * oversight (2026-09-07). `totalRuns`, `runsInWindow` and `failedRunsInWindow` count ATTEMPTS, so
 * they read the whole log — a failed run is a perfectly good attempt, and the flaky verdicts depend
 * on it. `consecutiveEmptyRuns`, `lastCount` and `rollingMean` count OBSERVATIONS, so they read the
 * log with tolerated failure episodes removed: a failed run's `item_count` of 0 is unknown, not
 * "zero annonces" (hard rule 9). A reader comparing `totalRuns` with `consecutiveEmptyRuns` and
 * finding they do not add up is seeing the design, not a bug. {@see RunStore::observedRuns()}.
 */
final readonly class SourceHealth
{
    /**
     * @param string      $sourceName           key in the sources config
     * @param string      $detail               human-readable justification, in French, for the status
     * @param int         $consecutiveEmptyRuns trailing runs that succeeded and returned nothing
     * @param string|null $lastSuccessAt        ISO-8601 timestamp of the last run that succeeded
     * @param string|null $lastFailureAt        ISO-8601 timestamp of the last run that failed
     * @param int|null    $lastCount            items returned by the most recent run
     * @param float|null  $rollingMean          mean item count over the rolling window, excluding the
     *                                          most recent run; null when there is nothing to average
     * @param int         $runsInWindow         runs recorded within the rolling window, inclusive
     * @param int         $failedRunsInWindow   how many of those failed
     * @param int         $totalRuns            runs on record, successful or not
     */
    public function __construct(
        public string $sourceName,
        public SourceStatus $status,
        public string $detail = '',
        public int $consecutiveEmptyRuns = 0,
        public ?string $lastSuccessAt = null,
        public ?string $lastFailureAt = null,
        public ?int $lastCount = null,
        public ?float $rollingMean = null,
        public int $runsInWindow = 0,
        public int $failedRunsInWindow = 0,
        public int $totalRuns = 0,
    ) {}

    /** Share of the runs in the rolling window that failed, or null when the window is empty. */
    public function failureRate(): ?float
    {
        return $this->runsInWindow === 0 ? null : $this->failedRunsInWindow / $this->runsInWindow;
    }
}
