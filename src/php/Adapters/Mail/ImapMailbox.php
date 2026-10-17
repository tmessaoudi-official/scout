<?php

declare(strict_types=1);

namespace Scout\Adapters\Mail;

use Scout\Core\Clock;
use Scout\Core\MutableByDesign;
use Scout\Core\SystemClock;

/**
 * IMAP over TLS, hand-rolled on stream sockets.
 *
 * **Hand-rolled because there is no choice, not because it is a good idea.** `ext-imap` is absent
 * from this container and PHP unbundled it in 8.4; Composer cannot install a library here at all
 * (the egress policy 403s Composer's dist source, which is why this project has zero dependencies).
 * `stream_socket_client` with `tls://` is what is left. The surface is kept as small as the job
 * allows — enough to log in, select a mailbox, list recent messages and read them — because every
 * line of a hand-written protocol client is a line nobody else has reviewed.
 *
 * **A FETCH IS READ-ONLY, and enforced rather than intended.** `EXAMINE`, not `SELECT`, so the
 * server itself refuses any modification while messages are being read; `BODY.PEEK[]`, never
 * `BODY[]`, so reading sets no flag as a side effect. The mailbox is the developer's, it is where
 * the alerts land, and a parser bug must not be able to cost them.
 *
 * **THE ONE WRITE IS {@see acknowledge()}, and it can only ADD `\Seen`** (row 36, 2026-09-04 —
 * developer request: *"mark the emails … as seen when you process them, so that way I know which
 * email was processed and which not"*). It runs in a SECOND session — `SELECT`, one
 * `UID STORE … +FLAGS.SILENT (\Seen)`, `LOGOUT` — on exactly the UIDs a source CLAIMED during the
 * last fetch and that do not already carry the flag, so steady state opens no write session at all.
 * Messages are addressed by UID throughout precisely because there are two sessions: a sequence
 * number is only meaningful inside one, and the folder's `UIDVALIDITY` is compared across the two
 * so a re-created folder cannot be flagged by a stale UID. Nothing here can ever remove a flag,
 * move or delete a message, and the fetch's query stays date-based — the flag is for the human
 * reading the label, never the pipeline's own dedup.
 *
 * `$connector` is the test seam that makes any of this observable on the wire: it replaces the
 * `tls://` socket with one a scripted loopback server answers. It is consulted AFTER the offline
 * refusal, never before, so it cannot be a way round `SCOUT_OFFLINE`.
 *
 * **"RECENT" IS A QUERY, NOT THE END OF THE FOLDER — and getting that wrong cost a live source.**
 * `fetchRecent()` used to read the highest `$limit` sequence numbers. On 2026-08-25 the developer
 * widened their Gmail filter to catch five portals and re-labelled a year of archived alert mail
 * into the same label; SeLoger immediately went from **9 listings to 0**, and the only thing that
 * said so was `SourceHealth` (`warn_drop`, 0 against a 7-day mean of 9). Measured on that mailbox:
 * 1436 messages in the folder, of which `SEARCH SINCE` matched 124 — **at sequence numbers 6, 7, 8,
 * 9, 10 and up**, so the tail of the folder contained none of them.
 *
 * Two consequences are baked in below and neither is optional:
 *
 * - the window is chosen by `SEARCH SINCE`, so what counts as recent is the SERVER's answer about
 *   dates rather than this client's assumption about ordering;
 * - the query carries the source's own `FROM`, because one mailbox now serves many portals and a
 *   shared window is a shared budget — without it a busy portal starves a quiet one silently, and
 *   the more sources are added the worse it gets. See {@see searchCommand()}.
 *
 * `docs/PHORJ-REQUIREMENTS.md` asks phorj for a `Core.Imap` with the same shape. This is the PHP
 * half of that, and the two will read the same `.eml` fixtures.
 */
final class ImapMailbox implements Mailbox, MutableByDesign
{
    // MutableByDesign, and it clears that interface's bar rather than borrowing it. A protocol
    // client IS its connection state: an open socket and a monotonically increasing command tag,
    // both of which change as it works and neither of which can be a `readonly` property. It is a
    // collaborator constructed and handed a job, never a value returned from a computation — so
    // nothing downstream can hold it and rewrite a verdict, which is what that rule protects.
    //
    // The loophole is deliberately NOT taken: wrapping the socket in a `readonly` property holding
    // a mutable object would satisfy reflection and defeat the check.

    /** A response line longer than this is not a mailbox we understand. */
    private const int MAX_LINE_BYTES = 65536;

    /** A single message larger than this is not a listing alert. */
    private const int MAX_MESSAGE_BYTES = 4 * 1024 * 1024;

    /**
     * How many messages one pass reads per source, absent `IMAP_MAX_MESSAGES`.
     *
     * A cost ceiling, not a correctness one: it is also the number of bodies fetched every pass, and
     * `--watch` runs about 96 passes a day. Raising it is not free — see {@see truncationNotice()},
     * which is what makes the trade-off visible instead of leaving it to be discovered.
     */
    public const int DEFAULT_MAX_MESSAGES = 50;

