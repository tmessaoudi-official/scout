<?php

declare(strict_types=1);

namespace Scout\Tests\Rent\Enrich;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Adapters\Http\HttpClient;
use Scout\Adapters\Http\HttpRequest;
use Scout\Adapters\Http\HttpResponse;
use Scout\Rent\Config\ConfigLoader;
use Scout\Rent\Enrich\NavitiaCommute;
use Scout\Rent\Store\Store;

/**
 * The IDFM/PRIM commute lookup, against a fake client. No test reaches the network.
 *
 * The endpoint shape asserted here was VERIFIED against the live API on 2026-08-26 (hard rule 1):
 * `apikey` header, `journeys?from=<lon>;<lat>&to=<lon>;<lat>`, `duration` in SECONDS. Those three
 * are exactly the details that are easy to get backwards and impossible to notice afterwards — a
 * reversed coordinate pair still returns a perfectly plausible journey between two other places.
 */
#[CoversClass(NavitiaCommute::class)]
final class NavitiaCommuteTest extends TestCase
{
    private const string CRITERIA = __DIR__ . '/../../../fixtures/rent/criteria/commute.json';

    /** @var list<string> */
    private array $urls = [];

    private ?string $dbPath = null;

    protected function tearDown(): void
    {
        if ($this->dbPath !== null && is_file($this->dbPath)) {
            unlink($this->dbPath);
        }
        $this->dbPath = null;
        $this->urls = [];
    }

    public function testASecondsDurationBecomesWholeMinutes(): void
    {
        // 2148 s is the real probe value: 35.8 minutes. Read as minutes it would be 2148.
        self::assertSame(36, $this->planner(2148)->minutesFrom('Sartrouville', '78500'));
    }

    public function testTheCoordinatePairIsLongitudeFirst(): void
    {
        $this->planner(1200)->minutesFrom('Sartrouville', '78500');

        $journey = $this->urlContaining('/journeys');

        // Île-de-France longitudes are ~2.x and latitudes ~48.x. Reversed, the request succeeds and
        // silently returns a journey between two places in the Indian Ocean.
        self::assertMatchesRegularExpression('~from=2\.[0-9]+%3B48\.[0-9]+~', $journey);
    }

    public function testEveryRequestCarriesTheApiKeyHeader(): void
    {
        $seen = [];

        $planner = $this->planner(1200, $seen);
        $planner->minutesFrom('Sartrouville', '78500');

        self::assertNotSame([], $seen);

        foreach ($seen as $headers) {
            self::assertArrayHasKey('apikey', $headers, 'a request without the key returns 401');
        }
    }

    public function testACommuneIsResolvedOnceAndThenReadFromTheCache(): void
    {
        $planner = $this->planner(1800);

        self::assertSame(30, $planner->minutesFrom('Sartrouville', '78500'));
        $afterFirst = count($this->urls);

        // THE SAME COMMUNE, SPELLED DIFFERENTLY. Logirep returns `Les Clayes-sous-Bois` and `les
        // clayes sous bois` in one response, so an unnormalised cache key spends the requests twice.
        self::assertSame(30, $planner->minutesFrom('SARTROUVILLE', '78500'));

        self::assertCount($afterFirst, $this->urls, 'the second lookup must cost no request at all');
    }

    public function testAFailedLookupIsNotCachedAsUnknownForEver(): void
    {
        // A non-2xx yields null — and must NOT be written to the cache, or one bad afternoon at the
        // API permanently removes the largest component in the score for that commune.
        $planner = $this->plannerReturning(new HttpResponse(503, ''));

        self::assertNull($planner->minutesFrom('Sartrouville', '78500'));

        $store = Store::open((string) $this->dbPath);
        self::assertNull($store->cachedCommuteMinutes('sartrouville', '78500', self::destinationKey()));
    }

    public function testAnUnreachableApiNeverThrows(): void
    {
        // Enrichment runs inside a pass that has already fetched real listings. A lookup that threw
        // would trade a missing score component for every listing in the pass — the blast-radius
        // mistake detail hydration made once already.
        $planner = $this->plannerThrowing();

        self::assertNull($planner->minutesFrom('Sartrouville', '78500'));
    }

