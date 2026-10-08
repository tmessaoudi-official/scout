# scout — engineering notes

> Moved verbatim from `CLAUDE.md` on 2026-09-28 (review-remediation 5.4): that file's own boundary test
> sends material Claude does not need to deliver correct code to `docs/`, and these dated records had grown to
> 132 KB of its 286 KB. Nothing below was reworded. Two things changed: the one relative link was rebased
> for `docs/`, and ONE heading was added — § "Status notes accreted after the transit section" — because the
> paragraphs after the transit note are status records that had accreted under it. Paths in backticks are
> relative to the repository root; a § reference names a heading here OR in `CLAUDE.md` (§1, the hard rules and
> the glossary stayed there).
>
> `CLAUDE.md` § "Engineering notes — moved to docs" indexes these sections and the standing rulings they
> record. New notes of this kind go here (or into `HISTORY.md` / `SOURCES.md` / `ARCHITECTURE.md`), not
> into `CLAUDE.md`.

### Detail hydration — the cache is the gate (2026-08-23)

**Phase 2 is BUILT, and Phase 2b (2026-08-23) added the two facts the description was carrying all
along** — see the floor/lift block above, plus the schema-v6 map fingerprint that stops a widened
`detail_map` from serving rows captured under the old one for ever. In'li has a `detail_map` (`h1` for the title, `.advert-body-description p` for
the description), which matters because an In'li card's ENTIRE text is `1 005 € cc 3 pièces ·
55.32 m² Longjumeau` — four facts, no title, so `exclude_title_patterns` was **structurally dead on
the source producing two thirds of the matches**, and nothing had slipped through only because In'li
lists flats. Luck, not a filter.

Three mechanisms replaced the single gate, and each closes a hole the others open:

- **NOVELTY IS THE GATE, and it lives in the schema-v6 `listing_detail` cache** — keyed on
  `(source, external_id)`, never on `dedup_key`, because normalisation evolves and a row keyed on a
  conclusion silently orphans the whole cache the day it changes. A page already on record costs no
  request ever again, so steady state is ZERO extra requests. **Hydration without persistence would
  be worse than none**: the listing's verdict would depend on which pass looked at it.
- **A per-pass BUDGET** (`detail_budget_per_pass`, default 20) bounds the cold start, which is the
  only expensive moment — In'li's ~174 listings are all novel at once, and at Q37 pacing that is a
  three-hour pass. The backlog drains over several passes. **An explicit `0` is REFUSED at load**,
  because a `detail_map` that can never run is a disabled feature dressed as a configured one; an
  OMITTED budget defaults, because a slow cold start is benign. Same asymmetry as `RENT_HEARTBEAT_HOURS`.
  This refusal is the successor to *"a `detail_map` with no gate REFUSES"*, which retired with the
  gate — replaced, not deleted.
- **PRIORITY decides who gets a short budget, and rank 0 is *not yet in the seen-set*.** Ranked any
  lower, backlog eats the budget while a genuinely new listing is notified unhydrated — and by then
  it is already `notified_at`, so hydrating it later buys nothing. That is the same bypass rebuilt
  out of a budget. Rank 1 is `matchesCommune()`, rank 2 the backlog.

**A per-listing fetch failure no longer voids the pass, and the taxonomy is the rule**: config-shaped
failures still THROW (robots refusing the detail path, a card with no `url` — those are states, not
events, and every hydration would fail for the same reason), while a runtime failure is RECORDED
with its attempt count and redacted message, counted by `Store::detailFailureCount()`, and reported
by `scout --domain=rent doctor`. Throwing was right about silence and wrong about blast radius: it voided the
entire pass, so one permanently-404ing page meant the source returned nothing, marked nothing seen,
never notified a new listing again — and reported `SOURCE_BROKEN` on a diagnosis that was untrue.
Retried past a 6 h backoff, three times, then left alone.

**Stated cost:** a listing whose detail page cannot be read is judged on its card alone, exactly as
every listing on that source is judged today — `exclude_title_patterns` cannot fire on it. In'li's
floor and lift also stay `null`, so the high-floor penalty cannot fire on that source; `null` says
nothing rather than saying no, which is the safe direction (hard rule 9).

> **The reason given for that used to be *"In'li publishes no lift at all"*, and it was measured on
> ONE page.** Live acceptance on 2026-08-23 hydrated 20 real detail pages: **18 of 20 mention
> `ascenseur` and 19 of 20 state a floor.** The frozen fixture contains neither, so the assertion
> pinning it is true of that capture and false of the source — a generalisation from n=1, the same
> error class as the retired *"live yield is 0"* claim two entries down. The fields stay `null`
> because nothing MAPS them, not because the source withholds them.
>
> **Recovered on 2026-08-23 by `Core\Prose`**, an opt-in reader wired through the reserved capture
> prefix `=> prose:floor` / `=> prose:elevator`. Two rules carry it, both hard rule 9 inverted —
> a fact manufactured from its own negation:
>
> - **A floor is a POSITION (`au 3e étage`), never a COUNT (`de 18 étages`).** `Payload::floor()`
>   returned **4 for a flat on the 3rd floor**: In'li's own copy carries the typo `au 3? étage`, the
>   ordinal failed, and `d'un immeuble de 4 étages` answered instead. Fixed there by requiring the
>   singular `etage\b` — deliberately NOT by anchoring on `au|en`, which would regress CDC's
>   preposition-free card. The anchored reader is `Prose`, and it is opt-in per map.
> - **A lift reads its NEGATION first.** 5 of the 18 mentions are negations (`sans`, `Aucun`,
>   `Pas d'`, `ne dispose pas d'`, one with a curly apostrophe). A wrong `true` awards a bonus for a
>   lift that does not exist; a wrong `false` only lowers the score. `Payload::bool()` cannot do this
>   and must not be extended to — it matches the whole trimmed string, so prose returns `null`
>   (safe), while a substring reader would read *"Aucun ascenseur"* as `true`.
>
> Ground truth is `tests/fixtures/rent/inli/descriptions.json` — 20 live captures, each hand-labelled, which
> **live extraction now matches 20/20**. The bare ordinal (`situé au deuxième`) and the site typo are
> deliberately NOT parsed: under-extraction is the safe direction.

> **HYDRATING IN'LI PROVED IT IS NOT PURE LLI, and that is the single most valuable thing Phase 2
> produced.** Two live listings state *"Le logement est soumis au plafond de ressources **PLS**"* —
> which their CARDS never said. Under §1 that is a reject, and only the detail page carries it. Every
> document here calls In'li pure LLI; it is not, and a source's tenure claim is a property of its
> LISTINGS, never of the source.

> **The same hydration also demoted 4 of 40 live matches to the digest, on the word `plus`.**
> `ListingMapper` passes the WHOLE structured surface as `fields`, so a mapped description arrives
> twice — as the property, read with the prose rules, and as a bare `description` key, read with the
> identifier discipline that turns the adverb into the acronym. In'li states no explicit label, so
> that tier-1 doubt was the only tier-1 signal present and it decided the verdict. `title` and
> `description` now re-route to the prose scan, guarded on CONTAINMENT rather than on the name so it
> cannot become a named hole. Third instance of the `au plus près` class, and the first that was
> silently costing real matches.

**Location is REGION MODE as of 2026-08-22 — and by the end of that day it covered ALL of
Île-de-France, at `min_rooms: 3`, `min_surface_m2: 50` and `max_rent_cc: 1200`.** `communes: []`
means the name is not checked and `postcode_prefixes` is the entire location filter; `commune_rank`
still orders results, so the Boucle de Seine is a preference rather than a hard reject. Every change
is ruled (Q1, Q2, Q3 in `docs/OPEN-QUESTIONS.md`, each naming the one line that reverses it) and
every one is measured on a live poll: region mode over 78/95 took the yield from **0 to 8**, and
widening to all eight departements while dropping the surface floor to 50 m² and the ceiling to
1200 € took it to **83 matches out of 478**.

> **That 83 is the number that should be quoted, and predicting it would have got it wrong.** All
> eight of the old matches quoted 1258–1669 € CC, so the ceiling alone kills every one of them — a
> first draft of the Q2 entry reasoned exactly that far and wrote *"the live yield is zero"*. The
> other two changes had opened a pool the old criteria never looked at. **Never predict a yield from
> the previous filter's matches**; `scout --domain=rent run --once --seed` on a throwaway `RENT_SCOUT_DB` costs
> one poll. Two live consequences worth knowing: nearly every match is OUTSIDE the ranked communes
> (91/93/94 — Les Ulis, Aulnay, Pierrefitte, Vitry — with Dourdan and Dammarie-les-Lys scoring
> highest), because there is nothing under 1200 € CC in the Boucle de Seine; and scores ran
> **16–48**, so `high_priority_score: 70` could never fire and the `!!` marker was dead.
>
> > **BOTH HALVES OF THAT LAST CLAUSE WERE REFUTED ON 2026-08-26, and the second one had never been
> > written down at all.** Re-judging all 256 stored v7 snapshots offline — no poll needed, schema
> > v7 keeps the evidence — scores now run **0–70** (median 28, p90 40): commute lifted the ceiling
> > that morning and one listing actually reaches 70, so 16–48 is a pre-commute figure. **And the
> > marker STILL could not fire, for a structural reason nobody had stated:** `!!` needs
> > `score >= high_priority_score` **AND** `confidenceBp >= 80`, and those two are satisfied by
> > **disjoint sets of listings**. The top scorers are all `conf 50` — private-portal cards whose
> > tenure is the source default — while the listings that clear the confidence floor top out at
> > **55**. At any threshold ≥ 60 the marker is unreachable *by construction*, not by luck.
> >
> > So the threshold is **50** (developer ruling, 2026-08-26), marking 3 of the 47 confident
> > listings. That is not the *"tuning the instrument to the reading"* this entry warned against:
> > 70 predates commute and was never derived from anything, so this is the FIRST calibration it has
> > had. **The confidence floor is deliberately untouched** — lowering the score bar while it stands
> > tightens what `!!` means rather than loosening it, and `HighPriorityMarkerTest` plus two ledger
> > cases now pin it, because deleting the floor used to leave the whole suite green.

Three things to know before touching this. **Region mode is the first LOOSENING this config has taken**, so it is guarded from
both sides — the loader REFUSES `communes` and `postcode_prefixes` both being empty (the one shape
that fails open, and over-matching is invisible because it looks like a busy market), and an unknown
postcode is REFUSED in region mode though it is forgiven in list mode. That is not a hard-rule-9
violation but what hard rule 9 actually says: in list mode the name already matched and the postcode
only narrows, while in region mode the postcode is the only evidence there is. **And it caused a
regression no test of region mode itself would have found**: `Criteria::communeLabels` is the
vocabulary `EmailAlertSource` reads a commune out of an alert body with, and building it from
`communes` alone left it empty — every emailed listing would have silently lost its commune (no S1
score, nothing to name in the notification, a weaker dedup key) while still matching on its
postcode, so nothing would have looked broken. Ranked communes now feed that vocabulary too.

