<?php

declare(strict_types=1);

namespace Scout\Rent\Core;

use Scout\Rent\Config\Criteria;

/**
 * Applies the hard disqualifiers, then scores what survives.
 *
 * ORDER MATTERS AND IT IS FIXED HERE, in one place, so no caller can re-derive it slightly
 * differently:
 *
 * 1. **The classifier's outcome first.** `REJECT` and `DIGEST` are decided by
 *    {@see TenureClassifier} and are not re-litigated — that is what makes `CLAUDE.md` §1's
 *    fail-closed rule apply in exactly one place. A criteria engine that could promote a `DIGEST`
 *    to a match would be a second, weaker copy of the rule.
 * 2. **Then the hard disqualifiers**, which reject silently and are logged only (hard rule 8).
 * 3. **Then the score**, which orders what is left and decides notification priority.
 *
 * THE THREE TRAPS THIS FILE IS WRITTEN AGAINST, all of them silent:
 *
 * - **`null` is not zero** (hard rule 9). Every unknown measurement passes the filter it cannot be
 *   judged by, and loses the score component instead. The prototype's `(l.rooms or 0) < min_rooms`
 *   rejects every listing that did not state a room count, and nothing arrives to say so.
 * - **A disqualifier applied before enrichment** rejects on a field enrichment would have filled.
 *   Enrichment does not exist yet; when it does, it runs before this.
 * - **Confidence must not multiply the score** (ruled 2026-08-07, Q31). It did, and the arithmetic
 *   made high priority unreachable for 16 of 31 expected matches and for the whole LIBRE track.
 */
