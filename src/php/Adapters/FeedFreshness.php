<?php

declare(strict_types=1);

namespace Scout\Adapters;

/**
 * A source that can say WHEN its feed last delivered something, as distinct from what it parsed.
 *
 * **Why this is separate from {@see Source} rather than a method on it.** Most sources have no
 * answer: an HTML or JSON source fetches a page that exists whether or not anything changed, so
 * "when did the feed last deliver" is not a question its payload can answer. Adding a method every
 * implementation would have to return `null` from is a contract nobody satisfies — and `Source`'s
 * own docblock says a contract every source has to bypass is not a contract. An email-alert source
 * is different in kind: its payload IS a set of dated messages.
 *
 * **What it buys.** Measured on the live watcher 2026-08-28: `leboncoin` reported a healthy
 * `item_count = 3` on 263 consecutive passes, every one of them re-reading ONE message dated 26
 * August that `SEARCH SINCE 7 days` kept matching. No existing verdict could see it — the baseline
 * was 3 and the last count was 3, so nothing dropped; no run failed; the schedule never stopped.
 * `CLAUDE.md` hard rule 2's shape, reached without a single `catch`.
 *
 * The obvious alternative — *"warn when no NEW listing has arrived for N days"* — is refused on
 * purpose: that is also exactly what a quiet rental market looks like, so it restates the ambiguity
 * instead of resolving it. Logirep legitimately returns the same 113 listings on every pass.
 *
 * **STATED COST, and the mailbox already holds the counterexample.** This reports that the sender
 * sent SOMETHING, not that the ALERT fired. `params.from` scopes a source to one address, and a
 * portal uses that address for more than alerts: `leboncoin`'s only other message in fourteen days
 * is a `Nouvel appareil détecté` account notice, which matches the filter and would have refreshed
 * this date. So an account notice or a marketing mail can mask alert silence for as long as it
 * keeps arriving. Tightening it to "the newest message that actually YIELDED a listing" is the
 * obvious next step and is deliberately not taken yet — it would make the signal depend on the
 * parser, so a parser regression would then present as a silent feed rather than as a broken one,
 * and those need different fixes.
 *
 * @see \Scout\Core\SourceStatus::FEED_SILENT for the verdict this feeds
 */
interface FeedFreshness
{
    /**
     * When the feed last delivered, as an ISO-8601 instant, or `null` when unknown.
     *
     * Valid only after a {@see Source::fetch()} in the same pass — before one there is nothing to
     * report. `null` is UNKNOWN and must never be read as old (hard rule 9): `RunStore::health()`
     * declines to judge on it, which is what keeps the frozen-fixture workflow and the entire
     * pre-schema-v11 run log out of a permanent alert.
     */
    public function newestFeedItemAt(): ?string;
}
