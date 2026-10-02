<?php

declare(strict_types=1);

namespace Scout\Tests\Rent\Core;

use PHPUnit\Framework\TestCase;
use Scout\Rent\Core\ListingSnapshot;
use Scout\Rent\Core\RawListing;

/**
 * `Core\ListingSnapshot` is the evidence half of schema v7.
 *
 * It exists so `scout reclassify` can re-run the classifier on **the evidence the original verdict
 * was formed from, never less**. That invariant is what keeps reclassify from being a §1 breach:
 * a card whose field says `PLS` while its title says *logement intermédiaire* classifies `UNKNOWN`
 * today by conflict, and re-run on the title alone it becomes a MATCH. The feature meant to reduce
 * misses would introduce the one thing §1 forbids.
 *
 * So the round trip is not a convenience — it is a correctness surface, and every case below is
 * hard rule 9 ("`None` is not zero") applied to serialisation:
 *
 * - `floor = 0` is the rez-de-chaussée and REAL; decoded as `null` it becomes "the source did not
 *   say", which is a different fact.
 * - `hasElevator = false` ("there is no lift") and `null` ("the listing did not mention one") are
 *   different facts, and only the explicit `false` may drive the high-floor penalty.
 * - `detailRead` decides whether weak evidence on a mixed source digests or matches. Lost in the
 *   round trip it reads as `false`, which is the SAFE direction — but it would silently re-digest
 *   every hydrated listing, so it is asserted rather than left to luck.
 */
final class ListingSnapshotTest extends TestCase
{
    /**
     * Every field survives, including the three whose falsy-but-real values a careless encoder eats.
     */
    public function testRoundTripPreservesEveryField(): void
    {
        $original = new RawListing(
            sourceName: 'inli',
            externalId: 'ABC-123',
            title: 'T4 lumineux',
            description: 'Au 3e étage, sans ascenseur.',
            fields: ['financement' => 'LLI', '_text' => '1 005 € cc · 55.32 m²'],
            url: 'https://example.test/a/1',
            commune: 'Sartrouville',
            postcode: '78500',
            rentCc: 1450,
            rentHc: 1300,
            charges: 150,
            surfaceM2: 88.5,
            rooms: 4,
            bedrooms: 3,
            floor: 0,
            hasElevator: false,
            detailRead: true,
        );

        $decoded = ListingSnapshot::decode(ListingSnapshot::encode($original));

        self::assertEquals($original, $decoded);

        // Spelled out separately from assertEquals, because the three below are the ones a null
        // coercion eats and an object comparison failure would not say which.
        self::assertSame(0, $decoded->floor, 'RDC is a floor, not an unknown floor');
        self::assertFalse($decoded->hasElevator, 'an explicit "no lift" is not an unmentioned lift');
        self::assertTrue($decoded->detailRead, 'a hydrated listing must not decode as unread');
    }

    /**
     * The absent case, which must stay absent rather than becoming a zero.
     */
    public function testRoundTripPreservesUnknownsAsNull(): void
    {
        $original = new RawListing(sourceName: 'cdc_habitat', externalId: 'X1');

        $decoded = ListingSnapshot::decode(ListingSnapshot::encode($original));

        self::assertEquals($original, $decoded);
        self::assertNull($decoded->floor, 'no floor stated is not the ground floor');
        self::assertNull($decoded->hasElevator, 'no lift mentioned is not "there is no lift"');
        self::assertNull($decoded->rentCc);
        self::assertNull($decoded->surfaceM2);
        self::assertFalse($decoded->detailRead);
        self::assertSame([], $decoded->fields);
    }

    /**
     * A float surface must not come back as an int, and an int rent must not come back as a float.
     *
     * JSON has one number type, so this is a real hazard rather than a theoretical one: `88.0`
     * round-trips through `json_decode` as a float but `1450.0` handed to an `?int` parameter is a
     * deprecation at best and a TypeError under strict types.
     */
    public function testNumericTypesSurviveJsonsSingleNumberType(): void
    {
        $original = new RawListing(
            sourceName: 'logirep',
            externalId: 'L9',
            surfaceM2: 88.0,
            rooms: 4,
            rentCc: 1200,
        );

        $decoded = ListingSnapshot::decode(ListingSnapshot::encode($original));

        self::assertIsFloat($decoded->surfaceM2);
        self::assertSame(88.0, $decoded->surfaceM2);
        self::assertIsInt($decoded->rentCc);
        self::assertIsInt($decoded->rooms);
    }