    /**
     * A FAILED LOOKUP IS COUNTED (architecture review C-10, 2026-10-08). Both shapes used to become
     * `null` and nothing else, so a revoked key or a dead API removed the commute component from
     * every listing's score while the reasons simply stopped mentioning it.
     */
    public function testAFailedResponseIsCounted(): void
    {
        $planner = $this->plannerReturning(new HttpResponse(503, ''));
        self::assertSame(0, $planner->failedLookups());

        $planner->minutesFrom('Sartrouville', '78500');

        self::assertSame(1, $planner->failedLookups());
    }

    public function testAnUnreachableApiIsCounted(): void
    {
        $planner = $this->plannerThrowing();

        $planner->minutesFrom('Sartrouville', '78500');
        $planner->minutesFrom('Houilles', '78800');

        self::assertSame(2, $planner->failedLookups());
    }

    /** The counterweight: an answer is not a failure, and neither is a place the API cannot match. */
    public function testAnAnsweredLookupIsNotAFailure(): void
    {
        $planner = $this->planner(1200);

        self::assertSame(20, $planner->minutesFrom('Sartrouville', '78500'));
        self::assertSame(0, $planner->failedLookups());

        $empty = $this->plannerReturning(new HttpResponse(200, '{"places": []}'));
        self::assertNull($empty->minutesFrom('Nulle-Part', '78500'));
        self::assertSame(0, $empty->failedLookups(), 'no match is an answer, not an outage');
    }

    public function testAPlaceWhosePostcodeDisagreesIsRefused(): void
    {
        // Commune names repeat across departements, and a wrong geocode is cached for ever and
        // mis-scores a whole town silently. Losing the component is the safe direction.
        // THE FAKE MUST ANSWER BOTH CALLS PROPERLY, and an earlier version did not: it returned the
        // same body for `places` and `journeys`, so with the postcode check removed the journey call
        // received the places JSON, found no `journeys` key and returned null anyway. The test passed
        // whether or not the guard existed — caught by the sabotage ledger, not by review.
        $places = json_encode([
            'places' => [[
                'address' => ['coord' => ['lon' => 2.1, 'lat' => 48.1], 'administrative_regions' => [['zip_code' => '13001']]],
            ]],
        ], JSON_THROW_ON_ERROR);
        $journeys = json_encode(['journeys' => [['duration' => 1200]]], JSON_THROW_ON_ERROR);

        $planner = $this->build(new class($places, $journeys) implements HttpClient {
            public function __construct(private readonly string $places, private readonly string $journeys) {}

            public function send(HttpRequest $request): HttpResponse
            {
                return new HttpResponse(
                    200,
                    str_contains($request->url, '/journeys') ? $this->journeys : $this->places,
                );
            }
        });

        // Marseille's 13001 is not Yvelines' 78500. Without the check this returns 20 minutes for a
        // place 750 km away — cached for ever, and mis-scoring the whole commune in silence.
        self::assertNull($planner->minutesFrom('Sainte-Marie', '78500'));
    }

    public function testChangingTheDestinationInvalidatesTheCache(): void
    {
        // A COMMUTE IS MINUTES BETWEEN TWO PLACES. Cached against only one of them, the day the
        // other changes — a new job, a moved office — every row goes on answering with the journey
        // to the old address, and nothing says so: the numbers stay plausible, the reasons stay
        // confident, failures are deliberately not cached and nothing expires. Same guarantee as the
        // schema-v6 detail-map fingerprint, and the same silent-wrong-for-ever failure it stops.
        $planner = $this->planner(1800);
        self::assertSame(30, $planner->minutesFrom('Sartrouville', '78500'));

        $store = Store::open((string) $this->dbPath);

        // The row is there for THIS destination...
        self::assertSame(30, $store->cachedCommuteMinutes('sartrouville', '78500', self::destinationKey()));

        // ...and reads as NOT CACHED for any other, so the commune re-resolves lazily.
        self::assertNull($store->cachedCommuteMinutes('sartrouville', '78500', sha1('somewhere else')));
    }

    /**
     * THE QUOTA IS 1000 REQUESTS A DAY, AND OUT-OF-AREA LISTINGS WERE SPENDING IT (2026-10-08). Every
     * harvested listing is enriched, and 59 of the 66 uncached communes of one deployed pass were
     * outside the location filter (Indre, Loire, Nantes...): rejected by the engine moments later,
     * after up to three requests each. A listing the location rule refuses now costs nothing, and is
     * not a failure: nothing was attempted.
     */
    public function testAListingOutsideTheLocationFilterCostsNoRequest(): void
    {
        $planner = $this->planner(1200);

        self::assertNull($planner->minutesFrom('Châteauroux', '36000'));
        self::assertSame([], $this->urls);
        self::assertSame(0, $planner->failedLookups());
        self::assertSame(0, $planner->quotaRefusedLookups());
    }

