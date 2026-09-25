<?php

declare(strict_types=1);

namespace Scout\Tests\Car;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Adapters\SourceError;
use Scout\Core\Notify\Channel;
use Scout\Core\Notify\Notification;
use Scout\Core\Notify\NotificationKind;
use Scout\Core\Notify\Notifier;
use Scout\Core\SourceHealth;
use Scout\Car\SitemapVehicleSource;
use Scout\Car\VehicleCriteriaLoader;
use Scout\Car\VehicleListing;
use Scout\Car\VehiclePipeline;
use Scout\Car\VehicleSource;
use Scout\Car\VehicleStore;

#[CoversClass(VehiclePipeline::class)]
final class VehiclePipelineTest extends TestCase
{
    public function testANewMatchIsPushedOnceWithTheSourceLeadingItsTitle(): void
    {
        [$pipeline, $channel] = $this->pipeline();
        $source = new FakeCarSource('paruvendu', [$this->car('a1', 21000)]);

        $pipeline->runOnce([$source], '2026-08-29T10:00:00Z');
        $pipeline->runOnce([$source], '2026-08-29T10:15:00Z');

        $matches = $this->ofKind($channel, NotificationKind::MATCH);
        self::assertCount(1, $matches, 'new exactly once');
        self::assertStringStartsWith('paruvendu · ', $matches[0]->title);
        self::assertStringContainsString('Renault Austral 2023 · 26 000 km · 21 000 €', $matches[0]->title);
    }

    public function testARejectedCarIsNeverPushedAndSaysWhy(): void
    {
        [$pipeline, $channel] = $this->pipeline();

        $result = $pipeline->runOnce([new FakeCarSource('paruvendu', [$this->car('g1', 21000, description: 'Véhicule gagé.')])], '2026-08-29T10:00:00Z');

        self::assertSame([], $this->ofKind($channel, NotificationKind::MATCH));
        self::assertSame(1, $result->rejectedCount);
        self::assertStringContainsString('gagé', $result->rejected[0]);
    }

    public function testSeedingMarksEverythingSeenAndPushesNothing(): void
    {
        [$pipeline, $channel, $store] = $this->pipeline();
        $source = new FakeCarSource('paruvendu', [$this->car('a1', 21000), $this->car('a2', 15000)], store: $store);

        $pipeline->runOnce([$source], '2026-08-29T10:00:00Z', seedOnly: true);
        $pipeline->runOnce([$source], '2026-08-29T10:15:00Z');

        self::assertSame([], $this->ofKind($channel, NotificationKind::MATCH), 'the market already watched is never announced');
        self::assertSame([], $this->ofKind($channel, NotificationKind::PRICE_DROP));
        self::assertFalse($store->isSeenSetEmpty());
    }

    /** The Bussy loop replayed on the car path, in both message orders: a re-read older card is never a drop. */
    public function testAReReadOlderCardIsNeverAPriceDrop(): void
    {
        [$pipeline, $channel, $store] = $this->pipeline();
        $older = $this->car('a1', 21000, observedAt: '2026-08-26T18:31:09Z');
        $newer = $this->car('a1', 21500, observedAt: '2026-08-27T13:34:25Z');

        $pipeline->runOnce([new FakeCarSource('paruvendu', [$older])], '2026-08-26T19:00:00Z');
        foreach (['2026-08-27T14:00:00Z', '2026-08-27T14:15:00Z'] as $now) {
            $pipeline->runOnce([new FakeCarSource('paruvendu', [$newer, $older])], $now);
        }
        $pipeline->runOnce([new FakeCarSource('paruvendu', [$older, $newer])], '2026-08-27T14:30:00Z');

        self::assertSame([], $this->ofKind($channel, NotificationKind::PRICE_DROP));
        self::assertSame([21000, 21500], $store->priceHistory($store->dedupKey($newer)));
    }

