#!/usr/bin/env bash
# fetch-phpunit.sh — fetch the test runner, and check it is the one we think it is.
#
# WHY THE RUNNER IS A PHAR AND NOT A COMPOSER DEV DEPENDENCY
# This project's container egress policy returns 403 for `codeload.github.com` and for
# `api.github.com/repos/.../zipball`, which is where Composer fetches package dists. Composer falls
# back to `git clone`, which works — and produced a 2.6 GB `vendor/` for a test runner, on a host
# with a fixed disk allowance. `phar.phpunit.de` is a different host and is not blocked. So the
# project has ZERO Composer dependencies and `vendor/` is 56 KB of generated autoloader.
# Per /root/.ccr/README.md a proxy 403 is reported rather than routed around; this is a separate
# official distribution channel, not a way around the block.
#
# WHAT THIS CHECKS, AND WHAT IT HONESTLY CANNOT
#   1. SHA-256 against a pin recorded below. Works offline, always runs.
#   2. The detached PGP signature PHPUnit publishes alongside the PHAR, against a pinned
#      fingerprint. Runs only when the public key can be fetched.
#
# The pin was established by trust-on-first-use on 2026-08-06 in this container: the PHAR and its
# .asc were downloaded, and the signature named RSA key D8406D0D82947747293778314AA394086372C20A,
# issuer sb@sebastian-bergmann.de. It was NOT verified out of band, because every public keyserver
# (keys.openpgp.org, keyserver.ubuntu.com, pgp.mit.edu) is unreachable from this container — the
# same egress policy. So the fingerprint below is a CONTINUITY check, not a root of trust: it proves
# a later fetch was signed by the same key as the first, which catches a swapped artefact but not a
# compromised-from-the-start one. Verify it out of band once from a machine with keyserver access
# (phpunit.de publishes it) and this comment can be upgraded.
#
# Run: bash tools/fetch-phpunit.sh

set -euo pipefail

# EXACT version, not the `13` series tag. `phpunit-13.phar` is a MOVING tag: upstream rolled it from
# 13.2.6 to 13.3.0 (2026-08-07) and then 13.3.1 (2026-08-13) with no change here, and because the
# mismatch branch below installs an off-pin PHAR whenever its signature verifies, CI silently ran a
# runner this repo had never pinned. That is how the nightly ledger died — 13.2.6 tolerated
# sabotage-check's `--do-not-cache-result`, 13.3.x turns it into a runner warning that
# `failOnWarning="true"` makes fatal — and nobody reviewed the upgrade because there was nothing to
# review. A versioned URL is immutable, so the SHA below can only change when this line does.
VERSION="${PHPUNIT_VERSION:-13.4.0}"
URL="https://phar.phpunit.de/phpunit-${VERSION}.phar"
DEST="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/phpunit.phar"

# Pinned for phpunit-13.4.0.phar, verified 2026-10-03: its detached signature checked GOODSIG/VALIDSIG
# against the pinned primary key below, and the suite was run with it before the pin moved. (The
# 13.3.1 pin, 2026-08-19, was taken from a versioned URL that hashed identically to the moving
# `phpunit-13.phar` it replaced.) Bump deliberately, in a commit that names the release AND changes
# VERSION above — the two must move together.
EXPECTED_SHA256="c2bf3c0be872eacc31b711f7d207ca8423e35144d466e01b53b9d30e22f391c5"
EXPECTED_KEY="D8406D0D82947747293778314AA394086372C20A"

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

printf 'fetching %s\n' "$URL"
curl -sSLf -o "$tmp/phpunit.phar" "$URL"
curl -sSLf -o "$tmp/phpunit.phar.asc" "${URL}.asc" || printf '  note: no detached signature published for this URL\n'

actual="$(sha256sum "$tmp/phpunit.phar" | cut -d' ' -f1)"

sha_ok=0
[[ "$actual" == "$EXPECTED_SHA256" ]] && sha_ok=1

if [[ $sha_ok -eq 1 ]]; then
  printf '  sha256 matches the pin\n'
else
  printf '  sha256 DOES NOT match the pin.\n    expected %s\n    got      %s\n' "$EXPECTED_SHA256" "$actual"
  printf '  "phpunit-%s.phar" is a moving tag, so a new upstream release looks exactly like a\n' "$VERSION"
  printf '  tampered download. Only a verified signature can tell them apart.\n'
fi

