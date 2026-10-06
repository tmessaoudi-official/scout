#!/usr/bin/env bash
# test-drift-scan.sh — the drift gate's own self-test: S8 (`.env.example` sync), S7's corpus
# breakdown claim (the one S7 shape that had never been seen red), the docs/plans/archive/ skip in
# S7 and S2 (an archived plan is history, never edited to satisfy the gate), and S6.
#
# WHY THIS FILE EXISTS. `drift-scan.sh` runs in CI and fails the build on P0/P1, which makes it a
# gate; and its own preamble records the failure every gate here is prone to — an earlier version
# incremented counters inside a python heredoc, which is a subprocess, so it printed a P0 and still
# exited 0. "A gate reporting clean because it had been disabled" is not a hypothetical in this
# repo, it is a thing that happened, and no amount of reading the script catches it.
#
# So each S8 sub-check is exercised the only way that proves anything: BREAK the thing it watches,
# and require the scan to say so. A check that has never been seen red is a check nobody has tested.
#
# S8 is the scoped subject because it is new (2026-08-22) and because it caught two live defects on
# its first run — `IMAP_PORT` declared twice, and `RW_UID`/`RW_GID` read by compose.yaml while
# `.env.example` never listed them. The other sections predate it and have their own history.
#
# The repo is NEVER mutated. Every case runs against a scratch copy of the three files S8 reads,
# with `CLAUDE_PROJECT_DIR` pointed at it — the scan honours that variable, which is what makes an
# isolated run possible at all. `src/php` and `bin/scout` are symlinked read-only rather than
# copied: they are large, S8 only greps them, and a stale copy would silently test the wrong tree.
#
# Run: bash tests/test-drift-scan.sh

set -uo pipefail

repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
scan="$repo/.claude/skills/scout-repair/drift-scan.sh"

work="$(mktemp -d)"
[[ -n "$work" && -d "$work" ]] || { printf 'mktemp -d failed; refusing to continue\n' >&2; exit 1; }
trap 'rm -rf "$work"' EXIT

pass=0
fail=0
failed_labels=()

check() {
  local label="$1"; shift
  if "$@"; then
    printf '  \033[32mok\033[0m   %s\n' "$label"
    pass=$((pass + 1))
  else
    printf '  \033[31mFAIL\033[0m %s\n' "$label"
    fail=$((fail + 1))
    failed_labels+=("$label")
  fi
}

# A scratch project S8 can be pointed at. Only what S8 reads is materialised; every other section
# of the scan simply finds nothing there and stays quiet, which is why the assertions below match
# on S8's own message text rather than on the P0/P1 tally.
scratch_project() {
  local dir="$1"
  mkdir -p "$dir"
  cp "$repo/.env.example" "$dir/.env.example"
  cp "$repo/compose.yaml" "$dir/compose.yaml"
  ln -s "$repo/src" "$dir/src"
  mkdir -p "$dir/bin" && ln -s "$repo/bin/scout" "$dir/bin/scout"
  # S8 reads SHELL readers too, since `tools/backup-state.sh` arrived — before it, every reader in
  # the project was PHP. Omitting these made `shell_read` empty here while it was populated in the
  # live repo, so S8 reported a P2 on an unmodified tree and the silence case failed. Same shape as
  # the `.claude/settings.json` omission described just below, and as the sabotage ledger's own
  # missing `.env.example`: a scratch tree that is not what it is judging.
  # `tools` ONLY, and NEVER `tests`. Linking `tests` made a later case's
  # `mkdir -p "$dir/tests/fixtures/rent/tenure"` + `cp` resolve THROUGH the link into the real
  # `tests/fixtures/rent/tenure/corpus.json` — the classifier's ground truth, which CLAUDE.md lists among
  # the files that must not be casually written. `cp` refused it as "the same file" and no damage
  # was done, but a case that WROTE rather than copied would have rewritten the corpus from a test
  # run. The scratch tree exists so the repo is never mutated; a symlink out of it defeats that
  # entirely. No test script declares an env key, so nothing is lost by leaving `tests` out.
  ln -s "$repo/tools" "$dir/tools"
  # Not read by S8 — present so that NO OTHER section crashes here. S1 opens it unguarded, and its
  # absence made the scratch project emit "checked NOTHING" on every run, which silently satisfied
  # the crash assertion below even with S8's entire body deleted. That check was then asserting
  # "some guard fires somewhere", which is not what it claims. Verified by re-running the gutting
  # sabotage: with this file present, deleting S8 makes the crash case fail as it should.
  mkdir -p "$dir/.claude" && cp "$repo/.claude/settings.json" "$dir/.claude/settings.json"
}

