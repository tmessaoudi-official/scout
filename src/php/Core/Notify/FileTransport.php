<?php

declare(strict_types=1);

namespace Scout\Core\Notify;

use Scout\Core\Clock;
use Scout\Core\SystemClock;

/**
 * Writes each message to a directory as an `.eml` file. Never sends anything.
 *
 * The counterpart to {@see \Scout\Adapters\Mail\FileMailbox}, and the reason the email path is
 * testable end to end offline: `scout test-notify` with `SMTP_TRANSPORT=file` produces a real
 * message you can open, without a server, a credential or an outbound connection.
 *
 * It is also the safe way to try a change to the notification payload against real listings —
 * nothing leaves the machine, and the file IS the evidence of what would have been sent.
 */
final readonly class FileTransport implements MailTransport
{
    public function __construct(
        private string $directory,
        private Clock $clock = new SystemClock(),
    ) {}

    public function check(): ?string
    {
        if (is_dir($this->directory)) {
            return is_writable($this->directory) ? null : 'le répertoire ' . $this->directory . ' n\'est pas accessible en écriture';
        }

        return @mkdir($this->directory, 0o775, true) || is_dir($this->directory)
            ? null
            : 'impossible de créer ' . $this->directory;
    }

    public function describe(): string
    {
        return 'fichiers .eml dans ' . $this->directory;
    }

    /**
     * NO. It writes a file and sends nothing — the whole point of it.
     *
     * Under Q8's Docker deployment the outbox is image-local (`compose.yaml` mounts only `./state`
     * and `./config`), so it does not even survive a rebuild.
     */
    public function reachesRecipient(): bool
    {
        return false;
    }

    public function send(string $to, string $subject, string $body, array $headers): void
    {
        $problem = $this->check();
        if ($problem !== null) {
            throw new ChannelError('email', $problem);
        }

        // Self-protect at the boundary, symmetric with the other transports. An `.eml` on disk is
        // the readable record of what WOULD be sent, so a CRLF-smuggled header here would both
        // misrepresent that record and be re-injected verbatim if the file were ever replayed.
        Headers::assertNoCrlf('recipient', $to);
        Headers::assertNoCrlf('subject', $subject);
        foreach ($headers as $name => $value) {
            $name = (string) $name;
            Headers::assertNoCrlf('a header name', $name);
            Headers::assertNoCrlf('header ' . $name, (string) $value);
        }

        $message = 'To: ' . $to . "\r\n" . 'Subject: ' . $subject . "\r\n";
        foreach ($headers as $name => $value) {
            $message .= $name . ': ' . $value . "\r\n";
        }
        $message .= "\r\n" . $body;

        // Sortable by name, and unique. `uniqid()` alone collides under a fast loop; the counter
        // suffix is what stops two notifications in the same millisecond overwriting each other —
        // which would silently lose one, and losing a notification is the failure being avoided.
        $path = sprintf(
            '%s/%s-%s.eml',
            rtrim($this->directory, '/'),
            $this->clock->now()->format('Ymd-His'),
            bin2hex(random_bytes(4)),
        );

        if (@file_put_contents($path, $message) === false) {
            throw new ChannelError('email', 'could not write ' . $path);
        }
    }
}
