<?php

declare(strict_types=1);

namespace Scout\Rent\Adapters;

use Scout\Adapters\Mail\EmailMessage;
use Scout\Adapters\Mail\Mailbox;
use Scout\Adapters\Mail\MailboxError;
use Scout\Rent\Config\SourceDefinition;
use Scout\Rent\Core\RawListing;
use Scout\Core\CountsPatternMisses;
use Scout\Core\PatternMissLog;
use Scout\Core\SourceHealth;
use Scout\Core\SourceHealthService;
use Scout\Rent\Core\SourceProfile;
use Scout\Rent\Core\Tenure;
use Scout\Core\Text;
use Scout\Adapters\AcknowledgesMessages;
use Scout\Adapters\FeedFreshness;
use Scout\Adapters\SourceError;

/**
 * Turns portal alert emails into listings. The primary path for private portals (hard rule 4).
 *
 * **THIS IS DELIBERATELY CONSERVATIVE, and the reason is worth stating up front.** No real portal
 * alert has been seen yet, so every extraction rule here is written against generic structures — a
 * link per listing, a rent that looks like a rent, a commune from the configured list — rather than
 * against any one portal's layout. It is built to be SHAPED by a real message, not to guess one:
 * the moment an actual alert lands in `tests/fixtures/rent/<portal>/`, its quirks become a fixture and
 * this class grows a per-portal override under `Adapters/sites/`.
 *
 * The consequence is honest and stated: **a listing this parser cannot read confidently gets no
 * tenure signal, so on a `mixed_tenure` source it DIGESTS rather than matching.** That is the
 * fail-closed direction, and it means an under-powered parser costs digest entries rather than
 * wrong notifications.
 *
 * What the class is NOT allowed to do, ever: follow the links to scrape the portal page. That is
 * the scraping route hard rule 4 gates behind an explicit flag, and doing it invisibly from the
 * email path would route around the gate entirely.
 */
