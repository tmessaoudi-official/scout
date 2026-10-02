<?php

declare(strict_types=1);

namespace Scout\Rent\Core;
use Scout\Core\MalformedText;
use Scout\Core\Text;

/**
 * THE classifier. `spec/PROJECT_BRIEF.md` §4, and the module that carries `CLAUDE.md` §1.
 *
 * Three ideas, in order of how much trouble they save:
 *
 * 1. **The ladder.** Five signal tiers. The highest tier that fires decides the tenure; lower tiers
 *    may only move the confidence. That is `CLAUDE.md`'s "a lower-priority signal must never
 *    override a higher one", implemented rather than restated.
 *
 * 2. **The collocation guard, and its third answer.** `PLUS` is a financing scheme and `plus` is one
 *    of the commonest words in French. Word boundaries alone are not enough (`plus de 3 chambres`),
 *    and uppercase alone is not enough (portal titles are SHOUTED). An uppercase acronym in a
 *    financing collocation is read three ways, per occurrence:
 *      - a financing label ENDS the phrase (punctuation, end of text, another acronym) → the label;
 *      - a known French comparative follows (`PLUS GRAND`) → the adverb, no signal;
 *      - any other word follows (`LOGEMENT PLUS MODERNE`) → **indécidable**, and it says so.
 *    The third answer exists because the alternative was a denylist of French adjectives, which
 *    cannot be completed: every adjective missing from it turned an emphatic title into a silent
 *    REJECT, and nothing arriving looks exactly like a quiet market.
 *
 * 3. **The conflict rule, and doubts.** The ladder on its own is not fail-closed. A structured field
 *    saying `LLI` and a body text saying `PLAI` leaves the ladder at LLI/0.97, sailing past the 0.6
 *    floor into a notification. So an eligible verdict contradicted by any excluded-tenure signal
 *    collapses to UNKNOWN. It does not assert the listing is social — it withholds. The reverse is
 *    deliberately NOT symmetric: an excluded verdict contradicted by an eligible signal stays
 *    excluded. Softening an exclusion is the one direction that costs a wasted application.
 *
 *    An *indécidable* marker from (2) is a **doubt**, not a tenure claim, so it is held apart from
 *    the ladder entirely: it withholds an otherwise-eligible verdict, and it never competes with,
 *    masks or is masked by a real label. It used to be resolved positionally against them, which
 *    made the answer depend on sentence order.
 */
