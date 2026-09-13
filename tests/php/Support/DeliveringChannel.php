<?php

declare(strict_types=1);

namespace Scout\Tests\Support;

use Scout\Core\Notify\Channel;
use Scout\Core\Notify\ChannelError;
use Scout\Core\Notify\Notification;

/**
 * A channel that COUNTS as a delivery and always succeeds, for CLI tests that need one.
 *
 * It exists because there is no offline configuration that can play this part. The four CLI test
 * classes used `email` over `SMTP_TRANSPORT=file` as their "remote" channel for one review round,
 * and a file transport cannot reach anyone — so every assertion about a listing being marked
 * notified passed for the reason that was itself the round-8 P0. Injecting a double is honest
 * about being a double; configuring a file transport looked like a real channel and was not.
 *
 * Injected through `Scout`'s constructor seam. Tests about CHANNEL BUILDING — an unknown name, a
 * missing credential, the console-only warning — must NOT use this, or they stop exercising the
 * thing they are about.
 */
final class DeliveringChannel implements Channel
{
    /** @var list<Notification> */
    public array $sent = [];

    public function name(): string
    {
        return 'ntfy';
    }

    public function check(): ?string
    {
        return null;
    }

    public function reachesRecipient(): bool
    {
        return true;
    }

    public function describe(): string
    {
        return 'double de test';
    }

    /**
     * Kinds this channel refuses to deliver, so a test can exercise the DELIVERY-FAILED branch.
     *
     * A recipient-reaching channel that throws is the state Q27's refusal note is most likely to
     * meet — the commonest startup refusal is a channel misconfiguration — and until round 5 nothing
     * could reach that branch at all.
     *
     * @var list<\Scout\Core\Notify\NotificationKind>
     */
    public array $refuses = [];

    /**
     * The message a refusal throws.
     *
     * Settable because a real channel error carries the URL it failed on — an ntfy topic, an SMTP
     * DSN, a Telegram bot token — and hard rule 7 says none of that may be logged. A test that
     * cannot put a credential in the message cannot prove the redaction (C2 round 7).
     */
    public string $refusalMessage = 'ntfy: HTTP 503 depuis ntfy.sh';

    /**
     * Called after each DELIVERED notification, so a test can play the concurrent writer.
     *
     * The §1 gate re-reads the store before every send precisely because a `run --watch` in
     * another process can write `recordTwin()` between two sends of one drain, and a single-process
     * seed cannot interleave with that — which is how three ledger cases reported `ok` while a
     * structural guard answered for them (plan row 70). Opening a second `Store` handle in here
     * simulates that writer at the exact seam the gate exists for. A closure that throws is wrapped
     * by `Notifier::send()` into a refused send, so a test using this must assert the closure ran.
     *
     * @var (\Closure(Notification): void)|null
     */
    public ?\Closure $onSend = null;

    public function send(Notification $notification): void
    {
        if (\in_array($notification->kind, $this->refuses, true)) {
            // TWO ARGUMENTS. It was one — `new ChannelError('ntfy: HTTP 503 …')` — which is a
            // TypeError, not a ChannelError, and `Notifier::send()` catches `\Throwable` and wraps
            // it. So every refusal test passed on a wrapped TypeError whose message named a PHP
            // argument count, and the refusal path this double exists for was never exercised as
            // itself (C2 round 7, found while proving the redaction).
            throw new ChannelError($this->name(), $this->refusalMessage);
        }

        $this->sent[] = $notification;

        if ($this->onSend !== null) {
            ($this->onSend)($notification);
        }
    }
}
