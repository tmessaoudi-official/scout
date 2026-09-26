<?php

declare(strict_types=1);

namespace Scout\Tests\Rent\Adapters;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Scout\Rent\Adapters\EmailAlertSource;
use Scout\Adapters\Http\CurlHttpClient;
use Scout\Adapters\Http\HttpClient;
use Scout\Adapters\Http\HttpError;
use Scout\Adapters\Http\HttpRequest;
use Scout\Adapters\Http\HttpResponse;
use Scout\Adapters\Http\Robots;
use Scout\Rent\Adapters\HttpJsonSource;
use Scout\Adapters\Mail\EmailMessage;
use Scout\Adapters\Mail\FileMailbox;
use Scout\Adapters\Mail\ImapMailbox;
use Scout\Adapters\Mail\Mailbox;
use Scout\Adapters\Mail\MailboxError;
use Scout\Adapters\SourceError;
use Scout\Rent\Config\ConfigLoader;
use Scout\Rent\Config\FieldMap;
use Scout\Rent\Config\SourceDefinition;
use Scout\Core\Notify\ChannelError;
use Scout\Core\Notify\FileTransport;
use Scout\Core\Notify\SendmailTransport;
use Scout\Core\Notify\SmtpTransport;
use Scout\Rent\Core\Outcome;
use Scout\Rent\Core\Tenure;
use Scout\Rent\Store\Store;

/**
 * The network adapters, exercised offline.
 *
 * These exist because a distinction got blurred and needed correcting: `CLAUDE.md` hard rule 1
 * forbids writing an ENDPOINT from memory, and says nothing about the transport. The adapters were
 * called "blocked" when only the URL in `config/rent/sources.json` was. Every class here is fully tested
 * against a fake client or a directory of `.eml` files, and the only untested strip is the socket.
 *
 * Categories: **robots** (hard rule 5) · **http** (hard rule 3, honest identification) ·
 * **mime** (charset, folding, multipart) · **email extraction** · **transports** (secrets).
 */
