<?php

declare(strict_types=1);

namespace Scout\Tests\Core;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Core\CountsPatternMisses;
use Scout\Core\PatternMissLog;
use Scout\Core\SourceHealth;
use Scout\Core\SourceStatus;

/**
 * F-R1 — FOUR ADAPTERS COUNTED EXTRACTION MISSES AND NOTHING READ THE COUNT.
 *
 * Found by the C2 round-1 resilience lens, 2026-09-02. `HtmlSource`, `HttpJsonSource` and
 * `FixtureSource` all implement {@see CountsPatternMisses} and hand their log to `ListingMapper`, so
 * they count — and their `health()` was a one-line delegation to the store that never read
 * `total()`. Under `run --watch`, the deployed mode, a field map going 100 % null on inli /
 * cdc_habitat / cityloger / logirep produced **no health degradation, no `isAlerting()`, no alert** —
 * only a `doctor` printout. That is hard rule 2's own shape: an alert computed and never sent is
 * worse than none, because someone believes the green.
 *
 * It was not theoretical. In'li's card `cp` went 171/171 dead on the deployed image while
 * `HtmlSource::health()` returned `ok`; a human found it running `doctor` after a redeploy. The
 * repair then left In'li's postcode resting on ONE selector, and in region mode `postcode_prefixes`
 * IS the location filter — so if that selector dies the source keeps returning ~171 listings,
 * `item_count` does not move, no run fails, `WARN_DROP` cannot fire, and In'li matches zero flats
 * for ever while reporting `ok`.
 *
 * **The escalation is extracted rather than copied a fifth time.** It was already duplicated
 * verbatim between the two email adapters; four more inline copies is precisely how the sixth
 * adapter forgets. {@see PatternMissLog::escalate()} is the one implementation, and the structural
 * test below is what makes forgetting it fail here rather than go dark in production.
 */
#[CoversClass(PatternMissLog::class)]
final class PatternMissEscalationTest extends TestCase
{
    private const string NOW = '2026-09-02T12:00:00+00:00';

    /** A blind pattern turns an OK verdict into a WARN that names the pattern. */
    public function testABlindPatternEscalatesAnOkVerdict(): void
    {
        $log = new PatternMissLog();
        for ($i = 0; $i < 5; ++$i) {
            $log->record('cp', false);
        }

        $health = $log->escalate($this->health(SourceStatus::OK));

        self::assertSame(SourceStatus::WARN_DROP, $health->status);
        self::assertStringContainsString('cp', $health->detail);
        self::assertStringContainsString('gabarit', $health->detail);
    }

    /** The counterweight: a source whose patterns all match is not warned about. */
    public function testAHealthySourceIsUntouched(): void
    {
        $log = new PatternMissLog();
        for ($i = 0; $i < 5; ++$i) {
            $log->record('cp', true);
        }

        $base = $this->health(SourceStatus::OK);

        self::assertEquals($base, $log->escalate($base), 'a source with no blind pattern must read identically');
    }

    /**
     * A MORE SPECIFIC VERDICT IS NEVER DOWNGRADED TO A LAYOUT COMPLAINT.
     *
     * `BROKEN`, `STALE` and `FEED_SILENT` all say something the operator must act on differently
     * from "the portal changed its markup"; the decoration only ever upgrades from `OK`. That is the
     * rent behaviour copied verbatim, and it is what makes this signal speak only about a source
     * that is otherwise fine — which is exactly the state F-R1's victims were in.
     */
    public function testAMoreSpecificVerdictKeepsItsStatus(): void
    {
        $log = new PatternMissLog();
        for ($i = 0; $i < 5; ++$i) {
            $log->record('cp', false);
        }

        foreach ([SourceStatus::BROKEN, SourceStatus::STALE, SourceStatus::FEED_SILENT] as $status) {
            self::assertSame(
                $status,
                $log->escalate($this->health($status))->status,
                $status->name . ' must not be downgraded to a layout complaint',
            );
        }
    }

    /**
     * EVERY `CountsPatternMisses` IMPLEMENTOR ROUTES `health()` THROUGH THE ONE ESCALATION.
     *
     * The set is discovered by reflection over the loaded classes, never listed here — a literal
     * list is a second place to forget, which is the defect this test exists to make impossible. An
     * adapter that learns to count and does not learn to report fails here instead of counting into
     * a void, which is exactly what three of the five did for a month.
     */
    public function testEveryCountingSourceEscalatesThroughHealth(): void
    {
        $implementors = $this->countingSources();

        // Ten measured 2026-10-08 (Rent/Adapters 5, Car 3, Job 2), asserted as a FLOOR: a new counting
        // adapter makes this stricter, never red for the wrong reason. Each context must contribute,
        // because a scan that silently lost one tree would still clear a bare count.
        self::assertGreaterThanOrEqual(
            10,
            count($implementors),
            'the implementor scan found too few classes to be trusted — it is the guard, not a formality',
        );
        foreach (['Scout\\Rent\\', 'Scout\\Car\\', 'Scout\\Job\\'] as $context) {
            self::assertNotSame(
                [],
                array_filter($implementors, static fn (string $c): bool => str_starts_with($c, $context)),
                'the implementor scan found no counting source in ' . $context . ' — did a tree drop out of the walk?',
            );
        }

        foreach ($implementors as $class) {
            $method = new \ReflectionMethod($class, 'health');
            $file = $method->getFileName();
            self::assertIsString($file);

            $lines = file($file);
            self::assertIsArray($lines);
            $body = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));

