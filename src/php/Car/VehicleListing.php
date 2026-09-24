<?php

declare(strict_types=1);

namespace Scout\Car;

/**
 * One used car as a source presented it — the car domain's `RawListing`.
 *
 * Every measurement is `null` when the source did not state it, never 0 (hard rule 9): an unknown
 * mileage is not a new car, an unknown price is not a free one, and an unknown location never
 * rejects. `observedAt` is the message date for an email card and `null` for a polled page — the
 * store orders sightings by it, which is the whole defence against a re-read older card
 * manufacturing a price drop (the rent side paid 128 emails to learn that on 2026-08-29).
 *
 * Adding a property: it must reach {@see VehicleSnapshot} (a reflection test enforces it) and
 * every `with…()` here must be a clone-with, never a field-by-field rebuild — the rent side's
 * `enrich()` dropped a property that way on its first live pass.
 */
final readonly class VehicleListing
{
    /**
     * @param array<string, mixed> $fields every structured field the source exposed, verbatim
     * @param ?string $fuel     normalised: essence | diesel | hybride | electrique | gpl | autre
     * @param ?string $gearbox  normalised: automatique | manuelle
     * @param ?string $body     folded, as the source wrote it (suv, break, berline, monospace, …)
     * @param ?string $sellerType professional | private
     * @param ?string $observedAt UTC ISO-8601 instant the SOURCE says the observation was made
     * @param ?string $saleOpensAt UTC ISO-8601 instant an AUCTION lot's sale opens; null for a sale
     * @param ?string $closingAt   UTC ISO-8601 instant an AUCTION lot's bidding closes — auction
     *                             rule 2 (2026-08-27) makes it mandatory in the notification, so a
     *                             source that cannot state it refuses the lot rather than leaving it null
     */
    public function __construct(
        public string $sourceName,
        public string $externalId,
        public string $title = '',
        public string $description = '',
        public array $fields = [],
        public ?string $url = null,
        public ?string $make = null,
        public ?string $model = null,
        public ?int $priceEur = null,
        public ?int $year = null,
        public ?int $month = null,
        public ?int $mileageKm = null,
        public ?string $fuel = null,
        public ?string $gearbox = null,
        public ?string $body = null,
        public ?string $sellerType = null,
        public ?string $postcode = null,
        public ?string $observedAt = null,
        public ?string $saleOpensAt = null,
        public ?string $closingAt = null,
    ) {}

    /** Every human-readable surface the classifier reads: title, description, then each scalar field. */
    public function text(): string
    {
        $parts = [$this->title, $this->description];
        foreach ($this->fields as $key => $value) {
            if (is_scalar($value)) {
                $parts[] = $key . ': ' . (string) $value;
            }
        }

        return implode("\n", array_filter($parts, static fn (string $p): bool => trim($p) !== ''));
    }

    /** Age in whole years at `$atYear`, or null when the first registration is unknown. */
    public function ageYears(int $atYear, int $atMonth = 12): ?float
    {
        if ($this->year === null) {
            return null;
        }
        $months = ($atYear - $this->year) * 12 + ($atMonth - ($this->month ?? 6));

        return max(0.0, round($months / 12, 2));
    }

    public function withObservedAt(?string $observedAt): self
    {
        return clone($this, ['observedAt' => $observedAt]);
    }
}
