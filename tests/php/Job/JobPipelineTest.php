<?php

declare(strict_types=1);

namespace Scout\Tests\Job;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Adapters\SourceError;
use Scout\Core\Notify\Notification;
use Scout\Core\Notify\NotificationKind;
use Scout\Core\Notify\Notifier;
use Scout\Core\SourceStatus;
use Scout\Job\JobClassifier;
use Scout\Job\JobCriteriaLoader;
use Scout\Job\JobListing;
use Scout\Job\JobOutcome;
use Scout\Job\JobPipeline;
use Scout\Job\JobScorer;
use Scout\Job\JobStore;
use Scout\Tests\Support\DeliveringChannel;

/**
 * fetch → read → judge → record → notify, for job offers. The car pipeline's guarantees, each
 * asserted here rather than assumed to have travelled with the copy, plus the one the car store
 * cannot express: a rollup does not cover a push.
 */
#[CoversClass(JobPipeline::class)]
final class JobPipelineTest extends TestCase
{
    private const string NOW = '2026-09-13T10:00:00Z';

    public function testANewMatchIsPushedOnceWithTheSourceLeadingItsTitleAndMarkedAsAPush(): void
    {
        [$pipeline, $channel, $store] = $this->pipeline();
        $source = new FakeJobSource('linkedin', $store, [$this->strong('s1')]);

        $first = $pipeline->runOnce([$source], self::NOW);
        $pipeline->runOnce([$source], '2026-09-13T10:15:00Z');

        $pushes = $this->ofKind($channel, NotificationKind::MATCH);
        self::assertCount(1, $pushes, 'new exactly once — an alert is re-read on every pass of its window');
        self::assertStringStartsWith('linkedin · ', $pushes[0]->title);
        self::assertStringContainsString('Lead Développeur PHP Symfony · Acme · Paris · télétravail', $pushes[0]->title);
        self::assertSame(1, $first->matches);
        self::assertSame(1, $first->notified);
        self::assertTrue($store->wasNotifiedAs($this->key($store, 's1'), JobStore::AS_MATCH));
    }

    public function testARejectedOfferIsNeverPushedIsRecordedAndSaysWhy(): void
    {
        [$pipeline, $channel, $store] = $this->pipeline();

        $result = $pipeline->runOnce([new FakeJobSource('linkedin', $store, [$this->offer('d1', 'Data Engineer')])], self::NOW);

        self::assertSame([], $this->ofKind($channel, NotificationKind::MATCH));
        self::assertSame(1, $result->rejectedCount);
        self::assertSame(0, $result->matches);
        self::assertStringContainsString('linkedin:d1', $result->rejected[0]);
        self::assertStringContainsString('intitulé exclu : data', $result->rejected[0]);
        self::assertSame(JobOutcome::REJECT, $store->outcomeOf($this->key($store, 'd1')), 'hidden from the phone, never from the store');
        self::assertFalse($store->wasNotified($this->key($store, 'd1')));
    }

    public function testSeedingMarksEverythingSeenAndPushesNothing(): void
    {
        [$pipeline, $channel, $store] = $this->pipeline();
        $source = new FakeJobSource('linkedin', $store, [$this->strong('s1'), $this->weak('w1')]);

        $seed = $pipeline->runOnce([$source], self::NOW, seedOnly: true);
        $pipeline->runOnce([$source], '2026-09-13T10:15:00Z');

        self::assertSame([], $this->ofKind($channel, NotificationKind::MATCH), 'the market already watched is never announced');
        self::assertSame(0, $seed->matches, 'a seed judges nothing');
        self::assertTrue($store->wasNotifiedAs($this->key($store, 's1'), JobStore::AS_MATCH));
        self::assertFalse($store->isSeenSetEmpty());
    }

    public function testAFailingSourceIsOneFailedSourceNotAnEmptyPass(): void
    {
        [$pipeline, $channel, $store] = $this->pipeline();

        $result = $pipeline->runOnce([
            new FakeJobSource('broken', $store, throw: new SourceError('broken', 'IMAP refusé')),
            new FakeJobSource('crashed', $store, throw: new \LogicException('boom')),
            new FakeJobSource('linkedin', $store, [$this->strong('s1')]),
        ], self::NOW);

        self::assertSame(2, $result->sourcesFailed);
        self::assertSame(1, $result->sourcesRun);
        self::assertCount(1, $this->ofKind($channel, NotificationKind::MATCH), 'the healthy source still pushed');
        self::assertStringContainsString('IMAP refusé', $result->errors[0]);
        self::assertStringContainsString('LogicException: boom', $result->errors[1]);
        self::assertTrue($store->runs()->health('broken', self::NOW)->status->isAlerting(), 'recorded as a failed run, not as a quiet one');
    }

