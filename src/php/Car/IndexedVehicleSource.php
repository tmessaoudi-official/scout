<?php

declare(strict_types=1);

namespace Scout\Car;

/**
 * A car source whose `fetch()` returns only NOVEL lots, over an index it reads first.
 *
 * Two facts about such a source are read by the pipeline and `doctor`, and both used to be
 * `instanceof SitemapVehicleSource` checks in three places — so a second source of this shape would
 * have silently skipped all three: seeded by fetching (every lot page at once), and baselined on its
 * novel slice, which reads as a drop on every quiet pass (the 2026-08-29 false `warn_drop`).
 */
interface IndexedVehicleSource extends VehicleSource
{
    /**
     * The seed pass: every lot the index lists today, as a bare listing (id + url) recorded as seen
     * WITHOUT fetching it. Nothing is judged or pushed.
     *
     * @return list<VehicleListing>
     */
    public function seedIndex(): array;

    /** How many lots the index listed on the last fetch — the FEED's size, which health baselines on. */
    public function lastIndexSize(): ?int;
}
