<?php

declare(strict_types=1);

namespace Scout\Tests\Job;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Adapters\AcknowledgesMessages;
use Scout\Adapters\Mail\Mailbox;
use Scout\Adapters\Mail\MailboxError;
use Scout\Adapters\SourceError;
use Scout\Core\SourceStatus;
use Scout\Job\JobEmailSource;
use Scout\Job\JobListing;
use Scout\Job\JobSourceDefinition;
use Scout\Job\JobStore;

/**
 * The job email adapter on synthetic alerts built in LinkedIn's shape: each card links its logo and
 * its title to the same `/jobs/view/<id>/` URL, a preheader sits above the first card, and a footer
 * block follows the last. Every boundary the real template relies on is exercised here on purpose,
 * because the three frozen alerts cannot reach most of them.
 */
#[CoversClass(JobEmailSource::class)]
final class JobEmailSourceTest extends TestCase
{
    private const string SENDER = 'jobalerts-noreply@linkedin.com';
    private const string NOW = '2026-09-13T12:00:00+00:00';

    public function testACardReadsItsTitleCompanyLocationModePayAndLabels(): void
    {
        $offers = $this->fetch([self::message(self::card('111', 'Senior Engineer', 'Acme · Montrouge (Hybride)', ['Entre 60 k € et 70 k € par an', 'Recrutement actif']))]);

        self::assertCount(1, $offers, 'a doubled logo/title link is ONE card');
        $o = $offers[0];
        self::assertSame('111', $o->externalId);
        self::assertSame('Senior Engineer', $o->title);
        self::assertSame('Acme', $o->company);
        self::assertSame('Montrouge', $o->location);
        self::assertSame('hybrid', $o->workMode);
        self::assertSame('Entre 60 k € et 70 k € par an', $o->payText);
        self::assertSame(['labels' => 'Recrutement actif'], $o->fields);
    }

    public function testEachModeWordMapsToItsCanonicalValue(): void
    {
        $offers = $this->fetch([self::message(
            self::card('1', 'A', 'Co · Paris (Hybride)') . self::card('2', 'B', 'Co · France (À distance)') . self::card('3', 'C', 'Co · Paris (Sur site)'),
        )]);

        self::assertSame(['hybrid', 'remote', 'onsite'], array_map(static fn (JobListing $o): ?string => $o->workMode, $offers));
    }

    /** The preheader states the ALERT's pay filter, not a card's pay. It must never become an offer's pay. */
    public function testThePreheaderAboveTheFirstCardIsNeverRead(): void
    {
        $offers = $this->fetch([self::message(self::card('111', 'Senior Engineer', 'Acme · Paris (Hybride)'), preheader: 'Salaire entre 90 k € et 120 k € par an')]);

        self::assertCount(1, $offers);
        self::assertNull($offers[0]->payText);
        self::assertSame([], $offers[0]->fields);
    }

    /** The footer carries other alerts' cards. Nothing from the marker on is read. */
    public function testNothingFromTheFooterMarkerOnIsRead(): void
    {
        $offers = $this->fetch([self::message(self::card('111', 'Senior Engineer', 'Acme · Paris (Hybride)'), footerCards: self::card('999', 'Footer Card', 'Other · Lyon (Sur site)'))]);

        self::assertSame(['111'], array_map(static fn (JobListing $o): string => $o->externalId, $offers));
    }

    /** DEGRADED, not silent: with the footer marker gone every card is still read, and the miss is counted. */
    public function testAMissingFooterMarkerReadsToTheEndAndIsCounted(): void
    {
        $warnings = [];
        $source = $this->source(new StubJobMailbox([self::message(self::card('111', 'Senior Engineer', 'Acme · Paris (Hybride)'), footer: 'Ne plus recevoir', footerCards: self::card('111', 'Senior Engineer', 'Acme · Paris (Hybride)') . self::card('222', 'Other', 'Beta · Paris (Hybride)'))]), $warnings);

        $offers = $source->fetch();

        self::assertSame(['111', '222'], array_map(static fn (JobListing $o): string => $o->externalId, $offers));
        self::assertSame(['calls' => 1, 'misses' => 1], $source->patternMisses()->counts()['footer_marker']);
        self::assertCount(1, $warnings, 'the repeated card is dropped and said out loud');
    }

