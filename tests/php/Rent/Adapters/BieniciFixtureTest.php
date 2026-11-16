<?php

declare(strict_types=1);

namespace Scout\Tests\Rent\Adapters;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Rent\Adapters\EmailAlertSource;
use Scout\Adapters\Mail\FileMailbox;
use Scout\Rent\Config\ConfigLoader;
use Scout\Rent\Core\RawListing;
use Scout\Rent\Core\SourceProfile;
use Scout\Rent\Core\Tenure;
use Scout\Rent\Core\TenureClassifier;
use Scout\Rent\Store\Store;

/**
 * Real Bien'ici alerts, parsed with the shipped `bienici` config.
 *
 * Three messages captured within ninety minutes of creating the alert on 2026-08-25: a five-listing
 * alert, a one-listing alert that also carries a *"Cette annonce peut également vous intéresser"*
 * suggestion, and the subscription confirmation, which carries no cards at all.
 *
 * **This is the second portal on the email-alert route and it is much the easier of the two**, for
 * one reason: it publishes a real listing id in the URL path. SeLoger sends none — sixteen cards
 * behind one opaque `click.by.seloger.com/?qs=…` redirect — which is why content-addressing had to
 * be invented for it. Here `stableId()` strips the query and what is left is the flat.
 *
 * Everything reads the SHIPPED `config/rent/sources.json` block rather than an inline copy, so a change
 * to the field map fails here rather than in production.
 */
