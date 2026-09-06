<?php

declare(strict_types=1);

namespace Scout\Tests\Rent\Adapters;

use PHPUnit\Framework\TestCase;
use Scout\Adapters\Http\HttpClient;
use Scout\Adapters\Http\HttpError;
use Scout\Adapters\Http\HttpRequest;
use Scout\Adapters\Http\HttpResponse;
use Scout\Adapters\Http\Robots;
use Scout\Rent\Adapters\DetailHydrator;
use Scout\Rent\Adapters\HtmlSource;
use Scout\Adapters\SourceError;
use Scout\Rent\Config\FieldMap;
use Scout\Rent\Config\SourceDefinition;
use Scout\Rent\Core\RawListing;
use Scout\Rent\Store\Store;

/**
 * Detail-page hydration: the second request a listing sometimes needs, and the gate that decides.
 *
 * Cityloger (the Immobilière 3F group's lettings platform) is why this exists. Its search cards
 * carry rent, rooms, surface, commune and floor — and NO tenure at all. The tenure lives on the
 * detail page, which means that on a mixed-tenure source every listing would resolve UNKNOWN and go
 * to the à-vérifier digest forever: correct under §1, and useless.
 *
 * Two things make this safe rather than a request storm, and both are asserted below:
 *
 * - **A gate decides who is worth a second request.** It is the source's own geographic criteria —
 *   `Criteria::matchesCommune()`, the one filter whose inputs the CARD already carries in full, so
 *   gating on it cannot reject on a field the detail page would have filled (hard rule 8). 51
 *   national listings become the handful in the watched communes.
 * - **A detail map selects the listing's own content, never the page.** Measured 2026-08-20 on the
 *   real Antony payload: the scoped `.description` classifies LLI at 0.90, and the SAME listing fed
 *   its whole detail page classifies UNKNOWN at 0.00 — the page's furniture ("Commission
 *   d'attribution", "demande de logement social") appears on social and intermediate listings alike
 *   and conflicts the verdict away. That is the CDC `au plus près` failure class on a new surface.
 */
final class HtmlSourceDetailTest extends TestCase
{
    private ?string $dbPath = null;

    protected function tearDown(): void
    {
        if ($this->dbPath !== null && file_exists($this->dbPath)) {
            unlink($this->dbPath);
        }
    }

    /**
     * THE CACHE IS THE GATE. A page already on record costs no request, ever again.
     *
     * This replaces the older assertion that only listings passing a geographic gate were hydrated.
     * That gate was cheap when the criteria named ten communes and Cityloger hydrated 3 of 51; it
     * is nearly vacuous now the region is all of Île-de-France, and it was never the right shape
     * anyway — hydrating on a per-pass predicate means a listing's verdict depends on which pass is
     * looking at it, and a listing the title filter rejected while hydrated comes back as a bare
     * card and notifies. Novelty plus persistence is the gate; geography only decides who goes
     * first when the budget is short.
     */
    public function testAHydratedListingCostsNoRequestOnALaterPass(): void
    {
        $store = $this->store();
        $client = new DetailHttpClient();

        $this->source($client, $this->definition(), null, store: $store)->fetch();
        self::assertCount(3, $client->detailUrls, 'first pass hydrates all three');

        $second = new DetailHttpClient();
        $rows = $this->source($second, $this->definition(), null, store: $store)->fetch();

        self::assertSame([], $second->detailUrls, 'a second pass spends NO detail requests');
        self::assertCount(3, $rows);

        // THE CONTENT, not merely a non-empty string. This asserted `!== ''`, which the CARD already
        // satisfies — so short-circuiting the cache branch entirely left the test green while every
        // listing came back unhydrated. That case reported `ok` for as long as the ledger harness
        // was vacuous, and was one of exactly two still undetected once it was fixed (2026-08-24).
        //
        // Reading the cache is not an optimisation: unhydrated listings are judged on their cards,
        // so `exclude_title_patterns` and the tenure label the DETAIL page carries both stop firing
        // — silently, and on the source producing most of the matches.
        self::assertStringContainsString(
            'Logement intermédiaire',
            $rows[1]->description,
            'the cached hydration must be merged in, not just some description',
        );
        self::assertSame('LI15P', $rows[1]->fields['tenureField'] ?? null, 'and its structured fields too');
    }

