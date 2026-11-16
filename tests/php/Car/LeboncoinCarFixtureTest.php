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
 * TRACK 2 — leboncoin's VEHICLE alert, the car domain's third source.
 *
 * Five real alerts, captured 2026-08-27 and scrubbed. Every value below was read by hand off the
 * subjects before it was asserted, which is the only thing that makes a fixture test ground truth
 * rather than a snapshot of whatever the parser happened to do.
 *
 * WHAT THIS SOURCE PROVED IS THAT ITS FACTS ARE IN THE SUBJECT. The body above the price line
 * carries the dealer's name, its star rating, its stock count and `vous présente ses bonnes
 * affaires :` — nothing about the car. The positional title reader would have titled all five with
 * that marketing sentence, and `gearboxFromTitle()` reads the title, so the two cards that state
 * `DCT-7` and `*BOITE AUTOMATIQUE` would have scored as stating no gearbox. Both are asserted.
 */
#[CoversClass(VehicleEmailSource::class)]
final class LeboncoinCarFixtureTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../..';

    /**
     * Hand-read ground truth, keyed by ad id.
     *
     * @return array<string, array{make: string, model: string, price: int, year: int, km: int, fuel: string, gearbox: ?string}>
     */
    private const array EXPECTED = [
        '3257349898.htm' => ['make' => 'volkswagen', 'model' => 'tiguan', 'price' => 22990, 'year' => 2021, 'km' => 75886, 'fuel' => 'diesel', 'gearbox' => null],
        '3257662544.htm' => ['make' => 'hyundai', 'model' => 'i20', 'price' => 11490, 'year' => 2019, 'km' => 62500, 'fuel' => 'essence', 'gearbox' => 'automatique'],
        '3257661323.htm' => ['make' => 'ds', 'model' => 'ds3', 'price' => 12490, 'year' => 2022, 'km' => 40000, 'fuel' => 'essence', 'gearbox' => null],
        '3257659868.htm' => ['make' => 'renault', 'model' => 'captur', 'price' => 13990, 'year' => 2019, 'km' => 54241, 'fuel' => 'essence', 'gearbox' => null],
        '3257659224.htm' => ['make' => 'volkswagen', 'model' => 'polo', 'price' => 14990, 'year' => 2023, 'km' => 82500, 'fuel' => 'essence', 'gearbox' => 'automatique'],
    ];

    public function testEveryCaptureParsesToExactlyOneVehicle(): void
    {
        $cars = $this->cars();

        self::assertCount(5, $cars, 'one vehicle per message — this portal sends no digest');
        self::assertSame(array_keys(self::EXPECTED), array_keys($cars));
    }

    public function testEveryFieldMatchesWhatTheSubjectSays(): void
    {
        foreach ($this->cars() as $id => $car) {
            $want = self::EXPECTED[$id];

            self::assertSame($want['make'], $car->make, "$id make");
            self::assertSame($want['model'], $car->model, "$id model");
            self::assertSame($want['price'], $car->priceEur, "$id price");
            self::assertSame($want['year'], $car->year, "$id year");
            self::assertSame($want['km'], $car->mileageKm, "$id mileage");
            self::assertSame($want['fuel'], $car->fuel, "$id fuel");
            self::assertSame($want['gearbox'], $car->gearbox, "$id gearbox");
        }
    }

    /**
     * THE MAKE IS NOT DECORATION — `brand_avoid` is read off it.
     *
     * An unextracted make scores 0 on the brand component (Track 1d), so a source that states its
     * make and does not map it ranks every car ten points below an identical one from a source that
     * does. `make_model_source: title` is what stops that here: leboncoin's ad path is
     * `/vi/<id>.htm` and carries neither make nor model, so the ParuVendu shape would find nothing.
     */
    public function testTheMakeIsExtractedOnEveryCardBecauseTheScoreDependsOnIt(): void
    {
        foreach ($this->cars() as $id => $car) {
            self::assertNotNull($car->make, "$id: a null make would silently cost the brand component");
        }

        // And one of them is genuinely on the avoid list, so the penalty is exercised by real data
        // rather than only by the synthetic table in VehicleBrandPenaltyTest.
        self::assertSame('renault', $this->cars()['3257659868.htm']->make);
    }

    /**
     * THE GEARBOX IS THE MEASURED COST OF READING THE SUBJECT, and it is why this needed code.
     *
     * `gearboxFromTitle()` reads the title. With the positional reader the title of all five would
     * have been `vous présente ses bonnes affaires :`, and these two would have reported no gearbox
     * at all — a silent loss of a scored component on a portal that states it plainly.
     */
    public function testTheTwoAutomaticsAreRecognisedOnlyBecauseTheTitleIsTheSubject(): void
    {
        $cars = $this->cars();

        self::assertSame('automatique', $cars['3257662544.htm']->gearbox, 'DCT-7');
        self::assertSame('automatique', $cars['3257659224.htm']->gearbox, '*BOITE AUTOMATIQUE');

        foreach ($cars as $car) {
            self::assertStringNotContainsString('bonnes affaires', $car->title, 'the marketing line is not a title');
        }
    }

    /**
     * THE LINK HOST CARRIES THE PATH — the PAP lesson, on a message with four decoys.
     *
     * Alongside the ad, each alert links `/reply/<id>`, `/mes-recherches/`, `/lien-FAQ` and
     * `/dc/cgu/`, all on `www.leboncoin.fr`, and `looksLikeAListing()` rejects none of them by
     * wording. A host-only `link_host` would mint phantom vehicles carrying the real car's price
     * and facts — and an unsubscribe page never goes away, so one would never delist.
     */
    public function testOnlyTheAdLinkIsTakenAndTheTrackingIsStripped(): void
    {
        foreach ($this->cars() as $id => $car) {
            self::assertSame("https://www.leboncoin.fr/vi/$id", $car->url);
            self::assertStringNotContainsString('#', (string) $car->url, 'stableId drops the fragment');
            self::assertStringNotContainsString('/reply/', (string) $car->url);
        }
    }

    /**
     * IT SHARES A SENDER AND A LINK HOST WITH THE RENT SOURCE, and the subject is the only divider.
     *
     * A leboncoin housing alert reaching this source would be parsed as a vehicle with no price and
     * no facts; a vehicle alert reaching the RENT source would be parsed as a flat with no commune,
     * silently rejected, and counted in that source's health. They do not collide today only
     * because the vehicle alerts sit unlabelled in the INBOX — luck that expires the moment the
     * routing filter is created. Both blocks carry a `subject_pattern`; this pins both.
     */
    public function testTheSubjectPatternsSeparateTheTwoDomains(): void
    {
        $car = VehicleSourceLoader::load(self::ROOT . '/config/car/sources.json')['leboncoin'];
        $rent = \Scout\Rent\Config\ConfigLoader::loadSources(self::ROOT . '/config/rent/sources.json')['leboncoin'];

        $carSubject = 'GARAGE DU LAC vous propose Renault Captur (2) Initiale Paris E-TECH 145 -21 à 13 990 € à Grigny (91350)';
        $rentSubject = '3 nouveaux biens à louer à Ile-de-France';

        self::assertSame('no.reply@leboncoin.fr', $car->param('from'));
        self::assertSame('no.reply@leboncoin.fr', $rent->params['from'] ?? null, 'same sender — that is the whole hazard');

        self::assertSame(1, preg_match((string) $car->param('subject_pattern'), $carSubject));
        self::assertSame(0, preg_match((string) $car->param('subject_pattern'), $rentSubject));
        self::assertSame(1, preg_match((string) ($rent->params['subject_pattern'] ?? '~$^~'), $rentSubject));
        self::assertSame(0, preg_match((string) ($rent->params['subject_pattern'] ?? '~~'), $carSubject));
    }

    /** @return array<string, VehicleListing> keyed by external id */
    private function cars(): array
    {
        $definition = VehicleSourceLoader::load(self::ROOT . '/config/car/sources.json')['leboncoin'];
        $source = new VehicleEmailSource(
            $definition,
            new FileMailbox(self::ROOT . '/tests/fixtures/car/leboncoin'),
        );

        $out = [];
        foreach ($source->fetch() as $car) {
            $out[$car->externalId] = $car;
        }

        ksort($out);
        $sorted = [];
        foreach (array_keys(self::EXPECTED) as $id) {
            if (isset($out[$id])) {
                $sorted[$id] = $out[$id];
            }
        }

        return $sorted + $out;
    }
}
