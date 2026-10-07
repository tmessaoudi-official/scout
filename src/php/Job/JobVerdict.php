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

    /** The reason a match carries when none was recorded: a bare score cannot be judged. */
    public const string NO_REASON = 'aucune raison enregistrée pour ce score — à juger sur l\'annonce';

    /**
     * A match always carries a reason (architecture review A-16, 2026-10-08): an empty list is
     * replaced by a stated one, never thrown on, because a throw would block the push. The text is
     * the rent one, kept here rather than imported so the job domain does not depend on rent.
     *
     * @param list<string> $reasons
     */
    public static function matched(int $score, array $reasons): self
    {
        return new self(JobOutcome::MATCH, $score, count($reasons) > 0 ? $reasons : [self::NO_REASON]);
    }
}