    /**
     * The budget bounds the cold start, which is the only expensive moment there is.
     *
     * In'li has ~174 listings and every one of them is novel on the first pass; at Q37's sixty
     * seconds per host that is a three-hour pass, which is a crawl under hard rule 5. The backlog
     * drains at N per pass instead.
     */
    public function testTheBudgetCapsDetailRequestsPerPassAndTheBacklogDrains(): void
    {
        $store = $this->store();
        $client = new DetailHttpClient();

        $this->source($client, $this->definition(budget: 1), null, store: $store)->fetch();
        self::assertCount(1, $client->detailUrls, 'one slot, one request');

        $second = new DetailHttpClient();
        $this->source($second, $this->definition(budget: 1), null, store: $store)->fetch();

        self::assertCount(1, $second->detailUrls, 'the next pass takes the next one');
        self::assertNotSame($client->detailUrls, $second->detailUrls, 'and not the same one again');
    }

    /**
     * Priority decides WHO gets a short budget, and the rank that matters most is "not yet seen".
     *
     * Ordered any other way, backlog eats the budget while a genuinely new listing is notified
     * unhydrated — and by the time its slot comes round it is already `notified_at`, so hydrating
     * it then buys nothing at all. This is the pass-2 bypass in a new costume, and the ordering is
     * what closes it.
     */
    public function testTheHighestPriorityCandidateGetsTheOnlySlot(): void
    {
        $client = new DetailHttpClient();
        $source = $this->source(
            $client,
            $this->definition(budget: 1),
            static fn (RawListing $l): int => $l->externalId === '3' ? 0 : 9,
        );

        $source->fetch();

        self::assertSame(['https://example.test/detail-3'], $client->detailUrls);
    }

    public function testTheDetailPageSuppliesTheTenureThatTheCardNeverCarries(): void
    {
        $source = $this->source(new DetailHttpClient(), $this->definition(), static fn (RawListing $l): bool => $l->commune === 'HOUILLES');

        $hydrated = null;
        foreach ($source->fetch() as $row) {
            if ($row->commune === 'HOUILLES') {
                $hydrated = $row;
            }
        }

        self::assertNotNull($hydrated);
        self::assertSame('LI15P', $hydrated->fields['tenureField'] ?? null);
        self::assertStringContainsString(
            'Logement intermédiaire',
            $hydrated->description,
            'the detail description is what carries the tier-2 label',
        );
        self::assertStringNotContainsString(
            'Commission d\'attribution',
            $hydrated->description,
            'the page furniture must NOT come with it — it appears on social and intermediate '
                . 'listings alike, and conflicts a correct verdict into UNKNOWN',
        );
    }

