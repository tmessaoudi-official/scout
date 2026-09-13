# job-domain Plan

A third scout domain, `--domain=job`: watch job offers from every usable source and push the ones
that match. Phase: DESIGN (developer chose "design first", 2026-09-13). No code until the formal
plan below is approved.

## Decisions Log
- [2026-09-13 14:15] AGREED: design first — read the registry and the car domain, catalogue sources, then a full plan with criteria before any code.
- [2026-09-13 14:15] AGREED: contract types surfaced are CDI, freelance/mission and portage salarial; CDD is not.
- [2026-09-13 14:15] AGREED: location is remote or hybrid in Île-de-France; an on-site-only offer is scored lower, never rejected.
- [2026-09-13 14:15] AGREED: ESNs, consulting firms and reposting intermediaries are neither rejected nor penalised (for now).
- [2026-09-13 14:15] AGREED: pay is scored against 65k€/year and 550€/day, and REJECTED only when the offer STATES a salary below 59k€/year or a freelance/portage day rate below 450€/day — an unstated pay never rejects (hard rule 9).
- [2026-09-13 14:25] AGREED: robots.txt stays literal EXCEPT for an authenticated API the developer registered for (OAuth or issued key), with its terms URL recorded on the source; unauthenticated endpoints (SmartRecruiters, Remotive /api) keep literal robots.
- [2026-09-13 14:25] AGREED: a stated salary RANGE is tested against 59k€ on its UPPER bound — "55-65k" is kept and scored against 65k€, only a range entirely under 59k€ is rejected.
- [2026-09-13 14:25] AGREED: build step by step — setup first, LinkedIn alert as the first source, everything else after.
- [2026-09-13 14:25] AGREED: Gmail setup is the developer's — labels `job-watch` and `job-watch/portails` created, a filter moves `jobalerts-noreply@linkedin.com` into `job-watch/portails` (it had already labelled all existing alerts: 0 unlabelled on check) and a `[job-watch]` subject filter catches scout's own mails.
- [2026-09-13 14:25] AGREED: job pushes go to their own ntfy topic, `JOB_NTFY_TOPIC` in `.env` (created and test-published HTTP 200 the same day), and build progress is posted there too.
- [2026-09-13 14:25] AGREED: an offer with no stated pay is shown and earns 0 of the pay share (`salaire non précisé — hors score`), with no extra negative weight — the car/rent unknown-component rule.
- [2026-09-13 14:25] AGREED: content is scored from weighted keyword lists in `config/job/criteria.json`, read on the TITLE and on any description text a source carries; the developer wants some keywords MANDATORY (a filter, so e.g. an accountant offer is never shown) and others optional — mechanism still to be ruled.
- [2026-09-13 14:25] AGREED: N4 — an offer stating a location outside Île-de-France is rejected only when on-site or hybrid work is also stated; full remote, or remote unstated, keeps it with a line.
- [2026-09-13 14:25] AGREED: N3 — one switch `eligibility.french_nationality: false` rejects offers STATING French nationality or secret-défense clearance required (anchored, negation read first); the developer flips it after naturalisation.
- [2026-09-13 14:25] AGREED: the MANDATORY filter is a TITLE role gate only — an offer whose (non-empty) title matches no developer-family role word is rejected; stack words are never mandatory (a mandatory PHP/Symfony title word would drop ~95 % of measured LinkedIn cards). The developer will widen their LinkedIn alerts to other stacks.
- [2026-09-13 14:25] AGREED: title words that REJECT despite the role gate — junior / stage / alternance (title-anchored), sales / business developer / recruiter, and data roles (data engineer / scientist / analyst, ML engineer); DevOps is NOT a reject, a dev role asking for some DevOps is wanted.
- [2026-09-13 14:25] AGREED: optional keyword scoring starts from the proposed lists and must be WIDENED beyond PHP/Symfony — Java/Spring and Vue.js at least — after another research round.
- [2026-09-13 14:25] AGREED: pay is read from the TITLE and from any description CONTENT a source carries, not from the title alone — confirmation of the reading shape still to be asked.
- [2026-09-13 14:25] AGREED: stack scoring is multi-stack and grouped — BACK (PHP/Symfony/Laravel, Java/Spring Boot, Node/NestJS/TypeScript) full back share, FRONT (Angular, Vue.js/Nuxt, React/Next.js) full front share, ADJACENT (Python, Go, Kotlin, .NET) half, any other primary stack 0 with a `stack principale X` line; never a reject.
- [2026-09-13 14:25] AGREED: legacy modernisation is GREEN (refonte, migration, modernisation, legacy to modern, from scratch, greenfield) and run-only work is RED (TMA, maintenance corrective/applicative, support N1-N3, astreintes, WordPress/Drupal/Magento/PrestaShop, régie, déplacements fréquents); a RED signal lowers the score and names itself on the push, it NEVER hides an offer.
- [2026-09-13 14:25] AGREED: four GREEN signal groups score, each capped and negation-first — craft & architecture, AI-augmented dev (tool senses only), product company, work conditions.
- [2026-09-13 14:25] AGREED: pay is read from the title, any description, and LinkedIn's own salary line (HTML part / subject); a unit is required, monthly gross ×12, the upper bound is tested against 59k€/450€ (reject) and scored against 65k€/550€; a figure without a unit is ignored, never guessed.
- [2026-09-13 15:37] AGREED: only the hard rejects hide an offer — role-gate miss, junior/stage/alternance, sales/recruiter, data role, stated pay under 59k€/450€, stated contracts all out of scope, nationality/clearance (switch), outside IdF with on-site/hybrid stated — and every hidden offer is still logged; RED signals never hide.
- [2026-09-13 15:37] AGREED: starting weights out of 100 — stack 25 · pay 20 · title level 15 · green signals 15 (craft, AI, product) · remote & work conditions 15 · freshness 10; each RED signal −5, capped at −15; re-measured after a week of real rows.
- [2026-09-13 15:37] AGREED: go — build slice 1 (scaffold → model/criteria/store → LinkedIn source with scrubbed fixtures → pipeline/CLI → tests + sabotage → docs → deploy), commit per green step, progress posted to the job ntfy topic.
- [2026-09-13 15:37] AGREED: Phase 3C certification tier for slice 1 is `advisor()` only.
- [2026-09-13 16:31] AGREED: `JOB_NTFY_TOPIC` is regenerated to the documented `jw-<32 hex>` scheme, the same strength as the `rw-`/`cw-` topics. The first topic had 20 hex, a generation slip, and it is retired.