> **Two ranked sources were dropped the same day, and the catalogue was wrong in nine rows.** Seqens
> (A5) and Immobilière 3F's own site (A6) publish no vacancies at all — both dead-end at `al-in.fr`,
> because Action Logement's ESH allocate by commission. That makes **A4 AL'in the only route to that
> group's stock**, not one source among many. `docs/SOURCES.md` carries the measurements and the
> cheap pre-check that would have caught A2, A5 and A6 before any deep crawl: on WordPress ask
> `wp-json/wp/v2/types` (it enumerates CUSTOM post types, so it settles the "maybe the search is
> JS-rendered" objection a sitemap scan cannot); on any site scan the index page for `€`, `m²` and
> `disponib`.
>
> **Three more went the same way on 2026-08-21, and Track 1's BUILT stock is now FOUR sources — A12 was measured pollable that day and built on 2026-08-22 (below).** A10 Batigère was
> the catalogue's starred best-remaining candidate; its search is a third-party widget whose bundle
> names its backend, and that host's `robots.txt` answers **500** while the endpoint answers **401**.
> A7 1001 Vies has no listings post type and routes tenants to `demande-logement-social.gouv.fr`
> (out of scope, §1). A8 Antin's one recorded lettings route is a **404**. What remains in Track 1 is
> **A4 AL'in** (authenticated — an INPUT, not a decision) and the Tier B email-alert route.
>
> **A11 and A13 were the last two marker-scan rows, and both were measured on 2026-08-21 — so Track 1
> is now measured out, and this time the word is earned.** ⚠️ **That last clause was FALSE when
> written and is worth keeping as the correction it earned.** A14 and A15 were both `UNMEASURED` at
> the time and still sat in the table; "measured out" described the marker-scan GROUP and was written
> as though it described Track 1. **A14 RIVP was measured 2026-08-26** and is NOT pollable and out of
> scope by §1 — no lettings post type at all (`wp-json/wp/v2/types` settles it in one request), a
> `residence` type that is the heritage map, and a *"Je cherche un logement **social**"* route to
> `demande-logement-social.gouv.fr` stating `numéro unique` twice. **A15 Val d'Oise Habitat was
> measured 2026-08-26 and Track 1 is now genuinely measured out** — every catalogued row has a
> verdict with a date. A15 is the FIRST row blocked by an active ANTI-BOT CHALLENGE rather than by
> having no feed: every HTML request 302s to `/shield?u=…`, whose page solves a challenge in
> JavaScript and sets a `shield` cookie. **Hard rule 5 refuses that outright** — obtaining an access
> cookie by solving a bot-detection challenge is the same class as CAPTCHA solving, so this is a
> RULING and not a capability limit. Do not revisit it with a headless browser. Its older tenure
> blocker stands anyway (predominantly social, out of scope by §1 and Q4), and whether it offers an
> email alert is unknowable from outside the shield.** The exhaustive pass produced a THIRD kind
> of verdict, which the catalogue had no column for. **A11 Toit et Joie is `www.postehabitat.com`**
> (301; the domain was stale, the third in three rows) and it is the delegation pattern a fourth
> time: its availability search is real and returns **0 dwellings** — but that zero is only worth
> anything because the same form returns **8 parkings and 2 commerces**, which is the rule the row
> adds, *a search that answers 0 needs a CONTROL query*. Its lettings route links
> `demande-logement-social.gouv.fr`, out of scope under §1. **A13 Erilia is POLLABLE and worthless**:
> `/louer/recherche` is clean, stable, GET-paginated and quotes rent `cc` on all 49 listings — and
> **zero of the 49 are in Île-de-France**. *Pollable* and *useful* are different columns; a catalogue
> recording only the first keeps proposing work that cannot pay. Erilia also carries the furniture
> class a FIFTH time, in its worst shape yet: the footer widget *"Ai-je droit à un logement social ?"*
> classifies **SOCIAL 0.90 → REJECT**, so a selector capturing the page rather than the card would
> reject every listing on the source while its health stayed green. One thing is worth keeping from
> A11 — its `/Plafonds-de-ressources` page carries the **PLAI/PLUS/PLS ceiling tables** for IdF, the
> social half of the missing tier-4 input; it states **no year**, so it is a pointer, not a figure.
>
> **A12 Logirep/Polylogis IS SOURCE #4, live since 2026-08-22** — and it was the row ranked WEAKEST.
> `scout --domain=rent doctor --source=logirep` returns **113 annonces, 428 ms, `ok`**: one request, no pagination,
> so it is the cheapest source in the tree by a factor of fifty (In'li takes 24 s). One endpoint
> covers four Polylogis landlords. Four things about it are worth carrying forward:
>
> - **Its homepage carries two `€` and two `m²` and no lettings link — and those four markers ARE a
>   lettings search form.** It POSTs to `/` and its results route exists only as a **303 `Location`**
>   (`/recherche?ss_trnsctntp=leasing`). Hence the rule: *a marker count is not a route census* —
>   submit the form, read the `Location`, and check it against `robots.txt` **before** following it.
>   Here that mattered: Logirep disallows `/search/`, and robots matching is LITERAL, so it does not
>   reach `/recherche`. One path over and the answer would have been no.
> - **The payload is JSON inside a `<script>` tag**, which neither adapter could read: `html` maps
>   selectors over repeated card elements and there is only ONE tag, and `json` parses the response
>   body, which is HTML. `type: json` gained **`embedded_json_selector`** — one step in the middle
>   that pulls the element's text and hands it to the ordinary JSON path, so `items_path`, the field
>   map and `ListingMapper` keep exactly one implementation and hard rule 9 is not re-decided. A
>   selector matching nothing **THROWS**; returning `[]` would read as a quiet market forever.
> - **Drupal/Solr boxes every text field as a ONE-ELEMENT LIST** (`"…locality": ["AVON"]`), and
>   `Payload::string()` returned `null` for those. That is the most dangerous bug this build turned
>   up and it is invisible: `matchesCommune()` refuses a null commune, so the source would have
>   mapped 113 listings, matched none of them, ever, and reported a green `SourceHealth` with a
>   plausible count throughout. `Payload::scalarOf()` unwraps a list of scalars — never an
>   associative array, never recursively, and never treating `0` as absent.
> - **Rent is `h.c.` and its charges are NOT reliably recoverable**: a `Charges locatives` field on
>   one detail page (17%), free prose on another (30%), and nothing at all on a third. Two shapes,
>   two ratios, one absence — so no uplift is defensible. It is mapped `charges_included: false`,
>   which `CriteriaEngine` was already built for (*"charges comprises, and never on an HC-only
>   figure"*): the value lands in `rentHc`, `max_rent_cc` never fires on it, and the score line says
>   the ceiling is unverifiable. **Stated cost: the rent ceiling is not checkable for this source.**
>
> **Its live yield today is 0, and the catalogue said otherwise.** A12's row read *"8 of the 19 are
> in the 78/95 departments the criteria filter on… would plausibly yield on day one"*. Running the
> real gate over the frozen payload gave **1 of 113** — `matchesCommune()` needs the commune NAME as
> well as the prefix, and Bezons was the only overlap, on a commercial unit that
> `exclude_title_patterns` then rejects. A department is not a commune; the row said so one sentence
> after claiming the opposite. It is asserted by test, so the number cannot drift back into prose —
> and that test earned its keep two days later: widening the region to all eight departements took
> it to **19 of 113**, across eleven communes, and the assertion is what made someone write the new
> number down rather than discover it in production.
>
> Every apparent tenure hit on the source is false and they come from **UI furniture**, not prose —
> `PLAI` inside the facet `Plain-pied`, `LLI` inside `Ce·lli·er`, `PLUS` inside `plusieurs`, `SNE`
> inside `SURESNES`. All were run through `TenureClassifier`: every one returns `UNKNOWN`/`DIGEST` at
> 0.00, so the guards hold and **nothing needed fixing**. Two are now captured corpus cases, because
> a filter LABEL is not something careful listing copy can ever remove.
>
> Three rules came out of this round, all recorded in `docs/SOURCES.md` and `/add-source`. The A12 one is
> above; the other two: **a client-rendered page is not a dead
> end** — follow the widget's `script src` to its bundle, the bundle to its API host, then check that
> host; and **an unreadable `robots.txt` has two verdicts** — RFC 9309 makes 5xx *unreachable* (MUST
> assume complete disallow, stricter than this repo's own posture) and 4xx *unavailable* (MAY access,
> so a 403 is blocked by this repo's posture, not by the standard). Record which one applies, with
> the date: a 500 can be transient.

**SOURCE #2 IS LIVE, AND IT IS THE FIRST MIXED-TENURE ONE (2026-08-20).** CDC Habitat is
`enabled: true`; `scout --domain=rent doctor --source=cdc_habitat` returns **139 annonces, ~21 s**. In'li is pure
LLI, so until now *nothing exercised §1 against a real payload* — CDC ships `Logement intermédiaire`
and `Logement à loyer libre` badges in one result set and social stock in others. Three things about
it are worth knowing before touching a source again:

- **`robots.txt` decided the whole shape of the config.** CDC disallows `/Recherche/show/`,
  `/Recherche/search` *and seven search QUERY PARAMETERS by name*, so the parameterised search is
  refused outright. What its own `sitemap.xml` advertises is the lowercase, query-free
  `/recherche/location/<region>` tree — and robots path matching is case-sensitive, so `/Recherche/…`
  does not cover `/recherche/…`. That is why pagination here is `page_path` (a PATH segment,
  `/page-2`) and not `page_param`: appending `?page=2` would query a space the site asked robots to
  stay out of. Because the path changes per page, **robots is re-checked for every page**, and CDC's
  `robots.txt` is frozen at `tests/fixtures/rent/cdc_habitat/robots.txt` and asserted per page by test.
- **The walk now stops at the count the site declares**, rather than probing one page past the end.
  CDC's out-of-range page answers `301`, not empty, and the adapter refuses a non-2xx on purpose —
  a redirect landing back on page one ends a walk exactly like a genuine last page.
- **`fields['_text']` is prose and goes through `RawListing::text()`.** It used to be scanned as a
  structured field, where financing acronyms match case-insensitively; CDC's own tooltip defining
  *logement intermédiaire* contains *"au plus près"*, which was read as the excluded acronym `PLUS`
  and vetoed the card's own tier-1 badge. 14 of 16 correctly-badged listings were going to the
  digest. `plus` is one of the commonest words in French — In'li escaped this by luck.

Two smaller things landed with it, both hard rule 9: `Payload::floor()` reads floors as the prose
they are (`RDC` is **0**, not unknown; and the generic number reader would return the ROOM COUNT from
`3 pièces - 4ème étage - 82m²`), and `Payload::bool()` accepts the amenity noun `ascenseur`, which
can only ever yield `true` or `null` and so cannot manufacture the explicit `false` the high-floor
penalty needs. **`tests/fixtures/rent/tenure/corpus.json` now has CAPTURED cases** (154
total, 146 synthetic + 8 captured): two CDC cards — including the `au plus près` one, which is what
stops that classifier fix from being quietly undone — two Cityloger detail pages, and two Logirep
captures added 2026-08-22, one an ordinary card that states no tenure at all and one the site's own
FILTER FACET STRIP, which contains `PLAI` inside `Plain-pied`, `LLI` inside `Ce·lli·er` and `PLUS`
inside `plusieurs`. That last one is the furniture failure class in a shape listing copy cannot
avoid: a UI label, not prose.

**ICF Habitat Novedis (A2) is NOT pollable** and was dropped: measured three levels deep, its site
publishes a directory of *résidences* with zero rents, zero surfaces and zero occurrences of
`disponib`. It was ranked second on portfolio value, not on a verified feed — a `200` had been read
as a feed. See `docs/SOURCES.md`, whose A2 and A3 rows were both corrected.

### The push gate — a match under the line waits for the rollup (A5, 2026-09-05)

**Nothing in the score gated DELIVERY until now**: every MATCH was pushed the minute it was seen,
and with region mode yielding ~80 matches the phone was the digest. Measured before the ruling,
over all 1 046 stored MATCH snapshots re-judged offline under production's shape (commute
30 / 75 min from the 411 cached communes): p10 23 · p50 40 · p90 54 · max 69 — commute is the
dominant component at 21/30 mean, rent headroom is near-dead at 1.9/15 because rents cluster at
the ceiling, and the commune's 25 points are earned by 15 listings of 1 046. Rebalancing was
offered and **declined** (developer ruling: *keep the weights, push individually at ≥ 55*).

- **`notify.push_min_score` is a SEPARATE knob from `high_priority_score`.** The marker is *score
  AND confidence ≥ 80* and says how sure the classifier is; the gate says whether the phone
  buzzes now or tomorrow morning. Rent 55 (the p90, one match in ten individually); car 73, the
  marker's own calibrated bar (one in four). Omitting the key pushes everything, as before.
- **The rent queue drains through the EXISTING digest** — `Cli/DigestBatch` grew a second list,
  and **TWO** drains empty it: `scout --domain=rent digest` and the daily floor, which runs under
  `--watch` only. **The end-of-pass emission is NOT one of them** — `Pipeline` calls
  `formatter->digest($batch)` with one argument and merely COUNTS what it queued, so a cron-driven
  `--once` deployment announces nothing from that queue until the verb is scheduled. This paragraph
  said *"digest, the end-of-pass emission and the daily floor"* for a day, in both this file and the
  README, and a review lens caught it; a documented drain that does not exist is how an operator
  concludes the queue is empty. Both real drains announce it under its own
  heading, *« vérifié, score bas »*, never mixed with the tenure doubts. That is the §1 half:
  a settled LLI must not be announced under *« au régime indéterminé »*, so the title carries the
  regime clause only when a tenure doubt is in the batch, and a rollup-only mail says
  *« Vérifié, score bas : N annonce(s) »*. The drain RE-SCORES a queued row from its v7 snapshot
  with the STORED classification, never re-forming a verdict (the `reclassify` rule), and a row the
  current criteria reject is left waiting with a warning rather than announced.
- **§1 IS JUDGED FROM EVERY PERSISTED READING, AND THE DRAIN IS THE THIRD SURFACE** (C2 round 7,
  correctness P0 — the most serious finding of that round). `pendingLowScore()` selects the row's
  OWN `tenure` column and nothing else, so the drain judged §1 from it alone while `Pipeline` (the
  push path) and `reclassify` both refuse on the PERSISTED twin and group readings. A flat whose
  twin on the other track was judged `PLS`, or whose cluster holds a `PLS` sibling, was therefore
  pushed as an individual `MATCH` and marked `MATCH` — which cannot be demoted, so the row left the
  queue for ever. **That is exactly the state schema v12 persists the veto for**, described in this
  file already: *a pass seeing the agency copy alone pushed the PLS flat*. The two reads now sit in
  `collectDigest()` ABOVE the retry/rollup split, because both arms announce and a guard inside
  `pushRetries()` would be *a fix landing on one of two symmetric surfaces* committed inside the fix
  for it. The UNDETERMINED twin is refused as well, exactly as `reclassify` refuses to PROMOTE on
  one: a doubt on the other track is not something this command may announce away, and it has no
  route to re-file the row as a doubt.
- **A COMPUTED VERDICT IS AN ANSWER, AND THE CAR DRAIN THREW ONE AWAY** (C2 round 7, P0 on two
  lenses). Round 6 added a re-judge to `CarScout::collectRollup()` and then discarded a `REJECT`:
  `$verdict` stayed `null`, the score fell back to the STORED one, and the row was announced —
  individually when that score cleared the gate — carrying *« réémission — score conservé,
  instantané absent »*, which was false, the snapshot having decoded cleanly. So a car today's
  classifier calls `accidenté` (the non-overridable excluded vehicle set) was pushed. Reachable with
  no forged state: the excluded set was WIDENED in code on 2026-08-31 and `max_price_eur` /
  `brand_avoid` have both changed since, each flipping previously-MATCH rows. The rent drain refused
  the identical case 400 lines away in the same commit.
- **COLLAPSE THE TWINS BEFORE THE SPLIT, NOT INSIDE EACH LIST** (C2 round 7). Collapsing per list
  left the case where twins STRADDLE the gate: one entry in each list, `collapseTwins()` returns at
  `count < 2`, and the flat goes out twice in one drain — an individual push AND a rollup line,
  neither naming the other route, with the agency copy taking the headline whenever it scores
  higher. Measured both ways round. The scope is the QUEUE only: a twin pair in the *à vérifier* bin
  is still announced twice, which is noise rather than a §1 fault, because merging two doubts would
  decide which `DigestCause` the survivor keeps.
- **A REMAINDER LINE MUST STAY SILENT WHEN THERE IS NO REMAINDER** (C2 round 7, P1 on two lenses).
  `overflow()` counted `waitingLowScore` — every queued row, retries included — against the rollup
  list alone, so every retry was reported as still pending: a phantom backlog on every drain, and on
  a no-gate deployment that is every drain there can be. Both domains. Every existing assertion and
  the ledger case proved the line FIRES when there IS a remainder; **none proved it silent**, which
  is this repo's named missing-counterweight shape.
- **A QUEUED ROW IS NOT PROOF OF A LOW SCORE, and reading it as one loses a push for ever** (C2
  round 6). The queue is *matched, and nobody was told* — exactly what a FAILED push leaves behind,
  and on a deployment with no gate configured it is the only thing a queued row can be. So each
  drain re-scores and splits: at or over the line, or with no gate at all, the row is pushed as the
  individual match it is and marked `MATCH`; only a row that really fell short is rolled up. A
  refused retry is left untouched for the next drain. `pushRetries()` is ONE implementation per
  domain called from both the verb and the floor, and the ledger carries a case per call site — a
  fix landing on one of two symmetric surfaces is this repo's named recurring defect.
- **Cross-track twins are collapsed AT THE DRAIN, not only at the push.** The pipeline's twin cover
  fires on a copy that was pushed, so two below-gate copies of one flat — a direct route and an
  agency copy — were both queued and both announced in the same mail. The drain collapses them
  (direct route survives, the other named beneath it with its link) and marks EVERY key of the
  pair, in whichever order the passes queued them. A `sources.json` the drain cannot read is
  VOICED and the entries pass through uncollapsed: the digest is §1's only landing zone, so a
  duplicate announcement is the acceptable cost and a bin that never empties is not.
- **The RENT store records WHAT a row was announced as, and the ordering is monotone**:
  `DIGEST (1) < ROLLUP (2) < MATCH (3)`. **The car store has no kind column at all** —
  `VehicleStore::markNotified()` writes `notified_at` and nothing else — so every sentence in this
  block about `notified_as` scopes to rent; the car domain's rollup is distinguished by the
  notification KIND it is sent under, not by anything persisted (C2 round 7, completeness P3). A rent drop that lifts a rolled-up flat over the line is
  a promotion and is pushed once; a pushed flat is never demoted to a rollup; a `DIGEST` write
  over a `ROLLUP` keeps `ROLLUP`. Schema untouched — `notified_as` already existed (v8).
- **The car half is a ROLLUP, deliberately not a digest**: the car domain has no tenure doubt, so
  `scout --domain=car rollup [--dry-run]` and a daily floor at `notify.rollup_hour` (marker
  `state/car-rollup.txt`, written after the channel confirms — a refused send marks nothing and
  leaves the window open) drain `VehicleStore::pendingRollup()`. `NotificationKind::ROLLUP` is the
  third kind. The floor reuses `Rent\Core\DigestSchedule` unchanged.

> **The startup floor was built with `$criteria` out of scope and every fixture stayed green
> until the one test that reaches it ran**: `CarScout::watch()` takes the pipeline, sources,
> store and notifier as parameters and never the criteria, so the floor block copied from
> `runCommand()` read an undefined variable — three PHP warnings and a `null` schedule that
> silently never fired. It is the *"a fix landing on one of two symmetric surfaces"* shape at the
> scale of a method: the verb worked, the floor did not, and only a test asserting the floor's own
> emission told them apart.

### The email-alert path — four defects, all found by one real message (2026-08-25)

**`EmailAlertSource`, `EmailMessage`, `FileMailbox` and `ImapMailbox` were written BLIND**, and the
class docblock said so: *"No real portal alert has been seen yet… It is built to be SHAPED by a real
message."* Two SeLoger alerts arrived on 2026-08-25 and running them through the parser returned
**`body len: 0`, `links: 0`** — zero listings, no exception, a source that would look like a quiet
market for ever. Hard rule 3's exact shape, reached without a single `catch`, behind 1886 green
tests.

Four defects, and the ordering of them is the lesson: none was findable without a real payload, and
each hid the next.

1. **An empty MIME part claimed the answer.** RFC 2046 puts a *preamble* before the first boundary
   and nearly every real mailer writes one; read as a part it has no `Content-Type`, defaults to
   `text/plain`, splits to an empty body, and runs `$plain ??= ''` — which `??=` never overwrites.
   Three instances, all fixed as *an empty string is not an answer*, plus the structural half: index
   0 is never a part. The committed `email_demo` fixtures happen to have no preamble.
2. **The RFC 2047 whitespace collapse ran after the decode**, where no `?= =?` sequence survives to
   match — dead from the line it was written on. A folded French subject decoded to
   `exclusivit és`, and the subject becomes a listing's `title`.
3. **A rent could be assembled across a line break.** `\s` in the thousands-separator class matches
   `\n`, and `Payload::int` truncates there, so the rent became whatever sat on the line above:
   `ref 850` over `1 450 EUR charges comprises` extracts **850** — inside the plausibility band, six
   hundred euros low, clearing a ceiling the real rent does not. The separator is `\h` now.
4. **HTML entities reached the classifier undecoded, and that one is §1.** A portal's `text/plain`
   alternative is generated from its HTML, and SeLoger's does not decode entities on the way.
   `Text::fold()` refuses such text outright, naming whose job it is: *an entity inside a label
   deletes that label while leaving others intact*. `logement conventionn&eacute;` folds to
   `logement conventionn` — the label destroyed, the listing apparently unlabelled. Decoding is the
   safe direction and can only ever restore a label: no entity expands to `PLAI` or `PLUS`.

**SeLoger sends no listing URL and no listing id**, which is the design problem the source poses.
Every link is `click.by.seloger.com/?qs=<opaque per-recipient token>`; strip the query, as
link-identity does, and all sixteen links in one alert collapse to a single id — sixteen cards, one
identity, each carrying the FIRST rent and FIRST surface in the whole message. Hence
`params.card_separator`, which cuts the body on the card's terminal CTA, and `params.id_from:
content`, which keys on the dwelling's structural facts.

Three rules travel with that identity, each closing what another opens:

- **The rent is deliberately not in the key.** A price drop is an event this project exists to
  detect; in the key it becomes a brand-new listing with no history and no *en baisse* reason.
- **A no-information floor.** Without it every card whose extraction failed hashes to
  `sha1('seloger|||||')` and they all collapse onto that one id — the store's own *"nothing
  collapses onto a shared key"* guarantee violated one layer up, where the store cannot see it.
  Refusing costs nothing: a listing with no location can never match anyway (Q32).
- **Duplicate ids WITHIN one message keep ONE card, drop the rest, and ANNOUNCE the drop**; across
  messages they are the legitimate re-send content-addressing exists to recognise. Scope is still
  the whole distinction. **This was a `SourceError` until 2026-08-26, and what changed it is worth
  reading before changing it back.** A `Baisse de prix` digest carried three coliving ROOMS in one
  flat at Gros Saule, Aulnay-sous-Bois, each advertised with the WHOLE flat's `6 pièces . 83,99 m²`:
  commune, postcode, rooms, surface and residence genuinely identical, only the OLD price differing
  — and the rent is deliberately not in the key. **`seloger` returned zero listings for seven
  consecutive passes**, over three rooms `exclude_title_patterns` rejects anyway, while the thrown
  message asserted *les champs qui les distinguent n'ont pas été lus* — which was **false**: they
  were read correctly. Two indistinguishable units in one residence is the STATED cost of
  content-addressing arriving, an EVENT, and a throw is for a STATE. Same taxonomy, same mistake and
  same fix as detail hydration. **The silence is NOT relaxed** — the drop is warned on every pass
  that sees it, and `tests/sabotage-check.sh` carries that half as its own case, because a silent
  drop is the regression this change could otherwise become.

**The redirect is never followed at ingest** — one third-party request per listing, on a token tied
to the subscriber, manufacturing an engagement signal from a click nobody made. Hard rule 5's
*identify honestly* one step out. The unresolved link goes in the notification, where a human clicks
it. **Stated cost:** the link expires with the email, and a listing that later gains a
previously-missing surface changes identity once and so notifies once more.

`card_separator` with `mixed_tenure: true` is **refused at load** rather than answered with a
batch-veto mechanism: segmenting removes a regime stated once for a whole digest from every card,
which on a mixed source is a §1 decision nobody has made against a real payload. Outside the
`enabled` branch, because `--source=` force-runs disabled sources.

The corpus gained its **first captured case from an email**, and it is the `plus` class a fourth
time: SeLoger's own CTA button reads **`En savoir plus →`**. Unlike CDC's tooltip or Cityloger's
prose this belongs to the portal's template rather than to anyone's listing copy, so no careful
writing can ever remove it. A first version of the fixture test asserted the word `plus` was absent
from a card — unachievable, and wrong in kind: the guarantee is the classifier's verdict, not the
absence of a common French adverb.

### A title is a position, never a vocabulary (2026-08-26)

**`exclude_title_patterns` was unreachable on 37.5% of SeLoger's cards for a month, and every
document here said the opposite.** `title_pattern` required the line to begin with
`Appartement|Maison|Studio|Duplex|Loft|Chambre` — a guess at what an ESTATE AGENT types — and on
**27 of 72 live cards** it missed, at which point the title silently became the message SUBJECT.
The stored title of a real flat in Moret-Loing-et-Orvanne read `2 nouvelles annonces : Ile-de-France`.

Nothing about that reads as a fault: it is a plausible French sentence in a plausible field, and it
survived a fixture suite, a live acceptance run and a review round. **It was found by querying the
production database, not by any test** — `SELECT title, COUNT(*) FROM listings GROUP BY title` on
`state/rent-watch.sqlite3`, which is worth running after any source goes live.

What it cost is precise, and narrower than the first draft of this entry claimed.
`Criteria::excludedBy()` has two lists: `exclude_patterns` matches title **and description**, and
`exclude_title_patterns` matches the TITLE ONLY — deliberately, because `3 chambres` in a
description is the family flat the criteria are looking for. So `colocation`, `coloc` and the
meublé family kept firing through the description all along; what went unreachable is
**`^\s*chambre\b` and the parking/box/garage/cave/cellier/bureau/terrain family**, which have no
second surface. `^\s*chambre\b` is the exclusion added *because* four of this source's first nine
matches were coliving ROOMS passing every numeric filter.

> **A first version of the test asserted the Saint-Denis COLOCATION was now rejected, and it passed
> before the fix as well as after.** `\bcolocation\b` was catching it via the description the whole
> time. A true observation attached to the wrong mechanism — this repo's named failure, arriving
> while writing the fix for an instance of it. The test that replaced it holds the description
> constant and varies only the title.

Three things carry forward:

- **The replacement is STRUCTURAL**, and it is the correction `commune_pattern` already took on this
  same source: anchor on the layout the portal emits, not on the words someone chose. SeLoger writes
  `<rent> €/mois`, then the agency's free text, then `<n> pièces . <s> m²`; the title is the line
  above the `pièces` line. Measured over the same 72 cards: **27 rescued, 45 identical, 0 lost, 0
  fallbacks left.** The capture floor is **2 characters**, not 3, because `T5` and `T3` are real
  titles; `APARTMENT` is real too, and no French vocabulary list would ever have held it.
- **A configured pattern that misses now yields `''`, never the subject** — and a source configuring
  NO pattern keeps subject semantics, because there the subject IS the answer rather than a
  substitute for one. `''` restores no filtering on its own; what it buys is that the failure is
  VISIBLE instead of wearing an alibi. Hard rule 9 one layer up: an extraction failure is not a value.
- **THE OBVIOUS SABOTAGE DOES NOT DETECT THAT.** With the positional pattern in place every frozen
  card extracts, so the fallback branch is never entered and all six fixture suites stay green while
  the safety is deleted. `EmailAlertSegmentationTest` enters it on purpose with a pattern that cannot
  match. Both halves are in `tests/sabotage-check.sh`. **A guarantee whose branch no fixture reaches
  is dead safety code until something reaches it.**

`\bcoliving\b` joined `exclude_patterns` here (a live card read
`Menilmontant 287 -Premium Coliving House Paris 20`). The meublé patterns were deliberately NOT
widened — `MEUBLE - RUE WAGRAM` and `Beau 3P MEUBLÉ 59m²` both escape
`\b(?:location|louer|loue|appartement|logement|studio|bien|t[1-9])\s+meuble`, and widening it needs
the negation shapes checked first (`non meublé`), which is the lift-negation lesson.

> **A POSITION NEEDS A LANDMARK, AND ONE LANDMARK IS NOT ENOUGH (F24, 2026-09-01).** The positional
> anchor above is the `pièces` line — so a card that never states a room count had **no anchor at
> all**, the title came back `''`, and every `exclude_title_patterns` entry was inert on it. That is
> not cosmetic: `\bcolocation\b` lives in `exclude_patterns` and fires through the DESCRIPTION, but
> the anchored `chambre` rule and the parking/box/garage/cave family match the TITLE ONLY — on
> purpose, because `3 chambres` in a description is the family flat the criteria want. So they had
> no second surface, and nothing matches an empty string.
>
> **Both victims are real, and the store had the evidence all along.** Schema v7 keeps the card text,
> so no Gmail capture was needed to see the shape: `750 €/mois charges comprises` · the title
> `chambre a louer dans une maison` · `140 m²`, and no `pièces` line anywhere. A single room quoting
> the whole house's surface — **pushed as a MATCH on 2026-08-27**. The second, `Chambre colocation
> evry village`, was rejected only because its text happens to say `colocation`. Luck on both counts.
>
> The repair is a **second anchor on the surface line**, and the trial is the part to copy: run the
> candidate over every stored card of that source before shipping it — **617 unchanged, 2 gained, 0
> changed, 0 lost** across all 619, the two gained being exactly the victims. `m2` is accepted beside
> `m²` because 128 stored SeLoger titles write the ASCII form; a `pièces` line still wins on a card
> carrying both, because SeLoger lays them on ONE line and the anchor is line-initial.
>
> **A first version of the test asserted the wrong thing** — the same trap the entry above records.
> Recovering a title is worth nothing unless the exclusion then fires, so the test asserts the
> REJECTION through the shipped criteria, with the description held empty so `colocation` cannot
> deliver the verdict the title is supposed to.

### An extraction that fails is only visible where something counts it (F27, 2026-09-01)

`PatternMissLog` is the signal built after PAP ran four days with both positional patterns dead.
**It shipped on one adapter of five**, and the Track 5b matrix measured what that cost: `HtmlSource`,
`JsonSource`, `DetailHydrator` and `VehicleEmailSource` counted nothing, so a silently-null CSS
selector or JSON path was exactly as invisible as the missed regex had been. *A fix landing on one
of two symmetric surfaces* is this repo's named recurring defect, and it was committed **by the fix
for the finding that names it**.

The car half is closed. Three things about it are worth knowing before touching either domain:

- **The class lives in `Scout\Core` now**, with `Scout\Core\CountsPatternMisses` as the READ side.
  Every domain CLI (rent, car, job) gates its miss report on the interface, never on `instanceof EmailAlertSource` — that
  class check is *why* the report existed on one adapter, and it would have had to be remembered
  again for every adapter that learned to count.
- **The car adapter cannot have one funnel, so its guard is SET MEMBERSHIP instead.** Its four
  patterns need four shapes (`PREG_OFFSET_CAPTURE` to locate the price line, `PREG_SET_ORDER` for
  the facts' named groups, the SUBJECT for the title, whichever haystack `make_model_source` names),
  and forcing them into one signature would distort four readers to spare one line each. So
  `VehicleEmailPatternMissTest` reads `VehicleSourceLoader::PATTERN_PARAMS` **by reflection**,
  subtracts `UNREAD_PARAMS` and the message-level `subject_pattern`, and asserts every remaining key
  is counted. A fifth pattern added and instrumented by nobody fails there rather than going dark.
- **`subject_pattern` is deliberately never counted**, and that exclusion is asserted too: a subject
  filter rejecting a message is the filter working, and this mailbox carries five portals plus
  everything else — counting those attempts would dilute the ratio into permanent silence with the
  very mail the filter exists to ignore. Staging (`begin()`/`resolve()`) is mandatory on the car loop
  for the same reason: it has a documented furniture segment, so counting it adds one permanent miss
  per message and the WARN only ever fires at 100%.

**The html/json half CLOSED on 2026-09-02** (`070964b` + `2ab3245`): `ListingMapper` — the one funnel
every html, json and detail extraction passes through — is instrumented, and `HtmlSource`,
`HttpJsonSource` and `FixtureSource` implement `CountsPatternMisses`. `SitemapVehicleSource` was the
last surface counting nothing and joined them the same day, through its own single `$field` funnel.
**Only a CONFIGURED key can miss**, on every adapter: counting an unmapped one reports a permanent
100 % on fields nobody asked for, and since `total()` speaks only at 100 % the signal would be pure
furniture on any source mapping a subset.

> **COUNTING IS NOT REPORTING, and for a month four adapters of five did the first and not the
> second** (C2 round-1 resilience lens + completeness lens, 2026-09-02). Their `health()` was a
> one-line delegation to the store that never read `total()`, so under `run --watch` — the deployed
> mode — a field map going 100 % null produced **no status change, no `isAlerting()`, no alert**,
> only a `doctor` printout. Hard rule 2's own shape: an alert computed and never sent is worse than
> none, because someone believes the green. It was not theoretical — In'li's card `cp` went 171/171
> dead on the deployed image while `HtmlSource::health()` returned `ok`, and a human found it running
> `doctor` after a redeploy. The repair then left In'li's postcode resting on ONE selector, and in
> region mode `postcode_prefixes` IS the location filter: if that selector dies the source keeps
> returning ~171 listings, `item_count` does not move, no run fails, and In'li matches zero flats for
> ever while reporting `ok`.
>
> **The escalation is now ONE implementation** — `PatternMissLog::escalate()` — rather than the two
> verbatim inline copies it had become. Four more copies is precisely how the sixth adapter forgets,
> so the guard is structural: `PatternMissEscalationTest` discovers every `CountsPatternMisses`
> implementor **by reflection** and fails when one does not route through it. **And `reset()` is now
> called at the top of every `fetch()`**, which is the same finding's other half: the CLI builds its
> sources ONCE and the watch loop closes over them, so a log that accumulates makes a template
> already fixed warn for ever — worse than silence, because it is credible.

**What this still does NOT cover:** cityloger's 9 null surfaces of 60 are a **16 %** miss rate, and
`total()` speaks only at 100 %, so the signal is SILENT on them by design. That question was settled
by one live fetch instead — the page states `65 m2` on line 221 while the selector scopes to
`div.tab-content`, which opens on line 268: a SCOPE miss, tracked separately. Partial-variant
detection remains uncovered.

### Bien'ici — source #6, and it disagrees with SeLoger on almost every decision (2026-08-25)

Three real alerts landed within ninety minutes of the subscription being created, and the source was
live the same evening: `scout --domain=rent doctor --source=bienici` returns **13 annonces, `ok`, 731 ms**, and a
seeded pass matches **10 of 13** — the best hit rate in the tree by a wide margin, because the
portal applies the saved search's own criteria before sending and those criteria mirror
`criteria.json`. Prove a change offline with
`RENT_SCOUT_DB=$(mktemp -u) MAILBOX_DIR=tests/fixtures/rent/bienici scout --domain=rent doctor --source=bienici`. Four things carry forward:

- **IT PUBLISHES A REAL LISTING ID, so identity is the LINK.** `/annonce/laforet-immo-facile-
  22588736` survives `stableId()` stripping the query. Content-addressing was invented for SeLoger,
  which sends sixteen cards behind one opaque redirect; that is a property of THAT portal, not of
  email alerts, and a real id has neither of the content key's stated costs. `cardListing()` gained
  a link-identity path for the segmented case; `id_from: content` still short-circuits, so SeLoger
  is byte-identical. **Pick the scheme BEFORE the source is first enabled** — nothing migrates a
  stored row from one key to another, so switching later re-notifies the whole backlog. *Ship
  config-only today and improve it later* is the trap here, not the safe option.
- **THE CARD SEPARATOR WAS MEASURED, AND COPYING SELOGER'S WOULD HAVE BEEN WRONG.** Splitting on the
  terminal call to action puts the alert's own criteria line — `1 200 € max - 3 pièces min - 45 m²
  min` — inside segment 0, and starts every later segment with the PRECEDING card's `RÉFÉRENCE :
  Cocon_Loc_T4`, whose `T4` the room reader takes. Over four live messages that is **3 of 13
  surfaces reading 45 and 1 of 13 room counts reading 4**, all under-reported, so `min_surface_m2`
  rejects a real match and nothing says why. `\nPhoto\n` — the line each card STARTS with — makes
  every segment exactly one card and leaves the header a segment of its own, which then drops for
  having no listing link. The four wrong values are asserted against the frozen payloads, so the
  separator cannot be "tidied" back to symmetry.
- **The no-information floor now guards the CARD, not the content key**, and that was found by
  regression rather than by design. Its first argument is identity collapse, to which link identity
  is immune — so a floor living in the content path alone stops applying the moment a portal
  publishes a real id. Its second argument does not depend on the key at all: a segment yielding a
  rent and nothing else is an extraction failure, and admitting it hides that failure behind a row
  quietly rejected for having no location. Retargeting the sabotage case then exposed a real hole —
  no test exercised *describes a flat but does not locate it*, so half the floor could be deleted in
  silence.
- **`params.from` is refused at load for an enabled `email_alert`**, the promise recorded as due
  *"with the next portal"*. One mailbox serves every portal, so it is the source's scope and what
  scopes the IMAP `SEARCH … FROM`. The blanket *"segmented needs `id_from: content`"* rule is
  replaced by the narrower one that survives: a segmented source keyed on its links must name
  `link_host`, because two cards ending on the same stray link is caught loudly at fetch while two
  cards ending on DIFFERENT rotating advert links is caught by nothing.

> **"THE ADDRESS IS ABSENT" IS THE WRONG TEST, AND `tools/scrub-eml.php` had been running it.** Every
> Bien'ici link carries `signedRecipient=eyJ…`, a JWT whose payload base64url-decodes to
> `{"email":"<the subscriber>"}`. Measured on a real capture: the literal address is absent from the
> decoded body, and one `base64 -d` recovers it in full — so the scrubber verified a true absence
> and wrote the file, reporting `scrubbed`. The right test is **not RECOVERABLE**: it now decodes
> every long base64url run and the quoted-printable form before it looks, and refuses when the
> address surfaces in any of them. Stated generally rather than as a Bien'ici special case, because
> the next portal's encoding is unknown. **The first fix still stripped nothing from all three real
> captures**: the tokens are quoted-printable, so a JWT reads `=3DeyJ…` and a `\b` anchor sees the
> `D` of `=3D` and refuses to start — the unit test passed on an unencoded fixture while the tool
> failed on every real one. `tests/test-scrub-eml.sh` now carries the QP case.
>
> `tests/php/Repo/FixtureSecretsTest.php` **was supposed to be the second line of defence and was not
> one until 2026-08-30**: its JWT pattern began with `\b`, and in a quoted-printable body a token reads
> `=3DeyJ…` — `D`→`e` is no boundary — so it never matched the committed Bien'ici tokens, plain OR
> placeholder. A review panel proved a live-shaped JWT REFUSED plain and PASSED in QP. It now decodes
> QP before it looks (the scrubber's own lesson) and carries a self-test; a ledger case removes the
> decode. It still would not catch the scrubber reporting success, which is the half that matters: a
> tool nobody doubts is a tool nobody checks.

### leboncoin — source #7, and the first HTML-only alert (2026-08-26)

Its first alert ever fired at 07:33 Paris and the source was live the same morning:
`scout --domain=rent doctor --source=leboncoin` returns **3 annonces, `ok`, 864 ms**, and a seeded pass matches
**1 of 3** (Combs-la-Ville, 59,9 m², 935 €; the other two rejected at 48 m² and 45 m² against the
50 m² floor). Prove a change offline with
`RENT_SCOUT_DB=$(mktemp -u) MAILBOX_DIR=tests/fixtures/rent/leboncoin scout --domain=rent doctor --source=leboncoin`.

**IT NEEDED A PARSER CHANGE, not config alone — and the failure it would otherwise have produced is
this project's defining one.** leboncoin sends **no `text/plain` alternative**, the first portal to
do so. `stripHtml()` removes tags, and every URL lived in an `href` that went with them: the parser
produced a perfect 15 975-character body carrying all three listings and **zero links**. A source
with no links yields no listings and reports a quiet market for ever, while `doctor` says `ok` —
hard rule 2's exact shape, reached without a single `catch`. `EmailMessage::harvestHrefs()` moves
each anchor's URL into the body text.

- **Into the BODY, not just the side `links` array**, and that decides the whole design:
  `cardListing()` associates a link with a card by scanning **that segment's** text, so a URL known
  only at message level could never be attached to the card it belongs to. It is emitted *after* the
  anchor text so reading order matches the rendered one — a reasoned default rather than a measured
  one, because this payload cannot distinguish the two orders (each card links twice) and the
  sabotage case that claimed otherwise was **retired** rather than left green.
- **Only the HTML path.** A message with a plain alternative is untouched, which matters more than
  the feature: Bien'ici's identity IS its links, so a changed link set would re-key the whole stored
  backlog and re-notify every flat already seen. Asserted by the existing fixture tests passing
  unchanged.

**THE SEPARATOR IS THE CTA HERE — the opposite of Bien'ici — and the first attempt failed.**
`"\nVoir l'annonce\n"` matched nothing, because the CTA sits inside a run of spaces rather than
alone on its line, and the whole message parsed as ONE card: card 3's URL with card 1's rent,
commune and surface. One plausible-looking listing, and nothing about it reads as a fault. The
literal `"Voir l'annonce"` gives every segment exactly one card's data and its own trailing link.
Every value is asserted against hand-read ground truth, so it cannot drift back.

**Identity is the LINK** — `/vi/3256902167.htm` is a real ad id, and the tracking lives in a
`#fragment` that `stableId()` drops along with the query. Chosen before the first enabled pass,
because nothing migrates a stored row between key schemes.

**Rent is `hors charges` by decision, not omission**: the alert mentions charges **nowhere**
(measured — zero occurrences of `charges`, `CC` or `HC`), so the Logirep precedent applies. The
figure lands in `rentHc`, `max_rent_cc` never fires on it, and the score line says so. **Stated
cost: the rent ceiling is not checkable for this source.**

> **URLS ARE CLASSIFIED TEXT NOW, and that is the fifth instance of one failure class.** Harvesting
> hrefs into the body feeds every tracking parameter to the tenure scan. Measured on a campaign
> string carrying `plus`, `lli` and `plai`: two explicit label signals fired and conflicted a
> correct verdict into the digest. leboncoin's real campaign string contains none of them — luck,
> not a guard, and unlike the CDC tooltip or the SeLoger CTA, nobody can rewrite a portal's
> analytics parameters. `RawListing::text()` now strips a URL's **query and fragment** and **keeps
> its path**, and that split is §1: measured, `plai` as a path SEGMENT classifies PLAI/REJECT while
> the same acronym in `?c=plai_plus` classifies nothing, so blanking the whole URL would lose a
> social signal to save a campaign string. Both halves are corpus cases (`url-001`, `url-002`).

> **n=1.** One message, three cards. The separator and `commune_pattern` are measured on that single
> capture, and this repo has twice paid for generalising from one. The second alert to arrive is the
> first regression test.

### PAP — source #8, and the numeric twin of the title lesson (2026-08-26)

`scout --domain=rent doctor --source=pap` returns **2 annonces, `ok`, 483 ms**. Prove a change offline with
`RENT_SCOUT_DB=$(mktemp -u) MAILBOX_DIR=tests/fixtures/rent/pap scout --domain=rent doctor --source=pap`. It is the first **direct-from-owner**
portal, so its inventory does not overlap the agency portals every other private source draws from,
and structurally the simplest alert in the tree: a real `text/plain` part, **one listing per
message** (no `card_separator` at all), and a real ad id — `/annonces/-r458301723` — so identity is
the link.

**THE ALERT QUOTES THE SUBSCRIBER'S OWN SEARCH CRITERIA ABOVE THE LISTING, and every generic reader
is a first-match-wins `preg_match`.** Measured on the real capture before any config existed: the
surface read **45** — *"à partir de 45 m²"*, the search FLOOR — instead of the flat's 50. 45 is below
`min_surface_m2`, so **the first PAP alert ever sent would have been rejected for being too small**,
silently, with nothing reading as a fault. That is Bien'ici's defect a second time, **down to the
same number 45**, because the same saved search is used on every portal. Rooms read 3 and were right
*by coincidence* — the criteria line also says 3. Rent survived only because the periodic-figure rule
from the SeLoger price-drop fix outranks a bare `1.200 EUR`. The commune came back `null`, the
SeLoger regression exactly: Milly-la-Forêt is not a RANKED commune, so the vocabulary scan is blind.

The fix is four **POSITIONAL** anchors keyed on the `(NNNNN)` postcode line — the one landmark the
template guarantees — and deliberately not on vocabulary. **This is the numeric twin of *a title is a
position, never a vocabulary*.** Two new per-source params carry it, `surface_pattern` and
`rooms_pattern`, compile-checked beside the other three; **a configured pattern that MISSES yields
`null`, never the generic scan**, because falling back restores the defect *and* gives it an alibi —
the row reads as a small flat rather than as a broken extraction. An unconfigured source keeps the
scan bit-for-bit, and that counterweight is asserted, since without it the guarantee is satisfied by
deleting the feature.

- **`link_host` CARRIES THE PATH here**, as at leboncoin. The message has two links and **both are on
  `www.pap.fr`**: the annonce, and the unsubscribe page at `/utilisateur/alertes`, whose wording
  matches **none** of the noise words `looksLikeAListing()` rejects. A non-segmented source builds one
  listing **per accepted link**, so a host-only value yielded a phantom second listing carrying the
  real flat's rent, commune and surface under its own identity — notified as a separate flat, and
  never delisted, because an unsubscribe page never goes away.
- **`title_pattern` was INERT on every non-segmented source.** `listingsIn()` hardcoded the subject;
  only the segmented path consulted `cardTitle()`. A configured pattern doing nothing — and on this
  source it makes `exclude_title_patterns` unreachable, the In'li/SeLoger lesson a third time.
- **No fixture reaches the miss branch**, so `EmailAlertSegmentationTest` enters it on purpose. That
  is the dead-safety-code trap the SeLoger title walked into hours earlier, avoided by having already
  been paid for once.

**Stated cost: the rent ceiling is not checkable for this source.** The payload mentions charges
nowhere — zero occurrences of `charges`, `CC` or `HC` — so the Logirep and leboncoin precedent
applies and the figure lands in `rentHc`.

> **n=1 lasted about three hours.** The anchors were measured on ONE message with the risk stated;
> a second alert arrived the same afternoon (Meulan-en-Yvelines, 78250), confirms every anchor on a
> different commune, and adds the case the first lacked — a rent written `1.150 EUR / mois`, where
> the dot is a **thousands separator** and *"the rightmost separator is the decimal point"* would
> read it as 1 €. Both are frozen. Append a third; never renumber.

> **ITS COLOCATIONS CANNOT BE FILTERED, SO THE PUSH SAYS SO — `prose_absent`, 2026-09-01.** The
> developer reported colocations arriving from PAP. They do, and the reason is that all three
> defences are structurally inert here: the alert carries **no listing prose at all** (`description`
> is the literal `PAP.fr  De Particulier à Particulier ____` on all 57 stored rows, `title` is
> `Location appartement` or `Location maison` on every one), so `exclude_patterns` and
> `exclude_title_patterns` have nothing to scan — while a room in a shared flat is advertised with
> the WHOLE flat's room count and surface, clearing every numeric filter.
>
> **Both routes out are closed, and both were MEASURED rather than reasoned.** Detail hydration is
> refused by hard rule 5: `www.pap.fr` answers a **Cloudflare challenge** (`Just a moment…`,
> `challenges.cloudflare.com`) on three probes spaced 45 s apart, including the URL that had
> returned a clean 301 four minutes earlier — reputation-based, not transient, and from the
> deployment's own IP. That is the **A15 Val d'Oise Habitat precedent** on a second source: a
> ruling, not a capability limit. **Do not revisit it with a headless browser or a wait-and-retry.**
> And rent-per-room, the last numeric candidate, has no gap: across the four private-market sources
> the low tail runs 63, 71, 78, 84, 85, 90, 90, 91, 92, 92, 93, 94, 97×3, 98×3, 100×4 upward, and
> the colocation that motivated this sits at **130**, inside the densest part of it. Same negative
> as Track 1f's price-per-m², reached independently on a different statistic — which says the
> problem is the EVIDENCE, not the choice of ratio.
>
> So the source declares `"prose_absent": true` and `CriteriaEngine` appends
> *"annonce sans texte — colocation/meublé non vérifiables"*, beside the
> `HORS CHARGES … plafond non vérifiable` line it already emits. **It rejects nothing and scores
> nothing** (hard rule 8) — it names the boundary of the judgement, which is hard rule 9's
> discipline one layer up. The declaration is REFUSED at load on a source that maps a
> `description`, outside the `enabled` branch because `--source=` force-runs disabled sources: a
> caveat contradicted by the config beside it is worse than none, since it reads as considered.
> **The counterweight is load-bearing** — a line on every push everywhere is furniture, so the
> sabotage ledger pins both halves. Full measurements: `docs/plans/archive/pap-detail-hydration.plan.md`
> (archived 2026-09-02 — it was a second live plan holding a ruling, which the unified plan
> declares itself the single source of truth for; the refusal and its probes are unchanged).
>
> Two robots lessons came with it, both general. **`robots.txt` must be READ, never recalled**: the
> plan that authorised this work paraphrased a file it had seen and inverted its first rule
> (`Disallow: /*?*` refuses every query-bearing URL, and all 57 stored PAP URLs carry one). And **a
> source's own URL can be the disclosure** — PAP puts the subscriber's address in plaintext in the
> link it emails, so hydrating it verbatim would have sent that address to pap.fr on every fetch.
> Two findings came out of that same pass. **The `Redact` one is CLOSED (2026-09-09)**: the mailbox
> rule required a literal `@`, so `email=x%40y.com` passed through untouched — the local-part class
> had contained `%` all along and only the separator was narrow, which is why the literal-`@` case
> stayed green for as long as the gap was open. It is one alternation, `(?:@|%40)`, never a decode
> cascade — `RecoverableForms` is the SCRUBBER's job and this surface is adapter error text.
> Measured first: `%40` is PAP-only at 98 of 98 rows, a literal `@` appears in 0 of 3 471 stored
> URLs, `%2540` measures zero so double-encoding is deliberately unread, and no case variant is
> possible because both hex digits are numerals. **The first measurement was wrong and is the part
> worth keeping** — `url LIKE '%'||char(37)||'40%'` reads as *contains "40"*, `%` being LIKE's own
> wildcard, and reported every source as affected; `instr(url, char(37)||'40')` has no
> metacharacters. **Scope stated honestly: no production path fetches a PAP URL today** (hydration
> is refused above), so this is defence-in-depth for the surface `Redact` guards, not a leak that
> was happening. **The followed-redirect half is CLOSED (2026-09-26), and it was closed by
> measurement rather than by a fix**: no production path follows a redirect except
> `SitemapVehicleSource::sameLotTarget()`, which robots-checks and paces its one hop; libcurl's
> `FOLLOWLOCATION` is `false`, pinned by a loopback wire test
> (`testARedirectIsReturnedToTheCallerRatherThanFollowed`, target a dead port so a sabotaged client
> never leaves the machine) and a ledger case. `CurlHttpClient::sendFollowing()` — the one method
> that did follow, with no robots check — had no caller and no test, and was deleted.

### Transit enrichment — the last empty layer, and the curve that had to be measured (2026-08-26)

`src/php/Rent/Enrich/` was the only spec layer with no code at all. It exists because **nothing in the
score discriminated**: 83 live matches spread over all eight departements scored 16–48, so
`high_priority_score: 70` could never fire and the `!!` marker was dead.

> **COMMUTE DID NOT ON ITS OWN REVIVE THE MARKER, and reading this paragraph as though it had is
> the trap.** It lifted the ceiling from 48 to **70** — measured the same day over all 256 stored
> snapshots — and the marker stayed dead anyway, because `!!` also needs `confidenceBp >= 80` and
> the listings that score highest are precisely the ones whose tenure is a source default at 50.
> The threshold is `50` since 2026-08-26 for that reason. Full measurement in the Q2 block above.

`Enrich/CommutePlanner` is the interface, `Enrich/NavitiaCommute` the IDFM/PRIM implementation over
the ordinary `HttpClient` seam — which is what makes `SCOUT_OFFLINE=1` cover it structurally
rather than by discipline. **Verified against the live API** (hard rule 1): base
`prim.iledefrance-mobilites.fr/marketplace/v2/navitia`, an `apikey` HEADER, and
`journeys?from=<lon>;<lat>` returning `duration` in **SECONDS**. Three details that are easy to get
backwards and silent when wrong — a reversed coordinate pair returns a perfectly plausible journey
between two other places. All three are sabotage cases.

**THE OBVIOUS CURVE WAS BUILT, MEASURED, AND MADE THE PROBLEM WORSE.** Treating `max_minutes` as the
zero point of a scale starting at 0 assumes short commutes are common; measured live, the affordable
communes run **68–131 minutes** (Sartrouville 68, Aulnay 88, Dammarie-les-Lys 112, Dourdan 131).
Under that curve Sartrouville earned 3 points of 30 and everything else earned zero — the component
separated the whole set by **three points** while adding 30 to `positiveTotal()`, so every score in
the tree dropped by about a quarter and the ordering barely moved. The shipped curve is **at or
under `max_minutes` is FULL MARKS**, decaying to zero at twice it: same data, **21 points of spread**,
and Sartrouville reaches 67 against the dead 70 threshold. Predicting this would have got it wrong;
one probe of four communes settled it.

- **A score component, never a disqualifier** — developer ruling, verbatim *"1 hour 15 max ! but keep
  showing even those with more anyway"*, and hard rule 8 independently. Clamped at both ends, so it
  can never go negative and can never act as a back-door rejection.
- **`commuteMinutes` lives on `RawListing`**, not as a `judge()` argument, because `scout --domain=rent reclassify`
  re-judges from the v7 snapshot — a value passed alongside would be absent on every re-judge and a
  stored listing would silently score lower the second time. `floor` and `hasElevator` arrive by the
  same route.
- **Enrichment runs before CLUSTERING**, so it is upstream of the snapshot and of every disqualifier
  (hard rule 8: a disqualifier applied before enrichment rejects on a field enrichment would have
  filled). Upstream of clustering specifically because `$observed` is keyed on object identity.
- **Cached per COMMUNE (schema v9 `commute_cache`), and a FAILURE is never cached** — caching one
  would turn a bad afternoon at the API into a permanently missing component, with nothing to retry
  it. The key is a NORMALISED commune plus postcode: the same commune arrives spelled two ways in one
  response, and commune names repeat across departements.
- **The reference departure is FIXED** (next weekday 08:30), not "now" — cached durations must share
  one timetable or a commune resolved at 02:00 is incomparable with one resolved at 08:30, on the
  heaviest component in the score. *Stated cost:* every duration is a one-time sample of that
  departure, reflecting neither the hour a listing appeared nor a strike.
- **Unknown is UNKNOWN, never far** (hard rule 9): the component goes unscored and the reasons say
  `trajet inconnu — hors score`, because on a phone a missing line reads as a short commute.

> **THE KEY WAS SHADOWED FOR ITS FIRST HOUR, and the cause was an instruction in this session.**
> `.env` ended up with TWO `IDFM_API_KEY=` lines — the empty template default, and the value appended
> after it by a `>> .env` one-liner. `Config\DotEnv` applies the FIRST occurrence and skips every
> later one, and an empty string counts as set, so the real key could never be read. The API said so
> plainly: `{"message":"No API key found in request"}`. **Never append a key that already has a
> template line — edit the line in place.** `.env.example` now carries that warning where it happens.

> **THE QUOTA IS 1000 REQUESTS A DAY, NOT 20 000, AND IT RAN OUT UNSEEN (2026-10-08).**
> The class docblock said "a documented quota of 20 000". PRIM's own 429 says otherwise:
> `x-ratelimit-limit-day: 1000`, `x-ratelimit-remaining-day: 0`, `retry-after` ~21 h. It was found
> the day failures started being counted (architecture review C-10): the first deployed pass warned
> `23 calcul(s) de trajet en échec` against 2 new `commute_cache` rows, and a replay of the 66
> uncached communes of that pass got 66 × `429`. Three things spent the quota. **Every harvested
> listing was looked up**, and 59 of the 66 were outside the location filter (Indre, Loire, Nantes…),
> rejected by the engine moments later. **A failure is never cached** (the bullet above, still
> right), so each was retried every pass. And **each miss re-resolved the destination**. Once the
> quota ran out, every lookup was refused until the reset, and the reasons said `trajet inconnu`,
> which is true and explains nothing. That it ran out EVERY day is inferred (~23 uncached lookups a
> pass × ~96 passes against 1000), not measured over days.
> The repair, in `NavitiaCommute::minutesFrom()`: a listing the shared `Criteria::matchesLocation()`
> refuses costs no request (the engine rejects on the same predicate, so they cannot disagree); the
> destination is resolved once per planner (one pass); the first 429 ends the pass's lookups and the
> pass prints its own line with the figures the 429 gave; a `404` whose error id is `no_origin` (probed
> live; `no_destination` and `no_origin_nor_destination` by symmetry, [Unverified]) is an answer,
> not an outage, and is remembered for the pass. **Stated costs:** an out-of-area listing is
> snapshotted without a commute, so a later WIDENING of `postcode_prefixes` leaves `reclassify`
> scoring it without the component until it is sighted again; and an in-area commune with no public
> transport still costs about two requests per pass, because a persisted no-route cache was ruled
> out (no schema change). Read `x-ratelimit-remaining-day` the day after a reset to see whether that
> second cost matters.

**Commute is OFF everywhere except the developer's machine.** The activation is a personal address
and lives only in the gitignored `config/rent/criteria.local.json`, and the loader's two-sided guard
refuses `weights.commute` without `commute.enabled` — so CI, the fixtures and the sabotage ledger all
run commute OFF, and the component is exercised by `tests/fixtures/rent/criteria/commute.json`.


### Status notes accreted after the transit section (heading added 2026-09-28; the paragraphs are unchanged)

**`seloger` IS LIVE as of 2026-08-25 — source #5, and the first that is not a landlord.** The IMAP
credentials arrived, and `scout --domain=rent doctor --source=seloger` against the real mailbox returns **9
annonces, `ok`, ~19 s**. Prove a change without touching the network with
`RENT_SCOUT_DB=$(mktemp -u) MAILBOX_DIR=tests/fixtures/rent/seloger scout --domain=rent doctor --source=seloger`; a seeded run over the two
fixtures yields one match (Dourdan, 3p, 52,37 m², 915 € CC) and one rejection (Conflans, 44,71 m²
under the 50 m² floor).

Two defects were found by pointing it at the real mailbox, and neither was findable any other way.
**Four of the first nine matches were COLIVING ROOMS** — a bedroom advertised with the whole flat's
room count and surface, so every numeric filter passed. Excluded by an ANCHORED title pattern
(`^\s*chambre\b`), never by a description match: `3 chambres` in a description is exactly the family
flat the criteria are looking for. **That exclusion was then INERT on 37.5% of the source for a
month, and this file said it worked** — see § "A title is a position, never a vocabulary" above.
And **every listing came back with `commune = null`** while its
postcode parsed correctly: `communeIn()` scanned only `Criteria::communeLabels`, which in region mode
is built from the RANKED communes, so a watch covering all of Île-de-France knew the names of a
handful of towns and no others. Nothing about that looks like a fault from outside — the listing
still matches on its postcode, so the push simply could not say where the flat was, `Dedup` got a
weaker key, and the S1 score could not fire.

The fix is **`commune_pattern`**, a per-source `params` regex read exactly as `title_pattern` and
`residence_pattern` are, with the vocabulary scan kept underneath as the fallback so a source that
configures no pattern is bit-for-bit unchanged. Three rules travel with it:

- **The anchor is the parenthesised postcode BELOW the name, not the quartier comma above it.**
  Measured across all 50 messages in the live mailbox: three of the nine cards (`Mormant`, `Garches`,
  `Moret-Loing-et-Orvanne`) carry no quartier line at all. **Both frozen fixtures do**, so a
  fixture-only test would have proven the wrong shape confidently — the n=1 generalisation this repo
  has already paid for twice. The no-quartier shape has its own test.
- **The pattern beats the vocabulary, deliberately.** The scan is a substring search over the whole
  card, so a card in Mormant whose copy says *"proche Dourdan"* returns Dourdan — the prototype's
  documented over-matching defect. The pattern reads the field the portal laid out.
- **It is compile-checked at load**, alongside the other two, because its failure has an alibi: a
  broken pattern falls back to the scan and reads as *"a listing in an unranked town"* rather than as
  a fault. `matchParam()` uses `@preg_match`, which neither warns nor throws.

> **A §1 RESIDUAL, stated rather than left to be discovered.** `seloger` is `mixed_tenure: false`,
> which this file already rules defensible for a private-market portal — but going live is what
> makes the flag act. What holds and is asserted: an explicit `PLS`/`PLUS`/`PLAI`/`conventionné`
> anywhere in a card is caught at 0.90 by the tier-2 label rules, which **never consult
> `mixed_tenure`**, and a real frozen card with one injected must REJECT. What does not: a card
> stating no tenure at all takes the source default `LIBRE` and matches — which is every live card,
> and the notification says so in its own reason line. It matters because the mailbox proves
> **In'li and CDC Habitat both advertise on SeLoger**, and In'li was itself proven not pure LLI. The
> flag is not armed because SeLoger cards state no tenure at all, so `true` would digest **100% of
> the source** — the In'li lesson of 2026-08-23, *"not §1 satisfied, it is the tool switched off"*.
> PLAI and PLUS are allocated by commission and are not advertised on commercial portals; **PLS
> occasionally is, and that is the residual.** Reversed by one line — `mixed_tenure: true` — and
> `docs/plans/archive/seloger-email-alert.plan.md` records what that costs.
>
> > **THE RESIDUAL IS NARROWER SINCE `dede8ac` (2026-09-01), AND THE PARAGRAPH ABOVE STOOD UNCHANGED
> > FOR A DAY SAYING OTHERWISE** — found by the C2 round-1 completeness lens, which noticed the new
> > class's own docblock naming this text as no longer holding while the text itself was never
> > edited. `Core/LandlordRegistry` reads the advertiser out of the card and substitutes THAT
> > landlord's profile, so a SeLoger card advertised by In'li or CDC Habitat is judged as the
> > landlord it names rather than by the portal's `LIBRE` default. The sentence *"a card stating no
> > tenure takes the source default and matches"* is therefore true only of an **anonymous**
> > advertiser now, which is what the residual has shrunk to. Everything else above still holds —
> > the tier-2 label rules never consult `mixed_tenure`, and arming the flag would still digest the
> > whole source.
> >
> > **It is a NARROWING, not a closure.** A card whose advertiser is a bailleur that names itself is
> > covered; one that advertises anonymously, or through an agency that does not, is not — and
> > `advertiser_pattern` is a per-source regex, so a source that configures none is exactly as
> > exposed as before. The registry is a new input to the classifier's five-tier signal priority in
> > § "Domain glossary" and is documented there.

> **ONE MAILBOX SERVING MANY PORTALS IS A SHARED BUDGET, and it zeroed a live source hours after it
> went live (2026-08-25).** The developer widened their Gmail filter to catch five portals and
> re-labelled a year of archive into the same label; SeLoger went **9 listings → 0**, and the only
> thing that said so was `SourceHealth` (`warn_drop`, 0 against a 7-day mean of 9). Measured: the
> folder held **1436** messages, `SEARCH SINCE` matched **124**, and their sequence numbers began at
> **6** — so `fetchRecent`'s tail-of-folder read contained none of the day's alerts. **The mechanism
> behind that ordering is deliberately NOT written down**: a first draft explained it as re-labelling
> minting fresh high UIDs, which its own evidence contradicts, and *a true number attached to an
> invented cause* is this repo's named failure. What is measured is that sequence order disagrees
> with date order. Two fixes: **`SEARCH SINCE`**, so what counts as recent is the SERVER's answer
> about dates (`IMAP_SINCE_DAYS`, default 7, a window of 0 clamped to 1); and **`FROM <the source's
> own sender>` pushed into the query**, so each source gets its own window rather than a slice of
> one — without it a busy portal starves a quiet one silently, and it worsens with every source
> added. `scout --domain=rent doctor --source=seloger` → **74 annonces**, from 0.

> **A rent is a PERIODIC amount, and a wider window is what proved it.** A live `Baisse de prix`
> card quotes three figures — the reduction `baissé de 100 €`, the new rent `1 100 €/mois`, the old
> `1 200 € ↘ 8%` — and only the rent carries a period. The reader took the first and returned 100,
> below the plausibility floor, so the card was refused and the source reported `broken` on an
> unchanged template. **That is luck, not a guard: a 300 € reduction would have been returned as a
> rent**, inside the band and clearing a ceiling the flat comes nowhere near. So a periodic figure
> outranks a bare one, and every match of a pattern is examined rather than only the first —
> `preg_match` stopping at the first hit is what let one implausible figure hide a readable rent
> three lines below it.

> **The mailbox is the developer's personal one, and the `from` filter is doing real work.** Of 50
> messages only 3 are SeLoger alerts. Twelve come from `seloger@s.seloger.com` — *contact receipts*,
> whose unfilled template reads `En avant-première870,00 €cc /mois. · m² · pièces · chambres` beside
> the commune *Saint-Germain-de-Tallevend-la-Lande* (Calvados) and the postcode `75015`: a rent in
> the plausible band, a valid IdF postcode, and a town 250 km away. They are excluded by
> `params.from`, and would be refused again by the no-information floor. Both layers earn their keep.

**THE CAR DOMAIN EXISTS AS OF 2026-08-29 — `scout --domain=car`, `src/php/Car/`,
`config/car/`.** A second domain, not a parameterisation of the rent path: `VehicleListing`,
`VehicleClassifier` (the §1 vehicle set, non-overridable, NEGATION READ FIRST because every term
arrives negated in honest copy), `VehicleCriteria` + `VehicleScorer` (one hard ceiling, one
stated-location filter, everything else a clamped score component), `VehicleStore` (own tables on
its own file, composing `Core/RunStore` for runs/health/alerts — it composed the housing `Store`
wholesale until the 2026-09-01 split), two adapters (`VehicleEmailSource`
with positional card readers; `SitemapVehicleSource`, the detail-hydration pattern applied to a whole
source), `VehiclePipeline`, `VehicleFormatter`, `Car/Cli/CarScout` (was `Cli/VehicleScout`). The rent path changed in two
places only: the `--domain=car` dispatch line and `Cli/ChannelFactory`, extracted so both CLIs build
channels from one place. First slice: ParuVendu (email, samples its feed — 3 cards per message) and
Autohero (sitemap + JSON-LD, seed before watching). Rulings: `docs/plans/archive/scout-rename-and-car-domain.plan.md`;
build record: `docs/plans/archive/car-domain-first-slice.plan.md`. **The §1 tripwire covers the vehicle
set as of 2026-08-31** — `tenure-guard.sh` gained the car patterns and `tests/test-vehicle-guard.sh`
proves both halves (22 cases). ONE hook, not two: the relaxation shapes are identical — an
allow-list, an emptied set, a weakened test — and only the vocabulary differs, so a second hook
would be two log formats, two self-exclusion lists and two places to forget. Four defects turned up
while building it, none by design and each worth knowing before touching the patterns: **the domain
signal must be the PATH** (`src/php/Car/`, `Vehicle*.php`), because a first draft keyed on a vehicle
word in the WRITE and so went silent on `private const array NEGATABLE = [];` — the literal way to
empty the set, whose only vehicle word was the filename; **`opposition` was missing outright**,
which is why the test iterates the vocabulary as a SET rather than spot-checking; **the multi-word
terms matched spaces only**, so the config spelling `"pour_pieces_enabled": true` went straight
through (`[ _-]` now); and **`config/car/` is deliberately NOT a domain signal**, because the §1
vehicle set is CODE and non-overridable while that file's `exclude_patterns: []` is the ordinary
user list and ships empty on purpose — a draft that included it fired on a shipped file, which is
how a tripwire gets waved through. The banner names whichever domain actually fired.

**`brand_avoid` IS A LIST OF STEMS, NOT OF MAKES, and that is the half worth knowing before editing
it** (developer ruling, 2026-09-01, widening it from 3 makes to 22 — Stellantis' 14 marques, plus
ford and chevrolet which are NOT Stellantis, plus the Renault-Nissan-Mitsubishi alliance and
Stellantis-distributed leapmotor). The mechanism is untouched: flat, equal, 10 points of 100, a
score component and never a disqualifier. What changed with it is the MATCHER. It was
`in_array($folded, $brandAvoid, true)` — exact equality — and the live store carries one marque
under two spellings, `ds` from leboncoin and `ds automobiles` from autohero, so a single entry
caught one source's row and silently missed the other's. A preference inert on a whole source, worth
10 points of ordering, with every score still plausible and nothing reading as a fault: the
`exclude_title_patterns`-on-In'li class for the fourth time. So an entry now matches to a
**non-letter boundary**, and the entry is the SHORTEST unambiguous form — `alfa`, never
`alfa romeo` — because the gap runs BOTH ways and a stem longer than the make misses just as
quietly. Two things guard it, and the second is what makes the first safe: every suffixed spelling
must be caught, and every one of the 26 makes the live store actually contains must be classified
as the list says — over-reaching ranks a car BELOW one that deserves less, which is as silent as
under-reaching and worse. Both are sabotage cases. **The list is not "Stellantis" — do not tidy it
into one**, and predicting its effect would have got it backwards: widening it took the avoided/
unlisted median gap from 9 points to **13** (58 vs 71 over 160 re-judged snapshots), so a list
covering 60 % of the fleet discriminates MORE, not less.

> **TRACK 7 MADE THE BRAND SHARE A THREE-WAY AND MOVED FORD, so two claims above no longer hold as
> written** (developer ruling, 2026-09-08). `brand_favour` is the second list — 16 makes, the same
> stem semantics and the SAME matcher, because the `ds` / `ds automobiles` miss runs in both
> directions and a favoured list on exact equality would have caught `mercedes` and silently missed
> `mercedes-benz`. **Favoured takes the whole share; avoided AND unlisted take none.** So *"an
> unlisted make earns the share"* is now true only when BOTH lists are empty, and the arm that
> awards it tests both — keyed on `brandAvoid` alone, a config naming only favoured makes would have
> awarded the share to everything and silently disabled the preference it had just written down.
> The list is **21 stems**, not 22: `ford` moved to `brand_favour` after being measured both ways
> (median 80 and 17 of 28 over the gate as favoured, median 55 and 0 of 28 as avoided), on the
> ground that `_why` in the config already admitted ford belonged to neither group it justifies.
> `chevrolet` stays and the choice is moot — 0 rows. The brand weight is **25** of 100 now, not 10,
> and the live store holds **39** distinct make spellings rather than 26; all 39 are pinned in
> `VehicleBrandPenaltyTest` as **three** classes, because an assertion that knew only *avoided* and
> *not avoided* would have passed unchanged through the ruling that created the third.
> `body_rank` is `body_favour` and is FLAT — suv, break and berline are equal — and the loader
> refuses the old key by name. Measured through the shipped scorer over all 951 stored MATCHes: 197
> individual pushes at the unchanged gate of 73, **all 197 favoured**, zero avoided, zero unlisted,
> zero unknown-make.

> **A CAR PORTAL WHOSE LINKS CARRY NO ID IS KEYED ON ITS CONTENT, AND A LABELLED CARD IS READ BY
> ONE PATTERN (rows 37 + 38, 2026-09-05).** Measured on CapCar and La Centrale before building:
> every link is a per-recipient tracking redirect (`sendibt3.com/tr/cl/<token>`,
> `clicks.mail-alerte.lacentrale.fr/f/a/<token>~~/…`), so `basename()` of the path — the identity
> ParuVendu and leboncoin have always used — is a fresh id per message and the same id for every
> card in one. `id_from: content` is the SeLoger discipline on the car side: `sha1(source | folded
> title | year | km)`, the PRICE deliberately out of the key (a drop is an event, not a second
> car), behind a no-information floor — a title AND a year or a mileage — below which the segment
> is not a card. Stated cost: two identical cars share one row, and on La Centrale, which truncates
> the title to ~28 characters, the mileage is all that tells two `RENAULT KANGOO II EXPRESS p...`
> apart. **Pick the scheme before the first enabled pass** — nothing migrates a row between keys.
> The card itself is a labelled block (`Marque : X / Modèle : Y / … / Prix : P €`, U+00A0 before
> every colon, U+202F in the price) or a title line with the facts on their own lines, so
> `facts_pattern` accepts `title`, `make`, `model`, `version`, `gearbox` and `price` named groups
> beside `body/fuel/year/km`; the title is composed `make model version` when no `title` group is
> captured, and the loader REFUSES a second provider for any fact a group supplies
> (`make_model_pattern`, `make_model_source`, `title_pattern`, `price_pattern` — two providers,
> one honoured, the other inert). Two more things a labelled portal forced: `card_separator_pattern`
> (a zero-width lookahead keeps the label inside the segment it starts) and `link_after` — on a
> portal whose EVERY link sits on one tracking host, the last card's segment also holds the
> footer's, so the card's link is the FIRST host link after its own CTA, never the last in the
> segment. The unknown-make sentinel applies on the facts path too. ParuVendu and leboncoin are
> byte-identical (their fixture tests are the counterweight); 12 ledger cases pin the directions.
>
> **CAPCAR IS SOURCE #4 OF THE CAR DOMAIN (Track 6-B1, 2026-09-05), and the first built on the
> mechanisms above.** `scout --domain=car doctor --source=capcar` against the frozen fixtures returns
> **12 annonces, `ok`, ~240 ms**; prove a change offline with
> `MAILBOX_DIR=tests/fixtures/car/capcar CAR_SCOUT_DB=$(mktemp -u) scout --domain=car doctor --source=capcar`.
> One alert a day at 18:00, four labelled cards each, text/html only, n=3 from day one (three
> captures frozen, `CapCarFixtureTest` hand-reads all twelve). Two things a reader of its block
> needs: `link_host` is the FULL Brevo account subdomain (`cjbjibe.r.bh.d.sendibt3.com/tr/cl/`),
> because `adLinkIn()` matches by prefix of the bare URL and `sendibt3.com` alone would match
> nothing — a source yielding zero cards while reporting a healthy fetch; and a `_`-prefixed
> comment on a PARAM lives inside `params`, not beside it — the source-level comment allow-list
> is `_comment/_why/_source/_verified_at` plus a known top-level key, and the loader refuses the
> rest (found by the first `doctor` run, not by reasoning). **Config-only for the source, but the
> adapter code it rests on is baked into the image**: a deployed watcher older than `7e1d54b`
> refuses the block at startup (`id_from` unknown to its loader) rather than skipping it.
>
> **LA CENTRALE IS SOURCE #5 (Track 6-B2, 2026-09-05), AND ITS FIXTURES ARE THE ONES THE SCRUBBER
> PASSED WITH THE ADDRESS STILL IN THEM.** `scout --domain=car doctor --source=lacentrale` against the
> three frozen captures returns **9 annonces, `ok`, ~45 ms**; the block is config-only over rows
> 37+38 (`card_separator "Détails"`, `id_from content`, one `facts_pattern` with title/km/price,
> `make_model_source: title` because the title's first word is the make). No year on the card, so
> the age component is unscored on every La Centrale car (hard rule 9: unknown, never 0). **The
> leak**: every capture carries an `X-MSFBL` feedback-loop header whose base64 payload holds the
> subscriber's address, FOLDED across RFC 5322 continuation lines every ~64 columns. Neither the
> scrubber's run scan nor its quoted-printable decode crosses a fold (a QP soft break ends in `=`,
> a header fold does not), so every fragment decoded to noise and the tool reported `scrubbed` on
> two of the three; `FixtureSecretsTest` caught them one commit before a push — by the luck of one
> 64-character fragment decoding to the local part. Both now scan a header-UNFOLDED form, the tool
> drops `X-MSFBL` by name, and the case in `tests/test-scrub-eml.sh` puts the address ASTRIDE the
> fold on purpose: with it inside line 1 a fragment decoded to it alone and the case passed before
> the fix. Verified red by removing the unfolded form from each guard. **This is the fourth
> encoding the scrubber learned from a real capture** (JWT, outer base64, percent-encoding,
> folding — and on 2026-09-24 HelloWork's token rewritten in place and Apec's host folded mid-word
> with a `=3F` for its `?`, so the count is history, not a tally), and the rule the file already carries: *a check that only understands the encodings
> already seen is the same defect with a later date.* Scrub, then run the guard, then commit —
> never the first two alone.
>
> **AGORASTORE IS SOURCE #6 (Track 6-B3, 2026-09-05), THE FIRST AUCTION, AND A STATED LOT
> REFERENCE IS ITS IDENTITY.** `scout --domain=car doctor --source=agorastore` against the three
> frozen captures returns **12 annonces, `ok`, ~25 ms**. No price on any card (an auction has none
> until it closes), no year or mileage except inside free-text titles — deliberately NOT read out
> of them, which would be the first-match scan this adapter exists to avoid — so the price ceiling
> never fires and most lots score `année inconnue` / `kilométrage inconnu` (hard rule 9). What every
> card DOES carry is a per-lot reference, and the facts `ref` group makes it the identity: a stated
> id beats a hash of facts, and it waives the year/km floor, because three of five lots state
> neither and the ref already supplies the evidence the floor exists to demand; a ref with an empty
> title is still not a card. Two scrubber defects surfaced on these captures and both are fixed with
> a case each in `tests/test-scrub-eml.sh`: `X-Mailgun-Sid` (base64 of a JSON array carrying the
> address — dropped by name; the 24 zlib tracking blobs per message inflate to none of it, measured)
> and the opaque-hex replacer numbering each OCCURRENCE, which turned Mailgun's 60-hex MIME
> boundary into four different strings so the scrubbed file parsed to an EMPTY body with zero
> links while the tool reported `scrubbed` — a fixture that exercises nothing, the quietest failure
> a fixture can have. One placeholder per distinct value now. **Always parse a scrubbed capture
> back and compare its link count with the raw one** before committing it.
>
> **ALCOPA IS SOURCE #7 (2026-09-24), THE SECOND AUCTION, AND THE FIRST CAR SOURCE THAT IS
> POLLED — because its alert fails auction rule 2** (no closing time, no per-lot link, the same three
> cars for days). `AlcopaVehicleSource` is a SITE-SPECIFIC adapter (`type: alcopa`, every selector in
> code, the loader refusing any other host, a `map` or an `item_url_pattern`). Prove a change
> offline with `AlcopaFixtureTest`, whose two frozen search pages hold 25 real lots. Five things
> before touching it:
>
> - **Three pages, three jobs.** The saved search is the index, walked to the count page 1 states
>   and checked against it (a page answering page 1 again is the lost-page shape, and it throws).
>   The LOT page is opened once per NOVEL lot, because its `Informations` / `Commentaires` blocks are
>   the only place a hail, a missing carte grise or a warning light is stated — the card never is,
>   so skipping it would switch the excluded-vehicle set off on this source. A LIVE sale's page is
>   read once per pass, for its window's end.
> - **The closing comes from two places, by sale kind.** An ONLINE lot closes at its card's
>   `data-ts` countdown: equal to the sale's Flash on 20/20 measured cards, and per LOT. A LIVE lot
>   opens at its lot page's instant and closes at the saleroom window's end; its `data-ts` sits 30 min
>   before the room opens, means something the site does not state, and is never rendered. A lot the
>   adapter cannot place — no sale, two sales, a sale page whose day disagrees, a window ending before
>   it opens, a closing already past — is warned and NOT returned; rule 2 refuses it.
> - **A card whose countdown is over is dropped before its lot page is requested**, so an ended sale
>   costs nothing. The post-fetch "already past" branch is reachable only on a card with no
>   countdown, which is how its test reaches it.
> - **`IndexedVehicleSource` is the contract that seeds it** without opening a lot page and
>   baselines its health on the index size, not the novel slice. Test for the interface, never the
>   class: `IndexedVehicleSourceCallSitesTest` fails when a concrete name comes back.
> - **No Alcopa lot can clear the push gate, and the rule that makes that safe is not in the adapter.**
>   Measured through the shipped scorer: no price (10 points) and no body (25) on any card, so 65 at
>   best against 73. `Car/AuctionUrgency` (developer ruling 2026-09-25) pushes a lot whose closing
>   falls BEFORE the next daily rollup whatever its score, with a reason line saying so — without it
>   a lot first seen the morning it closes would be announced the next day, after the hammer. Every
>   other lot waits for the rollup, which still arrives in time. With no `rollup_hour` nothing
>   drains the queue on a schedule, so any future closing counts as urgent.
> - **Hail is a score PENALTY, never a reject** (developer ruling 2026-09-25): `Car/Hail` reads
>   `grêlé` / `grêle` out of the car's own text, NEGATION FIRST (*non grêlé*, *sans grêle*, *aucune
>   trace de grêle*), and `hail_penalty: 30` comes off the score. 30 is measured: the smallest value
>   keeping a perfect-scoring hail car under the gate of 73. The live car store held 0 mentions in
>   2 570 snapshots before Alcopa. A whole sale titled *récents et grêlés* is NOT read — only the
>   lot's own comment is.
> - **Stated costs:** no price and no postcode, so neither the ceiling nor the location filter fires;
>   ~13 search pages a pass; a lot seen once keeps the closing it was announced with, even if it is
>   relisted.
>
> **A PORTAL WRITES ITS FACTS LINE IN MORE SHAPES THAN THE FIRST CAPTURE SHOWS (2026-09-05).**
> ParuVendu's `facts_pattern` required `body - fuel - Année YYYY - N km`; the portal also sends
> `Essence - Année 2019 - 59 500 km` and a bare `Année 2020 - 80 237 km`, and on those the WHOLE
> match failed — so year, mileage, fuel and body went null TOGETHER on **17 of 160 stored cards
> (11 %; live `doctor`: `facts_pattern 15/132`)**. Year and mileage are the two heaviest inputs of
> the vehicle score, so those cars were judged *année inconnue / kilométrage inconnu* with the facts
> printed on their own card, and nothing read as a fault: a card that extracts nothing looks exactly
> like a card that says nothing. **`PatternMissLog` counted every one of them and stayed silent,
> correctly** — `total()` speaks only at 100 %, and 11 % is the partial-miss blind spot this file
> already records for cityloger. It was found by the per-source NULL-rate audit, not by a test.
>
> **The trial is the part to copy, and it rejected two plausible repairs before the third stood.**
> Run the candidate over every stored card of that source first: `[^\n-]+?` for the optional body
> LOSES 38 cards, because the commonest body is `4x4 - SUV` and it contains the separator; and a
> GREEDY optional body puts a lone `Essence` in the BODY slot — a wrong answer wearing an alibi, the
> `autres` class again. The shipped shape is a LAZY optional body (`)??`) over a CLOSED fuel list:
> 143 identical, 17 gained, 0 lost, 0 changed. The list had to be widened as it was closed
> (`GPL ou GNL`), because the catch-all it replaced was what made any lone leading component look
> like a fuel. Four ledger cases, one per rejected shape.
>
> **A `_`-prefixed note is a comment only while the key it annotates exists** (`Config\Reader`), so
> `_facts_pattern` documents `facts_pattern` and `_facts_pattern_shapes` is refused as an unread
> param. Cost ten minutes to rediscover; it is the same rule the CapCar block already records from
> the other side.

> **A CAPTURE THAT SUCCEEDS IS NOT A CAPTURE THAT MEANS SOMETHING — the portal's own "I don't
> know" token (Track 6-A4, 2026-09-02).** ParuVendu writes `/voiture-occasion/autres/autres/` when
> it cannot name the marque. The pattern captured `autres` perfectly, so nothing read as a fault;
> `autres` then matched no `brand_avoid` stem, and the car earned the **whole 10-point brand
> share** — on `Ds Ds4 E-tense 225ch Performance Line`, a **DS**, which is on the avoid list. This
> is not the unknown-make arm, which is honest: it is a wrong answer wearing one, and the whole
> class of defect that `null`-versus-value discipline (hard rule 9) exists to prevent, one layer
> up. The repair is a per-source `make_model_unknown_pattern`, anchored, refused empty, and
> **deliberately never counted as an extraction miss** — the pattern HIT, so counting it would put
> every correctly-read card in the denominator and hold the ratio near 100 % for ever (F30's shape,
> the `subject_pattern` ruling read backwards).
>
> **The half worth carrying is the other one: the audit's own recommendation rested on a premise
> nobody re-read.** Finding N3 preferred reading the make from the title, because nulling would
> supposedly leave the car with *"still full share by hard rule 9"*. `VehicleScorer`'s null arm
> scores **0** and says `marque inconnue — hors score` — a deliberate deviation from the plan's
> line, made months earlier, and the arm's own docblock still claimed *"both shipped car sources do
> extract a make"*. Both mechanisms therefore give this car the same score, and the preferred one
> was the fallback shape `make_model_source`'s docblock refuses, on the measurably worse haystack:
> over 108 stored rows the title's first word is the make **101 times**, the misses starting with
> the model. **Re-read the code an audit recommendation reasons about before building the thing it
> recommends** — a finding can be right about the defect and wrong about the fix.

**THE JOB DOMAIN EXISTS AS OF 2026-09-13 — `scout --domain=job`, `src/php/Job/`, `config/job/` —
AND IT IS DEPLOYED AS `job-scout` SINCE 2026-09-14.** A third domain, slice 1 of `docs/plans/job-domain.plan.md`: `JobListing` +
`JobSnapshot`, `JobClassifier` (what an offer states: contracts, work mode, level, pay lines,
eligibility), `JobCriteria` + `JobScorer`, `JobStore` (its own `job_meta` v1 and `job_listings`,
composing `Core/RunStore`), `JobEmailSource`, `JobDigestEmailSource`, `JobPipeline`, `JobFormatter`
and `Job/Cli/JobScout`. Seven sources over IMAP: `linkedin` (an `email_alert`, the HTML part) and,
since 2026-09-24, six `email_digest` sources — `freework` (the text part), `hellowork`, `apec`,
`collective` and, since 2026-09-26, `mindquest` and `freelance_informatique` (see the bullets below). Prove a
change offline with
`JOB_SCOUT_DB=$(mktemp -u) MAILBOX_DIR=tests/fixtures/job/linkedin bin/scout --domain=job doctor --source=linkedin`
— **16 offres, `ok`** over three captures (2026-09-14) — or `MAILBOX_DIR=tests/fixtures/job/freework
… --source=freework` — **36 offres, `ok`** over one capture (n=1, 2026-09-24) — `hellowork`
**39, `ok`** over four, `apec` **45, `ok`** over one (n=1), `collective` **5, `ok`** over five, `mindquest` **12, `ok`** over
one alert delivered twice (n=1), `freelance_informatique` **1, `ok`** over one mail (n=1); the ledger half is
`SABOTAGE_FILTER='^job:'`. Nine things before touching it:

- **It has no §1.** H1–H8 reject and six components score (stack 25 · pay 20 · level 15 · green 15
  · remote 15 · freshness 10), but what it rejects is an offer the user does not want, not one they
  cannot take. So the car domain's stated cost below — a drain announcing a row whose snapshot will
  not decode from its stored columns — is an unwanted push here, never an ineligible one.
- **A LinkedIn card carries no date**, so freshness is unscored on every offer (`date de publication
  inconnue — hors score`) and the reachable score is **90** — **70** on a card stating no pay, which
  is most of them (7 of 115 measured cards state one). An unknown component scores 0 and says so,
  the car rule; nothing is renormalised.
- **The card is read from the HTML part** (`EmailMessage::htmlText`): it starts at a
  `/jobs/view/<id>/` link, that id is its identity, and it ends at the next distinct id. Nothing
  before the first card is read, because the preheader quotes the subscriber's own pay filter —
  PAP's search-criteria trap a second time — and nothing from `footer_marker` on.
- **Stated costs, each written down rather than left to be found:** no `push_min_score` ships, so
  every match is pushed individually and the rollup queue holds only pushes that FAILED; a queued
  offer whose snapshot will not decode is announced from its stored columns without a re-judge
  (`JobScout::collectRollup()`, the car cost again), so an offer today's criteria would reject can
  be pushed; an empty `green` map scores that component 0 for every offer (`JobScorer::green()`),
  lowering the ceiling by 15 where the car scorer awards the share — OPEN, though the SHIPPED map
  is not empty (23 terms, 3 groups); **what starves green is the TEXT, not the terms**: no job
  source carries a description, so green reads the title alone and fired on **7 of 409 stored
  matches** (2026-09-25, re-judged offline) — accepted by ruling, and the only lever is fetching
  the offer page, a per-site robots/ToS question nobody has asked; `pay_pattern` is not
  counted as a miss, and a message with NO HTML part counts misses that escalate only when every
  claimed message does — and it is still claimed, so marked `\Seen` with nothing read. Since
  2026-09-25 (developer ruling) each such mail is WARNED about by date on every pass that reads
  it, so one among normal ones is no longer silent (`JobEmailSourceTest`'s two `…NoHtmlPart…`
  cases and two ledger cases); if LinkedIn drops a card's
  logo link `place_pattern` misses on every card (counted, so it escalates); monthly pay shapes are
  unread and fail safe; and **the deployed gate lives outside the repo** — `push_min_score` 40 and
  a 09:00 rollup in the gitignored `config/job/criteria.local.json` (ruled 2026-09-14) — so a clone
  runs the shipped criteria, which push every match, and the shipped-criteria costs above describe
  a clone rather than the running watcher.
- **Free-Work is a DIGEST read from its own text part, and it names no company** (2026-09-24).
  One `card_pattern` per card (`title`, `contracts`, `facts`, `url`), deliberately NOT
  line-anchored: the first card of each of the four alert sections is glued to its section header,
  and a line-anchored reader found 36 cards of 40. The facts' LAST ` - ` segment is the place; a
  segment matching `salary_pattern` (`60k-67k €`) or `tjm_pattern` (`400-550 €` — a day rate, per
  the live offer page's `€⁄j`) is put into `payText` with that unit STATED, so `JobPay` stays the one
  reader of pay (it reads nothing from a bare `400-550 €`, measured). The sections overlap by design
  (40 cards, 36 distinct), so an identical repeat is kept once in silence and only a repeat with
  DIFFERENT text is warned about. **Stated costs:** the card states no company and no work mode, so
  the remote component is unscored and **the cross-source key the plan asked for (company + title +
  commune) cannot be built** — an offer on both LinkedIn and Free-Work is pushed twice (measured
  overlap on the first capture: 0 of 36); the digest shows 10 cards per section of up to 71, so the
  source SAMPLES its feed; and the scrubber had to learn Mailjet's click links
  (`tx.mjt.lu/lnk/…/<base64url of the target>`), whose targets were the live signed unsubscribe URLs.
- **HelloWork and Apec put the offer id ONLY inside a base64url token** (2026-09-24), so both use
  `id_token_pattern`: its `token` group is decoded strictly and `id_pattern` runs on the DECODED text.
  HelloWork's click token is `<subscriber>🪢<offer URL>`, and the push links that offer URL without its
  utm query; Apec's `e` token is `p1=www.apec.fr&p2=<id>W…`, names no URL, so the tracking link is kept
  WHOLE. Both cards put the place on a line of its own (`Suresnes - 92`), so `card_pattern` names a
  `place` group, which is the location whole — through the ` - ` facts splitter it reads `92`. The
  card patterns are line-by-line with POSSESSIVE separators: the first draft's `\s*` crossed newlines
  and hit PCRE's backtrack limit on every HelloWork message, which `preg_match_all` returns as
  `false`; the adapter records that as a `card_pattern` miss, so every message missing escalates as
  an outage of the whole source rather than reading as a quiet market — but not one offer is read. Apec's card is anchored on its TITLE,
  because only some cards carry a logo link before the title (anchored on the link it lost 15 of 45),
  and ties its four fields together by a backreference to the shared `e` token ALONE: each of a
  card's four links has its own slot in `id` and its own `s`. **The first deploy read 0 Apec offers
  live while the fixture read 45**, because the scrubber had replaced every `id`/`s` with ONE constant,
  making a card's links identical — a shape Apec never sends — and the reader was written against it.
  The tell was on screen and was explained away: 221 links became 53 after the scrub. Now each
  distinct value gets its own placeholder (the Agorastore rule, which this rule had not applied), the
  fixture keeps all 221, and a scrubbed capture is always checked against its RAW twin through the
  adapter, not only for its link count.
  **Stated costs:** neither card states a stack, a work mode or a date, and Apec states no pay, so
  under the deployed gate of 40 **one offer of 84 pushed individually** on the captures (the other
  59 matches go to the 09:00 rollup); Apec's company is sometimes the relaying board
  (`cadremploi`, `Handicap Job`); `Corbeil-Essonnes - 91` classifies as an UNKNOWN place (fails open,
  H8); and Apec is n=1. The scrubber learned two more encodings for them — a token rewritten in place
  rather than replaced, keyed on HelloWork's own markers in the DECODED text (the host is folded
  mid-word in the real capture), and Apec's per-recipient `id`/`s`/`e` behind a host folded
  mid-word and a `?` written `=3F`. Its query reader also had the alternation `[^"\s<>]|=\r?\n` in
  the wrong order, so it stopped at the first soft break; that was the only instance in the file.
- **Collective.work sends ONE offer per mail, and names its company only in the SUBJECT**
  (2026-09-24). `[<first name> x <company>] Nouvelle opportunité`, then `Offre :` + the title and a
  `Postuler` app link carrying the opportunity id — the identity. So `subject_company_pattern` (a
  `company` group, counted per claimed message) reads the company, and the card states no place at
  all, which the source DECLARES with `place_absent: "true"`: the loader still refuses a place-less
  card pattern without it, refuses it beside a pattern naming a place, and refuses the company from
  the subject AND the card (two providers, one inert). The plan recorded it as n=0 for a day while
  **24 mails sat in the label** — 459 over a year, 41–77 a month — because nobody searched the label
  by sender. Two template traps, both measured over the full history: the template before 2026-09
  reads `Projet:` / `Découvrir le projet` with its `é` DECOMPOSED (e + U+0301), and the current one
  writes its TITLES decomposed too — the role gate still fires because `Core/Text` folding strips
  `\p{Mn}`, pinned end to end by `CollectiveFixtureTest`. A February 2026 week sent a public link
  with no app id; it is not read, and would miss on every mail, which escalates. `tools/dump-eml.php`
  then took the tail of the SEQUENCE and so returned only 2025–Feb 2026 mails, so the live captures
  came through `ImapMailbox`'s own `SEARCH SINCE` — which the tool uses itself since 2026-09-25. **Stated costs:** no place, pay, contract, stack-by-card
  or date, so the five fixtures score 14–36 and go to the rollup under the deployed gate of 40; a
  re-posted offer gets a NEW id and is pushed again; the push opens the app page, which needs the
  developer's login.
- **Mindquest's click links are Mailjet's, and the offer id lives only in their last segment**
  (2026-09-26). An HTML-only alert, so the body path harvests each href after its anchor and a card
  reads `<title> [-] <dept> - <commune>` / `Mission de <n> jours|mois` or `CDI` / `Consulter l'offre`
  / `<account>.mjt.lu/lnk/<recipient>/<n>/<hash>/<base64url of the mission URL>`. `id_token_pattern`
  decodes that last segment and the push links the mission page, never the tracker. The title is
  greedy and may not end on a hyphen, so it splits at the LAST `<dept> - `, and a middle segment
  (`78 - CDI - Viroflay`) stays a label. The second line is the stated contract as written — a
  mission is never read as `freelance` (sabotage M20 pins that). **The scrubber learned three things
  from it, none of which its checks would have caught alone**: Mailjet serves each sender from its
  own subdomain (`z96x`, not Free-Work's `tx`), so the literal host matched nothing; a
  quoted-printable soft break fell INSIDE the subscriber address, which a plain `str_replace` missed
  while the fold-aware name needles rewrote half of it; and the recipient token also sits in
  `X-MJ-Mid` and in the `oo/` open pixel. A query-free click target now SURVIVES the scrub because it
  is the payload. **Stated costs:** no company, pay, work mode or date; with no mode stated **H8
  never fires**, so a Marseille CDI matches and scores low; `Le Pecq` and `Viroflay` are UNKNOWN
  places; the site's typo `DeveloperTypescript` fails the role gate; one missed card among others is
  silent. n=1 — one alert, delivered twice under two URL shapes, both read.
- **freelance-informatique.fr sends ONE offer per mail, links it directly, and its skills line is the
  only offer text anywhere in the job domain** (2026-09-26). HTML only; the card sits under `…votre
  profil :` as the title, the direct link, then `Date de début`, `Localisation`, `Durée` and
  `Compétences souhaitées`, each behind an emoji. The id is the last slug segment (`260924B004`),
  read after `?external_from=alerte` is stripped. It is why `card_pattern` gained a **`description`
  group**: the skills line becomes `JobListing->description`, so the stack score, H4 and the pay
  reader see it beside the title (a ledger case pins that the group reaches the offer). The scrubber
  had to drop SendGrid's `X-SG-EID` and `X-Entity-ID` headers first. **Stated costs:** no company,
  pay, work mode, contract or publication date — the site is freelance only and the card never says
  so; the start date is not read; the skills line is a few keywords, not the offer. The one fixture
  scores **31**, under the deployed gate of 40, so it waits for the rollup. n=1.

`src/phorj/` is **ON INDEFINITE HOLD** (developer ruling, 2026-08-19) — not blocked, deprioritised.
Do not start it; `docs/PHORJ-REQUIREMENTS.md` remains the record of what it would need.

**Q27's LIVENESS SIGNAL IS LIVE (2026-08-22), and it was ruled but unbuilt.** `HEARTBEAT_HOURS` (today `RENT_HEARTBEAT_HOURS`) sat
in `.env.example` read by no code at all: `NotificationKind::HEARTBEAT` existed, but only
`test-notify` used it, so a watcher that died at 03:00 was indistinguishable from one watching a
quiet market until somebody thought to look. `scout --domain=rent run --watch` now emits a LOW-priority beat
every `RENT_HEARTBEAT_HOURS` (default 24) — **whether or not anything matched**, which is the
entire point — carrying passes completed, listings notified and sources OK. **The startup beat is
`isDue()`-gated, not unconditional** (`RentScout::runCommand()`, the startup `isDue()` check — cited by LINE for one round, and the line moved twice; a symbol survives an edit above it): the marker is on the mounted volume, so a
restart inside the interval sends nothing, and only a cold start — no marker — beats immediately.
That is the correct behaviour (a redeploy loop must not spam the channel) but it is NOT a
channel-health check, and reading it as one costs time: a redeploy on 2026-08-23 15:48 left the
marker at the previous day's 22:15 and that looked like a fault for several minutes. To prove the
DEPLOYED image can reach the user, run `docker compose run --rm rent-scout test-notify`. `Core/Heartbeat` is the
pure policy (clock injected; a cold start is due, an unreadable marker is due, a marker in the
FUTURE is due — the bias is always one beat too many, never one suppressed), and the marker lives at
`state/rent-heartbeat.txt`, on Q8's mounted volume, so it survives the container being replaced. An
unusable `RENT_HEARTBEAT_HOURS` is a **loud refusal at startup**, not a silent fallback: `0` would
disable the one signal that distinguishes a dead watcher from a quiet market.

**Its health figure counts what the run WATCHES, not what the config enables** (fixed 2026-08-22,
found by running the real container). It read every enabled source, so `--watch --source=x` against
the shipped config reported *"1/5 source(s) en bon état"* — four faults that did not exist, in the
one channel whose entire value is that it can be believed; and it degrades, because an unpolled
source's health record goes `STALE`, so the beat would eventually alarm every day about sources
nobody asked it to watch. **The scope travels with the figure**: when a `--source` is in play the
beat says so and gives both counts, because silently reporting `1/1` would let a deployment with a
forgotten flag look flawless for ever while four landlords went unwatched. The banner states the
count too, but a banner is a log line read once and the beat is what reaches the phone.

Two things about that call site are worth knowing before touching it. **The in-loop beat — the one
that fires on day two — is unreachable under a fixed clock**, because the startup beat writes the
marker at `NOW` and every later check asks `isDue(NOW, NOW)`. That is what makes *"exactly one
beat"* assertable, and it also meant the loop's own call site was never executed by any test: the
argument added above lives outside the closure's `use` list, and the first genuinely due beat would
have thrown a `TypeError` and killed the watcher 24 hours into an unattended run. The way in is to
make the marker **unwritable** (a directory where the file goes): `beat()` writes it with
`@file_put_contents` precisely so a full volume cannot crash a liveness signal, `lastHeartbeat()`
reads `is_file()`, so every check is due — and two beats is then the *correct* result, per the
documented bias. All three guarantees are in `tests/sabotage-check.sh`.

Q27's other half landed with it: a startup refusal from `run` writes `state/rent-last-refusal.txt`, and
the next successful start reports it on the beat and clears it. That covers the failure that reaches
nobody — the process exits before any channel exists, and under Docker its stderr scrolls past in a
log nobody reads. **`ConfigError` and `SourceError` during `run` are recorded too**, because a
malformed config is the commonest startup refusal there is. The note is `Redact`ed before it touches
the disk — a `ConfigError` message quotes the offending VALUE back, which is exactly how a pasted
`imap://user:password@host` ends up in a file.

**No test reaches the network, and that is now structural rather than accidental.**
`tests/bootstrap.php` sets `SCOUT_OFFLINE=1` and `CurlHttpClient::send()` refuses any
third-party host (loopback stays allowed — the wire tests need a real socket). Before In'li was
enabled the offline guarantee held only because every source was disabled; enabling one turned the
suite into a four-page-per-test crawler of a live landlord's site within a single run.
`scout --domain=rent doctor|run --source=<name>` (repeatable) limits a run to one source, which is what onboarding
the next source needs and what keeps the CLI tests off the shipped source list.

**`--source=<name>` also FORCE-RUNS a source that is `enabled: false`** (2026-08-22), and only an
explicit name does — an ordinary pass still skips it, which is asserted separately because deleting
the enabled check is the over-correction. It is a repair, not a convenience: `/add-source` step 5
prescribes running `scout --domain=rent doctor` against a new block *before* flipping the flag, and that order was
impossible while a disabled source could not run. `dump` always behaved this way; the verbs now
agree. Three things travel with it. The run SAYS the source is disabled, because a `--source` left
behind in a deployment is otherwise indistinguishable from one somebody enabled on purpose. Hard
rule 1's `REMPLACER` refusal moved into `RentScout::buildSource()` — the single funnel every verb passes
through — because the loader's `enabled: true` check was the entire guard for as long as a disabled
source could never be polled. And hard rule 4's scraping opt-in still fires on a force-run private
portal, asserted, because the enabled check sits above it.

**`fixture_demo` is `enabled: false` for the same reason** — it had shipped enabled since before any
real endpoint existed, so a real pass reported *"5 source(s), 491 annonce(s) · 14 correspondance(s)"*
where 10 listings and 6 matches were fabricated. Nothing fake ever *pushed* under the documented
`--seed` → `--watch` flow (a frozen payload is never new after seeding), so the cost was every number
the operator reads — pass totals, `doctor`, `SourceHealth`, the Q27 beat — plus a real fake push on
any path that loses the seen-set. `ConfigTest::testNoFixtureSourceShipsEnabled` stops the flag
creeping back.

**Two more environment seams, both of them there so a test cannot become a hang or a crawl**
(2026-08-19). `SCOUT_MAX_PASSES=<n>` bounds `scout --domain=rent run --watch` to n passes; absent — the
normal case — the loop runs until stopped, and when it is set the watcher SAYS so on its banner
every time. `tests/php/Rent/Cli/RentScoutTest.php` sets it for every test in the class, because `--watch` is
the one verb whose success case never returns: a test that expects the run to be refused and is
wrong does not fail, it blocks, and it blocks the suite and the sabotage ledger behind it. That was
observed — disabling the Q36 guard made the ledger sit on its FIRST case for eleven minutes printing
nothing. `tests/sabotage-check.sh` now also runs each case under `timeout` (`SABOTAGE_SUITE_TIMEOUT`,
default 300 s) and counts a suite that never finished as a loud FAILURE, since a hang is not a
detection.

**The Q36 flood guard reads the ROWS, not the file** (fixed 2026-08-19). `scout --domain=rent run` refuses to
notify while `Store::isSeenSetEmpty()` — a missing volume mount produces a valid, empty, migrated
database indistinguishable from a healthy one, and every historic listing would push at once. The
guard used to ask whether `Store::open()` had CREATED the file, which any earlier command that
merely opened the database answered away: `scout --domain=rent doctor` opens it, so typing the first command a new
machine invites you to type disarmed the guard for the following run. Q36's other half — a mount
marker file — is WITHDRAWN rather than unimplemented; `docs/OPEN-QUESTIONS.md` records why it cannot
fire in either placement.

**The five sabotage gaps are closed as of 2026-08-12**, each verified individually by a targeted
mini-run going 7/7 red (the two fixed sed expressions included): honest User-Agent pinned;
SMTP-without-STARTTLS proven refused via a scripted loopback server and its wire transcript;
`SmtpTransport::secrets()` wiring proven by a server that echoes the base64 credential back; the
rent plausibility band exercised end to end; and a fifth found while closing them — the block-tag
test covered `</li>` while the sabotage degraded `</p>`; it now iterates the whole tag class. The
full-ledger count is recorded in `docs/plans/archive/milestone-1-pipeline.plan.md` as each run completes.

Anything below describing `enrich` or a real landlord endpoint is the **target**, not
the present. Do not report findings against files that do not exist yet, and do not name `pytest` as
though it were wired — the PHP suite is the only test runner here.

**Every question is closed as of 2026-08-07** — see [`docs/OPEN-QUESTIONS.md`](OPEN-QUESTIONS.md).
All 25 were resolved in one pass by applying each question's own documented default, on the
developer's instruction. **Nothing is blocking; milestone 1 proceeds.** A default applied is not a
preference expressed: every entry names the one line that reverses it.

Four things are still outstanding, and they are **inputs rather than decisions** — no default can
supply them: the DevTools cURL captures for the first sources (hard rule 1 forbids writing an
endpoint from memory), IMAP credentials for the alert mailbox, one real portal alert email to shape
the parser against, and the `plafonds de ressources` figures for classifier tier 4. **Three of the
four are now closed** — the alert email and the credentials arrived 2026-08-25, and the figures were
fetched and committed 2026-08-26.

**The alert email arrived on 2026-08-25 and the IMAP credentials with it, so that whole track is
CLOSED** — `seloger` is live (§ "The email-alert path"), and pointing the adapter at a real mailbox
cost six defects in one day: four in the MIME parser, one rent reading 600 € low, and four coliving
rooms scored as family flats. **A real payload is the input; a green suite is not a substitute for
one**, and 1 900 of them said nothing about any of the six.

**Bien'ici was the third of those, and it CLOSED the same evening** — the developer recreated the
alert with the current criteria, three messages landed within ninety minutes, and the source was
live. See § "Bien'ici" above. It confirmed the prediction that had been recorded here (a real
listing URL carrying a real listing id, so link identity works) and disproved the assumption
travelling with it: link identity was *unreachable* on a segmented source, because `identityFor()`
answered only for `id_from: content`. **A prediction about a payload is not a prediction about the
code that would read it.**

**leboncoin FIRED ITS FIRST ALERT ON 2026-08-26 and is source #7** (§ "leboncoin"). Before that
morning it had sent only a new-device notice, which is what the paragraph here used to record.

**PAP is the one portal still silent.** Two *Création de votre alerte* receipts and one
*Suppression*, all on 2026-08-25 at 19:29, and no search alert since — so one alert should survive
and is producing nothing. The ask is *check the surviving alert carries the current criteria*, not
*send a file*.

> **This paragraph also said "Jinka has sent a newsletter and no alert", and that was WRONG** — a
> mailbox census on 2026-08-26 found **two real alerts**, on 12 and 15 August, alongside the
> newsletter. The claim was written from a partial look and never re-checked, which is the same
> failure class as the retired *"live yield is 0"* entry: a confident statement about a source's
> behaviour, formed once and repeated. **Jinka is still not a candidate**, for a reason that
> survives the correction and is stronger than the old one: its `text/plain` part is **78 bytes
> total** — *"Bonjour, Sur votre alete Jinka, 1 nouvelles annonces ont été reçues"* — carrying no
> rent, no surface, no commune and no link, so everything is in the HTML and its links are
> `sendgrid.net` tracking redirects. It is also an AGGREGATOR rather than a portal, so it needs its
> own §1 evaluation before it is treated as a source: a truncated description can lose a `PLS` label
> the original listing carried.

The `plafonds` figures were reassigned the same day — hard rule 1 forbids writing a ceiling *from
memory*, not verifying one against a live authoritative source — and **they were fetched and
committed on 2026-08-26**, from two dated official publications, each carried in
`Core/PlafondBands` with its URL: the intermediate ceilings from BOFiP **BOI-BAREME-000017**
(published 2026-03-10, CGI annexe III art. 2 terdecies H), the social ones from the DRIHL's
*"Annexe 4 : grille des plafonds de ressources 2026"* (arrêté du 19 décembre 2025), both on the
2024 revenu fiscal de référence.

> **THE FIGURES REFUTED THE RULE EVERYONE ASSUMED WOULD BE BUILT, and that is the whole story of
> tier 4.** The assumption — at or below the highest social ceiling means social, above it means
> intermediate — fails twice against the real tables. **Even at the SAME household size**, zone B1's
> intermediate ceilings sit BELOW the Paris PLS ceilings for every size from two upward (B1 couple
> 48 268 € against PLS 52 303 €); only 13 of the 18 (zone, size) pairs separate at all. And a
> listing quotes a bare figure with **no household size**, so the sizes must be collapsed — at which
> point the bands overlap from 36 144 € to 109 595 €, a 73 451 € range. Under the assumed rule every
> genuine intermediate ceiling reads SOCIAL: not a §1 breach, since over-rejecting is the safe
> direction, but the tool switched off on the source producing most matches — and zone B1 is exactly
> where the current matches are (Dourdan, Dammarie-les-Lys). It is the numeric echo of a lesson the
> classifier already learned in words: `plafond de ressources` was rejected as a *text* signal
> because LLI has income ceilings too.
>
> **So tier 4 concludes in ONE direction only:** strictly below the lowest intermediate ceiling in
> Île-de-France (36 144 €, zone B1, one person) a figure cannot be an intermediate ceiling, so the
> financing is social. Above that it emits **nothing** — never an intermediate verdict, because
> manufacturing eligibility from a number is the §1-dangerous direction and `PlafondBands` refuses
> such a band *at construction*; and never a doubt either, because a numeric doubt would contradict
> a correct tier-2 label into the digest exactly as `loyer plafonné` once did to `lli-004` and
> `lli-011`. The threshold is DERIVED from the committed table, not written beside it, so the two
> cannot drift at the next January revaluation. **The boundary is strict**: 36 144 € IS an
> intermediate ceiling, and the scaffolding shipped with `<=`.
>
> **Stated cost:** it catches social listings quoting small-household ceilings without naming their
> scheme — PLAI at every size, PLUS for one to two people, PLS for one. It cannot catch a
> large-household social ceiling, and that is a property of the French figures rather than of the
> implementation.
>
> **The extraction is where the dangerous false positive lives**, not the arithmetic. It anchors on
> `plafond de ressources` and never a bare `plafond`; reads the negation first (`sans plafond de
> ressources` is ordinary private-market copy); applies an annual-income plausibility floor, twin of
> the rent band; examines every match rather than the first; and uses `\h` for thousands separators
> so a figure cannot assemble itself across a line break. A sabotage run showed the obvious
> demonstration of the anchor proves nothing — folded, `plafonné` is not `plafond`, and a rent is
> below the floor anyway — so corpus case `plafond-005` uses an intermediate ad quoting its own
> **rent** ceiling as a plausible annual figure, which defeats both other guards and leaves only the
> anchor standing.

## Status narrative moved out of CLAUDE.md (2026-10-02)

The paragraphs below filled lines 30-279 of `CLAUDE.md` (the "status" narrative after the opening state paragraph) and were moved here VERBATIM on 2026-10-02 because they are dated records, not rules; `CLAUDE.md` keeps a one-line ruling for each. They were NOT duplicated anywhere in `docs/` before this move.

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
