<?php

declare(strict_types=1);

namespace Scout\Rent\Adapters;

use Scout\Core\ReportsHealth;
use Scout\Rent\Core\RawListing;
use Scout\Rent\Core\SourceProfile;
use Scout\Rent\Core\Tenure;

/**
 * The one contract every source satisfies — `CLAUDE.md` § Architecture.
 *
 * **Adding a source is meant to be config-only in the common case.** A bespoke class under
 * `Adapters/sites/` is the fallback, not the default path; if you find yourself writing one, the
 * honest thing is to say why `config/rent/sources.json` was not enough, because a contract every source
 * has to bypass is not a contract.
 *
 * TWO RULES THE INTERFACE ITSELF ENFORCES:
 *
 * 1. **{@see fetch()} throws. It never returns an empty list to signal failure.** `CLAUDE.md` hard
 *    rule 3 calls this the highest-frequency defect class in this codebase, and the prototype commits
 *    it: `except Exception: return []` turns a loud breakage into a silent one, which is precisely
 *    what the source-health subsystem exists to prevent. A zero-item result and a failure are
 *    different facts, and only the caller — which records the run — may decide what to do with each.
 * 2. **`defaultTenure()` is a hint, never a verdict.** It is signal priority 5, the lowest there is.
 *    The classifier always runs; a source cannot declare its listings eligible.
 *
 * **A source does not judge its own health** (step 8e-2). It REPORTS what the verdict needs —
 * {@see ReportsHealth}: its name, its own pattern-miss log, its own `feed_silent_days` — and
 * {@see \Scout\Core\SourceHealthService} judges it against the run log with a required clock.
 */
interface Source extends ReportsHealth
{
    /** Key in `config/rent/sources.json`. */
    public function name(): string;

    /** @return 'institutional'|'private' */
    public function family(): string;

    /** A HINT of last resort, consulted only when nothing in the listing itself fires. */
    public function defaultTenure(): ?Tenure;

    /** The slice the tenure classifier needs, including the `mixed_tenure` fail-closed switch. */
    public function profile(): SourceProfile;

    /**
     * One poll. Returns every listing the source published, unfiltered and unclassified.
     *
     * The count returned here is what `item_count` means for source health — **what the adapter
     * PARSED, before any criteria are applied** (ruled 2026-08-07, Q30). A source is healthy when it
     * is producing listings, whether or not any of them match. Counting matches instead would make
     * the health subsystem a measure of the Île-de-France rental market rather than of the adapter,
     * and a drifted selector on a source whose matches are usually zero would become undetectable.
     *
     * @return list<RawListing>
     *
     * @throws SourceError on ANY failure — network, HTTP status, malformed payload, missing
     *                     `items_path`. Never swallow and return `[]`.
     */
    public function fetch(): array;

    /**
     * The internet host {@see fetch()} will contact, or `null` if it contacts none.
     *
     * This exists for one reason: the Q37 pacing ruling (2026-08-07) is written in terms of HOSTS —
     * *"at least 5 s between requests to distinct hosts, at least 60 s between two requests to the
     * same host"* — and `--watch` cannot honour it without asking each source where it is about to
     * go. Pacing by SOURCE instead would give two sources on one landlord's domain a private 60 s
     * window each, which is precisely the polling burst that gets an IP banned, and `CLAUDE.md` hard
     * rule 5 leaves polite rate limiting as the entire strategy for not being banned.
     *
     * `null` is a claim, not a fallback: *this source issues no outbound web request*. A fixture
     * read from disk and an IMAP mailbox both qualify — a mailbox is one connection to one's own
     * mail provider, not a site that can ban anyone. A `null` source is neither delayed nor allowed
     * to consume the distinct-host slot, so it never pushes a real request further down the pass.
     *
     * Returning `null` from a source that DOES make requests silently opts it out of the ruling, so
     * a new adapter must answer this honestly. There is no default implementation for that reason.
     */
    public function host(): ?string;
}
