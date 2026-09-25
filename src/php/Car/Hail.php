<?php

declare(strict_types=1);

namespace Scout\Car;

use Scout\Core\MalformedText;
use Scout\Core\Text;

/**
 * Does a car's own text say it is hail-damaged?
 *
 * Developer ruling 2026-09-25: hail is a SCORE PENALTY, never a reject — a hail-damaged car is still
 * a car one might buy cheaply, so it stays a match and ranks low. First met on Alcopa, whose lot
 * comments read *« Véhicule grêlé - lunette arriere cassé »* and one of whose sales is titled
 * *« véhicules récents et grêlés »*; the live car store held 0 mentions in 2 570 snapshots before it.
 *
 * NEGATION IS READ FIRST, as in `VehicleClassifier`: *non grêlé*, *sans grêle*, *aucune trace de
 * grêle* are what an honest ad says, and a bare match would penalise exactly the cars that are fine.
 * One un-negated mention is enough; a negated one never counts.
 */
final class Hail
{
    /** Folded: grêle, grêlé, grêlée, grêlés, grêlées. */
    private const string MENTION = '~\bgrel(?:e|ee|es|ees)\b~u';

    /** Up to two words between the negation and the mention: « aucune trace de grêle ». */
    private const string NEGATED = '~(?:\bnon|\bsans|\bpas\s+de|\baucune?|\bjamais|\bni)[\s-]+(?:\w+[\s-]+){0,2}$~u';

    public static function stated(string $text): bool
    {
        try {
            $folded = Text::fold($text);
        } catch (MalformedText) {
            return false;
        }
        if (preg_match_all(self::MENTION, $folded, $m, PREG_OFFSET_CAPTURE) === 0) {
            return false;
        }
        foreach ($m[0] as [, $offset]) {
            $before = substr($folded, max(0, $offset - 40), min(40, $offset));
            if (preg_match(self::NEGATED, $before) !== 1) {
                return true;
            }
        }

        return false;
    }
}
