<?php

declare(strict_types=1);

namespace Scout\Job;

/** One pass of the job pipeline, counted — the car `VehicleRunResult` without price drops. */
final readonly class JobRunResult
{
    /**
     * @param list<string> $errors   one line per failed source or refused acknowledgement
     * @param list<string> $rejected one verbose line per rejected offer
     * @param list<string> $warnings what the pass noticed (row 41) — printed, never a source failure
     */
    public function __construct(
        public int $sourcesRun = 0,
        public int $sourcesFailed = 0,
        public int $itemsParsed = 0,
        public int $matches = 0,
        public int $rejectedCount = 0,
        public int $notified = 0,
        public int $undelivered = 0,
        public array $errors = [],
        public array $rejected = [],
        public array $warnings = [],
        /** Matches judged this pass, held back by `push_min_score`, and not yet in any announcement. */
        public int $queuedLowScore = 0,
    ) {}
}
