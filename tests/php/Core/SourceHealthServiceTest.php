<?php

declare(strict_types=1);

namespace Scout\Tests\Core;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Core\PatternMissLog;
use Scout\Core\RunStore;
use Scout\Core\SourceHealthService;
use Scout\Core\SourceStatus;

/**
 * ONE PLACE TURNS A RUN LOG AND A SOURCE'S TELEMETRY INTO ITS HEALTH (architecture review B-11, step 8e-1).
 *
 * Every counting source used to compute `escalate(<run log>->health(name, now[, feedSilentDays]))` in its own
 * `health()` body — ten bodies, one shape. The service is that shape, written once; each source now hands it
 * its name, its own `PatternMissLog` and its own threshold. Each test pins one of the three inputs reaching
 * the verdict, with a counterweight showing the input — not something else — is what moved it:
 *
 *   - the miss log: a blind pattern turns `OK` into `WARN_DROP`, and an all-matching log leaves the run
 *     log's verdict exactly as it was;
 *   - the source's own `feedSilentDays`: it decides `FEED_SILENT` where the store's global default (none)
 *     says `OK` — `EmailAlertSource::health()` was the one funnel for it, and now this is;
 *   - the clock: `STALE` needs it, and a missing clock means "no clock", never "now".
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

    public function testABlindPatternEscalatesTheRunLogVerdict(): void
    {
        $this->runs->recordRun('pap', 10, true, null, '2026-11-15T11:45:00+00:00');
        $blind = new PatternMissLog();
        for ($i = 0; $i < 5; ++$i) {
            $blind->record('surface_pattern', false);
        }

        $health = (new SourceHealthService($this->runs))->of('pap', $blind, self::NOW);

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
            (new SourceHealthService($this->runs))->of('pap', $seeing, self::NOW),
        );
    }

    public function testTheSourcesOwnThresholdReachesTheRunLog(): void
    {
        // A pass that read 4 annonces out of a message three days old.
        $this->runs->recordRun('leboncoin', 4, true, null, '2026-11-15T11:45:00+00:00', feedNewestAt: '2026-11-12T08:00:00+00:00');
        $service = new SourceHealthService($this->runs);

        self::assertSame(
            SourceStatus::OK,
            $service->of('leboncoin', new PatternMissLog(), self::NOW)->status,
            'counterweight: with no threshold anywhere, an ageing feed earns no verdict',
        );
        self::assertSame(
            SourceStatus::FEED_SILENT,
            $service->of('leboncoin', new PatternMissLog(), self::NOW, 2)->status,
            'the source\'s own feed_silent_days must reach the run log',
        );
    }

    public function testTheClockReachesTheRunLog(): void
    {
        // The last run is ten days old: the schedule itself stopped.
        $this->runs->recordRun('inli', 165, true, null, '2026-11-05T12:00:00+00:00');
        $service = new SourceHealthService($this->runs);

        self::assertSame(SourceStatus::STALE, $service->of('inli', new PatternMissLog(), self::NOW)->status);
        self::assertNotSame(
            SourceStatus::STALE,
            $service->of('inli', new PatternMissLog(), null)->status,
            'a missing clock means "no clock" in 8e-1, never a defaulted now',
        );
    }
}
