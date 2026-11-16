<?php

declare(strict_types=1);

namespace Scout\Tests\Rent\Adapters;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Rent\Adapters\EmailAlertSource;
use Scout\Adapters\FeedFreshness;
use Scout\Adapters\Mail\FileMailbox;
use Scout\Adapters\Mail\ImapMailbox;
use Scout\Adapters\Mail\Mailbox;
use Scout\Rent\Config\SourceDefinition;
use Scout\Core\RunStore;
use Scout\Core\SourceHealthService;
use Scout\Core\SourceStatus;
use Scout\Rent\Store\Store;

/**
 * The production chain that PRODUCES a feed date, which a certification panel found entirely unpinned.
 *
 * **Three independent reviewers each mutated this path and got a fully green suite.** The master
 * switch was `EmailAlertSource::newestFeedItemAt() { return null; }` — one line, no test failure,
 * and `FEED_SILENT` dead in production for `seloger`, `bienici`, `leboncoin` and `pap`
 * simultaneously. `ImapMailbox::noteMessageDate()` had never been executed by any test at all.
 *
 * The excuse for that was *"no offline test can reach it"*, and it was false: `noteMessageDate()`
 * is pure given a raw message, the repo already reflects into this class's privates
 * ({@see \Scout\Tests\Rent\Adapters\NetworkAdaptersTest} does it for `capWithNotice` and `quote`), and a
 * fake `Mailbox` is all the source side needs. Dead safety code is not excused by being awkward.
 */
#[CoversClass(ImapMailbox::class)]
#[CoversClass(EmailAlertSource::class)]
#[CoversClass(FileMailbox::class)]
final class FeedFreshnessTest extends TestCase
{
    /** Feed one raw message through the real private method and read the real private field. */
    private static function noted(string ...$raw): ?string
    {
        $box = new ImapMailbox('h.test', 'u', 'p');
        $note = new \ReflectionMethod(ImapMailbox::class, 'noteMessageDate');

        foreach ($raw as $one) {
            $note->invoke($box, $one);
        }

        return $box->newestMessageAt();
    }

    private static function message(string $date): string
    {
        return "From: a@b.test\r\nDate: " . $date . "\r\nSubject: x\r\n\r\nbody\r\n";
    }

    public function testAnOrdinaryDateIsNormalisedToUtc(): void
    {
        self::assertSame('2026-08-26T05:33:06Z', self::noted(self::message('Wed, 26 Aug 2026 07:33:06 +0200')));
    }

    /**
     * THE DEFECT THE PANEL FOUND: a mismatched weekday must be REFUSED, never advanced.
     *
     * 9 August 2026 is a Sunday. `new \DateTimeImmutable('Fri, 09 Aug 2026 08:00:00 +0200')` applies
     * `Fri` as a relative modifier and returns **14 August** — five days forward. The observable
     * band is `IMAP_SINCE_DAYS − RENT_FEED_SILENT_DAYS`, four days on the defaults, so a +5 shift ages
     * the message out of the search window before the shifted date can reach the threshold: the
     * verdict is closed by its own input. `createFromFormat` alone does NOT catch this either; only
     * the round-trip comparison does.
     */
    public function testAMismatchedWeekdayIsRefusedRatherThanAdvanced(): void
    {
        self::assertNull(self::noted(self::message('Fri, 09 Aug 2026 08:00:00 +0200')));
    }

    /** A relative expression is not a date. Each of these was accepted before, as "permanently fresh". */
    public function testRelativeExpressionsAreNotDates(): void
    {
        foreach (['now', 'tomorrow', '+2 days', 'next friday'] as $bogus) {
            self::assertNull(self::noted(self::message($bogus)), $bogus . ' must not parse as a date');
        }
    }

    /** An impossible date is refused — the round-trip's original purpose in `Store::epoch()`. */
    public function testAnImpossibleDateIsRefused(): void
    {
        self::assertNull(self::noted(self::message('Thu, 32 Aug 2026 08:00:00 +0200')));
    }

    /** RFC 2822 makes the day-of-week optional, and a trailing zone comment is ubiquitous. */
    public function testTheRealWorldShapesStillParse(): void
    {
        self::assertSame('2026-08-26T05:33:06Z', self::noted(self::message('26 Aug 2026 07:33:06 +0200')));
        self::assertSame('2026-08-26T05:33:06Z', self::noted(self::message('Wed, 26 Aug 2026 07:33:06 +0200 (CEST)')));
    }

    /** An absent or blank header contributes nothing — unknown is not old (hard rule 9). */
    public function testAnAbsentOrBlankDateContributesNothing(): void
    {
        self::assertNull(self::noted("From: a@b.test\r\nSubject: x\r\n\r\nbody\r\n"));
        self::assertNull(self::noted(self::message('   ')));
    }

    /** The NEWEST wins, whatever order the messages arrive in, and offsets are compared as instants. */
    public function testTheNewestMessageWinsRegardlessOfOrder(): void
    {
        $older = self::message('Mon, 24 Aug 2026 23:00:00 +0000');
        $newer = self::message('Tue, 25 Aug 2026 02:00:00 +0200'); // 00:00Z — the later instant

        self::assertSame('2026-08-25T00:00:00Z', self::noted($older, $newer));
        self::assertSame('2026-08-25T00:00:00Z', self::noted($newer, $older));
    }

