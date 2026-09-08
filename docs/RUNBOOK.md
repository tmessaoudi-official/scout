# Runbook — bringing scout up, and keeping it up

> **What this file is.** The operator's checklist: from a fresh clone to a watcher that pushes, then
> every verb, every knob, and a symptom → check → fix table.
>
> **What it is not.** It is not the reasoning. `README.md` § *Deploying it* carries the measurements
> behind each step and the incidents that produced them — this file links to it rather than
> restating it, because a second copy of a rationale is a copy that drifts.
>
> Verified against the code on **2026-09-08**: verbs and flags read out of `Rent\Cli\RentScout` and
> `Car\Cli\CarScout`, env keys out of `.env.example`.

---

## 0. Two contexts, and the commands differ

Confusing these is the commonest first-hour mistake.

| Context | Command shape | Why |
|---|---|---|
| **On the host**, from the repo | `bin/scout --domain=rent doctor` | you name the domain |
| **Through compose** | `docker compose run --rm rent-scout doctor` | the domain is in the **`entrypoint`**, so the command replaces only the verb |

`docker compose run --rm car-scout doctor` is the car one. Passing `--domain=` through compose is
redundant, and putting the flag in `command:` instead of `entrypoint:` is how the first deploy ran
the *rent* doctor from the car service.

---

## 1. Zero to running

Nine steps. Each says what it proves — a step whose output you did not read has not been run.

### 1.1 Toolchain

```bash
composer install                 # generates the PSR-4 autoloader. Zero runtime dependencies.
bash tools/fetch-phpunit.sh      # the test runner; pinned SHA-256, refuses to install on a mismatch
php tools/phpunit.phar           # ✔ proves: the tree is green on this PHP
```