    /** @var resource|null */
    private mixed $socket = null;

    private int $tag = 0;

    /**
     * The newest credible `Date` seen by the last fetch, as an ISO-8601 UTC instant.
     *
     * Per-fetch state on a class that is already {@see MutableByDesign} for exactly this reason — it
     * IS its connection, and this is one more thing the connection learned. See
     * {@see Mailbox::newestMessageAt()} for why the value exists at all.
     */
    private ?string $newestMessageAt = null;

    /**
     * Position in the last fetch's result → the message's UID. Per-fetch state, like the date above.
     *
     * @var list<int>
     */
    private array $uids = [];

    /**
     * UIDs of the last fetch that did NOT carry `\Seen` when read — the only ones worth storing.
     *
     * @var array<int, true>
     */
    private array $unseen = [];

    /**
     * Positions a source claimed since the last fetch.
     *
     * @var array<int, true>
     */
    private array $claimed = [];

    /** The folder's `UIDVALIDITY` as EXAMINE reported it; a differing value on SELECT refuses the store. */
    private ?int $uidValidity = null;

    /**
     * @param string|null $fromFilter the source's own `params.from`, pushed INTO the IMAP query.
     *                                See {@see searchCommand()} for why it is not merely a
     *                                post-fetch filter.
     * @param int         $sinceDays  how far back to look. Seven, matching the source-health rolling
     *                                mean, so the two speak the same unit.
     */
    public function __construct(
        private readonly string $host,
        private readonly string $user,
        private readonly string $password,
        private readonly string $folder = 'INBOX',
        private readonly int $port = 993,
        private readonly int $timeoutSeconds = 20,
        private readonly ?string $fromFilter = null,
        private readonly int $sinceDays = 7,
        private readonly Clock $clock = new SystemClock(),
        /**
         * Where a non-fatal remark about this fetch goes, or `null` to say nothing.
         *
         * A closure rather than a return value because {@see Mailbox::fetchRecent()} returns the
         * messages and nothing else, and widening that contract for one diagnostic would make every
         * implementation carry it. `Scout` passes its own `warn()`, so the line lands wherever the
         * operator is already looking — `doctor`'s output, or the run banner.
         */
        private readonly ?\Closure $warn = null,
        /**
         * Test seam: `(string $host, int $port, int $timeoutSeconds) → resource|false`, replacing
         * the `tls://` socket. Consulted only after the offline refusal has passed.
         *
         * @var ?\Closure(string, int, int): (resource|false)
         */
        private readonly ?\Closure $connector = null,
    ) {}

    public function newestMessageAt(): ?string
    {
        return $this->newestMessageAt;
    }

    /**
     * Record a message's `Date`, keeping the newest.
     *
     * **An UNPARSEABLE date is skipped, never treated as now**, and — the part that cost a review
     * round — *unparseable* has to be decided by a STRICT parser. `new \DateTimeImmutable($header)`
     * is a relative-expression parser, not a date parser: it misparses far more often than it
     * throws, and every misparse moves the instant FORWARD. Measured on the real private method:
     * a `Date:` header whose weekday does not match its date — `Fri, 09 Aug 2026 08:00:00 +0200`,
     * where 9 August 2026 is a Sunday — has `Fri` applied as a RELATIVE MODIFIER and advances to
     * the next Friday, recording **+5 days**. `now`, `tomorrow` and `+2 days` are all accepted as
     * literal dates too.
     *
     * That is not a cosmetic error, it closes the verdict: the observable band is
     * `IMAP_SINCE_DAYS − RENT_FEED_SILENT_DAYS`, four days on the defaults, so a +5-day shift ages the
     * message out of the search window — collapsing the count, which the verdict gates on — before
     * the shifted date can ever reach the threshold. One bad header suppresses `FEED_SILENT` on
     * that source for ever, which is the failure this whole feature exists to remove.
     *
     * {@see EmailMessage::parseRfc2822()} is therefore strict by ROUND-TRIP, the same technique `Store::epoch()`
     * uses and for the same reason. `createFromFormat(RFC2822)` alone is NOT sufficient — it also
     * yields 14 August for the header above and `getLastErrors()` reports nothing; only re-formatting
     * the result and comparing it to the input catches it.
     *
     * Skipping degrades to `null`, which yields no verdict rather than a false one.
     *
     * Normalised to UTC on the way in so the comparison in `Store::health()` is between instants
     * rather than between strings: portals stamp their own offsets, and `+0200` sorts before `Z`
     * on the same instant.
     */
    private function noteMessageDate(string $raw): void
    {
        // ONE parser for a message's date — `EmailMessage::sentAt()`, which listings now carry as
        // their observation time too. Two strict parsers of the same header is how they drift.
        $iso = EmailMessage::parse($raw)->sentAt();

        if ($iso === null) {
            return;
        }

        if ($this->newestMessageAt === null || $iso > $this->newestMessageAt) {
            $this->newestMessageAt = $iso;
        }
    }

