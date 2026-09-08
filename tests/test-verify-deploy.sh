#!/usr/bin/env bash
#
# Sabotage test FOR tools/verify-deploy.sh — the mechanical half of F25.
#
# The verifier exists because a wedged recreate announces nothing: `docker compose ps` without `-a`
# OMITS a service that is not running, so a watcher being DOWN renders as a shorter list. If the
# verifier itself were to report green on that state it would be worse than not existing — a check
# nobody doubts is a check nobody repeats.
#
# So every case here drives it into a failure state through a STUB `docker` and asserts it goes red,
# plus the counterweight: a healthy deployment must still pass, or the guard is satisfied by
# refusing everything.
#
# The stub is a real script first on PATH; the verifier is otherwise untouched, and no case can
# reach a real container or image.
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

pass=0
fail=0
ok() { printf '  \033[32m✓\033[0m %s\n' "$1"; pass=$((pass + 1)); }
ko() { printf '  \033[31m✗\033[0m %s\n' "$1"; printf '      %s\n' "${2:-}"; fail=$((fail + 1)); }

CURRENT='sha256:aaaaaaaaaaaa'
STALE='sha256:bbbbbbbbbbbb'

mkdir -p "$TMP/bin"
cat > "$TMP/bin/docker" <<'STUB'
#!/usr/bin/env bash
# A stub whose every answer is an environment variable, so a case describes a deployment state
# rather than patching the verifier.
case "$1 ${2:-}" in
  "image inspect")
    [[ "${STUB_IMAGE_MISSING:-0}" == 1 ]] && exit 1
    # TWO different questions reach this branch, and answering both with the image ID made the
    # image-age check silently indeterminable — a vacuous pass, which is the shape the whole file
    # is about. Distinguish on the FORMAT, which is what the caller actually varies.
    if [[ "$*" == *'{{.Created}}'* ]]; then
      printf '%s\n' "${STUB_IMAGE_CREATED:-2099-01-01T00:00:00Z}"
    else
      printf '%s\n' "${STUB_CURRENT_IMAGE}"
    fi ;;
  "compose ps")
    printf '%b' "${STUB_PS_ROWS}" ;;
  "compose config")
    printf '%b' "${STUB_SERVICES}" ;;
  "inspect --format")
    # `docker inspect --format '{{.Image}}' <name>` — the last argument is the container name.
    name="${!#}"
    case "$name" in
      *"${STUB_STALE_CONTAINER:-__none__}"*) printf '%s\n' "${STUB_STALE_IMAGE}" ;;
      *) printf '%s\n' "${STUB_CURRENT_IMAGE}" ;;
    esac ;;
  "ps -a")
    printf '%b' "${STUB_LEFTOVERS:-}" ;;
  "info ")
    # An unreachable daemon is a DIFFERENT answer from a missing image, and reporting it as one
    # sent the operator to build while the builder was down.
    exit "${STUB_DAEMON_DOWN:-0}" ;;
  *) exit 0 ;;
esac
STUB
chmod +x "$TMP/bin/docker"

# EVERY KNOB IS CLEARED BEFORE EVERY CASE, and this is not tidiness. `PS_ROWS=x out="$(run)"` with
# no command after it is a plain ASSIGNMENT LIST, not a temporary environment — so the first draft
# leaked `IMAGE_MISSING=1` and `STALE_CONTAINER` forward, and two cases passed on a failure they had
# not asked for. A test proving something other than what it says is the trap this repo names most
# often; here it was introduced by the test for the guard against exactly that class.
reset_case() {
  unset PS_ROWS SERVICES LEFTOVERS STALE_CONTAINER IMAGE_MISSING IMAGE_CREATED DAEMON_DOWN
}

run() {
  PATH="$TMP/bin:$PATH" \
  STUB_CURRENT_IMAGE="$CURRENT" STUB_STALE_IMAGE="$STALE" \
  STUB_SERVICES="${SERVICES-rent-scout\ncar-scout\n}" \
  STUB_PS_ROWS="${PS_ROWS}" \
  STUB_LEFTOVERS="${LEFTOVERS:-}" \
  STUB_STALE_CONTAINER="${STALE_CONTAINER:-__none__}" \
  STUB_IMAGE_MISSING="${IMAGE_MISSING:-0}" \
  STUB_IMAGE_CREATED="${IMAGE_CREATED:-2099-01-01T00:00:00Z}" \
  STUB_DAEMON_DOWN="${DAEMON_DOWN:-0}" \
    bash "$ROOT/tools/verify-deploy.sh" 2>&1
}

healthy='rent-scout\tscout-rent-scout-1\trunning\ncar-scout\tscout-car-scout-1\trunning\n'

# ── THE COUNTERWEIGHT FIRST. Without it every assertion below is satisfied by a script that always
#    fails, which is a deployment nobody can verify rather than one nobody can break silently.
reset_case; PS_ROWS="$healthy" out="$(run)"; code=$?
if [[ $code -eq 0 && "$out" == *"déploiement vérifié"* ]]; then
  ok "a healthy deployment passes"
else
  ko "a healthy deployment passes" "exit=$code out=$out"
