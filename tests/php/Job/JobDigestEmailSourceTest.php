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

    // The shipped HelloWork and Apec rules, restated so this file tests the ADAPTER and not the config.
    private const string HW_CARD = '~^\\h(?<title>\\S[^\\n]*?)\\h*\\n(?<url>https://emails\\.hellowork\\.com/clic/\\S+)\\h*+\\n(?:\\h*+\\n)*+(?<company>\\S[^\\n]*?)\\h*+\\n(?:\\h*+\\n)*+(?:(?!Voir\\b)\\S[^\\n]*?\\h*+\\n(?:\\h*+\\n)*+){0,3}?(?<place>\\S[^\\n]*?)\\h*+\\n(?:\\h*+\\n)*+(?<contracts>\\S[^\\n]*?)\\h*+\\n(?:\\h*+\\n)*+(?:\\h(?<pay>\\S[^\\n]*?)\\h*+\\n(?:\\h*+\\n)*+)?\\h*Voir l[’\']offre~mu';
    private const string HW_TOKEN = '~/clic/[^/\\s]+/\\d+/[0-9a-f]+/(?<token>[A-Za-z0-9_-]+)~';
    private const string HW_ID = '~/fr-fr/emplois/(\\d+)\\.html~';
    private const string AP_CARD = '~^\\h*(?<title>\\S[^\\n]*?)\\h*+\\n(?:\\h*+\\n)*+\\h*(?<url>https://neomarket\\.diffusion\\.apec\\.fr/r/\\?\\S+)\\h*+\\n(?:\\h*+\\n)*+\\h*(?<company>\\S[^\\n]*?)\\h*+\\n(?:\\h*+\\n)*+\\h*\\k<url>\\h*+\\n(?:\\h*+\\n)*+\\h*(?<contracts>[^\\n•]+?)\\h*•\\h*+\\n(?:\\h*+\\n)*+\\h*\\k<url>\\h*+\\n(?:\\h*+\\n)*+\\h*(?<place>\\S[^\\n]*?)\\h*+\\n(?:\\h*+\\n)*+\\h*\\k<url>~mu';
    private const string AP_TOKEN = '~[?&]e=(?<token>[A-Za-z0-9_-]+)~';
    private const string AP_ID = '~\\bp2=(\\d+)W~';

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

        // The UNION of both shapes: a key only one of them reaches is still a key that must be counted.
        $digest = $this->source(new RecordingMailbox([self::message(self::card('dev/x', 'Dev', 'CDI', 'Paris, France'))]));
        $digest->fetch();
        $token = $this->helloWork(new RecordingMailbox([self::hwMessage(self::hwCard('1', 'Dev H/F', 'Acme', 'Paris - 75', 'CDI'))]));
        $token->fetch();
        $counted = array_values(array_unique([...array_keys($digest->patternMisses()->counts()), ...array_keys($token->patternMisses()->counts())]));
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

    /**
     * HELLOWORK'S SHAPE: THE OFFER ID LIVES ONLY INSIDE A BASE64URL TOKEN, and the token's decoded text
     * carries the offer's own address after the subscriber's. So the id is read from the DECODED token,
     * and the push links the offer directly — without its `utm_*` query — rather than a tracking click.
     * `Suresnes - 92` stays whole: through the ` - ` facts splitter it would read as the place `92`.
     */
    public function testATokenCardReadsItsIdAndLinksTheDirectOfferWithoutItsQuery(): void
    {
        $offers = $this->helloWork(new RecordingMailbox([self::hwMessage(
            self::hwCard('80508708', 'Ingénieur Build Cloud Azure H/F', 'AS International', 'Suresnes - 92', 'CDI', '55 000 - 65 000 € / an', 'Super recruteur'),
        )]))->fetch();

        self::assertCount(1, $offers);
        $o = $offers[0];
        self::assertSame('80508708', $o->externalId);
        self::assertSame('Ingénieur Build Cloud Azure H/F', $o->title);
        self::assertSame('AS International', $o->company);
        self::assertSame('Suresnes - 92', $o->location, 'a place group is the location whole, never split on " - "');
        self::assertSame(['CDI'], $o->contracts);
        self::assertSame('55 000 - 65 000 € / an', $o->payText, 'a pay group states its own unit; JobPay reads it as written');
        self::assertSame([], $o->fields);
        self::assertSame('https://www.hellowork.com/fr-fr/emplois/80508708.html', $o->url);
    }

    /** No badge, no pay: the optional lines are optional, and the next card is not swallowed. */
    public function testATokenCardWithoutBadgeOrPayIsReadAndTheNextCardToo(): void
    {
        $offers = $this->helloWork(new RecordingMailbox([self::hwMessage(
            self::hwCard('76017017', "Architecte Technique des Systèmes d'Information ERP H/F", 'Safran - CDI', 'Corbeil-Essonnes - 91', 'CDI')
            . self::hwCard('82621905', 'Chef de Projet Senior Aws H/F', 'Socadek Solutions', 'Île-de-France', 'CDI'),
        )]))->fetch();

        self::assertSame(['76017017', '82621905'], self::ids($offers));
        self::assertSame('Safran - CDI', $offers[0]->company, 'a " - " inside the company is the company');
        self::assertNull($offers[0]->payText);
        self::assertSame('Île-de-France', $offers[1]->location);
    }

    /**
     * APEC'S SHAPE: the token decodes to `p1=…&p2=<id>W…`, which is no URL at all. The card link then
     * stays WHOLE — its query IS the link, where Free-Work's query is only a campaign tag.
     */
    public function testATokenCarryingNoUrlKeepsTheCardLinkWhole(): void
    {
        $link = self::apLink('179472775');
        $offers = $this->apec(new RecordingMailbox([self::apMessage(self::apCard($link, 'Lead Développeur Full-Stack F/H', 'SKAELIA', 'Paris 10 - 75'))]))->fetch();

        self::assertCount(1, $offers);
        self::assertSame('179472775', $offers[0]->externalId);
        self::assertSame('SKAELIA', $offers[0]->company);
        self::assertSame(['CDI'], $offers[0]->contracts, 'the trailing bullet is not part of the contract');
        self::assertSame('Paris 10 - 75', $offers[0]->location);
        self::assertSame($link, $offers[0]->url);
    }

    /** A card link carrying no token is not a card, and the TOKEN rule's miss is the one counted. */
    public function testALinkWithoutATokenIsCountedAndTheCardDropped(): void
    {
        $source = $this->apec(new RecordingMailbox([self::apMessage(self::apCard('https://neomarket.diffusion.apec.fr/r/?id=FIXTURE&s=FIXTURE', 'Dev F/H', 'Acme', 'Paris - 75'))]));

        self::assertSame([], $source->fetch());
        self::assertSame(1, $source->patternMisses()->counts()['id_token_pattern']['misses'] ?? null);
    }

    /** A token that decodes but names no offer is counted under the ID rule, the half that failed. */
    public function testATokenNamingNoOfferIsCountedUnderTheIdPattern(): void
    {
        $token = rtrim(strtr(base64_encode('p1=www.apec.fr&p3='), '+/', '-_'), '=');
        $source = $this->apec(new RecordingMailbox([self::apMessage(self::apCard('https://neomarket.diffusion.apec.fr/r/?id=FIXTURE&e=' . $token . '&s=FIXTURE', 'Dev F/H', 'Acme', 'Paris - 75'))]));

        self::assertSame([], $source->fetch());
        self::assertSame(0, $source->patternMisses()->counts()['id_token_pattern']['misses'] ?? null);
        self::assertSame(1, $source->patternMisses()->counts()['id_pattern']['misses'] ?? null);
    }

    public function testTheLoaderRefusesACardPatternWithNeitherFactsNorPlace(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessage('place');

        JobSourceLoader::fromArray(['sources' => ['hw' => [
            'enabled' => false, 'family' => 'portal', 'type' => 'email_digest',
            'params' => ['card_pattern' => '~(?<title>.+) (?<company>.+) (?<url>\S+)~'],
        ]]]);
    }

    /** A token rule without its `token` group decodes nothing and would count every card as a hit. */
    public function testTheLoaderRefusesATokenPatternWithoutItsTokenGroup(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessage('token');

        JobSourceLoader::fromArray(['sources' => ['hw' => [
            'enabled' => false, 'family' => 'portal', 'type' => 'email_digest',
            'params' => ['id_token_pattern' => '~/clic/(.+)~'],
        ]]]);
    }

    public function testTheLoaderAcceptsAPlaceShapedCardWithATokenRule(): void
    {
        $sources = JobSourceLoader::fromArray(['sources' => ['hw' => [
            'enabled' => true, 'family' => 'portal', 'type' => 'email_digest',
            'params' => ['from' => 'x@y.test', 'card_pattern' => self::HW_CARD, 'id_token_pattern' => self::HW_TOKEN, 'id_pattern' => self::HW_ID],
        ]]]);

        self::assertSame(self::HW_TOKEN, $sources['hw']->param('id_token_pattern'));
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

    private static function hwCard(string $id, string $title, string $company, string $place, string $contracts, ?string $pay = null, ?string $badge = null): string
    {
        $token = rtrim(strtr(base64_encode("alertes@example.invalid\u{1FAA2}https://www.hellowork.com/fr-fr/emplois/{$id}.html?utm_source=jobalert&utm_term={$id}"), '+/', '-_'), '=');
        $link = "https://emails.hellowork.com/clic/ffffffff-0000-4000-8000-000000000004/3/f0f0f0f0200000000000000000000000/{$token}";

        return " {$title} \n{$link}\n\n{$company}\n\n" . ($badge === null ? '' : "{$badge}\n\n") . "{$place}\n\n{$contracts}\n\n"
            . ($pay === null ? '' : " {$pay} \n\n") . " Voir l’offre\n{$link}\n\n";
    }

    private static function hwMessage(string $body): string
    {
        return "From: Hellowork Alert <alerte@emails.hellowork.com>\r\nTo: <alertes@example.invalid>\r\nSubject: abonne, Hellowork a trouvé 1 nouvelles offres d'emploi rien que pour vous !\r\n"
            . "Date: Thu, 24 Sep 2026 08:27:46 +0200\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n\r\n" . $body;
    }

    private static function apLink(string $id): string
    {
        return 'https://neomarket.diffusion.apec.fr/r/?id=FIXTURE&e=' . rtrim(strtr(base64_encode("p1=www.apec.fr&p2={$id}W&p3=&xtor=EPR-41-[push_avec_compte]"), '+/', '-_'), '=') . '&s=FIXTURE';
    }

    /** The logo-less card: every field is followed by the same offer link. */
    private static function apCard(string $link, string $title, string $company, string $place): string
    {
        return "\n                      {$title}\n{$link}\n\n                      {$company}\n{$link}\n\n                      CDI\u{a0}•\n{$link}\n\n                      {$place}\n{$link}\n";
    }

    private static function apMessage(string $body): string
    {
        return "From: \"APEC\" <offres@diffusion.apec.fr>\r\nTo: <alertes@example.invalid>\r\nSubject: 552 offres Apec du 24/09/2026\r\n"
            . "Date: Thu, 24 Sep 2026 07:21:50 +0200\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n" . $body;
    }

    private function helloWork(Mailbox $mailbox): JobDigestEmailSource
    {
        return new JobDigestEmailSource(
            new JobSourceDefinition('hellowork', true, 'portal', 'email_digest', [
                'from' => 'alerte@emails.hellowork.com', 'card_pattern' => self::HW_CARD, 'id_token_pattern' => self::HW_TOKEN, 'id_pattern' => self::HW_ID,
            ]),
            JobStore::open(':memory:'),
            $mailbox,
        );
    }

    private function apec(Mailbox $mailbox): JobDigestEmailSource
    {
        return new JobDigestEmailSource(
            new JobSourceDefinition('apec', true, 'portal', 'email_digest', [
                'from' => 'offres@diffusion.apec.fr', 'card_pattern' => self::AP_CARD, 'id_token_pattern' => self::AP_TOKEN, 'id_pattern' => self::AP_ID,
            ]),
            JobStore::open(':memory:'),
            $mailbox,
        );
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
