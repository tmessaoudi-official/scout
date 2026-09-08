<?php

declare(strict_types=1);

namespace Scout\Tests\Car;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Car\VehicleClassifier;
use Scout\Car\VehicleCriteria;
use Scout\Car\VehicleCriteriaLoader;
use Scout\Car\VehicleListing;
use Scout\Car\VehicleOutcome;
use Scout\Car\VehicleScorer;

/**
 * TEXT THAT WILL NOT FOLD, ACROSS THE CAR DOMAIN'S FIVE CATCH ARMS.
 *
 * `Core\Text::fold()` refuses text it cannot normalise, and several places in `src/php/Car/` catch
 * that (`grep -c 'catch (MalformedText' src/php/Car/*.php` is the tally — a count written in prose
 * here went stale twice in this repo already). **Not one of them had a test**, so nothing in the
 * tree said which way each one fails — and they do not all fail the same way:
 *
 * - `VehicleClassifier::classify()` fails CLOSED. Unfoldable text is `REJECT`, with
 *   *"texte illisible"* as the reason. That is §1's twin for the excluded-vehicle set, and it is
 *   the arm that matters.
 * - `VehicleCriteria::excludedBy()`, `isFavouredBody()` and the shared brand stem matcher behind
 *   `isAvoidedBrand()` / `isFavouredBrand()` fail OPEN: they answer "no", and for `excludedBy()`
 *   that reads as *no user exclusion fired*. The brand matcher gained its catch in Track 7 — it
 *   folded unguarded before, so an unfoldable make threw out of the scorer rather than scoring 0;
 *   both arms are unreachable behind the same order below, and the catch makes the two brand
 *   methods agree with every other predicate on this class instead of being the one that throws.
 *
 * **The fail-open is unreachable today, and only because of an order nobody had written down.**
 * `VehicleScorer::judge()` returns on the classification's `REJECT` before it ever calls
 * `excludedBy()`, so a malformed listing never reaches the permissive arm. The dependency is real
 * and one edit from being false: weaken the classifier's catch and every user exclusion silently
 * stops firing on exactly those listings. So the ORDER is what is asserted here, not just each arm
 * in isolation — an arm-by-arm test would stay green through precisely the change that matters.
 */
#[CoversClass(VehicleClassifier::class)]
#[CoversClass(VehicleScorer::class)]
final class VehicleMalformedTextTest extends TestCase
{
    /** A lone continuation byte: valid nowhere, and what a mis-decoded payload actually looks like. */
    private const string UNFOLDABLE = "Renault Clio \xFF\xFE ess";

    public function testUnfoldableTextIsRejectedByTheClassifier(): void
    {
        $class = (new VehicleClassifier())->classify($this->car(self::UNFOLDABLE));

        self::assertSame(VehicleOutcome::REJECT, $class->outcome, 'fail closed: text nobody can read is not a match');
        self::assertStringContainsString('illisible', implode(' ', $class->reasons), 'and it says why, rather than rejecting mutely');
    }

    /**
     * THE COUNTERWEIGHT. Without it the assertion above is satisfied by rejecting everything, which
     * is a watcher that finds nothing — indistinguishable from a quiet market, hard rule 2's shape.
     */
    public function testOrdinaryTextIsNotRejected(): void
    {
        $class = (new VehicleClassifier())->classify($this->car('Renault Clio V 1.0 TCe essence, première main'));

        self::assertSame(VehicleOutcome::MATCH, $class->outcome);
    }

    /**
     * THE ORDER, which is the finding. `VehicleCriteria::excludedBy()` returns `null` on unfoldable
     * text — no exclusion fires — so the only thing standing between such a listing and a score is
     * the classifier's REJECT being consulted FIRST.
     *
     * Asserted through the real `judge()` rather than by reading the two methods: this is a claim
     * about sequence, and a claim about sequence that is not executed is a claim about the reader's
     * memory of the code.
     */
    public function testTheScorerRejectsOnTheClassifierBeforeConsultingTheExclusions(): void
    {
        $car = $this->car(self::UNFOLDABLE);
        $criteria = $this->criteria();

        // The permissive arm, shown rather than asserted about: on this text the user exclusions
        // find nothing at all, whatever they contain.
        self::assertNull($criteria->excludedBy($car->text()), 'the exclusion path is blind to unfoldable text');

        $verdict = (new VehicleScorer())->judge($car, (new VehicleClassifier())->classify($car), $criteria, 2026, 9);

        self::assertNull($verdict->score, 'a rejected listing has no score at all — not a score of zero');
        self::assertStringContainsString(
            'illisible',
            implode(' ', $verdict->reasons),
            'and the rejection is the CLASSIFIER\'s, which is the only thing that fires on this input',
        );
    }

    private function car(string $title): VehicleListing
    {
        return new VehicleListing(
            sourceName: 'paruvendu',
            externalId: 'v1',
            title: $title,
            priceEur: 9000,
            year: 2020,
            mileageKm: 60000,
            postcode: '78500',
        );
    }

    /**
     * The shipped-shaped criteria, carrying an exclusion that WOULD match this listing's title if
     * the text could be folded. That is what makes the order test mean something: the exclusion is
     * present and applicable, and the only reason it does not decide the verdict is that the
     * classifier rejected first.
     */
    private function criteria(): VehicleCriteria
    {
        return VehicleCriteriaLoader::fromArray(VehicleCriteriaTest::minimal([
            'max_price_eur' => 15000,
            'postcode_prefixes' => ['78'],
            'exclude_patterns' => ['\bclio\b'],
        ]));
    }
}
