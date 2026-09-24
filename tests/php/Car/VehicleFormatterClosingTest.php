<?php

declare(strict_types=1);

namespace Scout\Tests\Car;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Car\VehicleFormatter;
use Scout\Car\VehicleListing;
use Scout\Car\VehicleVerdict;

/**
 * AN AUCTION LOT IS ANNOUNCED WITH ITS CLOSING TIME, ON EVERY SURFACE THAT ANNOUNCES IT.
 *
 * Auction rule 2 (2026-08-27): the closing time is mandatory in the notification — a lot pushed
 * without one tells the reader nothing about whether it is still worth opening. It rides in the
 * HEADLINE, which the individual push and every rollup line share, so the two cannot disagree.
 * Instants are stored UTC and rendered in Europe/Paris, the sale's own zone, whatever the host says.
 */
#[CoversClass(VehicleFormatter::class)]
final class VehicleFormatterClosingTest extends TestCase
{
    private string $zone;

    protected function setUp(): void
    {
        $this->zone = date_default_timezone_get();
        // A host in UTC must not move the rendered hour: the sale is in Paris.
        date_default_timezone_set('UTC');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->zone);
    }

    /** Online: opens one day, closes on another — the closing is what matters. 15:00 CEST = 13:00Z. */
    public function testAnOnlineSaleNamesItsClosing(): void
    {
        $car = $this->lot(saleOpensAt: '2026-09-22T17:00:00Z', closingAt: '2026-09-25T13:00:00Z');

        self::assertStringEndsWith(' · clôture 25/09 15:00', $this->push($car));
        self::assertStringEndsWith(' · clôture 25/09 15:00', $this->rollupLine($car));
    }

    /** LIVE: one day, one window. 09:30–18:00 CEST = 07:30Z–16:00Z. */
    public function testALiveSaleNamesItsDayAndWindow(): void
    {
        $car = $this->lot(saleOpensAt: '2026-10-12T07:30:00Z', closingAt: '2026-10-12T16:00:00Z');

        self::assertStringEndsWith(' · vente le 12/10 09:30–18:00', $this->push($car));
        self::assertStringEndsWith(' · vente le 12/10 09:30–18:00', $this->rollupLine($car));
    }

    /** Winter time: 15:00 CET = 14:00Z. A fixed +2 offset would print 16:00. */
    public function testTheZoneIsParisNotAFixedOffset(): void
    {
        self::assertStringEndsWith(' · clôture 15/01 15:00', $this->push($this->lot(closingAt: '2027-01-15T14:00:00Z')));
    }

    /** A closing with no opening is still a closing. */
    public function testAClosingAloneIsRendered(): void
    {
        self::assertStringEndsWith(' · clôture 25/09 15:00', $this->push($this->lot(closingAt: '2026-09-25T13:00:00Z')));
    }

    /** The counterweight: a car that is not an auction carries no auction line — byte-identical to before. */
    public function testAnOrdinaryCarIsUnchanged(): void
    {
        self::assertSame('paruvendu · 70/100 — Jeep Avenger 2023 · 18 437 km', $this->push($this->lot()));
    }

    /** An instant the formatter cannot read is shown as written: leaving it out would read as "no closing". */
    public function testAnUnreadableClosingIsShownRawRatherThanHidden(): void
    {
        self::assertStringEndsWith(' · clôture 2026-09-25 15:00', $this->push($this->lot(closingAt: '2026-09-25 15:00')));
    }

    private function lot(?string $saleOpensAt = null, ?string $closingAt = null): VehicleListing
    {
        return new VehicleListing(
            sourceName: 'paruvendu', externalId: 'x', make: 'jeep', model: 'avenger', year: 2023, mileageKm: 18437,
            saleOpensAt: $saleOpensAt, closingAt: $closingAt,
        );
    }

    private function push(VehicleListing $car): string
    {
        return (new VehicleFormatter())->match($car, VehicleVerdict::matched(70, [], false))->title;
    }

    private function rollupLine(VehicleListing $car): string
    {
        return (new VehicleFormatter())->rollup([['car' => $car, 'score' => 70]])->reasons[0];
    }
}
