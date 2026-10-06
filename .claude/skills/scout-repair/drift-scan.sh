#!/usr/bin/env bash
# drift-scan.sh — the mechanical half of /repair (sections 1-5).
#
# WHY THIS IS A SCRIPT AND NOT PROSE IN SKILL.md: the first run of the prose version produced TWO
# false positives, both from HISTORICAL CITATIONS — the framework's own sentence explaining that it
# once wrongly listed `/cross-check` as absent, and /repair's banner citing the dangling plan pointer
# it was written to catch. A drift detector that fires on its own changelog gets ignored within a week,
# and an ignored detector is worse than none. Citation-awareness is fiddly enough to need testing, and
# prose cannot be tested. (The same class bit pdfturbo on 2026-08-06: its copy-out grep matched
# install.sh's own warning about the block that must never return.)
#
# Every check writes its findings to stdout as `P0 `/`P1 `/`P2 ` lines. The tally at the end is
# computed by grepping that output, NOT by incrementing counters inside each check — an earlier version
# incremented shell variables from inside a python heredoc, which is a subprocess, so it printed a P0
# and still exited 0. That is the "alert computed but never sent" failure this repo's own source-health
# rule exists to prevent, and it was found by sabotage-verification rather than by review.
#
# Usage: bash .claude/skills/scout-repair/drift-scan.sh [--quiet]
# Exit:  0 = no P0/P1 drift, 1 = drift found.
set -uo pipefail

ROOT="${CLAUDE_PROJECT_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)}"
cd "$ROOT" || exit 1
QUIET=0; [[ "${1:-}" == "--quiet" ]] && QUIET=1

FINDINGS="$(mktemp)"; trap 'rm -f "$FINDINGS"' EXIT
say() { (( QUIET )) || printf '%s\n' "$1"; }

# ── S1: the container-era bootstrap must STAY gone ───────────────────────────────────────────────
# Until 2026-08-18 this section compared a shipped CLAUDE-global.md's skill list against
# .claude/skills/, because install.sh copied that file over ~/.claude/CLAUDE.md UNCONDITIONALLY —
# a wrong claim there became the next session's own system prompt. The de-containerization deleted
# scripts/claude-bootstrap/ entirely, which left this section guarded by a file-absent skip: dead
# code that could never fire again in a live gate.
# It now asserts the INVERSE, which is the property that actually needs defending: the bootstrap
# stays gone. Reinstating it — by a restore, a revert, or a port from a sibling repo — would once
# more clobber the developer's own ~/.claude/ install, and the one-shot .pre-bootstrap.bak safety
# net was spent back in July. Same shape as S4b's assertion that `deny` stays empty.
say "── S1 the container-era bootstrap stays gone"
python3 - <<'PY' >>"$FINDINGS"
import json, pathlib
d = pathlib.Path('scripts/claude-bootstrap')
if d.exists():
    print("P0  scripts/claude-bootstrap/ exists again — it was removed 2026-08-18. Its install.sh "
          "ran at SessionStart and cp -f'd an in-repo, container-era framework copy over "
          "~/.claude/CLAUDE.md (a copy that BANNED AskUserQuestion). ~/.claude/ is the developer's "
          "own persistent install and this repo never writes it. Delete the directory; do not "
          "re-register it.")
s = pathlib.Path('.claude/settings.json')
if s.is_file():
    text = s.read_text()
    if 'claude-bootstrap' in text:
        print("P0  .claude/settings.json still references scripts/claude-bootstrap — a hook wired at "
              "a path removed 2026-08-18. Remove the registration.")
    try:
        hooks = json.loads(text).get('hooks', {})
    except json.JSONDecodeError as e:
        print(f"P0  .claude/settings.json is not valid JSON ({e}) — every hook claim below is "
              f"unverifiable and Claude Code will not load it.")
        hooks = {}
    # Scoped to BOOTSTRAP-DERIVED registrations, deliberately. An unscoped "any SessionStart or
    # PreCompact hook is drift" would cry wolf at the first legitimate one somebody adds — and a
    # gate that cries wolf gets deleted wholesale, which is the failure this repo records from the
    # other direction. The invariant is about the bootstrap, not about those two events.
    for event in ('SessionStart', 'PreCompact'):
        for group in hooks.get(event, []):
            for h in group.get('hooks', []):
                cmd = h.get('command', '')
                if 'claude-bootstrap' in cmd or 'precompact-handoff' in cmd:
                    print(f"P0  .claude/settings.json registers a {event} hook running `{cmd}` — a "
                          f"bootstrap-era registration removed 2026-08-18. Session handoffs are the "
                          f"GLOBAL ~/.claude/hooks/precompact-handoff.sh's job and the framework "
                          f"installer must never run again. Remove it.")
PY
# A python section that CRASHES must not read as a section that found nothing. Under
# `set -uo pipefail` with no `-e`, an exception here printed a traceback to stderr, wrote
# nothing to $FINDINGS, and the tally below counted zero — a gate reporting clean because
# it had been disabled. Demonstrated with a truncated corpus.json and with a `cases` →
# `items` rename: both produced `P0=0 P1=0 P2=0` and exit 0.
[[ $? -eq 0 ]] || printf 'P0  a drift-scan python section exited non-zero — it checked NOTHING. Re-run without --quiet and read the traceback; do not trust this run.\n' >>"$FINDINGS"

