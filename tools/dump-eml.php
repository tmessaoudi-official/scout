<?php

declare(strict_types=1);

/**
 * Dump raw `.eml` files from the alert mailbox, so a capture does not depend on a browser.
 *
 * `docs/ALERT-CAPTURE.md` Part A is the manual route — Gmail's *Download original* — and it stays
 * the documented one, because it is the only route that works for a mailbox this tool cannot reach.
 * This exists for the case that route makes expensive: several messages from one sender, needed at
 * once, to build a source against.
 *
 * IT IS READ-ONLY AT THE PROTOCOL LEVEL. `EXAMINE`, never `SELECT`, and `BODY.PEEK[]`, never
 * `BODY[]` — the first keeps the folder read-only, the second is what stops a fetch marking the
 * developer's mail as read. Both are the same choice `ImapMailbox` makes and for the same reason:
 * no defect on this side may modify a real mailbox.
 *
 * The output is RAW and therefore UNSCRUBBED — it carries the subscriber's address, and usually
 * their name. It writes to `var/claude/captures/` (gitignored) and REFUSES to write anywhere under
 * `tests/`, because the one-step path from a mailbox to a committed fixture is exactly how the
 * committed-then-scrubbed incidents this repo has already had would happen again. Run `tools/scrub-eml.php` on the result.
 *
 * Usage:
 *   php tools/dump-eml.php <from-address> [max] [out-dir] [folder]
 *   php tools/dump-eml.php no.reply@leboncoin.fr 5
 *   php tools/dump-eml.php support@agorastore.fr 2 var/claude/captures 'car-watch/portails'
 *   DUMP_SINCE_DAYS=all php tools/dump-eml.php ops@collective.work 50   # a history pull
 *
 * WHAT COUNTS AS RECENT is `DUMP_SINCE_DAYS` (default 7, the watcher's own window): the server's
 * `SEARCH SINCE`, never the tail of the folder — see the window block below for why.
 *
 * The FOLDER matters and defaults to INBOX: an alert routed to a Gmail label has been archived out
 * of the inbox, so a search there finds nothing and says `aucun message` — which reads exactly like
 * a portal that has sent nothing. `IMAP_MAILBOX` / `CAR_IMAP_MAILBOX` in `.env` name the folders
 * the sources themselves read.
 */

require __DIR__ . '/../vendor/autoload.php';

use Scout\Adapters\Mail\ImapMailbox;
use Scout\Config\DotEnv;

$from = $argv[1] ?? '';
$max = (int) ($argv[2] ?? 10);
$outDir = $argv[3] ?? __DIR__ . '/../var/claude/captures';
$folder = $argv[4] ?? 'INBOX';

if ($from === '') {
    fwrite(STDERR, "usage: php tools/dump-eml.php <from-address> [max] [out-dir] [folder]\n");
    exit(2);
}

// THE WINDOW IS THE SERVER'S DATE, NEVER THE SEQUENCE TAIL. This tool used to `SEARCH FROM` and keep
// the last N sequence numbers; in a re-labelled Gmail folder sequence order is not date order, and
// `ops@collective.work` came back 2025-09 → 2026-02 with nothing from this year. The watcher learnt
// the same lesson on 2026-08-25, so the query is ITS query: `ImapMailbox::searchCommand()`.
// Seven days by default, the watcher's own window; `all` for a deliberate history pull. Anything else
// is refused rather than clamped — an operator who typed `0` meant something, and guessing is worse.
$sinceDays = getenv('DUMP_SINCE_DAYS');
$sinceDays = $sinceDays === false || $sinceDays === '' ? '7' : $sinceDays;
if ($sinceDays !== 'all' && preg_match('~^[1-9][0-9]*$~', $sinceDays) !== 1) {
    fwrite(STDERR, "refus : DUMP_SINCE_DAYS doit être un nombre de jours ≥ 1, ou « all » (reçu : « $sinceDays »)\n");
    exit(2);
}

// `UID SEARCH` → `SEARCH`: the watcher needs UIDs because it marks \Seen in a SECOND session; this
// tool reads in ONE `EXAMINE` session and FETCHes by sequence number, so it must search by sequence
// too — a UID fetched as a sequence number dumps a different, plausible-looking message.
$search = (string) preg_replace('~^UID SEARCH ~', 'SEARCH ', ImapMailbox::searchCommand(
    $from,
    $sinceDays === 'all' ? 1 : (int) $sinceDays,
    new DateTimeImmutable('now', new DateTimeZone('UTC')),
));
if ($sinceDays === 'all') {
    $search = (string) preg_replace('~^SEARCH SINCE \S+ ~', 'SEARCH ', $search);
}
echo "recherche : $search\n";

