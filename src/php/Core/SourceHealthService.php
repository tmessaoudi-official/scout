<?php

declare(strict_types=1);

namespace Scout\Core;

/**
 * A source's health, computed in ONE place from the run log and the source's own telemetry (B-11).
 *
 * Every counting source used to write `escalate(<run log>->health(name, now[, feedSilentDays]))` in its
 * own `health()` body — ten bodies of one shape across rent, car and job. This is that shape, once:
 * the run log gives the verdict, and {@see PatternMissLog::escalate()} decorates it with any configured
 * pattern that matched nothing on the pass. The source only REPORTS ({@see ReportsHealth}): its name, its
 * OWN miss log and its own `feed_silent_days`, `null` leaving the store's global threshold in force.
 *
 * Step 8e-2 took `health()` off the source ports: `doctor`, the heartbeat and the three pipelines call
 * this directly (the pipelines through {@see SourceHealthReader}, defaulting to this class). The clock is
 * a NON-NULLABLE instant, so `STALE` can never be skipped by a caller that forgot it. It reaches the run
 * log as an ISO string that {@see RunStore::epoch()} reads back as the same instant.
 */
final readonly class SourceHealthService implements SourceHealthReader
{
    public function __construct(private RunStore $runs)
    {
    }

    public function of(ReportsHealth $source, \DateTimeImmutable $now): SourceHealth
    {
        return $source->patternMisses()->escalate(
            $this->runs->health($source->name(), $now->format(\DateTimeInterface::ATOM), $source->feedSilentDays()),
        );
    }
}
