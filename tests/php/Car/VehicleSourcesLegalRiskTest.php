<?php

declare(strict_types=1);

namespace Scout\Tests\Car;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Car\VehicleSourceDefinition;
use Scout\Car\VehicleSourceLoader;

/**
 * `legal_risk` ON THE CAR SOURCES IS A RECORD, NOT A GATE (audit 2026-10-02, P1-2; developer ruling).
 *
 * Alcopa and Autohero are polled `enabled: true` and nobody has read either's terms of use. Rent
 * refuses a `legal_risk` source without `--i-accept-legal-risk`; the car domain has no such gate,
 * and the developer chose to keep both polling and state the unknown instead. This pins the
 * statement: the two blocks carry the flag, the loader hands it through, and every other shipped
 * source is untouched.
 */
#[CoversClass(VehicleSourceLoader::class)]
final class VehicleSourcesLegalRiskTest extends TestCase
{
    public function testAlcopaAndAutoheroAreRecordedAsLegalRisk(): void
    {
        $sources = VehicleSourceLoader::load(__DIR__ . '/../../../config/car/sources.json');

        self::assertTrue($sources['alcopa']->legalRisk, 'alcopa: terms of use unread');
        self::assertTrue($sources['autohero']->legalRisk, 'autohero: terms of use unread');
    }

    public function testNoOtherShippedSourceIsFlagged(): void
    {
        $sources = VehicleSourceLoader::load(__DIR__ . '/../../../config/car/sources.json');
        $flagged = array_keys(array_filter($sources, static fn (VehicleSourceDefinition $d): bool => $d->legalRisk));
        sort($flagged);

        self::assertSame(['alcopa', 'autohero'], $flagged);
    }

    public function testTheFlagDefaultsToFalseAndMustBeABoolean(): void
    {
        $base = ['enabled' => false, 'family' => 'dealer', 'type' => 'fixture', 'fixture' => 'x.json'];
        $ok = VehicleSourceLoader::fromArray(['sources' => ['s' => $base]]);
        self::assertFalse($ok['s']->legalRisk);

        $this->expectException(\Scout\Config\ConfigError::class);
        VehicleSourceLoader::fromArray(['sources' => ['s' => $base + ['legal_risk' => 'yes']]]);
    }
}
