---
name: domain-docker-ops-sabotage-ledger
description: Use when a task touches scout's Docker watchers, deploy or redeploy, tools/verify-deploy.sh, SQLite backup, the Q36 seed guard, a case in tests/sabotage-check.sh (writing, auditing, running), CI (.github/workflows/ci.yml), exit 137 or 134, or any job over ~8 minutes on this box. The procedure and the traps; the trigger lines live in EXPERTISE.md.
---

Review date: 2026-10-03   Validation mode: advisory   Core: .claude/rules/expertise-core.md

## Roles and mental models
- **Release engineer**: green, pushed and deployed are three measurements. A section-1 fix sat CI-green, pushed and unarmed in production ~1.5 days (2026-09-04). [Observed: 2026-09-04]
- **Mutation tester**: a case is evidence only if it applied, parsed, and reddened the RIGHT test. [Observed: 2026-09-04/09]
- **Operator**: classify a down watcher (wipe, repeated stop, fault) before debugging. [Observed: 2026-09-27, 2026-10-01]

## Hard rules
1. After a redeploy and on "are we done / what's left": `bash tools/verify-deploy.sh` (exit 0 ok, 1 watcher down, 2 docker missing / daemon unreachable / image absent: not clean). `/progress` never reads docker (showed 97 % with both watchers gone since 2026-09-10). [Observed: 2026-09-13; Source: tools/verify-deploy.sh]
2. Compose runs DETACHED: `setsid docker compose up -d --force-recreate --remove-orphans <service>`. Never under a foreground `timeout` (SIGTERMs the group, leaves a renamed container running as the service). [Observed: 2026-09-07]
3. A pipeline or record-path fix is not done until the DEPLOYED first pass says so: rebuild `scout:local`, restart, `docker compose logs` until `annonce(s) analysees`. Tests and ledger run commute OFF. [Observed: 2026-08-29]
4. Detach any job over ~8 min (see CI, long jobs); wait on its own `EXIT=` line. [Observed: 2026-09-04/25/26]
5. Restore a mutated file from a byte copy, never `git checkout`; check the sha before and after. [Observed: 2026-09-05]
6. `docker compose config`: exit code only (`--quiet >/dev/null 2>&1; echo $?`); its output prints `env_file` tokens. [Source: s-tech-a s3, Unverified rc read on 2026-10-02]
7. Never overlap the ledger with a reviewer panel or a neighbour project's test run (load 35 turned a 90 s suite into 25 min). [Observed: 2026-09-04]

