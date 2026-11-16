<?php

declare(strict_types=1);

namespace Scout\Tests\Rent\Adapters;

use PHPUnit\Framework\TestCase;
use Scout\Adapters\AcknowledgesMessages;
use Scout\Adapters\Mail\Mailbox;
use Scout\Adapters\Mail\MailboxError;
use Scout\Adapters\SourceError;
use Scout\Rent\Adapters\EmailAlertSource;
use Scout\Rent\Config\SourceDefinition;
use Scout\Rent\Store\Store;

/**
 * WHICH messages a rent email source claims (row 36) — exactly the ones that passed its own two
 * filters, whatever they then yield — and that acknowledging delegates to the mailbox with a
 * refusal surfaced as a `SourceError`, never swallowed.
 *
 * One mailbox serves every portal, so the claim is the whole difference between "this source
 * processed it" and "some source fetched the window it sat in". A message from another sender, or
 * from this sender with a subject this source does not claim, must stay unread: that is the
 * message the developer wants to see when they open the label.
 */
final class EmailAlertClaimTest extends TestCase
{
    private const string OURS = "From: alertes@portal.test\r\nDate: Thu, 03 Sep 2026 10:00:00 +0200\r\nSubject: Nouvelle annonce\r\n\r\nrien\r\n";
    private const string OTHER_SENDER = "From: someone@else.test\r\nDate: Thu, 03 Sep 2026 10:00:00 +0200\r\nSubject: Nouvelle annonce\r\n\r\nrien\r\n";
    private const string OTHER_SUBJECT = "From: alertes@portal.test\r\nDate: Thu, 03 Sep 2026 10:00:00 +0200\r\nSubject: Newsletter\r\n\r\nrien\r\n";

    public function testOnlyAMessagePassingBothFiltersIsClaimedEvenWhenItYieldsNoListing(): void
    {
        $mailbox = new RecordingMailbox([self::OTHER_SENDER, self::OURS, self::OTHER_SUBJECT]);

        $listings = $this->source($mailbox)->fetch();

        self::assertSame([], $listings, 'a claimed message with no card is still a processed message');
        self::assertSame([1], $mailbox->claimed, 'position 1 is ours; 0 is another sender, 2 is our sender with a subject we do not claim');
    }

    public function testAcknowledgeDelegatesToTheMailboxAndASourceIsOneOfTheAcknowledgingKind(): void
    {
        $mailbox = new RecordingMailbox([self::OURS]);
        $source = $this->source($mailbox);
        self::assertInstanceOf(AcknowledgesMessages::class, $source);

        $source->fetch();
        $source->acknowledge();

        self::assertSame(1, $mailbox->acknowledged);
    }

    public function testAMailboxRefusalBecomesASourceErrorNamingTheSource(): void
    {
        $mailbox = new RecordingMailbox([self::OURS], refuse: new MailboxError('UIDVALIDITY changed'));
        $source = $this->source($mailbox);
        $source->fetch();

        try {
            $source->acknowledge();
            self::fail('a refusal must surface — swallowed, the flag silently stops being set');
        } catch (SourceError $e) {
            self::assertStringContainsString('stub_portal', $e->getMessage());
            self::assertStringContainsString('UIDVALIDITY changed', $e->getMessage());
        }
    }

    private function source(Mailbox $mailbox): EmailAlertSource
    {
        return new EmailAlertSource(
            new SourceDefinition(
                name: 'stub_portal',
                enabled: true,
                family: 'private',
                type: 'email_alert',
                mixedTenure: false,
                params: ['from' => 'alertes@portal.test', 'subject_pattern' => '~annonce~i', 'link_host' => 'portal.test'],
            ),
            $mailbox,
        );
    }
}

/** A mailbox that hands out fixed raw messages and records what was claimed and acknowledged. */
final class RecordingMailbox implements Mailbox
{
    /** @var list<int> */
    public array $claimed = [];

    public int $acknowledged = 0;

    /** @param list<string> $messages */
    public function __construct(private readonly array $messages, private readonly ?MailboxError $refuse = null) {}

    public function fetchRecent(int $limit = 50): array
    {
        return $this->messages;
    }

    public function describe(): string
    {
        return 'recording';
    }

    public function newestMessageAt(): ?string
    {
        return null;
    }

    public function claim(int $position): void
    {
        $this->claimed[] = $position;
    }

    public function acknowledge(): void
    {
        ++$this->acknowledged;
        if ($this->refuse !== null) {
            throw $this->refuse;
        }
    }
}
