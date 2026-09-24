---
name: add-source
description: >
  Use when onboarding a new landlord or portal into config/rent/sources.json. Walks live-endpoint
  discovery, field-map building, fixture capture, tenure labelling and the health baseline so that
  adding a source stays config-only.
user-invocable: true
---

<!-- ═══════════════════════════════════════════════════════════════════════════════════
  rent-watch-native skill (2026-08-06) — not ported from the bundle. It exists because
  `spec/PROJECT_BRIEF.md` §6 makes "adding a source is config-only" an architectural
  requirement, and §14 makes "verify every endpoint live" a hard rule. Both are easy to
  violate one convenient shortcut at a time, so the workflow is written down.

  Questions here follow `.claude/skills/scout-ask-human/SKILL.md`: `AskUserQuestion`, options with
  the recommended one first and a visible challenge escape (re-inverted 2026-08-18).
═══════════════════════════════════════════════════════════════════════════════════ -->

## --help

> If ARGUMENTS contains `--help`: output the text below verbatim, then STOP.
>
> ```
> /add-source <name> — Onboard a new listing source into config/rent/sources.json, config-only.
>
> Flags:
>   --url <url>     Search page URL to start discovery from
>   --family <f>    institutional | private   (default: institutional)
>   --email-alert   Use the IMAP alert-ingestion path instead of HTTP polling
> ```

---

# /add-source — onboard a listing source

Adding a source must be **config-only** in the common case. A bespoke adapter under
`src/php/Adapters/sites/` is the fallback, not the default path. If you find yourself writing PHP here,
stop and say why config was not enough — a contract every source bypasses is not a contract.

> **SCOPE: this skill is the RENT domain** — `config/rent/sources.json`, the tenure classifier, the
> `à vérifier` digest. **A car source is a different file and a different vocabulary**, and running
> these steps against it produces a block the car loader refuses. For `config/car/sources.json`,
> keep steps 1, 3, 5 and 6 (find the real endpoint, freeze a fixture, prove a health baseline,
> report) and read the car-side params off `Car/VehicleSourceLoader::PATTERN_PARAMS` rather than the
> field-map tables below. The four that have no rent equivalent, each earned by a real portal:
>
> - **`facts_pattern`** — ONE regex with named groups (`body/fuel/year/km`, plus `title/make/model/
>   version/gearbox/price/ref`) for a labelled card. Configuring a second provider for a fact a
>   group already supplies is refused at load: two providers, one honoured, the other inert.
> - **`card_separator_pattern`** — a zero-width lookahead, for a card whose separator is the label
>   that STARTS it rather than a CTA that ends it.
> - **`make_model_unknown_pattern`** — the portal's own *I don't know* token (ParuVendu writes
>   `/autres/autres/`). Without it the sentinel is captured as a make, matches no `brand_avoid`
>   stem, and silently awards the whole brand share to a car on the avoid list.
> - **`id_from: content`** — for a portal whose every link is a per-recipient tracking redirect, so
>   `basename()` is a fresh id per message and the same id for every card in one. Pick the scheme
>   BEFORE the first enabled pass: nothing migrates a stored row between keys.
>
> Step 4 (tenure) has no car equivalent — the §1 vehicle set is CODE in `VehicleClassifier` and is
> not configurable — but `tests/test-vehicle-guard.sh` is the tripwire that watches it, exactly as
> `tests/test-tenure-guard.sh` watches the rent one.

> **A JOB source is a third file, `config/job/sources.json`, read by `Job/JobSourceLoader`** — two
> types, and the loader refuses a param the chosen type does not read (`READ_PARAMS`), so pick the
> type first. `email_alert` (LinkedIn) starts a card at its link in the HTML part and reads
> `card_link_pattern`, `place_pattern`, `footer_marker`, `pay_pattern`. `email_digest` (Free-Work,
> 2026-09-24) reads the TEXT part with ONE `card_pattern` naming `title`, `facts` and `url` (plus
> an optional `contracts`), an `id_pattern` over the URL, and `salary_pattern` / `tjm_pattern`,
> which only STATE the unit so `JobPay` stays the one reader of pay. Keep steps 1, 3, 5 and 6 as for
> a car source. Two things the job side adds, both from Free-Work: **a repeated id inside one
> digest may be the template** (alert sections overlap), so decide whether a repeat is furniture or
> a fault before the adapter warns on it; and **the job domain has no cross-source matching**, so
> check whether the portal states a company — without one, an offer on two portals is pushed twice.
> There is no §1 and no tenure step here.

