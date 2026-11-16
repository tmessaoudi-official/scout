<?php

declare(strict_types=1);

namespace Scout\Core;

/**
 * Reads a source's health at an instant (step 8e-2).
 *
 * {@see SourceHealthService} is its ONE production implementation, and every pipeline built without a reader
 * gets exactly that. The interface exists for one reason: the rent pipeline's alert-routing tests must script
 * a verdict (every alerting status, a recovery, a cooldown) without manufacturing it from run-log state, a
 * seam that `health()` on the source port used to provide. A reader that is not the service is a TEST device;
 * production composition never passes one.
 */
interface SourceHealthReader
{
    public function of(ReportsHealth $source, \DateTimeImmutable $now): SourceHealth;
}
