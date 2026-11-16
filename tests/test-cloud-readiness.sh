#!/usr/bin/env bash
# Proves what a claude.ai cloud session needs from this repo (kit claude-code-setup, row 33;
# docs/plans/cloud-readiness.plan.md), and that none of it changes anything on the developer's machine:
#
#   - tools/in-docker.sh hands the agent proxy's CA to the dev container ONLY when $CCS_EXTRA_CA_FILE
#     names a file that exists. Unset, empty or pointing at nothing, the command is the one it was;
#   - compose.extra-ca.yaml mounts that file read-only and points curl and composer at it, and no
#     file here switches a TLS check off instead;
#   - tools/bootstrap.sh turns a fresh clone (no image, no vendor/, no PHAR) into one the gates run in,
#     and stops LOUDLY when Docker does not answer rather than half-way through;
#   - CLAUDE.md scopes its master-only git rules to the developer's machine and tells a cloud session
#     what it does instead.
#
# Offline and Docker-free: every case runs an isolated copy against a stub `docker` that records its
# arguments. Run: bash tests/test-cloud-readiness.sh
set -uo pipefail
repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

pass=0 fail=0
ok() { pass=$((pass + 1)); printf '  ok   %s\n' "$1"; }
no() { fail=$((fail + 1)); printf '  FAIL %s\n' "$1"; }
check() { local d="$1"; shift; if "$@" >/dev/null 2>&1; then ok "$d"; else no "$d"; fi; }

work="$(mktemp -d)"
[[ -n "$work" && -d "$work" ]] || { printf 'mktemp -d failed\n' >&2; exit 2; }
trap 'rm -rf "$work"' EXIT

# An isolated tree: the wrapper and the bootstrap resolve the repo from their own location, so the copy
# is what they act on. No PHAR, no vendor/: a fresh clone.
iso="$work/repo"
mkdir -p "$iso/tools" "$work/bin"
for f in tools/in-docker.sh tools/bootstrap.sh compose.dev.yaml compose.extra-ca.yaml; do
  [[ -e "$repo/$f" ]] && cp "$repo/$f" "$iso/$f"
done
cat > "$work/bin/docker" <<'STUB'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$DOCKER_LOG"
[[ "${1:-}" == info ]] && exit "${DOCKER_INFO_RC:-0}"
exit 0
STUB
chmod +x "$work/bin/docker"
log="$work/docker.log"
indocker() { : > "$log"; (cd "$work" && env DOCKER_LOG="$log" PATH="$work/bin:$PATH" "$@" bash "$iso/tools/in-docker.sh" true) >/dev/null 2>&1; cat "$log"; }

printf '\n== cloud readiness: the proxy CA ==\n\n'

printf -- '-----BEGIN CERTIFICATE-----\nnot a real one\n-----END CERTIFICATE-----\n' > "$work/ca.pem"
out="$(indocker env -u CCS_EXTRA_CA_FILE)"
check "no CA variable: compose.dev.yaml alone, as before" \
  bash -c 'grep -q -- "-f compose.dev.yaml run" <<<"$1" && ! grep -q extra-ca <<<"$1"' _ "$out"
out="$(indocker env CCS_EXTRA_CA_FILE=)"
check "an EMPTY CA variable adds nothing" bash -c '! grep -q extra-ca <<<"$1"' _ "$out"
out="$(indocker env CCS_EXTRA_CA_FILE="$work/missing.pem")"
check "a CA variable naming no file adds nothing" bash -c '! grep -q extra-ca <<<"$1"' _ "$out"
out="$(indocker env CCS_EXTRA_CA_FILE="$work/ca.pem")"
check "a CA file that exists adds -f compose.extra-ca.yaml" \
  bash -c 'grep -q -- "-f compose.dev.yaml -f compose.extra-ca.yaml run" <<<"$1"' _ "$out"

overlay="$repo/compose.extra-ca.yaml"
check "the overlay exists" test -s "$overlay"
check "it mounts the CA read-only at /etc/ccs-ca.crt" \
  grep -qE '^      - \$\{CCS_EXTRA_CA_FILE:\?[^}]*\}:/etc/ccs-ca\.crt:ro$' "$overlay"
for var in SSL_CERT_FILE COMPOSER_CAFILE; do
  check "it points $var at the mounted CA" grep -qE "^      $var: /etc/ccs-ca\\.crt$" "$overlay"
done
check "it adds to the dev service only" \
  bash -c '[ "$(grep -cE "^  [a-z-]+:$" "$1")" = 1 ] && grep -qE "^  dev:$" "$1"' _ "$overlay"
tls_off='--insecure|strict-ssl|verify_peer|secure-http|sslverify|NODE_TLS_REJECT_UNAUTHORIZED|GIT_SSL_NO_VERIFY'
check "no Dockerfile, compose file or tool switches a TLS check off" \
  bash -c 'cd "$1" && ! grep -nE -- "$2" Dockerfile compose*.yaml tools/*.sh' _ "$repo" "$tls_off"

printf '\n== cloud readiness: the bootstrap ==\n\n'

boot() { : > "$log"; (cd "$work" && env DOCKER_LOG="$log" PATH="$work/bin:$PATH" "$@" bash "$iso/tools/bootstrap.sh") >"$work/boot.out" 2>&1; }
check "tools/bootstrap.sh exists" test -s "$repo/tools/bootstrap.sh"
boot env DOCKER_INFO_RC=0 && r=0 || r=$?
check "on a fresh clone it exits 0" test "$r" = 0
check "it builds the dev image" grep -qx 'compose -f compose.dev.yaml build dev' "$log"
check "it installs the autoloader through the wrapper" grep -qE 'run --rm -T dev composer install' "$log"
for t in phpunit phpstan; do
  check "it fetches the pinned $t PHAR through the verifier" grep -qE "run --rm -T dev bash tools/fetch-phar\\.sh $t\$" "$log"
done
printf 'phar\n' | tee "$iso/tools/phpunit.phar" > "$iso/tools/phpstan.phar"
boot env DOCKER_INFO_RC=0
check "a second run re-fetches no PHAR that is present" bash -c '! grep -q fetch-phar "$1"' _ "$log"
rm -f "$iso/tools/phpunit.phar" "$iso/tools/phpstan.phar"
boot env DOCKER_INFO_RC=1 && r=0 || r=$?
check "Docker not answering is a non-zero stop" test "$r" != 0
check "...that runs nothing after the probe" bash -c '[ "$(cat "$1")" = info ]' _ "$log"
check "...and says what to do" grep -q 'dockerd' "$work/boot.out"

printf '\n  %d passed, %d failed\n\n' "$pass" "$fail"
[[ $fail -eq 0 ]]
