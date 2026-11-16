<?php

declare(strict_types=1);

namespace Scout\Tests\Car;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Adapters\Mail\FileMailbox;
use Scout\Car\VehicleEmailSource;
use Scout\Car\VehicleListing;
use Scout\Car\VehicleSourceLoader;
use Scout\Car\VehicleStore;

/**
 * CapCar (Track 6-B1, 2026-09-05) read through the SHIPPED source block — three real alerts,
 * four labelled cards each, every value asserted against a HAND-READ ground truth. A parser that
 * produces a plausible car is not a parser that is right: the first draft of this source, config-
 * only, would have stored `Kilométrage : 24409` as the title and no make at all
 * (`CapCarPayloadShapeTest` is the record of why).
 *
 * n=3, not n=1: the three captures are the regression set the CapCar entry asked for.
 */
#[CoversClass(VehicleEmailSource::class)]
final class CapCarFixtureTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../..';

    public function testTwelveCardsAcrossThreeMessagesEveryFactHandRead(): void
    {
        $cars = $this->source()->fetch();

        self::assertCount(12, $cars, 'three messages of four cards, no header, no footer');

        // FileMailbox reads newest first: 09-03, then 09-02, then 09-01.
        [$clio, $duster, $yaris, $countryman, $sportage, $c5, $crossland, $p308, $p208, $p508, $p2008, $ami] = $cars;

        self::assertSame('Renault Clio Evolution', $clio->title);
        self::assertSame('renault', $clio->make);
        self::assertSame('clio', $clio->model);
        self::assertSame(13490, $clio->priceEur, 'U+202F thousands mark');
        self::assertSame(2024, $clio->year);
        self::assertSame(24409, $clio->mileageKm);
        self::assertSame('essence', $clio->fuel);
        self::assertSame('manuelle', $clio->gearbox);
        self::assertSame('2026-09-03T18:00:41Z', $clio->observedAt, 'observed when the message was sent (the Date header is UTC)');
        self::assertNull($clio->postcode, 'the card carries no location');
        self::assertNull($clio->body, 'no body-type label on this template — null, never guessed');

        self::assertSame('Dacia Duster 15 Ans', $duster->title);
        self::assertSame(13890, $duster->priceEur);
        self::assertSame(2021, $duster->year);
        self::assertSame(76485, $duster->mileageKm);
        self::assertSame('manuelle', $duster->gearbox);

        self::assertSame('Toyota Yaris Cross Design', $yaris->title);
        self::assertSame('hybride', $yaris->fuel, '"Hybride essence"');
        self::assertSame('automatique', $yaris->gearbox);
        self::assertSame(81845, $yaris->mileageKm);

        self::assertSame('Mini Countryman Northwood', $countryman->title);
        self::assertSame(21490, $countryman->priceEur);
        self::assertSame(2020, $countryman->year);

        self::assertSame('Kia Sportage Gt-Line Premium Business', $sportage->title);
        self::assertSame('kia', $sportage->make);
        self::assertSame('hybride', $sportage->fuel, '"Hybride diesel"');
        self::assertSame(27990, $sportage->priceEur);
        self::assertSame(87967, $sportage->mileageKm);

        self::assertSame('Citroen C5 Aircross Shine', $c5->title);
        self::assertSame('c5 aircross', $c5->model, 'a two-word model');
        self::assertSame('diesel', $c5->fuel);
        self::assertSame(14490, $c5->priceEur);

        self::assertSame('Opel CROSSLAND X Innovation', $crossland->title, 'composed from the raw captures, case kept');
        self::assertSame('opel', $crossland->make);
        self::assertSame('crossland x', $crossland->model);
        self::assertSame(2019, $crossland->year);
        self::assertSame(8990, $crossland->priceEur);

        self::assertSame('Peugeot 308 Allure', $p308->title);
        self::assertSame(8490, $p308->priceEur);
        self::assertSame(79479, $p308->mileageKm);
        self::assertSame('manuelle', $p308->gearbox);

        self::assertSame('Peugeot 208 Active', $p208->title);
        self::assertSame(7990, $p208->priceEur);
        self::assertSame(2020, $p208->year);
        self::assertSame(44560, $p208->mileageKm);
        self::assertSame('2026-09-01T18:00:43Z', $p208->observedAt);

        self::assertSame('Peugeot 508 Gt Pack', $p508->title);
        self::assertSame('hybride', $p508->fuel, '"Hybride rechargeable"');
        self::assertSame('automatique', $p508->gearbox);
        self::assertSame(22790, $p508->priceEur);

        self::assertSame('Peugeot 2008 Allure', $p2008->title);
        self::assertSame(20900, $p2008->priceEur);
        self::assertSame(32286, $p2008->mileageKm);

        self::assertSame('Citroen Ami My Ami Pop', $ami->title);
        self::assertSame('electrique', $ami->fuel, '"Électrique", folded');
        self::assertSame(6490, $ami->priceEur);
        self::assertSame(5187, $ami->mileageKm);
        self::assertSame(2023, $ami->year);
    }

    public function testIdentityIsContentSoTheTwelveAreTwelveAndNoneIsATrackingToken(): void
    {
        $ids = array_map(static fn (VehicleListing $c): string => $c->externalId, $this->source()->fetch());

        self::assertCount(12, array_unique($ids), 'twelve different cars, twelve identities');
        foreach ($ids as $id) {
            self::assertMatchesRegularExpression('~^[0-9a-f]{40}$~', $id, 'a content hash, never a Brevo token');
        }
    }

    /**
     * Every link in a CapCar message is on the same tracking host — banner, four CTAs, footer. The
     * card's link must be its OWN CTA's, and the last card's must not be the footer's.
     */
    public function testEachCardsLinkIsItsOwnCtaAndNeverTheFooters(): void
    {
        $cars = $this->source()->fetch();
        $urls = array_map(static fn (VehicleListing $c): string => (string) $c->url, $cars);

        self::assertCount(12, array_unique($urls), 'twelve cards, twelve distinct CTA links');
        foreach ($urls as $url) {
            self::assertStringStartsWith('https://cjbjibe.r.bh.d.sendibt3.com/tr/cl/', $url);
        }
        // The footer's link is the LAST host link of each message (read off the three fixtures); it
        // must appear on no card.
        foreach (['1mNodaKYzURt5xtKhYIbx0sqYe9t', 'zBytLUgSVMKu2XQwQC9gsgpJNeVO', 'x5y98YJg8YCuGj5GCMJ0XbIuyiXx'] as $footerTokenPrefix) {
            foreach ($urls as $url) {
                self::assertStringNotContainsString($footerTokenPrefix, $url, 'the footer\'s « voir mes critères » redirect is not a car');
            }
        }
    }

    public function testNoSegmentYieldsAPhantomAndNoWarningIsRaised(): void
    {
        $warnings = [];
        $cars = $this->source(static function (string $w) use (&$warnings): void { $warnings[] = $w; })->fetch();

        self::assertCount(12, $cars);
        self::assertSame([], $warnings, 'no duplicate, no furniture read as a card');
    }

    /** The feed date is the mailbox's business; a fixture directory reports none, and the source must not invent one. */
    public function testTheSourceDelegatesFreshnessRatherThanInventingIt(): void
    {
        self::assertNull($this->source()->newestFeedItemAt());
    }

    private function source(?\Closure $warn = null): VehicleEmailSource
    {
        $definitions = VehicleSourceLoader::load(self::ROOT . '/config/car/sources.json');

        return new VehicleEmailSource(
            $definitions['capcar'],
            new FileMailbox(self::ROOT . '/tests/fixtures/car/capcar'),
            $warn,
        );
    }
}
