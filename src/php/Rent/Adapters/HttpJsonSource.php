<?php

declare(strict_types=1);

namespace Scout\Rent\Adapters;

use Scout\Core\CountsPatternMisses;
use Scout\Core\PatternMissLog;
use Dom\HTMLDocument;
use Scout\Adapters\Http\HttpClient;
use Scout\Adapters\Http\HttpError;
use Scout\Adapters\Http\HttpRequest;
use Scout\Adapters\Http\Robots;
use Scout\Rent\Config\SourceDefinition;
use Scout\Core\SourceHealth;
use Scout\Core\SourceHealthService;
use Scout\Rent\Core\SourceProfile;
use Scout\Rent\Core\Tenure;
use Scout\Rent\Store\Store;
use Scout\Adapters\SourceError;

/**
 * Polls a JSON endpoint and maps it with the source's field map.
 *
 * **THIS ADAPTER IS COMPLETE. WHAT IS MISSING IS A VERIFIED URL**, and those are different things —
 * a distinction I got wrong for a while and it is worth writing down. `CLAUDE.md` hard rule 1
 * forbids writing an ENDPOINT from memory; it says nothing about the transport. So this class exists,
 * is fully tested against a fake client, and shares `ListingMapper` and `Payload` with the fixture
 * adapter — meaning a fixture test exercises the same extraction code a real poll will. Every source
 * in `config/rent/sources.json` stays `enabled: false` with a `REMPLACER` URL until a DevTools capture
 * replaces it, and the config loader refuses the combination of `enabled: true` and a placeholder.
 *
 * Three guarantees this class owes the rest of the system:
 *
 * 1. **It throws; it never returns `[]` to signal failure** (hard rule 3). A transport error, a
 *    non-2xx status, malformed JSON and an absent `items_path` are four distinct failures and all
 *    four are loud.
 * 2. **It checks `robots.txt` before fetching** (hard rule 5), and fails CLOSED when it cannot.
 * 3. **It never disguises itself.** One honest User-Agent, no cookie jar, no proxy, no cross-host
 *    redirect. When a site blocks us the answer is the email-alert route, never a better disguise.
 */
