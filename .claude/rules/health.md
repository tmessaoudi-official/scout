---
paths:
  - "src/php/Core/**"
  - "src/php/*/Cli/**"
  - "compose.yaml"
  - "Dockerfile"
  - "tools/**"
  - "config/**"
---

# scout gotchas — health

Moved verbatim from CLAUDE.md § "Gotchas & pitfalls" on 2026-09-28 (review-remediation 5.4, /rules-split). Scope: source health, doctor, run records, the deploy gap, the silent-feed and same-filter warnings. New lessons for this area go HERE, not into CLAUDE.md. A § reference names a heading in CLAUDE.md or in docs/ENGINEERING-NOTES.md.

- **A FIXTURE-BACKED `doctor` WRITES A RUN INTO THE LIVE STORE, and that is how a healthy source is
  made to report `broken`.** `MAILBOX_DIR=…` swaps the mailbox; it does NOT swap the database, so the
  run is recorded against the default `RENT_SCOUT_DB` / `CAR_SCOUT_DB` / `JOB_SCOUT_DB` and its item count becomes
  part of the 7-day baseline every later LIVE run is judged against. Observed 2026-09-01: proving
  the new car source offline wrote `leboncoin item_count=5` into `state/car-watch.sqlite3`; every
  live pass after it returned 0, because that portal's alerts are unlabelled and the source reads a
  label — so `SourceHealth` said `broken · 6 runs consécutifs à vide alors que la référence
  précédente était de 5.0 annonces`, a **SOURCE_BROKEN alert on a premise made of fixture data**.
  The rent store took the same treatment more mildly (a `bienici item_count=10` run beside a live
  mean of ~250). Hard rule 2 is about health verdicts being BELIEVABLE, and this makes one that is
  not. **Always pair `MAILBOX_DIR=` with a throwaway database** — the per-source `doctor --source=` invocations in
  `docs/ENGINEERING-NOTES.md` do (`RENT_SCOUT_DB=$(mktemp -u) MAILBOX_DIR=…`) — exactly
  as `--seed` is already documented to need one. Recovering from it means deleting the synthetic
  `source_runs` row: back up first (`tools/backup-state.sh <db>`), delete by exact id, then re-run
  `doctor` and confirm the verdict changed.
- **A green tree says nothing about what the watcher is running — `src/` is baked into the image.**
  `compose.yaml` mounts `./config` and `./state`; the code comes from `scout:local`. So a fix
  can be committed, pushed, CI-green and *still not protecting anyone*. Measured 2026-08-23: the
  deployed watcher was seventeen hours old and predated **all of Phase 2 and 2b** — the §1
  fail-closed hydration gate was built, certified and unarmed in production the whole time, and the
  production database was still at schema v4 while the repo was at v6. Nothing in `git status`,
  `git log` or a passing suite says so. The check is
  `docker image inspect scout:local --format '{{.Created}}'` against the commit date of the
  last change under `src/`, and `SELECT value FROM schema_meta WHERE key='schema_version'` against
  the repo's current version. Redeploy recipe: `README.md` § Deploying it.
- **What saved that seventeen hours was two unrelated filters, which is not a defence.** Both live
  In'li listings that Phase 2b proved were **PLS** are `T2`/`2 pièces` at 47.6 m² and 43.4 m², so
  `min_rooms: 3` **and** `min_surface_m2: 50` each rejected them on their own, before tenure was ever
  the deciding question. The disarmed §1 gate cost nothing *this time* because two size thresholds
  happened to sit in front of it. A first draft of this entry named `min_surface_m2` as the sole
  cause, inferred from the surfaces in the titles without reading the room count — the repo's own
  *"a true number attached to an invented cause"* failure, committed while writing the entry that
  warns about it. The room count is in `listing_detail.fields_json`, one query away. Cross-checking all 54 notified
  In'li rows against their hydrated verdicts found 53 genuinely `LLI` and one `UNKNOWN` — a listing
  notified as a match that should have gone to the digest, its detail page being a 404. **Never read
  "no harm occurred" as "the rule held"**: ask which mechanism actually did the rejecting, because a
  filter that happens to be upstream today can be widened tomorrow, and Q1–Q3 widened three of them
  in one day.
