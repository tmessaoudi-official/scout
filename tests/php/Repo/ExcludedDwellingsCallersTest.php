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

    public function testEveryCallSiteIsNamedInTheDocblock(): void
    {
        $root = \dirname(__DIR__, 3);
        $doc = self::callerList($root);

        $found = [];
        foreach ($this->phpFilesUnder($root . '/src') as $file) {
            $body = (string) file_get_contents($file);
            if (!str_contains($body, 'ExcludedDwellings::match(')) {
                continue;
            }
            // The class NAMES itself; it is not one of its own callers.
            if (realpath($file) === realpath($root . '/' . self::MATCHER)) {
                continue;
            }
            $found[] = basename($file, '.php');
        }
        $found = array_values(array_unique($found));
        sort($found);

        self::assertNotSame([], $found, 'premise: the matcher has callers at all');

        foreach ($found as $class) {
            self::assertStringContainsString(
                $class,
                $doc,
                sprintf('%s calls ExcludedDwellings::match() and is not named in the docblock caller list', $class),
            );
        }
    }

    public function testTheDocblockNamesNoCallerThatNoLongerCalls(): void
    {
        $root = \dirname(__DIR__, 3);
        $doc = self::callerList($root);

        // The counterweight. Without it the test above is satisfied by naming every class in the
        // tree, which is the "assert around the gap" shape this panel keeps finding.
        foreach (['Pipeline', 'RentScout', 'Store'] as $class) {
            if (!str_contains($doc, $class)) {
                continue;
            }
            $calls = false;
            foreach ($this->phpFilesUnder($root . '/src') as $file) {
                if (basename($file, '.php') !== $class) {
                    continue;
                }
                $calls = $calls || str_contains((string) file_get_contents($file), 'ExcludedDwellings::match(');
            }
            self::assertTrue($calls, $class . ' is named as a caller but no longer calls the matcher');
        }
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
