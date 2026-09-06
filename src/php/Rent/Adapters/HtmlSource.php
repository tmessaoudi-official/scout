<?php

declare(strict_types=1);

namespace Scout\Rent\Adapters;

use Scout\Core\CountsPatternMisses;
use Scout\Core\PatternMissLog;
use Dom\Element;
use Dom\HTMLDocument;
use Scout\Rent\Adapters\Html\Selector;
use Scout\Adapters\Http\HttpClient;
use Scout\Adapters\Http\HttpError;
use Scout\Adapters\Http\HttpRequest;
use Scout\Adapters\Http\Robots;
use Scout\Rent\Config\FieldMap;
use Scout\Rent\Config\SourceDefinition;
use Scout\Rent\Core\RawListing;
use Scout\Core\SourceHealth;
use Scout\Rent\Core\SourceProfile;
use Scout\Rent\Core\Tenure;
use Scout\Rent\Store\Store;
use Scout\Adapters\SourceError;

/**
 * Polls a server-rendered search page and maps its listing cards with the source's field map.
 *
 * The twin of {@see HttpJsonSource}, and deliberately its mirror image: same url checks, same
 * `robots.txt` refusal, same honest User-Agent, same rule that a failure THROWS rather than
 * returning an empty list. What differs is one step in the middle — the payload is parsed as HTML
 * and the field map is read as CSS selectors ({@see Selector}) instead of dotted paths.
 *
 * **No hand-written selector engine, and that was not the original plan.** PHP 8.4 introduced
 * `Dom\HTMLDocument`, a spec-compliant HTML5 parser with `querySelectorAll`, and 8.5 ships it here.
 * It handles the unclosed `<li>` and the bare `&euro;` that real pages are full of, which a
 * regex-and-hope parser does not — and it is the language's own, so there is nothing between the
 * markup and the boolean that arms §1.
 *
 * **The extraction still funnels through {@see ListingMapper}.** Every element is resolved into a
 * plain array keyed by field name, and the mapper runs over that unchanged. That is the whole
 * reason there is no second implementation of hard rule 9 here: `null` is not zero, an empty string
 * is not a value, and a missing `ref` is a loud source failure — all of it decided in one place for
 * both adapters. An HTML-specific mapper would have had to re-derive every one of those.
 *
 * **It paginates, three ways, and exactly one per source:** `page_param` (a query parameter),
 * `page_path` (a suffix appended to the url) or a `{page}` template in the url itself, for a site
 * whose page number sits mid-path. Configuring two is refused at load AND here, because whichever
 * one the adapter picks, the ignored mechanism fails silently. A walk ends on the site's own
 * declared total where it publishes one, on an empty page otherwise, and on `maxPages` as a
 * FAILURE — a page that quietly 404s or redirects to page one otherwise terminates a walk exactly
 * like a genuine last page.
 *
 * **It follows a card to its detail page only when told to, and only for listings that matter.**
 * A `detail_map` costs one request PER LISTING, which is the polling burst Q37 pacing exists to
 * prevent, so hydration runs behind a gate the CALLER supplies — the run's own geographic criteria
 * — and a `detail_map` with no gate is refused rather than defaulted either way. Two things about
 * that path are load-bearing rather than incidental: it does not add `_text` (on a detail page that
 * would be the whole page, whose furniture conflicts a correct verdict into UNKNOWN), and a detail
 * value never erases what the card knew (hard rule 9, in {@see RawListing::mergedWith()}).
 *
 * **What it still does NOT do, stated so it is not mistaken for a bug:** nothing here reads a
 * source's own filters. Fields a source publishes only behind a form — `bedrooms` on every source
 * so far — stay `null`, which Q5 and hard rule 9 already account for: unknown is not zero, and the
 * criteria engine must not disqualify on it.
 */
