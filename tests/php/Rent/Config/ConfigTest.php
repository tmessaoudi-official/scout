<?php

declare(strict_types=1);

namespace Scout\Tests\Rent\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Scout\Config\ConfigError;
use Scout\Adapters\Http\Robots;
use Scout\Rent\Config\ConfigLoader;
use Scout\Rent\Config\Criteria;
use Scout\Rent\Config\NotifyPolicy;
use Scout\Rent\Config\Weights;
use Scout\Rent\Core\Tenure;

/**
 * The config layer's contract.
 *
 * Categories mirror the store's, for the same reason: the failure modes here are silent. A config
 * that loads with a misread `mixed_tenure` produces a tool that runs, reports healthy, and surfaces
 * social housing. A config that loads with a mis-scoped exclusion pattern produces a tool that
 * reports nothing and looks like a quiet market.
 *
 * - **strictness**  — an unknown key, a wrong type and a missing required key are all loud
 * - **§1 guards**   — `mixed_tenure` required, excluded `default_tenure` refused, corpus binding
 * - **hard rule 1** — an enabled source may not carry an unverified URL
 * - **legal**       — `browser` refused, scraping opt-in identified
 * - **filters**     — commune matching, and the two exclusion scopes
 * - **shipped**     — the committed files actually load and say what the docs claim
 */
