<?php

declare(strict_types=1);

namespace Scout\Rent\Store;

use Scout\Rent\Core\Dedup;
use Scout\Rent\Core\ExcludedDwellings;
use Scout\Rent\Core\ListingSnapshot;
use Scout\Rent\Core\RawListing;
use Scout\Core\Redact;
use Scout\Core\RunStore;
use Scout\Core\SourceHealth;
use Scout\Core\SourceStatus;
use Scout\Rent\Core\Tenure;
use Scout\Core\Text;

/**
 * The seen-set, the price history and the run log — everything that must survive between runs.
 *
 * This class carries two of the three data sets `CLAUDE.md` § "Credentials & stateful data" says
 * must not be casually deleted, and both fail silently in the direction that hurts:
 *
 * - Lose the **seen-set** and the next run re-notifies the entire market at once. The user does not
 *   get an error; they get two hundred notifications about flats they already rejected, and they
 *   turn the tool off.
 * - Lose the **price history** and it cannot be reconstructed, because a listing only ever shows
 *   its current rent. A rent drop is a notification-worthy event (spec §7); the evidence that one
 *   happened is exactly the thing that was lost.
 *
 * The **run log** is the third: it is what makes `CLAUDE.md` hard rule 2 enforceable. Without a
 * persisted baseline there is no way to tell a broken selector from a quiet market, and the broken
 * selector is the one that reports success forever.
 *
 * Timestamps are passed in as ISO-8601 strings rather than read from the clock. That is not
 * ceremony: health is a function of run history over time, and a store that reads `now()` internally
 * can only be tested by waiting. The cost of that choice is that a caller can supply nonsense, so
 * every timestamp entering this class is parsed strictly — and {@see health()} takes an optional
 * clock, which is the only thing that can tell a skewed timestamp from a correct one. The run log
 * itself refuses nothing: an earlier version kept it monotonic, and that deleted the runs it
 * rejected rather than fixing anything.
 */
