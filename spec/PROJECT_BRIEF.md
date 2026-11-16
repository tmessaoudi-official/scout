# PROJECT BRIEF — `logement-scout`

> Paste this whole document as the first message of a new Claude Code session.
> It is a specification, not a suggestion. Read all of it before writing code.

---

## 0. Before you write any code

Ask me these and **wait for answers**. Do not guess — they change the architecture:

1. Target communes, or a commute constraint (`≤ N min` to a named station via IDFM API)?
2. Max loyer charges comprises. Hard cutoff or soft (+10% still notified)?
3. Minimum rooms / surface.
4. **Is PLS in scope or excluded?** Are `LIBRE` (market-rate private) listings in scope?
5. Floor/elevator: hard reject or scoring penalty?
6. Do you want automatic LLI income-eligibility checking (requires RFR N-2 in `.env`)?
7. Stack: Python or TypeScript?
8. Runtime host: local cron / VPS / Docker / GitHub Actions?
9. Notification channel: Telegram / ntfy / email / Slack?
10. Playwright allowed?
11. Repo visibility (recommend **private**).

Then propose a milestone plan and get it approved before implementing.

---

## 1. Mission

A self-hosted watcher that monitors **rental listings in Île-de-France** across two very
different families of sources, classifies each listing by **tenure type**, filters against
personal criteria, and pushes a notification within minutes of publication.

The user is a developer relocating within the western suburbs (Yvelines / boucle de Seine).
Current flat: 4th floor, no elevator, second child arriving. Speed matters — good LLI units
are gone within hours.

### The one non-negotiable rule

> **`logement social` (PLAI, PLUS) must NEVER be surfaced as a match.**
> The user is not eligible and is not interested. The target is **LLI — logement locatif
> intermédiaire** — plus, depending on the answer to Q4, private market listings.

This is not a config toggle bolted on at the end. It is a first-class domain concept with
its own module, its own test suite, and a fail-closed default.

---

## 2. Domain glossary — read this carefully

French housing tenure is the core domain model. Getting it wrong makes the tool useless.

| Term | Meaning | In scope? |
|---|---|---|
| **LLI** — Logement Locatif Intermédiaire | Created by ordonnance 2014-159. Rent capped ~10–20% below market. Income ceilings exist but are far higher than social housing. Zones A bis / A / B1 only. Allocated **directly by the landlord**, no commission, no SNE number. | **YES — primary target** |
| **PLS** — Prêt Locatif Social | Highest tier of *social* financing. High ceilings, often marketed alongside intermediate stock. | **NEVER** — answered 2026-08-06 (Q4): PLS is social housing (SNE + commission), and the ruling is no social housing |
| **PLUS** — Prêt Locatif à Usage Social | Mainstream social housing. Requires SNE registration (numéro unique), allocated by commission d'attribution. | **NEVER** |
| **PLAI** — Prêt Locatif Aidé d'Intégration | Very-low-income social housing. | **NEVER** |
| **LIBRE** | Private market rate, no cap, no income condition. SeLoger / Leboncoin / PAP / agencies. | **YES** — answered 2026-08-06 (Q4). Its own track, its own mailbox and notification |
| **ANRU / ANAH / conventionné** | Various subsidised regimes. Treat as social unless explicitly labelled intermediate. | **NEVER** |

**Critical**: tenure is a property of the *listing*, not of the *source*. In'li is pure LLI,
but CDC Habitat, Vilogia, Immobilière 3F and Seqens publish social and intermediate stock on
the same pages, sometimes in the same result set.

---

## 3. Architecture

Single repo, single language. Layered, with adapters as the only site-specific code.

```
logement-scout/
├── src/
│   ├── core/
│   │   ├── models.*          # Listing, Source, MatchResult, SourceHealth
│   │   ├── tenure.*          # THE classifier — see §4
│   │   ├── criteria.*        # scoring + hard disqualifiers
│   │   ├── dedup.*           # within-source and cross-portal dedup
│   │   ├── store.*           # SQLite persistence + price history
│   │   ├── health.*          # per-source breakage detection — see §8
│   │   └── notify/           # telegram | ntfy | email | slack
│   ├── adapters/
│   │   ├── base.*            # Source interface
│   │   ├── http_json.*       # config-driven JSON/XHR adapter
│   │   ├── html.*            # config-driven CSS adapter
│   │   ├── email_alert.*     # IMAP ingestion — see §6
│   │   ├── browser.*         # Playwright, opt-in only
│   │   └── sites/            # per-site overrides where config isn't enough
│   ├── enrich/
│   │   ├── transit.*         # IDFM / PRIM API — door-to-door commute time
│   │   └── geo.*             # commune → INSEE code, coords
│   └── cli.*
├── config/
│   ├── criteria.json
│   └── sources.json
├── tests/
│   └── fixtures/             # frozen HTML + JSON payloads per source
├── .env.example
└── README.md
```

