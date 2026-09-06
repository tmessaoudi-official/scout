<?php

declare(strict_types=1);

namespace Scout\Tests\Rent\Adapters;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Scout\Rent\Adapters\Html\Selector;
use Scout\Adapters\Http\HttpError;
use Scout\Adapters\Http\HttpResponse;
use Scout\Adapters\Http\Robots;
use Scout\Rent\Adapters\HtmlSource;
use Scout\Adapters\SourceError;
use Scout\Rent\Config\FieldMap;
use Scout\Rent\Config\SourceDefinition;
use Scout\Rent\Core\Tenure;
use Scout\Rent\Store\Store;

/**
 * The `type: html` adapter, and the selector micro-syntax its field maps are written in.
 *
 * Everything here runs against a FROZEN payload — `tests/fixtures/rent/inli/search.html`, captured from
 * the live site on 2026-08-19 with `robots.txt` verified first and the page's embedded third-party
 * API key scrubbed. No test in this file reaches the network; one that did would be a monitoring
 * check wearing a test's clothes, green or red according to someone else's deploy schedule.
 *
 * The suite is arranged around the two things that can go wrong, which are not equally dangerous:
 *
 * 1. **Extraction is wrong** — a selector resolves the wrong element, a number is misparsed, a
 *    relative href is stored unresolved. Loud in the sense that the data looks odd, but nothing
 *    fails, so it is only ever caught by asserting real values against the frozen page.
 * 2. **Extraction returns NOTHING** — the far worse one. A redesign renames a class, the selector
 *    matches zero elements, and the adapter reports a perfectly healthy run with no listings. Zero
 *    listings is exactly what a quiet rental market looks like, so hard rules 2 and 3 say it must
 *    THROW. Several tests here exist only to pin that.
 */
#[CoversClass(HtmlSource::class)]
#[CoversClass(Selector::class)]
final class HtmlSourceTest extends TestCase
{
    private const string FIXTURE = __DIR__ . '/../../../fixtures/rent/inli/search.html';

    private ?string $dbPath = null;

    protected function tearDown(): void
    {
        if ($this->dbPath !== null && file_exists($this->dbPath)) {
            @unlink($this->dbPath);
        }
    }

    // ---------------------------------------------------------------- the selector micro-syntax

    #[DataProvider('selectorForms')]
    public function testSelectorResolution(string $entry, ?string $expected, string $why): void
    {
        $html = <<<'HTML'
            <div class="card" data-ref="A1" data-financement="LLI">
              <a href="/location-appartement-massy-91300/PRV-001">voir</a>
              <span class="price">1 005 € </span>
              <span class="details">3 pièces · 55.32 m²</span>
              <span class="blank">   </span>
              <a class="contact" href="mailto:agence@example.fr">écrire</a>
            </div>
            HTML;

        $document = \Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR);
        $card = $document->querySelector('.card');
        self::assertNotNull($card);

