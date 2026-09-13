<?php

declare(strict_types=1);

namespace Scout\Job;

use Scout\Core\RunStore;
use Scout\Core\Whitespace;

/**
 * The job domain's seen-set, announcement record and evidence — on its OWN database file, beside a
 * composed {@see RunStore} that provides the run log, source health, feed silence and alert cooldowns.
 *
 * The car store is the template, with two deliberate differences:
 *
 * - **It records WHAT an offer was announced as**, not only whether. A pushed offer and a rolled-up
 *   one are different facts, and the ordering is monotone: `ROLLUP < MATCH`. A rolled-up offer can
 *   still be promoted to a push; a pushed one is never demoted. The car store has no such column and
 *   the repo records that as a completeness gap, so this schema carries it from v1, while no
 *   deployment exists to migrate. There is no DIGEST level: this domain has no doubt bin.
 * - **An id is trimmed with {@see Whitespace::trim()}**, never `trim()`, so an id of one no-break
 *   space is refused rather than collapsing every offer of the pass onto one key.
 *
 * No pay history is kept. No ruling makes a salary change an event, and a table nothing reads is a
 * guarantee nobody decided on.
 */
final readonly class JobStore
{
    public const int SCHEMA_VERSION = 1;
    public const string AS_ROLLUP = 'ROLLUP';
    public const string AS_MATCH = 'MATCH';
    private const int BUSY_TIMEOUT_MS = 10000;

    private function __construct(
        private \PDO $pdo,
        private RunStore $runs,
    ) {}

    public static function open(string $path, ?int $feedSilentDays = null): self
    {
        // FIRST: the run store creates the parent directory, which this handle cannot.
        $runs = RunStore::open($path, $feedSilentDays);

        $pdo = new \PDO('sqlite:' . $path, options: [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = ' . self::BUSY_TIMEOUT_MS);
        $pdo->query('PRAGMA journal_mode = WAL')?->closeCursor();

        $store = new self($pdo, $runs);
        $store->migrate();

        return $store;
    }

    /** The run log, health, feed silence and alert cooldowns — the generic store. */
    public function runs(): RunStore
    {
        return $this->runs;
    }

    public function journalMode(): string
    {
        return $this->runs->journalMode();
    }

    /** @throws \InvalidArgumentException for an id that is only whitespace — it identifies nothing */
    public function dedupKey(JobListing $offer): string
    {
        $id = Whitespace::trim($offer->externalId);
        if ($id === '') {
            throw new \InvalidArgumentException('une offre sans identifiant ne peut pas être enregistrée : ' . $offer->sourceName);
        }

        return $offer->sourceName . ':id:' . rawurlencode($id);
    }

    /**
     * Record one observation at `$atIso` — the message's own instant for an email source, the pass
     * time otherwise — and say whether it is new and whether it is current.
     *
     * A sighting OLDER than the stored one is superseded: it updates nothing. An empty title or
     * company on a current sighting keeps the known one, because a card that failed to extract a
     * field is not an offer that renamed itself.
     */
    public function record(JobListing $offer, string $atIso): JobSighting
    {
        $key = $this->dedupKey($offer);
        $epoch = self::epoch($atIso);

        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $q = $this->pdo->prepare('SELECT seen_epoch FROM job_listings WHERE dedup_key = :k');
            $q->execute(['k' => $key]);
            $row = $q->fetch();

            $isNew = $row === false;
            $isCurrent = $isNew || $epoch >= (int) $row['seen_epoch'];

            if ($isNew) {
                $this->pdo->prepare(
                    'INSERT INTO job_listings (dedup_key, source, external_id, url, title, company, first_seen_at, last_seen_at, seen_epoch)
                     VALUES (:k, :s, :e, :u, :t, :c, :at, :at, :ep)',
                )->execute(['k' => $key, 's' => $offer->sourceName, 'e' => $offer->externalId, 'u' => $offer->url, 't' => $offer->title, 'c' => $offer->company, 'at' => $atIso, 'ep' => $epoch]);
            } elseif ($isCurrent) {
                $this->pdo->prepare(
                    'UPDATE job_listings SET last_seen_at = :at, seen_epoch = :ep, url = COALESCE(:u, url),
                            title = CASE WHEN :t = \'\' THEN title ELSE :t END,
                            company = CASE WHEN :c = \'\' THEN company ELSE :c END
                      WHERE dedup_key = :k',
                )->execute(['k' => $key, 'at' => $atIso, 'ep' => $epoch, 'u' => $offer->url, 't' => $offer->title, 'c' => $offer->company]);
            }

            $this->pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            $this->pdo->exec('ROLLBACK');
            throw $e;
        }

        return new JobSighting(dedupKey: $key, isNew: $isNew, isCurrent: $isCurrent);
    }

    /**
     * The verdict and the evidence it was formed from — every constructor parameter, by the snapshot's own reflection test.
     *
     * @throws \InvalidArgumentException if the offer was never recorded — a silent no-op would lose the verdict
     */
    public function recordVerdict(string $dedupKey, JobVerdict $verdict, JobListing $offer): void
    {
        $q = $this->pdo->prepare('UPDATE job_listings SET outcome = :o, score = :sc, snapshot_json = :j WHERE dedup_key = :k');
        $q->execute(['k' => $dedupKey, 'o' => $verdict->outcome->value, 'sc' => $verdict->score, 'j' => JobSnapshot::encode($offer)]);

        if ($q->rowCount() === 0) {
            throw new \InvalidArgumentException('offre inconnue, verdict non enregistré : ' . $dedupKey);
        }
    }

    public function wasNotified(string $dedupKey): bool
    {
        return $this->column($dedupKey, 'notified_at') !== null;
    }

    /** Whether this offer was already announced AS `$as` or stronger: a rollup covers a rollup, only a push covers a push. */
    public function wasNotifiedAs(string $dedupKey, string $as): bool
    {
        $wanted = self::rank($as);
        $q = $this->pdo->prepare('SELECT notified_at, notified_as FROM job_listings WHERE dedup_key = :k');
        $q->execute(['k' => $dedupKey]);
        $row = $q->fetch();

        if ($row === false || $row['notified_at'] === null) {
            return false;
        }

        return self::rank((string) $row['notified_as']) >= $wanted;
    }

    /**
     * Record that this offer was announced, and AS WHAT. `$as` is required: either default would be
     * wrong for one of the two callers. The write cannot demote, and the rule is in SQL so two
     * processes on one store cannot interleave into a lost update.
     *
     * @throws \InvalidArgumentException for a kind this domain does not have, or an offer never recorded
     */
    public function markNotified(string $dedupKey, string $atIso, string $as): void
    {
        self::rank($as);
        self::epoch($atIso);

        $q = $this->pdo->prepare(
            'UPDATE job_listings
                SET notified_at = :at,
                    notified_as = CASE WHEN notified_as = \'MATCH\' THEN \'MATCH\' ELSE :as END
              WHERE dedup_key = :k',
        );
        $q->execute(['k' => $dedupKey, 'at' => $atIso, 'as' => $as]);

        if ($q->rowCount() === 0) {
            throw new \InvalidArgumentException('offre inconnue, impossible de la marquer notifiée : ' . $dedupKey);
        }
    }

    /** Q36 analog: nothing recorded at all — a fresh file, or a missing volume mount wearing one's face. */
    public function isSeenSetEmpty(): bool
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM job_listings')->fetchColumn() === 0;
    }

    /** @throws \RuntimeException for a snapshot that does not decode — never degraded to "no evidence" */
    public function snapshot(string $dedupKey): ?JobListing
    {
        $json = $this->column($dedupKey, 'snapshot_json');
        if ($json === null) {
            return null;
        }

        try {
            return JobSnapshot::decode((string) $json);
        } catch (\InvalidArgumentException $e) {
            throw new \RuntimeException('instantané stocké illisible pour ' . $dedupKey . ' : ' . $e->getMessage(), 0, $e);
        }
    }

    public function snapshotScore(string $dedupKey): ?int
    {
        $v = $this->column($dedupKey, 'score');

        return $v === null ? null : (int) $v;
    }

    /** @throws \RuntimeException for a stored outcome that does not decode — never read as "not judged" */
    public function outcomeOf(string $dedupKey): ?JobOutcome
    {
        $v = $this->column($dedupKey, 'outcome');
        if ($v === null) {
            return null;
        }

        return JobOutcome::tryFrom((string) $v)
            ?? throw new \RuntimeException(sprintf('issue stockée inconnue « %s » pour %s', (string) $v, $dedupKey));
    }

    private function column(string $dedupKey, string $column): mixed
    {
        $q = $this->pdo->prepare('SELECT ' . $column . ' FROM job_listings WHERE dedup_key = :k');
        $q->execute(['k' => $dedupKey]);
        $v = $q->fetchColumn();

        return $v === false ? null : $v;
    }

    private static function rank(string $as): int
    {
        return match ($as) {
            self::AS_ROLLUP => 1,
            self::AS_MATCH => 2,
            default => throw new \InvalidArgumentException('type d\'annonce inconnu : ' . $as),
        };
    }

    private function migrate(): void
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS job_meta (key TEXT PRIMARY KEY, value TEXT NOT NULL)');

        $q = $this->pdo->query("SELECT value FROM job_meta WHERE key = 'schema_version'");
        $current = (int) ($q->fetchColumn() ?: 0);
        if ($current > self::SCHEMA_VERSION) {
            throw new \RuntimeException(sprintf('base offres au schéma %d, ce code connaît le %d — mettez le code à jour, pas la base', $current, self::SCHEMA_VERSION));
        }
        if ($current === self::SCHEMA_VERSION) {
            return;
        }

        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $this->pdo->exec('CREATE TABLE IF NOT EXISTS job_listings (
                dedup_key     TEXT PRIMARY KEY,
                source        TEXT NOT NULL,
                external_id   TEXT NOT NULL,
                url           TEXT,
                title         TEXT NOT NULL,
                company       TEXT NOT NULL,
                first_seen_at TEXT NOT NULL,
                last_seen_at  TEXT NOT NULL,
                seen_epoch    INTEGER NOT NULL,
                notified_at   TEXT,
                notified_as   TEXT,
                outcome       TEXT,
                score         INTEGER,
                snapshot_json TEXT
            )');
            $this->pdo->exec('CREATE INDEX IF NOT EXISTS job_listings_source ON job_listings (source)');
            $this->pdo->prepare("INSERT OR REPLACE INTO job_meta (key, value) VALUES ('schema_version', :v)")->execute(['v' => (string) self::SCHEMA_VERSION]);
            $this->pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            $this->pdo->exec('ROLLBACK');
            throw $e;
        }
    }

    /** Strict ISO-8601 to unix seconds — FORWARDS to the single implementation. */
    public static function epoch(string $iso): int
    {
        return RunStore::epoch($iso);
    }
}
