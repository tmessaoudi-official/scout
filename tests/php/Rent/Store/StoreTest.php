<?php

declare(strict_types=1);

namespace Scout\Tests\Rent\Store;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Rent\Core\RawListing;
use Scout\Core\RunStore;
use Scout\Core\SourceStatus;
use Scout\Rent\Store\Store;

/**
 * The store is where a silent failure costs the most, and it is not the obvious one.
 *
 * `CLAUDE.md` § "Credentials & stateful data" lists the seen-set and the price history as data that
 * must not be casually deleted: losing the seen-set re-notifies the entire market at once, and
 * price history is not reconstructible because a listing only ever shows its CURRENT rent. Both
 * failures are silent in the direction that matters — nothing warns you that a rent drop went
 * unnoticed, because the evidence it happened is the thing that was lost.
 */
#[CoversClass(Store::class)]
final class StoreTest extends TestCase
{
    private Store $store;

    protected function setUp(): void
    {
        // In-memory, so no test can leave a real seen-set behind or read one.
        $this->store = Store::open(':memory:');
    }

    /** The schema is created on open and creating it twice is not an error. */
    public function testMigrationIsIdempotent(): void
    {
        $this->store->migrate();
        $this->store->migrate();

        self::assertSame(Store::SCHEMA_VERSION, $this->store->schemaVersion());
    }

    /**
     * `(source, externalId)` is the stable key; the URL/title hash is only the FALLBACK.
     *
     * spec §7: "stable key `(source, external_ref)`, falling back to a hash of normalised
     * `(url, title)`". The fallback matters because several institutional feeds do not expose a
     * stable id at all — and if the key changed between runs, every listing would look new on every
     * run and the user would be notified about the same flat forever.
     */
    public function testDedupKeyPrefersTheSourcesOwnId(): void
    {
        $withId = $this->listing(externalId: 'ANN-42', url: 'https://a.test/1', title: 'T3 Cergy');
        $sameIdDifferentText = $this->listing(externalId: 'ANN-42', url: 'https://a.test/CHANGED', title: 'T3 Cergy — baisse de prix');

        self::assertSame($this->store->dedupKey($withId), $this->store->dedupKey($sameIdDifferentText));
    }

    public function testDedupKeyFallsBackToUrlAndTitleWhenThereIsNoId(): void
    {
        $a = $this->listing(externalId: '', url: 'https://a.test/1', title: 'T3 Cergy');
        $b = $this->listing(externalId: '', url: 'https://a.test/1', title: 'T3 Cergy');
        $c = $this->listing(externalId: '', url: 'https://a.test/2', title: 'T3 Cergy');

        self::assertSame($this->store->dedupKey($a), $this->store->dedupKey($b));
        self::assertNotSame($this->store->dedupKey($a), $this->store->dedupKey($c));
    }

    /** Two sources may legitimately use the same id; the key must not collide across them. */
    public function testDedupKeyIsScopedToTheSource(): void
    {
        $inli = $this->listing(source: 'inli', externalId: '17');
        $cdc = $this->listing(source: 'cdc_habitat', externalId: '17');

        self::assertNotSame($this->store->dedupKey($inli), $this->store->dedupKey($cdc));
    }

    /** First sighting is new; the second is not. This is the whole seen-set contract. */
    public function testAListingIsNewExactlyOnce(): void
    {
        $listing = $this->listing(externalId: 'ANN-1');

        self::assertTrue($this->store->record($listing, 950, '2026-08-07T09:00:00+00:00')->isNew);
        self::assertFalse($this->store->record($listing, 950, '2026-08-07T10:00:00+00:00')->isNew);
    }

    /**
     * ── seen-set ──
     *
     * "Has anything ever been recorded?" — the fact the Q36 flood guard reads before `scout run`
     * is allowed to notify. It replaced "did `open()` create the file?", which any earlier command
     * that merely opened the database destroyed; `scout doctor` did, so typing it once disarmed the
     * guard for the following run.
     *
     * A missing volume mount produces a valid, empty, migrated database indistinguishable from a
     * healthy one, and with nothing batched every historic listing pushes at once (Q8 rules out
     * GitHub Actions for exactly this, then adopts Docker-on-a-VPS, which fails the same way).
     */
    public function testAStoreThatHasRecordedNothingSaysSo(): void
    {
        self::assertTrue($this->store->isSeenSetEmpty(), 'a migrated database with no rows is empty');

        $this->store->record($this->listing(externalId: 'ANN-1'), 950, '2026-08-07T09:00:00+00:00');

        self::assertFalse($this->store->isSeenSetEmpty());
    }

    /**
     * And the answer comes from the ROWS, not from a flag set during this process's `open()` —
     * otherwise a second process reading the same file would report a full seen-set as empty and
     * refuse to run, or the reverse.
     */
    public function testTheEmptySeenSetAnswerSurvivesReopening(): void
    {
        $path = sys_get_temp_dir() . '/rentwatch-seenset-' . bin2hex(random_bytes(8)) . '.sqlite3';

        try {
            $first = Store::open($path);
            self::assertTrue($first->isSeenSetEmpty());
            $first->record($this->listing(externalId: 'ANN-1'), 950, '2026-08-07T09:00:00+00:00');

            self::assertFalse(Store::open($path)->isSeenSetEmpty(), 'reopened on a populated file');
        } finally {
            foreach (['', '-wal', '-shm'] as $suffix) {
                @unlink($path . $suffix);
            }
        }
    }

    /** A rent DROP on a listing already seen is its own notification-worthy event (spec §7). */
    public function testARentDropIsReported(): void
    {
        $listing = $this->listing(externalId: 'ANN-1');

        $this->store->record($listing, 1000, '2026-08-07T09:00:00+00:00');
        $second = $this->store->record($listing, 940, '2026-08-08T09:00:00+00:00');

        self::assertFalse($second->isNew);
        self::assertSame(1000, $second->previousRentCc);
        self::assertSame(-60, $second->rentDeltaCc);
        self::assertTrue($second->isPriceDrop);
    }

    /** A rent RISE is recorded but is not a price drop — the event is a drop, not a change. */
    public function testARentRiseIsRecordedButIsNotADrop(): void
    {
        $listing = $this->listing(externalId: 'ANN-1');

        $this->store->record($listing, 940, '2026-08-07T09:00:00+00:00');
        $second = $this->store->record($listing, 1000, '2026-08-08T09:00:00+00:00');

        self::assertSame(60, $second->rentDeltaCc);
        self::assertFalse($second->isPriceDrop);
    }

    /**
     * `null` IS NOT ZERO, and here it is not a price drop either — `CLAUDE.md` hard rule 9.
     *
     * A source that stops publishing the rent, or one that has not published it at all, must not
     * read as a fall to 0. This is the trap in its natural habitat: `1000 → null` is "the listing stopped
     * saying", and treating it as a 1000-euro reduction would fire the loudest notification the
     * system has on the least information it has ever had.
     */
    public function testAnUnknownRentDoesNotCountAsAPriceDrop(): void
    {
        $listing = $this->listing(externalId: 'ANN-1');

        $this->store->record($listing, 1000, '2026-08-07T09:00:00+00:00');
        $vanished = $this->store->record($listing, null, '2026-08-08T09:00:00+00:00');

        self::assertFalse($vanished->isPriceDrop);
        self::assertNull($vanished->rentDeltaCc);

        // …and the reverse: appearing for the first time is not a drop from nothing.
        $appeared = $this->store->record($this->listing(externalId: 'ANN-2'), null, '2026-08-07T09:00:00+00:00');
        $priced = $this->store->record($this->listing(externalId: 'ANN-2'), 900, '2026-08-08T09:00:00+00:00');

        self::assertTrue($appeared->isNew);
        self::assertFalse($priced->isPriceDrop);
        self::assertNull($priced->rentDeltaCc);
    }

    /**
     * A rent that vanishes and comes back LOWER is still a drop.
     *
     * The last KNOWN figure is kept when a source stops publishing the rent, rather than being
     * overwritten with the null. Overwriting reads as "we never knew", so the return at 940 would
     * look like a first sighting of the price and the drop — the notification-worthy event — would
     * be silently swallowed. That failure leaves no trace at all: nothing arrives, and nothing says
     * why.
     */
    public function testARentThatVanishesAndReturnsLowerIsStillADrop(): void
    {
        $listing = $this->listing(externalId: 'ANN-1');

        $this->store->record($listing, 1000, '2026-08-07T09:00:00+00:00');
        $this->store->record($listing, null, '2026-08-08T09:00:00+00:00');
        $back = $this->store->record($listing, 940, '2026-08-09T09:00:00+00:00');

        self::assertSame(1000, $back->previousRentCc);
        self::assertSame(-60, $back->rentDeltaCc);
        self::assertTrue($back->isPriceDrop);
        self::assertSame([1000, 940], $this->store->priceHistory($this->store->dedupKey($listing)));
    }

    /** Price history is append-only, and a rent that did not change adds no row. */
    public function testPriceHistoryRecordsEveryChangeAndOnlyChanges(): void
    {
        $listing = $this->listing(externalId: 'ANN-1');

        $this->store->record($listing, 1000, '2026-08-07T09:00:00+00:00');
        $this->store->record($listing, 1000, '2026-08-08T09:00:00+00:00');
        $this->store->record($listing, 940, '2026-08-09T09:00:00+00:00');

        self::assertSame([1000, 940], $this->store->priceHistory($this->store->dedupKey($listing)));
    }

    /** Notifying is recorded separately from seeing: a digested listing was seen, not notified. */
    public function testNotificationIsRecordedSeparatelyFromSighting(): void
    {
        $listing = $this->listing(externalId: 'ANN-1');
        $key = $this->store->dedupKey($listing);

        $this->store->record($listing, 900, '2026-08-07T09:00:00+00:00');
        self::assertFalse($this->store->wasNotified($key));

        $this->store->markNotified($key, '2026-08-07T09:05:00+00:00', 'MATCH');
        self::assertTrue($this->store->wasNotified($key));
    }

    /**
     * WHAT a listing was announced as is a different fact from WHETHER it was (schema v8).
     *
     * `notified_at` alone was answering both, and the match path read a delivered digest as
     * "already told about this listing" — so a promotion DIGEST -> MATCH was silently swallowed
     * while the pass counted it. Found by a review panel on 2026-08-24.
     */
    public function testADigestAnnouncementDoesNotCountAsHavingAnnouncedAMatch(): void
    {
        $listing = $this->listing(externalId: 'ANN-P1');
        $key = $this->store->dedupKey($listing);
        $this->store->record($listing, 900, '2026-08-07T09:00:00+00:00');

        $this->store->markNotified($key, '2026-08-07T09:05:00+00:00', 'DIGEST');

        self::assertTrue($this->store->wasNotified($key), 'the listing HAS been announced');
        self::assertTrue($this->store->wasNotifiedAs($key, 'DIGEST'), 'and announced as a doubt');
        self::assertFalse(
            $this->store->wasNotifiedAs($key, 'MATCH'),
            'being told a flat is doubtful is not being told it is a match',
        );
    }

