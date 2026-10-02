<?php

declare(strict_types=1);

namespace Scout\Tests\Rent\Core;

use PHPUnit\Framework\TestCase;
use Scout\Rent\Core\RawListing;

/**
 * `RawListing::mergedWith()` — the rule a detail fetch is merged under.
 *
 * Written after a sabotage run found three of its guarantees undetected. They were "covered" only
 * through `HtmlSource`, and the adapter hides two of them: it injects the CARD's ref into the detail
 * listing before merging, so a merge that preferred the detail's identity behaved identically and
 * every test stayed green. A guarantee that can only be observed through a caller which neutralises
 * it is not tested — it is assumed.
 */
final class RawListingMergeTest extends TestCase
{
    public function testTheDetailWinsWhereItHasAValue(): void
    {
        $merged = $this->card()->mergedWith($this->detail(rentCc: 1200, description: 'Logement intermédiaire'));

        self::assertSame(1200, $merged->rentCc);
        self::assertSame('Logement intermédiaire', $merged->description);
    }

    /** Hard rule 9: `null` is *unknown*, and unknown overwrites nothing. */
    public function testANullDetailValueNeverErasesTheCardsValue(): void
    {
        $merged = $this->card()->mergedWith($this->detail());

        self::assertSame(880, $merged->rentCc);
        self::assertSame(3, $merged->rooms);
        self::assertSame('HOUILLES', $merged->commune);
        self::assertTrue($merged->hasElevator, 'false and null are different facts, and this one was true');
    }

    /**
     * An empty string is an absent value, not a value of nothing.
     *
     * A selector that matched an empty element found NO text. Letting `''` win turns a good card
     * into a blank one — and a blank title or description is not visibly wrong in a digest, it just
     * quietly stops carrying the words the classifier reads.
     */
    public function testAnEmptyDetailStringNeverOverwritesTheCardsOwnText(): void
    {
        $merged = $this->card()->mergedWith($this->detail(title: '', description: ''));

        self::assertSame('Appartement 3 pièces', $merged->title);
        self::assertSame('Le texte de la carte', $merged->description);
    }

    /**
     * Identity comes from the card, always — even when the detail page names another.
     *
     * This is the guarantee the adapter cannot demonstrate, because it hands the detail mapper the
     * card's own ref before merging. Asserted here with two DIFFERENT ids, which is the only way to
     * see which one survives. A listing re-identified mid-pass is a listing the seen-set has never
     * seen, so it is announced again on every run forever.
     */
    // ── Audit 2026-10-02, P0-2: a detail page that said nothing is NOT a page that was read ─────────
    //
    // `detailRead` licenses a weak tenure signal on a mixed source ("examined and found nothing
    // excluding"), and it was set by reaching this method — so an HTTP-200 maintenance page, or a
    // description selector that stopped matching, counted as an examination. Measured on the live
    // store: 25 of 1036 In'li rows and 1 of 87 Cityloger rows were hydrated with an empty
    // description (their stored fields: a title and a postcode); eight of them were judged LLI/50
    // MATCH on the source default alone, one of them announced.

    /** The case that fired in production: the detail map found a title and a postcode and no prose. */
    public function testADetailThatYieldedNoProseIsNotAPageThatWasRead(): void
    {
        // `fields` is the whole flattened extract — `ref` is always in it — so it is modelled as it really arrives.
        $merged = $this->card()->mergedWith($this->detail(
            title: 'Appartement de 64.94 m² à GIF SUR YVETTE',
            postcode: '91190',
            fields: ['ref' => '229605', 'url' => 'https://www.cityloger.fr/logement-a-louer-229605'],
        ));

        self::assertFalse($merged->detailRead, 'a title and a postcode are not evidence about the tenure');
    }

    public function testADetailThatYieldedProseIsAPageThatWasRead(): void
    {
        $merged = $this->card()->mergedWith($this->detail(description: 'Logement intermédiaire'));

        self::assertTrue($merged->detailRead);
    }

    /** Cityloger's `tenure_field` is the structured tenure declaration, with no prose around it: it counts. */
    public function testADetailThatYieldedAStructuredFieldIsAPageThatWasRead(): void
    {
        $merged = $this->card()->mergedWith($this->detail(fields: ['tenureField' => 'LI15P']));

        self::assertTrue($merged->detailRead);
    }

    /** A later empty fetch never un-reads a listing that was already examined. */
    public function testAnEmptyDetailNeverUnreadsAnAlreadyHydratedCard(): void
    {
        $hydrated = $this->card()->mergedWith($this->detail(description: 'Logement intermédiaire'));
        self::assertTrue($hydrated->detailRead, 'premise');

        self::assertTrue($hydrated->mergedWith($this->detail())->detailRead);
    }

    public function testIdentityAlwaysComesFromTheCardEvenWhenTheDetailCarriesAnother(): void
    {
        $merged = $this->card()->mergedWith(
            new RawListing(sourceName: 'other-source', externalId: 'DIFFERENT-ID', title: 'x'),
        );

        self::assertSame('229605', $merged->externalId, 'the seen-set is keyed on this');
        self::assertSame('cityloger', $merged->sourceName);
    }

    /** Fields merge per key: the detail adds what it knows without discarding the card's own. */
    public function testFieldsMergePerKeyAndTheCardsOwnTextSurvives(): void
    {
        $merged = $this->card()->mergedWith(
            new RawListing(
                sourceName: 'cityloger',
                externalId: '229605',
                fields: ['tenureField' => 'LI15P'],
            ),
        );

        self::assertSame('LI15P', $merged->fields['tenureField'] ?? null);
        self::assertSame(
            'Appartement 3 pièces HOUILLES 78800 880 € cc',
            $merged->fields['_text'] ?? null,
            "the card's own text is correctly scoped to the card and must survive hydration",
        );
    }

    private function card(): RawListing
    {
        return new RawListing(
            sourceName: 'cityloger',
            externalId: '229605',
            title: 'Appartement 3 pièces',
            description: 'Le texte de la carte',
            fields: ['_text' => 'Appartement 3 pièces HOUILLES 78800 880 € cc'],
            url: 'https://www.cityloger.fr/logement-a-louer-229605',
            commune: 'HOUILLES',
            postcode: '78800',
            rentCc: 880,
            rooms: 3,
            hasElevator: true,
        );
    }

    /** @param array<string,string> $fields */
    private function detail(
        ?int $rentCc = null,
        string $description = '',
        string $title = '',
        array $fields = [],
        ?string $postcode = null,
    ): RawListing {
        return new RawListing(
            sourceName: 'cityloger',
            externalId: '229605',
            title: $title,
            description: $description,
            fields: $fields,
            postcode: $postcode,
            rentCc: $rentCc,
        );
    }
}
