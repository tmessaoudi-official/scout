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
 * **THE CALLERS ARE ENUMERATED, NOT COUNTED, AND A TEST PINS THE LIST.** This docblock said "TWO"
 * and then "THREE", and each time the very commit editing the line added a caller it did not count
 * — twice in two rounds, on a line reading *"the count is load-bearing, so keep it right"*. A
 * number a human maintains by hand is not load-bearing, it is decorative. The callers, each of
 * which must never disagree with the others:
 *
 *   - {@see \Scout\Rent\Cli\Pipeline}                — forms the verdict on a live pass
 *   - `RentScout::collectDigest()`                     — the digest/rollup drain, which announces
 *   - `RentScout::reclassify()`                        — forms a verdict AND announces; reads it
 *                                                        twice, once per row and once over the
 *                                                        settled store before promoting
 *   - `Store::reopen()`                                — reports the route `--reopen` cannot clear
 *
 * `tests/php/Repo/ExcludedDwellingsCallersTest.php` discovers the real call sites and fails when
 * this list is stale, so the next caller cannot be added silently.
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