final readonly class Store
{
    /**
     * Bumped whenever the schema changes; {@see migrate()} upgrades from older and refuses newer.
     *
     * Version 2 added `listings.seen_epoch`. It was added at version 1 by mistake, in the very
     * commit that introduced the mismatch refusal — so a database written the day before opened
     * without complaint and then threw a raw `no such column` at the first sighting. That is the
     * whole argument for this constant existing, demonstrated against itself.
     */
    public const int SCHEMA_VERSION = 12;

    /**
     * How many undelivered digest rows one `scout digest` may carry.
     *
     * **The query was unbounded and the send is all-or-nothing**, so any rejection that is a
     * function of payload size was permanently self-perpetuating: the batch that failed came back
     * next time with more rows in it, and the *à vérifier* bin — §1's only landing zone — became
     * undeliverable for good while the command printed one warning to a log nobody reads. Measured
     * by a review panel on 2026-08-24 at ~95 bytes per entry, linear and unbounded.
     *
     * A cap turns that into a drain: each run clears up to this many and says what is left, so a
     * backlog shrinks instead of hardening.
     *
     * **50 is a JUDGEMENT, and the first version of this docblock dressed it as a measurement** —
     * it said 50 "clears the smallest limit this project's channels are documented to have", and no
     * such limit is documented anywhere in the tree. A review panel grepped for one and found
     * nothing. The honest basis: at the ~95 bytes per entry measured on the real `Formatter`, 50 is
     * ~4.7 KB — small enough to sit under the body limits push services typically impose, large
     * enough that a real backlog empties in a few runs. If a channel ever states a limit, THAT is
     * the number to derive this from.
     */
    public const int DIGEST_BATCH = 50;






    /**
     * How long a second writer waits before giving up, in milliseconds.
     *
     * `scout run --watch` alongside a manual `scout doctor` is the spec's own target usage, and in
     * the default rollback-journal mode with no timeout the second process fails INSTANTLY with
     * `SQLSTATE[HY000]: General error: 5 database is locked` rather than waiting — reproduced by a
     * reviewer on 2026-08-07, and pinned by {@see StoreTest::testASecondWriterWaitsRatherThanFailing}.
     * The root cause is not "SQLite is flaky": it is that two processes are a designed part of the
     * product, so waiting is the correct behaviour rather than a retry papered over a race.
     */
    public const int BUSY_TIMEOUT_MS = 5000;

    /**
     * The confidence a reading needs before it may resolve a recorded twin DOUBT (COR-F5).
     *
     * **It is §1's own fail-closed threshold, not a new number.** Below 0.6 a classification on a
     * mixed-stock source is already `UNKNOWN` and goes to the digest; the same bar decides whether
     * a reading is strong enough to overwrite a doubt the other track raised. A tier-5 source
     * default sits at 50 and is refused. Every tier-1 structured field and tier-2 explicit label is
     * 90 and passes.
     *
     * Deriving it from the classifier's own threshold rather than storing a TIER on the row is
     * deliberate: a tier column would be a second encoding of the same fact, free to drift from the
     * first, and `twin_tenure` has no room for one without a migration.
     *
     * @see recordTwin() for the direction this gates, and the two it deliberately does not
     */
    public const int TWIN_DOUBT_MIN_CONFIDENCE = 60;


    private function __construct(
        private \PDO $pdo,
        /**
         * The run log and source health, which belong to no domain.
         *
         * COMPOSED rather than inherited, and it shares this store's PDO handle rather than opening
         * the file again — under WAL a second connection is how a process contends with itself.
         */
        private RunStore $runs,
        /**
         * How many days of portal silence make a still-producing source {@see SourceStatus::FEED_SILENT}.
         *
         * Carried on the store rather than threaded through {@see Source::health()} because every
         * adapter delegates health here and none of them has an opinion about the threshold — it is
         * an operator setting, not a property of a source. `null` disables the verdict entirely,
         * which is what keeps every existing caller and every non-email source unchanged.
         */
        private ?int $feedSilentDays = null,
    ) {}

    /**
     * The cached door-to-door commute for a COMMUNE, or `null` when nothing is cached.
     *
     * `null` means NOT LOOKED UP, never "unreachable" — {@see rememberCommute()} is only called on a
     * successful resolution, deliberately. Caching a failure would turn one bad afternoon at the API
     * into a permanently missing score component, and the component is the largest in the tree.
     */
    public function cachedCommuteMinutes(string $communeKey, string $postcode, string $destination): ?int
    {
        // THE DESTINATION IS PART OF THE KEY, and it is the whole reason this cannot be a plain
        // lookup. A commute is minutes BETWEEN TWO PLACES; cache it against one of them and the day
        // the other changes -- a new job, a moved office -- every row goes on answering with the
        // journey to the old address. Nothing would say so: the numbers stay plausible, the reasons
        // stay confident, failures are deliberately not cached and nothing expires. A mismatch reads
        // as NOT CACHED, so the commune re-resolves lazily and overwrites.
        $stmt = $this->pdo->prepare(
            'SELECT minutes FROM commute_cache'
            . ' WHERE commune_key = :k AND postcode = :p AND destination = :d',
        );
        $stmt->execute(['k' => $communeKey, 'p' => $postcode, 'd' => $destination]);
        $value = $stmt->fetchColumn();

        return is_int($value) || (is_string($value) && $value !== '') ? (int) $value : null;
    }

    /**
     * Record a resolved commune. Called ONLY on success — see {@see cachedCommuteMinutes()}.
     *
     * The coordinates are stored alongside the minutes even though nothing reads them back yet: they
     * are what was actually resolved, so a wrong journey can be diagnosed without re-querying, and a
     * future re-computation (a moved destination, a new timetable) does not need the geocode again.
     */
    public function rememberCommute(
        string $communeKey,
        string $postcode,
        ?float $lat,
        ?float $lon,
        int $minutes,
        string $nowIso,
        string $destination,
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO commute_cache'
            . ' (commune_key, postcode, lat, lon, minutes, resolved_at, destination)'
            . ' VALUES (:k, :p, :lat, :lon, :m, :at, :d)'
            . ' ON CONFLICT (commune_key, postcode) DO UPDATE SET'
            . ' lat = excluded.lat, lon = excluded.lon, minutes = excluded.minutes,'
            . ' resolved_at = excluded.resolved_at, destination = excluded.destination',
        );
        $stmt->execute([
            'k' => $communeKey,
            'p' => $postcode,
            'lat' => $lat,
            'lon' => $lon,
            'm' => $minutes,
            'at' => $nowIso,
            'd' => $destination,
        ]);
    }

    /** The journal mode SQLite actually gave us. Delegated — see {@see health()}. */
    public function journalMode(): string
    {
        return $this->runs->journalMode();
    }

    /**
     * Open (and create, if needed) the database at `$path`. Pass `:memory:` for a throwaway one.
     *
     * @throws \RuntimeException if the containing directory cannot be created
     */
    public static function open(string $path, ?int $feedSilentDays = null): self
    {
        if ($path !== ':memory:') {
            $directory = \dirname($path);

            if (!is_dir($directory)) {
                // Checked before `mkdir` rather than after, so the common misconfiguration — a
                // database path whose parent is a regular file — raises this class's own message
                // instead of a PHP warning followed by a confusing PDO error.
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
        // `journal_mode` is a QUERY pragma: it returns the mode that actually took effect, and
        // SQLite does not raise when it refuses. A network mount without shared-memory support
        // silently stays in rollback mode, and `exec()` discards the answer — so the mode is read
        // back and kept, rather than assumed. It is not fatal (`:memory:` legitimately answers
        // `memory`), but it must be visible: `scout doctor` reports it.
        $mode = $pdo->query('PRAGMA journal_mode = WAL');
        $journalMode = (string) $mode->fetchColumn();
        // An unconsumed result set counts as a statement in progress and makes the FIRST COMMIT of
        // the migration below fail with "cannot commit transaction - SQL statements in progress".
        $mode?->closeCursor();

        $store = new self($pdo, RunStore::fromPdo($pdo, $journalMode, $feedSilentDays), $feedSilentDays);
        $store->migrate();

        return $store;
    }

    /**
     * Has this store ever recorded a listing? The fact the Q36 flood guard reads before `scout run`
     * is allowed to notify anything.
     *
     * Q8 rules out GitHub Actions *because* no persistent disk means re-notifying everything, then
     * adopts Docker-on-a-VPS, which has the identical failure mode: a typo in `-v`, or a volume
     * that fails to reattach on reboot, produces a valid, empty, migrated database indistinguishable
     * from a healthy one — and with nothing batched, every historic listing pushes at once.
     *
     * The question used to be "did {@see open()} CREATE this file?", which is a DIFFERENT fact and
     * a fragile one: any earlier command that merely opened the database answered it for good.
     * `scout doctor` opens it, so typing the one command a new machine invites you to type disarmed
     * the guard for the following run. Rows survive that; a per-process flag does not.
     *
     * `:memory:` is deliberately NOT exempt, though it was under the old fact. An in-memory store
     * starts empty on every invocation, so it would re-notify the whole market every pass — that is
     * the flood itself, not an edge case around it.
     */
    public function isSeenSetEmpty(): bool
    {
        // EXISTS rather than COUNT: SQLite stops at the first row instead of walking the table, and
        // the answer is a yes/no anyway.
        return (int) $this->pdo->query('SELECT EXISTS (SELECT 1 FROM listings)')->fetchColumn() === 0;
    }

    /**
     * Create the schema if it is absent. Idempotent — running it twice is a no-op, not an error.
     *
     * An OLDER database is upgraded by {@see upgradeFrom()}; a NEWER one is refused, because this
     * code cannot know what a future version means. Both directions used to refuse, and the older
     * half of that was wrong: `CREATE TABLE IF NOT EXISTS` adds no columns to an existing table and
     * nothing re-stamped `schema_meta`, so refusing was the only signal a user would ever get and
     * they would get it forever, with no path forward.
     *
     * A database with no recorded version at all is treated as version 1 and upgraded, not stamped
     * current — that is the state a crash between the DDL and the version INSERT leaves, and it is
     * indistinguishable from a legacy database.
     */
    public function migrate(): void
    {
        $this->pdo->exec(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS schema_meta (
                key   TEXT PRIMARY KEY,
                value TEXT NOT NULL
            );

            CREATE TABLE IF NOT EXISTS listings (
                dedup_key     TEXT PRIMARY KEY,
                source        TEXT NOT NULL,
                external_id   TEXT NOT NULL,
                url           TEXT,
                title         TEXT NOT NULL,
                rent_cc       INTEGER,
                first_seen_at TEXT NOT NULL,
                last_seen_at  TEXT NOT NULL,
                seen_epoch    INTEGER NOT NULL,
                notified_at   TEXT,
                -- Schema v8. WHAT the listing was last announced AS, because `notified_at` alone
                -- was answering two different questions with one value. Being carried in a
                -- delivered digest set it, and the match path read it as "already told about this
                -- listing" -- so a listing promoted DIGEST -> MATCH on a later pass was silently
                -- swallowed, while the pass summary counted the match. Nothing could reach it
                -- afterwards either: the same pass overwrote `tenure` (out of `staleVerdicts()`)
                -- and `outcome` (out of `pendingDigest()`), and there is no third selector.
                --
                -- Monotone: DIGEST < MATCH. A row announced as a match is never re-announced as a
                -- doubt. NULL on a pre-v8 row means "announced under a version that did not record
                -- what it said", and is NOT backfilled -- same precedent as `tenure`, `group_key`
                -- and `evidence_json`. `wasNotifiedAs()` reads a NULL-with-a-timestamp as MATCH,
                -- which is the fail-quiet direction for the backlog: it re-announces nothing.
                notified_as   TEXT,
                -- Schema v3 (Q24). A listing stored under an old classifier cannot be re-evaluated
                -- or explained without re-fetching it, and by then the source may have removed the
                -- ad. Combined with the seen-set's "new exactly once" guarantee, a listing digested
                -- as UNKNOWN under a classifier that is later improved is a PERMANENT silent miss —
                -- and Q18 (PLI) and Q21 (shouted PLUS) both route there deliberately, so that bin
                -- will not be small. `scout reclassify` is what consumes these.
                tenure          TEXT,
                confidence_bp   INTEGER,
                signals_json    TEXT,
                -- Schema v4, ruled 2026-08-19. Ties the members of a dedup cluster together so a
                -- flat listed on two portals has ONE readable price history. It is a HISTORY
                -- concept and nothing else: `dedup_key` stays per-source, `price_history` rows stay
                -- attached to the source that observed them, and `notified_at` is never consulted
                -- through the group. A group-scoped notification gate would permanently suppress
                -- the second listing once two members stop clustering — under-merge notifies twice,
                -- which is visible and self-correcting; over-merge hides a flat, which is silent.
                --
                -- NULL means "never clustered under a version that recorded it" and is NOT
                -- backfilled, for the same reason `tenure` is not: a backfilled self-group would be
                -- indistinguishable from a real one, and that is the distinction the queries need.
                group_key       TEXT,

                -- Schema v7. THE EVIDENCE THE VERDICT WAS FORMED FROM, frozen so it can be
                -- re-judged without re-fetching an ad the source may have removed.
                --
                -- This is a §1 surface, not an audit nicety. `scout reclassify` re-runs an
                -- improved classifier over UNKNOWN rows, and running it on LESS evidence than the
                -- original saw does not make a smaller improvement — it makes a BREACH: a card
                -- whose field says PLS while its title says `logement intermédiaire` classifies
                -- UNKNOWN today by conflict, and re-run on the title alone it becomes a MATCH.
                -- The invariant is `evidence ⊇ original, never ⊂`, and this column is what makes
                -- it checkable rather than hoped for.
                --
                -- Holds the MAPPED RawListing, after any detail merge — never the pre-map payload,
                -- whose re-reading would make a stored verdict depend on ListingMapper code that
                -- has since changed. Same drift `map_fingerprint` catches one layer down.
                --
                -- NOT backfilled, like `tenure` and `group_key` before it: an invented snapshot is
                -- indistinguishable from a real capture, and telling those apart is the whole job.
                evidence_json   TEXT,

                -- Schema v7. What the criteria engine JUDGED this listing to be.
                --
                -- `scout digest` needs to find what was digested and never delivered by reading
                -- the STORE, because the pipeline retries only while the listing stays published
                -- — a digest entry whose ad is delisted between passes is otherwise lost with
                -- nothing anywhere saying so.
                --
                -- It CANNOT be re-derived from `tenure`: the engine can REJECT on a hard
                -- disqualifier before the tenure branch is ever reached, so UNKNOWN does not mean
                -- `was digested`. Deriving it would surface listings the criteria threw out, into
                -- the one channel §1 uses as its landing zone.
                --
                -- NULL means never judged, which is the honest state of an absorbed dedup member:
                -- every member is recorded and classified, only the survivor is judged.
                outcome         TEXT
            );

            -- `listings_group` is deliberately NOT here, and this is not an oversight. On an
            -- existing v3 database `CREATE TABLE IF NOT EXISTS` skips the table, so `group_key` does
            -- not exist yet and indexing it fails the whole DDL before `upgradeFrom()` can add the
            -- column. It is created in the v4 step instead, after the ALTER — which a brand-new
            -- database also runs, because a fresh store is stamped 1 and upgraded rather than
            -- stamped current.
            CREATE INDEX IF NOT EXISTS listings_source ON listings (source);

            CREATE TABLE IF NOT EXISTS price_history (
                id        INTEGER PRIMARY KEY AUTOINCREMENT,
                dedup_key TEXT NOT NULL REFERENCES listings (dedup_key),
                rent_cc   INTEGER NOT NULL,
                at        TEXT NOT NULL,
                at_epoch  INTEGER NOT NULL
            );

            CREATE INDEX IF NOT EXISTS price_history_key ON price_history (dedup_key, at_epoch, id);

            -- Schema v5. What a listing's own detail page said, so it is read ONCE.
            --
            -- Keyed on (source, external_id) and NOT on dedup_key: cache what was OBSERVED, never
            -- what was CONCLUDED. `dedupKey()` normalises, normalisation evolves, and a row keyed
            -- on it would silently orphan the entire cache the day it changes -- which presents as
            -- every listing being re-fetched on every pass, i.e. the crawl this table exists to
            -- prevent, with no symptom but somebody else's access log.
            --
            -- A row appears on the first ATTEMPT, not the first success, because the failure has to
            -- be expressible: fields_json NULL with attempts > 0 is "tried and could not read it",
            -- which is a different fact from "read it and it said nothing" (fields_json '{}'). If
            -- those collapse, a detail page that 404s becomes a listing that merely has no floor,
            -- for ever, while the source's health stays green.
            -- v9. One row per COMMUNE, not per listing: a commune's coordinates and its journey
            -- to the configured destination are properties of the place, so 83 daily matches across
            -- ~40 communes cost ~40 lookups once rather than 166 every pass.
            --
            -- Keyed on a NORMALISED commune plus its postcode. Normalised because the same commune
            -- arrives spelled two ways in one response (`Les Clayes-sous-Bois` / `les clayes sous
            -- bois`, observed on Logirep); postcode included because commune names repeat across
            -- departements and a wrong coordinate would mis-score a whole town silently.
            CREATE TABLE IF NOT EXISTS commute_cache (
                commune_key TEXT NOT NULL,
                postcode    TEXT NOT NULL,
                lat         REAL,
                lon         REAL,
                minutes     INTEGER,
                resolved_at TEXT,
                -- v10. WHICH DESTINATION these minutes are to. Without it the cache answers with
                -- the journey to a PREVIOUS address for ever the day the destination changes --
                -- plausible numbers, confident reasons, nothing anywhere saying they are stale.
                -- Same guarantee as the schema-v6 detail-map fingerprint, and the same failure it
                -- was built to stop.
                destination TEXT,
                PRIMARY KEY (commune_key, postcode)
            );

            CREATE TABLE IF NOT EXISTS listing_detail (
                source          TEXT NOT NULL,
                external_id     TEXT NOT NULL,
                url_fetched     TEXT,
                -- The RAW extracted strings, so a ListingMapper improvement reaches rows captured
                -- before it existed. Same reasoning as the stored tenure verdict.
                fields_json     TEXT,
                fetched_at      TEXT,
                attempts        INTEGER NOT NULL DEFAULT 0,
                last_attempt_at TEXT,
                -- Redact::text() applied before it is written: a detail-fetch failure naturally
                -- carries the URL it failed on.
                last_error      TEXT,
                -- Which detail_map produced `fields_json`. A row whose fingerprint no longer
                -- matches the live map reads as ABSENT, so it is refetched through the ordinary
                -- budget and priority path. Without it, adding a field to a map leaves every
                -- already-hydrated row serving the OLD fields for ever -- no refetch, no error, no
                -- signal, and a config that claims to collect what it never will.
                --
                -- NOT part of the primary key, deliberately: keying on it would orphan the whole
                -- cache on every map edit and grow the table one row per map version, where what is
                -- wanted is to refresh the row that exists.
                map_fingerprint TEXT,
                PRIMARY KEY (source, external_id)
            );
            SQL
        );

        // The generic tables AND their own version bookkeeping, from their single definition.
        //
        // `RunStore::migrate()` rather than `RunStore::ddl()`, and the difference is not cosmetic:
        // `ddl()` creates the tables, `migrate()` also records the run log's own schema version in
        // `run_meta`. Calling only `ddl()` shipped, passed 2536 tests, and left the LIVE rent
        // database with no record of the generic version at all — found by querying production after
        // the deploy, not by any test. A future `RunStore` v2 would then never migrate this file,
        // silently, because nothing here would know what version it was at.
        //
        // Placed ABOVE this method's own early return (see the `$recorded` check below), so it
        // reaches a database already at the current rent version — which every live one is.
        $this->runs->migrate();

        $recorded = $this->schemaVersionOrNull();

        if ($recorded === null) {
            $statement = $this->pdo->prepare('INSERT INTO schema_meta (key, value) VALUES (:key, :value)');
            $statement->execute(['key' => 'schema_version', 'value' => (string) '1']);

            // Deliberately stamped 1 and then upgraded, rather than stamped 2 and trusted. A
            // database whose `schema_meta` exists but carries no version row is the state a crash
            // between the DDL and the INSERT leaves — those are two separate autocommit statements
            // — and it is indistinguishable from a legacy v1 database. Stamping it 2 and returning
            // meant `CREATE TABLE IF NOT EXISTS` skipped the existing table, `schemaVersion()`
            // answered 2, and the first sighting threw a raw `no such column`. `upgradeFrom()` is
            // re-runnable by construction, so running it on a genuinely new database costs nothing.
            $recorded = 1;
        }

        if ($recorded === self::SCHEMA_VERSION) {
            return;
        }

        if ($recorded > self::SCHEMA_VERSION) {
            throw new \RuntimeException(sprintf(
                'base en version %d, ce code ne connaît que la version %d — mettez le code à jour',
                $recorded,
                self::SCHEMA_VERSION,
            ));
        }

        $this->upgradeFrom($recorded);
    }

    /**
     * Bring an older database up to `SCHEMA_VERSION`, in one transaction.
     *
     * Each step is written so that running it on a database that already has the change is not an
     * error, because a migration that half-applied and then died must be re-runnable — and the data
     * it operates on is the seen-set, which cannot be rebuilt from anywhere.
     */
    private function upgradeFrom(int $recorded): void
    {
        $this->pdo->exec('BEGIN IMMEDIATE');

        try {
            if ($recorded < 2) {
                $columns = $this->pdo->query('PRAGMA table_info(listings)');
                $names = array_column($columns->fetchAll(), 'name');

                if (!\in_array('seen_epoch', $names, true)) {
                    $this->pdo->exec('ALTER TABLE listings ADD COLUMN seen_epoch INTEGER NOT NULL DEFAULT 0');
                }

                // Backfill from the timestamp that was already there, so an upgraded database does
                // not treat every stored listing as older than every incoming sighting.
                $rows = $this->pdo->query('SELECT dedup_key, last_seen_at FROM listings WHERE seen_epoch = 0');
                $update = $this->pdo->prepare('UPDATE listings SET seen_epoch = :epoch WHERE dedup_key = :key');

                foreach ($rows->fetchAll() as $row) {
                    try {
                        $epoch = self::epoch((string) $row['last_seen_at']);
                    } catch (\InvalidArgumentException) {
                        // Treat an undateable row as the OLDEST thing on record rather than
                        // refusing to open the database at all. This is not a swallowed error: the
                        // failure mode was demonstrated — one hand-edited or merged row made
                        // `Store::open()` throw permanently, with no repair path, on the one data
                        // set `CLAUDE.md` says cannot be rebuilt. Epoch 0 costs one redundant
                        // overwrite on the next sighting; the alternative costs the seen-set.
                        $epoch = 0;
                    }

                    $update->execute(['epoch' => $epoch, 'key' => (string) $row['dedup_key']]);
                }
            }

            if ($recorded < 3) {
                // Additive only, and every step re-runnable — a migration that half-applied and
                // then died must be safe to run again, because the data underneath is the seen-set
                // and it cannot be rebuilt from anywhere.
                foreach ([
                    'listings' => ['tenure' => 'TEXT', 'confidence_bp' => 'INTEGER', 'signals_json' => 'TEXT'],
                    'source_runs' => ['duration_ms' => 'INTEGER'],
                ] as $table => $columns) {
                    $existing = array_column($this->pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(), 'name');

                    foreach ($columns as $column => $type) {
                        if (!\in_array($column, $existing, true)) {
                            $this->pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $type);
                        }
                    }
                }

                // NOT backfilled, deliberately. There is no honest value for the tenure of a listing
                // classified before the column existed: writing UNKNOWN would be indistinguishable
                // from a real UNKNOWN verdict, and `scout reclassify` selects on exactly that
                // distinction. NULL means "never classified under a version that recorded it", which
                // is the truth and is what makes those rows selectable.
                $this->pdo->exec(
                    'CREATE TABLE IF NOT EXISTS source_alerts ('
                    . ' source TEXT NOT NULL, status TEXT NOT NULL, at TEXT NOT NULL,'
                    . ' at_epoch INTEGER NOT NULL, PRIMARY KEY (source, status))',
                );
            }

            if ($recorded < 4) {
                // Additive and re-runnable, like every step above it. NOT backfilled: see the DDL
                // comment on the column — a self-group written here would be indistinguishable from
                // a cluster that genuinely has one member, and `groupPriceHistory()` tells those
                // apart by exactly this NULL.
                $existing = array_column($this->pdo->query('PRAGMA table_info(listings)')->fetchAll(), 'name');

                if (!\in_array('group_key', $existing, true)) {
                    $this->pdo->exec('ALTER TABLE listings ADD COLUMN group_key TEXT');
                }

                $this->pdo->exec('CREATE INDEX IF NOT EXISTS listings_group ON listings (group_key)');
            }

            if ($recorded < 5) {
                // Additive and re-runnable, like every step above. Nothing to backfill and nothing
                // that COULD be: a listing already in the seen-set was never hydrated, and an empty
                // row would claim it was read and found bare. Absent is the truth, and it is what
                // makes those listings eligible for the backlog.
                $this->pdo->exec(
                    'CREATE TABLE IF NOT EXISTS listing_detail ('
                    . ' source TEXT NOT NULL, external_id TEXT NOT NULL, url_fetched TEXT,'
                    . ' fields_json TEXT, fetched_at TEXT, attempts INTEGER NOT NULL DEFAULT 0,'
                    . ' last_attempt_at TEXT, last_error TEXT,'
                    . ' PRIMARY KEY (source, external_id))',
                );
            }

            if ($recorded < 6) {
                // Additive and re-runnable. SQLite has no `ADD COLUMN IF NOT EXISTS`, so the column
                // list is read first -- a bare ALTER would throw on the second run and turn a
                // re-entrant migration into a fatal one.
                //
                // Existing rows get NULL, which no live fingerprint can equal, so every row
                // captured before this column existed is refetched exactly once. That is the
                // correct default: those rows were captured under an unknown map.
                $columns = $this->pdo->query('PRAGMA table_info(listing_detail)');
                $names = $columns === false ? [] : array_column($columns->fetchAll(\PDO::FETCH_ASSOC), 'name');

                if (!in_array('map_fingerprint', $names, true)) {
                    $this->pdo->exec('ALTER TABLE listing_detail ADD COLUMN map_fingerprint TEXT');
                }
            }

            if ($recorded < 7) {
                // Additive and re-runnable, like every step above. SQLite has no
                // `ADD COLUMN IF NOT EXISTS`, so the column list is read first — a bare ALTER throws
                // on the second run and turns a re-entrant migration into a fatal one.
                //
                // NOTHING IS BACKFILLED, and there is nothing that could be. A listing already in
                // the seen-set was judged by a version that recorded neither column, and inventing
                // an `evidence_json` for it would be indistinguishable from a real capture —
                // which is the exact distinction `reclassify` has to make in order to SKIP a row
                // rather than re-judge it on less than the original saw. NULL is the truth here,
                // and it is what makes those rows identifiable. Same precedent as `tenure` (v3)
                // and `group_key` (v4).
                $existing = array_column($this->pdo->query('PRAGMA table_info(listings)')->fetchAll(), 'name');

                foreach (['evidence_json' => 'TEXT', 'outcome' => 'TEXT'] as $column => $type) {
                    if (!\in_array($column, $existing, true)) {
                        $this->pdo->exec('ALTER TABLE listings ADD COLUMN ' . $column . ' ' . $type);
                    }
                }

                // `pendingDigest()` selects on exactly this pair, and it runs on every `scout
                // digest`. Without the index that is a full scan of the seen-set, which grows
                // without bound — the one table nothing ever prunes.
                $this->pdo->exec('CREATE INDEX IF NOT EXISTS listings_outcome ON listings (outcome, notified_at)');
            }

            if ($recorded < 8) {
                // Additive and re-runnable like every step above; the column list is read first
                // because SQLite has no `ADD COLUMN IF NOT EXISTS`.
                //
                // NOT BACKFILLED. A row already carrying `notified_at` was announced by a version
                // that did not record what it said, and writing 'DIGEST' into those would re-
                // announce every one of them as a match the moment its tenure resolved -- a flood
                // out of the seen-set, which is the failure Q36 exists to prevent. `wasNotifiedAs()`
                // therefore treats a NULL-with-a-timestamp as the strongest thing it could have
                // been, so the pre-v8 backlog stays quiet.
                $existing = array_column($this->pdo->query('PRAGMA table_info(listings)')->fetchAll(), 'name');

                if (!\in_array('notified_as', $existing, true)) {
                    $this->pdo->exec('ALTER TABLE listings ADD COLUMN notified_as TEXT');
                }
            }

            if ($recorded < 9) {
                // Additive and re-runnable, like every step above. Nothing to backfill: a commute
                // is computed from live data, and an empty row would claim a lookup happened.
                $this->pdo->exec(
                    'CREATE TABLE IF NOT EXISTS commute_cache ('
                    . ' commune_key TEXT NOT NULL, postcode TEXT NOT NULL,'
                    . ' lat REAL, lon REAL, minutes INTEGER, resolved_at TEXT,'
                    . ' PRIMARY KEY (commune_key, postcode))',
                );
            }

            if ($recorded < 10) {
                // Additive and re-runnable. Existing rows get NULL, which no live fingerprint can
                // equal, so every cached commune re-resolves once against the current destination
                // rather than serving a journey to whatever the address used to be.
                $existing = array_column(
                    $this->pdo->query('PRAGMA table_info(commute_cache)')->fetchAll(),
                    'name',
                );

                if (!\in_array('destination', $existing, true)) {
                    $this->pdo->exec('ALTER TABLE commute_cache ADD COLUMN destination TEXT');
                }
            }

            if ($recorded < 11) {
                // Additive and re-runnable. Holds the newest MESSAGE date the source saw on that
                // run, so `health()` can tell "the portal stopped sending" from "the market is
                // quiet" -- see `SourceStatus::FEED_SILENT`.
                //
                // NOT BACKFILLED, and there is nothing to backfill it FROM: a pre-v11 row never
                // recorded a message date, and inventing one would either manufacture an alert
                // across the entire historic run log or, worse, manufacture a reassurance. NULL
                // means "this run did not report a feed date", which is also what every non-email
                // source and every `FileMailbox` run reports, and it yields no verdict either way
                // (hard rule 9: unknown is not old).
                $existing = array_column($this->pdo->query('PRAGMA table_info(source_runs)')->fetchAll(), 'name');

                if (!\in_array('feed_newest_at', $existing, true)) {
                    $this->pdo->exec('ALTER TABLE source_runs ADD COLUMN feed_newest_at TEXT');
                }
            }

            if ($recorded < 12) {
                // Schema v12 (2026-08-30). What the OTHER track last said about this flat — the one
                // cross-track datum, beside identities/groups/histories that stay per track by
                // ruling. It exists because a veto that lived only in the pass's harvest lapsed the
                // moment the twin was not fetched: a review panel pushed a PLS flat's agency copy on
                // the pass after its landlord listing failed to load. NOT backfilled — a pre-v12 row
                // has no fact, which is the truth, and learns one the next time both routes are
                // judged together.
                $existing = array_column($this->pdo->query('PRAGMA table_info(listings)')->fetchAll(), 'name');

                if (!\in_array('twin_tenure', $existing, true)) {
                    $this->pdo->exec('ALTER TABLE listings ADD COLUMN twin_tenure TEXT');
                }
                if (!\in_array('twin_source', $existing, true)) {
                    $this->pdo->exec('ALTER TABLE listings ADD COLUMN twin_source TEXT');
                }
            }

            $stamp = $this->pdo->prepare("UPDATE schema_meta SET value = :value WHERE key = 'schema_version'");
            $stamp->execute(['value' => (string) self::SCHEMA_VERSION]);

            $this->pdo->exec('COMMIT');
        } catch (\Throwable $failure) {
            self::rollBackQuietly($this->pdo);

            throw $failure;
        }
    }

    public function schemaVersion(): int
    {
        $version = $this->schemaVersionOrNull();

        if ($version === null) {
            throw new \RuntimeException('la base n\'a pas de version de schéma — migrate() n\'a jamais tourné');
        }

        return $version;
    }

    // ── Identity ──────────────────────────────────────────────────────────────────────────────────

    /**
     * The stable identity of a listing WITHIN its source (spec §7).
     *
     * `(source, externalId)` when the source publishes an id, otherwise a hash of the normalised
     * `(url, title)`. The fallback is not a nicety: several institutional feeds expose no stable id,
     * and a key that changes between runs makes every listing look new on every run — the
     * notification storm again, arrived at from the other direction.
     *
     * Cross-portal deduplication (the same flat on two sites) is a DIFFERENT problem with a
     * different failure profile and does not belong here.
     *
     * @throws \InvalidArgumentException when the listing carries nothing identifying at all
     */
    public function dedupKey(RawListing $listing): string
    {
        // rawurlencode so neither component can contain the separator and forge another key.
        $source = rawurlencode(self::trimUnicode($listing->sourceName));
        $externalId = self::trimUnicode($listing->externalId);

        if ($externalId !== '') {
            return $source . ':id:' . rawurlencode($externalId);
        }

        $url = self::normaliseUrl($listing->url ?? '');
        $title = self::normaliseText($listing->title);

        // No id, no URL, no title. Every other failure in this class is loud, and this one is in
        // the worse direction: `sha256("\n")` is a perfectly plausible-looking key that EVERY such
        // listing would share, so the second one silently vanishes and the price history of the
        // first interleaves two flats' rents. A listing with nothing identifying is an adapter bug
        // (hard rule 3), and an adapter bug must not be absorbed here.
        if ($url === '' && $title === '') {
            throw new \InvalidArgumentException(sprintf(
                'annonce sans identifiant, sans URL et sans titre chez « %s » — l\'adaptateur n\'a rien extrait',
                $listing->sourceName,
            ));
        }

        return $source . ':h:' . hash('sha256', $url . "\n" . $title);
    }

    // ── Seen-set and price history ────────────────────────────────────────────────────────────────

    /**
     * Record a sighting and report what changed since the last one.
     *
     * `$rentCc` is passed separately from `$listing->rentCc` because the charges-comprises figure is
     * often DERIVED (`rentHc + charges`) by a layer above this one, and the store must compare like
     * with like — `CLAUDE.md` hard rule 9.
     *
     * **Out-of-order sightings are expected, not exceptional.** Email-alert ingestion is the primary
     * path (hard rule 4) and delivers out of publication order routinely — an initial backfill, a
     * provider-delayed alert. A stale sighting therefore never overwrites the current state, and it
     * is not a price drop, whatever the arithmetic says.
     *
     * Two baselines, and they are not interchangeable — see the comment in the body. The DELTA is
     * measured against what we currently believe; the changes-only HISTORY is measured against the
     * chronological neighbour. An earlier version of this docblock argued for using the
     * chronological one for both, and doing that swallowed a real drop; using the stored one for
     * both put a duplicate into a history that cannot be cleaned up. Neither collapse survives.
     *
     * @param string $atIso ISO-8601 timestamp of this sighting
     */
    public function record(RawListing $listing, ?int $rentCc, string $atIso): Sighting
    {
        $key = $this->dedupKey($listing);
        $epoch = self::epoch($atIso);

        // BEGIN IMMEDIATE, not PDO's deferred `beginTransaction()`. A deferred transaction takes
        // the write lock lazily, on its first write — and SQLite deliberately SKIPS the busy handler
        // there, because retrying inside an already-open transaction can only deadlock. So
        // `busy_timeout` did nothing for this method: a second writer failed in 0.2 ms rather than
        // waiting the configured five seconds. The constant claimed a behaviour that did not happen
        // until the test written to demonstrate it went red.
        $this->pdo->exec('BEGIN IMMEDIATE');

        try {
            $existing = $this->pdo->prepare('SELECT seen_epoch, rent_cc FROM listings WHERE dedup_key = :key');
            $existing->execute(['key' => $key]);
            $row = $existing->fetch();

            $isNew = $row === false;
            $isCurrent = $isNew || $epoch >= (int) $row['seen_epoch'];

            // TWO DIFFERENT QUESTIONS, and conflating them swallowed a real drop.
            //
            // "Has the rent changed since what we believe now?" is answered by the stored current
            // rent. "Where does this observation sit in the timeline?" is answered by the
            // chronological neighbours in `price_history` — which is CHANGES-ONLY, so it is not a
            // record of observations and cannot answer the first question. Reading the history for
            // a current sighting made 1000 → (late 900) → 900 report no drop at all, because the
            // backfilled 900 had become the predecessor of the real one.
            // THREE values, and collapsing any two of them has already caused a defect:
            //   $chronoBefore  — the rent recorded immediately BEFORE this instant. Governs the
            //                    changes-only invariant, and nothing else.
            //   $previousRentCc — what we believed the rent was. Governs the delta and the drop.
            // They differ exactly when a sighting arrives out of order, which the email path makes
            // routine. Using the delta's baseline for the append guard put a duplicate 900 into a
            // changes-only history that cannot be cleaned up afterwards.
            $chronoBefore = $this->rentBefore($key, $epoch);
            $previousRentCc = $isCurrent
                ? ($isNew || $row['rent_cc'] === null ? null : (int) $row['rent_cc'])
                : $chronoBefore;

            if ($isNew) {
                $insert = $this->pdo->prepare(
                    'INSERT INTO listings (dedup_key, source, external_id, url, title, rent_cc,
                                           first_seen_at, last_seen_at, seen_epoch)
                     VALUES (:key, :source, :external_id, :url, :title, :rent, :at, :at, :epoch)',
                );
                $insert->execute([
                    'key' => $key,
                    'source' => $listing->sourceName,
                    'external_id' => $listing->externalId,
                    'url' => $listing->url,
                    'title' => $listing->title,
                    'rent' => $rentCc,
                    'at' => $atIso,
                    'epoch' => $epoch,
                ]);
            } elseif (!$isCurrent) {
                // A stale sighting may FILL a hole, never overwrite. A run whose link selector broke
                // followed by a delayed alert that carries the link would otherwise leave the store
                // permanently without one, because the whole UPDATE was skipped — and the notify
                // layer needs the URL and the title to produce anything actionable.
                //
                // `first_seen_at` deliberately does NOT move backwards: it records when WE first saw
                // the listing, which is what a seen-set is for, not when it was published.
                $fill = $this->pdo->prepare(
                    'UPDATE listings
                        SET url     = COALESCE(NULLIF(url, \'\'), NULLIF(:url, \'\'), url),
                            title   = CASE WHEN title = \'\' THEN :title ELSE title END,
                            rent_cc = COALESCE(rent_cc, :rent)
                      WHERE dedup_key = :key',
                );
                $fill->execute([
                    'url' => $listing->url,
                    'title' => $listing->title,
                    'rent' => $rentCc,
                    'key' => $key,
                ]);
            } else {
                // COALESCE on all three, so a run whose field map partially broke does not erase
                // what we already knew. The rent case is the documented one; the url and title
                // cases were the same bug, unnoticed — a single sighting with a missed link
                // selector left the seen-set holding a listing with no URL and no title, which is
                // precisely the pair a notification needs to be actionable.
                $update = $this->pdo->prepare(
                    'UPDATE listings
                        SET last_seen_at = :at,
                            seen_epoch   = :epoch,
                            url          = COALESCE(NULLIF(:url, \'\'), url),
                            title        = COALESCE(NULLIF(:title, \'\'), title),
                            rent_cc      = COALESCE(:rent, rent_cc)
                      WHERE dedup_key = :key',
                );
                $update->execute([
                    'at' => $atIso,
                    'epoch' => $epoch,
                    'url' => $listing->url,
                    'title' => $listing->title,
                    'rent' => $rentCc,
                    'key' => $key,
                ]);
            }

            // Append only when this observation is a CHANGE — different from the rent recorded
            // before it, and not made redundant by the one recorded after it. The second half only
            // matters for out-of-order inserts, and without it the changes-only invariant breaks
            // silently: `priceHistory()` starts returning consecutive identical values.
            if ($rentCc !== null && $rentCc !== $chronoBefore && $rentCc !== $this->rentAfter($key, $epoch)) {
                $history = $this->pdo->prepare(
                    'INSERT INTO price_history (dedup_key, rent_cc, at, at_epoch) VALUES (:key, :rent, :at, :epoch)',
                );
                $history->execute(['key' => $key, 'rent' => $rentCc, 'at' => $atIso, 'epoch' => $epoch]);
            }

            $this->pdo->exec('COMMIT');
        } catch (\Throwable $failure) {
            // The two writes must agree. Half of this — a `listings` row asserting a rent with no
            // matching history row — leaves the one data set that cannot be reconstructed in a
            // state that reads as complete.
            self::rollBackQuietly($this->pdo);

            throw $failure;
        }

        // Null is not zero. An unknown rent on either side yields an unknown delta rather than a
        // reduction — treating `1000 → null` as a 1000-euro cut would fire the loudest notification
        // the system has on the least information it has ever had.
        $delta = ($rentCc !== null && $previousRentCc !== null) ? $rentCc - $previousRentCc : null;

        return new Sighting(
            dedupKey: $key,
            isNew: $isNew,
            isCurrent: $isCurrent,
            rentCc: $rentCc,
            previousRentCc: $previousRentCc,
            rentDeltaCc: $delta,
            // A SUPERSEDED observation is not a price drop, whatever its arithmetic says. A
            // delayed alert carrying an older, intermediate price made the store answer "yes,
            // dropped to 900" for a flat whose current rent it correctly believed to be 1000 — the
            // row was hardened against this and the verdict object was left exposed.
            isPriceDrop: $isCurrent && $delta !== null && $delta < 0,
        );
    }

    /**
     * Every rent this listing has been seen at, oldest first — changes only, append-only.
     *
     * @return list<int>
     */
    public function priceHistory(string $dedupKey): array
    {
        $statement = $this->pdo->prepare(
            'SELECT rent_cc FROM price_history WHERE dedup_key = :key ORDER BY at_epoch ASC, id ASC',
        );
        $statement->execute(['key' => $dedupKey]);

        return array_map(static fn (array $row): int => (int) $row['rent_cc'], $statement->fetchAll());
    }

    // ── The cross-portal group (schema v4, ruled 2026-08-19) ──────────────────────────────────────

    /** The group a listing belongs to, or null if it has never clustered with anything. */
    /**
     * The strongest EXCLUDED tenure held by any member of this listing's dedup group, or `null`.
     *
     * **`scout reclassify` undid the pipeline's cluster veto without this.** The pipeline judges a
     * cluster on its most restrictive member (`Pipeline::clusterClassification()`), but it stores
     * each member's OWN tenure and OWN snapshot — so a vetoed survivor whose card states no tenure
     * is left `tenure = 'UNKNOWN'`, `outcome = 'REJECT'`. `staleVerdicts()` selects on `tenure`
     * alone, so `reclassify` picked it up, re-judged it on its own snapshot, and the `PLS` that
     * caused the rejection was by construction OUTSIDE that snapshot. A review panel drove it end
     * to end on 2026-08-24 with the shipped pipeline and the shipped commands: the REJECT vanished
     * after one `reclassify`, and in the promotion case the row was pushed as a match while the
     * store still held `PLS` under its own `group_key`.
     *
     * That is the invariant `reclassify` is built on, read exactly: **it runs on evidence ⊇
     * original, never ⊂.** The cluster's evidence is part of the original.
     *
     * A listing that clustered alone has no `group_key` and returns `null` — the common case, and
     * one query short-circuits it.
     *
     * **Returns a `Tenure`, not a string, and that is the contract.** It returned the enum's value
     * and every caller re-parsed it and re-asked `isExcluded()` — a check that can never fail,
     * because this method only ever returns an excluded tenure. The sabotage ledger proved it: a
     * case written against the caller's re-check went undetected, since no input reaches it. Dead
     * safety code reads as a second line of defence and is not one; the type says it instead.
     */
    public function groupExcludedTenure(string $dedupKey): ?Tenure
    {
        $group = $this->groupKey($dedupKey);

        if ($group === null) {
            return null;
        }

        $statement = $this->pdo->prepare(
            'SELECT tenure FROM listings
              WHERE group_key = :group AND tenure IS NOT NULL
              ORDER BY confidence_bp DESC',
        );
        $statement->execute(['group' => $group]);

        /** @var list<array{tenure: string}> $rows */
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            // Refuses loudly on a corrupt value rather than reading it as "nothing said" — see
            // {@see decodeTenure()}. This is the group half of the same §1 fail-open.
            $tenure = self::decodeTenure((string) $row['tenure'], 'tenure', 'groupe ' . $group);

            if ($tenure !== null && $tenure->isExcluded()) {
                return $tenure;
            }
        }

        return null;
    }

    public function groupKey(string $dedupKey): ?string
    {
        $statement = $this->pdo->prepare('SELECT group_key FROM listings WHERE dedup_key = :key');
        $statement->execute(['key' => $dedupKey]);
        $row = $statement->fetch();

        return $row === false || $row['group_key'] === null ? null : (string) $row['group_key'];
    }

    /**
     * Tie the members of a dedup cluster together, and report the group they now share.
     *
     * **The key is STICKY, and that is the whole design.** `Dedup::cluster()` keeps the FIRST item
     * as survivor, ordering is the caller's, and `Core/Pacer` shuffles source order on every pass —
     * so survivorship flips routinely. Minting the key from whoever survived THIS pass would rename
     * the group each time the shuffle changed its mind, and a member that delisted in between would
     * keep a key nobody else carries. That orphaning is exactly the failure the ruling rejected a
     * read-only join for: a flat whose surviving portal changes loses its history at the one seam
     * worth notifying on. So an existing group is adopted, and a new one minted only when no member
     * has one.
     *
     * Two groups that meet are MERGED rather than left to disagree — reachable whenever a third
     * listing corroborates two that never clustered with each other, because `Dedup`'s tolerances
     * are pairwise and transitivity is not guaranteed.
     *
     * A group is not unmade once formed. A persisted union is permanent, which is ruled and accepted: the
     * blast radius is one presentation view, because per-source rows, per-source `priceHistory()`
     * and per-source drop detection are all untouched by it.
     *
     * @param list<string> $memberKeys cluster members, survivor first
     *
     * @throws \InvalidArgumentException if a key was never recorded — grouping a listing the store
     *                                   has never seen would silently create a group of one
     */
    public function assignGroup(array $memberKeys): ?string
    {
        $members = array_values(array_unique($memberKeys));

        // Checked BEFORE the singleton short-circuit, so an unknown key is refused whether or not it
        // happens to have company. A missing row here means the caller recorded nothing, and a group
        // quietly assembled from listings that were never stored is unobservable.
        $existing = [];

        foreach ($members as $key) {
            $statement = $this->pdo->prepare('SELECT group_key FROM listings WHERE dedup_key = :key');
            $statement->execute(['key' => $key]);
            $row = $statement->fetch();

            if ($row === false) {
                throw new \InvalidArgumentException(sprintf(
                    'annonce inconnue, impossible de la regrouper : %s',
                    $key,
                ));
            }

            $existing[$key] = $row['group_key'] === null ? null : (string) $row['group_key'];
        }

        // A cluster of one is not a group. NULL keeps "never clustered" distinguishable from
        // "clustered, and the others have gone" — `groupPriceHistory()` branches on precisely that.
        if (count($members) < 2) {
            return null;
        }

        $adopted = null;

        foreach ($members as $key) {
            if ($existing[$key] !== null) {
                $adopted = $existing[$key];
                break;
            }
        }

        // Minted from the survivor only when nothing to adopt. Any member's key would do; the
        // survivor's is the one the caller already treats as the cluster's representative.
        $adopted ??= $members[0];

        $this->pdo->exec('BEGIN IMMEDIATE');

        try {
            $absorb = $this->pdo->prepare('UPDATE listings SET group_key = :into WHERE group_key = :from');

            foreach (array_unique(array_filter($existing)) as $other) {
                if ($other !== $adopted) {
                    // Every row of the losing group, not just the members in hand — a group that was
                    // only half-merged would report two histories for one flat, and the half left
                    // behind is the half whose portal has already delisted.
                    $absorb->execute(['into' => $adopted, 'from' => $other]);
                }
            }

            $join = $this->pdo->prepare('UPDATE listings SET group_key = :group WHERE dedup_key = :key');

            foreach ($members as $key) {
                $join->execute(['group' => $adopted, 'key' => $key]);
            }

            $this->pdo->exec('COMMIT');
        } catch (\Throwable $failure) {
            self::rollBackQuietly($this->pdo);

            throw $failure;
        }

        return $adopted;
    }

    /**
     * The price history of a listing's whole group, oldest first, each entry naming the source.
     *
     * **A listing with no group reports its OWN history, not an empty one.** Deriving the group with
     * `WHERE group_key = (SELECT group_key FROM listings WHERE dedup_key = :key)` reads correctly
     * and is wrong: SQL's NULL is never equal to NULL, so an ungrouped listing — which is most of
     * them — would match no rows at all and report "no price history" silently.
     *
     * The group is derived by JOIN rather than copied onto `price_history`, so a later merge cannot
     * leave a second copy of the column stale.
     *
     * @return list<array{source: string, rent_cc: int, at: string}>
     */
    public function groupPriceHistory(string $dedupKey): array
    {
        $group = $this->groupKey($dedupKey);

        if ($group === null) {
            $statement = $this->pdo->prepare(
                'SELECT l.source AS source, h.rent_cc AS rent_cc, h.at AS at
                   FROM price_history h
                   JOIN listings l ON l.dedup_key = h.dedup_key
                  WHERE h.dedup_key = :key
                  ORDER BY h.at_epoch ASC, h.id ASC',
            );
            $statement->execute(['key' => $dedupKey]);
        } else {
            $statement = $this->pdo->prepare(
                'SELECT l.source AS source, h.rent_cc AS rent_cc, h.at AS at
                   FROM price_history h
                   JOIN listings l ON l.dedup_key = h.dedup_key
                  WHERE l.group_key = :group
                  ORDER BY h.at_epoch ASC, h.id ASC',
            );
            $statement->execute(['group' => $group]);
        }

        return array_map(
            static fn (array $row): array => [
                'source' => (string) $row['source'],
                'rent_cc' => (int) $row['rent_cc'],
                'at' => (string) $row['at'],
            ],
            $statement->fetchAll(),
        );
    }

    /** What the store currently believes about a listing, or null if it has never been seen. */
    public function snapshot(string $dedupKey): ?StoredListing
    {
        $statement = $this->pdo->prepare('SELECT * FROM listings WHERE dedup_key = :key');
        $statement->execute(['key' => $dedupKey]);
        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        return new StoredListing(
            dedupKey: (string) $row['dedup_key'],
            sourceName: (string) $row['source'],
            externalId: (string) $row['external_id'],
            url: $row['url'] === null ? null : (string) $row['url'],
            title: (string) $row['title'],
            rentCc: $row['rent_cc'] === null ? null : (int) $row['rent_cc'],
            firstSeenAt: (string) $row['first_seen_at'],
            lastSeenAt: (string) $row['last_seen_at'],
            notifiedAt: $row['notified_at'] === null ? null : (string) $row['notified_at'],
        );
    }

    /**
     * Whether this listing has ALREADY been pushed to the user, AT ALL.
     *
     * Seen and notified are deliberately different facts: a listing that went to the low-priority
     * *"à vérifier"* digest was seen and not notified, and one whose rent later drops must be able
     * to reach the user even though it is no longer new.
     *
     * This is the DIGEST side's gate — announcing a doubt twice is the alert fatigue Q34 exists to
     * avoid, and being told a flat is a match certainly covers being told it is doubtful. The match
     * side must ask {@see self::wasNotifiedAs()} instead, because the reverse is not true.
     */
    public function wasNotified(string $dedupKey): bool
    {
        $statement = $this->pdo->prepare('SELECT notified_at FROM listings WHERE dedup_key = :key');
        $statement->execute(['key' => $dedupKey]);
        $row = $statement->fetch();

        return $row !== false && $row['notified_at'] !== null;
    }

    /**
     * Whether this listing has already been announced AS the given outcome or better (schema v8).
     *
     * **`wasNotified()` alone made a promotion unreachable.** A delivered digest sets
     * `notified_at`, the match path read that as "already told about this listing", and so a
     * listing promoted DIGEST -> MATCH hit `continue` and no match notification was ever sent —
     * while the pass summary counted it, which is the part that makes it invisible. The same pass
     * then overwrote `tenure` and `outcome`, taking the row out of both `staleVerdicts()` and
     * `pendingDigest()`, so neither `scout reclassify` nor `scout digest` could reach it either.
     *
     * The ordering is monotone and closed: DIGEST < ROLLUP < MATCH (three levels since A5 —
     * see {@see announcementRank()}). A row already announced as a MATCH is never re-announced
     * as anything.
     *
     * **A pre-v8 row — a timestamp with no recorded kind — reads as MATCH.** That is the quiet
     * direction on purpose: those rows were announced by a version that did not record what it
     * said, and treating them as digests would re-announce the entire historic backlog as matches
     * the moment their tenure resolved. That is a flood out of the seen-set, which is the failure
     * Q36 exists to prevent. A never-announced row returns false, as it must.
     */
    public function wasNotifiedAs(string $dedupKey, string $outcome): bool
    {
        $statement = $this->pdo->prepare('SELECT notified_at, notified_as FROM listings WHERE dedup_key = :key');
        $statement->execute(['key' => $dedupKey]);
        $row = $statement->fetch();

        if ($row === false || $row['notified_at'] === null) {
            return false;
        }

        $announced = $row['notified_as'] === null ? 'MATCH' : (string) $row['notified_as'];

        return self::announcementRank($announced) >= self::announcementRank($outcome);
    }

    /**
     * How strong an announcement is, so a promotion can be recognised and a demotion cannot.
     *
     * An unrecognised value ranks as MATCH — the strongest — because the only way one gets into
     * the column is a caller passing something this method has not been taught, and re-announcing
     * on a value nobody understands is the loud direction in the one place §1 wants the quiet one.
     */
    private static function announcementRank(string $outcome): int
    {
        // DIGEST < ROLLUP < MATCH (A5, row 6, 2026-09-05). A rollup entry has been told about —
        // it covers the doubt question — but it has NOT been pushed as a match, so a later rent
        // drop over the gate is a promotion, pushed once. Anything unrecognised ranks as MATCH,
        // the strongest, which is the §1-safe reading: an unknown kind never re-announces.
        return match ($outcome) {
            'DIGEST' => 1,
            'ROLLUP' => 2,
            default => 3,
        };
    }

    /**
     * Everything judged a MATCH and never delivered, oldest first — the low-score queue (A5).
     *
     * The pipeline pushes a match at or above `push_min_score` on the pass that judges it, so an
     * undelivered MATCH is one the gate held back (or one whose push failed, which the next pass
     * retries first). Same row shape as {@see pendingDigest()}, same reason: decoding belongs to
     * the caller so a corrupt snapshot costs one entry, not the batch.
     *
     * @return list<array{dedup_key: string, source: string, external_id: string, url: ?string, title: string, rent_cc: ?int, evidence_json: ?string, signals_json: ?string, tenure: ?string}>
     */
    public function pendingLowScore(int $limit = self::DIGEST_BATCH): array
    {
        $statement = $this->pdo->prepare(
            "SELECT dedup_key, source, external_id, url, title, rent_cc, evidence_json, signals_json, tenure, confidence_bp
               FROM listings
              WHERE outcome = 'MATCH' AND notified_at IS NULL
              ORDER BY seen_epoch ASC, dedup_key ASC
              LIMIT :limit",
        );
        $statement->bindValue('limit', max(1, $limit), \PDO::PARAM_INT);
        $statement->execute();

        /** @var list<array{dedup_key: string, source: string, external_id: string, url: ?string, title: string, rent_cc: ?int, evidence_json: ?string, signals_json: ?string, tenure: ?string}> $rows */
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);

        return $rows;
    }

    public function pendingLowScoreCount(): int
    {
        return (int) $this->pdo->query(
            "SELECT COUNT(*) FROM listings WHERE outcome = 'MATCH' AND notified_at IS NULL",
        )->fetchColumn();
    }

    /** @throws \InvalidArgumentException if the key was never recorded — a silent no-op here would re-notify forever */
    /**
     * Record that this listing was announced, and AS WHAT (schema v8).
     *
     * `$as` is REQUIRED rather than defaulted, and that is deliberate. Neither default is safe:
     * 'MATCH' would let a digest caller that forgot it suppress the very promotion this column
     * exists to allow, and 'DIGEST' would let a match caller that forgot it re-announce a flat
     * already pushed. A new caller has to decide, which is the only version of this that stays
     * correct as callers are added.
     *
     * **The write cannot DEMOTE.** A row already announced as a match keeps that, whatever a later
     * digest pass says — done in SQL rather than read-then-write so two `scout` processes on the
     * same store (WAL, and this project ships a `--watch` loop) cannot interleave into a lost
     * update. `notified_at` still moves, because the listing genuinely was carried again.
     */
    public function markNotified(string $dedupKey, string $atIso, string $as): void
    {
        self::epoch($atIso);

        $statement = $this->pdo->prepare(
            'UPDATE listings
                SET notified_at = :at,
                    notified_as = CASE
                        WHEN notified_at IS NOT NULL AND COALESCE(notified_as, \'MATCH\') = \'MATCH\' THEN \'MATCH\'
                        WHEN notified_at IS NOT NULL AND notified_as = \'ROLLUP\' AND :as2 = \'DIGEST\' THEN \'ROLLUP\'
                        ELSE :as
                    END
              WHERE dedup_key = :key',
        );
        // The monotone ordering, in SQL: a MATCH is sticky against everything, a ROLLUP against a
        // DIGEST, anything else takes the new kind (schema v8, extended by A5 — see announcementRank()).
        $statement->execute(['at' => $atIso, 'as' => $as, 'as2' => $as, 'key' => $dedupKey]);

        if ($statement->rowCount() === 0) {
            throw new \InvalidArgumentException(sprintf('annonce inconnue, impossible de la marquer notifiée : %s', $dedupKey));
        }
    }

    // ── Source health (spec §8, `CLAUDE.md` hard rule 2) ───────────────────────────────────────────

    /** Log one poll of one source. Delegated — see {@see health()}. */
    public function recordRun(
        string $sourceName,
        int $itemCount,
        bool $ok,
        ?string $error,
        string $atIso,
        ?int $durationMs = null,
        ?string $feedNewestAt = null,
    ): void {
        $this->runs->recordRun($sourceName, $itemCount, $ok, $error, $atIso, $durationMs, $feedNewestAt);
    }

    /**
     * Derive the current health of a source from its whole run history.
     *
     * `$nowIso` is optional because this class does not read the clock — health must be testable
     * without waiting. Supplying it unlocks the one verdict that cannot be derived from the log
     * alone: `STALE`, a source whose SCHEDULE has stopped. Without it, a source that last ran three
     * hundred days ago is indistinguishable from one that ran this morning.
     */
    // ── Alert cooldown (schema v3, Q29) ───────────────────────────────────────────────────────────

    /** Whether an alert is outside its cooldown. Delegated — see {@see health()}. */
    public function shouldAlert(string $sourceName, string $status, string $nowIso, int $cooldownHours): bool
    {
        return $this->runs->shouldAlert($sourceName, $status, $nowIso, $cooldownHours);
    }

    /** Record that an alert was sent. Delegated — see {@see health()}. */
    public function markAlerted(string $sourceName, string $status, string $atIso): void
    {
        $this->runs->markAlerted($sourceName, $status, $atIso);
    }

    /** Clear every standing alert for a source. Delegated — see {@see health()}. */
    public function clearAlerts(string $sourceName): bool
    {
        return $this->runs->clearAlerts($sourceName);
    }

    // ── Detail hydration (schema v5) ──────────────────────────────────────────────────────────────

    /**
     * What is on record about this listing's detail page, or `null` if it was never attempted.
     *
     * `null` and a row with `attempts > 0` are deliberately different answers. The first means the
     * listing is owed a request; the second means one was spent and failed, and the caller decides
     * about a retry from `attempts` and `lastAttemptAt` rather than trying again on every pass for
     * ever.
     */
    public function detail(string $sourceName, string $externalId, ?string $mapFingerprint = null): ?StoredDetail
    {
        $statement = $this->pdo->prepare(
            'SELECT source, external_id, url_fetched, fields_json, fetched_at, attempts,
                    last_attempt_at, last_error, map_fingerprint
             FROM listing_detail WHERE source = :source AND external_id = :id',
        );
        $statement->execute(['source' => $sourceName, 'id' => $externalId]);
        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        // A HYDRATED row captured under a different detail_map reads as absent, so the caller
        // refetches it through the ordinary budget and priority path.
        //
        // Scoped to hydrated rows on purpose. A FAILURE row's fingerprint says nothing — no map
        // produced it — and hiding it would discard the attempt count and the backoff with it,
        // turning one permanently-404ing page into a fresh request every single pass. That is the
        // crawl the backoff exists to prevent, and it would arrive disguised as a cache miss.
        if ($mapFingerprint !== null
            && $row['fields_json'] !== null
            && (string) ($row['map_fingerprint'] ?? '') !== $mapFingerprint) {
            return null;
        }

        $raw = $row['fields_json'];
        $fields = null;

        if (\is_string($raw)) {
            /** @var array<string,string> $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            $fields = $decoded;
        }

        return new StoredDetail(
            sourceName: (string) $row['source'],
            externalId: (string) $row['external_id'],
            urlFetched: $row['url_fetched'] === null ? null : (string) $row['url_fetched'],
            fields: $fields,
            fetchedAt: $row['fetched_at'] === null ? null : (string) $row['fetched_at'],
            attempts: (int) $row['attempts'],
            lastAttemptAt: $row['last_attempt_at'] === null ? null : (string) $row['last_attempt_at'],
            lastError: $row['last_error'] === null ? null : (string) $row['last_error'],
        );
    }

    /**
     * A detail page was read. Store what it said, verbatim.
     *
     * An EMPTY map is a legitimate success and is stored as `{}`, never as SQL NULL: "the page was
     * read and matched no selector" is a finding, and the difference from "never read" is what
     * stops the listing being re-fetched on every pass for ever.
     *
     * `attempts` still increments, so a page that needed three tries says so.
     *
     * @param array<string,string> $fields the RAW extracted strings
     */
    public function recordDetail(
        string $sourceName,
        string $externalId,
        ?string $urlFetched,
        array $fields,
        string $atIso,
        ?string $mapFingerprint = null,
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO listing_detail
                 (source, external_id, url_fetched, fields_json, fetched_at, attempts, last_attempt_at, last_error, map_fingerprint)
             VALUES (:source, :id, :url, :fields, :at, 1, :at, NULL, :fp)
             ON CONFLICT (source, external_id) DO UPDATE SET
                 url_fetched     = excluded.url_fetched,
                 fields_json     = excluded.fields_json,
                 fetched_at      = excluded.fetched_at,
                 attempts        = listing_detail.attempts + 1,
                 last_attempt_at = excluded.last_attempt_at,
                 last_error      = NULL,
                 map_fingerprint = excluded.map_fingerprint',
        );
        $statement->execute([
            'source' => $sourceName,
            'id' => $externalId,
            'url' => $urlFetched,
            'fields' => json_encode($fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'at' => $atIso,
            'fp' => $mapFingerprint,
        ]);
    }

    /**
     * A detail fetch failed. Record the attempt, and do NOT touch any hydration already on record.
     *
     * A source that starts 500ing must not erase what it told us last week — the stored fields stay
     * usable and the failure is recorded beside them. `Redact` is applied by the caller before the
     * text arrives here, because a detail-fetch failure carries the URL it failed on.
     */
    public function recordDetailFailure(
        string $sourceName,
        string $externalId,
        string $error,
        string $atIso,
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO listing_detail
                 (source, external_id, url_fetched, fields_json, fetched_at, attempts, last_attempt_at, last_error)
             VALUES (:source, :id, NULL, NULL, NULL, 1, :at, :error)
             ON CONFLICT (source, external_id) DO UPDATE SET
                 attempts        = listing_detail.attempts + 1,
                 last_attempt_at = excluded.last_attempt_at,
                 last_error      = excluded.last_error',
        );
        $statement->execute([
            'source' => $sourceName,
            'id' => $externalId,
            'at' => $atIso,
            'error' => $error,
        ]);
    }

    /**
     * How many listings on a source have failed hydration at least `$minAttempts` times and have
     * never yet succeeded.
     *
     * This is a HEALTH input, not a diagnostic. One detail page that will not load is noise; fifty
     * of a hundred and seventy-four means the landlord changed their detail markup, which is the
     * broken-selector-forever scenario hard rule 2 exists for — and it is invisible in every other
     * signal, because the search page still parses and the count still looks right.
     */
    public function detailFailureCount(string $sourceName, int $minAttempts = 1): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM listing_detail
             WHERE source = :source AND fields_json IS NULL AND attempts >= :min',
        );
        $statement->execute(['source' => $sourceName, 'min' => $minAttempts]);

        return (int) $statement->fetchColumn();
    }

    // ── Tenure verdicts (schema v3, Q24) ──────────────────────────────────────────────────────────

    /**
     * Persist the classifier's verdict alongside the listing, so a past decision can be audited —
     * and, more importantly, REVISED.
     *
     * Q24 asked for auditability and answered only the storage half. The reason this matters more
     * than auditing: the seen-set guarantees a listing is new exactly once, so a listing digested as
     * `UNKNOWN` under a classifier that is later improved is a permanent silent miss. `scout
     * reclassify` re-runs the classifier over these rows, and it can only select the stale ones
     * because {@see staleVerdicts()} knows which were never classified.
     *
     * @param list<string> $signals the `reasons[]`, stored verbatim so the verdict can be explained
     *                              later without re-fetching an ad the source may have removed
     *
     * @return bool whether the evidence snapshot could be captured. `false` means the verdict was
     *              stored WITHOUT one, and the caller must say so out loud — see below
     */
    public function recordVerdict(
        string $dedupKey,
        string $tenure,
        int $confidenceBp,
        array $signals,
        RawListing $evidence,
    ): bool {
        // A LISTING THAT CANNOT BE ENCODED MUST NOT KILL THE PASS, and until 2026-08-24 it did.
        //
        // `ListingSnapshot::encode()` uses JSON_THROW_ON_ERROR, and non-UTF-8 text is an explicitly
        // anticipated real input here — cp1252 under a UTF-8 declaration has its own `Text` test and
        // its own classifier branch, which turns it into UNKNOWN with a reason naming the encoding.
        // So the classifier handled it and the STORE threw, from inside `Pipeline`'s per-listing
        // loop, which sits outside the per-source try/catch. One badly-encoded listing therefore
        // aborted the whole pass: every later listing went unclassified and unnotified, and the
        // offending row was left in the seen-set with `tenure = NULL` — a value whose documented
        // meaning is "stored before schema v3", so `reclassify` would report it for ever as a
        // pre-v7 row, which is false. `recordRun` had already committed `ok=1`, so health stayed
        // green: hard rule 2's silent shape exactly.
        //
        // The verdict is stored with NO snapshot instead, which is the honest state rather than a
        // degraded one: a listing whose text cannot be read is a listing nothing can re-judge, and
        // `reclassify` already skips evidence-less rows and counts them. That is NOT a divergence
        // between verdict and evidence — it is the documented ABSENCE of evidence, the same state
        // every pre-v7 row is in.
        //
        // Returned rather than swallowed, because the caller is the one with a channel to report on.
        $snapshot = null;
        $captured = true;

        try {
            $snapshot = ListingSnapshot::encode($evidence);
        } catch (\JsonException) {
            $captured = false;
        }

        // ONE STATEMENT, and the snapshot travels with the verdict rather than in a second write,
        // because the property that matters is "the stored evidence is what produced the stored
        // verdict". A second write or an optional parameter re-opens the divergence: a verdict from
        // pass N sitting beside evidence from pass N-1 would let `reclassify` compare against
        // something the classifier never saw, which is the §1 hole this column exists to close.
        //
        // ONE DELIBERATE EXCEPTION, recorded rather than left to be discovered (round-5 panel,
        // 2026-08-31). Since round 4 the pipeline stores a DURABLE excluded tenure — yesterday's
        // reading — beside TODAY's snapshot, for every member. The invariant above is therefore not
        // literally true of such a row. It is safe in the only direction that matters: an excluded
        // row is never offered to `reclassify` (`staleVerdicts()` selects `tenure IS NULL OR
        // tenure IN ('UNKNOWN')`), so nothing ever compares that verdict against that evidence. The
        // snapshot is still the evidence THIS pass saw, which is what `scout dump` and any human
        // audit want.
        $statement = $this->pdo->prepare(
            'UPDATE listings SET tenure = :tenure, confidence_bp = :bp, signals_json = :signals,
                    evidence_json = :evidence
             WHERE dedup_key = :key',
        );
        $statement->execute([
            'tenure' => $tenure,
            'bp' => $confidenceBp,
            // The reasons are encoded WITHOUT the throwing flag for the same reason, and with
            // substitution rather than refusal: they are explanatory strings, and losing a byte of
            // one costs a sentence, where throwing costs the pass.
            'signals' => json_encode($signals, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            'evidence' => $snapshot,
            'key' => $dedupKey,
        ]);

        return $captured;
    }

    /**
     * The listing exactly as the classifier saw it when the stored verdict was formed.
     *
     * `null` means no snapshot was ever captured — either a row stored before schema v7 and
     * deliberately not backfilled, or (since 2026-08-24) a listing whose payload could not be
     * JSON-encoded. It does NOT mean the snapshot was empty, and the caller must not treat it as one:
     * `scout reclassify` skips such a row and counts it out loud rather than re-judging it on
     * whatever else is lying around.
     *
     * THROWS on a snapshot that exists and cannot be read. Hard rule 3 — degrading a corrupt row to
     * a bare listing would classify as `UNKNOWN` and read as an honest doubt rather than as a row
     * nobody can judge, and reclassify would then have run on strictly less evidence than the
     * original. Loud is the only safe direction here.
     *
     * @throws \JsonException            the stored snapshot is not JSON
     * @throws \InvalidArgumentException the stored snapshot is JSON but is not a listing
     */
    public function evidence(string $dedupKey): ?RawListing
    {
        $statement = $this->pdo->prepare('SELECT evidence_json FROM listings WHERE dedup_key = :key');
        $statement->execute(['key' => $dedupKey]);

        /** @var string|false|null $json */
        $json = $statement->fetchColumn();

        if (!is_string($json) || $json === '') {
            return null;
        }

        return ListingSnapshot::decode($json);
    }

    /**
     * Record what the criteria engine judged this listing to be.
     *
     * Written separately from the verdict, and that asymmetry is deliberate rather than an
     * oversight: every dedup MEMBER is recorded and classified, but only the SURVIVOR is judged.
     * Folding the outcome into {@see recordVerdict()} would force a value for members that never
     * reached the criteria engine, and `NULL` is precisely what distinguishes "never judged" from
     * "judged and rejected".
     *
     * Overwrites, so a listing re-judged on a later pass carries only its latest outcome — a flat
     * promoted from DIGEST to MATCH must LEAVE the pending digest, or `scout digest` announces as
     * doubtful something already notified as a match.
     */
    /**
     * Record what the OTHER track was judged to be for this flat (schema v12).
     *
     * Precedence is the group veto's, read across the track boundary: an EXCLUDED tenure is
     * DURABLE — once the other route said PLS, no later reading clears it (a portal that stops
     * printing yesterday's PLS has not changed the flat; stated cost: an over-merged twin rejects a
     * real flat for the row's life, and the repair is to unpick the row, never to weaken the rule).
     * Otherwise the LAST reading wins, so a doubt (UNKNOWN) is resolved when the twin is later
     * judged eligible — and can return. Developer ruling, 2026-08-30.
     *
     * **AMENDED 2026-09-04 (COR-F5), in one direction only:** resolving a recorded `UNKNOWN` now
     * also requires the incoming reading to reach {@see TWIN_DOUBT_MIN_CONFIDENCE}, so a tier-5
     * source default cannot erase a doubt a mixed-stock landlord raised. Tightening is unchanged
     * and needs no bar. The 2026-08-30 ruling stands; this narrows what counts as a later reading
     * winning, and only toward more caution.
     */
    public function recordTwin(string $dedupKey, Tenure $tenure, string $source, int $confidenceBp): void
    {
        $current = $this->twinTenure($dedupKey);

        if ($current !== null && $current['tenure']->isExcluded()) {
            return;
        }

        // A DOUBT IS CLEARED BY POSITIVE EVIDENCE, NEVER BY A SOURCE DEFAULT (COR-F5, 2026-09-04).
        //
        // "Otherwise the last reading wins" was too generous in exactly one direction. A THIRD
        // route, which never saw the route that raised the doubt, could erase a recorded `UNKNOWN`
        // with the weakest signal the classifier has: tier 5, the source default, whose own
        // documented property is that an ABSENT signal must lower confidence rather than silently
        // inherit `default_tenure`. Proven by execution against In'li — the source `CLAUDE.md`
        // records as **not pure LLI**, two live listings stating `plafond de ressources PLS` on
        // detail pages their cards never mentioned. The erasing signal came from the source that
        // most concretely disproves the assumption behind it.
        //
        // THE BAR IS §1'S OWN FAIL-CLOSED THRESHOLD, not a new number: below 0.6 a classification
        // on a mixed-stock source is already `UNKNOWN`. A tier-5 default sits at 50 and is refused;
        // a tier-1 structured field and a tier-2 explicit label are 90 and clear.
        //
        // **IT IS NOT A TIER TEST AND MUST NOT BE READ AS ONE** — this comment said "every tier-1/2
        // label is 90 and clears" as though those were the only tiers that pass, and a review panel
        // measured a tier-3 PROCEDURAL signal clearing at 80. That is correct rather than a hole: a
        // `commission d'attribution` is evidence, and evidence is what the ruling asks for. It is
        // also exactly why the gate reads CONFIDENCE and not a tier — the tier is not stored, and
        // the question is how strongly the reading is held, not what rank produced it. Encoding a
        // tier on the row would be a second copy of the same fact, free to drift from the first.
        //
        // ONLY THE RESOLVING DIRECTION IS GATED. Tightening — eligible to `UNKNOWN`, anything to an
        // excluded regime — needs no bar and is unaffected, and the durable rule above still runs
        // first. So this can only ever make the store MORE careful, which is why it lands without
        // re-opening the 2026-08-30 precedence ruling.
        //
        // The constant is named `…MIN_CONFIDENCE` rather than `…CLEARING_CONFIDENCE`, and that is
        // not taste: `clear` is one of the shapes `.claude/hooks/tenure-guard.sh` reads as the
        // excluded set being emptied, and it sat within the pattern's 80-character window of the
        // `isExcluded()` call below. The repo's rule is to reword and keep the tripwire credible,
        // never to widen the exception.
        if ($current !== null
            && $current['tenure'] === Tenure::UNKNOWN
            && !$tenure->isExcluded()
            && $tenure !== Tenure::UNKNOWN
            && $confidenceBp < self::TWIN_DOUBT_MIN_CONFIDENCE) {
            return;
        }

        $statement = $this->pdo->prepare('UPDATE listings SET twin_tenure = :tenure, twin_source = :source WHERE dedup_key = :key');
        $statement->execute(['tenure' => $tenure->value, 'source' => $source, 'key' => $dedupKey]);

        if ($statement->rowCount() === 0) {
            // A 0-row UPDATE returning quietly is a fact never stored, reported as stored (round-3
            // panel). Every key this is called with was recorded in the same pass, so this is a
            // programming error, not an input.
            throw new \LogicException('recordTwin: no listing under dedup key ' . $dedupKey);
        }
    }

    /** The tenure this row was last recorded as, or `null` when it has none (never judged, or pre-v3). */
    public function tenure(string $dedupKey): ?Tenure
    {
        $statement = $this->pdo->prepare('SELECT tenure FROM listings WHERE dedup_key = :key');
        $statement->execute(['key' => $dedupKey]);

        /** @var string|false|null $tenure */
        $tenure = $statement->fetchColumn();

        return self::decodeTenure(is_string($tenure) ? $tenure : null, 'tenure', $dedupKey);
    }

    /**
     * A stored tenure, refusing LOUDLY on a value that does not decode (round-5 panel, 2026-08-31).
     *
     * All three §1 vetoes read a `Tenure` out of the database with `Tenure::tryFrom()`, and all
     * three treated its `null` as "nothing was ever said": the row's own durable reading
     * ({@see tenure()}), the schema-v4 group veto ({@see groupExcludedTenure()}) and the schema-v12
     * twin veto ({@see twinTenure()}). But `tryFrom()` returns `null` for a value it cannot parse
     * just as readily as for a column that is genuinely NULL — so a single case-flip, a hand-edited
     * row, or ANY future rename of a `Tenure` case releases all three at once, silently, and the
     * flat is pushed.
     *
     * §1's own instruction is to bias every ambiguous decision toward NOT notifying, and this is the
     * one place the ambiguity was resolved toward notifying. A NULL column still means nothing was
     * said, which is the truth and is what a pre-v3 row carries; a non-empty string that does not
     * decode is a corrupt row and is refused by name, the same posture the store already takes for a
     * database whose schema it cannot read.
     *
     * Deliberately a throw and not a silent excluded value: pretending a corrupt row said `PLS`
     * would fail closed while inventing evidence, and the whole point of the durable reading is that
     * a stored verdict can be re-examined. The refusal is visible everywhere it can occur — a
     * `--watch` pass reports it on the heartbeat, and `--once` records it for `doctor`.
     */
    private static function decodeTenure(?string $raw, string $column, string $dedupKey): ?Tenure
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $tenure = Tenure::tryFrom($raw);

        if ($tenure === null) {
            throw new \RuntimeException(sprintf(
                'régime « %s » illisible en colonne %s pour %s — ligne corrompue : '
                . 'la traiter comme « rien de dit » relâcherait le veto §1 de cette ligne',
                $raw,
                $column,
                $dedupKey,
            ));
        }

        return $tenure;
    }

    /**
     * What the other track last said about this flat, or `null` when it never said anything.
     *
     * @return array{tenure: Tenure, source: string}|null
     */
    public function twinTenure(string $dedupKey): ?array
    {
        $statement = $this->pdo->prepare('SELECT twin_tenure, twin_source FROM listings WHERE dedup_key = :key');
        $statement->execute(['key' => $dedupKey]);

        /** @var array{twin_tenure: ?string, twin_source: ?string}|false $row */
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        // The twin half of the same §1 fail-open — see {@see decodeTenure()}.
        $tenure = $row === false ? null : self::decodeTenure($row['twin_tenure'], 'twin_tenure', $dedupKey);

        if ($tenure === null) {
            return null;
        }

        return ['tenure' => $tenure, 'source' => (string) $row['twin_source']];
    }

    /**
     * F20 / Q39 (row 40, 2026-09-05): the ONE way back for a durably-excluded row.
     *
     * A row's own excluded reading is permanent by design — `staleVerdicts()` skips it,
     * `pendingDigest()` skips a non-DIGEST outcome, `replay` writes no verdicts — and so is a
     * `PLS` it received from a twin on the other track. That is the §1-safe direction, and it has
     * one cost: an over-link or an over-merge rejects a genuine LLI flat for ever, and no command
     * could undo it. This is that command's store half: it reports where the exclusion came from
     * and clears the row's OWN reading and its TWIN reading, so the next pass (or the rest of the
     * `reclassify` invocation that called it) re-judges the row on its own evidence.
     *
     * TWO of the four routes are reported but NOT cleared, for one reason: they live on ANOTHER
     * row's own reading, and a listing that really says `PLS` keeps saying it. Clearing either here
     * would delete a §1 fact belonging to a row the operator did not name.
     *
     *   - the GROUP veto — the siblings' own readings
     *   - the SAME DWELLING on record under another ad id — that other listing's own reading
     *
     * So re-opening a row held by either changes nothing on the next pass, and the report says so
     * before the operator wonders why. **`--reopen` is therefore not a universal undo**: it
     * reverses the row's own reading and its twin's, and for the other two it tells you which
     * listing to reopen instead.
     *
     * A row whose snapshot will not decode reports `dwelling: null` — that route needs the
     * evidence, and a damaged snapshot is skipped rather than thrown (see the body). The clear
     * still happens, which is the part the operator asked for.
     *
     * @return array{own: ?Tenure, twin: ?array{tenure: Tenure, source: string}, group: ?Tenure,
     *         dwelling: ?array{key: string, source: string, externalId: string, tenure: Tenure,
     *         listing: RawListing, reason: string}, dwellingReadable: bool}|null
     *         the provenance, or `null` when no such row exists — nothing is touched then
     */
    public function reopen(string $dedupKey, bool $dryRun = false): ?array
    {
        $exists = $this->pdo->prepare('SELECT 1 FROM listings WHERE dedup_key = :key');
        $exists->execute(['key' => $dedupKey]);
        if ($exists->fetchColumn() === false) {
            return null;
        }

        // FOUR ROUTES, because `reclassify` judges §1 by four. Reporting three read as "nothing
        // links this row" on exactly the population the fourth exists for — a flat re-advertised
        // under a new ad id, which by construction has no group edge and no twin.
        // A CORRUPT SNAPSHOT IS SKIPPED, NEVER THROWN — the choice `excludedDwellings()` two methods
        // below documents as "the one place this method deliberately departs from `evidence()`".
        // Round 1 called `evidence()` unguarded here and made `--reopen` the only unguarded caller
        // in the tree: it sits ABOVE the `UPDATE`, so a damaged snapshot took the whole command down
        // with a raw stack trace, cleared nothing, and re-judged no other row either — on the verb
        // documented as the ONE way back for a durably-excluded row (C2 round 2, P1 on all three
        // lenses). The other two call sites both catch exactly this pair.
        $dwellingReadable = true;
        try {
            $evidence = $this->evidence($dedupKey);
        } catch (\JsonException | \InvalidArgumentException) {
            // COULD NOT CHECK is not CHECKED AND CLEAR (C2 round 3, P2 on two lenses). Reporting the
            // fourth route as `aucun` here told the operator all four routes were clear on a row
            // whose snapshot merely would not decode — and the row's own reading had just been
            // CLEARED, so they act on it. `Store::evidence()`'s own docblock refuses exactly this
            // trade ("degrading it to `null` would make data loss indistinguishable from a row that
            // never had one"); this made it at the reporting layer instead. The caller now has a
            // third state to render.
            $evidence = null;
            $dwellingReadable = false;
        }
        $provenance = [
            'own' => $this->tenure($dedupKey),
            'twin' => $this->twinTenure($dedupKey),
            'group' => $this->groupExcludedTenure($dedupKey),
            'dwelling' => $evidence === null
                ? null
                : ExcludedDwellings::match($evidence, $this->excludedDwellings(), new Dedup()),
            // `false` means the route could not be consulted at all — distinct from `null`, which
            // means it was consulted and found nothing.
            'dwellingReadable' => $dwellingReadable,
        ];

        // The DWELLING route is deliberately not cleared, for the group veto's exact reason: it is
        // another row's own reading, and a row that really says PLS keeps saying it. Clearing it
        // here would delete a §1 fact belonging to a listing the operator did not name.
        if (!$dryRun) {
            $this->pdo->prepare('UPDATE listings SET tenure = NULL, twin_tenure = NULL, twin_source = NULL WHERE dedup_key = :key')
                ->execute(['key' => $dedupKey]);
        }

        return $provenance;
    }

    public function recordOutcome(string $dedupKey, string $outcome): void
    {
        $this->pdo->prepare('UPDATE listings SET outcome = :outcome WHERE dedup_key = :key')
            ->execute(['outcome' => $outcome, 'key' => $dedupKey]);
    }

    /** What this listing was last judged to be, or `null` if it was never judged. */
    public function outcome(string $dedupKey): ?string
    {
        $statement = $this->pdo->prepare('SELECT outcome FROM listings WHERE dedup_key = :key');
        $statement->execute(['key' => $dedupKey]);

        /** @var string|false|null $outcome */
        $outcome = $statement->fetchColumn();

        return is_string($outcome) && $outcome !== '' ? $outcome : null;
    }

    /**
     * Everything digested and never delivered, oldest first, for `scout digest`.
     *
     * `notified_at IS NULL` is the delivery test, reusing the field rather than adding a parallel
     * one: being carried in a DELIVERED digest IS being told about the listing, which is what that
     * column means everywhere else.
     *
     * **The rows come back RAW, and that is the point.** A bulk query that decoded snapshots would
     * throw on the first corrupt one and take every readable entry with it — one bad row costing
     * the whole digest, which is the opposite of skipping it and saying so. Decoding belongs to the
     * caller so that its failure is per-row and countable.
     *
     * **`source`, `external_id`, `url` and `rent_cc` travel with the snapshot**, so a row whose
     * `evidence_json` is NULL can still be announced from stored facts rather than skipped or
     * invented.
     *
     * THE PRE-V7 BACKLOG IS NOT WHAT THAT IS FOR, and this docblock said it was. `outcome` is a v7
     * column too and is equally unbackfilled, so a pre-v7 row has `outcome = NULL` and this query
     * never returns it at all — see {@see \Scout\Rent\Cli\RentScout::digest()}, which carries the full
     * reasoning and the reason widening the query is refused. The reachable cause of a NULL
     * snapshot here is a listing whose payload could not be JSON-encoded.
     *
     * @return list<array{dedup_key: string, source: string, external_id: string, url: ?string,
     *     title: string, rent_cc: ?int, evidence_json: ?string, signals_json: ?string, tenure: ?string}>
     */
    public function pendingDigest(int $limit = self::DIGEST_BATCH): array
    {
        $statement = $this->pdo->prepare(
            // `tenure` rides along so the drain can tell a §1 tenure doubt from a row digested for
            // some other reason: the rollup title claims a regime only when EVERY entry earned it,
            // and the store is the only place that fact survives a pass. Reading it costs nothing
            // — it is a column on the row already being fetched — and it is a READ of what was
            // judged, never a re-judgement (`scout digest` announces, it does not re-classify).
            "SELECT dedup_key, source, external_id, url, title, rent_cc, evidence_json, signals_json, tenure
               FROM listings
              WHERE outcome = 'DIGEST' AND notified_at IS NULL
              ORDER BY seen_epoch ASC, dedup_key ASC
              LIMIT :limit",
        );
        $statement->bindValue(':limit', max(1, $limit), \PDO::PARAM_INT);
        $statement->execute();

        /** @var list<array{dedup_key: string, source: string, external_id: string, url: ?string, title: string, rent_cc: ?int, evidence_json: ?string, signals_json: ?string, tenure: ?string}> $rows */
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);

        return $rows;
    }

    /** How many rows are waiting in total, so a capped batch can say what it left behind. */
    public function pendingDigestCount(): int
    {
        // No `$statement === false` guard: `ERRMODE_EXCEPTION` is set at construction, so `query()`
        // throws rather than returning false — see the note further down this file, where four such
        // branches were removed as dead. Both counters added in round 6 reintroduced one, and both
        // fabricated `0`: "nothing waiting", which is the operator-facing reading they exist to
        // prevent. Dead safety code reads as a second line of defence and is not one.
        return (int) $this->pdo->query(
            "SELECT COUNT(*) FROM listings WHERE outcome = 'DIGEST' AND notified_at IS NULL",
        )->fetchColumn();
    }

    /**
     * How many stored verdicts have no evidence behind them, so can never be re-judged.
     *
     * **The one shape this makes visible is a notified MATCH that silently lost its snapshot**, and
     * nothing else could see it. `staleVerdicts()` selects `tenure IS NULL OR tenure = 'UNKNOWN'`,
     * so a row that classified `LLI` and failed to encode is not SKIPPED by `scout reclassify` — it
     * is invisible to it. `pendingDigest()` only walks digest outcomes. The single report was one
     * stdout line on the pass that caused it, which under Q8's deployment scrolls past in a log
     * CLAUDE.md says nobody reads. A review panel found it on 2026-08-24.
     *
     * That matters because this is the §1 audit trail: schema v7 exists so a verdict can be
     * re-examined, and a verdict with no evidence is one nobody can ever check.
     *
     * **`tenure IS NOT NULL` scopes it to rows that were actually classified**, so a pre-v3 row —
     * which has neither a verdict nor a snapshot and is a different, documented state — is not
     * counted. Every other pre-v7 row IS counted, and that is correct rather than noisy: they are
     * genuinely unreclassifiable, and on a database migrated from v4 the number starts high and
     * only ever falls as rows are re-observed under v7.
     *
     * Follows the precedent of {@see detailFailureCount()}: a per-listing failure that must not
     * void anything is persisted and surfaced by `scout doctor` rather than shouted once.
     */
    public function evidencelessVerdictCount(): int
    {
        // Same as `pendingDigestCount()`: no dead false-guard, and no fabricated zero.
        return (int) $this->pdo->query(
            'SELECT COUNT(*) FROM listings WHERE tenure IS NOT NULL AND evidence_json IS NULL',
        )->fetchColumn();
    }

    /**
     * Listings whose stored verdict predates a given classifier state, for `scout reclassify`.
     *
     * `tenure IS NULL` is deliberately included: those are rows stored before schema v3, and the
     * migration does NOT backfill them. There is no honest value to backfill with — writing
     * `UNKNOWN` would be indistinguishable from a real UNKNOWN verdict, and this query is exactly
     * the place that distinction has to survive.
     *
     * @param list<string> $tenures which stored verdicts to re-examine, e.g. `['UNKNOWN']`
     *
     * @return list<array{dedup_key: string, source: string, external_id: string, title: string, tenure: ?string}>
     */
    public function staleVerdicts(array $tenures = ['UNKNOWN']): array
    {
        $placeholders = [];
        $params = [];
        foreach (array_values($tenures) as $i => $tenure) {
            $placeholders[] = ':t' . $i;
            $params['t' . $i] = $tenure;
        }

        $sql = 'SELECT dedup_key, source, external_id, title, tenure FROM listings WHERE tenure IS NULL';
        if ($placeholders !== []) {
            $sql .= ' OR tenure IN (' . implode(', ', $placeholders) . ')';
        }
        $sql .= ' ORDER BY seen_epoch DESC, dedup_key ASC';

        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        /** @var list<array{dedup_key: string, source: string, external_id: string, title: string, tenure: ?string}> $rows */
        $rows = $statement->fetchAll();

        return $rows;
    }

    /**
     * Every dwelling the store has judged to be an EXCLUDED regime, with the evidence to compare it.
     *
     * The §1 veto travelled three ways — the persisted cluster `group_key`, the cross-track
     * `twin_tenure`, and the row's own durable reading — and a portal RE-ADVERTISING a flat under a
     * new ad id acquires none of them: a new `external_id` means a fresh row, and `Dedup` refuses a
     * same-source edge on purpose. So the `PLS` sat one row away on disk while the second copy was
     * pushed as a match (C2 round-1 correctness lens, 2026-09-02, proven through the real pipeline).
     * This is the fourth route, and the only one that does not need an edge in the pass's harvest —
     * which is what the DELISTED variant needs, where the excluded copy is not fetched at all.
     *
     * **Excluded only, and never `UNKNOWN`.** Propagating a doubt by content match against the whole
     * store is a different decision with the In'li cost — *not §1 satisfied, the tool switched off* —
     * and it is deliberately not made here.
     *
     * **A row with no snapshot cannot be compared and is therefore NOT vetoed.** That is the unsafe
     * direction and it is stated rather than hidden: a pre-v7 row (never backfilled, by ruling) and a
     * listing whose payload could not be JSON-encoded both fall out. It decays — the live store has
     * been v7 since late August — and the alternative, comparing on the four flat columns the
     * `listings` table happens to carry, would merge on the ABSENCE of a difference, which is the
     * over-merge `sameFlatReason()` exists to refuse.
     *
     * **A corrupt snapshot is SKIPPED here, not thrown**, which is the one place this method
     * deliberately departs from {@see evidence()}. That method throws because `reclassify` is
     * re-judging exactly that row and must not do it on less evidence; here the row is one candidate
     * among many and throwing would take the whole pass — every source, every listing — down with it,
     * turning one bad row into a total outage. The cost is that the bad row stops vetoing, which is
     * the same not-vetoed gap the snapshot-less rows already have.
     *
     * Small by construction: the live store holds 43 excluded rows against 2 331 listings, so this is
     * one indexed scan and a few dozen JSON decodes per pass, loaded ONCE and passed down.
     *
     * @return list<array{key: string, source: string, externalId: string, tenure: Tenure, listing: RawListing}>
     */
    public function excludedDwellings(): array
    {
        $excluded = array_values(array_filter(
            Tenure::cases(),
            static fn (Tenure $t): bool => $t->isExcluded(),
        ));

        $placeholders = [];
        $params = [];
        foreach ($excluded as $i => $tenure) {
            $placeholders[] = ':t' . $i;
            $params['t' . $i] = $tenure->value;
        }

        $statement = $this->pdo->prepare(
            'SELECT dedup_key, source, external_id, tenure, evidence_json FROM listings
              WHERE tenure IN (' . implode(', ', $placeholders) . ")
                AND evidence_json IS NOT NULL AND evidence_json <> ''
              ORDER BY dedup_key ASC",
        );
        $statement->execute($params);

        $out = [];
        /** @var array{dedup_key: string, source: string, external_id: string, tenure: string, evidence_json: string} $row */
        foreach ($statement->fetchAll() as $row) {
            $tenure = Tenure::tryFrom($row['tenure']);
            if ($tenure === null || !$tenure->isExcluded()) {
                continue;
            }

            try {
                $listing = ListingSnapshot::decode($row['evidence_json']);
            } catch (\JsonException | \InvalidArgumentException) {
                continue;
            }

            $out[] = [
                'key' => $row['dedup_key'],
                'source' => $row['source'],
                'externalId' => $row['external_id'],
                'tenure' => $tenure,
                'listing' => $listing,
            ];
        }

        return $out;
    }

    /**
     * Source health — DELEGATED to the generic run log.
     *
     * The verdicts live in {@see \Scout\Core\RunStore} because they are a property of a RUN, not of
     * a housing listing: the car domain reached this exact method through this exact class, which is
     * what a store composing another domain's store looks like from the inside.
     */
    public function health(string $sourceName, ?string $nowIso = null, ?int $feedSilentDays = null): SourceHealth
    {
        return $this->runs->health($sourceName, $nowIso, $feedSilentDays);
    }

    // ── Internals ─────────────────────────────────────────────────────────────────────────────────

    private function schemaVersionOrNull(): ?int
    {
        // No `$statement === false` guard: `ERRMODE_EXCEPTION` is set at construction, so `query()`
        // throws rather than returning false. Four such branches existed and all four were dead —
        // each fabricating a benign default for a condition that cannot occur, which reads as
        // protection and provides none. The `$row === false` checks after `fetch()` are LIVE and
        // stay: an empty result set is an ordinary outcome.
        $statement = $this->pdo->query("SELECT value FROM schema_meta WHERE key = 'schema_version'");
        $row = $statement->fetch();

        return $row === false ? null : (int) $row['value'];
    }

    /** The last rent recorded at or before `$epoch` — the chronological predecessor, not the last write. */
    private function rentBefore(string $key, int $epoch): ?int
    {
        $statement = $this->pdo->prepare(
            'SELECT rent_cc FROM price_history
              WHERE dedup_key = :key AND at_epoch <= :epoch
              ORDER BY at_epoch DESC, id DESC LIMIT 1',
        );
        $statement->execute(['key' => $key, 'epoch' => $epoch]);
        $row = $statement->fetch();

        return $row === false ? null : (int) $row['rent_cc'];
    }

    /** The first rent recorded strictly after `$epoch`, if a later sighting has already landed. */
    private function rentAfter(string $key, int $epoch): ?int
    {
        $statement = $this->pdo->prepare(
            'SELECT rent_cc FROM price_history
              WHERE dedup_key = :key AND at_epoch > :epoch
              ORDER BY at_epoch ASC, id ASC LIMIT 1',
        );
        $statement->execute(['key' => $key, 'epoch' => $epoch]);
        $row = $statement->fetch();

        return $row === false ? null : (int) $row['rent_cc'];
    }





    /**
     * Roll back without letting the rollback's own failure replace the real error.
     *
     * SQLite auto-rolls-back on `SQLITE_FULL`, so `PDO::rollBack()` then throws "There is no active
     * transaction" — and an unguarded call propagates THAT instead of "database or disk is full".
     * Disk-full is precisely the failure where the seen-set stops growing and the next run
     * re-notifies the market, and the operator was being handed a message about transactions.
     */
    private static function rollBackQuietly(\PDO $pdo): void
    {
        try {
            // `exec('ROLLBACK')` to match the `BEGIN IMMEDIATE` above — symmetry, not necessity.
            // An earlier version of this comment claimed `PDO::rollBack()` would throw because PDO
            // tracks only its own transactions. That is FALSE for PDO_SQLITE, which reads the
            // engine's autocommit state: `inTransaction()` is true after `exec('BEGIN IMMEDIATE')`
            // and `rollBack()` works. The correction is recorded rather than silently applied
            // because the previous commit's message claimed to have made it and had not.
            $pdo->exec('ROLLBACK');
        } catch (\Throwable) {
            // The engine already rolled back — verified in BOTH journal modes: SQLITE_FULL leaves
            // `inTransaction()` false and `rollBack()` throws. (An earlier version of this comment
            // said "under WAL", a claim conditioned on a pragma nothing verified had taken effect.)
            // The caller's exception is the one that matters, and this catch is what lets it survive.
            //
            // There is no `if ($pdo->inTransaction())` fast path here on purpose: it was written,
            // and it turned out to be unreachable-as-a-guarantee — the catch already covers every
            // case it covered, so removing it changed no behaviour and no test. Dead safety code
            // reads as protection and provides none.
        }
    }

    /**
     * Parse an ISO-8601 timestamp, strictly, and always as the instant it names.
     *
     * `new DateTimeImmutable($s)` accepts an empty string as "now" and shrugs at a good deal of
     * nonsense besides. Here the timestamp orders the price history and defines the health window,
     * so a silently-reinterpreted one would corrupt both without any visible symptom.
     *
     * A trailing `Z` is rewritten to `+00:00` rather than matched as a format literal. `\Z` in a
     * `createFromFormat` pattern is decoration: the instant is built in the DEFAULT timezone and
     * the Z is discarded, so on a `Europe/Paris` host — the natural deployment for an
     * Île-de-France tool — `…T10:30:00Z` stored 08:30 UTC. Mixed with a `+00:00` sibling that
     * inverts the price history's order and turns a rise into a drop, and a valid UTC instant
     * inside the spring DST gap was rejected outright, one hour a year.
     *
     * @throws \InvalidArgumentException on anything that is not a full ISO-8601 instant
     */



    /**
     * Strict ISO-8601 to unix seconds. FORWARDS to the generic run log's implementation.
     *
     * Kept as a private forwarder rather than rewritten at all 26 rent call sites: one
     * implementation, no churn, and `VehicleStore`'s own third copy is deleted by this same change —
     * so the split removes a duplication instead of adding one.
     */
    private static function epoch(string $iso): int
    {
        return RunStore::epoch($iso);
    }

    /**
     * Strip leading and trailing whitespace, including the Unicode kind.
     *
     * `trim()` strips seven ASCII bytes and nothing else. U+00A0 — which `Text` itself calls a real
     * adapter artefact, and which a decoded `&nbsp;` produces — survives it, so an `externalId` of
     * one no-break space passed the "does this source publish an id?" test and every listing in the
     * run collapsed onto the SAME key. Over-merging hides a flat entirely, and that flat is then
     * indistinguishable from a quiet market.
     */
    private static function trimUnicode(string $value): string
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

    /** Fold for comparison, falling back to the raw bytes rather than collapsing unreadable input to one key. */
    private static function normaliseText(string $value): string
    {
        $folded = Text::foldTolerant($value);

        if ($folded === null) {
            return $value === '' ? '' : 'brut:' . bin2hex($value);
        }

        return self::trimUnicode($folded);
    }

    /**
     * Normalise a URL for identity: drop the fragment, lowercase the scheme and host.
     *
     * The path and query keep their case, because RFC 3986 makes them case-SENSITIVE and folding
     * them risks merging two distinct listings — and over-merging hides a flat entirely, which is
     * the worse direction of the two. Tracking parameters are deliberately NOT stripped: no source
     * has yet been observed to emit one, and guessing at a strip-list would be inventing a rule
     * against a failure nobody has seen.
     */
    private static function normaliseUrl(string $url): string
    {
        $url = self::trimUnicode($url);

        if ($url === '') {
            return '';
        }

        $parts = parse_url($url);

        if ($parts === false || !isset($parts['host'])) {
            return self::normaliseText($url);
        }

        $rebuilt = isset($parts['scheme']) ? strtolower($parts['scheme']) . '://' : '//';

        if (isset($parts['user'])) {
            $rebuilt .= $parts['user'] . (isset($parts['pass']) ? ':' . $parts['pass'] : '') . '@';
        }

        $rebuilt .= strtolower($parts['host']);
        $rebuilt .= isset($parts['port']) ? ':' . $parts['port'] : '';
        $rebuilt .= $parts['path'] ?? '';
        $rebuilt .= isset($parts['query']) ? '?' . $parts['query'] : '';

        return $rebuilt;
    }
}
