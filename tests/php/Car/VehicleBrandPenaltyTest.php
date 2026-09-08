<?php

declare(strict_types=1);

namespace Scout\Tests\Car;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Scout\Car\VehicleClassifier;
use Scout\Car\VehicleCriteriaLoader;
use Scout\Car\VehicleListing;
use Scout\Car\VehicleScorer;
use Scout\Car\VehicleVerdict;

/**
 * TRACK 1d — the brand penalty, and the INVERSION is the ruling rather than a detail.
 *
 * The developer ruled (2026-08-31) that disfavoured makes score LOWER, with the weight taken out
 * of the existing 100. Mirroring `body_rank` — the mechanism already in this scorer — would have
 * done the exact opposite: that one gives its TOP entry the full share, so a `brand_rank` naming
 * the three makes to avoid would have ranked them first. Hence a LIST of makes to avoid, no
 * ordering among them, and the share EARNED by being absent from it.
 *
 * Everything here is asserted as a strict INEQUALITY at otherwise-identical specs rather than as a
 * fixed number: an expectation pinned to 10 points goes stale the day the weight is re-allocated,
 * and the guarantee is the ordering, not the arithmetic.
 */
#[CoversClass(VehicleScorer::class)]
final class VehicleBrandPenaltyTest extends TestCase
{
    /** @return iterable<string, array{0: string}> */
    public static function avoidedMakes(): iterable
    {
        yield 'peugeot' => ['Peugeot'];
        yield 'renault' => ['Renault'];
        yield 'opel' => ['Opel'];
        // FOLDED at load and at comparison, so the payload's casing is irrelevant — a real
        // `make_model_pattern` capture arrives however the portal typed it.
        yield 'shouting' => ['PEUGEOT'];
        yield 'padded' => ['  Renault '];
    }

    /**
     * THREE CLASSES SINCE TRACK 7, NOT TWO — and asserting only two is how the third goes unbuilt.
     *
     * This asserted `avoided < unlisted` with Toyota standing in for *unlisted*. Toyota is now
     * FAVOURED, and the class it used to represent — a make on neither list — scores what an
     * avoided one does, which is the developer's ruling read literally: *everything else must have
     * lowest score and almost not show up* (2026-09-08). 47 makes in the live store are in that
     * third class and split C, which gave them half the share, pushed 16 of them.
     *
     * So the guarantee is stated as the full partition: favoured strictly above, avoided and
     * unlisted equal and strictly below. Two of those three assertions would have passed against
     * the OLD two-way implementation, and the third is the one that pins the ruling.
     */
    #[DataProvider('avoidedMakes')]
    public function testTheBrandShareIsAThreeWayAndOnlyAFavouredMakeEarnsIt(string $make): void
    {
        $criteria = VehicleCriteriaLoader::fromArray(VehicleCriteriaTest::minimal([
            'brand_avoid' => ['peugeot', 'renault', 'opel'],
            'brand_favour' => ['toyota'],
        ]));

        $avoided = $this->judgeWith($make, $criteria);
        $favoured = $this->judgeWith('Toyota', $criteria);
        $neither = $this->judgeWith('Suzuki', $criteria);

        self::assertLessThan($favoured->score, $avoided->score, 'the preference is the whole mechanism');
        self::assertSame($avoided->score, $neither->score, 'and an unlisted make earns exactly what an avoided one does');
        self::assertContains(trim($make) . ' — marque à éviter', $avoided->reasons);
        self::assertContains('Toyota — marque recherchée', $favoured->reasons);
        self::assertContains('Suzuki — marque hors des listes', $neither->reasons, 'named as unlisted, never as a judgement nobody made');
    }