> `composer dump-autoload` **without `--dev`** omits the `Scout\Tests\` PSR-4 entry, and the corpus
> suite then errors `Class ... not found` while the unit tests keep passing. It reads as a code
> regression and is a build state. Fix: `composer dump-autoload --dev`.

### 1.2 Configuration

```bash
cp .env.example .env && $EDITOR .env
```

**Required to be useful:**

| Key | What it is |
|---|---|
| `RENT_SCOUT_DB` | the seen-set. Default `state/rent-watch.sqlite3`. **Not** under `var/` — that tree is documented as scratch someone may delete. |
| `IMAP_HOST` `IMAP_PORT` `IMAP_USER` `IMAP_PASSWORD` | the alert mailbox. Account-level, shared by both domains. |
| `RENT_IMAP_MAILBOX` | the folder/label the rent alerts are filed under. **A dedicated label, not `INBOX`** — a run marks the mail it claimed `\Seen`. |
| one push channel | `RENT_NTFY_TOPIC` (+ `NTFY_SERVER`), **or** the `SMTP_*` set. Without one, nothing ever reaches you. |
| `TZ` | e.g. `Europe/Paris`. The daily floors are computed in it. |

**Optional, with defaults:** `IMAP_SINCE_DAYS` (7) · `IMAP_MAX_MESSAGES` (50) ·
`RENT_HEARTBEAT_HOURS` (24) · `RENT_FEED_SILENT_DAYS` (3) · `SCOUT_BACKUP_KEEP` (7) ·
`IDFM_API_KEY` (commute scoring) · `SCOUT_UID` / `SCOUT_GID` · `SCOUT_OFFLINE` · `SCOUT_MAX_PASSES`
· `MAILBOX_DIR` (test only).

**For the car domain**, uncomment: `CAR_SCOUT_DB` · `CAR_IMAP_MAILBOX` · `CAR_NTFY_TOPIC` ·
`CAR_HEARTBEAT_HOURS` · `CAR_FEED_SILENT_DAYS`.

> ⚠ **Two `.env` traps, both silent.**
> **(a)** `Config\DotEnv` applies the **first** occurrence of a key and skips every later one, and an
> empty string counts as set — so appending `IDFM_API_KEY=…` to a template line that is already there
> leaves the real key permanently unread. **Edit the line in place; never append a key that already
> has one.**
> **(b)** The old unprefixed rent names (`SCOUT_DB`, `IMAP_MAILBOX`, `NTFY_TOPIC`,
> `HEARTBEAT_HOURS`, `FEED_SILENT_DAYS`) are **refused at startup**, naming their successor. That is
> deliberate: a silently accepted alias keeps both spellings valid for ever.

Then tune what a good result is — `config/rent/criteria.json` and `config/car/criteria.json`, or a
gitignored `criteria.local.json` beside either, which overrides **field by field**. Commute scoring
lives only in the local file, because it needs a personal address.

### 1.3 Prove it before scheduling it

```bash
docker compose run --rm rent-scout doctor       # ✔ sources reachable, journal mode WAL, channels usable
docker compose run --rm rent-scout test-notify  # ✔ a message actually reaches your phone. EXITS 1 if not.
```

`doctor` alone does **not** prove the channel; `test-notify` is the one that does.

### 1.4 Seed, then start

```bash
docker compose run --rm rent-scout run --once --seed
docker compose run --rm car-scout  run --once --seed
docker compose up -d
bash tools/verify-deploy.sh                     # ✔ the step that says whether any of it landed
```

**The seed is not optional.** An empty seen-set makes `run` refuse, because an empty seen-set is
exactly what a forgotten volume mount looks like and the alternative is notifying the entire back
catalogue at once. With `restart: unless-stopped` that refusal becomes a restart loop.

**`up -d` printing `Started` is not a deployment.** Both watchers set `stop_grace_period: 5m` and stop
only after the pass in flight finishes, so a recreate can sit for minutes and has twice wedged — and
`docker compose ps` without `-a` **omits** a service that is not running, so the failure renders as a
shorter list. `verify-deploy.sh` asserts the four things that output cannot show you: every declared
service has a running container; it runs the **current** image; that image was **built after the newest
`src/` commit**; and no hex-prefixed leftover is holding a name the next recreate will die on.

A hex-prefixed name is **two states**, and they take opposite commands. If the tool says *conteneurs
orphelins*, the container is dead and `docker rm -f <name>` is the remedy it prints. If it says the
container **EST le service**, compose still resolves a declared service to it — a recreate was killed
inside its grace period and left it running — so removing it kills the watcher; recover the clean name
with `setsid docker compose up -d --force-recreate --remove-orphans <service>`, and never run compose
under a foreground `timeout`, which is what produces the state. A hex-prefixed container belonging to
another compose project on this host is not reported at all.

### 1.5 Schedule the backup

```bash
0 4 * * *  cd /srv/scout && tools/backup-state.sh
```

---

## 2. If you drive it with cron instead of `--watch`

The daily floors live **inside the watch loop**, so a `--once` deployment has the event-driven paths
and no floor at all: the *à vérifier* bin and the *vérifié, score bas* queue both fill and nothing
empties them. Owe them two scheduled verbs:

```cron
30 8 * * *  cd /srv/scout && docker compose run --rm rent-scout digest
35 8 * * *  cd /srv/scout && docker compose run --rm car-scout  rollup
```

Both are safe when there is nothing pending — they say so and send nothing. Every `--once` pass that
holds a match back names the verb it is waiting for, and `doctor` says the floor is `--watch` only.

---

## 3. Every verb

### `--domain=rent`

| Command | Does | Writes? |
|---|---|---|
| `doctor` | state, duration and volume of each source; seen-set; channels; journal mode | **yes — records a run** |
| `dump <source>` | the first raw listing plus the field map applied | no |
| `replay <source>` | alias of `dump`. Takes a source **name**, not a file | no |
| `run --once [-v]` | one full pass | yes |
| `run --seed` | primes the seen-set without notifying | yes |
| `run --watch [-v]` | the loop: 15 min ± 5 jitter, heartbeat, daily floors | yes |
| `digest [--dry-run]` | emits the pending *« à vérifier »* rollup | yes unless dry |
| `reclassify [--dry-run]` | re-judges stored undetermined verdicts from the v7 snapshot | yes unless dry |
| `test-notify` | proves a channel reaches a human. **Exit 1** if none does | no |

**Flags:** `--source=<name>` (repeatable; limits `doctor`/`run`, and **force-runs a source that is
`enabled: false`** — the run says so) · `--file=<payload>` (replays a frozen file through an
`html`/`json` field map, no network, no database; for `email_alert` use `MAILBOX_DIR=`) ·
`--verbose` / `-v` · `--dry-run` · `--i-accept-legal-risk` (required for any `legal_risk` source) ·
`--reopen=<dedup_key>` on `reclassify`.

`--since` on `reclassify` is **refused, not unimplemented**: its ruled mechanism is a
classifier-version column that does not exist.

### `--domain=car`

| Command | Does |
|---|---|
| `doctor` | per-source state, seen-set, channels |
| `dump <source>` | first raw listing + reading + verdict |
| `run --once [-v]` / `run --once --seed` / `run --watch [-v]` | as above |
| `rollup [--dry-run]` | emits the pending *« vérifié, score bas »* rollup |
| `test-notify` | proves the car channel |

There is no `digest` and no `reclassify` on the car side: no tenure means no doubt bin, and no
persisted classification to re-judge.

### `reclassify --reopen=<dedup_key>` — the one way back

A durably-excluded row has exactly one repair route. `--reopen` prints where the exclusion came from
(the row's own reading / the twin / the group / the same dwelling under another ad id), **clears the
row's own and twin readings**, and re-judges it on its own evidence in the same invocation — so a row
that then matches is notified, and the run needs a delivering channel.

**Two routes are reported and deliberately not cleared**: the group veto and the same-dwelling veto.
Each lives on *another* row's reading, and a listing that really says `PLS` keeps saying it — so the
command tells you the next pass will reject again while that holds. It is **not a universal undo**.
Never a pattern, never *all*: the cost of a wrong re-open is a social-housing flat pushed as a match.
`--dry-run` reports and clears nothing; an unknown key is refused and touches nothing.

---

## 4. Redeploying after a code change

**`src/` is baked into the image; `config/` is not.** Compose mounts `./config` read-only and
`./state` read-write. So a `git pull` changes the criteria and the source definitions immediately, and
changes **no code at all** until the image is rebuilt. Green, pushed and deployed are three different
things — measured twice, at seventeen hours and at a day and a half, with every container reporting
*running, image courante* throughout.

```bash
docker tag scout:local scout:pre-<what-you-are-leaving>       # rollback, one retag away
docker compose build                                          # BEFORE stopping: a failed build must not leave you down

