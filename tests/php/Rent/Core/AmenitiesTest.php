<?php

declare(strict_types=1);

namespace Scout\Tests\Rent\Core;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Scout\Rent\Core\Amenities;

/**
 * The Track 7-B display reader: terrace, balcony, loggia, garden, cellar and the parking family.
 *
 * **IT REJECTS NOTHING AND SCORES NOTHING** (hard rule 8), so every guarantee here is about what
 * the notification SAYS. That makes the silence cases as important as the reading ones: an absent
 * line must mean *the ad said nothing*, never *the flat lacks a terrace*.
 *
 * The three false-positive shapes below are not invented — each was measured in the live store
 * before this class existed, and each is an instance of a class this repo has already paid for.
 */
#[CoversClass(Amenities::class)]
final class AmenitiesTest extends TestCase
{
    /** @return iterable<string, array{0: string, 1: list<string>}> */
    public static function ruledAmenities(): iterable
    {
        yield 'terrasse' => ['Appartement avec terrasse plein sud.', ['terrasse']];
        yield 'balcon' => ['Séjour donnant sur un balcon.', ['balcon']];
        yield 'loggia' => ['Une loggia fermée.', ['loggia']];
        yield 'jardin' => ['Jardin privatif de 40 m².', ['jardin']];
        yield 'cave' => ['Une cave en sous-sol complète le bien.', ['cave']];
        yield 'parking' => ['Un parking en sous-sol.', ['parking']];
        yield 'stationnement is the parking family' => ['Place de stationnement.', ['parking']];
        yield 'garage is the parking family' => ['Garage fermé.', ['parking']];
        yield 'box is the parking family' => ['Un box privatif.', ['parking']];
        yield 'plural' => ['Deux balcons.', ['balcon']];
        yield 'the fixed order, not the text order' => [
            'Parking, jardin, balcon et terrasse.',
            ['terrasse', 'balcon', 'jardin', 'parking'],
        ];
    }

    #[DataProvider('ruledAmenities')]
    public function testEveryRuledAmenityIsReadAndTheOrderIsFixed(string $text, array $expected): void
    {
        self::assertSame($expected, Amenities::read($text));
    }

    /**
     * `cellier` IS DROPPED BY RULING, and not because of its reach.
     *
     * It measured 0 matched mentions, but the deciding argument is that a cellier is an indoor
     * pantry rather than a basement cave — folding the two together would state something the ad
     * did not, which is the whole failure mode a display line has.
     */
    public function testCellierIsNotReadAsACave(): void
    {
        self::assertSame([], Amenities::read('Un cellier attenant à la cuisine.'));
    }

    /**
     * A RESIDENCE NAMED AFTER THE THING IT DOES NOT OFFER — the furniture class, sixth instance.
     *
     * `12, les terrasses de la ravinière` is 2 of terrasse's 38 matched mentions in the live store.
     * The same shape has cost this repo a CDC tooltip, a Logirep facet strip, a SeLoger CTA and a
     * leboncoin campaign string.
     */
    public function testAResidenceNameIsNotAnAmenity(): void
    {
        self::assertSame([], Amenities::read('Résidence 12, les terrasses de la ravinière.'));
        self::assertSame([], Amenities::read('Adresse : 4, les terrasses du parc.'));

        // THE COUNTERWEIGHT: the guard is the plural after a comma or a number, and nothing wider.
        // A flat that really has two of them still says so.
        self::assertSame(['terrasse'], Amenities::read('Deux terrasses exposées sud.'));
        self::assertSame(['terrasse'], Amenities::read('Grande terrasse de 20 m².'));
    }

    /**
     * URLS ARE CLASSIFIED TEXT — the tenth instance, and `cave` was measured inside a real SeLoger
     * tracking token.
     *
     * The query and fragment go; the PATH stays, exactly as `RawListing::text()` already rules —
     * and this class calls that method rather than writing the expression a third time.
     */
    public function testATrackingTokenIsNotAnAmenity(): void
    {
        self::assertSame([], Amenities::read('Voir : https://click.by.seloger.com/?qs=abb7cave9xj'));
        self::assertSame([], Amenities::read('https://example.test/annonce#cave-parking'));

        // The counterweight, and it is the asymmetry that matters: a path segment is real text.
        self::assertSame(['cave'], Amenities::read('https://example.test/bien-avec-cave/'));
    }