    /**
     * A fetch RESETS the remembered date before it does anything else — including a fetch that fails.
     *
     * **Testable offline precisely because the reset is the first statement**, ahead of `connect()`:
     * the offline tripwire throws a moment later, by which time the reset has already happened. So
     * the guarantee is observable without a socket, which is what made the earlier "untestable"
     * excuse wrong here too.
     *
     * The defect it pins: two `return []` paths — an empty mailbox, and a `SEARCH` matching nothing
     * in the window — sit above where the reset used to be, and a mailbox instance is built once per
     * source and reused across every `--watch` pass. So a pass that fetched NOTHING kept the
     * previous pass's date and reported it as its own, which is exactly what `Pipeline` documents
     * must never happen while guarding only the exception path. It was harmless only because
     * `RunStore::health()` gates on `$lastCount > 0` in a different file — one mechanism accidentally
     * protected by another, this repo's own named trap.
     */
    public function testAFetchForgetsThePreviousFetchsDate(): void
    {
        $box = new ImapMailbox('h.test', 'u', 'p');
        (new \ReflectionMethod(ImapMailbox::class, 'noteMessageDate'))
            ->invoke($box, self::message('Wed, 26 Aug 2026 07:33:06 +0200'));

        self::assertNotNull($box->newestMessageAt(), 'precondition: a date is remembered');

        try {
            $box->fetchRecent(5);
        } catch (\Throwable) {
            // The offline tripwire, or a refused connection. Either way the reset ran first.
        }

        self::assertNull($box->newestMessageAt(), 'a fetch must not inherit the previous one\'s date');
    }

    /**
     * `FileMailbox` reports `null`, and this is the assertion its docblock claimed to have.
     *
     * **Load-bearing today, not hypothetically.** The committed fixtures are dated 25–26 August and
     * the default threshold is three days, so the moment this returns a real date the documented
     * `MAILBOX_DIR=tests/fixtures/rent/<source> scout doctor` workflow reports `feed_silent` — a gate
     * that reddens with the calendar and no code change.
     */
    public function testAFileMailboxReportsNoFreshnessAtAll(): void
    {
        self::assertNull((new FileMailbox(__DIR__))->newestMessageAt());
    }

    /**
     * THE MASTER SWITCH: the source must DELEGATE to its mailbox.
     *
     * Replacing this one method body with `return null;` killed `FEED_SILENT` for every email
     * source at once, in production only, with 2118 tests green.
     */
    public function testTheSourceDelegatesFreshnessToItsMailbox(): void
    {
        $source = $this->source(new StubDatedMailbox('2026-08-26T05:33:06Z'));

        self::assertInstanceOf(FeedFreshness::class, $source);
        self::assertSame('2026-08-26T05:33:06Z', $source->newestFeedItemAt());
    }

    /** And a mailbox with nothing to report yields no verdict rather than a false one. */
    public function testASourceOverAMailboxWithNoFreshnessReportsNull(): void
    {
        self::assertNull($this->source(new StubDatedMailbox(null))->newestFeedItemAt());
    }

    /**
     * End to end through the REAL store: a delegated date becomes a real verdict.
     *
     * The link the panel could not see. Everything before this test proved the store judges
     * hand-written rows correctly and that the source returns a string; nothing proved the string
     * the source returns is the one the store judges.
     */
    public function testADelegatedDateBecomesAFeedSilentVerdict(): void
    {
        $store = Store::open(':memory:');
        $source = $this->source(new StubDatedMailbox('2026-08-26T05:33:06Z'), $store);

        $store->recordRun($source->name(), 3, true, null, '2026-08-29T09:00:00Z', null, $source->newestFeedItemAt());

        self::assertSame(SourceStatus::FEED_SILENT, $store->runs()->health($source->name(), '2026-08-29T09:00:00Z', 3)->status);
    }

    /**
     * A source's OWN `feed_silent_days` decides its verdict, through the port's `feedSilentDays()` — the
     * single funnel `doctor`, the pipeline and the heartbeat all read. A message two days old is
     * `OK` under the store's global three-day threshold and `FEED_SILENT` under this source's one
     * day, and the source is what has to say so; a threshold that only `doctor` honoured would leave
     * the beat counting the source as healthy on the same pass `doctor` called it silent.
     */
    public function testASourcesOwnThresholdOutranksTheGlobalOne(): void
    {
        $store = Store::open(':memory:', 3);
        $strict = $this->source(new StubDatedMailbox('2026-08-27T09:00:00Z'), $store, feedSilentDays: 1);
        $lenient = $this->source(new StubDatedMailbox('2026-08-27T09:00:00Z'), $store);

        $store->recordRun($strict->name(), 3, true, null, '2026-08-29T09:00:00Z', null, $strict->newestFeedItemAt());

        self::assertSame(SourceStatus::FEED_SILENT, (new SourceHealthService($store->runs()))->of($strict, RunStore::instant('2026-08-29T09:00:00Z'))->status, 'one day of silence exceeded');
        self::assertSame(SourceStatus::OK, (new SourceHealthService($store->runs()))->of($lenient, RunStore::instant('2026-08-29T09:00:00Z'))->status, 'the global 3 says not yet');
    }

    private function source(Mailbox $mailbox, ?Store $store = null, ?int $feedSilentDays = null): EmailAlertSource
    {
        return new EmailAlertSource(
            new SourceDefinition(
                name: 'stub_portal',
                enabled: true,
                family: 'private',
                type: 'email_alert',
                mixedTenure: false,
                feedSilentDays: $feedSilentDays,
            ),
            $mailbox,
        );
    }
}

/** A mailbox that reports a fixed freshness and no messages — the source side needs nothing more. */
final readonly class StubDatedMailbox implements Mailbox
{
    public function __construct(private ?string $newest) {}

    public function fetchRecent(int $limit = 50): array
    {
        return [];
    }

    public function describe(): string
    {
        return 'stub';
    }

    public function newestMessageAt(): ?string
    {
        return $this->newest;
    }

    public function claim(int $position): void
    {
    }

    public function acknowledge(): void
    {
    }
}
