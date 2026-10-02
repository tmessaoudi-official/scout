# CLAUDE.md — scout

> This file holds the RULES for how Claude delivers code here — quality, carefulness, gates, and the
> eligibility boundary that governs this whole project. The product itself (spec, decisions, open
> questions) lives in `spec/` and `docs/`. Boundary test before adding anything: *does Claude need this
> to deliver correct code?* If not, it belongs in `docs/`, not here.

scout is a **self-hosted watcher for rental listings in Île-de-France**. It polls institutional
landlords, ingests private-portal alert emails over IMAP, classifies every listing by French housing
**tenure type**, filters and scores it against personal criteria, and pushes a notification within
minutes of publication. Single language, single user, single machine. CLI plus push notifications —
**no web UI**, by design.

The full specification is [`spec/PROJECT_BRIEF.md`](spec/PROJECT_BRIEF.md). It is the source of truth
for the product, and **every constraint in it is a ruling**, not a draft to be improved on. Read it
before touching anything under `src/`.

Status (rewritten 2026-10-02 — the earlier paragraph still said a network adapter was missing): the rent, car
and job domains all run, deployed as three watchers (rent-scout, car-scout, job-scout). Eight rent sources are
live (polled institutional landlords plus private-portal alert emails over IMAP); the store is schema v12; the
corpus is 143 cases (`tests/fixtures/rent/tenure/corpus.json`); the rent code lives under `src/php/Rent/`
(`Core`, `Config`, `Adapters`, `Store`, `Enrich`, `Notify`, `Cli`), generic pieces under `src/php/Core/`,
`src/php/Adapters/` and `src/php/Config/`. CI runs the suite on every push and a six-shard sabotage ledger
nightly. Open inputs, not code: AL'in (needs a DevTools cURL capture — hard rule 1) and AutoScout24 (no alert
has ever arrived). History and per-feature records: `docs/ENGINEERING-NOTES.md`.

`scout --domain=rent doctor`, `scout --domain=rent dump`, `scout --domain=rent run --once/--seed`, `scout --domain=rent test-notify`, `scout --domain=rent digest` and
`scout --domain=rent reclassify` all work end to end today.

**Q34 IS CLOSED IN ALL THREE PATHS as of 2026-08-26** — the daily floor was the last one, and it had
been ruled, configured and unbuilt: `digest_hour` was parsed into `NotifyPolicy`, printed by
`doctor`, and read by nothing at all. `Core/DigestSchedule` is the policy (pure, clock injected,
mirroring `Core/Heartbeat`) and `state/rent-digest.txt` on the mounted volume is the marker, written only
after the channel confirms. Three rules travel with it. **It is SILENT on a day with nothing
pending, and records no window as served** — the heartbeat already carries daily liveness, so an
unconditional rollup would be a second scheduled push saying nothing new, and leaving the window
open is what makes *"an unsent digest is retried"* work. **It runs under `--watch` only**, so a
cron-driven `--once` deployment has the two event-driven paths and no floor; `doctor` says so.
And **the drain is SHARED with `scout --domain=rent digest`** (`Cli/DigestBatch` + one collector that never throws
and never prints), because two implementations of §1's only landing zone is how one drifts into
announcing what the other would withhold.

> **Its zone justification was WRONG when first written, and the correction is the part worth
> keeping.** It said PHP does not consult `TZ`, so computing the floor from the default zone would
> fire it at 10:00 Paris in summer. The measurement is real — `php -r` in the deployed container
> reports `UTC` with `TZ=Europe/Paris` set — and the conclusion does not follow, because
> `bin/scout:44` already calls `date_default_timezone_set()` from `TZ` before `Scout` is
> constructed. **A true number attached to an invented cause**, produced by measuring the runtime and
> never reading the entrypoint; `compose.yaml`'s TZ comment was accused of the same error, is
> likewise correct, and was left alone. What the explicit zone actually buys, both measured: an
> unusable `TZ` becomes a loud startup refusal (`date_default_timezone_set('Europe/Pariss')` returns
> `false`, emits a *Notice*, and leaves UTC standing), and the schedule stops depending on
> process-wide mutable state.

`digest` and `reclassify` closed 2026-08-23 and both carry a rule
worth knowing before touching either, because they look symmetrical and are not: **`digest`
announces an evidence-less row, `reclassify` skips one.** `digest` reads the store rather than the
pass — the pipeline re-offers an undelivered entry only while the ad is still published, so an entry
delisted in between is lost — and a snapshot-less row in that backlog is a listing whose own payload
could not be encoded, which is a live source fault rather than an old row, so skipping it would skip
exactly what the command exists to rescue. **This sentence used to say those rows "predate schema
v7", and that is impossible**: `pendingDigest()` filters on `outcome`, itself a v7 column that is
not backfilled, so a genuine pre-v7 row has `outcome = NULL` and is never returned at all. The
inverted premise was corrected twice in code and left standing here until a fourth review round;
believing it would lead a future session to widen `pendingDigest()` to reach pre-v7 rows, which is
a §1 risk that was explicitly refused — nothing stored distinguishes a pre-v7 digest from a pre-v7
rejection. `reclassify` FORMS a
verdict instead of announcing one, and re-judging on less evidence than the original saw is a §1
breach rather than a smaller improvement: a card whose field says `PLS` while its title says
*logement intermédiaire* classifies `UNKNOWN` by CONFLICT, and on the title alone it becomes a
MATCH. It also runs on the v7 snapshot ALONE — merging `listing_detail.fields_json` would buy no
evidence (the pipeline already rewrites the snapshot post-merge every pass) while making a stored
verdict depend on mapper code that has since changed. `--since` is refused, not implemented, because
its ruled mechanism is a classifier-version column that does not exist. The network adapters exist too — `HttpJsonSource` + `Robots`, `EmailAlertSource` +
`ImapMailbox`/`FileMailbox`, `SmtpTransport`/`FileTransport` — all tested offline against fakes,
with `.env` swapping the real thing in — and the email half was written BLIND, which cost four
defects the day a real alert first reached it (§ "The email-alert path" in [`docs/ENGINEERING-NOTES.md`](docs/ENGINEERING-NOTES.md)). **What is missing is not
code but INPUTS**: a DevTools cURL capture for AL'in (hard rule 1 forbids writing an endpoint from
memory) and IMAP credentials for the alert mailbox. The `plafonds` figures are no longer among them
— fetched, committed and wired on 2026-08-26 (§ "Tier 4"). CI now exists
(`.github/workflows/ci.yml`): the fast job runs the PHPUnit suite, the tenure tripwire,
runner-fetch and ci-workflow self-tests, the drift scan and shell syntax on every push and
PR; the sabotage ledger runs nightly and on demand. **A red nightly opens a GitHub issue, and a
green one closes every open ledger issue again** — it previously notified nobody, and failed 7/7
unnoticed from 2026-08-13 to 2026-08-19 (hard rule 2: an alert computed and never sent is worse than
none). The retraction half landed 2026-08-22 and is the same rule read backwards: nothing ever
closed one, so issues #1 and #2 stood open for days after the regression they reported was fixed and
pushed, and an alert nobody retracts becomes furniture. Both halves are pinned by
`tests/test-ci-workflow.sh` — by step NAME *and* by the API call that does the work, since a name
alone survives the body being gutted. **THE LEDGER IS SHARDED SIX WAYS since 2026-09-05** (developer
ruling), because one job could no longer finish it: 258 → 527 → 764 cases in the three weeks to 2026-09-05, four of
the last eight nightlies CANCELLED at the 240-minute cap and four failed on row 45's CI cause —
eight days with no completed detection proof and seven issues nobody could close. GitHub's hosted
ceiling is 360, so a bigger budget had nowhere left to go. `SABOTAGE_SHARD=<i>/<n>` selects by case
INDEX (stable whatever `SABOTAGE_FILTER` does), refuses a malformed spec and refuses a spec that
selects no case at all — a silently-ignored shard is how six jobs report a clean ledger between them
and run nothing, the `--section=` typo defect one layer up. `fail-fast: false` is load-bearing: one
shard's finding must not cancel the five that were about to find their own. **The alert lives in its
own `sabotage-alert` job** so six shards still open ONE issue and a green night still closes the
whole backlog; it holds the `issues: write` token that the shards no longer do, and it names a shard
whose log is MISSING rather than reading its silence as clean. **`scout --domain=rent run --watch` now runs** (2026-08-19):
`Core/Pacer` holds the Q37 cadence (15 min ± 5, 5 s between distinct hosts, 60 s per host, order
shuffled each pass), `Adapters/PacedSource` is the decorator that applies it — so `Pipeline` never
learns that time exists and `--once` stays unpaced — and `Cli/WatchLoop` is the loop, which SURVIVES
a pass that throws (reporting it) and stops on SIGINT/SIGTERM only after the pass in flight
finishes. `Source::host(): ?string` was added to the contract to make host-level pacing possible;
`null` means the source issues no outbound web request and is never delayed.

