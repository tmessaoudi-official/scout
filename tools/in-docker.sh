#!/usr/bin/env bash
# Run a command in the dev toolchain image — the one way to run php, composer, the suite and the guard
# scripts here (CLAUDE.md § "Run everything in Docker"). The host needs Docker and nothing else.
#
#   tools/in-docker.sh php tools/phpunit.phar
#   tools/in-docker.sh bash tests/test-drift-scan.sh
#   tools/in-docker.sh php bin/scout --domain=rent doctor
#   tools/in-docker.sh                      # an interactive shell
#
# `state/` is masked inside (see compose.dev.yaml), so `bin/scout` verbs that need the live databases
# and `tools/backup-state.sh` are not run through this — use the watcher services for those.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root"

command -v docker >/dev/null 2>&1 || { printf 'in-docker: docker introuvable\n' >&2; exit 127; }

# The mapped uid/gid is the invoking user's unless the environment says otherwise.
export SCOUT_UID="${SCOUT_UID:-$(id -u)}"
export SCOUT_GID="${SCOUT_GID:-$(id -g)}"

# `-T` without a terminal: CI and hooks have none, and a TTY allocation there fails outright.
tty_flag=()
[[ -t 0 && -t 1 ]] || tty_flag=(-T)

args=("$@")
[[ ${#args[@]} -gt 0 ]] || args=(bash)

exec docker compose -f compose.dev.yaml run --rm "${tty_flag[@]}" dev "${args[@]}"
