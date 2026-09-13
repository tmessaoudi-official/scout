<?php

declare(strict_types=1);

namespace Scout\Tests\Job;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Adapters\Mail\FileMailbox;
use Scout\Job\JobClassifier;
use Scout\Job\JobCriteriaLoader;
use Scout\Job\JobEmailSource;
use Scout\Job\JobListing;
use Scout\Job\JobOutcome;
use Scout\Job\JobScorer;
use Scout\Job\JobSourceLoader;
use Scout\Job\JobStore;

/**
 * THREE REAL LINKEDIN ALERTS, SCRUBBED, EVERY VALUE HAND-READ.
 *
 * `tests/fixtures/job/linkedin/{04,10,14}.eml`, scrubbed with `tools/scrub-eml.php` (the name, the
 * headline and every tracking value replaced; each `otpToken` decodes to a `FIXTURE<n>`
 * placeholder). Parsed back, the scrubbed copies yield the same cards, ids, lines, links and
 * `sentAt` as the raw captures.
 *
 * - 04 carries the 40–45 k€ card, which the pay floor must reject, and the TotalEnergies card that
 *   states no work mode.
 * - 10 carries the 44–70 k€ Aneo card, which must match with its pay read.
 * - 14 is the smallest template, four fully-remote cards.
 *
 * The expected values are literal. They were read off the fixture text, never produced by the
 * adapter under test.
 */