final readonly class EmailAlertSource implements AcknowledgesMessages, CountsPatternMisses, FeedFreshness, Source
{
    /**
     * Where a rent lives in an alert. Ordered: the most explicit form wins.
     *
     * Accent-folded input is NOT assumed here — this reads the raw message text, before folding,
     * because the folded surface is the classifier's and this is extraction.
     *
     * **THE SEPARATOR IS `\h`, NEVER `\s`, AND THE DIFFERENCE IS A WRONG RENT.** French rents are
     * written `1 450 €` with a narrow no-break space, so the digit class has to admit whitespace as
     * a thousands separator — and it did that with `\s`, which also matches `\n`. A line ending in
     * a digit therefore glues itself to the price below it, and `Payload::int` truncates at the
     * newline, so the rent becomes whatever sat on the previous line: `ref 850` above
     * `1 450 EUR charges comprises` extracts **850** [Verified 2026-08-25], six hundred euros below
     * reality, inside the plausibility band, clearing a ceiling the real rent does not.
     *
     * `\h` under `/u` already covers U+00A0 and U+202F, which is what the explicit escapes were
     * for. The trailing `\s*` before the currency marker stays `\h*` for the same reason — a figure
     * and a currency sign on two different lines are not one price.
     */
    /**
     * Most specific first — the first pattern to yield a PLAUSIBLE figure wins.
     *
     * The third entry is the reason the order matters, and it was added on 2026-08-25 after a live
     * *"Baisse de prix"* alert. That template quotes three amounts: the reduction
     * (`baissé de 100 €`), the new rent (`1 100 €/mois`) and the struck-through old one
     * (`1 200 € ↘ 8%`). Only one of the three carries a PERIOD, and it is the only one that is a
     * rent — so a periodic amount without `charges comprises` outranks a bare figure, and the bare
     * figure stays last for cards that state nothing else.
     *
     * Without it the reader returned the DISCOUNT. Here that was 100 €, below the plausibility
     * floor, so the card was refused and the source reported `broken` on an unchanged template —
     * the benign direction. A 300 € reduction would have been returned as the rent: inside the
     * band, six hundred euros wrong, clearing a ceiling the flat comes nowhere near.
     */
    private const array RENT_PATTERNS = [
        '~(\d[\d\h.,]{2,})\h*(?:€|EUR|euros?)\h*(?:/\h*mois|par mois|mensuel)?\h*(?:CC|charges comprises)~iu',
        '~(?:loyer|prix)\h*(?:CC|charges comprises)?\h*:?\h*(\d[\d\h.,]{2,})\h*(?:€|EUR|euros?)~iu',
        '~(\d[\d\h.,]{2,})\h*(?:€|EUR|euros?)\h*(?:/\h*mois|par mois|mensuel)~iu',
        '~(\d[\d\h.,]{2,})\h*(?:€|EUR|euros?)~iu',
    ];

    // The left anchor is `ROOMS_PATTERN`'s, for the same reason and one alphabet later. A surface
    // is a figure someone WROTE; a digit in the middle of a token — an image hash, a slug, a
    // base64url run — is not, and `preg_match` is first-match-wins, so the first such run beats a
    // real figure further down the card. The query is already stripped before the generic readers
    // run (`9ea9d77`); the PATH is deliberately kept, because a `plai` path segment is a real §1
    // signal while a campaign string is not — so the path is exactly where this still bit.
    private const string SURFACE_PATTERN = '~(?<![A-Za-z0-9])(\d{1,4}(?:[.,]\d{1,2})?)\s*(?:m²|m2|m\^2)~iu';

    /**
     * THE `T3` BRANCH NEEDS A LEFT ANCHOR, and for a month it had only a right one (Track 1j).
     *
     * `(?:T|F)\s?(\d)\b` is case-insensitive and bounded only AFTER the digit, so it matched any
     * `t`/`f` followed by a digit — and every alert card opens with image URLs full of hexadecimal
     * UUIDs. `preg_match` is first-match-wins and the photo sits near the TOP of the card, so
     * `…90F8-739278F557C5.jpg` beat the real `3 pièces` three lines below and stored 8.
     *
     * Measured across the store by comparing each row's own TITLE — the portal's words, independent
     * of anything here — against the stored count: 21 rows wrong, 12 of them too HIGH, and 6 already
     * NOTIFIED carrying a room count the listing never stated. Four genuine 3-pièces flats were
     * stored as 1 or 2 and silently REJECTED by `min_rooms`. Fifth instance of *URLs are classified
     * text*, and the same first-match-wins shape as the PAP criteria-line defect.
     *
     * `(?<![A-Za-z0-9])` is the whole fix and it is deliberately not `\b`: inside `92F5` the `F` is
     * preceded by a DIGIT, and `\b` sees no boundary between two word characters, so it never
     * refused any of these. Every real writing of the notation — line start, after a space, after a
     * bracket — is preceded by a non-alphanumeric or nothing at all, which
     * `tests/php/Rent/Adapters/RoomsFromUuidTest.php` pins as its counterweight so the branch cannot
     * be "fixed" by deleting it.
     */
    private const string ROOMS_PATTERN = '~(?<![A-Za-z0-9])(?:T|F)\s?(\d)\b|\b(\d)\s*pi[eè]ces?\b~iu';

    /**
     * A URL's QUERY and FRAGMENT — noise no generic reader may scan. The PATH is kept.
     *
     * SEVENTH instance of *URLs are classified text*, and the second poisoning of this same
     * first-match-wins scan: Track 1j anchored the rooms branch against hexadecimal photo UUIDs
     * with `(?<![A-Za-z0-9])`, and base64url walks past that anchor because `-` and `_` are not
     * alphanumeric. SeLoger wraps every link as `click.by.seloger.com/?qs=<per-recipient token>`,
     * and a token reading `…zaw7m29jtx…` stored **7 m²** for a flat whose card says `64,25 m²`
     * (the live row of 2026-09-02T05:24:51Z, found by Track 6-A6's own query).
     *
     * KEEPING THE PATH IS THE RULED SPLIT, not a convenience: `RawListing::text()` already makes
     * exactly this cut before the tenure classifier reads a URL, because `?c=plai_plus` is a
     * campaign string nobody can rewrite while a `plai` PATH SEGMENT is a real social signal.
     * Blanking the whole URL would lose that. Same rule, same reason, one layer down.
     *
     * MEASURED OVER 2 043 STORED CARD BODIES before shipping: surface 26 changed (all seloger,
     * every one a recovery), rooms 4, and rent, postcode and commune 0 each; inli, cdc_habitat,
     * cityloger, bienici, leboncoin and pap 0 apiece. SEVEN of the 26 are matches that cleared
     * every filter at their true size and were never notified; six more were pushed with no
     * surface at all. The full list is Track 6-A7's report.
     */
    private const string URL_NOISE = '~(https?://\S*?)(?:[?#]\S*)~i';

    public function __construct(
        private SourceDefinition $definition,
        private Mailbox $mailbox,
        /**
         * Communes to look for in the message text, from `config/rent/criteria.json`.
         *
         * Passed in rather than guessed, because a commune is the one field an alert reliably
         * carries and the one the criteria engine cannot do without (Q32: no location evidence is a
         * rejection). Matching against the configured list rather than trying to recognise any
         * French place name keeps this out of the business of geocoding.
         *
         * @var array<string,string> canonical key => label as configured
         */
        private array $communeLabels = [],
        private int $limit = 50,
        /**
         * How often each CONFIGURED positional pattern found nothing this pass — Track 1h's health
         * half. Mutable object behind a readonly property: the property cannot be reassigned, the
         * counter it points at can be written, which is the only way a `final readonly` adapter can
         * accumulate anything. See {@see PatternMissLog} for why it is not persisted.
         */
        private PatternMissLog $patternMisses = new PatternMissLog(),
        /**
         * Where a non-fatal remark about this fetch goes, or `null` to say nothing.
         *
         * Exists because {@see cardsIn()} stopped throwing on an identity collision and had nowhere
         * else to be loud. A closure rather than a widened {@see Source} contract: the collision is
         * a property of one message, not a health verdict about the source, and `SourceHealth`
         * describes the source.
         */
        private ?\Closure $warn = null,
    ) {}

    public function name(): string
    {
        return $this->definition->name;
    }

    /**
     * `null` on purpose, and it is a ruling rather than an omission. An alert mailbox is one IMAP
     * connection to the user's OWN mail provider — not a landlord's website, and not a party that
     * can rate-limit or ban this tool. Q37's host pacing exists to protect the sources; applying it
     * here would add dead time to every pass while protecting nobody, and would let the mailbox
     * consume the distinct-host slot ahead of the requests that actually need it.
     *
     * This is also why hard rule 4 makes email ingestion the PRIMARY path: there is no bot to block.
     */
    public function host(): ?string
    {
        return null;
    }

    public function family(): string
    {
        return $this->definition->family === 'private' ? 'private' : 'institutional';
    }

    public function defaultTenure(): ?Tenure
    {
        return $this->definition->defaultTenure;
    }

    public function profile(): SourceProfile
    {
        return $this->definition->profile();
    }

    public function fetch(): array
    {
        // Per PASS, never cumulative: a miss rate describes the payload in hand, and a count that
        // spanned two fetches would dilute exactly the 100%-miss signal this exists to raise.
        $this->patternMisses->reset();

        try {
            $messages = $this->mailbox->fetchRecent($this->limit);
        } catch (MailboxError $e) {
            throw new SourceError($this->name(), $e->getMessage(), $e);
        }

        $listings = [];

        foreach ($messages as $position => $raw) {
            $message = EmailMessage::parse($raw);

            // Only messages this source sent. A shared mailbox receiving alerts from four portals
            // must not have every message attributed to whichever source polls first — that would
            // give each listing the wrong `mixed_tenure` flag, which is the §1 switch.
            if (!$this->isFrom($message)) {
                continue;
            }

            // AND only messages whose SUBJECT this source claims. The sender is not always enough:
            // rent and car leboncoin share `no.reply@leboncoin.fr`, the link host
            // `leboncoin.fr/vi/` AND the `Voir l'annonce` separator, so a vehicle alert would parse
            // as a flat, be rejected for having no commune, and still count toward this source's
            // health.
            //
            // The key was CONFIGURED AND DOCUMENTED FOR THIS on 2026-08-26 and no adapter read it
            // (found by Track 5b, 2026-09-01). Its own comment named the expiry: the two collide
            // "the moment the Gmail filter that routes them is created, which is an owed developer
            // action" — so the guard was written for a day that had not arrived, and would have
            // been absent when it did.
            if (!$this->subjectMatches($message)) {
                continue;
            }

            // CLAIMED: this message is ours, whatever it yields — a `Bien'ici` alert with no card
            // at all is still a processed message. Intent only; the mark is written by
            // acknowledge(), after the store has recorded the pass.
            $this->mailbox->claim($position);

            foreach ($this->listingsIn($message) as $listing) {
                $listings[] = $listing;
            }
        }

        return $listings;
    }

    /**
     * Row 36: mark the messages this pass claimed as processed — the pipeline's call, after the
     * store recorded them. A mailbox refusal becomes a `SourceError` so the pass can REPORT it
     * without failing: the listings are already on disk.
     */
    public function acknowledge(): void
    {
        try {
            $this->mailbox->acknowledge();
        } catch (MailboxError $e) {
            throw new SourceError($this->name(), 'marquage des courriers traités refusé — ' . $e->getMessage(), $e);
        }
    }

    /**
     * Delegated to the mailbox, which is the only thing here that has seen a `Date` header.
     *
     * This class is `readonly` and so cannot cache the value itself — deliberately, because a
     * source caching a computed result is what `MutableByDesign` exists to keep out. `ImapMailbox`
     * already IS its connection state and records this during the fetch; `FileMailbox` answers
     * `null` on purpose, a directory of frozen fixtures being no kind of feed.
     */
    public function newestFeedItemAt(): ?string
    {
        return $this->mailbox->newestMessageAt();
    }

    /**
     * The source's OWN feed-silence threshold, read by `SourceHealthService` — the one funnel `doctor`,
     * the pipeline and the heartbeat all go through — so a per-source `feed_silent_days` cannot be
     * honoured by one caller and ignored by another. `null` leaves the store's global threshold in force.
     *
     * The miss log beside it matters as much: A PATTERN THAT MATCHED NOTHING AT ALL IS A TEMPLATE CHANGE,
     * invisible to every other verdict (the cards still parsed, no run failed, the feed kept arriving).
     * PAP ran four days like that — 23 rows with a null surface, 19 notified as MATCH — while `doctor`
     * said `ok`. The service escalates it over the run log (steps 8e-1/8e-2).
     */
    public function feedSilentDays(): ?int
    {
        return $this->definition->feedSilentDays;
    }

    /** The per-pattern miss counts of the last fetch — `doctor` prints them. */
    public function patternMisses(): PatternMissLog
    {
        return $this->patternMisses;
    }

    /** Where the alert must have come from, matched against the source's configured `params.from`. */
    private function isFrom(EmailMessage $message): bool
    {
        $expected = $this->definition->params['from'] ?? null;

        if ($expected === null || $expected === '') {
            // No filter configured: accept everything. Loud in the wrong direction, but the
            // alternative — silently dropping every message — would look exactly like an empty
            // mailbox, and hard rule 2 exists because that is indistinguishable from a quiet market.
            return true;
        }

        return stripos($message->from(), $expected) !== false;
    }

    /**
     * Does this message's SUBJECT belong to this source?
     *
     * Absent, every message from the sender is accepted — the same direction `isFrom()` takes for
     * the same reason: silently dropping everything is indistinguishable from an empty mailbox,
     * which is the ambiguity hard rule 2 exists to remove. Only a source that CONFIGURES a subject
     * filter gets one, so every other source is byte-identical.
     *
     * The pattern is compile-checked at load, because `preg_match` on a broken pattern returns
     * `false` and `false !== 1` would reject EVERY message — a source that reports a dead portal on
     * a template that never changed.
     */
    private function subjectMatches(EmailMessage $message): bool
    {
        $pattern = $this->definition->params['subject_pattern'] ?? null;

        if (!\is_string($pattern) || $pattern === '') {
            return true;
        }

        return preg_match($pattern, $message->subject()) === 1;
    }

    /**
     * One listing per link, with whatever facts the surrounding text yields.
     *
     * **The link is the identity**, because it is the only thing in an alert that is stable across
     * messages: a portal re-sends the same flat in tomorrow's digest with different surrounding
     * prose, and keying on anything else would notify it twice. Query parameters are stripped from
     * the id — alert links carry tracking parameters that change per send, and keying on those
     * would make every listing new forever, which is precisely the failure `ListingMapper` refuses
     * a synthetic id for.
     *
     * **Both halves of that paragraph fail on a real SeLoger alert, which is why `card_separator`
     * exists.** Every link in one is `click.by.seloger.com/?qs=<opaque token>`; strip the query and
     * all sixteen collapse to `https://click.by.seloger.com/`. Sixteen cards, one identity. And
     * "the surrounding text" is the whole message, so each of the sixteen would take the FIRST rent
     * and the FIRST surface in it — one flat's facts, sixteen times.
     *
     * With a separator configured, the body is cut into cards and each is read on its own. Without
     * one, the behaviour above is unchanged: `email_demo` and every portal whose alerts do carry a
     * real listing URL keep working exactly as before.
     *
     * @return list<RawListing>
     */
    private function listingsIn(EmailMessage $message): array
    {
        // A REGEX SEPARATOR, for a portal whose card header is not one fixed string.
        //
        // Bien'ici's cards start with the photo line, and a listing with NO photo starts
        // `Pas de photo [...]` instead of `Photo` — so the literal missed it, that card merged into
        // the one above, and ONE listing came out carrying the PREVIOUS card's commune, rent and
        // surface under THIS card's link. Measured on the real message of 2026-08-31 19:07: three
        // cards announced, two listings stored, and the notification said `Montigny-le-Bretonneux
        // 78180 · T3 67 m² · 1192 € CC` for a flat that is `93220 Gagny · 49 m² · 855 € CC`.
        //
        // Nothing about that reads as a fault: every field is individually plausible and the link
        // works. It is the segmentation failure class the SeLoger and PAP anchors already exist for,
        // arriving through the separator itself rather than through a reader.
        $pattern = $this->stringParam('card_separator_pattern');

        if ($pattern !== null) {
            return $this->cardsIn($message, $pattern, regex: true);
        }

        $separator = $this->stringParam('card_separator');

        if ($separator !== null) {
            return $this->cardsIn($message, $separator);
        }

        $body = $message->body;
        $out = [];

        foreach ($message->links as $link) {
            if (!$this->looksLikeAListing($link)) {
                continue;
            }

            $id = self::stableId($link);

            // The whole message is given as the description, and that is deliberate rather than
            // lazy: the classifier reads text for tenure vocabulary, and a portal that mentions
            // `logement conventionné` once for a batch must not have that lost by a segmentation
            // guess this class is not equipped to make. Over-inclusion costs a digest entry; the
            // under-inclusion it replaces would cost the §1 signal.
            $out[] = new RawListing(
                sourceName: $this->name(),
                externalId: $id,
                // `cardTitle()`, not the raw subject. It used to be the subject unconditionally, so
                // `title_pattern` was silently INERT on every non-segmented source — a configured
                // pattern doing nothing, which is the shape of defect this repo keeps finding.
                // Sources configuring no pattern keep subject semantics exactly (`cardTitle()`
                // returns the subject when unconfigured), so seloger, bienici and leboncoin are
                // untouched — the first two are segmented anyway.
                title: $this->cardTitle($message, $body),
                description: $body,
                fields: ['email.from' => $message->from(), 'email.subject' => $message->subject()],
                url: $link,
                commune: $this->communeIn($body),
                postcode: self::postcodeIn($body),
                rentCc: $this->definition->map->chargesIncluded === true ? self::rentIn($body) : null,
                rentHc: $this->definition->map->chargesIncluded === false ? self::rentIn($body) : null,
                surfaceM2: $this->surfaceIn($body),
                rooms: $this->roomsIn($body),
                observedAt: $message->sentAt(),
                // Read on BOTH paths deliberately. A source with no `card_separator` is one listing
                // per message (PAP), which is precisely the shape whose subject can name the
                // advertiser unambiguously — reading it only on the segmented path would be the
                // `title_pattern` mistake documented four lines above, made again on a new param.
                advertiser: $this->advertiserOf($message),
            proseAbsent: $this->definition->proseAbsent,
            );
        }

        return $out;
    }

    /**
     * The message cut into cards, one listing each.
     *
     * The separator is the card's terminal call to action — *Voir l'annonce* — which is the one
     * element every portal's card template ends with. Splitting on it makes each card the TAIL of
     * its segment: segment 0 carries the message header plus card 1, segment 1 carries card 2, and
     * the final segment is the trailer, which yields no rent and is therefore not a card.
     *
     * **The description is the CARD, not the message**, and that is the Cityloger ruling applied to
     * a new surface: a map must address the listing, never the page. Whole-body description is the
     * furniture failure class that sent 14 of 16 correctly-badged CDC listings to the digest, and
     * an alert's CNIL trailer, campaign code and "gérer mes alertes" block are furniture by
     * construction. The subject travels with each card because it is the listing's title when the
     * card carries none.
     *
     * @return list<RawListing>
     */
    private function cardsIn(EmailMessage $message, string $separator, bool $regex = false): array
    {
        if ($regex) {
            $segments = preg_split($separator, $message->body);

            if ($segments === false) {
                // A pattern that cannot run is a CONFIG fault, not an event: every message would
                // fail it identically, and returning the whole body as one segment would silently
                // merge every card in the message. Same taxonomy as elsewhere here — a state
                // throws, an event is recorded.
                throw new SourceError($this->name(), 'card_separator_pattern illisible : ' . $separator);
            }
        } else {
            $segments = explode($separator, $message->body);
        }
        $out = [];
        $seenIds = [];

        foreach ($segments as $segment) {
            $listing = $this->cardListing($message, $segment);

            if ($listing === null) {
                continue;
            }

            // Two cards in one message sharing an identity: KEEP ONE, DROP THE REST, SAY SO.
            //
            // Scoped to the message on purpose: ACROSS messages the same id is exactly the
            // legitimate re-send that content-addressing exists to recognise, so this cannot live
            // in the store.
            //
            // **THIS USED TO THROW, and the throw was right about silence and wrong about blast
            // radius** — the same mistake detail hydration made once already, and the same fix. On
            // 2026-08-26 at 17:11 a `Baisse de prix` digest carried three coliving ROOMS in one flat
            // at Gros Saule, Aulnay-sous-Bois, each advertised with the WHOLE flat's `6 pièces .
            // 83,99 m²`. Commune, postcode, rooms, surface and residence were genuinely identical;
            // only the OLD price differed, and the rent is deliberately not in the key because a
            // price drop must not mint a new listing. seloger returned zero listings for seven
            // consecutive passes over three rooms that `exclude_title_patterns` rejects anyway.
            //
            // The thrown message asserted *the fields that distinguish them were not read*, which
            // was FALSE: they were read correctly. Two indistinguishable units in one residence is
            // the STATED cost of content-addressing arriving, an EVENT rather than a broken
            // template — and a state is what a throw is for.
            //
            // What is NOT relaxed is the silence. Dropping a card without a word is the failure the
            // throw existed to prevent, so the collision is announced on every pass that sees it.
            if (isset($seenIds[$listing->externalId])) {
                if ($this->warn !== null) {
                    ($this->warn)(
                        'deux annonces d\'un même message partagent une identité ('
                            . $listing->externalId . ') — une seule est retenue. Les champs qui '
                            . 'composent l\'identité sont identiques : ce sont soit deux logements '
                            . 'indiscernables (même résidence, même surface), soit une extraction '
                            . 'incomplète',
                    );
                }

                continue;
            }

            $seenIds[$listing->externalId] = true;
            $out[] = $listing;
        }

        // Hard rule 2's shape. A message that plainly contains cards — it carries the separator —
        // and yields none is a broken parser, and a broken parser here is indistinguishable from a
        // market that went quiet. The alert itself usually says how many listings it carries.
        if ($out === [] && count($segments) > 1) {
            throw new SourceError(
                $this->name(),
                'le message contient ' . (count($segments) - 1) . ' séparateur(s) de carte mais '
                    . 'aucune annonce n\'a pu en être extraite — le gabarit du portail a changé',
            );
        }

        return $out;
    }

    /**
     * One card, or `null` when the segment is not one.
     *
     * A segment qualifies only if it yields a rent AND enough to locate the flat. The rent gate is
     * what drops the trailer; the locating gate is the **no-information floor**, and it is the part
     * that would otherwise have shipped as a defect.
     *
     * Without it a card whose extraction failed across the board hashes to `sha1('seloger|||||')`
     * — and EVERY such card collapses onto that one id. That is the store's own *"nothing collapses
     * onto a shared key"* identity guarantee violated one layer up, where the store cannot see it.
     * Refusing costs nothing real: a listing with no commune, no postcode, no rooms and no surface
     * can never match anyway (Q32 — no location evidence is a rejection), so the alternative to
     * skipping it is a listing that is rejected AND poisons an identity every other broken card
     * would share.
     */
    private function cardListing(EmailMessage $message, string $segment): ?RawListing
    {
        // STAGE the pattern attempts, because this method is the one that decides whether the
        // segment was ever a card. A wrapper rather than a `resolve()` beside each `return null`:
        // the body below has several of them, and the one forgotten is the one that leaks a
        // furniture segment back into the miss ratio. See PatternMissLog::begin().
        $this->patternMisses->begin();

        try {
            $listing = $this->buildCardListing($message, $segment);
        } catch (\Throwable $e) {
            $this->patternMisses->resolve(false);

            throw $e;
        }

        $this->patternMisses->resolve($listing !== null);

        return $listing;
    }

    private function buildCardListing(EmailMessage $message, string $segment): ?RawListing
    {
        $rent = self::rentIn($segment);

        if ($rent === null) {
            return null;
        }

        $commune = $this->communeIn($segment);
        $postcode = self::postcodeIn($segment);
        $rooms = $this->roomsIn($segment);
        $surface = $this->surfaceIn($segment);
        $residence = $this->matchParam('residence_pattern', $segment);

        // THE LAST LINK, NOT THE FIRST — and the difference is the whole value of the notification.
        //
        // Reported by the developer 2026-08-25: clicking a SeLoger push opened THE SAVED SEARCH they
        // had created, not the flat. Taking the first qualifying link reads whatever furniture the
        // portal put above the card. Measured on a live five-card alert: card one's first link is
        // "mettre en pause les envois" (alert management), card two's is a third-party advert
        // ("Estimez le prix de votre déménagement"), card three's is the photo. One of three reached
        // the flat, by luck.
        //
        // The last one reaches it structurally: `card_separator` IS the call to action, and a URL
        // precedes its own anchor text in this rendering, so a segment ENDS with the CTA's link
        // whatever sits above it. Verified against the message's HTML part, which names each anchor
        // — price, title, details, location and `Voir l'annonce` all address the listing, the header
        // does not. No redirect was followed to establish it: the tokens are per-subscriber, and
        // following one manufactures an engagement signal from a click nobody made.
        //
        // Only on the SEGMENTED path. Without a separator every link IS a listing, and reversing
        // there would pick a different flat rather than a different link on the same flat.
        $link = null;
        foreach (array_reverse(self::linksIn($segment)) as $candidate) {
            if ($this->looksLikeAListing($candidate)) {
                $link = $candidate;

                break;
            }
        }

        // IDENTITY: the card's own link where the portal publishes one, content-addressing where it
        // does not. Content-addressing was invented for SeLoger, which sends neither a listing URL
        // nor a listing id — sixteen cards behind one opaque redirect. That is a property of that
        // portal, not of email alerts: Bien'ici puts a real, stable id in the PATH, and a real id
        // avoids both stated costs of the content key (two identical units in one residence sharing
        // an identity, and a card that gains a surface changing identity and notifying twice).
        //
        // `id_from: content` still short-circuits, so SeLoger's identity is byte-identical to what
        // it was before this existed. That matters more than it looks: nothing migrates a stored row
        // from one key scheme to another, so a source that changes identity re-notifies its whole
        // backlog. The scheme is chosen once, before the source is first enabled.
        //
        // A segment with no listing link is not a card, and that is what drops the alert's own
        // HEADER — which carries the saved search's criteria (`1 200 € max - 3 pièces min - 45 m²
        // min`) and so yields a plausible rent, room count and surface belonging to no flat.
        if (!self::locatable($commune, $postcode, $rooms, $surface, $residence)) {
            return null;
        }

        $id = $this->identityFor($commune, $postcode, $rooms, $surface, $residence)
            ?? ($link === null ? null : self::stableId($link));

        if ($id === null) {
            return null;
        }

        return new RawListing(
            sourceName: $this->name(),
            externalId: $id,
            title: $this->cardTitle($message, $segment),
            description: trim($message->subject() . "\n" . $segment),
            fields: [
                'email.from' => $message->from(),
                'email.subject' => $message->subject(),
            ],
            // The tracking redirect goes in UNRESOLVED, and that is a ruling. Following it here
            // would be one third-party request per listing, on a token tied to the subscriber's
            // identity, manufacturing an engagement signal from a click nobody made — hard rule 5's
            // "identify honestly" one step further out. The link is clicked by the human who wanted
            // to see the ad, which is what it is for.
            url: $link,
            commune: $commune,
            postcode: $postcode,
            rentCc: $this->definition->map->chargesIncluded === true ? $rent : null,
            rentHc: $this->definition->map->chargesIncluded === false ? $rent : null,
            // WHEN this card was observed is when its message was SENT, not when the pass read it.
            // A portal re-sends yesterday's card, the window keeps both, and without this the store
            // saw two fresh sightings per pass — 1146 then 1122, "a drop", every 15 minutes
            // (2026-08-29, 429 history rows). With it, the older card is a superseded observation.
            observedAt: $message->sentAt(),
            surfaceM2: $surface,
            rooms: $rooms,
            advertiser: $this->advertiserOf($message),
            proseAbsent: $this->definition->proseAbsent,
        );
    }

    /**
     * WHO is advertising, read from the SUBJECT — not from the segment, and not from the body.
     *
     * The subject, because that is where SeLoger puts it: `<ADVERTISER> vous adresse ses dernières
     * exclusivités`, ~201 such messages, one listing each. `matchParam()` is otherwise handed a
     * segment, so passing the subject is the whole difference — and it keeps the pattern inside the
     * single funnel that {@see PatternMissLog} counts, so the day the portal renames that template
     * the miss surfaces in `health()` instead of silently reverting this rule (F1/F3: PAP's anchors
     * broke for four days and nothing counted them).
     *
     * NOT the body. A landlord name found in prose is `au plus près` all over again — and a
     * containment scan over an evidence blob matched `i3f` inside a base64url JWT signature on a
     * Century 21 listing, which is how this rule's own measurement was briefly wrong by one row.
     */
    private function advertiserOf(EmailMessage $message): ?string
    {
        return $this->matchParam('advertiser_pattern', $message->subject());
    }

    /**
     * A content-addressed identity, or `null` below the no-information floor.
     *
     * **Rent is deliberately absent from the key.** A price drop is an event this project exists to
     * detect; a rent in the identity turns every drop into a brand-new listing — notified as new,
     * with no price history and no *en baisse* reason — which is the silent opposite of what the
     * store's price-history table is for.
     *
     * What IS in the key are the dwelling's structural facts, which is why this does not fall foul
     * of `ListingMapper`'s refusal of synthetic ids. That refusal is about a hash of the ad's TEXT:
     * it changes whenever the copy is touched, so the listing is new on every run and notifies for
     * ever. Commune, postcode, rooms, surface and residence do not move when the prose is rewritten.
     *
     * **Stated cost, twice.** Two units in one residence with identical rooms and surface share an
     * identity, so the second is treated as already seen — a miss, in the §1-safe direction. And a
     * card that gains a previously-missing surface in a later email changes identity once, and so
     * notifies once more.
     */
    /**
     * The no-information floor: something that LOCATES the flat, and something that DESCRIBES it.
     *
     * Either half alone is not enough to call a segment a card — every card in a message shares a
     * commune often enough, and a bare `3 pièces` is shared by half a portal.
     *
     * **It guards the CARD, not the content key**, and that distinction was worth one regression to
     * find. It used to live inside `identityFor()`, where the argument for it was the identity
     * collapse: without it every card whose extraction failed hashes to `sha1('seloger|||||')` and
     * they all land on that one id. Link identity does not collapse — each card has its own URL —
     * so a floor living in the content path alone would silently stop applying the moment a portal
     * published a real listing id, and cards that yielded nothing but a rent would be admitted.
     *
     * The other half of the argument does not depend on the key at all: a segment that yields a
     * rent and nothing else is an EXTRACTION FAILURE, and admitting it as a listing hides the
     * failure behind a row that is quietly rejected for having no location (Q32). Skipping costs
     * nothing real and keeps the failure visible in the count.
     */
    private static function locatable(
        ?string $commune,
        ?string $postcode,
        ?int $rooms,
        ?float $surface,
        ?string $residence,
    ): bool {
        $locating = $commune ?? $postcode;

        if ($locating === null || $locating === '') {
            return false;
        }

        return $rooms !== null || $surface !== null || ($residence !== null && $residence !== '');
    }

    private function identityFor(
        ?string $commune,
        ?string $postcode,
        ?int $rooms,
        ?float $surface,
        ?string $residence,
    ): ?string {
        if ($this->stringParam('id_from') !== 'content') {
            return null;
        }

        $key = implode('|', [
            $this->name(),
            Text::fold($commune ?? ''),
            $postcode ?? '',
            $rooms === null ? '' : (string) $rooms,
            // Two decimals: a portal that reports 44.71 one week and 44.7 the next must not mint a
            // second identity for one flat.
            $surface === null ? '' : number_format($surface, 2, '.', ''),
            Text::fold($residence ?? ''),
        ]);

        return sha1($key);
    }

    /** @return list<string> */
    private static function linksIn(string $text): array
    {
        preg_match_all('~https?://[^\s<>"\'\)\]]+~i', $text, $matches);

        return array_values(array_unique(array_map(
            static fn (string $l): string => rtrim($l, '.,;:'),
            $matches[0],
        )));
    }

    /** A configured `params` entry, when it is a non-empty string. */
    private function stringParam(string $key): ?string
    {
        $value = $this->definition->params[$key] ?? null;

        return \is_string($value) && $value !== '' ? $value : null;
    }

    /** First capture of a configured regex, or `null` when the key is unset or matches nothing. */
    /**
     * The title of ONE card — and the subject line is a title only where no pattern claims to read one.
     *
     * **A configured `title_pattern` that misses yields `''`, never the subject**, and the asymmetry
     * is the whole point. Falling back to the subject was this repo's named *failure with an alibi*:
     * on 2026-08-26 SeLoger's vocabulary-based pattern was measured missing **27 of 72 live cards**,
     * and every one of them stored `4 nouvelles annonces : Ile-de-France` as a flat's title. Nothing
     * about that reads as a fault. It is a plausible French sentence sitting in a plausible field,
     * and it survived a fixture suite, a live acceptance run and a review round.
     *
     * What it costs is precise: {@see \Scout\Rent\Config\Criteria::excludeTitlePatterns} is matched
     * against the TITLE ONLY — deliberately, because `3 chambres` in a *description* is the family
     * flat the criteria are looking for — so a card wearing the subject line as its title cannot be
     * rejected by `^\s*chambre\b` or by the parking/box/garage/cave/bureau family, whatever it
     * actually advertises. That is the exclusion added *because* four of this source's first nine
     * matches were coliving ROOMS passing every numeric filter.
     *
     * `''` restores nothing on its own — an empty title matches no pattern either. What it buys is
     * that the failure is VISIBLE: in the push, in `scout dump`, in the stored v7 snapshot, an
     * unread title looks unread instead of looking like a flat an agency named after the alert.
     * Hard rule 9's shape, one layer up — an extraction failure is not a value.
     *
     * A source that configures NO pattern keeps subject semantics, because there the subject IS the
     * documented answer rather than a substitute for one: `email_demo` and any portal whose alert
     * carries a single flat are unchanged, byte for byte.
     */
    private function cardTitle(EmailMessage $message, string $segment): string
    {
        if ($this->stringParam('title_pattern') === null) {
            return $message->subject();
        }

        return $this->matchParam('title_pattern', $segment) ?? '';
    }

    /**
     * Patterns `matchParam()` reads but never COUNTS — F30, and the car domain's precedent applied.
     *
     * `advertiser_pattern` is read from the message SUBJECT and consulted once per CARD, so its miss
     * ratio is a statement about the wrong denominator. Measured on the first deployed run of the
     * miss signal: `advertiser_pattern 375/405`. That is not a template change — an ordinary SeLoger
     * alert names no agency at all, and the pattern anchors on the `exclusivités` template, one
     * listing each, so on a full-window pass it CANNOT be satisfied on the other ~92 %.
     *
     * The dangerous half is not the noise. `PatternMissLog::total()` fires at `misses === calls`
     * with a floor of three, so a THIN pass — the IMAP window truncated harder, or a quiet stretch
     * where one agencyless 3-card alert is the whole pass — satisfies it exactly and WARNs on a
     * pattern nobody can satisfy. That fires the one signal F27 exists to give, on the one occasion
     * it means nothing.
     *
     * DROPPING THE KEY WAS RULED AND OVERRULED, because it is a §1 mechanism: it feeds
     * {@see \Scout\Rent\Core\LandlordRegistry}, which is all of `dede8ac` — 23 SeLoger rows
     * advertised by an institutional landlord were judged `LIBRE` at the portal's 50bp default and
     * 21 were pushed as MATCHes. So the question is how to satisfy §1, never whether.
     * `VehicleEmailSource` already exempts its own message-level `subject_pattern` for exactly this
     * reason; this is that rule, not a new one.
     *
     * STATED COST: nothing now reports the `exclusivités` template changing. The ratio was the only
     * thing watching it, and it was watching it uselessly — but that is not the same as not at all.
     *
     * Kept as a SET and asserted by reflection, so a second name cannot be added quietly and turn a
     * named exception into a way of switching the signal off.
     */
    public const array UNCOUNTED_PARAMS = ['advertiser_pattern'];

    private function matchParam(string $key, string $subject): ?string
    {
        $pattern = $this->stringParam($key);

        if ($pattern === null) {
            return null;
        }

        $counted = !\in_array($key, self::UNCOUNTED_PARAMS, true);

        if (@preg_match($pattern, $subject, $m) !== 1) {
            if ($counted) {
                $this->patternMisses->record($key, false);
            }

            return null;
        }

        $captured = trim($m[1] ?? '');
        // COUNTED HERE because this is the single funnel every configured pattern passes through —
        // `title_pattern`, `commune_pattern`, `surface_pattern`, `rooms_pattern` and
        // `residence_pattern` all land on this line. Counting at each call site instead would be
        // five places to forget, and the one forgotten is the one that goes silent.
        if ($counted) {
            $this->patternMisses->record($key, $captured !== '');
        }

        return $captured === '' ? null : $captured;
    }

    /** A listing link, not an unsubscribe or a tracking pixel. */
    private function looksLikeAListing(string $link): bool
    {
        foreach (['unsubscribe', 'desabonnement', 'desinscription', 'preferences', 'mailto:', '/pixel', 'utm_medium=beacon'] as $noise) {
            if (stripos($link, $noise) !== false) {
                return false;
            }
        }

        $host = $this->definition->params['link_host'] ?? null;

        return $host === null || stripos($link, $host) !== false;
    }

    private static function stableId(string $link): string
    {
        $scheme = parse_url($link, PHP_URL_SCHEME) ?? 'https';
        $host = parse_url($link, PHP_URL_HOST) ?? '';
        $path = parse_url($link, PHP_URL_PATH) ?? '';

        return $scheme . '://' . $host . $path;
    }

    /**
     * The commune this card is in — read from the portal's own layout first, the ranked vocabulary
     * second.
     *
     * **The vocabulary alone is not a reader, and on a region-mode config it reads nothing.**
     * `communeLabels` is built from the RANKED communes, which are a preference and not a filter
     * (Q1, 2026-08-22): a watch covering all of Île-de-France ranks a handful of towns and knows the
     * names of no others. Every SeLoger listing therefore came back with a commune of `null` while
     * its postcode parsed correctly — measured 2026-08-25 against the live mailbox, 9 cards, 9
     * nulls. Nothing about that looks like a fault from outside: the listing still matches on its
     * postcode, so the operator sees a notification that cannot say where the flat is, a weaker
     * `Dedup` key, and an S1 commune score that never fires.
     *
     * `commune_pattern` is the fix and it is CONFIG, per portal, because the anchor is the portal's
     * template rather than anything general about French addresses. SeLoger's is the parenthesised
     * postcode on the line BELOW the name — chosen over the *quartier* comma on the line above
     * because that line is optional: three of the nine live cards have no quartier at all.
     *
     * ORDER MATTERS, and the pattern wins. It is structural — it reads the field the portal laid
     * out — while the vocabulary is a substring scan that will happily return a ranked commune the
     * copy merely mentions (*"proche Chatou"*), which is the prototype's over-matching defect this
     * repo already documents. The scan stays as the fallback so a source with no `commune_pattern`
     * behaves exactly as it did before this existed.
     *
     * A pattern that matches nothing returns `null` rather than a guess (hard rule 9): unknown, not
     * elsewhere.
     */
    private function communeIn(string $body): ?string
    {
        // A CONFIGURED pattern is the whole answer: on a miss it yields `null`, never the
        // vocabulary scan. Falling back restored the prototype's over-match ("proche Dourdan" in a
        // Mormant card reads as Dourdan) AND gave a broken pattern an alibi — a listing in an
        // unranked town rather than a failed extraction. The three numeric/title readers already
        // behaved this way; this was the last positional reader that did not (2026-08-29).
        // Measured before the change: zero fallback hits over every fixture, capture and the
        // live mailbox, so no stored row changes identity.
        if (trim((string) ($this->definition->params['commune_pattern'] ?? '')) !== '') {
            return $this->matchParam('commune_pattern', $body);
        }

        $folded = \Scout\Core\Text::fold(self::prose($body));

        foreach ($this->communeLabels as $key => $label) {
            if ($key !== '' && str_contains($folded, $key)) {
                return $label;
            }
        }

        return null;
    }

    /**
     * The body with every URL's query and fragment removed — what a GENERIC reader scans.
     *
     * **Scope is the generic readers ONLY**, and the boundary is load-bearing at both ends. A
     * CONFIGURED pattern owns its answer, is positional, and was measured against a real payload
     * when it was written (pap's `surface_pattern` and `rooms_pattern` are unchanged bit-for-bit,
     * which their fixtures assert). And the LINK readers never see this: for seloger the whole URL
     * *is* the query, so stripping it there would empty every notification's link — and on bienici
     * and leboncoin, whose identity IS the link, re-key the entire stored backlog and re-notify
     * every flat already seen.
     */
    private static function prose(string $body): string
    {
        return preg_replace(self::URL_NOISE, '$1', $body) ?? $body;
    }

    private static function postcodeIn(string $body): ?string
    {
        $body = self::prose($body);

        // Anchored to the departments in scope rather than any five digits, because a rent, a
        // surface and a reference number are all five digits too, and a wrong postcode is worse
        // than none: it can pass the prefix filter for a listing that is nowhere near.
        return preg_match('~\b((?:78|95|92|91|93|94|77|75)\d{3})\b~', $body, $m) === 1 ? $m[1] : null;
    }

    /**
     * The rent in a card, or `null` when no figure in it is credible as one.
     *
     * **Every match of a pattern is examined, not just the first**, and that is the other half of
     * the price-drop fix. `preg_match` stops at the first hit, so on a card reading
     * `baissé de 100 €` … `1 100 €/mois` the bare-figure pattern returned 100 and the reader gave
     * up — one implausible figure hiding a perfectly readable rent three lines below it. Scanning
     * all matches and taking the first PLAUSIBLE one costs nothing and removes a whole class of
     * "the first number that looks like money" failures.
     */
    private static function rentIn(string $body): ?int
    {
        $body = self::prose($body);

        foreach (self::RENT_PATTERNS as $pattern) {
            if (preg_match_all($pattern, $body, $matches, PREG_PATTERN_ORDER) === false) {
                continue;
            }

            foreach ($matches[1] ?? [] as $candidate) {
                $value = Payload::int(['v' => $candidate], ['v']);

                // A plausibility band. Without it, `2024` from a date and `95240` from a postcode
                // both parse as rents — and a rent of 2024 € would pass nothing while a rent of 95
                // would pass everything with maximum headroom.
                //
                // The numbers moved to `Payload::plausibleRent()` on 2026-09-04 so the mapped path
                // could share them rather than get a second copy: this band existed here and
                // nowhere else, and `ListingMapper` let 7 stored history rows through at 119–290 €.
                $banded = Payload::plausibleRent($value);

                if ($banded !== null) {
                    return $banded;
                }
            }
        }

        return null;
    }

    /**
     * The surface, preferring a per-source POSITIONAL anchor over the generic first-match scan.
     *
     * The generic scan is `preg_match` — first match wins — and that is correct only while the body
     * contains exactly one surface. PAP's alert quotes the SUBSCRIBER'S OWN SEARCH CRITERIA above
     * the listing (*"a partir de 45 m2"*), so the scan returns the search FLOOR and the flat's real
     * 50 m2 is never reached. 45 is below `min_surface_m2`, so the listing is rejected for being too
     * small — silently, with nothing reading as a fault. Measured, not predicted, on the first real
     * PAP alert; it is Bien'ici's defect a second time, down to the same number.
     *
     * **A CONFIGURED PATTERN THAT MISSES YIELDS `null`, NEVER THE GENERIC SCAN.** Falling back would
     * restore the exact defect the anchor exists to remove, and give it an alibi: the listing would
     * read as a small flat rather than as a broken extraction. Same rule as {@see cardTitle()}, and
     * the same reason — an extraction failure is not a value (hard rule 9, one layer up).
     *
     * A source configuring NO pattern is bit-for-bit unchanged.
     */
    private function surfaceIn(string $body): ?float
    {
        // A CONFIGURED pattern OWNS the answer, hit or miss — ownership is decided by the CONFIG,
        // never by whether the pattern matched. Deciding it on the match is the defect in disguise:
        // a miss would then fall through to the generic scan below, which is exactly what returns
        // the subscriber's own search floor.
        $owned = $this->stringParam('surface_pattern') !== null;

        if ($owned) {
            $captured = $this->matchParam('surface_pattern', $body);

            return $captured === null ? null : self::plausibleSurface(Payload::float(['v' => $captured], ['v']));
        }

        if (preg_match(self::SURFACE_PATTERN, self::prose($body), $m) !== 1) {
            return null;
        }

        return self::plausibleSurface(Payload::float(['v' => $m[1]], ['v']));
    }

    /**
     * The room count, on the same terms as {@see surfaceIn()} and for the same reason.
     *
     * On the PAP capture the generic scan returns the RIGHT answer — and only by coincidence, since
     * the criteria line quoted above the listing happens to say `3 pieces et plus` while the flat
     * happens to be a 3. A 4-piece flat surfaced by that same alert would have been recorded as a 3
     * and rejected by `min_rooms` on a number the listing never stated. A coincidence is not a
     * guarantee, so the anchor is applied here too.
     */
    private function roomsIn(string $body): ?int
    {
        // Ownership by CONFIG, not by match — see {@see surfaceIn()}.
        $owned = $this->stringParam('rooms_pattern') !== null;

        if ($owned) {
            $captured = $this->matchParam('rooms_pattern', $body);

            return $captured === null ? null : self::plausibleRooms((int) $captured);
        }

        if (preg_match(self::ROOMS_PATTERN, self::prose($body), $m) !== 1) {
            return null;
        }

        $digits = ($m[1] ?? '') !== '' ? $m[1] : ($m[2] ?? '');

        return $digits === '' ? null : self::plausibleRooms((int) $digits);
    }

    /**
     * The plausibility band, applied on BOTH paths.
     *
     * A positional capture that bypassed the band would be a new hole: the anchor guarantees the
     * figure came from the right LINE, never that the line held a sane number.
     */
    private static function plausibleSurface(?float $value): ?float
    {
        return $value !== null && $value >= 5.0 && $value <= 1000.0 ? $value : null;
    }

    private static function plausibleRooms(int $value): ?int
    {
        return $value >= 1 && $value <= 12 ? $value : null;
    }
}
