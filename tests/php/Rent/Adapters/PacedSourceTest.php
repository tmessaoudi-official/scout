<?php

declare(strict_types=1);

namespace Scout\Tests\Rent\Adapters;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Adapters\FeedFreshness;
use Scout\Rent\Adapters\PacedSource;
use Scout\Rent\Adapters\Source;
use Scout\Adapters\SourceError;
use Scout\Core\Pacer;
use Scout\Core\PatternMissLog;
use Scout\Rent\Core\SourceProfile;
use Scout\Rent\Core\Tenure;

/**
 * `PacedSource` is the seam that puts Q37 between the run loop and the network without teaching the
 * pipeline about time.
 *
 * The decorator shape was chosen over injecting the pacer into `Pipeline` because `Pipeline` also
 * serves `--once`, where there is no cadence at all — a timing-aware pipeline would need a "do not
 * actually pace" mode, and a safety control with an off switch is the kind that stays off.
 *
 * These tests assert what is OBSERVABLE — where the sleep lands relative to the request — rather
 * than spying on calls into `Pacer`. That is deliberate: a call-count test passes just as happily
 * when the pacer is invoked with the wrong host, or after the request instead of before it. The
 * sleeps and their ordering are the behaviour the ruling is actually about.
 *
 * Two properties matter more than the delegation, and both are about failure:
 *
 * 1. The wait happens BEFORE the request, never after. A decorator that slept afterwards would fire
 *    its first two requests back to back and only then start behaving.
 * 2. A fetch that THROWS still consumes the host's window. The packet left the machine; the host
 *    saw it. Not charging for it lets a failing source be retried inside its own window — hammering
 *    a site at the exact moment it is most likely to be rate-limiting on purpose.
 */
#[CoversClass(PacedSource::class)]
final class PacedSourceTest extends TestCase
{
    public function testTheWaitLandsBeforeTheSecondRequestNotAfterTheFirst(): void
    {
        $log = new CallLog();
        $paced = new PacedSource(new SpySource('a', 'example.test', $log), self::pacer($log));

        $paced->fetch();
        $paced->fetch();

        self::assertSame(['fetch:a', 'sleep:60', 'fetch:a'], $log->entries);
    }

    /**
     * The host reaches the pacer intact. Proven by the CONSEQUENCE rather than by a spy: two
     * distinct hosts are separated by 5 s and the same host by 60 s, so the interval itself reports
     * which host the pacer believed it was contacting.
     */
    public function testTheInnerSourcesHostIsWhatThePacerSees(): void
    {
        $log = new CallLog();
        $pacer = self::pacer($log);
        $a = new PacedSource(new SpySource('a', 'alpha.test', $log), $pacer);
        $b = new PacedSource(new SpySource('b', 'beta.test', $log), $pacer);

        $a->fetch();
        $b->fetch();

        self::assertSame(['fetch:a', 'sleep:5', 'fetch:b'], $log->entries);
    }

    public function testTheSameHostBehindTwoDifferentSourcesSharesOneWindow(): void
    {
        // The whole reason Q37 is worded in hosts and not in sources. Two adapters on one landlord's
        // domain must not each get a private 60 s window — that is the polling burst that gets an IP
        // banned, and `CLAUDE.md` hard rule 5 leaves polite pacing as the entire defence.
        $log = new CallLog();
        $pacer = self::pacer($log);
        $one = new PacedSource(new SpySource('one', 'shared.test', $log), $pacer);
        $two = new PacedSource(new SpySource('two', 'shared.test', $log), $pacer);

        $one->fetch();
        $two->fetch();

        self::assertSame(['fetch:one', 'sleep:60', 'fetch:two'], $log->entries);
    }

    public function testAHostlessSourceIsNeverDelayed(): void
    {
        // The decorator does not decide what "no host" means — `Pacer` owns that rule, in one place.
        // A decorator that skipped the pacer itself would duplicate the policy and let the two drift.
        $log = new CallLog();
        $pacer = self::pacer($log);
        $m = new PacedSource(new SpySource('mail', null, $log), $pacer);

        $m->fetch();
        $m->fetch();

        self::assertSame(['fetch:mail', 'fetch:mail'], $log->entries);
    }

    public function testAHostlessSourceDoesNotConsumeTheDistinctHostSlot(): void
    {
        $log = new CallLog();
        $pacer = self::pacer($log);
        $web = new PacedSource(new SpySource('web', 'alpha.test', $log), $pacer);
        $mail = new PacedSource(new SpySource('mail', null, $log), $pacer);
        $web2 = new PacedSource(new SpySource('web2', 'beta.test', $log), $pacer);

        $web->fetch();
        $mail->fetch();
        $web2->fetch();

        // Still exactly one 5 s gap: the mailbox neither waited nor pushed beta.test further out.
        self::assertSame(['fetch:web', 'fetch:mail', 'sleep:5', 'fetch:web2'], $log->entries);
    }

