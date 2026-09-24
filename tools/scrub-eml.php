<?php

declare(strict_types=1);

/**
 * Scrub a captured alert email into a committable fixture.
 *
 * A `.eml` from a real mailbox is a fixture and a personal record at the same time. This removes
 * the second half and keeps the first, because **the structure IS the ground truth**: the awkward
 * `=_?:` MIME boundary, the 8bit UTF-8 transfer encoding, the folded headers and the RFC 2047
 * subject split mid-word are each a parser defect this project has already had. Rewriting the
 * message into something tidy would delete the evidence.
 *
 * What goes, and why each one is identity rather than structure:
 *
 * | Field                              | Carries |
 * |------------------------------------|---------|
 * | `Delivered-To`, `To`, `Cc`, `Received` | the subscriber's address — AND, on `To`/`Cc`, a DISPLAY NAME, which no address check can reach |
 * | `Return-Path`, `Reply-To`          | a per-subscriber bounce/reply token |
 * | `Feedback-ID`, `X-SFMC-*`          | the ESP's subscriber and job ids |
 * | `List-Unsubscribe*`                | a one-click token that unsubscribes without asking |
 * | `DKIM-Signature`, `ARC-*`          | signatures over headers this rewrites — kept would be a lie |
 * | every `qs=` value                  | the click-tracking token, tied to the subscriber |
 * | every JWT-shaped token             | the subscriber's address, base64url-encoded in the payload |
 * | the address in the body            | stated in the CNIL footer of every alert |
 *
 * Usage: `php tools/scrub-eml.php <in.eml> <out.eml> [address-to-mask]`
 *
 * It VERIFIES its own work: the address, the ESP list ids and every original `qs=` token must be
 * absent from the output, or it refuses to write. A scrubber that half-works is worse than none,
 * because its output looks scrubbed.
 *
 * **ABSENT IS THE WRONG TEST — the address must not be RECOVERABLE**, and Bien'ici defeated the old
 * one with no effort at all. Every link in its alerts carries `signedRecipient=eyJhbGciOi…`, a JWT
 * whose payload base64url-decodes to `{"email":"<the subscriber>","iat":…}`. Measured 2026-08-25 on
 * a real capture: the literal address is absent from the decoded body, and one `base64 -d` recovers
 * it in full. This wrote that file and reported `scrubbed`.
 *
 * So the verification decodes before it looks — every long base64url run, the quoted-printable
 * form, and every base64-encoded BODY (which it can only REFUSE, not rewrite) — and refuses when
 * the address surfaces in any of them. That is stated as a general rule
 * rather than as a Bien'ici special case on purpose: the next portal's encoding is not known, and a
 * check that only understands the encodings already seen is the same defect with a later date.
 */

// The cascade is SHARED with the CI guard (`Scout\Core\RecoverableForms`) — one implementation,
// so the two cannot diverge again. The autoloader is the one dependency this tool has.
$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "scrub-eml: vendor/autoload.php introuvable — `composer install` d'abord.\n");
    exit(2);
}
require_once $autoload;

/**
 * Every form the message could be READ BACK in, beyond its own literal text.
 *
 * A token is only opaque until somebody decodes it, so this performs the decode the verification is
 * meant to defeat: each long base64url run, plus the quoted-printable form of the whole message.
 * An undecodable run is skipped rather than guessed at — it carries nothing readable either way.
 *
 * @return list<string>
 */
function recoverableForms(string $message): array
{
    return \Scout\Core\RecoverableForms::of($message);
}

$argvLocal = $_SERVER['argv'] ?? [];

