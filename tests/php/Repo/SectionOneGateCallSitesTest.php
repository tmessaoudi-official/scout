<?php

declare(strict_types=1);

namespace Scout\Tests\Repo;

use PHPUnit\Framework\TestCase;

/**
 * EVERY SURFACE THAT SENDS A MATCH MUST CONSULT `SectionOneGate` — discovered, never listed.
 *
 * §1 is judged from four persisted routes, and for three certification rounds the defect was always
 * the same: a route checked on one announcing surface and not another, or checked at a moment when
 * the state it reads was already stale. Five findings, three of them committed inside the fix for
 * the previous one. Enumerating the surfaces in prose is what failed twice — `ExcludedDwellings`'s
 * docblock said "TWO callers", then "THREE", and each time the commit editing that line added a
 * caller it did not count.
 *
 * So this discovers the surfaces instead. A file that formats a MATCH notification and sends it is
 * an announcing surface by definition; if a fourth one appears, it fails here rather than in a
 * review round or in production.
 *
 * It is deliberately COARSE — file granularity, not call-site — because a precise version would
 * need to parse PHP, and a guard nobody can read is a guard nobody maintains. Its job is to make
 * a new surface impossible to add SILENTLY, not to prove each existing call is correctly placed;
 * that is what `SectionOneGateTest` and the per-surface tests do.
 */
final class SectionOneGateCallSitesTest extends TestCase
{
    public function testEverySurfaceThatSendsAMatchConsultsTheGate(): void
    {
        $root = \dirname(__DIR__, 3);
        $offenders = [];
        $checked = 0;

        foreach ($this->rentPhpFiles($root) as $file) {
            $body = (string) file_get_contents($file);

            // A SEND, not merely a format: `[RETRY]` prints a formatted title to the console without
            // notifying anyone, and gating a console line would be noise.
            if (!preg_match('/send\(\s*(?:\(new Formatter\(\)\)|\$this->formatter|\$formatter)->match\(/', $body)) {
                continue;
            }
            ++$checked;
            if (!str_contains($body, 'SectionOneGate') || !str_contains($body, '->refuses(')) {
                $offenders[] = basename($file);
            }
        }

        self::assertGreaterThan(0, $checked, 'premise: at least one surface sends a MATCH');
        self::assertSame(
            [],
            $offenders,
            'these files send a MATCH notification without consulting SectionOneGate — §1 is judged '
                . 'from four persisted routes and a surface that skips the gate reads a subset',
        );
    }

    /**
     * The counterweight: the discovery must actually FIND the surfaces.
     *
     * Without it the test above is satisfied by a pattern that matches nothing at all, which is this
     * repo's named vacuity — a guard that reports coverage it does not have. Both known send sites
     * are named, so narrowing the pattern to zero fails here.
     */
    public function testTheDiscoveryFindsTheKnownSendingSurfaces(): void
    {
        $root = \dirname(__DIR__, 3);
        $found = [];

        foreach ($this->rentPhpFiles($root) as $file) {
            $body = (string) file_get_contents($file);
            if (preg_match('/send\(\s*(?:\(new Formatter\(\)\)|\$this->formatter|\$formatter)->match\(/', $body)) {
                $found[] = basename($file, '.php');
            }
        }
        sort($found);

        self::assertSame(['Pipeline', 'RentScout'], $found, 'the known MATCH-sending surfaces');
    }

    /** @return list<string> */
    private function rentPhpFiles(string $root): array
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/src/php/Rent', \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($it as $entry) {
            if ($entry instanceof \SplFileInfo && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }
        sort($files);

        return $files;
    }
}