    /** The destination is one place: resolved once per planner, not once per commune. */
    public function testTheDestinationIsResolvedOncePerPlanner(): void
    {
        $planner = $this->plannerAnsweringJourneys(new HttpResponse(200, '{"journeys":[{"duration":1200}]}'));

        self::assertSame(20, $planner->minutesFrom('Sartrouville', '78500'));
        self::assertSame(20, $planner->minutesFrom('Houilles', '78800'));

        $destination = array_filter($this->urls, static fn (string $u): bool => str_contains(urldecode($u), 'Quai test'));
        self::assertCount(1, $destination, 'the destination is re-resolved on every cache miss');
    }

    /**
     * A 429 ENDS THE PASS'S LOOKUPS AND IS REPORTED AS THE QUOTA. Measured live: the answer carries
     * `x-ratelimit-limit-day: 1000` and `x-ratelimit-remaining-day: 0` and stays 429 until the daily
     * reset, so every further request is spent for nothing. The planner stops asking, counts each
     * lookup it could not make, and reports the figures the API gave rather than a hard-coded one.
     */
    public function testA429StopsEveryLaterLookupAndIsReportedAsTheQuota(): void
    {
        $planner = $this->plannerReturning(new HttpResponse(429, '{"message":"API rate limit exceeded"}', [
            'x-ratelimit-limit-day' => '1000',
            'x-ratelimit-remaining-day' => '0',
        ]));

        self::assertNull($planner->minutesFrom('Sartrouville', '78500'));
        self::assertCount(1, $this->urls);
        self::assertNull($planner->minutesFrom('Houilles', '78800'));
        self::assertCount(1, $this->urls, 'no request after the quota said no');

        self::assertSame(2, $planner->quotaRefusedLookups());
        self::assertSame(0, $planner->failedLookups(), 'a quota refusal is not an outage');
        self::assertSame('quota journalier épuisé : 0 restant sur 1000', $planner->quotaDetail());
    }

    /** The same refusal on the SECOND request of a lookup: places answered, the journey was refused. */
    public function testA429OnTheJourneyIsAQuotaRefusalToo(): void
    {
        $planner = $this->plannerAnsweringJourneys(new HttpResponse(429, '{"message":"API rate limit exceeded"}', [
            'x-ratelimit-limit-day' => '1000',
            'x-ratelimit-remaining-day' => '0',
        ]));

        self::assertNull($planner->minutesFrom('Sartrouville', '78500'));

        self::assertSame(1, $planner->quotaRefusedLookups());
        self::assertSame(0, $planner->failedLookups());
    }

    public function testA429WithoutQuotaHeadersSaysOnlyWhatItKnows(): void
    {
        $planner = $this->plannerReturning(new HttpResponse(429, ''));
        $planner->minutesFrom('Sartrouville', '78500');

        self::assertSame('HTTP 429, limite de débit (sans détail de quota)', $planner->quotaDetail());
        self::assertNull($this->plannerReturning(new HttpResponse(503, ''))->quotaDetail(), 'no refusal, no detail');
    }

    /**
     * A 404 THAT ANSWERS IS NOT AN OUTAGE. Probed live 2026-10-08: an origin no public transport
     * reaches returns `404 {"error":{"id":"no_origin"}}`. Counted, it made the warning blame the key
     * or the network for "no route from here". Only answers are excused; any other 404 still counts.
     */
    public function testA404ThatAnswersIsNotAFailureAndIsNotAskedTwice(): void
    {
        $planner = $this->plannerAnsweringJourneys(new HttpResponse(404, '{"error":{"id":"no_origin","message":"Public transport is not reachable from origin"}}'));

        self::assertNull($planner->minutesFrom('Sartrouville', '78500'));
        self::assertSame(0, $planner->failedLookups());

        $before = count($this->urls);
        self::assertNull($planner->minutesFrom('Sartrouville', '78500'));
        self::assertCount($before, $this->urls, 'a no-route answer is remembered for the planner\'s life');
    }