    /**
     * A DETAIL PAGE SUPPLIES A POSTCODE THE CARD NEVER HAD — end to end, through the real merge.
     *
     * Track 5b, F-B: In'li's postcode came ENTIRELY from the URL slug, and the source publishes a
     * second URL shape that carries none. In region mode `postcode_prefixes` is the whole location
     * filter, so 212 live rows could not match at all — 212 rejections and 0 matches, though 44 of
     * them already satisfied rent, surface and rooms.
     *
     * The unit assertions on the committed map prove the selector reads the frozen page. This
     * proves the value actually REACHES the listing, which is a different claim and the one that
     * matters: `RawListing::mergedWith()` is `$theirs ?? $mine`, so the detail fills a null card
     * field — and a rule that silently preferred the card would have left the fix inert while every
     * unit test stayed green.
     */
    public function testADetailPageSuppliesAPostcodeTheCardNeverCarried(): void
    {
        $client = new DetailHttpClient(
            detailBody: '<html><head><title>Appartement 63m² à louer à LES ULIS (91940) | In\'li</title></head>'
                . '<body><div class="description">Un logement</div></body></html>',
        );

        // BUDGET 1, so exactly one listing is hydrated and the others genuinely are not. Without
        // that the counterweight below is vacuous: the cache is the gate, so every row would be
        // hydrated from the same fake detail body and "an unhydrated row" would not exist.
        $definition = $this->definitionWithDetailMap(new FieldMap(
            description: ['.description'],
            postcode: ['title => \((\d{5})\)'],
        ));
        $budgeted = new SourceDefinition(
            name: $definition->name,
            enabled: $definition->enabled,
            family: $definition->family,
            type: $definition->type,
            mixedTenure: $definition->mixedTenure,
            url: $definition->url,
            baseUrl: $definition->baseUrl,
            itemSelector: $definition->itemSelector,
            map: $definition->map,
            rateLimitMs: 0,
            detailBudgetPerPass: 1,
            detailMap: $definition->detailMap,
        );

        $source = $this->source($client, $budgeted, static fn (RawListing $l): int => $l->externalId === '3' ? 0 : 9);

        $hydrated = null;
        $untouched = null;
        foreach ($source->fetch() as $row) {
            if ($row->externalId === '3') {
                $hydrated = $row;
            } elseif ($untouched === null) {
                $untouched = $row;
            }
        }

        self::assertSame(['https://example.test/detail-3'], $client->detailUrls, 'exactly one hydration');

        self::assertNotNull($hydrated);
        self::assertSame('91940', $hydrated->postcode, 'the detail must fill a postcode the card lacks');

        // The counterweight: this must not be satisfied by stamping every row with it.
        self::assertNotNull($untouched);
        self::assertNotSame('91940', $untouched->postcode, 'an unhydrated row invents nothing');
    }

    /**
     * THE CARD MAP AND THE DETAIL MAP COUNT SEPARATELY — through the real wiring, not a mapper
     * built by hand.
     *
     * Found on the deployed image's FIRST live pass, hours after the miss signal shipped. In'li
     * maps `cp` on both maps (the card from its URL slug, the detail page from its `<title>`), and
     * pooled under one key `doctor` reported `cp 171/342`. `PatternMissLog::total()` speaks only at
     * 100 %, so a card pattern missing on ALL 171 cards was averaged with 171 detail successes into
     * a silent 50 %: one whole map dead, reported as half-working, WARN unreachable. That is the
     * same dilution `RunStore`'s seven-day flaky window had, one layer down and shipped the same
     * evening.
     *
     * This test goes through `HtmlSource` -> `DetailHydrator` deliberately: the separation lives in
     * the hydrator's construction of its mapper, so a test that builds the mapper itself would keep
     * passing while the wiring was removed.
     */
    public function testTheCardMapAndTheDetailMapDoNotPoolTheirMisses(): void
    {
        $client = new DetailHttpClient(
            detailBody: '<html><head><title>Appartement 63m² à louer à LES ULIS (91940) | In\'li</title></head>'
                . '<body><div class="description">Un logement</div></body></html>',
        );

        $base = $this->definitionWithDetailMap(new FieldMap(
            description: ['.description'],
            postcode: ['title => \((\d{5})\)'],
        ));

        // The CARD map points its postcode at a selector the card does not carry — In'li's URL-slug
        // pattern exactly, which is why `683a31b` had to read the postcode off the detail page. The
        // detail map supplies it on every listing.
        $definition = new SourceDefinition(
            name: $base->name,
            enabled: $base->enabled,
            family: $base->family,
            type: $base->type,
            mixedTenure: $base->mixedTenure,
            url: $base->url,
            baseUrl: $base->baseUrl,
            itemSelector: $base->itemSelector,
            map: new FieldMap(
                ref: ['@href => -(\d+)$'],
                url: ['@href'],
                commune: ['.commune'],
                postcode: ['.un-selecteur-que-la-carte-ne-porte-pas'],
                rent: ['.price'],
                chargesIncluded: true,
            ),
            rateLimitMs: 0,
            detailBudgetPerPass: $base->detailBudgetPerPass,
            detailMap: $base->detailMap,
        );

        $source = $this->source($client, $definition, null);
        iterator_to_array($source->fetch());

        $counts = $source->patternMisses()->counts();

        self::assertSame(
            $counts['cp']['calls'],
            $counts['cp']['misses'],
            'the card side missed on every card — that is the fault that must stay visible',
        );
        self::assertSame(0, $counts['detail.cp']['misses'], 'the detail side supplied it every time');

        // THE POINT: the WARN is reachable on the dead half. Pooled into one key this is
        // `misses/calls = 3/6`, which `total()` never reports, and the dead map stays invisible.
        self::assertContains('cp', $source->patternMisses()->total());
        self::assertNotContains('detail.cp', $source->patternMisses()->total());
    }

