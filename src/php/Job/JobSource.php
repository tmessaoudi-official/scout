<?php

declare(strict_types=1);

namespace Scout\Job;

use Scout\Core\ReportsHealth;

/**
 * The job domain's adapter contract. `fetch()` throws, never returns `[]` on failure (hard rule 3).
 *
 * A source does not judge its own health (step 8e-2): it reports through {@see ReportsHealth} and
 * {@see \Scout\Core\SourceHealthService} judges it.
 */
interface JobSource extends ReportsHealth
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
}
