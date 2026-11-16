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
 * PAP (De Particulier a Particulier) — the simplest alert shape in the tree, and the one that
 * proved a naive config wrong on four counts at once.
 *
 * ONE listing per message, a `text/plain` part, and a real ad id in the URL, so none of SeLoger's
 * content-addressing is needed. What it does have is a preamble quoting the SUBSCRIBER'S OWN SEARCH
 * CRITERIA above the listing — *"jusqu'a 1.200 EUR a partir de 45 m2"* — and every generic reader in
 * `EmailAlertSource` is a first-match-wins `preg_match`. Measured on the real capture before any of
 * this was written:
 *
 *   surfaceIn()  -> 45.0   (the search FILTER, not the flat's 50 m2)
 *   roomsIn()    -> 3      (right, and only by coincidence: the criteria line also says 3)
 *   rentIn()     -> 800    (correct — the periodic-figure rule earns its keep here)
 *   communeIn()  -> null   (Milly-la-Foret is not a RANKED commune, so the vocabulary scan is blind)
 *
 * 45 is below `min_surface_m2`, so the first PAP alert ever sent would have been rejected for being
 * too small — silently, with nothing anywhere reading as a fault. That is Bien'ici's defect a second
 * time, down to the same number 45, and it is why the anchors below are POSITIONAL: they key on the
 * `(NNNNN)` postcode line, which is the one structural landmark the template guarantees, rather than
 * on any vocabulary an owner might or might not type.
 *
 * **n=2, and the second capture is why that matters.** The anchors were measured on ONE message,
 * with the n=1 risk stated — a risk this repo has twice paid for. It lasted about three hours: a
 * second alert arrived the same afternoon and is frozen here beside the first. It confirms every
 * anchor on a different commune and adds the case the first lacked, a rent written `1.150 EUR`
 * where the dot is a thousands separator. Append a third; never renumber.
 *
 * Everything here reads the SHIPPED `config/rent/sources.json`, so a config edit that breaks extraction
 * fails here rather than in production.
 */
#[CoversClass(EmailAlertSource::class)]
final class PapFixtureTest extends TestCase
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

    public function testTheUnsubscribeLinkIsNotAPhantomSecondListing(): void
    {
        // The message carries TWO links and both are on `www.pap.fr`: the annonce, and the
        // unsubscribe page at `/utilisateur/alertes`. `looksLikeAListing()` rejects noise by
        // substring — `unsubscribe`, `desinscription`, `preferences` — and PAP's own wording matches
        // NONE of them, so with a host-only `link_host` the second link is accepted as a listing.
        //
        // A non-segmented source builds one listing PER ACCEPTED LINK, each given the whole message
        // as its body, so that phantom carries the real flat's rent, commune, surface and rooms
        // under its own identity: notified as a separate flat, and never delisted because the
        // unsubscribe page never goes away.
        //
        // Asserted as one-per-MESSAGE rather than a bare count, so adding a third capture cannot
        // silently satisfy it.
        $messages = glob(self::ROOT . '/tests/fixtures/rent/pap/*.eml') ?: [];
        $listings = $this->listings();

        self::assertCount(count($messages), $listings);
        self::assertNotSame([], $messages, 'the fixture directory must not be empty');

        foreach ($listings as $listing) {
            self::assertStringContainsString('/annonces/', (string) $listing->url);
            self::assertStringNotContainsString('/utilisateur/', (string) $listing->url);
        }
    }

    public function testTheSecondCaptureExtractsToo(): void
    {
        // n=1 was the stated risk when the anchors were written, and it lasted about three hours —
        // a second alert arrived the same afternoon, from a different commune, and is frozen here as
        // the regression test the docblock promised.
        //
        // It carries the case the first one did not: a rent written `1.150 EUR / mois`, where the
        // dot is a THOUSANDS separator. "The rightmost separator is the decimal point" would read
        // that as 1 € — a flat that clears every ceiling and scores maximum headroom.
        $listing = $this->byCommune()['Meulan-en-Yvelines'];

        self::assertSame('78250', $listing->postcode);
        self::assertSame(1150, $listing->rentHc, 'a dot before three digits is a thousands group');
        self::assertSame(90.0, $listing->surfaceM2, 'the flat\'s 90 m², not the search floor of 45');
        self::assertSame(3, $listing->rooms);
        self::assertSame('https://www.pap.fr/annonces/-r453201284', (string) $listing->externalId);
    }

    /**
     * THE 2026-08-28 TEMPLATE CHANGE, and the anchor that was never a landmark.
     *
     * PAP moved rooms out of the title line into a combined line BELOW the location
     * (`3 pièces - 63 m²`) and switched `EUR` to `€`. Both positional patterns anchored on the line
     * AFTER the postcode carrying the surface FIRST, so both missed — measured in the production
     * store: **23 rows with a null surface and rooms, 19 of them notified as MATCH**, because a null
     * surface passes `min_surface_m2` by hard rule 9. Sub-50 m² flats were notifying.
     *
     * The ownership rule behaved correctly throughout — a configured pattern that misses yields
     * null, never the generic scan — so the search floor of 45 was never substituted. The safety
     * held; the pattern went stale.
     */
    public function testTheCombinedRoomsAndSurfaceLineOfTheNewTemplateIsRead(): void
    {
        $listing = $this->byCommune()['Lieusaint'];

        self::assertSame('77127', $listing->postcode);
        self::assertSame(63.0, $listing->surfaceM2, 'the flat\'s 63 m², from `3 pièces - 63 m²`, not the search floor of 45');
        self::assertSame(3, $listing->rooms, 'and the rooms from the same line, which is BELOW the postcode now');
        self::assertSame(1150, $listing->rentHc, '`1.150 €` — the dot is still a thousands group when the currency changes');
        self::assertSame('Location appartement', $listing->title, 'the bare title of the new template, not the subject line');
    }

    /**
     * A LOCATION LINE NEED NOT CARRY A POSTCODE, and all four patterns anchored on one.
     *
     * `Saint-Maur-des-Fossés (94)` states a DEPARTMENT; `Paris 16e` states neither. On those shapes
     * `title_pattern`, `commune_pattern`, `surface_pattern` and `rooms_pattern` all failed together,
     * so the row stored an EMPTY title, a null commune, a null postcode, a null surface and null
     * rooms — and an empty title makes every `exclude_title_patterns` entry inert, which is the
     * In'li/SeLoger lesson a third time. The register listed those rows separately as "empty-title
     * rows"; they are this defect, not another one.
     *
     * The anchor is the LAYOUT now — a line that is entirely the facts, which the prose criteria
     * line can never be — so the postcode stops being load-bearing.
     */
    public function testALocationLineWithNoPostcodeStillYieldsTitleCommuneSurfaceAndRooms(): void
    {
        $listing = $this->byCommune()['Saint-Maur-des-Fossés'];

        self::assertSame('Location appartement', $listing->title, 'an empty title would make exclude_title_patterns inert');
        self::assertSame(55.0, $listing->surfaceM2);
        self::assertSame(3, $listing->rooms);
        self::assertSame(1050, $listing->rentHc);

        // STATED COST, asserted so it cannot be quietly "improved" into a guess: the payload states a
        // DEPARTMENT, not a postcode, and `postcodeIn()` refuses to invent one — its own comment says
        // a wrong postcode is worse than none, because it can pass the prefix filter for a listing
        // that is nowhere near. In region mode the postcode IS the location filter, so this listing
        // is still rejected on location. That is the safe direction, and it is now VISIBLE (a fully
        // read row) instead of an empty one.
        self::assertNull($listing->postcode, 'a department is not a postcode and must never be widened into one');
    }

    /**
     * A CONFIGURED PATTERN THAT MATCHES NOTHING AT ALL IS A TEMPLATE CHANGE, AND IT IS NOW SAID OUT
     * LOUD (Track 1h, health half).
     *
     * The ownership rule always behaved correctly — a configured pattern that misses yields `null`,
     * never the generic scan — but NOTHING COUNTED THE MISSES. So when PAP moved its rooms out of
     * the title line on 2026-08-28, every row stored a null surface and null rooms for four days,
     * 19 of them were notified as MATCH (a null passes `min_surface_m2` by hard rule 9), and
     * `doctor` said `ok` throughout. It was found by querying the production database, not by any
     * signal the tool emitted — which is hard rule 2 one layer in: the CARDS still parsed, so
     * `item_count` never moved.
     *
     * 100% of at least three, and both halves matter. The shipped patterns miss NOTHING here. The
     * old postcode-anchored ones miss on the two new-template captures and not on the old ones —
     * a PARTIAL miss, which is ordinary and must NOT raise anything, because a portal's own copy
     * varies and `commune_pattern` legitimately finds nothing on a card that states no commune.
     */
    public function testAPatternThatMissesEveryCardIsReportedWhileAPartialMissIsNot(): void
    {
        $shipped = $this->source();
        $shipped->fetch();

        self::assertSame([], $shipped->patternMisses()->total(), 'the shipped patterns read every capture');

        // The patterns as they stood on 2026-08-28: the old-template captures read, the newer ones
        // miss. The COUNTS move with the fixture directory, which is the point — a capture added
        // without re-reading this test would otherwise slip past it.
        $stale = $this->source([
            'surface_pattern' => '~\(\d{5}\)\h*\n\h*([\d.,]+)\h*m²~u',
            'rooms_pattern' => '~^[^\n]*?(\d+)\h*pi[eè]ces?[^\n]*\n[^\n]*\(\d{5}\)~mu',
        ]);
        $stale->fetch();
        $counts = $stale->patternMisses()->counts();

        self::assertSame(3, $counts['surface_pattern']['misses'], 'every new-template capture misses');
        self::assertSame(5, $counts['surface_pattern']['calls']);
        self::assertSame(
            [],
            $stale->patternMisses()->total(),
            'a PARTIAL miss raises nothing — a portal\'s copy varies, and crying wolf is how a signal gets ignored',
        );
    }

    /**
     * THE FIFTH CAPTURE IS HERE FOR ITS `Date` HEADER, and the flat is incidental.
     *
     * `Date: Sat, 5 Sep 2026 09:19:13 +0200` — a SINGLE-DIGIT day, which RFC 5322 writes as
     * `1*2DIGIT` and which `parseRfc2822()` refused for a month, because its four masks all used
     * `d` and the round-trip that makes it strict re-formats `5` as `05`. PAP is the portal that
     * sends it. Measured in production on 2026-09-05: every PAP row first seen 1–5 September
     * carried `observedAt = NULL` (36 of 86), `source_runs.feed_newest_at` for pap froze at
     * `2026-08-31T15:24:29Z`, and the live `doctor` reported `feed_silent` — *"rien envoyé depuis
     * 4 jour(s)"* — on a feed delivering daily.
     *
     * Two silent failures from one line: the store's stale-sighting guard had no instant to compare
     * (the 429-history-row defect), and a health verdict was false. `EmailMessageSentAtTest` covers
     * the parser; this covers the CHAIN, from a real header to the listing's own observation time —
     * the hop `Pipeline::enrich()` silently dropped once already.
     */
    public function testASingleDigitDayInTheDateHeaderReachesTheListingsObservationTime(): void
    {
        $listing = $this->byCommune()['Épinay-sur-Seine'];

        self::assertSame('2026-09-05T07:19:13Z', $listing->observedAt);
        self::assertSame('93800', $listing->postcode);
        self::assertSame(70.0, $listing->surfaceM2);
        self::assertSame(3, $listing->rooms);
        self::assertSame(550, $listing->rentHc, 'PAP states no charges anywhere — HC, never an invented CC');
    }

    public function testTheCardsOwnFactsBeatTheSearchCriteriaQuotedAboveThem(): void
    {
        $listing = $this->byCommune()['Milly-la-Forêt'];

        // THE ONE THAT WAS MEASURED WRONG. The preamble says `a partir de 45 m2`; the flat is 50.
        self::assertSame(50.0, $listing->surfaceM2, 'the flat\'s surface, not the search floor');

        // Right before the fix too, and only by coincidence — the criteria line also says 3 pieces.
        // Anchored anyway: a coincidence is not a guarantee, and a 4-piece flat found by a
        // "3 pieces et plus" alert would have read 3.
        self::assertSame(3, $listing->rooms);

        self::assertSame('91490', $listing->postcode);

        // RENT IS `hors charges` BY MEASUREMENT, not by omission: the payload mentions charges
        // NOWHERE — zero occurrences of `charges`, `CC` or `HC`. The Logirep and leboncoin precedent
        // applies, so the figure lands in rentHc, `max_rent_cc` never fires on it, and the score
        // line says the ceiling is unverifiable.
        self::assertSame(800, $listing->rentHc);
        self::assertNull($listing->rentCc, 'an unstated CC must never be invented');
    }

    public function testTheCommuneIsReadFromTheLayoutAndNotFromTheRankedVocabulary(): void
    {
        // `communeIn()` scans `Criteria::communeLabels`, which in REGION MODE is built from the
        // ranked communes only — ten names. Milly-la-Foret is not one of them, so the scan returns
        // null while the postcode parses fine: the listing still MATCHES, and the push simply cannot
        // say where the flat is, Dedup gets a weaker key, and the S1 score cannot fire. Nothing
        // about that reads as a fault from outside. Exactly the SeLoger regression of 2026-08-25.
        $communes = array_keys($this->byCommune());
        sort($communes);

        self::assertSame(
            ['Lieusaint', 'Meulan-en-Yvelines', 'Milly-la-Forêt', 'Saint-Maur-des-Fossés', 'Épinay-sur-Seine'],
            $communes,
            'EVERY capture names a commune, and NONE is a ranked one — sorted, because the '
            . 'mailbox does not promise an order and pinning an incidental one is a false guarantee. '
            . 'Saint-Maur-des-Fossés is the one that matters: its location line carries a DEPARTMENT '
            . 'and no postcode, so under the old postcode anchor it yielded no commune at all',
        );
    }

    public function testTheTitleIsTheCardsOwnAndNeverTheSubjectLine(): void
    {
        // Unconfigured, the title falls back to the message SUBJECT — here
        // `Alerte email : Appartement 3 pieces Milly-la-Foret (91490)`. That is not merely untidy:
        // `exclude_title_patterns` is matched against the TITLE ONLY, and the `Alerte email : `
        // prefix defeats the anchored `^\s*chambre\b` that exists because four of SeLoger's first
        // nine matches were coliving ROOMS passing every numeric filter. The exclusion would be
        // structurally unreachable on this source — the In'li and SeLoger lesson a third time.
        $title = (string) $this->byCommune()['Milly-la-Forêt']->title;

        self::assertSame('Location appartement 3 pièces', $title);
        self::assertStringNotContainsString('Alerte email', $title);
    }

    public function testIdentityIsTheAnnoncePathStrippedOfItsPerRecipientQuery(): void
    {
        // The ad id `-r458301723` is real and survives `stableId()`, which rebuilds from
        // scheme+host+path — dropping `email=`, the `md5=` token and the utm campaign, all of which
        // are per-recipient and would otherwise re-key the row on every send.
        //
        // Link identity was chosen BEFORE the source was first enabled, deliberately: nothing
        // migrates a stored row from one key scheme to another, so switching later re-notifies the
        // entire backlog.
        $listing = $this->byCommune()['Milly-la-Forêt'];

        self::assertSame('https://www.pap.fr/annonces/-r458301723', (string) $listing->externalId);
        self::assertStringNotContainsString('md5=', (string) $listing->externalId);
        self::assertStringNotContainsString('email=', (string) $listing->externalId);
    }

    public function testTheAlertSenderIsScopedAndNotThePortalsAccountAddress(): void
    {
        // PAP sends from TWO addresses and only one is alerts. `users@pap.fr` carries the
        // creation/deletion receipts and the marketing ("Le kit du locataire"); `users-alertes@pap.fr`
        // carries the search alerts. One mailbox serves every portal, so `from` is this source's
        // scope AND what scopes the IMAP `SEARCH ... FROM` — getting it wrong ingests a newsletter
        // as listings and starves the real alerts out of the window.
        $params = ConfigLoader::loadSources(self::ROOT . '/config/rent/sources.json')['pap']->params;

        self::assertSame('users-alertes@pap.fr', $params['from']);
    }

    public function testAnExplicitSocialLabelIsStillCaughtOnThisSource(): void
    {
        // §1's residual, stated again. `mixed_tenure: false` means a card stating no tenure takes
        // the LIBRE default — which is every real card here. What it does NOT do is switch off the
        // tier-2 label rules, which never consult the flag: an explicit PLS injected into a real
        // card must still REJECT.
        $listing = $this->byCommune()['Milly-la-Forêt'];

        $poisoned = new RawListing(
            sourceName: $listing->sourceName,
            externalId: $listing->externalId,
            title: $listing->title,
            description: ($listing->description ?? '') . "\nLogement conventionné, financement PLS.",
            commune: $listing->commune,
            postcode: $listing->postcode,
            rentHc: $listing->rentHc,
            surfaceM2: $listing->surfaceM2,
            rooms: $listing->rooms,
        );

        $result = (new TenureClassifier())->classify(
            $poisoned,
            new SourceProfile(name: 'pap', defaultTenure: Tenure::LIBRE, mixedTenure: false),
        );

        self::assertTrue($result->tenure->isExcluded());
    }

    /** @return list<RawListing> */
    /**
     * R6-1 — AN ARRONDISSEMENT HAS A DIGIT IN ITS NAME, and the capture class forbade digits.
     *
     * `commune_pattern`'s class was `[^\n\d(]`, so `Paris 16e (75016)` came back null while
     * `Milly-la-Forêt (91490)` read fine. PAP covers Paris and `75` is in `postcode_prefixes`, so
     * such a listing still MATCHED on its postcode while losing its commune — no S1 score, a weaker
     * dedup key, and a push that cannot say where the flat is. That is the SeLoger regression of
     * 2026-08-25 rebuilt on another source, and the safe direction only in the sense that a null
     * commune cannot over-match; it is silent, which is what makes it worth a test.
     *
     * The capture may now CONTAIN a digit but not START with one, so a numeric line is still not a
     * place name.
     *
     * @return iterable<string, array{0: string, 1: ?string}>
     */
    public static function communeLines(): iterable
    {
        yield 'arrondissement, short ordinal' => ['Paris 16e (75016)', 'Paris 16e'];
        yield 'arrondissement, long ordinal' => ['Paris 16ème (75016)', 'Paris 16ème'];
        yield 'arrondissement, single digit' => ['Paris 5e (75005)', 'Paris 5e'];
        yield 'ordinary commune' => ['Milly-la-Forêt (91490)', 'Milly-la-Forêt'];
        yield 'hyphenated commune' => ['Saint-Germain-en-Laye (78100)', 'Saint-Germain-en-Laye'];
        yield 'commune with an article' => ['Le Perreux-sur-Marne (94170)', 'Le Perreux-sur-Marne'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('communeLines')]
    public function testTheCommuneReaderAcceptsAnArrondissement(string $line, ?string $expected): void
    {
        $pattern = (string) ConfigLoader::loadSources(self::ROOT . '/config/rent/sources.json')['pap']->params['commune_pattern'];
        $body = "Location appartement\n" . $line . "\n4 pièces - 80 m²\n";

        self::assertSame(1, preg_match($pattern, $body, $m), $line . ' must yield a commune');
        self::assertSame($expected, trim($m[1]));
    }

    /** The counterweight: a line that STARTS with a digit is a measurement, not a place. */
    public function testANumericLineIsNotReadAsACommune(): void
    {
        $pattern = (string) ConfigLoader::loadSources(self::ROOT . '/config/rent/sources.json')['pap']->params['commune_pattern'];
        $body = "Lardy (91510)\n4 pièces - 80 m²\n";

        self::assertSame(1, preg_match($pattern, $body, $m));
        self::assertSame('Lardy', trim($m[1]), 'the commune line wins, not the pièces line');
    }

    private function listings(): array
    {
        $this->dbPath ??= sys_get_temp_dir() . '/rentwatch-pap-' . bin2hex(random_bytes(8)) . '.sqlite3';

        return $this->source()->fetch();
    }

    /**
     * The shipped `pap` source, optionally with some `params` replaced — used to run the patterns as
     * they stood before a fix against the captures that broke them.
     *
     * @param array<string, string> $paramOverrides
     */
    private function source(array $paramOverrides = []): EmailAlertSource
    {
        $this->dbPath ??= sys_get_temp_dir() . '/rentwatch-pap-' . bin2hex(random_bytes(8)) . '.sqlite3';

        $definition = ConfigLoader::loadSources(self::ROOT . '/config/rent/sources.json')['pap'];
        $criteria = ConfigLoader::loadCriteria(self::ROOT . '/config/rent/criteria.json');

        if ($paramOverrides !== []) {
            $fields = get_object_vars($definition);
            $fields['params'] = $paramOverrides + $definition->params;
            $definition = new \Scout\Rent\Config\SourceDefinition(...$fields);
        }

        return new EmailAlertSource(
            $definition,
            new FileMailbox(self::ROOT . '/tests/fixtures/rent/pap'),
            $criteria->communeLabels,
        );
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