**THE FIRST REAL SOURCE IS LIVE (2026-08-19).** In'li is `enabled: true` in `config/rent/sources.json`
with a verified endpoint — `robots.txt` read first (`Disallow: /espace-membre/` only), the search
page fetched, the payload frozen and scrubbed into `tests/fixtures/rent/inli/search.html`.
`scout --domain=rent doctor --source=inli` returns **92 annonces, 4 pages, ~12 s, `ok`**. Its search page is
server-rendered, so there is no JSON API to prefer and it uses the new `html` adapter:
`Adapters/HtmlSource` + `Adapters/Html/Selector`, built on PHP 8.5's own `Dom\HTMLDocument` and
`querySelectorAll` — **no hand-written selector engine was needed**, which is why this cost ~300
lines rather than the ~1 000 estimated. Field maps for `type: html` are CSS selectors with an
optional `@attr` and an optional `=> regex` capture; extraction still funnels through
`ListingMapper`, so hard rule 9 has exactly one implementation. Pagination is real, not deferred:
`page_param` walks pages and `total_selector` CHECKS the walk against the count the page states
about itself, because walking until a page comes back empty is a termination rule and not a proof.

**SOURCE #3 IS LIVE, AND IT IS THE FIRST THAT NEEDS A SECOND REQUEST (2026-08-21).** Cityloger —
`www.cityloger.fr`, the Immobilière 3F group's own lettings platform — is `enabled: true`;
`scout --domain=rent doctor --source=cityloger` returns **51 annonces, ~16 s, `ok`**, and the ref is stable across
two runs. Four things about it change how a source is added here:

- **Its search card carries NO tenure at all.** Not a badge, not a code, nothing — asserted by test,
  so the day one appears the assertion fails rather than the second request continuing forever. On a
  mixed source that meant every listing resolved `UNKNOWN` and went to the *à vérifier* digest:
  correct under §1, and useless. So `type: html` gained **`detail_map`** — a second field map,
  resolved against a listing's own detail page.
- **A detail fetch is one request PER LISTING, so it runs behind a gate — and as of 2026-08-23 the
  gate is the CACHE, not a predicate.** It was `Criteria::matchesCommune()`, injected by the CLI,
  and that shape was wrong for a reason worth keeping: a per-pass predicate makes a listing's
  verdict depend on which pass is looking at it, so a listing the title filter REJECTED while
  hydrated returns as a bare card on the next pass and notifies. What replaced it is in
  § "Detail hydration" in `docs/ENGINEERING-NOTES.md`. `matchesCommune()` survives as rank 1 of the ORDERING, for the
  original reason — it is the only filter whose inputs the CARD carries in full, so using it cannot
  act on a field the detail page would have filled (hard rule 8).
- **A detail map's selectors must address the LISTING, never the page.** Measured on the frozen
  Antony payload: its own `.description` classifies **LLI 0.90**, and the same listing fed its whole
  detail page classifies **UNKNOWN 0.00** — because *"Commission d'attribution"* and *"demande de
  logement social"* are page furniture present on social and intermediate listings alike, and three
  such signals conflict a correct verdict away. This is the CDC `au plus près` failure class on a
  new surface, and `Adapters/DetailHydrator` enforces it structurally: the detail path deliberately
  does NOT add `_text`, so a detail map contributes only what it selects. (It lived in `HtmlSource`
  until 2026-09-01 and was extracted so an email source can compose the same one — a hydrated
  description is what the tenure classifier reads, so it is a §1-adjacent path and gets exactly one
  implementation.)
- **`{page}` may now appear in `url` itself**, for a site whose page number sits mid-path
  (`resultats-location-{page}-defaut-`). Page one substitutes like every other page. The rejected
  alternative — point `url` at the site root and let page one be the homepage widget, whose ten
  cards are identical today — fails silently the day that widget becomes *featured* rather than
  ranks 1–10.

The corpus gained its **first captured SOCIAL case** here, and its third instance of one failure
class: *"Logement intermédiaire géré par un **bailleur social**"* — an explicit intermediate label
sharing a sentence with words that describe who manages the flat, not its tenure. `plus`,
`au plus près`, `bailleur social`: the pattern is excluded vocabulary appearing as ordinary French on
an eligible listing, and it has now cost three fixes.

**Cityloger's live yield was 0 matches when it was onboarded, and that was not a defect — but the
sentence stopped being true the same week and is kept here as an example of why a yield claim needs
a date.** As written on 2026-08-20 it read: all 51 listings sit outside the 78/95 filter, the three
Île-de-France ones being 92 and 77, so nothing is hydrated on a real pass. Both of those departments are INSIDE the region as of
2026-08-22, so those three are now gated in and their detail pages ARE fetched — and the yield is
**still 0, for a completely different reason**: all three quote 1221–1520 € CC and the ceiling is now
1200. Measured on a live pass that day, Cityloger contributed 0 of the 92 notified rows while CDC,
In'li and Logirep contributed 33, 54 and 5. So the claim survived a change that invalidated its
every premise, which is the most dangerous thing a documented number can do. The machinery was
always proven by fixtures rather than by yield, which is the part that has not changed. `docs/SOURCES.md` A6b records why the source is worth
having anyway: 3F's *social* stock is allocated through AL'in and the SNE, so what surfaces on
Cityloger skews to the intermediate and libre stock this project is looking for.

> **THE PROJECT-WIDE "0 matches" CLAIM THAT USED TO STAND HERE WAS WRONG, and the way it was wrong
> is the lesson.** It read *"live yield is 0 because everything is outside the commune filter"* —
> true of Cityloger, and false of the tree. Measured 2026-08-22 by running all four sources against
> live payloads: **474 listings, 457 rejected on location — and 13 got past it.** Twelve of those
> were near-misses (9 In'li LLI at 1017–1353 € CC and a CDC 82 m² at 1669 €, all rejected by
> `min_rooms: 4` alone; two CDC 5-pièces at 112 and 117 m² rejected by the rent ceiling). The
> headline number was right and its explanation was invented, which is worse than being wrong twice:
> a true number attached to a false cause stops anyone looking. **Never generalise one source's
> measurement to the tree** — `scout --domain=rent run --seed -v --source=<name>` on a throwaway
> `RENT_SCOUT_DB` prints every rejection with its reason and costs one poll.

**The notification carries the postcode, the departement, the floor and the lift** (phase 1,
2026-08-22). Headline: `82/100 — Sartrouville 78500 · T4 88 m² · 1450 € CC`; first reason line:
`Yvelines (78) · 2e étage · avec ascenseur`. `Core/Department` is the lookup, Île-de-France only —
those eight prefixes are what the criteria admit, and an unknown postcode returns `null` so the line
is omitted rather than guessed. Every rule on it is **hard rule 9 at the display layer**, each with
its own sabotage case: `floor === 0` is RDC and REAL (read as falsy it vanishes — the display twin
of rejecting a listing for not stating a floor), and an UNMENTIONED lift is not an absent one, so
`null` says nothing while `false` says *sans ascenseur*.