#[CoversClass(EmailAlertSource::class)]
final class BieniciFixtureTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../../..';

    private string $dbPath = '';

    protected function tearDown(): void
    {
        if ($this->dbPath !== '') {
            @unlink($this->dbPath);
        }
    }

    /** @return list<RawListing> */
    /**
     * A CARD WITH NO PHOTO MUST STILL START A NEW CARD (2026-08-31, found in production by the
     * developer reading a notification).
     *
     * Every Bien'ici card begins with its photo line, so the separator was the literal `\nPhoto\n`.
     * A listing with NO photo begins `Pas de photo [...]` instead — so the split missed it, that
     * card merged into the one above, and ONE listing came out of the pair: the PREVIOUS card's
     * commune, rent and surface under THIS card's link.
     *
     * Measured on this exact message: three cards announced, **two** listings stored, and the push
     * read `Montigny-le-Bretonneux 78180 · T3 67 m² · 1192 € CC` for a flat that is
     * `93220 Gagny · 49 m² · 855 € CC`. Nothing about it reads as a fault — every field is
     * individually plausible and the link works — which is why it survived until a human compared
     * the notification with the page it pointed at.
     *
     * Asserted PAIRWISE, not as a count: three listings with the facts shuffled between them would
     * satisfy a count assertion perfectly.
     */
    public function testACardWithNoPhotoIsItsOwnListingAndKeepsItsOwnFacts(): void
    {
        $byId = [];

        foreach ($this->listingsFrom('2026-08-31-004-carte-sans-photo.eml') as $listing) {
            $byId[basename((string) $listing->externalId)] = $listing;
        }

        self::assertCount(3, $byId, 'three cards announced, three listings — the photo-less one no longer merges');

        // The card that has no photo, and the one it used to be absorbed into.
        $gagny = $byId['ag752345-547582520'] ?? null;
        $montigny = $byId['netty-sofia-appt-51074'] ?? null;

        self::assertNotNull($gagny, 'the photo-less card exists as its own listing');
        self::assertNotNull($montigny, 'and so does the card above it, which used to swallow it');

        self::assertSame('Gagny', $gagny->commune, 'the notification named Montigny for this flat');
        self::assertSame('93220', $gagny->postcode);
        self::assertSame(49.0, $gagny->surfaceM2, 'and 67 m² — the other card\'s surface');
        self::assertSame(855, $gagny->rentCc, 'and 1 192 € — the other card\'s rent');

        self::assertSame('Montigny-le-Bretonneux', $montigny->commune);
        self::assertSame(67.0, $montigny->surfaceM2);
        self::assertSame(1192, $montigny->rentCc);
    }

    /** Every listing parsed from ONE named fixture, so a per-message assertion is possible. */
    private function listingsFrom(string $file): array
    {
        $dir = sys_get_temp_dir() . '/bienici-one-' . bin2hex(random_bytes(6));
        mkdir($dir);
        copy(self::ROOT . '/tests/fixtures/rent/bienici/' . $file, $dir . '/' . $file);

        try {
            $definition = ConfigLoader::loadSources(self::ROOT . '/config/rent/sources.json')['bienici'];
            $criteria = ConfigLoader::loadCriteria(self::ROOT . '/config/rent/criteria.json');
            $db = sys_get_temp_dir() . '/bienici-one-' . bin2hex(random_bytes(6)) . '.sqlite3';

            try {
                return (new EmailAlertSource(
                    $definition,
                    new FileMailbox($dir),
                    $criteria->communeLabels,
                ))->fetch();
            } finally {
                foreach (glob($db . '*') ?: [] as $f) {
                    @unlink($f);
                }
            }
        } finally {
            @unlink($dir . '/' . $file);
            @rmdir($dir);
        }
    }

    /**
     * A MISS ON A SEGMENT THAT NEVER BECAME A CARD IS NOT A MISS.
     *
     * Found on the pattern-miss signal's FIRST production pass (2026-08-31): `doctor` reported
     * `commune_pattern 117/364 carte(s) sans résultat` for bienici and `residence_pattern 201/399`
     * for seloger — alarming numbers on two sources that were extracting a commune for every
     * listing they returned. The counter was recording every attempt, including the ones made on a
     * message's header, its footer and its unsubscribe block, all of which `cardListing()` then
     * dropped for carrying no rent and no location.
     *
     * That is not cosmetic. The whole point of Track 1h is that a 100 %-miss pass is the signature
     * of a template change, and furniture segments dilute the ratio: on this fixture set four
     * non-cards would sit permanently in the denominator, so a genuine total failure of
     * `commune_pattern` across all ten real cards would have reported 14/14 only by coincidence of
     * every segment failing. Worse in the other direction — an operator reading 117/364 has no way
     * to tell a real 32 % miss from a message shape that simply has furniture in it.
     *
     * Asserted as ZERO rather than as a smaller number: every one of the ten listings this fixture
     * set yields carries a commune, which the sibling assertions in this class already pin
     * individually.
     */
    public function testAMissIsNotCountedOnASegmentThatIsNotACard(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/rentwatch-bi-' . bin2hex(random_bytes(8)) . '.sqlite3';

        $definition = ConfigLoader::loadSources(self::ROOT . '/config/rent/sources.json')['bienici'];
        $criteria = ConfigLoader::loadCriteria(self::ROOT . '/config/rent/criteria.json');

        $source = new EmailAlertSource(
            $definition,
            new FileMailbox(self::ROOT . '/tests/fixtures/rent/bienici'),
            $criteria->communeLabels,
        );

        $listings = $source->fetch();
        $counts = $source->patternMisses()->counts();

        self::assertSame(
            0,
            $counts['commune_pattern']['misses'] ?? -1,
            'every card this fixture set yields states its commune; the misses were message furniture',
        );
        self::assertSame(
            count($listings),
            $counts['commune_pattern']['calls'] ?? -1,
            'the denominator is cards, not segments — otherwise the ratio cannot be read',
        );
        self::assertSame([], $source->patternMisses()->total());
    }

    private function listings(): array
    {
        $this->dbPath = sys_get_temp_dir() . '/rentwatch-bi-' . bin2hex(random_bytes(8)) . '.sqlite3';

        $definition = ConfigLoader::loadSources(self::ROOT . '/config/rent/sources.json')['bienici'];
        $criteria = ConfigLoader::loadCriteria(self::ROOT . '/config/rent/criteria.json');

        $source = new EmailAlertSource(
            $definition,
            new FileMailbox(self::ROOT . '/tests/fixtures/rent/bienici'),
            $criteria->communeLabels,
        );

        return $source->fetch();
    }

    /** @return array<string,RawListing> keyed by commune */
    private function byCommune(): array
    {
        $out = [];

        foreach ($this->listings() as $listing) {
            $out[(string) $listing->commune] = $listing;
        }

        return $out;
    }

    /**
     * Every card in every message, and nothing else.
     *
     * Ten: five from the five-listing alert, two from the one-listing alert — its own match plus the
     * suggestion card below it — and three from the 2026-08-31 capture whose middle card has no
     * photo. The subscription confirmation contributes none and must not throw while doing so: a
     * message with no separator at all is not a broken template.
     *
     * That last three used to be TWO, which is the whole point of the fourth fixture: the photo-less
     * card merged into the one above it and the pair yielded a single listing carrying the wrong
     * flat's commune, rent and surface.
     */
    public function testEveryCardBecomesAListingAndNothingElseDoes(): void
    {
        self::assertCount(10, $this->listings());
    }

    /**
     * THE SEPARATOR CHOICE, pinned by the four values that were wrong without it.
     *
     * Splitting on the call to action — which is what SeLoger does — puts the alert's own criteria
     * line inside segment 0: `Louer région Île-de-France - Maison, appartement - 1 200 € max -
     * 3 pièces min - 45 m² min`. `surfaceIn()` takes the first match in the segment, so the first
     * card of every alert reports **45 m²**. Under `min_surface_m2: 50` that is a silent rejection
     * of a real match, which is this project's signature failure: nothing arrives, and nothing says
     * why.
     *
     * Splitting on the line each card STARTS with makes every segment exactly one card and leaves
     * the header a segment of its own.
     */
    public function testASurfaceIsTheFlatsAndNotTheSavedSearchsMinimum(): void
    {
        $byCommune = $this->byCommune();

        // Both of the committed alerts' first cards, which are exactly the cards the CTA separator
        // got wrong: one per message, because segment 0 is the only one carrying the header.
        self::assertSame(65.0, $byCommune['Choisy-le-Roi']->surfaceM2, 'the title says 65 m²');
        self::assertSame(62.0, $byCommune['Limay']->surfaceM2, 'the title says 62 m²');

        foreach ($this->listings() as $listing) {
            self::assertNotSame(
                45.0,
                $listing->surfaceM2,
                '45 is the saved search\'s `45 m² min`, not any flat\'s surface',
            );
        }
    }

    /**
     * The second contamination, and a stranger one: a card reading its NEIGHBOUR's reference.
     *
     * Under the CTA separator every segment after the first begins with the preceding card's
     * `RÉFÉRENCE : Cocon_Loc_T4`, and the `T4` branch of the room pattern reads that as four rooms.
     * The Épône flat is a 3-pièces whose predecessor happens to be named `Cocon_Loc_T4`.
     */
    public function testARoomCountIsTheFlatsAndNotTheNeighboursReferenceString(): void
    {
        self::assertSame(3, $this->byCommune()['Épône']->rooms, 'the title says 3 pièces');
    }

    /**
     * Identity is the listing URL with the tracking query stripped — a real id, not a hash.
     *
     * The URL itself KEEPS its query: what the portal sent is what the human clicks, and following
     * the link to canonicalise it would be a third-party request per listing on a token tied to the
     * subscriber. Only the identity is canonicalised.
     */
    public function testIdentityIsTheListingsOwnUrl(): void
    {
        foreach ($this->listings() as $listing) {
            self::assertStringStartsWith('https://www.bienici.com/annonce/', $listing->externalId);
            self::assertStringNotContainsString('?', $listing->externalId, 'the query is not identity');
            self::assertNotSame($listing->externalId, (string) $listing->url, 'the url keeps its query');
        }

        $ids = array_map(static fn (RawListing $l): string => $l->externalId, $this->listings());
        self::assertCount(count($ids), array_unique($ids), 'no two cards share a flat');
    }

    /**
     * The push must open the FLAT, not the saved search.
     *
     * Reported by the developer against SeLoger on 2026-08-25: clicking a notification opened the
     * alert they had created. The fix was to take a card's LAST qualifying link rather than its
     * first; this asserts the outcome per source, because a config change is what would undo it —
     * `link_host` widened from the listing path to the bare domain would let `/mon-alerte/` and
     * `/mes-alertes` qualify again.
     */
    public function testTheNotificationLinksToTheFlatAndNotToTheSavedSearch(): void
    {
        foreach ($this->listings() as $listing) {
            $url = (string) $listing->url;

            self::assertStringContainsString('/annonce/', $url);
            self::assertStringNotContainsString('/mon-alerte/', $url);
            self::assertStringNotContainsString('/mes-alertes', $url);
            self::assertStringNotContainsString('desinscription', $url);
        }
    }

    /**
     * The alert's own header carries a rent, a room count and a surface belonging to no flat.
     *
     * `1 200 € max - 3 pièces min - 45 m² min` is everything a card needs except a listing link,
     * and that absence is what drops it. Structural rather than lucky: a listing link only ever
     * appears inside a card.
     */
    public function testNoListingCarriesTheSavedSearchsCeiling(): void
    {
        foreach ($this->listings() as $listing) {
            self::assertNotSame(1200, $listing->rentCc, '1 200 is the ceiling, not a rent');
        }
    }

    /**
     * The rent is charges comprises, and the config says so.
     *
     * Bien'ici states `1 170 €par mois charges comprises` — no space before `par`, which the
     * periodic-rent pattern tolerates. `charges_included: true` is what puts it in `rentCc`, and
     * only a `rentCc` can be checked against `max_rent_cc`.
     */
    public function testTheRentIsChargesComprises(): void
    {
        $listing = $this->byCommune()['Choisy-le-Roi'];

        self::assertSame(1170, $listing->rentCc);
        self::assertNull($listing->rentHc, 'a cc figure must not also be recorded as hc');
    }

    /** The commune is read from the portal's layout — postcode first, then the name. */
    public function testTheCardNamesItsCommune(): void
    {
        $byCommune = $this->byCommune();

        self::assertArrayHasKey('Choisy-le-Roi', $byCommune);
        self::assertArrayHasKey('Ballancourt-sur-Essonne', $byCommune, 'a hyphenated name survives whole');
        self::assertSame('94600', $byCommune['Choisy-le-Roi']->postcode);

        foreach ($this->listings() as $listing) {
            self::assertNotNull(
                $listing->commune,
                'a null commune costs the S1 score, the notification text and a strong dedup key '
                    . 'while still matching on the postcode — nothing about it looks like a fault',
            );
        }
    }

    /**
     * A suggestion card is ingested as an ordinary listing, and that is deliberate.
     *
     * *"Cette annonce peut également vous intéresser"* is a real listing the portal thinks is
     * relevant; it is simply not one the saved search matched. Over-inclusion costs a row that the
     * criteria then reject — the Ballancourt suggestion is 44 m², under the surface floor.
     * Under-inclusion costs a flat.
     */
    public function testASuggestionCardIsIngestedLikeAnyOther(): void
    {
        $listing = $this->byCommune()['Ballancourt-sur-Essonne'];

        self::assertSame(44.0, $listing->surfaceM2);
        self::assertSame(725, $listing->rentCc);
    }

    /**
     * §1 holds on a source whose cards state no tenure at all.
     *
     * `mixed_tenure: false` means an unlabelled card takes the `LIBRE` default — which is every
     * card here, and the documented residual. What must hold regardless is the tier-2 label rule,
     * which never consults the flag: an explicit financing label anywhere in a real card is caught
     * at high confidence and refused. Asserted against a REAL card with a label injected, rather
     * than against a synthetic string, because the surrounding furniture is what has broken this
     * three times (`au plus près`, `bailleur social`, `En savoir plus`).
     */
    public function testAnExplicitSocialLabelOnARealCardIsStillRefused(): void
    {
        $listing = $this->byCommune()['Choisy-le-Roi'];
        $classifier = new TenureClassifier();
        $profile = new SourceProfile('bienici', 'private', Tenure::LIBRE, false);

        self::assertNotSame(
            Tenure::SOCIAL,
            $classifier->classify($listing, $profile)->tenure,
            'an ordinary card is not social',
        );

        foreach (['PLS', 'PLUS', 'PLAI', 'logement conventionné'] as $label) {
            $labelled = new RawListing(
                sourceName: $listing->sourceName,
                externalId: $listing->externalId,
                title: $listing->title,
                description: ($listing->description ?? '')
                    . "\nLe logement est soumis au plafond de ressources " . $label . '.',
                fields: $listing->fields,
                url: $listing->url,
                commune: $listing->commune,
                postcode: $listing->postcode,
                rentCc: $listing->rentCc,
                surfaceM2: $listing->surfaceM2,
                rooms: $listing->rooms,
            );

            self::assertNotSame(
                Tenure::LIBRE,
                $classifier->classify($labelled, $profile)->tenure,
                $label . ' must not be read as private-market stock just because the source default is',
            );
        }
    }

    /**
     * A message with no cards is not a broken template.
     *
     * The subscription confirmation carries no separator, so it yields nothing — and `cardsIn()`'s
     * *"the portal's template has changed"* guard must not fire on it. That guard exists because a
     * message that plainly contains cards and yields none is a broken parser; a message that
     * contains no cards is just a message.
     */
    public function testAMessageWithNoCardsYieldsNothingAndDoesNotThrow(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/rentwatch-bi-' . bin2hex(random_bytes(8)) . '.sqlite3';

        $dir = sys_get_temp_dir() . '/rentwatch-bi-conf-' . bin2hex(random_bytes(8));
        mkdir($dir, 0o700, true);

        try {
            copy(
                self::ROOT . '/tests/fixtures/rent/bienici/2026-08-25-003-subscription-confirmed.eml',
                $dir . '/only.eml',
            );

            $source = new EmailAlertSource(
                ConfigLoader::loadSources(self::ROOT . '/config/rent/sources.json')['bienici'],
                new FileMailbox($dir),
                [],
            );

            self::assertSame([], $source->fetch());
        } finally {
            @unlink($dir . '/only.eml');
            @rmdir($dir);
        }
    }
}