---

## Step 0 — Which path?

| Family | Path | Why |
|---|---|---|
| **Institutional** (In'li, CDC Habitat, Cityloger/3F, AL'in…) | a JSON endpoint → `type: json`, or a server-rendered page → `type: html` | No serious anti-bot, and this is where the project earns its keep — nothing on the market aggregates them. **`json` is not the common case in practice**: all three live sources turned out to be server-rendered, so look for the XHR endpoint, and reach for `html` when there is none rather than inventing one. |
| **Private portal** (SeLoger, Leboncoin, Bien'ici, PAP, Logic-Immo) | `type: email_alert` | **Primary path, not a workaround.** Within ToS, defeats DataDome entirely because there is no bot, *faster* than polling (alerts fire on publication), and immune to markup churn. |

> **On an `email_alert`, ANCHOR EVERY READER POSITIONALLY BEFORE YOU TRUST A NUMBER.** The generic
> readers in `EmailAlertSource` are first-match-wins `preg_match`, and most portals print the
> SUBSCRIBER'S OWN SEARCH CRITERIA somewhere in the message — *"jusqu'à 1.200 EUR à partir de 45 m²"*.
> Bien'ici hit this through segmentation (3 of 13 surfaces read 45) and PAP hit it with no
> segmentation at all (the surface read 45 instead of 50, below `min_surface_m2`, so the first alert
> ever sent was rejected as too small — silently). **Five** per-source `params` regexes exist for
> positional anchoring: `title_pattern`, `commune_pattern`, `residence_pattern`, `surface_pattern`,
> `rooms_pattern`. Key them on a STRUCTURAL landmark the template guarantees — a
> `(NNNNN)` postcode line, the line above the `pièces` line — never on vocabulary someone typed.
> **A configured `title_pattern`/`surface_pattern`/`rooms_pattern` that MISSES yields nothing rather
> than falling back to the generic scan**, so a broken one shows as a fault instead of as a small flat.
> And measure, do not predict: run the real extractors against the real capture before writing config.
>
> **EIGHT patterns are compile-checked at load, not five** — this passage used to conflate the two
> sets and so hid three keys from every session that read it (C2 round-1 completeness lens,
> 2026-09-02). The other three are not anchors and serve different jobs: `subject_pattern` (the
> source's scope inside a shared mailbox), `card_separator_pattern` (the regex form of the segmenter
> — Bien'ici needs it, and the literal `card_separator` is the other form; configuring both is
> refused, and since 2026-09-02 so is either one on a `mixed_tenure` source), and
> **`advertiser_pattern`, which is §1-relevant**: it feeds `Core/LandlordRegistry`, so a card
> advertised by a bailleur is judged with that landlord's profile rather than the portal's `LIBRE`
> default. All eight are compile-checked because `matchParam()` uses `@preg_match`, which neither
> warns nor throws — a broken one is silent, and on `advertiser_pattern` the silence re-opens the
> exact hole the registry closes.
>
> **AND SINCE 2026-09-04 THE FIELD MAPS ARE CHECKED TOO, which is the other half of that surface.**
> A `map` or `detail_map` entry may carry a capture — `a@href => -(\d{5})/` — and that capture is a
> regex, applied by `Selector::captureFrom()` with the same `@preg_match`. It had no load-time check
> at all, so a broken one nulled its field for every item on every pass while the source returned
> its usual count, no run failed and `SourceHealth` stayed green: the In'li `cp` shape, where one
> dead selector meant a source matched zero flats while reporting `ok`. It is compiled with the
> delimiter the adapter will actually use, because a guard that compiles a different string from the
> one that runs is not a guard. A `=> prose:<reader>` capture is checked against the reader list
> instead, and an unknown reader was already refused the same way.
>
> Both refusals `card_separator_pattern` used to bypass are keyed on EITHER separator now, not just
> the literal one: the `mixed_tenure` §1 refusal above, and the one requiring a `link_host` on a
> segmented link-keyed source — without which that source re-notifies its whole backlog for ever.

Direct HTTP scraping of a private portal is opt-in only: `legal_risk: true`, disabled by default, and it
must **refuse to run** without an explicit flag. No CAPTCHA solving, proxy rotation, fingerprint
spoofing, or a dishonestly-impersonating User-Agent — ever (`CLAUDE.md` hard rule 5).

**Out of scope entirely:** `demande-logement-social.gouv.fr`, and Bienvéo unless its intermediate-stock
filter can be applied reliably at the source. Both are social-housing channels and violate §1.

---

## Step 1 — Find the real endpoint. Never write it from memory.

Hard rule 1. Placeholder URLs must read `REMPLACER` so they cannot be mistaken for verified ones, and a
source stays `enabled: false` until its URL has been confirmed against the live site.

**Before asking for a capture, check whether one is needed.** Every source live so far was
server-rendered and needed none: read `robots.txt` first, then fetch the search page and look for the
listings in the HTML. And check the site publishes vacancies AT ALL before going deep — **five** ranked
candidates (ICF Novedis, Seqens, 3F's own site, 1001 Vies, Batigère) turned out to publish no
availability this project can poll. The cheap pre-check, best form first: on WordPress ask
`wp-json/wp/v2/types`, which enumerates **custom** post types and so settles the "maybe the search is
JS-rendered" objection that a sitemap scan cannot — only core types means nothing to poll (1001 Vies);
`sitemap_index.xml` is the weaker version of the same question (Seqens); on any site scan the candidate
index page for `€`, `m²` and `disponib`.

**And a marker count is not a route census — submit the form before ranking the site.** A page can
scan as "a couple of markers, no lettings route" and *be* a lettings search: Logirep's homepage carries
exactly two `€` and two `m²` and no lettings link, and those four markers are a search form with
`ss_trnsctntp=leasing` checked by default. It POSTs to `/`, and its results route exists **only as a
303 `Location`** — `/recherche?…`, 113 leasing ads embedded as JSON in `drupalSettings`. On any
candidate that scans as "some markers, no route": read the `<form action>` and submit it with the
fields the page ships. **Do not follow the redirect blindly — read the `Location`, then check it
against `robots.txt` before fetching it.** Logirep disallows `/search/`, and robots matching is literal,
so it does not reach `/recherche`; one path over and the answer would have been no. Two corollaries.
On Drupal the analogue of `wp-json/wp/v2/types` is **`/jsonapi`** — an empty HTML doc there means the
module is off, which is a platform fact and not a verdict. And **a site's `sitemap.xml` may not be its
own**: every one of the 247 `<loc>` entries on Logirep's host points at a 403-ing sibling, which a
sitemap scan alone would read as a blocked empty site. Check the host inside `<loc>`.

**A search that answers "0 results" needs a CONTROL query, and a zero-marker homepage still needs the
form treatment.** A11's zero markers were as misleading as A12's two: Poste Habitat's real availability
search sits at `/Groupe-Poste-Habitat/Outils/Nos-logements-disponibles` and renders *"Pas de résultats"*
client-side, so nothing shows in the markup. Submitting it returns **0 dwellings** — a number that on
its own is equally consistent with *"publishes none"* and *"my POST was malformed"*. **Send a query you
expect to succeed in the same shape**: the same form answers **8** for `type-bien=parking` and **2** for
`commerce`, which proves the engine and the field names and makes the zero the site's. Read the criteria
echo too — that form silently dropped the `region` it was sent, so the 0 was national, not IdF. A filter
a form accepts and drops is invisible unless the page states what it applied. **Only submit a form that
reads as a SEARCH** (selects, checkboxes, hidden fields); a form with free-text name/email/message fields
is a contact or application form, and posting into a landlord's inbox is not a measurement.

**Scope every selector to the CARD, because page furniture can be genuinely social.** The known failure
class is now five instances deep and Erilia is the worst shape of it: its site-wide footer widget reads
*"Ai-je droit à un logement social ?"*, which is a correct tier-2 label and classifies **SOCIAL 0.90 →
REJECT**. Where CDC's *"au plus près"* vetoed one badge, a selector capturing the page here would reject
**every listing on the source** — and `SourceHealth` would stay green throughout, because the listings
are still fetched and counted. That is hard rule 2's silent failure arriving through the classifier
rather than the parser, and no test on the source's own count would catch it. `HtmlSource` keeps `_text`
off the detail path structurally; on the search path the card element is the scope, so never widen a
selector to a page-level container to "pick up more text".

**A client-rendered page is not a dead end — follow the widget to its API host before concluding
anything.** Zero `€`/`m²` on a large page means rendered elsewhere, not absent. Three greps, two
fetches: third-party `script src` hosts on the page → absolute URLs and quoted paths inside the widget
bundle (Batigère's named its own backend, `api.app.quadral-eservices.fr/api` + `/offers/offers`) →
**that** host's `robots.txt`, plus one unauthenticated probe of the path.

**Then read the robots status code as TWO verdicts, not one.** RFC 9309 §2.3.1.4: *unreachable* (5xx)
→ a crawler **MUST assume complete disallow**, and the standard is stricter here than this repo's own
posture — that is what stopped Batigère. §2.3.1.3: *unavailable* (4xx) → a crawler MAY access, so a 403
is blocked by this repo's stricter posture rather than by the standard. Record which one applies and
date it: a 500 can be transient, and a row that blurs the two overclaims. A 401 on the endpoint is an
independent stop — the only way past it is replaying the widget's credential, which hard rule 5
refuses outright.

If it really is an XHR app whose API host is crawlable, ask the developer for the DevTools capture —
they have a browser, you do not:

> Open the site's search page, set the filters you actually want (Île-de-France, T3+, ≥ 50 m², ≤ 1200 € CC — check `config/rent/criteria.json`, these moved twice on 2026-08-22), then
> DevTools → Network → Fetch/XHR → re-run the search. Copy the request as cURL and paste it here.

From the cURL, extract: method, URL, query params or JSON body, and the **minimum** headers that make it
work. Drop cookies and tracking headers; keep `Referer` and `Content-Type` if required. **Drop
`User-Agent` always — the loader refuses it** (hard rule 5: the client pins its own honest one, and a
DevTools capture's browser UA in config would be impersonation). If the endpoint only answers to a
browser UA, that source is telling you it blocks plain clients: use the email-alert route, never a
disguise. If the endpoint needs an authenticated session (AL'in typically does), say so explicitly
rather than half-building it.

## Step 2 — Write a minimal block, then iterate with `scout --domain=rent dump`

Start with `enabled: false`, an `items_path`, and no `map:`. Run `scout --domain=rent dump <name>` to print one raw
item, then fill `map:` from the real payload shape. `scout --domain=rent dump` is what makes this take five minutes
instead of an hour — if it does not exist yet, building it comes first.

Config is **JSON**, not YAML — ruled 2026-08-07 (Q22). The rationale of the day was that the cloud
container had no `ext-yaml` and could not install a parser; that container is dead, but the ruling
stands on its own merits, which are the two that follow. `sources.json` is an **object keyed by source name**, so a duplicate name is
impossible to write rather than merely discouraged. JSON has no comments, so any key beginning with `_`
is ignored by the loader (`_comment` by convention); **every other unknown key is a hard error**, which
is what keeps that convention from doubling as a typo swallower.

```json
{
  "sources": {
    "<name>": {
      "_comment": "why this endpoint, and anything the next reader would ask",
      "enabled": false,
      "family": "institutional",
      "type": "json",
      "default_tenure": null,
      "mixed_tenure": true,
      "url": "REMPLACER",
      "method": "GET",
      "headers": { "Referer": "https://…/" },
      "items_path": "results.items",
      "map": {
        "ref": "id",
        "title": ["title", "name"],
        "url": "url",
        "commune": ["city", "address.city"],
        "cp": ["zipCode", "address.postalCode"],
        "rent": ["rent.total", "price"],
        "charges_included": true,
        "surface": "surface",
        "rooms": ["rooms", "nbRooms"],
        "floor": "floor",
        "elevator": "hasElevator",
        "description": "description",
        "tenure_field": "financement"
      }
    }
  }
}
```

### Pagination, and the detail page — what sources #2 and #3 forced

A first cut reads page one and stops. Three sources in, neither half of that is enough:

| Key | When | Why it exists |
|---|---|---|
| `page_param` | the page number is a query parameter | the ordinary case |
| `page_path` | it is a suffix on the path (`/page-2`) | CDC Habitat disallows its *query* search in `robots.txt` but publishes a query-free path tree. Robots is then re-checked **per page**, because the path is what changes |
| `{page}` in `url` | it sits mid-path (`resultats-location-{page}-defaut-`) | Cityloger. Page one substitutes like every other page — pointing `url` at the site root so page one is the homepage widget works today and fails silently the day that widget becomes *featured* rather than ranks 1–10 |
| `embedded_json_selector` | the results are JSON inside a `<script>` tag (`type: json` only) | Logirep/Polylogis. Not pagination — listed here because it is the other "where do the items actually live" key, and its whole result set arrived in one payload with no pages to walk |
| `total_selector` | the page states how many results it has | **the walk's only proof.** Walking until a page comes back empty is a termination rule, not a correctness check: CDC's out-of-range page answers `301`, which ends a walk exactly like a genuine last page |

Exactly one pagination mechanism per source — two is refused at load, because whichever the adapter
picks, the ignored one fails silently.

**`embedded_json_selector`** is for a page that serves its results as JSON *inside HTML* rather than
as an API response — a shape neither adapter could read before Logirep (A12). Its search page ships
all 113 records in
`<script type="application/json" data-drupal-selector="drupal-settings-json">` while the visible
markup carries two `€` — the search form. `html` maps selectors over repeated card elements and there
is only ONE script tag; `json` parses the response body, which is HTML. Set the key on a `type: json`
source and the element's text is extracted first; everything after that — `items_path`, the field
map, `ListingMapper`, and so hard rule 9 — is the ordinary JSON path, unchanged. Three rules:

- **The loader refuses it on any type but `json`**, exactly as it refuses `detail_map` outside
  `html`. A key the adapter for this type will never read is worse than absent, because it looks
  like behaviour somebody switched on.
- **A selector matching nothing THROWS, and so does an empty element.** The likeliest way such a
  source breaks is the site renaming a `data-` attribute, and the payload then simply is not where it
  was while the response is still 200 and still a valid page. An empty list there reads as a quiet
  rental market forever (hard rule 3).
- **On a Drupal/Solr payload, expect every text field to be a ONE-ELEMENT LIST** —
  `"…address_locality": ["AVON"]`. `Payload::scalarOf()` unwraps that, and did not until 2026-08-22:
  before it, commune and cp read as `null` on all 113 rows, `matchesCommune()` refuses a null
  commune, and the source would have matched nothing ever while `SourceHealth` reported 113 items
  and a green status. If a new source maps cleanly but matches nothing, look here first.

**And check whether the card's rent is `h.c.` before mapping it.** Logirep quotes hors charges, and
its charges are not reliably recoverable — a `Charges locatives` field on one detail page (17%), free
prose on another (30%), nothing at all on a third. Two shapes, two ratios, one absence, so no uplift
is defensible. `charges_included: false` is the honest answer and `CriteriaEngine` is already built
for it (*"charges comprises, and never on an HC-only figure"*): the value lands in `rentHc`,
`max_rent_cc` never fires on it, and the score line says the ceiling is unverifiable. Never estimate
charges to make a rent comparable — that is hard rule 9's failure with extra steps.

**`detail_map`** is a second field map resolved against a listing's own detail page, for a source
whose card does not carry what the classifier needs. Cityloger's cards carry no tenure at all, so
without it every listing resolved `UNKNOWN` and went to the *à vérifier* digest forever — correct
under §1, and useless. Three rules, none of them stylistic:

- **It costs a request per listing, so THREE things bound it** (2026-08-23 — this replaces the
  single `matchesCommune()` gate, which made a listing's verdict depend on which pass looked at it).
  **The cache is the gate**: a detail page is read once, stored in `listing_detail`, and read back
  for ever after, so steady state is zero extra requests. **`detail_budget_per_pass`** (default 20)
  bounds the cold start when every listing is novel at once; writing `0` is **refused**, because a
  detail map that can never run is a disabled feature dressed as a configured one — omit the key
  instead if you want the default. **Priority** decides who gets a short budget: not-yet-seen first,
  then `Criteria::matchesCommune()`, then source order. `matchesCommune()` keeps its old role for
  its old reason — it is the only filter whose inputs the CARD carries in full, so ordering on it
  cannot act on a field the detail page would have filled (hard rule 8).
- **A detail page that will not load does NOT fail the pass.** It is recorded with its attempt
  count, retried past a 6 h backoff up to three times, then left alone — and `scout --domain=rent doctor` reports
  how many pages a source has given up on. Do not "fix" this by throwing: a throw voids the whole
  pass, so one dead page stops the source notifying anything at all. A robots refusal or a card with
  no `url` DOES throw, because those are states rather than events.
- **Its selectors address the LISTING, never the page.** Measured on Cityloger's frozen payload: the
  scoped `.description` classifies LLI 0.90; the same listing fed its whole detail page classifies
  UNKNOWN 0.00, because *"Commission d'attribution"* and *"demande de logement social"* are furniture
  sitting on social and intermediate pages alike.
- **It must not define `ref`** — identity comes from the card, and the loader refuses one here.
  A listing re-identified mid-pass has never been seen, so it is announced again on every run.

Anchor a `tenure_field` selector on its LABEL, not its position: Cityloger's financement value sits
in the third of three indistinguishable `table.table` elements, and selecting by position feeds a
DATE into the tenure field the day a label is added above it.

- `default_tenure` — hint only, the classifier still runs. See Step 4.
- `mixed_tenure` — the fail-closed switch. **Required**, never defaulted in the file. See Step 4.
- `charges_included` — `true` or `false`. Sources are inconsistent; normalise, never assume.
- `tenure_field` — the highest-value mapping. Look hard for it.
- `ref` — must be STABLE across runs. See Step 5.

A path may be a list — the first non-empty one wins.

## Step 3 — Capture a fixture. No network in CI.

```bash
scout --domain=rent dump <name> --raw > tests/fixtures/rent/<name>/search.json
```

**Scrub anything personal from the payload before committing it** — agent names, phone numbers, internal
IDs. Then write a parser test that asserts field-by-field against the fixture. The test must fail if the
field map drifts. Verify every `map:` path actually exists in the fixture: a mapping no fixture exercises
fails silently at runtime instead of loudly in a test.

## Step 4 — Label the tenure. This is the step people skip.

`default_tenure` is a **hint of last resort** (signal priority 5). The classifier always runs. What
matters here is telling the classifier where to look and whether the source mixes stock:

- Find the structured tenure field (`financement`, `typeProduit`, `categorie`) and map it to
  `tenure_field`. Signal priority 1 — highest confidence, worth real effort to find.
- **`mixed_tenure: true` is the default and the fail-closed direction.** Set it for any source that
  publishes social **and** intermediate stock on the same pages: CDC Habitat, Vilogia, Immobilière 3F,
  Seqens, 1001 Vies, ICF. On these, confidence `< 0.6` means `UNKNOWN` → *"à vérifier"* digest, never a
  match.
- `mixed_tenure: false` is **only** for a source that is provably pure one tenure (In'li = pure LLI).
  Getting this wrong disables the fail-closed rule for that source entirely, which is how a PLAI listing
  reaches a notification. When unsure, leave it `true`.

Then add at least **two** hand-labelled entries from this source to the classifier corpus: one clear
case and one that was hard to judge. Label them by reading the listing text, not by trusting the
source's own field.

## Step 5 — Stability and health baseline

- **Check the `ref` is stable.** Re-run `scout --domain=rent dump <name>` twice and compare the `ref` of the same
  listing. A ref that changes between runs re-notifies forever; so does a fallback hash over a title
  the site A/B-tests.
- A new source starts with **no baseline**, so breakage detection is blind for its first runs — say so
  rather than implying it is covered. Run it a few times, confirm the item count is stable, then:

```bash
scout --domain=rent doctor --source=<name>    # status, timing, item count — for THIS block only
```

**`--source=<name>` force-runs a source that is still `enabled: false`**, which is what makes the
order below possible at all. Until 2026-08-22 it did not: `sources()` dropped disabled definitions
before the flag was read, so the only way to get a run was to enable the block first — the edit to
committed config this flag exists to avoid, made under exactly the time pressure that makes people
forget to undo it. The run says the source is disabled, and a `REMPLACER` url is refused rather than
fetched, so force-running cannot poll something nobody verified.

Only flip `enabled: true` once `doctor` is green, the parser test passes offline, and the URL was
verified live.

## Step 6 — Report

State plainly: endpoint verified live (yes/no, and how), fields mapped, fields unavailable at this
source, whether rent is charges comprises, the `mixed_tenure` verdict **and why**, fixture path, item
count observed, and whether the `ref` was confirmed stable across two runs.

If the source turned out to be technically or legally impractical, say so and propose the email-alert
route instead of quietly building something fragile (`spec/PROJECT_BRIEF.md` §14).

$ARGUMENTS
