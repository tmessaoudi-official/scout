<?php

declare(strict_types=1);

namespace Scout\Rent\Adapters;

use Scout\Core\PatternMissLog;
use Dom\HTMLDocument;
use Scout\Adapters\Http\HttpClient;
use Scout\Adapters\Http\HttpError;
use Scout\Adapters\Http\HttpRequest;
use Scout\Adapters\Http\Robots;
use Scout\Adapters\SourceError;
use Scout\Core\Redact;
use Scout\Rent\Adapters\Html\Selector;
use Scout\Rent\Config\FieldMap;
use Scout\Rent\Config\SourceDefinition;
use Scout\Rent\Core\RawListing;
use Scout\Rent\Store\Store;
use Scout\Rent\Store\StoredDetail;

/**
 * Follows a listing card to its own page and merges what only that page knows.
 *
 * **Extracted from {@see HtmlSource} on 2026-09-01, and the reason is §1 rather than tidiness.** A
 * hydrated description is what the tenure classifier reads, so hydration is a §1-adjacent path — and
 * this repo's answer to a §1-adjacent path is exactly one implementation of it (hard rule 9's own
 * argument, the one that keeps every field extraction funnelling through {@see ListingMapper}). PAP
 * needs hydration because its alert carries no listing prose at all, which makes `exclude_patterns`
 * structurally inert on the source; copying these two hundred lines into `EmailAlertSource` would
 * have created a second place for the cache, the budget, the backoff and the merge to drift, and the
 * drift would be invisible — a source quietly judging listings on less evidence than its twin.
 *
 * Every guarantee below travelled unchanged from `HtmlSource`; none was re-decided here.
 *
 * **THE CACHE IS THE GATE.** Not a predicate. It was `Criteria::matchesCommune()`, injected by the
 * CLI, and that shape was wrong for a reason worth keeping: a per-pass predicate makes a listing's
 * verdict depend on which pass is looking at it, so a listing the title filter REJECTED while
 * hydrated returns as a bare card on the next pass and notifies. Novelty is the gate and it lives in
 * `listing_detail`, keyed on `(source, external_id)` — never on `dedup_key`, because normalisation
 * evolves and a row keyed on a conclusion orphans the whole cache the day it changes. A page already
 * on record costs no request ever again, so steady state is ZERO extra requests.
 *
 * **The BUDGET bounds the cold start**, which is the only expensive moment: In'li's ~174 listings
 * are all novel at once, and at Q37 pacing that is a three-hour pass. The backlog drains over
 * several passes.
 *
 * **PRIORITY decides who gets a short budget**, and the caller supplies it because the ranking is
 * the run's own state — see {@see \Scout\Rent\Cli\RentScout} for the ranks and why *not yet in the
 * seen-set* outranks everything. It is an ORDERING, never a gate: ordering on card-complete fields
 * decides only WHEN a page is fetched, never whether a listing is judged on it, so hard rule 8 is
 * untouched.
 *
 * **The detail path adds no `_text`.** On a detail page that would be the whole page, whose
 * furniture conflicts a correct verdict into UNKNOWN — measured on Cityloger's Antony listing, whose
 * own `.description` classifies LLI 0.90 while its whole page classifies UNKNOWN 0.00. A detail map
 * addresses the LISTING, never the page, and that is enforced structurally here rather than asked
 * for in a comment.
 */
