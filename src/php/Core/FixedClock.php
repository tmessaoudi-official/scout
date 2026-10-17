<?php

declare(strict_types=1);

namespace Scout\Core;

/** A clock that always answers the same instant: the test seam {@see Clock} replaces `nowIso` strings with. */
final readonly class FixedClock implements Clock
{
    public function __construct(private \DateTimeImmutable $at) {}

    public static function at(string $iso): self
    {
        return new self(new \DateTimeImmutable($iso));
    }

    /**
     * The existing `nowIso` seams, as a clock: a pinned instant when a test gave one, the real clock
     * otherwise. Every class that still takes a `nowIso` string resolves it here, so "which clock" is
     * decided by one function rather than by a fallback at each read.
     */
    public static function orSystem(?string $iso): Clock
    {
        return $iso === null ? new SystemClock() : self::at($iso);
    }

    public function now(): \DateTimeImmutable
    {
        return $this->at;
    }
}
