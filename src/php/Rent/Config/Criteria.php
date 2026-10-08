<?php

declare(strict_types=1);

namespace Scout\Rent\Config;

use Scout\Core\MalformedText;
use Scout\Core\Text;

/**
 * The developer's criteria: hard disqualifiers, score weights and notification routing.
 *
 * **Hard disqualifiers and score are two different mechanisms** (`CLAUDE.md` hard rule 8) and this
 * object keeps them apart by construction: the disqualifier fields are scalars compared once, the
 * weights live in their own object, and nothing here can turn a weight into a rejection.
 *
 * TWO KEYS THE PROTOTYPE HAD ARE DELIBERATELY ABSENT, and their absence is the fix:
 *
 * - **`max_floor`** and **`require_elevator`** (ruled 2026-08-07, Q5). The prototype hard-rejected on
 *   floor, which silently drops a third-floor flat *with* a lift whenever the listing forgets to
 *   mention the lift — and listings forget constantly. Floor and lift are score components now. There
 *   is no config key, so the behaviour cannot be reintroduced by editing a file.
 * - **Tenure.** `Tenure::isExcluded()` is hard-coded. §1 is not user-overridable, so putting a tenure
 *   list here would create a second, weaker copy of the project's one guarantee, living in a file a
 *   config edit can change.
 *
 * `null` on any of the three measurement limits means *no limit configured*. That is distinct from a
 * listing whose measurement is unknown, which is never a disqualification (hard rule 9).
 */