## Docker, deploy, backup (REFERENCE s9)
- Two contexts: host `bin/scout --domain=rent doctor` vs `docker compose run --rm rent-scout doctor`. The domain lives in the compose `entrypoint`, never in `command:` (`run ... doctor` REPLACES command; the first car deploy ran the RENT doctor). [Observed: 2026-08-29; Source: compose.yaml]
- `src/` is baked into the image; `config/` is a read-only bind mount; `state/` a read-write bind mount (not a named volume: `down -v` cannot delete the seen-set). A pull changes criteria at once, code only after rebuild. Container user `${SCOUT_UID:-1000}:${SCOUT_GID:-1000}` (uid 10001 could not create the DB in the bind mount). [Source: compose.yaml, Dockerfile]
- Redeploy: tag `scout:pre-<x>`, `build` BEFORE stopping, rehearse the migration on a `.backup`, stop, `tools/backup-state.sh`, `up -d --remove-orphans`, `verify-deploy.sh`. [Source: RUNBOOK]
- `verify-deploy.sh` asserts: every declared service has a running container, current image, image built AFTER the newest commit touching the image inputs (derived from `grep -E '^(COPY|ADD)' Dockerfile`; `config/` excluded because the mount wins), no hex-prefixed leftover. [Source: verify-deploy.sh; Observed: 2026-09-04]
- Hex-prefixed `^[0-9a-f]{12}_` container: "EST le service" (wedged recreate: new `Created` beside the renamed running old one) is NOT removed, `--force-recreate`; "Orphelins": `docker rm -f`; another project's: count only. Key per ROW of `docker compose ps -a` (per-service keying offered the running container to `rm -f`); without `-a` a stopped service is omitted. [Observed: rows 62-63, ~2026-09-08]
- Watcher stop ends in SIGKILL 137 by design: `Cli/WatchLoop` finishes the pass in flight, `stop_grace_period: 5m` (all three services), a real pass exceeds 5 min. Measured 2026-09-07: stop 23:15:55, SIGKILL 21:20:55Z, 4 of 8 sources recorded, `OOMKilled=false`. Check `docker inspect <name> --format '{{.State.OOMKilled}} {{.State.FinishedAt}}'` (`docker ps` shows CreatedAt, not the exit). Docker's default 10 s would SIGKILL mid-pass: a kill between "sent" and "notified_at written" = duplicate-notification storm. [Observed: 2026-09-07; Source: compose.yaml]
- Resume with watchers down, classify first: (a) announced wipe (2026-09-27): state survives in `./state`; confirm `state/*.sqlite3` (empty seen-set: Q36 refuses, `--seed` needed), then `docker compose build`, `setsid docker compose up -d`, `verify-deploy.sh`. (b) all three `exited (137)`, image and state intact (2026-10-01): `setsid docker compose up -d`, no rebuild; cause "stopped by /stack work" [Inferred]. [Observed: 2026-09-27, 2026-10-01]
- Q36 guard: `run` REFUSES on an empty seen-set; with `restart: unless-stopped` that is a visible restart loop, reason in `state/rent-last-refusal.txt`. Fresh host: `.env` -> `doctor` -> `run --once --seed` -> `up -d`. Moving host: carry seen-set, `.env`, `criteria.local.json`; do NOT re-run `--seed` (swallows listings that appeared during the move). Car seed is load-bearing (~3,400 lots). [Source: compose.yaml, RUNBOOK; Observed: 2026-09-14]
- Delivery proof: a re-stamped `state/rent-digest.txt` / `state/car-rollup.txt` proves delivery only if the queue had something; use `docker compose run --rm rent-scout test-notify` (exit 1 if no channel reaches a human). [Observed: 2026-09-13]
- Backup: `tools/backup-state.sh` uses the SQLite online-backup API, checkpoints WAL, reads the copy back (`integrity_check` + row count), keeps 7, cron `0 4 * * *`; `cp` of a WAL DB gives a torn file with a plausible count. Cron hosts also owe `30 8 * * * ... rent-scout digest` and `35 8 * * * ... car-scout rollup`. It once reported "0 annonces" for 3 544 car vehicles (counted the rent table). [Source: RUNBOOK 1.4/4/5; Observed: 2026-09-01]
- `.env`/build traps: `Config\DotEnv` applies the FIRST occurrence of a key and an empty string counts as set; `doctor` WRITES a run (throwaway `RENT_SCOUT_DB`, paired with `MAILBOX_DIR=`; `--source=` force-runs a disabled source); `docker-php-ext-install dom` broke DOM (`php -m` first). [Source: RUNBOOK; s-tech-a s3]

