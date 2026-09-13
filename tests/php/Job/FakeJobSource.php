<?php

declare(strict_types=1);

namespace Scout\Tests\Job;

use Scout\Core\SourceHealth;
use Scout\Job\JobListing;
use Scout\Job\JobSource;
use Scout\Job\JobStore;

/** A job source the test drives: fixed offers or a fixed failure, health from the store it is given. */
final class FakeJobSource implements JobSource
{
    /** @param list<JobListing> $offers */
    public function __construct(
        private readonly string $name,
        private readonly JobStore $store,
        private readonly array $offers = [],
        private readonly ?\Throwable $throw = null,
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
        if ($this->throw !== null) {
            throw $this->throw;
        }

        return $this->offers;
    }

    public function health(?string $nowIso = null): SourceHealth
    {
        return $this->store->runs()->health($this->name, $nowIso);
    }
}
