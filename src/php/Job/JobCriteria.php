<?php

declare(strict_types=1);

namespace Scout\Job;

/**
 * The developer's job criteria, as ruled on 2026-09-13 (`docs/plans/job-domain.plan.md`). Every rule
 * the developer can move is here and nothing is non-overridable: the one eligibility rule is a switch
 * (H7), and the contract set, the floors, the role gate and every vocabulary are config.
 *
 * Hard disqualifiers and score are two mechanisms (hard rule 8). This class carries both, and
 * `JobScorer` applies them in that order. Every matcher answers on a STATED fact: an empty title
 * passes the role gate, an unrecognised place is neither inside nor outside Île-de-France.
 */
final readonly class JobCriteria
{
    public const array COMPONENTS = ['stack', 'pay', 'level', 'green', 'remote', 'freshness'];

    public const string IDF = 'idf';
    public const string OUTSIDE = 'outside';

    /**
     * @param array<string, JobTerms> $titleRejects    label => terms; the first label that fires rejects (H6, H4's title half)
     * @param list<string>            $rejectedContracts H3: a STATED contract set made only of these rejects
     * @param array<string, JobTerms> $green           group => terms; each group that fires earns an equal part of the share
     * @param array<int, float>       $remoteDaysShare remote days per week (0–5) => share
     * @param array<string, float>    $levelShares     lead, senior, confirme, junior, unlabelled => share
     * @param array<string, int>      $weights         the six components, summing to 100
     */
    public function __construct(
        public JobTerms $roleWords,
        public array $titleRejects,
        public JobTerms $excludePatterns,
        public array $rejectedContracts,
        public int $salaryFloorEur,
        public int $salaryTargetEur,
        public int $tjmFloorEur,
        public int $tjmTargetEur,
        /** False until the developer's naturalisation: an offer STATING French nationality or a defence clearance is rejected (H7). */
        public bool $frenchNationality,
        public JobTerms $idfPlaces,
        public JobTerms $outsidePlaces,
        public float $backShare,
        public JobTerms $backStack,
        public float $frontShare,
        public JobTerms $frontStack,
        public float $adjacentShare,
        public JobTerms $adjacentStack,
        public JobTerms $otherStack,
        public array $green,
        public int $redPenalty,
        public int $redCap,
        public JobTerms $red,
        public array $remoteDaysShare,
        public float $hybridUnstatedShare,
        public float $conditionsBonus,
        public JobTerms $conditions,
        public array $levelShares,
        public int $freshnessPeakDays,
        public array $weights,
        /** Where a match goes, and whether a gate holds the weaker ones for the daily rollup. */
        public JobNotifyPolicy $notify,
    ) {}

    /**
     * H5: does the title name a developer-family role? An EMPTY or unreadable title passes — the gate
     * rejects on a title that was read and names no such role, never on a title nobody could read.
     */
    public function passesRoleGate(string $title): bool
    {
        $folded = JobText::trySurface($title);
        if ($folded === null || trim($folded) === '') {
            return true;
        }

        return $this->roleWords->hits($folded) !== [];
    }

    /** H6 and H4's title half: the label of the first title reject that fires, or null. */
    public function titleRejectedBy(string $title): ?string
    {
        $folded = JobText::trySurface($title);
        if ($folded === null) {
            return null;
        }
        foreach ($this->titleRejects as $label => $terms) {
            if ($terms->hits($folded) !== []) {
                return $label;
            }
        }

        return null;
    }

    /** H4: the first user exclusion pattern matching the offer's title and description, or null. */
    public function excludedBy(string $text): ?string
    {
        $folded = JobText::trySurface($text);

        return $folded === null ? null : $this->excludePatterns->first($folded);
    }

    /**
     * {@see OUTSIDE}, {@see IDF}, or null when the place is recognised as neither — which is UNKNOWN and
     * never rejects (H8 fails open). Outside wins a collision, because its names are the specific ones:
     * `Saint-Denis, La Réunion` names an Île-de-France commune and an overseas region.
     */
    public function locationClass(string $location): ?string
    {
        $folded = JobText::trySurface($location);
        if ($folded === null || trim($folded) === '') {
            return null;
        }
        if ($this->outsidePlaces->hits($folded) !== []) {
            return self::OUTSIDE;
        }

        return $this->idfPlaces->hits($folded) !== [] ? self::IDF : null;
    }
}
