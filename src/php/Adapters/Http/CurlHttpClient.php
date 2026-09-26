<?php

declare(strict_types=1);

namespace Scout\Adapters\Http;

use Scout\Core\Offline;

/**
 * The real transport. cURL, because it is present here and reports failures distinguishably.
 *
 * `CLAUDE.md` hard rule 5 is enforced HERE rather than left to each adapter, so no adapter can
 * accidentally be the polite exception:
 *
 * - **Identify honestly.** One User-Agent, naming the tool and its purpose. No browser
 *   impersonation, no rotation — those are the fingerprint-spoofing the rule forbids outright.
 * - **No cookie jar, no proxy support.** Each of those is a step toward the anti-bot arms race this
 *   project refuses to enter. When a site blocks us, the answer is the email-alert route, never a
 *   better disguise.
 * - **A redirect is never followed here.** A 3xx comes back to the caller like any other status: a
 *   hop taken inside libcurl would pass below the offline switch, the header guards and every
 *   `robots.txt` check a source makes per URL. The one hop this project takes is the caller's
 *   (`SitemapVehicleSource::sameLotTarget()`), same host, same lot, robots-checked and paced.
 *   `sendFollowing()`, which followed up to three same-host hops with no robots check, had no caller
 *   and no test, and was deleted 2026-09-26.
 */
