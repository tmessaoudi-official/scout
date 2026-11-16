<?php

declare(strict_types=1);

namespace Scout\Tests\Core;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Core\PatternMissLog;
use Scout\Core\ReportsHealth;
use Scout\Core\RunStore;
use Scout\Core\SourceHealthReader;
use Scout\Core\SourceHealthService;
use Scout\Core\SourceStatus;

/**
 * ONE PLACE TURNS A RUN LOG AND A SOURCE'S TELEMETRY INTO ITS HEALTH (architecture review B-11, steps 8e-1/8e-2).
 *
 * Every counting source used to compute `escalate(<run log>->health(name, now[, feedSilentDays]))` in its own
 * `health()` body — ten bodies, one shape. 8e-1 wrote that shape once; 8e-2 took `health()` off the source
 * ports, so a source now only REPORTS what the verdict needs ({@see ReportsHealth}: its name, its own miss log,
 * its own threshold) and the service reads it. Each test pins one input reaching the verdict, with a
 * counterweight showing the input — not something else — is what moved it:
 *
 *   - the miss log: a blind pattern turns `OK` into `WARN_DROP`, and an all-matching log leaves the run
 *     log's verdict exactly as it was;
 *   - the source's own `feedSilentDays()`: it decides `FEED_SILENT` where the store's global default (none)
 *     says `OK`;
 *   - the clock: a NON-NULLABLE instant, so `STALE` always has one, judged as an instant whatever its offset.
 */
#[CoversClass(SourceHealthService::class)]
final class SourceHealthServiceTest extends TestCase
{
    private const string NOW = '2026-11-15T12:00:00+00:00';

    private RunStore $runs;

    protected function setUp(): void
    {
        $this->runs = RunStore::open(':memory:');
    }

    public function testTheServiceIsTheReader(): void
    {
        // The pipelines depend on the interface (so routing tests can script a verdict); the service is its one
        // production implementation, and a pipeline built without a reader gets exactly this.
        self::assertInstanceOf(SourceHealthReader::class, new SourceHealthService($this->runs));
    }

    public function testABlindPatternEscalatesTheRunLogVerdict(): void
    {
        $this->runs->recordRun('pap', 10, true, null, '2026-11-15T11:45:00+00:00');
        $blind = new PatternMissLog();
        for ($i = 0; $i < 5; ++$i) {
            $blind->record('surface_pattern', false);
        }

        $health = (new SourceHealthService($this->runs))->of(self::source('pap', $blind), self::now());

        self::assertSame(SourceStatus::OK, $this->runs->health('pap', self::NOW)->status, 'premise: the run log alone says OK');
        self::assertSame(SourceStatus::WARN_DROP, $health->status);
        self::assertStringContainsString('surface_pattern', $health->detail);
    }

    public function testAnAllMatchingLogLeavesTheRunLogVerdictUntouched(): void
    {
        $this->runs->recordRun('pap', 10, true, null, '2026-11-15T11:45:00+00:00');
        $seeing = new PatternMissLog();
        for ($i = 0; $i < 5; ++$i) {
            $seeing->record('surface_pattern', true);
        }

        self::assertEquals(
            $this->runs->health('pap', self::NOW),
            (new SourceHealthService($this->runs))->of(self::source('pap', $seeing), self::now()),
        );
    }

    public function testTheSourcesOwnThresholdReachesTheRunLog(): void
    {
        // A pass that read 4 annonces out of a message three days old.
        $this->runs->recordRun('leboncoin', 4, true, null, '2026-11-15T11:45:00+00:00', feedNewestAt: '2026-11-12T08:00:00+00:00');
        $service = new SourceHealthService($this->runs);

        self::assertSame(
            SourceStatus::OK,
            $service->of(self::source('leboncoin', new PatternMissLog()), self::now())->status,
            'counterweight: with no threshold anywhere, an ageing feed earns no verdict',
        );
        self::assertSame(
            SourceStatus::FEED_SILENT,
            $service->of(self::source('leboncoin', new PatternMissLog(), 2), self::now())->status,
            'the source\'s own feedSilentDays() must reach the run log',
        );
    }

    public function testTheClockReachesTheRunLog(): void
    {
        // The last run is ten days old: the schedule itself stopped.
        $this->runs->recordRun('inli', 165, true, null, '2026-11-05T12:00:00+00:00');
        $service = new SourceHealthService($this->runs);

        self::assertSame(SourceStatus::STALE, $service->of(self::source('inli', new PatternMissLog()), self::now())->status);
        self::assertSame(
            SourceStatus::OK,
            $service->of(self::source('inli', new PatternMissLog()), new \DateTimeImmutable('2026-11-05T13:00:00+00:00'))->status,
            'counterweight: an hour after the run, the same log is healthy',
        );
    }

    public function testTheClockIsJudgedAsAnInstantWhateverItsOffset(): void
    {
        // STALE is "more than seven days" since the run. Written in UTC+1, 14:00:01 is 7 days and 1 second after it
        // (STALE) and 13:00:00 is exactly 7 days (not yet). Read as wall-clock digits instead of instants, both would
        // be a day-and-hours later and both would be STALE.
        $this->runs->recordRun('inli', 165, true, null, '2026-11-08T12:00:00+00:00');
        $service = new SourceHealthService($this->runs);
        $source = self::source('inli', new PatternMissLog());

        self::assertSame(SourceStatus::STALE, $service->of($source, new \DateTimeImmutable('2026-11-15T14:00:01+01:00'))->status);
        self::assertSame(SourceStatus::OK, $service->of($source, new \DateTimeImmutable('2026-11-15T13:00:00+01:00'))->status);
    }

    public function testRunStoreInstantIsTheOneStringToInstantConversion(): void
    {
        // The pipelines hold the pass clock as an ISO string; they reach the service through this, which parses with
        // the same strict `epoch()` the time tests pin, never a second parser.
        self::assertSame(
            RunStore::epoch('2026-08-07T09:00:00.123456789Z'),
            RunStore::instant('2026-08-07T09:00:00.123456789Z')->getTimestamp(),
        );
        self::assertSame(
            RunStore::epoch('2026-08-07T11:00:00+02:00'),
            RunStore::instant('2026-08-07T11:00:00+02:00')->getTimestamp(),
        );
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW);
    }

    private static function source(string $name, PatternMissLog $misses, ?int $feedSilentDays = null): ReportsHealth
    {
        return new readonly class ($name, $misses, $feedSilentDays) implements ReportsHealth {
            public function __construct(private string $name, private PatternMissLog $misses, private ?int $feedSilentDays)
            {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function patternMisses(): PatternMissLog
            {
                return $this->misses;
            }

            public function feedSilentDays(): ?int
            {
                return $this->feedSilentDays;
            }
        };
    }
}
