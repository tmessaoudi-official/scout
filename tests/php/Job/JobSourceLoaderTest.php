<?php

declare(strict_types=1);

namespace Scout\Tests\Job;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Config\ConfigError;
use Scout\Job\JobSourceDefinition;
use Scout\Job\JobSourceLoader;

/**
 * `config/job/sources.json`, strictly. Every refusal here is a config shape whose failure would be
 * SILENT: a misspelt param loads and does nothing, a pattern that cannot compile matches nothing,
 * and an enabled source with no card link pattern yields no offer, ever, while reporting a quiet market.
 */
#[CoversClass(JobSourceLoader::class)]
#[CoversClass(JobSourceDefinition::class)]
final class JobSourceLoaderTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../..';

    public function testTheShippedConfigLoadsLinkedInAndFreeWork(): void
    {
        $sources = JobSourceLoader::load(self::ROOT . '/config/job/sources.json');

        self::assertSame(['linkedin', 'freework'], array_keys($sources));
        self::assertTrue($sources['freework']->enabled);
        self::assertSame('email_digest', $sources['freework']->type);
        $d = $sources['linkedin'];
        self::assertTrue($d->enabled);
        self::assertSame('portal', $d->family);
        self::assertSame('email_alert', $d->type);
        self::assertSame('jobalerts-noreply@linkedin.com', $d->param('from'));
        self::assertSame('Voir toutes les offres', $d->param('footer_marker'));
        self::assertNull($d->feedSilentDays);
        self::assertSame(1, preg_match((string) $d->param('card_link_pattern'), 'https://www.linkedin.com/comm/jobs/view/4461976159/?trackingId=x', $m));
        self::assertSame('4461976159', $m[1]);
    }

    /** A blank param reads as absent, so an empty declaration cannot pass for a configured one. */
    public function testABlankParamReadsAsAbsent(): void
    {
        $d = new JobSourceDefinition(name: 'x', enabled: false, family: 'portal', type: 'email_alert', params: ['footer_marker' => '  ']);

        self::assertNull($d->param('footer_marker'));
        self::assertNull($d->param('nowhere'));
    }

    public function testAMisspeltParamIsRefused(): void
    {
        $this->expectRefusal('card_link_patern', ['card_link_patern' => '~x~']);
    }

    /** Outside the `enabled` branch: `--source=` force-runs a disabled source, so the guard must meet it. */
    public function testAnUnreadParamIsRefusedOnADisabledSourceToo(): void
    {
        $this->expectRefusal('subject_pattern', ['subject_pattern' => '~x~'], enabled: false);
    }

    public function testAPatternThatDoesNotCompileIsRefused(): void
    {
        foreach (['card_link_pattern', 'place_pattern', 'pay_pattern'] as $key) {
            try {
                JobSourceLoader::fromArray($this->config([$key => '~(unclosed~u']));
                self::fail($key . ' that cannot compile must be refused');
            } catch (ConfigError $e) {
                self::assertStringContainsString($key, $e->getMessage());
            }
        }
    }

    public function testAnEnabledSourceMustNameItsSenderItsCardLinkAndItsPlaceLine(): void
    {
        foreach (['from', 'card_link_pattern', 'place_pattern'] as $key) {
            $params = self::validParams();
            unset($params[$key]);
            try {
                JobSourceLoader::fromArray($this->config([], $params));
                self::fail('an enabled email_alert without ' . $key . ' must be refused');
            } catch (ConfigError $e) {
                self::assertStringContainsString($key, $e->getMessage());
            }
        }
    }

    /** The counterweight: a DISABLED source may be half-written while it is being onboarded. */
    public function testADisabledSourceMayOmitThem(): void
    {
        $sources = JobSourceLoader::fromArray($this->config([], ['from' => 'x@y.test'], enabled: false));

        self::assertFalse($sources['linkedin']->enabled);
    }

    /** A place pattern without both named groups would load and fill neither field. */
    public function testThePlacePatternMustNameTheCompanyAndTheLocation(): void
    {
        foreach (['~^(?<company>.+) · (.+)$~u', '~^(.+) · (?<location>.+)$~u'] as $pattern) {
            try {
                JobSourceLoader::fromArray($this->config(['place_pattern' => $pattern]));
                self::fail('place_pattern without company and location groups must be refused: ' . $pattern);
            } catch (ConfigError $e) {
                self::assertStringContainsString('place_pattern', $e->getMessage());
            }
        }
    }

    public function testFeedSilentDaysIsReadAndMustBeAtLeastOne(): void
    {
        $data = $this->config();
        $data['sources']['linkedin']['feed_silent_days'] = 2;
        self::assertSame(2, JobSourceLoader::fromArray($data)['linkedin']->feedSilentDays);

        $data['sources']['linkedin']['feed_silent_days'] = 0;
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessage('feed_silent_days');
        JobSourceLoader::fromArray($data);
    }

    public function testAnUnknownKeyTypeOrFamilyIsRefused(): void
    {
        $cases = [
            'url' => static function (array $d): array { $d['sources']['linkedin']['url'] = 'https://x.test'; return $d; },
            'type' => static function (array $d): array { $d['sources']['linkedin']['type'] = 'html'; return $d; },
            'family' => static function (array $d): array { $d['sources']['linkedin']['family'] = 'agency'; return $d; },
        ];
        foreach ($cases as $label => $mutate) {
            try {
                JobSourceLoader::fromArray($mutate($this->config()));
                self::fail($label . ' must be refused');
            } catch (ConfigError $e) {
                self::assertStringContainsString($label, $e->getMessage());
            }
        }
    }

    public function testAnUnreadableFileIsRefused(): void
    {
        $this->expectException(ConfigError::class);
        JobSourceLoader::load(self::ROOT . '/config/job/nope.json');
    }

    /** @param array<string, string> $params */
    private function expectRefusal(string $key, array $params, bool $enabled = true): void
    {
        try {
            JobSourceLoader::fromArray($this->config($params, null, $enabled));
            self::fail($key . ' must be refused');
        } catch (ConfigError $e) {
            self::assertStringContainsString($key, $e->getMessage());
        }
    }

    /** @return array<string, string> */
    private static function validParams(): array
    {
        return [
            'from' => 'jobalerts-noreply@linkedin.com',
            'card_link_pattern' => '~^https://www\.linkedin\.com/comm/jobs/view/(\d+)/~',
            'footer_marker' => 'Voir toutes les offres',
            'place_pattern' => '~^(?<company>.+) · (?<location>.+?)(?: \((?<mode>[^()]+)\))?$~u',
            'pay_pattern' => '~€~u',
        ];
    }

    /**
     * @param array<string, string>      $overrides merged over the valid params
     * @param array<string, string>|null $params    replaces the valid params when given
     *
     * @return array<string, mixed>
     */
    private function config(array $overrides = [], ?array $params = null, bool $enabled = true): array
    {
        return ['sources' => ['linkedin' => [
            'enabled' => $enabled,
            'family' => 'portal',
            'type' => 'email_alert',
            'params' => [...($params ?? self::validParams()), ...$overrides],
        ]]];
    }
}
