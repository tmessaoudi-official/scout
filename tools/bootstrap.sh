#!/usr/bin/env bash
# bootstrap.sh — turn a fresh clone into one the gates run in: the dev image, the autoloader, the
# pinned PHAR tools. Idempotent; run it once per fresh clone, which is every claude.ai cloud session
# (CLAUDE.md § "A cloud session"). On the developer's machine it is the same three steps the README's
# Getting started lists.
#
#   bash tools/bootstrap.sh
#
# A script a session RUNS, not a `.claude/cloud-setup.sh` hook (kit row 33, 2026-11-16): the kit runs
# every workspace's hook inside ONE shared 120 s budget, which an image build does not fit, and the
# agent proxy's CA is written only after that setup, so a PHAR fetched there could not be verified.
# Run from the session, the CA exists and tools/in-docker.sh hands it to the container.
#
# `bash tools/…`, not `./tools/…`: this repo has core.fileMode=false, so a mode bit is not a contract.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root"

if ! docker info >/dev/null 2>&1; then
  printf 'bootstrap: docker does not answer. In a cloud session start the daemon once\n' >&2
  printf '  (dockerd >/tmp/dockerd.log 2>&1 &), wait until `docker info` answers, then run this again.\n' >&2
  exit 1
fi

printf 'bootstrap: building the dev image\n'
docker compose -f compose.dev.yaml build dev

printf 'bootstrap: composer install (the PSR-4 autoloader, --dev included)\n'
bash tools/in-docker.sh composer install --no-interaction

for tool in phpunit phpstan; do
  if [[ -s "tools/$tool.phar" ]]; then
    printf 'bootstrap: tools/%s.phar present, not re-fetched (delete it to fetch the pinned version again)\n' "$tool"
  else
    bash tools/in-docker.sh bash tools/fetch-phar.sh "$tool"
  fi
done

printf 'bootstrap: done. Gate: bash tools/in-docker.sh php tools/phpunit.phar\n'
