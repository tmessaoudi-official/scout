<?php

declare(strict_types=1);

namespace Scout\Rent\Adapters;

use Scout\Core\Pacer;
use Scout\Rent\Core\RawListing;
use Scout\Rent\Core\SourceProfile;
use Scout\Rent\Core\Tenure;
use Scout\Adapters\AcknowledgesMessages;
use Scout\Adapters\FeedFreshness;
use Scout\Core\PatternMissLog;

/**
 * Wraps a source so that {@see fetch()} cannot happen faster than the Q37 ruling permits.
 *
 * WHY A DECORATOR RATHER THAN PACING INSIDE `Pipeline`. `Pipeline::runOnce()` serves `--once` as
 * well as `--watch`, and a single `--once` invocation under cron has no cadence to respect. Teaching
 * the pipeline about time would therefore require a "do not actually pace" mode — and a safety
 * control with an off switch is the kind that ends up off. Here the pacing is decided once, where
 * the loop is built: `--watch` wraps its sources, `--once` does not, and `Pipeline` never learns
 * that time exists.
 *
 * WHY NOT INSIDE THE ADAPTERS. Every adapter would need its own copy of the rule, they would each
 * hold their own state, and the cross-source guarantee — *two different adapters on ONE landlord's
 * domain share a single 60 s window* — would be unimplementable, because no adapter can see what
 * another has just done. That guarantee is the whole reason Q37 is worded in hosts rather than in
 * sources, so it is the one that must not be lost.
 *
 * The pacer is shared across every wrapped source by construction: one `Pacer` instance is passed to
 * all of them. A decorator handed its own private pacer would pace nothing useful, which is why
 * `PacedSource::wrapAll()` exists and is the only intended way to build these.
 */
final readonly class PacedSource implements AcknowledgesMessages, FeedFreshness, Source
{
    public function __construct(
        private Source $inner,
        private Pacer $pacer,
    ) {}

    /**
     * Wraps a whole pass's worth of sources around ONE pacer, and shuffles them.
     *
     * Both halves belong together. Sharing the pacer is what makes the same-host rule hold across
     * different adapters; shuffling is Q37's requirement that no site is reliably polled first,
     * because being first every fifteen minutes is itself a fingerprint. Splitting them into two
     * calls at the call site is how one of them eventually gets forgotten.
     *
     * @param  list<Source> $sources
     * @return list<Source>
     */
    public static function wrapAll(array $sources, Pacer $pacer): array
    {
        return array_map(
            static fn (Source $s): Source => new self($s, $pacer),
            $pacer->shuffle($sources),
        );
    }

    /**
     * Waits its turn, then polls.
     *
     * The wait is BEFORE the request and the pacer records the attempt before control reaches the
     * inner source — so a fetch that throws has still consumed its host's window. That is deliberate
     * and it is the interesting case: the packet left the machine and the host saw it, so retrying a
     * broken source inside its own window would hammer a site at the exact moment it is most likely
     * to be rate-limiting on purpose.
     *
     * Nothing is caught here. `CLAUDE.md` hard rule 3 — a decorator that turned a `SourceError` into
     * `[]` would read as defensive programming while converting a loud breakage into the silent one
     * the whole health subsystem exists to prevent.
     *
     * @return list<RawListing>
     *
     * @throws SourceError
     */
    public function fetch(): array
    {
        $this->pacer->beforeFetch($this->inner->host());

        return $this->inner->fetch();
    }

    /**
     * Forwarded, because a decorator that silently drops a capability is worse than one that lacks it.
     *
     * `wrapAll()` wraps EVERY source, so without this the `--watch` loop — the only mode where a
     * feed can go silent unnoticed for days — would be exactly the mode in which the detection was
     * unreachable. An inner source that reports no freshness answers `null`, which yields no
     * verdict rather than a false one.
     */
    public function newestFeedItemAt(): ?string
    {
        return $this->inner instanceof FeedFreshness ? $this->inner->newestFeedItemAt() : null;
    }

    /**
     * Forwarded for the same reason, and it was NOT (C2 round 2, 2026-09-04): the decorator dropped
     * this capability once, and a paced source would then have reported an empty miss log — every
     * source going quiet at once, F27's shape exactly.
     *
     * Since step 8e-2 every `Source` reports a miss log ({@see \Scout\Core\ReportsHealth}), so the
     * inner's OWN log is forwarded unconditionally — the old fresh-log fallback for an inner that did
     * not count is gone, because there is no such inner any more. Under `--watch` this log is what
     * `SourceHealthService` escalates, so a decorator handing over a fresh log here would report every
     * blind pattern as healthy; `PatternMissEscalationTest` pins the forwarding.
     *
     * The interface's promise — that `reset()` runs at the start of every fetch — is the inner's to
     * keep, and {@see fetch()} delegates to it unchanged.
     */
    public function patternMisses(): PatternMissLog
    {
        return $this->inner->patternMisses();
    }

    /**
     * The inner's own threshold, forwarded for the same reason: under `--watch` every rent source is
     * wrapped, so a decorator answering `null` here would silently swap a per-source `feed_silent_days`
     * for the global one on the deployed path alone.
     */
    public function feedSilentDays(): ?int
    {
        return $this->inner->feedSilentDays();
    }

    /**
     * The THIRD capability forwarded the same way (row 36). Under `--watch` every rent source is
     * wrapped in this decorator, so a capability it drops is one the deployed path never has —
     * and the pipeline gates on the interface, so this class must carry it or `\Seen` would be
     * set by `--once` and never by the watcher. No pacing: an acknowledgement is not a web request.
     */
    public function acknowledge(): void
    {
        if ($this->inner instanceof AcknowledgesMessages) {
            $this->inner->acknowledge();
        }
    }

    public function name(): string
    {
        return $this->inner->name();
    }

    public function host(): ?string
    {
        return $this->inner->host();
    }

    public function family(): string
    {
        return $this->inner->family();
    }

    public function defaultTenure(): ?Tenure
    {
        return $this->inner->defaultTenure();
    }

    public function profile(): SourceProfile
    {
        return $this->inner->profile();
    }
}
