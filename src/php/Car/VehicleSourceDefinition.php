<?php

declare(strict_types=1);

namespace Scout\Car;

/**
 * One block of `config/car/sources.json`. Two adapter types in this slice: `email_alert` (a
 * portal's saved-search mail, card readers configured as positional patterns) and
 * `sitemap_jsonld` (a site whose sitemap indexes lot pages carrying a schema.org `Vehicle` block).
 */
final readonly class VehicleSourceDefinition
{
    /**
     * @param string               $family        portal | dealer | auction — a displayed fact and, later, a score component
     * @param array<string,string> $params        the adapter's string parameters (patterns, from, link_host, …)
     * @param bool                 $legalRisk     RECORD ONLY (developer ruling 2026-10-02): the source's terms of use were not read. No runtime gate reads it — hard rule 4's refusal is rent-side — so it states a fact, it does not enforce one
     * @param array<string,string> $map           `sitemap_jsonld`: listing field => dotted path into the JSON-LD block
     */
    public function __construct(
        public string $name,
        public bool $enabled,
        public string $family,
        public string $type,
        public array $params = [],
        public ?string $url = null,
        public ?string $itemUrlPattern = null,
        public array $map = [],
        public int $lotBudgetPerPass = 50,
        public int $rateLimitMs = 2000,
        public ?int $feedSilentDays = null,
        public ?string $fixture = null,
        // RECORD ONLY (developer ruling 2026-10-02): the source's terms of use were not read. No runtime
        // gate reads it — hard rule 4's refusal is rent-side — so it states a fact, it does not enforce one.
        public bool $legalRisk = false,
    ) {}

    public function param(string $key): ?string
    {
        $v = $this->params[$key] ?? null;

        return $v === null || trim($v) === '' ? null : $v;
    }
}