    public function testASourceGoneBrokenAlertsOncePerEscalationAndRecoversOnce(): void
    {
        [$pipeline, $channel, $store] = $this->pipeline();
        $good = new FakeJobSource('linkedin', $store, [$this->weak('w1')]);
        $bad = new FakeJobSource('linkedin', $store, throw: new SourceError('linkedin', 'IMAP refusé'));

        $pipeline->runOnce([$good], '2026-09-13T10:00:00Z');
        foreach (['10:15', '10:30', '10:45', '11:00'] as $t) {
            $pipeline->runOnce([$bad], '2026-09-13T' . $t . ':00Z');
        }
        // Recovery is a WINDOW, not a run — the car test's reason.
        $minute = 75;
        do {
            $now = sprintf('2026-09-13T%02d:%02d:00Z', 10 + intdiv($minute, 60), $minute % 60);
            $pipeline->runOnce([$good], $now);
            $minute += 15;
        } while ($good->health($now)->status !== SourceStatus::OK && $minute < 60 * 13);
        self::assertSame(SourceStatus::OK, $good->health($now)->status, 'the window drained within the cap');

        $alerts = $this->ofKind($channel, NotificationKind::SOURCE_HEALTH);
        self::assertGreaterThanOrEqual(1, count($alerts));
        self::assertLessThanOrEqual(2, count($alerts), 'never one alert per failing pass');
        self::assertStringContainsString('linkedin', $alerts[0]->title);
        self::assertCount(1, $this->ofKind($channel, NotificationKind::SOURCE_RECOVERED));
    }

    public function testAnOfferIsRecordedAtItsOwnObservationTimeSoAnOlderReReadChangesNothing(): void
    {
        [$pipeline, , $store] = $this->pipeline();
        $newer = $this->offer('o1', 'Lead Developer PHP', observedAt: '2026-09-12T10:00:00Z');
        $older = $this->offer('o1', 'Ancien intitulé', observedAt: '2026-09-11T10:00:00Z');

        $pipeline->runOnce([new FakeJobSource('linkedin', $store, [$newer])], self::NOW);
        $pipeline->runOnce([new FakeJobSource('linkedin', $store, [$older])], '2026-09-13T10:15:00Z');

        self::assertSame('Lead Developer PHP', $store->snapshot($this->key($store, 'o1'))?->title, 'a superseded sighting overwrites no verdict');
    }

    public function testARefusedPushIsLeftUnmarkedAndRetriedOnTheNextPass(): void
    {
        [$pipeline, $channel, $store] = $this->pipeline();
        $channel->refuses = [NotificationKind::MATCH];
        $source = new FakeJobSource('linkedin', $store, [$this->strong('s1')]);

        $refused = $pipeline->runOnce([$source], self::NOW);

        self::assertSame(1, $refused->undelivered);
        self::assertSame(0, $refused->notified);
        self::assertFalse($store->wasNotified($this->key($store, 's1')));
        self::assertSame(1, $store->pendingRollupCount(), 'a failed push is exactly what the rollup queue holds');

        $channel->refuses = [];
        $pipeline->runOnce([$source], '2026-09-13T10:15:00Z');

        self::assertCount(1, $this->ofKind($channel, NotificationKind::MATCH));
        self::assertTrue($store->wasNotifiedAs($this->key($store, 's1'), JobStore::AS_MATCH));
    }

    // ── the push gate: a match under `push_min_score` is queued for the rollup ──────────────────

    public function testWithoutAGateEveryMatchIsPushed(): void
    {
        [$pipeline, $channel, $store] = $this->pipeline();

        $result = $pipeline->runOnce([new FakeJobSource('linkedin', $store, [$this->strong('s1'), $this->weak('w1')])], self::NOW);

        self::assertCount(2, $this->ofKind($channel, NotificationKind::MATCH));
        self::assertSame(0, $result->queuedLowScore);
        self::assertSame(0, $store->pendingRollupCount());
    }

    public function testAMatchBelowTheGateIsQueuedNotPushedAndOneOverItIsPushed(): void
    {
        [$pipeline, $channel, $store] = $this->gatedPipeline(50);

        $result = $pipeline->runOnce([new FakeJobSource('linkedin', $store, [$this->strong('s1'), $this->weak('w1')])], self::NOW);

        $pushes = $this->ofKind($channel, NotificationKind::MATCH);
        self::assertCount(1, $pushes);
        self::assertStringContainsString('Lead Développeur', $pushes[0]->title);
        self::assertSame(2, $result->matches, 'held back is not rejected');
        self::assertSame(1, $result->queuedLowScore);
        self::assertSame(1, $store->pendingRollupCount());
        self::assertFalse($store->wasNotified($this->key($store, 'w1')));
    }

