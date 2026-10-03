# dockerize Plan

## Decisions Log
- [2026-10-03 09:57] ASSUMED (review): CI keeps setup-php rather than moving into the dev image — because its PCRE2 10.42 differs from the image's 10.44 and that divergence caught a real lookbehind bug (2026-09-05). Alternatives: a container job alongside the setup-php job; replace setup-php entirely.
- [2026-10-03 09:57] ASSUMED (review): The dev service lives in compose.dev.yaml with its own project name scout-dev, not a profile in compose.yaml — because tools/verify-deploy.sh reads every container with the watchers' labels and a redeploy runs --remove-orphans. Alternatives: a dev profile in compose.yaml.
- [2026-10-03 09:57] ASSUMED (review): Claude hooks (.claude/hooks) stay on host python3/shellcheck — because their python3 is hook-protocol JSON plumbing and tenure-guard is a fail-open tripwire that must not depend on the Docker daemon. Alternatives: docker exec into a persistent container per edit.
- [2026-10-03 09:57] ASSUMED (review): state/ is masked by tmpfs in the dev container and .env is NOT masked (a file overlay would create a root-owned .env on a fresh clone) — backup-state.sh and live-database bin/scout verbs stay on the host/watcher services. Alternatives: bind /dev/null over .env.

## Formal Plan
Run every scout gate in Docker (`/dockerize-project`, 2026-10-03). Files: Dockerfile (dev stage), compose.dev.yaml, tools/in-docker.sh, tests/test-in-docker.sh, CLAUDE.md, README.md, agent charters, .claude/progress.json.

## Status
<!-- progress-block v1 -->
| # | Step | Size | State | Evidence | Files |
|---|------|------|-------|----------|-------|
<!-- /progress-block -->
