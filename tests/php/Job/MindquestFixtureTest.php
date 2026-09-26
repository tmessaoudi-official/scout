<?php

declare(strict_types=1);

namespace Scout\Tests\Job;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
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
 * MINDQUEST'S FIRST JOB ALERT, DELIVERED TWICE, SCRUBBED, THE PINNED VALUES HAND-READ (2026-09-26).
 *
 * n=1: 01 and 02 are the same six offers sent in the same second under two Mailjet message ids, and
 * 02 links `mindquest.io/fr/missions/<id>` where 01 links `fr.mindquest.io/missions/<id>` — both
 * shapes are kept so the id reader covers each. 00 is the account's sign-up confirmation from the same
 * sender, which must stay unclaimed. HTML only: the body path harvests every href into the text after
 * its anchor, so each card is `<title> [-] <dept> - <commune>`, its duration or contract, `Consulter
 * l'offre` and its click link. Checked against the raw captures before the scrub: the same six ids,
 * twelve offers, from both.
 */
#[CoversClass(JobDigestEmailSource::class)]
final class MindquestFixtureTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../..';

    /** @var array<string, array{title: string, location: string, labels: string, contracts: list<string>}> */
    private const array OFFERS = [
        // The site's own typo glues `Developer` to `Typescript`; kept as sent.
        '94104' => ['title' => 'DeveloperTypescript (React) - Manager (H/F)', 'location' => 'Roubaix', 'labels' => '59', 'contracts' => ['Mission de 0 jours']],
        // A ` - ` INSIDE the title, before `- <dept> - `: the title keeps it and loses the separator.
        '94150' => ['title' => 'Développeur .Net C# - H/F', 'location' => 'Le Pecq', 'labels' => '78', 'contracts' => ['Mission de 15 mois']],
        '94181' => ['title' => 'Tech Lead Fullstack (H/F)', 'location' => 'Marseille', 'labels' => '13', 'contracts' => ['CDI']],
        '94182' => ['title' => 'Tech Lead Angular (H/F)', 'location' => 'Marseille', 'labels' => '13', 'contracts' => ['CDI']],
        // A middle segment between the department and the commune stays a label, never the place.
        '94223' => ['title' => 'Responsable Développement, Low Code, RPA & Outils Collaboratifs', 'location' => 'Viroflay', 'labels' => '78 | CDI', 'contracts' => ['CDI']],
        '94278' => ['title' => "Développeur Fullstack UX / ReactJS / Golang assité par l'IA (H/F)", 'location' => 'Guipavas', 'labels' => '29', 'contracts' => ['Mission de 0 jours']],
    ];

    public function testBothDeliveriesYieldTheSixOffersAsHandRead(): void
    {
        $offers = $this->source()->fetch();

        self::assertCount(12, $offers, 'six offers in each of the two alerts; the sign-up mail yields none');
        // Grouped by link shape, not by position: the mailbox order is its own business.
        foreach (['https://fr.mindquest.io/missions/', 'https://mindquest.io/fr/missions/'] as $prefix) {
            $delivery = array_values(array_filter($offers, static fn (JobListing $o): bool => str_starts_with((string) $o->url, $prefix)));
            self::assertSame(array_map('strval', array_keys(self::OFFERS)), array_map(static fn (JobListing $o): string => $o->externalId, $delivery));
            foreach ($delivery as $o) {
                $want = self::OFFERS[$o->externalId];
                self::assertSame('mindquest', $o->sourceName);
                self::assertSame($want['title'], $o->title, $o->externalId);
                self::assertSame($want['location'], $o->location, $o->externalId);
                self::assertSame(['labels' => $want['labels']], $o->fields, $o->externalId);
                self::assertSame($want['contracts'], $o->contracts, $o->externalId);
                // The push links the DECODED mission page, never the Mailjet tracker.
                self::assertSame($prefix . $o->externalId, $o->url, $o->externalId);
                self::assertSame('', $o->company, $o->externalId . ': the card states no company');
                self::assertNull($o->payText, $o->externalId);
                self::assertNull($o->workMode, $o->externalId);
                self::assertSame('2026-09-26T08:00:19Z', $o->observedAt, $o->externalId);
            }
        }
    }

    /**
     * End to end, SHIPPED criteria. The two role-gate rejections are real: `DeveloperTypescript` is the
     * site's typo and `Responsable Développement…` is a management title. The Marseille CDI MATCHES —
     * the stated cost of a card with no work mode: H8 rejects an outside place only when the mode is
     * stated onsite or hybrid, so on this source it never fires. Pinned so it is not mistaken for a fix.
     */
    public function testShippedCriteriaVerdicts(): void
    {
        $criteria = JobCriteriaLoader::load(self::ROOT . '/config/job/criteria.json');
        $outcomes = [];
        foreach (array_slice($this->source()->fetch(), 0, 6) as $o) {
            $outcomes[$o->externalId] = (new JobScorer())->judge($o, (new JobClassifier())->read($o), $criteria, new \DateTimeImmutable('2026-09-26T12:00:00Z'))->outcome;
        }

        self::assertSame([
            '94104' => JobOutcome::REJECT,
            '94150' => JobOutcome::MATCH,
            '94181' => JobOutcome::MATCH,
            '94182' => JobOutcome::MATCH,
            '94223' => JobOutcome::REJECT,
            '94278' => JobOutcome::MATCH,
        ], $outcomes);
    }

    /**
     * The counterweight: no warning, no miss — and the sign-up mail is NOT claimed. Were the subject
     * filter gone, it would be claimed, yield no card, and `card_pattern` would count three calls.
     */
    public function testNothingIsWarnedNothingIsBlindAndTheSignUpMailIsUnclaimed(): void
    {
        $warnings = [];
        $source = $this->source(static function (string $w) use (&$warnings): void {
            $warnings[] = $w;
        });
        $source->fetch();

        self::assertSame([], $warnings);
        self::assertSame([], $source->patternMisses()->total());
        self::assertSame(['calls' => 2, 'misses' => 0], $source->patternMisses()->counts()['card_pattern']);
        foreach ($source->patternMisses()->counts() as $key => $c) {
            self::assertSame(0, $c['misses'], $key . ' missed on a real mail');
        }
    }

    /** @param ?\Closure(string): void $warn */
    private function source(?\Closure $warn = null): JobDigestEmailSource
    {
        return new JobDigestEmailSource(
            JobSourceLoader::load(self::ROOT . '/config/job/sources.json')['mindquest'],
            JobStore::open(':memory:'),
            new FileMailbox(self::ROOT . '/tests/fixtures/job/mindquest'),
            $warn,
        );
    }
}
