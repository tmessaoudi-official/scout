#!/usr/bin/env bash
# test-fetch-phpunit.sh — does the runner-fetch script actually refuse what it promises to refuse?
#
# `README.md` and `CLAUDE.md` both say this script "refuses to install on a mismatch", and until
# 2026-08-06 nothing checked that claim. A review then reported the signature test as broken:
#
#     if gpg --batch --verify sig file 2>&1 | grep -q "$EXPECTED_KEY"; then
#
# whose exit status is GREP's, not gpg's — and gpg prints `using RSA key <fingerprint>` even when
# the next line reads `BAD signature`.
#
# THAT FORM IS VULNERABLE, BUT IT WAS NEVER LIVE HERE: the script sets `set -euo pipefail`, which
# makes the pipeline fail. The finding came from running the line in isolation, and two reviewers
# disagreed about it across two rounds. Both facts are asserted below — vulnerable without
# pipefail, safe with it — so the question is settled in code rather than in prose. The
# verification was rewritten anyway: correctness should not depend on a shell option set five
# lines away and inherited invisibly.
#
# This exercises the verification LOGIC against a throwaway key, without touching the network or
# the installed runner. It is deliberately not a mock: it makes gpg produce a real BAD signature.
#
# Run: bash tests/test-fetch-phpunit.sh

set -uo pipefail

repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# The verifier is tools/fetch-phar.sh since 2026-10-08 (step 7e); fetch-phpunit.sh is a wrapper.
script="$repo/tools/fetch-phar.sh"
wrapper="$repo/tools/fetch-phpunit.sh"
tools=(phpunit phpstan)

pass=0
fail=0

check() {
  local label="$1" expected="$2" actual="$3"
  if [[ "$expected" == "$actual" ]]; then
    printf '  \033[32mok\033[0m   %-56s\n' "$label"
    pass=$((pass + 1))
  else
    printf '  \033[31mFAIL\033[0m %-56s (expected %s, got %s)\n' "$label" "$expected" "$actual"
    fail=$((fail + 1))
  fi
}

work="$(mktemp -d)"
[[ -n "$work" && -d "$work" ]] || { printf 'mktemp -d failed\n' >&2; exit 1; }
export GNUPGHOME="$work/gnupg"
mkdir -p "$GNUPGHOME"
chmod 700 "$GNUPGHOME"
trap 'rm -rf "$work"' EXIT

printf '\n== fetch-phpunit: refuses a bad signature, accepts a good one ==\n\n'

# ── Static checks FIRST, deliberately. ───────────────────────────────────────────────────────────
# These need no gpg, and they are the anti-regression half: they are what would catch a revert to
# the `gpg --verify | grep` form. They used to sit AFTER the skip gate below, so whenever gpg
# misbehaved the script printed one SKIP line and exited 0 having asserted nothing — a green light
# to any caller. That is the same false-green shape sabotage-check.sh was hardened against, in the
# test written to close a different false-green.

# Every tool row of the pin table pins BOTH, read from that tool's own `case` arm — a row that
# forgot one would fetch unverified, and a count over the whole file could not say which.
for t in "${tools[@]}"; do
  arm="$(awk -v t="  $t)" '$0 == t {on=1; next} on && /^    ;;$/ {exit} on' "$script")"
  grep -qE '^    EXPECTED_SHA256="[0-9a-f]{64}"$' <<<"$arm" && r=yes || r=no
  check "the $t row pins a sha256" yes "$r"
  grep -qE '^    EXPECTED_KEY="[0-9A-F]{40}"$' <<<"$arm" && r=yes || r=no
  check "the $t row pins a key fingerprint" yes "$r"
done

grep -qE 'exec bash .*/fetch-phar\.sh" phpunit "\$@"$' "$wrapper" && r=yes || r=no
check "fetch-phpunit.sh delegates to the one verifier" yes "$r"

bash "$script" no-such-tool >/dev/null 2>&1 && r=accept || r=$?
check "an unknown tool is refused before any fetch" 2 "$r"

grep -qE '^set -euo pipefail' "$script" && r=yes || r=no
check "the script still sets pipefail (the old form's only protection)" yes "$r"

# Comments are stripped first: the script deliberately QUOTES the old broken form in a comment so a
# future reader knows why the current shape is what it is, and matching that would be a false alarm.
grep -vE '^\s*#' "$script" | grep -qE 'gpg[^|]*--verify[^|]*\|[^|]*grep' && r=piped || r=not-piped
check "the verification code does not depend on pipefail (no gpg|grep)" not-piped "$r"

