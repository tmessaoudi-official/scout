<?php

declare(strict_types=1);

namespace Scout\Tests\Core;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Scout\Core\Redact;

/**
 * `CLAUDE.md` hard rule 7 forbids credentials reaching a log or a committed file. This class is the
 * choke point that enforces it for adapter error text, which travels from an exception into
 * `source_runs.error` and out again in a user-facing notification.
 *
 * The tests are written from both sides, because a masker has two failure modes and only one of
 * them is loud: leaking a secret (silent, and the reason this exists) and destroying the diagnostic
 * (loud, but it makes people delete the masker).
 */
#[CoversClass(Redact::class)]
final class RedactTest extends TestCase
{
    /** @return iterable<string, array{string, list<string>}> */
    public static function secrets(): iterable
    {
        yield 'IDFM key as a query parameter' => [
            'GET https://prim.iledefrance-mobilites.fr/v2/journey?apikey=abc123XYZ&from=admin',
            ['abc123XYZ'],
        ];

        yield 'generic key parameter' => ['https://api.test/search?key=s3cr3tvalue', ['s3cr3tvalue']];
        yield 'access token' => ['refused: access_token=eyJhbGciOi.J9.sig', ['eyJhbGciOi.J9.sig']];
        yield 'password in a connection string' => ['imap connect failed password=hunter2', ['hunter2']];
        yield 'JSON body' => ['{"client_secret": "abcdefgh12345678"}', ['abcdefgh12345678']];
        yield 'URL userinfo' => ['https://takieddine:hunter2@imap.test/INBOX', ['hunter2', 'takieddine']];
        yield 'bearer header' => ['Authorization: Bearer eyJhbGciOiJIUzI1NiJ9', ['eyJhbGciOiJIUzI1NiJ9']];
        yield 'basic header' => ['Authorization: Basic dGFraTpodW50ZXIy', ['dGFraTpodW50ZXIy']];
        yield 'mailbox' => ['LOGIN failed for jean.dupont@example.com', ['jean.dupont@example.com']];

        // PERCENT-ENCODED, because a URL writes `@` that way and a source's own URL can be the
        // disclosure: PAP puts the subscriber's address in the link it emails, on all 98 of its
        // stored rows. The literal-`@` case above passed while this one did not — the local-part
        // class contained `%` all along, but the separator was a bare `@`.
        yield 'percent-encoded mailbox in a URL' => [
            'GET https://www.pap.fr/annonces/x-r1?email=x%40example.test&md5=deadbeef a échoué',
            ['x%40example.test'],
        ];
        yield 'signature parameter' => ['?sig=9f8e7d6c5b4a3210&page=2', ['9f8e7d6c5b4a3210']];

        // Every case below got through the first version, and four of them are keys `.env.example`
        // itself names. They share a shape the original patterns could not see: the secret carries
        // no `name=value` delimiter, because its transport is a space or a URL path segment.

        yield 'IMAP LOGIN, space-separated' => [
            'A01 NO [AUTHENTICATIONFAILED] in command: A01 LOGIN alertes-immo@example.net Hunter2Secret',
            ['Hunter2Secret'],
        ];

        yield 'POP3 PASS' => ['-ERR authorization failed: PASS Hunter2Secret', ['Hunter2Secret']];

        yield 'pass= (what imap_open emits)' => [
            'imap_open(): Login failed for user=alertes host=imap.example.net pass=Hunter2Secret',
            ['Hunter2Secret'],
        ];

        yield 'Telegram bot token in the URL PATH' => [
            'HTTP 404 https://api.telegram.org/bot7412345678:AAH9xQvKQ_fake_token_value/sendMessage',
            ['AAH9xQvKQ_fake_token_value'],
        ];

        yield 'ntfy topic in the URL path' => [
            // docs/OPEN-QUESTIONS.md Q9 calls the topic a secret: anyone who knows it reads the
            // notifications.
            'HTTP 500 https://ntfy.sh/rentwatch-a8f3d9c1-private',
            ['rentwatch-a8f3d9c1-private'],
        ];

        yield 'RFR income figure, French spacing' => [
            'critère de ressources : RFR N-2 = 41 250 € — au-dessus du plafond',
            ['41 250'],
        ];

        yield 'RFR income figure, env-var spelling' => ['RFR_N2=41250 dépasse le plafond', ['41250']];

        // EVERY credential variable in the committed `.env.example` defeated the masker, because
        // `_` is a word character and `\bpassword\b` therefore cannot match inside `IMAP_PASSWORD`.
        // Five of six went through untouched while the class docblock claimed the IDFM key was
        // covered. This block is the template read back against the code that is supposed to guard
        // it — if a key is added there, add it here.

        yield 'IMAP_PASSWORD' => ['ligne invalide dans .env : IMAP_PASSWORD=hunter2', ['hunter2']];
        yield 'SMTP_PASSWORD' => ['SMTP_PASSWORD=hunter2 rejeté', ['hunter2']];
        yield 'TELEGRAM_BOT_TOKEN' => ['TELEGRAM_BOT_TOKEN=7488291044:AAH9xQkL2mNp0RtVuWxYz1234 invalide', ['AAH9xQkL2mNp0RtVuWxYz1234']];
        yield 'IDFM_API_KEY' => ['IDFM_API_KEY=Zx9QpLm4Nn2Kk8Jj refusé (401)', ['Zx9QpLm4Nn2Kk8Jj']];
        yield 'RENT_NTFY_TOPIC' => ['RENT_NTFY_TOPIC=rw-a8f3k2p9qz introuvable', ['rw-a8f3k2p9qz']];

        // A single-quoted value matched NOTHING: the unquoted class excludes `'`, so the pattern
        // failed outright. `var_export()` on a config array is the likeliest way a credential
        // reaches an exception message in PHP.
        yield 'single-quoted value' => ["password='hunter2' refusé", ['hunter2']];
        yield 'var_export of a config array' => [
            "PDO DSN: array ( 'user' => 'rw', 'password' => 'hunter2', )",
            ['hunter2'],
        ];
        yield 'quoted passphrase with spaces' => ['password="correct horse battery staple"', ['battery staple']];

        // A Telegram token is `<bot_id>:<hash>`; the literal `bot` prefix exists only in the API
        // URL path, so the pattern anchored on `bot\d+:` was dead for a token anywhere else.
        yield 'bare Telegram token' => [
            'Telegram a répondu 401 pour 7488291044:AAH9xQ_kL2mNp0RtVuWxYz1234567890a',
            ['AAH9xQ_kL2mNp0RtVuWxYz1234567890a'],
        ];

        // SMTP AUTH PLAIN decodes to \0user\0password. `AUTH LOGIN` was masked by accident of the
        // LOGIN verb; `AUTH PLAIN` was not, so the coverage was arbitrary rather than designed.
        yield 'SMTP AUTH PLAIN' => [
            'sent: AUTH PLAIN AHJ3QGV4Lm9yZwBTM2NyM3RQYXNzdzByZCE=',
            ['AHJ3QGV4Lm9yZwBTM2NyM3RQYXNzdzByZCE='],
        ];
        // UNPADDED base64 — the length is a multiple of 4, so there is no `=` and the standalone
        // blob pattern cannot see it. This is what makes the AUTH verb pattern load-bearing rather
        // than redundant with it.
        yield 'SASL AUTH PLAIN, unpadded base64' => [
            'sent: AUTH PLAIN AHJ3QGV4Lm9yZwBTM2NyM3RQYXNzdzByZA',
            ['AHJ3QGV4Lm9yZwBTM2NyM3RQYXNzdzByZA'],
        ];

        yield 'bare base64 credential blob' => [
            '535 5.7.8 Error: authentication failed: AHJ3QGV4Lm9yZwBTM2NyM3RQYXNzdzByZCE=',
            ['AHJ3QGV4Lm9yZwBTM2NyM3RQYXNzdzByZCE='],
        ];

        // The masking half of the ambiguous-name split. It was narrowed to `=`-only in one round
        // and only the DIAGNOSTIC half was tested — the half being relied on was not.
        yield 'auth= is still masked' => ['auth=s3cr3tvalue refusé', ['s3cr3tvalue']];
        yield 'session= is still masked' => ['session=abc123def456 expirée', ['abc123def456']];

        // The French spellings, which a French-language IMAP or SMTP error would actually carry.
        yield 'mot de passe' => ['mot de passe: hunter2 incorrect', ['hunter2']];
        yield 'motdepasse' => ['motdepasse=hunter2', ['hunter2']];

        // A SUFFIXED component — a second mailbox, a rotated key. The affix has to reach past the
        // name on the right as well as the left, and only the left was pinned.
        yield 'suffixed env-var name' => ['IMAP_PASSWORD_2=hunter2 refusé', ['hunter2']];
        yield 'suffixed WITHOUT an underscore' => ['IMAP_PASSWORD2=hunter2 refusé', ['hunter2']];
        yield 'prefixed with a digit' => ['x2password=hunter2', ['hunter2']];

        // camelCase is the dominant JSON key convention and the literal spelling of every OAuth
        // field. Requiring a `_`/`-`/`.` delimiter around the name missed all of these, and an
        // OAuth 401 body reached the database and the notification in cleartext.
        yield 'clientSecret' => ['{"error":"invalid_client","clientSecret":"cdc-h4b1t4t-9f2a"}', ['cdc-h4b1t4t-9f2a']];
        yield 'accessToken' => ['{"accessToken":"ya29.A0ARrdaM9REDACTME"}', ['ya29.A0ARrdaM9REDACTME']];
        yield 'refreshToken' => ['{"refreshToken":"rt-abc123"}', ['rt-abc123']];
        yield 'botToken in var_export' => ["'botToken' => 'AAF-xyz1234'", ['AAF-xyz1234']];
        yield 'userPassword' => ['userPassword: hunter2', ['hunter2']];
        yield 'clientSecretKey' => ['clientSecretKey=abc123def', ['abc123def']];
        // `clientSecretKey` is rescued by `key` even without the right-hand camel boundary, so it
        // cannot pin it. `secretValue` can: `value` is not a name.
        yield 'a camelCase suffix that no other name rescues' => ['secretValue=abc123def', ['abc123def']];
        yield 'ntfy topic in a JSON body' => [
            '{"error":"forbidden","topic":"rentwatch-a8f3e2"}',
            ['rentwatch-a8f3e2'],
        ];

        // The adapter WRAPS the library error with its own context — the natural way to satisfy
        // hard rule 3 — so nothing can be assumed about what follows the credential.
        yield 'IMAP LOGIN wrapped by the adapter' => [
            'échec IMAP sur « inli » : > A01 LOGIN alertes-immo@example.net abcdefghijklmnop (tentative 1/3)',
            ['abcdefghijklmnop'],
        ];
        yield 'POP3 PASS mid-line' => ['échec POP3 : PASS abcdefghijklmnop — vérifiez .env', ['abcdefghijklmnop']];
        yield 'IMAP LOGIN with a trailing response' => ['a01 LOGIN alice hunter2 (réponse: NO)', ['hunter2']];

        // Tag shapes an IMAP-tag whitelist missed, each of which leaked a cleartext password.
        yield 'six-digit IMAP tag' => ['00000003 LOGIN alertes-immo hunter2secret', ['hunter2secret']];
        yield 'long alphabetic tag prefix' => ['TAG123456 LOGIN alertes-immo hunter2secret', ['hunter2secret']];
        yield 'no tag at all' => ['LOGIN alertes-immo hunter2secret', ['hunter2secret']];
        yield 'quoted args, no tag' => ['imap: LOGIN "alertes-immo" "hunter2secret"', ['hunter2secret']];

        // The tagged AUTHENTICATE rule had two NEGATIVE fixtures and no positive one, so it could
        // be deleted wholesale with the suite green — while being the only rule reaching a short
        // argument the SASL base64 floor does not.
        yield 'AUTHENTICATE with a short argument' => ['a01 AUTHENTICATE PLAIN c2VjcmV0', ['c2VjcmV0']];

        // Unpadded base64 with a line prefix — the union case. Two fixtures pinned prefixed+padded
        // and unpadded+standalone; neither pinned the intersection, which is the same real trace
        // with a password whose length is a multiple of three.
        yield 'unpadded base64 behind a trace prefix' => [
            'CLIENT -> SERVER: bW90ZGVwYXNzZXNl',
            ['bW90ZGVwYXNzZXNl'],
        ];
        yield 'base64 blob on a CRLF line' => [
            "334 UGFzc3dvcmQ6\r\nSHVudGVyMiFzZWNyZXRwYXNz\r\n535 5.7.8 auth failed",
            ['SHVudGVyMiFzZWNyZXRwYXNz'],
        ];

        // base64_encode() of a 16-character app password is 22 chars with no digit — so a rule
        // demanding 24 AND a digit missed exactly the secret the plaintext rule leaks.
        // `base64_encode()` of a 16-character app password is 22–24 characters. This one is also
        // DIGIT-FREE, which is the case the `==` rule exists for: the single-`=` rule demands a
        // digit, `+` or `/`, so a blob made only of letters reaches nothing else.
        yield 'base64 app password, padded and digit-free' => [
            'CLIENT -> SERVER: dmtzbnRwbHlrdGdwdHdocw==',
            ['dmtzbnRwbHlrdGdwdHdocw'],
        ];

        // A blob on its own line with NO padding at all — a secret whose length is a multiple of
        // three. Nothing precedes it, so no verb rule reaches it either; only the whole-line rule
        // does. SMTP AUTH puts the user and the password on separate lines exactly like this.
        yield 'unpadded base64 alone on a line' => [
            "334 UGFzc3dvcmQ6\nSHVudGVyMiFzZWNyZXRwYXNz\n535 5.7.8 auth failed",
            ['SHVudGVyMiFzZWNyZXRwYXNz'],
        ];

        // IMAP is CRLF on the wire; every other fixture here uses bare newlines.
        yield 'CRLF multi-line trace' => [
            "a01 LOGIN alice hunter2secret\r\na02 NO auth failed\r\n",
            ['hunter2secret'],
        ];
    }

