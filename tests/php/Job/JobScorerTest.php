<?php

declare(strict_types=1);

namespace Scout\Tests\Job;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Scout\Job\JobClassifier;
use Scout\Job\JobCriteria;
use Scout\Job\JobCriteriaLoader;
use Scout\Job\JobListing;
use Scout\Job\JobOutcome;
use Scout\Job\JobScorer;
use Scout\Job\JobVerdict;

/**
 * The verdict: H1–H8 reject, the six components score, RED subtracts. Every disqualifier is attacked
 * from both sides — a reject that fires on the shape it must not is invisible, because nothing arrives.
 * Offers go through the real classifier and the shipped criteria, so a case tests what a pass does.
 *
 * Expected scores are chosen off the .5 boundary on purpose: a total of 13.5 would pin float rounding,
 * not the rule.
 */
#[CoversClass(JobScorer::class)]
#[CoversClass(JobVerdict::class)]
final class JobScorerTest extends TestCase
{
    private const string NOW = '2026-09-13T12:00:00Z';
    private const string TEN_DAYS_AGO = '2026-09-03T12:00:00Z';
    private const string TWO_DAYS_AGO = '2026-09-11T12:00:00Z';

    /** A full offer: every component at its full share. */
    private const string FULL_TITLE = 'Lead Développeur PHP / React';
    private const string FULL_PAY = 'Entre 60 k€ et 70 k€ par an';
    private const string FULL_DESCRIPTION = 'TDD, Claude Code, éditeur SaaS. RTT.';

    /** @return iterable<string, array{string, array<string, mixed>, string}> */
    public static function rejects(): iterable
    {
        yield 'H1 a gross salary whose upper bound is under the floor' => ['Développeur PHP', ['payText' => 'Entre 45 k€ et 55 k€ par an'], 'salaire'];
        yield 'H1 a salary under the floor with portage beside a CDI' => ['Développeur PHP CDI ou portage', ['payText' => '45 k€ brut par an'], 'salaire'];
        yield 'H2 a day rate under the floor' => ['Développeur PHP', ['payText' => '400 €/jour'], 'TJM'];
        yield 'H3 every stated contract is out of scope' => ['Développeur PHP - CDD', [], 'contrat'];
        yield 'H5 the title names no developer role' => ['Chef de projet marketing', [], 'métier'];
        yield 'H6 a title reject' => ['Développeur Junior PHP', [], 'junior'];
        yield 'H7 French nationality stated as required' => ['Développeur PHP', ['description' => 'Nationalité française requise.'], 'nationalité'];
        yield 'H7 a defence clearance stated as required' => ['Développeur PHP', ['description' => 'Habilitation secret défense requise.'], 'habilitation'];
        yield 'H8 outside Île-de-France and hybrid' => ['Développeur PHP', ['location' => 'Lyon, Auvergne-Rhône-Alpes, France', 'workMode' => 'hybrid'], 'hors Île-de-France'];
        yield 'H8 outside Île-de-France and on site' => ['Développeur PHP', ['location' => 'Lyon', 'workMode' => 'onsite'], 'hors Île-de-France'];
        yield 'an unreadable text is rejected by name, never judged as empty' => ["D\xE9veloppeur PHP", [], 'illisible'];
    }

