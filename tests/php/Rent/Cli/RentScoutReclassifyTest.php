<?php

declare(strict_types=1);

namespace Scout\Tests\Rent\Cli;

use PHPUnit\Framework\TestCase;
use Scout\Rent\Cli\RentScout;
use Scout\Core\Notify\ConsoleChannel;
use Scout\Core\Notify\Notifier;
use Scout\Tests\Support\DeliveringChannel;
use Scout\Rent\Core\RawListing;
use Scout\Rent\Core\Tenure;
use Scout\Rent\Store\Store;

/**
 * `scout reclassify` — Q35, and the §1 surface of the two commands that close Bucket A.
 *
 * The problem it solves is a permanent silent miss. The seen-set guarantees a listing is new
 * exactly once, so a listing digested as `UNKNOWN` under a classifier that is later improved is
 * never surfaced again by anything. Q18 (PLI) and Q21 (a shouted `PLUS`) both route there
 * deliberately, so that bin is not small.
 *
 * **The invariant is `reclassify runs on evidence ⊇ original, never ⊂`, and it is not a quality
 * preference — it is what stops this command being a §1 breach.** A card whose structured field
 * says `PLS` while its title says *logement intermédiaire* classifies `UNKNOWN` today BY CONFLICT.
 * Re-run on the title alone it becomes a MATCH. So a reclassify that degrades an evidence-less row
 * to whatever text is lying around does not make a smaller improvement: it manufactures the one
 * outcome §1 forbids, and it does it preferentially to the listings most likely to be social,
 * because those are the ones whose evidence conflicts.
 *
 * {@see testARowWithNoStoredEvidenceIsSkippedAndCountedNeverJudgedOnLess} is the case that pins it.
 *
 * The command re-JUDGES rather than merely re-classifying: Q35's promotion test is on `Outcome`,
 * which only the criteria engine produces, so each row runs the classifier AND the full judge
 * against TODAY's criteria.
 */
final class RentScoutReclassifyTest extends TestCase
{
    private const NOW = '2026-08-23T21:00:00+02:00';

    /** @var list<string> */
    private array $roots = [];

