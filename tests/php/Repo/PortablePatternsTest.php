<?php

declare(strict_types=1);

namespace Scout\Tests\Repo;

use PHPUnit\Framework\TestCase;

/**
 * EVERY CONFIGURED PATTERN MUST COMPILE ON THE OLDEST PCRE2 THIS PROJECT RUNS ON — and for two
 * days none of the CI runs did (2026-09-03 → 2026-09-05, twelve red pushes in a row).
 *
 * The coliving exclusion (`chambre` not preceded by a count) shipped as a chain of lookbehinds,
 * one of them `(?<![0-9]\s\w{1,14}\s)` — a VARIABLE-LENGTH lookbehind, which PCRE2 accepts only
 * from 10.43. The PHP built here and in the deployed image bundles 10.44, so every local run and
 * every live pass was green; the CI runner's PHP links Ubuntu's libpcre2 (10.42), where the
 * loader's compile check refused the whole criteria file and 135 tests errored on `ConfigError`.
 * A regex that is a syntax error on one runtime and a rule on another is the quietest way a
 * guard can differ between the tree and the tree that certifies it.
 *
 * This is a heuristic on the TEXT of each pattern, because the suite cannot run on a PCRE it does
 * not have: a lookbehind body containing a quantifier that is not a fixed count is refused. Named
 * limitation: alternatives of DIFFERENT fixed lengths inside one lookbehind are portable and pass;
 * a bounded `{n,m}` or `+`/`*`/`?` inside one is not, and fails. Rewrite as a negative lookahead
 * from the string start — the coliving pattern is the worked example, measured identical over
 * every stored title before it shipped.
 */
final class PortablePatternsTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../..';

    /** @return list<string> every configured regex fragment or pattern, with where it lives */
    private static function configuredPatterns(): array
    {
        $out = [];
        foreach (['config/rent/criteria.json', 'config/rent/sources.json', 'config/car/criteria.json', 'config/car/sources.json', 'config/job/criteria.json'] as $file) {
            $path = self::ROOT . '/' . $file;
            if (!is_file($path)) {
                continue;
            }
            /** @var mixed $data */
            $data = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
            self::walk($data, $file, $out);
        }

        return $out;
    }

    /** @param array<string, string> $out */
    private static function walk(mixed $node, string $where, array &$out): void
    {
        if (is_array($node)) {
            foreach ($node as $key => $value) {
                if (is_string($key) && str_starts_with($key, '_')) {
                    continue; // a comment, never a pattern
                }
                self::walk($value, $where . '.' . $key, $out);
            }

            return;
        }
        if (is_string($node) && (str_contains($node, '(?<') || str_contains($node, '\\b') || str_starts_with($node, '~') || str_starts_with($node, '^'))) {
            $out[$where] = $node;
        }
    }

    /** A lookbehind body with a quantifier that is not a fixed `{n}` count. */
    public static function needsVariableLengthLookbehind(string $pattern): bool
    {
        if (preg_match_all('~\(\?<[=!]((?:[^()]|\((?:[^()]|\([^()]*\))*\))*)\)~', $pattern, $m) === 0) {
            return false;
        }
        foreach ($m[1] as $body) {
            // Strip escapes first so `\{`, `\+`, `\*`, `\?` are not read as quantifiers, then the
            // group openers — the `?` of `(?:` and `(?<!` is syntax, not a quantifier.
            $bare = (string) preg_replace('~\\\\.~', 'x', $body);
            $bare = (string) preg_replace('~\(\?(?:<?[=!]|[:>P<]?)~', '(', $bare);
            if (preg_match('~\{\d*,\d*\}|[+*?](?![?+])|[+*?]$~', $bare) === 1) {
                return true;
            }
        }

        return false;
    }

    public function testNoConfiguredPatternNeedsAVariableLengthLookbehind(): void
    {
        $offenders = [];
        foreach (self::configuredPatterns() as $where => $pattern) {
            if (self::needsVariableLengthLookbehind($pattern)) {
                $offenders[] = $where . ' :: ' . $pattern;
            }
        }

        self::assertSame([], $offenders, 'a variable-length lookbehind compiles on PCRE2 >= 10.43 only; the CI runner has 10.42');
        self::assertGreaterThan(20, count(self::configuredPatterns()), 'the scan must actually see the configured patterns');
    }

    /** The guard must flag the exact pattern that broke CI, and pass its portable replacement. */
    public function testTheGuardFlagsTheShapeThatBrokeCiAndPassesItsReplacement(): void
    {
        $broken = '(?<![0-9])(?<![0-9]\s)(?<![0-9]\s\w{1,14}\s)(?<!(?:une|deux|trois|quatre|cinq|six)\s)\bchambres?\b';
        $portable = '^(?!.*(?:\b\d+|\b(?:une|deux|trois|quatre|cinq|six))\s+(?:\w{1,14}\s+)?chambres?\b).*\bchambres?\b';
        $fixedAlternatives = '(?<!(?:une|deux|trois|quatre|cinq|six)\s)\bchambres?\b';

        self::assertTrue(self::needsVariableLengthLookbehind($broken));
        self::assertFalse(self::needsVariableLengthLookbehind($portable), 'a lookahead may be as variable as it likes');
        self::assertFalse(self::needsVariableLengthLookbehind($fixedAlternatives), 'alternatives of different FIXED lengths are portable');
        self::assertFalse(self::needsVariableLengthLookbehind('(?<!\d\+)\bx\b'), 'an escaped quantifier is a literal');
    }
}