### Adapter contract

Every source implements the same interface. No exceptions.

```
interface Source {
  name: string
  family: 'institutional' | 'private'
  defaultTenure: Tenure | null   // hint only, classifier still runs
  fetch(): Promise<RawListing[]>
  health(): SourceHealth   // computed FOR the source by SourceHealthService (step 8e-2)
}
```

Adding a source must be **config-only** in the common case. Writing a bespoke adapter is
the fallback, not the default path.

---

## 4. The tenure classifier — `core/tenure.*`

The most important module in the codebase. Give it real tests.

```
classify(listing) -> { tenure: Tenure, confidence: 0..1, signals: string[] }
```

**Signal sources, in priority order:**

1. **Explicit structured field** — many APIs expose `financement`, `typeProduit`,
   `categorie`. Highest confidence.
2. **Explicit label in text** — `logement intermédiaire`, `LLI`, `loyer intermédiaire`,
   `PLS`, `PLUS`, `PLAI`, `logement social`, `conventionné`.
3. **Procedural tells** — mention of `numéro unique d'enregistrement`, `SNE`,
   `commission d'attribution`, `demande de logement social` ⇒ strong social signal.
   Mention of `sans condition de commission` or direct-booking flow ⇒ intermediate signal.
4. **Plafonds de ressources** — if income ceilings are quoted, compare against known LLI
   vs PLUS/PLAI ceiling bands for the zone. Distinguishes reliably.
5. **Source default** — lowest confidence, used only when nothing else fires.

**Fail-closed rule**: if confidence < 0.6 AND the source is known to mix social and
intermediate stock, classify as `UNKNOWN` and **do not notify** — instead, surface it in a
separate low-priority "à vérifier" digest. Never let an unclassified listing through as a
match. A missed listing is annoying; a social-housing false positive makes the tool
untrustworthy, which is worse.

**Tests are mandatory here.** Build a fixture corpus of at least 30 real listing texts,
hand-labelled, covering: pure-LLI In'li, mixed CDC Habitat results, an explicit PLAI, an
explicit PLS, and an ambiguous case. The suite must go red if the classifier regresses.

---

## 5. Criteria engine — scoring, not just filtering

Two distinct mechanisms. Do not conflate them.

**Hard disqualifiers** (never notified, logged only):
- tenure in the excluded set
- rent above ceiling + tolerance
- rooms/surface below floor
- `exclude_regex` hit (colocation, meublé, résidence étudiante, senior)

**Score** (0–100, drives ordering and notification priority):
- commune preference weight
- commute time to target station (if IDFM enrichment is on)
- rent below ceiling → bonus proportional to headroom
- surface above minimum → bonus
- floor ≤ 1 **or** elevator present → large bonus; high floor without elevator → large penalty
- freshness — first-seen within the last hour → bonus

Every notification must carry `score` plus a human-readable `reasons[]` explaining it.

---

## 6. Sources — required coverage

### 6a. Institutional / LLI — **the priority**

Nothing on the market aggregates these. This is where the project earns its keep.

| Source | Notes |
|---|---|
| **In'li** | Pure LLI. Highest-value source. Has a JSON search endpoint. |
| **CDC Habitat** | Mixed social + intermediate — classifier is essential here. |
| **AL'in** (Action Logement) | Reserved stock for salariés of contributing employers. May need an authenticated session. |
| **Seqens** | Action Logement group, strong 78/95 footprint. |
| **Immobilière 3F** | Large IDF portfolio, mixed tenure. |
| **1001 Vies Habitat** | Action Logement group. |
| **Vilogia** | Has a dedicated intermediate product line. |
| **ICF Habitat La Sablière** | SNCF group, stock clustered along rail corridors. |
| **Batigère IDF**, **Toit et Joie**, **Logirep / Polylogis**, **Erilia** | Second tier, add once the core works. |