    /**
     * RFC 2822 `Date:`, parsed STRICTLY — or `null`.
     *
     * Strict by round-trip: parse, re-format with the same mask, and require the result to equal
     * the input. That is what rejects `Fri, 09 Aug 2026` (a Sunday), which every non-round-tripping
     * route in PHP accepts and silently advances by five days. Same technique as
     * {@see \Scout\Rent\Store\Store::epoch()}, which carries its own scar for the same class of bug.
     *
     * Both masks are tried because RFC 2822 makes the day-of-week optional and real mailers emit
     * both shapes; the numeric-offset and named-zone variants likewise. Nothing here falls back to
     * a permissive parse — the whole point is that an unrecognised shape is UNKNOWN, and unknown
     * yields no verdict (hard rule 9).
     */
    private static function parseRfc2822(string $header): ?\DateTimeImmutable
    {
        // MOVED to `EmailMessage::parseRfc2822()` on 2026-08-29, when listings started carrying the
        // same date as their observation time: two strict parsers of one header is how they drift.
        // Kept as a one-line delegate so this class's docblock above still points at a real method.
        return EmailMessage::parseRfc2822($header);
    }

    public function describe(): string
    {
        // The user is shown because `doctor` has to be able to say WHICH mailbox is misconfigured,
        // and it is not a secret. The password is never here, and never reaches a message.
        return 'IMAP ' . $this->user . '@' . $this->host . ':' . $this->port . ' (' . $this->folder . ')';
    }

    public function fetchRecent(int $limit = 50): array
    {
        // Reset FIRST, not beside the loop. Two early `return []` paths sit above that loop — an
        // empty mailbox, and a `SEARCH` that matched nothing in the window — and a mailbox instance
        // is built once per source and reused across every `--watch` pass. So a reset placed later
        // let a pass that fetched NOTHING keep the previous pass's date and report it as its own,
        // which is exactly what `Pipeline` says must never happen ("the previous pass's freshness
        // would vouch for a pass that fetched nothing") while guarding only the exception path.
        //
        // It was harmless only because `Store::health()` gates the verdict on `$lastCount > 0`, in
        // a different file, individually deletable. One mechanism accidentally protected by another
        // is this repo's own named trap: never read "no harm occurred" as "the rule held".
        $this->newestMessageAt = null;
        // The same rule for the claim state: a claim from the previous pass must never be stored
        // on the strength of this one, and the early returns below are where a later reset would
        // let it survive.
        $this->uids = [];
        $this->unseen = [];
        $this->claimed = [];
        $this->uidValidity = null;

        $this->connect();

        try {
            // NOT `command('LOGIN …')` — see login()'s docblock. The line must never be a call
            // argument, because PHP prints those in a stack trace and this one carries the password.
            $this->login();

            // EXAMINE, not SELECT. Read-only at the PROTOCOL level, so no bug on this side can
            // modify the developer's mailbox while it reads. The one write lives in acknowledge().
            $select = $this->command('EXAMINE ' . self::quote($this->folder));
            $this->uidValidity = self::uidValidityIn($select);

            $total = 0;
            foreach ($select as $line) {
                if (preg_match('~^\*\s+(\d+)\s+EXISTS~i', $line, $m) === 1) {
                    $total = (int) $m[1];
                }
            }

            if ($total === 0) {
                // An EMPTY mailbox is a legitimate answer, not a failure — nothing has arrived. It
                // is the one case where returning [] is right, and it is distinguishable from a
                // failure because every failure path above and below throws.
                return [];
            }

            // ASK THE SERVER WHICH MESSAGES ARE RECENT. Reading the tail of the folder instead was
            // the defect of 2026-08-25 — see the class docblock.
            $matched = self::allSequencesIn(
                $this->command(self::searchCommand($this->fromFilter, $this->sinceDays, $this->clock->now())),
            );

            $sequences = $this->capWithNotice($matched, $limit);

            if ($sequences === []) {
                // Nothing in the window. Legitimate for the same reason an empty folder is, and
                // reachable only because SEARCH SUCCEEDED — a failing SEARCH throws in `command()`.
                return [];
            }

            $messages = [];

            foreach ($sequences as $uid) {
                [$raw, $seen] = $this->fetchMessage($uid);
                $messages[] = $raw;
                $this->uids[] = $uid;
                if (!$seen) {
                    $this->unseen[$uid] = true;
                }
                $this->noteMessageDate($raw);
            }

            return $messages;
        } finally {
            $this->disconnect();
        }
    }

    public function claim(int $position): void
    {
        if (!array_key_exists($position, $this->uids)) {
            throw new \InvalidArgumentException(sprintf(
                'claim(%d): the last fetch returned %d message(s) — a claim outside it would be silently ignored, and a message that reads as unprocessed for ever is the failure this flag exists to remove',
                $position,
                count($this->uids),
            ));
        }
        $this->claimed[$position] = true;
    }

