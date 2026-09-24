<?php

declare(strict_types=1);

namespace Scout\Car;

use Dom\Element;
use Dom\HTMLDocument;
use Scout\Adapters\Http\HttpClient;
use Scout\Adapters\Http\HttpRequest;
use Scout\Adapters\Http\Robots;
use Scout\Adapters\SourceError;
use Scout\Core\CountsPatternMisses;
use Scout\Core\PatternMissLog;
use Scout\Core\SourceHealth;

/**
 * Alcopa Auction — the saved search, polled, because its alert fails auction rule 2.
 *
 * The alert (measured 2026-09-24) carries no closing time, no price, no per-lot link, and shows
 * the same three cars for six days, so a lot announced from it could never say when it stops
 * being worth opening. The site is server-rendered and its robots allow the search and lot
 * pages, so this polls what the alert only samples. Three kinds of page, each with one job:
 *
 * - The SEARCH (the developer's saved search, `url`) is the index: 20 cards a page, walked to the
 *   count the first page states and CHECKED against it. Each card carries the facts and a per-lot
 *   countdown, `data-ts`.
 * - The LOT PAGE, fetched only for a lot NOT yet in the car seen-set (`lot_budget_per_pass`,
 *   `rate_limit_ms`), is where the classifier's evidence lives — its `Informations` and
 *   `Commentaires` blocks (*« Véhicule grêlé »*, *« Carte grise sous 30 jours ouvrés »*). Skipping it
 *   would switch the excluded-vehicle set off on this source, since the card never states any of
 *   that. It names the lot's ONE sale and states that sale's start.
 * - The SALE PAGE, fetched once per pass per LIVE sale, states the saleroom window's end.
 *
 * THE CLOSING, which auction rule 2 makes mandatory. An ONLINE lot closes at its card `data-ts`: it
 * equals the sale's Flash on 20/20 measured cards and is per LOT, so it is the more precise figure.
 * A LIVE lot is sold in the saleroom within the window, so it opens at the lot page's instant and
 * closes at the window's end; its `data-ts` sits 30 min before the room opens and its meaning is
 * unstated, so it is never rendered. A lot this cannot place — no sale, two sales, no instant, a
 * window whose day disagrees with the lot page, a closing already past — is WARNED and not
 * returned: rule 2 refuses it rather than announcing it without a time. A card whose `data-ts` is
 * already past is dropped before any lot fetch, so an ended sale costs nothing.
 *
 * Price is NULL by design: `Mise à prix` and `Enchère courante` are not what the car sells for, so
 * the price ceiling never fires here and both figures ride in `fields`. The storage site is a
 * saleroom name, never a postcode, so the location filter never fires either (hard rule 9).
 */
