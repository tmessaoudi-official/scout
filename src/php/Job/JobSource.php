<?php

declare(strict_types=1);

namespace Scout\Job;

use Scout\Core\SourceHealth;

/** The job domain's adapter contract. `fetch()` throws, never returns `[]` on failure (hard rule 3). */
interface JobSource
{
    public function name(): string;

    /** portal */
    public function family(): string;

    /** The host outbound requests go to, or null when the source issues none (an email source). */
    public function host(): ?string;

    /**
     * @return list<JobListing>
     *
     * @throws \Scout\Adapters\SourceError
     */
    public function fetch(): array;

    public function health(?string $nowIso = null): SourceHealth;
}
