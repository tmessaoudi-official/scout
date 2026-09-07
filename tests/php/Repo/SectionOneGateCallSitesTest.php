<?php

declare(strict_types=1);

namespace Scout\Tests\Repo;

use PHPUnit\Framework\TestCase;

/**
 * EVERY METHOD THAT ANNOUNCES A LISTING MUST CONSULT `SectionOneGate` — discovered, and per SITE.
 *
 * §1 is judged from four persisted routes, and for four certification rounds the defect was always
 * the same: a route checked on one announcing surface and not another, or checked when the state it
 * read was already stale. Six findings of that shape, three committed inside the fix for the one
 * before. Enumerating surfaces in prose failed twice — `ExcludedDwellings`'s docblock said "TWO
 * callers", then "THREE", each time in the commit that added the uncounted one.
 *
 * **The first version of THIS guard failed too, and that is why it is shaped as it is.** It was
 * FILE-granular and keyed on `->match(`. Both halves were defeated in one round:
 *
 *   - `Pipeline.php` mentions the gate once, so a SECOND ungated send in the same file passed —
 *     which is exactly the round-4 P0, a `Priority::HIGH` rent-drop push of a `PLS` flat from a
 *     send 98 lines above the gate.
 *   - A genuinely new announcing file using a local `$fmt` was never even counted, because the
 *     pattern recognised three exact spellings of the formatter variable.
 *
 * That is the same class granularity its sibling guard was rewritten to remove IN THE SAME COMMIT.
 * The lesson landed on one of the two.
 *
 * So this discovers by **SEND**, never by notification kind, and attributes each send to the METHOD
 * containing it. An announcing surface is a send; equating it with `->match(` is what let the
 * rent-drop path through.
 */
final class SectionOneGateCallSitesTest extends TestCase
{
    /**
     * Methods whose notification is about a SOURCE, not a listing.
     *
     * There is no listing and no dedup key to judge, so the gate has nothing to read. Named rather
     * than pattern-matched, so a new exemption must be written down deliberately.
     */
    private const array NOT_ABOUT_A_LISTING = ['alertOnHealth', 'beat', 'testNotify'];

    public function testEveryMethodThatAnnouncesAListingConsultsTheGate(): void
    {
        $root = \dirname(__DIR__, 3);
        $offenders = [];
        $checked = 0;

        foreach (self::announcingMethods($root . '/src/php/Rent') as [$class, $method, $code]) {
            if (\in_array($method, self::NOT_ABOUT_A_LISTING, true)) {
                continue;
            }
            ++$checked;
            if (!str_contains($code, '->refuses(')) {
                $offenders[] = $class . '::' . $method;
            }
        }

        self::assertGreaterThan(0, $checked, 'premise: some method announces a listing');
        self::assertSame(
            [],
            $offenders,
            'these methods send a notification about a listing without consulting SectionOneGate in '
                . 'the same method — §1 is judged from four persisted routes, and a send that skips '
                . 'the gate reads none of them',
        );
    }

    /**
     * The counterweight: the discovery must find the sends that exist.
     *
     * Derived from source and asserted as a FLOOR, never a hardcoded exact set — the previous
     * counterweight was `assertSame(['Pipeline','RentScout'], $found)`, the same allow-list shape a
     * lens proved vacuous on the sibling guard. A floor means a NEW announcing method makes this
     * guard stricter rather than red for the wrong reason.
     */
    public function testTheDiscoveryFindsTheSendsThatExist(): void
    {
        $methods = array_map(
            static fn (array $m): string => $m[1],
            self::announcingMethods(\dirname(__DIR__, 3) . '/src/php/Rent'),
        );

        self::assertContains('runOnce', $methods, 'the live pass sends matches AND rent drops');
        self::assertContains('pushRetries', $methods, 'the retry drain sends individual matches');
        self::assertContains('announcePromotions', $methods, 'reclassify sends its promotions');
        self::assertGreaterThanOrEqual(4, \count($methods), 'at least the known sending methods');
    }

