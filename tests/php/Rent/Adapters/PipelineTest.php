<?php

declare(strict_types=1);

namespace Scout\Tests\Rent\Adapters;

use PHPUnit\Framework\TestCase;
use Scout\Rent\Adapters\FixtureSource;
use Scout\Adapters\SourceError;
use Scout\Rent\Config\ConfigLoader;
use Scout\Rent\Config\SourceDefinition;
use Scout\Rent\Core\CriteriaEngine;
use Scout\Rent\Core\Outcome;
use Scout\Rent\Core\TenureClassifier;
use Scout\Rent\Core\Verdict;
use Scout\Rent\Store\Store;

/**
 * The whole pipeline against a frozen payload — config, fetch, field map, classify, criteria.
 *
 * Offline by construction (`spec/PROJECT_BRIEF.md` §11: no network in CI). Every case below is a
 * trap the fixture was built to carry, and each one is a defect this project has already seen at
 * least once: in the prototype, in a review, or in a corpus fixture.
 */
final class PipelineTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../../..';

    private ?string $dbPath = null;

    protected function tearDown(): void
    {
        if ($this->dbPath !== null) {
            foreach (['', '-wal', '-shm'] as $suffix) {
                @unlink($this->dbPath . $suffix);
            }
            $this->dbPath = null;
        }
    }

    /** @return array<string, Verdict> keyed by external id */
    private function pipeline(): array
    {
        $criteria = ConfigLoader::loadCriteria(self::ROOT . '/tests/fixtures/rent/criteria/pipeline.json');
        $sources = ConfigLoader::loadSources(self::ROOT . '/config/rent/sources.json');
        $source = new FixtureSource($sources['fixture_demo'], self::ROOT);

        $classifier = new TenureClassifier();
        $engine = new CriteriaEngine($criteria);

        $out = [];
        foreach ($source->fetch() as $listing) {
            $classification = $classifier->classify($listing, $source->profile());
            $out[$listing->externalId] = $engine->judge($listing, $classification, null);
        }

        return $out;
    }

    private function store(): Store
    {
        $this->dbPath = sys_get_temp_dir() . '/rentwatch-pipeline-' . bin2hex(random_bytes(8)) . '.sqlite3';
        $store = Store::open($this->dbPath);
        $store->migrate();

        return $store;
    }

    // ---------------------------------------------------------------- §1

    public function testAnExcludedTenureIsRejectedAndNeverScored(): void
    {
        $v = $this->pipeline()['demo-0002'];

        self::assertSame(Outcome::REJECT, $v->outcome);
        self::assertSame('tenure: PLAI', $v->disqualifier);
        // Not "a score of zero". A rejected listing has NO score, so it cannot sort alongside a
        // genuine but poor match and invite a caller to notify it anyway.
        self::assertNull($v->score);
        self::assertFalse($v->highPriority);
    }

    public function testNoSignalOnAMixedSourceDigestsRatherThanMatching(): void
    {
        // The fail-closed rule, end to end. `fixture_demo` is `mixed_tenure: true`, so a listing
        // with no tenure evidence withholds instead of inheriting the source default.
        $v = $this->pipeline()['demo-0003'];

        self::assertSame(Outcome::DIGEST, $v->outcome);
        self::assertNull($v->score);
        self::assertNotEmpty($v->reasons, 'a digest entry with no reasons is one the developer stops opening');
    }

    public function testNothingInTheFixtureReachesAMatchWithAnExcludedTenure(): void
    {
        $classifier = new TenureClassifier();
        $sources = ConfigLoader::loadSources(self::ROOT . '/config/rent/sources.json');
        $source = new FixtureSource($sources['fixture_demo'], self::ROOT);
        $engine = new CriteriaEngine(ConfigLoader::loadCriteria(self::ROOT . '/tests/fixtures/rent/criteria/pipeline.json'));

        foreach ($source->fetch() as $listing) {
            $classification = $classifier->classify($listing, $source->profile());
            $verdict = $engine->judge($listing, $classification, null);

            if ($verdict->isMatch()) {
                self::assertFalse(
                    $classification->tenure->isExcluded(),
                    "listing {$listing->externalId} reached MATCH with tenure {$classification->tenure->value}",
                );
            }
        }

        self::assertTrue(true);
    }

    // ---------------------------------------------------------------- hard rule 9

    public function testAnUnknownRoomCountAndSurfaceDoNotDisqualify(): void
    {
        // `(l.rooms or 0) < min_rooms` is the prototype's bug in its natural habitat: it rejects
        // every listing that did not state a room count, silently, so nothing arrives to say so.
        $v = $this->pipeline()['demo-0004'];

        self::assertSame(Outcome::MATCH, $v->outcome);
        self::assertContains('nombre de pièces non communiqué', $v->reasons);
        self::assertContains('surface non communiquée', $v->reasons);
    }

    public function testAHighFloorWithNoLiftIsPenalisedAndNeverRejected(): void
    {
        // Ruled 2026-08-07 (Q5). The prototype hard-rejected on floor. This is a 4th floor with the
        // lift EXPLICITLY absent — the worst case the score knows — and it still reaches MATCH.
        $v = $this->pipeline()['demo-0006'];

        self::assertSame(Outcome::MATCH, $v->outcome);
        self::assertNotNull($v->score);
        self::assertGreaterThanOrEqual(0, $v->score, 'the score is clamped: a negative would sort below a rejection');
        self::assertContains('4e étage SANS ascenseur', $v->reasons);
    }

    public function testAHighFloorWithAnUNMENTIONEDLiftIsNotPenalised(): void
    {
        // The other side of hard rule 9, and the half that is easy to get wrong: the penalty needs
        // the lift to be EXPLICITLY absent. Most listings simply do not mention one, so a penalty
        // that fired on silence would dock nearly every listing in the market — and it would look
        // like the scoring was working.
        $engine = new CriteriaEngine(ConfigLoader::loadCriteria(self::ROOT . '/tests/fixtures/rent/criteria/pipeline.json'));
        $classifier = new TenureClassifier();
        $profile = new \Scout\Rent\Core\SourceProfile('t', 'institutional', \Scout\Rent\Core\Tenure::LLI, false);

        $make = static fn (?bool $lift): \Scout\Rent\Core\RawListing => new \Scout\Rent\Core\RawListing(
            sourceName: 't',
            externalId: 'lift-' . var_export($lift, true),
            title: 'T4 Sartrouville - logement intermediaire',
            description: '4 pieces, 85 m2, 4eme etage.',
            commune: 'Sartrouville',
            postcode: '78500',
            rentCc: 1450,
            surfaceM2: 85.0,
            rooms: 4,
            floor: 4,
            hasElevator: $lift,
        );

        $unmentioned = $make(null);
        $absent = $make(false);

        $vUnmentioned = $engine->judge($unmentioned, $classifier->classify($unmentioned, $profile), null);
        $vAbsent = $engine->judge($absent, $classifier->classify($absent, $profile), null);

        self::assertNotContains('4e étage SANS ascenseur', $vUnmentioned->reasons, 'silence is not a declared absence');
        self::assertContains('4e étage SANS ascenseur', $vAbsent->reasons);
        self::assertGreaterThan(
            (int) $vAbsent->score,
            (int) $vUnmentioned->score,
            'an unmentioned lift must score BETTER than a declared absence — otherwise the penalty is on silence',
        );
    }

    public function testTheGroundFloorIsRealRatherThanFalsy(): void
    {
        $v = $this->pipeline()['demo-0005'];

        self::assertSame(Outcome::MATCH, $v->outcome);
        self::assertContains('rez-de-chaussée', $v->reasons, 'floor 0 is falsy in PHP and is the BEST floor there is');
    }

    // ---------------------------------------------------------------- rent

    public function testChargesComprisesIsDerivedFromHorsChargesPlusCharges(): void
    {
        // Ruled 2026-08-07 (Q32, amending Q2). 1350 HC + 130 charges = 1480 CC, which passes the
        // 1800 ceiling. Calling this "unknown" would throw away a hard filter whose inputs are
        // both present.
        $v = $this->pipeline()['demo-0005'];

        self::assertSame(Outcome::MATCH, $v->outcome);
        self::assertContains('1480 € CC — 320 € sous le plafond', $v->reasons);
    }

    public function testARentOverTheCeilingIsDisqualified(): void
    {
        $criteria = ConfigLoader::loadCriteria(self::ROOT . '/tests/fixtures/rent/criteria/pipeline.json');
        self::assertSame(1800, $criteria->maxRentCc);

        $sources = ConfigLoader::loadSources(self::ROOT . '/config/rent/sources.json');
        $source = new FixtureSource($sources['fixture_demo'], self::ROOT);
        $engine = new CriteriaEngine($criteria);
        $classifier = new TenureClassifier();

        $listing = $source->fetch()[0];
        $over = new \Scout\Rent\Core\RawListing(
            sourceName: $listing->sourceName,
            externalId: 'over',
            title: $listing->title,
            description: $listing->description,
            fields: $listing->fields,
            commune: 'Sartrouville',
            postcode: '78500',
            rentCc: 1810,
            surfaceM2: 88.0,
            rooms: 4,
        );

        $v = $engine->judge($over, $classifier->classify($over, $source->profile()), null);

        self::assertSame(Outcome::REJECT, $v->outcome);
        self::assertStringContainsString('1810 € CC > 1800 € CC', (string) $v->disqualifier);
    }

    // ---------------------------------------------------------------- location

    public function testACommuneOutsideTheFilterIsRejected(): void
    {
        $v = $this->pipeline()['demo-0009'];

        self::assertSame(Outcome::REJECT, $v->outcome);
        self::assertStringContainsString('Nanterre', (string) $v->disqualifier);
    }

    public function testAListingWithNoCommuneIsJudgedOnItsPostcodeAlone(): void
    {
        // Ruled 2026-08-07 (Q32). A selector that catches the address but not the city is ordinary.
        // Rejecting on unknown would drop every listing from that source SILENTLY — source health
        // does not fire, because the fetch succeeded and the item count is non-zero.
        $v = $this->pipeline()['demo-0010'];

        self::assertSame(Outcome::MATCH, $v->outcome);
    }

    public function testAListingWithNeitherCommuneNorPostcodeIsRejected(): void
    {
        $engine = new CriteriaEngine(ConfigLoader::loadCriteria(self::ROOT . '/tests/fixtures/rent/criteria/pipeline.json'));
        $classifier = new TenureClassifier();
        $profile = new \Scout\Rent\Core\SourceProfile('t', 'institutional', \Scout\Rent\Core\Tenure::LLI, false);

        $listing = new \Scout\Rent\Core\RawListing(
            sourceName: 't',
            externalId: 'nowhere',
            title: 'T4 logement intermediaire',
            description: '4 pieces, 85 m2.',
            rooms: 4,
            surfaceM2: 85.0,
        );

        $v = $engine->judge($listing, $classifier->classify($listing, $profile), null);

        self::assertSame(Outcome::REJECT, $v->outcome);
        self::assertStringContainsString('no location evidence', (string) $v->disqualifier);
    }

    // ---------------------------------------------------------------- exclusion scope

    public function testAParkingSpaceIsRejectedEvenThoughNoSizeFilterCanCatchIt(): void
    {
        // It states no rooms and no surface, and an unknown measurement never disqualifies — so the
        // size filters are structurally unable to reject it. The title-scoped pattern is the only
        // thing standing between a parking ad and a notification.
        $v = $this->pipeline()['demo-0007'];

        self::assertSame(Outcome::REJECT, $v->outcome);
        self::assertStringContainsString('excluded kind of property', (string) $v->disqualifier);
    }

    public function testAFlatWITHAParkingSpaceIsNotRejected(): void
    {
        // The other half, and the reason the exclusion is title-scoped. demo-0005's description
        // says "parking en sous-sol inclus"; matching that would drop every flat that has one.
        $v = $this->pipeline()['demo-0005'];

        self::assertSame(Outcome::MATCH, $v->outcome);
    }

    // ---------------------------------------------------------------- extraction

    public function testFrenchFormattedNumbersAndARelativeUrlSurvive(): void
    {
        $sources = ConfigLoader::loadSources(self::ROOT . '/config/rent/sources.json');
        $source = new FixtureSource($sources['fixture_demo'], self::ROOT);

        $byId = [];
        foreach ($source->fetch() as $l) {
            $byId[$l->externalId] = $l;
        }
        $l = $byId['demo-0008'];

        self::assertSame(1540, $l->rentCc, '"1 540,00 €" with a narrow no-break space');
        self::assertSame(91.5, $l->surfaceM2, '"91,5" with a French decimal comma');
        self::assertSame(4, $l->rooms, 'a numeric string');
        self::assertTrue($l->hasElevator, '"oui"');
        // No `base_url` is configured for this source, so a relative href stays relative rather
        // than being dropped — dropping it would hide the missing `base_url` entirely.
        self::assertSame('/annonces/demo-0008', $l->url);
    }

    public function testTheClassifierSeesFieldsNobodyMapped(): void
    {
        // `fields` is the WHOLE flattened surface, not the handful the field map names, because the
        // classifier reads field NAMES as tier-1 evidence. A `financement` nobody thought to map
        // must still be found.
        $sources = ConfigLoader::loadSources(self::ROOT . '/config/rent/sources.json');
        $source = new FixtureSource($sources['fixture_demo'], self::ROOT);

        $listing = $source->fetch()[0];

        self::assertArrayHasKey('financement', $listing->fields);
        self::assertArrayHasKey('rent.charges', $listing->fields);
    }

    // ---------------------------------------------------------------- hard rule 3

    public function testAMissingItemsPathThrowsRatherThanReturningAnEmptyList(): void
    {
        // The single commonest way a working adapter stops working: still 200, still valid JSON,
        // but the results moved. Returning [] here would read as a quiet market forever — this is
        // the exact shape CLAUDE.md hard rule 3 forbids.
        $definition = new SourceDefinition(
            name: 'broken',
            enabled: true,
            family: 'institutional',
            type: 'fixture',
            mixedTenure: true,
            itemsPath: 'results.moved',
            fixture: 'tests/fixtures/rent/fixture_demo/search.json',
        );

        $this->expectException(SourceError::class);
        $this->expectExceptionMessageMatches('~items_path .* is absent from the payload~');

        (new FixtureSource($definition, self::ROOT))->fetch();
    }

    public function testAnItemWithNoStableIdThrowsRatherThanBeingSkipped(): void
    {
        // Nor is it given a synthetic id. A content hash or an array index changes whenever the ad
        // is edited or the result order shifts, so the listing is "new" on every run and notifies
        // forever — silently breaking the store's "new exactly once" guarantee.
        $definition = new SourceDefinition(
            name: 'noref',
            enabled: true,
            family: 'institutional',
            type: 'fixture',
            mixedTenure: true,
            itemsPath: 'results.items',
            map: new \Scout\Rent\Config\FieldMap(ref: ['reference_absente']),
            fixture: 'tests/fixtures/rent/fixture_demo/search.json',
        );

        $this->expectException(SourceError::class);
        $this->expectExceptionMessageMatches('~no value at any of the `ref` paths~');

        (new FixtureSource($definition, self::ROOT))->fetch();
    }

    public function testAMissingFixtureFileThrows(): void
    {
        $definition = new SourceDefinition(
            name: 'gone',
            enabled: true,
            family: 'institutional',
            type: 'fixture',
            mixedTenure: true,
            fixture: 'tests/fixtures/nope/search.json',
        );

        $this->expectException(SourceError::class);
        $this->expectExceptionMessageMatches('~fixture file not found~');

        (new FixtureSource($definition, self::ROOT))->fetch();
    }

    public function testAFixturePathMayNotEscapeTheRepo(): void
    {
        $definition = new SourceDefinition(
            name: 'traversal',
            enabled: true,
            family: 'institutional',
            type: 'fixture',
            mixedTenure: true,
            fixture: '../../../etc/passwd',
        );

        $this->expectException(SourceError::class);
        $this->expectExceptionMessageMatches('~may not contain~');

        (new FixtureSource($definition, self::ROOT))->fetch();
    }

    public function testASourceErrorMasksCredentialsInItsMessage(): void
    {
        // Adapter error text is a secrets channel: it reaches `source_runs.error` and from there a
        // user-facing health detail. Masking at construction means there is no unmasked variant to
        // reach for by mistake.
        $e = new SourceError('demo', 'GET https://api.example.test/search?apikey=abcdef123456 failed');

        self::assertStringNotContainsString('abcdef123456', $e->getMessage());
        self::assertStringContainsString('demo:', $e->getMessage());
    }

    // ---------------------------------------------------------------- health

    public function testEveryMappedPathExistsInTheCommittedFixture(): void
    {
        // A mapping no fixture exercises fails silently at runtime instead of loudly in a test.
        // This is the check `/add-source` step 3 asks for, made mechanical.
        $sources = ConfigLoader::loadSources(self::ROOT . '/config/rent/sources.json');
        $definition = $sources['fixture_demo'];

        $payload = json_decode(
            (string) file_get_contents(self::ROOT . '/' . (string) $definition->fixture),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $items = \Scout\Rent\Adapters\Payload::at($payload, (string) $definition->itemsPath);
        self::assertIsArray($items);

        $unused = [];
        foreach ($definition->map->allPaths() as $path) {
            $seen = false;
            foreach ($items as $item) {
                if (!\Scout\Rent\Adapters\Payload::isNullish(\Scout\Rent\Adapters\Payload::at($item, $path))) {
                    $seen = true;
                    break;
                }
            }
            if (!$seen) {
                $unused[] = $path;
            }
        }

        self::assertSame([], $unused, 'these mapped paths are exercised by no item in the fixture');
    }
}
