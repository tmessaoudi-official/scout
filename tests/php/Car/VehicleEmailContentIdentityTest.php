<?php

declare(strict_types=1);

namespace Scout\Tests\Car;

use PHPUnit\Framework\TestCase;
use Scout\Adapters\Mail\Mailbox;
use Scout\Car\VehicleEmailSource;
use Scout\Car\VehicleListing;
use Scout\Car\VehicleSourceDefinition;
use Scout\Car\VehicleSourceLoader;
use Scout\Car\VehicleStore;
use Scout\Config\ConfigError;

/**
 * ROWS 37 + 38 (2026-09-05): a car email source whose links carry no id, and whose cards are a
 * LABELLED BLOCK rather than a price line with a facts line under it.
 *
 * Both were measured on CapCar and La Centrale (Track 6-B, 2026-09-04) and neither fits the
 * adapter as ParuVendu and leboncoin left it:
 *
 *  - every link is a per-recipient tracking redirect (`sendibt3.com/tr/cl/<token>`,
 *    `clicks.mail-alerte.lacentrale.fr/f/a/<token>~~/…`), so `basename()` of the path is a fresh
 *    identity per message and the same identity for every card in one — SeLoger's problem, and
 *    the answer is SeLoger's: `id_from: content`, price OUT of the key, a no-information floor;
 *  - CapCar's card is `Marque : X / Modèle : Y / … / Prix : P €`, La Centrale's is
 *    `TITLE / La Centrale N km / P €` — facts on their own lines, a title nowhere near the price
 *    line's neighbour, a make that only the labelled block states. `facts_pattern` therefore
 *    accepts `title`, `make`, `model`, `version`, `gearbox` and `price` named groups, the title
 *    being composed from make + model + version when no `title` group exists.
 *
 * Synthetic messages throughout, shaped like the captures: the fixtures are B1's and B2's own
 * rows, and a raw dump-eml capture must never be referenced from a test.
 *
 * ParuVendu and leboncoin are pinned byte-identical by their own fixture tests; run them first.
 */
final class VehicleEmailContentIdentityTest extends TestCase
{
    private const string NBSP = "\u{00A0}";
    private const string NNBSP = "\u{202F}";

    // ── the CapCar shape ─────────────────────────────────────────────────────────────────────

    /** @return array<string, string> */
    private static function capcarParams(): array
    {
        return [
            'from' => 'contact@cars.test',
            'subject_pattern' => '~Nouvelle sélection~u',
            'link_host' => 't.cars.test/tr/cl/',
            'card_separator_pattern' => '~(?=Marque\x{00A0}:)~u',
            'link_after' => 'Voir ce véhicule',
            'id_from' => 'content',
            'facts_pattern' => '~Marque\h*:\h*(?<make>[^\n]+?)\h*\n\s*Modèle\h*:\h*(?<model>[^\n]+?)\h*\n\s*Finition\h*:\h*(?<version>[^\n]+?)\h*\n\s*Motorisation\h*:\h*[^\n]*\n\s*Carburant\h*:\h*(?<fuel>[^\n]+?)\h*\n\s*Boîte\h*:\h*(?<gearbox>[^\n]+?)\h*\n\s*Année\h*:\h*(?<year>\d{4})\h*\n\s*Kilométrage\h*:\h*(?<km>[\d\h]+?)\h*\n\s*Prix\h*:\h*(?<price>[\d\h]+?)\h*€~u',
            'make_model_unknown_pattern' => '~^autres$~',
        ];
    }