/**
 * The absolute, canonical path an out-dir NAMES, whether or not it exists yet.
 *
 * `realpath()` answers `false` for a path that does not exist, and the first version of the guard
 * below concatenated that `false` into a string — so `var/claude/captures` on a tree where
 * `var/claude` has not been created evaluated as the literal `/captures`, and the check ran against
 * a garbage path. That is the DEFAULT out-dir, so the guard was vacuous by default: the "passes
 * vacuously" shape `scrub-eml.php` was fixed for a few commits earlier, repeated here.
 *
 * So walk UP to the deepest ancestor that does exist, canonicalise that, and re-append the tail.
 * The walk terminates at `/`, which always resolves, so a fail-open is not reachable — and `..`
 * and `.` are folded first, because a path may point back inside `tests/` through either.
 */
$resolveIntended = static function (string $path): ?string {
    if ($path === '') {
        return null;
    }

    $absolute = str_starts_with($path, '/') ? $path : (string) getcwd() . '/' . $path;

    $parts = [];
    foreach (explode('/', $absolute) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            array_pop($parts);
            continue;
        }
        $parts[] = $segment;
    }

    $tail = [];
    while ($parts !== []) {
        $real = realpath('/' . implode('/', $parts));
        if ($real !== false) {
            return $tail === []
                ? rtrim($real, '/')
                : rtrim($real, '/') . '/' . implode('/', array_reverse($tail));
        }
        $tail[] = array_pop($parts);
    }

    return null;
};

// Never into the fixture tree: a raw dump is unscrubbed by definition — it carries the subscriber's
// address and usually their name, and the one-step path from a mailbox to a committed fixture is
// exactly how the committed-then-scrubbed incidents this repo has already had would happen again. Compared as a PREFIX with
// its separator, so the bare `tests` and `tests/` — which resolve with no trailing slash and slipped
// straight through a `str_contains('/tests/')` check — are refused like any path beneath it.
$intendedOutDir = $resolveIntended($outDir);
$testsRoot = realpath(__DIR__ . '/../tests');

if ($intendedOutDir === null || $testsRoot === false) {
    fwrite(STDERR, "refus : impossible de résoudre le dossier de sortie — refus par défaut.\n");
    exit(2);
}

if ($intendedOutDir === $testsRoot || str_starts_with($intendedOutDir, $testsRoot . '/')) {
    fwrite(STDERR, "refus : une capture brute n'est pas scrubée — elle ne va jamais sous tests/.\n");
    exit(2);
}

$outDir = $intendedOutDir;

DotEnv::load(__DIR__ . '/../.env');

$host = getenv('IMAP_HOST') ?: '';
$user = getenv('IMAP_USER') ?: '';
$pass = getenv('IMAP_PASSWORD') ?: '';
$port = (int) (getenv('IMAP_PORT') ?: 993);

if ($host === '' || $user === '' || $pass === '') {
    fwrite(STDERR, "IMAP_HOST / IMAP_USER / IMAP_PASSWORD manquants dans .env\n");
    exit(2);
}

@mkdir($outDir, 0o755, true);

$sock = @stream_socket_client("ssl://$host:$port", $errno, $errstr, 20);
if ($sock === false) {
    fwrite(STDERR, "connexion impossible : $errstr\n");
    exit(1);
}
stream_set_timeout($sock, 30);

$tag = 0;

/**
 * Read one tagged response. Takes the TAG, never the command — see `$login`.
 *
 * @return list<string>
 */
$readTagged = static function (string $t) use ($sock): array {
    $out = [];
    while (($l = fgets($sock, 65536)) !== false) {
        $out[] = rtrim($l, "\r\n");
        if (str_starts_with($l, "$t ")) {
            if (!preg_match('~^\S+\s+OK~i', $l)) {
                // LOUD, never an empty list — hard rule 3. A failed FETCH that returned `[]` would
                // read as "the sender has sent nothing", which is the one conclusion this whole
                // repo exists to make impossible.
                throw new RuntimeException('IMAP a refusé : ' . rtrim($l));
            }
            break;
        }
    }

    return $out;
};

/** @return list<string> */
$cmd = static function (string $line) use ($sock, &$tag, $readTagged): array {
    $t = 'a' . ++$tag;
    fwrite($sock, "$t $line\r\n");

    return $readTagged($t);
};

/**
 * LOGIN takes NO ARGUMENT, and that is the whole reason it is not `$cmd('LOGIN …')`.
 *
 * PHP ships `zend.exception_ignore_args = Off` and `zend.exception_string_param_max_len = 15`, so
 * an uncaught trace prints the first 15 characters of every call argument. Passing the LOGIN line
 * to `$cmd` puts `LOGIN "<user>" "<password>"` in that position, and the password is inside the
 * budget as soon as the username is short. Nothing leaked today only because the real `IMAP_USER`
 * is long enough to consume the 15 characters first — luck, not a guard, and this repo's own
 * vocabulary for that.
 *
 * A closure's `use` bindings are not call arguments and do not appear in a trace, so reading the
 * credentials from the enclosing scope removes the exposure rather than masking it afterwards.
 */
