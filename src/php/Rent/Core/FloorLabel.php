<?php

declare(strict_types=1);

namespace Scout\Rent\Core;

/**
 * The one name a floor has, wherever a listing is described.
 *
 * The score's `reasons[]` (`CriteriaEngine`) and the notification's context line (`Formatter`)
 * each used to build this label, and they disagreed below the ground floor: `-1er étage` on one
 * surface and `RDC` on the other, for the same flat (architecture review A-14, 2026-10-08).
 * Hard rule 9 still governs the input: `0` is the rez-de-chaussée and real, and a `null` floor
 * never reaches this class, because an unknown floor has no label at all.
 */
final class FloorLabel
{
    /** The long form, for a sentence: `rez-de-chaussée`, `1er étage`, `4e étage`, `sous-sol`. */
    public static function long(int $floor): string
    {
        return $floor === 0 ? 'rez-de-chaussée' : self::label($floor);
    }

    /** The short form, for the compact context line: `RDC` instead of `rez-de-chaussée`. */
    public static function short(int $floor): string
    {
        return $floor === 0 ? 'RDC' : self::label($floor);
    }

    private static function label(int $floor): string
    {
        return match (true) {
            $floor < 0 => 'sous-sol',
            $floor === 1 => '1er étage',
            default => $floor . 'e étage',
        };
    }
}
