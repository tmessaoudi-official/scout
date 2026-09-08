<?php

declare(strict_types=1);

namespace Scout\Tests\Rent\Core;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Scout\Rent\Core\Heating;

/**
 * The Track 7-A heating reader — the vocabulary was READ OFF the store, never composed.
 *
 * Every string in {@see realWordOrders} is a shape that occurs in `state/rent-watch.sqlite3`, with
 * the count that occurrence has. That matters more than the assertions: a reader tested against
 * invented French proves the author can write a regex, not that it reads what landlords type.
 *
 * The decisive measurement is that `individuel` and the energy word sit 0–3 words apart IN EITHER
 * ORDER, which is why this is a window and not the adjacency shape `exclude_patterns` uses for
 * meublé — an adjacency reader misses 9 of the 35 electric rows.
 */
#[CoversClass(Heating::class)]
final class HeatingTest extends TestCase
{
    /** @return iterable<string, array{0: string, 1: string, 2: ?string}> */
    public static function realWordOrders(): iterable
    {
        // mode-then-energy, the commonest shape (18 rows)
        yield 'individuel electrique' => ['Chauffage individuel électrique.', Heating::INDIVIDUAL, Heating::ELECTRIC];
        // ENERGY-THEN-MODE (6 rows) — the order an adjacency reader cannot see
        yield 'electrique individuel' => ['Chauffage électrique individuel.', Heating::INDIVIDUAL, Heating::ELECTRIC];
        // an infix verb (3 rows)
        yield 'est individuel' => ['Le chauffage est individuel électrique.', Heating::INDIVIDUAL, Heating::ELECTRIC];
        // an infix clause AND a plural (9 rows)
        yield 'et eau chaude' => ['Chauffage et eau chaude individuels gaz.', Heating::INDIVIDUAL, 'gaz'];
        // a preposition between the mode and the energy (3 rows)
        yield 'alimente au gaz' => ['Chauffage individuel alimenté au gaz.', Heating::INDIVIDUAL, 'gaz'];
        // the collective side, which takes no penalty at all
        yield 'collectif' => ['Chauffage collectif au gaz.', Heating::COLLECTIVE, 'gaz'];
        yield 'collectif feminine' => ['La chaufferie : chauffage collective.', Heating::COLLECTIVE, null];
    }

    #[DataProvider('realWordOrders')]
    public function testEveryWordOrderTheStoreActuallyContainsIsRead(string $text, string $mode, ?string $energy): void
    {
        $h = Heating::read($text);

        self::assertNotNull($h, 'this shape occurs in the live store and must not come back null');
        self::assertSame($mode, $h->mode);
        self::assertSame($energy, $h->energy);
    }

    /**
     * THE NEGATION IS READ FIRST — the lift-negation lesson, on a new surface.
     *
     * `Payload::bool()` once read *"Aucun ascenseur"* as `true`, and a wrong `individuel` here costs
     * a real flat 20 points of ordering for a fact the ad explicitly denied.
     *
     * @param string $text
     */
    #[DataProvider('negations')]
    public function testANegatedHeatingIsNotAMode(string $text): void
    {
        self::assertNull(Heating::read($text), 'the ad denied it — that is not a mode');
    }

    /** @return iterable<string, array{0: string}> */
    public static function negations(): iterable
    {
        yield 'sans' => ['Logement sans chauffage individuel installé.'];
        yield 'pas de' => ['Il n\'y a pas de chauffage collectif dans cet immeuble.'];
        yield 'aucun' => ['Aucun chauffage individuel n\'est prévu.'];
        yield 'aucune with a word between' => ['Aucune installation de chauffage individuel.'];
    }

