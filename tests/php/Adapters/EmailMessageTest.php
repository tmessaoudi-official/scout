<?php

declare(strict_types=1);

namespace Scout\Tests\Adapters;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Adapters\Mail\EmailMessage;

/**
 * The MIME parser, tested against the shapes a REAL mailer emits.
 *
 * Every case here was written after running an actual SeLoger alert through the parser and watching
 * it return an empty body. The class was authored blind — its own docblock said so — and the
 * committed `email_demo` fixtures happen to omit the two structures below, which is why 1879 green
 * tests said nothing about either.
 */
#[CoversClass(EmailMessage::class)]
final class EmailMessageTest extends TestCase
{
    /**
     * RFC 2046 §5.1.1: everything between the headers and the FIRST boundary is the *preamble*, and
     * it is not a body part. Nearly every real mailer writes one — `This is a multi-part message in
     * MIME format.` — for clients that predate MIME.
     *
     * Read as a part it has no `Content-Type`, so it defaults to `text/plain`, splits to an empty
     * body, and runs `$plain ??= ''`. `??=` assigns only on `null`, so the REAL `text/plain` part
     * that follows can never overwrite it and the whole message parses to nothing.
     *
     * That is this project's signature silent failure reached without a single `catch`: zero
     * listings, no exception, a source that looks like a quiet market for ever (hard rule 3).
     */
    public function testAPreambleDoesNotSwallowTheBody(): void
    {
        $raw = self::multipart(
            preamble: 'This is a multi-part message in MIME format.',
            plain: "Appartement Conflans-Sainte-Honorine\n980 €/mois charges comprises",
            html: '<p>Appartement</p>',
        );

        $message = EmailMessage::parse($raw);

        self::assertStringContainsString('980', $message->body, 'the plain part must survive a preamble');
        self::assertStringContainsString('Conflans-Sainte-Honorine', $message->body);
    }

    /**
     * A preamble containing a BLANK LINE is where skipping index 0 stops being belt-and-braces.
     *
     * The `!== ''` guard alone handles the ordinary preamble, because a single line with no colon
     * parses to no headers and no body. But `Preamble\n\nmore words` splits at the blank line, so
     * "more words" becomes a body, the missing `Content-Type` defaults to `text/plain`, and the
     * segment claims the answer with real garbage in it — a body that is not empty and is not the
     * message.
     *
     * RFC 2046 §5.1.1 is unambiguous that everything before the first boundary is the preamble
     * whatever it contains, which is why the skip is structural rather than a check on emptiness.
     */
    public function testAPreambleWithABlankLineIsStillNotAPart(): void
    {
        $raw = self::multipart(
            preamble: "Ceci est un message multi-parties.\n\nSi vous voyez ce texte, votre client est ancien.",
            plain: '980 €/mois charges comprises à Chatou',
            html: '<p>x</p>',
        );

        $body = EmailMessage::parse($raw)->body;

        self::assertStringContainsString('Chatou', $body);
        self::assertStringNotContainsString('votre client est ancien', $body, 'the preamble is not the body');
    }

    /**
     * The same bug one layer down: a nested `multipart/*` that resolves to nothing must not claim
     * the answer either. `multipart/mixed` wrapping `multipart/alternative` is the ordinary shape
     * for an alert carrying an attachment or a tracking image.
     */
    public function testAnEmptyNestedPartDoesNotClaimTheAnswer(): void
    {
        $inner = "--INNER\nContent-Type: text/plain; charset=\"utf-8\"\n\n\n--INNER--\n";

        $raw = "Subject: nested\n"
            . "Content-Type: multipart/mixed; boundary=\"OUTER\"\n"
            . "\n"
            . "preamble text\n"
            . "--OUTER\n"
            . "Content-Type: multipart/alternative; boundary=\"INNER\"\n"
            . "\n"
            . $inner
            . "--OUTER\n"
            . "Content-Type: text/plain; charset=\"utf-8\"\n"
            . "\n"
            . "1 200 € charges comprises\n"
            . "--OUTER--\n";

        self::assertStringContainsString('1 200', EmailMessage::parse($raw)->body);
    }

