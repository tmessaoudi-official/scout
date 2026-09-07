<?php

declare(strict_types=1);

namespace Scout\Rent\Cli;

use Scout\Adapters\AcknowledgesMessages;
use Scout\Core\Redact;
use Scout\Core\SameFilterWarning;
use Scout\Rent\Adapters\FeedDate;
use Scout\Rent\Adapters\Source;
use Scout\Adapters\SourceError;
use Scout\Rent\Config\Criteria;
use Scout\Rent\Core\Classification;
use Scout\Rent\Core\CriteriaEngine;
use Scout\Rent\Core\Dedup;
use Scout\Rent\Core\ExcludedDwellings;
use Scout\Rent\Notify\Formatter;
use Scout\Core\Notify\Notifier;
use Scout\Rent\Core\Outcome;
use Scout\Rent\Core\RawListing;
use Scout\Rent\Core\Tenure;
use Scout\Rent\Core\TenureSignal;
use Scout\Rent\Core\TenureClassifier;
use Scout\Rent\Core\Verdict;
use Scout\Rent\Enrich\CommutePlanner;
use Scout\Rent\Store\Store;

/**
 * One pass: fetch every enabled source, classify, filter, dedup, store, notify.
 *
 * THE ORDER IS THE DESIGN, and each step is where it is for a reason that cost something to learn:
 *
 * 1. **Fetch, and record the run whatever happens.** A failed fetch is a recorded failure, never an
 *    empty result — `CLAUDE.md` hard rule 3, and the thing source health is derived from.
 * 2. **Classify, then apply criteria.** The classifier owns `REJECT`/`DIGEST` so §1's fail-closed
 *    rule lives in exactly one place.
 * 3. **Dedup across sources, before storing.** Deduping after would have already recorded two
 *    sightings of one flat and manufactured a price history for a listing that does not exist.
 * 4. **Store, THEN notify, and only mark notified when a channel confirmed.** The reverse order
 *    loses a listing on a crash between the two; marking optimistically loses it on a send failure.
 */