    /**
     * The ONE write: `\Seen` on the claimed UIDs that lack it, in a second, read-write session.
     *
     * A no-op — no connection at all — when nothing needs storing, which is steady state: every
     * message inside the window was processed on an earlier pass and already carries the flag.
     * `--watch` runs ~96 passes a day per source; a login per pass for a flag already set would be
     * the cost with none of the signal.
     */
    public function acknowledge(): void
    {
        $uids = [];
        foreach (array_keys($this->claimed) as $position) {
            $uid = $this->uids[$position];
            if (isset($this->unseen[$uid])) {
                $uids[] = $uid;
            }
        }
        if ($uids === []) {
            return;
        }

        $this->connect();

        try {
            $this->login();

            // SELECT, not EXAMINE: this is the session that writes. A re-created folder hands out
            // the same UIDs for different messages, and UIDVALIDITY is the server's own statement
            // that the UIDs read under EXAMINE still name what they named — so a change refuses
            // the store BEFORE it is sent, never after.
            $selected = $this->command('SELECT ' . self::quote($this->folder));
            $validity = self::uidValidityIn($selected);
            if ($this->uidValidity !== null && $validity !== $this->uidValidity) {
                throw new MailboxError(sprintf(
                    'UIDVALIDITY of %s changed between the fetch (%d) and the acknowledgement (%s) — the claimed UIDs may name different messages, so nothing was marked',
                    $this->folder,
                    $this->uidValidity,
                    $validity === null ? 'absent' : (string) $validity,
                ));
            }

            $this->command(self::storeCommand($uids));

            foreach ($uids as $uid) {
                unset($this->unseen[$uid]);
            }
        } finally {
            $this->disconnect();
        }
    }

    /**
     * `UID STORE <set> +FLAGS.SILENT (\Seen)` — ADD the one flag, and only that one.
     *
     * `+FLAGS`, never `FLAGS` (which REPLACES the flag list and would strip `\Flagged` or a label
     * the developer set by hand) and never `-FLAGS`. `.SILENT` because the untagged FETCH echoes a
     * non-silent STORE sends are noise this client would otherwise have to read past.
     *
     * @param list<int> $uids
     */
    public static function storeCommand(array $uids): string
    {
        $set = array_values(array_unique(array_map(static fn (int $u): int => $u, $uids)));
        sort($set);
        if ($set === []) {
            throw new \InvalidArgumentException('a STORE with no UIDs would be a write that names nothing');
        }

        return 'UID STORE ' . implode(',', $set) . ' +FLAGS.SILENT (\Seen)';
    }

    /**
     * The `UIDVALIDITY` a SELECT/EXAMINE response carries, or `null` when the server sent none.
     *
     * @param list<string> $lines
     */
    private static function uidValidityIn(array $lines): ?int
    {
        foreach ($lines as $line) {
            if (preg_match('~\[UIDVALIDITY\s+(\d+)\]~i', $line, $m) === 1) {
                return (int) $m[1];
            }
        }

        return null;
    }

    /**
     * The IMAP query that decides which messages this pass even looks at.
     *
     * **`SINCE` is here because the tail of a folder is not "recent".** Measured on the live mailbox
     * 2026-08-25: the folder held 1436 messages, `SEARCH SINCE` two days back matched 124 of them,
     * and their sequence numbers began at **6, 7, 8, 9, 10** — the low end. Fetching the last 50 by
     * sequence therefore returned **zero** of the day's SeLoger alerts, against a seven-day mean of
     * nine, and `SourceHealth` reported `warn_drop` on a source that was publishing normally. The
     * mechanism by which a Gmail label orders its messages is NOT recorded here, because it was not
     * measured — what was measured is that ordering by sequence disagrees with ordering by date, and
     * that INTERNALDATE survives whatever re-labelling does (124 ≠ 1436 proves the server is
     * filtering on something the relabelling did not touch).
     *
     * **`FROM` is here because one mailbox now serves many portals**, and a shared window is a
     * shared budget. The `SINCE` window alone held 124 messages against a limit of 50 — five
     * portals' alerts plus the watcher's own notification emails, which land in the same inbox. So
     * a busy portal starves a quiet one, silently, and it gets worse with every source added. Push
     * the source's own `params.from` into the QUERY and each source gets its own window instead of a
     * slice of one. The post-fetch `from` check in {@see \Scout\Rent\Adapters\EmailAlertSource} stays
     * as it was: this makes the fetch cheap and correct, it is not the security boundary.
     *
     * The date is formatted with `date()`'s English month abbreviations, which RFC 3501 requires
     * (`d-Mon-yyyy`) and which are locale-independent by construction. A locale-aware formatter
     * would emit `Aoû` here and the server would either reject the command or, worse, match nothing.
     */
    /**
     * THE ONE PARSE OF `IMAP_SINCE_DAYS`, for every composition root and for {@see self::feedSilentWindowNote()}
     * (B-13, 2026-10-08). Four call sites parsed it with two different rules, so the window a domain
     * searched and the window its `doctor` advice named could differ.
     *
     * At least one day, as {@see self::searchCommand()} has always clamped. And `"0"` reads as the default:
     * `getenv() ?: 7` treats the STRING "0" as falsy, which is what every path has done since the key
     * existed — kept here, and pinned by a test, rather than changed inside a refactor.
     */
    public static function sinceDaysFrom(string|false $raw): int
    {
        return max(1, (int) ($raw ?: 7));
    }

