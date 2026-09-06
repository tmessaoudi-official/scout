<?php

declare(strict_types=1);

namespace Scout\Tests\Rent\Core;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Scout\Rent\Core\Outcome;
use Scout\Rent\Core\PlafondBands;
use Scout\Rent\Core\RawListing;
use Scout\Rent\Core\SourceProfile;
use Scout\Rent\Core\Tenure;
use Scout\Rent\Core\TenureClassifier;
use Scout\Rent\Core\TenureSignal;

/**
 * Structural invariants — the properties that must hold for EVERY input, not just the corpus ones.
 *
 * The corpus proves the classifier gets every case in `tests/fixtures/rent/tenure/corpus.json` right. This file proves the shape of
 * the module cannot drift: that the excluded set is fixed in code, that the floor is where the
 * rule says, that an unlabelled source fails closed, and that tier 4 is still honestly inert.
 */
#[CoversClass(TenureClassifier::class)]
#[CoversClass(Tenure::class)]
final class TenureClassifierTest extends TestCase
{
    private const string MIXED = 'cdc_habitat';

    /** `CLAUDE.md` §1: the excluded set is exactly this, and it is not extensible. */
    public function testExcludedSetIsExactlyTheDocumentedOne(): void
    {
        $excluded = array_values(array_map(
            static fn (Tenure $t): string => $t->value,
            array_filter(Tenure::cases(), static fn (Tenure $t): bool => $t->isExcluded()),
        ));

        sort($excluded);

        self::assertSame(
            ['ANAH', 'ANRU', 'CONVENTIONNE', 'PLAI', 'PLS', 'PLUS', 'SOCIAL'],
            $excluded,
        );
    }

    /** Eligible and excluded must partition every case except the deliberate abstention. */
    public function testEveryTenureIsEligibleOrExcludedOrExplicitlyUndetermined(): void
    {
        foreach (Tenure::cases() as $tenure) {
            if ($tenure === Tenure::UNKNOWN) {
                self::assertFalse($tenure->isEligible());
                self::assertFalse($tenure->isExcluded());

                continue;
            }

            self::assertNotSame(
                $tenure->isEligible(),
                $tenure->isExcluded(),
                sprintf('%s is both or neither', $tenure->value),
            );
        }
    }

    /**
     * The classifier takes no argument that could re-admit an excluded tenure.
     *
     * `CLAUDE.md` §1 calls a config key that re-enables the excluded set a P0 finding "even if
     * nothing currently sets it". The only injectable collaborator is the plafond band table, and
     * this asserts it cannot reach {@see Tenure::isExcluded()}.
     */
    public function testExcludedSetIsNotReachableThroughAnyConstructorArgument(): void
    {
        $constructor = (new \ReflectionClass(TenureClassifier::class))->getConstructor();

        self::assertNotNull($constructor);

        $names = array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            $constructor->getParameters(),
        );

        // `profileFor` joined `bands` on 2026-09-01 (Track 5a, the advertiser substitution), and
        // this test FIRED on it — correctly, because it is a second injectable collaborator that
        // reaches the tenure decision. Adding the name to this list is NOT the fix on its own; the
        // assertion below is. A resolver returning the loosest profile the schema permits must not
        // loosen anything, because `LandlordRegistry::stricterOf()` merges field by field and can
        // only tighten. Without that property, a resolver — a config typo, a future caller — would
        // turn a fail-closed source into a matching one, which §1 calls a P0 "even if nothing
        // currently sets it".
        self::assertSame(['bands', 'profileFor'], $names);

        $hostileResolver = static fn (string $key): SourceProfile => new SourceProfile(
            name: $key,
            family: 'private',
            defaultTenure: Tenure::LIBRE,
            mixedTenure: false,
        );

        // CDC Habitat is in the registry, so the substitution genuinely runs here; the source it
        // starts from is the strict one, and the hostile resolver offers the lax one.
        $strict = new SourceProfile(name: 'portal', family: 'private', defaultTenure: null, mixedTenure: true);
        $listing = new RawListing(
            sourceName: 'portal',
            externalId: '1',
            title: 'Appartement 3 pieces',
            description: 'Appartement 3 pieces 65 m2, cuisine equipee.',
            advertiser: 'CDC HABITAT',
        );

        $lax = (new TenureClassifier(profileFor: $hostileResolver))->classify($listing, $strict);
        $control = (new TenureClassifier())->classify($listing, $strict);

        self::assertSame(
            $control->outcome,
            $lax->outcome,
            'a resolver offering mixedTenure:false + LIBRE must not loosen the verdict — '
                . 'LandlordRegistry::stricterOf() merges toward the stricter profile, always',
        );
        self::assertNotSame(
            Outcome::MATCH,
            $lax->outcome,
            'a card with no tenure statement, advertised by a mixed-tenure bailleur, must not match',
        );

        // A band table claiming an intermediate tenure is now REFUSED OUTRIGHT — strictly stronger
        // than the previous guarantee, which merely survived one. Tier 4 answers SOCIAL or nothing:
        // reading a NUMBER as proof that a listing is eligible is the §1-dangerous direction, and
        // `PlafondBands`' own measurement shows such a reading would be wrong across a 73 451 €
        // overlap. Refused at construction so it cannot be reintroduced by editing a table.
        try {
            new PlafondBands(['A' => ['max' => 999_999, 'tenure' => Tenure::LLI]]);
            self::fail('a band asserting an intermediate tenure must be refused, not merely survived');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('SOCIAL', $e->getMessage());
        }

        // And the excluded set survives the most permissive table that IS constructible: a social
        // band with an absurd ceiling, which fires tier 4 on everything and still cannot promote
        // anything — a lower-priority signal never overrides a higher one.
        $classifier = new TenureClassifier(new PlafondBands([
            PlafondBands::IDF => ['max' => 999_999, 'tenure' => Tenure::SOCIAL],
        ]));
        $result = $classifier->classify($this->listing(fields: ['financement' => 'PLAI']), $this->source());