    /**
     * THE GUARD THAT KEEPS THIS HONEST AS `RawListing` GROWS.
     *
     * A field added to `RawListing` and not to the encoder is silently dropped from every snapshot
     * written afterwards — and the loss is invisible, because decode still succeeds and the missing
     * field reads as "the source did not say". Reclassify would then run on LESS evidence than the
     * original, which is exactly the §1 breach the whole mechanism exists to prevent.
     *
     * Asserted by reflection over the constructor itself, so tomorrow's field is covered without
     * anyone remembering this test exists. Same technique as the classifier's letter invariant: if
     * this goes red, add the field to the encoder — never relax the assertion.
     */
    /**
     * `observedAt` — the message date an email listing was observed at (2026-08-29) — survives the
     * round trip, and its absence decodes to null rather than to a fabricated "now". A re-judged
     * snapshot re-recorded at the pass time would be the phantom-drop loop a second way.
     */
    public function testTheObservationTimeSurvivesTheRoundTrip(): void
    {
        $dated = new RawListing(sourceName: 'bienici', externalId: 'x', observedAt: '2026-08-26T18:31:09Z');
        $undated = new RawListing(sourceName: 'bienici', externalId: 'x');

        self::assertSame('2026-08-26T18:31:09Z', ListingSnapshot::decode(ListingSnapshot::encode($dated))->observedAt);
        self::assertNull(ListingSnapshot::decode(ListingSnapshot::encode($undated))->observedAt);
    }

