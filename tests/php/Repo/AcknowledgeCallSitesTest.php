<?php

declare(strict_types=1);

namespace Scout\Tests\Repo;

use PHPUnit\Framework\TestCase;

/**
 * WHO may mark a message `\Seen` — pinned against the tree, because the CLI is where this would
 * quietly widen.
 *
 * Row 36's rule is *only a `run` pass acknowledges, and only after the store recorded the source*.
 * `doctor` calls `$source->fetch()` directly and must never acknowledge: a diagnostic that marks
 * mail read makes one `doctor` look like a pass, and the developer reads the flag as "processed".
 * `tools/dump-eml.php` stays read-only at the protocol level for the reason its own docblock gives.
 *
 * Neither guarantee has a runtime seam a test can observe — `doctor` against a `FileMailbox` has
 * nothing to flag, and the capture tool is a script — so both are asserted on the source text.
 */
final class AcknowledgeCallSitesTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../..';

    /**
     * WHO CALLS `acknowledge()`, discovered by BEHAVIOUR over the whole tree (architecture review B-4,
     * 2026-10-08).
     *
     * The guard used to match the literal `$source->acknowledge()`, by FILE basename, and checked "no
     * CLI acknowledges" only inside three named CLI files. So a `doctor` extracted into
     * `Application/Doctor.php` — the move the planned migration makes — or a pass whose variable is
     * `$src` escaped it. Now every call in CODE (`->acknowledge(`, `::acknowledge(` or the method named
     * as a callable string) counts, attributed to its METHOD; a call made inside an `acknowledge()`
     * method is a decorator or a source forwarding to its mailbox, which is the same act one layer down.
     * The set is EXACT, by path: a pass that moves must fail here loudly and be re-pinned on purpose.
     */
    public function testOnlyThePipelinesAcknowledgeSourcesAndNoCliDoes(): void
    {
        self::assertSame(
            [
                'Car/VehiclePipeline.php::runOnce',
                'Job/JobPipeline.php::runOnce',
                'Rent/Cli/Pipeline.php::runOnce',
            ],
            self::acknowledgingMethods(self::ROOT . '/src/php'),
            'a source is acknowledged by a run pass and nothing else — doctor and dump must never mark mail',
        );
    }

    /**
     * The discovery's own proof: a doctor moved out of the CLI, under a variable that is not `$source`,
     * is found; a forwarding `acknowledge()` is not; a comment is not.
     */
    public function testAMovedDoctorIsFoundAndAForwarderIsNot(): void
    {
        $dir = sys_get_temp_dir() . '/ackguard-' . bin2hex(random_bytes(6));
        mkdir($dir . '/Application', 0o777, true);

        try {
            file_put_contents($dir . '/Application/Doctor.php', <<<'PHP'
                <?php
                final class Doctor
                {
                    public function run(array $sources): void
                    {
                        // A comment that says $source->acknowledge() is prose, not a call.
                        foreach ($sources as $src) {
                            $src->fetch();
                            $src->acknowledge();
                        }
                    }
                }
                PHP);
            file_put_contents($dir . '/Application/Forwarder.php', <<<'PHP'
                <?php
                final class Forwarder
                {
                    public function acknowledge(): void
                    {
                        $this->inner->acknowledge();
                    }

                    public function describe(): string
                    {
                        // $this->inner->acknowledge() is documented here and never called.
                        return 'forwards';
                    }
                }
                PHP);

            self::assertSame(['Application/Doctor.php::run'], self::acknowledgingMethods($dir));
        } finally {
            array_map('unlink', glob($dir . '/Application/*.php') ?: []);
            rmdir($dir . '/Application');
            rmdir($dir);
        }
    }

    public function testTheCaptureToolStaysReadOnlyAtTheProtocolLevel(): void
    {
        $tool = (string) file_get_contents(self::ROOT . '/tools/dump-eml.php');

        self::assertStringContainsString('EXAMINE', $tool);
        self::assertStringContainsString('BODY.PEEK[]', $tool);
        self::assertDoesNotMatchRegularExpression('~\bSTORE\b~', $tool, 'the capture tool never writes a flag');
        self::assertDoesNotMatchRegularExpression('~[\'"]SELECT\s~', $tool, 'the capture tool never opens a folder read-write');
    }

    /**
     * `path/under/dir.php::method` for every method whose CODE calls `acknowledge()`, except an
     * `acknowledge()` method itself. Bodies are cut declaration to declaration, as in
     * `SectionOneGateCallSitesTest`: coarse, but a call is always attributed to a declaration at or
     * before it, so the imprecision can only make the guard stricter.
     *
     * @return list<string>
     */
    private static function acknowledgingMethods(string $dir): array
    {
        $root = rtrim((string) realpath($dir), '/') . '/';
        $out = [];
        foreach (self::phpFilesUnder($dir) as $file) {
            $lines = file($file, \FILE_IGNORE_NEW_LINES);
            if ($lines === false) {
                continue;
            }
            $starts = [];
            foreach ($lines as $i => $line) {
                if (preg_match('/function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $line, $m) === 1) {
                    $starts[] = [$i, $m[1]];
                }
            }
            foreach ($starts as $k => [$from, $name]) {
                if ($name === 'acknowledge') {
                    continue;
                }
                $to = $starts[$k + 1][0] ?? \count($lines);
                $code = implode("\n", array_filter(
                    \array_slice($lines, $from, $to - $from),
                    static fn (string $l): bool => preg_match('/^\s*(\*|\/\/|\/\*)/', $l) !== 1,
                ));
                if (preg_match('/(->|::)acknowledge\s*\(|[\'"]acknowledge[\'"]/', $code) === 1) {
                    $out[] = substr((string) realpath($file), \strlen($root)) . '::' . $name;
                }
            }
        }
        sort($out);

        return $out;
    }

    /** @return list<string> */
    private static function phpFilesUnder(string $dir): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $out[] = $file->getPathname();
            }
        }
        sort($out);

        return $out;
    }
}
