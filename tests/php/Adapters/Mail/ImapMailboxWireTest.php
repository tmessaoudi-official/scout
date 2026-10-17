<?php

declare(strict_types=1);

namespace Scout\Tests\Adapters\Mail;

use PHPUnit\Framework\TestCase;
use Scout\Adapters\Mail\ImapMailbox;
use Scout\Adapters\Mail\MailboxError;
use Scout\Core\FixedClock;

/**
 * `ImapMailbox` ON THE WIRE, against a scripted loopback server — its first wire coverage at all.
 *
 * Until row 36 (2026-09-04) this client had zero protocol-level tests: `connect()` hard-coded
 * `tls://` with peer verification, so no loopback server could answer it, and everything asserted
 * was a static helper (`searchCommand()`, `sequencesIn()`, `truncationNotice()`) or a private
 * method reached by reflection. The `\Seen` mark loosens the one invariant the class docblock
 * states — *"READ-ONLY, and enforced rather than intended"* — so the loosening had to be
 * observable, and the seam that makes it observable (`$connector`) is what makes the rest of the
 * dialogue observable too.
 *
 * What the mark must and must not do, each pinned by the transcript rather than by a return value:
 *
 *  - a FETCH stays read-only — `EXAMINE`, `BODY.PEEK[]`, never `SELECT`, never `STORE`;
 *  - messages are addressed by UID, because the mark happens in a SECOND session and sequence
 *    numbers are only meaningful inside one;
 *  - only CLAIMED messages are stored, and only those not already `\Seen`, so steady state opens
 *    no write session at all;
 *  - a folder whose `UIDVALIDITY` changed between the two sessions is refused — the same UID would
 *    name a different message;
 *  - claims do not survive the next fetch, because the mailbox is built once and the watch loop
 *    closes over it.
 */
final class ImapMailboxWireTest extends TestCase
{
    private const string RAW_NEWER = "From: alertes@portal.test\r\nDate: Thu, 03 Sep 2026 10:00:00 +0200\r\nSubject: newer\r\n\r\nbody newer\r\n";
    private const string RAW_OLDER = "From: alertes@portal.test\r\nDate: Wed, 02 Sep 2026 10:00:00 +0200\r\nSubject: older\r\n\r\nbody older\r\n";

    /** @var resource|null */
    private $proc = null;