# ── S1b: reviewer agents named vs defined ────────────────────────────────────────────────────────
say "── S1b reviewer agents"
for a in $(grep -rhoE '`[a-z-]+-reviewer`' CLAUDE.md 2>/dev/null \
            | tr -d '`' | sort -u); do
  [[ -f ".claude/agents/$a.md" ]] || printf 'P0  agent %s is named in CLAUDE.md/converge but .claude/agents/%s.md does not exist — /converge would spawn nothing at the gate meant to catch failures\n' "$a" "$a" >>"$FINDINGS"
done

# ── S2: plan pointers, citation-aware ────────────────────────────────────────────────────────────
# A reference is a POINTER when it tells the reader where something lives; a CITATION when it
# describes a past state. Citations are excluded two ways: inside an HTML comment, or on a line
# carrying a past-tense marker.
say "── S2 plan pointers"
python3 - <<'PY' >>"$FINDINGS"
import pathlib, re
CITE = re.compile(r'pointed at|used to|formerly|previously|only ever existed|was deleted|no longer|'
                  r'had REJECTED|correct when written|renamed|retargeted|dangling|SUPERSEDED', re.I)
for p in pathlib.Path('.').rglob('*'):
    if p.suffix not in ('.md', '.sh') or not p.is_file(): continue
    if any(x in p.parts for x in ('.git', 'var', 'node_modules')): continue
    # An archived plan is HISTORY (developer ruling 2026-10-06): a path it cites may be renamed later,
    # and the gate must never make anyone edit history to stay green. Same skip in S2b and S7.
    if p.parts[:3] == ('docs', 'plans', 'archive'): continue
    in_comment = False
    for n, line in enumerate(p.read_text(errors='replace').splitlines(), 1):
        if '<!--' in line and '-->' not in line: in_comment = True; continue
        if '-->' in line and '<!--' not in line: in_comment = False; continue
        if in_comment or CITE.search(line): continue
        # Any cited docs/ path, not just lowercase *.plan.md. An earlier version matched only
        # `docs/plans/[a-z0-9-]+\.plan\.md`, so a bare reference to phorj's docs/plans/MASTER-PLAN.md
        # — a foreign file wearing a local-looking path — slipped through both this check and S2b.
        # Uppercase and non-.plan.md suffixes now count. (This very comment needed the qualifier
        # adjacent to the path to pass its own rule, which is the rule working.)
        # A foreign path is FINE when qualified by its repo on the same line ("phorj's docs/…",
        # "phorj:docs/…"). Bare is the defect, because bare reads as local.
        qualified = re.search(r"(phorj|twes-in|pdfturbo|stack)(\'s)?[ :/]", line, re.I)
        # .claude/ paths joined 2026-08-19: a certification round found OPEN-QUESTIONS.md
        # dangling on the renamed ask-human skill while this check — whose message describes
        # exactly that defect — only policed docs/ paths. Same rule, second path family.
        for ref in re.findall(r'(?:docs/[A-Za-z0-9_/-]+\.md|(?<!~/)(?<!HOME/)\.claude/[A-Za-z0-9_./-]+)', line):
            if qualified: continue
            if not pathlib.Path(ref.rstrip('.')).exists():
                print(f"P1  {p}:{n} cites {ref}, which does not exist in this repo. If it belongs to "
                      f"another repo, say so explicitly — a bare path reads as local and a future "
                      f"session will follow it into nothing.")
PY
# A python section that CRASHES must not read as a section that found nothing. Under
# `set -uo pipefail` with no `-e`, an exception here printed a traceback to stderr, wrote
# nothing to $FINDINGS, and the tally below counted zero — a gate reporting clean because
# it had been disabled. Demonstrated with a truncated corpus.json and with a `cases` →
# `items` rename: both produced `P0=0 P1=0 P2=0` and exit 0.
[[ $? -eq 0 ]] || printf 'P0  a drift-scan python section exited non-zero — it checked NOTHING. Re-run without --quiet and read the traceback; do not trust this run.\n' >>"$FINDINGS"

# ── S2b: no SIBLING-REPO file cited as authority ─────────────────────────────────────────────────
# Raised by the developer 2026-08-06: "why would you have twes-in/CLAUDE.md in this repo???" A rent-watch
# decision record that cites `twes-in/CLAUDE.md` points at a file this repo does not contain and a future
# session here cannot read — the same dangling-reference class as S2, one level up. rent-watch's own
# CLAUDE.md is the only authority over rent-watch. The cross-repo AUDIT PLAN is exempt: comparing the
# siblings is literally its subject.
say "── S2b sibling-repo files cited as authority"
python3 - <<'PY' >>"$FINDINGS"
import pathlib, re
SIBS = ('twes-in', 'pdfturbo', 'phorj', 'stack')
EXEMPT = ('claude-bundle-cross-repo-audit.plan.md',)
pat = re.compile(r'\b(' + '|'.join(SIBS) + r')/[A-Za-z0-9_./-]*(CLAUDE\.md|\.plan\.md)')
for p in pathlib.Path('.').rglob('*.md'):
    if any(x in p.parts for x in ('.git', 'var', 'node_modules')): continue
    if p.parts[:3] == ('docs', 'plans', 'archive'): continue  # history, see S2
    if p.name in EXEMPT: continue
    for n, line in enumerate(p.read_text(errors='replace').splitlines(), 1):
        if 'earlier version' in line.lower() or 'was a defect' in line.lower(): continue
        m = pat.search(line)
        if m:
            print(f"P2  {p}:{n} cites {m.group(0)} as authority — a sibling repo's file. This repo cannot "
                  f"read it and a future session cannot verify it; rent-watch's own CLAUDE.md is the only "
                  f"authority here. State the argument directly instead.")
