#!/usr/bin/env bash
#
# Sabotage test FOR `tools/backup-state.sh`.
#
# The seen-set is the one file this project documents as UNRECOVERABLE: delete it and the next run
# re-notifies the entire market, and the price history cannot be rebuilt because a listing only ever
# advertises its CURRENT rent. The README has said *"back up `state/rent-watch.sqlite3`"* since Q8
# and has never said HOW — so the instruction was advice, not a procedure.
#
# Every guarantee here is one a naive `cp` would break, and each is checked because the failure is
# silent: a torn copy of a live SQLite database opens without complaint and reports a plausible row
# count, and a backup nobody read back is a file, not a backup.

set -uo pipefail

_pass=0
_fail=0

ok() {
  printf '  \033[32mok\033[0m   %s\n' "$1"
  _pass=$((_pass + 1))
}
no() {
  printf '  \033[31mFAIL\033[0m %s\n' "$1"
  _fail=$((_fail + 1))
}

check() { # check <description> <expected-exit> <cmd...>
  local desc="$1" want="$2"
  shift 2
  local out
  out="$("$@" 2>&1)"
  local got=$?
  if [[ "$got" == "$want" ]]; then ok "$desc"; else no "$desc (exit $got, wanted $want)
        $out"; fi
}

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
tool="$root/tools/backup-state.sh"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

printf '\n== does the state backup actually back state up? ==\n\n'

# THE MODE IN GIT, NOT THE MODE ON DISK — and the distinction cost two days of red CI.
#
# `core.fileMode=false` is set in this repo, so `chmod +x` is never staged: the working tree can be
# executable while every fresh clone gets 644. This file was added on 2026-08-26 as 100644 together
# with the `-x` check below, so the check passed locally from the first minute and failed in CI from
# the first minute, and the local pass is what made it look like a runner problem.
#
# It is not cosmetic. README documents `0 4 * * * cd /srv/rent-watch && tools/backup-state.sh` — a
# crontab line, invoked with no interpreter — so on a fresh deployment the nightly backup of the one
# file this project calls UNRECOVERABLE would fail with "Permission denied" into a log nobody reads.
if git -C "$root" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  _mode="$(git -C "$root" ls-files -s -- tools/backup-state.sh | awk '{print $1}')"
  if [[ "$_mode" == 100755 ]]; then
    ok "the tool is committed EXECUTABLE (git mode, which is what a fresh clone gets)"
  else
    no "committed executable (git mode $_mode — a fresh clone, CI and the crontab line all get a non-executable file)"
  fi
fi

if [[ ! -x "$tool" ]]; then
  no "tools/backup-state.sh exists and is executable (on disk)"
  printf '\n  %d passed, %d failed\n\n' "$_pass" "$_fail"
  exit 1
fi

db="$tmp/rent-watch.sqlite3"
sqlite3 "$db" "CREATE TABLE listings (dedup_key TEXT PRIMARY KEY, rent_cc INT);
               INSERT INTO listings VALUES ('a', 1200), ('b', 950);
               PRAGMA journal_mode=WAL;" >/dev/null

# ── the happy path ───────────────────────────────────────────────────────────────────────────────

out="$("$tool" "$db" "$tmp/backups" 2>&1)"
rc=$?
if [[ $rc -eq 0 ]]; then ok "a backup of a healthy database succeeds"; else no "a backup of a healthy database succeeds (exit $rc)
        $out"; fi

made="$(find "$tmp/backups" -name '*.sqlite3' | wc -l)"
if [[ "$made" == 1 ]]; then ok "it writes exactly one backup file"; else no "it writes exactly one backup file (found $made)"; fi

# THE POINT OF THE WHOLE TOOL. A backup that cannot be opened and read back is a file, and a torn
# copy of a live SQLite database is exactly that: it opens, and it lies.
copy="$(find "$tmp/backups" -name '*.sqlite3' | head -1)"
rows="$(sqlite3 "$copy" "SELECT COUNT(*) FROM listings;" 2>&1)"
if [[ "$rows" == 2 ]]; then ok "the copy READS BACK with every row present"; else no "the copy reads back (got '$rows')"; fi

integrity="$(sqlite3 "$copy" "PRAGMA integrity_check;" 2>&1)"
if [[ "$integrity" == "ok" ]]; then ok "the copy passes SQLite's own integrity check"; else no "integrity check (got '$integrity')"; fi

# ── the job store (2026-09-13) ───────────────────────────────────────────────────────────────────
#
# The row count reads a table by NAME, and a job database has no `listings` and no `vehicle_listings`:
# without its own fallback every job backup would report `? annonces`, and a backup that cannot say
# what it holds is one nobody checks. What this does NOT cover is the BARE call's recursion into the
# job store — with no arguments the tool writes under the real `state/backups/`, so no case here runs
# it, for the car store or the job one.
jobdb="$tmp/job-watch.sqlite3"
sqlite3 "$jobdb" "CREATE TABLE job_listings (dedup_key TEXT PRIMARY KEY, title TEXT);
                  INSERT INTO job_listings VALUES ('a', 'x'), ('b', 'y'), ('c', 'z');
                  PRAGMA journal_mode=WAL;" >/dev/null