    /** @var list<resource> */
    private array $pipes = [];

    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->pipes as $pipe) {
            @fclose($pipe);
        }
        if ($this->proc !== null) {
            @proc_terminate($this->proc);
            @proc_close($this->proc);
        }
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
    }

    public function testAFetchIsReadOnlyOnTheWireAndAddressesMessagesByUid(): void
    {
        [$port, $transcriptPath] = $this->startServer(['messages' => [
            ['uid' => 17, 'raw' => self::RAW_OLDER],
            ['uid' => 23, 'raw' => self::RAW_NEWER],
        ]]);

        $mailbox = $this->mailbox($port);
        $messages = $mailbox->fetchRecent();
        // Nothing claimed: acknowledging must open NO second session.
        $mailbox->acknowledge();

        self::assertSame([self::RAW_NEWER, self::RAW_OLDER], $messages, 'newest UID first');
        self::assertSame('2026-09-03T08:00:00Z', $mailbox->newestMessageAt());

        $lines = $this->transcript($transcriptPath);
        self::assertSame(1, $this->connections($lines), 'no write session when nothing was claimed');
        self::assertContainsCommand('EXAMINE "portails"', $lines);
        self::assertContainsCommand('UID SEARCH SINCE 27-Aug-2026 FROM "alertes@portal.test"', $lines);
        self::assertContainsCommand('UID FETCH 23 (UID FLAGS BODY.PEEK[])', $lines);
        self::assertContainsCommand('UID FETCH 17 (UID FLAGS BODY.PEEK[])', $lines);
        self::assertNoCommandMatching('~^SELECT\b~', $lines, 'a fetch never opens the folder read-write');
        self::assertNoCommandMatching('~STORE\b~', $lines, 'a fetch never stores a flag');
        self::assertNoCommandMatching('~\bBODY\[\]~', $lines, 'a plain BODY[] would set \Seen as a side effect of READING');
    }

    public function testAcknowledgeFlagsExactlyTheClaimedMessagesInASecondReadWriteSession(): void
    {
        [$port, $transcriptPath] = $this->startServer(['messages' => [
            ['uid' => 17, 'raw' => self::RAW_OLDER],
            ['uid' => 23, 'raw' => self::RAW_NEWER],
        ]]);

        $mailbox = $this->mailbox($port);
        $mailbox->fetchRecent();
        // Position 1 is the OLDER message (UID 17): newest first, so position 0 is UID 23.
        $mailbox->claim(1);
        $mailbox->acknowledge();

        $lines = $this->transcript($transcriptPath);
        self::assertSame(2, $this->connections($lines));

        $second = $this->session($lines, 2);
        self::assertContainsCommand('SELECT "portails"', $second, 'the write session opens the folder read-write');
        self::assertContainsCommand('UID STORE 17 +FLAGS.SILENT (\Seen)', $second, 'exactly the claimed UID, exactly the \Seen flag, added not replaced');
        self::assertNoCommandMatching('~STORE.*\b23\b~', $second, 'the unclaimed message keeps its flags');
        self::assertNoCommandMatching('~^EXAMINE\b~', $second);
        self::assertNoCommandMatching('~^UID FETCH\b~', $second, 'the write session reads nothing');
        self::assertNoCommandMatching('~-FLAGS|\\\\Deleted|EXPUNGE~i', $lines, 'nothing here can ever remove a flag or a message');
    }

    public function testAMessageAlreadySeenIsNotStoredAgainAndAnAllSeenClaimOpensNoSession(): void
    {
        [$port, $transcriptPath] = $this->startServer(['messages' => [
            ['uid' => 17, 'raw' => self::RAW_OLDER, 'seen' => true],
            ['uid' => 23, 'raw' => self::RAW_NEWER],
        ]]);

        $mailbox = $this->mailbox($port);
        $mailbox->fetchRecent();
        $mailbox->claim(0);
        $mailbox->claim(1);
        $mailbox->acknowledge();
        // A second acknowledge in the same pass has nothing left to store.
        $mailbox->acknowledge();

        $lines = $this->transcript($transcriptPath);
        self::assertSame(2, $this->connections($lines), 'one write session, not two');
        self::assertContainsCommand('UID STORE 23 +FLAGS.SILENT (\Seen)', $this->session($lines, 2));
        self::assertNoCommandMatching('~STORE.*\b17\b~', $lines, 'already \Seen on the server — not stored again');
    }

    public function testAllClaimedMessagesAlreadySeenMeansNoWriteSessionAtAll(): void
    {
        [$port, $transcriptPath] = $this->startServer(['messages' => [
            ['uid' => 17, 'raw' => self::RAW_OLDER, 'seen' => true],
        ]]);

        $mailbox = $this->mailbox($port);
        $mailbox->fetchRecent();
        $mailbox->claim(0);
        $mailbox->acknowledge();

        self::assertSame(1, $this->connections($this->transcript($transcriptPath)), 'steady state: 96 passes a day must not mean 96 extra logins');
    }

    public function testAChangedUidValidityRefusesTheStore(): void
    {
        [$port, $transcriptPath] = $this->startServer([
            'uidvalidity' => 1000,
            'uidvalidity_on_select' => 1001,
            'messages' => [['uid' => 17, 'raw' => self::RAW_OLDER]],
        ]);

        $mailbox = $this->mailbox($port);
        $mailbox->fetchRecent();
        $mailbox->claim(0);

        try {
            $mailbox->acknowledge();
            self::fail('a re-created folder must refuse the store: the same UID names a different message');
        } catch (MailboxError $e) {
            self::assertStringContainsString('UIDVALIDITY', $e->getMessage());
        }

        $second = $this->session($this->transcript($transcriptPath), 2);
        self::assertContainsCommand('SELECT "portails"', $second);
        self::assertNoCommandMatching('~STORE~', $second, 'refused BEFORE the store, not after');
    }

    public function testAStoreTheServerRefusesIsAMailboxError(): void
    {
        [$port] = $this->startServer([
            'store_reply' => 'NO',
            'messages' => [['uid' => 17, 'raw' => self::RAW_OLDER]],
        ]);

        $mailbox = $this->mailbox($port);
        $mailbox->fetchRecent();
        $mailbox->claim(0);

        $this->expectException(MailboxError::class);
        $this->expectExceptionMessage('STORE');
        $mailbox->acknowledge();
    }

    public function testClaimsDoNotSurviveTheNextFetch(): void
    {
        [$port, $transcriptPath] = $this->startServer([
            'max_connections' => 3,
            'messages' => [['uid' => 17, 'raw' => self::RAW_OLDER]],
        ]);

        $mailbox = $this->mailbox($port);
        $mailbox->fetchRecent();
        $mailbox->claim(0);
        // The next pass fetches again and claims nothing — a stale claim must not be stored.
        $mailbox->fetchRecent();
        $mailbox->acknowledge();

        $lines = $this->transcript($transcriptPath);
        self::assertSame(2, $this->connections($lines), 'two fetches, no write session');
        self::assertNoCommandMatching('~STORE~', $lines);
    }

    public function testAClaimOutsideTheLastFetchIsARefusedProgrammingError(): void
    {
        [$port] = $this->startServer(['messages' => [['uid' => 17, 'raw' => self::RAW_OLDER]]]);

        $mailbox = $this->mailbox($port);
        $mailbox->fetchRecent();

        $this->expectException(\InvalidArgumentException::class);
        $mailbox->claim(1);
    }

    public function testTheOfflineRefusalRunsBeforeTheConnectorCanBypassIt(): void
    {
        // `tests/bootstrap.php` sets SCOUT_OFFLINE=1. A non-loopback host must be refused BEFORE
        // the connector closure is consulted, or the test seam would be a way round the guard.
        $invoked = false;
        $mailbox = new ImapMailbox(
            host: 'imap.example.test',
            user: 'u',
            password: 'p',
            connector: static function () use (&$invoked) {
                $invoked = true;

                return false;
            },
        );

        try {
            $mailbox->fetchRecent();
            self::fail('SCOUT_OFFLINE must refuse a non-loopback host');
        } catch (MailboxError $e) {
            self::assertStringContainsString('SCOUT_OFFLINE', $e->getMessage());
        }
        self::assertFalse($invoked, 'the connector must never have been asked');
    }

    public function testTheStoreCommandNamesEveryUidOnceAscendingAndRefusesAnEmptySet(): void
    {
        self::assertSame('UID STORE 5,17,23 +FLAGS.SILENT (\Seen)', ImapMailbox::storeCommand([23, 5, 17, 5]));

        $this->expectException(\InvalidArgumentException::class);
        ImapMailbox::storeCommand([]);
    }

    // ------------------------------------------------------------------------------------------

    private function mailbox(int $port): ImapMailbox
    {
        return new ImapMailbox(
            host: '127.0.0.1',
            user: 'alertes',
            password: 'secret',
            folder: 'portails',
            port: $port,
            timeoutSeconds: 5,
            fromFilter: 'alertes@portal.test',
            sinceDays: 7,
            clock: FixedClock::at('2026-09-03 12:00:00'),
            connector: static function (string $host, int $port, int $timeout) {
                $errno = 0;
                $errstr = '';

                return stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, $timeout);
            },
        );
    }

    /**
     * @param array<string, mixed> $spec
     *
     * @return array{int, string}
     */
    private function startServer(array $spec): array
    {
        $id = bin2hex(random_bytes(6));
        $specPath = sys_get_temp_dir() . '/scout-imap-spec-' . $id . '.json';
        $transcript = sys_get_temp_dir() . '/scout-imap-transcript-' . $id . '.txt';
        file_put_contents($specPath, json_encode($spec, JSON_THROW_ON_ERROR));
        $this->tempFiles[] = $specPath;
        $this->tempFiles[] = $transcript;

        $proc = proc_open(
            [PHP_BINARY, __DIR__ . '/scripted-imap-server.php', $specPath, $transcript],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($proc, 'the scripted server must start');
        $this->proc = $proc;
        $this->pipes = array_values($pipes);

        $name = fgets($pipes[1], 256);
        self::assertIsString($name, 'the scripted server must report the port it chose');
        $port = (int) substr(strrchr(trim($name), ':') ?: ':0', 1);
        self::assertGreaterThan(0, $port);

        return [$port, $transcript];
    }

    /** @return list<string> */
    private function transcript(string $path): array
    {
        foreach ($this->pipes as $pipe) {
            @fclose($pipe);
        }
        $this->pipes = [];
        if ($this->proc !== null) {
            proc_close($this->proc);
            $this->proc = null;
        }

        $raw = @file_get_contents($path);
        self::assertIsString($raw, 'the server must have written its transcript');

        return array_values(array_filter(explode("\n", $raw), static fn (string $l): bool => $l !== ''));
    }

    /** @param list<string> $lines */
    private function connections(array $lines): int
    {
        return count(array_filter($lines, static fn (string $l): bool => str_starts_with($l, '--- connection ')));
    }

    /**
     * The lines of the n-th session only.
     *
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private function session(array $lines, int $n): array
    {
        $out = [];
        $current = 0;
        foreach ($lines as $line) {
            if (preg_match('~^--- connection (\d+) ---$~', $line, $m) === 1) {
                $current = (int) $m[1];
                continue;
            }
            if ($current === $n) {
                $out[] = $line;
            }
        }
        self::assertNotSame([], $out, 'session ' . $n . ' must exist in the transcript');

        return $out;
    }

    /**
     * A client line is `<tag> <command>`; the tag is the client's counter and not asserted.
     *
     * @param list<string> $lines
     */
    private static function assertContainsCommand(string $command, array $lines): void
    {
        foreach ($lines as $line) {
            if (preg_match('~^\S+ (.*)$~', $line, $m) === 1 && $m[1] === $command) {
                return;
            }
        }
        self::fail('expected the client to send `' . $command . '`; sent: ' . implode(' | ', $lines));
    }

    /** @param list<string> $lines */
    private static function assertNoCommandMatching(string $pattern, array $lines, string $why = ''): void
    {
        foreach ($lines as $line) {
            if (preg_match('~^\S+ (.*)$~', $line, $m) === 1 && preg_match($pattern, $m[1]) === 1) {
                self::fail('the client sent `' . $m[1] . '`' . ($why !== '' ? ' — ' . $why : ''));
            }
        }
        self::assertTrue(true);
    }
}
