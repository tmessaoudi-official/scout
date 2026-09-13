<?php

declare(strict_types=1);

namespace Scout\Core;

/**
 * Strip leading and trailing whitespace, including the Unicode kind — the one implementation every
 * store's identity check shares.
 *
 * `trim()` strips seven ASCII bytes and nothing else. U+00A0 — which `Text` itself calls a real
 * adapter artefact, and which a decoded `&nbsp;` produces — survives it, so an `externalId` of one
 * no-break space passed the "does this source publish an id?" test and every listing in the run
 * collapsed onto the SAME key. Over-merging hides a listing entirely, and that listing is then
 * indistinguishable from a quiet market.
 *
 * Moved here verbatim from the rent store on 2026-09-13, when the job store became its second
 * reader: a private copy per store is how one of them drifts back to `trim()`.
 */
final class Whitespace
{
    public static function trim(string $value): string
    {
        $trimmed = preg_replace('/^[\p{Z}\p{C}\s]+|[\p{Z}\p{C}\s]+$/u', '', $value);

        // preg_replace returns null on a PCRE failure, and with the `u` flag the demonstrated cause
        // is invalid UTF-8 — the same input `Text::fold()` refuses with MalformedText.
        //
        // The fallback strips the LATIN-1 spaces as well as the ASCII ones, and that is the whole
        // point of it. A Windows-1252 page is the likeliest encoding accident in this domain, and
        // its `&nbsp;` is the single byte `\xA0`: with a plain `trim()` an id of one such byte was
        // non-empty, so it passed the "does this source publish an id?" test and collapsed every
        // listing in the run onto `:id:%A0` — the exact over-merge the docblock above describes.
        // `\x85` (NEL) and `\xAD` (soft hyphen) are the other two that appear in scraped text.
        return $trimmed ?? trim($value, " \t\n\r\0\x0B\x85\xA0\xAD");
    }
}
