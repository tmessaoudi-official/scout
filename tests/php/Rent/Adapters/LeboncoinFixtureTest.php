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
 * leboncoin — the first HTML-ONLY portal, against its first real alert.
 *
 * It needed a parser change rather than config alone, and the failure it would have produced is the
 * one this project is shaped around. leboncoin sends no `text/plain` alternative, so every URL lived
 * in an `href` that `strip_tags()` removed: the parser produced a perfect 15 975-character body
 * carrying all three listings and **zero links**, and a source with no links yields no listings and
 * reports a quiet market for ever. `EmailMessage::harvestHrefs()` is the fix.
 *
 * **n=1, and it is stated rather than hidden.** One message, three cards, captured 2026-08-26 — the
 * first this subscription has ever produced. The separator and `commune_pattern` are measured on
 * that single message, and this repo has twice paid for generalising from one capture (the In'li
 * lift claim, the project-wide "0 matches" claim). The second alert to arrive is the first
 * regression test.
 *
 * Everything here reads the SHIPPED `config/rent/sources.json`, so a config edit that breaks extraction
 * fails here rather than in production.
 */
#[CoversClass(EmailAlertSource::class)]
final class LeboncoinFixtureTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../../..';

    private ?string $dbPath = null;

    protected function tearDown(): void
    {
        if ($this->dbPath !== null && is_file($this->dbPath)) {
            unlink($this->dbPath);
        }
        $this->dbPath = null;
    }

    public function testTheAlertYieldsExactlyItsThreeCards(): void
    {
        // The subject says "3 nouveaux biens" and the message carries three. Before the separator
        // was measured it yielded ONE listing that mixed all three: card 3's URL with card 1's rent,
        // commune and surface. Nothing about that looks wrong from outside — one plausible listing.
        self::assertCount(3, $this->listings());
    }

    public function testEveryCardCarriesItsOwnFactsAndNotItsNeighboursd(): void
    {
        $byCommune = $this->byCommune();

        self::assertSame(
            ['Ozoir-la-Ferrière', 'Conflans-Sainte-Honorine', 'Combs-la-Ville'],
            array_keys($byCommune),
            'three distinct communes, read from the portal\'s own layout',
        );

        // GROUND TRUTH, read off the rendered message by hand. Each triple belongs to ONE card, and
        // the whole point of the separator is that no value crosses from one card to another.
        $expected = [
            'Ozoir-la-Ferrière' => ['77330', 1042, 48.0, 3, '3256902167'],
            'Conflans-Sainte-Honorine' => ['78700', 980, 45.0, 3, '3256901211'],
            'Combs-la-Ville' => ['77380', 935, 59.9, 3, '3256893138'],
        ];

        foreach ($expected as $commune => [$postcode, $rent, $surface, $rooms, $adId]) {
            $listing = $byCommune[$commune];

            self::assertSame($postcode, $listing->postcode, $commune);
            self::assertSame($rooms, $listing->rooms, $commune);
            self::assertSame($surface, $listing->surfaceM2, $commune);
            self::assertStringContainsString($adId, (string) $listing->externalId, $commune);

            // THE RENT IS `hors charges`, and that is a decision rather than an oversight. The
            // message mentions charges NOWHERE — measured, zero occurrences of `charges`, `CC` or
            // `HC` in the whole payload — so the Logirep precedent applies: the figure lands in
            // rentHc, `max_rent_cc` never fires on it, and the score line says so.
            self::assertSame($rent, $listing->rentHc, $commune);
            self::assertNull($listing->rentCc, $commune . ': an unstated CC must not be invented');
        }
    }

    public function testEachCardTakesItsOwnTitleAndNotTheEmailSubject(): void
    {
        // WITHOUT `title_pattern` every listing's title was the SUBJECT LINE — "3 nouveaux biens à
        // louer à Ile-de-France" for all three cards — which makes `exclude_title_patterns`
        // structurally dead on this source. That is the In'li lesson verbatim, and it lands here on
        // the portal with the largest coliving market in France, where SeLoger's anchored
        // `^\s*chambre\b` exclusion exists because four of its first nine matches were coliving
        // ROOMS advertised with the whole flat's room count and surface — passing every numeric
        // filter, and rejectable only on the title.
        //
        // The pattern anchors on the portal's own TYPE LINE rather than a list of dwelling types, so
        // a `Chambre · 1 pièce · 12 m²` matches it too and the exclusion can fire.
        $titles = array_map(
            static fn (RawListing $l): string => (string) $l->title,
            $this->listings(),
        );

        self::assertSame([
            'Appartement · 3 pièces · 48 m²',
            'Appartement · 3 pièces · 45 m²',
            'Appartement · 3 pièces · 59.9 m²',
        ], $titles);

        foreach ($titles as $title) {
            self::assertStringNotContainsString('nouveaux biens', $title, 'the subject line is not a title');
        }
    }

    public function testIdentityIsTheListingUrlStrippedOfItsTracking(): void
    {
        // leboncoin publishes a REAL ad id, so identity is the link and none of SeLoger's
        // content-addressing is needed. The tracking lives in a `#fragment` here rather than a
        // query, and `stableId()` rebuilds from scheme+host+path, so both are dropped.
        //
        // Chosen BEFORE the source was first enabled, deliberately: nothing migrates a stored row
        // from one key scheme to another, so switching later re-notifies the entire backlog.
        foreach ($this->listings() as $listing) {
            self::assertMatchesRegularExpression(
                '~^https://www\.leboncoin\.fr/vi/\d+\.htm$~',
                (string) $listing->externalId,
                'the id must carry no tracking fragment and no query',
            );
            self::assertStringNotContainsString('at_campaign', (string) $listing->externalId);
        }
    }

    public function testFurnitureLinksAreNeverMistakenForListings(): void
    {
        // The message also links the homepage, the saved-search page, the CGU, four social networks
        // and two app stores. `link_host` is what keeps them out — and a segmented source keyed on
        // its links MUST name one, which the loader enforces.
        foreach ($this->listings() as $listing) {
            self::assertStringContainsString('/vi/', (string) $listing->url);
            self::assertStringNotContainsString('my-searches', (string) $listing->url);
            self::assertStringNotContainsString('/dc/cgu/', (string) $listing->url);
        }
    }

    public function testTheHeaderAndFooterSegmentsAreDropped(): void
    {
        // Splitting on the CTA leaves a leading segment (greeting + "3 nouvelles annonces…") and a
        // trailing one (the saved-search link and the sign-off). Neither carries a rent, so the rent
        // gate drops both — which is why the count is 3 and not 5.
        foreach ($this->listings() as $listing) {
            self::assertNotNull($listing->effectiveRentCc() ?? $listing->rentHc);
            self::assertNotNull($listing->commune);
        }
    }

    public function testAnExplicitSocialLabelIsStillCaughtOnThisSource(): void
    {
        // §1's residual, stated for the third time. `mixed_tenure: false` means a card stating no
        // tenure takes the LIBRE default — which is every real card here. What it does NOT do is
        // switch off the tier-2 label rules, which never consult the flag: an explicit PLS injected
        // into a real card must still REJECT.
        $listing = $this->listings()[0];

        $poisoned = new RawListing(
            sourceName: $listing->sourceName,
            externalId: $listing->externalId,
            title: $listing->title,
            description: ($listing->description ?? '') . "\nLogement financé en PLS, plafonds de ressources applicables.",
            commune: $listing->commune,
            postcode: $listing->postcode,
            rentHc: $listing->rentHc,
            surfaceM2: $listing->surfaceM2,
            rooms: $listing->rooms,
        );

        $result = (new TenureClassifier())->classify(
            $poisoned,
            new SourceProfile(name: 'leboncoin', defaultTenure: Tenure::LIBRE, mixedTenure: false),
        );

        self::assertSame(Tenure::PLS, $result->tenure);
        self::assertTrue($result->tenure->isExcluded());
    }

    public function testTheSourceIsEnabledAndWasProvenBeforeTheFlagMoved(): void
    {
        // `/add-source` step 5 prescribes the order: run `scout doctor --source=` against a new
        // block BEFORE enabling it — possible only because an explicitly named source is force-run
        // even while disabled. Measured live on 2026-08-26 with the flag still false: 3 annonces,
        // `ok`, 864 ms, identical to the frozen fixture; a seeded pass matched 1 of 3, the other two
        // rejected at 48 m² and 45 m² against the 50 m² floor.
        $definition = ConfigLoader::loadSources(self::ROOT . '/config/rent/sources.json')['leboncoin'];

        self::assertTrue($definition->enabled);
    }

    /** @return list<RawListing> */
    /**
     * A VEHICLE ALERT ON THE SAME SENDER YIELDS NOTHING — the guard `subject_pattern` was written
     * for and, until 2026-09-01, did not provide.
     *
     * Rent and car leboncoin share the sender `no.reply@leboncoin.fr`, the link host
     * `leboncoin.fr/vi/` and the `Voir l'annonce` card separator. Only the subject separates them,
     * the key was configured and documented for exactly this, and NO RENT ADAPTER READ IT. They do
     * not collide today only because the vehicle alerts sit unlabelled in the INBOX while this
     * source reads the alert folder — luck, and its own config comment named the expiry: "the
     * moment the Gmail filter that routes them is created, which is an owed developer action".
     *
     * Without the filter a car parses as a flat, is rejected for having no commune, and counts
     * toward this source's health — so the source would report a plausible number while ingesting
     * another domain's mail.
     *
     * The message is the REAL frozen alert with only its Subject rewritten to a real vehicle
     * subject, so nothing but the discriminator differs.
     */
    public function testAVehicleAlertOnTheSameSenderIsNotIngestedAsHousing(): void
    {
        $dir = sys_get_temp_dir() . '/lbc-subject-' . bin2hex(random_bytes(8));
        mkdir($dir);

        try {
            $raw = file_get_contents(self::ROOT . '/tests/fixtures/rent/leboncoin/alert-3-listings.eml');
            $swapped = preg_replace(
                '~^Subject:.*$~mi',
                'Subject: Auto Presto vous propose PEUGEOT 208 a 8 990 EUR a Melun',
                (string) $raw,
                1,
            );
            self::assertNotSame($raw, $swapped, 'the Subject header must actually have been replaced');
            file_put_contents($dir . '/vehicle-alert.eml', $swapped);

            $definition = ConfigLoader::loadSources(self::ROOT . '/config/rent/sources.json')['leboncoin'];
            $db = sys_get_temp_dir() . '/rentwatch-lbc-subj-' . bin2hex(random_bytes(8)) . '.sqlite3';

            $source = new EmailAlertSource(
                $definition,
                new FileMailbox($dir),
                ConfigLoader::loadCriteria(self::ROOT . '/config/rent/criteria.json')->communeLabels,
            );

            self::assertSame([], $source->fetch(), 'a vehicle alert must not be ingested as housing');

            // The counterweight: the SAME body under its own housing subject still yields its three
            // cards, so the guarantee cannot be satisfied by rejecting everything.
            self::assertCount(3, $this->listings());

            foreach (['', '-wal', '-shm'] as $s) {
                @unlink($db . $s);
            }
        } finally {
            foreach (glob($dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
    }

    private function listings(): array
    {
        $this->dbPath ??= sys_get_temp_dir() . '/rentwatch-lbc-' . bin2hex(random_bytes(8)) . '.sqlite3';

        $definition = ConfigLoader::loadSources(self::ROOT . '/config/rent/sources.json')['leboncoin'];
        $criteria = ConfigLoader::loadCriteria(self::ROOT . '/config/rent/criteria.json');

        $source = new EmailAlertSource(
            $definition,
            new FileMailbox(self::ROOT . '/tests/fixtures/rent/leboncoin'),
            $criteria->communeLabels,
        );

        return $source->fetch();
    }

    /** @return array<string, RawListing> keyed by commune, in fetch order */
    private function byCommune(): array
    {
        $out = [];

        foreach ($this->listings() as $listing) {
            $out[(string) $listing->commune] = $listing;
        }

        return $out;
    }
}
