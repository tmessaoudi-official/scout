# EXPERTISE - scout   (L4 core; loaded every session in this project)
Review date: 2026-10-02   Validation mode: advisory   Packs (project skills in .claude/skills/): domain-rental-tenure-matching, domain-scraping-sources-health, domain-alert-email-ingestion, domain-docker-ops-sabotage-ledger
Scope: the DELTA over CLAUDE.md (its 10 Hard rules, section-1 eligibility rule, certification ladder, git autonomy and the .claude/rules/{health,mail,rent,tests,prototype,tooling}.md files are NOT repeated). Detail with dates and thresholds: EXPERTISE-REFERENCE.md (at .claude/EXPERTISE-REFERENCE.md; read the section named in the pointer table when the task touches it; never load it whole). Tags: [Ruled: <who> <date>] = a dated owner ruling; [Source: ...] = a document; [Observed: ...] = measured/seen; [Unverified]. `Qn` = OPEN-QUESTIONS.md entry.

## 0. What a generic engineer gets wrong here (read this first)
1. Treats "no match" as "quiet market": a broken selector, dead pattern or silent exception shows as ZERO results with health green. Ask who counts this and who reads the count.  [Source: CLAUDE.md Hard rules 2-3; Observed 2026-08-22 Logirep list-boxing, 2026-09-02 In'li `cp` 171/171 dead while `ok`]
2. Believes a green suite (2 000-3 100 tests) certifies a path. Blind-written parsers cost 4-6 defects on the first real message; a fix passed everything yet failed deployed because a hop (commute) is OFF in tests.  [Observed: 2026-08-25, 2026-08-29]
3. Makes "unknown" mean "no": None rent/surface/floor/lift is unknown, never disqualifying; `floor == 0` (RDC) is real; `elevator False` != `None`; a card omitting a field is not a listing lacking it.  [Source: CLAUDE.md rule 9; Ruled: developer 2026-08-07 Q5]
4. Reads "social" words as ineligibility signals or "intermediate" words as safe. Excluded vocabulary is ordinary French on eligible listings (`plus`, `au plus pres`, `bailleur social`, `PLUS DE 70 M2`, `Pret PLUS`, `En savoir plus`, `Plain-pied`, `SURESNES`, `Ce-lli-er`). Classify only listing-scoped text, URLs and page furniture included.  [Observed: 2026-08-20..26, REFERENCE §2]
5. Fixes one of several symmetric surfaces (push, digest, rollup, floor, reclassify; rent vs car): a named failure class, >= 5 occurrences. Enumerate by reflection, not a hand list.  [Source: HISTORY "What this history is actually a record of"]
6. Attaches a true number to an invented cause (zone note, "0 matches", `exited (137)` as OOM, `uptime` as UTC). Measure the cause, date the number.  [Observed: 2026-08-22, 2026-08-26, 2026-09-07, 2026-09-14]
7. Reads this repo's own prose as current: it carries superseded rulings (section 7; also "8 of 19 would yield"). Code and `config/rent/criteria.json` win; dated beats undated; re-derive before quoting.  [Source: OPEN-QUESTIONS contradictions; Observed 2026-08-26, 2026-09-04]

## 1. Domains this project touches
| Domain | Pack | Detail in REFERENCE |
|---|---|---|
| French rental tenure (LLI/PLS/PLUS/PLAI/LIBRE), eligibility filter, scoring, notification routing | domain-rental-tenure-matching | §2, §3, §4 |
| Source onboarding and health: HTTP/HTML/JSON adapters, robots, pacing, SOURCE_BROKEN, pattern-miss counting | domain-scraping-sources-health | §5, §6, §8 |
| Alert-email ingestion (IMAP, MIME, per-portal parsers), fixture capture and scrubbing, Gmail MCP | domain-alert-email-ingestion | §7 |
| Docker watchers, deploy, SQLite backup, sabotage ledger, CI, long jobs on this box | domain-docker-ops-sabotage-ledger | §9, §10 |
| What `RawListing` carries, `Pipeline::enrich`, `Enrich/*`, the record path (adapter -> pipeline -> store) | domain-rental-tenure-matching (verification row in section 4 of this file; the deploy log check is in the docker-ops pack's evidence table, do not load that pack for it) | §3, §4 |
Car and job domains reuse the same machinery (no tenure; car excluded-vehicle set is code, not config); their per-source quirks are REFERENCE §6.
**Routing rule: load ONE pack (the first row above that matches the task) and at most ONE REFERENCE section named in the pointer table (section 8 of this file). Never read EXPERTISE-REFERENCE.md whole; open it at the named section only. Load a second pack only when the task names a second domain.**  [Observed: pilot baseline, 2026-10-02]

## 2. Doc map (which document answers which question)
| Question | Open | Authority |
|---|---|---|
| Product, non-goals, every constraint | spec/PROJECT_BRIEF.md | source of truth; every constraint is a ruling |
| Is X ruled; why; what is open | docs/OPEN-QUESTIONS.md (Q1-Q40 + Decisions Log) | ruling record, but STALE where it differs from config/code |
| Live filter values, weights, thresholds | config/rent/criteria.json (+ gitignored criteria.local.json) | config wins over any doc |
| Which sources exist, route, status | docs/SOURCES.md (catalogue), docs/SOURCES-LIVE.md (register, re-derive commands at bottom) | measured, dated |
| Why code is shaped this way | docs/ENGINEERING-NOTES.md | read the matching section BEFORE changing that behaviour |
| Run, deploy, backup, recover; capture alert emails | docs/RUNBOOK.md, README "Deploying it", tools/verify-deploy.sh; docs/ALERT-CAPTURE.md | |
## 3. Invariants and rulings that must not be broken (detail REFERENCE §2-§4)
Eligibility (section 1 of the brief; a false positive costs the user a wasted application):
- Tenure F1 is code, not a config key; `mixed_tenure` is mandatory in every source block (code default AND loader refusal: two guards). A source's tenure claim is a property of its LISTINGS (In'li is not pure LLI). `reclassify` re-judges on the stored v7 snapshot alone, `digest` announces an evidence-less row.  [Ruled: developer 2026-08-07, 2026-08-24; Observed 2026-08-23]
- `mixed_tenure: false` claims the landlord publishes NO social stock; seloger/bienici/leboncoin/pap carry a stated residual (anonymous advertiser, agency not naming the landlord, card with no tenure -> LIBRE 50 -> MATCH). Arming `true` there digests 100 % of the source: not a fix.  [Source: SOURCES-LIVE; Observed 2026-09-01/08]
- Fail-closed landing zone is the "a verifier" digest (33 % of the corpus). Vetoes decide whole clusters: Q38 same-track (an EXCLUDED member decides, an UNDETERMINED one does not), Q39 cross-track twin, same-dwelling-under-another-ad-id. `SectionOneGate` re-reads all four routes before every send. Not one-line reversible; the only repair is `reclassify --reopen=<dedup_key>`, which reports but does not clear group/same-dwelling vetoes.  [Ruled: 2026-08-24, 2026-08-29, 2026-09-05; Observed: 2026-08-31]
Scoring and routing:
- Amenities display-only; heating a stacking penalty read from the description, never a new field-map entry. A send failure leaves `notified_at` NULL; emitted only after the channel confirms; `console`/`SMTP_TRANSPORT=file` are not delivery.  [Ruled: developer 2026-08-07, 2026-08-25, 2026-09-08]
- `high_priority_score` 50 with confidence >= 80 (`!!`); priority is decided on the UNMULTIPLIED score, a MATCH with confidence < 0.80 is capped at NORMAL. Do not retune the threshold to a reading.  [Ruled: developer 2026-08-07 Q31, 2026-08-22, 2026-08-26, 2026-09-05]
Sources and legality:
- Robots.txt status handling: 404/410 allow; 403/5xx and an HTML 200 fail closed. A bot-challenge cookie (`/shield`, DataDome) is a CAPTCHA-class refusal: the route is the email alert, never a headless browser.  [Ruled: 2026-08-26; Observed: 2026-08-25]
- Never write an endpoint from memory (A4/AL'in stays blocked on a DevTools capture); do not re-try ICF Novedis, Seqens, RIVP, Val d'Oise without new evidence.  [Observed: 2026-08-20/26]
- Config is JSON, ZERO Composer deps (egress blocks codeload); `_`-keys are comments, unknown keys error loudly. Repo is PUBLIC and UNLICENSED; history purge is Q40 (default ACCEPT; never force-push).  [Ruled: 2026-08-07; Observed: 2026-09-07]
Workflow: `legal_risk: true` needs `--i-accept-legal-risk` per invocation; `/qa-sweep` stays rejected.  [Ruled: 2026-08-07, 2026-08-19]
## 4. Hard evidence surfaces (what certifies work here; "tests pass" alone does not)
| Change touches | Certified by | Sabotage shape that matters | Uncertified unless run |
|---|---|---|---|
| Tenure classifier, `Core/Text.php`, corpus (143+ cases), vetoes, SectionOneGate | PHPUnit suite + corpus + `tests/sabotage-check.sh` (800+ cases, 6 shards nightly, `SABOTAGE_SHARD=i/n`) | mutate the CONSEQUENCE, read WHICH test went red; two controls | the ledger (hours): say so in the report |
| A new source / parser | fixture captured from a REAL payload + `doctor --source=X` on a throwaway `RENT_SCOUT_DB`; hand-counted listings number | drop the separator / identity scheme | the first DEPLOYED pass |
| Anything on the record path or what `RawListing` carries | replay in `PipelineRunTest` WITH a `FixedPlanner` (commute ON) + watch `docker compose logs` for `annonce(s) analysees` after rebuild | clone-with, never field-by-field copy | deployed first pass |
| Deploy / watchers | `bash tools/verify-deploy.sh` (exit 2 image missing, 1 watcher down); NOT `/progress` | | `test-notify` (a stale digest marker proves nothing on an empty queue) |
| Config patterns (regex) | loader compile check + `PortablePatternsTest` (CI PCRE2 10.42 vs local 10.44) | variable-length lookbehind | CI run: `gh run list --limit 5` after every push |

## 5. Trigger -> lesson table (the carry-worthy lessons NOT in CLAUDE.md or rules/; detail in REFERENCE §9-§10)
| If the task... | Remember |
|---|---|
| adds/edits a ledger case in `tests/sabotage-check.sh` | on a scratch copy: apply (`diff -q`), parse (`php -l`), detect; ONE filtered run for all new cases; one-line sed only; a case can fail GREEN (RHS replaced, assignment left dangling)  [Observed: 2026-09-04/09] |
| moves code or extracts constants a case targets | rerun `bash tests/test-sabotage-applies.sh`; re-derive the expression from the new SHAPE; if the guarantee inverts the case must reintroduce the thing  [Observed: 2026-09-04] |
| adds a decoder/parser/fallback to something a self-test guards | assert the MECHANISM not the outcome; re-run the sabotage the self-test exists for (one improvement made `FixtureSecretsTest` vacuous)  [Observed: 2026-09-04] |
| runs the ledger locally | `PHP_INI_SCAN_DIR` from `php --ini` carries printed quotes: `tr -d '"'` and require `diff <(php -m) <(PHP_INI_SCAN_DIR=... php -m)` empty; suite 7-8 min: `SABOTAGE_SUITE_TIMEOUT=1500`; a timeout reads as "undetected"  [Observed: 2026-09-13/26; host may have changed 2026-09-28] |
| starts any job over ~8 min | background tasks die ~10 min; `setsid nohup ... > log 2>&1 < /dev/null & disown`, wait on the job's own `EXIT=` marker; a `pgrep -f` waiter matches itself, `pkill -f` kills your own shell (exit 144): kill by PGID  [Observed: 2026-09-04/25/26] |
| stops/recreates a watcher or reads `exited (137)` | 137 after a stop is by design (pass in flight > `stop_grace_period 5m`); check `docker inspect --format '{{.State.OOMKilled}}'` first; compose detached via `setsid`, never under foreground `timeout`  [Observed: 2026-09-07] |
| edits `tools/verify-deploy.sh` or finds a hex-prefixed container | three states (is the service: force-recreate; dead leftover: `docker rm -f`; another project's: count, never name); key the map per ROW, not per service  [Observed: rows 62-63] |
| queries Gmail via MCP | `label:` takes the hyphenated NAME (`rent-watch-portails`), not the ID; `{}` != "nothing unread"  [Observed: 2026-09-13] |
| captures/scrubs an email or page fixture | one placeholder per DISTINCT value; run the adapter over the RAW twin, require the same offers (a dropped link count is a defect); pass your own name as a scrub needle; never delete an alert email  [Observed: 2026-09-24, 2026-08-31] |
| calibrates a threshold right after `--seed` | `--seed` records WITHOUT judging (outcome NULL, doctor says 0 matches); judge with console-only `run --once -v`  [Observed: 2026-09-14] |
| adds a persisted safety fact | ask at what scope it is decided (cluster, not row); write it on every member, read the most restrictive  [Observed: 2026-08-30] |

## 6. Machine facts that mislead
- Local PHP is a dev/ZTS/DEBUG/GCOV build (8.6/8.7-dev observed; notes disagree): JIT assertion aborts (exit 134) are the harness, not detection; read `php -v`. CI: PCRE2 10.42, PRODUCTION ini.  [Observed: 2026-09-26, 2026-10-02]
- `uptime` prints LOCAL time (CEST); use `date -u`.  [Observed: 2026-09-14]

## 7. Known stale or contested (check before quoting; full list REFERENCE §11)
- Q16/Q17 "Default if unanswered" text, Q38 "UNANSWERED", Q5 prototype floor/elevator, "yield 0", "0 matches", "S8 as multiplier" (superseded by Q31), `Enrich/Transit.php` (it is `NavitiaCommute`), pre-09-04 constants on `Store::` (now `Core/RunStore`): superseded.  [Source: Q-file contradictions]
- CLAUDE.md Hard rule 6 says "no web UI"; Q17 ruled a read-only localhost digest.  [Ruled: 2026-08-06]

## 8. Pointer table: what to read in EXPERTISE-REFERENCE.md
| Task touches | REFERENCE § |
|---|---|
| Hard filters, location, rent basis, rooms, surface | 1 |
| Tenure classifier, vetoes, plafonds tier 4, corpus | 2 |
| Score, push gate, digest, rollup, heartbeat, routing | 3 |
| Config and schema rules, `reclassify`, store schema | 4 |
| Source onboarding, per-source quirks (rent) | 5 |
| Car and job domain sources | 6 |
| Alert email capture, scrubbing, MIME and portal parsers | 7 |
| Health thresholds, pacing, robots, SOURCE_BROKEN, pattern-miss counting | 8 |
| Deploy, Docker, backups, Q36 seed guard | 9 |
| Sabotage ledger, CI, long jobs | 10 |
| Stale or contested entries | 11 |
| Open questions | 12 |

## 9. Open questions that most often bite (REFERENCE §12)
- Q40: scrubbed captures (a subscriber name, a base64-wrapped address, a browser-side maps key) remain in the PUBLIC history; default accept. "Tree is clean" != "exposure is over".  [Observed: 2026-09-07]
- AL'in (only route to Action Logement ESH stock; section 3); AutoScout24 alert never arrived; SeLoger IMAP window truncated (509 messages, 500 read) on 2026-10-01.  [Observed: 2026-08-20, 2026-10-01; perishable]
