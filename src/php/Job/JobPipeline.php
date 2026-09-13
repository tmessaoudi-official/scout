<?php

declare(strict_types=1);

namespace Scout\Job;

use Scout\Adapters\AcknowledgesMessages;
use Scout\Adapters\FeedFreshness;
use Scout\Adapters\SourceError;
use Scout\Core\Notify\Notifier;
use Scout\Core\Redact;
use Scout\Core\SameFilterWarning;

/**
 * fetch → read → judge → record → notify, for job offers. The car pipeline's shape with what this
 * domain does not have removed (no price drops, no sitemap seeding) and what it learnt kept:
 *
 * - a source that throws is ONE failed source, recorded as such, never an empty pass (hard rule 3);
 * - every offer is recorded at ITS observation time (`observedAt`, or the pass time), so a re-read
 *   older alert is a superseded sighting that overwrites no verdict;
 * - `--seed` marks everything currently published as announced WITHOUT pushing;
 * - a push is marked per offer on delivery; a refused send leaves it queued for the retry;
 * - health alerts on every alerting status, once per cooldown, and one recovery notice.
 *
 * One rule the car pipeline cannot express, because its store records no announcement kind: **a
 * rollup does not cover a push.** The push check is `wasNotifiedAs(MATCH)`, so an offer rolled up
 * under a gate is still pushed once when it later clears the line, and a pushed one is never rolled up.
 */
final readonly class JobPipeline
{
    public function __construct(
        private JobCriteria $criteria,
        private JobStore $store,
        private Notifier $notifier,
        private JobClassifier $classifier = new JobClassifier(),
        private JobScorer $scorer = new JobScorer(),
        private JobFormatter $formatter = new JobFormatter(),
    ) {}

    /** @param list<JobSource> $sources */
    public function runOnce(array $sources, string $nowIso, bool $seedOnly = false): JobRunResult
    {
        $now = new \DateTimeImmutable($nowIso);

        $sourcesRun = $sourcesFailed = $itemsParsed = $matches = $rejectedCount = $notified = $undelivered = $queuedLowScore = 0;
        $errors = $rejected = [];
        /** @var array<string, array{judged: int, by: array<string, int>}> $filterTally row 41 */
        $filterTally = [];

        foreach ($sources as $source) {
            $started = microtime(true);
            try {
                $offers = $source->fetch();
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
            $this->store->runs()->recordRun($source->name(), count($offers), true, null, $nowIso, (int) ((microtime(true) - $started) * 1000), $feedNewestAt);
            $itemsParsed += count($offers);

            foreach ($offers as $offer) {
                $sighting = $this->store->record($offer, $offer->observedAt ?? $nowIso);

                if ($seedOnly) {
                    $this->store->markNotified($sighting->dedupKey, $nowIso, JobStore::AS_MATCH);
                    continue;
                }

                $verdict = $this->scorer->judge($offer, $this->classifier->read($offer), $this->criteria, $now);
                if ($sighting->isCurrent) {
                    $this->store->recordVerdict($sighting->dedupKey, $verdict, $offer);
                }

                // ROW 41: one judged offer into the same-filter tally — the first reject reason names the filter.
                SameFilterWarning::count($filterTally, $source->name(), $verdict->outcome === JobOutcome::REJECT ? ($verdict->reasons[0] ?? null) : null);

                if ($verdict->outcome === JobOutcome::REJECT) {
                    ++$rejectedCount;
                    $rejected[] = sprintf('écartée %s:%s — %s', $offer->sourceName, $offer->externalId, implode(' ; ', $verdict->reasons));
                    continue;
                }
                ++$matches;

                // Already PUSHED, so there is nothing more to say. A rollup is not a push and does not stop here.
                if ($this->store->wasNotifiedAs($sighting->dedupKey, JobStore::AS_MATCH)) {
                    continue;
                }

                $pushMin = $this->criteria->notify->pushMinScore;
                if ($pushMin !== null && ($verdict->score ?? 0) < $pushMin) {
                    // Held for the rollup. Counted only while no announcement covers it: an offer
                    // already in a sent rollup is not still waiting.
                    if (!$this->store->wasNotified($sighting->dedupKey)) {
                        ++$queuedLowScore;
                    }
                    continue;
                }

                if ($this->notifier->delivered($this->notifier->send($this->formatter->match($offer, $verdict)))) {
                    ++$notified;
                    $this->store->markNotified($sighting->dedupKey, $nowIso, JobStore::AS_MATCH);
                } else {
                    ++$undelivered;
                }
            }

            // ROW 36 — every offer of this source is recorded above, so its messages are marked
            // processed HERE and nowhere earlier. A refusal is reported and the pass goes on: the
            // offers are on disk, and the flag is for the human reading the label. The rent and car
            // pipelines carry the same block after their recording loops; keep the three rules equal.
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

        return new JobRunResult(
            sourcesRun: $sourcesRun, sourcesFailed: $sourcesFailed, itemsParsed: $itemsParsed, matches: $matches,
            rejectedCount: $rejectedCount, notified: $notified, undelivered: $undelivered,
            errors: $errors, rejected: $rejected, warnings: SameFilterWarning::warnings($filterTally), queuedLowScore: $queuedLowScore,
        );
    }

    /** @param list<JobSource> $sources */
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
