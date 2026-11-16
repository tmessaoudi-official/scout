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
 * La Centrale (Track 6-B2, 2026-09-05) read through the SHIPPED source block — three real alerts
 * across both subject templates, three cards each, every value asserted against a HAND-READ ground
 * truth. The template states no year, truncates the title at ~28 characters, and wraps every link
 * in a per-recipient redirect, so this is the source that exercises the content key's two stated
 * costs: no age component, and mileage as the only thing separating two same-titled cars.
 *
 * All three captures carried the subscriber's address base64-encoded and FOLDED inside an
 * `X-MSFBL` header; the scrubber passed two of them and `FixtureSecretsTest` caught the leak one
 * commit before a push. The tool unfolds headers now and drops the header by name — these
 * fixtures are the first scrubbed by the corrected tool.
 */
#[CoversClass(VehicleEmailSource::class)]
final class LaCentraleFixtureTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../..';

    public function testNineCardsAcrossThreeMessagesEveryFactHandRead(): void
    {
        $cars = $this->source()->fetch();

        self::assertCount(9, $cars, 'three per message, both templates admitted, no header and no unsubscribe tail');

        // FileMailbox reads newest by name: 09-04-001 (06:38), then 09-03-002 (14:34), then 09-03-001 (06:34).
        [$kangooC, $x4C, $hrv, $kangooB, $x4B, $p3008b, $ux, $p3008a, $transit] = $cars;

        self::assertSame('RENAULT KANGOO II EXPRESS p...', $kangooC->title, 'truncated by the portal, kept as sent');
        self::assertSame('renault', $kangooC->make);
        self::assertSame('kangoo', $kangooC->model);
        self::assertSame(8888, $kangooC->priceEur);
        self::assertSame(95902, $kangooC->mileageKm);
        self::assertNull($kangooC->year, 'the template states no year — unknown, never zero (hard rule 9)');
        self::assertNull($kangooC->fuel);
        self::assertNull($kangooC->gearbox);
        self::assertSame('2026-09-04T06:38:32Z', $kangooC->observedAt);

        self::assertSame('BMW X4 F98 M', $x4C->title);
        self::assertSame('bmw', $x4C->make);
        self::assertSame('x4', $x4C->model);
        self::assertSame(48900, $x4C->priceEur);
        self::assertSame(69056, $x4C->mileageKm);

        self::assertSame('HONDA HR-V II phase 2', $hrv->title);
        self::assertSame('honda', $hrv->make);
        self::assertSame('hr-v', $hrv->model);
        self::assertSame(19990, $hrv->priceEur);
        self::assertSame(28402, $hrv->mileageKm);

        self::assertSame('RENAULT KANGOO II EXPRESS p...', $kangooB->title);
        self::assertSame(95902, $kangooB->mileageKm);
        self::assertSame('2026-09-03T14:34:20Z', $kangooB->observedAt);

        self::assertSame('BMW X4 F98 M', $x4B->title);
        self::assertSame(69056, $x4B->mileageKm);

        self::assertSame('PEUGEOT 3008 II', $p3008b->title);
        self::assertSame('peugeot', $p3008b->make);
        self::assertSame('3008', $p3008b->model);
        self::assertSame(21990, $p3008b->priceEur);
        self::assertSame(60459, $p3008b->mileageKm);

        self::assertSame('LEXUS UX', $ux->title);
        self::assertSame('lexus', $ux->make);
        self::assertSame('ux', $ux->model);
        self::assertSame(22990, $ux->priceEur);
        self::assertSame(84135, $ux->mileageKm);
        self::assertSame('2026-09-03T06:34:13Z', $ux->observedAt);

        self::assertSame('PEUGEOT 3008 II', $p3008a->title);
        self::assertSame(21990, $p3008a->priceEur);
        self::assertSame(60459, $p3008a->mileageKm);

        self::assertSame('FORD TRANSIT IV', $transit->title);
        self::assertSame('ford', $transit->make);
        self::assertSame('transit', $transit->model);
        self::assertSame(23990, $transit->priceEur);
        self::assertSame(34074, $transit->mileageKm);

        foreach ($cars as $car) {
            self::assertStringStartsWith('https://clicks.mail-alerte.lacentrale.fr/f/a/', (string) $car->url);
            self::assertNull($car->postcode);
        }
    }

    /**
     * THE SAME CAR IN TWO MESSAGES IS ONE IDENTITY. The Kangoo and the X4 are re-sent the next
     * morning, the 3008 half a day later — each behind a fresh redirect token. Content identity
     * says one car each; link identity would have said two. Six identities for nine cards.
     */
    public function testACarReSentInALaterMessageKeepsItsIdentityAcrossMessages(): void
    {
        $cars = $this->source()->fetch();
        $ids = array_map(static fn (VehicleListing $c): string => $c->externalId, $cars);

        self::assertCount(6, array_unique($ids), 'nine cards, six cars');
        self::assertSame($ids[0], $ids[3], 'the Kangoo of 09-04 is the Kangoo of 09-03');
        self::assertSame($ids[1], $ids[4], 'the X4 likewise');
        self::assertSame($ids[5], $ids[7], 'the 3008 in message 002 is the 3008 in message 001');
        self::assertNotSame((string) $cars[0]->url, (string) $cars[3]->url, 'behind two different per-recipient tokens');
        foreach ($ids as $id) {
            self::assertMatchesRegularExpression('~^[0-9a-f]{40}$~', $id);
        }
    }

    public function testTheUnsubscribeTailIsFurnitureAndRaisesNoWarning(): void
    {
        $warnings = [];
        $cars = $this->source(static function (string $w) use (&$warnings): void { $warnings[] = $w; })->fetch();

        self::assertCount(9, $cars);
        self::assertSame([], $warnings, 'the tail after the last Détails carries a host link and no facts — not a card, and not a duplicate');
    }

    public function testTheSourceDelegatesFreshnessRatherThanInventingIt(): void
    {
        self::assertNull($this->source()->newestFeedItemAt());
    }

    private function source(?\Closure $warn = null): VehicleEmailSource
    {
        $definitions = VehicleSourceLoader::load(self::ROOT . '/config/car/sources.json');

        return new VehicleEmailSource(
            $definitions['lacentrale'],
            new FileMailbox(self::ROOT . '/tests/fixtures/car/lacentrale'),
            $warn,
        );
    }
}