    /** Hard rule 9: a detail page that omits a field states nothing about it. */
    public function testADetailPageThatOmitsAFieldNeverErasesWhatTheCardKnew(): void
    {
        $client = new DetailHttpClient(detailBody: '<html><body><div class="description">Un texte sans rien d\'autre</div></body></html>');
        $source = $this->source($client, $this->definition(), static fn (RawListing $l): bool => $l->commune === 'HOUILLES');

        $rows = $source->fetch();

        $hydrated = null;
        $untouched = null;
        foreach ($rows as $row) {
            if ($row->commune === 'HOUILLES') {
                $hydrated = $row;
            }
            if ($row->commune === 'NANTES') {
                $untouched = $row;
            }
        }

        // The control. Without it this test would also pass if the CARD had never parsed a rent at
        // all — proving the merge harmless by proving there was nothing there to harm.
        self::assertSame(700, $untouched?->rentCc, 'a listing nobody hydrated still has its card rent');

        self::assertNotNull($hydrated);
        self::assertSame(880, $hydrated->rentCc, 'the card knew the rent; the detail page said nothing about it');
        self::assertSame('HOUILLES', $hydrated->commune);
        self::assertNull($hydrated->fields['tenureField'] ?? null, 'and an absent tenure stays absent, not empty-string');
    }

    /** Hard rule 3: a failed detail request must not quietly yield an unhydrated listing. */
    /**
     * A DEAD PAGE IS RECORDED AND COUNTED. IT DOES NOT VOID THE PASS.
     *
     * This replaces a test that asserted the fetch THREW. Throwing was right about silence and
     * wrong about blast radius: it voided the whole pass, so a single permanently-404ing detail
     * page meant the source returned nothing, marked nothing seen, and never notified a genuinely
     * new listing again — while health reported SOURCE_BROKEN on a diagnosis that was untrue.
     *
     * Recording is not the silent alternative. All four halves of the contract are asserted here,
     * because dropping any one of them turns this back into the swallow it must never be.
     */
    public function testAFailedDetailFetchIsRecordedCountedAndDoesNotVoidThePass(): void
    {
        $store = $this->store();
        $client = new DetailHttpClient(failDetail: true);

        $rows = $this->source($client, $this->definition(), null, store: $store, nowIso: '2026-08-23T10:00:00+02:00')->fetch();

        // 1. the pass survives, with every card still in it
        self::assertCount(3, $rows);

        // 2. the failed listing arrives UNHYDRATED rather than not at all — it still carries the
        //    card's own text, which is exactly the status quo for a listing whose page cannot be
        //    read, and nothing the detail page would have supplied
        self::assertStringNotContainsString('Logement intermédiaire', $rows[1]->description);
        self::assertStringContainsString('HOUILLES', $rows[1]->description, 'the card survives');

        // 3. the failure is on record, with its attempt count and its message
        $detail = $store->detail('cityloger', '2');
        self::assertNotNull($detail, 'a failure that leaves no row is exactly the swallow');
        self::assertNull($detail->fields, 'tried-and-failed is not read-and-bare');
        self::assertSame(1, $detail->attempts);
        self::assertNotNull($detail->lastError);

        // 4. and health can see it, which is what makes it loud
        self::assertGreaterThanOrEqual(1, $store->detailFailureCount('cityloger'));
    }