    /** @param list<array<string, string>> $cards */
    private static function capcarMessage(array $cards, string $header = 'HDR', string $footer = 'FOOTER'): string
    {
        $body = "Nouveaux véhicules disponibles !\n\nPlusieurs véhicules viennent d'arriver\nhttps://t.cars.test/tr/cl/" . $header . "\n\n";
        foreach ($cards as $i => $c) {
            $body .= 'Marque' . self::NBSP . ': ' . $c['make'] . "\n\n"
                . 'Modèle' . self::NBSP . ': ' . $c['model'] . "\n\n"
                . 'Finition' . self::NBSP . ': ' . $c['version'] . "\n\n"
                . 'Motorisation' . self::NBSP . ": 1.0 TCe 90\n\n"
                . 'Carburant' . self::NBSP . ': ' . ($c['fuel'] ?? 'Essence') . "\n\n"
                . 'Boîte' . self::NBSP . ': ' . ($c['gearbox'] ?? 'Manuelle') . "\n\n"
                . 'Année' . self::NBSP . ': ' . $c['year'] . "\n\n"
                . 'Kilométrage' . self::NBSP . ': ' . $c['km'] . "\n\n"
                . 'Prix' . self::NBSP . ': ' . $c['price'] . " €\n\n"
                . "Voir ce véhicule\n\nhttps://t.cars.test/tr/cl/CARD" . $i . "\n\n";
        }
        $body .= "Achetez votre voiture d'occasion en toute confiance\n\nVoir les véhicules dans mes critères\nhttps://t.cars.test/tr/cl/" . $footer . "\n";

        return "From: \"CapCar\" <contact@cars.test>\r\nDate: Thu, 03 Sep 2026 18:00:41 +0200\r\nSubject: Nouvelle sélection de véhicules disponibles !\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n" . str_replace("\n", "\r\n", $body);
    }

    /** @return array<string, string> */
    private static function clio(string $price = '13' . self::NNBSP . '490'): array
    {
        return ['make' => 'Renault', 'model' => 'Clio', 'version' => 'Evolution', 'year' => '2024', 'km' => '24409', 'price' => $price];
    }

    /** @return array<string, string> */
    private static function duster(): array
    {
        return ['make' => 'Dacia', 'model' => 'Duster', 'version' => 'Journey', 'year' => '2023', 'km' => '31' . self::NBSP . '200', 'price' => '19' . self::NNBSP . '990', 'fuel' => 'Diesel', 'gearbox' => 'Automatique'];
    }

    public function testALabelledCardYieldsEveryFactAndAComposedTitle(): void
    {
        $cars = $this->fetch(self::capcarParams(), self::capcarMessage([self::clio(), self::duster()]));

        self::assertCount(2, $cars);
        [$clio, $duster] = $cars;

        self::assertSame('Renault Clio Evolution', $clio->title, 'composed from make + model + version');
        self::assertSame('renault', $clio->make);
        self::assertSame('clio', $clio->model);
        self::assertSame(13490, $clio->priceEur, 'a U+202F thousands mark is a digit separator');
        self::assertSame(2024, $clio->year);
        self::assertSame(24409, $clio->mileageKm);
        self::assertSame('essence', $clio->fuel);
        self::assertSame('manuelle', $clio->gearbox, 'the gearbox comes from its own label, not from the title');

        self::assertSame('Dacia Duster Journey', $duster->title);
        self::assertSame(31200, $duster->mileageKm, 'an NBSP thousands mark is a digit separator too');
        self::assertSame('diesel', $duster->fuel);
        self::assertSame('automatique', $duster->gearbox);
    }

    /**
     * THE LAST LINK ON THE HOST IS THE FOOTER'S, for the last card. Every CapCar link — the header
     * banner, each card's CTA, the footer's "voir mes critères" — is on the same tracking host,
     * and the lookahead separator leaves the footer inside the fourth card's segment. So the
     * card's link is the FIRST host link after its own CTA, never the last in the segment.
     */
    public function testACardsLinkIsTheFirstHostLinkAfterItsOwnCtaNeverTheFooters(): void
    {
        [$clio, $duster] = $this->fetch(self::capcarParams(), self::capcarMessage([self::clio(), self::duster()]));

        self::assertSame('https://t.cars.test/tr/cl/CARD0', $clio->url);
        self::assertSame('https://t.cars.test/tr/cl/CARD1', $duster->url, 'not …/FOOTER');
    }

