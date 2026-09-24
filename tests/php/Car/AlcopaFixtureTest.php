<?php

declare(strict_types=1);

namespace Scout\Tests\Car;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Adapters\Http\HttpClient;
use Scout\Adapters\Http\HttpRequest;
use Scout\Adapters\Http\HttpResponse;
use Scout\Adapters\Http\Robots;
use Scout\Adapters\SourceError;
use Scout\Car\AlcopaVehicleSource;
use Scout\Car\VehicleClassifier;
use Scout\Car\VehicleFormatter;
use Scout\Car\VehicleListing;
use Scout\Car\VehicleSourceDefinition;
use Scout\Car\VehicleSourceLoader;
use Scout\Car\VehicleStore;
use Scout\Car\VehicleVerdict;

/**
 * ALCOPA THROUGH THE ADAPTER, AGAINST PAGES CAPTURED ON 2026-09-24 — THE VALUES HAND-READ.
 *
 * `search-1/2.html` are a real two-page search (25 lots: the saved search narrowed to <= 20 000 km,
 * so the whole walk fits in two frozen pages); `lot-*.html` are three of its lots — two LIVE, in
 * two different salerooms, and one ONLINE; `sale-*.html` are those two LIVE sales. Only anonymous
 * CSRF values were scrubbed, each distinct value to its own placeholder.
 *
 * The clock is 2026-09-24 23:00 Paris unless a test moves it. 22 of the 25 lots are pre-seeded as
 * already seen, which is the novelty gate doing its job: only the three captured lot pages are
 * ever requested.
 */
