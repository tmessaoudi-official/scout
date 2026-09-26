<?php

declare(strict_types=1);

namespace Scout\Car;

use Scout\Adapters\Http\HttpClient;
use Scout\Adapters\Http\HttpRequest;
use Scout\Adapters\Http\HttpResponse;
use Scout\Adapters\Http\Robots;
use Scout\Adapters\SourceError;
use Scout\Core\CountsPatternMisses;
use Scout\Core\PatternMissLog;
use Scout\Core\SourceHealth;

/**
 * A site whose sitemap indexes lot pages carrying a schema.org `Vehicle` block — Autohero.
 *
 * The detail-hydration pattern applied to a whole source: the sitemap is the INDEX (one request,
 * free), and a lot page is fetched only for an id NOT yet in the car seen-set, behind
 * `lot_budget_per_pass`, at `rate_limit_ms`, every URL robots-checked. Cold start is the whole
 * catalogue (~3 400 lots ≈ 68 passes at 50) — which is why `seedIndex()` exists: the seed pass
 * records every currently-published id as seen WITHOUT fetching it, exactly as the rent side's
 * `--seed` treats the market it starts watching. Steady state is the day's new lots.
 *
 * A lot page that fails (non-2xx, no `Vehicle` block) is warned and skipped; it is not recorded,
 * so it is retried next pass within the budget. The one exception is a 3xx to the SAME lot under a
 * renamed model slug, which is followed once (see `sameLotTarget()`). Stated cost: a permanently-broken lot page costs
 * one budget slot per pass until it leaves the sitemap.
 */