# S8's findings only. The scan's other sections are not under test here and would otherwise make
# every assertion depend on the whole repo staying clean.
#
# The output is CAPTURED rather than piped, and the capture tolerates a non-zero exit. That is not a
# swallowed error: `drift-scan` exits 1 **by design** whenever it finds a P0/P1, which is exactly
# what every case below is engineered to make it do. Written as a pipeline first, and all four
# mutation cases reported FAIL while the scan was working perfectly — `set -o pipefail` takes the
# leftmost non-zero status, so the scan's deliberate 1 outranked grep's successful 0. The clean case
# passed throughout, because there the scan exits 0, which is what made the harness look right.
scan_s8() {
  local out
  out="$(CLAUDE_PROJECT_DIR="$1" bash "$scan" --quiet 2>&1)" || true
  grep -E '^P[012] .*(env\.example|compose\.yaml)' <<<"$out"
}

fires_with() {
  local dir="$work/case-$1"; shift
  local pattern="$1"; shift
  local found
  scratch_project "$dir"
  "$@" "$dir"
  found="$(scan_s8 "$dir")" || true
  grep -qE -- "$pattern" <<<"$found"
}

# True when S8 says NOTHING matching $2 for the scratch project at $1 — the counterweight cases.
silent_about() {
  local found
  found="$(scan_s8 "$1")" || true
  ! grep -qE -- "$2" <<<"$found"
}

printf '\n== drift-scan self-test (S8: .env.example sync · S7: corpus breakdown · archive/ skip · S6) ==\n\n'

# ── the gate must be QUIET on a correct tree ─────────────────────────────────────────────────────
# First, because every case below asserts a message appears; if the scan reported something on a
# clean tree, all of them would pass while proving nothing.
clean="$work/case-clean"
scratch_project "$clean"
check "S8 is silent on an unmodified tree (so the cases below mean something)" \
  test -z "$(scan_s8 "$clean")"

# ── (a) duplicate keys: env_file takes the LAST occurrence ───────────────────────────────────────
# The live defect. Editing a key where it belongs silently does nothing when a second copy sits
# below it, and the resulting value is plausible, so nothing errors and nobody looks.
check "a duplicated key is reported (env_file takes the LAST occurrence)" \
  fires_with dup 'declares TZ 2 times' \
  bash -c 'printf "TZ=UTC\n" >> "$1/.env.example"' _

# ── (b) a getenv() the template does not declare ─────────────────────────────────────────────────
check "a setting read by getenv() and missing from the template is reported" \
  fires_with getenv 'RENT_HEARTBEAT_HOURS is read by getenv' \
  bash -c 'sed -i "/^RENT_HEARTBEAT_HOURS=/d" "$1/.env.example"' _

# ── (c) a ${VAR} compose substitutes that the template does not declare ──────────────────────────
# Its own direction because a compose substitution has NO getenv() to find it by: Compose resolves
# it before the container exists. This is the half that hid RW_UID/RW_GID.
check "a compose substitution missing from the template is reported" \
  fires_with compose 'compose.yaml substitutes \$\{SCOUT_UID\}' \
  bash -c 'sed -i "/^SCOUT_UID=/d" "$1/.env.example"' _

# ── (d) a template key nothing reads ─────────────────────────────────────────────────────────────
check "a template key no code reads is reported" \
  fires_with orphan 'declares FOO_LEFTOVER and no code reads it' \
  bash -c 'printf "FOO_LEFTOVER=x\n" >> "$1/.env.example"' _

