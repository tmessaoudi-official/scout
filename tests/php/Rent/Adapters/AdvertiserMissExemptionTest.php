<?php

declare(strict_types=1);

namespace Scout\Tests\Rent\Adapters;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Adapters\Mail\FileMailbox;
use Scout\Rent\Adapters\EmailAlertSource;
use Scout\Rent\Config\ConfigLoader;
use Scout\Rent\Store\Store;

/**
 * F30 — `advertiser_pattern` is a MESSAGE-level pattern counted once per CARD, and that is what
 * made it look broken.
 *
 * The first deployed run of the extraction-miss signal printed
 * `advertiser_pattern 375/405 carte(s) sans résultat`, and every later one prints the same shape.
 * It is not a template change: an ordinary SeLoger alert names no agency at all, and the pattern
 * anchors on the `exclusivités` template — `<ADVERTISER> vous adresse ses dernières exclusivités`,
 * one listing each — so on a full-window pass it CANNOT be satisfied on the other ~92 % of cards.
 *
 * Two failure modes, and the second is the one that matters. On a busy pass it prints a large ratio
 * for ever, which is noise. On a THIN pass — the IMAP window truncated harder, or a quiet stretch
 * where one agencyless 3-card alert is the whole pass — `misses === calls` is satisfied exactly, and
 * the WARN fires on a pattern nobody can satisfy. That fires the one signal F27 exists to give, on
 * the one occasion it means nothing.
 *
 * **DROPPING THE PATTERN WAS RULED AND THEN OVERRULED, and the reason is §1.** It feeds
 * `LandlordRegistry`, which is the whole mechanism of `dede8ac`: 23 SeLoger rows advertised by an
 * institutional landlord (16 In'li, 7 CDC) were being judged `LIBRE` at the portal's 50bp default
 * and 21 were pushed as MATCHes. Removing the key sends those back. So the question was never
 * whether to satisfy §1 but how — and the answer is the CAR DOMAIN'S OWN PRECEDENT, already ruled
 * and already tested there: a message-level pattern is not counted per card. `subject_pattern`
 * takes exactly this treatment in `VehicleEmailSource`, for exactly this reason.
 *
 * **STATED COST, and it is real:** nothing now reports the `exclusivités` template changing. The
 * ratio was the only thing watching it, and it was watching it uselessly — but "uselessly" is not
 * "not at all", and this is what the exemption buys.
 */
#[CoversClass(EmailAlertSource::class)]
final class AdvertiserMissExemptionTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../../..';

    private ?string $dbPath = null;

    protected function tearDown(): void
    {
        if ($this->dbPath !== null) {
            @unlink($this->dbPath);
            @unlink($this->dbPath . '-wal');
            @unlink($this->dbPath . '-shm');
        }
    }

    public function testAMessageLevelAdvertiserPatternIsNeverCountedPerCard(): void
    {
        $source = $this->seloger();
        $source->fetch();

        self::assertArrayNotHasKey(
            'advertiser_pattern',
            $source->patternMisses()->counts(),
            'a pattern read from the SUBJECT must not be counted once per card',
        );
        self::assertNotContains('advertiser_pattern', $source->patternMisses()->total());
    }

    /**
     * THE COUNTERWEIGHT, and it is the whole guard: the exemption must stay a named exception, not
     * become a way of switching the signal off. Every OTHER configured pattern on the same source
     * still funnels through `matchParam()` and is still counted.
     */
    public function testEveryOtherConfiguredPatternIsStillCounted(): void
    {
        $source = $this->seloger();
        $source->fetch();

        $counted = array_keys($source->patternMisses()->counts());

        self::assertContains('residence_pattern', $counted, 'a per-card pattern is still counted');
        self::assertContains('commune_pattern', $counted);
        self::assertContains('title_pattern', $counted);
    }

    /**
     * And the exemption is EXACTLY ONE KEY. Read from the class itself, so a later hand adding a
     * second name to the list has to change this assertion and say why — set membership rather than
     * discipline, which is the shape the car domain's own guard takes.
     */
    public function testTheExemptionCoversOneKeyAndNoOther(): void
    {
        $constants = (new \ReflectionClass(EmailAlertSource::class))->getConstants();

        self::assertArrayHasKey('UNCOUNTED_PARAMS', $constants);
        self::assertSame(['advertiser_pattern'], $constants['UNCOUNTED_PARAMS']);
    }

    private function seloger(): EmailAlertSource
    {
        $this->dbPath ??= sys_get_temp_dir() . '/rentwatch-adv-' . bin2hex(random_bytes(8)) . '.sqlite3';

        $definition = ConfigLoader::loadSources(self::ROOT . '/config/rent/sources.json')['seloger'];
        $criteria = ConfigLoader::loadCriteria(self::ROOT . '/config/rent/criteria.json');

        return new EmailAlertSource(
            $definition,
            new FileMailbox(self::ROOT . '/tests/fixtures/rent/seloger'),
            $criteria->communeLabels,
        );
    }
}
