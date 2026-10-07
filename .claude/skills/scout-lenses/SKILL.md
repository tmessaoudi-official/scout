---
name: scout-lenses
description: >
  MANDATORY companion to every global review skill run in rent-watch (/certify, /sweep, /sleuth,
  /inspect, /gaps, /forge, /cross-check, /converge, /pre-commit, /sabotage-check, /aggregate-findings)
  — load it first: review dimensions, panel lenses, sleuth lens K, repo conventions.
---

<!-- Description history (moved out of the description 2026-09-28 to keep it ≤300 chars, review-remediation 5.8): Extracted 2026-08-18 from the deleted repo-local copies of those skills (global-is-reference ruling: a repo may not duplicate a global skill; what was repo-specific in them lives here instead). -->

# /scout-lenses — rent-watch review dimensions & conventions

This skill adds no procedure of its own. It is the **domain payload** for the global review
skills: run the global skill for its machinery, with everything below folded into its scope.

## Repo conventions (apply to every review skill)

- **Reports**: scout's own skills and `/certify`'s raw files write to `var/claude/<skill>/`
  (gitignored). The global analysis skills (`/sweep`, `/sleuth`, `/inspect`, `/gaps`,
  `/aggregate-findings`) keep their own directories under `~/.claude/projects/`, because they find
  each other's reports there.
- **No interrupts mid-task; one closing question.** Routine review work runs without stopping, and
  the turn closes with ONE `AskUserQuestion` listing the findings by severity (CLAUDE.md § Questions,
  developer ruling 2026-10-08). Never a prose "say which to fix".
- **Gates go through `/certify`**, never `/converge`: `/converge` spawns no panel. Reviewers probe in
  a pinned worktree, never the live tree; the recipe is in each agent charter under § "Probe in a
  worktree".
- **Project scope only.** `~/.claude/` is the developer's own persistent install, out of this
  repo's audit scope — audit it from its own sessions, not from here.
- Findings about files that do not exist yet are not findings — say the module is absent instead.
  The classifier is `src/php/Rent/Core/TenureClassifier.php`; `src/core/tenure.py` has never existed.

## For `/certify`: the panel's lenses

`/certify` takes its lenses from this skill and spawns general-purpose reviewers; it does not spawn
the agents in `.claude/agents/` by type. So brief each reviewer with its lens AND its charter: the
first instruction is "read `<charter>` and work as it says" (its attack surfaces and its worktree
recipe).

| Lens | Charter the reviewer reads first |
|---|---|
| correctness + regression | `.claude/agents/tenure-correctness-reviewer.md` |
| resilience + legal posture + secrets | `.claude/agents/source-resilience-reviewer.md` |
| completeness + blast radius | `.claude/agents/completeness-reviewer.md` |

- **Convergence when a panel runs:** TWO consecutive fully-clean rounds, which is stricter than
  `/certify`'s one; any finding resets the counter; cap 5, then ask (CLAUDE.md § "Certification
  ladder", developer ruling 2026-10-08). The STANDARD carve-out there (a diff touching no
  application source) still allows one reviewer and one clean round.