    /**
     * THE GUARD'S OWN SABOTAGE TEST — it had none, and that is how instance seven shipped.
     *
     * Three properties, each defeated in the C2 round-5 panel before this existed:
     *
     *   - a COMMENT mentioning `->refuses(` must not satisfy the gate check. A lens deleted
     *     `announcePromotions()`'s gate, left a TRUE comment in its place ("the caller already
     *     filters every promotion") and all 3018 tests passed;
     *   - a method whose notifier variable is not called `$notifier` must still be DISCOVERED.
     *     Renaming it in `floorDigest` and deleting the gate left the guard green;
     *   - an HTTP `->send(` must NOT be discovered, or the guard cries wolf on every adapter.
     */
    public function testACommentDoesNotSatisfyTheGateAndARenamedNotifierIsStillFound(): void
    {
        $dir = sys_get_temp_dir() . '/s1guard-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o777, true);

        try {
            file_put_contents($dir . '/Commented.php', <<<'PHP'
                <?php
                final class Commented
                {
                    private function announce($channel, array $rows): void
                    {
                        // The caller already filters every promotion: it calls $gate->refuses( on each.
                        foreach ($rows as $row) {
                            $channel->send((new Formatter())->match($row['listing'], $row['verdict']));
                        }
                    }
                }
                PHP);
            file_put_contents($dir . '/Http.php', <<<'PHP'
                <?php
                final class Http
                {
                    private function fetch($request): string
                    {
                        $response = $this->client->send($request);

                        return $response->body;
                    }
                }
                PHP);

            $found = self::announcingMethods($dir);
            $names = array_map(static fn (array $m): string => $m[0] . '::' . $m[1], $found);

            self::assertContains('Commented::announce', $names, 'a renamed notifier must still be discovered');
            self::assertNotContains('Http::fetch', $names, 'an HTTP send is not an announcement');

            foreach ($found as [$class, $method, $code]) {
                if ($class === 'Commented') {
                    self::assertStringNotContainsString(
                        '->refuses(',
                        $code,
                        'a COMMENT mentioning the gate must not satisfy the gate check',
                    );
                }
            }
        } finally {
            array_map('unlink', glob($dir . '/*.php') ?: []);
            rmdir($dir);
        }
    }

    /**
     * Every method under `$dir` that SENDS A NOTIFICATION, with its comment-stripped code.
     *
     * The needle discriminates on the NOTIFICATION, not on the receiver's name. It was the single
     * literal `notifier->send(`, so renaming `$notifier` to `$channel` hid an entire announcing
     * method — while this guard's own docblock faulted its predecessor for recognising "three exact
     * spellings of the formatter variable". One spelling of the notifier variable is the same
     * mistake wearing the other hat. A bare `->send(` alone would catch the HTTP clients in
     * `Adapters/` and `Enrich/`, so the second term is what makes it a NOTIFICATION send. It
     * matches `Formatter`, `formatter->` and `Notification` — the first spelling alone missed
     * `Pipeline::runOnce`, whose sends read `$this->formatter->match(` with a lowercase receiver.
     *
     * Bodies are cut declaration-to-declaration. That is coarse, and deliberately so: it has no
     * false NEGATIVES, because a send is always attributed to a declaration at or before it, so
     * imprecision can only ever make the guard STRICTER. `Scout\Car` is out of scope — the car
     * domain persists no §1 route at all, a claim verified against `VehicleStore`'s schema rather
     * than asserted.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private static function announcingMethods(string $dir): array
    {
        $out = [];
        foreach (self::rentPhpFiles($dir) as $file) {
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
                $to = $starts[$k + 1][0] ?? \count($lines);
                $body = implode("\n", \array_slice($lines, $from, $to - $from));
                // CODE ONLY. A docblock quoting `$notifier->send()` is prose, and attributing it to
                // the declaration above put `DigestBatch::count` on the offender list.
                $code = implode("\n", array_filter(
                    \array_slice($lines, $from, $to - $from),
                    static fn (string $l): bool => !preg_match('/^\s*(\*|\/\/|\/\*)/', $l),
                ));
                if (str_contains($code, '->send(') && preg_match('/Formatter|formatter->|Notification/', $code) === 1) {
                    // `$code`, NEVER `$body` — INSTANCE SEVEN of this milestone's named defect, and
                    // it was committed inside the fix for instance six. Comment-stripping was
                    // applied to the DETECTION half and not to the VERIFICATION half, ONE LINE
                    // APART, so a comment mentioning `->refuses(` satisfied the gate check. A lens
                    // deleted `announcePromotions()`'s gate, left a TRUE comment in its place, and
                    // all 3018 tests passed. `testACommentDoesNotSatisfyTheGate()` below is the
                    // self-test this guard had never had.
                    $out[] = [basename($file, '.php'), $name, $code];
                }
            }
        }

        return $out;
    }

    /** @return list<string> */
    private static function rentPhpFiles(string $dir): array
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
