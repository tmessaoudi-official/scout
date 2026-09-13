<?php

declare(strict_types=1);

namespace Scout\Job;

/** Rejected with the disqualifier that fired, or matched with a 0–100 score and the reasons a phone can show. */
final readonly class JobVerdict
{
    /** @param list<string> $reasons */
    private function __construct(
        public JobOutcome $outcome,
        public ?int $score,
        public array $reasons,
    ) {}

    /** @param list<string> $reasons */
    public static function rejected(array $reasons): self
    {
        return new self(JobOutcome::REJECT, null, $reasons);
    }

    /** @param list<string> $reasons */
    public static function matched(int $score, array $reasons): self
    {
        return new self(JobOutcome::MATCH, $score, $reasons);
    }
}