final readonly class Criteria
{
    /**
     * @param list<string>         $communes         canonical commune keys, see {@see communeKey()}
     * @param array<string,string> $communeLabels    canonical key → the label as written, for `reasons[]`
     * @param list<string>         $postcodePrefixes e.g. `['78', '95']`
     * @param list<string>         $excludePatterns      PCRE fragments, matched against folded title+description
     * @param list<string>         $excludeTitlePatterns PCRE fragments, matched against the folded TITLE only
     * @param array<string,int>    $communeRank      canonical key → rank, 1 is best
     */
    public function __construct(
        public array $communes,
        public array $communeLabels,
        public array $postcodePrefixes,
        public ?int $minRooms,
        public ?float $minSurfaceM2,
        public ?int $maxRentCc,
        public array $excludePatterns,
        public array $excludeTitlePatterns,
        public array $communeRank,
        public Weights $weights,
        public NotifyPolicy $notify,
        public int $freshnessMinutes,
        public bool $commuteEnabled,
        public ?string $commuteStation,
        public ?int $commuteMaxMinutes,
        /**
         * Below this many € CC per m², a listing is DOUBTFUL rather than cheap — Track 1f.
         *
         * `null` disables it, which is what every fixture and any deployment that has not derived a
         * threshold gets. See {@see \Scout\Rent\Core\CriteriaEngine::judge()} for why this routes
         * to the digest rather than rejecting, and `config/rent/criteria.json` for how the shipped
         * figure was derived.
         */
        public ?float $minPricePerM2 = null,
    ) {}

    /**
     * Canonical form of a commune name.
     *
     * Folded, then every run of non-alphanumeric bytes collapsed to one space. So `Maisons-Laffitte`,
     * `MAISONS LAFFITTE` and `maisons–laffitte` (en dash) all key the same, while `Le Vésinet` and
     * `Levesinet` stay distinct — the separator is normalised, not deleted.
     *
     * Returns `''` for a name with no alphanumeric content at all; callers must treat that as *no
     * commune*, never as a key that could match. {@see matchesCommune()} does.
     */
    public static function communeKey(string $raw): string
    {
        // A NAME THAT CANNOT BE FOLDED IS NO COMMUNE, and `''` is already exactly that — the
        // docblock above defines it, `matchesCommune()` honours it, `rankOf()` finds no rank for it
        // and `Dedup` refuses to cluster on it. Nothing new is invented here; the failure is routed
        // into a state the callers already handle.
        //
        // IT WAS UNGUARDED, and that was a pass-killer on two paths at once — found by a review
        // panel on 2026-08-24, AFTER a commit whose subject was "one listing nobody can decode must
        // not take the whole pass with it" and which hardened only `excludedBy()`. `Text::fold()`
        // refuses non-UTF-8 and undecoded HTML entities, and `ListingMapper` takes `commune`
        // straight from `Payload::string()`, which validates neither. So a single `&#039;` or one
        // cp1252 byte in a commune name — accented commune names being ubiquitous in Île-de-France
        // — threw out of `CriteriaEngine::score()` via `rankOf()`, and out of `Dedup::cluster()`
        // via `duplicateReason()`, neither of which is inside the per-source try/catch.
        //
        // Both leave hard rule 2's silent shape: `source_runs.ok = 1` with a full item count, health
        // green, and nothing notified — the Dedup one before anything is even stored. Under
        // `--watch` the loop swallows it (`MalformedText extends \RuntimeException`) and every pass
        // dies at the same row for as long as that listing is published: a permanently mute watcher
        // reporting perfect health.
        //
        // The direction is the safe one on both paths. Unranked costs a preference, not a match
        // (region mode ranks rather than filters). Refusing to cluster under-merges, which notifies
        // twice — visible and self-correcting — where over-merge hides a flat silently, and that
        // trade is already this repo's documented choice.
        try {
            $folded = Text::fold($raw);
        } catch (MalformedText) {
            return '';
        }

        $spaced = preg_replace('~[^a-z0-9]+~', ' ', $folded);

        return trim($spaced ?? '');
    }

    /**
     * Does this listing's location pass the commune filter?
     *
     * The prototype searched `commune + cp + title + raw_text` as one substring haystack, so a Paris
     * listing whose description said "proche Chatou" passed. This looks at the **commune field
     * only**, and matches whole canonical names.
     *
     * The postcode prefix is an AND, not an OR, and its job is narrow: every configured commune is in
     * 78 or 95, so the prefix cannot admit anything the commune list does not — what it does is
     * reject a same-named commune elsewhere in France. It is checked only when the listing states a
     * postcode; an unknown postcode is not a disqualification (hard rule 9).
     */
    public function matchesCommune(?string $commune, ?string $postcode): bool
    {
        // REGION MODE (2026-08-22). An empty `communes` means "do not check the name — the postcode
        // prefixes decide", which is the only way to say "anywhere in 78 or 95": the prefixes are
        // an AND that otherwise only ever narrows a name that already matched, so a departement-wide
        // watch had to be spelled out commune by commune and anything not thought of was silently
        // invisible. The loader refuses empty prefixes here, so this can never widen to everything.
        //
        // An unknown postcode is REJECTED, deliberately reversing the rule below. That is not a
        // hard-rule-9 violation, it is what hard rule 9 actually says: below, the NAME has already
        // matched and the postcode is narrowing a decision made on real evidence, so an unknown one
        // takes nothing away. Here the postcode is the only evidence there is, and "unknown" would
        // admit every listing anywhere that failed to state one.
        if ($this->communes === []) {
            return $this->postcodeMatchesPrefix($postcode);
        }

        if ($commune === null) {
            return false;
        }

        $key = self::communeKey($commune);
        if ($key === '' || !in_array($key, $this->communes, true)) {
            return false;
        }

        if ($postcode === null || $this->postcodePrefixes === []) {
            return true;
        }

        $digits = preg_replace('~\D+~', '', $postcode) ?? '';
        if ($digits === '') {
            // A postcode field that carries no digits told us nothing. Unknown, not wrong.
            return true;
        }

        foreach ($this->postcodePrefixes as $prefix) {
            if (str_starts_with($digits, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Location, under the Q32 ruling: judged on whichever of the two fields is present.
     *
     * THE ONE location rule (moved verbatim from `CriteriaEngine` on 2026-10-08): the engine rejects on
     * it and the commute planner asks it before spending a request, so the two cannot disagree.
     *
     * With a commune, {@see self::matchesCommune()} applies and the postcode narrows it. With
     * only a postcode — an ordinary shape when a selector catches the address but not the city —
     * the prefix alone decides, which is looser than the commune list but is the right kind of
     * loose: it admits a few neighbouring communes rather than dropping a real listing silently.
     */
    public function matchesLocation(?string $commune, ?string $postcode): bool
    {
        if ($commune !== null) {
            return $this->matchesCommune($commune, $postcode);
        }

        if ($this->postcodePrefixes === []) {
            return true;
        }

        $digits = preg_replace('~\D+~', '', (string) $postcode) ?? '';
        if ($digits === '') {
            return false;
        }

        foreach ($this->postcodePrefixes as $prefix) {
            if (str_starts_with($digits, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Region mode's whole filter: does this postcode start with one of the configured prefixes?
     *
     * Separate from the name path on purpose. Sharing one method would have meant threading a flag
     * through it to invert the unknown-postcode answer, and a boolean that flips a fail-open
     * decision is the kind of parameter that gets passed wrong once and never noticed.
     */
    private function postcodeMatchesPrefix(?string $postcode): bool
    {
        $digits = preg_replace('~\D+~', '', (string) $postcode) ?? '';
        if ($digits === '') {
            return false;
        }

        foreach ($this->postcodePrefixes as $prefix) {
            if (str_starts_with($digits, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The exclusion pattern that fired, or `null` if the listing is not an excluded KIND of property.
     *
     * TWO LISTS, AND THE SPLIT IS THE WHOLE POINT. `parking`, `box`, `garage`, `cave` and `bureau`
     * are property types in a TITLE and perfectly ordinary amenities in a DESCRIPTION — "T4 avec
     * parking" is a listing the developer wants, and "Parking en sous-sol" is not. One list matched
     * against the joined text cannot tell those apart, and whichever way it is tuned it is wrong for
     * half the corpus: match the description and every flat with a parking space is dropped, skip it
     * and a parking ad with no stated rooms or surface sails through — because an unknown room count
     * is not a disqualification (hard rule 9), so the size filters do NOT catch it.
     *
     * This is the same defect class as the prototype's `meubl`, which excluded a flat whose kitchen
     * was fitted (`cuisine équipée et meublée`), and it deserved the same fix rather than a tighter
     * regex: match the term where it means what you think it means.
     *
     * Both lists are matched case-insensitively against {@see Text::fold()}ed text, which is why
     * {@see \Scout\Rent\Config\ConfigLoader} refuses a pattern containing an accent.
     */
    public function excludedBy(string $title, string $description): ?string
    {
        // TEXT THAT CANNOT BE FOLDED MEANS THIS CHECK IS INCONCLUSIVE, NOT THAT THE PASS IS OVER.
        //
        // `Text::fold()` refuses non-UTF-8 rather than degrading it, which is right — folding it
        // would silently produce an empty string, and an empty string reads as a listing that named
        // no financing scheme at all. But that refusal used to escape all the way out of
        // `CriteriaEngine::judge()` and out of `Pipeline`'s judging loop, so ONE badly-encoded
        // listing aborted the whole pass and every later listing went unjudged and unnotified.
        // cp1252 under a UTF-8 declaration is an anticipated real input here, with its own `Text`
        // test and its own classifier branch.
        //
        // Returning `null` cannot turn an unreadable listing into a match, and that is verified
        // rather than assumed: the classifier refuses the same text and yields `UNKNOWN` — measured
        // for a malformed title AND for a malformed description — so `judge()` reaches its tenure
        // branch and digests. The listing goes to *à vérifier* with its encoding named, which is
        // exactly where a listing nobody can read belongs.
        // TWO SURFACES, TWO `try`s, and the first draft of this fix used one — which silently
        // disabled `exclude_title_patterns` on a perfectly READABLE title whenever the description
        // was unfoldable. A review panel measured it on 2026-08-24: title `Parking en sous-sol`,
        // description `Belle vue,&nbsp;calme.` — the parking ad stopped being rejected and landed
        // in the *à vérifier* channel instead. And the trigger is not exotic: `Text` refuses any
        // undecoded HTML entity, which is commoner in a scraped payload than cp1252.
        //
        // That is the failure class `CLAUDE.md` names — a correct rule applied to a subset of the
        // surfaces it belongs on — arriving through the fix for the same class one method up.
        try {
            $foldedTitle = Text::fold($title);
        } catch (MalformedText) {
            $foldedTitle = null;
        }

        if ($foldedTitle !== null) {
            foreach ($this->excludeTitlePatterns as $pattern) {
                if (preg_match('~' . $pattern . '~i', $foldedTitle) === 1) {
                    return $pattern;
                }
            }
        }

        try {
            $foldedAll = Text::fold($title . "\n" . $description);
        } catch (MalformedText) {
            return null;
        }

        foreach ($this->excludePatterns as $pattern) {
            if (preg_match('~' . $pattern . '~i', $foldedAll) === 1) {
                return $pattern;
            }
        }

        return null;
    }

    /**
     * Rank of a commune, 1 = most wanted. `null` when the commune is unranked — which scores as the
     * bottom of the ranked list rather than as zero, because every configured commune is wanted.
     */
    public function rankOf(?string $commune): ?int
    {
        if ($commune === null) {
            return null;
        }

        return $this->communeRank[self::communeKey($commune)] ?? null;
    }

    /** Highest rank number in use, for normalising S1. At least 1, so it is never a zero divisor. */
    public function worstRank(): int
    {
        return max(1, $this->communeRank === [] ? 1 : max($this->communeRank));
    }
}