    /**
     * A failed page is retried, but not on every pass — and never past the cap.
     *
     * Re-fetching a 404 every fifteen minutes for ever is a slow crawl aimed at a page that is
     * gone. Never retrying at all makes one bad afternoon permanent.
     */
    public function testAFailedDetailPageBacksOffAndThenStopsBeingRetried(): void
    {
        $store = $this->store();
        $def = $this->definition();
        // The fake client fails EVERY detail request, so all three listings fail together and the
        // counts below are per-pass totals rather than per-listing ones.
        $attempt = function (string $at) use ($store, $def): int {
            $client = new DetailHttpClient(failDetail: true);
            $this->source($client, $def, null, store: $store, nowIso: $at)->fetch();

            return \count($client->detailUrls);
        };

        self::assertSame(3, $attempt('2026-08-23T10:00:00+02:00'), 'first attempt');
        self::assertSame(0, $attempt('2026-08-23T10:20:00+02:00'), 'no retry inside the backoff window');
        self::assertSame(3, $attempt('2026-08-23T20:00:00+02:00'), 'retried once the backoff elapsed');
        self::assertSame(3, $attempt('2026-08-24T20:00:00+02:00'), 'and once more');
        self::assertSame(0, $attempt('2026-08-30T20:00:00+02:00'), 'three attempts is the cap');

        self::assertSame(DetailHydrator::DETAIL_ATTEMPT_CAP, $store->detail('cityloger', '2')?->attempts);
        self::assertSame(3, $store->detailFailureCount('cityloger', minAttempts: DetailHydrator::DETAIL_ATTEMPT_CAP));
    }

    /** Hard rule 5: the detail page is a different path, so it gets its own robots verdict. */
    public function testRobotsIsCheckedForEveryDetailUrlAndNotOnlyForTheSearchPage(): void
    {
        $robots = Robots::parse("User-agent: *\nDisallow: /detail-\n");
        $source = $this->source(
            new DetailHttpClient(),
            $this->definition(),
            static fn (RawListing $l): bool => $l->commune === 'HOUILLES',
            $robots,
        );

        $this->expectException(SourceError::class);
        $this->expectExceptionMessageMatches('/robots\.txt disallows/');

        $source->fetch();
    }

    /**
     * A detail map with no gate REFUSES, rather than defaulting either way.
     *
     * Both defaults are wrong and one is silent: hydrating everything turns a config-only source
     * into a per-listing crawl of somebody else's site, and hydrating nothing leaves a mixed-tenure
     * source resolving UNKNOWN forever while looking perfectly healthy. Refusing is the only option
     * that cannot be mistaken for working.
     */
    /**
     * A missing PRIORITY is not a missing gate, and must not refuse.
     *
     * The old invariant — a `detail_map` with no gate refuses rather than guessing between
     * hydrating everything and hydrating nothing — retired when novelty became the gate: there is
     * no longer a gate to be absent. The thing it protected is still real, so it has a successor
     * one layer up, at config load: `detail_budget_per_pass: 0` is REFUSED
     * ({@see \Scout\Tests\Rent\Config\ConfigTest::testADetailMapWithAZeroBudgetIsRefused}),
     * because a detail map that can never run is a disabled feature wearing a configured one's
     * clothes. An omitted priority merely means the budget is spent in source order.
     */
    public function testAMissingPriorityFallsBackToSourceOrderRatherThanRefusing(): void
    {
        $client = new DetailHttpClient();

        $rows = $this->source($client, $this->definition(budget: 2), null)->fetch();

        self::assertCount(3, $rows);
        self::assertSame(
            ['https://example.test/detail-1', 'https://example.test/detail-2'],
            $client->detailUrls,
            'source order, first two, because the budget was two',
        );
    }

