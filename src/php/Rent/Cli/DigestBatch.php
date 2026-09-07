<?php

declare(strict_types=1);

namespace Scout\Rent\Cli;

use Scout\Rent\Core\RawListing;
use Scout\Rent\Core\Verdict;

/**
 * One drain of the *à vérifier* bin, collected but not yet sent.
 *
 * This type exists so that `scout digest` and Q34's daily floor share ONE implementation of the
 * drain. The bin is §1's only landing zone: everything the classifier could not resolve confidently
 * lands here, and a second copy of the collection logic is exactly how one of them drifts into
 * announcing a listing the other would have withheld. The seam is drawn so that the shared half
 * decides WHAT is in the batch and the callers decide what to print, whether to send, and what a
 * failure means for their exit code.
 *
 * Collection **never throws** — a per-row snapshot that will not decode becomes a warning and a
 * degraded listing, never an exception. That is load-bearing rather than defensive: the floor runs
 * inside the watch loop's `finally`, where a throw would be caught by `WatchLoop` and counted as a
 * failed pass, so one damaged row would report every source as broken.
 */
final readonly class DigestBatch
{
    /**
     * @param list<array{listing: RawListing, verdict: Verdict, key: string, keys: list<string>}> $entries
     *        the capped batch, ready for `Formatter::digest()`
     * @param int          $waiting         total pending rows, which may exceed the batch
     * @param int          $withoutSnapshot rows carrying no snapshot at all — a live source fault
     *                                      (an unencodable payload), never an old row: the query
     *                                      filters on `outcome`, itself a schema-v7 column that is
     *                                      not backfilled, so a genuinely pre-v7 row has
     *                                      `outcome = NULL` and is never returned here
     * @param list<string> $warnings        already redacted, one per row whose snapshot would not
     *                                      decode
     */
    public function __construct(
        public array $entries = [],
        public int $waiting = 0,
        public int $withoutSnapshot = 0,
        public array $warnings = [],
        /**
         * THE SECOND QUEUE (A5, row 6, 2026-09-05): matches held back by `push_min_score`, drained
         * through this same batch under their own heading — never mixed into `$entries`, because
         * the rent digest MEANS tenure doubt and a settled LLI announced there would misreport its
         * §1 status. Same entry shape; marked `ROLLUP` on delivery, never `DIGEST`.
         *
         * @var list<array{listing: RawListing, verdict: Verdict, key: string, keys: list<string>}>
         */
        public array $lowScore = [],
        public int $waitingLowScore = 0,
        /**
         * THE RETRIES (C2 round 6, resilience P2): rows in the low-score queue that are NOT under
         * the line — a match whose individual push FAILED, or any queued match on a deployment
         * with no gate at all. Both queries select `outcome = MATCH AND notified_at IS NULL` and
         * cannot tell the two apart; the drain can, because it re-scores. Announced as ordinary
         * MATCH pushes by every emission site, marked `MATCH` on delivery — never filed under
         * « score bas », which would claim a threshold the score does not fall under.
         *
         * @var list<array{listing: RawListing, verdict: Verdict, key: string, keys: list<string>}>
         */
        public array $retries = [],
    ) {}

    /** Nothing in EITHER queue. */
    public function isEmpty(): bool
    {
        return $this->entries === [] && $this->lowScore === [] && $this->retries === [];
    }

    /**
     * What THIS MAIL announces — the tenure bin plus the rollup, and deliberately NOT the retries.
     *
     * The retries are individual pushes with their own line, printed by `pushRetries()` and
     * carrying its own delivered count. Adding them here counted them a second time, and counted
     * them as *« émise(s) »* before any channel had accepted one — so a drain against a dead
     * channel reported them as emitted (C2 round 8, P3 on two lenses). `isEmpty()` still knows all
     * three lists: *is there anything to do* and *what did this mail say* are different questions.
     */
    public function count(): int
    {
        return \count($this->entries) + \count($this->lowScore);
    }

    /**
     * Pending rows this batch did not take.
     *
     * Said out loud by every caller, because a capped batch that stayed silent about the remainder
     * would look like the whole backlog — and the operator would stop draining it.
     *
     * **IT MUST STAY SILENT WHEN THERE IS NO REMAINDER, and for one round it did not** (C2 round 7,
     * P1 on two lenses). Round 6 split the low-score queue into `lowScore` + `retries` and updated
     * `isEmpty()` to know three lists; this method and `count()` still knew two. `waitingLowScore`
     * counts EVERY queued row, so each row that became a retry was subtracted from nothing and
     * reported as still pending — on a deployment with no `push_min_score`, where every queued row
     * is a retry, that is a phantom backlog on every single drain, printed by the verb and by the
     * daily floor. The guarantee above is the same lie pointing the other way, and it is what makes
     * an operator stop reading the line.
     *
     * A COLLAPSED TWIN COUNTS FOR EVERY KEY IT DRAINED, not for one: the pair leaves the queue
     * together, so counting the entry once would report the other route as a remainder for ever.
     *
     * **`$retriesDrained` IS REQUIRED, AND IT IS THE KEY COUNT THE CHANNEL ACTUALLY TOOK** (C2
     * round 8, P1 on two lenses). Round 7 fixed the over-report and introduced the under-report in
     * the same change: this method subtracted EVERY retry, while `pushRetries()` leaves a refused
     * one queued by design — so a drain against a dead channel claimed `0 autre(s) en attente` for
     * a backlog that had not moved. There is no default, because both defaults are a lie in one
     * direction and the caller is the only thing that knows which happened. It follows that the
     * remainder line must be printed AFTER the retries are attempted, never before.
     *
     * **`$rollupDelivered` IS THE SAME RULE APPLIED TO THE MAIL** (C2 milestone panel, P2). The
     * rolled-up rows were subtracted UNCONDITIONALLY while `digest` called this BEFORE
     * `$notifier->send()` — so a refused mail marked nothing, left every row queued, and reported
     * them as gone. The rent daily floor was already correct (it counts after marking); only the
     * verb was wrong, which is this repo's *a fix landing on one of two symmetric surfaces* once
     * more. No default here either, and for the same reason as above.
     */
    /**
     * @param list<array{keys: list<string>}> $lowScoreAnnounced the rollup entries that SURVIVED the
     *                                                            §1 gate — never `$this->lowScore`,
     *                                                            which is what was QUEUED
     */
    public function overflow(int $retriesDrained, bool $batchAccountedFor, array $lowScoreAnnounced): int
    {
        // ONE mail carries both lists, so the fact is ONE fact and it governs both halves. A first
        // cut gated only the rollup half and would have left the digest half telling the same lie
        // in the same line — the very shape this finding is an instance of.
        //
        // **IT IS "ACCOUNTED FOR", NOT "DELIVERED"** (C2 round 2, P2 on two lenses). Round 1 named
        // it `$rollupDelivered` and passed `false` on the DRY-RUN path, where nothing is delivered
        // by definition — so a dry run subtracted nothing and reported the entire queue as waiting,
        // under wording asserting a backlog BEYOND the batch it had just listed. On a one-row bin it
        // printed the row and then called it an *other*. A dry run SHOWS the batch to the operator,
        // so the batch is accounted for; only a refused send leaves it unaccounted.
        // THE ANNOUNCED LIST, not the queued one (C2 round 5). This iterated `$this->lowScore`,
        // so a row the §1 gate removed from the mail was still counted as drained — and the
        // remainder line then stayed silent while that row sat in `pendingLowScore()`. That is
        // this method's own documented guarantee read backwards.
        $announced = $batchAccountedFor ? \count($this->entries) : 0;
        $drained = $retriesDrained;
        if ($batchAccountedFor) {
            foreach ($lowScoreAnnounced as $entry) {
                $drained += \count($entry['keys']);
            }
        }

        return max(0, $this->waiting - $announced) + max(0, $this->waitingLowScore - $drained);
    }

    /**
     * Every key the retries hold — what a DRY RUN accounts for, having printed them all.
     *
     * A dry run drains nothing, so `$retriesDrained` is `0` there and the retries counted as still
     * waiting BEYOND the batch — under a line the operator reads immediately after the `[RETRY]`
     * lines listing them (C2 round 3, P1 on all three lenses). Round 2 taught `overflow()` that a
     * dry run accounts for the batch and stopped at `entries` + `lowScore`; the retries are the
     * third list and they are printed too. On a deployment with no `push_min_score` every queued
     * row is a retry, so that was the whole queue on every dry run.
     */
    public function retryKeyCount(): int
    {
        $keys = 0;
        foreach ($this->retries as $entry) {
            $keys += \count($entry['keys']);
        }

        return $keys;
    }

    public function unreadable(): int
    {
        return \count($this->warnings);
    }
}
