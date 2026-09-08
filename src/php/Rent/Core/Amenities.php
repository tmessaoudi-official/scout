<?php

declare(strict_types=1);

namespace Scout\Rent\Core;

use Scout\Core\MalformedText;
use Scout\Core\Text;

/**
 * Terrace, balcony, loggia, garden, cellar and the parking family — read out of the listing's own
 * prose for the notification's context line.
 *
 * **A DISPLAY READER. IT REJECTS NOTHING AND SCORES NOTHING** (hard rule 8), exactly like the
 * departement / floor / lift line it joins. A score bonus was offered with its distortion priced —
 * only 15 % of matched flats mention any amenity, so scoring it would rank prose-carrying sources
 * above card-only ones for a fact the portals never state — and the developer declined it.
 *
 * **AN ABSENT LINE MEANS THE AD SAID NOTHING, NEVER THAT THE FLAT LACKS A TERRACE** (hard rule 9 at
 * the display layer, the rule that already governs `floor === 0` being RDC and an unmentioned lift
 * being `null` rather than `false`). Five of the eight rent sources carry no listing prose at all,
 * so on those the line is always empty and that is correct.
 *
 * Reach, measured over all 1 261 stored MATCH snapshots before this was written: 185 of them,
 * **15 %**. balcon 72 · parking 109 · stationnement 30 · terrasse 24 · jardin 17 · box 7 ·
 * garage 5 · cave 3 · loggia 3 · **cellier 0**. (`emplacement` mentions 17 — a MENTION count, and
 * it is no longer a family member: see {@see PARKING}.)
 *
 * `cellier` is DROPPED by ruling, and the reason is not its reach: a cellier is an indoor pantry
 * rather than a basement cave, so folding the two together would state something the ad did not.
 *
 * Three guards, each forced by a measurement and each an instance of a class this repo has already
 * paid for:
 *
 * - **A MENTION IS NOT AN INCLUSION.** The naive `parking … XX €` reader was built and rejected: 38
 *   hits of which **36 are CDC false positives**, because that card puts the rent immediately after
 *   the amenity list. So the claim rests on an explicit word — *inclus / compris / attribué* — and
 *   **within {@see INCLUSION_WINDOW} characters of the amenity**, which is stricter than the
 *   design's own measurement and deliberately so. Measured both ways over the 149 parking-mentioning
 *   MATCH rows: **93** carry one of those words ANYWHERE in the description, **37** carry one near
 *   the amenity. The design quoted the anywhere-figure; an inclusion word three sentences away is
 *   usually about the charges (`y compris les charges`), and printing `parking inclus` on the
 *   strength of it would tell the developer the rent covers a space it does not. Under-claiming is
 *   the safe direction here: the plain `parking` line is still correct, and the flat is still shown.
 * - **`terrasse` HAS A RESIDENCE-NAME FALSE POSITIVE** — 2 of its 38 matched mentions are
 *   `12, les terrasses de la ravinière`, a building called after the thing it does not offer. The
 *   furniture class again (CDC's tooltip, Logirep's facet strip, SeLoger's CTA), and guarded on the
 *   plural after a comma or a street number.
 * - **`cave` WAS MEASURED INSIDE A SELOGER TRACKING URL**, so the scan runs over prose with every
 *   URL's query and fragment stripped — ninth instance of *URLs are classified text*, and it calls
 *   {@see RawListing::withoutUrlParameters()} rather than repeating the expression a third time.
 *
 * `jardin` reads `jardin` and never `rez de jardin` (4 of 32 mentions): that is a FLOOR, and the
 * line it joins already prints the floor.
 */
final class Amenities
{
    /**
     * Simple amenities: folded stem => the word the line prints.
     *
     * ORDER IS THE OUTPUT ORDER and is deliberate rather than alphabetical — outdoor space first,
     * because that is what the developer asked to see, then storage. A set with a stable order is
     * what makes the line assertable.
     *
     * @var array<string,string>
     */
    private const array SIMPLE = [
        'terrasse' => 'terrasse',
        'balcon' => 'balcon',
        'loggia' => 'loggia',
        'jardin' => 'jardin',
        'cave' => 'cave',
    ];

