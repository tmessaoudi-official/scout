<?php

declare(strict_types=1);

namespace Scout\Tests\Car;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Adapters\Mail\FileMailbox;
use Scout\Car\VehicleEmailSource;
use Scout\Car\VehicleSourceLoader;
use Scout\Car\VehicleStore;

/**
 * The first real ParuVendu car alert, read through the shipped source block — every value asserted
 * against a HAND-READ ground truth, because the parser producing a plausible listing is not the
 * parser being right (leboncoin's one-card bug taught that).
 *
 * Two messages in the folder: the car alert (subject counts 42, the message carries THREE cards —
 * the source samples its feed, stated cost) and the same sender's RENT alert, which the subject
 * gate must ignore.
 */
#[CoversClass(VehicleEmailSource::class)]
final class ParuVenduFixtureTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../..';

    public function testThreeCardsForAStatedFortyTwoEveryFactHandRead(): void
    {
        $listings = $this->source()->fetch();

        self::assertCount(3, $listings, 'the message states 42 and carries 3 — the sampling cost, pinned');

        [$austral, $kadjar, $p2008] = $listings;

        self::assertSame('1294764484A1KVVOREAUS', $austral->externalId);
        self::assertSame('Renault Austral Mild Hybrid 160 Auto Evolution', $austral->title);
        self::assertSame(21000, $austral->priceEur);
        self::assertSame(2023, $austral->year);
        self::assertSame(26000, $austral->mileageKm);
        self::assertSame('essence', $austral->fuel);
        self::assertSame('4x4 - suv', $austral->body);
        self::assertSame('automatique', $austral->gearbox, '"Auto" in the title');
        self::assertSame('renault', $austral->make);
        self::assertSame('austral', $austral->model);
        self::assertSame('https://www.paruvendu.fr/a/voiture-occasion/renault/austral/1294764484A1KVVOREAUS', $austral->url, 'query and tracking stripped');
        self::assertSame('2026-08-29T17:05:14Z', $austral->observedAt, 'observed when the message was sent');
        self::assertNull($austral->postcode, 'the card carries no location');

        self::assertSame('Renault Kadjar 1.3 Tce 160 Intens Edc Bva //...', $kadjar->title);
        self::assertSame(16990, $kadjar->priceEur);
        self::assertSame(2019, $kadjar->year);
        self::assertSame(55500, $kadjar->mileageKm);
        self::assertSame('automatique', $kadjar->gearbox, '"Edc Bva"');

        self::assertSame('Peugeot 2008 Generation-i 1.5 Bluehdi 100 Active', $p2008->title);
        self::assertSame(10990, $p2008->priceEur);
        self::assertSame('diesel', $p2008->fuel);
        self::assertSame(66416, $p2008->mileageKm);
        self::assertNull($p2008->gearbox, 'nothing in the title says — null, not manual (hard rule 9)');
    }

    public function testTheSearchCriteriaLineIsNeverReadAsACardsFacts(): void
    {
        // The alert quotes "Jusqu'à 30 000 € … Jusqu'à 100 000 km" above the cards. No listing may
        // carry 30000 as its price or 100000 as its mileage.
        foreach ($this->source()->fetch() as $car) {
            self::assertNotSame(30000, $car->priceEur, $car->externalId);
            self::assertNotSame(100000, $car->mileageKm, $car->externalId);
        }
    }

    /**
     * The segment AFTER the last "Voir l'annonce" carries that card's CTA link and nothing else —
     * footer, not a card. It used to re-yield the last card and warn "en double" on every message.
     */
    public function testTheFooterSegmentIsNotACardAndRaisesNoWarning(): void
    {
        $warnings = [];
        $listings = $this->source(static function (string $w) use (&$warnings): void { $warnings[] = $w; })->fetch();

        self::assertCount(3, $listings);
        self::assertSame([], $warnings, 'a furniture segment is silently not a card');
    }

    public function testTheSameSendersRentAlertYieldsNothing(): void
    {
        $ids = array_map(static fn ($c): string => $c->externalId, $this->source()->fetch());

        self::assertCount(3, array_unique($ids));
        // `FileMailbox` reports NO freshness by design (the rent side's `testAFileMailboxReportsNoFreshnessAtAll`);
        // what is asserted is that the source DELEGATES rather than invents one.
        self::assertNull($this->source()->newestFeedItemAt());
    }

    private function source(?\Closure $warn = null): VehicleEmailSource
    {
        $definitions = VehicleSourceLoader::load(self::ROOT . '/config/car/sources.json');

        return new VehicleEmailSource(
            $definitions['paruvendu'],
            new FileMailbox(self::ROOT . '/tests/fixtures/car/paruvendu'),
            $warn,
        );
    }
}
