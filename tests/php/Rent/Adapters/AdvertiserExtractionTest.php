<?php

declare(strict_types=1);

namespace Scout\Tests\Rent\Adapters;

use PHPUnit\Framework\TestCase;
use Scout\Rent\Adapters\EmailAlertSource;
use Scout\Adapters\Mail\FileMailbox;
use Scout\Rent\Config\ConfigLoader;
use Scout\Rent\Config\FieldMap;
use Scout\Rent\Config\SourceDefinition;
use Scout\Rent\Core\Tenure;
use Scout\Rent\Store\Store;

/**
 * The WIRING between `advertiser_pattern` and `RawListing::$advertiser` — on BOTH adapter paths.
 *
 * Track 5a shipped with the registry proven in isolation ({@see \Scout\Tests\Rent\Core\LandlordRegistryTest}),
 * the profile substitution proven in isolation ({@see \Scout\Tests\Rent\Core\TenureClassifierTest}) and
 * the step that joins them — the adapter actually attaching the extracted name to the card — proven
 * by NOTHING. The sabotage ledger found it: nulling the attachment left the suite green.
 *
 * `EmailAlertSource` attaches the advertiser at TWO sites: `listingsIn()` (the non-segmented path,
 * one listing per accepted link — pap's shape) and `buildCardListing()` (the segmented path,
 * `card_separator` — seloger and bienici). BOTH were unpinned, and each is pinned separately here.
 *
 * > A FIRST READING OF THE LEDGER RESULT SAID THE CASE WAS ONLY "PARTIALLY APPLIED" — that the
 * > expression's 12-space indent nulled one site while the other kept working. That was wrong twice
 * > over, and the way it was wrong is worth keeping. The two sites' indents map to the OPPOSITE
 * > paths from the guess (16 spaces is the non-segmented one), and `sed`'s `s%…%…%` matches an
 * > unanchored SUBSTRING — so a 12-space pattern also matches inside a 16-space line, and the
 * > expression hit BOTH sites. It was fully applied and genuinely undetected. Inferring which code
 * > path an expression reaches from its indentation, without running it, is how a coverage claim
 * > gets made about a guarantee nothing tests. The ledger cases now carry `^` anchors so each one
 * > provably reaches exactly one site, and a regression names its own path.
 *
 * The pattern itself is read from the SHIPPED `config/rent/sources.json`, never copied into this
 * file — a copy would keep passing while the real one rotted (the fixture-leakage rule).
 */
