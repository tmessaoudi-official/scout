<?php

declare(strict_types=1);

namespace Scout\Tests\Rent\Core;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Rent\Config\ConfigLoader;
use Scout\Rent\Core\CriteriaEngine;
use Scout\Rent\Core\Outcome;
use Scout\Rent\Core\RawListing;
use Scout\Rent\Core\SourceProfile;
use Scout\Rent\Core\Tenure;
use Scout\Rent\Core\TenureClassifier;
use Scout\Rent\Core\Verdict;

/**
 * AN HC-ONLY RENT THAT ALREADY EXCEEDS THE CEILING IS PROVABLY OVER IT (audit 2026-10-02, P1-1;
 * developer ruling 2026-10-02, refining Q32's edge).
 *
 * Q32 ruled that a rent stated hors charges with no charges figure is a CC rent that is UNKNOWN —
 * "a 1750 € HC flat is roughly 1900 € CC" — and must stay unknown: not disqualified, scored zero on
 * the rent component, and said out loud. That holds when the HC figure is at or under the ceiling,
 * because charges could push the real total either side of it.
 *
 * It does not hold above the ceiling. Charges are never negative, so CC >= HC always, and an HC
 * figure over the ceiling means the CC figure is over it too: that is not an unknown, it is a lower
 * bound that already fails the test. Before this, a leboncoin flat at 1900 € HC against a 1200 €
 * ceiling scored 60 and was pushed ("1900 € HORS CHARGES — total réel inconnu, plafond non
 * vérifiable") — a wasted application the engine had all the information to refuse.
 *
 * The measured cost before the change was nil (353 stored HC-only rows, 8 over the ceiling, none
 * ever notified as a match), so this is guard rather than repair.
 */
#[CoversClass(CriteriaEngine::class)]
final class HcOnlyRentCeilingTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../../..';

    private function judge(?int $rentCc, ?int $rentHc, ?int $charges = null): Verdict
    {
        $listing = new RawListing(
            sourceName: 'leboncoin',
            externalId: 'HC-' . ($rentHc ?? 'x') . '-' . ($rentCc ?? 'x') . '-' . ($charges ?? 'x'),
            title: 'Appartement 4 pièces',
            description: 'Beau T4 lumineux',
            commune: 'Sartrouville',
            postcode: '78500',
            rentCc: $rentCc,
            rentHc: $rentHc,
            charges: $charges,
            surfaceM2: 80.0,
            rooms: 4,
        );
        $criteria = ConfigLoader::loadCriteria(self::ROOT . '/config/rent/criteria.json');
        $classification = (new TenureClassifier())->classify(
            $listing,
            new SourceProfile(name: 'leboncoin', family: 'private', defaultTenure: Tenure::LIBRE, mixedTenure: false),
        );

        return (new CriteriaEngine($criteria))->judge($listing, $classification);
    }

    /** The case the audit reproduced: 1900 € HC against a 1200 € ceiling. */
    public function testAnHcOnlyRentOverTheCeilingIsRejected(): void
    {
        $verdict = $this->judge(rentCc: null, rentHc: 1900);

        self::assertSame(Outcome::REJECT, $verdict->outcome);
        self::assertNotNull($verdict->disqualifier);
        self::assertStringContainsString('HC', $verdict->disqualifier, 'and the reason says it was the HC figure that decided');
    }

    /** Hard rule 9 and Q32 intact: an HC figure UNDER the ceiling is still an unknown, never a rejection. */
    public function testAnHcOnlyRentUnderTheCeilingStaysUnknownAndPasses(): void
    {
        $verdict = $this->judge(rentCc: null, rentHc: 1100);

        self::assertNotSame(Outcome::REJECT, $verdict->outcome);
        self::assertStringContainsString('HORS CHARGES', implode(' ', $verdict->reasons), 'and still says the ceiling could not be checked');
    }

    /** The boundary: the ceiling itself is not over it. */
    public function testAnHcOnlyRentExactlyAtTheCeilingIsNotRejected(): void
    {
        self::assertNotSame(Outcome::REJECT, $this->judge(rentCc: null, rentHc: 1200)->outcome);
    }

    /** Only when CC is UNKNOWN: a stated CC figure governs, whatever the HC figure beside it says. */
    public function testAKnownCcRentGovernsEvenWhenTheHcFigureIsOverTheCeiling(): void
    {
        self::assertNotSame(
            Outcome::REJECT,
            $this->judge(rentCc: 1100, rentHc: 1300)->outcome,
            'a stated CC figure under the ceiling is the budget the developer set; the HC figure is not consulted',
        );
    }

    /** Control: a derivable CC (HC + charges) over the ceiling is rejected by the existing CC rule, unchanged. */
    public function testADerivableCcOverTheCeilingStillRejectsOnTheCcRule(): void
    {
        $verdict = $this->judge(rentCc: null, rentHc: 1150, charges: 100);

        self::assertSame(Outcome::REJECT, $verdict->outcome);
        self::assertNotNull($verdict->disqualifier);
        self::assertStringContainsString('CC', $verdict->disqualifier);
        self::assertStringNotContainsString('HC >', $verdict->disqualifier, 'this is the CC rule, not the new HC-lower-bound one');
    }
}