    /**
     * IT IS A SCORE, NEVER A DISQUALIFIER (hard rule 8), and this is the half that matters most:
     * the developer asked to see fewer of these makes, not none of them.
     */
    #[DataProvider('avoidedMakes')]
    public function testAnAvoidedMakeIsStillNotified(string $make): void
    {
        self::assertSame(\Scout\Car\VehicleOutcome::MATCH, $this->judge($make)->outcome);
        self::assertGreaterThan(0, $this->judge($make)->score, 'penalised on one component, not zeroed overall');
    }

    /**
     * AN UNEXTRACTED MAKE SCORES 0 AND SAYS SO — the arm every other component here takes, and a
     * deliberate deviation from the plan's line that it should take the full share.
     *
     * Hard rule 9 forbids reading unknown as BELOW A MINIMUM, which is a disqualifier; nothing here
     * disqualifies. Awarding the share would rank an extraction failure as a definitely-not-Peugeot
     * — a fact manufactured from its own absence, which is this repo's recurring defect.
     *
     * (*"Both shipped car sources extract a make"* stood here and stopped being true at Track 6-A4:
     * ParuVendu writes `/autres/autres/` when it cannot name the marque, and that token is nulled
     * at the adapter precisely so such a card reaches THIS arm instead of earning the share as a
     * definitely-not-DS. Six sources ship now. The guarantee is unchanged; the reason given for it
     * was a claim about the sources and it had rotted.)
     *
     * The second assertion is worth reading twice since Track 7: an unknown make and an AVOIDED one
     * score the same, and so does an unlisted one — three ways to earn nothing. What separates them
     * is the REASON LINE, which names which fact is missing, and that is what the first assertion
     * pins.
     */
    public function testAnUnextractedMakeIsUnscoredAndSaysSo(): void
    {
        $unknown = $this->judge(null);

        self::assertContains('marque inconnue — hors score', $unknown->reasons);
        self::assertLessThan($this->judge('Toyota')->score, $unknown->score);
        self::assertSame(
            $this->judge('Peugeot')->score,
            $unknown->score,
            'no fact is invented in either direction: an unknown make earns the share no more than an avoided one does',
        );
    }

    /**
     * THE COUNTERWEIGHT, and without it the whole feature is satisfied by deleting it.
     *
     * With no `brand_avoid` configured no make is disfavoured, so EVERY make earns the share — and
     * the achievable maximum stays 100. Withholding it there would shrink the scale to 90 for such
     * a deployment, and `high_priority_score` is an ABSOLUTE threshold, so the marker would quietly
     * become harder to reach. Same failure the rent side measured on 2026-08-26, where `!!` was
     * unreachable by construction.
     */
    public function testWithNoListConfiguredEveryMakeEarnsTheShare(): void
    {
        // BOTH lists cleared: the arm requires it since Track 7, and clearing only one is the very
        // configuration {@see testAFavourOnlyConfigurationIsNotReadAsNoPreference} exists to pin.
        $criteria = VehicleCriteriaLoader::fromArray(VehicleCriteriaTest::minimal(['brand_avoid' => [], 'brand_favour' => []]));

        $peugeot = $this->judgeWith('Peugeot', $criteria);
        $toyota = $this->judgeWith('Toyota', $criteria);

        self::assertSame($toyota->score, $peugeot->score, 'nothing is configured, so nothing is disfavoured');
        self::assertContains('marque — aucune préférence configurée', $peugeot->reasons);
        self::assertGreaterThan(
            $this->judge('Peugeot')->score,
            $peugeot->score,
            'the share is genuinely awarded here, not merely unmentioned',
        );
    }

