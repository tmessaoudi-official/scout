---
name: domain-scraping-sources-health
description: Use when a task onboards or edits a scout source (html/json adapter, email_alert block, sitemap vehicle source), touches robots, pacing, health thresholds, SOURCE_BROKEN or FEED_SILENT, pattern-miss counting, detail hydration, or audits a zero-result source, in rent, car or job; paths config/*/sources.json, src/php/Rent/Adapters/**, src/php/Adapters/Http/**, src/php/Core/{RunStore,PatternMissLog,CountsPatternMisses,Pacer,SameFilterWarning,SourceStatus}.php, docs/SOURCES*.md. How an expert works here - procedure, traps, checks, evidence.
---

Review date: 2026-10-03 11:44   Validation mode: advisory   Core: .claude/rules/expertise-core.md

## Roles and mental models
- **Zero-result auditor**: any source can fail as "quiet market". For every count ask what counts it and who reads it; a count nobody reads is a defect.  [Source: C Hard rule 2; Observed: 2026-09-02 In'li `cp` 171/171 dead while `ok`]
- **Route surveyor**: pollable or email-only is decided by measurement, never memory, never a 200.  [Ruled: developer 2026-08-06]
- **Polite poller**: honest UA, robots per page, paced, never a challenge-defeating browser.  [Ruled: developer 2026-08-07, 2026-08-26]
- **Config-only onboarder**: a source is a block in `config/<domain>/sources.json`; `src/php/Adapters/sites/` does not exist (verified 2026-10-02) and having to create it is itself the finding.  [Source: docs/SOURCES-LIVE.md]

## Path traps in the router (verified 2026-10-02)
`src/php/Adapters/Html/` is an EMPTY dir. The html adapter is `src/php/Rent/Adapters/HtmlSource.php` (+ `Html/Selector.php`), json `HttpJsonSource.php`, plus `ListingMapper`, `Payload`, `PacedSource`, `EmailAlertSource`; car `src/php/Car/SitemapVehicleSource.php`. `src/php/Adapters/Http/` holds only transport (`CurlHttpClient`, `Robots`, `RobotsResolver`, `ReplayHttpClient`); health, pacing and miss counting are in `src/php/Core/`.  [Observed: ls]

## Hard rules (delta over CLAUDE.md and health.md)
| Rule | Source |
|---|---|
| Endpoint from a live capture only. A 200 proves a site, not a feed; a marker count is not a route census. | [Ruled: C rule 1; Observed: 2026-08-21/22] |
| Robots enforced per paginated and per detail page. 404/410 allow; 5xx = assume full disallow; 403 and an HTML 200 fail closed. A `null` Robots means "never check": until 2026-08-21 production passed `null` at both construction sites, so robots held in tests and never on a real poll. `RentScout::robotsFor()` fails closed for a source with no derivable origin. | [Observed: 2026-08-21/25; Source: R 8] |
| Robots matching is literal and case-sensitive (CDC `/Recherche/` disallowed, `/recherche/` allowed, hence `page_path`; Logirep `/search/` vs `/recherche`; PAP `Disallow: /*?*` refuses every query URL, a plan once inverted it). Read the rule, never paraphrase. | [Observed: 2026-08-20/09-09] |
| One honest UA constant, a caller UA is refused; no FOLLOWLOCATION (a 3xx returns to the caller, pinned by `testARedirectIsReturnedToTheCallerRatherThanFollowed` in `NetworkAdaptersTest`); only `SitemapVehicleSource::sameLotTarget()` follows ONE hop (same id, same host, robots-checked, paced). | [Source: T 7.1; Observed: 2026-09-26] |
| A bot challenge (`/shield` cookie, DataDome 403 on every route) is a ruling-class refusal like CAPTCHA: route = email alert. Final dead ends: SeLoger polling, Val d'Oise Habitat, RIVP, Poste Habitat, Batigere API, Alcopa email. ICF Novedis and Seqens were removed by evidence; do not retry without new evidence. | [Ruled: developer 2026-08-26, 2026-08-23] |
| Pacing (Q37): 15 min +/- 5 jitter; >= 5 s between distinct hosts, >= 60 s per host; shuffled order; `Core/Pacer` + `Rent/Adapters/PacedSource`; `Source::host()` null = never delayed; `--once` unpaced. A banned IP looks like every source going quiet together. | [Ruled: developer 2026-08-07] |
| Refusals are source-scoped (Q26/Q28): a bad block disables ONE source and shows as a status; invalid `criteria.json` refuses startup; an enabled URL still containing `REMPLACER` is refused (a `_comment` is discarded by the loader); `legal_risk: true` needs `--i-accept-legal-risk` per invocation. | [Ruled: 2026-08-07] |
| Identity scheme is chosen BEFORE enabling: nothing migrates stored keys; link-to-content re-notifies the whole backlog. | [Source: T 7.2] |
| `item_count` = what the ADAPTER parsed, before criteria; no verdict reads matched counts. A failed run's zero is UNKNOWN. A selector or path matching nothing THROWS, never `[]`; a throw is for a STATE, an event is a warning. | [Ruled: developer 2026-08-07 Q30; Observed: 2026-08-26, 09-07] |

## Health constants (CLASS+CONSTANT in `src/php/Core/RunStore.php`, verified 2026-10-02)
`ROLLING_WINDOW_DAYS` 7 (also STALE bound), `EMPTY_RUNS_BEFORE_BROKEN` 3, `FLAKY_FAILURE_RATIO` 0.3 / `MIN_RUNS_FOR_FLAKY` 3, `FLAKY_SHORT_WINDOW_DAYS` 1 / `FLAKY_SHORT_FAILURE_RATIO` 0.2 / `MIN_RUNS_FOR_SHORT_FLAKY` 20, `MIN_SPAN_FOR_NEVER_PRODUCED` 604800 s, `BUSY_TIMEOUT_MS` 5000. `SourceStatus`: NEVER_RUN, NEVER_PRODUCED, STALE, OK, WARN_DROP, WARN_FLAKY, BROKEN, FEED_SILENT. Left as is until a month of `doctor` history; prose citing `Store::` or a 1-day value is stale. Never retune to a reading.  [Ruled: developer 2026-08-07 Q23]
- The short window needs ~100 passes/day, so it is inert in a cron `--once` deployment (one failure of 4 = 25 %). 7 days for never-produced because 1 day false-accused a new source at hour 24; the stated cost is a broken field map on a NEW source unremarked for a week.  [Source: Q23]

## Zero-result auditing: what counts it, who reads it
| Zero shape | Counted by | Read by | Blind spot |
|---|---|---|---|
| Adapter returns 0 after producing before | `source_runs.item_count` | `RunStore::health()`: BROKEN after 3 empty runs | a NEW source that never produced: only NEVER_PRODUCED at 7 days |
| One configured pattern misses on EVERY card (floor 3 cards) | `Core/PatternMissLog` via `CountsPatternMisses` | `escalate()`: OK -> WARN_DROP only, never BROKEN, never downgrades BROKEN/STALE/FEED_SILENT; `PatternMissEscalationTest` finds implementors by reflection | partial misses, silent by design (cityloger 9 of 60 null surfaces: a selector scoped narrower than the text) |
| Selector drifts onto a wrong field (rent `95240`) | `Core/SameFilterWarning` | run warning when >= 3 cards all fail one filter; NOT in `SourceHealth` | partial drift; section-1 and vehicle-set rejections excluded on purpose |
| Email feed re-reads one frozen message | newest message `Date:` | FEED_SILENT (`feed_silent_days` per block) | never listing novelty: a quiet market looks the same |
| Watcher dead | `Core/Heartbeat` (24 h, `isDue()`-gated) | the human; not a channel check, use `test-notify` | beat scope = what the run WATCHES |
- Only a CONFIGURED key can miss. A pattern that HITS a placeholder (`make_model_unknown_pattern` capturing `autres`) is never counted (it would hold the ratio near 100 %); `subject_pattern` rejecting is the filter working; car guard = `VehicleSourceLoader::PATTERN_PARAMS` minus `UNREAD_PARAMS`.  [Observed: 2026-09-02]
- Counting is not reporting: a new adapter must route through `escalate()` (four once counted while `health()` never read `total()`). Count-based verdicts judge the log with failure episodes shorter than the threshold removed wherever they sit; STALE and WARN_FLAKY keep the whole log; an all-failure history still reports BROKEN. Measured: in'li 29 broken + 30 retablie emails over 428 runs, 44 of 59 failures a 302 to `/maintenance`, others 0 failures in 632+ runs. Verify on `sqlite3 state/<db> ".backup <copy>"`, never `doctor`.  [Observed: 2026-09-07]
- A new verdict needs a counterweight pass (leboncoin `feed_silent` while others stayed `ok`, 2026-08-29): firing on all is firing on none.

## Onboarding by adapter type (delta over /add-source)
- **html**: `total_selector` checks the walk against the count the page states (walk-until-empty is a termination rule, not proof); `page_path` when robots forbids query params; out-of-range page answers 301 so non-2xx is refused; a card with no tenure needs `detail_map`.  [Observed: 2026-08-19/21]
- **json**: `embedded_json_selector` for JSON in a `<script>` (no match THROWS). Drupal/Solr one-element lists: `Payload::scalarOf()` unwraps scalar lists only, never `0`; before it `matchesCommune()` refused null, zero matches, health green.  [Observed: 2026-08-22]
- **Detail hydration** (`listing_detail`): keyed `(source, external_id)`, never `dedup_key`; the gate is the CACHE, not a per-pass predicate; `detail_budget_per_pass` 20 (`0` refused). Config-shaped failures THROW, runtime ones are recorded (6 h backoff, 3 tries); throwing used to void the pass and raise a false SOURCE_BROKEN. `detailRead` means the page YIELDED evidence, not "fetched".  [Observed: 2026-08-23; Source: rent.md]
- **email_alert** (parsing and scrubbing: alert-email pack): `link_host` must carry the PATH (PAP's unsubscribe page shared the host, phantom second listing); pin `params.from` to the alert subdomain (12 of 50 SeLoger mails were receipts with an unfilled template); a positional pattern that misses yields null, never a generic scan.  [Observed: 2026-08-25/26]
- **Pre-checks**: WordPress `wp-json/wp/v2/types`, `sitemap_index.xml` (check the `<loc>` HOST: Logirep's 247 URLs sat on a 403 host). "0 resultat" needs a CONTROL query (Poste Habitat: `type-bien=parking` returned 8, a NATIONAL zero). A 404 can be a 109 KB styled page.  [Observed: 2026-08-21/26]

## Per-source quirks (perishable, dated 2026-09-08; re-derive with the `php -r` and read-only `sqlite3 "file:state/<db>?mode=ro"` commands at the bottom of docs/SOURCES-LIVE.md)
- In'li: card text is price, rooms, surface, commune only (`exclude_title_patterns` dead without `detail_map`); the flap source. CDC Habitat is `enabled: true` in config (verified 2026-10-02): text saying it ships disabled is stale. Logirep: one request, cheapest. Cityloger: card has NO tenure (asserted by test). pap: no listing prose, detail refused (bot challenge).  [Observed: 2026-08-20..26]
- `fixture_demo` once shipped enabled for two weeks and put ten nonexistent flats in every total; it and `email_demo` are `enabled: false` (verified).  [Observed: 2026-09, 2026-10-02]
- Mailbox windows and alert arrival (SeLoger truncated at 509 messages on 2026-10-01, AutoScout24 never arrived) are perishable: re-read the mailbox.

## Car and job reuse (same Pacer, Robots, RunStore, PatternMissLog; no tenure)
- Car excluded-vehicle set is CODE, negation read first; content key `sha1(source|folded title|year|km)` with price OUT, behind a no-information floor (agorastore waives it: its lot reference is evidence). Config lists 7 car and 7 job sources (verified).  [Ruled: developer 2026-09-01; Source: SOURCES-LIVE]
- A car fix is not a rent fix: round-7 P0 `CarScout::collectRollup()` discarded a REJECT; the car store has no `notified_as`. Enumerate both pipelines.  [Observed: 2026-09-05..07]
- Job sources are email-only and cards state little; never build one blind: search `label:job-watch-portails` by sender, capture with `tools/dump-eml.php`, scrub, compare with the raw twin. `--seed` records without judging; judge with console-only `run --once -v`. The deployed job gate (`push_min_score` 40) lives in gitignored `criteria.local.json`, so a clone differs from the watcher.  [Ruled: developer 2026-09-25; Observed: 2026-09-14]

## Traps with the failing symptom
| Trap | Symptom | Source |
|---|---|---|
| `MAILBOX_DIR=` doctor against the live DB | SOURCE_BROKEN on fixture data (`leboncoin item_count=5`, then 6 empty runs). Use `RENT_SCOUT_DB=$(mktemp -u)`; repair per health.md | [Observed: 2026-09-01; H] |
| `\s*` crossing newlines | PCRE backtrack limit, `preg_match_all` false, recorded as a miss = whole-source outage; use line-by-line patterns with possessive separators | [Observed: 2026-09-24; D K] |
| Missing positional anchor | PAP read the saved-search 45 m2 not the ad's 50; title anchor absent on 37.5 % of SeLoger cards; one room pushed as MATCH | [Observed: 2026-08-26, 09-01] |

## Before-you-start checks per path
- **`config/*/sources.json`**: unknown keys are loud errors (`_comment|_why|_source|_verified_at` only); read `Rent/Config/ConfigLoader.php` for accepted keys; `ConfigTest::testEveryCorpusSourceAgreesWithConfig` ties tenure keys to the corpus; capture a real payload (`bin/scout --domain=rent dump`), `doctor --source=X` on a throwaway DB, hand-count.
- **`src/php/Rent/Adapters/**`** and **`Adapters/Http/**`**: read `PacedSourceTest`, `HtmlSourceDetailTest`, `RobotsResolverTest` before adding a hop; every new request passes robots and the pacer.
- **`src/php/Core/{RunStore,PatternMissLog,CountsPatternMisses,SourceStatus}.php`**: `RunStoreFailureStreakTest`, `RunStoreFlakyWindowTest`, `PatternMissEscalationTest`; test BOTH directions and the failure mid-log, not last.

## Evidence table (what certifies a change here)
| Change touches | Certified by | Sabotage shape | Stays uncertified unless run |
|---|---|---|---|
| New or edited source | fixture from a REAL payload, `doctor --source=X` on a throwaway DB, hand-counted listings | drop the separator, swap the identity scheme, narrow the selector scope | **the first DEPLOYED pass** (rebuild, `docker compose logs` to `annonce(s) analysees`) |
| Health verdict or threshold | `RunStore*Test`, `PatternMissEscalationTest`, `.backup` copy of the live store | force the streak condition true; failure mid-log | live flap rate |
| Pattern-miss counting | reflection test over implementors | drop `escalate()` from one adapter; make every card miss | partial-miss drift (silent by design) |
| Robots, pacing | `RobotsResolverTest`, `PacerTest`, `PacedSourceTest` | pass a `null` Robots | real robots on the live host |
Never certified by gates: yield, a portal's next template change, delivery to a human, a banned IP. Declare each by name.  [Source: core EXPERTISE s4]

## Reviewer lenses
1. **Silence**: can this source return zero or wrong data while health says `ok`?
2. **Politeness**: does a new request skip robots, pacing or the honest UA, or follow a redirect?
3. **Scope**: is an n=1 capture or one source stated as true of the tree?
