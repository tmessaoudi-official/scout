<?php

declare(strict_types=1);

namespace Scout\Tests\Car;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Adapters\Http\HttpClient;
use Scout\Core\SourceStatus;
use Scout\Adapters\Http\HttpError;
use Scout\Adapters\Http\HttpRequest;
use Scout\Adapters\Http\HttpResponse;
use Scout\Adapters\Http\Robots;
use Scout\Adapters\SourceError;
use Scout\Car\SitemapVehicleSource;
use Scout\Car\VehicleSourceDefinition;
use Scout\Car\VehicleSourceLoader;
use Scout\Car\VehiclePipeline;
use Scout\Car\VehicleCriteriaLoader;
use Scout\Core\Notify\Notifier;
use Scout\Car\VehicleStore;

/**
 * Autohero through the shipped block, against the frozen sitemap (5 of the real 3 387 lots) and the
 * measured lot page reduced to its JSON-LD. The novelty gate and the budget are the source's whole
 * economics; the Nissan facts are hand-read from the block captured on 2026-08-29.
 */
#[CoversClass(SitemapVehicleSource::class)]
final class AutoheroFixtureTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../..';
    private const string SITEMAP = 'https://www.autohero.com/fr/sitemap_search.xml';
    private const string NISSAN = 'https://www.autohero.com/fr/nissan-note/id/61bd7f63-e508-43a3-8ba0-a2908629f24d/';

    public function testTheMeasuredLotMapsToEveryHandReadFact(): void
    {
        $client = $this->client();
        $source = $this->source($client, budget: 5);

        $lots = $source->fetch();

        self::assertCount(5, $lots);
        $nissan = null;
        foreach ($lots as $lot) {
            if ($lot->externalId === '61bd7f63-e508-43a3-8ba0-a2908629f24d') {
                $nissan = $lot;
            }
        }
        self::assertNotNull($nissan, 'identity is the uuid in the lot URL');
        self::assertSame('Nissan Note 1.2 DIG-S Tekna CVT', $nissan->title);
        self::assertSame(7990, $nissan->priceEur);
        self::assertSame(2015, $nissan->year);
        self::assertSame(9, $nissan->month);
        self::assertSame(136278, $nissan->mileageKm, '"136 278 KMT" — narrow no-break space and the UN/CEFACT unit');
        self::assertSame('essence', $nissan->fuel);
        self::assertSame('automatique', $nissan->gearbox, '"Boite de vitesse automatique", no circumflex');
        self::assertSame('monospace', $nissan->body);
        self::assertSame('nissan', $nissan->make);
        self::assertSame('note', $nissan->model);
        self::assertSame('professional', $nissan->sellerType, 'a reseller');
        self::assertSame('DK55532', $nissan->fields['ref']);
        self::assertSame(self::NISSAN, $nissan->url);
        self::assertNull($nissan->postcode, 'no location field at all — decision 6 is inert here by measurement');
        self::assertNull($nissan->observedAt, 'a polled page is observed at the pass time');
    }

    public function testTheNoveltyGateAndTheBudgetBoundTheFetch(): void
    {
        $store = VehicleStore::open(':memory:');
        $client = $this->client();
        $source = $this->source($client, budget: 2, store: $store);

        $first = $source->fetch();
        self::assertCount(2, $first, 'budget 2 of 5 novel lots');
        self::assertCount(3, $client->urls, 'the sitemap plus two lot pages — never the whole catalogue');
        self::assertSame(5, $source->lastIndexSize(), 'health baselines on the FEED (the index), never on the novel slice');

        foreach ($first as $lot) {
            $store->record($lot, '2026-08-29T10:00:00Z');
        }
        $second = $source->fetch();
        self::assertCount(2, $second);
        self::assertNotSame(
            array_map(static fn ($l) => $l->externalId, $first),
            array_map(static fn ($l) => $l->externalId, $second),
            'recorded lots are never fetched again — steady state is the day\'s new lots',
        );
    }

    public function testSeedingRecordsTheWholeIndexWithoutFetchingALot(): void
    {
        $store = VehicleStore::open(':memory:');
        $client = $this->client();
        $source = $this->source($client, budget: 50, store: $store);

        $seed = $source->seedIndex();
        self::assertCount(5, $seed);
        self::assertSame([self::SITEMAP], $client->urls, 'the index only');
        foreach ($seed as $bare) {
            self::assertNull($bare->priceEur, 'nothing is known, nothing is judged');
            $store->record($bare, '2026-08-29T10:00:00Z');
        }

        self::assertSame([], $source->fetch(), 'after the seed the catalogue is the market already watched');
    }

    public function testEveryRequestIsRobotsCheckedAndARefusalIsLoud(): void
    {
        $this->expectException(SourceError::class);
        $this->expectExceptionMessageMatches('~robots~');

        $this->source($this->client(), budget: 2, robots: Robots::parse("User-agent: *\nDisallow: /fr/\n"))->fetch();
    }

    /**
     * THE LOT LOOP RE-CHECKS ROBOTS, AND ONLY AN ASYMMETRIC FILE PROVES IT.
     *
     * `testEveryRequestIsRobotsCheckedAndARefusalIsLoud` disallows `/fr/`, which covers the sitemap
     * itself — so it proves the check on the INDEX fetch and stops there. The lot loop's own check
     * was therefore untested, and the nightly ledger said so: "the sitemap source stops checking
     * robots.txt for lot pages" reported `undetected`, because deleting that line left the index
     * refusal — and every other assertion in this class — untouched.
     *
     * A lot fetch is one request PER LISTING against a path the index merely advertises; the index
     * being allowed says nothing about them. So the file here ALLOWS the sitemap and disallows the
     * lot pattern, which is the only shape that can distinguish the two call sites.
     */
    public function testALotPageIsRefusedEvenWhenTheIndexItselfIsAllowed(): void
    {
        $robots = Robots::parse("User-agent: *\nDisallow: /fr/*/id/\n");
        // `allows()` takes a PATH — `Robots::pathOf()` is what the source applies before asking, and
        // handing it a full URL answers `true` for everything, which would make both premises pass
        // for the wrong reason and the test vacuous.
        self::assertTrue($robots->allows(Robots::pathOf(self::SITEMAP)), 'premise: the index must be reachable, or this proves the other call site');
        self::assertFalse($robots->allows(Robots::pathOf(self::NISSAN)), 'premise: the lot pattern must be refused');

        $this->expectException(SourceError::class);
        $this->expectExceptionMessageMatches('~robots~');

        $this->source($this->client(), budget: 2, robots: $robots)->fetch();
    }

    /**
     * THE PIPELINE BASELINES HEALTH ON THE INDEX, NOT ON THE NOVEL SLICE — and only the pipeline
     * can be asked that. `testTheNoveltyGateAndTheBudgetBoundTheFetch` asserts the SOURCE exposes
     * `lastIndexSize()`; nothing asserted that `VehiclePipeline` then RECORDS it, so the nightly
     * ledger reported "the car pipeline baselines a sitemap source's health on its novel lots, not
     * its index" as undetected — the conditional could be flattened to `count($listings)` with the
     * whole suite green.
     *
     * That is a hard-rule-2 failure with a delay fuse. A sitemap source's novel count FALLS to near
     * zero once the catalogue is watched — that is the novelty gate working — so a health baseline
     * built on it decays every pass until the source reports `broken` on a feed that never stopped.
     * The index size is the one figure that measures the FEED.
     *
     * Novel < index is forced with the BUDGET (1 of 5) rather than by seeding the store, so the
     * gap is created by the source's own economics and no fixture has to be pre-recorded.
     *
     * `Cli/CarScout.php:185` carries the same conditional for `doctor` — a second symmetric
     * surface, which is the shape this repo keeps paying for. It is not covered here.
     */
    public function testTheRecordedItemCountIsTheIndexSizeNotTheNovelLotCount(): void
    {
        $store = VehicleStore::open(':memory:');
        $source = $this->source($this->client(), budget: 1, store: $store);
        $pipeline = new VehiclePipeline(
            VehicleCriteriaLoader::fromArray(VehicleCriteriaTest::minimal()),
            $store,
            new Notifier([new CarRecordingChannel()]),
        );

        $result = $pipeline->runOnce([$source], '2026-08-29T10:00:00Z');

        self::assertSame([], $result->errors, 'premise: the pass must succeed, or the count below is not the one under test');
        self::assertSame(5, $source->lastIndexSize(), 'premise: the index really does hold five lots');

        $health = $store->runs()->health('autohero', '2026-08-29T10:05:00Z');
        self::assertSame(
            5,
            $health->lastCount,
            'the feed is five lots; one of them was novel under the budget. Recording 1 makes the '
            . 'baseline decay to zero as the catalogue is watched, and the source reports broken.',
        );
    }

    public function testABrokenLotPageIsWarnedAndSkippedNeverAnEmptyPass(): void
    {
        $table = $this->table();
        $table[self::NISSAN] = new HttpResponse(404, 'gone');
        $warnings = [];
        $source = $this->source(new TableHttpClient($table), budget: 5, warn: static function (string $w) use (&$warnings): void { $warnings[] = $w; });

        $lots = $source->fetch();

        self::assertCount(4, $lots);
        self::assertCount(1, $warnings);
        self::assertStringContainsString('404', $warnings[0]);
    }

    public function testTheRateLimitIsHonouredBetweenLotFetches(): void
    {
        $slept = [];
        $source = $this->source($this->client(), budget: 2, rateLimitMs: 2000, sleeper: static function (int $ms) use (&$slept): void { $slept[] = $ms; });

        $source->fetch();

        self::assertSame([2000, 2000], $slept, 'one pause per lot page, none for the sitemap');
    }

    public function testAnEmptyOrObsoleteSitemapIsRefusedNotReadAsAQuietMarket(): void
    {
        $this->expectException(SourceError::class);
        $table = $this->table();
        $table[self::SITEMAP] = new HttpResponse(200, '<urlset></urlset>');
        $this->source(new TableHttpClient($table), budget: 2)->fetch();
    }

    // ------------------------------------------------------------------------------------------

    /** @return array<string, HttpResponse> */
    private function table(): array
    {
        $sitemap = (string) file_get_contents(self::ROOT . '/tests/fixtures/car/autohero/sitemap_search.xml');
        $lot = (string) file_get_contents(self::ROOT . '/tests/fixtures/car/autohero/lot-61bd7f63.html');
        $table = [self::SITEMAP => new HttpResponse(200, $sitemap, ['content-type' => 'text/xml'])];
        preg_match_all('~<loc>\s*([^<\s]+)\s*</loc>~', $sitemap, $m);
        foreach ($m[1] as $url) {
            $table[$url] = new HttpResponse(200, $lot, ['content-type' => 'text/html']);
        }

        return $table;
    }

    private function client(): TableHttpClient
    {
        return new TableHttpClient($this->table());
    }

    /**
     * C-3 — A JSON-LD KEY THAT RESOLVES NOWHERE REACHES `health()` AS A WARN.
     *
     * This source extracted twelve configured map keys and counted NONE of them: `PatternMissLog`
     * reached the two email adapters and then the four rent html/json ones, and this was the last
     * extraction surface with no instrumentation at all (C2 round-1 completeness lens, 2026-09-02).
     * autohero is `enabled: true`, so a key the reseller renames would go null on every lot while
     * `item_count` did not move, no run failed, and `doctor` said `ok` — hard rule 2's shape.
     *
     * Broken by rewriting the MAP rather than by editing the shipped config: the guarantee is that a
     * dead key is REPORTED, and proving it must not depend on the repo shipping one.
     */
    public function testAMapKeyThatResolvesNowhereWarnsThroughHealth(): void
    {
        $store = VehicleStore::open(':memory:');
        foreach (['2026-08-30T09:00:00+00:00', '2026-08-31T09:00:00+00:00', '2026-09-01T09:00:00+00:00'] as $at) {
            $store->runs()->recordRun('autohero', 5, true, null, $at, 20);
        }

        $source = $this->source($this->client(), budget: 5, store: $store, mapOverrides: ['make' => 'il_ny_a_rien_ici']);
        $source->fetch();

        self::assertSame(['make'], $source->patternMisses()->total());

        $health = $source->health('2026-09-01T12:00:00+00:00');
        self::assertSame(SourceStatus::WARN_DROP, $health->status);
        self::assertStringContainsString('make', $health->detail);
    }

    /**
     * A COUNT NEVER SPANS TWO FETCHES.
     *
     * The source object outlives the pass — the CLI builds its sources once and the watch loop
     * closes over them — so without the reset a template already fixed keeps warning for ever. That
     * sends an operator to read a capture that is fine and teaches them to ignore the signal, which
     * is worse than silence because it is credible. It is also the contract `CountsPatternMisses`
     * states outright: implementing it promises `reset()` at the start of every fetch.
     */
    public function testTheCountIsPerPassAndNeverAccumulates(): void
    {
        $source = $this->source($this->client(), budget: 5, mapOverrides: ['make' => 'il_ny_a_rien_ici']);

        $source->fetch();
        $first = $source->patternMisses()->counts();
        $source->fetch();

        self::assertSame($first, $source->patternMisses()->counts(), 'a second identical pass must read identically');
    }

    /** The counterweight: the shipped map resolves, so nothing is warned about. */
    public function testTheShippedMapReportsNoBlindKey(): void
    {
        $source = $this->source($this->client(), budget: 5);
        $source->fetch();

        self::assertSame([], $source->patternMisses()->total());
    }

    /** @param array<string, string> $mapOverrides */
    private function source(
        HttpClient $client,
        int $budget,
        ?VehicleStore $store = null,
        ?Robots $robots = null,
        ?\Closure $warn = null,
        int $rateLimitMs = 0,
        ?\Closure $sleeper = null,
        array $mapOverrides = [],
    ): SitemapVehicleSource {
        $shipped = VehicleSourceLoader::load(self::ROOT . '/config/car/sources.json')['autohero'];
        $definition = new VehicleSourceDefinition(
            name: $shipped->name, enabled: true, family: $shipped->family, type: $shipped->type,
            url: $shipped->url, itemUrlPattern: $shipped->itemUrlPattern,
            map: [...$shipped->map, ...$mapOverrides],
            lotBudgetPerPass: $budget, rateLimitMs: $rateLimitMs,
        );

        return new SitemapVehicleSource(
            $definition,
            $store ?? VehicleStore::open(':memory:'),
            $client,
            $robots ?? Robots::parse((string) file_get_contents(self::ROOT . '/tests/fixtures/car/autohero/robots.txt')),
            $warn,
            $sleeper,
        );
    }
}

/** Answers from a table; an unscripted URL is an error, never a silent empty body. */
final class TableHttpClient implements HttpClient
{
    /** @var list<string> */
    public array $urls = [];

    /** @param array<string, HttpResponse> $table */
    public function __construct(private readonly array $table) {}

    public function send(HttpRequest $request): HttpResponse
    {
        $this->urls[] = $request->url;
        if (!isset($this->table[$request->url])) {
            throw new HttpError('TableHttpClient: no response scripted for ' . $request->url);
        }

        return $this->table[$request->url];
    }
}
