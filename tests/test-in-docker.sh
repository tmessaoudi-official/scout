#!/usr/bin/env bash
# Proves the dev toolchain (Dockerfile `dev` stage, compose.dev.yaml, tools/in-docker.sh) still gives
# the guarantees the "Run everything in Docker" rule rests on. Each property below was a way the setup
# could look right and be wrong:
#
#   - the runtime image must stay the LAST stage, or `docker compose build` ships a toolchain image to
#     the watchers;
#   - the dev service must NOT live in the watchers' compose project, or tools/verify-deploy.sh reads a
#     test container as a leftover and a redeploy's --remove-orphans can kill it;
#   - `state/` must be masked inside, so no test can write to the live seen-set;
#   - every tool a gate calls must be PRESENT, because a gate whose tool is missing skips and reports
#     green;
#   - files written into the mount must stay owned by the invoking user.
#
# Needs docker and the built image (`docker compose -f compose.dev.yaml build dev`); aborts rather than
# passing vacuously without them.
set -uo pipefail
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root" || exit 2

pass=0 fail=0
ok() { pass=$((pass + 1)); printf '  ok   %s\n' "$1"; }
no() { fail=$((fail + 1)); printf '  FAIL %s\n' "$1"; }
check() { local d="$1"; shift; if "$@" >/dev/null 2>&1; then ok "$d"; else no "$d"; fi; }

command -v docker >/dev/null 2>&1 || { printf 'docker introuvable — le test serait vide\n' >&2; exit 2; }
docker image inspect scout-dev:local >/dev/null 2>&1 \
  || { printf 'image scout-dev:local absente — docker compose -f compose.dev.yaml build dev\n' >&2; exit 2; }

printf '\n  static\n\n'
last_from="$(grep -E '^FROM ' Dockerfile | tail -1)"
check "the LAST Dockerfile stage is the runtime image, not dev" \
  bash -c '! grep -qE " AS dev$" <<<"$1"' _ "$last_from"
check "a dev stage exists" grep -qE '^FROM php:8\.5-cli AS dev$' Dockerfile
check "compose.dev.yaml has its own project name (not the watchers')" grep -qE '^name: scout-dev$' compose.dev.yaml
check "compose.yaml is not given a dev service" \
  bash -c '! docker compose config --services | grep -qx dev'
check "compose.yaml still declares exactly the three watchers" \
  bash -c '[ "$(docker compose config --services | sort | tr "\n" " ")" = "car-scout job-scout rent-scout " ]'
check "the dev service loads no env_file (offline, credential-free)" \
  bash -c '! docker compose -f compose.dev.yaml config --format json | jq -e ".services.dev.env_file" >/dev/null'
check "the wrapper is committed executable (git mode)" \
  bash -c '[ "$(git ls-files -s -- tools/in-docker.sh | cut -c1-6)" = 100755 ]'

printf '\n  behavioural — inside the container\n\n'
run() { SCOUT_UID="$(id -u)" SCOUT_GID="$(id -g)" tools/in-docker.sh "$@"; }

tools_missing="$(run bash -c 'for t in php composer git sqlite3 jq python3 gpg shellcheck yamllint curl sha256sum; do command -v $t >/dev/null || printf "%s " $t; done' 2>/dev/null)"
[[ -z "$tools_missing" ]] && ok "every tool a gate calls is present" || no "missing tools: $tools_missing"
check "PyYAML importable (test-ci-workflow skips its checks without it)" run python3 -c 'import yaml'
check "pcntl is loaded (the watcher's clean shutdown)" run php -r 'exit(extension_loaded("pcntl") ? 0 : 1);'

host_uid="$(id -u)"
[[ "$(run id -u 2>/dev/null)" == "$host_uid" ]] && ok "the container runs as the invoking uid" || no "uid not mapped"

marker="state/.in-docker-probe-$$"
touch "$marker"
inside="$(run bash -c "ls -A state | grep -c in-docker-probe" 2>/dev/null | tr -d '[:space:]')"
rm -f "$marker"
[[ "$inside" == 0 ]] && ok "state/ is masked: a file on the host is not visible inside" || no "state/ visible inside (saw $inside)"

run bash -c 'touch state/x-written-inside' >/dev/null 2>&1
[[ ! -e state/x-written-inside ]] && ok "a write into masked state/ never reaches the host" || { no "a write reached the host state/"; rm -f state/x-written-inside; }

probe="var/.in-docker-owner-$$"
mkdir -p var
run bash -c "touch $probe" >/dev/null 2>&1
[[ "$(stat -c %u "$probe" 2>/dev/null)" == "$host_uid" ]] && ok "a file written into the mount is owned by the invoking user" || no "wrong owner on a written file"
rm -f "$probe"

check "git sees the repo as the host does (same HEAD)" \
  bash -c '[ "$(git rev-parse HEAD)" = "$(SCOUT_UID=$(id -u) SCOUT_GID=$(id -g) tools/in-docker.sh git rev-parse HEAD)" ]'
check "the exit status of the wrapped command is propagated" \
  bash -c '! SCOUT_UID=$(id -u) SCOUT_GID=$(id -g) tools/in-docker.sh bash -c "exit 3"'

printf '\n  %d passed, %d failed\n' "$pass" "$fail"
[[ $fail -eq 0 ]]