PY
# A python section that CRASHES must not read as a section that found nothing. Under
# `set -uo pipefail` with no `-e`, an exception here printed a traceback to stderr, wrote
# nothing to $FINDINGS, and the tally below counted zero — a gate reporting clean because
# it had been disabled. Demonstrated with a truncated corpus.json and with a `cases` →
# `items` rename: both produced `P0=0 P1=0 P2=0` and exit 0.
[[ $? -eq 0 ]] || printf 'P0  a drift-scan python section exited non-zero — it checked NOTHING. Re-run without --quiet and read the traceback; do not trust this run.\n' >>"$FINDINGS"

# ── S3: inventory tables ─────────────────────────────────────────────────────────────────────────
say "── S3 inventory tables"
for f in .claude/hooks/*.sh .claude/agents/*.md; do
  [[ -e "$f" ]] || continue
  grep -q "$(basename "$f")" CLAUDE.md \
    || printf "P2  %s is not listed in CLAUDE.md § 'Claude config in this repo'\n" "$(basename "$f")" >>"$FINDINGS"
done

# ── S4: hook wiring, four ways ───────────────────────────────────────────────────────────────────
say "── S4 hook wiring"
REG="$(python3 -c "
import json
d = json.load(open('.claude/settings.json'))
print('\n'.join(h['command'].split('/')[-1]
      for g in d['hooks'].values() for e in g for h in e['hooks']))" 2>/dev/null)"
for f in .claude/hooks/*.sh; do
  b="$(basename "$f")"
  grep -qx "$b" <<<"$REG" \
    || printf 'P2  %s is on disk but not registered in .claude/settings.json — dead code\n' "$b" >>"$FINDINGS"
  grep -q 'log_obs' "$f" \
    || printf 'P2  %s does not use log_obs() — violates Rule 13 of the framework this repo ships\n' "$b" >>"$FINDINGS"
done
while read -r b; do
  [[ -z "$b" ]] && continue
  [[ -f ".claude/hooks/$b" ]] \
    || printf "P1  settings.json registers '%s' but no such script exists — the hook silently never runs\n" "$b" >>"$FINDINGS"
done <<<"$REG"
while read -r mode path; do
  [[ "$mode" == "100755" ]] \
    || printf 'P1  %s is mode %s in git — it will not execute after a fresh clone\n' "$path" "$mode" >>"$FINDINGS"
done < <(git ls-files -s .claude/hooks/ 2>/dev/null | awk '$4 ~ /\.sh$/ {print $1, $4}')

# ── S4b: permissions.deny MUST be empty (developer ruling, 2026-08-06) ───────────────────────────
# Full autonomy: in a web session there is no terminal in which to run a denied command by hand, so a
# deny entry is a dead end, not a guardrail. Checked mechanically because the natural instinct when
# porting from a sibling — or when adding a "harmless" .env guard — is to reintroduce one.
say "── S4b permissions.deny must be empty"
python3 - <<'PY' >>"$FINDINGS"
import json, pathlib
d = json.loads(pathlib.Path('.claude/settings.json').read_text())
deny = d.get('permissions', {}).get('deny', [])
if deny:
    print(f"P1  .claude/settings.json permissions.deny has {len(deny)} entr{'y' if len(deny)==1 else 'ies'} "
          f"({', '.join(deny[:4])}) — this repo requires FULL AUTONOMY: a denied action cannot be run "
          f"by hand in a web session, so it is an unrecoverable dead end. See CLAUDE.md § Git autonomy.")
PY
# A python section that CRASHES must not read as a section that found nothing. Under
# `set -uo pipefail` with no `-e`, an exception here printed a traceback to stderr, wrote
# nothing to $FINDINGS, and the tally below counted zero — a gate reporting clean because
# it had been disabled. Demonstrated with a truncated corpus.json and with a `cases` →
# `items` rename: both produced `P0=0 P1=0 P2=0` and exit 0.
[[ $? -eq 0 ]] || printf 'P0  a drift-scan python section exited non-zero — it checked NOTHING. Re-run without --quiet and read the traceback; do not trust this run.\n' >>"$FINDINGS"

# ── S6: THE TENURE INVARIANT — rent-watch's own, and the reason this skill is not a generic copy ──
# The one non-negotiable rule is asserted in ~16 files: CLAUDE.md §1 and its glossary, every skill
# banner (delta 8), the three agents, and tenure-guard.sh. Prose can drift from prose, but the case
# that actually matters is prose drifting from the TRIPWIRE: if CLAUDE.md names a term the guard's
# grep does not cover, the tripwire has a silent blind spot on exactly the rule it exists to watch.
# Same for the threshold — tenure-guard.sh encodes the 0.6 floor as a character class ([0-5]), so
# changing the documented threshold without changing that class leaves the guard checking the old one.
say "── S6 tenure invariant: docs vs the tripwire"
python3 - <<'PY' >>"$FINDINGS"
import json, os, pathlib, re, subprocess
guard = pathlib.Path('.claude/hooks/tenure-guard.sh')
claude = pathlib.Path('CLAUDE.md')
if not (guard.is_file() and claude.is_file()):
    raise SystemExit
c = claude.read_text()
# Only the guard's MATCHING PATTERNS count, not the whole file. Its warning message also names every
# excluded term, so a whole-file substring search passes even when the alternation has lost one —
# sabotage-verification caught exactly that: gutting `plai` from the patterns went undetected because
# the echo block still said "PLAI/PLUS/…". What matters is what the grep matches, not what it prints.
guard_src = guard.read_text()
g = "\n".join(re.findall(r"grep -Eq '([^']*)'", guard_src))
if not g:
    print("P1  tenure-guard.sh has no `grep -Eq '…'` patterns — the tripwire matches nothing at all.")
    raise SystemExit

# 1. Every excluded term named by the rule must appear in the guard's patterns.
#    CLAUDE.md §1 is the authority; accent-folded and truncated forms count as covered.
TERMS = {'PLAI': 'plai', 'PLUS': 'plus', 'PLS': 'pls', 'ANRU': 'anru', 'ANAH': 'anah',
         'conventionné': 'conventionn', 'logement social': 'logement social'}
for shown, pattern in TERMS.items():
    if shown.lower() not in c.lower():
        print(f"P2  CLAUDE.md no longer names '{shown}' in the excluded set — if that was deliberate it is a "
              f"product decision, not drift; if not, the rule lost a term.")
    elif pattern not in g.lower():
        print(f"P0  CLAUDE.md's excluded set names '{shown}' but tenure-guard.sh's patterns do not match "
              f"'{pattern}' — the tripwire has a blind spot on a term the rule covers.")

# 2. One documented threshold, and the guard's character class must bracket it.
th = sorted(set(re.findall(r'confidence[^.\n]{0,40}?([01]\.\d+)', c, re.I)))
if len(th) > 1:
    print(f"P1  CLAUDE.md states more than one fail-closed confidence threshold {th} — one of them is stale.")
elif th:
    want = th[0]                                  # e.g. '0.6'
    digit = int(want.split('.')[1][0])            # 6
    cls = re.search(r'confidence\[\^\.\]\{0,40\}\(0\\\.\[0-(\d)\]', g)
    if cls and int(cls.group(1)) != digit - 1:
        print(f"P0  CLAUDE.md documents a {want} fail-closed threshold but tenure-guard.sh's regex brackets "
              f"[0-{cls.group(1)}], i.e. it flags values below 0.{int(cls.group(1))+1} — the guard is checking "
              f"a different floor than the rule states.")
    if want not in guard_src:
        print(f"P2  tenure-guard.sh does not mention the documented threshold {want} in its comments — a "
              f"future edit cannot tell which floor its regex encodes.")

# 3. THE REPRESENTATION THE CODE ACTUALLY USES.
#
# Check 2 above audits the guard's FLOAT pattern against CLAUDE.md's prose, and for a while that was
# the entire threshold audit — while the classifier stored confidence in INTEGER BASIS POINTS
# (`FLOOR_BP = 60`), so that PHP and phorj could not disagree on a float. `0.6` appears nowhere under
# src/. Both the guard and this check were therefore validating a representation nothing used: a
# 2026-08-06 review set the floor to 0 and neither noticed. Whatever src/ declares as its floor, the
# guard must have a pattern that brackets values below it in the SAME notation.
floors = set()
for php in pathlib.Path('src').rglob('*.php') if pathlib.Path('src').is_dir() else []:
    floors.update(re.findall(r'FLOOR_BP\s*=\s*(\d+)', php.read_text()))

if len(floors) > 1:
    print(f"P1  src/ declares more than one FLOOR_BP {sorted(floors)} — one of them is stale.")
elif floors:
    bp = int(floors.pop())
    if th and bp != round(float(th[0]) * 100):
        print(f"P0  src/ sets FLOOR_BP={bp} but CLAUDE.md documents a {th[0]} fail-closed floor "
              f"({round(float(th[0]) * 100)} basis points) — the code and the rule disagree.")
    # BEHAVIOURAL, NOT A REGEX ABOUT A REGEX. The first version of this check read the digit class
    # straight out of the guard's source with `re.search(r'_bp\[\^\.\]...')`, and a review broke it
    # in BOTH directions: rewriting `= *` as the equivalent `=[[:space:]]*` left the guard exactly
    # correct and produced a false P0, while renaming the prefix alternation from `floor_bp` to
    # `ceiling_bp` killed the guard outright and this check stayed clean. A gate that blocks correct
    # work gets overridden; one that passes broken work is worse. So: RUN the guard, the way its own
    # test does, and assert what it actually does with a value one point under the floor.
    guard_path = str(guard)
    def guard_fires(snippet):
        payload = json.dumps({'tool_name': 'Write', 'tool_input': {
            'file_path': str(pathlib.Path.cwd() / 'src' / 'php' / 'Core' / 'Probe.php'),
            'content': snippet}})
        env = {**os.environ, 'OBS_LOG': os.devnull, 'CLAUDE_PROJECT_DIR': str(pathlib.Path.cwd())}
        r = subprocess.run(['bash', guard_path], input=payload, capture_output=True, text=True, env=env)
        return r.returncode == 2

    if not guard_fires(f'private const int FLOOR_BP = {bp - 1};'):
        print(f"P0  src/ sets FLOOR_BP={bp}, but feeding tenure-guard.sh a write that lowers it to "
              f"{bp - 1} does NOT fire the tripwire. Lowering the fail-closed floor is silent.")
    if guard_fires(f'private const int FLOOR_BP = {bp};'):
        print(f"P1  tenure-guard.sh fires on the CORRECT floor (FLOOR_BP={bp}). A tripwire that "
              f"trips on every edit that merely touches the floor is learned-to-be-ignored, which "
              f"is how the guard's own header says it stops being a guard.")
PY
# A python section that CRASHES must not read as a section that found nothing. Under
# `set -uo pipefail` with no `-e`, an exception here printed a traceback to stderr, wrote
# nothing to $FINDINGS, and the tally below counted zero — a gate reporting clean because
# it had been disabled. Demonstrated with a truncated corpus.json and with a `cases` →
# `items` rename: both produced `P0=0 P1=0 P2=0` and exit 0.
[[ $? -eq 0 ]] || printf 'P0  a drift-scan python section exited non-zero — it checked NOTHING. Re-run without --quiet and read the traceback; do not trust this run.\n' >>"$FINDINGS"

# ── S7: COUNTS WRITTEN IN PROSE vs the artefacts they describe ───────────────────────────────────
# Three separate review rounds caught a stale corpus count in CLAUDE.md or README.md, each time
# after fixtures were appended — the number is written in five places and every one of them has to
# be carried by hand. A count in prose is a claim, and this repo's own rule is that claims get
# checked mechanically rather than remembered. Cheap, and it retires a whole class of finding.
say "── S7 counts in prose vs reality"
python3 - <<'PY' >>"$FINDINGS"
import json, pathlib, re

corpus = pathlib.Path('tests/fixtures/rent/tenure/corpus.json')
if not corpus.is_file():
    # A BARE `raise SystemExit` EXITS 0, so the `[[ $? -eq 0 ]] ||` crash guard below cannot fire
    # and every prose claim this section polices silently stops being checked. A truncated
    # corpus.json and a `cases` -> `items` rename both surfaced correctly; the artefact simply being
    # GONE — which CLAUDE.md lists under never-casually-deleted stateful data — was the silent case.
    print("P0  tests/fixtures/rent/tenure/corpus.json is MISSING — S7 checked nothing at all, and that "
          "file is the classifier's ground truth, listed in CLAUDE.md as never casually deleted.")
    raise SystemExit

data = json.loads(corpus.read_text())
cases = len(data['cases'])
declared = data.get('declared_counts', {})
synthetic = sum(1 for c in data['cases'] if c.get('provenance') == 'synthetic')

captured = sum(1 for c in data['cases'] if c.get('provenance') == 'captured')

# BOTH halves. Checking only `synthetic` left the half that will actually change as sources come
# online unguarded: flip one case to `captured` and honestly drop `synthetic` by one, and the total
# is unchanged, no prose count drifts, and the `captured` field rots undetected.
for kind, actual in (('synthetic', synthetic), ('captured', captured)):
    if declared.get(kind) != actual:
        print(f"P0  corpus.json declares {declared.get(kind)} {kind} cases but contains {actual} — "
              f"the provenance disclosure is the thing that keeps the 'real texts' gap honest.")

unknown = [c['id'] for c in data['cases'] if c.get('provenance') not in ('synthetic', 'captured')]
if unknown:
    print(f"P1  corpus cases declare an unrecognised provenance: {', '.join(unknown[:5])} — "
          f"only 'synthetic' and 'captured' are counted, so anything else is invisible to the check.")

# Prose claims about the corpus, each with the number it must equal — PER GROUP, not one global
# total. Every claim used to be compared against `cases`, which was right only by accident: while
# the corpus is 100% synthetic, `synthetic == cases`. The day the first real payload is frozen in,
# an honest "all 86 are synthetic" would be reported as drift and a stale "all 87" would pass — the
# check would be actively wrong in both directions, on the exact commit it exists to police.
EXPECT = {'cases': cases, 'synthetic': synthetic, 'captured': captured}
CLAIMS = [
    # `,?` was not enough: CLAUDE.md's File-layout block separates with an EM DASH, which is the
    # phrasing the un-exempted fenced block actually uses, so the hole the exemption fix was written
    # for stayed open for that shape. Any separator now counts.
    (r'corpus\.json`?\s*[—–,:-]?\s+(\d+)\s+cases', 'corpus.json … N cases', ('cases',)),
    (r'still\s+(\d+)/(\d+)\s+synthetic', 'still N/N synthetic', ('synthetic', 'cases')),
    # NOT preceded by ≥ or "at least": those are the SPEC MINIMUM (spec §4 asks for >=30 texts),
    # which is a floor the corpus must clear, not a count of what it holds. This check flagged that
    # sentence on its first run.
    (r'(?<!≥)(?<!at least )(?<!minimum )\b(\d+)\s+hand-labelled', 'N hand-labelled', ('cases',)),
    # Bold-optional and case-insensitive: `**All 77 are synthetic**` AND `all 56 are synthetic`.
    # The second phrasing sat stale in the plan for three commits because the pattern required
    # the bold markers and a capital A.
    (r'(?i)\*{0,2}all\s+(\d+)\s+(?:are|of them are)\s+synthetic', 'all N are synthetic', ('synthetic',)),
    (r'(\d+)-case (?:language-neutral|synthetic)', 'N-case corpus', ('cases',)),
    # The BREAKDOWN phrasing, added 2026-08-23 after CI run 36 went red on a stale `120 cases` and
    # a SECOND stale count in the same file survived that fix. CLAUDE.md previously read
    # "120 total, 115 synthetic + 7 real", where 115 + 7 is 122. Every pattern above checks ONE
    # number, so a sentence that states the total and its parts had two of its three numbers
    # unguarded — and the one that drifted was a total whose own addends, three words later,
    # disproved it. That quote stays on ONE line inside double quotes: `code_spans()` exempts a
    # quoted span and its regex excludes newlines, so a reflow would make this comment a permanent
    # P1 against itself.
    # `\s+` spans the newline on purpose: the match is run against the whole file, and this claim
    # was wrapped across two lines, which is precisely why re-reading the paragraph did not catch it.
    (r'(\d+)\s+total,\s+(\d+)\s+synthetic', 'N total, N synthetic', ('cases', 'synthetic')),
    # And the parts on their own, for the phrasing that states no total (README's table cell).
    # `real` as well as `captured`: the corpus field is `captured`, but prose reaches for "real"
    # because that is the word spec §4 uses, and a checker that only knows the field name would
    # have skipped both live instances of this sentence.
    (r'(?i)(\d+)\s+synthetic\s*\+\s*(\d+)\s+(?:real|captured)', 'N synthetic + N captured',
     ('synthetic', 'captured')),
]


def code_spans(text):
    """Character ranges inside INLINE backticks. Fenced blocks are deliberately NOT exempt.

    A count QUOTED as an example is not a claim. This check's own write-up in the plan file cites
    `all 56 are synthetic` as the phrasing an earlier version missed — and the check then reported
    that citation as drift, on every run, forever. Documenting a bug became a permanent finding,
    which is how a gate gets routinely overridden. Backticks are the marker because that is how a
    stale count is naturally cited, and because a real claim is never written in code font.

    Fenced blocks were exempt too, until a review showed that hides LIVE claims: `CLAUDE.md`'s File
    layout quick reference IS a fenced block and already names corpus.json, so a stale
    "corpus.json, 42 cases" line placed there produced P0=0 P1=0 P2=0 and exit 0. The single
    citation this exemption exists for is inline, so narrowing to inline costs nothing.

    Double-quoted spans count too. A stale count CITED in a shell or python comment is naturally
    written "all 86 are synthetic", not in backticks -- and once this check grew from three
    hand-listed docs to every tracked .md and .sh, it started reporting its own write-ups of the
    bugs it fixed. Same reasoning as the backticks: nobody states a live claim in quotation marks.
    """
    return [
        (m.start(), m.end())
        for m in re.finditer(r'`[^`\n]+`|"[^"\n]{0,120}"', text)
    ]


def is_citation(line):
    """Does this line describe a PAST state rather than assert a current one?

    The same test S2 uses for plan pointers, applied to counts. `an earlier version … 82 cases` is
    a changelog entry, and a detector that fires on its own changelog gets ignored within a week —
    which is the sentence this script opens with.
    """
    return re.search(
        r'used to|earlier version|an earlier|would be reported|previously|formerly|no longer|'
        r'until a review|was reported|had been|stale "|placed there produced',
        line, re.I,
    ) is not None


# EVERY tracked .md and .sh, not a hand-listed three. The list was `CLAUDE.md`, `README.md` and the
# plan — so the same commit that updated 93→100 in those three introduced two fresh `93-case` claims
# in `.claude/hooks/tenure-guard.sh` and `tests/test-tenure-guard.sh` and this check reported clean.
# A drift detector with a hardcoded file list drifts exactly where it is not looking.
docs = sorted(
    str(q) for q in pathlib.Path('.').rglob('*')
    if q.suffix in ('.md', '.sh') and q.is_file()
    and not any(x in q.parts for x in ('.git', 'var', 'node_modules', 'vendor'))
    # NOT an archived plan (developer ruling 2026-10-06). A count there is a record of what was true
    # when the plan closed, and this check forced two edits to one (b41432c, d90c56e) only to stay
    # green. Exactly docs/plans/archive/, never all of docs/plans/: a live plan's count is a claim.
    and q.parts[:3] != ('docs', 'plans', 'archive')
)

for doc in docs:
    p = pathlib.Path(doc)
    if not p.is_file():
        continue
    text = p.read_text()
    quoted = code_spans(text)
    for pattern, label, kinds in CLAIMS:
        for m in re.finditer(pattern, text):
            for i, (g, kind) in enumerate(zip(m.groups(), kinds), start=1):
                # The NUMBER's position decides, not the match's. An earlier version tested
                # `m.start()` and so exempted "corpus.json, 82 cases" — the pattern begins at the
                # filename, which is in code font, while the claim that drifted is the bare number
                # after it. A rule that hides a live claim is worse than the noisy one it replaced.
                if any(a <= m.start(i) < b for a, b in quoted):
                    continue

                line_text = text[text.rfind('\n', 0, m.start(i)) + 1:text.find('\n', m.start(i))]

                if is_citation(line_text):
                    continue
                if int(g) != EXPECT[kind]:
                    line = text[:m.start(i)].count('\n') + 1
                    print(f"P1  {doc}:{line} claims {g} where the corpus has {EXPECT[kind]} "
                          f"{kind} ({label}) — a prose count drifted from the data it describes.")

# The open-decision count in CLAUDE.md must match docs/OPEN-QUESTIONS.md.
oq = pathlib.Path('docs/OPEN-QUESTIONS.md')
cm = pathlib.Path('CLAUDE.md')
if oq.is_file() and cm.is_file():
    open_qs = len(re.findall(r'^### (?:Ⓑ|Ⓞ)', oq.read_text(), re.M))
    WORDS = {'one':1,'two':2,'three':3,'four':4,'five':5,'six':6,'seven':7,'eight':8,'nine':9,
             'ten':10,'eleven':11,'twelve':12,'thirteen':13,'fourteen':14,'fifteen':15,
             'sixteen':16,'seventeen':17,'eighteen':18,'nineteen':19,'twenty':20}
    m = re.search(r'\*\*(\w+) decisions are still open\*\*', cm.read_text(), re.I)
    if m:
        claimed = WORDS.get(m.group(1).lower())
        if claimed is None:
            print(f"P2  CLAUDE.md writes the open-decision count as '{m.group(1)}', which this check "
                  f"cannot parse — use a word from one..twenty, or a digit.")
        elif claimed != open_qs:
            print(f"P1  CLAUDE.md says {m.group(1)} ({claimed}) decisions are open; "
                  f"docs/OPEN-QUESTIONS.md has {open_qs} (Ⓑ + Ⓞ headings).")
PY
# A python section that CRASHES must not read as a section that found nothing. Under
# `set -uo pipefail` with no `-e`, an exception here printed a traceback to stderr, wrote
# nothing to $FINDINGS, and the tally below counted zero — a gate reporting clean because
# it had been disabled. Demonstrated with a truncated corpus.json and with a `cases` →
# `items` rename: both produced `P0=0 P1=0 P2=0` and exit 0.
[[ $? -eq 0 ]] || printf 'P0  a drift-scan python section exited non-zero — it checked NOTHING. Re-run without --quiet and read the traceback; do not trust this run.\n' >>"$FINDINGS"

# ── S8: `.env.example` is the only place a deployer can DISCOVER a setting ───────────────────────
# Hard rule 7 makes keeping `.env` and this template in sync an obligation, and nothing checked it.
# Both defects it now catches were live on 2026-08-22, in a file committed the day before:
#
#   * `IMAP_PORT` was declared TWICE. Compose's env_file takes the LAST occurrence — measured on a
#     scratch stack, `IMAP_PORT=143` above `IMAP_PORT=993` starts the container with 993 — so an
#     operator editing the key where it belongs is silently overridden by a copy further down. The
#     misconfiguration is invisible: the value is plausible and nothing errors.
#   * `RW_UID`/`RW_GID` were read by `compose.yaml` and absent from the template, while compose's own
#     comment said to set them "in `.env`". The failure that produces is the one already observed on
#     a real container: uid mismatch on the bind-mounted `state/`, and PDO cannot create the database.
#
# Both are the same shape as this repo's source-health rule — a thing that is wrong while everything
# reports fine. Hence P1 rather than P2: nobody finds these by looking.
say "── S8 .env.example ↔ getenv() ↔ compose.yaml"
python3 - <<'PY' >>"$FINDINGS"
import pathlib, re

tpl = pathlib.Path('.env.example')
if not tpl.exists():
    print("P0  .env.example is missing — it is the committed template hard rule 7 names, and the "
          "only place a deployer can discover a setting.")
    raise SystemExit(0)

lines = tpl.read_text().splitlines()
# A commented-out declaration still COUNTS as listed: a documented key the operator is meant to
# leave unset (RFR_N2, SCOUT_MAX_PASSES) is discoverable, which is the whole requirement.
declared, active = {}, []
for n, line in enumerate(lines, 1):
    m = re.match(r'^(#?)([A-Z_][A-Z0-9_]*)=', line)
    if m:
        declared.setdefault(m.group(2), []).append(n)
        if not m.group(1):
            active.append((m.group(2), n))

# (a) duplicate ACTIVE keys — the last-wins trap. A commented twin is documentation, not a conflict.
seen = {}
for key, n in active:
    seen.setdefault(key, []).append(n)
for key, ns in sorted(seen.items()):
    if len(ns) > 1:
        print(f"P1  .env.example declares {key} {len(ns)} times (lines {', '.join(map(str, ns))}) — "
              f"env_file takes the LAST occurrence, so editing the first one silently does nothing. "
              f"Keep one declaration.")

# (b) every getenv() the shipped code performs must be discoverable here.
#     SCOUT_OFFLINE is deliberately absent and the template says why: it is the test seam that
#     makes CurlHttpClient refuse third-party hosts, and setting it in a deployment would disable
#     every source while health stayed plausible. Listing it as a setting would invite exactly that.
TEST_SEAMS = {'SCOUT_OFFLINE'}
read = set()
for path in list(pathlib.Path('src/php').rglob('*.php')) + [pathlib.Path('bin/scout')]:
    if path.exists():
        read |= set(re.findall(r"getenv\('([A-Z_][A-Z0-9_]*)'\)", path.read_text()))
for key in sorted(read - set(declared) - TEST_SEAMS):
    print(f"P1  {key} is read by getenv() and is not in .env.example — a setting nobody deploying "
          f"this can discover. Add it, or add it to TEST_SEAMS here with the reason.")

# (c) every ${VAR} compose substitutes. These have no getenv() to find them by, so (b) cannot see
#     them: Compose resolves them before the container exists.
compose = pathlib.Path('compose.yaml')
if compose.exists():
    subst = set(re.findall(r'\$\{([A-Z_][A-Z0-9_]*)(?::-[^}]*)?\}', compose.read_text()))
    for key in sorted(subst - set(declared)):
        print(f"P1  compose.yaml substitutes ${{{key}}} and .env.example does not declare it — "
              f"Compose reads it from `.env`, so the deployer is told to set a key the template "
              f"never mentions.")

# (d) the reverse: a template key nothing reads. Three different things look identical here — a
#     stale leftover, a key declared ahead of its feature, and a key declared PRECISELY BECAUSE it
#     is unsupported — so each deliberate one is named with its reason and anything else reports.
#     The reason is repeated here rather than trusted to the comment above the key, because every
#     key in that file has explanatory prose above it and "there is a comment" would match all of
#     them. A gate that fires on cases the repo has already reasoned about gets ignored within a
#     week — see this script's own preamble, which was written after exactly that.
DECLARED_UNREAD = {
    'RFR_N2': 'income-eligibility checking, Q6 — ruled off by default',
    # Not a gap and not a plan: Q9 rules the channels console/ntfy/email, and these are left
    # commented so that filling them in cannot produce silence. An unsupported name in
    # `notify.channels` is a config validation error, and `Redact` masks Telegram tokens anyway.
    'TELEGRAM_BOT_TOKEN': 'deliberately UNSUPPORTED under Q9 — commented so filling it cannot fail silently',
    'TELEGRAM_CHAT_ID': 'deliberately UNSUPPORTED under Q9 — commented so filling it cannot fail silently',
}
subst = set(re.findall(r'\$\{([A-Z_][A-Z0-9_]*)(?::-[^}]*)?\}', compose.read_text())) if compose.exists() else set()
# A SHELL SCRIPT IS A READER TOO, and until `tools/backup-state.sh` arrived every reader in this
# project was PHP. Scanned for the (d) direction ONLY, and that asymmetry is deliberate: used in the
# (b) direction it would report `PATH`, `HOME` and every ordinary shell local as an undeclared
# setting, and a gate that fires on things the repo has already reasoned about gets ignored within a
# week. Here it can only ever SUPPRESS a false "nothing reads this", never invent a finding.
shell_read = set()
# `tools/` ONLY. A key read solely by a test is a SEAM, not a deployment setting, so it has no
# business silencing "nothing reads this" — and keeping the scan's reach identical to what
# `tests/test-drift-scan.sh` can safely materialise is what stops the two drifting apart. That test
# cannot link `tests/` into its scratch tree: doing so made a later case write through the link into
# the real classifier corpus.
for path in pathlib.Path('tools').glob('*.sh'):
    if path.exists():
        shell_read |= set(re.findall(r'\$\{([A-Z_][A-Z0-9_]*)(?::[-=?+][^}]*)?\}', path.read_text()))

for key in sorted(set(declared) - read - shell_read - subst - set(DECLARED_UNREAD)):
    print(f"P2  .env.example declares {key} and no code reads it — stale, or ahead of its feature. "
          f"If deliberate, name it in DECLARED_UNREAD here with the reason.")
PY
# A python section that CRASHES must not read as a section that found nothing — see S4b.
[[ $? -eq 0 ]] || printf 'P0  a drift-scan python section exited non-zero — it checked NOTHING. Re-run without --quiet and read the traceback; do not trust this run.\n' >>"$FINDINGS"

# ── S5: tool availability (informational — compare against any doc that claims or hedges) ────────
say "── S5 tool availability (informational)"
if (( ! QUIET )); then
  for t in ruff python3 pytest jq yq git shellcheck yamllint shfmt hadolint; do
    printf '     %-11s %s\n' "$t" "$(command -v "$t" >/dev/null 2>&1 && echo PRESENT || echo absent)"
  done
fi

# ── S6: the surfaces that tell Claude or a human how to run the suite use the dev toolchain ──────
# `tools/in-docker.sh` is the one way to run php here (CLAUDE.md § "Run everything in Docker"): the
# host's php is an 8.7-dev ZTS/DEBUG/GCOV build, not the 8.5 NTS the watchers run. A bare
# `php tools/phpunit.phar` in a reviewer charter, the progress adapter or the README sends the next
# session to the wrong interpreter, and nothing else would notice — the suite passes on both.
# Scoped to the surfaces that were converted: CLAUDE.md legitimately NAMES the host command in its
# "Replaces" column, and the dated rules and skills are narrative the blanket sentence there covers.
say "── S6 live surfaces run the suite through tools/in-docker.sh"
grep -nE 'php tools/phpunit\.phar' .claude/agents/*.md .claude/progress.json README.md 2>/dev/null \
  | grep -v 'in-docker\.sh' \
  | sed 's/^/P1  bare host `php tools\/phpunit.phar`, use `tools\/in-docker.sh php tools\/phpunit.phar` — /' \
  | cut -c1-220 >>"$FINDINGS" || true

# ── Tally from the findings file — the single source of both the report and the exit code ────────
sort -u "$FINDINGS" > "$FINDINGS.u" && mv "$FINDINGS.u" "$FINDINGS"
[[ -s "$FINDINGS" ]] && cat "$FINDINGS"
n0=$(grep -c '^P0 ' "$FINDINGS" || true); n1=$(grep -c '^P1 ' "$FINDINGS" || true)
n2=$(grep -c '^P2 ' "$FINDINGS" || true)
printf '\ndrift-scan: P0=%d P1=%d P2=%d\n' "$n0" "$n1" "$n2"
(( n0 + n1 == 0 ))