    public function testHeaderAndFooterSegmentsYieldNoPhantomListing(): void
    {
        $cars = $this->fetch(self::capcarParams(), self::capcarMessage([self::clio()]));

        self::assertCount(1, $cars, 'the header carries a host link and no facts; the footer likewise — neither is a car');
    }

    // ── identity ─────────────────────────────────────────────────────────────────────────────

    public function testIdentityIsContentNotTheTrackingLinkAndThePriceIsNotInIt(): void
    {
        [$a] = $this->fetch(self::capcarParams(), self::capcarMessage([self::clio()], header: 'H1', footer: 'F1'));
        [$b] = $this->fetch(self::capcarParams(), self::capcarMessage([self::clio('12' . self::NNBSP . '990')], header: 'H2', footer: 'F2'));

        self::assertSame($a->externalId, $b->externalId, 'a price drop is an EVENT on one car, never a second car');
        self::assertNotSame('CARD0', $a->externalId, 'the tracking token is not an id');
        self::assertMatchesRegularExpression('~^[0-9a-f]{40}$~', $a->externalId);
    }

    /** `LEXUS UX` one week and `Lexus UX` the next is one car: the key folds the title. */
    public function testIdentityFoldsTheTitle(): void
    {
        [$a] = $this->fetch(self::lacentraleParams(), self::lacentraleMessage('LEXUS UX', '84 135', '22 990'));
        [$b] = $this->fetch(self::lacentraleParams(), self::lacentraleMessage('Lexus  ux', '84 135', '22 990', token: 'U'));

        self::assertSame($a->externalId, $b->externalId);
    }

    /** The same car seen through two portals is two rows — identities stay per source, as on the rent side. */
    public function testIdentityIsScopedToTheSource(): void
    {
        $raw = self::capcarMessage([self::clio()]);
        $a = (new VehicleEmailSource($this->load(self::capcarParams()), $this->mailbox($raw)))->fetch()[0];
        $other = VehicleSourceLoader::fromArray(['sources' => ['elsewhere' => ['enabled' => true, 'family' => 'portal', 'type' => 'email_alert', 'params' => self::capcarParams()]]])['elsewhere'];
        $b = (new VehicleEmailSource($other, $this->mailbox($raw)))->fetch()[0];

        self::assertNotSame($a->externalId, $b->externalId);
    }

    public function testTwoCarsDifferingOnlyInMileageAreTwoIdentities(): void
    {
        $other = self::clio();
        $other['km'] = '24410';
        [$a, $b] = $this->fetch(self::capcarParams(), self::capcarMessage([self::clio(), $other]));

        self::assertNotSame($a->externalId, $b->externalId);
    }

    /**
     * The no-information floor, the car shape: something that NAMES the car and something that
     * DESCRIBES it — a title alone would give every stripped card of one model one identity.
     */
    public function testACardWithATitleButNeitherYearNorMileageIsBelowTheFloor(): void
    {
        $params = self::capcarParams();
        // A pattern whose year and km groups are optional, so a card can match without them.
        $params['facts_pattern'] = '~Marque\h*:\h*(?<make>[^\n]+?)\h*\n\s*Modèle\h*:\h*(?<model>[^\n]+?)\h*\n(?:\s*Finition\h*:\h*(?<version>[^\n]+?)\h*\n)?(?:.*?Année\h*:\h*(?<year>\d{4}))?(?:.*?Kilométrage\h*:\h*(?<km>[\d\h]+?)\h*\n)?~su';
        $stripped = "From: contact@cars.test\r\nDate: Thu, 03 Sep 2026 18:00:41 +0200\r\nSubject: Nouvelle sélection\r\n\r\n"
            . 'Marque' . self::NBSP . ": Renault\r\n\r\nModèle" . self::NBSP . ": Clio\r\n\r\nVoir ce véhicule\r\n\r\nhttps://t.cars.test/tr/cl/CARD0\r\n";

        self::assertSame([], $this->fetch($params, $stripped), 'below the floor: not a card, and not an identity every Clio would share');
    }