> **THE SAME LINE CARRIES THE AMENITIES SINCE TRACK 7** (developer ruling, 2026-09-08: *show if it
> has terrace, cave, parking spot*) — `Yvelines (78) · 2e étage · avec ascenseur · terrasse ·
> parking inclus`. `Core/Amenities` reads terrasse, balcon, loggia, jardin, cave and the parking
> family (`cellier` was DROPPED by ruling: a cellier is an indoor pantry, not a basement cave, so
> merging them would state something the ad did not). **Display only — it rejects nothing and scores
> nothing** (hard rule 8); a score bonus was offered with the distortion priced (only 15 % of
> matched flats mention any amenity, so scoring it would rank prose-carrying sources above card-only
> ones) and was declined. Three guards, each measured rather than reasoned: a MENTION is not an
> INCLUSION, so `parking inclus` needs the word and the naive `parking … XX €` reader was built and
> rejected at 36 CDC false positives of 38 hits; `terrasse` has a RESIDENCE-NAME false positive
> (`12, les terrasses de la ravinière`, 2 of 38); and `cave` was measured inside a SeLoger tracking
> token, so the reader strips a URL's query and fragment — the tenth instance of *URLs are
> classified text*, and it calls `RawListing::withoutUrlParameters()` rather than becoming a third
> copy of that expression. **ITS REACH WAS ONE MATCH IN TEN, AND SINCE 2026-09-09 IT IS THE DIGEST
> AND THE ROLLUP TOO** (developer ruling). `factsLine()` had a single call site — `match()` — so at
> `push_min_score: 55` the whole departement/floor/lift/amenity line travelled on an individual push
> alone, and the two bins carrying most of the volume showed a headline and a reason and nothing
> else. It splits into `contextBits()` (floor · lift · amenities), shared by the push and by BOTH
> digest lists through one `digestLine()` helper — the two lists rendered byte-identical loops until
> then, which is *a fix landing on one of two symmetric surfaces* waiting to be committed. **The
> DEPARTEMENT deliberately did not travel**, and that is measured: the full line fires on 100 % of
> digest rows, but on 61–74 % of them the departement is the ONLY thing it adds, restating the
> postcode `headline()` already prints two fields to its left (rollup 40/62, tenure bin 58/94, all
> 1 282 stored matches 958/1282); dropped, the line fires on 35–38 % of the two live bins and every
> character of it is new. **That 35–38 % is dated 2026-09-09 morning, and the first DRY-RUN RENDER
> of the queue that afternoon came in at 5 of 50** — four amenity rows and one `7e étage`. **The
> cause is the drain's own ORDER, and THREE explanations for it were measured and refuted first**:
> not *"those portals ship no prose"* (URLs removed, the head 50 carry a median 359 characters
> against the tail's 376, and all 88 rows have a description), not the reason string
> *aucun signal dans l'annonce*, which marks no TENURE signal and prints on the floor-carrying In'li
> row too, and not *"`seen_epoch ASC` takes the oldest rows, and those are the portal cards"* — the
> sources INTERLEAVE across the whole queue (seloger holds positions 1–63, bienici 2–71, In'li
> 3–88), so there is no block of portal cards to take. **`seen_epoch` is the LAST sighting instant,
> and the current-sighting branch of `Store::record()` rewrites it every pass**, so the order is
> least-recently-sighted first: an email row freezes at its message `Date` and drifts forward, while
> a still-published polled row is pushed to the back each time it is seen again. Measured: queue
> positions **72–88 are exactly the 17 rows a POLLED source re-sighted in the last pass** (14 In'li,
> 3 cdc_habitat, all stamped 11:26Z), while 70–71 are one pap and one bienici alert — email rows
> frozen at 11:04Z and 11:08Z, which fall in the same half-hour without having been re-polled at
> all. The head 50 is 47 of 50 bienici+seloger cards, whose prose says `étage`/`RDC`
> once in 50 and `ascenseur` not at all; the 38 behind them are In'li-led and say them 17 and 8
> times — **8/8 of the lifts are the same rows that carry one in their snapshot, and 16 of the 17
> floors are** (one cdc_habitat floor comes from a mapped field rather than prose; the one In'li
> prose mention is `Le bâtiment compte quatre étages`, a COUNT rather than a position, which
> `Core\Prose` refuses correctly — a first draft called it under-extraction without reading the
> row), so the two 17s coincide rather than corresponding.
> **That ordering is a STARVATION shape, not merely a sequence**: In'li is the one source whose
> prose routinely states a floor and a lift, and a still-published In'li flat loses its place to
> every alert that arrives after it, for as long as it stays published. (A `--dry-run` sends
> nothing and marks nothing, so that batch is a READING of the queue rather than a drain of it —
> the 88 rows are all still waiting, which matters because the queue's own re-measurement is
> compared against that count.) The feature is behaving; the BIN moved. A reach figure here is only
> ever true of the bin and the day it was measured on, which this repo has already paid for twice.
> Silence still carries nothing extra — an entry whose ad said nothing is
> byte-identical to its pre-change line, asserted, because a dangling separator would announce an
> absence of information as though it were information.

**INDIVIDUAL HEATING IS PENALISED, AND THE SEVERITY IS A NUMBER RATHER THAN AN ADJECTIVE** (Track
7-A, developer ruling 2026-09-08: *penalise severely, especially electric, gas not as much*).
`Core/Heating` reads the mode and the energy out of the DESCRIPTION — never a new field-map entry,
because `FieldMap::fingerprint()` hashes every mapped field list and one more entry invalidates all
737 cached In'li `listing_detail` rows, which then re-hydrate at 20 per pass over about nine hours
while every In'li flat is judged card-alone. Two negative weights that STACK, the `high_floor_no_lift`
precedent exactly and likewise absent from `positiveTotal()`: electric −35, gas −20, mode-stated-
without-energy −20. Measured through the shipped engine over every stored MATCH at production's
`positiveTotal` of 105: individual pushes go **105 → 85**, and all 40 individually-heated matched
flats move to the daily digest. −30 and −40 were measured and buy NOTHING at `push_min_score: 55` —
they only reorder rows already under it.

Three rules travel with it. **The negation is read FIRST** (`sans chauffage individuel`) — the
lift-negation lesson on a new surface. **An unstated energy takes the BASE penalty only**, never the
electric surcharge: that is the largest class in the store (101 rows, 24 matched) and reading it as
electric would manufacture a fact from an absence. And **the vocabulary was read off the real copy**
— `individuel` and the energy word sit 0–3 words apart in EITHER order, so an adjacency reader
misses 9 of the 35 electric rows, while `convecteur`, `radiateur`, `CPCU` and `reseau de chaleur`
are 0 hits each and are deliberately absent.

> **STATED COST, and it is the whole asymmetry:** `chauffage` reaches the stored text of only THREE
> of the eight sources (In'li 671, Cityloger 75, SeLoger 4), and all 40 individually-heated matched
> flats are In'li. So the penalty ranks In'li flats below portal flats for a fact the portals never
> state. That is hard rule 9 behaving correctly — unknown is not "no" — and not a defect to repair
> later. The first implementation truncated its mode window at 24 characters and the STORE refuted
> it: ` et eau chaude individuels` is 26, so the commonest gas shape came back null, and a reader
> that reads nothing looks exactly like a flat that says nothing. Bounding the GAP is the fix, and
> the case is in the ledger.

## Engineering notes — moved to docs

The dated engineering records that filled 1,554 lines of this file were moved verbatim to
[`docs/ENGINEERING-NOTES.md`](docs/ENGINEERING-NOTES.md) on 2026-09-28 (review-remediation 5.4), per the
boundary test at the top of this file. Each records why
the code is shaped the way it is, usually after a defect — **read the matching note before changing the
behaviour it describes.** Sections:

- § "Detail hydration — the cache is the gate (2026-08-23)"
- § "The push gate — a match under the line waits for the rollup (A5, 2026-09-05)"
- § "The email-alert path — four defects, all found by one real message (2026-08-25)"
- § "A title is a position, never a vocabulary (2026-08-26)"
- § "An extraction that fails is only visible where something counts it (F27, 2026-09-01)"
- § "Bien'ici — source #6, and it disagrees with SeLoger on almost every decision (2026-08-25)"
- § "leboncoin — source #7, and the first HTML-only alert (2026-08-26)"
- § "PAP — source #8, and the numeric twin of the title lesson (2026-08-26)"
- § "Transit enrichment — the last empty layer, and the curve that had to be measured (2026-08-26)"
- § "Status notes accreted after the transit section" — seloger (source #5), the car domain and its sources, the brand lists,
  the job domain, Q27 liveness, `--source` force-runs, `fixture_demo`, the test seams, the Q36 flood guard, the
  closed sabotage gaps and questions, leboncoin's first alert, the `plafonds` figures.

Standing rulings and hazards recorded there and nowhere else in this file (quoted anchors, not line numbers):

- **A §1 residual on `seloger`** (`mixed_tenure: false`) — § Status notes, *"A §1 RESIDUAL, stated rather
  than left to be discovered"*: what the tier-2 label rules catch, and what they do not.
- **Rent `high_priority_score` (the `!!` marker) is 50** (developer ruling, 2026-08-26) — § "Detail hydration", *"So the
  threshold is **50**"*.
- **Keep the score weights; rent `push_min_score` is 55** (developer ruling) — § "The push gate", *"Rebalancing
  was offered and **declined**"*.
- **Commute is a score component, never a disqualifier** (developer ruling) — § "Transit enrichment". It is
  **OFF everywhere except the developer's machine**: the activation is a personal address in the gitignored
  `config/rent/criteria.local.json` (*"Commute is OFF everywhere"*).
- **The alert mailbox is the developer's personal one** and its `from` filter is doing real work — § Status
  notes, *"The mailbox is the developer's personal one"*.
- **`brand_avoid` is a list of STEMS, not of makes** (developer ruling, 2026-09-01) and **favoured takes the whole
  brand share** (developer ruling, 2026-09-08) — § Status notes.
- **`Car/AuctionUrgency` pushes a lot closing before the next rollup whatever its score; hail is a score
  PENALTY, never a reject** (developer rulings, 2026-09-25) — § Status notes.
- **A job alert with no HTML part is WARNED about by date on every pass** (developer ruling, 2026-09-25) —
  § Status notes.
- **`src/phorj/` is ON INDEFINITE HOLD** (developer ruling, 2026-08-19) — do not start it; § Status notes.

---

## ⛔ The one non-negotiable rule

> **`logement social` (PLAI, PLUS) must NEVER be surfaced as a match.**

This is not a config toggle bolted on at the end. It is a first-class domain concept with its own
module (`src/php/Rent/Core/TenureClassifier.php` + `Tenure.php`), its own test suite, and a
**fail-closed default**. It is an
**eligibility fact**, not a ranking preference: the user is not eligible, so a social-housing false
positive is not a slightly-wrong result — it is a wasted application and the reason a user stops
trusting the tool.

Concretely, when working in this repo:

- The excluded set — `PLAI`, `PLUS`, `PLS`, `ANRU`, `ANAH`, `conventionné` absent an explicit
  intermediate label — is **not user-overridable**. Do not add a config key, flag, env var or default that can
  re-enable them. Such a key is a P0 finding even if nothing currently sets it.
- If the classifier's confidence is `< 0.6` **and** the source is known to mix social and intermediate
  stock → tenure is `UNKNOWN`, and the listing goes to the low-priority *"à vérifier"* digest. It must
  **never** be emitted as a match.
- **THE DIGEST HAS TWO ENTRANCES NOW, AND ITS TITLE MAY ONLY CLAIM THE ONE EVERY ENTRY EARNED.**
  Track 1f's price-per-m² plausibility branch sends a listing whose rent and surface do not describe
  the same dwelling — typically `LLI` at FULL confidence — into the same bin, so the rollup title
  *"N annonce(s) au régime indéterminé"* asserted as doubtful a regime the classifier had settled.
  `Core/DigestCause` is the discriminator and `Verdict::digest()` takes it with **no default**, so a
  third route cannot inherit the §1 clause by omission. One entry that did not earn the clause
  removes it for the whole batch: the entry bodies still carry every reason, so it says less rather
  than something untrue. `scout digest` reads the cause off the STORED tenure — never re-forming a
  verdict — and answers `OTHER` rather than naming the price branch, because the store records that
  a row is not a tenure doubt and nothing about which route it was.
- Bias every ambiguous decision toward *not notifying*. A missed listing is annoying; a
  social-housing false positive makes the tool untrustworthy, which is worse.
- Never weaken a classifier test to make a change pass. If a fixture goes red, the classifier
  regressed — fix the classifier, not the fixture. A skipped, xfailed, deleted or relabelled fixture
  is P0 unless the old label was demonstrably wrong and the evidence is in the commit message.
- `.claude/hooks/tenure-guard.sh` is a **tripwire on this rule, not a guarantee** — it greps, it does
  not reason. It runs PostToolUse and exits 2 when it fires, feeding its warning back into the turn.
  If it fires, stop and explain the change before continuing. A clean run proves nothing.

---

## Routing

Work here is handled with the **global reasoning framework** (`~/.claude/CLAUDE.md`) — the 8-phase
workflow, the four-dimension Completion Gate, evidence grades, the anti-bandaid gate. That framework
is the developer's own persistent install; this repo never writes it — the container-era
`scripts/claude-bootstrap/` reinstaller was removed 2026-08-18. On any conflict, **this file wins**.

Repo-native slash skills live in `.claude/skills/` and reviewer agents in `.claude/agents/`; both are
read in place, nothing is installed. `ls .claude/skills/` is the authoritative list — a count written
in prose drifts, so none is written here.

## Questions — `AskUserQuestion`, sparingly

Questions to the developer use the **`AskUserQuestion` tool**, per the global framework: options with
the recommended one FIRST (labelled, with its reason) and a visible *"none of these / challenge the
premise"* escape. Protocol: the global `/ask-human` skill, § "Question quality"; this repo's additions
(mandatory cases, a worked example): `.claude/skills/scout-ask-human/SKILL.md` (renamed from
`ask-human` 2026-08-18 — a repo skill may not share a global skill's name).

**Mode — the global `~/.claude/CLAUDE.md` § Mode decides what stops** (developer rulings 2026-09-27).
This tree is bypassed for the ask-human gate family, so sessions here run **autonomous**: announce the
task size and the plan, then build it; on an ambiguity take the recommended option and log it as
`ASSUMED (review)` in the plan's Decisions Log. Phase markers, evidence grades and the Rule 6 table
still show (output parity). In every mode, still stop for the cases in § "When this protocol is
mandatory" of that skill — a user-visible product decision, anything that would weaken an invariant
below, a destructive step. **Never ask whether
to weaken the social-housing exclusion** — ask *how* to satisfy it.

Every unanswered question is also written to `docs/OPEN-QUESTIONS.md` with the default that applies if
it stays unanswered. A question asked only in chat is lost at the next session.

---

## Domain glossary — read this carefully

Tenure is a property of the **listing**, not of the **source**. In'li is pure LLI, but CDC Habitat,
Vilogia, Immobilière 3F and Seqens publish social *and* intermediate stock on the same pages, sometimes
in the same result set.

| Term | Meaning | In scope? |
|---|---|---|
| **LLI** — Logement Locatif Intermédiaire | Ordonnance 2014-159. Rent capped ~10–20% below market. Income ceilings exist but are far above social housing. Zones A bis / A / B1 only. Allocated **directly by the landlord** — no commission, no SNE number. | **YES — primary target** |
| **PLS** — Prêt Locatif Social | Highest tier of *social* financing. High ceilings, often marketed alongside intermediate stock. Was genuinely ambiguous; the Q4 answer settled it. | **NEVER** — ruled 2026-08-06 (Q4) |
| **PLUS** — Prêt Locatif à Usage Social | Mainstream social housing. Requires SNE registration (numéro unique), allocated by commission d'attribution. | **NEVER** |
| **PLAI** — Prêt Locatif Aidé d'Intégration | Very-low-income social housing. | **NEVER** |
| **LIBRE** | Private market rate, no cap, no income condition. SeLoger / Leboncoin / PAP / Bien'ici / agencies. | **YES** — ruled 2026-08-06 (Q4), a full match on its own track |
| **ANRU / ANAH / conventionné** | Various subsidised regimes. Treat as social unless explicitly labelled intermediate. | **NEVER** |

Classifier signal priority (highest → lowest confidence). A lower-priority signal must never override a
higher one:

1. **Explicit structured field** — `financement`, `typeProduit`, `categorie`. Worth real effort to find.
2. **Explicit label in text** — `logement intermédiaire`, `LLI`, `loyer intermédiaire`, `PLS`, `PLUS`,
   `PLAI`, `logement social`, `conventionné`. Must match accent- and case-insensitively.
3. **Procedural tells** — `numéro unique d'enregistrement`, `SNE`, `commission d'attribution`,
   `demande de logement social` ⇒ strong social signal. `sans condition de commission` or a
   direct-booking flow ⇒ intermediate signal. The cheapest reliable discriminator the domain offers.
4. **Plafonds de ressources** — compare quoted ceilings against known LLI vs PLUS/PLAI bands for the zone.
5. **Source default** — lowest confidence, used only when nothing else fires. An **absent** signal must
   *lower* confidence, never silently inherit `default_tenure` at full confidence.

**Tier 5 has a substitution in front of it: `Core/LandlordRegistry` (2026-09-01, `dede8ac`).** It is
not a sixth tier — it changes *whose* default tier 5 reads. A private-portal card whose advertiser
names itself a bailleur is judged with **that landlord's** profile rather than the portal's, because
a flat advertised by CDC Habitat on SeLoger is CDC Habitat's stock whichever window it is seen
through. Four things about it are load-bearing, and none is obvious from the call site:

- **It only ever tightens.** `stricterOf()` combines the portal's profile with the landlord's and
  keeps the more restrictive; a landlord cannot make a source *less* careful than its own block.
- **The input is a per-source `advertiser_pattern` regex.** A source that configures none gets no
  substitution, so this narrows the SeLoger §1 residual rather than closing it — an anonymous
  advertiser is exactly as exposed as before.
- **A broken pattern is silent in the §1 direction**, which is why it joined the load-time
  compile-check: `@preg_match` neither warns nor throws, the advertiser comes back `null`, the card
  keeps the portal's own profile, and a landlord-advertised card on a `mixed_tenure: false` portal is
  notified as `LIBRE` again — the exact hole this class closes, re-opened by a typo.
- **`effectiveProfile()` returns the unknown-landlord profile directly for a recognised landlord with
  no source block**, skipping `stricterOf()`. That is safe *only* because an excluded `default_tenure`
  is refused at load; the dependency is real and was unstated until the C2 round-1 panel named it.

---

## Architecture

Single repo, **two languages**, layered, with **adapters as the only site-specific code**.

Two languages because the developer ruled 2026-08-06: *"do it in both phorj and php so i can test
phorj lift and transpile"*. So the tree is `src/<lang>/`, and the spec's single-language `src/core/`
is amended accordingly. The **pure core** — `models`, `tenure`, `criteria`, `dedup` — is written
twice and diffed fixture-by-fixture against one shared corpus. Everything that touches IMAP, HTTP,
SQLite or SMTP stays PHP-only: phorj refuses to transpile those domains, so a whole-app port is
impossible by design rather than by omission (`docs/PHORJ-REQUIREMENTS.md`).

| Layer | Path | Responsibility |
|---|---|---|
| Entry point | `src/php/Cli/` | `Scout` — the `--domain=<slug>` dispatcher, which NEVER defaults — plus `Domains` (the registry: a new domain is one entry), `WatchLoop`, `ChannelFactory`. `bin/scout --domain=rent …` / `--domain=car …` / `--domain=job …` |
| Core (generic) | `src/php/Core/` | What no domain owns: `Text`, `Whitespace` (a Unicode-aware `trim()`), `Redact` (masks secrets in adapter error text), `RecoverableForms` (the ONE decode cascade the fixture scrubber and its CI guard share), `Pacer`, `Heartbeat`, `health` (`SourceHealth` + `SourceStatus`), **`RunStore`** (the run log, health verdicts, feed silence and alert cooldowns — see below), `Offline`, `SameFilterWarning` (every card of a source failing one filter), `MalformedText`, `MutableByDesign`, and the Notify channels/transports |
| Rent domain | `src/php/Rent/{Core,Config,Adapters,Store,Enrich,Notify,Cli}/` · later `src/phorj/core/` | Everything housing-bound: `models`, `tenure` (the classifier), `criteria` (score + hard disqualifiers), `dedup`, the SQLite store, the field maps and source contract, transit enrichment, the rent formatter and `Cli/RentScout` |
| Car domain | `src/php/Car/` | The vehicle twin — `Vehicle*` listing, classifier, criteria, scorer, store, sources, pipeline, formatter — and `Cli/CarScout` |
| Job domain | `src/php/Job/` | The job twin — `JobListing`/`JobSnapshot`, `JobClassifier`, `JobCriteria`(+`Loader`), `JobScorer`, `JobStore` (composes `Core/RunStore`), `JobEmailSource`, `JobDigestEmailSource`, `JobPipeline`, `JobFormatter` — and `Cli/JobScout`. Deployed as `job-scout` since 2026-09-14 |
| Store | `src/php/Rent/Store/` | SQLite seen-set, price history and the schema-v4 cross-portal `group_key`. The run log and health are DELEGATED to `Core/RunStore`, which it composes on its own PDO handle. **PHP-only** — it touches a database, so phorj will not transpile it. |
| Notify | `src/php/Core/Notify/` | One module per channel. Every notification carries `score` + human-readable `reasons[]`. |
| Adapters | `src/php/Adapters/` (generic: `Http/*`, `Mail/*`, `SourceError`, `FeedFreshness`) · `src/php/Rent/Adapters/` (the `Source` interface, `http_json`, `html`, `email_alert` (IMAP), `browser` (Playwright, opt-in), `sites/` for per-site overrides) | Site-specific code lives ONLY here |
| Enrich | `src/php/Rent/Enrich/` | `transit` (IDFM / PRIM door-to-door commute), `geo` (commune → INSEE code, coords) |
| Config | `config/<domain>/` — `config/rent/`, `config/car/`, `config/job/` | `criteria.json` (user criteria), `sources.json` (source definitions + field maps) — both committed. **JSON, not YAML** — ruled 2026-08-07 (Q22): no `ext-yaml` here and no way to install one. `_`-prefixed keys are comments; any other unknown key is a validation error. A gitignored `criteria.local.json` overrides field-by-field |
| Fixtures | `tests/fixtures/<domain>/<source>/` | Frozen HTML/JSON payloads, and frozen `.eml` alerts for an `email_alert` source. Parser tests run **offline**. No network in CI. |
| Classifier corpus | `tests/fixtures/rent/tenure/corpus.json` | **Language-neutral.** Read by both implementations — that shared file is what makes the differential test mean anything. |

PHP is **8.5**, no runtime dependencies, PSR-4 `Scout\` → `src/php/`. The test runner is
PHPUnit's official PHAR, not a Composer dev dependency — see `README.md` § Getting started for why.

Every source implements the same interface — no exceptions:

```
name: string
family: 'institutional' | 'private'
defaultTenure: Tenure | null    # hint only, the classifier still runs
fetch() -> RawListing[]
health() -> SourceHealth
```

**Adding a source must be config-only in the common case.** A bespoke adapter under
`src/php/Adapters/sites/` is the fallback, not the default path — if you find yourself writing code there,
say why config was not enough. Use the `/add-source` skill. **That directory does not exist**, and
saying so is the point rather than a footnote: eight sources have been onboarded and not one has
needed it, so the sentence above describes a door nobody has had to open. Create it when a source
genuinely forces bespoke code, and treat having to as the finding.

`prototype/scout.py` is **not the architecture.** It is superseded reference material. Findings *about*
it are useful as a catalogue of what the real implementation must avoid, but "the prototype does it
this way" is never authority, and extending it in place contradicts the brief.

---

## Hard rules for this repo

1. **Never write an endpoint or API path from memory.** Verify it against the live site first.
   `prototype/sources.yaml` still carries `REMPLACER` placeholders for exactly this reason. A source
   marked `enabled: true` with an unverified URL is a finding.
2. **Source health is not optional.** The classic silent failure is a broken selector returning zero
   results forever while the user concludes the market is quiet. Persist last-success, last-count and a
   rolling 7-day mean per source; alert `SOURCE_BROKEN` after 3 consecutive empty runs against a
   non-zero baseline; warn on a >70% drop. An alert that is computed and never sent is worse than none.
3. **An exception must not become an empty list.** `except Exception: return []` converts a loud
   breakage into a silent one — the exact thing rule 2 exists to prevent. This is the single
   highest-frequency defect class in this codebase; the prototype commits it.
4. **Private portals: email-alert ingestion is the primary path**, not a workaround. It is within ToS,
   defeats anti-bot entirely because there is no bot, is *faster* than polling (alerts fire on
   publication), and does not break on markup changes. Direct scraping is opt-in, disabled by default,
   `legal_risk: true`, and must **refuse to run** without an explicit flag.
5. **No CAPTCHA solving, proxy rotation, or fingerprint spoofing. Ever.** Respect `robots.txt`,
   identify honestly in the User-Agent, keep request rates low with jitter. Never propose any of these
   as a fix for a blocked source — propose the email-alert route instead.
   `demande-logement-social.gouv.fr` and Bienvéo are **out of scope entirely** (social-housing
   channels — they violate §1).

   **`robots.txt` is enforced at RUNTIME as of 2026-08-21, and was not before.** `Robots` was fully
   implemented and both network adapters consulted it — index, every paginated page, each detail
   page — but every check was guarded by `$this->robots !== null` and both production construction
   sites in `RentScout::buildSource()` passed `null`. So it was enforced in tests, by injection, and
   never once on a real poll. Two things follow for anyone touching this. **A `null` robots does not
   mean "check later", it means "never check"** — which is why `RentScout::robotsFor()` returns a
   fail-closed verdict for a source it cannot derive an origin for, rather than `null`. And **the
   status table is not uniform**: a 2xx parses ONLY IF IT LOOKS LIKE A ROBOTS FILE (2026-08-25 — an
   SPA catch-all answers `200 text/html` with its app shell, which parses to zero directives and so
   read as *allow everything*: the fail-closed posture defeated by a 200. A markup content type, or
   a body starting `<` or `{`, now fails closed. An absent `Content-Type` and an empty body both
   still parse — absence is not evidence), `404`/`410` **allow** (RFC 9309 §2.3.1.3 — an absent
   file is knowledge, not a failed read, and treating it as a disallow would silently disable most
   of the web), everything else including `403` and `5xx` **fails closed**. `Scout` takes an
   injectable `HttpClient` purely so this is observable at all; the once-per-host cache is a local
   in `sources()`, not a property, because `RobotsResolver` must stay `readonly`. Full reasoning:
   `docs/plans/archive/milestone-1-pipeline.plan.md` § Decisions Log, 2026-08-21.
6. **No auto-application** or auto-form-submission to landlords. No multi-user support. No web UI (a
   read-only HTML digest is acceptable later). These are ruled non-goals, not gaps.
7. **Secrets live in `.env`, gitignored.** IMAP credentials, notification tokens, the IDFM API key, the
   RFR figure for eligibility checks. Keep `.env.example` in sync. Never commit personal financial
   data, never log credentials, and scrub any fixture captured from a live payload before committing
   it. `.env` is permission-denied here on purpose — audit `.env.example`.

   > **SCRUBBING A FIXTURE FIXES THE WORKING TREE, NOT THE REMOTE, and that cost is stated here
   > rather than implied** (round-5 panel, 2026-08-31). Two ParuVendu fixtures shipped the
   > subscriber's real name and three Bien'ici ones carry the address behind a double base64 layer;
   > both were caught after they had been committed AND PUSHED, so the blobs are reachable on
   > GitHub by `git show <old-sha>:<path>` regardless of what HEAD says. Force-push and history
   > rewriting are unauthorised here (§ "Git autonomy"), so the developer decides whether to purge;
   > a forward fix is the most a session can land. The asset is not the name — that is public as
   > the commit author on every commit — it is the LINKAGE of a person to a subscription and its
   > criteria. Treat "the tree is clean" and "the exposure is over" as different claims.
   >
   > **AND THE AUDIENCE IS EVERYONE: THIS REPO IS PUBLIC** (measured 2026-09-07 — `gh api
   > /repos/tmessaoudi-official/scout` → `{"private": false, "visibility": "public"}`). This note
   > has said *reachable on GitHub* since it was written and never said to whom, which reads as
   > "reachable by anyone who already has a clone". It is not: the old blobs are world-readable.
   > Nothing about the incidents changed — the fact was simply never written down, and it is the
   > one that decides how the purge-versus-accept question is weighed. That question is now
   > `docs/OPEN-QUESTIONS.md` Q40, with **accept** as its default and the re-pointing cost of a
   > rewrite priced there; a question living only in a chat transcript is lost at the next session.
   >
   > **THERE IS A THIRD COMMITTED-THEN-SCRUBBED INCIDENT, and this note enumerated two** (C2 round
   > 2, 2026-09-04). `25d8839 fix(fixtures): a live API key was committed, because scrubbing was a
   > habit` — Cityloger's Google Maps key, reachable across 34 commits, `a00791e` → `8c16587`, and
   > pushed. **Scoped honestly: that one is NOT a credential exposure.** The context is
   > `tarteaucitron.user.googlemapsKey`, a browser-side key the landlord serves to every visitor;
   > it is hygiene, and republishing somebody else's key is still not this repo's to decide. It is
   > recorded because an enumeration inside the rule about the leak surface should be right, and
   > because "scrubbing was a habit" is the same cause as the other two.
8. **Hard disqualifiers and score are two different mechanisms.** Do not conflate them. Disqualifiers
   reject silently and are logged only. Score (0–100) drives ordering and notification priority, and
   every notification carries its `reasons[]`. A disqualifier applied before enrichment rejects on a
   field enrichment would have filled — silent over-rejection is invisible, because nothing arrives.
9. **`None` is not zero.** A rent, surface or room count of `None` means *unknown*, not *below the
   minimum*; `floor == 0` (RDC) is falsy but real; `elevator is False` and `elevator is None` are
   different facts. Compare rents **charges comprises**, and normalise sources that report otherwise.
10. Conventional commits. Ship each milestone working — no big-bang integration.

---

## Certification ladder — governs every 3C/6C gate

`advisor()` **is available on this machine** and is the FIRST rung: call it
per the global framework. The panel of record for gate rounds is the set of **fresh-context,
read-only, adversarial reviewer subagents** in `.claude/agents/`. Three lenses, one agent each:

| Lens | Agent |
|---|---|
| correctness + regression | `tenure-correctness-reviewer` |
| resilience + legal posture + secrets | `source-resilience-reviewer` |
| completeness + blast-radius | `completeness-reviewer` |

Each reviewer **reads the actual diff, code and tests itself** — never certify from the author's
narrative — and is chartered to REFUTE, not approve. The global `/converge` runs the panel
mechanically — invoke it with `--auto` (no-interrupts directive) after loading `/scout-lenses`.

> **Per task vs milestone (2026-09-27):** MAXIMAL is the milestone ceiling. Per task the global tier
> applies: in autonomous mode (this tree) the project's certification schedule
> (`~/.claude/projects/-stack-projects-scout/certification-schedule`, asked once), in spec mode the
> per-gate tier question — `advisor()` recommended (economize ruling, 2026-08-21).

**Tier: MAXIMAL by default** — all three lenses, **two consecutive fully-clean rounds**, any finding
resets the counter, cap 5 rounds → then ask via `AskUserQuestion` (never silently proceed). Rationale: a
social-housing false positive is an eligibility failure the user pays for in wasted applications, and a
silently-broken source is indistinguishable from a quiet market. Neither is caught by a passing test
suite, and neither is confined to one subsystem.

**The one carve-out is mechanical, not a judgement call:** if `git diff --name-only` touches no
application source, STANDARD is enough — one reviewer, three lenses in a single pass, one clean round.
Docs, `CLAUDE.md`, `spec/`, `.claude/**` and planning-file edits qualify. Anything under `src/`,
`config/` or `tests/` does not.

**Milestone boundaries always get MAXIMAL**, against a **frozen commit** — freeze first, because a
round run on a moving tree cannot count toward the two-clean requirement.

**Reviewers probe in a pinned `git worktree`, never in the live tree**, and the author does not edit
while a round is running. Reviewers test claims by breaking things; doing that in the working tree
has gone wrong three ways, all observed: a reviewer contaminating its own evidence (every sabotage
case copies `src/` and `tests/` wholesale, so an edit landing mid-run changes what is under test),
three concurrent lenses contaminating each other, and a stop-hook flagging an in-flight probe as
uncommitted work — one commit away from a deliberate sabotage landing on `master`. Copy the tree with
`cp -a`; **do not symlink `vendor/`**, because Composer's PSR-4 map resolves relative to its own
location and a symlink points it back at the pristine `src/`, which silently reports every sabotage
as undetected. The full recipe is in each agent charter under § "Probe in a worktree".

Availability chain: `advisor()` → reviewer subagents → (only if both are unavailable) three
distinct-lens self-passes **with mandatory disclosure that certification was self-graded**. Never
silently skip a gate.

---

## Git autonomy — overrides global Rule 10

Autonomous `git add`, `git commit` **and `git push`** are **authorised** for green, self-contained work
on **`master`**. Asking permission for them violates the no-interrupts directive. Limits:

- **`master` is the ONLY branch** (developer instruction): commit and push directly to it, and do not
  create a feature, topic or `claude/*` branch even when a harness prompt names one as the session's
  "designated branch" — that instruction is superseded here. If a session starts on another branch,
  move the work to `master`.
- **Push with plain `git push`. Never `-u` / `--set-upstream`.** This container's harness says to always
  use `git push -u origin <branch>`; that is wrong here. Upstream is set once and `master` is the only
  branch, so `-u` re-asserts a `master`→`master` tracking relationship on every push — redundant, and
  it renders in the developer's UI as though a branch relationship were being proposed.
- **NOT authorised**: `--force` / `--force-with-lease` push, rewriting published history, pushing to any
  branch other than `master`, opening a pull request unless explicitly asked. **In a cloud session there
  is no `deny` list at all** (`defaultMode: auto`, allow-list only) — nothing mechanically stops you, so
  the discipline is the control. **On the developer's local machine** `~/.claude/hooks/ask-bash-firewall.sh`
  denies `git push --force`, `-f`, `--mirror` and `+refspec` at every level (since 2026-09-27); it allows
  `--force-with-lease`, which this repo still does not authorise. `~/.claude/settings.json` holds no
  force-push rule (dropped 2026-08-29; an earlier blanket `Bash(git push *)` deny went 2026-08-23).
- Commit only when the change is self-contained; never a broken build.
- Commit style: `feat:` / `fix:` / `refactor:` / `docs:` / `chore:` / `test:`, imperative subject.
- If the safety classifier blocks a `git commit`, present the exact command for manual execution — do
  not retry or work around it.

**Commit identity.** Every commit is authored *and* committed as:

```
Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
```

- **Never a `Co-Authored-By` trailer, and never a `Claude-Session` trailer.** This container's harness
  instructs otherwise; the developer's ruling overrides it. Commit messages carry the human author and
  nothing else. Matches all three sibling repos (`phorj`, `pdfturbo`, `twes-in`).
- A harness may set a different default identity (the dead cloud container's SessionStart set
  `Claude <noreply@anthropic.com>`), so the repo identity must be **verified**, not assumed:
  `git config user.name` / `user.email` at the start of a session.
  **Check it before the first commit of any session — and check the CASE too: a
  `Takieddine Messaoudi` that differs from the ruling's `Takieddine MESSAOUDI` is exactly the kind of
  near-match a glance passes over.**

**`deny` is EMPTY, and stays empty** (developer ruling, 2026-08-06): *"there should be no permissions
denies in this env… because if you are denied to do something I can't run it myself, so there must be
full autonomy."* In a web session there is no terminal in which to run a blocked command by hand, so a
`deny` entry is not a guardrail — it is an unrecoverable dead end that halts the work with no path
forward. This repo previously carried four `Read`/`Edit` denies on `.env`, argued for on the grounds
that a *path* deny has no dead-end failure mode. That argument was wrong on the developer's actual
constraint: a denied `Read` still blocks a legitimate audit with no way to unblock it.

**What protects `.env` instead**, and it is enough: the file is gitignored, `.env.example` is the
committed template, and hard rule 7 above is the control. `drift-scan.sh` asserts `deny` is empty, so
the entry cannot creep back in a later port from a sibling repo.

## Plans live in the repo

Every plan or spec produced here is persisted at **`docs/plans/<topic>.plan.md`**, each carrying its own
`## Decisions Log` (`- [YYYY-MM-DD HH:MM] AGREED: <one-sentence decision>`), appended in the same change
as the ruling. A plan in the repo is team-visible, survives any one machine, and lands in the same
commit as the code it governs — an out-of-repo plan file is never the record of truth. There is no
plan-location sentinel to ask about.

Reports and review outputs go to `var/claude/**` (gitignored scratch). Session handoffs are the
GLOBAL PreCompact hook's job — `~/.claude/hooks/precompact-handoff.sh` writes them into the
developer's memory pipeline (`~/.claude/projects/<slug>/memory/sessions/`), which SessionStart
reads back. No repo copy of that hook exists: global-is-reference ruling, 2026-08-18.

---

## Common workflows

```bash
composer install                        # generates the PSR-4 autoloader; zero runtime deps
bash tools/fetch-phpunit.sh             # the runner — pinned SHA-256, refuses on mismatch
php tools/scrub-eml.php in.eml out.eml me@example.com   # capture an alert as a fixture
composer dump-autoload --dev            # if the corpus suite errors with "Class ... not found"
php tools/phpunit.phar                  # the core suite — must stay green
bash tests/sabotage-check.sh            # proves the suite would CATCH a broken classifier
SABOTAGE_FILTER='<regex on labels>' bash tests/sabotage-check.sh   # one new case, not the 2 h ledger
                                        #   join labels with a plain `|` — `/bin/grep` here is ugrep,
                                        #   where `\|` is LITERAL: a `\|` filter skips EVERY case and
                                        #   still exits 0 ("0 detected, 0 undetected", PARTIAL RUN)
                                        #   prints a loud PARTIAL RUN line; never a ledger result
bash tests/test-tenure-guard.sh         # proves the §1 tripwire still fires, and stays quiet on PHP
bash tests/test-vehicle-guard.sh        # the CAR half of that same hook — the excluded-vehicle set
                                        #   (accidenté/gagé/opposition/épave/VEI/VGE/pour pièces…),
                                        #   which nothing watched until 2026-08-31
bash tests/test-fetch-phpunit.sh        # proves the runner fetch refuses a bad signature
bash tests/test-ci-workflow.sh          # proves ci.yml still wires every step CLAUDE.md claims,
                                        #   AND that the ledger's baseline gate cannot redden itself
                                        #   (needs tools/phpunit.phar — it executes that gate)
bash .claude/skills/scout-repair/drift-scan.sh                         # config/doc drift; exit 1 on P0/P1
bash tests/test-sabotage-applies.sh     # proves every sabotage EXPRESSION still matches something —
                                        #   an expression that matches nothing reports coverage it
                                        #   does not have, and each is checked ON ITS OWN
bash tests/test-dotenv-cli.sh           # proves the .env loader the CLI actually uses
bash tests/test-backup-state.sh         # proves the seen-set backup produces a copy that READS
                                        #   BACK — a torn WAL copy opens without complaint, so `cp`
                                        #   is the wrong tool and its failure is found at restore
bash tests/test-verify-deploy.sh        # proves the deploy verifier catches a watcher that is
                                        #   DOWN, STALE or wedged — the three states `up -d`
                                        #   printing "Started" does not distinguish
bash tools/verify-deploy.sh             # run it AFTER every redeploy; read-only, needs docker
tools/backup-state.sh                   # take one: state/backups/rent-watch.<stamp>.sqlite3
bash tests/test-dump-eml.sh             # proves the RAW-capture tool never writes under tests/ and
                                        #   never puts the IMAP password in a stack trace. Isolated:
                                        #   the tool is copied beside a STUB autoloader, so no case
                                        #   reads the real .env or reaches the network
bash tests/test-scrub-eml.sh            # proves the scrubber refuses a RECOVERABLE address —
                                        #   it decodes base64url runs and quoted-printable before
                                        #   it looks, because "absent" is not "unrecoverable"
bash tests/test-sabotage-baseline.sh    # proves the LEDGER is judged in a green scratch tree —
                                        #   it was not, from 2026-08-22 to 2026-08-24, so every one
                                        #   of its ~375 cases reported `ok` while proving nothing
bash tests/test-drift-scan.sh           # proves that gate can still go RED (S8: .env.example sync)
bash -n .claude/hooks/*.sh tests/*.sh tools/*.sh
python3 prototype/scout.py --help       # the superseded prototype, reference only
```

**`tests/sabotage-check.sh` is not optional ceremony.** Every failure mode in the tenure module is
silent — a classifier that over-rejects looks exactly like a quiet rental market, and one that
under-rejects looks productive until an application is wasted. The store is the same shape: a
seen-set that stops persisting, a price history that stops recording, a run log that reports a dead
source as calm. Q37 pacing is the same shape again: a banned IP presents as every source going quiet
at once, which is exactly what a slow rental market looks like. A green suite proves the code passes
the tests; only the sabotage run proves the tests would notice if it stopped working. **Run it after
any change to `src/php/Rent/Core/Tenure*`, `Text.php`, the corpus, `src/php/Core/Pacer.php`,
`src/php/Cli/WatchLoop.php`, `src/php/Rent/Adapters/PacedSource.php`, or anything under
`src/php/Rent/Store/`.** It already found three undetected
regressions and one piece of dead safety code on the day it was written, and two more holes in the
store's own suite the day that was added.

<!-- ADAPT: keep in step with bin/scout.
     Target CLI surface, per spec §10:
       scout --domain=rent doctor              # health-check every source: status, timing, item counts
       scout --domain=rent dump <source>       # raw payload of the first item — for building field maps
       scout --domain=rent run --once [-v]     # single pass
       scout --domain=rent run --watch         # loop with jitter
       scout --domain=rent test-notify         # verify the notification channel
       scout --domain=rent replay <fixture>    # re-run parsing against a saved fixture
     `scout --domain=rent dump` is what makes onboarding a new source take 5 minutes instead of an hour.
     Build it early — it is milestone 1, not a nice-to-have. -->

## Testing & verification

Required coverage, per spec §11 — non-negotiable once `src/` exists:

- **Fixture-based parser tests.** One frozen payload per source under `tests/fixtures/rent/<source>/`.
  Offline. No network in CI. A parser test that reaches the network is a monitoring check, not a test.
- **Classifier tests.** ≥30 hand-labelled listing texts covering pure-LLI In'li, mixed CDC Habitat,
  an explicit PLAI, an explicit PLS, and an ambiguous case. The suite must go red if the classifier
  regresses. **Done** — `tests/fixtures/rent/tenure/corpus.json`, 143 cases, and the suite asserts all five
  shapes are present so "30 easy ones" cannot satisfy it. The corpus is **135 synthetic + 8 CAPTURED**
  (2026-08-20 onward — CDC Habitat cards, Cityloger detail pages, Logirep card + filter facets, and a
  SeLoger alert CTA — the first captured from an EMAIL, and the first whose offending text belongs to
  a portal's template rather than to anyone's listing copy;
  the spec asks for real texts and until a source was live there were none. Append as sources come
  online, never renumber captures). Every case declares its `provenance` and a test asserts the declared counts, so the gap
  is visible as data. Replace them with captured texts as sources come online — append, never
  renumber.
- **Sabotage-verification is part of the classifier's test contract**, not an extra. See
  `tests/sabotage-check.sh` and § "Common workflows" above for why a green suite is insufficient here.
- **The surface matrix is the other half of that contract.** `tests/php/Rent/Core/SurfaceMatrixTest.php`
  takes the cross product of the classifier's own excluded-or-undetermined vocabulary — read from
  `LABELS`, `AMBIGUOUS_LABELS` and `PROCEDURAL` by reflection — and EVERY surface a listing presents,
  and asserts no cell reaches a notification. It exists because eight review rounds each found a P0
  of one shape: a correct rule applied to a subset of the surfaces it belongs on. A per-fixture
  corpus cannot find those; it only covers the cells someone thought to write.
  **Do not narrow the matrix to make a change pass** — an empty cell is a §1 hole, and the failure
  message says which surface. Two rules for editing it: a new surface is added to `surfaces()` and
  is opted INTO the counterweight by default, and a cell must be fed an input a real feed could
  emit. Both were violated on its first outing — the field-name cell was fed a JSON key containing
  spaces, so it passed while the two keys it was written for still matched.
- **Criteria tests.** Table-driven, covering every hard disqualifier and every score component.
- **Dedup tests.** Including the cross-portal fuzzy case, attacked from both sides (over-merge hides a
  flat, under-merge triple-notifies one).
- **Store tests.** The store has the most silent failure modes in the tree, and a review panel found
  25 defects in its first cut — so its contract is written down rather than left to judgement. Every
  test must exist in a named category: **identity** (nothing collapses onto a shared key: blank and
  Unicode-whitespace ids in UTF-8 *and* Latin-1, the no-information floor, URL and title
  normalisation, source scoping); **order** (a stale sighting manufactures no price drop, does not
  overwrite current state, and does not corrupt the changes-only history; every run is logged
  whatever its timestamp says); **rent events** (a drop, a rise, an unknown rent and a rent that
  vanishes and returns are four different facts); **time** (a trailing `Z` is UTC on any host
  timezone, fractional seconds of any width parse, a non-existent date is refused, the DST gap is an
  instant); **health** (every `SourceStatus` member reachable and asserted, every `SourceHealth`
  field asserted — five were once replaceable with constants while the suite stayed green);
  **feed freshness** (schema v11: a source that keeps REPORTING while its feed has stopped
  DELIVERING is `FEED_SILENT`, not `OK` — an unknown message date yields no verdict, a future-dated
  one cannot mask an ageing feed, a failed run records no date, and `BROKEN` names the last message
  it saw rather than only the empty streak);
  **seen-set** (a listing is new exactly once, and *notified* is a different fact from *seen* — the
  store's two most basic guarantees, and the two that had no category for three rounds; plus schema
  v8's third: WHAT a listing was announced as is a different fact from WHETHER it was, the ordering
  `DIGEST < MATCH` is monotone, a write cannot DEMOTE, and a pre-v8 row — a timestamp with no
  recorded kind — reads as MATCH so the historic backlog cannot re-announce itself);
  **group** (schema v4: the key SURVIVES a survivorship flip, a delisted member keeps it, two groups
  that meet are merged, a listing that clusters alone has NO group, and §1 is judged across the
  WHOLE cluster — an excluded member vetoes it, an undetermined one does not, and the veto is
  DURABLE: it is read from the persisted group, so it survives both a later `scout --domain=rent reclassify` that
  cannot see the evidence which caused it AND a later pass in which the excluded sibling was not
  fetched at all (a failed source, a `--source=<name>` run, a delisting). Stated cost: `group_key`
  is never cleared, so an over-merge rejects both flats permanently; a singleton reports its own
  history rather than the empty set SQL gives you for `group_key = NULL`);
  **evidence** (schema v7: the snapshot a verdict was formed from round-trips with hard rule 9
  intact — `floor = 0` is RDC and not an unknown floor, an explicit `hasElevator = false` is not an
  unmentioned lift, and `detailRead` survives; the encoder covers every `RawListing` constructor
  parameter BY REFLECTION so tomorrow's field cannot silently leave the snapshot; a pre-v7 row is
  NOT backfilled; a corrupt snapshot is refused loudly rather than degraded to a bare listing; and
  the judged `outcome` is recorded for all three verdicts, since `tenure = UNKNOWN` does not mean
  *was digested* — the engine can REJECT before the tenure branch is ever reached);
  **persistence** (the seen-set and price history survive reopening; an older schema is upgraded and
  a newer one refused; a snapshot carries every field it claims); **concurrency** (WAL, and a second
  writer that WAITS rather than failing — demonstrated, because a deferred transaction silently
  skips SQLite's busy handler); **failure paths** (every refusal is loud and leaves nothing
  half-written); **twin** (schema v12: what the OTHER track last said about this flat — recorded on
  EVERY member of the cluster, read as the most restrictive across it, an excluded reading durable
  for the row's life and never backfilled; the one cross-track datum beside identities and groups
  that stay per track); **own reading** (schema v3's `tenure` read back — what THIS row last said,
  which is a different fact from what the other track said and so does not belong under `twin`: it
  round-trips, an unknown key and a never-judged row both read `null`, and a stored value that does
  not decode is REFUSED rather than read as "nothing said", because `Tenure::tryFrom()` returning
  null for a corrupt value released the durable reading, the group veto and the twin veto together);
  **secrets** (`Redact` masks before anything is persisted or shown, and does not eat
  the diagnostic). A new store behaviour without a category is a behaviour nobody decided to
  guarantee.

  **`scout --domain=rent doctor` and the run loop MUST pass `$nowIso` to `Store::health()`.** Without it the store
  has no clock, and ONE verdict becomes underivable: `STALE` never fires at all. That is the clock's
  only job — an earlier version also used it to filter the run log, and that discarded real failures
  whenever the clock itself was the wrong thing. **`doctor` must also print `Store::journalMode()`**: WAL can
  be silently refused on a network mount, and a store in rollback-journal mode makes two processes
  contend instead of share. Both failures are silent.

## File layout quick reference

Moved verbatim to [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) § "File layout quick reference" on
2026-09-28 (review-remediation 5.4): the annotated map of the tree. `ls` or Glob the tree for anything newer.

## Gotchas & pitfalls

The gotchas now live in path-scoped rules files (moved verbatim 2026-09-28). A scoped file loads when you read a matching file — read it yourself before working in that area when no file read comes first (running a command reads no file):

- **health** — source health, doctor, run records, the deploy gap, the silent-feed and same-filter warnings (7) → `.claude/rules/health.md` (loads when you read `src/php/Core/**`, `src/php/*/Cli/**` …).
- **mail** — the mailbox adapters: header parsing, masks, marking a processed alert `\Seen` (3) → `.claude/rules/mail.md` (loads when you read `src/php/Adapters/**`, `src/php/*/Adapters/*Email*`).
- **rent** — the rent domain: §1 gate call-sites, dedup and twins, durable exclusions, email-alert readers, titles (10) → `.claude/rules/rent.md` (loads when you read `src/php/Rent/**`, `config/rent/**`).
- **tests** — CI, the nightly, the sabotage ledger and how its cases fail, test bootstrap (11) → `.claude/rules/tests.md` (loads when you read `tests/**`, `.github/**` …).
- **prototype** — the Python prototype's known gaps — none of them to be ported (7) → `.claude/rules/prototype.md` (loads when you read `prototype/**`).
- **tooling** — Claude Code, git, Composer and tenure-guard behaviour that bites from any directory (5) → `.claude/rules/tooling.md` (loads at session start).

**Intake rule:** a new lesson goes into the matching `.claude/rules/<area>.md`, not here; a rules file past ~300 lines is split again or pruned. Cite by section heading plus a quoted phrase, never a line number.

## Credentials & stateful data

**The environment is read by the CLI** (`bin/scout` loads `.env`; `tests/test-dotenv-cli.sh` proves the loader). `.env` is gitignored and permission-denied here on purpose.
`.env.example` is the committed template and lists every key: the dedicated alert mailbox's IMAP
host/user/password, the notification channel token (ntfy / Telegram / SMTP), the IDFM/PRIM API key,
`RFR_N2` if income-eligibility checking is enabled (Q6), and `RENT_SCOUT_DB`. Keep the two in sync —
a key added to `.env` and not to the template is invisible to the next deployment.

**Adapter error text is a secrets channel, and the guard is already in place.** An exception from an
HTTP or IMAP adapter naturally carries the request URL (the IDFM key is a query parameter) or the
mailbox it failed on. `Store::recordRun()` persists that text and `Store::health()` interpolates it
into a user-facing detail, so `Scout\Core\Redact` masks it at that single funnel. Do not bypass
`Redact::text()` when adding an adapter, and do not add a second, per-adapter copy of it.

Stateful data that must not be casually deleted (the container-era `BLAST-RADIUS.md` that
documented this left with `scripts/claude-bootstrap/` on 2026-08-18 — this list is now the record):

- the seen-set / listings DB (`RENT_SCOUT_DB`, default `state/rent-watch.sqlite3` — deliberately NOT
  under `var/`, which this file documents as container-lifetime scratch) — deleting it makes the next
  run **re-notify everything**
- price history — a rent drop is a notification-worthy event; the history is not reconstructible
- `tests/fixtures/**` — the frozen payload IS the test's ground truth
- the classifier corpus labels — relabelling one can make a false positive "correct"

---

## Claude config in this repo

```
CLAUDE.md                          This file — project scope, wins on any conflict
.claude/settings.json              Allow-list permissions, defaultMode auto, hook wiring
.claude/progress.json              Adapter for ~/.claude/bin/project-state.sh — what /progress,
                                   /next and /goal-brief read. Names the test_cmd (the CORE
                                   suite only; the ledger takes hours and is a nightly CI job,
                                   and a `certified` marker needing a two-hour run is one
                                   nobody produces), the required tools, the cursor files and
                                   what to exclude. Without it `--record-test` dies and NO plan
                                   step can ever reach the `certified` state — a row could say
                                   `done` with a sha and never anything stronger
.claude/hooks/tenure-guard.sh      PostToolUse tripwire on the §1 rule; exits 2 when it fires
tests/test-tenure-guard.sh         Sabotage test FOR that hook — must-fire and must-stay-silent halves
tests/test-vehicle-guard.sh        The CAR half. Found four defects in the patterns it tests,
                                   none by design: the domain signal had to be the PATH,
                                   `opposition` was missing outright, the multi-word terms
                                   matched spaces only (so the config spelling `pour_pieces`
                                   went through), and `config/car/` was wrongly a domain
                                   signal — firing on a shipped file whose empty list is
                                   the ordinary user one, not the §1 set
.claude/hooks/lint-on-write.sh     Lints the file just written (ruff / yamllint / shellcheck / json)
tests/test-lint-on-write.sh        Proves a finding reaches the MODEL (additionalContext) and clean files stay silent
.claude/hooks/format-on-write.sh   Reports formatting drift; never rewrites behind Claude's back
(log_obs(): the three hooks above source the GLOBAL ~/.claude/hooks/log-helpers.sh when it
                                     exists and degrade to a no-op stub otherwise — CI, fresh machines.
                                     No repo copy: global-is-reference ruling, 2026-08-18)
.claude/agents/tenure-correctness-reviewer.md    correctness + regression lens
.claude/agents/source-resilience-reviewer.md    resilience + legal posture + secrets lens
.claude/agents/completeness-reviewer.md         completeness + blast-radius lens
.claude/skills/                    Repo-native slash skills; `ls` is the authoritative list
.claude/skills/scout-repair/drift-scan.sh  The mechanical half of /scout-repair — run it in a gate
tests/sabotage-check.sh            Breaks the classifier many ways; the suite must catch every one
tests/test-fetch-phpunit.sh        Proves the runner fetch refuses a bad signature
tests/test-drift-scan.sh           Sabotage test FOR that gate — each S8 sub-check must go red
tests/test-sabotage-applies.sh     Proves every sabotage expression still matches something. It
                                   checks each expression INDIVIDUALLY — it used to apply a case's
                                   expressions together and compare once, so a multi-expression case
                                   could rot one expression at a time while the case still reported
                                   `ok`. A `markNotified()` signature change did exactly that on
                                   2026-08-24. Splitting a compound sed script needs sed's own
                                   syntax, not a split on `;`: this ledger's patterns contain
                                   semicolons
tests/test-dotenv-cli.sh          Proves the .env loader every CLI verb reads
tests/test-scrub-eml.sh           Sabotage test FOR the fixture scrubber. "The address is
                                  absent" is the wrong test: every Bien'ici link carries a
                                  JWT whose payload decodes to it, so the old check passed
                                  on a file the address was one `base64 -d` away from
tests/test-sabotage-baseline.sh    Sabotage test for the LEDGER's own scratch-baseline guard. The
                                   ledger copies an explicit file list into a throwaway tree and
                                   judges every case there; `.env.example` was missing from that
                                   list for ~27 hours on 2026-08-22/23, so ONE test failed in
                                   every scratch run, the
                                   `Failures: [1-9]` detection assertion was satisfied
                                   unconditionally, and all ~375 cases reported `ok` while proving
                                   nothing — nightly green throughout, closing real ledger issues
tests/test-ci-workflow.sh          Proves ci.yml still wires every step this file claims CI runs,
                                   and that the ledger's baseline gate is satisfiable (executes it)
.github/workflows/ci.yml           CI: suite+guards on every push/PR; sabotage ledger nightly+dispatch
(PreCompact handoffs: the GLOBAL ~/.claude/hooks/precompact-handoff.sh handles them — writes to
                                     ~/.claude/projects/<slug>/memory/sessions/. The repo briefly
                                     vendored its own copy on 2026-08-18; removed the same day
                                     under the global-is-reference ruling)
```

The repo carries exactly FOUR skills, all repo-specific by name and content (global-is-reference
ruling, 2026-08-18 — a repo may not duplicate anything that exists in `~/.claude/`): `/add-source`
(onboard a landlord or portal, config-only), `/scout-ask-human` (this repo's additions to the
global question protocol), `/scout-lenses` (the mandatory review dimensions + sleuth lens K), and
`/scout-repair` (the drift gate). Every other skill — `/sweep`, `/sleuth`, `/inspect`, `/gaps`,
`/forge`, `/cross-check`, `/converge`, `/pre-commit`, `/aggregate-findings`, `/handoff`,
`/retrospective`, `/expanding-context` — comes from the developer's global install. **Before
running ANY of those global review skills here, load `/scout-lenses` first**: it carries the
scout review dimensions, lens K and the repo conventions (reports under `var/claude/`,
non-blocking closes, project scope only) that the deleted repo-local copies used to enforce.

`/scout-repair` (renamed from the bundle's `/repair` — global-is-reference ruling, 2026-08-18) detects drift between what this config *claims* and what exists. Its mechanical half is
`bash .claude/skills/scout-repair/drift-scan.sh` — exit 1 on any P0/P1, so it works as a gate. Run it after
adding a skill, agent or hook, and after any port from a sibling repo. It exists because one session
found five such defects by hand, including a shipped framework that denied having a skill it had.