    /** @param array<string, mixed> $named */
    #[DataProvider('rejects')]
    public function testAHardDisqualifierRejectsAndNamesItself(string $title, array $named, string $mentions): void
    {
        $verdict = self::judge(self::offer($title, $named));

        self::assertSame(JobOutcome::REJECT, $verdict->outcome, implode(' | ', $verdict->reasons));
        self::assertNull($verdict->score);
        self::assertStringContainsString($mentions, implode(' | ', $verdict->reasons));
    }

    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function mustNotReject(): iterable
    {
        yield 'no pay stated' => ['Développeur PHP', []];
        yield 'a salary exactly at the floor' => ['Développeur PHP', ['payText' => 'Salaire 59 k€ brut par an']];
        yield 'a monthly figure over the floor on 13 months, under it on 12' => ['Développeur PHP', ['payText' => '4 600 € brut par mois']];
        yield 'a NET figure is never compared' => ['Développeur PHP', ['payText' => '2 800 € net par mois']];
        yield 'a PACKAGE never rejects (N1)' => ['Développeur PHP', ['payText' => 'Package de 50 k€ par an']];
        yield 'a portage salary has no floor (N6)' => ['Développeur PHP en portage salarial', ['payText' => '45 k€ brut par an']];
        yield 'a dual offer rejects only when EVERY line is under (N2)' => ['Développeur PHP', ['payText' => 'CDI 50-55 k€ par an ou freelance 550 €/jour']];
        yield 'a day rate exactly at the floor' => ['Développeur PHP', ['payText' => '450 €/jour']];
        yield 'one ruled contract keeps a dual offer' => ['Développeur PHP CDD ou CDI', []];
        yield 'an unrecognised contract is shown, not rejected (N5)' => ['Développeur PHP', ['contracts' => ['Contractuel']]];
        yield 'DevOps is not a reject' => ['Développeur DevOps', []];
        yield 'French nationality stated as NOT required' => ['Développeur PHP', ['description' => 'Nationalité française non requise.']];
        yield 'outside Île-de-France, fully remote' => ['Développeur PHP', ['location' => 'Lyon', 'workMode' => 'remote']];
        yield 'outside Île-de-France, mode unstated' => ['Développeur PHP', ['location' => 'Lyon']];
        yield 'an unrecognised place, hybrid (H8 fails open)' => ['Développeur PHP', ['location' => 'Quelque Part', 'workMode' => 'hybrid']];
        yield 'a bare France, on site' => ['Développeur PHP', ['location' => 'France', 'workMode' => 'onsite']];
        yield 'RED signals lower the score and never hide an offer' => ['Développeur PHP', ['description' => 'TMA. Astreintes. Support N2. WordPress.']];
    }

    /** @param array<string, mixed> $named */
    #[DataProvider('mustNotReject')]
    public function testWhatTheRulingsKeepIsNeverRejected(string $title, array $named): void
    {
        $verdict = self::judge(self::offer($title, $named));

        self::assertSame(JobOutcome::MATCH, $verdict->outcome, implode(' | ', $verdict->reasons));
    }

    public function testTheNationalitySwitchDisarmsH7(): void
    {
        $data = JobCriteriaTest::shippedArray();
        $data['eligibility']['french_nationality'] = true;
        $offer = self::offer('Développeur PHP', ['description' => 'Nationalité française requise.']);

        self::assertSame(JobOutcome::MATCH, self::judge($offer, JobCriteriaLoader::fromArray($data))->outcome);
    }

    public function testTheUserExclusionListRejects(): void
    {
        $data = JobCriteriaTest::shippedArray();
        $data['exclude_patterns'] = ['\\bshopify\\b'];
        $offer = self::offer('Développeur PHP', ['description' => 'Thèmes Shopify.']);

        $verdict = self::judge($offer, JobCriteriaLoader::fromArray($data));

        self::assertSame(JobOutcome::REJECT, $verdict->outcome);
        self::assertStringContainsString('motif exclu', implode(' | ', $verdict->reasons));
    }

    public function testAFullOfferEarnsEveryShare(): void
    {
        $verdict = self::judge(self::full());

        self::assertSame(JobOutcome::MATCH, $verdict->outcome);
        self::assertSame(100, $verdict->score, implode(' | ', $verdict->reasons));
    }

    public function testAnUnknownComponentSaysSoAndEarnsNothing(): void
    {
        $verdict = self::judge(self::offer('Développeur'));
        $reasons = implode(' | ', $verdict->reasons);

        self::assertSame(6, $verdict->score, $reasons); // an unlabelled title is a reading: 0.4 × 15
        foreach (['stack inconnue — hors score', 'rémunération inconnue — hors score', 'mode de travail inconnu — hors score', 'date de publication inconnue — hors score'] as $line) {
            self::assertStringContainsString($line, $reasons);
        }
    }