final readonly class HtmlSource implements CountsPatternMisses, Source
{
    /**
     * @param ?\Closure(RawListing): int $detailPriority which listings go FIRST when the per-pass
     *        budget cannot cover them all. Lower ranks first; ties keep source order. Supplied by
     *        the caller rather than configured, because the ranking is the run's own state — see
     *        {@see \Scout\Rent\Cli\RentScout} for the ranks and why *not yet seen* outranks everything.
     *
     *        It is an ORDERING, no longer a gate. Novelty is the gate, and it lives in the cache: a
     *        listing whose detail page is already on record costs no request at all. Ordering on
     *        card-complete fields decides only WHEN a page is fetched, never whether a listing is
     *        judged on it, so hard rule 8 is untouched.
     *
     *        `null` means every candidate ranks equally and the budget is spent in source order.
     */
    public function __construct(
        private SourceDefinition $definition,
        private Store $store,
        private HttpClient $client,
        /**
         * REQUIRED, and deliberately not nullable. It was `?Robots $robots = null` until
         * 2026-08-21, and that default is the whole reason hard rule 5 went unenforced for months:
         * a `null` here does not mean *"check later"*, it means *"never check"*, silently, on every
         * real poll — and both production construction sites took the default. A caller that has no
         * verdict must pass a fail-closed one from {@see RobotsResolver}, or an explicit
         * `Robots::parse('')` to say in as many words that this call site is not about robots.
         */
        private Robots $robots,
        private ?\Closure $detailPriority = null,
        /**
         * A FIXED clock for tests, per the convention {@see \Scout\Rent\Cli\RentScout} already uses.
         * The hydration cache records when each attempt happened, and the retry backoff reads it
         * back — a backoff measured by SQL `now()` cannot be tested, and an untested backoff is how
         * a dead page gets re-fetched every fifteen minutes for ever.
         */
        private ?string $nowIso = null,
        /**
         * How often each CONFIGURED map field extracted nothing this pass — Track 6-A3 / F27b.
         * Mutable object behind a readonly property, the same shape {@see EmailAlertSource} uses
         * and for the same reason: a `final readonly` adapter cannot otherwise accumulate anything.
         *
         * SHARED WITH THE HYDRATOR below, deliberately. A detail-map field that stops extracting is
         * a fault of THIS SOURCE — that is what the operator needs told, and what `doctor` prints
         * per source — so the two maps report into one log rather than into a collaborator nobody
         * queries.
         */
        private PatternMissLog $patternMisses = new PatternMissLog(),
        /**
         * Forwarded to {@see DetailHydrator} — see its own note. NOT applied to this class's own
         * page-walk `usleep` below, which has no sabotage case and no test at all; that is a known
         * gap, recorded rather than half-closed here.
         *
         * @var ?\Closure(int): void $sleeper
         */
        private ?\Closure $sleeper = null,
    ) {
        // Built here rather than injected, because this class already holds every dependency it
        // needs — so injecting one would add a construction site to every caller and every test in
        // exchange for nothing. {@see EmailAlertSource} takes the opposite route for the opposite
        // reason: it holds NONE of them, and giving it an HttpClient and a Robots verdict to build
        // its own would teach a mailbox reader that the web exists.
        $this->hydrator = new DetailHydrator(
            $definition,
            $store,
            $client,
            $robots,
            // The source's SEARCH params, carried so this refactor changes nothing: In'li's detail
            // requests have always gone out with `price_max`/`area_min`/`room_min` attached. They
            // are meaningless on a detail page and harmless there, and dropping them would be a
            // live behaviour change wearing an extraction's clothes.
            $definition->params,
            $detailPriority,
            $nowIso,
            $this->patternMisses,
            $sleeper,
        );
    }

    /** The per-field miss counts of the last fetch, card map and detail map alike — `doctor` prints them. */
    public function patternMisses(): PatternMissLog
    {
        return $this->patternMisses;
    }

    /**
     * The detail-page collaborator, extracted 2026-09-01 so `EmailAlertSource` can compose the same
     * one. A hydrated description is what the tenure classifier reads, and a §1-adjacent path gets
     * exactly one implementation here — see {@see DetailHydrator} for every guarantee it carries.
     */
    private DetailHydrator $hydrator;

    public function name(): string
    {
        return $this->definition->name;
    }

    /** Tolerant by design, for the same reason {@see HttpJsonSource::host()} is — see its note. */
    public function host(): ?string
    {
        $url = $this->definition->url;
        if ($url === null || $url === '' || str_contains($url, 'REMPLACER')) {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }

    public function family(): string
    {
        return $this->definition->family === 'private' ? 'private' : 'institutional';
    }

    public function defaultTenure(): ?Tenure
    {
        return $this->definition->defaultTenure;
    }

    public function profile(): SourceProfile
    {
        return $this->definition->profile();
    }

    /**
     * A CONFIGURED SELECTOR THAT MATCHED NOTHING AT ALL IS A TEMPLATE CHANGE, and until 2026-09-02
     * this method could not say so: it counted misses through `ListingMapper` and then returned the
     * store's verdict untouched, so a field map going 100 % null produced no status change, no
     * `isAlerting()` and no alert — only a `doctor` printout (C2 round-1 resilience lens). In'li's
     * card `cp` went 171/171 dead on the deployed image exactly like that, and a human found it.
     *
     * `PatternMissLog::escalate()` is the one implementation, shared with the email adapters and
     * both domains.
     */
    public function health(?string $nowIso = null): SourceHealth
    {
        return $this->patternMisses->escalate($this->store->health($this->name(), $nowIso));
    }

    /**
     * @return list<\Scout\Rent\Core\RawListing>
     *
     * @throws SourceError
     */
    public function fetch(): array
    {
        // A COUNT NEVER SPANS TWO FETCHES, and this line is the second half of F-R1. `RentScout`
        // builds its sources ONCE and the watch loop closes over them, so an adapter — and its log —
        // lives for the whole process. Without the reset a template already fixed keeps warning for
        // ever, which sends an operator to read a capture that is fine and teaches them to ignore
        // the signal. That failure is worse than silence because it is credible. It is also the
        // contract `CountsPatternMisses` states outright: implementing it promises this call.
        $this->patternMisses->reset();

        $url = $this->definition->url;

        if ($url === null || $url === '') {
            throw new SourceError($this->name(), 'no url configured');
        }

        if (str_contains($url, 'REMPLACER')) {
            throw new SourceError(
                $this->name(),
                'the url is still the REMPLACER placeholder. CLAUDE.md hard rule 1: verify the '
                    . 'endpoint against the live site before enabling this source',
            );
        }

        $selector = $this->definition->itemSelector;
        if ($selector === null || trim($selector) === '') {
            throw new SourceError(
                $this->name(),
                'no item_selector configured — an html source cannot know which element is a listing',
            );
        }

        if (!$this->robots->allows(Robots::pathOf($url))) {
            throw new SourceError(
                $this->name(),
                $this->robots->refusal(Robots::pathOf($url)) . ' — this source must not be polled. '
                    . 'Use the email-alert route instead',
            );
        }

        $pageParam = $this->definition->pageParam;
        $pagePath = $this->definition->pagePath;

        // A `{page}` in the URL ITSELF, for a site whose page number is not a suffix. Cityloger
        // paginates through `resultats-location-{page}-defaut-`, where the number sits in the middle
        // of the path — which `page_path` cannot express, because it appends.
        //
        // The rejected alternative is worth recording: point `url` at the site root and let page one
        // be the homepage, whose listing widget holds the same ten cards today [verified 2026-08-20,
        // ids identical]. That equality is not a guarantee. The day the widget becomes "featured"
        // rather than ranks 1-10, page one's listings are simply never fetched, and a source that
        // silently drops its first page looks exactly like a smaller market (hard rule 2).
        $urlTemplate = str_contains($url, '{page}');

        if ($urlTemplate && ($pageParam !== null || $pagePath !== null)) {
            throw new SourceError(
                $this->name(),
                'the url carries a {page} template AND ' . ($pageParam !== null ? 'page_param' : 'page_path')
                    . ' is configured — a source paginates one way or the other, and guessing which '
                    . 'one the site honours is how a walk silently refetches page one',
            );
        }

        if ($pageParam !== null && $pagePath !== null) {
            throw new SourceError(
                $this->name(),
                'both page_param and page_path are configured — a source paginates one way or the '
                    . 'other, and guessing which one the site honours is how a walk silently refetches '
                    . 'page one',
            );
        }

        if ($pagePath !== null && !str_contains($pagePath, '{page}')) {
            throw new SourceError(
                $this->name(),
                'page_path `' . $pagePath . '` contains no {page} placeholder, so every page after '
                    . 'the first would request the same url — a walk that never advances and never ends',
            );
        }

        $firstUrl = $urlTemplate ? str_replace('{page}', '1', $url) : $url;

        if ($urlTemplate && !$this->robots->allows(Robots::pathOf($firstUrl))) {
            throw new SourceError(
                $this->name(),
                $this->robots->refusal(Robots::pathOf($firstUrl)) . ' — this source must not be '
                    . 'polled. Use the email-alert route instead',
            );
        }

        $body = $this->get($firstUrl, []);
        $out = $this->extract($body, $selector);

        if (!$urlTemplate && $pageParam === null && $pagePath === null) {
            return $this->hydrator->hydrate($out);
        }

        // ── walk the remaining pages ──────────────────────────────────────────────────────────
        //
        // Terminated three ways, and only one of them is success. A page that yields zero listings
        // ends the walk; `maxPages` ends it as a FAILURE; and the declared total is what decides
        // whether the walk that ended "naturally" actually reached the end. Walking until a page
        // comes back empty is a termination rule, not a correctness proof — a `page=3` that quietly
        // 404s or redirects to page one terminates exactly like a genuine last page.
        $total = $this->declaredTotal($body);
        $page = 1;

        // Set by either LEGITIMATE terminator — a page with no cards, or the site's own count being
        // reached. Without it the bound check below cannot tell a walk that FINISHED on its last
        // permitted page from one that ran out of road, and throws on both.
        //
        // CDC Habitat walked into exactly that on 2026-08-31 and the source reported `broken / 0
        // annonces` for a day: 315 declared listings at 16 a page is 19.7 pages, so the walk
        // completed correctly ON page 20 with the bound at 20, hit `count($out) >= $total`, broke —
        // and was then thrown away by `$page >= maxPages`. The message it threw named a cause that
        // was not true ("the path template is probably ignored by the site, so every page returns
        // the first one"): the template worked, the pages differed, the selectors matched. A true
        // symptom with an invented cause, which is this repo's own named failure and is worse than
        // no message, because it sends the next reader to check the one thing that is fine.
        $finished = false;

        // Set only by a SHORT final page — see below. It is the difference between "the walk ended"
        // and "the walk ended AND nothing was lost", which is what the declared-total assertion
        // needs to know.
        $reachedLastPage = false;

        // The first page's card count is the page size every later page is measured against.
        $pageSize = count($out) > 0 ? count($out) : null;

        while ($page < $this->definition->maxPages) {
            ++$page;

            // Between pages, because these requests never pass through `PacedSource` — it wraps one
            // `fetch()`, and every page after the first is inside it. Without this a four-page walk
            // is four requests back to back, which is the burst hard rule 5 forbids.
            if ($this->definition->rateLimitMs > 0) {
                usleep($this->definition->rateLimitMs * 1000);
            }

            if ($urlTemplate || $pagePath !== null) {
                $pageUrl = $urlTemplate
                    ? str_replace('{page}', (string) $page, $url)
                    : $url . str_replace('{page}', (string) $page, $pagePath);

                // Re-checked per page, because with path pagination the PATH is what changes. A
                // site may publish an index it welcomes and paginated forms it does not, and a
                // one-shot check on the index would walk into those with every request returning
                // 200 — a hard rule 5 breach that is invisible from the outside.
                if (!$this->robots->allows(Robots::pathOf($pageUrl))) {
                    throw new SourceError(
                        $this->name(),
                        $this->robots->refusal(Robots::pathOf($pageUrl)) . ' — the index is '
                            . 'pollable but its paginated form is not, so this walk must stop here',
                    );
                }

                $pageBody = $this->get($pageUrl, []);
            } else {
                $pageBody = $this->get($url, [$pageParam => (string) $page]);
            }

            try {
                $rows = $this->extract($pageBody, $selector);
            } catch (SourceError) {
                // A page past the last one legitimately has no cards. Only the FIRST page's
                // emptiness is a breakage — that one is rethrown above, before this loop starts.
                $finished = true;
                break;
            }

            // A SHORT PAGE IS THE END OF THE WALK, and it is PROOF of it in a way the declared
            // total is not. A site whose last page is partial never satisfies `count >= total`, so
            // without this the walk runs to the bound and is thrown away — which is what CDC
            // Habitat does: 20 pages of 16 except the last, which serves 8.
            // ONLY WHERE A TOTAL IS DECLARED, and that restriction is the safety. A short page is
            // the last page on every site seen so far, but a site could serve a short page in the
            // MIDDLE — filtered cards, a partial render — and stopping there would truncate
            // silently, which is the one failure this adapter exists to prevent. With a declared
            // total the assertion below verifies the shortcut; without one nothing could, so the
            // walk keeps its existing empty-page terminator and behaves exactly as before.
            if ($total !== null && $pageSize !== null && count($rows) > 0 && count($rows) < $pageSize) {
                $out = [...$out, ...$rows];
                $finished = true;
                $reachedLastPage = true;
                break;
            }

            $out = [...$out, ...$rows];

            // THE SITE'S OWN COUNT IS THE TERMINATOR, when it gives one.
            //
            // The alternative — walk until a page comes back empty — assumes a page past the end
            // comes back empty. CDC Habitat's does not: page 11 of a 9-page result set answers 301
            // [measured 2026-08-20], and this adapter refuses a non-2xx deliberately, because a
            // redirect that lands back on page one ends a walk exactly like a genuine last page.
            // The probe therefore turned a complete, correct walk into `broken / 0 items`.
            //
            // This is not new trust in the total: it is already the assertion the check below
            // makes. Using it to STOP as well as to VERIFY also costs one fewer request per pass
            // against somebody else's server, which is hard rule 5's direction anyway. With no
            // declared total there is nothing to stop on, and the empty-page probe still applies.
            if ($total !== null && count($out) >= $total) {
                $finished = true;
                break;
            }
        }

        if (!$finished && $page >= $this->definition->maxPages) {
            throw new SourceError(
                $this->name(),
                'pagination reached the ' . $this->definition->maxPages . '-page bound without ending — '
                    . 'the `' . ($pageParam ?? $pagePath ?? $url) . '` ' . ($pageParam !== null ? 'parameter' : 'path template')
                    . ' is probably ignored by the site, so every page returns the first one. '
                    . 'Refusing to keep requesting',
            );
        }

        // A SHORTFALL UNDER ONE PAGE IS THE SITE'S OWN COUNTER BEING OFF, NOT A LOST PAGE — but only
        // once a short final page has PROVEN the walk reached the end. CDC Habitat declares 315 and
        // serves 312; demanding `count >= declared` made it permanently `broken`, and raising the
        // bound would not have helped because the shortfall is in the site's arithmetic, not in the
        // walk. What the assertion exists to catch is a LOST PAGE, and a page is 16 listings here,
        // so anything short of one page is tolerated and anything from one page up still throws.
        $tolerance = $reachedLastPage && $pageSize !== null ? $pageSize - 1 : 0;

        if ($total !== null && count($out) + $tolerance < $total) {
            // The assertion the walk exists to make. Fewer listings than the site itself declares
            // means pages were lost, and a run that quietly reports 24 of 92 looks exactly like a
            // thin market — forever, and with a healthy source badge on it.
            //
            // Strict `<` is deliberate, and its cost is stated: listings can genuinely appear
            // between the first page fetch and the last, so a real change can trip this. That
            // trades a rare loud failure — recovered on the next pass 15 minutes later, and not
            // alerted until three in a row — against permanent silent truncation. Not a close call.
            throw new SourceError(
                $this->name(),
                'collected ' . count($out) . ' listings but the page declares ' . $total
                    . ' — pagination lost results rather than reaching the end',
            );
        }

        return $this->hydrator->hydrate($out);
    }

    /**
     * One request, with the source's own params plus any extra.
     *
     * @param array<string, string> $extra
     *
     * @throws SourceError
     */
    private function get(string $url, array $extra): string
    {
        $request = (new HttpRequest(
            url: $url,
            method: $this->definition->method,
            headers: $this->definition->headers,
            body: $this->definition->body,
        ))->withQuery([...$this->definition->params, ...$extra]);

        try {
            $response = $this->client->send($request);
        } catch (HttpError $e) {
            throw new SourceError($this->name(), $e->getMessage(), $e);
        }

        if (!$response->isSuccess()) {
            // A REDIRECT NAMES WHERE IT POINTS (row 44, 2026-09-05). In'li answered 302 on two of
            // five live passes and the run log held only `HTTP 302 from <url>` — enough to say the
            // source broke, not enough to say whether the page moved, a cookie bounce fired or a
            // shield answered, and four cold probes from the same host all returned 200. The next
            // occurrence must diagnose itself: the `Location` is the whole difference between a
            // config fix and a hard-rule-5 refusal, and it costs nothing to carry.
            $location = $response->status >= 300 && $response->status < 400 ? $response->header('location') : null;
            throw new SourceError(
                $this->name(),
                'HTTP ' . $response->status . ' from ' . $url
                    . ($location !== null && $location !== '' ? ' → Location: ' . $location : '')
                    . ($response->status === 403 ? ' — this source blocks plain clients; use the email-alert route' : ''),
            );
        }

        return $response->body;
    }

    /**
     * The result count the page states about itself, or `null` when the source declares no selector.
     *
     * Read through the same number parser everything else uses, so `92 logements` yields 92 and the
     * fusion trap that `Payload::number()` guards against cannot reappear here in a private copy.
     */
    private function declaredTotal(string $html): ?int
    {
        $selector = $this->definition->totalSelector;
        if ($selector === null || trim($selector) === '') {
            return null;
        }

        try {
            $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
        } catch (\Throwable) {
            return null;
        }

        $root = $document->documentElement;
        if ($root === null) {
            return null;
        }

        $text = Selector::parse($selector)->resolve($root);

        return $text === null ? null : Payload::int(['v' => $text], ['v']);
    }

    /**
     * @return list<\Scout\Rent\Core\RawListing>
     *
     * @throws SourceError
     */
    public function extract(string $html, string $itemSelector): array
    {
        try {
            $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
        } catch (\Throwable $e) {
            throw new SourceError($this->name(), 'response could not be parsed as HTML: ' . $e->getMessage(), $e);
        }

        try {
            $items = $document->querySelectorAll($itemSelector);
        } catch (\Throwable $e) {
            throw new SourceError(
                $this->name(),
                'item_selector `' . $itemSelector . '` is not a valid CSS selector: ' . $e->getMessage(),
                $e,
            );
        }

        if ($items->count() === 0) {
            // THE failure this adapter exists to make loud. A search page that still returns 200
            // and still parses, whose card markup was renamed in a redesign, yields zero items
            // forever — and zero listings is exactly what a quiet rental market looks like. Hard
            // rules 2 and 3: never `[]` to signal breakage.
            //
            // It is also where a WAF interstitial or a JSON error body lands, because the HTML5
            // parser accepts almost any byte sequence rather than refusing it — so the message says
            // so, instead of sending someone to inspect selectors that were never the problem.
            throw new SourceError(
                $this->name(),
                'item_selector `' . $itemSelector . '` matched no element — the page markup changed, '
                    . 'the selector is wrong, or the response was not the search page at all '
                    . '(an anti-bot interstitial parses as HTML without error)',
            );
        }

        $mapper = new ListingMapper($this->flatMapped(), $this->patternMisses);

        $out = [];
        foreach ($items as $item) {
            if (!$item instanceof Element) {
                continue;
            }
            $out[] = $mapper->map($this->itemToArray($item));
        }

        return $out;
    }

    /**
     * One listing element, resolved into the flat array {@see ListingMapper} expects.
     *
     * Three kinds of key, and the last two exist for the classifier rather than for the mapper:
     *
     * - **field names** — `ref`, `rent`, `surface`… each resolved from the source's own selectors,
     *   first non-null wins, mirroring {@see Payload::first()}.
     * - **`_text`** — the whole card, whitespace normalised. It is the classifier's text surface,
     *   and it is included unconditionally because the bias runs one way: §1 says an ambiguous
     *   listing must not be notified, so seeing MORE of the card can only make a `logement social`
     *   label easier to catch. It also serves as the `description` fallback.
     * - **`attr.*` / `data.*`** — every attribute of the card and every `data-*` beneath it. The
     *   classifier reads field NAMES as tier-1 evidence, and in HTML that evidence lives in
     *   attributes: a `data-financement="PLS"` nobody thought to map must still be seen.
     *
     * @return array<string, string>
     */
    private function itemToArray(Element $item): array
    {
        $map = $this->definition->map;
        $out = [];

        foreach (FieldMap::FIELDS as $field) {
            /** @var list<string> $entries */
            $entries = $map->{$field};
            foreach ($entries as $entry) {
                $value = Selector::parse($entry)->resolve($item);
                if ($value !== null) {
                    $out[$field] = $value;
                    break;
                }
            }
        }

        $out['_text'] = Selector::normalise($item->textContent);

        // ONE key shape per attribute, whatever its depth. Every `data-*` goes under `data.`
        // wherever it sits, and the card's other attributes go under `attr.`.
        //
        // The obvious split — the card's own attributes under `attr.`, descendants' `data-*` under
        // `data.` — was what this had first, and a test caught it: `data-financement` on the CARD
        // landed under `attr.` while the identical attribute one element deeper landed under
        // `data.`. The classifier would then see the same tier-1 evidence under two different names
        // according to markup depth, and a rule written for one of them silently misses the other.
        // That is the exact shape `SurfaceMatrixTest` exists to catch: a correct rule applied to a
        // subset of the surfaces it belongs on.
        foreach ($item->attributes as $attribute) {
            if (!str_starts_with($attribute->name, 'data-')) {
                $out['attr.' . $attribute->name] = $attribute->value;
            }
        }

        foreach ([$item, ...iterator_to_array($item->querySelectorAll('*'))] as $element) {
            if (!$element instanceof Element) {
                continue;
            }
            foreach ($element->attributes as $attribute) {
                if (str_starts_with($attribute->name, 'data-') && trim($attribute->value) !== '') {
                    $out['data.' . $attribute->name] = $attribute->value;
                }
            }
        }

        return $out;
    }

    /**
     * The source, with its field map rewritten to literal keys.
     *
     * {@see itemToArray()} has already done the selecting, so the mapper is handed paths that are
     * just field names. `chargesIncluded` is carried across untouched — it is a declaration about
     * what `rent` MEANS, not a path, and dropping it would make the mapper file a charges-comprises
     * rent as hors charges for a whole source.
     *
     * `description` falls back to `_text`: a card with no dedicated description element still owes
     * the classifier its words, and an absent description is the shape that quietly starves signal
     * tier 2. THE DETAIL PATH HAS NO SUCH FALLBACK, and that asymmetry is the whole reason
     * {@see DetailHydrator} keeps its own copy of this rather than sharing a flag: on a detail page
     * `_text` is the entire page, whose furniture conflicts a correct verdict into UNKNOWN.
     */
    private function flatMapped(): SourceDefinition
    {
        $map = $this->definition->map;
        $literal = [];
        foreach (FieldMap::FIELDS as $field) {
            $literal[$field] = $map->{$field} === [] ? [] : [$field];
        }

        return new SourceDefinition(
            name: $this->definition->name,
            enabled: $this->definition->enabled,
            family: $this->definition->family,
            type: $this->definition->type,
            mixedTenure: $this->definition->mixedTenure,
            defaultTenure: $this->definition->defaultTenure,
            url: $this->definition->url,
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
                description: [...$literal['description'], '_text'],
                tenureField: $literal['tenureField'],
                chargesIncluded: $map->chargesIncluded,
            ),
            legalRisk: $this->definition->legalRisk,
            rateLimitMs: $this->definition->rateLimitMs,
        );
    }

}
