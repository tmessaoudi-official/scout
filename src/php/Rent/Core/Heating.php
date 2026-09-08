<?php

declare(strict_types=1);

namespace Scout\Rent\Core;

use Scout\Core\MalformedText;
use Scout\Core\Text;

/**
 * How a flat is heated, read out of the listing's own prose — the input to the Track 7-A penalty.
 *
 * **THE VOCABULARY WAS READ OFF THE REAL COPY, NEVER COMPOSED.** 125 distinct `chauffage …`
 * contexts in the live store were enumerated before a line of this was written, and the decisive
 * shape is that `individuel` and the energy word sit **0–3 words apart, in either order**:
 * `chauffage individuel electrique` (18 rows) but also `chauffage electrique individuel` (6),
 * `chauffage est individuel electrique` (3), `chauffage et eau chaude individuels gaz` (9),
 * `chauffage individuel alimente au gaz` (3). An ADJACENCY reader — the shape `exclude_patterns`
 * uses for meublé — misses 9 of the 35 electric rows, which is why this is a WINDOW.
 *
 * `convecteur`, `radiateur`, `CPCU` and `reseau de chaleur` are **0 hits each** in the whole store
 * and are deliberately absent: a vocabulary entry no payload can reach is coverage this class does
 * not have.
 *
 * Three rules, each of them one of this repo's own recurring classes:
 *
 * - **THE NEGATION IS READ FIRST.** `sans chauffage`, `pas de chauffage` — the lift-negation lesson
 *   (`Payload::bool()` reading *"Aucun ascenseur"* as `true`). A wrong `individuel` costs a real
 *   flat 20 points of ordering for a fact the ad denied.
 * - **EVERY OCCURRENCE IS EXAMINED, never only the first.** `preg_match` stopping at the first hit
 *   is what let one implausible rent hide a readable one three lines below it (the SeLoger
 *   price-drop fix), and a description mentioning `chauffage collectif de l'immeuble` before
 *   `chauffage individuel dans le logement` is the same shape.
 * - **AN UNREAD FACT IS `null`, NEVER A MODE** (hard rule 9). Silence about heating is not
 *   collective heating, and five of the eight rent sources carry no listing prose at all — their
 *   flats simply never take this penalty. That is the STATED COST of Track 7-A: the penalty ranks
 *   In'li flats below portal flats for a fact the portals never state.
 */