    public function testEncoderCoversEveryConstructorParameter(): void
    {
        $parameters = (new \ReflectionClass(RawListing::class))->getConstructor()?->getParameters() ?? [];
        self::assertNotSame([], $parameters, 'reflection found no constructor — the guard would pass vacuously');

        $encoded = json_decode(ListingSnapshot::encode(new RawListing('s', 'e')), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($encoded);

        foreach ($parameters as $parameter) {
            self::assertArrayHasKey(
                $parameter->getName(),
                $encoded,
                sprintf(
                    'RawListing::$%s is not persisted by ListingSnapshot. Add it to the encoder — a '
                    . 'field missing from the snapshot makes reclassify run on less evidence than '
                    . 'the original verdict saw, which is the §1 breach v7 exists to prevent.',
                    $parameter->getName(),
                ),
            );
        }
    }

    /**
     * Every constructor parameter given a value DISTINGUISHABLE from its default.
     *
     * Built by hand rather than by reflection so a new parameter appears here as a red test rather
     * than as an automatically-satisfied one — the guard below asserts nothing was skipped, and a
     * generated fixture would quietly generate the new field too and prove nothing about the
     * readers.
     */
    private function richListing(): RawListing
    {
        return new RawListing(
            sourceName: 'cdc_habitat',
            externalId: 'c1',
            title: 'Appartement T4',
            description: 'Logement intermediaire, 88 m2.',
            fields: ['financement' => 'LLI'],
            url: 'https://cdc.test/c1',
            commune: 'Sartrouville',
            postcode: '78500',
            rentCc: 1450,
            rentHc: 1300,
            charges: 150,
            surfaceM2: 88.0,
            rooms: 4,
            bedrooms: 3,
            floor: 2,
            hasElevator: true,
            detailRead: true,
            commuteMinutes: 68,
            observedAt: '2026-09-04T09:00:00+00:00',
            advertiser: 'CDC Habitat',
            proseAbsent: true,
        );
    }

    /**
     * THE READERS ARE GUARDED TOO, AND UNTIL NOW ONLY THE ENCODER WAS (C2 round 3, 2026-09-04).
     *
     * The guard above is structural — reflection over the constructor — and its own failure message
     * invokes §1. `decode()` and `RawListing::mergedWith()` had no counterpart, and the merge's
     * docblock conceded it in words: *"the reflection guard cannot catch it: that guard checks the
     * snapshot ENCODER, not the merge."* A comment is not a guard.
     *
     * A review panel wired a 22nd field into the encoder ONLY and measured the result: the encoder
     * guard noticed and forced it in (+2 assertions), **both readers noticed nothing** — decoded
     * `NULL`, survived the merge as `NULL`. Latent, because all 21 current parameters are carried by
     * both. But `CLAUDE.md` records this exact trap firing on the sibling `enrich()` path at a cost
     * of 429 phantom price-history rows and 128 *Baisse de loyer* emails for one flat, and that was
     * fixed with a clone-with AND a reflection guard. The merge got neither.
     *
     * ROUND-TRIP RATHER THAN KEY-PRESENCE, because that is what the readers can get wrong: a field
     * the encoder writes and the decoder ignores is present in the JSON and absent from the object.
     * Every parameter is given a value distinguishable from its default, so "unchanged" cannot pass
     * for "carried".
     */
    public function testEveryConstructorParameterSurvivesDecodeAndMerge(): void
    {
        $parameters = (new \ReflectionClass(RawListing::class))->getConstructor()?->getParameters() ?? [];
        self::assertNotSame([], $parameters, 'reflection found no constructor — the guard would pass vacuously');

        $rich = $this->richListing();
        $decoded = ListingSnapshot::decode(ListingSnapshot::encode($rich));

        $skipped = [];

        foreach ($parameters as $parameter) {
            $name = $parameter->getName();
            $property = new \ReflectionProperty(RawListing::class, $name);
            $expected = $property->getValue($rich);

            if ($expected === null) {
                // Only a parameter this test could not give a distinguishable value to. Named
                // rather than silently passed, so the coverage is auditable — an unnamed skip is
                // how a guard becomes decorative.
                $skipped[] = $name;

                continue;
            }

            self::assertSame($expected, $property->getValue($decoded), 'RawListing::$' . $name . ' is lost by decode()');

            // BOTH DIRECTIONS, because production runs the one this test originally did not.
            // `DetailHydrator` calls `$card->mergedWith($detail)`, and merging a RICH card with an
            // EMPTY detail makes every `$any($mine, $theirs)` take `$mine` — so mutating a field to
            // `$this->x` was invisible and 2 753 tests stayed green (C2 round 4).
            $fromCard = $rich->mergedWith(new RawListing('s', 'e'));
            self::assertSame($expected, $property->getValue($fromCard), 'RawListing::$' . $name . ' is lost when the card holds it');

            // THE ARGUMENT DIRECTION, for the merged fields only. A receiver-only field
            // (`sourceName`, `observedAt`, `advertiser`…) is deliberately NOT taken from the detail
            // page and would fail here for the right reason; which fields those are is DERIVED
            // below rather than listed, so a field silently dropped out of `$any` cannot exempt
            // itself from this assertion by disappearing from it.
            if (in_array($name, self::mergedParameters(), true)) {
                $fromDetail = (new RawListing('s', 'e'))->mergedWith($rich);
                self::assertSame($expected, $property->getValue($fromDetail), 'RawListing::$' . $name . ' is lost when the DETAIL holds it');

                // AND WITH BOTH SIDES HOLDING A VALUE, the DETAIL must win. Neither case above can
                // say so: each has one side empty, so `$any($mine, $theirs)` and a swapped
                // `$any($theirs, $mine)` return the same answer, and a lens proved the swap passes.
                // The rule is `$theirs ?? $mine` — the detail page is the better evidence — and this
                // is the only shape that distinguishes it. Latent today, because no shipped
                // `detail_map` maps a key the card also fills; the day one does, this decides the
                // verdict.
                if ($name !== 'fields') {
                    $other = self::otherValue($expected);
                    $contested = (new RawListing('s', 'e'))->mergedWith($rich)->mergedWith(
                        self::listingWith($property->getName(), $other),
                    );
                    self::assertSame(
                        $other,
                        $property->getValue($contested),
                        'RawListing::$' . $name . ' takes the CARD\'s value when both are present — the detail page is the better evidence',
                    );
                }
            }
        }

        self::assertSame([], $skipped, 'every constructor parameter must be exercised: ' . implode(', ', $skipped));
    }

    /**
     * A value of the same type that is DISTINGUISHABLE from the one given.
     *
     * `fields` is excluded by the caller rather than handled here: it merges by spread, so "the
     * detail wins" is a per-KEY statement, and the counterweight below already pins the spread.
     */
    private static function otherValue(mixed $value): mixed
    {
        return match (true) {
            is_bool($value) => !$value,
            is_int($value) => $value + 1,
            is_float($value) => $value + 1.0,
            is_string($value) => $value . '-detail',
            default => $value,
        };
    }

    /**
     * A listing empty but for one property.
     *
     * Built by SPREADING a named argument rather than by reflection: `RawListing` is readonly, so
     * `ReflectionProperty::setValue()` throws — and the spread needs no constructor list here, so
     * a new parameter still arrives through `richListing()` as a red test rather than silently.
     */
    private static function listingWith(string $property, mixed $value): RawListing
    {
        return new RawListing('s', 'e', ...[$property => $value]);
    }

    /**
     * The parameters `mergedWith()` actually combines, read out of its own body.
     *
     * Derived rather than listed, and that is the whole point: a listed set would be edited in the
     * same breath as the code, so dropping a field out of `$any(...)` — the exact C2 round-4
     * mutation — would silently drop it from the assertion too. Read from the source, the field
     * leaves the merged set and the guard below notices.
     *
     * @return list<string>
     */
    private static function mergedParameters(): array
    {
        $source = (string) file_get_contents(__DIR__ . '/../../../../src/php/Rent/Core/RawListing.php');
        $body = substr($source, (int) strpos($source, 'public function mergedWith('));

        // `fields` is merged by SPREAD rather than by a helper, so the pattern covers both forms.
        // Matching only the helpers would have parked a genuinely-merged field in the receiver-only
        // list, where a later regression could not be told from the exemption.
        preg_match_all('~(\w+):\s*(?:\$(?:any|str)\(|\[\.\.\.\$this->)~', $body, $matches);

        return $matches[1];
    }

    /**
     * THE COUNTERWEIGHT: the merged set must not shrink to nothing, and the receiver-only fields
     * must be exactly the ones that are receiver-only ON PURPOSE.
     *
     * Without this, deleting every `$any(` from `mergedWith()` would empty the derived set and the
     * argument-direction assertion above would pass vacuously on a method that merges nothing.
     */
    public function testTheReceiverOnlyFieldsAreExactlyTheOnesThatAreDeliberate(): void
    {
        $all = array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            (new \ReflectionClass(RawListing::class))->getConstructor()?->getParameters() ?? [],
        );

        $receiverOnly = array_values(array_diff($all, self::mergedParameters()));
        sort($receiverOnly);

        self::assertSame(
            // `sourceName`/`externalId` are the card's identity; `observedAt` must not re-date an
            // old card to the pass that hydrated it; `advertiser` must never be armed from page
            // furniture (a §1-relevant verdict change); `detailRead` is COMPUTED from the detail
            // (evidence-bearing prose or a structured field), never taken from the detail's own flag.
            // `proseAbsent` is a property of the SOURCE, so both sides always agree — written as
            // the card's to say which one is meant. It is here rather than merged for that reason,
            // not by omission.
            ['advertiser', 'detailRead', 'externalId', 'observedAt', 'proseAbsent', 'sourceName'],
            $receiverOnly,
            'a field stopped being merged from the detail page. If that is deliberate, say so here '
            . 'and give the reason; if it is not, it is the C2 round-4 defect returning.',
        );
    }

