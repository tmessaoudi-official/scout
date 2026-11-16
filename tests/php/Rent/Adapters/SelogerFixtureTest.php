<?php

declare(strict_types=1);

namespace Scout\Tests\Rent\Adapters;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Rent\Adapters\EmailAlertSource;
use Scout\Adapters\Mail\FileMailbox;
use Scout\Rent\Config\ConfigLoader;
use Scout\Rent\Core\Outcome;
use Scout\Rent\Core\RawListing;
use Scout\Rent\Core\Tenure;
use Scout\Rent\Core\TenureClassifier;
use Scout\Rent\Store\Store;

/**
 * The first REAL portal alerts, parsed with the shipped `seloger` config.
 *
 * `spec/PROJECT_BRIEF.md` §11 asks for one frozen payload per source, offline, no network. These
 * two were captured from a live subscription on 2026-08-25 and scrubbed by `tools/scrub-eml.php`,
 * which refuses to write while the address, the ESP list ids or any original tracking token
 * survives.
 *
 * **The structure is deliberately NOT tidied.** The awkward `=_?:` MIME boundary, the 8bit UTF-8
 * transfer encoding, the folded headers and the RFC 2047 subject split mid-word are each a parser
 * defect this project has already had — two of them found by these very files. Rewriting the
 * messages into something well-behaved would delete the evidence and leave the fixture proving
 * only that a tidy message parses.
 *
 * Everything here reads the SHIPPED `config/rent/sources.json` block rather than an inline copy, so a
 * change to the field map fails here rather than in production.
 */
