<?php

declare(strict_types=1);

namespace Scout\Tests\Car;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Adapters\Mail\FileMailbox;
use Scout\Car\VehicleEmailSource;
use Scout\Car\VehicleSourceDefinition;
use Scout\Car\VehicleSourceLoader;
use Scout\Car\VehicleStore;
use Scout\Core\RunStore;
use Scout\Core\SourceHealthService;
use Scout\Core\CountsPatternMisses;
use Scout\Core\SourceStatus;

/**
 * F27 — THE CAR DOMAIN COUNTED NO EXTRACTION MISSES AT ALL, and the gap was built by the fix that
 * exists to prevent exactly it.
 *
 * `PatternMissLog` shipped on 2026-08-31 as Track 1h's answer to PAP running four days with both
 * positional patterns dead, 23 rows storing a null surface and 19 of them notified as MATCH while
 * `doctor` said `ok`. It landed on ONE adapter of five — `EmailAlertSource` — so `HtmlSource`,
 * `JsonSource`, `DetailHydrator` and this class counted nothing. *A fix landing on one of two
 * symmetric surfaces* is this repo's named recurring defect, and it was committed by the fix for
 * the finding that names it.
 *
 * Measured before this class existed: **13 of 99 stored ParuVendu rows carry `body`, `fuel`, `year`
 * and `mileageKm` ALL null** — the identical count on four fields being one `facts_pattern` miss
 * rather than four independent absences — with nothing counting them and nothing reporting them.
 *
 * **The first test is the load-bearing one.** The car adapter cannot funnel its four patterns
 * through one `matchParam()` the way the rent twin does — price needs `PREG_OFFSET_CAPTURE` to
 * locate its line, facts needs `PREG_SET_ORDER` for named groups, title reads the SUBJECT, and
 * make/model reads whichever haystack `make_model_source` names. So there are four call sites, and
 * "four places to forget" is answered by SET MEMBERSHIP rather than by discipline: the set is read
 * from `VehicleSourceLoader::PATTERN_PARAMS` by reflection, so a pattern param added tomorrow and
 * left uninstrumented fails here rather than going quietly dark in production.
 */
