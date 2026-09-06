# scout — UNIFIED EXECUTION PLAN (the single source of truth)

> SINGLE SOURCE OF TRUTH for all pending scout work (developer ruling, 2026-08-31).
> Supersedes every plan in docs/plans/archive/. Rulings land in this file's Decisions Log.

> **EXECUTION/REVIEW SPLIT (developer's workflow, 2026-08-31).** A DIFFERENT model executes this
> plan (bypass-permissions session); the reviewing model then certifies what was done. Two duties
> follow for the executor: (1) leave verifiable evidence for every track — paste real command
> output (test tallies, ledger results, doctor output, SQL results) into `var/claude/`
> execution notes and commit messages, never a narrative claim; (2) do not self-certify — the
> certification rounds this plan requires (Track 0 round 4, Track 1's MAXIMAL round, Step U's
> STANDARD pass, Track 4's own MAXIMAL) are run by the REVIEWING session against frozen commits,
> not by the executor declaring itself clean. The executor stops at each certification boundary
> and hands back.
>
> **HANDOFF SEQUENCE**: on approval, the planning session performs **Step U only** (install this
> plan into the repo, archive the 15 old plans, fix the citations, commit+push) and then STOPS.
> The developer switches models; the executing model starts from
> `docs/plans/scout-unified-execution.plan.md` at Track 0.

## What this document is

The developer asked (2026-08-31) for ONE unified plan that supersedes every divergent plan
document, detailed enough that **a different model with no session history can execute it without
misinterpretation**. It merges, with nothing lost:

1. `~/.claude/plans/heko-binary-scone.md` — the plan of record (advisor rounds 1–6: findings
   4,1,0,0,2,1; then a 3-lens plan-review panel, ~24 findings, all folded in).
2. `~/.claude/plans/let-s-go-through-the-fuzzy-wolf.md` — the 2026-08-31 12:40 re-verification
   delta (fresh primary evidence; 6 corrections).
3. Today's post-12:40 findings: the PAP template breakage (Track 1h, verified against the live
   store down to the exact pattern misses) and the four developer rulings received 2026-08-31
   (see Decisions Log).

**Review provenance**: the two source documents were heavily reviewed as described above; THIS
merged text was assembled 2026-08-31 and checked by `advisor()` in the assembling session. The
first executor should treat the per-track evidence lines as verified (each is reproducible
read-only) and the merged prose as one review deep.

**Executor briefing (fresh model, read first):**
- `CLAUDE.md` auto-loads and WINS on any conflict. Hard rules 1–10 and §1 (no social housing ever
  surfaced as a match) are non-negotiable. `docs/OPEN-QUESTIONS.md`, `docs/SOURCES.md`,
  `spec/PROJECT_BRIEF.md` are reference/rulings — kept, not superseded by this plan.
- `/bin/grep` on this machine is **ugrep 7.8.4**: in `SABOTAGE_FILTER`, join labels with a plain
  `|` inside double quotes — `\|` is LITERAL, matches nothing, and exits 0 (reads as a clean run;
  the script prints PARTIAL RUN — never accept that as a ledger result).
- The local PHP tracing JIT crashes long PHPUnit runs (exit 134). Run the ledger with
  `PHP_INI_SCAN_DIR="/stack/tools/phpbrew/php/php-8.5.9/var/db/cli:<dir containing opcache.jit=off>"`
  — KEEP the original scan dir or `iconv` disappears and the baseline reddens (18 errors, measured).
- Spawn reviewer subagents **UNNAMED** (a `name:` teammate's report is undeliverable here). Panel
  reviewers probe in pinned `git worktree`s, copy with `cp -a`, never symlink `vendor/`.
- Commit identity `Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>`, no trailers;
  `master` only; plain `git push`. Verify `git config user.name`/`user.email` before the first commit.
- Gate state: all six gate-bypass sentinels for this project already exist on disk
  (`~/.claude/projects/-stack-projects-scout/state/*-bypass`, written 2026-08-31 00:40), and global
  permission-swap is armed. **Verify they are present; do not re-run `/gates-bypass`** — the
  predecessor plan's "Step 0" is already done.

## Verified state snapshot (2026-08-31, all read-only, reproducible)

- HEAD `b8a1687`, tree clean, deployed image `09:02:14Z` (current, not drifted).
- Milestone `b8a1687` is **mid-certification**: owed = filtered sabotage ledger (6 cases) + panel
  round 4; MAXIMAL's two-consecutive-clean bar not yet met (round 3 was clean at the prior commits).
- Rent store `state/rent-watch.sqlite3`: ~1,790 listings and growing; car store exists with the
  composed rent-store tables (`Store::SCHEMA_VERSION=12` vs `VehicleStore::SCHEMA_VERSION=1`).
- Sabotage filter for Track 0 selects **exactly 6 of 574** labels (verified by listing every
  `run_sabotage` label).
- Gmail (live): `car-watch` label = 0 messages; leboncoin's 5 car "vous propose" messages sit in
  plain INBOX (no filter routes them); AutoScout24 has sent **no real listing alert ever** (3
  messages: welcome, confirm-address, search-saved receipt — nothing per-listing).
  > **THE FIRST CLAUSE WENT STALE WITHIN THE DAY, and the way it misled is worth keeping.**
  > Re-measured 2026-08-31 evening: `car-watch/portails` is POPULATED — La Centrale twice daily,
  > Agorastore daily, AutoScout24's newsletters — and the first run of `tools/dump-eml.php`
  > searched INBOX, found neither, and printed `aucun message de <sender>`, which reads exactly
  > like a portal that has sent nothing. An alert routed to a label is ARCHIVED OUT of the inbox.
  > A mailbox census is a measurement with a folder in it; quoting one without the folder is the
  > same shape as quoting a yield without a date.

---

## Decisions Log (seeded — THIS file is now where rulings land)

> Supersedes the predecessor plan's persistence instruction to append rulings to
> `finish-everything.plan.md` / `car-domain-first-slice.plan.md` — those files are archived by
> Step U2. From now on, every ruling for pending work is appended HERE, in the same commit as the
> change it governs.

- [2026-08-31] AGREED: unification = archive + supersede — new plan at
  `docs/plans/scout-unified-execution.plan.md`; the 15 existing `docs/plans/*.plan.md` move to
  `docs/plans/archive/` with a SUPERSEDED banner each; reference docs (spec, SOURCES,
  OPEN-QUESTIONS, FILTERS, ALERT-CAPTURE, PHORJ-REQUIREMENTS, CLAUDE.md, README) are kept.
- [2026-08-31] AGREED: Track 1d brand mechanism = PENALTY (inverted), in-weights: a `brand` weight
  reserved out of the existing 100; listed brands score 0 of it, unlisted score all of it. Not
  `body_rank`'s shape (which would score listed brands higher). Details in 1d.
- [2026-09-01 12:20] AGREED: `brand_avoid` goes from 3 makes to **22** — the developer asked to
  penalise more makes ("i know those come with manufacture problems"), enumerated them, and ruled
  the full set: Stellantis' 14 marques (peugeot citroen ds opel vauxhall fiat abarth lancia alfa
  jeep dodge chrysler ram maserati) + ford and chevrolet (the developer's own, neither Stellantis)
  + the Renault-Nissan-Mitsubishi alliance (renault dacia nissan alpine mitsubishi, in because
  they share Renault mechanicals) + leapmotor (Stellantis-distributed). MECHANISM UNCHANGED — flat,
  equal, 10 of 100, still never a disqualifier. Reversed make by make in `config/car/criteria.json`.
- [2026-09-01 12:20] AGREED (same ruling, its enabling repair): an entry is a **STEM matched to a
  non-letter boundary**, not an exact string. Measured on the live store: the same marque is spelled
  `ds` by leboncoin and `ds automobiles` by autohero, and `in_array(..., true)` caught one and
  silently missed the other — a preference inert on a whole source, for 10 points of ordering, with
  nothing reading as a fault. Write the shortest unambiguous form (`alfa`, not `alfa romeo`): the
  gap runs both ways and a stem longer than the make misses just as quietly. Stated residual: a
  make written with no separator at all (`alfaromeo`) is not caught; under-matching is the safe
  direction and no source emits it.
- [2026-09-01] MEASURED, and it refutes what was predicted at the gate: widening the list makes the
  component MORE discriminating, not less. Re-judging all 160 stored snapshots through the real
  scorer — avoided median **58** vs unlisted **71** (13 points apart, against 9 for the previous
  three: 60 vs 69); 49 of 160 still clear `high_priority_score: 70`, so the marker stays reachable.
  60 % of the fleet is now penalised, which reads like a coin flip and is in fact close to the
  most discriminating split a binary component can take.
- [2026-08-31] DEFAULT APPLIED (not ruled): brand's 10 points are taken from `price` (25→20) and
  `body` (15→10) — the assembling session's allocation, not the developer's. Reversed by taking
  the 10 from different components before building 1d.
- [2026-08-31] DEFAULT APPLIED, and it DEPARTS from 1d's own body text: an UNEXTRACTED make scores
  **0 and says so**, not the full share. 1d's line ("a car with no extracted make gets the full
  share, hard rule 9") is the assembling session's elaboration — the Decisions Log entry above
  rules only the listed/unlisted split. Two reasons for the departure, both repo-native: hard rule 9
  forbids reading unknown as BELOW A MINIMUM, which is a *disqualifier*, and nothing here
  disqualifies (hard rule 8 keeps the mechanisms apart); and `VehicleScorer`'s own docblock already
  rules that every unknown component "scores 0 and SAYS so… a sparse listing ranks below a
  documented one" — the five sibling components all take that arm. Awarding the share would rank an
  EXTRACTION FAILURE as a definitely-not-Peugeot: a fact manufactured from its own absence, wearing
  an alibi, which is the defect this repo has paid for repeatedly. Both shipped car sources extract
  a make. Reversed by adding `$score += $w['brand'];` to that one arm in `VehicleScorer`.
- [2026-08-31] DEFAULT APPLIED: an EMPTY `brand_avoid` awards the share to every make, which is a
  different question and takes the opposite answer. That is not an unknown fact but a configured
  absence of preference, and withholding it would silently shrink the achievable maximum to 90 —
  while `high_priority_score` is an ABSOLUTE threshold, so the `!!` marker would become harder to
  reach for exactly the deployments that expressed no preference. Same failure shape the rent side
  measured on 2026-08-26.
- [2026-09-01 12:20] AGREED (Track 5b, F-C): Cityloger's surface selector accepts `m²` as well as
  `m2`. Not a hydration gap (61 cached details for 60 listings) and not a missing mapping — the
  regex required the ASCII unit and the source writes both. 56 of 61 rows carried no surface, so
  `min_surface_m2` could not act on 93 % of the source. The failing fixture was already committed;
  only the passing spelling was asserted.
- [2026-09-01 12:20] AGREED (Track 5b, F-D): rent `leboncoin` takes `feed_silent_days: 7`, matching
  the car side's ruling for the same portal. It does NOT silence today's verdict (the last message
  is 2026-08-26, already past 7 days) — it stops an ordinary bursty gap being called silence.
- [2026-09-01 12:20] AGREED (Track 5b, F-A): a `params` key no adapter reads is REFUSED — on
  `email_alert` sources ONLY. On `html`/`json` sources `params` IS the HTTP query string
  (`HtmlSource` does `withQuery([...params, ...extra])`), so In'li's `price_max`/`area_min`/
  `room_min` and Logirep's `ss_trnsctntp` cannot be enumerated and a blanket refusal would lock both
  polling sources out at startup. AND the cleanup was inverted: rent leboncoin's unread
  `subject_pattern` was not dead config but a documented guard that was never ARMED — rent and car
  leboncoin share sender, link host and card separator, and its own comment named the expiry as the
  day the Gmail filter is created. It is read now rather than deleted.
- [2026-09-01 12:20] AGREED (Track 5b, F-B): In'li's postcode comes from the detail page `<title>`,
  which states it beside the commune. Chosen over a commune→postcode table (needs an authoritative
  dataset, and French commune names repeat across departements while In'li lets outside IdF). It
  costs NO extra request — all 212 affected rows were already hydrated. Three earlier explanations
  were each refuted by the next query and are recorded in the commit so they are not retried.
- [2026-09-01 15:10] AGREED: the fuel weight goes 10→20 (−5 price, −5 mileage) and
  `high_priority_score` 70→73 with it. Diesel remains a PREFERENCE, never a disqualifier — the
  2026-08-30 ruling stands and diesels still appear. Measured over 269 snapshots: gap 16→25, best
  diesel 77→70. The marker had to move because it is ABSOLUTE; 78 was estimated from the
  petrol-only subset and was wrong.
- [2026-08-31] AGREED: PAP fix = patterns + health signal (Track 1h). No backfill of the 21
  null-extraction rows — stated cost, they stay null.
- [2026-08-31] AGREED: name-in-git-history = leave history; forward fix only (scrubber + re-scrub
  the two fixtures in a new commit). Rationale recorded: the same name is already the commit
  author on every commit in this repo.
- [2026-08-31] DEFAULT APPLIED: `config/car/criteria.json` `postcode_prefixes` → `[]` (explicit
  national scope; it is inert today anyway — no car source maps a postcode; confirmed
  `VehicleCriteria::matchesLocation()` treats `[]` as match-everything, and the car loader has no
  rent-style empty-filter refusal). Reversed by restoring the 8-department list in that one line.
- [2026-08-31] DEFAULT APPLIED: per-source `feed_silent_days` for new car sources — leboncoin-car
  **7** (observed cadence: one burst then 4+ days quiet; default 3 would false-alarm), La Centrale
  **3** (≈daily, default fine), Agorastore **3**. Reversed by editing the source's own
  `feed_silent_days` key.
- [2026-08-31] DEFAULT APPLIED: `.env.example`'s `CAR_*` keys stay COMMENTED until the car domain
  has run a full week (matches how the rent keys were activated only after real use). Reversed by
  uncommenting them.
- [2026-08-30] AGREED (predecessor, carried): today's new findings do NOT join `b8a1687`'s
  certification — they land as their own milestone afterward, so round 4 stays attributable.
- [2026-08-30] AGREED (predecessor, carried): diesel/ZFE — keep the existing fuel-preference
  weight as-is; no work item (recorded so it is not re-asked).
- [2026-08-30] AGREED (predecessor, carried): gate hooks/config are never edited; bypass
  *sentinels* via the sanctioned skill were authorised and are already in place.
- [2026-08-30] AGREED (predecessor, carried): Track 0 exit (close after round 4 vs run round 5 for
  the second clean) is decided WITH the developer after seeing round 4's verdict — via
  `AskUserQuestion`, never silently.
- [2026-08-31] ROUND 4 RAN AND IS **NOT CLEAN**: 19 findings across three lenses (2 × P0, both
  found INDEPENDENTLY by two lenses; 6 × P1; the rest P2/P3). All fixed in the round-4 fix commit.
  MAXIMAL's two-consecutive-clean counter is therefore **0**, and the close-vs-round-5 question
  above does not arise yet — the milestone re-freezes at the fix commit and round 5 runs against it.
  Full record: `var/claude/track0-round4.md`.
- [2026-08-31] DEFAULT APPLIED: the durable own reading is **PERMANENT**, and the docblock now says
  so instead of promising "until an explicit command". No command re-opens an excluded row
  (`staleVerdicts()` skips excluded, `pendingDigest()` skips non-DIGEST, `replay` writes no
  verdicts). Reversed by building such a command, which is a design question nobody has been asked;
  Q38's precedent is to state permanence outright rather than promise a route.
- [2026-08-31] DEFAULT APPLIED: Track 2 step 0's scrubber fix was pulled FORWARD into the Track 0
  fix commit, ahead of its planned place at the head of Track 1. Reason: the resilience lens found
  the leak as a live P1 (two committed fixtures carrying the subscriber's real name), so it stopped
  being preparatory work for future captures and became a defect in the tree. Reversed by nothing —
  the plan's sequencing rationale (1g/1h captures depend on it) is unaffected, it simply landed
  earlier.
- [2026-08-31] AGREED (developer ruling): **Track 0 CLOSES UNCERTIFIED.** Rounds 4/5/6 produced
  19/25/21 findings and never approached MAXIMAL's two-consecutive-clean bar; each round costs ~5 h
  and the other four tracks were untouched. Round 6's findings are closed, then Track 1 starts —
  round 7 is NOT run. What stands in place of the panel: the 588-case sabotage ledger, the full
  suite, drift-scan, and live `doctor` runs against the deployed image. Reversed by running a round.
- [2026-08-31] AGREED (developer ruling): track order for the remaining work is **1 → 2 → 3 → 4**
  (defect fixes, car sources, the audit, then the vehicle guard + store split).
- [2026-08-31] AGREED (developer ruling, re-confirmed): git history is LEFT AS IS for both fixture
  leaks (the name in 2 ParuVendu fixtures, the address in 3 Bien'ici ones). Forward fixes only; the
  blobs stay reachable by `git show <sha>:<path>` and hard rule 7 now states that cost. Reversed
  only by the developer's own force-push.
- [2026-08-31] DEFAULT APPLIED: the twin veto is now TRANSITIVE over a connected component of the
  twin graph. Stated cost: a chain A–B–C vetoes both ends even where A and C would not have been
  linked directly. §1's bias is toward not notifying and Q39 already prices pairwise over-linking as
  permanent; reversed by resolving only over direct edges (one `if` in the propagation sweep).
- [2026-08-31] FINDING RECORDED, NOT YET FIXED: the generic `ROOMS_PATTERN` matches hex inside
  photo-URL UUIDs (`…90F8-…` → 8 rooms). 14 wrong room counts on seloger, 6 of them notified; both
  directions do harm. New Track 1j — evidence in `var/claude/track1j-rooms-uuid-evidence.md`.
- [2026-08-31] FINDING RECORDED, NOT YET FIXED: PAP has THREE location shapes, not one, and all four
  of its positional params anchor on `\(\d{5}\)` — so on the department-only and Paris-arrondissement
  variants all four fail together. This is also the true cause of F6's "4 pap empty-title rows": one
  root cause, not two. Evidence and the measured replacement patterns:
  `var/claude/track1h-pap-evidence.md`.
- [2026-09-01] AGREED (developer instruction, verbatim intent): *"everything specific case we
  handled we need to check all sources/workflows to see if it needs to be generalized or in other
  sources too"*. Becomes **Track 5**, a standing rule as much as a task: no per-source fix lands
  again without its own row in the mechanism × source matrix saying which other sources were
  checked and what the answer was.
- [2026-09-01] AGREED (developer observation, then measured): a Bien'ici/SeLoger listing whose
  ADVERTISER is an institutional landlord is judged `LIBRE` at the source default, while the same
  flat on that landlord's own site is judged under `mixed_tenure: true`. **The verdict depends on
  the route, not on the flat** — a §1 breach. 24 stored rows, 21 pushed as MATCH, 18 with no twin.
  Fix = Track 5a. NOT a new classifier signal (the classifier reasons about tenure, not about who)
  and NOT a new reject path.
- [2026-09-01] AGREED: **AL'in goes on indefinite hold, like `src/phorj/`** (developer ruling).
  It is no longer an owed input; do not start it, do not re-propose it. `docs/SOURCES.md` A4 keeps
  the measurement. Reversed by the developer saying so.
- [2026-09-01] VERIFIED, input closed: the leboncoin `vous propose` Gmail filter EXISTS and works —
  all 5 messages carry `car-watch/portails` (`Label_809606989472151971`), confirmed over
  `in:anywhere` with no date or read-state restriction. The source is quiet, not broken: leboncoin
  has sent no car alert since 2026-08-27.
- [2026-09-01] INPUT PENDING: CapCar alert created by the developer; awaiting its first message.
- [2026-09-01] DEFAULT APPLIED: the advertiser is read from the **subject line** on SeLoger
  (`<ADVERTISER> vous adresse ses dernières exclusivités`, ~201 messages, one listing each).
  Anchored on the portal's own layout, never on a vocabulary scan of the body — the `au plus près` /
  filter-facet / CTA failure class has already cost this repo five fixes. Reversed by removing the
  source's `advertiser_pattern`.
- [2026-09-01] CORRECTION, same day, before any code: the count is **23 and all SeLoger**, not 24
  with one on Bien'ici. The extra row was the alias `i3f` matching inside a base64url JWT signature
  on a Century 21 listing — the sixth instance of the acronym-in-machine-text class, committed in
  the audit that found the bug. **Bien'ici has no advertiser surface and is uncovered**; the
  "advertiser is in the Bien'ici URL slug" claim was asserted from one example against a slug census
  already in evidence showing CRM vendors and agencies only.
- [2026-09-01] DEFAULT APPLIED: the landlord table is an **explicit registry pinned by a test**, not
  derived from `sources.json`. Derivation was the first design and the data refutes it — RIVP has no
  source block, *Immobilière 3F* lives under a block named `cityloger`, and *IN'LI* does not fold to
  `inli`. Reversed by deleting the registry and its pinning test together; never one without the
  other.
- [2026-09-01] DEFAULT APPLIED: a recognised advertiser with **no source block** inherits the
  fail-closed posture (`mixed_tenure: true`, `default_tenure: null`) — digest unless an explicit
  label decides. Guessing LIBRE for an unmeasured landlord is the §1-dangerous direction. Reversed
  per-landlord by giving it an explicit posture in the registry.
- [2026-09-01] AGREED: **Track 5b's matrix was measured and triaged; three items build and three are
  declined.** Report: `var/claude/track5-matrix-2026-09-01.md` (gitignored — its findings are
  restated below so the record survives it). Build T5B-1 (the extraction-miss signal on every
  adapter, both domains), T5B-9/F24 (a SeLoger title anchor that survives a card with no `pièces`
  line), and the register corrections. The developer challenged the first triage question for
  recommending one option without arguing the rest, and the re-ask carried a recommendation for
  every row — recorded because *"which do you recommend"* was the right correction to make.
- [2026-09-01] AGREED: **T5B-2 — no rescue route for rows rejected on an extraction since fixed.**
  Recorded as a stated cost instead. **The rescue has a shelf life the §1 risk does not**: the 32
  affected rows span 08-25 to 08-31 and `digest` already documents that the pipeline can only
  re-offer a listing while its ad is still published, so most would push dead flats — while
  re-judging on evidence the original verdict never saw is the breach `ListingSnapshot` exists to
  prevent. Once T5B-1 lands, the detection window is one pass rather than four days, at which point
  a MANUAL re-offer of a named set is sufficient and no standing machinery is warranted. **Stated
  cost: 32 rows stay rejected permanently, 4 of them SeLoger rows whose titles state a surface above
  the 50 m² floor.** Reversed by designing the route, keyed on *"the extraction changed"* rather
  than on *"the classifier improved"* — which is the one framing that may not carry the §1 risk.
- [2026-09-01] AGREED: **T5B-3 — `Core\Prose` does NOT learn `dernier étage`.** leboncoin states it
  on 3 of 3 cards, but it is a POSITION WITHOUT AN ORDINAL, so the reader recovers zero floors; and
  it cannot feed the high-floor penalty either, which requires an explicit `hasElevator = false` —
  and corrected per-line measurement finds `ascenseur` in **zero** card texts across all four
  portals' fixtures. A reading that changes no score is not worth the negation discipline it drags
  behind it. Reversed if a portal starts stating a numeric floor.
- [2026-09-01] AGREED: **T5B-7 — Logirep does NOT declare `prose_absent`.** It maps no `description`
  (120/120 null), so `exclude_patterns` runs on its title alone and the load guard would permit the
  declaration — but Logirep is an institutional landlord whose stock is not rooms or furnished
  lets, so a line on 123 rows saying *"colocation/meublé non vérifiables"* is how a caveat becomes
  the furniture its own counterweight test exists to prevent. Reversed by one key in
  `config/rent/sources.json`.
- [2026-09-01 ~22:30] RECORD: **Full audit run and banked** — four read-only auditors (rent store,
  car store, plan-vs-tree, config coherence) + a live Gmail census against HEAD `7a51ae3`.
  Synthesis: `var/claude/audit-2026-09-01.md`; raw evidence with every query:
  `var/claude/raw/audit-*-2026-09-01.md`. Zero closed register rows refuted; §1 store-wide sweep
  clean. Track 3 is DISCHARGED by this audit (census, reconciliation, unmatched senders,
  structural outliers — all four checklist parts per label/source are in the raw files).
- [2026-09-01 ~22:40] AGREED: **all four audit triage clusters are selected for execution**
  (A live-risk fixes, B new car sources, C hygiene + certification, D rulings) — see Track 6
  below. The developer executes via a different model; this session saved the plan and stopped.
- [2026-09-01 ~22:45] AGREED: **F30 — `advertiser_pattern` is DROPPED from the seloger block.**
  The template names no agency (Track 5b matrix: N/A; live: 375/405 miss), so the pattern asks for
  a field that does not exist, and a thin pass (truncated window, one agencyless 3-card alert)
  reaches the 100%-of-≥3 WARN spuriously. A pattern the template structurally cannot satisfy is
  not configured. Reversed by restoring the key — but capture a card that names an agency first.
- [2026-09-01 ~22:45] AGREED: **push grain — SCORE-FLOOR BATCHING.** Individual push only for
  score ≥ `high_priority_score` (rent 50 / car 73); every other match rolls into the existing
  digest path. Nothing is hidden — low scorers arrive rolled up instead of one-per-mail (~200
  rent + ~20–45 car mails/day today, 925 of 1141 rent-watch unread). AND, verbatim: *"we need to
  work more and make the score more realistic and beneficial to me!"* — a score-recalibration
  work item travels with this ruling (Track 6-A5): measure over stored snapshots, present 2–3
  calibrated options with their effect on the developer's own recent matches via AskUserQuestion
  (a user-facing product decision — the repo's mandatory-ask case), then apply the chosen one.
- [2026-09-01 ~22:45] AGREED: **F28 victims — ONE-SHOT READ-ONLY REPORT, no rescue mechanism.**
  Print the ~7 seloger rows REJECTed on misread surfaces (stored 5–9 m², titles stating 49–65 m²)
  with titles + links for manual review — the ads may still be live. NO store writes, no
  re-judging; the F28 ruling itself stands untouched.
- [2026-09-01 ~22:45] AGREED: **T5B-7 is SUPERSEDED — Logirep DOES declare `prose_absent`.** The
  earlier same-day decline (above) argued the caveat is furniture on an institutional landlord;
  the developer ruled to declare it after seeing the measurement (120/120 null description, 100%
  UNKNOWN tenure — the description-matching half of `exclude_patterns` structurally inert; titles
  still real and still scanned). Stated cost: the caveat line on every logirep push. Reversed by
  removing the key. Executor note: the load guard refuses the declaration only on a source that
  MAPS a description — logirep maps none, so it is permitted.
- [2026-09-01 ~22:45] RECORD (closing a log gap found by the audit): **`feed_silent_days` 7→5 for
  car leboncoin was superseded by `4b50e47`** (both leboncoin thresholds had sat past the
  IMAP_SINCE_DAYS window, so the ruled 7 was unobservable); the supersession previously existed
  only in that commit message. The stale `"7"` in the config's `_feed_silent_days` comment is
  Track 6-C1's to fix.

- [2026-09-01 ~23:30] AGREED: **Track 6 execution order is A2 → A1 → A3**, then A4, A7, B, C1 —
  the cheapest certain win first, then the live In'li degradation INVESTIGATED before anything is
  built for it, then the `ListingMapper` bundle. A6 waits for 2026-09-02 data by construction.
- [2026-09-01 ~23:30] AGREED, and it OVERRIDES the 2026-09-01 ~22:45 F30 ruling: **`advertiser_pattern`
  is KEPT and EXEMPTED from miss-counting**, not dropped. The drop was ruled before anyone traced
  what the key feeds. Measured this session: `advertiser_pattern` is read by
  `EmailAlertSource` → `RawListing`/`ListingSnapshot` → `LandlordRegistry` → `TenureClassifier`,
  and is the whole mechanism of `dede8ac` (Track 5a) — the fix that stopped 23 institutional-landlord
  rows on SeLoger (16 In'li, 7 CDC) being judged LIBRE at the portal's 50bp default, 21 of which had
  been pushed as MATCHes. Dropping the key sends those back to MATCH, which is a §1 regression, so
  the question is *how* to satisfy §1, never *whether*. The 92.6 % miss is not a template change and
  not a fault: the pattern reads the SUBJECT of the `exclusivités` template, and it is counted PER
  CARD, which is what turns 30 real hits into a 375/405 ratio. **The car domain already rules this
  exact shape** — `subject_pattern` is deliberately never counted, because a message-level pattern
  counted per card dilutes the ratio the WARN depends on. Same treatment here. Reversed by counting
  it again, which re-creates the spurious thin-pass WARN F30 identified.
- [2026-09-02] RECORD (Track 6-A1, audit N1): **In'li investigated before anything was built, and
  the finding is not the one the audit predicted.** The audit recommended "a failure-RATE signal in
  health"; `WARN_FLAKY` ALREADY EXISTS and already computes one. What is wrong is the TIMESCALE, and
  reading the rule before writing a replacement is what found it: the ratio is 30 % over
  `ROLLING_WINDOW_DAYS = 7`, and In'li measured 23.0 % over 24 h against **8.2 % over 7 days**, so
  the rule could not fire while the daily rate climbed 2.0 → 11.3 → 11.7 → 22.8 % across four days.
  **The window that makes a SUSTAINED fault visible is the window that hides a CLIMBING one** — the
  `STALE`/`FEED_SILENT` class, a correct rule blind on one axis of what it measures. Built: a SHORT
  window beside the long one (`FLAKY_SHORT_WINDOW_DAYS = 1`, ratio 0.2, `MIN_RUNS_FOR_SHORT_FLAKY =
  20`), same rows, same clock bound, long rule checked first so a sustained fault keeps the fuller
  statement, and the detail names which window fired. Ratio chosen against a COUNTERWEIGHT, not
  fitted: last-24 h failure rate over eleven sources in both domains was inli 23.0 %, cdc_habitat
  3.0 %, car leboncoin 1.0 %, the other eight 0.0 %. The minimum keeps a cron `--once` deployment
  (four passes/day, where one failure is 25 %) out of it, and the seven-day rule still covers those
  — asserted as its own test so the limit is not a hole. **Not built, deliberately:** no timeout
  raise (the only measurement is a 1.4 s quiet-hour TTFB; the anti-bandaid gate's named case) and no
  pacing change (failures track the site's daytime load, not our cadence — zero between 18:00 and
  05:00, and 26 of 46 are on the INDEX rather than deep pages). Evidence: `var/claude/track6-a1.md`.
  Stated cost: a source under 20 % today and under 30 % this week still reads `ok`. Reversed by
  removing the second check.
- [2026-09-02] RECORD (Track 6-A3, F27b + audit N5 + N6): **the miss signal reaches the four rent
  html/json sources; N5 is not reproducible and N6 is DECLINED.** `ListingMapper` is instrumented —
  the one funnel all four sources and `DetailHydrator` pass through, so `HtmlSource`,
  `HttpJsonSource` and `FixtureSource` implement `CountsPatternMisses` and both CLIs already gate
  their report on it. The hydrator SHARES the source's log, because a detail-map field that stops
  extracting is a fault of the SOURCE. **Only a CONFIGURED field can miss**, and that guard is the
  whole signal: logirep maps no `floor`/`elevator` and its 123 rows are 123/123 null on both, so
  without it every pass reports a permanent 100 % on fields nobody asked for — F30's shape on four
  sources at once. **Stated ceiling:** `total()` reports only `misses === calls`, and no configured
  field is near it (inli elevator 46 %, cdc 35 %, cityloger surface 16 %), so this catches the F1
  shape — a field going to zero everywhere at once — and is SILENT on a partial variant, cityloger's
  real 16 % surface defect included. A per-field opt-out was considered and NOT built: nothing needs
  one, and an inert config key is the class A2 made refusable the same evening.
  **N5 REFUTED AS STATED**: measured before writing a fix, `financement: PLS`, `regime: PLS` and
  `tenureField: PLS` all classify PLS at tier 1, and `financement: LLI` / `regime: LLI` both give
  LLI 97 — the unknown-field path already scans any value for excluded vocabulary. No verdict
  changes. What WAS fixed is the asymmetry: `tenure_field` acted on the html path (via
  `flatMapped()`'s literal-key renaming) and did nothing on json. Real, worth closing, and NOT the
  §1 hole the audit described — the register must not say it was.
  **N6 DECLINED on the measurement**: the 11 sub-400 € rents are correct readings — four cdc rows
  titled `Sous-sol` are cellars at 119–210 €, cityloger's seven are mostly `1 pièce(s)`. A 400 €
  floor nulls ten right answers to catch one suspicious `5 pièce(s)` at 290 €, and nulling does not
  reject (hard rule 9) — it makes the ceiling unverifiable, so the band LOSES information here. The
  email band exists because a prose reader assembles a plausible wrong figure from three numbers on
  a card; a mapped field is a declared price and fails differently. Price-per-m² is the shape that
  might work and needs its own study. Evidence: `var/claude/track6-a3.md`. Reversed by building it.
- [2026-09-02] RECORD (Track 6-A3, found by DEPLOYING it): **the card map and the detail map must
  count separately — pooling them hides a whole dead map.** The miss signal shipped, the image was
  rebuilt, and the FIRST live `doctor` reported `inli … cp 171/342`. In'li maps `cp` on both maps
  (card = URL slug, detail = page `<title>`, the `683a31b` fix), so 342 = 171 card + 171 detail —
  confirmed by `floor`/`elevator` reading `/171`, those being detail-only. `PatternMissLog::total()`
  speaks only at 100 %, so **a card pattern missing on all 171 cards averages with 171 detail
  successes into a silent 50 %**: one whole map dead, reported as half-working, WARN unreachable.
  The seven-day flaky window's dilution, one layer down, shipped the same evening by the same
  session. Fixed with a `$missPrefix` on `ListingMapper` (`'detail.'` from `DetailHydrator`).
  **The test goes through `HtmlSource` → `DetailHydrator`, not a hand-built mapper**, because the
  separation lives in the wiring — a mapper-level test keeps passing while the wiring is removed,
  which is the dead-safety-code shape. Its first draft asserted the card side missed on the shared
  fixture, which carries `.cp`, so it failed on a premise I assumed rather than measured; the card
  map now points at a selector the fixture genuinely lacks. Ledger case added; mutation verified
  detected (`3 is identical to 6` — the pooling exactly) and the restore verified byte-identical.
  **CORRECTION, and it must be recorded because the commit message cannot be: `2ab3245` describes
  the hazard as *"a card pattern missing on all 171 cards averaged with 171 detail successes"*, and
  that split is NOT established.** Measured afterwards over the store: **281 of 531 In'li URLs carry
  a 5-digit slug the card pattern can read (53 %)**, so the card map is not structurally dead, and
  `171/342` is merely CONSISTENT with one dead map rather than evidence of one. The real split is
  undetermined — which is the whole reason the pooling had to go, and the un-pooled report on the
  next deployed pass is what answers it. A true number with an assumed cause is this repo's named
  failure, and the reasoning for the FIX (pooling makes a 100 % failure on one side unreportable)
  stands on its own without that diagnosis.
  **Standing rule this leaves behind: a signal is not proven by its tests, only by its first live
  pass.** Reversed by dropping the prefix.
- [2026-09-02] AGREED + RESOLVED: **In'li changed its URL format, and the card `cp` pattern is
  REMOVED.** This settles what the `4968356` correction left open. The un-pooled report on the very
  next deployed pass said `cp 171/171 ← AUCUNE : gabarit changé ?` with `detail.cp` absent (0
  misses), and the cause is measured, not inferred: the old
  `/location-appartement-sceaux-92330/441-20013-2003` carried the postcode, the new
  `/locations/offre/paris/PRV-251653` carries only a commune slug. **0 of 190 rows seen that morning
  match the pattern**, and it CANNOT be repaired — the figure is not in the URL any more. The 281 of
  531 historical matches are older rows in the old format; two populations, to be read rather than
  reconciled. **So the miss signal caught a REAL portal template change on its first honest pass**,
  which is exactly the F1 shape it was built for and which the pooled counter had shown as a benign
  50 %. STATED COST (developer ruling): an unhydrated card now carries no postcode and
  `matchesCommune()` refuses a null postcode in region mode, so during a cold start, a detail-budget
  backlog or a hydration failure an In'li listing cannot match until hydrated — 0 recent rows
  affected, 38 historical rows unhydrated and postcode-less. Reversed by restoring a pattern, which
  needs the postcode to be back in the URL first. Config-only, so it is live through the bind mount
  without a rebuild (verified in-container). Commit `7f5e22b`.
- [2026-09-02] FINDING RECORDED, NOT A TASK: **49 In'li rows are hydrated AND have no postcode** —
  meaning the detail `<title>` pattern missed on them too. All 49 were last seen BEFORE today and
  the pattern missed on 0 of today's 171, so this is audit N9's residue: it self-heals on
  re-sighting, and F28 covers the rest. A second pattern question on the same source if it recurs.
- [2026-09-02] RECORD: **the executor stops here.** Eight code commits since `ac0cc1b`, two
  deploys, three developer rulings taken by `AskUserQuestion` (F30 exemption overriding the drop,
  A5's shape, the In'li `cp` removal). Certified by execution: suite **2648 / 10 307 assertions**,
  drift-scan exit 0, `test-sabotage-applies` 636/636 on `e9d1c96` (one expression retargeted after
  it reported INERT), `test-ci-workflow` OK, every `test-*.sh` guard OK, and all six new ledger
  cases individually detected with restores verified byte-identical.
  **UNCERTIFIED-BY-EXECUTION, stated in those words:** `tests/test-sabotage-baseline.sh` has not
  completed since `413f38e` (4/4 there; the copy list `src tests config phpunit.xml composer.json
  .env.example vendor` is unchanged, and later runs were killed by the harness mid-execution, not by
  a failure); the full 631-case ledger has not been run in this session (nightly's job); and **A1's
  short-window rule has never fired live** — In'li recovered overnight to 2.7 %, so the rule is not
  due. A dated prediction of what firing will look like is in `var/claude/track6-a1.md`, so tomorrow
  is checked against a record rather than a memory. Track 6-C2's MAXIMAL round is the REVIEWING
  session's, against a frozen commit.
- [2026-09-02] RECORD (Track 6-A6 CLOSED, audit N2): **the query is NON-ZERO, and the plan's
  predicted cause was WRONG.** The 09-02 row is real — `seloger`, stored surface **7 m²**, own card
  `3 pièces . 64,25 m²`, Orly 94310 — and it does not need the positional repair the rooms reader
  got. The generic `SURFACE_PATTERN` matched `7m2` INSIDE a base64url tracking token
  (`click.by.seloger.com/?qs=…zaw7m29jtx…`, offset 1029; the real figure at 1948). SEVENTH instance
  of *URLs are classified text*, and the SECOND poisoning of this same first-match-wins scan —
  Track 1j's `(?<![A-Za-z0-9])` anchor is blind to base64url, whose alphabet includes `-` and `_`.
  **The repair is a rule already ruled here**, not a new one: `RawListing::text()` already drops a
  URL's query and fragment and KEEPS its path before the classifier reads it (`?c=plai_plus` is a
  campaign string; a `plai` path segment is a real social signal). Same cut, one layer down, applied
  to the GENERIC readers only — a configured pattern owns its answer and pap is unchanged
  bit-for-bit, and the LINK readers never see it, because for seloger the whole URL *is* the query
  and stripping it there would empty every notification and re-key the link-identity portals.
  **Measured over 2 043 stored bodies before shipping**: surface 26 changed (all seloger, every one
  a recovery), rooms 4, rent 0, postcode 0, commune 0; inli 497, cdc_habitat 469, cityloger 60,
  bienici 316, leboncoin 3, pap 62 — 0 each.
- [2026-09-02] RECORD (Track 6-A6 DEPLOYED, and the realised cost MEASURED): image `0eab6f1…`
  built 09:41:37Z, both watchers recreated onto it and their image ids verified — `car-scout`
  wedged in F25's exact shape on the way (`Created` beside `Up 3 hours`, old container renamed)
  and cleared itself inside the 5-minute stop grace. **The first live pass proved it**: the same
  Orly card that was stored as 7 m² was re-read as **64,25** (rowid 2230 beside the old 2202) —
  *a pipeline fix is not done until the deployed watcher's first live pass says so*.
  **A CORRECTED CARD CHANGES IDENTITY**, because seloger is `id_from: content` and the surface is
  in the key, so a repaired extraction is a NEW listing rather than an update. That is the
  documented, accepted cost of content-addressing, and at 11:44:45 it was realised in both
  directions at once — **7 pushes, of which 2 are duplicates and 5 are new**:
  `EAUBONNE` (pushed 08-28 with NO surface, pushed again at 65 m²) and `F3 SAVIGNY SUR ORGE`
  (08-30, again at 58,84) are the duplicates, both from the *pushed-with-no-surface* group and both
  second pushes carrying strictly better information. **Four of the five new ones are named victims
  from A7's lost list** — `Appartement en RDC` 50,2, `T3 avec balcon … Brétigny` 65, `APARTMENT`
  83,75, `APPARTEMENT … ETRECHY` 61,49 — rescued and pushed at their true size.
  **That refutes this session's own prediction** that only the 09-01 and 09-02 victims could come
  back: the 7-day IMAP window still reaches 08-26, so 08-28's and 08-30's returned too. Predicting
  a window's reach is not measuring it. The remaining old rows keep their wrong surface for ever —
  nothing repairs a stored row in place. One observed shape left unexplained rather than guessed:
  3 seloger rows carry a `group_key` with a NULL `outcome` (81 carry one with an outcome), row 2230
  among them; consistent with a non-survivor cluster member being marked rather than judged, but
  not verified.
- [2026-09-02] RECORD (Track 6-A7 DELIVERED, F28): the backward-looking report is
  `var/claude/track6-a7-surface-victims.txt` — read-only, no store writes, and it is the SAME defect
  A6 just found rather than a separate one. Of the 26 misread rows: **7 LOST** (they clear every
  numeric filter and every shipped exclusion at their true size and were never notified), 6 pushed
  with no surface at all, 4 room rentals the exclusions catch regardless, 9 that fail the ceiling or
  the floor at the true figure too. A first count said 8 and 5; it compared an int snapshot value
  with a float reader result using `===`, which reports 61 and 61.0 as different surfaces and
  inflated the total to 420. **Nothing is retracted and nothing is repaired in place** — the ruling
  stands that only a report looks backward — and a row self-heals only if its alert is still inside
  the IMAP window, which for the 25–28 August victims it is not.
- [2026-09-02] RECORD (Track 6-A6, audit N2): **SUPERSEDED by the entry above — premature when written.** The
  plan's own query filters `first_seen_at >= '2026-09-02'` and the newest row in the store is
  `2026-09-01T20:50:49Z`, so it returns nothing on every source. Against the DEPLOY boundary
  instead (image `2026-09-01T19:25:06Z`): all 14 tiny-surface seloger rows are pre-deploy, zero
  post, and the 8 post-deploy rows all state a surface, min 49.15 m². **n = 8 — a direction, not a
  verdict.** N2 stays open; the exact query is in `var/claude/track6-a6.md`. Note there is no
  `surface_m2` column: the figure lives in the v7 snapshot at `evidence_json -> $.surfaceM2`.
- [2026-09-02] RECORD (Track 6-A3 recon): **cityloger's null surfaces are a selector SCOPE miss,
  settled by ONE fetch.** The page states `65 m2` on line 221 in the icon-feature list; the
  `detail_map.surface` selector scopes to `div.tab-content`, which opens on line 268. Not an
  omission, and not the `m²`/`m2` spelling `7d882f3` fixed. 51 of 61 rows extract fine, so these two
  are a template variant. **Fix is a SEPARATE change** (developer ruling): add the second selector
  on its own and trial it over all 61 stored rows first — a widened scope altering any of the 51
  working extractions is a regression wearing a fix's clothes (the F24 precedent: 617 unchanged, 2
  gained, 0 lost). Evidence: `var/claude/track6-a3-cityloger.md`.
- [2026-09-01 ~23:30] AGREED (A5 shape): **a second queue, ONE drain, separated in the mail.**
  Low-score-but-tenure-clean matches queue on their own and drain through the existing
  `Cli/DigestBatch`, with the formatter keeping *"vérifié, score bas"* visibly apart from
  *"à vérifier"* — the rent digest already MEANS tenure-doubt, and reusing that kind for a
  score decision would misreport a listing's §1 status (schema v8's `DIGEST < MATCH` is monotone
  and never demotes). **Executor note, measured: the car domain has NO digest at all** — by design
  (`VehicleOutcome`: *"there is no mixed-stock digest in this domain"*), so the plan's "do NOT build
  a second drain" is rent-only advice and the car half is a NEW rollup that must not be called a
  digest. Reversed by pushing every match individually again.
- [2026-09-02] AGREED (C2 freeze point, re-asked): **A4 + C1 land, then freeze for the MAXIMAL
  round.** The order in this file said freeze *after* Track 6-A and C1; the 2026-09-02 handback
  reserved A4 onward for the reviewing session so the freeze point would stop moving. Those
  conflicted and the developer settled it. **C1 was never the choice** — it is a PREREQUISITE: the
  completeness lens checks claims against the tree, the register disagrees with the tree on eight
  F-rows plus five State/Where cells and two stale id citations, and under MAXIMAL every one of
  those non-defect findings resets the two-consecutive-clean counter. The only real question was
  whether A4 rides along, and it does: it is small, it is ruled next, it closes cluster A, and
  certifying it in this round is cheaper than the separate round it would otherwise need (the
  economize ruling's own logic — one panel for one backlog). **The span is bigger than this file
  says**: `7765997..HEAD` is **57 commits, 37 touching `src`/`config`/`tests`**, not the "45+"
  written in C2, and it is simultaneously Track 1's and Track 4's never-run rounds. Deferred past
  the freeze: A5 and B1-B3. Reversed by freezing earlier or by adding another item before it.
  **The re-ask is itself the record of a correction**: the first question named a recommendation
  and left the other rows unargued, which is the developer's 2026-09-01 challenge repeated; the
  second argued every row and named the deciding fact.
- [2026-09-02] RECORD (Track 6-A4 CLOSED): **built as sentinel-to-null, NOT the title read the
  plan preferred — and the premise that justified the title read is REFUTED.** ParuVendu writes
  `/voiture-occasion/autres/autres/` when it cannot name the marque; the capture succeeds, so
  `autres` was stored as the make, matched no `brand_avoid` stem, and earned the whole 10-point
  brand share on `Ds Ds4 E-tense 225ch Performance Line` — a **DS**, which IS on the avoid list.
  Not the unknown-make arm: a wrong answer wearing one. **Audit N3 preferred reading the make from
  the title because nulling would leave the car with "still full share by hard rule 9". It does
  not.** `VehicleScorer`'s `make === null` arm scores **0** and says `marque inconnue — hors score`
  — a deliberate, documented deviation from this plan's own line, and its docblock even said
  *"both shipped car sources do extract a make"*, which is what made the finding mis-scoped when it
  was raised. So both mechanisms give this car brand 0; they differ ONLY for a non-avoided make
  hiding behind a sentinel, **never once observed**. Building the title read for that case would be
  the n=1 generalisation this repo keeps paying for, in exactly the fallback shape
  `make_model_source`'s own docblock refuses — and on the measurably worse haystack: over all 108
  stored ParuVendu rows the title's first word is the make **101 times**, the seven misses starting
  with the model (`Captur (2) Techno Tce 90`, `3 1.2 Puretech 130`) or mangled (`Il-peugeot 208`),
  and one `citroen` path is titled `Ds Ds 7`. **Measured before shipping:** 1 of 108 paruvendu rows
  carries the token, 107 unchanged; autohero (261 rows) and car leboncoin (5) have no sentinel at
  all. Shipped as a per-source `make_model_unknown_pattern` (anchored — an unanchored `~autres~`
  would null a real marque containing the word), refused EMPTY and refused without
  `make_model_pattern`, and **deliberately never counted as an extraction miss**: the pattern HIT,
  the portal declared an unknown, and counting it would put every correctly-read card in the
  denominator — F30's shape, the `subject_pattern` ruling read the other way round. That
  subtraction from `VehicleEmailPatternMissTest`'s reflection guard has its own counterweight
  assertion, because a `missed()` call added later would pass the reflection test. Three ledger
  cases. Reversed by building the title read, which a non-avoided make behind a sentinel would
  justify and nothing has yet.
- [2026-09-01] AGREED (`prose_absent`, brought under this file by Track 6-C1 on 2026-09-02 — it
  had lived only in `pap-detail-hydration.plan.md`, a SECOND live plan, which is the single-source
  breach audit N7 raised): **PAP declares `prose_absent: true` and the push says its colocation and
  meublé filters cannot fire.** The alert carries no listing prose at all — `description` is the
  literal `PAP.fr  De Particulier à Particulier ____` on all 57 stored rows and `title` is
  `Location appartement`/`Location maison` on every one — so `exclude_patterns` and
  `exclude_title_patterns` have nothing to scan, while a room in a shared flat is advertised with
  the WHOLE flat's room count and surface and clears every numeric filter. **Both routes out are
  MEASURED and closed**: detail hydration is refused by hard rule 5 (three probes spaced 45 s apart
  all answer a Cloudflare challenge, including a URL that returned a clean 301 four minutes
  earlier — reputation-based, not transient; the A15 precedent, and NOT to be revisited with a
  headless browser or a wait-and-retry), and rent-per-room has no gap to cut at (the low tail runs
  63, 71, 78, 84, 85, 90, 90, 91, 92, 92, 93, 94, 97×3, 98×3, 100×4 upward and the motivating
  colocation sits at **130**, inside the densest part — the same negative Track 1f reached on a
  different statistic, which says the problem is the EVIDENCE). So it REJECTS nothing and SCORES
  nothing (hard rule 8): it names the boundary of the judgement. Refused at load on a source that
  maps a `description`, outside the `enabled` branch because `--source=` force-runs disabled
  sources. The counterweight is load-bearing — a caveat on every push everywhere is furniture — so
  the sabotage ledger pins both halves. Measurements:
  `docs/plans/archive/pap-detail-hydration.plan.md`.
- [2026-09-02] RECORD (Track 6-C1 DONE): **the register's reverse drift is closed, and TWO of C1's
  own list items were themselves stale.** Nine rows corrected against the tree rather than
  relabelled — F1 (re-measured: **0 null communes every day 08-28 to 09-02**, against the cell's
  "23 null rows"), F5, F10, F15, F16, F17, F19, F24 and **F30, which C1's list did not name at
  all** and which had read OPEN since the exemption shipped hours earlier. Stale by contrast: the
  `"7"` in car leboncoin's `_feed_silent_days` comment was **already corrected on 2026-09-01** and
  reads `5, not 7`, so that item was discharged before C1 ran. Also fixed: the two pre-collision id
  citations (`F10/F11` -> **F23/F24**; `3 seloger + 4 pap` -> F6's own re-measure, **2 seloger + 1
  In'li, 0 pap**), C2's span (**58 commits, 38 touching code** at `0ae6cd0`, not "45+"; **59/39** once this
  commit lands, which is not docs-only),
  Inputs-owed #6 (SPENT — the live car file carries `run_meta` v1 and no rent tables), and
  `pap-detail-hydration.plan.md` archived under a SUPERSEDED banner with its ruling copied above.
  **The verification is the part worth keeping**: a first pass grepped the config for `coliv` to
  check F5, found nothing, and would have left the row OPEN — the shipped
  `co[\s-]?living` defeats a substring grep. Check the pattern, not a fragment of it, and verify
  the check as well as the claim.
- [2026-09-02] RECORD (Track 6-A4 DEPLOYED, and the realised cost MEASURED): image
  `fd8ab0f7249e…` built 12:33:31Z, both watchers recreated onto it ONE AT A TIME (F25) with image
  ids verified and no wedge. Two car passes at 14:29 and 14:38 — all three sources `ok`, paruvendu
  111 items — and **zero `autres` makes remain in the store**. The DS4 row healed **in place**:
  `make` and `model` are now NULL and the score fell **15 → 5**, exactly the 10-point brand share
  it should never have had. **No re-notification, and that is the contrast worth keeping against
  A6 the day before:** ParuVendu's identity is the real ad id, so a corrected field UPDATES the
  row, while SeLoger is `id_from: content` **with the surface IN the key**, so A6's corrections
  re-keyed and cost 2 duplicate pushes. **The rule is NOT "content-addressed re-keys, link-keyed
  does not"** — that is the tempting generalisation and it is wrong: Bien'ici and leboncoin are
  link-keyed, and a correction to `linksIn()` would re-key their whole backlog just as thoroughly.
  The question is only ever **whether the field being corrected is part of that source's identity**.
  Here it is not (the make is not in an ad id), on A6 it was. **Ask that before predicting what a
  correction costs.** `last_seen_at` correctly did NOT move (2026-08-29T10:05:12Z): for
  an email listing that is the MESSAGE date, per the 2026-08-29 `observedAt` fix, not the pass time.
  Rollback is one retag away: `scout:pre-a4`.
- [2026-09-02] AGREED: **C2 is HANDED BACK — the reviewing session runs it, not this one.** The
  freeze is set at `ede198e` and holds (`git log --oneline ede198e..HEAD -- src config tests` is
  empty; every commit after it is docs-only). The alternative offered was for this session to spawn
  the three lenses immediately against the frozen commit, and it was declined for the reason the
  ladder exists: a MAXIMAL round run by the session that wrote `0ae6cd0` and `ede198e` certifies its
  own work, and two of the span's commits are today's. **Deferred past the panel by the same
  2026-09-02 ruling: A5, B1, B2, B3.** Nothing else is in flight.
- [2026-09-02 16:40] RULING (Track 6-C2): **the handback above is SUPERSEDED by the developer, and
  this session runs C2 after all.** Asked again at the start of a fresh, compacted context, the
  developer chose *"Run C2 here"* over building B1 or A5. The objection the earlier ruling raised —
  an author certifying its own 39 commits — is answered by the ladder's own mechanism rather than
  waived: the three lenses are **unnamed fresh-context subagents** that read the diff, the code and
  the tests themselves in their own pinned worktrees, and are chartered to REFUTE. The briefing
  carried that risk explicitly by phrasing every scope item as *a commit and a claim to refute*,
  never as this session's conclusion.
- [2026-09-02 17:05] RECORD (C2 ROUND 1 — **NOT CLEAN: 2 P0, 4 P1, 9 P2**, plus 5 P3 that do not
  reset). Three lenses, each in its own worktree pinned at `ede198e`, run concurrently; all three
  removed their worktrees and the live tree stayed clean throughout. Reports:
  `var/claude/c2-round1-{correctness,resilience,completeness}.md`. **The two-clean counter is at
  zero**, so a clean round 2 still leaves round 3 owed.

  **The reset rule was stated in the briefing rather than left to each charter**, because on a
  16 000-line span an unstated threshold makes convergence impossible: P0/P1/P2 reset, P3
  style/wording notes are reported separately and do not. That matches the charters' own taxonomy.

  Four decisions shaped the briefing and are worth keeping:

  - **Scope was given as COMMITS, never as this session's conclusions.** Where this session had a
    verdict — A4's refuted premise, C1's nine relabelled register rows — the prompt named the commit
    and the claim and asked the lens to refute it. Author bias enters through the prompt, not the
    diff. It paid: the correctness lens **verified** `VehicleScorer`'s null arm scores 0 rather than
    taking A4's word, and confirmed `9ea9d77`'s link-reader boundary by byte-identical `dump` output
    either side of the commit — the risk that would have re-keyed every link-keyed backlog.
  - **The CAR §1 surface was put in the correctness lens's scope explicitly.** That charter is
    written for French housing tenure and predates `src/php/Car/`; `VehicleClassifier`'s
    non-overridable excluded set is §1's twin, and without saying so it would have gone unreviewed
    across eight commits.
  - **Four local hazards were briefed**, all previously paid for: `difft` makes `git diff` silently
    empty, the tracing JIT kills the ledger at exit 134, `/bin/grep` is ugrep so a `\|` in
    `SABOTAGE_FILTER` yields a green PARTIAL RUN, and a `MAILBOX_DIR=` proof without a throwaway DB
    writes into the live store (F26).
  - **Each lens had to end with what it did NOT read and did NOT execute.** With 88 files a clean
    verdict is cheap; that list is what makes one worth anything — and it is what routes round 2.
    Between them the three left `Core/RunStore.php` (+950) unopened, `LandlordRegistry.php` unread by
    resilience, 39 of ~41 test files unread by resilience, and `test-sabotage-applies.sh` unfinished
    by correctness. Round 2 gets those by name.

  **One claim this session had made is downgraded by the round, in the safe direction.** A1's
  short-window rate rule was declared UNCERTIFIED-BY-EXECUTION; the resilience lens executed its
  alert path end to end — traced to `markAlerted()`, counterweight confirmed read-only against BOTH
  live stores (max 2.9 % against a 20 % threshold), all three ledger cases run 3 detected / 0
  undetected, and production `source_alerts` rows proving the send half. It is now certified as far
  as it can be short of a live firing, which has still not happened.
- [2026-09-02 18:20] RECORD (C2 round-1 **F2 CLOSED**, §1 config key): `card_separator_pattern` — the
  regex form of the segmenter, added in this span and honoured IN PREFERENCE to the literal — walked
  past both `card_separator` load-time refusals, one of whose own message calls its subject *"une
  décision §1 que personne n'a prise"*. All 16 occurrences in the tests and the ledger were the
  literal, so both guards could be defeated by a config change with everything green. Both now read
  either key. Two details beyond the report: **the refusal names the key the file actually carries**,
  because an error pointing at `card_separator` on a config that sets the pattern form sends the
  operator to a line that is not there; and **the existing ledger case went inert the moment the
  condition changed** — retargeted in the same commit rather than left for
  `test-sabotage-applies.sh`, which is the `4d49eda` failure class exactly. Three cases now cover it,
  and the two new ones are **one per guard** rather than one mutation of the shared `$segmented`:
  a single mutation reddens both new tests at once, so deleting either test would leave the case
  still detected. **4 detected, 0 undetected**, both baselines green.
- [2026-09-02 18:40] RECORD (C2 round-1 **F1 CLOSED**, §1 P0 — the re-advertised flat): the veto
  travelled three ways and **all three need an EDGE** — a persisted cluster `group_key`, a twin in the
  pass's harvest, or the row's own previous reading. A portal re-advertising an excluded flat under a
  NEW AD ID has none of them: a new `external_id` is a fresh row, and `Dedup` refuses a same-source
  edge because the source's own id is authoritative. **That refusal is right about IDENTITY and was
  being applied to the VETO**, which is a fact about the DWELLING. Reachable on `bienici`, `leboncoin`
  and `pap`; not on `seloger`, which is content-keyed.

  Shipped as a FOURTH route that needs no edge: `Store::excludedDwellings()` decodes the v7 snapshot
  of every row holding an excluded tenure (43 of 2 331 in the live store, so one indexed scan and a
  few dozen JSON decodes, loaded ONCE per pass), and `Pipeline::storedDwellingClassification()`
  compares each survivor against them through `Dedup::sameDwellingReason()` — the SAME
  positive-evidence bar as `duplicateReason()` and `twinReason()`, with only the source and family
  test dropped.

  Five decisions travel with it, and one was forced by evidence rather than chosen:

  - **It runs LAST, after `twinClassification()`, and the order is load-bearing.** Placed before it,
    the new veto pre-empted the old one — `twinClassification()` early-returns on an already excluded
    survivor, so `recordTwin()` never fired and the cross-track fact stopped being PERSISTED on every
    row this veto happened to catch first. **Three existing twin-durability tests went red and said
    so.** A veto that silently disables another veto's recording is a net loss: this one is derived
    from what is on disk, while the twin fact is what PUTS the other track's reading there.
  - **It is the row's OWN judged verdict, never a twin fact.** `recordTwin()` would persist a source
    name and `scout digest` would then announce *"relevé via bienici (autre voie)"* about a bienici
    row. A same-source copy is not another route.
  - **EXCLUDED ONLY — propagating `UNKNOWN` this way is an explicit NON-GOAL**, recorded here so a
    later round does not file it as an omission. A doubt spread by content match against the whole
    store is the In'li cost, *not §1 satisfied, the tool switched off*.
  - **STATED COST, the Gros Saule shape:** two genuinely different flats in one residence agreeing on
    commune, rooms, surface and rent within tolerance are one dwelling to the bar, so if either is
    excluded both are rejected permanently. Q39 already prices pairwise over-linking as a permanent
    rejection in this direction. **And a row with NO SNAPSHOT is not vetoed** — a pre-v7 row (never
    backfilled, by ruling) or one whose payload could not be encoded. That is the unsafe direction,
    stated rather than hidden; it decays, and the alternative — comparing on the four flat columns
    `listings` happens to carry — would merge on the ABSENCE of a difference.
  - **A corrupt snapshot is SKIPPED here, not thrown**, a deliberate departure from `evidence()`.
    There the row is the one being re-judged; here it is one candidate among many, and throwing would
    take the whole pass down — every source, every listing — turning one bad row into a total outage.

  **BOTH JUDGING ORDERS ARE ASSERTED**, because `Core\Pacer` shuffles the harvest: a fix that works
  when the vetoed row happens to be judged first works half the time, and survivorship following the
  harvest order is how the round-4 durable-reading defect escaped for a day.

  **The tests assert the MECHANISM, not the silence.** A first version checked only that no MATCH was
  sent, which PHPUnit reported as *"did not perform any assertions"* — and would have stayed green
  with the veto deleted and something else broken. They now assert the stored `tenure` of the
  re-advertised row.

  **ONE EXISTING TEST'S FIXTURE WAS WRONG, and the evidence is here rather than in a quiet edit.**
  `testEveryJudgedOutcomeIsRecordedWhicheverWayItWent` built its three listings from the same helper,
  so all three stated Sartrouville, 4 rooms, 88 m² and 1450 € — **one dwelling advertised three
  times, one copy saying `PLAI`** — and the new veto correctly rejected all three. The rejection was
  right and the fixture was wrong: the test's guarantee is that `recordOutcome()` runs before the
  REJECT and DIGEST branches, which needs three outcomes and says nothing about them sharing a flat.
  Varying rooms, surface and rent restores the guarantee without a §1 veto standing in the middle of
  it. **This is §1's clause on weakening a fixture being satisfied, not waived.**

  Suite **2 672 tests / 10 373 assertions** green. Three ledger cases: the veto never firing, the
  candidate set coming back empty (the shape a performance edit takes — present, called, permanently
  silent), and the **counterweight**, ignoring the positive-evidence bar so every stored exclusion
  vetoes every listing.
- [2026-09-03] AGREED (four rulings taken at `/goal-brief`, in one round): **(1) A3 half 3 — the rent
  plausibility band on mapped rents — is DEFERRED past the panel**, with A5 and B1–B3. It is genuinely
  open (verified: the band lives in `EmailAlertSource`, and neither `ListingMapper` nor `Payload`
  carries one), so the 16:40 *"nothing else is in flight"* line was wrong about the tree rather than
  about the intent; deferring makes it true. The freeze therefore re-sets on the P2 batch alone and
  the reviewed span stops growing. **(2) The four `Deep —` status rows ARE plan work** — the car
  `MalformedText` arms with no sabotage case, the field-map regex with no load-time compile check, the
  doc drift, and the stale track sections — accepted into the goal rather than left as sweep output,
  so the plan does not complete until they land. **(3) F5 — a persisted UNDETERMINED twin doubt is
  cleared by POSITIVE EVIDENCE ONLY.** A tier-5 source default may never overwrite a recorded `UNKNOWN`
  twin; only a tier 1–4 signal can. The refutation was a third route's In'li default `LLI` at 50bp
  erasing a doubt a mixed-stock landlord had raised (`[pass1] twin='UNKNOWN'` → `[pass2] twin='LLI'`,
  matches 0→2), and `CLAUDE.md` itself records In'li as proven **not** pure LLI — so the erasing signal
  was the weakest kind there is. This is §1's safe direction and it is narrower than *"only the
  doubting route may clear it"*: evidence still clears, absence no longer does. **(4) The goal brief
  covers 100 % of the project**, not this milestone — developer's words, verbatim: *"i want the goal
  brief to the 100 % of everything ! not just this milestone or recent work !!"*. So `A5`/`B1`–`B3`
  are in THIS plan, and the done-when spans the product, the two on-hold tracks and the register.

- [2026-09-04 09:30] RECORD (**REDEPLOYED FIRST**, before any P2 work): the deployed image was
  `2026-09-02T12:33:31Z` and the newest `src/` commit `581cbce` was `2026-09-02T17:41:52Z`, so the
  §1 P0 from round 1 — `3eca42f`, the flat the store holds as PLS being vetoed when the portal
  re-advertises it under a new ad id — was **certified and unarmed in production for a day and a
  half**, reachable on `bienici`, `leboncoin` and `pap`, the three link-keyed portals where a
  re-advertisement mints a new `external_id`. Rebuilt, backed the seen-set up, rehearsed the
  migration against a `.backup` copy (rent v12/WAL, unchanged), stopped, redeployed; image now
  `2026-09-04T07:28:46Z`, both watchers up. **This is the third time the *green, pushed and
  deployed are three different things* entry has been paid for**, and the interval was found by
  the goal-brief's own stop condition rather than by anything in the tree.

- [2026-09-04 10:05] RECORD (**C2 round-1 P2 batch — 8 of the 9 findings closed**; only COR-F5, the
  twin doubt, remains). Each carries its own executable evidence and, where the guarantee is silent,
  its own ledger case. Suite **2691 tests / 10 435 assertions** (from 2679),
  `test-sabotage-applies` **656 expressions, all still applying** (from 651), drift
  `P0=0 P1=0 P2=0`, every guard green.

  - **COR-F4** — the surface reader had no LEFT anchor, so a digit mid-token beat the real figure
    below it. **The lens graded this its softest finding, on the grounds that no real payload
    carries `m2` in a path, and the store says otherwise.** Trialled through the real `prose()`
    over all 2 579 stored evidence bodies: **4 rows change, every one a recovery**, and the poison
    is Bien'ici's own photo host `d2m2j20yzublln.cloudfront.net` — `2m2` reads as 2 m². Four flats
    of 41, 54, 65 and 59 m² are held at **2 m²**, so three were silently rejected by
    `min_surface_m2: 50`. The distribution id is on every photo URL from that CDN, so it is
    structural rather than four unlucky cards. **The measurement was almost recorded nine times too
    large**: against the RAW body it changes 41 rows, but 37 are SeLoger tokens `9ea9d77`'s query
    strip already closed — measuring a repair against the pre-repair baseline is this repo's own
    *true number attached to an invented cause*, caught before it was written down.
  - **COR-F3** — the *à vérifier* rollup title spoke for every entry while the bin had grown a
    second entrance. A listing digested for an implausible rent-to-surface ratio is typically `LLI`
    at full confidence, so announcing it *au régime indéterminé* asserts as doubtful a regime the
    classifier settled. New `Core/DigestCause`, with **no default on `Verdict::digest()`** so a
    future third route cannot inherit the §1 clause by omission; the clause is earned by every
    entry or by none. The drain reads the cause off the STORED tenure (`pendingDigest()` now selects
    it) rather than re-forming a verdict — `OTHER`, never naming the price branch, because the store
    records that a row is not a tenure doubt and nothing about which route it was.
  - **CMP-1** — the ntfy badge test used SINGLE-quoted `\u{}`, so it asserted a 21-byte ASCII
    stand-in no production path can emit. Now double-quoted, and a ledger case strips non-ASCII in
    `headerSafe()`: before the fix that sabotage was undetectable, because an ASCII stand-in
    survives any header sanitiser.
  - **F-R2 + F-R3 + CMP-2 (P2-3)** — `tools/dump-eml.php`, the tool that had no docs and no test.
    Its "never under `tests/`" guard concatenated `realpath()`'s `false`, so `var/claude/captures`
    on a tree without `var/claude` compared as the literal `/captures`: **vacuous on its own default
    out-dir**. It now walks up to the deepest existing ancestor, folds `.` and `..`, and compares as
    a prefix with its separator, so the bare `tests` and `tests/` are refused like anything beneath
    it; an unresolvable destination is refused rather than guessed. And LOGIN is sent by a
    **zero-argument closure**: PHP prints the first 15 characters of every call argument in an
    uncaught trace, and nothing leaked only because the real `IMAP_USER` is long enough to eat the
    budget first. `tests/test-dump-eml.sh` (17 checks) proves the trace mechanism on this machine's
    own PHP first — an argument leaks, a `use` binding does not — then ties the tool to it.
    **Its own docblock tripped its own structural grep**, which is why that check strips comment
    lines: the documentation of a guarantee must not read as its violation.
  - **CMP-2** — the frozen In'li payload was the OLD template, so the 2026-09-02 removal of the
    card `cp` had **no coverage in either direction**: restoring `a@href => -(\d{5})/` extracts
    cleanly from that capture and the whole suite stays green. A removal nothing can redden is a
    removal that gets undone. Captured the current page (robots re-read first — unchanged,
    `Disallow: /espace-membre/` only — HTTP 200, In'li's live Google Maps key replaced with the
    documented placeholder before the file was written) and **APPENDED it** as
    `search-2026-09-04-nouveau-gabarit.html`, following the PAP `nouveau-gabarit` precedent rather
    than substituting: every existing assertion keeps its ground truth and the old template stays
    available as the thing the new one is contrasted against. Measured on the two files: the old
    carries **19** postcode-bearing hrefs and **0** of the new shape, the current **0** and **24**.
    `InliCurrentTemplateTest` pins the contrast, the shipped map's empty `postcode`, the detail map
    as its replacement, and the stated cost — an unhydrated card carries no postcode, so in region
    mode it cannot match until hydrated. **It deliberately asserts no card COUNT**: a live search
    page's inventory changes daily, and an exact count reddens on a quiet Tuesday rather than on a
    defect.
  - **CMP-4** — F19's row cited two line numbers that had moved twice in a fortnight. Re-cited by
    SYMBOL, and the row now carries the drift as its own record.
  - **F18** — the method warning about grep is a mechanism now, and the guard's own `u`-modifier
    hole is closed with it. See the register row.
  - **THE LEDGER EARNED ITS KEEP ON THIS BATCH, AND THE WAY IT DID IS THE POINT.** The drain case
    was written with a closing parenthesis missing (`$cause = (true` for `$cause = (true)`), so the
    mutation did not parse and the ledger reported *"this proves nothing either way"* rather than
    counting it undetected. `test-sabotage-applies` cannot catch that — it checks an expression
    still MATCHES, never that its output compiles. **Fixed, the case then came back UNDETECTED: the
    suite stayed green with every stored row called a tenure doubt.** `DigestTitleTest` hands the
    formatter verdicts built by hand, so nothing exercised the drain deriving the cause from the
    STORED tenure — a real gap in this batch's own work, invisible to 2 691 green tests, found
    because a mutation that could not run was fixed rather than dropped.
    `RentScoutDigestTest::testTheRollupDropsTheRegimeClauseWhenARowsTenureWasDetermined` closes it,
    with its counterweight beside it.

    Second lesson, smaller: **a case that times out is indistinguishable from one that is
    undetected**, and on this shared box that is the commoner cause. An unrelated project's PHPUnit
    run pushed the load average to **27** while this ran, and the `headerSafe` case sat at 256 s of
    a 300 s budget; it was detected on a longer one. Check `uptime` before believing a lone timeout.
  - **Deep row 32** — a field-map `=> regex` capture had no load-time compile check, while every
    `params` pattern has had one since round 1. `Selector::captureFrom()` applies it with
    `@preg_match`, so a broken pattern nulls the field for every item on every pass while the source
    returns its usual count and `SourceHealth` stays green — the In'li `cp` shape. A broken pattern
    is a STATE, so it throws at load. **Checked for the symmetric surface this repo keeps paying
    for**: the car field map is plain dotted paths with no captures at all, and
    `VehicleSourceLoader` already compile-checks its patterns and `item_url_pattern`, so there is no
    second surface here — verified rather than assumed.

- [2026-09-04 10:10] RECORD (B1–B3 inputs **verified present**, so those rows are buildable rather
  than waiting): `tools/dump-eml.php` against the live mailbox returns three recent alerts for each
  of `contact@capcar.fr` (09-01, 09-02, 09-03), `info@mail-alerte.lacentrale.fr` (09-03 ×2, 09-04)
  and `support@agorastore.fr` (09-01, 09-02, 09-03). **CapCar is n=3, not the n=1 the B1 entry
  warns about** — the second and third alerts are the regression tests that entry asked for. Two
  shape facts measured while confirming, both of which change the build: **CapCar is HTML-ONLY**
  (`text/html`, no `text/plain` alternative — the leboncoin shape, so `harvestHrefs()` is what makes
  its links reachable at all), while La Centrale and Agorastore both carry a real `text/plain`
  part. All three route every link through a per-recipient tracking host (`sendibt3.com`,
  `clicks.mail-alerte.lacentrale.fr`, `email.alerts.agorastore.fr`), so all three are
  content-keyed, and the scheme must be chosen BEFORE the source is first enabled — nothing
  migrates a stored row between key schemes. The captures are raw in gitignored
  `var/claude/captures/`; `tools/scrub-eml.php` is still owed before any becomes a fixture.

- [2026-09-04 10:20] AGREED (**COR-F5's discriminator**, settling how the 2026-09-03 ruling is
  built). The ruling is *a persisted UNDETERMINED twin doubt is cleared by POSITIVE EVIDENCE ONLY —
  a tier-5 source default may never overwrite a recorded `UNKNOWN`, only a tier 1–4 signal can*. It
  needs a mechanism, because `twin_tenure` carries a tenure and a source and **no tier and no
  confidence**, so "positive evidence" is not readable from what is stored. Two options were
  available: add a tier column, or gate on the INCOMING reading's confidence. **The gate is the
  incoming confidence, at ≥ 60.** That is not a new number — it is §1's own fail-closed threshold,
  the one that already sends a sub-0.6 classification to the digest — and it puts a tier-5 source
  default (50) below the line while every tier-1/2 label (90) clears it. A tier column would be a
  second encoding of the same fact, free to drift from the first. The confidence is already in
  hand at the call site: `$twinReading` is seeded from a `Classification`, which carries
  `confidenceBp`, and the fixed point copies the whole entry, so it propagates with the tenure it
  belongs to rather than being recomputed.

  **Precedence, stated in full so the three rules cannot be read as one:** an EXCLUDED incoming
  reading always writes and is durable for the row's life (unchanged, developer ruling
  2026-08-30); a recorded `UNKNOWN` is TIGHTENED by anything more restrictive without a confidence
  bar; and only the *clearing* direction — `UNKNOWN` → eligible — is gated. So the change can only
  ever make the store more careful, never less, which is the direction §1 requires and the reason
  it is safe to land without re-opening the 2026-08-30 ruling.

- [2026-09-04 10:55] RECORD (**COR-F5 CLOSED, `eb5d971` — the last open C2 round-1 finding**). Built
  exactly as ruled above: `Store::TWIN_DOUBT_MIN_CONFIDENCE = 60`, gating the RESOLVING direction
  only, with the confidence carried through `$twinReading` and `$seen` so the number reaching the
  store belongs to the tenure that actually won rather than being recomputed. **Refutation
  reproduced through the real pipeline BEFORE the fix** — pass 1 records the doubt from the
  mixed-stock landlord's silent card, pass 2 drops that route and adds an In'li card stating
  nothing, and the stored twin goes `UNKNOWN → LLI`. Suite **2704 / 10 537** (from 2691).

  Four things travel with it, three of them scars:

  - **Store-level contract tests in the `twin` category**, per this repo's rule that a store
    behaviour without a named category is one nobody decided to guarantee: a weak reading cannot
    resolve a doubt, a reading AT the threshold can (the boundary is inclusive, because the
    threshold is where a classification stops being fail-closed), and **tightening is never gated**
    — asserted so the fix cannot later be widened into a general *"ignore weak readings"* rule,
    which would drop a weak `PLS` on the floor.
  - **The tenure tripwire fired twice, both the documented false positive**, and both were fixed by
    rewording rather than by touching a pattern. First `array $fields = []` in a test helper — `= []`
    is one of the shapes it reads as the excluded set being emptied. Then, less obviously, a constant
    named `…CLEARING_CONFIDENCE`: `clear` is another of those shapes, and it sat inside the pattern's
    80-character window of the `isExcluded()` call below it. The guard matches the EDIT PAYLOAD, not
    the file, which is why re-running it against the file on disk came back silent and briefly looked
    like a phantom.
  - **A two-line sed matches nothing**, and the counterweight case was first written that way. `sed`
    does not span lines; the expression would have applied to nothing while reporting no error —
    exactly what `tests/test-sabotage-applies.sh` exists to catch, caught here by trying each
    expression against a copy before committing it rather than after.
  - **The third sabotage case is the one worth keeping**: the store's gate is only as good as the
    number reaching it, so sending a constant from the pipeline leaves the guard intact and inert.
    A guard that cannot be reached is not a guard, and nothing else in the ledger would have said so.

- [2026-09-04 11:15] RECORD (**F20 half-closed and the car `MalformedText` arms tested, `526d246`**
  — steps 29 and 31; 25 of 34 rows now done, one blocked on an input). Suite **2708 / 10 546**,
  every guard green, drift `P0=0 P1=0 P2=0`.

  - **F20's two halves are separated deliberately, and only the smaller one is built.** The
    misattribution is fixed — nothing stores where an excluded reading came from, so the reason has
    stopped claiming it. The repair ROUTE is left owed: storing provenance means a migration on the
    §1 audit trail, and a command that re-opens an excluded row is a route to re-admitting an
    excluded listing, which §1 refuses without a ruling. What was actually costing someone
    something was the sentence, and a reviewer had already followed it to the wrong listing.
  - **The car `MalformedText` arms are not symmetric, and nothing said so.** The classifier fails
    CLOSED; `VehicleCriteria::excludedBy()` fails OPEN. The fail-open is unreachable only because
    `VehicleScorer::judge()` returns on the classification's `REJECT` first — a real, undocumented
    dependency, one edit from being false. **The test asserts the ORDER through the real `judge()`**
    rather than each arm alone: an arm-by-arm test stays green through precisely the change that
    matters, and a claim about sequence that is not executed is a claim about the reader's memory.
  - **The tenure tripwire fired a THIRD time**, and the shape is worth writing down because it is
    not the one the gotchas list describes. The match was an assertion message ending on *"never"*
    immediately before a docblock beginning *"A DOUBT IS CLEARED"*: `[^.]` spans newlines, so the
    pattern reached across a closing brace and a blank line into the next test. **The guard matches
    the EDIT PAYLOAD, not the file**, which is why re-running it against the file on disk came back
    silent and briefly looked like a phantom — the reliable diagnosis is to diff the pattern's
    matches over the file before and after, not to re-run the hook.

- [2026-09-04 12:56] RECORD (**C2 ROUND 2 RAN AND WAS NOT CLEAN — 0 P0, 2 P1, 5 P2, 5 P3; all
  findings fixed in `5833b87`; the two-clean counter RESETS**). Three lenses, unnamed, each in its
  own pinned worktree at `8fb9b64`. Suite **2732 / 10 610**, 667 sabotage expressions applying,
  every guard green, drift `P0=0 P1=0 P2=0`. Full reports under `var/claude/c2-round2-*.md`.

  **BOTH P1s WERE THIS SESSION'S OWN WORK, which is the panel doing exactly what it is for.**

  - **A credential can reach a stack trace, on BOTH production paths.** PHP prints the first 15
    characters of every string call ARGUMENT. `tools/dump-eml.php` was fixed for this in the SAME
    span that left `ImapMailbox` and `SmtpTransport` standing — a correct rule applied to a subset
    of the surfaces it belongs on, committed by the change that documented the threat model. The
    resilience lens found the IMAP one and measured two characters escaping behind a three-character
    username. **The SMTP one was found by following the finding's implication rather than fixing
    only its instance, and it is worse**: the credential was `say()`'s only string argument, so the
    whole budget went to it whatever the username — eleven characters, one `base64 -d` away.
    Recorded in `var/claude/c2-round2-author-sweep.md` because a lens did not produce it.

    **The fix needed TWO levels and the first was not enough.** Moving the credential out of the
    helper's parameters left it in `fwrite`'s, and a trace prints built-in frames too; `@`
    suppresses warnings and does nothing to the `TypeError` a closed stream raises. Both writes are
    wrapped with the original DISCARDED — a `previous` carries the very trace being escaped. A
    second draft then took the encoded value as the wrapper's own parameter, putting it straight
    back; the shipped form passes a SELECTOR and reads the credential into a local. Two levels of
    the same mistake in one sitting is a fair measure of how easy this is to get almost right.

  - **The twin tie-break made the store non-deterministic, and that refutes the claim `eb5d971`
    shipped under.** `twinClassification()`'s `$seen` loop replaced only on a STRICT rank increase,
    so two ELIGIBLE twins tied and the FIRST ITERATED won — and that order is `Core\Pacer`'s
    shuffle. Cosmetic while both wrote the same tenure; COR-F5 made the confidence decide whether
    the store writes at all, and the tie then decided the outcome on identical input. The unsafe
    direction is unreachable, so not a §1 breach — but *"can only ever make the store MORE careful"*
    was wrong, and both COR-F5 tests use a single twin so nothing covered it.

    **The pipeline-level test for it was written first and was wrong**: two same-family listings of
    one flat are DUPLICATES, so `Dedup` absorbs one and the survivor reaches the agency copy as a
    single twin — the tie never occurs, and the test passed in one order and failed in the other for
    an unrelated reason. Driven at `twinClassification()` instead, where the tie lives.

  - **The compile-check list could not catch its own finding, and neither could my first guard.**
    Deleting `advertiser_pattern` — the §1-relevant key, feeding `Core/LandlordRegistry` — left the
    whole suite and the whole ledger green. Promoted to a named constant with a reflection test, and
    **measured: still green**, because a data provider reading `PATTERN_PARAMS` derives its CASES
    from the list being deleted from, so seven of seven passed. The guard that bites is a SET
    assertion against `EMAIL_ALERT_PARAMS` — two independent lists, neither derived from the other.
    The reflection-guard trap in its purest form, caught by sabotaging my own guard.

  Five more, each verified against the tree before acting on it: `verify-deploy` read only `src/`
  while the image also bakes `bin/` and `composer.json` (latent — all five historic `bin/` commits
  also touched `src/`); an unreachable daemon reported as a missing image, telling the operator to
  build while the builder was down; `pendingDigest()`'s published `@return` omitted the `tenure`
  column its own SELECT returns; `test-backup-state.sh` ran in CI unpinned while eleven siblings
  were pinned; a truncated capture reported as a success; and `PacedSource` dropped the counting
  capability it forwards `FeedFreshness` for, its own docblock's principle. That last one made the
  escalation guard fire, correctly — a decorator satisfies it by DELEGATING, so the guard gained a
  narrow exemption requiring the class to forward `patternMisses()` to the same inner, and it still
  fires when a real adapter loses its escalation.

  **And the enumeration of historic leaks was short by one**: `25d8839`, a Cityloger Google Maps key
  across 34 commits, pushed. Scoped honestly — a browser-side key the landlord serves to every
  visitor is hygiene, not exposure — and recorded because an enumeration inside the rule about the
  leak surface should be right, and because *"scrubbing was a habit"* is the same cause as the other
  two.

  **RE-FREEZE for round 3: `5833b87`** — 82 commits since `7765997`, **49 touching code**. The
  pointer's own test is empty and the tree is clean.

- [2026-09-04 14:29] RECORD (**C2 ROUND 3 RAN AND WAS NOT CLEAN — 0 P0, 2 P1, 4 P2, 5 P3; all
  fixed in `909c159`; the two-clean counter RESTARTS AGAIN**). Three lenses, unnamed, pinned at
  `5833b87`. Suite **2735 / 10 662**, 667 sabotage expressions applying, every guard green, drift
  `P0=0 P1=0 P2=0`. Reports under `var/claude/c2-round3-*.md`.

  **THE TWO SHARPEST FINDINGS WERE THIS SESSION'S OWN WORK, for the second round running.**

  - **A SABOTAGE CASE THAT FAILS.** The SMTP-wrapper case replaced only the line INSIDE the `try`,
    so the `catch` survived, the `TypeError` was still swallowed, and the code was still safe — it
    reported an undetected regression against code that had not regressed. `ci.yml` opens a GitHub
    issue on a red nightly ledger, so `5833b87` shipped **a scheduled alarm for a non-defect**,
    which is hard rule 2 read backwards. The author's own run never saw it: that case was one of two
    the machine SIGKILLed at **exit 137** under the load of three concurrent reviewers.
    `test-sabotage-applies.sh` was green throughout and correctly so — **an expression that APPLIES
    is not one that MODELS the defect**, and only running the ledger tells them apart. The mutation
    is now the `catch`, verified red.

  - **THE CONSTRUCTOR IS A CREDENTIAL SURFACE NO PER-SITE FIX REACHED, and it leaks the most.** Each
    parameter carries its OWN 15-character budget, so the username no longer spends it first and the
    password arrives in CLEAR TEXT — fifteen characters, against the two that made
    `ImapMailbox::login()` a finding. Latent behind three `(int)` casts on the port that nothing
    asserted. **Fixed structurally rather than at a fourth call site**, because the per-site pattern
    had already been shown to miss one: `bin/scout` sets `zend.exception_ignore_args=1`, removing
    every argument from every frame in that process, including surfaces nobody has enumerated. The
    per-site fixes stay — they hold for code reached outside the executable (`tools/`, the suite, a
    future entrypoint). *Stated cost: deployed traces lose all argument information*, which is real
    debugging value given up, and the right trade only because this project already redacts its
    diagnostics on purpose.

  - **AND A CURE ASSERTED THAT DID NOT EXIST.** Round 2's rent-side anchor was justified by a
    docblock claiming *"the property the car side already had"*. It did not: `READ_PARAMS` was read
    by no test, and the car reflection guard constrains **four of eight** keys — a lens added a
    ninth `*_pattern` key to the car allow-list and loaded it uncompilable and silent with 382 tests
    green. So the sentence describing the one-of-two-symmetric-surfaces shape was itself an instance
    of it. The car anchor now exists and the docblock cites it instead of assuming it.

  - **Both lenses converged on the residual from opposite directions**: anchoring the compile-check
    set on the `_pattern` NAME SUFFIX is a convention, so a regex param named otherwise escapes both
    lists. The rent guard now also reads the `matchParam()` FUNNEL — every literal key handed to it
    is applied as a regex whatever it is called — which is an anchor rather than a habit.

  - **The round-trip guard covered the ENCODER only**, and `mergedWith()`'s own docblock conceded it
    in words. A comment is not a guard: a lens wired a 22nd field into the encoder alone and measured
    the encoder guard forcing it in while **both readers silently dropped it**. Latent — all 21
    current parameters are carried by both — but this is the trap `CLAUDE.md` records firing on the
    sibling `enrich()` path at a cost of 429 phantom history rows and 128 emails, fixed there with a
    clone-with AND a reflection guard; the merge had neither. Both readers now round-trip every
    parameter with a value distinguishable from its default, nothing skipped.

  Four more: `verify-deploy`'s freshness list was read off four `Dockerfile` LINES rather than the
  recipe, omitting the `Dockerfile` itself, `.dockerignore` and the baked demo fixture (**a
  line-number citation is what drifted, again**); `dump-eml.php`'s own credential write was the one
  surface of three left at level 1; `recordTwin()`'s docblock called a CONFIDENCE bar a tier test
  when a tier-3 procedural signal measurably clears at 80; and a docblock cited a method that had
  been renamed away.

  **The car domain's missing `PacedSource` is RECORDED, NOT FIXED**, with the condition that would
  make it real: its only web source (`SitemapVehicleSource`) rate-limits itself between detail
  fetches and every car email source answers `host(): null`, so the decorator's absence has no live
  consequence. A second car web source without its own limiter is the moment it becomes one, and
  the fix then is to lift `PacedSource` into `Scout\Adapters` over a shared contract — not to write
  a car twin, because two decorators is how one of them drifts.

  **RE-FREEZE for round 4: `909c159`** — 85 commits since `7765997`, **50 touching code**.

- [2026-09-04 16:45] RECORD (**C2 ROUND 4 RAN, WAS NOT CLEAN, AND CANNOT COUNT EITHER WAY — the
  two-clean counter RESTARTS AGAIN**). Three lenses, unnamed, pinned at **`6d0986e`** — NOT
  `909c159`, which this record first claimed. Both surviving round-4 lens reports name `6d0986e`,
  and `git merge-base --is-ancestor d3c201a 909c159` answers yes, so the reviewed span was wider
  than the record said (9 commits, not 3). Corrected by the round-5 completeness lens. **Only two of
  the three round-4 reports exist under `var/claude/`** — the completeness one was never written —
  so "three lenses" is true of what RAN and not of what was KEPT. Fixed in
  `1529fbb` + `fe0a468`; suite **2757 / 10 737**, every guard green, drift `P0=0 P1=0 P2=0`.

  **THE FREEZE BROKE MID-ROUND**, and that alone disqualifies it: `569a1e5` landed *after* a lens
  had verified its span was empty, so the three reports do not describe one tree. A round run on a
  moving tree cannot count toward the two-clean requirement — the rule exists for exactly this, and
  the finding was the lens's own, not a later discovery.

  **THE SHARPEST FINDING WAS THIS SESSION'S OWN FIX, FOR THE THIRD ROUND RUNNING, AND IT WAS
  DEPLOYED.** The mapped rent band shipped with both bounds, and the UPPER one bypassed
  `max_rent_cc` outright: `CriteriaEngine::disqualify()` guards the ceiling with `$rentCc !== null`,
  so nulling a 25 000 € figure did not reject the flat, it removed the evidence the ceiling needed —
  REJECT became MATCH, and the push said *"loyer non communiqué"* for a rent the portal had
  communicated. The cause is transplantation: in `EmailAlertSource::rentIn()` the band sits inside a
  loop over CANDIDATES, where *refused* means **keep looking** and discarding 95 000 costs nothing
  because the real rent is on the next line. On a single MAPPED value it means *no rent at all* —
  the same numbers, the opposite safety direction. The mapped path now keeps the FLOOR and drops the
  ceiling; above 20 000 the ceiling already rejects, which is strictly better than silence.

  **AND TWO GUARDS WERE GREEN WHILE THE GUARANTEE UNDER THEM WAS DEAD**, both the same shape — a
  check satisfied by something other than the thing it names, and neither found by a red run:

  - `mergedWith()` was tested in ONE direction. Production is `$card->mergedWith($detail)`; the
    guard merged a RICH card with an EMPTY detail, so every `$any($mine, $theirs)` took `$mine` and
    rewriting a field to `$this->x` left all 2 753 tests green. The two live `detail_map` fields are
    fixture-covered and would have gone red anyway — which is precisely what would have hidden that
    the guard itself was blind. The argument direction is asserted now, and **which fields it covers
    is DERIVED from `mergedWith()`'s own body** rather than listed, so a field dropped out of `$any`
    cannot exempt itself from the assertion by disappearing from it; a counterweight pins the
    receiver-only set, because emptying the derived set would otherwise satisfy the direction
    assertion on a method merging nothing.
  - the entrypoint assertion read `bin/scout` WITHOUT stripping comment lines, so the docblock
    explaining `zend.exception_ignore_args` satisfied a grep for the `ini_set` — commenting the line
    OUT left the file green while the guarantee was dead. **The same file strips comments in two
    other assertions and names the trap**, and it was still made here.

  **A LEDGER CASE WAS CORRECTED BEFORE IT SHIPPED, which is the round-3 lesson landing in time.**
  The counterweight's mutation first cut mid-argument-list, leaving `rentCc: $this->NOPE_rentCc,
  $detail->rentCc),` — a stray argument and an unbalanced paren, so `RawListing` failed to LOAD and
  the run printed `Errors … Assertions: 0`. That is a parse error wearing a detection's clothes, and
  it read as a pass on first glance. The shipped form rewrites each line whole and leaves valid code
  that merges nothing, verified with `php -l` **before** the assertion was believed. Round 3 shipped
  such a case and armed a nightly alarm for a non-defect; this one did not ship.

  **Rows 27 and 31 were wrongly `certified` and are back to `done`.** Their deliverable lives in
  `tests/sabotage-check.sh`, which the progress adapter's `test_cmd` does not run — the exact
  exclusion the commit that added the adapter had itself stated. `steps_certified` 20 → 18.

  **RE-FREEZE for round 5: `fe0a468`.** Round 5 is the MAXIMAL cap. Rounds 1–4 each found
  something, so even a fully clean round 5 is **one** clean round against a two-clean bar: the cap
  is reached with the counter at 1, and the exit is decided WITH the developer via
  `AskUserQuestion`, never silently — the 2026-08-30 ruling carried above, which autonomous mode
  does not suppress.

- [2026-09-04 19:30] RECORD (**C2 ROUND 5 RAN AND WAS NOT CLEAN — 17 findings across three lenses:
  6 + 6 + 5, of which 5 are P1. The two-clean counter is 0**). Three lenses, unnamed, pinned at
  `5e68c24`; the freeze HELD this time (the completeness lens verified it at start and end). Reports
  under `var/claude/c2-round5-*.md`.

  **THE ROUND-4 RECORD ABOVE CONTAINED A FALSIFIED PREDICTION, and it is corrected rather than
  quietly dropped.** It said the cap would be reached "with the counter at 1". Round 5 found
  seventeen things, so the counter is **0**. A prediction written into a decisions log is a claim
  like any other.

  **THE HEADLINE FINDING WAS THE ROUND-4 FIX ITSELF, ON THE BOUND IT DID NOT TOUCH.** `1529fbb`
  dropped the ceiling from the mapped band and kept the floor — and the floor does the identical
  damage to a different guard. `CriteriaEngine::pricePerM2()` returns null when the rent is null, so
  nulling a sub-200 figure skips the Track 1f plausibility branch: `{119 €, 60 m², 3 p}` judged
  **DIGEST** at `909c159` and **MATCH** at `5e68c24`. The round-4 commit message had argued the
  general principle — *"handing it a figure it will reject is strictly better than silence"* — and
  then applied it to one of the two bounds, which is this repo's named recurring defect committed by
  the fix for an instance of it, for the third round running.

  **RULING REVERSED: Track 6-A3 half 3 (AGREED 2026-09-03) is withdrawn — the mapped path carries NO
  band.** Not a defect fix but the reversal of a ruling, so it is recorded as one. The measurement
  that reverses it: the "7 price-history rows at 119–290 €" the band was justified by contain
  **ONE** row below 200 €, already digested on tenure — zero rows changed in either direction —
  while the band disabled the price-per-m² floor, which is the mechanism `Payload`'s own docblock
  names for a mis-mapped low rent. Every downstream guard reads a rent as `!== null`, so on a single
  LABELLED value "outside the band" and "not stated" are the same input and nulling deletes evidence
  rather than rejecting anything. The band stays in `EmailAlertSource::rentIn()`, where it sits
  inside a loop over CANDIDATES and *refused* means **keep looking**. Reversed back by
  reintroducing either bound in `ListingMapper::rents()` — two ledger cases now fire if anyone does.

  **THE CI GATE WAS RED AT THE FROZEN COMMIT, and the round-4 record claimed "every guard green".**
  Five ledger expressions went inert when the band moved into `Payload::plausibleRent()`;
  `tests/test-sabotage-applies.sh` exits 1 and `ci.yml` runs it on every push. The claim was false
  because that guard was never run — the suite, both tripwires, drift-scan and `bash -n` were.
  **The commit immediately before the span is titled *"two ledger expressions went inert when the
  escalation moved"*.** Same trap, one commit later, with the warning in the session's own memory
  index. Two lenses found it independently. Retargeting the FILE was not enough either: the numbers
  had become named constants in the same move, and all three stayed inert until the expressions
  followed the code's SHAPE as well as its address.

  **FOUR CONFIGURED FIELD-MAP KEYS WERE COUNTED BY NOTHING** — `rent`, `rent_hc`, `url` and
  `tenure_field` — also found independently by two lenses. They are precisely the four read outside
  `map()`'s constructor call, so anyone auditing that call saw a complete set. `tenure_field` is the
  §1 one: dead, the key is simply absent, the classifier falls through to the SOURCE DEFAULT, and a
  mixed-stock portal judges every listing by its most optimistic assumption while `item_count` is
  unchanged, no run fails and `SourceHealth` stays `ok`. Now guarded BEHAVIOURALLY by reflection
  over `FieldMap` — every configurable key must be reported by `total()` when dead — so a fifteenth
  key instrumented by nobody fails there. `ref` is the one exemption, and it needs no counter: its
  absence throws.

  **THE SECRETS GUARDS DECODE BOTH BASE64 ALPHABETS AT ALL FOUR ALIGNMENTS**, sharing ONE cascade so
  they cannot drift apart again, and `FixtureSecretsTest` gained an address guard — a non-portal
  address, allow-listed by domain for portals and by FULL ADDRESS for consumer domains, because the
  subscriber is on one and a domain allow-list would switch the guard off for the only address it
  exists to catch. `mail.com` was moved to the address list for that reason after its single
  occurrence was checked (a French template placeholder).

  **TWO REVIEWER CLAIMS WERE MEASURED AND DID NOT SURVIVE, recorded because a finding accepted
  without checking is as bad as one missed.** The alphabet widening could NOT be isolated: over
  ~100 000 randomised realistic shapes there is no payload where the wide scan recovers an address
  and the narrow one misses it — an ASCII address essentially never generates `+`/`/` inside its own
  encoded span. It is kept as defence in depth and says so. And the scrubber's percent-decode cannot
  be isolated through the tool's exit status either: an 80-character opaque-run detector fires
  first, which is layered defence working rather than a gap.

  **AND THE SHARPEST FINDING OF THE ROUND WAS AGAINST THE ROUND'S OWN FIX.** Adding four-alignment
  decoding made `testTheGuardSeesThroughPercentEncoding` **vacuous**: with four offsets a fragment
  of the RAW percent-encoded header decodes to text containing `eyJ` unaided, so deleting the
  `rawurldecode` that a P0 was fixed with left the test green. Measured both ways — offset 0 alone
  misses it, four offsets recover it. *A strictly better guard silently disabled the self-test
  protecting a P0's repair.* An OUTCOME assertion cannot defend against that, because every added
  capability gives the outcome one more way to be satisfied; the replacement asserts the MECHANISM
  — each decoded form must be present in the cascade actually scanned — and is verified red without
  it. Note the round-5 resilience lens had verified that twin non-vacuous AT `5e68c24`: it was
  correct, and this session broke it afterwards.

  **STILL OPEN, RECORDED NOT FIXED (P2):** correctness F3 — with no band, a selector drifting onto a
  5-digit field extracts `95240` cleanly, `max_rent_cc` then rejects every card on the source, `rent`
  counts zero misses because the extraction SUCCEEDED, and health stays `ok`. That is the ParuVendu
  `autres` class one layer over — a capture that succeeds without meaning anything — and it wants
  the same instrument, not this round's.

  **ROUND 5 WAS THE MAXIMAL CAP AND THE COUNTER IS 0.** Per this repo's ladder and the 2026-08-30
  ruling carried above, the exit is decided WITH the developer via `AskUserQuestion`, never
  silently. Row 35 cannot close without that answer.

- [2026-09-04 21:05] AGREED (**the cap question, answered: TRACK 0 IS PAUSED**). Asked via
  `AskUserQuestion` as the 2026-08-30 ruling requires — four options, round 6 recommended on the
  evidence that rounds 3, 4 AND 5 each found a P1 inside the PREVIOUS round's fix. The developer's
  answer, verbatim: *"Let's pause for now ! save everything !"*

  So **Track 0 does not close and does not continue**: the two-consecutive-clean bar stands at 0 and
  is UNMET, stated rather than waived, and row 35 stays open. Nothing is certified by this pause —
  the round-5 fixes rest on their executable evidence alone (suite 2798 / 10 798, nine guards green,
  677 ledger expressions applying, every new guarantee verified red by direct mutation), and the
  dimensions no round covered stay uncovered.

  **Resuming needs no re-derivation:** freeze at `792eb3a`, spawn three unnamed lenses over
  `5e68c24..792eb3a` — the smallest and cleanest input any round here has had — and the specific
  thing to aim at is this round's own fixes, because that is where the last three rounds found their
  P1s. The recommendation and its reasoning are recorded above; they do not expire.

- [2026-09-04 23:29] AGREED (**REACH 100 %, CODE FIRST — the pause is lifted and the order is
  ruled**). The developer asked for the real remainder (*"How much is not done or not certified or
  not ruled ! … real percentage/effort/time"*) and, given the forced sequencing, chose *"Code first,
  then freeze and rounds"* via `AskUserQuestion`. Measured before the question: plan 29/35 steps,
  84/122 pts (68 %); test-verified 16/35 (43 pts, 35 %); panel-certified **0** (counter 0, cap hit
  at round 5); five firm items outside the block (9 pts) and three ruled-deferred ones (8 pts) — so
  the honest denominator is 131 pts, 64 % done. Velocity 20.75 pts/week over 28 days of sessions.
  **The order is forced, not preferred:** row 35 certifies a FROZEN span and every open code item
  touches `src/`, `config/` or `tests/`, so a fix landing after a clean round moves the freeze and
  resets the two-clean counter — two panels for one milestone, the waste this file names. So:
  land every code item (rows 6, 9–11, 36–43 below), take each ruling as its row reaches it, freeze
  ONCE, run C2 rounds to two consecutive clean, redeploy, `verify-deploy`. **Tier for this run:**
  per-task 3C/6C gates run `advisor()` only; the three-lens panel runs at the freeze and nowhere
  else (the economize ruling, and the developer's chosen option says the same). Row 12 (B4) stays
  blocked on AutoScout24's first alert; 100 % is structurally 97.5 % of the block until it arrives.
- [2026-09-04 23:29] AGREED (**PROCESSED ALERT EMAILS ARE MARKED `\Seen`** — developer request,
  verbatim: *"can you mark the emails in (rent|car)/portails as seen when you process them ! so
  that way i know which email was processed and which not ??"*). Row 36. The shape, stated before
  it is built because it loosens a documented invariant (`ImapMailbox` is *"READ-ONLY, and enforced
  rather than intended"* — `EXAMINE`, `BODY.PEEK`): the flag is set only by a `run` pass, only AFTER
  the store has recorded that source's listings, and only on the messages that pass parsed;
  `doctor` and `tools/dump-eml.php` stay read-only at the protocol level. The `SEARCH` stays
  date-based (`SINCE` + `FROM`), never `UNSEEN`: the 7-day re-read is what lets a misread card
  self-heal and what `FEED_SILENT` measures, so the flag is an INFORMATIONAL mark for the human, not
  the pipeline's own dedup — the store's seen-set stays that. Stated cost: a pass that records and
  then fails downstream leaves a message flagged; the next pass re-reads it anyway.
- [2026-09-05 00:40] RECORD (**ROW 36 BUILT — `\Seen` on processed alerts, and the IMAP client's
  first wire coverage**). Shape as ruled above, plus what the 3C advisor pass added and was right
  about: messages addressed by UID throughout (`UID SEARCH` / `UID FETCH … (UID FLAGS BODY.PEEK[])`)
  because the mark runs in a SECOND session; `UIDVALIDITY` compared across the two and the store
  refused on a change; only claimed-AND-still-unseen UIDs stored, so steady state opens no write
  session; the claim state reset FIRST in `fetchRecent()` above the early returns; a refusal routed
  to `RunResult::$errors` (both pipelines), never fatal; `PacedSource` forwards the capability so
  `--watch` marks what `--once` marks; the `$connector` seam consulted only after the offline
  refusal. Executable evidence: `ImapMailboxWireTest` (10 tests against a scripted loopback
  server, transcript as evidence), `EmailAlertClaimTest` + `VehicleEmailClaimTest`, four
  order-asserting pipeline tests per domain, `AcknowledgeCallSitesTest` (doctor and `dump-eml`
  never mark), the `searchCommand()` assertions moved to `UID SEARCH`; **23 ledger cases, 23/23
  red by direct mutation**; `test-sabotage-applies` 700/700 (three expressions orphaned by the
  change retargeted — two were my own with `\\\\Seen` where the source has one backslash). Full
  suite green before commit. **Not certified by execution: the live Gmail server** — the scripted
  server answers the commands a real one does, but the first `run` pass after redeploy is the
  proof that Gmail accepts `UID STORE +FLAGS.SILENT` on a label folder; check the label after it.
  **PROVEN LIVE 2026-09-05 00:45** — redeployed at `766edd7` (`verify-deploy` clean), the first
  watch pass completed (`8 source(s), 1178 annonce(s) analysées`) with no *marquage … refusé*
  line, and a read-only Gmail search after it: `from:alertes.seloger.com is:read newer_than:1d`
  → 12 threads, `is:unread` → none. Gmail accepts the STORE on a label folder. The same pass
  surfaced a NEW live fault, unrelated: `inli: HTTP 302 from …/ile-de-france-region_r:11`,
  source `broken` — row 44.
- [2026-09-05 01:30] RECORD (**ROWS 37 + 38 BUILT TOGETHER — content identity and the
  labelled-card reader on `VehicleEmailSource`**; one commit, both cells cite it). Measured
  first, on the 2026-09-04 census captures: CapCar's 8 links are 4 CTAs + 4 banner/footer links,
  ALL on `sendibt3.com` (so the last-link rule would hand a push the footer's redirect — hence
  `link_after`); La Centrale's card is `TITLE / La Centrale N km / P €` with NO year and the title
  truncated at ~28 chars (`RENAULT KANGOO II EXPRESS p...`); Agorastore's is one line carrying
  year, km and a PLATE. **Scrubbing a La Centrale and a CapCar capture into the scratchpad
  succeeded** — the address is not recoverable from their tokens — so B2 is NOT blocked on row 39;
  Agorastore still is. Built: `id_from` (`link`|`content`, refused otherwise),
  `card_separator_pattern` (mutually exclusive with the literal), `link_after`, and `facts_pattern`
  named groups `title/make/model/version/gearbox/price` with the loader refusing a second provider
  for any fact a group supplies; content key `sha1(source|fold(title)|year|km)`, price out, floor
  = title AND (year or km); facts parsed BEFORE the title so a composed title exists when
  `make_model_source: title` and the gearbox reader run; furniture check keyed on "a price
  provider is configured" rather than on `price_pattern` (the 3C advisor's catch: the old test
  went false the moment the price moved into the facts, and a footer link became a car under link
  identity); the unknown-make sentinel applied on the facts path. Evidence:
  `VehicleEmailContentIdentityTest` (19 tests on synthetic CapCar- and La Centrale-shaped
  messages — no raw capture is referenced from a test), ParuVendu and leboncoin fixture tests
  unchanged, `VehicleEmailPatternMissTest` subtracts `card_separator_pattern` as message-level;
  12 ledger cases, 12/12 red by direct mutation — **after one of them came back UNDETECTED on
  the first loop**: the furniture-precondition case was "covered" by a test on the CapCar shape,
  where `link_after` and the lookahead separator keep every furniture link out of reach, so the
  guard could be deleted with the test green. Rewritten on the La Centrale shape, whose `Détails`
  split leaves the unsubscribe tail as its own segment — the mutation loop is what found it, and
  a case that reads UNDETECTED is a finding about the TEST. One pre-existing expression went
  inert with the change (the old one-line furniture condition) and was retargeted at the new
  first line. `test-sabotage-applies` 712/712. What this does NOT do: B1/B2 config and fixtures are their own rows (9, 10),
  and `CapCarPayloadShapeTest`'s "the subject cannot name a vehicle" assertion changes in B1's
  commit, not this one.
- [2026-09-05 02:10] RECORD (**ROW 9 — B1 CapCar IS LIVE IN CONFIG**, source #4 of the car
  domain). Three captures scrubbed (the scrubber reported the address unrecoverable on all
  three; a grep for the name finds nothing) and frozen — `2026-09-01-001-quatre-cartes-peugeot`,
  `2026-09-02-001-quatre-cartes-kia`, the existing `2026-09-03-001-quatre-cartes` — so n=3 from
  day one. The block: `from contact@capcar.fr`, `link_host` the FULL Brevo account subdomain
  `cjbjibe.r.bh.d.sendibt3.com/tr/cl/` (adLinkIn matches by prefix; measured over 24 links, every
  one starts with it), `subject_pattern`, `card_separator_pattern (?=Marque\x{00A0}:)`,
  `link_after "Voir ce véhicule"`, `id_from content`, one `facts_pattern` with eight named groups,
  `feed_silent_days 3` (one alert a day at 18:00). `family: dealer` — CapCar sells on mandate, a
  fixed price per car. `CapCarFixtureTest` hand-reads all 12 cards (title composed, make/model
  folded, U+202F prices, `Hybride rechargeable`/`Hybride essence`/`Hybride diesel` → hybride,
  `Électrique` → electrique, `body` null because the template carries none), asserts 12 distinct
  content ids that are sha1s and not tokens, 12 distinct CTA links none of which is a footer's
  (the three footer tokens are read off the fixtures), no warning and no phantom, freshness
  delegated. Offline proof:
  `MAILBOX_DIR=tests/fixtures/car/capcar CAR_SCOUT_DB=$(mktemp -u) scout --domain=car doctor
  --source=capcar` → **`ok · 12 annonces · 238 ms`**. One hand-read value was wrong on the first
  run — the observedAt was written as if the Date header were +0200; it is UTC — and corrected
  from the failure, not the other way round. **Config-only for the SOURCE, code-backed by rows
  37+38; the deployed image predates both, so the live watcher needs a rebuild before it can read
  this block** (a refused `id_from` on the old loader is a startup refusal, not a quiet skip).
- [2026-09-05 03:20] RECORD (**ROW 10 — B2 La Centrale IS LIVE IN CONFIG, source #5 — and the
  scrubber learned a fourth encoding on the way**). B1 redeployed first (`618b065`,
  `verify-deploy` clean). Then: the two clean La Centrale captures were scrubbed, copied in, and
  **`FixtureSecretsTest` refused the first one** — a Gmail address, base64-encoded inside the
  `X-MSFBL` feedback-loop header and FOLDED across continuation lines, which the scrubber had
  passed with `scrubbed`. Root cause measured: a header fold (`\r\n` + TAB) splits the base64 run
  and the quoted-printable decode does not unfold headers, so every fragment decoded to noise; the
  guard caught it only because one 64-char fragment happened to decode to the local part. The
  fixtures came straight back out of the tree. Fix, tests first: `tests/test-scrub-eml.sh` gained
  a folded `X-MSFBL` case (must be dropped and unrecoverable after unfolding) and the same blob
  folded under an UNKNOWN header (must be REFUSED — the unfolding, not the list); the first
  version of that case passed BEFORE the fix because the address sat inside line 1 and a fragment
  decoded to it, so the blob is padded to put the address ASTRIDE the fold. `tools/scrub-eml.php`
  scans a header-unfolded form and drops `x-msfbl`; `FixtureSecretsTest::allForms()` scans the
  same form, with a mechanism assertion and a planted-straddling-address self-test. **Both fixes
  verified red by mutation** (remove the unfolded form: scrubber 3 fail, guard 2 fail). With the
  corrected tool all THREE captures scrub clean — the 09-04 one that had been refused was refused
  for that same header — so B2 ships n=3: `LaCentraleFixtureTest` hand-reads nine cards, six
  identities (Kangoo, X4 and 3008 each re-sent behind fresh tokens), no year on any card, offline
  `doctor` → **`ok · 9 annonces · 43 ms`**. `PERMITTED_DOMAINS` gained `lacentrale.fr` and — not a
  domain — `2x.png`, the portal's retina asset names that match the address shape. Row 39
  (Agorastore's base64 JSON-array blob in the BODY) is a different shape and stays open.
- [2026-09-05 04:30] RECORD (**ROWS 11 + 39 — B3 Agorastore IS LIVE IN CONFIG, source #6, and
  row 39's narrow answer turned out to be a header drop**). Measured first: the JSON-array blob is
  not in the body at all — it is Mailgun's `X-Mailgun-Sid` HEADER, and the 24 zlib-compressed
  `/c/eJ…` tracking blobs per message inflate to no address (0 of 24). So the narrow stripper is a
  by-name drop, beside `x-mailin-eid` and `x-msfbl`, with a case in `test-scrub-eml.sh`. A SECOND
  scrubber defect showed on the first scrubbed capture: it parsed to an EMPTY body with zero links.
  Cause measured: Mailgun's MIME boundary is 60 hex characters, and the opaque-hex replacer numbered
  each OCCURRENCE, turning four copies of one boundary into four strings. One placeholder per
  distinct value now (a memo map), with a multipart case asserting the boundary stays one value and
  the parser still finds the link. Adapter: a facts `ref` group is the identity under
  `id_from: content` and waives the year/km floor (a stated id is the evidence), never the title
  requirement; ledger case verified red by mutation. Block: `family: auction`, `from
  support@agorastore.fr`, a lookahead separator on the card's shape, facts `title` + `ref`, no
  price/year/km groups — the stated costs are in the `_why`. Three captures scrubbed WITH THE NAME
  NEEDLES (the greeting is `Bonjour M. <name>`), parsed back (24/21/21 links, as raw), frozen;
  `AgorastoreFixtureTest` hand-reads all twelve lots, twelve distinct references; offline `doctor`
  → **`ok · 12 annonces · 25 ms`**. `PERMITTED_DOMAINS` gained `agorastore.fr` and
  `alerts.agorastore.fr`. **The lesson worth the most: parse a scrubbed capture back and compare
  the link count with the raw before committing it** — `scrubbed` is a statement about what left
  the file, not about what the parser can still read.
- [2026-09-05 05:10] AGREED (**four rulings in one `AskUserQuestion`, each on a measurement**).
  **A5 (row 6): keep the weights, push individually at score ≥ 55, the rest through the separate
  *"vérifié, score bas"* rollup as ruled 2026-09-01.** Measured first, over all 1046 stored MATCH
  snapshots re-judged offline with production's shape (commute 30 / 75 min, from the 411 cached
  communes — `scratchpad/a5-measure.php`): p10 23 · p50 40 · p90 54 · max 69; ≥40 → 51 %, ≥45 →
  25 %, ≥50 → 14 %, ≥55 → 10 %, ≥60 → 3 %. Components: commute mean 21/30 (dominant), surface
  5/10, lift 15 on 14 % only, rent headroom 1.9/15 (rents cluster at the ceiling), commune 25 pts
  earned by 15 of 1046 — region mode leaves it near-dead. `high_priority_score` 50 → 55 is the
  measured p90; rebalancing was offered and declined. **F20 / Q39 (row 40): a repair COMMAND** —
  `scout --domain=rent reclassify --reopen=<dedup_key>` clears the durable excluded reading on one
  named row, prints where the exclusion came from (own reading / twin / group sibling), and lets
  the next pass re-judge; explicit and logged, nothing automatic; the provenance-in-store route was
  declined. **Round-5 P2 (row 41): build the same-filter WARN** — when every card a source yielded
  in a pass fails the SAME hard filter, health warns; no band, no magic number. **Row 44 (In'li
  302): wait for the next occurrence** — the run log now names the redirect's `Location` (built,
  tested); no retry that would mask a shield.
- [2026-09-05 06:00] RECORD (**ROWS 40, 41 AND 44 BUILT**). **Row 40:** `scout --domain=rent
  reclassify --reopen=<dedup_key>` — `Store::reopen()` reports the provenance (own reading, twin
  reading with its source, group veto) and clears the row's OWN and TWIN readings; the group veto
  is reported and deliberately NOT cleared (it lives on the siblings' own readings, and the command
  says the next pass will reject again while a sibling says PLS). The rest of the same invocation
  re-judges the row on its own evidence — the first test caught that a re-opened row promoted to
  MATCH is NOTIFIED, so the run needs a delivering channel, exactly like every promotion. Unknown
  key → refused, nothing touched; `--dry-run` reports and clears nothing. **Row 41:**
  `Core/SameFilterWarning` — ONE implementation for both pipelines (the `escalate()` rule): every
  judged card counts into a per-source tally keyed on its disqualifier with numbers normalised;
  when every card of a source (≥ 3) failed the SAME filter, `RunResult::$warnings` /
  `VehicleRunResult::$warnings` carry one line naming the source, the count and the filter, printed
  by both CLIs beside the errors and never counted as a failure. §1 (`tenure:`) and vehicle-set
  (`exclu :`) rejections are deliberately excluded — the classifier working is not a drifted
  selector; a test on three PLS listings pins it (the tenure-guard fired on that test's `PLS`
  fixtures next to the word "match" — a false positive, stated). The car tally keys on the SOURCE
  name (the first test used listing names and caught it). **Row 44:** `HtmlSource` and
  `HttpJsonSource` name a 3xx's `Location` in the failure; a 3xx without one says nothing extra.
  Eight ledger cases (reopen half-done, dry-run clears, unknown key silent, warnings dropped on
  either pipeline, §1 counted, floor at one, "most" instead of "every"); mutation loop and full
  suite chained before the commit.
- [2026-09-05 07:00] RECORD (**ROW 45 — CI HAD BEEN RED FOR TWO DAYS AND NOBODY LOOKED**; the
  developer asked *"check the github ci and fix everything"*). `gh run list`: every push since
  `46546bc` (2026-09-03 08:56) failed — twelve runs, 113 errors + 22 failures each — while every
  local run and every live pass was green. Root causes, both measured: **(1)** the coliving
  exclusion's variable-length lookbehind (`(?<![0-9]\s\w{1,14}\s)`, PCRE2 ≥ 10.43). Local PHP
  8.5.9 and the container's 8.5.10 both bundle PCRE2 10.44; the runner's `setup-php` 8.5.10 links
  the system libpcre2 (10.42), so `criteria.json` failed the loader's compile check there and 135
  tests errored on `ConfigError`. Rewritten as a negative lookahead from the string start —
  `^(?!.*(?:\b\d+|\b(?:une|…|six))\s+(?:\w{1,14}\s+)?chambres?\b).*\bchambres?\b(?!…)` — and
  **measured identical over every stored title: 73 fire / 73 fire, 0 only-old, 0 only-new**.
  `PortablePatternsTest` now scans every configured pattern in all four config files and refuses
  a lookbehind with a non-fixed quantifier; its self-test flags the exact shape that broke CI and
  passes the replacement and the fixed-alternatives shape. The ledger's coliving expression was
  retargeted at the new line (verified: applies, and the mutated JSON still parses). **(2)**
  `CredentialsNeverReachATraceTest` assumed the development ini; the runner ships the production
  ini (`zend.exception_ignore_args = On`), so its premise failed while the guarantee held for
  free. It now SETS the printing runtime (`ini_set` of both directives, restored in `finally`;
  the child probe gets the same preamble) — proven by running the class under
  `-d zend.exception_ignore_args=1` locally: green, where it was red. Nightly ledger: also red on
  the same cause; dispatched on demand after the push so today's verdict is real.
  **A third instance of the same premise** surfaced only once the suite was green: the
  `tests/test-dump-eml.sh` shell probe ("an argument DOES reach the trace on this PHP") — fixed the
  same way, every `php` in it carrying both `-d` directives, proven under a shim forcing the
  production ini (17/17 from 16/17). **Fast job GREEN at `3f8fc42`** (run 33945957335: suite +
  guards success), the first green push since 2026-09-03 08:56. Ledger re-dispatched on that SHA.

- [2026-09-05 13:00] RECORD (**C2 ROUND 6 — NOT CLEAN ON ALL THREE LENSES; every finding fixed**).
  Round 6 ran over `5e68c24..878851d` (the A5 freeze). Grades as given: correctness P1+P2,
  resilience P1+P2, completeness P0+P2+P3; reports in `var/claude/c2-round6-*.md`. The two-clean
  counter stays at **0**. Disposition, each with its own tests and ledger cases:
  **RES-P1** — `FixtureSecretsTest::allForms()` and `tools/scrub-eml.php` were two copies of one
  decode cascade and the copy was one decode short, so `base64(percent-encoded(address))` passed CI
  while the tool refused it. One implementation now, `Scout\Core\RecoverableForms`. Measured while
  writing the ledger case: an ASCII address never base64-encodes to `+` or `/`, so the in-loop
  percent-decode is NOT isolable — the case removes both percent passes together and says why.
  **RES-P2** — a queued row is *matched and nobody was told*, which is also what a FAILED push
  leaves behind and, with no gate configured, the only thing it can be. Both drains now re-score and
  split: at or over the line (or no gate) the row is pushed individually and marked `MATCH`; only a
  row that really fell short is rolled up. `pushRetries()` is ONE method per domain called from the
  verb AND the floor, with a ledger case per call site.
  **COR-P1** — two below-gate copies of one flat, direct route and agency copy, were both queued and
  both announced in the same mail: the pipeline's twin cover only fires on a copy that was PUSHED.
  Collapsed at the drain, in both orders, marking every key of the pair. A `sources.json` the drain
  cannot read is VOICED and the entries pass through uncollapsed — §1's only landing zone must not
  be taken down by a config error, and a duplicate announcement is the pre-fix behaviour.
  **COR-P2** — a rollup-only rent mail carried `NotificationKind::DIGEST`.
  **COMP-P0** — this file and the README claimed THREE drains for the low-score queue; the code has
  two, because the end-of-pass emission calls `formatter->digest($batch)` with one argument and only
  COUNTS what it queued. Both corrected, README § Deploying it gained the `--once` cron step, and
  both pass lines and both `doctor`s now name the drain the RUN MODE actually has.
  **COMP-P2** — `Store.php`'s docblock still said `DIGEST < MATCH`. **COMP-P3** — `/add-source` now
  carries a car-domain scope block. Full suite **2937 tests / 11626 assertions green**; applies gate
  762/762 after retargeting two expressions the `RecoverableForms` extraction and the `keys`
  refactor had orphaned. **The two figures in the sentence above were stale when written** — the
  frozen tree gives `OK (2937 tests, 11626 assertions)` and 762 applying expressions, not 2925 /
  11582 / 756; corrected here rather than left, because a record that misstates the tree it
  describes is the drift this plan's own rule about written counts warns against (C2 round 7).

- [2026-09-05 13:30] RECORD (**A LIVE SILENT DEFECT FOUND BY THE SOURCE AUDIT THE DEVELOPER ASKED
  FOR — an RFC-legal `Date` was refused, and it cost PAP its observation time AND its feed
  verdict**). The ask was *"retest all emails to see if we missed anything, all sources to get if we
  missed any information"*. Method: per-source NULL rate over every field of the stored snapshots,
  both domains, plus one live `doctor` on each. **RFC 5322 writes the day as `1*2DIGIT`** and
  `EmailMessage::parseRfc2822()` carried four masks, all `d` — so `Sat, 5 Sep 2026 09:19:13 +0200`,
  which is what PAP actually sends (VERIFIED by pulling two live messages through
  `tools/dump-eml.php` and reading the header, not inferred from the dates), round-tripped to `05`
  and was refused. Measured: every PAP row first seen 1–5 September has `observedAt = NULL` (36 of
  86) while every row to 31 August has one, `source_runs.feed_newest_at` for pap is frozen at
  `2026-08-31T15:24:29Z`, and the live doctor reported **`pap feed_silent` — "rien envoyé depuis
  4 jour(s)" on a feed delivering daily**. Two silent failures at once: the store's stale-sighting
  guard (the 429-history-row defect) had no instant to compare, and a health verdict was false —
  hard rule 2 from both ends. The mask set is now the RFC's grammar (optional day name × 1-or-2-digit
  day × optional seconds × `O`/`T`), still round-trip strict, with the counterweight asserted
  (a mismatched weekday, `31 Sep`, `tomorrow` and `+2 days` all still refused). One parser serves
  `sentAt()` and `ImapMailbox`'s feed reader, so both surfaces are fixed at once.

- [2026-09-05 13:45] AGREED (**three rulings in one `AskUserQuestion`, each on a measurement**).
  **(1) The nightly ledger is SHARDED, not merely given a bigger budget.** Measured: not one
  completed run in eight days — four CANCELLED at the 240-minute cap (258 → 527 → 750 cases in three
  weeks) and four failed on the row-45 CI cause; seven issues open and none closable. GitHub's
  hosted ceiling is 360, so raising had nowhere left to go. Six-way matrix on the case index,
  `fail-fast: false` (one shard's finding must not cancel five others), each shard uploading its log,
  and the alert moved to ONE aggregating `sabotage-alert` job so a bad night still opens a single
  issue and a good one still closes the whole backlog. `issues: write` moved with it — a shard that
  only runs the ledger holds no write token. **(2) leboncoin stays as it is**: it has sent nothing
  since 2026-08-26 (rent) / 2026-08-27 (car), both sources report `broken` on 248 and 164
  consecutive empty runs, and only the developer can re-create a portal alert — recorded, not
  configured away. **(3) In'li's 8 % missing postcode is recorded, not repaired**: 48 of 600 rows
  have no `cp` and in region mode that is an outright reject, though their communes are stated and
  all are Île-de-France. The card `cp` was removed on 2026-09-02 when In'li changed its URL format,
  so the postcode now rests only on the detail page's title — the single-selector risk this file
  already records. In'li is answering `HTTP 302 → /maintenance` right now, so nothing can be probed
  live; a commune→postcode fallback and forgiving a null postcode in region mode were both offered
  and declined, the second correctly (it re-opens the fail-open shape region mode was guarded
  against). **Also measured and NOT acted on beyond a note:** ParuVendu's `facts_pattern` loses
  year, mileage, fuel and body together on 15–17 of ~132 cards (11 %, confirmed live and in the
  store) because it requires `body - fuel - Année …` and real cards write `Année 2020 - 80 237 km`;
  `IMAP_MAX_MESSAGES` was raised 250 → 500 in `.env` after the live doctor reported seloger's window
  TRUNCATED (361 messages, 250 read).

- [2026-09-05 16:30] RECORD (**C2 ROUND 7 — THREE OPUS LENSES, NOT CLEAN, AND ONE FINDING IS A §1
  BREACH**). Span `878851d..b692570`, frozen; reports in `var/claude/c2-round7-*.md`. Two P0s,
  three P1s, ten P2s, eight P3s. The two-clean counter stays at **0** — round 8 must run against a
  new freeze, and the lenses said so themselves.
  **P0 (correctness) — the drain is the THIRD verdict surface and it read one §1 signal.**
  `pendingLowScore()` selects the row's own `tenure` and nothing else; `Pipeline` and `reclassify`
  both refuse on the PERSISTED twin and group readings. A flat whose twin was judged `PLS`, or
  whose cluster held a `PLS` sibling, was pushed as an individual MATCH and marked MATCH — which
  cannot be demoted, so it left the queue for ever. Proven by executed probe on both vetoes. Fixed
  ABOVE the retry/rollup split (both arms announce), UNKNOWN twin included.
  **P0 (both lenses) — the car drain computed a REJECT and discarded it**, announcing an
  `accidenté` car on the stored score with a reason line that said the snapshot was absent when it
  had decoded cleanly. Reachable with no forged state: the excluded set was widened in code on
  2026-08-31.
  **P1 — collapse before the split.** Per-list collapse missed the twins that STRADDLE the gate:
  one entry in each list, `collapseTwins()` returns at `count < 2`, and the flat went out twice in
  one drain, the agency copy taking the headline whenever it scored higher.
  **P1 — a remainder line must stay silent when there is no remainder**; `overflow()` counted every
  queued row against the rollup list alone, so every retry read as still pending, on both domains.
  Every existing assertion proved the line FIRES and none proved it silent.
  **The P2 worth carrying: one finding was true of the syntax and false of the leak.** Three
  `RentScout` warnings print `$failure->getMessage()` unredacted — and `ChannelError::__construct()`
  redacts, `Notifier::send()` returning nothing else. That guarantee had NO test (deleting the
  constructor's `Redact::text()` left the suite green); it has one now. Chasing it found a REAL
  defect: `DeliveringChannel` threw `new ChannelError(<one argument>)`, a TypeError that `Notifier`
  wraps — so every refusal test passed on a wrapped TypeError and the refusal path was never
  exercised as itself.

- [2026-09-05 16:45] RECORD (**THE SHARDED LEDGER COMPLETED FOR THE FIRST TIME IN EIGHT DAYS, AND
  NAMED NINE GUARANTEES WITH NO TEST BEHIND THEM**). Run 33968248059 at `1c9f8aa`: six shards, all
  finished, **745 detected / 9 undetected**, and the aggregating alert opened ONE issue naming each
  case with its shard — the whole mechanism working end to end, on the first dispatch after the
  change. The partition was independently proven exact by a lens: `126+126+126+126+125+125 = 754`,
  zero duplicates, zero gaps. **The nine are the round's real inheritance**: several are §1-adjacent
  (the twin veto's transitivity, the twin's own group veto, the pipeline veto reading only this
  pass's harvest) and one is a secrets guard (the fixture-secrets guard blind to quoted-printable).
  Two of the nine are round-6's own and are already closed by the round-7 work. They are being
  re-run at HEAD one by one; each survivor needs a test, which is exactly what `undetected` means.

- [2026-09-06 11:55] RECORD (**THE LEDGER'S FIVE — AND TWO OF THEM WERE THE LEDGER LYING TO
  ITSELF**). Run 34019773860 at `3422225`, six shards, all reporting: **754 detected / 5
  undetected**. Three were real gaps and got tests. Two were BROKEN CASES, which is the more useful
  half: `run_sabotage()` binds `expr="$3"` and reads no further, so a case written as two quoted
  expressions seeds only the first. The quoted-printable case had additionally stopped naming its
  own guarantee when the cascade moved to `Core\RecoverableForms` on 2026-09-05 — it mutated an
  ASSERTION in `FixtureSecretsTest` into `assertContains($content, $forms)`, trivially true — and
  `tests/test-sabotage-applies.sh` could not see it, because the string still MATCHED. The other
  was a **§1 case** reporting `ok` on its first expression while the `mixedTenure && !$detailRead`
  arm was never seeded at all. `run_sabotage()` now ABORTS on a fourth argument, the same rule it
  already applies to a shard spec that selects nothing. Retargeting the first also needed BOTH
  decodes removed: `$headersUnfolded` equals `$message` when no header is folded, which is exactly
  the self-test's content, so removing one leaves the identical form in `$forms`. **Two premises
  earned their keep and one first attempt was wrong in the way this file keeps recording**: the
  robots test's premise caught that `allows()` takes a path and not a URL (a URL answers `true` for
  everything, so both premises would have passed for the wrong reason), and the doctor assertion
  asserted the WORD `--watch` first — satisfied by the startup-refusal note and the rollup line, so
  it reported `undetected` until it asserted the CLAUSE. Each of the five verified red then green
  individually. The tracing JIT aborted a scratch baseline again mid-run: ledger runs here need
  `PHP_INI_SCAN_DIR` with an `opcache.jit=off` overlay BESIDE the phpbrew dir, never replacing it.

- [2026-09-06 14:10] RECORD (**THE DISPATCHED LEDGER CONFIRMED THE FIVE AND EXPOSED A SIXTH THAT
  HAD NEVER BEEN RELIABLY DETECTABLE**). Run 34026474165 at `59b413e`: **758 detected / 1
  undetected**, all six shards reporting — every one of the morning's five now detects. The
  survivor, *"detail fetches stop being paced"*, had reported `ok` at `3422225`, so it looked like
  a regression and was not one: `HtmlSourceDetailTest` asserted `elapsed >= 50ms` around a fetch,
  and a slow machine clears that floor with the `usleep` deleted. It flipped on the ONE shard that
  took two hours where its five siblings took seventy minutes. **A timed-out case lands in the same
  `failed_labels` list the alert renders as `undetected`**, so the shard log was read before
  concluding anything — it says `SUITE STAYED GREEN`, which is the opposite verdict from the
  timeout the two-hour runtime suggested. `DetailHydrator` takes an injected sleeper now, the same
  seam `Core\Pacer` has, and the rewrite found the assertion weak a second way: the real behaviour
  is THREE sleeps, one per hydration, and a lower bound is satisfied by any one of them — so a walk
  that paced its first request and burst through the rest would have passed. The count is asserted.
  `HtmlSource`'s own page-walk `usleep` is left unwired and recorded under Known issues.

- [2026-09-06 15:20] RECORD (**A MASKED BACKSTOP IS NOT A COVERED ONE — found by the 6C advisor
  pass, which the ledger structurally cannot find**). Joining the §1 case's second expression in
  (`6a596d0`) made the case complete and proved nothing about the arm it added: expression 1 still
  reddens the suite, so the case reports `ok` either way. Measured alone, expression 2 left the
  WHOLE suite green — 2963 tests, the 130-case corpus and the surface matrix included. The arm is
  `route()`'s `mixedTenure && !$detailRead ? DIGEST` mirror, and it is unreachable through
  `classify()`: every `Tenure` is exactly one of eligible, excluded or UNKNOWN, so reaching that
  line means eligible-and-below-floor — the fail-closed rule's own condition — and the rule has
  already rewritten the tenure to UNKNOWN, which `route()` answers three branches earlier. **Kept
  and pinned rather than deleted**: it goes live exactly when someone weakens the rule above it,
  which is the §1 change most worth surviving. Reflection is the only way in — asserting through
  `classify()` would assert the RULE a second time, and the mirror's value is being independent of
  it. Isolated expression verified green before and red after. **The general lesson: a compound
  case's verdict is its FIRST detecting expression, so each must be measured alone or the joined
  case reports coverage the later expressions do not have.**

- [2026-09-06 15:55] AGREED (**round 8 tier: `advisor()` FIRST, then the three-lens panel**) — the
  developer's answer to the standing per-gate question, chosen over the panel alone. The order is
  the point: the advisor pass lands in the transcript the lenses inherit, so a shape problem is
  named before three agents spend a round on it. **Freeze: this commit.** Span under review is
  `b692570..HEAD` — 10 commits, 24 files, +1406/-105 — i.e. round 7's own fixes plus everything
  landed 2026-09-06 (the five ledger gaps, the arity guard, the pacing seam, the masked-backstop
  mirror, and the bookkeeping). Operational surfaces are touched (`src/php`, `config/car`,
  `tests/`), so the mechanical carve-out does not apply and the tier is MAXIMAL. A clean round
  moves the two-clean counter to **1**; any finding resets it to **0** and round 9 needs a new
  freeze. Reviewers are spawned UNNAMED and probe in pinned worktrees, never the live tree.

- [2026-09-06 16:35] RECORD (**THE ADVISOR PASS RAN FIRST AND MOVED THE FREEZE TWICE**). Three
  pre-spawn checks, in the order it gave them. (1) **Round 7's 23 findings all have a disposition**
  — every P2 and P3 named in `676602a`'s message against the three reports' own headings — but the
  commit itself **had no plan row**, so the largest fix of the round was invisible to every count
  this plan reports; row 52 now carries it. (2) **`HtmlSource` held a `$sleeper` it never used**,
  thirty lines from its own bare page-walk `usleep`: the seam was added for the DETAIL sleep and
  the symmetric surface was recorded as a known issue rather than closed. Closed now (row 53) —
  and the count is the assertion, because a test that merely proves a pause happened passes just
  as well if page one is paced too, which doubles every source's gap. (3) **12 of the 759 ledger
  cases are compound**, 24 sub-expressions in all; each is being measured ALONE in a `cp -a` copy,
  because a compound case's verdict is its FIRST detecting expression. That sweep is in flight and
  is a COVERAGE question about the ledger, not a correctness question about the span — its
  findings are round-9 material, and the lenses are told so rather than left to re-find it.
  **Freeze moves to this commit.**

---

## Fragile implementations register (the developer asked; keep this list honest)

> **IDS ARE UNIQUE AND NEVER REUSED, and this note exists because they were.** On 2026-08-31/09-01
> four rows were appended as F10–F13 without checking those numbers were taken — the register
> already ran to F22, and F10–F15 were all live. A first repair renumbered the wrong side and
> collided again. The rows added then are now **F23 (SeLoger `Baisse de prix` empty title), F24 (no
> `pièces` anchor), F25 (compose wedge), F26 (fixture-backed doctor)**, and the originals F10–F15
> are untouched.
>
> **Commits `d60a183`, `67e2828`, `8590db7` and `94962d2` cite the old numbers** (F10 for what is
> now F23, F13 for what is now F26). Git history cannot be corrected, so it is recorded here
> instead; the in-tree citations in `config/rent/sources.json`, `PriceLedTitleTest`,
> `SelogerFixtureTest` and `tests/sabotage-check.sh` were updated to the new numbers.
>
> This is the concrete half of R6-8, which called the register's bookkeeping "a real breach of this
> file's own rule". **Before appending a row, read the LAST id in the table, not the last one you
> remember.**

| # | Surface | State | Where handled |
|---|---|---|---|
| F1 | PAP positional anchors (all FOUR params) | **CLOSED — re-measured 2026-09-02, and the count in this cell was the stale one C1 was raised to fix.** It read *"100% null on 08-29/30/31, 23 null rows, 19 notified MATCH"*; the store now answers **0 null communes on every day from 08-28 to 09-02** (`SELECT substr(first_seen_at,1,10), COUNT(*), SUM(commune IS NULL)` over `evidence_json`), so the rows healed on re-sighting exactly as F28 predicts. Originally: Two independent axes — the body layout changed, AND all four params anchor on `\(\d{5}\)`, which 3 of PAP's location shapes do not carry | closed — Track 1h; re-verified against the live store 2026-09-02 |
| F1b | PAP department-only / arrondissement variants | **CLOSED — 2026-09-04 bookkeeping (row 42); the closers had landed and the cell had not moved.** `Cergy (95)`, `Paris 16e` carry no 5-digit postcode, so title+commune+surface+rooms all failed together; 4 rows, all `REJECT` with an empty title, one root cause with F6's "4 pap". Track 1h's re-anchoring healed the four (F6 re-measured 0 pap on 2026-09-01) and `c95ddb8` (R6-1) lets `commune_pattern` read an arrondissement. Re-measured 2026-09-04 read-only: `SELECT source, COUNT(*) FROM listings WHERE title IS NULL OR trim(title)=''` returns **pap 0** | closed — Track 1h + `c95ddb8` |
| F2 | Bien'ici single-card "Une annonce" reader | **CLOSED 2026-09-01** — fixed by `5962ae6` (08-31 22:04, the `Pas de photo` separator). Measured over the store by cross-checking title-stated against extracted surface on every row that states one: **BEFORE 244 checkable / 6 wrong · AFTER 52 checkable / 0 wrong**. The 6 victims stay REJECTed for ever (T5B-2) | Track 1g — done |
| F3 | Extraction failures are invisible | **CLOSED — 2026-09-04 bookkeeping (row 42).** A configured pattern that misses yielded null "visibly" while nothing counted misses, so F1 ran 4 days unnoticed. Closed in three moves this cell never recorded: `PatternMissLog` (Track 1h, one adapter), F27b/Track 6-A3 (every html/json/detail extraction through `ListingMapper`, and the car adapter), and C2 r1 F-R1 (`581cbce`: counting became REPORTING — `escalate()` is one implementation, discovered by reflection). What F3 does NOT cover, stated in `CLAUDE.md` § "An extraction that fails": a PARTIAL miss rate (cityloger's 16 %) is silent by design, and a capture that succeeds without meaning anything (row 41's P2) is a different instrument | closed — `581cbce` |
| F4 | `postcode_pattern` + `title_pattern` on the CAR side | **CLOSED, and discharged more strongly than this row asked for.** `title_pattern` became READ (`77ea035`, against leboncoin's subject); the remaining two are **REFUSED AT LOAD** — `VehicleSourceLoader::UNREAD_PARAMS` throws a `ConfigError` naming the file to edit, so an inert param cannot be configured at all rather than merely being documented | Track 1c — done |
| F5 | Coliving exclusion | **CLOSED — verified in the shipped config 2026-09-02.** `exclude_patterns` carries `\bco[\s-]?living\b`, which covers all three spellings; the row described `\bcoliving\b`, which is no longer what ships. (A first verification pass grepped the config for `coliv` and found nothing, which reads exactly like the defect still being open — the bracket in `co[\s-]?living` defeats a substring grep. Check the pattern, not a fragment of it.) | closed — Track 1i |
| F6 | Empty-title rows | **RE-MEASURED 2026-09-01: 3 rows, not 7 — 2 seloger, 1 INLI (new, never recorded), 0 pap.** The PAP four are fixed (Track 1h's re-anchoring); the In'li one nothing had noticed. Store counts are CURRENT STATE — a title is rewritten on every re-sighting — so this does not contradict the earlier census, it supersedes it. **One of the two seloger rows was NOTIFIED as a MATCH on 08-27** with every `exclude_title_patterns` entry inert on it. **RE-MEASURED 2026-09-04 (row 42): 1 row, seloger; 0 inli, 0 pap** — T5B-9 (F24's second anchor) shipped 2026-09-01 and recovered the two SeLoger room rentals, and the In'li one has since been re-sighted with a title. The one left is not yet read; it is the regression test for whatever anchor comes next | **OPEN at 1 row** — T5B-9 shipped |
| F7 | La Centrale truncation | Email carries ~3 of 900+ stated cards; `FEED_SILENT` keys on message DATE, so health stays green while 99.7% blind | Track 2 step 4 (documented cost) |
| F8 | SeLoger `id_from: content` + a misread surface | A bad surface reading changes the dedup key → one flat can notify twice under two identities | Noted in 1g; same root class |
| F9 | n=1 separators/patterns (leboncoin rent, PAP) | Measured on one capture each; PAP already proved what that costs | standing; each new alert is the regression test |
| F10 | Generic `ROOMS_PATTERN` reads hex out of photo-URL UUIDs | `(?:T\|F)\s?(\d)\b` is case-insensitive, so `…90F8-…` → 8 rooms. **14 wrong on seloger, 6 notified; 7 on bienici.** Too LOW loses real matches, too HIGH clears `min_rooms` on a number nobody stated. **CLOSED by Track 1j** — `ROOMS_PATTERN` now opens `(?<![A-Za-z0-9])`, verified in the tree 2026-09-02. **Its successor is F31**, and the succession is the lesson: that anchor is blind to base64url, whose alphabet includes `-` and `_`, so the same scan was poisoned a second time by a tracking token. A fix measured against ONE alphabet is not a fix against the class | closed — Track 1j; successor F31 |
| F11 | Startup refusal reachable only under `--watch` | *FIXED 2026-08-31* — it was also consumed above the `isDue()` test, so a restart inside the beat interval destroyed it unreported; `doctor` now reports it without consuming | round-4 fix commit |
| F23 | SeLoger `Baisse de prix` yields an EMPTY title | **CLOSED 2026-09-01** (`d60a183`) — the pattern refused any candidate CONTAINING a `€`; it now refuses only one that IS a price. Measured over the store: 552 unchanged, 2 gained a title, 0 changed, 0 lost. Template frozen as fixtures 004/005 | — |
| F24 | A SeLoger card with no `pièces` line has no title anchor at all | **CLOSED 2026-09-01 (T5B-9).** The captured card of that shape was already in the store — schema v7 keeps the card text, so no Gmail capture was needed: both empty-title rows are room rentals whose title line is present and readable, sitting between the price line and a `140 m²` line. `title_pattern` gained a SECOND ANCHOR on the surface line (`m²` and the ASCII `m2`, since 128 stored titles write it). Trialled over all 619 stored SeLoger cards before shipping: **617 unchanged, 2 gained, 0 changed, 0 LOST**, the two gained being exactly the victims — and both are now rejected through the SHIPPED criteria, asserted with the description held empty so `\bcolocation\b` cannot deliver the verdict the title is supposed to. **Confirmed LIVE on the deployed image the same day**: `doctor --source=seloger` over **405 live cards names no `title_pattern` miss at all** (the log lists only patterns whose miss count is non-zero), so the anchor read every one and never fell back to the subject. That is the post-deploy evidence F9 asks for, arriving on the first pass rather than waiting for the next room-rental alert. Was: **LIVE, and the remaining half of F10** — the anchor IS the `pièces` line, so a card stating no room count (a room rental, a parking, an atypical ad) yields `''` whatever the `€` rule does. Two such rows; both REJECTED, by the description-matching `exclude_patterns` rather than the title ones — luck rather than a guard. Needs a SECOND anchor, and a captured card of that shape to measure one against | closed — T5B-9; the second anchor ships |
| F25 | `docker compose up -d` wedges on recreate and leaves a watcher DOWN | **LIVE, twice on 2026-08-31.** `stop_grace_period: 5m` + a renamed old container = the orchestration stalls; once it then failed outright on `Conflict. The container name … is already in use`. rent-scout was down ~13 min and nothing said so | **CLOSED 2026-09-04 — and the prose recipe was not the closure.** The redeploy note below has existed since 08-31 and the failure is precisely one a human reading `up -d`'s output cannot see, so a note is the wrong instrument. `tools/verify-deploy.sh` asserts the three things that output hides: every service compose declares has a container AND it is running (`ps -a`, because without `-a` a down service is simply absent — the silent-omission shape hard rule 2 is about, one layer into the deployment); that container runs the CURRENT image rather than one from three deploys ago (`src/` is baked in, so a green tree says nothing about what is executing); and no hex-prefixed leftover still holds a name, which is what kills the NEXT recreate rather than this one. Read-only. `tests/test-verify-deploy.sh` is its sabotage test — 7 cases through a stub `docker`, counterweight first, and a missing image exits 2 *"build it"* rather than 1 *"watcher down"*, because collapsing those two would make a forgotten build read as a broken watcher. **Its own first draft leaked state between cases** (`PS_ROWS=x out="$(run)"` with no command is an assignment list, not a temporary environment) so two cases passed on a failure they had not asked for, and a `${SERVICES:-…}` knob was inert against an empty value — both found by running it, both fixed. Wired into CI and pinned by `test-ci-workflow.sh`. Verified against the live deployment the same day |
| F26 | A fixture-backed `doctor` writes its run into the LIVE store | **HIT 2026-09-01, and it is the DOCUMENTED workflow that does it.** `MAILBOX_DIR=` swaps the mailbox, not the database, so a fixture run's item count joins the 7-day baseline every live run is judged against — it made car `leboncoin` report `broken` on a 5-annonce premise made of fixtures. Fixed in CLAUDE.md: every documented offline proof now pairs with a throwaway DB | closed, guidance fixed |
| F12 | Car heartbeat inside the pass closure | *FIXED 2026-08-31* — a throwing pass silenced the watcher entirely, the one state the beat exists to make visible | round-4 fix commit |
| F13 | Scrubber `To:`/`Cc:` display name, and any base64 fold ≤19 columns | *FIXED 2026-08-31* — two committed fixtures had shipped the subscriber's real name; a 19-column fold was written and reported `scrubbed` with the address one `base64 -d` away | round-4 fix commit |
| F14 | Three Bien'ici fixtures carried the subscriber's address behind a DOUBLE base64 layer | *FIXED `3d24525`* — detection in `8f0c526`, stripping once the QP-eating hex replacer was fixed; all three re-scrubbed, 0 of 15 fixtures leak four levels deep, and `FixtureSecretsTest` is now armed with the same recursive decode. HISTORY still carries the blobs — the developer's call (hard rule 7's new note) | closed, remote exposure stated |
| F15 | The refusal note is consumed at beat-COMPOSE time, not on delivery | *FIXED `ec17b74`* — An undelivered beat destroys it, and the commonest refusal IS a channel misconfiguration — so the beat that should carry the note is the one most likely to fail | closed — `ec17b74`, commit verified in the tree 2026-09-02 |
| F16 | Cron `--once` never clears the refusal note | *FIXED `ccc8498`* — `takeLastRefusal()` is called only in `watch()`, so `doctor` reports a fixed outage for ever while saying it will be carried on the next beat | closed — `ccc8498`, commit verified in the tree 2026-09-02 |
| F17 | Car `doctor` has no `pendingRefusal` | *FIXED `4503834`* — The gap round 4 closed for rent is fully open on car (`grep -c pendingRefusal`: rent 3, car 0) | closed — `4503834`, commit verified in the tree 2026-09-02 |
| F18 | A plain `grep` silently skips the Latin-1 PAP fixtures | `grep -c .` prints nothing and exits **1**; `grep -ac .` prints 145 on the two 08-26 captures and 281 on the two 08-31 ones (re-measured 2026-09-04 — the original cell said "exits 0", which is what makes the sweep read as *scanned, clean* rather than *not scanned*). Any grep-based "N fixtures scanned, 0 hits" sweep is unsound on this tree — use a byte-level scanner | **CLOSED 2026-09-04.** A method warning is not a mechanism, so it is one now: `FixtureSecretsTest::testTheGuardScansNonUtf8FixturesAndEveryPatternIsByteSafe` asserts the tree really carries non-UTF-8 fixtures (else the guarantee is untested), that **no credential pattern carries the `u` modifier** — with `u`, PCRE refuses a Latin-1 subject outright, `preg_match` returns `false`, this code reads that as *no match*, and every one of those files goes silently unscanned — and that a planted key in a Latin-1 haystack is still found. The `u`-versus-byte difference is proven on this machine's own PCRE rather than asserted. Two blind spots were stacked on the same four files; the guard's is closed and reviewers still owe `grep -a` |
| F19 | A §1 guard inside `twinClassification()` covered by neither the suite nor the ledger | **CLOSED — verified 2026-09-01; citations repaired 2026-09-04 (C2 r1 CMP-4).** The ledger case exists: *"the pipeline veto reads only THIS pass's harvest (a missing sibling launders the flat)"* mutates the `groupExcludedTenure()` argument of `Pipeline::clusterClassification()` to `null`. **Cited by SYMBOL, not by line, and that is the finding this row now carries its own record of:** it named `tests/sabotage-check.sh:3756` and `Pipeline.php:829`, and by the time a lens checked them the case had moved to 3837 and then to 3874 in the same fortnight, while `clusterClassification` was at 335/418 rather than 829. Two of three citations stale, in a register whose stated purpose is that its cells match the tree, and against this repo's own rule that a symbol survives an edit above it while a line number does not. This row read FIXED *and* OPEN at once, which is the R6-8 breach; a session re-verified it from scratch because of that. *FIXED `43778bd`* — the judging loop's `clusterClassification($classification, groupExcludedTenure($sighting->dedupKey))` is the only thing reaching an excluded tenure on an ABSORBED SIBLING OF THE TWIN's cluster. Mutating it to `null` leaves all 2 339 tests green AND pushes the agency copy of a PLS flat (proven by execution). Round 4 added ledger cases for the twin fact's write-across and read-across; this third surface has none — the same "one of two surfaces" shape as the P0 it was fixing | closed — `43778bd`, commit verified in the tree 2026-09-02; the ledger case is `tests/sabotage-check.sh` |
| **F20** | **Neither documented repair route for an over-merge/over-link actually works, and no command can re-open a durably-excluded row** | The judged verdict (carrying the group's or twin's excluded tenure) is written into the row's OWN `tenure`, which round 4 then made durable — so a veto is laundered into the row's own reading. `staleVerdicts()`/`pendingDigest()`/`replay` all skip it. Q39 corrected; the rejection reason also MISATTRIBUTES the PLS to "a previous reading of THIS listing" when it was read on the other track | **HALF CLOSED 2026-09-04 (`526d246`), and the halves are deliberately separate.** The MISATTRIBUTION is fixed: the reason now reads *"régime exclu (PLS) retenu pour cette annonce — origine non enregistrée (lecture propre, groupe ou autre voie) — conservé (§1)"*, so the tool has stopped claiming a provenance the column does not carry. That was the half actively sending an operator to the wrong listing — one had already cleared `group_key`, removed the excluded stranger, and watched the flat stay rejected by a message naming a reading that did not take place. Hard rule 9 at the reason layer. **The REPAIR ROUTE is still owed and is left owed on purpose**: storing provenance means a new column and a migration on the §1 audit trail, and a command that can re-open an excluded row is a route to re-admitting an excluded listing, which §1 refuses without a ruling. Making the sentence honest costs neither. `PipelineRunTest::testTheDurableExcludedReadingDoesNotClaimWhereItWasRead` pins it; a ledger case restores the old wording; Q39 records which half closed |
| F21 | An unrecognised `tenure` string silently releases a durable excluded reading | *FIXED `76251b4`* — it released the row's own reading, the group veto AND the twin veto together. `decodeTenure()` now refuses a non-empty value that does not decode; a NULL column still means nothing was said | closed |
| F27 | **The extraction-miss signal reaches ONE adapter of five, across both domains** | **LIVE, and it is the fix for F3 landing on one of several symmetric surfaces — the repo's named recurring defect, committed by the fix for the finding that names it.** `grep -c PatternMiss`: `EmailAlertSource` 5, `HtmlSource` 0, `JsonSource` 0, `DetailHydrator` 0, `VehicleEmailSource` 0. So inli, cdc_habitat, cityloger, logirep and all three car sources count nothing — a silently-null CSS selector or JSON path is the same failure as a missed regex. Measured live: **13 of 99 ParuVendu rows carry `body`+`fuel`+`year`+`mileageKm` all null** (one `facts_pattern` miss, identical count on all four fields) while `doctor` reports `ok · 3 annonces`. Cityloger carries 9 null surfaces of 60, **two re-sighted today**, cause undecidable from the store | **CLOSED 2026-09-02** — car half `78ff21a`, rent half Track 6-A3. (The census in this cell names `JsonSource`, a file that does not exist; the class is `HttpJsonSource`, and its count was 0 too — an empty grep reading as a measured zero) |
| F27b | The four rent `html`/`json` sources still count no extraction misses | **CLOSED 2026-09-02 (Track 6-A3).** `ListingMapper` is instrumented — the one funnel all four sources and `DetailHydrator` pass through — and `HtmlSource`/`HttpJsonSource`/`FixtureSource` implement `CountsPatternMisses`, which both CLIs already gate their report on. Proved end to end: `doctor --source=fixture_demo` on a throwaway DB now prints five per-field miss lines on a source that counted nothing. **Only a CONFIGURED field can miss** — logirep maps no `floor`/`elevator` and is 123/123 null on both, so without that guard every pass reports a permanent 100 % on fields nobody mapped (F30's shape, four sources at once). **Stated ceiling, and it retires this row's own promise about cityloger:** `total()` speaks only at 100 %, and cityloger's surface miss is 16 %, so the signal is SILENT on it. That question was settled instead by the one live fetch this row said it would cost — the page states `65 m2` on line 221 and the selector scopes to `div.tab-content`, which opens on line 268: a SCOPE miss, fix tracked separately | closed. **And it earned its keep on day one**: the first un-pooled deployed pass reported `inli cp 171/171 ← AUCUNE`, which is a REAL portal template change (In'li moved the postcode out of its URLs) that nothing else was watching — the F1 shape, caught. Partial-variant detection is still NOT covered |
| F28 | Nothing re-judges a `REJECT`, so a fix never rescues its own victims | **ACCEPTED AS A STATED COST** (ruling above). `reclassify` filters on `outcome` and reaches DIGEST/UNKNOWN; `staleVerdicts()` skips an excluded tenure; `replay` writes no verdicts. 32 rows: pap 21, bienici 6, seloger 5 — and 4 of the seloger 5 state a surface ABOVE the 50 m² floor, so they are real matches rejected as too small | closed by ruling |
| F29 | SeLoger's `link_host` is host-only and filters nothing | **DEFENDED, BUT NOT BY THAT PARAM.** Measured: 100 % of links in all five fixtures are on `click.by.seloger.com` (16/16, 19/19, 38/38, 17/17, 17/17), footer and unsubscribe included — so the PAP phantom-listing shape is available in principle. What actually prevents it is `card_separator` segmentation plus the no-information floor. Recorded because *guard* and *luck* are different things | watch; no work |
| F30 | SeLoger's `advertiser_pattern` misses 92.6 % of cards, permanently and quietly | **OPEN, and it is the cost of the F27 signal being honest.** First deployed `doctor` run: `advertiser_pattern 375/405 carte(s) sans résultat`, beside `residence_pattern 187/405`. The advertiser miss is not a template change — the Track 5b matrix already measured it as **N/A**: an ordinary SeLoger alert names no agency at all, so the pattern is asking for a field the template does not carry. On a full-window pass it cannot reach 100 %, so it will simply print a large ratio on every run for ever — **but that ceiling is a property of the window, not of the pattern.** The WARN is 100 % of ≥3, and a sparse read (the IMAP window truncated harder, or a quiet stretch where one agencyless 3-card alert is the whole pass) satisfies it exactly. So the reachable failure is a SPURIOUS WARN on a thin pass, not permanent silence — which is the worse of the two, because it fires the one signal F27 exists to give, on a pattern nobody can satisfy. Two readings, and choosing between them is a config decision, not a bug fix: either the pattern is right and 92.6 % is the honest shape of the source, or a pattern that structurally cannot be satisfied should not be configured. **Do not "fix" it by lowering the WARN floor** — that would fire on this row for ever and dilute the one signal F27 exists to give. **RULED AND CLOSED 2026-09-01/02: the key is KEPT and EXEMPTED, not dropped** — `EmailAlertSource::UNCOUNTED_PARAMS = ['advertiser_pattern']` ships, with `AdvertiserMissExemptionTest` beside it, and the reasoning is in the Decisions Log entry that OVERRODE the earlier drop ruling (the key feeds `LandlordRegistry` and is the whole mechanism of `dede8ac`; dropping it is a §1 regression). This row read OPEN until 2026-09-02, which is the reverse-drift C1 exists to fix | closed — verified in the tree 2026-09-02 |
| F31 | A generic reader scans a URL's QUERY, so a tracking token becomes the flat's surface | **CLOSED 2026-09-02 (Track 6-A6).** Found by the plan's own A6 query on the first day it was answerable, and the plan predicted the WRONG cause — it expected the positional repair the rooms reader got. The live row of `2026-09-02T05:24:51Z` stored **7 m²** for a flat whose card says `3 pièces . 64,25 m²`: `SURFACE_PATTERN` matched `7m2` inside `click.by.seloger.com/?qs=…zaw7m29jtx…` at offset 1029, beating the real figure at 1948. SEVENTH instance of *URLs are classified text* and the SECOND poisoning of this same first-match-wins scan — Track 1j's `(?<![A-Za-z0-9])` anchor is blind to base64url, whose alphabet includes `-` and `_`. Fixed by applying `RawListing::text()`'s already-ruled query-and-fragment strip (path KEPT) to the generic readers only; configured patterns and the link readers are untouched, and both boundaries are sabotage cases. Measured over 2 043 stored bodies: 26 surfaces and 4 room counts recovered, all seloger; rent, postcode, commune 0; the other six sources 0 each | closed. Victims report: F31b |
| F31b | The 26 misread rows are still wrong on disk | **REPORTED, NOT REPAIRED** — Track 6-A7, and the F28 ruling stands that only a report looks backward. `var/claude/track6-a7-surface-victims.txt`: 7 LOST (cleared every filter at their true size, never notified), 6 pushed with no surface at all, 4 room rentals the exclusions catch regardless, 9 failing the ceiling or floor at the true figure too. A row self-heals only while its alert is still inside the IMAP window, which for the 25–28 August victims it is not | closed by ruling |
| F22 | A re-scrubbed ParuVendu fixture had a 152-column quoted-printable line | *FIXED `3d24525`* — root cause was the hex replacer eating `=3D` escapes, not the re-scrub itself; the line was re-folded at a soft break under a decode-equality guard that refuses unless the payload is byte-identical. Max column 152 -> 77 | closed |

---

## Step U — Unification commit (first commit of execution; docs-only)

U0. Verify the six gate-bypass sentinels exist (read-only `ls`); verify `git config user.name` /
    `user.email`. Do NOT re-run `/gates-bypass`.
U1. **Archive FIRST** (before installing the new plan, so no glob can sweep it up):
    `mkdir docs/plans/archive`, then `git mv` exactly these 15 files into it — an enumerated
    list, deliberately NOT a `*.plan.md` glob:
    `bienici-email-alert` · `car-domain-first-slice` · `claude-bundle-cross-repo-audit` ·
    `core-tenure-classifier` · `finish-everything` · `leboncoin-email-alert` ·
    `milestone-1-pipeline` · `pap-email-alert` · `phase-2-detail-hydration` ·
    `phase-2b-inli-floor-lift` · `plafonds-tier-4` · `q34-digest-daily-floor` ·
    `scout-rename-and-car-domain` · `seloger-email-alert` · `transit-enrichment`
    (each `.plan.md`). Prepend to each:
    ```
    > SUPERSEDED (2026-08-31) by docs/plans/scout-unified-execution.plan.md. Kept for its
    > Decisions Log and measurements; do not execute from this file.
    ```
U2. THEN copy this file verbatim to `docs/plans/scout-unified-execution.plan.md`, replacing only
    the leading `> INSTALL RULE` block with:
    ```
    > SINGLE SOURCE OF TRUTH for all pending scout work (developer ruling, 2026-08-31).
    > Supersedes every plan in docs/plans/archive/. Rulings land in this file's Decisions Log.
    ```
U3. Update every citation of the moved files — 20 exact hits (re-run
    `git grep -on "docs/plans/[a-z0-9-]*\.plan\.md" -- ':!docs/plans'` to confirm before editing):
    - `CLAUDE.md` (5): lines ~867, ~911, ~912, ~1019, ~1299 → `docs/plans/archive/<same-file>`.
    - `README.md` (4): lines ~66, ~487, ~555, ~556 → same rewrite.
    - `docs/OPEN-QUESTIONS.md` (3), `docs/SOURCES.md` (3), `docs/ALERT-CAPTURE.md` (1) → same.
    - `config/car/criteria.json` (1) and `config/car/sources.json` (2, incl. `_comment`) → same
      (JSON strings; keep valid JSON).
    - `src/php/Adapters/Http/RobotsResolver.php:53` docblock → same.
    - `.claude/skills/scout-repair/SKILL.md:25` cites `docs/plans/claude-bundle-integration.plan.md`,
      which does NOT exist (pre-existing drift — the real file is
      `claude-bundle-cross-repo-audit.plan.md`). Fix to the archived real path, or remove the
      example if the sentence doesn't need it.
    Also: `docs/OPEN-QUESTIONS.md` mentions phorj's `docs/plans/MASTER-PLAN.md` (outside the regex
    above — uppercase, no `.plan.md`) — that is a path in the PHORJ repo, not this one; leave it
    alone.
- U4. Verify: `bash .claude/skills/scout-repair/drift-scan.sh` exits 0;
    `git grep -n "docs/plans/" -- ':!docs/plans'` shows only `archive/` paths (plus the phorj
    MASTER-PLAN mention); `php -r "json_decode(file_get_contents('config/car/criteria.json'));"`
    and the same for `sources.json` report no error; full PHPUnit suite green (the PHP docblock
    edit is comment-only, but run it anyway).
U5. Commit (`docs: unify all plans into scout-unified-execution.plan.md, archive the rest`), push.
    **Certification: STANDARD** (one reviewer, three lenses, one pass) — this commit touches no
    application source; the mechanical carve-out in CLAUDE.md § Certification applies.
    **This commit does NOT disturb Track 0's freeze**: round 4 reviews `b8a1687` in worktrees
    pinned at that commit; a later docs commit on `master` is irrelevant to it.

---

## Track 0 — Finish certifying `b8a1687` (before any Track 1 code except 1a)

> **CLOSED. THIS SECTION IS A RECORD, NOT A TO-DO** (banner added 2026-09-04, Deep row 34). Its
> three steps all ran: the filtered ledger, panel rounds 4 and 5 frozen at `b8a1687` and its fix
> commits, and the developer's ruling at the outcome gate. Everything after it — Tracks 1 through 6
> and the C2 round now in flight at `ede198e` — is downstream of that close. **Do not re-run these
> steps.** Two numbers in them have also drifted and are kept verbatim rather than silently
> refreshed, because the instruction they carry is the durable part: the filter selects *"6 of
> 574"* labels and the ledger now holds **656**, so a future filtered run must re-derive its own
> denominator before trusting any "0 undetected" — which is exactly what step 1 tells you to do.

1. Filtered sabotage ledger, six cases (record said five; it is six — five added + one modified
   expression):
   ```
   SABOTAGE_FILTER="survivor's row only|excluded reading is forgotten|judged verdict|short last line|car startup refusal|never read back" bash tests/sabotage-check.sh
   ```
   Plain `|`, double quotes (ugrep). Pre-check that the filter selects exactly **6 of 574** labels
   before trusting any "0 undetected". Run under the JIT workaround (see executor briefing).
   Note: `b8a1687` also touched `Pipeline.php`, `Store.php`, `RentScout.php`, `CarScout.php`,
   `scrub-eml.php` beyond these 6 cases; whether that rotted any pre-existing sabotage expression
   is `tests/test-sabotage-applies.sh`'s job (run it too), not this filtered ledger's.
2. Panel round 4, frozen at `b8a1687`, three lenses (`tenure-correctness-reviewer`,
   `source-resilience-reviewer`, `completeness-reviewer`), spawned UNNAMED, each in its own pinned
   worktree (`cp -a`, never symlink `vendor/`). Put a timeout/Monitor on each lens — round 2 lost
   a lens to a 5.5 h stall.
3. Outcome: clean → ask the developer (AskUserQuestion) whether round 5 runs for the second clean
   or the milestone closes here. Findings → fix, re-freeze at the new commit, repeat round 4.

---

## Track 1 — Defects found 2026-08-30/31 (own milestone, own certification, AFTER Track 0 closes)

> **LANDED. THIS SECTION IS A RECORD, NOT A TO-DO** (banner added 2026-09-04, Deep row 34). Every
> item below shipped and is inside the span C2 round 1 is reviewing (`7765997..ede198e`, 59 commits,
> 39 touching `src`/`config`/`tests`). The sequencing paragraph that follows describes an ORDER that
> has already been executed — read it as provenance for why the commits sit in that order, not as an
> instruction to start. The one thing still owed from this track is **1f's `min_price_per_m2`
> follow-through**, and even that is now only its digest-title half, closed 2026-09-04 as COR-F3.

**Sequence within Track 1: Track 2 step 0 (the scrubber fix) lands as the FIRST commit of this
milestone** — the 1g and 1h fixture captures depend on it, and it is a code commit (tool + test),
so it belongs after Track 0 closes like the rest of Track 1. **Then 1g and 1h** — both are
actively losing/corrupting real matches daily. 1a runs immediately (gitignored file, outside any
certification). Then 1c, 1f, 1i, 1d.

### 1a. Car domain sends no email — one-line, gitignored, run at execution start

- Root cause (confirmed twice): `config/car/criteria.local.json` channels =
  `["console","ntfy"]` — missing `"email"`. Add it. No `CAR_*` SMTP key exists or is needed;
  `SMTP_*` is set and proven by rent's 889 sent notifications.
- `SMTP_FROM` is shared, so BOTH domains send from the identical address — if the developer's
  Gmail filter routes by sender, car mail misroutes into the rent label. The filter must key on
  the subject prefix `[car-watch]` (`CarScout.php:367`). Check the actual filter rule with the
  developer when applying.
- Fix the stale `_notify` comment in `config/rent/criteria.local.json` in the same edit (it still
  claims the `file` transport is pending real SMTP creds; `SMTP_TRANSPORT` is already `smtp`).
- Verify: `docker compose run --rm car-scout test-notify` → a message lands in the currently-empty
  `car-watch` Gmail label, and in the CORRECT label.

### 1b. `.env.example` `CAR_*` keys — RETRACTED, do not touch

Every `CAR_*` key IS declared, commented (`#CAR_SCOUT_DB=…` etc., lines ~139–148), which is this
repo's convention for optional keys (`RFR_N2`, `SCOUT_MAX_PASSES`, `TELEGRAM_*` identical).
drift-scan S8 counts a commented declaration as documented BY DESIGN. **Do not "fix" S8** — that
would false-positive on every commented-by-design key. No new test needed (`test-drift-scan.sh`
line ~131 already proves the missing-key mechanism generically). Keys stay commented per the
Decisions Log default.

### 1c. Car geography — inert filter, and two silently-unread params

- `postcode_prefixes` is inert on both car sources (neither maps a location) — set it to `[]` per
  the Decisions Log default and reword the config comment to say the location filter is
  deliberately national until a source maps a postcode.
- `postcode_pattern` AND `title_pattern` are declared in `VehicleSourceLoader::PATTERN_PARAMS` but
  read by ZERO car adapters. **Do not configure either on any car source** until an adapter reads
  them (the PAP-inert-param defect class, rebuilt). Making them actually read is real engineering,
  OWED but not scoped here — record as a one-line owed item in `config/car/sources.json`'s
  `_comment` (or this file) for whoever next touches `VehicleEmailSource`.
- Verify: a `matchesLocation()` unit test confirming `[]` matches every postcode (coverage of the
  already-true behavior); no doc line anywhere claims the two params are read.

### 1d. Car brand penalty — mechanism RESOLVED 2026-08-31

Contract (the ruling): **listed brands score strictly lower than unlisted at equal specs; unlisted
brands are unaffected; the weight comes out of the existing 100.**

- Config: `weights` becomes `price:20, age:20, mileage:20, gearbox:10, fuel:10, body:10, brand:10`
  (sum 100; the price/body allocation is a DEFAULT APPLIED, not ruled — see Decisions Log; the
  ruled part is only that `brand` comes out of the 100). New key `brand_avoid:
  ["peugeot","renault","opel"]` — a LIST of disfavoured brands, deliberately NOT named
  `brand_rank` (no `body_rank` higher-is-better connotation, and no ordering among the three was
  ruled — equal treatment).
- Scoring: an unlisted brand gets the full `brand` share (10); a listed brand gets 0. Matching is
  case/accent-insensitive on the extracted make. A car with NO extracted make gets the full share
  (hard rule 9: unknown is not disfavoured).
- Blast radius to cover in the same change: `VehicleCriteriaLoader` strict-key validation,
  `VehicleCriteria` constructor, every existing `VehicleScorer` test expectation (the rebalance
  shifts every score), `config/car/criteria.json` comments.
- `high_priority_score: 70` (already flagged uncalibrated): after the rebalance, re-score the
  stored car snapshots; if the distribution supports it, set a reachable value; if the store is
  still too small, keep 70 and record "recalibrate after a week of real scores" beside it.
- Test: table-driven — each of the three named brands scores strictly below an unlisted brand at
  otherwise-identical specs; an absent make scores as unlisted; weights still sum to 100.

### 1e. Diesel/ZFE — no change (ruling recorded in the Decisions Log; nothing to build).

### 1f. SeLoger room-in-shared-flat — price-per-m² plausibility heuristic

Evidence: three real matched listings implausibly cheap for their stated size (Champs-sur-Marne T5
at 605 € and 620 € CC ≈ 6.9 €/m²; a bare T5 at 449 €). The discriminating sentence exists only on
SeLoger's detail page, which this source structurally never reads (following the per-recipient
redirect is a hard-rule-5 refusal). **Do NOT write "SeLoger cards have no description"** — they
have one (`EmailAlertSource.php:451`), and `exclude_patterns` genuinely catches most coliving
wording through it; this heuristic covers the cards whose text says nothing.

Design constraints (non-negotiable):
- Threshold derived from a GAP in the store's price-per-m² distribution, never a raw percentile
  (p5 = 7.9 €/m² would eat a genuine 200 m²/750 € house at 3.75 €/m² — a real notified MATCH).
  Exclude 1g-class contaminated rows (misread surfaces) from the derivation set first. Use
  6.9 €/m² as a sanity anchor for where the break lands, never as the threshold.
- Reads `effectiveRentCc()` (same field as `max_rent_cc`); stated cost in the config comment: inert
  on the ~157 HC-only rows (Logirep, leboncoin, PAP).
- Guard `surface > 0 AND rooms > 0` — not merely non-null (15 Logirep rows carry surface=0 rooms=0
  for parkings; `rent/0.0` would `DivisionByZeroError` the pass).
- Routes to DIGEST via the existing v8 `notified_as` machinery, never a hard reject; the digest
  reason line names the price-plausibility cause explicitly. Whether it deserves its own route
  instead of reusing DIGEST is decided explicitly at build time (CriteriaEngine's docblock says the
  tenure classifier is meant to be the only outcome-decider — don't blur that silently).
- One sabotage case: disabling the heuristic turns the suite red.
- Verification: the three known rows are already `notified_as='MATCH'`, so NO digest email will
  appear for them — verify by re-querying their stored `outcome` flipping to DIGEST.
- The `une chambre` lookbehind bug in `exclude_title_patterns` stays a store-trialed CANDIDATE
  (zero confirmed real misses; naive fix re-opens a known over-rejection). Trial over
  `SELECT title FROM listings`; ship only with zero flat losses, else record the residual.

### 1g. Bien'ici single-card alerts silently reject real flats — FIRST, live loss (5 victims and counting)

The 2026-08-25 separator fix was measured on the MULTI-card template; the single-card "Une
annonce" template still reads the alert's own criteria line ("… - 3 pièces min - 45 m² min") as
the flat's stats. Five real production victims (ext_surface=45 vs titles 77/80/68/58/56 m², the
last two on 08-31 morning) — all genuinely inside criteria, all silently REJECTed. A sixth row
with title "…45 m²" agrees with its extraction — a genuine 45 m² flat, NOT a victim; exclude it
from the regression set.

- Capture the single-card template as a fixture (production `evidence_json` of the victims + a
  fresh Gmail capture, scrubbed — after Track 2 step 0's scrubber fix).
- Fix the surface/rooms reader for this template (same positional-anchor discipline as the
  multi-card fix, applied to the shape that lacks the separator).
- Regression test pins title-stated vs extracted surface on the 5 victim rows (not the 6th).
- Note in the same commit: the identical misread class on SeLoger changes the `id_from: content`
  dedup key → double-notification risk (F8). One line, not a separate fix.

### 1h. PAP template change broke both positional anchors — NEW 2026-08-31, with 1g at the front

**Evidence (live store, reproducible)**: null-surface AND null-rooms per day went 0/5 (08-26),
1/16 (08-27), 2/5 (08-28), 6/6, 5/5, 8/8 (08-31) — 21 rows null since 08-28, ~18 still notified
MATCH (null passes the 50 m² floor by hard rule 9 — so sub-50 m² flats can now notify). The
template moved rooms out of the title line into a combined line BELOW the postcode
(`4 pièces - 80 m²`) and switched `EUR` → `€`:

- OLD: `Location appartement 3 pièces` ⏎ `Meulan-en-Yvelines (78250)` ⏎ `90 m²` ⏎ `1.150 EUR / mois`
- NEW: `Location appartement` ⏎ `Lardy (91510)` ⏎ `4 pièces - 80 m²` ⏎ `1.200 € / mois`

Current patterns (`config/rent/sources.json`, pap params) and exactly why each misses:
- `surface_pattern = ~\(\d{5}\)\h*\n\h*([\d.,]+)\h*m²~u` — requires the surface figure FIRST on
  the line after the postcode; that line now starts `4 pièces - …`.
- `rooms_pattern = ~^[^\n]*?(\d+)\h*pi[eè]ces?[^\n]*\n[^\n]*\(\d{5}\)~mu` — requires rooms on the
  line ABOVE the postcode (the title); rooms moved below.

Fix (config-only for the patterns; code for the health half):
1. Capture one new-format `.eml` (Gmail RAW → corrected scrubber, see Track 2 step 0) as fixture
   003. Fixtures 001/002 (old format) are the regression set — both must keep extracting.
2. Widen both patterns to accept BOTH shapes. CANDIDATES (validate against all three fixtures and
   trial over the stored PAP evidence before shipping — the repo's own rule):
   - surface: `~\(\d{5}\)[^\n]*\n[^\n]*?([\d.,]+)\h*m²~u` (the figure immediately before `m²` on
     the line after the postcode — captures 90 in the old shape, 80 in the new, and can never see
     the criteria line's 45, which sits above the postcode anchor).
   - rooms: branch-reset both positions:
     `~(?|^[^\n]*?(\d+)\h*pi[eè]ces?[^\n]*\n[^\n]*\(\d{5}\)|\(\d{5}\)[^\n]*\n\h*(\d+)\h*pi[eè]ces?)~mu`
     (PCRE `(?|` keeps the capture as group 1 in both branches — verify the reader takes group 1).
   - Acceptance: 001 → 90 m²/3p (or its actual values — read the fixture, don't trust this line),
     002 → its values, 003 → 80 m²/4p; the criteria-line `45`/`3` are NEVER captured; a configured
     pattern that misses still yields null, never the generic scan (assert the counterweight).
3. **Health signal (the ruled half)**: count configured-pattern misses per source per pass and
   surface them through the ONE health funnel (`EmailAlertSource::health()` — the `feed_silent`
   precedent; doctor, pipeline and heartbeat all read it). Minimum contract:
   - per pass, per source: for each CONFIGURED pattern (`surface_pattern`, `rooms_pattern`,
     `title_pattern`, `commune_pattern`), the number of cards where it yielded null/`''`;
   - `scout --domain=rent doctor` prints the counts;
   - a pass where 100% of ≥3 parsed cards missed a configured pattern → health WARN (not BROKEN —
     cards still flowed; the portal changed its template, the F1 signature);
   - one sabotage case: break a pattern against the fixtures and assert doctor/health reports it.
4. No backfill of the 21 null rows (ruling). Stated cost: any sub-50 m² among them stays a wrongly
   notified MATCH in history.

### 1i. Coliving pattern gap — small, trialed, shipped only if clean

`Co-living - grande suite parentale…` (real notified MATCH 2026-08-30) and `Co living maison
partagee` both PASS every current exclusion. Candidate `\bco[\s-]?living\b` in
`exclude_patterns`; trial over `SELECT title FROM listings` (~1,790 rows) — ship only with zero
flat losses.

---

## Track 2 — Car source expansion (execute the existing research; do not re-research)

The exhaustive candidate investigation lives in `docs/plans/archive/scout-rename-and-car-domain.plan.md`
(dated 2026-08-26→29) — measurements stand, execute from here.

0. **DONE — `c95ddb8` and the commits around it, and this step now has THREE layers rather than the
   one it asks for** (banner 2026-09-04, Deep row 34). The `to` entry is in `$drop`;
   `tests/test-scrub-eml.sh` exists and is 51 checks, wired into CI; and the guarantee was
   afterwards found to be the wrong one — *"the address is absent"* passes on a file the address is
   one `base64 -d` away from, so the scrubber now decodes base64url runs and quoted-printable
   **before** it looks, and `FixtureSecretsTest` decodes QP and base64 bodies too. The two ParuVendu
   fixtures were re-scrubbed. **History still carries the blobs** — hard rule 7's stated cost, the
   developer's call. Kept verbatim below because the invocation line is still the one to copy.
   ~~Scrubber fix FIRST (prerequisite for every new capture, incl. 1g/1h fixtures)~~:
   `tools/scrub-eml.php`'s `$drop` list contains `delivered-to` but not `to` — the `To:` header
   survives with only the address masked (and only when an address argument is passed at all;
   invoking without one exits 0 printing "scrubbed" while doing nothing). Add `to` to `$drop`;
   add a `tests/test-scrub-eml.sh` case asserting the `To:` header never survives; re-scrub the
   two ParuVendu fixtures that carry the developer's display name
   (`tests/fixtures/car/paruvendu/2026-08-29-{001,002}-*.eml`) so no new copy enters future
   commits. History stays as-is (ruling). Every capture invocation:
   `php tools/scrub-eml.php in.eml out.eml takieddine.messaoudi.official@gmail.com [needles…]` —
   address REQUIRED, plus a first-name/username needle for templates that greet by name
   (leboncoin does).
1. **leboncoin-car — DONE 2026-08-31, commit `77ea035`.** 5 of 5 captures parse; every field read
   by hand off the subjects before being asserted. Two things the plan got wrong here, both worth
   keeping. It said **never `title_pattern`** — and this source cannot be built without it: its
   facts are in the SUBJECT (`<vendeur> vous propose <MARQUE MODELE …> à <prix> € à <Commune>`),
   while the body above the price line carries only the dealer's name, its rating and `vous
   présente ses bonnes affaires :`. The positional reader would have titled all five with that
   marketing sentence, and `gearboxFromTitle()` reads the title, so the two cards stating `DCT-7`
   and `*BOITE AUTOMATIQUE` would have scored as stating no gearbox. So `title_pattern` was made
   READ (against the subject) and left `UNREAD_PARAMS` in the same change, which is the discharge
   1c's comment asks for. And `make_model_pattern` alone was not enough: it matches the LINK, and
   leboncoin's is `/vi/<id>.htm` carrying neither make nor model — hence `make_model_source: title`,
   named rather than a link-then-title fallback. That matters to the SCORE: `brand_avoid` reads
   `make`, and an unextracted make scores 0 on the brand component (1d).

   **A latent cross-domain collision was found and guarded on both sides.** The rent and car
   leboncoin sources share a SENDER, a LINK HOST, and the `Voir l'annonce` string the rent source
   splits on. Nothing separates them but the subject, and they do not collide today ONLY because
   the vehicle alerts sit unlabelled in the INBOX while the rent source reads the alert folder —
   luck that expires the moment the routing filter is created. Both blocks now carry a
   `subject_pattern`, anchored on their own wording rather than negating the other's.
2. **AutoScout24 — BLOCKED ON AN INPUT, not buildable**: no per-listing alert has ever arrived
   (verified 2026-08-31 twice, the second time against the populated `car-watch/portails` label:
   only `autoscout24-news@` magazine issues and `autoscout24-info@` marketing — `Voitures de ville
   dans toutes les gammes de prix`, `Essai du Kia EV5` — never a per-listing alert). Wait for
   the first real alert; then capture, confirm the sender, build.
3. **La Centrale — MEASURED 2026-08-31, and it is NOT the config drop-in this entry assumed.**
   Three real alerts captured and scrubbed cleanly (`car-watch/portails`, twice daily). What the
   payload actually shows:
   - **EVERY link is an opaque tracking redirect** — `clicks.mail-alerte.lacentrale.fr/f/a/<token>~~/…`
     — carrying no listing id and no listing URL. `VehicleEmailSource` derives its identity from
     `basename(parse_url($link, PATH))`, so every card in a message would share one id, and cards
     across messages would each get a fresh one. That is SeLoger's problem exactly, and its answer
     was `id_from: content` — **which the CAR adapter does not have**. Building this source means
     porting content-addressing to `VehicleEmailSource` first, with the no-information floor and
     the within-message duplicate rule that travel with it. It is real engineering, not a config
     block, and the identity scheme must be chosen BEFORE the first enabled pass because nothing
     migrates a stored row between schemes.
   - A card reads `MERCEDES CLASSE C IV COUPE AMG` / `La Centrale 49 600 km` / `61 990 €` — so the
     facts are there, on separate lines rather than one facts line, and a `facts_pattern` of the
     ParuVendu shape will not read them.
   - **F7 confirmed and worse than recorded**: the subjects say `399`, `426`, `1071` new vehicles;
     the message carries a handful. Two subject shapes come from this sender (`NNN nouveaux
     véhicules correspondent à votre recherche` and `🚗 Votre prochaine voiture est peut-être
     ici…`), so a `subject_pattern` must admit both or deliberately pick one.
   - Polling stays REFUSED BY RULING (DataDome, hard rule 5).
4. **Agorastore — BLOCKED ON THE SCRUBBER, with the evidence measured.** It alerts daily from
   `support@agorastore.fr` into `car-watch/portails` and greets by name (`Bonjour M. <Prénom Nom>`),
   so the name needle is mandatory. `tools/scrub-eml.php` **REFUSES both captures**, correctly: the
   message carries `WyIyNjk4ZCIsInRha2llZGRpbmUu…`, which base64-decodes to the JSON array
   `["<list id>","<the subscriber's address>","<subscriber id>"]` — no JWT and no `eyJ` anchor, just
   an opaque blob with the address inside it. The verifier decodes and sees it; the strippers do
   not know the shape, so it refuses rather than writing a file the address is one `base64 -d` away
   from. **A generic "strip any base64 run that decodes to a needle" was written and REVERTED the
   same day**: it turned five of `tests/test-scrub-eml.sh`'s REFUSAL guarantees green-by-removal —
   the tool's whole safety model is *refuse what you cannot strip*, and a stripper broad enough to
   catch this is broad enough to silence the refusal for encodings nobody has seen. The next
   attempt must add a NARROW, shape-specific stripper and keep all 44 scrubber tests green,
   including the five that assert an unknown encoding still refuses.
   The `api.auctelia.com` polling route is unchanged and still needs checking against the auction
   ruling's closing-time requirement.
5. Skips, settled: Alcopa (refused both routes 2026-08-29), CapCar (blocked on the developer's own
   one-time browser check), Carizy (dead). Interencheres is MEASURED, pollable, low-priority
   engineering backlog — not blocked on any input.

---

## Track 3 — Full audit: four Gmail labels + both stores (mechanical, then case-by-case)

Method ruled by the developer: mechanical store audit + count reconciliation + targeted sampling —
NOT hand-reading ~2,600 mails. Findings are decided case-by-case WITH the developer, not built
unilaterally.

1. Label-level census (all four labels: `rent-watch`, `rent-watch/portails`, `car-watch`,
   `car-watch/portails`): count, sender and subject-template breakdown, cross-referenced against
   both `sources.json` `params.from`/`link_host` — flag any sender/template no configured source
   consumes (the leboncoin car-alert shape, generalised).
2. Structural audit over BOTH stores, complete not sampled: price-per-m²/per-room outliers;
   null/missing commune/location/title counts per source (incl. the empty-title rows — F6, whose
   count this line stated as `3 seloger + 4 pap` and which F6 itself RE-MEASURED on 2026-09-01 to
   **3 rows: 2 seloger, 1 In'li, 0 pap**; corrected 2026-09-02); per-source health history (`FEED_SILENT`/`STALE`, run-log gaps); outcome/
   notified_as distribution per source (flag 100%-UNKNOWN or zero-match sources).
3. Count reconciliation per source: the portal's own stated count vs what the run log ingested.
4. Targeted raw-email sampling only where 1–3 flag something.
### Track 3 step 1, the LABEL CENSUS — and the one it turned up

Message counts per sender, per folder, against what the configs consume:

```
                                  rent-watch/portails   car-watch/portails
alertes.seloger.com                      793                    0
no_reply@bienici.com                     214                    0
no.reply@leboncoin.fr                    208                  182
users-alertes@pap.fr                      51                    0
info@paruvendu.fr                          0                   41
info@mail-alerte.lacentrale.fr             0                   11
support@agorastore.fr                      0                    4
autoscout24                                0                    5
jinka                                     41                    0
```

Unmatched senders — mail no source consumes — are `jinka` (41, rent folder) and `autoscout24`
(5, car folder). Both are harmless rather than lost: since 2026-08-25 each source pushes its own
`FROM` into the IMAP `SEARCH`, so an unconsumed sender costs no other source's window. Jinka stays
out of scope (a 78-byte `text/plain`, and an aggregator needs its own §1 evaluation); AutoScout24's
five are newsletters, its per-listing alert still never sent.

**The 182 leboncoin messages in the CAR folder are not car alerts.** Running the source itself
against the live folder — the only test that settles it, because IMAP `SUBJECT` search cannot see
RFC 2047-encoded subjects and returned 0 for every probe — yields **0 listings**. The five real
`vous propose` alerts remain unlabelled in INBOX, which is what the owed developer action is about.

**And that run exposed F26.** It reported `broken · 6 runs consécutifs à vide alors que la référence
précédente était de 5.0 annonces` — a `SOURCE_BROKEN` verdict whose baseline was *my own offline
fixture run*, written into the live car store by `MAILBOX_DIR=… doctor`. `MAILBOX_DIR` swaps the
mailbox, not the database. Recovered by backing up (`tools/backup-state.sh`), deleting the one
provably-synthetic `source_runs` row by id, and re-running: `ok · 0 annonces`, which is the truthful
verdict for a source with nothing yet to read. Every documented offline proof in `CLAUDE.md` now
pairs with a throwaway DB.

> **A smaller thing found while backing up:** `tools/backup-state.sh` reported `0 annonces` for the
> car store. The copy is a full and integrity-checked one — no data risk — but it counts the rent
> `listings` table, and the car store keeps its vehicles in `vehicle_listings`. A confirmation line
> that says zero for a 3 536-row database is the wrong kind of reassuring.

### Track 3 step 2, the CAR store — and why a 100 % match rate is not a broken filter here

```
source     rows  no_title  no_snapshot  notified  MATCH  REJECT
autohero   3452  3387      3387         3449         62       3
paruvendu    84     0         0           84         84       0
```

**autohero's 3387 empty rows are the documented `--seed`, not a failure** — every one of them was
first seen inside a SINGLE hour (`2026-08-29T22`), and everything after it (6 rows on 08-30, 59 on
08-31) carries a snapshot and a real outcome. Seeding marks the back catalogue seen-and-notified so
it cannot flood the channel; `notified_at` being set is how that works.

**paruvendu never rejects, and that is the portal, not an inert filter.** Its 84 rows top out at
exactly `30 000 €` — the saved search applies `max_price_eur` before sending, the same shape as
Bien'ici on the rent side. The distinguishing evidence is autohero, which is a sitemap crawl with
no pre-filter: it rejects 3, and the boundary is exact —

```
35 990  REJECT      30 090  REJECT
33 990  REJECT      29 990  MATCH
```

That pairing is the whole point of auditing two sources rather than one. A source that never
rejects is exactly the shape a broken ceiling would take, and only a source WITHOUT a pre-filter can
tell the two readings apart. If autohero is ever dropped, the car ceiling stops being observable.

### The pattern-miss fix, confirmed on a LIVE pass (2026-08-31/09-01)

A fixture run proves the parser; only a live `doctor` proves the ratio an operator reads.

```
bienici   commune_pattern   117/364   ->   3/250
```

114 furniture segments left the denominator, which now counts LISTINGS (250) rather than segments
(364); 3 genuine misses remain. **seloger's `residence_pattern 201/399` and `title_pattern 4/399`
are UNCHANGED, and that is equally the point** — measured, that source carries only ~3 furniture
segments, so its numbers were genuine all along, and a fix that had moved both would have been
moving the wrong thing. (The 4 title misses are **F23/F24** above — this line cited `F10/F11`, the ids those rows carried before the 2026-08-31 collision, and F10/F11 are live rows about something else entirely. Corrected 2026-09-02.)

`doctor` exits 1 here and that is CORRECT, not a fault: it returns `$problems > 0 ? 1 : 0` and the
one problem is `leboncoin feed_silent` — true, that portal has sent nothing since 26 August and its
routing filter does not exist yet.

### Redeploying is not `docker compose up -d` — F25, hit twice on 2026-08-31

Both watchers set `stop_grace_period: 5m` and `WatchLoop` stops only after the pass in flight
finishes, so a recreate can sit for minutes. Compose renames the old container while it waits, and
that is where it goes wrong:

- **Attempt 1** failed outright — `Error when allocating new name: Conflict. The container name
  "/scout-car-scout-1" is already in use by container ec3ce4f13a26…` — `up exit=1`, rent-scout left
  in `Created`, i.e. NOT RUNNING.
- **Attempt 2** wedged for ~13 minutes with `d9272b63ebf1_scout-car-scout-1` in `Created` beside a
  still-`Up` original, rent-scout again `Created` and down.

**Nothing announced either.** `docker compose ps` (no `-a`) simply omits a non-running service, so
the failure looks like a shorter list — the same silent-absence shape hard rule 2 is about, one
layer down in the deployment.

Recipe that works, and check the IMAGE ID rather than trusting the command:

```bash
docker compose build
docker compose ps -a --format '{{.Service}} {{.Name}} {{.Status}}'   # -a, always: a down service is INVISIBLE without it
docker rm -f <any hex-prefixed leftover>                            # e.g. d9272b63ebf1_scout-car-scout-1
docker compose up -d --no-deps <service>                            # one service at a time
docker inspect --format '{{.Name}} {{.Image}}' scout-rent-scout-1 scout-car-scout-1
docker image inspect scout:local --format 'current {{.Id}}'          # all three must match
```

A watcher down is worse than a stale one: a stale watcher still pushes, wrongly; a stopped one
pushes nothing, and *nothing arriving* is exactly what a quiet market looks like.

### Track 3 — STARTED 2026-08-31. The structural half of step 2, over the rent store.

```
source       rows  no_title  no_snapshot  notified   MATCH DIGEST REJECT
seloger      529   4         0            241        227   4      298
inli         469   0         36           121         76   0      357
cdc_habitat  445   0         18            86         77   4      346
bienici      245   0         0            165        161   2       82
logirep      120   0         3             10          0  10      107
cityloger     60   0         1              1          1   0        58
pap           51   0         0             45         45   0         6
leboncoin      3   0         0              1          1   0         2
```

Read carefully, because two columns look like defects and are not. **`no_snapshot` is
pre-v7 rows**, not a failed capture — `evidence_json`, `outcome` and the commune all go null
together on exactly those rows, which is the documented non-backfill. And **`logirep`'s 0 MATCH /
10 DIGEST is correct**: its rent is `h.c.`, so `max_rent_cc` never fires and the ceiling is
unverifiable there by design.

Two findings that ARE real:

- **PAP's 21 null-surface rows have SELF-HEALED, and the plan's stated cost was wrong.** The
  Decisions Log says *"No backfill of the 21 null-extraction rows — stated cost, they stay null"*.
  Measured after 1h landed: **0 null surfaces and 0 null rooms on every PAP day, 26–31 August.**
  `Store::record()` rewrites the snapshot on every sighting, so a row whose ad is still published
  re-extracts itself on the next pass once the patterns work. The cost that DOES stand is the one
  nobody stated: the `outcome` and `notified_at` of the passes that ran while the surface was null
  are unchanged, so listings notified as MATCH on a null surface stay recorded that way — the
  evidence is repaired, the history of what was announced is not. A row for a delisted ad would
  keep its nulls; none did.
- **F23/F24 above** — SeLoger's empty titles, with the cause measured rather than guessed:
  `title_pattern` is `~^\h*(?!https?://)([^\n€]{2,80}?)\h*\n(?:\h*\n|\h*https?://[^\n]*\n)+\h*\d+\h*pi[eè]ces?\b~mu`.
  The `[^\n€]` class forbids `€` in the captured line — a guard against capturing the rent line —
  and a `Baisse de prix` card's own agency text begins `600€ TOUT COMPRIS – électricité…`, so it is
  refused and the title comes back empty. The other two rows have no `pièces` line at all, so the
  anchor is absent.
  **F23 IS NOW FIXED** (`d60a183`), and the route is worth repeating. The two `Baisse de prix`
  messages still in the IMAP window were captured — `php tools/dump-eml.php alertes.seloger.com 40
  var/claude/captures 'rent-watch/portails'`, note the sender is a DOMAIN not an address — scrubbed
  and frozen as fixtures 004/005. **Neither reproduces the failing variant**: both extract a title,
  because the `€`-leading rows had aged out of the window. What stood in for them is better than a
  synthetic guess — schema v7 stores the card body a verdict was formed from, so the failing layout
  was read back byte for byte and encoded in `PriceLedTitleTest`. Measured over every stored seloger
  snapshot: 552 unchanged, 2 gained a title, 0 changed, 0 lost.

  **F24 IS NOT FIXED**, and it is not the same bug: the anchor IS the `pièces` line, so a card
  stating no room count has no anchor at all regardless of the `€` rule. Fixing it needs a second
  anchor and a captured card of that shape to measure one against.

5. Deliverable `var/claude/audit-<date>.md` with the falsifiable checklist: per label and per
   source it MUST state (a) message/row count, (b) reconciliation result, (c) unmatched
   senders/templates, (d) structural outliers. Missing any of the four per label/source = the
   audit fails verification.

---

## Track 4 — Next milestone (design first, do not build blind)

> **BOTH ITEMS SHIPPED. THIS SECTION IS A RECORD, NOT A TO-DO** (banner added 2026-09-04, Deep row
> 34). Its gate — *"after Track 0 closes and Track 1 lands"* — was satisfied, and both items are
> inside the span C2 round 1 is reviewing. The vehicle tripwire landed 2026-08-31 as ONE hook
> covering both domains (22 checks in `tests/test-vehicle-guard.sh`, wired into CI); the store split
> landed 2026-09-01 as `Core/RunStore` (`4d49eda`, `bdffa44`). What is worth carrying forward from
> the split is not the plan but its scars, and they are recorded in `CLAUDE.md` § Gotchas: 39 of 607
> ledger expressions went inert because they target code INSIDE moved method bodies and so name no
> method, which the obvious "which expressions mention a moved method name?" query answered as **1**.

After Track 0 closes and Track 1 lands:

- `tests/test-vehicle-guard.sh` — the §1 tripwire (`tenure-guard.sh`) greps housing vocabulary
  only; the car domain's excluded-vehicle classifier has no tripwire. Same must-fire /
  must-stay-silent halves as `tests/test-tenure-guard.sh`. **DONE 2026-08-31.**
- Generic-store split: `VehicleStore` composes the rent `Store` directly. **DONE 2026-09-01.**

  **THE DESIGN PASS IS DONE (2026-09-01), AND IT SHRANK THE ITEM BY AN ORDER OF MAGNITUDE.** This
  entry used to read: *38 files reference the rent Store; **93 sabotage-ledger expressions** are
  path-anchored to `src/php/Rent/Store/Store.php`; the live `state/car-watch.sqlite3` already
  contains the composed rent tables … getting the migration wrong is a hard startup failure on a
  live domain.* Every number there is real and the conclusion drawn from them was wrong — this
  repo's own named failure, a true number attached to an invented cause. Measured:

  - **The car domain uses SIX methods of the rent `Store`** — `recordRun`, `health`, `shouldAlert`,
    `markAlerted`, `clearAlerts`, `journalMode`. That is the run log, source health and the alert
    cooldown. All generic; not one is housing-specific.
  - **6 of the 94 anchored ledger expressions touch that surface.** The other 88 test housing
    behaviour and do not move. `94` was never the blast radius of this change.
  - **It moves CODE, not DATA.** The rent-owned tables inside the car file are EMPTY — `listings` 0,
    `price_history` 0, `listing_detail` 0, `commute_cache` 0 — while the data that matters lives in
    `source_runs` (422) and the vehicle tables. `source_runs`/`source_alerts` are created
    `IF NOT EXISTS` and their schema does not change, so an extracted run log opens the same tables
    it already opens.

  **The ONE real design question**, and the only place a mistake is destructive: which schema-version
  key the extracted run log owns. The car file carries BOTH `schema_meta` (rent v12, written by the
  composed `Store`) and `vehicle_meta` (v1), and `Store::open()` refuses a schema newer than it
  knows — so a run log that keeps reading `schema_meta` inherits the rent version for ever, while
  one that ignores it must not trip the existing refusal. Settle that before writing code, back up
  first, and never run it against `state/car-watch.sqlite3` without the developer's explicit go.
- Its own MAXIMAL certification (frozen commit, two consecutive clean rounds), separate from
  Tracks 0 and 1.
- Verification: vehicle-guard both halves demonstrated; full suite + ledger green post-split with
  ZERO expressions pointing at a dead path (`grep -c "Rent/Store/Store.php" tests/sabotage-check.sh`
  before/after, reconciled); `state/car-watch.sqlite3` opens post-migration with row counts
  preserved on every table.

---

## Track 5 — Generalisation: every per-source fix is a candidate rule for ALL sources

Developer instruction, 2026-09-01. This repo's whole history is per-source repairs — `commune_pattern`
for SeLoger, the card separator for Bien'ici, positional anchors for PAP, prose readers for In'li —
and **each was measured on the source that hurt, then left there.** Nobody has ever asked the second
question. The entry point was the developer noticing a Bien'ici listing that is actually CDC
Habitat's.

### 5a. The advertiser is evidence, and nothing reads it — a live §1 breach

**Measured 2026-09-01 over `state/rent-watch.sqlite3`** (reproducible; the scan folds accents and
looks in `evidence_json`'s title+description):

| Portal | In'li | CDC Habitat | Immobilière 3F | RIVP | total |
|---|---|---|---|---|---|
| seloger | 16 | 7 | 2 | 1 | **23** |
| bienici | — | — | — | — | **0** |

All 23 classified **`LIBRE`, confidence 50** — the source default — and **21 pushed as `MATCH`**.
Six carry a v12 twin (4 `inli`, 2 `cdc_habitat`); **17 do not**, and that 17 is the fix's size.

> **THE FIRST VERSION OF THIS TABLE SAID 24, WITH ONE ON BIEN'ICI, AND THE EXTRA ROW WAS THE
> FAILURE CLASS THIS WHOLE FIX EXISTS TO PREVENT.** The scan looked for the alias `i3f`, which
> matched inside a **base64url JWT signature** — `…vgdb-d5xbqjfxsvc5tsiri3fo0ug2y4b…` — on a
> *Century 21* listing. An acronym found in opaque machine text: `Ce·lli·er`, `Plain-pied`,
> `?c=plai_plus` a sixth time, committed in the audit that found the bug. It is kept here rather
> than quietly corrected because the number was about to be quoted in a commit message.
>
> **Consequence: the developer's Bien'ici observation is NOT REPRODUCIBLE and the Bien'ici half of
> this item does not exist.** Zero of 254 stored Bien'ici rows name an institutional landlord, and
> a Gmail search over `in:anywhere` with no date restriction returns **zero** Bien'ici messages
> mentioning CDC Habitat, In'li, Immobilière 3F or RIVP, ever. The phenomenon the developer saw is
> real — 23 instances of it — and every one is SeLoger. Either the portal was mis-remembered, or
> the advertiser was a subsidiary trading under another name, in which case no anchor can see it.
>
> **And "Bien'ici puts the advertiser in the URL slug" was unfounded**, asserted from one example
> (`iad-france-800209`) without reading the census that was already in the transcript: the 254
> slugs are CRM vendors and agencies — `immo-facile` 30, `laforet-immo-facile` 16, `nestenn` 8,
> `iad-france` 7, `hektor-*`, `apimo`, `nockee-*` — and **no landlord**. If a landlord ever
> advertises there, the slug is the place to look; today there is nothing to anchor on, and
> building a body scan instead is what this design refuses. **Stated cost: Bien'ici is uncovered.**

**Why it is §1 and not a nicety.** CDC Habitat is a mixed-tenure landlord — `CLAUDE.md` says so in
its own words, *"social and intermediate stock on the same pages, sometimes in the same result
set"*. Its own source block is `mixed_tenure: true`, so a card of its stock stating no tenure goes
to the *à vérifier* digest. Routed through SeLoger it inherits `mixed_tenure: false` +
`default_tenure: LIBRE` and is pushed as a match. **Same flat, same evidence, opposite verdict,
decided by which mailbox it arrived in.**

**The user's premise was half right and the half matters:** this is NOT handled on SeLoger either.
`CLAUDE.md` records it as a known "residual" on the grounds that *"PLAI and PLUS are allocated by
commission and are not advertised on commercial portals; PLS occasionally is"*. That reasoning
covers a card from an ANONYMOUS advertiser. It does not survive a card whose advertiser announces
itself as a bailleur in the subject line.

**The advertiser is STRUCTURAL, which is what makes this safe to build.** Every hit is the message's
own opening line and subject:

```
CDC HABITAT vous adresse ses dernières exclusivités      <- ~201 such messages
IN'LI       vous adresse ses dernières exclusivités
NESTENN IGNY vous adresse ses dernières exclusivités
```

A vocabulary scan over the body is REFUSED — `au plus près`, `Ce·lli·er`, `En savoir plus`,
`Plain-pied`, `?c=plai_plus` are five paid-for instances of exactly that mistake, and a landlord
name in prose (`proche résidence CDC Habitat`) rebuilds it in reverse.

**Mechanism — a per-listing override of the SOURCE DEFAULT, nothing else:**

- New per-source param `advertiser_pattern`, compile-checked at load beside the other five. It reads
  the **SUBJECT**, not the segment — `matchParam()` runs over segment text and the SeLoger advertiser
  is not in it. The car domain already built this exact shape for its own `title_pattern`
  (`$fromSubject`); reuse that plumbing rather than inventing a second one.
- It joins the **PatternMissLog** counted set. When SeLoger renames the `exclusivités` template the
  miss must surface in `health()`, not silently revert this whole item — that is the F1/F3 lesson
  (PAP's anchors broke for four days and nothing counted the misses).
- `RawListing::$advertiser` (`?string`). It lives on the listing, not as a `judge()` argument —
  `scout --domain=rent reclassify` re-judges from the v7 snapshot alone, and a value passed
  alongside would be absent on every re-judge. This is the `commuteMinutes` lesson, already paid for.
  The snapshot encoder covers every constructor parameter BY REFLECTION, so it is carried for free —
  assert that rather than assume it.
- A recognised advertiser makes the listing inherit **that landlord's own** `default_tenure` /
  `mixed_tenure` — never a third invented treatment. That is literally the sentence *"the verdict
  must not depend on the route"* in code. Tiers 1–3 are untouched and still win: an explicit label
  beats an advertiser, always.
- The landlord table is an **EXPLICIT REGISTRY**, pinned by a test that asserts every institutional
  source block appears in it. Pure derivation from `sources.json` was the first design and **the 23
  rows refute it**: RIVP has no source block at all (measured out of scope, A14), so derivation
  leaves an RIVP-advertised card at the LIBRE default — the exact hole being closed; *Immobilière
  3F* maps to a block named `cityloger`; *IN'LI* does not fold to the key `inli` by any rule. So
  aliases and non-source landlords are needed regardless. The no-second-list concern is real and is
  answered by **pinning**, not by derivation — a test that fails when a source block is missing from
  the registry cannot drift, whereas a derived map silently covers only what happens to be enabled.
- **A recognised advertiser with NO source block inherits the fail-closed posture**
  (`mixed_tenure: true`, `default_tenure: null`) — i.e. digest unless an explicit label decides it.
  RIVP is predominantly social; guessing LIBRE for a landlord nobody has measured is the §1-dangerous
  direction.
- Outcome for a recognised mixed-tenure advertiser with no explicit label: **DIGEST**, via the
  existing v8 `notified_as` machinery. Never a hard reject (hard rule 8: silent over-rejection is
  invisible).

**Stated costs, all four real:**
1. The ordinary multi-card SeLoger alert names no advertiser per card, so this reaches the
   `exclusivités` template only. That template is where the landlords are, but it is not all of them.
2. No backfill. The 21 already-notified rows stay as they are; `reclassify` will re-judge them once
   the snapshot carries an advertiser, and it carries one only for rows captured after the fix.
3. **Bien'ici is uncovered** — it has no advertiser surface today (see the correction above).
4. **16 of the 23 are In'li, and they will now ALL digest, permanently.** In'li inherits
   `mixed_tenure: true`, an email-route card carries weak evidence, and its detail page can never be
   read on that route — so the fail-closed rule digests every one. That is correct under §1 and it
   is the majority of the affected rows: the developer will see the SeLoger match yield drop and the
   *à vérifier* digest grow by roughly that much. Named here so the change is not mistaken for a
   regression. In'li was itself proven not pure LLI (two live PLS listings), which is why this is the
   right answer rather than an over-correction.

**Tests:** corpus cases BOTH directions (advertiser-anchored card digests; a prose-only mention does
NOT flip an otherwise-clear card), the counterweight (an unrecognised advertiser changes nothing),
a round-trip through the v7 snapshot, and a sabotage case. Touches `src/php/Rent/Core` → MAXIMAL at
the milestone.

### 5b. The mechanism × source matrix — build it mechanically, then triage WITH the developer

Method mirrors the ruled Track 3 approach: measure every cell, write findings to
`var/claude/track5-matrix-<date>.md`, decide case by case. Do **not** build every row unilaterally.

Cells to fill, per source, both domains (the non-config mechanisms matter as much as the params):
criteria-line exposure (the PAP/Bien'ici `45 m² min` class — seloger and leboncoin have NO
positional anchors and rely on the first-match-wins scan); `link_host` path-vs-host (the PAP phantom
`/utilisateur/alertes` listing); `subject_pattern` presence; `exclude_title_patterns` reachability
(needs a non-empty title on every source); separator variant lines (Bien'ici's `Pas de photo`);
prose readers (`prose:floor` / `prose:elevator`, In'li-only today); `detail_map` presence on the
HTML sources; the advertiser surface from 5a; and the car domain throughout.

**The standing rule this leaves behind matters more than the one-off audit:** a per-source fix does
not land without a line saying which other sources were checked against it.

## Explicitly NOT in this plan (negative space — protects the fresh executor)

- **Do not "fix" drift-scan S8** (1b) — commented keys are documented by design.
- **Do not write "SeLoger cards have no description"** anywhere (1f) — false, and it would get
  `exclude_patterns` "fixed" into uselessness.
- **Do not configure `postcode_pattern`/`title_pattern` on car sources** (F4) — loaded, unread.
- **Do not follow SeLoger's per-recipient redirect at ingest** — hard rule 5; documented non-goal.
- **No CAPTCHA/anti-bot circumvention ever** — La Centrale/leboncoin polling, A15's shield: ruled.
- **`src/phorj/`** — on indefinite hold (2026-08-19). Not touched.
- **AL'in** — before any work: ONE logged-in look (developer's own browser) at what the account
  shows WITHOUT a NUR settles blocked-input vs §1 dead end. Obtaining a NUR runs through
  `demande-logement-social.gouv.fr` — out of scope by hard rule 5.
- **VPS/deploy kit** — confirmed not needed.
- **Editing any gate's own hook/config** — standing ruling; sentinels only, already in place.
- **Rewriting git history** for the name-in-fixtures incident — ruled: leave history, forward fix
  only (Track 2 step 0).

## Inputs still owed BY THE DEVELOPER (nothing else blocks)

1. AutoScout24: the first real listing alert (none has ever arrived — wait, don't build).
2. ~~AL'in: one logged-in look without a NUR.~~ **WITHDRAWN 2026-09-01** — AL'in is on indefinite
   hold like `src/phorj/` (developer ruling). Not an input; not work.
3. ~~CapCar: the one-time make-selector browser check.~~ **DONE 2026-09-01** — the developer created
   the alert. Now waiting on its first message, like AutoScout24.
4. ~~Gmail filter for leboncoin "vous propose".~~ **DONE 2026-09-01, verified** — all 5 messages
   carry `car-watch/portails`; confirmed over `in:anywhere`, no date or read-state restriction.
   The source is quiet (nothing since 2026-08-27), not broken.
5. ~~Track 0 round 4's close-vs-round-5 decision.~~ **RULED** — Track 0 closed uncertified.
6. ~~**Go to run against the live `state/car-watch.sqlite3`** (Track 4's store split).~~ **SPENT —
   verified 2026-09-02**: the live car file carries `run_meta` at schema 1 and the rent-owned
   tables (`listings`, `price_history`, `listing_detail`, `commute_cache`) are gone from it, so the
   migration has already run. Nothing here is owed. This entry stood after the fact, which is the
   same reverse-drift as the register rows — audit N8 flagged it and C1 discharges it.

**So ONE input is genuinely owed: AutoScout24's first real alert**, and nothing can be done to
hurry it. CapCar's arrived 2026-09-01 18:00, so Track 6-B1 is unblocked and waiting on nobody.

## Verification (whole plan)

- Step U: drift-scan exit 0; no non-archive `docs/plans/` citation left; JSON configs parse; suite
  green; STANDARD certification passed.
- Track 0: 6-of-574 filter pre-check, 6/6 detected, round-4 verdict pasted verbatim.
- Track 1: each item's own verification block above; plus full suite, `tests/sabotage-check.sh`
  (JIT workaround), and every `tests/test-*.sh` green before each commit; Track 1's own
  certification round after it lands (MAXIMAL — it touches `src/`, `config/`, `tests/`).
- Track 2: scrubber test red→green on the `To:` case; each new source's
  `scout --domain=car doctor --source=<name>` returning a real count offline against its scrubbed
  fixture; La Centrale's blind spot documented in its config comment.
- Track 3: the audit report satisfies the four-part checklist per label/source; reviewed with the
  developer before anything from it is built.
- Track 4: its own verification block above.

---

## Round 5 (2026-08-31) — NOT CLEAN, 25 findings across three lenses

All three lenses reported against `a3c09c4`. **Every number in the round-4 commit message was
independently re-run and verified true** (2 339 tests, scrubber 40/40, all `test-*.sh`, 585/585
expressions, drift 0/0/0, the 8-case ledger, 13 fixtures 0 hits). The findings are about what those
numbers do not cover.

Fixed and pushed during round 5: the recursive-decode P0 (`8f0c526`), the false-healthy car beat
(`b4de3dd`, found by two lenses), plus two corrections of errors introduced in round 4 — Q39's false
"reversed by one line" claim, and a `StoreTwinTest` docblock that asserted the opposite of its own
assertion.

**Still open, by theme.** Full detail in `var/claude/track0-round5.md` (gitignored scratch — the
register rows F14–F21 above are the tracked record).

1. **A fix landing on ONE of two symmetric surfaces — the round-4 P0's own shape, three more times.**
   `car doctor` never got `pendingRefusal()` (F17); the twin scan's third §1 surface has no ledger
   case (F19); the PII fix covers the working tree while the pushed history still carries it.
2. **Repair routes that do not exist** (F20). Both documented ones fail, and the same-track group
   veto has no OPEN-QUESTIONS entry the way the cross-track one now does.
3. **Fail-OPEN on an unrecognised tenure** (F21), and it is worse than F21 records: the same
   `Tenure::tryFrom()` releases the row's own durable reading, the schema-v4 group veto AND the
   schema-v12 twin veto — three §1 mechanisms, silently, on one case-flip or enum rename.
4. **Guards whose mechanism is untouched.** `refute()` in `tests/test-scrub-eml.sh` reports `ok` for
   ANY non-zero exit, including 2 and 127 — the round-4 fused-comment bug was fixed as an instance,
   not as a class, in the one file whose subject is a privacy guard. `FixtureSecretsTest` still has
   no name-or-address pattern, and `docs/ALERT-CAPTURE.md` never tells the operator to pass a name
   needle — so the next capture from a portal that greets by name reproduces the leak with both
   guards green.
5. **Dead safety code, by this repo's own definition.** `durableOwnReading()` at the judging step is
   now provably unreachable-as-a-change (deleting it, and instrumenting it to throw if it ever
   alters anything, both leave 2 339 tests green). The round-4 commit called it "left in place as a
   defence"; `Pipeline.php`'s own comment says such code is "worse than none". Remove or cover it.
6. **New code with no coverage**: the `:memory:` branch on both CLIs; and one re-scrubbed ParuVendu
   fixture gained a 152-column QP line, so it is no longer byte-what-the-mailer-emitted.

---

## Round 6 (2026-08-31) — NOT CLEAN, 21 findings; developer ruled "fix these, then move on"

Three lenses against `b8a1687..7765997`. Every number in every round-5 commit message was
independently re-run and verified TRUE; the findings are about what those numbers do not cover.

**The developer's ruling (2026-08-31): close round 6's findings, then START TRACK 1 rather than
running round 7.** Rounds 4/5/6 produced 19/25/21 findings and never approached the
two-consecutive-clean bar; each round costs ~5 h and the other four tracks were untouched.
**Track 0 is therefore CLOSED UNCERTIFIED, and that is a ruling rather than an omission.** What
stands in its place: the sabotage ledger (588 cases), the full suite, drift-scan, and live `doctor`
runs against the deployed image.

### Fixed

- **P0 — the twin veto was not TRANSITIVE.** Each twin contributed its own durable reading plus its
  own group veto but never its own TWIN-derived verdict, so an exclusion reaching a listing THROUGH
  a twin never reached that listing's OTHER twins: a second copy of a flat rejected as PLS was
  pushed as a MATCH naming the rejected route as the *voie directe*. The graph is now resolved to a
  fixed point across every edge BEFORE any survivor is judged — writing the judged reading back
  mid-loop is not enough, because sources are harvested institutional-first. **Stated cost:** the
  veto is now transitive over a connected component, so a chain A–B–C vetoes both ends even where A
  and C would never have been linked directly. §1's bias is toward not notifying; Q39 already
  prices pairwise over-linking as permanent.
- **P0 — the documented capture procedure defeated the address scrub** (needles before the address).
  See `5726222`.
- **P1 — a ledger case reddened on an undefined method**, found by all three lenses, and the CLASS
  behind it: `test-sabotage-applies.sh` now also checks every replacement names a live method. It
  found a second instance immediately.
- **P1 — the forced `--once` beat reported a hard-coded 0 notified** on both CLIs, re-introducing a
  defect the ledger already pins on the watch path; **the car forced beat swallowed its send
  failures**; and **the car half had no test at all** (deleting the branch left the suite green).
- **P1 — CDC Habitat was `broken / 0` for a day** on a message that named an untrue cause. Now
  `ok / 312 annonces`, verified live.

### Recorded, NOT fixed — the developer's ruling caps this milestone

| # | finding | why it is left |
|---|---|---|
| R6-1 | PAP `commune_pattern` cannot read an arrondissement (`Paris 16e`) | **CLOSED 2026-09-01** (`c95ddb8`) — the capture may now contain a digit but not start with one; all four frozen captures identical, reverting reddens 3 tests. The false `_why` (three patterns anchor on the postcode, not four) is corrected |
| R6-2 | A hard-wrapped PAP criteria line would still hand the search floor to `surface_pattern` | Unverified against any real payload; n=4 captures all put it on one line |
| R6-3 | The forwarded-as-attachment capture route defeats both secrets layers (`from` not dropped; embedded `message/rfc822` sits after the blank line; `Resent-To` in neither list) | Latent, not an active leak — the current tree is clean four levels deep |
| R6-4 | The wrapped-token stripper's left anchor is blind in quoted-printable (`?u=3D<blob>`), so it fails CLOSED on the very shape every real capture uses | Refuses rather than leaks, but the refusal is unresolvable — which invites hand-editing |
| R6-5 | Omitting the address makes the scrubber a silent no-op that reports success | **CLOSED 2026-09-01** (`c95ddb8`) — the address is REQUIRED, no opt-out. The load-bearing half was that the RECOVERABILITY check had no needle and passed vacuously. 6 assertions, both directions |
| R6-6 | The `=3D`-eaten escape is permanently baked into two ParuVendu fixtures (originals not in the repo) | Parse-identical; the tool is fixed so it cannot recur |
| R6-7 | QP line length regressed 4× in the Bien'ici fixtures (max 121 → 196) | Parse-identical: link counts and body lengths byte-stable |
| R6-8 | No Decisions Log entry for the round-5 fix commits; four register rows read FIXED and OPEN at once; `Pipeline.php:829` citation drifted; 7 dangling links inside `docs/plans/archive/**` | Bookkeeping. The first is a real breach of this file's own rule |

**Do not read round 5's verdict as a setback.** Round 4 fixed 19 findings and round 5 found that
several landed on one surface of two — which is the same defect class the milestone has now produced
at every round. That pattern, not any single finding, is the thing to fix: **before closing a
finding, enumerate every symmetric surface it has (rent/car, read/write, survivor/absorbed,
tool/guard) and say which ones the fix covers.**

---

## Track 6 — 2026-09-01 full-audit execution (all four clusters selected; rulings in the Decisions Log above)

> **Executor briefing.** Evidence for every item: `var/claude/audit-2026-09-01.md` (synthesis) and
> `var/claude/raw/audit-{rent-store,car-store,plan-vs-tree,config-coherence,gmail}-2026-09-01.md`
> (every query + raw output). Read the synthesis before building anything. Standing traps, all
> paid for at least once: pair every `MAILBOX_DIR=` proof with a throwaway DB (F26); scrub every
> new fixture with the address AND name needles (Agorastore and CapCar greet by name); never
> follow a tracking redirect at ingest (CapCar links are `sendibt3.com` per-recipient tokens —
> SeLoger's class); `/bin/grep` is ugrep (plain `|`; `-a` on Latin-1 fixtures); ledger runs need
> the JIT workaround; timestamps are `T`-separated (never compare against `datetime('now')`
> strings); trial any new pattern over the stored rows before shipping; sabotage cases for every
> guarantee whose branch no fixture reaches. Certification boundaries are the reviewing session's,
> not the executor's.

### 6-A — live-risk fixes (first: A1 and A2 are live loss/risk today)

- **A1 In'li degradation** (audit N1) — **DONE `56437d9`.** Route (a) was taken, with the
  counterweight run the entry demands: a SECOND, one-day window at a 20 % ratio beside the
  seven-day 30 % one, because a source degrading over hours never moves a seven-day denominator.
  It needs ≥ 20 runs in that day, so it stays deliberately inert on a sparse `--once` deployment
  where one failure of four is 25 % and means nothing — a scope limit, not a hole, and asserted as
  its own test. Measured against every other source rather than fitted to In'li: all the healthy
  ones sat at 0.0 %, and In'li's own history would have fired on 09-01 and no earlier day.
  Recovered to ~1.4 % afterwards. The five knobs are documented in `docs/OPEN-QUESTIONS.md`, whose
  table had drifted and was re-derived from the code 2026-09-04.
  ~~23 of 94 passes failed 2026-09-01~~ (20 s connection
  timeouts + one bare HTTP 302 on the index), climbing 2→11→12→23/day; health `ok` throughout
  because interleaved passes still return ~165 items. Investigate FIRST, fix second: read the
  302's `Location` once (one request, hard rule 5 posture unchanged), check whether the timeouts
  correlate with pagination depth or time of day (the run log has `duration_ms`), and only then
  decide between (a) a failure-RATE health signal (N% failed over 24 h — new verdict, needs its
  own counterweight run showing sources it does NOT fire on), (b) pacing changes, (c) nothing but
  the report. A timeout increase without measurement is the anti-bandaid gate's named case.
- **A2 car loader allow-list** (audit N4/C1) — **DONE `413f38e`.** The allow-list is per TYPE and
  `UNREAD_PARAMS` is kept as its own list rather than deleted, so the day an adapter learns to read
  one of those keys the compile-check is already there and only that line moves.
  ~~Port the rent side's EMAIL_ALERT_PARAMS-style~~
  allow-list refusal to `VehicleSourceLoader`: any param key no car adapter reads is a
  `ConfigError` naming the file. The docblock already promises this; make it true. One
  table-driven test; also cover the ~21 untested car refusals while in the file (audit N10).
- **A3 ListingMapper bundle** (F27b + audit N5 + N6) — **HALVES 1 AND 2 DONE `2ab3245`; HALF 3 IS
  THE ONLY PART OF TRACK 6-A STILL OPEN.** Half 1 answered the cityloger question with data and
  found its real cause (a SCOPE miss: the page states `65 m2` on line 221, the selector opens at
  268). Half 2 shipped with its §1 counterweight. **Half 3 — the rent plausibility band on mapped
  rents — is genuinely open**, verified 2026-09-03 rather than assumed: the band lives in
  `EmailAlertSource` and neither `ListingMapper` nor `Payload` carries one, so the 7 stored history
  rows at 119–290 € that came through the html path are still unbanded. Deferred past the C2 panel
  by developer ruling (2026-09-03) so the reviewed span stops growing.
  ~~One pass over the one funnel~~: (1) instrument
  `ListingMapper` with `PatternMissLog` so inli/cdc_habitat/cityloger/logirep and `DetailHydrator`
  count null-yielding selectors/paths — this finally answers cityloger's 9-null-surfaces-of-60
  question with data; (2) make `$map->tenureField` actually read on the JSON path (it is inert
  today; fixture_demo passes by coincidence — §1-adjacent latent); (3) run the rent plausibility
  band on mapped rents (7 stored history rows at 119–290 € came through the html path unbanded).
  Gate the CLI report on `CountsPatternMisses`, as both CLIs already do. Sabotage cases for each
  half; the miss-print itself is unpinned in both domains (audit N11) — pin it while here.
- **A4 brand `autres` bypass** (audit N3) — **DONE 2026-09-02, and NOT by the preferred
  mechanism.** The finding's premise for preferring the title read — that nulling leaves "still
  full share by hard rule 9" — is refuted at `VehicleScorer`'s null arm, which scores 0. Both
  routes give the DS4 brand 0; the title read is the worse haystack and the refused fallback shape.
  Shipped as a per-source `make_model_unknown_pattern`. See the Decisions Log entry of 2026-09-02.
  (The plan's "the existing zero-over-reach sabotage cases must stay green" was trivially true —
  they test `isAvoidedBrand`, which this does not touch, so they never guarded this change; three
  new cases do.)
> **LANDED 2026-09-05. THIS SECTION IS A RECORD, NOT A TO-DO.** Row 6 is `done`. What shipped is
> the ruling of 2026-09-05 05:10 — weights untouched, individual push at ≥ 55 — built as a SEPARATE
> `notify.push_min_score` knob rather than by moving `high_priority_score` (which stays 50: the
> marker is score AND confidence, the gate is delivery; the log line saying *"50 → 55"* names the
> bar, not the marker). Rent: `Store::announcementRank` DIGEST < ROLLUP < MATCH, `pendingLowScore()`,
> `DigestBatch::$lowScore`, the digest's second heading, `Pipeline` gate, `RunResult::$queuedLowScore`.
> Car: `push_min_score` 73 + `rollup_hour` 8, `VehicleStore::pendingRollup()`, `NotificationKind::ROLLUP`,
> `VehicleFormatter::rollup()`, the `rollup [--dry-run]` verb, the startup and in-loop floor with
> `state/car-rollup.txt` written after delivery. Pinned by the ledger block *Row 6 / A5* — no count written here, the block is the tally: a first draft wrote one and it was wrong by the same evening.
> The recalibration half below was offered and DECLINED — it is closed by ruling, not deferred.

- **A5 score-floor batching + recalibration** (ruling above). Two halves, ordered: (1) the
  batching — individual push only at score ≥ `high_priority_score`, the rest through the existing
  digest drain (`Cli/DigestBatch` is the shared landing zone; do NOT build a second one); (2) the
  recalibration — re-judge stored snapshots offline (the 2026-08-26 precedent: no poll needed),
  measure the distribution per component, present 2–3 weight options with their concrete effect
  on the developer's own recent matches via AskUserQuestion, apply the chosen one, then re-check
  `high_priority_score` still marks a sane fraction (the car 73 was calibrated 2026-09-01; rent 50
  predates commute's current shape).
- **A6 SeLoger surface-reader verification** (audit N2) — **DONE 2026-09-02, NON-ZERO.** One row,
  and the cause is not the predicted one: the generic scan read `7m2` out of a base64url tracking
  token. Fixed by applying `RawListing::text()`'s already-ruled query-and-fragment strip to the
  generic readers; 26 stored surfaces and 4 room counts recovered, 0 change on the other six
  sources. See the Decisions Log entry of 2026-09-02.
- **A7 F28 one-shot victims report** (ruling above) — **DONE 2026-09-02.** It is A6's defect, not
  a separate one, so the report was generated from the shipped reader rather than from a title
  heuristic: `var/claude/track6-a7-surface-victims.txt`, read-only, no store writes. 26 rows
  misread → 7 lost, 6 pushed with no surface, 4 excluded regardless, 9 failing anyway. The estimate
  of "~7 REJECTs" was right by coincidence: 7 is the LOST count, and there are 13 rejects in all.

### 6-B — new car sources (payloads banked in Gmail; build offline)

> **INPUTS RE-CENSUSED 2026-09-04 AND ALL THREE ARE PRESENT — plus two shape facts that change the
> build.** `tools/dump-eml.php` against the live mailbox returns three recent alerts each for
> CapCar (09-01, 09-02, 09-03), La Centrale (09-03 ×2, 09-04) and Agorastore (09-01, 09-02, 09-03).
> **CapCar is n=3, not the n=1 its entry warns about**, so the regression tests that entry asks for
> already exist. **CapCar is HTML-ONLY** — `text/html`, no `text/plain` alternative — which is the
> leboncoin shape: every URL lives in an `href`, so `EmailMessage::harvestHrefs()` is what makes its
> links reachable at all, and a config-only build would have produced a source with zero links and a
> permanently quiet market. La Centrale and Agorastore both carry a real `text/plain` part. All
> three route every link through a per-recipient tracking host (`sendibt3.com`,
> `clicks.mail-alerte.lacentrale.fr`, `email.alerts.agorastore.fr`), so all three are content-keyed
> — **and that must be settled before the first enabled pass**, because nothing migrates a stored
> row between key schemes and switching later re-notifies the whole backlog.

> **B1 IS NOT CONFIG-ONLY, MEASURED 2026-09-04, and the whole 6-B heading assumes it is.**
> `VehicleEmailSource` applies `title_pattern` to the message SUBJECT, never to the card segment.
> CapCar's subject is one banner for the whole message (*"Nouvelle sélection de véhicules
> disponibles !"*), so no pattern over it can name an individual car; with no `title_pattern` the
> adapter falls back to the line ABOVE the price, which here is `Kilométrage : 24409`. And
> `make_model_source: title` then reads the make out of THAT — the only other haystack it offers is
> the link, and every CapCar link is an opaque per-recipient `sendibt3.com` redirect carrying no
> make at all.
>
> **Shipping it config-only would store a mileage label as the title and no make**, and `brand_avoid`
> reads `make`: an unextracted make scores 0 on that component, so every CapCar car would rank ten
> points below an identical one from a source that states its make — the silent-ordering failure
> Track 1d exists for. Worse than not shipping the source.
>
> What is needed is a per-SEGMENT field reader; the labelled block is perfectly regular, so it is a
> small adapter change — but a CODE change with its own tests and its own review, not a config
> block. **Two more things measured and worth having before anyone writes it**: the labels use
> U+00A0 before the colon and the price a U+202F thousands mark, so `explode('Marque :', $body)`
> returns ONE segment silently — a source yielding nothing while reporting a healthy fetch. And the
> CTA cannot be the separator: `harvestHrefs()` puts each link INSIDE its card after the CTA, so
> splitting there leaves every card holding the PREVIOUS card's link, which is the PAP
> phantom-listing shape with the fields shifted by one.
>
> The capture is committed, scrubbed and audited for a recoverable identity in every encoding:
> `tests/fixtures/car/capcar/2026-09-03-001-quatre-cartes.eml`, pinned by
> `tests/php/Car/CapCarPayloadShapeTest.php` so the finding is reproducible without the mailbox and
> whoever builds the reader has ground truth to write it against.

- **B1 CapCar** (`contact@capcar.fr`, first real alert 2026-09-01 18:00). Structured labelled
  fields per card (`Marque/Modèle/Finition/Motorisation/Carburant/Boîte/Année/Kilométrage/Prix`),
  4 cards/message. Links are per-recipient tracking redirects → identity is CONTENT-based (the
  SeLoger discipline: no-information floor, rent/price out of the key, duplicate-in-message
  announced). n=1 — state it, and let the second alert be the regression test. `params.from`
  scopes to `contact@capcar.fr`; note `capcar.fr` also mails from agent addresses (noise).
- **B2 La Centrale** (`info@mail-alerte.lacentrale.fr`, ~2/day since 08-27, ~10 payloads banked).
  Build as ruled (Track 2 step 3): `feed_silent_days: 3`, truncation cost (email carries ~3 of
  900+ stated cards — F7) documented IN THE CONFIG COMMENT, polling refused by ruling (DataDome).
  `params.from` must exclude `mail-marketing` and `mail-rachat` senders.
- **B3 Agorastore** (`support@agorastore.fr`, daily) — optional third, price-only truncated email
  (Track 2 step 4's shape). Greets by name: scrubber needles mandatory on every fixture.
- AutoScout24 stays BLOCKED (mailbox-verified 2026-09-01: still zero real alerts; likely future
  sender `savedsearches@notifications.autoscout24.com`). The leboncoin car Gmail filter is DONE
  (messages labeled) — strike it from Inputs-owed.

### 6-C — hygiene + certification

- **C1 one docs commit** syncing the register to the tree (audit N7) — **DONE 2026-09-02**; see the
  Decisions Log entry, and note that two items on this very list were already discharged when it
  ran while F30, which it did not name, was not. Original scope: F1/F5/F10/F15/F16/F17/F19/
  F24 → their true CLOSED/FIXED state with commit refs; fix the five State/Where cell
  disagreements; F26's stale count in F1 ("23 null rows" → healed, 0); the two stale pre-collision
  id citations (Track 3's "F10/F11", "3 seloger + 4 pap"); the stale `"7"` in car leboncoin's
  `_feed_silent_days` comment; fold `pap-detail-hydration.plan.md` back under this file (archive
  it with the SUPERSEDED banner and copy its prose_absent ruling's pointer into the log above) so
  the single-source ruling holds; strike the done items from Inputs-owed.
- **C2 certification round** (audit N8) — **THE FREEZE POINT IS `ede198e`** (2026-09-02, the C1
  commit; A4 is `0ae6cd0` before it). That is the last CODE-touching commit: the developer ruled on
  2026-09-02 that A4 + C1 land and then the tree freezes, with A5 and B1-B3 deferred past the panel
  so the span stops growing. Any docs-only commit after `ede198e` — including the one that wrote
  this line — leaves the code under review unchanged; **if a commit after it touches
  `src`/`config`/`tests`, the freeze is broken and this pointer is wrong.** Verify with
  `git log --oneline ede198e..HEAD -- src config tests`, which must be empty. Then ONE MAXIMAL
  round covering the entire uncertified span since
  `7765997` — **58 commits, 38 of them touching `src`/`config`/`tests` at `0ae6cd0` (measured
  2026-09-02, and bigger than the "45+" this line used to state; the C1 commit that corrected
  this line makes it **59/39** — it is not docs-only, having repointed two in-tree citations of
  the archived plan file)**, covering Tracks 1, 2, 4,
  5, T5B and this one. **It is simultaneously Track 1's and Track 4's own never-run MAXIMAL
  rounds**, both of which their sections promise separately — one panel discharges all three.
  One panel for one backlog is the
  economize ruling's shape; run by the REVIEWING session, not the executor.

  > **ROUND 2's BRIEFING — written 2026-09-04, before the round, so it cannot be shaped by what the
  > round finds.** Round 1 returned 2 P0, 4 P1, 9 P2 and 5 P3; the P0/P1s landed as
  > `be8eba7..081ab28` and the P2 batch as the 2026-09-04 commits. Round 2 is therefore **not a
  > re-run**: it reviews a span that has grown by the fixes, and the bar is two CONSECUTIVE fully
  > clean rounds, so round 2 finding anything at all resets the counter and round 3 becomes round
  > 2 again.
  >
  > **Freeze first (step 25), and re-measure the span** — the `ede198e` pointer is stale by its own
  > stated test and every number quoted above (59 commits, 39 touching code) is measured at that
  > commit. Do not carry those numbers forward; re-derive them at the new freeze.
  >
  > **Weight the round at what round 1 did NOT read**, which its own reports name rather than leave
  > to be guessed: `src/php/Core/RunStore.php` (+950, the whole extracted store — the correctness
  > lens did not open it), `Core/PatternMissLog` and `CountsPatternMisses`, `DetailHydrator`,
  > `HtmlSource`, `HttpJsonSource`, `Car/VehicleStore`, `Car/Cli/CarScout`, `ImapMailbox`,
  > `NtfyChannel`, `ChannelFactory`, and ~25 changed test files. Two claims round 1 left explicitly
  > **unverified** belong in scope by name: whether the `4d49eda`/`bdffa44` store split left any
  > sabotage expression orphaned (its `test-sabotage-applies` run was killed at 143 by the
  > reviewer's own poll timeout and never finished — it has since been run clean at 656/656, so
  > this is a re-check rather than an open question), and the `RunStore` flaky-window arithmetic.
  >
  > **And put THIS batch in scope explicitly.** Nine of its own findings were closed by the session
  > that is briefing the round, which is exactly the author-bias shape the charters exist to
  > refute: the new `Core/DigestCause`, the `Verdict::digest()` signature change and its five call
  > sites, `pendingDigest()` growing a column, the `SURFACE_PATTERN` anchor (measured to change 4
  > stored rows and claimed to lose none), `FieldMap`'s new load-time compile check, and
  > `tools/verify-deploy.sh`. Name the commit and the claim; let the lens refute it.
  >
  > **Local hazards to brief, all previously paid for:** `difft` makes `git diff` silently empty
  > (use `git --no-pager -c core.pager=cat diff --no-ext-diff`); `/bin/grep` is ugrep, so `\|` in a
  > `SABOTAGE_FILTER` is LITERAL and skips every case while exiting 0; the tracing JIT kills the
  > ledger at exit 134 (`PHP_INI_SCAN_DIR` workaround, keeping the original scan dir or `iconv`
  > disappears); this box is SHARED and a neighbour's test run took the load average to 27 on
  > 2026-09-04, which turns a 300 s ledger budget into a false red; and a fixture-backed `doctor`
  > writes into the LIVE store unless paired with a throwaway `RENT_SCOUT_DB`.
  >
  > **Spawn UNNAMED**, each lens in its own pinned `git worktree` copied with `cp -a` and with
  > `vendor/` copied rather than symlinked, and do not edit the tree while a round is running — the
  > ledger says so itself when it sees a dirty tree, and this session proved the warning is real.
  >
  > ### THE RE-FREEZE (step 25) — measured 2026-09-04, replacing every number above
  >
  > **The freeze point is `8fb9b64`** (moved from `526d246` when the deploy verifier gained its
  > image-age check — a small addition made deliberately BEFORE the round rather than after it, so
  > the span stops growing once the round starts). It is the last commit touching
  > `src`/`config`/`tests`, and the pointer's own test confirms it rather than assuming:
  > `git log --oneline 8fb9b64..HEAD -- src config tests` is empty, and the tree is clean. The deferral ruling still holds,
  > so A3 half 3, A5 and B1–B3 stay unbuilt until the round closes and the span stops growing here.
  >
  > | | at `ede198e` (stale) | at `8fb9b64` |
  > |---|---|---|
  > | commits since `7765997` | 59 | **80** |
  > | of those touching `src`/`config`/`tests` | 39 | **48** |
  > | code commits since `ede198e` | — | **9** |
  >
  > Those nine are the whole delta the previous pointer missed: `be8eba7` and `3eca42f` (the two
  > round-1 P0s), `581cbce` and `081ab28` (the P1 and its ledger retargeting), then this session's
  > `38f64bb`, `95337fa`, `eb5d971`, `526d246` and `8fb9b64`. **Quote the new numbers, not the old ones** — the
  > 59/39 pair is measured at a commit the round is no longer frozen at, and carrying it forward is
  > the exact drift the pointer's self-test exists to catch.

### 6-D — rulings: ALL RESOLVED 2026-09-01 (see Decisions Log) — F30 drop is one config edit
(land it with A-cluster work); the rest are embedded in A5/A7 and the T5B-7 config line.

### Explicitly out of scope for Track 6
- Redact `%40` and redirect-vs-robots: still open by the developer's explicit earlier choice —
  touch neither without a new ruling.
- In'li: no headless browser, no fingerprinting, no timeout raise without measurement.
- The 12 historical rooms-misread MATCHes already pushed: noise already spent; nothing retracts a
  push. Only A7's read-only report looks backward.

## Status
<!-- progress-block v1 -->
| # | Step | Size | State | Evidence | Files |
|---|------|------|-------|----------|-------|
| 1 | 6-A1 In'li degradation — failure-rate health signal (dual window) | M | certified | 56437d9 test:2026-09-04 | src/php/Core/RunStore.php |
| 2 | 6-A2 car loader — a params key no adapter reads is refused | M | certified | 413f38e test:2026-09-04 | src/php/Car/VehicleSourceLoader.php |
| 3 | 6-A3 ListingMapper miss instrumentation + tenureField on the JSON path — COMPLETED at 8e3fe80: C2 r5 found 4 configured keys (rent, rent_hc, url, tenure_field) still counting nothing, so `certified` overclaimed | L | done | 8e3fe80 | src/php/Rent/Adapters/ListingMapper.php |
| 4 | 6-A3 half 3 — RULING REVERSED at 8e3fe80: the mapped path carries NO band (both bounds erased evidence a `!== null` guard needed; the scan keeps its band) | M | done | 8e3fe80 | src/php/Rent/Adapters/ListingMapper.php src/php/Rent/Adapters/Payload.php |
| 5 | 6-A4 brand `autres` bypass — make_model_unknown_pattern | M | certified | 0ae6cd0 test:2026-09-04 | src/php/Car/VehicleSourceLoader.php config/car/sources.json |
| 6 | 6-A5 score-floor batching + weight recalibration | L | certified | 5a04756 test:2026-09-06 | src/php/Rent/Cli/DigestBatch.php config/rent/criteria.json |
| 7 | 6-A6 SeLoger surface read out of a base64url tracking token | M | certified | 9ea9d77 test:2026-09-04 | src/php/Rent/Adapters/EmailAlertSource.php |
| 8 | 6-A7 F28 one-shot victims report (read-only, gitignored output) | S | done | - | - |
| 9 | 6-B1 CapCar email source | L | certified | 618b065 test:2026-09-06 | config/car/sources.json tests/php/Car/CapCarFixtureTest.php |
| 10 | 6-B2 La Centrale email source | L | done | 21117b7 | config/car/sources.json tests/php/Car/LaCentraleFixtureTest.php tools/scrub-eml.php |
| 11 | 6-B3 Agorastore email source (optional third) | M | certified | 74d15d1 test:2026-09-06 | config/car/sources.json tests/php/Car/AgorastoreFixtureTest.php |
| 12 | 6-B4 AutoScout24 — no alert has ever arrived | M | blocked | - | config/car/sources.json |
| 13 | 6-C1 register and docs say what the tree says | M | done | ede198e | docs/plans/scout-unified-execution.plan.md |
| 14 | 6-C2 r1 F2 (P0) — both segmentation guards read both separator keys | M | certified | be8eba7 test:2026-09-04 | src/php/Rent/Config/ConfigLoader.php tests/php/Rent/Config/ConfigTest.php |
| 15 | 6-C2 r1 F1 (P0) — a flat the store holds as PLS is vetoed under a new ad id | M | certified | 3eca42f test:2026-09-04 | src/php/Rent/Cli/Pipeline.php src/php/Rent/Core/Dedup.php src/php/Rent/Store/Store.php |
| 16 | 6-C2 r1 F-R1 + CMP-3 (P1) — counting a miss is not reporting one | M | certified | 581cbce test:2026-09-04 | src/php/Core/PatternMissLog.php src/php/Rent/Adapters/HtmlSource.php src/php/Car/SitemapVehicleSource.php |
| 17 | 6-C2 r1 — two ledger expressions retargeted after the escalation moved | S | done | 081ab28 | tests/sabotage-check.sh |
| 18 | 6-C2 P2 bundle — dump-eml fail-open guard, LOGIN trace, no docs/test (3 of 9) | M | done | 38f64bb | tools/dump-eml.php docs/ALERT-CAPTURE.md tests/test-dump-eml.sh |
| 19 | 6-C2 P2 COR-F5 — a persisted twin doubt cleared by a third route | M | certified | eb5d971 test:2026-09-04 | src/php/Rent/Cli/Pipeline.php src/php/Rent/Store/Store.php |
| 20 | 6-C2 P2 COR-F4 — SURFACE_PATTERN still has no left anchor | S | certified | 38f64bb test:2026-09-04 | src/php/Rent/Adapters/EmailAlertSource.php |
| 21 | 6-C2 P2 COR-F3 — digest title asserts an undetermined regime that is determined | S | certified | 38f64bb test:2026-09-04 | src/php/Rent/Core/CriteriaEngine.php src/php/Rent/Notify/Formatter.php |
| 22 | 6-C2 P2 CMP-2 — the frozen In'li fixture is the old template | M | certified | 38f64bb test:2026-09-04 | tests/fixtures/rent/inli/search-2026-09-04-nouveau-gabarit.html tests/php/Rent/Adapters/InliCurrentTemplateTest.php |
| 23 | 6-C2 P2 CMP-1 — ntfy badge test uses an ASCII stand-in | S | certified | 38f64bb test:2026-09-04 | tests/php/Core/Notify/NtfyChannelWireTest.php |
| 24 | 6-C2 P2 CMP-4 — F19 register row cites two stale line numbers | S | done | 38f64bb | docs/plans/scout-unified-execution.plan.md |
| 25 | 6-C2 re-freeze at the last P2 commit and re-measure the span | S | done | 6001b01 | docs/plans/scout-unified-execution.plan.md |
| 26 | 6-C2 round 2 — MAXIMAL, ran at 8fb9b64: 2 P1, 5 P2, 5 P3, all fixed | L | certified | 5833b87 test:2026-09-04 | src/php/Adapters/Mail/ImapMailbox.php src/php/Core/Notify/SmtpTransport.php src/php/Rent/Cli/Pipeline.php |
| 27 | 6-C2 round 3 — ran at 5833b87: 2 P1, 4 P2, 5 P3, all fixed | L | done | 909c159 | bin/scout src/php/Rent/Core/ListingSnapshot.php tests/sabotage-check.sh |
| 28 | F18 a plain grep silently skips the Latin-1 PAP fixtures | S | certified | 38f64bb test:2026-09-04 | tests/php/Repo/FixtureSecretsTest.php |
| 29 | F20 the durable reading no longer claims where it was read | M | certified | 526d246 test:2026-09-04 | src/php/Rent/Cli/Pipeline.php docs/OPEN-QUESTIONS.md |
| 30 | F25 compose recreate wedges and leaves the watcher down silently | M | done | 38f64bb | tools/verify-deploy.sh tests/test-verify-deploy.sh README.md |
| 31 | Deep — the five car MalformedText arms have no sabotage case | M | done | 526d246 | tests/php/Car/VehicleMalformedTextTest.php tests/sabotage-check.sh |
| 32 | Deep — field-map regex has no load-time compile check | S | certified | 38f64bb test:2026-09-04 | src/php/Rent/Config/FieldMap.php tests/php/Rent/Config/ConfigTest.php |
| 33 | Deep — doc drift: WARN_FLAKY, card_separator_pattern refusals, 4 stale Core paths | M | done | 38f64bb | CLAUDE.md docs/OPEN-QUESTIONS.md |
| 34 | Deep — plan track sections stale for Tracks 0 1 2-step0 4 and 6-A1/A2/A3 | M | done | 38f64bb | docs/plans/scout-unified-execution.plan.md |
| 35 | 6-C2 — the TWO CONSECUTIVE CLEAN rounds the bar requires; rounds 1-3 each found real defects, cap is 5 then ask | L | doing | - | src/php |
| 36 | Processed alert emails are marked \Seen — run only, after the store recorded the source; doctor/dump stay read-only | M | certified | 766edd7 test:2026-09-06 | src/php/Adapters/Mail/ImapMailbox.php src/php/Adapters/Mail/Mailbox.php src/php/Rent/Cli/Pipeline.php src/php/Car/VehiclePipeline.php |
| 37 | B-common — content-addressed identity for VehicleEmailSource (no-information floor, price out of the key, in-message duplicate announced) | M | certified | 7e1d54b test:2026-09-06 | src/php/Car/VehicleEmailSource.php src/php/Car/VehicleSourceLoader.php |
| 38 | B-common — per-segment labelled field reader for VehicleEmailSource (the CapCar shape) | M | certified | 7e1d54b test:2026-09-06 | src/php/Car/VehicleEmailSource.php src/php/Car/VehicleSourceLoader.php |
| 39 | B3 prerequisite — a NARROW scrubber stripper for a base64 JSON-array identity blob, all refusal guarantees kept | M | done | 74d15d1 | tools/scrub-eml.php tests/test-scrub-eml.sh |
| 40 | F20 / Q39 — a repair route for a durably-excluded row: ruling (command vs stored distinction), then build | M | certified | 2553c94 test:2026-09-06 | src/php/Rent/Store/Store.php src/php/Rent/Cli/RentScout.php |
| 41 | Round-5 P2 — a selector drifting onto a 5-digit field extracts cleanly and health stays ok: ruling (build vs accept), then build | M | certified | 2553c94 test:2026-09-06 | src/php/Core/SameFilterWarning.php src/php/Rent/Cli/Pipeline.php src/php/Car/VehiclePipeline.php |
| 42 | Register + Known-issues bookkeeping — F1b, F3, F6 closers; three stale bullets | S | done | - | docs/plans/scout-unified-execution.plan.md |
| 43 | Fresh test record at HEAD, then the freeze for row 35 | S | done | b692570 | docs/plans/scout-unified-execution.plan.md |
| 46 | C2 round 6 — three lenses NOT CLEAN (RES P1+P2, COR P1+P2, COMP P0+P2+P3); every finding fixed with tests and ledger cases | L | certified | 1c9f8aa test:2026-09-06 | src/php/Rent/Cli/RentScout.php src/php/Car/Cli/CarScout.php src/php/Core/RecoverableForms.php src/php/Rent/Notify/Formatter.php |
| 47 | The source audit the developer asked for: per-source NULL rates on both domains + a live doctor each — an RFC-legal single-digit Date was refused (PAP lost observedAt AND its feed verdict), ParuVendu read one of three facts shapes | L | certified | 1c9f8aa test:2026-09-06 | src/php/Adapters/Mail/EmailMessage.php config/car/sources.json tests/fixtures/rent/pap/2026-09-05-005-date-jour-un-chiffre.eml |
| 48 | The nightly ledger completed none of its last eight runs — shard it six ways, one aggregating alert job | M | done | 1c9f8aa | .github/workflows/ci.yml tests/sabotage-check.sh tests/test-ci-workflow.sh |
| 44 | In'li answers HTTP 302 on ~2 of 5 passes (seen 2026-09-05 00:30, source reports broken) — measure the redirect, rule, fix or record | M | certified | 2553c94 test:2026-09-06 | src/php/Rent/Adapters/HtmlSource.php src/php/Rent/Adapters/HttpJsonSource.php |
| 45 | CI RED for two days (12 pushes, since 46546bc): a PCRE2 ≥ 10.43 lookbehind in criteria.json and a trace test assuming the development ini — fix at the root, add a portability guard, re-run the nightly ledger on demand | M | certified | 4f0559d test:2026-09-06 | config/rent/criteria.json tests/php/Repo/PortablePatternsTest.php tests/php/Repo/CredentialsNeverReachATraceTest.php |
| 49 | The 2026-09-06 nightly's five undetected guarantees: three tests, and two cases that seeded half their mutation (`run_sabotage` reads only `$3`) — one of them §1 | M | certified | 6a596d0 test:2026-09-06 | tests/sabotage-check.sh tests/php/Car/AutoheroFixtureTest.php tests/php/Repo/CredentialsNeverReachATraceTest.php tests/php/Rent/Cli/RentScoutTest.php |
| 50 | The dispatched ledger's survivor: detail pacing was asserted by wall-clock, which a slow machine satisfies with the sleep deleted — an injected sleeper, and the per-hydration count asserted | S | certified | 8ee959b test:2026-09-06 | src/php/Rent/Adapters/DetailHydrator.php src/php/Rent/Adapters/HtmlSource.php tests/php/Rent/Adapters/HtmlSourceDetailTest.php tests/sabotage-check.sh |
| 51 | The §1 case's second expression was seeded but uncovered — the routing mirror is masked by the rule it mirrors, so it is pinned by reflection and each expression measured alone | S | certified | 243cb1d test:2026-09-06 | tests/php/Rent/Core/TenureClassifierTest.php tests/sabotage-check.sh |
| 52 | C2 round 7 — three lenses NOT CLEAN (2 P0, 3 P1, 10 P2, 8 P3); every finding fixed with a test that reddens on its removal | L | done | 676602a | src/php/Rent/Cli/DigestBatch.php src/php/Car/Cli/CarScout.php src/php/Rent/Cli/RentScout.php .github/workflows/ci.yml |
| 53 | The page-walk sleep was the symmetric surface of the detail one — same seam, count asserted, ledger case | S | done | - | src/php/Rent/Adapters/HtmlSource.php tests/php/Rent/Adapters/HtmlSourceTest.php tests/sabotage-check.sh |
<!-- /progress-block -->
### Blocked

- **Step 12 — 6-B4 AutoScout24.** Blocked on an INPUT no default can supply: the portal has never
  sent a real alert. Mailbox-verified 2026-09-01 and again 2026-09-04 — only newsletters. The
  likely future sender is `savedsearches@notifications.autoscout24.com`. The ruling is **wait, do
  not build**: a source written against a payload nobody has seen is the blind-build this repo paid
  four defects for on the day SeLoger's first real alert arrived, behind 1 886 green tests.

### Needs input

- Nothing. **All three of B1–B3's inputs were re-censused on 2026-09-04 and are present** (three
  recent alerts each for CapCar, La Centrale and Agorastore), so those rows are buildable rather
  than waiting. The only outstanding input in the whole plan is B4's, above.

### Needs research

- **Step 6 — A5's recalibration half.** The weights cannot be chosen from the desk: the ruling is to
  re-judge the stored snapshots offline, measure the distribution per component, and put 2–3 options
  to the developer with their concrete effect on their own recent matches. Every previous attempt to
  PREDICT a yield here has been wrong — the 83-match measurement, the Cityloger zero, the widened
  `brand_avoid` — so the research is a measurement, not a design.

### Fragile

- See the **fragile implementations register** above; it is the maintained list. Rows still OPEN
  there (re-read 2026-09-04, row 42): F6 (1 seloger row), F7 (La Centrale truncation — a stated
  cost, documented in the config comment when B2 lands), F8, F9, and F20's repair-route half
  (row 40). F1b and F3 were closed by commits their cells never recorded; F26's guidance half is
  closed in `CLAUDE.md`.

### Known issues

- **Row 35 is UNMET and IN FLIGHT** (round 8, frozen 2026-09-06): the two-clean counter is 0 —
  rounds 1–7 each found real defects, round 7 alone 23 of them. The rows that gated the resume
  (6, 9–11, 36–43) have all landed and the old resume point `792eb3a` is superseded. Round 8's
  freeze is the commit that closes this bullet's own row; a clean round moves the counter to 1,
  and TWO are required, so even a clean round 8 does not close row 35.
- **Round-5 P2, recorded not fixed** (row 41): with no band on the mapped path, a selector drifting
  onto a 5-digit field extracts `95240` cleanly, `max_rent_cc` rejects every card, `rent` counts
  zero misses and health stays `ok`. The ParuVendu `autres` class one layer over; it wants a
  plausibility instrument, and whether to build one is a ruling the row will ask for.
- **The car domain has no `PacedSource`**, recorded not fixed with its trigger: the moment a second
  car web source without its own rate limiter exists, lift `PacedSource` into `Scout\Adapters`
  over a shared contract — never write a car twin.
- *(Three bullets that stood here on 2026-09-04 were stale against rows 19, 25 and 31 — COR-F5 is
  built and test-verified at `eb5d971`; the `ede198e` freeze was superseded by step 25; the
  `MalformedText` ORDER is pinned by `VehicleMalformedTextTest` at `526d246`. Removed by row 42.)*
