# Source catalogue — verified 2026-08-06; A2/A3 re-measured 2026-08-20; A5–A13 re-measured 2026-08-20; A12 measured 2026-08-21 and BUILT 2026-08-22; A11 + A13 measured 2026-08-21

> **NINE rows changed on 2026-08-20, and they all changed for one reason: a `200` was read as
> a feed.** The second pass (A5–A13) is why the Tier A count is now much smaller than it looks:
> Seqens and 3F publish no vacancies at all on their own domains, and the reason turned out to be
> structural rather than per-site — see § "The Action Logement ESH do not publish their own
> vacancies". One genuinely new feed came out of it, **A6b Cityloger**, found from 3F's own page.
>
> **Two rows changed earlier that day and both changed for the same reason: a `200` was read as
> a feed.** A2 answers 200 and publishes no vacancies at all; A3 answers 200 and disallows far
> more than this table said, while advertising a different route in its own sitemap. Neither
> was knowable without fetching, which is what the preamble below already says — it just had
> not been done for those two.

> **Every status in this file was measured, not recalled.** `CLAUDE.md` hard rule 1 forbids writing an
> endpoint from memory, so each domain below was fetched once with an honest User-Agent
> (`rent-watch/0.1 (personal housing search; +<repo url>)`), ~1.2 s apart, and each Tier A site's
> `robots.txt` was read before it was listed as pollable.
>
> **A `200` here means "the site exists and answered".** It does **not** mean an endpoint has been
> found. No search/XHR endpoint has been reverse-engineered yet — that is per-source work, done with
> `/add-source`, and every `url:` in `config/rent/sources.json` stays `REMPLACER` until it is confirmed live.
>
> Scope, settled 2026-08-06 (`docs/OPEN-QUESTIONS.md` Q4): **LLI + LIBRE in scope. PLAI, PLUS, PLS,
> ANRU, ANAH out — no social housing.** Every mixed-tenure source therefore carries a real risk of
> surfacing something out of scope, which is what the classifier and `mixed_tenure: true` exist for.

---

## The single most useful thing this round found

**HTTP 403 to an honest non-browser User-Agent tracks the tier almost perfectly.**

| | 403 (blocks a plain client) | 200 (answers) |
|---|---|---|
| **Tier A** institutional | 1 of 15 (Vilogia) | 14 of 15 |
| **Tier B** private portals | 5 of 12 | 7 of 12 |

Every 403 in Tier B is a portal the brief already said to reach by **email alert** rather than HTTP —
Leboncoin, PAP, Logic-Immo, A Vendre A Louer, Gens de Confiance. So the architecture's central bet is
now backed by measurement rather than assumption: the sites that resist automation are exactly the ones
where a native alert subscription is the correct route, and the sites worth polling mostly welcome it.

This is not a reason to work around a 403. It is the reason not to have to.

**Read this claim narrowly.** It says a 403 predicts *which route to use*, and it holds. It does NOT
say a 200 predicts a pollable feed — five Tier A rows answered 200 and turned out to publish no
availability this project can poll (A2, A5, A6, A7, A10, **and now A11**). "The sites worth polling
mostly welcome it" was the optimistic reading, and the tally that survives measurement is
**4 of 15 built** (In'li, CDC Habitat, Cityloger, and **A12 Logirep/Polylogis — built 2026-08-22**,
`scout --domain=rent doctor --source=logirep` → **113 annonces, 428 ms, `ok`**). A12 is also the counter-example in the other direction: it answered
200, scanned as the *weakest* "some markers" row, and turned out to carry a structured 113-ad leasing
feed. A cheap scan is evidence about a page, never a verdict about a site.

**Track 1 is now MEASURED OUT, and this time the word is earned** (2026-08-21): A11 and A13 were the
last two rows resting on a marker scan, and both have been submitted, walked and counted. Note what
the exhaustive pass actually produced — **a third distinct verdict**. A11 is the sixth "200, no feed".
A13 is the first row that is genuinely *pollable and worthless*: a clean, stable, well-shaped
49-listing feed with **zero** stock in Île-de-France. "Pollable" and "useful" are different columns,
and a catalogue that only records the first will keep proposing work that cannot pay.

---

## TWO TRACKS, kept separate end to end (ruled 2026-08-06)

*"both! but have them separate! with two emails and two notification!"*

| | **Track 1 — INTERMEDIATE** | **Track 2 — PRIVATE** |
|---|---|---|
| Tenure | LLI / logement intermédiaire | LIBRE / loyer libre |
| Sources | Tier A below | Tier B below |
| Access | HTTP JSON endpoints (mostly welcome it) | Email alert over IMAP (mostly 403 a plain client) |
| Mailbox | its own | its own |
| Notification | its own | its own |
| Seen-set / dedup | **per track** — a flat listed by In'li AND on SeLoger is two findings, not a duplicate, because the application route differs. **AMENDED 2026-08-29 (developer ruling): two findings, ONE push.** Identities and histories stay per track; cross-track twins are linked for notification AND for §1 — an excluded or undetermined twin on the other track vetoes/digests the flat, and the fact is persisted (schema v12, 2026-08-30) — the first seen is pushed naming both routes, the twin is marked notified instead of pushed, and an institutional copy arriving after the private one is still pushed once as the direct route. Measured before the ruling: 43 flats pushed twice | |

The split is a first-class concept, not a filter flag: two mailboxes, two notification targets, two
digests, and `--track intermediate|private|both` on every command.

### The Action Logement clarification — this shrinks Track 1 a lot

*"enumerate all action logement in ile de france"* — measured answer: **Action Logement Immobilier holds
42 ESH plus exactly ONE logement-intermédiaire subsidiary.** That one is **In'li**.
[Source: groupe.actionlogement.fr/nos-filiales-immobilieres, via search 2026-08-06 — the page itself
403s a plain client.]

So "all Action Logement" resolves to a much cleaner answer than a 42-entity list:

- **In'li IS the Action Logement intermediate arm.** One entity, and it is the flagship of Track 1.
  ~60 000 units across IDF, covering both 78 and 95.
- The other **42 are ESH — Entreprises Sociales pour l'Habitat, i.e. social landlords.** They are out of
  scope by the no-social-housing ruling. They appear in Tier A below **only** because several
  (Seqens, Immobilière 3F, 1001 Vies, Antin) publish a *minority* of intermediate stock alongside their
  social stock. That is precisely the `mixed_tenure: true` case, and it is why the classifier exists.