- **A COUNT THAT NEVER VARIES IS ITSELF A SIGNAL, and for a month nothing read it.** Measured
  2026-08-28: `leboncoin` reported a healthy `item_count = 3` on **263 consecutive passes**, every
  one of them re-reading ONE email dated 26 August that `SEARCH SINCE 7 days` kept matching. Every
  existing verdict was correct and every one said healthy — the baseline was 3 and the last count was
  3, so nothing dropped; no run failed, so nothing was flaky; the schedule never stopped, so it was
  not `STALE`. A source re-reading one frozen message is indistinguishable from a source receiving a
  steady trickle. It would have self-corrected only when the message fell out of the window, days
  late and blaming the expiry rather than the silence. `SourceStatus::FEED_SILENT` (schema v11) is
  the fix, and **the signal is the newest MESSAGE date, never listing novelty** — "no new listing for
  N days" is also exactly what a quiet market looks like, so it restates hard rule 2's ambiguity
  instead of resolving it (Logirep returns the same 113 listings every pass by design). `STALE` is
  the twin from the other end: that one says the WATCHER stopped, this one says the PORTAL did.
  **Confirmed in production 2026-08-29**, which is the half a design note usually lacks: on the
  first `doctor` run after the redeploy `leboncoin` reported `feed_silent` — *"le portail n'a rien
  envoyé depuis 3 jour(s) (dernier message : 2026-08-26T05:33:06Z) — 3 annonce(s) relues du même
  courrier"* — while the other seven sources, including the three other email ones, all reported
  `ok` with real counts. **The counterweight run is the load-bearing half of that sentence:** a
  verdict that fires on every source is indistinguishable from one that fires on none, and only a
  pass showing both outcomes at once separates them.
- **A SINGLE FAILED RUN WAS A BROKEN SOURCE, AND THE COOLDOWN COULD NOT DAMP IT (2026-09-07).**
  `RunStore::health()` returned `BROKEN` on ONE failed run, while the empty path had required three
  since it was written. `Pipeline::alertOnHealth()` then read the next successful pass as recovery,
  sent *rétablie* and CLEARED the cooldown row — so every isolated blip cost exactly two emails and
  the cooldown could never apply to anything. Measured over four days: **in'li alone sent 29 broken
  + 30 rétablie out of 428 runs**, while returning 165 annonces on the passes either side; 77 flap
  emails across both domains. **The fault was the portal's, and that was measured rather than
  assumed**: 44 of in'li's 59 failures are an HTTP 302 to its own `/maintenance`, the rest are
  host-specific TCP refusals, and cityloger, logirep and seloger failed **0 times in 632+ runs**
  through the identical stack — nothing on our side is that selective — while a live probe answered
  200 three times running. **A THRESHOLD ALONE WOULD HAVE MOVED THE NOISE RATHER THAN REMOVED IT**,
  and that is the half worth carrying: two branches downstream read the failed run's `item_count` of
  0 as an observation. `WARN_DROP` fires at `lastCount < rollingMean * 0.3`, and 0 against a mean of
  165 clears it, so the same flap continues under a different subject line; and `!isAlerting()`
  reads as RECOVERY, so a source with a real standing alert announces itself recovered on a hiccup
  and re-alerts with its cooldown wiped. Both are **hard rule 9 at the health layer** — a failed
  run's zero is *unknown*, not *zero annonces*, and it is no more evidence of recovery than it is of
  a drop. `rollingMeanBefore()` already knew that and filters on `ok = 1`, as does
  `lastProductiveCount()`; nothing that COUNTED did. (This sentence read *"nothing else did"* for a
  day and was refuted by its own file — the C2 milestone panel found the claim and its refutation
  three commits apart.) So the
  count-based verdicts judge the log with sub-threshold trailing failures REMOVED, while `STALE` and
  `WARN_FLAKY` keep the whole log on purpose — they are about ATTEMPTS, and a failure is a perfectly
  good attempt. Three things travel with it. **The strip needs an observation behind it**: a source
  whose entire history is failures still reports `BROKEN`, or one misconfigured on the day it was
  added would hide. **The tolerated failure is named on EVERY verdict**, from the one funnel every
  status passes through — the first cut put that note in the `OK` branch alone, and
  `testAFailedRunOutranksASilentFeed` is what caught it: the source came back `FEED_SILENT` with the
  exception buried, the exact thing that test's own docblock forbids. **And it closed a second flap
  nobody had measured**: `trailingEmptyRuns()` stopped at a failed run, so one hiccup RESET a dead
  feed's empty streak and bought it three more silent passes — on leboncoin, whose streak was in the
  hundreds. The threshold is `EMPTY_RUNS_BEFORE_BROKEN`, one constant for both shapes, so a future
  tuning cannot move one and forget the other.
  **THE FIRST CUT DROPPED ONLY A TRAILING FAILURE, AND THE LIVE STORE REFUTED IT WITHIN THE HOUR** —
  which is the half worth carrying. Run against a copy of the real car store, leboncoin's failure sat
  THREE runs from the end, so a 308-run empty streak still truncated to 3; the streak rebuilding
  through 1 and 2 reports `OK`, which is *rétablie* plus a wiped cooldown, then `BROKEN` again at 3.
  **The flap survived its own fix, on the one source the fix was not about**, and no fixture reached
  it because every test written for the change put the failure last. A tolerated failure is dropped
  WHEREVER it sits now — `observedRuns()` drops each failure episode shorter than the threshold and
  keeps the rest, so a real outage still breaks a streak. Two things came out of that same probe:
  the note must count the TRAILING streak, never every failure ever dropped (the cumulative form put
  *"82 échec(s) toléré(s)"* on in'li's healthy verdict — true, and it reads as an incident on a
  source that is fine); and `$emptyStreak` counted in `$observed` must be INDEXED in `$observed`,
  a mismatch that reported a 25-listing baseline as 12.5 and was caught by an existing test rather
  than by review. **Verify a health change against a copy of the live store** —
  `sqlite3 state/<db> ".backup <copy>"`, then call `health()` on the copy; never `doctor`, which
  polls and writes a run into the baseline. Two costs are stated rather than left to be found:
  the five IMAP timeouts on capcar, bienici and agorastore (all isolated, under 1 % of runs) are now
  tolerated too, which is the intent; and because the strip refuses an empty remainder, a source
  whose very first run fails still alerts once — a brand-new source has no observation to be judged
  against, and hiding it would be the worse error.