    /**
     * `rez de jardin` IS A FLOOR, and the line this joins already prints the floor.
     *
     * 4 of jardin's 32 mentions in the store are this shape.
     */
    public function testRezDeJardinIsAFloorAndNotAGarden(): void
    {
        self::assertSame([], Amenities::read('Appartement en rez-de-jardin.'));
        self::assertSame([], Amenities::read('Situé en rez de jardin.'));
        self::assertSame(['jardin'], Amenities::read('Rez-de-chaussée avec jardin privatif.'));
    }

    /**
     * `emplacement` IS NOT IN THE PARKING FAMILY, AND THIS IS THE CASE THAT REMOVED IT.
     *
     * It used to be, and this provider used to assert `Emplacement extérieur.` reads as a parking
     * space. That assertion is gone deliberately rather than quietly: of the word's 36 distinct
     * contexts in the store EIGHT are a LOCATION (`emplacement privilégié / idéal / pratique`), and
     * on a card carrying no other family word it announced a space the advertisement never offered.
     *
     * The counterweight is the second assertion, and it is what makes the removal free: a real
     * `emplacement de parking` still reads, through `parking` — which is why the shipped label is
     * identical on all 3 379 stored rows with the word removed.
     */
    public function testALocationIsNotAParkingSpace(): void
    {
        self::assertSame([], Amenities::read('Emplacement idéal : proche des écoles et des commerces.'));
        self::assertSame([], Amenities::read('Résidence neuve, emplacement privilégié.'));

        self::assertSame(['parking inclus'], Amenities::read('Un emplacement de parking est inclus.'));
        self::assertSame(['parking'], Amenities::read('Loué avec un emplacement de stationnement.'));
    }

    /**
     * A MENTION IS NOT AN INCLUSION, and the naive reader for this was built and rejected.
     *
     * **37** of the 149 parking-mentioning matches carry *inclus / compris / attribué* within
     * {@see Amenities::INCLUSION_WINDOW} characters of the amenity, and 93 carry one ANYWHERE in the
     * description — this docblock quoted 79, which is neither figure and was the design's own
     * unmeasured guess. The near-count is the one that ships: an inclusion word three sentences away
     * is usually about the charges (`y compris les charges`).
     *
     * A `parking … XX €` reader gave 38 hits of which 36 were CDC false positives, because that card
     * puts the rent immediately after the amenity list — so the claim rests on an explicit inclusion
     * WORD and on nothing else.
     */
    public function testInclusionIsClaimedOnlyOnAnExplicitWord(): void
    {
        self::assertSame(['parking inclus'], Amenities::read('Un parking inclus dans le loyer.'));
        self::assertSame(['parking inclus'], Amenities::read('Place de stationnement comprise.'));
        self::assertSame(['parking inclus'], Amenities::read('Un box attribué au locataire.'));

        // Plain, because the ad said nothing about who pays.
        self::assertSame(['parking'], Amenities::read('Un parking en sous-sol. Loyer 1 100 € CC.'));
        // And `en sus` is the opposite — the five Cityloger rows reading `possibilité de louer … en
        // sus` must never print `inclus`.
        self::assertSame(['parking'], Amenities::read('Possibilité de louer un parking en sus.'));
    }

    /**
     * EVERY WORD OF THE FAMILY IS EXAMINED, never only the first.
     *
     * A description naming a `garage` in one sentence and a `stationnement inclus` in another states
     * an included space, and stopping at the first match would print the weaker claim.
     */
    public function testAnIncludedSpaceLaterInTheTextStillCounts(): void
    {
        self::assertSame(['parking inclus'], Amenities::read('Un garage. Une place de stationnement incluse.'));
    }

    /**
     * SILENCE IS SILENCE — the display twin of hard rule 9, and the half a display feature always
     * risks losing.
     *
     * Five of the eight rent sources carry no listing prose at all, so on those this returns `[]`
     * on every flat and that is the correct output rather than a gap.
     */
    public function testNothingReadableSaysNothing(): void
    {
        self::assertSame([], Amenities::read(null));
        self::assertSame([], Amenities::read(''));
        self::assertSame([], Amenities::read('Bel appartement lumineux, proche des commerces.'));
        self::assertSame([], Amenities::read("Terrasse \xC3\x28 sud"), 'unfoldable text states nothing');
    }
}
