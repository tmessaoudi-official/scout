<?php

declare(strict_types=1);

namespace Scout\Tests\Rent\Core;

use PHPUnit\Framework\TestCase;
use Scout\Rent\Config\Criteria;
use Scout\Rent\Core\Dedup;
use Scout\Rent\Core\RawListing;

/**
 * Dedup, attacked from BOTH sides, as `CLAUDE.md` § Testing requires.
 *
 * The two failure modes are not symmetric and the tests are weighted accordingly:
 *
 * - **Over-merge hides a flat** — silent, and indistinguishable from that flat never having been
 *   published. Most of the cases below attack this direction.
 * - **Under-merge triple-notifies one** — visible, annoying, self-correcting.
 */
final class DedupTest extends TestCase
{
    private function listing(
        string $source,
        string $id,
        ?string $commune = 'Chatou',
        ?int $rentCc = 1450,
        ?float $surface = 84.0,
        ?int $rooms = 4,
        ?int $floor = null,
    ): RawListing {
        return new RawListing(
            sourceName: $source,
            externalId: $id,
            commune: $commune,
            rentCc: $rentCc,
            surfaceM2: $surface,
            rooms: $rooms,
            floor: $floor,
        );
    }

    private function reason(RawListing $a, RawListing $b, string $fa = 'private', string $fb = 'private'): ?string
    {
        return (new Dedup())->duplicateReason($a, $b, $fa, $fb);
    }

    /**
     * A TWIN across tracks is recognised, and still never merged (developer ruling, 2026-08-29:
     * "two findings, ONE push"). `duplicateReason()` keeps refusing across families — identities,
     * groups and histories stay per track — while `twinReason()` answers the notification-only
     * question with the same positive-evidence bar. Same track is not a twin: that pair is a
     * duplicate, and duplicates are the other method's job.
     */
    public function testATwinAcrossTracksIsRecognisedButNeverMerged(): void
    {
        $direct = $this->listing('cdc_habitat', 'c1');
        $agency = $this->listing('seloger', 's1');

        self::assertNull($this->reason($direct, $agency, 'institutional', 'private'), 'identities stay per track');
        self::assertNotNull((new Dedup())->twinReason($direct, $agency, 'institutional', 'private'));
        self::assertNull((new Dedup())->twinReason($direct, $agency, 'private', 'private'), 'same track: a duplicate, not a twin');
        self::assertNull(
            (new Dedup())->twinReason($direct, $this->listing('seloger', 's2', rentCc: 1700), 'institutional', 'private'),
            'the same positive-evidence bar: a rent that disagrees is a different flat',
        );
    }

    // ---------------------------------------------------------------- must merge

    public function testTheSameFlatOnTwoPrivatePortalsIsOneFinding(): void
    {
        // SeLoger and Logic-Immo are the same group (docs/SOURCES.md B5) and duplicate heavily.
        // This is the case dedup exists for.
        $reason = $this->reason(
            $this->listing('seloger', 'a1'),
            $this->listing('logic_immo', 'b9'),
        );

        self::assertNotNull($reason);
        self::assertStringContainsString('logic_immo', $reason);
    }

    public function testSmallRoundingDifferencesStillMerge(): void
    {
        // Portals round rent and surface differently for the same flat.
        $reason = $this->reason(
            $this->listing('seloger', 'a1', rentCc: 1450, surface: 84.0),
            $this->listing('logic_immo', 'b9', rentCc: 1470, surface: 85.0),
        );

        self::assertNotNull($reason);
    }

    // ---------------------------------------------------------------- must NOT merge

    public function testTwoListingsThatAGREEONNOTHINGAreNotDuplicates(): void
    {
        // THE most important case in this file. Two listings in the same commune stating nothing
        // else share no positive evidence at all — merging them is the over-merge that hides a
        // flat, and it is exactly what a naive "same commune" key would do.
        $reason = $this->reason(
            $this->listing('seloger', 'a1', rentCc: null, surface: null, rooms: null),
            $this->listing('logic_immo', 'b9', rentCc: null, surface: null, rooms: null),
        );

        self::assertNull($reason);
    }

    public function testAgreeingOnRoomCountAloneIsNotEnough(): void
    {
        // A T4 filter means EVERY candidate is a T4, so merging on room count alone collapses the
        // whole result set into a single notification.
        $reason = $this->reason(
            $this->listing('seloger', 'a1', rentCc: null, surface: null, rooms: 4),
            $this->listing('logic_immo', 'b9', rentCc: null, surface: null, rooms: 4),
        );

        self::assertNull($reason);
    }

