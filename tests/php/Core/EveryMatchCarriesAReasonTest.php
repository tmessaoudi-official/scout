<?php

declare(strict_types=1);

namespace Scout\Tests\Core;

use PHPUnit\Framework\TestCase;
use Scout\Car\VehicleVerdict;
use Scout\Job\JobVerdict;
use Scout\Rent\Core\Verdict;

/**
 * A MATCH NEVER ARRIVES WITHOUT A REASON, in any domain (architecture review A-16, 2026-10-08).
 *
 * The brief's notification contract is a score AND the reasons behind it: a bare score cannot be
 * judged by the person reading it. Every domain's `matched()` used to accept an empty list, and the
 * rent drain builds one from a stored `signals_json` that decodes to nothing when it is damaged.
 * Rent's context line would still fill the notification's own list, which is why the guard sits on
 * the three verdicts and not on `Core\Notify\Notification`: it is the SCORE that must be explained.
 */
final class EveryMatchCarriesAReasonTest extends TestCase
{
    private const STATED = 'aucune raison enregistrée pour ce score — à juger sur l\'annonce';

    public function testARentMatchWithNoReasonSaysSo(): void
    {
        self::assertSame([self::STATED], Verdict::matched(0, [], false)->reasons);
    }

    public function testACarMatchWithNoReasonSaysSo(): void
    {
        self::assertSame([self::STATED], VehicleVerdict::matched(70, [], false)->reasons);
    }

    public function testAJobMatchWithNoReasonSaysSo(): void
    {
        self::assertSame([self::STATED], JobVerdict::matched(50, [])->reasons);
    }

    /** The counterweight: real reasons are carried untouched, in order, with nothing added. */
    public function testRealReasonsAreNeverReplaced(): void
    {
        self::assertSame(['a', 'b'], Verdict::matched(82, ['a', 'b'], true)->reasons);
        self::assertSame(['a'], VehicleVerdict::matched(70, ['a'], false)->reasons);
        self::assertSame(['a'], JobVerdict::matched(50, ['a'])->reasons);
    }
}