    /**
     * Hard rule 5: a detail walk is N extra requests, so it is paced like every other one.
     *
     * Asserted on the clock rather than on a seam because `usleep` is what the walk uses, and a test
     * that mocked a sleeper would prove the mock was called, not that the source waits. The margin
     * is generous in the one direction that matters: it can only fail if the wait vanished.
     */
    public function testDetailFetchesArePacedLikeEveryOtherRequest(): void
    {
        $definition = new SourceDefinition(
            name: 'cityloger',
            enabled: true,
            family: 'institutional',
            type: 'html',
            mixedTenure: true,
            url: 'https://example.test/results',
            baseUrl: 'https://example.test',
            itemSelector: 'a.card',
            map: new FieldMap(ref: ['@href => -(\d+)$'], url: ['@href'], commune: ['.commune'], postcode: ['.cp']),
            rateLimitMs: 60,
            detailMap: new FieldMap(description: ['.description']),
        );

        // ASSERT THE SLEEP WAS REQUESTED, NOT THAT TIME PASSED. This measured elapsed wall-clock
        // against a 50 ms floor, and a slow machine clears that floor with the `usleep` deleted —
        // so the guarantee was only detectable on a fast runner. It flipped on 2026-09-06: the
        // ledger case detected at `3422225` and reported `undetected` at `59b413e`, on the one
        // shard that took two hours where its siblings took seventy minutes. A lower-bound timing
        // assertion cannot tell "it waited" apart from "everything was slow".
        $slept = [];
        $source = $this->source(
            new DetailHttpClient(),
            $definition,
            static fn (RawListing $l): bool => $l->commune === 'HOUILLES',
            sleeper: static function (int $us) use (&$slept): void { $slept[] = $us; },
        );

        $source->fetch();

        // ONE PER DETAIL REQUEST, and the count is half the guarantee. The elapsed-time version
        // was satisfied by a SINGLE pause however many pages were fetched, so a walk that paced its
        // first request and then burst through the rest would have passed it.
        self::assertSame(
            [60_000, 60_000, 60_000],
            $slept,
            'every hydration must wait rate_limit_ms before its request — an unpaced detail walk is '
                . 'the burst that gets an IP banned, and it presents as every source going quiet',
        );
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Adding a field to a detail map REFETCHES the pages already on record.
     *
     * The hole Phase 2 left. Rows are keyed `(source, external_id)` and a page on record costs no
     * request ever again — so widening the map would have left every hydrated row serving the OLD
     * fields for ever: no refetch, no error, no signal, and a config claiming to collect a field it
     * would never carry. Silent-forever staleness, which is this project's characteristic failure.
     *
     * Asserted behaviourally rather than by checking that a fingerprint argument is passed, because
     * the argument being present is not the guarantee — the second request is.
     */
    public function testWideningTheDetailMapRefetchesWhatIsAlreadyCached(): void
    {
        $store = $this->store();
        $client = new DetailHttpClient();

        $this->source($client, $this->definition(), null, store: $store)->fetch();
        $firstPass = count($client->detailUrls);
        self::assertGreaterThan(0, $firstPass, 'the first pass hydrates');

        // Same map, same pages: the cache answers and NOTHING is requested again.
        $this->source($client, $this->definition(), null, store: $store)->fetch();
        self::assertCount($firstPass, $client->detailUrls, 'an unchanged map costs no further request');

        // One field added. Every cached row is now stale and must be read again.
        $widened = $this->definitionWithDetailMap(new FieldMap(
            description: ['.description'],
            floor: ['.description => prose:floor'],
            tenureField: ['table.financement => Financement\s*([A-Z0-9]+)'],
        ));

        $this->source($client, $widened, null, store: $store)->fetch();

        self::assertGreaterThan(
            $firstPass,
            count($client->detailUrls),
            'a widened map refetches rather than serving rows captured under the old one',
        );
    }

    private function definitionWithDetailMap(FieldMap $detailMap): SourceDefinition
    {
        $base = $this->definition();

        return new SourceDefinition(
            name: $base->name,
            enabled: $base->enabled,
            family: $base->family,
            type: $base->type,
            mixedTenure: $base->mixedTenure,
            url: $base->url,
            baseUrl: $base->baseUrl,
            itemSelector: $base->itemSelector,
            map: $base->map,
            rateLimitMs: 0,
            detailBudgetPerPass: $base->detailBudgetPerPass,
            detailMap: $detailMap,
        );
    }

    private function definition(int $budget = 20): SourceDefinition
    {
        return new SourceDefinition(
            name: 'cityloger',
            enabled: true,
            family: 'institutional',
            type: 'html',
            mixedTenure: true,
            url: 'https://example.test/results',
            baseUrl: 'https://example.test',
            itemSelector: 'a.card',
            map: new FieldMap(
                ref: ['@href => -(\d+)$'],
                url: ['@href'],
                commune: ['.commune'],
                postcode: ['.cp'],
                rent: ['.price'],
                chargesIncluded: true,
            ),
            // Zero, so the suite does not pay real seconds for a fake host. The pacing itself is
            // pinned by testDetailFetchesArePacedLikeEveryOtherRequest below, which sets its own.
            rateLimitMs: 0,
            detailBudgetPerPass: $budget,
            detailMap: new FieldMap(
                description: ['.description'],
                tenureField: ['table.financement => Financement\s*([A-Z0-9]+)'],
            ),
        );
    }

    private function source(
        HttpClient $client,
        SourceDefinition $definition,
        ?\Closure $priority,
        ?Robots $robots = null,
        ?Store $store = null,
        ?string $nowIso = null,
        ?\Closure $sleeper = null,
    ): HtmlSource {
        return new HtmlSource(
            $definition,
            $store ?? $this->store(),
            $client,
            $robots ?? Robots::parse(''),
            $priority,
            $nowIso,
            sleeper: $sleeper,
        );
    }

    /** One store per test file path, reused when a test needs two passes to share a cache. */
    private function store(): Store
    {
        $this->dbPath ??= sys_get_temp_dir() . '/rentwatch-detail-' . bin2hex(random_bytes(8)) . '.sqlite3';

        return Store::open($this->dbPath);
    }
}

/**
 * A search partial of three cards, one of them in a watched commune, plus detail pages behind them.
 */
final class DetailHttpClient implements HttpClient
{
    /** @var list<string> */
    public array $detailUrls = [];

