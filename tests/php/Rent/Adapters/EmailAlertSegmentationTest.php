<?php

declare(strict_types=1);

namespace Scout\Tests\Rent\Adapters;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Rent\Adapters\EmailAlertSource;
use Scout\Adapters\Mail\FileMailbox;
use Scout\Adapters\SourceError;
use Scout\Rent\Config\FieldMap;
use Scout\Rent\Config\SourceDefinition;
use Scout\Rent\Core\Tenure;
use Scout\Rent\Store\Store;

/**
 * Card segmentation and content-addressed identity, shaped by the first real portal alert.
 *
 * **The defect this closes is an identity collapse, not a parsing one.** SeLoger sends no listing
 * URL and no listing id: every link in an alert is `click.by.seloger.com/?qs=<opaque token>`, and
 * `EmailAlertSource` keys on the link with its query stripped — so all sixteen links in a real
 * message resolve to the single id `https://click.by.seloger.com/`. Sixteen cards, one identity.
 *
 * The redirect is deliberately never followed to recover the real URL: it is a third-party request
 * per listing, the token carries the subscriber's identity, and following it manufactures an
 * engagement signal from a click nobody made. Hard rule 5 one step out.
 */
#[CoversClass(EmailAlertSource::class)]
final class EmailAlertSegmentationTest extends TestCase
{
    private string $dir = '';

    private string $dbPath = '';

