---
name: domain-rental-tenure-matching
description: Use when a task touches scout's rent domain - French tenure classes (LLI/PLS/PLUS/PLAI/LIBRE), the section-1 eligibility gate, mixed_tenure, Q38/Q39 vetoes, SectionOneGate, reclassify --reopen, hard filters, score and push gate, digest/rollup routing, excluded-vocabulary traps; paths src/php/Rent/**, src/php/Core/Text.php, src/php/Core/Notify/**, config/rent/criteria.json. How an expert works here - procedure, traps, checks, evidence.
---

Review date: 2026-10-02   Validation mode: advisory   Core: .claude/rules/expertise-core.md

## Roles and mental models
- **Section-1 guardian**: a pushed social flat wastes the user's application; every doubt routes to the "a verifier" digest, never MATCH.  [Source: C section-1 rule; R 2]
- **Classifier maintainer**: five signal tiers (CLAUDE.md glossary); tier 5 source default is LIBRE, cap 50; an ABSENT signal lowers confidence, never inherits `default_tenure` at full confidence.  [Source: T 8.2; R 2]
- **Cluster/twin judge**: per CLUSTER and twin pair, not per row; ask "at what scope is this decided?" before persisting a safety fact.  [Observed: 2026-08-30, M C3]

## Tenure vocabulary (enum `src/php/Rent/Core/Tenure.php`)
| Case | Meaning here | Route |
|---|---|---|
| LLI | intermediate (ordonnance 2014-159), primary target | eligible |
| LIBRE | private market, own track since Q4 | eligible, confidence cap 50 from source default |
| PLS, PLUS, PLAI, ANRU, ANAH, CONVENTIONNE, SOCIAL | excluded; SOCIAL = procedural tell, tier undetermined (`agreesWith()`: family of any excluded member) | rejected |
| UNKNOWN | neither eligible nor excluded | fixed to Outcome::DIGEST |
The excluded set is hard-coded `match` arms (a re-enabling config key is a P0). "action logement" is a SOURCE, not a tenure.  [Source: T 8.1; Ruled: developer 2026-08-06, 2026-08-07 Q18]

## Hard rules (delta over CLAUDE.md)
| Rule | Source |
|---|---|
| F1 is code, not config. `mixed_tenure` is mandatory in every source block: code default AND loader refusal (`Rent/Config/ConfigLoader.php`, `requireBool('mixed_tenure')`). A `_`-prefixed key is a comment only while its un-prefixed twin exists in the same object (a renamed `_mixed_tenure` silently disarms section 1). | [Ruled: developer 2026-08-07] |
| `mixed_tenure: false` claims NO social stock; arming `true` on seloger/bienici/leboncoin/pap would digest 100 % of the source. Residual (anonymous advertiser/agency, no tenure on the card -> LIBRE 50 -> MATCH) stays; `Rent/Core/LandlordRegistry` covers a card naming its bailleur (per-source `advertiser_pattern`). | [Observed: 2026-09-01/08; Source: R 2] |
| Scheme marketing names (`loc'avantages`, `louer abordable`, `convention anah`) are tier-2 labels beating the source default; do NOT flip the flag (13 corpus MATCHes become DIGEST). REJECTED by the corpus: `loyer plafonne`, `plafond de ressources`; `loyer maitrise`/`loyer abordable` withhold, not reject. `financement: PLI` stays UNKNOWN -> digest (unknown-003). | [Ruled: 2026-08-07] |
| Q21: a SHOUTED `PLUS` no comparative explains is a DOUBT -> digest; lowercase `plus` untouched (trap-010). Closed financing-noun lists always leak (`Finance PLUS`, `Agree PLUS`, `Gamme PLUS`); keep the guard on private portals. | [Ruled: 2026-08-07] |
| Q38 same-track veto: an EXCLUDED member decides the whole cluster, an UNDETERMINED one does not (In'li's 166-of-168 weak-label rows would digest the yield). Durable via `Store::groupExcludedTenure()`. An over-merge rejects BOTH flats for good; `group_key` is never cleared. | [Ruled: 2026-08-24, forced by a P0] |
| Q39 cross-track veto (schema v12, `Dedup::twinReason()`, fuzzier pair): the agency copy's landlord route decides the agency copy and vice versa. NOT one-line reversible: `Pipeline` writes the JUDGED classification into the row's `listings.tenure`; `staleVerdicts()` skips excluded tenure, `pendingDigest()` skips non-DIGEST, `replay` writes no verdicts. | [Ruled: developer 2026-08-29; Observed: 2026-08-31] |
| Repair: `scout --domain=rent reclassify --reopen=<dedup_key>` prints provenance across FOUR routes (own reading / twin reading + source / group veto / same dwelling under another ad id), clears own and twin readings, re-judges on own evidence; group and same-dwelling vetoes are REPORTED, not cleared. Never a pattern or "all". | [Ruled: developer 2026-09-05; Observed: 2026-09-07] |
| `SectionOneGate` (`Rent/Cli/SectionOneGate.php`) re-reads all four routes fresh before every send; two reflection-driven guards fail the suite on an unguarded surface. | [Observed: 2026-09-06/07] |
| `reclassify` re-judges on the stored v7 snapshot alone, invariant `evidence superset-of original, never subset` (field PLS plus title "logement intermediaire" is undetermined by CONFLICT; title alone would MATCH). Any transition INTO MATCH is announced, REJECT -> MATCH included. Pre-v7 rows are skipped loudly; `--since` is refused (exit 2). | [Ruled: 2026-08-07, 2026-08-24] |
| Digest has two entrances (tenure doubt; price-per-m2 plausibility): `Rent/Core/DigestCause` discriminates, `Verdict::digest()` takes it with no default; one entry that did not earn the "au regime indetermine" clause removes it for the whole batch. | [Source: C; R 2] |

