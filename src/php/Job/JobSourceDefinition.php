<?php

declare(strict_types=1);

namespace Scout\Job;

/** One block of `config/job/sources.json`. Slice 1 has one adapter type, `email_alert`. */
final readonly class JobSourceDefinition
{
    /** @param array<string, string> $params the adapter's string parameters (from, patterns, footer marker) */
    public function __construct(
        public string $name,
        public bool $enabled,
        public string $family,
        public string $type,
        public array $params = [],
        public ?int $feedSilentDays = null,
    ) {}

    /** A blank value reads as absent, so an empty declaration cannot pass for a configured one. */
    public function param(string $key): ?string
    {
        $v = $this->params[$key] ?? null;

        return $v === null || trim($v) === '' ? null : $v;
    }
}
