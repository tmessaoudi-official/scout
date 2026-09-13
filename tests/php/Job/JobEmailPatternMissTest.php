<?php

declare(strict_types=1);

namespace Scout\Tests\Job;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Adapters\Mail\FileMailbox;
use Scout\Core\CountsPatternMisses;
use Scout\Core\SourceStatus;
use Scout\Job\JobEmailSource;
use Scout\Job\JobSourceDefinition;
use Scout\Job\JobSourceLoader;
use Scout\Job\JobStore;

/**
 * EVERY EXTRACTION RULE THE JOB ADAPTER READS IS COUNTED, by set membership rather than by memory.
 *
 * The set is read off `JobSourceLoader::READ_PARAMS` by reflection. `from` scopes the message and
 * `pay_pattern` is excluded by name (7 of 115 real cards state pay, so counting it would warn on
 * every pass). Everything else must reach `PatternMissLog`, or a rule added tomorrow goes dark in
 * production exactly as F27 did on the car side.
 */
#[CoversClass(JobEmailSource::class)]
final class JobEmailPatternMissTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../..';
    private const string NOW = '2026-09-13T12:00:00+00:00';

    /** @return list<string> */
    private static function countedParams(): array
    {
        /** @var array<string, list<string>> $read */
        $read = (new \ReflectionClass(JobSourceLoader::class))->getConstant('READ_PARAMS');

        return array_values(array_diff($read['email_alert'], ['from', 'pay_pattern']));
    }

    public function testEveryReadParamExceptTheScopeAndPayIsCounted(): void
    {
        $source = $this->source();
        $source->fetch();

        $counted = array_values(array_intersect(array_keys($source->patternMisses()->counts()), self::countedParams()));
        sort($counted);
        $expected = self::countedParams();
        sort($expected);

        self::assertSame($expected, $counted, 'a read param that no call site records goes dark in production');
    }

    public function testPayIsDeliberatelyNotCounted(): void
    {
        $source = $this->source();
        $source->fetch();

        self::assertArrayNotHasKey('pay_pattern', $source->patternMisses()->counts());
    }

    /** Only cards that state a parenthesis enter the mode denominator: 15 of the 16 real cards. */
    public function testTheModeDenominatorCountsOnlyParenthesisedCards(): void
    {
        $source = $this->source();
        $source->fetch();

        self::assertSame(['calls' => 15, 'misses' => 0], $source->patternMisses()->counts()['mode_word']);
        self::assertSame(['calls' => 16, 'misses' => 0], $source->patternMisses()->counts()['place_pattern']);
        self::assertSame(['calls' => 3, 'misses' => 0], $source->patternMisses()->counts()['card_link_pattern']);
        self::assertSame(['calls' => 3, 'misses' => 0], $source->patternMisses()->counts()['footer_marker']);
    }

    public function testADeadPlacePatternWarnsThroughHealth(): void
    {
        $store = JobStore::open(':memory:');
        foreach (['2026-09-10T09:00:00+00:00', '2026-09-11T09:00:00+00:00', '2026-09-12T09:00:00+00:00'] as $at) {
            $store->runs()->recordRun('linkedin', 5, true, null, $at, 20);
        }
        $source = $this->source(['place_pattern' => '~^(?<company>RIEN) · (?<location>ICI)$~u'], $store);
        $source->fetch();

        self::assertSame(['place_pattern'], $source->patternMisses()->total());
        $health = $source->health(self::NOW);
        self::assertSame(SourceStatus::WARN_DROP, $health->status);
        self::assertStringContainsString('place_pattern', $health->detail);
    }

    public function testTheSourceAnnouncesItselfAsCounting(): void
    {
        self::assertInstanceOf(CountsPatternMisses::class, $this->source());
    }

    /** Every accepted `*_pattern` key is compile-checked: two lists, neither derived from the other. */
    public function testEveryAcceptedPatternKeyIsCompileChecked(): void
    {
        $r = new \ReflectionClass(JobSourceLoader::class);
        /** @var list<string> $checked */
        $checked = $r->getConstant('PATTERN_PARAMS');
        /** @var array<string, list<string>> $accepted */
        $accepted = $r->getConstant('READ_PARAMS');

        $missing = [];
        foreach ($accepted as $keys) {
            foreach ($keys as $key) {
                if (str_ends_with($key, '_pattern') && !in_array($key, $checked, true)) {
                    $missing[] = $key;
                }
            }
        }

        self::assertSame([], $missing);
    }

    /** @param array<string, string> $overrides */
    private function source(array $overrides = [], ?JobStore $store = null): JobEmailSource
    {
        $d = JobSourceLoader::load(self::ROOT . '/config/job/sources.json')['linkedin'];
        if ($overrides !== []) {
            $d = new JobSourceDefinition(
                name: $d->name,
                enabled: $d->enabled,
                family: $d->family,
                type: $d->type,
                params: [...$d->params, ...$overrides],
                feedSilentDays: $d->feedSilentDays,
            );
        }

        return new JobEmailSource($d, $store ?? JobStore::open(':memory:'), new FileMailbox(self::ROOT . '/tests/fixtures/job/linkedin'));
    }
}
