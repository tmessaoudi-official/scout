<?php

declare(strict_types=1);

namespace Scout\Tests\Car;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Scout\Car\Hail;
use Scout\Car\VehicleClassifier;
use Scout\Car\VehicleCriteriaLoader;
use Scout\Car\VehicleListing;
use Scout\Car\VehicleScorer;
use Scout\Config\ConfigError;

/**
 * HAIL IS A SCORE PENALTY, NEVER A REJECT (developer ruling, 2026-09-25).
 *
 * Sized by measurement: 30 is the smallest penalty that keeps a perfect-scoring hail car (100)
 * under the car push gate of 73, so a hail car mostly waits for the rollup rather than buzzing the
 * phone; it still arrives, because it is still a match.
 */
#[CoversClass(Hail::class)]
#[CoversClass(VehicleScorer::class)]
final class HailPenaltyTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../..';

    /** @return iterable<string, array{string, bool}> */
    public static function texts(): iterable
    {
        yield 'Alcopa lot comment, verbatim' => ['Commentaires : Véhicule grêlé - gps défaillant - sans roue de secours - euro 6', true];
        yield 'the noun' => ['Traces de grêle sur le toit', true];
        yield 'plural, feminine' => ['voitures grêlées', true];
        yield 'capitals, no accent' => ['VEHICULE GRELE', true];
        yield 'non grêlé' => ['Véhicule non grêlé, jamais accidenté', false];
        yield 'hyphenated negation' => ['non-grêlé', false];
        yield 'sans grêle' => ['Garé au garage, sans grêle', false];
        yield 'aucune trace de grêle' => ['Aucune trace de grêle', false];
        yield 'a negated and a stated mention' => ['Capot non grêlé mais toit grêlé', true];
        yield 'nothing about it' => ['euro 6 - Carte grise sous 30 jours ouvrés', false];
        yield 'a word that merely starts alike' => ['grelots et grelotter', false];
    }

    #[DataProvider('texts')]
    public function testTheReaderReadsNegationFirst(string $text, bool $stated): void
    {
        self::assertSame($stated, Hail::stated($text));
    }

    public function testAHailCarIsPenalisedAndSaysSo(): void
    {
        $criteria = $this->criteria(30);
        $clean = $this->judge($this->car('Commentaires : euro 6'), $criteria);
        $hail = $this->judge($this->car('Commentaires : Véhicule grêlé - euro 6'), $criteria);

        self::assertSame('MATCH', $hail->outcome->value, 'a penalty, never a reject');
        self::assertSame($clean->score - 30, $hail->score);
        self::assertContains('grêle signalée — −30', $hail->reasons);
        self::assertNotContains('grêle signalée — −30', $clean->reasons);
    }

    /** The shipped criteria carry the ruled size. */
    public function testTheShippedPenaltyIsThirty(): void
    {
        self::assertSame(30, VehicleCriteriaLoader::load(self::ROOT . '/config/car/criteria.json')->hailPenalty);
    }

    /** Omitted, nothing is penalised — the counterweight for a deployment that never asked for it. */
    public function testAnOmittedPenaltyChangesNothing(): void
    {
        $criteria = $this->criteria(null);
        self::assertSame(0, $criteria->hailPenalty);
        self::assertSame(
            $this->judge($this->car('Commentaires : euro 6'), $criteria)->score,
            $this->judge($this->car('Commentaires : Véhicule grêlé'), $criteria)->score,
        );
    }

    /** A score never goes negative: the penalty clamps at 0 rather than wrapping below the scale. */
    public function testThePenaltyClampsAtZero(): void
    {
        $weak = new VehicleListing(sourceName: 'alcopa', externalId: 'w', description: 'Véhicule grêlé', make: 'peugeot', year: 2008, mileageKm: 300000, fuel: 'diesel');
        self::assertSame(0, $this->judge($weak, $this->criteria(100))->score);
    }

    public function testAPenaltyOutsideTheScaleIsRefused(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessage('hail_penalty');
        $this->criteria(101);
    }

    private function car(string $description): VehicleListing
    {
        return new VehicleListing(
            sourceName: 'alcopa', externalId: 'x', title: 'Toyota Yaris', description: $description, make: 'toyota',
            priceEur: 15000, year: 2023, mileageKm: 20000, fuel: 'hybride', gearbox: 'automatique', body: 'berline',
        );
    }

    private function criteria(?int $penalty): \Scout\Car\VehicleCriteria
    {
        $minimal = VehicleCriteriaTest::minimal();
        if ($penalty !== null) {
            $minimal['hail_penalty'] = $penalty;
        }

        return VehicleCriteriaLoader::fromArray($minimal);
    }

    private function judge(VehicleListing $car, \Scout\Car\VehicleCriteria $criteria): \Scout\Car\VehicleVerdict
    {
        return (new VehicleScorer())->judge($car, (new VehicleClassifier())->classify($car), $criteria, 2026, 9);
    }
}
