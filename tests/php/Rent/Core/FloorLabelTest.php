<?php

declare(strict_types=1);

namespace Scout\Tests\Rent\Core;

use PHPUnit\Framework\TestCase;
use Scout\Rent\Config\ConfigLoader;
use Scout\Rent\Core\CriteriaEngine;
use Scout\Rent\Core\RawListing;
use Scout\Rent\Core\SourceProfile;
use Scout\Rent\Core\Tenure;
use Scout\Rent\Core\TenureClassifier;
use Scout\Rent\Core\Verdict;
use Scout\Rent\Notify\Formatter;

/**
 * ONE FLOOR, ONE NAME, ON EVERY SURFACE (architecture review A-14, 2026-10-08).
 *
 * The score's `reasons[]` and the notification's context line each built their own floor label,
 * and they disagreed below the ground floor: `CriteriaEngine` wrote `-1er étage` and the formatter
 * wrote `RDC`, so one push could say both about the same flat. Neither is a basement. Whether any
 * live source emits a negative floor is unmeasured; the disagreement is the defect, and these
 * tests pin that the two surfaces now read one table.
 */
final class FloorLabelTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../../..';

    private static function listing(int $floor): RawListing
    {
        return new RawListing(
            sourceName: 't',
            externalId: 'floor-' . $floor,
            title: 'T4 Sartrouville - logement intermediaire',
            description: '4 pieces, 85 m2.',
            commune: 'Sartrouville',
            postcode: '78500',
            rentCc: 1450,
            surfaceM2: 85.0,
            rooms: 4,
            floor: $floor,
        );
    }

    /** @return list<string> */
    private static function scoreReasons(RawListing $listing): array
    {
        $engine = new CriteriaEngine(ConfigLoader::loadCriteria(self::ROOT . '/tests/fixtures/rent/criteria/pipeline.json'));
        $profile = new SourceProfile('t', 'institutional', Tenure::LLI, false);

        return $engine->judge($listing, (new TenureClassifier())->classify($listing, $profile), null)->reasons;
    }

    private static function contextLine(RawListing $listing): string
    {
        return implode("\n", (new Formatter())->match($listing, Verdict::matched(82, ['test'], false))->reasons);
    }

    public function testABasementIsNamedTheSameByTheScoreAndByTheNotification(): void
    {
        $basement = self::listing(-1);

        self::assertContains('sous-sol', self::scoreReasons($basement), 'the score must not invent a "-1er étage"');
        self::assertStringContainsString('· sous-sol', self::contextLine($basement));
        self::assertStringNotContainsString('RDC', self::contextLine($basement), 'a basement is not the ground floor');
    }

    /** The labels that were already right stay byte-identical on both surfaces. */
    public function testTheGroundAndFirstFloorsKeepTheirWording(): void
    {
        self::assertContains('rez-de-chaussée', self::scoreReasons(self::listing(0)));
        self::assertStringContainsString('· RDC', self::contextLine(self::listing(0)));

        self::assertContains('1er étage', self::scoreReasons(self::listing(1)));
        self::assertStringContainsString('· 1er étage', self::contextLine(self::listing(1)));

        self::assertStringContainsString('· 4e étage', self::contextLine(self::listing(4)));
    }
}