    /**
     * Base: a lead title (15), fully remote (15), published ten days ago (10 × 4/7 ≈ 5.71) → 35.71.
     *
     * @return iterable<string, array{string, array<string, mixed>, int, ?string}>
     */
    public static function components(): iterable
    {
        yield 'the base' => ['Lead Développeur', [], 36, null];
        yield 'stack: BACK alone' => ['Lead Développeur PHP', [], 51, 'PHP'];
        yield 'stack: ADJACENT only when no back term' => ['Lead Développeur Python', [], 43, 'Python'];
        yield 'stack: back outranks adjacent' => ['Lead Développeur PHP et Python', [], 51, null];
        yield 'stack: adjacent plus front' => ['Lead Développeur Python / Vue.js', [], 53, 'Vue.js'];
        yield 'stack: an OTHER stack is named and earns nothing' => ['Lead Développeur Ruby', [], 36, 'Ruby — stack hors préférences'];
        yield 'pay: midway between floor and target' => ['Lead Développeur', ['payText' => 'Salaire 62 k€ brut par an'], 46, null];
        yield 'pay: above the target is clamped at the full share' => ['Lead Développeur', ['payText' => 'Salaire 70 k€ brut par an'], 56, null];
        yield 'pay: at the floor earns nothing' => ['Lead Développeur', ['payText' => 'Salaire 59 k€ brut par an'], 36, null];
        yield 'pay: a monthly figure scores on 12 months' => ['Lead Développeur', ['payText' => '4 600 € brut par mois'], 36, null];
        yield 'pay: a day rate midway' => ['Lead Développeur', ['payText' => '500 €/jour'], 46, null];
        yield 'pay: the best line of a dual offer' => ['Lead Développeur', ['payText' => 'CDI 50-55 k€ par an ou freelance 550 €/jour'], 56, null];
        yield 'pay: a net figure is not compared' => ['Lead Développeur', ['payText' => '3 000 € net par mois'], 36, 'non comparable — hors score'];
        yield 'level: senior' => ['Développeur Senior', [], 31, null];
        yield 'green: one group of three' => ['Lead Développeur', ['description' => 'TDD et DDD.'], 41, 'TDD'];
        yield 'green: a negated term does not count' => ['Lead Développeur', ['description' => 'Pas de TDD.'], 36, null];
    }

    /** @param array<string, mixed> $named */
    #[DataProvider('components')]
    public function testEachComponentMovesTheScoreByItsShare(string $title, array $named, int $score, ?string $mentions): void
    {
        $verdict = self::judge(self::offer($title, ['workMode' => 'remote', 'publishedAt' => self::TEN_DAYS_AGO] + $named));
        $reasons = implode(' | ', $verdict->reasons);

        self::assertSame($score, $verdict->score, $reasons);
        if ($mentions !== null) {
            self::assertStringContainsString($mentions, $reasons);
        }
    }

    /**
     * Base: a lead title (15), published ten days ago (≈ 5.71), mode varied → 20.71 before remote.
     *
     * @return iterable<string, array{array<string, mixed>, int, ?string}>
     */
    public static function remote(): iterable
    {
        yield 'unstated mode is unknown, never on site' => [[], 21, 'mode de travail inconnu — hors score'];
        yield 'on site earns nothing' => [['workMode' => 'onsite'], 21, null];
        yield 'hybrid with stated days follows the days' => [['workMode' => 'hybrid', 'description' => '3 jours de télétravail par semaine.'], 32, null];
        yield 'hybrid with no days takes the unstated share' => [['workMode' => 'hybrid'], 28, null];
        yield 'a work condition adds its bonus' => [['workMode' => 'onsite', 'description' => 'RTT.'], 24, 'RTT'];
        yield 'the bonus is clamped at the full share' => [['workMode' => 'remote', 'description' => 'RTT.'], 36, null];
        yield 'a negated condition adds nothing' => [['workMode' => 'onsite', 'description' => 'Pas de RTT.'], 21, null];
    }

