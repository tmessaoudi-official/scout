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
 * FIVE REAL COLLECTIVE.WORK OPPORTUNITY MAILS, SCRUBBED, THE PINNED VALUES HAND-READ (2026-09-24).
 *
 * One offer per message. 01–04 are the current template (`Offre :` / `Postuler`); 02 and 03 are the
 * SAME Triskell offer re-posted a day apart under two ids, which is the stated cost of keying on the
 * app id. 05 is the template before 2026-09 (`Projet:` / `Découvrir le projet`, its é DECOMPOSED as
 * e + U+0301) — kept so a revert to it cannot blind the source. The company is in the subject only;
 * no mail states a place, pay, contract or work mode. Checked against the raw captures before the
 * scrub: the same five offers, 5/5 distinct links, from both.
 */
#[CoversClass(JobDigestEmailSource::class)]
final class CollectiveFixtureTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../..';

    /** The current template writes its titles DECOMPOSED (e + U+0301), and they are kept as sent. */
    private const string TRISKELL = "De\u{301}veloppeur Front-End Senior ReactJS / TypeScript (H/F)";

    /** @var array<string, array{title: string, company: string, observedAt: string}> */
    private const array OFFERS = [
        'cmuf9f6ln6uuf4kfe8ko9d1ps' => ['title' => "Senior De\u{301}veloppeur Java AWS Culture IA First", 'company' => 'Astrelya', 'observedAt' => '2026-09-24T08:45:15Z'],
        'cmucxoynv1kfk4ke2g2oq80l6' => ['title' => self::TRISKELL, 'company' => 'Triskell Consulting', 'observedAt' => '2026-09-22T17:36:10Z'],
        'cmudy4vxf0g3h4ke4gfyo2c5e' => ['title' => self::TRISKELL, 'company' => 'Triskell Consulting', 'observedAt' => '2026-09-23T10:29:19Z'],
        // A trailing space inside the subject's brackets, which the company is trimmed of.
        'cmuf9kdb56rc64kfka96c7a0l' => ['title' => "Consultant Confirme\u{301} Product Owner IA - F/H/N", 'company' => 'OCTO Technology', 'observedAt' => '2026-09-24T08:39:28Z'],
        'cmher2w2f0nw8aq5una9o4z6c' => ['title' => 'Consultant DevOps CI/CD – Python / Terraform / Ansible (H/F)', 'company' => 'Hoxton Partners', 'observedAt' => '2025-10-31T11:08:41Z'],
    ];

    public function testEveryMailYieldsItsOneOfferAsHandRead(): void
    {
        $byId = [];
        foreach ($this->source()->fetch() as $o) {
            $byId[$o->externalId] = $o;
        }

        self::assertSame(array_keys(self::OFFERS), array_values(array_intersect(array_keys(self::OFFERS), array_keys($byId))));
        self::assertCount(5, $byId);
        foreach (self::OFFERS as $id => $want) {
            $o = $byId[$id];
            self::assertSame('collective', $o->sourceName, $id);
            self::assertSame($want['title'], $o->title, $id);
            self::assertSame($want['company'], $o->company, $id);
            self::assertSame($want['observedAt'], $o->observedAt, $id);
            self::assertSame('', $o->location, $id . ': the mail states no place');
            self::assertSame([], $o->contracts, $id);
            self::assertNull($o->payText, $id);
            self::assertStringStartsWith('https://app.collective.work/', (string) $o->url, $id);
            self::assertStringEndsWith('/opportunities/' . $id, (string) $o->url, $id);
        }
    }

    /** The re-post is two offers: the stated cost of the app id, pinned so it is never mistaken for a fix. */
    public function testTheRepostIsTwoOffersWithOneTitle(): void
    {
        $titles = array_map(static fn (JobListing $o): string => $o->title, $this->source()->fetch());

        self::assertSame(2, array_count_values($titles)[self::TRISKELL]);
    }

    /**
     * End to end, SHIPPED criteria: a DECOMPOSED `Développeur` still passes the role gate, because
     * folding strips combining marks — if it did not, every current-template developer offer would be
     * rejected as `intitulé hors métier` in silence.
     */
    public function testADecomposedTitlePassesTheRoleGate(): void
    {
        $offers = array_values(array_filter($this->source()->fetch(), static fn (JobListing $o): bool => $o->externalId === 'cmudy4vxf0g3h4ke4gfyo2c5e'));
        self::assertCount(1, $offers);
        self::assertSame(1, preg_match('~\p{Mn}~u', $offers[0]->title), 'the fixture must still carry the decomposed form');

        $verdict = (new JobScorer())->judge($offers[0], (new JobClassifier())->read($offers[0]), JobCriteriaLoader::load(self::ROOT . '/config/job/criteria.json'), new \DateTimeImmutable('2026-09-24T12:00:00Z'));

        self::assertSame(JobOutcome::MATCH, $verdict->outcome, implode(' / ', $verdict->reasons));
    }

    /** The counterweight: five real mails, no warning, and not one rule missed. */
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
            self::assertSame(0, $c['misses'], $key . ' missed on a real mail');
        }
        self::assertArrayHasKey('subject_company_pattern', $source->patternMisses()->counts());
    }

    /** @param ?\Closure(string): void $warn */
    private function source(?\Closure $warn = null): JobDigestEmailSource
    {
        return new JobDigestEmailSource(
            JobSourceLoader::load(self::ROOT . '/config/job/sources.json')['collective'],
            JobStore::open(':memory:'),
            new FileMailbox(self::ROOT . '/tests/fixtures/job/collective'),
            $warn,
        );
    }
}