# ── Signature check ──────────────────────────────────────────────────────────────────────────────
# WRITTEN THIS WAY BECAUSE THE OBVIOUS WAY IS BROKEN, and a review caught it here:
#
#   if gpg --batch --verify sig file 2>&1 | grep -q "$EXPECTED_KEY"; then   # ← vulnerable shape
#
# The pipeline's exit status is grep's, not gpg's, and gpg prints `using RSA key <fingerprint>` on
# its output even when the very next line says `BAD signature`. A tampered PHAR carrying a signature
# that merely NAMES the pinned key would pass.
#
# IT NEVER PASSED HERE, and the correction matters more than the finding: `set -euo pipefail` above
# makes that pipeline fail, so the shipped script always refused. Two reviewers disagreed about this
# across two rounds; tests/test-fetch-phpunit.sh now asserts BOTH facts — vulnerable without
# pipefail, safe with it — so it cannot be re-litigated from memory. The shape was replaced anyway,
# because correctness that depends on a shell option set thirty lines away is correctness by luck.
#
# `--status-fd=1` emits the machine-readable protocol instead: `VALIDSIG <fingerprint>` appears only
# for a signature that actually verified. gpg's own exit status is checked separately.
sig_state='absent'

if [[ -s "$tmp/phpunit.phar.asc" ]]; then
  if gpg --list-keys "$EXPECTED_KEY" >/dev/null 2>&1 \
    || gpg --batch --quiet --keyserver hkps://keys.openpgp.org --recv-keys "$EXPECTED_KEY" >/dev/null 2>&1 \
    || gpg --batch --quiet --keyserver hkps://keyserver.ubuntu.com --recv-keys "$EXPECTED_KEY" >/dev/null 2>&1; then
    # `gpg_rc=$?` on the NEXT line would never run: `set -e` is on, and a bad signature makes gpg
    # exit non-zero, which aborts the script at the assignment — taking the REFUSING message below
    # with it. The outcome was still fail-closed (exit 1, nothing installed), but silently, with
    # gpg's own stderr discarded by `2>/dev/null`. A security tool that refuses without saying why
    # teaches its user to distrust the tool rather than the artefact. `|| gpg_rc=$?` keeps the
    # assignment from tripping `set -e` so the diagnostic path actually executes.
    gpg_rc=0
    gpg_out="$(gpg --batch --status-fd=1 --verify "$tmp/phpunit.phar.asc" "$tmp/phpunit.phar" 2>/dev/null)" || gpg_rc=$?

    # VALIDSIG's FIRST field is the signing key, which for a real release key is usually a SUBKEY;
    # its LAST field is the primary. The pin was taken from gpg's `using RSA key` line, i.e. the
    # signing key — but this file invites a maintainer to verify the fingerprint out of band, and
    # phpunit.de publishes the PRIMARY. Accepting either stops that correction from breaking every
    # future fetch, and neither can be forged without the private key.
    if [[ $gpg_rc -eq 0 ]] \
      && grep -q '^\[GNUPG:\] GOODSIG' <<<"$gpg_out" \
      && grep -qE "^\[GNUPG:\] VALIDSIG ($EXPECTED_KEY |.* $EXPECTED_KEY\$)" <<<"$gpg_out"; then
      sig_state='good'
      printf '  signature VERIFIED against the pinned key\n'
    else
      sig_state='bad'
      printf '  REFUSING: the signature did not verify against the pinned key %s\n' "$EXPECTED_KEY"
      exit 1
    fi
  else
    sig_state='unverifiable'
    printf '  signature present but UNVERIFIED: no public keyserver is reachable from this host.\n'
  fi
fi

# What may be installed:
#   sha pin matches                     -> yes (signature good, or unverifiable, or absent)
#   sha pin differs + signature GOOD    -> INTERACTIVE ONLY, and say so loudly. NEVER in CI.
#   sha pin differs, anything else      -> no
#
# The CI carve-out is the lesson of the 2026-08-13 → 08-19 ledger blackout. "Signature is good"
# answers "is this really from Sebastian Bergmann?" — it does NOT answer "is this the runner this
# repo was tested against?". Only the SHA answers that. Installing anyway let an unreviewed upgrade
# reach CI with no commit, no review and its only signal a log line in a job nobody watches; it
# changed runner behaviour and killed the nightly for six days. On a developer's machine the warn
# path is a convenience worth keeping. In CI a stale pin must be a red build, so a human bumps it
# deliberately — which is exactly what "pin" is supposed to mean.
if [[ $sha_ok -eq 0 ]]; then
  if [[ "$sig_state" == 'good' && -z "${CI:-}" ]]; then
    printf '  pin is stale but the signature is valid — this looks like a new upstream release.\n'
    printf '  UPDATE EXPECTED_SHA256 to %s in a commit that names the version.\n' "$actual"
  elif [[ "$sig_state" == 'good' ]]; then
    printf '  REFUSING (CI): sha256 mismatch. The signature verifies, so this is very likely a\n'
    printf '  legitimate new upstream release — but CI must not silently adopt a runner this repo\n'
    printf '  never pinned. Bump VERSION and EXPECTED_SHA256 to %s in a reviewed commit.\n' "$actual"
    exit 1
  else
    printf '  REFUSING: sha256 mismatch with no verified signature to justify it.\n'
    exit 1
  fi
fi

mv "$tmp/phpunit.phar" "$DEST"
chmod +x "$DEST"
printf 'installed %s — %s\n' "$DEST" "$(php "$DEST" --version | head -1)"
