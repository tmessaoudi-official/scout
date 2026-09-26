<?php

declare(strict_types=1);

namespace Scout\Tests\Job;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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
    private const string AP_CARD = '~^\\h*(?<title>\\S[^\\n]*?)\\h*+\\n(?:\\h*+\\n)*+\\h*(?<url>https://neomarket\\.diffusion\\.apec\\.fr/r/\\?\\S*?\\be=(?<e>[A-Za-z0-9_-]+)\\S*)\\h*+\\n(?:\\h*+\\n)*+\\h*(?<company>\\S[^\\n]*?)\\h*+\\n(?:\\h*+\\n)*+\\h*https://neomarket\\.diffusion\\.apec\\.fr/r/\\?\\S*?\\be=\\k<e>(?![A-Za-z0-9_-])\\S*\\h*+\\n(?:\\h*+\\n)*+\\h*(?<contracts>[^\\n•]+?)\\h*•\\h*+\\n(?:\\h*+\\n)*+\\h*https://neomarket\\.diffusion\\.apec\\.fr/r/\\?\\S*?\\be=\\k<e>(?![A-Za-z0-9_-])\\S*\\h*+\\n(?:\\h*+\\n)*+\\h*(?<place>\\S[^\\n]*?)\\h*+\\n(?:\\h*+\\n)*+\\h*https://neomarket\\.diffusion\\.apec\\.fr/r/\\?\\S*?\\be=\\k<e>(?![A-Za-z0-9_-])\\S*~mu';
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
        $expected = array_values(array_diff($read['email_digest'], ['from', 'subject_pattern', 'salary_pattern', 'tjm_pattern', 'place_absent']));
        sort($expected);

        // The UNION of both shapes: a key only one of them reaches is still a key that must be counted.
        $digest = $this->source(new RecordingMailbox([self::message(self::card('dev/x', 'Dev', 'CDI', 'Paris, France'))]));
        $digest->fetch();
        $token = $this->helloWork(new RecordingMailbox([self::hwMessage(self::hwCard('1', 'Dev H/F', 'Acme', 'Paris - 75', 'CDI'))]));
        $token->fetch();
        $subject = $this->collective(new RecordingMailbox([self::coMessage('Acme', 'Dev', 'cabc')]));
        $subject->fetch();
        $counted = array_values(array_unique([...array_keys($digest->patternMisses()->counts()), ...array_keys($token->patternMisses()->counts()), ...array_keys($subject->patternMisses()->counts())]));
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

    /** @return iterable<string, array{string, string}> */
    public static function patternsMissingAReadGroup(): iterable
    {
        yield 'no title' => ['~(?<facts>.+) (?<url>\S+)~', 'title'];
        yield 'no url' => ['~(?<title>.+) (?<facts>.+)~', 'url'];
    }

    /**
     * Every pattern here names `facts`, so the place check passes and only the title/url loop can
     * refuse it. The old single case named neither place group, so the place check refused it first
     * and the loop could be deleted with the suite still green (issue #17).
     */
    #[DataProvider('patternsMissingAReadGroup')]
    public function testTheLoaderRefusesACardPatternMissingAGroupTheAdapterReads(string $pattern, string $group): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessage('groupe « ' . $group . ' »');

        JobSourceLoader::fromArray(['sources' => ['fw' => [
            'enabled' => false, 'family' => 'portal', 'type' => 'email_digest',
            'params' => ['card_pattern' => $pattern],
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

    /** A card link carrying no token is not an offer, and the TOKEN rule's miss is the one counted. */
    public function testALinkWithoutATokenIsCountedAndTheCardDropped(): void
    {
        $card = (string) preg_replace('~(/clic/[^/\s]+/\d+/[0-9a-f]+)/[A-Za-z0-9_-]+~', '$1', self::hwCard('1', 'Dev H/F', 'Acme', 'Paris - 75', 'CDI'));
        $source = $this->helloWork(new RecordingMailbox([self::hwMessage($card)]));

        self::assertSame([], $source->fetch());
        self::assertSame(0, $source->patternMisses()->counts()['card_pattern']['misses'] ?? null, 'the card itself was read');
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
     * COLLECTIVE.WORK'S SHAPE: ONE OFFER PER MESSAGE, AND THE COMPANY IS IN THE SUBJECT ONLY —
     * `[<first name> x <company>] Nouvelle opportunité`. The body states a title and two links, and
     * no place, pay, contract or work mode. So the company is read from the subject, and the card
     * declares that it states no place rather than inventing one.
     */
    public function testTheCompanyIsReadFromTheSubject(): void
    {
        $offers = $this->collective(new RecordingMailbox([self::coMessage('Astrelya', 'Senior Développeur Java AWS', 'cmuf9f6ln6uuf4kfe8ko9d1ps')]))->fetch();

        self::assertCount(1, $offers);
        self::assertSame('cmuf9f6ln6uuf4kfe8ko9d1ps', $offers[0]->externalId);
        self::assertSame('Senior Développeur Java AWS', $offers[0]->title);
        self::assertSame('Astrelya', $offers[0]->company);
        self::assertSame('', $offers[0]->location, 'the card states no place, and none is guessed');
        self::assertSame('https://app.collective.work/talent/projects-activity/opportunities/cmuf9f6ln6uuf4kfe8ko9d1ps', $offers[0]->url);
    }

    /** A company containing ` x ` keeps it: only the FIRST ` x ` separates the subscriber's first name. */
    public function testASubjectCompanyContainingTheSeparatorIsKeptWhole(): void
    {
        $offers = $this->collective(new RecordingMailbox([self::coMessage('Brand x Co', 'Dev', 'cabc')]))->fetch();

        self::assertSame('Brand x Co', $offers[0]->company);
    }

    /** A subject the company rule cannot read is COUNTED — a template change must not drop the company in silence — and the offer is kept. */
    public function testASubjectWithoutTheCompanyIsCountedAndTheOfferKept(): void
    {
        $raw = str_replace('Subject: [abonne x Acme] Nouvelle opportunité', 'Subject: Nouvelle opportunité', self::coMessage('Acme', 'Dev', 'cabc'));
        $source = $this->collective(new RecordingMailbox([$raw]), '~Nouvelle opportunité~u');
        $offers = $source->fetch();

        self::assertCount(1, $offers);
        self::assertSame('', $offers[0]->company);
        self::assertSame(['subject_company_pattern' => ['calls' => 1, 'misses' => 1]], array_intersect_key($source->patternMisses()->counts(), ['subject_company_pattern' => true]));
    }

    public function testTheLoaderRefusesASubjectCompanyPatternWithoutItsGroup(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessage('subject_company_pattern');

        JobSourceLoader::fromArray(['sources' => ['co' => [
            'enabled' => false, 'family' => 'portal', 'type' => 'email_digest',
            'params' => ['subject_company_pattern' => '~x (.+)~'],
        ]]]);
    }

    /** Two providers of the company, one honoured and the other inert, is refused rather than resolved. */
    public function testTheLoaderRefusesACompanyFromBothTheSubjectAndTheCard(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessage('company');

        JobSourceLoader::fromArray(['sources' => ['co' => [
            'enabled' => false, 'family' => 'portal', 'type' => 'email_digest',
            'params' => ['subject_company_pattern' => '~x (?<company>.+)~', 'card_pattern' => '~(?<title>.+) (?<company>.+) (?<place>.+) (?<url>\S+)~'],
        ]]]);
    }

    /** The refusal of a place-less card NAMES the declaration that would permit one. */
    public function testTheLoaderRefusalOfAPlacelessCardNamesTheDeclaration(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessage('place_absent');

        JobSourceLoader::fromArray(['sources' => ['co' => [
            'enabled' => false, 'family' => 'portal', 'type' => 'email_digest',
            'params' => ['card_pattern' => '~(?<title>.+) (?<url>\S+)~'],
        ]]]);
    }

    public function testTheLoaderAcceptsAPlacelessCardThatDeclaresIt(): void
    {
        $defs = JobSourceLoader::fromArray(['sources' => ['co' => [
            'enabled' => false, 'family' => 'portal', 'type' => 'email_digest',
            'params' => ['card_pattern' => '~(?<title>.+) (?<url>\S+)~', 'place_absent' => 'true'],
        ]]]);

        self::assertSame('true', $defs['co']->param('place_absent'));
    }

    /** A caveat the pattern beside it contradicts is worse than none: it reads as considered. */
    public function testTheLoaderRefusesAPlaceAbsentDeclarationOnACardThatStatesAPlace(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessage('place_absent');

        JobSourceLoader::fromArray(['sources' => ['co' => [
            'enabled' => false, 'family' => 'portal', 'type' => 'email_digest',
            'params' => ['card_pattern' => '~(?<title>.+) (?<place>.+) (?<url>\S+)~', 'place_absent' => 'true'],
        ]]]);
    }

    /**
     * With NO card pattern beside it, so the value rule is the only one that can answer: next to a
     * place-less pattern, `"false"` is also refused as an undeclared place, and a test built that way
     * passed with the value rule deleted (the ledger found it).
     */
    public function testTheLoaderRefusesAPlaceAbsentValueOtherThanTrue(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessage('seule la valeur');

        JobSourceLoader::fromArray(['sources' => ['co' => [
            'enabled' => false, 'family' => 'portal', 'type' => 'email_digest',
            'params' => ['place_absent' => 'false'],
        ]]]);
    }

    /**
     * A `description` group is the offer's own text (freelance-informatique.fr: its `Compétences
     * souhaitées` line), so the stack score, H4 and the pay reader see it. Collapsed like every other
     * group; an optional group that did not participate is the empty description, never a miss.
     */
    public function testADescriptionGroupBecomesTheOffersDescription(): void
    {
        $card = static fn (string $id, string $skills): string => "Profil : Dev {$id}\nhttps://jobs.example/mission-{$id}\nLieu : Paris\n"
            . ($skills === '' ? '' : "Compétences : {$skills}\n");
        $source = new JobDigestEmailSource(
            new JobSourceDefinition('fi', true, 'portal', 'email_digest', [
                'from' => self::SENDER,
                'subject_pattern' => '~^Une nouvelle opportunité~u',
                'card_pattern' => '~Profil : (?<title>[^\n]+)\n(?<url>https://jobs\.example/mission-\S+)\nLieu : (?<place>[^\n]+)\n(?:Compétences : (?<description>[^\n]+)\n)?~u',
                'id_pattern' => '~/mission-([a-z0-9]+)$~',
            ]),
            JobStore::open(':memory:'),
            new RecordingMailbox([
                self::message($card('a1', 'Angular,   TypeScript'), subject: 'Une nouvelle opportunité'),
                self::message($card('b2', ''), subject: 'Une nouvelle opportunité'),
            ]),
        );

        $byId = [];
        foreach ($source->fetch() as $o) {
            $byId[$o->externalId] = $o->description;
        }
        self::assertSame(['a1' => 'Angular, TypeScript', 'b2' => ''], $byId);
        self::assertSame([], $source->patternMisses()->total());
    }

    private static function coMessage(string $company, string $title, string $id): string
    {
        return "From: Collective <ops@collective.work>\r\nTo: <alertes@example.invalid>\r\nSubject: [abonne x {$company}] Nouvelle opportunité\r\n"
            . "Date: Thu, 24 Sep 2026 10:45:15 +0200\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n"
            . "Un brief projet vient d'arriver dans votre boîte mail\n      Une nouvelle offre est disponible\n      Offre :\n{$title}\n"
            . "                Postuler\nhttps://app.collective.work/talent/projects-activity/opportunities/{$id}\n"
            . "                Voir l'offre\nhttps://www.collective.work/jobs/fr/dev-xcjb\n";
    }

    private function collective(Mailbox $mailbox, string $subjectPattern = '~\]\h*Nouvelle opportunité~u'): JobDigestEmailSource
    {
        return new JobDigestEmailSource(
            new JobSourceDefinition('collective', true, 'portal', 'email_digest', [
                'from' => 'ops@collective.work',
                'subject_pattern' => $subjectPattern,
                'subject_company_pattern' => '~^\[\S+\h+x\h+(?<company>[^\]]+?)\h*\]~u',
                'card_pattern' => '~Offre :\h*+\n\h*(?<title>\S[^\n]*?)\h*+\n(?:\h*+\n)*+\h*Postuler\h*+\n\h*(?<url>https://app\.collective\.work/\S*?/opportunities/[a-z0-9]+)~u',
                'id_pattern' => '~/opportunities/([a-z0-9]+)~',
                'place_absent' => 'true',
            ]),
            JobStore::open(':memory:'),
            $mailbox,
        );
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

    /** One field's link: every link of an Apec card has its OWN slot in `id` and its own `s`, and only `e` is shared. */
    private static function apLink(string $id, int $slot = 1): string
    {
        return 'https://neomarket.diffusion.apec.fr/r/?id=FIXTURE' . sprintf('%04d', $slot) . '&e=' . rtrim(strtr(base64_encode("p1=www.apec.fr&p2={$id}W&p3=&xtor=EPR-41-[push_avec_compte]"), '+/', '-_'), '=') . '&s=FIXTURE' . sprintf('%04d', 100 + $slot);
    }

    /** The logo-less card: every field is followed by a link to the same offer — four DIFFERENT links. */
    private static function apCard(string $link, string $title, string $company, string $place): string
    {
        $next = static fn (int $n): string => (string) preg_replace_callback('~FIXTURE(\d+)~', static fn (array $m): string => sprintf('FIXTURE%04d', (int) $m[1] + 10 * $n), $link);

        return "\n                      {$title}\n{$link}\n\n                      {$company}\n{$next(1)}\n\n                      CDI\u{a0}•\n{$next(2)}\n\n                      {$place}\n{$next(3)}\n";
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