    /**
     * An empty `text/plain` part falls through to the HTML one rather than winning by being first.
     *
     * Some mailers ship a deliberately blank plain alternative. Preferring plain is right (stripping
     * markup is where a tenure label in an attribute gets lost) — preferring an EMPTY plain is the
     * same `''`-is-not-`null` mistake wearing different clothes.
     */
    public function testAnEmptyPlainPartFallsThroughToHtml(): void
    {
        $raw = self::multipart(preamble: '', plain: '', html: '<p>Studio Dourdan 915 €</p>');

        self::assertStringContainsString('Dourdan', EmailMessage::parse($raw)->body);
    }

    /**
     * RFC 2047 §6.2: linear whitespace BETWEEN two adjacent encoded words is not displayed. A long
     * French subject is folded across two of them constantly, and the split lands mid-word.
     *
     * `Consilium vous adresse ses dernières exclusivités` arrives as `…exclusivit?= =?UTF-8?Q?=C3=A9s`
     * and must not decode to `exclusivit és`. The collapse existed but ran AFTER the decode, where
     * no `?= =?` sequence survives to match — dead from the line it was written on.
     *
     * A mangled subject is not cosmetic here: the subject is a listing's `title`, which
     * `exclude_title_patterns` filters on and which the tenure classifier reads.
     */
    public function testAdjacentEncodedWordsJoinWithNoSeparator(): void
    {
        $raw = "Subject: =?UTF-8?Q?Consilium_vous_adresse_ses_derni=C3=A8res_exclusivit?=\n"
            . " =?UTF-8?Q?=C3=A9s?=\n"
            . "Content-Type: text/plain; charset=\"utf-8\"\n"
            . "\n"
            . "corps\n";

        self::assertSame(
            'Consilium vous adresse ses dernières exclusivités',
            EmailMessage::parse($raw)->subject(),
        );
    }

    /** A single encoded word keeps working, so the collapse cannot be blamed for a regression. */
    public function testASingleEncodedWordStillDecodes(): void
    {
        $raw = "Subject: =?UTF-8?B?" . base64_encode('1 nouvelle annonce : Île-de-France') . "?=\n"
            . "Content-Type: text/plain\n"
            . "\n"
            . "corps\n";

        self::assertSame('1 nouvelle annonce : Île-de-France', EmailMessage::parse($raw)->subject());
    }

    /**
     * Two encoded words separated by a word that is NOT encoded keep their real spacing. Only
     * whitespace between two ADJACENT encoded words is dropped, and collapsing more than that would
     * run `Paris` into the next token.
     */
    public function testWhitespaceAroundAPlainWordSurvives(): void
    {
        $raw = "Subject: =?UTF-8?Q?Alerte?= Paris =?UTF-8?Q?imm=C3=A9diate?=\n"
            . "Content-Type: text/plain\n"
            . "\n"
            . "corps\n";

        self::assertSame('Alerte Paris immédiate', EmailMessage::parse($raw)->subject());
    }

    /** A boundary containing regex-significant and RFC-2047-looking bytes — SeLoger's is `8PaVqvzMwU9R=_?:`. */
    public function testAnAwkwardBoundaryStillSplits(): void
    {
        $raw = "Subject: awkward\n"
            . "Content-Type: multipart/alternative;\n"
            . "\tboundary=\"8PaVqvzMwU9R=_?:\"\n"
            . "\n"
            . "This is a multi-part message in MIME format.\n"
            . "\n"
            . "--8PaVqvzMwU9R=_?:\n"
            . "Content-Type: text/plain;\n"
            . "\tcharset=\"utf-8\"\n"
            . "Content-Transfer-Encoding: 8bit\n"
            . "\n"
            . "44,71 m² à Conflans-Sainte-Honorine\n"
            . "--8PaVqvzMwU9R=_?:--\n";

        self::assertStringContainsString('44,71', EmailMessage::parse($raw)->body);
    }

