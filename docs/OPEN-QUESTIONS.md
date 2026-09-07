# Open questions — rent-watch

Decisions the developer still owes, each with the **default that applies if it stays unanswered**.
Answered questions are struck through with the decision and the date; they are never deleted, because
the record of *why* is worth more than the tidiness.

`spec/PROJECT_BRIEF.md` §0 lists eleven questions and says to wait for answers before writing code.
This file is that list, plus what reading `prototype/sources.yaml` already answers, plus what the
bundle integration raised on its own.

> **Ⓐ Answered · Ⓞ Open · Ⓑ Blocking** — Ⓑ means milestone 1 should not start until it is settled,
> because the answer changes the architecture rather than a constant.

> ## ⚑ ALL QUESTIONS ANSWERED — 2026-08-07
>
> *"let's answer all the questions then continue non stop till you finish everything"* — the developer.
>
> Every question in this file is now closed by applying its own **"Default if unanswered"**, which is
> what that instruction authorises: each default was written to be the safe, reversible choice, and
> adopting them wholesale is a smaller decision than any one of them individually. **Nothing is
> blocking.** Milestone 1 proceeds.
>
> Two honest caveats on what "answered" means here:
>
> 1. **A default applied is not the same as a preference expressed.** Every one of these is a one-line
>    config or doc edit to overturn, and each resolution below says which line. Where a default would
>    have been expensive or irreversible to undo, it is called out in that question's entry.
> 2. **Four things remain genuinely blocked on the developer**, and no default can unblock them,
>    because they need data only the developer can obtain: the DevTools cURL captures for the first
>    sources (hard rule 1 forbids writing an endpoint from memory), IMAP credentials for the alert
>    mailbox, one real portal alert email to shape the parser against, and the `plafonds` figures for
>    Q19. Those are inputs, not decisions — they are listed in `docs/plans/archive/milestone-1-pipeline.plan.md`.
>    **Three of the four are now closed:** the alert email and the IMAP credentials arrived
>    2026-08-25, and the `plafonds` figures were FETCHED and committed 2026-08-26 — hard rule 1
>    forbids writing a ceiling from memory, not verifying one against a dated official source. Only
>    the AL'in capture remains genuinely blocked on the developer.

---

## Part 1 — The filters, enumerated

> **SETTLED 2026-08-07 — every row below is now a ruling, and `config/rent/criteria.json` is its
> implementation.** The tables are kept as written because they record what was reconsidered and why.
> Where a row asked a question, the answer is in the *Resolution* column added below each table. The
> committed config is the authority from here on; if the two ever disagree, the config is right and this
> file is stale.

### Resolution of 1a — hard disqualifiers as shipped

| # | Shipped in `config/rent/criteria.json` | Resolution |
|---|---|---|
| F1 | (not a config key) | `Tenure::isExcluded()` is hard-coded. §1 is not user-overridable, so it is deliberately absent from config. |
| F2 | `communes` — **now empty: REGION MODE** | **REVERSED 2026-08-22** (see Q1). The ten named communes yielded **zero matches** across all four live sources when measured that day, while 60 listings sat in 78/95; the list is now empty and the prefixes are the whole location filter. Ranking still prefers the Boucle de Seine. Originally **kept as a hard filter** (Q1). The named neighbours are **not** added: adding a commune is a one-line config edit the day it is wanted, and adding ten unrequested ones widens the search on my judgement rather than the developer's. Matching is on **normalised commune + postcode only**, never a substring search over the description — the prototype's *"proche Chatou"* over-match is fixed by construction. |
| F3 | `postcode_prefixes` — `78`, `95` | **`92` REMOVED.** F2 and F3 both apply, so `92` only ever admitted Bezons-adjacent spillover — and every commune in F2 is in 78 or 95. A prefix that can never match anything the commune filter also passes is dead config that reads as intent. If a 92 commune is ever wanted, it is added to F2 and F3 together. |
| F4 | `min_rooms: 3` | **REVERSED 2026-08-22** (see Q3): 10 of the 13 listings that got past the location filter were rejected here alone, every one under the rent ceiling. Originally kept at `4` (Q3). Unknown room count does **not** disqualify. |
| F5 | `min_surface_m2: 75` | Kept (Q3). Unknown surface does **not** disqualify. |
| F6 | `max_rent_cc: 1800` | Kept, **charges comprises**, hard (Q2). An unknown CC rent does not disqualify. |
| F7 | *(absent)* | **Removed as a disqualifier** (Q5) — it is score component S5/S6 now. |
| F8 | *(absent)* | **Removed entirely** (Q5), as this row itself anticipated. |
| F9 | `exclude_patterns` | **Rewritten.** The `meubl` bug is real and is fixed by matching the term only as a **property type** — `location meublée`, `appartement meublé`, `meublé de tourisme`, `bail mobilité` — never as a bare stem. `cuisine équipée et meublée` therefore passes, where the prototype's `meubl` excluded it. All the suggested additions are adopted except `logement social` / `PLAI` / `PLUS`, which are **deliberately NOT here**: tenure exclusion is the classifier's job and duplicating it in a user-editable regex would create a second, weaker copy of §1 that a config edit could delete. |

### Resolution of 1b — score weights as shipped

Adopted as proposed, with two changes. **S2 ships at weight 0 and disabled** (Q1) — it cannot silently
contribute a quarter of the score with no API key. **S8 is adopted**: tenure confidence scales the
whole score rather than adding to it, because a low-confidence LLI verdict should rank below a
high-confidence one at every other component, not merely lose a fixed number of points.

| # | Weight | Note |
|---|---|---|
| S1 | 25 | Needs a **ranked** list. Shipped ranking, revisable in one edit: `commune_rank` puts **Sartrouville, Houilles, Maisons-Laffitte** top, then Le Vésinet / Chatou / Croissy-adjacent, then the rest. |
| S2 | 0 | Disabled (Q1). |
| S3 | 15 | Rent headroom below the ceiling. Zero when CC rent is unknown, with a `reasons[]` entry. |
| S4 | 10 | Surface above the minimum. |
| S5 | 15 | Floor ≤ 1 **or** an elevator is declared. |
| S6 | −20 | High floor **and** an elevator explicitly absent. **Not** applied when the lift is merely unmentioned — that is the `null`-is-not-`false` trap (hard rule 9), and it is why S6 is a separate component rather than the negation of S5. |
| S7 | 10 | Freshness. |
| S8 | × factor | Confidence multiplier, not an additive term. `UNKNOWN` never reaches scoring at all — it digests. |

Weights are normalised, so the enabled set summing to 75 rather than 100 does not depress every score.

### Resolution of 1c — notification routing as shipped

| Route | Ruling |
|---|---|
| High priority | **score ≥ 70** → immediate push at high priority. Below that, immediate at normal priority. Nothing is batched: the brief's whole premise is that good LLI stock goes within hours, so a batching delay would defeat the tool. |
| Normal | every `MATCH` below 70. |
| *"à vérifier"* digest | **on demand via `scout --domain=rent digest`**, plus an automatic daily emission at the first run after 08:00 local. Not per-listing — that is what makes it a digest rather than a second notification stream. |
| `SOURCE_BROKEN` | **same channel, low priority, and de-duplicated**: at most one alert per source per 24 h, so a source broken for a week does not send seven identical pushes and train the developer to ignore them. |
| Rent drop | **any drop of ≥ 20 € or ≥ 2%**, whichever is smaller in absolute terms. A 5 € correction is noise; 20 €/month is 240 €/year and worth a glance. |

This is the part to review line by line. Every value below is **taken from
`prototype/sources.yaml`**, which the developer wrote — so these are existing choices, not proposals.
Say `keep` for anything that is already right; the point of the table is that nothing gets adopted
silently just because it was in the prototype.

### 1a. Hard disqualifiers — a listing failing ANY of these is never notified, only logged

| # | Filter | Current value | Notes / what to reconsider |
|---|---|---|---|
| F1 | **Tenure** | **LLI + LIBRE in; PLAI/PLUS/PLS/ANRU/ANAH never** | Settled 2026-08-06 (Q4). The exclusion is non-negotiable (`CLAUDE.md` §1); the inclusion of LIBRE is a product choice. |
| F2 | **Communes** | sartrouville, houilles, maisons-laffitte, bezons, cormeilles, argenteuil, le vesinet, chatou, carrieres-sur-seine, montesson | 10 communes, boucle de Seine. Missing neighbours worth a yes/no: **Le Pecq, Croissy-sur-Seine, Sartrouville-Plateau, Bougival, La Frette, Herblay, Conflans, Achères, Poissy, Saint-Germain-en-Laye**. Also: is this a hard filter at all, or a *preference weight* with a commute constraint as the real filter (**Q1**)? |
| F3 | **Postcode prefixes** | `78`, `95`, `92` | `92` admits Nanterre/Colombes/Courbevoie — much pricier. Deliberate, or a leftover? Note F2 and F3 currently **both** apply, so `92` only matters for Bezons-adjacent spillover. |
| F4 | **Minimum rooms** | `4` (T4) | Stated reason: 2 children. Would a **large T3** (≥70 m² with a separate dining space) ever be acceptable? T4 is the single most restrictive filter in LLI stock. |
| F5 | **Minimum surface** | `75` m² | Interacts with F4: a 75 m² T4 is tight; an 80 m² T3 is not in scope. Worth deciding which of the two is the real constraint. |
| F6 | **Max rent** | `1800` € | **Charges comprises or not?** The prototype does not say, and sources disagree — this is the #1 source of wrong disqualifications (`CLAUDE.md` hard rule 9). Also **Q2**: hard cutoff, or soft with +10% still notified at a lower score? |
| F7 | **Max floor** | `1`, ignored when an elevator is declared | The brief wants floor/elevator to be a **large score penalty**, not a hard reject (**Q5**). As a hard reject this silently drops a 3rd floor *with* a lift whenever the listing fails to mention the lift — which is common. |
| F8 | **Require elevator** | `false` (a declared "sans ascenseur" is *not* rejected) | Consistent with F7 being soft. If F7 becomes soft, this can go entirely. |
| F9 | **Exclude regex** | `colocation\|meubl\|residence etudiante\|senior` | Suggested additions: `logement social`, `PLAI`, `PLUS`, `viager`, `parking`, `box`, `local commercial`, `bureau`, `LMNP`, `saisonnier`, `sous-location`, `foyer`, `EHPAD`, `résidence services`. Note `meubl` also catches "meublé de tourisme" — fine — but a listing saying "cuisine meublée" would be **wrongly excluded**. That is a real bug in the current regex. |

### 1b. Score components — 0–100, drives ordering and notification priority

These are from the brief §5; **none has a weight yet**, and the weights are a decision.

| # | Component | Direction | Weight (proposed) | Notes |
|---|---|---|---|---|
| S1 | Commune preference | bonus | 25 | Needs a **ranked** commune list, not the flat set in F2. Which 3 are top? |
| S2 | Commute time to a target station | bonus | 25 | Requires **Q1** (which station) and **Q6-adjacent** (IDFM/PRIM API key). Off until then. |
| S3 | Rent headroom below ceiling | bonus ∝ headroom | 15 | Cheap to compute, good tiebreaker. |
| S4 | Surface above minimum | bonus | 10 | |
| S5 | Floor ≤1 **or** elevator present | large bonus | 15 | The other half of **Q5**. |
| S6 | High floor, no elevator | large penalty | −20 | 4th floor no lift is the current flat — the thing being escaped. |
| S7 | Freshness (first seen < 1h) | bonus | 10 | The brief notes good LLI units go within hours, so this may deserve more. |
| S8 | Tenure confidence | penalty when low | — | **Proposed addition, not in the brief.** A `LLI` verdict at 0.65 confidence and one at 0.98 should not rank equally. Worth adding? |

### 1c. Notification routing

| Route | Trigger | Current | Decision needed |
|---|---|---|---|
| High priority | new match, score ≥ ? | no threshold defined | What score, if any, gets an immediate push vs. batched? |
| Normal | new match | all matches | |
| *"à vérifier"* digest | `UNKNOWN` tenure on a mixed source | required by §4 | Delivery cadence — daily digest, or on demand via a CLI verb? |
| `SOURCE_BROKEN` | 3 consecutive empty runs vs non-zero baseline | required by §8 | Same channel as matches, or a quieter one? |
| Rent drop | price history change on a known listing | required by §7 | Notify on any drop, or only ≥ N €/%? |

---

## Part 2 — Blocking architecture decisions

### Ⓐ Q1 — ANSWERED 2026-08-07: commune stays the hard filter — **WIDENED 2026-08-22**