    /** The gate is `score < push_min_score`: a score exactly on the line is pushed. */
    public function testAnOfferExactlyAtTheGateIsPushed(): void
    {
        $strong = $this->strong('s1');
        [$pipeline, $channel, $store] = $this->gatedPipeline($this->scoreOf($strong));

        $result = $pipeline->runOnce([new FakeJobSource('linkedin', $store, [$strong])], self::NOW);

        self::assertCount(1, $this->ofKind($channel, NotificationKind::MATCH));
        self::assertSame(0, $result->queuedLowScore);
    }

    public function testSeedingUnderAGateLeavesNothingForTheFirstRollup(): void
    {
        [$pipeline, , $store] = $this->gatedPipeline(50);
        $source = new FakeJobSource('linkedin', $store, [$this->weak('w1')]);

        $pipeline->runOnce([$source], self::NOW, seedOnly: true);
        $after = $pipeline->runOnce([$source], '2026-09-13T10:15:00Z');

        self::assertSame(0, $store->pendingRollupCount(), 'the backlog is seen, never drained into the first rollup');
        self::assertSame(0, $after->queuedLowScore);
    }

    /** An offer already in a sent rollup is not reported as still waiting on every later pass. */
    public function testARolledUpOfferIsNotCountedAsQueuedAgain(): void
    {
        [$pipeline, , $store] = $this->gatedPipeline(50);
        $source = new FakeJobSource('linkedin', $store, [$this->weak('w1')]);

        self::assertSame(1, $pipeline->runOnce([$source], self::NOW)->queuedLowScore);
        $store->markNotified($this->key($store, 'w1'), '2026-09-13T10:05:00Z', JobStore::AS_ROLLUP);
        $again = $pipeline->runOnce([$source], '2026-09-13T10:15:00Z');

        self::assertSame(0, $again->queuedLowScore);
        self::assertSame(1, $again->matches);
    }

    /** A rollup does not cover a push: an offer rolled up that now clears the gate is pushed, once. */
    public function testARolledUpOfferThatNowClearsTheGateIsPromotedToOnePush(): void
    {
        $store = JobStore::open(':memory:');
        [$closed, $closedChannel] = $this->gatedPipeline(100, $store);
        $source = new FakeJobSource('linkedin', $store, [$this->strong('s1')]);

        $closed->runOnce([$source], self::NOW);
        $store->markNotified($this->key($store, 's1'), '2026-09-13T10:05:00Z', JobStore::AS_ROLLUP);

        [$open, $channel] = $this->gatedPipeline(50, $store);
        $open->runOnce([$source], '2026-09-13T10:15:00Z');
        $open->runOnce([$source], '2026-09-13T10:30:00Z');

        self::assertSame([], $this->ofKind($closedChannel, NotificationKind::MATCH));
        self::assertCount(1, $this->ofKind($channel, NotificationKind::MATCH), 'promoted exactly once');
        self::assertTrue($store->wasNotifiedAs($this->key($store, 's1'), JobStore::AS_MATCH));
    }

    // ── row 41: every offer of a source failing one filter is a warning ─────────────────────────

    public function testASourceWhoseEveryOfferFailsTheSameFilterIsWarnedAbout(): void
    {
        [$pipeline, , $store] = $this->pipeline();
        $offers = array_map(fn (string $id): JobListing => $this->offer($id, 'Développeur PHP', payText: 'Entre 40 k € et 45 k € par an'), ['p1', 'p2', 'p3']);

        $result = $pipeline->runOnce([new FakeJobSource('linkedin', $store, $offers)], self::NOW);

        self::assertSame(3, $result->rejectedCount);
        self::assertCount(1, $result->warnings);
        self::assertStringContainsString('linkedin : 3 annonce(s) sur 3', $result->warnings[0]);
        self::assertSame(0, $result->sourcesFailed, 'a warning, never a failure');
    }

    public function testOneSurvivingOfferRaisesNoSameFilterWarning(): void
    {
        [$pipeline, , $store] = $this->pipeline();
        $offers = [
            $this->offer('p1', 'Développeur PHP', payText: 'Entre 40 k € et 45 k € par an'),
            $this->offer('p2', 'Développeur PHP', payText: 'Entre 40 k € et 45 k € par an'),
            $this->strong('s1'),
        ];

        $result = $pipeline->runOnce([new FakeJobSource('linkedin', $store, $offers)], self::NOW);

        self::assertSame([], $result->warnings);
    }

    // ── row 36: a processed alert is acknowledged — AFTER the store recorded it ─────────────────