# ── the allowlists must still SUPPRESS, or the gate cries wolf ───────────────────────────────────
# The other half of (d), and the reason the allowlist is named DECLARED_UNREAD rather than
# AHEAD_OF_FEATURE: "unsupported on purpose" and "not built yet" are different facts. The commented
# TELEGRAM_* keys exist precisely BECAUSE Q9 does not support that channel — filling them in must
# fail loudly rather than silently do nothing. A gate that reports them every run gets ignored
# within a week, which is the argument drift-scan's own preamble makes about itself.
check "a key named in DECLARED_UNREAD is NOT reported (the gate must not cry wolf)" \
  silent_about "$clean" 'TELEGRAM'

# SCOUT_OFFLINE is read by src/ and deliberately absent from the template: it is the test seam
# that makes CurlHttpClient refuse third-party hosts, so listing it as a setting would invite an
# operator to set it and silently disable every source while health stayed plausible.
check "SCOUT_OFFLINE is NOT demanded of the template (it is a test seam)" \
  silent_about "$clean" 'SCOUT_OFFLINE is read by getenv'

# ── the section must actually RUN ────────────────────────────────────────────────────────────────
# The failure this whole file exists for. A python section that throws writes nothing to the
# findings file, and under `set -uo pipefail` with no `-e` the tally then counts zero — clean, and
# disabled. drift-scan guards it by checking `$?` after the heredoc; this proves the guard fires,
# by making the section crash on unreadable input.
crash="$work/case-crash"
scratch_project "$crash"
# UNREADABLE `.env.example`, not a broken `src` symlink. The symlink was the first attempt and it
# was a false pass: it crashes several sections, so the check went green even with S8's whole body
# deleted — it was asserting that SOME section's guard fires, which is not what it claims. Nothing
# else in the scan reads `.env.example` (verified: the only other occurrence is S8's own heading),
# so this input can crash S8 alone. Skipped under root, where a mode of 000 is not a barrier.
chmod 000 "$crash/.env.example"
if (( EUID == 0 )); then
  printf '  \033[33mskip\033[0m a crashing S8 reports P0 (running as root: mode 000 does not deny)\n'
else
  check "a crashing S8 reports P0 rather than reading as a clean section" \
    bash -c 'out="$(CLAUDE_PROJECT_DIR="'"$crash"'" bash "'"$scan"'" --quiet 2>&1)" || true; grep -q "checked NOTHING" <<<"$out"'
fi
chmod 644 "$crash/.env.example"

# ── the scan announces the section at all ────────────────────────────────────────────────────────
check "S8 announces itself in a non-quiet run (so a silent removal is visible)" \
  bash -c 'CLAUDE_PROJECT_DIR="'"$clean"'" bash "'"$scan"'" 2>&1 | grep -q "S8 .env.example"'

# ── S7: the corpus BREAKDOWN claim ───────────────────────────────────────────────────────────────
# Added 2026-08-23, and the reason is the whole point of this file. CI run 36 went red on a stale
# `120 cases` in CLAUDE.md — S7 working exactly as designed. The commit that fixed it left a SECOND
# stale count untouched three words away, in a sentence that previously read
# "120 total, 115 synthetic + 7 real" — where 115 + 7 is 122. Every S7 pattern until now checked
# ONE number, so a claim that states a total AND its addends had two of its three numbers
# unguarded. A drifted total whose own addends disprove it survived the fix for the very drift it
# is an instance of.
#
# That citation is on ONE line and inside double quotes on purpose. `code_spans()` exempts a quoted
# span, and its regex is `"[^"\n]{0,120}"` — newline-excluding — so wrapping the quote across two
# lines makes the scan report this write-up as live drift on every run, for ever. It did exactly
# that on the first run after this section was written, which is the trap drift-scan's own preamble
# describes: documenting a bug becomes a permanent finding, and a gate that cries wolf gets
# overridden within a week. `previously` on the same line is the belt to that braces.
#
# S7 is a different shape of subject from S8 above: it reads the corpus and every tracked .md/.sh
# rather than three named files, so the scratch project needs a corpus and a doc to make a claim in.
# `scan_s8`'s message filter does not apply; these cases match S7's own text.
scratch_corpus() {
  local dir="$1"
  scratch_project "$dir"
  mkdir -p "$dir/tests/fixtures/rent/tenure"
  cp "$repo/tests/fixtures/rent/tenure/corpus.json" "$dir/tests/fixtures/rent/tenure/corpus.json"
}