final readonly class SitemapVehicleSource implements CountsPatternMisses, IndexedVehicleSource
{
    /** @param ?\Closure(string): void $warn */
    public function __construct(
        private readonly VehicleSourceDefinition $definition,
        private readonly VehicleStore $store,
        private readonly HttpClient $client,
        private readonly Robots $robots,
        private readonly ?\Closure $warn = null,
        private readonly ?\Closure $sleeper = null,
        private readonly IndexSize $lastIndexSize = new IndexSize(),
        private readonly PatternMissLog $patternMisses = new PatternMissLog(),
    ) {}

    public function name(): string
    {
        return $this->definition->name;
    }

    public function family(): string
    {
        return $this->definition->family;
    }

    public function host(): ?string
    {
        return $this->definition->url === null ? null : (parse_url($this->definition->url, PHP_URL_HOST) ?: null);
    }

    /**
     * C-3 — THIS SOURCE EXTRACTED TWELVE CONFIGURED MAP KEYS AND COUNTED NOTHING.
     *
     * `PatternMissLog` reached the two email adapters and then the four rent html/json ones, and
     * this class was the last extraction surface with no instrumentation at all — autohero is
     * `enabled: true`, and a JSON-LD key the reseller renames would go null on every lot with
     * `item_count` unmoved, no run failed and `ok` reported. The gap was stated in no doc, plan or
     * register until the C2 round-1 completeness lens found it.
     */
    public function health(?string $nowIso = null): SourceHealth
    {
        return $this->patternMisses->escalate($this->store->runs()->health($this->name(), $nowIso));
    }

    /** The per-key miss counts of the last fetch — `scout --domain=car doctor` prints them. */
    public function patternMisses(): PatternMissLog
    {
        return $this->patternMisses;
    }

    /**
     * Every lot URL the sitemap lists today, as [externalId => url], in sitemap order.
     *
     * @return array<string, string>
     */
    public function index(): array
    {
        $url = (string) $this->definition->url;
        $this->refuseUnlessAllowed($url);
        $response = $this->client->send(new HttpRequest($url));
        if (!$response->isSuccess()) {
            throw new SourceError($this->name(), sprintf('HTTP %d from %s', $response->status, $url));
        }
        if (preg_match_all('~<loc>\s*([^<\s]+)\s*</loc>~', $response->body, $m) === 0) {
            throw new SourceError($this->name(), 'le sitemap ne liste aucune URL — un index vide se lit comme un marché vide, refusé');
        }

        $pattern = (string) $this->definition->itemUrlPattern;
        $index = [];
        foreach ($m[1] as $loc) {
            $loc = html_entity_decode($loc, ENT_QUOTES | ENT_XML1, 'UTF-8');
            if (preg_match($pattern, $loc, $g) === 1 && isset($g[1]) && $g[1] !== '') {
                $index[$g[1]] = $loc;
            }
        }
        if ($index === []) {
            throw new SourceError($this->name(), 'aucune URL du sitemap ne correspond à item_url_pattern — motif obsolète, refusé');
        }
        $this->lastIndexSize->value = count($index);

        return $index;
    }

    /**
     * The seed pass: every currently-published lot as a bare listing (id + url), to be recorded as
     * seen without a fetch. Facts are unknown, and that is the point — nothing is judged or pushed.
     *
     * @return list<VehicleListing>
     */
    public function seedIndex(): array
    {
        $out = [];
        foreach ($this->index() as $id => $url) {
            $out[] = new VehicleListing(sourceName: $this->name(), externalId: $id, title: '', url: $url);
        }

        return $out;
    }

    /**
     * How many lots the sitemap listed on the last `fetch()`/`index()` — the FEED's size, which is
     * what health must baseline on. `fetch()` returns only NOVEL lots, and after the seed a pass
     * with zero novel lots is the normal steady state, not a drop: baselining on it fired a false
     * `warn_drop` on the first live pass (2026-08-29 22:37, "0 annonces contre une moyenne de 1718").
     */
    public function lastIndexSize(): ?int
    {
        return $this->lastIndexSize->value;
    }

    public function fetch(): array
    {
        // A count never spans two fetches — the `CountsPatternMisses` contract, and the reason the
        // rent adapters grew this line the same day: a source object outlives the pass.
        $this->patternMisses->reset();

        $index = $this->index();
        $known = $this->store->knownExternalIds($this->name());
        $budget = $this->definition->lotBudgetPerPass;
        $out = [];
        $fetched = 0;

        foreach ($index as $id => $url) {
            if (isset($known[$id])) {
                continue;
            }
            if ($fetched >= $budget) {
                break;
            }
            ++$fetched;
            $this->refuseUnlessAllowed($url);
            $this->pace();
            $response = $this->client->send(new HttpRequest($url));
            $moved = $this->sameLotTarget($id, $url, $response);
            if ($moved !== null) {
                // A renamed model slug answers 301 to the SAME uuid (measured 2026-09-26). One hop, the
                // target robots-checked and paced like any lot page. The page's own `offers.url` still
                // names the listing, as for every lot; the target is only its fallback.
                $this->refuseUnlessAllowed($moved);
                $this->pace();
                $url = $moved;
                $response = $this->client->send(new HttpRequest($url));
            }
            if (!$response->isSuccess()) {
                ($this->warn)?->__invoke(sprintf('%s : HTTP %d sur %s — lot ignoré cette passe', $this->name(), $response->status, $url));
                continue;
            }
            $vehicle = self::vehicleBlock($response->body);
            if ($vehicle === null) {
                ($this->warn)?->__invoke(sprintf('%s : aucun bloc JSON-LD Vehicle sur %s — lot ignoré cette passe', $this->name(), $url));
                continue;
            }
            $out[] = $this->map($id, $url, $vehicle);
        }

        return $out;
    }

    /**
     * The redirect target when a lot page answers 3xx to the SAME lot — same host, and
     * `item_url_pattern` reads the same id off the target — else null, and the caller warns and skips
     * as for any non-2xx. Another id, another host, or a page that is not a lot is never followed:
     * an id the sitemap does not list is not this lot, and the source must not learn a new host.
     */
    private function sameLotTarget(string $id, string $url, HttpResponse $response): ?string
    {
        if ($response->status < 300 || $response->status >= 400) {
            return null;
        }
        $location = trim((string) $response->header('location'));
        if ($location === '') {
            return null;
        }
        $parts = parse_url($url);
        $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
        $target = preg_match('~^https?://~i', $location) === 1 ? $location
            : (str_starts_with($location, '/') ? $origin . $location : null);
        if ($target === null || parse_url($target, PHP_URL_HOST) !== ($parts['host'] ?? null)) {
            return null;
        }

        return preg_match((string) $this->definition->itemUrlPattern, $target, $g) === 1 && ($g[1] ?? '') === $id
            ? $target
            : null;
    }

    private function refuseUnlessAllowed(string $url): void
    {
        if (!$this->robots->allows(Robots::pathOf($url))) {
            throw new SourceError($this->name(), sprintf('%s est refusé par robots.txt — this source must not be polled', $url));
        }
    }

    private function pace(): void
    {
        $ms = $this->definition->rateLimitMs;
        if ($ms <= 0) {
            return;
        }
        if ($this->sleeper !== null) {
            ($this->sleeper)($ms);

            return;
        }
        usleep($ms * 1000);
    }

    /** @return ?array<string, mixed> the first `application/ld+json` block whose @type is Vehicle */
    public static function vehicleBlock(string $html): ?array
    {
        if (preg_match_all('~<script[^>]*type\s*=\s*["\']application/ld\+json["\'][^>]*>(.*?)</script>~si', $html, $m) === 0) {
            return null;
        }
        foreach ($m[1] as $json) {
            $data = json_decode(trim($json), true);
            if (!is_array($data)) {
                continue;
            }
            foreach (isset($data['@type']) ? [$data] : (array_is_list($data) ? $data : [$data]) as $node) {
                if (is_array($node) && ($node['@type'] ?? null) === 'Vehicle') {
                    return $node;
                }
            }
        }

        return null;
    }

    /** @param array<string, mixed> $vehicle */
    private function map(string $id, string $url, array $vehicle): VehicleListing
    {
        $get = static function (array $node, string $path): mixed {
            $cur = $node;
            foreach (explode('.', $path) as $step) {
                if (!is_array($cur) || !array_key_exists($step, $cur)) {
                    return null;
                }
                $cur = $cur[$step];
            }

            return $cur;
        };
        $map = $this->definition->map;
        $str = static fn (mixed $v): ?string => is_scalar($v) ? trim((string) $v) : null;
        // ONLY A CONFIGURED KEY CAN MISS, and that guard is the F27b lesson rather than a nicety:
        // counting an unmapped key would report a permanent 100 % on fields nobody asked for, and
        // `total()` only ever speaks at 100 % — so the signal would be pure furniture on every
        // source that maps a subset. `$field` is the ONE funnel all twelve keys pass through, which
        // is why the instrumentation is one line rather than twelve call sites to forget.
        $field = function (string $key) use ($get, $map, $vehicle): mixed {
            if (!isset($map[$key])) {
                return null;
            }

            $value = $get($vehicle, $map[$key]);
            $this->patternMisses->record($key, $value !== null);

            return $value;
        };

        [$year, $month] = VehicleFacts::firstRegistered($str($field('first_registered')));

        $fields = [];
        foreach ($vehicle as $k => $v) {
            if (is_scalar($v)) {
                $fields[(string) $k] = $v;
            }
        }

        return new VehicleListing(
            sourceName: $this->name(),
            externalId: $id,
            title: (string) ($str($field('title')) ?? ''),
            description: (string) ($str($field('description')) ?? ''),
            fields: $fields + ['ref' => $str($field('ref'))],
            url: $str($field('url')) ?? $url,
            make: VehicleFacts::fold($str($field('make'))),
            model: VehicleFacts::fold($str($field('model'))),
            priceEur: VehicleFacts::int($field('price')),
            year: $year,
            month: $month,
            mileageKm: VehicleFacts::int($str($field('mileage'))),
            fuel: VehicleFacts::fuel($str($field('fuel'))),
            gearbox: VehicleFacts::gearbox($str($field('gearbox'))),
            body: VehicleFacts::fold($str($field('body'))),
            sellerType: $this->definition->family === 'dealer' ? 'professional' : null,
        );
    }
}