final readonly class AlcopaVehicleSource implements CountsPatternMisses, IndexedVehicleSource
{
    private const string LOT_PATH = '~^/(?:voiture|utilitaire|materiel)-occasion/[a-z0-9-]+/[a-z0-9-]+-(\d+)$~';
    private const string ONLINE_SALE = '~^/vente-encheres-en-ligne/\d+$~';
    private const string LIVE_SALE = '~^/salle-de-vente-encheres/[a-z0-9-]+/\d+$~';
    private const int MAX_PAGES = 60;

    /**
     * Measured against each card's own title words (2026-09-24): EE on every PHEV, EH on the
     * non-rechargeable hybrids, EG on the one GPL Meriva. `XX` — and any code not here — is unknown,
     * never `autre`: a code this source has not been seen to use is not a fact about the car.
     */
    private const array FUEL = ['ES' => 'essence', 'GO' => 'diesel', 'EL' => 'electrique', 'EE' => 'hybride', 'EH' => 'hybride', 'EG' => 'gpl'];

    private const array MONTHS = [
        'janvier' => 1, 'fevrier' => 2, 'mars' => 3, 'avril' => 4, 'mai' => 5, 'juin' => 6,
        'juillet' => 7, 'aout' => 8, 'septembre' => 9, 'octobre' => 10, 'novembre' => 11, 'decembre' => 12,
    ];

    /**
     * @param ?\Closure(string): void $warn
     * @param ?\Closure(int): void    $sleeper milliseconds
     * @param ?\Closure(): int        $clock   epoch seconds
     */
    public function __construct(
        private VehicleSourceDefinition $definition,
        private VehicleStore $store,
        private HttpClient $client,
        private Robots $robots,
        private ?\Closure $warn = null,
        private ?\Closure $sleeper = null,
        private ?\Closure $clock = null,
        private IndexSize $lastIndexSize = new IndexSize(),
        private PatternMissLog $patternMisses = new PatternMissLog(),
    ) {}

    public function name(): string
    {
        return $this->definition->name;
    }

    public function family(): string
    {
        return $this->definition->family;
    }

    public function host(): ?string
    {
        return $this->definition->url === null ? null : (parse_url($this->definition->url, PHP_URL_HOST) ?: null);
    }

    public function health(?string $nowIso = null): SourceHealth
    {
        return $this->patternMisses->escalate($this->store->runs()->health($this->name(), $nowIso));
    }

    public function patternMisses(): PatternMissLog
    {
        return $this->patternMisses;
    }

    public function lastIndexSize(): ?int
    {
        return $this->lastIndexSize->value;
    }

    /** @return list<VehicleListing> every lot the search lists today, bare — the seed records them without a lot fetch */
    public function seedIndex(): array
    {
        $out = [];
        foreach ($this->index() as $key => $card) {
            $id = (string) $key;
            $out[] = new VehicleListing(sourceName: $this->name(), externalId: $id, title: $card['title'], url: $card['url']);
        }

        return $out;
    }

    public function fetch(): array
    {
        $this->patternMisses->reset();

        $cards = $this->index();
        $known = $this->store->knownExternalIds($this->name());
        $now = $this->now();
        $budget = $this->definition->lotBudgetPerPass;
        /** @var array<string, ?array{day: string, end: string}> $sales */
        $sales = [];
        $out = [];
        $fetched = 0;
        $ended = 0;

        foreach ($cards as $key => $card) {
            $id = (string) $key;
            if (isset($known[$id])) {
                continue;
            }
            if ($card['countdown'] !== null && $card['countdown'] <= $now) {
                ++$ended;
                continue;
            }
            if ($fetched >= $budget) {
                break;
            }
            ++$fetched;
            $lot = $this->lotPage($card['url']);
            if ($lot === null) {
                continue;
            }
            $closing = $this->closing($id, $card, $lot, $sales, $now);
            if ($closing === null) {
                continue;
            }
            $out[] = $this->listing($id, $card, $lot['description'], $closing[0], $closing[1]);
        }
        if ($ended > 0) {
            $this->say(sprintf('%d lot(s) dont le compte à rebours est échu — ignorés sans les ouvrir', $ended));
        }

        return $out;
    }

    /**
     * Every card the saved search lists, by lot id, in page order — checked against the count the
     * first page states, because walking until a page comes back empty is a termination rule and
     * not a proof (the `total_selector` rule the rent html adapter already carries).
     *
     * @return array<string, array{url: string, title: string, make: ?string, model: ?string, version: string, facts: list<string>, site: ?string, lot: ?string, date: ?string, fields: array<string, string>, countdown: ?int}>
     */
    public function index(): array
    {
        $url = (string) $this->definition->url;
        [$first, $stated] = $this->searchPage($url, true);
        $pageSize = count($first);
        if ($pageSize === 0 && $stated > 0) {
            throw new SourceError($this->name(), sprintf('la recherche annonce %d lots et la première page n\'en montre aucun — gabarit de carte changé, refusé', $stated));
        }
        $pages = $pageSize === 0 ? 1 : (int) ceil($stated / $pageSize);
        if ($pages > self::MAX_PAGES) {
            throw new SourceError($this->name(), sprintf('%d pages annoncées, au-delà de la borne de %d — recherche trop large, refusée', $pages, self::MAX_PAGES));
        }

        $cards = $first;
        $lastShort = $pages === 1;
        for ($page = 2; $page <= $pages; ++$page) {
            $this->pace();
            [$more] = $this->searchPage($url . (str_contains($url, '?') ? '&' : '?') . 'page=' . $page, false);
            $cards += $more;
            $lastShort = count($more) < $pageSize;
        }

        // A shortfall under one page is lots leaving between two page fetches; from one page up it is
        // a LOST page, and a pass that reports 40 of 249 looks exactly like a thin market.
        $tolerance = $lastShort ? $pageSize - 1 : 0;
        if (count($cards) + $tolerance < $stated) {
            throw new SourceError($this->name(), sprintf('%d lots lus pour %d annoncés — la pagination a perdu des pages', count($cards), $stated));
        }
        $this->lastIndexSize->value = count($cards);

        return $cards;
    }

    /** @return array{0: array<string, array<string, mixed>>, 1: int} */
    private function searchPage(string $url, bool $first): array
    {
        $html = $this->get($url, true);
        $stated = 0;
        if ($first) {
            if (preg_match('~(\d[\d\h\x{202F}\x{A0}]*)</b>\s*R(?:é|&eacute;)sultat~u', $html, $m) !== 1) {
                throw new SourceError($this->name(), 'la recherche n\'annonce plus son nombre de résultats — sans lui la pagination n\'est pas vérifiable, refusé');
            }
            $stated = (int) preg_replace('~\D~', '', $m[1]);
        }

        $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
        $cards = [];
        foreach ($document->querySelectorAll('div.card') as $node) {
            $link = $node->querySelector('.card-title a');
            if (!$link instanceof Element) {
                continue;
            }
            $card = $this->card($node, $link);
            if ($card !== null) {
                $cards[$card['id']] = $card['card'];
            }
        }

        return [$cards, $stated];
    }

    /** @return ?array{id: string, card: array<string, mixed>} */
    private function card(Element $node, Element $link): ?array
    {
        $href = (string) $link->getAttribute('href');
        $ok = preg_match(self::LOT_PATH, $href, $m) === 1;
        $this->patternMisses->record('lot_link', $ok);
        if (!$ok) {
            return null;
        }

        $heading = self::text($link);
        $parts = array_map('trim', explode('|', $heading, 2));
        $this->patternMisses->record('title', $heading !== '');
        $version = self::text($node->querySelector('.card-text p.mb-2'));

        $facts = [];
        $factsNode = $node->querySelector('.card-text p.mb-1');
        if ($factsNode instanceof Element) {
            foreach ($factsNode->childNodes as $child) {
                if ($child->nodeType === XML_TEXT_NODE) {
                    $line = trim(preg_replace('~\s+~u', ' ', (string) $child->textContent) ?? '');
                    if ($line !== '') {
                        $facts[] = $line;
                    }
                }
            }
        }
        $this->patternMisses->record('facts', $facts !== []);

        $fields = [];
        $site = self::text($node->querySelector('p[title="Lieu de stockage"]'));
        foreach ($node->querySelectorAll('.card-footer p') as $p) {
            $t = self::text($p);
            if (preg_match('~^(Mise à prix|Enchère courante)\s*:\s*(.+?)\s*€$~u', $t, $g) === 1) {
                $fields[$g[1] === 'Mise à prix' ? 'mise_a_prix' : 'enchere_courante'] = $g[2];
            }
        }
        $lot = null;
        foreach ($node->querySelectorAll('.card-footer p') as $p) {
            if (preg_match('~^Lot n°\s*(\d+)$~u', self::text($p), $g) === 1) {
                $lot = $g[1];
            }
        }
        $date = self::text($node->querySelector('p[title="Date vente"]'));
        $ts = $node->querySelector('.countdown-time[data-ts]');
        $countdown = $ts instanceof Element && ctype_digit((string) $ts->getAttribute('data-ts')) ? (int) $ts->getAttribute('data-ts') : null;
        $this->patternMisses->record('countdown', $countdown !== null);

        if ($site !== '') {
            $fields['site'] = $site;
        }
        if ($lot !== null) {
            $fields['lot'] = $lot;
        }
        if ($date !== '') {
            $fields['date_vente'] = $date;
        }

        return ['id' => $m[1], 'card' => [
            'url' => 'https://' . $this->host() . $href,
            'title' => trim((string) preg_replace('~\s+~u', ' ', str_replace('|', ' ', $heading) . ' ' . $version)),
            'make' => VehicleFacts::fold($parts[0] !== '' ? $parts[0] : null),
            'model' => VehicleFacts::fold(($parts[1] ?? '') !== '' ? $parts[1] : null),
            'version' => $version,
            'facts' => $facts,
            'site' => $site !== '' ? $site : null,
            'lot' => $lot,
            'date' => $date !== '' ? $date : null,
            'fields' => $fields,
            'countdown' => $countdown,
        ]];
    }

    /** @return ?array{sale: ?string, live: bool, opens: ?string, description: string} */
    private function lotPage(string $url): ?array
    {
        $this->pace();
        $html = $this->get($url, false);
        if ($html === null) {
            return null;
        }
        $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);

        $salesSeen = [];
        foreach ($document->querySelectorAll('a[href]') as $a) {
            $href = (string) $a->getAttribute('href');
            if (preg_match(self::ONLINE_SALE, $href) === 1 || preg_match(self::LIVE_SALE, $href) === 1) {
                $salesSeen[$href] = true;
            }
        }
        $this->patternMisses->record('sale_link', $salesSeen !== []);
        if (count($salesSeen) !== 1) {
            $this->say(sprintf('%s : %d vente(s) nommée(s) — heure de clôture indéterminable, lot ignoré cette passe', $url, count($salesSeen)));

            return null;
        }
        $sale = (string) array_key_first($salesSeen);

        $opens = null;
        foreach ($document->querySelectorAll('div.bg-graylight li') as $li) {
            if (preg_match('~^\d\d-\d\d-\d{4} \d\d:\d\d:\d\d$~', self::text($li)) === 1) {
                $opens = self::parisToUtc(self::text($li), '!d-m-Y H:i:s');
                break;
            }
        }
        $this->patternMisses->record('sale_start', $opens !== null);

        // ONLY the two blocks that describe THIS car. The page around them is furniture — guides,
        // warranty, footer — and a furniture word reaching the classifier is a verdict about the
        // site rather than the lot (the CDC `au plus près` class, on a new surface).
        $description = [];
        foreach ($document->querySelectorAll('h3') as $h3) {
            $label = self::text($h3);
            if (($label === 'Informations' || $label === 'Commentaires') && $h3->parentElement instanceof Element) {
                $body = trim(substr(self::text($h3->parentElement), strlen($label)));
                if ($body !== '') {
                    $description[] = $label . ' : ' . $body;
                }
            }
        }

        return ['sale' => $sale, 'live' => preg_match(self::LIVE_SALE, $sale) === 1, 'opens' => $opens, 'description' => implode("\n", $description)];
    }

    /**
     * @param array<string, mixed>                                    $card
     * @param array{sale: ?string, live: bool, opens: ?string, description: string} $lot
     * @param array<string, ?array{day: string, end: string}>         $sales
     *
     * @return ?array{0: ?string, 1: string} [opens, closes], UTC ISO-8601
     */
    private function closing(string $id, array $card, array $lot, array &$sales, int $now): ?array
    {
        $opens = $lot['opens'];
        if ($opens === null) {
            $this->say(sprintf('lot %s : la page ne donne pas l\'heure de sa vente — ignoré cette passe', $id));

            return null;
        }

        if (!$lot['live']) {
            if ($card['countdown'] === null) {
                $this->say(sprintf('lot %s : vente en ligne sans compte à rebours — heure de clôture inconnue, ignoré', $id));

                return null;
            }
            $closes = gmdate('Y-m-d\TH:i:s\Z', $card['countdown']);
        } else {
            $sale = (string) $lot['sale'];
            if (!array_key_exists($sale, $sales)) {
                $sales[$sale] = $this->saleWindow($sale);
            }
            $window = $sales[$sale];
            if ($window === null) {
                return null;
            }
            if ($window['day'] !== self::parisDay($opens)) {
                $this->say(sprintf('lot %s : la page du lot et celle de la vente %s ne donnent pas le même jour — ignoré', $id, $sale));

                return null;
            }
            $closes = self::parisToUtc($window['day'] . ' ' . $window['end'], '!Y-m-d H:i');
            if ($closes === null) {
                return null;
            }
        }

        if ($closes <= $opens) {
            $this->say(sprintf('lot %s : clôture %s avant l\'ouverture %s — refusé', $id, $closes, $opens));

            return null;
        }
        if (strtotime($closes) <= $now) {
            $this->say(sprintf('lot %s : clôture %s déjà passée — non annoncé', $id, $closes));

            return null;
        }

        return [$opens, $closes];
    }

    /** @return ?array{day: string, end: string} the saleroom day (Europe/Paris, Y-m-d) and the window's end (H:i) */
    private function saleWindow(string $path): ?array
    {
        $this->pace();
        $html = $this->get('https://' . $this->host() . $path, false);
        if ($html === null) {
            return null;
        }
        $hours = preg_match('~Horaires\s*:\s*<b>\s*(\d\d:\d\d)\s*</b>\s*-\s*<b>\s*(\d\d:\d\d)\s*</b>~u', $html, $h) === 1;
        $day = null;
        $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
        foreach ($document->querySelectorAll('p.font-weight-bold') as $p) {
            if (preg_match('~^\p{L}+\s+(\d{1,2})\s+(\p{L}+)\s+(\d{4})\b~u', self::text($p), $d) === 1) {
                $month = self::MONTHS[(string) VehicleFacts::fold($d[2])] ?? null;
                if ($month !== null && checkdate($month, (int) $d[1], (int) $d[3])) {
                    $day = sprintf('%04d-%02d-%02d', (int) $d[3], $month, (int) $d[1]);
                }
                break;
            }
        }
        $this->patternMisses->record('horaires', $hours && $day !== null);
        if (!$hours || $day === null) {
            $this->say(sprintf('vente %s : jour ou horaires illisibles — ses lots sont ignorés cette passe', $path));

            return null;
        }

        return ['day' => $day, 'end' => $h[2]];
    }

    /**
     * @param array<string, mixed> $card
     */
    private function listing(string $id, array $card, string $description, ?string $opens, string $closes): VehicleListing
    {
        $fuel = null;
        $year = null;
        $km = null;
        $gearbox = null;
        foreach ($card['facts'] as $line) {
            if (preg_match('~^[A-Z]{2}$~', $line) === 1) {
                $fuel = self::FUEL[$line] ?? null;
            } elseif (preg_match('~^1(?:ère|re)\s+mise\s*:\s*(\d{4})$~u', $line, $g) === 1) {
                $year = (int) $g[1];
            } elseif (preg_match('~^(\d[\d\h\x{202F}\x{A0}]*)\s*km$~iu', $line, $g) === 1) {
                $km = (int) preg_replace('~\D~', '', $g[1]);
            } elseif (preg_match('~^Boîte\s+(.+)$~u', $line, $g) === 1) {
                $gearbox = VehicleFacts::gearbox($g[1]);
            }
        }

        return new VehicleListing(
            sourceName: $this->name(),
            externalId: $id,
            title: $card['title'],
            description: $description,
            fields: $card['fields'],
            url: $card['url'],
            make: $card['make'],
            model: $card['model'],
            year: $year,
            mileageKm: $km,
            fuel: $fuel,
            gearbox: $gearbox,
            saleOpensAt: $opens,
            closingAt: $closes,
        );
    }

    /** A 2xx body, or — for a lot or sale page — null after a warning. The SEARCH throws: it is the index. */
    private function get(string $url, bool $index): ?string
    {
        if (!$this->robots->allows(Robots::pathOf($url))) {
            throw new SourceError($this->name(), sprintf('%s est refusé par robots.txt — this source must not be polled', $url));
        }
        $response = $this->client->send(new HttpRequest($url));
        if ($response->isSuccess()) {
            return $response->body;
        }
        if ($index) {
            throw new SourceError($this->name(), sprintf('HTTP %d from %s', $response->status, $url));
        }
        $this->say(sprintf('HTTP %d sur %s — ignoré cette passe', $response->status, $url));

        return null;
    }

    private function pace(): void
    {
        $ms = $this->definition->rateLimitMs;
        if ($ms <= 0) {
            return;
        }
        if ($this->sleeper !== null) {
            ($this->sleeper)($ms);

            return;
        }
        usleep($ms * 1000);
    }

    private function now(): int
    {
        return $this->clock !== null ? ($this->clock)() : time();
    }

    private function say(string $message): void
    {
        ($this->warn)?->__invoke($this->name() . ' : ' . $message);
    }

    private static function text(?Element $node): string
    {
        return $node === null ? '' : trim(preg_replace('~\s+~u', ' ', (string) $node->textContent) ?? '');
    }

    /** Europe/Paris wall-clock to a UTC instant, strict by round-trip; null when it will not parse. */
    private static function parisToUtc(string $local, string $format): ?string
    {
        $zone = new \DateTimeZone('Europe/Paris');
        $at = \DateTimeImmutable::createFromFormat($format, $local, $zone);
        if ($at === false || $at->format(ltrim($format, '!')) !== $local) {
            return null;
        }

        return $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    /** The Europe/Paris calendar day of a UTC instant. */
    private static function parisDay(string $utc): string
    {
        return (new \DateTimeImmutable($utc))->setTimezone(new \DateTimeZone('Europe/Paris'))->format('Y-m-d');
    }
}
