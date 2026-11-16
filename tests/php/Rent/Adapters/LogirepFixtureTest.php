<?php

declare(strict_types=1);

namespace Scout\Tests\Rent\Adapters;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Scout\Adapters\Http\HttpClient;
use Scout\Adapters\Http\HttpRequest;
use Scout\Adapters\Http\HttpResponse;
use Scout\Adapters\Http\Robots;
use Scout\Rent\Adapters\HttpJsonSource;
use Scout\Rent\Config\ConfigLoader;
use Scout\Rent\Config\Criteria;
use Scout\Rent\Config\SourceDefinition;
use Scout\Rent\Core\RawListing;
use Scout\Rent\Core\SourceProfile;
use Scout\Rent\Core\Tenure;
use Scout\Rent\Core\TenureClassifier;
use Scout\Rent\Store\Store;

/**
 * Logirep/Polylogis — source #4, against the payload frozen on 2026-08-22.
 *
 * The page ships all 113 records as JSON inside one script tag, so this exercises
 * `embedded_json_selector` end to end against the real thing rather than a hand-written sample. The
 * client refuses to be called for anything but the frozen page: a fixture test that can reach the
 * network is a monitoring check wearing a test's name.
 *
 * Four assertions here are not about parsing at all, and each one is a defect that would otherwise
 * be silent:
 *
 * - **`commune` must not be null.** Every text field on this Drupal/Solr payload is boxed as a
 *   one-element list. Before `Payload::scalarOf()`, all 113 commune values read as `null`, and
 *   `Criteria::matchesCommune()` refuses a null commune — so the source would have matched nothing,
 *   ever, while `SourceHealth` reported 113 items and a green status.
 * - **rent must land in `rentHc`, never `rentCc`.** The source quotes hors charges and the gap is
 *   ~30% (`1 279 € h.c.` vs `1 662,67 € c.c.` on the one dwelling measured). Filed as CC it would
 *   be compared against `max_rent_cc` and let flats through that are over budget.
 * - **a parking's room count must be 0, not null.** `null` means *unknown*, which hard rule 9 says
 *   is not below the minimum — so `min_rooms` would not reject it.
 * - **no listing may classify as an excluded tenure.** The source states no tenure anywhere, so
 *   every listing must resolve `UNKNOWN` and reach the digest, never a match.
 */
#[CoversNothing]
final class LogirepFixtureTest extends TestCase
{
    private ?string $dbPath = null;

    /**
     * Parsed once per test rather than per call.
     *
     * The frozen page is 167 KB and carries 113 records, and several tests walk the whole payload —
     * re-parsing it (and opening a fresh SQLite store) for each assertion made this class the
     * slowest in the suite by an order of magnitude, for no extra coverage.
     *
     * @var list<RawListing>|null
     */
    private ?array $rows = null;