## Sabotage ledger: the case recipe (REFERENCE s10)
- Shape: `SABOTAGE_SHARD=<i>/<n>` selects by case INDEX and refuses a spec selecting no case; `fail-fast: false` is load-bearing; the `sabotage-alert` job opens ONE issue, closes the backlog on a green night, names a missing shard. Run after changes to the tenure module, `Core/Text.php`, corpus, `Core/Pacer.php`, `Cli/WatchLoop.php`, `Rent/Adapters/PacedSource.php`, `Rent/Store/`. [Ruled: developer 2026-09-05; Source: RUNBOOK 6-7]
- **Apply, parse, detect** on a scratch copy: `cp`, run the sed, `diff -q` (applied), `php -l` the mutated copy (parses), real run answers detected. A two-line expression never applies. A parse error reads "proves nothing either way" (skimmed as pass); 2026-09-04: three of seven new cases defective. [Observed: 2026-09-04]
- **One-line sed only; batch all new cases into ONE filtered run** (each run pays two full-suite baselines). Raise `SABOTAGE_SUITE_TIMEOUT` to 1500-1800 and check load before reading a lone timeout as undetected (7-8 min suite on the host; the core suite alone is 62 s in the image, 159 s on the host, 2026-10-03). [Observed: 2026-09-04/13/26]
- **Mutate the CONSEQUENCE, keep the call**: `if ($refusal !== null)` -> `if (false)` scoped to ONE method by a function address range. Deleting the `->refuses(` token left only the structural guard `SectionOneGateCallSitesTest` red: case `ok`, section 1 tested by nothing. A structural or `Scout\Tests\Repo\` guard answering for a behavioural one has measured its own scaffolding. [Observed: 2026-09-09/10]
- **Replace the right-hand side with the assignment intact** (`$drained = 0;`, not `$drained = ;`). One commit rotted four cases: three parse errors, the fourth formed `$drainedKeys = $sectionOne = new SectionOneGate(...)`, parsed, and reported `ok` nightly for three days; `test-sabotage-applies.sh` cannot see it (the expression still matched). After any refactor that changes a call's SHAPE, re-measure the changed-line count and read which test reddens. [Observed: 2026-09-09]
- **Two controls**: a scratch tree built ONCE (the ledger's copy list, `ln -s tools`, NEVER symlink `vendor`); per case `cp -a` sidecar -> plain `sed -i` -> `php -l` -> full suite -> partition failing classes (`Scout\Tests\Repo\` = structural) -> restore. Control 1: the unmutated baseline is green (`OK 3128 / 12118` on 2026-09-10). Control 2: null the OTHER route alone and require the new test to stay green. "Detects" != "a behavioural test covers it". [Observed: 2026-09-07..10]
- **Compound cases**: before compounding undetected cases, measure whether the shadow is a GATE or the FIXTURE. A guarantee defended by TWO layers is green under either single mutation; nulling two routes and going red proves neither observable. It cannot be a ledger case (`run_sabotage` takes ONE file): annotate in place. Count changed lines (`diff | grep -c '^<'`). Enumerate CALL SITES (`ExcludedDwellings::match()` had five, the ledger four). [Observed: 2026-09-07..10]
- **Two cases sharing a sed** can hit four guards: scope each with a function range (the applies-gate proves a MATCH, never ONE). [Observed: 2026-09-07]
- **Code moves make expressions INERT** (39 of 607 after the `Core/RunStore` split). Retarget the FILE path, not the expression. [Observed: 2026-09-01/04]
- **Sed traps**: `sed -E` turns `()` in a BRE expression into an empty group; `\\Seen` in a single-quoted expression needs two backslashes (four match nothing); a compound sed script runs its commands BOTTOM-UP (2 of 154 generated cases); a scripted runner needs PHPUnit `--colors=never` or coloured `OK (` defeats the `^OK \(` anchor. [Observed: 2026-09-05/14]
- **Failure labels**: only `[UNDETECTED]` is a finding about the tests; `[inconclusive-parse-error]`, `[inert-expression]`, `[inconclusive-timeout]`, `[harness-*]` are not. The two headings (`%d undetected` printf, `undetected or unapplied:`) are pinned by text in `tests/test-ci-workflow.sh`: do not reword. [Observed: 2026-09-09; Source: tests/sabotage-check.sh]
- **Guard scripts that execute the suite** (`test-ci-workflow.sh`, `test-sabotage-baseline.sh`) fail spuriously on an unsettled tree: settle the tree (`git status --porcelain` empty), rerun. [Observed: 2026-08-31]
- **Host run env (not needed in the dev image)**: take `PHP_INI_SCAN_DIR` from `php --ini | sed -n 's/^Scan for additional .ini files in: *//p'`, strip the quotes, require the `php -m` diff empty (unquoted loses 12 extensions: iconv, intl, gmp). A ledger ABORT under a custom env is an env fault until the plain suite says otherwise. `SABOTAGE_FILTER` joins with `|`. [Observed: 2026-09-13/26, 2026-08-30]

## CI, long jobs, exit codes
- ci.yml jobs: `test` (suite + ~12 bash self-tests + drift-scan + `bash -n`), `sabotage` (nightly/dispatch, 6 shards, 240-min cap), `sabotage-alert`. A red `test` job is silent: `gh run list --limit 5` after EVERY push. Emulate the runner's production ini: `php -d zend.exception_ignore_args=1 tools/phpunit.phar --filter <Class>`. [Observed: 2026-09-05; Source: rules/tests.md]
- Long jobs: `run_in_background` was killed at ~10 min (`status: killed`); a pipe to `tail` makes a kill read "no output". Use `setsid nohup env VAR=... bash script.sh > "$log" 2>&1 < /dev/null & disown`, poll the log with Read, wait on `EXIT=`. If a process check is unavoidable: `pgrep -f 'sabotage-chec[k].sh'`. Kill by PGID: `kill -TERM -- -$(ps -o pgid= -p <pid> | tr -d ' ')`; `pkill -f` killed the Bash tool's own shell (exit 144). [Observed: 2026-09-04/25/26]
- **137**: after a watcher stop it is the design (above); read `OOMKilled` before saying OOM. **134**: `zend_jit_trace.c ... Assertion !p->op_array failed` is harness broke, not detection (host changed 2026-09-28: read `php -v`); CI unaffected. Local runs take ~14x CI. [Observed: 2026-08-30, 2026-09-26, 2026-10-02]

## Traps (symptom -> cause)
- "didn't terminate within 300s" on every case: default timeout vs a 7-8 min suite. Corpus "Class not found" with unit green: `dump-autoload` without `--dev`.
- `git add -A` after `git mv config/criteria.json config/rent/` staged `criteria.local.json` (home address): `.gitignore` still said `/config/*.local.json`; widen it BEFORE the move, read `git status` for an `A` line, `git rm --cached -f`. [Observed: Rent/ config split, date not stated]

## Before you start (per path)
- `tests/sabotage-check.sh`: `git status --porcelain` empty; load via `uptime` and `ps -eo pid,etimes,args --sort=-etimes | grep phpunit`; byte copy of the target; read the function you mutate and its call sites.
- `tests/test-*.sh`: run `tools/in-docker.sh bash tests/test-sabotage-applies.sh` after any code move; self-tests need `tools/phpunit.phar` (`tools/fetch-phpunit.sh`).
- `tools/verify-deploy.sh`, `compose.yaml`, `Dockerfile`: read the matching RUNBOOK section; `tools/in-docker.sh bash tests/test-verify-deploy.sh`; compose config check (rule 6); back up first.
- `tools/backup-state.sh`: `tools/in-docker.sh bash tests/test-backup-state.sh`; never `cp` a live WAL DB.
- `.github/workflows/ci.yml`: `tools/in-docker.sh bash tests/test-ci-workflow.sh` pins its steps and the failure-label headings.
- Any pattern in `config/`: PCRE2 10.42 portability (`PortablePatternsTest` refuses a variable-length lookbehind).

## Evidence table
| Change | Certified by | Sabotage shape | Uncertified unless run |
|---|---|---|---|
| Ledger case | applies + parses + detected, both controls, changed-line count, restored byte-exact | the consequence, assignment intact | nightly (hours) |
| Deploy / watcher | `verify-deploy.sh` exit 0 + `test-notify` | | first deployed pass; channel on an empty-queue day |
| Backup | `test-backup-state.sh`, read-back count | | restore drill [Unverified] |
| CI / workflow | `gh run list` on the full SHA, every job read | | the nightly |
| Record path | `PipelineRunTest` + `FixedPlanner` + logs `annonce(s) analysees` | clone-with, never field copy | |