> **WIDENED TWICE ON 2026-08-22, and location is still a hard filter.** `communes` is empty — the
> name is not checked and `postcode_prefixes` is the whole location filter — and those prefixes are
> now **all eight Île-de-France departements** (75, 77, 78, 91, 92, 93, 94, 95), having been 78/95
> for the first half of the day. The second widening is a developer ruling rather than a
> measurement; the first was measured, and its reasoning is below. The reason is measured, not preferential: on live payloads that day the ten named communes
> matched **nothing at all** across four sources, while **60 listings** sat inside those two
> departements. The Boucle de Seine preference is not lost — `commune_rank` still orders results, so
> what changed is that a neighbouring commune stopped being a silent rejection and became a
> lower-scoring match.
>
> This does NOT reopen the commute question: S2 is still weight 0 and inert until an IDFM key
> exists. If anything it makes that question sharper, because a departement-wide filter is exactly
> the case where *"≤ N minutes door-to-door"* would beat a geographic one.
>
> **Reverses with one line, at either level:** cut `postcode_prefixes` back to `["78", "95"]` for
> the departements, or put the ten communes back in `config/rent/criteria.json` for the named list —
> they are listed verbatim in that file's own `_communes` note. The loader refuses `communes` and
> `postcode_prefixes` both being empty, so neither form can degrade into no filter at all.
>
> One consequence of the second widening, worth stating because it is invisible: `commune_rank`
> still names only the ten Boucle de Seine communes, so a Seine-Saint-Denis flat matches and simply
> scores 25 points lower. The preference survived the widening; it did not become uniform.

**ANSWERED 2026-08-07 — the default applies.** F2 remains a hard filter; S2 (commute) ships with
weight 0 and is inert until an IDFM key exists. The transit layer is therefore NOT milestone-1
infrastructure. `config/rent/criteria.json` carries `commute.enabled: false` so turning it on
is one visible edit, not a code change.

> **WHAT ACTUALLY SHIPPED, and the citation this paragraph carried was wrong from the day the code
> landed (corrected 2026-09-04, Deep row 33).** There is no `src/php/Rent/Enrich/Transit.php` and
> there never was: the layer is `Enrich/CommutePlanner` (the interface) and `Enrich/NavitiaCommute`
> (the IDFM/PRIM implementation over the ordinary `HttpClient` seam), built 2026-08-26. The
> *ruling* above stands unchanged — commute is still a clamped score component and never a
> disqualifier, and it is still OFF in the committed config. What is no longer true is *"inert
> until an IDFM key exists"*: the key exists, and the activation lives in the gitignored
> `config/rent/criteria.local.json`, so commute is ON in production and OFF in CI, the fixtures and
> the sabotage ledger. That asymmetry is deliberate and load-bearing — it is also the reason a
> pipeline defect can pass 2 000+ tests and still fire on the deployed watcher's first pass.

`spec/PROJECT_BRIEF.md` §0.1. F2 is a flat commune set. The alternative is *"≤ N minutes door-to-door
to a named station"* via the IDFM/PRIM API, with commune as a score weight instead of a filter.
**Default if unanswered:** keep F2 as the hard filter, ship S2 disabled, revisit after milestone 8.
Changes: whether `src/php/Rent/Enrich/Transit.php` is milestone-1 infrastructure or a later add-on.

### Ⓐ Q2 — ANSWERED 2026-08-07: hard cutoff, charges comprises — **CEILING LOWERED 2026-08-22**

> **LOWERED to 1200 € CC on 2026-08-22**, developer ruling. The MECHANISM is unchanged and that is
> what this question was about — still a hard cutoff, still charges comprises, still `null`-safe.
> Only the number moved. **Measured after the change rather than inferred from before it: the live
> yield is 83 matches out of 478 listings** across the four institutional sources.
> **Reverses with one line:** `max_rent_cc` in `config/rent/criteria.json`.
>
> That number is worth dwelling on, because the obvious prediction was ZERO — all eight listings
> that matched under the old 1800 ceiling quoted 1258–1669 € CC, so every one of them dies at 1200,
> and a first draft of this entry said exactly that and stopped. It was wrong for the reason this
> repo keeps re-learning: the ceiling did not move alone. The region went to all eight departements
> and the surface floor to 50 m² in the same change, opening a pool the old criteria never looked
> at. The eight became zero and a different 83 appeared. **Never predict a yield from the previous
> filter's matches** — one poll on a throwaway `RENT_SCOUT_DB` settles it.
>
> Two consequences of the 83, both real, neither a defect to quietly fix:
>
> - **Almost none is in the Boucle de Seine.** Les Ulis, Aulnay-sous-Bois, Pierrefitte, Épinay,
>   Vitry, Fresnes — 91, 93, 94 — with Dourdan, Dammarie-les-Lys and Savigny-le-Temple scoring
>   highest. `commune_rank` names only the ten Boucle communes, so nearly every match scores 0 on
>   S1. The ranking is not broken; there is simply nothing under 1200 € CC in the ranked communes.
> - **Nothing reaches `high_priority_score: 70`.** Scores run **16–48**, so the `!!` marker cannot
>   fire under this combination of filters and that distinction is currently dead. Left alone
>   deliberately: lowering the threshold to make the marker appear would be tuning the instrument to
>   the reading.

**ANSWERED 2026-08-07 — the default applies.** Hard cutoff at 1800 € **charges comprises**. Every
source is normalised to CC before comparison; a listing whose CC rent is unknown is NOT disqualified
on rent (hard rule 9 — `null` is not zero), it loses the rent-headroom score component and carries a
`reasons[]` entry saying the rent basis was unknown.

§0.2. F6 is `1800`. Soft would notify up to `1980` at a reduced score.
**Default if unanswered:** hard cutoff at 1800 **charges comprises**, and normalise every source to CC.
Changes: the criteria engine's return type (boolean vs. graded).

### Ⓐ Q3 — ANSWERED 2026-08-07: T4 / 75 m², both hard — **T4 REVERSED 2026-08-22**

> **REVERSED 2026-08-22, on measurement rather than preference.** `min_rooms` is now **3**. The
> question this entry left open was its own words — *"is a large T3 ever acceptable?"* — and the
> live data answered it: of the 13 listings that got past the location filter that day, **10 were
> rejected by this filter alone**, and every one of them was under the rent ceiling (9 In'li LLI at
> 1017–1353 € CC, plus a CDC 82 m² at 1669 €). T4-or-larger at ≤1800 € CC did not exist across the
> four live sources. `min_surface_m2` was **unchanged at 75** when this was written and is what kept
> this a real filter. **BOTH HALVES HAVE NOW MOVED: the surface floor went to 50 m² later the same
> day**, developer ruling, so a 55 m² T3 IS in scope — the sentence above is preserved rather than
> rewritten because the reasoning it records is still why `min_rooms` is 3, and the floor beneath it
> is now lower rather than absent. What still holds: an unknown surface does not disqualify, and 50
> m² is a floor a real T3 clears and a converted studio does not. **Reverses with one line each:**
> `min_rooms` back to `4`, `min_surface_m2` back to `75`, in `config/rent/criteria.json`.

**ANSWERED 2026-08-07 — the default applies.** `min_rooms: 4`, `min_surface_m2: 75`, both hard
disqualifiers. A large T3 is not in scope. An **unknown** room count or surface does not disqualify —
that is the prototype's bug (hard rule 9); it scores as if at the minimum and says so in `reasons[]`.

§0.3. Answered in substance by F4/F5 (`4` / `75`). Open only as: is a large T3 ever acceptable?
**Default if unanswered:** T4 / 75 m², as the prototype has it.

### Ⓐ Q4 — ANSWERED 2026-08-06: PLS **out**, LIBRE **in**

> *"I only want private housing or loyer/logement libre and logement intermediaire/action logement!
> no social housing!"*