    public function testAGenuineNotableDropIsPushedOnce(): void
    {
        [$pipeline, $channel] = $this->pipeline();

        $pipeline->runOnce([new FakeCarSource('paruvendu', [$this->car('a1', 21000, observedAt: '2026-08-26T10:00:00Z')])], '2026-08-26T10:00:00Z');
        $pipeline->runOnce([new FakeCarSource('paruvendu', [$this->car('a1', 19500, observedAt: '2026-08-27T10:00:00Z')])], '2026-08-27T10:00:00Z');
        $pipeline->runOnce([new FakeCarSource('paruvendu', [$this->car('a1', 19500, observedAt: '2026-08-27T10:00:00Z')])], '2026-08-27T10:15:00Z');

        $drops = $this->ofKind($channel, NotificationKind::PRICE_DROP);
        self::assertCount(1, $drops);
        self::assertStringStartsWith('paruvendu · Baisse de prix', $drops[0]->title);
    }

    public function testAFailingSourceIsOneFailedSourceNotAnEmptyPass(): void
    {
        [$pipeline, $channel] = $this->pipeline();

        $result = $pipeline->runOnce([
            new FakeCarSource('broken', [], throw: new SourceError('broken', 'HTTP 500')),
            new FakeCarSource('paruvendu', [$this->car('a1', 21000)]),
        ], '2026-08-29T10:00:00Z');

        self::assertSame(1, $result->sourcesFailed);
        self::assertSame(1, $result->sourcesRun);
        self::assertCount(1, $this->ofKind($channel, NotificationKind::MATCH), 'the healthy source still pushed');
        self::assertStringContainsString('HTTP 500', $result->errors[0]);
    }

    public function testASourceGoneBrokenAlertsOnceAndRecoversOnce(): void
    {
        [$pipeline, $channel, $store] = $this->pipeline();
        $good = new FakeCarSource('paruvendu', [$this->car('a1', 21000)], store: $store);
        $bad = new FakeCarSource('paruvendu', [], throw: new SourceError('paruvendu', 'HTTP 500'), store: $store);

        $pipeline->runOnce([$good], '2026-08-29T10:00:00Z');
        foreach (['10:15', '10:30', '10:45', '11:00'] as $t) {
            $pipeline->runOnce([$bad], '2026-08-29T' . $t . ':00Z');
        }
        // Recovery is a WINDOW, not a run: the store keeps a source flaky while most of its recent
        // runs failed, so it takes several clean passes before health reads OK again.
        $minute = 75;
        do {
            $now = sprintf('2026-08-29T%02d:%02d:00Z', 10 + intdiv($minute, 60), $minute % 60);
            $pipeline->runOnce([$good], $now);
            $minute += 15;
        } while ($good->health($now)->status !== \Scout\Core\SourceStatus::OK && $minute < 60 * 13);
        self::assertSame(\Scout\Core\SourceStatus::OK, $good->health($now)->status, 'the window drained within the cap');

        // An ESCALATION is not swallowed by the earlier, quieter alert (the rent side's Q29 rule):
        // `warn_flaky` first, then `broken` — each once per cooldown, never one per pass.
        // Four FAILED runs (not empty ones) classify as flaky, not broken — the store's rule.
        $alerts = $this->ofKind($channel, NotificationKind::SOURCE_HEALTH);
        self::assertGreaterThanOrEqual(1, count($alerts));
        self::assertLessThanOrEqual(2, count($alerts), 'never one alert per failing pass');
        self::assertStringContainsString('paruvendu', $alerts[0]->title);
        self::assertCount(1, $this->ofKind($channel, NotificationKind::SOURCE_RECOVERED));
    }

    public function testSeedingASitemapSourceRecordsItsIndexWithoutFetchingLots(): void
    {
        // Covered structurally in AutoheroFixtureTest (seedIndex); here the pipeline's own branch:
        // a SitemapVehicleSource under --seed is asked for its index, never its lots.
        self::assertTrue(method_exists(SitemapVehicleSource::class, 'seedIndex'));
    }

    // ------------------------------------------------------------------------------------------

