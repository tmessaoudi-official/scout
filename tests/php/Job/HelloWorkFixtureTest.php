<?php

declare(strict_types=1);

namespace Scout\Tests\Job;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Adapters\Mail\EmailMessage;
use Scout\Adapters\Mail\FileMailbox;
use Scout\Job\JobClassifier;
use Scout\Job\JobCriteriaLoader;
use Scout\Job\JobDigestEmailSource;
use Scout\Job\JobListing;
use Scout\Job\JobOutcome;
use Scout\Job\JobScorer;
use Scout\Job\JobSourceLoader;
use Scout\Job\JobStore;

/**
 * FOUR REAL HELLOWORK ALERTS, SCRUBBED, THE PINNED VALUES HAND-READ (2026-09-24).
 *
 * One message per saved search — DevOps/SRE (01, 8 cards), Lead/Management (02, 4), Dev (03, 20) and
 * Architecture (04, 7): 39 offers. HTML only, so the body is the stripped HTML with each link written
 * into it. The offer id lives ONLY inside each click link's base64url token (`<subscriber>🪢<offer
 * URL>`), which `tools/scrub-eml.php` rewrote with the subscriber replaced and the offer URL intact.
 *
 * The lost-card check does not trust the card pattern: it decodes EVERY click token in the raw
 * message on its own and compares the offer ids found there with the ones the adapter returns.
 */
#[CoversClass(JobDigestEmailSource::class)]
final class HelloWorkFixtureTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../..';

    /**
     * One card per shape the template takes, read off the rendered text by hand.
     *
     * @var array<string, array{title: string, company: string, location: string, pay: ?string}>
     */
    private const array CARDS = [
        // A badge line (`Super recruteur`) between company and place, and a salary RANGE with its unit.
        '80508708' => ['title' => 'Ingénieur Build Cloud Azure H/F', 'company' => 'AS International', 'location' => 'Suresnes - 92', 'pay' => '55 000 - 65 000 € / an'],
        // A region for a place, and no pay line.
        '82621905' => ['title' => 'Chef de Projet Senior Aws H/F', 'company' => 'Socadek Solutions', 'location' => 'Île-de-France', 'pay' => null],
        // A ` - ` inside the COMPANY, which a facts splitter would have cut.
        '76017017' => ['title' => "Architecte Technique des Systèmes d'Information ERP H/F", 'company' => 'Safran - CDI', 'location' => 'Corbeil-Essonnes - 91', 'pay' => null],
        // A single-figure salary, and an arrondissement.
        '73929335' => ['title' => 'Architecte Solution H/F', 'company' => 'UMG Groupe VYV', 'location' => 'Paris 13e - 75', 'pay' => '50 000 € / an'],
    ];

    public function testEveryMessageYieldsEveryOfferItsTokensName(): void
    {
        $offers = $this->source()->fetch();
        $read = array_map(static fn (JobListing $o): string => $o->externalId, $offers);

        self::assertCount(39, $offers);
        self::assertSame($read, array_values(array_unique($read)), 'each search names distinct offers');
        $named = [];
        foreach (glob(self::ROOT . '/tests/fixtures/job/hellowork/*.eml') ?: [] as $file) {
            $body = EmailMessage::parse((string) file_get_contents($file))->body;
            preg_match_all('~/clic/[^/\s]+/\d+/[0-9a-f]+/([A-Za-z0-9_-]+)~', $body, $m);
            foreach ($m[1] as $token) {
                if (preg_match('~/fr-fr/emplois/(\d+)\.html~', (string) base64_decode(strtr($token, '-_', '+/'), true), $id) === 1) {
                    $named[$id[1]] = true;
                }
            }
        }
        $ids = array_keys($named);
        sort($ids);
        sort($read);
        self::assertSame(array_map('strval', $ids), $read, 'an offer a token names and no card read is a lost card');
    }

    public function testThePinnedCardsAreReadAsHandRead(): void
    {
        $byId = [];
        foreach ($this->source()->fetch() as $o) {
            $byId[$o->externalId] = $o;
        }

        foreach (self::CARDS as $id => $want) {
            self::assertArrayHasKey($id, $byId, (string) $id);
            $o = $byId[$id];
            self::assertSame('hellowork', $o->sourceName, (string) $id);
            self::assertSame($want['title'], $o->title, (string) $id);
            self::assertSame($want['company'], $o->company, (string) $id);
            self::assertSame($want['location'], $o->location, (string) $id);
            self::assertSame(['CDI'], $o->contracts, (string) $id);
            self::assertSame($want['pay'], $o->payText, (string) $id);
            self::assertNull($o->workMode, $id . ': a HelloWork card states no work mode');
            self::assertSame('https://www.hellowork.com/fr-fr/emplois/' . $id . '.html', $o->url, $id . ': the offer page, not the tracking click, and no utm query');
            self::assertNull($o->publishedAt, $id . ': the card states no date');
        }
        self::assertSame('2026-09-24T08:39:30Z', $byId['80508708']->observedAt, 'the message send instant');
    }

    /** The counterweight: four real alerts, no warning, and not one rule missed. */
    public function testNothingIsWarnedAndNothingIsBlind(): void
    {
        $warnings = [];
        $source = $this->source(static function (string $w) use (&$warnings): void {
            $warnings[] = $w;
        });
        $source->fetch();

        self::assertSame([], $warnings);
        self::assertSame([], $source->patternMisses()->total());
        foreach ($source->patternMisses()->counts() as $key => $c) {
            self::assertSame(0, $c['misses'], $key . ' missed on a real alert');
        }
    }

    /** End to end, SHIPPED criteria: a 55–65 k€ cloud engineer clears the salary floor and matches. */
    public function testTheAzureEngineerMatchesWithItsSalaryRead(): void
    {
        [$facts, $verdict] = $this->judge('80508708');

        self::assertCount(1, $facts->pay);
        self::assertSame(55000, $facts->pay[0]->minEur);
        self::assertSame(65000, $facts->pay[0]->maxEur);
        self::assertSame(JobOutcome::MATCH, $verdict->outcome, implode(' / ', $verdict->reasons));
    }

    /** End to end, SHIPPED criteria: a 50 k€ salary is under the floor, so the pay line is not merely shown. */
    public function testTheFiftyThousandArchitectIsRejectedOnItsSalary(): void
    {
        [, $verdict] = $this->judge('73929335');

        self::assertSame(JobOutcome::REJECT, $verdict->outcome);
        self::assertStringContainsString('sous le plancher', implode(' / ', $verdict->reasons));
    }

    /** @return array{0: \Scout\Job\JobFacts, 1: \Scout\Job\JobVerdict} */
    private function judge(string $id): array
    {
        $offers = array_values(array_filter($this->source()->fetch(), static fn (JobListing $o): bool => $o->externalId === $id));
        self::assertCount(1, $offers);

        $criteria = JobCriteriaLoader::load(self::ROOT . '/config/job/criteria.json');
        $facts = (new JobClassifier())->read($offers[0]);

        return [$facts, (new JobScorer())->judge($offers[0], $facts, $criteria, new \DateTimeImmutable('2026-09-24T12:00:00Z'))];
    }

    /** @param ?\Closure(string): void $warn */
    private function source(?\Closure $warn = null): JobDigestEmailSource
    {
        return new JobDigestEmailSource(
            JobSourceLoader::load(self::ROOT . '/config/job/sources.json')['hellowork'],
            JobStore::open(':memory:'),
            new FileMailbox(self::ROOT . '/tests/fixtures/job/hellowork'),
            $warn,
        );
    }
}
