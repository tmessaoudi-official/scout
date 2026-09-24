<?php

declare(strict_types=1);

namespace Scout\Tests\Job;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Scout\Adapters\Mail\EmailMessage;
use Scout\Adapters\Mail\FileMailbox;
use Scout\Job\JobDigestEmailSource;
use Scout\Job\JobListing;
use Scout\Job\JobSourceLoader;
use Scout\Job\JobStore;

/**
 * THE FIRST REAL APEC SAVED-SEARCH DIGEST, SCRUBBED, THE PINNED VALUES HAND-READ (n=1, 2026-09-24).
 *
 * `01.eml` is one daily digest for four searches, twelve cards each: 48 cards and 45 distinct offers —
 * the searches overlap, and an identical repeat is kept once in silence. HTML only. Each field is
 * followed by a link to its offer, and the four links DIFFER — each has its own slot in `id` and its
 * own `s`; only the `e` token (`p1=www.apec.fr&p2=<id>W…`, the one place the offer id lives) is shared.
 * Some cards also carry a logo link before the title, which the card pattern's title anchor skips.
 * The scrubber gave each distinct `id`, `s` and header `e` its own placeholder, so all 221 links stay
 * distinct: an earlier scrub used one constant, made the links identical, and the reader written
 * against that fixture read 45 offers here and 0 on the live message.
 *
 * `00.eml` is Apec's weekly "Nos recommandations d'offres d'emploi" from the SAME sender, kept as the
 * counterweight: its subject is not an alert's, so it must never be claimed.
 */
#[CoversClass(JobDigestEmailSource::class)]
final class ApecFixtureTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../..';

    /**
     * One card per shape, read off the rendered text by hand.
     *
     * @var array<string, array{title: string, company: string, location: string}>
     */
    private const array CARDS = [
        // A card WITH a logo link before its title — and a relaying board for a company.
        '179473574' => ['title' => 'Software engineer full stack - totalenergies digital factory F/H', 'company' => 'cadremploi', 'location' => 'Paris 01 - 75'],
        // A card WITHOUT one: the previous card's last link sits just above its title.
        '179472775' => ['title' => 'Lead Développeur Full-Stack F/H', 'company' => 'SKAELIA', 'location' => 'Paris 10 - 75'],
    ];

    public function testTheDigestYieldsEveryOfferItsTokensName(): void
    {
        $offers = $this->source()->fetch();
        $read = array_map(static fn (JobListing $o): string => $o->externalId, $offers);

        self::assertCount(45, $offers);
        $body = EmailMessage::parse((string) file_get_contents(self::ROOT . '/tests/fixtures/job/apec/01.eml'))->body;
        preg_match_all('~[?&]e=([A-Za-z0-9_-]+)~', $body, $m);
        $named = [];
        foreach ($m[1] as $token) {
            if (preg_match('~\bp2=(\d+)W~', (string) base64_decode(strtr($token, '-_', '+/'), true), $id) === 1) {
                $named[$id[1]] = true;
            }
        }
        $ids = array_map('strval', array_keys($named));
        sort($ids);
        sort($read);
        self::assertSame($ids, $read, 'an offer a token names and no card read is a lost card');
    }

    public function testThePinnedCardsAreReadAsHandRead(): void
    {
        $byId = [];
        foreach ($this->source()->fetch() as $o) {
            $byId[$o->externalId] = $o;
        }

        foreach (self::CARDS as $id => $want) {
            self::assertArrayHasKey($id, $byId, (string) $id);
            $o = $byId[$id];
            self::assertSame('apec', $o->sourceName, (string) $id);
            self::assertSame($want['title'], $o->title, (string) $id);
            self::assertSame($want['company'], $o->company, (string) $id);
            self::assertSame($want['location'], $o->location, (string) $id);
            self::assertSame(['CDI'], $o->contracts, $id . ': the `CDI •` bullet is not part of the contract');
            self::assertNull($o->payText, $id . ': an Apec card states no pay');
            self::assertStringStartsWith('https://neomarket.diffusion.apec.fr/r/?', (string) $o->url, $id . ': the token names no URL, so the link is kept');
            self::assertStringContainsString('&e=', (string) $o->url, $id . ': kept WHOLE — its query is the link');
            self::assertSame('2026-09-24T05:21:50Z', $o->observedAt, (string) $id);
        }
    }

    /** The counterweight: overlapping searches are not a warning, and not one rule missed. */
    public function testRepeatsAreNotWarnedAndNothingIsBlind(): void
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

    /** The weekly recommendations mail carries real offer links from the same sender and is never claimed. */
    public function testTheRecommendationsMailIsNotClaimed(): void
    {
        $mailbox = new RecordingMailbox([
            (string) file_get_contents(self::ROOT . '/tests/fixtures/job/apec/00.eml'),
            (string) file_get_contents(self::ROOT . '/tests/fixtures/job/apec/01.eml'),
        ]);
        $source = new JobDigestEmailSource(
            JobSourceLoader::load(self::ROOT . '/config/job/sources.json')['apec'],
            JobStore::open(':memory:'),
            $mailbox,
        );

        self::assertCount(45, $source->fetch());
        self::assertSame([1], $mailbox->claimed);
    }

    /** @param ?\Closure(string): void $warn */
    private function source(?\Closure $warn = null): JobDigestEmailSource
    {
        return new JobDigestEmailSource(
            JobSourceLoader::load(self::ROOT . '/config/job/sources.json')['apec'],
            JobStore::open(':memory:'),
            new FileMailbox(self::ROOT . '/tests/fixtures/job/apec'),
            $warn,
        );
    }
}
