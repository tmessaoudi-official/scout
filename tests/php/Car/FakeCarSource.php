<?php

declare(strict_types=1);

namespace Scout\Tests\Car;

use Scout\Core\PatternMissLog;
use Scout\Car\VehicleListing;
use Scout\Car\VehicleSource;

/** A car source the test drives: fixed listings or a fixed failure; health judged by the service from the run log. */
final class FakeCarSource implements VehicleSource
{
    private readonly PatternMissLog $misses;

    /** @param list<VehicleListing> $listings */
    public function __construct(
        private readonly string $name,
        private readonly array $listings,
        private readonly ?\Throwable $throw = null,
        /** Set: every fetched item records one call on `facts_pattern`, a hit (false) or a miss (true), as a real adapter counts. */
        private readonly ?bool $blind = null,
    ) {
        $this->misses = new PatternMissLog();
    }

    public function name(): string
    {
        return $this->name;
    }

    public function family(): string
    {
        return 'portal';
    }

    public function host(): ?string
    {
        return null;
    }

    public function fetch(): array
    {
        if ($this->throw !== null) {
            throw $this->throw;
        }

        if ($this->blind !== null) {
            foreach ($this->listings as $_) {
                $this->misses->record('facts_pattern', !$this->blind);
            }
        }

        return $this->listings;
    }

    public function patternMisses(): PatternMissLog
    {
        return $this->misses;
    }

    public function feedSilentDays(): ?int
    {
        return null;
    }
}