final readonly class Pipeline
{
    public function __construct(
        private Criteria $criteria,
        private Store $store,
        private Notifier $notifier,
        private TenureClassifier $classifier = new TenureClassifier(),
        private ?CriteriaEngine $engine = null,
        private Dedup $dedup = new Dedup(),
        private Formatter $formatter = new Formatter(),
        /**
         * Optional, and `null` is the shipped default — no key, no destination, no enrichment.
         *
         * Injected rather than constructed here so the pipeline never learns that the network
         * exists, exactly as `PacedSource` keeps it from learning that time does. Every test in the
         * tree therefore runs enrichment OFF unless it says otherwise.
         */
        private ?CommutePlanner $commute = null,
    ) {}

    /**
     * This listing plus its commute, or this listing unchanged.
     *
     * Never throws and never rejects: a planner is contractually required to answer `null` rather
     * than raise, and `null` is UNKNOWN rather than far (hard rule 9). The `try` is belt-and-braces
     * around a third-party implementation — one bad lookup must not void a pass that has already
     * fetched real listings.
     */
    private function enrich(RawListing $listing): RawListing
    {
        if ($this->commute === null || $listing->commuteMinutes !== null) {
            return $listing;
        }

        try {
            $minutes = $this->commute->minutesFrom($listing->commune, $listing->postcode);
        } catch (\Throwable) {
            return $listing;
        }

        if ($minutes === null) {
            return $listing;
        }

        // CLONE-WITH, never a field-by-field rebuild. This used to enumerate every constructor
        // parameter, and the day `observedAt` was added it silently dropped it — on the ONE
        // machine where commute is enabled, which is production. The phantom-drop fix shipped,
        // the tests were green (they run commute OFF), and the first live pass fired the same
        // phantom drop again (2026-08-29 21:20). `RawListing::mergedWith()`'s own comment had
        // named this exact trap. A clone carries every property, including tomorrow's, and
        // `PipelineRunTest` now asserts that by reflection.
        return $listing->withCommute($minutes);
    }

    /**
     * @param list<Source> $sources
     * @param bool         $seedOnly populate the seen-set without notifying — `scout run --seed`,
     *                               the way through a freshly created database (Q36)
     */
    public function runOnce(array $sources, string $nowIso, bool $seedOnly = false): RunResult
    {
        $engine = $this->engine ?? new CriteriaEngine($this->criteria);

        $sourcesRun = 0;
        $sourcesFailed = 0;
        $itemsParsed = 0;
        $errors = [];

        /** @var list<array{listing: RawListing, family: string}> $harvested */
        $harvested = [];

        /**
         * Sources whose fetch SUCCEEDED — the only ones whose messages may be acknowledged (row 36).
         * A failed fetch processed nothing, so it marks nothing.
         *
         * @var list<Source> $fetched
         */
        $fetched = [];

        foreach ($sources as $source) {
            ++$sourcesRun;
            $startedAt = hrtime(true);

            try {
                $listings = $source->fetch();
                $fetched[] = $source;
                $durationMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);

                // `item_count` is what the ADAPTER PARSED, before criteria (Q30). Counting matches
                // would make source health a measure of the rental market rather than of the
                // adapter, and a drifted selector on a source whose matches are usually zero would
                // become undetectable — which is the exact failure §8 exists for.
                // The feed date is read only on the SUCCESS path, and only after `fetch()` — before
                // one there is nothing to report, and on a failure the mailbox never got far enough
                // to see a message. Writing a stale value into a failed run would let the previous
                // pass's freshness vouch for a pass that fetched nothing.
                $feedNewestAt = FeedDate::of($source);
                // Kept on ONE line on purpose. `tests/sabotage-check.sh` matches this call by its
                // literal prefix up to `true`, and a multi-line reformat silently rots that
                // expression into matching nothing — the exact way a `markNotified()` signature
                // change rotted one on 2026-08-24, reporting coverage the ledger no longer had.
                $this->store->recordRun($source->name(), count($listings), true, null, $nowIso, $durationMs, $feedNewestAt);
                $itemsParsed += count($listings);

                foreach ($listings as $listing) {
                    $harvested[] = ['listing' => $listing, 'family' => $source->family()];
                }
            } catch (SourceError $e) {
                $durationMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);
                ++$sourcesFailed;
                $errors[] = $e->getMessage();
                // Recorded, not swallowed. This row is what `SourceStatus::BROKEN` is derived from,
                // and a failure that leaves no trace is indistinguishable from a quiet market.
                $this->store->recordRun($source->name(), 0, false, $e->getMessage(), $nowIso, $durationMs);
            } catch (\Throwable $e) {
                $durationMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);
                ++$sourcesFailed;
                // An adapter throwing something it did not declare is still a source failure, and it
                // must not abort the pass — the remaining sources have not been tried yet.
                $wrapped = new SourceError($source->name(), $e->getMessage(), $e);
                $errors[] = $wrapped->getMessage();
                $this->store->recordRun($source->name(), 0, false, $wrapped->getMessage(), $nowIso, $durationMs);
            }
        }

        // ENRICHMENT, BEFORE CLUSTERING AND THEREFORE BEFORE EVERYTHING.
        //
        // Placed here rather than beside `judge()` for two independent reasons, and either alone
        // would decide it. **Hard rule 8:** a disqualifier applied before enrichment rejects on a
        // field enrichment would have filled, and silent over-rejection is invisible because nothing
        // arrives. **And the v7 snapshot** is written from the member exactly as the classifier
        // consumed it, so a listing enriched after that point would be re-judged by
        // `scout reclassify` without its commute and silently score lower the second time.
        //
        // Clustering is downstream of it because `$observed` is keyed on OBJECT IDENTITY: enriching
        // afterwards would replace member objects that the survivor is a second reference to, and
        // the cluster bookkeeping would quietly stop matching.
        $harvested = array_map(
            fn (array $row): array => ['listing' => $this->enrich($row['listing']), 'family' => $row['family']],
            $harvested,
        );

        $clustered = $this->dedup->cluster($harvested);
        $duplicates = count($harvested) - count($clustered);

        // TWO TRACKS, ONE PUSH (developer ruling, 2026-08-29). Clusters stay per track — the sort
        // and the map below never merge anything — but the DIRECT route is judged first, so when
        // a landlord's listing and its agency copy arrive in the same pass the landlord's is the
        // one pushed and the copy is marked; and each survivor knows its twins on the other track,
        // found by the same positive-evidence bar as clustering. `usort` is stable in PHP 8, so
        // within a track the harvest order (the pacer's shuffle) is untouched.
        usort($clustered, static fn (array $a, array $b): int => ($a['family'] === 'institutional' ? 0 : 1) <=> ($b['family'] === 'institutional' ? 0 : 1));

        /** @var array<int, list<array{listing: RawListing, family: string}>> $twinsOf keyed on the survivor's object id */
        $twinsOf = [];
        foreach ($clustered as $i => $one) {
            foreach ($clustered as $j => $other) {
                if ($i === $j || $this->dedup->twinReason($one['listing'], $other['listing'], $one['family'], $other['family']) === null) {
                    continue;
                }
                $twinsOf[spl_object_id($one['listing'])][] = ['listing' => $other['listing'], 'family' => $other['family']];
            }
        }
        $twinsSuppressed = 0;
        /** @var array<int, string> $keyOf dedup key of every recorded member, by object id */
        $keyOf = [];

        // EVERY member is recorded and classified here, not just the survivor the loop below
        // iterates. Until schema v4 the pipeline clustered first and recorded only survivors, so an
        // absorbed duplicate had no row at all — and `listings.group_key` on top of that would only
        // ever describe groups of one, shipping inert while looking finished.
        //
        // Classified, not merely recorded: `Store::staleVerdicts()` selects `tenure IS NULL` and
        // that value already means "stored before schema v3, deliberately not backfilled". Leaving
        // members NULL would give it a second meaning and silently enlarge what `scout reclassify`
        // re-announces.
        //
        // Recorded once and reused below, keyed on object identity, so the survivor is not stored
        // twice in one pass.
        /** @var array<int, array{sighting: \Scout\Rent\Store\Sighting, classification: \Scout\Rent\Core\Classification}> $observed */
        $observed = [];
        /** @var array<int, list<string>> $clusterKeys survivor object id -> every member's dedup key */
        $clusterKeys = [];
        $unencodable = 0;

        foreach ($clustered as $cluster) {
            $memberKeys = [];

            foreach ($cluster['members'] as $member) {
                $classification = $this->classifier->classify($member, $this->profileFor($sources, $member));
                // The SOURCE'S observation time when it states one (an email's `Date`), the pass
                // time otherwise. The store orders sightings by this instant, and that ordering is
                // the whole defence against a re-read older card manufacturing a rent drop.
                $sighting = $this->store->record($member, $member->effectiveRentCc(), $member->observedAt ?? $nowIso);
                // What this row said LAST time, read before today's reading overwrites it: an
                // excluded tenure is durable (see durableOwnReading()).
                $previousTenure = $this->store->tenure($sighting->dedupKey);
                // THE DURABLE READING IS APPLIED HERE, PER MEMBER, BEFORE ANYTHING IS WRITTEN
                // (round-4 panel, 2026-08-31). It used to be applied to the SURVIVOR only, after
                // judging — so this write tore down every member's stored tenure and only the
                // survivor's was rebuilt. Survivorship follows the harvest order and `Core\Pacer`
                // shuffles it every pass, so a row holding yesterday's `PLS` lost it the moment a
                // sibling was polled first, and `groupExcludedTenure()` — which reads this very
                // column — then found nothing excluded and the flat was pushed.
                //
                // Writing the durable reading here rather than restoring it later is what makes the
                // guarantee hold for a member that is never judged at all, and it is the same
                // refuse-to-downgrade rule `Store::recordTwin()` already applies to the twin fact.
                // It also survives a pass that dies between the two loops: there is no window in
                // which the excluded reading is off disk.
                $own = $this->durableOwnReading($classification, $previousTenure);
                $captured = $this->store->recordVerdict(
                    $sighting->dedupKey,
                    $own->tenure->value,
                    $own->confidenceBp,
                    $own->reasons(),
                    // Schema v7: the member EXACTLY as the classifier just consumed it — after
                    // mapping and after any detail merge, which is why `$member` is passed rather
                    // than anything re-derived. `scout reclassify` re-runs on this and must never
                    // run on less, so it is written in the same statement as the verdict it
                    // produced and cannot drift from it.
                    $member,
                );

                if (!$captured) {
                    // A listing whose PAYLOAD cannot be encoded — malformed UTF-8 anywhere in it,
                    // not necessarily its prose. A structured field alone will do it, on a listing
                    // whose title and description are clean, which then classifies normally and can
                    // be a NOTIFIED MATCH that silently lost its evidence.
                    //
                    // Its verdict is stored without a snapshot, which is honest: nothing can
                    // re-judge what was never captured. But "reclassify will skip it" — what an
                    // earlier version of this comment said — is only true of an UNKNOWN row.
                    // `staleVerdicts()` selects `tenure IS NULL OR tenure = 'UNKNOWN'`, so a MATCH
                    // row is not skipped, it is INVISIBLE to that command. `scout doctor` reports
                    // the standing count for exactly that reason. It used to THROW instead, from
                    // outside the per-source try/catch, and took the whole pass with it.
                    ++$unencodable;
                }

                // `$own`, not `$classification` — so `twinClassification()`, which reads this array
                // to learn what the OTHER track says, sees the twin's durable reading rather than
                // its raw one. Reading the raw one let a twin held excluded only by its own durable
                // reading contribute an ELIGIBLE tenure, which `recordTwin()` then persisted, and
                // the agency copy was pushed as a match naming the PLS route as the *voie directe*.
                // No `previous` key: it existed only to feed the judging-loop `durableOwnReading()`
                // call that round 5 removed as dead, and an unread field in a documented array shape
                // reads as something consulted when it is not. `$previousTenure` is still used, at
                // the write above, which is where the guarantee now lives.
                $observed[spl_object_id($member)] = ['sighting' => $sighting, 'classification' => $own];
                $keyOf[spl_object_id($member)] = $sighting->dedupKey;
                $memberKeys[] = $sighting->dedupKey;
            }

            // A cluster of one is not a group and `assignGroup()` returns null for it, leaving
            // `group_key` NULL — which is what keeps "never clustered" distinguishable from
            // "clustered, and the others have since delisted".
            $this->store->assignGroup($memberKeys);
            $clusterKeys[spl_object_id($cluster['listing'])] = $memberKeys;
        }

        // THE TWIN GRAPH IS RESOLVED TO A FIXED POINT BEFORE ANY SURVIVOR IS JUDGED (round-6 panel,
        // 2026-08-31). The veto was not TRANSITIVE: `twinClassification()` took each twin's reading
        // from `$observed`, which carries that twin's OWN durable reading and (round 5) its own
        // group veto — but never the twin's own TWIN-derived verdict. So when a listing's exclusion
        // came from ITS twin, it still contributed an ELIGIBLE tenure to its OTHER twins, and
        // `recordTwin()` then persisted that eligible fact.
        //
        // Proven by execution on the shape that needs no exotic data: one portal re-advertising a
        // flat mints a second ad id, and both copies twin with the same direct route. Only one card
        // carries the `PLS`; the direct route was REJECTED on it, and the second copy was pushed as
        // a MATCH whose reasons named that rejected route as the *voie directe, candidature au
        // bailleur* — verbatim the failure the twin mechanism was built to stop, closed for two
        // nodes and open for three.
        //
        // Writing `$judged` back into `$observed` inside the judging loop is NOT sufficient, and the
        // reviewer said so before I tried it: sources are harvested institutional-first, so a clean
        // institutional end is judged before the private middle has learned the other institutional
        // end's `PLS`. The reading has to be resolved across every edge FIRST.
        //
        // Most-restrictive over each CONNECTED COMPONENT, iterated to a fixed point. Stated cost, and
        // it is the §1-safe direction rather than a free win: a twin link is positive evidence about
        // prose, not a key, so a chain A–B–C where A and C would never have been linked directly now
        // vetoes both. Q39 already prices pairwise over-linking as a permanent rejection; this widens
        // that, and §1's own instruction is to bias every ambiguous decision toward NOT notifying.
        // THE FOURTH VETO ROUTE, loaded ONCE per pass and read at both classification sites below.
        // The other three need an EDGE — a persisted cluster, a twin in hand or the row's own
        // previous reading — and a portal re-advertising an excluded flat under a new ad id has
        // none of them, so it was pushed as a match with the `PLS` one row away on disk. See
        // `Store::excludedDwellings()` for what it costs and what it cannot reach.
        $excludedDwellings = $this->store->excludedDwellings();

        $twinRank = static fn (Tenure $t): int => $t->isExcluded() ? 2 : ($t === Tenure::UNKNOWN ? 1 : 0);

        /**
         * The confidence rides WITH the tenure, and that is load-bearing (COR-F5). `recordTwin()`
         * refuses to let a weak reading resolve a recorded doubt, so it needs to know how strong
         * this one is — and the fixed point below copies whole entries, so the number that arrives
         * at the store is the one belonging to the tenure that actually won, not a recomputation.
         *
         * @var array<int, array{tenure: Tenure, source: string, bp: int}> $twinReading survivor id -> resolved
         */
        $twinReading = [];
        foreach ($clustered as $cluster) {
            $listing = $cluster['listing'];
            $id = spl_object_id($listing);
            $observation = $observed[$id] ?? null;
            if ($observation === null) {
                continue;
            }
            $key = $keyOf[$id] ?? null;
            $base = $this->storedDwellingClassification(
                $this->clusterClassification(
                    $observation['classification'],
                    $key === null ? null : $this->store->groupExcludedTenure($key),
                ),
                $listing,
                $excludedDwellings,
            );
            $twinReading[$id] = ['tenure' => $base->tenure, 'source' => $listing->sourceName, 'bp' => $base->confidenceBp];
        }

        // Bounded by the number of survivors: each sweep either tightens at least one node or ends.
        for ($sweep = 0, $cap = count($twinReading) + 1; $sweep < $cap; ++$sweep) {
            $changed = false;

            foreach ($clustered as $cluster) {
                $id = spl_object_id($cluster['listing']);
                foreach ($twinsOf[$id] ?? [] as $twin) {
                    $other = $twinReading[spl_object_id($twin['listing'])] ?? null;
                    $mine = $twinReading[$id] ?? null;
                    if ($other === null || $mine === null) {
                        continue;
                    }
                    if ($twinRank($other['tenure']) > $twinRank($mine['tenure'])) {
                        $twinReading[$id] = $other;
                        $changed = true;
                    }
                }
            }

            if (!$changed) {
                break;
            }
        }

        // ROW 36 — THE MESSAGES ARE MARKED PROCESSED HERE, AND NOWHERE EARLIER. Every member of
        // every cluster has been `record()`ed above, so a message flagged `\Seen` is one whose
        // listings the store holds; moved above the recording loop, the flag would vouch for a
        // pass that may still throw before writing, which the developer would read as "processed".
        // Gated on the interface, never on the adapter class — and `PacedSource` forwards it, so
        // the deployed `--watch` path is the same path as this one. A refusal is REPORTED on the
        // banner and the pass carries on: the listings are on disk and the flag is informational.
        foreach ($fetched as $source) {
            if (!$source instanceof AcknowledgesMessages) {
                continue;
            }
            try {
                $source->acknowledge();
            } catch (SourceError $e) {
                $errors[] = Redact::text($e->getMessage());
            } catch (\Throwable $e) {
                $errors[] = $source->name() . ' : ' . Redact::text($e::class . ': ' . $e->getMessage());
            }
        }

        $matches = 0;
        $digested = 0;
        $rejectedCount = 0;
        $rentDrops = 0;
        $undelivered = 0;

        $digestOverflow = 0;

        /** @var array<string, array{judged: int, by: array<string, int>}> $filterTally row 41 */
        $filterTally = [];

        // CONFIRMED DELIVERIES, which is not `$matches`. See `RunResult::$notified` — `$matches` is
        // incremented when the engine judges, before the already-announced gate and before the
        // channel confirms, so in steady state it is the standing match count while the pass sends
        // nothing at all.
        $notified = 0;
        $queuedLowScore = 0;
        /** @var list<string> $sectionOneRefused */
        $sectionOneRefused = [];

        // ONE gate, constructed once, reading FRESH on every call. Not hoisted state: it holds no
        // candidate list — a hoisted list is what both round-3 P0s were.
        $sectionOne = new SectionOneGate($this->store, $this->dedup);
        $rejected = [];

        /** @var list<array{listing: RawListing, verdict: Verdict, key: string, keys: list<string>}> $digestEntries */
        $digestEntries = [];

        foreach ($clustered as $cluster) {
            $listing = $cluster['listing'];
            $observation = $observed[spl_object_id($listing)];
            $sighting = $observation['sighting'];
            $classification = $observation['classification'];

            // Freshness (score component S7) is measured from FIRST seen, not from this sighting.
            // `null` means "new right now" and earns the bonus outright; an existing listing earns
            // it only while it is still inside the window. Passing `null` unconditionally — which an
            // earlier draft did, with both branches of a ternary returning null — would have given
            // every listing the freshness bonus forever, quietly flattening the one component that
            // is supposed to separate a flat published this hour from one that has sat for a week.
            // §1 ACROSS THE WHOLE CLUSTER, not just the survivor. Every member is classified above
            // and each verdict stored, but only the survivor was ever JUDGED — so the same flat
            // published on a pure-LLI portal and on a mixed one that says `PLS` outright was a
            // match or a reject depending on which source happened to be polled first, and
            // `Core\Pacer` shuffles source order every pass. A coin-flip, not a stable answer.
            // The store then held that `PLS` under the same `group_key` as the row it had just
            // pushed as a match, and the notification's own `reasons[]` named the sibling.
            //
            // It inverts the documented dedup trade-off too: over-merging is supposed to cost a
            // hidden second flat, and here it LAUNDERED an excluded tenure into a notification.
            // THE PERSISTED GROUP, not this pass's harvest. `Dedup` clusters what was fetched
            // right now and never consults the store, so the veto used to be a function of who
            // happened to be fetched together — while `assignGroup()` returns before any UPDATE for
            // a single member, so the row KEEPS the group it earned when the excluded sibling was
            // present. A failed source fetch, a `--source=<name>` run, or the sibling delisting was
            // enough to launder the flat into a match on the very next pass, and that pass
            // overwrote the stored REJECT with MATCH while the survivor's own tenure resolved —
            // putting the row outside `staleVerdicts()` AND `pendingDigest()`, beyond the reach of
            // either repair command.
            $judged = $this->clusterClassification(
                $classification,
                $this->store->groupExcludedTenure($sighting->dedupKey),
            );
            // THE DURABLE READING IS NOT RE-APPLIED HERE, and the reason is this repo's own rule
            // rather than a saving (round-5 panel, 2026-08-31). Round 4 moved it into the recording
            // loop and left this second call standing, described in the commit as "left in place as
            // a defence". It was not one: `$classification` above IS the durable `$own`, so if the
            // previous reading was excluded then `$own` is excluded, `clusterClassification()`
            // early-returns on an excluded survivor, and `durableOwnReading()` early-returns on an
            // excluded `$judged` — and if it was NOT excluded there is nothing to preserve. A
            // reviewer proved it twice: deleting the line, and instrumenting it to THROW if it ever
            // changed anything, both left all 2 339 tests green.
            //
            // `durableOwnReading()`'s own neighbouring comment says what to do with that: "Dead
            // safety code is worse than none: it reads as a second line of defence and is not one.
            // Removed rather than kept." The guarantee lives at the write, where a member that is
            // never judged is also covered.
            $judged = $this->twinClassification($judged, $sighting->dedupKey, $clusterKeys[spl_object_id($listing)] ?? [$sighting->dedupKey], $twinsOf[spl_object_id($listing)] ?? [], $twinReading);

            // THE STORED-DWELLING VETO RUNS LAST, AND THE ORDER IS LOAD-BEARING. Placed before
            // `twinClassification()` it pre-empted it — that method early-returns on an already
            // excluded survivor, so `recordTwin()` never fired and the cross-track fact stopped
            // being PERSISTED on every row this new veto happened to catch first. Three existing
            // twin-durability tests went red and said so. A veto that silently disables another
            // veto's recording is a net loss: this one is derived from what is on disk, while the
            // twin fact is what PUTS the other track's reading there for the passes in which the
            // twin is not fetched at all.
            $judged = $this->storedDwellingClassification($judged, $listing, $excludedDwellings);

            $verdict = $engine->judge($listing, $judged, $this->ageSeconds($sighting->dedupKey, $nowIso));

            // THE ROW RECORDS THE JUDGED VERDICT (round-3 panel). The recording loop stored the
            // row's OWN reading before the group, twin and durable readings had their say, so
            // `scout digest` — which drains the STORE — announced a demoted row with reasons that
            // could not say what to verify, while the in-pass digest did. Two drains disagreeing on
            // one row is what the Q34 single-drain rule exists to prevent. Re-recording the judged
            // reading also makes an excluded verdict visible to the next pass's durable read.
            if ($judged !== $classification) {
                $this->store->recordVerdict($sighting->dedupKey, $judged->tenure->value, $judged->confidenceBp, $judged->reasons(), $listing);
            }

            // Schema v7. Recorded for ALL THREE outcomes and BEFORE the branches below, because
            // both of them `continue` — writing it inside the digest branch alone would leave a
            // listing promoted DIGEST -> MATCH still carrying `DIGEST`, and `scout digest` would go
            // on announcing as doubtful something the pipeline had already notified as a match.
            //
            // Only the SURVIVOR reaches here. An absorbed member keeps `outcome` NULL, which is the
            // truth — it was recorded and classified but never judged — and that NULL is what stops
            // `pendingDigest()` from mistaking an unjudged member for a digested one.
            $this->store->recordOutcome($sighting->dedupKey, $verdict->outcome->value);

            // ROW 41: one judged card into the same-filter tally, whatever its outcome.
            SameFilterWarning::count($filterTally, $listing->sourceName, $verdict->outcome === Outcome::REJECT ? $verdict->disqualifier : null);

            if ($verdict->outcome === Outcome::REJECT) {
                ++$rejectedCount;
                $rejected[] = $listing->sourceName . ':' . $listing->externalId . ' — ' . (string) $verdict->disqualifier;
                continue;
            }

            if ($verdict->outcome === Outcome::DIGEST) {
                ++$digested;

                // ONLY WHAT IS NEW SINCE THE LAST SUCCESSFUL EMISSION (Q34). Without this the
                // digest repeats its whole contents every pass, which is the alert fatigue it was
                // designed to avoid — and a digest the developer has learned to skip costs the
                // fail-closed rule its only landing zone.
                //
                // `notified_at` answers "has this listing been announced at all", which is the
                // right question HERE: being told a flat is a match certainly covers being told it
                // is doubtful, so a match is never re-announced as a doubt.
                //
                // The reverse is NOT true, and this comment used to claim `scout reclassify` closed
                // that gap. It did not — the pipeline's own re-judgement removes the row from
                // `staleVerdicts()` before reclassify can ever see it. Schema v8's `notified_as` is
                // what closes it, and the match path below asks `wasNotifiedAs(..., 'MATCH')`.
                // §1 ON THE DIGEST BIN TOO — THE DIGEST IS AN ANNOUNCEMENT (C2 round 5, P0).
                //
                // §1 was implemented as "never a MATCH", so `DIGEST` was treated as a DESTINATION
                // rather than as a surface that reaches the phone. It reaches the phone. This
                // branch `continue`s 42 lines ABOVE the gate, so an excluded dwelling was announced
                // under a headline asserting *« au régime indéterminé »* while the store recorded
                // `PLS` for the same dwelling one row away, written by this very pass. Reachable on
                // ordinary in-pass ordering with no concurrency: the non-transitive tolerance chain.
                //
                // THE REFUSAL IS DIFFERENT FROM THE MATCH GATE'S, deliberately. A row refused here
                // is DROPPED FROM THE BIN and said out loud; it is NOT written `REJECT` with a
                // derived durable reading, because a doubt the pipeline could not resolve is not
                // the same fact as a regime it read — and the match gate's write is terminal by
                // query. The next pass's own hoisted set catches it in `storedDwellingClassification`
                // anyway; what this closes is the announcement.
                $digestRefusal = $sectionOne->refuses($listing, $sighting->dedupKey);
                if ($digestRefusal !== null) {
                    $sectionOneRefused[] = sprintf(
                        '%s — §1 : %s (%s) — retirée du récapitulatif « à vérifier »',
                        $sighting->dedupKey,
                        $digestRefusal['detail'],
                        $digestRefusal['route'],
                    );

                    continue;
                }

                if (!$this->store->wasNotified($sighting->dedupKey)) {
                    $digestEntries[] = [
                        'listing' => $listing,
                        'verdict' => $verdict,
                        'key' => $sighting->dedupKey,
                        // Every member, for `--seed` only — see the seeding branch below.
                        'keys' => $clusterKeys[spl_object_id($listing)],
                    ];
                }

                continue;
            }

            ++$matches;

            if ($seedOnly) {
                // MARKED NOTIFIED WITHOUT SENDING, and that is the whole point of the mode.
                // Recording the sighting alone was not enough: `notified_at` stayed NULL, so the
                // very next run notified all of them and the flood simply moved one run later —
                // which is precisely what Q36 exists to prevent. `--seed` means "treat everything
                // currently published as already seen AND already told about"; only what appears
                // after it is news.
                //
                // EVERY MEMBER, not just the survivor. The contract is "everything currently
                // published is already seen AND already told about", and an absorbed member is
                // currently published — so the first later pass whose shuffle flipped survivorship
                // would notify it, which is the flood moved one run later rather than prevented.
                // This is not group-scoped suppression: it marks listings that were seen and seeded,
                // and it would mark them identically if they had clustered with nothing.
                foreach ($clusterKeys[spl_object_id($listing)] as $memberKey) {
                    $this->store->markNotified($memberKey, $nowIso, 'MATCH');
                }

                continue;
            }

            // §1's GATE — ABOVE EVERY SEND IN THIS LOOP, over FRESHLY-READ state.
            //
            // It sat 98 lines lower, immediately above the MATCH send, and its own docblock claimed
            // that was "the last moment, so no ordering can route around it". THERE ARE TWO SENDS
            // HERE. The rent-drop push below is reached by a listing whose outcome is already
            // MATCH, and a crossed ceiling is documented as "a NEW MATCH whatever its size" (Q33) —
            // `Priority::HIGH`, titled PASSE SOUS LE PLAFOND, carrying the listing's URL. Two C2
            // round-4 lenses executed it independently: a PLS flat reached the phone at high
            // priority saying it now fits the budget, while the SAME iteration recorded REJECT/PLS
            // for it and wrote the gate's own refusal string into its reasons.
            //
            // That was the SIXTH instance of this milestone's named defect, committed inside the
            // structural fix for the other five. The lesson is the placement rule, not the line:
            // an announcing surface is a SEND, not a notification KIND, and the gate belongs above
            // all of them. The car pipeline already had this right — `VehiclePipeline` puts its
            // price-drop below the REJECT check.
            $refusal = $sectionOne->refuses($listing, $sighting->dedupKey);
            if ($refusal !== null) {
                // Recorded, not merely skipped: the reading is what makes the next pass cheap and
                // what every other surface reads. Left unnotified, so nothing announces it.
                //
                // `100`, not `9000`: `Classification::$confidenceBp` is documented `0..100` and
                // `confidence()` divides by 100. Every other §1 write in this file passes 100.
                $this->store->recordVerdict(
                    $sighting->dedupKey,
                    $refusal['tenure']->value,
                    100,
                    ['§1 — ' . $refusal['detail'] . ' (' . $refusal['route'] . ')'],
                    $listing,
                );
                $this->store->recordOutcome($sighting->dedupKey, 'REJECT');

                // SAID OUT LOUD, with the key. This refusal writes the row's own durable reading
                // and `outcome = REJECT`, which closes `pendingLowScore()`, `pendingDigest()` and
                // `staleVerdicts()` by query — so the documented one way back is
                // `reclassify --reopen=<dedup_key>`, and until now NOTHING PRINTED THE KEY. The
                // other three announcing surfaces all speak on the identical refusal; this is the
                // one that runs unattended every 15 minutes, and it was the silent one.
                // THE REMEDY NAMED MUST WORK FOR THIS ROUTE (C2 round 5). `--reopen` clears the
                // row's OWN reading and its TWIN's; the GROUP and SAME-DWELLING vetoes live on
                // ANOTHER row's reading and are deliberately NOT cleared — so promising it
                // unconditionally sent the operator into a closed loop: re-refused and rewritten
                // every pass, under an instruction saying there is a way out.
                $reversible = \in_array($refusal['route'], ['lecture propre', 'jumeau'], true);
                $sectionOneRefused[] = sprintf(
                    '%s — §1 : %s (%s) — %s',
                    $sighting->dedupKey,
                    $refusal['detail'],
                    $refusal['route'],
                    $reversible
                        ? sprintf('`scout --domain=rent reclassify --reopen=%s` si c\'est une erreur', $sighting->dedupKey)
                        : 'ce veto vient d\'une AUTRE annonce et n\'est pas effaçable ici — rouvrez celle-là si elle est erronée',
                );

                continue;
            }

            // A rent that fell on a listing we already knew is its own event — and one that crosses
            // the ceiling from above is a NEW MATCH whatever its size (Q33), which is why the size
            // thresholds are not consulted in that case.
            $crossedCeiling = $sighting->previousRentCc !== null
                && $this->criteria->maxRentCc !== null
                && $sighting->previousRentCc > $this->criteria->maxRentCc
                && ($listing->effectiveRentCc() ?? PHP_INT_MAX) <= $this->criteria->maxRentCc;

            if ($sighting->isPriceDrop && $sighting->previousRentCc !== null && $listing->effectiveRentCc() !== null) {
                $notable = $crossedCeiling
                    || $this->criteria->notify->isNotableDrop($sighting->previousRentCc, (int) $listing->effectiveRentCc());

                if ($notable) {
                    ++$rentDrops;
                    $failures = $this->notifier->send($this->formatter->rentDrop(
                        $listing,
                        $sighting->previousRentCc,
                        (int) $listing->effectiveRentCc(),
                        $crossedCeiling,
                    ));

                    if ($this->notifier->delivered($failures)) {
                        ++$notified;
                    } else {
                        ++$undelivered;
                    }
                }
            }

            // **THE MATCH PATH IS DELIBERATELY UNCAPPED**, and that decision is written here because
            // a review panel found it stated nowhere while the three announcement paths beside it
            // were all capped this session. It IS the biggest measured burst in the tree — 92
            // notified rows in one live pass on 2026-08-22 after Q1-Q3 widened three filters in a
            // day, as 92 separate pushes.
            //
            // The digest's reasoning does not transfer, and the difference is the whole argument.
            // A digest is ONE all-or-nothing send whose failure marks nothing, so the batch that
            // failed came back strictly LARGER every pass: self-perpetuating growth, and the cap
            // turns it into a drain. A match is sent per listing and marked per listing, so a burst
            // is bounded by how many new flats exist and a failure retries exactly one of them.
            // Nothing compounds.
            //
            // Against that, capping costs the thing the product is for — the brief says a
            // notification "within minutes of publication", and a capped match waits a full Q37
            // interval for no benefit. Reverses by wrapping this send the way the digest branch is
            // wrapped, if a channel is ever observed rate-limiting a real burst.
            //
            // ASKED AS A MATCH, not merely "was this listing announced". A delivered digest sets
            // `notified_at`, so the plain question suppressed the match notification of any listing
            // promoted DIGEST -> MATCH — while `++$matches` above still counted it, so the pass
            // reported a match the operator never received. The same pass overwrites `tenure` and
            // `outcome`, taking the row out of `staleVerdicts()` and `pendingDigest()`, so neither
            // `scout reclassify` nor `scout digest` could reach it afterwards. There is no third
            // selector. Found by a review panel on 2026-08-24, against a docblock in this file that
            // asserted `reclassify` covered exactly this population.
            if ($this->store->wasNotifiedAs($sighting->dedupKey, 'MATCH')) {
                continue;
            }

            // THE OTHER TRACK (2026-08-29). A twin already pushed means this flat has been
            // announced: an agency copy of it is marked and not pushed, while the DIRECT route is
            // pushed once anyway, saying whose push it follows — the better route is never hidden
            // (the 2026-08-06 ruling), and the mark above stops it ever being pushed again. A twin
            // not yet pushed rides along in this push, with its link, and is marked — unless IT is
            // the direct route, which announces itself when its turn comes (never in this pass:
            // the clusters are sorted direct-first, so that turn has already been).
            $isDirect = $cluster['family'] === 'institutional';
            $twins = [];
            $announcedByTwin = false;
            foreach ($twinsOf[spl_object_id($listing)] ?? [] as $twin) {
                $twinKey = $keyOf[spl_object_id($twin['listing'])] ?? null;
                $pushed = $twinKey !== null && $this->store->wasNotifiedAs($twinKey, 'MATCH');
                $announcedByTwin = $announcedByTwin || $pushed;
                $twins[] = [
                    'source' => $twin['listing']->sourceName,
                    'url' => $twin['listing']->url,
                    'direct' => $twin['family'] === 'institutional',
                    'pushed' => $pushed,
                    'key' => $twinKey,
                ];
            }

            if ($announcedByTwin && !$isDirect) {
                $this->store->markNotified($sighting->dedupKey, $nowIso, 'MATCH');
                ++$twinsSuppressed;

                continue;
            }

            // A5 — ROW 6 (developer ruling 2026-09-05): A MATCH BELOW THE GATE IS QUEUED, NOT PUSHED.
            // It is judged, recorded and counted as a match above; it stays unannounced here and
            // `Store::pendingLowScore()` hands it to the digest drain, which announces it under
            // its own heading and marks it ROLLUP — so a rent drop lifting it over the gate later
            // is a promotion, pushed once. `null` (every fixture) keeps the pre-A5 behaviour.
            // Placed AFTER the twin bookkeeping so a copy already pushed by its direct route is
            // marked as before, and before the send so nothing below the gate ever leaves.
            $pushMin = $this->criteria->notify->pushMinScore;
            if ($pushMin !== null && ($verdict->score ?? 0) < $pushMin) {
                ++$queuedLowScore;

                continue;
            }

            $failures = $this->notifier->send($this->formatter->match(
                $listing,
                $verdict,
                $cluster['duplicates'],
                array_map(
                    static fn (array $t): array => ['source' => $t['source'], 'url' => $t['url'], 'direct' => $t['direct'], 'pushed' => $t['pushed']],
                    $twins,
                ),
                $isDirect,
            ));

            if ($this->notifier->delivered($failures)) {
                ++$notified;
                $this->store->markNotified($sighting->dedupKey, $nowIso, 'MATCH');
                foreach ($twins as $twin) {
                    if (!$twin['pushed'] && !$twin['direct'] && $twin['key'] !== null) {
                        $this->store->markNotified($twin['key'], $nowIso, 'MATCH');
                        ++$twinsSuppressed;
                    }
                }
            } else {
                // Left un-notified ON PURPOSE, so the next run retries. Marking it notified here is
                // the hole Q28 closes: the run reports success, the listing is recorded as sent, and
                // the flat is gone with nothing anywhere saying so.
                ++$undelivered;
            }
        }

        if ($digestEntries !== []) {
            if ($seedOnly) {
                // Every member, for the same reason the match path seeds every member: an absorbed
                // duplicate is currently published, so seeding only the survivor leaves it to be
                // announced by the first pass that reshuffles source order.
                foreach ($digestEntries as $entry) {
                    foreach ($entry['keys'] as $memberKey) {
                        // SEEDED AS 'MATCH', not 'DIGEST', though these are digest entries.
                        // `--seed` means "nothing currently published is news", and seeding them
                        // as doubts would make the PIPELINE announce each one as a match the moment
                        // its tenure resolved — which for a mixed source with a `detail_map` is
                        // most of the catalogue, arriving over the following passes.
                        //
                        // **The cost this used to state was false.** It said "a genuine promotion
                        // inside the seeded set is never announced"; `scout reclassify` announces
                        // it, because `staleVerdicts()` filters on neither `notified_at` nor
                        // `outcome` and `announcePromotions()` never consults `wasNotifiedAs()`. A
                        // review panel demonstrated it on 2026-08-24. So the real cost is narrower:
                        // the PIPELINE will not re-announce a seeded row, and a deliberate
                        // `scout reclassify` still can — which is the right split, since that
                        // command is run on purpose rather than every fifteen minutes.
                        $this->store->markNotified($memberKey, $nowIso, 'MATCH');
                    }
                }
            } else {
                // Emitted at the end of any run that produced NEW entries (Q34), rather than once a
                // day. A third of the corpus routes here, it is the fail-closed rule's only landing
                // zone, and a daily cadence turns "one glance, later" into "gone".
                //
                // **CAPPED, like the manual drain.** `scout digest` was bounded first and this was
                // not — and this is the path that runs unattended, so the reasoning applied here
                // with more force: an unbounded all-or-nothing send whose failure marks nothing
                // comes back next pass with MORE rows in it, and §1's only landing zone hardens
                // into permanent undeliverability while stderr promises a retry into a log nobody
                // reads. Measured by a review panel on 2026-08-24 at 120 entries and 20.9 KB in one
                // send — 4.4x the batch this project had just decided was safe.
                //
                // The remainder is NOT lost: it keeps `outcome = 'DIGEST' AND notified_at IS NULL`,
                // so the next pass re-collects it and `scout digest` can drain it now. Capping
                // makes the backlog shrink; leaving it uncapped made it grow.
                $batch = \array_slice($digestEntries, 0, Store::DIGEST_BATCH);

                // ASSIGNED BEFORE THE SEND, not inside the success branch. It sat there until round
                // 7, so on a FAILED batch send the remainder read `0` — a 500-entry backlog whose
                // 50-entry batch was rejected printed "1 notification(s) non délivrée(s)" and no
                // remainder line at all. The pass summary's `à vérifier` is what was JUDGED, never
                // what is pending, so nothing anywhere named it. The number is a property of the
                // BACKLOG and the cap, not of whether the channel accepted this batch — and it
                // reading healthy on the one pass it should not is the shape of every defect this
                // round found.
                $digestOverflow = max(0, \count($digestEntries) - \count($batch));

                $failures = $this->notifier->send($this->formatter->digest($batch));

                if ($this->notifier->delivered($failures)) {
                    // Marked only AFTER the channel confirmed. Marking first would lose the whole
                    // batch permanently on a failed send — and a digest entry, unlike a match, has
                    // no second chance: nothing else will ever surface it.
                    foreach ($batch as $entry) {
                        $this->store->markNotified($entry['key'], $nowIso, 'DIGEST');
                    }

                    // THE REMAINDER IS NAMED, like both sibling caps. `Formatter::digest()` titles
                    // on the BATCH, so a 120-entry backlog pushed as "À vérifier : 50 annonce(s)"
                    // and the pass summary's `à vérifier` is what was JUDGED this pass, never what
                    // is still pending — so nothing anywhere said a remainder existed. The claim
                    // that the next pass re-collects it holds only while the ad is still published,
                    // and the delisted case is exactly what `scout digest` was built to rescue.
                    $notified += \count($batch);
                } else {
                    ++$undelivered;
                }
            }
        }

        if (!$seedOnly) {
            $undelivered += $this->alertOnHealth($sources, $nowIso);
        }

        // ROW 41 — a source whose every judged card failed the SAME hard filter is named here,
        // beside the errors and never as one: the pass succeeded, the selector may not have.
        $warnings = SameFilterWarning::warnings($filterTally);

        // §1 REFUSALS ARE WARNINGS, not a silent counter. `$sectionOneRefused` was incremented and
        // read NOWHERE (C2 round 4, P1): the one announcing surface that runs unattended every 15
        // minutes said nothing at all, while the other three all speak on the identical refusal.
        // Hard rule 2's shape one layer in from the source — if the gate ever refuses wrongly (an
        // over-merge, or the excluded set growing), the operator sees a healthy source, a plausible
        // match count and a phone that never rings.
        //
        // Each line carries the `dedup_key` AND the command that reverses it, because the refusal
        // is terminal by query: it writes the row's own durable reading and `outcome = REJECT`,
        // which closes `pendingLowScore()`, `pendingDigest()` and `staleVerdicts()` at once.
        $warnings = [...$warnings, ...$sectionOneRefused];

        return new RunResult(
            sourcesRun: $sourcesRun,
            sourcesFailed: $sourcesFailed,
            itemsParsed: $itemsParsed,
            matches: $matches,
            digested: $digested,
            rejectedCount: $rejectedCount,
            duplicates: $duplicates,
            twinsSuppressed: $twinsSuppressed,
            rentDrops: $rentDrops,
            undelivered: $undelivered,
            notified: $notified,
            digestOverflow: $digestOverflow,
            unencodable: $unencodable,
            errors: $errors,
            rejected: $rejected, warnings: $warnings, queuedLowScore: $queuedLowScore,
        );
    }

    /**
     * Alert on EVERY status where `isAlerting()` is true, deduplicated per `(source, status)`.
     *
     * Ruled 2026-08-07 (Q29). The routing table originally named `SOURCE_BROKEN` alone, while six
     * statuses alert — so `NEVER_PRODUCED`, added precisely because it hid behind `OK`, and `STALE`,
     * which catches the schedule itself having stopped, would have been derived, stored and never
     * sent. An alert that is computed and never sent is worse than none (hard rule 2).
     *
     * @param list<Source> $sources
     */
    private function alertOnHealth(array $sources, string $nowIso): int
    {
        $undelivered = 0;
        $cooldown = $this->criteria->notify->sourceBrokenCooldownHours;

        foreach ($sources as $source) {
            $health = $source->health($nowIso);

            if (!$health->status->isAlerting()) {
                // A source that WAS alerting and is now OK gets exactly one recovery notice, and
                // clearing the keys is what makes a second, different breakage that day audible.
                if ($this->store->clearAlerts($source->name())) {
                    $failures = $this->notifier->send($this->formatter->sourceRecovered($health));
                    if (!$this->notifier->delivered($failures)) {
                        ++$undelivered;
                    }
                }

                continue;
            }

            if (!$this->store->shouldAlert($source->name(), $health->status->value, $nowIso, $cooldown)) {
                continue;
            }

            $failures = $this->notifier->send($this->formatter->sourceHealth($health));

            if ($this->notifier->delivered($failures)) {
                $this->store->markAlerted($source->name(), $health->status->value, $nowIso);
            } else {
                // NOT marked, so the alert is retried. A cooldown that starts on a failed send would
                // silence the alert for a day on the strength of a delivery that never happened.
                ++$undelivered;
            }
        }

        return $undelivered;
    }

    /**
     * The classification the CLUSTER is judged on: an excluded member vetoes, nothing else does.
     *
     * §1 is a property of the FLAT, and a cross-portal cluster is one flat seen twice. So if any
     * member carries an excluded tenure, that member's classification is what the engine judges —
     * its reasons travel with it, so the rejection says which portal stated what.
     *
     * **THAT SENTENCE WAS FALSE OF EVERY PASS BUT THE FIRST**, and it took a sixth review round to
     * catch. The check USED to read a `$members` parameter — what `Dedup` clustered out of THIS
     * pass's harvest, the store never consulted — while `assignGroup()` returns before any UPDATE
     * for a single member, so a survivor that clusters alone keeps the `group_key` it earned when
     * the excluded sibling was there. A failed source fetch, a `--source=<name>` run or the sibling
     * delisting was enough to make the flat a match on the next pass. `$groupTenure` — read from
     * the PERSISTED group — is what answers now.
     *
     * There is no in-pass scan any more, and this docblock described one for a round after it was
     * deleted, with a reason that was also wrong in the direction this review keeps finding: it
     * said a first-ever sighting "has no group yet". It does. `assignGroup()` runs in the recording
     * loop at the top of `runOnce()`, before any judging — which is precisely WHY the scan was dead
     * code and why disabling it left the whole suite green.
     *
     * **An UNKNOWN sibling deliberately does NOT veto.** Absence of a signal is not evidence, and
     * most search cards state no tenure at all — In\'li\'s entire card text is four facts and no
     * label — so vetoing on doubt would digest nearly every clustered match in the tree. Over-
     * rejection is the invisible failure here, because nothing arrives to notice. The excluded set
     * is closed and explicit; doubt is already handled by the fail-closed threshold on the
     * survivor\'s own classification.
     *
     * Ties go to the highest confidence, and the survivor wins an exact tie by being the default —
     * both are excluded in that case, so the choice only affects which reasons are shown.
     *
     * **STATED COST, and it is a real one: an OVER-MERGE now costs both flats, not one.** `Dedup`'s
     * documented failure mode is merging two different flats on two corroborating facts; before
     * this rule that hid the absorbed one and still notified the survivor, and now an eligible LLI
     * merged with a stranger carrying `PLS` is silently rejected with it. Demonstrated by a review
     * panel on 2026-08-24 (same commune, same rooms, rents within `Dedup::RENT_TOLERANCE_EUR`, one
     * surface unstated). The direction is the one §1 requires — a missed listing is annoying, a
     * social-housing false positive is not — but silent over-rejection is invisible by definition,
     * so it is written here rather than left to be discovered.
     *
     * **And since the veto now reads the PERSISTED group, that cost is PERMANENT.** `group_key` is
     * never cleared — `grep "SET group_key"` finds only the two `assignGroup()` UPDATEs — so a flat
     * once mis-merged with an excluded stranger is rejected for the rest of the store's life, even
     * on passes where the two no longer cluster. Deliberate: the alternative is a veto that lapses
     * the moment a source has a bad day, which is the hole this durable read was added to close. If
     * it ever bites, the repair is to unpick the group in the store, not to weaken the rule.
     *
     * **THAT REPAIR NO LONGER WORKS, and this sentence promised it for two rounds** (round-5 panel,
     * 2026-08-31). The judging loop writes the JUDGED classification — which carries this veto's
     * excluded tenure — back onto the survivor's own `listings.tenure`, and round 4 then made the
     * row's own reading DURABLE. So a group veto is laundered into the row's own permanent reading:
     * a reviewer cleared `group_key` and deleted the excluded stranger outright, and the flat was
     * still rejected on the next pass, with a reason claiming the `PLS` was "relevé lors d'une
     * lecture précédente de cette annonce" when it was read on a sibling. The cross-track twin
     * carries the identical laundering; Q39 records it there. Nothing re-opens such a row —
     * `staleVerdicts()` skips an excluded tenure, `pendingDigest()` skips a non-DIGEST outcome and
     * `replay` writes no verdicts — so the only repair today is a deliberate edit to that column.
     * Do not describe either veto as reversible until a command exists, or until the stored reading
     * distinguishes "this row said PLS" from "something linked to this row said PLS".
     *
     * **ONE MECHANISM, not two.** This began as a scan over the members clustered in THIS pass,
     * and the durable read was added beside it in round 6. The sabotage ledger then showed the
     * in-pass scan was DEAD: `assignGroup()` runs in the recording loop, before any judging, so
     * for every cluster of two or more the persisted group is already up to date when this is
     * called — disabling the in-pass scan left the whole suite green. Dead safety code is worse
     * than none: it reads as a second line of defence and is not one. Removed rather than kept.
     */
    /**
     * A LISTING'S OWN EXCLUDED READING IS DURABLE (round-3 panel, 2026-08-30). The tool can drop
     * its own evidence — a hydration fingerprint mismatch serves the card alone until the re-fetch
     * budget reaches it — and a row read as `PLS` yesterday was re-judged on the card today, LIBRE
     * by source default, and PUSHED; the overwrite then took the row outside `staleVerdicts()` and
     * `pendingDigest()`, beyond either repair command. `reclassify` enforces *evidence ⊇ original,
     * never ⊂*; the pipeline now does too, by the same rule the twin fact obeys: once excluded,
     * excluded.
     *
     * **IT IS PERMANENT, and this docblock used to say "until an explicit command says otherwise"
     * (round-4 panel, 2026-08-31). There is no such command.** `staleVerdicts()` selects
     * `tenure IS NULL OR tenure IN ('UNKNOWN')`, so `reclassify` never offers an excluded row;
     * `pendingDigest()` selects `outcome = 'DIGEST'`, so `digest` never drains one; `replay` writes
     * no verdicts. A promise of a repair route that does not exist is worse than the plain fact,
     * because it invites someone to look for the route instead of deciding whether to build one.
     * Q38 states its own permanence outright and is the precedent.
     *
     * Stated cost, unchanged and now accurate: a genuine re-labelling by the portal is not honoured
     * at all — only a deliberate edit to the row will undo it. The safe direction (§1).
     *
     * Applied in the RECORDING loop, per member, so the reading is durable for a row that is never
     * judged — see the call site for why the survivor-only version was a §1 hole.
     */
    private function durableOwnReading(Classification $judged, ?Tenure $previous): Classification
    {
        if ($previous === null || !$previous->isExcluded() || $judged->tenure->isExcluded()) {
            return $judged;
        }

        return new Classification(
            tenure: $previous,
            confidenceBp: 100,
            signals: [new TenureSignal(
                tier: 1,
                tenure: $previous,
                // THE REASON MAY NOT CLAIM A PROVENANCE THE ROW DOES NOT CARRY (F20, 2026-09-04).
                //
                // It read *"relevé lors d'une lecture précédente de cette annonce"* — recorded on a
                // previous reading of THIS listing — and that is true only sometimes.
                // `listings.tenure` holds one value and no note of where it came from, while the
                // judging loop writes the JUDGED classification back onto it; a group veto's or a
                // twin's excluded tenure is therefore laundered into the row's own column and is
                // indistinguishable afterwards. A reviewer acted on the old sentence: they cleared
                // `group_key`, removed the excluded stranger, and the flat stayed rejected by a
                // message pointing at a reading that did not take place.
                //
                // The repair is not to invent the provenance — nothing stores it — but to stop
                // asserting it, and to say plainly that it is not recorded. Hard rule 9 at the
                // reason layer. The rejection is exactly as durable as before; only the sentence
                // changed, and `scout run -v` is where it is read.
                reason: 'régime exclu (' . $previous->value . ') retenu pour cette annonce — '
                    . 'origine non enregistrée (lecture propre, groupe ou autre voie) — conservé (§1)',
                evidence: $previous->value,
            )],
            outcome: Outcome::REJECT,
        );
    }

    /**
     * §1 ACROSS THE TWO TRACKS (2026-08-30). The cross-track link of 2026-08-29 reused the
     * positive evidence that a landlord's listing and its agency copy are ONE flat — for the "one
     * push" bookkeeping only. It consulted nothing else, so a direct route REJECTED as `PLS` on its
     * detail page was still named, with its URL, as the *voie directe, candidature au bailleur* in
     * the match push of its tenure-less agency copy: the user told to apply for a PLS flat at the
     * bailleur, by a tool whose §1 promise is that this never happens. A review panel proved it
     * through the real pipeline. Two rules, both the schema-v4 group veto read across the track
     * boundary: an EXCLUDED twin vetoes the flat whichever route is being judged, and an
     * UNDETERMINED twin turns a match into a doubt — the digest, never a push.
     *
     * **THE FACT IS PERSISTED (schema v12), ON EVERY MEMBER OF THE CLUSTER, AND READ ACROSS IT.**
     * A veto living only in this pass's harvest lapsed the moment the twin was not fetched (round
     * 2: the agency copy fetched ALONE was pushed). A fact written on the survivor's row only lapsed
     * the moment survivorship changed (round 3: a second private-portal copy, absorbed on pass 1,
     * pushed alone on pass 2 and again on pass 3 when it survived the seloger row whose PLS sat on
     * disk beside it — judgement is per cluster, the fact was per row, and the pacer shuffles the
     * harvest). So what the other track said is written to every member's row whenever a twin is
     * in hand (`Store::recordTwin()`, precedence: excluded sticks, otherwise the last reading wins)
     * and the most restrictive fact across the cluster's rows is what judges. The twin's tenure is
     * read as its own cluster would be judged (its persisted group veto included).
     *
     * @param list<string> $memberKeys every row of this cluster, the survivor's included
     * @param list<array{listing: RawListing, family: string}> $twins
     * @param array<int, array{tenure: Tenure, source: string, bp: int}> $twinReading survivor object
     *        id -> the reading resolved across the WHOLE twin graph, computed before any judging.
     *        `bp` is that reading's own confidence, carried so `recordTwin()` can refuse to let a
     *        tier-5 source default resolve a doubt another route raised (COR-F5)
     */
    private function twinClassification(Classification $survivor, string $dedupKey, array $memberKeys, array $twins, array $twinReading): Classification
    {
        if ($survivor->tenure->isExcluded()) {
            return $survivor;
        }

        $rank = static fn (Tenure $t): int => $t->isExcluded() ? 2 : ($t === Tenure::UNKNOWN ? 1 : 0);

        // The most restrictive reading among the twins in hand: excluded > undetermined > eligible.
        //
        // Read from `$twinReading`, which the caller resolved to a FIXED POINT across the whole twin
        // graph before any survivor was judged. It used to recompute each twin's reading here from
        // `$observed` — that twin's own durable reading plus its own group veto — which is every
        // surface EXCEPT the twin's own twins. So an exclusion that reached a listing through ITS
        // twin never reached the listing's OTHER twins, and the second copy of a flat rejected as
        // PLS was pushed as a match naming the rejected route (round-6 panel, proven by execution).
        /** @var array{tenure: Tenure, source: string, bp: int}|null $seen */
        $seen = null;
        foreach ($twins as $twin) {
            $resolved = $twinReading[spl_object_id($twin['listing'])] ?? null;
            if ($resolved === null) {
                continue;
            }
            // THE TIE IS BROKEN ON CONFIDENCE, and before COR-F5 it did not need to be (C2 round 2).
            //
            // A strict `>` leaves two ELIGIBLE twins tied at rank 0, so the FIRST ITERATED won — and
            // `$twinsOf` is built from `$clustered`, whose within-track order is `Core\Pacer`'s
            // shuffle. That was cosmetic while both twins wrote the same tenure and only the `source`
            // string differed. `eb5d971` made `bp` decide whether `recordTwin()` writes AT ALL, so
            // the tie began deciding the outcome on identical input. Reproduced through the real
            // method and the real store, with only the twin order differing: leading with the WEAK
            // twin left the flat in the digest and the stored fact undetermined; leading with the
            // strong one, on the same input, produced a push and an eligible stored fact.
            //
            // The unsafe direction is unreachable — the worst case is over-caution — so this is not
            // a §1 breach. What it refuted is the claim `eb5d971` shipped under, that the change
            // "can only ever make the store MORE careful": it also made the store NON-DETERMINISTIC,
            // which is the failure the fixed point three hundred lines above exists to remove. A
            // guarantee whose answer depends on the pacer's shuffle is not a guarantee, whichever
            // way it errs.
            if ($seen === null
                || $rank($resolved['tenure']) > $rank($seen['tenure'])
                || ($rank($resolved['tenure']) === $rank($seen['tenure']) && $resolved['bp'] > $seen['bp'])) {
                // The SOURCE named is the twin actually in hand, not whatever distant node the
                // reading propagated from: the notification tells the operator where to look, and a
                // route they cannot see from here is not that.
                // The SOURCE is the twin in hand; the CONFIDENCE is the resolved reading's own, so
                // the two describe the same judgement even when it propagated from further away.
                $seen = ['tenure' => $resolved['tenure'], 'source' => $twin['listing']->sourceName, 'bp' => $resolved['bp']];
            }
        }

        if ($seen !== null) {
            foreach ($memberKeys as $memberKey) {
                $this->store->recordTwin($memberKey, $seen['tenure'], $seen['source'], $seen['bp']);
            }
        }

        // Read back across the cluster rather than trusting `$seen`: the store keeps an earlier
        // excluded reading over today's eligible one, and an absorbed member may carry a fact this
        // survivor's own row never received.
        /** @var array{tenure: Tenure, source: string}|null $fact */
        $fact = null;
        foreach ($memberKeys as $readKey) {
            $candidate = $this->store->twinTenure($readKey);
            if ($candidate !== null && ($fact === null || $rank($candidate['tenure']) > $rank($fact['tenure']))) {
                $fact = $candidate;
            }
        }
        if ($fact === null) {
            return $survivor;
        }

        if ($fact['tenure']->isExcluded()) {
            return new Classification(
                tenure: $fact['tenure'],
                confidenceBp: 100,
                signals: [new TenureSignal(
                    tier: 1,
                    tenure: $fact['tenure'],
                    reason: 'régime exclu (' . $fact['tenure']->value . ') relevé sur la même annonce via ' . $fact['source'] . ' (autre voie)',
                    evidence: $fact['tenure']->value,
                )],
                outcome: Outcome::REJECT,
            );
        }

        if ($fact['tenure'] === Tenure::UNKNOWN && $survivor->outcome === Outcome::MATCH) {
            return new Classification(
                tenure: Tenure::UNKNOWN,
                confidenceBp: $survivor->confidenceBp,
                signals: [...$survivor->signals, new TenureSignal(
                    tier: 1,
                    tenure: Tenure::UNKNOWN,
                    reason: 'régime indéterminé sur la même annonce via ' . $fact['source'] . ' (autre voie) — à vérifier',
                    evidence: $fact['source'],
                )],
                outcome: Outcome::DIGEST,
            );
        }

        return $survivor;
    }

    /**
     * A dwelling the store already judged EXCLUDED rejects this listing, whatever advertised it.
     *
     * §1 is a fact about the FLAT, not about the advertisement. The three routes above all need an
     * edge — a persisted `group_key`, a twin in this pass's harvest, or the row's own previous
     * reading — and a portal re-advertising an excluded flat under a NEW AD ID has none: a new
     * `external_id` is a fresh row, and `Dedup` refuses a same-source edge because the source's own
     * id is authoritative for IDENTITY. Correct for identity, wrong for §1, and the C2 round-1
     * correctness lens proved it end to end through the real pipeline: `s1` rejected because the
     * store says `PLS`, `s2` pushed as a MATCH, one pass, one portal, one flat.
     *
     * **It is the row's OWN judged verdict, never a twin fact.** `recordTwin()` would persist it with
     * a source name and `scout digest` would then announce *"relevé via bienici (autre voie)"* about
     * a bienici row — a same-source copy is not another route, and a notification that names the
     * route the operator is already looking at is worse than one that names none.
     *
     * **Excluded only.** The twin mechanism also turns an UNDETERMINED reading into a DIGEST; doing
     * that by content match against the whole store is a different decision — the In'li cost, *not
     * §1 satisfied, the tool switched off* — and it is deliberately not made here.
     *
     * **STATED COST, and it is the Gros Saule shape.** Two genuinely different flats in one residence
     * sharing commune, rooms, surface and rent within tolerance are one dwelling to
     * `sameFlatReason()`, so if either is excluded both are rejected — permanently, since the reading
     * is durable. Q39 already prices pairwise over-linking as a permanent rejection in exactly this
     * direction, and §1's own instruction is to bias every ambiguous decision toward not notifying.
     * The bar is unchanged and merges only on POSITIVE evidence: a stated disagreement on rent,
     * surface, rooms or floor is decisive, and two unknown communes are two unknowns rather than a
     * match.
     *
     * @param list<array{key: string, source: string, externalId: string, tenure: Tenure, listing: RawListing}> $excludedDwellings
     */
    private function storedDwellingClassification(Classification $survivor, RawListing $listing, array $excludedDwellings): Classification
    {
        if ($survivor->tenure->isExcluded()) {
            return $survivor;
        }

        // ONE matcher, shared with the digest drain (C2 round 8, completeness P0): that surface
        // read only two of the FOUR persisted routes because this rule lived here alone.
        $candidate = ExcludedDwellings::match($listing, $excludedDwellings, $this->dedup);
        if ($candidate === null) {
            return $survivor;
        }

        return new Classification(
            tenure: $candidate['tenure'],
            confidenceBp: 100,
            signals: [new TenureSignal(
                tier: 1,
                tenure: $candidate['tenure'],
                reason: 'régime exclu (' . $candidate['tenure']->value . ') déjà relevé sur le même logement — '
                    . $candidate['source'] . ' ' . $candidate['externalId'] . ' (' . $candidate['reason'] . ')',
                evidence: $candidate['tenure']->value,
            )],
            outcome: Outcome::REJECT,
        );
    }

    private function clusterClassification(Classification $survivor, ?Tenure $groupTenure): Classification
    {
        // No re-check that `$groupTenure` is excluded: `Store::groupExcludedTenure()` returns
        // nothing else, and the caller-side re-check that used to sit here was unreachable — a
        // sabotage case written against it went undetected because no input can reach it.
        if ($survivor->tenure->isExcluded() || $groupTenure === null) {
            return $survivor;
        }

        $tenure = $groupTenure;

        return new Classification(
            tenure: $tenure,
            // CARRIED FOR THE RECORD, not for the decision. `CriteriaEngine` branches on
            // `$classification->outcome` alone — it never reads tenure or confidence — so this
            // number decides nothing, and an earlier version of this comment claimed it had to
            // "clear the fail-closed floor on its own". It does not; that was an invented
            // justification, and the sabotage case written to pin it correctly went undetected.
            // 100 is what a stored, explicit tenure on a sibling is worth to `scout dump` and to
            // anyone reading the row.
            confidenceBp: 100,
            signals: [new TenureSignal(
                tier: 1,
                tenure: $tenure,
                reason: 'régime exclu (' . $tenure->value . ') relevé sur un doublon de cette annonce',
                evidence: $tenure->value,
            )],
            outcome: Outcome::REJECT,
        );
    }

    /**
     * How long ago this listing was first seen, or `null` if it is new to the store right now.
     *
     * A listing with no snapshot is new. An UNDATEABLE `first_seen_at` returns `null` rather than
     * throwing: the store's own migration treats an undateable row as the oldest thing on record
     * rather than refusing to open the database, and refusing to score a listing over a bad
     * timestamp would be a harsher response to the same corruption.
     */
    private function ageSeconds(string $dedupKey, string $nowIso): ?int
    {
        $snapshot = $this->store->snapshot($dedupKey);
        if ($snapshot === null) {
            return null;
        }

        try {
            $first = new \DateTimeImmutable($snapshot->firstSeenAt);
            $now = new \DateTimeImmutable($nowIso);
        } catch (\Exception) {
            return null;
        }

        return max(0, $now->getTimestamp() - $first->getTimestamp());
    }

    /**
     * @param list<Source> $sources
     */
    private function profileFor(array $sources, RawListing $listing): \Scout\Rent\Core\SourceProfile
    {
        foreach ($sources as $source) {
            if ($source->name() === $listing->sourceName) {
                return $source->profile();
            }
        }

        // Unreachable in practice — the listing came from one of these sources. Fail CLOSED anyway:
        // `mixedTenure: true` with no default means a listing with no signal digests rather than
        // matching, which is the direction §1 requires when we do not know what we are looking at.
        return new \Scout\Rent\Core\SourceProfile($listing->sourceName, 'institutional', null, true);
    }
}
