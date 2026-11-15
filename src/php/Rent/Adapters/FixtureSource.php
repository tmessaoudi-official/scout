<?php

declare(strict_types=1);

namespace Scout\Rent\Adapters;

use Scout\Core\CountsPatternMisses;
use Scout\Core\PatternMissLog;
use Scout\Rent\Config\SourceDefinition;
use Scout\Core\SourceHealth;
use Scout\Core\SourceHealthService;
use Scout\Rent\Core\SourceProfile;
use Scout\Rent\Core\Tenure;
use Scout\Rent\Store\Store;
use Scout\Adapters\SourceError;

/**
 * A source backed by a frozen payload file. No network, no credentials.
 *
 * This is not a test double bolted on beside the real thing — it is the adapter `scout replay` uses
 * (`spec/PROJECT_BRIEF.md` §10) and the one that makes `spec` §11's *"parser tests run offline, no
 * network in CI"* possible. It shares `ListingMapper` and `Payload` with every network adapter, so a
 * fixture test exercises the same extraction code the real poll does; a fixture source that parsed
 * payloads its own way would prove nothing about production.
 *
 * It is also why `scout run --once` can be demonstrated end to end today, with every real endpoint
 * still unverified under hard rule 1.
 */
final readonly class FixtureSource implements CountsPatternMisses, Source
{
    public function __construct(
        private readonly SourceDefinition $definition,
        private readonly Store $store,
        /** Repo root, so a config path stays relative and portable. */
        private readonly string $rootDir,
        /**
         * How often each CONFIGURED map field extracted nothing this pass — Track 6-A3 / F27b.
         * Mutable object behind a readonly property, the shape {@see EmailAlertSource} already uses.
         */
        private readonly PatternMissLog $patternMisses = new PatternMissLog(),
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

    /** A frozen payload read off the local disk. No host, so Q37 pacing does not apply. */
    public function host(): ?string
    {
        return null;
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

        $relative = $this->definition->fixture;
        if ($relative === null) {
            throw new SourceError($this->name(), 'no fixture file configured');
        }

        // Refused rather than resolved. A `..` in a config-supplied path reaching the filesystem is
        // a traversal whether or not this particular file is attacker-controlled, and there is no
        // legitimate fixture outside the repo.
        if (str_contains($relative, '..')) {
            throw new SourceError($this->name(), 'fixture path may not contain `..`: ' . $relative);
        }

        $path = rtrim($this->rootDir, '/') . '/' . ltrim($relative, '/');
        if (!is_file($path)) {
            throw new SourceError($this->name(), 'fixture file not found: ' . $relative);
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new SourceError($this->name(), 'fixture file could not be read: ' . $relative);
        }

        try {
            $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new SourceError($this->name(), 'fixture is not valid JSON: ' . $e->getMessage(), $e);
        }

        return $this->extract($decoded);
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
        $items = $itemsPath === null ? $decoded : Payload::at($decoded, $itemsPath);

        if ($items === null) {
            // The single commonest way a working adapter stops working: the payload is still valid
            // JSON and still 200, but the results moved. Silently yielding zero items here is the
            // exact shape hard rule 3 forbids — it would read as a quiet market forever.
            throw new SourceError(
                $this->name(),
                'items_path `' . (string) $itemsPath . '` is absent from the payload — '
                    . 'the response shape changed, or the path is wrong',
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