    public function testThePortalsUnknownMakeTokenIsNulledOnTheFactsPathToo(): void
    {
        $card = self::clio();
        $card['make'] = 'Autres';
        $card['model'] = 'Autres';
        [$car] = $this->fetch(self::capcarParams(), self::capcarMessage([$card]));

        self::assertNull($car->make, 'Track 6-A4 applies whichever haystack the make came from');
        self::assertNull($car->model);
        self::assertSame('Evolution', $car->title, 'the composed title keeps what is real');
    }

    // ── the La Centrale shape ────────────────────────────────────────────────────────────────

    /** @return array<string, string> */
    private static function lacentraleParams(): array
    {
        return [
            'from' => 'info@alerte.lc.test',
            'link_host' => 'clicks.alerte.lc.test/f/a/',
            'card_separator' => 'Détails',
            'id_from' => 'content',
            'facts_pattern' => '~^\h*(?<title>[^\n(]+?)\h*\n\h*\(\h*https?://\S+\h*\)\h*\n\h*La Centrale\h+(?<km>[\d\h]+?)\h*km\h*\n\h*\(\h*https?://\S+\h*\)\h*\n\h*(?<price>[\d\h]+?)\h*€~mu',
        ];
    }

    private static function lacentraleMessage(string $title, string $km, string $price, string $token = 'T'): string
    {
        // Distinct last path segments per link, so link identity can be told apart in a test.
        $body = "Vos nouvelles alertes\n924\nnouvelles annonces\n( https://clicks.alerte.lc.test/f/a/" . $token . "HDR~~/hdr )\n\nLa Centrale \n( https://clicks.alerte.lc.test/f/a/" . $token . "LOGO~~/logo )\n\n"
            . $title . " \n( https://clicks.alerte.lc.test/f/a/" . $token . "1~~/title )\nLa Centrale " . $km . " km \n( https://clicks.alerte.lc.test/f/a/" . $token . "2~~/km )\n" . $price . " € \n( https://clicks.alerte.lc.test/f/a/" . $token . "3~~/price )\nDétails \n( https://clicks.alerte.lc.test/f/a/" . $token . "4~~/details )\n\n"
            . "Se désabonner\n( https://clicks.alerte.lc.test/f/a/" . $token . "UNSUB~~/unsub )\n";

        return "From: \"La Centrale\" <info@alerte.lc.test>\r\nDate: Thu, 03 Sep 2026 08:34:13 +0200\r\nSubject: 924 nouveaux véhicules correspondent à votre recherche\r\n\r\n" . str_replace("\n", "\r\n", $body);
    }

    public function testATitleLineCardWithNoYearIsACarKeyedOnTitleAndMileage(): void
    {
        $cars = $this->fetch(self::lacentraleParams(), self::lacentraleMessage('LEXUS UX', '84 135', '22 990'));

        self::assertCount(1, $cars, 'the header and the unsubscribe tail are not cars');
        $car = $cars[0];
        self::assertSame('LEXUS UX', $car->title);
        self::assertSame(84135, $car->mileageKm);
        self::assertSame(22990, $car->priceEur);
        self::assertNull($car->year, 'unknown, never zero (hard rule 9)');

        [$again] = $this->fetch(self::lacentraleParams(), self::lacentraleMessage('LEXUS UX', '84 135', '21 500', token: 'U'));
        self::assertSame($car->externalId, $again->externalId, 'a fresh token set and a lower price: the same car');
    }

    public function testATruncatedTitleStillKeysWithTheMileage(): void
    {
        [$a] = $this->fetch(self::lacentraleParams(), self::lacentraleMessage('RENAULT KANGOO II EXPRESS p...', '95 902', '9 990'));
        [$b] = $this->fetch(self::lacentraleParams(), self::lacentraleMessage('RENAULT KANGOO II EXPRESS p...', '61 000', '9 990'));

        self::assertNotSame($a->externalId, $b->externalId, 'stated cost of a truncated title: two identical-titled cars are told apart by their mileage — and by nothing else');
    }

