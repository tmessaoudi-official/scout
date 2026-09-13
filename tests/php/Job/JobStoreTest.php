<?php

declare(strict_types=1);

namespace Scout\Tests\Job;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Core\SourceStatus;
use Scout\Core\Whitespace;
use Scout\Job\JobListing;
use Scout\Job\JobOutcome;
use Scout\Job\JobStore;
use Scout\Job\JobVerdict;

/**
 * The job store's contract, in the rent store's categories where they apply: seen-set, announced
 * kind, identity, order, evidence, persistence, concurrency, and the composed run log. A behaviour
 * with no category here is one nobody decided to guarantee.
 */
#[CoversClass(JobStore::class)]
#[CoversClass(Whitespace::class)]
final class JobStoreTest extends TestCase
{
    private string $dir;
    private string $path;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/scout-job-' . bin2hex(random_bytes(6));
        // A directory that does not exist yet: a fresh `state/` is the first-deployment shape.
        $this->path = $this->dir . '/state/job-watch.sqlite3';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->path . '*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir . '/state');
        @rmdir($this->dir);
    }

    // ── seen-set ──────────────────────────────────────────────────────────────────────────────

    public function testAnOfferIsNewExactlyOnceAndNotifiedIsADifferentFact(): void
    {
        $store = JobStore::open($this->path);

        $first = $store->record($this->offer('4301'), '2026-09-13T10:00:00Z');
        $second = $store->record($this->offer('4301'), '2026-09-13T10:15:00Z');

        self::assertTrue($first->isNew);
        self::assertFalse($second->isNew);
        self::assertTrue($second->isCurrent);
        self::assertFalse($store->wasNotified($first->dedupKey), 'seen is not notified');
        $store->markNotified($first->dedupKey, '2026-09-13T10:15:01Z', JobStore::AS_MATCH);
        self::assertTrue($store->wasNotified($first->dedupKey));
    }

    public function testTheSeenSetIsEmptyOnlyBeforeAnythingWasRecorded(): void
    {
        $store = JobStore::open($this->path);
        self::assertTrue($store->isSeenSetEmpty(), 'Q36: a fresh file, or a missing mount wearing one\'s face');
        $store->record($this->offer('4301'), '2026-09-13T10:00:00Z');
        self::assertFalse($store->isSeenSetEmpty());
    }

    public function testTheSeenSetSurvivesReopening(): void
    {
        JobStore::open($this->path)->record($this->offer('4301'), '2026-09-13T10:00:00Z');

        self::assertFalse(JobStore::open($this->path)->record($this->offer('4301'), '2026-09-13T11:00:00Z')->isNew);
    }

    // ── announced kind: ROLLUP < MATCH, and a push is never demoted ──────────────────────────

    public function testARollupIsNotAPushAndAPushIsNeverDemoted(): void
    {
        $store = JobStore::open($this->path);
        $key = $store->record($this->offer('4301'), '2026-09-13T10:00:00Z')->dedupKey;

        self::assertFalse($store->wasNotifiedAs($key, JobStore::AS_ROLLUP), 'never announced is not announced as anything');

        $store->markNotified($key, '2026-09-13T10:00:01Z', JobStore::AS_ROLLUP);
        self::assertTrue($store->wasNotifiedAs($key, JobStore::AS_ROLLUP));
        self::assertFalse($store->wasNotifiedAs($key, JobStore::AS_MATCH), 'a rolled-up offer can still be promoted to a push');

        $store->markNotified($key, '2026-09-14T08:00:00Z', JobStore::AS_MATCH);
        self::assertTrue($store->wasNotifiedAs($key, JobStore::AS_MATCH));

        $store->markNotified($key, '2026-09-15T08:00:00Z', JobStore::AS_ROLLUP);
        self::assertTrue($store->wasNotifiedAs($key, JobStore::AS_MATCH), 'a later rollup write must not demote a push');
    }

    // ── the rollup queue: judged matches nobody was told about ────────────────────────────────

    public function testTheRollupQueueIsJudgedMatchesNobodyWasToldLeastRecentlySeenFirst(): void
    {
        $store = JobStore::open($this->path);
        $old = $store->record($this->offer('1'), '2026-09-13T08:00:00Z')->dedupKey;
        $new = $store->record($this->offer('2', company: 'Aneo'), '2026-09-13T09:00:00Z')->dedupKey;
        $rejected = $store->record($this->offer('3'), '2026-09-13T07:00:00Z')->dedupKey;
        $store->recordVerdict($new, JobVerdict::matched(31, []), $this->offer('2', company: 'Aneo'));
        $store->recordVerdict($old, JobVerdict::matched(44, []), $this->offer('1'));
        $store->recordVerdict($rejected, JobVerdict::rejected(['intitulé exclu : data']), $this->offer('3'));

        $queue = $store->pendingRollup();
        self::assertSame([$old, $new], array_column($queue, 'dedup_key'), 'MATCH only, least recently seen first');
        self::assertSame(['linkedin', '1', 'Lead Developer', 'Acme', 44], [$queue[0]['source'], $queue[0]['external_id'], $queue[0]['title'], $queue[0]['company'], (int) $queue[0]['score']]);
        self::assertNotNull($queue[0]['snapshot_json']);
        self::assertSame(2, $store->pendingRollupCount());
        self::assertSame([$old], array_column($store->pendingRollup(1), 'dedup_key'), 'the batch is capped; the count is not');
        self::assertSame(['count' => 3, 'notified' => 0, 'matches' => 2], $store->counts());

        $store->markNotified($old, '2026-09-13T10:00:00Z', JobStore::AS_ROLLUP);
        $store->markNotified($new, '2026-09-13T10:00:00Z', JobStore::AS_MATCH);

        self::assertSame([], $store->pendingRollup(), 'announced either way, an offer leaves the queue');
        self::assertSame(0, $store->pendingRollupCount());
        self::assertSame(['count' => 3, 'notified' => 2, 'matches' => 2], $store->counts());
    }

    public function testAnUnknownKindIsRefusedAndNothingIsWritten(): void
    {
        $store = JobStore::open($this->path);
        $key = $store->record($this->offer('4301'), '2026-09-13T10:00:00Z')->dedupKey;

        try {
            $store->markNotified($key, '2026-09-13T10:00:01Z', 'DIGEST');
            self::fail('an announcement kind this domain does not have was accepted');
        } catch (\InvalidArgumentException) {
        }

        self::assertFalse($store->wasNotified($key));
    }

    public function testMarkingAnUnknownOfferIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        JobStore::open($this->path)->markNotified('linkedin:id:nope', '2026-09-13T10:00:00Z', JobStore::AS_MATCH);
    }

    // ── identity ──────────────────────────────────────────────────────────────────────────────

    public function testIdentityIsScopedToTheSource(): void
    {
        $store = JobStore::open($this->path);
        $store->record($this->offer('4301', source: 'linkedin'), '2026-09-13T10:00:00Z');

        self::assertTrue($store->record($this->offer('4301', source: 'wttj'), '2026-09-13T10:00:00Z')->isNew, 'the same id on another source is another offer');
    }

    /**
     * Two offers whose id is only whitespace must not collapse onto one key — the second would never
     * be announced. The Latin-1 bytes (`\xA0`, `\x85`, `\xAD`) are not valid UTF-8, so the byte
     * fallback is what has to strip them.
     */
    public function testAnIdOfOnlyUnicodeWhitespaceIsRefused(): void
    {
        $store = JobStore::open($this->path);

        foreach (["\u{00A0}", "\u{2007}", "\u{202F}", "\u{3000}", "\u{200B}", "\u{FEFF}", " \t ", "\xA0", "\x85", "\xAD"] as $blank) {
            try {
                $store->record($this->offer($blank), '2026-09-13T10:00:00Z');
                self::fail(sprintf('a blank id was recorded (%s)', bin2hex($blank)));
            } catch (\InvalidArgumentException) {
            }
        }

        self::assertTrue($store->isSeenSetEmpty(), 'a refusal writes nothing');
    }

    public function testAPaddedIdIsTheSameId(): void
    {
        $store = JobStore::open($this->path);
        $first = $store->record($this->offer('4301'), '2026-09-13T10:00:00Z');
        $padded = $store->record($this->offer("\u{00A0}4301 "), '2026-09-13T10:15:00Z');

        self::assertSame($first->dedupKey, $padded->dedupKey);
        self::assertFalse($padded->isNew, 'otherwise the offer looks new on every pass');
    }

    // ── order ─────────────────────────────────────────────────────────────────────────────────

    public function testAStaleSightingOverwritesNothing(): void
    {
        $store = JobStore::open($this->path);
        $store->record($this->offer('4301', title: 'Lead Developer', company: 'Acme'), '2026-09-13T13:00:00Z');

        $stale = $store->record($this->offer('4301', title: 'Developer', company: 'Old Co'), '2026-09-12T09:00:00Z');
        self::assertFalse($stale->isCurrent);
        self::assertSame(['Lead Developer', 'Acme'], $this->titleAndCompany());

        // The stored instant did not move back either: a sighting between the two is still stale.
        self::assertFalse($store->record($this->offer('4301'), '2026-09-13T12:00:00Z')->isCurrent);
    }

    public function testAnEmptyTitleOrCompanyDoesNotEraseAKnownOne(): void
    {
        $store = JobStore::open($this->path);
        $store->record($this->offer('4301', title: 'Lead Developer', company: 'Acme'), '2026-09-13T10:00:00Z');
        $store->record($this->offer('4301', title: '', company: ''), '2026-09-13T11:00:00Z');

        self::assertSame(['Lead Developer', 'Acme'], $this->titleAndCompany());
    }

    public function testACurrentSightingUpdatesTheTitle(): void
    {
        $store = JobStore::open($this->path);
        $store->record($this->offer('4301', title: 'Developer', company: 'Acme'), '2026-09-13T10:00:00Z');
        $store->record($this->offer('4301', title: 'Lead Developer', company: 'Acme SAS'), '2026-09-13T11:00:00Z');

        self::assertSame(['Lead Developer', 'Acme SAS'], $this->titleAndCompany());
    }

    public function testAnUnreadableInstantIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        JobStore::open($this->path)->record($this->offer('4301'), '2026-02-30T10:00:00Z');
    }

    // ── evidence ──────────────────────────────────────────────────────────────────────────────

    /** Hard rule 9 survives the store: an unstated salary, mode or contract comes back unstated, not zero. */
    public function testTheSnapshotRoundTripsUnknownsAsUnknownsAcrossReopening(): void
    {
        $store = JobStore::open($this->path);
        $silent = $this->offer('4301');
        $stated = new JobListing(
            sourceName: 'linkedin', externalId: '4302', title: 'Développeur PHP', company: 'Acme', location: 'Paris',
            fields: ['recrutement_actif' => true], url: 'https://www.linkedin.com/jobs/view/4302/', contracts: ['cdi', 'freelance'],
            workMode: 'onsite', salaryMinEur: 60000, salaryMaxEur: 70000, tjmMaxEur: 550, payText: '60 k€ - 70 k€', observedAt: '2026-09-13T10:00:00Z',
        );
        $k1 = $store->record($silent, '2026-09-13T10:00:00Z')->dedupKey;
        $k2 = $store->record($stated, '2026-09-13T10:00:00Z')->dedupKey;
        $store->recordVerdict($k1, JobVerdict::rejected(['H4 rémunération sous le plancher']), $silent);
        $store->recordVerdict($k2, JobVerdict::matched(72, ['CDI']), $stated);
        unset($store);

        $again = JobStore::open($this->path);
        self::assertEquals($silent, $again->snapshot($k1));
        self::assertNull($again->snapshot($k1)?->salaryMinEur, 'unknown pay is not zero pay');
        self::assertNull($again->snapshot($k1)?->workMode, 'an unstated mode is not on site');
        self::assertSame([], $again->snapshot($k1)?->contracts);
        self::assertEquals($stated, $again->snapshot($k2));
        self::assertSame(JobOutcome::REJECT, $again->outcomeOf($k1));
        self::assertNull($again->snapshotScore($k1), 'a rejection has no score');
        self::assertSame(JobOutcome::MATCH, $again->outcomeOf($k2));
        self::assertSame(72, $again->snapshotScore($k2));
    }

    public function testAnUnjudgedOfferHasNoOutcomeNoScoreAndNoSnapshot(): void
    {
        $store = JobStore::open($this->path);
        $key = $store->record($this->offer('4301'), '2026-09-13T10:00:00Z')->dedupKey;

        self::assertNull($store->outcomeOf($key));
        self::assertNull($store->snapshotScore($key));
        self::assertNull($store->snapshot($key));
        self::assertNull($store->outcomeOf('linkedin:id:never'));
    }

    public function testACorruptSnapshotIsRefusedLoudly(): void
    {
        $store = JobStore::open($this->path);
        $key = $store->record($this->offer('4301'), '2026-09-13T10:00:00Z')->dedupKey;
        $this->raw()->exec("UPDATE job_listings SET snapshot_json = '{\"sourceName\":' ");

        $this->expectException(\RuntimeException::class);
        $store->snapshot($key);
    }

    /** A stored outcome that does not decode is REFUSED, never read as "not judged" — that is the rent `tryFrom` lesson. */
    public function testACorruptOutcomeIsRefusedLoudly(): void
    {
        $store = JobStore::open($this->path);
        $key = $store->record($this->offer('4301'), '2026-09-13T10:00:00Z')->dedupKey;
        $this->raw()->exec("UPDATE job_listings SET outcome = 'DIGEST'");

        $this->expectException(\RuntimeException::class);
        $store->outcomeOf($key);
    }

    public function testAVerdictForAnUnknownOfferIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        JobStore::open($this->path)->recordVerdict('linkedin:id:never', JobVerdict::matched(50, []), $this->offer('never'));
    }

    // ── persistence ───────────────────────────────────────────────────────────────────────────

    public function testADatabaseFromANewerSchemaIsRefused(): void
    {
        JobStore::open($this->path);
        $this->raw()->prepare("UPDATE job_meta SET value = :v WHERE key = 'schema_version'")
            ->execute(['v' => (string) (JobStore::SCHEMA_VERSION + 1)]);

        $this->expectException(\RuntimeException::class);
        JobStore::open($this->path);
    }

    // ── concurrency ───────────────────────────────────────────────────────────────────────────

    /** WAL and `BEGIN IMMEDIATE`: a deferred transaction skips SQLite's busy handler, so the second writer would fail instantly. */
    public function testASecondWriterWaitsRatherThanFailing(): void
    {
        $holder = JobStore::open($this->path);
        $second = JobStore::open($this->path);

        $holderPdo = (new \ReflectionProperty(JobStore::class, 'pdo'))->getValue($holder);
        $secondPdo = (new \ReflectionProperty(JobStore::class, 'pdo'))->getValue($second);
        self::assertInstanceOf(\PDO::class, $holderPdo);
        self::assertInstanceOf(\PDO::class, $secondPdo);

        $secondPdo->exec('PRAGMA busy_timeout = 400');
        $holderPdo->exec('BEGIN IMMEDIATE');

        $startedAt = hrtime(true);

        try {
            $second->record($this->offer('4301'), '2026-09-13T10:00:00Z');
            self::fail('the second writer got the lock while the first was holding it');
        } catch (\PDOException $failure) {
            $waitedMs = (hrtime(true) - $startedAt) / 1_000_000;

            self::assertStringContainsString('locked', $failure->getMessage());
            self::assertGreaterThan(300, $waitedMs, 'the second writer gave up instantly instead of waiting');
        } finally {
            $holderPdo->exec('ROLLBACK');
        }
    }

    // ── the composed run log ──────────────────────────────────────────────────────────────────

    public function testTheRunLogAndHealthComeFromTheComposedStoreOnTheSameFile(): void
    {
        $store = JobStore::open($this->path);
        $store->runs()->recordRun('linkedin', 12, true, null, '2026-09-13T10:00:00Z', 400);

        self::assertSame(SourceStatus::OK, $store->runs()->health('linkedin', '2026-09-13T10:05:00Z')->status);
        self::assertSame('wal', $store->journalMode());
    }

    private function offer(string $id, string $source = 'linkedin', string $title = 'Lead Developer', string $company = 'Acme'): JobListing
    {
        return new JobListing(sourceName: $source, externalId: $id, title: $title, company: $company);
    }

    /** @return array{string, string} */
    private function titleAndCompany(): array
    {
        $row = $this->raw()->query('SELECT title, company FROM job_listings')->fetch(\PDO::FETCH_NUM);
        self::assertIsArray($row);

        return [(string) $row[0], (string) $row[1]];
    }

    private function raw(): \PDO
    {
        return new \PDO('sqlite:' . $this->path, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    }
}
