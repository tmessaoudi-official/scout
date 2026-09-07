<?php

declare(strict_types=1);

namespace Scout\Rent\Core;

/**
 * THE ONE MATCHER FOR §1'S FOURTH PERSISTED ROUTE: *is this flat already on record as excluded,
 * under some other ad id?*
 *
 * **FOUR readings, not three, and this file said THREE for a fortnight** (C2 milestone panel, P3).
 * `Pipeline` judges §1 from the row's own durable reading, its group veto, its cross-track twin,
 * and this. `Store::excludedDwellings()` numbered it fourth while this docblock numbered it third,
 * and two live numberings for one rule is how the next session concludes it has covered them all.
 *
 * It exists as its own class because it has THREE callers that must never disagree — {@see
 * \Scout\Rent\Cli\Pipeline}, which forms the verdict, the digest drain in {@see
 * \Scout\Rent\Cli\RentScout}, which announces one, and `reclassify()` in the same file, which
 * does BOTH: it forms a verdict, promotes `DIGEST -> MATCH` and pushes it. **This docblock said TWO
 * and `reclassify` was the missing third** — found by all three lenses of the C2 milestone panel,
 * each with its own probe. C2 round 8 had found the drain reading only two of the four, and the
 * missing one is precisely the route that catches a portal RE-ADVERTISING a flat under a new ad id:
 * there is no group edge and no twin, so the other vetoes see nothing at all and the flat is pushed
 * while an excluded reading of the same dwelling sits one row away. A second implementation of that
 * rule is how they drift again, and this repo already names *a fix landing on one of two symmetric
 * surfaces* as its recurring defect — which is exactly what naming two callers produced here.
 *
 * The count is load-bearing, so keep it right: adding a caller means editing this line.
 *
 * Pure: no store, no clock, no I/O. The candidate list is loaded ONCE by the caller and passed in.
 */
final readonly class ExcludedDwellings
{
    /**
     * The first excluded row describing the SAME dwelling as `$listing`, or `null`.
     *
     * A row never vetoes itself — its own durable reading covers that, and a self-match would name
     * this very listing as the evidence against it. Identity is (source, externalId), not the dedup
     * key: the whole point of this route is that the key is different.
     *
     * @param list<array{key: string, source: string, externalId: string, tenure: Tenure, listing: RawListing}> $candidates
     *
     * @return array{key: string, source: string, externalId: string, tenure: Tenure, listing: RawListing, reason: string}|null
     */
    public static function match(RawListing $listing, array $candidates, Dedup $dedup): ?array
    {
        foreach ($candidates as $candidate) {
            if ($candidate['source'] === $listing->sourceName && $candidate['externalId'] === $listing->externalId) {
                continue;
            }

            $reason = $dedup->sameDwellingReason($listing, $candidate['listing']);
            if ($reason === null) {
                continue;
            }

            return $candidate + ['reason' => $reason];
        }

        return null;
    }
}
