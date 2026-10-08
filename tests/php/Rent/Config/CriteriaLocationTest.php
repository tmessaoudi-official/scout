<?php

declare(strict_types=1);

namespace Scout\Tests\Rent\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Rent\Config\ConfigLoader;
use Scout\Rent\Config\Criteria;

/**
 * The ONE location rule, read by the criteria engine and by the commute planner alike (2026-10-08).
 *
 * The planner spends a daily quota of 1000 PRIM requests (measured), and it used to spend them on
 * every listing a pass harvested, including the ones the location filter was about to reject. It now
 * asks this predicate first, so the two cannot disagree: a listing the engine accepts is enriched,
 * one it rejects costs nothing.
 */
#[CoversClass(Criteria::class)]
final class CriteriaLocationTest extends TestCase
{
    public function testWithACommuneTheCommuneListDecides(): void
    {
        $named = self::criteria(['communes' => ['Sartrouville'], 'postcode_prefixes' => ['78']]);

        self::assertTrue($named->matchesLocation('Sartrouville', '78500'));
        self::assertFalse($named->matchesLocation('Houilles', '78800'), 'not on the list');
        self::assertFalse($named->matchesLocation('Sartrouville', '13001'), 'same name, another departement');
    }

    public function testWithOnlyAPostcodeThePrefixDecides(): void
    {
        $named = self::criteria(['communes' => ['Sartrouville'], 'postcode_prefixes' => ['78']]);

        self::assertTrue($named->matchesLocation(null, '78800'), 'looser than the list, the right kind of loose');
        self::assertFalse($named->matchesLocation(null, '36000'));
        self::assertFalse($named->matchesLocation(null, null), 'no location at all is not a match');
    }

    public function testRegionModeIsThePrefixAlone(): void
    {
        $region = self::criteria(['communes' => [], 'postcode_prefixes' => ['78', '95']]);

        self::assertTrue($region->matchesLocation('Anywhere', '95100'));
        self::assertFalse($region->matchesLocation('Châteauroux', '36000'));
        self::assertFalse($region->matchesLocation('Paris', null), 'in region mode the postcode is the only evidence');
    }

    /** @param array<string,mixed> $data */
    private static function criteria(array $data): Criteria
    {
        return ConfigLoader::criteriaFromArray($data);
    }
}