    /**
     * Hard rule 3 in decorator form: the wrapper must not convert a `SourceError` into an empty
     * list. A decorator is exactly where that regression hides, because it reads as defensive
     * programming rather than as the silent-failure bug it is.
     */
    public function testAFetchFailurePropagatesAndIsNotSwallowed(): void
    {
        $log = new CallLog();
        $paced = new PacedSource(
            new SpySource('boom', 'example.test', $log, new SourceError('boom', 'HTTP 503')),
            self::pacer($log),
        );

        $this->expectException(SourceError::class);
        $paced->fetch();
    }

    public function testAFailedRequestStillConsumesTheHostWindow(): void
    {
        $log = new CallLog();
        $paced = new PacedSource(
            new SpySource('boom', 'example.test', $log, new SourceError('boom', 'HTTP 503')),
            self::pacer($log),
        );

        foreach ([1, 2] as $ignored) {
            try {
                $paced->fetch();
            } catch (SourceError) {
                // deliberately retried — that is the behaviour under test
            }
        }

        self::assertSame(['fetch:boom', 'sleep:60', 'fetch:boom'], $log->entries);
    }

    public function testEveryOtherContractMethodDelegatesUntouched(): void
    {
        $log = new CallLog();
        $inner = new SpySource('deleg', 'example.test', $log);
        $paced = new PacedSource($inner, self::pacer($log));

        self::assertSame('deleg', $paced->name());
        self::assertSame('private', $paced->family());
        self::assertSame(Tenure::LLI, $paced->defaultTenure());
        self::assertSame('example.test', $paced->host());
        self::assertSame('deleg', $paced->profile()->name);
    }

    /**
     * What the health verdict needs must survive the wrapper (step 8e-2). The clock this decorator
     * used to forward is now the SERVICE's argument and never passes through a source; what still
     * passes through is the inner source's OWN pattern-miss log and its OWN `feed_silent_days`. A
     * fresh log here would hide a blind pattern behind every paced source, and a dropped threshold
     * would switch `FEED_SILENT` off for it, both with `health` reading `ok`.
     */
    public function testTheWrapperForwardsTheInnerMissLogAndSilenceThreshold(): void
    {
        $log = new CallLog();
        $inner = new SpySource('fwd', 'example.test', $log, silentDays: 9);
        $paced = new PacedSource($inner, self::pacer($log));

        self::assertSame($inner->misses, $paced->patternMisses(), 'the inner log itself, not a copy or a fresh one');
        self::assertSame(9, $paced->feedSilentDays());
    }

    /**
     * Feed freshness must survive the wrapper, and NOTHING tested that until the ledger said so.
     *
     * **Found by `tests/sabotage-check.sh`, not by review:** replacing the forwarding with
     * `return null;` left the whole suite green. That is the worst possible shape for this
     * particular capability — `wrapAll()` wraps every source, and `--watch` is the ONLY mode in
     * which a feed can go silent unnoticed for days, so a decorator that silently drops it makes
     * the detection unreachable in exactly the mode it was built for while every unit test on the
     * inner source keeps passing.
     */
    public function testFeedFreshnessSurvivesTheWrapper(): void
    {
        $inner = new SpyFreshSource('leboncoin', '2026-08-26T07:33:06Z');
        $paced = new PacedSource($inner, self::pacer(new CallLog()));

        self::assertSame('2026-08-26T07:33:06Z', $paced->newestFeedItemAt());
    }

    /**
     * A source that reports no freshness answers `null`, not an error.
     *
     * The counterweight, and it is what stops the guarantee above being satisfied by making
     * `FeedFreshness` mandatory on `Source` — a contract every implementation has to bypass is not
     * a contract, which is `Source`'s own docblock. `null` yields no verdict rather than a false one.
     */
    public function testASourceWithoutFreshnessIsNotAnError(): void
    {
        $log = new CallLog();
        $paced = new PacedSource(new SpySource('inli', 'a.test', $log), self::pacer($log));

        self::assertNull($paced->newestFeedItemAt());
    }