        self::assertSame(Tenure::PLAI, $result->tenure);
        self::assertSame(Outcome::REJECT, $result->outcome);
    }

    /** The documented floor, asserted as a number so a silent edit is caught. */
    public function testFailClosedFloorIsSixtyPoints(): void
    {
        self::assertSame(60, TenureClassifier::FLOOR_BP);
    }

    /**
     * A source declared without saying whether it mixes stock must behave as though it does.
     *
     * This is the config-omission case: someone adds a landlord to `config/rent/sources.json`, forgets
     * the flag, and the §1 protection must engage anyway.
     */
    public function testAnUndeclaredSourceFailsClosed(): void
    {
        $bare = new SourceProfile(name: 'newly_added_landlord');

        self::assertTrue($bare->mixedTenure);

        $result = (new TenureClassifier())->classify($this->listing(description: 'Bel appartement.'), $bare);

        self::assertSame(Tenure::UNKNOWN, $result->tenure);
        self::assertSame(Outcome::DIGEST, $result->outcome);
    }

    /**
     * Tier 4 is ARMED [2026-08-26]. This test used to assert it was inert, and its own docblock said
     * that when someone loaded real figures the rung would wake up and need fixtures of its own —
     * which is what happened, so the wake-up is the planned event rather than a weakened test. The
     * fixtures it asked for are in {@see PlafondBandsTest} and in the corpus.
     *
     * What it asserts now is the property that must not silently reverse: the tier is loaded, its
     * threshold is DERIVED from the committed table rather than written beside it, and it can only
     * ever conclude SOCIAL.
     */
    public function testPlafondTierIsArmedFromTheCommittedFigures(): void
    {
        $bands = (new TenureClassifier())->plafondBands();

        self::assertFalse($bands->isEmpty(), 'tier 4 shipped inert for want of the figures; they are committed now');
        self::assertSame(
            min(array_map(min(...), PlafondBands::LLI_2026)),
            $bands->bands[PlafondBands::IDF]['max'],
            'the threshold must stay derived from the table, or the two drift at the next revaluation',
        );

        foreach ($bands->bands as $zone => $band) {
            self::assertSame(Tenure::SOCIAL, $band['tenure'], "zone {$zone}: tier 4 never asserts eligibility");
        }
    }

    /**
     * The extraction, which is where the dangerous false positive lives.
     *
     * The band comparison is arithmetic; deciding WHAT NUMBER to compare is the risky half. The
     * third case here is load-bearing: this classifier's own vocabulary note records that
     * `loyer plafonné` is *the primary target describing itself*, so an anchor on a bare `plafond`
     * would hand the tier a rent, and every rent is below every threshold.
     */
    #[DataProvider('ceilingProse')]
    public function testTheCeilingReaderOnlyFiresOnAStatedIncomeCeiling(string $text, bool $shouldFire, string $why): void
    {
        $result = (new TenureClassifier())->classify(
            $this->listing(description: $text),
            new SourceProfile(name: 'portal', mixedTenure: true),
        );

        $fired = str_contains(implode(' | ', $result->reasons()), 'plafond de ressources annoncé');

        self::assertSame($shouldFire, $fired, $why);
    }

    /** @return iterable<string, array{string, bool, string}> */
    public static function ceilingProse(): iterable
    {
        yield 'a stated ceiling below every intermediate one' => [
            'Logement soumis à plafond de ressources : 26 920 € de revenu fiscal de référence.',
            true,
            'PLUS one-person, below every Ile-de-France intermediate ceiling',
        ];
        yield 'a stated ceiling inside the overlap' => [
            'Plafond de ressources : 44 344 € pour une personne seule.',
            false,
            'an INTERMEDIATE ceiling — firing here is the false positive the tier exists to avoid',
        ];
        yield 'the rent cap, which is the primary target describing itself' => [
            'Bel appartement, loyer plafonné à 1 250 € charges comprises.',
            false,
            'a bare `plafond` anchor would read the RENT as an income ceiling and reject a real match',
        ];
        yield 'the absence of a ceiling, advertised' => [
            'Location libre, sans plafond de ressources, dossier simplifié.',
            false,
            'the negation must be read first — a signal manufactured from its own negation',
        ];
        yield 'a figure too small to be an annual income' => [
            'Plafond de ressources mensuel indicatif : 1 450 €.',
            false,
            'the plausibility floor: a rent or charge near the anchor is below every threshold',
        ];
        yield 'no ceiling stated at all' => [
            'Appartement T3 lumineux, proche des transports.',
            false,
            'nothing to read',
        ];
    }

    /**
     * The source default must stay below the floor on its own.
     *
     * `CLAUDE.md`: "An absent signal must lower confidence, never silently inherit `default_tenure`
     * at full confidence." Asserted for every tenure a source could declare.
     *
     * @return iterable<string, array{Tenure}>
     */
    public static function everyTenure(): iterable
    {
        foreach (Tenure::cases() as $tenure) {
            yield $tenure->value => [$tenure];
        }
    }

    #[DataProvider('everyTenure')]
    public function testSourceDefaultAloneStaysBelowTheFloor(Tenure $declared): void
    {
        $result = (new TenureClassifier())->classify(
            $this->listing(description: 'Appartement 3 pieces, 65 m2.'),
            new SourceProfile(name: 'x', defaultTenure: $declared, mixedTenure: true),
        );

        self::assertLessThan(TenureClassifier::FLOOR_BP, $result->confidenceBp);
        self::assertNotSame(Outcome::MATCH, $result->outcome);
    }

    /**
     * The asymmetry of the conflict rule, stated as a property.
     *
     * An eligible verdict contradicted by an excluded signal withholds; an excluded verdict
     * contradicted by an eligible signal does not soften. Both directions asserted together so a
     * future "let's make this symmetric for consistency" refactor goes red.
     */
    public function testConflictRuleOnlyEverMovesTowardWithholding(): void
    {
        $classifier = new TenureClassifier();

        $eligibleContradicted = $classifier->classify(
            $this->listing(description: 'Logement PLAI.', fields: ['financement' => 'LLI']),
            $this->source(),
        );

        self::assertSame(Tenure::UNKNOWN, $eligibleContradicted->tenure);
        self::assertSame(Outcome::DIGEST, $eligibleContradicted->outcome);

        $excludedContradicted = $classifier->classify(
            $this->listing(description: 'Logement intermediaire.', fields: ['financement' => 'PLAI']),
            $this->source(),
        );

        self::assertSame(Tenure::PLAI, $excludedContradicted->tenure);
        self::assertSame(Outcome::REJECT, $excludedContradicted->outcome);
    }

    /** A higher tier decides; a lower one may only move confidence. */
    public function testLowerTierNeverOverridesHigherTier(): void
    {
        $result = (new TenureClassifier())->classify(
            // Tier 2 says LIBRE; the source default (tier 5) says LLI and must not win.
            $this->listing(description: 'Logement a loyer libre.'),
            new SourceProfile(name: 'inli', defaultTenure: Tenure::LLI, mixedTenure: false),
        );

        self::assertSame(Tenure::LIBRE, $result->tenure);
    }

    /** Tier 5 is consulted only when nothing else fires. */
    public function testSourceDefaultIsSilentWhenRealEvidenceExists(): void
    {
        $result = (new TenureClassifier())->classify(
            $this->listing(description: 'Logement a loyer libre.'),
            new SourceProfile(name: 'inli', defaultTenure: Tenure::LLI, mixedTenure: false),
        );

        $tiers = array_map(static fn ($s): int => $s->tier, $result->signals);

        self::assertNotContains(5, $tiers);
    }

    /** An empty structured field is an absent signal, not a verdict. */
    public function testEmptyStructuredFieldDoesNotFireTierOne(): void
    {
        $result = (new TenureClassifier())->classify(
            $this->listing(description: 'Appartement.', fields: ['financement' => '   ']),
            $this->source(),
        );

        self::assertSame(Tenure::UNKNOWN, $result->tenure);
        self::assertSame([], $result->signals);
    }

    /**
     * A financing CODE in a tenure field is read as a code whatever its case; PROSE is read as prose.
     *
     * The predecessor of this test used CASE as the whole discriminator — an uppercase `PLUS` in a
     * tenure field was a code, a lowercase one was a word. Both halves were wrong. A feed that
     * lowercases its own field values (`financement: plus`) emitted NO signal and NO doubt, and the
     * listing then matched on its description: the strongest rung of the ladder, silent, on an
     * explicitly social financing code. In the other direction `PINEL PLUS` — a real 2023 scheme,
     * routinely shouted in a `categorie` field — became tenure PLUS at confidence 97 and a silent
     * REJECT of a listing that has nothing to do with social housing.
     *
     * The discriminator is the SHAPE of the value, not its case: a value made only of financing
     * acronyms and short uppercase code fragments is a code list; anything carrying a real French
     * word is prose, and prose goes through the same collocation guard the description does.
     *
     * @param array{0:string,1:?Tenure} $expect tenure, or null for "no tier-1 signal at all"
     */
    #[DataProvider('fieldValueShapes')]
    public function testTenureFieldValuesAreReadByShapeNotByCase(string $value, ?Tenure $expect): void
    {
        $result = (new TenureClassifier())->classify(
            $this->listing(description: 'Appartement renove.', fields: ['categorie' => $value]),
            $this->source(),
        );

        $tierOne = array_values(array_filter($result->signals, static fn ($s): bool => $s->tier === 1));

        if ($expect === null) {
            self::assertSame([], $tierOne, sprintf('"%s" should produce no tier-1 signal', $value));

            return;
        }

        self::assertNotSame([], $tierOne, sprintf('"%s" produced no tier-1 signal — a fail-open', $value));
        // Containment, not `$tierOne[0]`: `PLUS / PLAI` legitimately yields two tier-1 signals and
        // their order is the resolver's business, not this test's.
        self::assertContains(
            $expect,
            array_map(static fn ($s): Tenure => $s->tenure, $tierOne),
            sprintf('"%s"', $value),
        );
    }

    /** @return iterable<string, array{string, ?Tenure}> */
    public static function fieldValueShapes(): iterable
    {
        // Codes — every one of these must reach a determinate PLUS, regardless of case.
        yield 'bare code, uppercase' => ['PLUS', Tenure::PLUS];
        yield 'bare code, lowercase — the feed that lowercases everything' => ['plus', Tenure::PLUS];
        yield 'bare code, title case' => ['Plus', Tenure::PLUS];
        yield 'code with a modifier' => ['PLUS CD', Tenure::PLUS];
        yield 'enumerated codes' => ['PLUS / PLAI', Tenure::PLUS];
        yield 'lowercase enumeration' => ['plai, plus', Tenure::PLAI];

        // Prose that names the word without a financing collocation — a DOUBT, never silence.
        //
        // These four returned NO tier-1 signal at all until review round 5, which is how
        // `financement: "Prêt PLUS"` reached MATCH at confidence 50 on In'li with `reasons[]`
        // saying "aucun signal dans l'annonce". Inside a TENURE FIELD the field name is already the
        // financing collocation, so the guard's "no context found" is not an answer here. The cost
        // is that a scheme name and a typology now digest instead of matching: one glance each,
        // against an application for the alternative.
        yield 'Pinel Plus is a scheme name, but the field cannot say so' => ['Pinel Plus', Tenure::UNKNOWN];
        yield 'shouted scheme name' => ['PINEL PLUS', Tenure::UNKNOWN];
        yield 'a financing noun outside the closed COLLOCATION list' => ['Prêt PLUS', Tenure::UNKNOWN];
        yield 'a separator the code-list splitter does not know' => ['PLUS|CD', Tenure::UNKNOWN];

        // A typology token carries a digit, so it is not a code fragment and the value is prose.
        yield 'T3 PLUS is a typology, but the field cannot say so' => ['T3 PLUS', Tenure::UNKNOWN];

        // The ONE case that stays silent: a known comparative proves the adverb. That tail is the
        // adverb's own grammar — a closed set — unlike the open set of nouns a scheme name follows.
        yield 'the adverb, shouted' => ['MAISON DE PLUS DE 100 M2', null];
        yield 'the adverb, lowercase' => ['maison de plus de 100 m2', null];

        // Prose that IS in a financing collocation stays decidable — the guard still runs.
        yield 'prose in collocation' => ['Logement PLUS.', Tenure::PLUS];
    }

    /**
     * `LOGEMENT PLUS MODERNE` in a tenure field is indécidable, and must digest rather than vanish.
     *
     * The prose branch is not a licence to go quiet: when the collocation guard reaches its third
     * answer, the field produces a DOUBT exactly as the description would.
     */
    public function testAnIndecidableTenureFieldProducesADoubtNotSilence(): void
    {
        $result = (new TenureClassifier())->classify(
            $this->listing(description: 'Loyer intermediaire.', fields: ['categorie' => 'LOGEMENT PLUS MODERNE']),
            $this->source(),
        );

        self::assertSame(Tenure::UNKNOWN, $result->tenure);
        self::assertSame(Outcome::DIGEST, $result->outcome);
    }

    /**
     * A field value that is not a string must not crash the run, and must not go silent either.
     *
     * `RawListing::$fields` is annotated `array<string,string>`, and an annotation is not a runtime
     * guarantee: a JSON feed with `"financement": ["PLUS", "PLAI"]` or `{"code": "PLUS"}` decodes to
     * an array, and an adapter that forwards it verbatim reaches here. `(string) $value` turned the
     * array into the literal `"Array"` — no signal, no doubt, tier 1 blind on a field that names an
     * excluded scheme — and turned a stdClass into an uncaught `Error` that kills the whole run.
     *
     * Neither is acceptable, so an unreadable tenure field is a DOUBT: the field claims to carry the
     * one fact this project exists to check, and it could not be read.
     *
     * @param array<string,mixed> $fields
     */
    #[DataProvider('unreadableFieldValues')]
    public function testAnUnreadableTenureFieldDigestsRatherThanCrashingOrGoingSilent(array $fields): void
    {
        /** @var array<string,string> $fields intentionally violated — that is the point of this test */
        $result = (new TenureClassifier())->classify(
            $this->listing(description: 'Loyer intermediaire, proche RER.', fields: $fields),
            $this->source(),
        );

        self::assertSame(Tenure::UNKNOWN, $result->tenure);
        self::assertSame(Outcome::DIGEST, $result->outcome);
    }

    /** @return iterable<string, array{array<string,mixed>}> */
    public static function unreadableFieldValues(): iterable
    {
        yield 'a list, as a JSON feed would decode it' => [['financement' => ['PLUS', 'PLAI']]];
        yield 'an object with no __toString' => [['categorie' => new \stdClass()]];
        yield 'a nested map' => [['dispositif' => ['code' => 'PLUS']]];
    }

    /**
     * `conventionné` is excused only by an intermediate label that PRECEDES it.
     *
     * Asserted here rather than left to the span arithmetic. The rule used to carry an explicit
     * `position > $s->position → skip`, and sabotage-verification proved that clause unreachable:
     * a label starting after `conventionné` also ends after it, so it already fails the adjacency
     * test. The clause was deleted as dead code — but the PROPERTY it expressed is still a real
     * requirement, and deleting the only place that stated it would have left it holding by
     * accident. `conventionné logement intermédiaire` is two noun phrases, not a qualifier.
     */
    public function testConventionneIsOnlyExcusedByALabelThatPrecedesIt(): void
    {
        $classifier = new TenureClassifier();

        $qualifier = $classifier->classify(
            $this->listing(description: 'Logement intermediaire conventionne.'),
            $this->source(),
        );
        $reversed = $classifier->classify(
            $this->listing(description: 'Conventionne, logement intermediaire.'),
            $this->source(),
        );

        self::assertSame(Tenure::LLI, $qualifier->tenure, 'the adjective after its noun IS the exception');
        self::assertSame(Outcome::MATCH, $qualifier->outcome);

        self::assertNotSame(Tenure::LLI, $reversed->tenure, 'conventionne BEFORE a label is not qualified by it');
        self::assertNotSame(Outcome::MATCH, $reversed->outcome);
    }

    /**
     * The evidence trail behind a verdict cannot be rewritten by whoever holds it.
     *
     * `reasons[]` is what a notification is built from (`spec/PROJECT_BRIEF.md` §5), so a mutable
     * `TenureSignal` means a caller can make a PLAI rejection describe itself as an LLI match.
     * `TenureSignal` was `final readonly` until `$length` gained a computed default — promoting the
     * parameter forced the assignment into the constructor body, which is illegal in a readonly
     * class, and the class keyword was dropped instead of the promotion. All six properties went
     * writable, silently, and no test noticed. A non-promoted readonly property does the same job.
     */
    public function testASignalCannotBeRewrittenAfterTheVerdictIsFormed(): void
    {
        $signal = new TenureSignal(tier: 2, tenure: Tenure::PLAI, reason: 'r', evidence: 'plai', position: 5);

        self::assertSame(4, $signal->length, 'the computed default must survive the readonly rewrite');

        foreach (['tier', 'tenure', 'reason', 'evidence', 'position', 'length'] as $property) {
            try {
                $signal->{$property} = $property === 'tenure' ? Tenure::LLI : 1;
                self::fail(sprintf('TenureSignal::$%s is writable — the §1 evidence trail is forgeable', $property));
            } catch (\Error $e) {
                self::assertStringContainsString('readonly', $e->getMessage());
            }
        }
    }

    /**
     * `reasons[]` names the text that ACTUALLY matched, not the table literal it matched against.
     *
     * `reasons[]` is the product's only user-facing output (`spec/PROJECT_BRIEF.md` §5) and the
     * whole suite asserted exactly two things about it: that it is non-empty, and that no entry is
     * blank. Reverting `reason:` to `$hit['literal']` — so an inflected match reports a phrase the
     * listing does not contain — left the whole suite green (183 tests at the time). A notification that says
     * « logement locatif intermediaire » for a listing reading *"logements locatifs intermédiaires
     * conventionnés"* is not wrong enough to fail a test and is exactly wrong enough to erode trust.
     */
    public function testReasonsQuoteTheMatchedTextNotTheTableLiteral(): void
    {
        $result = (new TenureClassifier())->classify(
            $this->listing(description: 'Logements locatifs intermediaires, loyer plafonne.'),
            new SourceProfile(name: 'inli', defaultTenure: Tenure::LLI, mixedTenure: false),
        );

        $reasons = implode(' | ', $result->reasons());

        self::assertStringContainsString('logements locatifs intermediaires', $reasons);
        self::assertStringNotContainsString(
            '« logement locatif intermediaire »',
            $reasons,
            'the reason quotes the singular table literal, which this listing does not say',
        );
    }

    /**
     * A doubt explains itself as a doubt, so the digest entry is actionable.
     *
     * The three tier-1 doubt reasons — unreadable field, indécidable value, and the round-5 prose
     * floor — are the only text a human sees when deciding whether to open a digested listing.
     */
    #[DataProvider('doubtReasons')]
    public function testEveryDoubtSaysWhatCouldNotBeDecided(mixed $value, string $expected): void
    {
        /** @var array<string,string> $fields */
        $fields = ['categorie' => $value];

        $result = (new TenureClassifier())->classify(
            $this->listing(description: 'Loyer intermediaire, proche RER.', fields: $fields),
            $this->source(),
        );

        self::assertSame(Outcome::DIGEST, $result->outcome);
        self::assertStringContainsString($expected, implode(' | ', $result->reasons()));
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function doubtReasons(): iterable
    {
        yield 'unreadable field names its type' => [new \stdClass(), 'illisible (stdClass)'];
        yield 'indecidable value says so' => ['LOGEMENT PLUS MODERNE', 'indécidable'];
        yield 'unplaceable acronym says so' => ['Prêt PLUS', 'indécidable'];
    }

    /** `SOCIAL` corroborates any excluded tenure rather than contradicting it. */
    public function testSocialAgreesWithAnyExcludedTenure(): void
    {
        self::assertTrue(Tenure::SOCIAL->agreesWith(Tenure::PLAI));
        self::assertTrue(Tenure::PLUS->agreesWith(Tenure::SOCIAL));
        self::assertFalse(Tenure::SOCIAL->agreesWith(Tenure::LLI));
        self::assertFalse(Tenure::LLI->agreesWith(Tenure::LIBRE));
        self::assertTrue(Tenure::LLI->agreesWith(Tenure::LLI));
    }

    /** @param array<string,string> $fields */
    private function listing(string $title = 'T3', string $description = '', array $fields = []): RawListing
    {
        return new RawListing(
            sourceName: self::MIXED,
            externalId: 'unit',
            title: $title,
            description: $description,
            fields: $fields,
        );
    }

    private function source(): SourceProfile
    {
        return new SourceProfile(name: self::MIXED, defaultTenure: null, mixedTenure: true);
    }
    // ------------------------------------------------- the config's own tenure_field must be tier 1

    /**
     * `tenure_field` is the field map's declaration of "THIS is the financing field" — so the
     * classifier has to recognise the name the mapper stores it under.
     *
     * It did not. `FieldMap::tenure_field` writes its value into `RawListing::$fields` under the key
     * `tenureField`, and `TENURE_FIELDS` lists the names sources use in their own payloads
     * (`financement`, `typeProduit`, `categorie`) — not that one. So the mapping documented in
     * `/add-source` Step 4 as "the highest-value mapping, look hard for it" produced NO tier-1
     * signal at all, and fell through to the unrecognised-field path, which only raises a doubt when
     * the value contains EXCLUDED vocabulary.
     *
     * Found against CDC Habitat's real payload [2026-08-20]: all 16 cards on the frozen page
     * classified `UNKNOWN / 0.00 / DIGEST`, including the 14 whose own badge reads
     * `Logement intermédiaire`. §1 held — nothing reached a match — but it held by accident, and
     * the visible half of the bug is over-rejection: a correctly-labelled intermediate source
     * emitting zero matches looks exactly like a quiet market.
     */
    public function testTheConfiguredTenureFieldIsReadAsAFinancingField(): void
    {
        $listing = new RawListing(
            sourceName: 'cdc_habitat',
            externalId: '1',
            title: '2 pièces - RDC - 48m²',
            description: 'Ascenseur, Eau froide comprise, Parking',
            fields: ['tenureField' => 'Logement intermédiaire'],
        );

        $verdict = (new TenureClassifier())->classify($listing, new SourceProfile(name: 'cdc_habitat', defaultTenure: null, mixedTenure: true));

        self::assertSame(Tenure::LLI, $verdict->tenure);
        self::assertGreaterThanOrEqual(0.6, $verdict->confidence(), 'a tier-1 field is not a doubt');
    }

    /**
     * The same path, pointed the other way — and this is the half that matters for §1.
     *
     * A source that declares its financing field and says `Logement social` must REJECT, not merely
     * raise a doubt. Before the fix this produced an `UNKNOWN` digest entry, which is safe but wrong
     * for the wrong reason: safe because the value happened to contain excluded vocabulary, not
     * because the field was understood.
     */
    public function testAConfiguredTenureFieldSayingSocialIsExcludedOutright(): void
    {
        $listing = new RawListing(
            sourceName: 'cdc_habitat',
            externalId: '2',
            title: '4 pièces - 2ème étage - 78m²',
            description: 'Ascenseur',
            fields: ['tenureField' => 'Logement social'],
        );

        $verdict = (new TenureClassifier())->classify($listing, new SourceProfile(name: 'cdc_habitat', defaultTenure: null, mixedTenure: true));

        self::assertNotSame(Tenure::LLI, $verdict->tenure);
        self::assertNotSame(Tenure::LIBRE, $verdict->tenure);
        self::assertSame(Outcome::REJECT, $verdict->outcome, 'an explicit social declaration is not a maybe');
    }

    // ------------------------------------------------- `_text` is PROSE, not an identifier field

    /**
     * The card's own text must not veto its own badge because French uses the word *plus*.
     *
     * `HtmlSource` writes the whole card into `fields['_text']` and its docblock calls that "the
     * classifier's text surface". It arrived through the FIELD door, though, and the field path
     * matches `AMBIGUOUS_LABELS` case-INSENSITIVELY — deliberately, on the stated grounds that
     * "neither of these haystacks is prose". `_text` is prose, so *"implanté au plus près des
     * bassins d'emploi"* — CDC Habitat's own tooltip defining logement intermédiaire — matched the
     * excluded financing acronym `PLUS` and contradicted the tier-1 badge down to UNKNOWN.
     *
     * Measured 2026-08-20: identical text in `description` classified LLI 0.97 MATCH, in `_text`
     * UNKNOWN 0.00 DIGEST. `plus` is one of the commonest words in French, so this vetoes almost any
     * card that carries prose; In'li escaped it by luck, not design. Fail-closed, and useless —
     * the over-rejection hard rule 8 names.
     */
    public function testTheCardsOwnProseDoesNotVetoItsBadgeOverTheAdverbPlus(): void
    {
        $listing = new RawListing(
            sourceName: 'cdc_habitat',
            externalId: '1',
            title: '2 pièces - RDC - 48m²',
            description: 'Ascenseur, Eau froide comprise, Parking',
            fields: [
                'tenureField' => 'Logement intermédiaire',
                '_text' => 'Logement intermédiaire Le logement intermédiaire est 10 à 15 % inférieur '
                    . "au prix du marché et implanté au plus près des bassins d'emploi. L'attribution "
                    . 'est soumise à plafonds de ressources.',
            ],
        );

        $verdict = (new TenureClassifier())->classify(
            $listing,
            new SourceProfile(name: 'cdc_habitat', defaultTenure: null, mixedTenure: true),
        );

        self::assertSame(Tenure::LLI, $verdict->tenure);
        self::assertGreaterThanOrEqual(0.6, $verdict->confidence(), 'the adverb "plus" is not the acronym PLUS');
    }

    /**
     * A source-default match needs the detail page to have been READ, not merely to exist.
     *
     * In'li shipped as `mixed_tenure: false` — "pure LLI" — so its listings matched on the source
     * default alone at 50bp, below the 0.60 floor, with the fail-closed rule disarmed. Hydration
     * then proved the source publishes PLS (two live listings, `plafond de ressources PLS`, stated
     * only on the detail page). The premise the flag encoded was false.
     *
     * Arming the flag outright is not the answer either: 166 of In'li's 168 listings sit at 50bp,
     * so every one of them — two thirds of the whole tree's yield — would go to the digest.
     *
     * The fail-closed rule exists because the EVIDENCE is weak, and hydration is the step that
     * gathers it. So a mixed source's weakly-labelled listing digests while its page is unread, and
     * matches once the page has been read and found to say nothing excluding. A source with no
     * detail map never hydrates, so nothing about it changes.
     */
    public function testAWeaklyLabelledListingOnAMixedSourceDigestsUntilItsDetailPageIsRead(): void
    {
        $source = new SourceProfile(name: 'inli', defaultTenure: Tenure::LLI, mixedTenure: true);
        $classifier = new TenureClassifier();

        $card = new RawListing(
            sourceName: 'inli',
            externalId: 'PRV-1',
            title: '',
            description: '1 005 € cc 3 pièces · 55.32 m² Longjumeau',
            fields: [],
            url: 'https://www.inli.fr/x',
        );

        $unread = $classifier->classify($card, $source);
        self::assertSame(
            Outcome::DIGEST,
            $unread->outcome,
            'weak evidence and an unread page is exactly what the fail-closed rule is for',
        );

        $hydrated = $classifier->classify($card->mergedWith(new RawListing(
            sourceName: 'inli',
            externalId: 'PRV-1',
            title: 'Appartement de 55 m² à LONGJUMEAU',
            description: "Le bien est situé au 3e étage avec ascenseur. Chauffage collectif.",
            fields: [],
            url: null,
        )), $source);

        self::assertSame(
            Outcome::MATCH,
            $hydrated->outcome,
            'the page was read and said nothing excluding — that is evidence, not silence',
        );
    }

    /**
     * The counterweight: reading the page does NOT license an excluded listing.
     *
     * If the gate above ever becomes "hydrated means eligible", this goes red. The two live In'li
     * PLS listings are caught by the explicit-label rule at 90bp, which does not depend on the flag
     * or on hydration — but they are only VISIBLE once the page is read, which is the whole point.
     */
    /**
     * THE ROUTING BACKSTOP, PINNED DIRECTLY — because nothing can reach it through `classify()`.
     *
     * `route()` ends `$source->mixedTenure && !$detailRead ? DIGEST : MATCH`, mirroring the
     * fail-closed rule above it. That mirror is UNREACHABLE while the rule stands: every `Tenure`
     * is exactly one of eligible, excluded or UNKNOWN, so reaching that line at all means the
     * tenure is eligible and below the floor — the rule's own condition — and the rule has already
     * rewritten the tenure to UNKNOWN, which `route()` answers three branches earlier.
     *
     * So the ledger case "the fail-closed rule stops requiring the detail page to have been read"
     * could not detect its own second expression: mutating a masked backstop changes nothing
     * observable. Measured on 2026-09-06 — that expression ALONE left the suite green across all
     * 2963 tests, the 130-case corpus and the surface matrix included.
     *
     * The line is not dead code, and that is the reason to pin it rather than delete it: it becomes
     * live exactly when someone removes or weakens the rule above, which is the §1 change most
     * worth surviving. Reflection is the only way in, and asserting through `classify()` would
     * assert the RULE a second time instead — the mirror's whole value is being independent of it.
     *
     * The counterweight is the second half: a card whose detail page WAS read routes to MATCH on
     * the same thin signal, which is the In'li ruling — an examined listing on a source whose
     * social stock declares itself is not the same as an unexamined one.
     */
    public function testTheRoutingMirrorDigestsAnUnreadCardOnAMixedSourceIndependentlyOfTheRule(): void
    {
        $route = (new \ReflectionClass(TenureClassifier::class))->getMethod('route');
        $classifier = new TenureClassifier();
        $mixed = new SourceProfile(name: 'inli', defaultTenure: Tenure::LLI, mixedTenure: true);
        $pure = new SourceProfile(name: 'pap', defaultTenure: Tenure::LIBRE, mixedTenure: false);
        $thin = TenureClassifier::FLOOR_BP - 1;

        self::assertSame(
            Outcome::DIGEST,
            $route->invoke($classifier, Tenure::LLI, $thin, $mixed, false),
            'an unread card on a mixed source, below the floor, is not established — even if the '
                . 'fail-closed rule above ever stops saying so',
        );

        self::assertSame(
            Outcome::MATCH,
            $route->invoke($classifier, Tenure::LLI, $thin, $mixed, true),
            'a card whose own detail page was read and said nothing excluding HAS been examined',
        );
        self::assertSame(
            Outcome::MATCH,
            $route->invoke($classifier, Tenure::LLI, $thin, $pure, false),
            'a source that publishes no social stock has nothing to confuse a thin signal with',
        );
    }

    public function testReadingTheDetailPageNeverLicensesAnExcludedListing(): void
    {
        $source = new SourceProfile(name: 'inli', defaultTenure: Tenure::LLI, mixedTenure: true);

        $card = new RawListing(
            sourceName: 'inli', externalId: 'PRV-317130', title: '',
            description: '1 190 € cc 3 pièces', fields: [], url: 'https://www.inli.fr/y',
        );

        $verdict = (new TenureClassifier())->classify($card->mergedWith(new RawListing(
            sourceName: 'inli', externalId: 'PRV-317130', title: 'Appartement de 62 m²',
            description: 'Le logement est soumis au plafond de ressources PLS.',
            fields: [], url: null,
        )), $source);

        self::assertNotSame(Tenure::LLI, $verdict->tenure);
        self::assertNotSame(Outcome::MATCH, $verdict->outcome, 'an explicit PLS is never a match');
    }

    /**
     * `description` and `title` are PROSE, wherever the adapter also leaves a copy of them.
     *
     * `ListingMapper` passes the WHOLE structured surface as `fields`, so an HTML source's mapped
     * `description` arrives BOTH as the property and as `fields['description']`. The property goes
     * through `RawListing::text()` and its prose rules; the field copy went through the identifier
     * discipline, which reads the French adverb `plus` as the acronym `PLUS`.
     *
     * Measured on live In'li data (2026-08-23), where Phase 2's detail map first gave that source a
     * description: 4 of 40 hydrated listings flipped to UNKNOWN on `de plus de 20 m²` and `encore
     * plus d'espace`. In'li carries no explicit label, so a tier-1 doubt is the ONLY tier-1 signal
     * there is and it dominates — a correct LLI match silently demoted to the *à vérifier* digest.
     * Same failure class as the CDC `au plus près` fix, one surface over: `plus` is one of the
     * commonest words in French.
     *
     * This is a RE-ROUTE, not a narrowing — see the PLS half, which must keep failing closed.
     */
    public function testProseFieldsAreReadAsProseEvenWhenTheAdapterAlsoCopiesThemIntoFields(): void
    {
        $prose = 'un espace de vie principal de plus de 20 m² (cuisine ouverte et séjour)';

        $listing = new RawListing(
            sourceName: 'inli',
            externalId: 'PRV-1',
            title: 'Appartement de 41 m² à SCEAUX',
            description: $prose,
            // Exactly what ListingMapper produces for a `type: html` source with a detail map.
            fields: ['title' => 'Appartement de 41 m² à SCEAUX', 'description' => $prose],
            url: 'https://www.inli.fr/x',
        );

        $verdict = (new TenureClassifier())->classify(
            $listing,
            new SourceProfile(name: 'inli', defaultTenure: Tenure::LLI, mixedTenure: false),
        );

        self::assertSame(Tenure::LLI, $verdict->tenure, 'the adverb "plus" is not the acronym PLUS');
    }

    /**
     * The counterweight to the re-route above: a REAL exclusion in the description still fails closed.
     *
     * Not hypothetical. In'li is documented as pure LLI, and hydrating its detail pages turned up
     * `Le logement est soumis au plafond de ressources PLS.` on two live listings whose CARDS said
     * nothing of the kind. Under §1 that is a reject, and it must stay one — if the fix above ever
     * becomes "stop scanning the description", this test goes red.
     */
    public function testARealExclusionInTheDescriptionStillFailsClosed(): void
    {
        $listing = new RawListing(
            sourceName: 'inli',
            externalId: 'PRV-317130',
            title: 'Appartement de 62 m²',
            description: 'Le logement est soumis au plafond de ressources PLS.',
            fields: ['description' => 'Le logement est soumis au plafond de ressources PLS.'],
            url: 'https://www.inli.fr/y',
        );

        $verdict = (new TenureClassifier())->classify(
            $listing,
            new SourceProfile(name: 'inli', defaultTenure: Tenure::LLI, mixedTenure: false),
        );

        self::assertNotSame(Tenure::LLI, $verdict->tenure, 'an explicit PLS is never an eligible match');
    }

    /**
     * The counterweight, and the reason the fix above is a re-route rather than a skip.
     *
     * If `_text` simply stopped being scanned, excluded vocabulary living in card regions that no
     * field map names would stop being seen at all — the "correct rule applied to a subset of the
     * surfaces it belongs on" P0 that eight review rounds each found one of. `_text` must still be
     * read; it must be read as PROSE.
     *
     * @param string $text
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('excludedProseInText')]
    public function testExcludedVocabularyInTheCardTextIsStillCaught(string $text, string $seen, string $why): void
    {
        $listing = new RawListing(
            sourceName: 'cdc_habitat',
            externalId: '2',
            title: '4 pièces - 2ème étage - 78m²',
            description: 'Ascenseur',
            fields: ['_text' => $text],
        );

        $verdict = (new TenureClassifier())->classify(
            $listing,
            new SourceProfile(name: 'cdc_habitat', defaultTenure: null, mixedTenure: true),
        );

        self::assertNotSame(Outcome::MATCH, $verdict->outcome, $why);
        self::assertNotSame(Tenure::LLI, $verdict->tenure, $why);

        // "Not a match" is NOT the guarantee — a listing whose card text was never scanned at all
        // also fails to match, by falling through to the sub-floor tier-5 default. A sabotage run
        // proved exactly that: deleting `_text` from `RawListing::text()` left this test green
        // [measured 2026-08-20]. What must be pinned is that the vocabulary was SEEN, so the
        // assertion is on the reason the classifier gives.
        self::assertStringContainsStringIgnoringCase(
            $seen,
            implode(' | ', $verdict->reasons()),
            'the card text was not scanned at all — silence here is indistinguishable from safety',
        );
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function excludedProseInText(): iterable
    {
        yield 'logement social' => [
            '4 pièces - 78m² Logement social CERGY (95000) 612,40 €',
            'logement social',
            'an explicit social badge in the card text must never reach a match',
        ];
        yield 'PLAI' => [
            '4 pièces - 78m² financement PLAI CERGY (95000)',
            'plai',
            'an excluded acronym in the card text must never reach a match',
        ];
        yield 'PLUS in financing context' => [
            '4 pièces - 78m² financement PLUS CERGY (95000)',
            'PLUS',
            'the real acronym, uppercase and in context, is still the real acronym',
        ];
        yield 'numero unique' => [
            "4 pièces - 78m² numéro unique d'enregistrement requis CERGY",
            'numero unique',
            'a procedural social tell in the card text must never reach a match',
        ];
    }

    // ------------------------------------------- the letterless skip, and the invariant it rests on

    /**
     * EVERY vocabulary literal contains at least one letter.
     *
     * This is not decoration — it is the precondition that makes the letterless fast path in
     * {@see TenureClassifier} sound. That path returns early for a value with no letter in it, on
     * the argument that no literal could match; the argument is only true while this holds, and it
     * holds for a reason nobody has to remember: the vocabulary is French housing terminology and
     * financing acronyms, and neither has a digits-only member.
     *
     * Read by reflection from the three tables themselves rather than from a copied list, so a
     * literal added tomorrow is covered without anyone thinking about it. If this ever goes red the
     * fix is to DELETE the fast path, not to relax the assertion: a letterless literal would make
     * the skip silently stop scanning a surface it is supposed to scan, which is the
     * breakage-as-absence shape hard rule 3 forbids.
     */
    public function testEveryVocabularyLiteralContainsALetter(): void
    {
        $reflected = new \ReflectionClass(TenureClassifier::class);
        $checked = 0;

        foreach (['LABELS', 'AMBIGUOUS_LABELS', 'PROCEDURAL'] as $table) {
            /** @var array<string,Tenure> $literals */
            $literals = $reflected->getConstant($table);

            self::assertNotSame([], $literals, $table . ' is empty — the reflection target moved');

            foreach (array_keys($literals) as $literal) {
                self::assertMatchesRegularExpression(
                    '/\p{L}/u',
                    (string) $literal,
                    sprintf('%s literal "%s" has no letter — the letterless fast path is UNSOUND', $table, $literal),
                );
                ++$checked;
            }
        }

        // The tables are read, not assumed: a typo in a table name above would silently check zero.
        self::assertGreaterThan(20, $checked, 'far fewer literals than expected — did a table move?');
    }

    /**
     * THE SKIP HAPPENS AFTER DECODING, AND THAT IS THE WHOLE TRAP.
     *
     * `&#80;&#76;&#65;&#73;` contains no letter at all — it is ampersands, hashes, digits and
     * semicolons — and it decodes to `PLAI`. So a letterless test applied to the RAW string would
     * skip the one value §1 exists to catch, and it would do it silently, on a source that had
     * simply numeric-entity-encoded its own payload.
     *
     * Placing the check after `Text::foldTolerant()` is what makes it safe, and this test is the
     * only thing standing between the two placements: both look identical in review, and every
     * other test in this file feeds plain text, which cannot tell them apart.
     */
    public function testALetterlessValueThatDecodesToAnExcludedLabelIsStillCaught(): void
    {
        $classifier = new TenureClassifier();

        $encoded = '&#80;&#76;&#65;&#73;';
        self::assertDoesNotMatchRegularExpression('/\p{L}/u', $encoded, 'the fixture stopped being letterless');

        $result = $classifier->classify(
            $this->listing(fields: ['gamme' => $encoded]),
            $this->source(),
        );

        // ASSERTED ON THE REASON, not on the outcome. The source is mixed with no default, so an
        // unlabelled listing digests anyway — `assertNotSame(MATCH)` passes whether or not the PLAI
        // was ever seen, and it did pass against a deliberately mis-placed guard. What distinguishes
        // "caught" from "found nothing and fell back to fail-closed" is the listing being able to
        // SAY what it saw, which is also what the digest entry has to carry for a human to settle.
        $said = implode(' | ', $result->reasons());

        self::assertStringContainsString('plai', $said, 'the encoded PLAI was never seen: ' . $said);
        self::assertStringContainsString('gamme', $said, 'the doubt does not name the field it came from');
        self::assertNotSame(Outcome::MATCH, $result->outcome, 'an encoded PLAI reached a match');
        self::assertNotSame(Tenure::LLI, $result->tenure);
    }

    /**
     * A genuinely letterless value contributes nothing — which is what lets it be skipped.
     *
     * Stated as an EQUIVALENCE against the same listing without the field, rather than as a timing
     * assertion. A milliseconds threshold flakes on CI and on the sabotage ledger, where the suite
     * runs some three hundred times; the property that actually matters is that adding rents, room
     * counts and dates to `fields` cannot move a verdict, and that survives any machine.
     */
    public function testALetterlessValueCannotMoveAVerdict(): void
    {
        $classifier = new TenureClassifier();

        $bare = $classifier->classify($this->listing(title: 'T3 Cergy'), $this->source());
        $noisy = $classifier->classify(
            $this->listing(title: 'T3 Cergy', fields: [
                'loyer' => '1005',
                'surface' => '55.32',
                'publie' => '2026-08-23',
                'reference' => '42-77-1900',
            ]),
            $this->source(),
        );

        self::assertSame($bare->tenure, $noisy->tenure);
        self::assertSame($bare->confidenceBp, $noisy->confidenceBp);
        self::assertSame($bare->outcome, $noisy->outcome);
    }

}
