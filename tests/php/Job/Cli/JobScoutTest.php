<?php

declare(strict_types=1);

namespace Scout\Tests\Job\Cli;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Cli\Scout;
use Scout\Core\Notify\Notification;
use Scout\Core\Notify\NotificationKind;
use Scout\Core\Notify\Notifier;
use Scout\Job\Cli\JobScout;
use Scout\Job\JobListing;
use Scout\Job\JobStore;
use Scout\Job\JobVerdict;
use Scout\Tests\Support\DeliveringChannel;

/**
 * `scout --domain=job …` against the three LinkedIn fixtures and a THROWAWAY database in its own
 * directory — never the live store, which a fixture-backed run would feed into the health baseline.
 *
 * The fixtures carry 16 cards: 15 match and 1 is rejected on a stated salary under the floor. On
 * today's weights the matches score 6–46; the gate cases below are built on those numbers, and
 * when the weights are re-measured the two constants move with them.
 */
#[CoversClass(JobScout::class)]
final class JobScoutTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../../..';
    private const string NOW = '2026-09-13T20:00:00+02:00';

    /** The fixture cards that MATCH under the shipped criteria. */
    private const int MATCHES = 15;

    /** Of those, the ones a gate of 40 holds back — every one but the 46. */
    private const int UNDER_FORTY = 14;

    /** Aneo, `Senior Software Engineer`, 38 today: under a gate of 40, over nothing else. */
    private const string ANEO = '4461976159';

    private string $dir;
    private string $db;

    /** @var list<string> */
    private array $tempRoots = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/scout-job-cli-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        $this->db = $this->dir . '/job-watch.sqlite3';
        putenv('JOB_SCOUT_DB=' . $this->db);
        putenv('MAILBOX_DIR=' . self::ROOT . '/tests/fixtures/job/linkedin');
    }

    protected function tearDown(): void
    {
        foreach (['JOB_SCOUT_DB', 'MAILBOX_DIR', 'SCOUT_MAX_PASSES', 'JOB_HEARTBEAT_HOURS', 'JOB_FEED_SILENT_DAYS'] as $key) {
            putenv($key);
        }
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            is_dir($f) ? @rmdir($f) : @unlink($f);
        }
        @rmdir($this->dir);

        foreach ($this->tempRoots as $root) {
            foreach (glob($root . '/config/job/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($root . '/config/job');
            @rmdir($root . '/config');
            @rmdir($root);
        }
        $this->tempRoots = [];
    }

    public function testHelpListsEveryVerbTheJobConfigAndItsOwnKeys(): void
    {
        $r = $this->scout(['--domain=job', 'help']);

        self::assertSame(0, $r['code']);
        foreach (['doctor', 'dump <source>', 'run --once', 'run --watch', 'test-notify', 'rollup [--dry-run]'] as $verb) {
            self::assertStringContainsString('scout --domain=job ' . $verb, $r['out']);
        }
        self::assertStringContainsString('config/job/criteria.json', $r['out']);
        self::assertStringContainsString('JOB_NTFY_TOPIC', $r['out']);
        self::assertStringNotContainsString('config/car/', $r['out'], 'the job help is not a copy of the car one');
        self::assertStringNotContainsString('pas encore', $r['out'] . $r['err'], 'no verb is a stub any more');
    }

    public function testAnUnknownVerbIsRefused(): void
    {
        $r = $this->scout(['--domain=job', 'frobnicate']);

        self::assertSame(2, $r['code']);
        self::assertStringContainsString('commande inconnue : frobnicate', $r['err']);
    }

    // ── run ─────────────────────────────────────────────────────────────────────────────────────

    public function testRunRefusesAnEmptySeenSetUntilSeededAndTheRefusalReachesTheNextBeat(): void
    {
        $refused = $this->scout(['--domain=job', 'run', '--once', '--source=linkedin']);
        self::assertSame(2, $refused['code']);
        self::assertStringContainsString('--seed', $refused['err'], 'Q36, the job analog');
        self::assertFileExists($this->dir . '/job-last-refusal.txt', 'a refused run is recorded (Q27)');

        $seed = $this->scout(['--domain=job', 'run', '--once', '--seed', '--source=linkedin']);
        self::assertSame(0, $seed['code'], $seed['err']);
        self::assertStringContainsString('mode --seed', $seed['out']);

        $channel = new DeliveringChannel();
        $again = $this->scout(['--domain=job', 'run', '--once', '--source=linkedin'], $channel);
        self::assertSame(0, $again['code'], $again['err']);
        self::assertSame([], $this->ofKind($channel, NotificationKind::MATCH), 'seeded offers are never pushed');
        self::assertStringContainsString('16 offre(s) analysée(s)', $again['out']);
        self::assertCount(1, $this->ofKind($channel, NotificationKind::HEARTBEAT), 'a pending refusal forces one beat under --once');
        self::assertFileDoesNotExist($this->dir . '/job-last-refusal.txt', 'cleared once that beat was delivered');
    }

    public function testSeedAndWatchTogetherAreRefused(): void
    {
        $r = $this->scout(['--domain=job', 'run', '--watch', '--seed']);

        self::assertSame(2, $r['code']);
        self::assertStringContainsString('--seed', $r['err']);
    }

    public function testAnUnseededRunPushesEveryMatchOnceWithTheSourceLeadingTheTitle(): void
    {
        $this->seedWithOneThrowawayOffer();
        $channel = new DeliveringChannel();

        $r = $this->scout(['--domain=job', 'run', '--once', '-v', '--source=linkedin'], $channel);
        $this->scout(['--domain=job', 'run', '--once', '--source=linkedin'], $channel);

        self::assertSame(0, $r['code'], $r['err']);
        self::assertStringContainsString('15 correspondance(s), 1 écartée(s)', $r['out']);
        self::assertStringContainsString('sous le plancher', $r['out'], '-v names the rejection');
        $pushes = $this->ofKind($channel, NotificationKind::MATCH);
        self::assertCount(self::MATCHES, $pushes, 'no gate ships: every match pushed, none twice');
        self::assertContains(
            'linkedin · 38/100 — Senior Software Engineer · Aneo · Montrouge · hybride',
            array_map(static fn (Notification $n): string => $n->title, $pushes),
        );
        self::assertSame(0, JobStore::open($this->db)->pendingRollupCount());
    }

    public function testUnderAGateAOncePassHoldsBackTheRestAndNamesTheVerbThatDrainsThem(): void
    {
        $root = $this->rootWithPushGate(40);
        $this->seedWithOneThrowawayOffer();
        $channel = new DeliveringChannel();

        $r = $this->scout(['--domain=job', 'run', '--once', '--source=linkedin'], $channel, $root);

        self::assertSame(0, $r['code'], $r['err']);
        self::assertCount(1, $this->ofKind($channel, NotificationKind::MATCH), 'only the 46 clears 40');
        self::assertMatchesRegularExpression('~(?<![0-9])' . self::UNDER_FORTY . ' correspondance\(s\) sous le seuil~', $r['out']);
        self::assertStringContainsString('scout --domain=job rollup', $r['out'], 'the --once drain is the verb');
        self::assertStringContainsString('ne tourne que sous --watch', $r['out']);
        self::assertSame(self::UNDER_FORTY, JobStore::open($this->db)->pendingRollupCount());
    }

    // ── rollup: the verb and its daily floor ───────────────────────────────────────────────────

    public function testRollupAnnouncesTheQueueOnceUnderItsOwnKindAndMarksEachOfferAsRolledUp(): void
    {
        $root = $this->queueUnderAGateOfForty();
        $channel = new DeliveringChannel();

        $r = $this->scout(['--domain=job', 'rollup'], $channel, $root);

        self::assertSame(0, $r['code'], $r['out'] . $r['err']);
        $rollups = $this->ofKind($channel, NotificationKind::ROLLUP);
        self::assertCount(1, $rollups, 'one message, not a push per offer');
        self::assertCount(self::UNDER_FORTY, $rollups[0]->reasons);
        self::assertStringContainsString('Senior Software Engineer · Aneo', implode("\n", $rollups[0]->reasons));
        self::assertSame([], $this->ofKind($channel, NotificationKind::MATCH), 'never a MATCH push');
        self::assertStringNotContainsString('autre(s) en attente', $r['out'], 'a drain that emptied the queue claims no remainder');

        $store = JobStore::open($this->db);
        self::assertSame(0, $store->pendingRollupCount(), 'marked on delivery');
        self::assertTrue($store->wasNotifiedAs($this->key(self::ANEO), JobStore::AS_ROLLUP));
        self::assertFalse($store->wasNotifiedAs($this->key(self::ANEO), JobStore::AS_MATCH), 'a rollup is not a push — it can still be promoted');

        $again = $this->scout(['--domain=job', 'rollup'], $channel, $root);
        self::assertStringContainsString('Aucune offre en attente', $again['out'], 'drained once');
    }

    public function testRollupDryRunPrintsTheBatchSendsNothingAndMarksNothing(): void
    {
        $root = $this->queueUnderAGateOfForty();
        $channel = new DeliveringChannel();

        $r = $this->scout(['--domain=job', 'rollup', '--dry-run'], $channel, $root);

        self::assertSame(0, $r['code'], $r['out'] . $r['err']);
        self::assertStringContainsString('Senior Software Engineer · Aneo', $r['out']);
        self::assertSame([], $channel->sent);
        self::assertSame(self::UNDER_FORTY, JobStore::open($this->db)->pendingRollupCount());
    }

    public function testRollupRefusesAnUnknownOption(): void
    {
        $r = $this->scout(['--domain=job', 'rollup', '--force']);

        self::assertSame(2, $r['code']);
        self::assertStringContainsString('option inconnue : --force', $r['err']);
    }

    public function testARefusedRollupMarksNothingAndSaysTheQueueIsStillWaiting(): void
    {
        $root = $this->queueUnderAGateOfForty();
        $channel = new DeliveringChannel();
        $channel->refuses = [NotificationKind::ROLLUP];

        $r = $this->scout(['--domain=job', 'rollup'], $channel, $root);

        self::assertSame(1, $r['code']);
        self::assertMatchesRegularExpression('~(?<![0-9])' . self::UNDER_FORTY . ' autre\(s\) en attente~', $r['out']);
        self::assertSame(self::UNDER_FORTY, JobStore::open($this->db)->pendingRollupCount());
    }

    public function testTheDailyFloorDrainsTheQueueAtStartupUnderWatchAndWritesItsMarkerOnDelivery(): void
    {
        $root = $this->queueUnderAGateOfForty();
        $channel = new DeliveringChannel();

        $r = $this->watchOnce($channel, $root);

        self::assertSame(0, $r['code'], $r['out'] . $r['err']);
        self::assertCount(1, $this->ofKind($channel, NotificationKind::ROLLUP));
        // BEFORE the first pass, not merely at some point: the floor inside the loop drains the same
        // queue afterwards, so without the ordering this test passes with the startup floor deleted.
        $floor = strpos($r['out'], 'récapitulatif quotidien');
        $pass = strpos($r['out'], ' offre(s) analysée(s)');
        self::assertNotFalse($floor, $r['out']);
        self::assertNotFalse($pass, $r['out']);
        self::assertLessThan($pass, $floor, 'the startup floor drains before the first pass runs');
        self::assertDoesNotMatchRegularExpression('~(?<![0-9])14 correspondance\(s\) sous le seuil~', $r['out'], 'a pass after the drain holds nothing back again');
        self::assertFileExists($this->dir . '/job-rollup.txt', 'the marker is written after delivery');
        self::assertSame(0, JobStore::open($this->db)->pendingRollupCount());
    }

    public function testTheDailyFloorWritesNoMarkerAndMarksNothingWhenTheChannelRefuses(): void
    {
        $root = $this->queueUnderAGateOfForty();
        $channel = new DeliveringChannel();
        $channel->refuses = [NotificationKind::ROLLUP];

        $r = $this->watchOnce($channel, $root);

        self::assertSame([], $this->ofKind($channel, NotificationKind::ROLLUP));
        self::assertFileDoesNotExist($this->dir . '/job-rollup.txt', 'no delivery, no marker — the window stays open');
        self::assertSame(self::UNDER_FORTY, JobStore::open($this->db)->pendingRollupCount());
        self::assertStringContainsString('non délivré', $r['out'] . $r['err']);
    }

    /** With no gate, a queued offer can only be a failed push: it goes out as the match it is. */
    public function testTheRollupVerbRePushesAFailedPushAsTheMatchItIs(): void
    {
        $this->queueFailedPushes();
        $channel = new DeliveringChannel();

        $r = $this->scout(['--domain=job', 'rollup'], $channel);

        self::assertSame(0, $r['code'], $r['out'] . $r['err']);
        self::assertStringContainsString('réémise(s) individuellement', $r['out']);
        self::assertCount(self::MATCHES, $this->ofKind($channel, NotificationKind::MATCH));
        self::assertSame([], $this->ofKind($channel, NotificationKind::ROLLUP), 'never filed under « score bas »');
        $store = JobStore::open($this->db);
        self::assertSame(0, $store->pendingRollupCount());
        self::assertTrue($store->wasNotifiedAs($this->key(self::ANEO), JobStore::AS_MATCH));
    }

    /** The floor is the only automatic drain under --watch, so it re-pushes exactly as the verb does. */
    public function testTheDailyFloorRePushesAFailedPushAndThePassAfterItPushesNothingAgain(): void
    {
        $this->queueFailedPushes();
        $channel = new DeliveringChannel();

        $r = $this->watchOnce($channel);

        self::assertSame(0, $r['code'], $r['out'] . $r['err']);
        self::assertStringContainsString('réémise(s) individuellement', $r['out']);
        self::assertCount(self::MATCHES, $this->ofKind($channel, NotificationKind::MATCH), 'the pass re-reads the same alerts and pushes none twice');
        self::assertSame(0, JobStore::open($this->db)->pendingRollupCount());
    }

    /** A rollup does not cover a push, end to end: lower the gate and each rolled-up offer is pushed once. */
    public function testARolledUpOfferIsPromotedToOnePushWhenTheGateDropsUnderItsScore(): void
    {
        $closed = $this->rootWithPushGate(100);
        $this->seedWithOneThrowawayOffer();
        $this->scout(['--domain=job', 'run', '--once', '--source=linkedin'], new DeliveringChannel(), $closed);
        $this->scout(['--domain=job', 'rollup'], new DeliveringChannel(), $closed);
        self::assertTrue(JobStore::open($this->db)->wasNotifiedAs($this->key(self::ANEO), JobStore::AS_ROLLUP));

        $open = $this->rootWithPushGate(0);
        $channel = new DeliveringChannel();
        $this->scout(['--domain=job', 'run', '--once', '--source=linkedin'], $channel, $open);
        $this->scout(['--domain=job', 'run', '--once', '--source=linkedin'], $channel, $open);

        self::assertCount(self::MATCHES, $this->ofKind($channel, NotificationKind::MATCH), 'each promoted exactly once');
        self::assertTrue(JobStore::open($this->db)->wasNotifiedAs($this->key(self::ANEO), JobStore::AS_MATCH), 'the stored kind flipped from ROLLUP to MATCH');
    }

    public function testAQueuedOfferTodaysRulesRejectIsLeftWaitingAndNeverAnnounced(): void
    {
        $key = $this->queueAnOffer(new JobListing(sourceName: 'linkedin', externalId: 'DATA-1', title: 'Data Engineer', company: 'Acme', location: 'Paris'), 90);
        $channel = new DeliveringChannel();

        $r = $this->scout(['--domain=job', 'rollup'], $channel);

        self::assertSame([], $channel->sent);
        self::assertStringContainsString($key . ' re-jugée REJECT', $r['err']);
        self::assertSame(1, JobStore::open($this->db)->pendingRollupCount(), 'left waiting, never marked');
    }

    public function testAnOfferWhoseSnapshotWillNotDecodeIsAnnouncedFromItsColumnsAndSaysSo(): void
    {
        $key = $this->queueAnOffer(new JobListing(sourceName: 'linkedin', externalId: 'BROKEN-1', title: 'Staff Engineer', company: 'Acme', url: 'https://www.linkedin.com/jobs/view/BROKEN-1/'), 60);
        $pdo = new \PDO('sqlite:' . $this->db);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->prepare('UPDATE job_listings SET snapshot_json = :j WHERE dedup_key = :k')->execute(['j' => '{pas du json', 'k' => $key]);

        $channel = new DeliveringChannel();
        $r = $this->scout(['--domain=job', 'rollup'], $channel);

        self::assertSame(0, $r['code'], $r['out'] . $r['err']);
        self::assertStringContainsString('instantané illisible pour ' . $key, $r['err']);
        $pushes = $this->ofKind($channel, NotificationKind::MATCH);
        self::assertCount(1, $pushes, 'no gate: the stored score goes out as the match it was');
        self::assertSame('linkedin · 60/100 — Staff Engineer · Acme', $pushes[0]->title);
        self::assertSame('https://www.linkedin.com/jobs/view/BROKEN-1/', $pushes[0]->url);
        self::assertTrue(JobStore::open($this->db)->wasNotifiedAs($key, JobStore::AS_MATCH));
    }

    // ── watch ───────────────────────────────────────────────────────────────────────────────────

    public function testWatchBeatsOnceAtColdStartCarryingThePendingRefusalAndClearsItOnDelivery(): void
    {
        $this->scout(['--domain=job', 'run', '--once', '--source=linkedin']);
        $this->seedWithOneThrowawayOffer();
        $channel = new DeliveringChannel();

        $r = $this->watchOnce($channel);

        self::assertSame(0, $r['code'], $r['out'] . $r['err']);
        $beats = $this->ofKind($channel, NotificationKind::HEARTBEAT);
        self::assertCount(1, $beats, 'a cold start beats once; the marker holds the rest of the interval');
        self::assertStringStartsWith('job-watch tourne — ', $beats[0]->title);
        self::assertStringContainsString('démarrage précédent refusé', implode("\n", $beats[0]->reasons));
        self::assertFileExists($this->dir . '/job-heartbeat.txt');
        self::assertFileDoesNotExist($this->dir . '/job-last-refusal.txt');
    }

    public function testAnUnusableHeartbeatIntervalIsALoudRefusalNamingItsKey(): void
    {
        $this->seedWithOneThrowawayOffer();
        putenv('JOB_HEARTBEAT_HOURS=0');

        $r = $this->watchOnce(new DeliveringChannel());

        self::assertSame(2, $r['code']);
        self::assertStringContainsString('JOB_HEARTBEAT_HOURS', $r['err']);
    }

    /**
     * The in-loop beat is the one that fires on day two, and under a fixed clock the startup beat's
     * marker makes it unreachable. An unwritable marker — a directory where the file goes — reaches
     * it: every check is then due, so two beats is the documented bias rather than a spam bug.
     */
    public function testTheInLoopBeatIsReachedWhenTheMarkerCannotBeWritten(): void
    {
        $this->seedWithOneThrowawayOffer();
        mkdir($this->dir . '/job-heartbeat.txt');
        $channel = new DeliveringChannel();

        $r = $this->watchOnce($channel);

        self::assertSame(0, $r['code'], $r['out'] . $r['err']);
        self::assertStringNotContainsString('battement de cœur non émis', $r['err']);
        self::assertCount(2, $this->ofKind($channel, NotificationKind::HEARTBEAT), 'the startup beat and the in-loop beat');
    }

    // ── --source= force-runs a disabled source ──────────────────────────────────────────────────

    public function testANamedSourceRunsEvenWhenDisabledAndSaysSo(): void
    {
        $root = $this->rootWithLinkedinDisabled();
        $this->seedWithOneThrowawayOffer();
        $channel = new DeliveringChannel();

        $r = $this->scout(['--domain=job', 'run', '--once', '--source=linkedin'], $channel, $root);

        self::assertSame(0, $r['code'], $r['out'] . $r['err']);
        self::assertStringContainsString('source linkedin est `enabled: false` — forcée par --source=', $r['err']);
        self::assertCount(self::MATCHES, $this->ofKind($channel, NotificationKind::MATCH));
    }

    /** The counterweight: deleting the enabled check would satisfy the test above on its own. */
    public function testAnOrdinaryPassStillSkipsADisabledSource(): void
    {
        $root = $this->rootWithLinkedinDisabled();
        $this->seedWithOneThrowawayOffer();
        $channel = new DeliveringChannel();

        $r = $this->scout(['--domain=job', 'run', '--once'], $channel, $root);

        self::assertSame(2, $r['code'], $r['out'] . $r['err']);
        self::assertStringContainsString('aucune source activée', $r['err']);
        self::assertSame([], $channel->sent);
    }

    // ── doctor, dump, test-notify ───────────────────────────────────────────────────────────────

    public function testDoctorReportsTheStoreTheChannelsAndTheSource(): void
    {
        $r = $this->scout(['--domain=job', 'doctor', '--source=linkedin']);

        self::assertSame(0, $r['code'], $r['out'] . $r['err']);
        self::assertStringContainsString('job-watch · base ' . $this->db, $r['out']);
        self::assertStringContainsString('seen-set : 0 offre(s)', $r['out']);
        self::assertStringContainsString('VIDE', $r['out']);
        self::assertStringContainsString('AUCUN canal n\'atteint de destinataire', $r['out'], 'console ships alone, and doctor says what that means');
        self::assertMatchesRegularExpression('~linkedin\s+ok\s+16\s~', $r['out']);
    }

    public function testDoctorReportsAPendingRefusalWithoutConsumingIt(): void
    {
        $this->scout(['--domain=job', 'run', '--once', '--source=linkedin']);

        $r = $this->scout(['--domain=job', 'doctor', '--source=linkedin']);

        self::assertStringContainsString('refus    :', $r['out']);
        self::assertFileExists($this->dir . '/job-last-refusal.txt', 'doctor reports, the beat consumes');
    }

    public function testDoctorWarnsWhenTheFeedSilenceThresholdReachesTheImapWindow(): void
    {
        putenv('JOB_FEED_SILENT_DAYS=7');

        $r = $this->scout(['--domain=job', 'doctor', '--source=linkedin']);

        self::assertStringContainsString('JOB_FEED_SILENT_DAYS', $r['err']);
    }

    public function testAZeroFeedSilenceThresholdIsRefusedLoudly(): void
    {
        putenv('JOB_FEED_SILENT_DAYS=0');

        $r = $this->scout(['--domain=job', 'doctor']);

        self::assertSame(2, $r['code']);
        self::assertStringContainsString('JOB_FEED_SILENT_DAYS', $r['err']);
    }

    public function testDumpShowsTheFirstOfferEveryFieldOfTheModelAndItsVerdict(): void
    {
        $r = $this->scout(['--domain=job', 'dump', 'linkedin']);

        self::assertSame(0, $r['code'], $r['err']);
        self::assertStringContainsString('16 au total', $r['out']);
        self::assertStringContainsString('Full-Stack Developer (Remote)', $r['out']);
        foreach ((new \ReflectionClass(JobListing::class))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            self::assertMatchesRegularExpression('~^  ' . $property->getName() . '\s~m', $r['out'], 'every model field is dumped: ' . $property->getName());
        }
        self::assertMatchesRegularExpression('~^  verdict\s+MATCH \d+/100~m', $r['out']);
    }

    public function testDumpRefusesAMissingOrUnknownSource(): void
    {
        self::assertStringContainsString('usage', $this->scout(['--domain=job', 'dump'])['err']);

        $unknown = $this->scout(['--domain=job', 'dump', 'indeed']);
        self::assertSame(2, $unknown['code']);
        self::assertStringContainsString('source inconnue : indeed', $unknown['err']);
    }

    public function testTestNotifyRefusesTheConsoleAloneAndSucceedsOnARealChannel(): void
    {
        $console = $this->scout(['--domain=job', 'test-notify']);
        self::assertSame(2, $console['code']);
        self::assertStringContainsString('la console seule ne prouve rien', $console['err']);

        $channel = new DeliveringChannel();
        $ok = $this->scout(['--domain=job', 'test-notify'], $channel);
        self::assertSame(0, $ok['code'], $ok['err']);
        self::assertSame('job-watch : test de notification', $channel->sent[0]->title);
    }

    // ────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * @param list<string> $argv
     *
     * @return array{code: int, out: string, err: string}
     */
    private function scout(array $argv, ?DeliveringChannel $channel = null, ?string $root = null): array
    {
        $out = fopen('php://memory', 'r+');
        $err = fopen('php://memory', 'r+');
        self::assertIsResource($out);
        self::assertIsResource($err);

        $code = (new Scout($root ?? self::ROOT, $out, $err, self::NOW, null, $channel === null ? null : new Notifier([$channel])))->run($argv);

        rewind($out);
        rewind($err);

        return ['code' => $code, 'out' => (string) stream_get_contents($out), 'err' => (string) stream_get_contents($err)];
    }

    /** @return array{code: int, out: string, err: string} */
    private function watchOnce(DeliveringChannel $channel, ?string $root = null): array
    {
        putenv('SCOUT_MAX_PASSES=1');
        try {
            return $this->scout(['--domain=job', 'run', '--watch', '--source=linkedin'], $channel, $root);
        } finally {
            putenv('SCOUT_MAX_PASSES');
        }
    }

    /** A seed over nothing leaves the seen-set empty, so one throwaway offer is recorded by hand. */
    private function seedWithOneThrowawayOffer(): void
    {
        JobStore::open($this->db)->record(new JobListing(sourceName: 'linkedin', externalId: 'seed'), '2026-08-01T00:00:00Z');
    }

    private function queueUnderAGateOfForty(): string
    {
        $root = $this->rootWithPushGate(40);
        $this->seedWithOneThrowawayOffer();
        $r = $this->scout(['--domain=job', 'run', '--once', '--source=linkedin'], new DeliveringChannel(), $root);
        self::assertSame(0, $r['code'], $r['err']);
        self::assertSame(self::UNDER_FORTY, JobStore::open($this->db)->pendingRollupCount());

        return $root;
    }

    private function queueFailedPushes(): void
    {
        $this->seedWithOneThrowawayOffer();
        $down = new DeliveringChannel();
        $down->refuses = [NotificationKind::MATCH];
        $this->scout(['--domain=job', 'run', '--once', '--source=linkedin'], $down);
        self::assertSame(self::MATCHES, JobStore::open($this->db)->pendingRollupCount(), 'with no gate, a queued offer can only be a failed push');
    }

    private function queueAnOffer(JobListing $offer, int $storedScore): string
    {
        $store = JobStore::open($this->db);
        $key = $store->record($offer, '2026-09-01T00:00:00Z')->dedupKey;
        $store->recordVerdict($key, JobVerdict::matched($storedScore, ['jugée avant ce changement']), $offer);

        return $key;
    }

    /** A private root carrying the SHIPPED config with one key overridden, through the loader's own local-override mechanism. */
    private function rootWithPushGate(int $gate): string
    {
        $root = sys_get_temp_dir() . '/scout-job-root-' . bin2hex(random_bytes(4));
        mkdir($root . '/config/job', 0o777, true);
        foreach (['criteria.json', 'sources.json'] as $f) {
            copy(self::ROOT . '/config/job/' . $f, $root . '/config/job/' . $f);
        }
        file_put_contents($root . '/config/job/criteria.local.json', (string) json_encode(['notify' => ['push_min_score' => $gate]]));
        $this->tempRoots[] = $root;

        return $root;
    }

    private function rootWithLinkedinDisabled(): string
    {
        $root = $this->rootWithPushGate(0);
        unlink($root . '/config/job/criteria.local.json');
        $sources = json_decode((string) file_get_contents($root . '/config/job/sources.json'), true, flags: JSON_THROW_ON_ERROR);
        $sources['sources']['linkedin']['enabled'] = false;
        file_put_contents($root . '/config/job/sources.json', json_encode($sources, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $root;
    }

    private function key(string $id): string
    {
        return JobStore::open($this->db)->dedupKey(new JobListing(sourceName: 'linkedin', externalId: $id));
    }

    /** @return list<Notification> */
    private function ofKind(DeliveringChannel $channel, NotificationKind $kind): array
    {
        return array_values(array_filter($channel->sent, static fn (Notification $n): bool => $n->kind === $kind));
    }
}