    /**
     * THE NO-PREFERENCE ARM NEEDS **BOTH** LISTS EMPTY, and keying it on one was a live hole.
     *
     * Until Track 7 the condition was `brandAvoid === []`, which was complete while `brand_avoid`
     * was the only list. With a favoured list it is not: a deployment configuring ONLY
     * `brand_favour` — the natural way to express *these are the makes I want* — would have taken
     * this arm and awarded the whole share to every make, silently disabling the preference it had
     * just written down. Nothing would read as a fault; every score stays plausible and the
     * favoured cars simply stop standing out. That is the same silent shape as the `ds` /
     * `ds automobiles` miss, one level up.
     */
    public function testAFavourOnlyConfigurationIsNotReadAsNoPreference(): void
    {
        $criteria = VehicleCriteriaLoader::fromArray(VehicleCriteriaTest::minimal([
            'brand_avoid' => [],
            'brand_favour' => ['toyota'],
        ]));

        $toyota = $this->judgeWith('Toyota', $criteria);
        $suzuki = $this->judgeWith('Suzuki', $criteria);

        self::assertGreaterThan($suzuki->score, $toyota->score, 'the favoured list alone still discriminates');
        self::assertContains('Toyota — marque recherchée', $toyota->reasons);
        self::assertNotContains('marque — aucune préférence configurée', $suzuki->reasons, 'a configured preference is never reported as none');
    }

    /**
     * A make on BOTH lists is refused at load rather than resolved quietly.
     *
     * The scorer checks avoid before favour, so a clash would resolve to *avoided* and the favoured
     * entry would sit in the config doing nothing — a configured preference inert while every score
     * stays plausible, which is the defect class the stem matcher was repaired for.
     */
    public function testAMakeOnBothListsIsRefused(): void
    {
        $this->expectException(\Scout\Config\ConfigError::class);
        $this->expectExceptionMessageMatches('~peugeot~');
        VehicleCriteriaLoader::fromArray(VehicleCriteriaTest::minimal([
            'brand_avoid' => ['peugeot'],
            'brand_favour' => ['peugeot'],
        ]));
    }

    /**
     * THE FAVOURED SIDE USES THE SAME NON-LETTER-BOUNDARY MATCHER, and this is the measured reason.
     *
     * `mercedes-benz` and `mercedes` are the same marque under two spellings in the live store —
     * the `ds` / `ds automobiles` shape facing the other way. An exact-equality favoured list would
     * have caught one and silently missed the other: the car merely ranks 25 points too low, no
     * error, no miss counted, and the developer's own named make quietly failing to show up.
     */
    public function testAFavouredStemCatchesEverySuffixedSpelling(): void
    {
        $criteria = VehicleCriteriaLoader::load(__DIR__ . '/../../../config/car/criteria.json');

        foreach (['Mercedes', 'Mercedes-Benz', 'MERCEDES BENZ', 'Volkswagen'] as $make) {
            self::assertTrue($criteria->isFavouredBrand($make), $make . ' is a make the developer named');
        }
        self::assertFalse($criteria->isFavouredBrand('Fordson'), 'a longer word that merely starts the same way is not the marque');
        self::assertFalse($criteria->isFavouredBrand(null), 'hard rule 9: unknown is not favoured either');
    }

    /** The weights still sum to 100 — the ruled part of the mechanism, and the one that is checkable. */
    public function testTheShippedWeightsStillSumToAHundredAndReserveABrandShare(): void
    {
        $criteria = VehicleCriteriaLoader::load(__DIR__ . '/../../../config/car/criteria.json');

        self::assertSame(100, array_sum($criteria->weights));
        self::assertGreaterThan(0, $criteria->weights['brand'], 'a zero share is the feature switched off');

        // The 22 ruled 2026-09-01, MINUS ford, which the developer moved to `brand_favour` on
        // 2026-09-08 after it was measured both ways (median 80 / 17 of 28 over the gate as
        // favoured; median 55 / 0 of 28 as avoided). Asserted as a SET rather than spot-checked,
        // because the failure this list has is a make quietly going missing from it — which no
        // sample catches. `chevrolet` deliberately stays and the choice is moot: 0 rows in the store.
        self::assertSame([
            'peugeot', 'citroen', 'ds', 'opel', 'vauxhall', 'fiat', 'abarth', 'lancia',
            'alfa', 'jeep', 'dodge', 'chrysler', 'ram', 'maserati',
            'chevrolet',
            'renault', 'dacia', 'nissan', 'alpine', 'mitsubishi', 'leapmotor',
        ], $criteria->brandAvoid);

        // The 16 ruled 2026-09-08 — fifteen named by the developer plus ford, moved across.
        self::assertSame([
            'toyota', 'hyundai', 'kia', 'honda', 'tesla', 'byd',
            'ford', 'bmw', 'mercedes', 'volkswagen', 'seat', 'cupra',
            'audi', 'mazda', 'skoda', 'volvo',
        ], $criteria->brandFavour);
        self::assertSame([], array_intersect($criteria->brandAvoid, $criteria->brandFavour), 'and no make is on both');
    }

