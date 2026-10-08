<?php

declare(strict_types=1);

namespace Scout\Rent\Enrich;

use Scout\Adapters\Http\HttpClient;
use Scout\Adapters\Http\HttpRequest;
use Scout\Adapters\Http\HttpResponse;
use Scout\Rent\Config\Criteria;
use Scout\Rent\Store\Store;

/**
 * Île-de-France Mobilités (PRIM), Navitia-based, over the ordinary {@see HttpClient} seam.
 *
 * **The endpoint and the auth header were VERIFIED against the live API on 2026-08-26**, not written
 * from memory (hard rule 1). A probe of `journeys?from=2.3200;48.8589&to=2.2870;48.9110` with an
 * `apikey` header returned HTTP 200 and `journeys[0].duration = 2148` seconds. Two details of that
 * are easy to get backwards and both are load-bearing: coordinates are **longitude first**,
 * semicolon-separated, and `duration` is in **SECONDS**.
 *
 * Two lookups per commune, then never again:
 *
 * 1. `places` resolves the commune name + postcode to coordinates;
 * 2. `journeys` asks for the trip to the configured destination.
 *
 * The result is cached in the store PER COMMUNE, because a commune's coordinates and its journey are
 * properties of the place rather than of the listing.
 *
 * **THE QUOTA IS 1000 REQUESTS A DAY**, read off PRIM's own 429 on 2026-10-08
 * (`x-ratelimit-limit-day: 1000`). This docblock said "a documented quota of 20 000", and the
 * difference was being spent: every harvested listing was looked up, out-of-area ones included, a
 * failure was never cached, and each miss re-resolved the destination, so the quota ran out daily and
 * every later lookup was refused until the reset. Three economies follow, each in `minutesFrom()`:
 * a listing outside the location rule costs nothing, the destination is resolved once per planner,
 * and the first 429 ends the planner's lookups.
 */
