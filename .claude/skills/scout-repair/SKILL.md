---
name: scout-repair
description: >
  Detect and repair drift between what CLAUDE.md and .claude/ CLAIM exists in this repo, and what
  actually exists — skills, agents, hooks, settings entries, plan pointers, config
  keys, fixtures, documented commands. Run after adding a skill/agent/hook, after a port from a
  sibling repo, or any time the config might be stale. Never weakens an invariant.
user-invocable: true
---

<!-- ═══════════════════════════════════════════════════════════════════════════════════
  rent-watch ADAPTATION (2026-08-06) of the machine bundle's `/repair`
  (`claude-setup-global-20260722`). This skill was REJECTED by the pdfturbo and twes-in ports as
  "operates on a persistent ~/.claude/", and that judgement was WRONG — the rejection was inherited
  rather than checked. Its five drift categories (MISSING / STALE / OBSOLETE / ADAPT PENDING /
  LINGERING) are almost entirely about the PROJECT's own documentation versus the project's own
  filesystem, which is exactly what this repo needs.

  IT IS PORTED ON EVIDENCE, NOT ON PRINCIPLE. On 2026-08-06 a single session found five drift defects
  BY HAND, every one of which this skill's scan would have caught mechanically:
    1. (P0) `CLAUDE-global.md` listed `/cross-check` in its "NOT installed here" set while the skill
       was installed — and `install.sh` copies that file into `~/.claude/CLAUDE.md` UNCONDITIONALLY,
       so the next session's own system prompt would deny having a skill it had.
    2. `/add-source` existed on disk and appeared in no list at all.
    3. Three files pointed at `docs/plans/claude-bundle-integration.plan.md`, a plan file that only
       ever existed in twes-in.
    4. `/cross-check`'s tool row asserted `pytest`/`yq` "may not be" present; both were — asserted
       from memory in the very row that says to run `command -v`.
    5. All three project hooks were missing `log_obs`, which the framework they ship mandates
       (Rule 13).
  Findings 1 and 2 belong to a drift class the upstream skill does NOT have, because upstream assumed
  `~/.claude/` was authored in place. Here it is GENERATED from repo files, so the repo can ship a
  framework that lies about the repo. That check is § 1 below and it runs first.

  ADAPTATIONS: the lean-mode interlock is removed (no lean mode here). `--apply` no longer means
  "without prompting" — see § "What is never auto-fixed". Questions use `AskUserQuestion`
  (`.claude/skills/scout-ask-human/SKILL.md`, re-inverted 2026-08-18). Reports go to
  `var/claude/scout-repair/` (gitignored), never `~/.claude/projects/…`.
═══════════════════════════════════════════════════════════════════════════════════ -->

## --help

> If ARGUMENTS contains `--help`: output the text below verbatim, then STOP.
>
> ```
> /scout-repair — Detect and repair drift between what the config CLAIMS and what exists.
>
>   (none)     Scan, report, fix what is safely fixable, state what is not
>   --check    Scan only, no writes. Exit 0 = clean, 1 = drift found. Use in a gate.
>   --quick    Sections 1-3 only (the claim surfaces). ~30s.
> ```

---

# /scout-repair — drift between claim and reality

Every check below answers one question: **does something this repo asserts still match the
filesystem?** A stale claim is worse than a missing one, because it is trusted — and in this repo one
class of stale claim is shipped into the next session's system prompt.

Run it after adding or removing a skill, agent or hook; after porting anything from a sibling repo;
and before a milestone boundary.

---

## Section 1 — THE BOOTSTRAP STAYS GONE (run this first; it is the one with teeth)

**De-containerized 2026-08-18**: the repo no longer ships a framework copy — `~/.claude/` is the
developer's own persistent install and `scripts/claude-bootstrap/` is gone.

S1 used to compare that shipped copy's skill list against `.claude/skills/`. With the copy deleted
that check could only ever skip, so it was **dead code inside a live gate** — the shape this repo
records against everything else. **It now asserts the inverse**, which is the property that
actually needs defending: the directory must not come back, `.claude/settings.json` must not name
it, and no `SessionStart` or `PreCompact` registration may reappear. Reinstating any of them would
once more `cp -f` a container-era framework over the developer's own `~/.claude/CLAUDE.md`, and the
one-shot `.pre-bootstrap.bak` safety net was spent in July. Same shape as § S4b's assertion that
`deny` stays empty — an invariant a later sibling-repo port could otherwise reintroduce.