    public static function searchCommand(?string $fromFilter, int $sinceDays, \DateTimeImmutable $now): string
    {
        // At least one day. A zero or negative window would match nothing and read as a quiet
        // market for ever — the failure this whole class of guard exists to refuse.
        $days = max(1, $sinceDays);
        $since = $now->sub(new \DateInterval('P' . $days . 'D'))->format('d-M-Y');

        // `UID SEARCH`, so the answer is UIDs rather than sequence numbers: acknowledge() runs in a
        // SECOND session, and a sequence number is only meaningful inside the one that read it.
        $command = 'UID SEARCH SINCE ' . $since;

        if ($fromFilter !== null && $fromFilter !== '') {
            // Through `quote()`, so the CRLF refusal applies: this value reaches the command line.
            $command .= ' FROM ' . self::quote($fromFilter);
        }

        return $command;
    }

    /**
     * The sequence numbers in a `SEARCH` response, highest first, capped at `$limit`.
     *
     * Highest first because the caller's contract is *newest first*, and within a window already
     * narrowed by date and sender that is the best available ordering — the alternative is fetching
     * every match to read its `Date`, which is the cost this query exists to avoid.
     *
     * An untagged `* SEARCH` reply MAY be split across several lines, and a server is free to send
     * one with no numbers at all; both shapes are read here rather than assumed away.
     *
     * @param list<string> $lines the response lines from {@see command()}
     *
     * @return list<int>
     */
    public static function sequencesIn(array $lines, int $limit): array
    {
        return array_slice(self::allSequencesIn($lines), 0, max(0, $limit));
    }

    /**
     * The same sequences, UNCAPPED — the count the window actually matched.
     *
     * Split out from {@see sequencesIn()} so the cap can be counted against the window rather than
     * silently applied to it. `SEARCH SINCE` decides what is recent and the cap decides how much of
     * that is read; the two disagree the moment a portal gets busy, and until this existed nothing
     * anywhere could observe the disagreement.
     *
     * @param list<string> $lines the response lines from {@see command()}
     *
     * @return list<int>
     */
    public static function allSequencesIn(array $lines): array
    {
        $sequences = [];

        foreach ($lines as $line) {
            if (preg_match('~^\*\s+SEARCH\b(.*)$~i', $line, $m) !== 1) {
                continue;
            }

            foreach (preg_split('~\s+~', trim($m[1])) ?: [] as $token) {
                if ($token !== '' && ctype_digit($token)) {
                    $sequences[] = (int) $token;
                }
            }
        }

        rsort($sequences);

        return array_values(array_unique($sequences));
    }

    /**
     * Apply the cap, saying so when it bites.
     *
     * **Extracted from {@see fetchRecent()} so that it can be reached without a socket.** Inline it
     * was three lines behind a live IMAP login, which no test in this tree can perform — and this
     * repo has twice shipped a guarantee whose branch no test entered, in exactly that shape. The
     * arithmetic is pure and covered by {@see truncationNotice()}; what this adds is proof that the
     * notice is actually emitted and that the returned slice is the capped one.
     *
     * @param list<int> $matched every sequence the window held, uncapped
     *
     * @return list<int>
     */
    private function capWithNotice(array $matched, int $limit): array
    {
        $notice = self::truncationNotice(\count($matched), $limit, $this->fromFilter);

        if ($notice !== null && $this->warn !== null) {
            ($this->warn)($notice);
        }

        return \array_slice($matched, 0, max(0, $limit));
    }

    /**
     * How many messages a pass may read, from `IMAP_MAX_MESSAGES` or the default.
     *
     * CLAMPED, never refused, and clamped the same way `IMAP_SINCE_DAYS` is — the two bound the same
     * query, one by date and one by count, so a reader who has learnt that a nonsense value there
     * means "at least one day" would be entitled to assume the same here. Zero is the case worth
     * naming: it would read no messages at all, which is a source reporting a quiet market for ever.
     *
     * @param string|null $configured the raw env value, or `null` when it is unset
     */
    public static function maxMessages(?string $configured): int
    {
        if ($configured === null || trim($configured) === '' || !is_numeric(trim($configured))) {
            return self::DEFAULT_MAX_MESSAGES;
        }

        return max(1, (int) trim($configured));
    }