    /**
     * A5 (row 6, 2026-09-05): a THIRD announcement kind between the two. A low-score match carried
     * in the rollup has been told about — it covers the digest question — but it has NOT been
     * pushed as a match, so a later rent drop lifting it over the gate is a promotion, pushed once.
     * The ordering is monotone: DIGEST < ROLLUP < MATCH, a write never demotes.
     */
    public function testARollupAnnouncementSitsBetweenDigestAndMatch(): void
    {
        $listing = $this->listing(externalId: 'ANN-R1');
        $key = $this->store->dedupKey($listing);
        $this->store->record($listing, 900, '2026-09-05T09:00:00+00:00');

        $this->store->markNotified($key, '2026-09-05T09:05:00+00:00', 'ROLLUP');

        self::assertTrue($this->store->wasNotified($key));
        self::assertTrue($this->store->wasNotifiedAs($key, 'DIGEST'), 'a rollup covers the doubt question');
        self::assertTrue($this->store->wasNotifiedAs($key, 'ROLLUP'));
        self::assertFalse($this->store->wasNotifiedAs($key, 'MATCH'), 'rolled up is not pushed — the promotion stays reachable');

        // A later DIGEST write cannot demote it; a later MATCH write promotes it.
        $this->store->markNotified($key, '2026-09-05T10:00:00+00:00', 'DIGEST');
        self::assertTrue($this->store->wasNotifiedAs($key, 'ROLLUP'), 'never demoted');
        $this->store->markNotified($key, '2026-09-05T11:00:00+00:00', 'MATCH');
        self::assertTrue($this->store->wasNotifiedAs($key, 'MATCH'), 'promoted');
        $this->store->markNotified($key, '2026-09-05T12:00:00+00:00', 'ROLLUP');
        self::assertTrue($this->store->wasNotifiedAs($key, 'MATCH'), 'and a match is never demoted to a rollup');
    }

    /** The low-score queue is exactly the judged MATCHes nobody has been told about, oldest first. */
    public function testThePendingLowScoreQueueIsTheUnannouncedMatches(): void
    {
        $queued = $this->listing(externalId: 'LS-1');
        $pushed = $this->listing(externalId: 'LS-2');
        $doubt = $this->listing(externalId: 'LS-3');
        foreach ([[$queued, 'MATCH', false], [$pushed, 'MATCH', true], [$doubt, 'DIGEST', false]] as [$l, $outcome, $announced]) {
            $key = $this->store->dedupKey($l);
            $this->store->record($l, 900, '2026-09-05T09:00:00+00:00');
            $this->store->recordVerdict($key, 'LLI', 90, ['mention explicite'], $l);
            $this->store->recordOutcome($key, $outcome);
            if ($announced) {
                $this->store->markNotified($key, '2026-09-05T09:05:00+00:00', 'MATCH');
            }
        }

        $rows = $this->store->pendingLowScore();

        self::assertCount(1, $rows);
        self::assertSame($this->store->dedupKey($queued), $rows[0]['dedup_key']);
        self::assertSame(1, $this->store->pendingLowScoreCount());
        self::assertArrayHasKey('evidence_json', $rows[0], 'announced from its snapshot, like the digest');

        $this->store->markNotified($this->store->dedupKey($queued), '2026-09-05T10:00:00+00:00', 'ROLLUP');
        self::assertSame([], $this->store->pendingLowScore(), 'a rolled-up row leaves the queue');
    }

    /** A match covers a doubt, so a digest never re-announces something already pushed as a match. */
    public function testAMatchAnnouncementCoversTheDigestQuestionToo(): void
    {
        $listing = $this->listing(externalId: 'ANN-P2');
        $key = $this->store->dedupKey($listing);
        $this->store->record($listing, 900, '2026-08-07T09:00:00+00:00');

        $this->store->markNotified($key, '2026-08-07T09:05:00+00:00', 'MATCH');

        self::assertTrue($this->store->wasNotifiedAs($key, 'MATCH'));
        self::assertTrue($this->store->wasNotifiedAs($key, 'DIGEST'), 'DIGEST < MATCH — the order is monotone');
    }

    /**
     * The write cannot DEMOTE.
     *
     * A later digest pass on a flat already pushed as a match must not reopen it for re-
     * announcement — that is a duplicate push, and the ordering exists precisely to forbid it.
     */
    public function testAnAnnouncementIsNeverDowngraded(): void
    {
        $listing = $this->listing(externalId: 'ANN-P3');
        $key = $this->store->dedupKey($listing);
        $this->store->record($listing, 900, '2026-08-07T09:00:00+00:00');

        $this->store->markNotified($key, '2026-08-07T09:05:00+00:00', 'MATCH');
        $this->store->markNotified($key, '2026-08-08T09:05:00+00:00', 'DIGEST');

        self::assertTrue($this->store->wasNotifiedAs($key, 'MATCH'), 'a match must not be demoted to a doubt');
    }

    /** A listing nobody ever announced is not "announced as" anything. */
    public function testANeverAnnouncedListingWasNotAnnouncedAsAnything(): void
    {
        $listing = $this->listing(externalId: 'ANN-P4');
        $key = $this->store->dedupKey($listing);
        $this->store->record($listing, 900, '2026-08-07T09:00:00+00:00');

        self::assertFalse($this->store->wasNotifiedAs($key, 'DIGEST'));
        self::assertFalse($this->store->wasNotifiedAs($key, 'MATCH'));
    }

    /**
     * An UNRECOGNISED announcement kind ranks as the strongest, which is the quiet direction.
     *
     * `markNotified()` writes `$as` unvalidated, so `announcementRank()`'s `default` arm is
     * reachable by any caller passing a third string. Its docblock states the rule — *"re-announcing
     * on a value nobody understands is the loud direction in the one place §1 wants the quiet one"*
     * — and round 7 found nothing pinned it: flipping the arm so an unknown kind ranks LOWEST left
     * the whole suite green.
     *
     * The sibling rule, a pre-v8 NULL reading as MATCH, IS pinned (below, and in the ledger). This
     * is the same guarantee reached through the other door.
     */
    public function testAnUnrecognisedAnnouncementKindIsTreatedAsTheStrongest(): void
    {
        $listing = $this->listing(externalId: 'ANN-P5');
        $key = $this->store->dedupKey($listing);
        $this->store->record($listing, 900, '2026-08-07T09:00:00+00:00');

        $this->store->markNotified($key, '2026-08-07T10:00:00+00:00', 'TELEGRAPH');

        self::assertTrue(
            $this->store->wasNotifiedAs($key, 'TELEGRAPH'),
            'the kind it was actually announced as',
        );
        self::assertTrue(
            $this->store->wasNotifiedAs($key, 'DIGEST'),
            'and it outranks a digest, so nothing re-announces it as a doubt',
        );
        self::assertTrue(
            $this->store->wasNotifiedAs($key, 'MATCH'),
            'a kind nobody understands must SUPPRESS rather than re-announce — the flat may '
            . 'already be on the user\'s phone, and §1 wants the quiet direction here',
        );
    }

    /**
     * A PRE-v8 row — a timestamp with no recorded kind — reads as MATCH, and that is the quiet
     * direction on purpose.
     *
     * Those rows were announced by a version that did not record what it said. Reading them as
     * digests would re-announce the whole historic backlog as matches the moment each tenure
     * resolved, which is a flood out of the seen-set — the failure Q36 exists to prevent.
     */
    public function testAPreV8AnnouncementIsReadAsAMatchSoTheBacklogStaysQuiet(): void
    {
        // File-backed, because the pre-v8 shape can only be produced by a second connection —
        // the in-memory store this class otherwise uses is private to its own handle.
        $path = sys_get_temp_dir() . '/rentwatch-prev8-' . bin2hex(random_bytes(8)) . '.sqlite3';

        try {
            $store = Store::open($path);
            $listing = $this->listing(externalId: 'ANN-P5');
            $key = $store->dedupKey($listing);
            $store->record($listing, 900, '2026-08-07T09:00:00+00:00');
            $store->markNotified($key, '2026-08-07T09:05:00+00:00', 'DIGEST');

            // Exactly what the v8 migration leaves behind: the timestamp, and no kind.
            (new \PDO('sqlite:' . $path))->exec('UPDATE listings SET notified_as = NULL');

            self::assertTrue($store->wasNotifiedAs($key, 'MATCH'), 'an unrecorded kind must not re-announce');
            self::assertTrue($store->wasNotified($key), 'and it is still an announced listing');
        } finally {
            foreach (['', '-wal', '-shm'] as $suffix) {
                @unlink($path . $suffix);
            }
        }
    }

    // ── Source health (spec §8) ───────────────────────────────────────────────────────────────────

    /** A source that has never run is NEVER_RUN, not OK — absence of failure is not health. */
    public function testASourceThatHasNeverRunIsNotReportedHealthy(): void
    {
        self::assertSame(SourceStatus::NEVER_RUN, $this->store->health('inli')->status);
    }

    /** Three consecutive empty runs against a non-zero baseline is BROKEN (spec §8). */
    public function testThreeConsecutiveEmptyRunsAgainstANonZeroBaselineIsBroken(): void
    {
        foreach (['2026-08-01', '2026-08-02', '2026-08-03'] as $day) {
            $this->store->recordRun('inli', 12, true, null, $day . 'T09:00:00+00:00');
        }

        foreach (['2026-08-04', '2026-08-05', '2026-08-06'] as $day) {
            $this->store->recordRun('inli', 0, true, null, $day . 'T09:00:00+00:00');
        }

        $health = $this->store->health('inli');

        self::assertSame(SourceStatus::BROKEN, $health->status);
        self::assertSame(3, $health->consecutiveEmptyRuns);
    }

    /**
     * A source whose baseline IS zero is not broken for returning zero.
     *
     * A genuinely quiet source — one with no matching stock this week — must not be reported as a
     * broken selector, or the alert becomes noise and stops being read. This is the distinction
     * spec §8 draws with "when its baseline is non-zero".
     */
    public function testASourceWithAZeroBaselineIsNotBroken(): void
    {
        foreach (['2026-08-01', '2026-08-02', '2026-08-03', '2026-08-04'] as $day) {
            $this->store->recordRun('quiet_source', 0, true, null, $day . 'T09:00:00+00:00');
        }

        self::assertNotSame(SourceStatus::BROKEN, $this->store->health('quiet_source')->status);
    }

    /** A >70% drop below the rolling mean warns (spec §8). */
    public function testALargeDropBelowTheRollingMeanWarns(): void
    {
        foreach (range(1, 7) as $day) {
            $this->store->recordRun('inli', 20, true, null, sprintf('2026-08-%02dT09:00:00+00:00', $day));
        }

        $this->store->recordRun('inli', 3, true, null, '2026-08-08T09:00:00+00:00');

        self::assertSame(SourceStatus::WARN_DROP, $this->store->health('inli')->status);
    }

    /**
     * A failed fetch is a failure, not an empty result — `CLAUDE.md` hard rule 3.
     *
     * Asserted at the THRESHOLD since 2026-09-07 (a single failure is tolerated), and the point is
     * the PAIR: both shapes reach `BROKEN`, and each names its own cause. Collapsing them — an
     * empty streak reported as an exception, or an exception reported as "rien trouvé" — is the
     * confusion hard rule 3 exists to prevent, and only the two assertions together forbid it.
     */
    public function testAFailedRunIsDistinguishedFromAnEmptyOne(): void
    {
        $this->store->recordRun('inli', 12, true, null, '2026-08-01T09:00:00+00:00');

        foreach ([2, 3, 4] as $day) {
            $this->store->recordRun('inli', 0, false, 'HTTP 503', sprintf('2026-08-%02dT09:00:00+00:00', $day));
        }

        $failed = $this->store->health('inli');

        self::assertSame(SourceStatus::BROKEN, $failed->status);
        self::assertStringContainsString('HTTP 503', $failed->detail);
        self::assertStringContainsString('en échec', $failed->detail);

        $this->store->recordRun('quiet', 12, true, null, '2026-08-01T09:00:00+00:00');

        foreach ([2, 3, 4] as $day) {
            $this->store->recordRun('quiet', 0, true, null, sprintf('2026-08-%02dT09:00:00+00:00', $day));
        }

        $empty = $this->store->health('quiet');

        self::assertSame(SourceStatus::BROKEN, $empty->status);
        self::assertStringContainsString('à vide', $empty->detail);
        self::assertStringNotContainsString('en échec', $empty->detail, 'an empty streak must not be reported as an exception');
    }