## Hard filters, score, push gate, routing
- Location: `communes` empty = region mode, `postcode_prefixes` 75,77,78,91,92,93,94,95 are the WHOLE filter; loader refuses both empty; postcode is a STRING; match normalised commune+postcode, never a description substring. Neither = rejected; one = judged on it with a `reasons[]` entry.  [Ruled: developer 2026-08-22, 2026-08-07 Q32; Source: config/rent/criteria.json `_communes`]
- `max_rent_cc` 1200, `min_rooms` 3, `min_surface_m2` 50 (verified in criteria.json). Unknown rent/rooms/surface loses its score component, said in `reasons[]`. `max_floor`/`require_elevator` DO NOT EXIST (Q5): floor/elevator are score only, S6 fires only when the lift is explicitly False.  [Ruled: 2026-08-07, 2026-08-22]
- Never predict a yield from the previous filter's matches (8 matches at 1800 died at 1200, 83 others appeared). A new filter reads as a collapse while the backlog drains at `detail_budget_per_pass`. State the measured yield from one poll on a throwaway `RENT_SCOUT_DB`.  [Observed: 2026-08-22, 2026-09]
- Score: UNKNOWN never reaches scoring. Heating penalties STACK (electric -35, gas -20, mode-without-energy -20); unstated energy takes the BASE penalty only; negation read first.  [Ruled: 2026-08-07 Q31, developer 2026-09-08]
- Push gate: `push_min_score` 55 (rent; verified in criteria.json) is SEPARATE from `high_priority_score` 50; thresholds follow measurement on stored snapshots (1 046, p90 54), never the reading. Rebalancing the weights was offered and DECLINED. A match under the line waits for the "verifie, score bas" rollup (own heading, never mixed with "au regime indetermine"). Amenities are display-only.  [Ruled: developer 2026-09-05, 2026-08-26, 2026-09-08]
- Drain paths: only `digest` and the daily floor (`--watch` only); the end-of-pass emission is NOT a drain. Drain RE-SCORES from the v7 snapshot with the STORED classification via `CriteriaEngine` (never re-runs the classifier); a row current criteria reject waits, warned. `notified_as` is monotone DIGEST < ROLLUP < MATCH (schema v8). An empty bin emits nothing and writes no `state/rent-digest.txt` marker.  [Source: D I; Ruled: 2026-08-26]
- Rent drop notifies at >= 20 EUR OR >= 2%, or on crossing a hard-disqualifier boundary. Q36 seed guard: `Store::isSeenSetEmpty()`.  [Ruled: 2026-08-07]

## Traps with the failing symptom
| Trap | Failing symptom | Source |
|---|---|---|
| Classifying page furniture: Cityloger "Commission d'attribution", Erilia footer "Ai-je droit a un logement social ?", CDC tooltip "au plus pres", URL tracking token (`cave`), `plusieurs` (more in core section 0) | correct LLI conflicts to UNKNOWN 0.00 / 14 of 16 headed for digest / every listing REJECT while health stays green. A detail map addresses the LISTING, never the page; URLs are classified text (`RawListing::withoutUrlParameters()`) | [Observed: 2026-08-20..26] |
| Description arriving twice (as property and bare `description` key) | 4 of 40 live matches demoted to digest on `plus`; `title`/`description` now route to the prose scan, guarded on CONTAINMENT | [Observed: 2026-08-23] |
| Fixing one of two symmetric surfaces (push / digest / rollup / floor / reclassify; rent / car) | round-7 P0s: rent drain judged section 1 from the row's own tenure only (twin PLS pushed as MATCH); `CarScout::collectRollup()` discarded a REJECT; twins straddling the gate sent twice (collapse BEFORE the split); `overflow()` phantom backlog | [Observed: 2026-09-05..07] |
| A per-ROW persisted safety fact | lapses when its row is not the one judged (same P0 three panel rounds); write it on EVERY cluster member, read the most restrictive (`Pipeline::twinClassification()`) | [Observed: 2026-08-30, M C3] |
| Pass where the excluded sibling was not fetched (failed source, `--source=` run, delisting) | flat laundered back to MATCH; `reclassify` undid the veto (both fixed) | [Observed: Q38] |
| A hard filter on a usually-absent field ("unknown = no", core section 0) | "nothing arrives", health green | [Source: C rule 9; R 1] |
| Fetch failure licenses a weak signal | 25 of 1036 In'li rows hydrated with only title+postcode; 8 judged LLI/50 MATCH on source default alone, 1 announced | [Observed: 2026-10-02 audit P0-2, T 8.4] |