    public function __construct(
        private readonly ?string $detailBody = null,
        private readonly bool $failDetail = false,
    ) {}

    public function send(HttpRequest $request): HttpResponse
    {
        if (str_contains($request->url, '/detail-')) {
            $this->detailUrls[] = $request->url;

            if ($this->failDetail) {
                throw new HttpError('HTTP 503 from ' . $request->url);
            }

            return new HttpResponse(200, $this->detailBody ?? $this->detail());
        }

        return new HttpResponse(200, $this->search());
    }

    private function search(): string
    {
        $card = static fn (int $n, string $commune, string $cp, int $rent): string => <<<HTML
            <a class="card" href="https://example.test/detail-{$n}">
                <span class="commune">{$commune}</span><span class="cp">{$cp}</span>
                <span class="price">{$rent} € cc</span>
            </a>
            HTML;

        return '<html><body>'
            . $card(1, 'NANTES', '44000', 700)
            . $card(2, 'HOUILLES', '78800', 880)
            . $card(3, 'LYON', '69003', 950)
            . '</body></html>';
    }

    /**
     * Shaped after the real Antony payload: the listing's own prose carries the tier-2 label, and
     * the page furniture around it carries social vocabulary that belongs to neither listing.
     */
    private function detail(): string
    {
        return <<<'HTML'
            <html><body>
                <div class="description">Bel appartement de type F4 de 80 m2. Logement intermédiaire
                    géré par un bailleur social - attribution sous conditions de ressources.</div>
                <table class="financement"><tr><td>Financement</td><td>LI15P</td></tr></table>
                <div class="page-furniture">
                    <p>Numéro de demande de logement social</p>
                    <p>Pour proposer la location, la Commission d'attribution vérifie les revenus.</p>
                </div>
            </body></html>
            HTML;
    }
}
