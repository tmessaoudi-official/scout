<?php

declare(strict_types=1);

namespace Scout\Tests\Job;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Scout\Config\ConfigError;
use Scout\Job\JobCriteria;
use Scout\Job\JobCriteriaLoader;
use Scout\Job\JobTerms;
use Scout\Job\JobText;

/**
 * The job criteria as shipped, and the loader's refusals. The vocabularies are tested on their
 * false positives as much as their hits: a role gate that misses a real title, a title reject that
 * fires on `e-commerce`, a stack word read inside `JavaScript` — each one is silent in production.
 */
#[CoversClass(JobCriteriaLoader::class)]
#[CoversClass(JobCriteria::class)]
#[CoversClass(JobTerms::class)]
final class JobCriteriaTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../..';

    /** Every distinct card title in the 20 LinkedIn captures of 2026-09-13 — all of them developer roles. */
    private const array CAPTURED_TITLES = [
        'AI Engineer - Generative AI (Remote)', 'Agile IT Developer',
        'Développeur Confirmé Fullstack PHP/JS/TS (Avec connaissances Python) - Paris - H/F',
        'Développeur Full Stack/Développeur Full Stack', 'Développeur Fullstack Confirmé Php/JavaScript/Typescript - Paris - H/F',
        'Développeur Fullstack PHP', 'Développeur Fullstack PHP / JavaScript (H/F)', 'Développeur Fullstack Php/JS/TS - Paris - H/F',
        'Développeur full-stack H/F', 'Développeur(se) Full Stack Senior & Architecte Système H/F',
        'Développeur.euse Fullstack Senior Laravel / React', 'Forward Deployed Engineer', 'Forward Deployed Engineer_3202',
        'Full Stack AI Engineer', 'Full Stack Engineer', 'Full-Stack Developer (Remote)', 'Full-Stack Engineer - Creative Studio',
        'Full-Stack Engineer AI-native H/F', 'Fullstack Developer', 'Fullstack Developer (React/Node.js) (Remote)',
        'Fullstack Software Engineer (H/F/X)', 'Ingénieur Développeur Fullstack H/F', 'Ingénieur Full Stack', 'Ingénieur Full Stack Senior',
        'Ingénieur Full Stack/Ingénieure Full Stack', 'Ingénieur Fullstack TypeScript - Senior Full remote possible',
        'Ingénieur senior full-stack .NET Core/Angular/Azure (F/H) Full remote', 'Lead Dev / Tech Lead - Paris, France - Paris, France (H/F)',
        'Lead Developer - CDI - F/H/NB', 'Lead Développeur Fullstack - Java / PostgreSQL', 'Lead Développeur Fullstack PHP',
        'Lead Software Developer F/H', 'Lead Technique Fullstack', 'PHP Developer/ Web Designer', 'Principal Engineer (H/F)',
        'Product Engineer (Full Stack)', 'SOFTWARE ENGINEER FULL STACK - TOTALENERGIES DIGITAL FACTORY', 'Senior AI Engineer',
        'Senior Back-End Software Engineer (H/F/X)', 'Senior Backend Engineer', 'Senior Full Stack Software Engineer', 'Senior Fullstack Engineer',
        'Senior Software Engineer', 'Senior Software Engineer (Agentic Search) – Web Rendering Engineer',
        'Senior Software Engineer (Laravel | Next.js | PostgreSQL)', 'Senior Software Engineer - Distributed Systems (Remote)',
        'Senior Software Engineer - OpenCRQ', 'Senior Software Engineer I', 'Software AI Engineer', 'Software Developer',
        'Software Engineer Fullstack - CDI Paris - Theodo FinTech', 'Software Engineer Fullstack AI-Modernisation - CDI Paris - Theodo',
        'Software Engineer I', 'Software Lead / Tech Lead', 'Staff Software Engineer', 'Tech Lead Dev IA (H/F)', '📱 Fullstack Developer',
    ];

    public function testTheShippedFileLoadsAndIsTheRuledShape(): void
    {
        $c = JobCriteriaLoader::load(self::ROOT . '/config/job/criteria.json');

        self::assertSame(['stack' => 25, 'pay' => 20, 'level' => 15, 'green' => 15, 'remote' => 15, 'freshness' => 10], $c->weights, 'ruled 2026-09-13 15:37');
        self::assertSame([59000, 65000, 450, 550], [$c->salaryFloorEur, $c->salaryTargetEur, $c->tjmFloorEur, $c->tjmTargetEur]);
        self::assertFalse($c->frenchNationality, 'H7 armed until naturalisation');
        self::assertSame(['cdd', 'stage', 'alternance'], $c->rejectedContracts, 'CDI, freelance and portage are the scope; CDD is not');
        self::assertSame([5, 15], [$c->redPenalty, $c->redCap], 'each RED −5, capped at −15');
        self::assertSame(['craft', 'ai', 'product'], array_keys($c->green));
        self::assertSame(1.0, $c->backShare + $c->frontShare);
    }

    public function testEveryCapturedTitlePassesTheRoleGateAndNoTitleReject(): void
    {
        $c = self::shipped();
        self::assertCount(57, self::CAPTURED_TITLES);
        foreach (self::CAPTURED_TITLES as $title) {
            self::assertTrue($c->passesRoleGate($title), 'the role gate rejects a real captured title: ' . $title);
            self::assertNull($c->titleRejectedBy($title), 'a title reject fires on a real captured title: ' . $title);
        }
    }

    public function testTheRoleGateAcceptsFeminineInclusiveAndPluralForms(): void
    {
        $c = self::shipped();
        foreach (['Développeuse PHP', 'Développeur.euse Symfony', 'Développeur·euse Symfony', 'Ingénieure logiciel', 'Technical Lead', 'Lead Technique', 'Développeurs PHP (x3)', 'Architecte logiciel Java'] as $title) {
            self::assertTrue($c->passesRoleGate($title), $title);
        }
    }

    public function testTheRoleGateRejectsATitleThatNamesNoDeveloperRoleAndNeverAnUnreadOne(): void
    {
        $c = self::shipped();
        foreach (['Comptable', 'Chef de projet marketing', 'Assistant RH', 'Product Owner'] as $title) {
            self::assertFalse($c->passesRoleGate($title), $title);
        }
        self::assertTrue($c->passesRoleGate(''), 'an empty title was not read: the gate cannot reject on it');
    }

    /**
     * Two live LinkedIn titles the gate rejected as `intitulé hors métier` on the first deployed pass
     * (job store, 2026-09-14), ruled developer roles the same day: an architect NAMING a scored stack
     * word, in either word order, and the AI labs' engineer title. The counterweight is the ruled
     * boundary — an architect naming no stack is a building, network or cloud role, and the low-code
     * builder from that same pass is not a developer role.
     */
    public function testTheRoleGateAcceptsAStackArchitectAndAMemberOfTechnicalStaffOnly(): void
    {
        $c = self::shipped();
        foreach (['Architecte Php', 'Architecte Symfony', 'Architect Java', 'Architecte .NET', 'PHP Architect', 'Senior Member of Technical Staff, Multimodal AI'] as $title) {
            self::assertTrue($c->passesRoleGate($title), 'the role gate rejects a ruled developer title: ' . $title);
        }
        foreach (['Architecte d\'intérieur', 'Architecte réseau', 'Architecte cloud', 'Architecte DPLG', 'Low-Code Product Builder H/F'] as $title) {
            self::assertFalse($c->passesRoleGate($title), 'the role gate admits a title outside the ruled boundary: ' . $title);
        }
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function titleRejects(): iterable
    {
        yield 'junior' => ['Développeur Junior PHP', 'junior'];
        yield 'stage' => ['Stage - Développeur Web', 'stage'];
        yield 'alternance' => ['Alternance Développeur', 'alternance'];
        yield 'business developer' => ['Business Developer IT', 'commercial'];
        yield 'développeur commercial' => ['Développeur commercial', 'commercial'];
        yield 'ingénieur d\'affaires' => ['Ingénieur d\'affaires IT', 'commercial'];
        yield 'recruiter' => ['Talent Acquisition / Recruteur IT', 'recrutement'];
        yield 'data engineer' => ['Data Engineer', 'data'];
        yield 'data scientist' => ['Data Scientist Python', 'data'];
        yield 'ml engineer' => ['ML Engineer', 'data'];
        yield 'machine learning in French is data, not alternance' => ['Ingénieur apprentissage automatique', 'data'];
        yield 'DevOps is NOT a reject (ruled)' => ['Développeur DevOps', null];
        yield 'a DevOps-flavoured dev role' => ['Ingénieur DevOps Symfony', null];
        yield 'e-commerce is not commercial' => ['Développeur plateforme e-commerce', null];
        yield 'Salesforce is not sales' => ['Salesforce Developer', null];
        yield 'backstage is not a stage' => ['Backstage Developer', null];
        yield 'full stack is not a stage' => ['Full Stack Developer', null];
        yield 'a data platform role is not a data role' => ['Ingénieur Data Platform PHP', null];
        yield 'international is not an intern' => ['International Software Engineer', null];
    }

    #[DataProvider('titleRejects')]
    public function testTitleRejectsFireOnTheRuledFamiliesOnly(string $title, ?string $label): void
    {
        self::assertSame($label, self::shipped()->titleRejectedBy($title));
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function places(): iterable
    {
        yield 'Paris' => ['Paris', JobCriteria::IDF];
        yield 'Ville de Paris' => ['Ville de Paris', JobCriteria::IDF];
        yield 'the region' => ['Île-de-France, France', JobCriteria::IDF];
        yield 'Paris et périphérie' => ['Paris et périphérie', JobCriteria::IDF];
        yield 'a captured commune' => ['Montévrain', JobCriteria::IDF];
        yield 'another captured commune' => ['Boulogne-Billancourt', JobCriteria::IDF];
        yield 'a bare France is unknown' => ['France', null];
        yield 'nothing stated' => ['', null];
        yield 'an unrecognised place is unknown, never outside' => ['Quelque Part', null];
        yield 'a region outside' => ['Lyon, Auvergne-Rhône-Alpes, France', JobCriteria::OUTSIDE];
        yield 'outside wins a collision' => ['Saint-Denis, La Réunion', JobCriteria::OUTSIDE];
        yield 'abroad' => ['Londres, Royaume-Uni', JobCriteria::OUTSIDE];
    }

    #[DataProvider('places')]
    public function testAPlaceIsRecognisedByNameAndFailsOpen(string $location, ?string $class): void
    {
        self::assertSame($class, self::shipped()->locationClass($location));
    }

    /** @return iterable<string, array{string, string, list<string>}> */
    public static function stackTraps(): iterable
    {
        yield 'PHP and TS in a slash list' => ['back', 'Développeur PHP/JS/TS', ['PHP', 'TypeScript']];
        yield 'JavaScript is not Java' => ['back', 'Développeur JavaScript', []];
        yield 'Java and Spring' => ['back', 'Java / Spring Boot', ['Java', 'Spring']];
        yield 'PHPUnit is PHP' => ['back', 'tests PHPUnit', ['PHP']];
        yield 'Node and NestJS' => ['back', 'Node.js et NestJS', ['Node.js', 'NestJS']];
        yield 'nodes is not Node' => ['back', 'un graphe de nodes', []];
        yield 'a bare TS is not TypeScript' => ['back', 'normes ETS et TS', []];
        yield 'Vue.js' => ['front', 'Vue.js 3 et Nuxt', ['Vue.js']];
        yield 'vue is a French word' => ['front', 'une vue d\'ensemble', []];
        yield 'réactivité is not React' => ['front', 'grande réactivité', []];
        yield 'React' => ['front', 'réactivité et React', ['React']];
        yield 'Go as gigaoctet' => ['adjacent', '16 Go de RAM', []];
        yield 'Golang' => ['adjacent', 'Golang', ['Go']];
        yield 'Go in a slash list' => ['adjacent', 'Python/Go', ['Python', 'Go']];
        yield '.NET and C#' => ['adjacent', '.NET Core et C#', ['.NET']];
        yield 'internet is not .NET' => ['adjacent', 'accès internet', []];
        yield 'ASP.NET' => ['adjacent', 'ASP.NET MVC', ['.NET']];
        yield 'a tracking token is not a stack word, whatever its underscores' => ['back', 'Postuler : https://ex.com/job/42?trk=php_symfony', []];
        yield 'scalable is not Scala' => ['other', 'architecture scalable', []];
        yield 'Scala' => ['other', 'Scala et Akka', ['Scala']];
    }

    /** @param list<string> $expected */
    #[DataProvider('stackTraps')]
    public function testStackTermsRespectTheirBoundaryTraps(string $group, string $text, array $expected): void
    {
        $c = self::shipped();
        $terms = match ($group) {
            'back' => $c->backStack,
            'front' => $c->frontStack,
            'adjacent' => $c->adjacentStack,
            default => $c->otherStack,
        };

        self::assertSame($expected, $terms->hits(JobText::surface($text)));
    }

    public function testGreenRedAndConditionsAreReadNegationFirst(): void
    {
        $c = self::shipped();
        $hits = static fn (JobTerms $t, string $text): array => $t->hits(JobText::surface($text), true);

        self::assertSame([], $hits($c->red, 'Pas d\'astreinte.'));
        self::assertSame(['astreintes'], $hits($c->red, 'Astreintes le week-end.'));
        self::assertSame([], $hits($c->red, 'Sans TMA, que du build.'));
        self::assertSame(['support N1-N3'], $hits($c->red, 'Support N2/N3.'));
        self::assertSame(['DDD', 'TDD'], $hits($c->green['craft'], 'TDD et DDD.'));
        self::assertSame([], $hits($c->green['craft'], 'Pas de TDD.'));
        self::assertSame(['Cursor', 'Claude Code'], $hits($c->green['ai'], 'Cursor et Claude Code au quotidien.'));
        self::assertSame([], $hits($c->green['ai'], 'Déplacer le curseur.'));
        self::assertSame(['éditeur', 'SaaS'], $hits($c->green['product'], 'Éditeur de logiciels SaaS.'));
        self::assertSame(['RTT', 'horaires flexibles'], $hits($c->conditions, 'RTT et horaires flexibles.'));
        self::assertSame(['TDD'], $c->green['craft']->hits(JobText::surface('Pas de TDD.')), 'without negation-first the bare term reads — the flag is what reads the negation');
    }

    public function testTheUserExclusionListMatchesTitleAndDescription(): void
    {
        $c = self::with(static function (array &$d): void {
            $d['exclude_patterns'] = ['\\bshopify\\b'];
        });

        self::assertSame('\\bshopify\\b', $c->excludedBy("Développeur PHP\nThèmes Shopify"));
        self::assertNull($c->excludedBy('Développeur PHP'));
        self::assertNull(self::shipped()->excludedBy('Développeur Shopify'), 'the shipped list is empty on purpose');
    }

    /** @return iterable<string, array{callable(array<string, mixed>&): void, string}> */
    public static function refusals(): iterable
    {
        yield 'an unknown key' => [static function (array &$d): void { $d['max_floor'] = 3; }, 'max_floor'];
        yield 'weights not summing to 100' => [static function (array &$d): void { $d['weights']['stack'] = 30; }, '100'];
        yield 'an empty role gate' => [static function (array &$d): void { $d['role_words'] = []; }, 'role_words'];
        yield 'an invalid role word' => [static function (array &$d): void { $d['role_words'][] = '(unclosed'; }, 'role_words'];
        yield 'an invalid title reject' => [static function (array &$d): void { $d['title_rejects']['data'][] = '[x'; }, 'title_rejects'];
        yield 'an invalid stack term' => [static function (array &$d): void { $d['stack']['back']['terms']['PHP'] = '(?<'; }, 'stack'];
        yield 'an invalid red term' => [static function (array &$d): void { $d['red']['terms']['TMA'] = '+'; }, 'red'];
        yield 'an invalid place' => [static function (array &$d): void { $d['location']['outside_patterns'][] = '('; }, 'location'];
        yield 'a salary target not above its floor' => [static function (array &$d): void { $d['pay']['salary_target_eur'] = 59000; }, 'salary_target_eur'];
        yield 'a TJM target not above its floor' => [static function (array &$d): void { $d['pay']['tjm_target_eur'] = 400; }, 'tjm_target_eur'];
        yield 'a rejected contract the classifier never emits' => [static function (array &$d): void { $d['contracts']['rejected'][] = 'CDD'; }, 'rejected'];
        yield 'back and front shares not summing to 1' => [static function (array &$d): void { $d['stack']['front']['share'] = 0.5; }, 'share'];
        yield 'an adjacent share above the back share' => [static function (array &$d): void { $d['stack']['adjacent']['share'] = 0.7; }, 'adjacent'];
        yield 'a RED cap below one penalty' => [static function (array &$d): void { $d['red']['cap'] = 4; }, 'cap'];
        yield 'a missing remote day' => [static function (array &$d): void { unset($d['remote']['days_share']['3']); }, 'days_share'];
        yield 'a missing notify block' => [static function (array &$d): void { unset($d['notify']); }, 'notify'];
        yield 'no notification channel' => [static function (array &$d): void { $d['notify']['channels'] = []; }, 'channels'];
        yield 'a push gate above 100' => [static function (array &$d): void { $d['notify']['push_min_score'] = 101; }, 'push_min_score'];
        yield 'a rollup hour past 23' => [static function (array &$d): void { $d['notify']['rollup_hour'] = 24; }, 'rollup_hour'];
        yield 'a zero alert cooldown' => [static function (array &$d): void { $d['notify']['source_alert_cooldown_hours'] = 0; }, 'source_alert_cooldown_hours'];
        yield 'a car-only notify key' => [static function (array &$d): void { $d['notify']['high_priority_score'] = 50; }, 'high_priority_score'];
    }

    public function testTheNotifyBlockShipsTheConsoleAloneNoPushGateAndAMorningFloor(): void
    {
        $notify = self::shipped()->notify;

        self::assertSame(['console'], $notify->channels, 'the phone channels come from a gitignored local override at deploy');
        self::assertNull($notify->pushMinScore, 'no gate until real rows calibrate one — every match is pushed');
        self::assertSame(8, $notify->rollupHour);
        self::assertSame(12, $notify->sourceAlertCooldownHours);
    }

    /** @param callable(array<string, mixed>&): void $mutate */
    #[DataProvider('refusals')]
    public function testTheLoaderRefusesAShapeThatWouldMisbehaveSilently(callable $mutate, string $mentions): void
    {
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('~' . preg_quote($mentions, '~') . '~');
        self::with($mutate);
    }

    public function testALocalOverrideMergesFieldByField(): void
    {
        $dir = sys_get_temp_dir() . '/scout-job-' . bin2hex(random_bytes(4));
        mkdir($dir);
        copy(self::ROOT . '/config/job/criteria.json', $dir . '/criteria.json');
        file_put_contents($dir . '/criteria.local.json', json_encode(['eligibility' => ['french_nationality' => true], 'pay' => ['salary_floor_eur' => 60000]]));

        try {
            $c = JobCriteriaLoader::load($dir . '/criteria.json', $dir . '/criteria.local.json');
        } finally {
            array_map('unlink', glob($dir . '/*') ?: []);
            rmdir($dir);
        }

        self::assertTrue($c->frenchNationality);
        self::assertSame(60000, $c->salaryFloorEur);
        self::assertSame(65000, $c->salaryTargetEur, 'an untouched key keeps the base value');
    }

    public static function shipped(): JobCriteria
    {
        return JobCriteriaLoader::load(self::ROOT . '/config/job/criteria.json');
    }

    /** @return array<string, mixed> */
    public static function shippedArray(): array
    {
        return json_decode((string) file_get_contents(self::ROOT . '/config/job/criteria.json'), true, 64, JSON_THROW_ON_ERROR);
    }

    /** @param callable(array<string, mixed>&): void $mutate */
    private static function with(callable $mutate): JobCriteria
    {
        $data = self::shippedArray();
        $mutate($data);

        return JobCriteriaLoader::fromArray($data);
    }
}