    /**
     * EVERY MAKE THE LIVE STORE ACTUALLY CONTAINS, AND THE CLASS THE SHIPPED CONFIG GIVES IT.
     *
     * The counterweight to the two set assertions above, and the one that catches a stem reaching
     * too far — which is as silent as one reaching too short and worse, because it ranks a car
     * BELOW one that deserves less. Enumerated from `state/car-watch.sqlite3` on 2026-09-08 (39
     * distinct spellings across 951 judged MATCHes) and asserted as THREE classes since Track 7,
     * not two: an assertion that only knew *avoided* and *not avoided* would have passed unchanged
     * through the ruling that created the third.
     *
     * @param 'avoided'|'favoured'|'neither' $class
     */
    #[DataProvider('everyMakeInTheLiveStore')]
    public function testEveryStoredMakeLandsInTheClassTheConfigSays(string $make, string $class): void
    {
        $criteria = VehicleCriteriaLoader::load(__DIR__ . '/../../../config/car/criteria.json');

        $actual = $criteria->isAvoidedBrand($make) ? 'avoided'
            : ($criteria->isFavouredBrand($make) ? 'favoured' : 'neither');

        self::assertSame($class, $actual, $make);
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function everyMakeInTheLiveStore(): iterable
    {
        $makes = [
            'abarth' => 'avoided', 'alfa romeo' => 'avoided', 'alfa-romeo' => 'avoided',
            'citroen' => 'avoided', 'dacia' => 'avoided', 'ds' => 'avoided',
            'ds automobiles' => 'avoided', 'fiat' => 'avoided', 'jeep' => 'avoided',
            'leapmotor' => 'avoided', 'mitsubishi' => 'avoided', 'nissan' => 'avoided',
            'opel' => 'avoided', 'peugeot' => 'avoided', 'renault' => 'avoided',
            'audi' => 'favoured', 'bmw' => 'favoured', 'cupra' => 'favoured',
            'ford' => 'favoured', 'honda' => 'favoured', 'hyundai' => 'favoured',
            'kia' => 'favoured', 'mazda' => 'favoured', 'mercedes' => 'favoured',
            'mercedes-benz' => 'favoured', 'seat' => 'favoured', 'skoda' => 'favoured',
            'tesla' => 'favoured', 'toyota' => 'favoured', 'volkswagen' => 'favoured',
            'volvo' => 'favoured',
            // The third class, and `c4` is in it by DEFECT rather than by choice: it is a Citroën
            // MODEL stored as a make (Track 7's second incidental finding). Self-cancelling today —
            // it scores 0 as unlisted and Citroën is avoided — and it stops being so the day the
            // extraction is fixed, which is why it is written down here rather than tidied away.
            'c4' => 'neither', 'isuzu' => 'neither', 'land rover' => 'neither',
            'lexus' => 'neither', 'mg' => 'neither', 'mini' => 'neither',
            'smart' => 'neither', 'suzuki' => 'neither',
        ];

        foreach ($makes as $make => $class) {
            yield $make => [$make, $class];
        }
    }

    /**
     * DIESEL FORFEITS THE WHOLE FUEL SHARE, AND STILL APPEARS (developer ruling, 2026-09-01).
     *
     * The developer asked whether only petrol and hybrid were meant to show, having seen diesels in
     * the pushes. They were not: fuel was ruled a PREFERENCE on 2026-08-30 and recorded as settled.
     * What changed on 2026-09-01 is the DEPTH — the fuel weight went 10→20, taken 5 from price and
     * 5 from mileage — after measuring that 5 diesels were reaching the petrol median and the best
     * scored 77.
     *
     * Asserted as the SHARE rather than as a number, so a future reallocation cannot make this
     * silently vacuous: a diesel gives up exactly `weights['fuel']`, no more and no less. And the
     * second half is the ruling's other side, which a penalty test always risks losing — hard rule
     * 8 keeps disqualifiers and score apart, so the diesel is still a MATCH. It ranks lower; it
     * does not vanish.
     */
    public function testADieselForfeitsTheWholeFuelShareAndIsStillNotified(): void
    {
        $criteria = VehicleCriteriaLoader::load(__DIR__ . '/../../../config/car/criteria.json');

        $petrol = $this->judgeFuelWith('essence', $criteria);
        $diesel = $this->judgeFuelWith('diesel', $criteria);

        self::assertSame(
            $criteria->weights['fuel'],
            $petrol->score - $diesel->score,
            'a diesel forfeits exactly the fuel share at otherwise-identical specs',
        );
        self::assertSame(\Scout\Car\VehicleOutcome::MATCH, $diesel->outcome, 'a preference, never a disqualifier');
        self::assertGreaterThan(0, $diesel->score);
    }

    /**
     * GPL is the half-share case — the only fuel that is neither preferred nor forfeited.
     *
     * **THE EXACT-EQUALITY FORM OF THIS ASSERTION WAS FRAGILE AND TRACK 7 FOUND OUT.** It read
     * `assertSame((int) round($fuel / 2), $essence - $gpl)`, which is only stable while the fuel
     * weight is EVEN: a score is `(int) round(...)` of the whole total, so at fuel 15 the two runs
     * round `X + 15` and `X + 7.5`, and the difference is 7 or 8 depending on the fraction the
     * OTHER six components happen to carry. The weight went 20 → 15 on 2026-09-08 and the test
     * went red having found no defect at all — the shipped behaviour is unchanged and correct.
     *
     * So the guarantee is stated as what it actually is: gpl earns half the share to within one
     * point of rounding, and it sits STRICTLY between the two arms it is between. The strict
     * ordering is the half that cannot be satisfied by an accident — an implementation awarding
     * gpl the full share or none would still pass a `<= 1` tolerance on its own.
     */
    public function testGplTakesHalfTheFuelShare(): void
    {
        $criteria = VehicleCriteriaLoader::load(__DIR__ . '/../../../config/car/criteria.json');

        $petrol = $this->judgeFuelWith('essence', $criteria)->score;
        $gpl = $this->judgeFuelWith('gpl', $criteria)->score;
        $diesel = $this->judgeFuelWith('diesel', $criteria)->score;

        self::assertLessThanOrEqual(
            1,
            abs(($petrol - $gpl) - $criteria->weights['fuel'] / 2),
            'gpl forfeits half the fuel share, up to the one point an odd weight rounds by',
        );
        self::assertGreaterThan($diesel, $gpl, 'and it is strictly better than a diesel');
        self::assertGreaterThan($gpl, $petrol, 'and strictly worse than petrol');
    }

    /**
     * A MARQUE IS CAUGHT WHATEVER SUFFIX THE SOURCE SPELLS IT WITH — and this is a measured
     * defect, not a hypothetical.
     *
     * The live car store carries the SAME marque under two spellings, one per source: autohero
     * emits `ds automobiles` and leboncoin emits `ds`. `in_array($folded, $brandAvoid, true)` is
     * exact equality, so a config entry `ds` caught the leboncoin row and SILENTLY MISSED the
     * autohero one — a configured preference that is inert on one source, which is the failure
     * class this repo has already paid for four times (`exclude_title_patterns` on In'li, the two
     * unread car params, PAP's anchors). It costs 10 points of ordering and nothing reads as a
     * fault.
     *
     * So the entry is a STEM and the match runs to a non-letter boundary. The stem is the shortest
     * unambiguous form on purpose — `alfa`, not `alfa romeo` — because the gap runs BOTH ways: an
     * entry longer than the make misses just as silently the day a source emits the short form.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function suffixedSpellings(): iterable
    {
        yield 'ds automobiles (autohero, live)' => ['DS Automobiles'];
        yield 'fiat professional' => ['Fiat Professional'];
        yield 'alfa romeo' => ['Alfa Romeo'];
        yield 'ram trucks' => ['RAM Trucks'];
        // A hyphen is a boundary too — a portal writing the badge rather than the marque.
        yield 'hyphenated' => ['Citroen-DS'];
        // A digit is a boundary: `DS 3` and `DS3` are the same car typed two ways.
        yield 'digit boundary' => ['DS3 Crossback'];
    }

    #[DataProvider('suffixedSpellings')]
    public function testAMarqueIsCaughtWhateverSuffixTheSourceSpellsItWith(string $make): void
    {
        $criteria = VehicleCriteriaLoader::load(__DIR__ . '/../../../config/car/criteria.json');

        self::assertTrue(
            $criteria->isAvoidedBrand($make),
            "`{$make}` must be penalised — a stem that misses a real spelling is an inert filter",
        );
    }

    /**
     * THE COUNTERWEIGHT, and without it the guarantee above is satisfied by penalising everything.
     *
     * A stem match can over-reach as silently as an exact match under-reaches, and the direction is
     * worse: a make wrongly penalised is a car ranked below one that deserves less. Every spelling
     * below is one the LIVE store actually contains (26 distinct makes across the three sources,
     * 2026-09-01) — real inputs, not invented ones, which is the rule this repo's surface matrix
     * already carries.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function unlistedSpellings(): iterable
    {
        foreach ([
            'audi', 'autres', 'bmw', 'hyundai', 'kia', 'lexus', 'mazda',
            'mercedes', 'mercedes-benz', 'seat', 'skoda', 'smart', 'toyota', 'volkswagen',
        ] as $make) {
            yield $make => [$make];
        }
    }

    #[DataProvider('unlistedSpellings')]
    public function testAStemNeverReachesAMakeNobodyListed(string $make): void
    {
        $criteria = VehicleCriteriaLoader::load(__DIR__ . '/../../../config/car/criteria.json');

        self::assertFalse(
            $criteria->isAvoidedBrand($make),
            "`{$make}` is on nobody's list — a stem reaching it ranks a car below one that deserves less",
        );
    }

    /**
     * THE COUNTERWEIGHT ABOVE DOES NOT REACH THE BOUNDARY CHECK, which is why this provider exists
     * separately rather than as two more rows in it.
     *
     * Measured: deleting the boundary check left the whole suite GREEN. Not one of the 26 makes the
     * live store contains BEGINS with one of the 22 stems, so the guard is never entered and was
     * dead safety code the moment it was written — the trap this repo already documents ("a
     * guarantee whose branch no fixture reaches is dead safety code until something reaches it").
     * The sabotage case pins it, and it needs an input that enters the branch.
     *
     * Both below are REAL marques, not invented ones, and both are plausible on a classic listing
     * — the rule that a cell must be fed something a real feed could emit. `Rambler` (American
     * Motors) begins with the `ram` stem; `Fordson` (Ford's tractors) begins with `ford`. Neither
     * is the marque its stem names, and penalising either would rank a car below one that deserves
     * less, silently.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function wordsThatMerelyBeginLikeAStem(): iterable
    {
        yield 'rambler (begins with the `ram` stem)' => ['Rambler'];
        yield 'fordson (begins with the `ford` stem)' => ['Fordson'];
    }

    #[DataProvider('wordsThatMerelyBeginLikeAStem')]
    public function testAStemStopsAtALetterAndDoesNotSwallowALongerWord(string $make): void
    {
        $criteria = VehicleCriteriaLoader::load(__DIR__ . '/../../../config/car/criteria.json');

        self::assertFalse(
            $criteria->isAvoidedBrand($make),
            "`{$make}` merely BEGINS like a stem — the boundary check is the whole difference",
        );
    }

    /**
     * A BRAND THAT FOLDS TO NOTHING IS REFUSED, and the input matters more than the assertion.
     *
     * A first draft used `'  '` and passed — against `Reader::requireStringList`, which already
     * refuses a `trim()`-blank entry. Deleting the loader's own guard left that draft green: it was
     * a true observation attached to the wrong mechanism, which is this repo's named failure. The
     * shapes that actually REACH the guard are the ones `trim()` does not see — a non-breaking
     * space, a zero-width space — and folding is what collapses them. An entry that folds to
     * nothing would match no make at all while reading as a configured preference.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function emptyBrands(): iterable
    {
        yield 'non-breaking space' => ["\u{00A0}"];
        yield 'zero-width space' => ["\u{200B}"];
    }

    #[DataProvider('emptyBrands')]
    public function testABrandThatFoldsToNothingIsRefused(string $brand): void
    {
        $this->expectException(\Scout\Config\ConfigError::class);
        $this->expectExceptionMessageMatches('~brand_avoid~');
        VehicleCriteriaLoader::fromArray(VehicleCriteriaTest::minimal(['brand_avoid' => [$brand]]));
    }

    /** The plain-blank case, caught one layer up — asserted so the two guards cannot both be lost. */
    public function testAPlainBlankBrandIsRefusedByTheReader(): void
    {
        $this->expectException(\Scout\Config\ConfigError::class);
        $this->expectExceptionMessageMatches('~brand_avoid~');
        VehicleCriteriaLoader::fromArray(VehicleCriteriaTest::minimal(['brand_avoid' => ['  ']]));
    }

    private function judge(?string $make): VehicleVerdict
    {
        return $this->judgeWith($make, VehicleCriteriaLoader::fromArray(VehicleCriteriaTest::minimal()));
    }

    private function judgeWith(?string $make, \Scout\Car\VehicleCriteria $criteria): VehicleVerdict
    {
        // Identical specs on every other component, so the ONLY difference between two runs is the
        // make — an inequality that survived a change to any other weight would prove nothing.
        $car = new VehicleListing(
            sourceName: 'test',
            externalId: 'x',
            title: 'Voiture d\'occasion',
            priceEur: 15000,
            year: 2024,
            month: 1,
            mileageKm: 40000,
            gearbox: 'automatique',
            fuel: 'essence',
            body: 'suv',
            postcode: null,
            make: $make,
        );

        return (new VehicleScorer())->judge($car, (new VehicleClassifier())->classify($car), $criteria, 2026, 8);
    }

    /** The same car every time, varying only the FUEL — the twin of `judgeWith()` above. */
    private function judgeFuelWith(string $fuel, \Scout\Car\VehicleCriteria $criteria): VehicleVerdict
    {
        $car = new VehicleListing(
            sourceName: 'test',
            externalId: 'x',
            title: 'Voiture d\'occasion',
            priceEur: 15000,
            year: 2024,
            month: 1,
            mileageKm: 40000,
            gearbox: 'automatique',
            fuel: $fuel,
            body: 'suv',
            postcode: null,
            // Deliberately a make NOT on `brand_avoid`, so the brand share is constant across the
            // comparison and the difference measured is the fuel share alone.
            make: 'Toyota',
        );

        return (new VehicleScorer())->judge($car, (new VehicleClassifier())->classify($car), $criteria, 2026, 8);
    }
}
