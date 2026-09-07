<?php

declare(strict_types=1);

namespace Scout\Rent\Cli;

use Scout\Rent\Core\Dedup;
use Scout\Rent\Core\ExcludedDwellings;
use Scout\Rent\Core\RawListing;
use Scout\Rent\Core\Tenure;
use Scout\Rent\Store\Store;

/**
 * THE LAST GATE BEFORE A FLAT IS ANNOUNCED AS A MATCH — all four persisted §1 routes, read fresh.
 *
 * **Why this exists, in one sentence: §1 is judged from FOUR persisted routes across THREE
 * announcing surfaces, and for three consecutive certification rounds every fix patched one cell
 * of that matrix and the next round found another.** The C2 milestone panel logged five instances
 * of *a fix landing on one of two symmetric surfaces* over rounds 1–3, three of them committed
 * inside the fix for the previous one. The matrix is the defect; this class collapses it.
 *
 * The two round-3 P0s are what settled it, and neither was a careless edit:
 *
 *   - `Pipeline` loads the excluded-dwelling set once per pass and writes judged tenures inside the
 *     loop, so the set goes stale mid-pass. The "it cannot matter, both copies of one flat are
 *     caught by the same veto" argument is FALSE, because `Dedup::within()` is a TOLERANCE BAND and
 *     is not transitive: three ad ids 30 € apart chain, and the third is inside the second's band
 *     and outside the first's. A lens executed the push.
 *   - `reclassify`'s round-2 promotion re-check re-read the DWELLING route only. The GROUP route
 *     reads the same `tenure` column the loop writes, and `group_key` is a DIFFERENT, sticky
 *     predicate — a rent drop past the tolerance leaves the cluster edge standing while
 *     `sameFlatReason` stops matching, so the dwelling filter misses what the group would catch.
 *
 * Both are staleness, and staleness cannot be fixed by re-reading one route at one call site. So:
 *
 *   - **EVERY route, never a subset.** A caller that wants three of them is a caller that will be a
 *     finding. There is no parameter to select routes and there must never be one.
 *   - **READ FRESH on every call.** Nothing is cached and nothing is hoisted, because a hoist is
 *     exactly what produced both P0s. `Store::excludedDwellings()` is ~19 ms on the live store and
 *     this runs once per ANNOUNCEMENT, not per listing — a pass that pushes 90 matches spends under
 *     two seconds here, against a 15-minute cadence.
 *   - **CALLED AT THE LAST MOMENT**, immediately before the send, so no ordering within a pass or a
 *     loop can route around it. The per-row vetoes upstream stay where they are: they shape the
 *     VERDICT (an excluded reading is recorded, a doubt becomes a digest) and they short-circuit
 *     work. This gate does not replace them and is not a substitute for them — it is the backstop
 *     that makes their ordering stop mattering for §1.
 *
 * It answers one question — *may this flat be announced as a MATCH?* — and returns the route that
 * says no, so the caller can say which. It never decides what to do instead: demoting to a digest,
 * leaving a row queued and re-filing a verdict are all caller business.
 */
final readonly class SectionOneGate
{
    public function __construct(private Store $store, private Dedup $dedup) {}

    /**
     * The first route that refuses to let this flat be announced as a match, or `null`.
     *
     * Routes are checked cheapest-first, and the order is an optimisation ONLY — every one of them
     * is consulted before `null` is returned, so no ordering carries meaning. `UNDETERMINED` is not
     * a veto here: a doubt is the digest's business and demoting to it is the caller's decision,
     * while this gate exists to stop an EXCLUDED regime reaching a push.
     *
     * @return array{route: string, tenure: Tenure, detail: string}|null
     */
    public function refuses(RawListing $listing, string $dedupKey): ?array
    {
        // 1. THE ROW'S OWN DURABLE READING. Permanent by design once excluded, and the only route
        //    that needs no other row to exist.
        $own = $this->store->tenure($dedupKey);
        if ($own !== null && $own->isExcluded()) {
            return ['route' => 'lecture propre', 'tenure' => $own, 'detail' => 'cette annonce est elle-même au régime ' . $own->value];
        }

        // 2. THE CLUSTER. `group_key` is sticky and survives a survivorship flip, which is why it
        //    catches pairs the dwelling predicate has stopped matching.
        $group = $this->store->groupExcludedTenure($dedupKey);
        if ($group !== null) {
            return ['route' => 'groupe', 'tenure' => $group, 'detail' => 'une annonce du même groupe est au régime ' . $group->value];
        }

        // 3. THE OTHER TRACK (schema v12). Persisted precisely so a pass that does not fetch the
        //    twin still honours what it said.
        $twin = $this->store->twinTenure($dedupKey);
        if ($twin !== null && $twin['tenure']->isExcluded()) {
            return [
                'route' => 'jumeau',
                'tenure' => $twin['tenure'],
                'detail' => 'le jumeau sur ' . $twin['source'] . ' est au régime ' . $twin['tenure']->value,
            ];
        }

        // 4. THE SAME DWELLING under another ad id — the route with no edge of its own, which is why
        //    it is the one a re-advertisement reaches.
        $dwelling = ExcludedDwellings::match($listing, $this->store->excludedDwellings(), $this->dedup);
        if ($dwelling !== null) {
            return [
                'route' => 'même logement',
                'tenure' => $dwelling['tenure'],
                'detail' => 'le même logement est au régime ' . $dwelling['tenure']->value
                    . ' sous ' . $dwelling['source'] . ' ' . $dwelling['externalId'],
            ];
        }

        return null;
    }
}
