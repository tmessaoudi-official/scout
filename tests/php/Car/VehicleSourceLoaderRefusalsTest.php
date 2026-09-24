<?php

declare(strict_types=1);

namespace Scout\Tests\Car;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Scout\Car\VehicleSourceLoader;
use Scout\Config\ConfigError;

/**
 * TRACK 6-A2 — the car loader's allow-list, and the ~21 refusals nothing exercised.
 *
 * `VehicleSourceLoader`'s own docblock promised that "every key an adapter could never act on is
 * refused rather than ignored". That was true of exactly two keys — `seller_pattern` and
 * `postcode_pattern`, the `UNREAD_PARAMS` pair — and false of every other spelling: a
 * `link_hosts`, a ported-from-rent `commune_pattern` or a plain typo loaded cleanly and did
 * nothing at all. That is the ENABLING CONDITION of the inert-parameter class this repo has now
 * paid for three times (rent `title_pattern` unread on every non-segmented source for a month;
 * PAP's `link_host` carrying no path; the car pair above), sitting open beneath a comment saying
 * it was closed.
 *
 * The rent side closed it with `ConfigLoader::EMAIL_ALERT_PARAMS`. This is the same mechanism, and
 * the same two rules travel with it:
 *
 *  - the allow-list is the UNION OF EVERY READER, read from the CODE (`grep -rnoE '[-]>param\('
 *    src/php/Car/ src/php/Cli/`) rather than from what the shipped config happens to use — a param
 *    an adapter reads but the list omits is a refusal on a CORRECT config, which is loud, and the
 *    safe direction;
 *  - it lives OUTSIDE the `enabled` branch, because `--source=<name>` force-runs a disabled source
 *    and that is the documented onboarding path (`/add-source` step 5). A guard firing only on
 *    enabled sources is one the intended workflow walks straight past.
 *
 * The second half of this file is N10: the audit measured ~21 of the car loader's 24 refusals as
 * having no test at all. A refusal nobody exercises is indistinguishable from one that was
 * silently deleted, and this loader's whole job is to refuse.
 */