    public function testAnIdRepeatedInOneMessageIsKeptOnceAndWarned(): void
    {
        $warnings = [];
        $source = $this->source(new StubJobMailbox([self::message(
            self::card('111', 'First', 'Acme · Paris (Hybride)') . self::card('222', 'Second', 'Beta · Paris (Hybride)') . self::card('111', 'First again', 'Acme · Paris (Hybride)'),
        )]), $warnings);

        $offers = $source->fetch();

        self::assertSame(['111', '222'], array_map(static fn (JobListing $o): string => $o->externalId, $offers));
        self::assertSame('First', $offers[0]->title, 'the first reading is kept');
        self::assertCount(1, $warnings);
        self::assertStringContainsString('111', $warnings[0]);
    }

    /** An unknown parenthesis is not a mode. The whole text stays the location and the mode stays unknown. */
    public function testAnUnknownModeWordKeepsTheWholeLocationAndIsCounted(): void
    {
        $source = $this->source(new StubJobMailbox([self::message(self::card('1', 'A', 'Co · Lyon (Rhône)'))]));

        $offers = $source->fetch();

        self::assertSame('Lyon (Rhône)', $offers[0]->location);
        self::assertNull($offers[0]->workMode);
        self::assertSame(['calls' => 1, 'misses' => 1], $source->patternMisses()->counts()['mode_word']);
    }

    /** A place with no parenthesis states no mode, legitimately. It must not dilute the mode ratio. */
    public function testAPlaceWithoutParenthesesIsNeitherAModeNorAMiss(): void
    {
        $source = $this->source(new StubJobMailbox([self::message(self::card('1', 'A', 'TotalEnergies · Ville de Paris'))]));

        $offers = $source->fetch();

        self::assertSame('TotalEnergies', $offers[0]->company);
        self::assertSame('Ville de Paris', $offers[0]->location);
        self::assertNull($offers[0]->workMode);
        self::assertArrayNotHasKey('mode_word', $source->patternMisses()->counts());
        self::assertSame(['calls' => 1, 'misses' => 0], $source->patternMisses()->counts()['place_pattern']);
    }

    /** A card whose place line is missing is still an offer, with its place unknown and the miss counted. */
    public function testACardWithoutAPlaceLineIsStillAnOfferAndTheMissIsCounted(): void
    {
        $source = $this->source(new StubJobMailbox([self::message(self::card('1', 'Just a title', null))]));

        $offers = $source->fetch();

        self::assertCount(1, $offers);
        self::assertSame('Just a title', $offers[0]->title);
        self::assertSame('', $offers[0]->company);
        self::assertSame('', $offers[0]->location);
        self::assertNull($offers[0]->workMode);
        self::assertSame(['calls' => 1, 'misses' => 1], $source->patternMisses()->counts()['place_pattern']);
    }

    /** The template's invisible characters: U+034F and no-break spaces never reach a field. */
    public function testInvisibleCharactersAreNormalised(): void
    {
        $offers = $this->fetch([self::message(self::card('1', "Senior\u{00A0}Engineer\u{034F}", "Acme\u{00A0}·\u{00A0}Paris\u{034F} (Hybride)", ["Entre\u{00A0}60\u{00A0}k\u{00A0}€ et 70\u{00A0}k\u{00A0}€ par an"]))]);

        self::assertSame('Senior Engineer', $offers[0]->title);
        self::assertSame('Acme', $offers[0]->company);
        self::assertSame('Paris', $offers[0]->location);
        self::assertSame('hybrid', $offers[0]->workMode);
        self::assertSame('Entre 60 k € et 70 k € par an', $offers[0]->payText);
    }

    public function testTheUrlIsTheViewLinkWithoutItsTrackingQuery(): void
    {
        $offers = $this->fetch([self::message(self::card('4461976159', 'A', 'Co · Paris (Hybride)'))]);

        self::assertSame('https://www.linkedin.com/comm/jobs/view/4461976159/', $offers[0]->url);
    }

