<?php

declare(strict_types=1);

namespace Scout\Tests\Rent\Cli;

use PHPUnit\Framework\TestCase;
use Scout\Rent\Cli\SectionOneGate;
use Scout\Rent\Core\Dedup;
use Scout\Rent\Core\RawListing;
use Scout\Rent\Core\Tenure;
use Scout\Rent\Store\Store;

/**
 * The gate's own contract: EVERY route refuses, and an eligible flat is not refused by any of them.
 *
 * The counterweight is not decoration here. Every route-specific test below is satisfied by a gate
 * that refuses unconditionally, and §1 satisfied by switching matching off is the failure mode this
 * repo names as "not §1 satisfied, it is the tool switched off".
 */
final class SectionOneGateTest extends TestCase
{
    private const string NOW = '2026-08-23T21:00:00+02:00';

    private string $db = '';

    protected function setUp(): void
    {
        $this->db = sys_get_temp_dir() . '/s1gate-' . bin2hex(random_bytes(6)) . '.sqlite3';
    }

    protected function tearDown(): void
    {
        foreach ([$this->db, $this->db . '-wal', $this->db . '-shm'] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
    }

    public function testTheRowsOwnExcludedReadingRefuses(): void
    {
        $store = Store::open($this->db);
        $listing = $this->flat('OWN-1');
        $key = $store->record($listing, 1450, self::NOW)->dedupKey;
        $store->recordVerdict($key, 'PLS', 90, ['régime relevé'], $listing);

        $refusal = $this->gate($store)->refuses($listing, $key);

        self::assertNotNull($refusal);
        self::assertSame('lecture propre', $refusal['route']);
    }

    public function testTheClusterRefuses(): void
    {
        $store = Store::open($this->db);
        $listing = $this->flat('GRP-1');
        $sibling = $this->flat('GRP-2', 'other');
        $key = $store->record($listing, 1450, self::NOW)->dedupKey;
        $siblingKey = $store->record($sibling, 1450, self::NOW)->dedupKey;
        $store->recordVerdict($siblingKey, 'PLS', 90, ['régime relevé'], $sibling);
        $store->assignGroup([$key, $siblingKey]);

        $refusal = $this->gate($store)->refuses($listing, $key);

        self::assertNotNull($refusal);
        self::assertSame('groupe', $refusal['route']);
    }

    public function testTheCrossTrackTwinRefuses(): void
    {
        $store = Store::open($this->db);
        $listing = $this->flat('TWIN-1');
        $key = $store->record($listing, 1450, self::NOW)->dedupKey;
        $store->recordTwin($key, Tenure::PLS, 'seloger', 9000);

        $refusal = $this->gate($store)->refuses($listing, $key);

        self::assertNotNull($refusal);
        self::assertSame('jumeau', $refusal['route']);
    }

    public function testTheSameDwellingUnderAnotherAdIdRefuses(): void
    {
        $store = Store::open($this->db);
        $old = $this->flat('DW-OLD');
        $oldKey = $store->record($old, 1450, self::NOW)->dedupKey;
        $store->recordVerdict($oldKey, 'PLS', 90, ['régime relevé'], $old);

        $new = $this->flat('DW-NEW');
        $newKey = $store->record($new, 1450, self::NOW)->dedupKey;

        $refusal = $this->gate($store)->refuses($new, $newKey);

        self::assertNotNull($refusal);
        self::assertSame('même logement', $refusal['route']);
    }

    /**
     * THE ROUND-3 P0 CHAIN: `Dedup::within()` IS A TOLERANCE BAND AND IS NOT TRANSITIVE.
     *
     * Three ad ids of one flat, 30 € apart at each step. The third is inside the second's band and
     * OUTSIDE the first's, so the argument that "both copies of one flat are caught by the same
     * veto" — which is why a fix for this was written, reverted and had to be written again — is
     * false. The gate holds because it reads the set FRESH: by the time the third is judged, the
     * second's excluded reading is on disk.
     */
    public function testANonTransitiveChainStillRefuses(): void
    {
        $store = Store::open($this->db);

        $c1 = $this->flat('CHAIN-1', 'cdc_habitat', 1450);
        $c1Key = $store->record($c1, 1450, self::NOW)->dedupKey;
        $store->recordVerdict($c1Key, 'PLS', 90, ['régime relevé'], $c1);

        // b1 is inside c1's band, and is judged excluded — as the pipeline would.
        $b1 = $this->flat('CHAIN-2', 'bienici', 1480);
        $b1Key = $store->record($b1, 1480, self::NOW)->dedupKey;
        self::assertNotNull($this->gate($store)->refuses($b1, $b1Key), 'premise: b1 is caught by c1');
        $store->recordVerdict($b1Key, 'PLS', 90, ['hérité du même logement'], $b1);

        // b2 is inside b1's band and OUTSIDE c1's — the cell the old reasoning said cannot exist.
        $b2 = $this->flat('CHAIN-3', 'bienici', 1510);
        $b2Key = $store->record($b2, 1510, self::NOW)->dedupKey;

        $refusal = $this->gate($store)->refuses($b2, $b2Key);

        self::assertNotNull($refusal, '§1: the chain link is inside b1 band even though it is outside c1 band');
        self::assertSame('même logement', $refusal['route']);
    }

    /** THE COUNTERWEIGHT: an eligible flat with nothing on record against it is NOT refused. */
    public function testAnEligibleFlatIsNotRefused(): void
    {
        $store = Store::open($this->db);
        $listing = $this->flat('CLEAN-1');
        $key = $store->record($listing, 1450, self::NOW)->dedupKey;
        $store->recordVerdict($key, 'LLI', 90, ['logement intermédiaire'], $listing);

        self::assertNull($this->gate($store)->refuses($listing, $key));
    }

    /** And an UNRELATED excluded flat elsewhere in the store does not refuse this one either. */
    public function testAnUnrelatedExcludedFlatDoesNotRefuse(): void
    {
        $store = Store::open($this->db);
        $elsewhere = new RawListing(
            sourceName: 'demo',
            externalId: 'OTHER',
            title: 'Studio',
            description: 'Ailleurs.',
            commune: 'Dourdan',
            postcode: '91410',
            rentCc: 700,
            surfaceM2: 28.0,
            rooms: 1,
        );
        $otherKey = $store->record($elsewhere, 700, self::NOW)->dedupKey;
        $store->recordVerdict($otherKey, 'PLS', 90, ['régime relevé'], $elsewhere);

        $listing = $this->flat('CLEAN-2');
        $key = $store->record($listing, 1450, self::NOW)->dedupKey;

        self::assertNull($this->gate($store)->refuses($listing, $key));
    }

    /** A doubt is not a refusal — demoting to the digest is the caller's decision, not the gate's. */
    public function testAnUndeterminedTwinIsNotARefusal(): void
    {
        $store = Store::open($this->db);
        $listing = $this->flat('DOUBT-1');
        $key = $store->record($listing, 1450, self::NOW)->dedupKey;
        $store->recordTwin($key, Tenure::UNKNOWN, 'seloger', 0);

        self::assertNull($this->gate($store)->refuses($listing, $key));
    }

    /**
     * A CORRUPT SNAPSHOT ON AN EXCLUDED ROW IS SKIPPED, NEVER THROWN — and nothing covered it.
     *
     * `Store::excludedDwellings()` catches the decode and `continue`s; `Store::reopen()` cites that
     * choice as its own precedent. Mutating it alone left all 3000 tests green (C2 round 3, P2 on
     * two lenses), and the ledger case that appeared to cover it was `reopen`'s, whose unscoped sed
     * hit both blocks while its detection came from the other one.
     *
     * What it costs if it throws: `excludedDwellings()` is called by `Pipeline`, the digest drain,
     * `reclassify` and `reopen`, so ONE undecodable row would abort every pass for as long as it
     * exists. Stated cost of skipping instead: that row stops vetoing re-adverts — which is why the
     * counterweight below pins that a DECODABLE excluded row still does.
     */
    public function testACorruptSnapshotOnAnExcludedRowDoesNotThrow(): void
    {
        $store = Store::open($this->db);
        $bad = $this->flat('CORRUPT-1');
        $badKey = $store->record($bad, 1450, self::NOW)->dedupKey;
        $store->recordVerdict($badKey, 'PLS', 90, ['régime relevé'], $bad);

        $pdo = new \PDO('sqlite:' . $this->db);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->prepare('UPDATE listings SET evidence_json = :j WHERE dedup_key = :k')
            ->execute(['j' => '{not json at all', 'k' => $badKey]);

        $listing = $this->flat('AFTER-1');
        $key = $store->record($listing, 1450, self::NOW)->dedupKey;

        // The whole point: this must ANSWER, not throw.
        $refusal = $this->gate($store)->refuses($listing, $key);

        self::assertNull($refusal, 'the undecodable row cannot veto, and it must not abort the run either');
    }

    /** The counterweight: a DECODABLE excluded row on the same store still vetoes. */
    public function testACorruptRowDoesNotDisableTheDecodableOnesBesideIt(): void
    {
        $store = Store::open($this->db);
        $bad = $this->flat('CORRUPT-2');
        $badKey = $store->record($bad, 1450, self::NOW)->dedupKey;
        $store->recordVerdict($badKey, 'PLS', 90, ['régime relevé'], $bad);
        $pdo = new \PDO('sqlite:' . $this->db);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->prepare('UPDATE listings SET evidence_json = :j WHERE dedup_key = :k')
            ->execute(['j' => '{not json at all', 'k' => $badKey]);

        $good = $this->flat('GOOD-OLD');
        $goodKey = $store->record($good, 1450, self::NOW)->dedupKey;
        $store->recordVerdict($goodKey, 'PLS', 90, ['régime relevé'], $good);

        $listing = $this->flat('AFTER-2');
        $key = $store->record($listing, 1450, self::NOW)->dedupKey;

        $refusal = $this->gate($store)->refuses($listing, $key);

        self::assertNotNull($refusal, 'one unreadable row must not disable the readable ones');
        self::assertSame('même logement', $refusal['route']);
    }

    private function gate(Store $store): SectionOneGate
    {
        return new SectionOneGate($store, new Dedup());
    }

    private function flat(string $id, string $source = 'demo', int $rent = 1450): RawListing
    {
        return new RawListing(
            sourceName: $source,
            externalId: $id,
            title: 'T4 lumineux',
            description: 'Quatre pieces, 88 m2.',
            commune: 'Sartrouville',
            postcode: '78500',
            rentCc: $rent,
            surfaceM2: 88.0,
            rooms: 4,
        );
    }
}