    protected function setUp(): void
    {
    }

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            self::removeTree($root);
        }
        $this->roots = [];
        putenv('RENT_SCOUT_DB');
    }

    public function testAnImprovedVerdictIsRecordedAndAPromotionIsNotified(): void
    {
        $root = $this->tempRoot();
        // Stored UNKNOWN, but its snapshot says `logement intermédiaire` in plain words: exactly the
        // row Q35 exists for — one an improved classifier can now resolve.
        $key = $this->seed($root, new RawListing(
            sourceName: 'demo',
            externalId: 'A-1',
            title: 'T4 lumineux',
            description: 'Logement intermédiaire (LLI), attribution directe par le bailleur.',
            commune: 'Sartrouville',
            postcode: '78500',
            rentCc: 1450,
            surfaceM2: 88.0,
            rooms: 4,
        ), tenure: 'UNKNOWN', outcome: 'DIGEST');

        $result = $this->scout($root, ['reclassify'], $this->delivering());

        self::assertSame(0, $result['code'], $result['err']);
        self::assertStringContainsString('1 promotion', mb_strtolower($result['out']));

        $store = Store::open($root . '/state/rent-watch.sqlite3');
        self::assertSame('MATCH', $store->outcome($key));
        self::assertTrue($store->wasNotified($key), 'a promoted listing is notified — that is the miss Q35 recovers');
    }

    public function testARowWithNoStoredEvidenceIsSkippedAndCountedNeverJudgedOnLess(): void
    {
        $root = $this->tempRoot();
        $key = $this->seed($root, new RawListing(
            sourceName: 'demo',
            externalId: 'B-2',
            // The title alone reads as an eligible intermediate listing. The evidence that made this
            // row UNKNOWN — a structured field saying PLS — is exactly what a pre-v7 row no longer
            // has, so judging it on this title is how a social listing becomes a match.
            title: 'Logement intermédiaire T4',
            commune: 'Sartrouville',
            postcode: '78500',
            rentCc: 1450,
            surfaceM2: 88.0,
            rooms: 4,
        ), tenure: 'UNKNOWN', outcome: 'DIGEST');
        $this->stripSnapshot($root, $key);

        $result = $this->scout($root, ['reclassify']);

        self::assertSame(0, $result['code'], $result['err']);
        self::assertStringContainsString('sans instantané', $result['out']);

        $store = Store::open($root . '/state/rent-watch.sqlite3');
        self::assertSame('DIGEST', $store->outcome($key), 'an evidence-less row keeps its verdict — it is not re-judged on less');
        self::assertFalse($store->wasNotified($key), 'and it is certainly never notified');
    }

    public function testACorruptSnapshotIsReportedWithoutVoidingTheRun(): void
    {
        $root = $this->tempRoot();
        $damaged = $this->seed($root, new RawListing(
            sourceName: 'demo',
            externalId: 'C-3',
            title: 'T4 Antony',
            rentCc: 1300,
        ), tenure: 'UNKNOWN', outcome: 'DIGEST');
        $this->corruptSnapshot($root, $damaged);

        $healthy = $this->seed($root, new RawListing(
            sourceName: 'demo',
            externalId: 'C-4',
            title: 'T4 lumineux',
            description: 'Logement intermédiaire (LLI), attribution directe par le bailleur.',
            commune: 'Sartrouville',
            postcode: '78500',
            rentCc: 1450,
            surfaceM2: 88.0,
            rooms: 4,
        ), tenure: 'UNKNOWN', outcome: 'DIGEST');

        $result = $this->scout($root, ['reclassify'], $this->delivering());

        // Loud AND scoped. One damaged row voiding the whole run is the blast-radius mistake detail
        // hydration already made once: a single bad page must not stop every other listing being
        // judged.
        self::assertStringContainsString('illisible', $result['out'] . $result['err']);

        $store = Store::open($root . '/state/rent-watch.sqlite3');
        self::assertSame('DIGEST', $store->outcome($damaged), 'the damaged row keeps its verdict untouched');
        self::assertSame('MATCH', $store->outcome($healthy), 'and the healthy one is still judged');
    }

    public function testTodaysCriteriaDecideTheOutcomeSoATightenedCeilingRejects(): void
    {
        // Re-judging means TODAY's criteria, not the ones in force when the row was stored. A flat
        // whose tenure now resolves cleanly can still fail a ceiling that has since been lowered —
        // and it must record REJECT rather than be notified on a stale rule.
        $root = $this->tempRoot(['max_rent_cc' => 1200]);
        $key = $this->seed($root, new RawListing(
            sourceName: 'demo',
            externalId: 'D-4',
            title: 'T4 lumineux',
            description: 'Logement intermédiaire (LLI), attribution directe par le bailleur.',
            commune: 'Sartrouville',
            postcode: '78500',
            rentCc: 1450,
            surfaceM2: 88.0,
            rooms: 4,
        ), tenure: 'UNKNOWN', outcome: 'DIGEST');

        $result = $this->scout($root, ['reclassify']);

        self::assertSame(0, $result['code'], $result['err']);

        $store = Store::open($root . '/state/rent-watch.sqlite3');
        self::assertSame('REJECT', $store->outcome($key));
        self::assertFalse($store->wasNotified($key), 'a rejection is recorded silently, never announced');
    }

    public function testAMemberThatWasNeverJudgedGetsAVerdictButNoOutcome(): void
    {
        // Every dedup MEMBER is classified; only the SURVIVOR is judged. `NULL` outcome is precisely
        // what distinguishes "never judged" from "judged and rejected", and reclassify must not
        // manufacture an outcome for a row the criteria engine never saw.
        $root = $this->tempRoot();
        $key = $this->seed($root, new RawListing(
            sourceName: 'demo',
            externalId: 'E-5',
            title: 'T4 lumineux',
            description: 'Logement intermédiaire (LLI), attribution directe par le bailleur.',
            commune: 'Sartrouville',
            postcode: '78500',
            rentCc: 1450,
            surfaceM2: 88.0,
            rooms: 4,
        ), tenure: 'UNKNOWN', outcome: null);

        $this->scout($root, ['reclassify']);

        $store = Store::open($root . '/state/rent-watch.sqlite3');
        self::assertNull($store->outcome($key), 'an unjudged member stays unjudged');
        self::assertFalse($store->wasNotified($key));
    }

    public function testAListingWhoseSourceNoLongerExistsIsJudgedFailClosed(): void
    {
        // A source removed from sources.json leaves its listings behind. With no profile the
        // classifier must assume mixed tenure and no default — the digest-biased direction — never
        // the reverse, which would let a vanished landlord's stock resolve as eligible by omission.
        $root = $this->tempRoot();
        $key = $this->seed($root, new RawListing(
            sourceName: 'a_landlord_since_removed',
            externalId: 'F-6',
            title: 'T4 sans mention de régime',
            commune: 'Sartrouville',
            postcode: '78500',
            rentCc: 1450,
            surfaceM2: 88.0,
            rooms: 4,
        ), tenure: 'UNKNOWN', outcome: 'DIGEST');

        $result = $this->scout($root, ['reclassify']);

        self::assertSame(0, $result['code'], $result['err']);

        $store = Store::open($root . '/state/rent-watch.sqlite3');
        self::assertSame('DIGEST', $store->outcome($key), 'no signal and no profile digests, it never matches');
        self::assertFalse($store->wasNotified($key));
        // THE TENURE, not just the outcome. `mixedTenure: true` is inert while `defaultTenure` is
        // null — tier 5 is the only sub-floor tier and it needs a non-null default, and every other
        // route to "eligible below the floor" is forced to UNKNOWN by the conflict rule — so the
        // outcome assertion above cannot see the half of the fail-closed profile that actually
        // bites. A default of LLI would make this row `LLI`, and `LLI` plus a non-mixed source is a
        // MATCH. Asserting the stored tenure is what makes that flip visible.
        self::assertSame('UNKNOWN', $this->tenureOf($root, $key));
    }

    public function testNothingIsWrittenWhenAPromotionCannotBeDelivered(): void
    {
        // The retry the warning promises has to be REAL. Writing the verdict before the send removes
        // the row from `staleVerdicts()` (its tenure resolves) AND from `pendingDigest()` (its
        // outcome is no longer DIGEST), and there is no third selector — so a failed send left a
        // MATCH nobody was told about that no command could reach again. The population is exactly
        // the one that cannot be rescued by the pipeline either: a still-published listing is
        // re-judged next pass, but these commands exist for the listing that has since delisted.
        $root = $this->tempRoot(['notify' => ['channels' => ['ntfy']]]);
        putenv('RENT_NTFY_TOPIC=rent-watch-test');
        putenv('NTFY_SERVER=http://127.0.0.1:1');

        $key = $this->seed($root, $this->intermediateListing('H-8'), tenure: 'UNKNOWN', outcome: 'DIGEST');

        $result = $this->scout($root, ['reclassify']);

        self::assertSame(1, $result['code'], 'an undelivered promotion must exit non-zero');

        $store = Store::open($root . '/state/rent-watch.sqlite3');
        self::assertSame('DIGEST', $store->outcome($key), 'the outcome must not move before the channel confirms');
        self::assertSame('UNKNOWN', $this->tenureOf($root, $key), 'nor the tenure — that is what keeps it in staleVerdicts()');
        self::assertFalse($store->wasNotified($key));

        // The actual retry, exercised rather than asserted about: a second run with a working
        // channel must find and announce it. That channel has to be one that genuinely REACHES a
        // recipient — it was `console` until round 7, then `email` over a file transport until
        // round 8, and neither delivers anything, so the retry proved itself either way.
        putenv('RENT_NTFY_TOPIC');
        putenv('NTFY_SERVER');
        $second = $this->scout($root, ['reclassify'], $this->delivering());

        self::assertSame(0, $second['code'], $second['err']);
        self::assertStringContainsString('1 promotion', mb_strtolower($second['out']));
        self::assertSame('MATCH', $store->outcome($key));
        self::assertTrue($store->wasNotified($key));
    }

    public function testAnUnusableChannelRefusesBeforeAnyRowIsTouched(): void
    {
        // The refusal has to come FIRST. Built after the loop — as it was until 2026-08-24 — a
        // deploy whose RENT_NTFY_TOPIC is not yet filled in re-judged everything, rewrote every verdict,
        // and only then discovered it had nowhere to send: one run consumed the entire promotable
        // backlog while printing a message about an environment variable. `run`, `digest` and
        // `test-notify` all refuse before doing the work; this was the one verb that did not.
        $root = $this->tempRoot(['notify' => ['channels' => ['ntfy']]]);
        putenv('RENT_NTFY_TOPIC');
        putenv('NTFY_SERVER');

        $key = $this->seed($root, $this->intermediateListing('K-1'), tenure: 'UNKNOWN', outcome: 'DIGEST');

        $result = $this->scout($root, ['reclassify']);

        self::assertSame(2, $result['code'], 'no usable channel is a startup refusal');
        self::assertStringNotContainsString(
            're-jugée(s)',
            $result['out'],
            'the refusal must precede the work, not report it',
        );

        $store = Store::open($root . '/state/rent-watch.sqlite3');
        self::assertSame('DIGEST', $store->outcome($key));
        self::assertSame('UNKNOWN', $this->tenureOf($root, $key));
    }

    public function testDryRunNeedsNoChannelAtAll(): void
    {
        // The counterweight to the refusal above. `--dry-run` sends nothing, so demanding a working
        // channel would refuse the one command whose whole purpose is to look before touching
        // anything — and would do it on the machine least likely to have a channel configured yet.
        $root = $this->tempRoot(['notify' => ['channels' => ['ntfy']]]);
        putenv('RENT_NTFY_TOPIC');
        putenv('NTFY_SERVER');

        $this->seed($root, $this->intermediateListing('K-2'), tenure: 'UNKNOWN', outcome: 'DIGEST');

        $result = $this->scout($root, ['reclassify', '--dry-run']);

        self::assertSame(0, $result['code'], $result['err']);
        self::assertStringContainsString('re-jugée(s)', $result['out']);
    }

    public function testAListingThatWasRejectedAndNowQualifiesIsAnnounced(): void
    {
        // REJECT -> MATCH is not a demotion, and it is reachable the moment the criteria widen —
        // Q1-Q3 widened three filters in one day. `docs/OPEN-QUESTIONS.md` already rules that a
        // listing which was disqualified and now qualifies IS a new match; recording it silently
        // stranded it exactly like an undelivered promotion.
        $root = $this->tempRoot();
        $key = $this->seed($root, $this->intermediateListing('I-9'), tenure: 'UNKNOWN', outcome: 'REJECT');

        $result = $this->scout($root, ['reclassify'], $this->delivering());

        self::assertSame(0, $result['code'], $result['err']);
        self::assertStringContainsString('1 promotion', mb_strtolower($result['out']));

        $store = Store::open($root . '/state/rent-watch.sqlite3');
        self::assertSame('MATCH', $store->outcome($key));
        self::assertTrue($store->wasNotified($key));
    }

    public function testTheUnreadableCountIsReportedNotJustThePerRowWarning(): void
    {
        // The per-row warning says WHICH row; the count says HOW MANY, and only the count survives
        // a run with hundreds of rows scrolling past. A database quietly losing snapshots is
        // otherwise indistinguishable from one with nothing to re-judge.
        $root = $this->tempRoot();
        foreach (['J-1', 'J-2'] as $id) {
            $this->corruptSnapshot($root, $this->seed(
                $root,
                new RawListing(sourceName: 'demo', externalId: $id, title: 'T4'),
                tenure: 'UNKNOWN',
                outcome: 'DIGEST',
            ));
        }

        $result = $this->scout($root, ['reclassify']);

        self::assertStringContainsString('2 instantané(s) illisible(s)', $result['out']);
    }

    public function testDryRunChangesNothing(): void
    {
        $root = $this->tempRoot();
        $key = $this->seed($root, new RawListing(
            sourceName: 'demo',
            externalId: 'G-7',
            title: 'T4 lumineux',
            description: 'Logement intermédiaire (LLI), attribution directe par le bailleur.',
            commune: 'Sartrouville',
            postcode: '78500',
            rentCc: 1450,
            surfaceM2: 88.0,
            rooms: 4,
        ), tenure: 'UNKNOWN', outcome: 'DIGEST');

        $result = $this->scout($root, ['reclassify', '--dry-run']);

        self::assertSame(0, $result['code'], $result['err']);

        $store = Store::open($root . '/state/rent-watch.sqlite3');
        self::assertSame('DIGEST', $store->outcome($key));
        self::assertFalse($store->wasNotified($key));
    }

    public function testSinceIsRefusedRatherThanGivenInventedSemantics(): void
    {
        // Q35 names `--since`, and its staleness mechanism is a stored classifier version that does
        // not exist. Answering it against `last_seen_at` would fake the ruling's mechanism with a
        // different one; refusing says so out loud, and re-running the whole bin costs seconds.
        $root = $this->tempRoot();

        $result = $this->scout($root, ['reclassify', '--since=2026-08-01']);

        self::assertSame(2, $result['code']);
        self::assertStringContainsString('--since', $result['err']);
    }

    // ── harness ───────────────────────────────────────────────────────────────────────────────────

    /** The listing every promotion test starts from: eligible, and eligible for a stated reason. */
    /**
     * A listing its CLUSTER vetoed must not be resurrected by `reclassify`.
     *
     * **This is the round-4 cluster veto being undone by a shipped command.** The pipeline judges a
     * cluster on its most restrictive member, but stores each member's OWN tenure and OWN snapshot
     * — so a vetoed survivor whose card states no tenure is left `tenure = 'UNKNOWN'`,
     * `outcome = 'REJECT'`. `staleVerdicts()` selects on `tenure` alone, so `reclassify` picked it
     * up and re-judged it on its own snapshot, in which the sibling's `PLS` cannot appear.
     *
     * That is the invariant this command exists to hold, read exactly: **evidence ⊇ original,
     * never ⊂.** The cluster's evidence is part of the original. A review panel drove it end to end
     * on 2026-08-24 through the real pipeline and the real commands.
     */
    public function testAListingItsClusterVetoedIsNotResurrected(): void
    {
        $root = $this->tempRoot();

        // The survivor: a card stating no tenure at all, rejected by its cluster rather than by
        // anything in its own text.
        $survivor = new RawListing(
            sourceName: 'demo',
            externalId: 'VETO-1',
            title: 'T4 lumineux',
            description: 'Quatre pieces, 88 m2, ascenseur.',
            commune: 'Sartrouville',
            postcode: '78500',
            rentCc: 1450,
            surfaceM2: 88.0,
            rooms: 4,
        );
        $sibling = new RawListing(
            sourceName: 'other',
            externalId: 'VETO-2',
            title: 'T4 lumineux',
            description: 'Financement PLS, commission d attribution.',
            fields: ['financement' => 'PLS'],
            commune: 'Sartrouville',
            postcode: '78500',
            rentCc: 1450,
            surfaceM2: 88.0,
            rooms: 4,
        );

        $key = $this->seed($root, $survivor, 'UNKNOWN', 'REJECT');
        $siblingKey = $this->seed($root, $sibling, 'PLS', null);

        $store = Store::open($root . '/state/rent-watch.sqlite3');
        $store->assignGroup([$key, $siblingKey]);

        $r = $this->scout($root, ['reclassify']);

        self::assertSame(0, $r['code']);
        self::assertStringNotContainsString(
            'promotion(s) vers MATCH.' . PHP_EOL,
            str_replace('0 promotion(s) vers MATCH.', '', $r['out']),
            'a vetoed listing must never be promoted',
        );
        self::assertSame(
            'REJECT',
            $this->pdo($root)
                ->query("SELECT outcome FROM listings WHERE dedup_key = '" . $key . "'")
                ->fetchColumn(),
            'the cluster veto must survive a re-judgement that cannot see the evidence which caused it',
        );
        self::assertStringContainsString(
            'écartée(s) par un doublon',
            $r['out'],
            'the skip must be counted out loud — a silent skip is indistinguishable from a bug',
        );
    }

    /**
     * The counterweight: a clustered listing whose siblings are ELIGIBLE is still re-judged.
     *
     * Without this the veto above is one character from skipping every clustered row in the store
     * — and over-rejection is the invisible direction, because nothing arrives to notice. The
     * sabotage ledger proved the gap: reading the group's tenures as excluded regardless of what
     * they say left the whole suite green.
     */
    /**
     * THE OTHER TRACK'S WORD IS EVIDENCE TOO (schema v12, 2026-08-30). A row vetoed by its
     * cross-track twin sits at `tenure = UNKNOWN`, `outcome = REJECT` exactly like a group-vetoed
     * one, and `staleVerdicts()` selects on `tenure` alone — so without this read the command
     * re-judged it on a snapshot in which the twin's PLS cannot appear and PROMOTED it. The
     * round-2 ledger proved the read undetected until this test existed.
     */
    public function testAListingVetoedByItsCrossTrackTwinIsSkippedNotRejudged(): void
    {
        $root = $this->tempRoot();
        $key = $this->seed($root, $this->intermediateListing('TWIN-1'), 'UNKNOWN', 'REJECT');
        Store::open($root . '/state/rent-watch.sqlite3')->recordTwin($key, Tenure::PLS, 'cdc_habitat', 90);

        $r = $this->scout($root, ['reclassify'], $this->delivering());

        self::assertSame(0, $r['code'], $r['err']);
        self::assertStringContainsString('écartée(s) par un doublon', $r['out'], 'the skip is counted out loud');
        self::assertStringNotContainsString('1 annonce(s) re-jugée(s)', $r['out'], 'never re-judged on a snapshot the twin cannot appear in');
        self::assertSame('REJECT', $this->outcomeOf($root, $key), 'the veto stands');
    }

    public function testAListingWhoseTwinIsUndeterminedIsNotPromoted(): void
    {
        // The doubt travels the same way: a twin the pipeline could not classify keeps this row
        // out of the matches until both are judged together again.
        $root = $this->tempRoot();
        $key = $this->seed($root, $this->intermediateListing('TWIN-2'), 'UNKNOWN', 'DIGEST');
        Store::open($root . '/state/rent-watch.sqlite3')->recordTwin($key, Tenure::UNKNOWN, 'cdc_habitat', 90);

        $r = $this->scout($root, ['reclassify'], $this->delivering());

        self::assertSame(0, $r['code'], $r['err']);
        self::assertStringContainsString('écartée(s) par un doublon', $r['out']);
        self::assertNotSame('MATCH', $this->outcomeOf($root, $key), 'a doubt on the other track blocks promotion');
    }
    /**
     * THE FOURTH PERSISTED ROUTE, and `reclassify` is the third surface that ANNOUNCES.
     *
     * `ExcludedDwellings`'s docblock said it "has TWO callers that must never disagree" — the
     * pipeline and the digest drain. There are three: this command FORMS a verdict, promotes
     * `DIGEST -> MATCH` and pushes it. The dwelling route is precisely the one with no group edge
     * and no twin — a portal re-advertising the same flat under a new ad id, same source, so
     * `Dedup` refuses the edge — which is why the two vetoes above cannot see it.
     *
     * The drain's own warning tells the operator to run this command. Before this test it was the
     * documented remedy for a row it would then push.
     *
     * Terminal, which is why it is P0 rather than a lost digest: after the push the row holds a
     * resolved tenure (out of `staleVerdicts()`) and `outcome = MATCH` (out of `pendingDigest()`),
     * so no command reaches it again.
     */
    public function testAFlatOnRecordAsExcludedUnderAnotherAdIdIsNotPromoted(): void
    {
        $root = $this->tempRoot();
        $key = $this->seed($root, $this->intermediateListing('READVERT-NEW'), 'UNKNOWN', 'DIGEST');
        $this->seedExcludedDwelling($root, $this->intermediateListing('READVERT-OLD'), Tenure::PLS);

        $delivering = $this->delivering();
        $result = $this->scout($root, ['reclassify'], $delivering);

        self::assertSame(0, $result['code']);
        self::assertSame([], $delivering->sent, 'a flat on record as PLS under another ad id must not be pushed');
        self::assertSame('DIGEST', $this->outcomeOf($root, $key), 'the row must stay in the digest backlog');
        self::assertStringContainsString('écartée', $result['out']);
    }

    /**
     * The counterweight, and it is what stops the fix being "veto everything".
     *
     * A DIFFERENT flat on record as PLS must not veto this one — otherwise a single excluded row
     * anywhere in the store would freeze every promotion, which is §1 satisfied by switching the
     * command off. Same seed as the case above, one dwelling apart.
     */
    public function testAnUnrelatedExcludedFlatDoesNotVetoAPromotion(): void
    {
        $root = $this->tempRoot();
        $key = $this->seed($root, $this->intermediateListing('READVERT-NEW'), 'UNKNOWN', 'DIGEST');
        $elsewhere = new RawListing(
            sourceName: 'demo',
            externalId: 'OTHER-FLAT',
            title: 'Studio',
            description: 'Un studio ailleurs.',
            commune: 'Dourdan',
            postcode: '91410',
            rentCc: 700,
            surfaceM2: 28.0,
            rooms: 1,
        );
        $this->seedExcludedDwelling($root, $elsewhere, Tenure::PLS);

        $delivering = $this->delivering();
        $result = $this->scout($root, ['reclassify'], $delivering);

        self::assertSame(0, $result['code']);
        self::assertCount(1, $delivering->sent, 'an unrelated excluded flat must not veto this promotion');
        self::assertSame('MATCH', $this->outcomeOf($root, $key));
    }

    /**
     * `--reopen` reported THREE routes while `reclassify` judges by FOUR.
     *
     * On the population the fourth exists for — a flat re-advertised under a new ad id, which by
     * construction has no group edge and no twin — the line read `jumeau : aucun · groupe : aucun`
     * and so told the operator nothing linked the row. The dwelling reading is NOT cleared, for the
     * group veto's exact reason: it is another listing's own reading, and clearing it here would
     * delete a §1 fact belonging to a row the operator did not name.
     */
    public function testReopenNamesTheSameDwellingRouteAndDoesNotClearIt(): void
    {
        $root = $this->tempRoot();
        $key = $this->seed($root, $this->intermediateListing('REOPEN-NEW'), 'PLS', 'REJECT');
        $this->seedExcludedDwelling($root, $this->intermediateListing('REOPEN-OLD'), Tenure::PLS);

        $delivering = $this->delivering();
        $result = $this->scout($root, ['reclassify', '--reopen=' . $key], $delivering);

        self::assertSame(0, $result['code'], $result['err']);
        self::assertStringContainsString('même logement : PLS', $result['out'], 'the fourth route must be named');
        self::assertStringContainsString('PAS effacé', $result['err'], 'and the operator must be told it survives');
        self::assertSame([], $delivering->sent, 'reopening must not push a flat the dwelling route still vetoes');
    }

    /**
     * THE EXCLUSION THIS RUN RESOLVES MUST VETO THE SIBLING THIS RUN PROMOTES.
     *
     * Round 1 hoisted `excludedDwellings()` above the loop, on the reasoning that the candidate set
     * is a property of the store. True of `Pipeline` — which persists every reading BEFORE loading
     * the set — and false here: `reclassify` writes `tenure` inside the loop, so an exclusion it
     * resolves itself never enters the set, and the next row describing that dwelling is judged
     * against a set that predates it. Two panel lenses executed the push independently.
     *
     * BOTH rows start UNKNOWN, which is the whole point: this is the population the command exists
     * for after a classifier change, not a forged state. The old ad states its PLS ceiling, the new
     * one states LLI. Ordering is deliberately not relied on — `staleVerdicts()` orders
     * `seen_epoch DESC`, so the NEW ad is judged first in the natural case, which is why the veto
     * is applied to the PROMOTION rather than in a second judging pass.
     */
    public function testAnExclusionResolvedInThisRunVetoesItsOwnSibling(): void
    {
        $root = $this->tempRoot();
        $old = new RawListing(
            sourceName: 'demo',
            externalId: 'SAME-OLD',
            title: 'T4 lumineux',
            description: 'Le logement est soumis au plafond de ressources PLS.',
            commune: 'Sartrouville',
            postcode: '78500',
            rentCc: 1450,
            surfaceM2: 88.0,
            rooms: 4,
        );
        $this->seed($root, $old, 'UNKNOWN', 'DIGEST');
        $newKey = $this->seed($root, $this->intermediateListing('SAME-NEW'), 'UNKNOWN', 'DIGEST');

        $delivering = $this->delivering();
        $result = $this->scout($root, ['reclassify'], $delivering);

        self::assertSame(0, $result['code'], $result['err']);
        self::assertSame(
            [],
            $delivering->sent,
            'the same dwelling resolved PLS in this very run must veto the promotion of its sibling',
        );
        self::assertSame('DIGEST', $this->outcomeOf($root, $newKey), 'and the sibling stays in the backlog');
    }

    /**
     * The counterweight, and it is what stops the re-check being "veto every promotion".
     *
     * An unrelated flat resolved PLS in the same run must not touch this promotion. Without it the
     * fix above is satisfied by refusing everything, which is §1 by switching the command off.
     */
    public function testAnUnrelatedExclusionResolvedInThisRunLeavesThePromotionAlone(): void
    {
        $root = $this->tempRoot();
        $elsewhere = new RawListing(
            sourceName: 'demo',
            externalId: 'OTHER-PLS',
            title: 'Studio',
            description: 'Le logement est soumis au plafond de ressources PLS.',
            commune: 'Dourdan',
            postcode: '91410',
            rentCc: 700,
            surfaceM2: 28.0,
            rooms: 1,
        );
        $this->seed($root, $elsewhere, 'UNKNOWN', 'DIGEST');
        $key = $this->seed($root, $this->intermediateListing('UNRELATED-NEW'), 'UNKNOWN', 'DIGEST');

        $delivering = $this->delivering();
        $result = $this->scout($root, ['reclassify'], $delivering);

        self::assertSame(0, $result['code'], $result['err']);
        self::assertCount(1, $delivering->sent, 'an unrelated exclusion must not veto this promotion');
        self::assertSame('MATCH', $this->outcomeOf($root, $key));
    }

    /**
     * `--reopen` MUST SURVIVE A CORRUPT SNAPSHOT — it is the documented one way back.
     *
     * Round 1 added an unguarded `evidence()` call to `Store::reopen()` for the dwelling provenance.
     * `evidence()` throws on a damaged snapshot by design, `reopen()` runs ABOVE the loop, and none
     * of the CLI's catch arms covers `JsonException` — so the whole command died with a raw stack
     * trace, cleared nothing, and re-judged nothing else either. The repo's other two `evidence()`
     * call sites both catch that pair; this made reopen the only unguarded one.
     */
    public function testReopenSurvivesACorruptSnapshotAndStillClearsTheReading(): void
    {
        $root = $this->tempRoot();
        $key = $this->seed($root, $this->intermediateListing('CORRUPT-1'), 'PLS', 'REJECT');
        $this->corruptSnapshot($root, $key);

        $result = $this->scout($root, ['reclassify', '--reopen=' . $key], $this->delivering());

        self::assertSame(0, $result['code'], $result['err']);
        self::assertStringContainsString('réouverture', $result['out']);
        self::assertNull($this->tenureOf($root, $key), 'the reading must still be cleared');
    }

    /** A row on record with an EXCLUDED tenure and a snapshot — what `excludedDwellings()` returns. */
    private function seedExcludedDwelling(string $root, RawListing $listing, Tenure $tenure): string
    {
        $store = Store::open($root . '/state/rent-watch.sqlite3');
        $sighting = $store->record($listing, $listing->effectiveRentCc(), self::NOW);
        $store->recordVerdict($sighting->dedupKey, $tenure->value, 90, ['régime exclu relevé'], $listing);
        $store->recordOutcome($sighting->dedupKey, 'REJECT');

        return $sighting->dedupKey;
    }

    public function testAClusteredListingWithEligibleSiblingsIsStillRejudged(): void
    {
        $root = $this->tempRoot();

        $listing = $this->intermediateListing('PAIR-1');
        $sibling = new RawListing(
            sourceName: 'other',
            externalId: 'PAIR-2',
            title: 'T4 lumineux',
            description: 'Logement intermédiaire (LLI), attribution directe par le bailleur.',
            commune: 'Sartrouville',
            postcode: '78500',
            rentCc: 1450,
            surfaceM2: 88.0,
            rooms: 4,
        );

        $key = $this->seed($root, $listing, 'UNKNOWN', 'DIGEST');
        $siblingKey = $this->seed($root, $sibling, 'LLI', null);

        Store::open($root . '/state/rent-watch.sqlite3')->assignGroup([$key, $siblingKey]);

        $r = $this->scout($root, ['reclassify'], $this->delivering());

        self::assertSame(0, $r['code']);
        self::assertStringContainsString(
            '1 annonce(s) re-jugée(s)',
            $r['out'],
            'an eligible sibling is not evidence against anything — the row must still be judged',
        );
        self::assertStringNotContainsString('écartée(s) par un doublon', $r['out']);
    }

    /**
     * The promotion announcements are CAPPED, and the cap is pinned.
     *
     * Round 5 capped three announcement paths in one commit; two got a named test and this one got
     * none — deleting the `array_slice` left the whole suite green, and no ledger case touched it
     * either. The population it bounds is the worst case the cap was added for: `staleVerdicts()`
     * after a `--seed` is everything published at seed time, at one push per promotion. Found by a
     * review panel on 2026-08-24.
     */
    public function testPromotionAnnouncementsAreCappedAndTheRemainderSurvives(): void
    {
        $root = $this->tempRoot();
        $over = Store::DIGEST_BATCH + 4;

        for ($i = 0; $i < $over; $i++) {
            $this->seed($root, $this->intermediateListing('PROMO-' . $i), 'UNKNOWN', 'DIGEST');
        }

        $first = $this->scout($root, ['reclassify'], $this->delivering());

        self::assertSame(0, $first['code']);
        self::assertStringContainsString(
            Store::DIGEST_BATCH . ' promotion(s) vers MATCH.',
            $first['out'],
            'one batch, capped',
        );
        self::assertStringContainsString(
            '4 promotion(s) au-delà du lot',
            $first['out'],
            'the remainder must be named, or the operator believes the backlog is drained',
        );

        // Nothing is written for an un-announced promotion, so the remainder is still reachable.
        $second = $this->scout($root, ['reclassify'], $this->delivering());

        self::assertStringContainsString(
            '4 promotion(s) vers MATCH.',
            $second['out'],
            'capping may not silently drop a promotion — the next run must reach it',
        );
    }

    private function intermediateListing(string $id): RawListing
    {
        return new RawListing(
            sourceName: 'demo',
            externalId: $id,
            title: 'T4 lumineux',
            description: 'Logement intermédiaire (LLI), attribution directe par le bailleur.',
            commune: 'Sartrouville',
            postcode: '78500',
            rentCc: 1450,
            surfaceM2: 88.0,
            rooms: 4,
        );
    }

    private function tenureOf(string $root, string $key): ?string
    {
        $statement = $this->pdo($root)->prepare('SELECT tenure FROM listings WHERE dedup_key = :key');
        $statement->execute(['key' => $key]);
        /** @var string|false|null $tenure */
        $tenure = $statement->fetchColumn();

        return is_string($tenure) ? $tenure : null;
    }

    /** @param array<string,mixed> $criteria */
    private function reconfigure(string $root, array $criteria): string
    {
        file_put_contents($root . '/config/rent/criteria.json', json_encode($criteria + [
            'communes' => ['Sartrouville'],
            'postcode_prefixes' => ['78'],
            'min_rooms' => 3,
            'min_surface_m2' => 50,
            'max_rent_cc' => 1800,
        ], JSON_THROW_ON_ERROR));

        return $root;
    }

    /**
     * A dedup MEMBER keeps its `NULL` outcome — reclassify may re-verdict it, never re-judge it.
     *
     * **This guarantee had no test at all, and the nightly ledger said so for three consecutive
     * nights** (issues #3, #4 and #5; `an unjudged dedup member is given a manufactured outcome`
     * stayed undetected while 476 other cases were caught). It predates the work that was running
     * beside it — commit `f6dfa43`, 2026-08-25 — so this is a pre-existing hole, and the ledger is
     * the only thing that ever noticed.
     *
     * Every member of a dedup cluster is CLASSIFIED; only the survivor is JUDGED by the criteria
     * engine. `outcome = NULL` is precisely what distinguishes *never judged* from *judged and
     * rejected*, and nothing else in the schema carries that difference. Manufacturing an outcome
     * here destroys it permanently — and the row then reads as a survivor to every later pass, so a
     * MEMBER becomes eligible for promotion to `MATCH` and a push. That is the same class of defect
     * as widening `pendingDigest()` to reach pre-v7 rows, which §1 refused for the same reason:
     * nothing stored distinguishes the two states afterwards.
     */
    public function testAnUnjudgedDedupMemberIsNeverGivenAnOutcome(): void
    {
        $root = $this->tempRoot();

        $member = $this->seed($root, new RawListing(
            sourceName: 'cdc_habitat',
            externalId: 'MEMBRE-1',
            title: 'Appartement T4',
            description: 'Aucun régime annoncé',
            commune: 'Sartrouville',
            postcode: '78500',
            rentCc: 1450,
            surfaceM2: 82.0,
            rooms: 4,
        ), 'UNKNOWN', null);

        self::assertNull($this->outcomeOf($root, $member), 'precondition: the member is unjudged');

        $r = $this->scout($root, ['reclassify']);

        self::assertSame(0, $r['code'], $r['err']);
        self::assertNull(
            $this->outcomeOf($root, $member),
            'a member the criteria engine never judged must keep NULL — an outcome here cannot be '
            . 'told apart from a real REJECT afterwards, and the row then reads as a survivor',
        );
    }

    /** The stored outcome, or `null` when the row was never judged. */
    private function outcomeOf(string $root, string $key): ?string
    {
        $stmt = $this->pdo($root)->prepare('SELECT outcome FROM listings WHERE dedup_key = :key');
        $stmt->execute(['key' => $key]);
        $value = $stmt->fetchColumn();

        return is_string($value) ? $value : null;
    }

    // ── F20 / Q39 (row 40, 2026-09-05): `--reopen=<dedup_key>` is the ONE way back for a durably-excluded row ──

    /**
     * A row holding an excluded reading it got from a TWIN (the other track said PLS, the row's own
     * card says nothing) can never be re-opened by any pass: `staleVerdicts()` skips excluded
     * tenures, `pendingDigest()` skips non-DIGEST outcomes, `replay` writes no verdicts. The repair
     * command clears the row's own reading AND its twin reading, says where each came from, and
     * lets the normal re-judge run on the same invocation.
     */
    public function testReopenClearsTheDurableReadingSaysWhereItCameFromAndReJudges(): void
    {
        $root = $this->tempRoot();
        $listing = new RawListing(
            sourceName: 'demo',
            externalId: 'REOPEN-1',
            title: 'T4 lumineux',
            description: 'Quatre pieces, 88 m2, ascenseur, logement intermediaire.',
            fields: ['financement' => 'LLI'],
            commune: 'Sartrouville',
            postcode: '78500',
            rentCc: 1450,
            surfaceM2: 88.0,
            rooms: 4,
        );
        $key = $this->seed($root, $listing, 'PLS', 'REJECT');
        $store = Store::open($root . '/state/rent-watch.sqlite3');
        $store->recordTwin($key, Tenure::PLS, 'seloger', 90);

        // A re-opened row that re-judges as a MATCH is NOTIFIED — that is the miss the command
        // recovers — so the run needs a channel that delivers, like every promotion test here.
        $r = $this->scout($root, ['reclassify', '--reopen=' . $key], $this->delivering());

        self::assertSame(0, $r['code'], $r['out'] . $r['err']);
        self::assertStringContainsString('1 promotion', $r['out'], 'the re-opened row was re-judged on its own evidence and promoted');
        self::assertStringContainsString('lecture propre : PLS', $r['out'], 'the row\'s own durable reading is named');
        self::assertStringContainsString('jumeau : PLS (seloger)', $r['out'], 'the twin reading is named with its source');
        self::assertStringContainsString('groupe : aucun', $r['out']);
        self::assertNull($store->twinTenure($key), 'the twin reading is cleared');
        self::assertSame(
            'LLI',
            $this->pdo($root)->query("SELECT tenure FROM listings WHERE dedup_key = '" . $key . "'")->fetchColumn(),
            'the same invocation re-judged the row on its OWN evidence',
        );
    }

    public function testReopenOnAnUnknownKeyIsRefusedAndTouchesNothing(): void
    {
        $root = $this->tempRoot();
        $key = $this->seed($root, $this->listing('KEEP-1'), 'PLS', 'REJECT');

        $r = $this->scout($root, ['reclassify', '--reopen=no-such-key']);

        self::assertSame(2, $r['code']);
        self::assertStringContainsString('no-such-key', $r['err'] . $r['out']);
        self::assertSame('PLS', $this->pdo($root)->query("SELECT tenure FROM listings WHERE dedup_key = '" . $key . "'")->fetchColumn());
    }

    public function testDryRunReopenReportsTheProvenanceAndClearsNothing(): void
    {
        $root = $this->tempRoot();
        $key = $this->seed($root, $this->listing('DRY-1'), 'PLS', 'REJECT');
        $store = Store::open($root . '/state/rent-watch.sqlite3');
        $store->recordTwin($key, Tenure::PLS, 'seloger', 90);

        $r = $this->scout($root, ['reclassify', '--dry-run', '--reopen=' . $key]);

        self::assertSame(0, $r['code'], 'OUT=' . $r['out'] . ' ERR=' . $r['err']);
        self::assertStringContainsString('lecture propre : PLS', $r['out']);
        self::assertSame('PLS', $this->pdo($root)->query("SELECT tenure FROM listings WHERE dedup_key = '" . $key . "'")->fetchColumn(), 'dry run: nothing cleared');
        self::assertNotNull($store->twinTenure($key), 'dry run: the twin reading stays');
    }

    private function listing(string $id): RawListing
    {
        return new RawListing(
            sourceName: 'demo',
            externalId: $id,
            title: 'T4 lumineux',
            description: 'Quatre pieces, 88 m2.',
            commune: 'Sartrouville',
            postcode: '78500',
            rentCc: 1450,
            surfaceM2: 88.0,
            rooms: 4,
        );
    }

    private function seed(string $root, RawListing $listing, string $tenure, ?string $outcome): string
    {
        $store = Store::open($root . '/state/rent-watch.sqlite3');
        $sighting = $store->record($listing, $listing->effectiveRentCc(), self::NOW);
        $store->recordVerdict($sighting->dedupKey, $tenure, 0, ['aucun signal de régime'], $listing);
        if ($outcome !== null) {
            $store->recordOutcome($sighting->dedupKey, $outcome);
        }

        return $sighting->dedupKey;
    }

    private function stripSnapshot(string $root, string $key): void
    {
        $this->pdo($root)
            ->prepare('UPDATE listings SET evidence_json = NULL WHERE dedup_key = :key')
            ->execute(['key' => $key]);
    }

    private function corruptSnapshot(string $root, string $key): void
    {
        $this->pdo($root)
            ->prepare('UPDATE listings SET evidence_json = :json WHERE dedup_key = :key')
            ->execute(['json' => '{not json at all', 'key' => $key]);
    }

    private function pdo(string $root): \PDO
    {
        $pdo = new \PDO('sqlite:' . $root . '/state/rent-watch.sqlite3');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    /**
     * A channel that COUNTS and always succeeds, composed with `console` inside the helpers.
     *
     * These tests assert that a listing was marked notified, which requires a real delivery, and
     * no offline CONFIGURATION can provide one: `console` cannot reach anyone and neither can
     * `email` over `SMTP_TRANSPORT=file` — which is what these four classes used for one review
     * round, making every such assertion pass for the reason that was itself the round-8 P0.
     *
     * It returns a CHANNEL rather than a `Notifier` on purpose. The helper composes it with a
     * `ConsoleChannel` bound to the test's own `$out` stream, so stdout assertions keep working
     * and the shape matches production: one channel to read, one that delivers.
     */
    private function delivering(): DeliveringChannel
    {
        return new DeliveringChannel();
    }

    /**
     * `console` plus the delivering double, or `null` to let `RentScout` build from config.
     *
     * @param resource $out
     */
    private static function compose(mixed $out, ?DeliveringChannel $delivering): ?Notifier
    {
        return $delivering === null ? null : new Notifier([new ConsoleChannel($out), $delivering]);
    }

    /**
     * @param list<string> $argv
     *
     * @return array{code: int, out: string, err: string}
     */
    private function scout(string $root, array $argv, ?DeliveringChannel $delivering = null): array
    {
        $out = fopen('php://memory', 'r+');
        $err = fopen('php://memory', 'r+');
        self::assertIsResource($out);
        self::assertIsResource($err);

        putenv('RENT_SCOUT_DB=' . $root . '/state/rent-watch.sqlite3');

        $code = (new RentScout($root, $out, $err, self::NOW, null, self::compose($out, $delivering)))->run($argv);
        rewind($out);
        rewind($err);

        return ['code' => $code, 'out' => (string) stream_get_contents($out), 'err' => (string) stream_get_contents($err)];
    }

    /** @param array<string,mixed> $criteria */
    private function tempRoot(array $criteria = []): string
    {
        $root = sys_get_temp_dir() . '/rentwatch-recl-' . bin2hex(random_bytes(8));
        mkdir($root . '/config/rent', 0o775, true);
        mkdir($root . '/state', 0o775, true);
        $this->roots[] = $root;

        file_put_contents($root . '/config/rent/criteria.json', json_encode($criteria + [
            'notify' => ['channels' => ['console', 'email']],
            'communes' => ['Sartrouville'],
            'postcode_prefixes' => ['78'],
            'min_rooms' => 3,
            'min_surface_m2' => 50,
            'max_rent_cc' => 1800,
        ], JSON_THROW_ON_ERROR));

        file_put_contents($root . '/config/rent/sources.json', json_encode([
            'sources' => [
                'demo' => [
                    'enabled' => false,
                    'family' => 'institutional',
                    'type' => 'fixture',
                    'mixed_tenure' => true,
                    'fixture' => 'tests/fixtures/rent/fixture_demo/search.json',
                    'items_path' => 'results.items',
                    'map' => ['ref' => 'id', 'title' => 'title'],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        return $root;
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            /** @var \SplFileInfo $entry */
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($path);
    }
}