        self::assertSame($expected, Selector::parse($entry)->resolve($card), $why);
    }

    /** @return iterable<string, array{string, ?string, string}> */
    public static function selectorForms(): iterable
    {
        yield 'plain text' => ['.price', '1 005 €', 'whitespace normalised, trailing space gone'];
        yield 'an attribute of a descendant' => ['a@href', '/location-appartement-massy-91300/PRV-001', ''];
        yield 'an attribute of the item itself' => ['@data-ref', 'A1', 'an empty selector half means the card element'];
        yield 'a capture group' => ['.details => ([\d.,]+)\s*m²', '55.32', 'the surface, isolated from the room count sharing its node'];
        yield 'attribute then capture' => ['a@href => /([^/]+)$', 'PRV-001', 'the stable id, taken off the end of the href'];
        yield 'a capture with no group falls back to the whole match' => ['.details => \d+\s*pièces', '3 pièces', ''];

        // The reason the `@` split is not a plain `explode`. A CSS selector may legitimately carry
        // an `@` inside an attribute VALUE, and splitting there would leave a broken selector and a
        // nonsense attribute name — silently, because both halves still look like something.
        yield 'an @ inside an attribute value is not an attribute split' => [
            'a[href="mailto:agence@example.fr"]', 'écrire',
            'the tail `example.fr"]` is not a valid attribute name, so the selector stays whole',
        ];

        // Hard rule 9 lives in this block: every one of these is `null` for UNKNOWN, never '' and
        // never a zero that a minimum would then reject the listing against.
        yield 'a selector that matches nothing' => ['.nope', null, 'unknown, not empty'];
        yield 'an attribute that is not there' => ['a@data-nope', null, ''];
        yield 'an element whose text is only whitespace' => ['.blank', null, 'blank is not a value'];
        yield 'a capture that does not match' => ['.price => ([\d.]+)\s*m²', null, 'no surface in a price — null, NOT the unparsed price text'];
        yield 'a capture that matches emptily' => ['.price => (x?)', null, 'an empty capture is not a value'];
        yield 'an invalid CSS selector' => ['div[', null, 'a broken field map yields unknown per field; the missing `ref` is what fails loudly'];
        yield 'an uncompilable pattern' => ['.price => ([0-9', null, ''];
    }

    public function testNormaliseCollapsesTheUnicodeSpacesFrenchTypographyUses(): void
    {
        // U+00A0 and U+202F sit between a figure and its unit on the pages this reads, and `\s`
        // does not match either without the `u` flag. Left in, they make a string that LOOKS equal
        // to its plain-space twin and is not — so a listing's text would hash differently across a
        // redesign that changed nothing a reader could see.
        self::assertSame('1 005 € cc', Selector::normalise("1\u{00A0}005\u{202F}€\n\n  cc"));
    }

    // ---------------------------------------------------------------- the frozen In'li page

    public function testTheFrozenPageYieldsEveryListingWithEveryMappedField(): void
    {
        $rows = $this->inli()->extract((string) file_get_contents(self::FIXTURE), '.featured-items > .featured-item');

        self::assertCount(19, $rows, 'the page says "19 logements" and the extractor must agree');

        foreach ($rows as $row) {
            self::assertNotSame('', $row->externalId, 'every card must yield a stable id');
            self::assertNotNull($row->rentCc, 'every card quotes a rent');
            self::assertNotNull($row->surfaceM2);
            self::assertNotNull($row->rooms);
            self::assertNotNull($row->commune);
            self::assertStringStartsWith('https://www.inli.fr/', (string) $row->url, 'base_url resolves the relative href');
        }
    }

    public function testTheFirstCardsValuesAreExactlyWhatThePageShows(): void
    {
        // Pinned against the real markup rather than a shape assertion. A field map that resolves
        // the WRONG element still produces a full listing — this is what says it resolved the right
        // one, and it is the assertion that goes red when a redesign moves a class.
        $rows = $this->inli()->extract((string) file_get_contents(self::FIXTURE), '.featured-items > .featured-item');
        $first = $rows[0];

        self::assertSame('441-20030-3014', $first->externalId);
        self::assertSame('LONGJUMEAU', $first->commune);
        self::assertSame('91160', $first->postcode);
        self::assertSame(1005, $first->rentCc, 'charges comprises — the card says `cc` beside the figure');
        self::assertSame(55.32, $first->surfaceM2, 'the surface, not the 3 that shares its text node');
        self::assertSame(3, $first->rooms);
        self::assertSame('https://www.inli.fr/location-appartement-longjumeau-91160/441-20030-3014', $first->url);
    }

    public function testTheWholeCardTextReachesTheClassifierEvenWhereNothingIsMapped(): void
    {
        // The classifier reads text as signal tier 2 and field NAMES as tier 1. In HTML the tier-1
        // evidence lives in attributes, and a `data-financement` nobody mapped must still be seen —
        // otherwise §1's excluded labels can sit in the markup, unread, while the listing is
        // notified. Bias runs one way here: seeing more of the card can only make an exclusion
        // easier to catch.
        $rows = $this->inli()->extract((string) file_get_contents(self::FIXTURE), '.featured-items > .featured-item');
        $fields = $rows[0]->fields;

        self::assertArrayHasKey('_text', $fields);
        self::assertStringContainsString('55.32 m²', (string) $fields['_text']);
        self::assertStringContainsString('1 005 €', (string) $rows[0]->description, 'description falls back to the card text');
    }

    public function testAnAttributeCarryingATenureLabelReachesTheClassifierSurface(): void
    {
        $html = '<ul><li class="c" data-financement="PLS"><a href="/x/1">a</a>'
            . '<span class="p">900 €</span></li></ul>';

        $rows = $this->inli(['itemSelector' => 'li.c'])->extract($html, 'li.c');

        self::assertArrayHasKey('data.data-financement', $rows[0]->fields);
        self::assertSame('PLS', $rows[0]->fields['data.data-financement']);
    }

    // ---------------------------------------------------------------- the failure paths

    public function testASelectorThatMatchesNothingThrowsRatherThanReturningAnEmptyList(): void
    {
        // Hard rules 2 and 3, and the single most valuable assertion in this file. A redesign that
        // renames `featured-item` leaves a 200 response, valid HTML and zero listings — and zero
        // listings is byte-identical to a quiet market. It has to be loud.
        $this->expectException(SourceError::class);
        $this->expectExceptionMessageMatches('~matched no element~');

        $this->inli()->extract((string) file_get_contents(self::FIXTURE), '.renamed-in-a-redesign');
    }

    public function testTheZeroMatchMessageNamesTheAntiBotCaseToo(): void
    {
        // An interstitial or a JSON error body parses as HTML without error — the HTML5 parser
        // takes almost any byte sequence. So the failure surfaces here, as "matched nothing", and
        // the message has to say so or it sends someone to audit selectors that were fine.
        //
        // (The wording here is deliberate. An earlier draft used a synonym for "error" that has
        // the excluded-tenure abbreviation buried inside it, close to a permissive-sounding verb,
        // and the §1 tripwire fired on the pair. It was pure prose — no tenure logic anywhere near
        // it — and CLAUDE.md's rule for that case is to reword the sentence, never the pattern.
        // A tripwire that gets relaxed each time it is inconvenient stops being a tripwire.)
        try {
            $this->inli()->extract('{"error":"forbidden"}', '.featured-item');
            self::fail('expected a SourceError');
        } catch (SourceError $e) {
            self::assertStringContainsString('interstitial', $e->getMessage());
        }
    }

    public function testAnInvalidItemSelectorIsDiagnosedAsSuchRatherThanAsAnEmptyPage(): void
    {
        $this->expectException(SourceError::class);
        $this->expectExceptionMessageMatches('~not a valid CSS selector~');

        $this->inli()->extract('<div class="featured-item"></div>', 'div[');
    }

    public function testAPlaceholderUrlIsRefusedBeforeAnyRequestIsMade(): void
    {
        $client = new FakeHttpClient(new HttpResponse(200, '<html></html>'));

        try {
            $this->inli(['url' => 'https://www.inli.fr/REMPLACER'], $client)->fetch();
            self::fail('expected a SourceError');
        } catch (SourceError $e) {
            self::assertStringContainsString('hard rule 1', $e->getMessage());
        }

        self::assertSame(0, $client->calls, 'and nothing was fetched');
    }

    public function testAnHtmlSourceWithNoItemSelectorIsRefusedBeforeAnyRequestIsMade(): void
    {
        $client = new FakeHttpClient(new HttpResponse(200, '<html></html>'));

        try {
            $this->inli(['itemSelector' => null], $client)->fetch();
            self::fail('expected a SourceError');
        } catch (SourceError $e) {
            self::assertStringContainsString('item_selector', $e->getMessage());
        }

        self::assertSame(0, $client->calls);
    }

    public function testRobotsDisallowRefusesAndNeverWorksAround(): void
    {
        $client = new FakeHttpClient(new HttpResponse(200, '<html></html>'));
        $robots = Robots::parse("User-agent: *\nDisallow: /locations/");

        try {
            $this->inli(['url' => 'https://www.inli.fr/locations/offres/x'], $client, $robots)->fetch();
            self::fail('expected a SourceError');
        } catch (SourceError $e) {
            self::assertStringContainsString('email-alert route', $e->getMessage(), 'hard rule 5 names the alternative');
        }

        self::assertSame(0, $client->calls, 'refused before the request, not after');
    }

    public function testA403PointsAtTheEmailAlertRouteRatherThanAtABetterDisguise(): void
    {
        $this->expectException(SourceError::class);
        $this->expectExceptionMessageMatches('~email-alert route~');

        $this->inli([], new FakeHttpClient(new HttpResponse(403, 'nope')))->fetch();
    }

    public function testATransportFailureIsASourceErrorNotAnEmptyResult(): void
    {
        $this->expectException(SourceError::class);

        $this->inli([], new FakeHttpClient(null, new HttpError('connection reset')))->fetch();
    }

    public function testAFetchThatSucceedsMapsTheFrozenPageEndToEnd(): void
    {
        $client = new FakeHttpClient(new HttpResponse(200, (string) file_get_contents(self::FIXTURE)));

        $rows = $this->inli([], $client)->fetch();

        self::assertCount(19, $rows);
        self::assertSame(1, $client->calls);
    }

    // ---------------------------------------------------------------- pagination

    /**
     * Pagination exists because of a measurement, not a hunch: In'li answered `92 logements` to an
     * ordinary set of filters and put 24 on page one [verified 2026-08-19 against the live site].
     * A page-one-only adapter would have dropped 68 listings every pass and reported a healthy run.
     */
    public function testEveryPageIsWalkedAndTheListingsAreConcatenated(): void
    {
        $client = new PagedHttpClient([
            1 => self::page(['a', 'b'], 5),
            2 => self::page(['c', 'd'], 5),
            3 => self::page(['e'], 5),
        ]);

        $rows = $this->paged($client)->fetch();

        self::assertSame(['a', 'b', 'c', 'd', 'e'], array_map(static fn ($r) => $r->externalId, $rows));

        // No `4`: the walk stops once the site's own declared count is satisfied. It used to probe
        // one page past the end, which assumed a page past the end comes back EMPTY — CDC Habitat's
        // answers 301 instead [measured 2026-08-20], and a refused non-2xx then failed the whole
        // run. The declared total was already the assertion this mechanism makes; using it to stop
        // as well costs one fewer request per pass against somebody else's server.
        self::assertSame([null, '2', '3'], $client->pages, 'page one carries no page param');
    }

    /**
     * Hard rule 5's polite-pacing half, on the one sleep in this class that had no test at all.
     * `PacedSource` wraps ONE `fetch()`, so every page after the first is inside it and unpaced by
     * anything else — without this pause a four-page walk is four requests back to back, which is
     * the burst that gets an IP banned, and a banned IP presents as every source going quiet at
     * once: exactly what a slow rental market looks like.
     *
     * THE COUNT IS THE ASSERTION, not the fact that it slept. Three pages are fetched and two
     * sleeps are expected, which is what says page one is NOT paced here — asserting merely that a
     * pause happened would pass just as well if this class paced the first request too, which would
     * double every source's own gap and is the opposite mistake. A wall-clock lower bound was the
     * shape the DETAIL pacing test used until 2026-09-06, and a slow machine satisfies one of those
     * with the `usleep` deleted [measured: the case reported `ok` on the shard that took two hours].
     */
    public function testThePageWalkPausesBetweenPagesAndNotBeforeTheFirst(): void
    {
        $slept = [];
        $client = new PagedHttpClient([
            1 => self::page(['a', 'b'], 5),
            2 => self::page(['c', 'd'], 5),
            3 => self::page(['e'], 5),
        ]);

        $this->paged($client, ['rateLimitMs' => 750], static function (int $us) use (&$slept): void {
            $slept[] = $us;
        })->fetch();

        self::assertSame([null, '2', '3'], $client->pages, 'premise: three pages are fetched');
        self::assertSame(
            [750_000, 750_000],
            $slept,
            'one pause per page AFTER the first, at rate_limit_ms — never before page one',
        );
    }

    /** A source that declares no rate limit must not pause at all; the guard is a real branch. */
    public function testAWalkWithNoRateLimitDoesNotPause(): void
    {
        $slept = [];
        $client = new PagedHttpClient([1 => self::page(['a', 'b'], 3), 2 => self::page(['c'], 3)]);

        $this->paged($client, [], static function (int $us) use (&$slept): void {
            $slept[] = $us;
        })->fetch();

        self::assertSame([], $slept, 'rate_limit_ms 0 means no pause, not a zero-length one');
    }

    public function testTheWalkStopsAtTheFirstPageWithNoListings(): void
    {
        $client = new PagedHttpClient([1 => self::page(['a'], 1)]);

        self::assertCount(1, $this->paged($client)->fetch());
        self::assertSame([null, '2'], $client->pages, 'one probe past the end, then stop');
    }

    public function testAWalkThatCollectsFewerThanThePageDeclaresIsAFailureNotAShortResultSet(): void
    {
        // The assertion the whole mechanism exists for. Walking until a page comes back empty is a
        // termination rule, not a proof — a `page=2` that quietly 404s or redirects to page one
        // ends the walk exactly like a real last page. Only the site's own declared total can tell
        // those apart, and without it 24-of-92 is indistinguishable from a thin market.
        $client = new PagedHttpClient([1 => self::page(['a', 'b'], 92)]);

        $this->expectException(SourceError::class);
        $this->expectExceptionMessageMatches('~collected 2 listings but the page declares 92~');

        $this->paged($client)->fetch();
    }

    public function testAPageParamTheSiteIgnoresIsRefusedRatherThanRequestedForever(): void
    {
        // Every page returns page one, so the walk never terminates naturally. Unbounded, this is
        // an infinite request loop against somebody else's server — the one bug in this adapter
        // that could actually get an IP banned, which hard rule 5 leaves polite pacing to prevent.
        $client = new PagedHttpClient([], self::page(['same'], null));

        $this->expectException(SourceError::class);
        $this->expectExceptionMessageMatches('~reached the 4-page bound~');

        $this->paged($client, ['maxPages' => 4])->fetch();
    }

    /**
     * A WALK THAT FINISHES ON ITS LAST PERMITTED PAGE IS NOT A FAILURE (round-6 finding, 2026-08-31).
     *
     * The bound check ran unconditionally after the loop, so it could not tell a walk that ENDED
     * correctly on page `maxPages` from one that ran out of road there. Both threw.
     *
     * CDC Habitat walked into exactly that in production and reported `broken / 0 annonces` for a
     * day: 315 declared listings at 16 a page is 19.7 pages, the bound is 20, so the walk completed
     * ON page 20, hit the declared total, broke — and was then thrown away. Worse, the message named
     * a cause that was measurably untrue ("the path template is probably ignored by the site, so
     * every page returns the first one"): the template worked, the pages differed, the selectors
     * matched 16 cards each. Verified live before this fix.
     */
    public function testAWalkThatReachesTheDeclaredTotalOnItsLastPermittedPageSucceeds(): void
    {
        // Four cards over two pages, a declared total of four, and a bound of exactly two.
        $client = new PagedHttpClient([
            1 => self::page(['a', 'b'], 4),
            2 => self::page(['c', 'd'], 4),
        ]);

        $listings = $this->paged($client, ['maxPages' => 2])->fetch();

        self::assertCount(4, $listings, 'the walk completed on its last permitted page and must be kept');
        self::assertSame([null, '2'], $client->pages, 'and it stopped there rather than asking for a third');
    }

    /**
     * A SHORT FINAL PAGE ENDS THE WALK, and it is proof of the end in a way the declared total is
     * not (round-6 finding, 2026-08-31, measured live).
     *
     * CDC Habitat serves 20 pages of 16 except the last, which serves 8, and DECLARES 315 while
     * actually serving 312. So `count >= total` never fired, the walk ran to the bound, and the
     * source was permanently `broken / 0 annonces` — with a message blaming the path template, which
     * was measurably fine. Raising the bound would not have helped: the shortfall is in the site's
     * own arithmetic, not in the walk.
     *
     * A shortfall UNDER ONE PAGE is therefore tolerated once a short page has proven the end. A lost
     * PAGE — which is what that assertion exists to catch — is a page-size shortfall or more, and
     * still throws. The next test pins that half.
     */
    public function testAShortFinalPageEndsTheWalkAndForgivesASmallDeclaredOvercount(): void
    {
        // Declares 6, serves 4 + 1: a partial last page and a total the site overstates.
        $client = new PagedHttpClient([
            1 => self::page(['a', 'b', 'c', 'd'], 6),
            2 => self::page(['e'], 6),
        ]);

        $listings = $this->paged($client, ['maxPages' => 9])->fetch();

        self::assertCount(5, $listings, 'the short page ended the walk and its cards were kept');
        self::assertSame([null, '2'], $client->pages, 'and no page 3 was requested');
    }

    /** A shortfall of a WHOLE PAGE is a lost page, and still fails — the guard is not switched off. */
    public function testAShortfallOfAWholePageStillFails(): void
    {
        // Declares 12, serves 4 + 1 = 5. The gap is 7, which is more than one 4-card page.
        $client = new PagedHttpClient([
            1 => self::page(['a', 'b', 'c', 'd'], 12),
            2 => self::page(['e'], 12),
        ]);

        $this->expectException(SourceError::class);
        $this->expectExceptionMessageMatches('~pagination lost results~');

        $this->paged($client, ['maxPages' => 9])->fetch();
    }

    /** The other half: running OUT of road on the bound is still a failure, and still says so. */
    public function testAWalkThatNeverEndsStillFailsOnTheBound(): void
    {
        // Every page yields cards and NO total is declared, so nothing can terminate the walk.
        $client = new PagedHttpClient([], self::page(['same'], null));

        $this->expectException(SourceError::class);
        $this->expectExceptionMessageMatches('~reached the 3-page bound~');

        $this->paged($client, ['maxPages' => 3])->fetch();
    }

    public function testASourceWithNoPageParamMakesExactlyOneRequest(): void
    {
        $client = new PagedHttpClient([1 => self::page(['a'], null)]);

        self::assertCount(1, $this->paged($client, ['pageParam' => null])->fetch());
        self::assertSame([null], $client->pages);
    }

    /** A minimal results page: `$refs` cards, and optionally a declared total. */
    private static function page(array $refs, ?int $total): string
    {
        $cards = '';
        foreach ($refs as $ref) {
            $cards .= '<div class="featured-item"><a href="/l/' . $ref . '"><img alt="MASSY">'
                . '<div class="featured-price"><span class="demi-condensed">900 €</span></div>'
                . '<div class="featured-details"><span>2 pièces · 40 m²</span></div></a></div>';
        }

        return '<html><body>'
            . ($total === null ? '' : '<div class="sf-results-head"><h5>' . $total . ' logements</h5></div>')
            . '<div class="featured-items">' . $cards . '</div></body></html>';
    }

    /** @param array<string, mixed> $overrides */
    private function paged(PagedHttpClient $client, array $overrides = [], ?\Closure $sleeper = null): HtmlSource
    {
        $definition = new SourceDefinition(
            name: 'inli',
            enabled: true,
            family: 'institutional',
            type: 'html',
            mixedTenure: false,
            defaultTenure: Tenure::LLI,
            url: 'https://www.inli.fr/locations/offres/x',
            baseUrl: 'https://www.inli.fr',
            itemSelector: '.featured-items > .featured-item',
            map: new FieldMap(
                ref: ['a@href => /([^/]+)$'],
                url: ['a@href'],
                commune: ['img@alt'],
                rent: ['.featured-price .demi-condensed'],
                chargesIncluded: true,
            ),
            pageParam: array_key_exists('pageParam', $overrides) ? $overrides['pageParam'] : 'page',
            totalSelector: '.sf-results-head h5',
            // Zero by default, so the suite does not actually sleep between pages: what most of
            // these tests pin is the walk's SHAPE. The pacing test overrides it and injects a
            // sleeper, which is the only way to assert the pause without paying for it.
            maxPages: $overrides['maxPages'] ?? 20,
            rateLimitMs: $overrides['rateLimitMs'] ?? 0,
        );

        return new HtmlSource($definition, $this->store(), $client, Robots::parse(''), sleeper: $sleeper);
    }

    // ---------------------------------------------------------------- helpers

    /** @param array<string, mixed> $overrides */
    private function inli(array $overrides = [], ?FakeHttpClient $client = null, ?Robots $robots = null): HtmlSource
    {
        $definition = new SourceDefinition(
            name: 'inli',
            enabled: true,
            family: 'institutional',
            type: 'html',
            mixedTenure: false,
            defaultTenure: Tenure::LLI,
            url: $overrides['url'] ?? 'https://www.inli.fr/locations/offres/ile-de-france-region_r:11',
            baseUrl: 'https://www.inli.fr',
            itemSelector: array_key_exists('itemSelector', $overrides)
                ? $overrides['itemSelector']
                : '.featured-items > .featured-item',
            map: new FieldMap(
                ref: ['a@href => /([^/]+)$'],
                url: ['a@href'],
                commune: ['img@alt'],
                postcode: ['a@href => -(\d{5})/'],
                rent: ['.featured-price .demi-condensed', '.p'],
                surface: ['.featured-details span => ([\d.,]+)\s*m²'],
                rooms: ['.featured-details span => (\d+)\s*pièce'],
                chargesIncluded: true,
            ),
        );

        return new HtmlSource($definition, $this->store(), $client ?? new FakeHttpClient(null), $robots ?? Robots::parse(''));
    }

    private function store(): Store
    {
        $this->dbPath = sys_get_temp_dir() . '/rentwatch-html-' . bin2hex(random_bytes(8)) . '.sqlite3';

        return Store::open($this->dbPath);
    }
    // ---------------------------------------------------------------- pagination in the PATH

    /**
     * Some sites paginate in the path, not the query string — and for CDC Habitat that is the whole
     * reason the source is pollable at all.
     *
     * Its `robots.txt` disallows every search QUERY PARAMETER by name (`?nbPiece`, `?nbLoyerMin`,
     * `?cdTypeBien`, …). Its own sitemap advertises `/recherche/location/<region>/page-2/`, which
     * carries no query string. A `page_param` walk would append `?page=2` to a URL the site asked
     * not to be queried; `page_path` walks the shape the site publishes.
     */
    public function testAPathPaginatedSourceWalksPagesInThePathNotTheQueryString(): void
    {
        $client = new PathPagedHttpClient([
            1 => self::page(['a', 'b'], 5),
            2 => self::page(['c', 'd'], 5),
            3 => self::page(['e'], 5),
        ]);

        $rows = $this->pathPaged($client)->fetch();

        self::assertSame(['a', 'b', 'c', 'd', 'e'], array_map(static fn ($r) => $r->externalId, $rows));
        self::assertSame([
            'https://www.cdc-habitat.fr/recherche/location/ile-de-france',
            'https://www.cdc-habitat.fr/recherche/location/ile-de-france/page-2',
            'https://www.cdc-habitat.fr/recherche/location/ile-de-france/page-3',
        ], $client->urls, 'page one is the bare url; the rest carry the path segment, and the '
            . 'declared total of 5 ends the walk without a fourth request');
        self::assertSame([], $client->queries, 'no query string is ever added — the site disallows those');
    }

    /**
     * robots.txt is consulted for EVERY page, not only the first.
     *
     * With query-string pagination the path never changes, so one check covered the whole walk. A
     * path-paginated walk visits a different path on every request, and a site may allow the index
     * while disallowing the paginated form. Checking only page one would then walk straight into
     * the disallowed paths with a clean conscience — the exact shape of hard rule 5 violation that
     * is invisible from the outside because every request returns 200.
     */
    public function testPathPaginationChecksRobotsForEveryPageNotJustTheFirst(): void
    {
        $client = new PathPagedHttpClient([1 => self::page(['a', 'b'], 5), 2 => self::page(['c'], 5)]);
        $robots = Robots::parse("User-agent: *\nDisallow: /recherche/location/ile-de-france/page-\n");

        $this->expectException(SourceError::class);
        $this->expectExceptionMessageMatches('~robots.txt disallows /recherche/location/ile-de-france/page-2~');

        $this->pathPaged($client, [], $robots)->fetch();
    }

    /** The `{page}` placeholder is the whole contract — a template without it would refetch page one forever. */
    public function testAPagePathWithNoPlaceholderIsRefused(): void
    {
        $client = new PathPagedHttpClient([1 => self::page(['a'], 5)]);

        $this->expectException(SourceError::class);
        $this->expectExceptionMessageMatches('~\{page\}~');

        $this->pathPaged($client, ['pagePath' => '/page-2'])->fetch();
    }

    /**
     * The declared total ENDS the walk, rather than only auditing it afterwards.
     *
     * Walking one page past the end was the terminator, and it assumed a page past the end comes
     * back empty. CDC Habitat's does not — it **301s** [measured 2026-08-20: page 11 of a 9-page
     * result set redirects], and the adapter refuses a non-2xx on purpose, because a redirect that
     * lands back on page one ends a walk exactly like a real last page. So the probe turned a
     * complete, correct walk into `broken / 0 items`.
     *
     * Stopping at the declared total is not new trust: that number was already the assertion the
     * whole mechanism exists to make. Using it to stop as well as to check costs one fewer request
     * to somebody else's server every pass, which hard rule 5 asks for anyway.
     */
    public function testTheWalkStopsOnceTheSitesDeclaredTotalIsReached(): void
    {
        // 5 declared, 2+2+1 across three pages — and a fourth request would fail outright.
        $client = new PathPagedHttpClient([
            1 => self::page(['a', 'b'], 5),
            2 => self::page(['c', 'd'], 5),
            3 => self::page(['e'], 5),
        ], throwBeyond: 3);

        $rows = $this->pathPaged($client)->fetch();

        self::assertCount(5, $rows);
        self::assertCount(3, $client->urls, 'no probe past the last page once the count is satisfied');
    }

    /** With NO declared total there is nothing to stop on, so the empty-page probe still applies. */
    public function testWithoutADeclaredTotalTheWalkStillProbesForAnEmptyPage(): void
    {
        $client = new PathPagedHttpClient([1 => self::page(['a'], null)]);

        self::assertCount(1, $this->pathPaged($client)->fetch());
        self::assertCount(2, $client->urls, 'one probe past the end, then stop');
    }

    /** @param array<string, mixed> $overrides */
    private function pathPaged(PathPagedHttpClient $client, array $overrides = [], ?Robots $robots = null): HtmlSource
    {
        $definition = new SourceDefinition(
            name: 'cdc_habitat',
            enabled: true,
            family: 'institutional',
            type: 'html',
            mixedTenure: true,
            defaultTenure: null,
            url: 'https://www.cdc-habitat.fr/recherche/location/ile-de-france',
            baseUrl: 'https://www.cdc-habitat.fr',
            itemSelector: '.featured-items > .featured-item',
            map: new FieldMap(
                ref: ['a@href => /([^/]+)$'],
                url: ['a@href'],
                commune: ['img@alt'],
                rent: ['.featured-price .demi-condensed'],
                chargesIncluded: true,
            ),
            pagePath: array_key_exists("pagePath", $overrides) ? $overrides["pagePath"] : "/page-{page}",
            totalSelector: '.sf-results-head h5',
            maxPages: $overrides['maxPages'] ?? 20,
            rateLimitMs: 0,
        );

        return new HtmlSource($definition, $this->store(), $client, $robots ?? Robots::parse(''));
    }


    /**
     * A `{page}` in the URL itself, for a site whose page number is not a suffix.
     *
     * Cityloger paginates through `resultats-location-{page}-defaut-`, where the number sits in the
     * MIDDLE of the path. `page_path` cannot express that — it appends — and the tempting
     * alternative was to point `url` at the site root and let page one be the homepage, whose
     * listing widget happens to hold the same ten cards today. That equality is not a guarantee:
     * the day the widget becomes "featured" rather than ranks 1-10, page one's listings are never
     * fetched at all and nothing anywhere says so. So page one substitutes like every other page.
     */
    public function testAPageTemplateInTheUrlSubstitutesEveryPageIncludingTheFirst(): void
    {
        $client = new TemplatedUrlHttpClient([
            1 => $this->templatedPage(['a1', 'a2']),
            2 => $this->templatedPage(['b1']),
        ]);

        $rows = $this->templatedSource($client)->fetch();

        self::assertCount(3, $rows);
        self::assertSame(
            [
                'https://example.test/resultats-location-1-defaut-',
                'https://example.test/resultats-location-2-defaut-',
                'https://example.test/resultats-location-3-defaut-',
            ],
            $client->urls,
            'page one is fetched through the template too, not from a different url',
        );
    }

    /** Two pagination mechanisms is a guess about which one the site honours. */
    public function testAUrlTemplateCombinedWithPageParamIsRefused(): void
    {
        $client = new TemplatedUrlHttpClient([1 => $this->templatedPage(['a1'])]);

        $this->expectException(SourceError::class);
        $this->expectExceptionMessageMatches('/one way or the other|both/i');

        $this->templatedSource($client, pageParam: 'page')->fetch();
    }

    /** Hard rule 5: with the path changing per page, every page gets its own robots verdict. */
    public function testRobotsIsCheckedForEveryTemplatedPageUrl(): void
    {
        $client = new TemplatedUrlHttpClient([1 => $this->templatedPage(['a1']), 2 => $this->templatedPage(['b1'])]);
        $robots = \Scout\Adapters\Http\Robots::parse("User-agent: *\nDisallow: /resultats-location-2\n");

        $this->expectException(SourceError::class);
        $this->expectExceptionMessageMatches('/robots\.txt disallows/');

        $this->templatedSource($client, robots: $robots)->fetch();
    }

    /** @param list<string> $refs */
    private function templatedPage(array $refs): string
    {
        $cards = '';
        foreach ($refs as $ref) {
            $cards .= '<a class="card" href="https://example.test/logement-' . $ref . '"><span class="commune">HOUILLES</span></a>';
        }

        return '<html><body>' . $cards . '</body></html>';
    }

    private function templatedSource(
        TemplatedUrlHttpClient $client,
        ?string $pageParam = null,
        ?\Scout\Adapters\Http\Robots $robots = null,
    ): HtmlSource {
        $definition = new SourceDefinition(
            name: 'cityloger',
            enabled: true,
            family: 'institutional',
            type: 'html',
            mixedTenure: true,
            url: 'https://example.test/resultats-location-{page}-defaut-',
            baseUrl: 'https://example.test',
            itemSelector: 'a.card',
            pageParam: $pageParam,
            maxPages: 10,
            map: new FieldMap(ref: ['@href => -([^-]+)$'], url: ['@href'], commune: ['.commune']),
            rateLimitMs: 0,
        );

        return new HtmlSource($definition, $this->store(), $client, $robots ?? Robots::parse(''));
    }

}


