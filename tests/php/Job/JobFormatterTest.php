<?php

declare(strict_types=1);

namespace Scout\Tests\Job;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Scout\Core\Notify\NotificationKind;
use Scout\Core\Notify\Priority;
use Scout\Job\JobFormatter;
use Scout\Job\JobListing;
use Scout\Job\JobStore;
use Scout\Job\JobVerdict;

/** What a job push says: the source first, then the score, then title · company · place · mode. */
#[CoversClass(JobFormatter::class)]
final class JobFormatterTest extends TestCase
{
    public function testAPushLeadsWithTheSourceThenTheScoreThenTheOffer(): void
    {
        $n = (new JobFormatter())->match(self::offer(), JobVerdict::matched(72, ['stack : PHP', 'hybride — 2 jour(s) de télétravail']));

        self::assertSame(NotificationKind::MATCH, $n->kind);
        self::assertSame('linkedin · 72/100 — Senior Software Engineer · Aneo · Montrouge · hybride', $n->title);
        self::assertSame(['stack : PHP', 'hybride — 2 jour(s) de télétravail'], $n->reasons);
        self::assertSame('https://www.linkedin.com/jobs/view/4461976159/', $n->url);
        self::assertSame(72, $n->score);
        self::assertSame('linkedin', $n->sourceName);
    }

    /** @return iterable<string, array{string, string}> */
    public static function modes(): iterable
    {
        yield 'remote' => ['remote', ' · télétravail'];
        yield 'hybrid' => ['hybrid', ' · hybride'];
        yield 'on site' => ['onsite', ' · sur site'];
    }

    #[DataProvider('modes')]
    public function testEachStatedModeHasItsFrenchLabel(string $mode, string $suffix): void
    {
        $n = (new JobFormatter())->match(self::offer($mode), JobVerdict::matched(50, []));

        self::assertStringEndsWith('Montrouge' . $suffix, $n->title);
    }

    /** Hard rule 9 at the display layer: an unstated mode is left out, never printed as "sur site". */
    public function testAnUnstatedModeAndEmptyFieldsAreLeftOutRatherThanGuessed(): void
    {
        $bare = (new JobFormatter())->match(new JobListing(sourceName: 'linkedin', externalId: '1', title: 'Lead Developer'), JobVerdict::matched(40, []));
        self::assertSame('linkedin · 40/100 — Lead Developer', $bare->title);

        $untitled = (new JobFormatter())->match(new JobListing(sourceName: 'linkedin', externalId: '2', company: 'Aneo'), JobVerdict::matched(40, []));
        self::assertSame('linkedin · 40/100 — offre sans intitulé · Aneo', $untitled->title);
    }

    /** No `!!` marker in this domain: no score bar has been calibrated to earn one. */
    public function testEveryPushIsNormalPriorityEvenAtAPerfectScore(): void
    {
        self::assertSame(Priority::NORMAL, (new JobFormatter())->match(self::offer(), JobVerdict::matched(100, []))->priority);
    }

    public function testARollupIsOneLowPriorityMessageWithOneLinePerOffer(): void
    {
        $n = (new JobFormatter())->rollup([
            ['offer' => self::offer(), 'score' => 31],
            ['offer' => new JobListing(sourceName: 'linkedin', externalId: '9', title: 'Staff Software Engineer', company: 'GitGuardian', location: 'Paris'), 'score' => null],
        ]);

        self::assertSame(NotificationKind::ROLLUP, $n->kind);
        self::assertSame(Priority::LOW, $n->priority);
        self::assertSame('Vérifié, score bas : 2 offre(s) sous le seuil de notification individuelle', $n->title);
        self::assertSame([
            '• linkedin · 31/100 — Senior Software Engineer · Aneo · Montrouge · hybride',
            '• linkedin · Staff Software Engineer · GitGuardian · Paris',
        ], $n->reasons);
    }

    public function testTheHeartbeatNamesTheJobWatcherPutsFailedPassesFirstAndTheRefusalLast(): void
    {
        $n = (new JobFormatter())->heartbeat(4, 2, [], '2026-09-13T08:00:00+02:00', 'configuration illisible', 3);

        self::assertSame(NotificationKind::HEARTBEAT, $n->kind);
        self::assertSame('job-watch tourne — 2 correspondance(s) depuis 2026-09-13T08:00:00+02:00', $n->title);
        self::assertSame('3 passe(s) EN ÉCHEC — voir les journaux', $n->reasons[0]);
        self::assertSame('démarrage précédent refusé : configuration illisible', $n->reasons[array_key_last($n->reasons)]);

        $quiet = implode("\n", (new JobFormatter())->heartbeat(4, 2, [], '2026-09-13T08:00:00+02:00')->reasons);
        self::assertStringNotContainsString('ÉCHEC', $quiet);
        self::assertStringNotContainsString('refusé', $quiet);
    }

    public function testHealthNoticesAreTheSharedOnes(): void
    {
        $store = JobStore::open(':memory:');
        $store->runs()->recordRun('linkedin', 0, false, 'IMAP refusé', '2026-09-13T10:00:00Z', 5);
        $health = $store->runs()->health('linkedin', '2026-09-13T10:00:00Z');

        self::assertSame(NotificationKind::SOURCE_HEALTH, (new JobFormatter())->sourceHealth($health)->kind);
        self::assertSame(NotificationKind::SOURCE_RECOVERED, (new JobFormatter())->sourceRecovered($health)->kind);
    }

    private static function offer(string $mode = 'hybrid'): JobListing
    {
        return new JobListing(
            sourceName: 'linkedin', externalId: '4461976159', title: 'Senior Software Engineer', company: 'Aneo', location: 'Montrouge',
            url: 'https://www.linkedin.com/jobs/view/4461976159/', workMode: $mode,
        );
    }
}
