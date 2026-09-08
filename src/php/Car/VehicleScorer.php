<?php

declare(strict_types=1);

namespace Scout\Car;

/**
 * Hard disqualifiers and score are two different mechanisms (hard rule 8), and here the hard set
 * is deliberately tiny: the §1 vehicle classifier, the price CEILING, a STATED location outside the
 * set, and the extra `exclude_patterns`. Everything else — age, mileage, gearbox, fuel, body — is
 * a clamped score component that can never reject (decisions 7 and 11).
 *
 * An unknown component scores 0 and SAYS so (`kilométrage inconnu — hors score`): a car that
 * states nothing is never rewarded for it, and on a phone a missing line would read as a good
 * value. Scores are out of the FULL 100, so a sparse listing ranks below a documented one.
 *
 * The diesel penalty is a plain preference. Whether a given diesel may enter the Grand Paris ZFE
 * is `[Unverified]` here and revised often; the reason line never claims a regulatory fact.
 */
final class VehicleScorer
{
    public const array COMPONENTS = ['price', 'age', 'mileage', 'gearbox', 'fuel', 'body', 'brand'];

    public function judge(VehicleListing $car, VehicleClassification $class, VehicleCriteria $criteria, int $year, int $month): VehicleVerdict
    {
        if ($class->outcome === VehicleOutcome::REJECT) {
            return VehicleVerdict::rejected(array_map(static fn (string $r): string => 'exclu : ' . $r, $class->reasons));
        }
        if ($car->priceEur !== null && $car->priceEur > $criteria->maxPriceEur) {
            return VehicleVerdict::rejected([sprintf('prix %s € au-dessus du plafond de %s €', self::n($car->priceEur), self::n($criteria->maxPriceEur))]);
        }
        if (!$criteria->matchesLocation($car->postcode)) {
            return VehicleVerdict::rejected(['code postal ' . $car->postcode . ' hors de la zone']);
        }
        $pattern = $criteria->excludedBy($car->text());
        if ($pattern !== null) {
            return VehicleVerdict::rejected(['motif exclu : ' . $pattern]);
        }

        $w = $criteria->weights;
        $score = 0.0;
        $reasons = [];

        // price — linear from the ceiling (0) down to 0 € (full marks)
        if ($car->priceEur === null) {
            $reasons[] = 'prix inconnu — hors score';
        } else {
            $share = max(0.0, 1 - $car->priceEur / $criteria->maxPriceEur);
            $score += $w['price'] * $share;
            $reasons[] = sprintf('%s € — %d %% sous le plafond', self::n($car->priceEur), (int) round($share * 100));
        }

        // age — full marks up to the peak, decaying to 0 at twice it
        $age = $car->ageYears($year, $month);
        if ($age === null) {
            $reasons[] = 'année inconnue — hors score';
        } else {
            $share = self::decay($age, $criteria->peakAgeYears);
            $score += $w['age'] * $share;
            $reasons[] = sprintf('%d · %s an(s) — %s', $car->year, rtrim(rtrim(number_format($age, 1, ',', ' '), '0'), ','), $share >= 1 ? 'dans la fenêtre idéale (≤ ' . $criteria->peakAgeYears . ' ans)' : 'au-delà de ' . $criteria->peakAgeYears . ' ans');
        }

        // mileage — same shape
        if ($car->mileageKm === null) {
            $reasons[] = 'kilométrage inconnu — hors score';
        } else {
            $share = self::decay((float) $car->mileageKm, (float) $criteria->peakMileageKm);
            $score += $w['mileage'] * $share;
            $reasons[] = sprintf('%s km — %s', self::n($car->mileageKm), $share >= 1 ? 'dans la fenêtre (≤ ' . self::n($criteria->peakMileageKm) . ')' : 'au-delà de ' . self::n($criteria->peakMileageKm) . ' km');
        }

        // gearbox — automatic preferred (decision 11)
        if ($car->gearbox === null) {
            $reasons[] = 'boîte inconnue — hors score';
        } elseif ($car->gearbox === 'automatique') {
            $score += $w['gearbox'];
            $reasons[] = 'boîte automatique';
        } else {
            $reasons[] = 'boîte ' . $car->gearbox;
        }

        // fuel — petrol / hybrid / electric preferred over diesel; a PREFERENCE, never a ZFE claim
        if ($car->fuel === null) {
            $reasons[] = 'énergie inconnue — hors score';
        } else {
            $share = match ($car->fuel) {
                'essence', 'hybride', 'electrique' => 1.0,
                'gpl' => 0.5,
                default => 0.0,
            };
            $score += $w['fuel'] * $share;
            $reasons[] = $car->fuel . ($car->fuel === 'diesel' ? ' — préférence, pas une règle ZFE' : '');
        }

        // BODY — FLAT SINCE TRACK 7, and the flatness is the ruling rather than a simplification.
        // This was the `commune_rank` mechanism: the list's first entry took the whole share, the
        // second two thirds, the third one third. The developer ruled suv, break and berline
        // **equally very high** (2026-09-08), which leaves the position meaning nothing — so the
        // list is a SET, the key is `body_favour`, and a listed body takes the full share.
        //
        // An unlisted body still scores 0 and is still NOTIFIED: this is a preference and never a
        // disqualifier (hard rule 8). `citadine` (133 stored cars) and `monospace` (59) scored 0
        // under the ranked model too, so nothing about them changed.
        if ($car->body === null) {
            $reasons[] = 'carrosserie inconnue — hors score';
        } elseif ($criteria->isFavouredBody($car->body)) {
            $score += $w['body'];
            $reasons[] = $car->body . ' — carrosserie recherchée';
        } else {
            $reasons[] = $car->body . ' — carrosserie hors préférences';
        }

        // BRAND — A THREE-WAY SINCE TRACK 7, replacing the inverted binary (developer ruling,
        // 2026-09-08: *"for brands I want it also high score … everything else must have lowest
        // score and almost not show up"*). Favoured takes the whole share; avoided takes none; a
        // make on NEITHER list takes none either, which is the literal reading of *everything
        // else*. 47 stored makes are in that third class — mini 13, suzuki 11, mg 7, lexus 6,
        // land rover 6 … — and at gate 73 not one of them is pushed individually under the ruled
        // weights; they all reach the developer in the daily rollup instead. Giving them HALF the
        // share was measured (split C) and pushed 16 of the 47, which is what the ruling excludes.
        //
        // The previous shape is still visible in the `brandAvoid === []` arm and its comment: the
        // share used to be earned by NOT being on the avoid list. That inversion was itself the
        // 2026-08-31 ruling, because mirroring the body list would have scored the disfavoured
        // makes HIGHEST.
        //
        // The weight comes OUT of the existing 100 rather than pushing past it, so the total still
        // means what `high_priority_score` was calibrated against — and 73 was RE-MEASURED under
        // the new weights rather than carried over, because an absolute threshold silently changes
        // meaning when the scale beneath it moves.
        //
        // AVOID IS CHECKED BEFORE FAVOUR. The loader refuses a stem appearing on both lists, so an
        // exact clash cannot reach here; overlapping STEMS still can, and the order settles those
        // in the conservative direction — a preference against outranks a preference for.
        if ($criteria->brandAvoid === [] && $criteria->brandFavour === []) {
            // NEITHER list configured means no preference at all, so EVERY make earns the share. It
            // reads as a wash for ordering either way, but withholding it would quietly drop the
            // achievable maximum to 90 for such a deployment — and `high_priority_score` is an
            // ABSOLUTE threshold, so a scale that silently shrinks makes it unreachable. Same
            // reasoning as the unknown-make arm below.
            //
            // The condition tests BOTH lists since Track 7: keyed on `brandAvoid` alone, a
            // deployment configuring only `brand_favour` would have taken this arm and awarded the
            // share to every make, silently disabling the preference it had just written down.
            $score += $w['brand']; // unique on purpose: the ledger addresses this arm by this line
            $reasons[] = 'marque — aucune préférence configurée';
        } elseif ($car->make === null) {
            // UNKNOWN SCORES 0 AND SAYS SO — the same arm every other component here takes, and a
            // DELIBERATE deviation from the plan's line ("a car with no extracted make gets the
            // full share"). Hard rule 9 forbids treating unknown as BELOW A MINIMUM — a
            // disqualifier — and nothing here disqualifies; hard rule 8 keeps the two mechanisms
            // apart. Awarding the share instead would rank an EXTRACTION FAILURE as a definitely-
            // not-Peugeot, which is this repo's recurring defect: a fact manufactured from its own
            // absence, wearing an alibi.
            //
            // THIS ARM IS REACHED ON A REAL SOURCE since Track 6-A4, and the comment that used to
            // stand here ("both shipped car sources do extract a make") is why the finding was
            // mis-scoped when it was raised. ParuVendu writes `/autres/autres/` when it cannot name
            // the marque; that token is now nulled at the adapter, so such a card lands here rather
            // than earning the share as a definitely-not-DS. Audit finding N3 assumed the opposite
            // of this arm — that a null make keeps the full share "by hard rule 9" — and preferred
            // a title fallback on that basis. It does not, so it did not need one.
            //
            // Reversed by adding `$score += $w['brand'];` to this arm.
            $reasons[] = 'marque inconnue — hors score';
        } elseif ($criteria->isAvoidedBrand($car->make)) {
            // TRIMMED for display, because the comparison is: `isAvoidedBrand()` folds, and folding
            // trims. A `make_model_pattern` capture carrying a trailing space would otherwise be
            // penalised correctly and announced with the whitespace still in it.
            $reasons[] = trim($car->make) . ' — marque à éviter';
        } elseif ($criteria->isFavouredBrand($car->make)) {
            $score += $w['brand'];
            $reasons[] = trim($car->make) . ' — marque recherchée';
        } else {
            // THE THIRD CLASS, AND IT IS NOT THE OLD `else`. Before Track 7 this arm awarded the
            // whole share to anything not on the avoid list; it now awards none, and the reason
            // line says which list the make is missing from rather than implying a judgement
            // nobody made. It is still a preference — the car is notified, and reaches the daily
            // rollup — never a disqualifier (hard rule 8).
            $reasons[] = trim($car->make) . ' — marque hors des listes';
        }

        if ($car->sellerType !== null) {
            $reasons[] = $car->sellerType === 'professional' ? 'vendeur professionnel' : 'vendeur particulier';
        }

        $total = (int) max(0, min(100, round($score)));

        return VehicleVerdict::matched($total, $reasons, $total >= $criteria->notify->highPriorityScore);
    }

    /** 1.0 up to the peak, linear to 0.0 at twice the peak, never negative. */
    private static function decay(float $value, float $peak): float
    {
        if ($value <= $peak) {
            return 1.0;
        }

        return max(0.0, 1 - ($value - $peak) / $peak);
    }

    private static function n(int $v): string
    {
        return number_format($v, 0, ',', ' ');
    }
}
