<?php

declare(strict_types=1);

namespace Scout\Job;

/**
 * What an offer STATES, read once and judged by nothing yet. `JobClassifier` produces it; `JobScorer`
 * applies the criteria to it. Every field is the unknown value when the offer is silent: an empty
 * contract set, a null mode, null days, a null level (unlabelled), no pay lines, no eligibility clause.
 */
final readonly class JobFacts
{
    /** Every canonical contract word the classifier emits — what `contracts.rejected` may name. */
    public const array CONTRACTS = ['alternance', 'cdd', 'cdi', 'freelance', 'interim', 'portage', 'stage', 'vie'];

    public const string LEAD = 'lead';
    public const string SENIOR = 'senior';
    public const string CONFIRMED = 'confirme';
    public const string JUNIOR = 'junior';

    public const string FRENCH_NATIONALITY = 'nationalité française requise';
    public const string CLEARANCE = 'habilitation défense requise';

    /**
     * @param list<string>  $contracts canonical contract words, sorted: `alternance`, `cdd`, `cdi`, `freelance`, `interim`, `portage`, `stage`, `vie`, or a structured source's own folded value
     * @param list<PayLine> $pay
     */
    public function __construct(
        public array $contracts = [],
        /** `remote`, `hybrid`, `onsite`, or null when not stated. */
        public ?string $workMode = null,
        /** Remote days per week, 0–5, or null when not stated. */
        public ?float $remoteDays = null,
        /** {@see LEAD}, {@see SENIOR}, {@see CONFIRMED}, {@see JUNIOR}, or null for an unlabelled title. */
        public ?string $level = null,
        public array $pay = [],
        /** {@see FRENCH_NATIONALITY} or {@see CLEARANCE} when the offer states one as required. */
        public ?string $eligibility = null,
        /** Why the text could not be read, or null. A named breakage, never a silent absence. */
        public ?string $unreadable = null,
    ) {}
}
