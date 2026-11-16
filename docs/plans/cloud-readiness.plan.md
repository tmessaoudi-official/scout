# cloud-readiness Plan

Kit plan row 33 (claude-code-setup, W3): make scout workable from a claude.ai cloud session. The brief is
`claude-code-setup/var/claude/prompts/scout-row33.md`; the report goes to `var/claude/row33-scout-report.md`
(gitignored). `/cloud-ready --check` on `b8c9745` found CR1 and CR3 BLOCKER, CR4 and CR6 DEGRADED, CR5
DEGRADED report-only, CR2/CR8/CR9 OK.

## Decisions Log
- [2026-11-16 15:08] AGREED: I1: on the developer's machine master stays the only branch (plain git push, never -u); a cloud session (CLAUDE_CODE_REMOTE=true) commits and pushes only its harness branch (claude/*, ccr-*), never master, opens no PR unless asked, and the developer lands it with land.sh — pdfturbo's ruling of the same day is the precedent
- [2026-11-16 15:08] AGREED: Plan A-D approved as presented: no cloud-setup.sh hook under .claude (tools/bootstrap.sh instead), both PHAR signing keys committed and the keyserver path removed on every machine, CA overlay added by tools/in-docker.sh only when CCS_EXTRA_CA_FILE names a file, CR5 report-only

## Formal Plan

Baseline on `b8c9745`, before any change: `OK (5870 tests, 17376 assertions)` in the dev image, every
`tests/test-*.sh` rc=0 (14), `docker compose -f compose.dev.yaml config` sha256 `03f36b46…`. The same suite
run as uid 0 (what a cloud session is) failed exactly 2 tests, and `test-ci-workflow.sh` and
`test-sabotage-baseline.sh` failed with it because both execute that suite.

A. **Root skips (CR6).** The two PHP tests whose premise is a permission root ignores skip under
   `posix_geteuid() === 0`, as `test-drift-scan.sh` and `test-backup-state.sh` already do.
B. **Signing keys in the repo (CR3).** `tools/keys/<fingerprint>.asc` for PHPUnit and PHPStan;
   `tools/fetch-phar.sh` verifies against the committed key in a throwaway keyring and has no keyserver
   path, because dirmngr ignores `SSL_CERT_FILE` (measured: a bogus CA file left `--recv-keys` working) and
   so cannot be taught the cloud proxy's CA. An unverifiable or ABSENT signature refuses. Stated cost: a new
   upstream signing key refuses until its key file is refreshed.
C. **Proxy CA and bootstrap (CR4, CR3).** `compose.extra-ca.yaml` mounts `$CCS_EXTRA_CA_FILE` read-only at
   `/etc/ccs-ca.crt` with `SSL_CERT_FILE` and `COMPOSER_CAFILE`; `tools/in-docker.sh` adds it only when that
   file exists. No build secret: the dev stage's apt reads `http://deb.debian.org` (measured). `tools/bootstrap.sh`
   builds the dev image, runs `composer install` and fetches both PHARs. No `cloud-setup.sh` hook under `.claude`: an image
   build does not fit the 120 s hook budget and the proxy CA is written after setup.
D. **CLAUDE.md (CR1, CR7).** § Git autonomy scoped to the developer's machine, the stale deny sentence
   replaced, § "A cloud session" added. Pins in `tests/test-cloud-readiness.sh`, wired into CI.

## Status
<!-- progress-block v1 -->
| # | Step | Size | State | Evidence | Files |
|---|------|------|-------|----------|-------|
| 1 | A. Root skips for the two permission tests (CR6) | S | done | be50793 | tests/php/Car/Cli/CarScoutTest.php, tests/php/Rent/Cli/RentScoutHeartbeatTest.php |
| 2 | B. Signing keys committed, keyserver path removed (CR3) | M | done | b91aad0 | tools/fetch-phar.sh, tools/keys, tests/test-fetch-phpunit.sh |
| 3 | C. Proxy CA overlay and bootstrap (CR4, CR3) | M | done | b1e75cb | compose.extra-ca.yaml, tools/in-docker.sh, tools/bootstrap.sh, tests/test-cloud-readiness.sh |
| 4 | D. CLAUDE.md cloud paragraph and scoped git rules (CR1, CR7) | M | doing | - | CLAUDE.md |
<!-- /progress-block -->
### Blocked
### Needs input
### Needs research
### Fragile
### Known issues
- Only a real cloud session proves the toolchain, the proxy CA and the root run there.
- `b1e75cb` alone is red on drift-scan (`tools/bootstrap.sh` cited the never-created setup hook as a local
  path); step D rewords it. Bisect across that one commit with this in mind.
- CR5 is report-only: `tenure-guard.sh` and the lint hooks go silent without `python3`/their linter, on the
  PC as in the cloud; the cloud paragraph tells a session to check `python3` once.
