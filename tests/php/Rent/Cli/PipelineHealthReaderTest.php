<?php

declare(strict_types=1);

namespace Scout\Tests\Rent\Cli;

use PHPUnit\Framework\TestCase;
use Scout\Adapters\FeedFreshness;
use Scout\Core\Notify\Notification;
use Scout\Core\Notify\NotificationKind;
use Scout\Core\Notify\Notifier;
use Scout\Core\PatternMissLog;
use Scout\Core\SourceStatus;
use Scout\Rent\Adapters\Source;
use Scout\Rent\Cli\Pipeline;
use Scout\Rent\Config\ConfigLoader;
use Scout\Rent\Core\RawListing;
use Scout\Rent\Core\SourceProfile;
use Scout\Rent\Core\Tenure;
use Scout\Rent\Store\Store;
use Scout\Tests\Support\DeliveringChannel;

/**
 * THE PRODUCTION DEFAULT READER, WITH NOTHING SCRIPTED (step 8e-2).
 *
 * Every other pipeline health test hands the pipeline a reader or a scripted verdict, so none of them
 * proves what `Pipeline` does when it is built the way `RentScout` builds it: no `healthReader`, so
 * the default `SourceHealthService` on the store's run log. Two facts must reach that default from
 * the SOURCE — its own pattern-miss log and its own `feed_silent_days` — and before 8e-2 each source
 * carried them into its own `health()`. Now the port carries them and the service reads them, and a
 * default that read the run log alone would drop both with every routing test still green.
 *
 * Each test has its counterweight: the same pass with the source's fact removed sends no alert, so a
 * reader that alerted on everything cannot pass either.
 */
final class PipelineHealthReaderTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../../..';
    private const string NOW = '2026-08-29T09:00:00+00:00';

    public function testABlindPatternInTheSourcesOwnLogReachesTheDefaultReader(): void
    {
        self::assertSame([SourceStatus::WARN_DROP], $this->alertsAfterOnePass(new CountingSource(found: false)));
    }

    public function testCounterweightAnAllMatchingLogSendsNoAlert(): void
    {
        self::assertSame([], $this->alertsAfterOnePass(new CountingSource(found: true)));
    }

    /** One day of silence exceeds THIS source's threshold of 1, while the store's global 3 says not yet. */
    public function testTheSourcesOwnFeedSilenceThresholdReachesTheDefaultReader(): void
    {
        $source = new CountingSource(found: true, newest: '2026-08-27T09:00:00Z', silentDays: 1);

        self::assertSame([SourceStatus::FEED_SILENT], $this->alertsAfterOnePass($source));
    }

    public function testCounterweightWithoutItsOwnThresholdTheGlobalOneHolds(): void
    {
        $source = new CountingSource(found: true, newest: '2026-08-27T09:00:00Z', silentDays: null);

        self::assertSame([], $this->alertsAfterOnePass($source));
    }

    /** @return list<SourceStatus> the status every SOURCE_HEALTH alert of the pass names, in order */
    private function alertsAfterOnePass(CountingSource $source): array
    {
        $store = Store::open(':memory:', 3);
        $channel = new DeliveringChannel();
        // Built as `RentScout` builds it: NO health reader, so the production default runs.
        $pipeline = new Pipeline(
            ConfigLoader::loadCriteria(self::ROOT . '/tests/fixtures/rent/criteria/pipeline.json'),
            $store,
            new Notifier([$channel]),
        );

        $pipeline->runOnce([$source], self::NOW);

        $alerts = array_values(array_filter(
            $channel->sent,
            static fn (Notification $n): bool => $n->kind === NotificationKind::SOURCE_HEALTH,
        ));

        // The formatter titles an alert "Source <name> : <status value>"; read the value back exactly.
        return array_map(
            static fn (Notification $n): SourceStatus => SourceStatus::from(substr($n->title, (int) strrpos($n->title, ' : ') + 3)),
            $alerts,
        );
    }
}

/**
 * A source that counts into its OWN log as a real adapter does — three cards, one pattern, every
 * call a hit or every call a miss — and dates its feed when told to.
 */
final class CountingSource implements Source, FeedFreshness
{
    private readonly PatternMissLog $misses;

    public function __construct(
        private readonly bool $found,
        private readonly ?string $newest = null,
        private readonly ?int $silentDays = null,
    ) {
        $this->misses = new PatternMissLog();
    }

    public function name(): string
    {
        return 'counting';
    }

    public function family(): string
    {
        return 'private';
    }

    public function host(): ?string
    {
        return null;
    }

    public function defaultTenure(): ?Tenure
    {
        return null;
    }

    public function profile(): SourceProfile
    {
        return new SourceProfile('counting', 'private', null, false);
    }

    public function fetch(): array
    {
        $listings = [];
        foreach (['c1', 'c2', 'c3'] as $id) {
            $this->misses->record('facts_pattern', $this->found);
            $listings[] = new RawListing(sourceName: 'counting', externalId: $id, title: 'Studio', url: 'https://example.test/' . $id);
        }

        return $listings;
    }

    public function newestFeedItemAt(): ?string
    {
        return $this->newest;
    }

    public function patternMisses(): PatternMissLog
    {
        return $this->misses;
    }

    public function feedSilentDays(): ?int
    {
        return $this->silentDays;
    }
}