sqlite3 state/rent-watch.sqlite3 ".backup /tmp/mig-rehearse.sqlite3"
php -r 'require "vendor/autoload.php"; $s=Scout\Rent\Store\Store::open("/tmp/mig-rehearse.sqlite3");
        echo $s->schemaVersion()," ",$s->journalMode(),PHP_EOL;'   # rehearse the migration offline

docker compose stop                                           # graceful; finishes the pass in flight
sqlite3 state/rent-watch.sqlite3 ".backup state/rent-watch.sqlite3.pre-<v>.$(date +%s).bak"
docker compose up -d --remove-orphans
bash tools/verify-deploy.sh
```

Compare row counts on both sides of the rehearsal: **a migration that silently drops the price history
looks exactly like a successful one.**

> **A redeploy that adds a filter reads as a collapse in matches, and that is the filter working.**
> One upgrade took the same 478 listings from `83 correspondance(s), 9 à vérifier` to `29, 63`, because
> a source-default match is now withheld until the listing's own page has been read. Matches climb back
> over the following passes as the backlog drains; nothing already notified is re-notified.

**Moving to another host:** three files carry the deployment and only one is in git — the seen-set
(`state/rent-watch.sqlite3`), `.env`, and `config/rent/criteria.local.json`. Carry all three, run
`doctor` before `up -d`, and **do not re-run `--seed`** if you carried the seen-set: seeding marks
everything currently published as already seen, so a genuine listing that appeared during the move is
swallowed silently.

---

## 5. Backups

```bash
tools/backup-state.sh                       # → state/backups/rent-watch.<stamp>.sqlite3
tools/backup-state.sh state/car-watch.sqlite3
```

**Do not use `cp`, and the reason is silent.** The watcher holds the database open in WAL, so a byte
copy taken mid-transaction is torn — and a torn SQLite file **opens without complaint and reports a
plausible row count**. You find out at restore. `backup-state.sh` uses SQLite's own online-backup API,
checkpoints the WAL, then **reads the copy back** (`integrity_check` plus a row count) before reporting
success. It keeps `SCOUT_BACKUP_KEEP` (7), oldest-first, because an unbounded backup directory fills
the disk and takes the live seen-set down with it.

Restoring is a move: stop the container, put the file at `state/rent-watch.sqlite3`, start it.

---

## 6. Symptom → check → fix

| Symptom | Check | Fix |
|---|---|---|
| `run` refuses, restart loop | `state/rent-last-refusal.txt` — the refusal is recorded and reported on the next successful start | usually the empty seen-set: `run --once --seed` |
| refusal names the database as unusable | is `state/` writable by the container uid? | `SCOUT_UID=$(id -u) SCOUT_GID=$(id -g) docker compose up -d` |
| `test-notify` exits 1 | `doctor` says `AUCUN canal n'atteint de destinataire` | `console` and `email` over `SMTP_TRANSPORT=file` reach nobody. Configure ntfy or real SMTP |
| a channel is silently absent | startup prints `⚠ canal … désactivé` | the channel is listed in `notify.channels` but its credential is missing from `.env` |
| a source reports `broken` | `doctor`, then the run log | if you ran a fixture-backed `doctor` without a throwaway DB, the synthetic run is in the baseline — see below |
| a source reports `feed_silent` | the portal has sent nothing for `*_FEED_SILENT_DAYS` | keep that threshold **under** `IMAP_SINCE_DAYS`; the observable band is `(threshold, window)` |
| a source reports `stale` | the **watcher** stopped, not the portal | `docker compose ps -a`, then `verify-deploy.sh` |
| matches collapsed after a deploy | did a filter or a gate land? | expected; the backlog drains at `detail_budget_per_pass` per source per pass |
| nothing arrives, everything green | is the heartbeat arriving? | `docker compose run --rm rent-scout test-notify` proves the deployed image can reach you |
| a fix is committed, pushed, CI-green, and not live | `docker image inspect scout:local --format '{{.Created}}'` vs. the last `src/` commit | rebuild — `verify-deploy.sh` asserts exactly this |
| pushed and CI is red | `gh run list --limit 5` | the runtime differs from this one (PCRE2 version, production ini). **Check after every push** — a red fast job notifies nobody |
| the corpus suite errors `Class not found` | a build state, not a regression | `composer dump-autoload --dev` |