// THE ADDRESS IS REQUIRED, and it used to be optional (R6-5). Omitting it made this tool a SILENT
// NO-OP that reported success: `str_replace($address)` had nothing to replace, the local-part
// fallback had nothing to match, and — the half that matters — the final RECOVERABILITY check had
// no needle to look for, so it passed vacuously. The run printed `scrubbed … 0 named identifier(s)
// replaced` and exited 0 on a file it had barely touched.
//
// That is the worst shape a secrets tool can take: `docs/ALERT-CAPTURE.md` already says to pass the
// address, so the gap only ever caught someone following the shorter of two documented forms, and
// it caught them with a green light. Refusing is the safe direction and costs nothing real — every
// alert this repo captures is addressed to the subscriber, which is the whole reason it needs
// scrubbing. There is deliberately NO opt-out flag: one would be the same trap with an extra step.
if (count($argvLocal) < 4 || trim((string) $argvLocal[3]) === '') {
    fwrite(STDERR, "usage: php tools/scrub-eml.php <in.eml> <out.eml> <address> [needle …]\n");
    fwrite(
        STDERR,
        "\nl'adresse de l'abonné est OBLIGATOIRE : sans elle ce script ne remplace rien et,\n"
            . "surtout, sa vérification finale n'a aucune aiguille à chercher — elle passe à vide et\n"
            . "le fichier est écrit avec un feu vert. Passez aussi vos nom/prénom en aiguilles pour\n"
            . "les portails qui vous saluent par votre nom (voir docs/ALERT-CAPTURE.md).\n",
    );

    exit(2);
}

[$in, $out] = [$argvLocal[1], $argvLocal[2]];
$address = $argvLocal[3];

/**
 * Extra identifiers to mask, named by the operator.
 *
 * A portal greets you by USERNAME, not by address — leboncoin's alert opens `Bonjour tmessaoudi`.
 * That is personal data by any reading of hard rule 7, and no address check can find it: it is not
 * derivable from the address either (`takieddine.messaoudi.official` does not contain `tmessaoudi`).
 * Measured 2026-08-26 on the first real leboncoin capture, where this tool reported
 * `0 tracking tokens and 0 signed tokens replaced` and wrote the username straight through.
 *
 * Each needle is masked AND verified as unrecoverable, exactly like the address — an advisory
 * argument is the shape of a guard that gets quietly dropped.
 *
 * @var list<string> $needles
 */
$needles = array_values(array_filter(
    array_slice($argvLocal, 4),
    static fn (string $a): bool => trim($a) !== '',
));

$raw = @file_get_contents($in);
if ($raw === false) {
    fwrite(STDERR, "cannot read {$in}\n");

    exit(1);
}

// Headers dropped whole, including their folded continuation lines.
// `to` and `cc` JOINED THIS LIST on 2026-08-31 (round-4 panel). The tool APPENDS its own
// `To: <alertes@example.invalid>`, so it always meant to own that header — but it never removed the
// original, and a `To:` carries a DISPLAY NAME as well as an address. `str_replace($local, …)`
// cannot see a display name (it is not the local part) and the `$needles` that would are optional,
// so two committed fixtures shipped the subscriber's real full name in plaintext while the tool
// reported `scrubbed … 0 named identifier(s) replaced` and exited 0. The name is public as the
// commit author; the LINKAGE of that name to a subscription and its criteria is not.
$drop = [
    'to', 'cc',
    'delivered-to', 'received', 'x-received', 'return-path', 'received-spf',
    'authentication-results', 'arc-seal', 'arc-message-signature',
    'arc-authentication-results', 'dkim-signature', 'reply-to', 'feedback-id',
    'list-unsubscribe', 'list-unsubscribe-post', 'x-sfmc-stack', 'x-delivery',
    'x-csa-complaints', 'message-id',
    // BREVO/SENDINBLUE, added 2026-09-04 after a P0. `X-Mailin-EID` is a
    // percent-encoded base64 blob that decodes to
    // `<n>~<subscriber address>~<message-id>~<relay>` in clear text. It survived this
    // scrubber AND `FixtureSecretsTest` into a pushed commit.
    'x-mailin-eid', 'x-sib-id', 'x-mailin-client',
    // MICROSOFT FEEDBACK-LOOP, added 2026-09-05 after a leak caught by `FixtureSecretsTest` one
    // commit before it would have been pushed. `X-MSFBL` is `<hash>|<base64 blob>` and the blob
    // carries the recipient address in clear; it is FOLDED across continuation lines every ~64
    // columns, which is why this tool reported `scrubbed` on it — see recoverableForms().
    'x-msfbl',
    // MAILGUN, added 2026-09-05 (Track 6-B3). `X-Mailgun-Sid` is base64 of the JSON array
    // `["<list id>","<subscriber address>","<subscriber id>"]` — the address in clear one decode
    // away. Measured on three Agorastore captures: the header is the ONLY place the address
    // survives (24 zlib-compressed `/c/eJ…` tracking blobs per message inflate to none of it).
    'x-mailgun-sid',
];

