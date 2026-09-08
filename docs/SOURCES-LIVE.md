# The live source register — both domains

> **What this file is.** What is *enabled and polling today*, per domain: how each source is read,
> how a listing on it is identified, what it costs, and what it is known **not** to be able to tell
> us. One row per source, with the stated cost written down rather than discovered later.
>
> **What it is not.** It is not the candidate catalogue — why a landlord was measured and rejected
> is [`docs/SOURCES.md`](SOURCES.md), which covers the *rent* domain only and is a measurement
> history rather than a register. It is not the adapter reference either; that is
> [`docs/ARCHITECTURE.md` §6](ARCHITECTURE.md#6-adapters--the-only-site-specific-code).
>
> **Every figure below is dated.** Source counts drift with every alert; the register is derived
> from `config/<domain>/sources.json` and from a read-only query against the live stores on
> **2026-09-08**. Re-derive rather than trust: the commands are at the bottom.

---

## Rent — eight enabled sources, two tracks

**Two tracks, kept separate end to end** (ruled 2026-08-06). Track 1 is *institutional*: landlords
publishing their own stock, where an intermediate (LLI) flat is actually found. Track 2 is *private*:
the commercial portals, whose stock is market-rate (`LIBRE`) and which are reached **by email alert,
never by scraping** (hard rule 4). Identities, groups and price histories stay per track; since
2026-08-29 a flat found on both is **pushed once**, the direct route leading and the agency copy named
beneath it with its link.

| # | Source | Track | Adapter | Identity | Rent basis | Rows on record | Live since |
|---|---|---|---|---|---|---|---|
| 1 | **inli** | institutional | `html` + `detail_map` | site listing id | **CC** | 681 | 2026-08-19 |
| 2 | **cdc_habitat** | institutional | `html`, `page_path` pagination | site listing id | **CC** | 597 | 2026-08-20 |
| 3 | **cityloger** | institutional | `html` + `detail_map` | site listing id | **CC** | 65 | 2026-08-21 |
| 4 | **logirep** | institutional | `json` via `embedded_json_selector` | feed id | **HC** ⚠ | 145 | 2026-08-22 |
| 5 | **seloger** | private | `email_alert`, segmented | **content hash** ⚠ | **CC** | 1 068 | 2026-08-25 |
| 6 | **bienici** | private | `email_alert`, segmented | link (real ad id) | **CC** | 546 | 2026-08-25 |
| 7 | **leboncoin** | private | `email_alert`, HTML-only | link (real ad id) | **HC** ⚠ | 3 | 2026-08-26 |
| 8 | **pap** | private | `email_alert`, one listing per message | link (real ad id) | **HC** ⚠ | 97 | 2026-08-26 |

*Rows on record = distinct listings in `state/rent-watch.sqlite3` on 2026-09-08. It measures how much
a source has produced since it went live, not how healthy it is today — `scout --domain=rent doctor`
answers that.*

Two more blocks ship **disabled** and exist to prove the pipeline without polling anybody:
`fixture_demo` (a frozen payload) and `email_demo`. Both take an explicit `--source=` to run. The
first shipped *enabled* for two weeks and put ten flats that do not exist into every total.

### What each one cannot tell us

| Source | Stated cost |
|---|---|
| **inli** | Its cards carry almost no text — rent, rooms, surface, commune. A `detail_map` recovers the title, description, floor and lift; a listing whose detail page cannot be read is judged on its card alone, and `exclude_title_patterns` cannot fire on it. **It is not pure LLI**: two live listings state a `PLS` ceiling their cards never mentioned. |
| **cdc_habitat** | Mixed tenure by design — intermediate, market-rate and social stock in one result set. `robots.txt` disallows the parameterised search, so pagination walks a query-free path tree and robots is re-checked **per page**. |
| **cityloger** | Its search card carries **no tenure at all** (asserted by test, so the day one appears the test fails). Every listing therefore needs a second request, which is why detail hydration exists. |
| **logirep** | Rent is **hors charges** and the charges are not reliably recoverable — a field on one detail page, free prose on another, nothing on a third. **The rent ceiling is not checkable for this source**; the figure lands in `rentHc` and the score line says so. One request, no pagination — the cheapest source in the tree. |
| **seloger** | Sends **no listing URL and no listing id** — every link is a per-recipient redirect, so all cards in one alert collapse to a single identity unless the listing is content-addressed. The rent is deliberately **not** in that key: a price drop is an event, not a new flat. Its §1 residual is stated below. |
| **bienici** | The portal applies the saved search's own criteria before sending, so its hit rate is the best in the tree. Its card separator is the line each card *starts* with — copying SeLoger's terminal-CTA separator puts the alert's own criteria line inside the first card. |
| **leboncoin** | **No `text/plain` part at all** — every URL lives in an `href`, so the parser had to harvest them into the body or the source would have reported a quiet market for ever. Charges are mentioned nowhere: **the rent ceiling is not checkable for this source.** |
| **pap** | Direct-from-owner, so its inventory does not overlap the agency portals. It carries **no listing prose at all**, so `exclude_patterns` and `exclude_title_patterns` have nothing to scan and colocations cannot be filtered out — the push says so (`prose_absent: true`). Detail hydration is **refused**: the site answers a bot challenge, and solving one is out of scope. **The rent ceiling is not checkable for this source.** |

### The §1 residual, stated

`seloger`, `bienici`, `leboncoin` **and `pap`** are `mixed_tenure: false`, which is defensible for a
private-market portal. What holds regardless: an explicit `PLS`/`PLUS`/`PLAI`/`conventionné` label
anywhere in a card is caught by the tier-2 label rules, which **never consult that flag**. What does
not: a card stating no tenure at all takes the source default and matches. `pap`'s `prose_absent`
does **not** exempt it — a card carrying no prose states no tenure either, so it takes `LIBRE` by
exactly the same route; the flag names a filtering gap, not a tenure one.

Since 2026-09-01 `Rent\Core\LandlordRegistry` narrows this — a card whose advertiser names itself a
bailleur is judged with **that landlord's** profile — so the residual is now an **anonymous**
advertiser, or one advertising through an agency that does not name the landlord. It is a narrowing,
not a closure, and it needs a per-source `advertiser_pattern`.

Arming `mixed_tenure: true` on these would digest **100 %** of the source, because their cards state
no tenure at all — that is not §1 satisfied, it is the tool switched off.

---

## Car — six enabled sources

No tenure, no clustering across tracks, no detail hydration. The excluded-vehicle set
(`accidenté`, `gagé`, `opposition`, `épave`, VEI, VGE, *pour pièces*…) is in code and not
configurable, and negation is read first because every one of those terms arrives negated in honest
copy.

| # | Source | Kind | Adapter | Identity | Rows on record |
|---|---|---|---|---|---|
| 1 | **autohero** | dealer | `sitemap_jsonld` | site listing id | 3 947 |
| 2 | **paruvendu** | portal | `email_alert`, segmented | link | 203 |
| 3 | **leboncoin** | portal | `email_alert` | link | 5 |
| 4 | **lacentrale** | portal | `email_alert`, labelled cards | **content hash** | 39 |
| 5 | **agorastore** | auction | `email_alert`, labelled cards | **stated lot reference** | 32 |
| 6 | **capcar** | dealer | `email_alert`, labelled cards | **content hash** | 28 |

| Source | Stated cost |
|---|---|
| **autohero** | Seed before watching — a sitemap walk over a whole dealer inventory is the one cold start large enough to matter. |
| **paruvendu** | Its facts line comes in more shapes than the first capture showed; 11 % of stored cards once lost year, mileage, fuel and body **together** because one pattern required all four. A miss of that size is below the 100 % threshold at which the miss counter speaks. |
| **lacentrale** | **No year on the card**, so the age component is unscored on every La Centrale car (unknown, never 0). It truncates the title to ~28 characters, so mileage is often all that tells two otherwise identical cars apart. |
| **agorastore** | An auction: **no price** until it closes, and no year or mileage except inside free-text titles — deliberately not read out of them. The price ceiling never fires; most lots score *année inconnue* / *kilométrage inconnu*. |
| **capcar** | One alert a day at 18:00, four labelled cards each, `text/html` only. |
| **leboncoin** | Its car alerts are unlabelled, which is why its stored row count is small. |

**Why content-addressing here.** CapCar and La Centrale wrap every link in a per-recipient tracking
redirect, so the path's basename is a fresh id per message *and* the same id for every card in one.
The key is `sha1(source | folded title | year | km)` with the **price deliberately out of it**,
behind a no-information floor — a title **and** a year or a mileage, below which the segment is not a
card. Agorastore states a real lot reference and uses it, which waives that floor (three of five lots
state neither year nor mileage, and the reference already supplies the evidence the floor demands).

> **Pick the identity scheme before the first enabled pass.** Nothing migrates a stored row from one
> key to another, so switching later re-notifies the whole backlog.

---

## The two rules that decide whether a source is worth adding

Learned the expensive way; both are in [`docs/SOURCES.md`](SOURCES.md) with their measurements.

1. **Pollable and useful are different columns.** One catalogued portal has a clean, stable,
   GET-paginated search quoting rent `cc` on all 49 listings — and **zero of the 49 are in
   Île-de-France**. A register recording only the first keeps proposing work that cannot pay.
2. **A marker count is not a route census.** Counting `€` and `m²` on a homepage does not tell you
   whether a lettings search exists: one source's four markers *were* a search form, POSTing to `/`
   with its results route existing only as a `303 Location`. Submit the form, read the `Location`,
   and check **that** path against `robots.txt` before following it — matching is literal, so one
   path segment over and the answer flips.

Two more that cost a whole day each: **a client-rendered search is not a dead end** (follow the
widget's `script src` to its bundle, the bundle to its API host, then check that host); and **a search
answering 0 needs a control query** (0 dwellings means nothing until the same form returns 8 parkings).

---

## Re-deriving this register

```bash
# what is configured, per domain
php -r '$j=json_decode(file_get_contents("config/rent/sources.json"),true);
        foreach($j["sources"] as $k=>$s) printf("%-14s %-12s %-14s enabled=%s\n",
          $k,$s["type"]??"-",$s["family"]??"-",var_export($s["enabled"]??false,true));'

# what each has actually produced (read-only, writes nothing)
sqlite3 "file:state/rent-watch.sqlite3?mode=ro" \
  "SELECT source, COUNT(*), SUM(notified_at IS NOT NULL) FROM listings GROUP BY source ORDER BY 2 DESC;"
sqlite3 "file:state/car-watch.sqlite3?mode=ro" \
  "SELECT source, COUNT(*), SUM(notified_at IS NOT NULL) FROM vehicle_listings GROUP BY source ORDER BY 2 DESC;"

# what each says about itself right now — NOTE: this polls, and writes a run into the baseline
bin/scout --domain=rent doctor
bin/scout --domain=car  doctor
```

> ⚠ **A fixture-backed `doctor` writes a run into the live store.** `MAILBOX_DIR=` swaps the mailbox;
> it does **not** swap the database, so the fixture's item count joins the 7-day baseline every later
> live run is judged against — that is how a healthy source is made to report `broken`. Always pair
> `MAILBOX_DIR=` with a throwaway DB:
>
> ```bash
> RENT_SCOUT_DB=$(mktemp -u) MAILBOX_DIR=tests/fixtures/rent/pap \
>   bin/scout --domain=rent doctor --source=pap
> ```

To add a source, use the `/add-source` skill: it walks live-endpoint discovery, field-map building,
fixture capture, tenure labelling and the health baseline, so that adding one stays **config-only**.
`src/php/Adapters/sites/` is the bespoke-adapter fallback — **it does not exist**, because fourteen
sources have been onboarded and none has needed it. Having to create it is itself the finding.
