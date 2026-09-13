<?php

declare(strict_types=1);

namespace Scout\Job;

/** What recording one observation of an offer established: its key, whether it is new, and whether it is the newest sighting. */
final readonly class JobSighting
{
    public function __construct(
        public string $dedupKey,
        public bool $isNew,
        public bool $isCurrent,
    ) {}
}
