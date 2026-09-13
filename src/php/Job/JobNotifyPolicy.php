<?php

declare(strict_types=1);

namespace Scout\Job;

/**
 * The job domain's notification routing — the car policy without what this domain does not have: no
 * price drops, and no `high_priority_score`, because no score bar has been calibrated to earn a `!!`.
 */
final readonly class JobNotifyPolicy
{
    /** @param list<string> $channels */
    public function __construct(
        public array $channels,
        public int $sourceAlertCooldownHours = 12,
        /**
         * A MATCH scoring below this is not pushed on its own: it stays queued, and the daily ROLLUP
         * drains it (`scout --domain=job rollup`, and the floor at `rollupHour` under `--watch`).
         * `null` pushes every match, which is what ships until real rows calibrate a gate.
         */
        public ?int $pushMinScore = null,
        /** The hour (local zone) of the daily rollup floor under `--watch`; `null` means no floor, the verb only. */
        public ?int $rollupHour = null,
    ) {}
}