    /**
     * THE COUNTERWEIGHT, and without it the negation guard is satisfied by returning null always.
     *
     * A reader that never answers is indistinguishable from a careful one on the tests above.
     */
    public function testAPlainStatementIsStillReadWhenANegationSitsElsewhereInTheText(): void
    {
        $h = Heating::read('Résidence sans vis-à-vis. Chauffage individuel gaz. Sans ascenseur.');

        self::assertNotNull($h);
        self::assertTrue($h->isIndividual());
        self::assertSame('gaz', $h->energy);
    }

    /**
     * EVERY OCCURRENCE IS EXAMINED, never only the first.
     *
     * `preg_match` stopping at the first hit is what let one implausible rent hide a readable one
     * three lines below it (the SeLoger price-drop fix), and a description naming the building's
     * collective system before the flat's own is the same shape.
     */
    public function testAnUnreadableFirstOccurrenceDoesNotHideAReadableSecond(): void
    {
        $h = Heating::read('Le chauffage. Puis : chauffage individuel électrique dans le logement.');

        self::assertNotNull($h, 'a bare first mention must not end the scan');
        self::assertTrue($h->electric);
    }

    /**
     * AN UNSTATED ENERGY IS NOT ELECTRICITY (developer ruling, 2026-09-08).
     *
     * The largest single class in the store — 101 rows, 24 of them matched — states the mode and no
     * energy at all. It takes the BASE penalty only; reading it as electric would manufacture a fact
     * from an absence, which is hard rule 9 read backwards.
     */
    public function testAModeWithNoEnergyIsIndividualAndNotElectric(): void
    {
        $h = Heating::read('Chauffage individuel.');

        self::assertNotNull($h);
        self::assertTrue($h->isIndividual());
        self::assertNull($h->energy);
        self::assertFalse($h->electric, 'the surcharge needs an explicit electric, never a silence');
        self::assertSame('chauffage individuel', $h->label());
    }

    /**
     * THE ENERGY IS THE FIRST BY POSITION, not the first by the table's order.
     *
     * `chauffage individuel gaz, eau chaude électrique` names gas for the heating and electricity
     * for something else; taking the table's order would apply the −15 surcharge to a gas flat.
     */
    public function testTheEnergyIsTheOneNearestTheWordChauffage(): void
    {
        $h = Heating::read('Chauffage individuel gaz, eau chaude électrique.');

        self::assertNotNull($h);
        self::assertSame('gaz', $h->energy);
        self::assertFalse($h->electric);
    }

    /**
     * WORDS WITH ZERO HITS IN THE STORE ARE DELIBERATELY ABSENT.
     *
     * `convecteur`, `radiateur`, `CPCU` and `reseau de chaleur` were each measured at 0 occurrences
     * across all 3 377 stored rows. A vocabulary entry no payload can reach is coverage this reader
     * does not have, and this test is what stops one being added on a hunch — if a real payload
     * ever carries one, the measurement comes first and this test changes with it.
     *
     * @param string $text
     */
    #[DataProvider('vocabularyDeliberatelyAbsent')]
    public function testAWordWithNoOccurrenceInTheStoreIsNotVocabulary(string $text): void
    {
        self::assertNull(Heating::read($text));
    }

    /** @return iterable<string, array{0: string}> */
    public static function vocabularyDeliberatelyAbsent(): iterable
    {
        yield 'convecteur' => ['Convecteurs électriques dans chaque pièce.'];
        yield 'radiateur' => ['Radiateurs à eau dans le séjour.'];
        yield 'cpcu' => ['Raccordé au CPCU.'];
        yield 'reseau de chaleur' => ['Réseau de chaleur urbain.'];
    }

    /** Silence, unreadable bytes and an empty string all say nothing (hard rule 9). */
    public function testNothingReadableIsNull(): void
    {
        self::assertNull(Heating::read(null));
        self::assertNull(Heating::read(''));
        self::assertNull(Heating::read('   '));
        self::assertNull(Heating::read('Bel appartement lumineux avec vue dégagée.'));
        self::assertNull(Heating::read("Chauffage individuel \xC3\x28 gaz"), 'unfoldable text is not evidence');
    }
}
