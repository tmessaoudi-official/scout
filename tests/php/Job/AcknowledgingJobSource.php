<?php

declare(strict_types=1);

namespace Scout\Tests\Job;

use Scout\Adapters\AcknowledgesMessages;
use Scout\Core\SourceHealth;
use Scout\Job\JobListing;
use Scout\Job\JobSource;
use Scout\Job\JobStore;

/**
 * The job twin of `AcknowledgingCarSource`: records the ORDER of the acknowledgement relative to
 * the store, never merely that one happened.
 */
final class AcknowledgingJobSource implements JobSource, AcknowledgesMessages
{
    /** @var list<string> */
    public array $events = [];

    /** @param list<JobListing> $offers */
    public function __construct(
        private readonly string $name,
        private readonly array $offers,
        private readonly JobStore $store,
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

        return $this->offers;
    }

    public function health(?string $nowIso = null): SourceHealth
    {
        return $this->store->runs()->health($this->name, $nowIso);
    }

    public function acknowledge(): void
    {
        if ($this->throwOnAck !== null) {
            throw $this->throwOnAck;
        }
        $this->events[] = $this->store->isSeenSetEmpty() ? 'acknowledged-before-recording' : 'acknowledged-after-recording';
    }
}