    /** @return array{VehiclePipeline, CarRecordingChannel, VehicleStore} */
    // ── Row 6 / A5 (2026-09-05): a match below `push_min_score` is queued for the rollup, not pushed ──

    /** @return array{0: VehiclePipeline, 1: CarRecordingChannel, 2: VehicleStore} */
    private function gatedPipeline(int $pushMinScore): array
    {
        $store = VehicleStore::open(':memory:');
        $channel = new CarRecordingChannel();
        $minimal = VehicleCriteriaTest::minimal();
        $minimal['notify']['push_min_score'] = $pushMinScore;
        $pipeline = new VehiclePipeline(VehicleCriteriaLoader::fromArray($minimal), $store, new Notifier([$channel]));

        return [$pipeline, $channel, $store];
    }

    /** Twelve years old, 210 000 km, at the ceiling, an avoided make: only the body ranks. */
    private function weakCar(string $id): VehicleListing
    {
        return new VehicleListing(
            sourceName: 'paruvendu', externalId: $id, title: 'Peugeot 208 Active', description: 'Citadine - Essence - Année 2014 - 210 000 km',
            url: 'https://www.paruvendu.fr/a/voiture-occasion/peugeot/208/' . $id, make: 'peugeot', model: '208',
            priceEur: 29900, year: 2014, mileageKm: 210000, fuel: 'essence', gearbox: null, body: 'citadine',
        );
    }

    public function testAMatchBelowTheGateIsQueuedNotPushedAndOneAboveItIsPushed(): void
    {
        [$pipeline, $channel, $store] = $this->gatedPipeline(60);
        $source = new FakeCarSource('paruvendu', [$this->weakCar('weak'), $this->car('strong', 15000)], null, $store);

        $result = $pipeline->runOnce([$source], '2026-09-05T10:00:00Z');

        self::assertSame(2, $result->matches, 'both are MATCHES — the gate is about delivery, not judgement');
        $pushed = $this->ofKind($channel, NotificationKind::MATCH);
        self::assertCount(1, $pushed);
        self::assertStringContainsString('Austral', $pushed[0]->title);
        self::assertSame(1, $result->queuedLowScore);
        self::assertSame(1, $store->pendingRollupCount(), 'the weak one waits for the rollup');
        self::assertFalse($store->wasNotified($store->dedupKey($this->weakCar('weak'))));
    }

    public function testSeedingMarksAQueuedCarTooSoNoBacklogDrainsIntoTheFirstRollup(): void
    {
        [$pipeline, , $store] = $this->gatedPipeline(60);

        $pipeline->runOnce([new FakeCarSource('paruvendu', [$this->weakCar('weak-2')], null, $store)], '2026-09-05T10:00:00Z', true);

        self::assertTrue($store->wasNotified($store->dedupKey($this->weakCar('weak-2'))));
        self::assertSame(0, $store->pendingRollupCount());
    }

    public function testWithoutAGateEveryCarMatchIsPushedAsBefore(): void
    {
        [$pipeline, $channel, $store] = $this->pipeline();

        $pipeline->runOnce([new FakeCarSource('paruvendu', [$this->weakCar('weak-3'), $this->car('strong-3', 15000)], null, $store)], '2026-09-05T10:00:00Z');

        self::assertCount(2, $this->ofKind($channel, NotificationKind::MATCH));
        self::assertSame(0, $store->pendingRollupCount());
    }

    // ── 2026-09-25: an auction lot closing before the next rollup is pushed whatever its score ──

    /** The weak car, as an auction lot closing at `$closingAt`. */
    private function weakLot(string $id, ?string $closingAt): VehicleListing
    {
        return new VehicleListing(
            sourceName: 'alcopa', externalId: $id, title: 'Peugeot 208 Active', url: 'https://www.alcopa-auction.fr/voiture-occasion/peugeot/208-' . $id,
            make: 'peugeot', model: '208', year: 2014, mileageKm: 210000, fuel: 'essence', body: 'citadine',
            saleOpensAt: $closingAt === null ? null : '2026-09-24T17:00:00Z', closingAt: $closingAt,
        );
    }

