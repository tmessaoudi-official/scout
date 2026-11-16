<?php

declare(strict_types=1);

namespace Scout\Tests\Rent\Adapters;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Scout\Adapters\Http\HttpClient;
use Scout\Adapters\Http\HttpRequest;
use Scout\Adapters\Http\HttpResponse;
use Scout\Adapters\Http\Robots;
use Scout\Rent\Adapters\HttpJsonSource;
use Scout\Adapters\SourceError;
use Scout\Config\ConfigError;
use Scout\Rent\Config\ConfigLoader;
use Scout\Rent\Config\FieldMap;
use Scout\Rent\Config\SourceDefinition;
use Scout\Rent\Store\Store;

/**
 * `embedded_json_selector` — a JSON payload served inside an HTML page.
 *
 * Logirep/Polylogis is Drupal: its search results are not an API response and not HTML cards either.
 * The page ships 113 records as JSON inside
 * `<script type="application/json" data-drupal-selector="drupal-settings-json">`, and the visible
 * markup carries exactly two `€` — the search form. Neither existing adapter could read that: `html`
 * maps CSS selectors over repeated card elements and there is only ONE script tag, while `json`
 * parses the whole response body, which here is HTML.
 *
 * The extension is one step in the middle: pull the element's text, then hand it to the SAME JSON
 * path that every other json source uses. `items_path`, the field map, `ListingMapper` and therefore
 * hard rule 9 all keep exactly one implementation — which is the test this file is really applying
 * to the design.
 *
 * The behaviour that matters most here is the refusal. A selector that stops matching — because the
 * site renamed a `data-` attribute, which is the single likeliest thing to change — must THROW.
 * Returning `[]` would turn a live breakage into "no listings today", forever, and `CLAUDE.md`
 * names that as this codebase's highest-frequency defect class (hard rule 3).
 */
#[CoversNothing]
final class EmbeddedJsonTest extends TestCase
{
    private ?string $dbPath = null;