final readonly class DetailHydrator
{
    /**
     * How many times a detail page may fail before it is left alone.
     *
     * Three, not one: a 503 during a deploy is not a dead page. Not unlimited, because the failure
     * that matters is the permanent one — a listing whose page has been removed while the card
     * lingers — and retrying that every pass for ever is a slow crawl aimed at a 404.
     */
    public const int DETAIL_ATTEMPT_CAP = 3;

    /** Hours between retries of a failed detail page. Long enough that a bad afternoon passes. */
    public const int DETAIL_RETRY_BACKOFF_HOURS = 6;

    public function __construct(
        private SourceDefinition $definition,
        private Store $store,
        private HttpClient $client,
        /**
         * REQUIRED, and deliberately not nullable — the {@see HtmlSource} constructor's own note
         * applies verbatim. A `null` here does not mean *"check later"*, it means *"never check"*,
         * silently, on every real poll, which is how hard rule 5 went unenforced for months. A
         * caller with no verdict passes a fail-closed one from {@see \Scout\Adapters\Http\RobotsResolver}.
         */
        private Robots $robots,
        /**
         * Query parameters added to every detail request, or `[]` for none.
         *
         * EXPLICIT rather than read off `$definition->params`, because that field means two
         * different things by source type: on `html`/`json` it is the search query, on `email_alert`
         * it is the adapter's own configuration (`from`, `card_separator`, `link_host`, the
         * positional patterns). Reading it here would put an email source's configuration into a
         * third-party URL's query string, which is both wrong and a small disclosure. `HtmlSource`
         * passes its search params so its behaviour is unchanged; an email source passes nothing,
         * a published listing URL being complete as it stands.
         *
         * @var array<string,string>
         */
        private array $detailQuery = [],
        /** @param ?\Closure(RawListing): int $detailPriority lower ranks first; ties keep source order. */
        private ?\Closure $detailPriority = null,
        /**
         * A FIXED clock for tests, per the convention {@see \Scout\Rent\Cli\RentScout} already uses.
         * The cache records when each attempt happened and the retry backoff reads it back — a
         * backoff measured by SQL `now()` cannot be tested, and an untested backoff is how a dead
         * page gets re-fetched every fifteen minutes for ever.
         */
        private ?string $nowIso = null,
        /**
         * The SOURCE's miss log, so a detail-map field that stops extracting is reported against
         * the source rather than against this collaborator — Track 6-A3. Optional, because a
         * hydrator built without one must behave exactly as before.
         */
        private ?PatternMissLog $patternMisses = null,
        /**
         * THE PACING SEAM, injected for the same reason {@see \Scout\Core\Pacer} injects one: the
         * guarantee is that a sleep is REQUESTED, and wall-clock cannot assert that.
         *
         * `HtmlSourceDetailTest` measured elapsed time against a 50 ms floor, which a slow machine
         * satisfies with the `usleep` deleted — so detection depended on the runner being fast. It
         * flipped on 2026-09-06: the case detected at `3422225` and reported `undetected` at
         * `59b413e` on a shard that took two hours against its siblings' seventy minutes. A
         * lower-bound timing assertion cannot distinguish "it waited" from "everything was slow".
         *
         * `null` keeps the real `usleep`, so production is unchanged.
         *
         * @var ?\Closure(int): void $sleeper microseconds, as `usleep` takes them
         */
        private ?\Closure $sleeper = null,
    ) {}

    private function name(): string
    {
        return $this->definition->name;
    }

    /**
     * Fetch each owed listing's own page and merge what only that page knows.
     *
     * No detail map means no second request, and that is the whole cost model: a source without one
     * behaves exactly as before, and a source with one spends requests only on listings that are new
     * to it.
     *
     * @param list<RawListing> $listings
     *
     * @return list<RawListing>
     *
     * @throws SourceError
     */
    public function hydrate(array $listings): array
    {
        $detailMap = $this->definition->detailMap;

        if ($detailMap === null) {
            return $listings;
        }

        $now = $this->now();
        $owed = [];
        $out = [];

        // Pass one spends NO requests. It answers, per listing, "is the page already on record?" —
        // and a hit is merged here, which is the whole point of the cache: steady state is zero
        // extra requests, and only a genuinely new listing costs one.
        foreach ($listings as $index => $listing) {
            $cached = $this->store->detail($this->name(), $listing->externalId, $detailMap->fingerprint());

            if ($cached !== null && $cached->fields !== null) {
                $out[$index] = $this->mergeDetail($listing, $detailMap, $cached->fields);

                continue;
            }

            $out[$index] = $listing;

            if ($this->mayAttempt($cached, $now)) {
                $owed[$index] = $listing;
            }
        }

        // Pass two spends the budget, and ORDER is load-bearing. Ranked by the caller, whose first
        // rank is "not yet in the seen-set": a listing about to be notified must never lose its
        // slot to backlog, because by the time backlog's slot comes round the new one has already
        // been notified unhydrated and hydrating it then buys nothing.
        $budget = $this->definition->detailBudgetPerPass;
        $spent = 0;

        foreach ($this->rankedForHydration($owed) as $index => $listing) {
            if ($spent >= $budget) {
                break;
            }

            // Counted BEFORE the attempt, so a failure spends a slot too. Counting successes
            // instead lets a pass full of dead pages issue requests without limit, hunting — which
            // is the crawl this budget exists to prevent, wearing a retry for a costume.
            ++$spent;
            $out[$index] = $this->withDetail($listing, $detailMap, $now);
        }

        ksort($out);

        return array_values($out);
    }

    /**
     * Is this listing owed a request at all?
     *
     * Three states, and they are not the same: never attempted (fetch), attempted and failed within
     * the cap and past the backoff (retry), attempted and failed too often or too recently (leave
     * it). A hydrated row never reaches here — it was merged without a request.
     */
    private function mayAttempt(?StoredDetail $cached, string $nowIso): bool
    {
        if ($cached === null) {
            return true;
        }

        if ($cached->attempts >= self::DETAIL_ATTEMPT_CAP) {
            return false;
        }

        $last = $cached->lastAttemptAt;

        if ($last === null) {
            return true;
        }

        try {
            $since = new \DateTimeImmutable($last);
            $now = new \DateTimeImmutable($nowIso);
        } catch (\Exception) {
            // An undateable stamp is treated as due rather than as permanently blocked. The bias is
            // one redundant request, never a listing silently frozen out of hydration by a row
            // nobody can read — same choice `Store::upgradeFrom()` makes for an undateable sighting.
            return true;
        }

        return $now->getTimestamp() - $since->getTimestamp() >= self::DETAIL_RETRY_BACKOFF_HOURS * 3600;
    }

    /**
     * The candidates, best first.
     *
     * A stable sort, deliberately: within a rank the source's own order is the fairest thing there
     * is, and an unstable sort would make which listing gets hydrated depend on PHP's internals.
     *
     * @param array<int,RawListing> $owed
     *
     * @return array<int,RawListing>
     */
    private function rankedForHydration(array $owed): array
    {
        if ($this->detailPriority === null || $owed === []) {
            return $owed;
        }

        $ranked = [];

        foreach ($owed as $index => $listing) {
            $ranked[] = [($this->detailPriority)($listing), $index, $listing];
        }

        usort($ranked, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        $out = [];

        foreach ($ranked as [, $index, $listing]) {
            $out[$index] = $listing;
        }

        return $out;
    }

    /** The convention {@see \Scout\Rent\Cli\RentScout::nowIso()} uses: injected for tests, real otherwise. */
    private function now(): string
    {
        return $this->nowIso ?? (new \DateTimeImmutable())->format('Y-m-d\TH:i:sP');
    }

    /**
     * One listing, one extra request, merged under hard rule 9.
     *
     * **WHAT THROWS AND WHAT IS RECORDED — the taxonomy, because this is hard rule 3 territory and
     * the wrong split is a defect either way.**
     *
     * A CONFIG-SHAPED failure throws: robots refusing the detail path, or a card with no url. Those
     * are STATES, not events — every hydration on the source would fail for the same reason, for
     * ever — so recording them per listing would be pretending to try. They mean the `detail_map`
     * is unusable and someone must be told.
     *
     * A PER-LISTING RUNTIME failure is recorded and the pass continues: an HTTP failure, an
     * unparseable page. This USED to throw, on the argument that returning the listing unhydrated
     * converts a broken fetch into a listing that merely looks tenure-less. That argument was right
     * about silence and wrong about blast radius: a throw here voids the ENTIRE pass, so one
     * permanently-404ing detail page meant the source returned nothing, recorded nothing as seen,
     * and never notified a genuinely new listing again — while its health reported SOURCE_BROKEN
     * with a diagnosis that was simply untrue, since the source was fine and one page was gone.
     * Over-rejection at source scale plus a misleading alert, which is two of this project's three
     * named failure modes at once.
     *
     * Recording is not silence, and that distinction is the whole justification: the failure is
     * persisted with its attempt count and its (redacted) message, {@see Store::detailFailureCount}
     * counts it, and health surfaces the pattern that matters — not one dead page, but fifty, which
     * is a landlord who changed their detail markup.
     *
     * Stated cost: a listing whose detail page cannot be read is judged on its card alone, exactly
     * as every listing on this source is judged today. `exclude_title_patterns` cannot fire on it.
     *
     * @throws SourceError on a config-shaped failure only
     */
    private function withDetail(RawListing $listing, FieldMap $detailMap, string $atIso): RawListing
    {
        $url = $listing->url;

        if ($url === null || $url === '') {
            throw new SourceError(
                $this->name(),
                'listing ' . $listing->externalId . ' passed the detail gate but carries no url, so '
                    . 'its detail page cannot be fetched — the card\'s `url` mapping is wrong',
            );
        }

        // Its own robots verdict, because a detail page is a different path from the search index.
        // A site may publish a search it welcomes and listing pages it does not, and a check made
        // once on the index would walk into every one of them returning 200 (hard rule 5).
        if (!$this->robots->allows(Robots::pathOf($url))) {
            throw new SourceError(
                $this->name(),
                $this->robots->refusal(Robots::pathOf($url)) . ' — the search index is pollable '
                    . 'but the listing pages are not, so this source cannot be hydrated',
            );
        }

        if ($this->definition->rateLimitMs > 0) {
            ($this->sleeper ?? static fn (int $us): mixed => usleep($us))($this->definition->rateLimitMs * 1000);
        }

        try {
            $body = $this->get($url);
            $flat = $this->detailFields($body, $detailMap);
        } catch (SourceError $e) {
            // Redacted here rather than in the store, because a detail-fetch failure carries the
            // url it failed on and this is the last place that url is a live string.
            $this->store->recordDetailFailure(
                $this->name(),
                $listing->externalId,
                Redact::text($e->getMessage()),
                $atIso,
            );

            return $listing;
        }

        // The RAW extracted strings go to the store, and the MAPPER runs on the way out — on this
        // path and on the cache-hit path alike, from one place. Storing mapped values instead would
        // freeze today's ListingMapper into every row, and a later fix to how `RDC` is read would
        // never reach a listing captured before it.
        $this->store->recordDetail(
            $this->name(),
            $listing->externalId,
            $url,
            $flat,
            $atIso,
            $detailMap->fingerprint(),
        );

        return $this->mergeDetail($listing, $detailMap, $flat);
    }

    /**
     * Raw extracted strings + the card = the hydrated listing.
     *
     * The single funnel, used by the fetch path and the cache-hit path, so a merge cannot behave
     * one way on the pass that fetched a page and another on every pass after it — which is the
     * shape of bug the cache exists to prevent, reintroduced inside the cache.
     *
     * Through the MAPPER, not assigned raw: a detail page's rent, floor and surface are the same
     * prose the card's are, and hard rule 9 lives in `ListingMapper`/`Payload`. A second,
     * hand-rolled conversion here would be a second place for `RDC` to stop meaning zero.
     *
     * @param array<string,string> $flat
     */
    private function mergeDetail(RawListing $listing, FieldMap $detailMap, array $flat): RawListing
    {
        // `detail.` so a detail-map miss is counted SEPARATELY from the card map's field of the
        // same name — see {@see ListingMapper}'s `$missPrefix` for the live pass that proved pooling
        // them hides a whole dead map.
        $mapper = new ListingMapper($this->literalKeyed($detailMap), $this->patternMisses, 'detail.');
        $flat['ref'] = $listing->externalId;

        return $listing->mergedWith($mapper->map($flat));
    }

    /**
     * The detail map, resolved against the detail document.
     *
     * Deliberately NOT the card path's extraction: that one adds `_text` — every word of the element
     * it is given — which on a whole page is the furniture that conflicts a correct verdict into
     * UNKNOWN. Here only the configured selectors are read, so a detail map that addresses the
     * listing's own block contributes the listing's own words and nothing else.
     *
     * @return array<string, string>
     *
     * @throws SourceError
     */
    private function detailFields(string $html, FieldMap $detailMap): array
    {
        try {
            $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
        } catch (\Throwable $e) {
            throw new SourceError($this->name(), 'detail page could not be parsed as HTML: ' . $e->getMessage(), $e);
        }

        $root = $document->documentElement;
        if ($root === null) {
            throw new SourceError($this->name(), 'detail page parsed to an empty document');
        }

        $out = [];
        foreach (FieldMap::FIELDS as $field) {
            /** @var list<string> $entries */
            $entries = $detailMap->{$field};
            foreach ($entries as $entry) {
                $value = Selector::parse($entry)->resolve($root);
                if ($value !== null) {
                    $out[$field] = $value;
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * The detail map rewritten to literal keys, so {@see ListingMapper} can read the flat array.
     *
     * {@see detailFields()} has already done the selecting, so the mapper is handed paths that are
     * just field names.
     *
     * **Only the three fields the mapper actually reads are carried across** — `name`, `baseUrl` and
     * `map`, verified by grep over `ListingMapper` — plus the four `SourceDefinition` constructor
     * requirements. Its predecessor copied fifteen, none of which the mapper consults; copying a
     * field nobody reads is how a value ends up believed to be in force somewhere it never was.
     *
     * `chargesIncluded` IS carried, inside the map: it is a declaration about what `rent` MEANS
     * rather than a path, and dropping it would make the mapper file a charges-comprises rent as
     * hors charges for a whole source.
     *
     * NO `_text` fallback for `description`, and that is the point rather than an omission — see
     * this class's own note on why a detail map may not contribute the page.
     */
    private function literalKeyed(FieldMap $detailMap): SourceDefinition
    {
        $literal = [];
        foreach (FieldMap::FIELDS as $field) {
            $literal[$field] = $detailMap->{$field} === [] ? [] : [$field];
        }

        // The mapper refuses a listing with no `ref`, and it is right to: without a stable id every
        // run re-notifies everything. A detail map has no ref of its own and must not have one —
        // identity belongs to the card — so the caller supplies the card's id under this key and
        // the guarantee stays intact rather than being switched off for detail pages.
        $literal['ref'] = ['ref'];

        return new SourceDefinition(
            name: $this->definition->name,
            enabled: $this->definition->enabled,
            family: $this->definition->family,
            type: $this->definition->type,
            mixedTenure: $this->definition->mixedTenure,
            baseUrl: $this->definition->baseUrl,
            map: new FieldMap(
                ref: $literal['ref'],
                title: $literal['title'],
                url: $literal['url'],
                commune: $literal['commune'],
                postcode: $literal['postcode'],
                rent: $literal['rent'],
                rentHc: $literal['rentHc'],
                charges: $literal['charges'],
                surface: $literal['surface'],
                rooms: $literal['rooms'],
                bedrooms: $literal['bedrooms'],
                floor: $literal['floor'],
                elevator: $literal['elevator'],
                description: $literal['description'],
                tenureField: $literal['tenureField'],
                chargesIncluded: $detailMap->chargesIncluded,
            ),
        );
    }

    /**
     * One request for one detail page.
     *
     * @throws SourceError
     */
    private function get(string $url): string
    {
        $request = (new HttpRequest(
            url: $url,
            method: $this->definition->method,
            headers: $this->definition->headers,
            body: $this->definition->body,
        ))->withQuery($this->detailQuery);

        try {
            $response = $this->client->send($request);
        } catch (HttpError $e) {
            throw new SourceError($this->name(), $e->getMessage(), $e);
        }

        if (!$response->isSuccess()) {
            throw new SourceError(
                $this->name(),
                'HTTP ' . $response->status . ' from ' . $url
                    . ($response->status === 403 ? ' — this source blocks plain clients; use the email-alert route' : ''),
            );
        }

        return $response->body;
    }
}
