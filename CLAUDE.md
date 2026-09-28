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

Status: **milestone 1 is functionally complete against a frozen payload.** The pure core, the store
(schema v12 as of 2026-08-30), the config layer, the adapter contract, the criteria engine, dedup, the notification
layer and the `scout` CLI all exist. What is missing is a NETWORK adapter, and that is blocked on an
input rather than a decision. As of 2026-08-07 there is a PHP 8.5
implementation of `models` + `tenure` under `src/php/Core/`, a 130-case language-neutral classifier
corpus at `tests/fixtures/rent/tenure/corpus.json`, the seen-set / price-history / run-log store under
`src/php/Rent/Store/` with `SourceHealth` + `SourceStatus` in `Core/`, a strict JSON config layer under
`src/php/Config/` with both files committed, the `Source` contract plus `Payload` / `ListingMapper` /
`FixtureSource` under `src/php/Adapters/`, the criteria engine (`CriteriaEngine` + `Verdict`), and a
PHPUnit suite. `scout --domain=rent run --once` is demonstrable end to end today against a frozen payload.

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
  regresses. **Done** — `tests/fixtures/rent/tenure/corpus.json`, 130 cases, and the suite asserts all five
  shapes are present so "30 easy ones" cannot satisfy it. The corpus is **122 synthetic + 8 CAPTURED**
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
- **STRICT IS NOT THE SAME AS NARROW, and for a month the `Date` parser was both (2026-09-05).**
  The round-trip below is right and stays; what was wrong is the MASK SET it round-tripped against.
  RFC 5322 writes the day as `1*2DIGIT` and makes the seconds optional, the four masks were all `d`
  with `H:i:s`, and PAP sends `Sat, 5 Sep 2026 09:19:13 +0200` — `createFromFormat` accepts `5` and
  re-formats it `05`, so the round-trip refused a legal header. **Five days a month, on any portal
  that does not zero-pad, and nothing anywhere said so.** Measured: every PAP row first seen 1–5
  September carried `observedAt = NULL` (36 of 86) while every row to 31 August carried one;
  `source_runs.feed_newest_at` for `pap` froze at `2026-08-31T15:24:29Z`; and the live `doctor`
  reported **`pap feed_silent` — *« rien envoyé depuis 4 jour(s) »* on a feed delivering daily**.
  Two silent failures from one line: the store's stale-sighting guard (the 429-history-row defect)
  had no instant to compare, and a health verdict was false — hard rule 2 from both ends. The masks
  are now the grammar itself (optional day name × 1-or-2-digit day × optional seconds × `O`/`T`),
  and widening cannot weaken anything because the round-trip applies to every mask — the
  counterweight (a mismatched weekday, `31 Sep`, `tomorrow`, `+2 days`) is asserted beside the
  widening. **It was found by an audit, not by a test**: per-source NULL rates over the stored
  snapshots, which is the same instrument that found the SeLoger subject-as-title defect. Run it
  after any source goes live, and again when a portal changes its template. **The live header was
  READ, not inferred** — two messages pulled through `tools/dump-eml.php` — because *a true number
  attached to an invented cause* is this repo's named failure and the dates alone were only
  circumstantial.
- **A `Date:` header needs a STRICT parser, and `new \DateTimeImmutable` is not one.** It is a
  *relative-expression* parser: it misparses far more often than it throws, and every misparse moves
  the instant FORWARD. `Date: Fri, 09 Aug 2026` — where 9 August is a Sunday — has `Fri` applied as a
  relative modifier and records **14 August**, five days on; `now`, `tomorrow` and `+2 days` all
  parse as literal dates. That silently closed `FEED_SILENT`, whose observable band is only four
  days. The fix is strictness **by round-trip** — parse, re-format with the same mask, require
  equality — which is what `Store::epoch()` has done since its own scar. `createFromFormat` alone is
  NOT sufficient: it also returns 14 August and reports no error.