#[CoversClass(AlcopaVehicleSource::class)]
final class AlcopaFixtureTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../..';
    private const string DIR = self::ROOT . '/tests/fixtures/car/alcopa/';
    private const string SEARCH = 'https://www.alcopa-auction.fr/recherche?categories%5B%5D=VP&years%5B%5D=2020-2100&price_max=30000&km_max=20000&sites%5B%5D=1&sites%5B%5D=45&sites%5B%5D=21';
    private const array CAPTURED = ['1119509', '1120464', '1111335'];
    private const string NOW = '2026-09-24T21:00:00Z';

    public function testTheThreeNovelLotsAreReadAsHandRead(): void
    {
        $byId = $this->byId($this->source()->fetch());

        self::assertSame(self::CAPTURED, array_map('strval', array_keys($byId)));

        // LIVE, Paris Sud: `Horaires : 10:00 - 18:00` on Monday 28 September — CEST, so 08:00Z–16:00Z.
        $jeep = $byId['1119509'];
        self::assertSame('JEEP AVENGER ELECTRIQUE AVENGER 115 KW 4X2 1ST EDITION', $jeep->title);
        self::assertSame(['jeep', 'avenger electrique', 2023, 18437, 'electrique', 'automatique'], [$jeep->make, $jeep->model, $jeep->year, $jeep->mileageKm, $jeep->fuel, $jeep->gearbox]);
        self::assertSame(['2026-09-28T08:00:00Z', '2026-09-28T16:00:00Z'], [$jeep->saleOpensAt, $jeep->closingAt]);
        self::assertSame('https://www.alcopa-auction.fr/voiture-occasion/jeep/avenger-115-kw-4x2-1st-edition-1119509', $jeep->url);
        self::assertSame(['mise_a_prix' => '--', 'site' => 'Paris Sud', 'lot' => '497', 'date_vente' => '28/09/2026'], $jeep->fields);
        self::assertNull($jeep->priceEur, 'a starting price is not a price');

        // LIVE, Beauvais sale 13026 — "véhicules récents et grêlés", 09:30 - 18:00.
        $peugeot = $byId['1120464'];
        self::assertSame(['2026-10-12T07:30:00Z', '2026-10-12T16:00:00Z'], [$peugeot->saleOpensAt, $peugeot->closingAt]);
        self::assertSame('Commentaires : Véhicule grêlé - gps défaillant - sans roue de secours - euro 6', $peugeot->description);
        self::assertSame(['essence', 'manuelle', '2 400'], [$peugeot->fuel, $peugeot->gearbox, $peugeot->fields['mise_a_prix']]);

        // ONLINE, sale 12902: opens at the lot page's `23-09-2026 16:00:00`, closes at the card's own
        // countdown, 25/09 15:20 — per lot, which the sale header could not have said.
        $dacia = $byId['1111335'];
        self::assertSame(['2026-09-23T14:00:00Z', '2026-09-25T13:20:00Z'], [$dacia->saleOpensAt, $dacia->closingAt]);
        self::assertStringContainsString('Carte grise sous 30 jours ouvrés', $dacia->description);
        self::assertStringContainsString('Liquide de refroidissement à contrôler', $dacia->description);
    }

    /** The two blocks and nothing else: page furniture reaching the classifier is a verdict about the site. */
    public function testTheDescriptionCarriesNoPageFurniture(): void
    {
        foreach ($this->source()->fetch() as $lot) {
            foreach (['Garantie', 'Comment placer', 'Conditions générales', 'Information sur la salle'] as $furniture) {
                self::assertStringNotContainsString($furniture, $lot->description, $lot->externalId);
            }
        }
    }

    /** The lot page is what the vehicle classifier reads — and these three honest descriptions pass it. */
    public function testTheClassifierReadsTheLotPageEvidence(): void
    {
        $classifier = new VehicleClassifier();
        foreach ($this->source()->fetch() as $lot) {
            self::assertSame('MATCH', $classifier->classify($lot)->outcome->value, $lot->externalId);
        }

        $wreck = new VehicleListing(sourceName: 'alcopa', externalId: 'x', description: 'Commentaires : véhicule accidenté - non roulant');
        self::assertNotSame('MATCH', $classifier->classify($wreck)->outcome->value, 'the same surface carries the excluded set');
    }

    /** Rule 2 on the phone: the push says when each lot stops being worth opening. */
    public function testThePushCarriesEachLotsClosing(): void
    {
        $byId = $this->byId($this->source()->fetch());
        $title = static fn (VehicleListing $l): string => (new VehicleFormatter())->match($l, VehicleVerdict::matched(60, [], false))->title;

        self::assertStringEndsWith(' · vente le 28/09 10:00–18:00', $title($byId['1119509']));
        self::assertStringEndsWith(' · vente le 12/10 09:30–18:00', $title($byId['1120464']));
        self::assertStringEndsWith(' · clôture 25/09 15:20', $title($byId['1111335']));
    }

    /** The walk: two pages, checked against the stated 25, and only the novel lots' pages after. */
    public function testTheWalkIsCheckedAndOnlyNovelLotsAreOpened(): void
    {
        $client = $this->client();
        $source = $this->source($client);
        $source->fetch();

        self::assertSame(25, $source->lastIndexSize());
        self::assertSame(self::SEARCH, $client->urls[0]);
        self::assertSame(self::SEARCH . '&page=2', $client->urls[1]);
        $lotPages = array_values(array_filter($client->urls, static fn (string $u): bool => str_contains($u, '-occasion/')));
        self::assertCount(3, $lotPages, 'a lot already in the seen-set is never opened again');
        self::assertCount(2, array_filter($client->urls, static fn (string $u): bool => str_contains($u, '/salle-de-vente-encheres/')), 'one sale page per LIVE sale per pass');
    }

    public function testTheSeedReadsTheIndexAndOpensNoLot(): void
    {
        $client = $this->client();
        $seed = $this->source($client, seeded: false)->seedIndex();

        self::assertCount(25, $seed);
        self::assertSame([self::SEARCH, self::SEARCH . '&page=2'], $client->urls);
    }

    /** The counterweight: real pages, not one rule blind, nothing warned. */
    public function testNothingIsBlindAndNothingIsWarnedOnRealPages(): void
    {
        $warnings = [];
        $source = $this->source(warn: static function (string $w) use (&$warnings): void {
            $warnings[] = $w;
        });
        $source->fetch();

        self::assertSame([], $warnings);
        self::assertSame([], $source->patternMisses()->total());
        foreach ($source->patternMisses()->counts() as $key => $c) {
            self::assertSame(0, $c['misses'], $key . ' missed on a real page');
        }
    }

    /** Past its countdown, a card is dropped before its lot page is ever requested. */
    public function testAnEndedCountdownCostsNoRequest(): void
    {
        $client = $this->client();
        $warnings = [];
        $lots = $this->source($client, now: '2026-09-25T14:00:00Z', warn: static function (string $w) use (&$warnings): void {
            $warnings[] = $w;
        })->fetch();

        self::assertNotContains('1111335', array_map(static fn (VehicleListing $l): string => $l->externalId, $lots));
        self::assertEmpty(array_filter($client->urls, static fn (string $u): bool => str_ends_with($u, '-1111335')));
        self::assertNotSame([], array_filter($warnings, static fn (string $w): bool => str_contains($w, 'compte à rebours est échu')));
    }

    /** A window that ends before it opens is not a closing time. */
    public function testAWindowEndingBeforeItOpensIsRefused(): void
    {
        $lots = $this->source($this->client(['sale-paris-sud-10350.html' => static fn (string $h): string => (string) preg_replace('~<b>18:00</b>~', '<b>00:30</b>', $h, 1)]))->fetch();

        self::assertNotContains('1119509', array_map(static fn (VehicleListing $l): string => $l->externalId, $lots));
    }

    /**
     * A closing already past is never announced. Reachable only when a card carries no countdown
     * (otherwise the countdown, earlier than any LIVE window's end, drops it before the lot fetch),
     * so the countdown is removed and the clock moved past the Paris Sud window.
     */
    public function testAClosingAlreadyPastIsNotAnnounced(): void
    {
        $warnings = [];
        $lots = $this->source(
            $this->client(['search-1.html' => static fn (string $h): string => (string) preg_replace('~data-ts="1790580600"~', 'data-x="1790580600"', $h)]),
            now: '2026-09-28T17:00:00Z',
            warn: static function (string $w) use (&$warnings): void {
                $warnings[] = $w;
            },
        )->fetch();

        self::assertNotContains('1119509', array_map(static fn (VehicleListing $l): string => $l->externalId, $lots));
        self::assertNotSame([], array_filter($warnings, static fn (string $w): bool => str_contains($w, 'lot 1119509') && str_contains($w, 'déjà passée')));
    }

    public function testALotPageNamingTwoSalesIsRefused(): void
    {
        $warnings = [];
        $lots = $this->source(
            $this->client(['lot-1111335.html' => static fn (string $h): string => str_replace('</body>', '<a href="/vente-encheres-en-ligne/99999">x</a></body>', $h)]),
            warn: static function (string $w) use (&$warnings): void {
                $warnings[] = $w;
            },
        )->fetch();

        self::assertNotContains('1111335', array_map(static fn (VehicleListing $l): string => $l->externalId, $lots));
        self::assertNotSame([], array_filter($warnings, static fn (string $w): bool => str_contains($w, '2 vente(s)')));
    }

    public function testALotPageNamingNoSaleIsRefused(): void
    {
        $lots = $this->source($this->client(['lot-1111335.html' => static fn (string $h): string => str_replace('/vente-encheres-en-ligne/12902', '/ailleurs', $h)]))->fetch();

        self::assertNotContains('1111335', array_map(static fn (VehicleListing $l): string => $l->externalId, $lots));
    }

    public function testASaleWhoseDayDisagreesWithTheLotPageIsRefused(): void
    {
        $lots = $this->source($this->client(['sale-beauvais-13026.html' => static fn (string $h): string => str_replace('Lundi 12 octobre 2026', 'Mardi 13 octobre 2026', $h)]))->fetch();

        self::assertNotContains('1120464', array_map(static fn (VehicleListing $l): string => $l->externalId, $lots));
        self::assertContains('1119509', array_map(static fn (VehicleListing $l): string => $l->externalId, $lots), 'a different sale is unaffected');
    }

    public function testAnOnlineLotWithNoCountdownIsRefused(): void
    {
        $lots = $this->source($this->client(['search-1.html' => static fn (string $h): string => str_replace('data-ts="1790342400"', 'data-x="1790342400"', $h)]))->fetch();

        self::assertNotContains('1111335', array_map(static fn (VehicleListing $l): string => $l->externalId, $lots));
    }

    /** A lost page reads exactly like a thin market, so a shortfall of one page or more throws. */
    public function testAWalkShortOfTheStatedCountThrows(): void
    {
        $this->expectException(SourceError::class);
        $this->expectExceptionMessage('la pagination a perdu des pages');
        // The site ignoring `page=`: page 2 answers page 1 again, so 20 distinct lots for 25 stated.
        $first = (string) file_get_contents(self::DIR . 'search-1.html');
        $this->source($this->client(['search-2.html' => static fn (string $h): string => $first]))->fetch();
    }

    public function testASearchThatNoLongerStatesItsCountThrows(): void
    {
        $this->expectException(SourceError::class);
        $this->source($this->client(['search-1.html' => static fn (string $h): string => preg_replace('~</b>(\s*)R(é|&eacute;)sultat~u', '</b>$1Total', $h)]))->fetch();
    }

    public function testRobotsIsReadForEveryPage(): void
    {
        $this->expectException(SourceError::class);
        $this->expectExceptionMessage('robots.txt');
        $this->source(robots: Robots::parse("User-agent: *\nDisallow: /voiture-occasion/\n"))->fetch();
    }

    /** An unknown fuel code is unknown — never `autre`, which would be a fact about the car. */
    public function testAnUnseenFuelCodeIsUnknown(): void
    {
        $lots = $this->byId($this->source($this->client(['search-1.html' => static fn (string $h): string => preg_replace('~<p class="mb-1">EL~', '<p class="mb-1">ZZ', $h, 1)]))->fetch());

        self::assertNull($lots['1119509']->fuel);
    }

    public function testTheShippedBlockLoadsDisabledOrEnabledAsTheAdapterType(): void
    {
        $shipped = VehicleSourceLoader::load(self::ROOT . '/config/car/sources.json')['alcopa'];

        self::assertSame(['alcopa', 'auction'], [$shipped->type, $shipped->family]);
        self::assertStringStartsWith('https://www.alcopa-auction.fr/recherche?', (string) $shipped->url);
    }

    /** @param list<VehicleListing> $lots @return array<string, VehicleListing> */
    private function byId(array $lots): array
    {
        $out = [];
        foreach ($lots as $lot) {
            $out[$lot->externalId] = $lot;
        }

        return $out;
    }

    /** @param array<string, \Closure(string): string> $edits fixture file => rewrite */
    private function client(array $edits = []): AlcopaTableClient
    {
        return new AlcopaTableClient(self::DIR, self::SEARCH, $edits);
    }

    /** @param ?\Closure(string): void $warn */
    private function source(?AlcopaTableClient $client = null, bool $seeded = true, string $now = self::NOW, ?\Closure $warn = null, ?Robots $robots = null): AlcopaVehicleSource
    {
        $client ??= $this->client();
        $store = VehicleStore::open(':memory:');
        $definition = new VehicleSourceDefinition(name: 'alcopa', enabled: true, family: 'auction', type: 'alcopa', url: self::SEARCH, lotBudgetPerPass: 25, rateLimitMs: 0);
        if ($seeded) {
            $bare = new AlcopaVehicleSource($definition, VehicleStore::open(':memory:'), $client, $this->robots());
            foreach ($bare->seedIndex() as $lot) {
                if (!in_array($lot->externalId, self::CAPTURED, true)) {
                    $store->record($lot, '2026-09-24T20:00:00Z');
                }
            }
            $client->urls = [];
        }

        return new AlcopaVehicleSource($definition, $store, $client, $robots ?? $this->robots(), $warn, null, static fn (): int => (int) strtotime($now));
    }

    private function robots(): Robots
    {
        return Robots::parse((string) file_get_contents(self::DIR . 'robots.txt'));
    }
}

/** Serves the frozen pages by URL; anything unscripted is a 404, which the adapter must warn about. */
final class AlcopaTableClient implements HttpClient
{
    /** @var list<string> */
    public array $urls = [];

    /** @param array<string, \Closure(string): string> $edits */
    public function __construct(private readonly string $dir, private readonly string $search, private readonly array $edits) {}

    public function send(HttpRequest $request): HttpResponse
    {
        $this->urls[] = $u = $request->url;
        $file = match (true) {
            $u === $this->search => 'search-1.html',
            $u === $this->search . '&page=2' => 'search-2.html',
            preg_match('~-occasion/.+-(\d+)$~', $u, $m) === 1 => 'lot-' . $m[1] . '.html',
            preg_match('~/salle-de-vente-encheres/([a-z-]+)/(\d+)$~', $u, $m) === 1 => 'sale-' . $m[1] . '-' . $m[2] . '.html',
            default => null,
        };
        if ($file === null || !is_file($this->dir . $file)) {
            return new HttpResponse(404, '');
        }
        $body = (string) file_get_contents($this->dir . $file);
        if (isset($this->edits[$file])) {
            $body = ($this->edits[$file])($body);
        }

        return new HttpResponse(200, $body);
    }
}
