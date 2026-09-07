<?php

declare(strict_types=1);

namespace Scout\Core;

/**
 * The RUN LOG and SOURCE HEALTH — the part of a store that belongs to no domain.
 *
 * Extracted from {@see \Scout\Rent\Store\Store} on 2026-09-01. `VehicleStore` composed the rent
 * housing store wholesale to reach six methods, and the cost of that was visible in the live car
 * database: `state/car-watch.sqlite3` carried `listings`, `price_history`, `listing_detail` and
 * `commute_cache` — four rent tables, all empty, created by a migration written for a different
 * domain — and its `schema_meta` said `12`, the RENT schema version, in a file that has no housing
 * in it.
 *
 * The six, measured rather than assumed (`git grep "runs()->"` plus a direct scan of `src/php/Car`):
 * `recordRun`, `health`, `shouldAlert`, `markAlerted`, `clearAlerts`, `journalMode`. Nothing else.
 *
 * ## Why a new meta table rather than `schema_meta`
 *
 * Every store here refuses to open a schema newer than the code knows — deliberately, so a rolled
 * back binary cannot quietly rewrite a database it does not understand. That rule is what makes
 * adopting `schema_meta` for this class unsafe: the live rent file already records `12` there, and a
 * generic store at version 1 reading it would REFUSE TO OPEN THE RENT DATABASE. Not a degraded
 * start — the watcher failing to boot on the domain that produces the matches.
 *
 * So this class owns {@see RUN_META}, a table neither existing file has. Both read `$current = 0`
 * and migrate to 1 by the ordinary path, no existing key is reinterpreted, and the three versions
 * each mean exactly one thing. `vehicle_meta` already avoided colliding with `schema_meta` for the
 * same reason; this is that precedent, not a new idea.
 *
 * ## What did NOT change, on purpose
 *
 * The rent migration is not restructured. `Store::migrate()` keeps its shape and calls
 * {@see ddl()} where it previously wrote the two generic `CREATE TABLE` statements inline — one
 * definition, same statements, same order. Rewriting a migration that has run against an 8.9 MB
 * live database to make an extraction tidier is a trade this refactor declines.
 */
