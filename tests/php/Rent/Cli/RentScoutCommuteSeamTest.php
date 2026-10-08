<?php

declare(strict_types=1);

namespace Scout\Tests\Rent\Cli;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Scout\Adapters\Http\HttpClient;
use Scout\Adapters\Http\HttpError;
use Scout\Adapters\Http\HttpRequest;
use Scout\Adapters\Http\HttpResponse;
use Scout\Rent\Cli\RentScout;

/**
 * COMMUTE TRAFFIC GOES THROUGH THE CLI's HTTP SEAM (architecture review C-10's port half, step 8a, 2026-10-08).
 *
 * `RentScout` takes an injected {@see HttpClient} so that what the CLI requests is observable — it is the
 * seam {@see RentScoutRobotsTest} proves hard rule 5 through. `commutePlanner()` built
 * `new NavitiaCommute(new CurlHttpClient(), …)` beside it, so the one outbound call that runs only in
 * production (commute is ON only on the developer's machine) was also the one no CLI test could see.
 *
 * The failing-first shape: the recording client received no PRIM request, because the planner talked to its
 * own client, which `SCOUT_OFFLINE` refused and `NavitiaCommute` swallowed.
 */
#[CoversNothing]
final class RentScoutCommuteSeamTest extends TestCase
{
    private const string NOW = '2026-08-07T12:00:00+02:00';

    private string $root = '';

    protected function tearDown(): void
    {
        putenv('IDFM_API_KEY');
        putenv('RENT_SCOUT_DB');
        if ($this->root !== '') {
            self::removeTree($this->root);
        }
    }

    public function testTheCommutePlannerUsesTheInjectedClient(): void
    {
        $this->root = $this->tempRoot();
        putenv('IDFM_API_KEY=test-key-not-a-secret');
        putenv('RENT_SCOUT_DB=' . $this->root . '/state/rent-watch.sqlite3');
        $client = new CommuteRecordingClient();

        $seed = $this->scout($client, ['run', '--once', '--seed']);
        $run = $this->scout($client, ['run', '--once', '-v']);

        $prim = array_values(array_filter(
            $client->urls,
            static fn (string $url): bool => str_starts_with($url, 'https://prim.iledefrance-mobilites.fr/'),
        ));
        self::assertNotSame(
            [],
            $prim,
            "no commute request reached the injected client — the planner built its own\n" . $seed . "\n" . $run,
        );
        self::assertSame([], array_values(array_diff($client->urls, $prim)), 'a fixture source makes no other request');
    }

    /**
     * @param list<string> $argv
     *
     * @return string what the run printed, so a failure says what the CLI did rather than only what it did not
     */
    private function scout(HttpClient $client, array $argv): string
    {
        $out = fopen('php://memory', 'r+');
        $err = fopen('php://memory', 'r+');
        self::assertIsResource($out);
        self::assertIsResource($err);

        $code = (new RentScout($this->root, $out, $err, self::NOW, $client))->run($argv);
        rewind($out);
        rewind($err);

        return implode(' ', $argv) . ' → ' . $code . "\n" . stream_get_contents($out) . stream_get_contents($err);
    }

    private function tempRoot(): string
    {
        $root = sys_get_temp_dir() . '/rentwatch-commute-' . bin2hex(random_bytes(8));
        mkdir($root . '/config/rent', 0o775, true);
        mkdir($root . '/state', 0o775, true);
        mkdir($root . '/tests/fixtures/rent/fixture_demo', 0o775, true);
        copy(
            dirname(__DIR__, 4) . '/tests/fixtures/rent/fixture_demo/search.json',
            $root . '/tests/fixtures/rent/fixture_demo/search.json',
        );

        file_put_contents($root . '/config/rent/criteria.json', json_encode([
            'communes' => ['Sartrouville'],
            'postcode_prefixes' => ['78'],
            'min_rooms' => 4,
            'max_rent_cc' => 1800,
            'commute' => ['enabled' => true, 'station' => 'Gare de Lyon', 'max_minutes' => 60],
            // An enabled commute weighted 0 is refused at load ("can never affect a score").
            'weights' => ['commute' => 20],
        ], JSON_THROW_ON_ERROR));

        // The shipped fixture_demo block, as RentScoutHeartbeatTest carries it.
        file_put_contents($root . '/config/rent/sources.json', json_encode([
            'sources' => [
                'fixture_demo' => [
                    'enabled' => true,
                    'family' => 'institutional',
                    'type' => 'fixture',
                    'mixed_tenure' => true,
                    'fixture' => 'tests/fixtures/rent/fixture_demo/search.json',
                    'items_path' => 'results.items',
                    'map' => [
                        'ref' => 'id',
                        'title' => 'title',
                        'commune' => ['city', 'address.city'],
                        'cp' => ['zipCode', 'address.postalCode'],
                        'rent' => ['rent.total', 'price'],
                        'charges_included' => true,
                        'surface' => 'surface',
                        'rooms' => ['rooms', 'nbRooms'],
                        'description' => 'description',
                        'tenure_field' => 'financement',
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        return $root;
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}

/** Records every url and answers nothing: the planner swallows the refusal, and the log is the assertion. */
final class CommuteRecordingClient implements HttpClient
{
    /** @var list<string> */
    public array $urls = [];

    public function send(HttpRequest $request): HttpResponse
    {
        $this->urls[] = $request->url;

        throw new HttpError('CommuteRecordingClient answers nothing: ' . $request->url);
    }
}
