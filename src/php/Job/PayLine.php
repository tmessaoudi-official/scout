<?php

declare(strict_types=1);

namespace Scout\Job;

/**
 * One pay figure an offer STATES: a salary or a day rate, its basis, its period, and its bounds.
 *
 * The kind lives here, in the words the figure was read from, and not on `JobListing`. A structured
 * source that maps `salaryMinEur`/`salaryMaxEur` promises a GROSS ANNUAL figure by contract; a source
 * that can only say "package" or "net" leaves those fields null and puts the words in `payText`,
 * where this reader marks them. That is what lets N1 (a package never rejects) and "a net figure is
 * never compared" hold without a second field to keep in sync.
 */
final readonly class PayLine
{
    public const string SALARY = 'salary';
    public const string TJM = 'tjm';

    public const string GROSS = 'gross';
    public const string NET = 'net';
    public const string PACKAGE = 'package';
    /** A day rate excluding VAT — a TTC figure is converted before it becomes a line. */
    public const string HT = 'ht';

    public const string YEAR = 'year';
    public const string MONTH = 'month';
    public const string DAY = 'day';

    public function __construct(
        public string $kind,
        public string $basis,
        public string $period,
        public int $minEur,
        public int $maxEur,
        /** The folded words the figure was read from, for the reason line. */
        public string $text,
    ) {}

    /** Two lines with the same key are the same statement, however many surfaces repeat it. */
    public function key(): string
    {
        return $this->kind . '|' . $this->basis . '|' . $this->period . '|' . $this->minEur . '|' . $this->maxEur;
    }

    /** The upper bound over twelve months — what a salary is SCORED on. Null for a day rate. */
    public function annualMaxForScore(): ?int
    {
        return match ($this->period) {
            self::YEAR => $this->maxEur,
            self::MONTH => $this->maxEur * 12,
            default => null,
        };
    }

    /**
     * The upper bound over THIRTEEN months — what the 59 k€ floor is tested on. A monthly figure
     * rejects only when even a thirteenth month would not lift it over the floor. Null for a day rate.
     */
    public function annualMaxForFloor(): ?int
    {
        return match ($this->period) {
            self::YEAR => $this->maxEur,
            self::MONTH => $this->maxEur * 13,
            default => null,
        };
    }
}
