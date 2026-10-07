<?php

declare(strict_types=1);

namespace Scout\Rent\Enrich;

use Scout\Core\MutableByDesign;

/**
 * The running count of failed commute lookups, held by the `readonly` `NavitiaCommute`.
 *
 * Mutable by design, and it meets the bar {@see MutableByDesign} sets: counting IS its mechanism,
 * and the object is never handed to a caller as a result. Its owner exposes the number alone
 * ({@see ReportsCommuteFailures::failedLookups()}), so nothing outside can rewrite the count.
 */
final class CommuteFailures implements MutableByDesign
{
    private int $count = 0;

    public function record(): void
    {
        ++$this->count;
    }

    public function count(): int
    {
        return $this->count;
    }
}
