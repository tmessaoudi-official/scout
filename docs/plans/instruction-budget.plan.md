# instruction-budget Plan

Bring the session's always-loaded instruction files back under Claude Code's 150,000-char total with
nothing lost. Warning seen 2026-11-16: *"8 instruction files add up to 182.6k chars, over the
150.0k-char total limit · largest: CLAUDE.md (75.6k), ~/.claude/CLAUDE.md (67.0k),
.claude/rules/expertise-core.md (15.2k)"*. Scope: this repo's files only (developer ruling below);
the global files are a follow-up. **This file is the plan; no move was made in the session that wrote it.**

## Decisions Log
- [2026-11-16 19:47] AGREED: Fix the overflow inside this repo only with /rules-split extended to CLAUDE.md's reference sections plus trims of the unscoped tooling.md and expertise-core.md; global files untouched (developer chose 'Rules-split extended', 2026-11-16).
- [2026-11-16 19:47] AGREED: This session writes and commits the plan only; the moves run in a later session (developer chose 'Plan only, stop').
- [2026-11-16 19:47] ASSUMED (review): Triggers stay always-loaded, only reference moves: expertise-core §5 and §8 and every command-run trap stay, because a path-scoped rule never loads for a command (advisor 3C round 1; /rules-split passive-presence rule). Alternative: move §5 to EXPERTISE-REFERENCE for ~3.3k more.
- [2026-11-16 19:47] ASSUMED (review): Keep a one-line inventory of the six hook/agent basenames in CLAUDE.md so drift-scan S3 stays green, rather than teaching S3 to read the new rules file. Alternative: edit drift-scan S3 and tests/test-drift-scan.sh.

## Formal Plan

### Baseline (2026-11-16, `LC_ALL=C.UTF-8 wc -m`, HEAD `f261894`)

| Always-loaded file | chars | owner |
|---|---|---|
| `CLAUDE.md` | 76,395 | this repo |
| `~/.claude/CLAUDE.md` | 66,971 | global (out of scope) |
| `rules/expertise-core.md` (no `paths:`) | 15,217 | this repo, curated |
| `~/.claude/BLAST-RADIUS.md` (`@`-included) | 10,026 | global (out of scope) |
| `rules/tooling.md` (no `paths:`) | 8,411 | this repo |
| `~/.claude/THINKING.md`, `RTK-local.md`, `RTK.md` | 6,346 | global (out of scope) |
| **Total** | **183,366** | harness says 182.6k (it counts `CLAUDE.md` as 75.6k) |

Path-scoped rules are NOT in the total [Inferred, strong: the warning counts exactly 8 files, the eight
above; `health.md`, `mail.md`, `rent.md`, `tests.md`, `prototype.md` (all with `paths:`) and `MEMORY.md`
are absent. Corroborated by /stack's 2026-11-07 live probe in /stack's docs/plans/claude-md-size.plan.md].
That same probe found a rules file whose opening `---` is missing loads at SESSION START, so every new
rules file needs well-formed frontmatter or it costs the budget it was meant to save.

**Need:** cut ≥ 33.4k (by `wc -m`) to pass. **Target:** total ≤ 145k (`wc -m`) — margin, because the
harness count differs from `wc -m` and `tooling.md` is an always-loaded intake target that regrows.
Project-owned always-loaded text is 100,023 chars, so the target means cutting ~38–41k of it.

### Sorting rule — trigger or reference (the /rules-split passive-presence rule)

A block STAYS always-loaded when it must be in context before any file of its area is read: §1, the
hard rules' rule sentences, gates, git autonomy, destructive/never-do rules, the certification ladder,
and every trap hit by RUNNING a command (running a command reads no file, so a path-scoped rule never
loads for it). A block MOVES only when it is knowledge needed *while working in* an area that is always
entered by reading a file of it. Ties stay.

### Hard constraints (what reads `CLAUDE.md`; verified 2026-11-16)

- `drift-scan.sh` S3 greps every `.claude/hooks/*.sh` and `.claude/agents/*.md` BASENAME in `CLAUDE.md`.
  `completeness-reviewer.md`, `source-resilience-reviewer.md`, `tenure-correctness-reviewer.md`,
  `format-on-write.sh`, `lint-on-write.sh` appear ONLY in § "Claude config in this repo" → a one-line
  inventory naming all six basenames stays in `CLAUDE.md`. S1b reads the `*-reviewer` names (the
  certification table stays). S6 reads §1's excluded set (stays).
- S2/S7 scan every `*.md`/`*.sh` in the tree (`rglob`), so moved text and its counts stay covered. S2
  flags a bare `.claude/…` or `docs/…*.md` path that does not exist: name not-yet-created files as
  `rules/<name>.md` in this plan and in commit-time text.
- `tests/test-cloud-readiness.sh` reads `CLAUDE.md`: § "A cloud session" stays whole, the two
  *"On the developer's machine, …"* sentences stay, and the literal `bash tests/test-cloud-readiness.sh`
  stays in Common workflows.
- Other `CLAUDE.md` mentions in `tests/`, `tools/`, `.github/` are comments (checked:
  `test-ci-workflow.sh` hardcodes its pins, it does not read the file).

### Steps (apply largest-first inside each step; `wc -m` after each move)

1. **Dedupe** (zero loss; each removal names its surviving twin in the commit body). Candidates:
   the in-docker "forwards no host variable" lesson (Common workflows + `tooling.md` + expertise-core
   §5 row "runs the ledger locally" → keep `tooling.md`, shorten the other two to the operative line);
   the per-task-tier ruling stated in both § Questions and § Certification ladder; § Certification
   ladder's "Probe in a pinned worktree" recipe (survivor: each agent charter's § "Probe in a
   worktree"); expertise-core §6/§7/§9 against EXPERTISE-REFERENCE §11/§12 and CLAUDE.md. Est. ~6k.
