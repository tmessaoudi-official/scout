<?php

declare(strict_types=1);

namespace Scout\Rent\Enrich;

use Scout\Core\MutableByDesign;

/**
 * What a `NavitiaCommute` has already learned during its life (one pass), so it does not pay twice.
 *
 * Two facts, both measured as waste on 2026-10-08 against a quota of 1000 requests a day:
 *
 * - **the destination's coordinates.** It is one place, and every cache miss re-resolved it, which
 *   was a third of each miss's requests. Only a SUCCESSFUL resolution is kept, keyed on the
 *   destination text, so a changed destination resolves again.
 * - **communes the API answered "no route" for** (`no_origin` and its siblings). Remembered for the
 *   planner's life only: no schema change was ruled, so such a commune is asked once per pass.
 *   Stated cost: an in-area commune with no public transport still spends about two requests a pass.
 *
 * Mutable by design, and it meets the bar {@see MutableByDesign} sets: remembering IS its mechanism,
 * it carries no verdict, and its owner never hands it to a caller.
 */
final class CommuteMemo implements MutableByDesign
{
    /** @var array<string, array{0: float, 1: float}> */
    private array $destinations = [];

    /** @var array<string, true> */
    private array $noRoute = [];

    /** @return array{0: float, 1: float}|null */
    public function destination(string $destination): ?array
    {
        return $this->destinations[$destination] ?? null;
    }

    /** @param array{0: float, 1: float} $coordinates */
    public function rememberDestination(string $destination, array $coordinates): void
    {
        $this->destinations[$destination] = $coordinates;
    }

    public function isNoRoute(string $origin): bool
    {
        return isset($this->noRoute[$origin]);
    }

    public function rememberNoRoute(string $origin): void
    {
        $this->noRoute[$origin] = true;
    }
}
