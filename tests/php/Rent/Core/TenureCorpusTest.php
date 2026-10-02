<?php

declare(strict_types=1);

namespace Scout\Tests\Rent\Core;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;
use Scout\Rent\Core\Classification;
use Scout\Rent\Core\Outcome;
use Scout\Rent\Core\RawListing;
use Scout\Rent\Core\SourceProfile;
use Scout\Rent\Core\Tenure;
use Scout\Core\Text;
use Scout\Rent\Core\TenureClassifier;

/**
 * The corpus suite. `spec/PROJECT_BRIEF.md` §4: "The suite must go red if the classifier regresses."
 *
 * `CLAUDE.md`: never weaken a fixture to make a change pass. If one of these goes red, the
 * classifier regressed — fix the classifier. A skipped, xfailed, deleted or relabelled fixture is a
 * P0 finding unless the old label was demonstrably wrong AND the evidence is in the commit message.
 */
#[CoversClass(TenureClassifier::class)]
final class TenureCorpusTest extends TestCase
{
    /** @param array<string,mixed> $expect */
    #[DataProviderExternal(Corpus::class, 'provider')]
    public function testCorpusCaseClassifiesAsLabelled(
        RawListing $listing,
        SourceProfile $source,
        array $expect,
        string $why,
        string $id = '?',
    ): void {
        $result = (new TenureClassifier())->classify($listing, $source);

        $context = sprintf("fixture %s\nwhy: %s\ngot: %s", $id, $why, json_encode($result->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        self::assertSame(Tenure::from((string) $expect['tenure']), $result->tenure, $context);
        self::assertSame(Outcome::from((string) $expect['outcome']), $result->outcome, $context);

        if (isset($expect['min_confidence'])) {
            self::assertGreaterThanOrEqual((int) $expect['min_confidence'], $result->confidenceBp, $context);
        }

        if (isset($expect['max_confidence'])) {
            self::assertLessThanOrEqual((int) $expect['max_confidence'], $result->confidenceBp, $context);
        }
    }

    /**
     * §1 asserted against the LISTING, not against the verdict — the invariant that was missing.
     *
     * {@see testNoExcludedTenureEverReachesAMatch()} branches on `$result->tenure->isExcluded()`.
     * Every §1 breach found so far had the verdict `LLI`, so that assertion never fired: a listing
     * whose own description said `logements conventionnés` and one whose `financement` field said
     * `PLUS CD` both reached MATCH with the whole suite green, including 26/26 sabotages. §1 is
     * about what the LISTING says; that test is about what the classifier concluded.
     *
     * So: no corpus case may reach MATCH while its own text or fields name an excluded tenure —
     * unless it is on the allow-list below, which exists because a handful of fixtures deliberately
     * contain the FRENCH ADVERB `plus` and are the whole point of the collocation guard.
     */
    public function testNoListingNamingAnExcludedTenureEverReachesAMatch(): void
    {
        $exempt = self::exclusionExemptions();
        $classifier = new TenureClassifier();

        foreach (Corpus::provider() as $id => [$listing, $source]) {
            $result = $classifier->classify($listing, $source);

            if ($result->outcome !== Outcome::MATCH) {
                continue;
            }

            // Built the way the CLASSIFIER reads surfaces, not as one concatenation: the free text
            // strictly, each field tolerantly, and field NAMES included because they carry evidence
            // too. A single `Text::fold()` over everything threw on the `&amp;` in a URL fixture —
            // an invariant using a stricter reader than the code it polices is its own kind of
            // drift, and it would have hidden any listing whose fields do not survive the gate.
            $haystack = Text::fold($listing->text());

            foreach ($listing->fields as $fieldName => $fieldValue) {
                $haystack .= ' ' . Text::foldTolerant((string) $fieldName);

                if (is_scalar($fieldValue) || $fieldValue instanceof \Stringable) {
                    $haystack .= ' ' . Text::foldTolerant((string) $fieldValue);
                }
            }

            foreach (self::excludedTokens() as $token) {
                if (isset($exempt[$id][$token])) {
                    continue;
                }

                self::assertNull(
                    Text::inflectedTokenPosition($haystack, $token, true),
                    sprintf(
                        "fixture %s reached MATCH while its own listing says '%s'.\n"
                        . "That is CLAUDE.md §1. If this is a legitimate exception (the French "
                        . "adverb, or conventionne qualifying an intermediate label), add it to "
                        . '$exempt as %s => [%s => reason] — do not delete the assertion.',
                        $id,
                        $token,
                        $id,
                        $token,
                    ),
                );
            }
        }
    }

    /**
     * The §1 exemption table, keyed `fixture id => token => reason`.
     *
     * KEYED BY TOKEN, NOT BY FIXTURE ALONE. A blanket per-fixture exemption switches the invariant
     * OFF for that listing entirely: a fixture excused for containing the French adverb `plus` would
     * also stop being checked for `plai`, `hlm` and every other token, so the §1 assertion could be
     * silenced on any listing by giving it one innocent reason. Naming the token keeps the rest live.
     *
     * @return array<string, array<string, string>>
     */
    private static function exclusionExemptions(): array
    {
        return [
            // The `plus` adverb traps — the guard exists precisely so these MATCH.
            'trap-001-plus-de-chambres' => ['plus' => 'the adverb in "plus de 3 chambres"'],
            'trap-002-au-plus-tard' => ['plus' => 'the adverb in "au plus tard" and "plus lumineux"'],
            'trap-003-plus-tier-uppercase-boundary' => ['plus' => 'a SHOUTED title, "PLUS DE 70 M2"'],
            'trap-003b-shouted-logement-plus-grand' => ['plus' => 'a SHOUTED "LOGEMENT PLUS GRAND"'],
            // THE FIRST CAPTURED FIXTURE TO NEED ONE, and the reason the re-route landed: this is
            // CDC Habitat's own tooltip DEFINING logement intermediaire, and the definition happens
            // to contain `au plus pres des bassins d'emploi`. Not a contrived trap — real card text
            // from a real results page [captured 2026-08-20], which is what makes it the strongest
            // member of this table. Exempted for `plus` ONLY: everything else the §1 invariant
            // checks still applies to this fixture, including the `plafonds de ressources` it also
            // carries, which is true of LLI and of social housing alike.
            'cdc-001-captured-intermediate-badge-with-plus-adverb' => [
                'plus' => 'the adverb in CDC Habitat\'s own definition, "implante au plus pres des '
                    . 'bassins d\'emploi"',
            ],
            // THE FIRST FROM AN EMAIL, and the first where the offending text belongs to nobody who
            // could edit it. `En savoir plus →` is SeLoger's own call-to-action button, part of the
            // alert template rather than the landlord's copy [captured 2026-08-25], so unlike a
            // description this is a surface no careful writing can ever clean. `plus` ONLY: every
            // other excluded token is still checked against this fixture.
            'seloger-001-captured-cta-en-savoir-plus' => [
                'plus' => 'the adverb in SeLoger\'s own CTA button, "En savoir plus"',
            ],
            'trap-010-lowercase-plus-with-no-comparative' => [
                'plus' => 'the LOWERCASE adverb in "plus un bureau" — the prose doubt floor is '
                    . 'case-sensitive, and this fixture is what stops it being widened',
            ],
            // NOTE: `trap-005-surplus-not-plus` is deliberately NOT here. It was, until the
            // earned-exemption test above rejected it: `inflectedTokenPosition` is word-bounded, so
            // the `plus` inside `surplus` never matched and the exemption never excused anything.
            // A no-op exemption is worth deleting — it reads as evidence that a real hole was
            // considered and waved through.
            // NOTE: `trap-005b` was exempted here until review round 6. A shouted `PLUS` that no
            // comparative explains is now a doubt in prose as well as in fields, so it digests
            // rather than matching and the exemption would be dead.
            // NOTE: `regress-027` and `regress-028` were exempted here until review round 5. They
            // no longer reach MATCH — a `PLUS` in a tenure field that the guard cannot place is now
            // a doubt rather than silence — so an exemption for them would be dead, and
            // testEveryExclusionExemptionIsStillEarned() rejects dead exemptions on purpose.
            // `conventionné` QUALIFYING an explicit intermediate label — the glossary's exception.
            'lli-011-conventionne-with-intermediate-label' => [
                'conventionne' => 'CLAUDE.md glossary: conventionne qualifying an explicit '
                    . 'intermediate label is not excluded',
            ],
            // A NEGATED procedural tell. `sans commission d'attribution` is an INTERMEDIATE signal —
            // allocation by the landlord rather than by a commission — and it contains the social
            // literal `commission d'attribution` as a substring. The classifier handles this with a
            // `sans` lookbehind; this invariant reads the raw text and cannot, so the exemption is
            // named per-token and the fixture stays checked for every other excluded literal.
            'lli-003-explicit-label-text' => [
                "commission d'attribution" => 'the NEGATED form, "sans commission d\'attribution", '
                    . 'which is the intermediate tell rather than the social one',
            ],
            // The invariant reads text and fields as ONE haystack, so it re-assembles across the
            // surface join that the classifier now refuses to — `…aucune commission` in the
            // description and `Attribution directe…` in a field. That is the invariant being
            // coarser than the code, not a §1 exception: the classifier is correct here and the
            // listing carries the INTERMEDIATE tell, not the social one.
            'regress-044-procedural-literal-not-assembled-across-a-field-join' => [
                'commission attribution' => 'assembled by the invariant across the description/field '
                    . 'join; the listing says "aucune commission" and "attribution directe", which '
                    . 'are two surfaces and two different tells',
            ],
            'regress-030-inflected-label-then-conventionne' => [
                'conventionne' => 'the same glossary exception, reached through an INFLECTED label '
                    . '("logements locatifs intermédiaires conventionnés") — the case that pins the '
                    . 'adjacency window to the matched text rather than to the table literal',
            ],
        ];
    }

    /**
     * The literals the classifier itself treats as naming an excluded tenure.
     *
     * DERIVED FROM THE CLASSIFIER'S OWN TABLES, not hand-listed beside them. The hand-written
     * version held eight tokens while the tables held fifteen, so `pret locatif a usage social`,
     * `logement locatif social`, `habitation a loyer modere` and four more were absent from the one
     * assertion that checks §1 against the LISTING rather than against the verdict — a listing whose
     * description spelled the scheme out in full was never checked at all.
     *
     * BOTH tables, and that is not a detail: `plus` lives in `AMBIGUOUS_LABELS` alone, so a first
     * version of this method that read only `LABELS` silently dropped the single most dangerous
     * token in the set and left six now-dead `plus` exemptions behind. That is why
     * {@see testEveryExclusionExemptionIsStillEarned()} exists — a dead exemption is the visible
     * symptom of exactly this mistake.
     *
     * @return list<string>
     */
    private static function excludedTokens(): array
    {
        $class = new \ReflectionClass(TenureClassifier::class);
        $tokens = [];

        // PROCEDURAL is included, and that was a gap: `numero unique d'enregistrement`,
        // `systeme national d'enregistrement`, `sne`, `demande de logement social` and
        // `commission d'attribution` and its no-apostrophe variant are SIX more phrases from which
        // the classifier concludes an excluded tenure, and none of them was inside the one invariant that checks §1 against the
        // LISTING rather than against the verdict. No live breach ran through them — every tier-3
        // SOCIAL signal reaches `reasons[]` and the conflict rule catches it — but "no breach today"
        // is what an untested guarantee always looks like.
        foreach (['LABELS', 'AMBIGUOUS_LABELS', 'PROCEDURAL'] as $table) {
            /** @var array<string, Tenure> $labels */
            $labels = $class->getConstant($table);

            self::assertNotSame([], $labels, sprintf('%s is empty — §1 lost its vocabulary', $table));

            foreach ($labels as $literal => $tenure) {
                if ($tenure->isExcluded()) {
                    // Folded, because the haystack is `Text::fold()` output. `AMBIGUOUS_LABELS`
                    // keys are UPPERCASE — case is evidence in the classifier's own prose guard —
                    // and an unfolded `PLUS` would match nothing at all here.
                    $tokens[] = Text::fold($literal);
                }
            }
        }

        self::assertContains('plus', $tokens, 'the excluded-token list lost `plus` — see this method');

        return array_values(array_unique($tokens));
    }

    /**
     * Every §1 exemption must still be doing work.
     *
     * An exemption that no longer matches anything is not harmless: it is the fingerprint of the
     * assertion having stopped looking. When {@see excludedTokens()} briefly read only `LABELS`, the
     * whole `plus` vocabulary vanished from the invariant and the suite stayed green — the only
     * visible trace was six exemptions that no longer excused anything. This test turns that trace
     * into a failure.
     */
    public function testEveryExclusionExemptionIsStillEarned(): void
    {
        $classifier = new TenureClassifier();
        $tokens = self::excludedTokens();

        foreach (self::exclusionExemptions() as $id => $byToken) {
            // An exemption with NO tokens skips the loop below entirely, so it would be unchecked —
            // and an unchecked exemption entry is exactly the dead weight this test exists to
            // reject. Found by leaving a placeholder behind during the round-6 fix: the suite
            // stayed green with a fixture id in the table that does not exist in the corpus.
            self::assertNotSame([], $byToken, sprintf('exemption for %s names no token', $id));

            foreach ($byToken as $token => $reason) {
                self::assertContains(
                    $token,
                    $tokens,
                    sprintf('fixture %s is exempted for "%s", which is no longer an excluded token', $id, $token),
                );

                $found = false;

                foreach (Corpus::provider() as $caseId => [$listing, $source]) {
                    if ($caseId !== $id) {
                        continue;
                    }

                    $found = true;
                    $haystack = Text::fold($listing->text() . ' ' . implode(' ', $listing->fields));

                    self::assertSame(
                        Outcome::MATCH,
                        $classifier->classify($listing, $source)->outcome,
                        sprintf('fixture %s no longer reaches MATCH, so its exemption is dead — remove it', $id),
                    );
                    self::assertNotNull(
                        Text::inflectedTokenPosition($haystack, $token, true),
                        sprintf('fixture %s no longer contains "%s" — remove the exemption', $id, $token),
                    );
                    self::assertNotSame('', trim($reason), sprintf('fixture %s exempts "%s" with no reason', $id, $token));
                }

                self::assertTrue($found, sprintf('exemption names fixture %s, which is not in the corpus', $id));
            }
        }
    }

    /**
     * The one rule this project exists to enforce, asserted over the whole corpus at once rather
     * than case by case — so it holds even if someone adds a fixture and forgets what it implies.
     */
    public function testNoExcludedTenureEverReachesAMatch(): void
    {
        $classifier = new TenureClassifier();

        foreach (Corpus::provider() as $id => [$listing, $source]) {
            $result = $classifier->classify($listing, $source);

            if ($result->tenure->isExcluded()) {
                self::assertSame(
                    Outcome::REJECT,
                    $result->outcome,
                    sprintf('fixture %s: %s reached %s', $id, $result->tenure->value, $result->outcome->value),
                );
            }

            self::assertNotSame(
                Outcome::MATCH,
                $result->tenure === Tenure::UNKNOWN ? $result->outcome : Outcome::DIGEST,
                sprintf('fixture %s: an undetermined tenure was routed to the notification channel', $id),
            );
        }
    }

    /**
     * The corpus knows how many of its own entries are synthetic, and the suite checks it.
     *
     * `spec/PROJECT_BRIEF.md` §4 asks for >=30 REAL listing texts. Real texts cannot be captured
     * until a source endpoint or a browser session exists. Rather than let that gap live in a
     * comment nobody re-reads, the corpus declares its own composition and this assertion keeps the
     * declaration honest: the day real payloads are frozen in, the counts have to be updated
     * deliberately.
     */
    public function testCorpusDeclaresItsOwnProvenanceHonestly(): void
    {
        $corpus = Corpus::load();

        $actual = ['synthetic' => 0, 'captured' => 0];

        foreach ($corpus['cases'] as $case) {
            /** @var array{provenance:string} $case */
            ++$actual[$case['provenance']];
        }

        self::assertSame(
            $corpus['declared_counts'],
            $actual,
            'the corpus declares a provenance mix it does not have — update declared_counts deliberately',
        );
    }

    /** `spec/PROJECT_BRIEF.md` §4 sets the floor at 30 cases. */
    public function testCorpusMeetsTheSpecifiedMinimumSize(): void
    {
        self::assertGreaterThanOrEqual(30, count(Corpus::load()['cases']));
    }

    /** Fixture ids are how a failure is identified; two cases sharing one would hide a regression. */
    public function testCorpusIdsAreUnique(): void
    {
        $ids = array_column(Corpus::load()['cases'], 'id');

        self::assertSame(array_values(array_unique($ids)), $ids);
    }

    /**
     * The five cases `spec/PROJECT_BRIEF.md` §4 names by hand must all be present.
     *
     * Named explicitly because "30 cases" is satisfiable with 30 easy ones. These five are the
     * shapes the spec author knew were hard.
     */
    public function testCorpusCoversEveryShapeTheSpecNamed(): void
    {
        $classifier = new TenureClassifier();
        $seen = [
            'pure-LLI source' => false,
            'mixed-tenure source' => false,
            'explicit PLAI' => false,
            'explicit PLS' => false,
            'ambiguous case' => false,
        ];

        foreach (Corpus::provider() as [$listing, $source]) {
            $result = $classifier->classify($listing, $source);

            $seen['pure-LLI source'] = $seen['pure-LLI source'] || (!$source->mixedTenure && $result->tenure === Tenure::LLI);
            $seen['mixed-tenure source'] = $seen['mixed-tenure source'] || $source->mixedTenure;
            $seen['explicit PLAI'] = $seen['explicit PLAI'] || $result->tenure === Tenure::PLAI;
            $seen['explicit PLS'] = $seen['explicit PLS'] || $result->tenure === Tenure::PLS;
            $seen['ambiguous case'] = $seen['ambiguous case'] || $result->tenure === Tenure::UNKNOWN;
        }

        foreach ($seen as $shape => $covered) {
            self::assertTrue($covered, sprintf('corpus has no case covering: %s', $shape));
        }
    }

    /** Every notification carries `reasons[]` (`spec/PROJECT_BRIEF.md` §5) — so they must exist. */
    public function testEveryVerdictWithEvidenceExplainsItself(): void
    {
        $classifier = new TenureClassifier();

        foreach (Corpus::provider() as $id => [$listing, $source]) {
            $result = $classifier->classify($listing, $source);

            if ($result->signals === []) {
                continue;
            }

            self::assertNotEmpty($result->reasons(), sprintf('fixture %s produced signals but no reasons', $id));

            foreach ($result->reasons() as $reason) {
                self::assertNotSame('', trim($reason), sprintf('fixture %s produced an empty reason', $id));
            }
        }
    }

    /**
     * No reason ever contains a raw newline — `reasons[]` is rendered on a phone lock screen.
     *
     * Folding began preserving newlines so the title/description boundary could act as a phrase
     * break, and the reason strings quote the text that ACTUALLY matched. A multi-word label
     * straddling that join therefore rendered as « logement\nintermediaire » in a notification, and
     * a hard-wrapped `text/plain` IMAP alert body — the PRIMARY ingestion path per `CLAUDE.md` hard
     * rule 4 — does it mid-sentence. Asserted over the whole corpus rather than case by case,
     * because the next surface to acquire a quoted fragment will not think to add its own test.
     */
    public function testNoReasonEverContainsARawNewline(): void
    {
        $classifier = new TenureClassifier();

        foreach (Corpus::provider() as $id => [$listing, $source]) {
            foreach ($classifier->classify($listing, $source)->reasons() as $reason) {
                self::assertDoesNotMatchRegularExpression(
                    '/\s\s|[\r\n\t]/u',
                    $reason,
                    sprintf('fixture %s produced a multi-line or double-spaced reason: %s', $id, json_encode($reason)),
                );
            }
        }
    }

    /**
     * Every value object in the core is immutable, not just the one a review happened to name.
     *
     * `TenureSignal` silently lost `final readonly` when `$length` gained a computed default, and
     * the fix that restored it was pinned by a test naming that class alone — while the argument
     * for the fix was that *"a caller holding a `Classification` could rewrite the `reasons[]` a
     * notification is built from"*. `Classification` had no such test, and neither did the other
     * four: all five could lose the keyword with the suite green. Reflection over the namespace
     * closes the whole class of regression at once, including for classes not yet written.
     */
    public function testEveryCoreValueObjectIsImmutable(): void
    {
        $mutable = [];
        $exempt = [];

        // RECURSIVE. `glob('*.php')` does not cross a directory separator, and this method's own
        // docblock claimed it closed the class "including for classes not yet written" — while
        // `CLAUDE.md`'s architecture table puts the Notify layer at `src/php/Core/Notify/`. A
        // `Formatter` there holding a writable `reasons` array is precisely the mutation the ruling
        // was written against, and it passed.
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(__DIR__ . '/../../../../src/php', \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            $file = (string) $file;

            if (!str_ends_with($file, '.php')) {
                continue;
            }

            // PSR-4: `src/php/` maps to `Scout\`, so a subdirectory is a sub-namespace.
            $relative = substr($file, strpos($file, 'src/php/') + strlen('src/php/'));
            $class = 'Scout\\' . str_replace('/', '\\', substr($relative, 0, -4));

            if (!class_exists($class)) {
                continue;                          // enums and interfaces have no mutable state
            }

            $reflection = new \ReflectionClass($class);

            if ($reflection->isAbstract() || $reflection->isEnum() || is_a($class, \Throwable::class, true)) {
                // Enums have no writable state by construction; exceptions inherit mutable state
                // from `Exception` and cannot be readonly at all.
                continue;
            }

            if ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC | \ReflectionProperty::IS_PROTECTED | \ReflectionProperty::IS_PRIVATE) === []) {
                continue;                          // a static-only utility (`Text`) holds nothing
            }

            if (is_a($class, \Scout\Core\MutableByDesign::class, true)) {
                // Declared at the class, not listed here — see that interface's docblock for why an
                // exemption living inside this test would rot. Every implementor is still pinned by
                // the assertion below, so adding one is a visible change to this file.
                $exempt[] = $reflection->getShortName();
                continue;
            }

            if (!$reflection->isReadOnly()) {
                $mutable[] = $reflection->getShortName();
            }
        }

        self::assertSame(
            [],
            $mutable,
            'these core classes are not readonly, so a caller can rewrite a verdict after the fact: '
            . implode(', ', $mutable),
        );

        sort($exempt);
        self::assertSame(
            // IndexSize (2026-08-29): the one mutable cell a readonly SitemapVehicleSource holds —
            // how many lots its index listed last time, written by index() and read by health.
            // A counter whose update IS its mechanism, never handed to a caller as a result.
            // PatternMissLog (2026-08-31, Track 1h): how often each CONFIGURED positional pattern
            // found nothing this pass. The same shape as IndexSize — a counter whose update IS its
            // mechanism, held by a `final readonly` adapter that could not otherwise accumulate
            // anything, and read only by `health()` and `doctor`. It carries no verdict: nothing
            // in it can change what a listing was judged to be.
            ['ImapMailbox', 'IndexSize', 'Pacer', 'PatternMissLog', 'Reader', 'WatchLoop'],
            $exempt,
            'the MutableByDesign set changed. Every entry must be a non-value-object whose mutation '
            . 'IS its mechanism and which is never handed to a caller as a result — argue it here',
        );
    }