    /**
     * The parking family — four words for one fact, collapsed to one label.
     *
     * `box` and `garage` are in it because they are the same amenity under a roof. All four print
     * `parking`, because printing four synonyms would say four things.
     *
     * **`emplacement` WAS IN THIS LIST AND WAS MEASURED OUT** (6C gate, 2026-09-08). It is the one
     * family word carrying a second, unrelated sense: of its 36 distinct contexts in the store
     * EIGHT are a LOCATION rather than a space — `emplacement privilegie` (5), `emplacement ideal`
     * (2), `emplacement pratique` (1) — so on a card carrying nothing else it would announce a
     * parking space the advertisement does not offer, the display twin of the `terrasse` residence
     * name below. And it earns nothing: running the shipped {@see statesParking()} with and without
     * it over every stored listing returns the IDENTICAL label on all 3 379 rows (of the matched
     * ones, 1 117 none · 112 `parking` · 37 `parking inclus`), because every real one reads
     * `emplacement de parking` or `emplacement de stationnement` and both of those words are
     * already family members here. Zero coverage plus a live false-positive sense is the ruling
     * {@see Heating} already made for `convecteur` and `CPCU`. Qualifying it instead — requiring a
     * vehicle word beside it — was the other candidate and was rejected as a branch no stored
     * payload reaches, which this repo has paid for before as dead safety code.
     *
     * @var list<string>
     */
    private const array PARKING = ['parking', 'stationnement', 'garage', 'box'];

    /** Words that turn `parking` into `parking inclus`. Folded, so `attribué` is written plain. */
    private const array INCLUDED = ['inclus', 'incluse', 'compris', 'comprise', 'attribue', 'attribuee'];

    /** How far past the amenity word an inclusion word may sit and still be about it. */
    private const int INCLUSION_WINDOW = 40;

    /**
     * The amenity labels a listing's prose states, in the fixed order above; `[]` when it states
     * none or when there is no prose to read.
     *
     * @return list<string>
     */
    public static function read(?string $text): array
    {
        if ($text === null || trim($text) === '') {
            return [];
        }

        try {
            $folded = Text::fold(RawListing::withoutUrlParameters($text));
        } catch (MalformedText) {
            // Unreadable text states nothing. The display twin of every other unknown here.
            return [];
        }

        $found = [];

        foreach (self::SIMPLE as $stem => $label) {
            if (self::statesSimple($folded, $stem)) {
                $found[] = $label;
            }
        }

        $parking = self::statesParking($folded);
        if ($parking !== null) {
            $found[] = $parking;
        }

        return $found;
    }

    /** Does the prose state this amenity, discounting the two measured false-positive shapes? */
    private static function statesSimple(string $folded, string $stem): bool
    {
        if (preg_match_all('~\b' . $stem . '(?:s)?\b~u', $folded, $m, PREG_OFFSET_CAPTURE) < 1) {
            return false;
        }

        foreach ($m[0] as [$word, $at]) {
            $before = substr($folded, max(0, $at - 16), min($at, 16));

            // A RESIDENCE NAMED AFTER THE THING IT DOES NOT OFFER. `12, les terrasses de la
            // ravinière` — a comma or a street number, then an article, then the PLURAL. The
            // singular is never this shape, so it is never discounted.
            if (str_ends_with($word, 's') && preg_match('~(?:[,\d]\s*)(?:les|des|aux)\s+$~u', $before) === 1) {
                continue;
            }

            // `rez de jardin` is a FLOOR, and the line this joins already prints the floor.
            if ($stem === 'jardin' && preg_match('~\brez[\s-]*de[\s-]*$~u', $before) === 1) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * `parking`, `parking inclus`, or null.
     *
     * Every word of the family is examined rather than only the first — a description saying
     * `garage` in one sentence and `stationnement inclus` in another states an included parking
     * space, and stopping at the first match would print the weaker of the two claims.
     */
    private static function statesParking(string $folded): ?string
    {
        $seen = false;

        foreach (self::PARKING as $stem) {
            if (preg_match_all('~\b' . $stem . '(?:s)?\b~u', $folded, $m, PREG_OFFSET_CAPTURE) < 1) {
                continue;
            }

            foreach ($m[0] as [$word, $at]) {
                $seen = true;
                $window = substr($folded, $at + \strlen($word), self::INCLUSION_WINDOW);

                // `en sus` means the opposite, and it wins wherever it sits in the window: the five
                // Cityloger rows reading `possibilité de louer … en sus` must never print `inclus`.
                if (str_contains($window, 'en sus')) {
                    continue;
                }

                foreach (self::INCLUDED as $word2) {
                    if (str_contains($window, $word2)) {
                        return 'parking inclus';
                    }
                }
            }
        }

        return $seen ? 'parking' : null;
    }
}
