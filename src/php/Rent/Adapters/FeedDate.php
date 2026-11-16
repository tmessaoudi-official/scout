<?php

declare(strict_types=1);

namespace Scout\Rent\Adapters;
use Scout\Adapters\FeedFreshness;

/**
 * The ONE place a `Source` is asked for its feed freshness.
 *
 * **It exists because there were two, and only one of them was reachable by a test.** `Pipeline`
 * and `Scout::doctor()` each wrote their own `$source instanceof FeedFreshness ? … : null`, and a
 * sabotage round showed both could be replaced with `null` while the suite stayed green. The
 * pipeline's copy is now pinned by `PipelineRunTest`; the doctor's could not be, because observing
 * it through the CLI needs an `email_alert` source whose mailbox reports a date, and the only
 * offline mailbox is {@see Mail\FileMailbox}, which reports `null` on purpose and must keep doing so.
 *
 * A test that cannot exist is not a reason to leave the code untested — it is a reason for the code
 * not to be duplicated. Collapsing the two sites into one makes the pipeline's coverage cover both,
 * which is a better outcome than two tests would have been.
 */
final class FeedDate
{
    /**
     * When this source's feed last delivered, or `null` when it does not report.
     *
     * `null` is UNKNOWN and never "old" (hard rule 9): `RunStore::health()` declines to judge on it,
     * which is what keeps every html/json source, every frozen-fixture run and the whole
     * pre-schema-v11 run log out of a permanent alert.
     *
     * **Only ever called on a SUCCESSFUL fetch.** On a failure the mailbox never got far enough to
     * see a message, and writing the previous pass's value into a failed run would let that pass's
     * freshness vouch for one that fetched nothing.
     */
    public static function of(Source $source): ?string
    {
        return $source instanceof FeedFreshness ? $source->newestFeedItemAt() : null;
    }
}
