<?php

declare(strict_types=1);

namespace Scout\Tests\Job;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Adapters\Mail\Mailbox;
use Scout\Config\ConfigError;
use Scout\Job\JobDigestEmailSource;
use Scout\Job\JobListing;
use Scout\Job\JobSourceDefinition;
use Scout\Job\JobSourceLoader;
use Scout\Job\JobStore;

/**
 * The digest adapter on synthetic messages in Free-Work's text-part shape:
 * `- [ **<title> -  <contracts>** ⏎ <facts> ](<url>)`, facts being ` - `-separated segments whose LAST
 * is the place. Every boundary the one real capture cannot reach is built here on purpose.
 */
#[CoversClass(JobDigestEmailSource::class)]
#[CoversClass(JobSourceLoader::class)]
final class JobDigestEmailSourceTest extends TestCase
{
    private const string SENDER = 'jobs@free-work.com';
    private const string SUBJECT = '12 offres matchant avec vos critères';

    public function testACardReadsItsTitleContractsPlacePayAndDuration(): void
    {
        $offers = $this->fetch([self::message(self::card('dev/a-1', 'Développeur PHP', 'Freelance, CDI', '12 mois - 55k-65k € - 500-600 € - Paris, France'))]);

        self::assertCount(1, $offers);
        $o = $offers[0];
        self::assertSame('dev/a-1', $o->externalId);
        self::assertSame('Développeur PHP', $o->title);
        self::assertSame(['Freelance', 'CDI'], $o->contracts);
        self::assertSame('Paris, France', $o->location);
        self::assertSame("salaire annuel 55k-65k €\nTJM 500-600 € par jour", $o->payText);
        self::assertSame(['labels' => '12 mois'], $o->fields);
        self::assertSame('https://www.free-work.com/fr/tech-it/job-mission/dev/a-1', $o->url);
    }

    /** The template's own trap: the first card of a section shares the section header's line. */
    public function testACardGluedToTheSectionHeaderIsRead(): void
    {
        $body = '![bell](cid:x) 3 nouvelles offres correspondant à votre alerte **Dev IDF**' . ltrim(self::card('dev/glued', 'Glued Dev', 'CDI', '60k-70k € - Paris, France'))
            . self::card('dev/next', 'Next Dev', 'CDI', '60k-70k € - Paris, France');

        self::assertSame(['dev/glued', 'dev/next'], self::ids($this->fetch([self::message($body)])));
    }

    /** Two sections list the same offer: one listing, and NO warning — the overlap is the template, not a fault. */
    public function testAnIdenticalRepeatAcrossSectionsIsKeptOnceInSilence(): void
    {
        $card = self::card('dev/same', 'Dev', 'CDI', '60k-70k € - Paris, France');
        $warnings = [];
        $offers = $this->fetch([self::message($card . $card)], $warnings);

        self::assertSame(['dev/same'], self::ids($offers));
        self::assertSame([], $warnings);
    }

    /** The same id with DIFFERENT text is not the template repeating itself: one is kept, and it is said. */
    public function testARepeatWithDifferentTextIsKeptOnceAndWarned(): void
    {
        $warnings = [];
        $offers = $this->fetch([self::message(
            self::card('dev/same', 'Dev', 'CDI', '60k-70k € - Paris, France') . self::card('dev/same', 'Dev', 'CDI', '40k-45k € - Paris, France'),
        )], $warnings);

        self::assertCount(1, $offers);
        self::assertSame('salaire annuel 60k-70k €', $offers[0]->payText, 'the FIRST reading is kept');
        self::assertCount(1, $warnings);
        self::assertStringContainsString('dev/same', $warnings[0]);
    }

    /** A message from the sender whose subject is not an alert's is not claimed and yields nothing. */
    public function testAMessageOutsideTheSubjectPatternIsNotClaimed(): void
    {
        $mailbox = new RecordingMailbox([self::message(self::card('dev/x', 'Dev', 'CDI', 'Paris, France'), subject: '6 mois sans mise à jour')]);

        self::assertSame([], $this->source($mailbox)->fetch());
        self::assertSame([], $mailbox->claimed);
    }

    public function testAMessageFromAnotherSenderIsIgnored(): void
    {
        self::assertSame([], $this->fetch([self::message(self::card('dev/x', 'Dev', 'CDI', 'Paris, France'), from: 'contact@free-work.com')]));
    }

    /** A claimed alert with no card is a template change, counted — never a quiet day. */
    public function testAClaimedAlertWithNoCardCountsAMiss(): void
    {
        // Three, because `total()` speaks only from three attempts — a daily digest reaches that inside the IMAP window.
        $empty = self::message('Aucune offre lisible ici.');
        $source = $this->source(new RecordingMailbox([$empty, $empty, $empty]));
        $source->fetch();

        self::assertSame(3, $source->patternMisses()->counts()['card_pattern']['misses'] ?? null);
        self::assertNotSame([], $source->patternMisses()->total(), 'every claimed message missed: the 100 % that escalates');
    }

    /** A URL the id pattern cannot read is not a card, and the miss is counted. */
    public function testAnUnreadableIdIsCountedAndTheCardDropped(): void
    {
        $source = $this->source(new RecordingMailbox([self::message(str_replace('/job-mission/', '/elsewhere/', self::card('dev/x', 'Dev', 'CDI', 'Paris, France')))]), 'https://www\\.free-work\\.com/fr/tech-it/[^)\\s]+');

        self::assertSame([], $source->fetch());
        self::assertSame(1, $source->patternMisses()->counts()['id_pattern']['misses'] ?? null);
    }