    /** Hard rule 9: observed when the alert was sent; published date unknown, never the send date. */
    public function testObservedAtIsTheSendInstantAndPublishedAtStaysUnknown(): void
    {
        $offers = $this->fetch([self::message(self::card('1', 'A', 'Co · Paris (Hybride)'), date: 'Fri, 11 Sep 2026 12:49:28 +0200')]);

        self::assertSame('2026-09-11T10:49:28Z', $offers[0]->observedAt);
        self::assertNull($offers[0]->publishedAt);
    }

    public function testOnlyTheSendersMessagesAreClaimed(): void
    {
        $mailbox = new StubJobMailbox([
            self::message(self::card('1', 'A', 'Co · Paris (Hybride)'), from: 'someone@else.test'),
            self::message(self::card('2', 'B', 'Co · Paris (Hybride)')),
        ]);

        $offers = $this->source($mailbox)->fetch();

        self::assertSame(['2'], array_map(static fn (JobListing $o): string => $o->externalId, $offers));
        self::assertSame([1], $mailbox->claimed);
    }

    public function testAMailboxFailureIsASourceErrorNeverAnEmptyList(): void
    {
        $this->expectException(SourceError::class);
        $this->expectExceptionMessage('IMAP down');
        $this->source(new StubJobMailbox([], fail: new MailboxError('IMAP down')))->fetch();
    }

    public function testAcknowledgeDelegatesAndARefusalBecomesASourceError(): void
    {
        $fine = new StubJobMailbox([self::message(self::card('1', 'A', 'Co · Paris (Hybride)'))]);
        $source = $this->source($fine);
        self::assertInstanceOf(AcknowledgesMessages::class, $source);
        $source->fetch();
        $source->acknowledge();
        self::assertSame(1, $fine->acknowledged);

        $refusing = new StubJobMailbox([], refuse: new MailboxError('STORE refused'));
        try {
            $this->source($refusing)->acknowledge();
            self::fail('a refusal must surface');
        } catch (SourceError $e) {
            self::assertStringContainsString('STORE refused', $e->getMessage());
        }
    }

    /**
     * A CLAIMED ALERT WITH NO CARD IS A TEMPLATE CHANGE, NOT A QUIET MARKET.
     *
     * The adapter returns no offer, which is correct, and the source's health must say why: three
     * messages from the sender, not one card link among them, is the template moving under the pattern.
     */
    public function testClaimedAlertsWithNoCardEscalateHealth(): void
    {
        $store = JobStore::open(':memory:');
        foreach (['2026-09-10T09:00:00+00:00', '2026-09-11T09:00:00+00:00', '2026-09-12T09:00:00+00:00'] as $at) {
            $store->runs()->recordRun('linkedin', 5, true, null, $at, 20);
        }
        $blank = self::message('<p>Nouvelle mise en page sans lien</p>');
        $warnings = [];
        $source = $this->source(new StubJobMailbox([$blank, $blank, $blank]), $warnings, $store);

        self::assertSame([], $source->fetch());
        // The synthetic footer is still there, so only the card links are blind.
        self::assertSame(['card_link_pattern'], $source->patternMisses()->total());
        $health = $source->health(self::NOW);
        self::assertSame(SourceStatus::WARN_DROP, $health->status);
        self::assertStringContainsString('card_link_pattern', $health->detail);
    }

    /** The counterweight: healthy alerts leave an OK verdict untouched. */
    public function testHealthyAlertsLeaveHealthUntouched(): void
    {
        $store = JobStore::open(':memory:');
        foreach (['2026-09-10T09:00:00+00:00', '2026-09-11T09:00:00+00:00', '2026-09-12T09:00:00+00:00'] as $at) {
            $store->runs()->recordRun('linkedin', 5, true, null, $at, 20);
        }
        $ok = self::message(self::card('1', 'A', 'Co · Paris (Hybride)'));
        $warnings = [];
        $source = $this->source(new StubJobMailbox([$ok, $ok, $ok]), $warnings, $store);
        $source->fetch();

        self::assertSame(SourceStatus::OK, $source->health(self::NOW)->status);
    }

    public function testTheCountNeverSpansTwoFetches(): void
    {
        $source = $this->source(new StubJobMailbox([self::message(self::card('1', 'A', 'Co · Paris (Hybride)'))]));
        $source->fetch();
        $first = $source->patternMisses()->counts();
        $source->fetch();

        self::assertSame($first, $source->patternMisses()->counts());
    }