Do not hardcode URLs from memory — **verify every endpoint live** before committing it.

### 6b. Private portals

**Primary strategy: email-alert ingestion.** For each portal, the user subscribes to the
portal's own native alert with the right criteria, pointed at a dedicated mailbox. The
`email_alert` adapter connects over IMAP, parses the alert emails, and extracts listings.

Why this is the primary path and not a workaround:
- It is fully within each portal's terms of service.
- It defeats DataDome/anti-bot entirely, because there is no bot.
- It is *faster* than polling — alerts fire on publication.
- It does not break when the site's markup changes.

Portals to cover: **SeLoger**, **Leboncoin**, **Bien'ici**, **PAP**, **Logic-Immo**.

Direct HTTP scraping of these portals may be implemented as an explicitly opt-in,
disabled-by-default fallback (`legal_risk: true` in config, refuses to run without an
explicit flag). Respect `robots.txt`, identify honestly in the User-Agent, keep request
rates low. Do not build CAPTCHA-solving or fingerprint-evasion machinery.

### 6c. Explicitly out of scope

`demande-logement-social.gouv.fr`, and **Bienvéo** unless its intermediate-stock filter can
be applied reliably at the source — both are social-housing channels and violate §1.

---

## 7. Deduplication

Two levels:

- **Within source** — stable key: `(source, external_ref)`, falling back to a hash of
  normalised `(url, title)`.
- **Cross-portal** — the same flat commonly appears on SeLoger, Leboncoin and Bien'ici with
  different photos and wording. Fuzzy match on `(cp, surface ±2 m², rent ±20 €, rooms)`.
  Cluster into a single logical listing, keep every source URL on it, notify once.

Track **price history** per logical listing. A rent drop on a listing already seen is a
notification-worthy event in its own right.

---

## 8. Source health — do not skip this

The classic silent failure of this kind of tool: a selector breaks, the source returns zero
results, no notifications arrive, and the user concludes the market is quiet.

Requirements:
- Persist per-source: last success, last item count, rolling 7-day mean count.
- If a source returns 0 items on N consecutive runs (default 3) when its baseline is
  non-zero → emit a **`SOURCE_BROKEN` alert** through the normal notification channel.
- If item count drops >70% below its rolling mean → warn.
- `scout --domain=rent doctor` command: run every source once, report status, timing, and item counts.

---

## 9. Configuration & secrets

- `config/rent/criteria.json` — user criteria, committed.
- `config/rent/sources.json` — source definitions and field mappings, committed.

  **Amended 2026-08-07 (Q22).** These two were specified as `.yaml`. This container has no `ext-yaml`
  and cannot install a parser (the egress policy 403s Composer's dist source), so a `.yaml` config
  would sit unread. JSON is parsed by `ext-json`, which is always present, and by phorj's `Core.Json`,
  which keeps the shared-file property that makes the two implementations comparable. Keys beginning
  with `_` are ignored — `_comment` by convention, since JSON has none — and every other unrecognised
  key is a hard validation error.

  A gitignored `config/rent/criteria.local.json`, when present, overrides `criteria.json` field by field.
  That is how personal tuning stays out of git while the committed file stays a working default.
- `.env` — **gitignored**. Holds: IMAP credentials, Telegram token, IDFM API key, RFR for
  eligibility checks, any site credentials. Ship a `.env.example`.
- Never commit personal financial data. Never log credentials.

---

## 10. Developer experience

Required CLI:

```
scout --domain=rent doctor                  # health-check every source; prints status, timing, item counts,
                              #   the store's journal mode and the resolved digest schedule
scout --domain=rent dump <source>           # raw payload of first item — for building field maps
scout --domain=rent run --once [-v]         # single pass
scout --domain=rent run --watch             # loop with jitter (15 min ± 5, paced per host — Q37)
scout --domain=rent run --seed              # populate the seen-set without notifying (empty seen-set — Q36)
scout --domain=rent digest [--dry-run]      # emit the "à vérifier" digest on demand — added 2026-08-07 (1c)
scout --domain=rent reclassify [--dry-run]  # re-judge stored undetermined verdicts — added 2026-08-07 (Q35)
scout --domain=rent test-notify             # verify the notification channel
scout --domain=rent replay <source>         # alias of `dump` — see the amendment below
```

**Amended 2026-08-24 on `replay`, which is an ALIAS rather than the verb specified.** This line
read `scout --domain=rent replay <fixture>`; the implementation is `'replay' => $this->dump($flags)`, which takes
a SOURCE NAME, and the verb was missing from `scout help` altogether — so the spec, the code and the
tool's own help each said something different, and a fixture path exits 2 with *"source inconnue"*.
Now listed in `help` and documented as an alias. For a `type: fixture` source, `dump` already IS a
replay against a saved payload; what is NOT built is replaying an arbitrary fixture file through a
network source's field map, which is the useful half for developing a map offline. Outstanding, not
withdrawn — unlike `--since`, nothing about it is unsound.

**Amended 2026-08-29: that half is BUILT — `scout --domain=rent replay <source> --file=<payload>`.** A frozen page
through a network source's own adapter and field map, offline by construction (`/robots.txt` → 404 =
allow, the search URL and its pages → the file, everything else → 404), against a throwaway store,
unthrottled. `README.md` § Planned CLI carries the rules and why each one exists.