    public function testAnyOther404IsStillAFailure(): void
    {
        $outOfBounds = $this->plannerAnsweringJourneys(new HttpResponse(404, '{"error":{"id":"date_out_of_bounds"}}'));
        $outOfBounds->minutesFrom('Sartrouville', '78500');
        self::assertSame(1, $outOfBounds->failedLookups(), 'a timetable the date falls outside is a real problem');

        $bare = $this->plannerAnsweringJourneys(new HttpResponse(404, 'Not Found'));
        $bare->minutesFrom('Sartrouville', '78500');
        self::assertSame(1, $bare->failedLookups(), 'a 404 that says nothing is not an answer');
    }

    /** The fingerprint the planner writes: a HASH, because the destination is a personal address. */
    private static function destinationKey(): string
    {
        $criteria = ConfigLoader::loadCriteria(self::CRITERIA);

        return sha1((string) $criteria->commuteStation);
    }

    // ── plumbing ─────────────────────────────────────────────────────────────────────────────────

    private function planner(int $durationSeconds, array &$headerSink = []): NavitiaCommute
    {
        $places = json_encode([
            'places' => [[
                'address' => [
                    'coord' => ['lon' => 2.1602, 'lat' => 48.9376],
                    'administrative_regions' => [['zip_code' => '78500']],
                ],
            ]],
        ], JSON_THROW_ON_ERROR);

        $journeys = json_encode(
            ['journeys' => [['duration' => $durationSeconds], ['duration' => $durationSeconds + 600]]],
            JSON_THROW_ON_ERROR,
        );

        return $this->build(new class($places, $journeys, $this->urls, $headerSink) implements HttpClient {
            public function __construct(
                private readonly string $places,
                private readonly string $journeys,
                private array &$urls,
                private array &$headers,
            ) {}

            public function send(HttpRequest $request): HttpResponse
            {
                $this->urls[] = $request->url;
                $this->headers[] = $request->headers;

                return new HttpResponse(
                    200,
                    str_contains($request->url, '/journeys') ? $this->journeys : $this->places,
                );
            }
        });
    }

    private function plannerReturning(HttpResponse $response): NavitiaCommute
    {
        return $this->build(new class($response, $this->urls) implements HttpClient {
            public function __construct(private readonly HttpResponse $response, private array &$urls) {}

            public function send(HttpRequest $request): HttpResponse
            {
                $this->urls[] = $request->url;

                return $this->response;
            }
        });
    }

    /**
     * `places` answers with the postcode the query asked for (so any commune passes the postcode
     * check), and `journeys` answers with `$journeys`.
     */
    private function plannerAnsweringJourneys(HttpResponse $journeys): NavitiaCommute
    {
        return $this->build(new class($journeys, $this->urls) implements HttpClient {
            public function __construct(private readonly HttpResponse $journeys, private array &$urls) {}

            public function send(HttpRequest $request): HttpResponse
            {
                $this->urls[] = $request->url;

                if (str_contains($request->url, '/journeys')) {
                    return $this->journeys;
                }

                parse_str((string) parse_url($request->url, PHP_URL_QUERY), $query);
                $zip = preg_match('~(\d{5})$~', (string) ($query['q'] ?? ''), $m) === 1 ? $m[1] : '92600';

                return new HttpResponse(200, json_encode(['places' => [[
                    'address' => ['coord' => ['lon' => 2.16, 'lat' => 48.93], 'administrative_regions' => [['zip_code' => $zip]]],
                ]]], JSON_THROW_ON_ERROR));
            }
        });
    }

    private function plannerThrowing(): NavitiaCommute
    {
        return $this->build(new class implements HttpClient {
            public function send(HttpRequest $request): HttpResponse
            {
                throw new \RuntimeException('network down');
            }
        });
    }

    private function build(HttpClient $http): NavitiaCommute
    {
        $this->dbPath ??= sys_get_temp_dir() . '/rentwatch-commute-' . bin2hex(random_bytes(8)) . '.sqlite3';

        return new NavitiaCommute(
            $http,
            Store::open($this->dbPath),
            ConfigLoader::loadCriteria(self::CRITERIA),
            'test-key',
            '20260827T083000',
            '2026-08-26T12:00:00+00:00',
        );
    }

    private function urlContaining(string $needle): string
    {
        foreach ($this->urls as $url) {
            if (str_contains($url, $needle)) {
                return $url;
            }
        }

        self::fail('no request to ' . $needle);
    }
}
