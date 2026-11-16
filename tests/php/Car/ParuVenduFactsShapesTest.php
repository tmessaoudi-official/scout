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
 * ParuVendu writes its facts line in THREE shapes, and for a month the pattern read only one.
 *
 * `body - fuel - Année YYYY - N km` was required, so `Essence - Année 2019 - 59 500 km` and plain
 * `Année 2020 - 80 237 km` failed the WHOLE match — year, mileage, fuel and body null together on
 * **17 of 160 stored cards (11 %)**, confirmed live by `doctor` (`facts_pattern 15/132`). Year and
 * mileage are the two heaviest inputs of the vehicle score, so those cars were judged *année
 * inconnue / kilométrage inconnu* with the facts printed on their own card.
 *
 * **THE ENVELOPE HERE IS SYNTHETIC AND THE FACTS LINES ARE NOT.** Every one is copied verbatim from
 * a stored ParuVendu description — the two committed fixtures happen to carry only the full shape,
 * and the messages that carried the short ones have long since fallen out of the IMAP window. Say
 * what a fixture is: this proves the SHIPPED pattern against real text, not a real capture. Replace
 * it with a capture the next time a short-shape alert arrives.
 *
 * The repair was trialled over every stored description before shipping, as this repo requires —
 * 143 identical, 17 gained, 0 lost, 0 changed — and the two shapes the trial rejected are asserted
 * below as the counterweight, because both were plausible and both were wrong:
 *
 *  - an optional body written `[^\n-]+?` LOSES 38 cards, since the commonest body is `4x4 - SUV`
 *    and it contains the separator;
 *  - a GREEDY optional body puts a lone `Essence` in the BODY slot — a wrong answer wearing an
 *    alibi, the `autres` class again — which is why the group is `)??`.
 */
#[CoversClass(VehicleEmailSource::class)]
final class ParuVenduFactsShapesTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../..';

    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            foreach (glob($dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
        $this->dirs = [];
    }

    public function testTheFullShapeIsUnchanged(): void
    {
        $car = $this->onlyCardFor('4x4 - SUV - Diesel - Année 2019 - 66 416 km');

        self::assertSame(2019, $car->year);
        self::assertSame(66416, $car->mileageKm);
        self::assertSame('diesel', $car->fuel);
        self::assertSame('4x4 - suv', $car->body, 'the body keeps its own internal separator');
    }

    public function testALoneFuelIsAFuelAndNotABody(): void
    {
        // Verbatim from a stored card (Fiat 500 C, 12 980 €). Under a greedy optional body this
        // read `body = Essence`, which scores nothing and says something false.
        $car = $this->onlyCardFor('Essence - Année 2019 - 59 500 km');

        self::assertSame(2019, $car->year);
        self::assertSame(59500, $car->mileageKm);
        self::assertSame('essence', $car->fuel);
        self::assertNull($car->body, 'the card states no body — unknown, never a guess');
    }

    public function testYearAndMileageSurviveWhenTheCardStatesNeitherBodyNorFuel(): void
    {
        // Verbatim from a stored card (Volkswagen Golf 8, 21 980 €). This is the shape that cost
        // the two heaviest score inputs on 11 % of the source.
        $car = $this->onlyCardFor('Année 2020 - 80 237 km');

        self::assertSame(2020, $car->year);
        self::assertSame(80237, $car->mileageKm);
        self::assertNull($car->fuel);
        self::assertNull($car->body);
    }

    public function testThePortalsOwnUnknownBodyTokenIsNotReadAsAFuel(): void
    {
        // Verbatim from the DS DS4 card. `Autres` is ParuVendu's *I don't know* token for the body;
        // the fuel list is closed precisely so a lone unknown token cannot land in the fuel slot.
        $car = $this->onlyCardFor('Autres - Année 2023 - 66 000 km');

        self::assertSame(2023, $car->year);
        self::assertSame(66000, $car->mileageKm);
        self::assertNull($car->fuel, 'an unlisted token is not a fuel');
    }

    public function testAFuelOutsideTheClosedListStaysInTheFuelSlot(): void
    {
        // `GPL ou GNL` is why the list had to be WIDENED rather than merely closed: two live cards
        // carry it, and closing the list without it moved them into `body` — the trial's only
        // "changed" rows, and the reason this assertion exists.
        $car = $this->onlyCardFor('Berline - GPL ou GNL - Année 2023 - 42 000 km');

        // `VehicleFacts::fuel()` canonicalises, so the assertion is on the VOCABULARY the scorer
        // reads, not on the raw capture — what matters is that the fuel reached the fuel slot at
        // all, which the closed list without this entry would have prevented.
        self::assertSame('gpl', $car->fuel);
        self::assertSame('berline', $car->body);
        self::assertSame(42000, $car->mileageKm);
    }

    /** One synthetic message carrying exactly one card whose facts line is `$facts`. */
    private function onlyCardFor(string $facts): \Scout\Car\VehicleListing
    {
        $dir = sys_get_temp_dir() . '/pv-shapes-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o775, true);
        $this->dirs[] = $dir;

        $body = implode("\n", [
            'Bonjour, il y a 1 nouvelle annonce pour votre recherche.',
            '',
            'Peugeot 208 1.2 Puretech 100 Allure',
            'https://www.paruvendu.fr/a/voiture-occasion/peugeot/208/FORMEONLY0001',
            '12 980 €',
            $facts,
            "Voir l'annonce",
            '',
        ]);

        file_put_contents($dir . '/alert.eml', implode("\r\n", [
            'From: info@paruvendu.fr',
            "Subject: Voiture d'occasion : 1 nouvelle annonce",
            'Date: Sat, 5 Sep 2026 09:19:13 +0200',
            'Content-Type: text/plain; charset=utf-8',
            '',
            str_replace("\n", "\r\n", $body),
        ]));

        $definitions = VehicleSourceLoader::load(self::ROOT . '/config/car/sources.json');
        $cars = (new VehicleEmailSource(
            $definitions['paruvendu'],
            new FileMailbox($dir),
        ))->fetch();

        self::assertCount(1, $cars, 'the harness itself must yield exactly one card');

        return $cars[0];
    }
}