    /** @param array<string, mixed> $named */
    #[DataProvider('remote')]
    public function testRemoteAndConditionsScoreTogether(array $named, int $score, ?string $mentions): void
    {
        $verdict = self::judge(self::offer('Lead Développeur', ['publishedAt' => self::TEN_DAYS_AGO] + $named));
        $reasons = implode(' | ', $verdict->reasons);

        self::assertSame($score, $verdict->score, $reasons);
        if ($mentions !== null) {
            self::assertStringContainsString($mentions, $reasons);
        }
    }

    /** @return iterable<string, array{string, ?string, int, ?string}> */
    public static function red(): iterable
    {
        yield 'one RED signal' => [self::FULL_DESCRIPTION . ' Astreintes le week-end.', self::TWO_DAYS_AGO, 95, 'astreintes'];
        yield 'RED is capped' => [self::FULL_DESCRIPTION . ' TMA. Astreintes. Support N2. WordPress.', self::TWO_DAYS_AGO, 85, '−15'];
        yield 'a negated RED signal subtracts nothing' => [self::FULL_DESCRIPTION . " Pas d'astreinte.", self::TWO_DAYS_AGO, 100, null];
        yield 'freshness decays past the peak' => [self::FULL_DESCRIPTION, self::TEN_DAYS_AGO, 96, null];
        yield 'freshness is zero at twice the peak' => [self::FULL_DESCRIPTION, '2026-08-24T12:00:00Z', 90, null];
        yield 'a future date is fresh, not negative' => [self::FULL_DESCRIPTION, '2026-09-14T12:00:00Z', 100, null];
        // `yesterday` is what a lenient parser reads: `new \DateTimeImmutable('yesterday')` is a date, and full marks.
        yield 'a relative expression is not a date' => [self::FULL_DESCRIPTION, 'yesterday', 90, 'date de publication inconnue — hors score'];
    }

    #[DataProvider('red')]
    public function testRedAndFreshnessMoveAFullOffer(string $description, ?string $publishedAt, int $score, ?string $mentions): void
    {
        $verdict = self::judge(self::offer(self::FULL_TITLE, ['payText' => self::FULL_PAY, 'workMode' => 'remote', 'description' => $description, 'publishedAt' => $publishedAt]));
        $reasons = implode(' | ', $verdict->reasons);

        self::assertSame(JobOutcome::MATCH, $verdict->outcome);
        self::assertSame($score, $verdict->score, $reasons);
        if ($mentions !== null) {
            self::assertStringContainsString($mentions, $reasons);
        }
    }

    public function testTheScoreIsClampedAtZero(): void
    {
        $verdict = self::judge(self::offer('Développeur', ['description' => 'TMA. Astreintes. Support N2. WordPress.']));

        self::assertSame(JobOutcome::MATCH, $verdict->outcome);
        self::assertSame(0, $verdict->score, implode(' | ', $verdict->reasons));
    }

    private static function full(): JobListing
    {
        return self::offer(self::FULL_TITLE, ['payText' => self::FULL_PAY, 'workMode' => 'remote', 'description' => self::FULL_DESCRIPTION, 'publishedAt' => self::TWO_DAYS_AGO]);
    }

    /** @param array<string, mixed> $named */
    private static function offer(string $title, array $named = []): JobListing
    {
        return new JobListing(...['sourceName' => 'linkedin', 'externalId' => '1', 'title' => $title] + $named);
    }

    private static function judge(JobListing $offer, ?JobCriteria $criteria = null): JobVerdict
    {
        return (new JobScorer())->judge($offer, (new JobClassifier())->read($offer), $criteria ?? JobCriteriaTest::shipped(), new \DateTimeImmutable(self::NOW));
    }
}
