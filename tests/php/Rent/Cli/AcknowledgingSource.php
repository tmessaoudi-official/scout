<?php

declare(strict_types=1);

namespace Scout\Tests\Rent\Cli;

use Scout\Adapters\AcknowledgesMessages;
use Scout\Core\PatternMissLog;
use Scout\Rent\Adapters\Source;
use Scout\Rent\Core\RawListing;
use Scout\Rent\Core\SourceProfile;
use Scout\Rent\Core\Tenure;
use Scout\Rent\Store\Store;

/**
 * A rent source that records WHEN it was acknowledged relative to the store — the ORDER, not a
 * count. "Acknowledged once" is satisfied by an acknowledgement moved above the recording loop;
 * "acknowledged after the store knew its listings" is not.
 */
final class AcknowledgingSource implements Source, AcknowledgesMessages
{
    /** @var list<string> */
    public array $events = [];

    /** @param list<RawListing> $listings */
    public function __construct(
        private readonly string $name,
        private readonly array $listings,
        private readonly Store $store,
        private readonly ?\Throwable $throwOnFetch = null,
        private readonly ?\Throwable $throwOnAck = null,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function family(): string
    {
        return 'private';
    }

    public function host(): ?string
    {
        return null;
    }

    public function defaultTenure(): ?Tenure
    {
        return Tenure::LIBRE;
    }

    public function profile(): SourceProfile
    {
        return new SourceProfile($this->name, 'private', Tenure::LIBRE, false);
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