/**
 * Answers per requested page, and records which pages were asked for.
 *
 * The page is read back out of the URL rather than tracked by call count, so the test also proves
 * the adapter puts the parameter where it said it would — a fake that just counted calls would
 * pass just as happily if every request went to page one.
 */
final class PagedHttpClient implements \Scout\Adapters\Http\HttpClient
{
    /** @var list<string|null> */
    public array $pages = [];

    /** @param array<int, string> $bodies */
    public function __construct(
        private readonly array $bodies,
        private readonly ?string $fallback = null,
    ) {}

    public function send(\Scout\Adapters\Http\HttpRequest $request): HttpResponse
    {
        $query = parse_url($request->url, PHP_URL_QUERY);
        parse_str(is_string($query) ? $query : '', $params);

        $page = isset($params['page']) ? (string) $params['page'] : null;
        $this->pages[] = $page;

        $body = $this->bodies[(int) ($page ?? 1)] ?? $this->fallback ?? '<html><body><div class="featured-items"></div></body></html>';

        return new HttpResponse(200, $body);
    }
}

/** Serves pages addressed by a `/page-N/` PATH segment, and records the URLs it was asked for. */
final class PathPagedHttpClient implements \Scout\Adapters\Http\HttpClient
{
    /** @var list<string> */
    public array $urls = [];