    /**
     * What to say when the window held more than the cap will read — or `null` when it fits.
     *
     * **This is not a day-to-day loss, which is exactly why it needed saying out loud.** Inside a
     * date-filtered window the highest sequence numbers are the newest messages, so a busy source
     * still reads today's alerts and reports a healthy count; what silently shrinks is the CATCH-UP
     * window, the thing `IMAP_SINCE_DAYS` is set for. Measured on the live mailbox 2026-08-26:
     * SeLoger matched 107 messages in a seven-day window against a cap of 50, so seven days of
     * stated resilience was really about three and a quarter — and it gets worse as the portal gets
     * busier, in a direction nothing reports.
     *
     * Silent at exactly the cap: 50 read out of 50 loses nothing, and a notice that fires on every
     * busy-but-fine source is how a real one stops being read.
     *
     * @param string|null $from the source's own `params.from`, so the reader knows WHOSE window
     *                          this is — one mailbox serves every portal and each has its own
     */
    public static function truncationNotice(int $matched, int $limit, ?string $from): ?string
    {
        if ($matched <= $limit) {
            return null;
        }

        return sprintf(
            'fenêtre IMAP tronquée : %d message(s) de %s dans la fenêtre, %d lus (les plus récents). '
                . 'Les alertes du jour sont lues, mais le rattrapage promis par IMAP_SINCE_DAYS est '
                . 'réduit d\'autant — augmenter IMAP_MAX_MESSAGES (défaut %d) ou réduire IMAP_SINCE_DAYS',
            $matched,
            $from ?? 'cette source',
            $limit,
            self::DEFAULT_MAX_MESSAGES,
        );
    }

    private function connect(): void
    {
        $errno = 0;
        $errstr = '';

        $context = stream_context_create(['ssl' => [
            // Non-negotiable. An IMAP session carries the password in cleartext inside the TLS
            // tunnel, so a tunnel nobody verified is no tunnel at all.
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
        ]]);

        // The offline tripwire, on the egress point that matters most: hard rule 4 makes email-alert
        // ingestion the PRIMARY path for private portals, and this sends a cleartext password to a
        // host read from `.env`. A raw socket, so `CurlHttpClient`'s guard never saw it.
        $refusal = \Scout\Core\Offline::refusalForHost($this->host . ':' . $this->port, 'the IMAP server');
        if ($refusal !== null) {
            throw new MailboxError($refusal);
        }

        // The test seam, AFTER the refusal above — a connector consulted first would be a way round
        // `SCOUT_OFFLINE`, and the wire test asserts it is never asked for a non-loopback host.
        $socket = $this->connector !== null
            ? ($this->connector)($this->host, $this->port, $this->timeoutSeconds)
            : @stream_socket_client(
                'tls://' . $this->host . ':' . $this->port,
                $errno,
                $errstr,
                $this->timeoutSeconds,
                STREAM_CLIENT_CONNECT,
                $context,
            );

        if ($socket === false) {
            throw new MailboxError(sprintf('could not connect to %s:%d — %s', $this->host, $this->port, $errstr));
        }

        stream_set_timeout($socket, $this->timeoutSeconds);
        $this->socket = $socket;

        $greeting = $this->readLine();
        if (!str_starts_with($greeting, '* OK')) {
            throw new MailboxError('unexpected IMAP greeting: ' . $greeting);
        }
    }

    private function disconnect(): void
    {
        if ($this->socket === null) {
            return;
        }

        // Best effort — the connection is being torn down either way, and a failure to say LOGOUT
        // politely must not mask the real error that brought us here.
        @fwrite($this->socket, 'ZZZZ LOGOUT' . "\r\n");
        @fclose($this->socket);
        $this->socket = null;
    }

    /**
     * Send a tagged command and read until its completion line.
     *
     * @return list<string> every untagged line, in order
     */
    private function command(string $command): array
    {
        if ($this->socket === null) {
            throw new MailboxError('not connected');
        }

        $tag = sprintf('A%04d', ++$this->tag);

        if (@fwrite($this->socket, $tag . ' ' . $command . "\r\n") === false) {
            throw new MailboxError('could not write to the IMAP connection');
        }

        return $this->readTagged($tag);
    }

