# dockerize Plan

## Decisions Log
- [2026-10-03 09:57] AGREED (ratified ASSUMED): CI keeps setup-php rather than moving into the dev image — because its PCRE2 10.42 differs from the image's 10.44 and that divergence caught a real lookbehind bug (2026-09-05). Alternatives: a container job alongside the setup-php job; replace setup-php entirely.
- [2026-10-03 09:57] AGREED (ratified ASSUMED): The dev service lives in compose.dev.yaml with its own project name scout-dev, not a profile in compose.yaml — because tools/verify-deploy.sh reads every container with the watchers' labels and a redeploy runs --remove-orphans. Alternatives: a dev profile in compose.yaml.
- [2026-10-03 09:57] AGREED (ratified ASSUMED): Claude hooks (.claude/hooks) stay on host python3/shellcheck — because their python3 is hook-protocol JSON plumbing and tenure-guard is a fail-open tripwire that must not depend on the Docker daemon. Alternatives: docker exec into a persistent container per edit.
- [2026-10-03 09:57] AGREED (ratified ASSUMED): state/ is masked by tmpfs in the dev container and .env is NOT masked (a file overlay would create a root-owned .env on a fresh clone) — backup-state.sh and live-database bin/scout verbs stay on the host/watcher services. Alternatives: bind /dev/null over .env.

## Formal Plan
Run every scout gate in Docker (`/dockerize-project`, 2026-10-03). Files: Dockerfile (dev stage), compose.dev.yaml, tools/in-docker.sh, tests/test-in-docker.sh, CLAUDE.md, README.md, agent charters, .claude/progress.json.

## Status
<!-- progress-block v1 -->
| # | Step | Size | State | Evidence | Files |
|---|------|------|-------|----------|-------|
| 1 | dev stage, compose.dev.yaml and the tools/in-docker.sh wrapper; host baseline and container parity | M | done | 0e5389b | Dockerfile, compose.dev.yaml, tools/in-docker.sh |
| 2 | guard test for the dev toolchain, mode read from HEAD | S | done | fc0cc99 | tests/test-in-docker.sh |
| 3 | fresh-clone defects (gpg HOME, state/ owner, one PHP_VERSION ARG) and drift-scan S6 | M | done | 2ea5573 | Dockerfile, compose.dev.yaml, tools/in-docker.sh, .claude/skills/scout-repair/drift-scan.sh, tests/test-drift-scan.sh |
| 4 | docs rewiring and the recorded lessons | S | done | fdd25ed | CLAUDE.md, .claude/rules/tooling.md |
<!-- /progress-block -->

### Known issues
- **Host-version workarounds that look obsolete inside the dev image — FLAGGED, not deleted (the developer decides).**
  Evidence: the image runs PHP 8.5.11 NTS (PCRE2 10.44, GNU grep 3.11); the host runs 8.7.0-dev ZTS DEBUG GCOV. Core suite 62 s in the
  image against 159 s on the host, identical `OK (5395 tests, 16200 assertions)`; one filtered ledger case ran clean in the image
  (`1 sabotage(s) detected, 0 undetected`). Candidates: `.claude/rules/tests.md` — the tracing-JIT / `PHP_INI_SCAN_DIR` entry (already
  marked HOST CHANGED); `.claude/rules/expertise-core.md` §5 (the local-ledger row: scan-dir quoting, `SABOTAGE_SUITE_TIMEOUT=1500`) and
  §6 (the dev/ZTS/DEBUG/GCOV machine fact); the ugrep `\|` note in CLAUDE.md's workflow block (the image's grep is GNU, where `\|` works —
  but CI and a host `grep` may still differ, so keep it until the ledger runs only in Docker).
- **Not converted, by decision (ASSUMED above):** CI (`setup-php`, PCRE2 10.42), the Claude hooks, `verify-deploy.sh`, `backup-state.sh`.
- **Open — instruction surfaces still written as bare host commands:** `.claude/rules/*`, the `domain-*` skills, RUNBOOK, ALERT-CAPTURE and
  the dated narrative. The CLAUDE.md sentence covers them; the live agents, README and progress adapter are converted and gated by
  drift-scan S6. Count them: `git grep -cE 'php tools/|bash tests/' -- .claude docs README.md`.
