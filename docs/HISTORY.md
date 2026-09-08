# How scout got here

> Derived from `git log` on **2026-09-08** — 443 commits, first `2026-08-06`, all on `master`. This
> is the *what and when*; the *why* lives in the plan files under
> [`docs/plans/`](plans/) (the archive filenames are the milestone labels) and in
> [`docs/OPEN-QUESTIONS.md`](OPEN-QUESTIONS.md), where every decision names the one line that reverses
> it.
>
> Re-derive with `git log --reverse --date=short --format='%ad %s'`.

---

## The arc in one paragraph

A Python prototype and a written brief became, in five weeks, a two-domain watcher with fourteen live
sources, a fail-closed French housing-tenure classifier, a SQLite state layer at rent schema
v12 across two databases, transit-time scoring against a live public API, a sharded nightly sabotage ledger that proves the
tests would *notice* a regression, and a Dockerised deployment with its own liveness beat. The shape
of the work is unusual and worth naming: **most of the commits after the first week are corrections
found by pointing the code at a real payload**, not by writing new features — and nearly every one of
them was a *silent* failure that a green test suite had been reporting as fine.

---

## Week 1 — the core, written twice (2026-08-06 → 08-12)

| Date | Landed |
|---|---|
| 08-06 | Bootstrap: the brief, the prototype, the Claude config. **The tenure classifier and models in PHP 8.5** — §1 first, before anything that could surface a listing. |
| 08-07 | The store (seen-set, price history, run log, source health). The JSON config layer — *and a §1 breach the decision review found*. The `Source` adapter contract, the criteria engine, an end-to-end fixture run. Cross-portal dedup and the notification layer. Schema v3, the run loop, the `scout` CLI: **milestone 1 runs end to end**. HTTP, IMAP and SMTP adapters — *the transports were never the blocker*. |
| 08-07 | All 25 open questions closed in one pass, each by applying its own documented default. |
| 08-12 | The five sabotage gaps closed, each verified by a mini-run going red. |

At the end of week 1 the whole pipeline ran against a **frozen payload**. What was missing was not
code but **inputs**: a verified endpoint, IMAP credentials, one real portal alert, and the *plafonds*
figures. No default can supply those.

## Week 2 — the first real sources (2026-08-18 → 08-22)

| Date | Landed |
|---|---|
| 08-19 | `run --watch` to the Q37 pacing ruling. **In'li is source #1** — the `html` adapter, a verified endpoint, real pagination checked against the count the page states about itself. Schema v4 ties a flat's portals together *without letting a group hide one*. |
| 08-20 | **CDC Habitat is #2, and the first with mixed tenure** — so §1 was exercised against a real payload for the first time. Path pagination; a floor parser that knows `RDC` is zero. The first captured corpus cases. |
| 08-21 | **Cityloger is #3, and the first needing a second request per listing** — its card carries no tenure at all. `robots.txt` enforcement moved from tests-only to runtime. |
| 08-22 | **Logirep is #4** — a JSON feed served inside an HTML page, and *the silent bug it exposed* (a Solr field boxed as a one-element list returned `null`, which would have matched zero listings for ever while reporting green). Q27's heartbeat. The Q8 Docker deployment. **Region mode**: 0 matches becomes 8, then 83 when the region widened to all eight departements. |

## Week 3 — email alerts, and what a real message cost (2026-08-23 → 08-28)