    /**
     * LOGIN, sent WITHOUT the credential ever becoming a call argument (C2 round 2, 2026-09-04).
     *
     * PHP ships `zend.exception_ignore_args = Off` and `zend.exception_string_param_max_len = 15`,
     * so an uncaught trace prints the first 15 characters of every string ARGUMENT. Passing
     * `'LOGIN "user" "password"'` to {@see command()} put the credential in that position, and it was
     * measured coming back out on this runtime:
     *
     *     #0 ... Scout\Adapters\Mail\ImapMailbox->command('LOGIN "ann" "Su...')
     *
     * Nothing leaked with the real `IMAP_USER` only because a long username spends the budget
     * first. That is luck, not a guard, and this repo's own vocabulary for it.
     *
     * **This construction was removed from `tools/dump-eml.php` in the SAME span that left it here**
     * — a correct rule applied to a subset of the surfaces it belongs on, which is this repo's named
     * recurring defect, committed by the change that documented the threat model. A review panel
     * found it; `Core\Notify\SmtpTransport` was the third surface, found by asking what else the
     * same question reaches.
     *
     * Object properties are NOT printed in a trace — only call arguments are — so reading the
     * credential from `$this` is the whole fix. The CR/LF check runs BEFORE {@see quote()} for the
     * same reason: `quote()` throws on a CR or LF, and it would throw with the password as its own
     * argument. Pre-validated, it cannot throw, so its frame is already gone by the time anything
     * else does.
     */
    private function login(): void
    {
        if ($this->socket === null) {
            throw new MailboxError('not connected');
        }

        foreach ([$this->user, $this->password] as $credential) {
            if (preg_match('~[\r\n]~', $credential) === 1) {
                throw new MailboxError('a CR or LF in an IMAP argument cannot be quoted — refusing to send it');
            }
        }

        $tag = sprintf('A%04d', ++$this->tag);
        $line = $tag . ' LOGIN ' . self::quote($this->user) . ' ' . self::quote($this->password) . "\r\n";

        // THE WRITE ITSELF IS A FRAME. Keeping the line out of `command()`'s parameter list is not
        // enough — it is then `fwrite`'s argument, and a trace prints every LIVE frame's arguments,
        // built-ins included. `@` suppresses warnings and does nothing to the `TypeError` a closed
        // stream raises. So the write is wrapped and the original discarded: the `MailboxError`
        // below captures its trace here, where nothing live holds the credential. Not chained, for
        // the same reason — a `previous` carries the trace being escaped.
        //
        // Stated cost: the underlying stream error is lost on this one call, so a failed LOGIN write
        // reports only that it could not be written. The same trade `secrets()` already makes.
        $written = false;

        try {
            $written = @fwrite($this->socket, $line);
        } catch (\Throwable) {
            $written = false;
        }

        if ($written === false) {
            throw new MailboxError('could not write to the IMAP connection');
        }

        $this->readTagged($tag);
    }

    /**
     * Read until the completion line for `$tag`.
     *
     * Split out of {@see command()} so {@see login()} can share it without routing the credential
     * through a call argument. The TAG is the only thing that crosses the boundary, and a tag is
     * not a secret.
     *
     * @return list<string> every untagged line, in order
     */
    private function readTagged(string $tag): array
    {
        $lines = [];

        while (true) {
            $line = $this->readLine();

            if (str_starts_with($line, $tag . ' ')) {
                $rest = substr($line, strlen($tag) + 1);
                if (!str_starts_with(strtoupper($rest), 'OK')) {
                    // The server echoes the failing command in its response, which for LOGIN means
                    // the password. `MailboxError` masks at construction, and `Redact`'s LOGIN rule
                    // fails CLOSED — it masks unless what follows is recognised prose.
                    throw new MailboxError('IMAP command failed: ' . $rest);
                }

                return $lines;
            }

            $lines[] = $line;
        }
    }

    /**
     * One message by UID, with whether it already carries `\Seen`.
     *
     * `BODY.PEEK[]`, never `BODY[]` — the plain form sets `\Seen` as a side effect of READING, which
     * would mark every message in the window on every pass whether or not any source claimed it.
     * The flags ride along so acknowledge() can skip what is already marked without a second read.
     *
     * @return array{string, bool} the raw message, and whether it was already `\Seen`
     */
    private function fetchMessage(int $uid): array
    {
        if ($this->socket === null) {
            throw new MailboxError('not connected');
        }

        $tag = sprintf('A%04d', ++$this->tag);
        if (@fwrite($this->socket, $tag . ' UID FETCH ' . $uid . ' (UID FLAGS BODY.PEEK[])' . "\r\n") === false) {
            throw new MailboxError('could not write to the IMAP connection');
        }

        $body = '';
        $seen = false;

        while (true) {
            $line = $this->readLine();

            if (str_starts_with($line, $tag . ' ')) {
                if (!str_starts_with(strtoupper(substr($line, strlen($tag) + 1)), 'OK')) {
                    throw new MailboxError('IMAP FETCH failed: ' . substr($line, strlen($tag) + 1));
                }

                return [$body, $seen];
            }

            if (preg_match('~FLAGS\s+\(([^)]*)\)~i', $line, $f) === 1 && preg_match('~\\\\Seen\b~i', $f[1]) === 1) {
                $seen = true;
            }

            // A literal: `* 12 FETCH (UID 34 FLAGS () BODY[] {2048}` means exactly 2048 bytes follow.
            if (preg_match('~\{(\d+)\}$~', $line, $m) === 1) {
                $length = (int) $m[1];

                if ($length > self::MAX_MESSAGE_BYTES) {
                    throw new MailboxError(sprintf(
                        'message UID %d is %d bytes, over the %d-byte limit — refusing to read it',
                        $uid,
                        $length,
                        self::MAX_MESSAGE_BYTES,
                    ));
                }

                $body = $this->readExactly($length);
            }
        }
    }

