<?php

declare(strict_types=1);

namespace Scout\Car;

use Scout\Core\MalformedText;
use Scout\Core\Text;

/**
 * The developer's car criteria, as ruled (decisions 5–11): ONE hard ceiling (the displayed price),
 * one geography filter that only acts on a STATED location, and score components for everything
 * else. Age and mileage are PEAKS, never disqualifiers — the Q5 precedent that removed `max_floor`
 * from the rent side, and hard rule 8.
 */
final readonly class VehicleCriteria
{
    /**
     * @param list<string>       $postcodePrefixes empty = any location (decision 6: settable to any set)
     * @param list<string>       $bodyFavour       folded; a listed body takes the FULL share, an unlisted one none
     * @param array<string,int>  $weights          price, age, mileage, gearbox, fuel, body — summing to 100
     * @param list<string>       $excludePatterns  extra regexes (folded text); the §1 vehicle set is NOT here
     */
    public function __construct(
        public int $maxPriceEur,
        public array $postcodePrefixes,
        /**
         * Bodies the developer wants, folded — a SET, and the order carries no meaning.
         *
         * **RENAMED FROM `bodyRank` / `body_rank` IN TRACK 7, AND THE RENAME IS THE POINT.** It was
         * the `commune_rank` mechanism: the first entry took the full share, the second two thirds,
         * the third one third. The developer ruled suv, break and berline **equally very high**
         * (2026-09-08), so the position stopped meaning anything — and a key whose name asserts a
         * mechanism that no longer exists is the failure this repo has paid for more than any
         * other. The loader refuses `body_rank` BY NAME and says what to write instead, so a stale
         * `criteria.local.json` gets an instruction rather than the generic unknown-key message.
         *
         * @var list<string>
         */
        public array $bodyFavour,
        public int $peakAgeYears,
        public int $peakMileageKm,
        public array $weights,
        public array $excludePatterns,
        public VehicleNotifyPolicy $notify,
        /**
         * Makes to score DOWN, folded — the developer's 2026-08-31 ruling.
         *
         * A LIST, not a rank, and the name says so. `body_rank` scores its top entry HIGHEST, so
         * mirroring it here would have made the disfavoured brands beat an unlisted one — the
         * opposite of the ruling. No ordering among these was ruled either: they are equal.
         *
         * @var list<string>
         */
        public array $brandAvoid = [],
        /**
         * Makes the developer WANTS, folded — the third arm of Track 7's brand model.
         *
         * Same stem semantics as `brandAvoid` and the same matcher, deliberately: the `ds` /
         * `ds automobiles` miss that forced the non-letter boundary runs in BOTH directions, and a
         * favoured list matched by exact equality would silently miss the same source's spelling
         * while every score stayed plausible.
         *
         * With both lists configured the share is a THREE-WAY: favoured takes it all, avoided takes
         * none, and a make on NEITHER list takes none either — the literal reading of the ruling
         * *"everything else must have lowest score and almost not show up"* (2026-09-08). With
         * both lists EMPTY no preference is configured and every make takes the share, which is
         * what keeps the achievable maximum at 100 for such a deployment.
         *
         * @var list<string>
         */
        public array $brandFavour = [],
    ) {}

    /** Hard rule 9: an UNKNOWN location never rejects; a stated one outside the set does. */
    public function matchesLocation(?string $postcode): bool
    {
        if ($this->postcodePrefixes === [] || $postcode === null || trim($postcode) === '') {
            return true;
        }
        foreach ($this->postcodePrefixes as $prefix) {
            if (str_starts_with($postcode, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Is this make one the developer would rather avoid?
     *
     * `null` — no make extracted — is NOT avoided (hard rule 9: unknown is not disfavoured), which
     * is the same direction every other unknown takes here.
     *
     * AN ENTRY IS A STEM, MATCHED TO A NON-LETTER BOUNDARY — and that is a measured repair, not a
     * generalisation. This was `in_array($folded, $brandAvoid, true)`, exact equality, and the live
     * store carries the SAME marque under two spellings, one per source: autohero emits
     * `ds automobiles`, leboncoin emits `ds`. A config entry `ds` caught one row and silently
     * missed the other — a configured preference inert on a whole source, which is this repo's
     * recurring defect (`exclude_title_patterns` on In'li, the two unread car params, PAP's
     * anchors). Nothing reads as a fault; the car merely ranks 10 points too high.
     *
     * The boundary is a NON-LETTER so `DS 3`, `DS-3` and `DS3` are all the same marque, and so the
     * stem can never reach a longer word that merely begins with it. The counterweight is asserted
     * against every make the live store actually contains — over-reaching here ranks a car BELOW
     * one that deserves less, which is as silent as under-reaching and worse.
     *
     * The residual, stated rather than left to be found: a make written with NO separator at all
     * (`alfaromeo`) is not caught. Under-matching is the safe direction, and no source emits it.
     */
    public function isAvoidedBrand(?string $make): bool
    {
        return self::matchesStem($make, $this->brandAvoid);
    }

    /**
     * Is this make one the developer is actively looking for? (Track 7-C, ruling 2026-09-08.)
     *
     * ONE MATCHER, shared with {@see isAvoidedBrand()} rather than written again. A second copy is
     * exactly how the favoured side would have re-acquired the `ds` / `ds automobiles` defect the
     * avoided side was repaired for — *a fix landing on one of two symmetric surfaces* is this
     * repo's most-repeated defect, and the two surfaces here are one method apart.
     */
    public function isFavouredBrand(?string $make): bool
    {
        return self::matchesStem($make, $this->brandFavour);
    }

    /**
     * Does a make match any stem in a list, to a NON-LETTER boundary?
     *
     * `null` — no make extracted — matches nothing (hard rule 9: unknown is neither wanted nor
     * disfavoured), which is the same direction every other unknown takes here.
     *
     * @param list<string> $stems folded at load
     */
    private static function matchesStem(?string $make, array $stems): bool
    {
        if ($make === null || trim($make) === '') {
            return false;
        }

        try {
            $folded = Text::fold($make);
        } catch (MalformedText) {
            // A make that cannot be folded is a make nobody read. It matches no list — the same
            // answer `null` gets, and the safe one: the alternative is a preference applied to a
            // string nobody could decode.
            return false;
        }

        foreach ($stems as $stem) {
            if ($folded === $stem) {
                return true;
            }

            if (!str_starts_with($folded, $stem)) {
                continue;
            }

            // What ENDS the stem decides. A letter means this is a longer word that merely starts
            // the same way; anything else — space, hyphen, digit — is a boundary and the marque.
            if (!ctype_alpha($folded[\strlen($stem)])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Is this body one of the wanted ones? FLAT — every entry is worth the same.
     *
     * This returned a 1-based RANK until Track 7 and the scorer paid the position out
     * proportionally. suv, break and berline are now equally wanted (developer ruling 2026-09-08),
     * so a rank would be a number nobody uses and a name that lies. `str_contains` is kept
     * deliberately: `4x4 - suv` is how one source writes it, and 60 stored cars depend on that
     * being read as a suv.
     */
    public function isFavouredBody(?string $body): bool
    {
        if ($body === null) {
            return false;
        }
        try {
            $key = Text::fold($body);
        } catch (MalformedText) {
            return false;
        }
        foreach ($this->bodyFavour as $wanted) {
            if ($key === $wanted || str_contains($key, $wanted)) {
                return true;
            }
        }

        return false;
    }

    /** The first extra exclusion pattern matching the listing's folded text, or null. */
    public function excludedBy(string $text): ?string
    {
        try {
            $folded = Text::fold($text);
        } catch (MalformedText) {
            return null;
        }
        foreach ($this->excludePatterns as $pattern) {
            if (@preg_match('~' . $pattern . '~u', $folded) === 1) {
                return $pattern;
            }
        }

        return null;
    }
}