## Before-you-start checks per path
Path note: `src/php/Enrich/` is EMPTY; rent enrichment is `src/php/Rent/Enrich/` (`CommutePlanner.php`, `NavitiaCommute.php`; no `Transit.php`). `src/php/Notify/` does not exist: rent formatting is `src/php/Rent/Notify/Formatter.php`, channels `src/php/Core/Notify/`.  [Observed: ls 2026-10-02]
- **`src/php/Rent/Core/{Tenure,TenureClassifier,TenureSignal,Dedup,LandlordRegistry,PlafondBands}.php`**: run `bash tests/test-tenure-guard.sh` (grep tripwire `.claude/hooks/tenure-guard.sh`; "a clean run proves nothing"); read the matching ENGINEERING-NOTES section first; never relabel or delete a fixture in `tests/fixtures/rent/tenure/corpus.json` to pass; any new label or tier needs a corpus case in the SAME change, with an eligible counter-case.  [Source: C; T 8.2]
- **`src/php/Core/Text.php`** (`fold`, `foldPreserveCase`, `hasToken`): the `PLUS`/`plus` distinction depends on `foldPreserveCase`; output must not depend on locale. Decode can only restore a label, no entity expands to PLAI/PLUS; `fold()` refuses undecoded entities. Add the new vocabulary-trap string to the corpus as eligible text.  [Source: Text.php docblocks; Observed: 2026-08-25]
- **`src/php/Rent/Cli/{Pipeline,RentScout,SectionOneGate,DigestBatch}.php`**: name all FOUR routes and ALL surfaces (push, digest, rollup, floor, reclassify, rent drain, car rollup) in the change; after any change to the record path or `RawListing` contents (clone-with inside the class): rebuild `scout:local`, restart, watch `docker compose logs` until `annonce(s) analysees`, because commute is OFF in tests.  [Observed: 2026-08-29, M C1]
- **`src/php/Rent/Store/Store.php`**: migrate through `upgradeFrom()`; rehearse on a `.backup` copy, compare row counts (dropped price history looks successful). Schema marks: v7 snapshot (no backfill), v8 `notified_as`, v12 twin tenure.  [Source: R 4]
- **`config/rent/criteria.json`** (and `sources.json`): unknown keys are hard errors (`_comment|_why|_source|_verified_at` only); `/config/*.local.json` is gitignored and wins field by field (widen `.gitignore` BEFORE moving ignored files; check `git status --porcelain | grep '^A'`); a regex must pass the loader compile check and `PortablePatternsTest` (CI PCRE2 10.42 vs local 10.44).  [Ruled: 2026-08-07; Observed: M C5]

## What good looks like (checkable)
- New refusal/veto: a failing corpus or `PipelineRunTest` case first, red for the stated reason; a case where the vetoed flat is NOT fetched in the pass; a case through `reclassify`.
- New tenure signal: `ConfigTest::testEveryCorpusSourceAgreesWithConfig` green with >= 5 shared sources; counter-case for the eligible spelling.
- Reason wording does not claim where PLS was read (pinned by `PipelineRunTest::testTheDurableExcludedReadingDoesNotClaimWhereItWasRead`).

## Evidence table (what certifies a change here)
| Change touches | Certified by | Sabotage shape that matters | Stays uncertified unless run |
|---|---|---|---|
| Tenure classifier, `Core/Text.php`, corpus, vetoes, SectionOneGate | PHPUnit suite + corpus (143+ cases) + `tests/sabotage-check.sh` (`SABOTAGE_SHARD=i/n`), one filtered run for new cases | mutate the CONSEQUENCE (force the veto condition true), read WHICH test goes red; replace the right-hand side, assignment intact; restore from a byte copy | the full ledger (hours): say so; the DEPLOYED first pass |
| Record path, `RawListing` contents, Enrich | `PipelineRunTest` with `FixedPlanner` + deployed first-pass log line | clone-with instead of field-by-field copy | live commute hop |
| Digest / rollup / push gate | `HighPriorityMarkerTest`, `PipelineRunTest`, rollup tests; offline re-judge of stored snapshots for any threshold | force the gate on/off; assert the surface emits, not just counts | `test-notify` to a real channel (console/file is not delivery) |
| `config/rent/criteria.json` values | loader compile check, `PortablePatternsTest`, one measured throwaway poll | variable-length lookbehind | `gh run list --limit 5` CI result after the push |
Never certified by gates, declare by name: delivery to a human, live yield, social-flat leakage on sources whose cards state nothing (the residual above).  [Source: core EXPERTISE s4; Observed: 2026-08-29, 2026-09-06/07]

## Reviewer lenses
1. **Section 1**: can any route (own, twin, group, same-dwelling, source default) let an excluded or doubtful flat reach MATCH or a push?
2. **Surface symmetry**: every surface and car twin enumerated by reflection.
3. **Instrument honesty**: a count nobody reads, an invented cause on a true number, an unknown treated as no.