> ⚠ **Recovering from a fixture run in the live baseline.** Back up first, delete the synthetic
> `source_runs` row by exact id, then re-run `doctor` and confirm the verdict changed. Prevent it by
> always pairing `MAILBOX_DIR=` with a throwaway database.

---

## 7. Checking a change before it ships

```bash
php tools/phpunit.phar                                   # the suite — read its last line, not the ✓ marks
bash tests/sabotage-check.sh                             # the ledger: would the suite NOTICE a regression?
SABOTAGE_FILTER='<regex on labels>' bash tests/sabotage-check.sh   # one case, not the multi-hour ledger
bash tests/test-tenure-guard.sh                          # the §1 tripwire still fires (rent vocabulary)
bash tests/test-vehicle-guard.sh                         # …and the car one
bash .claude/skills/scout-repair/drift-scan.sh           # docs vs. reality; exit 1 on any P0/P1
bash -n .claude/hooks/*.sh tests/*.sh tools/*.sh
```

Run the ledger after any change to the tenure module, `Core/Text.php`, the corpus, `Core/Pacer.php`,
`Cli/WatchLoop.php`, `Rent/Adapters/PacedSource.php`, or anything under `Rent/Store/`.

> **Three ways to misread the ledger.** Join `SABOTAGE_FILTER` labels with a plain `|` — `/bin/grep`
> here is ugrep, where `\|` is **literal**, so a `\|` filter skips every case and still exits 0. A
> case that **times out** counts as undetected — each case runs the whole suite, ~88 s on an idle box
(the ledger itself is hours, which is why it is a nightly CI job), so check
> `uptime` before believing a lone timeout on this shared machine. And the local PHP's tracing JIT
> crashes it nondeterministically (`zend_jit_trace.c` assertion, exit 134) — that is *harness broke*,
> not a detection.

---

## 8. Where the reasoning is

| You want | Read |
|---|---|
| the shape of the program | [`docs/ARCHITECTURE.md`](ARCHITECTURE.md) |
| what each live source costs | [`docs/SOURCES-LIVE.md`](SOURCES-LIVE.md) |
| why a deploy step exists | `README.md` § *Deploying it* |
| how a channel is configured | `README.md` § *Notifications* |
| capturing an alert as a fixture | [`docs/ALERT-CAPTURE.md`](ALERT-CAPTURE.md) |
| every decision and its reversal line | [`docs/OPEN-QUESTIONS.md`](OPEN-QUESTIONS.md) |