2. **`CLAUDE.md` → path-scoped rules, verbatim** (citations rewritten as quoted anchors only):
   - § "Claude config in this repo" listing → new `rules/claude-config.md`
     (`paths: .claude/**, tests/test-*guard*.sh, tests/test-drift-scan.sh, tests/test-lint-on-write.sh`);
     the six-basename inventory line stays. Est. ~6.5k.
   - § Testing "Store tests" category contract + the `doctor`/`journalMode()` paragraph → new
     `rules/store.md` (`paths: src/php/Rent/Store/**, src/php/Core/RunStore.php,
     src/php/Core/SourceHealthService.php, tests/php/Rent/Store/**, tests/php/Core/**`). The surface-matrix
     "do not narrow" rule stays (never-do). Est. ~5.5k.
   - Status-narrative bullets and the Domain glossary's `LandlordRegistry` four bullets → existing
     `rules/rent.md` (`src/php/Rent/**`, `config/rent/**`). Est. ~4.5k.
   - CI-runtime rationale in § "Run everything in Docker" (PCRE2 10.42 vs 10.44) → existing
     `rules/tests.md`. Est. ~0.6k.
3. **`CLAUDE.md` → `docs/ENGINEERING-NOTES.md`, verbatim** (history and rationale, rule sentence stays):
   Hard rule 7's three blockquotes (keep: repo is PUBLIC, "tree clean ≠ exposure over", Q40); Hard
   rule 5's robots narrative (keep: "a `null` robots means never check" and the status table one-liner);
   § Git autonomy's `deny`-is-empty rationale (keep the rule); § Testing's corpus-provenance history;
   the "That directory does not exist" paragraph under § Architecture. Est. ~8k.
4. **Architecture table detail → `docs/ARCHITECTURE.md`** (Core/Store row prose; the layer table stays
   as one line per layer). Common workflows: comments that DESCRIBE what a guard test proves move to
   the matching rules file; comments that are TRAPS (the `env` inside the wrapper, ugrep `\|`) stay.
   Est. ~5k.
5. **`tooling.md` split**: entries that only bite inside `tools/`, `compose*.yaml`, `Dockerfile`
   (`docker build --load`, `SSL_CERT_FILE`, the superseded `fetch-phpunit` HOME entry, `core.fileMode`
   on a mode change) → new `rules/tooling-docker.md` with `paths:`; any-directory entries (commit
   heredoc, tenure-guard diagnosis, in-docker env, drift-scan `.claude` paths, Composer) stay. Est. ~3.5k.
6. **expertise-core refresh** (curated file: run as a `/discover-expertise` refresh, bump its review
   date; never a hand edit). §0, §1 routing, §3, §4, §5 trigger table and §8 pointer table STAY (they
   are triggers). Only the dedupes of step 1 apply. Est. ~2.5k (counted in step 1).
7. **Budget guard** (keeps it from regrowing): a static check, failing when `CLAUDE.md` + the unscoped
   `rules/*.md` exceed a stated ceiling, or when a scoped rules file's frontmatter is malformed or a
   `paths:` glob matches no file — modelled on /stack's bin/tests/claude-rules-paths.test.sh. Where it
   lives is Needs input.
8. **Verify and land**: drift-scan clean; `tests/test-drift-scan.sh` and `tests/test-cloud-readiness.sh`
   pass in the dev image; every moved block byte-identical at its destination (extract-and-diff);
   `wc -m` total ≤ 145k; then a FRESH session must show no overflow warning — the only real acceptance.

**Stopping rule:** if the total is still ≥ 150,000 after step 6, STOP and ask — the remaining
candidates are triggers, and moving a trigger is the trade this plan excludes. No improvised tranche.

**Estimate:** steps 1–6 ≈ 38–41k [Inferred: per-section sizes measured 2026-11-16, keep/move split
estimated, not measured] → ≈ 142–145k.

**Certification:** per-task `advisor()` via `/certify`. The STANDARD carve-out applies only while no
file under `src/`, `config/` or `tests/` changes; step 7 may put a file under `tests/`.

## Status
<!-- progress-block v1 -->
| # | Step | Size | State | Evidence | Files |
|---|------|------|-------|----------|-------|
| 1 | Dedupe always-loaded repeats | S | todo | - | CLAUDE.md |
| 2 | CLAUDE.md reference blocks to path-scoped rules | M | todo | - | CLAUDE.md |
| 3 | History and rationale to ENGINEERING-NOTES | M | todo | - | CLAUDE.md, docs/ENGINEERING-NOTES.md |
| 4 | Architecture detail and guard-test comments | S | todo | - | CLAUDE.md, docs/ARCHITECTURE.md |
| 5 | Split tooling.md by scope | S | todo | - | - |
| 6 | expertise-core refresh (dedupes only) | S | todo | - | - |
| 7 | Budget guard | M | todo | - | - |
| 8 | Verify, land, fresh-session check | S | todo | - | - |
<!-- /progress-block -->
### Blocked
### Needs input
- Step 7: the guard as a new drift-scan section (`.claude/` only, keeps the STANDARD carve-out but
  needs a `tests/test-drift-scan.sh` case) or as a standalone `tests/` script. Default if unanswered:
  a drift-scan section plus its test case.
- Follow-up outside this plan: the global side is 83.3k of the 183.4k (`~/.claude/CLAUDE.md` 67.0k,
  `BLAST-RADIUS.md` 10.0k); trimming it helps every project and is its own session in `~/.claude`.
### Needs research
### Fragile
- Moving a block that is really a trigger silently switches it off: nothing errors, the lesson just
  stops arriving. Re-apply the sorting rule to every block, and list what stayed and why.
### Known issues