            // A DECORATOR SATISFIES THIS BY DELEGATING, and inlining an escalation in one would be
            // the defect rather than the fix: `PacedSource::health()` returns the inner's, and that
            // inner is itself in this same implementor set and checked by this same loop. Adding
            // `escalate()` here would escalate a second time on a verdict already escalated —
            // precisely the "two verbatim inline copies" that extracting `escalate()` removed.
            //
            // The exemption is NARROW and cannot be used to opt a real adapter out: the class must
            // ALSO forward `patternMisses()` to the same inner, which is what makes it a decorator
            // rather than a counter that forgot to report. Both conditions are read from the source,
            // not declared.
            $misses = new \ReflectionMethod($class, 'patternMisses');
            $missesFile = $misses->getFileName();
            self::assertIsString($missesFile);
            $missesLines = file($missesFile);
            self::assertIsArray($missesLines);
            $missesBody = implode('', array_slice(
                $missesLines,
                $misses->getStartLine() - 1,
                $misses->getEndLine() - $misses->getStartLine() + 1,
            ));

            $delegates = str_contains($body, '$this->inner->health(')
                && str_contains($missesBody, '$this->inner->patternMisses()');

            if ($delegates) {
                continue;
            }

            self::assertStringContainsString(
                '->escalate(',
                $body,
                $class . '::health() counts pattern misses and never reports them — route it through '
                    . 'PatternMissLog::escalate(), the one implementation, rather than inlining a copy',
            );
        }
    }

    /**
     * THE WALK FOLLOWS THE TREE, NOT TODAY'S LAYOUT (architecture review B-4, 2026-10-08).
     *
     * The discovery used to glob three directories one level deep — `Rent/Adapters`, `Car`, `Job` — and
     * try three namespace prefixes, and its own comment warned that a class outside them "is never
     * declared and never checked". The migration this review plans moves adapters into new folders
     * (`Car/Adapters/`, a shared application layer), which is exactly the move that would have dropped an
     * implementor out of this guard while it stayed green. A file planted two levels deep in a scratch
     * tree must be found; the self-test below is that proof.
     */
    public function testTheImplementorWalkReachesANestedFolder(): void
    {
        $dir = sys_get_temp_dir() . '/pmwalk-' . bin2hex(random_bytes(6));
        $class = 'NestedCounter' . bin2hex(random_bytes(4));
        mkdir($dir . '/Deep/Nested', 0o777, true);

        try {
            file_put_contents($dir . '/Deep/Nested/' . $class . '.php', <<<PHP
                <?php
                namespace Scout\\Tests\\Scratch;
                final class {$class} implements \\Scout\\Core\\CountsPatternMisses
                {
                    public function patternMisses(): \\Scout\\Core\\PatternMissLog
                    {
                        return new \\Scout\\Core\\PatternMissLog();
                    }
                }
                PHP);

            self::assertContains(
                'Scout\\Tests\\Scratch\\' . $class,
                $this->countingSourcesUnder($dir),
                'an implementor two folders deep was not discovered — the walk does not follow the tree',
            );
        } finally {
            @unlink($dir . '/Deep/Nested/' . $class . '.php');
            @rmdir($dir . '/Deep/Nested');
            @rmdir($dir . '/Deep');
            @rmdir($dir);
        }
    }

    /** @return list<class-string> every class under `src/php` implementing the interface */
    private function countingSources(): array
    {
        return $this->countingSourcesUnder(__DIR__ . '/../../../src/php');
    }

    /**
     * Every class under `$dir`, at any depth, whose CODE declares it implements the interface.
     *
     * The namespace and class name are read from the file itself, so no prefix list can go stale. A class
     * the autoloader cannot reach (a scratch file) is required directly; a `src/php` class is already
     * autoloadable, so `class_exists()` declares it first and the file is never included twice.
     *
     * @return list<class-string>
     */
    private function countingSourcesUnder(string $dir): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $lines = file($file->getPathname(), \FILE_IGNORE_NEW_LINES);
            if ($lines === false) {
                continue;
            }
            // CODE ONLY: a docblock saying "implements CountsPatternMisses" is prose, not a declaration.
            $code = implode("\n", array_filter($lines, static fn (string $l): bool => preg_match('/^\s*(\*|\/\/|\/\*)/', $l) !== 1));
            if (preg_match('/\bclass\s+([A-Za-z_][A-Za-z0-9_]*)[^{]*\bimplements\b[^{]*\bCountsPatternMisses\b/', $code, $c) !== 1) {
                continue;
            }
            $namespace = preg_match('/^\s*namespace\s+([^;]+);/m', $code, $n) === 1 ? trim($n[1]) . '\\' : '';
            $fqcn = $namespace . $c[1];
            if (!class_exists($fqcn)) {
                require_once $file->getPathname();
            }
            if (is_a($fqcn, CountsPatternMisses::class, true)) {
                $out[] = $fqcn;
            }
        }
        sort($out);

        return $out;
    }

    private function health(SourceStatus $status): SourceHealth
    {
        return new SourceHealth(
            sourceName: 'x',
            status: $status,
            detail: '10 annonces au dernier run',
            consecutiveEmptyRuns: 0,
            lastSuccessAt: self::NOW,
            lastFailureAt: null,
            lastCount: 10,
            rollingMean: 10.0,
            runsInWindow: 3,
            failedRunsInWindow: 0,
            totalRuns: 3,
        );
    }
}