    /**
     * **HTML entities are decoded, including in the `text/plain` part, and §1 is why.**
     *
     * A portal's plain alternative is generated FROM its HTML, and SeLoger's does not decode
     * entities on the way — its real alerts carry `&rarr;` in the plain part. `Text::fold()`
     * refuses text containing entities outright, with a message that says exactly whose job this
     * is: *"an entity inside a label deletes that label while leaving others intact, which has
     * already turned an explicitly social listing into an eligible one."*
     *
     * That is the §1 failure in one sentence. `logement conventionn&eacute;` folds to
     * `logement conventionn` if the entity is left alone — the label is destroyed and the listing
     * looks unlabelled, which on a mixed source is the difference between a digest entry and a
     * notification.
     *
     * Decoding is the SAFE direction. It can only ever restore a label, never invent one: no HTML
     * entity expands to `PLAI`, `PLUS`, `PLS` or `intermédiaire`.
     */
    public function testHtmlEntitiesAreDecodedInThePlainPart(): void
    {
        $raw = self::multipart(
            preamble: 'This is a multi-part message in MIME format.',
            plain: "Se désabonner &rarr;\nLogement conventionn&eacute; &agrave; Chatou",
            html: '<p>ignored</p>',
        );

        $body = EmailMessage::parse($raw)->body;

        self::assertStringContainsString('conventionné', $body, 'the label survives, entity and all');
        self::assertStringNotContainsString('&eacute;', $body);
        self::assertStringNotContainsString('&rarr;', $body);
    }

    /** An `&amp;` inside a link must decode too, or every query parameter after it is lost. */
    public function testEntitiesInLinksAreDecoded(): void
    {
        $raw = self::multipart(
            preamble: '',
            plain: 'https://portail.test/a?x=1&amp;y=2',
            html: '<p>x</p>',
        );

        self::assertSame(['https://portail.test/a?x=1&y=2'], EmailMessage::parse($raw)->links);
    }

    /** A bare ampersand in ordinary prose is not an entity and is left exactly as written. */
    public function testABareAmpersandIsUntouched(): void
    {
        $raw = self::multipart(preamble: '', plain: 'Agence Dupont & Fils', html: '<p>x</p>');

        self::assertStringContainsString('Dupont & Fils', EmailMessage::parse($raw)->body);
    }

    /**
     * THE HTML ALTERNATIVE IS EXPOSED BESIDE THE BODY, AND THE BODY DOES NOT MOVE (2026-09-13).
     *
     * LinkedIn's job alert sends both alternatives, and they list the same offers, but only the HTML
     * part states each card's work mode (`Aneo · Montrouge (Hybride)`) and pay line (`Entre 44 k €
     * et 70 k € par an`) — measured on 20 real captures. `body` prefers text/plain, so a source
     * reading it alone would score remote and pay as unknown on every card, for ever, while
     * reporting a healthy fetch.
     *
     * The counterweight is the half that matters to the rest of the tree: `body` is unchanged.
     * Bien'ici's listing IDENTITY is the links read out of it, so any change there re-keys a stored
     * backlog and re-notifies it.
     */
    public function testTheHtmlAlternativeIsExposedBesideAnUnchangedBody(): void
    {
        $raw = self::multipart(
            preamble: '',
            plain: "Senior Software Engineer\nAneo\nMontrouge",
            html: '<p>Aneo &middot; Montrouge (Hybride)</p><p>Entre 44 k € et 70 k € par an</p>'
                . '<a href="https://www.linkedin.com/comm/jobs/view/4461976159/?x=1">Senior Software Engineer</a>',
        );

        $message = EmailMessage::parse($raw);

        self::assertSame("Senior Software Engineer\nAneo\nMontrouge\n", $message->body, 'body still prefers text/plain, byte for byte');
        self::assertStringContainsString('Aneo · Montrouge (Hybride)', $message->htmlText, 'entities decoded, like the body');
        self::assertStringContainsString('Entre 44 k € et 70 k € par an', $message->htmlText);
        self::assertStringContainsString(
            'https://www.linkedin.com/comm/jobs/view/4461976159/?x=1',
            $message->htmlText,
            'hrefs are harvested into the HTML text, so a card segment can find its own link',
        );
        self::assertSame([], $message->links, 'links still come from the body alone');
    }

    public function testAPlainOnlyMessageHasNoHtmlText(): void
    {
        $raw = "Subject: alerte\nContent-Type: text/plain; charset=\"utf-8\"\n\nLoyer 980 €";

        self::assertSame('', EmailMessage::parse($raw)->htmlText);
    }

    /** A single-part HTML message and an HTML-only multipart both expose what their body already is. */
    public function testAnHtmlOnlyMessageExposesItsStrippedTextTwice(): void
    {
        $single = "Subject: alerte\nContent-Type: text/html; charset=\"utf-8\"\n\n<p>Chatou</p><p>Houilles</p>";
        $multi = self::multipart(preamble: '', plain: '', html: '<p>Chatou</p><p>Houilles</p>');

        foreach ([$single, $multi] as $raw) {
            $message = EmailMessage::parse($raw);
            self::assertStringContainsString('Houilles', $message->htmlText);
            self::assertSame($message->body, $message->htmlText);
        }
    }