# The claim under test is written into a doc the scan discovers by rglob. `CLAUDE.md` is the file
# both live instances of this phrasing sit in, so it is what the fixture uses.
claims() {
  printf 'The corpus is %s.\n' "$1" >"$2/CLAUDE.md"
}

s7_says() {
  local out
  out="$(CLAUDE_PROJECT_DIR="$1" bash "$scan" --quiet 2>&1)" || true
  grep -E '^P1 .*(N total, N synthetic|N synthetic \+ N captured)' <<<"$out"
}

# Real numbers, so a fixture that drifts from the corpus fails as loudly as the prose would.
s7_cases=$(python3 -c "import json;d=json.load(open('$repo/tests/fixtures/rent/tenure/corpus.json'));print(len(d['cases']))")
s7_synth=$(python3 -c "import json;d=json.load(open('$repo/tests/fixtures/rent/tenure/corpus.json'));print(sum(1 for c in d['cases'] if c.get('provenance')=='synthetic'))")
s7_capt=$(python3 -c "import json;d=json.load(open('$repo/tests/fixtures/rent/tenure/corpus.json'));print(sum(1 for c in d['cases'] if c.get('provenance')=='captured'))")

s7ok="$work/case-s7-clean"
scratch_corpus "$s7ok"
claims "$s7_cases total, $s7_synth synthetic + $s7_capt captured" "$s7ok"
check "S7 is silent on a breakdown claim that adds up (so the two below mean something)" \
  test -z "$(s7_says "$s7ok")"

s7bad="$work/case-s7-total"
scratch_corpus "$s7bad"
claims "$((s7_cases + 7)) total, $s7_synth synthetic + $s7_capt captured" "$s7bad"
check "a stale TOTAL in a breakdown claim is reported (the live CLAUDE.md defect)" \
  bash -c 'o="$(CLAUDE_PROJECT_DIR="'"$s7bad"'" bash "'"$scan"'" --quiet 2>&1)" || true; grep -qE "^P1 .*N total, N synthetic" <<<"$o"'

s7part="$work/case-s7-captured"
scratch_corpus "$s7part"
claims "$s7_cases total, $s7_synth synthetic + $((s7_capt + 5)) captured" "$s7part"
check "a stale CAPTURED half is reported (the number that moves as sources come online)" \
  bash -c 'o="$(CLAUDE_PROJECT_DIR="'"$s7part"'" bash "'"$scan"'" --quiet 2>&1)" || true; grep -qE "^P1 .*N synthetic \+ N captured" <<<"$o"'

# ── archived plans are HISTORY: the scan never makes anyone edit them (developer ruling 2026-10-06) ──
# The S7 gate forced two edits to a count inside an ARCHIVED plan (b41432c, then d90c56e moved
# 135 to 144 in the archived classifier plan) purely to stay green. A number in an archived plan is
# a record of what was true then, not a live claim, so the scan skips docs/plans/archive/ in every
# section that walks the docs. The SAME stale count in a live plan must still be reported: that
# second half is the boundary, and a skip widened to all of docs/plans/ reddens it.
# The file names are real ones from this repo, and the paths are built from variables, because S2
# polices every docs path this script spells out in full.
arch_dir="docs/plans/archive"
arch_doc="$arch_dir/core-tenure-classifier.plan.md"
live_doc="docs/plans/dockerize.plan.md"