- **PLS → EXCLUDED**, joining PLAI and PLUS. PLS is social housing (SNE + commission d'attribution), and
  the ruling is no social housing. It is no longer "genuinely ambiguous" for this project — it is out.
  The excluded set is now `PLAI`, `PLUS`, `PLS`, `ANRU`, `ANAH`, `conventionné`-absent-intermediate-label.
- **LIBRE → IN SCOPE**, as a full match alongside LLI. This is the largest architectural consequence of
  any answer so far: the private portals stop being optional, and the `email_alert` (IMAP) adapter moves
  from milestone 6 to milestone-critical.
- **"action logement" is a SOURCE, not a tenure** — see § "The Action Logement wrinkle" below. Its
  intermediate stock is in scope; its social stock is not, and the same page carries both.

~~Ⓑ Q4 — original wording~~ (kept for the record): *Is PLS in scope? Are LIBRE listings in scope?*
Default had been PLS → digest, LIBRE → out. **Both overridden by the answer above.**

### Ⓐ Q5 — ANSWERED 2026-08-07: scoring penalty, never a reject

**ANSWERED 2026-08-07 — the default applies.** The brief wins over the prototype. Floor and elevator
are score components only (S5 +15 / S6 −20). `max_floor` and `require_elevator` do NOT exist in
`config/rent/criteria.json`, so the prototype's silent drop cannot be reintroduced by a config edit.

§0.5. The prototype hard-rejects (F7); the brief wants a penalty (S5/S6).
**Default if unanswered:** follow the brief — scoring penalty, no hard reject. The prototype's
behaviour silently drops good flats whose listing omits the lift.

### Ⓐ Q6 — ANSWERED 2026-08-07: OFF

**ANSWERED 2026-08-07 — the default applies.** Income-eligibility checking is **off**. `RFR_N2` stays
out of `.env.example`'s required set and nothing reads it. Ceilings are checked by hand. This keeps
personal financial data out of the process entirely rather than merely out of git.

§0.6. Requires the RFR N-2 figure in `.env`. **This is personal financial data** — it stays in `.env`,
never in git, never in a log, never in a notification body.
**Default if unanswered:** **off**. Do not ask for the figure; check ceilings manually.

### Ⓐ Q7 — SUPERSEDED 2026-08-06 by Q16, closed 2026-08-07

**CLOSED 2026-08-07.** Neither. The stack is **PHP 8.5** now and **phorj** for the pure core once its
two missing modules land (Q16). `pytest` / `ruff` / `mypy` are not part of this project's toolchain;
the runner is PHPUnit's PHAR. The Ⓐ marker below was already stale — recorded here so it stops
reading as an open toolchain question.

§0.7. **Answered by the artefacts**: `prototype/scout.py` is Python, and `ruff` is already available in
the container. Treating this as **Python** unless told otherwise. Confirm the toolchain: `uv` or plain
`pip` + `requirements.txt`? `pytest` + `ruff` + `mypy`?

### Ⓐ Q8 — ANSWERED 2026-08-07: Docker on a VPS, mounted volume

**ANSWERED 2026-08-07 — the default applies.** Docker on a VPS with `state/` on a mounted volume;
`scout --domain=rent run --watch` with jitter rather than cron, so the process owns its own schedule and the run log
is continuous. **GitHub Actions is ruled out explicitly**: no persistent disk means no seen-set, which
means re-notifying everything on every run.

§0.8. Changes where state lives and whether the SQLite file is durable. GitHub Actions in particular
has **no persistent disk**, which breaks price history and the seen-set — and a public repo would leak
the criteria.
**Default if unanswered:** Docker on a VPS with a mounted volume; `--watch` with jitter rather than cron.

### Ⓐ Q9 — ANSWERED 2026-08-07: email **and** ntfy, both optional, console always

**ANSWERED 2026-08-07.** The open half — email only, or a push channel alongside — resolves to
**both, and neither is required to run.** `config/rent/criteria.json` carries a `notify.channels` list; the
shipped default enables `console` only, because a channel that needs a credential cannot be the
default in a repo whose `.env` is not filled in. Enabling `ntfy` or `email` without its credential is a
**startup refusal**, not a silent no-op — an unsent notification is the failure this project cannot
afford.

> **AMENDED twice, and the second amendment inverts what the sentence above implies.** Q28 already
> scoped the refusal: it fires only when NOTHING is usable, so with `console` in the list — which
> the shipped config has — a credential-less `ntfy` or `email` is reported and disabled, never a
> refusal. **And as of 2026-08-25 `console` does not COUNT as a delivery, nor does `email` over
> `SMTP_TRANSPORT=file`.** So a console-only deployment is the very state this answer says the
> project cannot afford — every notification unsent, by construction — and it is deliberately still
> allowed to start, because `run --once` at a terminal is that shape. What changed instead: nothing
> is marked notified, the run warns at startup, `doctor` names every channel and whether it counts,
> and `test-notify` exits 1. Reversing this means making `hasRemoteChannel()` false a startup
> refusal in `RentScout::runCommand()`. LIBRE listings go fast, which is the argument for push; email is the argument for a readable
record. Having both, gated on config, costs one interface and two small classes.


> *"just filter and show me/email me the list"*

**Email is in.** Open only on whether email is the ONLY channel or whether a push channel (ntfy) rides
alongside it for time-critical LIBRE listings, which go fast. See the question set of 2026-08-06.

~~Ⓑ Q9 — original~~: Telegram / ntfy / email / Slack?
§0.9. `prototype/sources.yaml` scaffolds **ntfy** (topic blank) and **SMTP** (`SCOUT_SMTP_PASS`).
**Default if unanswered:** ntfy — no account, no bot token, works on mobile, and the prototype already
leans that way. Needs a topic name; treat the topic as a **secret** (anyone who knows it can read the
notifications).

### Ⓐ Q15 — ANSWERED 2026-08-07: CDC Habitat stays disabled

**ANSWERED 2026-08-07 — the default applies.** `cdc_habitat` ships in `config/rent/sources.json` with
`enabled: false` and a `_comment` naming the `robots.txt` `Disallow`. It is not enabled until the real
endpoint path is known to sit outside it; if it does not, CDC Habitat moves to the email-alert route.

Measured, not assumed. If CDC Habitat's listing endpoint sits under that path, polling it violates
`robots.txt`, which `CLAUDE.md` hard rule 5 forbids without exception.
**Default if unanswered:** A3 stays `enabled: false` until the real endpoint path is known. If it is
inside the `Disallow`, CDC Habitat moves to the email-alert route like a private portal. **Never work
around it.**

### Ⓐ Q16 — ANSWERED 2026-08-06 by the developer; closed here 2026-08-07

**This was still marked Ⓑ BLOCKING with no resolution while the banner above claimed nothing was
blocking.** A review caught it. The developer answered it on 2026-08-06 and the Decisions Log records
the answer; only the heading was never updated.

**What holds:** **PHP 8.5 is the implementation.** phorj takes the *pure core* — `models`, `tenure`,
`criteria`, `dedup` — via `phg transpile` once its two missing modules land, so the classifier runs
byte-identically on three legs and the differential test means something. Everything touching IMAP,
HTTP, SQLite or SMTP stays PHP-only, because `phg` refuses those domains by design.

**The "Default if unanswered" written below is SUPERSEDED and must not be applied.** It says *"Track 1
in phorj now… with no Python written in the meantime"*, which contradicts both the developer's answer
and the 1000-test PHP core that exists. A session applying it literally would rewrite the classifier.
It is kept for the record only.

### ~~Ⓑ Q16 — original wording~~ (kept for the record)

*"i think i want to do it with this language! - it is WIP! but i think it can do it!!"* — referring to
[phorj](https://github.com/tmessaoudi-official/phorj), the developer's own language.

**I assessed it against this project's actual needs rather than guessing.** It is far more capable than
"WIP" suggested — and it has exactly two gaps, both load-bearing for Track 2.

**phorj HAS, verified in phorj's `Cargo.toml` and phorj's `docs/EXTENSIONS.md` at `1.0.0-nightly.0`:**
`Core.Json` (default feature) · `Core.Database` = **SQLite via bundled rusqlite** (`database = ["dep:rusqlite"]`) ·
`Core.HttpClient` (opt-in `http-client`: sync HTTP/1.1 over std TcpStream + rustls + webpki-roots) ·
`Core.Mail` (opt-in: SMTP **send** with auth, STARTTLS/TLS, optional DKIM) · `Core.Http` incl. a Router ·
`Core.Regex` · `Core.Decimal` with RoundingMode · `Core.Csv` · `Core.Secret` · `Core.Time` · `Core.Url` ·
`Core.Log` · `Core.Config` · `Core.Env` · `Core.Console` · `Core.Process` · `Core.Cryptography` ·
`Core.Validation` — and it compiles to a **single standalone native executable**.

**phorj LACKS exactly two things this project needs:**

1. **IMAP — and it is explicitly DEFERRED, by the developer's own ruling.** phorj's `docs/plans/MASTER-PLAN.md`
   records DEC-413 (2026-07-29): IMAP is an Appendix-A row *"recorded as DEFERRED… post-1.0"*, reason
   given as *"PHP itself unbundled it"*. `Core.Mail` is SMTP **send**, not IMAP **receive**. Track 2 —
   the private portals — is email-alert ingestion over IMAP. **This is the blocker, and it is precisely
   the half that cannot be done another way**, because 5 of the 11 portals 403 a plain HTTP client.
2. **No HTML parser.** `Core.Html` is a *builder* (`Html.div`, `Html.el`, `Html.attr`) — it renders HTML,
   it does not parse it. An HTML5 parser exists as a planned item (referenced as W4-10), not shipped.
   So `type: html` adapters are not buildable in phorj today; `type: json` ones are.

**What that implies, and it is a genuinely good fit rather than a rejection:**

phorj can build **all of Track 1 today** — HttpClient + Json + SQLite + SMTP + Regex is the complete
happy path for In'li and the JSON-endpoint landlords, and a native single binary is a *better* deploy
story than Python for a self-hosted watcher. It cannot build Track 2 until IMAP lands.

**Options — a decision, not a default:**

1. **Track 1 in phorj now; Track 2 waits for `Core.Imap`** (recommended). Build order already put Track 1
   first because it is the differentiated half, and it happens to be exactly what phorj can do. rent-watch
   becomes phorj's first real application, which is how a language finds its bugs. Cost: Track 2 is
   blocked on a post-1.0 phorj item, and every phorj bug becomes a rent-watch blocker with one person who
   can fix it — you.
2. **Everything in Python now**, port later. Fastest to a working tool on both tracks; `imaplib`,
   `requests`, `BeautifulSoup`, `sqlite3` are all stdlib-or-trivial, and `prototype/scout.py` already
   exists. Cost: no dogfooding, and a rewrite later if you still want phorj.
3. **Hybrid — phorj core + a small Python IMAP feeder** writing into the same SQLite file. Both tracks
   ship now and the core is dogfooded. Cost: two toolchains in one repo, which is real complexity for a
   single-user tool.
4. **phorj for everything, and build `Core.Imap` in phorj first.** Most ambitious. It makes rent-watch
   the forcing function for a real phorj feature. Cost: a language feature before any product feature.

**Default if unanswered:** option 1 — Track 1 in phorj, Track 2 deferred and clearly marked as blocked
on `Core.Imap`, with no Python written in the meantime.

**On the risk, stated in my own words rather than borrowed:** building a product on a pre-1.0,
single-developer language means every language bug becomes a product blocker with exactly one person who
can fix it. For a **commercial platform** that is reckless. For a **single-user personal tool** it is a
reasonable trade, and arguably the point — rent-watch becomes the forcing function that finds phorj's
bugs on real work rather than on test programs. That is the actual argument, and it needs no other repo
to make it.

*(An earlier version of this section cited another repo's `CLAUDE.md` as precedent. That was a defect,
not a supporting detail: this repo cannot read that file, a future session here cannot verify it, and
rent-watch's own `CLAUDE.md` is the only authority that governs rent-watch. Removed 2026-08-06 on the
developer's challenge — the same dangling-cross-reference class that `/repair` § S2 exists to catch.)*

### Ⓐ Q17 — ANSWERED 2026-08-06 by the developer; closed here 2026-08-07

**Also still marked Ⓑ, and this one mattered more.** The developer answered *"both in parallel yes"*.
The "Default if unanswered" written below says *"CLI first, **then** a read-only local web digest…
later"* — so applying the default would have **overridden an explicit answer with my own guess**,
which is exactly what this file's own caveat warns against. It was caught by a review, not by me.

**What holds:** **the CLI and the read-only web digest are built in PARALLEL**, from the start. This
amends `spec/PROJECT_BRIEF.md` §12, which rules a web UI a non-goal. Constraints kept in full:
**read-only, localhost, no auth, no multi-user.** Write actions or remote access would be a NEW
decision, not an extension of this one.

### ~~Ⓑ Q17 — original wording~~ (kept for the record)

The brief rules a web UI a **non-goal** (`spec/PROJECT_BRIEF.md` §12: *"No web UI. CLI plus push
notifications. A read-only HTML digest is acceptable later."*). The question reopens it, so it needs a
ruling rather than an assumption — the brief is a ruling set.

1. **CLI first, then a read-only local web digest** (recommended). The CLI is what `--once` / `--watch`
   need regardless, and the notification is the primary output. The web page earns its place *later*, for
   the thing notifications are bad at: browsing 300 accumulated listings, comparing them side by side,
   and tuning filters without editing YAML. phorj makes this cheap — `Core.Http` Router plus the
   `Core.Html` builder are already there, and it stays **read-only and localhost**, so no auth, no
   multi-user, no attack surface. This is the brief's *"read-only HTML digest is acceptable later"*, taken
   up deliberately.
2. **CLI only.** Smallest surface, fastest, exactly the brief. Cost: filter tuning stays a YAML-edit loop
   and there is no way to browse history.
3. **Web app first.** Nicer to use, but you would be building a UI before the classifier that makes the
   data trustworthy — and the notification is what actually gets you the flat.
4. **Both, in parallel.** Doubles the surface before either is proven.

**Default if unanswered:** option 1. Explicitly a *read-only, localhost, no-auth* digest — if it ever
grows write actions or remote access, that is a new decision, not an extension of this one.

### Ⓐ Q10 — ANSWERED 2026-08-07: no

**ANSWERED 2026-08-07 — the default applies.** No browser automation. `type: browser` is a recognised
value in the source schema that the loader **refuses** with a clear message, rather than an
unimplemented adapter that fails at fetch time. Email-alert ingestion is the private-portal path.

§0.10. Only relevant for a source that is impossible otherwise. Chromium is pre-installed in this
container.
**Default if unanswered:** **no** — the brief makes email-alert ingestion the private-portal path, and
`browser.*` stays an unimplemented opt-in stub.

### Ⓐ Q11 — ANSWERED 2026-08-07: criteria stay non-personal in git

**ANSWERED 2026-08-07.** Making the repo private is the developer's action and cannot be done from
here. What IS done from here is the mitigation that makes it survivable either way: `config/rent/criteria.json`
is committed with the **prototype's already-public values** (they have been in `prototype/sources.yaml`
in this public repo since before this session) and nothing personal is added to it — no name, no
employer, no income figure, no exact address, no household detail beyond the room count that F4 already
implies. A `criteria.local.json` override, gitignored, is read when present and wins field-by-field, so
genuinely private tuning never has to enter git.

§0.11 recommends **private**. The repo is currently **public** (it was made public during this session
so the sibling repos could be cloned). That is a real exposure: `config/criteria.yaml` will carry the
target communes, the budget and the household composition. `.env` is gitignored, so no credential is at
risk — but the criteria themselves are personal.
**Recommendation: make it private before `config/criteria.yaml` lands.** Nothing committed so far
contains personal data.

---

## Part 2c — Raised by review rounds 5–7 (2026-08-07)

One question, and it is a genuine notify/digest tradeoff I decided unilaterally while closing a §1
breach. It is resolved in the safe direction, so nothing is blocked — but the cost falls on you, in
digest entries, every day the tool runs.

### Ⓐ Q21 — ANSWERED 2026-08-07: keep the guard

**ANSWERED 2026-08-07 — the default applies (option 1).** The shouted-`PLUS` doubt stays exactly as
it is. Nothing changes; `trap-005b` keeps digesting. Revisit only against real captured payloads.


`PLUS` is both the mainstream social-housing scheme and one of the commonest words in French. The
classifier holds it behind a collocation guard: an uppercase `PLUS` next to a financing noun is the
scheme, one followed by a known comparative (`PLUS DE 70 M2`) is the adverb. Rounds 5–7 showed the
noun list is closed and always will be — `Prêt PLUS`, `Financé PLUS`, `Agréé PLUS`, `Gamme PLUS` all
went silent, and on a pure source such a listing was NOTIFIED at confidence 50 with `reasons[]`
reading *"aucun signal dans l'annonce"*.

**What I decided:** a shouted `PLUS` that no comparative explains is now a DOUBT wherever it
appears — prose, any field value, any field name — and doubts go to the *"à vérifier"* digest.

**What it costs you.** `trap-005b` is the honest example: an all-caps portal title reading
`T4 CROISSY-SUR-SEINE - 3 CHAMBRES, PLUS UN BUREAU` is ordinary French and now digests instead of
matching. Every SHOUTED listing whose title happens to contain `PLUS` followed by an ordinary word
lands in the digest. Lowercase `plus` is unaffected — the prose rule is case-sensitive precisely so
that `plus de 3 chambres` does not digest half the market (`trap-010` pins that).

**Options:**

1. **Keep it** *(recommended, and the current behaviour)*. A wasted application costs an afternoon
   and the tool's credibility; a digest entry costs one glance. Until real captured payloads exist
   we cannot even estimate how often shouted titles collide, and the estimate is what would justify
   loosening it.
2. **Narrow to institutional sources only.** Private portals (SeLoger, Leboncoin) publish no social
   stock, so the guard could be skipped there and `PLUS UN BUREAU` would match again. Cheap to do,
   and it trades a `default_tenure` declaration for a §1 protection — which is exactly the kind of
   config-driven exemption `CLAUDE.md` §1 warns about, so I did not do it unasked.
3. **Digest, but rank the doubt low** so it never competes with real matches for attention. Needs
   the notification layer, which does not exist yet.
4. **None of these / challenge the premise** — for instance, if you would rather see the false
   positives and filter by eye, say so and the floor comes out.

**Default if unanswered:** option 1. Nothing changes.

## Part 2d — Raised by starting milestone 1 (2026-08-07)

### Ⓐ Q22 — ANSWERED 2026-08-07: JSON. Milestone 1 is unblocked.

**ANSWERED 2026-08-07 — the default applies (option 1).** The two config files are
`config/rent/criteria.json` and `config/rent/sources.json`, parsed by `ext-json`. `spec/PROJECT_BRIEF.md` §9 and
`CLAUDE.md`'s architecture table are amended to say `.json` in the same change.

Three consequences worth writing down, because each one is a thing a later session would otherwise
have to rediscover:

- **Comments.** JSON has none, so any key whose name begins with `_` is ignored by the loader —
  `_comment` by convention. The loader **rejects unknown keys** that do NOT begin with `_`, so a typo
  is a loud validation error rather than a silently-ignored setting. That is the trade that makes the
  underscore convention safe rather than merely ugly.
- **`mixed_tenure` is not optional and not defaulted in the file.** `SourceProfile` defaults it to
  `true` in code, and the loader ALSO refuses a source block that omits it. Two independent guards on
  the one flag that disarms §1's fail-closed rule.
- **phorj can read the same file.** `Core.Json` is a default feature, so the shared-corpus argument
  that killed option 2 (PHP array files) is preserved.


**BLOCKING.** Nothing in milestone 1 above the store can be built until this is settled: the adapter
contract, the criteria engine and the CLI all read config, and the file format decides what you edit
by hand for the rest of the project's life.

`spec/PROJECT_BRIEF.md` §9 and `CLAUDE.md`'s architecture table both name `config/criteria.yaml` and
`config/sources.yaml`. **This container has no `ext-yaml`** — `php -m` does not list it — and
Composer cannot install `symfony/yaml` or any other parser, because the egress policy returns 403 on
`codeload.github.com` (`CLAUDE.md` § Gotchas: this is why the project has zero Composer
dependencies). So `.yaml` files would sit there unread.

Concretely, this is the smallest thing that has to load:

```yaml
inli:
  family: institutional
  default_tenure: LLI
  mixed_tenure: false        # ← this boolean arms the §1 fail-closed rule
  enabled: true
```

**Options:**

1. **JSON — `config/rent/criteria.json` + `config/rent/sources.json`** *(recommended)*. `ext-json` is already a
   hard requirement in `composer.json` and is always present. The parser is the language's, so there
   is no bespoke code between your file and `mixed_tenure`. The cost is real and worth stating: JSON
   has no comments, and the source definitions are exactly the kind of file that wants a comment
   explaining why a selector is what it is. Mitigation is a `"_comment"` key convention, which is
   ugly but honest.
2. **PHP array files — `config/criteria.php` returning an array.** Comments, trailing commas, no
   parser at all, and an editor that already understands the syntax. The cost is that a config file
   becomes executable code: a typo can be a fatal error rather than a validation message, and it
   closes the door on the phorj side ever reading the same file — which matters, because the shared
   corpus is the whole reason this project is written twice.
3. **Hand-rolled YAML-subset parser, keeping the `.yaml` files as specified.** Matches the spec
   exactly and reads best of the three. I recommend against it: a subset parser that mis-reads one
   boolean silently disarms `mixed_tenure`, and `CLAUDE.md` §1 is the one guarantee in this project
   that must not depend on code I wrote in an afternoon. If you want this, it needs its own test
   suite and its own sabotage cases before any config depends on it.
4. **None of these / challenge the premise** — e.g. if the real deployment host has `ext-yaml` and
   only this container does not, say so and option 3 becomes unnecessary rather than risky. That is
   a genuine possibility I cannot check from here.

**Default if unanswered:** option 1, and `spec/PROJECT_BRIEF.md` §9 plus `CLAUDE.md`'s architecture
table are amended to say `.json`.

### Ⓐ Q23 — ANSWERED 2026-08-07: leave all five

**ANSWERED 2026-08-07 — the default applies (option 1).** All five constants stay at their current
values. They can only be tuned against run history that does not exist yet, and all five alert in the
safe direction. Re-open when `scout --domain=rent doctor` has a month of real runs behind it.


**Not blocking** — all five have working defaults and all five alert in the safe direction. Raised so they
are tuned against real run history instead of defended as if they had been measured.

Spec §8 names two numbers (3 consecutive empty runs, a >70% drop below the rolling mean) and those
are implemented as written. A review panel found four more failure shapes it does not name, and
closing them needed thresholds the spec does not supply:

> **RE-DERIVED FROM THE CODE 2026-09-04 (Deep row 33).** The table below had drifted in three ways
> at once, and the third is the one that matters: it named a threshold the code does not use. All
> five constants were cited on `Store::`, where the 2026-09-01 `Core/RunStore` split left only
> `BUSY_TIMEOUT_MS`; `MIN_SPAN_FOR_NEVER_PRODUCED` was written as one day and has been **seven since
> 2026-08-07**; and the dual-window flaky rule — the whole of Track 6-A1, added because a source
> degrading over hours cannot move a seven-day ratio — was absent, so a reader tuning "the flaky
> threshold" would have found one of the two. Cited by CLASS AND CONSTANT, which survive an edit
> above them, and every value read out of `src/php/Core/RunStore.php` rather than recalled.

| Constant | Value | What it decides |
|---|---|---|
| `Core\RunStore::FLAKY_FAILURE_RATIO` | `0.3` | more than 30% of runs failing in the 7-day window ⇒ `WARN_FLAKY` |
| `Core\RunStore::MIN_RUNS_FOR_FLAKY` | `3` | below this many runs in the window, a failure *rate* means nothing |
| `Core\RunStore::FLAKY_SHORT_WINDOW_DAYS` | `1` | the SECOND, shorter window — a source degrading over hours never moves the seven-day ratio, so In'li's failure rate climbed for a day while `doctor` said `ok` |
| `Core\RunStore::FLAKY_SHORT_FAILURE_RATIO` | `0.2` | more than 20% failing inside that day ⇒ `WARN_FLAKY`. Measured against every other source rather than fitted to the one that broke |
| `Core\RunStore::MIN_RUNS_FOR_SHORT_FLAKY` | `20` | and it needs a DENSE window. At Q37 cadence a day holds ~100 passes; on a cron `--once` deployment it holds four, where one failure is 25% and means nothing. Deliberately inert there — the seven-day rule still covers it, which makes this a scope limit rather than a hole |
| `Core\RunStore::MIN_SPAN_FOR_NEVER_PRODUCED` | `604800` (**7 days**, raised from one on 2026-08-07) | how long a source must have been trying before "never produced an item" is read as a broken field map. The one-day value false-accused a new source answering 200 with zero items at the 24-hour mark; Q23 had already written down that a week was the honest floor and kept the day anyway. Stated cost: a genuinely broken field map on a NEW source goes unremarked for a week — acceptable only because a source that used to produce and stops is caught in three runs by `EMPTY_RUNS_BEFORE_BROKEN`, the far commoner shape |
| STALE's silence bound | `Core\RunStore::ROLLING_WINDOW_DAYS` (7 days) | no run in this long ⇒ the schedule itself has stopped |
| `Core\RunStore::BUSY_TIMEOUT_MS` | `5000` | how long a second writer waits before giving up. The one constant still mirrored on `Rent\Store\Store`, which sets the same `PRAGMA busy_timeout` on its own handle |

Three of the four shapes are the same class — a source that is not working and does not fail: one
erroring on half its fetches (the streak counter resets on any success, and the BROKEN rule reads
only the last run); one that answers HTTP 200 and parses zero items forever; and one whose schedule
stopped. The fourth is not: two processes contending for the database fails LOUDLY with
`SQLITE_BUSY`, which is precisely why `BUSY_TIMEOUT_MS` exists — it is a threshold on how long to
wait, not a detector for silence.

None of the five numbers is derived. `MIN_SPAN_FOR_NEVER_PRODUCED` in particular is justified by an
anecdote — three empty polls at a fifteen-minute interval was accusing a source of a bad field map
forty-five minutes after onboarding — and In'li LLI stock in one commune is legitimately empty for
days, so the honest floor could be a week rather than a day.

**Options:** 1. leave them as they are *(recommended — they can only be tuned against run history
that does not exist yet)*; 2. raise `MIN_SPAN_FOR_NEVER_PRODUCED` to a week, which trades a slower
bad-field-map alert for no false accusation of a genuinely quiet source; 3. raise the flaky ratio if
`scout --domain=rent doctor` turns out noisy on a flaky host; 4. none of these / challenge the premise.

**Default if unanswered:** option 1.

### Ⓐ Q24 — ANSWERED 2026-08-07: schema v3, with the criteria layer

**ANSWERED 2026-08-07 — the default applies (option 1).** `tenure`, `confidence_bp` and `signals_json`
land on `listings` in schema **v3**, together with Q25's `duration_ms` on `source_runs`, at the same
time as the criteria layer that consumes them — i.e. in this milestone.


**Not blocking milestone 1**, but it gets more expensive with every stored listing.

`.claude/agents/tenure-correctness-reviewer.md` is committed policy and asks that a stored row keep
the confidence and the signals, *"so a past verdict can be audited after a change"*. The `listings`
table keeps none of it — a listing stored under an old classifier cannot be re-evaluated or explained
without re-fetching it, and the source may have removed the ad by then.

Not done now because it is a schema change and the criteria layer that would consume it does not
exist. **Amended 2026-08-07:** `Store::migrate()` now HAS an upgrade path (`upgradeFrom()`), and
schema **v2 is already spent** — it was consumed by `listings.seen_epoch`. So this is a v3, and the
mechanism to carry it exists and is tested; only the decision is outstanding.

**Options:** 1. add `tenure`, `confidence` and `signals_json` at the same time as the criteria layer,
in one schema v3 with its migration *(recommended)*; 2. add them now, ahead of a consumer; 3. accept
that verdicts are not auditable and drop the reviewer-agent clause; 4. none of these.

**Default if unanswered:** option 1.

### Ⓐ Q25 — ANSWERED 2026-08-07: schema v3, same migration as Q24

**ANSWERED 2026-08-07 — the default applies (option 1).** `source_runs.duration_ms` lands in the same
schema v3 as Q24, and `scout --domain=rent doctor` prints it. `SourceHealth` gains a `lastDurationMs` field so the
spec's fourth `doctor` column stops being aspirational.


Spec §8: *"`scout --domain=rent doctor` command: run every source once, report status, **timing**, and item
counts."* Status and item counts are implemented; `source_runs` has no duration column and
`SourceHealth` has no timing field, so three of the four are done and the fourth is not.

Deferred rather than guessed at because only the CLI can measure a fetch, and the CLI does not exist.
Like Q24 it is a schema change, so the two should probably land together.

**Options:** 1. add `duration_ms` in the same schema v3 as Q24, when the CLI lands *(recommended)*;
2. add it now; 3. drop timing from `doctor`, amending spec §8; 4. none of these.

**Default if unanswered:** option 1.

## Part 2b — Raised by building the tenure classifier (2026-08-06)

Both of these were found by writing the code rather than by planning it, and both are currently
resolved in the safe direction — so neither blocks anything. They are recorded because the safe
direction has a cost, and the cost is yours to accept or overturn.

### Ⓐ Q18 — ANSWERED 2026-08-07: PLI keeps digesting

**ANSWERED 2026-08-07 — the default applies (option 1).** `financement: PLI` stays UNKNOWN → digest.
It is a small ageing tail and a digest entry costs one glance; promoting it would decide a product
question inside the classifier on the strength of the label sounding intermediate.


`PLI` is the pre-2014 intermediate scheme, superseded by LLI when ordonnance 2014-159 created the
current regime. Older stock is still marketed under the PLI label, and it is **genuinely not social
housing** — no SNE number, no commission d'attribution.

But it is not in the `CLAUDE.md` glossary, and Q4 ruled on PLS and LIBRE without mentioning it.
Treating it as intermediate because it *sounds* intermediate would decide a product question inside
the classifier, which is exactly the move this project forbids.

**Current behaviour (the default if this stays unanswered):** a `financement: PLI` field yields
`UNKNOWN` → the *"à vérifier"* digest. Fixture `unknown-003-unrecognised-field-value`.

- **Option 1 — leave it (recommended).** PLI stock in IdF is a small, ageing tail; a digest entry
  costs one glance. Zero risk.
- **Option 2 — treat PLI as eligible**, joining LLI. Slightly more reach, and defensible on the
  facts. Requires a fixture change and a glossary row.
- **Option 3 — exclude it outright.** Not recommended: it would drop genuinely eligible stock, and
  the reason to exclude a tenure here is ineligibility, which does not apply.

### Ⓐ Q19 — ANSWERED 2026-08-07: tier 4 stays inert. **SUPERSEDED 2026-08-26: it is ARMED.**

**ARMED [2026-08-26, `8ddb8d0`].** The figures were fetched from two dated official publications and
committed inside `Core/PlafondBands` with their URLs: the intermediate ceilings from BOFiP
**BOI-BAREME-000017** (published 2026-03-10, CGI annexe III art. 2 terdecies H), the social ones from
the DRIHL's *"Annexe 4 : grille des plafonds de ressources 2026"* (arrêté du 19 décembre 2025, JO
24 décembre 2025), both on the 2024 revenu fiscal de référence.

**And the figures REFUTED this question's own premise.** The text below says *"the bands are far
apart, so it discriminates reliably — it is the best signal the project is not using."* Measured,
they are not far apart at all. Even at the SAME household size, zone B1's intermediate ceilings sit
BELOW the Paris PLS ceilings for every size from two upward (B1 couple 48 268 € against PLS
52 303 €); only 13 of the 18 (zone, size) pairs separate. And a listing states a bare figure with no
household size, so the sizes collapse and the bands overlap from 36 144 € to 109 595 € — a 73 451 €
range, 0 of 18 separable. Social ceilings are unbounded upward besides, so *"above every social
ceiling ⇒ intermediate"* has no derivable region at all.

**What was built is therefore one direction only:** strictly below 36 144 € — the lowest intermediate
ceiling anywhere in Île-de-France — a figure cannot be an intermediate ceiling, so the financing is
social. Above that the tier emits nothing: never an intermediate verdict (`PlafondBands` refuses such
a band at construction), and never a doubt (a numeric doubt contradicts a correct tier-2 label into
the digest, as `loyer plafonné` did to `lli-004` and `lli-011`). Measured after arming, it fires on
**0** of 165 live listings and 0 of the 20 captured In'li descriptions — the shape it catches is real
and no live source currently emits it. Full reasoning: `docs/plans/archive/plafonds-tier-4.plan.md`.

**Everything below this line is the pre-2026-08-26 record**, kept because the refuted premise is the
useful part. `PlafondBands` shipped empty and the test that asserted the tier stayed inert
stood. Writing the figures from memory is forbidden (hard rule 1) and inventing them would be worse
than the gap: the tier would appear to work while silently dropping eligible listings. Tiers 1–3 clear
the floor on every corpus case, so this is an enhancement, not a hole. Source them from the annual
arrêté when someone has the figures in hand.


Signal tier 4 in `spec/PROJECT_BRIEF.md` §4 compares a quoted income ceiling against known LLI vs
PLUS/PLAI bands. The bands are far apart, so it discriminates reliably — it is the best signal the
project is not using.

What is missing is the figures. They vary by zone (A bis / A / B1) and by household size, and they
are revised annually. `CLAUDE.md` hard rule 1 forbids writing them from memory, and inventing them
would be worse than the gap: the tier would appear to work while silently dropping eligible listings.

**Behaviour until 2026-08-26:** the rung existed in the ladder, `PlafondBands` shipped empty, and
`TenureClassifierTest::testPlafondTierIsInertUntilRealBandsAreSourced()` asserted it stayed inert —
so the day someone loaded real figures, the suite would say the tier had woken up and needed its own
fixtures. That is exactly what happened; the test is now
`testPlafondTierIsArmedFromTheCommittedFigures`.

Sourcing them needs a decision on *where from*: the annual arrêté on Légifrance is authoritative but
awkward to parse; ANIL and service-public.fr republish them in readable tables. Neither is a
five-minute job, and the classifier already clears the floor on tiers 1–3 for every corpus case, so
this is a genuine enhancement rather than a gap.

### Ⓐ Q20 — ANSWERED 2026-08-07: ICF stays `mixed_tenure: true` · SUBJECT RETIRED 2026-08-23

> **The source this question is about no longer exists in `config/rent/sources.json`** (retired
> 2026-08-23, with `seqens`). Nothing below is withdrawn — the ruling was right and the drift guard
> it demanded is still live — but the block it applied to is gone, so there is no `mixed_tenure`
> flag left to flip.
>
> **It ended as option 3, and NOT for option 3's reason, which is the part worth carrying forward.**
> Option 3 below is *"drop ICF until someone confirms an endpoint"* — a caution, taken while nobody
> had looked. What actually happened is that somebody looked: A2 was measured three levels deep on
> 2026-08-20 and publishes a directory of *résidences* with zero rents, zero surfaces and zero
> occurrences of `disponib` (`docs/SOURCES.md`, A2). So the block was not removed pending evidence,
> it was removed BY evidence, and the difference decides whether a future session should try again.
> Reaching the same disposition from a guess and from a measurement are not the same outcome, and a
> file that records only the disposition cannot tell them apart.
>
> `tests/fixtures/rent/tenure/corpus.json` keeps its own `icf_novedis` and `seqens` entries — corpus
> labels are corpus-local — so `ConfigTest::testEveryCorpusSourceAgreesWithConfig()` simply has two
> fewer sources to compare, and `testTheMeasuredDeadEndsAreNotShippedAsPlaceholders()` now stops
> either name reappearing as a `REMPLACER` placeholder.

**ANSWERED 2026-08-07 — the default applies (option 1).** `icf_novedis` keeps `mixed_tenure: true`
until a real Novedis payload can be inspected. The binding check this section asked for is now
implemented: `ConfigTest::testEveryCorpusSourceAgreesWithConfig()` fails if a `mixed_tenure`,
`family` or `default_tenure` in `config/rent/sources.json` disagrees with the same source in
`tests/fixtures/rent/tenure/corpus.json`, so the two cannot drift apart silently. It also asserts that at
least five sources appear in both files, because a rename on either side would otherwise make the
comparison vacuous rather than red.

**Correction, and it is the reason this paragraph is worth reading twice.** When this answer was
first committed the sentence above was written in the present tense and the test did not exist —
neither did `config/`. Three independent reviewers found it, and they were right to treat it as the
most serious finding in the batch: Q20 *closes* the question that asked for a drift guard on the one
flag that disarms §1's fail-closed rule, so a future session reading it had no reason to build the
guard. The test and the config landed in the very next commit, which is what makes the paragraph true
now — but it was false when written, and that is recorded rather than quietly repaired.


`mixed_tenure` is the single flag that disarms the fail-closed rule: when it is `false`, a listing
with no tenure signal at all is notified on the source default alone. So every `false` is a claim
that the landlord publishes **no social stock whatsoever**.

The corpus declares four such sources — `inli`, `seloger`, `leboncoin` and, until review,
`icf_novedis`. The first three are defensible: In'li is Action Logement's intermediate arm, and the
two portals are private-market. **ICF is not**, and `CLAUDE.md`'s own reviewer charter names ICF
among the landlords that publish social *and* intermediate stock. ICF Habitat **Novedis** genuinely
is the non-social arm, so `false` is right for a Novedis-only endpoint and wrong for the group
portal — and nothing in this repo yet says which the adapter will hit, because `config/sources.yaml`
does not exist.

**Current behaviour (the default if this stays unanswered):** `icf_novedis` is `mixed_tenure: true`.
A Novedis listing with no signal now digests instead of notifying — slightly noisier, and safe.

- **Option 1 — leave it `true` until the adapter is written (recommended).** Costs a few digest
  entries; cannot cost an application. The flag can be flipped with evidence once we can see what a
  Novedis payload actually contains.
- **Option 2 — set it `false` now**, on the strength of Novedis being the non-social arm. Correct if
  and only if the adapter targets a Novedis-only listing endpoint, which is unverified.
- **Option 3 — drop ICF from the source list** until someone confirms an endpoint. Loses the
  second-highest-value Tier A source for no safety gain over option 1.

Whichever way this goes, the day `config/sources.yaml` lands there must be a check that binds each
declared `mixed_tenure` to the corpus, so the two cannot drift apart silently.

---

## Part 3 — Raised by the bundle integration (2026-08-06)

### Ⓐ Q12 — ANSWERED 2026-08-07: unlicensed

**ANSWERED 2026-08-07 — the default applies.** The repo stays unlicensed (all rights reserved). No
`LICENSE` file, no SPDX headers restored. If AGPL is ever adopted that is a new decision.

The `scripts/claude-bootstrap/` scripts carried `SPDX-License-Identifier: AGPL-3.0-or-later` in
`twes-in`. rent-watch declares **no licence**, so those headers were **stripped** rather than
propagated — importing AGPL into an unlicensed repo by accident is a licensing decision nobody made.
Same copyright holder throughout, so this is the developer's call.
**Default if unanswered:** stay unlicensed (all rights reserved), which is the safe state for a private
personal tool. If AGPL is adopted, restore the headers and add a `LICENSE`.

### Ⓐ Q13 — ANSWERED 2026-08-07: no trailers, as ruled

**ANSWERED 2026-08-07.** The developer ruled this directly on 2026-07-29 and again in this session:
author and committer are `Takieddine MESSAOUDI`, and there is never a `Co-Authored-By` or
`Claude-Session` trailer. The harness default is overridden. Closed.

`CLAUDE.md` § "Git autonomy" adopts the sibling repos' ruling: commits are authored as
`Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>` with **no `Co-Authored-By` and no
`Claude-Session` trailer**. This container's harness instructs the opposite. All three sibling repos
follow the no-trailer rule, so it was applied here for consistency.
**Say so if you want the harness default instead** — it is a one-line change to `CLAUDE.md`.

### Ⓐ Q14 — ANSWERED 2026-08-07: still rejected, revisit with the digest

**ANSWERED 2026-08-07.** Q17 put the read-only digest in scope, which is the condition this question
named — but the digest does not exist yet, so there is nothing to crawl. `/qa-sweep` stays rejected and
this re-opens automatically the day the digest ships, not before.

`pdfturbo` ported it because it had a UI to crawl. rent-watch has no UI by design. Recorded in
`scripts/claude-bootstrap/README.md` § "What was rejected" so it is not re-imported "for later".
**No decision needed unless a read-only HTML digest is built.**

---

## Ⓐ The Action Logement wrinkle — RULED 2026-08-07

**Ruling: Action Logement is a SOURCE, not a tenure.** Its landlords are in scope, and every one of
them — AL'in, Seqens, Immobilière 3F, 1001 Vies Habitat, Antin Résidences — ships
`mixed_tenure: true`, because they publish social and intermediate stock on the same pages. Nothing
special is needed beyond what §1 already mandates. The section below is the reasoning, unchanged.

"Action Logement" is an **organisation**, not a tenure. It distributes housing reserved for employees of
contributing companies, and that reserved stock spans BOTH families:

- its **intermediate / LLI** stock — in scope, and a genuinely good channel because the reservation
  reduces competition
- its **social** stock (PLUS/PLAI via employer reservation) — out of scope by the Q4 answer, and it
  still runs through the SNE and a commission

`AL'in` (the Action Logement platform) and the Action Logement group landlords — **Seqens**,
**Immobilière 3F**, **1001 Vies Habitat** — publish both on the same pages. So "I want Action Logement"
resolves to: **yes to the source, with the classifier doing its job on each listing.** Every one of them
gets `mixed_tenure: true`. Nothing special is needed beyond what §1 already mandates — but it is worth
recording that "action logement" was named as a wanted *source*, not as a wanted *tenure*.

## Part 2e — Corrections forced by the review of 2026-08-07

The developer asked for the answered defaults to be reviewed for flaws. Three adversarial reviewers
found 56 findings between them. The ones that **change a ruling** are recorded here as rulings in
their own right, so that a session building the notify or CLI layer builds the corrected version
rather than the one the original answers described.

### Ⓐ Q26 — the `legal_risk` refusal, which the first pass never actually decided

Three refusals were designed on 2026-08-07 — `browser` (Q10), unknown config keys (Q22), missing
credentials (Q9) — and the one `CLAUDE.md` hard rule 4 and `spec/PROJECT_BRIEF.md` §7 actually
*mandate* got no decision at all, in a pass that announced *"nothing is blocking"*.

**RULED:** a source block with `legal_risk: true` is refused at config load unless
`--i-accept-legal-risk` is passed on that invocation. The flag is **never persisted in config**, so
starting a scrape is a deliberate act each time rather than a boolean somebody flipped once. In
addition, `SourceDefinition::requiresScrapingOptIn()` returns true for any `family: private` source
of type `json` or `html` — polling a private portal is the gated case; email-alert ingestion is the
sanctioned path and is never gated.

CDC Habitat's protection was a `_comment`, which the loader discards before anything can act on it —
so `enabled: true` required deleting nothing. That is now backed by code: an enabled source whose URL
still contains `REMPLACER` is refused at load.

### Ⓐ Q27 — the tool's own silence is undetectable, and four rulings created total-stop modes

The single best finding of the round, and it applies this project's own reasoning one level up.
Source health exists because *"a source returns zero forever and the user concludes the market is
quiet."* Under Q8 the process is unattended on a VPS; under Q9/Q10/Q22 a stale credential, a stray
comma in `criteria.json` or a leftover `type: browser` **stops the whole process** — and the only
channel that could report it is the one that refused. Zero notifications is also the normal output on
a quiet evening, so a dead watcher and a quiet market emit byte-identical output.

**RULED:** `scout --domain=rent run --watch` emits a **heartbeat** at low priority every `HEARTBEAT_HOURS` (default
24) stating runs completed, sources OK and matches sent — **whether or not anything matched**. Silence
from rent-watch for longer than that is then itself a signal. A startup refusal exits non-zero *and*
writes its reason to `state/rent-last-refusal.txt` on the mounted volume, so the next successful start can
report what happened while it was down.

### Ⓐ Q28 — refusals are scoped, not global

**RULED**, amending Q9 and Q22:

- A validation error in **`criteria.json`** is a startup refusal. The criteria govern what is
  filtered, and a wrong filter is invisible — the user sees plausible results forever.
- A validation error confined to **one block in `sources.json`** disables that source and reports it
  as a health status, like any other broken source. A typo in a Tier-B selector must not stop In'li.
- A channel enabled without its credential is a startup refusal **only when it is the only enabled
  channel**. Otherwise the process starts, that channel is disabled for the run, and an alert about
  it goes out through a working one. `console` alone does not count as usable under Q8's Docker
  deployment — a container log is not a notification channel.
- A **send failure at runtime** leaves `notified_at` NULL so the next run retries. Q9 covered only
  startup, which left the hole where a failed delivery silently marks a listing as notified.

### Ⓐ Q29 — health routing covers every alerting status, not `SOURCE_BROKEN` alone

`SourceStatus::isAlerting()` is true for six members. The 1c table routed one. An implementer building
from it would derive `NEVER_PRODUCED` (the wrong-field-map detector, added *because* it hid behind OK)
and `STALE` (the schedule-stopped detector), store them, and never send them.

**RULED:** every status where `isAlerting()` is true is routed. Same channel, de-duplicated per
**`(source, status)`** per 24 h — keyed on the status too, so an escalation from `WARN_DROP` to
`BROKEN` is not swallowed by the earlier alert. Re-alert at 24 h, then 72 h, then weekly, at rising
priority, so a source broken for three weeks is not as quiet on day 20 as on day 2. A transition back
to `OK` sends one recovery notice and clears the key. The daily digest additionally carries a one-line
summary of every source not currently `OK`, so a suppressed alert is still visible without a push.

The cooldown is **persisted**, in a `source_alerts` table added by schema v3 — in process memory a
crash-looping container re-alerts on every restart and a manual `scout --domain=rent doctor` shares no state with
the running `--watch`.

### Ⓐ Q30 — `item_count` is what the ADAPTER PARSED, before any filtering

Undecided, and the two readings contradict each other: Q23 defended a threshold with *market*
emptiness (a post-filter count) while `Store::health()` reports the same condition as *"mapping de
champs à vérifier"* (only meaningful for a raw count).

**RULED:** `item_count` is the number of listings the adapter parsed, before criteria are applied. A
source is healthy when it is producing listings, whether or not any match. Matched counts get their
own column and **no health verdict reads it** — otherwise the health subsystem measures the
Île-de-France rental market rather than the adapter, and a broken selector on a source whose matches
are usually zero becomes undetectable, which is the exact failure §8 exists for.

### Ⓐ Q31 — confidence is a routing gate and a tiebreaker, NOT a score multiplier

S8 as a multiplier, combined with 1c's *"score ≥ 70 is high priority"*, makes high priority
**arithmetically unreachable** for most matches. Realised confidences are ≈{0.50, 0.80, 0.90, 0.97},
so at 0.50 a listing would need a normalised 140 to clear 70. Measured against the corpus: **16 of 31
expected MATCH cases can never reach high priority**, and the entire LIBRE track is barred — private
portals carry no tenure vocabulary, so they sit at tier 5 and cap at 50. Q4 made LIBRE first-class
*because* it goes fast, and Q9 chose a push channel for the same reason; the multiplier then silences
the urgency signal for exactly that track. It also inverts ranking below zero, because multiplying a
negative score by a confidence in (0,1) moves it *toward* zero and the less-trustworthy verdict wins.

**RULED:** priority is decided on the **unmultiplied** normalised score, clamped to `[0, 100]`. A
MATCH whose confidence is below 0.80 is **capped at normal priority** however it scores, and
confidence breaks ties in the ordering. That delivers what S8 was asked for — a 0.65 LLI must not rank
with a 0.98 LLI — without making the threshold unreachable. `UNKNOWN` still never reaches scoring.

### Ⓐ Q32 — an unknown commune, and an unknown rent basis

F4, F5 and F6 each say explicitly what an unknown measurement does. F2/F3 said nothing, and both
readings are wrong: reject-on-unknown silently drops every listing from a source whose commune
selector drifted (health does not fire — the fetch succeeded and the count is non-zero), while
pass-on-unknown stops filtering geography entirely, and Leboncoin is a national portal.

**RULED:** a listing with **neither** a commune nor a postcode is rejected — it carries no location
evidence at all, and location is the one criterion with no score fallback. With one of the two
present it is judged on that one, and notified with a `reasons[]` entry naming the missing field.
Three consecutive runs in which a source yields ≥1 item and **zero** parseable communes raises
`SOURCE_BROKEN`: a location field map that has drifted is a source breakage, not a quiet market.

**And on rent (amending Q2):** CC is `rent_cc` when present, **else `rent_hc + charges` when both are
present**. Only when neither holds is the CC rent unknown. Treating a derivable CC as unknown throws
away a hard filter; treating an HC-only rent as CC notifies a ~1900 € flat against an 1800 € ceiling.
The unknown case is not disqualified, scores 0 on S3, and says so.

### Ⓐ Q33 — a rent drop that crosses a disqualifier boundary always notifies

The thresholds were written for a known match getting cheaper. A listing seen at 1810 € CC is
hard-disqualified and never notified; re-seen at 1795 € it is a full match — but the drop is 15 €
and 0.83%, so both thresholds fail and nothing is sent. The whole band just above the ceiling is
exposed.

**RULED:** a drop notifies at ≥ 20 € **or** ≥ 2% — whichever fires first — **or whenever it crosses a
hard-disqualifier boundary in either direction, whatever its size.** A listing that was disqualified
and now qualifies is a **new match**, not a price event, and is notified at the priority its score
earns. (The original wording said *"whichever is smaller in absolute terms"*, which is redundant at
best and the opposite of the intent if read as an `and`. Removed.)

### Ⓐ Q34 — the digest cannot wait a day, and it must survive a failed send

33% of the corpus routes to DIGEST, the digest is the fail-closed rule's only landing zone, and Q21
knowingly put an ordinary shouted title for a good flat in it. A once-a-day emission turns *"one
glance"* into *"gone"* — and Q21's cost argument was made before the cadence was chosen, then never
revisited.

**RULED:** emitted on demand via `scout --domain=rent digest`, **and at the end of any run that produced new digest
entries**, as one low-priority rollup naming only what is new since the last successful emission.
Entries are marked emitted **only after the channel confirms delivery**; an unsent digest is retried
next run. The daily emission stays as a floor for days with nothing new, at the timezone named in
`TZ` — which defaults to `Europe/Paris` in `.env.example`, because a Docker container without it runs
UTC and *"08:00 local"* silently becomes 10:00 Paris in summer. `scout --domain=rent doctor` prints the resolved
local time next to the digest schedule.

**PARTIALLY BUILT, and the unbuilt half is named here rather than left to be discovered
[2026-08-24].** Both emission paths exist and are tested: the automatic one at the end of any pass
producing new entries, and `scout --domain=rent digest [--dry-run]` on demand, reading the STORE rather than the
pass — which is the difference that matters, since the pipeline re-offers an undelivered entry only
while the ad is still published. Entries are marked only after the channel confirms, and an unsent
digest is retried.

**THE DAILY FLOOR IS BUILT [2026-08-26, `8c24cb2`], and Q34 is now closed in all three paths.**
`Core/DigestSchedule` is the policy — pure, clock injected, mirroring `Core/Heartbeat` — and
`state/rent-digest.txt` on Q8's mounted volume is the marker, written ONLY after the channel confirms.
The window is the most recent local `digest_hour` at or before now, so a container down at 08:00 and
back at 11:00 emits LATE rather than skipping the day. Marker absent, unreadable, or dated in the
future are all due: the inherited bias is one emission too many, never one suppressed.

**It emits NOTHING on a day when the bin is empty, and writes no marker in that case** (developer
ruling, 2026-08-26). `Core/Heartbeat` post-dates the sentence above and already sends a daily
liveness beat, so an unconditional floor would be a SECOND scheduled push carrying nothing the beat
did not — and a channel that speaks daily with nothing to say is one its reader learns to swipe
away. Not writing the marker is the half that is easy to miss: it leaves the window open, which is
what makes this ruling's own *"an unsent digest is retried"* work — a send that failed at 08:05 is
retried on the next pass rather than tomorrow. **To reverse:** emit unconditionally instead of
returning early on an empty bin.

**SCOPE: the floor runs under `scout --domain=rent run --watch` only.** It lives in the watch loop, beside the
heartbeat, so a cron-driven `--once` deployment gets the two event-driven paths and no floor.
`doctor` says `en --watch` for that reason. **To widen:** a due-check in the `--once` path too.

**The zone is resolved explicitly, and the reason first recorded for that was WRONG.** It said PHP
does not consult `TZ`, so the default zone would fire the floor at 10:00 Paris in summer. The
measurement is real — `php -r` in the deployed container reports `UTC` with `TZ=Europe/Paris` set —
and the conclusion does not follow, because `bin/scout:44` already calls `date_default_timezone_set()`
from `TZ` before `Scout` exists. A true number attached to an invented cause, from measuring the
runtime and never reading the entrypoint. What the explicit zone actually buys, both measured: an
unusable `TZ` becomes a LOUD startup refusal, where `date_default_timezone_set('Europe/Pariss')`
returns `false`, emits a Notice and leaves UTC standing — a compose typo moving the floor two hours
all summer with only a log line to show for it; and the schedule stops depending on process-wide
mutable state.

The drain itself is SHARED with `scout --domain=rent digest` (`Cli/DigestBatch` plus one collector), because two
implementations of §1's only landing zone is how one drifts into announcing what the other would
withhold. The collector never throws and never prints: the floor runs inside the loop's `finally`,
where a throw would be counted as a failed pass and one damaged row would report every source
broken.

### Ⓐ Q35 — a stored verdict must be revisable, not merely auditable

Q24 stated the problem — *"a listing stored under an old classifier cannot be re-evaluated"* — and
answered only the storage half. Combined with the seen-set's *"new exactly once"* guarantee, a
listing digested as `UNKNOWN` under a classifier that is later improved is a **permanent silent
miss**. Q18 (PLI) and Q21 (shouted `PLUS`) both deliberately route there, so the bin will not be small.

**RULED:** `scout --domain=rent reclassify [--since]` re-runs the classifier over stored listings using the
persisted raw fields; any row whose `Outcome` improves from `DIGEST` to `MATCH` is notified as a new
match. The classifier version is stored with the verdict so the command can select only stale rows.

**AMENDED [2026-08-24], on three of those mechanisms. The ruling's INTENT stands and is BUILT.**

*The persisted evidence is a SNAPSHOT, not the raw fields.* Schema v7 stores the `RawListing` the
classifier actually consumed — after mapping and after any detail merge — rather than the pre-map
payload, whose re-reading would make a stored verdict depend on `ListingMapper` code that has since
changed. Pre-v7 rows are **not** backfilled, and reclassify **skips** them, loudly counted: the
invariant is `evidence ⊇ original, never ⊂`, because a card whose field says `PLS` while its title
says *logement intermédiaire* is undetermined by CONFLICT, and on the title alone it becomes a
match. Re-judging on shrunken evidence is not a smaller improvement, it is the breach §1 forbids.

*`--since` is REFUSED, not implemented.* Its staleness mechanism is the classifier version stored
with the verdict, and there is no such column. Answering `--since` against `last_seen_at` would
substitute a different mechanism for the ruled one while looking like it had been honoured;
re-running the whole undetermined bin costs seconds. The flag exits 2 with that reason and is absent
from `scout help`. **To reverse:** add the version column (a schema bump, written in
`recordVerdict`) and implement the flag against it.

*The notified transition is wider than `DIGEST → MATCH`.* Any transition INTO `MATCH` from a
different outcome is announced, `REJECT → MATCH` included — it is not a demotion, it is reachable
the moment the criteria widen (Q1–Q3 widened three filters in one day), and Q33 already rules that a
listing which was disqualified and now qualifies is a new match. Recording it silently stranded it:
the row leaves `staleVerdicts()` and `pendingDigest()` at once, and there is no third selector.

### Ⓐ Q36 — a missing volume must not look like a fresh install

Q8 rules out GitHub Actions *because* no persistent disk means re-notifying everything, then adopts a
deployment with the identical failure mode and no guard: `Store::open()` creates the file, so a typo
in `-v` produces a valid, empty, migrated database indistinguishable from a healthy one — and with
nothing batched, every historic listing pushes at once.

**RULED:** `Store::open()` reports whether it **created** the database. On a fresh one `scout --domain=rent run`
refuses to notify and exits saying so, offering `--seed` to populate the seen-set without notifying.
The mount is additionally asserted by a marker file written in `SCOUT_DB`'s directory at first
successful start.

**AMENDED [2026-08-19], on both halves — the ruling's INTENT stands, its two mechanisms did not.**

*The fact the guard reads.* "Did `open()` **create** the file?" is destroyed by any earlier command
that merely opens the database, and `scout --domain=rent doctor` — the first command a new machine invites you to
type — opens it. Typing `doctor` once therefore let the following `scout --domain=rent run --once` push the whole
back catalogue, which on In'li is 92 listings at once. The guard now reads whether anything has ever
been **recorded** (`Store::isSeenSetEmpty()`): a fact that lives in the rows, so no other command can
answer it away. `--seed` is unchanged as the route through.

*The marker file is WITHDRAWN, because it cannot detect what it was ruled for.* Its stated location
is `SCOUT_DB`'s own directory — that is to say, inside the volume. A typo in `-v` gives the
container a fresh empty directory, so the marker vanishes together with the database and the two
states it was meant to separate stay identical. Put it outside the volume instead and it lives in
the image layer, which resets on exactly the container recreation where the typo happens. Neither
placement fires. Nothing is lost by dropping it: the empty seen-set fires in precisely that case,
and it is the same refusal.

### Ⓐ Q37 — `--watch` pacing, which was ruled as the word "jitter" and no number

15 Tier-A sources polled from one VPS IP, with the obvious implementation being a tight loop, is 15
near-simultaneous requests per interval. That is what a scraper looks like and it is how the
developer's own IP gets banned — while `CLAUDE.md` hard rule 5 requires low rates with jitter.

**RULED:** poll every 15 minutes ± 5 minutes of jitter. Within a pass: at least 5 s between requests
to distinct hosts, at least 60 s between two requests to the same host, and the source order is
**shuffled each pass** so no site is always first. A pass finishing in under 60 s does not immediately
start another.

---

## Part 2f — Raised by schema v4, the cross-portal group (2026-08-19)

### Ⓐ Q38 — ANSWERED 2026-08-24: yes, an excluded member decides the whole cluster

**ANSWERED 2026-08-24 — and NOT by anyone deliberating this entry.** It was settled by a P0 the
round-4 review panel found in the code, which is the part worth recording: §1 was being judged on
the survivor of a cluster ALONE, so the same flat published on a pure-LLI portal and on a mixed one
declaring `PLS` came out a MATCH or a REJECT depending on **which source `Pacer` happened to shuffle
first that pass**. The store then held `PLS` at confidence 99 under the same `group_key` as the row
it had just pushed as a 75/100 match. That is not the "for a veto / against a veto" trade-off below;
it is a verdict that was not a function of the evidence at all, and no answer to this question could
have left it standing.

So the argument *against* — that a cluster is a fuzzy guess and should not be allowed to suppress a
real match — did not lose. It was **priced and accepted**, and the price is stated in
`CLAUDE.md` § store test contract: an over-merge now rejects BOTH flats, permanently.

What shipped, in three parts, each with its own test:

- an EXCLUDED member decides the cluster (`Pipeline::clusterClassification()`);
- an UNDETERMINED one deliberately does **not** — absence of a signal is not evidence, and In'li's
  166-of-168 weak-label population would otherwise digest most of the tree's yield;
- the decision is DURABLE, read from the persisted group via `Store::groupExcludedTenure()` rather
  than recomputed per pass. Two later fixes forced that: a pass in which the excluded sibling was
  not fetched (a failed source, a `--source=<name>` run, a delisting) laundered the flat back into a
  match, and `scout --domain=rent reclassify` — which re-judges from each member's OWN v7 snapshot — undid it
  wholesale.

One line reverses it: make `Pipeline::clusterClassification()` return the survivor's own classification.
**Reversing it is no longer free**, which is why this entry says so rather than repeating the
boilerplate: `group_key` is never cleared, so the rejections a cluster has already caused stay
rejected.

**The third route below was NOT taken and is still available** — it is an addition to this, not a
replacement for it, since it decides nothing and so cannot fix the poll-order defect that forced the
change.

This became askable only on 2026-08-19. Before schema v4 the pipeline clustered before recording and
iterated survivors only, so an absorbed duplicate was never classified — the question had no data to
be asked about. Now every member is classified at record time, and the store can hold a fact the
notifier does not look at:

    inli:id:A-1    "T3 Sartrouville — logement locatif intermédiaire"   LLI, 0.94   ← survivor, notified
    cdc:id:B-9     "T3 Sartrouville — financement PLUS"                 PLUS, 0.91  ← same flat, silent

Both rows describe one flat. One portal says LLI and the other says PLUS. Today the user is notified
on the survivor's verdict and never learns the second row disagrees.

It is genuinely two-sided, which is why it is written down rather than decided:

- **For a veto.** §1 is an ELIGIBILITY rule, and eligibility is a property of the flat, not of the
  page that described it. If any portal says PLUS, the flat plausibly is PLUS and the application is
  wasted — which is the cost §1 exists to avoid. Biasing an ambiguous case toward not notifying is
  the standing instruction.
- **Against a veto.** A cluster is a GUESS. `Dedup`'s tolerances are pairwise and fuzzy, and the
  ruling that created the group accepted over-merge as a live possibility whose blast radius is
  confined to one presentation view. A veto would take it out of that confinement and let a bad
  cluster suppress a real LLI match — silently, which is the failure direction §1 itself forbids.
  A landlord mixing social and intermediate stock on one result page is also normal, so two rows
  disagreeing is weak evidence about either.

A third route exists and may beat both: notify the survivor as today, and put the disagreement IN the
notification's `reasons[]` — "In'li dit LLI, CDC Habitat dit PLUS sur ce qui semble être le même
bien". That satisfies §1's spirit (the user is not misled) without letting a fuzzy cluster suppress
anything. It is the option to raise first if this is ever picked up.

**Still true, and still unbuilt.** With the answer above in place it is no longer an alternative to
the cluster rule but a complement to it: today a rejected cluster is silent, so the user cannot tell
an over-merge from an absent listing. Surfacing the disagreement is what would make the accepted
over-rejection cost VISIBLE rather than merely documented.

## Part 2g — Raised by schema v12, the cross-track twin fact (2026-08-31)

### Ⓐ Q39 — the cross-track veto is permanent, and it links a FUZZIER pair than Q38's

Q38 records the same-track rule — an EXCLUDED cluster member decides the whole cluster — with its
two-sided argument and its priced permanence. Schema v12 extended the same veto ACROSS the two
tracks: what the agency copy's landlord route last said now decides the agency copy, and vice versa.
That was ruled and built (developer ruling 2026-08-29, hardened by the round-3 and round-4 panels),
but it never got an entry here, which a review found on 2026-08-31 — so the weaker link carried the
stronger permanence with only a plan Decisions Log and a `CLAUDE.md` gotcha behind it.

**Why it deserves its own entry rather than an extension of Q38.** A same-track cluster is two rows
that `Dedup` judged to be one flat using a shared family's own identifiers. A cross-track twin links
two DIFFERENT portals' descriptions with no shared identifier at all — `Dedup::twinReason()` uses
positive evidence, but it is positive evidence about prose, not a key. It is strictly the fuzzier
link, and it carries the same consequence: an excluded reading sticks for the row's life.

**The accepted cost, stated plainly.** An over-linked pair permanently rejects a genuine LLI flat,
silently, which is the failure direction §1 itself warns about — exactly Q38's trade, on weaker
evidence. It is accepted for Q38's reason: the alternative is pushing a flat the same database
records as PLS on the row beside it, and a wasted application is the cost the whole project exists
to avoid.

**IT IS NOT REVERSIBLE, and this entry claimed it was for about an hour after it was written**
(round-5 panel, 2026-08-31). The first draft said *"reversed by one line: drop the twin branch from
`Pipeline::twinClassification()`"*. A reviewer executed that, together with the repair
`Store.php` documents (*"unpick the row"*) — `twin_tenure`, `twin_source` and `group_key` all
cleared, the direct route deleted outright — and the flat was STILL rejected on the next pass:

```
after pass 2: [{"source":"seloger","tenure":"PLS","twin_tenure":null,"outcome":"REJECT",
  "signals_json":"[\"régime exclu (PLS) … conservé (§1)\"]"}]
```

The mechanism: `Pipeline` writes the JUDGED classification — which carries the twin's or the
group's excluded tenure — into the row's OWN `listings.tenure`, and the round-4 durable-reading fix
then re-derives that from `Store::tenure()` on every member at record time. **A cross-track veto is
laundered into the row's own durable reading**, which has no repair route at all: `staleVerdicts()`
skips an excluded tenure, `pendingDigest()` skips a non-DIGEST outcome, `replay` writes no verdicts.
The same is true of Q38's group veto, whose documented repair (*"unpick the group in the store"*)
was proven not to work either.

Two consequences, both stated rather than left to be found. **The accepted cost above was priced on
the assumption it is reversible, and it is not** — an over-linked pair rejects a genuine LLI flat
permanently, and only a deliberate edit to the row's `tenure` column undoes it. And the rejection
reason says the PLS was *"relevé lors d'une lecture précédente de cette annonce"* when it was read
on the OTHER TRACK or on a sibling — the exact misattribution an operator diagnosing a silent
over-rejection would follow to the wrong listing.

**Owed:** either a repair command that can re-open a durably-excluded row, or a distinction in the
stored reading between "this row said PLS" and "something linked to this row said PLS". Until one
exists, do not describe either veto as one-line reversible.

> **THE REPAIR ROUTE LANDED 2026-09-05 (row 40, developer ruling: the command, not the stored
> distinction).** `scout --domain=rent reclassify --reopen=<dedup_key>` prints the provenance
> (own reading / twin reading with its source / group veto / same dwelling under another ad id —
> FOUR routes since 2026-09-07), clears the row's own and twin readings, and re-judges the row on
> its own evidence in the same invocation. The group veto is reported and NOT cleared — it lives on
> the siblings' own readings — and so is the same-dwelling route, for the same reason.
>
> **THIS ENTRY CLAIMED *"either veto is therefore reversible by one named command"* AND THAT WAS
> ALWAYS TOO STRONG** (C2 milestone panel round 2, P2). What one command reverses is the row's OWN
> reading and its TWIN's. A row held by its GROUP or by the SAME DWELLING is not reversible by
> `--reopen` at all: the command reports the route, and for the SAME-DWELLING route it names the
> offending listing's source and external id. **For the GROUP route it names no listing** — a
> cluster veto comes from the siblings' readings collectively — and neither route prints the
> `dedup_key` that `--reopen=` takes, so what you get is guidance rather than a command line.
> Closed, but narrower than the closure claimed — and the narrowing is the §1-safe direction, which
> is why it is not being widened.

> **THE MISATTRIBUTION HALF IS CLOSED (2026-09-04, F20); THE REPAIR ROUTE IS STILL OWED, and the
> two are worth keeping apart.** The reason now reads *"régime exclu (PLS) retenu pour cette
> annonce — origine non enregistrée (lecture propre, groupe ou autre voie) — conservé (§1)"*. That
> is not the distinction this entry asks for: nothing new is stored, and a session cannot tell from
> the row whether the `PLS` was its own reading or a sibling's. What changed is that the tool has
> stopped CLAIMING it could — hard rule 9 at the reason layer, where an operator diagnosing a
> silent over-rejection was being pointed at a reading that did not take place. The rejection is
> exactly as durable and exactly as permanent.
>
> **This is deliberately the smaller of the two available fixes.** Storing the provenance means a
> new column and a migration on the §1 audit trail; building a repair command means a route that
> can re-admit an excluded listing, which is the direction §1 refuses without a ruling. Making the
> sentence honest costs neither and removes the one consequence that was actively misleading
> someone. `tests/php/Rent/Cli/PipelineRunTest::testTheDurableExcludedReadingDoesNotClaimWhereItWasRead`
> pins it, with a ledger case restoring the old wording.

**Unbuilt complement, same as Q38's third route**: surface the cross-track disagreement in the
notification's `reasons[]` rather than only rejecting on it, so an over-link is visible instead of
merely documented. Today a vetoed row is silent and the user cannot tell an over-link from an absent
listing.

## Decisions Log

- [2026-08-06] AGREED: work on `master` only; no `claude/*` branch (developer instruction).
- [2026-08-06] AGREED: `AskUserQuestion` is forbidden; questions are plain text per
  `.claude/skills/ask-human/SKILL.md` — SUPERSEDED by the 2026-08-18 re-inversion below (the
  timeout died with the cloud container), and the skill was renamed `scout-ask-human`.
- [2026-08-06] ASSUMED: stack is **Python** (Q7), from `prototype/scout.py` and available `ruff`.
- [2026-08-06] ASSUMED: AGPL headers **stripped**, not propagated (Q12) — flagged for ruling.
- [2026-08-06] AGREED (Q4): **PLS is EXCLUDED** — it is social housing, and the ruling is no social
  housing. The excluded set becomes PLAI, PLUS, **PLS**, ANRU, ANAH, conventionné-absent-label.
- [2026-08-06] AGREED (Q4): **LIBRE is IN SCOPE** as a full match. Private portals become first-class;
  the IMAP `email_alert` adapter becomes milestone-critical rather than milestone-6.
- [2026-08-06] AGREED: **no auto-application, ever** — *"i don't want you to fill any application for
  me! just filter and show me/email me the list"*. This was already a ruled non-goal
  (`spec/PROJECT_BRIEF.md` §12); it is now doubly confirmed and must never be revisited.
- [2026-08-06] AGREED (Q9, partial): **email delivery is wanted.** Whether a push channel rides
  alongside it is still open.
- [2026-08-06] AGREED: **full coverage** — do not trim the source list to a recommended subset.
  Verified round recorded in `docs/SOURCES.md`: 15 Tier A + 11 Tier B candidates, each fetched once with
  an honest UA, `robots.txt` read for every pollable Tier A site. Two corrections and one significant
  find came out of measuring rather than recalling (`1001vieshabitat.fr` had no hyphen; Coopération et
  Famille merged into it in 2018; **ICF Habitat Novedis** is a 10 000-unit intermediate/loyer-libre arm
  that the first pass missed entirely).
- [2026-08-06] AGREED (Q16): **phorj is the implementation language**, and the developer will build the
  two missing pieces. Requirements spec written to `docs/PHORJ-REQUIREMENTS.md`: `Core.Imap` (read-only,
  streaming, typed errors, MIME-decoded bodies, **plus a file-backed test transport** mirroring
  `Mail.FileTransport` — without which Track 2 ships untested) and an **HTML parser with CSS selectors**
  (`Core.Html` today is a builder, so it renders HTML but cannot read it).
- [2026-08-06] CORRECTED, on the developer's challenge: an earlier Q16 note cited `twes-in`'s `CLAUDE.md`
  as precedent. **That was a defect** — this repo cannot read a sibling's file and a future session here
  cannot verify it, so it is a dangling cross-reference with the authority of a rumour. The argument was
  restated in rent-watch's own terms and `drift-scan.sh` § S2b now catches the whole class.
- [2026-08-06] AGREED (Q17): **CLI and the read-only web digest in PARALLEL** — *"both in parallel yes"*.
  This amends `spec/PROJECT_BRIEF.md` §12, which rules a web UI a non-goal: the digest is now in scope
  from the start rather than "acceptable later". Constraints kept: **read-only, localhost, no auth, no
  multi-user**. If it ever grows write actions or remote access that is a NEW decision, not an extension
  of this one.
- [2026-08-06] AGREED (transpile): **write it ONCE in phorj; `phg transpile` GENERATES the PHP, and only
  for the pure core.** The developer asked for both languages to test phorj's transpiler. Measured first:
  `phg` refuses 18 domains, four of which are rent-watch's whole I/O surface —
  `E-TRANSPILE-HTTPCLIENT` (all of Track 1), `E-TRANSPILE-DB` (the store), `E-TRANSPILE-MAIL` (both
  notifications), `E-TRANSPILE-SERVE` (the web app). So a whole-app transpile is impossible today, and
  hand-writing a second PHP implementation would test the *author*, not the transpiler, while creating a
  silent-divergence trap. Instead: `core/tenure`, `core/criteria`, `core/dedup` and `core/models` stay
  **pure** (`json`, `decimal`, `regex` carry no transpile gate), so the classifier — the highest-risk
  component in the product — runs **byte-identically on three legs**: interpreter, bytecode VM, and
  transpiled PHP. Free differential testing on exactly the code where a bug means a social-housing false
  positive.
- [2026-08-06] AGREED (architecture, follows from the above): **the pure core must not import a
  native-only module.** No `Core.HttpClient`, `Core.Database`, `Core.Mail`, serve or `Core.File` in
  it. I/O is passed in — the classifier takes a listing, not a URL. This is ports-and-adapters
  discipline, worth having regardless, and it is **mechanically checkable**: `phg transpile
  src/phorj/core/` succeeding IS the proof the discipline held. Wire it as a gate once that tree
  exists. (Paths updated 2026-08-07: this entry said `src/core/`, the single-language layout the
  two-language ruling replaced. The core lives at `src/php/Core/` and, later, `src/phorj/core/`.)
- [2026-08-06] AGREED: **run every assumption past the developer** — *"even if you assume anything run
  it by me"*. Assumptions get stated explicitly and recorded here, not absorbed silently.
- [2026-08-06] AGREED: **build the pure core without waiting for phorj** — *"let's start without
  phorj and then we will do it when it's ready"*. `core/models` + `core/tenure` need none of phorj's
  three missing modules, no mailbox and no endpoint, so they were written in PHP 8.5 first. The
  corpus is language-neutral JSON, so the phorj port reads the same file and the two can be diffed
  fixture-by-fixture. See `docs/plans/archive/core-tenure-classifier.plan.md`.
- [2026-08-06] AGREED: **PHP 8.5, latest of everything** — *"use php 8.5"*, *"latest of everything"*.
  PHP 8.5.9, Composer 2.10.2, PHPUnit 13.2.6. Zero Composer dependencies: the container's egress
  policy blocks GitHub dist downloads, so Composer falls back to full git clones and a PHPUnit dev
  dependency cost 2.6 GB of `vendor/`. The runner is the official PHAR instead.
- [2026-08-06] RAISED (Q18): **PLI is not in the glossary** and Q4 did not rule on it. It is
  genuinely not social housing, but guessing would decide a product question in code, so it
  classifies as UNKNOWN and digests. Default stands unless overturned.
- [2026-08-06] RAISED (Q19): **classifier tier 4 (plafonds bands) ships inert.** The rung exists; the
  figures do not, and hard rule 1 forbids writing them from memory. A test asserts it stays inert so
  loading real bands is a deliberate, visible act.
  **CLOSED [2026-08-26, `8ddb8d0`]** — figures committed from two dated official sources, tier armed,
  and the measurement refuted the question's own "the bands are far apart" premise. See Q19 above.
- [2026-08-06] AGREED (tooling): **`tests/sabotage-check.sh` is part of the classifier's test
  contract.** Every failure mode in this module is silent, so a green suite is not evidence. The
  sabotage run breaks the classifier 15 ways and requires the suite to catch each one; it found three
  undetected regressions and one piece of unreachable safety code on the day it was written.
- [2026-08-07] AGREED (all questions): **every open question is closed by applying its documented
  default** — *"let's answer all the questions then continue non stop till you finish everything"*.
  21 questions resolved in one pass. The per-question entries above each say what was applied and
  what one line reverses it.
- [2026-08-07] AGREED (Q22, was BLOCKING): **config is JSON** — `config/rent/criteria.json` +
  `config/rent/sources.json`, parsed by `ext-json`. `spec/PROJECT_BRIEF.md` §9 and `CLAUDE.md`'s
  architecture table amended to `.json` in the same change. Keys beginning `_` are ignored
  (`_comment` by convention); every other unknown key is a **hard validation error**, so the
  comment convention cannot double as a typo swallower. Milestone 1 unblocked.
- [2026-08-07] AGREED (Q9): **console always, ntfy and email both available and both optional.**
  Shipped default enables `console` only. A channel enabled without its credential is a **startup
  refusal**, never a silent no-op — an unsent notification is the one failure mode this project
  cannot tolerate quietly.
- [2026-08-07] AGREED (Q5 + F7/F8): **floor and elevator are scoring only.** `max_floor` and
  `require_elevator` do not exist as config keys, so the prototype's silent drop of a good flat whose
  listing forgot to mention the lift cannot be reintroduced by editing config.
- [2026-08-07] AGREED (F3): **postcode prefix `92` removed.** Every commune in F2 is in 78 or 95, so
  `92` could never admit anything F2 also admitted. Dead config that reads as intent is worse than no
  config.
- [2026-08-07] AGREED (F9): **the `meubl` over-exclusion is a real bug and is fixed** — the term is
  matched as a property type, never as a bare stem, so `cuisine équipée et meublée` is no longer
  excluded. Tenure terms (`logement social`, `PLAI`, `PLUS`) are deliberately **NOT** added to
  `exclude_patterns`: that would be a second, weaker copy of §1 living in a user-editable file.
- [2026-08-07] AGREED (1c): **score ≥ 70 is high priority; nothing is batched**; the digest is
  on-demand plus one daily emission; `SOURCE_BROKEN` is de-duplicated to at most one alert per source
  per 24 h; a rent drop notifies at ≥ 20 € or ≥ 2%.
- [2026-08-07] AGREED (Q24 + Q25): **schema v3 lands in this milestone** — `listings.tenure`,
  `listings.confidence_bp`, `listings.signals_json` and `source_runs.duration_ms`, with the migration
  `upgradeFrom()` already supports.
- [2026-08-07] AGREED (Q11): repo visibility is the developer's action, but the mitigation ships
  regardless — committed criteria carry only values already public in `prototype/sources.yaml`, and a
  gitignored `config/rent/criteria.local.json` overrides field-by-field so private tuning never enters git.
- [2026-08-07] AGREED (Q20): the `mixed_tenure` drift check this file asked for is **implemented** —
  a test binds every source's flag in `config/rent/sources.json` to the same source in the classifier
  corpus, so the two cannot diverge silently.
- [2026-08-07 18:30] AGREED (review round): the developer asked for every applied default to be
  reviewed for flaws. Three adversarial reviewers returned **56 findings**. Twelve changed a ruling
  and are recorded as Q26–Q37 above; the rest were doc drift, fixed in the same change.
- [2026-08-07 18:30] AGREED (§1 breach, the most serious finding): **ANAH conventionné is signed by
  PRIVATE INDIVIDUAL landlords** and advertised on Leboncoin and SeLoger — the two sources declared
  `mixed_tenure: false`. A reviewer ran the payload and got `LIBRE / 50 / MATCH` with `reasons[]`
  reading *"aucun signal dans l'annonce"*: an excluded tenure reaching a notification. Closed by
  adding the scheme's MARKETING names to the classifier (`loc'avantages`, `loc avantages`,
  `locavantages`, `louer abordable`, `convention anah`) as tier-2 labels, which beat the tier-5
  source default — **not** by flipping the flag, which would have turned 13 corpus MATCHes into
  DIGEST and gutted the LIBRE track Q4 made first-class. Six corpus fixtures pin it.
- [2026-08-07 18:30] REJECTED, by the corpus: `loyer plafonne` was added alongside those as a doubt
  and immediately digested `lli-004`, `lli-011` and `regress-030`. **LLI is by definition a capped
  rent**, so that phrase is the primary target describing itself. Removed, and `lli-012` now pins the
  reason so it cannot be re-added against a green suite. `plafond de ressources` was rejected up
  front for the same reason. What survives is `loyer maitrise` and `loyer abordable` — conventionné
  vocabulary that LLI ads do not use — and both withhold rather than reject.
- [2026-08-07 18:30] AGREED (Q11 mitigation had no teeth): `config/rent/criteria.local.json` was
  documented as gitignored in **four** places and was not ignored by anything. `git check-ignore`
  confirmed it. `/config/*.local.json` added — the whole privacy argument for accepting a public repo
  rested on a rule nobody had written.
- [2026-08-07 18:30] AGREED (comment convention, corrected): "any key beginning with `_` is ignored"
  was unbounded — renaming `mixed_tenure` to `_mixed_tenure` would have silently disarmed §1. The
  rule is now: a free-standing note must be one of `_comment`, `_why`, `_source`, `_verified_at`, and
  a `_x` note is accepted **only while `x` is present in the same object**. The same edit now
  produces two loud errors instead of silence, and per-key notes — the ones actually read — survive.
- [2026-08-07 18:30] AGREED (F3 rationale verified, not assumed): a reviewer checked all ten communes
  against `geo.api.gouv.fr`. Nine matched the claim; the tenth exposed that the prototype's bare
  `cormeilles` names **no commune in 78 or 95** — it is Cormeilles-en-Parisis (95240), and bare
  `cormeilles` would either match nothing under exact comparison or also match
  Montigny-lès-Cormeilles under substring. `config/rent/criteria.json` ships the full name, and matching
  is exact on the folded commune field.
- [2026-08-07 18:30] AGREED (the tripwire had two JSON-shaped blind spots, both created by Q22):
  pattern 6 fired on the `"_comment"` note every mixed-tenure source is now required to carry — the
  exact sentence its own header quotes as the thing it must stay silent on. And pattern 5 stayed
  **silent** on `"tenure_classifier": false`, because a JSON key carries a closing quote between the
  name and the colon. Both fixed, with a must-fire and a must-stay-silent case for each.
- [2026-08-07 18:30] AGREED (Q23 partially overturned): `MIN_SPAN_FOR_NEVER_PRODUCED` raised from
  1 day to 7. Q23 had written down that a day false-accuses a legitimately quiet source and kept the
  day anyway; a reviewer demonstrated the flip at exactly the 24-hour mark on a source doing nothing
  wrong. The trade is stated: a broken field map on a brand-new source now goes unremarked for a
  week, which is acceptable only because `EMPTY_RUNS_BEFORE_BROKEN` still catches the far commoner
  shape — a source that used to work and stopped — in three runs.
- [2026-08-07 18:30] AGREED (schema keys restored): `base_url`, `params` and `body` were dropped in
  the YAML→JSON translation of the source schema. Under the new "unknown keys are a hard error" rule
  that left every source publishing relative hrefs with no documented key **and** a documented hard
  failure. Restored, with `item_selector` added for `type: html`.

- [2026-08-18] AGREED (supersedes the 2026-08-06 `AskUserQuestion` ban): questions use
  **`AskUserQuestion` again**. The ban's sole rationale was the tool timing out in the Claude Code
  cloud container; that environment is dead and work happens on the developer's own machine, where
  the tool works (`askUserQuestionTimeout: "never"` globally, answered calls verified 2026-08-18)
  and the global Stop hook mechanically requires it. The `❓`/`⏹` end-of-reply markers retire with
  the plain-text protocol. Question QUALITY rules (five parts, recommendation first, after-states,
  visible escape) are unchanged — see `.claude/skills/scout-ask-human/SKILL.md`.

- [2026-08-24] AGREED (Q38): **an EXCLUDED cluster member decides the whole cluster; an UNDETERMINED
  one does not.** Not deliberated on its merits — forced by a P0 the round-4 panel found, in which
  §1 was judged on the survivor alone and so returned MATCH or REJECT according to the order `Pacer`
  shuffled the sources into that pass. Made DURABLE (read from the persisted group) by two follow-up
  fixes: a pass that did not fetch the excluded sibling laundered the flat, and `scout --domain=rent reclassify`
  undid the decision wholesale. Accepted cost, stated rather than discovered: an over-merge now
  rejects both flats and `group_key` is never cleared, so that rejection is permanent. Recorded here
  on 2026-08-26 — the entry had said UNANSWERED for two days after the code answered it, which is
  the drift `/scout-repair` exists to catch.
