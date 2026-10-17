<?php

declare(strict_types=1);

namespace Scout\Tests\Repo;

/**
 * What reading the WALL CLOCK looks like in this code, shared by the two guards that forbid it:
 * {@see WallClockTest} (only `Core/SystemClock` may) and {@see DependencyRuleTest} (no pure file may).
 * One vocabulary, so the two cannot drift apart.
 *
 * A GIVEN instant is not a read (`new \DateTimeImmutable($iso)`, `date('Y', $ts)`), and neither is a
 * stopwatch (`hrtime(true)`, `microtime(true)`): elapsed time is not the date. A relative literal is a
 * read (`new \DateTimeImmutable('tomorrow 08:30')`), because it is resolved against now.
 */
final class WallClockPattern
{
    /** An alternation fragment, no delimiters; callers anchor it against `->`, `::`, `$` and word characters. */
    public const string FRAGMENT = 'time\s*\(\s*\)'
        . '|date\s*\(\s*[\'"][^\'"]*[\'"]\s*\)'
        . '|date_create(?:_immutable)?\s*\(\s*(?:\)|[\'"](?:now|today|tomorrow|yesterday|midnight|noon|next|last|this|[+-]))'
        . '|strtotime\s*\(\s*[\'"][^\'"]*[\'"]\s*\)'
        . '|uniqid\s*\('
        . '|new\s+\\\\?DateTime(?:Immutable)?\s*\(\s*(?:\)|[\'"](?:now|today|tomorrow|yesterday|midnight|noon|next|last|this|[+-]))';
}