Pinned by a mutant: `mkdir -p scripts/claude-bootstrap` turns `P0=0` into `P0=1`.

```bash
# Ground truth
ls .claude/skills/ | sort
```

Cross-check both directions and report each mismatch as **P0**:

- a skill **on disk** that the framework lists as NOT installed → a session will refuse to use a tool
  it has. (This happened; see the banner.)
- a skill **on disk** in neither list → a session will not know it exists.
- a skill in the "As built" list with **no directory** → a session will try to invoke nothing.

Do the same for the agents named in `§ "Certification ladder"` of `CLAUDE.md` and the reviewer names
in the reviewer agent charters (`.claude/agents/`) — a `/converge` run that spawns an agent by a name with no
definition fails at the gate that is supposed to catch failures.

## Section 2 — POINTERS THAT GO NOWHERE

```bash
# Every plan file referenced anywhere, checked for existence
grep -rhoE 'docs/plans/[a-z0-9-]+\.plan\.md' .claude/ scripts/ docs/ CLAUDE.md README.md 2>/dev/null \
  | sort -u | while read -r f; do [[ -f "$f" ]] || echo "DANGLING: $f"; done

# Every repo-relative path named in a skill or agent
grep -rhoE '`(src|config|tests|spec|docs|scripts|prototype|\.claude)/[A-Za-z0-9_./*-]+`' .claude/ \
  | tr -d '`' | sort -u | while read -r f; do
      [[ -e "$f" ]] || case "$f" in *\**) ;; *) echo "NAMED-BUT-ABSENT: $f" ;; esac
    done
```

**Judgement required on the second list, and this is the trap — in BOTH directions.**

`config/rent/sources.json` is named all over `.claude/` and does not exist yet. That is correct: it is the
documented target, and a bullet saying "when it lands, check X" is not drift.

**`src/core/tenure.py` is NOT in that category, and this paragraph said it was until 2026-08-06.**
That path has never existed in this repo. `CLAUDE.md` records the two-language ruling and amends the
spec's single-language `src/core/` to `src/<lang>/`, so the classifier is
`src/php/Rent/Core/TenureClassifier.php`. By telling future sessions the reference was *correct*, this
instruction actively suppressed a real finding across three review rounds — including a `/pre-commit`
routing trigger keyed on a path that can never match, which meant the highest-blast-radius rule in
the pre-commit gate never fired for the classifier. **A stale `src/core/` reference IS drift. Report
it.**

The general rule still holds: flag a path when the text asserts it **exists** or tells the reader to
**run** it, and check `git ls-files src/ config/ tests/` before deciding either way rather than
trusting any sentence — including this one. When unsure, report it as a question rather than a
finding.

## Section 3 — INVENTORY TABLES

`CLAUDE.md` § "Claude config in this repo" enumerates files. Diff it against `ls`:

```bash
ls .claude/hooks/ .claude/agents/ .claude/skills/
grep -n '\.claude/' CLAUDE.md | sed -n '/Claude config in this repo/,$p'
```

A file added without updating the table is **P2**; a table row naming a deleted file is **P1** (a
future session will look for it).

## Section 4 — HOOK WIRING, THREE WAYS

A hook has to be on disk, registered, executable, and observable. Check all four:

```bash
ls .claude/hooks/*.sh
python3 -c "import json;d=json.load(open('.claude/settings.json'));
print([h['command'] for g in d['hooks'].values() for e in g for h in e['hooks']])"
git ls-files -s .claude/hooks/                                   # 100755 expected on scripts
grep -l 'log_obs' .claude/hooks/*.sh                             # Rule 13 — all of them
```

- on disk but unregistered → dead code, **P2**
- registered but absent → the hook silently never runs, **P1**
- mode `100644` on a script → it will not execute after a fresh clone, **P1**
- no `log_obs` → violates Rule 13 of the developer's global framework (`~/.claude/CLAUDE.md`), **P2** (this happened; see banner)

## Section 5 — DOCUMENTED COMMANDS THAT DO NOT RUN

For every command in `CLAUDE.md § "Common workflows"` and every tool named in a skill:

```bash
for t in ruff python3 pytest jq yq git shellcheck yamllint shfmt hadolint; do
  printf '%-11s %s\n' "$t" "$(command -v "$t" >/dev/null 2>&1 && echo PRESENT || echo absent)"
done
```

Report any doc that claims a command runs when the binary is absent — **and the converse**, which is
the one that actually bit: a doc hedging that a tool "may not be" present when it demonstrably is.
Both are the same defect, recalling instead of running.

## Section 6 — CONFIG AND FIXTURES (skip cleanly until `config/` exists)

Once `config/` and `tests/fixtures/` exist:

- every `map:` path in a `sources.json` block must exist in that source's committed fixture — a
  mapping no fixture exercises fails at runtime, not in a test
- every source with `enabled: true` must have a URL that is not `REMPLACER` (hard rule 1)
- every key read from `.env` in code must appear in `.env.example`, and vice versa. **Never read the
  real `.env`** — it is permission-denied and gitignored on purpose
- every `criteria.json` key must be read somewhere, and every key read must be documented

If those directories do not exist, print one line saying so and move on. **Do not report an empty
`config/` as drift** — it is the documented target and has not been built yet. Note that
`CLAUDE.md`'s status line now reads *"the pure core and the store exist; nothing else does"*:
`src/php/Core/`, `src/php/Rent/Store/` and `tests/php/` DO exist and are populated, so a claim that this
repo is spec-and-prototype only is itself drift. Re-read the line rather than trusting this quote —
it was stale here for one round, in the tool whose whole job is catching exactly that.

## Section 7 — LINGERING MARKERS

```bash
grep -rn '<!-- ADAPT:' CLAUDE.md .claude/ scripts/ 2>/dev/null
grep -rn 'TODO\|FIXME\|XXX\|REMPLACER' CLAUDE.md .claude/ config/ 2>/dev/null
find .claude/hooks/ -name '*.sh.template' 2>/dev/null
```

`<!-- ADAPT: -->` markers are **intentional** — `CLAUDE.md` carries several for sections that cannot
be written until the stack exists. Report them under their own heading as *pending*, never as drift,
and never fill one by guessing.

---

## Report

Group by severity, and for every finding give the command whose output proves it. Write the full
report to `var/claude/scout-repair/<YYYY-MM-DD-HHMMSS>.md` (gitignored) and summarise inline:

```
/scout-repair — N findings (P0:a P1:b P2:c) · M checks clean · K pending-adaptation

P1  CLAUDE.md § "Claude config in this repo" lists a hook that .claude/hooks/
    does not contain — a future session will look for it and find nothing.
    (Historical P0 example — the shipped-framework drift class — retired with the
    container-era bootstrap on 2026-08-18.)
    proof: ls .claude/skills/ | grep cross-check  →  cross-check
    fix:   move it to the "As built" list
```

## What is never auto-fixed

Fix freely: an inventory table row, a dangling pointer, a skill-list membership, a missing `log_obs`
line, a stale tool claim, an exec bit. These are documentation catching up with reality.

**Never auto-fix, always report and stop:**

- anything touching the **excluded-tenure set, the 0.6 fail-closed threshold, or where `UNKNOWN` is
  routed**. If a doc and the code disagree about the social-housing rule, that is not drift to be
  tidied — the code might be the thing that is wrong, and "repairing" the doc to match would launder a
  P0 into a consistent lie. Report both sides and stop.
- a `permissions` change in `.claude/settings.json`.
- deleting a test, a fixture, or a corpus label.
- a spec change. `spec/PROJECT_BRIEF.md` is a ruling set; if reality contradicts it, reality is what
  needs explaining.

If a fix would touch one of these, ask via `/ask-human` (`AskUserQuestion`, recommended option first) and
**stop** — do not proceed on a default.

## `--check` mode

Scan, print the summary, write no files, exit 1 if any P0/P1 remains. Suitable as a pre-milestone
gate. Exit 0 means every claim surface in sections 1–5 matched the filesystem at that commit.

$ARGUMENTS
