<?php

declare(strict_types=1);

namespace Scout\Tests\Rent\Cli;

use PHPUnit\Framework\TestCase;
use Scout\Rent\Cli\DigestBatch;

/**
 * `overflow()` counts what was ANNOUNCED against what is QUEUED — and the two are now different.
 *
 * Round 5 added a §1 gate to both digest drains' rollup half, filtering `$batch->lowScore` into a
 * local. `overflow()` went on iterating `$this->lowScore`, so a row the gate had REMOVED from the
 * mail was still counted as drained — and the remainder line then stayed silent while that row sat
 * in `pendingLowScore()` for ever. That is this method's own documented guarantee read backwards:
 * *"a remainder line must stay silent when there is no remainder"*, inverted into staying silent
 * when there IS one.
 *
 * Unit-level on purpose. The drains' §1 filter has no single-process reachable case — `collectDigest()`
 * refuses such a row upstream through all four routes, so nothing that reaches the filter can be
 * refused by it except in the concurrent-writer window a round-4 lens demonstrated with two store
 * handles. The ARITHMETIC is reachable here, and it is the half that was wrong.
 */
final class DigestBatchOverflowTest extends TestCase
{
    public function testARowTheGateRemovedFromTheMailIsStillCountedAsWaiting(): void
    {
        $batch = new DigestBatch(
            entries: [],
            waiting: 0,
            waitingLowScore: 1,
            lowScore: [['keys' => ['inli:id:GATED']]],
        );

        // The gate refused the only rollup entry, so NOTHING was announced.
        self::assertSame(
            1,
            $batch->overflow(0, true, []),
            'a row removed from the mail is still queued, and the remainder must say so',
        );
    }

    /** The counterweight: a row that really WAS announced is not reported as waiting. */
    public function testAnAnnouncedRowIsNotReportedAsWaiting(): void
    {
        $entry = ['keys' => ['inli:id:SENT']];
        $batch = new DigestBatch(
            entries: [],
            waiting: 0,
            waitingLowScore: 1,
            lowScore: [$entry],
        );

        self::assertSame(0, $batch->overflow(0, true, [$entry]), 'there is no suite');
    }
}
