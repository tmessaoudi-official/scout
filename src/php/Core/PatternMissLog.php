<?php

declare(strict_types=1);

namespace Scout\Core;


/**
 * How often each CONFIGURED positional pattern found nothing, for the pass just run.
 *
 * **The half of Track 1h that makes the next template change visible instead of silent.** PAP moved
 * its rooms out of the title line on 2026-08-28 and both its positional patterns stopped matching.
 * The ownership rule behaved correctly throughout — a configured pattern that misses yields `null`,
 * never the generic scan, so the subscriber's own search floor was never substituted — but NOTHING
 * COUNTED THE MISSES. So for four days every PAP row stored a null surface and a null rooms, 19 of
 * them were notified as MATCH (a null passes `min_surface_m2` by hard rule 9), and `doctor` said
 * `ok`. It was found by querying the production database, not by any signal the tool emitted.
 *
 * That is hard rule 2's shape one layer in: a source whose CARDS still parse but whose FIELDS have
 * stopped extracting is indistinguishable from a source publishing thin listings — and the count of
 * items, which source health watches, does not move at all.
 *
 * Deliberately NOT persisted. A miss rate is a property of the pass that just ran, and the pattern
 * either matches this template or it does not; there is no rolling mean to keep and no history that
 * would say anything the current pass does not. `SourceStatus::FEED_SILENT` needed the store because
 * it reasons about DATES; this reasons about the payload in hand.
 *
 * Mutable on purpose, held by a `readonly` source as a readonly property: the property cannot be
 * reassigned, the counter it points at can be written. That is the only way to accumulate anything
 * inside a `final readonly` adapter without making the adapter itself mutable.
 */
final class PatternMissLog implements MutableByDesign
{
    /** @var array<string, array{calls: int, misses: int}> */
    private array $counts = [];

    /**
     * Attempts made while a card's fate is undecided, or `null` when nothing is staged.
     *
     * @var list<array{0: string, 1: bool}>|null
     */
    private ?array $pending = null;

    /** One attempt at a configured pattern: `$found` is false when it yielded null or an empty string. */
    public function record(string $key, bool $found): void
    {
        if ($this->pending !== null) {
            $this->pending[] = [$key, $found];

            return;
        }

        $this->tally($key, $found);
    }

    /**
     * Stage the attempts about to be made, because the thing being read may not be a card.
     *
     * A SEGMENT IS NOT A CARD UNTIL `cardListing()` SAYS SO. A segmented message carries its header,
     * its footer and its unsubscribe block alongside the listings, and every one of those is fed to
     * the same patterns before being dropped for having no rent and no location. Counting those
     * attempts put four permanent misses in bienici's denominator on this signal's first production
     * pass — `commune_pattern 117/364` live, on a source extracting a commune for every listing it
     * returned.
     *
     * Diluting the ratio is the damage. {@see total()} fires on 100 % of at least three, so
     * furniture in the denominator means a pattern that has genuinely stopped matching every real
     * card can still report short of 100 % and say nothing — the exact silence Track 1h exists to
     * end. And an operator reading 117/364 cannot tell a real one-in-three miss from a message
     * shape that simply has furniture in it.
     *
     * Staging rather than moving the call site: {@see \Scout\Rent\Adapters\EmailAlertSource::matchParam()}
     * is deliberately the single funnel every configured pattern passes through, and five call
     * sites would be five places to forget. Outside a begin/resolve pair `record()` still counts
     * immediately, which is what the NON-segmented path needs — there every attempt belongs to a
     * listing that is emitted.
     */
    public function begin(): void
    {
        $this->pending = [];
    }

    /** Keep the staged attempts (the segment was a card) or drop them (it was not). */
    public function resolve(bool $keep): void
    {
        $pending = $this->pending ?? [];
        $this->pending = null;

        if (!$keep) {
            return;
        }

        foreach ($pending as [$key, $found]) {
            $this->tally($key, $found);
        }
    }

    /**
     * Lot pages attempted on a pass that used its WHOLE lot budget and read none, or `null`.
     *
     * A lot-budget source's `item_count` is its INDEX's size, so a source that can no longer read a
     * single lot page still records `ok` (Autohero, 2026-11-16: 50 of 50 slots on the same 50
     * self-redirecting lots, five passes, `ok, 4022 items`). A skipped lot is not recorded, so the same
     * lots refill the budget every pass. "Every attempt failed" is the wrong test: four dead lots are
     * retried on every quiet pass, so it flaps; a budget used up with nothing read does not
     * (docs/plans/lot-budget-starvation.plan.md).
     */
    private ?int $starvedAttempts = null;