    /**
     * The masker must not eat the words that say what went wrong.
     *
     * Each of these is a real diagnostic shape that an over-eager pattern destroyed: `auth:` before
     * a URL, `key:` in a YAML error, `Login failed for` before the actual failure, and a `?` query
     * containing an `@` which made the userinfo pattern mask the HOST — pointing a debugging human
     * at the wrong server entirely.
     *
     * @return iterable<string, array{string, list<string>}>
     */
    public static function diagnostics(): iterable
    {
        yield 'auth: before a URL' => [
            'Erreur auth: https://www.inli.fr/api/recherche a répondu 403 Forbidden',
            ['https://www.inli.fr/api/recherche', '403'],
        ];

        yield 'key: in a config error' => [
            'erreur config à la ligne 12 : key: sources n\'est pas une liste',
            ['sources', 'ligne 12'],
        ];

        yield 'Login failed for' => [
            'imap_open(): Login failed for user=alertes host=imap.example.net',
            ['Login failed for', 'imap.example.net'],
        ];

        // THE COUNTERWEIGHT to the percent-encoded mailbox above: masking the address must not eat
        // the endpoint that says WHICH request failed. Same asymmetry the literal-`@` case has had
        // since it was written.
        yield 'a percent-encoded address does not take the endpoint with it' => [
            'GET https://www.pap.fr/annonces/x-r1?email=x%40example.test&md5=deadbeef a échoué',
            ['www.pap.fr', '/annonces/x-r1', 'a échoué'],
        ];

        yield 'host:port with an @ in the query' => [
            'GET https://host.example.com:8443/api?redirect=agent@example.com timed out',
            ['host.example.com:8443', '/api'],
        ];

        yield 'a value that is itself a URL is not the secret' => [
            // The secret would be INSIDE such a URL, and the query-parameter branch reaches it on
            // its own pass. Masking the whole value destroys the endpoint instead.
            'callback refused: key=https://callback.test/hook?apikey=abc123XYZ',
            ['https://callback.test/hook'],
        ];

        // Real protocol text and real French prose. The verb pattern is case-SENSITIVE and requires
        // its argument to contain a digit or a symbol precisely because of these: an English or
        // French word does not, and an accented one is still a word.
        yield 'RFC 1939 POP3 state error' => [
            '-ERR PASS command issued in wrong state',
            ['PASS command issued'],
        ];

        yield 'French failure word after an uppercase verb' => [
            'LOGIN refusé par le serveur imap.example.net',
            ['refusé', 'imap.example.net'],
        ];

        yield 'the host after a lowercase Login' => [
            'Login to imap.example.net:993 failed',
            ['imap.example.net:993'],
        ];

        yield 'Pass Navigo is a transit card, not a credential' => [
            'abonnement Pass Navigo non reconnu par PRIM',
            ['Pass Navigo'],
        ];

        // The count is what a case-INSENSITIVE verb pattern eats first: `pass 2` looks exactly like
        // a credential to it, because `2` is a digit.
        yield 'an item count is not a credential' => [
            'pass 2 sur 3 : 27 annonces',
            ['pass 2 sur 3', '27 annonces'],
        ];

        yield 'the SASL mechanism name is the diagnostic' => [
            'AUTHENTICATE XOAUTH2 rejected, server requires PLAIN over TLS',
            ['XOAUTH2'],
        ];

        // `$hasAlnum`: a value made only of punctuation is not a secret, and `unexpected token: }`
        // is the canonical JSON parse failure — the message a broken adapter emits.
        yield 'a punctuation-only value is not a secret' => [
            'JSON error at offset 412: unexpected token: }',
            ['unexpected token: }'],
        ];

        // The base64 blob rule needs BOTH the `=` padding and a digit/`+`/`/`, or it eats the long
        // camelCase identifiers French portal APIs produce — name-first, keeping the value.
        yield 'a long camelCase query parameter is not base64' => [
            'GET https://www.inli.fr/recherche?typeDeBienRechercheParUtilisateur=appartement -> 500',
            ['typeDeBienRechercheParUtilisateur=appartement'],
        ];

        yield 'a long identifier without padding is not base64' => [
            'champ inconnu: caracteristiquesDuLogement2Principal ignoré',
            ['caracteristiquesDuLogement2Principal'],
        ];

        // The verb rule is case-SENSITIVE: a real protocol trace shouts, French prose does not.
        // An untagged LOGIN is a server response or French prose, never a command.
        yield 'an untagged LOGIN is prose' => [
            'échec LOGIN sur imap.example.net',
            ['LOGIN sur imap.example.net'],
        ];

        yield 'an untagged AUTHENTICATE is a server response' => [
            'a01 BAD AUTHENTICATE mechanism not supported',
            ['mechanism not supported'],
        ];

        yield 'PASS followed by a protocol word' => ['-ERR PASS required', ['PASS required']];

        // The stoplist's FRENCH half, and its case-insensitivity. The docblock justifies both —
        // "this project's own messages are French" — and only an English lowercase entry was pinned.
        yield 'PASS followed by a French protocol word' => [
            '-ERR PASS erreur de syntaxe',
            ['PASS erreur de syntaxe'],
        ];
        yield 'PASS followed by a capitalised word' => [
            '-ERR PASS Command issued in wrong state',
            ['PASS Command issued'],
        ];
        // `refusé` was a dead entry: without a `u` flag, `\b` after a multi-byte character can
        // never assert, and it is the only accented word in the list.
        yield 'PASS followed by an accented French word' => [
            '-ERR PASS refusé par le serveur',
            ['PASS refusé par le serveur'],
        ];

        // Diagnostics the whole-line base64 rule ate before it required a multiple of four and both
        // cases. `conventionnement` is §1 classifier vocabulary.
        yield 'an all-caps token alone on a line' => [
            "IMAP:\nAUTHENTICATIONFAILED\ncredentials rejected",
            ['AUTHENTICATIONFAILED'],
        ];
        yield 'a French domain word alone on a line' => [
            "régime détecté:\nconventionnement\nsur la fiche",
            ['conventionnement'],
        ];
        yield 'a path alone on a line' => [
            "fixture introuvable:\ntests/fixtures/rent/tenure\nabandon",
            ['tests/fixtures/rent/tenure'],
        ];
        yield 'a bare SHA-256 alone on a line' => [
            "checksum mismatch\n9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08\nexpected",
            ['9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08'],
        ];

        // The camel boundaries' `(?-i:)` wrappers and the "real hump" requirement. Degrading any of
        // the three re-opens the over-masking that was a P0 in two consecutive rounds.
        yield 'SCREAMING_CASE is not a camel hump' => ['PASSAGE=interdit sur la ligne 4', ['PASSAGE=interdit']];
        yield 'SIGNAL in caps is not a secret' => ['SIGNAL=SIGTERM reçu', ['SIGNAL=SIGTERM']];
        yield 'passerelle is not a pass' => ['passerelle=inli-proxy', ['passerelle=inli-proxy']];
        // `bypass` is `pass` with a lowercase prefix — only the case-sensitivity of the LEFT camel
        // assertion keeps it out. `robots.txt bypass` is this project's own hard-rule-5 vocabulary.
        yield 'bypass is not a pass' => [
            'robots.txt bypass: refusé par la configuration',
            ['bypass: refusé par la configuration'],
        ];
        // Mixed case and 21 characters — it satisfies the whole-line rule's case requirement, so
        // only the multiple-of-four requirement keeps it intact.
        yield 'a mixed-case class name alone on a line' => [
            "classe introuvable:\nCommissionAttribution\nabandon",
            ['CommissionAttribution'],
        ];

        yield 'a lowercase verb at end of line is prose' => [
            'réponse inattendue: pass invalide',
            ['pass invalide'],
        ];

        yield 'rents and counts are not secrets' => [
            '0 annonces — loyer=1450 charges=120 surface=68',
            ['loyer=1450', 'charges=120', 'surface=68'],
        ];
    }

