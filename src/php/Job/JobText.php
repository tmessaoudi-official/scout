<?php

declare(strict_types=1);

namespace Scout\Job;

use Scout\Core\MalformedText;
use Scout\Core\Text;

/**
 * The job domain's reading surface, and the one negation-first matcher every vocabulary shares.
 *
 * ONE implementation, because the classifier's contract words and the criteria's GREEN, RED and
 * condition terms all need the same rule — `pas de freelance`, `pas d'astreinte`, `sans TDD` — and
 * two copies of a negation window is how one of them drifts a word short.
 */
final class JobText
{
    /** A negation up to two words before a term. */
    public const string NEGATION_BEFORE = '~\b(?:pas|non|hors|sans|aucune?|ni|exclus?)\b[ ]*(?:besoin[ ]+d[\'’](?:etre|avoir)[ ]+|de[ ]+|d[\'’]|en[ ]+)?(?:[^ .\n]+[ ]+){0,2}$~u';

    /** A negation right after a term: `freelance non accepté`, `non requise`, `freelances s'abstenir`. */
    public const string NEGATION_AFTER = '~^[ ]*[(:,]?[ ]*(?:non\b|exclus?\b|refuses?\b|n[\'’]est pas\b|pas (?:possible|accepte|envisage)|(?:[^ .\n]+[ ]+)?s[\'’]abstenir|not accepted|excluded)~u';

    private const int WINDOW = 40;

    /**
     * Folded text with every URL's query and fragment dropped — a tracking token is not ad copy, and
     * `midToken=AQFV…` can contain a run that reads as a stack word. The PATH is kept. Then `_` becomes
     * a space, because it is a WORD character to `\b`: `Forward Deployed Engineer_3202` — a real
     * captured title carrying a requisition number — failed `\bengineer\b` and the role gate rejected
     * it. AFTER the query strip, never before: spaced out first, a token's tail would survive the strip.
     * Throws `MalformedText` on invalid UTF-8: an unreadable text is named by the caller, never read as empty.
     */
    public static function surface(string $raw): string
    {
        return str_replace('_', ' ', (string) preg_replace('~(https?://[^\s?#]+)[?#]\S*~u', '$1', Text::fold($raw)));
    }

    /** Does the pattern occur at least once WITHOUT a negation beside it? Every occurrence is examined. */
    public static function stated(string $folded, string $pattern): bool
    {
        if (preg_match_all($pattern, $folded, $m, PREG_OFFSET_CAPTURE) < 1) {
            return false;
        }
        foreach ($m[0] as [$word, $at]) {
            $from = max(0, $at - self::WINDOW);
            $before = substr($folded, $from, $at - $from);
            $before = (string) preg_replace('~^.*(?:\n|\.[ ])~s', '', $before);
            $after = substr($folded, $at + strlen($word), self::WINDOW);
            if (preg_match(self::NEGATION_BEFORE, $before) !== 1 && preg_match(self::NEGATION_AFTER, $after) !== 1) {
                return true;
            }
        }

        return false;
    }

    /** Is this folded text safe to hand a pattern? Callers that cannot report a breakage use it. */
    public static function trySurface(string $raw): ?string
    {
        try {
            return self::surface($raw);
        } catch (MalformedText) {
            return null;
        }
    }
}
