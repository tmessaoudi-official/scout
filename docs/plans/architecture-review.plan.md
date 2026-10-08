# architecture-review Plan

Origin: the full read-only review of 2026-10-07/08. Five lanes covered DDD, hexagonal/Clean/CQRS fit, craftsmanship,
framework sync, and domain-agnosticism with expertise and docs. Together they produced 85 findings: P0 0, P1 19, P2 37,
P3 29. The lane reports live in the gitignored scratch area `var/claude/review-2026-10-07/` (A-ddd, B-hexagonal,
C-craftsmanship, D-framework-sync, E-agnostic-expertise, SYNTHESIS). The finding IDs below (A-n … E-n) refer to them.
This file is the durable record; the scratch area is not.

## Decisions Log
- [2026-10-08 00:24] AGREED: Target architecture: DDD + Hexagonal (Clean Architecture's dependency rule as enforcement, not its folder rings) + light CQRS (query services; no command bus, no event sourcing).
- [2026-10-08 00:25] AGREED: Motivation and bar: maintainability, phorj-liftable pure core, Rent/Car/Job reuse, and a portfolio-grade codebase demonstrating an expert full dev process.
- [2026-10-08 00:25] AGREED: Migration follows lane B's order 0-6; step 0 (dependency-rule test, re-rooted section-1 discovery tests) is a precondition of any code move.
- [2026-10-08 00:25] AGREED: Quick wins, recommended version: framework fixes first; T8 small defects with red tests (commute port moves to step 1); T6 folded into step 0 with PHPStan on a baseline raised gradually; T9 now = SHA pinning + badges + forward-only commit-subject rule, CHANGELOG/tags per milestone, CONTRIBUTING/SECURITY after the licence.
- [2026-10-08 00:25] AGREED: Docs: full showcase overhaul (ADRs, close the 3 finished plans, README front door, CLAUDE.md slimmed to its boundary test, incident history out of code comments).
- [2026-10-08 00:25] AGREED: Licence: MIT for the code, with tests/fixtures/** carved out as third-party material included for testing only; composer.json follows.
- [2026-10-08 00:26] AGREED: CALEOL (E-11): verify the term on an official source first, then add it as a procedural social tell with a red-first synthetic corpus case.
- [2026-10-08 00:26] AGREED: Expertise packs: fix stale facts now; restructuring waits for the framework's section-D expertise entry-point plan.
- [2026-10-08 00:26] AGREED: Mutation testing: Infection pilot on Rent/Core (pcov in the dev image), compared against the sabotage ledger, which stays for section-1 guarantees.
- [2026-10-08 00:26] AGREED: Persist this review in docs/plans/architecture-review.plan.md; lane reports stay in var/claude/review-2026-10-07/.
- [2026-10-08 00:27] ASSUMED (review): Review lanes ran as 5 unnamed general-purpose agents on the session model (override table: agents = as-is) — because autonomous mode does not ask the spawner model question. Alternatives: per-lane model choice.
- [2026-10-08 00:43] AGREED: D-7: scout aligns with the global closing rule — no interrupts mid-task on routine work; a turn closes with one AskUserQuestion; scout-lenses' prose 'say which to fix' close is removed.
- [2026-10-08 00:44] AGREED: D-10: scout pushes in batches per global Rule 10 (10 unpushed, milestone end, before a stop), reading CI at each push; scout's git section becomes its delta on Rule 10.
- [2026-10-08 00:44] AGREED: D-3: per-task gates follow the certification schedule (advisor); the milestone panel stays MAXIMAL with TWO consecutive fully-clean rounds, a scout-only rule stricter than /certify, and scout-lenses tells /certify so.
- [2026-10-08 01:12] ASSUMED (review): D-13: scout-repair's description and messages drop the claim that drift-scan checks the global framework — because drift-scan excludes ~/.claude references and runs in Docker where ~/.claude is absent, so the claim was false. Alternatives: add a host-side check that every cited /skill and ~/.claude path resolves (new code; could become a later plan step).
- [2026-10-08 01:12] ASSUMED (review): D-5: scout-lenses briefs /certify's general-purpose reviewers with a charter pointer per lens, not a subagent_type — because /certify step 3 spawns general-purpose or Explore and documents no subagent_type hint from *-lenses. Alternatives: ask the framework to honour a subagent_type hint (framework-side, open).
- [2026-10-08 01:13] ASSUMED (review): D-4: the var/claude report rule is narrowed to scout's own skills and /certify raw files; the global analysis skills keep their dirs — because they locate each other's reports there (449b09ac). Alternatives: ask the framework for a --output honoured by the whole pipeline.
- [2026-10-08 01:13] ASSUMED (review): D-17: the /long-run and /ci-watch pointers move to step 4's expertise refresh — because they replace text inside the L4 packs, which step 4 rewrites anyway. Alternatives: fix them in step 1.
- [2026-10-08 01:13] ASSUMED (review): D-3 charters: the 'TWO consecutive fully-clean rounds' lines in the three charters are left verbatim — because they apply when a panel runs, which the 2026-10-08 ruling keeps two-clean for. Alternatives: rescope them to 'at the milestone'.
- [2026-10-08 01:28] ASSUMED (review): A-14: a negative floor is labelled 'sous-sol' on both surfaces (score reasons and the notification line) — because neither '-1er étage' nor 'RDC' names a basement, and the two surfaces now read one table (Rent/Core/FloorLabel). Unmeasured whether any live source emits a negative floor. Alternatives: 'niveau -1' (keeps the depth).
- [2026-10-08 01:31] ASSUMED (review): A-16: Verdict::matched, VehicleVerdict::matched and JobVerdict::matched replace an EMPTY reason list with one stated reason, 'aucune raison enregistrée pour ce score — à juger sur l'annonce', and a corrupt stored signals_json is warned by dedup_key in both rent drains — because a score without reasons is unreviewable (brief §5) and a throw would block the push. The guard sits on the domain Verdicts, not on Core Notification, because rent's context line would satisfy a Notification guard without explaining the score. Alternatives: throw; guard on Notification.
- [2026-10-08 01:40] ASSUMED (review): A-11: no new guard — lane A's finding was stale: ListingSnapshotTest::testEveryConstructorParameterSurvivesDecodeAndMerge (2026-09-04) already checks every RawListing constructor parameter through mergedWith() in both directions; the fix is the stale RawListing comment that said the guard could not catch it. Alternatives: a second merge guard in RawListingMergeTest (duplicate).
- [2026-10-08 01:40] ASSUMED (review): C-10: commute failures are counted by NavitiaCommute (non-success response or exception; an unmatched address is not a failure) through a CommuteFailures MutableByDesign counter pinned in TenureCorpusTest, exposed as failedLookups(): int; Pipeline warns once per pass on the delta plus its own catch. The commute HTTP-port half stays in migration step 1. Alternatives: record the count on the run row via RunStore.
- [2026-10-08 02:15] ASSUMED (review): step 2 landed as four commits (A-14 c1c6692, A-16 0bf693f, C-10 d292b6e, docs+A-11 d4fe57a) plus the ledger cases; the plan row cites d292b6e, the last code commit. Alternatives: one squashed commit
- [2026-10-08 03:21] ASSUMED (review): redeploy recreated all three watchers (rent, car, job), not rent alone, because A-16 changes the car and job verdicts and all three run scout:local; rollback tag scout:pre-arch-step2. Alternatives: rent-scout only
- [2026-10-08 07:58] AGREED: C-10 follow-up: stop the PRIM quota burn (1000/day measured) — one shared location predicate in Criteria, the planner skips out-of-area listings, destination coordinates resolved once per planner, a 429 stops lookups for the rest of the planner's life and the warning names the quota, a Navitia 404 with an answer error id is not counted; no schema change
- [2026-10-08 09:34] ASSUMED (review): quota fix: only no_origin (probed) and its name-symmetric siblings no_destination / no_origin_nor_destination excuse a 404; no_solution is excluded as unprobed, narrowing the chosen option's 'a 404 with an error id'. Alternatives: any 404 carrying an error id
- [2026-10-08 09:35] ASSUMED (review): quota refusals are counted per harvested card (the warning says how many listings lack their commute), not per distinct commune; the first deployed pass printed 80 for 61 stored in-area rows across 7 communes. Alternatives: count distinct communes
- [2026-10-08 11:56] AGREED: Next after the quota fix: roadmap step 3, CALEOL (E-11) as planned — verify the term on an official source, then a procedural social tell with a red-first corpus case (the closing question's label wrongly called it a source to add config-only; corrected here); the phase-2 quota check still runs at 00:47 Paris
- [2026-10-08 12:07] ASSUMED (review): CALEOL: only the acronym 'caleol' is added as a SOCIAL procedural tell; E-11's 'cal only in collocation' is dropped — because no collocation list for 'cal' has been verified (length alone is not the reason: 'sne' is already a bare 3-letter entry), and 'sans CALEOL' is left un-negated (over-rejects, the safe direction). Alternatives: add 'cal' in verified collocations ('passage en cal', 'commission cal') after an official-source check

## Formal Plan

**Target (ruled):** DDD + Hexagonal, with Clean Architecture's dependency rule as the enforcement and not its folder
rings, plus LIGHT CQRS: query services over the same SQLite, no command bus, no event sourcing. **Bar:** portfolio-grade.
The motivation is maintainability, a pure core the phorj transpile can lift, real Rent/Car/Job reuse, and demonstrating
an expert full dev process.

**Where the code stands** (no path lets an excluded tenure reach a notification today; the suite is
`OK (5769 tests, 16836 assertions)` in the dev image, 2026-10-07):

| # | Theme | Sev | Findings |
|---|---|---|---|
| T1 | The §1 eligibility rule holds by call-site discipline, not by construction. The cluster policy sits in the SQLite Store and Pipeline; the send gate is guarded by a text-scanning test; `Classification` can hold excluded+MATCH; veto provenance is not stored | P1 | A-1,A-2,A-3,A-6,A-12,B-1,B-4,C-7 |
| T2 | The application layer is inside the CLI (`RentScout` 3,439 lines, `runOnce` ~807 lines) | P1 | A-4,B-3,C-1,C-6,C-17,A-15 |
| T3 | No driven ports: a concrete Store (43 public methods), no clock port, 65 `getenv` calls, adapters compute their own health | P1 | A-5,B-2,B-10,B-11,C-5,C-11,B-13 |
| T4 | Car/Job are copy-paste twins (~79 %), "a new domain is one entry" is false, and Car/Job import Rent | P1 | A-7,A-9,A-10,B-7,B-12,B-14,C-2,E-1,E-3,E-4,E-5 |
| T5 | Generic Core carries domain knowledge (§1 acronyms in `Core/Text`) and infrastructure (RunStore, transports) | P1/P2 | A-8,B-6,B-9,E-2,E-6 |
| T6 | Nothing enforces the architecture: no static analysis, no dependency-rule test, CI shell lint is `bash -n` only | P1 | B-5,C-3,C-12 |
| T7 | `doctor` looks like a read and writes a run | P2 | B-8 |
| T8 | Small defects: floor-label divergence (−1), a match with empty reasons, commute failures swallowed twice, `mergedWith()` field-by-field copy | P2/P3 | A-14,A-16,A-11,C-10 |
| T9 | Public-repo basics: no LICENSE ("proprietary" on a public repo), no CHANGELOG/tags, pinning by tag | P1/P3 | C-4,C-9,C-15,C-16 |
| T10 | Docs: no ADRs, finished plans never closed, contradictions, CLAUDE.md fails its own boundary test, 44 % comment lines | P1/P2 | E-13..E-17,C-8,C-13,A-17,C-18 |
| T11 | Framework sync and expertise currency | P1-P3 | D-1..D-18,E-7..E-12 |

**Migration order (lane B).** Ledger churn per step is an estimate. `tests/test-sabotage-applies.sh` runs after EVERY step:
the 2026-09-01 RunStore extraction silently staled 39 of 607 cases.
- **0. Lock first — a precondition, not optional.** Dependency-rule test, network-guard test, the §1 discovery tests
  re-rooted to all of `src/php` with a rent selector. PHPStan arrives here as a pinned PHAR with a baseline, raised
  gradually; shellcheck and yamllint join CI. No ledger case changes.
- **1. Ports without moving code.** Store role interfaces, Clock, health lifted out of the adapters, commute onto the HTTP
  port (C-10's port half). About 0-5 cases.
- **2. Shared Car/Job application layer.** No §1 exposure. About 60 path rewrites and 15-25 expression rewrites.
- **3. Light CQRS and the `doctor` read/command split.** About 50 cases.
- **4. §1 cluster policy as a Rent/Core aggregate; the typed send gate in Rent/Application.** MAXIMAL certification on a
  frozen commit. About 18 cases.
- **5. Rent use cases out of `RentScout`.** About 136 cases, 40-60 of them rewritten.
- **6. Optional verbatim moves** (Core split, Car/Job folders). Path rewrites only.

**Independent quick wins**, in this order: framework fixes → T8 small defects (red test first; the commute port moves to
step 1) → CALEOL → expertise facts → LICENSE → T9 now-items. The remaining T9 items attach to migration milestones.

## Status
<!-- progress-block v1 -->
| # | Step | Size | State | Evidence | Files |
|---|------|------|-------|----------|-------|
| 1 | Framework sync fixes, SAFE-NOW (D-1 panel via /certify, D-2 main-plan Decisions Log heading, D-3 MAXIMAL/two-clean wording, D-4/D-5 scout-lenses, D-7 non-stop close, D-9 plan-location, D-10 Rule 10 cadence, D-12 dead cite, D-13 drift-gate claim, D-14, D-18; D-17 moved to step 4) | M | done | 5f3061f | CLAUDE.md, .claude/** |
| 2 | T8 small defects, red test first: floor label (A-14), empty-reasons match (A-16), mergedWith reflection guard (A-11: already guarded, comment only), commute failures counted (C-10 non-port half; the port move rides with step 8) | M | done | d292b6e | src/php/Rent/**, src/php/Car/**, src/php/Job/** |
| 3 | CALEOL (E-11): verify the term on an official source, then a procedural social tell + red-first synthetic corpus case + sabotage + surface matrix | M | done | daa7975 | src/php/Rent/Core/TenureClassifier.php, tests/fixtures/rent/tenure/corpus.json |
| 4 | Expertise facts refresh (E-7, E-9, D-8 facts only: counts, HC-rent ruling, post-review commits; D-17 /long-run and /ci-watch pointers; expertise-core's ledger row must say `env` goes inside tools/in-docker.sh; C-18: the dockerised suite now takes ~3 min, not 62 s; E-11 remainder: one "deliberately conservative" row each for PLI → UNKNOWN, Loc1 under loc'avantages → excluded, ANRU as an agency not a regime, verified on an official source first) | S | todo | - | .claude/rules/expertise-core.md, .claude/EXPERTISE-REFERENCE.md, .claude/skills/ |
| 5 | LICENSE: MIT + tests/fixtures/** third-party carve-out; composer.json license and description | S | todo | - | LICENSE, composer.json |
| 6 | T9 now: actions/images pinned by SHA/digest, CI badges, forward-only commit-subject rule | S | todo | - | .github/workflows/ci.yml, Dockerfile, README.md |
| 7 | Migration step 0: dependency-rule + network-guard tests, §1 discovery tests re-rooted, PHPStan PHAR + baseline, shellcheck/yamllint in CI | M | todo | - | tests/php/Repo/**, .github/workflows/ci.yml, tools/** |
| 8 | Migration step 1: ports (Store roles, Clock, health out of adapters, commute on HTTP port) | L | todo | - | src/php/** |
| 9 | Migration step 2: shared Car/Job application layer | L | todo | - | src/php/Car/**, src/php/Job/** |
| 10 | Migration step 3: light CQRS query services + doctor split | M | todo | - | src/php/** |
| 11 | Migration step 4: §1 cluster aggregate + typed send gate (MAXIMAL cert, frozen commit) | L | todo | - | src/php/Rent/** |
| 12 | Migration step 5: Rent use cases out of RentScout | L | todo | - | src/php/Rent/Cli/** |
| 13 | Migration step 6: optional verbatim moves (Core split, Car/Job folders) | M | todo | - | src/php/** |
| 14 | Docs overhaul: ADRs (docs/adr), close/ledger the 3 finished plans, README front door with diagram, CLAUDE.md slimmed to its boundary test, incident history out of code comments | L | todo | - | docs/**, README.md, CLAUDE.md |
| 15 | Infection pilot on Rent/Core (pcov in dev image + CI), compared against the ledger | M | todo | - | Dockerfile, compose.dev.yaml, tools/** |
| 16 | T9 at milestones: CHANGELOG + tags (first tag at the step-0 freeze), CONTRIBUTING, SECURITY | M | todo | - | CHANGELOG.md, CONTRIBUTING.md, SECURITY.md |
| 17 | Expertise restructure (dedupe 5-10x copies, checkable sources, session load) | M | deferred | - | .claude/** |
<!-- /progress-block -->
### Blocked
### Needs input
### Needs research
- PHPStan and Infection PHAR reachability from this box (GitHub release hosts; the egress has blocked codeload before) — probe before steps 7 and 15.
- Old watchers lost DNS for four polled hosts (inli, cityloger, logirep, cdc_habitat) from 2026-10-04 14:37 to 2026-10-08 (every run failed; `broken` alerts recorded in `source_alerts`), while IMAP through the same resolver worked. The containers replaced on 2026-10-08 00:40Z had started inside the outage, so the recreate is NOT shown to be the fix; the hosts resolve in the new ones. Default if unexamined: watch `source_runs` for those four; a recurrence re-fires `broken`. First check if pursued: host `/etc/resolv.conf` against the container's.
- `scout --domain=rent doctor` run with `docker compose exec` inside the live rent-scout hung 13+ min during its first pass (stopped by PID; read-only). Default: reproduce against a backup copy, not the live store.
### Fragile
- Every code move re-derives sabotage-ledger cases: run `tools/in-docker.sh bash tests/test-sabotage-applies.sh` after each migration step.
### Known issues
- Step 17 waits for the ~/.claude framework-health §D "expertise entry point" plan (peer session, 2026-10-07); restructuring now risks redoing it.
- RESOLVED 2026-10-08 (bc785be): the C-10 warning's real cause was PRIM's 1000-a-day quota (measured from its 429 headers), not 404s. Fix: out-of-area listings cost no request (shared `Criteria::matchesLocation()`), destination resolved once per planner, the first 429 ends the pass's lookups with its own warning line carrying the 429's figures, a `no_origin`-family 404 is an answer remembered for the pass. Open: whether in-area no-route communes still drain the quota (read `x-ratelimit-remaining-day` the day after a reset).
