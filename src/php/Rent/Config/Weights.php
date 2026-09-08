<?php

declare(strict_types=1);

namespace Scout\Rent\Config;
use Scout\Config\Reader;

/**
 * The score components of `spec/PROJECT_BRIEF.md` §5, with the weights ruled on 2026-08-07.
 *
 * **Weights are normalised, not summed raw.** A component that is disabled (commute, weight 0 until
 * an IDFM key exists — `docs/OPEN-QUESTIONS.md` Q1) would otherwise depress every score by its share
 * for the whole life of the project, so a perfect flat would top out at 75/100 and the notification
 * threshold would quietly mean something different from what it says. {@see positiveTotal()} is what
 * the criteria engine divides by.
 *
 * `highFloorNoLift` is NEGATIVE and is deliberately excluded from that total: a penalty is not part
 * of what a perfect listing can earn, it is subtracted from what it earned. Including it in the
 * denominator would make the penalty smaller the larger it was set, which is the opposite of the
 * intent.
 *
 * `heatingIndividual` and `heatingIndividualElectric` (Track 7-A, developer ruling 2026-09-08) are
 * the second and third weights of that kind and follow the precedent exactly — bounded `-1000..0`,
 * absent from {@see positiveTotal()}. They STACK: an electric individual system takes both, so the
 * three configured classes are electric −35, gas −20 and mode-stated-without-energy −20.
 *
 * **THE SEVERITY IS A NUMBER, NOT AN ADJECTIVE, AND IT WAS MEASURED.** `positiveTotal()` is 105 in
 * production, so −20 is 19 points on the 0–100 scale. Re-judging all 1 261 stored MATCH snapshots
 * at production weights: −20 takes **every** individually-heated flat below `push_min_score: 55`,
 * and −30 and −40 buy nothing at that gate — they only reorder rows already under it. So −20 is
 * the whole effect and a bigger number would be theatre.
 */
final readonly class Weights
{
    public function __construct(
        public int $commune = 25,
        public int $commute = 0,
        public int $rentHeadroom = 15,
        public int $surface = 10,
        public int $lift = 15,
        public int $highFloorNoLift = -20,
        public int $freshness = 10,
        public int $heatingIndividual = -20,
        public int $heatingIndividualElectric = -15,
    ) {}

    /**
     * Sum of the components a listing can EARN. Never zero — see the guard.
     *
     * A config that zeroed every positive weight would make the score a division by zero, and the
     * honest reading of it is "the developer disabled scoring", not "every listing scores 0". The
     * criteria engine treats a zero total as "no scoring configured" and scores every match 100,
     * because ordering by an all-zero score is meaningless and a silent 0 would look like a bad
     * listing rather than an unconfigured one.
     */
    public function positiveTotal(): int
    {
        // ENUMERATED, NOT DERIVED, and the three penalties are absent on purpose — `highFloorNoLift`,
        // `heatingIndividual` and `heatingIndividualElectric`. Summing the properties reflectively
        // and clamping each at 0 would look equivalent and would silently enrol the next penalty
        // somebody adds, at which point setting it larger would make it weaker. If you add a
        // component here, add it because it is EARNABLE.
        return max(0, $this->commune)
            + max(0, $this->commute)
            + max(0, $this->rentHeadroom)
            + max(0, $this->surface)
            + max(0, $this->lift)
            + max(0, $this->freshness);
    }

    public static function fromReader(Reader $r): self
    {
        $w = new self(
            commune: $r->optInt('commune', 25, 0, 1000) ?? 0,
            commute: $r->optInt('commute', 0, 0, 1000) ?? 0,
            rentHeadroom: $r->optInt('rent_headroom', 15, 0, 1000) ?? 0,
            surface: $r->optInt('surface', 10, 0, 1000) ?? 0,
            lift: $r->optInt('lift', 15, 0, 1000) ?? 0,
            // The one weight allowed to be negative, and required to be: a positive "high floor, no
            // lift" weight would be a bonus for the exact thing the developer is escaping.
            highFloorNoLift: $r->optInt('high_floor_no_lift', -20, -1000, 0) ?? 0,
            freshness: $r->optInt('freshness', 10, 0, 1000) ?? 0,
            // Negative for the same reason and by the same bound: a POSITIVE heating penalty would
            // be a bonus for the exact thing the developer asked to be penalised severely.
            heatingIndividual: $r->optInt('heating_individual', -20, -1000, 0) ?? 0,
            // A SURCHARGE that stacks on the line above, never a replacement for it — so setting
            // this alone still leaves an electric flat carrying the base penalty, and zeroing the
            // base while keeping this one is a configuration that penalises electric heating only.
            heatingIndividualElectric: $r->optInt('heating_individual_electric', -15, -1000, 0) ?? 0,
        );
        $r->done();

        return $w;
    }
}