#[CoversClass(JobEmailSource::class)]
final class LinkedInFixtureTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../..';

    /**
     * Newest message first, because `FileMailbox` orders by name, descending.
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string, 4: ?string, 5: ?string, 6: list<string>, 7: string}>
     */
    private const array CARDS = [
        ['4466018807', 'Full-Stack Developer (Remote)', 'Hired', 'France', 'remote', null, [], '2026-09-12T14:49:28Z'],
        ['4464805323', 'Full-Stack Engineer - Creative Studio', 'Jobgether', 'France', 'remote', null, ['Candidature simplifiée'], '2026-09-12T14:49:28Z'],
        ['4466039493', 'Fullstack Developer (React/Node.js) (Remote)', 'Hire Feed', 'France', 'remote', null, [], '2026-09-12T14:49:28Z'],
        ['4465105380', 'Senior Full Stack Software Engineer', 'Nexum Technologies', 'France', 'remote', null, ['Candidature simplifiée'], '2026-09-12T14:49:28Z'],
        ['4461976159', 'Senior Software Engineer', 'Aneo', 'Montrouge', 'hybrid', 'Entre 44 k € et 70 k € par an', ['Recrutement actif'], '2026-09-11T10:49:28Z'],
        ['4426842634', 'Senior Software Engineer - OpenCRQ', 'Filigran', 'France', 'remote', null, [], '2026-09-11T10:49:28Z'],
        ['4456327930', 'Senior Backend Engineer', 'Earnix', 'Paris', 'hybrid', null, ['Recrutement actif'], '2026-09-11T10:49:28Z'],
        ['4445764079', 'Agile IT Developer', 'LSEG', 'Paris', 'hybrid', null, ['Recrutement actif'], '2026-09-11T10:49:28Z'],
        ['4465803921', 'Lead Developer - CDI - F/H/NB', 'Disneyland Paris', 'Montévrain', 'onsite', null, ['1 ancien collègue'], '2026-09-11T10:49:28Z'],
        ['4464560753', 'Lead Developer - CDI - F/H/NB', 'The Walt Disney Company', 'Montévrain', 'onsite', null, ['1 ancien collègue'], '2026-09-11T10:49:28Z'],
        ['4454991178', 'Senior Fullstack Engineer', 'Malt', 'Paris', 'hybrid', null, ['Recrutement actif', 'Appliquer'], '2026-09-10T10:49:34Z'],
        ['4446342077', 'SOFTWARE ENGINEER FULL STACK - TOTALENERGIES DIGITAL FACTORY', 'TotalEnergies', 'Ville de Paris', null, null, ['1 relation'], '2026-09-10T10:49:34Z'],
        ['4372730067', 'Staff Software Engineer', 'GitGuardian', 'Paris', 'hybrid', null, ['Recrutement actif'], '2026-09-10T10:49:34Z'],
        ['4463039807', 'Développeur Fullstack PHP / JavaScript (H/F)', 'Free-Work', 'Paris', 'hybrid', 'Entre 40 k € et 45 k € par an', [], '2026-09-10T10:49:34Z'],
        ['4465000321', 'Full Stack Engineer', 'BAO', 'Ville de Paris', 'hybrid', null, ['1 relation', 'Appliquer'], '2026-09-10T10:49:34Z'],
        ['4434521823', 'Software Engineer I', 'Checkout.com', 'Paris', 'onsite', null, [], '2026-09-10T10:49:34Z'],
    ];

    public function testEverySixteenCardsAreReadAsHandRead(): void
    {
        $offers = $this->source()->fetch();

        self::assertCount(16, $offers, 'ground truth: 4 + 6 + 6 cards across the three alerts');

        foreach (self::CARDS as $i => [$id, $title, $company, $location, $mode, $pay, $labels, $sent]) {
            $o = $offers[$i];
            $at = 'card ' . $i . ' (' . $id . ')';
            self::assertSame('linkedin', $o->sourceName, $at);
            self::assertSame($id, $o->externalId, $at);
            self::assertSame($title, $o->title, $at);
            self::assertSame($company, $o->company, $at);
            self::assertSame($location, $o->location, $at);
            self::assertSame($mode, $o->workMode, $at);
            self::assertSame($pay, $o->payText, $at);
            self::assertSame($labels === [] ? [] : ['labels' => implode(' | ', $labels)], $o->fields, $at);
            self::assertSame('https://www.linkedin.com/comm/jobs/view/' . $id . '/', $o->url, $at);
            self::assertSame($sent, $o->observedAt, $at . ': observed when the alert was sent');
            self::assertNull($o->publishedAt, $at . ': a LinkedIn card carries no publication date');
            self::assertSame('', $o->description, $at);
            self::assertSame([], $o->contracts, $at);
        }
    }

    /** The counterweight to the reflection test: on real alerts nothing is blind and nothing is warned. */
    public function testRealAlertsLeaveNoPatternBlindAndNoDuplicate(): void
    {
        $warnings = [];
        $source = $this->source(static function (string $w) use (&$warnings): void {
            $warnings[] = $w;
        });
        $source->fetch();

        self::assertSame([], $source->patternMisses()->total());
        self::assertSame([], $warnings);
        foreach ($source->patternMisses()->counts() as $key => $c) {
            self::assertSame(0, $c['misses'], $key . ' missed on a real alert');
        }
    }

    /** End to end with the SHIPPED criteria: the 44–70 k€ Aneo card matches, and its pay was read. */
    public function testTheAneoCardMatchesWithItsPayRead(): void
    {
        [$offer, $facts, $verdict] = $this->judge('4461976159');

        self::assertNotSame([], $facts->pay, 'the Aneo card states 44–70 k€ and the classifier must read it');
        self::assertSame('MATCH', $verdict->outcome->name, implode(' / ', $verdict->reasons));
        self::assertNotNull($verdict->score);
        self::assertSame('hybrid', $offer->workMode);
    }

    /** End to end with the SHIPPED criteria: the 40–45 k€ card is rejected by the pay floor (H1). */
    public function testTheFortyFiveKCardIsRejectedOnPay(): void
    {
        [, , $verdict] = $this->judge('4463039807');

        self::assertSame(JobOutcome::REJECT, $verdict->outcome);
        self::assertStringContainsString('sous le plancher', implode(' / ', $verdict->reasons));
    }

    /** @return array{0: JobListing, 1: \Scout\Job\JobFacts, 2: \Scout\Job\JobVerdict} */
    private function judge(string $id): array
    {
        $offers = array_values(array_filter($this->source()->fetch(), static fn (JobListing $o): bool => $o->externalId === $id));
        self::assertCount(1, $offers);

        $criteria = JobCriteriaLoader::load(self::ROOT . '/config/job/criteria.json');
        $facts = (new JobClassifier())->read($offers[0]);

        return [$offers[0], $facts, (new JobScorer())->judge($offers[0], $facts, $criteria, new \DateTimeImmutable('2026-09-13T12:00:00Z'))];
    }

    /** @param ?\Closure(string): void $warn */
    private function source(?\Closure $warn = null): JobEmailSource
    {
        return new JobEmailSource(
            JobSourceLoader::load(self::ROOT . '/config/job/sources.json')['linkedin'],
            JobStore::open(':memory:'),
            new FileMailbox(self::ROOT . '/tests/fixtures/job/linkedin'),
            $warn,
        );
    }
}