grep -q 'status-fd=1' "$script" && r=yes || r=no
check "the script uses gpg --status-fd for a machine-readable verdict" yes "$r"

grep -q '|| gpg_rc=\$?' "$script" && r=yes || r=no
check "gpg's exit status is captured without tripping set -e" yes "$r"

grep -q 'VALIDSIG (\$EXPECTED_KEY \|.* \$EXPECTED_KEY\\\$)' "$script" && r=yes || r=no
check "VALIDSIG accepts the signing key OR the primary" yes "$r"


# ── A throwaway signing key, so this test needs no network and no real PHPUnit release ───────────
gpg --batch --quiet --passphrase '' --pinentry-mode loopback --quick-generate-key \
  'rent-watch test signer <test@example.invalid>' rsa2048 sign never >/dev/null 2>&1

key="$(gpg --batch --with-colons --list-secret-keys 2>/dev/null | awk -F: '/^fpr:/ {print $10; exit}')"

if [[ -z "$key" ]]; then
  # The static checks above have already run and are already counted. Report the tally and exit on
  # it rather than `exit 0`, so a caller never reads success from a run that asserted nothing.
  printf '  \033[33mSKIP\033[0m gpg could not create a test key — signature checks not exercised\n'
  printf '\n  %d passed, %d failed (static checks only)\n\n' "$pass" "$fail"
  [[ $fail -eq 0 ]]
  exit $?
fi

printf 'artefact contents\n' > "$work/art"
gpg --batch --quiet --yes --detach-sign --armor -o "$work/art.asc" "$work/art" 2>/dev/null

# The verification logic, in the SAME SHAPE as tools/fetch-phpunit.sh — including the `|| rc=$?`,
# which matters. This test shell is `set -uo pipefail` with no `-e`, so a bare `rc=$?` after the
# assignment works here; the shipped script has `-e`, where the same line never executes because a
# failing gpg aborts at the assignment. A helper that differed in exactly that dimension would pass
# for a reason the shipped code does not share, which a review caught. Keep these aligned.
verify() {
  local sig="$1" file="$2" expected_key="$3" out rc=0
  out="$(gpg --batch --status-fd=1 --verify "$sig" "$file" 2>/dev/null)" || rc=$?
  [[ $rc -eq 0 ]] \
    && grep -q '^\[GNUPG:\] GOODSIG' <<<"$out" \
    && grep -qE "^\[GNUPG:\] VALIDSIG ($expected_key |.* $expected_key\$)" <<<"$out"
}

# The OLD check, in a subshell so its shell options are controlled rather than inherited. That
# distinction is the whole story: piping `gpg --verify` into `grep` yields GREP's exit status, and
# gpg prints `using RSA key <fingerprint>` even on a BAD signature — so the form is vulnerable in a
# shell WITHOUT `pipefail`, and safe in one with it. `tools/fetch-phpunit.sh` has always had
# `set -euo pipefail`, so the bypass was never live here; a review reported it as live after testing
# the line in isolation. Both facts are asserted below so neither can be re-litigated from memory.
verify_old_form() {
  local opts="$1" sig="$2" file="$3" expected_key="$4"
  bash -c "set $opts; gpg --batch --verify '$sig' '$file' 2>&1 | grep -q '$expected_key'"
}

verify "$work/art.asc" "$work/art" "$key" && r=accept || r=refuse
check "a GOOD signature from the pinned key is accepted" accept "$r"

printf 'artefact contents TAMPERED\n' > "$work/art"

verify "$work/art.asc" "$work/art" "$key" && r=accept || r=refuse
check "a BAD signature is REFUSED" refuse "$r"

# The old form, both ways round — the record of what was and was not actually broken.
verify_old_form '-u' "$work/art.asc" "$work/art" "$key" && r=accept || r=refuse
check "old grep-piped form WITHOUT pipefail accepts a bad signature" accept "$r"

verify_old_form '-euo pipefail' "$work/art.asc" "$work/art" "$key" && r=accept || r=refuse
check "old grep-piped form WITH pipefail refuses it (what shipped)" refuse "$r"

printf 'artefact contents\n' > "$work/art"
verify "$work/art.asc" "$work/art" "0000000000000000000000000000000000000000" && r=accept || r=refuse
check "a good signature from an UNPINNED key is refused" refuse "$r"

printf '\n  %d passed, %d failed\n\n' "$pass" "$fail"

[[ $fail -eq 0 ]]