    protected function tearDown(): void
    {
        if ($this->dbPath !== null && is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
    }

    private const string PAGE = <<<'HTML'
        <!doctype html><html><body>
          <form><input name="ss_trnsctntp" value="leasing"></form>
          <script type="application/json" data-drupal-selector="drupal-settings-json">
            {"searchResults":{"results":[
              {"zs_field_ad_id":"lr-1","zs_field_short_title":"Appartement 4 pieces 87 m2",
               "tcngramm_X3b_fr_address_locality":["Sartrouville"],
               "tcngramm_X3b_fr_address_postalcode":["78500"],
               "zs_field_public_price":"1278.82","fts_field_living_space":87,
               "its_field_typology":4,"zs_url":"/annonce/lr-1"},
              {"zs_field_ad_id":"lr-2","zs_field_short_title":"Place de parking 10 m2",
               "tcngramm_X3b_fr_address_locality":["Bezons"],
               "tcngramm_X3b_fr_address_postalcode":["95870"],
               "zs_field_public_price":"75.00","fts_field_living_space":10,
               "its_field_typology":0,"zs_url":"/annonce/lr-2"}
            ]}}
          </script>
        </body></html>
        HTML;

    public function testTheEmbeddedPayloadIsReadThroughTheOrdinaryJsonPath(): void
    {
        $rows = $this->source(self::PAGE)->fetch();

        self::assertCount(2, $rows);
        self::assertSame('lr-1', $rows[0]->externalId);
        self::assertSame('Sartrouville', $rows[0]->commune);
        self::assertSame('78500', $rows[0]->postcode);
        self::assertSame(87.0, $rows[0]->surfaceM2, 'surface is a float on RawListing — 42.97 m2 is a real value');
        self::assertSame(4, $rows[0]->rooms);
    }

    public function testTheSolrStyleSingleElementListsResolveToValues(): void
    {
        // The whole source turns on this. Every text field on a Drupal/Solr payload is boxed as a
        // one-element list, and a null commune makes `matchesCommune()` refuse every listing while
        // the source reports a healthy count.
        $rows = $this->source(self::PAGE)->fetch();

        self::assertNotNull($rows[0]->commune, 'a boxed commune must not read as unknown');
        self::assertNotNull($rows[1]->commune);
        self::assertSame('Bezons', $rows[1]->commune);
    }

    public function testRentIsFiledAsHorsChargesAndNeverAsChargesComprises(): void
    {
        // The 2026-08-22 ruling. Logirep quotes `h.c.` and its charges are prose that is not always
        // present, so the value goes to `rentHc` and `rentCc` stays null -- which is what stops
        // `CriteriaEngine` from ever comparing it to `max_rent_cc`. Filing it as CC would understate
        // every rent by ~30% against the ceiling, silently.
        $rows = $this->source(self::PAGE)->fetch();

        self::assertNull($rows[0]->rentCc, 'an h.c. figure must never be filed as charges comprises');
        self::assertSame(1279, $rows[0]->rentHc);
    }

    public function testAZeroRoomCountSurvivesAsZeroRatherThanUnknown(): void
    {
        // A parking has zero rooms. That is a real value, and `min_rooms` must be able to reject it;
        // read as `null` it would mean "unknown", which hard rule 9 says is NOT below the minimum,
        // and the parking would sail past the disqualifier.
        $rows = $this->source(self::PAGE)->fetch();

        self::assertSame(0, $rows[1]->rooms);
    }

    // ── the refusals ──────────────────────────────────────────────────────────────────────────────

    public function testAMissingScriptTagThrowsRatherThanYieldingNoListings(): void
    {
        // Hard rule 3, on the likeliest breakage this source has: the site renames its
        // `data-drupal-selector` and the payload is simply not where it was. An empty list here
        // reads as a quiet rental market and stays green forever.
        $this->expectException(SourceError::class);
        $this->expectExceptionMessageMatches('/embedded_json_selector/');

        $this->source('<!doctype html><html><body><p>rien</p></body></html>')->fetch();
    }

    public function testAnEmptyScriptTagThrowsToo(): void
    {
        // A tag that matches but carries nothing is the same failure wearing a match.
        $this->expectException(SourceError::class);

        $this->source(
            '<html><body><script type="application/json" data-drupal-selector="drupal-settings-json">'
                . '   </script></body></html>',
        )->fetch();
    }

    public function testAMalformedPayloadThrowsAndNamesTheSelector(): void
    {
        $this->expectException(SourceError::class);
        $this->expectExceptionMessageMatches('/JSON/i');

        $this->source(
            '<html><body><script type="application/json" data-drupal-selector="drupal-settings-json">'
                . '{"searchResults": {' . '</script></body></html>',
        )->fetch();
    }

    public function testAnItemsPathThatMovedStillThrows(): void
    {
        // The extension must not weaken the guard that already exists on `items_path`: still 200,
        // still valid JSON, but the results moved.
        $this->expectException(SourceError::class);

        $this->source(
            '<html><body><script type="application/json" data-drupal-selector="drupal-settings-json">'
                . '{"autreChose": []}' . '</script></body></html>',
        )->fetch();
    }

    public function testTheLoaderRefusesAnEmbeddedSelectorOnANonJsonSource(): void
    {
        // Mirrors the refusal `detail_map` already carries: a key that the adapter for this type
        // will never read is worse than absent, because it LOOKS like configuration and reads as
        // though the behaviour were switched on.
        //
        // `enabled: true` on purpose. Every structural refusal in the loader sits inside the
        // `if ($enabled)` block, and that is deliberate rather than an oversight: a DISABLED source
        // is a draft, and refusing to load the entire config because a half-written block carries a
        // stray key would take down the working sources with it.
        $path = $this->writeConfig([
            'sources' => [
                'x' => [
                    'enabled' => true,
                    'family' => 'institutional',
                    'type' => 'html',
                    'mixed_tenure' => true,
                    'url' => 'https://example.test/x',
                    'item_selector' => 'article',
                    'embedded_json_selector' => 'script#data',
                    'map' => ['ref' => '.ref'],
                ],
            ],
        ]);

        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('/embedded_json_selector/');

        ConfigLoader::loadSources($path);
    }

    public function testTheLoaderAcceptsItOnAJsonSource(): void
    {
        $path = $this->writeConfig([
            'sources' => [
                'x' => [
                    'enabled' => true,
                    'family' => 'institutional',
                    'type' => 'json',
                    'mixed_tenure' => true,
                    'url' => 'https://example.test/x',
                    'embedded_json_selector' => 'script[data-drupal-selector="drupal-settings-json"]',
                    'items_path' => 'searchResults.results',
                    'map' => ['ref' => 'zs_field_ad_id'],
                ],
            ],
        ]);

        $definitions = array_values(ConfigLoader::loadSources($path));

        self::assertSame(
            'script[data-drupal-selector="drupal-settings-json"]',
            $definitions[0]->embeddedJsonSelector,
        );
    }

    /** @param array<string,mixed> $config */
    private function writeConfig(array $config): string
    {
        $path = sys_get_temp_dir() . '/rentwatch-embedded-' . bin2hex(random_bytes(8)) . '.json';
        file_put_contents($path, json_encode($config, JSON_THROW_ON_ERROR));

        return $path;
    }

    private function source(string $html): HttpJsonSource
    {
        $this->dbPath = sys_get_temp_dir() . '/rentwatch-embedded-' . bin2hex(random_bytes(8)) . '.sqlite3';

        $definition = new SourceDefinition(
            name: 'logirep',
            enabled: true,
            family: 'institutional',
            type: 'json',
            mixedTenure: true,
            url: 'https://example.test/recherche',
            baseUrl: 'https://example.test',
            embeddedJsonSelector: 'script[data-drupal-selector="drupal-settings-json"]',
            itemsPath: 'searchResults.results',
            map: new FieldMap(
                ref: ['zs_field_ad_id'],
                title: ['zs_field_short_title'],
                url: ['zs_url'],
                commune: ['tcngramm_X3b_fr_address_locality'],
                postcode: ['tcngramm_X3b_fr_address_postalcode'],
                rent: ['zs_field_public_price'],
                chargesIncluded: false,
                surface: ['fts_field_living_space'],
                rooms: ['its_field_typology'],
            ),
            rateLimitMs: 0,
        );

        return new HttpJsonSource(
            $definition,
            new class($html) implements HttpClient {
                public function __construct(private readonly string $body) {}

                public function send(HttpRequest $request): HttpResponse
                {
                    return new HttpResponse(200, $this->body);
                }
            },
            Robots::parse(''),
        );
    }
}