#[CoversClass(VehicleEmailSource::class)]
final class VehicleEmailPatternMissTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../..';
    private const string NOW = '2026-09-01T12:00:00+00:00';

    /**
     * Read off the loader, never listed here — a literal list would be a second place to forget,
     * which is the defect this test exists to make impossible.
     *
     * `subject_pattern` is subtracted because it is MESSAGE-level: a miss means "this mail is not
     * ours", which is the filter working, and counting it would put every unrelated message in the
     * denominator. `UNREAD_PARAMS` is subtracted because those are refused at load and no adapter
     * may read them.
     *
     * `make_model_unknown_pattern` (Track 6-A4) is subtracted for `subject_pattern`'s reason read
     * the other way round: it does not describe an extraction at all, it describes what the portal
     * writes when it HAS no answer. `make_model_pattern` has already hit by the time it is applied,
     * so a "miss" here means the ordinary case — the marque was named — and counting it would put
     * every correctly-read card in the denominator and hold the ratio near 100 % for ever. That is
     * F30's shape exactly. Its own behaviour is pinned by `MakeSentinelTest`, not by absence here.
     *
     * @return list<string>
     */
    private static function cardLevelPatternParams(): array
    {
        $r = new \ReflectionClass(VehicleSourceLoader::class);
        /** @var list<string> $all */
        $all = $r->getConstant('PATTERN_PARAMS');
        /** @var list<string> $unread */
        $unread = $r->getConstant('UNREAD_PARAMS');

        // `card_separator_pattern` (row 38) is MESSAGE-level like `subject_pattern`: it decides
        // where cards begin, not whether a card's field was read, so it has no miss to count.
        return array_values(array_diff($all, $unread, ['subject_pattern', 'card_separator_pattern', 'make_model_unknown_pattern']));
    }

    /**
     * EVERY CARD-LEVEL PATTERN THIS ADAPTER READS IS COUNTED — the guard against a fifth reader
     * being added and instrumented by nobody.
     *
     * leboncoin is the fixture because it is the only car source configuring all four; on a source
     * that configures three, the fourth would be absent from the counts for a legitimate reason and
     * this assertion could not tell that apart from an uninstrumented one.
     */
    public function testEveryCardLevelPatternParamIsCounted(): void
    {
        $source = $this->source('leboncoin');
        $source->fetch();

        $counted = array_keys($source->patternMisses()->counts());
        sort($counted);
        $expected = self::cardLevelPatternParams();
        sort($expected);

        self::assertSame(
            $expected,
            $counted,
            'a pattern param that no call site records goes dark in production exactly as F27 did — '
                . 'instrument it in VehicleEmailSource, or subtract it here with a reason',
        );
    }

    /** The counterweight: a healthy source reports NOTHING, or the signal is furniture. */
    public function testAHealthySourceReportsNoBlindPattern(): void
    {
        [$source, $store] = $this->sourceAndStore('leboncoin');
        $this->recordHealthyRuns($store, 'leboncoin');
        $source->fetch();

        self::assertSame([], $source->patternMisses()->total());
        self::assertSame(
            SourceStatus::OK,
            (new SourceHealthService($store->runs()))->of($source, RunStore::instant(self::NOW))->status,
            'a source whose patterns all match must not be warned about',
        );
    }

    /**
     * A PATTERN THAT MATCHES NOTHING AT ALL REACHES `health()` AS A WARN — the whole point.
     *
     * Broken by rewriting the definition rather than by editing the shipped config: the guarantee is
     * that a dead pattern is REPORTED, and proving it must not depend on the repo shipping one.
     */
    public function testAPatternThatMatchesNothingWarnsThroughHealth(): void
    {
        [$source, $store] = $this->sourceAndStore('leboncoin', ['facts_pattern' => '~^\h*IL-N-Y-A-RIEN-ICI\h*$~mu']);
        $this->recordHealthyRuns($store, 'leboncoin');
        $source->fetch();

        self::assertSame(['facts_pattern'], $source->patternMisses()->total());

        $health = (new SourceHealthService($store->runs()))->of($source, RunStore::instant(self::NOW));
        self::assertSame(SourceStatus::WARN_DROP, $health->status);
        self::assertStringContainsString('facts_pattern', $health->detail);
        self::assertStringContainsString('gabarit', $health->detail);
    }

    /**
     * WARN, NEVER BROKEN. Cards are still flowing and the source is still reachable; what changed is
     * the portal's layout. Escalating to BROKEN would say "stop polling" about a source that is
     * delivering, which is the wrong instruction and the wrong urgency.
     */
    public function testADeadPatternDoesNotEscalateToBroken(): void
    {
        [$source, $store] = $this->sourceAndStore('leboncoin', ['facts_pattern' => '~^\h*RIEN\h*$~mu']);
        $this->recordHealthyRuns($store, 'leboncoin');
        $source->fetch();

        self::assertNotSame(SourceStatus::BROKEN, (new SourceHealthService($store->runs()))->of($source, RunStore::instant(self::NOW))->status);
    }

    /**
     * A COUNT NEVER SPANS TWO FETCHES.
     *
     * Without the `reset()` a second pass reports the first one's misses, so a template already
     * fixed keeps warning — which sends an operator to read a capture that is fine, and teaches them
     * to ignore the signal. The failure is worse than silence because it is credible.
     */
    public function testTheCountIsPerPassAndNeverAccumulates(): void
    {
        $source = $this->source('leboncoin');
        $source->fetch();
        $first = $source->patternMisses()->counts();
        $source->fetch();

        self::assertSame($first, $source->patternMisses()->counts(), 'a second identical pass must read identically');
    }

    /**
     * `subject_pattern` IS NOT COUNTED, and this is the counterweight to the reflection test above.
     *
     * A subject filter that rejects a message is the filter DOING ITS JOB — this mailbox is the
     * developer's own and carries five portals' mail plus everything else. Counting those attempts
     * would put every unrelated message in the denominator, and `total()` fires on a ratio, so the
     * signal would be diluted into permanent silence by the very mail it exists to ignore.
     */
    public function testTheMessageLevelSubjectFilterIsNotCounted(): void
    {
        $source = $this->source('leboncoin');
        $source->fetch();

        self::assertArrayNotHasKey('subject_pattern', $source->patternMisses()->counts());
    }

    /** Both CLIs gate their report on the INTERFACE, so implementing it is what makes a source visible. */
    public function testTheSourceAnnouncesItselfAsCounting(): void
    {
        self::assertInstanceOf(CountsPatternMisses::class, $this->source('leboncoin'));
    }

    /**
     * THE DENOMINATOR COUNTS CARDS, NOT SEGMENTS — and this test exists because the SABOTAGE LEDGER
     * proved the rest of this class could not see it.
     *
     * Every other test here uses leboncoin, which configures **no `card_separator`**: one message is
     * one segment is one card, so `resolve($card !== null)` and `resolve(true)` are literally
     * indistinguishable on that source. Mutating the staging left the whole suite green. A guarantee
     * that only one fixture can exercise is dead safety code on every other one.
     *
     * ParuVendu is the source that can see it, and the margin is not subtle: **2 messages split into
     * 8 segments and yield 3 cards.** The 5 others are the header and the tail carrying the previous
     * card's CTA link — the segment that re-yielded the last card six times per doctor run on
     * 2026-08-29. Counting them would put the ratio at 3/8 instead of 3/3, and since the WARN only
     * ever fires at 100 %, a pattern that had genuinely stopped matching every real card would report
     * short of it and say nothing. That is the silence this signal exists to end.
     */
    public function testFurnitureSegmentsAreExcludedFromTheDenominator(): void
    {
        $source = $this->source('paruvendu');
        $cards = $source->fetch();

        self::assertCount(3, $cards, 'ground truth: the frozen ParuVendu captures carry three vehicles');

        foreach ($source->patternMisses()->counts() as $key => $c) {
            self::assertSame(
                3,
                $c['calls'],
                $key . ' counted segments rather than cards — the 5 furniture segments of these 2 '
                    . 'messages dilute the ratio, and the WARN only fires at 100 %',
            );
        }
    }

    /** @param array<string, string> $paramOverrides */
    private function source(string $name, array $paramOverrides = []): VehicleEmailSource
    {
        return $this->sourceAndStore($name, $paramOverrides)[0];
    }

    /**
     * A HEALTHY BASELINE, because the WARN decoration upgrades from `OK` and from nothing else.
     *
     * A fresh store answers `NEVER_RUN`, and the decoration deliberately preserves any status that
     * is not `OK` so a source already `BROKEN`, `STALE` or `FEED_SILENT` keeps its more specific
     * verdict rather than being downgraded to a layout complaint. That is the rent behaviour copied
     * verbatim, and it means this signal only ever speaks about a source that is otherwise fine —
     * which is exactly the state F27's victims were in. Recorded here rather than mocked so the
     * test exercises the real `RunStore` path `doctor` takes.
     */
    private function recordHealthyRuns(VehicleStore $store, string $name): void
    {
        foreach (['2026-08-30T09:00:00+00:00', '2026-08-31T09:00:00+00:00', '2026-09-01T09:00:00+00:00'] as $at) {
            $store->runs()->recordRun($name, 5, true, null, $at, 20);
        }
    }

    /**
     * @param array<string, string> $paramOverrides
     *
     * @return array{0: VehicleEmailSource, 1: VehicleStore}
     */
    private function sourceAndStore(string $name, array $paramOverrides = []): array
    {
        $d = VehicleSourceLoader::load(self::ROOT . '/config/car/sources.json')[$name];

        if ($paramOverrides !== []) {
            $d = new VehicleSourceDefinition(
                name: $d->name,
                family: $d->family,
                type: $d->type,
                enabled: $d->enabled,
                url: $d->url,
                params: [...$d->params, ...$paramOverrides],
                map: $d->map,
                feedSilentDays: $d->feedSilentDays,
            );
        }

        $store = VehicleStore::open(':memory:');

        return [
            new VehicleEmailSource($d, new FileMailbox(self::ROOT . '/tests/fixtures/car/' . $name)),
            $store,
        ];
    }

    /**
     * EVERY `*_pattern` KEY A CAR ADAPTER ACCEPTS IS ALSO COMPILE-CHECKED (C2 round 3, 2026-09-04).
     *
     * The rent side gained this anchor in round 2, and the docblock justifying it claimed the car
     * side ALREADY had the property. **It did not, and a review panel proved it**: `READ_PARAMS` —
     * the car allow-list — is read by no test at all, and the existing reflection guard in this file
     * is anchored to the four COUNTED keys, with `subject_pattern` and `make_model_unknown_pattern`
     * explicitly subtracted and `seller_pattern`/`postcode_pattern` subtracted as `UNREAD_PARAMS`.
     * Four of eight, not eight. A ninth `*_pattern` key added to `READ_PARAMS` and forgotten in
     * `PATTERN_PARAMS` loaded uncompilable and silent with 382 tests green.
     *
     * Two independent lists, neither derived from the other: dropping a key from either side fails
     * here. `matchParam()` reads these with `@preg_match`, so one that does not compile never
     * matches, never warns and never throws.
     *
     * A claim that a cure exists is not a cure. This is the cure.
     */
    public function testEveryPatternKeyTheCarAdaptersAcceptIsAlsoCompileChecked(): void
    {
        $r = new \ReflectionClass(VehicleSourceLoader::class);
        /** @var list<string> $checked */
        $checked = $r->getConstant('PATTERN_PARAMS');
        /** @var array<string,list<string>> $accepted */
        $accepted = $r->getConstant('READ_PARAMS');

        $shouldBeChecked = [];
        foreach ($accepted as $keys) {
            foreach ($keys as $key) {
                if (str_ends_with($key, '_pattern')) {
                    $shouldBeChecked[$key] = true;
                }
            }
        }

        // `seller_pattern` and `postcode_pattern` are compile-checked while no adapter reads them —
        // `UNREAD_PARAMS` keeps them deliberately, so the check is already there the day one is
        // wired up. That is a superset, not a gap, so the assertion is one-directional: everything
        // ACCEPTED must be CHECKED, and checking more than that is the safe direction.
        $missing = array_values(array_diff(array_keys($shouldBeChecked), $checked));
        sort($missing);

        self::assertSame(
            [],
            $missing,
            'a car params key ends in _pattern, is accepted by an adapter, and is never compiled: '
                . 'it would match nothing, silently, on every message',
        );
    }
}
