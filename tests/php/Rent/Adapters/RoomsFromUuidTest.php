<?php

declare(strict_types=1);

namespace Scout\Tests\Rent\Adapters;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Scout\Adapters\Mail\FileMailbox;
use Scout\Rent\Adapters\EmailAlertSource;
use Scout\Rent\Config\ConfigLoader;
use Scout\Rent\Config\SourceDefinition;
use Scout\Rent\Store\Store;

/**
 * TRACK 1j — the generic rooms reader matched HEX inside photo-URL UUIDs.
 *
 * `ROOMS_PATTERN`'s first branch exists for French `T3` / `F4` notation. It was case-insensitive
 * and required a word boundary only AFTER the digit, so it matched any `t`/`f` followed by a digit
 * — and every alert card opens with image URLs full of hexadecimal UUIDs. `preg_match` is
 * first-match-wins and the photo sits near the TOP of the card, so the UUID beat the real
 * `N pièces` text further down.
 *
 * Fifth instance of *URLs are classified text* (corpus `url-001`/`url-002`), and the same
 * first-match-wins shape as the PAP criteria-line defect.
 *
 * MEASURED OVER THE WHOLE STORE, by comparing each row's own TITLE — independent ground truth,
 * written by the portal — against the count the pipeline stored: **21 rows wrong** (14 seloger,
 * 7 bienici), of which 12 too high and 6 already NOTIFIED with a room count the listing never
 * stated. Only `pap` configures a `rooms_pattern`; every other email source falls through to this
 * scan. The HTML sources map rooms from a structured field and are unaffected.
 *
 * BOTH DIRECTIONS DO HARM, and the low one is the one already costing matches: four real 3-pièces
 * flats were stored as 1 or 2 and silently REJECTED by `min_rooms: 3`. No false MATCH has been
 * *caused* by the high direction yet — every inflated row would have passed at its true count too —
 * and that is luck, not a guard: a `T1` studio whose card carries an `F5` UUID reads as five rooms
 * and clears `min_rooms` outright.
 */
#[CoversClass(EmailAlertSource::class)]
final class RoomsFromUuidTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../../..';

    /**
     * The real UUID fragments taken from stored rows, each with the count it wrongly produced.
     *
     * @return iterable<string, array{0: string, 1: int}>
     */
    public static function uuidShapes(): iterable
    {
        // Digit before the letter — the commonest, and the one a plain `\b` cannot see.
        yield 'digit before F' => ['90F8-739278F557C5.jpg', 8];
        yield 'digit before F, lower' => ['92f5-4ae6d9659b8d.jpg', 5];
        yield 'zero before F' => ['40F6-AADF-8FFCE30D5EA8.jpg', 6];
        // Letter before the letter.
        yield 'letter before f' => ['80f9-4aaa-8d9a-4deff97.jpg', 9];
        yield 'letter before T' => ['abT7-91bc-4d0e-a1f2.jpg', 7];
    }

    #[DataProvider('uuidShapes')]
    public function testHexInAPhotoUrlIsNotARoomCount(string $uuid, int $wrong): void
    {
        // The UUID sits ABOVE the real text, exactly as it does in a live card: first-match-wins is
        // half the defect, so a fixture with the URL below the prose would pass without a fix.
        $body = "https://photos.example.test/{$uuid}\nAppartement 3 pièces 65 m²\nhttps://www.seloger.com/annonces/1\n";

        $rooms = $this->roomsIn($body);

        self::assertNotSame($wrong, $rooms, "the hex in {$uuid} must not be read as a room count");
        self::assertSame(3, $rooms, 'the listing states 3 pièces and that is the only room count in it');
    }

    /**
     * THE COUNTERWEIGHT, and without it the fix is satisfied by deleting the branch.
     *
     * `T3` / `F4` is ordinary French listing notation and the branch exists for it. Each of these
     * must still read, in the positions a portal actually writes them.
     *
     * @return iterable<string, array{0: string, 1: int}>
     */
    public static function realNotation(): iterable
    {
        yield 'T at the start of the line' => ["T3\n", 3];
        yield 'F after a space' => ["Bel appartement F4 refait\n", 4];
        yield 'T after a space' => ["Location T5 Sartrouville\n", 5];
        yield 'lower case t' => ["joli t3 lumineux\n", 3];
        yield 'T with a space before the digit' => ["Appartement T 4 avec balcon\n", 4];
        yield 'the pièces branch is untouched' => ["Appartement 3 pièces 65 m²\n", 3];
        yield 'pieces without the accent' => ["Appartement 4 pieces\n", 4];
    }

    #[DataProvider('realNotation')]
    public function testOrdinaryFrenchNotationStillReads(string $body, int $expected): void
    {
        self::assertSame($expected, $this->roomsIn($body . "https://www.seloger.com/annonces/1\n"));
    }

    /** The generic scan reads a body through a source that configures no `rooms_pattern`. */
    private function roomsIn(string $body): ?int
    {
        $definition = ConfigLoader::loadSources(self::ROOT . '/config/rent/sources.json')['seloger'];

        // No `card_separator`, no `rooms_pattern`: the plain per-link path, which is where every
        // source but pap lands. Built by hand rather than by mutating the shipped block, so this
        // cannot start passing because seloger gained a pattern of its own.
        $bare = new SourceDefinition(
            name: 'probe',
            enabled: false,
            family: $definition->family,
            type: 'email_alert',
            defaultTenure: $definition->defaultTenure,
            mixedTenure: false,
            map: $definition->map,
            params: ['from' => 'alerts@portal.test', 'link_host' => 'seloger.com/annonces/'],
        );

        $dir = sys_get_temp_dir() . '/rooms-uuid-' . bin2hex(random_bytes(6));
        mkdir($dir);
        file_put_contents(
            $dir . '/probe.eml',
            "From: alerts@portal.test\r\nTo: me@example.invalid\r\nSubject: alerte\r\n"
                . "Date: Sun, 31 Aug 2026 10:00:00 +0200\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n"
                . "1 200 € CC\n" . $body,
        );

        try {
            $source = new EmailAlertSource($bare, new FileMailbox($dir));
            $listings = $source->fetch();

            self::assertCount(1, $listings, 'the probe message must yield exactly one listing');

            return $listings[0]->rooms;
        } finally {
            @unlink($dir . '/probe.eml');
            @rmdir($dir);
        }
    }
}
