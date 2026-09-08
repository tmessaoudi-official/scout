<?php

declare(strict_types=1);

namespace Scout\Rent\Core;

/**
 * What an adapter produced, before enrichment and before scoring.
 *
 * Two rules from `CLAUDE.md` are encoded in the SHAPE of this object rather than left to the
 * discipline of whoever writes the criteria engine:
 *
 * 1. **`null` is not zero** (hard rule 9). Every measurement is nullable, and null means *the source
 *    did not say*, never *below the minimum*. `$rooms = null` must not be compared against a
 *    minimum; `$floor = 0` is the rez-de-chaussée and is falsy but real; `$hasElevator = false`
 *    ("there is no lift") and `$hasElevator = null` ("the listing did not mention a lift") are
 *    different facts and the prototype conflated them.
 *
 * 2. **Rent is charges comprises or hors charges, and sources disagree** (hard rule 9, and the #1
 *    correctness trap in `docs/FILTERS.md`). Storing one `rent` field forces a guess at parse time
 *    that nothing downstream can recover from, so both are carried separately and explicitly. The
 *    criteria engine compares on CC; an adapter that only knows HC leaves `$rentCc` null and says so.
 */
final readonly class RawListing
{
    /**
     * @param string                $sourceName   key in `config/rent/sources.json`
     * @param string                $externalId   the source's own id — the basis of within-source dedup
     * @param array<string, mixed>  $fields       structured fields exactly as the adapter found them.
     *                                            Field names are NOT normalised here on purpose: the
     *                                            raw name is evidence, and normalising is the
     *                                            classifier's job ({@see Text::fieldKey()}).
     *
     *                                            **`mixed`, not `string`, and the annotation used to
     *                                            lie.** Every production adapter normalises through
     *                                            `Payload::flatten()`, but an annotation is not a
     *                                            runtime guarantee — a JSON feed with
     *                                            `"gamme": ["PLUS","PLAI"]` forwarded verbatim by a
     *                                            bespoke adapter arrives here as a list, and
     *                                            {@see TenureClassifier} has a deliberate tier-1
     *                                            doubt for exactly that. Writing `string` invited
     *                                            every layer downstream to assume one; the snapshot
     *                                            layer did, and silently dropped the value that the
     *                                            doubt was watching for.
     * @param int|null              $rentCc       monthly rent charges comprises, in euros
     * @param int|null              $rentHc       monthly rent hors charges, in euros
     * @param int|null              $charges      monthly charges, in euros
     * @param float|null            $surfaceM2    living surface
     * @param int|null              $rooms        pièces (not chambres)
     * @param int|null              $bedrooms     chambres
     * @param int|null              $floor        0 = rez-de-chaussée
     * @param bool|null             $hasElevator  null = the listing did not say
     */
    public function __construct(
        public string $sourceName,
        public string $externalId,
        public string $title = '',
        public string $description = '',
        public array $fields = [],
        public ?string $url = null,
        public ?string $commune = null,
        public ?string $postcode = null,
        public ?int $rentCc = null,
        public ?int $rentHc = null,
        public ?int $charges = null,
        public ?float $surfaceM2 = null,
        public ?int $rooms = null,
        public ?int $bedrooms = null,
        public ?int $floor = null,
        public ?bool $hasElevator = null,
        /**
         * Has this listing's OWN detail page been read?
         *
         * Only {@see mergedWith()} sets it, so it means what it says: a second fetch happened and
         * its content is folded in. A hydration FAILURE never reaches that method, so a page that
         * could not be read stays `false` — tried-and-failed is not read-and-bare, here as in the
         * store.
         *
         * Read by the fail-closed rule: weak evidence on a mixed source digests while the page is
         * unread, and matches once it has been read and found to say nothing excluding. See
         * {@see \Scout\Rent\Core\TenureClassifier}.
         */
        public bool $detailRead = false,
        /**
         * Door-to-door public-transport minutes to the configured destination, or `null`.
         *
         * ENRICHMENT, and it lives here rather than as a `judge()` argument for one reason:
         * `scout reclassify` re-judges from the schema-v7 snapshot, and the snapshot is exactly the
         * `RawListing` the classifier consumed. A commute passed alongside would be absent on every
         * re-judge, so a stored listing would silently score lower the second time it was looked at.
         * `floor` and `hasElevator` arrive by the same route (detail hydration) for the same reason.
         *
         * `null` means UNKNOWN — the API was unreachable, the commune did not geocode, or commute is
         * switched off. It is never "far": hard rule 9, and the component is simply not scored.
         */
        public ?int $commuteMinutes = null,
        /**
         * When the SOURCE says this observation was made — an email's `Date`, as a UTC ISO-8601
         * instant — or null for "when the pass read it" (every polling adapter). The store orders
         * sightings by this instant, and that ordering is the whole defence against a re-read
         * older card manufacturing a rent drop (2026-08-29: one Bien'ici flat, re-sent a day later
         * at a higher rent, produced 429 alternating history rows and 128 phantom emails).
         */
        public ?string $observedAt = null,
        /**
         * WHO is advertising this flat — the agency or landlord named by the portal itself — or
         * `null` when the source publishes no advertiser surface.
         *
         * Extracted STRUCTURALLY by a per-source `advertiser_pattern`, never by scanning prose for
         * landlord names: SeLoger states it in the subject (`<ADVERTISER> vous adresse ses
         * dernières exclusivités`), and a body scan is the `au plus près` / `Ce·lli·er` /
         * `?c=plai_plus` failure class, which has cost this repo six fixes counting the one
         * committed in the audit that produced this field.
         *
         * It lives HERE, on the listing, and not as a `classify()` argument. `scout reclassify`
         * re-judges from the schema-v7 snapshot alone, and the snapshot IS this object — a value
         * passed alongside would be absent on every re-judge, so a stored listing would silently
         * lose its advertiser the second time it was looked at. That is exactly the mistake
         * `commuteMinutes` above records, and `floor`/`hasElevator` arrive by the same route.
         *
         * `null` is UNKNOWN, never "an anonymous private advertiser" (hard rule 9): an unrecognised
         * advertiser and an unstated one both leave the source profile exactly as it was.
         *
         * Read by {@see LandlordRegistry}, which turns a recognised institutional name into the
         * source profile that landlord's own listings are judged under — so the same flat gets the
         * same verdict whichever route it arrives by.
         */
        public ?string $advertiser = null,
        /**
         * Does the SOURCE that produced this listing publish no listing prose at all?
         *
         * Declared per source ({@see \Scout\Rent\Config\SourceDefinition::$proseAbsent}) and carried
         * here rather than passed to `judge()`, for the reason {@see $commuteMinutes} documents:
         * `scout reclassify` re-judges from the schema-v7 snapshot, and a value handed in alongside
         * would be absent on every re-judge — so a stored listing would quietly lose its caveat the
         * second time it was looked at, which is worse than never having had one.
         *
         * It is EVIDENCE ABOUT THE EVIDENCE, and it changes no verdict. `CriteriaEngine` reads it to
         * say that the exclusion lists could not fire, exactly as it already says a rent ceiling
         * could not be applied to an hors-charges figure. Never a disqualifier and never a score
         * component (hard rule 8).
         *
         * `false` on a pre-v7 row and on every source that publishes text, which says nothing rather
         * than asserting the check WAS made — the safe direction (hard rule 9).
         */
        public bool $proseAbsent = false,
    ) {}

    /**
     * This listing, plus whatever a second fetch of its own detail page turned up.
     *
     * The merge rule is hard rule 9 read strictly: **`$detail` wins where it HAS a value, and never
     * where it does not.** A detail page that omits the rent is not a detail page reporting a rent
     * of nothing — it is a page that said nothing about rent, and the card's figure stands. Empty
     * strings count as absent for the same reason: a selector that matched an empty element found
     * no value, and letting `''` overwrite a real title is how a good card becomes a blank one.
     *
     * Identity is never merged. `sourceName` and `externalId` come from the card, because the card
     * is what the seen-set is keyed on — a detail map that happened to define `ref` could otherwise
     * re-identify a listing mid-pass and re-notify it forever.
     */
    /**
     * The same listing with its commute filled in — and EVERYTHING ELSE untouched, by construction.
     *
     * Clone-with inside the class (readonly properties may only be re-initialised here), never a
     * field-by-field rebuild: `Pipeline::enrich()` used to enumerate every constructor parameter,
     * and the day `observedAt` was added it dropped it silently, on the one machine where commute
     * is enabled — production. The phantom-drop fix shipped green and the first live pass fired
     * the same phantom drop again (2026-08-29). `mergedWith()` below had named this exact trap.
     */
    public function withCommute(int $minutes): self
    {
        return clone($this, ['commuteMinutes' => $minutes]);
    }

    public function mergedWith(self $detail): self
    {
        $str = static fn (string $mine, string $theirs): string => $theirs !== '' ? $theirs : $mine;
        $any = static fn (mixed $mine, mixed $theirs): mixed => $theirs ?? $mine;

        return new self(
            sourceName: $this->sourceName,
            externalId: $this->externalId,
            title: $str($this->title, $detail->title),
            description: $str($this->description, $detail->description),
            // The card's own keys stay unless the detail page names the same one — `_text` included,
            // which is the card's text and remains correctly scoped to the card.
            fields: [...$this->fields, ...$detail->fields],
            url: $any($this->url, $detail->url),
            commune: $any($this->commune, $detail->commune),
            postcode: $any($this->postcode, $detail->postcode),
            rentCc: $any($this->rentCc, $detail->rentCc),
            rentHc: $any($this->rentHc, $detail->rentHc),
            charges: $any($this->charges, $detail->charges),
            surfaceM2: $any($this->surfaceM2, $detail->surfaceM2),
            rooms: $any($this->rooms, $detail->rooms),
            bedrooms: $any($this->bedrooms, $detail->bedrooms),
            floor: $any($this->floor, $detail->floor),
            hasElevator: $any($this->hasElevator, $detail->hasElevator),
            // Reaching this method IS the detail page having been read. A failed fetch never gets
            // here — `HtmlSource` records the failure and returns the card untouched.
            detailRead: true,
            // Carried even though today's order (hydrate, THEN enrich) means the card never holds
            // one yet. A constructor parameter this method forgets is silently DROPPED, and the
            // reflection guard cannot catch it: that guard checks the snapshot ENCODER, not the
            // merge. The trap arms itself the day the order changes.
            commuteMinutes: $any($this->commuteMinutes, $detail->commuteMinutes),
            // The CARD's observation time: a detail page fetched now says nothing about when the
            // listing was observed, and a detail merge must not re-date an old card to the pass.
            observedAt: $this->observedAt,
            // THE CARD'S ADVERTISER, never the detail page's. The detail page belongs to the portal
            // and its furniture names whoever the portal wants — the same reason a `detail_map`
            // must address the LISTING and not the page. `$any` would also let a detail fetch
            // ARM this field on a card that never named an advertiser, which is a §1-relevant
            // change of verdict sourced from page chrome.
            advertiser: $this->advertiser,
            // A property of the SOURCE, so both sides always agree and either would do — written as
            // the card's to say which one is meant, like `advertiser` and `observedAt` above. What
            // matters is that it is written at all: this method's own warning, two fields up, is
            // that a constructor parameter it forgets is silently dropped, and a caveat that
            // vanishes the moment a listing is hydrated is exactly the shape that goes unnoticed.
            proseAbsent: $this->proseAbsent,
        );
    }

    /**
     * Rent charges comprises, derived when it was not reported directly.
     *
     * RULED 2026-08-07 (Q32, amending Q2). Q2 said only *"normalise every source to CC"*, which left
     * three distinct cases collapsing into one "unknown":
     *
     * - **`rentCc` present** — use it.
     * - **`rentHc` and `charges` both present** — CC is their sum, and it is derivable. Calling this
     *   unknown throws away a hard filter on a listing whose total is right there.
     * - **`rentHc` only** — genuinely unknown, and it must STAY unknown. A 1750 € HC flat is roughly
     *   1900 € CC, so treating HC as CC notifies it against an 1800 € ceiling it does not meet.
     *
     * Returns `null` for the last case and for a listing with no rent at all. `null` here means *the
     * ceiling cannot be applied*, never *below the ceiling* — the criteria engine must not disqualify
     * on it (hard rule 9), and says so in `reasons[]` instead.
     */
    public function effectiveRentCc(): ?int
    {
        if ($this->rentCc !== null) {
            return $this->rentCc;
        }

        if ($this->rentHc !== null && $this->charges !== null) {
            return $this->rentHc + $this->charges;
        }

        return null;
    }

    /**
     * Does this listing carry any location evidence at all?
     *
     * RULED 2026-08-07 (Q32). F4/F5/F6 each say explicitly what an unknown measurement does; F2/F3
     * said nothing, and both readings are wrong. Rejecting on unknown silently drops every listing
     * from a source whose commune selector drifted — and source health does NOT fire, because the
     * fetch succeeded and the item count is non-zero. Passing on unknown stops filtering geography
     * altogether, and Leboncoin is a national portal.
     *
     * So: a listing with neither field is rejected (location is the one criterion with no score
     * fallback), and a listing with one of the two is judged on that one.
     */
    public function hasLocationEvidence(): bool
    {
        return $this->commune !== null || $this->postcode !== null;
    }

    /**
     * Every free-text surface the classifier reads, in one string.
     *
     * Title and description are joined rather than searched separately because landlords put the
     * financing label in either one — `LLI - T3 Le Vésinet` is a real shape of title.
     */
    public function text(): string
    {
        // `_text` is the adapter's whole-card text surface (see `HtmlSource`, which writes it and
        // calls it exactly that). It belongs HERE and not in the field scan: the field path matches
        // financing acronyms case-insensitively, on the stated grounds that a field value is an
        // identifier or a short declaration rather than prose. Fed a card's prose, that rule read
        // the French adverb in `implanté au plus près` as the excluded acronym `PLUS` and vetoed the
        // listing's own tier-1 badge [measured 2026-08-20 on CDC Habitat: identical text in
        // `description` gave LLI 0.97, in `_text` UNKNOWN 0.00]. `plus` is one of the commonest
        // words in the language, so that is close to every card carrying prose.
        //
        // Joined rather than scanned separately for the same reason title and description are: a
        // label sits in whichever surface the landlord chose.
        $cardText = $this->fields['_text'] ?? null;

        return self::withoutUrlParameters(trim(
            $this->title . "\n" . $this->description
            . (is_string($cardText) && $cardText !== '' ? "\n" . $cardText : ''),
        ));
    }

    /**
     * A URL's QUERY and FRAGMENT removed; its scheme, host and path kept.
     *
     * **Fifth instance of one failure class, on a surface that did not exist before 2026-08-26.**
     * `EmailMessage::harvestHrefs()` now moves each anchor's URL into the body — it has to, because
     * a segmented source associates a link with a card by scanning that segment's text — so every
     * tracking parameter a portal writes is fed to the tenure scan. Measured on a synthetic campaign
     * string carrying `plus`, `lli` and `plai`: two explicit label signals fired and conflicted the
     * verdict to `UNKNOWN`, digesting an eligible flat. leboncoin's real campaign string happens to
     * contain none of them, which is luck rather than a guard — and unlike the CDC tooltip, the
     * Cityloger prose or the SeLoger CTA, nobody can rewrite a portal's analytics parameters.
     *
     * **The path is KEPT, and that asymmetry is §1.** Blanking the whole URL would be simpler and
     * would run the wrong way: a path segment like `/logement-social/` is real evidence, and losing
     * a social signal is the dangerous direction, where losing a campaign string costs nothing. A
     * query or fragment is machine parameters by construction — the same distinction
     * {@see \Scout\Rent\Adapters\EmailAlertSource::stableId()} already draws for identity.
     *
     * PUBLIC since Track 7, and only because the alternative was a THIRD copy. `Core\Amenities`
     * scans the same prose for the same reason — `cave` was measured inside a SeLoger tracking URL
     * — and this rule already exists twice (here and in `EmailAlertSource::prose()`). Writing it
     * again is *a fix landing on one of two symmetric surfaces* with the third one added by hand,
     * which is this repo's most-repeated defect; a caller is cheaper than a copy.
     */
    public static function withoutUrlParameters(string $text): string
    {
        return preg_replace('~(https?://[^\s<>"\']*?)[?#][^\s<>"\']*~i', '$1', $text) ?? $text;
    }
}
