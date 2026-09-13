<?php

declare(strict_types=1);

namespace Scout\Job;

use Scout\Core\MalformedText;
use Scout\Core\Text;

/**
 * Pay read out of offer text: a salary or a day rate, only when the offer STATES one with a unit.
 *
 * Every rule here leans toward reading NOTHING, because the only decision pay can force is a
 * rejection, and a figure that is not pay read as pay rejects a real offer in silence. So:
 *
 * - **A currency is required**, and a period or a salary word beside it. `Salaire 60000` and
 *   `55k utilisateurs` read nothing; a figure without a unit is ignored, never guessed (ruled).
 * - **The nearest anchor before a figure decides what it is.** `BSPCE`, `prime`, `variable`,
 *   `budget`, `levée` make it not pay; `salaire`, `package`, `TJM` make it pay. Nearest, because
 *   `variable jusqu'à 10 k€. Salaire fixe entre 60 et 70 k€` carries both words.
 * - **Every figure is examined**, never only the first — first-match-wins is how one figure hides a
 *   readable one three words later.
 * - **A thousands separator is a space on one line.** Text is folded first, which turns U+00A0 into a
 *   space, and the separator class is a literal space, so `réf 850` above `1 450 €` stays two figures.
 * - **An hourly rate is dropped** (N8), and a figure outside a plausibility band for its period is
 *   dropped: `5 € par an` is a typo or a different fact, not a salary to test a floor against.
 *
 * Folded text is quoted in each line's `text`, so a push shows the words the figure came from.
 */
final class JobPay
{
    /** A figure: `45 000`, `1.150` (dot as thousands), `62,5`, `550`. */
    private const string NUM = '(\d{1,3}(?:[ .]\d{3})+|\d+(?:[.,]\d{1,2})?)';

    private const string CURRENCY = '(€|euros?\b|eur\b)';

    /** How far before a figure its anchor word may sit, in bytes of folded text. */
    private const int BEFORE = 60;

    /** How far after a figure its period and basis may sit. */
    private const int AFTER = 40;

    /** Words that make the figure beside them PAY. */
    private const string PAY_ANCHOR = 'salaires?|remunerations?|package|brut|fixe|salary|compensation|tjm|taux journalier|daily rate|tj|retribution';

    /** Words that make the figure beside them NOT pay, whatever period follows it. */
    private const string NOT_PAY_ANCHOR = 'bspce|actions?|stock[- ]options?|primes?|bonus|variables?|interessement|participation|levees?|budgets?|chiffre d\'affaires|ca|tickets?|mutuelle|navigo|rembourse(?:e|ment)?|formation|cagnotte|dotation|equity';

    /** Plausible bounds per period, inclusive. */
    private const array BAND = [
        PayLine::YEAR => [15000, 500000],
        PayLine::MONTH => [1000, 40000],
        PayLine::DAY => [100, 3000],
    ];

    /** @return list<PayLine> */
    public static function read(string $text): array
    {
        if (trim($text) === '') {
            return [];
        }
        try {
            $folded = Text::fold($text);
        } catch (MalformedText) {
            // Unreadable text states nothing. Pay never decides in this direction on an absence.
            return [];
        }

        $pattern = '~(?<![\d.,])(?:(?:entre|de|from)[ ]+)?' . self::NUM . '[ ]*(k)?[ ]*' . self::CURRENCY . '?'
            . '(?:[ ]*(?:-|–|—|\ba\b|\bet\b|\bto\b)[ ]*' . self::NUM . '[ ]*(k)?[ ]*' . self::CURRENCY . '?)?~u';

        if (preg_match_all($pattern, $folded, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL) === false) {
            return [];
        }

        $lines = [];
        foreach ($matches as $m) {
            $line = self::lineFor($folded, $m);
            if ($line !== null) {
                $lines[$line->key()] ??= $line;
            }
        }

        return array_values($lines);
    }

    /** @param array<int, array{0: ?string, 1: int}> $m */
    private static function lineFor(string $folded, array $m): ?PayLine
    {
        [$whole, $at] = $m[0];
        $aNum = $m[1][0];
        $aK = $m[2][0] !== null;
        $aCur = $m[3][0] !== null;
        $bNum = $m[4][0] ?? null;
        $bK = ($m[5][0] ?? null) !== null;
        $bCur = ($m[6][0] ?? null) !== null;

        if ($whole === null || $aNum === null || (!$aCur && !$bCur)) {
            return null;
        }

        $before = self::before($folded, $at);
        $anchor = self::nearestAnchor($before);
        if ($anchor === 'not-pay') {
            return null;
        }

        $end = $at + strlen($whole);
        $after = self::after($folded, $end);

        if (preg_match('~^[^\n]{0,15}?(?:/[ ]*h(?:eure|r)?\b|\bpar heure\b|\bde l\'heure\b|\bhoraires?\b|\bper hour\b|\bhourly\b)~u', $after) === 1) {
            return null; // N8: an hourly rate is ignored, never converted
        }

        $a = self::amount($aNum, $aK);
        $b = $bNum === null ? $a : self::amount($bNum, $bK);
        // `55-65k€`: the k of the upper bound carries to a lower bound that is plainly in thousands.
        if ($bNum !== null && $bK && !$aK && $a < 1000) {
            $a *= 1000;
        }

        $period = self::period($before, $after, $anchor, $whole);
        if ($period === null) {
            return null;
        }

        [$min, $max] = $a <= $b ? [$a, $b] : [$b, $a];
        [$low, $high] = self::BAND[$period];

        if ($period === PayLine::DAY) {
            if (preg_match('~^[^\n]{0,15}?\bttc\b~u', $after) === 1) {
                $min = (int) round($min / 1.2);
                $max = (int) round($max / 1.2);
            }
            if ($min < $low || $max > $high) {
                return null;
            }

            return new PayLine(PayLine::TJM, PayLine::HT, PayLine::DAY, $min, $max, trim($whole . $after));
        }

        if ($min < $low || $max > $high) {
            return null;
        }

        return new PayLine(PayLine::SALARY, self::basis($before, $after), $period, $min, $max, trim($whole . $after));
    }

