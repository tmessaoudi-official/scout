<?php

declare(strict_types=1);

namespace Scout\Car;

/**
 * Would the next daily rollup reach the developer BEFORE this auction lot closes?
 *
 * A match under `push_min_score` waits for the rollup, and for most cars that costs a few hours of
 * nothing. For an auction lot it can cost the lot: one first seen the morning it closes would be
 * announced tomorrow, after the hammer. Measured on Alcopa, no card can clear the gate at all (65 at
 * best against 73 — no price, no body), so without this rule every such lot waited. Developer ruling
 * 2026-09-25: a lot whose closing falls before the next rollup is pushed individually whatever its
 * score; every other lot waits as its score decides, and the rollup still arrives in time.
 *
 * Pure: the clock and the zone are the caller's, as in `DigestSchedule` and `Heartbeat`.
 */
final class AuctionUrgency
{
    /**
     * @param ?string $closingAt  UTC ISO-8601 `Y-m-d\TH:i:s\Z`, the listing's own; null for a car that is no auction
     * @param ?int    $rollupHour the daily floor's local hour; null means no floor, so nothing drains the queue on a schedule
     */
    public static function closesBeforeNextRollup(?string $closingAt, \DateTimeImmutable $now, ?int $rollupHour, \DateTimeZone $zone): bool
    {
        if ($closingAt === null) {
            return false;
        }
        $closes = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $closingAt, new \DateTimeZone('UTC'));
        if ($closes === false || $closes->format('Y-m-d\TH:i:s\Z') !== $closingAt) {
            // Unreadable is not urgent: the formatter still prints it as written, and pushing on a
            // value this cannot read would be a decision made from nothing.
            return false;
        }
        if ($closes <= $now) {
            return false;
        }
        if ($rollupHour === null) {
            return true;
        }

        $local = $now->setTimezone($zone);
        $next = $local->setTime($rollupHour, 0);
        if ($next <= $local) {
            $next = $next->modify('+1 day')->setTime($rollupHour, 0);
        }

        return $closes < $next;
    }
}
