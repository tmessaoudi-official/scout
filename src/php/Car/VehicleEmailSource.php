<?php

declare(strict_types=1);

namespace Scout\Car;

use Scout\Adapters\AcknowledgesMessages;
use Scout\Adapters\FeedFreshness;
use Scout\Adapters\Mail\EmailMessage;
use Scout\Adapters\Mail\Mailbox;
use Scout\Adapters\Mail\MailboxError;
use Scout\Adapters\SourceError;
use Scout\Core\CountsPatternMisses;
use Scout\Core\MalformedText;
use Scout\Core\PatternMissLog;
use Scout\Core\SourceHealthService;
use Scout\Core\Text;

/**
 * A car portal's saved-search alert, read from a mailbox — the rent side's `EmailAlertSource`
 * rebuilt for cars, with the lessons that adapter paid for already applied:
 *
 * - **every reader is POSITIONAL**, anchored on the card's own layout (a `€` line, a facts line
 *   `<body> - <fuel> - Année <year> - <km> km`), never a body-wide scan: the alert quotes the
 *   subscriber's own criteria above the cards (`Jusqu'à 30 000 € … Jusqu'à 100 000 km`) and a
 *   first-match reader returns the search floor — PAP's defect, on the day it was built;
 * - **the subject is part of the source's scope**: one sender serves the rent alert, the car alert
 *   and marketing, so `params.from` alone admits three kinds of message;
 * - **identity is the ad link** (`/a/voiture-occasion/<make>/<model>/<id>`), the LAST matching link
 *   in a segment, which is the card's own; make and model are read off the same path;
 * - **a listing is observed when its message was sent** — `observedAt` from `sentAt()` — so a
 *   re-read older message is a superseded sighting at the store, never a price drop.
 *
 * A card whose price line is missing still yields a listing with `priceEur = null` (unknown is
 * not zero); a segment with no ad link yields nothing — it is the header or the footer.
 */