- **`RENT_FEED_SILENT_DAYS` should stay under `IMAP_SINCE_DAYS` — and `doctor` WARNS, it does not refuse.**
  This shipped as a hard startup refusal on 2026-08-28 and was demoted the next day, because **both
  of its legs broke under review**. Its premise was *"the newest message `SEARCH SINCE` can match is
  by definition at most `IMAP_SINCE_DAYS` old"*, which is **false**: `SEARCH SINCE` filters on
  **INTERNALDATE** (server arrival) while the threshold is measured against the message's own
  **`Date:` header**, so a message delivered today and stamped weeks ago — a bulk re-label, a delayed
  relay — is inside the window and arbitrarily old. Demonstrated at twenty days. And the refusal
  **locked the tool out**: `IMAP_SINCE_DAYS=1` left no satisfiable threshold, so `doctor`, `dump`,
  `run`, `digest` and `reclassify` all exited 2, *including on deployments with no email source at
  all* — a regression, since the same value was previously just clamped — while a refused `run` wrote
  a note meant to be read on the next successful start, which could never come. **`doctor` DIAGNOSES,
  it does not refuse**, exactly as it already does for an unusable `TZ`. The guidance survives the
  refusal: the observable band is `(threshold, window)`. The default of 3 is measured, not chosen —
  over 14 days Bien'ici fires ~30/day, PAP ~8/day and SeLoger 160 in a week, none ever quiet for a
  full day, while leboncoin has sent exactly one alert since creation. **Since 2026-08-29 the
  threshold is also settable PER SOURCE** — `feed_silent_days` on an `email_alert` block, refused
  at 0 and on any type that reports no feed date — and it reaches the store through exactly ONE
  funnel, `EmailAlertSource::health()`, which `doctor`, the pipeline and the heartbeat all read
  (the beat used to read `Store::health()` by name and would have counted a source healthy on the
  very pass `doctor` called it silent). `doctor` gives a per-source value the same window advice.
- **EVERY CARD OF A SOURCE FAILING THE SAME HARD FILTER IS A WARNING (row 41, 2026-09-05), and
  it is the instrument the round-5 P2 asked for.** With no band on the mapped path, a selector
  drifting onto a 5-digit field extracts `95240` cleanly — no miss counted, every card rejected
  by `max_rent_cc`, health `ok`. `Core/SameFilterWarning` is ONE implementation for every pipeline (rent, car, job):
  each judged card counts into a per-source tally keyed on its disqualifier with the numbers
  normalised, and when every card of a source (three or more) failed the same filter the pass
  carries one warning naming the source, the count and the filter — `RunResult::$warnings`, printed
  beside the errors and never counted as a failure. **§1 and vehicle-set rejections are excluded
  on purpose**: a source whose every card is social housing is the classifier working, and
  counting it would fire the one honest signal on exactly the sources the rules exist to refuse.
  It says nothing about a PARTIAL drift, and it reaches the run output, not `SourceHealth`.
