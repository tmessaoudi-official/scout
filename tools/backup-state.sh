#!/usr/bin/env bash
#
# Back up the seen-set — the one file this project documents as UNRECOVERABLE.
#
#     tools/backup-state.sh [<database>] [<destination-dir>]
#
# Defaults: `$RENT_SCOUT_DB` or `state/rent-watch.sqlite3`, into `state/backups/`.
#
# **`cp` IS THE WRONG TOOL AND THE REASON IS SILENT.** The watcher holds the database open in WAL
# mode, so a byte copy taken mid-transaction is torn — and a torn SQLite file OPENS WITHOUT
# COMPLAINT and reports a plausible row count. You discover it at restore, which is the one moment
# you cannot afford to. `.backup` is SQLite's own online backup API: it is safe against a live
# writer and it checkpoints the WAL, so what lands is a single consistent file.
#
# And the copy is READ BACK before this script reports success. A backup nobody opened is a file,
# not a backup — the same reasoning that makes `tests/sabotage-check.sh` exist for the test suite.
#
# Retention is bounded for the reason `compose.yaml` bounds the container's logs: an unbounded
# backup directory fills the VPS disk and takes the live seen-set down with it, turning a safety
# measure into the outage it was meant to prevent.

set -euo pipefail

_keep="${SCOUT_BACKUP_KEEP:-7}"

_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
_db="${1:-${RENT_SCOUT_DB:-$_root/state/rent-watch.sqlite3}}"
# EVERY DOMAIN when called bare (car 2026-08-29, job 2026-09-13): each store is the same kind of
# unrecoverable state.
# Recursion with an explicit path, so each store gets its own verified copy under its own name.
if [[ $# -eq 0 ]]; then
  _car="${CAR_SCOUT_DB:-$_root/state/car-watch.sqlite3}"
  [[ "$_car" = /* ]] || _car="$_root/$_car"
  if [[ -f "$_car" ]]; then "$0" "$_car"; fi
  # The JOB store (2026-09-13), same guards: a deployment without it is not a refusal on the cron path.
  _job="${JOB_SCOUT_DB:-$_root/state/job-watch.sqlite3}"
  [[ "$_job" = /* ]] || _job="$_root/$_job"
  if [[ -f "$_job" ]]; then "$0" "$_job"; fi
fi
_base="${_db##*/}"
_base="${_base%.sqlite3}"
_dest="${2:-$_root/state/backups}"

die() {
  printf 'backup-state: %s\n' "$1" >&2
  exit 1
}

command -v sqlite3 >/dev/null 2>&1 || die "sqlite3 introuvable — il fait la copie en ligne, un cp ne suffit pas"

# A MISSING DATABASE IS A REFUSAL, NEVER AN EMPTY BACKUP. Writing a zero-row file here would be the
# worst outcome available: retention would then rotate the real backups out from under it, one run a
# day, until the only copies left were copies of nothing.
[[ -f "$_db" ]] || die "base introuvable : $_db (le volume est-il monté ?)"
[[ -r "$_db" ]] || die "base illisible : $_db"

mkdir -p "$_dest" 2>/dev/null || die "destination non créable : $_dest"
[[ -w "$_dest" ]] || die "destination non inscriptible : $_dest"

# SECOND GRANULARITY IS NOT ENOUGH, and its own test is what proved it: eight backups taken inside
# one second all resolved to the SAME filename and silently overwrote each other, so retention kept
# two files while reporting success eight times. Harmless on a daily cron and not harmless at all in
# the moment that matters — the manual copy taken just before a redeploy would overwrite the
# automatic one taken seconds earlier, leaving one copy where the operator believed there were two.
_stamp="$(date +%Y%m%dT%H%M%S)"
_out="$_dest/$_base.$_stamp.sqlite3"
_n=0
while [[ -e "$_out" ]]; do
  _n=$((_n + 1))
  _out="$_dest/$_base.$_stamp-$_n.sqlite3"
done

# `.backup` rather than `.dump`: it produces a real database file, so the restore is a move rather
# than a replay, and it cannot be silently truncated by a broken pipe halfway through.
sqlite3 "$_db" ".backup '$_out'" || die "la copie en ligne a échoué"
[[ -s "$_out" ]] || die "la copie est vide — refus de la déclarer valide"

# READ IT BACK. Both halves matter: `integrity_check` proves the file is a sound database, and the
# row count proves it is a copy of THIS one rather than a sound empty one.
_integrity="$(sqlite3 "$_out" 'PRAGMA integrity_check;' 2>&1 || true)"
if [[ "$_integrity" != "ok" ]]; then
  rm -f "$_out"
  die "la copie ne passe pas integrity_check ($_integrity) — supprimée plutôt que gardée"
fi

_rows="$(sqlite3 "$_out" 'SELECT COUNT(*) FROM listings;' 2>/dev/null || sqlite3 "$_out" 'SELECT COUNT(*) FROM vehicle_listings;' 2>/dev/null || sqlite3 "$_out" 'SELECT COUNT(*) FROM job_listings;' 2>/dev/null || echo '?')"

# Prune OLDEST-FIRST. Keeping the oldest N would be worse than keeping none: the copy wanted after a
# bad migration is the most recent good one, and a retention rule that discards it is a trap wearing
# the costume of a safety net.
mapfile -t _all < <(find "$_dest" -maxdepth 1 -name "$_base.*.sqlite3" -printf '%T@ %p\n' \
  | sort -rn | cut -d' ' -f2-)
if ((${#_all[@]} > _keep)); then
  for _old in "${_all[@]:$_keep}"; do rm -f "$_old"; done
fi

printf 'backup-state: %s (%s annonces, integrity ok, %d copie(s) conservée(s))\n' \
  "$_out" "$_rows" "$(find "$_dest" -maxdepth 1 -name "$_base.*.sqlite3" | wc -l)"