final class AdvertiserExtractionTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../../..';

    private string $dir = '';
    private string $dbPath = '';

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        @unlink($this->dbPath);
    }

    /**
     * THE SEGMENTED PATH — seloger's shape, and the one that carries all 23 production rows.
     *
     * Every card in the message takes the advertiser, because the subject names it once for the whole
     * digest. That is a property of the template, not an assumption: SeLoger's exclusivités mail is
     * one agency's listings, which is exactly why the subject can carry the name at all.
     */
    public function testTheSegmentedPathAttachesTheAdvertiserToEveryCard(): void
    {
        $listings = $this->source(
            subject: "IN'LI PARIS EST vous adresse ses dernières exclusivités",
            body: self::segmentedBody(),
            params: self::selogerParams(),
        )->fetch();

        self::assertCount(2, $listings, 'both cards must survive segmentation');

        foreach ($listings as $listing) {
            self::assertSame(
                "IN'LI PARIS EST",
                $listing->advertiser,
                'the subject names the advertiser once for the whole digest; every card takes it',
            );
        }
    }

    /**
     * THE NON-SEGMENTED PATH — one listing per accepted link, pap's shape.
     *
     * No shipped source configures `advertiser_pattern` without a `card_separator` today, so this
     * site is currently unreached in production. It is live code reachable by one config key, and the
     * ledger case that was supposed to cover it silently did not — which is precisely when a path
     * rots. Pinned so the next portal to need it inherits a working attachment rather than a
     * plausible-looking null.
     */
    public function testTheNonSegmentedPathAttachesTheAdvertiser(): void
    {
        $listings = $this->source(
            subject: 'CDC HABITAT ILE DE FRANCE vous adresse ses dernières exclusivités',
            body: self::singleCardBody(),
            params: self::nonSegmentedParams(),
        )->fetch();

        self::assertCount(1, $listings);
        self::assertSame('CDC HABITAT ILE DE FRANCE', $listings[0]->advertiser);
    }

    /**
     * THE COUNTERWEIGHT — a source configuring no `advertiser_pattern` yields `null`, not a guess.
     *
     * Without this, both tests above are satisfied by hardcoding the subject line into the adapter,
     * and the "configured pattern that misses yields null, never the generic scan" discipline that
     * governs every other per-source pattern here would not hold for this one.
     */
    public function testASourceWithNoAdvertiserPatternExtractsNothing(): void
    {
        $params = self::selogerParams();
        unset($params['advertiser_pattern']);

        $listings = $this->source(
            subject: "IN'LI PARIS EST vous adresse ses dernières exclusivités",
            body: self::segmentedBody(),
            params: $params,
        )->fetch();

        self::assertCount(2, $listings);

        foreach ($listings as $listing) {
            self::assertNull(
                $listing->advertiser,
                'an unconfigured source must extract nothing — the subject is not a fallback',
            );
        }
    }

    /**
     * A CONFIGURED PATTERN THAT MISSES YIELDS `null`, never the subject.
     *
     * The subject of a SeLoger alert is routinely `4 nouvelles annonces : Ile-de-France`, which names
     * no advertiser at all — so the miss branch is the COMMON case, not an edge one, and falling back
     * to the raw subject would hand `LandlordRegistry` a string to scan. It recognises nothing there
     * today, which is luck rather than a guard: `Ile-de-France` is one landlord rename away from
     * containing a registry entry.
     */
    public function testAPatternThatMissesYieldsNullRatherThanTheSubject(): void
    {
        $listings = $this->source(
            subject: '4 nouvelles annonces : Ile-de-France',
            body: self::segmentedBody(),
            params: self::selogerParams(),
        )->fetch();

        self::assertCount(2, $listings);

        foreach ($listings as $listing) {
            self::assertNull($listing->advertiser);
        }
    }

    /** The SHIPPED seloger params, with the real `advertiser_pattern` — never a copy of it. */
    private static function selogerParams(): array
    {
        $shipped = ConfigLoader::loadSources(self::ROOT . '/config/rent/sources.json')['seloger'];

        return [
            'from' => 'example-portal.test',
            'link_host' => 'example-portal.test',
            'card_separator' => "Voir l'annonce",
            'id_from' => 'content',
            'advertiser_pattern' => $shipped->params['advertiser_pattern'] ?? '',
        ];
    }

    /** The same, minus the separator — so `listingsIn()` takes the one-listing-per-link path. */
    private static function nonSegmentedParams(): array
    {
        $params = self::selogerParams();
        unset($params['card_separator'], $params['id_from']);
        $params['link_host'] = 'example-portal.test/annonces/';

        return $params;
    }

    private static function segmentedBody(): string
    {
        return "1 nouvelle annonce : Ile-de-France\n"
            . self::segmentedCard('1 150', 'Appartement 3 pièces', '3 pièces . 65,20 m²', 'Sartrouville', '78500')
            . self::segmentedCard('1 090', 'Appartement 4 pièces', '4 pièces . 78,40 m²', 'Dourdan', '91410')
            . "\nSeLoger • \n<L>\n";
    }

    private static function segmentedCard(
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

    /** A single listing behind a path-scoped `link_host`, so exactly one link is accepted. */
    private static function singleCardBody(): string
    {
        return "Location appartement\n"
            . "Lardy (91510)\n"
            . "4 pièces - 80 m²\n"
            . "1.200 € / mois\n"
            . "https://example-portal.test/annonces/-r458301723\n";
    }

    private function source(string $subject, string $body, array $params): EmailAlertSource
    {
        $this->dir = sys_get_temp_dir() . '/rentwatch-adv-' . bin2hex(random_bytes(8));
        mkdir($this->dir, 0o700, true);
        file_put_contents($this->dir . '/alert.eml', self::message($subject, $body));

        $this->dbPath = sys_get_temp_dir() . '/rentwatch-adv-' . bin2hex(random_bytes(8)) . '.sqlite3';

        $definition = new SourceDefinition(
            name: 'seloger',
            enabled: true,
            family: 'private',
            type: 'email_alert',
            mixedTenure: false,
            defaultTenure: Tenure::LIBRE,
            params: $params,
            map: new FieldMap(ref: ['url'], chargesIncluded: true),
        );

        return new EmailAlertSource(
            $definition,
            new FileMailbox($this->dir),
            ['sartrouville' => 'Sartrouville', 'dourdan' => 'Dourdan', 'lardy' => 'Lardy'],
        );
    }

    private static function message(string $subject, string $body): string
    {
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
            . 'Subject: ' . $subject . "\n"
            . "Content-Type: multipart/alternative; boundary=\"B\"\n"
            . "\n"
            . "--B\n"
            . "Content-Type: text/plain; charset=\"utf-8\"\n"
            . "\n"
            . $body
            . "\n--B--\n";
    }
}
