<?php

declare(strict_types=1);

namespace Scout\Tests\Job;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Scout\Job\JobPay;
use Scout\Job\PayLine;

/**
 * Pay read out of offer text. Every rejection this reader feeds runs on a STATED figure, so the
 * direction that matters is the false positive: a number that is not pay, read as pay, rejects a
 * real offer and nothing says so. Each trap below is a figure an ordinary ad carries.
 */
#[CoversClass(JobPay::class)]
#[CoversClass(PayLine::class)]
final class JobPayTest extends TestCase
{
    /** The LinkedIn card line, byte for byte: U+00A0 between the figure, the `k` and the `€`. */
    public function testTheLinkedInCardLineWithItsNonBreakingSpaces(): void
    {
        $lines = JobPay::read("Entre 44\u{00A0}k\u{00A0}€ et 70\u{00A0}k\u{00A0}€ par an");

        self::assertCount(1, $lines);
        self::assertSame([PayLine::SALARY, PayLine::GROSS, PayLine::YEAR, 44000, 70000], self::shape($lines[0]));
    }

    /** @return iterable<string, array{string, list<array{string, string, string, int, int}>}> */
    public static function readable(): iterable
    {
        yield 'a gross annual range in full figures' => ['45 000 € - 55 000 € brut annuel', [[PayLine::SALARY, PayLine::GROSS, PayLine::YEAR, 45000, 55000]]];
        yield 'a single monthly figure' => ['Rémunération : 3 500 € brut par mois', [[PayLine::SALARY, PayLine::GROSS, PayLine::MONTH, 3500, 3500]]];
        yield 'the k of the upper bound carries to the lower' => ['Salaire : 55-65k€', [[PayLine::SALARY, PayLine::GROSS, PayLine::YEAR, 55000, 65000]]];
        yield 'a dot as a thousands separator' => ['1.150 EUR par mois', [[PayLine::SALARY, PayLine::GROSS, PayLine::MONTH, 1150, 1150]]];
        yield 'a decimal k' => ['entre 62,5 k€ et 70 k€ par an', [[PayLine::SALARY, PayLine::GROSS, PayLine::YEAR, 62500, 70000]]];
        yield 'a day rate' => ['TJM : 550 € HT', [[PayLine::TJM, PayLine::HT, PayLine::DAY, 550, 550]]];
        yield 'a day rate written per day' => ['Freelance 500-600 €/jour', [[PayLine::TJM, PayLine::HT, PayLine::DAY, 500, 600]]];
        yield 'a day rate quoted TTC is brought back to HT' => ['TJM 600 € TTC', [[PayLine::TJM, PayLine::HT, PayLine::DAY, 500, 500]]];
        yield 'a net figure is kept and marked net' => ['2 800 € net par mois', [[PayLine::SALARY, PayLine::NET, PayLine::MONTH, 2800, 2800]]];
        yield 'a package is kept and marked package' => ['Package de 70 k€ par an', [[PayLine::SALARY, PayLine::PACKAGE, PayLine::YEAR, 70000, 70000]]];
        yield 'a package with no period still reads as annual' => ['package 75k€', [[PayLine::SALARY, PayLine::PACKAGE, PayLine::YEAR, 75000, 75000]]];
        // Both anchors sit before the bonus figure; the NEAREST one decides, so the salary word three
        // words back does not turn a 20 k€ bonus into a second salary line.
        yield 'a bonus after the salary is not pay' => ['Salaire : 60-70 k€ par an, bonus de 20 k€ par an', [[PayLine::SALARY, PayLine::GROSS, PayLine::YEAR, 60000, 70000]]];
        yield 'a dual offer states two lines' => ['CDI 60-70 k€ par an ou freelance 550 €/jour', [
            [PayLine::SALARY, PayLine::GROSS, PayLine::YEAR, 60000, 70000],
            [PayLine::TJM, PayLine::HT, PayLine::DAY, 550, 550],
        ]];
    }