    /**
     * A snapshot that cannot be read is a LOUD failure, never a bare listing.
     *
     * Hard rule 3: an exception must not become an empty result. Degrading to a listing with no
     * fields is the silent-shrink case — it would classify as `UNKNOWN` and read as an honest
     * doubt rather than as a corrupt row.
     */
    public function testMalformedSnapshotIsRefusedLoudly(): void
    {
        $this->expectException(\JsonException::class);

        ListingSnapshot::decode('{not json');
    }

    /**
     * Well-formed JSON that is not a snapshot is refused too — the shape is checked, not just the
     * syntax. A `null` decode is valid JSON and would otherwise reach the constructor as nothing.
     */
    public function testWellFormedButWrongShapeIsRefusedLoudly(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ListingSnapshot::decode('"a string is valid JSON and is not a listing"');
    }

    /**
     * A NON-SCALAR FIELD VALUE SURVIVES THE ROUND TRIP, and this is the §1 case of the whole class.
     *
     * `decode()` used to keep a key only `if (is_scalar($item))`, so an array-valued field was
     * written by `encode()` and silently dropped on the way back. That is precisely the value
     * {@see \Scout\Rent\Core\TenureClassifier} raises its tier-1 DOUBT on, and the doubt is the only
     * thing withholding a match — so the round trip quietly disarmed the guard. A review panel
     * demonstrated the consequence end to end on 2026-08-24: `UNKNOWN`/`DIGEST` before,
     * `LLI`/`MATCH` and pushed after, on a listing whose own field named two excluded regimes.
     *
     * @see \Scout\Tests\Rent\Core\TenureSnapshotEvidenceTest for the classifier-level assertion
     */
    public function testANonScalarFieldValueSurvivesTheRoundTrip(): void
    {
        $listing = new RawListing(
            sourceName: 'demo',
            externalId: 'x-1',
            fields: ['gamme' => ['PLAI', 'PLUS']],
        );

        $back = ListingSnapshot::decode(ListingSnapshot::encode($listing));

        self::assertArrayHasKey('gamme', $back->fields, 'the key must survive — a field NAME is evidence in its own right');
        self::assertSame(['PLAI', 'PLUS'], $back->fields['gamme']);
    }