    private static function amount(string $num, bool $k): int
    {
        $plain = str_replace(' ', '', $num);
        $value = preg_match('~^\d{1,3}(?:\.\d{3})+$~', $plain) === 1
            ? (float) str_replace('.', '', $plain)
            : (float) str_replace(',', '.', $plain);

        return (int) round($k ? $value * 1000 : $value);
    }

    /** The folded text before a figure, back to the start of its sentence or line. */
    private static function before(string $folded, int $at): string
    {
        $from = max(0, $at - self::BEFORE);
        $chunk = substr($folded, $from, $at - $from);
        $cut = max((int) strrpos("\n" . $chunk, "\n"), (int) strrpos('. ' . $chunk, '. '));

        return substr($chunk, max(0, $cut - 1));
    }

    /** The folded text after a figure, up to the end of its sentence or line. */
    private static function after(string $folded, int $end): string
    {
        $chunk = substr($folded, $end, self::AFTER);
        if (preg_match('~\n|\.(?:\s|$)~', $chunk, $cut, PREG_OFFSET_CAPTURE) === 1) {
            $chunk = substr($chunk, 0, $cut[0][1]);
        }

        return $chunk;
    }

    /** `pay`, `not-pay` or null, from the LAST anchor word before the figure. */
    private static function nearestAnchor(string $before): ?string
    {
        $pay = self::lastOffset('~\b(?:' . self::PAY_ANCHOR . ')\b~u', $before);
        $notPay = self::lastOffset('~\b(?:' . self::NOT_PAY_ANCHOR . ')\b~u', $before);

        if ($pay === null && $notPay === null) {
            return null;
        }

        return ($notPay ?? -1) > ($pay ?? -1) ? 'not-pay' : 'pay';
    }

    private static function lastOffset(string $pattern, string $text): ?int
    {
        if (preg_match_all($pattern, $text, $m, PREG_OFFSET_CAPTURE) < 1) {
            return null;
        }

        return $m[0][count($m[0]) - 1][1];
    }

    private static function period(string $before, string $after, ?string $anchor, string $whole): ?string
    {
        if (preg_match('~^[^\n]{0,15}?(?:/[ ]*j(?:our)?\b|\bpar jour\b|/[ ]*day\b|\bper day\b|\bdaily\b)~u', $after) === 1
            || preg_match('~\b(?:tjm|taux journalier|daily rate|tj)\b~u', $before) === 1) {
            return PayLine::DAY;
        }
        if (preg_match('~^[^\n]{0,20}?(?:/[ ]*mois\b|\bpar mois\b|\bmensuel(?:le|s)?\b|\bper month\b|/[ ]*month\b|\bmonthly\b)~u', $after) === 1) {
            return PayLine::MONTH;
        }
        if (preg_match('~^[^\n]{0,20}?(?:/[ ]*an\b|\bpar an(?:nee)?\b|\bannuel(?:le|s)?\b|\bper year\b|/[ ]*(?:year|yr)\b|\byearly\b|\bannual\b|\bsur 1[23] mois\b)~u', $after) === 1) {
            return PayLine::YEAR;
        }
        // No stated period: annual only when a salary word introduced it, since a bare `45 000 €`
        // beside nothing is as likely a budget as a salary.
        if ($anchor === 'pay' && preg_match('~k~', $whole) === 1) {
            return PayLine::YEAR;
        }
        if ($anchor === 'pay' && preg_match('~\b(?:package|salaires?|remunerations?|brut)\b~u', $before) === 1) {
            return PayLine::YEAR;
        }

        return null;
    }

    private static function basis(string $before, string $after): string
    {
        $around = $before . ' ' . $after;
        if (preg_match('~\b(?:package|remuneration (?:globale|totale)|fixe \+ variable|variable (?:inclus|compris)|total compensation|ote)\b~u', $around) === 1) {
            return PayLine::PACKAGE;
        }
        // The word nearest the figure decides: the first of brut/net after it, else the last before it.
        if (preg_match('~\b(brut|net)\b~u', $after, $m) === 1) {
            return $m[1] === 'net' ? PayLine::NET : PayLine::GROSS;
        }
        if (preg_match_all('~\b(brut|net)\b~u', $before, $m) > 0) {
            return $m[1][count($m[1]) - 1] === 'net' ? PayLine::NET : PayLine::GROSS;
        }

        return PayLine::GROSS;
    }
}