final readonly class CriteriaEngine
{
    /**
     * Below this confidence a match is capped at normal priority however well it scores.
     *
     * This is what S8 was actually asking for — *"a 0.65 LLI and a 0.98 LLI should not rank
     * equally"* — expressed as a routing gate rather than as a multiplier on the score. As a
     * multiplier it made the priority threshold arithmetically unreachable: realised confidences are
     * about {50, 80, 90, 97}, so a listing at 50 needed a normalised 140 to clear 70. Worse, it
     * inverted the ordering of anything scoring below zero, because multiplying a negative by a
     * fraction moves it toward zero and the LESS trustworthy verdict won.
     */
    public const int HIGH_PRIORITY_MIN_CONFIDENCE_BP = 80;

    public function __construct(private readonly Criteria $criteria) {}

    /**
     * @param int|null $firstSeenAgeSeconds how long ago this listing was first seen, or `null` if
     *                                      it is new to the store right now
     */
    /**
     * € CC per m², or `null` when the question cannot be asked of this listing.
     *
     * Reads `effectiveRentCc()` — the same figure `max_rent_cc` uses — so it is INERT on the ~157
     * HC-only rows (Logirep, leboncoin, PAP), which is correct rather than a gap: a ratio built on
     * a rent that excludes charges is not comparable with one that includes them.
     */
    private function pricePerM2(RawListing $listing): ?float
    {
        $rent = $listing->effectiveRentCc();
        $surface = $listing->surfaceM2;

        if ($rent === null || $surface === null || $surface <= 0.0 || ($listing->rooms ?? 0) <= 0) {
            return null;
        }

        return (float) $rent / $surface;
    }

    public function judge(RawListing $listing, Classification $classification, ?int $firstSeenAgeSeconds = null): Verdict
    {
        if ($classification->outcome === Outcome::REJECT) {
            return Verdict::rejected('tenure: ' . $classification->tenure->value);
        }

        $disqualifier = $this->disqualify($listing);
        if ($disqualifier !== null) {
            return Verdict::rejected($disqualifier);
        }

        // AFTER the disqualifiers, deliberately. A listing that is the wrong size in the wrong
        // commune should be rejected on that, not filed in a digest the developer has to read.
        if ($classification->outcome === Outcome::DIGEST) {
            $reasons = $classification->reasons();

            if ($reasons === []) {
                // The commonest digest case produces NO signals at all — nothing fired, which is
                // exactly why it withheld. An entry with an empty `reasons[]` is a bare link, and a
                // digest of bare links is one the developer stops opening, which quietly costs the
                // fail-closed rule its only landing zone. Say the silence out loud instead.
                $reasons = ["régime du logement indéterminé : aucun signal dans l'annonce, "
                    . 'et cette source publie aussi du logement social'];
            }

            return Verdict::digest($reasons, DigestCause::TENURE_UNDETERMINED);
        }

        // PRICE-PER-m² PLAUSIBILITY — Track 1f, and it is a SECOND route into the same landing zone
        // rather than a second tenure decision. Said out loud because this class's own contract is
        // that the tenure classifier owns REJECT and DIGEST: it still owns them *for tenure*. This
        // asks a different question — is the listing describing the dwelling it prices? — and the
        // digest is simply the only place a doubt can be put.
        //
        // WHAT IT CATCHES, measured over the 1 392 stored listings that survive every other
        // exclusion: a room in a shared flat advertised with the WHOLE flat's surface, and a surface
        // that was read off the wrong thing entirely (`Appartement dans maison avec plus de 400m2
        // jardin` priced the GARDEN). Both pass every numeric filter, because each number is
        // individually plausible and only their RATIO is not.
        //
        // NEVER A REJECTION: the discriminating sentence usually lives on the detail page, which
        // this source structurally never reads (following SeLoger's per-recipient redirect is a
        // hard-rule-5 refusal), so the tool is guessing from a ratio. A guess belongs in the bin the
        // developer reads, not in the one that is silent.
        //
        // The GUARD IS `> 0`, NOT `!== null`: 15 stored Logirep rows carry `surface = 0, rooms = 0`
        // for parkings, and `rent / 0.0` is a `DivisionByZeroError` that would take down the pass.
        $ppm = $this->pricePerM2($listing);

        if ($ppm !== null && $this->criteria->minPricePerM2 !== null && $ppm < $this->criteria->minPricePerM2) {
            return Verdict::digest([
                sprintf(
                    'loyer implausible pour la surface annoncée : %.2f €/m² CC (%d € pour %s m²), '
                    . 'sous le plancher de %.2f €/m² — typiquement une chambre en colocation '
                    . 'annoncée avec la surface de tout le logement, ou une surface lue sur autre chose',
                    $ppm,
                    (int) $listing->effectiveRentCc(),
                    rtrim(rtrim(number_format((float) $listing->surfaceM2, 1, ',', ' '), '0'), ','),
                    $this->criteria->minPricePerM2,
                ),
                // NOT the §1 landing zone: this listing's tenure is determined — typically `LLI` at
                // full confidence — and only its rent-to-surface ratio is in doubt. Announcing it
                // under the regime clause would assert something the classifier already settled.
            ], DigestCause::OTHER);
        }

        return $this->score($listing, $classification, $firstSeenAgeSeconds);
    }

    /**
     * The first hard rule this listing fails, or `null`.
     *
     * Returns the FIRST rather than all of them: this feeds a log line, and "rejected: commune" is
     * what a human needs. Location is checked first because it is the cheapest and the commonest
     * rejection.
     */
    private function disqualify(RawListing $listing): ?string
    {
        // --- location (F2/F3, and the Q32 ruling on what an unknown one does) ---
        if (!$listing->hasLocationEvidence()) {
            return 'no commune and no postcode — the listing carries no location evidence at all';
        }

        if (!$this->matchesLocation($listing)) {
            return 'commune: ' . ($listing->commune ?? '(absent)') . ' / ' . ($listing->postcode ?? '(absent)');
        }

        // --- property kind (F9) ---
        $pattern = $this->criteria->excludedBy($listing->title, $listing->description);
        if ($pattern !== null) {
            return 'excluded kind of property (pattern: ' . $pattern . ')';
        }

        // --- size (F4/F5) — an UNKNOWN measurement never disqualifies ---
        if ($this->criteria->minRooms !== null && $listing->rooms !== null && $listing->rooms < $this->criteria->minRooms) {
            return 'rooms: ' . $listing->rooms . ' < ' . $this->criteria->minRooms;
        }

        if ($this->criteria->minSurfaceM2 !== null && $listing->surfaceM2 !== null && $listing->surfaceM2 < $this->criteria->minSurfaceM2) {
            return 'surface: ' . $listing->surfaceM2 . ' m² < ' . $this->criteria->minSurfaceM2 . ' m²';
        }

        // --- rent (F6), charges comprises, and never on an HC-only figure ---
        $rentCc = $listing->effectiveRentCc();
        if ($this->criteria->maxRentCc !== null && $rentCc !== null && $rentCc > $this->criteria->maxRentCc) {
            return 'rent: ' . $rentCc . ' € CC > ' . $this->criteria->maxRentCc . ' € CC';
        }

        return null;
    }

    /**
     * Location, under the Q32 ruling: judged on whichever of the two fields is present.
     *
     * With a commune, {@see Criteria::matchesCommune()} applies and the postcode narrows it. With
     * only a postcode — an ordinary shape when a selector catches the address but not the city —
     * the prefix alone decides, which is looser than the commune list but is the right kind of
     * loose: it admits a few neighbouring communes rather than dropping a real listing silently.
     */
    private function matchesLocation(RawListing $listing): bool
    {
        if ($listing->commune !== null) {
            return $this->criteria->matchesCommune($listing->commune, $listing->postcode);
        }

        if ($this->criteria->postcodePrefixes === []) {
            return true;
        }

        $digits = preg_replace('~\D+~', '', (string) $listing->postcode) ?? '';
        if ($digits === '') {
            return false;
        }

        foreach ($this->criteria->postcodePrefixes as $prefix) {
            if (str_starts_with($digits, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function score(RawListing $listing, Classification $classification, ?int $firstSeenAgeSeconds): Verdict
    {
        $w = $this->criteria->weights;
        $total = $w->positiveTotal();
        $earned = 0;
        $reasons = [];

        // --- S1 commune preference ---
        $rank = $this->criteria->rankOf($listing->commune);
        if ($w->commune > 0) {
            $worst = $this->criteria->worstRank();
            // An UNRANKED commune scores as the bottom of the ranked list rather than as zero: it is
            // in `communes`, so it is wanted. Zero would punish a commune for the config author not
            // having got round to ranking it.
            $effective = $rank ?? $worst;
            $share = $worst <= 1 ? 1.0 : (($worst - $effective) / ($worst - 1));
            $earned += (int) round($w->commune * $share);

            if ($listing->commune !== null) {
                $reasons[] = $rank === 1
                    ? $listing->commune . ' — commune de premier choix'
                    : $listing->commune;
            }
        }

        // --- S3 rent headroom ---
        $rentCc = $listing->effectiveRentCc();
        if ($w->rentHeadroom > 0) {
            if ($rentCc !== null && $this->criteria->maxRentCc !== null && $this->criteria->maxRentCc > 0) {
                $headroom = max(0, $this->criteria->maxRentCc - $rentCc);
                $earned += (int) round($w->rentHeadroom * min(1.0, $headroom / $this->criteria->maxRentCc));
                $reasons[] = $rentCc . ' € CC — ' . $headroom . ' € sous le plafond';
            } elseif ($listing->rentHc !== null) {
                // Named explicitly, because this is the case where the ceiling could not be applied
                // at all. A 1750 € HC flat is roughly 1900 € CC; the developer must see that the
                // figure shown is not comparable to the budget.
                $reasons[] = $listing->rentHc . ' € HORS CHARGES — total réel inconnu, plafond non vérifiable';
            } else {
                $reasons[] = 'loyer non communiqué';
            }
        }

        // --- S4 surface above the minimum ---
        if ($w->surface > 0) {
            if ($listing->surfaceM2 !== null) {
                $min = $this->criteria->minSurfaceM2 ?? 0.0;
                // Saturates at +50% of the minimum. Without a cap a 300 m² outlier would take the
                // full weight and flatten every real difference between 80 and 95 m².
                $over = $min > 0.0 ? min(1.0, max(0.0, ($listing->surfaceM2 - $min) / ($min * 0.5))) : 0.0;
                $earned += (int) round($w->surface * $over);
                $reasons[] = rtrim(rtrim(number_format($listing->surfaceM2, 1, ',', ' '), '0'), ',') . ' m²';
            } else {
                $reasons[] = 'surface non communiquée';
            }
        }

        if ($listing->rooms !== null) {
            $reasons[] = $listing->rooms . ' pièces';
        } else {
            $reasons[] = 'nombre de pièces non communiqué';
        }

        // --- S5 / S6 floor and lift ---
        // TWO components, not one signed component, and that is the `null`-is-not-`false` rule
        // (hard rule 9) made structural: the BONUS needs a low floor or a declared lift, and the
        // PENALTY needs the lift to be EXPLICITLY absent. A listing that simply does not mention a
        // lift gets neither — which is right, because most listings do not mention one, and
        // penalising silence is how the prototype dropped good flats.
        $lowFloor = $listing->floor !== null && $listing->floor <= 1;
        if ($w->lift > 0 && ($lowFloor || $listing->hasElevator === true)) {
            $earned += $w->lift;
            $reasons[] = $listing->hasElevator === true
                ? 'ascenseur'
                : ($listing->floor === 0 ? 'rez-de-chaussée' : ($listing->floor . 'er étage'));
        }

        if (!$lowFloor && $listing->hasElevator === false && $listing->floor !== null) {
            $earned += $w->highFloorNoLift;
            $reasons[] = $listing->floor . 'e étage SANS ascenseur';
        }

        // --- S8 individual heating (Track 7-A, developer ruling 2026-09-08) ---
        // A PENALTY, not a disqualifier (hard rule 8): an individually-heated flat is still a match,
        // still notified, and simply ranks below one that is not. It reads the DESCRIPTION, which is
        // the surface `exclude_patterns` already scans — never a new field-map entry, because
        // `FieldMap::fingerprint()` hashes every mapped field list and one more entry would
        // invalidate all 737 cached In'li detail rows.
        //
        // SILENCE TAKES NOTHING (hard rule 9). Five of the eight sources carry no listing prose at
        // all, so their flats never take this — the penalty ranks In'li flats below portal flats for
        // a fact the portals never state, and that asymmetry is the STATED COST rather than a defect
        // to repair later. The alternative — reading an unstated mode as individual — manufactures a
        // fact from its own absence, which is the shape this repo keeps paying for.
        //
        // The two weights STACK on purpose: electric is the mode penalty PLUS the energy surcharge.
        $heating = Heating::read($listing->description);
        if ($heating !== null && $heating->isIndividual()) {
            $earned += $w->heatingIndividual;
            if ($heating->electric) {
                // An UNSTATED energy never reaches here (developer ruling): the base penalty alone
                // covers the commonest shape in the store — 101 rows saying `chauffage individuel`
                // and no energy — because an unstated energy is not electricity.
                $earned += $w->heatingIndividualElectric;
            }
            $reasons[] = $heating->label() . ' — énergie à votre charge';
        }

        // --- S7 freshness ---
        if ($w->freshness > 0) {
            $window = $this->criteria->freshnessMinutes * 60;
            if ($firstSeenAgeSeconds === null || $firstSeenAgeSeconds <= $window) {
                $earned += $w->freshness;
                $reasons[] = 'publiée à l\'instant';
            }
        }

        // --- S8 commute ---
        //
        // The largest single component (30, ahead of commune's 25), and it exists because nothing
        // else discriminated: 83 live matches spread over eight departements scored 16–48, so
        // `high_priority_score: 70` could never fire.
        //
        // **IT IS A SCORE COMPONENT AND NEVER A DISQUALIFIER** — hard rule 8, and the developer's
        // own ruling of 2026-08-26: *"1 hour 15 max ! but keep showing even those with more anyway"*.
        if ($w->commute > 0 && $this->criteria->commuteEnabled) {
            $minutes = $listing->commuteMinutes;
            $ceiling = $this->criteria->commuteMaxMinutes;

            if ($minutes === null || $ceiling === null) {
                // UNKNOWN IS NOT FAR (hard rule 9). The component goes unscored — it cannot be
                // earned on evidence nobody has — and the reasons SAY so, because on a phone a
                // missing line is indistinguishable from a short commute. Same shape as the
                // `rentHc` line that reports an unverifiable ceiling.
                $reasons[] = 'trajet inconnu — hors score';
            } else {
                // `max_minutes` IS FULL MARKS, NOT ZERO — and that reading was chosen by
                // measurement after the obvious one was built and found to be counterproductive.
                //
                // Treating the ceiling as the zero point of a scale starting at 0 minutes assumes
                // commutes shorter than it are common. They are not: measured against the live API
                // on 2026-08-26, the affordable communes run **68 to 131 minutes** to the configured
                // destination (Sartrouville 68, Aulnay 88, Dammarie-les-Lys 112, Dourdan 131). Under
                // that curve Sartrouville earned 3 points of 30 and everything else earned zero, so
                // the component separated the whole set by THREE points while adding 30 to
                // `positiveTotal()` — every score in the tree dropped by about a quarter and the
                // ordering barely moved. It made the problem it was built for worse.
                //
                // So: at or under the ceiling is full marks (the plain meaning of "1h15 max"),
                // decaying linearly to zero at twice it. On the same live data that spreads the set
                // across 21 points instead of 3. The ties this creates below the ceiling are
                // theoretical — nothing in the affordable set is under 68 minutes.
                //
                // Still clamped at both ends, so the component can never go negative and can never
                // act as a back-door rejection.
                $share = $ceiling <= 0
                    ? 0.0
                    : max(0.0, min(1.0, (2 * $ceiling - $minutes) / $ceiling));
                $earned += (int) round($w->commute * $share);
                $reasons[] = $minutes . ' min de trajet';
            }
        }

        // Clamped, and the clamp is not cosmetic: `highFloorNoLift` is negative and is deliberately
        // excluded from `$total`, so `$earned` can go below zero. A negative score would sort below
        // a rejected listing and, before the Q31 ruling removed the confidence multiplier, would
        // have ranked a low-confidence verdict ABOVE a high-confidence one.
        $normalised = $total > 0
            ? (int) round(max(0, min($total, $earned)) * 100 / $total)
            // No positive weight configured at all. Scoring is off, not "everything scores zero" —
            // a zero would read as a bad listing rather than an unconfigured one.
            : 100;

        // A CHECK THAT COULD NOT BE MADE IS REPORTED AS ONE, never passed over in silence — the
        // same discipline as the `HORS CHARGES … plafond non vérifiable` line above, and the same
        // discipline as hard rule 9 refusing to read an unknown as a zero, one layer up.
        //
        // On a source declaring `prose_absent` the two exclusion lists are structurally inert:
        // `exclude_patterns` scans title and description and `exclude_title_patterns` scans the
        // title, and there is no listing text in either. PAP is the case — a colocation advertised
        // with the whole flat's room count and surface clears every numeric filter, and nothing in
        // the payload can catch it. Neither route out exists: the detail page is behind a bot
        // challenge (hard rule 5) and rent-per-room has no gap to threshold on.
        //
        // It adjusts no score and rejects nothing (hard rule 8) — it tells the reader which pushes
        // they have to open. Placed here rather than in `disqualify()` deliberately: this is not a
        // judgement, it is the boundary of one.
        if ($listing->proseAbsent) {
            $reasons[] = 'annonce sans texte — colocation/meublé non vérifiables';
        }

        array_unshift($reasons, ...$classification->reasons());

        $highPriority = $normalised >= $this->criteria->notify->highPriorityScore
            && $classification->confidenceBp >= self::HIGH_PRIORITY_MIN_CONFIDENCE_BP;

        if ($normalised >= $this->criteria->notify->highPriorityScore
            && $classification->confidenceBp < self::HIGH_PRIORITY_MIN_CONFIDENCE_BP) {
            $reasons[] = 'priorité normale malgré le score : confiance '
                . $classification->confidenceBp . '/100 sur le régime du logement';
        }

        return Verdict::matched($normalised, array_values($reasons), $highPriority);
    }
}