    /**
     * A `null`-valued field keeps its KEY, which is the half that is easy to miss.
     *
     * `numeroUniqueEnregistrement` and `demandeLogementSocial` are literal `PROCEDURAL` entries, so
     * the name alone is the strongest social discriminator the domain offers — dropping the key
     * because its value is empty throws away the signal while looking like tidying.
     */
    public function testANullValuedFieldKeepsItsKey(): void
    {
        $listing = new RawListing(
            sourceName: 'demo',
            externalId: 'x-2',
            fields: ['numeroUniqueEnregistrement' => null],
        );

        $back = ListingSnapshot::decode(ListingSnapshot::encode($listing));

        self::assertArrayHasKey('numeroUniqueEnregistrement', $back->fields);
        self::assertNull($back->fields['numeroUniqueEnregistrement']);
    }

    /**
     * The counterweight: an ordinary string field is still a string afterwards. Without this, a
     * "preserve everything" fix that stopped stringifying scalars would pass the two cases above
     * while changing what every real adapter's listing looks like to the classifier.
     */
    public function testAnOrdinaryScalarFieldIsStillAString(): void
    {
        $listing = new RawListing(
            sourceName: 'demo',
            externalId: 'x-3',
            fields: ['financement' => 'LLI', 'etage' => 3],
        );

        $back = ListingSnapshot::decode(ListingSnapshot::encode($listing));

        self::assertSame('LLI', $back->fields['financement']);
        self::assertSame('3', $back->fields['etage'], 'a scalar is normalised to its string form, as an adapter would have produced');
    }

    /**
     * `fields` that is not an object at all is CORRUPTION, not sparseness, and must be refused.
     *
     * Degrading it to `[]` would hand the classifier a listing with no structured evidence and no
     * way to tell that apart from a listing that genuinely had none — hard rule 3, in the one place
     * where the difference decides whether a social listing can be notified.
     */
    public function testANonObjectFieldsMapIsRefusedRatherThanEmptied(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ListingSnapshot::decode('{"sourceName":"demo","externalId":"x-4","fields":"not a map"}');
    }

    public function testASnapshotWrittenBeforeCommuteExistedDecodesToUnknown(): void
    {
        // Backward compatibility the reflection guard structurally cannot cover: it checks that
        // every constructor parameter is ENCODED, which says nothing about decoding a payload
        // written before the parameter existed. A stored row from yesterday has no `commuteMinutes`
        // key, and that is an OLD row, not a corrupt one — the decoder refuses corrupt snapshots
        // loudly, and must not refuse this.
        //
        // It decodes to null (unknown), never to 0. Zero minutes would be a listing on the doorstep
        // and would score the full commute component on the strength of an absent key.
        $decoded = ListingSnapshot::decode(json_encode([
            'sourceName' => 'pap',
            'externalId' => 'https://www.pap.fr/annonces/-r1',
            'title' => 'Location appartement 3 pièces',
            'description' => '',
            'fields' => [],
            'commune' => 'Milly-la-Forêt',
            'postcode' => '91490',
            'detailRead' => false,
        ], JSON_THROW_ON_ERROR));

        self::assertNull($decoded->commuteMinutes);
        self::assertSame('Milly-la-Forêt', $decoded->commune);
    }
}
