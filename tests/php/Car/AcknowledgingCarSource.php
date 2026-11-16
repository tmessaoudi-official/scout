<?php

declare(strict_types=1);

namespace Scout\Tests\Car;

use Scout\Adapters\AcknowledgesMessages;
use Scout\Car\VehicleListing;
use Scout\Car\VehicleSource;
use Scout\Car\VehicleStore;
use Scout\Core\PatternMissLog;

/**
 * The car twin of the rent `AcknowledgingSource`: records the ORDER of the acknowledgement
 * relative to the store, never merely that one happened.
 */
final class AcknowledgingCarSource implements VehicleSource, AcknowledgesMessages
{
    /** @var list<string> */
    public array $events = [];

    /** @param list<VehicleListing> $listings */
    public function __construct(
        private readonly string $name,
        private readonly array $listings,
        private readonly VehicleStore $store,
        private readonly ?\Throwable $throwOnFetch = null,
        private readonly ?\Throwable $throwOnAck = null,
    ) {}

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
        if ($this->throwOnFetch !== null) {
            throw $this->throwOnFetch;
        }

        return $this->listings;
    }

    public function patternMisses(): PatternMissLog
    {
        return new PatternMissLog();
    }

    public function feedSilentDays(): ?int
    {
        return null;
    }

    public function acknowledge(): void
    {
        if ($this->throwOnAck !== null) {
            throw $this->throwOnAck;
        }
        $this->events[] = $this->store->isSeenSetEmpty() ? 'acknowledged-before-recording' : 'acknowledged-after-recording';
    }
}