    public function testTwoUNKNOWNCommunesAreNotTheSameCommune(): void
    {
        $reason = $this->reason(
            $this->listing('seloger', 'a1', commune: null),
            $this->listing('logic_immo', 'b9', commune: null),
        );

        self::assertNull($reason);
    }

    public function testDifferentCommunesNeverMerge(): void
    {
        self::assertNull($this->reason(
            $this->listing('seloger', 'a1', commune: 'Chatou'),
            $this->listing('logic_immo', 'b9', commune: 'Houilles'),
        ));
    }

    public function testAStatedRentDisagreementIsDecisive(): void
    {
        // Even with surface and rooms matching perfectly. Two flats whose rents differ by more than
        // the tolerance are two flats.
        self::assertNull($this->reason(
            $this->listing('seloger', 'a1', rentCc: 1450),
            $this->listing('logic_immo', 'b9', rentCc: 1750),
        ));
    }

    public function testAStatedSurfaceDisagreementIsDecisive(): void
    {
        self::assertNull($this->reason(
            $this->listing('seloger', 'a1', surface: 84.0),
            $this->listing('logic_immo', 'b9', surface: 110.0),
        ));
    }

    public function testAStatedRoomCountDisagreementIsDecisive(): void
    {
        // Rent and surface agree (two corroborating facts — enough to merge on their own), so the
        // room-count refusal is the ONLY thing keeping a T3 and a T4 apart. Without this case the
        // ledger's "dedup ignores a stated room-count disagreement" sabotage stayed green
        // (audit 2026-10-06, D-F6): every other test pair either agreed on rooms or was already
        // refused by rent or surface.
        self::assertNull($this->reason(
            $this->listing('seloger', 'a1', rooms: 4),
            $this->listing('logic_immo', 'b9', rooms: 3),
        ));
        self::assertNotNull($this->reason(
            $this->listing('seloger', 'a1', rooms: 4),
            $this->listing('logic_immo', 'b9', rooms: 4),
        ), 'control: the same pair with the same room count IS one flat');
    }

    public function testDifferentFloorsNeverMerge(): void
    {
        // Two identical flats in the same building on different floors are genuinely two flats, and
        // in a new development they are common.
        self::assertNull($this->reason(
            $this->listing('seloger', 'a1', floor: 2),
            $this->listing('logic_immo', 'b9', floor: 5),
        ));
    }

    // ---------------------------------------------------------------- tracks

    public function testAFlatOnInliAndOnSeLogerIsTWOFindings(): void
    {
        // docs/SOURCES.md: "a flat listed by In'li AND on SeLoger is two findings, not a duplicate,
        // because the application route differs." Applying to In'li directly is a different act
        // from applying through an agency — collapsing them hides the better route.
        self::assertNull($this->reason(
            $this->listing('inli', 'a1'),
            $this->listing('seloger', 'b9'),
            'institutional',
            'private',
        ));
    }

    public function testTwoListingsFromTheSameSourceAreNeverFuzzyMatched(): void
    {
        // Within a source the source's own id is authoritative and exact. Fuzzy matching here would
        // second-guess the only identifier that is actually reliable.
        self::assertNull($this->reason(
            $this->listing('seloger', 'a1'),
            $this->listing('seloger', 'b9'),
        ));
    }

    // ---------------------------------------------------------------- clustering

    public function testClusteringKeepsTheFirstAndRecordsWhatItAbsorbed(): void
    {
        $clusters = (new Dedup())->cluster([
            ['listing' => $this->listing('seloger', 'a1'), 'family' => 'private'],
            ['listing' => $this->listing('logic_immo', 'b9'), 'family' => 'private'],
            ['listing' => $this->listing('leboncoin', 'c3', commune: 'Houilles'), 'family' => 'private'],
        ]);

        self::assertCount(2, $clusters);
        self::assertSame('a1', $clusters[0]['listing']->externalId, 'the first is kept');
        // Recorded rather than dropped: knowing the same flat is on two portals is a second
        // application route, and a silently discarded duplicate is indistinguishable from a
        // listing that was never fetched.
        self::assertSame(['logic_immo:b9'], $clusters[0]['duplicates']);
        self::assertSame([], $clusters[1]['duplicates']);
    }

