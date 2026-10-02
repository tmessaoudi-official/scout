<?php

declare(strict_types=1);

namespace Scout\Core;

/**
 * Deterministic text folding, shared by every text-matching decision in the core.
 *
 * WHY THIS IS HAND-ROLLED RATHER THAN `Normalizer::normalize()` OR `iconv`:
 * this repo ships the same core twice — once in PHP, once in phorj — and the two are compared
 * fixture-by-fixture against `tests/fixtures/rent/tenure/corpus.json`. That comparison only means
 * something if both implementations fold text identically. ICU's normalisation tables are a
 * dependency phorj has not been confirmed to have, and `iconv//TRANSLIT` output varies with the
 * host locale. An explicit table is portable, has no locale, and never changes under us.
 *
 * Folding is deliberately lossy in one direction only: it removes accents and (in {@see fold()})
 * ASCII case — non-ASCII uppercase survives, deliberately, so that byte offsets in the two folded
 * surfaces agree — and it normalises the several Unicode apostrophes French text arrives with.
 * Whitespace collapses to a single space EXCEPT newlines, which mark a field boundary. It does NOT
 * decode HTML entities — that is the adapter's job, and a classifier that quietly decoded them
 * would hide a broken adapter (see the `case-005` fixture).
 */
final class Text
{
    /** Lowercase accented forms. */
    private const array FOLD_LOWER = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
        'ç' => 'c',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'ñ' => 'n',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
        'ý' => 'y', 'ÿ' => 'y',
        'œ' => 'oe', 'æ' => 'ae', 'ß' => 'ss',
    ];

    /**
     * Uppercase accented forms, written out rather than derived at runtime.
     *
     * Spelled in full because {@see foldPreserveCase()} is what tells the financing acronym `PLUS`
     * from the French adverb `plus`, so its output must not depend on a locale-sensitive
     * `mb_strtoupper()` call — and because phorj will mirror this table literally.
     */
    private const array FOLD_UPPER = [
        'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'Å' => 'A',
        'Ç' => 'C',
        'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E',
        'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I',
        'Ñ' => 'N',
        'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O',
        'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U', 'Ü' => 'U',
        'Ý' => 'Y', 'Ÿ' => 'Y',
        'Œ' => 'OE', 'Æ' => 'AE',
    ];

    /**
     * Invisible characters, defined by a PROPERTY rather than a list — because the list approach
     * failed three review rounds in a row.
     *
     * The failure is always the same: an invisible character between `logement` and `social`
     * deletes that label while leaving `loyer intermediaire` standing, so an explicitly social
     * listing classifies as LLI at confidence 90 — above the floor, so the fail-closed rule never
     * engages — and the word never reaches `reasons[]`. Round 2 closed `\p{Cf}` (soft hyphen,
     * ZWSP, BOM). Round 3 added `\p{Cc}` controls and four named invisible LETTERS. Round 4 found
     * U+2065, U+FFF0 and U+E0080 still open: Unicode's Default_Ignorable set includes RESERVED
     * codepoints of category `Cn`, which no enumeration of "the categories I can think of" reaches.
     *
     * `\p{DI}` IS that set, and PCRE2 has it. It also excludes every whitespace character, which is
     * what blocked the obvious `\p{Cc}` widening — that one ate `\t`, `\n` and `\r`, which the
     * collapse below depends on, and broke nine tests.
     *
     * Verified exhaustively over all 0x10FFFF codepoints: **zero** Default_Ignorable survivors, and
     * `\t \n \r \v \f` all preserved. Two additions remain necessary beyond `\p{DI}`:
     *   - the C0/C1 control ranges minus the whitespace ones. U+0091–U+009F are the ordinary
     *     product of CP1252 bytes decoded as Latin-1, a routine French-CMS mojibake path;
     *   - U+2800 BRAILLE PATTERN BLANK, category `So` and NOT Default_Ignorable, but it renders as
     *     nothing in every font a listing will meet.
     *
     * Known nuance, harmless: U+0085 and U+180E are both whitespace AND in the stripped set, so
     * they are deleted rather than collapsed to a space. The `\s*` join in
     * {@see inflectedTokenPosition()} covers the label case, and `RawListing::text()` joins on
     * `\n`, which is preserved.
     */
    private const string INVISIBLE = '\p{DI}\x{0000}-\x{0008}\x{000E}-\x{001F}\x{007F}-\x{009F}\x{2800}';

    /** Every apostrophe French listings arrive with, folded to the ASCII one. */
    private const array APOSTROPHES = ["\u{2019}", "\u{2018}", "\u{02BC}", "\u{FF07}", '`', '´'];

    /**
     * Strip accents and normalise apostrophes and whitespace, but KEEP case.
     *
     * This is the surface the ambiguous-acronym rules are matched against, because case is the
     * evidence there: `PLUS` in a financing collocation is a tenure label, `plus` is the adverb.
     */
    public static function foldPreserveCase(string $raw): string
    {
        // Checked BEFORE anything else, and it throws rather than degrading. See MalformedText:
        // `preg_replace(…, '/u')` returns null on malformed UTF-8, and casting that to string
        // produced '' — which the classifier read as "this listing mentioned no financing scheme"
        // and matched on the source default. An unreadable listing is not an unlabelled one.
        if ($raw !== '' && !mb_check_encoding($raw, 'UTF-8')) {
            throw MalformedText::notUtf8('Text::foldPreserveCase');
        }

        // An HTML entity here means an adapter stopped short. Refuse rather than classify around
        // it: an entity sitting inside a multi-word label deletes that label and leaves the others
        // standing, and the label it deletes is not chosen fairly.
        //
        // `=== 1` on its own reads a PCRE ERROR (`false`) as "no entity found", which is the same
        // fail-open shape this file rejects everywhere else — see tokenPosition() and the null
        // check below.
        //
        // THIS BRANCH DOES EXECUTE; what is latent is only its EFFECT. PCRE limits are global, so
        // anything that breaks this call also breaks the `preg_replace` further down, whose null
        // check throws the same exception class — remove this guard and the caller still gets a
        // `MalformedText`, which is why no test can pin the branch on its own. An earlier version of
        // this comment said "LATENT, not live", which reads as *unreachable*, and this repo has just
        // deleted one guard on exactly that argument. It is reachable, it fires first, and its
        // message is the one that names the entity gate.
        //
        // The exception is `notUtf8()`, whose text asserts the input is not valid UTF-8. For a
        // resource-limit failure that is the wrong diagnosis, and it is kept only because inventing
        // a second failure type for a path with no reproducer would be worse. If a real reproducer
        // ever appears, give it its own named constructor rather than widening this one's message.
        $entityFound = preg_match('/&(?:[a-zA-Z][a-zA-Z0-9]{1,10}|#\d{1,6}|#[xX][0-9a-fA-F]{1,6});/', $raw, $entity);

        if ($entityFound === false) {
            throw MalformedText::notUtf8('Text::foldPreserveCase entity gate (' . preg_last_error_msg() . ')');
        }

        if ($entityFound === 1) {
            throw MalformedText::undecodedEntities($entity[0]);
        }

        $s = strtr($raw, self::FOLD_LOWER + self::FOLD_UPPER);
        $s = str_replace(self::APOSTROPHES, "'", $s);

        // TWO INVISIBLE CATEGORIES, STRIPPED FOR THE SAME REASON: neither carries meaning, and
        // either one sitting inside a multi-word label deletes that label while leaving every other
        // label in the listing standing. The deletion is not chosen fairly, so the failure is
        // asymmetric — and both directions of it were found by review rather than by the suite.
        //
        // \p{Mn} — combining diacritics, for text that arrives NFD-decomposed (`e` + U+0301) rather
        // than precomposed. Without it an NFD `numéro unique d'enregistrement` never matched while
        // an unaccented `LLI` in the same listing still did: the social side vanished, the eligible
        // side survived.
        //
        // \p{Cf} — invisible FORMAT characters: U+00AD soft hyphen, U+200B zero-width space,
        // U+200C/D zero-width (non-)joiner, U+200E LRM, U+2060 word joiner, U+FEFF BOM. Same
        // asymmetry, one Unicode category over: `Ce logement<U+00AD>social a loyer intermediaire`
        // lost `logement social` and kept `loyer intermediaire`, classifying an explicitly social
        // listing as LLI at confidence 90 — above the floor, so the fail-closed rule never engaged.
        //
        // Note the doctrine that CREATES this input: MalformedText::undecodedEntities() tells the
        // adapter that decoding is its job. An adapter that obeys turns `&shy;` -- standard
        // hyphenation markup in justified French CMS output -- into U+00AD, which passes both the
        // UTF-8 gate and the entity gate. Refusing these would punish the adapter for being
        // correct; stripping them is right, because they are invisible by definition.
        // \s already covers the space-LIKE leaks (NBSP, narrow NBSP) via the collapse below.
        $stripped = preg_replace('/[\p{Mn}\p{Cf}' . self::INVISIBLE . ']/u', '', $s);

        // NEWLINES SURVIVE THE COLLAPSE; every other run of whitespace becomes one space.
        //
        // A newline here is a FIELD BOUNDARY — `RawListing::text()` joins title and description with
        // one — and the `conventionné` adjacency rule needs to see it. Collapsing everything to a
        // single space made a title ending in `Logement intermédiaire` sit one ordinary space from a
        // description opening `Conventionné, réservé aux ménages sous plafond`, so the adjective
        // read as qualifying the noun and the exception fired: MATCH, with `conventionne` absent
        // from `reasons[]`. A comma blocked that exception and a field boundary did not, which is
        // backwards — a boundary is the stronger phrase break of the two.
        //
        // Runs of newlines collapse to one, and spaces around a newline are absorbed into it, so the
        // output stays canonical: the only whitespace bytes it can contain are ' ' and "\n".
        $collapsed = $stripped === null ? null : preg_replace('/[^\S\n]+/u', ' ', $stripped);
        $collapsed = $collapsed === null ? null : preg_replace('/ ?\n[\s]*/u', "\n", $collapsed);

        // `null` from any of them is a PCRE ERROR, never "nothing to replace". Casting it to string
        // is what turned an unreadable listing into an unlabelled one in the first place, so the
        // anti-pattern is not left sitting two lines above its own fix.
        if ($collapsed === null) {
            throw MalformedText::notUtf8('Text::foldPreserveCase (' . preg_last_error_msg() . ')');
        }

        return trim($collapsed);
    }

    /**
     * Lowercase ASCII, strip accents, normalise apostrophes, collapse whitespace but KEEP newlines.
     *
     * The result is the canonical form every literal pattern in this codebase is written against.
     * Two qualifiers in that first line are load-bearing and both were missing from it: the
     * lowercasing is ASCII-only so the two folded surfaces stay byte-aligned, and a newline
     * survives because it is the title/description boundary. See the body comments for each.
     */
    public static function fold(string $raw): string
    {
        // BYTE-WISE `strtolower`, NOT `mb_strtolower`, and the difference is load-bearing.
        //
        // Positions from this surface and from `foldPreserveCase()` are compared directly: the
        // resolver breaks ties on `TenureSignal::position`, and the `conventionné` adjacency rule
        // does arithmetic across a span. Both are only meaningful if the two surfaces agree byte
        // for byte, which the docblock in `TenureClassifier::financingAcronymPosition()` asserted —
        // and `mb_strtolower` made false for 27 codepoints, among them `İ` (U+0130), `ẞ` (U+1E9E)
        // and the Kelvin sign `K` (U+212A), each of which changes byte length when lowercased. A
        // listing containing one before a `PLUS` shifted every later offset and could mis-decide
        // the adjacency test. Found by enumerating U+0020..U+2FFFF; no test had asserted it.
        //
        // ASCII-only lowercasing costs nothing here because `foldPreserveCase()` has already mapped
        // French to ASCII — `É`→`E`, `Œ`→`OE`, `Ç`→`C` — and every literal in this codebase's label
        // tables is ASCII. Greek and Cyrillic uppercase now survive un-lowercased, which no pattern
        // was ever going to match either way. `testFoldPreservesByteOffsets()` enumerates the range
        // and keeps this true rather than merely claimed.
        return strtolower(self::foldPreserveCase($raw));
    }

    /**
     * Does `$needle` occur in `$foldedHaystack` as a whole token?
     *
     * This is the single most important function in the tenure module, and the reason is one word:
     * `plus`. It is among the commonest words in French, so a substring match classifies most of the
     * rental market as social housing. `plai` is the mirror image — as a substring it matches
     * *plaine*, *plaisant* and *plaisir*. Both mistakes are silent: the listings are simply dropped
     * and never arrive, so the developer concludes the market is quiet.
     *
     * @param string $foldedHaystack must already have been through {@see fold()}
     * @param string $needle         a folded literal, possibly multi-word
     */
    public static function hasToken(string $foldedHaystack, string $needle): bool
    {
        return self::tokenPosition($foldedHaystack, $needle) !== null;
    }

    /**
     * Byte offset of the first whole-token occurrence, or null.
     *
     * The offset is what makes tie-breaking between two matches deterministic — and therefore
     * reproducible in the phorj implementation — rather than dependent on table iteration order.
     */
    public static function tokenPosition(string $foldedHaystack, string $needle): ?int
    {
        if ($needle === '' || $foldedHaystack === '') {
            return null;
        }

        $pattern = '/(?<![a-z0-9])' . preg_quote($needle, '/') . '(?![a-z0-9])/u';
        $result = preg_match($pattern, $foldedHaystack, $m, PREG_OFFSET_CAPTURE);

        // `false` is a PCRE ERROR, not "no match", and treating the two alike is how an unreadable
        // listing becomes an unlabelled one. Inputs here have already been through fold(), so this
        // should be unreachable — which is exactly why it must be loud if it ever happens.
        if ($result === false) {
            throw MalformedText::notUtf8('Text::tokenPosition (' . preg_last_error_msg() . ')');
        }

        return $result === 1 ? $m[0][1] : null;
    }

    /**
     * Words that are acronyms, not French words, and therefore never inflect.
     *
     * `plai` must be here and the reason is sharp: the generic rule below would generate `plaie`
     * (a wound) and `plais` (from *plaire*), both real French words, and every listing containing
     * one would be classified as social housing and dropped in silence.
     */
    private const array INVARIANT_WORDS = ['lli', 'plai', 'plus', 'pls', 'anru', 'anah', 'hlm', 'sne', 'apl'];

    /**
     * The INVARIANT acronyms that are also written with a separator between their letters. `plus`,
     * `sne` and `apl` are deliberately absent: `plus` is an ordinary adverb and `sne`/`apl` are
     * procedural, so letter-spacing them would only add false positives.
     */
    private const array SPELLED_OUT_ACRONYMS = ['lli', 'plai', 'pls', 'anru', 'anah', 'hlm'];

    /**
     * What may sit BETWEEN the words of a multi-word literal: whitespace (possibly none — see the
     * comment where it is used), a hyphen of any width, an underscore, a slash, or a dot that has a
     * letter on each side. A dot followed by a space is a sentence break and is NOT a separator, so
     * `proche du logement. Social club` stays two sentences.
     */
    private const string WORD_GAP = '(?:[\\s\\-_\\/\\x{2010}-\\x{2015}]|(?<=[a-z])\\.(?=[a-z]))*';

    /** The same, plus any dot: `P.L.A.I.` has a dot after every letter, and a space or a dot may follow it. */
    private const string LETTER_GAP = '(?:[\\s\\-_\\/\\.\\x{2010}-\\x{2015}])*';

    /**
     * Whole-token match that tolerates FRENCH AGREEMENT AND PLURALS.
     *
     * THE DEFECT THIS EXISTS FOR: every literal used to be matched exactly, with a trailing
     * `(?![a-z0-9])` guard. French tenure vocabulary is inflected — the adjective agrees and the
     * noun phrase pluralises — so `conventionnée`, `logements sociaux` and `prêts locatifs sociaux`
     * were all non-matches while `conventionné` and `logement social` matched. The acronyms
     * (`PLAI`, `ANRU`, `ANAH`, `HLM`) are invariant, which is precisely why the table read as safe
     * on inspection: the terms a reviewer checks first are the ones that could not break.
     *
     * A listing whose own description said *« logements sociaux et intermédiaires »* classified as
     * LLI at full tier-2 confidence and was notified, because no excluded signal existed for the
     * conflict rule to see.
     *
     * The rules are deliberately small and French-specific:
     *   - a word ending in `-al` also matches `-aux` (`social` → `sociaux`), plus `-e`/`-es`;
     *   - any other word may take `-e`, `-es`, `-s` or `-x`;
     *   - words in {@see INVARIANT_WORDS} take nothing at all.
     *
     * Over-generation (`logementx`, `uniquee`) is harmless: those are not French words, so they
     * match no real listing. Under-generation is what costs an application.
     *
     * @param string $foldedHaystack must already have been through {@see fold()}
     *
     * @return array{int, string}|null byte offset and the text that actually matched, or null
     */
    public static function inflectedTokenPosition(string $foldedHaystack, string $needle): ?array
    {
        if ($needle === '' || $foldedHaystack === '') {
            return null;
        }

        $parts = [];

        foreach (explode(' ', $needle) as $word) {
            if (in_array($word, self::SPELLED_OUT_ACRONYMS, true)) {
                // `PLA-I`, `PLA I`, `P.L.A.I.`, `P.L.S.` — the same acronym with a separator between
                // its letters. Matching only the joined spelling let an explicit social label fall
                // through to the source default and reach MATCH (audit 2026-10-02, P0-1).
                $parts[] = implode(self::LETTER_GAP, array_map(
                    static fn (string $letter): string => preg_quote($letter, '/'),
                    str_split($word),
                ));
            } elseif (in_array($word, self::INVARIANT_WORDS, true)) {
                $parts[] = preg_quote($word, '/');
            } elseif (str_ends_with($word, 'al')) {
                $parts[] = '(?:' . preg_quote($word, '/') . '(?:es|e)?|'
                    . preg_quote(substr($word, 0, -2), '/') . 'aux)';
            } else {
                $parts[] = preg_quote($word, '/') . '(?:es|e|s|x)?';
            }
        }

        // `\s*`, not `\s+`, between words. Stripping an invisible \p{Cf} character that sat between
        // two words JOINS them — `logement<U+200B>social` becomes `logementsocial` — so a pattern
        // requiring whitespace would still miss the label the strip was meant to rescue. Allowing
        // zero separation is safe in both directions: `logementsocial` is not a French word, so
        // nothing legitimate matches by accident, and the guards at each end still require the
        // whole thing to stand as a token.
        $pattern = '/(?<![a-z0-9])' . implode(self::WORD_GAP, $parts) . '(?![a-z0-9])/u';
        $result = preg_match($pattern, $foldedHaystack, $m, PREG_OFFSET_CAPTURE);

        if ($result === false) {
            throw MalformedText::notUtf8('Text::inflectedTokenPosition (' . preg_last_error_msg() . ')');
        }

        return $result === 1 ? [$m[0][1], $m[0][0]] : null;
    }

    /**
     * {@see fold()} for INCIDENTAL surfaces: it DECODES rather than refusing, and returns null when
     * it genuinely cannot read the input.
     *
     * THE FIRST VERSION SUBSTITUTED A SPACE FOR EACH ENTITY, and that was a §1 fail-open dressed as
     * a repair. Its docblock claimed the substitution "cannot create a false positive and cannot
     * hide a true one — every literal is matched with `\s*` between its words". `\s*` sits between
     * the WORDS of a literal, not inside a word, so:
     *   - `PL&shy;AI` folded to `pl ai`, matched nothing, and NOTIFIED at confidence 50. `&shy;` is
     *     ordinary hyphenation markup in justified French CMS output, and `&#8203;` is its
     *     zero-width sibling. All three reviewers reproduced this independently.
     *   - `commission d&#39;attribution` folded to `commission d attribution` — `&#39;` is how every
     *     French CMS emits an apostrophe, and three procedural literals contain one.
     *   - in the other direction `plai&shy;sir` folded to `plai sir`, inventing a token inside
     *     *plaisir* — the exact silent drop {@see hasToken()} exists to prevent.
     *
     * Decoding has none of those failure modes, because the machinery that already exists handles
     * the result correctly: `&shy;` and `&#8203;` decode to characters {@see INVISIBLE} strips,
     * `&nbsp;` decodes to a space the collapse folds, `&#39;` decodes to an apostrophe
     * {@see APOSTROPHES} normalises. `plai&shy;sir` becomes `plaisir` and correctly matches nothing.
     * Repeated because double-encoded input (`&amp;nbsp;`) is a real adapter artefact; bounded
     * because a decode loop on hostile input must terminate.
     *
     * NULL, NOT '' — a distinction the first version got wrong in the way `CLAUDE.md` hard rule 3
     * names. It returned `''` on failure and both callers read `''` as "this surface said nothing",
     * so an unreadable field became a silent one. Null means UNREADABLE and the caller raises a
     * doubt; `''` means genuinely empty.
     *
     * The strict {@see fold()} still guards the title, the description and declared tenure fields.
     * Decoding for DETECTION on an incidental surface is not the same as hiding a broken adapter on
     * a tenure-bearing one — and when a health module exists, an entity reaching here is a signal it
     * should count.
     */
    public static function foldTolerant(string $raw): ?string
    {
        try {
            return self::fold(self::decodeEntities($raw));
        } catch (MalformedText) {
            return null;                       // still unreadable — the caller must raise a doubt
        }
    }

    /**
     * {@see foldTolerant()} keeping case — for callers that must restore word boundaries.
     *
     * Invisible characters are already stripped at this point, which is what makes it safe to split
     * an identifier on non-alphanumerics: on the merely-decoded form a soft hyphen reads as a
     * separator, so `plai<U+00AD>sir` splits into the word `plai`.
     */
    public static function foldTolerantPreserveCase(string $raw): ?string
    {
        try {
            return self::foldPreserveCase(self::decodeEntities($raw));
        } catch (MalformedText) {
            return null;
        }
    }

    /**
     * Scrub invalid bytes and decode HTML entities, repeatedly and boundedly.
     *
     * Separate from {@see foldTolerant()} because callers that need to restore WORD BOUNDARIES in
     * an identifier must split the decoded-but-not-yet-folded form: splitting the raw string turns
     * `plai&shy;sir` into the words `plai shy sir` and invents a match inside *plaisir*.
     *
     * Three passes handles the double-encoded `&amp;nbsp;` seen in real scrapes; the bound is there
     * so input engineered to decode forever cannot spin.
     */
    public static function decodeEntities(string $raw): string
    {
        $decoded = mb_convert_encoding($raw, 'UTF-8', 'UTF-8');

        for ($pass = 0; $pass < 3; ++$pass) {
            $next = html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            if ($next === $decoded) {
                break;
            }

            $decoded = $next;
        }

        return $decoded;
    }

    /**
     * Reduce a structured field NAME to a comparison key: folded, then stripped of every
     * separator. `typeProduit`, `TYPE_PRODUIT` and `Type de produit` all become `typeproduit`
     * — adapters disagree about field-name style and none of them is wrong.
     */
    public static function fieldKey(string $rawName): string
    {
        $s = self::fold($rawName);
        $s = str_replace([' de ', " d'", ' du ', ' des '], ' ', $s);

        return preg_replace('/[^a-z0-9]/u', '', $s)
            ?? throw MalformedText::notUtf8('Text::fieldKey (' . preg_last_error_msg() . ')');
    }
}
