<?php

declare(strict_types=1);

namespace Scout\Tests\Car;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Car\AuctionUrgency;

/**
 * AN AUCTION LOT THAT CLOSES BEFORE THE NEXT ROLLUP CANNOT WAIT FOR IT (developer ruling, 2026-09-25).
 *
 * The daily rollup is where a lot under the push gate is announced — and an Alcopa lot is unable to
 * reach that gate (measured: 65 at best against 73). A lot first seen the morning it closes would
 * therefore reach the developer in tomorrow's rollup, after it had closed. The rule is the smallest
 * one that cannot miss: urgent exactly when the closing falls before the next rollup.
 */
#[CoversClass(AuctionUrgency::class)]
final class AuctionUrgencyTest extends TestCase
{
    private \DateTimeZone $paris;

    protected function setUp(): void
    {
        $this->paris = new \DateTimeZone('Europe/Paris');
    }

    /** 10:00 Paris, rollup at 08:00: the next one is tomorrow 08:00 — so a 15:00 closing today is urgent. */
    public function testAClosingBeforeTomorrowsRollupIsUrgent(): void
    {
        self::assertTrue($this->urgent('2026-09-25T13:00:00Z', '2026-09-25T08:00:00Z'));
    }

    /** …and one after tomorrow's 08:00 is not: that rollup still reaches the developer in time. */
    public function testAClosingAfterTheNextRollupIsNot(): void
    {
        self::assertFalse($this->urgent('2026-09-26T13:00:00Z', '2026-09-25T08:00:00Z'));
    }

    /** 06:00 Paris: today's 08:00 rollup is still ahead, so a 07:30 closing is urgent and a 09:00 one is not. */
    public function testBeforeTheHourTodaysRollupIsTheNextOne(): void
    {
        self::assertTrue($this->urgent('2026-09-25T05:30:00Z', '2026-09-25T04:00:00Z'));
        self::assertFalse($this->urgent('2026-09-25T07:00:00Z', '2026-09-25T04:00:00Z'), 'today\'s 08:00 rollup reaches it first');
    }

    /** The hour is Paris wall-clock, not UTC: at 07:30Z in summer it is 09:30 Paris, so today's rollup has passed. */
    public function testTheRollupHourIsLocal(): void
    {
        self::assertTrue($this->urgent('2026-09-25T20:00:00Z', '2026-09-25T07:30:00Z'), 'next rollup is tomorrow 06:00Z');
    }

    /** No daily floor at all: nothing drains the queue on a schedule, so any future closing is urgent. */
    public function testWithNoRollupHourEveryFutureClosingIsUrgent(): void
    {
        self::assertTrue(AuctionUrgency::closesBeforeNextRollup('2026-10-12T16:00:00Z', new \DateTimeImmutable('2026-09-25T08:00:00Z'), null, $this->paris));
    }

    /** The counterweights: not an auction, a past closing, an unreadable closing. */
    public function testNoClosingAPastClosingOrAnUnreadableOneIsNeverUrgent(): void
    {
        self::assertFalse($this->urgent(null, '2026-09-25T08:00:00Z'));
        self::assertFalse($this->urgent('2026-09-25T07:00:00Z', '2026-09-25T08:00:00Z'));
        self::assertFalse($this->urgent('demain', '2026-09-25T08:00:00Z'));
    }

    private function urgent(?string $closingAt, string $now): bool
    {
        return AuctionUrgency::closesBeforeNextRollup($closingAt, new \DateTimeImmutable($now), 8, $this->paris);
    }
}