    /** The differential-test contract: the same input always yields the same bytes. */
    public function testClassificationIsDeterministic(): void
    {
        $classifier = new TenureClassifier();

        foreach (Corpus::provider() as $id => [$listing, $source]) {
            $first = $classifier->classify($listing, $source)->toArray();
            $second = (new TenureClassifier())->classify($listing, $source)->toArray();

            self::assertSame($first, $second, sprintf('fixture %s is not deterministic', $id));
        }
    }

    /** Confidence is an integer 0..100 internally and a 0..1 float at the boundary. */
    public function testConfidenceStaysInRange(): void
    {
        $classifier = new TenureClassifier();

        foreach (Corpus::provider() as $id => [$listing, $source]) {
            $result = $classifier->classify($listing, $source);

            self::assertGreaterThanOrEqual(0, $result->confidenceBp, $id);
            self::assertLessThanOrEqual(100, $result->confidenceBp, $id);
            // Exact, not within-epsilon: `confidence()` is defined as this division, so any
            // difference at all would mean the boundary conversion had grown logic of its own.
            // Cast because PHP's `/` returns int when the division is exact (0/100 === int 0)
            // while `confidence()` is declared `: float`.
            self::assertSame((float) ($result->confidenceBp / 100), $result->confidence(), $id);
            self::assertInstanceOf(Classification::class, $result);
        }
    }
}