    // ── the Agorastore shape: a stated lot REFERENCE is the identity ─────────────────────────

    /** @return array<string, string> */
    private static function agorastoreParams(): array
    {
        return [
            'from' => 'support@auction.test',
            'link_host' => 'email.alerts.auction.test/c/',
            'card_separator_pattern' => '~\n(?=[^\n\[]+\n\[https?://\S+\]\n\[https?://\S+\]\n\d{6}-)~u',
            'id_from' => 'content',
            // title line, two links, then `<ref> <TITLE IN CAPITALS>` — year and km optional inside the title.
            'facts_pattern' => '~^\h*(?<title>[^\n\[]+?)\h*\n\[https?://\S+\]\n\[https?://\S+\]\n(?<ref>\d{6}-[A-Z0-9-]+)\h+[^\n]+~mu',
        ];
    }

    private static function auctionMessage(): string
    {
        $body = "Bonjour,\n\nVotre recherche : Voiture\n[https://email.alerts.auction.test/c/HDR]\n\n"
            . "Fiat Punto 1.3 Multijet - 2013 - 212669km - DB714WD\n[https://email.alerts.auction.test/c/A1]\n[https://email.alerts.auction.test/c/A2]\n011218-259 FIAT PUNTO 1.3 MULTIJET - 2013 - 212669KM - DB714WD\n[https://email.alerts.auction.test/c/A3]\n\n"
            . "Voiture - Mercedes - CLK\n[https://email.alerts.auction.test/c/B1]\n[https://email.alerts.auction.test/c/B2]\n014684-S-4361 VOITURE - MERCEDES - CLK\n[https://email.alerts.auction.test/c/B3]\n\n"
            . "Se désabonner\n[https://email.alerts.auction.test/c/UNSUB]\n";

        return "From: Auction <support@auction.test>\r\nDate: Mon, 01 Sep 2026 10:12:00 +0200\r\nSubject: Nouveaux articles\r\n\r\n" . str_replace("\n", "\r\n", $body);
    }

    public function testAStatedLotReferenceIsTheIdentityEvenWithNoYearAndNoMileage(): void
    {
        $cars = $this->fetch(self::agorastoreParams(), self::auctionMessage());

        self::assertCount(2, $cars, 'the CLK card states no year and no km and is still a car: its ref is the evidence');
        [$punto, $clk] = $cars;
        self::assertSame('011218-259', $punto->externalId, 'the lot reference, not a hash');
        self::assertSame('Fiat Punto 1.3 Multijet - 2013 - 212669km - DB714WD', $punto->title);
        self::assertSame('014684-S-4361', $clk->externalId);
        self::assertSame('Voiture - Mercedes - CLK', $clk->title);
        self::assertNull($clk->year);
        self::assertNull($clk->mileageKm);
        self::assertNull($clk->priceEur, 'an auction states no price — unknown, never zero');
    }

    public function testARefWithNoTitleIsNotACard(): void
    {
        $params = self::agorastoreParams();
        $params['facts_pattern'] = '~^\h*(?<title>)\n?\[https?://\S+\]\n\[https?://\S+\]\n(?<ref>\d{6}-[A-Z0-9-]+)\h+[^\n]+~mu';
        $raw = "From: Auction <support@auction.test>\r\nDate: Mon, 01 Sep 2026 10:12:00 +0200\r\nSubject: Nouveaux articles\r\n\r\n"
            . "[https://email.alerts.auction.test/c/A1]\r\n[https://email.alerts.auction.test/c/A2]\r\n011218-259 FIAT PUNTO\r\n[https://email.alerts.auction.test/c/A3]\r\n";

        self::assertSame([], $this->fetch($params, $raw), 'a ref alone is a row nobody can read');
    }