final class NetworkAdaptersTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../../..';

    private ?string $dbPath = null;

    protected function tearDown(): void
    {
        if ($this->dbPath !== null) {
            foreach (['', '-wal', '-shm'] as $suffix) {
                @unlink($this->dbPath . $suffix);
            }
            $this->dbPath = null;
        }
    }

    private function store(): Store
    {
        $this->dbPath = sys_get_temp_dir() . '/rentwatch-net-' . bin2hex(random_bytes(8)) . '.sqlite3';

        return Store::open($this->dbPath);
    }

    // ---------------------------------------------------------------- robots (hard rule 5)

    #[DataProvider('robotsCases')]
    public function testRobots(string $file, string $path, bool $allowed, string $why): void
    {
        self::assertSame($allowed, Robots::parse($file, 'scout')->allows($path), $why);
    }

    /** @return iterable<string, array{string,string,bool,string}> */
    public static function robotsCases(): iterable
    {
        yield 'the CDC Habitat shape from Q15' => [
            "User-agent: *\nDisallow: /Recherche/show/",
            '/Recherche/show/12345',
            false,
            'the exact rule Q15 measured — this source must not be polled until its endpoint is known to sit outside it',
        ];
        yield 'a sibling path is unaffected' => [
            "User-agent: *\nDisallow: /Recherche/show/",
            '/api/annonces',
            true,
            'the disallow is a prefix, not a whole-site ban',
        ];
        yield 'an empty Disallow allows everything' => [
            "User-agent: *\nDisallow:",
            '/anything',
            true,
            'the documented way to opt out — reading it as "disallow /" would ban a site that welcomed us',
        ];
        yield 'Allow beats a shorter Disallow' => [
            "User-agent: *\nDisallow: /search\nAllow: /search/api",
            '/search/api/v1',
            true,
            'longest match wins, which is the convention a site author writes against',
        ];
        yield 'a longer Disallow beats a shorter Allow' => [
            "User-agent: *\nAllow: /\nDisallow: /search/private",
            '/search/private/x',
            false,
            '',
        ];
        yield 'a wildcard in the middle' => [
            "User-agent: *\nDisallow: /*/private",
            '/anything/private',
            false,
            'real files use wildcards heavily',
        ];
        yield 'an anchored rule does not match a longer path' => [
            "User-agent: *\nDisallow: /search$",
            '/search/results',
            true,
            '$ anchors the end',
        ];
        yield 'a group naming us beats the wildcard group ABOVE it' => [
            "User-agent: *\nDisallow: /\n\nUser-agent: scout\nDisallow: /admin",
            '/annonces',
            true,
            'a single pass taking the first matching group would let the `*` block mask ours',
        ];
        yield 'the query string is part of what a rule can match' => [
            "User-agent: *\nDisallow: /*?search=",
            '/list?search=t4',
            false,
            'Disallow: /*?search= is a real shape, so the query must not be stripped before matching',
        ];
        yield 'a file about someone else says nothing about us' => [
            "User-agent: GPTBot\nDisallow: /",
            '/annonces',
            true,
            'no group applies — the ordinary no-restrictions case',
        ];
    }

    public function testAnUnreadableRobotsFileDisallowsEverything(): void
    {
        // FAIL CLOSED, which is the opposite of the usual convention and deliberate: a false
        // "disallowed" costs one source staying off until someone looks; a false "allowed" costs
        // polling a site that asked us not to, with an honest User-Agent naming whose tool it is.
        self::assertFalse(Robots::unavailable()->allows('/anything'));
    }

    public function testPathOfKeepsTheQueryAndDefaultsToRoot(): void
    {
        self::assertSame('/a/b?x=1', Robots::pathOf('https://example.test/a/b?x=1'));
        self::assertSame('/', Robots::pathOf('https://example.test'));
    }

    // ---------------------------------------------------------------- http (hard rule 3)

    /**
     * A REDIRECT NAMES WHERE IT POINTS (row 44, 2026-09-05). In'li answered 302 on two of five live
     * passes and the run log held only `HTTP 302 from <url>` — enough to say the source broke, not
     * enough to tell a moved page from a cookie bounce or a shield. The failure must carry the
     * `Location`, so the next occurrence diagnoses itself.
     */
    public function testARedirectFailureNamesItsLocation(): void
    {
        try {
            $this->httpSource(new FakeHttpClient(new HttpResponse(302, '', ['location' => 'https://example.test/shield?u=abc'])))->fetch();
            self::fail('a 3xx is a failure');
        } catch (SourceError $e) {
            self::assertStringContainsString('HTTP 302 from https://example.test/api/search', $e->getMessage());
            self::assertStringContainsString('Location: https://example.test/shield?u=abc', $e->getMessage());
        }

        // A 3xx WITHOUT a Location says so by saying nothing extra — never a fabricated target.
        try {
            $this->httpSource(new FakeHttpClient(new HttpResponse(302, '')))->fetch();
            self::fail('a 3xx is a failure');
        } catch (SourceError $e) {
            self::assertStringNotContainsString('Location', $e->getMessage());
        }
    }

    private function httpSource(HttpClient $client, array $overrides = [], ?Robots $robots = null): HttpJsonSource
    {
        $definition = new SourceDefinition(
            name: $overrides['name'] ?? 'net',
            enabled: true,
            family: 'institutional',
            type: 'json',
            mixedTenure: true,
            url: $overrides['url'] ?? 'https://example.test/api/search',
            itemsPath: $overrides['itemsPath'] ?? 'results.items',
            map: new FieldMap(ref: ['id'], title: ['title'], commune: ['city'], rent: ['rent'], chargesIncluded: true),
        );

        return new HttpJsonSource($definition, $this->store(), $client, $robots ?? Robots::parse(''));
    }

    public function testAWorkingEndpointMapsListingsThroughTheSameCodeTheFixtureAdapterUses(): void
    {
        $client = new FakeHttpClient(new HttpResponse(200, (string) json_encode([
            'results' => ['items' => [
                ['id' => 'x1', 'title' => 'T4 Sartrouville', 'city' => 'Sartrouville', 'rent' => '1 450 €'],
            ]],
        ])));

        $listings = $this->httpSource($client)->fetch();

        self::assertCount(1, $listings);
        self::assertSame('x1', $listings[0]->externalId);
        self::assertSame(1450, $listings[0]->rentCc, 'the shared Payload parser handles the French formatting');
    }

    public function testANonSuccessStatusIsAFailureNotAnEmptyResult(): void
    {
        // Hard rule 3. `docs/SOURCES.md` records five portals answering 403 to a plain client; that
        // fact must reach the run log, because it is what says "use the email route" rather than
        // "the market is quiet".
        $this->expectException(SourceError::class);
        $this->expectExceptionMessageMatches('~HTTP 403~');

        $this->httpSource(new FakeHttpClient(new HttpResponse(403, 'nope')))->fetch();
    }

    public function testA403SuggestsTheEmailRouteRatherThanAWorkaround(): void
    {
        // Hard rule 5: never propose CAPTCHA solving, proxy rotation or fingerprint spoofing. The
        // message is where that guidance actually reaches whoever is debugging.
        try {
            $this->httpSource(new FakeHttpClient(new HttpResponse(403, '')))->fetch();
            self::fail('expected a SourceError');
        } catch (SourceError $e) {
            self::assertStringContainsString('email-alert', $e->getMessage());
        }
    }

    public function testATransportFailureIsWrappedAsASourceError(): void
    {
        $this->expectException(SourceError::class);
        $this->expectExceptionMessageMatches('~timed out~');

        $this->httpSource(new FakeHttpClient(null, new HttpError('the request timed out')))->fetch();
    }

    public function testMalformedJsonIsALoudFailure(): void
    {
        $this->expectException(SourceError::class);
        $this->expectExceptionMessageMatches('~not valid JSON~');

        $this->httpSource(new FakeHttpClient(new HttpResponse(200, '{"results": [')))->fetch();
    }

    public function testAMovedItemsPathThrowsRatherThanReadingAsAQuietMarket(): void
    {
        $this->expectException(SourceError::class);
        $this->expectExceptionMessageMatches('~is absent from the response~');

        $this->httpSource(new FakeHttpClient(new HttpResponse(200, '{"data":{"list":[]}}')))->fetch();
    }

    public function testAPlaceholderUrlIsRefusedEvenWhenTheSourceIsBuiltInCode(): void
    {
        // The config loader already refuses `enabled: true` next to REMPLACER; this second check
        // covers a source constructed in code, which is how a test or a future `--dry-run` builds one.
        $this->expectException(SourceError::class);
        $this->expectExceptionMessageMatches('~hard rule 1~');

        $this->httpSource(new FakeHttpClient(new HttpResponse(200, '{}')), ['url' => 'https://example.test/REMPLACER'])->fetch();
    }

    public function testADisallowedPathIsNeverFetched(): void
    {
        $client = new FakeHttpClient(new HttpResponse(200, '{"results":{"items":[]}}'));
        $robots = Robots::parse("User-agent: *\nDisallow: /api/");

        try {
            $this->httpSource($client, [], $robots)->fetch();
            self::fail('expected a SourceError');
        } catch (SourceError $e) {
            self::assertStringContainsString('robots.txt', $e->getMessage());
            self::assertSame(0, $client->calls, 'the request must not be made at all');
        }
    }

    public function testTheUserAgentIdentifiesHonestlyRatherThanDisguising(): void
    {
        // Hard rule 5: identify honestly in the User-Agent. This is the rule the project is most
        // explicit about, and a sabotage run showed nothing asserted the string — a browser
        // disguise left the whole suite green. The shape is pinned: the product name leads, a
        // contact route is present, and no browser family token appears anywhere.
        $agent = CurlHttpClient::USER_AGENT;

        self::assertStringStartsWith('scout/', $agent);
        self::assertStringContainsString('contact', $agent);

        foreach (['mozilla', 'chrome', 'chromium', 'safari', 'firefox', 'gecko', 'applewebkit', 'edg/', 'opera'] as $disguise) {
            self::assertStringNotContainsStringIgnoringCase($disguise, $agent);
        }
    }

    public function testTheHonestUserAgentIsWhatActuallyCrossesTheWire(): void
    {
        // The constant test above cannot see the WIRING: cURL sends whatever CURLOPT_USERAGENT
        // received, and a review demonstrated a disguise at that one line — constant left honest,
        // every test green. So this test IS the server, on loopback, and asserts on the request
        // head it actually received from real libcurl.
        $transcriptPath = sys_get_temp_dir() . '/rentwatch-http-transcript-' . bin2hex(random_bytes(6)) . '.txt';

        $proc = proc_open(
            [PHP_BINARY, __DIR__ . '/../../Adapters/scripted-http-server.php', $transcriptPath],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($proc, 'the scripted HTTP server must start');

        try {
            $name = fgets($pipes[1], 256);
            self::assertIsString($name, 'the server must report the port it chose');
            $port = (int) substr(strrchr(trim($name), ':') ?: ':0', 1);
            self::assertGreaterThan(0, $port);

            $response = (new CurlHttpClient())
                ->send(new HttpRequest('http://127.0.0.1:' . $port . '/items.json', timeoutSeconds: 5));
            self::assertSame(200, $response->status);
        } finally {
            foreach ($pipes as $pipe) {
                @fclose($pipe);
            }
            proc_close($proc);
        }

        $head = (string) @file_get_contents($transcriptPath);
        @unlink($transcriptPath);

        self::assertStringContainsString('User-Agent: ' . CurlHttpClient::USER_AGENT, $head);
        self::assertStringNotContainsStringIgnoringCase('mozilla', $head);
    }

    /**
     * THE TRANSPORT NEVER FOLLOWS A REDIRECT (2026-09-26). A hop libcurl takes on its own happens
     * below every guard this client and its callers apply — the offline switch, the header refusals,
     * and above all `robots.txt`, which a source checks per URL before it sends. So a 3xx must come
     * back to the caller, who alone decides: every adapter refuses it as a non-2xx, and the one hop
     * this project follows (`SitemapVehicleSource::sameLotTarget()`) is robots-checked and paced.
     *
     * The target is a dead loopback port on purpose: a followed hop fails on this machine, and a
     * sabotaged client can never reach a third-party host from CI. The Location assertion is the
     * other half — it proves on a real socket that curl's `Location:` reaches the lowercase lookup
     * the Autohero hop reads, which a table client cannot show.
     */
    public function testARedirectIsReturnedToTheCallerRatherThanFollowed(): void
    {
        $transcriptPath = sys_get_temp_dir() . '/rentwatch-http-transcript-' . bin2hex(random_bytes(6)) . '.txt';
        $target = 'http://127.0.0.1:1/moved';

        $proc = proc_open(
            [PHP_BINARY, __DIR__ . '/../../Adapters/scripted-http-server.php', $transcriptPath, 'redirect:' . $target],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($proc, 'the scripted HTTP server must start');

        try {
            $name = fgets($pipes[1], 256);
            self::assertIsString($name, 'the server must report the port it chose');
            $port = (int) substr(strrchr(trim($name), ':') ?: ':0', 1);
            self::assertGreaterThan(0, $port);

            $response = (new CurlHttpClient())
                ->send(new HttpRequest('http://127.0.0.1:' . $port . '/lot', timeoutSeconds: 5));
            self::assertSame(301, $response->status);
            self::assertSame($target, $response->header('Location'));
        } finally {
            foreach ($pipes as $pipe) {
                @fclose($pipe);
            }
            proc_close($proc);
            @unlink($transcriptPath);
        }
    }

    /**
     * `SCOUT_OFFLINE=1` refuses any request to a third-party host.
     *
     * Set by `tests/bootstrap.php` for the whole suite. Spec §11 says parser tests run offline, and
     * until 2026-08-19 that held only BY ACCIDENT: every source in the shipped config was disabled,
     * so the tests that run the real CLI against the real config had nothing to poll. Enabling the
     * first real source turned the suite into a four-page-per-test crawler of a live landlord's
     * site inside one run — which is both a broken test contract and, under hard rule 5, load on
     * somebody else's server every time CI fires.
     */
    public function testTheOfflineSwitchRefusesAThirdPartyRequest(): void
    {
        self::assertSame('1', getenv('SCOUT_OFFLINE'), 'the bootstrap must set this for every test');

        $this->expectException(HttpError::class);
        $this->expectExceptionMessageMatches('~SCOUT_OFFLINE=1~');

        (new CurlHttpClient())->send(new HttpRequest('https://www.inli.fr/locations/offres/x'));
    }

    public function testTheOfflineSwitchStillAllowsALoopbackServer(): void
    {
        // Not a loophole: what the switch forbids is contacting somebody else's server. A scripted
        // server on 127.0.0.1 is how this project proves the things only a real socket can — that
        // the honest User-Agent is what actually crosses the wire, and that SMTP refuses a
        // credential without STARTTLS. Banning those deletes real evidence to enforce a rule they
        // do not break.
        //
        // Port 1 has nothing listening, so the request fails either way. What is asserted is WHICH
        // failure: a connection error means the offline gate let it through to the socket.
        try {
            (new CurlHttpClient())->send(new HttpRequest('http://127.0.0.1:1/never-reached'));
            self::fail('expected the connection to be refused');
        } catch (HttpError $e) {
            self::assertStringNotContainsString('SCOUT_OFFLINE', $e->getMessage());
        }
    }

    public function testAUserAgentHeaderCannotOverrideTheHonestOne(): void
    {
        // In cURL, a User-Agent entry in CURLOPT_HTTPHEADER silently overrides CURLOPT_USERAGENT —
        // so without the funnel guard, one request header (from config or from any future caller)
        // would disguise the poller while the honest constant sat unread. Lowercase on purpose:
        // the guard must be case-insensitive, because HTTP header names are.
        $this->expectException(HttpError::class);
        $this->expectExceptionMessageMatches('~User-Agent header cannot be overridden~');

        (new CurlHttpClient())->send(new HttpRequest(
            'http://127.0.0.1:1/never-reached',
            headers: ['user-agent' => 'Mozilla/5.0 (disguise)'],
        ));
    }

    public function testAColonSmuggledHeaderNameIsRefusedAtTheFunnel(): void
    {
        // The round-2 bypass: libcurl reads the header NAME from the text before the first colon,
        // so a KEY of `User-Agent: Mozilla…` cleared the equality guard and put a browser UA on
        // the wire. A colon can never appear in an RFC 7230 token, so the token check refuses
        // every spelling of that shape at once.
        $this->expectException(HttpError::class);
        $this->expectExceptionMessageMatches('~not a valid HTTP token~');

        (new CurlHttpClient())->send(new HttpRequest(
            'http://127.0.0.1:1/never-reached',
            headers: ['User-Agent: Mozilla/5.0 (evasion)' => 'x'],
        ));
    }

    public function testALineBreakInAHeaderValueIsRefusedAtTheFunnel(): void
    {
        // Same defect class arriving through the VALUE: a CRLF inside it starts a second header
        // line of the attacker's choosing. Refused before anything reaches libcurl.
        $this->expectException(HttpError::class);
        $this->expectExceptionMessageMatches('~header injection~');

        (new CurlHttpClient())->send(new HttpRequest(
            'http://127.0.0.1:1/never-reached',
            headers: ['X-Custom' => "benign\r\nUser-Agent: Mozilla/5.0"],
        ));
    }

    public function testATrailingNewlineInAHeaderNameIsRefusedAtTheFunnel(): void
    {
        // The token guard's own edge: PHP's `$` matches before a single trailing newline, so
        // `"user-agent\n"` would pass a `$`-anchored class AND dodge the equality guard (it no
        // longer string-equals `user-agent`), putting a raw LF into the request headers. The `D`
        // modifier is what refuses it; this test is what proves the modifier stays.
        $this->expectException(HttpError::class);
        $this->expectExceptionMessageMatches('~not a valid HTTP token~');

        (new CurlHttpClient())->send(new HttpRequest(
            'http://127.0.0.1:1/never-reached',
            headers: ["user-agent\n" => 'Mozilla/5.0 (evasion)'],
        ));
    }

    // ---------------------------------------------------------------- MIME

    public function testALatin1MessageIsReadAsCp1252SoTheEuroSignSurvives(): void
    {
        // THE BUG RUNNING IT EXPOSED. Mail declaring ISO-8859-1 is almost always CP1252, and they
        // differ exactly in 0x80-0x9F — where `€` lives. Under strict Latin-1 the euro sign becomes
        // an invisible control character, and EVERY rent pattern requires a currency marker, so the
        // rent came out NULL on an alert that stated it plainly.
        $raw = "Content-Type: text/plain; charset=ISO-8859-1\n"
            . "Content-Transfer-Encoding: quoted-printable\n\n"
            . "Loyer : 1 450 =80 charges comprises\nSurface : 88 m=B2\n";

        $message = EmailMessage::parse($raw);

        self::assertStringContainsString('€', $message->body);
        self::assertStringContainsString('m²', $message->body);
    }

    public function testAnEncodedWordSubjectIsDecoded(): void
    {
        $raw = "Subject: =?UTF-8?Q?Nouvelle_annonce_:_T4_=C3=A0_Sartrouville?=\n\nbody";

        self::assertSame('Nouvelle annonce : T4 à Sartrouville', EmailMessage::parse($raw)->subject());
    }

    public function testAFoldedHeaderIsJoinedRatherThanTruncated(): void
    {
        // Real subjects fold constantly; a parser that ignores continuation lines truncates them all.
        $raw = "Subject: Nouvelle annonce\n  pour votre alerte T4\n\nbody";

        self::assertSame('Nouvelle annonce pour votre alerte T4', EmailMessage::parse($raw)->subject());
    }

    public function testThePlainTextPartIsPreferredOverHtml(): void
    {
        $raw = "Content-Type: multipart/alternative; boundary=\"b\"\n\n"
            . "--b\nContent-Type: text/html\n\n<p>HTML VERSION</p>\n"
            . "--b\nContent-Type: text/plain\n\nTEXTE BRUT\n--b--";

        self::assertStringContainsString('TEXTE BRUT', EmailMessage::parse($raw)->body);
        self::assertStringNotContainsString('HTML VERSION', EmailMessage::parse($raw)->body);
    }

    public function testAnHtmlOnlyMessageHasItsBlockTagsTurnedIntoNewlines(): void
    {
        // Otherwise `<li>Chatou</li><li>Houilles</li>` collapses into `ChatouHouilles`, which
        // matches neither commune. EVERY member of the tag class is exercised, not a sample: a
        // sabotage degraded `</p>` alone and stayed green because only `</li>` was asserted —
        // a correct rule tested on a subset of the surfaces it belongs on.
        foreach (['p', 'div', 'li', 'tr', 'td', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'] as $tag) {
            $raw = "Content-Type: text/html; charset=UTF-8\n\n"
                . sprintf('<%1$s>Chatou</%1$s><%1$s>Houilles</%1$s>', $tag);

            self::assertMatchesRegularExpression(
                '~Chatou\n+\s*Houilles~',
                EmailMessage::parse($raw)->body,
                sprintf('</%s> must become a newline, not vanish', $tag),
            );
        }

        foreach (['<br>', '<br/>', '<br />', '</br>'] as $br) {
            $raw = "Content-Type: text/html; charset=UTF-8\n\nChatou" . $br . 'Houilles';

            self::assertMatchesRegularExpression(
                '~Chatou\n+\s*Houilles~',
                EmailMessage::parse($raw)->body,
                $br . ' must become a newline, not vanish',
            );
        }
    }

    public function testCrlfLineEndingsDoNotBreakTheHeaderBodySplit(): void
    {
        $raw = "Subject: Test\r\nContent-Type: text/plain\r\n\r\nle corps du message";

        self::assertSame('Test', EmailMessage::parse($raw)->subject());
        self::assertStringContainsString('le corps', EmailMessage::parse($raw)->body);
    }

    public function testBase64BodiesAreDecoded(): void
    {
        $raw = "Content-Type: text/plain; charset=UTF-8\nContent-Transfer-Encoding: base64\n\n"
            . base64_encode('Loyer : 1450 € CC');

        self::assertStringContainsString('1450 €', EmailMessage::parse($raw)->body);
    }

    // ---------------------------------------------------------------- email extraction

    private function emailSource(array $params = []): EmailAlertSource
    {
        $definition = new SourceDefinition(
            name: 'email_demo',
            enabled: true,
            family: 'private',
            type: 'email_alert',
            mixedTenure: true,
            defaultTenure: Tenure::LIBRE,
            params: $params + ['from' => 'example-portal.test', 'link_host' => 'example-portal.test'],
            map: new FieldMap(ref: ['url'], chargesIncluded: true),
        );

        return new EmailAlertSource(
            $definition,
            $this->store(),
            new FileMailbox(self::ROOT . '/tests/fixtures/rent/mailbox'),
            ConfigLoader::loadCriteria(self::ROOT . '/config/rent/criteria.json')->communeLabels,
        );
    }

    public function testTheAlertFixtureYieldsOneListingPerRealLink(): void
    {
        $listings = $this->emailSource()->fetch();

        self::assertCount(2, $listings, 'the unsubscribe link must not become a listing');
        foreach ($listings as $listing) {
            self::assertStringNotContainsString('desabonnement', (string) $listing->url);
        }
    }

    public function testFactsAreExtractedFromTheAlertText(): void
    {
        $byId = [];
        foreach ($this->emailSource()->fetch() as $l) {
            $byId[basename($l->externalId)] = $l;
        }

        $listing = $byId['12345'];
        self::assertSame(1450, $listing->rentCc);
        self::assertSame(88.0, $listing->surfaceM2);
        self::assertSame(4, $listing->rooms);
        self::assertSame('Sartrouville', $listing->commune);
        self::assertSame('78500', $listing->postcode);
    }

    public function testTrackingParametersAreStrippedFromTheIdentity(): void
    {
        // Alert links carry per-send tracking parameters. Keying on them would make the same flat
        // new on every digest — which is the "notifies forever" failure `ListingMapper` refuses a
        // synthetic id for.
        $ids = array_map(static fn ($l): string => $l->externalId, $this->emailSource()->fetch());

        foreach ($ids as $id) {
            self::assertStringNotContainsString('utm_', $id);
            self::assertStringNotContainsString('?', $id);
        }
    }

    public function testAnAnahAlertOnAPrivatePortalIsREJECTED(): void
    {
        // THE §1 BREACH A REVIEW FOUND, demonstrated closed through the real email path. ANAH
        // conventionné is signed by private individual landlords and advertised on exactly these
        // portals; before the Loc'Avantages label existed this classified LIBRE / 50 / MATCH.
        $source = $this->emailSource();
        $classifier = new \Scout\Rent\Core\TenureClassifier();
        $engine = new \Scout\Rent\Core\CriteriaEngine(ConfigLoader::loadCriteria(self::ROOT . '/config/rent/criteria.json'));

        $found = false;
        foreach ($source->fetch() as $listing) {
            if (!str_contains($listing->externalId, '67890')) {
                continue;
            }

            $found = true;
            $classification = $classifier->classify($listing, $source->profile());

            self::assertSame(Tenure::ANAH, $classification->tenure);
            self::assertSame(Outcome::REJECT, $engine->judge($listing, $classification, null)->outcome);
        }

        self::assertTrue($found, 'the ANAH fixture must be present — it is the regression this pins');
    }

    public function testAMessageFromSomewhereElseIsIgnored(): void
    {
        // A shared mailbox receives alerts from several portals. Attributing every message to
        // whichever source polls first would give each listing the wrong `mixed_tenure` flag —
        // which is the §1 switch.
        self::assertSame([], $this->emailSource(['from' => 'un-autre-portail.test'])->fetch());
    }

    public function testAnOutOfBandFigureIsNotReadAsARent(): void
    {
        // The plausibility band, with EACH half exercised independently. `rentIn()` evaluates only
        // the FIRST match per pattern, so one message carrying both figures tests only whichever
        // comes first — a review demonstrated the 200 floor deleted outright with every test still
        // green, because the ceiling alone rejected the fixture. Hence two messages: one whose only
        // currency-marked figure is an agency fee under the floor, one whose only figure is a sale
        // price over the ceiling. Neither is a rent, and each must be refused by ITS half of the
        // band. The shared fixture dir carries no such figure, which is why the original sabotage
        // stayed green.
        $dir = sys_get_temp_dir() . '/rentwatch-mailbox-' . bin2hex(random_bytes(6));
        mkdir($dir);

        file_put_contents($dir . '/floor.eml',
            "From: alertes@sale-portal.test\r\n"
            . "Subject: Frais de dossier\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "\r\n"
            . "Frais de dossier : 150 EUR pour constituer votre dossier.\r\n"
            . "https://sale-portal.test/annonce/11111\r\n");

        file_put_contents($dir . '/ceiling.eml',
            "From: alertes@sale-portal.test\r\n"
            . "Subject: Nouvelle annonce correspondant a votre recherche\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "\r\n"
            . "Maison a Chatou, ref. 95240.\r\n"
            . "Prix de vente : 245 000 EUR.\r\n"
            . "https://sale-portal.test/annonce/95240\r\n");

        try {
            $definition = new SourceDefinition(
                name: 'sale_portal',
                enabled: true,
                family: 'private',
                type: 'email_alert',
                mixedTenure: true,
                params: ['from' => 'sale-portal.test', 'link_host' => 'sale-portal.test'],
                map: new FieldMap(ref: ['url'], chargesIncluded: true),
            );

            $listings = (new EmailAlertSource($definition, $this->store(), new FileMailbox($dir)))->fetch();
            self::assertCount(2, $listings);

            foreach ($listings as $listing) {
                $half = str_contains($listing->externalId, '11111') ? '150 is under the 200 floor' : '245000 is over the 20000 ceiling';
                self::assertNull($listing->rentCc, $half);
                self::assertNull($listing->rentHc, $half);

                if (str_contains($listing->externalId, '95240')) {
                    self::assertSame('95240', $listing->postcode, 'the five-digit figure is a postcode, not a rent');
                }
            }
        } finally {
            @unlink($dir . '/floor.eml');
            @unlink($dir . '/ceiling.eml');
            @rmdir($dir);
        }
    }

    /**
     * A rent may not be assembled across a LINE BREAK, and a real alert is what proved it can be.
     *
     * French rents are written `1 450 €` with a narrow no-break space, so the digit class admits
     * whitespace as a thousands separator — and it did that with `\s`, which matches `\n`. A stray
     * figure on the line above therefore glues itself to the price: measured on the first real
     * SeLoger alert, `1 nouvelle annonce\n980 €/mois charges comprises` captured `"1\n980 "` and
     * parsed to **1**.
     *
     * **The glued value is TRUNCATED at the newline, not concatenated** — `Payload::int` stops
     * there — so the extracted rent is whatever sat on the line above. A first draft of this
     * docblock said `2\n980` reads as 2980; it reads as **2**. The mechanism matters because it
     * decides which failures are possible:
     *
     * - a short leading run yields a silly number the 200–20000 band rejects, so the rent is `null`
     *   — *unknown* under hard rule 9, and the listing is notified with `loyer non communiqué`
     *   while the alert stated it plainly [Verified: `réf 2` above `980 EUR` yields 2, out of band];
     * - a leading run of three or four digits is far worse, because it lands INSIDE the band and
     *   nothing reports it: `ref 850` above `1 450 EUR charges comprises` extracts **850**
     *   [Verified], a rent 600 € below reality that clears the ceiling and notifies a flat which
     *   does not.
     *
     * The separator is horizontal whitespace, `\h` — which under `/u` already covers U+00A0 and
     * U+202F, the two the explicit escapes were there for.
     *
     */
    public function testARentIsNotAssembledAcrossALineBreak(): void
    {
        $dir = sys_get_temp_dir() . '/rentwatch-mailbox-' . bin2hex(random_bytes(6));
        mkdir($dir);

        file_put_contents($dir . '/wrap.eml',
            "From: alertes@wrap-portal.test\r\n"
            . "Subject: 1 nouvelle annonce\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "\r\n"
            // The digit must be adjacent to the newline for the glue to happen — a line ending in
            // one, not merely containing one. In a real alert that is a tracking URL ending in a
            // digit, immediately above the price. A first draft of this test put the `1` at the
            // START of the line, and passed while the defect was fully present.
            . "Votre recherche : réf 2\r\n"
            . "980 EUR/mois charges comprises\r\n"
            . "https://wrap-portal.test/annonce/4242\r\n");

        try {
            $definition = new SourceDefinition(
                name: 'wrap_portal',
                enabled: true,
                family: 'private',
                type: 'email_alert',
                mixedTenure: true,
                params: ['from' => 'wrap-portal.test', 'link_host' => 'wrap-portal.test'],
                map: new FieldMap(ref: ['url'], chargesIncluded: true),
            );

            $listings = (new EmailAlertSource($definition, $this->store(), new FileMailbox($dir)))->fetch();

            self::assertCount(1, $listings);
            self::assertSame(980, $listings[0]->rentCc, 'the 1 on the line above is not part of the rent');
        } finally {
            @unlink($dir . '/wrap.eml');
            @rmdir($dir);
        }
    }

    /** The narrow no-break space French rents actually use still reads as a thousands separator. */
    public function testANarrowNoBreakSpaceIsStillAThousandsSeparator(): void
    {
        $dir = sys_get_temp_dir() . '/rentwatch-mailbox-' . bin2hex(random_bytes(6));
        mkdir($dir);

        file_put_contents($dir . '/nnbsp.eml',
            "From: alertes@wrap-portal.test\r\n"
            . "Subject: annonce\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "\r\n"
            . "Loyer : 1\u{202F}450\u{00A0}EUR charges comprises\r\n"
            . "https://wrap-portal.test/annonce/7\r\n");

        try {
            $definition = new SourceDefinition(
                name: 'wrap_portal',
                enabled: true,
                family: 'private',
                type: 'email_alert',
                mixedTenure: true,
                params: ['from' => 'wrap-portal.test', 'link_host' => 'wrap-portal.test'],
                map: new FieldMap(ref: ['url'], chargesIncluded: true),
            );

            $listings = (new EmailAlertSource($definition, $this->store(), new FileMailbox($dir)))->fetch();

            self::assertSame(1450, $listings[0]->rentCc);
        } finally {
            @unlink($dir . '/nnbsp.eml');
            @rmdir($dir);
        }
    }

    public function testAMissingMailboxDirectoryIsALoudFailure(): void
    {
        $this->expectException(MailboxError::class);
        $this->expectExceptionMessageMatches('~no such mailbox directory~');

        (new FileMailbox('/nonexistent/mailbox'))->fetchRecent();
    }

    public function testAMailboxFailureBecomesASourceErrorNotAnEmptyList(): void
    {
        $definition = new SourceDefinition(
            name: 'broken_mail',
            enabled: true,
            family: 'private',
            type: 'email_alert',
            mixedTenure: true,
            map: new FieldMap(ref: ['url']),
        );

        $this->expectException(SourceError::class);

        (new EmailAlertSource($definition, $this->store(), new FileMailbox('/nonexistent')))->fetch();
    }

    /**
     * "Recent" is asked of the SERVER, and it is asked per source.
     *
     * The tail of a folder is not the recent end of it. Measured on the live mailbox 2026-08-25:
     * 1436 messages, of which `SEARCH SINCE` matched 124 — at sequence numbers starting at **6** —
     * so reading the last 50 by sequence returned NONE of the day's SeLoger alerts. The source
     * reported `warn_drop`, 0 against a seven-day mean of 9, while the portal published normally.
     *
     * `FROM` is the half that keeps it fixed as sources are added: one mailbox serves every email
     * source, so a single window is a shared budget, and the window already held 124 messages
     * against a limit of 50. Without a per-source query a busy portal starves a quiet one silently.
     */
    public function testTheImapSearchIsScopedByDateAndBySender(): void
    {
        $now = new \DateTimeImmutable('2026-08-25 18:00:00');

        $command = ImapMailbox::searchCommand('alertes.seloger.com', 7, $now);

        self::assertSame('UID SEARCH SINCE 18-Aug-2026 FROM "alertes.seloger.com"', $command);

        // RFC 3501 wants `dd-Mon-yyyy` with ENGLISH month abbreviations. A locale-aware formatter
        // would emit `Aoû` here and the server would reject the command or, far worse, match
        // nothing at all — a quiet market that is really a broken query.
        self::assertStringContainsString('-Aug-', $command);

        // No `from` configured: the date window still applies. A source without a sender to scope
        // by is not a source that should read the whole folder.
        self::assertSame('UID SEARCH SINCE 18-Aug-2026', ImapMailbox::searchCommand(null, 7, $now));
    }

    /**
     * A window of zero days would match nothing and read as a quiet market for ever.
     *
     * Same asymmetry as `RENT_HEARTBEAT_HOURS`: the safe direction is one day too many.
     */
    public function testANonPositiveImapWindowIsClampedRatherThanObeyed(): void
    {
        $now = new \DateTimeImmutable('2026-08-25 18:00:00');

        self::assertSame('UID SEARCH SINCE 24-Aug-2026', ImapMailbox::searchCommand(null, 0, $now));
        self::assertSame('UID SEARCH SINCE 24-Aug-2026', ImapMailbox::searchCommand(null, -30, $now));
    }

    /** The sender reaches the command line, so the CRLF refusal has to cover it. */
    public function testAnImapFromFilterCarryingCrlfIsRefused(): void
    {
        $this->expectException(MailboxError::class);

        ImapMailbox::searchCommand("alertes.seloger.com\r\nA002 DELETE", 7, new \DateTimeImmutable());
    }

    /**
     * An untagged `* SEARCH` reply may be split across lines, and may carry no numbers at all.
     *
     * Highest first, because the caller's contract is newest first and within a window already
     * narrowed by date and sender that is the best ordering available without fetching every match
     * to read its `Date` — which is the cost the query exists to avoid.
     */
    public function testTheSearchResponseIsReadAcrossLinesAndCapped(): void
    {
        $lines = ['* SEARCH 6 7 8', '* SEARCH 52 53 8', '* OK unrelated', 'A003 OK SEARCH completed'];

        self::assertSame([53, 52, 8], ImapMailbox::sequencesIn($lines, 3));
        self::assertSame([53, 52, 8, 7, 6], ImapMailbox::sequencesIn($lines, 50), 'deduplicated, highest first');

        // No match is a legitimate answer — nothing arrived in the window. It is distinguishable
        // from a failure because a failing SEARCH throws before this is ever reached.
        self::assertSame([], ImapMailbox::sequencesIn(['* SEARCH', 'A003 OK'], 50));
        self::assertSame([], ImapMailbox::sequencesIn(['A003 OK SEARCH completed'], 50));
    }

    /**
     * The cap is counted AGAINST the window, not silently applied to it.
     *
     * `SEARCH SINCE` decides what is recent and the cap decides how much of that is read, so the
     * two disagree the moment a portal gets busy — and the disagreement is invisible. Measured on
     * the live mailbox 2026-08-26: SeLoger matched **104** messages in the seven-day window against
     * a cap of 50, so `IMAP_SINCE_DAYS=7` was really buying about 3.4 days of catch-up for that
     * source. Nothing was being missed day to day (highest sequences are the newest inside a
     * date-filtered window), which is exactly why nothing said so.
     */
    public function testAWindowLargerThanTheCapSaysSo(): void
    {
        $notice = ImapMailbox::truncationNotice(104, 50, 'alertes.seloger.com');

        self::assertIsString($notice);
        self::assertStringContainsString('104', $notice);
        self::assertStringContainsString('50', $notice);
        // The knob is NAMED, because a warning that does not say what to turn is a complaint.
        self::assertStringContainsString('IMAP_MAX_MESSAGES', $notice);
        // WHOSE window it is. One mailbox serves every portal and each has its own `FROM`, so a
        // notice that did not name the sender would send the reader to the wrong source.
        self::assertStringContainsString('alertes.seloger.com', $notice);
    }

    /**
     * Silent when nothing is dropped, INCLUDING at exactly the cap.
     *
     * The boundary is the whole point: 50 matches read under a cap of 50 lose nothing, and a notice
     * there would fire on every busy-but-fine source until it was ignored — which is how a real one
     * stops being read.
     */
    public function testAWindowThatFitsIsSilent(): void
    {
        self::assertNull(ImapMailbox::truncationNotice(9, 50, 'alertes.seloger.com'));
        self::assertNull(ImapMailbox::truncationNotice(50, 50, 'alertes.seloger.com'));
        self::assertIsString(ImapMailbox::truncationNotice(51, 50, 'alertes.seloger.com'));
    }

    /**
     * The count the notice reports is the count BEFORE the cap — the only one that carries news.
     */
    public function testEverySequenceIsCountedBeforeTheCapIsApplied(): void
    {
        $lines = ['* SEARCH 6 7 8', '* SEARCH 52 53 8', 'A003 OK SEARCH completed'];

        self::assertSame([53, 52, 8, 7, 6], ImapMailbox::allSequencesIn($lines), 'deduplicated, uncapped');
        self::assertSame([53, 52], ImapMailbox::sequencesIn($lines, 2), 'the cap still caps');
    }

    /**
     * `IMAP_MAX_MESSAGES` reads like its twin `IMAP_SINCE_DAYS`, deliberately.
     *
     * The two knobs bound the same query — one by date, one by count — so a reader who learns that
     * a nonsense `IMAP_SINCE_DAYS` clamps to one day would be entitled to assume the same here.
     * Making one clamp and the other refuse is a trap for no gain. Zero is the case that matters: it
     * would read NO messages, which is a source reporting a quiet market for ever.
     */
    public function testTheMessageCapIsClampedNeverZero(): void
    {
        self::assertSame(120, ImapMailbox::maxMessages('120'));
        self::assertSame(ImapMailbox::DEFAULT_MAX_MESSAGES, ImapMailbox::maxMessages(null));
        self::assertSame(ImapMailbox::DEFAULT_MAX_MESSAGES, ImapMailbox::maxMessages(''));

        // Not a number at all — a typo must not silently mean "read nothing".
        self::assertSame(ImapMailbox::DEFAULT_MAX_MESSAGES, ImapMailbox::maxMessages('beaucoup'));

        self::assertSame(1, ImapMailbox::maxMessages('0'), 'zero would be a permanently quiet source');
        self::assertSame(1, ImapMailbox::maxMessages('-5'));
    }

    /**
     * The notice is EMITTED, not merely computable — and the slice is the capped one.
     *
     * Verified against the live mailbox on 2026-08-26 (108 SeLoger messages, 50 read), but a live
     * mailbox is not a test: the day that portal goes quiet the branch stops being entered and this
     * becomes safety code nobody would notice deleting. That has happened twice in this repo, most
     * recently to the SeLoger title fallback hours before this was written. So the branch is entered
     * here on purpose, through the private method it was extracted into for the purpose.
     */
    public function testTheTruncationNoticeIsActuallyEmitted(): void
    {
        $said = [];

        $mailbox = new ImapMailbox(
            host: 'imap.invalid',
            user: 'u',
            password: 'p',
            fromFilter: 'alertes.seloger.com',
            warn: function (string $message) use (&$said): void { $said[] = $message; },
        );

        $cap = new \ReflectionMethod(ImapMailbox::class, 'capWithNotice');

        // 6 matched, 4 read.
        $kept = $cap->invoke($mailbox, [60, 59, 58, 57, 56, 55], 4);

        self::assertSame([60, 59, 58, 57], $kept, 'the newest, capped');
        self::assertCount(1, $said);
        self::assertStringContainsString('alertes.seloger.com', $said[0]);
        self::assertStringContainsString('IMAP_MAX_MESSAGES', $said[0]);

        // ...and silent when it fits, which is what stops it becoming background noise.
        $said = [];
        self::assertSame([60, 59], $cap->invoke($mailbox, [60, 59], 4));
        self::assertSame([], $said, 'a window that fits says nothing');
    }

    /**
     * A mailbox given no `warn` must not crash when the cap bites.
     *
     * `FileMailbox` and every test that constructs an `ImapMailbox` directly pass none, so the null
     * branch is the common one — and a diagnostic that can take down a fetch is worse than the
     * silence it was added to fix.
     */
    public function testACappedFetchWithNoWarnChannelIsHarmless(): void
    {
        $mailbox = new ImapMailbox(host: 'imap.invalid', user: 'u', password: 'p');
        $cap = new \ReflectionMethod(ImapMailbox::class, 'capWithNotice');

        self::assertSame([9, 8], $cap->invoke($mailbox, [9, 8, 7], 2));
    }

    public function testTheImapMailboxIsBehindTheOfflineTripwire(): void
    {
        // THE EGRESS POINT THAT MATTERS MOST, and it was not covered. `Core\Offline` was introduced
        // claiming to refuse "every outbound request" while guarding `CurlHttpClient` and
        // `NtfyChannel` only; this opens a RAW SOCKET, so it never passed that funnel. Hard rule 4
        // makes email-alert ingestion the PRIMARY path for private portals, and this sends a
        // cleartext password to a host read from `.env`. A review panel watched it dial with the
        // flag set, on 2026-08-24.
        //
        // TEST-NET-1 (RFC 5737) is deliberate: nothing there is routable, so a failure that is NOT
        // the tripwire's sentence would prove the guard never ran — a connection timeout is exactly
        // what the panel saw before the fix.
        self::assertSame('1', getenv('SCOUT_OFFLINE'), 'the bootstrap must set this for every test');

        $mailbox = new ImapMailbox(
            host: '192.0.2.1',
            user: 'someone@example.test',
            password: 'not-a-real-password',
        );

        try {
            $mailbox->fetchRecent(10);
            self::fail('IMAP reached the network from a test');
        } catch (MailboxError $e) {
            self::assertStringContainsString('SCOUT_OFFLINE', $e->getMessage());
            self::assertStringNotContainsString('not-a-real-password', $e->getMessage());
        }
    }

    public function testAnImapArgumentWithAnEmbeddedCrlfIsRefused(): void
    {
        // An IMAP quoted-string cannot contain CR or LF (RFC 3501), so one in a `.env` user,
        // password or folder would break the command line and could inject a second command. These
        // are operator-controlled, not attacker input — defense-in-depth — but the grammar forbids
        // it regardless. `quote()` is private and the only reachable path (`fetchRecent`) needs a
        // live TLS server, so this pins the property directly by reflection; the sabotage case that
        // disables the guard turns this red.
        $quote = new \ReflectionMethod(ImapMailbox::class, 'quote');

        $this->expectException(MailboxError::class);
        $this->expectExceptionMessageMatches('~CR or LF~');

        $quote->invoke(null, "inbox\r\nA002 DELETE important");
    }

    // ---------------------------------------------------------------- transports

    public function testTheFileTransportWritesAReadableMessage(): void
    {
        $dir = sys_get_temp_dir() . '/rentwatch-outbox-' . bin2hex(random_bytes(6));
        $transport = new FileTransport($dir);

        self::assertNull($transport->check());
        $transport->send('moi@example.test', 'Sujet', 'Le corps', ['From' => 'rent-watch@localhost']);

        $files = glob($dir . '/*.eml') ?: [];
        self::assertCount(1, $files);
        self::assertStringContainsString('Le corps', (string) file_get_contents($files[0]));

        foreach ($files as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }

    public function testSmtpRefusesPlaintextCredentialsToARemoteHost(): void
    {
        // Not a warning. A credential on a plaintext connection to a remote host is a credential on
        // the wire, and "the user configured it" is not a reason to help.
        $problem = (new SmtpTransport('smtp.example.test', 25, 'user', 'pw', security: 'none'))->check();

        self::assertNotNull($problem);
        self::assertStringContainsString('loopback', (string) $problem);
    }

    public function testSmtpPermitsPlaintextToLoopbackForALocalTestServer(): void
    {
        self::assertNull((new SmtpTransport('localhost', 1025, security: 'none'))->check());
    }

    /**
     * The reviewer's exact scenario, round 7.
     *
     * `SmtpTransport` carried a PRIVATE `isLoopback()` that had drifted from `Offline`'s: it also
     * admitted `mailhog` and `mailpit`, two strings appearing nowhere else in the repo — not
     * `.env.example`, not `compose.yaml`, not a doc. So `SMTP_HOST=mailhog SMTP_SECURITY=none` put
     * `AUTH LOGIN` and `SMTP_PASSWORD` on a compose network in the clear, straight past the guard
     * whose entire subject is that. `Offline`'s own docblock says a second copy "would be one edit
     * away from disagreeing with the first about what loopback means, and the disagreement would be
     * invisible until it mattered" — while that second copy sat in the tree, already disagreeing.
     */
    public function testSmtpRefusesPlaintextCredentialsToACatcherHostnameThatIsNotLoopback(): void
    {
        foreach (['mailhog', 'mailpit'] as $host) {
            $problem = (new SmtpTransport($host, 1025, 'user', 'pw', security: 'none'))->check();

            self::assertNotNull($problem, $host . ' is not a loopback address in any network sense');
            self::assertStringContainsString('loopback', (string) $problem);
        }
    }

    /**
     * And the counterweight, which is why the fix is not simply deleting those two names.
     *
     * A local mail catcher needs no AUTH. With no `SMTP_USER` there is no credential to expose, so
     * what the guard was really protecting is the PAIR — plaintext AND a credential — not the host
     * on its own. Refusing here would break the one setup those two names were added for, and
     * loudly, for no gain.
     */
    public function testSmtpPermitsPlaintextToANonLoopbackHostWhenNoCredentialIsSent(): void
    {
        self::assertNull((new SmtpTransport('mailpit', 1025, security: 'none'))->check());
    }

    /**
     * The narrowing must not be SILENT.
     *
     * Round 7 changed the `SMTP_SECURITY=none` guard from "refuse for any non-loopback host" to
     * "refuse only when a credential would go with it" — right about credentials, and it replaced
     * a refusal with nothing at all: no warning, no `disabledReport()` entry, no `doctor` line.
     * The message body — every notified listing plus the recipient — crosses the internet in the
     * clear, and the only surface that could have said so was `describe()`, which nothing called.
     */
    public function testAnUnencryptedRemoteConnectionSaysSoEvenThoughItIsPermitted(): void
    {
        $permitted = new SmtpTransport('smtp.example.test', 25, security: 'none');

        self::assertNull($permitted->check(), 'no credential, so it is not refused');
        self::assertStringContainsString(
            'EN CLAIR',
            $permitted->describe(),
            'but `doctor` must say the mail is leaving unencrypted',
        );

        self::assertStringNotContainsString(
            'EN CLAIR',
            (new SmtpTransport('localhost', 1025, security: 'none'))->describe(),
            'a loopback test server is not a warning — a notice that always fires is furniture',
        );
    }

    public function testSmtpRefusesAUserWithNoPassword(): void
    {
        $problem = (new SmtpTransport('smtp.example.test', 587, 'user', ''))->check();

        self::assertNotNull($problem);
        self::assertStringContainsString('SMTP_PASSWORD', (string) $problem);
    }

    public function testEveryMailTransportSelfProtectsAgainstACrlfHeader(): void
    {
        // The class is closed at EVERY builder, not just SMTP: Sendmail (the default, whose sink is
        // the injection-prone mail()) and File each apply the shared guard at their own boundary,
        // so none depends on the EmailChannel caller to have sanitised.
        $dir = sys_get_temp_dir() . '/rentwatch-transport-crlf-' . bin2hex(random_bytes(6));

        $transports = [
            'sendmail' => new SendmailTransport(),
            'file' => new FileTransport($dir),
        ];

        foreach ($transports as $label => $transport) {
            try {
                $transport->send('moi@example.test', "Sujet\r\nBcc: attacker@evil.test", 'corps', []);
                self::fail($label . ' must refuse a CR/LF in the subject');
            } catch (ChannelError $e) {
                self::assertStringContainsString('inject a header or command', $e->getMessage());
            }

            try {
                $transport->send('moi@example.test', 'Sujet', 'corps', ['X-Custom' => "ok\r\nBcc: attacker@evil.test"]);
                self::fail($label . ' must refuse a CR/LF in a header value');
            } catch (ChannelError $e) {
                self::assertStringContainsString('inject a header or command', $e->getMessage());
            }
        }

        // The File transport must have written nothing — the refusal precedes the write.
        self::assertSame([], glob($dir . '/*.eml') ?: []);
        @rmdir($dir);
    }

    public function testSmtpRefusesACrlfInTheEnvelopeOrAHeaderBeforeConnecting(): void
    {
        // In-transport CR/LF refusal, symmetric with ImapMailbox::quote(): a CR/LF in the
        // recipient, sender, subject or a header value would inject a second SMTP command or header
        // (an extra RCPT, a Bcc). The guard fires before any socket, so no server is needed. These
        // are caller/operator-controlled — defense-in-depth — but the grammar forbids it regardless.
        $transport = new SmtpTransport('127.0.0.1', 1025, security: 'none');

        foreach ([
            ['victim@x.test' . "\r\n" . 'RCPT TO:<other@evil.test>', 'Sujet', []],
            ['moi@example.test', "Sujet\r\nBcc: attacker@evil.test", []],
            ['moi@example.test', 'Sujet', ['X-Custom' => "ok\r\nBcc: attacker@evil.test"]],
            // A CRLF in the header NAME: refused, AND the raw name must not be echoed back into the
            // error (the error message must itself be newline-free).
            ['moi@example.test', 'Sujet', ["X\r\nBcc" => 'v']],
        ] as [$to, $subject, $headers]) {
            try {
                $transport->send($to, $subject, 'corps', $headers);
                self::fail('a CR/LF in an SMTP field must be refused before connecting');
            } catch (ChannelError $e) {
                self::assertStringContainsString('inject a header or command', $e->getMessage());
                self::assertDoesNotMatchRegularExpression('~[\r\n]~', $e->getMessage(), 'the error must not echo the offending value');
            }
        }
    }

    public function testAnSmtpErrorMasksThePasswordInBothItsForms(): void
    {
        // The base64 form is what actually goes on the wire and what a server echoes back in a
        // rejection, so masking only the plaintext would leave the transmitted credential exposed.
        $password = 'hunter2-app-password';

        $e = new ChannelError('email', 'SMTP rejected: AUTH LOGIN ' . base64_encode($password), null, [
            $password,
            base64_encode($password),
        ]);

        self::assertStringNotContainsString($password, $e->getMessage());
        self::assertStringNotContainsString(base64_encode($password), $e->getMessage());
    }
}

/** An HTTP client the test drives. The seam that makes a network adapter testable offline. */
final class FakeHttpClient implements HttpClient
{
    public int $calls = 0;

    public ?HttpRequest $lastRequest = null;

    public function __construct(
        private readonly ?HttpResponse $response,
        private readonly ?HttpError $error = null,
    ) {}

    public function send(HttpRequest $request): HttpResponse
    {
        ++$this->calls;
        $this->lastRequest = $request;

        if ($this->error !== null) {
            throw $this->error;
        }

        return $this->response ?? new HttpResponse(200, '');
    }
}