    // ── Identity details the fallback key depends on ──────────────────────────────────────────────
    //
    // These cover normalisation choices made while implementing `dedupKey()`, not in the contract
    // above. Each is a way the SAME flat could come back with a different key on the next run — and
    // a key that churns means every listing looks new every run, which is the notification storm
    // from the other direction.

    /** A fragment is a scroll position, never an identity. */
    public function testTheUrlFragmentIsNotPartOfIdentity(): void
    {
        $plain = $this->listing(externalId: '', url: 'https://a.test/annonce/1');
        $anchored = $this->listing(externalId: '', url: 'https://a.test/annonce/1#photos');

        self::assertSame($this->store->dedupKey($plain), $this->store->dedupKey($anchored));
    }

    /**
     * Host case is not identity (RFC 3986); path case IS.
     *
     * Folding the path too would be the tempting simplification, and it is the wrong direction:
     * over-merging hides a flat completely, while under-merging only costs a duplicate notification.
     */
    public function testTheUrlHostIsCaseInsensitiveButThePathIsNot(): void
    {
        $lower = $this->listing(externalId: '', url: 'https://a.test/Annonce/1');
        $shoutedHost = $this->listing(externalId: '', url: 'https://A.TEST/Annonce/1');
        $shoutedPath = $this->listing(externalId: '', url: 'https://a.test/ANNONCE/1');

        self::assertSame($this->store->dedupKey($lower), $this->store->dedupKey($shoutedHost));
        self::assertNotSame($this->store->dedupKey($lower), $this->store->dedupKey($shoutedPath));
    }

    /** Landlords re-case and re-accent their own titles between runs; that is not a new flat. */
    public function testATitleRecasedOrReaccentedIsTheSameListing(): void
    {
        $a = $this->listing(externalId: '', title: 'Appartement à Cergy');
        $b = $this->listing(externalId: '', title: 'APPARTEMENT A CERGY');

        self::assertSame($this->store->dedupKey($a), $this->store->dedupKey($b));
    }

    // ── Failure paths, which must be loud ─────────────────────────────────────────────────────────

