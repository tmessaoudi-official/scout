<?php

declare(strict_types=1);

namespace Scout\Tests\Car;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Config\ConfigError;
use Scout\Car\VehicleCriteria;
use Scout\Car\VehicleCriteriaLoader;

#[CoversClass(VehicleCriteriaLoader::class)]
#[CoversClass(VehicleCriteria::class)]
final class VehicleCriteriaTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../..';

    public function testTheShippedFileLoadsAndIsTheRuledShape(): void
    {
        $c = VehicleCriteriaLoader::load(self::ROOT . '/config/car/criteria.json');

        self::assertSame(30000, $c->maxPriceEur, 'decision 5');
        // EMPTY = national since Track 1c (2026-08-31), and that is the honest state rather than a
        // widening. The eight Île-de-France departements were copied from the rent side and were
        // INERT: no car source maps a postcode, so `matchesLocation()` answered true for every
        // vehicle regardless. Leaving them would have activated the filter silently and
        // asymmetrically the day a source first mapped one. `config/car/criteria.json` carries the
        // reasoning and the one line that reverses it.
        self::assertSame([], $c->postcodePrefixes, 'decision 6, revised by Track 1c');
        self::assertTrue($c->matchesLocation('69000'), 'and an empty list genuinely matches everything');
        self::assertTrue($c->matchesLocation(null), 'including an unstated location');
        self::assertSame(['suv', 'break', 'berline'], $c->bodyFavour, 'decision 11, renamed by Track 7 — the list is unchanged, the ARITHMETIC is flat');
        self::assertSame(5, $c->peakAgeYears);
        self::assertSame(80000, $c->peakMileageKm);
        self::assertSame(100, array_sum($c->weights));
    }

    public function testAnUnknownKeyIsRefused(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~max_floor~');
        VehicleCriteriaLoader::fromArray(self::minimal(['max_floor' => 3]));
    }

    public function testWeightsMustSumToAHundred(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~100~');
        VehicleCriteriaLoader::fromArray(self::minimal(['weights' => ['price' => 50, 'age' => 20, 'mileage' => 20, 'gearbox' => 10, 'fuel' => 10, 'body' => 10, 'brand' => 10]]));
    }

    public function testALocalOverrideMergesFieldByField(): void
    {
        $dir = sys_get_temp_dir() . '/scout-car-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/criteria.json', json_encode(self::minimal()));
        file_put_contents($dir . '/criteria.local.json', json_encode(['max_price_eur' => 15000, 'notify' => ['channels' => ['ntfy']]]));

        $c = VehicleCriteriaLoader::load($dir . '/criteria.json', $dir . '/criteria.local.json');

        self::assertSame(15000, $c->maxPriceEur);
        self::assertSame(['ntfy'], $c->notify->channels);
        self::assertSame(70, $c->notify->highPriorityScore, 'untouched keys keep the base value');
    }

    public function testAnUnknownLocationNeverRejectsAndAStatedOneOutsideDoes(): void
    {
        $c = VehicleCriteriaLoader::fromArray(self::minimal());

        self::assertTrue($c->matchesLocation(null), 'hard rule 9');
        self::assertTrue($c->matchesLocation('78500'));
        self::assertFalse($c->matchesLocation('33000'));
        self::assertTrue(VehicleCriteriaLoader::fromArray(self::minimal(['postcode_prefixes' => []]))->matchesLocation('33000'), 'empty = national');
    }

    public function testBodyFavourIsFoldedAndFlat(): void
    {
        $c = VehicleCriteriaLoader::fromArray(self::minimal());

        self::assertTrue($c->isFavouredBody('SUV'));
        self::assertTrue($c->isFavouredBody('4x4 - SUV'), 'a body that CONTAINS a wanted word — 60 stored cars are written this way');
        self::assertTrue($c->isFavouredBody('Berline'), 'FLAT since Track 7: the last entry is worth exactly as much as the first');
        self::assertFalse($c->isFavouredBody('Monospace'));
        self::assertFalse($c->isFavouredBody(null), 'hard rule 9: an unstated body is not a disfavoured one');
    }

    /**
     * The old key says what to do, rather than being swallowed by the generic unknown-key message.
     *
     * A stale `criteria.local.json` is the reaching case: its list is still exactly right and only
     * the key moved, so *"clé inconnue"* would send the reader looking for a mistake that is not
     * there. The counterweight is the second half — an ordinary unknown key must still get the
     * ordinary message, or this branch has quietly become the answer to everything.
     */
    public function testTheRenamedBodyKeyIsRefusedByName(): void
    {
        try {
            VehicleCriteriaLoader::fromArray(self::minimal(['body_rank' => ['suv']]) + ['body_favour' => ['suv']]);
            self::fail('body_rank must be refused');
        } catch (ConfigError $e) {
            self::assertMatchesRegularExpression('~body_rank~', $e->getMessage());
            self::assertMatchesRegularExpression('~body_favour~', $e->getMessage(), 'and it must name the replacement');
        }
    }

    /** @return array<string,mixed> */
    public static function minimal(array $overrides = []): array
    {
        return $overrides + [
            'max_price_eur' => 30000,
            'postcode_prefixes' => ['75', '77', '78', '91', '92', '93', '94', '95'],
            'body_favour' => ['suv', 'break', 'berline'],
            'peak_age_years' => 5,
            'peak_mileage_km' => 80000,
            'weights' => ['price' => 20, 'age' => 20, 'mileage' => 20, 'gearbox' => 10, 'fuel' => 10, 'body' => 10, 'brand' => 10],
            'brand_avoid' => ['peugeot', 'renault', 'opel'],
            // BOTH LISTS, because the shipped shape has both and a fixture carrying one exercises
            // the wrong arm. With only `brand_avoid` set, `Toyota` lands in the THIRD class and
            // scores 0 — correct behaviour, and it would have made every test using this fixture
            // quietly measure *unlisted* while its name said *favoured*.
            'brand_favour' => ['toyota'],
            'exclude_patterns' => [],
            'notify' => ['channels' => ['console'], 'high_priority_score' => 70, 'price_drop_min_eur' => 300, 'price_drop_min_pct' => 3.0],
        ];
    }
}
