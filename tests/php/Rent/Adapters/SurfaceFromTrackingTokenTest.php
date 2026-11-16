<?php

declare(strict_types=1);

namespace Scout\Tests\Rent\Adapters;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Scout\Adapters\Mail\FileMailbox;
use Scout\Rent\Adapters\EmailAlertSource;
use Scout\Rent\Config\ConfigLoader;
use Scout\Rent\Config\SourceDefinition;
use Scout\Rent\Core\RawListing;
use Scout\Rent\Store\Store;

/**
 * TRACK 6-A6 — the generic surface reader matched `m2` inside a base64url TRACKING TOKEN.
 *
 * SEVENTH instance of *URLs are classified text*, and the second time the same first-match-wins
 * scan has been poisoned by a URL: Track 1j fixed the rooms branch against hexadecimal photo UUIDs
 * with a left anchor, and base64url walks straight past that anchor because `-` and `_` are not
 * `[A-Za-z0-9]`.
 *
 * FOUND BY THE PLAN'S OWN A6 QUERY, on the first day it was answerable, and the plan predicted the
 * WRONG CAUSE: it expected the surface reader to need the positional repair the rooms reader got.
 * It does not. The real row, `first_seen_at 2026-09-02T05:24:51Z`, stored **7 m²** for a flat whose
 * own card says `3 pièces . 64,25 m²`, because SeLoger wraps every link as
 * `click.by.seloger.com/?qs=<opaque per-recipient token>` and one token reads `…zaw7m29jtx…`.
 * Offset 1029 beat offset 1948.
 *
 * ## MEASURED OVER THE WHOLE STORE BEFORE SHIPPING — 2 043 stored card bodies, all seven sources
 *
 *     surface   26 changed   all seloger, every one a recovery (7 → 64,25; 30 → 80,13; NULL → 65)
 *     rooms      4 changed   all seloger, the base64url twin of the Track 1j UUID defect
 *     rent       0 changed   postcode 0   commune 0
 *     inli 497 · cdc_habitat 469 · cityloger 60 · bienici 316 · leboncoin 3 · pap 62 — 0 each
 *
 * Of the 26, **seven are matches that were never notified** — they clear every numeric filter and
 * every shipped exclusion at their true size — and six more were pushed with no surface at all, so
 * the notification could not state it and the surface score component could not fire. Four are room
 * rentals the exclusions catch regardless; nine fail the ceiling or the floor at the true figure
 * too. A first draft of this paragraph said eight and five, counted with a `===` that reads stored
 * int 61 and read float 61.0 as different surfaces; Track 6-A7's report is the measured split.
 *
 * ## THE FIX IS A RULE THIS REPO HAS ALREADY RULED, NOT A NEW ONE
 *
 * `RawListing::text()` strips a URL's QUERY and FRAGMENT and KEEPS ITS PATH before the tenure
 * classifier reads it, because `?c=plai_plus` is a campaign string nobody can rewrite while a
 * `plai` PATH SEGMENT is a real social signal. The same split applies here for the same reason,
 * and this is where it was missing: the extraction readers scanned the raw body.
 *
 * **SCOPE: the GENERIC readers only.** A configured pattern owns its answer, is positional, and is
 * measured against a real payload when it is written — pap's `surface_pattern` and `rooms_pattern`
 * are unchanged bit-for-bit, which their fixtures assert. What is repaired is the first-match-wins
 * fallback that has now produced this defect three times (the PAP criteria line, the rooms UUID,
 * this).
 *
 * **AND THE LINK IS NOT TOUCHED.** For seloger the whole URL *is* the query, so stripping it at the
 * link reader would empty every notification's link and — on a portal keyed on its links — re-key
 * the entire stored backlog. That counterweight is asserted below.
 */
