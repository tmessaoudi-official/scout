<?php

declare(strict_types=1);

namespace Scout\Core;

/**
 * What a source REPORTS so that its health can be judged somewhere else (architecture review B-11, step 8e-2).
 *
 * Until 8e-2 every source port carried `health(?string $nowIso)`, and each source computed its own verdict —
 * ten bodies of one shape, and a nullable clock that every caller had to remember. Now a source reports three
 * facts and {@see SourceHealthService} turns them, with the run log and a REQUIRED clock, into the verdict:
 *
 *   - its name (the run log's key);
 *   - its OWN pattern-miss log ({@see CountsPatternMisses}) — a fresh log would report nothing, which is the
 *     "counted, never reported" failure the structural guard in `PatternMissEscalationTest` refuses;
 *   - its own `feed_silent_days`, `null` leaving the store's global threshold in force (only an email source,
 *     which reports a feed date, may set one; the loader refuses it anywhere else).
 *
 * The rent, car and job source ports all extend this, so every source reports through the same three methods
 * and the type system — not an `instanceof` at each call site — guarantees it.
 */
interface ReportsHealth extends CountsPatternMisses
{
    public function name(): string;

    /** This source's own feed-silence threshold in days, or `null` for the store's global one. */
    public function feedSilentDays(): ?int;
}
