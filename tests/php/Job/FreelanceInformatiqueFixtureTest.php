<?php

declare(strict_types=1);

namespace Scout\Tests\Job;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Adapters\Mail\FileMailbox;
use Scout\Job\JobClassifier;
use Scout\Job\JobCriteriaLoader;
use Scout\Job\JobDigestEmailSource;
use Scout\Job\JobOutcome;
use Scout\Job\JobScorer;
use Scout\Job\JobSourceLoader;
use Scout\Job\JobStore;

/**
 * FREELANCE-INFORMATIQUE.FR'S FIRST OPPORTUNITY ALERT, SCRUBBED, THE VALUES HAND-READ (2026-09-26).
 *
 * n=1: one mail, one offer. HTML only, and its links go straight to the offer — no click tracker, so
 * there is no token to decode and the push links the page with its `?external_from=alerte` query
 * stripped. Checked against the raw capture before the scrub: the same offer from both.
 */
#[CoversClass(JobDigestEmailSource::class)]
final class FreelanceInformatiqueFixtureTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../..';

    public function testTheOfferIsReadAsHandRead(): void
    {
        $offers = $this->source()->fetch();

        self::assertCount(1, $offers);
        $o = $offers[0];
        self::assertSame('freelance_informatique', $o->sourceName);
        self::assertSame('260924B004', $o->externalId);
        self::assertSame('Développeur Frontend Angular / TypeScript (94)', $o->title);
        self::assertSame('94220 Charenton le Pont', $o->location);
        // The duration is a label; the start date (28/09/2026) is not a publication date and is not read.
        self::assertSame(['labels' => '3 mois (renouvelables)'], $o->fields);
        // The skills line is the description, so the stack score and H4 read it with the title.
        self::assertSame('Angular, TypeScript', $o->description);
        self::assertSame('https://www.freelance-informatique.fr/mission-developpeur-frontend-angular-typescript-94-260924B004', $o->url);
        self::assertSame([], $o->contracts, 'the site is freelance only, and the card never says so');
        self::assertSame('', $o->company);
        self::assertNull($o->payText);
        self::assertNull($o->workMode);
        self::assertSame('2026-09-26T09:00:25Z', $o->observedAt);
    }

    /**
     * SHIPPED criteria: a MATCH at 31 — stack only, everything else unknown. Under the deployed gate of
     * 40 it waits for the rollup, which is the stated cost of a card stating no pay, level or mode.
     */
    public function testShippedCriteriaVerdict(): void
    {
        $criteria = JobCriteriaLoader::load(self::ROOT . '/config/job/criteria.json');
        $o = $this->source()->fetch()[0];
        $verdict = (new JobScorer())->judge($o, (new JobClassifier())->read($o), $criteria, new \DateTimeImmutable('2026-09-26T12:00:00Z'));

        self::assertSame(JobOutcome::MATCH, $verdict->outcome);
        self::assertSame(31, $verdict->score);
    }

    /** The counterweight: no warning and no miss on a real mail, the optional groups included. */
    public function testNothingIsWarnedAndNothingIsBlind(): void
    {
        $warnings = [];
        $source = $this->source(static function (string $w) use (&$warnings): void {
            $warnings[] = $w;
        });
        $source->fetch();

        self::assertSame([], $warnings);
        self::assertSame([], $source->patternMisses()->total());
        self::assertSame(['calls' => 1, 'misses' => 0], $source->patternMisses()->counts()['card_pattern']);
        foreach ($source->patternMisses()->counts() as $key => $c) {
            self::assertSame(0, $c['misses'], $key . ' missed on a real mail');
        }
    }

    /** @param ?\Closure(string): void $warn */
    private function source(?\Closure $warn = null): JobDigestEmailSource
    {
        return new JobDigestEmailSource(
            JobSourceLoader::load(self::ROOT . '/config/job/sources.json')['freelance_informatique'],
            JobStore::open(':memory:'),
            new FileMailbox(self::ROOT . '/tests/fixtures/job/freelance-informatique'),
            $warn,
        );
    }
}