$eol = str_contains($raw, "\r\n") ? "\r\n" : "\n";
$parts = preg_split('~\R\R~', $raw, 2);
$headerBlock = $parts[0] ?? '';
$body = $parts[1] ?? '';

$kept = [];
$dropping = false;

foreach (preg_split('~\R~', $headerBlock) ?: [] as $line) {
    if ($line !== '' && ($line[0] === ' ' || $line[0] === "\t")) {
        // A continuation belongs to whatever header preceded it — dropped or kept, both.
        if (!$dropping) {
            $kept[] = $line;
        }

        continue;
    }

    $name = strtolower(trim(explode(':', $line, 2)[0] ?? ''));
    $dropping = in_array($name, $drop, true);

    if (!$dropping) {
        $kept[] = $line;
    }
}

// A synthetic Message-ID, because the parser has a `messageId()` and a fixture with none tests the
// null path by accident rather than on purpose.
$kept[] = 'Message-ID: <fixture-' . substr(sha1($in), 0, 12) . '@example.invalid>';
$kept[] = 'To: <alertes@example.invalid>';

$message = implode($eol, $kept) . $eol . $eol . $body;

// Tracking tokens: the VALUE goes, the shape stays. A fixture whose links have no query at all
// would not exercise the identity collapse that made `id_from: content` necessary.
$tokenSeq = 0;
$message = preg_replace_callback(
    '~qs=[A-Za-z0-9_\-]+~',
    static function () use (&$tokenSeq): string {
        ++$tokenSeq;

        return 'qs=FIXTURE' . str_pad((string) $tokenSeq, 3, '0', STR_PAD_LEFT);
    },
    $message,
) ?? $message;

// JWT-shaped tokens: same rule as `qs=`, different encoding. The VALUE goes and the three-segment
// SHAPE stays, because a fixture whose links carry no token at all would not exercise the link
// handling it was captured to exercise. The replacement announces itself in plain text so that
// tests/php/Repo/FixtureSecretsTest.php — which refuses a committed JWT — reads it as a placeholder
// rather than as the live credential it is shaped like.
//
// THE PATTERN IS QUOTED-PRINTABLE-AWARE, and it has to be. Most alert mail is QP-encoded, and QP
// folds every line at 76 columns with a trailing `=` soft break — straight through the middle of a
// token. A pattern that only understands unbroken tokens matches nothing on such a capture and
// leaves the address in place. Bien'ici's subscription confirmation is exactly that message: the
// verification below caught it and refused the write, which is the guard working and the tool not.
$jwtSeq = 0;
$softBreak = '(?:=\r?\n)';
$b64 = '(?:[A-Za-z0-9_\-]|' . $softBreak . ')';

// The left anchor has TWO branches, and the second one is not decoration. A JWT sits in a query
// parameter, so it is preceded by `=` — which quoted-printable escapes to `=3D`. A plain `\b` or a
// "not a base64 character" lookbehind then sees the `D` and refuses to start, so the pattern
// matched nothing at all on QP mail and left every address in place. That is how the first version
// of this passed its own unit test (an unencoded fixture) and failed on all three real captures.
$startsToken = '(?:(?<![A-Za-z0-9_\-])|(?<==3D))';
$message = preg_replace_callback(
    '~' . $startsToken . 'eyJ' . $b64 . '{6,}\.' . $b64 . '{6,}\.' . $b64 . '{6,}~',
    static function (array $m) use (&$jwtSeq, $eol): string {
        ++$jwtSeq;

        $payload = rtrim(strtr(base64_encode(
            '{"PLACEHOLDER":"FIXTURE' . str_pad((string) $jwtSeq, 3, '0', STR_PAD_LEFT) . '"}',
        ), '+/', '-_'), '=');

        // The header decodes to {"alg":"none","typ":"JWT"} — true structure, no claim.
        $token = 'eyJhbGciOiJub25lIiwidHlwIjoiSldUIn0.' . $payload . '.PLACEHOLDER0SIGNATURE0FIXTURE';

        // Re-fold if the original was folded. Emitting one long line instead would leave a
        // non-conformant QP line where a conformant one stood, and the structure of the capture is
        // the part this whole tool exists to preserve.
        return str_contains($m[0], '=')
            && preg_match('~=\r?\n~', $m[0]) === 1
            ? '=' . $eol . chunk_split($token, 60, '=' . $eol)
            : $token;
    },
    $message,
) ?? $message;

