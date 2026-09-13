<?php

declare(strict_types=1);

namespace Scout\Job;

/**
 * A named vocabulary from `config/job/criteria.json`: label → regex fragment, applied with `~…~u`
 * to FOLDED text. The label is what a reason line shows (`PHP`, `astreintes`), so a push names the
 * word rather than the pattern.
 */
final readonly class JobTerms
{
    /** @param array<string, string> $patterns label => regex fragment, compile-checked at load */
    public function __construct(public array $patterns = []) {}

    public function isEmpty(): bool
    {
        return $this->patterns === [];
    }

    /**
     * Every label whose pattern occurs in the folded text, in config order. With `$negationFirst` a
     * term counts only where no negation sits beside it (`pas d'astreinte` is not an astreinte).
     *
     * @return list<string>
     */
    public function hits(string $folded, bool $negationFirst = false): array
    {
        $out = [];
        foreach ($this->patterns as $label => $fragment) {
            $regex = self::regex($fragment);
            if ($negationFirst ? JobText::stated($folded, $regex) : preg_match($regex, $folded) === 1) {
                $out[] = (string) $label;
            }
        }

        return $out;
    }

    /** The one place a fragment becomes a regex, so the loader's compile check tests what `hits()` runs. */
    public static function regex(string $fragment): string
    {
        return '~' . $fragment . '~u';
    }

    /** The first label that occurs, or null. */
    public function first(string $folded): ?string
    {
        return $this->hits($folded)[0] ?? null;
    }
}
