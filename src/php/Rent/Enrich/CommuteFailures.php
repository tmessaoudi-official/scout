<?php

declare(strict_types=1);

namespace Scout\Rent\Enrich;

use Scout\Core\MutableByDesign;

/**
 * The running count of commute lookups that did not complete, held by the `readonly` `NavitiaCommute`.
 *
 * Two kinds, kept apart because they call for different actions: a FAILURE (an error response or an
 * exception: the key, the network, the API) and a QUOTA REFUSAL (a 429: PRIM allows 1000 requests a
 * day, measured 2026-10-08, and refuses every request after that until its daily reset). The first
 * 429 marks the quota exhausted, so the planner asks nothing more for the rest of its life (one pass).
 *
 * Mutable by design, and it meets the bar {@see MutableByDesign} sets: counting IS its mechanism,
 * and the object is never handed to a caller as a result. Its owner exposes the numbers alone
 * ({@see ReportsCommuteFailures}), so nothing outside can rewrite them.
 */
final class CommuteFailures implements MutableByDesign
{
    private int $count = 0;

    private int $quotaRefusals = 0;

    private ?string $quotaDetail = null;

    public function record(): void
    {
        ++$this->count;
    }

    public function count(): int
    {
        return $this->count;
    }

    /** @param ?string $detail what the 429 said; the first one is kept, a later `null` keeps it */
    public function recordQuota(?string $detail): void
    {
        ++$this->quotaRefusals;
        $this->quotaDetail ??= $detail;
    }

    public function quotaExhausted(): bool
    {
        return $this->quotaRefusals > 0;
    }

    public function quotaRefusals(): int
    {
        return $this->quotaRefusals;
    }

    public function quotaDetail(): ?string
    {
        return $this->quotaDetail;
    }
}