final readonly class HttpJsonSource implements CountsPatternMisses, Source
{
    public function __construct(
        private SourceDefinition $definition,
        private Store $store,
        private HttpClient $client,
        /**
         * Injected rather than fetched inside, so a test can state the site's posture without a
         * network round trip — and so the run loop can cache one `robots.txt` per host per pass
         * instead of re-fetching it for every source on that host.
         *
         * REQUIRED, and deliberately not nullable — see {@see HtmlSource::__construct()} for the
         * defect the old `= null` default caused.
         */
        private Robots $robots,
        /**
         * How often each CONFIGURED map field extracted nothing this pass — Track 6-A3 / F27b.
         * Mutable object behind a readonly property, the shape {@see EmailAlertSource} already uses.
         */
        private PatternMissLog $patternMisses = new PatternMissLog(),
    ) {}

    /** The per-field miss counts of the last fetch — `doctor` prints them. */
    public function patternMisses(): PatternMissLog
    {
        return $this->patternMisses;
    }


    public function name(): string
    {
        return $this->definition->name;
    }

    /**
     * The host `fetch()` will contact, for Q37 pacing.
     *
     * Deliberately tolerant: a URL that is absent, empty or still the `REMPLACER` placeholder yields
     * `null` here rather than throwing. `host()` is called by the pacer BEFORE the fetch, and a
     * throw from the pacing layer would report a misconfigured source as a pacing failure. `fetch()`
     * is where those cases are diagnosed properly, with the hard-rule-1 message they deserve — this
     * method's only job is to answer "which site", and for a source that cannot name one the honest
     * answer is "none yet".
     */
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

        // Belt and braces with the config loader, which already refuses this combination. A second
        // check here costs one comparison and covers a source constructed in code rather than
        // loaded from the file — which is exactly how a test or a future `--dry-run` would build one.
        if (str_contains($url, 'REMPLACER')) {
            throw new SourceError(
                $this->name(),
                'the url is still the REMPLACER placeholder. CLAUDE.md hard rule 1: verify the '
                    . 'endpoint against the live site before enabling this source',
            );
        }

        if (!$this->robots->allows(Robots::pathOf($url))) {
            // Refused, and NEVER worked around. Hard rule 5 has no exception, and the documented
            // alternative for a site that does not want polling is the email-alert route.
            throw new SourceError(
                $this->name(),
                $this->robots->refusal(Robots::pathOf($url)) . ' — this source must not be polled. '
                    . 'Use the email-alert route instead',
            );
        }

        $request = (new HttpRequest(
            url: $url,
            method: $this->definition->method,
            headers: $this->definition->headers,
            body: $this->definition->body,
        ))->withQuery($this->definition->params);

        try {
            $response = $this->client->send($request);
        } catch (HttpError $e) {
            throw new SourceError($this->name(), $e->getMessage(), $e);
        }

        if (!$response->isSuccess()) {
            // A non-2xx is a recorded FAILURE, not an empty result. `docs/SOURCES.md` records five
            // portals that answer 403 to a plain client; that fact has to reach the run log, because
            // it is what says "this source needs the email route" rather than "the market is quiet".
            // A redirect names where it points — the same rule as `HtmlSource` (row 44), so the
            // two adapters diagnose a 3xx the same way.
            $location = $response->status >= 300 && $response->status < 400 ? $response->header('location') : null;
            throw new SourceError(
                $this->name(),
                'HTTP ' . $response->status . ' from ' . $url
                    . ($location !== null && $location !== '' ? ' → Location: ' . $location : '')
                    . ($response->status === 403 ? ' — this source blocks plain clients; use the email-alert route' : ''),
            );
        }

        $payload = $this->payloadIn($response->body);

        try {
            $decoded = json_decode($payload, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new SourceError($this->name(), 'response was not valid JSON: ' . $e->getMessage(), $e);
        }

        return $this->extract($decoded);
    }


    /**
     * The JSON text to parse: the response body, or the text of one element inside it.
     *
     * `embedded_json_selector` is for a page that serves its results as JSON embedded in HTML rather
     * than as an API response — see {@see \Scout\Rent\Config\SourceDefinition::$embeddedJsonSelector}.
     * Everything after this method is the ordinary JSON path, so `items_path`, the field map and
     * `ListingMapper` keep exactly one implementation and hard rule 9 is not re-decided here.
     *
     * **A selector that matches nothing THROWS.** That is the point of the method, not a detail. The
     * likeliest way this source breaks is the site renaming a `data-` attribute, and the payload
     * then simply is not where it was — while the response is still 200 and still a valid page.
     * Returning an empty list there would read as a quiet rental market and stay green for as long
     * as nobody checked, which `CLAUDE.md` names as this codebase's highest-frequency defect class
     * (hard rule 3).
     *
     * @throws SourceError
     */
    private function payloadIn(string $body): string
    {
        $selector = $this->definition->embeddedJsonSelector;

        if ($selector === null || trim($selector) === '') {
            return $body;
        }

        $document = HTMLDocument::createFromString($body, LIBXML_NOERROR);
        $element = $document->querySelector($selector);

        if ($element === null) {
            throw new SourceError(
                $this->name(),
                'embedded_json_selector `' . $selector . '` matched nothing in the response — the '
                    . 'payload has moved or the page changed. Refusing to report an empty result set '
                    . 'for what is a broken selector',
            );
        }

        $text = trim($element->textContent);

        if ($text === '') {
            throw new SourceError(
                $this->name(),
                'embedded_json_selector `' . $selector . '` matched an element with no content — '
                    . 'the same breakage as no match, wearing a match',
            );
        }

        return $text;
    }

    /**
     * A CONFIGURED SELECTOR THAT MATCHED NOTHING AT ALL IS A TEMPLATE CHANGE, and until 2026-09-02
     * this method could not say so: it counted misses through `ListingMapper` and then returned the
     * store's verdict untouched, so a field map going 100 % null produced no status change, no
     * `isAlerting()` and no alert — only a `doctor` printout (C2 round-1 resilience lens). In'li's
     * card `cp` went 171/171 dead on the deployed image exactly like that, and a human found it.
     *
     * `SourceHealthService` is the one place that escalates them (`PatternMissLog::escalate()` over
     * the run log), shared with the email adapters and all three domains.
     */
    public function health(?string $nowIso = null): SourceHealth
    {
        return (new SourceHealthService($this->store->runs()))->of($this->name(), $this->patternMisses, $nowIso);
    }

    /**
     * @return list<\Scout\Rent\Core\RawListing>
     *
     * @throws SourceError
     */
    private function extract(mixed $decoded): array
    {
        $itemsPath = $this->definition->itemsPath;
        $items = $itemsPath === null || $itemsPath === '' ? $decoded : Payload::at($decoded, $itemsPath);

        if ($items === null) {
            // The single commonest way a working adapter stops working: still 200, still valid JSON,
            // but the results moved. Returning `[]` here would read as a quiet market forever.
            throw new SourceError(
                $this->name(),
                'items_path `' . (string) $itemsPath . '` is absent from the response — '
                    . 'the payload shape changed, or the path is wrong',
            );
        }

        if (!is_array($items) || !array_is_list($items)) {
            throw new SourceError(
                $this->name(),
                'items_path `' . (string) $itemsPath . '` did not yield a list of items',
            );
        }

        $mapper = new ListingMapper($this->definition, $this->patternMisses);

        $out = [];
        foreach ($items as $item) {
            $out[] = $mapper->map($item);
        }

        return $out;
    }
}
