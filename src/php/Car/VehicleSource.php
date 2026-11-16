<?php

declare(strict_types=1);

namespace Scout\Car;

use Scout\Core\ReportsHealth;

/**
 * The car domain's adapter contract — `Adapters\Source`, for cars. `fetch()` throws, never returns `[]` on failure (hard rule 3).
 *
 * A source does not judge its own health (step 8e-2): it reports through {@see ReportsHealth} and
 * {@see \Scout\Core\SourceHealthService} judges it.
 */
interface VehicleSource extends ReportsHealth
{
    public function name(): string;

    /** portal | dealer | auction */
    public function family(): string;

    /** The host outbound requests go to, or null when the source issues none (an email source). */
    public function host(): ?string;

    /**
     * @return list<VehicleListing>
     *
     * @throws \Scout\Adapters\SourceError
     */
    public function fetch(): array;
}
