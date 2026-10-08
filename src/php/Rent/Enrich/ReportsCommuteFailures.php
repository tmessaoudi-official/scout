<?php

declare(strict_types=1);

namespace Scout\Rent\Enrich;

/**
 * A commute planner that counts the lookups it could not complete (architecture review C-10,
 * 2026-10-08).
 *
 * A planner answers `null` rather than throwing, and `null` is UNKNOWN (hard rule 9), so an outage
 * and an unmatched address used to look the same: the score lost its commute component and the
 * reasons stopped mentioning it. The count is cumulative over the planner's life; `Pipeline`
 * reads it before and after each pass and warns on the difference.
 */
interface ReportsCommuteFailures
{
    /** Lookups that FAILED (an error response or an exception), never ones the API answered. */
    public function failedLookups(): int;

    /** Lookups the API's quota refused (a 429), and every lookup not attempted after one. */
    public function quotaRefusedLookups(): int;

    /** What the first 429 said (the daily figures when it gave them), or `null` if none came. */
    public function quotaDetail(): ?string;
}