#[CoversClass(EmailAlertSource::class)]
final class SelogerFixtureTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../../..';

    private string $dbPath = '';

    protected function tearDown(): void
    {
        if ($this->dbPath !== '') {
            @unlink($this->dbPath);
        }
    }

    /** @return array<string,\Scout\Rent\Core\RawListing> keyed by commune */
    private function listings(): array
    {
        $this->dbPath = sys_get_temp_dir() . '/rentwatch-slg-' . bin2hex(random_bytes(8)) . '.sqlite3';

        $definition = ConfigLoader::loadSources(self::ROOT . '/config/rent/sources.json')['seloger'];
        $criteria = ConfigLoader::loadCriteria(self::ROOT . '/config/rent/criteria.json');

        $source = new EmailAlertSource(
            $definition,
            new FileMailbox(self::ROOT . '/tests/fixtures/rent/seloger'),
            $criteria->communeLabels,
        );

        $out = [];
        foreach ($source->fetch() as $listing) {
            $out[(string) $listing->postcode] = $listing;
        }

        return $out;
    }

    /**
     * Five messages, eight cards, eight listings — and NOT one listing per link.
     *
     * The first two fixtures carry 16 and 19 links respectively, every one of them a
     * `click.by.seloger.com` redirect. Under link-identity that is 35 listings sharing a single id
     * and a single flat's facts. This assertion is the one that would go red if `card_separator`
     * were dropped from the config.
     *
     * 6 → 8 on 2026-08-31, when the `Baisse de prix` template was frozen for the first time (004,
     * 005). It is a SECOND template from the same sender and it had never been captured, which is
     * how F23 went a month unnoticed: a price-drop alert leads its card with the agency's own text
     * starting `600€ TOUT COMPRIS…`, and `title_pattern` refuses any candidate containing `€`.
     * Neither of these two captures reproduces that — both extract a title — so they pin the
     * template's ordinary shape, and the `€`-leading variant is covered by
     * {@see PriceLedTitleTest}, built on the layout measured out of the store.
     */
    public function testEachMessageYieldsOneListingPerCardAndNotPerLink(): void
    {
        self::assertCount(8, $this->listings(), 'five alerts, eight flats');
    }

    /**
     * The alert's own card, read field by field.
     *
     * `Appartement … 3 pièce(s) 45…` is the title as SeLoger truncates it; the surface comes from
     * the card's own `3 pièces . 44,71 m²` line, NOT from the `45` in the truncated title.
     */
    public function testTheAlertCardIsReadCorrectly(): void
    {
        $listing = $this->listings()['78700'] ?? null;

        self::assertNotNull($listing, 'the Conflans-Sainte-Honorine card');
        self::assertSame(980, $listing->rentCc, 'charges comprises, from the card not the message');
        self::assertNull($listing->rentHc);
        self::assertSame(44.71, $listing->surfaceM2, 'the card line, not the 45 in the truncated title');
        self::assertSame(3, $listing->rooms);
        self::assertStringStartsWith('Appartement', (string) $listing->title);
    }

    /**
     * The link in a notification is the CARD'S OWN, and it is the LAST one in the card.
     *
     * Reported by the developer 2026-08-25: clicking a SeLoger notification opened *the saved search
     * they had created*, not the flat. The reader took the FIRST qualifying link in the segment, and
     * on a real message that is never the listing — measured across a live five-card alert, the
     * first link is *"mettre en pause les envois"* (alert management) on card one, a third-party
     * advert (*"Estimez le prix de votre déménagement"*) on card two, and the photo on card three.
     * One of the three happened to reach the flat.
     *
     * The last one always does, and structurally rather than by luck: `card_separator` IS the
     * `Voir l'annonce` call to action, and in this rendering a URL precedes its own anchor text — so
     * the final link of a segment is the CTA's, whatever the portal puts above it. Confirmed against
     * the message's HTML part, which names each anchor: price, title, details, location and
     * `Voir l'annonce` all point at the listing, and the header does not. No redirect was followed
     * to establish this — the tokens are per-subscriber and following one manufactures a click
     * nobody made.
     *
     * The tokens are the scrubber's sequential `FIXTURE###`, so their ORDER is preserved and the
     * assertion is about position, which is exactly what regressed.
     */
    public function testTheNotificationLinksToTheFlatAndNotToTheSavedSearch(): void
    {
        $listings = $this->listings();

        self::assertStringEndsWith('FIXTURE007', (string) $listings['78700']?->url, 'the Voir l\'annonce CTA');
        self::assertStringEndsWith('FIXTURE010', (string) $listings['91410']?->url);

        foreach ($listings as $listing) {
            self::assertStringNotContainsString(
                'FIXTURE001',
                (string) $listing->url,
                'the first link in a card is the portal\'s own furniture, never the flat',
            );
        }
    }

    /**
     * The card names its town, and the two frozen fixtures are the two shapes the template has.
     *
     * SeLoger prints the location as a *quartier* line, then the commune on its own line, then the
     * postcode alone in parentheses — and the quartier line is OPTIONAL. Both fixtures carry it;
     * three of the nine cards measured live on 2026-08-25 do not (`Mormant`, `Garches`,
     * `Moret-Loing-et-Orvanne` sit directly above their postcode), which is why the pattern anchors
     * on the parenthesised postcode BELOW the name rather than on the comma above it.
     *
     * Without this the commune was `null` on every SeLoger listing while the postcode parsed fine:
     * the notification could not say where the flat was, `Dedup` got a weaker key, and the S1
     * commune score could not fire — none of which looks like a fault from the outside.
     */
    public function testTheCardNamesItsCommune(): void
    {
        $listings = $this->listings();

        self::assertSame('Conflans-Sainte-Honorine', $listings['78700']?->commune);
        self::assertSame('Dourdan', $listings['91410']?->commune);
    }

    /** The exclusivity's card. Same selectors, different template framing — one format, not two. */
    public function testTheExclusivityCardIsReadCorrectly(): void
    {
        $listing = $this->listings()['91410'] ?? null;

        self::assertNotNull($listing, 'the Dourdan card');
        self::assertSame(915, $listing->rentCc);
        self::assertSame(52.37, $listing->surfaceM2);
        self::assertSame(3, $listing->rooms);
    }

    /**
     * No two flats share an identity, which is the whole reason `id_from: content` exists.
     *
     * Under the link-identity path all six would be `https://click.by.seloger.com/` — five flats
     * silently swallowed as already seen, for ever.
     */
    public function testEveryFlatHasADistinctIdentity(): void
    {
        $ids = array_map(static fn ($l) => $l->externalId, $this->listings());

        self::assertCount(8, array_unique($ids));
        foreach ($ids as $id) {
            self::assertMatchesRegularExpression('~^[0-9a-f]{40}$~', $id, 'content-addressed, not a URL');
        }
    }

    /**
     * The tracking link is carried UNRESOLVED, and it is carried.
     *
     * Both halves matter. Dropping it would leave a notification with nothing to click; resolving
     * it would be a request per listing on a subscriber-bound token (hard rule 5).
     */
    public function testTheTrackingLinkIsCarriedButNotResolved(): void
    {
        foreach ($this->listings() as $listing) {
            self::assertNotNull($listing->url, 'a notification needs something to click');
            self::assertStringContainsString('click.by.seloger.com', (string) $listing->url);
        }
    }

    /**
     * A card's description is its own card, not the message and not the trailer.
     *
     * The Cityloger ruling on a new surface. Every alert ends in a CNIL notice, a postal address
     * and a campaign code, and those are furniture by construction — the same class of text that
     * sent 14 of 16 correctly-badged CDC listings to the digest.
     */
    public function testTheDescriptionExcludesTheTrailer(): void
    {
        foreach ($this->listings() as $listing) {
            self::assertStringNotContainsString('Conformément à la loi', $listing->description);
            self::assertStringNotContainsString('rue des Italiens', $listing->description);
        }
    }

    /**
     * **The classifier's VERDICT on each real card, which is the guarantee that matters.**
     *
     * A first version of this test asserted that the word `plus` never appears in a card's text.
     * That is unachievable and wrong in kind: `plus` is one of the commonest words in French, and
     * SeLoger's own card carries the CTA `En savoir plus →`. Asserting the absence of a word is
     * asserting something about French; asserting the classifier's output is asserting the thing
     * §1 actually forbids.
     *
     * This is the FOURTH recorded instance of that vocabulary class — after CDC's `au plus près`
     * tooltip, Cityloger's `bailleur social`, and Logirep's `Plain-pied` filter facet. Each cost a
     * fix; this one cost only a test, because the classifier's `_text`-as-prose handling already
     * covers it. Which is precisely what the assertion below proves, on the real payload.
     */
    public function testTheClassifierReadsEveryRealCardAsEligible(): void
    {
        $classifier = new TenureClassifier();
        $definition = ConfigLoader::loadSources(self::ROOT . '/config/rent/sources.json')['seloger'];

        foreach ($this->listings() as $postcode => $listing) {
            $verdict = $classifier->classify($listing, $definition->profile());

            self::assertNotSame(
                Outcome::REJECT,
                $verdict->outcome,
                "card {$postcode} was rejected on tenure — SeLoger is a private-market portal, so "
                    . 'this means UI furniture is being read as a financing regime',
            );

            self::assertNotContains(
                $verdict->tenure,
                [Tenure::SOCIAL],
                "card {$postcode} classified as social housing from a private-market alert",
            );
        }
    }

    /**
     * An explicit excluded LABEL in a card is still refused, so the test above cannot pass by the
     * classifier having simply stopped looking at this source.
     *
     * The counterweight matters because `mixed_tenure: false` plus `default_tenure: LIBRE` is a
     * configuration that could plausibly short-circuit tenure reasoning altogether. It does not,
     * and this is what says so.
     */
    public function testAnExplicitExcludedLabelInACardIsStillRefused(): void
    {
        $listing = $this->listings()['78700'] ?? null;
        self::assertNotNull($listing);

        $poisoned = new \Scout\Rent\Core\RawListing(
            sourceName: $listing->sourceName,
            externalId: $listing->externalId,
            title: $listing->title,
            description: $listing->description . "\nLogement conventionné PLUS, demande de logement social.",
            fields: $listing->fields,
            url: $listing->url,
            commune: $listing->commune,
            postcode: $listing->postcode,
            rentCc: $listing->rentCc,
            surfaceM2: $listing->surfaceM2,
            rooms: $listing->rooms,
        );

        $definition = ConfigLoader::loadSources(self::ROOT . '/config/rent/sources.json')['seloger'];
        $verdict = (new TenureClassifier())->classify($poisoned, $definition->profile());

        self::assertSame(Outcome::REJECT, $verdict->outcome, 'an explicit PLUS label must still veto');
    }

    /**
     * The fixtures carry no personal data. Asserted here as well as in the scrubber, because the
     * scrubber runs once by hand and this runs on every push.
     */
    public function testTheFixturesCarryNoSubscriberIdentity(): void
    {
        foreach (glob(self::ROOT . '/tests/fixtures/rent/seloger/*.eml') ?: [] as $file) {
            $raw = (string) file_get_contents($file);
            $name = basename($file);

            self::assertStringNotContainsStringIgnoringCase('takieddine', $raw, "{$name} leaks the address");
            self::assertStringNotContainsStringIgnoringCase('gmail', $raw, "{$name} leaks the mailbox host");
            self::assertDoesNotMatchRegularExpression('~51000[1-9]~', $raw, "{$name} leaks an ESP list id");

            preg_match_all('~qs=([A-Za-z0-9_\-]+)~', $raw, $tokens);
            foreach ($tokens[1] as $token) {
                self::assertStringStartsWith('FIXTURE', $token, "{$name} leaks a live tracking token");
            }
        }
    }

    /**
     * The structure that made these fixtures worth keeping is still in them.
     *
     * A future "tidy up the fixtures" would silently retire the two regression tests they carry:
     * the MIME preamble that made every real alert parse to nothing, and the RFC 2047 subject split
     * mid-word that decoded to `exclusivit és`.
     */
    public function testTheAwkwardStructureIsPreserved(): void
    {
        $alert = (string) file_get_contents(self::ROOT . '/tests/fixtures/rent/seloger/2026-08-25-001-alert.eml');
        $exclusive = (string) file_get_contents(self::ROOT . '/tests/fixtures/rent/seloger/2026-08-25-002-exclusive.eml');

        self::assertStringContainsString('This is a multi-part message in MIME format.', $alert, 'the preamble');
        self::assertStringContainsString('boundary="8PaVqvzMwU9R=_?:"', $alert, 'the awkward boundary');
        self::assertStringContainsString("exclusivit?=\r\n =?UTF-8?Q?", $exclusive, 'the mid-word 2047 split');
    }

    /**
     * A TITLE IS A POSITION, NEVER A VOCABULARY — and reading it as a vocabulary was silent.
     *
     * `title_pattern` used to require the line to begin with `Appartement|Maison|Studio|Duplex|
     * Loft|Chambre`, which is a guess at what an ESTATE AGENT types. Measured against 72 live cards
     * on 2026-08-26 it missed **27 of them (37.5%)**, and every miss fell back to
     * {@see \Scout\Adapters\Mail\EmailMessage::subject()} — so the stored title of a real flat
     * read `4 nouvelles annonces : Ile-de-France`.
     *
     * That is not a cosmetic defect. `Criteria::excludeTitlePatterns` is matched against the TITLE
     * ONLY, deliberately (`config/rent/criteria.json` records why: `3 chambres` in a description is the
     * family flat the criteria are looking for). A subject line matches none of those patterns and
     * never can, so on 37.5% of this source's cards **every title-only exclusion was inert** —
     * `chambre`, `colocation`, `meublé`, `parking`, `box`, `garage`, `cave`, `bureau`. Card 1 below
     * is what that cost: a COLOCATION notified as a 6-room 105 m² family flat.
     *
     * The replacement is structural, and it is the same correction `commune_pattern` already took:
     * anchor on the layout the portal emits rather than on the words someone chose. SeLoger writes
     * `<rent> €/mois`, then the agency's own free-text title, then `<n> pièces . <s> m²`; the title
     * is the line above the `pièces` line. Ground truth below is hand-read off the rendered message.
     */
    public function testATitleIsReadFromItsPositionAndNotFromAVocabulary(): void
    {
        $byPostcode = $this->listings();

        // Not one of these four starts with a French dwelling noun. `APARTMENT` is English, `T5`
        // and `T3` are two characters — which is why the capture floor is 2 and not 3 — and card 1
        // begins with the very word that must exclude it.
        $expected = [
            '93380' => 'Colocation Saint-Denis - Remis à Neuf - Proche Mét...',
            '77000' => 'T5',
            '95220' => 'APARTMENT',
            '95100' => 'T3',
        ];

        foreach ($expected as $postcode => $title) {
            self::assertArrayHasKey($postcode, $byPostcode, (string) $postcode);
            self::assertSame(
                $title,
                $byPostcode[$postcode]->title,
                $postcode . ': the card\'s own title, not the alert\'s subject line',
            );
        }
    }

    /**
     * The subject line never becomes a listing's title, on any card.
     *
     * The pattern above fixes the 27 cards that were measured; THIS is what stops the class of
     * defect returning the next time SeLoger reshapes a template. A configured `title_pattern` that
     * misses now yields an EMPTY title rather than the subject — an extraction failure that looks
     * like an extraction failure, instead of one wearing a plausible French sentence as an alibi.
     *
     * An empty title excludes nothing either, so this buys no filtering on its own. What it buys is
     * that the failure is VISIBLE — in the notification, in `scout dump`, in the stored snapshot —
     * rather than indistinguishable from a flat an agency happened to name after the alert.
     */
    public function testAnUnreadableTitleIsNeverTheSubjectLine(): void
    {
        foreach ($this->listings() as $postcode => $listing) {
            self::assertStringNotContainsString('nouvelle', $listing->title, (string) $postcode);
            self::assertStringNotContainsString('Ile-de-France', $listing->title, (string) $postcode);
        }
    }

    /**
     * THE TITLE-ONLY RULE SEES THE CARD'S TITLE — and the same card's subject line defeats it.
     *
     * A first version of this test asserted the Saint-Denis COLOCATION was rejected, and it passed
     * before the fix as well as after: `\bcolocation\b` lives in `exclude_patterns`, which is
     * matched against title AND description, and the description is the whole card. The test proved
     * nothing and its premise was wrong — recorded here rather than quietly corrected, because
     * *a true observation attached to the wrong mechanism* is this repo's named failure and it
     * arrived while writing the fix for an instance of it.
     *
     * What the subject fallback actually disabled is {@see \Scout\Rent\Config\Criteria} 's
     * TITLE-ONLY set: `^\s*chambre\b` and the parking/box/garage/cave/bureau family. Those are
     * title-scoped deliberately — `3 chambres` in a description is the family flat the criteria are
     * looking for — so they have no second surface to fall back on, and a card wearing
     * `4 nouvelles annonces : Ile-de-France` is unreachable by every one of them.
     *
     * This asserts that difference directly, on a REAL card: same description, two titles, two
     * verdicts. It is the sabotage shape for the whole change — revert the pattern and the left-hand
     * column becomes the right-hand one.
     */
    public function testATitleOnlyExclusionIsReachableOnlyWhenTheTitleIsTheCardsOwn(): void
    {
        $criteria = ConfigLoader::loadCriteria(self::ROOT . '/config/rent/criteria.json');
        $card = $this->listings()['95220'];

        // The card's own description is held constant; only the title varies. `Chambre en
        // colocation` is not invented — it is verbatim what a live SeLoger card carried on
        // 2026-08-26, and the shape the `^\s*chambre\b` exclusion was added for.
        $withCardTitle = new RawListing(
            sourceName: $card->sourceName,
            externalId: $card->externalId,
            title: 'Chambre en colocation maison meublée',
            description: $card->description,
            commune: $card->commune,
            postcode: $card->postcode,
            rentCc: $card->rentCc,
            surfaceM2: $card->surfaceM2,
            rooms: $card->rooms,
        );

        $withSubject = new RawListing(
            sourceName: $card->sourceName,
            externalId: $card->externalId,
            title: '4 nouvelles annonces : Ile-de-France',
            description: $card->description,
            commune: $card->commune,
            postcode: $card->postcode,
            rentCc: $card->rentCc,
            surfaceM2: $card->surfaceM2,
            rooms: $card->rooms,
        );

        self::assertSame(
            '^(?!.*(?:\b\d+|\b(?:une|deux|trois|quatre|cinq|six))\s+(?:\w{1,14}\s+)?chambres?\b).*\bchambres?\b(?!\s+(?:de\s+service|d\'amis|parentale|d\'enfants?))',
            $criteria->excludedBy($withCardTitle->title, $withCardTitle->description),
            'the card\'s own title reaches the title-only rule',
        );

        self::assertNotSame(
            '^(?!.*(?:\b\d+|\b(?:une|deux|trois|quatre|cinq|six))\s+(?:\w{1,14}\s+)?chambres?\b).*\bchambres?\b(?!\s+(?:de\s+service|d\'amis|parentale|d\'enfants?))',
            $criteria->excludedBy($withSubject->title, $withSubject->description),
            'and the subject line does not — which is what the fallback was costing on 27 of 72 '
                . 'live cards measured 2026-08-26',
        );
    }

}