    // ── furniture under link identity ────────────────────────────────────────────────────────

    /**
     * The furniture check used to be keyed on `price_pattern` being configured. With the price
     * coming from a `facts_pattern` group instead, a footer segment carrying a host link and no
     * facts would have become a phantom listing keyed on that link — the PAP `/utilisateur/alertes`
     * shape rebuilt. The content floor happens to catch it under `id_from: content`; this pins the
     * `link` path, which has no floor.
     */
    public function testAFooterSegmentIsFurnitureUnderLinkIdentityWhenThePriceComesFromFacts(): void
    {
        // The La Centrale shape on purpose: `Détails` splits the unsubscribe tail into a segment of
        // its own that carries a host link and no facts. (On the CapCar shape `link_after` and the
        // lookahead separator keep every furniture link out of reach, so a test there passed with
        // the guard deleted — the mutation loop said so.)
        $params = self::lacentraleParams();
        $params['id_from'] = 'link';

        $cars = $this->fetch($params, self::lacentraleMessage('LEXUS UX', '84 135', '22 990'));

        self::assertCount(1, $cars, 'the unsubscribe tail is furniture, not a car keyed on its own link');
        self::assertSame('price', $cars[0]->externalId, 'link identity: the last host link of the card\'s own segment');
    }

    // ── loader refusals: two providers for one fact ──────────────────────────────────────────

    public function testAnUnknownIdFromValueIsRefused(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessage('id_from');
        $this->load(['id_from' => 'contenu'] + self::capcarParams());
    }

    public function testAMakeModelPatternBesideAFactsMakeGroupIsRefused(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessage('make_model_pattern');
        $this->load(['make_model_pattern' => '~^(\S+)\h+(\S+)~u'] + self::capcarParams());
    }

    public function testAMakeModelSourceBesideAFactsMakeGroupIsRefused(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessage('make_model_source');
        $this->load(['make_model_source' => 'title', 'make_model_pattern' => '~^(\S+)\h+(\S+)~u'] + self::capcarParams());
    }

    public function testAPricePatternBesideAFactsPriceGroupIsRefused(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessage('price_pattern');
        $this->load(['price_pattern' => '~^\h*(\d[\d\h]*)\h*€\h*$~mu'] + self::capcarParams());
    }

    public function testATitlePatternBesideAFactsTitleOrMakeGroupIsRefused(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessage('title_pattern');
        $this->load(['title_pattern' => '~(.+)~'] + self::capcarParams());
    }

    public function testBothSeparatorsTogetherAreRefused(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessage('card_separator_pattern');
        $this->load(['card_separator' => 'Voir ce véhicule'] + self::capcarParams());
    }

    public function testAnUnreadableSeparatorPatternIsRefusedAtLoad(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessage('card_separator_pattern');
        $this->load(['card_separator_pattern' => '~(~'] + self::capcarParams());
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────

    /**
     * @param array<string, string> $params
     *
     * @return list<VehicleListing>
     */
    private function fetch(array $params, string $raw): array
    {
        return (new VehicleEmailSource($this->load($params), $this->mailbox($raw)))->fetch();
    }

    private function mailbox(string $raw): Mailbox
    {
        return new class([$raw]) implements Mailbox {
            /** @param list<string> $messages */
            public function __construct(private readonly array $messages) {}

            public function fetchRecent(int $limit = 50): array
            {
                return $this->messages;
            }

            public function describe(): string
            {
                return 'inline';
            }

            public function newestMessageAt(): ?string
            {
                return null;
            }

            public function claim(int $position): void
            {
            }

            public function acknowledge(): void
            {
            }
        };
    }

    /** @param array<string, string> $params */
    private function load(array $params): VehicleSourceDefinition
    {
        $all = VehicleSourceLoader::fromArray(['sources' => ['synthetic' => [
            'enabled' => true,
            'family' => 'portal',
            'type' => 'email_alert',
            'params' => $params,
        ]]]);

        return $all['synthetic'];
    }
}
