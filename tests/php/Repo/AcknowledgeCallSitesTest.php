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

    public function testOnlyThePipelinesAcknowledgeSourcesAndNoCliDoes(): void
    {
        $callers = [];
        foreach (self::phpFilesUnder(self::ROOT . '/src/php') as $file) {
            $content = (string) file_get_contents($file);
            if (preg_match('~\$source->acknowledge\(\)~', $content) === 1) {
                $callers[] = basename($file);
            }
        }
        sort($callers);

        self::assertSame(['JobPipeline.php', 'Pipeline.php', 'VehiclePipeline.php'], $callers, 'a source is acknowledged by a run pass and nothing else');

        foreach (['src/php/Rent/Cli/RentScout.php', 'src/php/Car/Cli/CarScout.php', 'src/php/Job/Cli/JobScout.php'] as $cli) {
            self::assertStringNotContainsString('acknowledge(', (string) file_get_contents(self::ROOT . '/' . $cli), $cli . ' — doctor and dump must never mark mail');
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