// Opaque ACCOUNT-SCOPED identifiers: a saved-search UUID, an analytics hex. Neither is an address,
// so every address check passes on them; both identify the subscriber's account to anyone holding
// the file. The VALUE goes and the SHAPE stays, same rule as `qs=` and the JWT — a fixture whose
// UUID had become `xxxx` would stop exercising the link handling it was captured for.
//
// Matched LOOSELY and validated STRICTLY in the callback, because quoted-printable folds lines at
// 76 columns straight through the middle of a 36-character token; a pattern that only understood
// unbroken tokens matched nothing on real mail and left the identifier in place. That exact defect
// is why the JWT pattern above carries the same note.
$unfold = static fn (string $s): string => (string) preg_replace('~=\r?\n~', '', $s);
// A FOLDED ORIGINAL GETS ITS REPLACEMENT ON LINES OF ITS OWN (2026-09-13). The match consumed every soft
// break inside the value, so writing the placeholder inline JOINED the lines around it: a LinkedIn
// link came out on one 335-byte line from a capture whose longest body line was 76. A fold before
// and after the re-chunked placeholder keeps every line at or under the limit, whatever the prefix.
$refold = static fn (string $replacement, string $original): string
    => preg_match('~=\r?\n~', $original) === 1
        ? '=' . $eol . chunk_split($replacement, 60, '=' . $eol)
        : $replacement;

// NAMED PER-RECIPIENT LINK PARAMETERS (2026-09-13, LinkedIn job alerts). Every LinkedIn link carries
// `lipi`, `midToken`, `midSig`, `eid`, `trk`, `trkEmail`, `otpToken`, `trackingId` and `refId`, and
// the "all offers" link a `savedSearchId`. None of them decodes to the address, so the recoverability
// check below has nothing to find, and the tool reported `scrubbed` on a capture whose 41 `otpToken`
// values each tied a link to one member's account. The rule is the one `qs=` follows: the VALUE
// goes, the NAME stays. It is QP-aware on both sides — `=3D` before the value, soft breaks inside
// it — and the replacement is re-folded when the original was. Matched by NAME, and only these
// names: a generic "any long value" rule would also eat the job id the fixture exists to carry.
//
// FOLDS THROUGH THE NAME, TOO. On a real capture a soft break fell through the middle of `otpToken` and
// between `midSig` and its `=3D`, and 20 values survived a pattern that expected unbroken names. An
// encoder never splits an `=3D` escape but folds anywhere else, so the name is matched with an optional
// soft break between every byte, and the separator with one before it. A bare `=` counts as a
// separator only when it is NOT a soft break. The matched name and separator are written back
// untouched, folds included.
$foldable = static fn (string $word): string => implode('(?:=\r?\n)?', array_map(
    static fn (string $byte): string => preg_quote($byte, '~'),
    str_split($word),
));
$linkParams = ['trackingId', 'refId', 'lipi', 'midToken', 'midSig', 'trkEmail', 'trk', 'eid', 'otpToken', 'savedSearchId'];
$message = preg_replace_callback(
    '~(?<![A-Za-z0-9_])(' . implode('|', array_map($foldable, $linkParams)) . ')'
        . '((?:=\r?\n)?(?:=3D|=(?!\r?\n)))((?:[^&"\'<>\s=]|=3D|=\r?\n)+)~',
    static function (array $m) use (&$tokenSeq, $unfold, $refold): string {
        ++$tokenSeq;
        $seq = str_pad((string) $tokenSeq, 3, '0', STR_PAD_LEFT);
        $flat = $unfold($m[3]);
        // A numeric id stays numeric, so a reader expecting digits still meets digits.
        $placeholder = ctype_digit($flat)
            ? str_pad($seq, max(strlen($flat), strlen($seq)), '0', STR_PAD_LEFT)
            : 'FIXTURE' . $seq;

        return $m[1] . $m[2] . $refold($placeholder, $m[3]);
    },
    $message,
) ?? $message;