final readonly class RunStore
{
    /** This class's own schema version, recorded in its own table — never in `schema_meta`. */
    public const int SCHEMA_VERSION = 1;

    /** The meta table this class owns. Named so it can never be confused with a domain's own. */
    public const string RUN_META = 'run_meta';

    /**
     * See {@see \Scout\Rent\Store\Store::BUSY_TIMEOUT_MS} — two processes are a designed part of
     * the product, so a second writer must WAIT rather than fail.
     */
    public const int BUSY_TIMEOUT_MS = 5000;

    private function __construct(
        private \PDO $pdo,
        private string $journalModeInUse,
        /**
         * How many days of portal silence make a still-producing source
         * {@see SourceStatus::FEED_SILENT}. `null` disables the verdict entirely.
         */
        private ?int $feedSilentDays = null,
    ) {}

    /**
     * Adopt an ALREADY-OPEN handle — how the rent store composes this one.
     *
     * The rent store creates the PDO, sets the pragmas and learns the journal mode it was actually
     * given; re-opening the same file here would be a second connection to a database the caller
     * already holds, which under WAL is how a process contends with itself.
     */
    public static function fromPdo(\PDO $pdo, string $journalMode, ?int $feedSilentDays = null): self
    {
        return new self($pdo, $journalMode, $feedSilentDays);
    }

    /** Open a database that has ONLY a run log — the car domain's shape. */
    public static function open(string $path, ?int $feedSilentDays = null): self
    {
        if ($path !== ':memory:') {
            $directory = \dirname($path);

            if (!is_dir($directory)) {
                if (file_exists($directory)) {
                    throw new \RuntimeException(sprintf(
                        'le chemin de la base traverse un fichier : %s',
                        $directory,
                    ));
                }

                if (!mkdir($directory, 0o775, true) && !is_dir($directory)) {
                    throw new \RuntimeException(sprintf('impossible de créer le répertoire de la base : %s', $directory));
                }
            }
        }

        $pdo = new \PDO('sqlite:' . $path, options: [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = ' . self::BUSY_TIMEOUT_MS);
        $mode = $pdo->query('PRAGMA journal_mode = WAL');
        $journalMode = $mode === false ? 'unknown' : (string) $mode->fetchColumn();

        $store = new self($pdo, $journalMode, $feedSilentDays);
        $store->migrate();

        return $store;
    }

    /**
     * The generic tables, as ONE definition.
     *
     * `static` and taking a handle so the rent store can call it from inside its own migration
     * without owning an instance yet. Every statement is `IF NOT EXISTS`, so running it against a
     * database that already has these tables — every live file does — is a no-op.
     */
    public static function ddl(\PDO $pdo): void
    {
        $pdo->exec(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS source_runs (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                source     TEXT NOT NULL,
                item_count INTEGER NOT NULL,
                ok         INTEGER NOT NULL,
                error      TEXT,
                at         TEXT NOT NULL,
                at_epoch   INTEGER NOT NULL,
                duration_ms INTEGER,
                -- The newest message date this run saw, so health can tell "the portal stopped
                -- sending" from "the market is quiet". The rent store reached this shape by an
                -- ALTER at its v11; a table created HERE is created at the current shape, and that
                -- store's upgrade step is guarded on the column's existence, so it skips.
                --
                -- Copying the ORIGINAL create statement rather than the CURRENT shape is the
                -- mistake this comment exists to stop being repeated: `php -l` passed, the class
                -- reflected cleanly, and the first `recordRun()` threw "table source_runs has no
                -- column named feed_newest_at". Only running it found that.
                feed_newest_at TEXT
            );

            CREATE INDEX IF NOT EXISTS source_runs_source ON source_runs (source, at_epoch, id);

            CREATE TABLE IF NOT EXISTS source_alerts (
                source   TEXT NOT NULL,
                status   TEXT NOT NULL,
                at       TEXT NOT NULL,
                at_epoch INTEGER NOT NULL,
                PRIMARY KEY (source, status)
            );
            SQL
        );
    }

    /** Create the generic tables and record this class's own version in its own table. */
    public function migrate(): void
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS ' . self::RUN_META . ' (key TEXT PRIMARY KEY, value TEXT NOT NULL)');

        $q = $this->pdo->query('SELECT value FROM ' . self::RUN_META . " WHERE key = 'schema_version'");
        $current = $q === false ? 0 : (int) ($q->fetchColumn() ?: 0);

        if ($current > self::SCHEMA_VERSION) {
            throw new \RuntimeException(sprintf(
                'journal des exécutions au schéma %d, ce code connaît le %d — mettez le code à jour, pas la base',
                $current,
                self::SCHEMA_VERSION,
            ));
        }

        self::ddl($this->pdo);

        // A table that predates the column — created by an older `ddl()`, or by a store whose own
        // upgrade path had not reached v11. Guarded on existence rather than on a version number,
        // because this class may be adopting a file another store migrated.
        $columns = array_column($this->pdo->query('PRAGMA table_info(source_runs)')->fetchAll(), 'name');

        if (!\in_array('feed_newest_at', $columns, true)) {
            $this->pdo->exec('ALTER TABLE source_runs ADD COLUMN feed_newest_at TEXT');
        }

        if ($current !== self::SCHEMA_VERSION) {
            $this->pdo
                ->prepare('INSERT OR REPLACE INTO ' . self::RUN_META . " (key, value) VALUES ('schema_version', :v)")
                ->execute(['v' => (string) self::SCHEMA_VERSION]);
        }
    }

    /** The version this class has recorded for itself. */
    public function schemaVersion(): int
    {
        $q = $this->pdo->query('SELECT value FROM ' . self::RUN_META . " WHERE key = 'schema_version'");

        return $q === false ? 0 : (int) ($q->fetchColumn() ?: 0);
    }

    /**
     * The journal mode SQLite actually gave us — `wal` on a normal filesystem, `memory` for
     * `:memory:`, `delete` where WAL was refused. Anything but `wal` on a file database means two
     * processes will contend rather than share, so `scout doctor` prints it.
     *
     * This docblock was ORPHANED in the rent store — stranded above `cachedCommuteMinutes()`'s own,
     * where PHP took the later one and this text documented nothing. Reunited with its method here.
     */
    public function journalMode(): string
    {
        return $this->journalModeInUse;
    }

    // ---- thresholds (moved verbatim from Scout\Rent\Store\Store, 2026-09-01) ----

    /** Spec §8: the mean is rolling over seven days, not over a fixed number of runs. */
    public const int ROLLING_WINDOW_DAYS = 7;

    /** Spec §8: three consecutive empty runs against a non-zero baseline means broken. */
    public const int EMPTY_RUNS_BEFORE_BROKEN = 3;

    /** Spec §8: a drop of more than 70% below the rolling mean warrants a warning. */
    public const float DROP_WARNING_RATIO = 0.3;

    /**
     * Share of failed runs in the window above which a source is flagged flaky.
     *
     * Chosen, not derived — spec §8 does not name a number, and there is no run history to fit one
     * to. Recorded as an open question so it can be tuned against real data rather than defended
     * as if it had been measured.
     */
    public const float FLAKY_FAILURE_RATIO = 0.3;

    /** A window needs at least this many runs before a failure RATE means anything. */
    public const int MIN_RUNS_FOR_FLAKY = 3;

    /**
     * The SHORT flaky window, and the reason there are two (Track 6-A1, 2026-09-01).
     *
     * The window that makes a SUSTAINED fault visible is the window that hides a CLIMBING one.
     * Measured on the live watcher rather than reasoned: In'li's daily failure rate went 2.0 %
     * (08-29) → 11.3 % → 11.7 % → **22.8 %** (09-01) — 23 of 100 passes on the last day — while
     * the seven-day denominator held the reported figure at **8.2 %**, so
     * {@see FLAKY_FAILURE_RATIO} could not fire and `doctor` said `ok` throughout. No other verdict
     * could see it either: the interleaved successes still returned ~165 items, so nothing dropped,
     * nothing was empty, and the schedule never stopped. Hard rule 2's shape, on an axis the
     * existing rule averages away.
     *
     * Same class as `STALE` / `FEED_SILENT` — a correct rule with a blind spot on one axis of what
     * it measures — so it takes the same repair: a second window beside the first, not a
     * replacement, with the detail line naming which one fired.
     *
     * The ratio is CHOSEN AGAINST A COUNTERWEIGHT, not fitted to In'li. Failure rate over the last
     * 24 h, eleven sources, both domains, 2026-09-01: inli 23.0 %, cdc_habitat 3.0 %, car leboncoin
     * 1.0 %, and bienici / cityloger / leboncoin / logirep / pap / seloger / autohero / paruvendu
     * all 0.0 %. 20 % separates the one degrading source from every healthy one with room on both
     * sides. In'li's own history says the same: it would have fired on 09-01 and on no earlier day.
     *
     * THE MINIMUM IS THE HALF THAT KEEPS IT HONEST, and it is much larger than
     * {@see MIN_RUNS_FOR_FLAKY} on purpose. At the Q37 cadence a day holds ~100 passes; on a
     * cron-driven `--once` deployment it holds four, where ONE failure is 25 % and means nothing.
     * So the short rule requires a dense window and stays deliberately inert on sparse
     * deployments — the seven-day rule still covers those, which is what makes this a scope limit
     * rather than a hole, and it is asserted as its own test.
     */
    public const int FLAKY_SHORT_WINDOW_DAYS = 1;

    /** @see FLAKY_SHORT_WINDOW_DAYS — measured against every other source, not fitted to one. */
    public const float FLAKY_SHORT_FAILURE_RATIO = 0.2;

    /** @see FLAKY_SHORT_WINDOW_DAYS — a day's window on a sparse deployment says nothing. */
    public const int MIN_RUNS_FOR_SHORT_FLAKY = 20;

    /**
     * A source must have been trying for at least this long before "never produced" means anything.
     *
     * Without it, three successful empty polls at a 15-minute interval told a source onboarded
     * forty-five minutes ago that its field map was probably wrong. In'li LLI stock in one commune
     * is legitimately empty for days.
     *
     * RAISED FROM ONE DAY TO SEVEN on 2026-08-07, after a review demonstrated the one-day value
     * false-accusing a source doing nothing wrong: a new source answering HTTP 200 with zero items,
     * polled every 15 minutes, flipped to `NEVER_PRODUCED` at the 24-hour mark and stayed there. The
     * question that adopted one day (`docs/OPEN-QUESTIONS.md` Q23) had already written down that
     * *"In'li LLI stock in one commune is legitimately empty for days, so the honest floor could be
     * a week"* — and then kept the day anyway. Seven days now matches {@see ROLLING_WINDOW_DAYS},
     * which is the same judgement about how long this market takes to say anything.
     *
     * The trade is stated rather than hidden: a genuinely broken field map on a new source now goes
     * unremarked for a week. That is acceptable only because it is not the ONLY detector — a source
     * that used to produce items and stops is caught in three runs by {@see EMPTY_RUNS_BEFORE_BROKEN},
     * which is the far commoner shape. This one covers the source that never worked at all, where a
     * week of patience costs nothing and a false accusation costs the alert's credibility.
     */
    public const int MIN_SPAN_FOR_NEVER_PRODUCED = 604800;

    /**
     * How far into the future a message date may sit before it stops being credible.
     *
     * Not slack for a broken clock — slack for THIS process. `Pipeline::runOnce()` captures one
     * `$nowIso` at the start of a pass, and under Q37 pacing (5 s between hosts, 60 s per host,
     * order shuffled) a source polled minutes later can legitimately see a message stamped after
     * that instant. With no grace, a healthy source's very first pass reports
     * `toutes les dates de message sont dans le futur — vérifiez l'horloge du portail`, on a source
     * that just delivered, on a fresh database — which is precisely when someone is watching
     * (`scout run --seed`). An hour is far beyond any pass and far below any threshold, so it
     * cannot mask real silence: the smallest threshold is one day.
     */
    private const int FEED_CLOCK_GRACE_SECONDS = 3600;

    // ---- the six methods the car domain reached through the rent store ----

    /**
     * Log the outcome of one fetch.
     *
     * A failed fetch is recorded AS a failure, with its error. `CLAUDE.md` hard rule 3 exists
     * because the alternative — catching the exception and recording zero items — turns a loud
     * breakage into a quiet one, and quiet is indistinguishable from a calm rental market.
     *
     * **Every run is logged, whatever its timestamp says.** An earlier version refused a run stamped
     * before one already recorded, on the theory that a run has no legitimate out-of-order case. It
     * made things worse: nothing checks a timestamp against a clock (by design — health can only be
     * tested if time is an argument), so the FIRST forward-skewed run was still accepted, and the
     * refusal then discarded the real runs that followed. Three outright failures went unrecorded
     * and `health()` reported `OK` with `lastFailureAt` null — the freeze survived, and the evidence
     * of it did not. A log that drops entries is not a log.
     *
     * What actually defends against a poisoned timestamp is downstream, in {@see health()}: recency
     * is read from the log's own insertion order, and the rolling MEAN is bounded at both ends so a
     * run dated 2036 cannot inflate it forever. The COUNTS are bounded differently — see
     * {@see windowCounts()}, which has to answer a different question.
     *
     * @throws \InvalidArgumentException on an unreadable timestamp
     */
    public function recordRun(
        string $sourceName,
        int $itemCount,
        bool $ok,
        ?string $error,
        string $atIso,
        ?int $durationMs = null,
        ?string $feedNewestAt = null,
    ): void {
        $epoch = self::epoch($atIso);

        if ($feedNewestAt !== null) {
            // Validated at WRITE time, beside the caller that produced it. Deferring it to
            // `health()` would turn an unreadable date into a permanent absence of verdict, which
            // is the silent direction: the source would look watched and be unwatched.
            self::epoch($feedNewestAt);
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO source_runs (source, item_count, ok, error, at, at_epoch, duration_ms, feed_newest_at)
             VALUES (:source, :count, :ok, :error, :at, :epoch, :duration, :feed)',
        );
        $statement->execute([
            'source' => $sourceName,
            'count' => $itemCount,
            'ok' => $ok ? 1 : 0,
            // Schema v3 (Q25). Nullable, because only the CLI can measure a fetch and a caller that
            // does not measure must record `null` rather than a fabricated 0 — a zero-millisecond
            // fetch would read as a suspiciously fast source rather than as an unmeasured one.
            'duration' => $durationMs,
            // An adapter exception naturally carries the request URL or the mailbox it failed on,
            // and this value reaches both the database and the user-facing detail below.
            'error' => Redact::text($error),
            'at' => $atIso,
            'epoch' => $epoch,
            // Schema v11. The newest MESSAGE date this run saw, or null when the source does not
            // report one -- every html/json source, and every FileMailbox run, because a directory
            // of frozen fixtures is not a feed. Validated here rather than at read time so an
            // unparseable value is refused loudly at the moment it is written, next to the caller
            // that produced it, instead of quietly yielding no verdict for ever.
            'feed' => $feedNewestAt,
        ]);
    }

    /**
     * Should this source/status alert be sent, given the cooldown?
     *
     * KEYED ON `(source, status)`, never on source alone. An escalation from `WARN_DROP` to `BROKEN`
     * is a different fact from the warning that preceded it, and keying on the source would let the
     * quieter alert swallow the louder one for a whole day.
     *
     * Persisted rather than held in memory, and that is the point of the table: a crash-looping
     * container re-alerts on every restart, and a manual `scout doctor` shares no state with a
     * running `--watch`. It also cannot be derived from `source_runs`, which records RUNS and cannot
     * distinguish "was broken" from "was told about".
     */
    public function shouldAlert(string $sourceName, string $status, string $nowIso, int $cooldownHours): bool
    {
        $now = self::epoch($nowIso);

        $statement = $this->pdo->prepare(
            'SELECT at_epoch FROM source_alerts WHERE source = :source AND status = :status',
        );
        $statement->execute(['source' => $sourceName, 'status' => $status]);
        $row = $statement->fetch();

        if ($row === false) {
            return true;
        }

        $last = (int) $row['at_epoch'];

        // A future-stamped row means a clock moved backwards, or a hand-edited database. Alerting
        // is the safe direction: the alternative is a source that is silently un-alertable until the
        // clock catches up, which could be indefinitely.
        if ($last > $now) {
            return true;
        }

        return ($now - $last) >= $cooldownHours * 3600;
    }

    /** Record that an alert for this `(source, status)` has just been sent. */
    public function markAlerted(string $sourceName, string $status, string $atIso): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO source_alerts (source, status, at, at_epoch)
             VALUES (:source, :status, :at, :epoch)
             ON CONFLICT (source, status) DO UPDATE SET at = :at, at_epoch = :epoch',
        );
        $statement->execute([
            'source' => $sourceName,
            'status' => $status,
            'at' => $atIso,
            'epoch' => self::epoch($atIso),
        ]);
    }

    /**
     * A source is `OK` again: forget every alert we sent about it.
     *
     * Returns whether anything was cleared, which is exactly the condition for sending the ONE
     * recovery notice. Without it a developer who fixes a field map sees nothing and has no
     * confirmation the fix took — and the next, different breakage that day would also be silent,
     * because the cooldown from the old alert would still be running.
     */
    public function clearAlerts(string $sourceName): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM source_alerts WHERE source = :source');
        $statement->execute(['source' => $sourceName]);

        return $statement->rowCount() > 0;
    }

    public function health(string $sourceName, ?string $nowIso = null, ?int $feedSilentDays = null): SourceHealth
    {
        $feedSilentDays ??= $this->feedSilentDays;

        if ($feedSilentDays !== null && $feedSilentDays < 1) {
            // Refused rather than clamped, and refused LOUDLY, because a threshold of zero or below
            // disables the only signal that distinguishes a dead alert from a quiet market. Same
            // asymmetry as `RENT_HEARTBEAT_HOURS`: an omitted threshold is benign (no verdict), an
            // explicit unusable one is a configuration error wearing the shape of a setting.
            throw new \InvalidArgumentException(
                'feedSilentDays doit valoir au moins 1 jour — 0 désactiverait la détection de flux muet',
            );
        }

        $statement = $this->pdo->prepare(
            // Insertion order. See below for why it is not the final word.
            'SELECT id, item_count, ok, error, at, at_epoch, feed_newest_at FROM source_runs
              WHERE source = :source ORDER BY id ASC',
        );
        $statement->execute(['source' => $sourceName]);

        /** @var list<array{item_count:int|string, ok:int|string, error:?string, at:string, at_epoch:int|string, feed_newest_at:?string}> $runs */
        $runs = $statement->fetchAll();

        if ($runs === []) {
            // Never run is not the same as healthy. A source configured months ago that has never
            // once fired is a configuration bug, and it is invisible because it never fails.
            return new SourceHealth(
                sourceName: $sourceName,
                status: SourceStatus::NEVER_RUN,
                detail: 'aucun run enregistré pour cette source',
            );
        }

        // WHICH RUN IS "THE LAST ONE" — settled, after three rounds of getting it wrong.
        //
        // INSERTION ORDER, always. Not the timestamp, and not the timestamp filtered by a clock.
        // The three candidates fail for different LENGTHS of time, and that is the whole argument:
        //
        //   timestamp, no clock — one future-stamped row sorts last FOREVER and hides every later
        //     run. Twenty consecutive failures reported OK, permanently.
        //   timestamp + clock — a clock only disqualifies rows stamped after `now`. A row skewed by
        //     an hour is fully credible once that hour has passed, and then it hides every real run
        //     logged after it for the duration of the skew. It is also worse than useless when the
        //     CLOCK is the thing that is wrong: an hour-slow clock discarded three real failures and
        //     reported OK, with `totalRuns` counting only the survivors — a silent discard.
        //   insertion order — a run committed late but stamped earlier wins, so one success logged
        //     after three failures reads OK. Two writers make that reachable. A SINGLE such row
        //     self-corrects on the next run; a writer that stamps stale SYSTEMATICALLY keeps the
        //     last-run rule quiet indefinitely, and what catches that is WARN_FLAKY — which is why
        //     `windowCounts()` counts failures whatever their clock says. Both are alerting.
        //
        // Bounded-by-one-run beats bounded-by-the-skew beats unbounded. So the log's own order wins,
        // and `$nowIso` is used for exactly one thing below: STALE, which genuinely cannot be
        // derived without a clock. It never filters, reorders or discards a run.

        // A TRAILING FAILED RUN BELOW THE THRESHOLD IS NOT AN OBSERVATION (2026-09-07).
        //
        // `if (!$lastOk)` fired on ONE failure while the empty path had required three since it was
        // written, and `alertOnHealth()` reads the next successful pass as recovery: it sends
        // *retablie* and CLEARS the cooldown row, so the cooldown could never damp anything.
        // Measured over four days, in'li alone sent 29 broken + 30 retablie out of 428 runs while
        // returning 165 annonces on the passes either side. The fault was the portal's -- 44 of its
        // 59 failures are an HTTP 302 to its own /maintenance -- and three other sources failed 0
        // times in 632+ runs through this same stack.
        //
        // A THRESHOLD ALONE WOULD HAVE MOVED THE NOISE, not removed it, because two branches below
        // read the failed run's `item_count` of 0 as an observation: `WARN_DROP` fires at
        // `lastCount < rollingMean * 0.3`, and `!isAlerting()` reads as RECOVERY -- so a source
        // with a real standing alert would announce itself recovered on a hiccup and re-alert with
        // its cooldown wiped. Both are hard rule 9 at this layer: a failed run's zero is UNKNOWN,
        // not "zero annonces", and it is no more evidence of recovery than of a drop.
        // `rollingMeanBefore()` already knew that and filters on `ok = 1`; nothing else did.
        //
        // So the count-based verdicts judge `$observed`. `STALE` and `WARN_FLAKY` keep the WHOLE
        // log on purpose -- they are about ATTEMPTS, and a failure is a perfectly good attempt.
        // Stripping also stops one hiccup resetting a long empty streak, which it did.
        //
        // The strip needs something BEHIND it: a source whose entire history is failures has no
        // observation to fall back on, and reporting OK there would hide a source misconfigured on
        // the day it was added. That is why an empty remainder is refused rather than accepted.
        // A TOLERATED FAILURE IS DROPPED WHEREVER IT SITS, not only when it is the last run. The
        // trailing-only version of this was measured against the live car store on 2026-09-07 and
        // did NOT hold: leboncoin's failure sat THREE runs from the end, so it still truncated a
        // 308-run empty streak to 3 — and the streak rebuilding through 1 and 2 reports OK, which
        // is *retablie* plus a wiped cooldown, then BROKEN again at 3. The flap survived the fix
        // for it, on the one source the fix was not about.
        $failedStreak = self::trailingFailedRuns($runs);
        $observed = self::observedRuns($runs);

        $last = $observed[array_key_last($observed)];
        $lastCount = (int) $last['item_count'];
        $lastOk = (int) $last['ok'] === 1;

        $lastSuccessAt = null;
        $lastFailureAt = null;
        $everProduced = false;
        $successfulRuns = 0;

        foreach ($runs as $run) {
            if ((int) $run['ok'] === 1) {
                $lastSuccessAt = (string) $run['at'];
                ++$successfulRuns;
                $everProduced = $everProduced || (int) $run['item_count'] > 0;
            } else {
                $lastFailureAt = (string) $run['at'];
            }
        }

        $epochs = array_map(static fn (array $run): int => (int) $run['at_epoch'], $runs);

        // Every MESSAGE date any run reported. A row that reported nothing contributes nothing:
        // unknown is not old (hard rule 9), and that covers every html/json source, every
        // `FileMailbox` run and every row written before schema v11.
        //
        // Kept as a LIST rather than reduced to a maximum here, because the maximum is only
        // meaningful once a clock exists to judge credibility against. The value is whatever a
        // portal stamped on its own mail, so one skewed clock — or one message dated 2030 — would
        // otherwise win the maximum and mask a genuinely ageing feed behind it, permanently.
        // CLAMPING that date to now is worse than useless: it makes the bogus date read as
        // perfectly fresh, which is the masking this exists to prevent. Future rows are FILTERED,
        // exactly as `STALE` already filters forward-skewed run timestamps below.
        $feedDates = [];

        foreach ($runs as $run) {
            $reported = $run['feed_newest_at'] ?? null;

            if ($reported !== null && $reported !== '') {
                $feedDates[] = $reported;
            }
        }

        $emptyStreak = self::trailingEmptyRuns($observed);
        $rollingMean = self::rollingMeanBefore($observed, \count($observed) - 1);
        $edge = $nowIso === null ? null : self::epoch($nowIso);
        [$runsInWindow, $failedInWindow] = self::windowCounts($runs, $edge);
        // The SHORT window, computed from the same rows and bounded above by the same clock —
        // a future-stamped success must not dilute this one either (Track 6-A1).
        [$runsInShortWindow, $failedInShortWindow] = self::windowCounts($runs, $edge, self::FLAKY_SHORT_WINDOW_DAYS);

        // A TOLERATED FAILURE IS SAID ON EVERY VERDICT, not just on OK. The note lives in the one
        // place every status passes through, because the first version put it in the OK branch
        // alone -- and `testAFailedRunOutranksASilentFeed` proved what that costs: the source came
        // back FEED_SILENT and the exception was buried, which is the very thing that test's
        // docblock forbids. One surface, or it is forgotten on the next verdict added.
        // THE TRAILING streak, never the cumulative count. Counting every failure ever dropped put
        // "82 échec(s) toléré(s)" on in'li's healthy verdict when it was measured against the live
        // store — true, and it reads as an incident on a source that is fine. The note exists to say
        // one thing: a failure is being tolerated RIGHT NOW, and here is the bar it has not reached.
        $tolerated = $failedStreak > 0 && $lastOk ? sprintf(
            ' — %d échec(s) récent(s) toléré(s), alerte à %d',
            $failedStreak,
            self::EMPTY_RUNS_BEFORE_BROKEN,
        ) : '';

        $health = static fn (SourceStatus $status, string $detail): SourceHealth => new SourceHealth(
            sourceName: $sourceName,
            status: $status,
            detail: $detail . $tolerated,
            consecutiveEmptyRuns: $emptyStreak,
            lastSuccessAt: $lastSuccessAt,
            lastFailureAt: $lastFailureAt,
            lastCount: $lastCount,
            rollingMean: $rollingMean,
            runsInWindow: $runsInWindow,
            failedRunsInWindow: $failedInWindow,
            totalRuns: \count($runs),
        );

        if (!$lastOk) {
            return $health(SourceStatus::BROKEN, sprintf(
                '%d run(s) consécutif(s) en échec — dernier (%s) : %s',
                $failedStreak,
                (string) $last['at'],
                $last['error'] ?? 'erreur non renseignée',
            ));
        }

        if ($emptyStreak >= self::EMPTY_RUNS_BEFORE_BROKEN) {
            // INDEXED INTO `$observed`, because `$emptyStreak` is counted there. Mixing the two
            // is not a style point: with the failure dropped, `\count($runs) - $emptyStreak` lands
            // one row early and averages a run the streak already contains — a 25-listing baseline
            // was reported as 12.5. `testAQuietRunBeforeTheStreakDoesNotZeroTheBaseline` caught it.
            $streakStart = \count($observed) - $emptyStreak;
            $baseline = self::rollingMeanBefore($observed, $streakStart);

            if ($baseline === null) {
                // The rolling window before the streak is EMPTY — the machine was off, or the
                // source was disabled, for longer than the window. That is "I do not know what
                // normal looks like", NOT "normal is zero", and letting the two share a branch made
                // a source that broke after any gap longer than the window report OK forever: ten
                // consecutive empty runs against a documented 25-listing history, status OK. So
                // fall back to the last successful run of ANY age.
                $baseline = self::lastProductiveCount($observed, $streakStart);
            }

            if ($baseline > 0.0) {
                // The last message date is carried into the detail, not just into FEED_SILENT.
                // This is the half that survives the threshold being wrong: when an email source's
                // count finally collapses because its window expired, the empty streak dates from
                // the expiry and the SILENCE dates from days earlier. Naming only the streak is how
                // the leboncoin case would have been reported on the wrong day and blamed on the
                // wrong cause.
                return $health(SourceStatus::BROKEN, sprintf(
                    '%d runs consécutifs à vide alors que la référence précédente était de %.1f annonces%s',
                    $emptyStreak,
                    $baseline,
                    self::newestCredibleFeedDate($feedDates, $nowIso) === null
                        ? ''
                        : sprintf(' — dernier message du portail : %s', self::newestCredibleFeedDate($feedDates, $nowIso)),
                ));
            }
        }

        if ($nowIso !== null) {
            // The ONLY use of the clock, and the only verdict that needs one. Silence is measured
            // from the newest run that is not stamped in the future, because a future-stamped row
            // would otherwise make this negative and report a stopped schedule as healthy. Nothing
            // is discarded from `$runs` — the other verdicts still see the whole log.
            $now = self::epoch($nowIso);
            $credible = array_filter($epochs, static fn (int $at): bool => $at <= $now);

            if ($credible === []) {
                return $health(SourceStatus::STALE, 'tous les runs sont horodatés dans le futur — vérifiez l\'horloge de la machine');
            }

            $silentFor = $now - max($credible);

            if ($silentFor > self::ROLLING_WINDOW_DAYS * 86400) {
                return $health(SourceStatus::STALE, sprintf(
                    'aucun run depuis %d jours — la planification a-t-elle cessé ?',
                    intdiv($silentFor, 86400),
                ));
            }
        }

        // max − min over the WHOLE log, not last − first. The rows are in insertion order and
        // `recordRun()` accepts any timestamp, so `last − first` goes NEGATIVE when the first row is
        // forward-skewed — and a negative span can never satisfy the floor below, disabling the
        // wrong-field-map detector permanently. One poisoned row hiding an unbounded number of later
        // ones is the exact shape the rest of this class was rewritten to remove.
        $span = max($epochs) - min($epochs);

        if (!$everProduced && $successfulRuns >= self::EMPTY_RUNS_BEFORE_BROKEN
            && $span >= self::MIN_SPAN_FOR_NEVER_PRODUCED) {
            // Succeeded repeatedly, never returned a single item. The empty-run rule correctly
            // declines to fire (the baseline really is zero) and nothing ever fails, so this hid
            // behind OK. It is the shape a wrong field map takes: HTTP 200, zero parsed items.
            return $health(SourceStatus::NEVER_PRODUCED, sprintf(
                '%d runs réussis sur %d jours, aucune annonce produite — mapping de champs à vérifier',
                $successfulRuns,
                max(1, intdiv($span, 86400)),
            ));
        }

        if ($runsInWindow >= self::MIN_RUNS_FOR_FLAKY && $failedInWindow / $runsInWindow > self::FLAKY_FAILURE_RATIO) {
            return $health(SourceStatus::WARN_FLAKY, sprintf(
                '%d échecs sur %d runs en %d jours',
                $failedInWindow,
                $runsInWindow,
                self::ROLLING_WINDOW_DAYS,
            ));
        }

        // The SHORT window, checked AFTER the long one so a sustained fault keeps the fuller
        // statement, and reported with its own wording so the two are never confused: "failing hard
        // today" and "failing steadily all week" are different faults with different responses.
        // See {@see FLAKY_SHORT_WINDOW_DAYS} for the measurement behind both numbers.
        if ($runsInShortWindow >= self::MIN_RUNS_FOR_SHORT_FLAKY
            && $failedInShortWindow / $runsInShortWindow > self::FLAKY_SHORT_FAILURE_RATIO) {
            return $health(SourceStatus::WARN_FLAKY, sprintf(
                // The window is DERIVED from the constant, never written beside it: a prose "24 h"
                // that outlives a changed `FLAKY_SHORT_WINDOW_DAYS` is a detail line that lies,
                // and this class's whole job is producing a line the operator can believe.
                '%d échecs sur %d runs sur les dernières %d h (%.0f %%) — dégradation récente, la '
                    . 'moyenne sur %d jours ne la montre pas encore (%d sur %d)',
                $failedInShortWindow,
                $runsInShortWindow,
                self::FLAKY_SHORT_WINDOW_DAYS * 24,
                100 * $failedInShortWindow / $runsInShortWindow,
                self::ROLLING_WINDOW_DAYS,
                $failedInWindow,
                $runsInWindow,
            ));
        }

        if ($nowIso !== null && $feedDates !== [] && $feedSilentDays !== null && $lastCount > 0) {
            // Ahead of WARN_DROP deliberately. A source that is BOTH quieter than its mean and
            // reading an ageing message is described far better by "the portal stopped sending"
            // than by "fewer than average": the first names a cause, the second names a symptom.
            //
            // Gated on `$lastCount > 0` because at zero the existing verdicts already own the
            // answer -- the empty-streak rule above knows the baseline and fires BROKEN, which now
            // carries the message date in its own detail.
            $now = self::epoch($nowIso);
            $feedNewest = self::newestCredibleFeedDate($feedDates, $nowIso);

            if ($feedNewest === null) {
                // Dates WERE reported and not one of them is believable. Saying nothing here would
                // let a portal with a permanently fast clock disable this verdict for ever, which
                // is the suppressed direction `Core/Heartbeat` rules against. Mirrors the STALE
                // branch below, which says the same thing about run timestamps.
                return $health(SourceStatus::FEED_SILENT, sprintf(
                    'toutes les dates de message sont dans le futur (la plus récente : %s) — vérifiez l\'horloge du portail',
                    self::newestFeedDate($feedDates) ?? '?',
                ));
            }

            $silentFor = max(0, $now - self::epoch($feedNewest));

            if ($silentFor >= $feedSilentDays * 86400) {
                return $health(SourceStatus::FEED_SILENT, sprintf(
                    'le portail n\'a rien envoyé depuis %d jour(s) (dernier message : %s) — %d annonce(s) relues du même courrier',
                    intdiv($silentFor, 86400),
                    $feedNewest,
                    $lastCount,
                ));
            }
        }

        if ($rollingMean !== null && $rollingMean > 0.0 && $lastCount < $rollingMean * self::DROP_WARNING_RATIO) {
            return $health(SourceStatus::WARN_DROP, sprintf(
                '%d annonces contre une moyenne de %.1f sur %d jours',
                $lastCount,
                $rollingMean,
                self::ROLLING_WINDOW_DAYS,
            ));
        }

        return $health(SourceStatus::OK, sprintf('%d annonces au dernier run', $lastCount));
    }

    // ---- private helpers of the health cluster ----

    /**
     * The runs that count as OBSERVATIONS: every failure episode shorter than
     * {@see EMPTY_RUNS_BEFORE_BROKEN} is dropped, and everything else is kept in order.
     *
     * A failed run's `item_count` of 0 is UNKNOWN, not "zero annonces" (hard rule 9), so the
     * count-based verdicts must not read it — as a supply drop, as an empty run, or as RECOVERY.
     * `STALE` and `WARN_FLAKY` deliberately keep the WHOLE log: they are about ATTEMPTS, and a
     * failure is a perfectly good attempt. That division is what keeps this from being a hole —
     * a source failing 30 % of the week is still `WARN_FLAKY` however isolated each failure is.
     *
     * An episode AT or OVER the threshold is kept, because that is a real outage and it should
     * break an empty streak exactly as it always did.
     *
     * THE EMPTY REMAINDER IS REFUSED. A source whose entire history is short failure episodes has
     * no observation to be judged against, and reporting it healthy would hide a source that was
     * misconfigured on the day it was added — so it keeps the raw log and reports BROKEN.
     *
     * @param list<array{ok:int|string, ...}> $runs
     * @return list<array{ok:int|string, ...}>
     */
    private static function observedRuns(array $runs): array
    {
        $observed = [];
        $episode = [];

        foreach ($runs as $run) {
            if ((int) $run['ok'] !== 1) {
                $episode[] = $run;

                continue;
            }

            if (\count($episode) >= self::EMPTY_RUNS_BEFORE_BROKEN) {
                array_push($observed, ...$episode);
            }

            $episode = [];
            $observed[] = $run;
        }

        if (\count($episode) >= self::EMPTY_RUNS_BEFORE_BROKEN) {
            array_push($observed, ...$episode);
        }

        return $observed === [] ? $runs : $observed;
    }

    /**
     * How many of the most recent runs FAILED — the mirror of {@see trailingEmptyRuns()}, and the
     * reason both exist: "the source answered and had nothing" and "the source did not answer" are
     * different diagnoses, so each gets its own streak and neither extends the other.
     *
     * @param list<array{ok:int|string, ...}> $runs
     */
    private static function trailingFailedRuns(array $runs): int
    {
        $streak = 0;

        foreach (array_reverse($runs) as $run) {
            if ((int) $run['ok'] === 1) {
                break;
            }

            ++$streak;
        }

        return $streak;
    }

    /**
     * How many of the most recent runs succeeded and returned nothing.
     *
     * A FAILED run terminates the streak rather than extending it. An empty run and a failed run are
     * different diagnoses — the source answered and had nothing, versus the source did not answer —
     * and the failure has its own, louder rule above.
     *
     * @param list<array{item_count:int|string, ok:int|string, ...}> $runs
     */
    private static function trailingEmptyRuns(array $runs): int
    {
        $streak = 0;

        foreach (array_reverse($runs) as $run) {
            if ((int) $run['ok'] !== 1 || (int) $run['item_count'] !== 0) {
                break;
            }

            ++$streak;
        }

        return $streak;
    }

    /**
     * Mean item count over the successful runs in the `ROLLING_WINDOW_DAYS` window ending at (and
     * excluding) `$index`. Null when there is nothing in the window to average — which is a
     * different fact from a mean of zero, and callers must treat it as such.
     *
     * @param list<array{item_count:int|string, ok:int|string, at_epoch:int|string, ...}> $runs
     */
    private static function rollingMeanBefore(array $runs, int $index): ?float
    {
        if ($index <= 0 || $index >= \count($runs)) {
            return null;
        }

        $reference = (int) $runs[$index]['at_epoch'];
        $cutoff = $reference - self::ROLLING_WINDOW_DAYS * 86400;
        $counts = [];

        // SKIP rather than stop, and bound the window at BOTH ends. The rows are in insertion order,
        // not timestamp order, so a single out-of-range timestamp would otherwise end the scan early
        // (`break`) or sit inside the window forever (no upper bound) — a run dated 2036 inflating
        // the mean of every later verdict.
        for ($i = $index - 1; $i >= 0; --$i) {
            $at = (int) $runs[$i]['at_epoch'];

            if ($at < $cutoff || $at > $reference) {
                continue;
            }

            if ((int) $runs[$i]['ok'] === 1) {
                $counts[] = (int) $runs[$i]['item_count'];
            }
        }

        return $counts === [] ? null : array_sum($counts) / \count($counts);
    }

    /**
     * Item count of the most recent run before `$index` that actually PRODUCED something, of any
     * age. Zero when the source has never produced anything — used only as the fallback baseline
     * when the rolling window is empty.
     *
     * "Most recent SUCCESSFUL run" was the first attempt and it was one step too shallow: a single
     * successful-but-empty run sitting between the productive history and the streak set the
     * baseline to zero, so a source with a 25-listing history went silent for three runs and
     * reported `OK`. A quiet run is not evidence that nothing is normal here; a productive one is
     * evidence that something was.
     *
     * @param list<array{item_count:int|string, ok:int|string, ...}> $runs
     */
    private static function lastProductiveCount(array $runs, int $index): float
    {
        for ($i = min($index, \count($runs)) - 1; $i >= 0; --$i) {
            if ((int) $runs[$i]['ok'] === 1 && (int) $runs[$i]['item_count'] > 0) {
                return (float) (int) $runs[$i]['item_count'];
            }
        }

        return 0.0;
    }

    /**
     * Total and failed runs within the rolling window ending at the most recent run, inclusive.
     *
     * @param  list<array{ok:int|string, at_epoch:int|string, ...}> $runs
     * @return array{int, int}
     */
    private static function windowCounts(array $runs, ?int $now, int $windowDays = self::ROLLING_WINDOW_DAYS): array
    {
        // Anchored on the LAST-INSERTED row, deliberately, and NOT on `max(at_epoch)`.
        //
        // Both choices lose the MEAN when a row is forward-skewed, and they lose it for different
        // lengths of time. Anchoring on the last row loses it until the next real run; anchoring on
        // the maximum loses it FOREVER, because the skewed row stays the maximum and every real row
        // sits outside its window. A bounded degradation beats a permanent one, so this reads the
        // log's own order for the same reason `health()` does.
        // THE UPPER EDGE OF THE WINDOW IS "NOW", AND ONLY A CLOCK KNOWS IT.
        //
        // Three attempts, each failing differently, so all three are recorded:
        //   bounded by the last-INSERTED row's stamp — a writer stamping from a lagging `Date:`
        //     header hid eleven consecutive real failures, because every newer-stamped row fell
        //     outside the window and `failedRunsInWindow` read 0 of 11.
        //   unbounded above — a future-stamped row never leaves the window. Twenty successes
        //     stamped a year ahead diluted a genuinely flaky source back to OK; ten failures
        //     stamped a year ahead alerted permanently through ninety healthy days, with a detail
        //     line reading "10 échecs sur 18 runs en 7 jours" when the last seven days held none.
        //   bounded by `$now` — correct in both, because a row stamped after the current time has
        //     not happened yet. That is the whole reason `health()` takes a clock.
        //
        // Without a clock we fall back to the last-inserted stamp: the first failure above, bounded
        // and self-correcting, rather than the second, unbounded in both directions. `CLAUDE.md`
        // requires `scout doctor` to pass one.
        $edge = $now ?? (int) $runs[array_key_last($runs)]['at_epoch'];
        $cutoff = $edge - $windowDays * 86400;
        $total = 0;
        $failed = 0;

        for ($i = \count($runs) - 1; $i >= 0; --$i) {
            $at = (int) $runs[$i]['at_epoch'];

            if ($at < $cutoff || $at > $edge) {
                continue;
            }

            ++$total;

            if ((int) $runs[$i]['ok'] !== 1) {
                ++$failed;
            }
        }

        return [$total, $failed];
    }

    /**
     * The newest reported feed date, compared as INSTANTS.
     *
     * `max()` over these strings is a LEXICAL comparison and the store accepts any RFC 3339 offset —
     * its own CLI test writes `+02:00`. Measured on the real store: `2026-08-28T23:00:00-12:00`
     * (the newer instant) loses to `2026-08-29T00:00:00+14:00` (25 h older), so a feed one hour old
     * was reported as one day silent, naming the wrong message. The error is one-directional — the
     * lexical max is never a later instant than the true max — so it can only ever OVER-state
     * silence, which is the safe direction and why this was P2 rather than P0.
     *
     * @param list<string> $dates
     */
    private static function newestFeedDate(array $dates): ?string
    {
        $best = null;

        foreach ($dates as $date) {
            if ($best === null || self::epoch($date) > self::epoch($best)) {
                $best = $date;
            }
        }

        return $best;
    }

    /**
     * The newest reported feed date that is not implausibly in the future, or `null`.
     *
     * Future dates are FILTERED rather than clamped: the value is a maximum over what portals stamp
     * on their own mail, so one message dated 2030 would win that maximum and report the feed fresh
     * for ever, and clamping it to now makes it read fresher still. Both the `FEED_SILENT` verdict
     * and the `BROKEN` detail read through here, because a detail line that names a date the verdict
     * refused to believe is a diagnostic that contradicts its own conclusion.
     *
     * @param list<string> $dates
     */
    private static function newestCredibleFeedDate(array $dates, ?string $nowIso): ?string
    {
        if ($nowIso === null) {
            return self::newestFeedDate($dates);
        }

        $cutoff = self::epoch($nowIso) + self::FEED_CLOCK_GRACE_SECONDS;

        return self::newestFeedDate(array_values(array_filter(
            $dates,
            static fn (string $at): bool => self::epoch($at) <= $cutoff,
        )));
    }

    public static function epoch(string $iso): int
    {
        $normalised = str_ends_with($iso, 'Z') ? substr($iso, 0, -1) . '+00:00' : $iso;

        // RFC 3339 §4.3 permits `-00:00`; PHP renders the same instant as `+00:00`, so the
        // round-trip below would reject it on spelling alone.
        if (str_ends_with($normalised, '-00:00')) {
            $normalised = substr($normalised, 0, -6) . '+00:00';
        }

        // RFC 3339 `time-secfrac` is `"." 1*DIGIT` — ANY number of digits — and Go's RFC3339Nano
        // trims trailing zeros, so a Go-backed JSON feed emits `.1Z` for `.100`. Padding to the six
        // digits PHP round-trips accepts every width instead of the two that happened to be tried.
        $normalised = preg_replace_callback(
            '~\.(\d+)(?=[+\-]\d{2}:\d{2}$)~',
            // Pad up to six and TRUNCATE beyond it. `{1,6}` accepted widths 1–6 and refused 7–9 —
            // precisely .NET's `o` format (7) and Go's RFC3339Nano at full precision (9), the
            // producer the comment above names. Sub-microsecond precision is not information this
            // store can use, so discarding it is correct rather than lossy.
            static fn (array $m): string => '.' . substr(str_pad($m[1], 6, '0'), 0, 6),
            $normalised,
        ) ?? $normalised;

        foreach ([\DateTimeInterface::ATOM, 'Y-m-d\TH:i:s.uP'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat($format, $normalised);

            // The round-trip is what makes this strict rather than merely parseable:
            // `createFromFormat` silently normalises `2026-02-30T09:00:00+00:00` to 2 March.
            if ($parsed !== false && $parsed->format($format) === $normalised) {
                return $parsed->getTimestamp();
            }
        }

        throw new \InvalidArgumentException(sprintf('horodatage ISO-8601 illisible : %s', $iso));
    }
}