s7arch="$work/case-s7-archive"
scratch_corpus "$s7arch"
mkdir -p "$s7arch/$arch_dir"
claims "$((s7_cases - 9)) total, $s7_synth synthetic + $s7_capt captured" "$s7arch"
cp "$s7arch/CLAUDE.md" "$s7arch/$arch_doc"
mv "$s7arch/CLAUDE.md" "$s7arch/$live_doc"
s7arch_out="$(CLAUDE_PROJECT_DIR="$s7arch" bash "$scan" --quiet 2>&1)" || true
check "S7 does NOT report a stale count inside docs/plans/archive/ (history is never edited for the gate)" \
  bash -c '! grep -qF -e "$1" <<<"$2"' _ "P1  $arch_doc:" "$s7arch_out"
check "S7 still reports the SAME stale count in a live plan (the skip stops at archive/)" \
  bash -c 'grep -qE -e "^P1  $1:1 claims [0-9]+ where the corpus has [0-9]+ cases" <<<"$2"' _ "${live_doc//./\\.}" "$s7arch_out"

# S2 walks the same tree: a path an archived plan cites may be renamed later, and that must not
# force a history edit either. The missing path is assembled so this script holds no literal one.
gone="docs/never-written-$$""-file.md"
s2arch="$work/case-s2-archive"
scratch_project "$s2arch"
mkdir -p "$s2arch/$arch_dir"
printf 'See %s for the detail.\n' "$gone" >"$s2arch/$arch_doc"
printf 'See %s for the detail.\n' "$gone" >"$s2arch/$live_doc"
s2arch_out="$(CLAUDE_PROJECT_DIR="$s2arch" bash "$scan" --quiet 2>&1)" || true
check "S2 does NOT report a dangling path inside docs/plans/archive/" \
  bash -c '! grep -qF -e "$1" <<<"$2"' _ "P1  $arch_doc:" "$s2arch_out"
check "S2 still reports the same dangling path in a live plan" \
  bash -c 'grep -qF -e "$1" <<<"$2"' _ "P1  $live_doc:1 cites $gone" "$s2arch_out"

# ── S6: live surfaces run the suite through tools/in-docker.sh ───────────────────────────────────
# Only S6's own message is matched: this scratch tree holds none of what the other sections read, so
# they report their own absences, which would make a tally assertion meaningless.
s6="$work/case-s6"
mkdir -p "$s6/.claude/agents"
printf 'The runner is `tools/in-docker.sh php tools/phpunit.phar`.\n' > "$s6/.claude/agents/completeness-reviewer.md"
printf '{"test_cmd": "tools/in-docker.sh php tools/phpunit.phar"}\n' > "$s6/.claude/progress.json"
printf 'tools/in-docker.sh php tools/phpunit.phar\n' > "$s6/README.md"
s6_out() { CLAUDE_PROJECT_DIR="$1" bash "$scan" --quiet 2>&1 || true; }
check "S6 is silent when every live surface goes through the wrapper (so the cases below mean something)" \
  bash -c '! grep -q "bare host" <<<"$1"' _ "$(s6_out "$s6")"
printf 'Run `php tools/phpunit.phar` to check.\n' > "$s6/.claude/agents/completeness-reviewer.md"
check "S6 reports a bare host php in a reviewer charter" \
  bash -c 'grep -qE "^P1 .*bare host .*completeness-reviewer\.md" <<<"$1"' _ "$(s6_out "$s6")"
printf 'tools/in-docker.sh php tools/phpunit.phar\n' > "$s6/.claude/agents/completeness-reviewer.md"
printf '{"test_cmd": "php tools/phpunit.phar"}\n' > "$s6/.claude/progress.json"
check "S6 reports a bare host php in the progress adapter" \
  bash -c 'grep -qE "^P1 .*bare host .*progress\.json" <<<"$1"' _ "$(s6_out "$s6")"

printf '\n  %d passed, %d failed\n' "$pass" "$fail"

if (( fail )); then
  printf '\n  failed:\n'
  for label in "${failed_labels[@]}"; do printf '    - %s\n' "$label"; done
fi

printf '\n'
[[ $fail -eq 0 ]]
exit $?
