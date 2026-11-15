<?php

declare(strict_types=1);

namespace Scout\Core;

/**
 * A source's health, computed in ONE place from the run log and the source's own telemetry (B-11).
 *
 * Every counting source used to write `escalate(<run log>->health(name, now[, feedSilentDays]))` in its
 * own `health()` body — ten bodies of one shape across rent, car and job. This is that shape, once:
 * the run log gives the verdict, and {@see PatternMissLog::escalate()} decorates it with any configured
 * pattern that matched nothing on the pass. A source hands over three things — its name, its OWN miss
 * log (a fresh one would route through here and report nothing, which the structural guard in
 * `PatternMissEscalationTest` refuses) and its own `feed_silent_days`, `null` leaving the store's global
 * threshold in force.
 *
 * Step 8e-1 keeps the old ports: each source's `health()` delegates here, so `doctor`, the pipelines and
 * the heartbeat still read health through the source. The clock is therefore still the nullable ISO
 * string those ports carry — `null` means "no clock" (no `STALE` verdict), never a defaulted now, which
 * would make `STALE` fire where it does not today. Step 8e-2 moves the callers here directly and makes
 * the clock a non-nullable `DateTimeImmutable`.
 */
final readonly class SourceHealthService
{
    public function __construct(private RunStore $runs)
    {
    }

    public function of(string $sourceName, PatternMissLog $misses, ?string $nowIso, ?int $feedSilentDays = null): SourceHealth
    {
        return $misses->escalate($this->runs->health($sourceName, $nowIso, $feedSilentDays));
    }
}