    /** @param list<array{string, string, string, int, int}> $expected */
    #[DataProvider('readable')]
    public function testAStatedPayLineIsRead(string $text, array $expected): void
    {
        self::assertSame($expected, array_map(self::shape(...), JobPay::read($text)));
    }

    /** @return iterable<string, array{string}> */
    public static function notPay(): iterable
    {
        yield 'an hourly rate (N8: ignored)' => ['25 €/h'];
        // The one hourly shape the band cannot catch: a TJM word makes it a day rate, and 125 € a day
        // is plausible — and under the 450 € floor, so read as a TJM it rejects the offer.
        yield 'an hourly rate beside a TJM word' => ['TJM : 125 €/h'];
        yield 'equity' => ['BSPCE 20 k€'];
        yield 'a variable part' => ["variable jusqu'à 10 k€ par an"];
        yield 'a bonus' => ['prime de 1 500 € par an'];
        yield 'a not-pay word nearer the figure than a pay word' => ["Salaire selon profil, primes jusqu'à 20 000 € par an"];
        yield 'a fundraise' => ['levée de 15 M€'];
        yield 'a user count' => ['55k utilisateurs'];
        yield 'years of experience' => ['3-5 ans d’expérience'];
        yield 'a transport benefit' => ['Navigo remboursé à 50 %'];
        yield 'a figure with no unit' => ['Salaire 60000'];
        yield 'a figure with a currency but no period and no salary word' => ['budget de 45 000 €'];
        yield 'an implausible annual salary' => ['5 € par an'];
    }

    #[DataProvider('notPay')]
    public function testAFigureThatIsNotPayIsNeverRead(string $text): void
    {
        self::assertSame([], JobPay::read($text), 'a number that is not pay, read as pay, rejects a real offer in silence');
    }

    /** First-match-wins is how one implausible figure hides a real one three words later. */
    public function testEveryFigureIsExaminedNotOnlyTheFirst(): void
    {
        $lines = JobPay::read("Variable jusqu'à 10 k€. Salaire fixe entre 60 k€ et 70 k€ par an.");

        self::assertSame([[PayLine::SALARY, PayLine::GROSS, PayLine::YEAR, 60000, 70000]], array_map(self::shape(...), $lines));
    }

    /**
     * A thousands separator is a space on ONE line. `poste 45` above `800 € par an` assembled across
     * the break is 45 800 € a year — inside the band, under the 59 k€ floor, a rejection built from two
     * numbers that were never one. Kept apart, `800 € par an` is implausible and reads nothing.
     */
    public function testAFigureIsNeverAssembledAcrossALineBreak(): void
    {
        self::assertSame([], JobPay::read("poste 45\n800 € par an"));
        self::assertSame(
            [[PayLine::SALARY, PayLine::GROSS, PayLine::MONTH, 1450, 1450]],
            array_map(self::shape(...), JobPay::read("réf 850\n1 450 € par mois")),
            'and the real figure on its own line still reads',
        );
    }

    /** ×12 to score, ×13 to reject: a monthly figure only falls under the floor when even 13 months do. */
    public function testAMonthlyFigureAnnualisesDifferentlyForTheScoreAndForTheFloor(): void
    {
        $line = JobPay::read('4 400 € brut par mois')[0];

        self::assertSame(52800, $line->annualMaxForScore());
        self::assertSame(57200, $line->annualMaxForFloor());
        self::assertSame(70000, JobPay::read('entre 60 k€ et 70 k€ par an')[0]->annualMaxForFloor(), 'an annual figure is itself');
        self::assertNull(JobPay::read('550 €/jour')[0]->annualMaxForFloor(), 'a day rate has no annual form');
    }

    public function testTheSameLineStatedTwiceIsReadOnce(): void
    {
        self::assertCount(1, JobPay::read('Entre 44 k € et 70 k € par an. Salaire entre 44 k € et 70 k € par an'));
    }

    /** @return array{string, string, string, int, int} */
    private static function shape(PayLine $l): array
    {
        return [$l->kind, $l->basis, $l->period, $l->minEur, $l->maxEur];
    }
}
