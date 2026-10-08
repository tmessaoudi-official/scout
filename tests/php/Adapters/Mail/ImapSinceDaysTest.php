<?php

declare(strict_types=1);

namespace Scout\Tests\Adapters\Mail;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Scout\Adapters\Mail\ImapMailbox;

/**
 * `IMAP_SINCE_DAYS` HAS ONE PARSE, AND THE WINDOW ADVICE IS ABOUT THE WINDOW IN USE (B-13, step 8b, 2026-10-08).
 *
 * The value was parsed in four places — the rent, car and job composition roots, and
 * `feedSilentWindowNote()` reading the environment itself — and they did not agree: car and job
 * clamped with `max(1, …)`, rent passed the raw value to the mailbox (which clamps at use), and the
 * note clamped nothing. So `IMAP_SINCE_DAYS=-3` searched one day while `doctor` reported the window as
 * `-3`. The note now takes the window from its caller, and every caller takes it from
 * {@see ImapMailbox::sinceDaysFrom()}.
 */
#[CoversClass(ImapMailbox::class)]
final class ImapSinceDaysTest extends TestCase
{
    /** @return iterable<string, array{string|false, int}> */
    public static function rawValues(): iterable
    {
        yield 'unset' => [false, 7];
        yield 'empty' => ['', 7];
        // PINNED, not endorsed: `getenv() ?: 7` treats the STRING "0" as falsy, so a configured zero has
        // meant the seven-day default on every path since the key existed. A refactor keeps it; changing
        // it is a behaviour decision of its own.
        yield 'zero reads as the default' => ['0', 7];
        yield 'negative clamps to one day' => ['-3', 1];
        yield 'garbage clamps to one day' => ['abc', 1];
        yield 'a real window' => ['14', 14];
    }

    #[DataProvider('rawValues')]
    public function testEveryCallerParsesTheWindowTheSameWay(string|false $raw, int $expected): void
    {
        self::assertSame($expected, ImapMailbox::sinceDaysFrom($raw));
    }

    /** The advice is about the window the caller hands it, never one the environment says. */
    public function testTheNoteReadsTheWindowItIsGivenNotTheEnvironment(): void
    {
        putenv('IMAP_SINCE_DAYS=30');

        try {
            self::assertNotNull(ImapMailbox::feedSilentWindowNote(7, 7), 'a threshold equal to the window is an empty band');
            self::assertNull(ImapMailbox::feedSilentWindowNote(7, 6), 'a threshold under the window leaves a band');
        } finally {
            putenv('IMAP_SINCE_DAYS');
        }
    }

    /** A clamped window is reported as the window the search uses. */
    public function testTheNoteNamesTheEffectiveWindow(): void
    {
        $note = ImapMailbox::feedSilentWindowNote(ImapMailbox::sinceDaysFrom('-3'), 3);

        self::assertNotNull($note);
        self::assertStringContainsString('IMAP_SINCE_DAYS (1)', $note);
    }
}