    /** The pass spent its whole lot budget, `$attempts` pages, and read no lot. */
    public function starved(int $attempts): void
    {
        $this->starvedAttempts = $attempts;
    }

    /** Starts a pass clean, so a count never spans two fetches — staged attempts included. */
    public function reset(): void
    {
        $this->counts = [];
        $this->pending = null;
        $this->starvedAttempts = null;
    }

    private function tally(string $key, bool $found): void
    {
        $this->counts[$key] ??= ['calls' => 0, 'misses' => 0];
        ++$this->counts[$key]['calls'];

        if (!$found) {
            ++$this->counts[$key]['misses'];
        }
    }

    /** @return array<string, array{calls: int, misses: int}> */
    public function counts(): array
    {
        return $this->counts;
    }

    /**
     * The patterns that found NOTHING AT ALL this pass, over enough cards to mean it.
     *
     * **100% of at least three**, and both halves of that are the point. A pattern that misses on
     * SOME cards is ordinary — a portal's own copy varies, and `commune_pattern` legitimately finds
     * nothing on a card that states no commune. A pattern that misses on EVERY card is a template
     * change, which is the F1 signature and the thing worth a channel. The floor of three stops a
     * quiet pass (one alert, one card) from crying wolf on a source that is working fine.
     *
     * @return list<string> the configured keys that missed everywhere, sorted for a stable message
     */
    public function total(int $floor = 3): array
    {
        $out = [];

        foreach ($this->counts as $key => $c) {
            if ($c['calls'] >= $floor && $c['misses'] === $c['calls']) {
                $out[] = $key;
            }
        }

        sort($out);

        return $out;
    }

    /**
     * Decorate a source's health verdict with what stopped extracting — the ONE implementation.
     *
     * **This was inline in two adapters and absent from three more** (C2 round-1 resilience lens,
     * 2026-09-02). `HtmlSource`, `HttpJsonSource` and `FixtureSource` counted misses and their
     * `health()` never read the count, so a field map going 100 % null on the four rent html/json
     * sources produced no status change, no `isAlerting()` and no alert — only a `doctor` printout.
     * Hard rule 2: an alert computed and never sent is worse than none.
     *
     * Extracted rather than copied a fifth time. The two email adapters already carried it verbatim,
     * and a fifth inline copy is exactly how the sixth adapter forgets;
     * `PatternMissEscalationTest::testEveryCountingSourceEscalatesThroughHealth()` discovers the
     * implementors by reflection and fails when one does not route through here.
     *
     * **WARN, never BROKEN**, and it only ever upgrades from `OK`. Cards ARE flowing and the source
     * IS reachable — what changed is the portal's layout, which needs a human to look at a capture
     * rather than a reason to stop polling. A source already `BROKEN`, `STALE` or `FEED_SILENT` keeps
     * its more specific verdict: those say something the operator must act on differently, and
     * downgrading one to a layout complaint would lose the instruction.
     */
    public function escalate(SourceHealth $health): SourceHealth
    {
        $blind = $this->total();
        $why = [];

        if ($this->starvedAttempts !== null) {
            // Not the template sentence: a starved budget is lots that yielded nothing (a redirect, a
            // 404, a missing block, a sale window), and the per-lot warnings in the log say which.
            $why[] = sprintf('les %d lots tentés sur cette passe n\'ont donné aucune annonce (budget épuisé, aucun lot lu) : '
                . 'la source ne lit plus aucun nouveau lot, voir les avertissements par lot dans le journal', $this->starvedAttempts);
        }
        if ($blind !== []) {
            $why[] = 'aucun résultat pour ' . implode(', ', $blind)
                . ' sur cette passe : le gabarit du portail a probablement changé, les champs concernés sont null';
        }

        if ($why === []) {
            return $health;
        }

        return new SourceHealth(
            sourceName: $health->sourceName,
            status: $health->status === SourceStatus::OK ? SourceStatus::WARN_DROP : $health->status,
            detail: rtrim($health->detail, ' .') . ' — MAIS ' . implode(' ; ', $why),
            consecutiveEmptyRuns: $health->consecutiveEmptyRuns,
            lastSuccessAt: $health->lastSuccessAt,
            lastFailureAt: $health->lastFailureAt,
            lastCount: $health->lastCount,
            rollingMean: $health->rollingMean,
            runsInWindow: $health->runsInWindow,
            failedRunsInWindow: $health->failedRunsInWindow,
            totalRuns: $health->totalRuns,
        );
    }
}