// MAILJET CLICK LINKS (2026-09-24, Free-Work job alerts). `tx.mjt.lu/lnk/<recipient token>/<n>/<hash>/
// <base64url of the target URL>` — the whole PATH is per recipient, and its last segment decodes to the
// destination. Free-Work's destinations are the signed per-account unsubscribe links, so after the text
// part's literal was replaced the account's alert ids and a WORKING signature were one decode away, and
// the recoverability check below refused the capture. The host stays, so the link still reads as a
// tracking redirect; the path goes. Nothing reads these links: the adapter keys on the text part's own
// free-work.com URLs. QP-aware, because the HTML part folds straight through the path.
$message = preg_replace_callback(
    '~(tx\.mjt\.lu/lnk/)((?:[A-Za-z0-9_\-/]|=\r?\n)+)~',
    static function (array $m) use (&$tokenSeq, $refold): string {
        ++$tokenSeq;

        return $m[1] . $refold('FIXTURE' . str_pad((string) $tokenSeq, 3, '0', STR_PAD_LEFT), $m[2]);
    },
    $message,
) ?? $message;

$uuidSeq = 0;
$message = preg_replace_callback(
    '~(?<![0-9a-fA-F-])(?:[0-9a-fA-F-]|=\r?\n){36,60}(?![0-9a-fA-F-])~',
    static function (array $m) use (&$uuidSeq, $unfold, $refold): string {
        $flat = $unfold($m[0]);
        if (preg_match('~^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$~i', $flat) !== 1) {
            return $m[0];   // not a UUID after all — the loose match earns nothing here
        }
        ++$uuidSeq;
        $n = str_pad((string) $uuidSeq, 4, '0', STR_PAD_LEFT);

        return $refold('ffffffff-0000-4000-8000-00000000' . $n, $m[0]);
    },
    $message,
) ?? $message;

$hexSeq = 0;
/**
 * ONE PLACEHOLDER PER DISTINCT VALUE, and this map is why a scrubbed multipart message still
 * parses (2026-09-05, Track 6-B3). Mailgun writes a 60-hex MIME boundary; it occurs four times —
 * the `boundary=` parameter and three delimiter lines — and a per-OCCURRENCE sequence turned them
 * into four different strings, so `EmailMessage::parse()` found no part at all: body 0 bytes,
 * links 0, on a file the tool reported `scrubbed`. Every earlier fixture had a non-hex boundary,
 * which is the only reason this never showed. Same value in, same placeholder out.
 *
 * @var array<string, string> $hexMap
 */
