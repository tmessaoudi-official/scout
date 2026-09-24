<?php

declare(strict_types=1);

namespace Scout\Tests\Repo;

use PHPUnit\Framework\TestCase;

/**
 * NO CODE MAY SPECIAL-CASE A CONCRETE INDEXED CAR SOURCE BY CLASS.
 *
 * Seeding and the health baseline of a novelty-only source were three `instanceof
 * SitemapVehicleSource` checks until 2026-09-24. A second source of that shape (Alcopa) would have
 * skipped all three in silence — seeded by fetching every lot page, and baselined on its novel slice,
 * which reads as a drop on every quiet pass. They now test `IndexedVehicleSource`; this pins that no
 * concrete class name comes back.
 */
final class IndexedVehicleSourceCallSitesTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../..';

    public function testNoCallSiteTestsForAConcreteIndexedSource(): void
    {
        $offenders = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT . '/src/php', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            if (preg_match('~instanceof\s+\\\\?(?:Scout\\\\Car\\\\)?(?:SitemapVehicleSource|AlcopaVehicleSource)\b~', self::code($file->getPathname())) === 1) {
                $offenders[] = basename($file->getPathname());
            }
        }

        self::assertSame([], $offenders, 'test `instanceof IndexedVehicleSource`, never the concrete class');
    }

    /** The code alone: a docblock QUOTING the retired check is history, not a call site. */
    private static function code(string $path): string
    {
        $code = '';
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    public function testTheInterfaceIsWhatTheThreeCallSitesRead(): void
    {
        self::assertSame(2, substr_count((string) file_get_contents(self::ROOT . '/src/php/Car/VehiclePipeline.php'), 'instanceof IndexedVehicleSource'));
        self::assertSame(1, substr_count((string) file_get_contents(self::ROOT . '/src/php/Car/Cli/CarScout.php'), 'instanceof IndexedVehicleSource'));
    }
}