    public function testTheSourceIsAnEmailSourceWithNoHost(): void
    {
        $source = $this->source(new StubJobMailbox([]));

        self::assertSame('linkedin', $source->name());
        self::assertSame('portal', $source->family());
        self::assertNull($source->host());
        self::assertNull($source->newestFeedItemAt());
    }

    /**
     * @param list<string> $messages
     *
     * @return list<JobListing>
     */
    private function fetch(array $messages): array
    {
        return $this->source(new StubJobMailbox($messages))->fetch();
    }

    /** @param list<string> $warnings */
    private function source(Mailbox $mailbox, array &$warnings = [], ?JobStore $store = null): JobEmailSource
    {
        return new JobEmailSource(
            new JobSourceDefinition(
                name: 'linkedin',
                enabled: true,
                family: 'portal',
                type: 'email_alert',
                params: [
                    'from' => self::SENDER,
                    'card_link_pattern' => '~^https://www\.linkedin\.com/comm/jobs/view/(\d+)/~',
                    'footer_marker' => 'Voir toutes les offres',
                    'place_pattern' => '~^(?<company>.+) · (?<location>.+?)(?: \((?<mode>[^()]+)\))?$~u',
                    'pay_pattern' => '~€~u',
                ],
            ),
            $store ?? JobStore::open(':memory:'),
            $mailbox,
            static function (string $w) use (&$warnings): void {
                $warnings[] = $w;
            },
        );
    }

    /**
     * One card in LinkedIn's shape: the logo link, the title link, the place line, then extra lines.
     *
     * @param list<string> $extra
     */
    private static function card(string $id, string $title, ?string $place, array $extra = []): string
    {
        $url = 'https://www.linkedin.com/comm/jobs/view/' . $id . '/?trackingId=abc%3D&amp;refId=xyz';
        $rows = '<tr><td><a href="' . $url . '"><img src="https://media.licdn.com/logo.png" alt=""></a></td></tr>'
            . '<tr><td><a href="' . $url . '">' . $title . '</a></td></tr>';
        if ($place !== null) {
            $rows .= '<tr><td><p>' . $place . '</p></td></tr>';
        }
        foreach ($extra as $line) {
            $rows .= '<tr><td><p>' . $line . '</p></td></tr>';
        }

        return '<table>' . $rows . '</table>';
    }

    private static function message(
        string $cards,
        string $from = self::SENDER,
        string $date = 'Fri, 11 Sep 2026 12:49:28 +0200',
        string $preheader = 'De nouvelles offres correspondent à vos préférences',
        string $footer = 'Voir toutes les offres',
        string $footerCards = '',
    ): string {
        $html = '<html><body><p>' . $preheader . '</p>' . $cards
            . '<p><a href="https://www.linkedin.com/comm/jobs/search/?x=1">' . $footer . '</a></p>'
            . '<p>Vos autres alertes</p>' . $footerCards . '</body></html>';

        return "From: LinkedIn <" . $from . ">\r\nDate: " . $date . "\r\nSubject: Offres\r\nMIME-Version: 1.0\r\n"
            . "Content-Type: multipart/alternative; boundary=\"b1\"\r\n\r\n"
            . "--b1\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\nVersion texte\r\n"
            . "--b1\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n" . $html . "\r\n--b1--\r\n";
    }
}

/** Hands out fixed messages; records claims and acknowledgements; can fail either way. */
final class StubJobMailbox implements Mailbox
{
    /** @var list<int> */
    public array $claimed = [];

    public int $acknowledged = 0;

    /** @param list<string> $messages */
    public function __construct(
        private readonly array $messages,
        private readonly ?MailboxError $fail = null,
        private readonly ?MailboxError $refuse = null,
    ) {}

    public function fetchRecent(int $limit = 50): array
    {
        if ($this->fail !== null) {
            throw $this->fail;
        }

        return array_slice($this->messages, 0, $limit);
    }

    public function describe(): string
    {
        return 'stub';
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
        if ($this->refuse !== null) {
            throw $this->refuse;
        }
        ++$this->acknowledged;
    }
}