    /** A silent no-op here would leave the listing un-notified and re-notify it on every later run. */
    public function testMarkingAnUnknownListingNotifiedIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->store->markNotified('inli:id:never-recorded', '2026-08-07T09:00:00+00:00', 'MATCH');
    }

    /**
     * A timestamp that is not a full ISO-8601 instant is refused, not reinterpreted.
     *
     * `new DateTimeImmutable('')` silently means "now". Since the timestamp orders the price history
     * and defines the health window, a reinterpreted one corrupts both with no visible symptom.
     */
    public function testAnUnreadableTimestampIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->store->recordRun('inli', 3, true, null, 'hier matin');
    }

    public function testPriceHistoryOfAnUnknownListingIsEmpty(): void
    {
        self::assertSame([], $this->store->priceHistory('inli:id:never-recorded'));
    }

    /**
     * A failed run is not an empty run — the source did not answer, it did not answer "nothing".
     *
     * The streak counts the two EMPTY runs and stops there. Before 2026-09-07 this asserted `0`,
     * which conflated two different facts: a tolerated failure neither EXTENDS the streak (that
     * would be 3, and is what reading a failure as "nothing found" gives) nor RESETS it. Resetting
     * was its own defect — it bought a dead feed three more passes of silence for every hiccup, on
     * a source like leboncoin whose streak was in the hundreds.
     */
    public function testAFailedRunDoesNotExtendTheEmptyStreak(): void
    {
        $this->store->recordRun('inli', 12, true, null, '2026-08-01T09:00:00+00:00');
        $this->store->recordRun('inli', 0, true, null, '2026-08-02T09:00:00+00:00');
        $this->store->recordRun('inli', 0, true, null, '2026-08-03T09:00:00+00:00');
        $this->store->recordRun('inli', 0, false, 'timeout', '2026-08-04T09:00:00+00:00');

        $streak = $this->store->health('inli')->consecutiveEmptyRuns;

        self::assertSame(2, $streak, 'the streak is the empty runs, and the failure is neither one of them nor a reset');
        self::assertNotSame(3, $streak, 'a failed run counted as an empty one is hard rule 3 at the health layer');
    }

    /** A source that starts producing again is healthy again; the streak is trailing, not cumulative. */
    public function testASourceThatRecoversIsHealthyAgain(): void
    {
        $this->store->recordRun('inli', 12, true, null, '2026-08-01T09:00:00+00:00');

        foreach (['2026-08-02', '2026-08-03', '2026-08-04'] as $day) {
            $this->store->recordRun('inli', 0, true, null, $day . 'T09:00:00+00:00');
        }

        self::assertSame(SourceStatus::BROKEN, $this->store->health('inli')->status);

        $this->store->recordRun('inli', 11, true, null, '2026-08-05T09:00:00+00:00');

        self::assertSame(SourceStatus::OK, $this->store->health('inli')->status);
    }

    // ── Identity: nothing may collapse onto a shared key ──────────────────────────────────────────

    /**
     * `trim()` strips seven ASCII bytes and nothing else.
     *
     * U+00A0 — what a decoded `&nbsp;` produces, and what `Text` itself calls a real adapter
     * artefact — survives it. An `externalId` of one no-break space therefore passed the "does this
     * source publish an id?" test, and EVERY listing in the run collapsed onto `:id:%C2%A0`. The
     * second flat is never notified, and the miss is indistinguishable from a quiet market.
     */
    public function testAnExternalIdOfOnlyUnicodeWhitespaceIsNoIdAtAll(): void
    {
        // The last entry is the LATIN-1 spelling of `&nbsp;` — a single `\xA0` byte. It is not
        // valid UTF-8, so the Unicode trim's `/u` pattern returns null and the byte fallback is what
        // has to strip it. With a plain `trim()` it survived, made the id look non-empty, and
        // collapsed every listing in the run onto `:id:%A0`.
        // The last three are LATIN-1 bytes, not valid UTF-8: `\xA0` (&nbsp;), `\x85` (NEL) and
        // `\xAD` (soft hyphen), all of which appear in scraped text. The Unicode trim's `/u` pattern
        // returns null on them, so the byte fallback is what has to strip them — and only `\xA0`
        // was pinned, leaving the other two asserted by a code comment alone.
        foreach (["\u{00A0}", "\u{2007}", "\u{202F}", "\u{3000}", "\u{200B}", "\u{FEFF}", " \t ", "\xA0", "\x85", "\xAD"] as $blank) {
            $a = $this->listing(externalId: $blank, url: 'https://a.test/1', title: 'T2 Nanterre');
            $b = $this->listing(externalId: $blank, url: 'https://a.test/2', title: 'T4 Meudon');

            self::assertNotSame(
                $this->store->dedupKey($a),
                $this->store->dedupKey($b),
                sprintf('two distinct flats collided on a blank id (%s)', bin2hex($blank)),
            );
        }
    }

    /** A padded id is the same id — otherwise the flat looks new every run. */
    public function testAPaddedExternalIdIsTheSameId(): void
    {
        self::assertSame(
            $this->store->dedupKey($this->listing(externalId: 'ANN-42')),
            $this->store->dedupKey($this->listing(externalId: "\u{00A0}ANN-42 ")),
        );
    }

    /**
     * A listing with no id, no URL and no title is refused, loudly.
     *
     * The fallback would otherwise hash `"\n"` — a plausible-looking key that every such listing
     * shares, so the second one silently vanishes and the price history of the first interleaves
     * two flats' rents. It is the shape a broken title selector plus a broken link selector takes,
     * and the item count stays non-zero so `health()` reports OK throughout.
     */
    public function testAListingWithNothingIdentifyingIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->store->dedupKey($this->listing(externalId: '', url: '', title: ''));
    }

    // ── Out-of-order sightings, which the IMAP path makes routine ─────────────────────────────────

    /**
     * A replayed older sighting must not manufacture a price drop.
     *
     * Email-alert ingestion is the primary path (`CLAUDE.md` hard rule 4) and delivers out of
     * publication order routinely — an initial backfill, a provider-delayed alert. Comparing
     * against whatever was written LAST rather than against the chronologically preceding rent
     * fired a drop notification for a rent that had never moved.
     */
    public function testAStaleSightingManufacturesNoPriceDrop(): void
    {
        $listing = $this->listing(externalId: 'ANN-1');

        $this->store->record($listing, 1000, '2026-08-06T09:00:00+00:00');
        $this->store->record($listing, 1000, '2026-08-07T09:00:00+00:00');
        $this->store->record($listing, 1200, '2026-08-01T09:00:00+00:00');   // an archived run, replayed
        $latest = $this->store->record($listing, 1000, '2026-08-08T09:00:00+00:00');

        self::assertFalse($latest->isPriceDrop);
        self::assertSame(0, $latest->rentDeltaCc, 'the rent never moved, so the change is zero — not a 200-euro cut');
        self::assertSame([1200, 1000], $this->store->priceHistory($this->store->dedupKey($listing)));
    }

    /**
     * A superseded sighting does not count as a price drop, whatever its arithmetic says.
     *
     * The scenario needs no contradictory data: rent goes 1200 → 900 → 1000, and the 900 alert is
     * delivered last. The store answered `rentCc = 900, isPriceDrop = true` for a flat whose stored
     * current rent it correctly believed to be 1000. The ROW was hardened against this and the
     * verdict object — the thing the notifier actually reads — was left exposed.
     */
    public function testASupersededSightingDoesNotCountAsAPriceDrop(): void
    {
        $listing = $this->listing(externalId: 'ANN-1');

        $this->store->record($listing, 1200, '2026-08-07T08:00:00+00:00');
        $this->store->record($listing, 1000, '2026-08-07T12:00:00+00:00');
        $late = $this->store->record($listing, 900, '2026-08-07T10:00:00+00:00');   // delayed alert

        self::assertFalse($late->isCurrent);
        self::assertFalse($late->isPriceDrop);
        self::assertSame(1000, $this->store->snapshot($this->store->dedupKey($listing))?->rentCc);
    }

    /**
     * A REAL drop must not be swallowed by an out-of-order sighting that preceded it.
     *
     * `price_history` is changes-only, so it is not a record of observations — an observation equal
     * to its predecessor leaves no row. Reading it to answer "has the rent changed since what we
     * believe now?" made a backfilled 900 become the chronological predecessor of the real one, and
     * the 100-euro drop reported `isPriceDrop = false`. The two questions need two different
     * sources: the stored current rent, and the history.
     */
    public function testALateSightingDoesNotSwallowTheRealDropThatFollows(): void
    {
        $listing = $this->listing(externalId: 'ANN-1');

        $this->store->record($listing, 1000, '2026-08-07T10:00:00+00:00');
        $this->store->record($listing, 1000, '2026-08-07T12:00:00+00:00');
        $this->store->record($listing, 900, '2026-08-07T11:00:00+00:00');    // delayed, superseded
        $real = $this->store->record($listing, 900, '2026-08-07T13:00:00+00:00');

        self::assertTrue($real->isCurrent);
        self::assertSame(1000, $real->previousRentCc);
        self::assertSame(-100, $real->rentDeltaCc);
        self::assertTrue($real->isPriceDrop, 'a 100-euro drop went unreported');
    }

    /**
     * A stale sighting may FILL a hole it finds, though it may never overwrite.
     *
     * A run whose link selector broke, followed by a delayed alert that carries the link, otherwise
     * left the store permanently without a URL — the whole UPDATE was skipped for stale data. The
     * notify layer needs the URL and the title to produce anything actionable.
     */
    public function testAStaleSightingMayFillAMissingUrlOrTitle(): void
    {
        $broken = new RawListing(sourceName: 'inli', externalId: 'ANN-1');       // url null, title ''
        $complete = $this->listing(externalId: 'ANN-1', url: 'https://inli.test/a/1', title: 'T3 Cergy');

        $this->store->record($broken, null, '2026-08-07T09:00:00+00:00');
        $this->store->record($complete, 1200, '2026-08-06T09:00:00+00:00');      // delayed, but richer

        $snapshot = $this->store->snapshot($this->store->dedupKey($broken));

        self::assertNotNull($snapshot);
        self::assertSame('https://inli.test/a/1', $snapshot->url);
        self::assertSame('T3 Cergy', $snapshot->title);
        self::assertSame(1200, $snapshot->rentCc);
        // …but `first_seen_at` records when WE first saw it, which is what a seen-set is for.
        self::assertSame('2026-08-07T09:00:00+00:00', $snapshot->firstSeenAt);
    }

    /**
     * The changes-only invariant holds when the delta's baseline and the chronological one differ.
     *
     * They differ exactly when a sighting arrives out of order, which the email path makes routine.
     * The append guard was reading the DELTA's baseline — the stored current rent — so the final
     * 900 compared against 1000 and appended a second 900 the history already held. `price_history`
     * is one of the data sets that cannot be rebuilt, so a phantom change in it is permanent.
     */
    public function testPriceHistoryStaysChangesOnlyWhenTheTwoBaselinesDiverge(): void
    {
        $listing = $this->listing(externalId: 'ANN-1');

        $this->store->record($listing, 1000, '2026-08-07T10:00:00+00:00');
        $this->store->record($listing, 1000, '2026-08-07T12:00:00+00:00');
        $this->store->record($listing, 900, '2026-08-07T11:00:00+00:00');   // delayed, superseded
        $this->store->record($listing, 900, '2026-08-07T13:00:00+00:00');

        self::assertSame([1000, 900], $this->store->priceHistory($this->store->dedupKey($listing)));
    }

    /**
     * An adapter that returns an EMPTY url must not erase a known one.
     *
     * `COALESCE(:url, url)` only protects against `null`. A DOM attribute miss or a trimmed empty
     * `href` yields `''`, which sailed straight through and overwrote the link — and the stale-fill
     * branch beside it could not repair it, because that branch treated `''` as present too. The
     * title clause already used empty-as-missing; the url clause did not.
     */
    public function testAnEmptyUrlNeverOverwritesAKnownOne(): void
    {
        $good = $this->listing(externalId: 'ANN-1', url: 'https://inli.test/a/1', title: 'T3 Cergy');
        $blank = $this->listing(externalId: 'ANN-1', url: '', title: '');

        $this->store->record($good, 1000, '2026-08-07T09:00:00+00:00');
        $this->store->record($blank, 1000, '2026-08-08T09:00:00+00:00');

        self::assertSame('https://inli.test/a/1', $this->store->snapshot($this->store->dedupKey($good))?->url);
    }

    /** …and a delayed sighting can repair a URL that was lost before the guard existed. */
    public function testAStaleSightingRepairsAnEmptyUrl(): void
    {
        $blank = $this->listing(externalId: 'ANN-1', url: '', title: 'T3 Cergy');
        $good = $this->listing(externalId: 'ANN-1', url: 'https://inli.test/a/1', title: 'T3 Cergy');

        $this->store->record($blank, 1000, '2026-08-08T09:00:00+00:00');
        $this->store->record($good, 1000, '2026-08-07T09:00:00+00:00');   // delayed, but richer

        self::assertSame('https://inli.test/a/1', $this->store->snapshot($this->store->dedupKey($blank))?->url);
    }

    /** The changes-only invariant survives an insertion between two equal rents. */
    public function testPriceHistoryStaysChangesOnlyUnderOutOfOrderSightings(): void
    {
        $listing = $this->listing(externalId: 'ANN-1');

        $this->store->record($listing, 1000, '2026-08-06T09:00:00+00:00');
        $this->store->record($listing, 1000, '2026-08-01T09:00:00+00:00');

        self::assertSame([1000], $this->store->priceHistory($this->store->dedupKey($listing)));
    }

    /** A stale sighting does not roll the current state backwards. */
    public function testAStaleSightingDoesNotOverwriteTheCurrentState(): void
    {
        $listing = $this->listing(externalId: 'ANN-1', title: 'T3 Cergy', url: 'https://a.test/new');
        $stale = $this->listing(externalId: 'ANN-1', title: 'ANCIEN TITRE', url: 'https://a.test/old');

        $this->store->record($listing, 940, '2026-08-08T09:00:00+00:00');
        $this->store->record($stale, 1200, '2026-08-01T09:00:00+00:00');

        $snapshot = $this->store->snapshot($this->store->dedupKey($listing));

        self::assertNotNull($snapshot);
        self::assertSame('T3 Cergy', $snapshot->title);
        self::assertSame('https://a.test/new', $snapshot->url);
        self::assertSame(940, $snapshot->rentCc);
    }

    /**
     * A run whose field map partially broke must not erase what we already knew.
     *
     * The rent case was defended in the commit that introduced it; the URL and title were the same
     * bug, unnoticed. Markup drift is the premise of the whole health module, so one sighting with
     * a missed link selector is the expected case — and it left the seen-set holding a listing
     * with no link and no title, which is exactly the pair a notification needs to be actionable.
     */
    public function testAPartialReParseDoesNotEraseTheUrlOrTitle(): void
    {
        $full = $this->listing(externalId: 'ANN-1', url: 'https://a.test/annonce/1', title: 'T3 Cergy 68 m²');
        $partial = new RawListing(sourceName: 'inli', externalId: 'ANN-1');   // url null, title ''

        $this->store->record($full, 1450, '2026-08-07T09:00:00+00:00');
        $this->store->record($partial, null, '2026-08-08T09:00:00+00:00');

        $snapshot = $this->store->snapshot($this->store->dedupKey($full));

        self::assertNotNull($snapshot);
        self::assertSame('https://a.test/annonce/1', $snapshot->url);
        self::assertSame('T3 Cergy 68 m²', $snapshot->title);
        self::assertSame(1450, $snapshot->rentCc);
    }

    // ── Timestamps ────────────────────────────────────────────────────────────────────────────────

    /**
     * A trailing `Z` is the UTC instant, whatever the host timezone.
     *
     * `\Z` in a `createFromFormat` pattern is decoration: the instant is built in the DEFAULT
     * timezone and the Z discarded. On `Europe/Paris` — the natural deployment for an
     * Île-de-France tool — `…T10:30:00Z` therefore landed at 08:30 UTC, sorting BEFORE a 09:00 UTC
     * sibling written with an offset and inverting the price history, so a rise read as a drop.
     */
    public function testATrailingZIsTheUtcInstantWhateverTheHostTimezone(): void
    {
        $original = date_default_timezone_get();
        date_default_timezone_set('Europe/Paris');

        try {
            $listing = $this->listing(externalId: 'ANN-1');

            $this->store->record($listing, 1000, '2026-07-01T10:30:00Z');            // 10:30 UTC
            $this->store->record($listing, 1200, '2026-07-01T11:00:00+02:00');       // 09:00 UTC — earlier

            self::assertSame([1200, 1000], $this->store->priceHistory($this->store->dedupKey($listing)));
        } finally {
            date_default_timezone_set($original);
        }
    }

    /** A valid UTC instant inside the spring DST gap is an instant, not an error. */
    public function testAnInstantInsideTheSpringDstGapIsAccepted(): void
    {
        $original = date_default_timezone_get();
        date_default_timezone_set('Europe/Paris');

        try {
            $this->store->recordRun('inli', 4, true, null, '2026-03-29T02:30:00Z');

            self::assertSame(4, $this->store->health('inli')->lastCount);
        } finally {
            date_default_timezone_set($original);
        }
    }

    /**
     * A date that does not exist is refused rather than rolled forward.
     *
     * `createFromFormat` silently normalises `2026-02-30` to 2 March. The round-trip check is what
     * makes the parse strict, and `'hier matin'` — which `createFromFormat` rejects outright —
     * never exercised it.
     */
    /**
     * The timestamp shapes a real adapter will actually emit are accepted.
     *
     * Millisecond precision is what every JSON API and `Date.toISOString()` produces, and it was
     * rejected because the six-digit microsecond format round-trips to six digits. `-00:00` is
     * permitted by RFC 3339 §4.3 and PHP renders the same instant as `+00:00`, so it was refused on
     * spelling alone. Both are strictness aimed at the wrong target: the round-trip exists to catch
     * `2026-02-30`, not to insist on one rendering of a correct instant.
     */
    public function testTheTimestampShapesRealFeedsEmitAreAccepted(): void
    {
        $accepted = [
            '2026-08-07T09:00:00+00:00',
            '2026-08-07T09:00:00Z',
            '2026-08-07T09:00:00-00:00',
            '2026-08-07T09:00:00.123Z',
            '2026-08-07T09:00:00.123+02:00',
            '2026-08-07T09:00:00.123456Z',
            // .NET's `o` format emits seven digits; Go's RFC3339Nano emits up to nine. Both were
            // refused while the code comment claimed any width was accepted.
            '2026-08-07T09:00:00.1234567Z',
            '2026-08-07T09:00:00.123456789Z',
        ];

        foreach ($accepted as $index => $iso) {
            $this->store->recordRun('src' . $index, 3, true, null, $iso);

            self::assertSame(3, $this->store->health('src' . $index)->lastCount, $iso);
        }
    }

    public function testADateThatDoesNotExistIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->store->recordRun('inli', 3, true, null, '2026-02-30T09:00:00+00:00');
    }

    // ── Persistence, which is the entire point ────────────────────────────────────────────────────

    /**
     * The seen-set survives closing the process. This is the test that would have caught a store
     * that works perfectly in memory and writes nothing — which looks identical in every test above.
     */
    public function testTheSeenSetAndPriceHistorySurviveReopening(): void
    {
        $path = $this->temporaryDatabase();
        $listing = $this->listing(externalId: 'ANN-1');

        $first = Store::open($path);
        self::assertTrue($first->record($listing, 1000, '2026-08-07T09:00:00+00:00')->isNew);
        $first->markNotified($first->dedupKey($listing), '2026-08-07T09:05:00+00:00', 'MATCH');
        unset($first);

        $reopened = Store::open($path);
        $second = $reopened->record($listing, 940, '2026-08-08T09:00:00+00:00');

        self::assertFalse($second->isNew);
        self::assertTrue($second->isPriceDrop);
        self::assertTrue($reopened->wasNotified($reopened->dedupKey($listing)));
        self::assertSame([1000, 940], $reopened->priceHistory($reopened->dedupKey($listing)));
    }

    /**
     * A database written by NEWER code is refused rather than operated on blindly.
     *
     * Guessing at a schema you do not understand is how a seen-set gets corrupted, and the cost of
     * a corrupted seen-set is the notification storm.
     */
    public function testADatabaseFromANewerSchemaIsRefused(): void
    {
        $path = $this->temporaryDatabase();
        Store::open($path);

        $raw = new \PDO('sqlite:' . $path, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $raw->prepare("UPDATE schema_meta SET value = :v WHERE key = 'schema_version'")
            ->execute(['v' => (string) (Store::SCHEMA_VERSION + 1)]);
        unset($raw);

        $this->expectException(\RuntimeException::class);
        Store::open($path);
    }

    /**
     * A version-1 database is UPGRADED, and its data survives.
     *
     * The day `SCHEMA_VERSION` became 2 was the day this stopped being hypothetical — and the
     * column that forced it (`listings.seen_epoch`) had already been added at version 1 by mistake,
     * in the very commit that introduced the mismatch refusal. `CREATE TABLE IF NOT EXISTS` adds no
     * columns to an existing table, so a database written the day before opened without complaint
     * and threw a raw `no such column` at the first sighting.
     *
     * The fixture below is v1's schema written out literally rather than fetched from git, because
     * the point is to pin what the old shape WAS: if this ever drifts to match the current schema,
     * the test silently stops testing a migration.
     */
    public function testAVersionOneDatabaseIsUpgradedAndKeepsItsData(): void
    {
        $path = $this->temporaryDatabase();

        $raw = new \PDO('sqlite:' . $path, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $raw->exec(
            <<<'SQL'
            CREATE TABLE schema_meta (key TEXT PRIMARY KEY, value TEXT NOT NULL);
            CREATE TABLE listings (
                dedup_key TEXT PRIMARY KEY, source TEXT NOT NULL, external_id TEXT NOT NULL,
                url TEXT, title TEXT NOT NULL, rent_cc INTEGER,
                first_seen_at TEXT NOT NULL, last_seen_at TEXT NOT NULL, notified_at TEXT
            );
            CREATE TABLE price_history (
                id INTEGER PRIMARY KEY AUTOINCREMENT, dedup_key TEXT NOT NULL,
                rent_cc INTEGER NOT NULL, at TEXT NOT NULL, at_epoch INTEGER NOT NULL
            );
            CREATE TABLE source_runs (
                id INTEGER PRIMARY KEY AUTOINCREMENT, source TEXT NOT NULL, item_count INTEGER NOT NULL,
                ok INTEGER NOT NULL, error TEXT, at TEXT NOT NULL, at_epoch INTEGER NOT NULL
            );
            INSERT INTO schema_meta VALUES ('schema_version', '1');
            INSERT INTO listings VALUES
                ('inli:id:ANN-1', 'inli', 'ANN-1', 'https://a.test/1', 'T3 Cergy', 1000,
                 '2026-08-01T09:00:00+00:00', '2026-08-05T09:00:00+00:00', NULL);
            INSERT INTO price_history (dedup_key, rent_cc, at, at_epoch) VALUES
                ('inli:id:ANN-1', 1000, '2026-08-01T09:00:00+00:00', 1785315600);
            SQL
        );
        unset($raw);

        $store = Store::open($path);

        self::assertSame(Store::SCHEMA_VERSION, $store->schemaVersion());

        $snapshot = $store->snapshot('inli:id:ANN-1');
        self::assertNotNull($snapshot, 'the upgrade lost the seen-set');
        self::assertSame('T3 Cergy', $snapshot->title);
        self::assertSame([1000], $store->priceHistory('inli:id:ANN-1'));

        // The backfill must use the stored `last_seen_at`, not zero. This assertion comes FIRST,
        // because any later current sighting rewrites `seen_epoch` and erases the evidence: a
        // sighting OLDER than the migrated timestamp must read as superseded. Backfilled to 0,
        // every upgraded listing looks older than everything that arrives after it.
        $stale = $store->record(
            $this->listing(externalId: 'ANN-1', url: 'https://a.test/1', title: 'ANCIEN TITRE'),
            1500,
            '2026-08-03T09:00:00+00:00',
        );

        self::assertFalse($stale->isCurrent);
        self::assertSame('T3 Cergy', $store->snapshot('inli:id:ANN-1')?->title);

        // …and a genuinely later sighting is current, so the upgraded row is not frozen either.
        $drop = $store->record(
            $this->listing(externalId: 'ANN-1', url: 'https://a.test/1', title: 'T3 Cergy'),
            940,
            '2026-08-09T09:00:00+00:00',
        );

        self::assertFalse($drop->isNew);
        self::assertTrue($drop->isPriceDrop);
        self::assertSame(940, $store->snapshot('inli:id:ANN-1')?->rentCc);
    }

    /**
     * Every field of a snapshot is asserted, because the last time a value object shipped with
     * unasserted fields, five of them could be replaced with constants and the suite stayed green.
     */
    public function testASnapshotCarriesEveryFieldItClaims(): void
    {
        $listing = $this->listing(externalId: 'ANN-7', url: 'https://inli.test/a/7', title: 'T3 Cergy');
        $key = $this->store->dedupKey($listing);

        $this->store->record($listing, 1000, '2026-08-01T09:00:00+00:00');
        $this->store->record($listing, 940, '2026-08-05T09:00:00+00:00');
        $this->store->markNotified($key, '2026-08-05T09:05:00+00:00', 'MATCH');

        $snapshot = $this->store->snapshot($key);

        self::assertNotNull($snapshot);
        self::assertSame($key, $snapshot->dedupKey);
        self::assertSame('inli', $snapshot->sourceName);
        self::assertSame('ANN-7', $snapshot->externalId);
        self::assertSame('https://inli.test/a/7', $snapshot->url);
        self::assertSame('T3 Cergy', $snapshot->title);
        self::assertSame(940, $snapshot->rentCc);
        self::assertSame('2026-08-01T09:00:00+00:00', $snapshot->firstSeenAt);
        self::assertSame('2026-08-05T09:00:00+00:00', $snapshot->lastSeenAt);
        self::assertSame('2026-08-05T09:05:00+00:00', $snapshot->notifiedAt);
    }

    public function testASnapshotOfAnUnknownListingIsNull(): void
    {
        self::assertNull($this->store->snapshot('inli:id:never-recorded'));
    }

    /**
     * When the disk fills mid-`record()`, the operator is told the disk is full.
     *
     * SQLite auto-rolls-back on `SQLITE_FULL`, so an unguarded `PDO::rollBack()` throws "There is no
     * active transaction" and that propagates INSTEAD of the real error. Disk-full is precisely the
     * failure where the seen-set stops growing and the next run re-notifies the market, and the
     * message was about transactions.
     */
    public function testAFullDiskReportsAFullDiskAndRollsBack(): void
    {
        $store = Store::open($this->temporaryDatabase());

        // The standard SQLITE_FULL simulation is `PRAGMA max_page_count`, and it is per-CONNECTION —
        // it does not persist to a second handle — so it has to be set on the store's own. Reaching
        // for the private property is the point: `Store` must not expose a knob that exists only to
        // make a test possible. The cap CLAMPS to the current page count, so it has to be set to
        // exactly that and then exceeded by an oversized row.
        $pdo = (new \ReflectionProperty(Store::class, 'pdo'))->getValue($store);
        self::assertInstanceOf(\PDO::class, $pdo);
        $pdo->exec('PRAGMA max_page_count = ' . (int) $pdo->query('PRAGMA page_count')->fetchColumn());

        $oversized = $this->listing(externalId: 'ANN-1', title: str_repeat('x', 200_000));

        try {
            $store->record($oversized, 1000, '2026-08-07T09:00:00+00:00');
            self::fail('the capped database accepted a write');
        } catch (\PDOException $failure) {
            self::assertStringContainsString(
                'disk is full',
                $failure->getMessage(),
                'the rollback\'s own "no active transaction" error replaced the real one',
            );
        }

        // No transaction was left open, so the connection is usable afterwards.
        self::assertFalse($pdo->inTransaction());
    }

    /**
     * Running the upgrade twice is not an error — a migration that died half-way must be re-runnable.
     *
     * The first version of this test opened a store already at `SCHEMA_VERSION`, so `migrate()`
     * returned before `upgradeFrom()` was ever reached and the guard it was named for was free:
     * forcing `ALTER TABLE ADD COLUMN seen_epoch` to run unconditionally left the suite green.
     * It now starts from a real v1 database, so the upgrade path is actually entered.
     */
    public function testTheUpgradeIsIdempotent(): void
    {
        $path = $this->temporaryDatabase();
        $this->writeVersionOneDatabase($path);

        $store = Store::open($path);
        $store->migrate();
        $store->migrate();

        self::assertSame(Store::SCHEMA_VERSION, $store->schemaVersion());
        self::assertNotNull($store->snapshot('inli:id:ANN-1'));
    }

    /**
     * A legacy database whose `schema_meta` carries NO version row is upgraded, not stamped.
     *
     * That is the state a crash between the DDL and the version INSERT leaves — they are two
     * separate autocommit statements — and it is indistinguishable from a v1 database. Stamping it
     * with the current version and returning meant `CREATE TABLE IF NOT EXISTS` skipped the
     * existing table, `schemaVersion()` answered 2, and the first sighting threw a raw
     * `no such column`. Verbatim the failure the constant exists to prevent.
     */
    public function testALegacyDatabaseWithNoVersionRowIsUpgraded(): void
    {
        $path = $this->temporaryDatabase();
        $this->writeVersionOneDatabase($path, stampVersion: false);

        $store = Store::open($path);

        self::assertSame(Store::SCHEMA_VERSION, $store->schemaVersion());
        self::assertNotNull($store->snapshot('inli:id:ANN-1'));
        self::assertFalse($store->record($this->listing(externalId: 'ANN-1'), 940, '2026-08-09T09:00:00+00:00')->isNew);
    }

    /**
     * A row whose timestamp cannot be parsed must not brick the database permanently.
     *
     * One hand-edited, restored or merged row made `Store::open()` throw forever, with no repair
     * path, on the one data set that cannot be rebuilt. Treating it as the oldest thing on record
     * costs one redundant overwrite; refusing to open costs the seen-set.
     */
    public function testAnUndateableRowDoesNotBrickTheUpgrade(): void
    {
        $path = $this->temporaryDatabase();
        $this->writeVersionOneDatabase($path, lastSeenAt: '2026-08-07 09:00:00');   // not ISO-8601

        $store = Store::open($path);

        self::assertSame(Store::SCHEMA_VERSION, $store->schemaVersion());
        self::assertNotNull($store->snapshot('inli:id:ANN-1'));
    }

    /** `Store::open()` creates a missing parent directory rather than failing. */
    public function testTheDatabaseDirectoryIsCreatedWhenAbsent(): void
    {
        $base = $this->temporaryDatabase();
        unlink($base);
        $nested = $base . '.d/state/rent-watch.sqlite3';

        // Registered BEFORE the assertions, not cleaned up after them: inline cleanup is skipped by
        // any run that reddens an assertion, which is every sabotage run — 48 real databases were
        // left in /tmp by the time a reviewer counted them.
        $this->temporaryPaths[] = $nested;
        $this->temporaryDirectories[] = \dirname($nested);
        $this->temporaryDirectories[] = \dirname($nested, 2);

        $store = Store::open($nested);

        self::assertSame(Store::SCHEMA_VERSION, $store->schemaVersion());
        self::assertFileExists($nested);
    }

    /** Both PRAGMAs actually take effect — `journal_mode` in particular is a QUERY pragma. */
    public function testTheConcurrencyPragmasAreApplied(): void
    {
        $store = Store::open($this->temporaryDatabase());
        $pdo = (new \ReflectionProperty(Store::class, 'pdo'))->getValue($store);

        self::assertInstanceOf(\PDO::class, $pdo);
        self::assertSame('wal', $store->journalMode());
        self::assertSame('wal', $pdo->query('PRAGMA journal_mode')->fetchColumn());
        self::assertSame(Store::BUSY_TIMEOUT_MS, (int) $pdo->query('PRAGMA busy_timeout')->fetchColumn());
    }

    /**
     * `journalMode()` reports what SQLite ACTUALLY gave us, not what we asked for.
     *
     * The method's whole justification is the read-back — `journal_mode` is a query pragma and
     * SQLite does not raise when it refuses, so a network mount silently stays in rollback mode.
     * Every assertion was satisfied by hardcoding `'wal'` until this case existed: `:memory:`
     * legitimately answers something else, which is the cheapest available proof of a real read.
     */
    public function testJournalModeReportsWhatSqliteActuallyGave(): void
    {
        self::assertSame('memory', Store::open(':memory:')->journalMode());
    }

    /**
     * A second writer WAITS rather than failing instantly.
     *
     * This is the demonstration `BUSY_TIMEOUT_MS` is justified by, and it was cited by name in that
     * constant's docblock before it existed. Without the timeout SQLite returns
     * `SQLSTATE[HY000]: General error: 5 database is locked` immediately; with it, the second
     * connection blocks and retries. Two processes are designed behaviour here — `run --watch`
     * alongside a manual `doctor` — so waiting is correct, not a retry papered over a race.
     *
     * The timeout is lowered on the second connection so the test costs a fraction of a second
     * rather than five; what is being asserted is that it waited AT ALL.
     */
    public function testASecondWriterWaitsRatherThanFailing(): void
    {
        $path = $this->temporaryDatabase();
        $holder = Store::open($path);
        $second = Store::open($path);

        $holderPdo = (new \ReflectionProperty(Store::class, 'pdo'))->getValue($holder);
        $secondPdo = (new \ReflectionProperty(Store::class, 'pdo'))->getValue($second);
        self::assertInstanceOf(\PDO::class, $holderPdo);
        self::assertInstanceOf(\PDO::class, $secondPdo);

        $secondPdo->exec('PRAGMA busy_timeout = 400');
        $holderPdo->exec('BEGIN IMMEDIATE');            // takes the write lock and keeps it

        $startedAt = hrtime(true);

        try {
            $second->record($this->listing(externalId: 'ANN-1'), 1000, '2026-08-07T09:00:00+00:00');
            self::fail('the second writer got the lock while the first was holding it');
        } catch (\PDOException $failure) {
            $waitedMs = (hrtime(true) - $startedAt) / 1_000_000;

            self::assertStringContainsString('locked', $failure->getMessage());
            self::assertGreaterThan(300, $waitedMs, 'the second writer gave up instantly instead of waiting');
        } finally {
            $holderPdo->exec('ROLLBACK');
        }
    }

    /**
     * A forward-skewed FIRST run must not disable the wrong-field-map detector.
     *
     * `$span` was `last − first` over rows in INSERTION order, so a first run stamped in the future
     * made it negative — and a negative span can never satisfy the floor, switching `NEVER_PRODUCED`
     * off for the life of the database. The first run after a boot is the one most likely to be
     * skewed, which is what makes this the reachable case rather than the exotic one.
     */
    public function testASkewedFirstRunDoesNotDisableTheFieldMapDetector(): void
    {
        $this->store->recordRun('newsource', 0, true, null, '2036-01-01T09:00:00+00:00');

        foreach (range(1, 20) as $day) {
            $this->store->recordRun('newsource', 0, true, null, sprintf('2026-08-%02dT09:00:00+00:00', $day));
        }

        self::assertSame(SourceStatus::NEVER_PRODUCED, $this->store->health('newsource')->status);
    }

    /**
     * Write a database with the EXACT v1 schema — no `seen_epoch` — so the migration is real.
     *
     * Written out literally rather than fetched from git: if this ever drifts to match the current
     * schema, every test above silently stops testing a migration.
     */
    private function writeVersionOneDatabase(
        string $path,
        bool $stampVersion = true,
        string $lastSeenAt = '2026-08-05T09:00:00+00:00',
    ): void {
        $raw = new \PDO('sqlite:' . $path, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $raw->exec(
            <<<'SQL'
            CREATE TABLE schema_meta (key TEXT PRIMARY KEY, value TEXT NOT NULL);
            CREATE TABLE listings (
                dedup_key TEXT PRIMARY KEY, source TEXT NOT NULL, external_id TEXT NOT NULL,
                url TEXT, title TEXT NOT NULL, rent_cc INTEGER,
                first_seen_at TEXT NOT NULL, last_seen_at TEXT NOT NULL, notified_at TEXT
            );
            CREATE TABLE price_history (
                id INTEGER PRIMARY KEY AUTOINCREMENT, dedup_key TEXT NOT NULL,
                rent_cc INTEGER NOT NULL, at TEXT NOT NULL, at_epoch INTEGER NOT NULL
            );
            CREATE TABLE source_runs (
                id INTEGER PRIMARY KEY AUTOINCREMENT, source TEXT NOT NULL, item_count INTEGER NOT NULL,
                ok INTEGER NOT NULL, error TEXT, at TEXT NOT NULL, at_epoch INTEGER NOT NULL
            );
            SQL
        );

        if ($stampVersion) {
            $raw->exec("INSERT INTO schema_meta VALUES ('schema_version', '1')");
        }

        $raw->prepare(
            'INSERT INTO listings VALUES (:k, :s, :e, :u, :t, :r, :f, :l, NULL)',
        )->execute([
            'k' => 'inli:id:ANN-1', 's' => 'inli', 'e' => 'ANN-1', 'u' => 'https://a.test/1',
            't' => 'T3 Cergy', 'r' => 1000, 'f' => '2026-08-01T09:00:00+00:00', 'l' => $lastSeenAt,
        ]);
        $raw->exec(
            "INSERT INTO price_history (dedup_key, rent_cc, at, at_epoch)
             VALUES ('inli:id:ANN-1', 1000, '2026-08-01T09:00:00+00:00', 1785315600)",
        );
    }

    /** A database path whose parent is a regular file fails with this class's own message. */
    public function testADatabasePathThatTraversesAFileIsRefused(): void
    {
        $file = $this->temporaryDatabase();

        $this->expectException(\RuntimeException::class);
        Store::open($file . '/store.db');
    }

    /** @var list<string> */
    private array $temporaryPaths = [];

    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryPaths as $path) {
            // WAL leaves `-wal` and `-shm` sidecars beside the database. They are real files, they
            // hold un-checkpointed pages including `source_runs.error` text, and forgetting them
            // here is the same forgetting that left them out of `.gitignore`.
            foreach ([$path, $path . '-wal', $path . '-shm', $path . '-journal'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }

        // Deepest first, so a nested tree comes apart.
        foreach ($this->temporaryDirectories as $directory) {
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }

        $this->temporaryPaths = [];
        $this->temporaryDirectories = [];
    }

    private function temporaryDatabase(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'rentwatch-store-');

        if ($path === false) {
            self::fail('impossible de créer un fichier temporaire pour le test');
        }

        $this->temporaryPaths[] = $path;

        return $path;
    }

    // ── Source health: the two ways a dead source reported OK ─────────────────────────────────────

    /**
     * A source that breaks after a GAP longer than the rolling window is still broken.
     *
     * `rollingMeanBefore()` anchors its window on the first empty run of the streak, so any gap
     * longer than the window — a holiday, a reclaimed container, a temporarily disabled source —
     * left it with nothing to average and returned null. Null is "I do not know what normal looks
     * like for this source", and it shared a branch with "normal is zero": ten consecutive empty
     * runs against a documented 25-listing history reported `OK`, detail *"0 annonces au dernier
     * run"*. The identical sequence WITHOUT the gap alerted correctly, which is what made it
     * invisible — it works in the test and fails in the field.
     */
    public function testASourceThatBreaksAfterALongGapIsStillReportedBroken(): void
    {
        foreach (range(1, 14) as $day) {
            $this->store->recordRun('inli', 25, true, null, sprintf('2026-08-%02dT09:00:00+00:00', $day));
        }

        // Nine days off — longer than ROLLING_WINDOW_DAYS. The site redesigns meanwhile.
        foreach (['2026-08-23', '2026-08-24', '2026-08-25'] as $day) {
            $this->store->recordRun('inli', 0, true, null, $day . 'T09:00:00+00:00');
        }

        $health = $this->store->health('inli');

        self::assertSame(SourceStatus::BROKEN, $health->status);
        self::assertStringContainsString('25', $health->detail);
    }

    /**
     * ONE run from a skewed clock must not hide every run that follows it.
     *
     * Timestamps are caller-supplied and never checked against a clock — deliberate, since health
     * can only be tested if time is an argument. The cost is that a run stamped from a bad clock
     * (NTP not yet synced after a resume, a VM restored from a snapshot, a caller passing the
     * listing's published date instead of the run time) is accepted. It must then be inert, not
     * poisonous.
     *
     * **This test replaces one that asserted the opposite**, and the replacement is not a
     * weakening — the old contract was demonstrated to make the failure worse. It refused any run
     * stamped before one already logged, which did nothing about the FIRST skewed run (nothing
     * checks a clock) and discarded the real runs that followed. Three outright failures went
     * unrecorded and health still said `OK`, now with `lastFailureAt` null: the freeze survived and
     * the evidence of it did not. Recency is read from the log's own insertion order instead.
     */
    public function testASkewedClockDoesNotHideTheRunsThatFollowIt(): void
    {
        $this->store->recordRun('inli', 25, true, null, '2036-01-01T09:00:00+00:00');   // skewed clock

        foreach (['2026-08-05', '2026-08-06', '2026-08-07'] as $day) {
            $this->store->recordRun('inli', 0, false, 'HTTP 503', $day . 'T09:00:00+00:00');
        }

        $health = $this->store->health('inli');

        self::assertSame(SourceStatus::BROKEN, $health->status);
        self::assertSame('2026-08-07T09:00:00+00:00', $health->lastFailureAt);
        self::assertSame(4, $health->totalRuns, 'a log that drops entries is not a log');
    }

    /** A run dated far in the future must not sit inside the rolling window forever. */
    public function testASkewedRunDoesNotStayInTheRollingWindow(): void
    {
        $this->store->recordRun('inli', 900, true, null, '2036-01-01T09:00:00+00:00');

        foreach (range(1, 5) as $day) {
            $this->store->recordRun('inli', 10, true, null, sprintf('2026-08-%02dT09:00:00+00:00', $day));
        }

        $health = $this->store->health('inli');

        self::assertSame(SourceStatus::OK, $health->status);
        // Both the mean and the count exclude it. `health()` is called here WITHOUT a clock, so the
        // window's upper edge falls back to the last-inserted stamp — bounded and self-correcting.
        // Leaving the count unbounded instead made a future-stamped row alert forever.
        self::assertSame(10.0, $health->rollingMean, 'the 2036 run inflated the mean of every later verdict');
        self::assertSame(5, $health->runsInWindow);
    }

    /**
     * One QUIET run between the productive history and the streak must not zero the baseline.
     *
     * The first fix here fell back to "the last SUCCESSFUL run of any age", which includes a run
     * that legitimately returned nothing. A single such run set the baseline to zero, `$baseline >
     * 0.0` went false, and a source with a 25-listing history went silent for three runs and
     * reported `OK` — the same defect one step deeper. A quiet run is not evidence that nothing is
     * normal here; a productive one is evidence that something was.
     */
    public function testAQuietRunBeforeTheStreakDoesNotZeroTheBaseline(): void
    {
        $this->store->recordRun('inli', 25, true, null, '2026-08-01T09:00:00+00:00');
        $this->store->recordRun('inli', 0, true, null, '2026-08-02T09:00:00+00:00');    // a quiet day
        $this->store->recordRun('inli', 0, false, 'HTTP 503', '2026-08-03T09:00:00+00:00');

        // …then an outage longer than the rolling window, and the site redesigns meanwhile.
        foreach (['2026-09-10', '2026-09-11', '2026-09-12'] as $day) {
            $this->store->recordRun('inli', 0, true, null, $day . 'T09:00:00+00:00');
        }

        $health = $this->store->health('inli');

        self::assertSame(SourceStatus::BROKEN, $health->status);
        self::assertStringContainsString('25', $health->detail);
    }

    /** The same, with productive and empty runs alternating before the gap. */
    public function testAnAlternatingHistoryAcrossAGapIsStillABaseline(): void
    {
        foreach ([[1, 30], [2, 0], [3, 28], [4, 0]] as [$day, $count]) {
            $this->store->recordRun('inli', $count, true, null, sprintf('2026-08-%02dT09:00:00+00:00', $day));
        }

        foreach (['2026-09-20', '2026-09-21', '2026-09-22'] as $day) {
            $this->store->recordRun('inli', 0, true, null, $day . 'T09:00:00+00:00');
        }

        self::assertSame(SourceStatus::BROKEN, $this->store->health('inli')->status);
    }

    /**
     * A quiet run BEHIND a retained outage must not zero the baseline — and this is the only shape
     * in which `lastProductiveCount()`'s `item_count > 0` guard is observable at all.
     *
     * The two tests above are named for that guard and are VACUOUS with respect to it. Both put a
     * single failure (or none) between the quiet run and the streak; `observedRuns()` drops a
     * failure episode shorter than `EMPTY_RUNS_BEFORE_BROKEN`, so the quiet run is absorbed INTO
     * the trailing streak rather than sitting before it, `trailingEmptyRuns()` never breaks, and
     * `rollingMeanBefore()` answers before the fallback is reached. Deleting the guard left the
     * whole suite green — the ledger case reported detection it did not have until this test
     * existed, exactly as `testAToleratedFailureDoesNotShiftTheBaselineWindow` records for its own.
     *
     * The reaching shape needs three things at once: an outage AT the threshold, so it is RETAINED
     * and breaks the streak, leaving the quiet run outside it; a gap longer than the rolling
     * window, so `rollingMeanBefore()` returns null and the fallback runs at all; and a productive
     * run older still, so the fallback has something to find.
     */
    public function testAQuietRunBehindARetainedOutageDoesNotZeroTheBaseline(): void
    {
        // Sized off the constants rather than a literal 3 or 7: at a raised threshold the outage
        // would fall under the retention bar and this test would rejoin its two vacuous siblings.
        $base = new \DateTimeImmutable('2026-08-01T09:00:00+00:00');
        $at = static fn (int $offset): string => $base->modify(sprintf('+%d days', $offset))->format('Y-m-d\TH:i:sP');

        $this->store->recordRun('inli', 25, true, null, $at(0));
        $this->store->recordRun('inli', 0, true, null, $at(1));    // a quiet day, not a broken one

        for ($i = 0; $i < RunStore::EMPTY_RUNS_BEFORE_BROKEN; ++$i) {
            $this->store->recordRun('inli', 0, false, 'HTTP 503', $at(2 + $i));
        }

        // …then a gap longer than the rolling window, and the source comes back saying nothing.
        $resume = 2 + RunStore::EMPTY_RUNS_BEFORE_BROKEN + RunStore::ROLLING_WINDOW_DAYS + 1;

        for ($i = 0; $i < RunStore::EMPTY_RUNS_BEFORE_BROKEN; ++$i) {
            $this->store->recordRun('inli', 0, true, null, $at($resume + $i));
        }

        $health = $this->store->health('inli');

        // THE PREMISE, asserted rather than assumed: the outage BROKE the streak, so the quiet run
        // is outside it. One more and this is the siblings' shape again, proving nothing.
        self::assertSame(
            RunStore::EMPTY_RUNS_BEFORE_BROKEN,
            $health->consecutiveEmptyRuns,
            'the quiet run was absorbed into the streak — this test is back to proving nothing',
        );

        self::assertSame(SourceStatus::BROKEN, $health->status);
        // The digit boundary, because a bare '25' is satisfied by '125.0' — the remainder-line
        // lesson of 2026-09-07 on a second surface.
        self::assertMatchesRegularExpression('/(?<![0-9])25\.0 annonces/', $health->detail);
    }

    /**
     * The rolling window really is seven days.
     *
     * Every surface added alongside `WARN_FLAKY` — `runsInWindow`, `failedRunsInWindow`,
     * `failureRate()` — hangs off this cutoff, and nothing tested it: failures from two months ago
     * counted forever, so a source that has succeeded daily since could be reported flaky.
     */
    public function testTheRollingWindowExcludesRunsOlderThanSevenDays(): void
    {
        foreach (range(1, 5) as $day) {
            $this->store->recordRun('inli', 0, false, 'HTTP 503', sprintf('2026-06-%02dT09:00:00+00:00', $day));
        }

        foreach (range(1, 5) as $day) {
            $this->store->recordRun('inli', 12, true, null, sprintf('2026-08-%02dT09:00:00+00:00', $day));
        }

        $health = $this->store->health('inli');

        self::assertSame(5, $health->runsInWindow, 'the June failures are outside the seven-day window');
        self::assertSame(0, $health->failedRunsInWindow);
        self::assertSame(0.0, $health->failureRate());
        self::assertSame(SourceStatus::OK, $health->status);
    }

    /**
     * An out-of-range timestamp in the MIDDLE of the log is skipped, not treated as the end of it.
     *
     * The rows come back in insertion order, not timestamp order, so a backward scan that STOPS at
     * the first row outside the window (`break`) silently truncates the history at whatever sits
     * next to the bad row. Only a run whose timestamp is out of range *and* has older runs behind
     * it can tell the two apart — which is why the first version of this went undetected.
     */
    public function testAnOutOfRangeRunInTheMiddleOfTheLogIsSkippedNotFatal(): void
    {
        $this->store->recordRun('inli', 100, true, null, '2026-08-01T09:00:00+00:00');
        $this->store->recordRun('inli', 100, true, null, '2026-08-02T09:00:00+00:00');
        $this->store->recordRun('inli', 900, true, null, '2036-01-01T09:00:00+00:00');   // skewed clock
        $this->store->recordRun('inli', 10, true, null, '2026-08-03T09:00:00+00:00');
        $this->store->recordRun('inli', 10, true, null, '2026-08-04T09:00:00+00:00');
        $this->store->recordRun('inli', 10, true, null, '2026-08-05T09:00:00+00:00');

        $health = $this->store->health('inli');

        // All four in-window predecessors, not just the two on the near side of the bad row.
        self::assertSame(55.0, $health->rollingMean);
        self::assertSame(5, $health->runsInWindow, 'the out-of-range run is outside the window');
    }

    /**
     * A source that succeeds and never once produces an item is not healthy.
     *
     * The twin of NEVER_RUN. A source onboarded with a wrong field map answers HTTP 200 and parses
     * zero items, forever: it never fails so nothing alerts, and its baseline really is zero so the
     * empty-run rule correctly declines to fire. Thirty days of `OK` for a source that has never
     * worked.
     */
    public function testASourceThatNeverProducesAnythingIsNotReportedHealthy(): void
    {
        foreach (range(1, 30) as $day) {
            $this->store->recordRun('newsource', 0, true, null, sprintf('2026-08-%02dT09:00:00+00:00', $day));
        }

        $health = $this->store->health('newsource');

        self::assertSame(SourceStatus::NEVER_PRODUCED, $health->status);
        self::assertTrue($health->status->isAlerting());
    }

    /**
     * A source whose SCHEDULE has stopped is not healthy, and only a clock can tell.
     *
     * `NEVER_RUN` covered "never" and nothing covered "stopped". A container reclaimed, a cron
     * entry removed, a systemd timer disabled. One successful run three hundred days ago reported
     * `OK`, non-alerting, forever — the same silent-absence class as `NEVER_PRODUCED`, reached from
     * the other end. `health()` takes the current time as an argument because this class never
     * reads the clock; omit it and the check does not run.
     */
    public function testASourceWhoseScheduleHasStoppedIsStale(): void
    {
        $this->store->recordRun('inli', 25, true, null, '2026-01-01T09:00:00+00:00');

        self::assertSame(SourceStatus::OK, $this->store->health('inli')->status);
        self::assertSame(
            SourceStatus::STALE,
            $this->store->health('inli', '2026-11-01T09:00:00+00:00')->status,
        );
        // …and a source that ran this morning is not stale.
        self::assertSame(
            SourceStatus::OK,
            $this->store->health('inli', '2026-01-02T09:00:00+00:00')->status,
        );
    }

    /**
     * A forward-skewed run must not make a stopped schedule look healthy.
     *
     * Silence is measured from the newest run that is NOT stamped in the future. Measuring from the
     * last-inserted row instead made `$silentFor` negative whenever that row carried a bad clock,
     * so a source that had not run for a year reported `OK` — the same silent-absence class
     * `STALE` was added to close, arrived at through the fix for it.
     */
    public function testASkewedRunDoesNotMaskAStoppedSchedule(): void
    {
        $this->store->recordRun('inli', 25, true, null, '2026-01-01T09:00:00+00:00');
        $this->store->recordRun('inli', 25, true, null, '2036-01-01T09:00:00+00:00');   // skewed clock

        self::assertSame(
            SourceStatus::STALE,
            $this->store->health('inli', '2026-11-01T09:00:00+00:00')->status,
        );
    }

    /**
     * A source onboarded forty-five minutes ago is not told its field map is wrong.
     *
     * Three successful empty polls at a fifteen-minute interval satisfied `NEVER_PRODUCED` with no
     * time floor at all, and In'li LLI stock in one commune is legitimately empty for days.
     */
    public function testANewlyOnboardedSourceIsNotAccusedOfABadFieldMap(): void
    {
        foreach (['09:00', '09:15', '09:30', '09:45'] as $time) {
            $this->store->recordRun('newsource', 0, true, null, '2026-08-07T' . $time . ':00+00:00');
        }

        self::assertNotSame(SourceStatus::NEVER_PRODUCED, $this->store->health('newsource')->status);
    }

    /**
     * A run committed late but stamped earlier must not erase a BROKEN verdict.
     *
     * `--watch` alongside a manual `doctor` is the spec's own target usage, so two processes writing
     * the same source is designed behaviour — and then insertion order and timestamp order disagree.
     * Reading recency purely from insertion order let one success logged after three failures, but
     * stamped a minute before the last of them, report `OK` with `isAlerting()` false. Reading it
     * purely from the timestamp is the round-10 P0 in the other direction. A clock settles it, which
     * is what `$nowIso` is for.
     */
    public function testALateCommittedRunDoesNotEraseABrokenVerdict(): void
    {
        foreach (range(1, 7) as $day) {
            $this->store->recordRun('inli', 25, true, null, sprintf('2026-08-%02dT09:00:00+00:00', $day));
        }

        foreach ([8, 9, 10] as $day) {
            $this->store->recordRun('inli', 0, false, 'HTTP 502', sprintf('2026-08-%02dT09:00:00+00:00', $day));
        }

        self::assertSame(SourceStatus::BROKEN, $this->store->health('inli', '2026-08-10T10:00:00+00:00')->status);

        // Committed last, but it happened BEFORE the final failure and says so. Two writers make
        // this reachable: `--watch` alongside a manual `doctor`.
        $this->store->recordRun('inli', 25, true, null, '2026-08-10T08:59:00+00:00');

        // The LAST-RUN rule goes quiet — this run reads as the latest and it succeeded — but the
        // source does NOT read healthy, because `windowCounts()` still sees three failures in the
        // window. That is the difference between the two rules, and it is why the counting window
        // is bounded on the old side only.
        $quieted = $this->store->health('inli', '2026-08-10T10:00:00+00:00');

        self::assertSame(SourceStatus::WARN_FLAKY, $quieted->status);
        self::assertTrue($quieted->status->isAlerting(), 'a late-committed success silenced the alert');

        // …and three polls later the sharper verdict is back. THREE since 2026-09-07: one failure
        // after a success is tolerated, so restoring `BROKEN` now takes a streak — which is the
        // guarantee this half is really about, that the sharper verdict RETURNS.
        foreach (['09:15:00', '09:30:00', '09:45:00'] as $time) {
            $this->store->recordRun('inli', 0, false, 'HTTP 502', '2026-08-10T' . $time . '+00:00');
        }

        $health = $this->store->health('inli', '2026-08-10T10:00:00+00:00');

        self::assertSame(SourceStatus::BROKEN, $health->status);
        self::assertTrue($health->status->isAlerting());
    }

    /**
     * A SLOW clock must not discard real runs, silently or otherwise.
     *
     * Filtering the log by `at_epoch <= $nowIso` reported `OK` on a source in sustained failure
     * whenever the clock was the thing that was wrong — a CLI building `$nowIso` with `gmdate()`
     * while `recordRun()` used `date('c')` on a Paris host is a two-hour skew and an ordinary bug.
     * Worse, the discard was invisible: `totalRuns` counted only the survivors. The clock now
     * touches exactly one verdict.
     */
    public function testASlowClockDoesNotDiscardRealRuns(): void
    {
        $this->store->recordRun('inli', 25, true, null, '2026-08-10T09:00:00+00:00');

        foreach (['09:15', '09:30', '09:45'] as $time) {
            $this->store->recordRun('inli', 0, false, 'Connection refused', '2026-08-10T' . $time . ':00+00:00');
        }

        // The clock is two hours behind every failure it is being asked about.
        $health = $this->store->health('inli', '2026-08-10T08:00:00+00:00');

        self::assertSame(SourceStatus::BROKEN, $health->status);
        self::assertSame(4, $health->totalRuns, 'runs were discarded, and nothing said so');
        self::assertSame('2026-08-10T09:45:00+00:00', $health->lastFailureAt);
    }

    /**
     * A run stamped in the future changes NOTHING outside the STALE branch.
     *
     * This test used to claim the clock disqualified such a run — it does not, and has not since
     * recency went back to insertion order. It passed identically with the clock argument removed,
     * so it would have stayed green whether clock filtering were re-introduced or deleted: a test
     * shaped exactly like the dead safety code this project removes on sight. It now asserts the
     * property that is actually load-bearing, in both directions.
     */
    public function testAFutureStampedRunDoesNotChangeAnyVerdictButStale(): void
    {
        foreach (range(1, 7) as $day) {
            $this->store->recordRun('inli', 25, true, null, sprintf('2026-08-%02dT09:00:00+00:00', $day));
        }

        $this->store->recordRun('inli', 25, true, null, '2036-01-01T09:00:00+00:00');   // skewed

        foreach ([8, 9, 10] as $day) {
            $this->store->recordRun('inli', 0, false, 'HTTP 502', sprintf('2026-08-%02dT09:00:00+00:00', $day));
        }

        $withClock = $this->store->health('inli', '2026-08-10T10:00:00+00:00');
        $without = $this->store->health('inli');

        self::assertSame(SourceStatus::BROKEN, $without->status);
        self::assertSame($without->status, $withClock->status, 'the clock changed a non-STALE verdict');
        self::assertSame('2026-08-10T09:00:00+00:00', $withClock->lastFailureAt);
    }

    /** A log where EVERY run is in the future is a clock problem, and says so. */
    public function testALogEntirelyInTheFutureIsReportedAsAClockProblem(): void
    {
        $this->store->recordRun('inli', 25, true, null, '2036-01-01T09:00:00+00:00');

        $health = $this->store->health('inli', '2026-08-10T10:00:00+00:00');

        self::assertSame(SourceStatus::STALE, $health->status);
        self::assertStringContainsString('horloge', $health->detail);
    }

    /**
     * A writer that stamps stale SYSTEMATICALLY must not keep a source quiet indefinitely.
     *
     * Two writers on one source — an HTTP adapter and an email-alert adapter can carry the same
     * name, and the email path stamps from a lagging `Date:` header — with one succeeding on stale
     * stamps. The last-run rule stays quiet because the last-INSERTED row is the success, and two
     * comments in `Store` claimed that self-corrects "on the next run". It does not, under
     * repetition: eleven consecutive real failures read `OK` indefinitely, because the window's
     * upper bound counted `failedRunsInWindow` as 0 of 11. Counting is now bounded on the old side
     * only — a failure is a failure whatever its clock says — so `WARN_FLAKY` catches it.
     */
    public function testASystematicallyStaleWriterCannotKeepASourceQuiet(): void
    {
        $day = static fn (int $i): string => sprintf('2026-08-%02dT09:00:00+00:00', $i);

        $this->store->recordRun('inli', 20, true, null, $day(1));

        for ($i = 2; $i <= 12; ++$i) {
            $this->store->recordRun('inli', 0, false, 'boom', $day($i));   // real failure, real stamp
            $this->store->recordRun('inli', 18, true, null, $day(1));      // stale-stamped success
        }

        $health = $this->store->health('inli', $day(13));

        self::assertTrue($health->status->isAlerting(), 'eleven real failures reported as healthy');
        self::assertSame(SourceStatus::WARN_FLAKY, $health->status);
        // Seven, not eleven: the clock puts the window's cutoff at day 6, so the four failures
        // before it age out exactly as they should. What matters is that NONE of the stale-stamped
        // successes dilute the ratio — they are older than the cutoff too.
        self::assertSame(7, $health->failedRunsInWindow);
        self::assertSame(7, $health->runsInWindow);
    }

    /**
     * Future-stamped SUCCESSES must not dilute a genuinely flaky source back to healthy.
     *
     * Twenty successes stamped a year ahead pushed a source with seven failures in twenty-one real
     * runs from `WARN_FLAKY` to `OK` — hard rule 2's headline failure, reached by inflating the
     * denominator. A row stamped after the current time has not happened yet.
     */
    public function testFutureStampedSuccessesDoNotDiluteAFlakySource(): void
    {
        foreach (range(1, 21) as $i) {
            $ok = $i % 3 !== 0;
            $this->store->recordRun('inli', $ok ? 12 : 0, $ok, $ok ? null : 'HTTP 502',
                sprintf('2026-08-%02dT09:00:00+00:00', $i));
        }

        foreach (range(1, 20) as $i) {
            $this->store->recordRun('inli', 12, true, null, sprintf('2027-08-%02dT09:00:00+00:00', $i));
        }

        $health = $this->store->health('inli', '2026-08-21T10:00:00+00:00');

        self::assertSame(SourceStatus::WARN_FLAKY, $health->status);
        self::assertTrue($health->status->isAlerting());
    }

    /**
     * Future-stamped FAILURES must not alert forever, either.
     *
     * Ten failures logged while the clock read 2036 — a dead RTC, a pre-NTP boot — kept
     * `WARN_FLAKY` on through ninety healthy days, with a detail line claiming ten failures "en 7
     * jours" when the last seven days held none. An alert that never clears is one the operator
     * learns to ignore, which puts a genuinely broken source back to indistinguishable from quiet.
     */
    public function testFutureStampedFailuresDoNotAlertForever(): void
    {
        foreach (range(1, 10) as $i) {
            $this->store->recordRun('inli', 0, false, 'HTTP 502', sprintf('2036-01-%02dT09:00:00+00:00', $i));
        }

        foreach (range(1, 28) as $i) {
            $this->store->recordRun('inli', 12, true, null, sprintf('2026-09-%02dT09:00:00+00:00', $i));
        }

        $health = $this->store->health('inli', '2026-09-28T10:00:00+00:00');

        self::assertSame(SourceStatus::OK, $health->status);
        self::assertSame(0, $health->failedRunsInWindow);
    }

    /**
     * A source failing half its fetches is not healthy either.
     *
     * `trailingEmptyRuns()` resets on any success and the BROKEN rule reads only the last run, so
     * half the market missed every day was indistinguishable from a working source.
     */
    public function testASourceFailingHalfItsRunsIsFlagged(): void
    {
        foreach ([[1, true], [2, false], [3, true], [4, false], [5, true], [6, false], [7, true]] as [$day, $ok]) {
            $this->store->recordRun(
                'inli',
                $ok ? 12 : 0,
                $ok,
                $ok ? null : 'HTTP 503',
                sprintf('2026-08-%02dT09:00:00+00:00', $day),
            );
        }

        $health = $this->store->health('inli');

        self::assertSame(SourceStatus::WARN_FLAKY, $health->status);
        self::assertSame(3, $health->failedRunsInWindow);
        self::assertSame(7, $health->runsInWindow);
    }

    /**
     * Every field of the verdict carries evidence, and every one is asserted.
     *
     * Five of `SourceHealth`'s fields could be replaced with constants and the whole suite stayed
     * green — including `lastSuccessAt`, `lastCount` and `rollingMean`, the three `CLAUDE.md` hard
     * rule 2 names by name. An unasserted field is a field that can silently become wrong, and the
     * class exists so a verdict can be argued with.
     */
    public function testHealthCarriesTheEvidenceForItsVerdict(): void
    {
        $this->store->recordRun('inli', 20, true, null, '2026-08-01T09:00:00+00:00');
        $this->store->recordRun('inli', 0, false, 'HTTP 503', '2026-08-02T09:00:00+00:00');
        $this->store->recordRun('inli', 18, true, null, '2026-08-03T09:00:00+00:00');
        $this->store->recordRun('inli', 19, true, null, '2026-08-04T09:00:00+00:00');

        $health = $this->store->health('inli');

        self::assertSame(SourceStatus::OK, $health->status);
        self::assertSame('inli', $health->sourceName);
        self::assertSame('2026-08-04T09:00:00+00:00', $health->lastSuccessAt);
        self::assertSame('2026-08-02T09:00:00+00:00', $health->lastFailureAt);
        self::assertSame(19, $health->lastCount);
        self::assertSame(19.0, $health->rollingMean);          // mean of 20 and 18, before the last run
        self::assertSame(4, $health->runsInWindow);
        self::assertSame(1, $health->failedRunsInWindow);
        self::assertSame(4, $health->totalRuns);
        self::assertSame(0.25, $health->failureRate());
        self::assertSame(0, $health->consecutiveEmptyRuns);
    }

    /** Exactly one status means "nothing to tell the user". */
    public function testEveryStatusExceptOkAlerts(): void
    {
        foreach (SourceStatus::cases() as $status) {
            self::assertSame(
                $status !== SourceStatus::OK,
                $status->isAlerting(),
                sprintf('%s alerts when it should not, or vice versa', $status->value),
            );
        }
    }

    /**
     * An adapter's error text is masked before it is persisted or shown.
     *
     * `recordRun()` stores it, `health()` interpolates it into the detail, and `isAlerting()` says
     * that detail should reach the user — so a cURL error carrying the IDFM key, or an IMAP failure
     * carrying the mailbox, would put a credential into the database and into a push notification
     * through a channel nobody would think to grep (`CLAUDE.md` hard rule 7). The store is the
     * single funnel every adapter passes through, so the guard belongs here.
     */
    public function testCredentialsInAnAdapterErrorAreMaskedBeforeTheyReachTheUser(): void
    {
        $this->store->recordRun(
            'transit',
            0,
            false,
            'GET https://prim.iledefrance-mobilites.fr/marketplace/v2?apikey=SECRET123456 failed for jean.dupont@example.com',
            '2026-08-07T09:00:00+00:00',
        );

        $detail = $this->store->health('transit')->detail;

        self::assertStringNotContainsString('SECRET123456', $detail);
        self::assertStringNotContainsString('jean.dupont@example.com', $detail);
        self::assertStringContainsString('prim.iledefrance-mobilites.fr', $detail);   // still diagnosable
    }

    /** @param array<string,string> $fields */
    private function listing(
        string $source = 'inli',
        string $externalId = 'ANN-1',
        string $url = 'https://inli.test/annonce/1',
        string $title = 'T3 Cergy',
        array $fields = [],
    ): RawListing {
        return new RawListing(
            sourceName: $source,
            externalId: $externalId,
            title: $title,
            description: 'Bel appartement.',
            fields: $fields,
            url: $url,
        );
    }
}
