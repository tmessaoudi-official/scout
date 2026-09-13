<?php

declare(strict_types=1);

namespace Scout\Adapters\Mail;

/**
 * A parsed RFC-822 message: headers, a text body, and the links it contains.
 *
 * Hand-rolled for the same reason {@see ImapMailbox} is — no `ext-imap`, no Composer. The subset is
 * the one a listing alert actually uses: headers with folded continuation lines, MIME multipart,
 * `quoted-printable` and `base64` transfer encodings, and a charset conversion to UTF-8.
 *
 * **The charset conversion is the part that matters most and looks least important.** French alert
 * emails are routinely `ISO-8859-1`, and a `é` read as Latin-1 bytes and stored as UTF-8 becomes
 * mojibake — which then fails to match `Text::fold()`, which then means the tenure classifier never
 * sees `logement intermédiaire`. That is a §1 hole arriving through a text encoding, and it would
 * present as "the classifier is oddly bad at email".
 */
final readonly class EmailMessage
{
    /**
     * @param array<string,string> $headers lower-cased names
     * @param list<string>         $links   every http(s) URL found, in order, de-duplicated
     * @param string               $htmlText the stripped text of the text/html alternative, `''` when none
     */
    private function __construct(
        public array $headers,
        public string $body,
        public array $links,
        /**
         * THE HTML ALTERNATIVE, BESIDE THE BODY — never instead of it (2026-09-13).
         *
         * `body` prefers text/plain for the reasons {@see preferredPart()} gives, and that stays.
         * But a portal can put facts in its HTML part that its plain part omits: LinkedIn's job
         * alert lists the same offers in both, and only the HTML states each card's work mode and
         * pay line (measured on 20 captures). This is that text, stripped, hrefs harvested and
         * entities decoded exactly as `body` would have been, so a reader of it inherits every rule
         * the body path learnt.
         *
         * Additive by construction: `body` and `links` are computed exactly as before, and nothing
         * but a source that asks for this property reads it.
         */
        public string $htmlText = '',
    ) {}

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function subject(): string
    {
        return $this->header('subject') ?? '';
    }

    public function from(): string
    {
        return $this->header('from') ?? '';
    }

    /** Stable identity for a message, for the seen-set. */
    public function messageId(): ?string
    {
        $id = $this->header('message-id');

        return $id === null ? null : trim($id, '<> ');
    }

    /**
     * When this message was sent, as a UTC ISO-8601 instant — or null when its `Date` is absent or
     * does not parse STRICTLY.
     *
     * This is the observation time of every listing the message carries (2026-08-29). A portal
     * re-sends yesterday's card, the IMAP window keeps both messages, and a listing observed "at
     * the pass time" on every pass is a fresh sighting every pass — which is how one Bien'ici flat
     * produced 429 alternating price-history rows and 128 phantom *Baisse de loyer* emails. The
     * store already orders sightings by their instant; it only ever lacked the instant.
     *
     * Strict by round-trip, because `new \DateTimeImmutable` is a relative-expression parser that
     * moves a mismatched weekday FORWARD (`Fri, 09 Aug` → the 14th), and forward is precisely the
     * wrong direction here: a stale card would read as the newest one. A misparse is null, "now" —
     * today's behaviour, and the direction that cannot lose a genuinely new card.
     */
    public function sentAt(): ?string
    {
        $header = $this->header('date');

        if ($header === null || trim($header) === '') {
            return null;
        }

        return self::parseRfc2822($header)?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * RFC 2822 `Date`, parsed strictly: the value must re-format to itself under the mask that
     * accepted it. Shared with the feed-freshness reader in {@see ImapMailbox}, which learnt this
     * rule first (a `Fri, 09 Aug 2026` recorded as 14 August closed a FEED_SILENT verdict).
     *
     * **THE MASK SET FOLLOWS THE RFC'S GRAMMAR WHERE IT MATTERS, not the shapes one portal happened
     * to send** — and *where it matters* is the qualification, added after a round-7 lens measured
     * the claim: the obsolete zone forms RFC 5322 §4.3 still permits (`-0000`, `UT`, and the single
     * military letters) are legal and are REFUSED here. That is the safe direction — an unparsed
     * date yields no verdict rather than a wrong instant — and no portal in this tree sends one, so
     * it is a stated limit and not a defect. It was
     * four masks for a month and refused two legal ones, at a cost measured in production on
     * 2026-09-05: RFC 5322 writes the day as `1*2DIGIT` and makes the seconds optional, PAP writes
     * `Thu, 3 Sep 2026`, and the round-trip refused it because `createFromFormat` accepts `3` and
     * re-formats it as `03`. Every PAP alert of 1–5 September was stored with no observation time —
     * disarming the stale-sighting guard that exists because of the 429-history-row defect — while
     * `feed_newest_at` froze on 31 August and `FEED_SILENT` was about to call a daily feed dead.
     * Five days a month, on any portal that does not zero-pad, and nothing anywhere said so.
     *
     * Widening cannot weaken it: the round-trip is what makes this strict, and it applies to every
     * mask. A mismatched weekday, an impossible day and a relative expression are still refused —
     * `EmailMessageSentAtTest` asserts that counterweight beside the widening, because a mask list
     * is exactly the thing somebody "simplifies" by dropping the round-trip.
     */
    public static function parseRfc2822(string $header): ?\DateTimeImmutable
    {
        $value = trim(preg_replace('~\s*\([^()]*\)\s*$~', '', trim($header)) ?? trim($header));

        $masks = [];
        foreach (['D, ', ''] as $dow) {           // the day name is optional in the grammar
            foreach (['d', 'j'] as $day) {        // 1*2DIGIT — zero-padded or not
                foreach (['H:i:s', 'H:i'] as $time) { // seconds are optional
                    foreach (['O', 'T'] as $zone) {   // +0200, or a named zone
                        $masks[] = $dow . $day . ' M Y ' . $time . ' ' . $zone;
                    }
                }
            }
        }

        foreach ($masks as $mask) {
            $parsed = \DateTimeImmutable::createFromFormat($mask, $value);

            if ($parsed !== false && $parsed->format($mask) === $value) {
                return $parsed;
            }
        }

        return null;
    }

    public static function parse(string $raw): self
    {
        // Normalise line endings first. A message that mixes CRLF and LF — common once it has passed
        // through a filter — otherwise splits its header block in the wrong place, and everything
        // downstream reads headers as body.
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);

        $split = preg_split('~\n\n~', $raw, 2);
        $headerBlock = $split[0] ?? '';
        $bodyBlock = $split[1] ?? '';

        $headers = self::parseHeaders($headerBlock);
        $html = null;
        $body = self::decodeEntities(self::decodeBody($headers, $bodyBlock, $html));

        return new self($headers, $body, self::extractLinks($body), self::decodeEntities($html ?? ''));
    }

    /** @return array<string,string> */
    private static function parseHeaders(string $block): array
    {
        $headers = [];
        $name = null;
        $value = '';

        foreach (explode("\n", $block) as $line) {
            // A leading space or tab CONTINUES the previous header — RFC 822 folding. Real subjects
            // fold constantly, and a parser that ignores it truncates every long one.
            if ($name !== null && ($line !== '' && ($line[0] === ' ' || $line[0] === "\t"))) {
                $value .= ' ' . trim($line);

                continue;
            }

            if ($name !== null) {
                $headers[$name] = self::decodeHeader($value);
            }

            $parts = explode(':', $line, 2);
            if (count($parts) !== 2) {
                $name = null;
                $value = '';

                continue;
            }

            $name = strtolower(trim($parts[0]));
            $value = trim($parts[1]);
        }

        if ($name !== null) {
            $headers[$name] = self::decodeHeader($value);
        }

        return $headers;
    }

    /** RFC 2047 encoded words — `=?UTF-8?Q?Nouvelle_annonce?=`, which every French subject uses. */
    private static function decodeHeader(string $value): string
    {
        // RFC 2047 §6.2: linear whitespace BETWEEN two adjacent encoded words is not displayed, so
        // it is collapsed away FIRST — before decoding, which is the only moment a `?= =?` sequence
        // still exists to match. The collapse used to run last, after `preg_replace_callback` had
        // already turned both words into plain text, and was therefore dead from the line it was
        // written on: a real SeLoger subject folded mid-word decoded to `exclusivit és`.
        //
        // That is not cosmetic. The subject becomes a listing's `title`, which
        // `exclude_title_patterns` filters on and which the tenure classifier reads as prose.
        $joined = preg_replace('~\?=\s+=\?~', '?==?', $value) ?? $value;

        $decoded = preg_replace_callback(
            '~=\?([^?]+)\?([BbQq])\?([^?]*)\?=~',
            static function (array $m): string {
                $text = strtoupper($m[2]) === 'B'
                    ? (base64_decode($m[3], true) ?: '')
                    : quoted_printable_decode(str_replace('_', ' ', $m[3]));

                return self::toUtf8($text, $m[1]);
            },
            $joined,
        );

        return trim($decoded ?? $joined);
    }

    /** @param array<string,string> $headers */
    private static function decodeBody(array $headers, string $body, ?string &$html = null): string
    {
        $contentType = $headers['content-type'] ?? 'text/plain';

        if (stripos($contentType, 'multipart/') !== false
            && preg_match('~boundary="?([^";]+)"?~i', $contentType, $m) === 1) {
            return self::preferredPart($body, $m[1], $html);
        }

        $decoded = self::decodeTransfer($body, $headers['content-transfer-encoding'] ?? '');
        $charset = preg_match('~charset="?([^";]+)"?~i', $contentType, $c) === 1 ? $c[1] : 'UTF-8';
        $text = self::toUtf8($decoded, $charset);

        if (stripos($contentType, 'text/html') === false) {
            return $text;
        }

        $html = self::stripHtml($text);

        return $html;
    }

    /**
     * From a multipart body, the part worth classifying.
     *
     * `text/plain` is preferred over `text/html` — not for tidiness, but because an HTML part
     * carries markup that would need stripping, and stripping is where a tenure label hidden in an
     * attribute gets lost. When only HTML exists it is stripped, and the classifier sees the
     * result; `Text` deliberately does NOT decode entities, so a broken strip stays visible rather
     * than being silently papered over.
     *
     * **AN EMPTY PART IS NOT AN ANSWER, and index 0 is not a part.** Both halves of that sentence
     * were defects, and together they made every real multipart alert parse to nothing.
     *
     * RFC 2046 §5.1.1 defines everything between the headers and the FIRST boundary as the
     * *preamble* — `This is a multi-part message in MIME format.`, which nearly every real mailer
     * emits for pre-MIME clients. `explode()` hands it back as index 0. Read as a part it carries no
     * `Content-Type`, so it defaulted to `text/plain`, split to an empty body, and ran
     * `$plain ??= ''` — and `??=` assigns only on `null`, so the REAL text part that followed could
     * never overwrite it. Measured on a live SeLoger alert: `body len: 0`, `links: 0`, zero
     * listings, no exception. Hard rule 3's exact shape, reached without a single `catch`; the
     * committed `email_demo` fixtures happen to omit a preamble, which is why the suite was green.
     */
    private static function preferredPart(string $body, string $boundary, ?string &$htmlOut = null): string
    {
        $parts = explode('--' . $boundary, $body);
        $plain = null;
        $html = null;
        $nestedHtml = null;

        foreach ($parts as $index => $part) {
            // The preamble, structurally. Not "text that looked empty" — everything before the
            // first boundary, whatever it contains.
            if ($index === 0) {
                continue;
            }

            $part = ltrim($part, "\n");
            if ($part === '' || str_starts_with($part, '--')) {
                continue;
            }

            $split = preg_split('~\n\n~', $part, 2);
            $partHeaders = self::parseHeaders($split[0] ?? '');
            $partBody = $split[1] ?? '';

            $type = $partHeaders['content-type'] ?? 'text/plain';

            if (stripos($type, 'multipart/') !== false
                && preg_match('~boundary="?([^";]+)"?~i', $type, $m) === 1) {
                $nested = self::preferredPart($partBody, $m[1], $nestedHtml);
                if ($plain === null && $nested !== '') {
                    $plain = $nested;
                }
                if ($html === null && $nestedHtml !== null && $nestedHtml !== '') {
                    $html = $nestedHtml;
                }

                continue;
            }

            $decoded = self::decodeTransfer($partBody, $partHeaders['content-transfer-encoding'] ?? '');
            $charset = preg_match('~charset="?([^";]+)"?~i', $type, $c) === 1 ? $c[1] : 'UTF-8';
            $text = self::toUtf8($decoded, $charset);

            // `!== ''` rather than `??=`, in all three places. A blank `text/plain` alternative is
            // a real thing some mailers ship, and letting it claim the answer is the same
            // `''`-is-not-`null` mistake wearing different clothes: the HTML alternative carrying
            // the whole listing would never be reached.
            if (stripos($type, 'text/plain') !== false) {
                $trimmed = trim($text);
                if ($plain === null && $trimmed !== '') {
                    $plain = $text;
                }
            } elseif (stripos($type, 'text/html') !== false) {
                $stripped = self::stripHtml($text);
                if ($html === null && $stripped !== '') {
                    $html = $stripped;
                }
            }
        }

        $htmlOut = $html;

        return $plain ?? $html ?? '';
    }

    private static function decodeTransfer(string $body, string $encoding): string
    {
        return match (strtolower(trim($encoding))) {
            'base64' => (string) base64_decode(preg_replace('~\s+~', '', $body) ?? '', true),
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };
    }

    /**
     * Convert to UTF-8, and NEVER silently mangle when the charset is unknown.
     *
     * `iconv` with `//IGNORE` would drop the bytes it cannot map, which for a Latin-1 French email
     * means every accented character vanishing — and a listing whose text lost its accents still
     * looks like text, so nothing downstream reports a problem. Falling back to the original bytes
     * keeps the damage visible: `Text::foldTolerant()` returns null on invalid UTF-8, and the
     * classifier treats an unreadable surface as a reason to withhold rather than to match.
     */
    private static function toUtf8(string $text, string $charset): string
    {
        $charset = strtoupper(trim($charset, "\"' "));

        if ($charset === '' || $charset === 'UTF-8' || $charset === 'UTF8' || $charset === 'US-ASCII') {
            return $text;
        }

        // ISO-8859-1 IS READ AS CP1252, and for this project that is not pedantry — it decides
        // whether a rent is extracted at all.
        //
        // Mail declaring `charset=ISO-8859-1` is almost always Windows-1252 in fact; every mail
        // client renders it that way. The two differ exactly in 0x80–0x9F, which strict Latin-1
        // leaves undefined and CP1252 fills with the characters French commerce uses most: `€` is
        // 0x80, and the curly quotes are 0x91–0x94.
        //
        // Running the fixture is what showed it. `Loyer : 1 450 =80 charges comprises` converted from
        // strict ISO-8859-1 loses the euro sign to an invisible control character — and EVERY rent
        // pattern in `EmailAlertSource` requires a currency marker, so the rent came out NULL. A
        // listing with no rent is not disqualified (hard rule 9), so it would have been notified with
        // "loyer non communiqué" while the alert stated the rent plainly.
        $effective = in_array($charset, ['ISO-8859-1', 'ISO8859-1', 'LATIN1', 'ISO_8859-1'], true)
            ? 'CP1252'
            : $charset;

        $converted = @iconv($effective, 'UTF-8', $text);

        if ($converted === false && $effective !== $charset) {
            // A byte genuinely invalid in CP1252 but valid in Latin-1 is rare and possible; fall
            // back rather than lose the whole body.
            $converted = @iconv($charset, 'UTF-8', $text);
        }

        return $converted === false ? $text : $converted;
    }

    /**
     * HTML entities, decoded once at the funnel — for the plain part as much as the HTML one.
     *
     * **This is a §1 obligation, not tidiness.** `Text::fold()` REFUSES text still carrying
     * entities, and its refusal names whose job this is: *"an entity inside a label deletes that
     * label while leaving others intact, which has already turned an explicitly social listing
     * into an eligible one."* `logement conventionn&eacute;` folds to `logement conventionn` —
     * the label destroyed, the listing apparently unlabelled, which on a mixed source is the
     * difference between a digest entry and a notification.
     *
     * It applies to the PLAIN part because a portal's plain alternative is generated from its HTML
     * and need not have been decoded on the way. SeLoger's real alerts carry `&rarr;` in theirs,
     * which is what turned this from a hypothetical into a failing test.
     *
     * Decoding can only ever restore a label, never invent one: no HTML entity expands to `PLAI`,
     * `PLUS`, `PLS` or `intermédiaire`, so the §1 risk runs one way and this is the safe end of it.
     * `ENT_QUOTES | ENT_HTML5` so `&apos;` and the named arrows both resolve; a bare `&` in
     * ordinary agency prose is not an entity and is left exactly as written.
     */
    private static function decodeEntities(string $text): string
    {
        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private static function stripHtml(string $html): string
    {
        // Block-level tags become newlines BEFORE stripping, so `<li>Chatou</li><li>Houilles</li>`
        // does not collapse into `ChatouHouilles` — which would then match neither commune.
        $spaced = preg_replace('~</(p|div|br|li|tr|h[1-6]|td)\s*>|<br\s*/?>~i', "\n", $html) ?? $html;
        $spaced = preg_replace('~<(script|style)[^>]*>.*?</\1>~is', ' ', $spaced) ?? $spaced;

        return trim(preg_replace('~\n{3,}~', "\n\n", strip_tags(self::harvestHrefs($spaced))) ?? '');
    }

    /**
     * Move each anchor's URL into the TEXT, immediately after the words it wraps.
     *
     * leboncoin is the first portal to send an alert as HTML with no `text/plain` alternative, and
     * before this the parser produced a perfectly good body carrying all three listings and **zero
     * links** — every URL lived in an `href`, and `strip_tags()` takes attributes with the tags. A
     * source that yields no links yields no listings and reports a quiet market for ever, which is
     * hard rule 2's exact shape reached without a single `catch`.
     *
     * **The URL goes into the body rather than only into the side `$links` array, and that is the
     * whole design.** `EmailAlertSource::cardListing()` finds a card's link by scanning THAT
     * SEGMENT's text, so a URL known only at message level can never be associated with the card it
     * belongs to. It is emitted AFTER the anchor text so the reading order matches the rendered
     * one — a segmented source takes the last qualifying link in a segment, and a URL emitted first
     * would attach each card to the link of the card above it.
     *
     * Only the HTML path calls this. A message with a `text/plain` alternative is unaffected, which
     * matters more than the feature does: Bien'ici's listing IDENTITY is its links, so a changed
     * link set would re-key the whole stored backlog and re-notify every flat already seen.
     */
    private static function harvestHrefs(string $html): string
    {
        return preg_replace_callback(
            '~<a\b[^>]*\bhref\s*=\s*(["\'])(https?://[^"\']+)\1[^>]*>(.*?)</a>~is',
            static fn (array $m): string => $m[3] . "\n" . $m[2] . "\n",
            $html,
        ) ?? $html;
    }

    /** @return list<string> */
    private static function extractLinks(string $body): array
    {
        preg_match_all('~https?://[^\s<>"\'\)\]]+~i', $body, $matches);

        $seen = [];
        foreach ($matches[0] as $link) {
            $link = rtrim($link, '.,;:');
            $seen[$link] = true;
        }

        return array_keys($seen);
    }
}
