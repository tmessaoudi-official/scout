<?php

declare(strict_types=1);

namespace Scout\Tests\Car;

use PHPUnit\Framework\TestCase;
use Scout\Adapters\AcknowledgesMessages;
use Scout\Adapters\Mail\Mailbox;
use Scout\Adapters\Mail\MailboxError;
use Scout\Adapters\SourceError;
use Scout\Car\VehicleEmailSource;
use Scout\Car\VehicleSourceDefinition;
use Scout\Car\VehicleStore;

/**
 * The car twin of `EmailAlertClaimTest` (row 36): a message is claimed when it passes this
 * source's sender and subject filters — and only then — and a mailbox refusal surfaces.
 */
final class VehicleEmailClaimTest extends TestCase
{
    private const string OURS = "From: info@cars.test\r\nDate: Thu, 03 Sep 2026 10:00:00 +0200\r\nSubject: Voiture d'occasion\r\n\r\nrien\r\n";
    private const string OTHER_SENDER = "From: someone@else.test\r\nDate: Thu, 03 Sep 2026 10:00:00 +0200\r\nSubject: Voiture d'occasion\r\n\r\nrien\r\n";
    private const string OTHER_SUBJECT = "From: info@cars.test\r\nDate: Thu, 03 Sep 2026 10:00:00 +0200\r\nSubject: Newsletter\r\n\r\nrien\r\n";

    public function testOnlyAMessagePassingBothFiltersIsClaimed(): void
    {
        $mailbox = new RecordingCarMailbox([self::OTHER_SUBJECT, self::OTHER_SENDER, self::OURS]);

        $listings = $this->source($mailbox)->fetch();

        self::assertSame([], $listings);
        self::assertSame([2], $mailbox->claimed);
    }

    public function testAcknowledgeDelegatesAndARefusalBecomesASourceError(): void
    {
        $fine = new RecordingCarMailbox([self::OURS]);
        $source = $this->source($fine);
        self::assertInstanceOf(AcknowledgesMessages::class, $source);
        $source->fetch();
        $source->acknowledge();
        self::assertSame(1, $fine->acknowledged);

        $refusing = new RecordingCarMailbox([self::OURS], refuse: new MailboxError('STORE refused'));
        $source = $this->source($refusing);
        $source->fetch();
        try {
            $source->acknowledge();
            self::fail('a refusal must surface');
        } catch (SourceError $e) {
            self::assertStringContainsString('STORE refused', $e->getMessage());
        }
    }

    private function source(Mailbox $mailbox): VehicleEmailSource
    {
        return new VehicleEmailSource(
            new VehicleSourceDefinition(
                name: 'stub_cars',
                enabled: true,
                family: 'portal',
                type: 'email_alert',
                params: ['from' => 'info@cars.test', 'subject_pattern' => "~Voiture d'occasion~u", 'link_host' => 'cars.test/a/'],
            ),
            $mailbox,
        );
    }
}

/** Records claims and acknowledgements; hands out fixed messages. */
final class RecordingCarMailbox implements Mailbox
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