    /**
     * A cluster also carries its MEMBERS as listings, survivor first — schema v4's group needs them.
     *
     * `duplicates` cannot serve: it holds `sourceName:externalId` strings, and every listing whose
     * `externalId` is empty produces the same string, so parsing them back into store keys collides
     * exactly where the store's own fallback key does not. The store must be handed the listings.
     */
    public function testAClusterCarriesItsMembersAsListingsSurvivorFirst(): void
    {
        $clusters = (new Dedup())->cluster([
            ['listing' => $this->listing('seloger', 'a1'), 'family' => 'private'],
            ['listing' => $this->listing('logic_immo', 'b9'), 'family' => 'private'],
            ['listing' => $this->listing('leboncoin', 'c3', commune: 'Houilles'), 'family' => 'private'],
        ]);

        self::assertSame(['a1', 'b9'], array_map(
            static fn ($l): string => $l->externalId,
            $clusters[0]['members'],
        ));
        self::assertSame(['c3'], array_map(
            static fn ($l): string => $l->externalId,
            $clusters[1]['members'],
        ));
    }

    /** Every harvested listing is a member of exactly one cluster — none is lost on the way. */
    public function testEveryListingEndsUpInExactlyOneClustersMembers(): void
    {
        $items = [];
        for ($i = 0; $i < 12; ++$i) {
            $items[] = ['listing' => $this->listing('seloger', 'x' . $i, commune: 'Ville' . $i), 'family' => 'private'];
        }

        $clusters = (new Dedup())->cluster($items);
        $seen = [];

        foreach ($clusters as $cluster) {
            foreach ($cluster['members'] as $member) {
                $seen[] = $member->externalId;
            }
        }

        sort($seen);
        self::assertSame(12, count($seen));
        self::assertSame(count($seen), count(array_unique($seen)), 'a listing appeared in two clusters');
    }

    /**
     * An UNFOLDABLE commune is an unknown one, not a shared one.
     *
     * `Criteria::communeKey()` was changed this session to return `''` instead of throwing on
     * `MalformedText`, and the commit's own comment says the trigger is common — *"`Text` refuses
     * any undecoded HTML entity, which is commoner in a scraped payload than cp1252"*. What made
     * that safe was `Dedup` refusing to cluster on the empty key, and nothing pinned it: a reviewer
     * deleted the `$communeA === ''` clause in round 7 and the whole suite stayed green while two
     * flats in different DEPARTMENTS merged.
     *
     * The blast radius grew twice inside the same range. `communeKey()` widened the `''`
     * population, and the cluster veto became DURABLE and permanent — a flat once mis-merged with
     * an excluded stranger is rejected for the rest of the store's life, because `group_key` is
     * never cleared. So this guard's failure mode is now permanent silent §1 over-rejection, which
     * is invisible by definition: nothing arrives to notice.
     *
     * The ledger's existing case collapses all four clauses at once, so its detection comes from
     * the `null` half and the `''` clause could rot alone while the case still reported `ok` —
     * exactly the compound-rot failure `test-sabotage-applies.sh` was rewritten to prevent, one
     * level down. This test pins the clause on its own.
     */
    public function testTwoUnFOLDABLECommunesAreTwoUnknownsAndNeverTheSameFlat(): void
    {
        // Different towns, different departments, and both carry an undecoded HTML entity — so
        // `communeKey()` returns '' for each and a naive equality reads them as identical.
        $a = $this->listing('seloger', 'u-1', commune: 'Ch&acirc;teau-Thierry');
        $b = $this->listing('pap', 'u-2', commune: 'Bourg&ndash;la&ndash;Reine');

        self::assertSame('', Criteria::communeKey((string) $a->commune), 'the premise: unfoldable');
        self::assertSame('', Criteria::communeKey((string) $b->commune), 'and so is the other');

        self::assertNull(
            $this->reason($a, $b),
            'two communes that cannot be normalised are two UNKNOWNS. Merging them hides a flat, '
            . 'and since the cluster veto is durable the mis-merge would reject both for ever',
        );
    }

    public function testClusteringDoesNotCollapseAWholeResultSet(): void
    {
        // The regression this whole file guards: every candidate passes a T4 filter, so a weak rule
        // turns twenty findings into one notification and nineteen silent losses.
        $items = [];
        for ($i = 0; $i < 20; ++$i) {
            $items[] = [
                'listing' => $this->listing('seloger', 'x' . $i, rentCc: 1400 + $i * 40, surface: 80.0 + $i),
                'family' => 'private',
            ];
        }

        // All from the same source, so nothing may merge at all.
        self::assertCount(20, (new Dedup())->cluster($items));
    }
}
