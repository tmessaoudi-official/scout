<?php

declare(strict_types=1);

namespace Scout\Tests\Rent\Adapters;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Rent\Adapters\EmailAlertSource;
use Scout\Adapters\Mail\FileMailbox;
use Scout\Rent\Config\FieldMap;
use Scout\Rent\Config\SourceDefinition;
use Scout\Rent\Core\Tenure;
use Scout\Rent\Store\Store;

/**
 * An email listing is OBSERVED when its message was sent, not when the pass read it.
 *
 * The one fact the 2026-08-29 phantom-drop loop turned on: a re-sent card read on every pass is
 * the SAME observation every time, and only its `Date` says so. The adapter stamps every listing
 * it yields with the message's `sentAt()`; the pipeline hands that to the store as the sighting
 * time, and the store's existing ordering does the rest.
 *
 * An unparseable `Date` yields null — "observed now" — which is today's behaviour and the direction
 * that cannot lose a genuinely new card. It is stated rather than hidden: a portal whose dates are
 * unreadable is a portal on which this guard does not act.
 */
#[CoversClass(EmailAlertSource::class)]
final class EmailObservedAtTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/scout-observed-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o700, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    public function testAListingCarriesItsMessagesDateAsItsObservationTime(): void
    {
        file_put_contents($this->dir . '/a.eml', self::alert('Wed, 26 Aug 2026 18:31:09 +0000', '900'));

        $listings = $this->source()->fetch();

        self::assertCount(1, $listings);
        self::assertSame('2026-08-26T18:31:09Z', $listings[0]->observedAt);
    }

    public function testAnUnreadableDateLeavesTheObservationTimeUnknown(): void
    {
        file_put_contents($this->dir . '/a.eml', self::alert('Fri, 09 Aug 2026 10:00:00 +0000', '900'));

        $listings = $this->source()->fetch();

        self::assertCount(1, $listings);
        self::assertNull($listings[0]->observedAt, 'a misdated message is "now", never a forward-moved instant');
    }

    private function source(): EmailAlertSource
    {
        return new EmailAlertSource(
            new SourceDefinition(
                name: 'portal',
                enabled: true,
                family: 'private',
                type: 'email_alert',
                mixedTenure: false,
                defaultTenure: Tenure::LIBRE,
                params: ['from' => 'alerts@example-portal.test', 'link_host' => 'example-portal.test/annonce/'],
                map: new FieldMap(ref: ['url'], chargesIncluded: true),
            ),
            new FileMailbox($this->dir),
            ['dourdan' => 'Dourdan'],
        );
    }

    private static function alert(string $date, string $rent): string
    {
        return "From: alerts@example-portal.test\r\nTo: me@example.test\r\nSubject: 1 nouvelle annonce\r\n"
            . "Date: {$date}\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n"
            . "Appartement T3\r\n{$rent} €/mois charges comprises\r\n3 pièces . 62 m²\r\nDourdan (91410)\r\n"
            . "https://example-portal.test/annonce/123\r\n";
    }
}