    /** A segment that is neither a salary, a day rate nor the place is kept as a label, never guessed at. */
    public function testAnUnknownSegmentBecomesALabelAndNoPay(): void
    {
        $offers = $this->fetch([self::message(self::card('dev/x', 'Dev', 'Freelance', '3 mois - selon profil - Paris, France'))]);

        self::assertNull($offers[0]->payText);
        self::assertSame(['labels' => '3 mois | selon profil'], $offers[0]->fields);
        self::assertSame('Paris, France', $offers[0]->location);
    }

    /**
     * EVERY RULE A DIGEST SOURCE READS IS COUNTED, by set membership: `READ_PARAMS['email_digest']` by
     * reflection, minus the two scope keys (`from`, `subject_pattern` — a filter rejecting mail is the
     * filter working) and the two pay patterns (most cards state no pay). A rule added tomorrow and
     * instrumented by nobody fails here rather than going dark, the LinkedIn guard's shape.
     */
    public function testEveryReadParamExceptScopeAndPayIsCounted(): void
    {
        /** @var array<string, list<string>> $read */
        $read = (new \ReflectionClass(JobSourceLoader::class))->getConstant('READ_PARAMS');
        $expected = array_values(array_diff($read['email_digest'], ['from', 'subject_pattern', 'salary_pattern', 'tjm_pattern']));
        sort($expected);

        $source = $this->source(new RecordingMailbox([self::message(self::card('dev/x', 'Dev', 'CDI', 'Paris, France'))]));
        $source->fetch();
        $counted = array_keys($source->patternMisses()->counts());
        sort($counted);

        self::assertSame($expected, $counted);
    }

    public function testTheLoaderRefusesAnEnabledDigestWithoutItsCardPattern(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessage('card_pattern');

        JobSourceLoader::fromArray(['sources' => ['fw' => [
            'enabled' => true, 'family' => 'portal', 'type' => 'email_digest',
            'params' => ['from' => self::SENDER, 'id_pattern' => '~(x)~'],
        ]]]);
    }

    public function testTheLoaderRefusesACardPatternMissingAGroupTheAdapterReads(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessage('facts');

        JobSourceLoader::fromArray(['sources' => ['fw' => [
            'enabled' => false, 'family' => 'portal', 'type' => 'email_digest',
            'params' => ['card_pattern' => '~(?<title>.+) (?<url>\S+)~'],
        ]]]);
    }

    /** The LinkedIn shape's own params are not read by a digest source, and saying so beats ignoring them. */
    public function testTheLoaderRefusesALinkedInParamOnADigestSource(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessage('place_pattern');

        JobSourceLoader::fromArray(['sources' => ['fw' => [
            'enabled' => false, 'family' => 'portal', 'type' => 'email_digest',
            'params' => ['place_pattern' => '~(?<company>a)(?<location>b)~'],
        ]]]);
    }

    /** @param list<JobListing> $offers @return list<string> */
    private static function ids(array $offers): array
    {
        return array_map(static fn (JobListing $o): string => $o->externalId, $offers);
    }

    private static function card(string $id, string $title, string $contracts, string $facts): string
    {
        return "- [ **{$title} -  {$contracts}** \n     {$facts} ](https://www.free-work.com/fr/tech-it/job-mission/{$id}?mtm_campaign=user_alert_missions)\n";
    }

    private static function message(string $body, string $from = self::SENDER, string $subject = self::SUBJECT): string
    {
        return "From: Free-Work <{$from}>\r\nTo: <alertes@example.invalid>\r\nSubject: {$subject}\r\nDate: Thu, 24 Sep 2026 08:27:46 +0200\r\n"
            . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n" . $body;
    }

    /**
     * @param list<string> $messages
     * @param list<string> $warnings
     *
     * @return list<JobListing>
     */
    private function fetch(array $messages, array &$warnings = []): array
    {
        return $this->source(new RecordingMailbox($messages), null, static function (string $w) use (&$warnings): void {
            $warnings[] = $w;
        })->fetch();
    }

    /** @param ?\Closure(string): void $warn */
    private function source(Mailbox $mailbox, ?string $urlPattern = null, ?\Closure $warn = null): JobDigestEmailSource
    {
        $url = $urlPattern ?? 'https://www\\.free-work\\.com/fr/tech-it/job-mission/[^)\\s]+';

        return new JobDigestEmailSource(
            new JobSourceDefinition('freework', true, 'portal', 'email_digest', [
                'from' => self::SENDER,
                'subject_pattern' => '~\\boffres? matchant\\b~u',
                'card_pattern' => '~- \\[ \\*\\*(?<title>.+?) -  (?<contracts>[^*\\n]+?)\\*\\* *\\n\\s*(?<facts>[^\\n]*?) \\]\\((?<url>' . $url . ')\\)~u',
                'id_pattern' => '~/job-mission/([^?#]+)~',
                'salary_pattern' => '~^\\d+k(?:-\\d+k)? €$~u',
                'tjm_pattern' => '~^\\d+(?:-\\d+)? €$~u',
            ]),
            JobStore::open(':memory:'),
            $mailbox,
            $warn,
        );
    }
}

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
