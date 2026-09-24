<?php

declare(strict_types=1);

namespace Scout\Tests\Job;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Adapters\Mail\FileMailbox;
use Scout\Job\JobClassifier;
use Scout\Job\JobCriteriaLoader;
use Scout\Job\JobDigestEmailSource;
use Scout\Job\JobListing;
use Scout\Job\JobOutcome;
use Scout\Job\JobScorer;
use Scout\Job\JobSourceLoader;
use Scout\Job\JobStore;

/**
 * THE FIRST REAL FREE-WORK ALERT, SCRUBBED, EVERY PINNED VALUE HAND-READ (n=1, 2026-09-24).
 *
 * `tests/fixtures/job/freework/01.eml` is one daily digest: four alert sections (Dev, Lead/Management,
 * Architecture, DevOps/SRE), ten cards shown in each, 40 cards and 36 distinct offers — the sections
 * overlap by design. The FIRST card of every section is glued onto its section header line, so a
 * line-anchored reader finds 36 cards of 40; the Dev one (`qa-analyst-23`) is the one a line-anchored
 * reader loses outright, since the other three are repeats.
 *
 * `00.eml` is a profile reminder from the SAME sender, carrying three real `job-mission` links in a
 * different card shape (`**Title** | place`). It must not be claimed: its subject is not an alert's.
 *
 * Scrubbed with `tools/scrub-eml.php` (name, alert ids, Mailjet click paths and the unsubscribe
 * signatures replaced). Parsed back, it yields the same 40 cards as the raw capture.
 */