    public function testAnEmailSourceIsAcknowledgedAfterTheStoreRecordedItsPass(): void
    {
        [$pipeline, , $store] = $this->pipeline();
        $source = new AcknowledgingJobSource('mail', [$this->strong('s1')], $store);

        $result = $pipeline->runOnce([$source], self::NOW);

        self::assertSame(['acknowledged-after-recording'], $source->events);
        self::assertSame([], $result->errors);
    }

    public function testASourceWhoseFetchFailedIsNeverAcknowledged(): void
    {
        [$pipeline, , $store] = $this->pipeline();
        $source = new AcknowledgingJobSource('mail', [], $store, throwOnFetch: new SourceError('mail', 'boom'));

        $pipeline->runOnce([$source], self::NOW);

        self::assertSame([], $source->events);
    }

    public function testAFailedAcknowledgementIsReportedAndDoesNotFailThePass(): void
    {
        [$pipeline, , $store] = $this->pipeline();
        $source = new AcknowledgingJobSource('mail', [$this->strong('s1')], $store, throwOnAck: new SourceError('mail', 'STORE refused by the server'));

        $result = $pipeline->runOnce([$source], self::NOW);

        self::assertSame(0, $result->sourcesFailed);
        self::assertSame(1, $result->itemsParsed);
        self::assertCount(1, $result->errors);
        self::assertStringContainsString('STORE refused', $result->errors[0]);
        self::assertFalse($store->isSeenSetEmpty(), 'recorded regardless');
    }

    public function testSeedingAcknowledgesTheSource(): void
    {
        [$pipeline, , $store] = $this->pipeline();
        $source = new AcknowledgingJobSource('mail', [$this->strong('s1')], $store);

        $pipeline->runOnce([$source], self::NOW, seedOnly: true);

        self::assertSame(['acknowledged-after-recording'], $source->events);
    }

    // ────────────────────────────────────────────────────────────────────────────────────────────

    /** @return array{0: JobPipeline, 1: DeliveringChannel, 2: JobStore} */
    private function pipeline(): array
    {
        $store = JobStore::open(':memory:');
        $channel = new DeliveringChannel();

        return [new JobPipeline(JobCriteriaTest::shipped(), $store, new Notifier([$channel])), $channel, $store];
    }

    /** @return array{0: JobPipeline, 1: DeliveringChannel, 2: JobStore} */
    private function gatedPipeline(int $pushMinScore, ?JobStore $store = null): array
    {
        $store ??= JobStore::open(':memory:');
        $data = JobCriteriaTest::shippedArray();
        $data['notify']['push_min_score'] = $pushMinScore;
        $channel = new DeliveringChannel();

        return [new JobPipeline(JobCriteriaLoader::fromArray($data), $store, new Notifier([$channel])), $channel, $store];
    }

    /** Stack, pay over target, lead, craft and product signals, full remote: high on today's weights. */
    private function strong(string $id): JobListing
    {
        return new JobListing(
            sourceName: 'linkedin', externalId: $id, title: 'Lead Développeur PHP Symfony', company: 'Acme', location: 'Paris',
            description: 'CDI. PHP 8, Symfony, TypeScript, React. TDD, clean code. Éditeur SaaS.',
            url: 'https://www.linkedin.com/jobs/view/' . $id . '/', workMode: 'remote', payText: 'Entre 65 k € et 75 k € par an',
        );
    }

    /** A bare card: every component but the title level is unknown, so it scores low without being rejected. */
    private function weak(string $id): JobListing
    {
        return new JobListing(sourceName: 'linkedin', externalId: $id, title: 'Software Engineer', company: 'Acme', location: 'Paris', url: 'https://www.linkedin.com/jobs/view/' . $id . '/');
    }

    private function offer(string $id, string $title, ?string $payText = null, ?string $observedAt = null): JobListing
    {
        return new JobListing(sourceName: 'linkedin', externalId: $id, title: $title, company: 'Acme', location: 'Paris', payText: $payText, observedAt: $observedAt);
    }

    private function key(JobStore $store, string $id): string
    {
        return $store->dedupKey(new JobListing(sourceName: 'linkedin', externalId: $id));
    }

    private function scoreOf(JobListing $offer): int
    {
        $verdict = (new JobScorer())->judge($offer, (new JobClassifier())->read($offer), JobCriteriaTest::shipped(), new \DateTimeImmutable(self::NOW));
        self::assertNotNull($verdict->score, 'the offer must match for its score to be a gate');

        return $verdict->score;
    }

    /** @return list<Notification> */
    private function ofKind(DeliveringChannel $channel, NotificationKind $kind): array
    {
        return array_values(array_filter($channel->sent, static fn (Notification $n): bool => $n->kind === $kind));
    }
}
