<?php

declare(strict_types=1);

namespace Scout\Cli;

use Scout\Car\Cli\CarScout;
use Scout\Job\Cli\JobScout;
use Scout\Rent\Cli\RentScout;

/**
 * The domain registry. Adding a domain is ONE entry here plus its own `Scout\<Slug>\` namespace,
 * `config/<slug>/` and `<SLUG>_*` keys in `.env.example` — no generic-layer CODE imports a domain
 * class, generic messages speak of `<SLUG>_*` / `config/<domain>/` rather than enumerating domains,
 * and `ScoutDispatchTest` asserts the usage text is generated from this table rather than typed
 * beside it.
 */
final class Domains
{
    /** @return array<string, Domain> keyed by slug, in the order the usage lists them */
    public static function all(): array
    {
        return [
            'rent' => new Domain('rent', 'rent-watch', 'RENT_', 'config/rent', RentScout::class),
            'car' => new Domain('car', 'car-watch', 'CAR_', 'config/car', CarScout::class),
            'job' => new Domain('job', 'job-watch', 'JOB_', 'config/job', JobScout::class),
        ];
    }

    public static function get(string $slug): ?Domain
    {
        return self::all()[$slug] ?? null;
    }

    /** @return list<string> */
    public static function slugs(): array
    {
        return array_keys(self::all());
    }
}
