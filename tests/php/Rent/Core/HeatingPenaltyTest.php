<?php

declare(strict_types=1);

namespace Scout\Tests\Rent\Core;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Rent\Config\ConfigLoader;
use Scout\Rent\Config\Weights;
use Scout\Rent\Core\CriteriaEngine;
use Scout\Rent\Core\Outcome;
use Scout\Rent\Core\RawListing;
use Scout\Rent\Core\SourceProfile;
use Scout\Rent\Core\Tenure;
use Scout\Rent\Core\TenureClassifier;
use Scout\Rent\Core\Verdict;

/**
 * Track 7-A: individual heating is penalised severely, electric worst, gas less.
 *
 * **A PENALTY, NEVER A DISQUALIFIER** (hard rule 8). The developer said *penalise*, and the whole
 * effect is that such a flat drops off the individual push and into the daily digest — it is still
 * a MATCH, still announced, still linked. A flat that silently vanished would be indistinguishable
 * from one nobody fetched.
 *
 * **THE SEVERITY IS A NUMBER AND IT WAS MEASURED.** At production's `positiveTotal()` of 105, −20 is
 * 19 points on the 0–100 scale, and re-judging every stored MATCH through this engine shows it
 * takes ALL 40 individually-heated matched flats below `push_min_score: 55` — 105 individual pushes
 * become 85. −30 and −40 were measured and buy nothing at that gate; they only reorder rows already
 * under it.
 */
#[CoversClass(CriteriaEngine::class)]
#[CoversClass(Weights::class)]
final class HeatingPenaltyTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../../..';

    /** The three configured classes, and the ordering between them IS the ruling. */
    public function testElectricIsPenalisedHardestThenGasThenAnUnstatedEnergy(): void
    {
        $none = $this->score('Beau T4 lumineux.');
        $unstated = $this->score('Beau T4 lumineux. Chauffage individuel.');
        $gas = $this->score('Beau T4 lumineux. Chauffage individuel gaz.');
        $electric = $this->score('Beau T4 lumineux. Chauffage individuel électrique.');

        self::assertLessThan($none, $unstated, 'stating the mode alone is already the base penalty');
        self::assertSame($unstated, $gas, 'gas takes the base penalty and no surcharge — “gas not as much”');
        self::assertLessThan($gas, $electric, 'and electric takes the surcharge on top');
    }

    /**
     * COLLECTIVE HEATING IS NOT PENALISED AT ALL, and this is the assertion that stops the penalty
     * quietly becoming "we found the word chauffage".
     */
    public function testCollectiveHeatingCostsNothing(): void
    {
        self::assertSame(
            $this->score('Beau T4 lumineux.'),
            $this->score('Beau T4 lumineux. Chauffage collectif au gaz.'),
        );
    }

    /**
     * SILENCE COSTS NOTHING (hard rule 9), and this is the STATED COST of the whole feature.
     *
     * `chauffage` reaches the stored text of only three of the eight sources; the other five carry
     * no listing prose at all. So the penalty ranks In'li flats below portal flats for a fact the
     * portals never state — which is unknown-is-not-no behaving correctly, and is why the
     * alternative (reading an unstated mode as individual) was refused.
     */
    public function testAFlatThatSaysNothingAboutHeatingIsUntouched(): void
    {
        self::assertSame(
            $this->score('Beau T4 lumineux, proche des commerces.'),
            $this->score(''),
        );
    }

    /**
     * IT IS A SCORE, NEVER A DISQUALIFIER — the half a penalty test always risks losing.
     *
     * The developer asked to see fewer of these, not none of them.
     */
    public function testAnIndividuallyHeatedFlatIsStillAMatchAndSaysWhy(): void
    {
        $v = $this->judge('Beau T4 lumineux. Chauffage individuel électrique.');

        self::assertSame(Outcome::MATCH, $v->outcome, 'hard rule 8: penalised on one component, never rejected');
        self::assertGreaterThan(0, $v->score);
        self::assertContains('chauffage individuel electrique — énergie à votre charge', $v->reasons);
    }

    /**
     * THE PENALTIES ARE OUT OF THE NORMALISING TOTAL, exactly as `highFloorNoLift` is.
     *
     * Including a penalty in the denominator makes it SMALLER the larger it is set — the opposite
     * of the intent — and it is the kind of arithmetic nobody re-derives once it ships. Asserted
     * against `positiveTotal()` directly rather than through a score, because a score would only
     * show the symptom.
     */
    public function testAPenaltyIsNotPartOfWhatAListingCanEarn(): void
    {
        $base = new Weights(commune: 25, commute: 0, rentHeadroom: 15, surface: 10, lift: 15, highFloorNoLift: 0, freshness: 10);
        $withPenalties = new Weights(
            commune: 25, commute: 0, rentHeadroom: 15, surface: 10, lift: 15,
            highFloorNoLift: -20, freshness: 10,
            heatingIndividual: -20, heatingIndividualElectric: -15,
        );

        self::assertSame(75, $base->positiveTotal());
        self::assertSame(75, $withPenalties->positiveTotal(), 'three penalties, none of them earnable');
    }

    /** The shipped file carries the ruled numbers, and they are negative. */
    public function testTheShippedWeightsCarryTheRuledPenalties(): void
    {
        $criteria = ConfigLoader::loadCriteria(self::ROOT . '/config/rent/criteria.json');

        self::assertSame(-20, $criteria->weights->heatingIndividual);
        self::assertSame(-15, $criteria->weights->heatingIndividualElectric);
    }

    private function score(string $description): int
    {
        $v = $this->judge($description);
        self::assertNotNull($v->score);

        return $v->score;
    }

    private function judge(string $description): Verdict
    {
        $listing = new RawListing(
            sourceName: 'inli',
            externalId: 'x-1',
            title: 'Appartement 4 pièces',
            description: $description,
            commune: 'Sartrouville',
            postcode: '78500',
            rentCc: 1000,
            surfaceM2: 88.0,
            rooms: 4,
        );

        $criteria = ConfigLoader::loadCriteria(self::ROOT . '/config/rent/criteria.json');
        $classification = (new TenureClassifier())->classify(
            $listing,
            new SourceProfile(name: 'inli', family: 'institutional', defaultTenure: Tenure::LLI, mixedTenure: false),
        );

        return (new CriteriaEngine($criteria))->judge($listing, $classification);
    }
}