    protected function tearDown(): void
    {
        if ($this->dir !== '' && is_dir($this->dir)) {
            foreach (glob($this->dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->dir);
        }
        if ($this->dbPath !== '') {
            @unlink($this->dbPath);
        }
    }

    // ------------------------------------------------------------------ segmentation

    public function testEachCardBecomesItsOwnListing(): void
    {
        $listings = $this->source(self::body([
            self::card('980', 'Appartement Conflans', '3 pièces . 44,71 m²', 'Romagne', 'Conflans-Sainte-Honorine', '78700'),
            self::card('915', 'Appartement À Louer', '3 pièces . 52,37 m²', 'Le Parterre', 'Dourdan', '91410'),
        ]))->fetch();

        self::assertCount(2, $listings, 'two cards, two listings');

        $ids = array_map(static fn ($l) => $l->externalId, $listings);
        self::assertCount(2, array_unique($ids), 'a card is not the same flat as the card above it');
    }

    /**
     * The footer is a segment too, and it is not a card.
     *
     * A trailer carrying the CNIL notice, the postal address and a campaign code follows the last
     * CTA in every real alert. It yields no rent, so it is not a listing.
     */
    public function testTheFooterIsNotAListing(): void
    {
        $listings = $this->source(self::body([
            self::card('980', 'Appartement Conflans', '3 pièces . 44,71 m²', 'Romagne', 'Conflans-Sainte-Honorine', '78700'),
        ]))->fetch();

        self::assertCount(1, $listings);
    }

    /**
     * The card is the description, not the message.
     *
     * This is the Cityloger ruling on a new surface: a map must address the LISTING, never the page.
     * Whole-body description is the furniture failure class that sent 14 of 16 correctly-badged CDC
     * listings to the digest, and an alert's trailer is furniture by construction.
     */
    public function testTheDescriptionIsTheCardNotTheWholeMessage(): void
    {
        $listings = $this->source(self::body([
            self::card('980', 'Appartement Conflans', '3 pièces . 44,71 m²', 'Romagne', 'Conflans-Sainte-Honorine', '78700'),
            self::card('915', 'Appartement Dourdan', '3 pièces . 52,37 m²', 'Le Parterre', 'Dourdan', '91410'),
        ]))->fetch();

        self::assertStringNotContainsString('Dourdan', $listings[0]->description, 'card 1 must not carry card 2');
        self::assertStringNotContainsString('Conformément à la loi', $listings[0]->description, 'nor the trailer');
    }

    // ------------------------------------------------------------------ identity

    /**
     * **Rent is NOT in the identity, and that is the whole point of excluding it.**
     *
     * A price drop is an event this project exists to detect. A rent in the key turns every drop
     * into a brand-new listing — notified as new, with no price history and no "en baisse" reason —
     * which is the silent opposite of what the store's price-history table is for.
     */
    public function testAPriceChangeDoesNotChangeTheIdentity(): void
    {
        $before = $this->source(self::body([
            self::card('980', 'Appartement Conflans', '3 pièces . 44,71 m²', 'Romagne', 'Conflans-Sainte-Honorine', '78700'),
        ]))->fetch();

        $after = $this->source(self::body([
            self::card('940', 'Appartement Conflans', '3 pièces . 44,71 m²', 'Romagne', 'Conflans-Sainte-Honorine', '78700'),
        ]))->fetch();

        self::assertSame($before[0]->externalId, $after[0]->externalId, 'same flat, lower rent');
        self::assertSame(980, $before[0]->rentCc);
        self::assertSame(940, $after[0]->rentCc);
    }

    /** A different flat is a different identity — the guard against under-notifying. */
    public function testADifferentFlatIsADifferentIdentity(): void
    {
        $listings = $this->source(self::body([
            self::card('980', 'Appartement A', '3 pièces . 44,71 m²', 'Romagne', 'Conflans-Sainte-Honorine', '78700'),
            self::card('980', 'Appartement B', '3 pièces . 61,20 m²', 'Romagne', 'Conflans-Sainte-Honorine', '78700'),
        ]))->fetch();

        self::assertNotSame($listings[0]->externalId, $listings[1]->externalId, 'a different surface is a different flat');
    }

    /**
     * **The no-information floor.** A card whose extraction fails across the board would hash to
     * `sha1("seloger|||||")` — and EVERY such card collapses onto that one id, which is the store's
     * own "nothing collapses onto a shared key" identity guarantee violated one layer up.
     *
     * Skipping costs nothing real: a card with no location can never match anyway (Q32 — no
     * location evidence is a rejection), so the alternative to skipping is a listing that is
     * rejected AND poisons an identity.
     */
    public function testACardWithNoLocatingEvidenceIsNotGivenAnIdentity(): void
    {
        $listings = $this->source(self::body([
            self::card('980', 'Appartement Conflans', '3 pièces . 44,71 m²', 'Romagne', 'Conflans-Sainte-Honorine', '78700'),
            "\n<L>\n1 100 €/mois charges comprises\n<L>\nVoir l'annonce\n",
        ]))->fetch();

        self::assertCount(1, $listings, 'the card with no commune, postcode, rooms or surface is refused');
        self::assertStringContainsString('Conflans', (string) $listings[0]->commune);
    }

    /**
     * The floor has TWO halves, and this exercises the half the test above cannot.
     *
     * *Nothing at all* is refused by either half, so a card carrying nothing proves only that one
     * of them fired. This card DESCRIBES a flat in full — three rooms, 52 m², a rent — and does not
     * LOCATE it, which is the case where only the locating half stands between the card and an
     * identity.
     *
     * Found by sabotage, not by design: neutering the locating half alone left the suite green,
     * because every card the suite had was missing both. A rule with an untested half is a rule
     * that can be deleted silently.
     */
    public function testDescribingAFlatIsNotLocatingIt(): void
    {
        $listings = $this->source(self::body([
            self::card('980', 'Appartement Conflans', '3 pièces . 44,71 m²', 'Romagne', 'Conflans-Sainte-Honorine', '78700'),
            "\n<L>\n1 100 €/mois charges comprises\n<L>\nAppartement Sans Adresse\n<L>\n3 pièces . 52,37 m²\n<L>\nVoir l'annonce\n",
        ]))->fetch();

        self::assertCount(1, $listings, 'rooms and a surface are not a location (Q32)');
        self::assertStringContainsString('Conflans', (string) $listings[0]->commune);
    }

    /**
     * A message that HAS cards but yields none is a broken parser, and it must be loud.
     *
     * This is hard rule 2's shape: extraction that silently returns nothing is indistinguishable
     * from a market that went quiet, and the alert literally says how many listings it carries.
     */
    public function testAMessageWhoseCardsAllFailIsALoudFailure(): void
    {
        $this->expectException(SourceError::class);
        $this->expectExceptionMessageMatches('~aucune annonce~i');

        $this->source(self::body([
            "\n<L>\nRien d'exploitable ici\n<L>\nVoir l'annonce\n",
        ]))->fetch();
    }

    /**
     * Two cards in one message sharing an id: KEEP ONE, DROP THE REST, AND SAY SO.
     *
     * **This used to throw, and the change is a ruling rather than a relaxation** (developer, 2026-08-26).
     * The throw was right that a collision must never be silent and wrong about its blast radius,
     * which is the detail-hydration lesson exactly: a per-message data EVENT was being treated as a
     * broken-template STATE, so one message took the whole source down.
     *
     * It happened for real. On 2026-08-26 at 17:11 a `Baisse de prix` digest carried three coliving
     * ROOMS in one flat at Gros Saule, Aulnay-sous-Bois — each advertised with the whole flat's
     * `6 pièces . 83,99 m²`, so commune, postcode, rooms, surface and residence were genuinely
     * identical and only the OLD price differed. seloger returned zero listings for seven
     * consecutive passes. The guard's own message — *the fields that distinguish them were not
     * read* — was FALSE: they were read correctly, and the three rooms are indistinguishable by any
     * field that belongs in a stable identity. That is the documented cost of content-addressing
     * arriving, not a fault.
     *
     * What is NOT relaxed is the silence: the collision is announced every pass, with the id.
     */
    public function testTwoCardsInOneMessageSharingAnIdentityKeepOneAndSaysSo(): void
    {
        $said = [];

        $listings = $this->source(
            self::body([
                self::card('980', 'Appartement A', '3 pièces . 44,71 m²', 'Romagne', 'Conflans-Sainte-Honorine', '78700'),
                self::card('1 400', 'Appartement B', '3 pièces . 44,71 m²', 'Romagne', 'Conflans-Sainte-Honorine', '78700'),
            ]),
            warn: function (string $m) use (&$said): void { $said[] = $m; },
        )->fetch();

        self::assertCount(1, $listings, 'one survives; the source does not go down over it');
        self::assertCount(1, $said, 'and it is announced, not swallowed');
        self::assertStringContainsString($listings[0]->externalId, $said[0], 'the id is named');
    }

    /**
     * The counterweight, and without it the ruling above is satisfied by dropping every card.
     *
     * Two cards that genuinely differ must still yield TWO listings and say nothing at all.
     */
    public function testTwoDistinctCardsAreBothKeptAndSilent(): void
    {
        $said = [];

        $listings = $this->source(
            self::body([
                self::card('980', 'Appartement A', '3 pièces . 44,71 m²', 'Romagne', 'Conflans-Sainte-Honorine', '78700'),
                self::card('1 400', 'Appartement B', '4 pièces . 71,20 m²', 'Romagne', 'Dourdan', '91410'),
            ]),
            warn: function (string $m) use (&$said): void { $said[] = $m; },
        )->fetch();

        self::assertCount(2, $listings);
        self::assertSame([], $said, 'a message with no collision says nothing');
    }

    /**
     * A source given no warn channel must not crash on a collision.
     *
     * Every other test in this class constructs the source without one, so the null branch is the
     * common path — and a diagnostic able to take down a fetch is worse than the silence it
     * replaced.
     */
    public function testACollisionWithNoWarnChannelIsStillSurvivable(): void
    {
        $listings = $this->source(self::body([
            self::card('980', 'Appartement A', '3 pièces . 44,71 m²', 'Romagne', 'Conflans-Sainte-Honorine', '78700'),
            self::card('1 400', 'Appartement B', '3 pièces . 44,71 m²', 'Romagne', 'Conflans-Sainte-Honorine', '78700'),
        ]))->fetch();

        self::assertCount(1, $listings);
    }

    // ------------------------------------------------------------------ backwards compatibility

    /**
     * With no `card_separator` the source behaves exactly as it did — one listing per link, whole
     * body as the description. `email_demo` and its two committed fixtures depend on it.
     */
    public function testWithoutASeparatorTheOldBehaviourIsUnchanged(): void
    {
        $body = self::body([
            self::card('980', 'Appartement Conflans', '3 pièces . 44,71 m²', 'Romagne', 'Conflans-Sainte-Honorine', '78700'),
        ]);

        $listings = $this->source($body, params: ['link_host' => 'example-portal.test'])->fetch();

        foreach ($listings as $listing) {
            self::assertStringStartsWith('https://example-portal.test/', $listing->externalId);
        }
    }

    // ------------------------------------------------------------------ rent

    /**
     * A PRICE-DROP alert quotes three amounts, and only one of them is the rent.
     *
     * Captured from a live SeLoger message on 2026-08-25 (*"Baisse de prix"*, Pontault-Combault
     * 77340). It states the DISCOUNT first (`baissé de 100 €`), then the new rent (`1 100 €/mois`),
     * then the old one (`1 200 € ↘ 8%`) — and the reader took the first figure it saw.
     *
     * **100 € is below the plausibility floor, so the card was dropped entirely** and the source
     * reported `broken` on a message whose template had not changed at all. That is the benign
     * direction. Had the reduction been 300 € the reader would have returned **300** — inside the
     * band, wildly wrong, and clearing a rent ceiling the flat comes nowhere near. Same shape as the
     * `ref 850` defect of the same day: a plausible wrong rent is worse than none.
     *
     * Two rules come out of it, and this one card is the evidence for both. **A rent is a PERIODIC
     * amount**: `/mois` is what tells the new rent apart from a discount and from a struck-through
     * old price, neither of which carries a period. And **a pattern is scanned for ALL its matches,
     * first plausible one wins**, because the bare figure fallback would otherwise still take the
     * discount on a card that states no period at all.
     */
    public function testAPriceDropCardReadsTheRentAndNotTheReduction(): void
    {
        $listings = $this->source(self::body([
            "\n<L>\n Le prix d'un bien correspondant à votre recherche a baissé de 100 €\n"
                . "<L>\n1 100 €/mois \n"
                . "<L>\n1 200 € ↘ 8%\n"
                . "<L>\nAppartement 3 pièces\n"
                . "<L>\n3 pièces . 66,57 m² \n"
                . "<L>\n Mairie Rouxel-Sud Est, \n\n Pontault-Combault\n (77340)\n"
                . "<L>\nVoir l'annonce\n",
        ]), self::shippedParams())->fetch();

        self::assertCount(1, $listings, 'the card is readable — 100 € is a reduction, not a rent');
        self::assertSame(1100, $listings[0]->rentCc, 'the periodic amount, not the discount and not the old price');
        self::assertSame('Pontault-Combault', $listings[0]->commune);
    }

    /**
     * The two rules above are INDEPENDENT, and this pair is what proves it.
     *
     * The captured card cannot: a 100 € reduction is below the plausibility floor, so scanning
     * every match rescues it even with no periodic pattern, and the periodic pattern rescues it even
     * scanning only the first. Each fix alone makes that test pass — so it asserts "at least one of
     * these works", which is not what its docblock claims. The sabotage ledger said so: both cases
     * came back UNDETECTED while the suite was green.
     *
     * Here the reduction is **300 €** — the hypothetical the fix was argued from, made executable.
     * It sits inside the plausibility band, so scanning every match does NOT save it: only knowing
     * that a rent carries a period does. Six hundred euros wrong, and it would clear a ceiling the
     * flat comes nowhere near.
     */
    public function testAPlausibleReductionIsNotMistakenForTheRent(): void
    {
        $listings = $this->source(self::body([
            "\n<L>\n Le prix a baissé de 300 €\n"
                . "<L>\n1 100 €/mois \n"
                . "<L>\nAppartement 3 pièces\n"
                . "<L>\n3 pièces . 66,57 m² \n"
                . "<L>\n Pontault-Combault\n (77340)\n"
                . "<L>\nVoir l'annonce\n",
        ]), self::shippedParams())->fetch();

        self::assertSame(1100, $listings[0]->rentCc ?? null, 'the periodic figure, not the plausible discount');
    }

    /**
     * And the converse: no periodic marker anywhere, so only scanning every match can find the rent.
     *
     * A card quoting a fee before a bare rent. The first figure is implausible, and stopping there
     * — which is what `preg_match` does — drops a listing that states its rent perfectly clearly
     * two lines down. Silent: the card simply never becomes a listing.
     */
    public function testAnImplausibleFigureDoesNotHideABareRentBelowIt(): void
    {
        $listings = $this->source(self::body([
            "\n<L>\nFrais de dossier 50 €\n"
                . "<L>\n980 €\n"
                . "<L>\nAppartement 3 pièces\n"
                . "<L>\n3 pièces . 66,57 m² \n"
                . "<L>\n Pontault-Combault\n (77340)\n"
                . "<L>\nVoir l'annonce\n",
        ]), self::shippedParams())->fetch();

        self::assertSame(980, $listings[0]->rentCc ?? null, 'the fee is not the rent, and it does not hide it');
    }

    // ------------------------------------------------------------------ commune

    /**
     * The quartier line above the commune is OPTIONAL, and both shapes must read.
     *
     * The two frozen fixtures both carry one, so a fixture-only assertion would prove the shape
     * that has a quartier and say nothing about the shape that does not — the generalisation from
     * n=1 this repo has already paid for twice. Measured against the live mailbox on 2026-08-25:
     * of nine cards, three (`Mormant`, `Garches`, `Moret-Loing-et-Orvanne`) sit directly above
     * their postcode with no quartier at all. That is why the pattern anchors on the parenthesised
     * postcode BELOW the name and not on the comma above it.
     */
    public function testTheCommuneIsReadWithOrWithoutAQuartierLine(): void
    {
        $listings = $this->source(self::body([
            self::card('980', 'Appartement Conflans', '3 pièces . 44,71 m²', 'Romagne', 'Conflans-Sainte-Honorine', '78700'),
            self::cardWithoutQuartier('860', 'Appartement Mormant', '3 pièces . 80 m²', 'Mormant', '77720'),
        ]), self::shippedParams())->fetch();

        $communes = [];
        foreach ($listings as $listing) {
            $communes[(string) $listing->postcode] = $listing->commune;
        }

        self::assertSame('Conflans-Sainte-Honorine', $communes['78700'] ?? null, 'with a quartier');
        self::assertSame('Mormant', $communes['77720'] ?? null, 'without a quartier');
    }

    /**
     * The configured pattern beats the vocabulary scan, and this is the case that shows why.
     *
     * The vocabulary is a substring search over the whole card, so a card in Mormant whose copy
     * says *"proche Dourdan"* returns Dourdan — the prototype's over-matching defect, which
     * `CLAUDE.md` records as *"a Paris listing mentioning 'proche Chatou' passes the commune
     * filter"*. The pattern reads the field the portal laid out, so it is not fooled by prose. The
     * ranked names are deliberately supplied here so the fallback COULD fire and is proven not to.
     */
    public function testTheLaidOutCommuneBeatsATownMerelyMentionedInTheCopy(): void
    {
        $listings = $this->source(self::body([
            self::cardWithoutQuartier('860', 'Appartement proche Dourdan', '3 pièces . 80 m²', 'Mormant', '77720'),
        ]), self::shippedParams())->fetch();

        self::assertSame('Mormant', $listings[0]->commune ?? null);
    }

    /**
     * With no `commune_pattern` configured, the vocabulary scan is exactly what it always was.
     *
     * The blast radius of this change on every other email source is meant to be zero, and an
     * unconfigured key is how that is guaranteed rather than hoped for.
     */
    public function testWithoutTheKeyTheVocabularyScanStillAnswers(): void
    {
        $listings = $this->source(self::body([
            self::cardWithoutQuartier('915', 'Appartement À Louer', '3 pièces . 52,37 m²', 'Dourdan', '91410'),
        ]))->fetch();

        self::assertSame('Dourdan', $listings[0]->commune ?? null, 'found by the ranked vocabulary');
    }

    /**
     * A configured `commune_pattern` that MISSES yields `null` — never the vocabulary scan.
     *
     * Until 2026-08-29 this was the one positional reader that still fell back: `title_pattern`,
     * `surface_pattern` and `rooms_pattern` all yield `null` on a miss, for the reason the PAP plan
     * states — falling back restores the defect AND gives it an alibi. Here the alibi was *"a
     * listing in an unranked town"*: a broken pattern read as a quiet geography rather than as a
     * broken extraction, and the card Mormant-whose-copy-says-Dourdan would have come back as
     * Dourdan, the prototype's documented over-match, with nothing saying the pattern had failed.
     *
     * Blast radius MEASURED before the change, not reasoned about: over the four shipped fixture
     * sets, the 2026-08-28 raw captures and the LIVE mailbox (388 SeLoger cards, 194 Bien'ici,
     * 30 PAP, 3 leboncoin) the fallback branch fired on ZERO cards — every commune came from the
     * pattern or from nowhere — so no stored row changes identity and SeLoger's content key is
     * untouched. The unconfigured counterweight above is what keeps the other sources byte-identical.
     */
    public function testAConfiguredPatternThatMissesYieldsNullNotTheVocabularyScan(): void
    {
        $params = self::shippedParams();
        $params['commune_pattern'] = '~^NEVER-MATCHES-\d{99}$~m';

        $listings = $this->source(self::body([
            self::cardWithoutQuartier('915', 'Appartement À Louer', '3 pièces . 52,37 m²', 'Dourdan', '91410'),
        ]), $params)->fetch();

        self::assertCount(1, $listings, 'the card still yields a listing — the postcode locates it');
        self::assertNull(
            $listings[0]->commune,
            'a configured pattern that misses must read as an extraction failure (null), not fall '
            . 'back to the ranked vocabulary and answer "Dourdan" as though the pattern had worked',
        );
    }

    // ------------------------------------------------------------ identity on the segmented path

    /**
     * A segmented source with no `id_from` keys on the CARD'S OWN LINK.
     *
     * Content-addressing exists because SeLoger sends no listing id — every link is one opaque
     * redirect, so stripping the query collapses sixteen cards onto one identity. That is a defect
     * of one portal, not a property of email alerts: Bien'ici puts a real, stable listing id in the
     * PATH (`/annonce/laforet-immo-facile-22588736`), which `stableId()` keeps.
     *
     * Where a real id exists it is the honest identity, and it avoids both stated costs of the
     * content key: two identical units in one residence no longer share an id, and a card that
     * gains a previously-missing surface no longer changes identity and notifies twice.
     *
     * Before this, `identityFor()` answered `null` unless `id_from` said `content`, so a card on a
     * segmented source could not acquire an identity at all: `cardsIn()` then threw *"the portal's
     * template has changed"*, and link identity was unreachable for any segmented source.
     */
    public function testASegmentedSourceWithoutContentIdentityKeysOnTheCardsOwnLink(): void
    {
        $listings = $this->source(
            self::photoBody([
                self::photoCard('agency-111', '1 170', 'Appartement 3 pièces 65 m²', 'Choisy-le-Roi', '94600'),
                self::photoCard('agency-222', '1 095', 'Appartement 3 pièces 67 m²', 'Plaisir', '78370'),
            ]),
            self::photoParams(),
        )->fetch();

        self::assertCount(2, $listings, 'two cards, two listings — and the header is not a third');

        self::assertSame(
            'https://www.example-portal.test/annonce/agency-111',
            $listings[0]->externalId,
            'the identity is the listing link with its tracking query stripped',
        );
        self::assertSame(
            'https://www.example-portal.test/annonce/agency-222',
            $listings[1]->externalId,
        );

        // The URL keeps its query: what the portal sent is what the human clicks. Only the IDENTITY
        // is canonicalised.
        self::assertStringContainsString('fromSavedSearchId=', (string) $listings[0]->url);
    }

    /**
     * The identity is picked ONCE, and it is picked before the source is ever enabled.
     *
     * Nothing migrates a stored row from one key scheme to another, so flipping content→link on a
     * live source makes every row look new and re-notifies the whole backlog. `id_from: content`
     * therefore still short-circuits — SeLoger's identity must be byte-identical to what it was
     * before link identity existed.
     */
    public function testTheContentIdentityStillWinsWhereItIsConfigured(): void
    {
        $listings = $this->source(self::body([
            self::card('980', 'Appartement Conflans', '3 pièces . 44,71 m²', 'Romagne', 'Conflans-Sainte-Honorine', '78700'),
        ]))->fetch();

        self::assertMatchesRegularExpression(
            '~^[0-9a-f]{40}$~',
            $listings[0]->externalId,
            'a content identity is a sha1 of the dwelling facts, not a URL',
        );
    }

    /**
     * The alert's own HEADER is a segment, and it must not become a phantom listing.
     *
     * It carries the saved search's criteria — `1 200 € max - 3 pièces min - 45 m² min` — so it
     * yields a plausible rent, a room count and a surface, which is everything a card needs. What
     * it does not carry is a link to a listing, and that is structural rather than lucky: a listing
     * link only ever appears inside a card.
     */
    public function testTheAlertsOwnHeaderIsNotAListing(): void
    {
        $listings = $this->source(
            self::photoBody([
                self::photoCard('agency-111', '1 170', 'Appartement 3 pièces 65 m²', 'Choisy-le-Roi', '94600'),
            ]),
            self::photoParams(),
        )->fetch();

        self::assertCount(1, $listings);
        self::assertSame(65.0, $listings[0]->surfaceM2, 'the criteria line says 45 m² min — the card says 65');
        self::assertSame(1170, $listings[0]->rentCc, 'the criteria line says 1 200 € max — the card says 1 170');
    }

    /**
     * The no-information floor applies to the LINK key too, and this is the twin of the content
     * test above rather than a copy of it.
     *
     * The floor's first argument is about identity collapse — every card whose extraction failed
     * hashes to the same content key — and link identity is immune to that, so a floor living only
     * in the content path would stop applying the moment a portal published a real listing id. The
     * argument that does not depend on the key: a segment yielding a rent and nothing else is an
     * EXTRACTION FAILURE, and admitting it hides that failure behind a row quietly rejected for
     * having no location.
     *
     * Found by regression, not by design: moving identity to the link made an existing floor test
     * go red, and the fix was to move the floor rather than to relax it.
     */
    public function testTheInformationFloorAppliesToTheLinkKeyAsWell(): void
    {
        $body = self::photoBody([
            self::photoCard('agency-111', '1 170', 'Appartement 3 pièces 65 m²', 'Choisy-le-Roi', '94600'),
        ]);

        // A second card carrying a rent and a real listing link — and no location, no rooms, no
        // surface. Its link would give it a perfectly good identity; it is still not a card.
        $bare = "\nPhoto\nhttps://www.example-portal.test/annonce/agency-999?fromSavedSearchId=abc\n"
            . "850 €par mois charges comprises\n"
            . "https://www.example-portal.test/annonce/agency-999?fromSavedSearchId=abc\n"
            . "Voir l’annonce\n";

        $listings = $this->source(
            str_replace("\nÀ bientôt.", $bare . "\nÀ bientôt.", $body),
            self::photoParams(),
        )->fetch();

        self::assertCount(1, $listings, 'the card with a link but no locating evidence is refused');
        self::assertSame('https://www.example-portal.test/annonce/agency-111', $listings[0]->externalId);
    }

    /**
     * A CONFIGURED `title_pattern` THAT MISSES YIELDS `''` — never the message subject.
     *
     * This exists because the obvious sabotage does not detect the guarantee. Restore the subject
     * fallback and every fixture suite stays green, because the SeLoger pattern that replaced the
     * vocabulary one extracts a title from all six frozen cards — so the fallback branch is never
     * entered and the safety is dead code nobody would notice was gone [measured 2026-08-26].
     *
     * The failure it guards was live for a month. SeLoger's old pattern missed 27 of 72 real cards
     * and each stored `4 nouvelles annonces : Ile-de-France` as a flat's title, which reads as a
     * value rather than as the extraction failure it is — and which no
     * {@see \Scout\Rent\Config\Criteria::excludedBy()} title-only rule can ever match, so
     * `^\s*chambre\b` and the parking/box/garage family were unreachable on 37.5% of the source.
     */
    public function testAConfiguredTitlePatternThatMissesYieldsNoTitle(): void
    {
        $params = self::shippedParams();
        // Cannot match anything this body contains — the shape of a template SeLoger has not sent
        // yet, which is precisely the case the fallback used to paper over.
        $params['title_pattern'] = '~^\s*(NOTHING-MATCHES-THIS)\s*$~m';

        $listings = $this->source(self::body([
            "\n<L>\n1 100 €/mois charges comprises\n"
                . "<L>\nAppartement 3 pièces\n"
                . "<L>\n3 pièces . 66,57 m² \n"
                . "<L>\n Mairie Rouxel-Sud Est, \n\n Pontault-Combault\n (77340)\n"
                . "<L>\nVoir l'annonce\n",
        ]), $params)->fetch();

        self::assertCount(1, $listings);
        self::assertSame('', $listings[0]->title, 'an unread title is unread, not the subject line');
        self::assertStringNotContainsString(
            'nouvelle',
            $listings[0]->title,
            'the subject must never stand in for a card it does not describe',
        );
    }

    /**
     * A source that configures NO pattern keeps subject semantics, and that asymmetry is deliberate.
     *
     * Where nothing claims to read a title, the subject IS the documented answer rather than a
     * substitute for one — a single-flat alert whose subject names the flat. Without this half the
     * change would silently blank the title of every such source, so it is asserted rather than
     * assumed.
     */
    public function testASourceWithNoTitlePatternStillUsesTheSubject(): void
    {
        $params = self::shippedParams();
        unset($params['title_pattern']);

        $listings = $this->source(self::body([
            "\n<L>\n1 100 €/mois charges comprises\n"
                . "<L>\nAppartement 3 pièces\n"
                . "<L>\n3 pièces . 66,57 m² \n"
                . "<L>\n Mairie Rouxel-Sud Est, \n\n Pontault-Combault\n (77340)\n"
                . "<L>\nVoir l'annonce\n",
        ]), $params)->fetch();

        self::assertCount(1, $listings);
        self::assertNotSame('', $listings[0]->title);
    }

    // ------------------------------------------------------------------ helpers

    /** @param array<string,mixed> $params */
    /**
     * A configured `surface_pattern` that MISSES yields no surface — never the generic scan.
     *
     * NO FIXTURE REACHES THIS BRANCH. With the shipped PAP anchors in place every frozen card
     * extracts, so nothing enters the fallback — and stripping the safety out leaves all six fixture
     * suites green, which is the dead-safety-code trap this repo walked into six days ago on the
     * SeLoger title. This test enters the branch on purpose.
     *
     * Falling back would be worse than no anchor at all: the generic scan is what returns the
     * subscriber's own search FLOOR (45) instead of the flat's surface, so the fallback would
     * restore the defect AND give it an alibi — the row reads as a small flat, not a broken
     * extraction. An extraction failure is not a value (hard rule 9, one layer up).
     */
    public function testAConfiguredSurfacePatternThatMissesYieldsNoSurface(): void
    {
        $params = self::shippedParams();
        $params['surface_pattern'] = '~(NOTHING-MATCHES-THIS)~';

        $listings = $this->source(self::body([
            self::card('1 100', 'Appartement 3 pièces', '3 pièces . 66,57 m²', 'Résidence X', 'Pontault-Combault', '77340'),
        ]), $params)->fetch();

        self::assertCount(1, $listings);
        self::assertNull(
            $listings[0]->surfaceM2,
            'an unread surface is unread — falling back to the first m² in the body is the defect '
            . 'the anchor exists to remove',
        );
    }

    /** The room count, on the same terms and for the same reason. */
    public function testAConfiguredRoomsPatternThatMissesYieldsNoRoomCount(): void
    {
        $params = self::shippedParams();
        $params['rooms_pattern'] = '~(NOTHING-MATCHES-THIS)~';

        $listings = $this->source(self::body([
            self::card('1 100', 'Appartement 3 pièces', '3 pièces . 66,57 m²', 'Résidence X', 'Pontault-Combault', '77340'),
        ]), $params)->fetch();

        self::assertCount(1, $listings);
        self::assertNull($listings[0]->rooms, 'an unread room count is unread, not the first digit in the body');
    }

    /**
     * A source configuring NEITHER keeps the generic scan, bit-for-bit.
     *
     * This is the counterweight, and without it the two tests above are satisfied by deleting the
     * feature outright. seloger, bienici and leboncoin all rely on this path.
     */
    public function testASourceWithNoNumericPatternsStillUsesTheGenericScan(): void
    {
        $params = self::shippedParams();
        unset($params['surface_pattern'], $params['rooms_pattern']);

        $listings = $this->source(self::body([
            self::card('1 100', 'Appartement 3 pièces', '3 pièces . 66,57 m²', 'Résidence X', 'Pontault-Combault', '77340'),
        ]), $params)->fetch();

        self::assertCount(1, $listings);
        self::assertSame(66.57, $listings[0]->surfaceM2);
        self::assertSame(3, $listings[0]->rooms);
    }

    private function source(string $body, ?array $params = null, ?\Closure $warn = null): EmailAlertSource
    {
        $this->dir = sys_get_temp_dir() . '/rentwatch-seg-' . bin2hex(random_bytes(8));
        mkdir($this->dir, 0o700, true);
        file_put_contents($this->dir . '/alert.eml', self::message($body));

        $this->dbPath = sys_get_temp_dir() . '/rentwatch-seg-' . bin2hex(random_bytes(8)) . '.sqlite3';

        $definition = new SourceDefinition(
            name: 'seloger',
            enabled: true,
            family: 'private',
            type: 'email_alert',
            mixedTenure: false,
            defaultTenure: Tenure::LIBRE,
            params: $params ?? [
                'from' => 'example-portal.test',
                'link_host' => 'example-portal.test',
                'card_separator' => "Voir l'annonce",
                'id_from' => 'content',
                'residence_pattern' => '~^\s*([^\n,]{2,60}),\s*$~m',
                'title_pattern' => '~^\s*((?:Appartement|Maison|Studio)[^\n]*)$~m',
            ],
            map: new FieldMap(ref: ['url'], chargesIncluded: true),
        );

        return new EmailAlertSource(
            $definition,
            new FileMailbox($this->dir),
            ['conflans-sainte-honorine' => 'Conflans-Sainte-Honorine', 'dourdan' => 'Dourdan'],
            warn: $warn,
        );
    }

    /**
     * The synthetic params, but with the SHIPPED `commune_pattern` rather than a copy of it.
     *
     * A copy would pass while the real one rotted — the fixture-leakage rule. The rest stays
     * synthetic because the host and the separator are what make this a self-contained test.
     *
     * @return array<string,mixed>
     */
    private static function shippedParams(): array
    {
        $shipped = \Scout\Rent\Config\ConfigLoader::loadSources(
            dirname(__DIR__, 4) . '/config/rent/sources.json',
        )['seloger'];

        return [
            'from' => 'example-portal.test',
            'link_host' => 'example-portal.test',
            'card_separator' => "Voir l'annonce",
            'id_from' => 'content',
            'residence_pattern' => '~^\s*([^\n,]{2,60}),\s*$~m',
            'title_pattern' => '~^\s*((?:Appartement|Maison|Studio)[^\n]*)$~m',
            'commune_pattern' => $shipped->params['commune_pattern'] ?? '',
        ];
    }

    /**
     * A Bien'ici-shaped card: the separator is the line each card STARTS with, not its CTA.
     *
     * The tail-of-segment shape SeLoger uses cannot work here, and the frozen payloads say so
     * rather than the design. Split on the CTA and segment 0 is the alert's header plus card one,
     * so the saved search's own `45 m² min` is read as the flat's surface; every later segment
     * begins with the PRECEDING card's `RÉFÉRENCE : Cocon_Loc_T4`, which the `T4` branch of the room
     * pattern reads as a room count. Measured 2026-08-25 over four real messages: 3 of 13 surfaces
     * and 1 of 13 room counts wrong, all of them in the under-reporting direction, which under
     * `min_surface_m2` rejects a real match and looks like nothing at all.
     */
    private static function photoCard(
        string $id,
        string $rent,
        string $title,
        string $commune,
        string $postcode,
    ): string {
        $link = "https://www.example-portal.test/annonce/{$id}?fromSavedSearchId=abc123";

        return "\nPhoto\n"
            . "https://file.example-portal.test/photo/{$id}.jpg\n"
            . "{$link}\n{$title}\n"
            . "{$link}\n{$postcode} {$commune}\n"
            . "{$link}\n{$rent} €par mois charges comprises\n"
            . "{$link}\nVoir l’annonce\n"
            . "{$link}\nRÉFÉRENCE : Cocon_Loc_T4\n";
    }

    /**
     * The alert's header, carrying the saved search's criteria — a rent, a room count and a surface
     * that belong to no flat.
     *
     * @param list<string> $cards
     */
    private static function photoBody(array $cards): string
    {
        $header = "Bonne nouvelle, 2 nouvelles annonces\ncorrespondent à votre alerte !\n"
            . "https://www.example-portal.test/mon-alerte/abc\n"
            . "Louer région Île-de-France - Maison, appartement - 1 200 € max - 3 pièces min - 45 m² min\n"
            . "+Ajouter ou modifier des critères\n"
            . "https://www.example-portal.test/mes-alertes?timedToken=xyz\n";

        return $header . implode('', $cards) . "\nÀ bientôt.\nL’équipe du portail\n";
    }

    /**
     * Bien'ici's shape: a start-of-card separator, a link host narrowed to the listing PATH, and no
     * `id_from` — so identity falls to the card's own link.
     *
     * @return array<string,mixed>
     */
    private static function photoParams(): array
    {
        return [
            'from' => 'example-portal.test',
            // Narrowed to the listing path on purpose: the alert's own furniture (`/mon-alerte/`,
            // `/mes-alertes`) and the photo CDN all sit on the same domain, and a host-only match
            // would let the header qualify as a card.
            'link_host' => 'example-portal.test/annonce/',
            'card_separator' => "\nPhoto\n",
            'title_pattern' => '~^\h*((?:Appartement|Maison|Studio|Duplex)[^\n]*?)\h*$~m',
            'commune_pattern' => '~^\h*\d{5}\h+([^\n]{2,60}?)\h*$~mu',
        ];
    }

    /** A card whose location block is the commune alone — three of nine live cards, 2026-08-25. */
    private static function cardWithoutQuartier(
        string $rent,
        string $title,
        string $roomsAndSurface,
        string $commune,
        string $postcode,
    ): string {
        return "\n<L>\n{$rent} €/mois charges comprises\n"
            . "<L>\n{$title}\n"
            . "<L>\n{$roomsAndSurface}\n"
            . "<L>\n {$commune}\n ({$postcode})\n"
            . "<L>\nVoir l'annonce\n";
    }

    private static function card(
        string $rent,
        string $title,
        string $roomsAndSurface,
        string $residence,
        string $commune,
        string $postcode,
    ): string {
        return "\n<L>\n{$rent} €/mois charges comprises\n"
            . "<L>\n{$title}\n"
            . "<L>\n{$roomsAndSurface}\n"
            . "<L>\n {$residence}, \n\n {$commune}\n ({$postcode})\n"
            . "<L>\nVoir l'annonce\n";
    }

    /** @param list<string> $cards */
    private static function body(array $cards): string
    {
        $trailer = "\nConformément à la loi Informatique et Libertés, vous pouvez accéder aux données"
            . " vous concernant.\nSeLoger • \n<L>\nSLG-202501-ALI-RELAXED\n";

        return "1 nouvelle annonce : Ile-de-France\n" . implode('', $cards) . $trailer;
    }

    private static function message(string $body): string
    {
        // Links are written as `<L>` in the card helpers and expanded here, so a card reads as the
        // shape a human sees in the mail rather than as a wall of tokens.
        static $n = 0;
        $body = preg_replace_callback(
            '~<L>~',
            static function () use (&$n): string {
                ++$n;

                return 'https://example-portal.test/r/?qs=tok' . $n;
            },
            $body,
        ) ?? $body;

        return "From: \"Portail\" <alertes@example-portal.test>\n"
            . "Subject: 1 nouvelle annonce : Ile-de-France\n"
            . "Content-Type: multipart/alternative; boundary=\"B\"\n"
            . "\n"
            . "This is a multi-part message in MIME format.\n"
            . "\n"
            . "--B\n"
            . "Content-Type: text/plain; charset=\"utf-8\"\n"
            . "\n"
            . $body
            . "\n--B--\n";
    }
}
