<?php

declare(strict_types=1);

namespace Scout\Core;

/**
 * The wall clock, as a port (architecture review B-10, step 8c, 2026-10-08).
 *
 * Time used to be read wherever it was needed: a nullable `nowIso` string threaded by hand, and a
 * `?? new \DateTimeImmutable()` fallback at each end of it, in two styles across nine files. A test
 * that forgot to pin time passed at 23:59 and failed at 00:01. Now {@see SystemClock} is the one
 * class that reads the wall clock (pinned by `tests/php/Repo/WallClockTest.php`), {@see FixedClock}
 * is the test seam, and {@see FixedClock::orSystem()} turns the existing `nowIso` seams into one.
 *
 * Durations are not this port's business: `hrtime()` and `microtime(true)` measure elapsed time, and
 * a stopwatch must not jump when a test pins the date.
 */
interface Clock
{
    public function now(): \DateTimeImmutable;
}
