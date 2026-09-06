<?php

declare(strict_types=1);

namespace Scout\Rent\Core;

/**
 * THE ONE MATCHER FOR §1'S THIRD PERSISTED ROUTE: *is this flat already on record as excluded,
 * under some other ad id?*
 *
 * It exists as its own class because it has TWO callers that must never disagree — {@see
 * \Scout\Rent\Cli\Pipeline}, which forms the verdict, and the digest drain in {@see
 * \Scout\Rent\Cli\RentScout}, which announces one. C2 round 8 found the drain reading only two
 * of the three routes `Pipeline` reads, and the missing one is precisely the route that catches a
 * portal RE-ADVERTISING a flat under a new ad id: there is no group edge and no twin, so the other
 * two vetoes see nothing at all and the flat is pushed while an excluded reading of the same
 * dwelling sits one row away. A second implementation of that rule is how the two drift again, and
 * this repo already names *a fix landing on one of two symmetric surfaces* as its recurring defect.
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