#[CoversClass(JobDigestEmailSource::class)]
final class FreeWorkFixtureTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../..';

    /** The 36 distinct offers in first-appearance order, as `category/slug` read off the fixture's URLs. */
    private const array IDS = [
        'business-analyst/qa-analyst-23',
        'lead-developer/lead-developer-full-stack-next-js-node-js-h-f-1',
        'developpeur-integrateur-dapplication-erp-crm-dynamics-oracle-salesforce-sap-sage-sharepoint-sybase/developpeur-senior-salesforce-apex-lwc-api-rest-f-h',
        'developpeur-java-kotlin-groovy-scala/developeur-java-angular-2',
        'ingenieur-devops-cloud/expert-developpeur-marketing-cloud-campagnes-automatisation-f-h',
        'consultant/consultant-developpeur-webmethods-senior',
        'developpeur-mobile-android/developpeur-android-senior-expert-mobile-native-kotlin',
        'consultant-decisionnel-bi-powerbi-sas-tableau/developpeur-data-bi-sas-sql-webdev-power-bi-h-f',
        'developpeur-php-symfony-laravel-drupal/developpeur-php-symfony-angular-senior-h-f',
        'developpeur-integrateur-dapplication-erp-crm-dynamics-oracle-salesforce-sap-sage-sharepoint-sybase/developpeur-sap-abap-senior-2',
        'lead-developer/tech-lead-mongodb-f-h-2',
        'ingenieur-devops-cloud/tech-lead-senior-migration-dapplications-3-tiers-vers-le-cloud-dmzr',
        'ingenieur-devops-cloud/tech-lead-senior-migration-cloud',
        'lead-developer/tech-lead-data-engineering-plateforme-data',
        'data-engineer/tech-lead-data-engineer-h-f-30',
        'developpeur-php-symfony-laravel-drupal/lead-developpeur-php-telecom-paris-h-f',
        'lead-developer/399-ai-tech-lead-genai-enablement',
        'lead-developer/developpeur-rpa-blueprism-obligatoire',
        'ingenieur-devops-cloud/tech-lead-informatica-devops-referentiel-didentite',
        'architecte-cloud/architecte-solution-salesforce-environnement-multi-cloud-f-h',
        'project-management-officer/directeur-directrice-du-patrimoine-applicatif-management-de-transition',
        'autre/architecte-solution-h-f-106',
        'architecte-de-base-de-donnees/architecte-infrastructure-generaliste-3',
        'architecte-de-base-de-donnees/architecte-junior-infrastructure-ingenieur-reseau-evolutif',
        'architecte-solutions/architecte-solutions-infrastructure-2',
        'administrateur-de-base-de-donnee-oracle-sybase/chef-fe-de-projet-iam-oracle-f-h',
        'administrateur-securite/architecte-expert-reseau-securite-bilingue-anglais',
        'administrateur-securite/expert-architecte-securite-dynamics-365-power-pages-waf',
        'assistant-chef-de-projet/chef-de-projet-deploiement-xray-migration-des-referentiels-de-test-1',
        'ingenieur-devops-cloud/ingenieur-devops-integration-applicative-secteur-energie',
        'ingenieur-devops-cloud/directeur-de-programme-infrastructure-cloud-devops',
        'ingenieur-devops-cloud/ingenieur-splunk-senior-devops',
        'consultant-fonctionnel/data-engineer-databricks-azure-squad-supply-chain-h-f',
        'ingenieur-devops-cloud/expert-cloud-devops-10',
        'ingenieur-devops-cloud/devops-engineer-jenkins',
        'ingenieur-devops-cloud/ingenieur-ops-openshift-rhoai-devops-gitops-et-ia',
    ];

    /**
     * Six cards pinned field by field — each for one shape the template can take.
     *
     * @var array<string, array{title: string, contracts: list<string>, location: string, pay: ?string, labels: ?string}>
     */
    private const array CARDS = [
        // The GLUED first card: on the same line as the Dev section header.
        'business-analyst/qa-analyst-23' => ['title' => 'QA Analyst (F/H)', 'contracts' => ['Freelance'], 'location' => 'Saint-Denis, Île-de-France', 'pay' => null, 'labels' => '1 mois'],
        // A ` - ` INSIDE the title: the separator before the contracts is ` -  ` (two spaces).
        'ingenieur-devops-cloud/expert-developpeur-marketing-cloud-campagnes-automatisation-f-h' => ['title' => 'Expert / Développeur Marketing Cloud - Campagnes & Automatisation (F/H)', 'contracts' => ['Freelance'], 'location' => 'Paris, France', 'pay' => 'TJM 350-400 € par jour', 'labels' => '6 mois'],
        // A CDI: an annual salary and no duration.
        'consultant/consultant-developpeur-webmethods-senior' => ['title' => 'Consultant / Développeur webMethods Senior', 'contracts' => ['CDI'], 'location' => 'Puteaux, Île-de-France', 'pay' => 'salaire annuel 60k-67k €', 'labels' => null],
        // Both contracts, both pay lines.
        'developpeur-mobile-android/developpeur-android-senior-expert-mobile-native-kotlin' => ['title' => 'Développeur Android Senior / Expert Mobile Native – Kotlin', 'contracts' => ['Freelance', 'CDI'], 'location' => 'Paris, France', 'pay' => "salaire annuel 40k-49k €\nTJM 400-550 € par jour", 'labels' => '12 mois'],
        // No pay at all.
        'ingenieur-devops-cloud/tech-lead-senior-migration-cloud' => ['title' => 'Tech Lead Senior Migration Cloud', 'contracts' => ['Freelance', 'CDI'], 'location' => 'Île-de-France, France', 'pay' => null, 'labels' => '12 mois'],
        // A postcode in the place, and a single-figure day rate elsewhere in the digest.
        'ingenieur-devops-cloud/ingenieur-devops-integration-applicative-secteur-energie' => ['title' => 'Ingénieur DevOps Intégration applicative secteur energie', 'contracts' => ['Freelance'], 'location' => '75001, Paris, Île-de-France', 'pay' => null, 'labels' => '8 mois'],
        'lead-developer/399-ai-tech-lead-genai-enablement' => ['title' => '399 - AI Tech Lead (GenAI Enablement)', 'contracts' => ['Freelance'], 'location' => 'Paris, France', 'pay' => 'TJM 730 € par jour', 'labels' => '12 mois'],
    ];

    public function testTheDigestYieldsItsThirtySixDistinctOffersInOrder(): void
    {
        $offers = $this->source()->fetch();

        self::assertSame(self::IDS, array_map(static fn (JobListing $o): string => $o->externalId, $offers));
    }

    public function testThePinnedCardsAreReadAsHandRead(): void
    {
        $byId = [];
        foreach ($this->source()->fetch() as $o) {
            $byId[$o->externalId] = $o;
        }

        foreach (self::CARDS as $id => $want) {
            self::assertArrayHasKey($id, $byId, $id);
            $o = $byId[$id];
            self::assertSame('freework', $o->sourceName, $id);
            self::assertSame($want['title'], $o->title, $id);
            self::assertSame($want['contracts'], $o->contracts, $id);
            self::assertSame($want['location'], $o->location, $id);
            self::assertSame($want['pay'], $o->payText, $id);
            self::assertSame($want['labels'] === null ? [] : ['labels' => $want['labels']], $o->fields, $id);
            self::assertSame('', $o->company, $id . ': a Free-Work card names no company');
            self::assertNull($o->workMode, $id . ': a Free-Work card states no work mode');
            self::assertSame('https://www.free-work.com/fr/tech-it/job-mission/' . $id, $o->url, $id . ': the query (a campaign tag) is dropped');
            self::assertSame('2026-09-24T06:27:46Z', $o->observedAt, $id);
            self::assertNull($o->publishedAt, $id . ': "dernières 24h" is not a publication date');
        }
    }

    /** The counterweight: the four sections overlap by design, and that is neither a warning nor a miss. */
    public function testRepeatsAcrossSectionsAreNotWarnedAndNothingIsBlind(): void
    {
        $warnings = [];
        $source = $this->source(static function (string $w) use (&$warnings): void {
            $warnings[] = $w;
        });
        $source->fetch();

        self::assertSame([], $warnings);
        self::assertSame([], $source->patternMisses()->total());
        foreach ($source->patternMisses()->counts() as $key => $c) {
            self::assertSame(0, $c['misses'], $key . ' missed on a real alert');
        }
    }

    /** End to end, SHIPPED criteria: a 60–67 k€ CDI developer offer matches with its pay read. */
    public function testTheWebMethodsCdiMatchesWithItsSalaryRead(): void
    {
        [$facts, $verdict] = $this->judge('consultant/consultant-developpeur-webmethods-senior');

        self::assertCount(1, $facts->pay);
        self::assertSame(60000, $facts->pay[0]->minEur);
        self::assertSame(67000, $facts->pay[0]->maxEur);
        self::assertSame(JobOutcome::MATCH, $verdict->outcome, implode(' / ', $verdict->reasons));
    }

    /** End to end, SHIPPED criteria: a 350–400 € day rate is under the 450 € TJM floor. */
    public function testTheMarketingCloudMissionIsRejectedOnItsDayRate(): void
    {
        [, $verdict] = $this->judge('ingenieur-devops-cloud/expert-developpeur-marketing-cloud-campagnes-automatisation-f-h');

        self::assertSame(JobOutcome::REJECT, $verdict->outcome);
        self::assertStringContainsString('sous le plancher', implode(' / ', $verdict->reasons));
    }

    /** N2 on a real card: a salary under its floor beside a day rate over its own is NOT rejected on pay. */
    public function testADualOfferIsKeptWhenOneLineClearsItsFloor(): void
    {
        [$facts, $verdict] = $this->judge('ingenieur-devops-cloud/tech-lead-informatica-devops-referentiel-didentite');

        self::assertCount(2, $facts->pay, '40–45 k€ AND 400–520 €/j are both read');
        self::assertStringNotContainsString('sous le plancher', implode(' / ', $verdict->reasons));
    }

    /** @return array{0: \Scout\Job\JobFacts, 1: \Scout\Job\JobVerdict} */
    private function judge(string $id): array
    {
        $offers = array_values(array_filter($this->source()->fetch(), static fn (JobListing $o): bool => $o->externalId === $id));
        self::assertCount(1, $offers);

        $criteria = JobCriteriaLoader::load(self::ROOT . '/config/job/criteria.json');
        $facts = (new JobClassifier())->read($offers[0]);

        return [$facts, (new JobScorer())->judge($offers[0], $facts, $criteria, new \DateTimeImmutable('2026-09-24T12:00:00Z'))];
    }

    /** @param ?\Closure(string): void $warn */
    private function source(?\Closure $warn = null): JobDigestEmailSource
    {
        return new JobDigestEmailSource(
            JobSourceLoader::load(self::ROOT . '/config/job/sources.json')['freework'],
            JobStore::open(':memory:'),
            new FileMailbox(self::ROOT . '/tests/fixtures/job/freework'),
            $warn,
        );
    }
}