final readonly class TenureClassifier
{
    /**
     * Returned by {@see excludedVocabularyIn()} when a surface could not be read at all.
     *
     * A distinct value rather than `null`, because `null` there means "read it, found nothing" and
     * conflating the two turned an unreadable field into a silent one.
     */
    private const string UNREADABLE = "\x00unreadable";

    /** The fail-closed floor: `CLAUDE.md` §1's 0.6, in whole points. */
    public const int FLOOR_BP = 60;

    /**
     * Base confidence per tier.
     *
     * Tier 5 sits BELOW {@see FLOOR_BP} on purpose. A mixed source emitting a listing that carries
     * zero tenure evidence therefore lands in the digest, never in a notification — `CLAUDE.md`'s "an
     * absent signal must lower confidence, never silently inherit `default_tenure` at full
     * confidence", made structural instead of aspirational.
     */
    private const array TIER_BASE = [1 => 97, 2 => 90, 3 => 80, 4 => 70, 5 => 50];

    private const int CORROBORATION_BP = 3;
    private const int CONTRADICTION_BP = 15;
    private const int CEILING_BP = 99;
    private const int BASEMENT_BP = 10;

    /**
     * An annual income ceiling is an ANNUAL INCOME, and the twin of the rent plausibility band.
     *
     * The lowest real figure in the committed tables is PLAI at 14 811 €, so 10 000 leaves headroom
     * for a future revaluation downward while still refusing every rent, charge and surface a
     * listing states. Without it a "loyer 1 250 €" near the anchor becomes an income ceiling below
     * every threshold and the tier answers SOCIAL to noise.
     */
    private const int PLAFOND_PLAUSIBLE_MIN_EUR = 10000;

    /** Structured fields worth treating as tier 1. Compared as {@see Text::fieldKey()} output. */
    private const array TENURE_FIELDS = [
        // `tenureField` is not a name any landlord uses — it is the key `FieldMap::tenure_field`
        // writes into, i.e. THIS PROJECT'S OWN declaration that a given element of a payload is the
        // financing field. Omitting it made that config key inert: `/add-source` Step 4 calls it
        // "the highest-value mapping, look hard for it", and it produced no tier-1 signal at all,
        // falling through to the unrecognised-field path which only doubts on EXCLUDED vocabulary.
        // Measured on CDC Habitat's frozen payload [2026-08-20]: 16 of 16 cards came back
        // UNKNOWN/0.00/DIGEST, including the 14 badged `Logement intermédiaire`. §1 held, but by
        // accident rather than by understanding — and the visible half was over-rejection, which
        // looks exactly like a quiet market.
        'tenurefield',
        'financement', 'typefinancement', 'financementlogement',
        'typeproduit', 'produit',
        'categorie', 'category', 'categorielogement',
        'typelogement', 'dispositif', 'regime', 'conventionnement', 'agrement',
    ];

    /**
     * Tier 2 — explicit labels, matched whole-token on folded text.
     *
     * Multi-word entries are more specific than their parts, and the resolver prefers the longest
     * match at the earliest position, so `conventionne anah` beats a bare `conventionne` and
     * `logement intermediaire` beats a bare `lli` that appears later in the same sentence.
     */
    private const array LABELS = [
        'logement locatif intermediaire' => Tenure::LLI,
        'logement intermediaire' => Tenure::LLI,
        'loyer intermediaire' => Tenure::LLI,
        'lli' => Tenure::LLI,
        // `loyer libre` is the tenure term. `logement libre` is NOT — it is the standard French
        // VACANCY phrase (`libre au 1er août`, `libre de suite`), and it was in this table until
        // 2026-08-06, when a review showed it firing tier 2 at 90 on a move-in date. Worse, the
        // spurious LIBRE satisfied the conventionné exception below, so a listing whose own title
        // said `conventionné` reached MATCH. Do not add vacancy vocabulary here.
        'loyer libre' => Tenure::LIBRE,
        'pret locatif intermediaire' => Tenure::LLI,
        'conventionne anah' => Tenure::ANAH,
        // ANAH's MARKETING names, added 2026-08-07. `conventionne anah` above only catches an ad
        // that spells out the administrative term, and almost none do — the scheme is advertised
        // under its brand. This matters more than it looks: ANAH conventionnement (Loc'Avantages,
        // formerly Louer abordable) is signed by PRIVATE INDIVIDUAL LANDLORDS, so it appears on
        // Leboncoin and SeLoger, which are exactly the sources declared `mixed_tenure: false`.
        // A review ran the payload and got `LIBRE / 50 / MATCH` with reasons[] reading "aucun
        // signal dans l'annonce" — an excluded tenure reaching a notification. Tier 2 beats the
        // tier-5 source default, so these close it without touching the flag.
        "loc'avantages" => Tenure::ANAH,
        'loc avantages' => Tenure::ANAH,
        'locavantages' => Tenure::ANAH,
        'louer abordable' => Tenure::ANAH,
        'convention anah' => Tenure::ANAH,
        'conventionne anru' => Tenure::ANRU,
        'pret locatif a usage social' => Tenure::PLUS,
        "pret locatif aide d'integration" => Tenure::PLAI,
        'pret locatif social' => Tenure::PLS,
        'logement locatif social' => Tenure::SOCIAL,
        'logement social' => Tenure::SOCIAL,
        'habitation a loyer modere' => Tenure::SOCIAL,
        'hlm' => Tenure::SOCIAL,
        'plai' => Tenure::PLAI,
        // `pls` sat in AMBIGUOUS_LABELS until 2026-08-06 (round 4), behind the collocation guard —
        // and that was a fail-open, because the guard's noun list is closed. `Appartements PLS`,
        // `programme agréé PLS`, `financé en PLS`, `lots (PLS)` are all ordinary French housing
        // register and all produced NO signal and NO doubt; on a pure source, a listing whose
        // entire description read "Appartements PLS disponibles" was notified at confidence 50 with
        // reasons[] saying "aucun signal dans l'annonce". The identical PLAI text rejected.
        //
        // The argument for moving it is the one already written for `plai` below, and
        // `Text::INVARIANT_WORDS` had listed `pls` alongside `plai` the whole time: no French word
        // is spelled `pls`, so word boundaries are enough. Only `plus` is genuinely ambiguous.
        'pls' => Tenure::PLS,
        'anru' => Tenure::ANRU,
        'anah' => Tenure::ANAH,
        'conventionne' => Tenure::CONVENTIONNE,
    ];

    /**
     * Tier 2, but only under the collocation guard. See idea 2 in the class docblock.
     *
     * ONE entry, and that is the point: `plus` is a French adverb, `plai`/`pls`/`lli` are not
     * words at all. An acronym only belongs behind the guard if the guard's failure to recognise a
     * context is SAFER than matching — which is true for `plus` (matching costs eligible listings)
     * and false for every other acronym (not matching costs an application).
     */
    private const array AMBIGUOUS_LABELS = [
        'PLUS' => Tenure::PLUS,
    ];

    /** Words that make an adjacent uppercase acronym a financing label rather than a coincidence. */
    private const string COLLOCATION = 'logements?|financements?|categories?|types?|typologie|produits?|agrement|dispositif|programme|regime|conventionnement|financement';

    /** An acronym next to another acronym is a financing enumeration: `PLUS / PLAI`, `PLS ou PLUS`. */
    private const string ACRONYMS = 'PLAI|PLUS|PLS|LLI';

    /**
     * If the acronym is followed by one of these, it was the French adverb after all.
     *
     * `PLUS DE 70 M2` in a shouted title is the case that makes this necessary: it satisfies
     * uppercase, and a title like `LOGEMENT PLUS GRAND` would satisfy collocation too.
     */
    private const string COMPARATIVE_TAIL = "de|des|du|d'|que|qu'|grand|grande|grands|grandes|petit|petite|spacieux|spacieuse|lumineux|lumineuse|recent|recente|calme|proche|proches|cher|chere|tard|tot|haut|haute|bas|basse|pres|loin|rapide|facile|eleve|elevee|beau|belle|joli|jolie|vaste|clair|claire|grand-e|important|importante";

    /**
     * Tier 3 — procedural tells. `CLAUDE.md` calls these "the cheapest reliable discriminator the
     * domain offers", and it is right: a listing can avoid every financing acronym and still give
     * itself away by demanding an SNE number.
     *
     * The intermediate-side tells are listed first for readability only; resolution is by position
     * and length, as everywhere else.
     */
    private const array PROCEDURAL = [
        // Intermediate: allocated by the landlord, no commission d'attribution.
        // Both literals must carry the ATTRIBUTION sense explicitly. A bare `sans commission` was
        // here until 2026-08-06 and is a fee disclaimer — in the wild it is almost always
        // `sans commission d'agence`, which says nothing about how the flat is allocated and which
        // bailleurs sociaux advertise too. Tier 3 clears the floor unaided, so it turned a
        // fail-closed digest into a notification on a phrase orthogonal to tenure.
        'sans condition de commission' => Tenure::LLI,
        "sans commission d'attribution" => Tenure::LLI,
        'attribution directe' => Tenure::LLI,
        // Social: allocated through the national register and a commission.
        "numero unique d'enregistrement" => Tenure::SOCIAL,
        // A DOUBT, not a verdict. `numéro unique` alone is overwhelmingly the SNE in this domain,
        // but it is also ordinary CRM boilerplate — *"votre numéro unique de dossier vous sera
        // communiqué"*. As a determinate SOCIAL signal it cleared the floor unaided at tier 3, so
        // one such sentence was a hard, silent REJECT: nothing arrives, and that is indistinguishable
        // from a quiet market (`CLAUDE.md` hard rule 8). As a doubt it withholds instead — the
        // listing surfaces in the "à vérifier" digest where a human settles it in three seconds.
        // The discriminating form, with `d'enregistrement`, stays determinate above.
        'numero unique' => Tenure::UNKNOWN,
        // Rent-cap vocabulary: a DOUBT, never a verdict, and deliberately not `plafond de
        // ressources`. Added 2026-08-07 alongside the ANAH labels above, for the ad that carries
        // the scheme's economics without naming the scheme.
        //
        // The line is drawn where the vocabulary stops being SHARED WITH LLI, and it was drawn in
        // the wrong place first. `plafond de ressources` was rejected on that reasoning up front —
        // LLI has income ceilings too. `loyer plafonne` was added anyway and the corpus immediately
        // failed three fixtures: LLI is BY DEFINITION a capped rent, so `loyer plafonné` is the
        // primary target describing itself, and as a doubt it digested `lli-004`, `lli-011` and
        // `regress-030`. Both are out. What is left is vocabulary conventionné ads use and LLI ads
        // do not.
        //
        // These withhold rather than reject because both phrases are also ordinary market copy: a
        // verdict either way would be wrong about half the time, and withholding costs one glance
        // at the digest while a REJECT costs a flat, silently.
        'loyer maitrise' => Tenure::UNKNOWN,
        'loyer abordable' => Tenure::UNKNOWN,
        "systeme national d'enregistrement" => Tenure::SOCIAL,
        'sne' => Tenure::SOCIAL,
        'demande de logement social' => Tenure::SOCIAL,
        "commission d'attribution" => Tenure::SOCIAL,
        'commission attribution' => Tenure::SOCIAL,
    ];

    /**
     * Social procedural tells that are negated by an immediately preceding `sans`.
     *
     * This is NOT general negation parsing — the classifier does not do that, and the `trap-009`
     * fixture records the consequence honestly. It is one targeted lookbehind on the one phrase
     * where the negated form is itself a documented intermediate tell, so both readings of
     * "sans commission d'attribution" cannot fire at once and trigger a spurious conflict.
     */
    private const array NEGATED_BY_SANS = ["commission d'attribution", 'commission attribution'];

    private PlafondBands $bands;

    /**
     * Tier 4 is ARMED by default as of 2026-08-26 — it shipped inert for want of the figures.
     *
     * Resolved in the body rather than as a parameter default because a static factory call is not
     * a constant expression; `readonly` permits the single assignment here. Passing an explicit
     * `new PlafondBands()` still disarms the tier, which is what the surface-matrix and differential
     * suites use to isolate the other tiers.
     */
    /**
     * Resolves a `config/rent/sources.json` key to its profile, for {@see LandlordRegistry}.
     *
     * `\Closure`, not `callable`: this class is `readonly`, which forbids an untyped property, and
     * `callable` is not a legal property type in PHP. `Closure::fromCallable()` normalises whatever
     * the caller passes.
     *
     * @var \Closure(string): ?SourceProfile
     */
    private \Closure $profileFor;

    /**
     * The default resolver answers `null` for every key, and that is the SAFE direction rather than
     * an omission. A caller that does not wire config in — every corpus and fixture test — still
     * gets the substitution, just to the fail-closed unknown-landlord profile instead of to the
     * landlord's own. Both digest a card with no explicit label; the real profile only restores that
     * landlord's `default_tenure` hint. So forgetting to inject cannot re-open the hole, which is
     * the property that matters when the constructor is called from twenty places.
     *
     * @param (callable(string): ?SourceProfile)|null $profileFor
     */
    public function __construct(?PlafondBands $bands = null, ?callable $profileFor = null)
    {
        $this->bands = $bands ?? PlafondBands::ileDeFrance2026();
        $this->profileFor = $profileFor === null
            ? static fn (string $key): ?SourceProfile => null
            : \Closure::fromCallable($profileFor);
    }

    public function classify(RawListing $listing, SourceProfile $source): Classification
    {
        // WHO IS ADVERTISING decides which profile this listing is judged under, before any tier
        // runs. Measured 2026-09-01: 23 SeLoger rows advertised by an institutional landlord were
        // judged `LIBRE` at the source default and 21 were pushed as a MATCH, while the same flat
        // on that landlord's own site digests under `mixed_tenure: true`. The verdict depended on
        // the ROUTE, not on the flat.
        //
        // It is a SUBSTITUTION, not a signal, and the distinction is load-bearing. This class
        // reasons about tenure — labels, procedural tells, ceilings — and the one adjacent decision
        // in its history removed `sans commission` from tier 3 precisely because a name-adjacent
        // phrase says nothing about tenure. So the advertiser emits no signal, adds no confidence
        // and cannot out-rank a label: every tier below runs unchanged, against a stricter profile.
        $source = LandlordRegistry::effectiveProfile($source, $listing->advertiser, $this->profileFor);

        try {
            $signals = [
                1 => $this->structuredFieldSignals($listing),
                2 => $this->labelSignals($listing),
                3 => $this->proceduralSignals($listing),
                4 => $this->plafondSignals($listing),
            ];
        } catch (MalformedText $e) {
            // NOT swallowed into an empty result — that is the defect this replaces. The listing
            // becomes visibly undetermined, with a reason naming the encoding, so it surfaces in
            // the "à vérifier" digest instead of vanishing or matching on the source default.
            return $this->verdict(
                Tenure::UNKNOWN,
                0,
                [new TenureSignal(
                    tier: 0,
                    tenure: Tenure::UNKNOWN,
                    reason: 'texte illisible : ' . $e->getMessage(),
                    evidence: 'malformed-text',
                )],
                $source,
                $listing->detailRead,
            );
        }

        $signals[2] = $this->dropConventionneWhenIntermediateIsStated($signals[2], Text::fold($listing->text()));

        // ── Doubts are separated from evidence before anything competes ──────────────────────────
        // An "indécidable" acronym marker (an uppercase PLUS/PLS in a financing collocation that is
        // followed by an ordinary word) is NOT a tenure claim. It used to be emitted as a tier-2
        // signal and then resolved positionally against real labels, which was wrong in both
        // directions and neither was visible to the suite:
        //
        //   - when it LOST the position race it vanished entirely — not an objection (UNKNOWN is
        //     not excluded) and not a contradiction (score() skips same-tier signals) — so
        //     `Loyer intermédiaire … LOGEMENT PLS MODERNE` was notified while the same two
        //     sentences in the opposite order digested. Identical facts, different word order.
        //   - when it WON it masked a determinate label, downgrading an explicit PLAI from REJECT
        //     to a user-visible digest entry.
        //
        // A doubt now competes with nothing. It cannot beat evidence and it cannot be beaten by it;
        // it simply withholds a verdict that would otherwise be a match.
        $doubts = [];

        foreach ($signals as $tier => $tierSignals) {
            $signals[$tier] = array_values(array_filter(
                $tierSignals,
                static function (TenureSignal $s) use (&$doubts): bool {
                    if ($s->tenure === Tenure::UNKNOWN) {
                        $doubts[] = $s;

                        return false;
                    }

                    return true;
                },
            ));
        }

        // Tier 5 is consulted ONLY when nothing else fired — `CLAUDE.md`: "Source default — lowest
        // confidence, used only when nothing else fires." A doubt counts as something having
        // fired: falling back to the source default there would notify the very listing the doubt
        // was raised about.
        $signals[5] = (array_filter($signals) === [] && $doubts === [])
            ? $this->sourceDefaultSignals($source)
            : [];

        /** @var list<TenureSignal> $evidence */
        $evidence = array_merge(...array_values($signals));
        /** @var list<TenureSignal> $flat */
        $flat = [...$evidence, ...$doubts];

        if ($evidence === []) {
            // Either nothing at all, or nothing but doubt. Both are undetermined.
            return $this->verdict(Tenure::UNKNOWN, 0, $flat, $source, $listing->detailRead);
        }

        $winningTier = min(array_keys(array_filter($signals)));
        $winner = $this->resolve($signals[$winningTier]);

        $confidence = $this->score($winningTier, $winner->tenure, $evidence);

        // ── The conflict rule ────────────────────────────────────────────────────────────────────
        // Asymmetric on purpose: it can only ever move a verdict toward withholding. An EXCLUDED
        // winner is never softened — not by a contradicting eligible signal, and not by a doubt.
        if ($winner->tenure->isEligible()) {
            $objections = array_values(array_filter(
                $evidence,
                static fn (TenureSignal $s): bool => $s->tenure->isExcluded(),
            ));

            if ($objections !== [] || $doubts !== []) {
                $against = array_map(
                    static fn (TenureSignal $s): string => $s->evidence,
                    [...$objections, ...$doubts],
                );

                $flat = [...$flat, new TenureSignal(
                    tier: $winningTier,
                    tenure: Tenure::UNKNOWN,
                    reason: sprintf(
                        'conflit : verdict %s contredit ou fragilisé par %d signal(s) (%s) — mis en attente de vérification',
                        $winner->tenure->value,
                        count($against),
                        implode(', ', $against),
                    ),
                    evidence: 'conflict-rule',
                    position: $winner->position,
                )];

                return $this->verdict(Tenure::UNKNOWN, 0, $flat, $source, $listing->detailRead);
            }
        }

        return $this->verdict($winner->tenure, $confidence, $flat, $source, $listing->detailRead);
    }

    /**
     * Every exit from {@see classify()} goes through here, and that is the point.
     *
     * Until 2026-08-06 the two fail-closed paths — no evidence at all, and the conflict rule —
     * constructed their own `Classification` with a hard-coded DIGEST, which made {@see route()}'s
     * undetermined-tenure arm unreachable. `tests/sabotage-check.sh` caught it: that arm could be
     * deleted outright with the suite staying green, because nothing ever reached it. Dead safety
     * code is worse than none — it reads as protection while protecting nothing.
     *
     * @param list<TenureSignal> $signals
     */
    private function verdict(
        Tenure $tenure,
        int $confidenceBp,
        array $signals,
        SourceProfile $source,
        bool $detailRead,
    ): Classification
    {
        // The §1 fail-closed rule, applied to the TENURE and not merely to the routing.
        // `spec/PROJECT_BRIEF.md` §4 requires the verdict itself to become UNKNOWN, withholding
        // the notification rather than merely downgrading its priority — so a caller
        // reading `$classification->tenure` has to see UNKNOWN, or the two halves of the object
        // disagree and the next module to be written will believe the eligible one.
        // `!$listing->detailRead` — the fail-closed rule fires while the EVIDENCE is still weak, and
        // reading the listing's own detail page is the step that gathers it.
        //
        // In'li forced this. It shipped `mixed_tenure: false` on the claim of being pure LLI, so its
        // listings matched on the source default alone at 50bp with this rule disarmed — and
        // hydration then found two live listings stating `plafond de ressources PLS`, declared
        // NOWHERE but the detail page. The premise was false.
        //
        // Simply arming the flag is not the fix: 166 of In'li's 168 listings sit at 50bp, so every
        // one of them — about two thirds of the tree's entire yield — would digest for ever. That is
        // not §1 being satisfied, it is the tool being switched off, and a tool nobody reads
        // protects nobody.
        //
        // So the rule reads the evidence rather than the source: unread page, weak label, mixed
        // source → digest. Page read and it said nothing excluding → that is evidence of absence on
        // a source whose social stock DOES declare itself, and the source default stands. An
        // explicit exclusion is caught by the label rules above at 90bp, which never consulted this
        // flag and still do not — see testReadingTheDetailPageNeverLicensesAnExcludedListing.
        //
        // A source with no `detail_map` never hydrates, so `detailRead` is always false there and
        // nothing about its behaviour changes.
        if ($tenure->isEligible()
            && $confidenceBp < self::FLOOR_BP
            && $source->mixedTenure
            && !$detailRead) {
            $signals = [...$signals, new TenureSignal(
                tier: 0,
                tenure: Tenure::UNKNOWN,
                reason: sprintf(
                    'confiance %d < %d sur « %s », source à parc mixte (social et intermédiaire) — '
                    . 'tenure non établie, mise en attente de vérification',
                    $confidenceBp,
                    self::FLOOR_BP,
                    $source->name,
                ),
                evidence: 'fail-closed',
            )];

            $tenure = Tenure::UNKNOWN;
            $confidenceBp = 0;
        }

        return new Classification($tenure, $confidenceBp, $signals, $this->route($tenure, $confidenceBp, $source, $detailRead));
    }

    /**
     * Tenure + confidence + source → what the pipeline does with it.
     *
     * The one place the fail-closed rule is applied, so no caller can re-derive it slightly
     * differently.
     */
    private function route(Tenure $tenure, int $confidenceBp, SourceProfile $source, bool $detailRead): Outcome
    {
        if ($tenure->isExcluded()) {
            return Outcome::REJECT;
        }

        if ($tenure === Tenure::UNKNOWN) {
            return Outcome::DIGEST;
        }

        if ($confidenceBp >= self::FLOOR_BP) {
            return Outcome::MATCH;
        }

        // `CLAUDE.md` §1, verbatim: below the floor AND a source known to mix social with
        // intermediate stock means the tenure is not established. It goes to the "à vérifier"
        // digest. On a source that publishes no social stock at all there is nothing to confuse it
        // with, so a thin signal is still good enough.
        //
        // `$detailRead` is the third term, and it is about EVIDENCE rather than about the source.
        // A listing whose own detail page has been read and found to say nothing excluding has been
        // examined; one whose page has never been fetched has not, and the two must not be treated
        // alike merely because the card text came out the same length. The reasoning, the In'li
        // measurements that forced it and the counterweight are on the fail-closed rule above —
        // both terms have to agree or a listing would digest here after passing there.
        return $source->mixedTenure && !$detailRead ? Outcome::DIGEST : Outcome::MATCH;
    }

    /**
     * Confidence: the winning tier's base, adjusted by every other signal.
     *
     * Integer arithmetic throughout — see {@see Classification}.
     *
     * @param list<TenureSignal> $all
     */
    private function score(int $winningTier, Tenure $winner, array $all): int
    {
        $bp = self::TIER_BASE[$winningTier];

        foreach ($all as $signal) {
            if ($signal->tier === $winningTier) {
                continue;
            }

            $bp += $signal->tenure->agreesWith($winner) ? self::CORROBORATION_BP : -self::CONTRADICTION_BP;
        }

        return max(self::BASEMENT_BP, min(self::CEILING_BP, $bp));
    }

    /**
     * Pick one signal from a tier: earliest position wins; on a tie, the longer (more specific)
     * evidence wins.
     *
     * Deterministic by construction — never dependent on the iteration order of a pattern table —
     * because the phorj implementation has to reach the same answer on the same fixture.
     *
     * @param non-empty-list<TenureSignal> $tierSignals
     */
    private function resolve(array $tierSignals): TenureSignal
    {
        // The strlen terms were transposed until 2026-08-06, so the SHORTER evidence won a tie —
        // the opposite of what this docblock says, and reachable: two structured fields each match
        // at offset 0 within their own value, so `{financement: LLI, categorie: PLAI}` was decided
        // by `lli` being three characters. A phorj port written from the docblock would have
        // disagreed with this implementation on exactly that input.
        usort($tierSignals, static fn (TenureSignal $a, TenureSignal $b): int
            => [$a->position, -strlen($a->evidence)] <=> [$b->position, -strlen($b->evidence)]);

        return $tierSignals[0];
    }

    /**
     * Tier 1 over the structured fields, AND over the listing strings no rule used to read.
     *
     * @return list<TenureSignal>
     */
    private function structuredFieldSignals(RawListing $listing): array
    {
        $out = [];

        // THE PROPERTIES `text()` DOES NOT RETURN. `RawListing` presents `url`, `commune`,
        // `postcode` and `externalId` besides its title and description, and no rule read any of
        // them — a review found all 21 excluded literals reaching MATCH on each. `url` is the one
        // that matters: `https://bailleur.test/logement-social/plai/t3-cergy` is the ordinary slug
        // shape of a landlord portal and arrives verbatim. A DOUBT rather than a verdict, for the
        // same reason as an unrecognised field: a slug is not a declaration, and `commune` could
        // legitimately be a place whose name collides.
        foreach (['url' => $listing->url, 'commune' => $listing->commune,
                  'postcode' => $listing->postcode, 'externalId' => $listing->externalId] as $what => $text) {
            if ($text === null || $text === '') {
                continue;
            }

            $found = $this->excludedVocabularyIn($text);

            if ($found !== null && $found !== self::UNREADABLE) {
                $out[] = new TenureSignal(
                    tier: 1,
                    tenure: Tenure::UNKNOWN,
                    reason: sprintf('%s de l\'annonce contient « %s » — à vérifier', $what, $found),
                    evidence: $found . '?',
                );
            }
        }

        foreach ($listing->fields as $name => $value) {
            // `_text` IS NOT A FIELD. It is the adapter's prose surface, and `RawListing::text()`
            // now returns it, so every prose rule in this class already reads it — case-respecting
            // acronym handling, collocation context, the `sans` negation, the lot. Scanning it here
            // as well would re-apply the identifier discipline this loop uses, which is what turned
            // the adverb `plus` into the acronym `PLUS`. Skipping it is a RE-ROUTE, not a
            // narrowing: `testExcludedVocabularyInTheCardTextIsStillCaught` pins that a social
            // badge, `PLAI`, an in-context `PLUS` and a `numéro unique` in card text still never
            // reach a match.
            if ($name === '_text') {
                continue;
            }

            // `title` and `description` ARE PROSE TOO, and an adapter may hand us a copy of them.
            // `ListingMapper` passes the WHOLE structured surface as `fields`, so a `type: html`
            // source's mapped description arrives twice: as the property, which `RawListing::text()`
            // reads with the prose rules, and as a bare `description` key, which this loop was
            // reading with the identifier discipline. The second reading is the one that turns the
            // adverb `plus` into the acronym `PLUS` — measured on live In'li data, where 4 of 40
            // hydrated listings were demoted to the digest by `de plus de 20 m²` and `encore plus
            // d'espace`. In'li states no explicit label, so that doubt was the only tier-1 signal
            // present and it decided the verdict.
            //
            // Guarded on CONTAINMENT, not on the name alone: the skip is only sound if the prose
            // scanner really did see this text. A field called `description` whose value is NOT part
            // of `text()` is some other adapter's field and keeps its scan, so this cannot become a
            // named hole. Same re-route-not-narrowing shape as `_text` above, and the counterweight
            // is testARealExclusionInTheDescriptionStillFailsClosed: an explicit PLS in a
            // description is still a reject.
            if (($name === 'title' || $name === 'description')
                && \is_string($value)
                && $value !== ''
                && str_contains($listing->text(), $value)) {
                continue;
            }

            // ── Checks that apply to EVERY field, before the recognised/unrecognised split ──────
            //
            // Both of these used to sit inside the recognised branch, and both were empty cells in
            // the surface matrix. `tests/php/Rent/Core/SurfaceMatrixTest.php` now enumerates the cross
            // product of the excluded vocabulary and every surface a listing has, so a rule that
            // reaches one field kind and not the other is a failing test rather than a review
            // finding — which is how the previous four rounds each found one of these.

            // 1. AN UNREADABLE VALUE IS A DOUBT ON ANY FIELD. Annotated `array<string,string>`, and
            //    an annotation is not a runtime guarantee: a JSON feed with
            //    `"gamme": ["PLUS","PLAI"]` decodes to a list and an adapter forwarding it verbatim
            //    arrives here. The recognised branch raised a doubt; the unrecognised branch
            //    silently dropped it, so a spelled-out PLAI in `gamme` NOTIFIED at confidence 50
            //    with `reasons[]` asserting "aucun signal dans l'annonce". Hard rule 3: a breakage
            //    is never an absence — and the unrecognised branch is MORE exposed, because its
            //    whole reason to exist is that the recognised list is closed.
            if (!is_scalar($value) && !$value instanceof \Stringable && $value !== null) {
                $out[] = new TenureSignal(
                    tier: 1,
                    tenure: Tenure::UNKNOWN,
                    reason: sprintf(
                        'champ structuré %s illisible (%s) — à vérifier',
                        self::oneLine((string) $name),
                        get_debug_type($value),
                    ),
                    evidence: '?',
                );

                continue;
            }

            // 2. THE FIELD NAME IS EVIDENCE TOO, and no rule read it — it was only ever exact-matched
            //    against `TENURE_FIELDS`. `numeroUnique` and `demandeLogementSocial` are ordinary
            //    bailleur-social JSON keys and both are literal `PROCEDURAL` entries; a listing
            //    carrying either matched at 50 with the name unread. A doubt rather than the tenure,
            //    for the same reason as an unrecognised value: a key is not a declaration.
            // A FOLD FAILURE ON ONE FIELD IS A DOUBT ON THAT FIELD, not a dead listing.
            //
            // `Text::fold()` refuses an undecoded HTML entity or non-UTF-8 input, which is right for
            // the title and description: an entity inside a multi-word label deletes that label and
            // leaves the others standing. Since this class began reading EVERY field, that gate ran
            // on URLs and surface cells too — and `&amp;` in an href is the ordinary output of an
            // HTML scrape, so one link turned the whole listing into UNKNOWN/DIGEST. Worse, it
            // SOFTENED determinate rejections: a description saying PLAI went from REJECT to DIGEST
            // because an unrelated field was encoded, which the class doctrine forbids outright.
            //
            // Caught per field, so the breakage is recorded where it happened and the rest of the
            // listing is still classified. Hard rule 3 without the collateral.
            $nameSignal = $this->excludedVocabularyIn((string) $name);

            if ($nameSignal === self::UNREADABLE) {
                $nameSignal = null;            // an unreadable NAME is reported by the value branch
            }

            if ($nameSignal !== null) {
                $out[] = new TenureSignal(
                    tier: 1,
                    tenure: Tenure::UNKNOWN,
                    reason: sprintf(
                        'le NOM du champ « %s » contient « %s » — à vérifier',
                        self::oneLine((string) $name),
                        $nameSignal,
                    ),
                    evidence: $nameSignal . '?',
                );
            }

            if (!in_array(Text::fieldKey((string) $name), self::TENURE_FIELDS, true)) {
                // NOT A TENURE FIELD — but an excluded label sitting in one is still §1 evidence,
                // and skipping outright made `TENURE_FIELDS` a closed list standing between a
                // spelled-out `PLAI` and a notification. `typeFinancement` is in the list and
                // `financementType` is not, so one word order rejected at 97 and the other matched
                // at 50; so did `gamme`, `programme` and `nature`. Fields are not part of
                // `RawListing::text()`, so there was no prose fallback either. That is the same
                // closed-list failure this class has now been bitten by three times — in the
                // COLLOCATION nouns twice, and here in the field NAMES.
                //
                // A DOUBT rather than the tenure itself, deliberately. The field name is unknown, so
                // the value may be prose rather than a financing declaration — `commentaire: "pas de
                // PLAI ici"` must not become a silent REJECT. Digesting says what was seen and lets
                // a human settle it, which is the fail-closed direction without the over-rejection.
                $unknownFieldDoubt = $this->excludedVocabularyIn($value);

                if ($unknownFieldDoubt === self::UNREADABLE) {
                    // Decoding could not repair it, so this surface was never scanned. Silence here
                    // would be the breakage-as-absence hard rule 3 forbids.
                    $out[] = new TenureSignal(
                        tier: 1,
                        tenure: Tenure::UNKNOWN,
                        reason: sprintf(
                            'champ « %s » illisible même après décodage — à vérifier',
                            self::oneLine((string) $name),
                        ),
                        evidence: '?',
                    );
                } elseif ($unknownFieldDoubt !== null) {
                    $out[] = new TenureSignal(
                        tier: 1,
                        tenure: Tenure::UNKNOWN,
                        reason: sprintf(
                            'champ « %s » (non reconnu comme champ de financement) contient « %s » '
                            . '— à vérifier',
                            self::oneLine((string) $name),
                            $unknownFieldDoubt,
                        ),
                        evidence: $unknownFieldDoubt . '?',
                    );
                }

                continue;
            }

            $raw = (string) $value;

            // An EMPTY field value is an absent signal, not a verdict. Sources that emit the key
            // unconditionally would otherwise fire tier 1 on every listing they publish.
            $folded = Text::fold($raw);
            if ($folded === '') {
                continue;
            }

            foreach ($this->matchLabels($folded) + $this->fieldAcronymSignals($raw) as $position => $hit) {
                // The third caller of `matchLabels()`, and the one the eligible-may-not-span rule
                // was not carried to — `labelSignals()` and `proceduralSignals()` got it, this did
                // not. A multi-line field value therefore assembled an eligible label across its own
                // newline at confidence 97, the highest this system produces and comfortably above
                // the floor, so the fail-closed arm never engaged — while the identical fragments in
                // prose digested. Same asymmetry as everywhere else: an EXCLUDED label may span,
                // because failing to match it is the §1 fail-open.
                if (isset($hit['matched'])
                    && $hit['tenure']->isEligible()
                    && str_contains($hit['matched'], "\n")) {
                    continue;
                }

                $out[] = new TenureSignal(
                    tier: 1,
                    tenure: $hit['tenure'],
                    reason: $hit['tenure'] === Tenure::UNKNOWN
                        ? sprintf(
                            'champ structuré %s = « %s » : « %s » y est indécidable — à vérifier',
                            (string) $name,
                            self::oneLine($raw),
                            $hit['literal'],
                        )
                        : sprintf('champ structuré %s = « %s »', (string) $name, self::oneLine($raw)),
                    evidence: $hit['literal'],
                    position: $position,
                );
            }
        }

        return $out;
    }

    /** @return list<TenureSignal> */
    private function labelSignals(RawListing $listing): array
    {
        $folded = Text::fold($listing->text());
        $cased = Text::foldPreserveCase($listing->text());
        $out = [];

        foreach ($this->matchLabels($folded) as $position => $hit) {
            $matched = $hit['matched'] ?? $hit['literal'];

            // Same asymmetry as the procedural tells: an ELIGIBLE label may not be assembled across
            // a phrase boundary, because that manufactures eligibility from two unrelated
            // fragments — a title ending `… - Loyer` and a description opening `intermediaire, …`
            // is not a listing that says `loyer intermediaire`. An EXCLUDED label spanning one is
            // kept, because failing to match it is the §1 fail-open and a line-wrapped
            // `logement social` in a `text/plain` alert body is the primary ingestion path.
            if ($hit['tenure']->isEligible() && str_contains($matched, "\n")) {
                continue;
            }

            $out[] = new TenureSignal(
                tier: 2,
                tenure: $hit['tenure'],
                reason: sprintf('mention explicite « %s » dans le texte', self::oneLine($matched)),
                evidence: $hit['literal'],
                position: $position,
                length: strlen($matched),
            );
        }

        foreach (self::AMBIGUOUS_LABELS as $acronym => $tenure) {
            $hit = $this->financingAcronymPosition($cased, $acronym);

            if ($hit === null) {
                // THE SAME DOUBT FLOOR THE FIELDS GOT, on the surface that was left behind.
                //
                // Round 5 gave structured fields a floor because the COLLOCATION noun list is
                // closed and `financement: "Prêt PLUS"` was therefore notified at confidence 50 with
                // `reasons[]` saying "aucun signal dans l'annonce". Round 6 pointed out that the
                // list is just as closed in prose, and every value named in that fix —
                // `Logements financés en PLUS`, `Programme agréé PLUS`, `Gamme PLUS` — MATCHED when
                // written in the description instead. `financé en PLS` rejected while
                // `financé en PLUS` matched, on the same sentence.
                //
                // CASE-SENSITIVE here, unlike the field path. In a field, capitalisation is the
                // feed's house style and carries nothing. In prose it is real evidence: `plus de 3
                // chambres` is the adverb and nothing else, and a case-insensitive floor would
                // digest a large fraction of the Paris market. Only a SHOUTED `PLUS` that no
                // comparative explains becomes a doubt.
                $doubtAt = $this->firstNonComparativeOccurrence($cased, $acronym, caseInsensitive: false);

                if ($doubtAt !== null) {
                    $out[] = new TenureSignal(
                        tier: 2,
                        tenure: Tenure::UNKNOWN,
                        reason: sprintf(
                            '« %s » en majuscules dans le texte, sans contexte de financement '
                            . 'reconnu ni comparatif : indécidable — à vérifier',
                            $acronym,
                        ),
                        evidence: $acronym . '?',
                        position: $doubtAt,
                    );
                }

                continue;
            }

            [$position, $confident] = $hit;

            $out[] = $confident
                ? new TenureSignal(
                    tier: 2,
                    tenure: $tenure,
                    reason: sprintf('acronyme de financement « %s » en contexte', $acronym),
                    evidence: $acronym,
                    position: $position,
                )
                : new TenureSignal(
                    tier: 2,
                    tenure: Tenure::UNKNOWN,
                    reason: sprintf(
                        '« %s » en majuscules après un mot de financement, mais suivi d\'un mot : '
                        . 'label de financement ou adverbe, indécidable — à vérifier',
                        $acronym,
                    ),
                    evidence: $acronym . '?',
                    position: $position,
                );
        }

        return $out;
    }

    /**
     * `CLAUDE.md` glossary: conventionné is treated as social "unless explicitly labelled
     * **intermediate**". When such a label is present, a bare `conventionne` stops being evidence —
     * LLI stock genuinely is conventionné, and leaving the signal in would send a
     * correctly-identified LLI listing to the digest through the conflict rule.
     *
     * TIER 2 ONLY. A `financement: LLI` structured field does NOT qualify a `conventionné` in the
     * body text, and the history of that decision is worth keeping because it reversed:
     *
     * - Round 3 made tier 1 qualify too, on the ladder argument that a structured field is the
     *   strongest evidence there is. That is true about the FIELD and irrelevant here — this
     *   exception is about one French construction, an adjective qualifying the noun it follows,
     *   and a field is not adjacent to anything in the text.
     * - Round 4 showed what round 3 bought: a description reading *"40 logements conventionnés
     *   réservés aux ménages sous plafond, et 12 logements en location"* MATCHED on the strength of
     *   one field, with `conventionne` absent from `reasons[]`. The exemption was removed outright
     *   rather than bounded a third time — both bounding attempts leaked, always toward notifying.
     *
     * It also tested `isEligible()`, i.e. LLI **or LIBRE**, until round 3. LIBRE is not an
     * intermediate label, so a spurious LIBRE signal disarmed the exclusion — and combined with
     * `logement libre` then being in the label table, a listing whose own title read *"Logement
     * conventionné"* reached MATCH. Only an LLI label qualifies.
     *
     * `conventionne anah` / `conventionne anru` are named regimes; this exception never applies to them.
     *
     * @param list<TenureSignal> $labelSignals tier-2 signals only, by the rule above
     * @param string             $folded       `Text::fold($listing->text())`, whose byte offsets the
     *                                         signals' `position`/`length` index into
     *
     * @return list<TenureSignal>
     */
    private function dropConventionneWhenIntermediateIsStated(array $labelSignals, string $folded): array
    {
        return array_values(array_filter(
            $labelSignals,
            function (TenureSignal $s) use ($labelSignals, $folded): bool {
                if ($s->tenure !== Tenure::CONVENTIONNE || $s->evidence !== 'conventionne') {
                    return true;
                }

                // The exception applies to ONE French construction and nothing else: an
                // intermediate label IMMEDIATELY FOLLOWED by `conventionné`, the adjective
                // qualifying the noun it comes after — `logement intermédiaire conventionné`.
                //
                // This is the third attempt, and the first two are why it is now this narrow.
                //   v1 dropped the signal whenever ANY LLI label appeared anywhere, so
                //      "logements conventionnés et intermédiaires" — a mixed résidence — MATCHED.
                //   v2 bounded it to a 3-CHARACTER window, which `et`/`ou` (4 chars) cleared but
                //      every punctuation separator did not: "Logement conventionné, loyer
                //      intermédiaire disponible." MATCHED through a comma.
                // Both had the same shape: deleting an excluded signal biases toward NOTIFYING,
                // the one direction §1 forbids, and does it invisibly because the word never
                // reaches `reasons[]`.
                //
                // Direction matters and v2 ignored it. `conventionné` BEFORE the label is a
                // separate noun phrase; only `conventionné` AFTER it is the qualifier. And the
                // span compared is the MATCHED text, not the table literal — v2 used
                // `strlen($evidence)`, so the ordinary plural `logements locatifs intermédiaires
                // conventionnés` overshot its own window by one character and silently digested.
                //
                // Direction is enforced BY THE SPAN ARITHMETIC, not by a separate test. An explicit
                // `$other->position > $s->position → skip` sat here until sabotage-verification
                // showed it could be deleted with the suite staying green: `$between` is
                // `position + length` and length is never negative, so a label that starts after
                // `conventionné` always ends after it too and fails `$between <= $s->position`
                // anyway. Unreachable safety code is worse than none — it reads as a guarantee
                // while contributing nothing, and the phorj port would have copied it.
                foreach ($labelSignals as $other) {
                    if ($other->tenure !== Tenure::LLI) {
                        continue;
                    }

                    $between = $other->position + $other->length;

                    // `[ (\[«"']`, NOT `[\s…]`. A NEWLINE MUST NOT QUALIFY, because it is the
                    // title/description boundary that `RawListing::text()` inserts — and a boundary
                    // is a stronger phrase break than the comma that already blocks this rule.
                    // With `\s` here, a title ending `Logement intermédiaire` excused a description
                    // opening `Conventionné, réservé aux ménages sous plafond`: MATCH, with the word
                    // absent from `reasons[]`. `Text::foldPreserveCase()` guarantees the only
                    // whitespace bytes that reach this point are ' ' and "\n", so the class is
                    // exhaustive rather than merely narrower.
                    // `\z`, NOT `$`. PCRE's `$` matches before a FINAL NEWLINE as well as at the
                    // end of the subject, so `/^[ ]*$/` accepts the single "\n" that IS the field
                    // boundary — the exact string this class was narrowed to reject. The narrowing
                    // looked correct and changed nothing until the anchor was fixed too.
                    if ($between <= $s->position
                        && $this->matches('/^[ (\[«"\']*\z/u', substr($folded, $between, $s->position - $between))) {
                        return false;
                    }
                }

                return true;
            },
        ));
    }

    /**
     * Tier 3 over the free text AND over every structured field value.
     *
     * FIELDS ARE INCLUDED, and their omission was a §1 hole. `proceduralSignals()` read only
     * `RawListing::text()`, and fields are not part of it — so `dispositif: "Attribution par
     * commission d'attribution"` produced no signal at all and the listing matched on the source
     * default, while the identical phrase in the description rejected at 80. None of the five
     * social procedural literals is also in `LABELS`, so the tier-1 field scan could not see them
     * either: the surface was blind to every one of them at once.
     *
     * EACH SURFACE IS SCANNED SEPARATELY rather than concatenated, and the first version of this
     * method got that wrong in a way its own docblock denied. It appended field values after a
     * newline and claimed *"an inflection or a `sans` negation cannot straddle the join between a
     * description and a field, or between two fields"*. False:
     * {@see Text::inflectedTokenPosition()} joins a literal's words with `\s*` (for an excluded literal
     * a WIDER gap that still contains `\s`), which matches a newline, and only ELIGIBLE tells were
     * blocked from spanning. So a description ending
     * *"…aucune commission"* and the next field opening *"Attribution directe par le bailleur"* —
     * the brief's canonical INTERMEDIATE tell — assembled into the social `commission attribution`
     * and hard-REJECTED. `REJECT` is silent by design, so nothing arrived and nothing said why.
     *
     * Scanning per surface removes the class rather than patching the separator: two fragments in
     * two different surfaces can no longer become one literal, whatever the separator is.
     *
     * KNOWN AND LEFT: the same assembly is still possible ACROSS THE TITLE/DESCRIPTION JOIN, which
     * is one surface by construction (`RawListing::text()`). Closing it would mean forbidding a
     * literal to span any newline, and that breaks the case round 6 protected — a line-wrapped
     * `logement social` in a `text/plain` alert body, which `CLAUDE.md` hard rule 4 makes the
     * PRIMARY ingestion path. The residue over-rejects, which is the safe direction, and it is
     * stated here rather than denied.
     *
     * @return list<TenureSignal>
     */
    private function proceduralSignals(RawListing $listing): array
    {
        $out = [];

        foreach ($this->proceduralSurfaces($listing) as $folded) {
            foreach ($this->proceduralSignalsIn($folded) as $signal) {
                $out[] = $signal;
            }
        }

        return $out;
    }

    /**
     * The free text, then each readable field value — each its own string.
     *
     * @return list<string>
     */
    private function proceduralSurfaces(RawListing $listing): array
    {
        $surfaces = [Text::fold($listing->text())];

        foreach ($listing->fields as $value) {
            if (!is_scalar($value) && !$value instanceof \Stringable) {
                continue;              // structuredFieldSignals() raises the unreadable doubt
            }

            // TOLERANT: an undecoded `&amp;` in one field must not delete the procedural evidence in
            // every other field, nor kill the listing. See `Text::foldTolerant()`.
            $folded = Text::foldTolerant((string) $value);

            // `null` and `''` are both skippable here, and the reason for `null` is NOT the one this
            // comment used to give. It claimed `structuredFieldSignals()` raises the unreadable
            // doubt for `null` — it does not: that branch reads
            // `!is_scalar($value) && !$value instanceof \Stringable && $value !== null`, which
            // excludes `null` on purpose. A review panel checked it on 2026-08-24 and found a
            // `financement => null` field classifying LLI/MATCH at 90 on the strength of the title
            // alone, exactly as it does with the field absent.
            //
            // That is the DECIDED behaviour, not an oversight: an empty field value is an absent
            // signal, ruled below, and a key whose value is empty says nothing about tenure by
            // itself. What was wrong was the justification, and a docblock asserting a §1 guard
            // that is not there is worse than one that says nothing — it is the sentence a future
            // reader will trust instead of re-checking.
            //
            // And a surface with NO LETTER carries no procedural literal either — same invariant as
            // the fast path in `excludedVocabularyIn()`, same reflection test guarding it, and the
            // same rule about placement: `$folded` is post-decode, so an entity-encoded
            // `numéro unique` still arrives here as letters. Rents, surfaces, dates and references
            // are what this actually drops, and on a `json` source they are a third of the record.
            if ($folded !== null && $folded !== '' && $this->matches('/\p{L}/u', $folded)) {
                $surfaces[] = $folded;
            }
        }

        return $surfaces;
    }

    /** @return list<TenureSignal> */
    private function proceduralSignalsIn(string $folded): array
    {
        $out = [];

        foreach (self::PROCEDURAL as $literal => $tenure) {
            // Inflected for the same reason as the labels: `commissions d'attribution`,
            // `numéros uniques` and `attributions directes` are all ordinary phrasings.
            $hit = Text::inflectedTokenPosition($folded, $literal, !$tenure->isEligible());

            if ($hit === null) {
                continue;
            }

            [$position, $matched] = $hit;

            if (in_array($literal, self::NEGATED_BY_SANS, true) && $this->isPrecededBySans($folded, $position)) {
                continue;
            }

            // An ELIGIBLE tell may not be assembled across a phrase boundary. `Text::
            // inflectedTokenPosition()` joins a multi-word literal with `\s*` (an eligible literal keeps
            // exactly that gap — the separator tolerance is for excluded ones only) so that an inflected
            // or line-wrapped phrase still matches — which is right for an EXCLUDED literal, where
            // failing to match is the §1 fail-open, and wrong for an eligible one, where matching
            // manufactures eligibility out of two unrelated fragments. A title ending `T3 Cergy
            // sans` and a description opening `Commission d'attribution le 12 mars` assembled into
            // the intermediate tell `sans commission d'attribution` and matched at 80.
            //
            // Asymmetric on purpose, exactly like the conflict rule: a wrapped `logement social`
            // must still be caught, so the restriction applies only in the direction that costs a
            // wasted application.
            if ($tenure->isEligible() && str_contains($matched, "\n")) {
                continue;
            }

            $out[] = new TenureSignal(
                tier: 3,
                tenure: $tenure,
                reason: sprintf('indice de procédure « %s »', self::oneLine($matched)),
                evidence: $literal,
                position: $position,
            );
        }

        return $out;
    }

    /**
     * `sans` must be on the SAME LINE — `\s` would let it reach across a phrase boundary.
     *
     * With `\s+`, a title ending in the word `sans` negated a procedural tell opening the
     * description: `T3 Cergy sans` + `Commission d'attribution le 12 mars` read as the intermediate
     * `sans commission d'attribution` and matched at 80. `\z` rather than `$` for the same reason
     * the adjacency rule needs it — PCRE's `$` also matches before a final newline.
     */
    private function isPrecededBySans(string $folded, int $position): bool
    {
        return $this->matches('/\bsans[^\S\n]+\z/u', substr($folded, 0, $position));
    }

    /**
     * Tier 4 — inert by construction. See {@see PlafondBands}.
     *
     * Two things must exist before this rung can fire: the ceiling figures per zone and household
     * size, and an extractor for the ceiling a listing quotes. Neither is in this repo, and
     * `CLAUDE.md` hard rule 1 forbids writing the figures from memory. The rung stays in the ladder
     * so the documented priority order is complete and auditable, and it emits nothing rather than
     * guessing — a tier that appears to work and is wrong would drop eligible listings in silence.
     *
     * @return list<TenureSignal>
     */
    private function plafondSignals(RawListing $listing): array
    {
        if ($this->bands->isEmpty()) {
            return [];
        }

        $out = [];

        foreach ($this->proceduralSurfaces($listing) as $folded) {
            foreach ($this->quotedCeilingsIn($folded) as [$amount, $position, $evidence]) {
                // The zone is IDF because nothing maps a commune to A bis / A / B1 yet, and IDF is
                // the safest threshold across all three. The per-zone table arms itself for free
                // the day such a map exists.
                $tenure = $this->bands->classifyCeiling($amount, PlafondBands::IDF);

                if ($tenure === null) {
                    // Inside the measured overlap: the figure could belong to either regime, so
                    // nothing is emitted. NOT a doubt — a tier-4 doubt would contradict a correct
                    // tier-2 label into the digest, which is what `loyer plafonné` did to `lli-004`
                    // and `lli-011` when it was tried as one.
                    continue;
                }

                $out[] = new TenureSignal(
                    tier: 4,
                    tenure: $tenure,
                    reason: 'plafond de ressources annoncé (' . $amount . ' €) sous tout plafond '
                        . 'intermédiaire d\'Île-de-France — financement social',
                    evidence: $evidence,
                    position: $position,
                );
            }
        }

        return $out;
    }

    /**
     * Annual income ceilings a listing states about itself.
     *
     * **EVERY guard here is a lesson this repo has already paid for**, and the extraction is where
     * the dangerous false positive lives — not in the band comparison.
     *
     * - **Anchored on `plafond de ressources`, never on a bare `plafond`.** This classifier's own
     *   vocabulary note records that `loyer plafonné` is *the primary target describing itself*: an
     *   intermediate ad saying "loyer plafonné à 1 250 €" would hand a bare-`plafond` reader a
     *   figure far below the threshold and get a real match rejected.
     * - **Read the NEGATION first.** `sans plafond de ressources` is an advertisement for the
     *   absence of the thing, and it is ordinary copy on private-market listings. Same rule as
     *   `Prose`'s lift reader, and the same direction: a manufactured signal is worse than none.
     * - **An annual-income magnitude floor.** Twin of the rent plausibility band. Without it a rent,
     *   a charge or a surface sitting near the anchor becomes an "income ceiling" of 1 250 €, which
     *   is below every threshold and therefore always reads SOCIAL.
     * - **Every match is examined, not just the first.** `preg_match` stopping at the first hit is
     *   what let one implausible figure hide a readable rent three lines below it on a SeLoger
     *   *baisse de prix* card.
     * - **`\h` for the thousands separator, never `\s`.** `\s` matches a newline, so a figure
     *   assembles itself across a line break out of two unrelated numbers — the defect that read a
     *   1 450 € rent as 850 €.
     *
     * @return list<array{int, int, string}> amount in euros, byte offset, matched evidence
     */
    private function quotedCeilingsIn(string $folded): array
    {
        // Folded text, so no accents and lower case: `plafond de ressources`, `plafonds de
        // ressources`, and the elided `plafond de ressource`.
        $anchor = 'plafonds?\h+(?:de\h+)?ressources?';

        if (preg_match_all(
            '/(?<neg>sans\h+|aucun\h+|pas\h+de\h+|hors\h+)?' . $anchor . '[^\d]{0,40}(?<amount>\d{1,3}(?:\h\d{3})+|\d{4,7})/u',
            $folded,
            $matches,
            PREG_OFFSET_CAPTURE | PREG_SET_ORDER,
        ) !== 1 && $matches === []) {
            return [];
        }

        $out = [];

        foreach ($matches as $match) {
            if (($match['neg'][0] ?? '') !== '') {
                // "sans plafond de ressources" states the ABSENCE of a ceiling. Reading the number
                // that follows would invent a signal out of its own negation.
                continue;
            }

            $amount = (int) str_replace(["\u{202f}", "\u{a0}", ' ', "\t"], '', $match['amount'][0]);

            if ($amount < self::PLAFOND_PLAUSIBLE_MIN_EUR) {
                // A rent, a charge, a surface or a reference number that happened to sit near the
                // anchor. Every one of those is below every threshold, so admitting them would make
                // the tier answer SOCIAL to noise.
                continue;
            }

            $out[] = [$amount, $match[0][1], trim($match[0][0])];
        }

        return $out;
    }

    /** Exposed so the suite can assert the tier is still inert. */
    public function plafondBands(): PlafondBands
    {
        return $this->bands;
    }

    /** @return list<TenureSignal> */
    private function sourceDefaultSignals(SourceProfile $source): array
    {
        if ($source->defaultTenure === null) {
            return [];
        }

        return [new TenureSignal(
            tier: 5,
            tenure: $source->defaultTenure,
            reason: sprintf(
                'aucun signal dans l\'annonce — défaut de la source « %s » (%s), confiance volontairement sous le seuil',
                $source->name,
                $source->defaultTenure->value,
            ),
            evidence: 'source-default',
        )];
    }

    /**
     * Every {@see LABELS} literal present, keyed by position.
     *
     * Keyed by position so that a longer literal starting at the same offset as a shorter one
     * replaces it — `conventionne anah` overwrites `conventionne` at the same index, which is the
     * longest-match-wins rule with no extra bookkeeping.
     *
     * @return array<int, array{tenure: Tenure, literal: string}>
     */
    private function matchLabels(string $folded): array
    {
        $hits = [];

        foreach (self::LABELS as $literal => $tenure) {
            // Inflected, not exact: French agreement and plurals are not optional decoration.
            // `conventionnée`, `logements sociaux` and `prêts locatifs sociaux` were all silent
            // non-matches while their singular masculine forms matched. See Text.
            $hit = Text::inflectedTokenPosition($folded, $literal, !$tenure->isEligible());

            if ($hit === null) {
                continue;
            }

            [$position, $matched] = $hit;
            $existing = $hits[$position] ?? null;

            if ($existing === null || strlen($literal) > strlen($existing['literal'])) {
                $hits[$position] = ['tenure' => $tenure, 'literal' => $literal, 'matched' => $matched];
            }
        }

        return $hits;
    }

    /**
     * Collapse a quoted fragment of the listing onto one line for `reasons[]`.
     *
     * `reasons[]` is the product's only user-facing output (`spec/PROJECT_BRIEF.md` §5) and it is
     * built from the text that ACTUALLY matched. Since folding began preserving newlines — so the
     * title/description boundary could act as a phrase break — that matched text can contain one:
     * `Text::inflectedTokenPosition()` joins a multi-word literal with `\s*` (or a wider gap that
     * contains it), so a label straddling
     * the join produced « logement\nintermediaire » in a notification. A hard-wrapped `text/plain`
     * IMAP alert body, which `CLAUDE.md` hard rule 4 makes the PRIMARY ingestion path, does it in
     * the middle of a sentence. Fixed at the formatting site rather than in the fold, because the
     * newline is load-bearing for the matching and inert for the rendering.
     */
    private static function oneLine(string $fragment): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $fragment));
    }

    /**
     * The first excluded-tenure literal named by an arbitrary string, or null.
     *
     * READS ALL THREE TABLES, and the previous version reading only `LABELS` was the round-7 P0.
     * `PLUS` is not in `LABELS` — it is the sole entry of `AMBIGUOUS_LABELS` — so the fix that
     * closed unrecognised fields for `PLAI`, `PLS`, `HLM` and `logement social` left open the one
     * acronym the whole bug class had been about: `gamme: "Prêt PLUS"` matched at 50 on a pure
     * source while `typeFinancement: "PLUS"` rejected at 97. `PROCEDURAL` is here for field NAMES
     * like `numeroUnique` and `demandeLogementSocial`, which are literal entries in it.
     *
     * Deliberately narrower than a full classification in two ways. Only EXCLUDED tenures count, so
     * an unrecognised field saying `LLI` cannot manufacture eligibility out of a key nobody
     * declared. And the result only ever raises a DOUBT — never decides a tenure — because such a
     * string may be prose: `commentaire: "pas de PLAI ici"` must not become a silent REJECT.
     *
     * Case-insensitive, via `Text::fold()`. That matches the field-value path and differs from the
     * prose path on purpose: prose is where `plus` is a French adverb, and a field key or an
     * unrecognised value is not prose enough to be worth the false negatives.
     *
     * Returns the MATCHED text, not the table literal — the same correction tier 2 needed, so a
     * digest entry quotes what the listing actually says rather than a lowercased singular the
     * reader will not find in it.
     */
    private function excludedVocabularyIn(mixed $value): ?string
    {
        if (!is_scalar($value) && !$value instanceof \Stringable) {
            return null;                       // callers raise the unreadable doubt themselves
        }

        // Case-PRESERVING and invisible-STRIPPED, which is the only form the identifier split can
        // safely run on. Splitting on `[^A-Za-z0-9]+` treats a soft hyphen as a separator, so
        // splitting the merely-decoded `plai<U+00AD>sir` yields the word `plai` and invents the
        // match this method spent two attempts learning not to invent.
        $cased = Text::foldTolerantPreserveCase((string) $value);
        $folded = Text::foldTolerant((string) $value);

        if ($folded === null) {
            // UNREADABLE, not empty. `Text::foldTolerant()` returns null only when decoding could
            // not repair the input, and reading that as "said nothing" is hard rule 3's exact
            // shape. The sentinel is distinguishable so the caller can raise a doubt.
            return self::UNREADABLE;
        }

        if ($folded === '') {
            return null;
        }

        // NO LETTER, NO LITERAL — the one provably-safe cut in this method's cost.
        //
        // Every literal in `LABELS`, `AMBIGUOUS_LABELS` and `PROCEDURAL` contains a letter, so a
        // value with none cannot match any of them, in any of the four passes below. Asserted by
        // reflection over the three tables themselves in
        // `TenureClassifierTest::testEveryVocabularyLiteralContainsALetter` — if that ever goes red
        // the fix is to DELETE this branch, not to relax the assertion.
        //
        // Worth doing because this method is called for every field NAME and every unrecognised
        // field VALUE, and a `json` source maps its whole raw record into `fields`: Logirep's
        // records carry 31 keys, of which 11 are rents, surfaces, dates and references. Classifying
        // one of its listings measured ~155 ms and its 113-row payload ~17 s, and the sabotage
        // ledger runs the whole suite some three hundred times.
        //
        // IT MUST STAY BELOW THE DECODE, and that is not a style preference. `&#80;&#76;&#65;&#73;`
        // contains no letter and decodes to `PLAI`; tested against the raw string this branch would
        // silently skip the one value §1 exists to catch, on nothing worse than a source that
        // numeric-entity-encodes its own payload. The two placements are indistinguishable in
        // review and every other test in the file feeds plain text, so
        // `testALetterlessValueThatDecodesToAnExcludedLabelIsStillCaught` is the only thing that
        // tells them apart. Do not move it up.
        //
        // NOT a narrowing of what is scanned, which is the move this class has been bitten by
        // repeatedly: nothing is excluded from the scan by name, kind or origin — only values in
        // which no possible match exists are skipped, and the reflection test is what keeps
        // "possible" honest.
        if (!$this->matches('/\p{L}/u', $folded)) {
            return null;
        }

        // TWO HAYSTACKS, because this surface carries IDENTIFIERS as well as prose.
        //
        //   $folded — the value as written. Catches `logement social`, `PLAI`, prose in a field.
        //   $split  — the same value with word boundaries restored at case and separator
        //             transitions, so `typePlai` becomes `type Plai` and `numeroSNE` becomes
        //             `numero SNE`. Built from the DECODED value, never the raw one: splitting
        //             `plai&shy;sir` on non-alphanumerics yields the word `plai` and invents a match
        //             inside *plaisir*, which is the silent drop `Text::hasToken()` exists to stop.
        $split = $cased === null ? '' : Text::fold((string) preg_replace(
            ['/([a-z0-9])([A-Z])/u', '/[^A-Za-z0-9]+/u'],
            ['$1 $2', ' '],
            $cased,
        ));

        foreach ([$folded, $split] as $haystack) {
            if ($haystack === '') {
                continue;
            }

            foreach ($this->matchLabels($haystack) as $hit) {
                if (!$hit['tenure']->isEligible()) {
                    return self::oneLine($hit['matched'] ?? $hit['literal']);
                }
            }

            foreach (self::PROCEDURAL as $literal => $tenure) {
                if ($tenure->isEligible()) {
                    continue;
                }

                $hit = Text::inflectedTokenPosition($haystack, $literal, true);

                if ($hit === null) {
                    continue;
                }

                // The `sans` negation applies here too. Without it, `commentaire: "Attribution sans
                // commission d'attribution"` — the brief's canonical INTERMEDIATE tell — digested,
                // while the identical sentence in the description matched at 80. Silent
                // over-rejection (hard rule 8), and the digest reason compounded it by reporting the
                // field "contient « commission d'attribution »" when the field says the negated form.
                if (in_array($literal, self::NEGATED_BY_SANS, true)
                    && $this->isPrecededBySans($haystack, $hit[0])) {
                    continue;
                }

                return self::oneLine($hit[1]);
            }

            // The ambiguous acronym, case-insensitively — `AMBIGUOUS_LABELS` is keyed UPPERCASE
            // because case is evidence in prose, and neither of these haystacks is prose.
            foreach (array_keys(self::AMBIGUOUS_LABELS) as $acronym) {
                if ($this->matches('/(?<![A-Za-z0-9])(?i:' . preg_quote($acronym, '/') . ')(?![A-Za-z0-9])/u', $haystack)) {
                    return $acronym;
                }
            }
        }

        // SEPARATOR-FREE containment, for MULTI-WORD literals only. `demandeLogementSocial` folds to
        // `demandelogementsocial`, which contains `logementsocial` — and neither haystack above sees
        // it, because the literal's connectives are simply absent from the identifier. This is the
        // pass that catches the two keys the field-name rule was written for and did not detect.
        $key = preg_replace('/[^a-z0-9]/u', '', $folded) ?? '';

        if ($key !== '') {
            foreach (self::vocabularyKeys() as $normalised => $literal) {
                if (!str_contains($key, $normalised)) {
                    continue;
                }

                // The `sans` negation, in the one form this pass can express it. Positions are gone
                // once separators are stripped, so the check is containment of the NEGATED key
                // rather than a lookbehind: `attributionsanscommissiondattribution` contains
                // `sanscommissiondattribution`. Without it, `commentaire: "Attribution sans
                // commission d'attribution"` — the brief's canonical INTERMEDIATE tell — digested
                // while the identical sentence in the description matched, and the digest reason
                // quoted the un-negated form back at a reader who had written the negated one.
                if (in_array($literal, self::NEGATED_BY_SANS, true)
                    && str_contains($key, 'sans' . $normalised)) {
                    continue;
                }

                return $literal;
            }
        }

        return null;
    }

    /**
     * Every excluded-or-undetermined literal, keyed by its separator-free form.
     *
     * Built from the same three tables the rest of this class uses, so a literal added tomorrow is
     * covered without anyone remembering to add it here.
     *
     * NOT FILTERED TO `isExcluded()`. `PROCEDURAL` holds `numero unique => Tenure::UNKNOWN`, a
     * deliberate DOUBT rather than a verdict — and a filter on `isExcluded()` skips it, so the
     * one key the field-name rule names in its own comment stayed invisible. §1 is about a listing
     * whose tenure never resolved reaching a notification, not only about an excluded verdict, so
     * the vocabulary is "everything that is not eligible".
     *
     * @return array<string, string>
     */
    private static function vocabularyKeys(): array
    {
        static $keys = null;

        if ($keys !== null) {
            return $keys;
        }

        $keys = [];

        foreach ([self::LABELS, self::AMBIGUOUS_LABELS, self::PROCEDURAL] as $table) {
            foreach ($table as $literal => $tenure) {
                if ($tenure->isEligible()) {
                    continue;
                }

                // MULTI-WORD ONLY. A single-word literal compared as a substring matches inside a
                // longer French word — `plai` inside *plaisir* — and pass (a) above already covers
                // single words with proper boundaries.
                if (!str_contains((string) $literal, ' ')) {
                    continue;
                }

                $normalised = preg_replace('/[^a-z0-9]/u', '', Text::fold((string) $literal)) ?? '';

                if (strlen($normalised) >= 8) {
                    $keys[$normalised] = (string) $literal;
                }
            }
        }

        // Longest first, so `demande de logement social` is reported rather than the `logement
        // social` inside it — the more specific literal is the more useful digest entry.
        uksort($keys, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $keys;
    }

    /**
     * The ambiguous acronym (`PLUS`, and only `PLUS`) inside a structured field value.
     *
     * THE DISCRIMINATOR IS THE SHAPE OF THE VALUE, NOT ITS CASE, and getting there took three
     * wrong answers — each recorded here because each looked obviously right at the time:
     *
     * 1. **No guard at all**, on the argument that a structured field "is not French prose". True
     *    of `financement: PLUS`, false of the generic keys in {@see TENURE_FIELDS}: `categorie`,
     *    `dispositif` and `typelogement` carry prose in real feeds, and `Pinel Plus` is a real 2023
     *    scheme name — so that value became tenure PLUS at confidence 97 and a silent REJECT.
     * 2. **Whole value must be financing tokens.** Fixed `Pinel Plus`, and FAILED OPEN: `PLUS CD`,
     *    a real financing code, matched nothing — no signal, no doubt — and since fields are not
     *    part of {@see RawListing::text()} there was no prose fallback either. The listing then
     *    matched on its description. The strongest rung of the ladder, blinder than the weakest.
     * 3. **Case decides**: uppercase is a code, lowercase is a word. Wrong in BOTH directions. A
     *    feed that lowercases its own field values (`financement: plus`) went silent on an
     *    explicitly social code, and `PINEL PLUS` — routinely shouted in a `categorie` — became a
     *    silent REJECT. Case is a house style, not evidence.
     *
     * So: a value made only of financing acronyms and short uppercase code fragments is a CODE
     * LIST, and its acronyms are determinate whatever their case. Anything else contains a real
     * word, which makes it PROSE, and prose goes through {@see financingAcronymPosition()} — the
     * same three-answer collocation guard the description gets, doubts included. There is no fourth
     * branch, and in particular no branch that returns silently on a value naming a scheme.
     *
     * @return array<int, array{tenure: Tenure, literal: string}>
     */
    private function fieldAcronymSignals(string $rawValue): array
    {
        $hits = [];
        $cased = Text::foldPreserveCase($rawValue);

        if ($this->isCodeList($cased)) {
            foreach (self::AMBIGUOUS_LABELS as $acronym => $tenure) {
                if ($this->matchOffset('/(?<![A-Za-z0-9])(?i:' . preg_quote($acronym, '/') . ')(?![A-Za-z0-9])/u', $cased, $m)) {
                    $hits[$m[0][1]] = ['tenure' => $tenure, 'literal' => $acronym];
                }
            }

            return $hits;
        }

        foreach (self::AMBIGUOUS_LABELS as $acronym => $tenure) {
            $hit = $this->financingAcronymPosition($cased, $acronym);

            if ($hit !== null) {
                [$position, $confident] = $hit;
                $hits[$position] = $confident
                    ? ['tenure' => $tenure, 'literal' => $acronym]
                    : ['tenure' => Tenure::UNKNOWN, 'literal' => $acronym];

                continue;
            }

            // THE GUARD RETURNING null IS NOT AN ANSWER HERE, and treating it as one was a §1
            // breach: `financement: "Prêt PLUS"` on In'li produced no signal and no doubt, so the
            // listing matched at confidence 50 with `reasons[]` reading "aucun signal dans
            // l'annonce". That is verbatim the failure that moved `pls` out of this guard one
            // commit earlier — left in place for `PLUS`, on the strongest rung of the ladder.
            //
            // `financingAcronymPosition()` answers null when no occurrence sits beside a
            // COLLOCATION noun. That is right for a DESCRIPTION, where most of the text is not
            // about financing. It is wrong for a TENURE FIELD, because the field NAME is already
            // the collocation — `financement`, `categorie` and `dispositif` are literally in that
            // list. So the floor here is the third answer, a doubt, not silence.
            //
            // Enumerating the missing nouns instead was considered and rejected: `Prêt PLUS`,
            // `Financé PLUS`, `Agréé PLUS`, `Bailleur PLUS`, `Gamme PLUS` … the review that found
            // this listed 66 of them, and a closed list is what failed here twice already.
            //
            // A known comparative still wins, because `PLUS DE 100 M2` is provably the adverb and
            // digesting every such value would make the digest useless. Everything else digests —
            // including `Pinel Plus` and `T3 PLUS`, which used to match. That is the trade: those
            // two now cost one glance each, where the alternative costs an application.
            $doubtAt = $this->firstNonComparativeOccurrence($cased, $acronym);

            if ($doubtAt !== null) {
                $hits[$doubtAt] = ['tenure' => Tenure::UNKNOWN, 'literal' => $acronym];
            }
        }

        return $hits;
    }

    /**
     * The first occurrence of `$acronym` that is NOT the French comparative adverb, or null.
     *
     * Used only for structured tenure fields, where the field name supplies the financing context
     * that {@see financingAcronymPosition()} looks for in surrounding prose. Occurrences followed by
     * a known comparative (`PLUS DE`, `PLUS GRAND`) are skipped — that is the one case where the
     * word is provably not a scheme, and it is a closed set because the tail is the adverb's own
     * grammar rather than the open set of nouns a scheme name can follow.
     */
    private function firstNonComparativeOccurrence(
        string $cased,
        string $acronym,
        bool $caseInsensitive = true,
    ): ?int {
        // Case matters on one surface and not the other. In a structured field, capitalisation is
        // the feed's house style — `financement: plus` is the scheme — so the search is
        // case-insensitive. In prose it is evidence, and `plus de 3 chambres` is the commonest
        // phrase in French rental copy, so only a shouted `PLUS` may reach the doubt floor.
        $literal = preg_quote($acronym, '/');
        $pattern = $caseInsensitive ? '(?i:' . $literal . ')' : $literal;

        $found = preg_match_all(
            '/(?<![A-Za-z0-9])' . $pattern . '(?![A-Za-z0-9])/u',
            $cased,
            $matches,
            PREG_OFFSET_CAPTURE,
        );

        // An error is not an absence of matches — the same rule every other preg call here obeys.
        if ($found === false) {
            throw MalformedText::notUtf8('TenureClassifier::firstNonComparativeOccurrence (' . preg_last_error_msg() . ')');
        }

        foreach ($matches[0] as [$literal, $offset]) {
            $after = substr($cased, $offset + strlen($literal));

            // Same-line only, for the same reason as the phrase-end rule: a comparative on the far
            // side of a title/description boundary is not modifying this occurrence.
            if (!$this->matches('/^[^\S\n]*(?i:' . self::COMPARATIVE_TAIL . ')(?![A-Za-z0-9])/u', $after)) {
                return $offset;
            }
        }

        return null;
    }

    /**
     * Is this field value a list of financing codes rather than a sentence?
     *
     * Every token must be a known acronym or a short uppercase code fragment, and at least one must
     * be an acronym — `PLUS`, `plus`, `PLUS CD`, `PLUS / PLAI`, `plai, plus` are code lists;
     * `Pinel Plus`, `PINEL PLUS` and `MAISON DE PLUS DE 100 M2` are not, because a token of five or
     * more letters is a French word and not a code.
     *
     * A code fragment is `[A-Z]{1,3}` — LETTERS ONLY, deliberately. Admitting digits would make
     * `T3 PLUS` a code list and turn an ordinary typology into a silent REJECT; excluding them
     * sends it down the prose branch, where `T3` is not a financing noun and the guard correctly
     * says nothing. Pure numbers are allowed because a bare count carries no French meaning.
     *
     * Returning `false` is NOT the safe direction here, which is why the PCRE error below throws
     * rather than falling through to it: a value wrongly sent to the prose branch used to go silent
     * on a scheme name. It no longer can — that branch has a doubt floor since round 5 — but the
     * separator list is still the difference between a determinate REJECT and a digest entry, and
     * "an error is not an absence of matches" is the rule every other preg call in this class obeys.
     */
    private function isCodeList(string $cased): bool
    {
        $tokens = preg_split('/[\s\/\-,;:()+&.]+/u', trim($cased), -1, PREG_SPLIT_NO_EMPTY);

        // `false` is a PCRE ERROR and `[]` is an empty value. Conflating them made a resource-limit
        // failure indistinguishable from a blank field.
        if ($tokens === false) {
            throw MalformedText::notUtf8('TenureClassifier::isCodeList (' . preg_last_error_msg() . ')');
        }

        if ($tokens === []) {
            return false;
        }

        $sawAcronym = false;

        foreach ($tokens as $token) {
            if ($this->matches('/^(?i:' . self::ACRONYMS . ')$/u', $token)) {
                $sawAcronym = true;

                continue;
            }

            if (!$this->matches('/^(?:[A-Z]{1,3}|[0-9]+)$/u', $token)) {
                return false;
            }
        }

        // `$sawAcronym` is currently UNREACHABLE as a false result, and is kept deliberately —
        // unlike the direction guard round 4 deleted on an unreachability argument, which is worth
        // distinguishing. That one was unreachable by ARITHMETIC and could never fire. This one is
        // unreachable only because `AMBIGUOUS_LABELS` happens to hold a single four-letter entry:
        // any token that could satisfy the caller's search is four characters, so it either matches
        // `ACRONYMS` or fails the fragment class above. Add a second entry — a two-letter code, say
        // — and the check starts carrying weight immediately. It states the rule the phorj port must
        // reproduce, and the rule is not "whatever falls out of the current table".
        return $sawAcronym;
    }

    /**
     * The collocation guard. Returns `[offset, isConfident]`, or null if no occurrence is a label.
     *
     * THREE ANSWERS, NOT TWO. An occurrence in a financing collocation is then read by what FOLLOWS
     * it:
     *   - the phrase ENDS (punctuation, end of text, another financing acronym) → `[offset, true]`,
     *     a determinate label;
     *   - a known French comparative follows (`PLUS GRAND`) → the adverb, skipped;
     *
     * Called with ONE acronym, `PLUS`, since `AMBIGUOUS_LABELS` was reduced to a single entry.
     * Examples below that show `PLS` reaching here (`Financement / PLS`, `PLS ou PLUS`) describe
     * the shape of the patterns, not a live call: `pls` is a plain label now. `ACRONYMS` still
     * lists it because an acronym NEXT TO the one being tested is what makes an enumeration.
     *   - any other word follows (`LOGEMENT PLUS MODERNE`) → `[offset, false]`, **indécidable**,
     *     which the caller turns into a doubt and the digest.
     *
     * The third answer replaced a two-answer version whose second condition was "not followed by a
     * French comparative", i.e. a denylist of adjectives. That list cannot be completed, and every
     * adjective missing from it turned an emphatic title into tenure PLUS and a silent REJECT.
     * Asking instead whether a label ENDS the phrase is a small closed question.
     *
     * TESTED PER OCCURRENCE, NOT PER DOCUMENT, and that too was a real hole. A document-level check
     * asks "is this text in a financing context anywhere?" and "is any occurrence adverbial
     * anywhere?" — so `Logement PLUS. Plus grand que la moyenne.` had its genuine financing label
     * suppressed by an unrelated adverb later in the description, and a social listing reached a
     * notification.
     *
     * Uppercase is enforced by the pattern itself: `$acronym` is matched literally against
     * case-preserving text, so `logement plus grand` never reaches any condition.
     *
     * @param string $cased accent-folded but case-PRESERVING text — case is the evidence here.
     *                      Its byte offsets align with {@see Text::fold()}'s output, because
     *                      folding removes accents first and lowercasing ASCII preserves length.
     *                      That alignment is what makes positions from the two surfaces
     *                      comparable at all, and it was ASSERTED HERE AND FALSE: `fold()` used
     *                      `mb_strtolower`, which changes byte length for 27 codepoints. It is now
     *                      byte-wise `strtolower` and enumerated by
     *                      `TextTest::testFoldPreservesByteOffsets()` — do not restore the
     *                      multibyte call, and do not weaken this paragraph back to a promise.
     *
     * @return array{int, bool}|null
     */
    private function financingAcronymPosition(string $cased, string $acronym): ?array
    {
        $a = preg_quote($acronym, '/');
        $colloc = self::COLLOCATION;
        $acros = self::ACRONYMS;
        // `[^\S\n]` — whitespace EXCEPT a newline — for the same reason as the phrase-end test, the
        // comparative escape, the `sans` lookbehind and the `conventionné` adjacency span. This was
        // the fifth consumer of the boundary rule and the last one still reading `\s`: a collocation
        // noun ending the TITLE supplied financing context to a `PLUS` opening the DESCRIPTION, so
        // `Residence de 40 logements` / `PLUS. Contactez-nous.` invented a determinate REJECT at 90
        // that the same words on one line cannot produce. Over-rejection, therefore invisible.
        $sep = '(?:[^\S\n]|[\/\-,:;()]){1,3}';
        $ambiguousAt = null;

        // `=== 0` alone would let a PCRE error (`false`) fall through into the foreach and silently
        // lose every PLUS/PLS detection in the listing. An error is not an absence of matches.
        $found = preg_match_all('/(?<![A-Za-z0-9])' . $a . '(?![A-Za-z0-9])/u', $cased, $matches, PREG_OFFSET_CAPTURE);

        if ($found === false) {
            throw MalformedText::notUtf8('TenureClassifier::financingAcronymPosition (' . preg_last_error_msg() . ')');
        }

        if ($found === 0) {
            return null;
        }

        foreach ($matches[0] as [$literal, $offset]) {
            $before = substr($cased, 0, $offset);
            $after = substr($cased, $offset + strlen($literal));

            $inContext =
                // …after a financing noun: `Logement PLUS`, `Financement / PLS`
                $this->matches('/(?:^|[^A-Za-z0-9])(?i:' . $colloc . ')' . $sep . '$/u', $before)
                // …before another financing acronym: `PLUS / PLAI`, `PLS ou PLUS`
                || $this->matches('/^' . $sep . '(?:(?i:ou|et)' . $sep . ')?(?:' . $acros . ')(?![A-Za-z0-9])/u', $after)
                // …after another financing acronym: `PLAI / PLUS`
                || $this->matches('/(?:^|[^A-Za-z0-9])(?:' . $acros . ')' . $sep . '(?:(?i:ou|et)' . $sep . ')?$/u', $before);

            if (!$inContext) {
                continue;
            }

            // A financing label ENDS a noun phrase: `Logement PLUS.`, `Logement PLUS - …`,
            // `Logement PLUS, …`, `PLUS / PLAI`, `PLS ou PLUS`. That is a small closed set, unlike
            // the set of French adjectives an adverbial `PLUS` could modify.
            //
            // A NEWLINE ENDS IT TOO, and adding that is half of a §1 fix. `Text::foldPreserveCase()`
            // keeps the newline `RawListing::text()` puts between title and description precisely
            // because it is a phrase break — but this rule was written before that and only the
            // `conventionné` adjacency rule was updated when it landed. So a title ending
            // `LOGEMENT PLUS` did not end its phrase here; the comparative test below then read the
            // FIRST WORD OF THE DESCRIPTION and dropped the label whenever it happened to be one of
            // the 44 in the tail. `Grand`, `Proche`, `Calme` and `Des` all notified; `Bel` digested.
            // Identical facts, different word order — the failure class the class docblock claims to
            // have retired for the doubt/label race, alive in the adverb race.
            if ($this->matches('/^[^\S\n]*(\n|$)|^[^\S\n]*[,;:.!?)\]\/\-–—]|^[^\S\n]*(?:(?i:ou|et)[^\S\n]+)?(?:' . $acros . ')(?![A-Za-z0-9])/u', $after)) {
                return [$offset, true];
            }

            // A word follows. If it is a comparative we know it was the adverb, so drop it.
            //
            // `[^\S\n]*`, not `\s*`: the comparative must be on the SAME LINE. Dropping a
            // determinate label is the one thing this method does that biases toward notifying, so
            // it is the one place a boundary must not be crossed. The phrase-end test above has
            // already returned for the newline case, so this is belt and braces — deliberately,
            // because whichever of the two is edited first should not be able to reopen the hole.
            if ($this->matches('/^[^\S\n]*(?i:' . self::COMPARATIVE_TAIL . ')(?![A-Za-z0-9])/u', $after)) {
                continue;
            }

            // Otherwise it is genuinely ambiguous — `LOGEMENT PLUS MODERNE` in a shouted title.
            // Do NOT guess: the caller turns this into a DOUBT, which withholds an otherwise-eligible
            // verdict without ever competing with a real label. See the class docblock, idea 3.
            $ambiguousAt ??= $offset;
        }

        return $ambiguousAt === null ? null : [$ambiguousAt, false];
    }
    /**
     * `preg_match` with the error case treated as an error.
     *
     * `preg_match(...) === 1` reads `false` — a PCRE failure — as "no match". Several call sites in
     * this class compose their result into a condition whose false branch DROPS a social signal, so
     * an error there is a silent fail-open. This should be unreachable, because every subject has
     * already been through {@see Text::fold()}, which refuses malformed input — and that is exactly
     * why it must be loud if it ever happens.
     */
    private function matches(string $pattern, string $subject): bool
    {
        $result = preg_match($pattern, $subject);

        if ($result === false) {
            throw MalformedText::notUtf8('TenureClassifier::matches (' . preg_last_error_msg() . ')');
        }

        return $result === 1;
    }

    /**
     * {@see matches()}, capturing offsets.
     *
     * @param array<int, array{string, int}> $m
     */
    private function matchOffset(string $pattern, string $subject, ?array &$m): bool
    {
        $result = preg_match($pattern, $subject, $m, PREG_OFFSET_CAPTURE);

        if ($result === false) {
            throw MalformedText::notUtf8('TenureClassifier::matchOffset (' . preg_last_error_msg() . ')');
        }

        return $result === 1;
    }
}