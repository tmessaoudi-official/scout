<?php

declare(strict_types=1);

namespace Scout\Tests\Car;

use Scout\Core\Notify\Channel;
use Scout\Core\Notify\NotificationKind;
use Scout\Core\Notify\Notification;

/** A channel that keeps what it was sent and counts as delivered — the car tests' notification spy. */
final class CarRecordingChannel implements Channel
{
    /** @var list<Notification> */
    public array $sent = [];

    /** When set, every send() throws — the channel-down half of a delivery-gated guarantee. */
    public bool $down = false;

    /**
     * When set, only THIS kind throws.
     *
     * The remainder clause lives after the rollup mail is confirmed, so a wholly-down channel
     * short-circuits before it and can never exercise the line. The defect the C2 panel found
     * needs the rollup to land and an individual retry to be refused — which is exactly the
     * shape `pushRetries()` is built to survive.
     */
    public ?NotificationKind $refuseKind = null;

    public function name(): string
    {
        return 'recording';
    }

    public function check(): ?string
    {
        return null;
    }

    public function send(Notification $notification): void
    {
        if ($this->down || ($this->refuseKind !== null && $notification->kind === $this->refuseKind)) {
            throw new \RuntimeException('canal indisponible');
        }
        $this->sent[] = $notification;
    }

    public function reachesRecipient(): bool
    {
        return true;
    }

    public function describe(): string
    {
        return 'recording channel';
    }
}