#[CoversClass(VehicleSourceLoader::class)]
final class VehicleSourceLoaderRefusalsTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../..';

    // ---------------------------------------------------------------- the allow-list (A2)

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function unreadKeys(): iterable
    {
        // A rent key ported across by hand. Every one of these is a plausible edit, and every one
        // of them did nothing, in silence, before this guard existed.
        yield 'a rent-side key ported over' => ['commune_pattern'];
        yield 'another rent-side key' => ['surface_pattern'];
        yield 'a plural typo on a real key' => ['link_hosts'];
        yield 'a near-miss on a real key' => ['make_model_patterns'];
        yield 'an invented key' => ['mileage_pattern'];
    }

    #[DataProvider('unreadKeys')]
    public function testAParamNoCarAdapterReadsIsRefused(string $key): void
    {
        $path = $this->write([
            'sources' => [
                'probe' => [
                    'enabled' => false,
                    'family' => 'portal',
                    'type' => 'email_alert',
                    'params' => [
                        'from' => 'alerts@portal.test',
                        'link_host' => 'portal.test',
                        $key => 'peu importe',
                    ],
                ],
            ],
        ]);

        try {
            $this->expectException(ConfigError::class);
            $this->expectExceptionMessageMatches('~' . preg_quote($key, '~') . '~');
            VehicleSourceLoader::load($path);
        } finally {
            @unlink($path);
        }
    }

    /**
     * The message must NAME what is read, not merely say no.
     *
     * A refusal that does not list the alternatives sends the reader to the source to find them,
     * which is how the rent-side message was written and why it is the one being copied.
     */
    public function testTheRefusalNamesTheParamsThatAreRead(): void
    {
        $path = $this->write([
            'sources' => [
                'probe' => [
                    'enabled' => false,
                    'family' => 'portal',
                    'type' => 'email_alert',
                    'params' => ['from' => 'a@b.test', 'link_host' => 'b.test', 'nawak' => 'x'],
                ],
            ],
        ]);

        try {
            VehicleSourceLoader::load($path);
            self::fail('un paramètre inconnu doit être refusé');
        } catch (ConfigError $e) {
            foreach (['from', 'link_host', 'price_pattern', 'facts_pattern', 'make_model_source'] as $read) {
                self::assertStringContainsString($read, $e->getMessage());
            }
        } finally {
            @unlink($path);
        }
    }

    /**
     * OUTSIDE the `enabled` branch — asserted separately because "it fires on the shipped config"
     * and "it fires on the source you are onboarding" are different guarantees, and the onboarding
     * one is the reason the rent side moved its own guard out of that branch.
     */
    public function testItFiresOnADisabledSourceToo(): void
    {
        $path = $this->write([
            'sources' => [
                'probe' => [
                    'enabled' => false,
                    'family' => 'portal',
                    'type' => 'email_alert',
                    'params' => ['from' => 'a@b.test', 'link_host' => 'b.test', 'inconnu' => 'x'],
                ],
            ],
        ]);

        try {
            $this->expectException(ConfigError::class);
            VehicleSourceLoader::load($path);
        } finally {
            @unlink($path);
        }
    }

    /**
     * A `sitemap_jsonld` source reads NO params at all — measured, not assumed:
     * `SitemapVehicleSource` contains no `param(` call and autohero configures none. So any param
     * on one is inert by construction, and the message says which type reads nothing.
     */
    public function testAParamOnASitemapSourceIsRefusedBecauseThatAdapterReadsNone(): void
    {
        $path = $this->write([
            'sources' => [
                'probe' => [
                    'enabled' => false,
                    'family' => 'dealer',
                    'type' => 'sitemap_jsonld',
                    'url' => 'https://example.test/sitemap.xml',
                    'item_url_pattern' => '~/lot-(\d+)~',
                    'map' => ['ref' => 'sku', 'price' => 'offers.price'],
                    'params' => ['from' => 'a@b.test'],
                ],
            ],
        ]);

        try {
            $this->expectException(ConfigError::class);
            $this->expectExceptionMessageMatches('~from~');
            VehicleSourceLoader::load($path);
        } finally {
            @unlink($path);
        }
    }

    /**
     * THE COUNTERWEIGHT, and it is load-bearing: a refusal satisfied by refusing everything is
     * satisfied by deleting the feature. Every key the code actually reads must still load.
     */
    public function testEveryParamAnAdapterReadsIsStillAccepted(): void
    {
        $path = $this->write([
            'sources' => [
                'probe' => [
                    'enabled' => false,
                    'family' => 'portal',
                    'type' => 'email_alert',
                    'params' => [
                        'from' => 'alerts@portal.test',
                        'link_host' => 'portal.test',
                        'subject_pattern' => '~alerte~i',
                        'card_separator' => "\nAnnonce\n",
                        'price_pattern' => '~([\d ]+)\s*€~',
                        'title_pattern' => '~vous propose (.+?) à~u',
                        'facts_pattern' => '~(?<year>\d{4})~',
                        'make_model_pattern' => '~/(?<make>[a-z]+)/~',
                        'make_model_source' => 'title',
                    ],
                ],
            ],
        ]);

        try {
            self::assertArrayHasKey('probe', VehicleSourceLoader::load($path));
        } finally {
            @unlink($path);
        }
    }

    /** The other half of the counterweight: the file that actually ships. */
    public function testTheShippedCarConfigStillLoads(): void
    {
        $definitions = VehicleSourceLoader::load(self::ROOT . '/config/car/sources.json');

        self::assertArrayHasKey('paruvendu', $definitions);
        self::assertArrayHasKey('leboncoin', $definitions);
        self::assertArrayHasKey('autohero', $definitions);
    }

    // ---------------------------------------------------------------- the untested refusals (N10)

    /**
     * Every refusal this loader can raise, one row each, each asserting the MESSAGE and not merely
     * that something threw — a `ConfigError` from the wrong guard is a green test proving nothing.
     *
     * @return iterable<string, array{0: array<string,mixed>, 1: string}>
     */
    public static function refusals(): iterable
    {
        $emailBase = ['enabled' => false, 'family' => 'portal', 'type' => 'email_alert'];

        yield 'feed_silent_days on a type that reports no feed date' => [
            ['sources' => ['p' => [
                'enabled' => false, 'family' => 'dealer', 'type' => 'sitemap_jsonld',
                'url' => 'https://e.test/s.xml', 'item_url_pattern' => '~/l-(\d+)~',
                'map' => ['ref' => 'sku', 'price' => 'offers.price'],
                'feed_silent_days' => 3,
            ]]],
            'feed_silent_days',
        ];

        yield 'feed_silent_days of zero' => [
            ['sources' => ['p' => $emailBase + ['feed_silent_days' => 0, 'params' => ['from' => 'a@b.test']]]],
            'au moins 1 jour',
        ];

        foreach (['subject_pattern', 'price_pattern', 'facts_pattern', 'title_pattern', 'make_model_pattern'] as $key) {
            yield 'an uncompilable ' . $key => [
                ['sources' => ['p' => $emailBase + ['params' => ['from' => 'a@b.test', $key => '~(non fermé~']]]],
                $key,
            ];
        }

        yield 'make_model_source naming something that is neither link nor title' => [
            ['sources' => ['p' => $emailBase + ['params' => [
                'from' => 'a@b.test', 'make_model_pattern' => '~(.+)~', 'make_model_source' => 'titre',
            ]]]],
            'make_model_source',
        ];

        yield 'make_model_source without the pattern it names' => [
            ['sources' => ['p' => $emailBase + ['params' => ['from' => 'a@b.test', 'make_model_source' => 'title']]]],
            'make_model_pattern',
        ];

        yield 'an enabled email source naming no sender' => [
            ['sources' => ['p' => ['enabled' => true, 'family' => 'portal', 'type' => 'email_alert', 'params' => ['link_host' => 'b.test']]]],
            'from',
        ];

        yield 'an enabled email source naming no link host' => [
            ['sources' => ['p' => ['enabled' => true, 'family' => 'portal', 'type' => 'email_alert', 'params' => ['from' => 'a@b.test']]]],
            'link_host',
        ];

        yield 'a sitemap source with no sitemap url' => [
            ['sources' => ['p' => ['enabled' => false, 'family' => 'dealer', 'type' => 'sitemap_jsonld',
                'item_url_pattern' => '~/l-(\d+)~', 'map' => ['ref' => 'sku', 'price' => 'offers.price']]]],
            'url',
        ];

        yield 'a sitemap source with no item pattern' => [
            ['sources' => ['p' => ['enabled' => false, 'family' => 'dealer', 'type' => 'sitemap_jsonld',
                'url' => 'https://e.test/s.xml', 'map' => ['ref' => 'sku', 'price' => 'offers.price']]]],
            'item_url_pattern',
        ];

        yield 'a sitemap source whose item pattern cannot compile' => [
            ['sources' => ['p' => ['enabled' => false, 'family' => 'dealer', 'type' => 'sitemap_jsonld',
                'url' => 'https://e.test/s.xml', 'item_url_pattern' => '~/l-(\d+~',
                'map' => ['ref' => 'sku', 'price' => 'offers.price']]]],
            'item_url_pattern',
        ];

        yield 'a sitemap source mapping no ref' => [
            ['sources' => ['p' => ['enabled' => false, 'family' => 'dealer', 'type' => 'sitemap_jsonld',
                'url' => 'https://e.test/s.xml', 'item_url_pattern' => '~/l-(\d+)~', 'map' => ['price' => 'offers.price']]]],
            'map.ref',
        ];

        yield 'a sitemap source mapping no price' => [
            ['sources' => ['p' => ['enabled' => false, 'family' => 'dealer', 'type' => 'sitemap_jsonld',
                'url' => 'https://e.test/s.xml', 'item_url_pattern' => '~/l-(\d+)~', 'map' => ['ref' => 'sku']]]],
            'map.price',
        ];

        yield 'an alcopa source with no search url' => [
            ['sources' => ['p' => ['enabled' => false, 'family' => 'auction', 'type' => 'alcopa']]],
            'recherche enregistrée',
        ];

        yield 'an alcopa source pointing at another site' => [
            ['sources' => ['p' => ['enabled' => false, 'family' => 'auction', 'type' => 'alcopa', 'url' => 'https://e.test/recherche?x=1']]],
            'recherche enregistrée',
        ];

        yield 'an alcopa source carrying a map it would never read' => [
            ['sources' => ['p' => ['enabled' => false, 'family' => 'auction', 'type' => 'alcopa',
                'url' => 'https://www.alcopa-auction.fr/recherche?categories%5B%5D=VP', 'map' => ['ref' => 'sku']]]],
            'ni item_url_pattern ni map',
        ];

        yield 'a fixture source naming no file' => [
            ['sources' => ['p' => ['enabled' => false, 'family' => 'portal', 'type' => 'fixture']]],
            'fixture',
        ];

        yield 'an unknown type' => [
            ['sources' => ['p' => ['enabled' => false, 'family' => 'portal', 'type' => 'rss']]],
            'type',
        ];

        yield 'an unknown family' => [
            ['sources' => ['p' => ['enabled' => false, 'family' => 'concessionnaire', 'type' => 'fixture', 'fixture' => 'x.json']]],
            'family',
        ];

        yield 'no sources object at all' => [
            ['portails' => []],
            'sources',
        ];
    }

    /**
     * @param array<string,mixed> $config
     */
    #[DataProvider('refusals')]
    public function testTheLoaderRefuses(array $config, string $expectedInMessage): void
    {
        $path = $this->write($config);

        try {
            VehicleSourceLoader::load($path);
            self::fail('attendu un refus mentionnant « ' . $expectedInMessage . ' »');
        } catch (ConfigError $e) {
            self::assertStringContainsString($expectedInMessage, $e->getMessage());
        } finally {
            @unlink($path);
        }
    }

    public function testAnUnreadableFileIsRefusedByName(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~illisible~');
        VehicleSourceLoader::load(sys_get_temp_dir() . '/scout-car-absent-' . bin2hex(random_bytes(6)) . '.json');
    }

    public function testInvalidJsonIsRefusedAsJsonAndNotAsAMissingKey(): void
    {
        $path = sys_get_temp_dir() . '/scout-car-src-' . bin2hex(random_bytes(6)) . '.json';
        file_put_contents($path, '{"sources": {');

        try {
            $this->expectException(ConfigError::class);
            $this->expectExceptionMessageMatches('~JSON invalide~');
            VehicleSourceLoader::load($path);
        } finally {
            @unlink($path);
        }
    }

    public function testAJsonScalarAtTheRootIsRefused(): void
    {
        $path = sys_get_temp_dir() . '/scout-car-src-' . bin2hex(random_bytes(6)) . '.json';
        file_put_contents($path, '"nope"');

        try {
            $this->expectException(ConfigError::class);
            $this->expectExceptionMessageMatches('~objet JSON~');
            VehicleSourceLoader::load($path);
        } finally {
            @unlink($path);
        }
    }

    /**
     * `_`-prefixed keys are comments, on the sources object as everywhere else — asserted because
     * an allow-list is exactly the kind of change that starts refusing them.
     */
    public function testACommentKeyIsSkippedRatherThanValidated(): void
    {
        $path = $this->write([
            'sources' => [
                '_comment' => 'ceci est une note, pas une source',
                'p' => ['enabled' => false, 'family' => 'portal', 'type' => 'fixture', 'fixture' => 'x.json'],
            ],
        ]);

        try {
            $definitions = VehicleSourceLoader::load($path);
            self::assertArrayHasKey('p', $definitions);
            self::assertArrayNotHasKey('_comment', $definitions);
        } finally {
            @unlink($path);
        }
    }

    /** @param array<string, mixed> $config */
    private function write(array $config): string
    {
        $path = sys_get_temp_dir() . '/scout-car-src-' . bin2hex(random_bytes(6)) . '.json';
        file_put_contents($path, json_encode($config, JSON_THROW_ON_ERROR));

        return $path;
    }
}