final readonly class CurlHttpClient implements HttpClient
{
    public const string USER_AGENT = 'scout/1.0 (+self-hosted personal listing watcher; contact via repository)';

    /** 8 MB. A listing page that large is a misconfiguration, and reading it unbounded is a DoS on ourselves. */
    private const int MAX_BODY_BYTES = 8 * 1024 * 1024;

    /**
     * RFC 7230 token — the only characters legal in a header NAME.
     *
     * Load-bearing for the User-Agent guard below: libcurl derives the header name from the text
     * before the first colon in the `CURLOPT_HTTPHEADER` entry, so a NAME of `user-agent: Mozilla`
     * would clear an equality check and still put a browser User-Agent on the wire — a review
     * demonstrated exactly that. A colon, space or control character can never appear in a valid
     * token, so refusing non-tokens closes the smuggling shape wholesale rather than one spelling
     * at a time. `ConfigLoader` applies the same rule at load time.
     *
     * The `D` modifier is not optional: without it PHP's `$` also matches just BEFORE a single
     * trailing newline, so `"user-agent\n"` would pass the class AND dodge the equality check
     * below (it no longer string-equals `user-agent`) — putting a raw LF into the request headers.
     * `\z`-equivalent anchoring is what makes "no control character in a name" actually true.
     */
    private const string HEADER_NAME_TOKEN = '/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/D';

    public function send(HttpRequest $request): HttpResponse
    {
        if (!extension_loaded('curl')) {
            throw new HttpError('ext-curl is not loaded, so no network source can run');
        }

        $handle = curl_init($request->url);
        if ($handle === false) {
            throw new HttpError('could not initialise the HTTP client for ' . $request->url);
        }

        $headers = [];
        foreach ($request->headers as $name => $value) {
            $name = (string) $name;

            if (preg_match(self::HEADER_NAME_TOKEN, $name) !== 1) {
                throw new HttpError(
                    'header name ' . var_export($name, true) . ' is not a valid HTTP token — a '
                        . 'colon, space or control character in a name smuggles a different header '
                        . 'onto the wire than the one the guards below inspected',
                );
            }

            if (preg_match('~[\r\n]~', (string) $value) === 1) {
                throw new HttpError(
                    'header ' . $name . ' carries a line break in its value — refusing HTTP '
                        . 'header injection',
                );
            }

            if (strtolower($name) === 'user-agent') {
                // The docblock's promise, made real: in cURL, a User-Agent entry in
                // CURLOPT_HTTPHEADER silently overrides CURLOPT_USERAGENT, so without this check
                // any caller could disguise the poller while the honest constant sat unread.
                // `ConfigLoader` refuses the same key at load time; this is the funnel, and it
                // refuses too, because config is not the only path a header can arrive by.
                throw new HttpError(
                    'a User-Agent header cannot be overridden — hard rule 5: one honest '
                        . 'User-Agent, no browser impersonation. Remove the header; if the source '
                        . 'blocks plain clients, use the email-alert route',
                );
            }

            $headers[] = $name . ': ' . $value;
        }

        // ── the offline tripwire ─────────────────────────────────────────────────────────────
        //
        // `SCOUT_OFFLINE=1` turns every outbound request into a loud refusal, and
        // `tests/bootstrap.php` sets it for the whole suite. Spec §11 says parser tests run
        // offline; until 2026-08-19 that held only BY ACCIDENT, because every source in
        // `config/rent/sources.json` was disabled and the tests that run the real CLI against the real
        // config therefore had nothing to poll. Enabling In'li turned the suite into a four-page
        // crawler of a live landlord's site, once per test, inside a single run.
        //
        // An accidental guarantee is not one. This makes it structural: an accidental real client
        // now fails in microseconds naming the cause, rather than hanging — and, the part that
        // matters under hard rule 5, rather than hammering somebody else's server from CI.
        //
        // **Placed AFTER the header guards on purpose.** Above them it short-circuited the
        // User-Agent, token and CRLF refusals, so four tests that prove hard rule 5 is enforced at
        // this funnel started passing for the wrong reason — they saw the offline message instead
        // of the refusal they were written to pin. A safety check that its own test can no longer
        // reach is indistinguishable from one that was deleted. Validation first, transport second.
        // Loopback is exempt, and that is not a loophole. What the switch forbids is contacting
        // SOMEBODY ELSE'S server — third-party load, third-party rate limits, a test suite whose
        // result depends on a landlord's deploy schedule. A scripted server on 127.0.0.1 is how
        // this project proves the things only a real socket can prove: that the honest User-Agent
        // is what actually crosses the wire, and that SMTP refuses a credential without STARTTLS.
        // Banning those would delete real evidence to enforce a rule they do not break.
        //
        // The PREDICATE moved to `Scout\Core\Offline` on 2026-08-24; the CALL SITE stays exactly
        // here, for the reason the paragraph above gives. It moved because this was not the only
        // path out: `NtfyChannel` calls libcurl directly and never passed this funnel, so the
        // guarantee `tests/bootstrap.php` describes was smaller than it read.
        $refusal = Offline::refusal($request->url);
        if ($refusal !== null) {
            throw new HttpError($refusal);
        }

        $responseHeaders = [];
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $request->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $request->timeoutSeconds,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_HTTPHEADER => $headers,
            // Never followed: a 3xx is the caller's to judge (see the class docblock).
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                $length = strlen($line);
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return $length;
            },
        ];

        if ($request->method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $request->body ?? '';
        }

        curl_setopt_array($handle, $options);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        // No curl_close(): deprecated in PHP 8.5, a no-op since 8.0 — the handle is an object,
        // freed when it goes out of scope. The wire test is what first executed this path and
        // surfaced the deprecation; nothing before it drove real libcurl.

        if ($body === false || $error !== '') {
            // A TRANSPORT failure. Distinct from a non-2xx answer on purpose: a 403 is a source
            // telling us it blocks plain clients, and a timeout is the network. Only one of those
            // means the source needs a different route.
            throw new HttpError('request to ' . $request->url . ' failed: ' . $error);
        }

        $body = (string) $body;
        if (strlen($body) > self::MAX_BODY_BYTES) {
            throw new HttpError(sprintf(
                'response from %s exceeded %d bytes — refusing to parse it',
                $request->url,
                self::MAX_BODY_BYTES,
            ));
        }

        return new HttpResponse($status, $body, $responseHeaders);
    }
}
