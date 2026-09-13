<?php

declare(strict_types=1);

namespace Scout\Tests\Job;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Scout\Job\JobClassifier;
use Scout\Job\JobFacts;
use Scout\Job\JobListing;
use Scout\Job\PayLine;

/**
 * The facts read out of an offer, before any criterion is applied. Each rejection the scorer forms
 * runs on one of these, so each is tested in BOTH directions: the stated form that reads, and the
 * ordinary copy that must read nothing — a negation, a homonym, a word on the wrong surface.
 */
#[CoversClass(JobClassifier::class)]
#[CoversClass(JobFacts::class)]
final class JobClassifierTest extends TestCase
{
    /** @return iterable<string, array{string, string, list<string>, list<string>}> */
    public static function contracts(): iterable
    {
        yield 'a contract in the title' => ['Lead Developer - CDI - F/H/NB', '', [], ['cdi']];
        yield 'a dual offer is a set' => ['Développeur PHP', 'Poste ouvert en CDI ou en freelance.', [], ['cdi', 'freelance']];
        yield 'portage' => ['Développeur Java', 'Mission en portage salarial possible.', [], ['portage']];
        yield 'a negated contract is not stated' => ['Développeur PHP', 'CDI uniquement, pas de freelance.', [], ['cdi']];
        yield 'a negated mention does not hide a stated one' => ['Développeur PHP', 'Pas de CDD. Poste en CDI, CDD possible pour démarrer.', [], ['cdd', 'cdi']];
        yield 'freelances s\'abstenir' =>['Développeur PHP', 'CDI. Freelances s\'abstenir.', [], ['cdi']];
        yield 'a structured contract field' => ['Développeur PHP', '', ['CDD'], ['cdd']];
        // N5: an unrecognised structured value is KEPT. Dropped, `CDD + contractuel` would read as
        // CDD alone and H3 would reject an offer whose other contract nobody has ruled out.
        yield 'an unrecognised structured contract is kept as stated' => ['Développeur PHP', '', ['CDD', 'Contractuel'], ['cdd', 'contractuel']];
        yield 'stage from the title' => ['Stage - Développeur PHP', '', [], ['stage']];
        yield 'alternance from the title' => ['Alternance Développeur Web', '', [], ['alternance']];
        yield 'mission alone is not freelance' => ['Développeur PHP', 'Vos missions : concevoir et maintenir la plateforme.', [], []];
        yield 'stage in the DESCRIPTION is never a contract' => ['Développeur PHP', 'Encadrement de stagiaires. Stage de fin d\'études possible.', [], []];
        yield 'étage folds to a word containing stage' => ['Développeur PHP', 'Bureaux au 3e étage, en CDI.', [], ['cdi']];
        yield 'alternance in the description is never a contract' => ['Développeur PHP', 'Nous accueillons aussi des alternants.', [], []];
        yield 'apprentissage automatique is machine learning' => ['Ingénieur apprentissage automatique', '', [], []];
        yield 'vie is a French word, not a VIE' => ['Développeur PHP', 'Une vraie qualité de vie au travail.', [], []];
        yield 'nothing stated is an empty set' => ['Senior Software Engineer', '', [], []];
    }

    /**
     * @param list<string> $field
     * @param list<string> $expected
     */
    #[DataProvider('contracts')]
    public function testContractsAreReadAsAStatedSet(string $title, string $description, array $field, array $expected): void
    {
        $facts = self::read(new JobListing(sourceName: 's', externalId: 'x', title: $title, description: $description, contracts: $field));

        self::assertSame($expected, $facts->contracts);
    }

    /** @return iterable<string, array{string, string, ?string, ?string, ?float}> */
    public static function workModes(): iterable
    {
        yield 'full remote' => ['Développeur PHP', 'Poste en full remote.', null, 'remote', 5.0];
        yield 'remote in the title' => ['Full-Stack Developer (Remote)', '', null, 'remote', 5.0];
        yield 'remote days per week' => ['Développeur PHP', '2 jours de télétravail par semaine.', null, 'hybrid', 2.0];
        yield 'on-site days per week' => ['Développeur PHP', '3 jours sur site par semaine.', null, 'hybrid', 2.0];
        yield 'days written as words' => ['Développeur PHP', 'Deux jours de télétravail.', null, 'hybrid', 2.0];
        yield 'hybrid with no days' => ['Développeur PHP', 'Organisation hybride.', null, 'hybrid', null];
        yield 'no remote at all' => ['Développeur PHP', 'Pas de télétravail.', null, 'onsite', 0.0];
        yield 'a negated full remote states nothing' => ['Développeur PHP', 'Pas de full remote.', null, null, null];
        yield 'remote on a description is not a mode' => ['Développeur PHP', 'You will work with remote teams.', null, null, null];
        yield 'unmentioned is not on site' => ['Développeur PHP', 'Stack Symfony.', null, null, null];
        yield 'the card field wins over the text' => ['Développeur PHP', 'Full remote.', 'hybrid', 'hybrid', null];
        yield 'the field is refined by stated days' => ['Développeur PHP', '2 jours de télétravail.', 'hybrid', 'hybrid', 2.0];
        yield 'contradicting modes state nothing' => ['Développeur PHP', 'Full remote. Pas de télétravail.', null, null, null];
    }

