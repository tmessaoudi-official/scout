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
 * Agorastore (Track 6-B3, 2026-09-05) read through the SHIPPED source block — three real daily
 * alerts, twelve lots, every value asserted against a HAND-READ ground truth. The first AUCTION
 * source: no price on any card, no year or mileage except inside a free-text title, and a real
 * per-lot reference that IS the identity (the facts `ref` group), which is why lots stating
 * neither a year nor a mileage are still cars here.
 *
 * The scrubber learned two things on these captures — drop `X-Mailgun-Sid`, and keep a hex MIME
 * boundary as one placeholder — and these fixtures are the first it wrote correctly.
 */
#[CoversClass(VehicleEmailSource::class)]
final class AgorastoreFixtureTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../..';

    public function testTwelveLotsAcrossThreeMessagesEveryFactHandRead(): void
    {
        $lots = $this->source()->fetch();

        self::assertCount(12, $lots, 'five, three and four lots; no header, no support address, no unsubscribe tail');

        // FileMailbox reads newest by name: 09-03 (4 lots), 09-02 (3), 09-01 (5).
        $expected = [
            ['014683-S-4349', 'PEUGEOT 308 SW II 1.2 T 130 cv', '2026-09-03T08:17:41Z'],
            ['014685-S-4524', 'Voiture - Volvo - XC90', '2026-09-03T08:17:41Z'],
            ['013637-021', 'RENAULT CLIO SOCIETE 2 PLACES 2005 160903km 506EAB91', '2026-09-03T08:17:41Z'],
            ['014467-097', 'Peugeot 307 - 4775XQ74', '2026-09-03T08:17:41Z'],
            ['014579-008', 'Vehicule Peugeot 207', '2026-09-02T08:12:06Z'],
            ['014655-003', 'VEHICULE LEGER 9 PLACES 1090 VM 16 CP 0894', '2026-09-02T08:12:06Z'],
            ['014655-004', 'VEHICULE LEGER BJ 337 QN CP 0965', '2026-09-02T08:12:06Z'],
            ['011218-259', 'Fiat Punto 1.3 Multijet - 2013 - 212669km - DB714WD', '2026-09-01T08:12:00Z'],
            ['014684-S-4361', 'Voiture - Mercedes - CLK', '2026-09-01T08:12:00Z'],
            ['014684-S-4366', 'Voiture - Mitsubishi - L200', '2026-09-01T08:12:00Z'],
            ['011218-210', 'RENAULT KANGOO 2008 236706km 4443WL24', '2026-09-01T08:12:00Z'],
            ['014684-S-4364', 'Voiture - Land Rover', '2026-09-01T08:12:00Z'],
        ];

        foreach ($expected as $i => [$ref, $title, $sentAt]) {
            $lot = $lots[$i];
            self::assertSame($ref, $lot->externalId, 'lot ' . $i . ': the reference is the identity');
            self::assertSame($title, $lot->title, 'lot ' . $i);
            self::assertSame($sentAt, $lot->observedAt, 'lot ' . $i);
            self::assertNull($lot->priceEur, 'lot ' . $i . ': an auction states no price — unknown, never zero');
            self::assertNull($lot->year, 'lot ' . $i . ': never read out of a free-text title');
            self::assertNull($lot->mileageKm, 'lot ' . $i);
            self::assertStringStartsWith('https://email.alerts.agorastore.fr/c/', (string) $lot->url);
            self::assertNull($lot->postcode);
        }
    }

    public function testTwelveDistinctReferencesAndNoTrackingTokenAsAnId(): void
    {
        $ids = array_map(static fn (VehicleListing $l): string => $l->externalId, $this->source()->fetch());

        self::assertCount(12, array_unique($ids));
        foreach ($ids as $id) {
            self::assertMatchesRegularExpression('~^\d{6}-[A-Z0-9-]+$~', $id, 'a lot reference, never a Mailgun blob and never a hash');
        }
    }

    public function testEachLotsLinkIsItsOwnAndTheHeaderAndTailYieldNothing(): void
    {
        $warnings = [];
        $lots = $this->source(static function (string $w) use (&$warnings): void { $warnings[] = $w; })->fetch();
        $urls = array_map(static fn (VehicleListing $l): string => (string) $l->url, $lots);

        self::assertCount(12, array_unique($urls), 'twelve lots, twelve distinct redirects');
        self::assertSame([], $warnings, 'no duplicate, no furniture read as a lot');
    }

    public function testTheSourceIsAnAuctionAndDelegatesFreshness(): void
    {
        $source = $this->source();

        self::assertSame('auction', $source->family());
        self::assertNull($source->newestFeedItemAt());
    }

    private function source(?\Closure $warn = null): VehicleEmailSource
    {
        $definitions = VehicleSourceLoader::load(self::ROOT . '/config/car/sources.json');

        return new VehicleEmailSource(
            $definitions['agorastore'],
            new FileMailbox(self::ROOT . '/tests/fixtures/car/agorastore'),
            $warn,
        );
    }
}