    protected function tearDown(): void
    {
        if ($this->dbPath !== null && is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
    }

    public function testTheFrozenPageYieldsAllOneHundredAndThirteenRecords(): void
    {
        self::assertCount(113, $this->fetch());
    }

    public function testTheAvonStudioMapsFieldForField(): void
    {
        $row = $this->byRef('Lrs-1300-1678889398');

        self::assertSame('Appartement Studio 35 m²', $row->title);
        self::assertSame('AVON', $row->commune);
        self::assertSame('77210', $row->postcode);
        self::assertSame(35.0, $row->surfaceM2);
        self::assertSame(1, $row->rooms);
        self::assertSame(
            'https://logirep.polylogis.immo/annonce/lrs-1300-1678889398',
            $row->url,
            'a relative href must be resolved against base_url — a link nobody can tap from a phone is not a link',
        );
    }

    public function testEveryListingCarriesACommuneAndAPostcode(): void
    {
        // The single-element-list trap, asserted across the whole payload rather than on one record.
        foreach ($this->fetch() as $row) {
            self::assertNotNull($row->commune, 'null commune on ' . $row->externalId . ' — matchesCommune() would refuse it');
            self::assertNotSame('', $row->commune);
            self::assertNotNull($row->postcode, 'null postcode on ' . $row->externalId);
        }
    }

    public function testEveryRentIsFiledHorsChargesAndNoneAsChargesComprises(): void
    {
        $withRent = 0;

        foreach ($this->fetch() as $row) {
            self::assertNull(
                $row->rentCc,
                'the source quotes h.c. — filing ' . $row->externalId . ' as CC would compare the wrong '
                    . 'number against max_rent_cc, understated by ~30%',
            );

            if ($row->rentHc !== null) {
                ++$withRent;
            }
        }

        self::assertGreaterThan(100, $withRent, 'nearly every record on this payload quotes a price');
    }

    public function testAParkingHasZeroRoomsRatherThanAnUnknownRoomCount(): void
    {
        $zero = array_values(array_filter(
            $this->fetch(),
            static fn (RawListing $r): bool => str_starts_with((string) $r->title, 'Place de parking'),
        ));

        self::assertNotSame([], $zero, 'the frozen payload is expected to contain parkings');

        foreach ($zero as $row) {
            self::assertSame(
                0,
                $row->rooms,
                'a parking has zero rooms — read as null it would mean "unknown", which hard rule 9 '
                    . 'says is NOT below the minimum, and min_rooms would not reject it',
            );
        }
    }

    public function testTheRefIsTheSitesOwnStableIdentifierAndIsUniqueAcrossThePayload(): void
    {
        // A ref that collides re-identifies two flats as one; a ref that drifts re-notifies forever.
        $refs = array_map(static fn (RawListing $r): string => (string) $r->externalId, $this->fetch());

        self::assertCount(113, $refs);
        self::assertSame(113, count(array_unique($refs)), 'every listing must have its own ref');

        foreach ($refs as $ref) {
            self::assertNotSame('', trim($ref));
        }
    }

    // ── §1: the source states no tenure, so nothing may be surfaced as a match ────────────────────

    public function testNoListingIsSurfacableAndEveryOneResolvesUnknown(): void
    {
        // Both §1 assertions in ONE walk of the payload, deliberately.
        //
        // They were two tests, and that cost 17 seconds of the suite for nothing: classifying 113
        // listings twice proves exactly what classifying them once proves. It matters more than it
        // looks, because `tests/sabotage-check.sh` runs the WHOLE suite once per sabotage case —
        // around 315 of them — so a second wasted in a fixture test is five minutes on the ledger.
        //
        // What is asserted: nothing on this source may be surfaced (the excluded set), and the
        // expected resting state is UNKNOWN (ruled 2026-08-22 — the source states no tenure
        // anywhere, so every listing goes to the *à vérifier* digest and none is a match). If the
        // second assertion ever goes red the source has started publishing a tenure signal, which
        // is a reason to go looking for a `tenure_field` — never a reason to relax the assertion.
        $profile = new SourceProfile('logirep', 'institutional', null, true);
        $classifier = new TenureClassifier();

        foreach ($this->fetch() as $row) {
            $tenure = $classifier->classify($row, $profile)->tenure;

            self::assertNotSame(
                Tenure::SOCIAL,
                $tenure,
                'nothing on this source states tenure, so a SOCIAL verdict on ' . $row->externalId
                    . ' could only come from furniture or from a title read as a signal',
            );
            self::assertSame(
                Tenure::UNKNOWN,
                $tenure,
                $row->externalId . ' resolved a tenure — the source is expected to state none',
            );
        }
    }

    // ── hard rule 5: every url this source would visit, against its own frozen robots.txt ─────────

    public function testTheSearchUrlIsAllowedByTheSitesOwnRobotsFile(): void
    {
        $robots = Robots::parse((string) file_get_contents(self::fixturePath('robots.txt')));

        self::assertTrue(
            $robots->allows(Robots::pathOf('/recherche?ss_trnsctntp=leasing')),
            'the configured search path must be allowed',
        );
        self::assertFalse(
            $robots->allows('/search/whatever'),
            'the frozen file is expected to disallow /search/ — robots matching is LITERAL, which is '
                . 'the only reason /recherche is reachable at all',
        );
    }

    // ── the gate, measured rather than assumed ───────────────────────────────────────────────────

    public function testTheLocationGateOnTheShippedConfigIsMeasuredNotAssumed(): void
    {
        // Guards a premise that was WRONG when this source was proposed: "8 rows are in the 78/95
        // departments the criteria filter on" was read as 8 matches.
        //
        // REWRITTEN TWICE on 2026-08-22, and both times the number moved for a real reason rather
        // than to make a change pass — which is the distinction this test exists to force someone
        // to make out loud. It first asserted ONE row (Bezons), because the shipped config named
        // ten communes and `matchesCommune()` needs the NAME as well as the prefix. Region mode —
        // `communes: []`, prefixes are the whole filter — took it to EIGHT across 78/95. Widening
        // the region to all eight Île-de-France departements takes it to NINETEEN, across eleven
        // communes in six departements, which is the widening measured rather than asserted.
        //
        // Nineteen rows past the LOCATION gate is not nineteen matches: the rent ceiling dropped to
        // 1200 € CC in the same change, and this source quotes hors charges, so `max_rent_cc` never
        // fires on it at all. That is deliberate and recorded in CLAUDE.md — the ceiling is not
        // checkable for Logirep — and it is exactly why this test asserts the LOCATION gate alone
        // and names it in its own title.
        //
        // Deliberately still bound to the SHIPPED file rather than to a fixture-local config: its
        // job is to make the live consequence of a criteria edit visible in a test run, so it must
        // fail when that file changes. It reads whichever mode is configured.
        $criteria = ConfigLoader::loadCriteria(self::root() . '/config/rent/criteria.json');
        self::assertInstanceOf(Criteria::class, $criteria);

        $passing = array_values(array_filter(
            $this->fetch(),
            static fn (RawListing $r): bool => $criteria->matchesCommune($r->commune, $r->postcode),
        ));

        // Compared on the CANONICAL key, not the raw label. This source publishes the same commune
        // in two casings — `Les Clayes-sous-Bois` and `les clayes sous bois` both appear in the
        // frozen payload — so a raw-string assertion would pin the site's typography rather than
        // the gate's behaviour, and would churn on an editorial change that means nothing here.
        // Criteria::communeKey() folds them together, which is the same normalisation the filter
        // itself uses.
        $keys = array_map(
            static fn (RawListing $r): string => Criteria::communeKey((string) $r->commune),
            $passing,
        );
        $distinct = array_values(array_unique($keys));
        sort($distinct);

        self::assertCount(
            19,
            $passing,
            'on the frozen payload exactly nineteen rows pass the location gate under the shipped '
                . 'region-mode config — a change here means the criteria were edited or the source '
                . 'started publishing elsewhere, and both are worth noticing',
        );
        self::assertSame([
            'avon',
            'bagneux',
            'bezons',
            'chennevieres sur marne',
            'le plessis trevise',
            'les clayes sous bois',
            'montreuil',
            'neuilly sur marne',
            'osny',
            'rueil malmaison',
            'vincennes',
        ], $distinct);
    }

    /** @return list<RawListing> */
    private function fetch(): array
    {
        return $this->rows ??= $this->source()->fetch();
    }

    private function byRef(string $ref): RawListing
    {
        foreach ($this->fetch() as $row) {
            if ($row->externalId === $ref) {
                return $row;
            }
        }

        self::fail('ref ' . $ref . ' is not in the frozen payload');
    }

    private function source(): HttpJsonSource
    {
        $this->dbPath = sys_get_temp_dir() . '/rentwatch-logirep-' . bin2hex(random_bytes(8)) . '.sqlite3';

        $definition = null;
        foreach (ConfigLoader::loadSources(self::root() . '/config/rent/sources.json') as $candidate) {
            if ($candidate->name === 'logirep') {
                $definition = $candidate;
            }
        }

        self::assertInstanceOf(
            SourceDefinition::class,
            $definition,
            'the committed config must carry a logirep block — this test asserts the SHIPPED mapping, '
                . 'not a copy of it that could drift',
        );

        $page = (string) file_get_contents(self::fixturePath('search.html'));

        return new HttpJsonSource(
            $definition,
            new class($page) implements HttpClient {
                public function __construct(private readonly string $page) {}

                public function send(HttpRequest $request): HttpResponse
                {
                    if (!str_contains($request->url, '/recherche')) {
                        throw new \LogicException('a fixture test must not request ' . $request->url);
                    }

                    return new HttpResponse(200, $this->page);
                }
            },
            Robots::parse((string) file_get_contents(self::fixturePath('robots.txt'))),
        );
    }

    private static function fixturePath(string $name): string
    {
        return self::root() . '/tests/fixtures/rent/logirep/' . $name;
    }

    private static function root(): string
    {
        return dirname(__DIR__, 4);
    }
}