$hexMap = [];
$message = preg_replace_callback(
    // `(?<!=)` IS LOAD-BEARING, and its absence corrupted every link it touched (round-5 panel,
    // 2026-08-31). A quoted-printable escape is `=3D`, and `3` and `D` are HEX — so the run happily
    // started at the `3D`, swallowed it, and emitted `=f0f0f0…` in its place. That is itself a valid
    // QP escape (`=f0` means byte 0xF0), so the URL decoded to `fromSavedSearchId<0xF0>f0f0…` and the
    // listing link was destroyed. It also joined the two lines the escape had spanned, leaving the
    // 152-column line in the ParuVendu fixture that a reviewer flagged.
    //
    // Not starting after `=` leaves the escape intact and matches the value on its far side, which
    // is what was meant all along: `fromSavedSearchId=3Df0f0f0…` decodes to `…=f0f0f0…`.
    // Two lookbehinds, and each one alone is wrong. `(?<!=)` stops the run beginning ON the escape;
    // the alternation is what lets it begin immediately AFTER one — without it the escape's own `D`
    // is a hex character, so the ordinary `(?<![0-9a-fA-F])` blocked the real value from starting
    // and the analytics hex went unscrubbed entirely. Measured both ways against the suite.
    '~(?:(?<![0-9a-fA-F])|(?<==[0-9a-fA-F]{2}))(?<!=)(?:[0-9a-fA-F]|=\r?\n){24,}(?![0-9a-fA-F])~',
    static function (array $m) use (&$hexSeq, &$hexMap, $unfold, $refold): string {
        $flat = $unfold($m[0]);
        if (preg_match('~^[0-9a-fA-F]{24,}$~', $flat) !== 1) {
            return $m[0];
        }
        $key = strtolower($flat);
        if (!isset($hexMap[$key])) {
            ++$hexSeq;
            $hexMap[$key] = str_pad(substr('f0f0f0f0', 0, 8) . $hexSeq, strlen($flat), '0');
        }

        return $refold($hexMap[$key], $m[0]);
    },
    $message,
) ?? $message;

// THE ADDRESS GOES FIRST, AND THE ORDER IS THE WHOLE POINT (round-6 panel, 2026-08-31).
//
// It used to run needles first, and that turned this tool's own documented procedure into a leak.
// A needle is typically the subscriber's NAME, and the name is usually IN the address — so
// `Takieddine` + `MESSAOUDI` rewrote `takieddine.messaoudi.official@gmail.com` into
// `abonne.abonne.official@gmail.com` BEFORE the address replacement ran. `str_replace($address)`
// then matched nothing, the local-part fallback matched nothing, and the final verification
// (`stripos($message, $address)`) matched nothing either — so the tool wrote the file and exited 0
// while the remainder, `.official@gmail.com`, sat beside the commit author on the same commit and
// reconstructs the address verbatim. Two fixtures shipped exactly that, six times each, hours after
// `docs/ALERT-CAPTURE.md` started telling operators to pass the name.
//
// Replacing the address first leaves the needles nothing of it to damage: what remains for them is
// the greeting and the display name, which is what they are for.
if ($address !== null && $address !== '') {
    $message = str_replace($address, 'alertes@example.invalid', $message);
    // The local part alone appears in some ESP ids.
    $local = explode('@', $address)[0];
    if ($local !== '') {
        $message = str_replace($local, 'alertes', $message);
    }
}

// THE FOOTER SENTENCE THAT NAMES THE SUBSCRIBER, AND THEIR PROFILE HEADLINE WITH IT (2026-09-13).
// LinkedIn ends every job alert, in both parts, with `Cet e-mail est destiné à <name> (<headline>)`.
// The headline is the member's own profile line — `Lead Developer | Senior Fullstack | PHP · Symfony
// · …` — and needles cannot remove it. Its `·` is `=C2=B7` in quoted-printable, so a byte-literal
// needle never matches across it. Its generic half is also a JOB TITLE that must survive in the cards
// above: a card in the same capture is titled `Lead Developer`. So the rule is scoped to that one
// sentence: the name and the whole parenthetical become `abonne`, and nothing outside the sentence is
// touched. Byte-wise and fold-aware for the needle loop's reason. Measured on the real captures: the
// anchor bytes are `destin=C3=A9 =C3=A0 ` in every one. A folded original is re-folded by `$refold`.
$qpByte = static fn (string $utf8): string => '(?:' . preg_quote($utf8, '~') . '|'
    . implode('', array_map(static fn (string $b): string => '=' . strtoupper(bin2hex($b)), str_split($utf8))) . ')';
$fold = '(?:=\r?\n)?';
$gap = $fold . '(?:\x20|\t|=20|=C2=A0|\xC2\xA0)' . $fold;
$message = preg_replace_callback(
    '~(' . $foldable('destin') . $fold . $qpByte('é') . $gap . $qpByte('à') . $gap . ')'
        . '((?:[^()\r\n]|=\r?\n){1,160}?\((?:[^()\r\n]|=\r?\n){1,600}\))~i',
    static fn (array $m): string => $m[1] . $refold('abonne', $m[2]),
    $message,
) ?? $message;

