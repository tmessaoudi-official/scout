<?php

declare(strict_types=1);

namespace Scout\Tests\Job;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Job\JobListing;
use Scout\Job\JobSnapshot;

/**
 * The evidence a verdict was formed from, round-tripped. The car snapshot's contract, applied to the job domain.
 */
#[CoversClass(JobSnapshot::class)]
final class JobSnapshotTest extends TestCase
{
    public function testRoundTripPreservesEveryField(): void
    {
        $listing = new JobListing(
            sourceName: 'linkedin', externalId: '4461976159', title: 'Senior Software Engineer',
            company: 'Aneo', location: 'Montrouge', description: 'Entre 44 k € et 70 k € par an',
            fields: ['recrutement_actif' => true, 'relations' => 3], url: 'https://www.linkedin.com/comm/jobs/view/4461976159/',
            contracts: ['cdi', 'freelance'], workMode: 'hybrid', salaryMinEur: 44000, salaryMaxEur: 70000,
            tjmMinEur: 450, tjmMaxEur: 600, payText: 'Entre 44 k € et 70 k € par an',
            publishedAt: '2026-09-10T08:00:00Z', observedAt: '2026-09-11T07:33:06Z',
        );

        $back = JobSnapshot::decode(JobSnapshot::encode($listing));

        foreach ((new \ReflectionClass(JobListing::class))->getConstructor()?->getParameters() ?? [] as $p) {
            $name = $p->getName();
            self::assertSame($listing->$name, $back->$name, 'round trip lost ' . $name);
        }
    }

    public function testUnknownsStayNullNeverZero(): void
    {
        $back = JobSnapshot::decode(JobSnapshot::encode(new JobListing(sourceName: 's', externalId: 'x')));

        self::assertNull($back->salaryMinEur);
        self::assertNull($back->salaryMaxEur);
        self::assertNull($back->tjmMaxEur);
        self::assertNull($back->workMode);
        self::assertNull($back->observedAt);
        self::assertSame([], $back->contracts, 'no contract stated stays no contract stated');
    }

    /** Tomorrow's property cannot leave the snapshot silently: every constructor parameter is encoded. */
    public function testEncoderCoversEveryConstructorParameter(): void
    {
        $encoded = json_decode(JobSnapshot::encode(new JobListing(sourceName: 's', externalId: 'x')), true);
        foreach ((new \ReflectionClass(JobListing::class))->getConstructor()?->getParameters() ?? [] as $p) {
            self::assertArrayHasKey($p->getName(), $encoded, 'JobSnapshot::encode() does not cover ' . $p->getName());
        }
    }

    public function testMalformedSnapshotIsRefusedLoudly(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        JobSnapshot::decode('{"nope": true}');
    }

    /**
     * A work mode the model does not know is REFUSED, at construction and therefore on decode. This
     * is the `Tenure::tryFrom()` lesson: a corrupt stored value read back as "nothing said" silently
     * turns an explicit `onsite` into an unstated mode.
     */
    public function testAnUnknownWorkModeIsRefused(): void
    {
        foreach (['remote', 'hybrid', 'onsite', null] as $ok) {
            self::assertSame($ok, (new JobListing(sourceName: 's', externalId: 'x', workMode: $ok))->workMode);
        }

        $this->expectException(\InvalidArgumentException::class);
        JobSnapshot::decode((string) json_encode([
            'sourceName' => 's', 'externalId' => 'x', 'fields' => [], 'contracts' => [], 'workMode' => 'Hybride',
        ]));
    }

    /** The one sanctioned way to change a listing's observation time: clone-with, never a field-by-field rebuild. */
    public function testWithObservedAtChangesThatFieldAndNothingElse(): void
    {
        $listing = new JobListing(
            sourceName: 'linkedin', externalId: '4461976159', title: 'T', company: 'C', contracts: ['cdi'],
            workMode: 'remote', salaryMaxEur: 70000, observedAt: '2026-09-11T07:33:06Z',
        );

        $moved = $listing->withObservedAt('2026-09-12T08:00:00Z');

        self::assertSame('2026-09-12T08:00:00Z', $moved->observedAt);
        foreach ((new \ReflectionClass(JobListing::class))->getConstructor()?->getParameters() ?? [] as $p) {
            $name = $p->getName();
            if ($name !== 'observedAt') {
                self::assertSame($listing->$name, $moved->$name, 'withObservedAt() changed ' . $name);
            }
        }
    }

    /** A zero is a real stated figure, and a salary of 0 must not decode as "unknown". */
    public function testAStoredZeroIsNotReadAsUnknown(): void
    {
        $back = JobSnapshot::decode(JobSnapshot::encode(new JobListing(sourceName: 's', externalId: 'x', tjmMinEur: 0)));

        self::assertSame(0, $back->tjmMinEur);
    }
}