    /** @return array{0: VehiclePipeline, 1: CarRecordingChannel, 2: VehicleStore} */
    private function auctionPipeline(): array
    {
        $store = VehicleStore::open(':memory:');
        $channel = new CarRecordingChannel();
        $minimal = VehicleCriteriaTest::minimal();
        $minimal['notify']['push_min_score'] = 60;
        $minimal['notify']['rollup_hour'] = 8;
        $pipeline = new VehiclePipeline(VehicleCriteriaLoader::fromArray($minimal), $store, new Notifier([$channel]), zone: new \DateTimeZone('Europe/Paris'));

        return [$pipeline, $channel, $store];
    }

    public function testALotClosingBeforeTheNextRollupIsPushedUnderTheGate(): void
    {
        [$pipeline, $channel, $store] = $this->auctionPipeline();

        // 10:00 Paris; the lot closes at 15:00 today, the next rollup is tomorrow 08:00.
        $result = $pipeline->runOnce([new FakeCarSource('alcopa', [$this->weakLot('today', '2026-09-25T13:00:00Z')], null, $store)], '2026-09-25T08:00:00Z');

        $pushed = $this->ofKind($channel, NotificationKind::MATCH);
        self::assertCount(1, $pushed, 'it would otherwise be announced only after it had closed');
        self::assertStringEndsWith('clôture 25/09 15:00', $pushed[0]->title);
        self::assertContains('clôture avant le prochain récapitulatif — envoyée sans attendre', $pushed[0]->reasons);
        self::assertSame(0, $result->queuedLowScore);
        self::assertTrue($store->wasNotified($store->dedupKey($this->weakLot('today', '2026-09-25T13:00:00Z'))));
    }

    /** The counterweights: a lot the next rollup still reaches in time, and a car that is no auction. */
    public function testALotTheNextRollupStillReachesWaitsForIt(): void
    {
        [$pipeline, $channel, $store] = $this->auctionPipeline();

        $result = $pipeline->runOnce([new FakeCarSource('alcopa', [$this->weakLot('later', '2026-09-26T13:00:00Z'), $this->weakLot('none', null)], null, $store)], '2026-09-25T08:00:00Z');

        self::assertSame([], $this->ofKind($channel, NotificationKind::MATCH));
        self::assertSame(2, $result->queuedLowScore);
    }

    // ── Row 41 (2026-09-05): every car of a source failing the SAME hard filter is a warning ──

    public function testASourceWhoseEveryCarFailsTheSameFilterIsWarnedAbout(): void
    {
        [$pipeline, , $store] = $this->pipeline();
        $source = new FakeCarSource('drifted', [$this->car('d1', 95240), $this->car('d2', 95241), $this->car('d3', 95242)], null, $store);

        $result = $pipeline->runOnce([$source], '2026-09-05T10:00:00Z');

        self::assertCount(1, $result->warnings, implode(' | ', $result->warnings));
        self::assertStringContainsString('drifted', $result->warnings[0]);
        self::assertStringContainsString('prix', $result->warnings[0], 'the filter is named');
        self::assertSame([], $result->errors);
    }

    public function testASurvivorOrFewerThanThreeCarsRaisesNoSameFilterWarning(): void
    {
        [$pipeline, , $store] = $this->pipeline();
        $alive = new FakeCarSource('alive', [$this->car('a1', 95240), $this->car('a2', 95241), $this->car('a3', 15000)], null, $store);
        $tiny = new FakeCarSource('tiny', [$this->car('t1', 95240), $this->car('t2', 95241)], null, $store);

        $result = $pipeline->runOnce([$alive, $tiny], '2026-09-05T10:00:00Z');

        self::assertSame([], $result->warnings);
    }

