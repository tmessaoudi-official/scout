<?php

declare(strict_types=1);

namespace Scout\Tests\Car;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Car\VehicleListing;
use Scout\Car\VehicleSnapshot;

#[CoversClass(VehicleSnapshot::class)]
final class VehicleSnapshotTest extends TestCase
{
    public function testRoundTripPreservesEveryField(): void
    {
        $listing = new VehicleListing(
            sourceName: 'paruvendu', externalId: '1294764484A1KVVOREAUS', title: 'Renault Austral',
            description: '4x4 - SUV - Essence', fields: ['k' => 'v', 'n' => 3], url: 'https://x.test/a',
            make: 'Renault', model: 'Austral', priceEur: 21000, year: 2023, month: 4, mileageKm: 26000,
            fuel: 'essence', gearbox: 'automatique', body: 'suv', sellerType: 'professional',
            postcode: '78500', observedAt: '2026-08-29T17:05:14Z',
            saleOpensAt: '2026-09-22T17:00:00Z', closingAt: '2026-09-25T13:00:00Z',
        );

        $back = VehicleSnapshot::decode(VehicleSnapshot::encode($listing));

        foreach ((new \ReflectionClass(VehicleListing::class))->getConstructor()?->getParameters() ?? [] as $p) {
            $name = $p->getName();
            self::assertSame($listing->$name, $back->$name, 'round trip lost ' . $name);
        }
    }

    public function testUnknownsStayNullNeverZero(): void
    {
        $back = VehicleSnapshot::decode(VehicleSnapshot::encode(new VehicleListing(sourceName: 's', externalId: 'x')));

        self::assertNull($back->priceEur);
        self::assertNull($back->mileageKm);
        self::assertNull($back->year);
        self::assertNull($back->observedAt);
    }

    /** Tomorrow's property cannot leave the snapshot silently: every constructor parameter is encoded. */
    public function testEncoderCoversEveryConstructorParameter(): void
    {
        $encoded = json_decode(VehicleSnapshot::encode(new VehicleListing(sourceName: 's', externalId: 'x')), true);
        foreach ((new \ReflectionClass(VehicleListing::class))->getConstructor()?->getParameters() ?? [] as $p) {
            self::assertArrayHasKey($p->getName(), $encoded, 'VehicleSnapshot::encode() does not cover ' . $p->getName());
        }
    }

    public function testMalformedSnapshotIsRefusedLoudly(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        VehicleSnapshot::decode('{"nope": true}');
    }
}