fi

# ── 1. THE SILENT ONE. A service with no container at all: `ps` without -a would not have listed it,
#    and the operator reads a shorter list rather than a failure.
reset_case; PS_ROWS='rent-scout\tscout-rent-scout-1\trunning\n' out="$(run)"; code=$?
if [[ $code -eq 1 && "$out" == *"AUCUN conteneur"* ]]; then
  ok "a service with NO container is named, not omitted"
else
  ko "a service with NO container is named, not omitted" "exit=$code out=$out"
fi

# ── 2. `Created`, which is the exact state both 2026-08-31 attempts left rent-scout in.
reset_case; PS_ROWS='rent-scout\tscout-rent-scout-1\tcreated\ncar-scout\tscout-car-scout-1\trunning\n' out="$(run)"; code=$?
if [[ $code -eq 1 && "$out" == *"created"* ]]; then
  ok "a container stuck in 'created' is a failure, not a start"
else
  ko "a container stuck in 'created' is a failure, not a start" "exit=$code out=$out"
fi

# ── 3. THE STALE-IMAGE CASE, and it is the one a human cannot see. `up -d` printed Started; the
#    container runs code from three deploys ago. `src/` is baked into the image, so nothing in
#    `git status` or a green suite disagrees.
reset_case; PS_ROWS="$healthy" STALE_CONTAINER='car-scout' out="$(run)"; code=$?
if [[ $code -eq 1 && "$out" == *"AUTRE image"* ]]; then
  ok "a container running a previous image is caught"
else
  ko "a container running a previous image is caught" "exit=$code out=$out"
fi

# ── 3b. IS THE IMAGE ITSELF NEWER THAN THE CODE? A different question from case 3, with the same
#    comforting output. Case 3 asks whether the containers run the image that was built; this asks
#    whether that image was built from the code that was committed. `src/` is baked in, so a pull
#    changes the criteria immediately and changes no code at all — every container reporting
#    "running, image courante" throughout. This is the failure that left a §1 fix unarmed in
#    production for a day and a half on 2026-09-04, and an earlier instance ran seventeen hours.
#
#    Driven through the REAL repository rather than a stub, because the check reads `git log`
#    directly: a stub docker cannot express "the image is older than HEAD's src commit". The image
#    date is what the stub controls, so an epoch far in the past is a stale build by construction.
reset_case; PS_ROWS="$healthy" out="$(PATH="$TMP/bin:$PATH" \
  STUB_CURRENT_IMAGE="$CURRENT" STUB_STALE_IMAGE="$STALE" \
  STUB_SERVICES='rent-scout\ncar-scout\n' STUB_PS_ROWS="$healthy" STUB_LEFTOVERS='' \
  STUB_STALE_CONTAINER='__none__' STUB_IMAGE_MISSING=0 STUB_IMAGE_CREATED='2001-01-01T00:00:00Z' \
  bash "$ROOT/tools/verify-deploy.sh" 2>&1)"; code=$?
if [[ $code -eq 1 && "$out" == *"ANTÉRIEURE au dernier commit"* ]]; then
  ok "an image older than its newest build input is a failure, not a green deploy"
else
  ko "an image older than its newest build input is a failure, not a green deploy" "exit=$code out=$out"
fi

# THE COUNTERWEIGHT: an image built after the code passes, or the check is satisfied by always
# failing — which would make every real deploy look broken and train the operator to ignore it.
reset_case; PS_ROWS="$healthy" out="$(PATH="$TMP/bin:$PATH" \
  STUB_CURRENT_IMAGE="$CURRENT" STUB_STALE_IMAGE="$STALE" \
  STUB_SERVICES='rent-scout\ncar-scout\n' STUB_PS_ROWS="$healthy" STUB_LEFTOVERS='' \
  STUB_STALE_CONTAINER='__none__' STUB_IMAGE_MISSING=0 STUB_IMAGE_CREATED='2099-01-01T00:00:00Z' \
  bash "$ROOT/tools/verify-deploy.sh" 2>&1)"; code=$?
if [[ $code -eq 0 && "$out" == *"postérieure à toutes ses entrées"* ]]; then
  ok "an image built after every build input passes"
else
  ko "an image built after every build input passes" "exit=$code out=$out"
fi

# ── 4. The leftover is not this deploy's failure, it is the next one's: it holds the name that makes
#    the following recreate die on `Conflict. The container name … is already in use`.
reset_case; PS_ROWS="$healthy" LEFTOVERS='d9272b63ebf1_scout-car-scout-1\n' out="$(run)"; code=$?
if [[ $code -eq 1 && "$out" == *"orphelins"* && "$out" == *"d9272b63ebf1"* ]]; then
  ok "a hex-prefixed leftover is reported, with the command to remove it"
else
  ko "a hex-prefixed leftover is reported, with the command to remove it" "exit=$code out=$out"
fi