    /** @param list<string> $mustSurvive */
    #[DataProvider('diagnostics')]
    public function testTheDiagnosticIsNotDestroyed(string $raw, array $mustSurvive): void
    {
        $masked = Redact::text($raw);

        self::assertNotNull($masked);

        foreach ($mustSurvive as $fragment) {
            self::assertStringContainsString(
                $fragment,
                $masked,
                sprintf('« %s » was masked away; a masker that eats diagnostics gets deleted', $fragment),
            );
        }
    }

    /** @param list<string> $mustVanish */
    #[DataProvider('secrets')]
    public function testTheSecretDoesNotSurvive(string $raw, array $mustVanish): void
    {
        $masked = Redact::text($raw);

        self::assertNotNull($masked);

        foreach ($mustVanish as $secret) {
            self::assertStringNotContainsString($secret, $masked, sprintf('« %s » survived masking', $secret));
        }
    }

    /**
     * The message must stay diagnosable. A masker that eats the host and the status code gets
     * deleted by the next person who has to debug a broken source at midnight, and then nothing
     * masks anything.
     */
    public function testTheDiagnosticSurvives(): void
    {
        $masked = Redact::text('HTTP 503 from https://prim.iledefrance-mobilites.fr/v2/journey?apikey=abc123XYZ');

        self::assertNotNull($masked);
        self::assertStringContainsString('503', $masked);
        self::assertStringContainsString('prim.iledefrance-mobilites.fr', $masked);
        self::assertStringContainsString('/v2/journey', $masked);
    }

