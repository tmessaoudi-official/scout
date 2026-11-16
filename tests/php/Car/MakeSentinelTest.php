<?php

declare(strict_types=1);

namespace Scout\Tests\Car;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Adapters\Mail\FileMailbox;
use Scout\Car\VehicleClassification;
use Scout\Car\VehicleCriteriaLoader;
use Scout\Car\VehicleEmailSource;
use Scout\Car\VehicleListing;
use Scout\Car\VehicleScorer;
use Scout\Car\VehicleSourceLoader;
use Scout\Car\VehicleStore;
use Scout\Config\ConfigError;

/**
 * TRACK 6-A4 — a portal's "I don't know" token is not a make.
 *
 * ParuVendu encodes make and model in the ad path (`/voiture-occasion/<marque>/<modele>/`) and
 * writes **`/autres/autres/`** when it does not know them. That token was stored as the make, so it
 * matched no `brand_avoid` stem and the car took the whole 10-point brand share. The live row:
 *
 *     title `Ds Ds4 E-tense 225ch Performance Line` · make `autres` · model `autres` · score 15
 *
 * A **DS**, which is on the avoid list. Nothing reads as a fault — the make is non-null and looks
 * like a value, so this is not the unknown-make arm, it is a wrong answer wearing one.
 *
 * ## THE AUDIT'S PREMISE FOR THE OTHER MECHANISM IS REFUTED, AND THAT IS WHY THIS IS THE FIX
 *
 * Finding N3 offered two routes and preferred the second: *"either map the portal's `autres` token
 * to null (unknown-make arm then applies — **still full share by hard rule 9**), or read the make
 * from the title … The second actually fixes it."* The code says otherwise. `VehicleScorer`'s
 * `make === null` arm scores **0** and says `marque inconnue — hors score`, a deliberate and
 * documented deviation from the plan's own line. So both routes give this car brand 0; they differ
 * only for a make that is NOT avoided hiding behind a sentinel — never once observed.
 *
 * Building the title read for that unobserved case would be the n=1 generalisation this repo keeps
 * paying for, in exactly the fallback shape `make_model_source`'s own docblock refuses: *"a fallback
 * lets a pattern written for one haystack quietly match the other, which is how an extraction
 * failure acquires an alibi."* The title is also measurably worse than the path — over all 108
 * stored ParuVendu rows the title's first word is the make **101 times**, the seven misses starting
 * with the model (`Captur (2) Techno Tce 90`, `3 1.2 Puretech 130`) or mangled (`Il-peugeot 208`).
 *
 * ## MEASURED OVER THE STORE BEFORE SHIPPING
 *
 *     paruvendu 108 rows · `autres` 1 · every other make a real marque · 107 unchanged
 *     autohero 261 rows · leboncoin 5 rows · no sentinel token on either
 *
 * The sentinel is CONFIGURED per source, not hardcoded: it is a property of one portal's URL
 * scheme. An empty declaration is refused — a sentinel that can never match is a disabled feature
 * dressed as a configured one (the `detail_budget_per_pass: 0` precedent).
 */