**Amended 2026-08-24 on `--since`, which is REFUSED rather than built.** Q35 named it, and its
staleness mechanism is a classifier version stored with the verdict — a column that does not exist.
Answering it against `last_seen_at` would substitute a different mechanism for the ruled one while
looking honoured; re-running the whole undetermined bin costs seconds. The flag exits 2 saying so.
Reversing it means adding the version column first. See `docs/OPEN-QUESTIONS.md` Q35 § AMENDED.

**Amended 2026-08-07.** `digest`, `reclassify` and `run --seed` were added by the rulings of that
date and each closes a silent-miss hole: without `digest` the fail-closed rule's only landing zone is
emptied once a day at best; without `reclassify` a listing digested under an old classifier is a
permanent miss, because the seen-set guarantees it is new exactly once; without `--seed` a database
created by a missing volume mount re-notifies the entire market at once.

`--i-accept-legal-risk` is a global flag, never persisted in config, and is required before any
source carrying `legal_risk: true` will run (Q26, `CLAUDE.md` hard rule 4).

`scout --domain=rent dump` is what makes onboarding a new source take five minutes instead of an hour.
Build it early.

---

## 11. Testing

- **Fixture-based parser tests.** Save a real payload per source under
  `tests/fixtures/rent/<source>/`. Parser tests run offline against fixtures. No network in CI.
- **Classifier tests** — the hand-labelled corpus from §4. Non-negotiable.
- **Criteria tests** — table-driven, covering every hard disqualifier and score component.
- **Dedup tests** — including the cross-portal fuzzy case.

---

## 12. Non-goals

- No web UI. CLI plus push notifications. A read-only HTML digest is acceptable later.
- No auto-application or auto-form-submission to landlords.
- No CAPTCHA solving, proxy rotation, or browser-fingerprint spoofing.
- No multi-user support. Single user, single machine.

---

## 13. Milestones

1. **Core skeleton** — models, SQLite store, config loading, CLI, notification channel.
   Prove it end-to-end with one fake source.
2. **Tenure classifier + tests.** Before any real source. Everything depends on it.
3. **In'li adapter.** Highest-value single source, and pure LLI so it exercises the happy path.
4. **Health monitoring + `doctor`.** Before adding breadth — otherwise breakage goes unnoticed.
5. **CDC Habitat.** First mixed-tenure source; validates the classifier against reality.
6. **Email-alert adapter + one private portal.**
7. **Remaining institutional sources.**
8. **Cross-portal dedup, transit enrichment, scoring refinement.**

Ship each milestone working. No big-bang integration.

---

## 14. Working agreement

- Ask when uncertain rather than assuming — especially about tenure semantics and eligibility.
- Verify every external endpoint against the live site before committing it. Do not write
  URLs or API paths from memory.
- Conventional commits, small PRs, one milestone per branch.
- Keep `README.md` current: how to add a source, how to build a field map, how to run tests.
- If a source turns out to be technically or legally impractical, say so and propose the
  email-alert route instead of quietly building something fragile.