    /** An ordinary adapter error passes through unchanged — masking is not paraphrasing. */
    public function testAnErrorWithNoSecretIsUntouched(): void
    {
        $plain = 'HTTP 503 Service Unavailable after 3 attempts (selector .listing-card matched 0 nodes)';

        self::assertSame($plain, Redact::text($plain));
    }

    /**
     * Masking twice must not corrupt the mask.
     *
     * Two patterns could both match one name — `X-Api-Key` fires on `api-key` and then on `key` —
     * and the second consumed `[masqué` from the first, leaving an orphan `]`. Repeated calls
     * accumulated brackets.
     */
    public function testMaskingIsIdempotent(): void
    {
        $once = Redact::text('X-Api-Key: abcdefgh12345');
        self::assertSame('X-Api-Key=' . Redact::MASK, $once);
        self::assertSame($once, Redact::text((string) $once));
    }

    public function testNullAndEmptyPassThrough(): void
    {
        self::assertNull(Redact::text(null));
        self::assertSame('', Redact::text(''));
    }

    /** Masking must not be defeated by the case a real header actually uses. */
    public function testMaskingIsCaseInsensitive(): void
    {
        foreach (['APIKEY=abc123XYZ', 'ApiKey: abc123XYZ', 'TOKEN=abc123XYZ'] as $raw) {
            $masked = Redact::text($raw);

            self::assertNotNull($masked);
            self::assertStringNotContainsString('abc123XYZ', $masked, $raw);
        }
    }