    public function testWrapAllGivesEverySourceTheSamePacerRatherThanAPrivateWindow(): void
    {
        // `wrapAll` is documented as the ONLY intended way to build these, and it was the one thing
        // here with no test at all — found by a sabotage run, not by reading the code. Handing each
        // source its own pacer is the plausible mistake (a `clone`, a pacer built inside the map),
        // and it is invisible: every source still paces itself perfectly, while the machine as a
        // whole fires one request per source with no gap between them. That is the polling burst
        // Q37 exists to prevent, and no single-source test can see it.
        $log = new CallLog();
        $wrapped = PacedSource::wrapAll(
            [new SpySource('one', 'shared.test', $log), new SpySource('two', 'shared.test', $log)],
            self::pacer($log),
        );

        foreach ($wrapped as $source) {
            $source->fetch();
        }

        // One shared window ⇒ the second request waits 60 s. Two private ones ⇒ no sleep at all.
        self::assertContains('sleep:60', $log->entries);
        self::assertCount(3, $log->entries);
    }

    public function testWrapAllShufflesTheOrderAndLosesNoSource(): void
    {
        // Q37 shuffles the order each pass so a site cannot learn its slot in a fixed rotation. The
        // failure mode of a shuffle is not "badly shuffled" — it is "quietly dropped a source",
        // which reads as a market that went quiet on exactly one landlord.
        $log = new CallLog();
        $names = ['a', 'b', 'c', 'd'];
        $sources = array_map(static fn (string $n): Source => new SpySource($n, $n . '.test', $log), $names);

        // The helper's rand returns its LOWER bound, which drives Fisher-Yates to a deterministic
        // rotation rather than the identity — so "did not shuffle" and "shuffled" are
        // distinguishable. Returning the upper bound would swap every element with itself and this
        // test would then assert nothing; it did exactly that on its first run.
        $wrapped = PacedSource::wrapAll($sources, self::pacer($log));

        $got = array_map(static fn (Source $s): string => $s->name(), $wrapped);
        self::assertSame($names, self::sorted($got), 'a source was dropped or duplicated');
        self::assertNotSame($names, $got, 'the order was not shuffled at all');
    }

    /** @param list<string> $names @return list<string> */
    private static function sorted(array $names): array
    {
        sort($names);

        return $names;
    }

    /** A pacer whose sleeps are recorded into the same log the fetches write to, so order is visible. */
    private static function pacer(CallLog $log, ?\Closure $rand = null): Pacer
    {
        $now = 0.0;

        return new Pacer(
            clock: static function () use (&$now): float {
                return $now;
            },
            sleeper: static function (float $seconds) use ($log, &$now): void {
                $log->entries[] = 'sleep:' . (int) round($seconds);
                $now += $seconds;
            },
            rand: $rand ?? static fn (int $min, int $max): int => $min,
        );
    }
}

/** Shared by reference through object identity — PHP has no reference-typed promoted properties. */
final class CallLog
{
    /** @var list<string> */
    public array $entries = [];
}

/** Records WHEN it was fetched, because "before or after the wait" is the property under test. */
final class SpySource implements Source
{
    public readonly PatternMissLog $misses;

    public function __construct(
        private readonly string $sourceName,
        private readonly ?string $sourceHost,
        private readonly CallLog $log,
        private readonly ?\Throwable $throw = null,
        private readonly ?int $silentDays = null,
    ) {
        $this->misses = new PatternMissLog();
    }

    public function name(): string
    {
        return $this->sourceName;
    }

    public function host(): ?string
    {
        return $this->sourceHost;
    }

    public function family(): string
    {
        return 'private';
    }

    public function defaultTenure(): ?Tenure
    {
        return Tenure::LLI;
    }

    public function profile(): SourceProfile
    {
        return new SourceProfile($this->sourceName, 'private', Tenure::LLI, false);
    }

    public function fetch(): array
    {
        $this->log->entries[] = 'fetch:' . $this->sourceName;

        if ($this->throw !== null) {
            throw $this->throw;
        }

        return [];
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

/** A source that DOES report feed freshness, so the decorator's forwarding is observable. */
final class SpyFreshSource implements FeedFreshness, Source
{
    public function __construct(
        private readonly string $sourceName,
        private readonly ?string $newest,
    ) {}

    public function newestFeedItemAt(): ?string
    {
        return $this->newest;
    }

    public function name(): string
    {
        return $this->sourceName;
    }

    public function host(): ?string
    {
        return null;
    }

    public function family(): string
    {
        return 'private';
    }

    public function defaultTenure(): ?Tenure
    {
        return null;
    }

    public function profile(): SourceProfile
    {
        return new SourceProfile($this->sourceName, 'private', null, false);
    }

    public function fetch(): array
    {
        return [];
    }

    public function patternMisses(): PatternMissLog
    {
        return new PatternMissLog();
    }

    public function feedSilentDays(): ?int
    {
        return null;
    }
}