## Evidence gathered (2026-09-13)
- `Cli/Domains::all()` is the registry — a new domain is one entry plus `Scout\<Slug>\`, `config/<slug>/` and `<SLUG>_*` keys.
- The car domain is the template: 23 files, ~4 100 lines under `src/php/Car/` (CarScout 1 063, VehicleEmailSource 551, VehicleStore 367); generic core reused as-is (~7 000 lines: RunStore, health, Pacer, Heartbeat, IMAP, HTTP/Robots, Notify, Redact, PatternMissLog).
- Mailbox census: LinkedIn job alerts (`jobalerts-noreply@linkedin.com`) arrive every ~2 h in INBOX, text/plain part present, digest of cards separated by a dashed rule, each with title / company / location and a `linkedin.com/comm/jobs/view/<id>/` link — link identity is available. No salary in the plain part. The footer carries the subscriber's name and headline, and every link carries an `otpToken`: both must be scrubbed from any fixture.
- Free-Work sends a weekly newsletter of mixed-region offers (Île-de-France, Lille, Bouches-du-Rhône in one issue), not a saved-search alert.
- NO other portal currently sends a job alert. The latest mail from each was read, not assumed. Indeed (2026-08-10): a recruiter-share reminder. WTTJ (08-01): profile validation. APEC (08-27): marketing. Cadremploi (07-11): tracking-pixel notice. Glassdoor (07-08): Indeed merger notice. HelloWork (05-14): OTP code. The last real APEC alert was `offres@diffusion.apec.fr` on 2026-04-30: a profile-based recommendation, one card (title / `CDI` / `Montreuil - 93`), every link an opaque `neomarket.diffusion.apec.fr/r/` redirect carrying base64 parameters. So APEC offers NO link identity from the email alone.
- HelloWork's tracking links embed the subscriber address base64-encoded in the path, which the fixture scrubber already has to handle.
- ~~No `job-watch/portails` label exists yet~~ — created by the developer the same day (Decisions Log).
- **Title role gate, measured 2026-09-13.** A scratch script ran over 89 LinkedIn alerts from the last 21 days, read without marking through `ImapMailbox`, and yielded 277 distinct job ids. A candidate developer-family title regex failed 22 of them. 16 of the 22 were PARSER artefacts: a footer line (`Gérer les alertes`, `Voir toutes les offres`) was taken as the title while the real title — e.g. `Senior Software Engineer PHP`, `Lead Développeur Fullstack PHP` — sat in the next slot and passes. Of the six genuine misses, four are regex gaps to fix: the plurals in `Développeurs & Ingénieurs Tech`, which appears three times, and `Technical Lead`. The remaining two are the non-developer roles the gate exists for: `Low-Code Product Builder` and `Applied AI - Digital Experience`.
- **What the measurement also showed about the template:**
  - One email can merge SEVERAL saved searches, in a primary list and a `Nouvelles offres d’emploi issues de vos autres alertes` secondary list. The developer has at least four LinkedIn alerts: Ingénieur Full Stack IdF, full stack engineer Sartrouville, Ingénieur Full Stack Ville de Paris, Ingénieur logiciel senior IdF.
  - The location line is a commune, `Paris`, `Ville de Paris`, `Paris et périphérie`, `Île-de-France, France`, or a bare `France` (81 of 277), and `US` appears 3 times.
  - Pay sometimes appears only in the TITLE: `70 / 90K fixe`, `Up to 75K`.
  - Only ~5 of the sampled titles name PHP or Symfony, so a mandatory stack word on the title would drop ~95 % of cards [Inferred: from the 60-card pass sample and the fail list].
- **Title vocabulary, measured 2026-09-13** over 358 distinct LinkedIn job ids from the last 30 days. Each word is counted once per title.
  - Role words: `engineer` 116, `senior` 74, `full stack` 62 (bigram), `software` 58, `developer` 43, `fullstack` 39, `développeur` 26, `lead` 17, `staff` 7, `ingénieur` 7, `founding` 8, `product` 8, `technical lead` 3.
  - Stack words: `react` 14, `node.js` 13, `php` 13, `typescript` 8 (+ `ts` 4), `js`/`javascript` 11, `laravel` 5, `python` 5, `next.js` 5, `postgresql` 4, `symfony` 2, `angular` 2, `.net` 2 (+ `dotnet` 1), `java` 1, `ror` 1; `vue` 0; `ai` 22.
  - Pay in titles: `100k 160k equity` 7 times.
  - LinkedIn DOES carry pay outside the plain part. A single-offer alert's subject read `Senior Software Engineer chez Aneo : jusqu’à 70 k € par an`, and its HTML snippet `Salaire entre 44 k € et 70 k € par an`.
  - Stack words are rare in titles, so the stack score will mostly come from description text where a source has it. On a LinkedIn card it is a small title-only share.
- **The HTML part carries what the plain part lacks.** Measured 2026-09-13 over the 20 captures, split on each message's real MIME boundary.
  - Both parts list the SAME job ids: 119 distinct across the 20, with no id present in only one part.
  - Per card, the stripped HTML reads: link / title / link / `Company · Location (Hybride|À distance|Sur site)` / optional pay line `Entre X k € et Y k € par an` / flags (`Recrutement actif`, `Candidature simplifiée`, `Appliquer`, `N relation(s)`, `1 ancien collègue`).
  - There are 123 card segments, 4 of which repeat an id within the same message. These come from the `issues de vos autres alertes` list and are legitimate, so they are not an error.
  - A work mode is stated on 115 of the 123 segments: Hybride 71, À distance 32, Sur site 12. It is absent on 8.
  - A pay line is present on 7 segments, all annual: 40–45 k, 45–55 k, 44–70 k and 60–78 k. The 40–45 k card is a genuine H1 reject on live data.
  - The plain part has neither the work mode nor the pay line. `EmailMessage::parse()` prefers text/plain, so a source reading `->body` alone would score remote (15) and pay (20) as unknown on every card.
  - **TRAP: the HTML preheader.** Every one of the 20 HTML parts starts with `Salaire entre …`, followed by a description snippet rather than a pay figure (e.g. `Salaire entre <company> <title> : Qui sommes-nous ?…`). It is template boilerplate. It sits BEFORE the first job link and must never be read as pay.
  - Other message-level boilerplate that must not reach a card: the search scope `(Paris et périphérie)`, and the footer headline, which names the subscriber's own stack (`PHP · Symfony · …`). Card segments therefore start AT a card's first `/jobs/view/<id>/` link.
  - **The pay line uses NO-BREAK SPACES:** `44 k €` is `44` U+00A0 `k` U+00A0 `€`. A pay reader
    that assumes an ordinary space reads zero pay lines on real mail. It must use `\h` (which
    matches U+00A0 under `u`), and a fixture must carry the real bytes.
  - **Scrubbing a LinkedIn capture needs more than the name** (measured on capture 10):
    - The footer names the subscriber in both parts. One occurrence is split by a QP soft break.
    - The first-name slug appears in a `fr.linkedin.com` profile-URL path.
    - Every link carries per-recipient tokens (`lipi`, `midToken`, `midSig`, `eid`, `trk`,
      `trkEmail`, `otpToken`, `trackingId`, `refId`, `savedSearchId`). Some of their NAMES are
      folded mid-word by a soft break.
    - The headline in the footer is also identifying.
    - `tools/scrub-eml.php` learns all of these, each with a case in `tests/test-scrub-eml.sh`,
      before any job fixture is committed.
    - **Needles cannot remove the headline**, measured on captures 04, 09 and 10. Its `·` is
      `=C2=B7` in QP, so a byte-literal needle never matches across it. Its generic half,
      `Lead Developer`, is also a card title in capture 09, so it can never be a needle. The
      scrubber therefore removes the whole footer sentence: from `destin=C3=A9 =C3=A0 ` (identical
      bytes in every capture) through the closing `)`, replaced with `abonne`. Trial on 04 and 10:
      2 of 2 footers replaced per capture, generic-half counts drop only by the footer occurrences,
      the 6 job ids are identical, and the parser reads them back.
    - **A re-folded placeholder used to join lines.** The link-token replacer consumed the soft
      breaks inside a folded value and wrote the placeholder inline, so capture 10 came out with a
      335-byte line (raw body maximum 76). `$refold` now puts the placeholder on lines of its own;
      the trial's maximum body line is back to 76.
  - A first census cut the HTML part at the first `\n--` and reported 82 vs 119 cards. That figure is wrong: a line inside the HTML starts with `--`. The figures above come from the boundary split.

## Research inputs (2026-09-13)
Full catalogues live in gitignored scratch — `var/claude/jobs/sources-research.md` and
`var/claude/jobs/criteria-research.md`. The facts below are the ones the plan rests on, copied here
because scratch does not survive a clone.

- **France Travail *Offres d'emploi v2* is the only open, general-purpose French job API.**
  Registration at francetravail.io, 10 calls/s, Open Licence 2.0 [Verified: data.gouv.fr
  dataservice page]. The search endpoint answers 401 without a token [Verified]. The OAuth2 token
  URL and scopes are Unverified: they must be captured from the developer console after registering
  (hard rule 1).
- **robots.txt conflict:** `api.francetravail.io` serves `User-agent: *` / `Disallow: /`
  [Verified]. So do `api.smartrecruiters.com` and `api.adzuna.com`, and `remotive.com` disallows
  `/api/*`. `Robots` enforces literally, so all four are REFUSED as wired. This needs a ruling.
- **Company ATS boards are the primary sources.** Greenhouse and Lever publish public per-company
  job-board APIs; they need a company watch-list.
- **Every large private board is EMAIL_ALERT only:** WTTJ, APEC, HelloWork, Indeed, LinkedIn,
  Cadremploi, Monster, Free-Work. Seven answer a plain GET with an anti-bot page. That kills polling,
  not alerts.
- **Public sector:** the Choisir le service public RSS is `200 text/xml`, 20 items, and its robots
  file is empty [Verified].
- **Pay transparency: France has NOT transposed Directive 2023/970** [Verified: service-public.gouv.fr,
  2026-09-10]. The bill reached the Conseil des ministres on 2026-09-10, and a mandatory range in ads
  is unlikely before ~2028 [Inferred]. **Most offers will state no pay, so the pay floors will rarely
  fire.** That is hard rule 9 working, not a defect.

## Formal Plan
*Proposed 2026-09-13, awaiting go.* Size: **Large**. Template: the car domain — 23 files, ~4 100
lines — with the generic core reused unchanged.

### Hard disqualifiers (rejected, logged, never pushed) — rule 8
- **H1** — a STATED gross salary whose **upper** bound, annualised, is under 59 000 €. Never on null,
  `selon profil`, a net figure, a package-only figure, or a portage salary figure.
- **H2** — a STATED freelance/portage TJM excluding VAT whose upper bound is under 450 €. Never on
  null or on an hourly rate.
- **H3** — the offer STATES contracts and **every** one is CDD, stage or alternance. Stage and
  alternance are read from the contract field or the TITLE only: `apprentissage automatique`,
  `encadrement de stagiaires` and `étage` (folded, it contains `stage`) are the three measured
  false-positive shapes. A dual offer (`CDI ou freelance`) is a set, and one ruled contract keeps it.
- **H4** — user `exclude_title_patterns` / `exclude_patterns`, seeded with the `business developer` /
  `développeur commercial` family.

- **H5** — the TITLE fails the developer-family role gate. The gate must accept inclusive and feminine forms
  (`développeuse`, `développeur.euse`, `ingénieure`), plurals, and `technical lead`.
- **H6** — title rejects: junior / stage / alternance (title-anchored), sales / business developer /
  recruiter, data roles. DevOps is not a reject.
- **H7** — `eligibility.french_nationality: false` and the offer STATES French nationality or
  secret-défense clearance is required (negation read first).
- **H8** — (N4) a location outside Île-de-France is stated AND on-site or hybrid work is stated. A
  bare `France` counts as unknown and never rejects. IdF is recognised by NAME (Paris, `Ville de Paris`,
  `Paris et périphérie`, `Île-de-France`, the 8 département names, a commune list), because
  `Core/Department` is keyed on postcode and LinkedIn gives no postcode.

### Score components (clamped, never reject) — the ruled starting weights, re-measured after a week of rows
Stack 25 · pay vs 65 k€ / 550 €/day 20 · title level 15 · green signals 15 (craft, AI, product) ·
remote & work conditions 15 · freshness 10. Each RED signal −5, capped at −15. An unknown component
says so on its own line and earns 0.
- **Freshness on LinkedIn:** the card carries no publication date, so the component is
  `date de publication inconnue — hors score`. The weights are NOT silently rebalanced. A LinkedIn
  card can therefore reach at most 90, and at most 70 without a pay line. That decides
  `push_min_score`, which stays unset until a week of real rows exists.

### Slice 1 — what gets built
1. **Registry + scaffolding.** The `Domains` entry is `job` / `job-watch` / `JOB_` / `config/job`, in
   the `Scout\Job\` namespace. `JobScout` knows only `help`: every planned verb refuses by name with
   exit 2, and no verb is a silent success.
   - Each generic surface lands WITH the step that first makes it real, never ahead of it. Declaring a
     surface early is a key nothing reads (drift-scan S8(d)), or a compose service that crash-loops.
     - `JOB_SCOUT_DB` and `backup-state.sh` land with the store (step 4).
     - `JOB_IMAP_MAILBOX`, `JOB_FEED_SILENT_DAYS` and the `PatternMissEscalationTest` scan dir land
       with the source (step 5).
     - `JOB_NTFY_TOPIC`, `JOB_HEARTBEAT_HOURS` and the `job-scout` compose service land with the CLI
       and deploy (steps 6 and 9).
2. **Model.** `JobListing` — title, company, commune/postcode, contract SET, salary
   min/max/period/basis, TJM min/max, remote mode + on-site days, published/observed instants, url,
   external id. Every measurement is null when unstated. `JobSnapshot` has a reflection round-trip
   test.
3. **Judgement.** `JobClassifier` (H3 contract set, stage/alternance anchoring) → `JobCriteria`
   (strict loader, `_`-comments, refusals) → `JobScorer` (H1/H2/H4 + components, `reasons[]`, an
   unknown component says so).
4. **Store.** `JobStore` — own tables, composing `Core\RunStore`; seen-set, notified-at, the verdict
   snapshot.
5. **Sources.**
   - `JobEmailSource` with the **LinkedIn job alert** as source #1. It reads cards from the HTML
     part (see *Design choice* below). Identity is the `/jobs/view/<id>/` number, and `params.from`
     scopes the source. The fixtures are scrubbed before any test is written against them. The
     scrub replaces the subscriber's name and headline in both parts and strips every tracking
     parameter (`otpToken`, `midToken`, `trkEmail`, …). Two checks must come back 0 on the output:
     `grep -c` for the surname, and `grep -c` for `otpToken=`. Then `FixtureSecretsTest` runs, and
     only after that the commit. Captures 10 (pay 44–70 k) and 04 (pay 40–45 k, the H1 reject) are
     frozen first, with every value hand-read.
   - `FranceTravailSource` (OAuth2 client credentials, `JOB_FT_CLIENT_ID/SECRET`) — **only if the
     robots ruling allows it**. The endpoint and field map come from a live capture, never from
     memory.
6. **Pipeline + CLI.** `JobPipeline` (the car shape: a throwing source is one failed source, each
   listing recorded at its own observation instant, messages acknowledged after recording, health
   alerts), `JobFormatter` (source first, then score, then headline), `JobScout`: `doctor`, `dump`,
   `run --once/--seed/--watch`, `test-notify`, `rollup`.
7. **Tests.**
   - Fixture tests with every value hand-read.
   - H1–H4 in both directions, including the three H3 false-positive traps and "null never rejects".
   - Reflection coverage that every configured pattern is counted by `PatternMissLog`.
   - Sabotage ledger cases per guarantee, each shown to redden a BEHAVIOURAL test.
8. **Docs.** CLAUDE.md job section, `docs/SOURCES-LIVE.md`, `docs/RUNBOOK.md`,
   `docs/ARCHITECTURE.md` layer map, README deploy.
9. **Deploy.** Build, back up, `up -d`, `tools/verify-deploy.sh` — then read the first live pass,
   which is the only proof that counts.

### Slice 2 and later
- More alerts as they arrive: WTTJ, APEC, HelloWork, Free-Work, Indeed.
- Greenhouse/Lever boards for a company watch-list.
- Commute — this means moving `CommutePlanner` out of `Rent\Enrich` into `Core`, because a domain
  must not import another domain.
- Twin detection across portals (C12).
- Seniority, language, scam, clearance, travel, astreintes.
- Extracting a shared card-segmenting email reader. Speculative: two copies exist today, and a third
  is the moment to consider it, not before.

### Needs input from the developer (no code can supply these)
- ~~A Gmail label `job-watch/portails` and its LinkedIn filter~~ — done 2026-09-13 (Decisions Log).
- Alerts created on WTTJ, APEC, HelloWork, Free-Work and Indeed, into the same label (slice 2).
- A francetravail.io application (client id + secret into `.env`) — slice 2, authenticated API.

### Open rulings, with the default applied until ruled
| # | Question | Default |
|---|---|---|
| N1 | Only a package is stated (no fixe) | never rejects; scored at face value, line `package (fixe non précisé)` |
| N2 | Dual offer, one pay line under its floor | keep; reject only when EVERY stated pay line is under its floor |
| N3 | Clearance / nationality required | **RULED** 14:25 — H7, one switch |
| N4 | Location stated outside Île-de-France, not full remote | **RULED** 14:25 — H8 |
| N5 | Intérim, VIE, public-sector contractuel | not rejected; contract shown |
| N6 | Portage offer quoting a monthly salary, no TJM | no floor on it |
| N7 | Your years of experience | seniority component deferred |
| N8 | Hourly freelance rates | ignored |

### Design choice — reading LinkedIn's HTML part (2026-09-13, pre-work check)
This is an implementation choice made under the "go" ruling, not a new ruling.
- `EmailMessage` gains ONE additive public property, `htmlText`: the stripped text of the text/html
  alternative, with entities decoded and hrefs harvested; `''` when no HTML part exists.
- `body` and `links` stay byte-identical. The existing rent and car email fixture tests passing
  unchanged is the counterweight.
- It rides on `preferredPart()` as a by-reference out-param, so all 14 sabotage expressions aimed at
  `EmailMessage.php` keep matching. `return $plain ?? $html ?? '';` is untouched.
- The two rejected alternatives:
  - Source-local MIME parsing would duplicate the preamble / empty-part / charset rules, which cost
    four defects to learn.
  - Plain-only loses work mode and pay on every card.
- `JobEmailSource` reads cards from `htmlText`. Each segment runs from a card's first
  `/jobs/view/<id>/` link to the next distinct id. Everything before the first link (the
  `Salaire entre` preheader, the search scope) and from `Voir toutes les offres` onward (the footer
  headline) is excluded.
- Identity is the numeric job id. A repeated id inside one message is kept once, silently: it is the
  secondary `autres alertes` list, not an extraction fault.
- No `subject_pattern` is configured: three subject templates appeared in 20 captures. `from`
  scopes the source.
- The IMAP cap comes from the shared `ImapMailbox::maxMessages()`. At ~10 alerts a day, a 7-day window
  holds more than the car CLI's literal 50.

### Step 3 design — reading, criteria, verdict (2026-09-13, pre-work check)
This is an implementation choice made under the "go" ruling, checked by `advisor()`, not a new ruling.
- **Three layers.** `JobClassifier` READS facts and knows no config: contracts, work mode and
  remote days, title level, pay lines (`JobPay` → `PayLine`), an eligibility clause. `JobCriteria`
  holds every rule the developer can move: role words, title rejects, stack groups, GREEN/RED terms,
  the IdF and outside-IdF place names, the H7 switch, the weights. `JobScorer` applies H1–H8 and then
  the six components. Nothing in this domain is a non-overridable set, so `tenure-guard.sh` does not
  cover it, and the classifier's docblock says so.
- **The pay kind lives in the words, not on `JobListing`.** A structured `salaryMinEur`/`salaryMaxEur`
  is gross annual by contract. A source that can only say `package` or `net` leaves those fields null
  and puts the words in `payText`, where `JobPay` marks the basis. This keeps N1 (a package never
  rejects) and "a net figure is never compared" true with no second field to keep in sync.
- **The config seeds from the RULINGS where they differ from the research.** Stack scoring is the
  grouped BACK/FRONT/ADJACENT ruling, not C8's 0.6 PHP/Symfony share. Non-dev roles are handled by
  the positive role gate, not by C7(b)'s exclude list. RED (−5 each, capped at −15) is its own key,
  outside the 100. An EMPTY role-word list is refused at load: a gate that rejects everything is a
  disabled feature dressed as a configured one. `notify` lands with the CLI (step 6).
- **H8 fails open.** A place recognised as neither IdF nor outside it is UNKNOWN and never rejects.
- **The realistic LinkedIn ceiling is lower than 90/70.** Freshness is always unknown on a card
  (−10). Pay is stated on 7 of 115 cards. Few titles name a stack word. So a typical card tops out
  around 65, or around 45 without pay. That is the population `push_min_score` gets calibrated
  against, and it is not a defect.

Evidence for the design, measured 2026-09-13 over the 20 captures:
- **Card census.** The 20 captures carry 115 cards with 57 distinct titles.
  - Work-mode suffix: `Hybride` 69, `À distance` 29, `Sur site` 12, none on 5.
  - Pay lines: 7 cards carry one, in 4 distinct forms. All read `Entre X k € et Y k € par an`, with
    U+00A0 between the figure, the `k` and the `€`.
  - Locations: 13 forms. Every one is in Île-de-France or a bare `France`, so no card exercises H8.
- **Classifier trial** over the same 115 cards:
  - 0 unreadable.
  - Pay: all 7 lines read, 0 missed.
  - Contracts: `cdi` on 8 cards, all from titles.
  - Levels: lead 28, senior 27, confirmé 7, unlabelled 53.
  - Modes agree with the card suffix on every card.
- **Criteria trial (step 3b).** The shipped role gate passes all 57 captured titles and no title reject
  fires on any of them — but only after one fix the trial forced. `Text::fold` keeps `_`, and `_` is a
  word character to `\b`, so the real title `Forward Deployed Engineer_3202` (a requisition number)
  failed `\bengineer\b` and was rejected. `JobText::surface` now spaces `_` out AFTER the URL query
  strip; before it, a tracking token's tail would survive the strip (`?trk=php_symfony` read Symfony).
  Both directions are pinned by `JobCriteriaTest`.
- **Scorer choices (step 3c)**, implementation choices under the "go" ruling, none a new ruling:
  - **H1 and H2 are one set (N2).** Pay rejects only when EVERY stated line is comparable AND under
    its floor. A net line, a package line or a portage-only salary is not comparable, so its presence
    keeps the offer. A gross monthly line is floored on 13 months and scored on 12.
  - **An unreadable text REJECTS, named.** The classifier returns `texte illisible : …` instead of
    facts, so H1–H8 cannot be applied to it. The rejection is logged like every disqualifier.
    [Speculative — reversible to a score-0 match that says so, if unreadable offers turn up in real
    alerts.]
  - **Pay is scored on gross salary and TJM lines only**, best line wins, clamped to the full share.
    A net or package figure is shown as `non comparable — hors score`.
  - **The publication date is parsed strictly by round-trip.** `new \DateTimeImmutable('yesterday')`
    is a date to PHP, and would give an undated offer full freshness.
  - **Adjacent never adds to back.** It is read only when no back term fires, and the share takes back
    first. That guarantee is defended twice, so its sabotage case mutates both.

### Rollback
Additive. The domain is one registry entry, its own namespace, config dir, state file and compose
service. Removing those five restores today exactly. The one change to the generic core is the
additive `EmailMessage::htmlText` property. Nothing but the job source reads it, so leaving it in place
after a rollback is harmless; reverting its commit removes it.

## Status
<!-- progress-block v1 -->
| # | Step | Size | State | Evidence | Files |
|---|------|------|-------|----------|-------|
| 0 | EmailMessage exposes the HTML alternative (`htmlText`), body unchanged | S | done | 774f4f7 | src/php/Adapters/Mail/EmailMessage.php tests/php/Adapters/EmailMessageTest.php tests/sabotage-check.sh |
| 1 | Registry + JobScout (help only; generic surfaces land with the step that reads them) | M | done | cff36d3 | src/php/Cli/Domains.php src/php/Job/Cli/** tests/php/Job/Cli/** config/job/** |
| 1b | Scrubber learns LinkedIn: per-recipient link tokens, soft-break-folded needles | S | done | d1193dd | tools/scrub-eml.php tests/test-scrub-eml.sh |
| 2 | Model: JobListing + JobSnapshot | M | done | f5f664c | src/php/Job/JobListing.php src/php/Job/JobSnapshot.php tests/php/Job/JobSnapshotTest.php |
| 3 | Judgement: JobClassifier, JobCriteria(+Loader), JobScorer | L | done | 0b1fdb7 | src/php/Job/** config/job/criteria.json tests/php/Job/** |
| 4 | Store: JobStore composing RunStore | M | todo | - | src/php/Job/** tests/php/Job/** |
| 5 | LinkedIn source: scrubbed fixtures, JobEmailSource | L | todo | - | src/php/Job/** config/job/sources.json tests/fixtures/job/** tests/php/Job/** |
| 6 | Pipeline, formatter, JobScout CLI | L | todo | - | src/php/Job/** tests/php/Job/** |
| 7 | Sabotage ledger cases | M | todo | - | tests/sabotage-check.sh |
| 8 | Docs | M | todo | - | CLAUDE.md README.md docs/** |
| 9 | Deploy + first live pass | M | todo | - | compose.yaml |
<!-- /progress-block -->
### Blocked
### Needs input
- Slice 2: job-alert subscriptions on WTTJ, APEC, HelloWork, Free-Work and Indeed, into `job-watch/portails`.
### Needs research
- Keyword list widening (Java/Spring, Vue.js, …) — seeded in step 3 from `var/claude/jobs/criteria-research.md`.
### Fragile
### Known issues
