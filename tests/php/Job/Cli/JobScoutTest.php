<?php

declare(strict_types=1);

namespace Scout\Tests\Job\Cli;

use PHPUnit\Framework\TestCase;
use Scout\Job\Cli\JobScout;

/**
 * `scout --domain=job …` while the domain is being built one slice at a time.
 *
 * The registry entry lands before the verbs do, so an unbuilt verb must REFUSE by name, loudly, with
 * a non-zero exit. That rule is the whole point of these tests. A verb that exits 0 having done nothing
 * is a watcher reporting a quiet market while watching nothing, which is this project's defining
 * failure. It would have arrived through a stub.
 */
final class JobScoutTest extends TestCase
{
    public function testHelpNamesTheJobConfigDirectoryAndItsOwnEnvPrefix(): void
    {
        $r = self::invoke(['help']);

        self::assertSame(0, $r['code']);
        self::assertStringContainsString('scout --domain=job', $r['out']);
        self::assertStringContainsString('config/job/', $r['out']);
        self::assertStringNotContainsString('config/car/', $r['out'], 'the job help must not be a copy of the car one');
    }

    public function testAVerbNotBuiltYetIsRefusedByNameNeverASilentSuccess(): void
    {
        foreach (['doctor', 'run', 'dump', 'test-notify', 'rollup'] as $verb) {
            $r = self::invoke([$verb]);

            self::assertSame(2, $r['code'], $verb);
            self::assertStringContainsString($verb, $r['err'], $verb);
            self::assertStringContainsString('pas encore', $r['err'], $verb);
        }
    }

    public function testAnUnknownVerbIsRefused(): void
    {
        $r = self::invoke(['frobnicate']);

        self::assertSame(2, $r['code']);
        self::assertStringContainsString('commande inconnue : frobnicate', $r['err']);
    }

    /**
     * @param list<string> $argv
     * @return array{code: int, out: string, err: string}
     */
    private static function invoke(array $argv): array
    {
        $out = fopen('php://memory', 'r+');
        $err = fopen('php://memory', 'r+');
        $code = (new JobScout(sys_get_temp_dir(), $out, $err, '2026-09-13T16:00:00+02:00'))->run($argv);
        rewind($out);
        rewind($err);

        return ['code' => $code, 'out' => (string) stream_get_contents($out), 'err' => (string) stream_get_contents($err)];
    }
}