final class ConfigTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../../..';

    /** @return array<string,mixed> */
    private static function minimalCriteria(array $overrides = []): array
    {
        return $overrides + [
            'communes' => ['Sartrouville'],
            'postcode_prefixes' => ['78'],
        ];
    }

    /** @return array<string,mixed> */
    private static function minimalSource(array $overrides = []): array
    {
        return ['sources' => ['demo' => $overrides + [
            'enabled' => false,
            'family' => 'institutional',
            'type' => 'json',
            'mixed_tenure' => true,
            'map' => ['ref' => 'id'],
        ]]];
    }

    // ---------------------------------------------------------------- the html adapter's config

    /**
     * An enabled `type: html` source without an `item_selector` is refused AT LOAD.
     *
     * `HtmlSource::fetch()` refuses it too, so this is the second of two guards — and it is the one
     * that matters, because the adapter's refusal arrives after a poll has been scheduled and a run
     * logged against a source that was never going to extract anything. Without a selector the
     * adapter matches zero elements, and zero listings is this project's signature silent failure:
     * identical, from the outside, to a rental market that went quiet.
     *
     * Written because a sabotage run found the guard had no test at all — the validation was added
     * and the suite stayed green with it deleted.
     */
    /**
     * Both pagination mechanisms at once is refused AT LOAD, because the loser fails silently.
     *
     * The adapter picks one; the other is then ignored, and "ignored" here means either a walk that
     * refetches page one until the bound trips, or one that ends on a duplicate page and reports a
     * short result set. Neither reads as a configuration mistake at the point it hurts.
     */
    public function testASourceCannotPaginateByBothQueryParameterAndPath(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~page_path~');

        ConfigLoader::sourcesFromArray(self::minimalSource([
            'enabled' => true,
            'type' => 'html',
            'url' => 'https://example.test/search',
            'item_selector' => '.card',
            'page_param' => 'page',
            'page_path' => '/page-{page}/',
        ]));
    }

    /** A `page_path` with no `{page}` requests the same url forever — refused where it is written. */
    public function testAPagePathWithoutThePlaceholderIsRefusedAtLoad(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~\{page\}~');

        ConfigLoader::sourcesFromArray(self::minimalSource([
            'enabled' => true,
            'type' => 'html',
            'url' => 'https://example.test/search',
            'item_selector' => '.card',
            'page_path' => '/page-2/',
        ]));
    }

    public function testAnEnabledHtmlSourceWithoutAnItemSelectorIsRefusedAtLoad(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~item_selector~');

        ConfigLoader::sourcesFromArray(self::minimalSource([
            'enabled' => true,
            'type' => 'html',
            'url' => 'https://example.test/search',
        ]));
    }

    public function testADisabledHtmlSourceMayStillBeIncomplete(): void
    {
        // The refusal is scoped to ENABLED sources on purpose: a block being drafted towards a
        // future capture must remain writable, and hard rule 1 keeps it disabled meanwhile.
        $sources = ConfigLoader::sourcesFromArray(self::minimalSource(['type' => 'html']));

        self::assertNull($sources['demo']->itemSelector);
    }

    // ---------------------------------------------------------------- strictness

    public function testUnknownKeyIsRefusedRatherThanIgnored(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~unknown key mixd_tenure~');

        ConfigLoader::sourcesFromArray(self::minimalSource(['mixd_tenure' => true]));
    }

    public function testUnderscoreKeysAreCommentsAndAreIgnored(): void
    {
        $c = ConfigLoader::criteriaFromArray(self::minimalCriteria([
            '_comment' => 'JSON has no comments, so this is the convention',
            '_min_rooms' => 'a note about the key below',
            'min_rooms' => 4,
        ]));

        self::assertSame(4, $c->minRooms);
    }

    public function testAPerKeyNoteIsAcceptedWhileItsKeyIsPresent(): void
    {
        $c = ConfigLoader::criteriaFromArray(self::minimalCriteria([
            '_min_rooms' => 'a note sitting immediately above the key it explains',
            'min_rooms' => 4,
        ]));

        self::assertSame(4, $c->minRooms);
    }

    public function testAPerKeyNoteIsAcceptedRegardlessOfKeyOrder(): void
    {
        // The sibling check reads the ORIGINAL object, not what has been kept so far, so a note
        // written BELOW its key behaves the same as one written above it. Order-dependence here
        // would be a rule that works in the shipped file and fails in a hand-edited one.
        $c = ConfigLoader::criteriaFromArray(self::minimalCriteria([
            'min_rooms' => 4,
            '_min_rooms' => 'a note written after its key',
        ]));

        self::assertSame(4, $c->minRooms);
    }

    public function testAnUnderscoreKeyWithNoSiblingIsAnUnknownKey(): void
    {
        // The whole point of the sibling rule. Under the unbounded "any `_` prefix" rule this was
        // silently discarded, so a stray underscore disabled a setting with no message at all.
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~unknown key _min_roooms~');

        ConfigLoader::criteriaFromArray(self::minimalCriteria(['_min_roooms' => 'typo']));
    }

    public function testRenamingAKeyToDisableItProducesTwoLoudErrorsNotSilence(): void
    {
        // The failure a review found: `mixed_tenure` renamed to `_mixed_tenure` would, under a bare
        // prefix rule, silently disarm the fail-closed switch of CLAUDE.md §1. Now the note has no
        // sibling, so it is an unknown key AND the required key is missing.
        $source = self::minimalSource();
        $source['sources']['demo']['_mixed_tenure'] = $source['sources']['demo']['mixed_tenure'];
        unset($source['sources']['demo']['mixed_tenure']);

        try {
            ConfigLoader::sourcesFromArray($source);
            self::fail('expected a ConfigError');
        } catch (ConfigError $e) {
            self::assertStringContainsString('mixed_tenure: required', $e->getMessage());
        }
    }

    public function testFreeStandingCommentKeysNeedNoSibling(): void
    {
        $c = ConfigLoader::criteriaFromArray(self::minimalCriteria([
            '_comment' => 'about the object as a whole',
            '_why' => 'the reasoning behind it',
            '_source' => 'where the values came from',
            '_verified_at' => '2026-08-07',
        ]));

        self::assertNotSame([], $c->communes);
    }

    public function testACommentKeyCannotSatisfyARequiredKey(): void
    {
        // The underscore convention is only safe because the strictness above exists. This pins the
        // other half: a `_communes` note does not stand in for `communes`.
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~communes: required key is missing~');

        ConfigLoader::criteriaFromArray(['_communes' => 'a note', 'postcode_prefixes' => ['78']]);
    }

    public function testAStringThatLooksLikeABooleanIsRefused(): void
    {
        // "true" is truthy in PHP. `mixed_tenure: "false"` would be truthy too, which is how a
        // mixed-stock landlord ends up armed and a pure one ends up disarmed by the same mistake.
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~mixed_tenure: expected true or false, got the string~');

        ConfigLoader::sourcesFromArray(self::minimalSource(['mixed_tenure' => 'true']));
    }

    public function testAUserAgentHeaderInSourceConfigIsRefused(): void
    {
        // In cURL, a User-Agent entry in CURLOPT_HTTPHEADER silently overrides CURLOPT_USERAGENT,
        // so this one config key would disguise every request from the source — the browser
        // impersonation hard rule 5 forbids. Refused at load time, case-insensitively, because
        // HTTP header names are.
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~User-Agent header is not configurable~');

        ConfigLoader::sourcesFromArray(self::minimalSource(['headers' => ['USER-AGENT' => 'Mozilla/5.0']]));
    }

    public function testAColonSmuggledHeaderNameIsRefusedInConfig(): void
    {
        // The round-2 bypass of the refusal above: libcurl reads the header NAME from the text
        // before the first colon, so a JSON key of "user-agent: Mozilla…" cleared the equality
        // guard and still disguised the request. A colon can never appear in an RFC 7230 token.
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~not a valid HTTP token~');

        ConfigLoader::sourcesFromArray(self::minimalSource(['headers' => ['user-agent: Mozilla/5.0' => 'x']]));
    }

    public function testALineBreakInAHeaderValueIsRefusedInConfig(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~header injection~');

        ConfigLoader::sourcesFromArray(self::minimalSource(['headers' => ['Referer' => "https://x.test/\r\nUser-Agent: Mozilla"]]));
    }

    public function testATrailingNewlineInAHeaderNameIsRefusedInConfig(): void
    {
        // PHP's `$` matches before a single trailing newline, so `"user-agent\n"` would pass a
        // `$`-anchored token class and dodge the User-Agent equality guard. The `D` modifier on
        // HEADER_NAME_TOKEN refuses it; this pins the modifier in place.
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~not a valid HTTP token~');

        ConfigLoader::sourcesFromArray(self::minimalSource(['headers' => ["user-agent\n" => 'Mozilla/5.0']]));
    }

    public function testAFloatIsNotAcceptedWhereAnIntegerIsSpecified(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~max_rent_cc: expected an integer~');

        ConfigLoader::criteriaFromArray(self::minimalCriteria(['max_rent_cc' => 1800.5]));
    }

    public function testErrorMessagesNameTheFullPointer(): void
    {
        try {
            ConfigLoader::sourcesFromArray(self::minimalSource(['family' => 'municipal']));
            self::fail('expected a ConfigError');
        } catch (ConfigError $e) {
            self::assertStringContainsString('sources.json.sources.demo.family', $e->getMessage());
        }
    }

    public function testNestedObjectsAreAlsoStrict(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~weights: unknown key freshnes~');

        ConfigLoader::criteriaFromArray(self::minimalCriteria(['weights' => ['freshnes' => 10]]));
    }

    // ---------------------------------------------------------------- §1 guards

    public function testMixedTenureIsRequiredEvenThoughTheCodeDefaultsIt(): void
    {
        $source = self::minimalSource();
        unset($source['sources']['demo']['mixed_tenure']);

        $this->expectException(ConfigError::class);
        // Matching only `mixed_tenure: required` was NOT enough, and a sabotage run proved it:
        // deleting the explicit guard falls through to `requireBool()`, whose generic "required key
        // is missing" ALSO matches that pattern. The test passed while the guard was gone. Asserting
        // the guidance text is what makes the guard load-bearing rather than decorative — it is the
        // sentence that tells whoever hit this WHY the flag matters.
        $this->expectExceptionMessageMatches('~arms the fail-closed rule~');

        ConfigLoader::sourcesFromArray($source);
    }

    #[DataProvider('excludedTenures')]
    public function testAnExcludedDefaultTenureIsRefused(string $tenure): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~excluded set~');

        ConfigLoader::sourcesFromArray(self::minimalSource(['default_tenure' => $tenure]));
    }

    /** @return iterable<string, array{string}> */
    public static function excludedTenures(): iterable
    {
        foreach (Tenure::cases() as $case) {
            if ($case->isExcluded()) {
                yield $case->value => [$case->value];
            }
        }
    }

    public function testEveryExcludedTenureIsCoveredByThatProvider(): void
    {
        // The provider derives from the enum by reflection, so a tenure added to the excluded set
        // gets a test for free. This asserts the derivation is not empty — a provider that silently
        // yielded nothing would make the test above pass by doing nothing at all.
        $covered = array_keys(iterator_to_array(self::excludedTenures()));
        self::assertNotEmpty($covered);
        self::assertContains('PLAI', $covered);
        self::assertContains('PLUS', $covered);
        self::assertContains('PLS', $covered);
    }

    public function testUnknownIsRefusedAsADefaultTenure(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~UNKNOWN is what the classifier concludes~');

        ConfigLoader::sourcesFromArray(self::minimalSource(['default_tenure' => 'UNKNOWN']));
    }

    public function testAnEligibleDefaultTenureIsAccepted(): void
    {
        $sources = ConfigLoader::sourcesFromArray(self::minimalSource(['default_tenure' => 'LLI']));
        self::assertSame(Tenure::LLI, $sources['demo']->defaultTenure);
    }

    public function testThereIsNoConfigKeyThatCanReEnableAnExcludedTenure(): void
    {
        // Not a formality. §1 says the excluded set is not user-overridable, and the way that breaks
        // is a well-meaning key added later. If a future edit adds `tenures` or `allow_tenures` to
        // Criteria, this fails and the author has to argue for it.
        $forbidden = ['tenure', 'tenures', 'allow_tenures', 'allowed_tenures', 'exclude_tenures', 'social'];
        $properties = array_map(
            static fn (\ReflectionProperty $p): string => strtolower($p->getName()),
            (new \ReflectionClass(Criteria::class))->getProperties(),
        );

        foreach ($forbidden as $name) {
            self::assertNotContains(
                str_replace('_', '', $name),
                array_map(static fn (string $p): string => str_replace('_', '', $p), $properties),
                'Criteria must not carry a tenure key — CLAUDE.md §1 is not user-overridable',
            );
        }
    }

    public function testEveryCorpusSourceAgreesWithConfig(): void
    {
        // The binding docs/OPEN-QUESTIONS.md Q20 asked for. `mixed_tenure` is the one flag that
        // disarms the fail-closed rule, and it is declared in TWO places — the classifier corpus and
        // config/rent/sources.json. Two declarations of one fact drift, and this is the only thing that
        // makes the drift loud.
        $corpus = json_decode(
            (string) file_get_contents(self::ROOT . '/tests/fixtures/rent/tenure/corpus.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $config = ConfigLoader::loadSources(self::ROOT . '/config/rent/sources.json');

        $compared = 0;
        foreach ($corpus['sources'] as $name => $declared) {
            if (!isset($config[$name])) {
                continue;
            }

            ++$compared;
            self::assertSame(
                $declared['mixed_tenure'],
                $config[$name]->mixedTenure,
                "mixed_tenure for '{$name}' differs between the corpus and config/rent/sources.json",
            );
            self::assertSame(
                $declared['family'],
                $config[$name]->family,
                "family for '{$name}' differs between the corpus and config/rent/sources.json",
            );
            self::assertSame(
                $declared['default_tenure'],
                $config[$name]->defaultTenure?->value,
                "default_tenure for '{$name}' differs between the corpus and config/rent/sources.json",
            );
        }

        // Without this the test passes when the two files share no source at all — which is exactly
        // what a rename would produce, and exactly the drift it exists to catch.
        self::assertGreaterThanOrEqual(
            5,
            $compared,
            'fewer than 5 sources are declared in both the corpus and config/rent/sources.json — '
                . 'a rename on one side would make this test vacuous',
        );
    }

    // ---------------------------------------------------------------- hard rule 1

    public function testAnEnabledSourceCannotCarryTheUnverifiedUrlPlaceholder(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~hard rule 1~');

        ConfigLoader::sourcesFromArray(self::minimalSource([
            'enabled' => true,
            'url' => 'https://example.test/REMPLACER',
        ]));
    }

    public function testADisabledSourceMayCarryThePlaceholder(): void
    {
        // The placeholder is the whole point of `enabled: false` — it is how a source is staged
        // before its endpoint has been captured. Refusing it here would make onboarding impossible.
        $sources = ConfigLoader::sourcesFromArray(self::minimalSource(['url' => 'REMPLACER']));
        self::assertFalse($sources['demo']->enabled);
    }

    public function testAnEnabledSourceNeedsAUrl(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~needs a url~');

        ConfigLoader::sourcesFromArray(self::minimalSource(['enabled' => true]));
    }

    public function testAnEnabledFixtureSourceNeedsAFixtureFileRatherThanAUrl(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~fixture: a fixture source must name its payload file~');

        ConfigLoader::sourcesFromArray(self::minimalSource(['enabled' => true, 'type' => 'fixture']));
    }

    // ---------------------------------------------------------------- legal posture

    public function testBrowserTypeIsRefusedAtLoadRatherThanStubbed(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~browser automation is not permitted~');

        ConfigLoader::sourcesFromArray(self::minimalSource(['type' => 'browser']));
    }

    public function testPollingAPrivatePortalIsIdentifiedAsNeedingTheScrapingOptIn(): void
    {
        $sources = ConfigLoader::sourcesFromArray(self::minimalSource([
            'family' => 'private',
            'type' => 'json',
        ]));

        self::assertTrue($sources['demo']->requiresScrapingOptIn());
    }

    /**
     * EVERY REGEX PARAM COMPILES AT LOAD, AND THE LIST IS READ BY REFLECTION (C2 round 2).
     *
     * The eight keys were an inline literal inside `loadSources()`, and **six of them had no test at
     * all**: deleting `advertiser_pattern` — the §1-relevant one, feeding `Core\LandlordRegistry` —
     * left the whole suite and the whole sabotage ledger green. A key added tomorrow and covered by
     * nobody would be exactly as invisible.
     *
     * So the list is a named constant and this test reads it rather than restating it. A ninth entry
     * that does not compile-check fails HERE.
     *
     * **THIS DOCBLOCK CLAIMED THE CAR SIDE ALREADY HAD THAT PROPERTY, AND IT DID NOT** (C2 round 3).
     * `VehicleSourceLoader::READ_PARAMS` was read by no test at all, and the car reflection guard is
     * anchored to the four COUNTED keys — `subject_pattern` and `make_model_unknown_pattern` are
     * explicitly subtracted, `seller_pattern` and `postcode_pattern` are subtracted as
     * `UNREAD_PARAMS`. Four of eight. A review panel added a ninth key to the car allow-list and
     * loaded it uncompilable and silent with 382 tests green.
     *
     * So the sentence describing the one-of-two-symmetric-surfaces shape was itself an instance of
     * it: a cure asserted for a surface that had none. The car anchor now exists —
     * `VehicleEmailPatternMissTest::testEveryPatternKeyTheCarAdaptersAcceptIsAlsoCompileChecked` —
     * and this cites it rather than assuming it.
     *
     * The refusal matters because `matchParam()` uses `@preg_match`: a pattern that does not compile
     * neither warns nor throws, it simply never matches. On `advertiser_pattern` that silence
     * re-opens the hole the registry closes; on `subject_pattern` it is not an extraction failure at
     * all but an ADMISSION one — the source then reads every message from its sender.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function regexParams(): iterable
    {
        $r = new \ReflectionClass(ConfigLoader::class);
        /** @var list<string> $keys */
        $keys = $r->getConstant('PATTERN_PARAMS');

        self::assertNotSame([], $keys, 'PATTERN_PARAMS is empty — the compile check would cover nothing');

        foreach ($keys as $key) {
            yield $key => [$key];
        }
    }

    /**
     * THE LIST IS ANCHORED TO AN INDEPENDENT ONE, and without this the guard cannot catch its own
     * finding.
     *
     * A data provider that reads `PATTERN_PARAMS` derives its CASES from the list being sabotaged:
     * delete `advertiser_pattern` and the provider simply yields seven cases instead of eight, all
     * green. Measured — the deletion the round-2 lens demonstrated was still silent with the
     * per-key tests in place, which is the reflection-guard trap in its purest form.
     *
     * So the set is pinned against `EMAIL_ALERT_PARAMS`, the allow-list of every key an adapter
     * actually reads, filtered to those whose value is a pattern by NAME. Two independent lists,
     * neither derived from the other: dropping a key from either side now fails here.
     */
    public function testEveryPatternKeyTheAdaptersAcceptIsAlsoCompileChecked(): void
    {
        $r = new \ReflectionClass(ConfigLoader::class);
        /** @var list<string> $checked */
        $checked = $r->getConstant('PATTERN_PARAMS');
        /** @var list<string> $accepted */
        $accepted = $r->getConstant('EMAIL_ALERT_PARAMS');

        $shouldBeChecked = array_values(array_filter(
            $accepted,
            static fn (string $k): bool => str_ends_with($k, '_pattern'),
        ));

        sort($shouldBeChecked);
        $actual = $checked;
        sort($actual);

        self::assertSame(
            $shouldBeChecked,
            $actual,
            'every accepted `*_pattern` param must compile at load — `matchParam()` uses @preg_match, '
                . 'so one that does not compile never matches and never says so',
        );

        // THE NAME SUFFIX IS A CONVENTION, NOT AN ANCHOR — both round-3 lenses reached this from
        // opposite directions. A regex param called something else escapes the filter above and the
        // list it is compared against. `matchParam()` is the real funnel: every key it is handed IS
        // applied as a regex, whatever it is named, so the literals at its call sites are read out
        // of the adapter's source and required to be compile-checked. That is an anchor rather than
        // a habit, and it holds for a key named `trim` as readily as for one named `trim_pattern`.
        $adapter = (string) file_get_contents(self::ROOT . '/src/php/Rent/Adapters/EmailAlertSource.php');
        self::assertSame(
            1,
            preg_match_all("~matchParam\('([a-z_]+)'~", $adapter, $m) > 0 ? 1 : 0,
            'the matchParam call sites must be readable — the anchor depends on them',
        );

        $applied = array_values(array_unique($m[1]));
        sort($applied);
        $notChecked = array_values(array_diff($applied, $checked));

        self::assertSame(
            [],
            $notChecked,
            'a params key is applied as a REGEX by matchParam() and is not compile-checked — the '
                . 'name-suffix filter above cannot see it, which is why this reads the funnel instead',
        );
    }

    #[DataProvider('regexParams')]
    public function testEveryRegexParamIsCompileCheckedAtLoad(string $key): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('/ne compile pas/');

        ConfigLoader::sourcesFromArray(self::minimalSource([
            'family' => 'private',
            'type' => 'email_alert',
            // `mixed_tenure: false` and a `link_host` are not incidental: on a MIXED source
            // `card_separator_pattern` is refused by the §1 segmentation guard BEFORE anything is
            // compiled, and a segmented link-keyed source without a `link_host` is refused too.
            // Both refusals are round-1 fixes doing their job; this test is about a different one.
            'mixed_tenure' => false,
            // `(unclosed` compiles nowhere, in any delimiter.
            'params' => ['from' => 'alerts@portal.test', 'link_host' => 'portal.test/annonce/', $key => '~(unclosed~'],
        ]));
    }

    /**
     * THE COUNTERWEIGHT: a pattern that DOES compile still loads. Without it the assertion above is
     * satisfied by refusing every value of those keys, which would break four shipped sources.
     */
    #[DataProvider('regexParams')]
    public function testAValidRegexParamStillLoads(string $key): void
    {
        $sources = ConfigLoader::sourcesFromArray(self::minimalSource([
            'family' => 'private',
            'type' => 'email_alert',
            'mixed_tenure' => false,
            'params' => ['from' => 'alerts@portal.test', 'link_host' => 'portal.test/annonce/', $key => '~^\d{2}$~'],
        ]));

        self::assertSame('~^\d{2}$~', $sources['demo']->params[$key] ?? null);
    }

    public function testEmailAlertIngestionIsNotGated(): void
    {
        // Hard rule 4: email-alert ingestion is the PRIMARY path for a private portal, not a
        // workaround. Gating it would push the developer toward the scraping route it replaces.
        $sources = ConfigLoader::sourcesFromArray(self::minimalSource([
            'family' => 'private',
            'type' => 'email_alert',
        ]));

        self::assertFalse($sources['demo']->requiresScrapingOptIn());
    }

    // ---------------------------------------------------------------- unrecognised params, per type

    /**
     * A PARAM NO ADAPTER READS IS REFUSED ON AN `email_alert` SOURCE (Track 5b, F-A).
     *
     * `CLAUDE.md` says of the config layer that "any other unrecognised key is a validation error". That
     * was true of the top level and FALSE of `params`, which is where every silent-extraction defect
     * this project has had lives: a misspelt `titel_pattern` loaded cleanly and did nothing, which
     * is precisely the PAP failure — the pattern silently absent, the generic scan answering
     * instead, wrong values stored, and nothing reading as a fault. Measured 2026-09-01: both
     * `params.titel_pattern` and `params.totally_made_up` loaded without complaint.
     *
     * A live instance was already shipped: rent `leboncoin` carried a `subject_pattern`, a key only
     * the CAR adapter reads. It is removed in the same commit as this guard, because a validator
     * landing without that cleanup makes the deployed watcher refuse its own shipped config on the
     * next restart.
     */
    public function testAnUnrecognisedParamOnAnEmailSourceIsRefusedAndNamed(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~titel_pattern~');

        ConfigLoader::sourcesFromArray(self::minimalSource([
            'type' => 'email_alert',
            'params' => ['from' => 'a@b.test', 'link_host' => 'b.test', 'titel_pattern' => '~x~'],
            'map' => ['ref' => 'url'],
        ]));
    }

    /**
     * THE COUNTERWEIGHT, and it is the half that makes the guard safe to ship: on an `html` or
     * `json` source `params` IS THE HTTP QUERY STRING — `HtmlSource` does
     * `withQuery([...$this->definition->params, ...$extra])` — so it cannot be a closed set. In'li
     * ships `price_max`, `area_min`, `room_min` and Logirep ships `ss_trnsctntp`; every one is a
     * query argument for the site, read by no adapter by design.
     *
     * `params` is therefore OVERLOADED: adapter configuration on an email source, a query string on
     * a polling one. Refusing unrecognised keys on both would break the two live polling sources at
     * startup — which is the failure the `RENT_FEED_SILENT_DAYS` hard refusal already taught this
     * repo once, and the reason the guard is scoped by type rather than applied everywhere.
     */
    public function testAnUnrecognisedParamOnAPollingSourceIsTheQueryStringAndIsAccepted(): void
    {
        $sources = ConfigLoader::sourcesFromArray(self::minimalSource([
            'type' => 'json',
            'params' => ['ss_trnsctntp' => 'leasing', 'whatever_the_site_wants' => '1'],
        ]));

        self::assertSame('leasing', $sources['demo']->params['ss_trnsctntp'] ?? null);
        self::assertSame('1', $sources['demo']->params['whatever_the_site_wants'] ?? null);
    }

    /**
     * `subject_pattern` IS READ ON THE RENT SIDE — and it was configured, documented and UNARMED.
     *
     * The rent and car leboncoin alerts share the sender `no.reply@leboncoin.fr`, the link host
     * `leboncoin.fr/vi/` AND the `Voir l'annonce` separator. Nothing but the subject separates
     * them. Rent leboncoin carries a `subject_pattern` written for exactly that, with a comment
     * saying the two collide the moment the Gmail filter routing the vehicle alerts is created —
     * and no rent adapter read the key, so the guard did not exist.
     *
     * Track 5b found it as "a param no adapter reads", and deleting it was the wrong fix: it would
     * have removed a documented intention and left the collision unguarded on the day the owed
     * filter lands. A car alert would then parse as a flat, be rejected for having no commune, and
     * count toward this source's health.
     *
     * Asserted against the SHIPPED pattern rather than a copy, so the config and the guarantee
     * cannot drift.
     */
    public function testTheShippedLeboncoinSubjectPatternDistinguishesHousingFromVehicleAlerts(): void
    {
        $sources = ConfigLoader::loadSources(__DIR__ . '/../../../../config/rent/sources.json');
        $pattern = $sources['leboncoin']->params['subject_pattern'] ?? null;

        self::assertIsString($pattern, 'rent leboncoin must keep a subject filter');

        self::assertSame(1, preg_match($pattern, '3 nouveaux biens à louer à Ile-de-France'));
        self::assertSame(
            0,
            preg_match($pattern, 'Auto Presto vous propose PEUGEOT 208 à 8 990 € à Melun'),
            'a VEHICLE alert on the same sender and link host must not match the housing subject',
        );
    }

    /** Every param the shipped config actually uses must survive the guard — the regression half. */
    public function testEveryShippedSourceStillLoadsUnderTheParamGuard(): void
    {
        $sources = ConfigLoader::loadSources(__DIR__ . '/../../../../config/rent/sources.json');

        self::assertGreaterThanOrEqual(8, count($sources), 'the shipped config must still load in full');
    }

    // ---------------------------------------------------------------- feed_silent_days, per source

    /**
     * A per-source `feed_silent_days` — the override the car-domain plan argued for on 2026-08-28:
     * a portal firing thirty alerts a day is only noticed after the GLOBAL three days of silence,
     * ~90 missed alerts, while leboncoin needs those three days because it fires once a week.
     * One number cannot serve both. Absent, the global `RENT_FEED_SILENT_DAYS` applies unchanged, which
     * is what keeps every shipped block byte-identical.
     */
    public function testFeedSilentDaysIsAcceptedOnAnEmailAlertSourceAndCarriedOnTheDefinition(): void
    {
        $sources = ConfigLoader::sourcesFromArray(self::minimalSource([
            'type' => 'email_alert',
            'feed_silent_days' => 1,
            'params' => ['from' => 'alerts@portal.test', 'link_host' => 'portal.test'],
            'map' => ['ref' => 'url', 'charges_included' => true],
        ]));

        self::assertSame(1, $sources['demo']->feedSilentDays);
    }

    public function testAnAbsentFeedSilentDaysLeavesTheGlobalThresholdInForce(): void
    {
        $sources = ConfigLoader::sourcesFromArray(self::minimalSource(['type' => 'email_alert']));

        self::assertNull($sources['demo']->feedSilentDays, 'null means "use RENT_FEED_SILENT_DAYS", not 0');
    }

    /** `0` would disable the one verdict that tells a dead alert from a quiet market — same refusal as the env var. */
    public function testFeedSilentDaysBelowOneIsRefused(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~feed_silent_days.*au moins 1|au moins 1.*feed_silent_days~s');

        ConfigLoader::sourcesFromArray(self::minimalSource(['type' => 'email_alert', 'feed_silent_days' => 0]));
    }

    /**
     * Only a source that REPORTS a feed date can act on the threshold. On an `html` or `json`
     * source the key would be a configured feature that never runs — the `detail_budget_per_pass: 0`
     * shape — so it is refused at load rather than accepted and ignored.
     */
    public function testFeedSilentDaysOnASourceThatCannotActOnItIsRefused(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~feed_silent_days.*email_alert|email_alert.*feed_silent_days~s');

        ConfigLoader::sourcesFromArray(self::minimalSource(['type' => 'json', 'feed_silent_days' => 2]));
    }

    public function testPollingAnInstitutionalLandlordIsNotGated(): void
    {
        $sources = ConfigLoader::sourcesFromArray(self::minimalSource(['type' => 'json']));
        self::assertFalse($sources['demo']->requiresScrapingOptIn());
    }

    // ---------------------------------------------------------------- field map

    public function testARefPathIsRequiredBecauseWithoutOneEveryRunRenotifiesEverything(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~ref: required~');

        ConfigLoader::sourcesFromArray(self::minimalSource(['map' => ['title' => 'name']]));
    }

    public function testChargesIncludedIsRequiredWheneverRentIsMapped(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~charges_included: required whenever `rent` is mapped~');

        ConfigLoader::sourcesFromArray(self::minimalSource([
            'map' => ['ref' => 'id', 'rent' => 'price'],
        ]));
    }

    public function testASinglePathStringIsNormalisedToAOneElementList(): void
    {
        $sources = ConfigLoader::sourcesFromArray(self::minimalSource([
            'map' => ['ref' => 'id', 'title' => 'name'],
        ]));

        self::assertSame(['name'], $sources['demo']->map->title);
    }

    public function testAPathListIsPreservedInOrder(): void
    {
        $sources = ConfigLoader::sourcesFromArray(self::minimalSource([
            'map' => ['ref' => 'id', 'commune' => ['city', 'address.city']],
        ]));

        self::assertSame(['city', 'address.city'], $sources['demo']->map->commune);
    }

    // ---------------------------------------------------------------- commune filter

    public function testCommuneKeyNormalisesSeparatorsAndAccentsButNotWordBoundaries(): void
    {
        self::assertSame('maisons laffitte', Criteria::communeKey('Maisons-Laffitte'));
        self::assertSame('maisons laffitte', Criteria::communeKey('MAISONS LAFFITTE'));
        self::assertSame('le vesinet', Criteria::communeKey('Le Vésinet'));
        // Separators normalise; they do not vanish. Otherwise `levesinet` would match `Le Vésinet`.
        self::assertNotSame(Criteria::communeKey('Le Vésinet'), Criteria::communeKey('Levesinet'));
    }

    public function testTwoCommunesThatNormaliseTheSameAreRefused(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~normalise to the same commune~');

        ConfigLoader::criteriaFromArray(self::minimalCriteria([
            'communes' => ['Maisons-Laffitte', 'maisons laffitte'],
        ]));
    }

    public function testTheCommuneFilterReadsTheCommuneFieldNotTheDescription(): void
    {
        // The prototype searched `commune + cp + title + raw_text` as one haystack, so a Paris flat
        // whose description said "proche Chatou" passed the commune filter. There is no way to
        // reproduce that here, because the description is not an argument.
        //
        // Built explicitly rather than read from the shipped file, since 2026-08-22. It used to use
        // the shipped criteria and assert that Paris 17e was refused — which stopped being true
        // when the region widened to all eight Île-de-France departements, and 75 became a
        // perfectly good match. That would have been a test failing for a reason that has nothing
        // to do with what it is named after: this is about WHICH FIELD is read, not about which
        // communes are wanted this month.
        $c = ConfigLoader::criteriaFromArray([
            'communes' => ['Chatou'],
            'postcode_prefixes' => ['78'],
        ]);

        self::assertFalse($c->matchesCommune('Paris 17e', '75017'));
        self::assertTrue($c->matchesCommune('Chatou', '78400'));
    }

    public function testAnUnknownPostcodeIsNotADisqualification(): void
    {
        // LIST MODE only, and now built explicitly rather than read from the shipped file — the
        // shipped config went to region mode on 2026-08-22, where an unknown postcode IS a
        // disqualification because it is the only evidence there is. Both rules are real; this one
        // belongs to the mode that has a name to match on first. See
        // testRegionModeRefusesAListingWhosePostcodeIsUnknown for the other half.
        $listMode = ConfigLoader::criteriaFromArray([
            'communes' => ['Chatou'],
            'postcode_prefixes' => ['78'],
        ]);

        self::assertTrue($listMode->matchesCommune('Chatou', null));
    }

    public function testAPostcodeFromAnotherDepartementRejectsASameNamedCommune(): void
    {
        // This is the prefix filter's actual job. Argenteuil is 95100; Argenteuil-sur-Armançon is 89.
        self::assertFalse($this->shipped()->matchesCommune('Argenteuil', '89160'));
        self::assertTrue($this->shipped()->matchesCommune('Argenteuil', '95100'));
    }

    public function testEveryShippedCommuneIsInAConfiguredPostcodePrefix(): void
    {
        // The stated justification for removing prefix 92 was "every commune is in 78 or 95". If a
        // commune is ever added that is not, this fails — because the two keys would then silently
        // disagree and the new commune could never match.
        //
        // Now written to hold in BOTH modes rather than pinning the count of ten, which the shipped
        // file stopped having on 2026-08-22. The invariant was never really "there are ten"; it was
        // "no configured commune sits outside the configured prefixes", and that is what is checked
        // — vacuously true in region mode, where the check that matters instead is that the prefixes
        // are non-empty, since they are then the entire filter.
        // The literal prefix list is pinned by `testTheShippedCriteriaFileLoads`, which is where a
        // criteria edit should fail. Here only the property this test is ABOUT is asserted, so a
        // region change does not redden two tests for one reason.
        $c = $this->shipped();
        self::assertNotSame([], $c->postcodePrefixes);

        $outside = array_values(array_filter(
            array_keys($c->communeLabels),
            static fn (string $key): bool => !in_array($key, $c->communes, true),
        ));

        if ($c->communes === []) {
            // Region mode: the prefixes ARE the filter, so an empty one would be a filter that
            // admits everything. The loader refuses that; this is the shipped-file half of it.
            self::assertNotSame([], $c->postcodePrefixes, 'region mode with no prefixes admits all of France');

            return;
        }

        self::assertSame([], $outside, 'a commune with a label but no filter entry can never match');
    }

    public function testARankedCommuneMustAlsoBeFiltered(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~ranked but not in `communes`~');

        ConfigLoader::criteriaFromArray(self::minimalCriteria([
            'commune_rank' => ['Poissy' => 1],
        ]));
    }

    // ---------------------------------------------------------------- region mode (2026-08-22)
    //
    // `communes: []` means "do not check the NAME — the postcode prefixes decide". It exists
    // because there was no way to say "anywhere in 78 or 95": the prefixes are an AND that only
    // ever narrows a name match, so a departement-wide watch had to be written out commune by
    // commune, and any commune not thought of was silently invisible.
    //
    // It is the one loosening in this file that can fail OPEN, so the tests below are written from
    // that direction: what still gets REJECTED matters more than what now passes.

    public function testRegionModeMatchesAnyCommuneInsideTheConfiguredPrefixes(): void
    {
        $c = ConfigLoader::criteriaFromArray([
            'communes' => [],
            'postcode_prefixes' => ['78', '95'],
        ]);

        self::assertSame([], $c->communes);
        // None of these is in the shipped ten-commune list; all are in the two departements.
        self::assertTrue($c->matchesCommune('Saint-Germain-en-Laye', '78100'));
        self::assertTrue($c->matchesCommune('Osny', '95520'));
        self::assertTrue($c->matchesCommune('Mantes-la-Jolie', '78200'));
    }

    public function testRegionModeStillRejectsEverythingOutsideThePrefixes(): void
    {
        // The whole safety of region mode rests on this. If the prefix check were skipped along
        // with the name check, `communes: []` would quietly become "anywhere in France" — and the
        // failure is invisible, because over-matching looks like a busy market, not a broken filter.
        $c = ConfigLoader::criteriaFromArray([
            'communes' => [],
            'postcode_prefixes' => ['78', '95'],
        ]);

        self::assertFalse($c->matchesCommune('Paris 17e', '75017'));
        self::assertFalse($c->matchesCommune('Nantes', '44300'));
        self::assertFalse($c->matchesCommune('Vincennes', '94300'));
    }

    public function testRegionModeRefusesAListingWhosePostcodeIsUnknown(): void
    {
        // DELIBERATELY the opposite of list mode, where an unknown postcode is not a
        // disqualification (hard rule 9: unknown is not wrong). In list mode the NAME has already
        // matched, so the postcode is only narrowing a decision already made on real evidence. In
        // region mode the postcode is the ONLY evidence there is — accepting an unknown one would
        // admit every listing on earth that failed to state one, which is fail-open.
        $c = ConfigLoader::criteriaFromArray([
            'communes' => [],
            'postcode_prefixes' => ['78', '95'],
        ]);

        self::assertFalse($c->matchesCommune('Sartrouville', null));
        self::assertFalse($c->matchesCommune('Sartrouville', 'n/a'));
    }

    public function testRegionModeWithNoPrefixesIsRefused(): void
    {
        // Both filters empty is "notify me about every rental in France". Nobody means that, and
        // the shape it arrives in is an edit that empties `communes` and forgets the prefixes.
        // Pinned to the SPECIFIC refusal, not just to the word "communes". Written loosely first,
        // this passed before region mode existed at all — an empty `communes` was refused outright,
        // so the assertion was satisfied by the very rule it is meant to outlive.
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~region mode.*every rental in France~');

        ConfigLoader::criteriaFromArray([
            'communes' => [],
            'postcode_prefixes' => [],
        ]);
    }

    public function testRegionModeKeepsCommuneRankAsPurePreference(): void
    {
        // In list mode a rank outside `communes` is dead config and is refused. In region mode
        // there is no list to be outside of, so the same check would reject EVERY rank — forcing
        // anyone who widens to a departement to delete their ordering, which is the half of the
        // configuration that says where they actually want to live.
        $c = ConfigLoader::criteriaFromArray([
            'communes' => [],
            'postcode_prefixes' => ['78', '95'],
            'commune_rank' => ['Sartrouville' => 1, 'Poissy' => 3],
        ]);

        self::assertSame(1, $c->communeRank[Criteria::communeKey('Sartrouville')] ?? null);
        self::assertSame(3, $c->communeRank[Criteria::communeKey('Poissy')] ?? null);
    }

    public function testRegionModeStillGivesTheAlertParserAVocabulary(): void
    {
        // A REGRESSION REGION MODE CAUSED, caught by an unrelated suite. `communeLabels` is not
        // only for `reasons[]` — EmailAlertSource scans an alert body with it, because a commune is
        // the one field an alert reliably carries and Q32 makes missing location a rejection. Built
        // from `communes` alone, region mode emptied it and every emailed listing lost its commune:
        // no S1 score, nothing to name in the notification, a weaker dedup key — and the listing
        // still matched on its postcode, so nothing looked broken.
        $c = ConfigLoader::criteriaFromArray([
            'communes' => [],
            'postcode_prefixes' => ['78', '95'],
            'commune_rank' => ['Sartrouville' => 1, 'Le Vésinet' => 2],
        ]);

        self::assertSame('Sartrouville', $c->communeLabels[Criteria::communeKey('Sartrouville')] ?? null);
        self::assertSame('Le Vésinet', $c->communeLabels[Criteria::communeKey('Le Vésinet')] ?? null);
    }

    public function testInListModeARankAddsNoVocabularyBeyondTheCommuneList(): void
    {
        // The other half: the union must not become a back door for naming a commune that is not
        // filtered on. It cannot, because a rank outside `communes` is still refused in list mode —
        // this pins that the two rules stay consistent with each other.
        $c = ConfigLoader::criteriaFromArray([
            'communes' => ['Sartrouville', 'Houilles'],
            'postcode_prefixes' => ['78'],
            'commune_rank' => ['Sartrouville' => 1],
        ]);

        self::assertSame(
            ['houilles', 'sartrouville'],
            self::sorted(array_keys($c->communeLabels)),
        );
    }

    /** @param list<string> $values @return list<string> */
    private static function sorted(array $values): array
    {
        sort($values);

        return $values;
    }

    // ---------------------------------------------------------------- exclusion scope

    #[DataProvider('exclusionCases')]
    public function testExclusionScope(string $title, string $description, bool $expectExcluded, string $why): void
    {
        $fired = $this->shipped()->excludedBy($title, $description);

        $expectExcluded
            ? self::assertNotNull($fired, $why)
            : self::assertNull($fired, $why . ($fired === null ? '' : " — fired on: {$fired}"));
    }

    public function testAnUnfoldableCommuneRankLabelIsRefusedAtLoad(): void
    {
        // The asymmetry a listing-side fix opened. `communeKey()` used to THROW on a name it could
        // not fold, so a malformed rank label failed loudly here. Since it returns `''` instead — so
        // one unreadable listing commune cannot abort a whole pass — `commune_rank['']` became
        // constructible in REGION MODE, where the "ranked but not in communes" check is deliberately
        // skipped. `rankOf()` has no `''` guard, so every listing with an unfoldable commune was
        // then awarded that rank: a review panel measured one scoring "commune de premier choix".
        //
        // Config is the input that should fail loudly. A fix for listing data must not widen it.
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~commune_rank~');

        ConfigLoader::criteriaFromArray(self::minimalCriteria([
            'communes' => [],
            'postcode_prefixes' => ['78'],
            'commune_rank' => ['Ch&acirc;teau' => 1],
        ]));
    }

    public function testAnUnfoldableDescriptionStillLeavesTheTitleCHECKED(): void
    {
        // TWO SURFACES, TWO try BLOCKS. The first version of the malformed-text fix wrapped both
        // folds in one `try`, so an unfoldable DESCRIPTION skipped the TITLE patterns as well — and
        // the trigger is not exotic: `Text` refuses any undecoded HTML entity, commoner in a scraped
        // payload than cp1252. A review panel measured the consequence on 2026-08-24: this exact
        // parking ad stopped being rejected and landed in the *à vérifier* channel, which is §1's
        // landing zone, silently and per listing.
        $fired = $this->shipped()->excludedBy('Parking en sous-sol', 'Belle vue,&nbsp;calme.');

        self::assertNotNull($fired, 'a readable title must still be checked when the description cannot be folded');
    }

    public function testAnUnfoldableTITLEIsInconclusiveRatherThanFatal(): void
    {
        // The other half, and the direction is deliberate: inconclusive, never a rejection. It
        // cannot turn an unreadable listing into a match, because the classifier refuses the same
        // text and yields UNKNOWN, so `judge()` reaches its digest branch. Throwing here aborted the
        // judging loop for every listing after the bad one.
        self::assertNull($this->shipped()->excludedBy("Parking&#039;", 'Belle vue, calme.'));
    }

    /** @return iterable<string, array{string, string, bool, string}> */
    public static function exclusionCases(): iterable
    {
        // COLIVING SPELLS ITSELF THREE WAYS, and `\bcoliving\b` caught only one (Track 1i).
        // `Co-living - grande suite parentale coeur de ville` was a real NOTIFIED MATCH on
        // 2026-08-30: a room in a shared flat, passing every numeric filter because it advertises
        // the whole flat's size. Trialled over all 1 905 stored listings before shipping — the
        // repo's own rule for a pattern change — the widened form catches exactly one more row,
        // that one, and loses none.
        yield 'a hyphenated coliving room is excluded' => [
            'Co-living - grande suite parentale coeur de ville',
            'Belle suite dans un appartement partage.',
            true,
            'the real notified MATCH of 2026-08-30 that `\bcoliving\b` could not see',
        ];
        yield 'a spaced coliving room is excluded' => [
            'Co living maison partagee',
            'Chambre dans une maison partagee.',
            true,
            'the same word with a space instead of a hyphen',
        ];
        yield 'the unhyphenated spelling still fires' => [
            'Coliving Paris 15e',
            'Chambre en coliving.',
            true,
            'the widened pattern must not lose what the narrow one caught',
        ];
        yield 'an ordinary flat near a place called Colivia is not excluded' => [
            'T4 Sartrouville 85m2',
            'Proche du parc, calme, lumineux. Aucun rapport avec la colocation.',
            true,
            'colocation still fires — this case exists to keep the two patterns distinguishable',
        ];
        yield 'fitted kitchen is not a furnished let' => [
            'T4 Sartrouville 85m2',
            'Cuisine équipée et meublée, séjour lumineux.',
            false,
            "the prototype's `meubl` excluded this exact listing — it is the bug F9 was rewritten to fix",
        ];
        yield 'a furnished let is excluded' => [
            'Appartement meublé T4 Houilles',
            'Entièrement meublé, disponible immédiatement.',
            true,
            'a furnished let is genuinely out of scope',
        ];
        // ── furnished lets whose title the keyword pattern could not reach ───────────────────────
        //
        // `exclude_patterns` carries `\b(?:location|louer|loue|appartement|logement|studio|bien|
        // t[1-9])\s+meuble`, which requires the keyword to sit IMMEDIATELY before `meublé`.
        // Measured over all 747 distinct stored titles on 2026-08-26: **29 contain `\bmeuble` and
        // 15 of them escape**, and the dominant shape is the one the keyword can never reach —
        // `<n> pièces meublé`, where the room count sits in between. `maison` and `duplex` were
        // simply absent from the list, and one title leads with the bare word.
        //
        // The fix is a TITLE-ONLY `\bmeuble`, and it must be title-only: the very first case in
        // this provider is a fitted kitchen in a DESCRIPTION, which is what a family flat has.
        // Measured on the same 747 titles: zero carry `cuisine meublée`, zero carry a negation, and
        // a bare `\bmeuble` produces zero false positives. **Stated cost:** a title advertising a
        // fitted kitchen rather than a furnished let would now be rejected — none exists today.
        yield 'a furnished let whose room count sits between the keyword and the word' => [
            'À louer Grand 3 pièces meublé 66 m² Rénové à neuf',
            'Proche gare.',
            true,
            'the keyword pattern needs `louer meuble` adjacent; the room count defeats it',
        ];
        yield 'a furnished let advertised as a house' => [
            'Maison meublée 3 pièces 83 m²',
            'Jardin clos.',
            true,
            '`maison` was never in the keyword list at all — captured live from Bien\'ici',
        ];
        yield 'a furnished let whose title leads with the bare word' => [
            'MEUBLE - RUE WAGRAM',
            'Beau volume.',
            true,
            'captured live from SeLoger: no keyword precedes it because nothing precedes it',
        ];
        yield 'a furnished let with the word parenthesised' => [
            'CACHAN - 3 PIECES (meuble) - 50,35 m2',
            'Proche RER B.',
            true,
            'captured live: punctuation between the room count and the word',
        ];
        yield 'a furnished let written as 3P' => [
            'Beau 3P MEUBLE 59m2 lumineux et au calme',
            'Calme et lumineux.',
            true,
            'captured live: `3P` is not `t[1-9]`, so the keyword list never saw this one',
        ];
        yield 'a flat explicitly NOT furnished is wanted' => [
            'Grand 3 pieces non meuble, Houilles',
            'Cuisine equipee.',
            false,
            'THE NEGATION: a bare \\bmeuble would reject the exact flat the criteria are looking for, '
                . 'and a disqualifier rejects SILENTLY — nothing would ever say why it vanished',
        ];
        // ── Track 7-D: the negation on the OTHER side of the word (2026-09-08) ───────────────────
        //
        // `exclude_patterns` scans title AND description, and it was safe only by ADJACENCY — a
        // trigger word must sit immediately before `meuble`, which is why `location non meublée`
        // escapes and why the 8 In'li rows escape (their negation is about a KITCHEN, and `cuisine`
        // is not a trigger). What was NOT safe is a negation AFTER the word: these two shapes were
        // rejected outright, and no stored row carries either — 152 rows match the meublé rule, 13
        // carry a negated meublé, and the two sets are DISJOINT. **So no fixture can reach this and
        // only these cases can**, which is exactly the dead-safety-code trap this repo has walked
        // into twice.
        yield 'a flat offered furnished OR NOT is wanted' => [
            'Appartement 3 pieces',
            'Appartement meuble ou non, au choix du locataire.',
            false,
            'THE NEGATION AS AN ANSWER: `ou non` means the flat can be taken unfurnished, which is '
                . 'the flat the criteria are looking for',
        ];
        yield 'a structured field answering no is wanted' => [
            'Appartement 3 pieces',
            'Surface : 82 m2. Appartement meuble : non. Etage : 3.',
            false,
            'the portal template shape — a label and its answer, where the answer is what counts',
        ];
        // THE COUNTERWEIGHT, and without it the guard is satisfied by never rejecting anything.
        // The broad form of this lookahead (a 15-character window for non|pas|sans, copied from
        // `exclude_title_patterns`) lets BOTH of these through, which is why the shipped one admits
        // only `ou non` and `: non`.
        yield 'a furnished flat that merely mentions a nearby landmark is still rejected' => [
            'Appartement 3 pieces',
            'Appartement meublee, non loin de la gare RER.',
            true,
            '`non loin` is ordinary French on a genuinely furnished flat — a wide negation window '
                . 'would let it through',
        ];
        yield 'a furnished flat that mentions what it lacks is still rejected' => [
            'Appartement 3 pieces',
            'Appartement meuble, pas de vis-a-vis.',
            true,
            'so is `pas de` about something else entirely',
        ];
        yield 'a title naming the building is wanted' => [
            'Appartement 4 pieces dans immeuble recent',
            'Au 3e etage avec ascenseur.',
            false,
            'THE WORD BOUNDARY: `immeuble` contains `meubl`, and it is in almost every description '
                . 'in the store — an unanchored pattern would reject the entire tree',
        ];
        yield 'a flat with a parking space is wanted' => [
            'T4 Chatou 82m2 avec parking',
            'Box en sous-sol inclus, cave et ascenseur.',
            false,
            'parking, box and cave are amenities here, not the property type',
        ];
        // ── coliving rooms, captured from a live SeLoger alert on 2026-08-25 ──────────────────────
        //
        // **THE ROOMS AND SURFACE BELONG TO THE FLAT; THE RENT BELONGS TO ONE BEDROOM.** SeLoger
        // markets a room in a shared flat as `Chambre à <quartier>, <rue>` with the WHOLE
        // apartment's figures beside it, so the criteria engine reads `4 pièces . 90 m² . 1 195 €`
        // and sees an extraordinary family flat. Four of nine real listings in the first live pass
        // were these, and all four scored as matches.
        //
        // `exclude_patterns` already carries `\bcolocation\b` and `\bcoloc\b` — and **neither word
        // appears anywhere in these listings** [Verified 2026-08-25: `coloc? False` against the
        // stored evidence of both]. The spec's `colocation` case arrived in a vocabulary the
        // pattern could not see.
        //
        // It belongs in the TITLE list, anchored, for exactly the reason that list exists: a
        // description saying "3 chambres" describes a family flat and is precisely what the user
        // wants, while a title *beginning* `Chambre` is the property type. Stated cost: a
        // whole-flat listing whose title starts with the word `Chambre` would be rejected.
        yield 'a coliving room is excluded, though it quotes the flat as 4 pieces' => [
            'Chambre à Picpus, rue de Picpus',
            '1 195 €/mois charges comprises. 4 pièces . 90 m². Nation-Picpus, Paris 12ème.',
            true,
            'the rent is for one bedroom while the rooms and surface are the whole flat',
        ];
        yield 'a second coliving room, with no coloc vocabulary anywhere' => [
            'Chambre à Saint Lambert, rue Olier',
            "Vous souhaitez investir dans l'immobilier locatif ? 1 070 €/mois charges comprises. 3 pièces . 80 m².",
            true,
            'the existing colocation patterns cannot fire — the word is simply not there',
        ];
        yield 'a flat with three bedrooms is wanted' => [
            'Appartement 4 pièces Sartrouville',
            'Séjour double et 3 chambres, dont une chambre parentale avec dressing.',
            false,
            'the counterweight: `chambre` in a DESCRIPTION is what a family flat is made of',
        ];
        yield 'a flat whose title counts its bedrooms is wanted' => [
            'T4 avec 3 chambres, Houilles',
            'Lumineux, proche RER.',
            false,
            'and in a title too, when it is a count rather than the property type at the front',
        ];

        // A ROOM IS A NOUN, NOT A POSITION (2026-08-29). `^\s*chambre\b` was the first cut, and it
        // read the room as the FIRST WORD of the title. Three live titles defeated it in one week —
        // a leading emoji, an adjective, a plural mid-sentence — and the last was pushed as a match
        // at 20:04 on the day this was written. Measured over all 1 593 stored titles: the
        // replacement catches 48 room rentals (the anchored form: 36) and zero flats, because a
        // flat COUNTS its bedrooms ("3 chambres", "Trois chambres", "2 chambres") while a room
        // rental NAMES one. That count is what the lookbehinds protect.
        yield 'a room rental behind a leading emoji is excluded' => [
            '✅ Chambre 10 min RER B TGV Massy Palaiseau',
            '725 €/mois charges comprises. 5 pièces . 79 m².',
            true,
            'the anchor ^\s*chambre saw the emoji first and let the room through, at 20:04 on 2026-08-29',
        ];
        yield 'a room rental behind an adjective is excluded' => [
            'Confortable chambre individuelle',
            '1 125 €/mois charges comprises. 4 pièces . 80 m².',
            true,
            'the room is not the first word, and the flat\'s 4 pièces / 80 m² pass every numeric filter',
        ];
        yield 'rooms in the plural, mid-title, are excluded' => [
            'Belles chambres dans appartement lumineux 53m²',
            '630 €/mois charges comprises. 4 pièces . 53 m².',
            true,
            '630 € for one room of a 53 m² flat — the coliving shape a fourth way',
        ];
        // ADJECTIVE TOLERANCE (2026-08-30). A review panel measured the first noun form against
        // plausible titles: "3 belles chambres" was REJECTED because the lookbehind exempted a count
        // only when it sat IMMEDIATELY before the noun, and "chambre de service" / "chambre d'amis"
        // — room TYPES that describe a flat — were rejected as room rentals. Silent over-rejection
        // on a hard disqualifier (hard rule 8). Re-measured over 1 107 stored titles: the same 40
        // room rentals caught, zero flats, and the four shapes below now survive.
        yield 'a count with an adjective before the noun is wanted' => [
            'Appartement 3 belles chambres',
            'Proche gare.',
            false,
            'the count is one word away from the noun, and it is still a count',
        ];
        yield 'a chambre de service describes the flat, not a room to let' => [
            'Appartement 4 pièces, chambre de service au 6e',
            'Proche gare.',
            false,
            'a room TYPE that a family flat is sold with',
        ];
        yield 'a chambre d\'amis describes the flat too' => [
            'Appartement avec chambre d\'amis et balcon',
            'Proche gare.',
            false,
            'same class: the noun names a feature of the flat',
        ];
        yield 'a maid\'s room to let is still a room to let' => [
            'Chambre de bonne 9m²',
            '450 €/mois charges comprises. 1 pièce . 9 m².',
            true,
            'the tolerance is for counts and flat features, never for the rental itself',
        ];
        yield 'a house counting its bedrooms is wanted' => [
            'Maison 3 chambres au haras du château de abondant',
            '1 100 €/mois charges comprises. 4 pièces . 79 m².',
            false,
            'a digit before the noun is a count, and a count is what a family flat states',
        ];
        yield 'a duplex counting its bedrooms is wanted' => [
            'Duplex 2 chambres rénové 50m2',
            'Proche gare.',
            false,
            'same count, different property type',
        ];
        yield 'a count written in words is still a count' => [
            'Trois chambres et un bureau, Houilles',
            'Lumineux.',
            false,
            '"Trois chambres" is how a French agent writes 3 chambres; folding lowercases it before the pattern runs',
        ];

        yield 'a parking space for rent is excluded' => [
            'Parking en sous-sol - Bezons',
            'Emplacement sécurisé.',
            true,
            'here the same word IS the property type, and it sits at the front of the title',
        ];
        yield 'a garage let is excluded' => [
            'Garage fermé Argenteuil',
            '',
            true,
            'a garage ad states no rooms and no surface, so the size filters cannot catch it',
        ];
        yield 'a flat with a study is wanted' => [
            'T4 Montesson avec bureau',
            'Pièce supplémentaire utilisable en bureau.',
            false,
            'bureau is a room here; only a title STARTING with it is a commercial let',
        ];
        yield 'colocation is excluded' => [
            'Chambre en colocation - Houilles',
            '',
            true,
            'explicitly out of scope',
        ];
        yield 'a student residence is excluded' => [
            'Studio en résidence étudiante',
            '',
            true,
            'accent-folded matching: the pattern is written `etudiante`',
        ];
        yield 'a senior residence is excluded' => [
            'T2 en résidence senior',
            '',
            true,
            'same folding path',
        ];
        // The next two are lifted verbatim from the classifier corpus, where a review showed that
        // adopting F9's suggested `bureau` and `parking` as bare stems would delete them before the
        // classifier's verdict was ever consulted. `trap-010` is asserted to MATCH there.
        yield 'trap-010 corpus text: a study is not a commercial let' => [
            'T4 Croissy-sur-Seine',
            'Trois chambres, plus un bureau ferme et une buanderie.',
            false,
            'a bare `bureau` pattern would silently delete a corpus case asserted to MATCH',
        ];
        yield 'unknown-001 corpus text: a parking space is not a parking ad' => [
            'Appartement 3 pieces',
            'Appartement 3 pieces, 65 m2, parking.',
            false,
            'a bare `parking` pattern would delete this before the classifier ever ran',
        ];
        // THE PAIR THAT MAKES THE TITLE-ONLY SCOPE FALSIFIABLE. A sabotage run showed that while
        // every title pattern was `^`-anchored, widening the scope to the description was a
        // semantic no-op — a `^` with no `m` flag cannot match inside appended text either way — so
        // the two-list design was protecting nothing a test could disprove. These two use an
        // UNANCHORED title pattern, and they differ only in which field carries the phrase.
        yield 'a parking phrase in the DESCRIPTION is an amenity' => [
            'T4 Sartrouville 88m2',
            'Emplacement de stationnement inclus, cave et ascenseur.',
            false,
            'if the exclusion ever reads the description, this good flat vanishes silently',
        ];
        yield 'the same phrase in the TITLE is the property type' => [
            'Emplacement de stationnement - Sartrouville',
            'Acces badge.',
            true,
            'and if the title scope is dropped entirely, this parking ad gets notified',
        ];
        yield 'loue parking in a title is excluded even unanchored' => [
            'Particulier loue parking - Houilles',
            '',
            true,
            'the anchored pattern misses this: the title does not START with the property type',
        ];
        yield 'an ordinary listing is not excluded' => [
            'Bel appartement T4 - Maisons-Laffitte',
            'Proche RER A, 88m2, 4 pièces, 3e étage avec ascenseur.',
            false,
            'the baseline — if this ever fires, an exclusion pattern has become too broad',
        ];
    }

    public function testNoExclusionPatternMentionsATenure(): void
    {
        // Ruled 2026-08-07: tenure exclusion lives in Tenure::isExcluded() only. A second copy in a
        // user-editable file would be a weaker one, since editing that file is enough to unmake it.
        $c = $this->shipped();
        $all = strtolower(implode(' ', array_merge($c->excludePatterns, $c->excludeTitlePatterns)));

        foreach (['plai', 'plus', 'pls', 'social', 'conventionne', 'anru', 'anah', 'hlm'] as $term) {
            self::assertStringNotContainsString(
                $term,
                $all,
                "'{$term}' belongs to the classifier, not to exclude_patterns — see CLAUDE.md §1",
            );
        }
    }

    public function testAnAccentedPatternIsRefusedRatherThanSilentlyNeverMatching(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~non-ASCII~');

        ConfigLoader::criteriaFromArray(self::minimalCriteria([
            'exclude_patterns' => ['meublé'],
        ]));
    }

    public function testAnUncompilablePatternIsRefused(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~not a valid regular expression~');

        ConfigLoader::criteriaFromArray(self::minimalCriteria([
            'exclude_patterns' => ['(unclosed'],
        ]));
    }

    // ---------------------------------------------------------------- weights & routing

    public function testDisabledCommuteMustNotCarryAWeight(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~`commute.enabled` is false~');

        ConfigLoader::criteriaFromArray(self::minimalCriteria([
            'weights' => ['commute' => 25],
        ]));
    }

    public function testEnabledCommuteMustCarryAWeight(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~weighted 0~');

        ConfigLoader::criteriaFromArray(self::minimalCriteria([
            'commute' => ['enabled' => true, 'station' => 'La Défense', 'max_minutes' => 45],
            'weights' => ['commute' => 0],
        ]));
    }

    public function testEnabledCommuteNeedsSomethingToMeasureAgainst(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~station` or `max_minutes` is missing~');

        ConfigLoader::criteriaFromArray(self::minimalCriteria([
            'commute' => ['enabled' => true],
            'weights' => ['commute' => 25],
        ]));
    }

    public function testAPositiveHighFloorWeightIsRefused(): void
    {
        // A positive "high floor, no lift" weight would be a bonus for the exact thing being escaped.
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~high_floor_no_lift~');

        ConfigLoader::criteriaFromArray(self::minimalCriteria([
            'weights' => ['high_floor_no_lift' => 20],
        ]));
    }

    public function testThePenaltyIsExcludedFromTheNormalisingTotal(): void
    {
        $w = new Weights(commune: 25, commute: 0, rentHeadroom: 15, surface: 10, lift: 15, highFloorNoLift: -20, freshness: 10);
        self::assertSame(75, $w->positiveTotal());
    }

    #[DataProvider('rentDrops')]
    public function testNotableRentDrops(int $previous, int $current, bool $expected, string $why): void
    {
        self::assertSame($expected, (new NotifyPolicy())->isNotableDrop($previous, $current), $why);
    }

    /** @return iterable<string, array{int,int,bool,string}> */
    public static function rentDrops(): iterable
    {
        yield 'a 5 euro correction is noise' => [1800, 1795, false, 'below both thresholds'];
        yield 'a 20 euro drop clears the absolute threshold' => [1800, 1780, true, '240 euros a year'];
        yield 'a small drop on a cheap flat clears the percentage' => [500, 490, true, '2% of 500'];
        yield 'either threshold is enough, not both' => [1800, 1780, true, 'requiring both would silence a 1.1% drop of 20 euros'];
        yield 'a rise is not a drop' => [1700, 1800, false, 'the event is a drop'];
        yield 'no change is not a drop' => [1700, 1700, false, 'equality is not a drop'];
        yield 'a zero previous rent fabricates nothing' => [0, 0, false, 'a previous rent of 0 is not a rent'];
    }

    // ---------------------------------------------------------------- the shipped files

    public function testTheShippedCriteriaFileLoads(): void
    {
        $c = $this->shipped();

        // T3 since 2026-08-22, reversing Q3's T4 on the developer's ruling. Measured that day:
        // 10 of the 13 listings that got past the location filter were rejected here alone, every
        // one of them under the rent ceiling. `min_surface_m2` below keeps it a real filter.
        self::assertSame(3, $c->minRooms);

        // WIDENED and TIGHTENED together on 2026-08-22, developer ruling: all eight Île-de-France
        // departements instead of 78/95, a 50 m² floor instead of 75, and a 1200 € ceiling instead
        // of 1800. The three move as one decision — more places and a smaller flat, in exchange for
        // a real budget — so they are asserted together and a change to any of them fails here.
        //
        // The rent is the binding one, measured rather than assumed: all EIGHT listings that
        // matched under the 1800 ceiling quoted 1258–1669 € CC, so none of them survives 1200.
        self::assertSame(['75', '77', '78', '91', '92', '93', '94', '95'], $c->postcodePrefixes);
        self::assertSame(50.0, $c->minSurfaceM2);
        self::assertSame(1200, $c->maxRentCc);
        self::assertFalse($c->commuteEnabled);
        // 70 → 50, developer ruling 2026-08-26, and it is the FIRST calibration this threshold has
        // ever had. `!!` needs score >= this AND tenure confidence >= 80/100, and measured across
        // all 256 stored v7 snapshots those two are satisfied by DISJOINT sets: the top scorers are
        // private-portal listings whose tenure is the source default at 50/100, while the listings
        // that clear the confidence floor top out at 55. At any threshold >= 60 the marker is
        // unreachable by construction. 50 marks 3 of the 47 confident listings.
        self::assertSame(50, $c->notify->highPriorityScore);
        self::assertSame(['console'], $c->notify->channels);
    }

    public function testTheShippedCriteriaHasNoFloorOrElevatorDisqualifier(): void
    {
        // Ruled 2026-08-07 (Q5). There is no key to set, so the prototype's silent drop cannot be
        // reintroduced by editing config — this asserts the key genuinely does not exist rather
        // than merely being unset.
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~unknown key max_floor~');

        ConfigLoader::criteriaFromArray(self::minimalCriteria(['max_floor' => 1]));
    }

    public function testRequireElevatorIsAlsoNotAKey(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~unknown key require_elevator~');

        ConfigLoader::criteriaFromArray(self::minimalCriteria(['require_elevator' => true]));
    }

    public function testTheShippedSourcesFileLoads(): void
    {
        $sources = ConfigLoader::loadSources(self::ROOT . '/config/rent/sources.json');

        self::assertArrayHasKey('inli', $sources);
        // NOT a weakened assertion: the old label was measurably wrong. In'li shipped as pure LLI,
        // and hydrating its detail pages on 2026-08-23 found two live listings stating `plafond de
        // ressources PLS` that their CARDS never mentioned. Tenure is a property of the listing,
        // never of the source. What makes the flip affordable is the third term on the fail-closed
        // rule — see testAWeaklyLabelledListingOnAMixedSourceDigestsUntilItsDetailPageIsRead —
        // without which all 166 of In'li's 50bp listings would digest for ever.
        self::assertTrue($sources['inli']->mixedTenure, "In'li publishes PLS stock; its cards hide it");
        self::assertTrue($sources['cdc_habitat']->mixedTenure);
        self::assertTrue($sources['cityloger']->mixedTenure, 'the group publishes social and intermediate alike');
    }

    /**
     * A MEASURED DEAD END MUST NOT LOOK LIKE AN UNVERIFIED PLACEHOLDER.
     *
     * `icf_novedis` (A2) and `seqens` (A5) sat here as `enabled: false` blocks whose url read
     * `REMPLACER` — the shape this file uses for "an endpoint nobody has captured yet". Both were
     * then MEASURED (docs/SOURCES.md A2, A5) and publish no pollable vacancies at all: Novedis
     * lists residences with zero rents and zero surfaces, and Seqens dead-ends at al-in.fr because
     * Action Logement's ESH allocate by commission. Waiting on a capture and having no feed to
     * capture are different facts, and a config that renders them identically keeps inviting the
     * work that cannot pay.
     *
     * They are retired from the CONFIG only. docs/SOURCES.md keeps their rows, because the
     * measurement is the record of why nobody should try again, and tests/fixtures/rent/tenure/
     * corpus.json keeps its own `seqens` / `icf_novedis` source entries, which are corpus-local
     * labels and never read this file.
     */
    public function testTheMeasuredDeadEndsAreNotShippedAsPlaceholders(): void
    {
        $sources = ConfigLoader::loadSources(self::ROOT . '/config/rent/sources.json');

        self::assertArrayNotHasKey('icf_novedis', $sources, 'A2 measured non-pollable 2026-08-20');
        self::assertArrayNotHasKey('seqens', $sources, 'A5 measured non-pollable 2026-08-20');
    }

    /**
     * An enabled source must carry its own EVIDENCE of verification.
     *
     * This replaces a blanket "no source may be enabled at all", which was the right assertion
     * while no endpoint in the repo had ever been checked and became false on 2026-08-19, when
     * In'li was fetched live with `robots.txt` read first and its payload frozen into
     * `tests/fixtures/rent/inli/`. Deleting the test would have removed the guard along with the
     * premise, so the guard is restated against what actually makes enabling legitimate.
     *
     * What it now demands of every enabled non-fixture source, and why each one:
     *
     * - **`_verified_at`** — hard rule 1 is a claim about a MOMENT ("verified against the live
     *   site"). Undated, it decays into folklore, and nobody can tell a checked endpoint from an
     *   inherited one.
     * - **`_source`** — how it was verified, so the next person can repeat it rather than trust it.
     * - **no `REMPLACER` anywhere in the block** — the loader guards the `url`; a placeholder can
     *   also sit in `items_path`, `item_selector` or any map entry, and there it fails at fetch
     *   rather than at load.
     * - **an `item_selector` for `type: html`** — without it the adapter matches nothing, and
     *   matching nothing is this project's signature silent failure.
     */
    /**
     * A demo fixture may not ship enabled.
     *
     * `fixture_demo` is not a landlord: it is a frozen payload that exercises the pipeline offline.
     * It shipped `enabled: true` from before any real endpoint existed, when it was the only way
     * `scout run --once` could be demonstrated end to end — and it stayed enabled after four real
     * sources went live, where it stopped being a demo and became ten fabricated listings inside a
     * real deployment's store, counted in every pass total, every `doctor` table and every
     * heartbeat source count.
     *
     * Deliberately NOT folded into the evidence test below, which carves fixtures out on purpose
     * (a fixture has no endpoint to have verified). The two ask opposite questions about the same
     * flag, so they stay separate assertions with separate failure messages.
     */
    public function testNoFixtureSourceShipsEnabled(): void
    {
        foreach (ConfigLoader::loadSources(self::ROOT . '/config/rent/sources.json') as $name => $s) {
            if ($s->type !== 'fixture') {
                continue;
            }

            self::assertFalse(
                $s->enabled,
                "source '{$name}' is a fixture and ships enabled — a real deployment would count "
                    . 'listings that do not exist, and would notify them on any path that loses the '
                    . 'seen-set. Run it with `--source=' . $name . '`, which force-runs a disabled source',
            );
        }
    }

    public function testEveryEnabledSourceCarriesTheEvidenceThatItWasVerified(): void
    {
        $raw = json_decode((string) file_get_contents(self::ROOT . '/config/rent/sources.json'), true);
        self::assertIsArray($raw);

        $enabled = [];
        $withDetail = [];
        $detailBudgets = [];

        foreach (ConfigLoader::loadSources(self::ROOT . '/config/rent/sources.json') as $name => $s) {
            if (!$s->enabled || $s->type === 'fixture') {
                continue;
            }

            $enabled[] = $name;
            $block = $raw['sources'][$name] ?? null;
            self::assertIsArray($block, "source '{$name}' is enabled but has no block in the file");

            self::assertArrayHasKey('_verified_at', $block, "source '{$name}' is enabled without recording WHEN its endpoint was verified (hard rule 1)");
            self::assertArrayHasKey('_source', $block, "source '{$name}' is enabled without recording HOW its endpoint was verified");
            self::assertStringNotContainsString(
                'REMPLACER',
                (string) json_encode($block),
                "source '{$name}' is enabled with a placeholder still somewhere in its block",
            );

            if ($s->type === 'html') {
                self::assertNotNull($s->itemSelector, "html source '{$name}' is enabled with no item_selector — it would match nothing and report calm");
            }

            if ($s->detailMap !== null) {
                $withDetail[] = $name;
                $detailBudgets[$name] = $s->detailBudgetPerPass;
            }
        }

        // A detail map costs one request PER LISTING on top of the page walk, so a source acquiring
        // one is a request-volume decision and not a mapping tweak. Named for the same reason the
        // enabled set is.
        //
        // In'li joined on 2026-08-23. The volume question it raises is real and is answered
        // elsewhere rather than here: ~174 listings, all novel on the first pass, would be a
        // three-hour pass at Q37 pacing. It is bounded by `detail_budget_per_pass` (20 by default,
        // draining the backlog over several passes) and by the schema-v5 cache, which makes steady
        // state zero extra requests — a page is read once and read back for ever after.
        self::assertSame(['inli', 'cityloger'], $withDetail, 'the set of sources that fetch detail pages changed');

        // The bound itself, asserted where the decision is recorded. A detail map shipping with an
        // unbounded budget is the request-volume regression this whole guard is watching for.
        foreach ($detailBudgets as $name => $budget) {
            self::assertLessThanOrEqual(
                25,
                $budget,
                "source '{$name}' fetches detail pages with a per-pass budget large enough to be a crawl",
            );
        }

        // Named rather than counted: the day a second source is enabled, this line is the one that
        // makes someone confirm it was a decision.
        // `seloger` joined 2026-08-25 — the fifth source, and the first that is not a landlord. It
        // is an `email_alert`, so it makes no outbound web request at all: the cost of enabling it
        // is one IMAP session per pass, not a crawl.
        // `bienici` joined the same day, the second portal on that route. Same cost, and it shares
        // seloger's IMAP session budget rather than adding one: each email source scopes its own
        // `SEARCH … FROM`, which is why `params.from` is now refused at load when it is missing.
        // `leboncoin` joined 2026-08-26, the third portal and the seventh source. Same IMAP cost —
        // and it is the first HTML-ONLY alert, which needed `EmailMessage::harvestHrefs()` before it
        // could yield anything at all: every URL lived in an `href` that `strip_tags()` removed, so
        // the parser produced a full body and ZERO links.
        // `pap` joined 2026-08-26, the fourth portal and the eighth source. Same IMAP cost, sharing
        // the same session budget. It is the first DIRECT-FROM-OWNER portal, so its inventory does
        // not overlap the agency portals, and the simplest shape in the tree — one listing per
        // message, no `card_separator` at all. What it cost was two defects a naive config walks
        // into, both measured: a host-only `link_host` accepted the unsubscribe page as a second
        // listing, and the alert quotes the subscriber's own search criteria ABOVE the listing, so
        // the first-match-wins surface reader returned the 45 m² search floor instead of the flat's
        // 50 — below `min_surface_m2`, so the first PAP alert ever sent would have been rejected as
        // too small, silently.
        self::assertSame(
            ['inli', 'cdc_habitat', 'cityloger', 'seloger', 'bienici', 'leboncoin', 'pap', 'logirep'],
            $enabled,
            'the set of enabled network sources changed',
        );
    }

    /**
     * A detail map may not redefine `ref`, and the loader says so rather than ignoring it.
     *
     * Identity belongs to the card, because the seen-set is keyed on it: a listing re-identified
     * from its detail page has never been seen before and is announced again on every run. The
     * merge already ignores detail identity, so a `ref` here would be config that reads as
     * behaviour and does nothing — the shape this file exists to refuse.
     */
    public function testADetailMapMayNotRedefineTheRef(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('/identity comes from the card/');

        ConfigLoader::sourcesFromArray(['sources' => ['x' => [
            '_verified_at' => '2026-08-21',
            '_source' => 'a test',
            'enabled' => true,
            'family' => 'institutional',
            'type' => 'html',
            'mixed_tenure' => true,
            'url' => 'https://example.test/search',
            'item_selector' => 'a.card',
            'map' => ['ref' => '@href', 'url' => '@href'],
            'detail_map' => ['ref' => '.detail-id', 'description' => '.description'],
        ]]]);
    }

    /**
     * A budget of ZERO is refused, because it is a disabled feature dressed as a configured one.
     *
     * This replaces the older "a detail_map with no gate REFUSES" invariant, which retired when the
     * gate became novelty — supplied by the run rather than configured, so there is no longer a
     * gate to be missing. What that invariant protected is still real: a detail_map that quietly
     * never runs leaves a mixed-tenure source resolving UNKNOWN for ever while its health stays
     * green and its count looks right, which is the silent shape this project refuses.
     *
     * Omitting the key is NOT refused and must not be: it defaults, and the cost of the default is
     * a slow cold start, which is benign and self-correcting. The asymmetry is the point, and it is
     * the same one an unusable RENT_HEARTBEAT_HOURS gets.
     */
    public function testADetailMapWithAZeroBudgetIsRefused(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('/can never run/');

        ConfigLoader::sourcesFromArray(['sources' => ['x' => self::htmlSourceWithDetailMap(['detail_budget_per_pass' => 0])]]);
    }

    /**
     * **`card_separator` on a `mixed_tenure` source is REFUSED, and the refusal is the §1 answer.**
     *
     * Segmenting an alert means each listing's description is its own CARD rather than the whole
     * message. That is more correct — the Cityloger ruling is that a map must address the listing,
     * never the page, and a mail trailer is furniture by construction — but it costs a signal:
     * a batch-level `logement conventionné` stated once for a whole digest is no longer visible to
     * any card's classifier.
     *
     * On a pure-`LIBRE` portal that costs nothing. On a source that mixes social and intermediate
     * stock it is a §1 decision, and nobody has made it against a real payload, because no such
     * source exists yet: seloger and leboncoin are `LIBRE`, and `email_demo` is mixed and
     * unsegmented. Refusing the combination at load forces whoever onboards the first mixed
     * segmented portal to decide it with a real message in front of them, instead of inheriting a
     * silent answer from today. Same shape as `detail_budget_per_pass: 0`.
     *
     * Deliberately OUTSIDE the `enabled` branch: `--source=<name>` force-runs a disabled source, so
     * a guard that only fires on enabled ones is a guard the documented onboarding path walks past.
     */
    public function testASegmentedMixedTenureSourceIsRefused(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('/mixed_tenure/');

        ConfigLoader::sourcesFromArray(['sources' => ['x' => [
            'enabled' => false,
            'family' => 'private',
            'type' => 'email_alert',
            'mixed_tenure' => true,
            'params' => ['card_separator' => "Voir l'annonce"],
            'map' => ['ref' => 'url', 'charges_included' => true],
        ]]]);
    }

    /**
     * THE SAME REFUSAL, REACHED THROUGH THE OTHER SEPARATOR — and it was not there.
     *
     * `card_separator_pattern` is a second segmentation mechanism added on 2026-08-31 for Bien'ici,
     * and `EmailAlertSource::segments()` honours it IN PREFERENCE to the literal. Both §1-adjacent
     * guards above tested `card_separator` alone, so every one of them could be defeated by writing
     * the same configuration in the regex form — with the whole suite and the whole sabotage ledger
     * green, because all 16 of their occurrences there are the literal.
     *
     * That is `CLAUDE.md` §1 verbatim: *a config key that can re-enable an excluded regime is P0
     * even if nothing currently sets it*. It is also this repo's named recurring defect — a rule
     * applied to one of two symmetric surfaces — and the third guard added in the SAME commit
     * (`card_separator` and `card_separator_pattern` both set) already reads both keys, which is
     * what makes this a slip rather than a design.
     *
     * Found by the C2 round-1 correctness lens, 2026-09-02.
     */
    public function testASegmentedMixedTenureSourceIsRefusedThroughThePatternFormToo(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('/mixed_tenure/');

        ConfigLoader::sourcesFromArray(['sources' => ['x' => [
            'enabled' => false,
            'family' => 'private',
            'type' => 'email_alert',
            'mixed_tenure' => true,
            'params' => ['card_separator_pattern' => '~^Photo$~m'],
            'map' => ['ref' => 'url', 'charges_included' => true],
        ]]]);
    }

    /** The same source without segmentation loads — the refusal is about the combination. */
    public function testAMixedTenureEmailSourceWithoutSegmentationLoads(): void
    {
        $sources = ConfigLoader::sourcesFromArray(['sources' => ['x' => [
            'enabled' => false,
            'family' => 'private',
            'type' => 'email_alert',
            'mixed_tenure' => true,
            'map' => ['ref' => 'url', 'charges_included' => true],
        ]]]);

        self::assertTrue($sources['x']->mixedTenure);
    }

    /** And a segmented source that is NOT mixed loads, which is the shape SeLoger ships in. */
    public function testASegmentedPureTenureSourceLoads(): void
    {
        $sources = ConfigLoader::sourcesFromArray(['sources' => ['x' => [
            'enabled' => false,
            'family' => 'private',
            'type' => 'email_alert',
            'mixed_tenure' => false,
            'default_tenure' => 'LIBRE',
            'params' => ['card_separator' => "Voir l'annonce", 'id_from' => 'content'],
            'map' => ['ref' => 'url', 'charges_included' => true],
        ]]]);

        self::assertSame("Voir l'annonce", $sources['x']->params['card_separator']);
    }

    /**
     * **`card_separator` without `id_from: content` is refused, because the combination is legal
     * on paper and silently useless in practice.**
     *
     * `identityFor()` returns `null` for anything but `content`, so every card is dropped — and the
     * zero-cards guard then throws *"le gabarit du portail a changé"*. Loud, which is right, and a
     * WRONG DIAGNOSIS, which is not: the template is fine and the config is incoherent. Someone
     * would go looking at the portal's markup for a fault that is three lines away in JSON.
     *
     * It has to be refused rather than defaulted, because `link` is a real answer for a portal
     * whose alerts DO carry listing URLs — silently promoting it to `content` would hand such a
     * source content-addressed ids it never asked for, and change every identity it already has.
     */
    public function testSegmentationWithoutContentIdentityIsRefused(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('/id_from/');

        ConfigLoader::sourcesFromArray(['sources' => ['x' => [
            'enabled' => false,
            'family' => 'private',
            'type' => 'email_alert',
            'mixed_tenure' => false,
            'params' => ['card_separator' => "Voir l'annonce"],
            'map' => ['ref' => 'url', 'charges_included' => true],
        ]]]);
    }

    /**
     * A `title_pattern` or `residence_pattern` that does not compile is refused at load.
     *
     * `matchParam()` uses `@preg_match`, so an unbalanced bracket does not warn and does not throw
     * — it returns `false` and the field is `null` for ever. On `residence_pattern` that is worse
     * than cosmetic: the residence name is one of the three facts the identity floor accepts, so a
     * typo silently narrows what can be identified at all.
     */
    public function testAnUncompilableEmailPatternIsRefused(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('/residence_pattern/');

        ConfigLoader::sourcesFromArray(['sources' => ['x' => [
            'enabled' => false,
            'family' => 'private',
            'type' => 'email_alert',
            'mixed_tenure' => false,
            'params' => [
                'card_separator' => "Voir l'annonce",
                'id_from' => 'content',
                'residence_pattern' => '~^([unclosed~m',
            ],
            'map' => ['ref' => 'url', 'charges_included' => true],
        ]]]);
    }

    /** The shipped seloger patterns compile, asserted against the real file rather than a copy. */
    public function testTheShippedEmailPatternsCompile(): void
    {
        $seloger = ConfigLoader::loadSources(self::ROOT . '/config/rent/sources.json')['seloger'];

        foreach (['title_pattern', 'residence_pattern', 'commune_pattern'] as $key) {
            $pattern = $seloger->params[$key] ?? null;
            self::assertIsString($pattern, "seloger lost its {$key}");
            self::assertNotFalse(@preg_match($pattern, 'Appartement Test,'), "{$key} does not compile");
        }
    }

    /**
     * An uncompilable `commune_pattern` is refused too, and it is the one with an alibi.
     *
     * The other two fail visibly — a listing with no title, an identity floor that turns cards
     * away. This one falls back to the ranked-vocabulary scan, and on a region-mode config that
     * scan knows almost no commune names, so a broken pattern is indistinguishable from a listing
     * in an unranked town. It has to be refused at load or it is never noticed at all.
     */
    public function testAnUncompilableCommunePatternIsRefused(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('/commune_pattern/');

        ConfigLoader::sourcesFromArray(['sources' => ['x' => [
            'enabled' => false,
            'family' => 'private',
            'type' => 'email_alert',
            'mixed_tenure' => false,
            'params' => [
                'card_separator' => "Voir l'annonce",
                'id_from' => 'content',
                'commune_pattern' => '~^([unclosed~m',
            ],
            'map' => ['ref' => 'url', 'charges_included' => true],
        ]]]);
    }

    /**
     * An ENABLED `email_alert` source must name the sender it reads.
     *
     * One mailbox serves every portal — that is the whole point of a single `rent-watch` label —
     * so `params.from` is not a nicety, it is the source's scope. Without it the source reads every
     * message in the folder within the window and ingests other portals' alerts as its own, while
     * `SourceHealth` records a plausible count throughout.
     *
     * It is also what scopes the IMAP query. `ImapMailbox` pushes `FROM` into `SEARCH`, so each
     * source gets its own window rather than a slice of one shared one; without it a busy portal
     * starves a quiet one silently, and it worsens with every source added. Measured 2026-08-25:
     * SeLoger went from 9 listings to 0 when a year of another portal's archive was relabelled into
     * the same folder, and nothing but the health baseline noticed.
     *
     * Refused at LOAD rather than at fetch, because a source that can only fail once it is polling
     * is a source that fails in production. A DISABLED source is left alone: a block being drafted
     * has not claimed to work yet.
     */
    public function testAnEnabledEmailAlertSourceMustNameItsSender(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('/params\.from/');

        ConfigLoader::sourcesFromArray(['sources' => ['x' => [
            'enabled' => true,
            'family' => 'private',
            'type' => 'email_alert',
            'mixed_tenure' => false,
            'params' => ['card_separator' => "Voir l'annonce", 'id_from' => 'content'],
            'map' => ['ref' => 'url', 'charges_included' => true],
        ]]]);
    }

    /** A block still being drafted has not claimed to work yet, so it is left alone. */
    public function testADisabledEmailAlertSourceNeedNotNameItsSenderYet(): void
    {
        $definitions = ConfigLoader::sourcesFromArray(['sources' => ['x' => [
            'enabled' => false,
            'family' => 'private',
            'type' => 'email_alert',
            'mixed_tenure' => false,
            'params' => ['card_separator' => "Voir l'annonce", 'id_from' => 'content'],
            'map' => ['ref' => 'url', 'charges_included' => true],
        ]]]);

        self::assertCount(1, $definitions);
    }

    /**
     * A segmented source keyed on the LINK must say which links are listings.
     *
     * This replaces a blanket *"segmented needs `id_from: content`"*, which was true only while
     * link identity meant the whole message's links — SeLoger's sixteen cards behind one opaque
     * redirect. The segmented path now keys on the card's own last qualifying link, so `link` is a
     * real answer for a portal that publishes listing URLs.
     *
     * What is left to refuse is the shape whose failure is SILENT. Two cards ending on the SAME
     * stray link is caught loudly at fetch. Two cards ending on DIFFERENT rotating advert links is
     * caught by nothing: every card gets a plausible unique id that changes with the next campaign,
     * so the source re-notifies for ever and reads as a busy market.
     */
    public function testASegmentedSourceKeyedOnItsLinksMustSayWhichLinksAreListings(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('/link_host/');

        ConfigLoader::sourcesFromArray(['sources' => ['x' => [
            'enabled' => false,
            'family' => 'private',
            'type' => 'email_alert',
            'mixed_tenure' => false,
            'params' => ['card_separator' => "\nPhoto\n"],
            'map' => ['ref' => 'url', 'charges_included' => true],
        ]]]);
    }

    /**
     * An empty `link_host` is refused like a missing one.
     *
     * `looksLikeAListing()` reads it through `stringParam()`, which treats `''` as unset — so an
     * empty string would satisfy an `isset` check and then make every link qualify, which is the
     * silent failure the refusal exists to prevent, reached by a different mistake.
     */
    public function testAnEmptyLinkHostIsRefusedLikeAMissingOne(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('/link_host/');

        ConfigLoader::sourcesFromArray(['sources' => ['x' => [
            'enabled' => false,
            'family' => 'private',
            'type' => 'email_alert',
            'mixed_tenure' => false,
            'params' => ['card_separator' => "\nPhoto\n", 'link_host' => '  '],
            'map' => ['ref' => 'url', 'charges_included' => true],
        ]]]);
    }

    /**
     * THE LINK-IDENTITY REFUSAL, REACHED THROUGH THE PATTERN FORM — the second half of the same
     * slip. Without it, a source segmented by regex and keyed on its links needs no `link_host`,
     * so a card's identity becomes the last link that happens to sit in it. Two cards ending on the
     * same stray link is caught loudly at fetch; two cards ending on DIFFERENT rotating advert
     * links is caught by nothing, and the whole source re-notifies for ever while reading as a busy
     * market.
     *
     * Bien'ici — the only `card_separator_pattern` user today — does set `link_host`, so there is no
     * live exposure. The finding is the guard.
     */
    public function testASegmentedSourceKeyedOnItsLinksMustSayWhichLinksAreListingsThroughThePatternFormToo(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('/link_host/');

        ConfigLoader::sourcesFromArray(['sources' => ['x' => [
            'enabled' => false,
            'family' => 'private',
            'type' => 'email_alert',
            'mixed_tenure' => false,
            'params' => ['card_separator_pattern' => '~^Photo$~m'],
            'map' => ['ref' => 'url', 'charges_included' => true],
        ]]]);
    }

    /** With `link_host` named, link identity on a segmented source is accepted — Bien'ici's shape. */
    public function testASegmentedSourceKeyedOnNamedListingLinksIsAccepted(): void
    {
        $definitions = ConfigLoader::sourcesFromArray(['sources' => ['x' => [
            'enabled' => false,
            'family' => 'private',
            'type' => 'email_alert',
            'mixed_tenure' => false,
            'params' => ['card_separator' => "\nPhoto\n", 'link_host' => 'example.test/annonce/'],
            'map' => ['ref' => 'url', 'charges_included' => true],
        ]]]);

        self::assertCount(1, $definitions);
    }

    /** An empty string is not a sender — the same refusal, reached by a different mistake. */
    public function testAnEmptySenderIsRefusedLikeAMissingOne(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('/params\.from/');

        ConfigLoader::sourcesFromArray(['sources' => ['x' => [
            'enabled' => true,
            'family' => 'private',
            'type' => 'email_alert',
            'mixed_tenure' => false,
            'params' => ['from' => '   ', 'card_separator' => "Voir l'annonce", 'id_from' => 'content'],
            'map' => ['ref' => 'url', 'charges_included' => true],
        ]]]);
    }

    /**
     * An `id_from` this adapter does not implement is refused rather than ignored.
     *
     * Silently falling back to link-identity is the dangerous direction: on a portal whose links
     * are all tracking redirects that means every card in a message shares one id, which is the
     * exact collapse `id_from: content` exists to prevent — arrived at through a typo.
     */
    public function testAnUnknownIdFromIsRefused(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('/id_from/');

        ConfigLoader::sourcesFromArray(['sources' => ['x' => [
            'enabled' => false,
            'family' => 'private',
            'type' => 'email_alert',
            'mixed_tenure' => false,
            'params' => ['id_from' => 'contnet'],
            'map' => ['ref' => 'url', 'charges_included' => true],
        ]]]);
    }

    public function testAnOmittedBudgetDefaultsRatherThanRefusing(): void
    {
        $sources = ConfigLoader::sourcesFromArray(['sources' => ['x' => self::htmlSourceWithDetailMap()]]);

        self::assertSame(20, $sources['x']->detailBudgetPerPass);
    }

    public function testABudgetIsCarriedThrough(): void
    {
        $sources = ConfigLoader::sourcesFromArray(['sources' => ['x' => self::htmlSourceWithDetailMap(['detail_budget_per_pass' => 5])]]);

        self::assertSame(5, $sources['x']->detailBudgetPerPass);
    }

    /**
     * `prose:` is a RESERVED capture prefix, and an unknown reader after it refuses at load.
     *
     * The alternative was to let it fall through and be compiled as an ordinary regex, which is
     * what makes the prefix necessary in the first place: `prose:flor` is a perfectly valid
     * pattern that matches nothing, so the field would read `null` for ever while the config
     * looked deliberate. Same asymmetry as the zero-budget refusal — a directive that can never
     * do anything is a disabled feature wearing a configured one's clothes.
     */
    public function testAnUnknownProseReaderIsRefused(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('/prose:/');

        ConfigLoader::sourcesFromArray(['sources' => ['x' => self::htmlSourceWithDetailMap([
            'detail_map' => ['floor' => '.d => prose:flor'],
        ])]]);
    }

    public function testTheKnownProseReadersAreAccepted(): void
    {
        $sources = ConfigLoader::sourcesFromArray(['sources' => ['x' => self::htmlSourceWithDetailMap([
            'detail_map' => ['floor' => '.d => prose:floor', 'elevator' => '.d => prose:elevator'],
        ])]]);

        self::assertNotNull($sources['x']->detailMap);
    }

    /**
     * A FIELD-MAP CAPTURE REGEX THAT DOES NOT COMPILE IS REFUSED AT LOAD.
     *
     * `Selector::captureFrom()` applies it with `@preg_match`, which neither warns nor throws: a
     * broken pattern returns `false`, is read as *no match*, and the field resolves to `null` for
     * every item on every pass, for ever. The source keeps returning its usual count, no run fails,
     * and `SourceHealth` stays green — the In'li `cp` shape, where one dead selector meant the
     * source matched zero flats while reporting `ok`.
     *
     * A broken pattern is a STATE, not an event: every extraction fails for the same reason and no
     * retry can change it, so it belongs with the load-time refusals rather than with the
     * per-listing failures that are recorded and counted. `params` patterns have been compile-checked
     * since round 1; the field maps are the other half of the same surface, and were not.
     *
     * The `_comment` half of the config is untouched by this: only a capture is compiled, and only
     * one that is not a `prose:` reader — the reader check above already owns those.
     */
    public function testAFieldMapCaptureThatDoesNotCompileIsRefused(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('/ne compile pas/');

        ConfigLoader::sourcesFromArray(['sources' => ['x' => self::htmlSourceWithDetailMap([
            'map' => ['ref' => '@href', 'url' => '@href', 'cp' => 'a@href => -(\\d{5}/'],
        ])]]);
    }

    public function testABrokenCaptureInADetailMapIsRefusedToo(): void
    {
        // The detail map is the surface a session is most likely to widen by hand, and it feeds the
        // description the tenure classifier reads — so a silent null there is §1-adjacent.
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('/ne compile pas/');

        ConfigLoader::sourcesFromArray(['sources' => ['x' => self::htmlSourceWithDetailMap([
            'detail_map' => ['description' => '.d => (unclosed'],
        ])]]);
    }

    /**
     * THE COUNTERWEIGHT. Without it the refusal is satisfied by rejecting every capture, which
     * would break four shipped sources — so the shapes that are in `config/rent/sources.json` today
     * must still load, `~` included, since `captureFrom()` delimits with `~` and escapes it.
     */
    public function testOrdinaryCapturesStillLoad(): void
    {
        $sources = ConfigLoader::sourcesFromArray(['sources' => ['x' => self::htmlSourceWithDetailMap([
            'map' => [
                'ref' => 'a@href => /([^/]+)$',
                'url' => '@href',
                'cp' => 'a@href => -(\\d{5})/',
                'surface' => '.card => ([\\d.,]+)\\s*m',
                'rooms' => '.card => (\\d+)\\s*pi[eè]ce',
            ],
            'detail_map' => ['description' => '.d => ^(.*)$', 'floor' => '.d => prose:floor'],
        ])]]);

        self::assertSame(['a@href => -(\\d{5})/'], $sources['x']->map->postcode);
    }

    /** The SHIPPED config must load — the guard cannot be allowed to reject the real tree. */
    public function testEveryShippedFieldMapCaptureCompiles(): void
    {
        $sources = ConfigLoader::loadSources(self::ROOT . '/config/rent/sources.json');

        self::assertNotSame([], $sources, 'the shipped sources must still load with the guard in place');
    }

    /** @param array<string,mixed> $extra */
    private static function htmlSourceWithDetailMap(array $extra = []): array
    {
        return $extra + [
            '_verified_at' => '2026-08-23',
            '_source' => 'a test',
            'enabled' => true,
            'family' => 'institutional',
            'type' => 'html',
            'mixed_tenure' => true,
            'url' => 'https://example.test/search',
            'item_selector' => 'a.card',
            'map' => ['ref' => '@href', 'url' => '@href'],
            'detail_map' => ['description' => '.description'],
        ];
    }

    /** A detail map on a source whose adapter would never read it is refused, not ignored. */
    public function testADetailMapOnANonHtmlSourceIsRefused(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('/only an html source/');

        ConfigLoader::sourcesFromArray(['sources' => ['x' => [
            '_verified_at' => '2026-08-21',
            '_source' => 'a test',
            'enabled' => true,
            'family' => 'institutional',
            'type' => 'json',
            'mixed_tenure' => true,
            'url' => 'https://example.test/api',
            'items_path' => 'results',
            'map' => ['ref' => 'id', 'url' => 'url'],
            'detail_map' => ['description' => 'description'],
        ]]]);
    }

    /**
     * Q15, answered by measurement on 2026-08-20 — and this test is now the answer's guard.
     *
     * The question was whether CDC Habitat's listing endpoint sits inside the `Disallow` space. It
     * turned out to be larger than the question assumed: robots forbids `/Recherche/show/`,
     * `/Recherche/search` AND seven search QUERY PARAMETERS by name, so the parameterised search is
     * off limits entirely. What the site's own sitemap.xml advertises is the lowercase, query-free
     * `/recherche/location/<region>` tree.
     *
     * So the invariant worth pinning is no longer "cdc_habitat is disabled" — it is that the URL
     * this source polls, AND every page its walk visits, is allowed by CDC's REAL robots.txt. That
     * file is frozen beside the payload; if CDC tightens it, this test is what notices, rather than
     * a crawl that keeps returning 200 while breaking hard rule 5.
     */
    public function testEveryUrlTheCdcWalkVisitsIsAllowedByCdcsRealRobotsTxt(): void
    {
        $source = ConfigLoader::loadSources(self::ROOT . '/config/rent/sources.json')['cdc_habitat'];
        $robots = Robots::parse((string) file_get_contents(self::ROOT . '/tests/fixtures/rent/cdc_habitat/robots.txt'));

        $url = (string) $source->url;
        self::assertNull(parse_url($url, PHP_URL_QUERY), 'the query space is what robots disallows here');
        self::assertTrue($robots->allows(Robots::pathOf($url)), 'the index page itself must be allowed');

        // Not just page one. The path changes per page, so each is a separate robots decision.
        for ($page = 2; $page <= $source->maxPages; ++$page) {
            $paged = $url . str_replace('{page}', (string) $page, (string) $source->pagePath);

            self::assertNull(parse_url($paged, PHP_URL_QUERY), "page {$page} must not add a query string");
            self::assertTrue($robots->allows(Robots::pathOf($paged)), "page {$page} is disallowed by robots.txt");
        }
    }

    /**
     * The disallowed shapes are still disallowed — the frozen robots.txt is read, not assumed.
     *
     * Without this, the test above would pass just as happily against an empty or mis-parsed
     * robots file, which is the failure mode where a compliance check certifies nothing.
     */
    public function testCdcsRobotsTxtStillForbidsTheParameterisedSearch(): void
    {
        $robots = Robots::parse((string) file_get_contents(self::ROOT . '/tests/fixtures/rent/cdc_habitat/robots.txt'));

        self::assertFalse($robots->allows('/Recherche/show/12345'), 'the capitalised search path is disallowed');
        self::assertFalse($robots->allows('/Recherche/search'), 'the search endpoint is disallowed');
    }

    // ---------------------------------------------------------------- local override

    public function testALocalOverrideMergesFieldByFieldAndReplacesArrays(): void
    {
        $dir = sys_get_temp_dir() . '/rentwatch-config-' . bin2hex(random_bytes(6));
        mkdir($dir);

        try {
            file_put_contents($dir . '/criteria.json', json_encode([
                'communes' => ['Sartrouville', 'Houilles'],
                'postcode_prefixes' => ['78'],
                'max_rent_cc' => 1800,
                'notify' => ['high_priority_score' => 70, 'digest_hour' => 8],
            ], JSON_THROW_ON_ERROR));

            file_put_contents($dir . '/criteria.local.json', json_encode([
                'max_rent_cc' => 2100,
                'communes' => ['Chatou'],
                'notify' => ['digest_hour' => 6],
            ], JSON_THROW_ON_ERROR));

            $c = ConfigLoader::loadCriteria($dir . '/criteria.json', $dir . '/criteria.local.json');

            self::assertSame(2100, $c->maxRentCc, 'a scalar is replaced');
            self::assertSame(['chatou'], $c->communes, 'an array is replaced wholesale, not merged');
            self::assertSame(6, $c->notify->digestHour, 'an object merges key by key');
            self::assertSame(70, $c->notify->highPriorityScore, 'an unmentioned key survives the merge');
        } finally {
            @unlink($dir . '/criteria.json');
            @unlink($dir . '/criteria.local.json');
            @rmdir($dir);
        }
    }

    public function testAnAbsentLocalOverrideIsNotAnError(): void
    {
        // Pointed at a path that CANNOT exist, since 2026-08-22. It named
        // `config/rent/criteria.local.json`, which is gitignored and therefore absent in CI and on a
        // fresh clone — but present on any machine where somebody actually configured the tool, and
        // this one has had one since channels were wired. So on the developer's own machine the
        // test named "absent" was silently exercising the PRESENT path, and passed only because
        // that override happens not to touch `max_rent_cc`. A test whose premise depends on
        // untracked local state is a test that means something different for every reader.
        $c = ConfigLoader::loadCriteria(
            self::ROOT . '/config/rent/criteria.json',
            self::ROOT . '/config/criteria.local-' . bin2hex(random_bytes(8)) . '.json',
        );

        // Reads whatever the shipped file says rather than pinning a number here — that number is
        // pinned once, in testTheShippedCriteriaFileLoads, and a second copy of it would make one
        // criteria edit redden two tests for one reason.
        $shipped = $this->shipped();
        self::assertSame($shipped->maxRentCc, $c->maxRentCc);
    }

    public function testAMissingConfigFileIsALoudRefusal(): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~no such config file~');

        ConfigLoader::loadCriteria('/nonexistent/criteria.json');
    }

    public function testMalformedJsonIsALoudRefusal(): void
    {
        $path = sys_get_temp_dir() . '/rentwatch-bad-' . bin2hex(random_bytes(6)) . '.json';
        file_put_contents($path, '{"communes": [');

        try {
            $this->expectException(ConfigError::class);
            $this->expectExceptionMessageMatches('~not valid JSON~');
            ConfigLoader::loadCriteria($path);
        } finally {
            @unlink($path);
        }
    }

    private function shipped(): Criteria
    {
        return ConfigLoader::loadCriteria(self::ROOT . '/config/rent/criteria.json');
    }
}