- **Freeze first:** a round run on a moving tree does not count toward the two.
- **Reviewers run every PHP, test and guard command through `tools/in-docker.sh`** (CLAUDE.md § "Run
  everything in Docker"), never the host `php`.

## For `/sabotage-check`: the ledger

- The ledger is `tests/sabotage-check.sh`; run it in the dev image (`tools/in-docker.sh bash
  tests/sabotage-check.sh`). The full ledger takes hours: it runs nightly in CI as six shards
  (`SABOTAGE_SHARD=<i>/<n>`).
- One new case: `tools/in-docker.sh env SABOTAGE_FILTER='<regex on labels>'
  SABOTAGE_SUITE_TIMEOUT=1500 bash tests/sabotage-check.sh`. The `env` goes INSIDE the wrapper:
  `tools/in-docker.sh` forwards no host variable, so a prefix before it is dropped and the FULL
  ledger starts (observed 2026-10-08). Join labels with a plain `|`. `/bin/grep` here is ugrep,
  where `\|` is literal: such a filter skips every case and still exits 0 with a loud PARTIAL RUN
  line. A filtered run is never a ledger result.
- After any code move, run `tools/in-docker.sh bash tests/test-sabotage-applies.sh`: a sabotage
  expression that matches nothing reports coverage it does not have.

## For `/pre-commit`: scout's deltas

- `core.fileMode=false` makes a pathspec commit drop a mode change. Commit a mode change from the
  index on its own (`.claude/rules/tooling.md`, the 2026-10-03 entry).
- Identity and trailers: CLAUDE.md § "Git autonomy" (the author's case is checked; no trailers).

## For `/sleuth`: budget for lens K

Run agent K (below) in sleuth's second batch, so the global cap of five concurrent agents holds. K is
judgment work: it takes the model sleuth's table gives its judgment agents.

## Review dimensions — MANDATORY additions to any sweep/review of this repo

Run these **in addition to** the global skill's own dimensions, on every review. Skip a dimension
only when the tree it applies to genuinely does not exist yet — and then **name the dimensions you
skipped and why**. A silently skipped dimension is a coverage lie. Reviewing `prototype/scout.py`
against these is legitimate and useful.

- **Tenure exclusion (P0 — this is an eligibility error, not a bug).** Trace every path from a parsed
  listing to a notification and prove that PLAI, PLUS, `conventionné`, ANRU and ANAH cannot reach it.
  A path that *happens* to filter them today because of ordering, a truthy default or a source that
  currently returns no social stock is the bug — the exclusion must hold by construction. Any config
  key, CLI flag, env var or default that could re-enable an excluded tenure is P0 even if nothing sets
  it. `prototype/scout.py` has **no classifier at all** and is a standing example of this failure.
- **Fail-closed classification (P0).** Confidence `< 0.6` on a source flagged `mixed_tenure: true`
  must yield `UNKNOWN`, and `UNKNOWN` must route to the low-priority "à vérifier" digest — never to a
  match, never to the normal notification path. Check the *default* when a signal is missing, not just
  the happy path: an absent `financement` field must lower confidence, not silently inherit the
  source's `default_tenure` at full confidence. A missed listing is annoying; a social-housing false
  positive makes the tool untrustworthy, which is worse.
- **Classifier corpus integrity (P0).** The hand-labelled fixture corpus is the only thing standing
  between a refactor and a silent regression. A skipped, xfailed, deleted or relabelled fixture is P0
  unless the label was demonstrably wrong — and then the evidence goes in the commit message. Never
  weaken a fixture to make a change pass. New classifier behaviour needs a new fixture whose
  pre-change form provably fails.
- **Silent source breakage.** The classic failure of this tool class: a selector breaks, the source
  returns zero results forever, no notification arrives, and the user concludes the market is quiet.
  Every source must persist last-success, last-count and a rolling 7-day mean, alert `SOURCE_BROKEN`
  after N consecutive empty runs against a non-zero baseline, and warn on a >70% drop. A `try/except`
  that swallows a fetch error and returns `[]` is this failure mode wearing a hat — see the
  anti-bandaid gate below.
- **Field-map and parser fragility.** Parsers run offline against frozen fixtures; a parser test that
  needs the network is not a test. Check that rent is compared **charges comprises** and that a source
  reporting rent excluding charges is normalised rather than compared raw — an un-normalised rent is a
  wrong disqualification, which is invisible. Check that commune matching uses structured fields, not
  a substring search over the whole blob: "proche Chatou" in a Paris listing must not pass the commune
  filter.
- **Dedup correctness.** Within-source key stability first: a `(source, external_ref)` that changes
  between runs re-notifies forever, and a hash over a volatile title does the same. Cross-portal, the
  fuzzy match on `(cp, surface ±2 m², rent ±20 €, rooms)` must be checked in both directions — a
  cluster that over-merges hides a real second flat, one that under-merges triple-notifies the same
  one. A rent drop on a known listing is a notification-worthy event, not a duplicate.
- **Legal and ToS posture (P0).** Direct scraping of a private portal must be opt-in, disabled by
  default, `legal_risk: true`, and refuse to run without an explicit flag. Any CAPTCHA solving, proxy
  rotation, fingerprint spoofing or dishonest User-Agent is P0 — remove it and route the source
  through email-alert ingestion instead. Check `robots.txt` handling and request rates. A source added
  under `demande-logement-social.gouv.fr` or Bienvéo is P0 (out of scope by §1).
- **Secrets hygiene (P0).** IMAP credentials, notification tokens, the IDFM/PRIM API key and the RFR
  figure live in `.env`, gitignored, mirrored by an in-sync `.env.example`. Grep the diff for a
  credential, a mailbox address, a real RFR or a personal financial figure reaching a committed file,
  a log line, an exception message, a fixture or a notification body. A fixture captured from a live
  payload must be scrubbed before it is committed.
- **Notification quality.** Every notification carries `score` **and** human-readable `reasons[]`
  explaining it. A score with no reasons is unreviewable by the user and untestable by us. Hard
  disqualifiers reject silently and are logged only — a disqualifier that notifies, or a score
  component that silently disqualifies, means the two mechanisms have been conflated.
- **Anti-bandaid gate.** The global Phase 6 anti-bandaid gate applies to every review here (no
  evidence ⇒ **P0**, replace it with a root-cause fix). In this repo its highest-frequency instance is an adapter
  swallowing a fetch exception and returning an empty list — that converts a loud breakage into a
  silent one, which is exactly the thing source health exists to prevent.

## Sleuth lens K — MANDATORY additional agent for /sleuth

Beyond the global skill's agents A–J, always run **agent K** on this repo, and report its findings
as category **K** alongside A–J:

> **K — Tenure and silent-failure divergence.** rent-watch decides the same thing in more than one
> place — the classifier, the criteria engine, a source's `default_tenure` hint, a per-site adapter
> override in `adapters/sites/`, and `config/rent/sources.json` — and every one of those is a chance for two
> answers to disagree. Unlike a display bug, a disagreement here is invisible: the tool simply says
> nothing, or says the wrong thing confidently. Hunt for places they can:
> **(1) Tenure-decision divergence** — tenure decided in the classifier and *re-decided* anywhere else
> (a source adapter that pre-filters, a criteria check that reads a raw `financement` string directly,
> a notification formatter that re-derives a label). A second implementation of the tenure rule is a
> divergence by construction; find which one is authoritative and whether anything says so. If a
> source's `default_tenure` or `mixed_tenure` flag contradicts what the classifier concludes from the
> listing text, that contradiction must lower confidence, not be silently resolved in either direction.
> **(2) Fail-closed divergence** — an `UNKNOWN` verdict honoured on one path and ignored on another
> (the digest builder, a `--no-state` run, a replay, a rent-drop re-notification, a cross-portal
> cluster that inherits the tenure of whichever member was parsed first). Look for a listing reaching
> a notification without passing the same gate its neighbours pass.
> **(3) Disqualifier ↔ score divergence** — a criterion enforced as a hard disqualifier in one place
> and as a score penalty in another (floor/elevator is the standing example), or a disqualifier
> applied before enrichment so it rejects on a field that enrichment would have filled. Silent
> over-rejection is the failure mode nobody notices, because nothing arrives.
> **(4) Loud-failure-to-silent-failure divergence** — an exception path that converts a broken source
> into an empty result set: a bare `except` returning `[]`, a `dig()` miss yielding `None` that a
> filter then treats as "does not match", a schema change that makes every field map miss. Cross-check
> against the health module: if the run records a success with zero items where the baseline is
> non-zero and nothing alerts, that gap IS the finding.
> **(5) Dedup ↔ notification divergence** — a cluster key computed one way when storing and another
> when looking up, so the same flat re-notifies forever or a genuine second flat is swallowed; a price
> history that records a drop the notifier never surfaces, or vice versa.
> **(6) Config ↔ code divergence** — a `config/rent/sources.json` or `criteria.json` key read under one
> name and documented under another, a field map referencing a path the fixture does not contain, a
> `.env` var consumed in code but absent from `.env.example`. A config key that silently does nothing
> is indistinguishable from one that works.
> For each: file + line, which two surfaces diverge, the smallest listing fixture that would show it,
> and whether a test guards it — read the dependency manifest for the real runner rather than assuming
> one. If the module is not in the tree yet, say so; if it is and no test covers the divergence, that
> absence IS the finding.
> Research only, no writes.