    #[DataProvider('workModes')]
    public function testTheWorkModeIsReadOnlyWhenStated(string $title, string $description, ?string $field, ?string $mode, ?float $days): void
    {
        $facts = self::read(new JobListing(sourceName: 's', externalId: 'x', title: $title, description: $description, workMode: $field));

        self::assertSame($mode, $facts->workMode, 'mode');
        self::assertSame($days, $facts->remoteDays, 'remote days');
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function levels(): iterable
    {
        yield 'lead' => ['Lead Developer - CDI - F/H/NB', JobFacts::LEAD];
        yield 'tech lead' => ['Software Lead / Tech Lead', JobFacts::LEAD];
        yield 'staff' => ['Staff Software Engineer', JobFacts::LEAD];
        yield 'principal' => ['Principal Engineer (H/F)', JobFacts::LEAD];
        yield 'architect beside senior takes the higher' => ['Développeur(se) Full Stack Senior & Architecte Système H/F', JobFacts::LEAD];
        yield 'senior' => ['Senior Fullstack Engineer', JobFacts::SENIOR];
        yield 'a numbered senior grade' => ['Senior Software Engineer I', JobFacts::SENIOR];
        yield 'confirmé' => ['Développeur Fullstack Confirmé Php/JavaScript/Typescript - Paris - H/F', JobFacts::CONFIRMED];
        yield 'junior' => ['Développeur Junior PHP', JobFacts::JUNIOR];
        yield 'unlabelled' => ['Software Engineer I', null];
        yield 'a lead word in the description is not a title level' => ['Full Stack Engineer', null];
    }

    #[DataProvider('levels')]
    public function testTheTitleLevelIsReadFromTheTitleOnly(string $title, ?string $level): void
    {
        $facts = self::read(new JobListing(sourceName: 's', externalId: 'x', title: $title, description: 'Vous serez lead sur le projet, en tant que senior.'));

        self::assertSame($level, $facts->level);
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function eligibility(): iterable
    {
        yield 'nationality required' => ['Nationalité française requise.', JobFacts::FRENCH_NATIONALITY];
        yield 'être de nationalité française' => ['Vous devez être de nationalité française.', JobFacts::FRENCH_NATIONALITY];
        yield 'nationality not required' => ['Nationalité française non requise.', null];
        yield 'no need to be French' => ['Pas besoin d\'être de nationalité française.', null];
        yield 'secret-défense clearance' => ['Habilitation Secret Défense requise.', JobFacts::CLEARANCE];
        yield 'confidentiel-défense clearance' => ['Habilitation Confidentiel-Défense exigée.', JobFacts::CLEARANCE];
        yield 'habilitable' => ['Profil habilitable.', JobFacts::CLEARANCE];
        yield 'access-rights management is not a clearance' => ['Gestion des habilitations et des droits utilisateurs.', null];
        yield 'no clearance needed' => ['Sans habilitation secret défense.', null];
        yield 'nothing said' => ['Stack Symfony et Vue.js.', null];
    }

    #[DataProvider('eligibility')]
    public function testAnEligibilityRequirementIsReadNegationFirst(string $description, ?string $expected): void
    {
        self::assertSame($expected, self::read(new JobListing(sourceName: 's', externalId: 'x', title: 'Développeur PHP', description: $description))->eligibility);
    }

    public function testPayIsReadFromTheTitleThePayLineAndTheDescription(): void
    {
        $facts = self::read(new JobListing(
            sourceName: 's', externalId: 'x', title: 'Développeur PHP - 60-70 k€ par an',
            description: 'TJM 550 €/jour en freelance.', payText: "Entre 60\u{00A0}k\u{00A0}€ et 70\u{00A0}k\u{00A0}€ par an",
        ));

        self::assertSame(
            [[PayLine::SALARY, 60000, 70000], [PayLine::TJM, 550, 550]],
            array_map(static fn (PayLine $l): array => [$l->kind, $l->minEur, $l->maxEur], $facts->pay),
            'the same line stated on two surfaces is read once',
        );
    }

    /** A structured salary field is gross annual by contract; a day-rate field is HT. */
    public function testStructuredPayFieldsBecomeLines(): void
    {
        $facts = self::read(new JobListing(sourceName: 's', externalId: 'x', title: 'Développeur PHP', salaryMinEur: 58000, salaryMaxEur: 62000, tjmMaxEur: 500));

        self::assertSame(
            [[PayLine::SALARY, PayLine::GROSS, PayLine::YEAR, 58000, 62000], [PayLine::TJM, PayLine::HT, PayLine::DAY, 500, 500]],
            array_map(static fn (PayLine $l): array => [$l->kind, $l->basis, $l->period, $l->minEur, $l->maxEur], $facts->pay),
        );
    }

    /** Unreadable text is not an offer that says nothing: it is named, and nothing is read from it. */
    public function testUnreadableTextIsNamedAndReadsNothing(): void
    {
        $facts = self::read(new JobListing(sourceName: 's', externalId: 'x', title: "D\xC3\x28veloppeur CDI", description: 'Full remote.'));

        self::assertNotNull($facts->unreadable);
        self::assertSame([], $facts->contracts);
        self::assertNull($facts->workMode);
    }

    private static function read(JobListing $listing): JobFacts
    {
        return (new JobClassifier())->read($listing);
    }
}