#[CoversClass(EmailAlertSource::class)]
final class SurfaceFromTrackingTokenTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../../..';

    /** The real token from the live 2026-09-02 Orly card, plus the shapes around it. */
    private const LIVE_TOKEN = 'ABB7InYiOjEsImQiOjQ5ODd9ADMAAAAAAM2qSkOJ0Tfpa9EiGIpZzaw7m29jtxPwkxTdmsr64r6TerOJySMEk0GT9f';

    /**
     * @return iterable<string, array{0: string, 1: float}>
     */
    public static function poisonedTokens(): iterable
    {
        yield 'the live Orly token' => [self::LIVE_TOKEN, 64.25];
        // The two other shapes measured in the store, reduced to their offending run.
        yield 'digit before m2, lower' => ['gLsYZg1mt2HjrrNYF0BIvb8qk5m24wYLcDsV58', 64.25];
        yield 'two digits before M2' => ['Qs30m2ZmVyZW5jZQAAAAAAAAAAAAAAAAAAAAAA', 64.25];
    }

    #[DataProvider('poisonedTokens')]
    public function testASurfaceIsNeverReadOutOfATrackingToken(string $token, float $expected): void
    {
        // The token sits ABOVE the prose, exactly as it does in a live card: first-match-wins is
        // half the defect, so a fixture with the URL below would pass without a fix.
        $listing = $this->read(
            "https://click.by.seloger.com/?qs={$token}\n"
            . "3 pièces . 64,25 m²\n"
        );

        self::assertSame($expected, $listing->surfaceM2);
    }

    /**
     * THE BASE64URL TWIN OF TRACK 1j. `(?<![A-Za-z0-9])` was the whole fix there and it cannot see
     * this: base64url's own alphabet includes `-` and `_`, which are exactly the non-alphanumeric
     * left boundary the anchor accepts — and `-` after the digit satisfies the `\b` on the right.
     *
     * These four runs are TAKEN FROM THE STORE, not invented. A first draft of this case used
     * `_T9wZXJf`, which passed before the fix as well as after (the `w` denies the trailing `\b`)
     * — a test proving nothing, which is exactly the trap the SeLoger title fix walked into.
     *
     * @return iterable<string, array{0: string, 1: int}>
     */
    public static function poisonedRoomTokens(): iterable
    {
        yield 'underscore then F5' => ['e1HKwtvPq7aA31G5b8QF2_F5-6VQ_houIpEuRrZsWu6b', 5];
        yield 'underscore then t9' => ['MAAAAAAMf_UJdzLmKOWdT_t9-yXcEpyQa9PPGCGwJKzw', 9];
        yield 'hyphen then T9' => ['8b72kzqH9qqq-7VRsUxrI-T9-kqhduELPVP3ZT49qqgA', 9];
        yield 'the other live t9' => ['4stFT6U6Hu3udxdaTA01F_t9-q7sA0b0z9nph7-8Tn_g', 9];
    }

    #[DataProvider('poisonedRoomTokens')]
    public function testARoomCountIsNeverReadOutOfATrackingToken(string $token, int $wrong): void
    {
        $listing = $this->read(
            "https://click.by.seloger.com/?qs={$token}\n"
            . "3 pièces . 64,25 m²\n"
        );

        self::assertNotSame($wrong, $listing->rooms, "the run in {$token} must not be read as a room count");
        self::assertSame(3, $listing->rooms, 'the card states 3 pièces and that is the only room count in it');
    }

    /**
     * THE COUNTERWEIGHT THAT MATTERS MOST: the link keeps its query.
     *
     * SeLoger's link is nothing BUT a query, so a strip applied one layer too wide empties the URL
     * a human clicks — and on bienici and leboncoin, whose identity IS the link, it would re-key the
     * whole stored backlog and re-notify every flat already seen.
     */
    public function testTheListingsOwnLinkKeepsItsQuery(): void
    {
        $listing = $this->read(
            "3 pièces . 64,25 m²\n",
            linkHost: 'click.by.seloger.com',
            extra: "https://click.by.seloger.com/?qs=" . self::LIVE_TOKEN . "\n",
        );

        self::assertStringContainsString('?qs=' . self::LIVE_TOKEN, (string) $listing->url);
    }

    /**
     * And the ordinary reading is unchanged — without this the fix is satisfied by returning null.
     *
     * @return iterable<string, array{0: string, 1: float}>
     */
    public static function ordinaryProse(): iterable
    {
        yield 'the SeLoger card line' => ["3 pièces . 64,25 m²\n", 64.25];
        yield 'ascii m2' => ["Appartement 3 pièces 65.00 m2\n", 65.0];
        yield 'a space before the unit' => ["3 pièces de 58,84 m²\n", 58.84];
        yield 'a whole number' => ["T3 - 3 pièce(s) - 64 m²\n", 64.0];
    }

    #[DataProvider('ordinaryProse')]
    public function testAnOrdinarySurfaceStillReads(string $body, float $expected): void
    {
        self::assertSame($expected, $this->read($body)->surfaceM2);
    }

    /**
     * A URL's PATH is kept, deliberately and for the same reason `RawListing::text()` keeps it: the
     * ruled split is query-and-fragment, not the whole URL. Stated as a test so the scope cannot
     * quietly widen into "blank every URL", which would lose the `plai` path segment §1 relies on.
     */
    public function testTheUrlPathSurvivesTheStrip(): void
    {
        // The ONLY surface in this body is in the path. Widen the strip to blank whole URLs and
        // this returns null — which is how the scope guard fails.
        $listing = $this->read("https://www.example.test/annonce/64m2-orly?qs=zaw7m29jtx\n");

        self::assertSame(64.0, $listing->surfaceM2, 'the path is prose, the query is not');
    }

    /**
     * COR-F4 — the query strip closed one alphabet; the PATH is kept and had no left anchor at all.
     *
     * `9ea9d77` removed a URL's query and fragment before the generic readers run, and — correctly,
     * per the ruled split this file's own counterweight asserts — KEPT the path. `ROOMS_PATTERN`
     * additionally carries `(?<![A-Za-z0-9])` from Track 1j. `SURFACE_PATTERN` carried neither, and
     * `preg_match` is first-match-wins, so a run inside a path segment still won over the real
     * figure below it.
     *
     * **THE LENS GRADED THIS THE SOFTEST FINDING IN ITS REPORT — *no real payload is shown to carry
     * `m2` in a path* — AND THE STORE SAYS OTHERWISE.** Trialled through the real `prose()` over all
     * 2 579 stored evidence bodies: **4 rows change, every one a recovery**, and the poison is a
     * CloudFront distribution id in Bien'ici's own photo host, `d2m2j20yzublln.cloudfront.net`. It
     * reads `2 m²`. Four flats of 41, 54, 65 and 59 m² are stored at **2 m²**, so three of them were
     * silently rejected by `min_surface_m2: 50` — silent over-rejection, the one failure mode
     * nothing can see, because nothing arrives. Not a class-completeness fix: a live one.
     *
     * The distribution id is on every photo URL from that CDN, so this is structural rather than a
     * run of bad luck in four cards.
     *
     * **The measurement was almost recorded nine times too large.** Applied to the RAW stored body
     * it changes 41 rows — but 37 of those are SeLoger tokens already closed by `9ea9d77`'s query
     * strip, which runs before any generic reader. Measuring a repair against the pre-repair
     * baseline is this repo's own *true number attached to an invented cause*; the honest figure is
     * the 4 above.
     *
     * Closed under this repo's standard — *a fix measured against one alphabet is not a fix against
     * the class* — the standard that made `9ea9d77` necessary after `46262ee`'s anchor proved blind
     * to base64url. Third time this first-match-wins scan has been poisoned by text nobody wrote
     * for a reader.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function poisonedPathSegments(): iterable
    {
        // CAPTURED, not invented — the live Bien'ici photo host, from four stored rows.
        yield 'the live cloudfront id' => ['https://photo.bienici.com/photo/x_d2m2j20yzublln.cloudfront.net_287_661294'];
        yield 'an image hash mid-path' => ['https://img.example.test/ph/a7m2c9/x.jpg'];
        yield 'a slug carrying a run' => ['https://www.example.test/annonces/xa30m2b/detail'];
    }

    #[DataProvider('poisonedPathSegments')]
    public function testASurfaceIsNeverReadOutOfTheMiddleOfAPathToken(string $url): void
    {
        // Above the prose, like every other case here: first-match-wins is half the defect.
        $listing = $this->read("{$url}\n3 pièces . 64,25 m²\n");

        self::assertSame(64.25, $listing->surfaceM2, "the run inside {$url} must not be read as a surface");
    }

    /** Read one body through the real `fetch()`, on a source that configures no numeric pattern. */
    private function read(string $body, string $linkHost = 'seloger.com/annonces/', string $extra = ''): RawListing
    {
        $definition = ConfigLoader::loadSources(self::ROOT . '/config/rent/sources.json')['seloger'];

        // Built by hand rather than by mutating the shipped block, so this cannot start passing
        // because seloger gained a `surface_pattern` of its own.
        $bare = new SourceDefinition(
            name: 'probe',
            enabled: false,
            family: $definition->family,
            type: 'email_alert',
            defaultTenure: $definition->defaultTenure,
            mixedTenure: false,
            map: $definition->map,
            params: ['from' => 'alerts@portal.test', 'link_host' => $linkHost],
        );

        $dir = sys_get_temp_dir() . '/surface-token-' . bin2hex(random_bytes(6));
        mkdir($dir);
        file_put_contents(
            $dir . '/probe.eml',
            "From: alerts@portal.test\r\nTo: me@example.invalid\r\nSubject: alerte\r\n"
                . "Date: Wed, 02 Sep 2026 07:24:51 +0200\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n"
                . "1 082 € CC\n" . $body . $extra
                . ($linkHost === 'seloger.com/annonces/' ? "https://www.seloger.com/annonces/1\n" : ''),
        );

        try {
            $source = new EmailAlertSource($bare, new FileMailbox($dir));
            $listings = $source->fetch();

            self::assertCount(1, $listings, 'the probe message must yield exactly one listing');

            return $listings[0];
        } finally {
            @unlink($dir . '/probe.eml');
            @rmdir($dir);
        }
    }
}
