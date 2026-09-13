<?php

declare(strict_types=1);

namespace Scout\Job;

/**
 * One job offer as a source read it: everything a verdict is formed from, and nothing derived.
 *
 * **Every measurement is null when the offer does not state it** (hard rule 9). A null salary is
 * UNKNOWN pay, not zero pay, and it can never reach the 59 k€ floor. A null work mode is an
 * unstated one, not "on site". An empty contract set means no contract was stated, not "none in
 * scope". Every rejection in this domain runs on a STATED fact, and those three distinctions are
 * what keep it that way.
 *
 * Pay is stored as the offer states it — `salaryMinEur`/`salaryMaxEur` ANNUAL gross, `tjmMinEur`/
 * `tjmMaxEur` per day excluding VAT — with `payText` holding the words the figures were read from,
 * so a push can quote the ad rather than a number the reader built.
 */
final readonly class JobListing
{
    /**
     * @param array<string, scalar> $fields    source-specific extras (LinkedIn's `Recrutement actif`, …)
     * @param list<string>          $contracts stated contract types, folded (`cdi`, `freelance`, `portage`, `cdd`, …)
     */
    public function __construct(
        public string $sourceName,
        public string $externalId,
        public string $title = '',
        public string $company = '',
        public string $location = '',
        public string $description = '',
        public array $fields = [],
        public ?string $url = null,
        public array $contracts = [],
        public ?string $workMode = null,
        public ?int $salaryMinEur = null,
        public ?int $salaryMaxEur = null,
        public ?int $tjmMinEur = null,
        public ?int $tjmMaxEur = null,
        public ?string $payText = null,
        public ?string $publishedAt = null,
        public ?string $observedAt = null,
    ) {
        if ($workMode !== null && !in_array($workMode, self::WORK_MODES, true)) {
            throw new \InvalidArgumentException('mode de travail inconnu : ' . $workMode);
        }
    }

    /**
     * The work modes a listing may carry. Anything else is REFUSED at construction, so a corrupt
     * stored value cannot decode as "nothing said" and turn an explicit `onsite` into an unstated mode.
     */
    public const array WORK_MODES = ['remote', 'hybrid', 'onsite'];

    /** The one sanctioned way to change the observation time: a clone, so tomorrow's property travels too. */
    public function withObservedAt(?string $observedAt): self
    {
        return clone($this, ['observedAt' => $observedAt]);
    }
}