final readonly class NavitiaCommute implements CommutePlanner, ReportsCommuteFailures
{
    private const string BASE = 'https://prim.iledefrance-mobilites.fr/marketplace/v2/navitia';

    public function __construct(
        private HttpClient $http,
        private Store $store,
        private Criteria $criteria,
        private string $apiKey,
        /**
         * The reference departure, as `YYYYMMDDTHHMMSS`.
         *
         * FIXED, and that is the point. The verified probe passed no `datetime` and so sampled
         * "now" — which would make a commune resolved at 02:00 (night buses, no RER) incomparable
         * with one resolved at 08:30, on the component that carries the most weight in the score.
         * Cached values must be measured against one timetable or they are noise.
         *
         * **Stated cost:** every duration is a one-time sample of that representative departure, so
         * it does not reflect the hour a particular listing was found, nor a strike, nor works.
         */
        private string $referenceDeparture,
        private ?string $nowIso = null,
        private CommuteFailures $failures = new CommuteFailures(),
        private CommuteMemo $memo = new CommuteMemo(),
    ) {}

    /**
     * Navitia's 404 error ids that ANSWER rather than fail: there is no public transport at one end.
     * `no_origin` was probed live on 2026-10-08 (`404 {"error":{"id":"no_origin","message":"Public
     * transport is not reachable from origin"}}`); its two siblings are named by symmetry and are
     * [Unverified]. `no_solution` is deliberately absent: an origin with transport but no journey may
     * well come back as a 200 with no journeys, which `journeyMinutes()` already reads as an answer,
     * and an unprobed id must not excuse a failure. Every other 404 is still counted.
     */
    private const array NO_ROUTE_ANSWERS = ['no_origin', 'no_destination', 'no_origin_nor_destination'];

    public function failedLookups(): int
    {
        return $this->failures->count();
    }

    public function quotaRefusedLookups(): int
    {
        return $this->failures->quotaRefusals();
    }

    public function quotaDetail(): ?string
    {
        return $this->failures->quotaDetail();
    }

    public function minutesFrom(?string $commune, ?string $postcode): ?int
    {
        if (!$this->criteria->commuteEnabled) {
            return null;
        }

        $destination = $this->criteria->commuteStation;

        if ($commune === null || $postcode === null || $destination === null || $this->apiKey === '') {
            return null;
        }

        // NORMALISED, because the same commune arrives spelled two ways in one response — `Les
        // Clayes-sous-Bois` and `les clayes sous bois`, observed on Logirep. An unnormalised key
        // caches the same place twice and spends the requests twice.
        $key = Criteria::communeKey($commune);

        // The destination is HASHED rather than stored verbatim: it is a personal address, and the
        // store's rows end up in diagnostics, in backups and in anything anyone pastes. A hash
        // compares exactly as well and reveals nothing.
        $destinationKey = sha1($destination);

        $cached = $this->store->cachedCommuteMinutes($key, $postcode, $destinationKey);

        if ($cached !== null) {
            return $cached;
        }

        // OUTSIDE THE LOCATION RULE, NOTHING IS SPENT. The engine rejects such a listing on this same
        // predicate moments later, and 59 of the 66 uncached communes of one deployed pass were of
        // that kind. After the cache read, so a row cached before a narrowing stays readable.
        // Stated cost: such a listing is snapshotted without a commute, so if `postcode_prefixes` is
        // later WIDENED, `scout reclassify` judges it without the component until it is sighted again.
        if (!$this->criteria->matchesLocation($commune, $postcode)) {
            return null;
        }

        $origin = $key . '|' . $postcode;

        if ($this->memo->isNoRoute($origin)) {
            return null;
        }

        // The quota said no earlier in this planner's life: every further request would be refused
        // too. Counted, because the listing still lacks its commute.
        if ($this->failures->quotaExhausted()) {
            $this->failures->recordQuota(null);

            return null;
        }

        // NOTHING BELOW MAY THROW. Enrichment runs inside a pass that has already fetched real
        // listings from live sources, so a commute lookup that voided the pass would trade a missing
        // score component for every listing in it — the blast-radius mistake detail hydration made
        // once already.
        try {
            $from = $this->coordinatesOf($commune, $postcode);

            if ($from === null) {
                return null;
            }

            $to = $this->memo->destination($destination);

            if ($to === null) {
                $to = $this->coordinatesOf($destination, null);

                if ($to !== null) {
                    $this->memo->rememberDestination($destination, $to);
                }
            }

            if ($to === null) {
                return null;
            }

            $minutes = $this->journeyMinutes($from, $to, $origin);

            if ($minutes === null) {
                return null;
            }

            $this->store->rememberCommute(
                $key,
                $postcode,
                $from[1],
                $from[0],
                $minutes,
                $this->nowIso ?? (new \DateTimeImmutable())->format(\DATE_ATOM),
                $destinationKey,
            );

            return $minutes;
        } catch (\Throwable) {
            // An exception is an outage, never an answer: counted (architecture review C-10).
            $this->failures->record();

            return null;
        }
    }

    /**
     * Resolve a place to `[lon, lat]`, or `null`.
     *
     * **A resolved place is CHECKED against the postcode it was asked for.** A wrong geocode is
     * cached for ever and would mis-score a whole commune silently — and commune names repeat across
     * departements, so `Sainte-Marie` alone is not an address. On a mismatch this yields `null`,
     * losing the component rather than returning a confident wrong number.
     *
     * @return array{0: float, 1: float}|null
     */
    private function coordinatesOf(string $place, ?string $postcode): ?array
    {
        $response = $this->http->send(new HttpRequest(
            url: self::BASE . '/places?' . http_build_query([
                'q' => $postcode === null ? $place : $place . ' ' . $postcode,
                'type[]' => 'address',
                'count' => 5,
            ]),
            headers: ['apikey' => $this->apiKey],
        ));

        if ($response->status === 429) {
            $this->failures->recordQuota(self::quotaDetailOf($response));

            return null;
        }

        if (!$response->isSuccess()) {
            $this->failures->record();

            return null;
        }

        $data = json_decode($response->body, true, 32, \JSON_THROW_ON_ERROR);

        if (!is_array($data) || !isset($data['places']) || !is_array($data['places'])) {
            return null;
        }

        foreach ($data['places'] as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $coord = $this->coordOf($candidate);

            if ($coord === null) {
                continue;
            }

            if ($postcode !== null && !$this->matchesPostcode($candidate, $postcode)) {
                continue;
            }

            return $coord;
        }

        return null;
    }

    /** @param array<string,mixed> $candidate @return array{0: float, 1: float}|null */
    private function coordOf(array $candidate): ?array
    {
        foreach (['address', 'administrative_region', 'stop_area'] as $kind) {
            $inner = $candidate[$kind] ?? null;

            if (!is_array($inner)) {
                continue;
            }

            $coord = $inner['coord'] ?? null;

            if (is_array($coord) && isset($coord['lon'], $coord['lat'])) {
                return [(float) $coord['lon'], (float) $coord['lat']];
            }
        }

        return null;
    }

    /** @param array<string,mixed> $candidate */
    private function matchesPostcode(array $candidate, string $postcode): bool
    {
        // The zip can sit on the place itself or on any of its administrative regions, and Navitia
        // returns a comma-separated list for a commune spanning several codes.
        $haystack = json_encode($candidate, \JSON_THROW_ON_ERROR);

        return str_contains($haystack, '"' . $postcode . '"')
            || str_contains($haystack, $postcode . ';')
            || str_contains($haystack, ';' . $postcode);
    }

    /**
     * @param array{0: float, 1: float} $from
     * @param array{0: float, 1: float} $to
     */
    private function journeyMinutes(array $from, array $to, string $origin): ?int
    {
        $response = $this->http->send(new HttpRequest(
            url: self::BASE . '/journeys?' . http_build_query([
                // LONGITUDE FIRST, semicolon-separated. Verified against the live API; reversed, the
                // request still succeeds and returns a journey between two other places entirely.
                'from' => $from[0] . ';' . $from[1],
                'to' => $to[0] . ';' . $to[1],
                'datetime' => $this->referenceDeparture,
            ]),
            headers: ['apikey' => $this->apiKey],
            timeoutSeconds: 30,
        ));

        if ($response->status === 429) {
            $this->failures->recordQuota(self::quotaDetailOf($response));

            return null;
        }

        if ($response->status === 404 && self::answersNoRoute($response->body)) {
            $this->memo->rememberNoRoute($origin);

            return null;
        }

        if (!$response->isSuccess()) {
            $this->failures->record();

            return null;
        }

        $data = json_decode($response->body, true, 32, \JSON_THROW_ON_ERROR);
        $journeys = is_array($data) ? ($data['journeys'] ?? null) : null;

        if (!is_array($journeys) || $journeys === []) {
            return null;
        }

        $best = null;

        foreach ($journeys as $journey) {
            if (!is_array($journey) || !isset($journey['duration'])) {
                continue;
            }

            // SECONDS, verified. Read as minutes it would report a 35-minute trip as 2148.
            $seconds = (int) $journey['duration'];

            if ($seconds <= 0) {
                continue;
            }

            $best = $best === null ? $seconds : min($best, $seconds);
        }

        return $best === null ? null : (int) round($best / 60);
    }

    private static function answersNoRoute(string $body): bool
    {
        $data = json_decode($body, true);
        $id = is_array($data) && is_array($data['error'] ?? null) ? ($data['error']['id'] ?? null) : null;

        return is_string($id) && in_array($id, self::NO_ROUTE_ANSWERS, true);
    }

    /**
     * What a 429 said, from PRIM's own headers when it sent them: a day's figures are evidence, a
     * hard-coded one would go stale exactly as the "20 000" in this class's docblock did.
     */
    private static function quotaDetailOf(HttpResponse $response): string
    {
        $limit = $response->header('x-ratelimit-limit-day');
        $remaining = $response->header('x-ratelimit-remaining-day');

        if ($limit === null || $remaining === null || !ctype_digit($limit) || !ctype_digit($remaining)) {
            return 'HTTP 429, limite de débit (sans détail de quota)';
        }

        return $remaining === '0'
            ? sprintf('quota journalier épuisé : 0 restant sur %s', $limit)
            : sprintf('HTTP 429, limite de débit (quota journalier : %s restant sur %s)', $remaining, $limit);
    }
}
