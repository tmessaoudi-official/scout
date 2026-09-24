<?php

declare(strict_types=1);

namespace Scout\Tests\Job;

use Scout\Adapters\Mail\Mailbox;

/** A mailbox that remembers which positions a source claimed. */
final class RecordingMailbox implements Mailbox
{
    /** @var list<int> */
    public array $claimed = [];

    /** @param list<string> $messages */
    public function __construct(private readonly array $messages) {}

    public function fetchRecent(int $limit = 50): array
    {
        return array_slice($this->messages, 0, $limit);
    }

    public function describe(): string
    {
        return 'test';
    }

    public function newestMessageAt(): ?string
    {
        return null;
    }

    public function claim(int $position): void
    {
        $this->claimed[] = $position;
    }

    public function acknowledge(): void {}
}