$login = static function () use ($sock, &$tag, $readTagged, $user, $pass): void {
    $t = 'a' . ++$tag;
    $line = "$t LOGIN \"" . addcslashes($user, '"\\') . '" "' . addcslashes($pass, '"\\') . "\"\r\n";

    // THE WRITE ITSELF IS A FRAME, and this was the ONE surface of three left at level 1 — the
    // production classes were wrapped and the tool that taught them the lesson was not (C2 round 3).
    // Keeping the credential out of the closure's parameter list leaves it in `fwrite`'s, and a
    // trace prints built-in frames too; `@` suppresses warnings and does nothing to the `TypeError`
    // a closed stream raises. The original is DISCARDED rather than chained, because a `previous`
    // carries the trace being escaped.
    try {
        $written = @fwrite($sock, $line);
    } catch (\Throwable) {
        $written = false;
    }

    if ($written === false) {
        fwrite(STDERR, "connexion IMAP : écriture impossible pendant LOGIN\n");
        exit(1);
    }

    $readTagged($t);
};

fgets($sock, 65536); // greeting
$login();
$cmd('EXAMINE "' . addcslashes($folder, '"\\') . '"');

$searched = $cmd($search);
$ids = [];
foreach ($searched as $line) {
    if (preg_match('~^\*\s+SEARCH\s+(.*)$~i', $line, $m) === 1) {
        $ids = array_values(array_filter(array_map('intval', preg_split('~\s+~', trim($m[1])) ?: [])));
    }
}

if ($ids === []) {
    // NAMES THE FOLDER, because "no message" and "wrong folder" are the same output otherwise —
    // and an alert routed to a label is archived out of INBOX, which is the common case here.
    fwrite(STDERR, "aucun message de $from dans le dossier \"$folder\"\n");
    exit(1);
}

// Newest by SEQUENCE within the date window — not by date. Inside a window already narrowed by
// SINCE and FROM that is the best ordering available without fetching every match's `Date`, the
// same trade `ImapMailbox::sequencesIn()` documents.
$ids = array_slice($ids, -$max);
echo count($ids), " message(s) de $from dans \"$folder\"\n";

foreach ($ids as $id) {
    // BODY.PEEK[] — the whole message, WITHOUT setting \Seen.
    fwrite($sock, 'b' . ++$tag . " FETCH $id BODY.PEEK[]\r\n");

    $header = fgets($sock, 65536);
    if ($header === false || preg_match('~\{(\d+)\}~', $header, $m) !== 1) {
        fwrite(STDERR, "  #$id : réponse FETCH inattendue, ignoré\n");
        continue;
    }

    $need = (int) $m[1];
    $raw = '';
    while (strlen($raw) < $need) {
        $chunk = fread($sock, min(65536, $need - strlen($raw)));
        if ($chunk === false || $chunk === '') {
            break;
        }
        $raw .= $chunk;
    }
    while (($l = fgets($sock, 65536)) !== false) {
        if (preg_match('~^b\d+\s~', $l) === 1) {
            break;
        }
    }

    $date = 'inconnue';
    if (preg_match('~^Date:\s*(.+)$~mi', $raw, $d) === 1) {
        $ts = strtotime(trim($d[1]));
        $date = $ts === false ? 'inconnue' : date('Y-m-d', $ts);
    }

    // A SHORT READ IS NOT A CAPTURE (C2 round 2, 2026-09-04). The loop above `break`s on a failed or
    // empty `fread`, and the write then happened regardless with `file_put_contents`'s return value
    // unchecked — so a truncated `.eml` was written and reported as a normal capture. That matters
    // more here than a lost byte count would elsewhere: a truncated alert still PARSES, it simply
    // carries fewer cards, so it becomes a fixture that quietly under-represents the payload and
    // every assertion written against it is measured on the wrong ground truth.
    if (strlen($raw) !== $need) {
        fwrite(STDERR, sprintf("  #%d : lecture tronquée (%d octets sur %d) — non écrit\n", $id, strlen($raw), $need));
        continue;
    }

    $path = rtrim($outDir, '/') . "/$date-imap-$id.eml";

    if (@file_put_contents($path, $raw) !== strlen($raw)) {
        fwrite(STDERR, "  #$id : écriture incomplète ou impossible — $path\n");
        continue;
    }

    printf("  #%-6d %8d octets  %s\n", $id, strlen($raw), $path);
}

$cmd('LOGOUT');
fclose($sock);

echo "\nCes fichiers sont BRUTS et non scrubés. Passez-les par tools/scrub-eml.php avant tout commit.\n";
