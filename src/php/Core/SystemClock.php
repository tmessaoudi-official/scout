<?php

declare(strict_types=1);

namespace Scout\Core;

/** The real clock — the ONE place in `src/php` that reads the wall clock ({@see Clock}). */
final readonly class SystemClock implements Clock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }
}