    /** Malformed bytes are still masked — they are an adapter artefact, not an escape hatch. */
    public function testMalformedInputNeverReturnsTheRawText(): void
    {
        $masked = Redact::text("token=s3cr3tvalue \xC3\x28 \xFF\xFE broken");

        self::assertNotNull($masked);
        self::assertStringNotContainsString('s3cr3tvalue', $masked);
    }

    /**
     * A PCRE failure must fail CLOSED, and the branch that does so is reachable.
     *
     * `preg_replace` returns null on a backtrack-limit exhaustion. Passing that through would hand
     * the caller the ORIGINAL unmasked text — the one outcome this class exists to prevent — so it
     * returns the mask instead and loses the diagnostic. Losing a diagnostic is recoverable;
     * leaking a credential into a database and a push notification is not.
     *
     * The limit is lowered here rather than a catastrophic input constructed, because that makes
     * the trigger exact and the test fast. Without this the fail-closed branch is unreachable in
     * practice, i.e. dead safety code — which this repo has deleted once already for good reason.
     */
    public function testAPcreFailureFailsClosed(): void
    {
        $original = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '1');

        try {
            $masked = Redact::text('token=s3cr3tvalue ' . str_repeat('ab ', 200));

            self::assertSame(Redact::MASK, $masked);
            self::assertStringNotContainsString('s3cr3tvalue', (string) $masked);
        } finally {
            ini_set('pcre.backtrack_limit', $original === false ? '1000000' : $original);
        }
    }
}
