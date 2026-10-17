<?php

declare(strict_types=1);

namespace Scout\Tests\Repo;

use PHPUnit\Framework\TestCase;

/**
 * ONLY `Core/SystemClock` READS THE WALL CLOCK (architecture review B-10, step 8c, 2026-10-08).
 *
 * The clock was read in nine files as `$this->nowIso ?? new \DateTimeImmutable()` and its variants,
 * each a place a test could forget to pin time. They now go through {@see \Scout\Core\Clock}; this
 * guard makes a new ambient read a failing test rather than a review comment. The allowed set is
 * EXACT, so a second reader is a diff, not a count.
 */
final class WallClockTest extends TestCase
{
    private const string SRC = __DIR__ . '/../../../src/php';

    private const array ALLOWED = ['Core/SystemClock.php'];

    public function testOnlySystemClockReadsTheWallClock(): void
    {
        $readers = [];
        foreach (self::files(self::SRC) as $rel => $code) {
            foreach (explode("\n", $code) as $line) {
                if (preg_match(self::pattern(), $line, $m) === 1) {
                    $readers[$rel][] = trim($m[0]);
                }
            }
        }

        self::assertSame(
            self::ALLOWED,
            array_keys($readers),
            'a file outside Core/SystemClock reads the wall clock — take a Clock instead: ' . json_encode($readers),
        );
    }

    /** The pattern's own proof: each kind of read fires, and the lookalikes a codebase writes do not. */
    public function testThePatternFiresOnReadsAndNotOnLookalikes(): void
    {
        $reads = [
            '$t = time();',
            "\$s = date('c');",
            '$d = date_create();',
            "\$d = date_create('now');",
            "\$t = strtotime('now');",
            '$d = new \DateTimeImmutable();',
            "\$d = new \\DateTimeImmutable('tomorrow 08:30');",
            "\$d = new DateTime('+2 days');",
            '$id = uniqid();',
        ];
        $notReads = [
            "\$y = date('Y', strtotime(\$x));",
            "\$y = date('Y', \$ts);",
            '$n = $this->now();',
            '$n = $clock->now();',
            '$t = Clock::time();',
            '$t = $this->time();',
            '$t = hrtime(true);',
            '$t = microtime(true);',
            '$d = new \DateTimeImmutable($iso);',
            "\$d = new \\DateTimeImmutable('2026-08-07T12:00:00+02:00');",
            '$f = $this->date(\'c\');',
        ];

        foreach ($reads as $line) {
            self::assertSame(1, preg_match(self::pattern(), $line), 'not caught: ' . $line);
        }
        foreach ($notReads as $line) {
            self::assertSame(0, preg_match(self::pattern(), $line), 'false positive: ' . $line);
        }
    }

    private static function pattern(): string
    {
        return '/(?<![\w$>:\\\\])(?:' . WallClockPattern::FRAGMENT . ')/';
    }

    /** @return array<string, string> `relative path => code with comment lines dropped`, at any depth */
    private static function files(string $dir): array
    {
        $root = rtrim((string) realpath($dir), '/') . '/';
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $lines = file($file->getPathname(), \FILE_IGNORE_NEW_LINES) ?: [];
            $out[substr((string) realpath($file->getPathname()), \strlen($root))] = implode("\n", array_filter(
                $lines,
                static fn (string $l): bool => preg_match('/^\s*(\*|\/\/|\/\*)/', $l) !== 1,
            ));
        }
        ksort($out);

        return $out;
    }
}