    /** @var list<string> */
    public array $queries = [];

    /** @param array<int, string> $bodies */
    public function __construct(
        private readonly array $bodies,
        private readonly ?int $throwBeyond = null,
    ) {}

    public function send(\Scout\Adapters\Http\HttpRequest $request): HttpResponse
    {
        $this->urls[] = $request->url;

        $query = parse_url($request->url, PHP_URL_QUERY);
        if (is_string($query) && $query !== '') {
            $this->queries[] = $query;
        }

        $page = preg_match('~/page-(\d+)~', $request->url, $m) === 1 ? (int) $m[1] : 1;

        // Stands in for CDC Habitat, whose out-of-range page redirects rather than emptying: a fake
        // that always answers an empty page cannot express "asking is itself the failure".
        if ($this->throwBeyond !== null && $page > $this->throwBeyond) {
            throw new \Scout\Adapters\Http\HttpError('HTTP 301 from ' . $request->url);
        }

        $body = $this->bodies[$page] ?? '<html><body><div class="featured-items"></div></body></html>';

        return new HttpResponse(200, $body);
    }
}


/**
 * Answers per page, where the page number sits INSIDE the path rather than at its end.
 *
 * Reads the page back out of the URL for the same reason {@see PagedHttpClient} does: a fake that
 * counted calls would pass just as happily if every request went to page one, which is precisely
 * the failure the template exists to make impossible.
 */
final class TemplatedUrlHttpClient implements \Scout\Adapters\Http\HttpClient
{
    /** @var list<string> */
    public array $urls = [];

    /** @param array<int, string> $bodies */
    public function __construct(private readonly array $bodies) {}

    public function send(\Scout\Adapters\Http\HttpRequest $request): HttpResponse
    {
        $this->urls[] = $request->url;

        $page = preg_match('~resultats-location-(\d+)-~', $request->url, $m) === 1 ? (int) $m[1] : 0;

        return new HttpResponse(200, $this->bodies[$page] ?? '<html><body></body></html>');
    }
}
