<?php

declare(strict_types=1);

namespace Scout\Adapters;

use Scout\Core\Redact;

/**
 * A source failed. Loudly, and with the failure preserved.
 *
 * This type exists so that `CLAUDE.md` hard rule 3 has something to be satisfied BY. "Do not return
 * an empty list on failure" is only actionable if there is an obvious thing to do instead, and this
 * is it: every adapter failure path throws one of these, and the run loop turns it into a failed
 * entry in the run log, which is what `SourceStatus::BROKEN` is derived from.
 *
 * **The message is redacted at construction**, not at the point it is displayed. An adapter's
 * exception naturally carries the request URL — and the IDFM key travels as a query parameter — or
 * the mailbox it failed to open. `Store::recordRun()` persists that text and `RunStore::health()`
 * interpolates it into a user-facing detail, so masking late means masking in two places and
 * forgetting one. Masking here means a `SourceError` is safe to persist, log or notify by
 * construction, and there is no unmasked variant to reach for by mistake.
 *
 * The original is deliberately NOT kept anywhere on this object. A `$rawMessage` property would be
 * the obvious convenience and it would defeat the point: anything reachable gets logged eventually.
 */
final class SourceError extends \RuntimeException
{
    public function __construct(
        public readonly string $sourceName,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            $sourceName . ': ' . Redact::text($message),
            0,
            // The chain is kept for a stack trace in `-v` mode. A `$previous->getMessage()` is NOT
            // masked — PHP builds that string itself — so never print a chained message directly;
            // `Redact::text()` it, or print only this one.
            $previous,
        );
    }

    public static function from(string $sourceName, \Throwable $e): self
    {
        return new self($sourceName, $e->getMessage(), $e);
    }
}
