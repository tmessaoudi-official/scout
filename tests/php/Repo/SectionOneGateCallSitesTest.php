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

    /**
     * THE WHOLE SOURCE TREE, NOT `src/php/Rent` (architecture review B-4, 2026-10-08).
     *
     * The scan was rooted at `src/php/Rent` with a floor of four methods, so a sending method moved into
     * a shared application layer — the natural home for Rent/Car/Job reuse, and the migration this review
     * plans — escaped it while the methods left behind kept the floor satisfied. What makes a send a RENT
     * announcement is now read from the file ({@see self::isRentScoped()}), so the root can be the whole
     * tree without dragging in the car and job senders, which have no §1 route to consult.
     */
    private const string SCAN_ROOT = '/src/php';

    public function testEveryMethodThatAnnouncesAListingConsultsTheGate(): void
    {
        $root = \dirname(__DIR__, 3);
        $offenders = [];
        $checked = 0;

        foreach (self::announcingMethods($root . self::SCAN_ROOT) as [$class, $method, $code]) {
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
            self::announcingMethods(\dirname(__DIR__, 3) . self::SCAN_ROOT),
        );

        self::assertContains('runOnce', $methods, 'the live pass sends matches AND rent drops');
        self::assertContains('pushRetries', $methods, 'the retry drain sends individual matches');
        self::assertContains('announcePromotions', $methods, 'reclassify sends its promotions');
        self::assertGreaterThanOrEqual(4, \count($methods), 'at least the known sending methods');
    }

    /**
     * The walk must reach beyond `src/php/Rent`, and the rent filter must drop what it finds there.
     *
     * Every rent sender lives under `Rent/` today, so a scan narrowed back to `src/php/Rent` would find
     * the same offenders and stay green: the narrowing is visible only through the senders it would no
     * longer SEE. The car and job pipelines send notifications too; the unfiltered walk must reach them,
     * and the rent filter must keep every one of them out of the §1 set.
     */
    public function testTheWalkReachesTheWholeTreeAndKeepsOnlyRentSends(): void
    {
        $root = \dirname(__DIR__, 3) . self::SCAN_ROOT;
        $outsideRent = array_map(
            static fn (array $m): string => $m[0],
            array_filter(self::notificationSends($root), static fn (array $m): bool => !$m[3]),
        );

        self::assertContains('VehiclePipeline', $outsideRent, 'the walk did not reach src/php/Car — is the root narrowed?');
        self::assertContains('JobPipeline', $outsideRent, 'the walk did not reach src/php/Job — is the root narrowed?');

        $announcing = array_map(static fn (array $m): string => $m[0], self::announcingMethods($root));
        self::assertSame(
            [],
            array_values(array_intersect($announcing, ['VehiclePipeline', 'JobPipeline', 'CarScout', 'JobScout'])),
            'a car or job sender was taken for a rent announcement — those domains persist no §1 route',
        );
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
                namespace Scout\Rent\Cli;
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
            // A rent sender MOVED OUT of `Rent/`, into the shared layer the migration plans. It must
            // import the rent formatter to use it, and that is what keeps it in scope.
            mkdir($dir . '/Application', 0o777, true);
            file_put_contents($dir . '/Application/Announcer.php', <<<'PHP'
                <?php
                namespace Scout\Application;
                use Scout\Rent\Notify\Formatter;
                final class Announcer
                {
                    public function announce($channel, $listing, $verdict): void
                    {
                        $channel->send((new Formatter())->match($listing, $verdict));
                    }
                }
                PHP);
            // A car sender: a notification send through its own formatter, no §1 route to consult.
            mkdir($dir . '/Car', 0o777, true);
            file_put_contents($dir . '/Car/CarAnnouncer.php', <<<'PHP'
                <?php
                namespace Scout\Car;
                final class CarAnnouncer
                {
                    public function announce($channel, $vehicle): void
                    {
                        $channel->send((new VehicleFormatter())->match($vehicle));
                    }
                }
                PHP);

            $found = self::announcingMethods($dir);
            $names = array_map(static fn (array $m): string => $m[0] . '::' . $m[1], $found);

            self::assertContains('Commented::announce', $names, 'a renamed notifier must still be discovered');
            self::assertNotContains('Http::fetch', $names, 'an HTTP send is not an announcement');
            self::assertContains('Announcer::announce', $names, 'a rent sender moved out of Rent/ escaped the scan');
            self::assertNotContains('CarAnnouncer::announce', $names, 'a car send was taken for a rent announcement');

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
            array_map('unlink', array_merge(glob($dir . '/*.php') ?: [], glob($dir . '/*/*.php') ?: []));
            array_map('rmdir', glob($dir . '/*', \GLOB_ONLYDIR) ?: []);
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
     * than asserted. Since the scan covers the whole tree, that scoping is done per FILE by
     * {@see self::isRentScoped()} rather than by the root.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private static function announcingMethods(string $dir): array
    {
        $out = [];
        foreach (self::notificationSends($dir) as [$class, $method, $code, $rent]) {
            if ($rent) {
                $out[] = [$class, $method, $code];
            }
        }

        return $out;
    }

    /**
     * WHAT MAKES A SEND A RENT ANNOUNCEMENT, read from the file's code (comments stripped).
     *
     * A file in the `Scout\Rent` namespace, or one that names the rent formatter. A rent sender moved
     * into a shared layer must import `Scout\Rent\Notify\Formatter` to build its notification, which is
     * what keeps it in scope. Keyed per FILE on purpose: `VehicleFormatter` and `JobFormatter` contain
     * the word `Formatter`, so a method-level match on the class name would drag every car and job send
     * into the §1 set. Both of those formatter FILES do import the rent formatter, but neither sends.
     */
    private static function isRentScoped(string $code): bool
    {
        return preg_match('/^\s*namespace\s+Scout\\\\Rent(\\\\|;)/m', $code) === 1
            || str_contains($code, 'Scout\\Rent\\Notify\\Formatter');
    }

    /**
     * Every method under `$dir` that sends a NOTIFICATION, rent-scoped or not — the unfiltered walk.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: bool}>
     */
    private static function notificationSends(string $dir): array
    {
        $out = [];
        foreach (self::phpFilesUnder($dir) as $file) {
            $lines = file($file, \FILE_IGNORE_NEW_LINES);
            if ($lines === false) {
                continue;
            }
            $rent = self::isRentScoped(implode("\n", array_filter(
                $lines,
                static fn (string $l): bool => preg_match('/^\s*(\*|\/\/|\/\*)/', $l) !== 1,
            )));
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
                    $out[] = [basename($file, '.php'), $name, $code, $rent];
                }
            }
        }

        return $out;
    }

    /** @return list<string> */
    private static function phpFilesUnder(string $dir): array
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