    private function readLine(): string
    {
        if ($this->socket === null) {
            throw new MailboxError('not connected');
        }

        $line = @fgets($this->socket, self::MAX_LINE_BYTES);
        $meta = stream_get_meta_data($this->socket);

        if ($meta['timed_out']) {
            throw new MailboxError('the IMAP connection timed out');
        }
        if ($line === false) {
            throw new MailboxError('the IMAP connection closed unexpectedly');
        }

        return rtrim($line, "\r\n");
    }

    private function readExactly(int $length): string
    {
        if ($this->socket === null) {
            throw new MailboxError('not connected');
        }

        $body = '';

        while (strlen($body) < $length) {
            $chunk = @fread($this->socket, $length - strlen($body));

            if ($chunk === false || $chunk === '') {
                throw new MailboxError('the IMAP connection closed while reading a message body');
            }

            $body .= $chunk;
        }

        return $body;
    }

    /**
     * IMAP quoted-string, with the two characters the grammar requires escaping.
     *
     * A password containing `"` or `\` is otherwise a protocol-level injection: the server would
     * read the rest of the password as further command arguments. Perfectly ordinary in a
     * generated app password.
     */
    private static function quote(string $value): string
    {
        if (preg_match('~[\r\n]~', $value) === 1) {
            // A CR or LF cannot be represented in an IMAP quoted string at all (RFC 3501: a
            // quoted-string excludes CR and LF), so one here would break the command line and
            // could inject a second protocol command. These values are operator-controlled `.env`
            // config, not attacker input — so this is defense-in-depth — but the quoted-string
            // grammar forbids it regardless, and refusing loudly beats emitting a malformed command.
            throw new MailboxError('a CR or LF in an IMAP argument cannot be quoted — refusing to send it');
        }

        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }
    /**
     * Whether the threshold is high enough, relative to the IMAP window, to rarely fire.
     *
     * `null` when there is nothing to say. A STRING for `doctor` to print — **not** a refusal, and
     * the reason it is not is worth keeping.
     *
     * **This WAS a hard startup refusal, and both of its legs broke under review (2026-08-29).**
     *
     * Its premise was *"the newest message `SEARCH SINCE` can match is by definition at most
     * `IMAP_SINCE_DAYS` old"*, which is false: `SEARCH SINCE` filters on **INTERNALDATE**, the
     * server's arrival time, while this threshold is measured against the message's own **`Date:`
     * header**. A message delivered today and stamped weeks ago — a bulk re-label, a delayed relay,
     * exactly the 2026-08-25 incident shape — is inside the window and arbitrarily old. Demonstrated
     * at twenty days. So the age is NOT bounded by the window and `feed_silent` is not unreachable;
     * it is merely less likely to fire, which is advice, not an error.
     *
     * And the refusal LOCKED THE TOOL OUT. `IMAP_SINCE_DAYS=1` — which `.env.example` documents as
     * meaningful and which `ImapMailbox` clamps to happily — leaves no integer satisfying
     * `1 <= days < 1`, so every store-opening verb (`doctor`, `dump`, `run`, `digest`,
     * `reclassify`) exited 2, **including on deployments with no email source at all**. That was a
     * regression: before this feature the same value was simply clamped. Worse, a refused `run`
     * writes `state/rent-last-refusal.txt` to be reported on the next SUCCESSFUL start, which could never
     * come — under Docker, a crash loop writing a note nobody reads, verbatim the reader Q27 exists
     * for.
     *
     * MOVED HERE FROM `RentScout` on 2026-09-01: `IMAP_SINCE_DAYS` is SHARED between the two
     * domains (`CarScout` says so in its own doctor output), and the check lived only on the rent
     * side — so the car domain carried the identical clash and said nothing. `config/car/sources.json`
     * had `leboncoin: feed_silent_days 7` against the default 7-day window, an empty observable band,
     * for as long as that value existed. This class owns the window, so it owns the advice about it.
     *
     * `doctor` DIAGNOSES, it does not refuse. Direct precedent in this same class: an unusable `TZ`
     * is reported by `doctor` as a line and refused only by `run`.
     */
    public static function feedSilentWindowNote(int $window, ?int $days, string $label = 'RENT_FEED_SILENT_DAYS'): ?string
    {
        // The window comes from the caller, parsed by sinceDaysFrom() exactly as the mailbox it advises
        // about was built (B-13, 2026-10-08): this read the environment itself, unclamped, so
        // `IMAP_SINCE_DAYS=-3` searched one day while the advice reported a window of -3.
        if ($days === null || $days < $window) {
            return null;
        }

        return sprintf(
            '%s (%d) >= IMAP_SINCE_DAYS (%d) — un flux muet fera surtout retomber le '
            . 'compteur à zéro (statut broken) avant d\'atteindre le seuil ; la bande observable est '
            . '(seuil, fenêtre). Baissez le seuil ou augmentez la fenêtre.',
            $label,
            $days,
            $window,
        );
    }

}