    private static function multipart(string $preamble, string $plain, string $html): string
    {
        return "Subject: alerte\n"
            . "Content-Type: multipart/alternative; boundary=\"BOUND\"\n"
            . "\n"
            . ($preamble === '' ? '' : $preamble . "\n\n")
            . "--BOUND\n"
            . "Content-Type: text/plain; charset=\"utf-8\"\n"
            . "\n"
            . $plain . "\n"
            . "--BOUND\n"
            . "Content-Type: text/html; charset=\"utf-8\"\n"
            . "\n"
            . $html . "\n"
            . "--BOUND--\n";
    }

    // ── HTML-only mail, where the links live in attributes ───────────────────────────────────────

    /**
     * An HTML-only alert must still yield its links, and they must land IN THE BODY TEXT.
     *
     * leboncoin is the first portal to send HTML with no `text/plain` alternative. Measured on the
     * first real capture, 2026-08-26: the parser produced a perfectly good 15 975-character body
     * carrying all three listings, and **zero links** — because `stripHtml()` removes tags, and
     * every URL lived in an `href` attribute that went with them. A source with no links yields no
     * listings and reports a quiet market for ever, which is hard rule 2's exact shape.
     *
     * **The side `$links` array alone would not be enough**, and that is the constraint that decides
     * the design. `EmailAlertSource::cardListing()` finds a card's link by scanning THAT SEGMENT's
     * text (`linksIn($segment)`), so a URL that exists only in a message-level array can never be
     * associated with the card it belongs to. The URL has to sit next to its own anchor text.
     */
    public function testAnHtmlOnlyMessageKeepsItsLinksInTheBodyText(): void
    {
        $raw = "From: alerts@portal.test\r\n"
            . "Subject: 2 nouveaux biens\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "\r\n"
            . '<p>Bonjour,</p>'
            . '<a href="https://portal.test/vi/111.htm">1 042 &euro; Appartement 3 pieces 48 m2</a>'
            . '<a href="https://portal.test/vi/222.htm">980 &euro; Appartement 3 pieces 45 m2</a>';

        $m = EmailMessage::parse($raw);

        self::assertContains('https://portal.test/vi/111.htm', $m->links);
        self::assertContains('https://portal.test/vi/222.htm', $m->links);

        // AND in the body, each next to the card it belongs to — which is what makes per-segment
        // association possible at all.
        self::assertStringContainsString('https://portal.test/vi/111.htm', $m->body);
        self::assertLessThan(
            strpos($m->body, '980'),
            strpos($m->body, 'https://portal.test/vi/111.htm'),
            'the first card\'s URL must precede the second card\'s text, or a segmented source '
            . 'associates every card with the wrong flat',
        );

        // Entities still decoded, because `Text::fold()` THROWS on an undecoded one and says
        // decoding is the adapter's job.
        self::assertStringNotContainsString('&euro;', $m->body);
        self::assertStringContainsString('€', $m->body);
    }

    public function testHarvestingHrefsDoesNotDisturbAPlainTextMessage(): void
    {
        // The catastrophic direction. Bien'ici's IDENTITY is its links, so a changed link set
        // re-keys the whole backlog and re-notifies every listing already seen. A message with a
        // plain alternative must be byte-identical to what it parsed to before the harvest existed.
        $raw = "From: alerts@portal.test\r\n"
            . "Subject: x\r\n"
            . "Content-Type: multipart/alternative; boundary=BB\r\n"
            . "\r\n"
            . "preamble\r\n"
            . "--BB\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "\r\n"
            . "Photo\r\n1 042 EUR\r\nhttps://portal.test/annonce/111\r\n"
            . "--BB\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "\r\n"
            . '<a href="https://portal.test/TRACKING/should-not-appear">x</a>'
            . "\r\n--BB--\r\n";

        $m = EmailMessage::parse($raw);

        self::assertSame(['https://portal.test/annonce/111'], $m->links);
        self::assertStringNotContainsString('TRACKING', $m->body, 'the HTML part is not the chosen part');
    }
}