# ── 4b. THE COUNTERWEIGHT CASE 4 LACKED, and its absence let the tool contradict itself for a day.
#    A recreate interrupted mid-flight — a foreground `timeout` SIGTERMing the whole process group
#    while compose sat in its minutes-long stop grace period — leaves the RENAMED container RUNNING
#    as the service rather than dead. Compose still resolves the service to it, so it appears in BOTH
#    lists: the loop above certifies it `running, image courante`, and the leftover scan named that
#    same container an orphan with `docker rm -f` beside it. One real run on 2026-09-07 printed both,
#    four lines apart. The service check was right; the remedy would have killed the watcher.
#
#    The `rm -f` line is EXTRACTED before it is asserted on. A glob over the whole output is satisfied
#    or defeated by which section printed first, not by what the command line contains — the
#    unintended-match shape this repo names as the remainder-digit-boundary scar.
wedged='rent-scout\tscout-rent-scout-1\trunning\ncar-scout\t0250190bdb78_scout-car-scout-1\trunning\n'
reset_case; PS_ROWS="$wedged" LEFTOVERS='0250190bdb78_scout-car-scout-1\n' out="$(run)"; code=$?
rm_line="$(printf '%s\n' "$out" | grep -F 'docker rm -f')"
if [[ $code -eq 1 && "$out" == *"EST le service"* && "$out" == *"--force-recreate"* \
      && "$rm_line" != *"0250190bdb78"* ]]; then
  ok "a renamed container that IS the live service is never offered to 'docker rm -f'"
else
  ko "a renamed container that IS the live service is never offered to 'docker rm -f'" \
     "exit=$code rm_line='$rm_line' out=$out"
fi

# ── 4c. BOTH AT ONCE, which is what makes the split more than a relabelling. A constant classifier
#    passes 4 or 4b but never both: everything-live loses the corpse, everything-dead resurrects the
#    contradiction. Here one hex container is the live service and the other is a corpse, and each
#    must draw its own verdict and its own command.
reset_case; PS_ROWS="$wedged" \
  LEFTOVERS='0250190bdb78_scout-car-scout-1\nd9272b63ebf1_scout-rent-scout-1\n' out="$(run)"; code=$?
rm_line="$(printf '%s\n' "$out" | grep -F 'docker rm -f')"
if [[ $code -eq 1 && "$out" == *"EST le service"* && "$out" == *"orphelins"* \
      && "$rm_line" == *"d9272b63ebf1"* && "$rm_line" != *"0250190bdb78"* ]]; then
  ok "a live rename and a corpse in one run get opposite commands"
else
  ko "a live rename and a corpse in one run get opposite commands" \
     "exit=$code rm_line='$rm_line' out=$out"
fi

# ── 4d. `docker ps -a` IS MACHINE-WIDE, and this host runs other compose projects. A hex-prefixed
#    container naming none of OUR services is somebody else's interrupted recreate: it cannot make
#    our next one fail, and printing `docker rm -f` beside it is the same destructive remedy with a
#    wider blast radius than the one this whole case set exists for.
reset_case; PS_ROWS="$healthy" LEFTOVERS='ab12cd34ef56_global_stack-03node24-1\n' out="$(run)"; code=$?
if [[ $code -eq 0 && "$out" != *"orphelins"* && "$out" != *"docker rm -f"* ]]; then
  ok "another project's hex-prefixed leftover is not this project's to delete"
else
  ko "another project's hex-prefixed leftover is not this project's to delete" "exit=$code out=$out"
fi

# ── 5. No image at all is a DIFFERENT answer from a bad deployment: exit 2, "build it first".
#    Collapsing the two would let a missing build read as a broken watcher.
reset_case; PS_ROWS="$healthy" IMAGE_MISSING=1 out="$(run)"; code=$?
if [[ $code -eq 2 && "$out" == *"n'existe pas"* ]]; then
  ok "a missing image exits 2 and says to build, not 1 and 'watcher down'"
else
  ko "a missing image exits 2 and says to build, not 1 and 'watcher down'" "exit=$code out=$out"
fi

# ── 6. And an unreadable compose file is exit 2 as well, never a green run over zero services —
#    the vacuous pass this repo has been bitten by in check-image-versions and drift-scan alike.
reset_case; PS_ROWS="$healthy" SERVICES='' out="$(run)"; code=$?
if [[ $code -eq 2 && "$out" == *"aucun service"* ]]; then
  ok "zero services is a refusal, never a vacuous green"
else
  ko "zero services is a refusal, never a vacuous green" "exit=$code out=$out"
fi

# ── 7. A DOWNED DAEMON IS NOT A MISSING IMAGE. Both exit 2, so the exit code alone cannot tell them
#    apart — the MESSAGE is the finding, because "build it" is the wrong instruction when the thing
#    that would build it is the thing that is down.
reset_case; PS_ROWS="$healthy" DAEMON_DOWN=1 out="$(run)"; code=$?
if [[ $code -eq 2 && "$out" == *"injoignable"* && "$out" != *"construisez-la"* ]]; then
  ok "an unreachable daemon says so, and does NOT tell the operator to build"
else
  ko "an unreachable daemon says so, and does NOT tell the operator to build" "exit=$code out=$out"
fi

printf '\n  %d passed, %d failed\n\n' "$pass" "$fail"
(( fail == 0 ))
