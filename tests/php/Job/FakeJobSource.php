<?php

declare(strict_types=1);

namespace Scout\Tests\Job;

use Scout\Core\PatternMissLog;
use Scout\Job\JobListing;
use Scout\Job\JobSource;

/** A job source the test drives: fixed offers or a fixed failure, health judged by the service from the run log. */
final class FakeJobSource implements JobSource
{
    private readonly PatternMissLog $misses;

    /** @param list<JobListing> $offers */
    public function __construct(
        private readonly string $name,
        private readonly array $offers = [],
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
            foreach ($this->offers as $_) {
                $this->misses->record('facts_pattern', !$this->blind);
            }
        }

        return $this->offers;
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