foreach ($needles as $needle) {
    // Case-insensitively, because a portal's greeting capitalises what its account record does not.
    //
    // AND THROUGH A QUOTED-PRINTABLE SOFT BREAK (2026-09-13). LinkedIn's job alert names the
    // subscriber in its footer, and in the QP-encoded HTML part one occurrence is folded mid-name
    // (`Jea=\nnne`). The literal replace missed it, the recoverability check below found it, and the
    // tool refused four captures of twenty. The refusal was correct; the miss was the defect. Each
    // fold is put BACK at the same offset in the replacement, so the scrub cannot lengthen a line
    // or erase the soft-break shape the parser has to keep meeting (the `$refold` discipline).
    // Bytes, not characters: a QP body is ASCII, and a `u` pattern over an 8bit Latin-1 body fails
    // to compile its subject and returns null.
    $pattern = '~' . implode('(?:=\r?\n)?', array_map(
        static fn (string $byte): string => preg_quote($byte, '~'),
        str_split($needle),
    )) . '~i';
    $message = preg_replace_callback(
        $pattern,
        static function (array $m): string {
            $segments = preg_split('~(=\r?\n)~', $m[0], -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$m[0]];
            $replacement = 'abonne';
            $out = '';
            $taken = 0;
            foreach ($segments as $i => $segment) {
                if ($i % 2 === 1) {
                    $out .= $segment;     // the fold itself, byte for byte (`=\n` or `=\r\n`)

                    continue;
                }
                $last = $i === count($segments) - 1;
                $length = $last ? strlen($replacement) - $taken : min(strlen($segment), strlen($replacement) - $taken);
                $out .= substr($replacement, $taken, max(0, $length));
                $taken += max(0, $length);
            }

            return $out;
        },
        $message,
    ) ?? $message;
}

// The ESP's list/subscriber ids, which survive in bounce addresses and campaign strings.
$message = preg_replace('~\b51000[0-9]\b~', '510000', $message) ?? $message;

// A TOKEN WRAPPED IN AN OUTER BASE64 LAYER (round-5 panel, 2026-08-31). Bien'ici wraps its links in
// one, so the literal `eyJ` the JWT replacer above anchors on never appears in the message at all,
// and three committed, PUSHED fixtures carried the subscriber's address one `base64 -d | base64 -d`
// away while this tool and `FixtureSecretsTest` both reported clean. Detecting it is not enough:
// without a way to STRIP it the refusal is unresolvable and no clean fixture of this portal could
// ever be produced.
//
// **LAST, AND THAT IS THE WHOLE TRICK.** Two earlier attempts put this above the UUID and hex
// replacers and broke the fixtures: a re-encoded base64 blob is full of hex-looking runs, so the
// hex replacer then rewrote the middle of it and the link decoded to rubbish. Running after every
// other replacement means nothing downstream touches the blob — measured, the parser reads back the
// same 61 links and a byte-identical body.
//
// The decoded blob is a whole URL, so only the JWT INSIDE it is rewritten and the rest is
// re-encoded unchanged: the link keeps its host, its path and its query, which is the identity
// Bien'ici is keyed on. A run is touched only when it decodes AND carries a JWT, so an ordinary long
// token is left alone.
$wrappedSeq = 0;
$message = preg_replace_callback(
    '~(?<![A-Za-z0-9_\-])(?:[A-Za-z0-9_\-]|=\r?\n){80,}(?![A-Za-z0-9_\-])~',
    static function (array $m) use (&$wrappedSeq, $unfold, $refold): string {
        $flat = $unfold($m[0]);
        $decoded = base64_decode(strtr($flat . str_repeat('=', (4 - strlen($flat) % 4) % 4), '-_', '+/'), true);

        if ($decoded === false || $decoded === '' || !str_contains($decoded, 'eyJ')) {
            return $m[0];
        }

        $clean = (string) preg_replace(
            '~eyJ[A-Za-z0-9_\-]{6,}\.[A-Za-z0-9_\-]{6,}\.[A-Za-z0-9_\-]{6,}~',
            'eyJhbGciOiJub25lIiwidHlwIjoiSldUIn0.eyJQTEFDRUhPTERFUiI6IkZJWFRVUkUifQ.PLACEHOLDER0SIGNATURE0FIXTURE',
            $decoded,
        );

        if ($clean === $decoded) {
            return $m[0];
        }

        ++$wrappedSeq;

        return $refold(rtrim(strtr(base64_encode($clean), '+/', '-_'), '='), $m[0]);
    },
    $message,
) ?? $message;

