<?php

declare(strict_types=1);

namespace Scout\Car;

use Scout\Adapters\AcknowledgesMessages;
use Scout\Adapters\FeedFreshness;
use Scout\Adapters\SourceError;
use Scout\Core\Notify\Notifier;
use Scout\Core\Redact;
use Scout\Core\SameFilterWarning;

/**
 * fetch → classify → judge → record → notify, for cars. The rent pipeline's shape with what the car
 * domain does not have removed (no digest, no cross-portal clustering yet) and what it learnt kept:
 *
 * - a source that throws is ONE failed source, recorded as such, never an empty pass (hard rule 3);
 * - every listing is recorded at ITS observation time (`observedAt`, or the pass time), so a
 *   re-read older message is a superseded sighting and never a price drop;
 * - `--seed` marks everything currently published as seen and notified WITHOUT pushing — and for
 *   a sitemap source it records the whole INDEX without fetching a single lot page;
 * - a match is pushed once and marked per listing; a failed send leaves it unmarked for the retry;
 * - health alerts on every alerting status, once per cooldown, and one recovery notice.
 */
final readonly class VehiclePipeline
{
    public function __construct(
        private readonly VehicleCriteria $criteria,
        private readonly VehicleStore $store,
        private readonly Notifier $notifier,
        private readonly VehicleClassifier $classifier = new VehicleClassifier(),
        private readonly VehicleScorer $scorer = new VehicleScorer(),
        private readonly VehicleFormatter $formatter = new VehicleFormatter(),
        private readonly \DateTimeZone $zone = new \DateTimeZone('Europe/Paris'),
    ) {}

    /** @param list<VehicleSource> $sources */
    public function runOnce(array $sources, string $nowIso, bool $seedOnly = false): VehicleRunResult
    {
        $now = new \DateTimeImmutable($nowIso);
        [$year, $month] = [(int) $now->format('Y'), (int) $now->format('n')];

        $sourcesRun = $sourcesFailed = $itemsParsed = $matches = $rejectedCount = $priceDrops = $notified = $undelivered = 0;
        $errors = $rejected = [];
        $queuedLowScore = 0;
        /** @var array<string, array{judged: int, by: array<string, int>}> $filterTally row 41 */
        $filterTally = [];

        foreach ($sources as $source) {
            $started = microtime(true);
            try {
                $listings = $seedOnly && $source instanceof IndexedVehicleSource ? $source->seedIndex() : $source->fetch();
                ++$sourcesRun;
            } catch (SourceError $e) {
                ++$sourcesFailed;
                $errors[] = $source->name() . ' : ' . Redact::text($e->getMessage());
                $this->store->runs()->recordRun($source->name(), 0, false, $e->getMessage(), $nowIso, (int) ((microtime(true) - $started) * 1000));
                continue;
            } catch (\Throwable $e) {
                ++$sourcesFailed;
                $errors[] = $source->name() . ' : ' . Redact::text($e::class . ': ' . $e->getMessage());
                $this->store->runs()->recordRun($source->name(), 0, false, $e::class . ': ' . $e->getMessage(), $nowIso, (int) ((microtime(true) - $started) * 1000));
                continue;
            }
            $feedNewestAt = $source instanceof FeedFreshness ? $source->newestFeedItemAt() : null;
            // THE FEED'S SIZE, not the novel slice of it: a sitemap source returns only the lots the
            // seen-set does not know, and after the seed that is 0 on a normal pass — baselining on
            // it fired a false warn_drop on the first live pass (22:37, "0 contre une moyenne de 1718").
            $itemCount = $source instanceof IndexedVehicleSource ? ($source->lastIndexSize() ?? count($listings)) : count($listings);
            $this->store->runs()->recordRun($source->name(), $itemCount, true, null, $nowIso, (int) ((microtime(true) - $started) * 1000), $feedNewestAt);
            $itemsParsed += count($listings);

            foreach ($listings as $car) {
                $sighting = $this->store->record($car, $car->observedAt ?? $nowIso);

                if ($seedOnly) {
                    $this->store->markNotified($sighting->dedupKey, $nowIso);
                    continue;
                }

                $verdict = $this->scorer->judge($car, $this->classifier->classify($car), $this->criteria, $year, $month);
                if ($sighting->isCurrent) {
                    $this->store->recordVerdict($sighting->dedupKey, $verdict, $car);
                }

                // ROW 41: one judged car into the same-filter tally — the first reject reason names
                // the filter; `exclu : …` (the vehicle set) is the classifier working and is skipped.
                SameFilterWarning::count($filterTally, $source->name(), $verdict->outcome === VehicleOutcome::REJECT ? ($verdict->reasons[0] ?? null) : null);

                if ($verdict->outcome === VehicleOutcome::REJECT) {
                    ++$rejectedCount;
                    $rejected[] = sprintf('écartée %s:%s — %s', $car->sourceName, $car->externalId, implode(' ; ', $verdict->reasons));
                    continue;
                }
                ++$matches;

                if (!$this->store->wasNotified($sighting->dedupKey)) {
                    // A5 — ROW 6: A MATCH BELOW THE GATE IS QUEUED, NOT PUSHED. Judged, recorded and
                    // counted above; it stays unannounced and `VehicleStore::pendingRollup()` hands
                    // it to the daily rollup. `null` (every fixture) keeps the pre-A5 behaviour.
                    $pushMin = $this->criteria->notify->pushMinScore;
                    if ($pushMin !== null && ($verdict->score ?? 0) < $pushMin) {
                        // UNLESS IT IS AN AUCTION LOT THE ROLLUP WOULD REACH TOO LATE (ruling
                        // 2026-09-25): queued, it would be announced only after it had closed.
                        if (!AuctionUrgency::closesBeforeNextRollup($car->closingAt, $now, $this->criteria->notify->rollupHour, $this->zone)) {
                            ++$queuedLowScore;
                            continue;
                        }
                        $verdict = VehicleVerdict::matched((int) $verdict->score, [...$verdict->reasons, 'clôture avant le prochain récapitulatif — envoyée sans attendre'], $verdict->highPriority);
                    }

                    $failures = $this->notifier->send($this->formatter->match($car, $verdict));
                    if ($this->notifier->delivered($failures)) {
                        ++$notified;
                        $this->store->markNotified($sighting->dedupKey, $nowIso);
                    } else {
                        ++$undelivered;
                    }
                    continue;
                }

                if ($sighting->isPriceDrop && $sighting->previousPriceEur !== null && $sighting->priceEur !== null
                    && $this->criteria->notify->isNotableDrop($sighting->previousPriceEur, $sighting->priceEur)) {
                    ++$priceDrops;
                    $failures = $this->notifier->send($this->formatter->priceDrop($car, $sighting->previousPriceEur, $sighting->priceEur));
                    if ($this->notifier->delivered($failures)) {
                        ++$notified;
                    } else {
                        ++$undelivered;
                    }
                }
            }

            // ROW 36 — every listing of this source is `record()`ed above, so its messages are
            // marked processed HERE and nowhere earlier. A refusal is reported and the pass goes
            // on: the cars are on disk, the flag is for the human reading the label. The rent
            // pipeline carries the same block after its recording loop; keep the two rules equal.
            if ($source instanceof AcknowledgesMessages) {
                try {
                    $source->acknowledge();
                } catch (SourceError $e) {
                    $errors[] = Redact::text($e->getMessage());
                } catch (\Throwable $e) {
                    $errors[] = $source->name() . ' : ' . Redact::text($e::class . ': ' . $e->getMessage());
                }
            }
        }

        $undelivered += $this->alertOnHealth($sources, $nowIso);

        return new VehicleRunResult(
            sourcesRun: $sourcesRun, sourcesFailed: $sourcesFailed, itemsParsed: $itemsParsed, matches: $matches,
            rejectedCount: $rejectedCount, priceDrops: $priceDrops, notified: $notified, undelivered: $undelivered,
            errors: $errors, rejected: $rejected, warnings: SameFilterWarning::warnings($filterTally), queuedLowScore: $queuedLowScore,
        );
    }

    /** @param list<VehicleSource> $sources */
    private function alertOnHealth(array $sources, string $nowIso): int
    {
        $undelivered = 0;
        $cooldown = $this->criteria->notify->sourceAlertCooldownHours;
        $runs = $this->store->runs();

        foreach ($sources as $source) {
            $health = $source->health($nowIso);
            if (!$health->status->isAlerting()) {
                if ($runs->clearAlerts($source->name())) {
                    if (!$this->notifier->delivered($this->notifier->send($this->formatter->sourceRecovered($health)))) {
                        ++$undelivered;
                    }
                }
                continue;
            }
            if (!$runs->shouldAlert($source->name(), $health->status->value, $nowIso, $cooldown)) {
                continue;
            }
            if ($this->notifier->delivered($this->notifier->send($this->formatter->sourceHealth($health)))) {
                $runs->markAlerted($source->name(), $health->status->value, $nowIso);
            } else {
                ++$undelivered;
            }
        }

        return $undelivered;
    }
}