- **For other regions** ("eventually any region"): In'li has regional arms — **In'li Aura** is confirmed
  (Auvergne-Rhône-Alpes, Annecy). *[Unverified: the full list of In'li regional entities.]* The design
  consequence is the useful part — region must be **config, not code**, so a new region is a
  `config/rent/sources.json` block plus a commune list, never a code change.

### The Action Logement ESH do not publish their own vacancies — they delegate to AL'in (measured 2026-08-20)

Seqens (A5) and Immobilière 3F (A6) were probed independently and both ended at the same place: their
own sites carry no vacancy feed, and their "I'm looking for a home" route links out to
**`https://al-in.fr/`**. Antin (A8) is the same group and the same shape as far as one fetch shows.

Three consequences, and the first is the one that changes plans:

- **A4 AL'in is not "one more source" — it is the ONLY route to the Action Logement ESH stock.** It was
  ranked fourth on the strength of being employer-reserved and hard; it should be read as the gateway to
  A5, A6 and A8 combined. Its authenticated session stops being an obstacle to one source and becomes
  the price of an entire group.
- **In'li is the exception that explains the rule.** It publishes its own feed because it is the group's
  *intermediate* arm and allocates directly — no commission, no SNE number. The ESH delegate because
  their stock is allocated by commission, through the social channel.
- **A `200` still is not a feed.** Five rows in this table (A2, A5, A6, A7, A10) were ranked on
  portfolio size and each turned out to publish no availability this project can poll. The cheap
  pre-check that would have caught them, before any deep crawl: on WordPress, ask the REST API
  directly — `wp-json/wp/v2/types` enumerates every post type including custom ones, so a site with
  only core types has **no listings to poll and no JS-rendered search hiding one** (1001 Vies, settled
  2026-08-21); `sitemap_index.xml` is the weaker version of the same question (Seqens); and on any
  site, scan the candidate index page for `€`, `m²` and `disponib` — a directory of buildings has none
  of the three.

### A client-rendered search is not a dead end — follow the widget to its API, then check THAT host (2026-08-21)

Batigère (A10) is the shape worth knowing, because the first three signals all said "keep going" and
the fourth stopped it dead. Its offers subdomain answers 200 with ~197 KB and **zero** `€`/`m²`, which
means client-rendered rather than absent — so the question becomes *which* backend renders it. The
route to that answer is three greps and costs two fetches:

1. Grep the page for third-party `script src` hosts. Batigère's search is a vendor widget:
   `static.app.quadral-eservices.fr/static/quadral-map-search-engine`.
2. Fetch the widget bundle and grep it for absolute URLs and quoted paths. It names its own backend —
   **`https://api.app.quadral-eservices.fr/api`**, with `/offers/offers`, `/offers/programs`,
   `/offers/references`, `/offers/leads`.
3. Check **that** host's `robots.txt`, and probe one path unauthenticated. Both answers were stops.

Two rules fell out of it, and they generalise past Batigère:

- **The robots status code is not one verdict, it is two.** RFC 9309 §2.3.1.4: an *unreachable* robots
  (5xx) means a crawler **MUST assume complete disallow** — the standard is stricter than this project
  is, and `api.app.quadral-eservices.fr` answers **500**. §2.3.1.3: an *unavailable* robots (4xx) means
  a crawler MAY access, so the **403** on `offres.batigere.fr` is blocked by this repo's own posture
  rather than by the standard. Record which one applies; a row that blurs them overclaims.
- **Quadral is multi-tenant, so this is a lead and not only a dead end.** `api.app.quadral-eservices.fr`
  serves Batigère through a `/offers/leads/batigere` path, which implies sibling landlords behind the
  same API. Any future candidate whose search is a `quadral-` widget is the same 401 and the same 500 —
  check for it early. And a 500 can be transient: re-check before treating A10 as permanent.

### A marker count is not a route census — submit the form before ranking the site (2026-08-21)

Logirep (A12) is the shape worth knowing in the *opposite* direction to Batigère, and it nearly cost
this project its best remaining Track 1 source. Its homepage carries exactly **two `€` and two `m²`
and no lettings link at all** — which is what the 2026-08-20 scan measured, and on that basis the row
was ranked "weakest of the 'some markers' group". Both numbers were right. The conclusion was wrong:
those four markers *are* a full lettings search form, `ss_trnsctntp=leasing` ("Louer") checked by
default, and it POSTs to `/`. The results route exists **only as a 303 `Location`** —
`/recherche?ss_trnsctntp=leasing&…` — so it can never appear in the markup a marker scan reads.

The check costs one POST, and it is worth doing on any candidate that scans as "some markers, no
route": read the `<form action>`, submit it with the fields the page ships, and **do not follow the
redirect blindly — read the `Location` first and check it against `robots.txt` before fetching it.**
Here that mattered: Logirep disallows `/search/`, and robots path matching is literal, so `/search/`
does not reach `/recherche`. Had the results landed one path over, the answer would have been no.

Corollary, learned the same day: **a site's own `sitemap.xml` may not be its own.**
`logirep.polylogis.immo/sitemap.xml` lists 247 URLs and **every one of them is on
`scalis.polylogis.immo`**, a sibling that answers 403. A sitemap scan alone would have read that as a
blocked, empty site. Check the host in the `<loc>` before drawing anything from a sitemap.

### A search that answers "0 results" needs a CONTROL query (2026-08-21)

A12 said *submit the form*. A11 says what to do with the answer. Poste Habitat's availability search
returns **0 résultat** for `type-bien=logement_location`, and on its own that number means nothing —
it is equally consistent with *"this landlord publishes no dwellings"* and with *"the POST was
malformed, the session lacked a cookie, or the field name changed"*. The two readings send the
catalogue in opposite directions, and only one extra request tells them apart.

**Submit a query you expect to SUCCEED, in the same shape, and check it returns non-zero.** Here the
same form with `type-bien=parking` returns **8** and `commerce` returns **2**, so the engine works,
the field name is right, and the zero belongs to the site. Without those two requests the row would
have read *"0 results — probably delegated"*, which is an inference wearing a measurement's clothes.

Read the criteria echo, too, not just the count: this form replies *"Rappel de vos critères : Type de
bien : Logement (location)"* and says nothing about the `region` that was also posted — so `region`
was silently ignored, and the 0 is a NATIONAL zero rather than an Île-de-France one. A filter that a
form accepts and drops is invisible unless the page states what it applied.

## Tier A — Track 1: institutional, intermediate / LLI. **Where this project earns its keep**

Nothing on the market aggregates these. Status column = HTTP response to a single polite GET.

| # | Source | Domain | HTTP | Tenure mix | Notes |
|---|---|---|---|---|---|
| **A1** | **In'li** | `www.inli.fr` | **200** | **Pure LLI** | ⭐ The flagship. ~60 000 units across IDF, **covers both 78 and 95**, rents ~20–30 % below market. `robots.txt` disallows only `/espace-membre/`. `mixed_tenure: false`. **Build first.** |
| **A2** | **ICF Habitat Novedis** | `www.icfhabitat.fr/patrimoine/icf-novedis` | **200, but NO VACANCY FEED** | **Intermediate + loyer libre only** | ⛔ **NOT POLLABLE — measured 2026-08-20, three levels deep.** ICF Habitat's *non-social* arm (10 000 units aimed at *"personnes dont les revenus dépassent les plafonds sociaux"*, SNCF group, rail-corridor stock) was ranked second on PORTFOLIO value. Its site publishes a **patrimoine directory, not availability**: `/patrimoine/icf-novedis` → `/patrimoine/filiale/icf-novedis/78-yvelines` → `/patrimoine/localites/icf-novedis/78500-sartrouville` all list *résidences* ("Il y a 8 résidence(s)"), with **zero rents, zero surfaces and zero occurrences of `disponib`** on any of the three. `robots.txt` is stock Drupal and irrelevant here — there is nothing to poll. Remaining routes, in order: an email alert if ICF offers one, or the portals. `novedispm.fr` is the third-party property-management arm, a separate site — *[Unverified: not fetched; capped deliberately rather than crawled]*. |
| **A3** | **CDC Habitat** | `www.cdc-habitat.fr` | **200** | Mixed — **and genuinely so** | ✅ **LIVE since 2026-08-20 — the second verified source, and the first mixed-tenure one.** robots is **stricter than this table recorded**: it disallows `/Recherche/show/`, `/Recherche/search` **and seven search QUERY PARAMETERS by name** (`?cdTypeBien`, `?nbSurfaceMin`, `?nbSurfaceMax`, `?cdCategorieLogement`, `?nbPiece`, `?nbLoyerMin`, `?nbLoyerMax`), so the parameterised search is off limits entirely. What is pollable is the route the site **advertises in its own `sitemap.xml`**: the lowercase, query-free `/recherche/location/<region>` tree — robots path matching is case-sensitive, so `/Recherche/…` does not cover `/recherche/…`. Server-rendered, `134 logements disponibles` for Île-de-France at 16/page, paginated by **path** (`/page-2/`, never a query string). Frozen at `tests/fixtures/rent/cdc_habitat/search.html`, with `robots.txt` frozen beside it and asserted per page by test. |
| **A4** | **AL'in** (Action Logement) | `www.al-in.fr` | **200** | Mixed | Employer-reserved stock. Likely needs an authenticated session. High value (less competition), hardest to build. |
| **A5** | **Seqens** | `www.seqens.fr` | **200, but NO VACANCY FEED** | n/a | ⛔ **NOT POLLABLE — measured 2026-08-20.** `robots.txt` is clean (`/wp-admin/` only), and there is nothing behind it to poll. Its Yoast `sitemap_index.xml` enumerates every public post type — `post`, `page`, `beetween`, `evenement`, `job`, `metiers`, `publication`, `question_faq`, `realisation`, `theme_faq`, `profil_ideal` — and **none of them is a listings type**. The whole `/louer/` section offers exactly three routes: a *local commercial*, a *parking*, and *un logement social* — and that last page's own outbound link is **`https://al-in.fr/`**. `patrimoine/nos-residences-hlm/` is a résidence directory with **zero** `disponib`, `€` or `m²`, i.e. A2's shape exactly. Seqens does not publish its vacancies; it delegates them to AL'in (A4). |
| **A6** | **Immobilière 3F** | `www.groupe3f.fr` | **200, but NO VACANCY FEED ON THIS DOMAIN** | Mixed | ⛔ **The corporate site is not pollable — measured 2026-08-20.** Drupal, stock robots (`/search/` and `/search?` disallowed, `/location` allowed). `/location` is editorial: zero `€`, `m²` or `pièce`. `/je-cherche-un-logement` carries no listings either and its outbound routes are **`al-in.fr`**, `logement-actionlogement.fr` and **`cityloger.fr`**. The stock is real; it is published elsewhere — see A6b. |
| **A6b** | **Cityloger** (the 3F group's own lettings platform) | `www.cityloger.fr` | **200 — REAL FEED, verified 2026-08-20** | Mixed, national | ✅ Pollable today, and the shape is good: `robots.txt` disallows only `/composants/ /classes/ /include/ /dpe/ /newdpe/` — nothing near the search. Results are **server-rendered** and paginated by PATH through an infinite-scroll partial, `resultats-location-{page}-defaut-`, which is **stateless GET** (pages 2 and 3 return disjoint sets with no cookie or token) and terminates cleanly on an empty page. Cards carry filiale, type, rooms, postcode, commune, address, rent **cc**, and floor. **The catch is volume and tenure placement:** the whole national inventory is **51 rentals**, of which **3 are IDF** (2×92, 1×77) — and the tenure lives on the DETAIL page, never on the card. Detail pages do carry the tier-1 field (`Financement`: `LI15P` on the Antony T4, `PEXNC` on an Occitanie social one). ⚠️ Those detail pages also carry generic boilerplate — *"Numéro de demande de logement social"*, *"Commission d'attribution"*, *"catégories de logements sociaux"* — sitting on a listing whose own financement code is **intermediate**. That is the CDC `au plus près` failure class again, on a new surface. |
| **A7** | **1001 Vies Habitat** | `www.1001vieshabitat.fr` | **200** | Mixed | ⛔ **NOT POLLABLE — settled 2026-08-21, and it is the A5/A6 delegation pattern a third time.** The 2026-08-20 row left this open because "a JS-rendered search would not show in either signal"; the WordPress REST API answers that directly. `wp-json/wp/v2/types` enumerates **only core types** — `post`, `page`, `attachment`, `nav_menu_item`, `wp_block`, `wp_template`, `wp_template_part`, `wp_global_styles`, `wp_navigation`, `wp_font_family`, `wp_font_face` — so there is no listings post type to poll, and no custom one hiding behind the sitemap. Nor is the search rendered client-side by somebody else's widget: `/devenir-locataire/` and `/nos-residences/` carry **zero** `€`, `louer` or `annonce`, and their only third-party script hosts are a cookie banner, YouTube, LinkedIn and Twitter — no offers widget of the Quadral shape found at A10. What `/devenir-locataire/` links out to is **`www.demande-logement-social.gouv.fr`**, which is out of scope entirely (§1, hard rule 5): 1001 Vies routes its lettings through the SNE, exactly as Seqens and 3F route theirs through AL'in. **Domain corrected** — not `1001vies-habitat.fr`. `robots.txt` sets `Content-Signal: use=reference` and blocks named AI crawlers; `rent-watch/1.0` is a generic client and unaffected — moot now. |
| **A8** | **Antin Résidences** | `www.antin-residences.fr` | **200** | Mixed | ⛔ **The one lettings route this table recorded does not exist — measured 2026-08-21: `/louer-acheter` answers 404** (a 109 KB styled error page, which is why a size-only check would have passed it). Zero `€`, zero `disponib`, and no outbound `al-in.fr` link on that page to confirm the delegation prediction either way. Action Logement group, so the A5/A6 finding still predicts AL'in delegation — but that is now a prediction with one refuted route behind it, not a measurement. Do not rank it as pollable. *[Unverified: the real lettings route, if any, was not searched for — capped deliberately rather than crawled.]* |
| **A9** | **Vilogia** | `www.vilogia.fr` | **403** | Mixed, has an intermediate line | Blocks a plain client. Treat as Tier B in practice: email alert, or skip. |
| **A10** | **Batigère IDF** | `www.batigere.fr` | **200** | Mixed | ⛔ **NOT POLLABLE — measured 2026-08-21. It was ranked ⭐ best-remaining on a 200 and a page size; the feed behind it is authenticated and its host forbids crawling.** `offres.batigere.fr` is **Liferay**, and the search is a third-party widget — `static.app.quadral-eservices.fr/static/quadral-map-search-engine` — whose bundle names its own backend: **`https://api.app.quadral-eservices.fr/api`**, paths `/offers/offers`, `/offers/programs`, `/offers/references`, `/offers/leads`. Two independent stops, either alone sufficient: (1) **`api.app.quadral-eservices.fr/robots.txt` answers 500**, and RFC 9309 §2.3.1.4 is explicit that an *unreachable* (5xx) robots means a crawler **MUST assume complete disallow** — this is the standard's own conservative branch, not merely this project's posture; (2) a plain `GET /api/offers/offers` answers **401**, so the feed is not public and the only way in would be replaying the widget's credential, which hard rule 5 refuses outright. The **403** on `offres.batigere.fr/robots.txt` (Microsoft-Azure-Application-Gateway) is a weaker stop and should be read as such: RFC 9309 §2.3.1.3 treats 4xx as *unavailable*, under which a crawler MAY access — it is this repo's stricter posture that blocks it, not the standard. No email-alert fallback either: `alerte` appears **zero** times on the offers page. A 500 can be transient, so this row invites a re-check on another day rather than closing the source forever. |
| **A11** | **Toit et Joie → Poste Habitat** | `www.postehabitat.com` | **200** | Mixed | ⛔ **NOT POLLABLE — measured 2026-08-21, and it is the A5/A6/A7 delegation pattern a FOURTH time.** **Domain corrected** — `www.toitetjoie.com` answers **301 → `www.postehabitat.com`** (measured without `-L`: a single hop, `redirect_url` verbatim): Toit et Joie is now the Île-de-France entity of Groupe Poste Habitat (`(societe)/4629`), beside Provence, Rhône-Alpes and Normandie. `robots.txt` is **404** — RFC 9309 §2.3.1.3 treats 4xx as *unavailable*, under which a crawler MAY access; note this repo's `Robots::unavailable()` fails CLOSED, so enabling a host that serves no `robots.txt` would need a ruling first. Moot here — there is nothing to poll. **The 2026-08-20 zero-marker scan was right about the markers and wrong about the site, exactly as at A12.** There IS a real availability search at `/Groupe-Poste-Habitat/Outils/Nos-logements-disponibles`: a selects-only form (`type-bien`, `type-achat`, `region`, `localisation`) POSTing to `…/Nos-logements-disponibles/Resultats`, whose result area renders client-side as *"Pas de résultats"* — invisible to a marker scan. **Submitting it settles the row, and the CONTROL queries are what make the answer trustworthy**: `type-bien=logement_location` → **0 résultat**, `logement_achat` → **0**, `parking` → **8**, `commerce` → **2**. The engine works and returns non-zero for two of its four categories, so the zero is the site's, not the query's: Poste Habitat publishes **no dwellings at all**, to rent or to buy. Its actual lettings route is `/Toit-et-Joie-Poste-Habitat/Se-loger-chez-nous/Demande-de-logement-social`, whose only outbound link is **`demande-logement-social.gouv.fr`** — out of scope entirely (§1, hard rule 5). `sitemap.xml` is **404**. ℹ️ **One thing here is worth keeping**: `/Toit-et-Joie-Poste-Habitat/Se-loger-chez-nous/Plafonds-de-ressources` carries the **PLAI / PLUS / PLS annual ceiling tables**, split *"Paris et ses communes limitrophes"* / *"Reste de l'Île-de-France"* (single person: PLAI 13 845 €, PLUS 25 165 €, PLS 32 715 €) — the SOCIAL half of the missing classifier-tier-4 input. **The page states no year anywhere**, and plafonds are re-set annually by arrêté (it cites *Arrêté du 29 juillet 1987 modifié*), so this is a POINTER, not a figure to wire: the authoritative source is the current arrêté. The LLI half is still missing, and tier 4 needs both bands to discriminate. |
| **A12** | **Logirep / Polylogis** | `logirep.polylogis.immo` | **200** | Mixed | ✅ **POLLABLE — measured 2026-08-21, and it is the best Track 1 find since Cityloger. It is the reverse of A10: ranked WEAKEST on a marker scan, and it carries a structured 113-ad leasing feed.** **Domain corrected** — `www.logirep.fr` answers **301 → `logirep.polylogis.immo`**; the old value was stale. It is **Drupal** (`/core/`, `/profiles/`, `/node/add/` in robots), so `wp-json` is moot; the Drupal analogue `/jsonapi` answers 200 with an empty HTML document (module disabled) — a platform fact, not a verdict. `robots.txt` **200**, and it disallows `/search/`, `/admin/`, `/user/*` and `/sites/default/files/*.pdf` — **neither `/recherche` nor `/annonce/` is disallowed**, and robots matching is literal so `/search/` does not reach `/recherche`. The homepage's two `€` and two `m²` **are the lettings search form itself**; it POSTs to `/` and answers **303 → `/recherche?ss_trnsctntp=leasing&srt=fts_field_ad_min_price~asc`** — see § "A marker count is not a route census". That page embeds the **entire result set as JSON** in `drupalSettings.searchResults.results` — read by the `embedded_json_selector` key added for it, which pulls one element's text and hands it to the ordinary JSON path so `items_path`, the field map and `ListingMapper` keep exactly one implementation: **113 leasing ads**, each with price, living space, typology, locality, postcode, GPS, property type and gallery. **No pagination to walk** — `page=1` and `page=2` return the identical 113 rows and the same first id (113 is not a round number, so a Solr row cap is unlikely but UNVERIFIED). The feed spans the **whole Polylogis group, not just Logirep** — Scalis 75, Logirep 29, Logiouest 8, LogiRys 1 — so one endpoint covers four landlords; **19 of 113 are Île-de-France** (95×5, 94×5, 93×3, 78×3, 92×2, 77×1). Those 19 sit far above social rent levels: Rueil-Malmaison 62 m² @ 1116 € h.c. (~18 €/m²), Montreuil 87 m² @ 1278 €, Bagneux studio 39 m² @ 818 €. Read that as *not social* rather than as a tenure: the PLUS/PLAI ceilings that would make it a measurement are one of this project's missing INPUTS [Unverified: no plafonds figures in repo], which is also why classifier tier 4 is unbuilt. **8 of the 19 are in the 78/95 departments** (Osny, Bezons, Les Clayes-sous-Bois) — and that number was READ AS A YIELD, which it is not. ⚠️ **Corrected 2026-08-22, by running the real gate over the frozen payload: exactly ONE row of 113 passes `Criteria::matchesCommune()`** — a 382 m² commercial unit in BEZONS, which `exclude_title_patterns` then rejects on its `Local…` title. The filter is commune name **AND** postcode prefix, not the prefix alone, and Bezons is the only overlap between Polylogis's 78/95 stock and the ten target communes. **So A12's live yield today is 0, exactly like Cityloger** — the row's own warning that "department is not commune" was right, and the sentence above it was not. That is asserted by test now (`LogirepFixtureTest::testTheCommuneGateIsNarrowerThanTheDepartmentAndThatIsRecorded`), so the real number shows up in a test run rather than in a note somebody has to remember; it going red means the source has started publishing in the target communes, which is the event worth noticing. Note the same commune arrives spelled two ways in one response (`Les Clayes-sous-Bois` and `les clayes sous bois`): commune matching must normalise, and the prototype's substring approach would over-match here. **Two things `/add-source` had to handle, both RESOLVED 2026-08-22 — see `docs/plans/archive/milestone-1-pipeline.plan.md`.** (1) **Rent is quoted `h.c.`** — and the charges are not reliably recoverable: they appear as a `Charges locatives` detail-page field on Rueil (1116 + 192 = 1308, **17%**), as free PROSE on Montreuil (*"Loyer charges comprises : 1 662,67 €"* against 1 278,82 h.c., **30%**), and NOT AT ALL on the Bezons commercial unit. Two shapes, two very different ratios, one absence — so no fixed uplift is defensible and a regex on n=1 would have been guesswork. **Resolved by `charges_included: false`**, which is what `CriteriaEngine` was already built for (*"charges comprises, and never on an HC-only figure"*): the value lands in `rentHc`, `max_rent_cc` never fires on it, and the score line says the ceiling is unverifiable. The stated cost is that the rent ceiling is not checkable for this source. (2) **Neither surface states tenure** — zero `intermédiaire`, `conventionné`, `PLS` or `PLAI` on card or detail page — so this is the Cityloger problem without Cityloger's detail-page answer, and everything resolves `UNKNOWN` into the *à vérifier* digest. **Ruled 2026-08-22: that stays**, fail-closed under §1 and asserted over all 113 rows by test — and the digest-flooding worry is moot, because the commune gate admits one row. `detail_map` is refused by the loader on a non-`html` source anyway, so there was no hydration path to reuse. **Every apparent tenure hit on this source is a false one, and they are worth knowing by name** — this is the `au plus près` failure class a fourth time, and the first time it came from *UI furniture* rather than prose: `PLAI` is inside the filter facet **`Plain-pied`**, `LLI` inside the facet **`Ce·lli·er`**, `PLUS` inside **`plusieurs`** in the comparator modal, `SNE` inside **`SURESNES`** (the landlord's own address), and the only literal `social` is the comparator column header *"Prix locataire parc social"*. All five real strings were run through `TenureClassifier` and every one returns `UNKNOWN` / `DIGEST` at confidence 0.00 — the word-boundary, uppercase and collocation guards hold, so **no fix is needed**; the row records them so the next reader does not re-open the question. The search form carries **no tenure facet at all**: `ts_prprttp` decodes to property type (20 Appartement, 21 Maison, 22 Terrain, 23 Local d'activité, 24 Garage, 64 Place de parking), which is the only use of `its_field_property_type`. **The one structured tenure candidate is dead on this snapshot**: `zs_field_tenant_price` ("Prix locataire parc social" in the UI) is `0.00` on 18 of the 19 IDF rows, absent on the 19th, and **non-zero on 0 of 113 nationally** — so it carries no tenure information here and must not be relied on. Whether it populates on social stock is UNVERIFIED and would need a snapshot that contains some. |
| **A13** | **Erilia** | `www.erilia.fr` | **200** | Predominantly **social** | ⚠️ **POLLABLE — and it yields NOTHING for this project. Measured 2026-08-21.** The 2026-08-20 row recorded zero `€`, `m²` and `disponib` on the homepage and called `/decouvrir-erilia/nos-offres` corporate; both were true, and the lettings route is simply elsewhere — the homepage's 8 `louer` hits lead to **`/louer/recherche`**, which is server-rendered and carries the whole inventory. Drupal; `robots.txt` **200**, disallowing `/search/`, `/admin/`, `/user/*` and `/node/add/` — **`/louer/` is clear**, and robots matching is literal so `/search/` does not reach `/louer/recherche`. Pagination is a plain GET `?page=0…5`; **49 results**, stable across two runs (page-1 refs identical), detail pages at `/louer/<slug>`. The card shape is the best seen so far: commune, postcode, surface, pièces, chambres, neuf/ancien, and **rent quoted `cc` on all 49** — charges comprises, which is exactly what hard rule 9 wants and what A12 does *not* give. **The disqualifier is geography: 0 of the 49 are in Île-de-France.** The spread is 13×10, 33×8, 20×6, 38×6, 69×4, 84×4, 04/74×2, and 01/06/17/31/34/40/64×1 — Erilia is a Marseille ESH, and although its department selector *offers* 75, 78, 92, 93, 94 and 95, nothing is listed in any of them today. **No card states tenure, and nor does the sampled detail page** — zero `PLAI`, `PLS`, `LLI`, `conventionné` or `intermédiaire` across all six search pages (so that half covers all 49) and on the one detail page fetched (Crolles, **n=1** — more were not fetched, since sharpening a *do not enable* row is not worth the requests). On that evidence everything would resolve `UNKNOWN` into the *à vérifier* digest — A12's problem again. ⚠️ **And it carries a page-furniture string that is genuinely SOCIAL**: the site-wide footer widget *"Ai-je droit à un logement social ?"* returns **SOCIAL at confidence 0.90 → REJECT** from `TenureClassifier` — correctly, in isolation. That makes this the fifth outing of the furniture class and the most dangerous shape of it yet: where CDC's `au plus près` vetoed one badge, a selector here that captured the PAGE rather than the CARD would reject **every listing on the source** while `SourceHealth` stayed green, because the listings would still be fetched and counted — hard rule 2's silent failure, arriving through the classifier instead of the parser. Card-scoped selectors are the structural guard, and `HtmlSource` already keeps `_text` off the detail path. Ordinary French is fine here: the three `plus` hits are *"le loyer le plus adapté"* and probe `UNKNOWN` / `DIGEST` at 0.00. `sitemap.xml` and `/jsonapi` are both **404**. **Do not enable** — re-check only if Erilia's IdF stock ever appears. |
| **A14** | **RIVP** | `www.rivp.fr` | **200** | **Social** | ⛔ **NOT POLLABLE, and OUT OF SCOPE by §1 — measured 2026-08-26. The 2026-08-24 re-rank was right that the geographic blocker had expired and wrong to imply that was the only one.** `robots.txt` allows everything (WordPress + Yoast, one `Disallow` on a plugin JSON), so nothing here is a permission problem — the stock simply is not published. The WordPress pre-check settles it in ONE request: `wp-json/wp/v2/types` returns 16 types whose only custom ones are `multimedia`, `faqs`, `residence`, `metier`, `projet` — **no `logement`, `annonce`, `offre` or `location`**. `wp-json/wp/v2/residence?per_page=5` is the A2 shape exactly: it is the `carte-du-patrimoine`, a heritage map of buildings RIVP owns (*Manufacture de Belleville*, *39 rue Myrha*), with **0 `€`, 0 `m²`, 0 `disponib`, 0 `loyer`, 0 `surface`, 0 `pièces`** across the payload. The index page scores **0 `€`, 0 `m²`, 0 `disponib`, 0 `louer`** and its lettings link reads *"Je cherche un logement **social**"*. That page routes to **`demande-logement-social.gouv.fr`**, `al-in.fr` and `balae.logement.gouv.fr`, and says **`numéro unique` twice** — the SNE procedural tell, classifier tier 3, a strong SOCIAL signal. This is the A5/A6/A7/A11 delegation pattern a **FIFTH** time, and the first where the destination is itself named out-of-scope by hard rule 5. **Do not revisit on a tenure argument:** RIVP does hold intermediate stock, but it is allocated through the same commission route, so it never becomes a feed. One lead was chased and closed — the page links `latitud.immo`, whose name reads like a lettings brand and which is RIVP's **software vendor** ("LATITUD | Logiciel & Solutions pour l'immobilier BY INTUITIV"). *A plausible name is not a route*, and it cost one request to find out rather than one paragraph to assume. |
| **A15** | **Val d'Oise Habitat** | `www.valdoisehabitat.fr` | **302 → JS challenge** | Predominantly **social** | ⛔ **NOT POLLABLE — measured 2026-08-26, and it is the FIRST row in this catalogue blocked by an active ANTI-BOT CHALLENGE rather than by having no feed.** Every HTML request 302s to `/shield?u=<the original URL>`, an interstitial whose page computes a `solution` to a `challenge` in JavaScript and sets a `shield` cookie (`setCookie("shield", challenge + ":" + solution, 1)`, `document.cookie`, `setTimeout`, `location.href` — measured, three occurrences of `challenge` in the page source). **Hard rule 5 refuses this outright**: solving a bot-detection challenge to obtain an access cookie is the same class as CAPTCHA solving and fingerprint spoofing, and the rule admits no exception. The block is therefore a RULING, not a capability limit — do not revisit it with a headless browser, and do not read the `browser` adapter's existence as a licence to. What is measurable without touching the shield: `robots.txt` answers **200 `text/plain`** and is served WITHOUT the interstitial, allowing everything except `/include/viewfilesecure.php*`, `/cms/` and five QUERY-PARAMETER patterns — `UTB_FS=`, `UTB_S=`, `UTB_RESET=`, `fontSize=`, `style=`, `reset=`. Those first three are the CDC Habitat shape (§ A3): a parameterised search the site asks crawlers to stay out of, so even past the shield the search itself would likely be robots-refused. `sitemap.xml` is a sitemap INDEX naming three sub-sitemaps and its `<loc>` values are **malformed** — `https://www.valdoisehabitat.frsitemap.module.xml`, missing the `/` — and repairing them by hand gets a 302 to the shield anyway, so the tree is unreadable. **The tenure blocker stands independently and is the older reason:** a departmental office whose stock is predominantly social, out of scope by §1 and Q4, with A13 Erilia the cautionary case for a social-skewed source. ℹ️ **Whether it offers an email alert is UNKNOWN and unmeasurable** — hard rule 4's primary route would be behind the same shield, and nothing about it can be checked without passing the challenge. That is the one thing here worth a human's five minutes in a real browser, if 95 stock ever becomes worth chasing.

### Merged away — do NOT add these as sources

Verified this round; adding them would produce duplicate or dead sources:

- **Coopération et Famille**, **Logement Français**, **Logement Francilien** → all three merged into
  **1001 Vies Habitat** in 2018. They are A7, not separate sources.
- **Efidis**, **Osica** → absorbed into **CDC Habitat** (A3).
- **France Habitation** → absorbed into **Seqens** (A5).
- **Ampere Gestion** is a CDC Habitat *asset manager* for LLI portfolios, not a letting platform. It has
  no listings to poll. Not a source.

---

## Tier B — private portals (LIBRE). In scope since Q4

**Email-alert ingestion is the primary path** — within ToS, no bot to detect, *faster* than polling
because alerts fire on publication, and immune to markup churn. You subscribe once to each portal's own
alert, pointed at a dedicated mailbox; `email_alert` ingests over IMAP.

| # | Portal | Domain | HTTP | Notes |
|---|---|---|---|---|
| **B1** | **SeLoger** | `www.seloger.com` | **403** | ✅ **LIVE 2026-08-25 — source #5, and the first that is not a landlord.** The first Tier B portal and the first email source of any kind; largest IDF inventory, AVIV group. Direct polling stays CLOSED (DataDome, 403 on every route incl. the bare homepage) — the route in is the ALERT MAILBOX, hard rule 4's primary path. `scout --domain=rent doctor --source=seloger`: **9 annonces, `ok`, ~19 s**. Two real alerts frozen at `tests/fixtures/rent/seloger/`; offline proof is `MAILBOX_DIR=tests/fixtures/rent/seloger`. See the block below. |
| **B2** | **Leboncoin** | `www.leboncoin.fr` | **LIVE 2026-08-26** | Source #7. Where non-agency private landlords actually post; 403s a plain client, so the email alert is the only route. First alert ever fired 07:33 Paris that morning. 3 annonces, `ok`, 864 ms; 1 match of 3. **The first HTML-ONLY alert** — no `text/plain` part, so every URL lived in an `href` and the parser yielded a full body with ZERO links until `EmailMessage::harvestHrefs()`. See `docs/plans/archive/leboncoin-email-alert.plan.md`. |
| **B3** | **PAP** | `www.pap.fr` | **LIVE 2026-08-26** | ✅ **Source #8, and the first DIRECT-FROM-OWNER portal** — *particulier à particulier*, no agency fees, so its inventory does not overlap the agency portals every other private source draws from. `scout --domain=rent doctor --source=pap`: **2 annonces, `ok`, 483 ms**. Structurally the simplest alert in the tree: a real `text/plain` part, ONE listing per message (no `card_separator`), and a real ad id (`/annonces/-r458301723`), so identity is the link. **What it cost was two defects a naive config walks into, both MEASURED before any config was written.** (1) The alert quotes the SUBSCRIBER'S OWN search criteria above the listing — *"jusqu'à 1.200 EUR à partir de 45 m²"* — and every generic reader is first-match-wins, so the surface read **45**, the search FLOOR, instead of the flat's 50: below `min_surface_m2`, so the first alert ever sent would have been rejected as too small, silently. Bien'ici's defect a second time, down to the same number, because the same saved search is used on every portal. Fixed with four POSITIONAL anchors on the `(NNNNN)` postcode line — the numeric twin of *a title is a position, never a vocabulary*. (2) `link_host` must carry the PATH: the message has two links and BOTH are on `www.pap.fr`, and the unsubscribe page at `/utilisateur/alertes` matches none of the noise words `looksLikeAListing()` rejects, so a host-only value yielded a phantom second listing carrying the real flat's facts under its own identity — never delisted. Two real alerts frozen at `tests/fixtures/rent/pap/`; offline proof is `MAILBOX_DIR=tests/fixtures/rent/pap`. See `docs/plans/archive/pap-email-alert.plan.md`. |
| **B4** | **Bien'ici** | `www.bienici.com` | **200** | ✅ **LIVE 2026-08-25 — source #6, and much the EASIER of the two email portals.** Map-first, agency consortium. `scout --domain=rent doctor --source=bienici`: **13 annonces, `ok`, 731 ms** — and a seeded pass matched **10 of 13**, by far the best hit rate in the tree, because the portal's own alert criteria mirror `criteria.json` closely. It publishes a REAL LISTING ID in the URL path (`/annonce/laforet-immo-facile-22588736`), so identity is the link and none of SeLoger's content-addressing is needed. Three real messages frozen at `tests/fixtures/rent/bienici/`; offline proof is `MAILBOX_DIR=tests/fixtures/rent/bienici`. See the block below. |
| **B5** | **Logic-Immo** | `www.logic-immo.com` | **403** | ⚠️ **Same group as SeLoger (AVIV)** — expect heavy duplicate overlap with B1. The cross-portal dedup case in the brief is mostly this pair. |
| **B6** | **A Vendre A Louer** | `www.avendrealouer.fr` | **403** | Email alert only. |
| **B7** | **Figaro Immo** | `immobilier.lefigaro.fr` | **200** | Smaller IDF rental inventory. |
| **B8** | **ParuVendu** | `www.paruvendu.fr` | **200** | Long tail. |
| **B9** | **Locservice** | `www.locservice.fr` | **200** | Tenant-posts-criteria model rather than listing search — a different shape; may not fit the adapter contract cleanly. |
| **B10** | **Flatlooker** | `www.flatlooker.com` | **200** | Online agency, remote-viewing. Small inventory, low duplication with the majors. |
| **B11** | **Gens de Confiance** | `www.gensdeconfiance.com` | **403** | Closed community, needs an account. Email alert only. |
### B1 SeLoger — what onboarding an EMAIL source cost, and what it teaches the other ten

**Direct polling is CLOSED, and it took two measurements to say why correctly.** A first pass read
`robots.txt` and concluded *"every route we would need is Disallow-ed"*. That was **too strong**: the
66 rules target query-string search (`/classified-search?`, `/list.htm`, `*/?page=*`) and the OLD
`detail.htm` format, and a denylist leaves everything else open — tested with this project's own
`Robots` parser, `/annonces/locations/appartement/<commune>/<id>.htm`, `/immobilier/locations/immo-<commune>-<dept>/`
and `/sitemap.xml` are all **ALLOWED** [Verified 2026-08-25].

**The site refuses them anyway.** Every one of those paths returns **HTTP 403** with a DataDome
challenge body (`dd={'rt':'c',…}`), and so does the bare homepage, and so does a request carrying an
ordinary Chrome User-Agent — the block is not UA-based [Verified 2026-08-25]. This row said `200`
until that measurement; it was stale, and a stale `200` is exactly what sent the A2 ICF Habitat
assessment down a three-level crawl for a feed that did not exist.

So the conclusion holds and the reasoning changed: SeLoger is not closed by `robots.txt`, it is
closed by **anti-bot**. That matters, because it decides what a browser adapter would be FOR. The
allowed pages are not JS-rendered-but-reachable; they are *blocked*. Driving headless Chrome at them
adds exactly one thing over `curl` — defeating the challenge — which is the whole of hard rule 5 and
`spec/PROJECT_BRIEF.md` §6b (*"Do not build CAPTCHA-solving or fingerprint-evasion machinery"*).
Using a logged-in human profile is the same act with better cover.

Worth noting even in the counterfactual: `?page=` IS disallowed, so a permitted crawl of the SEO
landing pages would reach page 1 only.

**Two alerts, ONE format.** `1 nouvelle annonce : Ile-de-France` (campaign `SLG-…-ALI-RELAXED`) and
`Consilium vous adresse ses dernières exclusivités` (`SLG-…-ALI-EXCLUSIVE`) look like two products
and share one card template: price → type → rooms · surface → residence, commune, (postcode) →
*Voir l'annonce*. Do not build two field maps before checking whether the cards differ.

**Neither is an exact-match alert.** SeLoger pads the feed with *offres alternatives* (the first
carries a `Surface différente` badge) and partner exclusivities. They are NOT filtered at ingest:
`CriteriaEngine` re-judges everything, so a listing the portal calls a near-miss that clears the
real floors is a real match, and dropping it at the door is the silent over-rejection hard rule 8
forbids.

**The pre-check for an email source is not an HTTP status.** A Tier B row's `200`/`403` says nothing
about whether the portal's alerts are parseable — B1 is `200` and its search is irrelevant, because
the route is the mailbox. What to check instead, in order:

1. **Does the alert carry a real listing URL?** SeLoger's does not: every link is
   `click.by.seloger.com/?qs=<opaque per-recipient token>`, and there is no listing id anywhere in
   the HTML — no `data-*`, no numeric ref. Strip the query, as link-identity does, and every link in
   the message collapses to one id. That single fact decided the whole adapter design
   (`id_from: content`). **Check this FIRST on every new portal**; it is a five-minute grep and it
   is the difference between a config block and a feature.
2. **Does the `text/plain` part carry undecoded HTML entities?** SeLoger's does (`&rarr;`). It is
   generated from the HTML and nobody decoded it on the way. This is a §1 hazard, not a cosmetic
   one — `logement conventionn&eacute;` folds to `logement conventionn`, label destroyed.
3. **Does a card end in a consistent CTA?** That string is the `card_separator`. Without one, every
   link in the message becomes a listing carrying the FIRST rent and FIRST surface in the whole body.
4. **Is there a stray digit at the end of the line above a price?** A tracking URL usually ends in
   one. It used to be glued to the rent.

**Never follow the tracking redirect to recover a real URL.** One third-party request per listing,
on a token tied to the subscriber, manufacturing an engagement signal from a click nobody made —
hard rule 5's *identify honestly* one step out. Carry the link unresolved; the human clicks it.

**Capture with `tools/scrub-eml.php`, and keep the message ugly.** It strips `Delivered-To`, `To`,
`Received`, `Return-Path`, `Reply-To`, `Feedback-ID`, `List-Unsubscribe*`, the DKIM/ARC signatures
it would otherwise invalidate, every `qs=` value and the address in the CNIL footer — and it
REFUSES to write while any of those survive. What it deliberately does NOT touch is the structure:
the MIME preamble, the `=_?:` boundary, the 8bit transfer encoding, the folded headers and the RFC
2047 subject split mid-word. Three of those five were live parser defects; tidying the fixture would
delete the evidence.

**Measured yield, 2026-08-25:** a seeded run over the two fixtures gives **1 match** (Dourdan 91410,
3 pièces, 52,37 m², 915 € CC) and **1 rejection** (Conflans-Sainte-Honorine 78700, 44,71 m², under
the 50 m² floor). Two listings is not a yield estimate — it is proof the path runs end to end.

**What the LIVE mailbox then found that no fixture could, and both are rules for the other ten.**

- **A portal's own numeric fields do not distinguish a flat from a bedroom in one.** Four of the
  first nine live matches were **coliving rooms**, each advertised with the whole flat's room count
  and surface — so `min_rooms`, `min_surface_m2` and `max_rent_cc` all passed, and a 10 m² bedroom
  presented as an 80 m² T4. The discriminator is the TITLE and only the title, anchored:
  `^\s*chambre\b`. Not a description match — `3 chambres` in a description is exactly the family
  flat the criteria want. Expect this on every private portal; institutional landlords do not
  publish coliving.
- **Read the commune from the portal's LAYOUT, never from a vocabulary of names you happen to
  know.** Every live listing arrived with `commune = null` while its postcode parsed fine, because
  the only reader was a substring scan over `Criteria::communeLabels` — which in region mode holds
  the RANKED communes and nothing else. A watch covering all of Île-de-France therefore knew a
  handful of town names and none of the rest, and the failure is invisible: the listing still
  matches on its postcode, so the push simply cannot name the town. `commune_pattern` is the
  per-source fix; the vocabulary scan stays underneath as the fallback.
- **Anchor that pattern on the part of the block that is always there.** SeLoger prints an optional
  *quartier* line above the commune and the postcode alone in parentheses below it. **Three of the
  nine live cards have no quartier** — and **both frozen fixtures have one**, so anchoring on the
  comma above would have read six cards, missed a third of the source, and passed every
  fixture-based test. Anchor below, on the postcode.
- **The From filter is load-bearing, not hygiene.** This mailbox is the developer's personal
  address: 50 messages, 3 alerts. Twelve are SeLoger *contact receipts* from a different subdomain
  whose unfilled template carries `870,00 €cc /mois. · m² · pièces · chambres` beside the commune
  *Saint-Germain-de-Tallevend-la-Lande* (Calvados) and the postcode `75015` — a plausible rent, a
  valid IdF postcode and a town 250 km away. Pin `params.from` to the alert subdomain, and keep the
  no-information floor as the second layer.

### B4 Bien'ici — the same route, and almost none of the same problems

Onboarded 2026-08-25, hours after B1. Worth reading beside it, because the two portals take the
identical route in and differ on nearly every decision it forces.

**It publishes a real listing id**, which is the whole difference. `/annonce/laforet-immo-facile-
22588736` survives `stableId()` stripping the query, so identity is the LINK and none of SeLoger's
content-addressing is needed — nor wanted: a real id has neither of the content key's stated costs.
The adapter gained a link-identity path for the segmented case to make that possible; `id_from:
content` still short-circuits, so SeLoger's identity is byte-identical.

**Pick the identity scheme BEFORE enabling the source.** Nothing migrates a stored row from one key
to another, so switching content→link on a live source makes every row look new and re-notifies the
whole backlog. *Ship config-only today and improve it later* is a trap here, not the safe option.

**THE SEPARATOR IS THE LINE EACH CARD STARTS WITH, and copying SeLoger's would have been wrong.**
Splitting on the call to action puts the alert's own criteria line — `Louer région Île-de-France -
Maison, appartement - 1 200 € max - 3 pièces min - 45 m² min` — inside segment 0, and starts every
later segment with the PRECEDING card's `RÉFÉRENCE : Cocon_Loc_T4`. Measured over four live
messages: **3 of 13 surfaces read 45 m² and 1 of 13 room counts read 4**, every error in the
under-reporting direction, so `min_surface_m2` rejects a real match and nothing says why. The rule
that generalises: **a card separator must be measured against a real payload, both candidates run,
and the extracted values compared against what the card's own title states.**

**`commune_pattern` anchors on the postcode BEFORE the name** (`94600 Choisy-le-Roi`) — the exact
inverse of SeLoger's, whose postcode sits parenthesised on the line below. Two portals, two
anchors, which is why this is per-source config rather than a general reader of French addresses.

**`title_pattern` anchors on the STRUCTURE, not on a list of dwelling types.** Every title here is
`<type> N pièces M m²`, so the pattern keys on `pièces`. That matters for §1-adjacent filtering
rather than for tidiness: a listing whose title does not match falls back to the message SUBJECT,
and `exclude_title_patterns` — which is what catches coliving rooms — then has nothing to fire on.

**The scrubber had to be fixed before a single fixture could be committed**, and the defect it
exposed is general. Every Bien'ici link carries `signedRecipient=eyJ…`, a JWT whose payload
base64url-decodes to `{"email":"<the subscriber>"}`. `tools/scrub-eml.php` verified that the address
was ABSENT, which it was, and wrote the file. **Absent is the wrong test; the right one is not
RECOVERABLE.** It now decodes every long base64url run and the quoted-printable form before it
looks, and refuses when the address surfaces in any of them. The first fix still stripped nothing
from all three real captures: the tokens are quoted-printable, so a JWT reads `=3DeyJ…` and a `\b`
anchor sees the `D` of `=3D`.

**Its live yield is the best in the tree, and that is not luck**: a seeded pass matched **10 of 13**.
The portal applies the saved search's own criteria before sending, and those criteria were set to
mirror `criteria.json`. The three rejections were 44 m² (a suggestion card), a `meublé`, and 1208 €
against a 1200 € ceiling. Contrast Cityloger, whose 51 listings yield 0. **A portal that filters for
you is worth more than a landlord with a bigger catalogue**, which is the argument for the remaining
Tier B rows.

**A suggestion card is ingested like any other.** *"Cette annonce peut également vous intéresser"*
appends a real listing the saved search did not match, so the message carries one more card than its
subject announces. Over-inclusion costs a row the criteria reject; under-inclusion costs a flat.

### Agency networks — deliberately NOT separate sources

Foncia, Orpi, Century 21, Laforêt, Guy Hoquet, Nexity, Citya, Stéphane Plaza. Each has its own site, but
they **feed the portals above**, so adding them multiplies duplicates for very little unique stock. Add
one only if a specific agency is found to withhold listings from the portals.

### Out of scope entirely

| Source | Why |
|---|---|
| `demande-logement-social.gouv.fr` | Social-housing channel. Violates the Q4 ruling and `CLAUDE.md` §1. |
| **Bienvéo** | Social-housing channel (AORIF). Same reason. |
| Studapart, Lodgis, Morning Croissant | Student / furnished / short-stay — excluded by filter F9 (`meubl`, `residence etudiante`). |
| Facebook Marketplace & groups | No usable API, and automated access is against ToS. |
| **Jinka** | See § "Honest note" — it is a *competitor*, not a source. Scraping an aggregator would also be a ToS problem. |

---

## Car sources polled without a reviewed legal posture (recorded 2026-10-02)

Audit 2026-10-02 (P1-2): rent refuses any `legal_risk` source without `--i-accept-legal-risk`
(hard rule 4); the car domain has no such gate, and two car sources poll `enabled: true`. Developer
ruling: **mark and record only** — both stay polling, both carry `"legal_risk": true` in
`config/car/sources.json`, and nothing enforces it at runtime (`VehicleSourceDefinition::$legalRisk`
is a statement, read by `tests/php/Car/VehicleSourcesLegalRiskTest.php`, not a gate).

| Source | Known | NOT known |
|---|---|---|
| Autohero (`sitemap_jsonld`) | `robots.txt` open and enforced at runtime; the sitemap it publishes for crawlers is what is read; 2 s between requests, honest User-Agent | its terms of use — never read; whether automated reading of lot pages is permitted |
| Alcopa (`alcopa`) | public auction site; `robots.txt` disallows only `/*.pdf$` and `/calendrier/`, enforced at runtime; ~13 search pages per pass, 3 s apart | its terms of use — never read; whether a polling cadence of this size is acceptable |

Closing this means reading both sets of terms, then either removing the flag with the evidence here
or disabling the source. Until then the flag is the only place the gap is written down.

## Honest note: for Tier B, a product already exists

**Jinka** (`www.jinka.fr`, HTTP 200) aggregates SeLoger / Leboncoin / PAP / Bien'ici and alerts on new
matches — roughly the Tier B half of this project. *[Unverified: its current feature set, filter
granularity and pricing were not checked.]*

What **nothing** does is Tier A. In'li, ICF Novedis, CDC Habitat, AL'in and the Action Logement group
landlords each publish only to their own site, and no aggregator covers them.

So the defensible framing is:

- **Tier A is the differentiated half.** Build it regardless.
- **Tier B is the "already solved, but not the way I want it" half.** Worth building for one unified
  filtered list with your exact criteria and dedup across both families — but that is a convenience
  argument, not a capability one, and it should be a deliberate choice rather than a default.

---

## What is NOT decided by this file

- No endpoint has been reverse-engineered. Every source starts `enabled: false` with `url: REMPLACER`.
- Build order, which Tier B portals to subscribe to, and the mailbox — see `docs/OPEN-QUESTIONS.md`.
- Whether CDC Habitat's search path is inside its `robots.txt` `Disallow` — **must be resolved before
  A3 is enabled**, and if it is, A3 moves to the email-alert route.
