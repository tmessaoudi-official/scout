<?php

declare(strict_types=1);

namespace Scout\Tests\Repo;

use PHPUnit\Framework\TestCase;

/**
 * `ExcludedDwellings`'s docblock enumerates its callers; this proves the list is not stale.
 *
 * The docblock carried a COUNT — "TWO callers", then "THREE" — and both times the very commit that
 * edited the line added a caller it did not count, on a line reading *"the count is load-bearing,
 * so keep it right"* (C2 milestone panel rounds 1 and 2). A number maintained by hand is not
 * load-bearing; it is decorative. This is what makes the list load-bearing instead.
 *
 * §1 is judged from four persisted readings and this matcher is one of them, so a caller added
 * without the others knowing is exactly how the drain and `reclassify` came to disagree twice.
 */
final class ExcludedDwellingsCallersTest extends TestCase
{
    private const string MATCHER = 'src/php/Rent/Core/ExcludedDwellings.php';

    /**
     * METHOD granularity, not file granularity.
     *
     * The first version collected `basename($file)`, so `RentScout::collectDigest()` and
     * `RentScout::reclassify()` collapsed to the single token `RentScout` — and a panel proved both
     * directions: deleting the `collectDigest` bullet left it green, and adding a FIFTH call site
     * inside `RentScout` left it green too. That is exactly the population this guard exists for:
     * BOTH §1 P0s of this milestone were a call site added inside a class the docblock already
     * named.
     */
    public function testEveryCallSiteIsNamedInTheDocblock(): void
    {
        $root = \dirname(__DIR__, 3);
        $doc = self::callerList($root);
        $sites = self::callSites($root);

        self::assertNotSame([], $sites, 'premise: the matcher has call sites at all');

        foreach ($sites as $site) {
            [$class, $method] = $site;
            // NO ESCAPE CLAUSE. This read `|| str_contains($doc, $class . '}')`, which accepts a
            // `{@see RentScout}`-style bullet and thereby covers EVERY method of that class — the
            // class granularity this test was written to remove, re-opened on demand. It was inert
            // (no bullet contains `}`) and undocumented, and a lens defeated the test with it in
            // one edit: two bullets replaced by `{@see RentScout}`, plus a fifth undeclared call
            // site, green (C2 round 4). A dead escape clause is still a door.
            self::assertTrue(
                str_contains($doc, $class . '::' . $method),
                sprintf(
                    '%s::%s() calls ExcludedDwellings::match() and no bullet names it. §1 is judged '
                        . 'from four persisted routes; a call site the enumeration does not know '
                        . 'about is how two rounds of this panel found a P0.',
                    $class,
                    $method,
                ),
            );
        }
    }

    /**
     * THE COUNTERWEIGHT: every bullet must name a call site that really exists.
     *
     * Without it the test above is satisfied by listing every method in the tree. The first version
     * of this counterweight iterated a hardcoded `['Pipeline', 'RentScout', 'Store']`, so an
     * invented bullet passed — a panel proved it by inserting `Formatter::nothing()` and watching
     * the suite stay green. This derives the truth from the source instead, so an invented bullet
     * fails whatever it is called.
     */
    public function testEveryBulletNamesARealCallSite(): void
    {
        $root = \dirname(__DIR__, 3);
        $real = [];
        foreach (self::callSites($root) as [$class, $method]) {
            $real[] = $class . '::' . $method;
        }

        preg_match_all('/([A-Za-z]+)::([A-Za-z]+)\\(\\)/', self::callerList($root), $m, PREG_SET_ORDER);
        self::assertNotSame([], $m, 'premise: the enumeration is parseable');

        foreach ($m as $bullet) {
            self::assertContains(
                $bullet[1] . '::' . $bullet[2],
                $real,
                sprintf('the docblock names %s::%s(), which calls nothing', $bullet[1], $bullet[2]),
            );
        }
    }

    /**
     * Every `ExcludedDwellings::match()` call site under `src/`, as [class, enclosing method].
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function callSites(string $root): array
    {
        $sites = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/src', \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($it as $entry) {
            if (!$entry instanceof \SplFileInfo || $entry->getExtension() !== 'php') {
                continue;
            }
            if (realpath($entry->getPathname()) === realpath($root . '/' . self::MATCHER)) {
                continue; // the class does not call itself
            }
            $lines = file($entry->getPathname(), \FILE_IGNORE_NEW_LINES);
            if ($lines === false) {
                continue;
            }
            $class = basename($entry->getPathname(), '.php');
            foreach ($lines as $i => $line) {
                if (!str_contains($line, 'ExcludedDwellings::match(')) {
                    continue;
                }
                // Walk back to the nearest enclosing declaration.
                for ($j = $i; $j >= 0; --$j) {
                    if (preg_match('/function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $lines[$j], $m) === 1) {
                        $sites[] = [$class, $m[1]];
                        break;
                    }
                }
            }
        }
        sort($sites);

        return $sites;
    }

    /**
     * ONLY the enumeration bullets, never the whole docblock.
     *
     * A first cut matched the whole file text and was VACUOUS: deleting `Store::reopen()` from the
     * list left the suite green, because the word `Store` also appears in the prose above it
     * (*"`Store::excludedDwellings()` numbered it fourth"*). Verified by sabotage before shipping —
     * the same assert-around-the-gap shape this test exists to prevent, committed inside it.
     */
    private static function callerList(string $root): string
    {
        $doc = (string) file_get_contents($root . '/' . self::MATCHER);
        preg_match_all('/^ \*   - .*$/m', $doc, $m);

        return implode("\n", $m[0]);
    }

    /** @return list<string> */
    private function phpFilesUnder(string $dir): array
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $entry) {
            if ($entry instanceof \SplFileInfo && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }
        sort($files);

        return $files;
    }
}