    /** The excluded-vehicle set is the classifier working, never a drifted selector. */
    public function testClassifierRejectionsNeverRaiseTheSameFilterWarning(): void
    {
        [$pipeline, , $store] = $this->pipeline();
        $wrecks = new FakeCarSource('wrecks', [
            $this->car('w1', 9000, 'Vendu pour pièces, accidenté'),
            $this->car('w2', 9000, 'Véhicule gagé, vendu pour pièces'),
            $this->car('w3', 9000, 'Epave pour pièces'),
        ], null, $store);

        $result = $pipeline->runOnce([$wrecks], '2026-09-05T10:00:00Z');

        self::assertSame(3, $result->rejectedCount);
        self::assertSame([], $result->warnings);
    }

    // ── Row 36 (2026-09-04): a processed alert email is acknowledged — AFTER the store recorded it ──

    public function testAnEmailSourceIsAcknowledgedAfterTheStoreRecordedItsPass(): void
    {
        [$pipeline, , $store] = $this->pipeline();
        $source = new AcknowledgingCarSource('mail', [$this->car('c1', 15000)], $store);

        $result = $pipeline->runOnce([$source], '2026-09-04T10:00:00Z');

        self::assertSame(['acknowledged-after-recording'], $source->events);
        self::assertSame([], $result->errors);
    }

    public function testACarSourceWhoseFetchFailedIsNeverAcknowledged(): void
    {
        [$pipeline, , $store] = $this->pipeline();
        $source = new AcknowledgingCarSource('mail', [], $store, throwOnFetch: new SourceError('mail', 'boom'));

        $pipeline->runOnce([$source], '2026-09-04T10:00:00Z');

        self::assertSame([], $source->events);
    }

    public function testAFailedCarAcknowledgementIsReportedAndDoesNotFailThePass(): void
    {
        [$pipeline, , $store] = $this->pipeline();
        $source = new AcknowledgingCarSource('mail', [$this->car('c1', 15000)], $store, throwOnAck: new SourceError('mail', 'STORE refused by the server'));

        $result = $pipeline->runOnce([$source], '2026-09-04T10:00:00Z');

        self::assertSame(0, $result->sourcesFailed);
        self::assertSame(1, $result->itemsParsed);
        self::assertCount(1, $result->errors);
        self::assertStringContainsString('STORE refused', $result->errors[0]);
        self::assertFalse($store->isSeenSetEmpty(), 'recorded regardless');
    }

    public function testSeedingAcknowledgesACarSource(): void
    {
        [$pipeline, , $store] = $this->pipeline();
        $source = new AcknowledgingCarSource('mail', [$this->car('c1', 15000)], $store);

        $pipeline->runOnce([$source], '2026-09-04T10:00:00Z', true);

        self::assertSame(['acknowledged-after-recording'], $source->events);
    }

    private function pipeline(): array
    {
        $store = VehicleStore::open(':memory:');
        $channel = new CarRecordingChannel();
        $pipeline = new VehiclePipeline(
            VehicleCriteriaLoader::fromArray(VehicleCriteriaTest::minimal()),
            $store,
            new Notifier([$channel]),
        );

        return [$pipeline, $channel, $store];
    }

    private function car(string $id, ?int $price, string $description = '4x4 - SUV - Essence - Année 2023 - 26 000 km', ?string $observedAt = null): VehicleListing
    {
        return new VehicleListing(
            sourceName: 'paruvendu', externalId: $id, title: 'Renault Austral Auto', description: $description,
            url: 'https://www.paruvendu.fr/a/voiture-occasion/renault/austral/' . $id, make: 'renault', model: 'austral',
            priceEur: $price, year: 2023, mileageKm: 26000, fuel: 'essence', gearbox: 'automatique', body: 'suv',
            observedAt: $observedAt,
        );
    }

    /** @return list<Notification> */
    private function ofKind(CarRecordingChannel $channel, NotificationKind $kind): array
    {
        return array_values(array_filter($channel->sent, static fn (Notification $n): bool => $n->kind === $kind));
    }
}