final readonly class VehicleEmailSource implements AcknowledgesMessages, CountsPatternMisses, VehicleSource, FeedFreshness
{
    /** @param ?\Closure(string): void $warn */
    public function __construct(
        private readonly VehicleSourceDefinition $definition,
        private readonly Mailbox $mailbox,
        private readonly ?\Closure $warn = null,
        private readonly int $limit = 50,
        /**
         * F27: THIS DOMAIN COUNTED NOTHING UNTIL 2026-09-01, and the gap was built by the very fix
         * that exists to prevent it. `PatternMissLog` shipped for the rent email adapter on
         * 2026-08-31 as Track 1h's answer to PAP running four days with both positional patterns
         * dead — and it landed on one adapter of five. Measured at the time: 13 of 99 stored
         * ParuVendu rows carry `body`, `fuel`, `year` and `mileageKm` ALL null — the identical count
         * on four fields being one `facts_pattern` miss rather than four absences — while
         * `scout --domain=car doctor` reported `ok`.
         *
         * Mutable held by a `readonly` source, exactly as on the rent side: the property cannot be
         * reassigned, the counter it points at can be written.
         */
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
        return null;
    }

    public function newestFeedItemAt(): ?string
    {
        return $this->mailbox->newestMessageAt();
    }

    public function fetch(): array
    {
        // A COUNT NEVER SPANS TWO FETCHES. A miss rate is a property of the pass in hand, so a log
        // carried over would report a template that has since been fixed and send someone to read a
        // capture that is fine. Same contract `CountsPatternMisses` states.
        $this->patternMisses->reset();

        try {
            $messages = $this->mailbox->fetchRecent($this->limit);
        } catch (MailboxError $e) {
            throw new SourceError($this->name(), $e->getMessage(), $e);
        }

        $from = strtolower((string) $this->definition->param('from'));
        $subjectPattern = $this->definition->param('subject_pattern');
        $out = [];

        foreach ($messages as $position => $raw) {
            $message = EmailMessage::parse($raw);
            if ($from !== '' && !str_contains(strtolower($message->from()), $from)) {
                continue;
            }
            if ($subjectPattern !== null && preg_match($subjectPattern, $message->subject()) !== 1) {
                continue;
            }

            // CLAIMED: past both filters, this message is ours whatever its segments yield. Intent
            // only — the mark is written by acknowledge(), after the store has recorded the pass.
            $this->mailbox->claim($position);

            $segments = $this->segments($message);
            $seen = [];
            foreach ($segments as $segment) {
                // A SEGMENT IS NOT A CARD UNTIL `cardListing()` SAYS SO, and this source has a
                // DOCUMENTED furniture segment: the CTA link ending a card sits on the line after
                // the separator, so the tail segment carries that card's link and nothing else —
                // it re-yielded the last card on every message, six times per doctor run on
                // 2026-08-29. Counting the patterns it fails puts one permanent miss per message
                // into the denominator, which is the bienici `commune_pattern 117/364` dilution
                // that made `PatternMissLog::begin()` exist. `total()` fires only at 100 %, so a
                // diluted ratio is a pattern that has genuinely died reporting short of it, saying
                // nothing.
                $this->patternMisses->begin();
                $card = $this->cardListing($message, $segment);
                // A WITHIN-MESSAGE DUPLICATE COUNTS. Its patterns ran on a real card's text and
                // found what they found; that the id was already taken is an identity fact decided
                // one layer up, and dropping the attempts would understate the denominator on
                // exactly the source that emits duplicates.
                $this->patternMisses->resolve($card !== null);

                if ($card === null) {
                    continue;
                }
                if (isset($seen[$card->externalId])) {
                    ($this->warn)?->__invoke(sprintf('%s : annonce %s en double dans un même courrier — une seule gardée', $this->name(), $card->externalId));
                    continue;
                }
                $seen[$card->externalId] = true;
                $out[] = $card;
            }
        }

        return $out;
    }

    /** Row 36 — the rent twin's rule, verbatim: report a refusal, never fail the pass for a flag. */
    public function acknowledge(): void
    {
        try {
            $this->mailbox->acknowledge();
        } catch (MailboxError $e) {
            throw new SourceError($this->name(), 'marquage des courriers traités refusé — ' . $e->getMessage(), $e);
        }
    }

    /**
     * This source's own `feed_silent_days`, for `SourceHealthService`; `null` leaves the store's global one.
     *
     * Its miss log matters as much: A PATTERN THAT MATCHED NOTHING AT ALL IS A TEMPLATE CHANGE, and every
     * other verdict is blind to it — the state PAP ran in for four days on the rent side. The service
     * escalates it over the run log, one place for all three domains (steps 8e-1/8e-2).
     */
    public function feedSilentDays(): ?int
    {
        return $this->definition->feedSilentDays;
    }

    /** The per-pattern miss counts of the last fetch — `scout --domain=car doctor` prints them. */
    public function patternMisses(): PatternMissLog
    {
        return $this->patternMisses;
    }

    /**
     * ONE RECORDING IMPLEMENTATION for every card-level pattern this adapter reads.
     *
     * The rent twin funnels through `matchParam()` because its patterns all share one shape —
     * capture group 1 of a `preg_match`. These four do not: price wants `PREG_OFFSET_CAPTURE` to
     * locate the line, facts wants `PREG_SET_ORDER` for its named groups, title reads the SUBJECT
     * rather than the segment, and make/model reads whichever haystack `make_model_source` names.
     * Forcing them into one signature would distort four readers to spare one line each.
     *
     * So the call sites stay four and the SET is guarded by a test instead of by discipline:
     * {@see \Scout\Tests\Car\VehicleEmailPatternMissTest} reads `VehicleSourceLoader::PATTERN_PARAMS`
     * by REFLECTION, subtracts the unread ones and the message-level `subject_pattern`, and asserts
     * every remaining key is counted. A pattern param added and left uninstrumented fails there —
     * which is the only form of "five places to forget" that actually holds, because the guard is
     * set membership rather than someone remembering.
     *
     * `subject_pattern` is deliberately NOT counted: it decides whether a MESSAGE is ours at all, so
     * a miss means "skip this one", which is the filter working. Counting it would put every
     * unrelated mail in the denominator.
     */
    private function missed(string $key, bool $found): void
    {
        $this->patternMisses->record($key, $found);
    }

    /**
     * The segments of one message: one per card plus the header and footer furniture.
     *
     * `card_separator_pattern` (row 38) is the regex form, honoured IN PREFERENCE to the literal —
     * the loader refuses both together. A pattern that cannot run is a CONFIG fault, not an event:
     * every message would fail it identically, and returning the whole body as one segment would
     * silently merge every card, so it throws (the rent adapter's own rule). A zero-width
     * lookahead — `(?=Marque\x{00A0}:)` — keeps the label inside the segment it starts, which is
     * what lets a labelled-block `facts_pattern` begin at the block's first line.
     *
     * @return list<string>
     */
    private function segments(EmailMessage $message): array
    {
        $pattern = $this->definition->param('card_separator_pattern');
        if ($pattern !== null) {
            $segments = preg_split($pattern, $message->body);
            if ($segments === false) {
                throw new SourceError($this->name(), 'card_separator_pattern illisible : ' . $pattern);
            }

            return $segments;
        }

        $separator = $this->definition->param('card_separator');

        return $separator === null ? [$message->body] : explode($separator, $message->body);
    }

    private function cardListing(EmailMessage $message, string $segment): ?VehicleListing
    {
        $link = $this->adLinkIn($segment);
        if ($link === null) {
            return null;
        }

        $lines = preg_split('~\R~u', $segment) ?: [];
        $isUrl = static fn (string $l): bool => str_starts_with(trim($l), 'http://') || str_starts_with(trim($l), 'https://');

        // FACTS FIRST (row 38) — body, fuel, year, mileage, and on a labelled-block portal the make,
        // model, version, gearbox, price and title too, from the one pattern the portal's layout
        // fits. Moved above the price and title readers because a facts-derived title has to
        // exist before `make_model_source: title` and `gearboxFromTitle()` read `$title`; the
        // ParuVendu/leboncoin outcome is unchanged because their patterns carry none of the new
        // groups (their fixture tests are the proof).
        $body = $fuel = null;
        $year = $km = null;
        /** @var array<string, string> $facts the last match's named groups, empty when nothing matched */
        $facts = [];
        $factsMake = $factsModel = $factsVersion = $factsGearbox = $factsTitle = null;
        $factsPrice = null;
        $factsHasPrice = false;
        $factsPattern = $this->definition->param('facts_pattern');
        if ($factsPattern !== null) {
            $factsHasPrice = str_contains($factsPattern, '(?<price>') || str_contains($factsPattern, '(?P<price>');
            $hit = preg_match_all($factsPattern, $segment, $f, PREG_SET_ORDER) > 0;
            // THE ONE THAT PROVED THE GAP WAS REAL: 13 of 99 stored ParuVendu rows carry `body`,
            // `fuel`, `year` and `mileageKm` all null — one miss here, four fields dark, and
            // nothing said so.
            $this->missed('facts_pattern', $hit);

            if ($hit) {
                $facts = end($f);
                $body = self::foldOrNull($facts['body'] ?? null);
                $fuel = self::fuel($facts['fuel'] ?? null);
                $year = isset($facts['year']) && $facts['year'] !== '' ? (int) $facts['year'] : null;
                $km = isset($facts['km']) && $facts['km'] !== '' ? self::int($facts['km']) : null;
                $factsMake = self::foldOrNull($facts['make'] ?? null);
                $factsModel = self::foldOrNull($facts['model'] ?? null);
                $factsVersion = isset($facts['version']) ? trim($facts['version']) : null;
                $factsGearbox = isset($facts['gearbox']) ? VehicleFacts::gearbox($facts['gearbox']) : null;
                $factsTitle = isset($facts['title']) ? trim($facts['title']) : null;
                $factsPrice = isset($facts['price']) && $facts['price'] !== '' ? self::int($facts['price']) : null;
            }
        }

        // PRICE — the card's own `€` line, the last one in the segment (the header's criteria line
        // is not a bare price line and the anchored pattern never reads it) — or the `price` group
        // of the facts, on a portal that labels it (the loader refuses both providers at once).
        $price = $factsPrice;
        $priceLine = null;
        $pricePattern = $this->definition->param('price_pattern');
        if ($pricePattern !== null) {
            $hit = preg_match_all($pricePattern, $segment, $m, PREG_OFFSET_CAPTURE) > 0;
            $this->missed('price_pattern', $hit);

            if ($hit) {
                $last = end($m[1]);
                $price = self::int($last[0]);
                $priceLine = substr_count(substr($segment, 0, $last[1]), "\n");
            }
        }

        // TITLE — the facts' own `title` group; else COMPOSED from make + model + version on a
        // labelled block; else the SUBJECT when a pattern names it there; else the card's lines.
        //
        // **`title_pattern` WAS DECLARED AND UNREAD** (Track 1c), which is the inert-parameter
        // defect the rent side already paid for twice. It is read now because leboncoin needs it:
        // that portal states the vehicle in its SUBJECT — `<dealer> vous propose <MAKE MODEL …> à
        // <price> € à <Commune> (<postcode>)` — and puts nothing but the dealer's name, its rating
        // and `vous présente ses bonnes affaires :` above the price line. Without this the title
        // would be that last sentence, and `gearboxFromTitle()` reads the title, so a card
        // advertising `BOITE AUTOMATIQUE` would score as if it stated no gearbox at all.
        //
        // A CONFIGURED PATTERN THAT MISSES YIELDS `''`, never the positional fallback — the rule
        // `cardTitle()` on the rent side already carries. Falling back would restore the defect and
        // give it an alibi: the row would read as a car whose title is a marketing sentence rather
        // than as an extraction that failed.
        $title = '';
        $titlePattern = $this->definition->param('title_pattern');
        $fromSubject = $titlePattern !== null;
        $fromFacts = $factsTitle !== null || $factsMake !== null || $factsModel !== null;

        if ($fromFacts) {
            // Composed from the RAW captures, not the folded make/model: the title is what the
            // developer reads on the phone, and `Renault Clio Evolution` beats `renault clio`.
            $facts = end($f);
            $title = $factsTitle ?? trim(implode(' ', array_filter([
                trim((string) ($facts['make'] ?? '')),
                trim((string) ($facts['model'] ?? '')),
                (string) ($factsVersion ?? ''),
            ], static fn (string $p): bool => $p !== '')));
        } elseif ($fromSubject) {
            $title = preg_match($titlePattern, $message->subject(), $t) === 1 ? trim($t[1] ?? '') : '';
            // The MISS is `''`, whether the pattern failed or captured nothing — both are the same
            // fact for this signal, and the empty capture is the one that reads like a value.
            $this->missed('title_pattern', $title !== '');
        } elseif ($priceLine !== null) {
            for ($i = min($priceLine, count($lines) - 1) - 1; $i >= 0; $i--) {
                $l = trim($lines[$i]);
                if ($l !== '' && !$isUrl($l)) {
                    $title = $l;
                    break;
                }
            }
        }
        if ($title === '' && !$fromSubject && !$fromFacts) {
            foreach ($lines as $l) {
                $l = trim($l);
                if ($l !== '' && !$isUrl($l)) {
                    $title = $l;
                    break;
                }
            }
        }

        // FURNITURE, not a card. The CTA link that ends a card sits on the line AFTER the separator,
        // so the segment following the last card is the footer carrying that card's link and
        // nothing else — it re-yielded the last card on every message ("en double dans un même
        // courrier", six times per doctor run on 2026-08-29). When the portal lays out a price
        // (a `price_pattern` OR a facts `price` group — keyed on "a price provider is configured",
        // because the day the price moved into the facts the old `$pricePattern !== null` test
        // went false and a footer with a host link became a phantom listing under link identity)
        // and facts, and a segment has none of it, it is not a card.
        $priceConfigured = $pricePattern !== null || $factsHasPrice;
        if ($priceConfigured && $factsPattern !== null
            && $price === null && $body === null && $year === null && $km === null && !$fromFacts) {
            return null;
        }

        // MAKE / MODEL — from the facts' own groups on a labelled block, else from wherever
        // `make_model_source` names, and the SOURCE IS NAMED.
        //
        // ParuVendu encodes them in the ad path (`/voiture-occasion/<make>/<model>/`); leboncoin's
        // path is `/vi/<id>.htm` and states the make in the subject instead. `make_model_source`
        // says which, rather than trying the link and falling back to the title: a fallback would
        // let a pattern written for one haystack quietly match the other, which is how an
        // extraction failure acquires an alibi. Unconfigured means `link`, so ParuVendu is
        // unchanged byte for byte. The loader refuses `make_model_pattern` beside a facts `make`
        // group for the same reason — two providers, one honoured.
        //
        // IT MATTERS TO THE SCORE, not just to the display: `brand_avoid` is read off `make`, and
        // an unextracted make scores 0 on that component (Track 1d). A source that states its make
        // and does not map it would rank ten points below an identical car from a source that does.
        $make = $factsMake;
        $model = $factsModel;
        $mm = $this->definition->param('make_model_pattern');
        if ($mm !== null) {
            $haystack = $this->definition->param('make_model_source') === 'title' ? $title : $link;
            $hit = preg_match($mm, $haystack, $g) === 1;
            $this->missed('make_model_pattern', $hit);

            if ($hit) {
                $make = self::foldOrNull($g[1] ?? null);
                $model = self::foldOrNull($g[2] ?? null);
            }
        }

        // THE PORTAL'S OWN "I DON'T KNOW" TOKEN IS NOT A MAKE (Track 6-A4).
        //
        // ParuVendu writes `/voiture-occasion/autres/autres/` when it cannot name the marque. The
        // pattern captures it perfectly, so nothing above reads as a fault — and `autres` then
        // matches no `brand_avoid` stem, so the car earns the entire brand share. The live row was
        // `Ds Ds4 E-tense 225ch Performance Line`, a DS, which is ON the avoid list.
        //
        // NOT COUNTED AS A MISS, deliberately. The pattern HIT; the portal declared an unknown.
        // Counting it would dilute the ratio the WARN depends on with an expected non-extraction —
        // the `subject_pattern` ruling, and F30's shape.
        //
        // Both fields, because the row carries `model = autres` too. Applied AFTER the capture and
        // never as a fallback to another haystack — and applied WHICHEVER haystack the make came
        // from, the facts included: a labelled block writing `Marque : Autres` is the same token.
        $sentinel = $this->definition->param('make_model_unknown_pattern');
        if ($sentinel !== null) {
            if ($make !== null && preg_match($sentinel, $make) === 1) {
                $make = null;
                if ($fromFacts && $factsTitle === null) {
                    // The composed title must not carry the token either — `Autres Autres X` is
                    // not a name, and the title reaches the developer's phone.
                    $title = trim(implode(' ', array_filter([
                        $factsModel !== null && preg_match($sentinel, $factsModel) !== 1 ? trim((string) (end($f)['model'] ?? '')) : '',
                        (string) ($factsVersion ?? ''),
                    ], static fn (string $p): bool => $p !== '')));
                }
            }
            if ($model !== null && preg_match($sentinel, $model) === 1) {
                $model = null;
            }
        }

        // IDENTITY (row 37). `link`: the last path segment of the card's own ad link, as ParuVendu
        // and leboncoin have always been keyed. `content`: a hash of the car's STRUCTURAL facts —
        // the SeLoger discipline — for a portal whose every link is a per-recipient tracking
        // redirect, where `basename()` is a fresh identity per message and the same identity for
        // every card in one.
        //
        // **The price is deliberately absent from the key.** A price drop is an event this project
        // exists to detect; a price in the identity turns every drop into a brand-new car, notified
        // as new with no history. Title, year and mileage do not move when the copy is rewritten.
        //
        // **The no-information floor, car shape:** something that NAMES the car and something that
        // DESCRIBES it. A title alone would give every stripped card of one model a single
        // identity, so the second is treated as already seen; a year or a mileage alone is shared
        // by half a portal. Below it the segment is not a card — an extraction failure kept
        // visible in the count rather than hidden behind a row.
        //
        // **Stated cost:** two identical cars — same title, same year, same mileage — share one
        // identity, and on a portal that truncates the title (La Centrale, ~28 characters) the
        // mileage is all that separates two `RENAULT KANGOO II EXPRESS p...`.
        // A `ref` GROUP IS A REAL ID (Track 6-B3, 2026-09-05). An auction house prints its lot
        // reference on every card (`011218-259`, `014684-S-4361`) while its links are Mailgun
        // redirects; a stated id beats a hash of facts, and it needs no describing fact beside it —
        // three of five Agorastore cards carry no year and no mileage, and the floor would drop
        // them for lack of evidence that the ref already supplies. The title must still be there:
        // a ref alone is a row nobody can read.
        $ref = isset($facts['ref']) ? trim((string) $facts['ref']) : '';
        if ($this->definition->param('id_from') === 'content' && $ref !== '') {
            if ($title === '') {
                return null;
            }
            $id = $ref;
        } elseif ($this->definition->param('id_from') === 'content') {
            if ($title === '' || ($year === null && $km === null)) {
                return null;
            }
            $id = sha1(implode('|', [
                $this->name(),
                (string) self::foldOrNull($title),
                $year === null ? '' : (string) $year,
                $km === null ? '' : (string) $km,
            ]));
        } else {
            $path = (string) (parse_url($link, PHP_URL_PATH) ?? '');
            $id = basename(rtrim($path, '/'));
            if ($id === '' || $id === '/') {
                return null;
            }
        }

        return new VehicleListing(
            sourceName: $this->name(),
            externalId: $id,
            title: $title,
            description: trim(preg_replace('~https?://\S+~', '', $segment) ?? $segment),
            fields: ['email.from' => $message->from(), 'email.subject' => $message->subject()],
            url: preg_replace('~[?#].*$~', '', $link),
            make: $make,
            model: $model,
            priceEur: $price,
            year: $year,
            mileageKm: $km,
            fuel: $fuel,
            gearbox: $factsGearbox ?? self::gearboxFromTitle($title),
            body: $body,
            observedAt: $message->sentAt(),
        );
    }

    /**
     * The card's own ad link.
     *
     * Without `link_after`: the LAST link on the ad host in the segment — the header's links precede
     * the card, and on ParuVendu and leboncoin the card's CTA is the last host link before the
     * separator. With `link_after` (row 38): the FIRST host link after that marker's first
     * occurrence in the segment, because on a portal whose EVERY link is on one tracking host —
     * CapCar: banner, four CTAs, footer, all `sendibt3.com` — the last card's segment also holds the
     * footer's links, and "last" would hand the developer an unsubscribe redirect from a push. A
     * segment without the marker is not a card.
     */
    private function adLinkIn(string $segment): ?string
    {
        $host = $this->definition->param('link_host');
        if ($host === null) {
            return null;
        }

        $after = $this->definition->param('link_after');
        $haystack = $segment;
        if ($after !== null) {
            $at = strpos($segment, $after);
            if ($at === false) {
                return null;
            }
            $haystack = substr($segment, $at + strlen($after));
        }

        if (preg_match_all('~https?://\S+~', $haystack, $m) === 0) {
            return null;
        }
        $onHost = static function (string $candidate) use ($host): bool {
            $bare = preg_replace('~^https?://~', '', $candidate) ?? $candidate;

            return str_starts_with($bare, $host) || str_starts_with($bare, 'www.' . $host);
        };
        foreach ($after !== null ? $m[0] : array_reverse($m[0]) as $candidate) {
            if ($onHost($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private static function int(string $digits): ?int
    {
        return VehicleFacts::int($digits);
    }

    private static function foldOrNull(?string $raw): ?string
    {
        return VehicleFacts::fold($raw);
    }

    public static function fuel(?string $raw): ?string
    {
        return VehicleFacts::fuel($raw);
    }

    public static function gearboxFromTitle(string $title): ?string
    {
        return VehicleFacts::gearbox($title);
    }
}