// ---- verification. Refuse rather than write something that looks scrubbed and is not.
$leaks = [];

if ($address !== null && $address !== '' && stripos($message, $address) !== false) {
    $leaks[] = 'the address is still present';
}

if ($address !== null && $address !== '') {
    foreach (recoverableForms($message) as $form) {
        if (stripos($form, $address) !== false) {
            $leaks[] = 'the address is RECOVERABLE from the output — it survives ENCODED (a base64url '
                . 'token, the quoted-printable form, or a base64-encoded BODY, which this tool cannot '
                . 'rewrite without re-encoding it). Teach it that token, or capture the message in a '
                . 'form it can scrub; do not relax this check.';

            break;
        }
    }

    // The local part alone is enough to identify the subscriber, and it is what ESP ids embed. Only
    // checked when it is long enough to be distinctive: a short local part matched against decoded
    // binary would fire on noise, and a guard that cries wolf gets weakened and then deleted.
    $local = explode('@', $address)[0];

    if (strlen($local) >= 6) {
        foreach (recoverableForms($message) as $form) {
            if (stripos($form, $local) !== false) {
                $leaks[] = 'the local part of the address is RECOVERABLE from the output — it '
                    . 'survives ENCODED inside a token this scrubber does not know how to strip.';

                break;
            }
        }
    }
}

foreach (['510006', '510008'] as $listId) {
    if (str_contains($message, $listId)) {
        $leaks[] = 'ESP list id ' . $listId . ' is still present';
    }
}

preg_match_all('~qs=([A-Za-z0-9_\-]+)~', $message, $remaining);
foreach ($remaining[1] as $token) {
    if (!str_starts_with($token, 'FIXTURE')) {
        $leaks[] = 'an original tracking token survives: ' . substr($token, 0, 12) . '…';
    }
}

// Each named identifier, held to the SAME standard as the address: gone from the text, and not
// recoverable from any encoding of it either. Anything weaker makes the argument advisory, and an
// advisory guard is one somebody drops the first time it is inconvenient.
foreach ($needles as $needle) {
    if (stripos($message, $needle) !== false) {
        $leaks[] = 'the identifier `' . $needle . '` is still present';

        continue;
    }

    foreach (recoverableForms($message) as $form) {
        if (stripos($form, $needle) !== false) {
            $leaks[] = 'the identifier `' . $needle . '` is RECOVERABLE from the output — it '
                . 'survives ENCODED inside a token this scrubber does not know how to strip. Teach '
                . 'it that token, or drop the parameter; do not relax this check.';

            break;
        }
    }
}

if ($leaks !== []) {
    fwrite(STDERR, "REFUSING to write — scrub incomplete:\n  - " . implode("\n  - ", array_unique($leaks)) . "\n");

    exit(1);
}

if (file_put_contents($out, $message) === false) {
    fwrite(STDERR, "cannot write {$out}\n");

    exit(1);
}

fwrite(STDOUT, sprintf(
    "scrubbed %s -> %s (%d bytes, %d tracking tokens, %d signed tokens, %d UUIDs, %d opaque hexes"
    . " and %d named identifier(s) replaced)\n",
    basename($in),
    $out,
    strlen($message),
    $tokenSeq,
    $jwtSeq,
    $uuidSeq,
    $hexSeq,
    count($needles),
));
