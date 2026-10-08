<?php

declare(strict_types=1);

namespace Scout\Tests\Repo;

use PHPUnit\Framework\TestCase;

/**
 * EVERY WAY OFF THE MACHINE ASKS `Offline` FIRST (architecture review B-6, 2026-10-08).
 *
 * `SCOUT_OFFLINE` is what keeps the suite, and any developer run with it set, from reaching a landlord,
 * a mailbox or a notification server. It holds only where a primitive that leaves the machine is called
 * after an `Offline::` refusal is read — and the guarantee has already been smaller than it read once:
 * `NtfyChannel` called libcurl directly and never passed `CurlHttpClient`'s funnel (2026-08-24). The
 * behavioural tests prove each guarded site refuses; nothing proved there is no UNGUARDED site. This
 * walks `src/php` and reads it from the code.
 *
 * A method whose code calls `curl_exec`, `stream_socket_client`, `fsockopen` or `mail` must name
 * `Offline::` before the first such call. The set of such methods is EXACT, by path, so a new way out
 * fails here and is pinned on purpose. Stated limit: it checks that the refusal is READ before the
 * primitive, not that it is acted on — the `if ($refusal !== null) throw` that follows is each site's
 * own behavioural test's job.
 */
final class NetworkPrimitivesAreGuardedTest extends TestCase
{
    private const string SRC = __DIR__ . '/../../../src/php';

    /** A call to a primitive that leaves the machine. A method or a string of the same name is not one. */
    private const string PRIMITIVE = '/(?<![\w$>:\'"])(?:curl_exec|curl_multi_exec|stream_socket_client|fsockopen|pfsockopen|mail)\s*\(/';

    public function testEveryMethodThatLeavesTheMachineAsksOfflineFirst(): void
    {
        $found = self::primitiveMethods(self::SRC);

        self::assertSame(
            [
                'Adapters/Http/CurlHttpClient.php::send' => true,
                'Adapters/Mail/ImapMailbox.php::connect' => true,
                'Core/Notify/NtfyChannel.php::send' => true,
                'Core/Notify/SendmailTransport.php::send' => true,
                'Core/Notify/SmtpTransport.php::connect' => true,
            ],
            $found,
            'a method leaves the machine without reading Offline first (false), or the set moved — pin a new '
                . 'way out on purpose, after it asks Offline',
        );
    }

    /** The walk's own proof: an unguarded call is found, a guarded one passes, prose and lookalikes are not calls. */
    public function testAnUnguardedCallIsFoundAndLookalikesAreNot(): void
    {
        $dir = sys_get_temp_dir() . '/netguard-' . bin2hex(random_bytes(6));
        mkdir($dir . '/Deep/Er', 0o777, true);

        try {
            file_put_contents($dir . '/Deep/Er/Leaky.php', <<<'PHP'
                <?php
                final class Leaky
                {
                    public function guarded(string $url): void
                    {
                        $refusal = Offline::refusal($url);
                        $h = curl_init($url);
                        curl_exec($h);
                    }

                    public function late(string $url): void
                    {
                        $h = curl_init($url);
                        curl_exec($h);
                        $refusal = Offline::refusal($url);
                    }

                    public function quiet(): ?string
                    {
                        // curl_exec($h) in a comment is prose.
                        return function_exists('mail') ? $this->mail() : 'PHP mail() is unavailable';
                    }
                }
                PHP);

            self::assertSame(
                ['Deep/Er/Leaky.php::guarded' => true, 'Deep/Er/Leaky.php::late' => false],
                self::primitiveMethods($dir),
            );
        } finally {
            @unlink($dir . '/Deep/Er/Leaky.php');
            @rmdir($dir . '/Deep/Er');
            @rmdir($dir . '/Deep');
            @rmdir($dir);
        }
    }

    /**
     * `path::method => asks Offline before its first primitive call`, for every method under `$dir` whose
     * code makes one. Bodies are cut declaration to declaration, comment lines dropped, as in
     * {@see AcknowledgeCallSitesTest}.
     *
     * @return array<string, bool>
     */
    private static function primitiveMethods(string $dir): array
    {
        $root = rtrim((string) realpath($dir), '/') . '/';
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $lines = file($file->getPathname(), \FILE_IGNORE_NEW_LINES);
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
                $to = $starts[$k + 1][0] ?? \count($lines);
                // Comment lines dropped, and string literals blanked: `'PHP mail() is unavailable'` is a
                // message, not a call (SendmailTransport::check() names it exactly that way).
                $code = (string) preg_replace('/\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"/s', "''", implode("\n", array_filter(
                    \array_slice($lines, $from + 1, $to - $from - 1),
                    static fn (string $l): bool => preg_match('/^\s*(\*|\/\/|\/\*)/', $l) !== 1,
                )));
                if (preg_match(self::PRIMITIVE, $code, $m, \PREG_OFFSET_CAPTURE) !== 1) {
                    continue;
                }
                $guard = strpos($code, 'Offline::');
                $out[substr((string) realpath($file->getPathname()), \strlen($root)) . '::' . $name] = $guard !== false && $guard < $m[0][1];
            }
        }
        ksort($out);

        return $out;
    }
}