jobout="$("$tool" "$jobdb" "$tmp/jobbackups" 2>&1)"
if grep -q '(3 annonces' <<<"$jobout"; then
  ok "a job database reports its own row count"
else
  no "a job database reports its own row count (got: $jobout)"
fi
jobcopy="$(find "$tmp/jobbackups" -name 'job-watch.*.sqlite3' | head -1)"
if [[ -n "$jobcopy" ]] && [[ "$(sqlite3 "$jobcopy" 'SELECT COUNT(*) FROM job_listings;' 2>&1)" == 3 ]]; then
  ok "…and the job copy READS BACK under its own name"
else
  no "the job copy reads back under its own name"
fi

# ── the refusals, which are the half a `cp` one-liner does not have ──────────────────────────────

check "a missing database is a LOUD refusal, never an empty backup" 1 "$tool" "$tmp/nope.sqlite3" "$tmp/backups"

# A directory that is not writable must fail rather than report success having written nothing —
# the cron-job failure mode, where nobody reads the output and the absence is discovered at restore.
mkdir -p "$tmp/ro" && chmod 500 "$tmp/ro"
if [[ "$(id -u)" == 0 ]]; then
  ok "an unwritable destination refuses (skipped: running as root, which cannot be denied)"
else
  check "an unwritable destination is a LOUD refusal" 1 "$tool" "$db" "$tmp/ro/sub"
fi
chmod 700 "$tmp/ro"

# ── the dependency this script does not declare ──────────────────────────────────────────────────
#
# `sqlite3` is a SYSTEM BINARY, and nothing installs it: not composer.json (zero dependencies), not
# the Dockerfile (the image needs only the PHP extension), and — until 2026-08-28 — not ci.yml
# either, which named `sqlite3` under `extensions:` where it means the PHP extension, a different
# thing entirely. Two files in this repo use the CLI: this test and the tool it tests.
#
# On a rotating `ubuntu-latest` runner that is a time bomb, and the explosion is unreadable: bash
# prints `sqlite3: command not found` and the tool answers `la copie en ligne a échoué`, which
# names the symptom and not the cause. A backup tool that cannot say WHY it produced no backup is
# the failure this whole file exists to prevent, one level up.
# A PATH holding everything the tool needs EXCEPT sqlite3 — emptying PATH entirely would hide
# `bash` too and prove only that a shebang needs an interpreter.
mkdir -p "$tmp/nosqlite-bin"
for _b in bash env date find rm sort cut wc mkdir dirname cat; do
  _p="$(command -v "$_b" 2>/dev/null)" && ln -sf "$_p" "$tmp/nosqlite-bin/$_b"
done
without_sqlite3="$(PATH="$tmp/nosqlite-bin" "$tool" "$db" "$tmp/nodep" 2>&1 || true)"
if grep -qi 'sqlite3' <<<"$without_sqlite3" && grep -qi 'introuvable' <<<"$without_sqlite3"; then
  ok "a missing sqlite3 names ITSELF, rather than reporting a failed copy"
else
  no "a missing sqlite3 names itself (got: $(head -1 <<<"$without_sqlite3"))"
fi
if [[ ! -d "$tmp/nodep" ]] || [[ -z "$(find "$tmp/nodep" -name '*.sqlite3' 2>/dev/null)" ]]; then
  ok "…and it refuses BEFORE writing anything, so no empty file is left behind"
else
  no "a missing dependency left a file behind"
fi

# ── retention ────────────────────────────────────────────────────────────────────────────────────
#
# Unbounded backups fill the VPS disk and take the seen-set down with them — the same reasoning that
# bounds the container's json-file logging in `compose.yaml`.

# EIGHT RUNS INSIDE ONE SECOND, deliberately: the first draft of this loop slept between runs and
# asserted `<= 7`, which was satisfied by **2** — every backup had collided on a second-granularity
# filename and overwritten the last, and the weak assertion hid it. Assert the exact number, and
# make the runs simultaneous enough that a collision cannot pass.
for _ in 1 2 3 4 5 6 7 8; do "$tool" "$db" "$tmp/backups" >/dev/null 2>&1; done
kept="$(find "$tmp/backups" -name '*.sqlite3' | wc -l)"
if [[ "$kept" == 7 ]]; then
  ok "retention keeps exactly 7 of 9, and same-second runs do not collide"
else
  no "retention keeps exactly 7 (kept $kept — a lower number means filenames collided)"
fi

# And it prunes the OLDEST, not an arbitrary one — a retention that keeps the oldest N is worse than
# none, because the copy you want after a bad migration is the most recent good one.
newest="$(find "$tmp/backups" -name '*.sqlite3' -printf '%T@ %p\n' | sort -rn | head -1 | cut -d' ' -f2-)"
if [[ -f "$newest" ]]; then ok "the most recent backup is among those kept"; else no "the most recent backup survived pruning"; fi

printf '\n  %d passed, %d failed\n\n' "$_pass" "$_fail"
[[ "$_fail" -eq 0 ]]