#[CoversClass(VehicleEmailSource::class)]
final class MakeSentinelTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../..';

    /** @var array<string, array{calls: int, misses: int}> */
    private array $lastMisses = [];

    /**
     * The live row's own shape, beside a real card so the counterweight travels with it: without
     * the second card this passes by nulling every make on the source.
     */
    public function testTheUnknownTokenIsNotAMakeAndARealPathStillIs(): void
    {
        [$ds, $austral] = $this->read();

        self::assertNull($ds->make, 'the portal wrote `autres` because it does not know — that is not a marque');
        self::assertNull($ds->model, 'and the model token is the same sentinel');
        self::assertSame('Ds Ds4 E-tense 225ch Performance Line', $ds->title, 'the title is untouched — only the make is refused');

        self::assertSame('renault', $austral->make, 'a real path token is read exactly as before');
        self::assertSame('austral', $austral->model);
    }

    /**
     * DOES IT ACTUALLY CHANGE THE SCORE? The adapter assertion above is the mechanism; this is the
     * consequence, and it is the whole reason the finding was raised. Judged through the SHIPPED
     * criteria, not a fixture set.
     */
    public function testTheSentinelCarNoLongerEarnsTheBrandShare(): void
    {
        [$ds, $austral] = $this->read();

        $criteria = VehicleCriteriaLoader::load(self::ROOT . '/config/car/criteria.json');

        $dsVerdict = $this->judge($ds, $criteria);
        self::assertContains(
            'marque inconnue — hors score',
            $dsVerdict->reasons,
            'the honest arm, reached for the first time on this source',
        );
        foreach ($dsVerdict->reasons as $line) {
            self::assertStringNotContainsString('autres', $line, 'and `autres` is never announced as a marque');
        }

        // renault IS on the avoid list, so the counterweight proves the OTHER arm still fires —
        // a change that nulled every make would satisfy the assertion above and break this one.
        self::assertContains('renault — marque à éviter', $this->judge($austral, $criteria)->reasons);
    }

    /**
     * THE COUNTERWEIGHT TO SUBTRACTING IT FROM THE MISS GUARD. `VehicleEmailPatternMissTest` reads
     * `PATTERN_PARAMS` by reflection and asserts every card-level key is counted;
     * `make_model_unknown_pattern` is subtracted there with a reason, and that subtraction is only
     * safe while the adapter really does not count it. A `missed()` call added here would pass the
     * reflection test — it is subtracted — and put every correctly-read card in the denominator,
     * holding the ratio near 100 % for ever. F30's shape.
     */
    public function testTheSentinelIsNeverCountedAsAnExtractionMiss(): void
    {
        $this->read();

        self::assertArrayNotHasKey('make_model_unknown_pattern', $this->lastMisses);
        self::assertArrayHasKey('make_model_pattern', $this->lastMisses, 'while the pattern it guards IS counted');
    }

    /** A sentinel that can never match is a disabled feature dressed as a configured one. */
    public function testAnEmptySentinelIsRefusedAtLoad(): void
    {
        $this->expectException(ConfigError::class);

        $this->loadWith('""');
    }

    /** And a broken one is refused too — it would silently null nothing and read as "no sentinels". */
    public function testAnUninterpretableSentinelIsRefusedAtLoad(): void
    {
        $this->expectException(ConfigError::class);

        $this->loadWith('"~^(autres$~"');
    }

    private function judge(VehicleListing $car, \Scout\Car\VehicleCriteria $criteria): \Scout\Car\VehicleVerdict
    {
        return (new VehicleScorer())->judge($car, new VehicleClassification(\Scout\Car\VehicleOutcome::MATCH, []), $criteria, 2026, 9);
    }

    /** @return array{0: VehicleListing, 1: VehicleListing} */
    private function read(): array
    {
        $dir = sys_get_temp_dir() . '/car-sentinel-' . bin2hex(random_bytes(6));
        mkdir($dir);
        file_put_contents($dir . '/alerte.eml', $this->message());

        try {
            $source = new VehicleEmailSource(
                VehicleSourceLoader::load(self::ROOT . '/config/car/sources.json')['paruvendu'],
                new FileMailbox($dir),
            );
            $listings = $source->fetch();
            $this->lastMisses = $source->patternMisses()->counts();

            self::assertCount(2, $listings, 'the probe message carries exactly two cards');

            return [$listings[0], $listings[1]];
        } finally {
            @unlink($dir . '/alerte.eml');
            @rmdir($dir);
        }
    }

    /** Two cards in ParuVendu's own layout: title, price, facts, each followed by the ad link. */
    private function message(): string
    {
        $card = static fn (string $title, string $path, string $id, string $price, string $facts): string => $title . "\n"
            . 'https://www.paruvendu.fr/a/voiture-occasion/' . $path . '/' . $id . "?idAlerte=0\n\n"
            . $price . "€\n"
            . 'https://www.paruvendu.fr/a/voiture-occasion/' . $path . '/' . $id . "?idAlerte=0\n\n"
            . $facts . "\n"
            . 'https://www.paruvendu.fr/a/voiture-occasion/' . $path . '/' . $id . "?idAlerte=0\n\n"
            . "Voir l'annonce\n\n";

        return "From: info@paruvendu.fr\r\nTo: me@example.invalid\r\n"
            . "Subject: 2 nouvelles annonces - Voiture d'occasion\r\n"
            . "Date: Wed, 02 Sep 2026 07:05:14 +0200\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n\r\n"
            . $card(
                'Ds Ds4 E-tense 225ch Performance Line',
                'autres/autres',
                '1294740473A1KVVOZZZZZ',
                '24 900',
                '4x4 - SUV - Hybride rechargeable - Année 2022 - 41 000 km',
            )
            . $card(
                'Renault Austral Mild Hybrid 160 Auto Evolution',
                'renault/austral',
                '1294764484A1KVVOREAUS',
                '21 000',
                '4x4 - SUV - Essence - Année 2023 - 26 000 km',
            )
            . "https://www.paruvendu.fr/a/voiture-occasion/\n";
    }

    /** Load the shipped config with ParuVendu's sentinel replaced by the given JSON scalar. */
    private function loadWith(string $json): void
    {
        $raw = (string) file_get_contents(self::ROOT . '/config/car/sources.json');
        $patched = preg_replace(
            '~"make_model_unknown_pattern"\s*:\s*"(?:[^"\\\\]|\\\\.)*"~',
            '"make_model_unknown_pattern": ' . $json,
            $raw,
            1,
            $count,
        );
        self::assertSame(1, $count, 'the shipped paruvendu block must carry the sentinel this test edits');

        $file = sys_get_temp_dir() . '/car-sources-' . bin2hex(random_bytes(6)) . '.json';
        file_put_contents($file, (string) $patched);

        try {
            VehicleSourceLoader::load($file);
        } finally {
            @unlink($file);
        }
    }
}