| Date | Landed |
|---|---|
| 08-23 | Schema v5–v7: what a detail page said, hydration gated by the cache and capped per pass, and **the evidence a verdict was formed from**. `digest` and `reclassify`. Floor and lift read out of description prose. |
| 08-25 | **The first real portal alerts arrive.** SeLoger goes live as #5. Pointing a blind-written parser at one real message cost **six defects in a day** — four in the MIME parser, a rent reading 600 € low, and four coliving rooms scored as family flats. 1 900 green tests had said nothing about any of them. Bien'ici follows as #6 the same evening. |
| 08-26 | **leboncoin #7** — the first alert with no `text/plain` part at all. **PAP #8** — a search filter read as the flat's surface. Tier 4 armed, *and the real figures refuted the rule everyone assumed would be built*. **Commute scoring** — the component that finally discriminates, whose obvious curve was built, measured, and made the ordering worse. The first backup tool for the file the project calls unrecoverable. |
| 08-27 | The namespace and env prefixes rename: `RentWatch\` → `Scout\`. |
| 08-28 | `FEED_SILENT` — *a source can keep reporting while its feed has stopped*, found because one source reported a healthy count of 3 on 263 consecutive passes, re-reading one frozen email. |

## Week 4 — a second domain (2026-08-29 → 09-02)

| Date | Landed |
|---|---|
| 08-29 | **The car domain**, first slice: ParuVendu (email) and Autohero (sitemap + JSON-LD). Every domain-bound env key becomes `RENT_*` or `CAR_*`. **Two tracks, one push** — 43 flats had been pushed twice. |
| 08-30 | **One generic scout**: domains as namespaces, `--domain=<slug>`, a dispatcher that never defaults. Schema v12 persists the cross-track twin. |
| 08-31 | The §1 tripwire extended to the car excluded set. Pattern-miss counting (Tracks 1h/1i). A brand penalty taken out of the existing 100. leboncoin as car source #3. |
| 09-01 | `Core/RunStore` extracted, so the car domain stops composing the rent store. 22 makes penalised, matched as **stems** rather than exact strings. Hydration becomes its own collaborator. A source that publishes no listing text **says so in the push**. |
| 09-02 | Every structured extraction counts its misses, **through the one funnel**. A portal's *"I don't know"* token stops being read as a make. |

## Week 5 — the deploy gap, three more car sources, and one gate (2026-09-04 → 09-07)

| Date | Landed |
|---|---|
| 09-04 | `verify-deploy.sh` checks the **image against the code**, not just the containers — after a §1 fix ran unarmed in production for a day and a half while every container reported *running, image courante*. CapCar's payload measured. |
| 09-05 | A processed alert email is marked `\Seen`, after the store recorded it and by a `run` pass only. **CapCar #4, La Centrale #5, Agorastore #6** — content-addressed identity, labelled-block cards, and the first auction. `reclassify --reopen`. **The push gate**: a match under the line waits for the rollup. The sabotage ledger sharded six ways. |
| 09-06 → 09-07 | **§1 collapses into one gate.** A certification panel ran five rounds against the four-routes × three-surfaces matrix and found 9, then 8, then 17 defects — *in every round the majority were in the previous round's fixes*. `SectionOneGate` reads all four routes fresh at the last moment before every send, and two reflection-driven guards make an unguarded surface fail the suite rather than ship. |
| 09-07 | The health-alert flap: a single failed run had counted as a broken source, costing 77 flap emails in four days. A failed run's zero is now *unknown*, not *zero listings*. |

---

## What this history is actually a record of

Five patterns recur often enough to be worth stating as findings rather than as anecdotes. Each cost
at least a day.

1. **A true number attached to an invented cause.** The most dangerous thing a documented measurement
   can do: *"live yield is 0 because everything is outside the commune filter"* was right about the
   number and wrong about the reason, so nobody looked again. Corrected repeatedly, and it recurred
   **while writing the entry that warns about it**.
2. **A fix landing on one of two symmetric surfaces.** Committed at least five times, three of them
   *inside the fix for the one before*. The repair is never the keystroke — it is a guard that
   **discovers** the surfaces by reflection instead of listing them, because every hand-maintained
   enumeration in this repo has gone stale in the commit that edited it.
3. **A generalisation from n=1.** A separator, a title pattern, a lift claim — each measured on one
   captured message and each wrong about the source. The corpus and the fixture directories exist to
   make the second capture the first regression test.
4. **A guarantee whose branch no fixture reaches is dead safety code.** Repeatedly, the obvious
   sabotage failed to detect a deleted safety because the branch was unreachable with the frozen
   payloads. The counterweight case — proving the thing stays *silent* when it should — is missing far
   more often than the positive one.
5. **Green, pushed and deployed are three different things.** Twice measured, at seventeen hours and a
   day and a half, both times with a clean tree and a passing suite.

---

## Still open

- **`src/phorj/`** — the second implementation of the pure core. On indefinite hold (2026-08-19):
  deprioritised, not blocked. `docs/PHORJ-REQUIREMENTS.md` records what it would need.
- **AL'in (A4)** — the only remaining route to one landlord group's stock, and it is authenticated.
  An input, not a decision.
- **Q40** — this repository is public, and three fixtures were committed before being scrubbed. The
  old blobs remain reachable in history. Default is *accept*; the alternative is a history rewrite
  whose re-pointing cost is priced in `docs/OPEN-QUESTIONS.md`.