- **AN EMAIL LISTING IS OBSERVED WHEN ITS MESSAGE WAS SENT, not when the pass read it** — and for a
  month it was not, which produced the loudest defect this tool has had. Measured 2026-08-29 from
  the notification folder (825 mails in 30 days): Bien'ici re-sent one Ozoir flat a day later at a
  HIGHER rent (1122 → 1146); both messages stayed in the 7-day IMAP window, every pass re-read both
  and stamped both at the pass time, and the store — whose *"a stale sighting manufactures no
  drop"* guard keys on the observation instant — recorded "1146 then 1122, a drop" every fifteen
  minutes: **429 alternating history rows, 128 *Baisse de loyer* emails** for one flat, 53 rows for
  a SeLoger one. Every guard for this existed; it never received the date. `RawListing::observedAt`
  (an email's `Date`, strict-parsed by `EmailMessage::sentAt()`, `null` for polling adapters) is
  now what the pipeline hands `Store::record()`, the snapshot round-trips it, and a detail merge
  keeps the CARD's. Ten ledger cases pin the chain hop by hop, because every hop is a place it can
  be quietly dropped. **And one hop WAS dropped after the fix shipped green:** `Pipeline::enrich()`
  rebuilt the listing field by field and forgot the new property — on the one machine where commute
  is ON, production — so the deployed fix fired the same phantom drop on its first live pass while
  2 192 tests (commute OFF) passed. Now `RawListing::withCommute()` is a clone-with and a reflection
  guard asserts enrichment changes nothing else. **Two rules from it: never copy a `RawListing`
  field by field (clone-with inside the class carries tomorrow's property too), and a pipeline fix
  is not done until the DEPLOYED watcher's first live pass says so — commute is the production-only
  path, and a test that reproduces the sequence on a path production does not take proves the
  sequence, not the production.**
- **A GREEN TREE HERE IS NOT A GREEN CI, AND FOR TWO DAYS NOBODY LOOKED (row 45, 2026-09-05).**
  Twelve consecutive pushes were red from `46546bc` (2026-09-03 08:56) on, 113 errors + 22
  failures each, while every local run was green — found only when the developer asked to check
  `gh run list`. Two root causes, both about the RUNTIME differing from this one: **(1)** the
  coliving title exclusion used a variable-length lookbehind (`(?<![0-9]\s\w{1,14}\s)`), which
  PCRE2 accepts from 10.43; the PHP built here and in the deployed image bundles 10.44, the CI
  runner's `setup-php` links Ubuntu's libpcre2 10.42, so the loader's own compile check refused
  the whole `criteria.json` there and every test that loads it errored on `ConfigError` — a rule
  on one runtime, a syntax error on another. Rewritten as a negative lookahead from the string
  start, **measured identical over every stored title (73/73, 0 differences)**, and
  `tests/php/Repo/PortablePatternsTest.php` now refuses any configured lookbehind with a
  non-fixed quantifier. **(2)** `CredentialsNeverReachATraceTest` ASSUMED the development ini
  (trace arguments printed); the runner ships the production ini, so its premise assertion failed
  while the guarantee held for free. The test now SETS the printing runtime (`ini_set`, both
  directives are `PHP_INI_ALL`, restored in `finally`; the child probe likewise) instead of
  finding it. **Run `gh run list --limit 5` after every push** — the notification half of CI is
  the nightly ledger's issue, not the fast job's, and a red fast job is silent.
- **THE NIGHTLY DOES NOT START WHEN ITS CRON SAYS, AND THE DECLARATION IS THE WRONG THING TO READ
  (2026-09-10).** `ci.yml` declares `cron: '0 3 * * *'` (UTC); the last eight scheduled runs
  actually started between **07:26 and 07:58 UTC** — a consistent 4.5–5 hour queue delay, which is
  GitHub behaving as documented (`schedule` is best effort and is delayed at popular cron times).
  So a session that checks shortly after 03:00 UTC, finds nothing, and reports the nightly broken is
  reading the declaration rather than the history: `gh run list --event=schedule` settles it in one
  request, and it is the only thing that does — `gh run list` alone is dominated by `push` runs and
  shows no scheduled row at all. The delay is not free: it lands the six shards in GitHub's busiest
  runner window, competing for the same wall clock as the **240-minute job cap** that cancelled four
  of eight nightlies before the shard split, and no shard count can address that.
  **AND READ `date -u`, NEVER `uptime`, WHEN CHECKING THAT BAND (2026-09-10).** This box runs
  `CEST` (UTC+2) and `uptime` prints LOCAL time, so a session that reads its clock as UTC is two
  hours fast and concludes the nightly is late when it has not yet been created. That happened the
  day after the entry above was written: "09:45Z, nothing has fired" was really 07:45Z, and the run
  was created at **07:53:56Z** — inside the band, two seconds off the previous night's start. A
  true observation (no scheduled row listed) on an invented cause, which is this repo's named
  failure reached by misreading a timezone.
- **§1 HAS ONE GATE NOW, AND THE REASON IT EXISTS IS THE SHAPE OF THREE ROUNDS OF FAILURE
  (`Rent/Cli/SectionOneGate`, 2026-09-07).** §1 is judged from FOUR persisted routes — the row's own
  durable reading, the group veto, the cross-track twin, the same dwelling under another ad id —
  across THREE announcing surfaces (`Pipeline`, the digest drain, `reclassify`). A certification
  panel ran three rounds against that matrix and found 9, then 8, then 17; in every round the
  majority of defects were in the PREVIOUS round's fixes, and *a fix landing on one of two symmetric
  surfaces* was committed **five times, three of them inside the fix for the one before**. The defect
  was never the keystroke: patching one cell of a 4×3 matrix leaves eleven.
  **The gate reads ALL four routes, FRESH, at the LAST moment before every send.** Nothing hoisted,
  nothing cached — a hoist is exactly what both round-3 P0s were — and `Store::excludedDwellings()`
  is ~19 ms, run once per ANNOUNCEMENT rather than per listing, so a 90-match pass spends under two
  seconds against a 15-minute cadence. The per-route checks upstream STAY: they shape the verdict
  (an excluded reading is recorded, a doubt becomes a digest) and short-circuit work. The gate does
  not replace them; it makes their ordering stop mattering for §1.
  **THE DIGEST BIN IS AN ANNOUNCEMENT, AND FOR FIVE ROUNDS IT WAS NOT TREATED AS ONE (round 5).**
  §1 was implemented as *"never a MATCH"*, so `Outcome::DIGEST` read as a DESTINATION rather than as
  something that reaches the phone. It reaches the phone. The digest branch `continue`s 42 lines
  ABOVE the match gate, so an excluded dwelling was announced under a headline asserting *« au régime
  indéterminé »* while the store held `PLS` for the same dwelling one row away, written by that same
  pass — reachable on ordinary in-pass ordering, no concurrency, via the non-transitive tolerance
  chain. The bin is §1's ONLY landing zone, so what it announces has to be trustworthy.
  **Its refusal is deliberately DIFFERENT from the match gate's**, and copying one to the other would
  be a defect: a row refused at the bin is DROPPED and said out loud, never written `REJECT` with a
  derived durable reading — a doubt the pipeline could not resolve is not a regime it read, and the
  match gate's write is terminal by query. `$digestRefusal` is a separate variable for that reason,
  so the two cannot share one sabotage expression either.
  **`tests/php/Repo/SectionOneGateCallSitesTest.php` is the half that prevents recurrence**: it
  discovers every METHOD under `src/php/Rent` that SENDS A NOTIFICATION (a `->send(` beside a formatter or
  `Notification` — the literal `notifier->send(` it first keyed on hid a renamed receiver, `8055943` the same
  evening) and fails when one does not consult the gate in that same method. Enumerating surfaces in prose failed twice —
  `ExcludedDwellings`'s docblock said "TWO callers", then "THREE", and each time the commit editing
  that line added the uncounted one.
  **ITS FIRST VERSION FAILED TOO, and how is the rule worth keeping.** It was FILE-granular and
  keyed on `->match(`, so `Pipeline` mentioning the gate once covered a SECOND ungated send in the
  same file — which is the round-4 P0, a `Priority::HIGH` rent-drop push of a `PLS` flat from a send
  98 lines above the gate — and a new surface using a local `$fmt` was never counted at all. That is
  the same class granularity its sibling guard was rewritten to remove IN THE SAME COMMIT: the
  lesson landed on one of the two. **An announcing surface is a SEND, not a notification KIND**, and
  the gate belongs above every send in its scope, not immediately above one of them. Rewriting the
  guard that way found THREE more ungated methods the lenses had not reached — both digest drains'
  rollup half, and `announcePromotions`, whose gate sat one level up in the caller.
  **Scope, stated because the sentence above invites the wrong reading:** the gate and the guard are
  RENT-only. The car domain persists no §1 route — verified against `VehicleStore`'s schema, which
  has no tenure, group or twin column — with ONE stated cost: when a car's snapshot will not decode,
  **`CarScout::collectRollup()`** leaves `$car` null, skips the re-judge block, rebuilds the listing
  from the stored columns and pushes it as an individual match if the stored score clears the gate,
  so an excluded car can be announced — and, for an AUCTION lot, announced WITHOUT ITS CLOSING
  TIME, because the columns carry none (auction rule 2's one uncovered path, ruled 2026-09-24:
  written down rather than patched with a column). (This paragraph named `VehiclePipeline`, which contains no
  snapshot handling at all — *a true cost attached to an invented site*, this repo's own named
  failure, in the sentence written to close a gap. Found by the C2 round-5 panel.) Structural,
  not patchable: the rent drain has a stored `tenure` column to
  check for exactly that row and the car domain has no persisted fact to substitute.
- **A TOLERANCE BAND IS NOT AN EQUIVALENCE RELATION, and assuming it is cost a reverted fix
  (2026-09-07).** `Dedup::within()` matches on ±30 € / 3 %, so it is NOT TRANSITIVE: three ad ids of
  one flat 30 € apart chain, and the third sits inside the second's band and OUTSIDE the first's. A
  session investigated the `Pipeline` staleness, could not build a reaching case, concluded the hole
  was unreachable on the reasoning that *"every route that excludes one copy excludes the other"*,
  **reverted a correct fix and wrote the reasoning into the plan**. A panel lens then executed the
  push on the live path. **Failing to find a reaching case is not evidence that none exists** — and
  when the argument turns on a predicate, check whether that predicate is transitive before relying
  on it. `SectionOneGateTest::testANonTransitiveChainStillRefuses` pins the chain.
- **A HOISTED READ IS STALE THE MOMENT THE LOOP IT GUARDS WRITES TO WHAT IT READ (2026-09-07, §1
  P0).** `reclassify` loaded `excludedDwellings()` once above its judging loop, with a comment
  borrowed from `Pipeline`: *"the candidate set is a property of the store, not of the row being
  judged"*. True there, false here, and the difference is ORDERING — `Pipeline` persists every
  member's reading in a recording loop BEFORE it loads the set; `reclassify` writes `tenure` INSIDE
  the loop. So an exclusion the command resolved itself was invisible to every row judged after it,
  and the same flat re-advertised under a new ad id was promoted and PUSHED in the same invocation.
  Two panel lenses reached it independently, each with an executed push, on rows that both start
  `UNKNOWN` — the population the command exists for, not a forged state.
  **A second judging pass would NOT have fixed it**: `staleVerdicts()` orders `seen_epoch DESC`, so
  in the natural case the NEW ad is judged FIRST. The veto belongs on the PROMOTION — the last
  moment at which every verdict of the run is on disk, and the only one that is ordering-independent.
  **And the rationale for the hoist was never measured**: on a copy of the live store
  `excludedDwellings()` is 47 candidates in 42 ms and matching every stale row costs 331 ms. The
  cost it avoided did not exist. *Borrowing a justification from a sibling call site is not the same
  as checking it holds at this one.*
- **A REMAINDER ASSERTION MUST PIN THE DIGIT BOUNDARY (2026-09-07).** `assertStringContainsString('7
  autre(s) en attente')` is satisfied by `57 autre(s) en attente`. The suite was green with the
  number wrong by a factor of eight, on the line that tells an operator whether a capped batch
  drained. Anchor with `(?<![0-9])` — the same guard `ROOMS_PATTERN` already carries for the same
  reason one layer down.
- **A COUNT A HUMAN MAINTAINS IS DECORATIVE; ENUMERATE AND PIN IT (2026-09-07).**
  `ExcludedDwellings`'s docblock said *"TWO callers that must never disagree"*, then *"THREE"* — and
  **each time, the very commit editing that line added a caller it did not count**, on a line
  reading *"the count is load-bearing, so keep it right"*. Twice in two rounds. It now NAMES its
  callers and `tests/php/Repo/ExcludedDwellingsCallersTest.php` discovers the real call sites and
  fails when the list is stale. Its own first draft was vacuous — matching the whole docblock, where
  the word `Store` also appears in prose above the list — so it scopes to the enumeration bullets
  and both directions are sabotage-verified.
- **AN UNSCOPED `sed` IN THE LEDGER CAN LEAVE THE LABELLED SURFACE COVERED BY NOTHING (2026-09-07).**
  Two cases labelled for the digest verb and the daily floor shared
  `s%if (!$notifier->delivered($failures)) {%if (false) {%`, which hits **four** guards — the verb,
  `pushRetries()`, the reclassify promotion and `floorDigest()`. Both passed on the other three, and
  mutating `floorDigest()`'s guard ALONE left the entire suite green: the DEPLOYED drain marking its
  digest entries before the channel confirms — a backlog whose own comment says *"these entries have
  no other route to the developer"* — was dead safety code. `test-sabotage-applies.sh` cannot see
  this: it proves an expression MATCHES, never that it matches ONE thing. When two cases share a
  pattern, scope each with a function address range and measure the changed-line count.
- **A LEDGER CASE CAN FAIL *GREEN*, AND NOTHING IN THE GATE CAN SEE IT (2026-09-09).** One commit —
  `422e27a`, which gave every `pushRetries()` call an assignment — rotted FOUR cases at once, and
  the four did not fail the same way. Three left `$drainedKeys = ;` and became PARSE ERRORS, which
  the ledger reports as *"this proves nothing either way"*. The fourth, the rent daily FLOOR,
  truncated into the FOLLOWING statement and made a valid assignment chain
  (`$drainedKeys = $sectionOne = new SectionOneGate(…)`): it parsed, the suite went red on a **type
  scramble** rather than on its own label, and the case reported **`ok`** in every nightly for three
  days. `test-sabotage-applies.sh` is blind to it — the expression still MATCHED — and so is the
  nightly, which only ever names cases that FAILED. Two rules: replace rather than delete
  (`$drained = 0;` keeps the assignment), and after any refactor that changes a call's SHAPE rather
  than its address, re-measure the changed-line count and read WHICH test goes red, because a case
  reddening the wrong test is indistinguishable from a case working.
- **AND THERE IS A SECOND SPECIES: THE RED COMES FROM A GUARD THAT IS NOT ABOUT THE LABEL
  (2026-09-09).** The species above truncates code; this one runs perfectly and still proves
  nothing. The §1 cases for `pushRetries()`, the `digest()` rollup filter, the `floorDigest()`
  rollup filter and `announcePromotions()` mutate `$refusal = null;`, which removes the `->refuses(`
  token — so what reddens is `SectionOneGateCallSitesTest`, the STRUCTURAL guard. Suite red, case
  `ok`, §1 tested by nothing. **That guard's needle is `->send(` PLUS a `Formatter|formatter->|
  Notification` term, never the literal `notifier->send(`** — its own docblock records the single
  literal as the defeated predecessor, because renaming `$notifier` to `$channel` hid a whole
  announcing method; cite it by behaviour, not by that string. The four are exactly the methods
  holding ONE `->refuses(`, which is why deleting it leaves only the guard to answer;
  `Pipeline::runOnce` holds TWO (the match gate and `$digestRefusal`), so its case is genuinely
  behavioural. `announcePromotions()` is a knowing member — the plan rules it can have no fixture of
  its own, the caller's filter removing every refused promotion before it is reached — so the
  gap was the other three, CLOSED by row 70 (2026-09-13, below). Measured in a scratch tree whose own baseline was green (`OK 3128 /
  12118`), mutating the
  CONSEQUENCE instead so the call survives: `pushRetries()`, the `digest()` rollup filter and the
  `floorDigest()` rollup filter each go **changed=1, rc=0 — GREEN**. The shape was already ruled
  (`docs/plans/scout-unified-execution.plan.md`, the `de2a81e` entry): mutate the CONSEQUENCE,
  `if ($refusal !== null)` → `if (false)` scoped to one method. **The rule that generalises: when a
  case reddens, read WHICH test — a structural or repo-level guard answering for a behavioural one
  is a case that has measured its own scaffolding.** And the obvious repair is not always available:
  the reaching case proposed for these three (a snapshot-less row entering `$retries`) cannot fire
  at the gate either, because that row's rebuilt listing carries no commune and
  `Dedup::sameFlatReason()` requires both communes to agree positively — verified by executing it
  against a hydrated copy, which does match. **This sentence then said two of the three had no
  in-process seam, and that was wrong: a claim about ORDER made without reading the order.** Row 70
  (2026-09-13) read it. In `floorDigest()` the retries are pushed BEFORE the rollup filter, and in
  `pushRetries()` one retry's send comes before the next retry's gate read. So a concurrent writer
  simulated on the send (`DeliveringChannel::$onSend` recording a PLS twin) reaches both. The real
  finding was the VERB: it read the gate before its retries and then mailed that stale list, which
  breaks the gate's own *last moment* contract. It now reads the gate again after the retries.
  All three cases mutate the consequence, and each one reddens a behavioural test. **Before
  declaring a seam absent, read the send order: any send that comes before a gate read is a seam.**
  **THE SPECIES WAS THEN AUDITED ACROSS ALL THREE NEEDLES, AND IT IS NARROW (2026-09-10).** The
  finding above came from the `->refuses(` needle alone; the other two literal tokens those guards
  grep are `$source->acknowledge()` and `ExcludedDwellings::match(`, so every case whose `sed`
  removes one was a candidate. Eight were measured one at a time against a green scratch baseline
  (`OK 3128 / 12118`), partitioning the failing classes on `Scout\Tests\Repo\`: **seven carry
  behavioural coverage and ONE does not** — `RentScout::collectDigest()`'s `$dwellingVeto`. **And
  that one is a guarantee defended TWICE rather than a hole**, which the single mutation cannot
  show: nulling the drain's read alone is behaviourally green because `SectionOneGate` re-reads the
  route fresh before every send, while nulling BOTH reds nine behavioural tests including
  `RentScoutDigestTest::testAFlatRecordedExcludedUnderAnotherAdIdVetoesTheDrain`. It cannot be
  compounded into one case — the layers are in different FILES and `run_sabotage` takes one target
  — so it is annotated in place. **The audit's real yield was the opposite defect**: the matcher has
  FIVE call sites and the ledger covered four, `Pipeline::storedDwellingClassification()` — the one
  that shapes a live pass's verdict rather than refusing at a send — having none. A case for it was
  added and measured genuine (changed=1, behavioural red). **When auditing a needle, enumerate the
  call sites too: a case that mismeasures and a case that is absent look identical from the tally.**
- **AND THE TALLY CANNOT TELL YOU WHICH KIND YOU HAVE.** `$fail` counts copy failures, refused seds,
  inert expressions, timeouts, parse errors, harness breaks and genuine undetected cases alike,
  while the tally line and the `undetected or unapplied:` heading call all of them *undetected* — so
  issue #16's seven bare labels read as seven undetected regressions when three were parse errors
  and one was reporting `ok`. Since 2026-09-09 every label carries its kind in brackets
  (`[UNDETECTED]`, `[inconclusive-parse-error]`, `[inert-expression]`, `[inconclusive-timeout]`,
  `[harness-*]`) and the red-ledger issue enumerates them; only `[UNDETECTED]` is a finding about
  the TESTS. **The two headings are deliberately unchanged** — `tests/test-ci-workflow.sh` pins the
  `%d undetected` printf positionally against the shard ABORT, and pins `undetected or unapplied:`
  both as the proof the issue names which cases were not caught and as the regex `ci.yml` harvests
  the block with. Renaming either is a separate change that must move its pin with it.
- **A DURABLY-EXCLUDED ROW HAS ONE WAY BACK, AND IT IS A NAMED COMMAND (row 40, 2026-09-05).**
  `scout --domain=rent reclassify --reopen=<dedup_key>` prints where the exclusion came from
  (*lecture propre / jumeau / groupe / même logement* — FOUR routes since 2026-09-07; it printed
  three until then, which read as *"nothing links this row"* on exactly the population the fourth
  exists for), clears the row's OWN and TWIN readings, and re-judges it
  on its own evidence in the same invocation — a row that then judges MATCH is notified, so the
  run needs a delivering channel like every promotion. **TWO routes are reported and deliberately
  NOT cleared** — the GROUP veto and the SAME DWELLING under another ad id — for one reason: each
  lives on ANOTHER row's own reading, and a listing that really says `PLS` keeps saying it, so the
  command tells you the next pass will reject again while that holds. **`--reopen` is therefore not
  a universal undo**, and a row held by either of those two is not reversible by this command alone.
  **What it can name differs by route, and the difference is not cosmetic**: the SAME-DWELLING
  warning names the offending listing's source and external id, while the GROUP warning names no
  listing at all — a cluster veto comes from the siblings' readings collectively. Neither prints the
  `dedup_key` that `--reopen=` actually takes, so "reopen it too" is guidance, not a command line.
  Never a
  pattern, never "all" — the cost of a wrong re-open is a social-housing flat pushed as a match.
  `--dry-run` reports and clears nothing; an unknown key is refused and touches nothing. This
  closes F20's *"repair route still owed"*; the *"this row said PLS vs something linked to it said
  PLS"* distinction was offered and declined — the provenance is printed instead of stored.
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
- **A room is a NOUN, not a POSITION.** `^\s*chambre\b` was the first cut of the coliving
  exclusion and three live titles defeated it in one week — a leading emoji (`✅ Chambre 10 min RER
  B`, pushed as a match at 20:04 on 2026-08-29), an adjective (`Confortable chambre individuelle`),
  a plural mid-title. The pattern now matches `chambre(s)` anywhere UNLESS a count precedes it (a
  digit or un/deux/trois/quatre/cinq/six): a flat COUNTS its bedrooms, a room rental NAMES one.
  Measured over all 1 593 stored titles before shipping: 48 room rentals caught (anchored: 36),
  zero flats. **Trial a pattern over the store before shipping it** — `SELECT title FROM listings`
  is one query and it is the only corpus of real titles this project has.
- **A GENERIC READER MUST NOT SCAN A URL'S QUERY — seventh instance of *URLs are classified text*,
  and the second poisoning of the SAME scan (2026-09-02).** Track 1j anchored the rooms branch
  against hexadecimal photo UUIDs with `(?<![A-Za-z0-9])`; base64url walks straight past that,
  because `-` and `_` are not alphanumeric. SeLoger wraps every link as
  `click.by.seloger.com/?qs=<per-recipient token>`, and one token reading `…zaw7m29jtx…` stored
  **7 m²** for a flat whose own card says `3 pièces . 64,25 m²` — offset 1029 beating offset 1948.
  The repair is a rule this repo had ALREADY RULED one layer up and never applied here:
  `RawListing::text()` drops a URL's query and fragment and KEEPS its path before the classifier
  reads it, because `?c=plai_plus` is a campaign string nobody can rewrite while a `plai` path
  SEGMENT is a real social signal. `EmailAlertSource::prose()` makes the same cut for the generic
  readers, and **its scope is load-bearing at both ends**: a CONFIGURED pattern owns its answer and
  is left untouched (pap is bit-for-bit unchanged), and the LINK readers never see it — for seloger
  the whole URL *is* the query, so stripping it there empties every notification's link and re-keys
  the entire backlog on the portals whose identity IS the link. **Measured over 2 043 stored card
  bodies before shipping**: 26 surfaces and 4 room counts recovered, all seloger; rent, postcode and
  commune 0; the other six sources 0 each. Seven of the 26 were matches that cleared every filter at
  their true size and were never notified. **Found by a scheduled query, not by a test** — Track
  6-A6, on the first day it was answerable — and the plan predicted the wrong cause (it expected the
  positional repair the rooms reader got), which is why the query says *run it*, not *reason about
  it*.
- **AND THE PATH HALF IS THE EIGHTH INSTANCE, closed 2026-09-04.** The query strip above closed one
  alphabet; the PATH is deliberately KEPT — a `plai` path SEGMENT is a real social signal while a
  campaign string is not — and `SURFACE_PATTERN` had no left anchor at all, so a digit in the middle
  of a path token still beat the real figure below it (`preg_match` is first-match-wins).
  `ROOMS_PATTERN` had carried `(?<![A-Za-z0-9])` since Track 1j; the surface branch had neither
  guard. A review lens graded it the softest finding in its report, on the grounds that no real
  payload carries `m2` in a path — **and the store disagreed**: Bien'ici's own photo host is
  `d2m2j20yzublln.cloudfront.net`, `2m2` reads as 2 m², and four stored flats of 41, 54, 65 and
  59 m² are held at **2 m²**, three of them silently rejected by `min_surface_m2: 50`. The
  distribution id is on every photo URL from that CDN. **Measure a repair through the CURRENT
  pipeline, never against the raw payload**: applied to the raw stored body the same anchor changes
  41 rows, but 37 are SeLoger tokens the query strip already closed — a true number attached to a
  cause that had already been fixed, which is the repo's named failure pointing backwards.
- **A PROCESSED ALERT EMAIL IS MARKED `\Seen`, AND THE FLAG MEANS ONE THING (row 36, 2026-09-04
  — developer request: *"mark the emails in (rent|car)/portails as seen when you process them, so
  that way I know which email was processed and which not"*).** `ImapMailbox` READS under
  `EXAMINE` + `BODY.PEEK[]` exactly as before; the one write is `acknowledge()` — a second session,
  `SELECT`, one `UID STORE … +FLAGS.SILENT (\Seen)` — on the messages a source CLAIMED (passed its
  `params.from` and `subject_pattern`, whatever they then yielded) and that do not already carry
  the flag, so steady state opens no write session. It is called by the domain pipelines ONLY (rent, car, job), after
  the store has recorded the pass, through `Scout\Adapters\AcknowledgesMessages` (gated on the
  interface, forwarded by `PacedSource`, so `--watch` marks exactly what `--once` marks); `doctor`
  and `tools/dump-eml.php` never mark, pinned by `AcknowledgeCallSitesTest`. A refusal lands in
  `RunResult::$errors` and the pass carries on — the listings are on disk and the flag is for the
  human. **So an UNREAD message inside the window is a signal**: no configured source claims its
  sender or subject (a new template, a widened filter), or the `IMAP_MAX_MESSAGES` cap cut it —
  truncation is visible for the first time. Two things not to "improve": the `SEARCH` stays
  `SINCE` + `FROM`, never `UNSEEN` (the 7-day re-read is what lets a misread card self-heal and
  what `FEED_SILENT` measures — the store's seen-set is the dedup, the flag is not), and the client
  addresses messages by UID with `UIDVALIDITY` compared across the two sessions, because a
  sequence number is only meaningful inside one. `tests/php/Adapters/Mail/ImapMailboxWireTest.php`
  is this client's FIRST wire coverage — a scripted loopback server behind the `$connector` seam,
  consulted only after the offline refusal — and 23 ledger cases pin every direction above.
- **Two tracks, ONE push (developer ruling, 2026-08-29).** The 2026-08-06 rule that a landlord's
  listing and its agency copy on SeLoger/Bien'ici are two findings STANDS — identities, groups and
  histories stay per track, `Dedup::duplicateReason()` still refuses across families — but 43 flats
  had been pushed twice, so `Dedup::twinReason()` links cross-track twins for NOTIFICATION only,
  with the same positive-evidence bar. Clusters are judged direct-route first; the push names the
  other route with its link; the agency copy is marked, not pushed; and a direct route arriving
  AFTER the agency copy is still pushed once, saying whose push it follows — the better route is
  never hidden. **The source now leads every listing title** (`seloger · 44/100 — …`), because the
  developer prioritises by source and the title is what a phone shows first.
  **AND THE LINK CARRIES §1 (2026-08-30, two panel rounds):** the twin's judged tenure feeds the
  same funnel as the persisted group veto — an EXCLUDED twin on the other track REJECTS the flat
  whichever route is being judged, an UNDETERMINED twin turns the match into a DIGEST — and the
  fact is PERSISTED on the row (schema v12 `twin_tenure`/`twin_source`, `Store::recordTwin()`),
  because a veto living only in the pass's harvest lapsed the moment the twin was not fetched: a
  pass seeing the agency copy alone pushed the PLS flat. Precedence is the group veto's: excluded
  sticks for the row's life (developer ruling), otherwise the last reading wins, so a doubt clears
  only when both routes are judged together again. `reclassify` reads it beside the group veto.
  **Round 3 added two more halves**: the fact is written on EVERY member of the cluster and read
  as the most restrictive across it (a second private-portal copy absorbed on one pass and surviving
  on another had been pushed twice), and a row's OWN excluded reading is durable too (a hydration
  fingerprint mismatch served the card alone, and a PLS row was re-judged LIBRE and pushed). The
  row records the JUDGED verdict, so `scout digest` announces the doubt's cause.
  **Round 4 (2026-08-31) found that last half landed on ONE of its two surfaces, and the sentence
  above was true only while the protected row kept surviving its cluster.** `durableOwnReading()`
  repaired the survivor's JUDGEMENT; the recording loop still overwrote EVERY member's stored
  tenure with today's raw reading, and only the survivor's was rebuilt. Survivorship follows the
  harvest order and `Core\Pacer` shuffles it every pass, so a row holding yesterday's `PLS` lost it
  the moment a sibling was polled first — before `groupExcludedTenure()`, which reads that same live
  column, was ever consulted — and the flat was pushed. **The same read also fed the twin scan**,
  which took the other track's tenure from the pass's RAW classification, so a twin held excluded
  only by its own durable reading contributed an ELIGIBLE one, `recordTwin()` persisted it, and the
  agency copy was pushed naming the PLS route as the *voie directe* with its URL. Two lenses found
  it independently. The durable reading is now applied **in the recording loop, per member, before
  anything is written** — the same refuse-to-downgrade rule `recordTwin()` already used, so there is
  no window in which the excluded reading is off disk, and it holds for a member that is never
  judged at all. **It is PERMANENT to every automatic path**: nothing re-opens it (`staleVerdicts()` skips an excluded
  tenure, `pendingDigest()` skips a non-DIGEST outcome, `replay` writes no verdicts). The docblock's old
  *"until an explicit command"* named a route that did not exist when this was written (2026-08-31); since
  2026-09-05 (`2553c94`) it does — see *"A DURABLY-EXCLUDED ROW HAS ONE WAY BACK"*: `reclassify --reopen`
  clears the row's own and twin reading. The code comment in `Pipeline.php` beside `durableOwnReading()` still
  says "There is no such command" [re-checked 2026-09-28].
- **EXTRACTING A CLASS ORPHANS SABOTAGE EXPRESSIONS THAT NEVER NAMED IT, and the obvious
  measurement says otherwise.** The 2026-09-01 `Core/RunStore` split moved 625 lines out of the rent
  `Store`. Asking which ledger expressions mention one of the six moving METHOD NAMES answered
  **1**; the expressions target code *inside* those bodies, which names no method, and
  `tests/test-sabotage-applies.sh` found **39 of 607 gone INERT** — reporting coverage they did not
  have. `grep -c "Rent/Store/Store.php" tests/sabotage-check.sh` answers 94 and is the number to
  ignore; only running the gate answers the question. Retarget the file path, never the expression:
  the code moved verbatim, so each must match in the new file AND not in the old, checked one at a
  time. Four other things this split is worth remembering for, each a silent failure:
  **(a)** a generic store must NOT adopt `schema_meta` — the live rent file records `12` there, and
  a v1 store reading it would refuse to open the database that produces the matches, so the run log
  owns its own `run_meta`; **(b)** `VehicleStore::migrate()` RETURNS EARLY at the current version, so
  a cleanup for existing files placed in the migration transaction never runs on the one file it
  exists for — it goes above the return; **(c)** copying a table's ORIGINAL `CREATE` rather than its
  CURRENT shape passes `php -l` and reflection and throws on the first write (`source_runs` had
  gained `feed_newest_at` by `ALTER` at v11) — only running it finds that; **(d)** `backup-state.sh`
  counts the rent `listings` table, so every car backup had reported *"0 annonces"* while holding
  3 544 vehicles. A backup that reports itself empty is one nobody checks.
- **`prototype/scout.py` has no tenure classifier at all.** It will happily surface PLAI and PLUS
  listings. It is reference material for the field-mapping and adapter shape only — treat its filtering
  logic as incomplete, not as a baseline to preserve.
- The prototype's commune matching is a substring search over `commune + cp + title + raw_text`. That
  over-matches: a Paris listing mentioning "proche Chatou" passes the commune filter.
- The prototype's `(l.rooms or 0) < self.min_rooms` **disqualifies an unknown room count**. Same for
  surface. That is the `None`-is-not-zero bug (hard rule 9) in its natural habitat.
- The prototype swallows every per-source exception and `continue`s — hard rule 3.
- The prototype's `max_floor` is a hard reject. **Ruled 2026-08-07 (Q5): floor and lift are score
  components only**, and more strongly than the spec asked — `max_floor` and `require_elevator` do
  not exist as config keys at all, so the prototype's behaviour cannot be reintroduced by editing a
  file. The high-floor penalty additionally requires the lift to be **explicitly absent**, never
  merely unmentioned: `null` is not `false` (hard rule 9), which is why it is its own score
  component rather than the negation of the bonus.
- `prototype/sources.yaml` mixes criteria, notification config and sources in one file. The PHP tree
  splits them per domain: `config/{rent,car,job}/{criteria,sources}.json`.
- **`allow` rules in `.claude/settings.json` are inert in cloud sessions.** [Unverified 2026-09-28: Claude Code
  behaviour, not checkable from the repo.] They need an accepted
  workspace-trust dialog, which a cloud session never shows. `defaultMode` is what actually takes
  effect. Don't grow the allow list expecting cloud effect.
- **New skills need a session restart to appear.** [Unverified 2026-09-28: Claude Code behaviour, not checkable
  from the repo.] Claude Code watches an existing `.claude/skills/`
  directory live, but a newly-created one is not watched until the CLI restarts. The `CLAUDE.md`
  sections bind immediately; the slash commands appear next session.
- **Commit messages: always `git commit -F -` with a QUOTED heredoc (`<<'EOF'`), never `-m "…"`.**
  A double-quoted `-m` string runs backtick command substitution, so any `` `Identifier` `` in the message
  is executed and replaced with its (usually empty) output. Hit on 2026-08-06 in commit `7234550`:
  `` `using` `` was eaten, leaving *"Closable + for the connection"*, and `bash` reported
  `using: command not found`. History was **not** rewritten — force-push is unauthorised here and the loss
  was one word in a message — so the cause is fixed instead. A `<<'EOF'` heredoc is literal: no expansion,
  no substitution, backticks safe.
- **Composer cannot install anything here, and that shaped the toolchain.** The container's egress
  policy returns **403 on `codeload.github.com` and on `api.github.com/.../zipball`**, which is where
  Composer fetches dists from. `git clone` over HTTPS *is* allowed, so `--prefer-source` works — but
  it pulls full git histories, and installing PHPUnit that way produced a **2.6 GB `vendor/`** for a
  test runner. The project therefore has **zero Composer dependencies**; `vendor/` holds only the
  generated autoloader (56 KB) and the runner is PHPUnit's official PHAR at `tools/phpunit.phar`
  (6 MB, gitignored, fetched from `phar.phpunit.de`, which is not blocked). Do not "fix" this by
  adding a dev dependency. Per `/root/.ccr/README.md`, a 403 from the proxy is reported, not routed
  around.
- **`composer dump-autoload` WITHOUT `--dev` silently breaks the corpus suite.** It omits the
  `Scout\Tests\` PSR-4 entry; PHPUnit still loads the test *files* itself, so the unit tests keep
  passing while every corpus test errors `Class ... not found`. It reads as a code regression and is
  a build state. `tests/bootstrap.php` now checks this and prints the fix, but if you see that error,
  run `composer dump-autoload --dev`.
- **`.claude/hooks/tenure-guard.sh` false-positives on ordinary PHP, and that is a known cost.** It
  fired five times while the first PHP was written, every time on prose or syntax: `$flat[] =`
  (PHP's array append, read as an empty-list literal — the pattern now enumerates the shapes that
  actually empty something: `= []`, `=> []`, `return []`, `: []`, `: null`, `= array()`), a
  `0.0001` float epsilon read as a lowered confidence threshold, and phrases like *"no tenure
  signal"*, *"clear the floor"* and *"must never be deleted"*. When it fires, check WHICH pattern
  matched before assuming a real problem — reproduce with
  `tr '[:upper:]' '[:lower:]' < file | grep -oE '<pattern from the hook>'`. Reword prose to keep the
  tripwire credible; never weaken a pattern without a matching case in `tests/test-tenure-guard.sh`.

  > **TWO THINGS THAT MAKE THE DIAGNOSIS ABOVE FAIL, both learned 2026-09-04 after three more
  > firings in one session.** First, **the hook matches the EDIT PAYLOAD, not the file** — so
  > re-running it against the file on disk can come back silent while the write was blocked, which
  > reads as a phantom and wastes the next ten minutes. Second, `[^.]{0,80}` **spans newlines**, so
  > the window reaches across a closing brace and a blank line into the NEXT function: one firing
  > was an assertion message ending on *"never"* immediately before a docblock beginning *"A DOUBT
  > IS CLEARED"*, two declarations apart. Neither is visible from the matched line.
  >
  > The reliable diagnosis is a SET DIFFERENCE of the pattern's matches over the whole file, before
  > and after the write — `git show HEAD:<file>` against the working copy, both lowercased, both run
  > through the hook's own regex in `python3` (`re.S`), printing only what is new. That names the
  > match in one step. The three firings that session were `array $fields = []` in a test helper, a
  > constant named `…CLEARING_CONFIDENCE` sitting inside the window of an `isExcluded()` call, and
  > the cross-declaration one above. All three were reworded; no pattern was touched.
- `ruff` **is** available in this container, and although the PHP side now has a `composer.json`
  there is still no Python manifest — so
  `.claude/hooks/lint-on-write.sh` is live and will report on `prototype/scout.py`. Those findings are
  known and deliberately unfixed: the prototype is kept verbatim as received. Since 2026-09-28 they
  reach Claude directly (the hook's `additionalContext`), so they arrive after any edit there — they
  still stay unfixed.
- **A LEDGER CASE THAT TIMES OUT READS AS "UNDETECTED", AND ON A LOADED BOX THAT IS THE COMMONEST
  FALSE RED.** Each case runs under `timeout` (`SABOTAGE_SUITE_TIMEOUT`, default 300 s) and a suite
  that never finished is counted as a loud FAILURE — correctly, since a hang is not a detection. But
  the full suite takes ~88 s on an idle box, and this machine is shared: on 2026-09-04 a PHPUnit run
  from an unrelated project (`/stack/projects/invoiceninja`) pushed the load average to **27** while
  the ledger was running, and a case sat at 256 s of a 300 s budget with nothing wrong. Check
  `uptime` and `ps -eo pid,etimes,args --sort=-etimes | grep phpunit` before believing a lone
  timeout; re-run that case alone on a quiet box, or raise `SABOTAGE_SUITE_TIMEOUT`. Same class as
  the JIT entry below — the harness broke, the guarantee did not.
- **The local PHP's tracing JIT crashes the sabotage ledger nondeterministically.** [HOST CHANGED —
  re-checked 2026-09-28: `php` is now the distro `/usr/bin/php8.5` and `~/.phpbrew` is gone, so the phpbrew
  recipe below is historical; whether the distro build crashes the same way is untested. What still holds:
  a crash exit is *harness broke*, not a detection.] `php` here was
  phpbrew's `8.5.9 (ZTS DEBUG)` with `opcache.jit=tracing` and `opcache.enable_cli=1`; under the
  ledger the suite dies mid-run with `zend_jit_trace.c … Assertion !p->op_array failed` (exit 134),
  which `tests/sabotage-check.sh` rightly counts as *harness broke*, not as a detection — observed on
  three different cases across two runs on 2026-08-30, each detecting fine on the other run. CI is
  unaffected. For a local run, disable the JIT WITHOUT dropping the original ini scan dir:
  `PHP_INI_SCAN_DIR="<phpbrew var/db/cli>:<a dir holding opcache.jit=off>"` — dropping the original
  loses `iconv` and reddens the baseline for an unrelated reason (measured: 18 errors). Take the
  phpbrew dir from `php --ini`, and **strip the double quotes it prints around the scan dir**: pasted
  with them the path matches nothing, twelve extensions drop and the ledger baseline aborts red
  (measured 2026-09-13).

## Credentials & stateful data

**Nothing reads the environment yet** — the adapters, the channels and the CLI do not exist, so
`.env.example` is the agreed SHAPE of the configuration rather than live settings.
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