final readonly class Heating
{
    public const string INDIVIDUAL = 'individuel';
    public const string COLLECTIVE = 'collectif';

    public const string ELECTRIC = 'electrique';

    /**
     * How wide the GAP between `chauffage` and the MODE word may be — not where to cut the text.
     *
     * **THE FIRST VERSION TRUNCATED THE WINDOW AT 24 CHARACTERS AND THE STORE REFUTED IT.** The
     * longest real infix is ` et eau chaude ` (15 characters, 9 rows) and `individuels` is eleven
     * more, so a 24-character substring cut the mode word in half and the commonest gas shape came
     * back `null` — a reader that reads nothing, which looks exactly like a flat that says nothing.
     * Bounding the GAP instead lets the word be as long as it is. Kept at 24, which is *0–3 words*
     * as measured.
     *
     * The gap deliberately cannot cross a `.` or a newline: the next sentence's mode is not this
     * one's, and a newline is a field boundary in folded text.
     */
    private const int MODE_GAP = 24;

    /** How far past `chauffage` the ENERGY word may sit — the mode window plus one short phrase. */
    private const int ENERGY_WINDOW = 64;

    /** Longest negation this can recognise, in characters looked back from `chauffage`. */
    private const int NEGATION_LOOKBACK = 24;

    /**
     * Energy words, longest-first where one contains another, each mapped to what it is called in
     * the reason line. Only {@see ELECTRIC} carries the surcharge; the rest exist so that a gas or
     * wood flat is announced as what it is rather than as an unread one.
     *
     * @var array<string,string>
     */
    private const array ENERGIES = [
        'pompe a chaleur' => 'pompe à chaleur',
        'electri' => self::ELECTRIC,
        'gaz' => 'gaz',
        'fioul' => 'fioul',
        'bois' => 'bois',
        'urbain' => 'réseau urbain',
        'pac' => 'pompe à chaleur',
    ];

    private function __construct(
        /** {@see INDIVIDUAL} or {@see COLLECTIVE}. */
        public string $mode,
        /** The energy as the reason line names it, or `null` when the ad stated the mode alone. */
        public ?string $energy,
        /** True only for an explicitly ELECTRIC individual system — the surcharge's whole trigger. */
        public bool $electric,
    ) {}

    /** Does this flat heat itself? The base penalty's trigger. */
    public function isIndividual(): bool
    {
        return $this->mode === self::INDIVIDUAL;
    }

    /** `chauffage individuel électrique`, or `chauffage individuel` when no energy was stated. */
    public function label(): string
    {
        return 'chauffage ' . $this->mode . ($this->energy === null ? '' : ' ' . $this->energy);
    }

    /**
     * Read the heating out of a listing's prose, or `null` when it says nothing readable.
     *
     * Runs over the DESCRIPTION as mapped — never over a new field-map entry, because
     * `FieldMap::fingerprint()` hashes every mapped field list and one more entry invalidates all
     * 737 cached In'li `listing_detail` rows, which then re-hydrate at 20 per pass over about nine
     * hours while every In'li flat is judged card-alone.
     */
    public static function read(?string $text): ?self
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        try {
            $folded = Text::fold($text);
        } catch (MalformedText) {
            // Unreadable text is not evidence of anything. Same direction as every other unknown.
            return null;
        }

        if (preg_match_all('~chauffage~', $folded, $m, PREG_OFFSET_CAPTURE) < 1) {
            return null;
        }

        foreach ($m[0] as [$word, $at]) {
            if (self::isNegated($folded, $at)) {
                continue;
            }

            $after = substr($folded, $at + \strlen($word), self::ENERGY_WINDOW);
            // A newline is a field boundary in folded text — the energy of the NEXT field is not
            // this sentence's.
            $nl = strpos($after, "\n");
            if ($nl !== false) {
                $after = substr($after, 0, $nl);
            }

            $mode = self::modeIn($after);
            if ($mode === null) {
                continue;
            }

            $energy = self::energyIn($after);

            return new self($mode, $energy, $energy === self::ELECTRIC);
        }

        return null;
    }

    /** Is this occurrence of `chauffage` denied rather than described? */
    private static function isNegated(string $folded, int $at): bool
    {
        $back = substr($folded, max(0, $at - self::NEGATION_LOOKBACK), min($at, self::NEGATION_LOOKBACK));

        return preg_match('~\b(?:sans|aucun\w*|pas\s+d(?:e|\')|ni)\s+(?:\w+\s+){0,2}$~u', $back) === 1;
    }

    /**
     * The mode word within {@see MODE_GAP} characters of `chauffage`, or null when there is none.
     *
     * Anchored at the start of the text FOLLOWING `chauffage`, with the gap bounded and lazy, so
     * the bound applies to the distance and never to the word itself.
     */
    private static function modeIn(string $after): ?string
    {
        $gap = '^[^.\n]{0,' . self::MODE_GAP . '}?';

        if (preg_match('~' . $gap . '\bindividuel(?:le|s|les)?\b~u', $after) === 1) {
            return self::INDIVIDUAL;
        }
        if (preg_match('~' . $gap . '\bcollecti(?:f|fs|ve|ves)\b~u', $after) === 1) {
            return self::COLLECTIVE;
        }

        return null;
    }

    /**
     * The FIRST energy word by position, not the first by table order.
     *
     * A description reading `chauffage individuel gaz, eau chaude electrique` names gas for the
     * heating and electricity for something else; taking the table's order instead of the text's
     * would apply the surcharge to a gas flat.
     */
    private static function energyIn(string $window): ?string
    {
        $bestAt = null;
        $best = null;

        foreach (self::ENERGIES as $needle => $label) {
            if ($needle === 'pac') {
                // `pac` is an ABBREVIATION and must not match inside a word (`capacité`), so it is
                // located by its own anchored match — NOT by `strpos` plus a separate `\bpac\b`
                // test somewhere else in the window, which would take the position of the wrong
                // occurrence and could order it ahead of a real energy word.
                $at = preg_match('~\bpac\b~u', $window, $m, PREG_OFFSET_CAPTURE) === 1 ? $m[0][1] : false;
            } else {
                // The rest are stems of French nouns and are safe unanchored — `electri` is
                // deliberately a stem, so `electrique` and `electricite` both read.
                $at = strpos($window, $needle);
            }

            if ($at === false) {
                continue;
            }
            if ($bestAt === null || $at < $bestAt) {
                $bestAt = $at;
                $best = $label;
            }
        }

        return $best;
    }
}
