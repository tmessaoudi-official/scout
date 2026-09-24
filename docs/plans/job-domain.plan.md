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
- [2026-09-13 21:18] AGREED: a gross MONTHLY salary is tested against the 59 k€ floor on ×13 and scored on ×12 — ratifying the step 3 choice, which refines the 14:25 "monthly gross ×12" ruling for the floor only.
- [2026-09-13 21:18] AGREED: step 4 (store) goes next; the monthly-pay reading gaps are recorded as a known issue and fixed against real slice 2 alerts, not guessed now.
- [2026-09-13 21:49] NOTED: the job store records WHAT an offer was announced as, ROLLUP < MATCH and never demoted, from schema v1 — a design choice made in step 4 (not a ruling), because step 6 adds the rollup and no deployment exists to migrate.
- [2026-09-13 21:49] NOTED: the Unicode id trim moved to `Core\Whitespace::trim()` and is shared by the rent and job stores; the car store still uses `trim()` (Known issues).
- [2026-09-13 22:50] NOTED: the LinkedIn source reads its cards through config params (`card_link_pattern`, `footer_marker`, `place_pattern`, `pay_pattern`) and maps the three mode words in code; `pay_pattern` is deliberately not a counted pattern, because 7 of 115 real cards state pay — a design choice made in step 5 (not a ruling).
- [2026-09-13 22:50] NOTED: `JOB_IMAP_MAILBOX` and `JOB_FEED_SILENT_DAYS` move from step 5 to step 6, where the CLI is their first reader (drift-scan S8(d): a declared key nothing reads is a finding).
- [2026-09-13 22:50] NOTED: a card id repeated in one message is kept once and WARNED, never dropped in silence; the same id continues a card only across its title line (logo link, title, title link).
- [2026-09-13 22:50] NOTED: the scrub check `grep -c otpToken=` must be 0 was unsatisfiable — the scrubber keeps parameter NAMES by design — and is replaced by: every decoded `otpToken` value is a `FIXTURE<n>` placeholder.
- [2026-09-13 22:50] NOTED: step 5 adds no `publishedAt` round-trip test, because LinkedIn cards carry no date; the Fragile entry stays open for slice 2.
- [2026-09-13 23:29] NOTED: the job CLI imports `Rent\Core\DigestSchedule` and `Rent\Notify\Formatter` exactly as the car CLI does; two domains now import them, and the next shared import is the moment to lift them into `Core` — a design choice made in step 6 (not a ruling).
- [2026-09-13 23:29] NOTED: the job pipeline's push check is `wasNotifiedAs(MATCH)`, so an offer already in a sent rollup is still pushed once when it later clears the gate, and an offer is counted as held back only while no announcement covers it — a design choice made in step 6 (not a ruling).
- [2026-09-13 23:29] NOTED: `config/job/criteria.json` ships `notify.channels: ["console"]`, no `push_min_score` and `rollup_hour` 8, so every match is pushed until real rows calibrate a gate; the phone channels arrive through a gitignored `criteria.local.json` at deploy (step 9), as on the car and rent sides — a design choice made in step 6 (not a ruling).
- [2026-09-13 23:29] NOTED: a job push is always NORMAL priority, with no `!!` marker, because no score bar has been calibrated to earn one — a design choice made in step 6 (not a ruling).
- [2026-09-14 13:19] NOTED: measured before deploy on the live mailbox, console-only: `--seed` records and marks without judging, so a separate `run --once -v` judged the window — 156 cards, 93 distinct offers, 88 MATCH and 5 REJECT, match scores p50 21 · p90 43 · max 51, 12 at ≥ 40, and 7–17 new offers per UTC day, i.e. ~12 pushes/day with no gate.
- [2026-09-14 13:19] AGREED: the deployed job watcher pushes individually at `push_min_score` 40 and drains the rest in a daily rollup at 09:00 (`rollup_hour` 9), both set in the gitignored `config/job/criteria.local.json` beside the ntfy and email channels.
- [2026-09-14 13:19] AGREED: the live reject of `Architecte Php` as `intitulé hors métier` is recorded as a known issue and fixed as a follow-up step with a failing test first; it does not block the deploy.
- [2026-09-14 13:29] NOTED: step 9's Files cell widened from `compose.yaml` to the Dockerfile, CLAUDE.md, README.md, docs/**, .env.example and tools/verify-deploy.sh, because the deploy made every "not deployed / no compose service / both watchers" claim false and the Dockerfile header had to change before the image build; the step landed as `ac76abd` (service), `7c79cb9` (rulings) and `c5be0df` (docs), and `c5be0df` is cited as the evidence because it touches the widened cell — a scoping record, not a ruling.
- [2026-09-14 13:56] AGREED: an architect title passes the H5 role gate when it names a stack word the criteria already score (`Architecte Php`, `Architect Java`, `Architecte .NET`); an architect naming no stack (`d'intérieur`, `réseau`, `cloud`) stays rejected, and the stated cost is that a new stack word must be added to both `stack` and `role_words`.
- [2026-09-14 13:56] NOTED: the architect rule is written for both word orders (`PHP Architect` too), because the ruling's discriminator is the stack word and "followed by" described the live title rather than scoping the order; the 93-row store holds no reversed title today.
- [2026-09-14 13:56] AGREED: `member of technical staff` is a role word, so `Senior Member of Technical Staff, Multimodal AI` (`linkedin:4272080636`) passes the gate.
- [2026-09-14 13:56] AGREED: `Low-Code Product Builder H/F` (`linkedin:4394433858`) is a correct title reject and is pinned as a must-reject case beside the architect counterweights.
- [2026-09-14 13:56] NOTED: the two pay-floor rejects of the same first pass were re-judged from their stored rows and are correct; nothing changes for them.
- [2026-09-23 15:37] AGREED: next job sources, in order — Free-Work saved-search alert, then Welcome to the Jungle alert, then a re-measure of the job score weights on the week of real rows; France Travail, company ATS boards, Apec, HelloWork and Indeed are deferred.
- [2026-09-23 15:37] NOTED: the job domain keys offers on each source's own id and has no cross-source matching, so the second source must ship with it (company + normalised title + commune), or an offer seen on two sources is pushed twice.
- [2026-09-23 21:59] AGREED: portal alerts are BROAD and scout does the sorting — four role families (dev, lead/management, architecture, DevOps/SRE), any stack, Île-de-France or remote, CDI + freelance, no salary filter; LinkedIn, Free-Work and WTTJ feed `job-watch/portails`, while HelloWork and Apec alerts are created now but routed to a separate parked label that no source reads, so real captures build up before their readers are built.
- [2026-09-23 21:59] AGREED: widen `role_words` (test-first) so the management and DevOps/SRE titles the new alerts bring are not rejected in silence — engineering manager, head/VP of engineering, directeur/responsable technique, devops, sre, platform engineer; a bare architect stays rejected as ruled 2026-09-14.
- [2026-09-23 22:39] NOTED: portal alerts set up in the developer's browser — LinkedIn 5 broad keyword alerts (4 Île-de-France families + 1 France remote; two older narrow ones kept), Free-Work 4 (Île-de-France, CDI + freelance), HelloWork 4 parked (`scout - Dev/Lead/Management/Architecture/DevOps/SRE IDF`, CDI + indépendant + freelance, 3 job titles each from HelloWork's own list — no boolean keywords there). WTTJ alerts are SUSPENDED site-wide ("your alerts are getting a makeover"), so WTTJ cannot be source #3 until they return; Apec 4 parked (`scout - Dev/Lead/Management/Architecture/DevOps/SRE IDF`, Île-de-France, no contract filter — Apec is mostly CDI, and its keyword field takes `OR`: `développeur OR devops` returned 1 963 against 1 259 and 1 020 alone), beside the developer's older `Ingénieur développement` search — Apec caps an account at FIVE searches, so all five slots are used. A first attempt at Apec failed with a per-domain permission error that turned out to be a transient extension glitch, not a restriction.
- [2026-09-24 09:57] AGREED: HelloWork and Apec alerts stay in `job-watch/portails` rather than a separate parked label (reversing that half of the 2026-09-23 21:59 ruling); no source claims them, so they stay unread there and build up as captures until their readers exist.
- [2026-09-24 09:57] NOTED: Free-Work's first alert arrived 2026-09-24 06:27 from `jobs@free-work.com` ("161 offres matchant avec vos critères"); `contact@free-work.com` sends a newsletter into the same label, and it is not an alert.
- [2026-09-24 10:40] NOTED: the cross-source key ruled necessary on 2026-09-23 (company + normalised title + commune) cannot be built for Free-Work — its cards state no company — and the measured overlap today is 0 of 36 distinct Free-Work titles against the 197 stored LinkedIn offers; Free-Work ships without cross-source matching, under that entry's own stated cost (an offer on both sources is pushed twice), recorded as a Known issue.
- [2026-09-24 10:40] NOTED: Free-Work's digest is read from its own text/plain part (Symfony-generated markdown), one `card_pattern` per card, NOT line-anchored — the first card of each of the four sections is glued to its section header, and a line-anchored reader found 36 of 40 cards; a bare `€` range is a day rate (the live offer page shows `400-550 €⁄j`), `NNk-NNk €` an annual salary, and the adapter only states that unit so `JobPay` stays the one reader of pay — a design choice (not a ruling).
- [2026-09-24 11:05] NOTED: step 13 deployed; the first live pass read 2 sources, and Free-Work pushed exactly the 4 offers the offline judging predicted at the gate of 40 (scores 60, 50, 48, 40), queuing 19 for the rollup.
- [2026-09-24 11:30] AGREED: next, in order — HelloWork and Apec readers, then the Alcopa car source, then a source research pass diffed against `var/claude/jobs/sources-research.md`.
- [2026-09-24 11:20] AGREED: the role gate accepts `d[eé]velop+eur` (Free-Work's `Dévelopeur` typo), test-first.
- [2026-09-24 11:20] AGREED: the developer has a Collective.work account; its mission alerts are set up in the developer's browser like the other job portals and routed into `job-watch/portails`.
- [2026-09-24 11:58] NOTED: the one-`p` typo fix landed as `2f38e48` (`developp?eu` in the role gate and in the commercial reject). CI is green, and `job-scout` was restarted so it reads the new criteria; no `criteria.local.json` override exists.
- [2026-09-24 13:48] NOTED: HelloWork and Apec readers built as two `email_digest` sources over the same `JobDigestEmailSource`, which gained `id_token_pattern` (the offer id is decoded from a base64url token — both portals put it nowhere else) and optional `company`/`place`/`pay` card groups (a `place` group is the location whole: `Suresnes - 92` through the facts splitter reads `92`). Offline `doctor`: hellowork 39 offres over four captures, apec 45 over one (n=1). Shipped criteria: 24+15 and 36+9 match/reject; under the deployed gate of 40 only ONE of the 60 matches pushes individually — the cards state no stack, work mode or date, and Apec no pay. The scrubber was fixed for both first (`ca377e0`).
- [2026-09-24 11:26] NOTED: four Collective.work alerts created in the developer's session, all daily, all Île-de-France, no contract or pay filter: `scout - Dev IDF` (search `Développeur`), `scout - Lead IDF` (`Tech Lead`), `scout - Architecte IDF` (`Architecte`), `scout - DevOps IDF` (`DevOps`). Collective.work sends them to the account's own address, so the Gmail filter that routes them into `job-watch/portails` is still the developer's to add. The source can't be built until the first real email arrives (n=0).

## Evidence gathered (2026-09-13)
- `Cli/Domains::all()` is the registry — a new domain is one entry plus `Scout\<Slug>\`, `config/<slug>/` and `<SLUG>_*` keys.
- The car domain is the template: 23 files, ~4 100 lines under `src/php/Car/` (CarScout 1 063, VehicleEmailSource 551, VehicleStore 367); generic core reused as-is (~7 000 lines: RunStore, health, Pacer, Heartbeat, IMAP, HTTP/Robots, Notify, Redact, PatternMissLog).
- Mailbox census: LinkedIn job alerts (`jobalerts-noreply@linkedin.com`) arrive every ~2 h in INBOX, text/plain part present, digest of cards separated by a dashed rule, each with title / company / location and a `linkedin.com/comm/jobs/view/<id>/` link — link identity is available. No salary in the plain part. The footer carries the subscriber's name and headline, and every link carries an `otpToken`: both must be scrubbed from any fixture.
- Free-Work sends a weekly newsletter of mixed-region offers (Île-de-France, Lille, Bouches-du-Rhône in one issue), not a saved-search alert.
- NO other portal currently sends a job alert. The latest mail from each was read, not assumed. Indeed (2026-08-10): a recruiter-share reminder. WTTJ (08-01): profile validation. APEC (08-27): marketing. Cadremploi (07-11): tracking-pixel notice. Glassdoor (07-08): Indeed merger notice. HelloWork (05-14): OTP code. The last real APEC alert was `offres@diffusion.apec.fr` on 2026-04-30: a profile-based recommendation, one card (title / `CDI` / `Montreuil - 93`), every link an opaque `neomarket.diffusion.apec.fr/r/` redirect carrying base64 parameters. So APEC offers NO link identity from the email alone. **REFUTED 2026-09-24 for the saved-search alert:** each link's `e` parameter is base64url of `p1=www.apec.fr&p2=<id>W…`, so the offer id IS in the email — decoded, not read off the URL.
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
     - The `PatternMissEscalationTest` scan dir and namespace land with the source (step 5).
     - `JOB_IMAP_MAILBOX` and `JOB_FEED_SILENT_DAYS` land with the CLI (step 6), their first reader.
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
   - Schema v1 records WHAT an offer was announced as, `ROLLUP < MATCH`, and a push is never demoted
     (the rent `notified_as` shape, two levels — this domain has no digest bin). Chosen at v1 because
     step 6 adds the rollup, and no deployment exists to migrate; the car store's missing kind column
     is the gap this avoids.
   - The id is trimmed by `Core\Whitespace::trim()`, moved out of the rent store so there is one
     implementation; the rent store forwards to it and its two ledger cases target the new file.
   - No pay history: no ruling makes a salary change an event. The rollup queue (`pendingRollup`,
     `counts`) lands with step 6, which first reads it.
5. **Sources.**
   - `JobEmailSource` with the **LinkedIn job alert** as source #1. It reads cards from the HTML
     part (see *Design choice* below). Identity is the `/jobs/view/<id>/` number, and `params.from`
     scopes the source. The fixtures are scrubbed before any test is written against them. The
     scrub replaces the subscriber's name and headline in both parts and strips every tracking
     parameter (`otpToken`, `midToken`, `trkEmail`, …). Two checks gate the output: `grep -ci` for the
     name and surname must be 0, and every decoded `otpToken` VALUE must be a `FIXTURE<n>` placeholder
     (the scrubber keeps parameter names, so `grep -c otpToken=` is never 0). Then `FixtureSecretsTest` runs, and
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
- Commute — this means moving `CommutePlanner` out of `Rent\Enrich` into `Core` first. A domain
  importing another domain's class already happens twice: the car and job CLIs both import
  `Rent\Core\DigestSchedule` and `Rent\Notify\Formatter` (step 6). That is recorded, not endorsed:
  the next shared import is the moment to lift all of them into `Core`.
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
  [Verified 2026-09-13 with `JobScorer` and the shipped criteria over the 67 distinct cards among
  the 115 (`var/claude/jobs/trial-scorer.log`):
  - 65 match and 2 reject. Both rejects are stated salaries under the floor, 45k and 55k (H1,
    correct by ruling). No other disqualifier fires.
  - Scores run 6 / 21 / 51 (min / median / max). Histogram: 0–9 ×4, 10–19 ×27, 20–29 ×23,
    30–39 ×4, 40–49 ×5, 50–59 ×2.
  - The best card is 51, which is under the predicted 65, because no pay-carrying card also names
    a back stack. [Verified 2026-09-13: the two pay-carrying matches score 38 and 43, and a
    back-stack read of each title returns nothing.]]
  The GREEN, RED and conditions vocabularies CANNOT be calibrated on cards. On title-only text one
  label fires across all 67 (`craft:modernisation`, once), RED fires on none and conditions on none.
  Whether the broad terms (`migrations?`, `support n1-3`) fire on most real descriptions stays
  UNMEASURED until a source supplies descriptions. Measure it before `push_min_score` is set.

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
  - **Remote days are a WEEKLY figure only.** `remoteDaysShare` has keys 0–5 only, and the index into
    it is bounded by construction. The day word is `[1-5]` or `une`–`cinq`, the on-site form reads
    `5 − n`, and a card field keeps only `0 < d < 5`. [Verified: pattern read, plus a probe where
    `10 jours … par mois` reads no figure.] A per-month figure under 6 was NOT bounded in meaning:
    `2 jours de télétravail par mois` read as 2 a week and scored a near-on-site post as hybrid,
    under a correct-looking label. A figure followed by a month unit now reads as no figure. Four
    provider cases pin it, and removing the guard reddens three of them.

### Step 6 design — pipeline, formatter, CLI (2026-09-13, pre-work check)
This is an implementation choice made under the "go" ruling, checked by `advisor()` in two rounds, not
a new ruling.
- **The car shape, minus what this domain lacks.** `JobPipeline` keeps every car rule:
  - a throwing source is one failed source, never an empty pass;
  - each offer is recorded at its own `observedAt`;
  - `--seed` marks everything announced without pushing;
  - messages are acknowledged after the store records the pass;
  - health alerts fire once per cooldown, with one recovery notice;
  - the same-filter warning is counted per source.
  There are no price drops and no sitemap branch.
- **What an offer was announced as decides the push.** The push check is `wasNotifiedAs(MATCH)`, not
  `wasNotified`. An offer below `push_min_score` stays queued, and counts as held back only while no
  announcement covers it. A delivered push is marked `MATCH`, the rollup marks `ROLLUP`, and a retry
  out of the queue marks `MATCH`.
- **`notify` is required in `criteria.json`** — channels, `push_min_score` (absent), `rollup_hour`,
  `source_alert_cooldown_hours`. There is no `high_priority_score`: `JobFormatter::match()` is always
  NORMAL.
- **`JobStore` gains the queue** — `pendingRollup()`, `pendingRollupCount()`, `counts()` — the car
  queries, plus `company` for the headline.
- **`JobScout` mirrors `CarScout` verb for verb**: `doctor`, `dump`, `run --once/--seed/--watch`,
  `--source=`, `test-notify`, `rollup [--dry-run]`.
  - Kept: the Q36 refusal; the Q27 refusal note, cleared only on delivery, with a forced beat under
    `--once`; the Q37 pacer with the beat and the rollup floor in `finally`; `SCOUT_MAX_PASSES`; the
    one `remainingAfterDrain()`.
  - Its own keys: `JOB_SCOUT_DB`, `JOB_IMAP_MAILBOX` (default `job-watch/portails`), `JOB_NTFY_TOPIC`,
    `JOB_HEARTBEAT_HOURS`, `JOB_FEED_SILENT_DAYS`. Its markers: `job-heartbeat.txt`, `job-rollup.txt`,
    `job-last-refusal.txt`. The IMAP cap is the shared `ImapMailbox::maxMessages()`.
- **The drain re-judges each snapshot.** A REJECT today stays queued with a warning. At or over the
  gate, or with no gate, the offer is re-pushed as a match; otherwise it joins the rollup. A snapshot
  that will not decode is announced from the stored columns and its stored score, with a warning.
- **`dump` lists every `JobListing` property by reflection**, so a field added to the model cannot
  vanish from it.

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
| 4 | Store: JobStore composing RunStore | M | done | dd705cb | src/php/Job/** tests/php/Job/** src/php/Core/Whitespace.php src/php/Rent/Store/Store.php tests/sabotage-check.sh tools/backup-state.sh tests/test-backup-state.sh .env.example |
| 5 | LinkedIn source: scrubbed fixtures, JobEmailSource | L | done | a482832 | src/php/Job/** config/job/sources.json tests/fixtures/job/** tests/php/Job/** tests/php/Core/PatternMissEscalationTest.php tests/php/Repo/FixtureSecretsTest.php tests/php/Repo/PortablePatternsTest.php |
| 6 | Pipeline, formatter, JobScout CLI | L | done | 7450db2 | src/php/Job/** tests/php/Job/** config/job/criteria.json .env.example tests/php/Repo/AcknowledgeCallSitesTest.php |
| 7 | Sabotage ledger cases | M | done | 53c7969 | tests/sabotage-check.sh tests/php/Job/Cli/JobScoutTest.php |
| 8 | Docs | M | done | 9eee7fa | CLAUDE.md README.md docs/** |
| 9 | Deploy + first live pass | M | done | c5be0df | compose.yaml Dockerfile CLAUDE.md README.md docs/** .env.example tools/verify-deploy.sh |
| 10 | Title gate: stack architect + member of technical staff (live over-rejection) | S | done | aeb40c2 | config/job/criteria.json tests/php/Job/JobCriteriaTest.php docs/plans/job-domain.plan.md |
| 11 | Title gate: engineering management + DevOps/SRE (broad alerts, ruled 2026-09-23) | S | done | c102458 | config/job/criteria.json tests/php/Job/JobCriteriaTest.php docs/plans/job-domain.plan.md |
| 12 | JobScoutTest runs on a shipped-config temp root, never the checkout's gitignored criteria.local.json | S | done | 2e3d632 | tests/php/Job/Cli/JobScoutTest.php |
| 13 | Free-Work source: `email_digest` adapter over the text part, scrubbed fixtures, Mailjet scrub rule | L | done | 85c899f | src/php/Job/** config/job/sources.json tests/fixtures/job/freework/** tests/php/Job/** tests/php/Repo/FixtureSecretsTest.php tools/scrub-eml.php tests/test-scrub-eml.sh tests/sabotage-check.sh CLAUDE.md README.md docs/** .claude/skills/add-source/SKILL.md |
<!-- /progress-block -->
### Blocked
- WTTJ as a source: its saved-search alerts are suspended site-wide (checked 2026-09-23); re-check `welcometothejungle.com/fr/me/alerts`.
### Needs input
- Gmail: add the Free-Work sender to the `job-watch/portails` filter once its first alert shows the address; a separate parked label + filter for HelloWork and Apec.
- Slice 2: job-alert subscriptions on WTTJ, APEC, HelloWork, Free-Work and Indeed, into `job-watch/portails`.
### Needs research
- **Collective.work** (developer, 2026-09-24): absent from `var/claude/jobs/sources-research.md` and this plan. Measured 2026-09-24: `robots.txt` disallows only `/style-guide`; the sitemap lists `blog`, `solution`, `etude-de-cas`, `produits`, `pricing`, `talents` and NO mission or offer URL, and its blog titles address recruiters (posting to many job boards, programmatic job ads) — so it reads as a recruiter-side product with no public board [Inferred]. The route, if any, is an account's mission-alert email (hard rule 4, the AL'in shape): the developer has an account, and four daily alerts were created 2026-09-24 (Decisions Log). What stays open is the email's shape; nothing has arrived yet.
- Keyword list widening (Java/Spring, Vue.js, …) — seeded in step 3 from `var/claude/jobs/criteria-research.md`.
### Fragile
- `JobScout::watch()` runs the rollup floor TWICE per start: before the first pass, and in each pass's
  `finally`. The step-6 mutation run (2026-09-14) showed a test named for the startup floor passing
  with that floor deleted, because the in-loop floor drains the same queue one pass later. It now
  asserts ORDER (the floor's line before the pass report) and that the pass holds nothing back again.
  Any future floor test must assert where the drain ran, never only that it ran.
- `JobScorer::instant()` accepts exactly `Y-m-d\TH:i:s` plus `Z` or an offset: no fractional seconds, no
  bare date. LinkedIn cards carry no date, so nothing reaches this path yet. The first source that
  writes `publishedAt` (step 5 or slice 2) must write that shape, and must add a test that its value
  round-trips — otherwise freshness reads unknown on every offer, in silence.
  Step 5 writes no `publishedAt` (LinkedIn cards carry no date), so this stays open for slice 2.
- `JobEmailSource` starts a LinkedIn card at its LOGO link, which precedes the title. If LinkedIn drops
  that link, each title lands in the card above and every place line stops matching: `place_pattern`
  misses on 100 % of cards and `health()` WARNs. Loud, not silent — pinned by the escalation tests.
- `JobText::surface` turns `_` into a space on EVERY surface the classifier and criteria read, not
  only the role gate. No pattern depends on `_` today; S14 and S15 pin both directions.
- The `job:` ledger cases are a line-by-line TRANSLATION of the step 3–6 mutation runs: each
  multi-line mutation became one `s` per changed line, blanked or folded so the line count never
  moves, and addressed from the nearest unique line above when a line repeats. That translation was
  measured once (changed-line count, resulting file, `php -l`, the named test red). A later refactor
  that keeps a guarded line matching but moves what sits around it can make a case land on the right
  pattern and cut the wrong thing — `tests/test-sabotage-applies.sh` sees only INERT, not a
  mis-landing. After reshaping a file under `src/php/Job/`, re-run `SABOTAGE_FILTER='^job:'` and read
  WHICH test goes red, not only that one does.
- **Free-Work is n=1** (step 13): the card separator, the facts grammar (`[N mois - ][NNk-NNk € - ][NNN-NNN € - ]place`),
  the glued first card and the two pay units are measured on ONE digest. The next digest is the first
  regression test; freeze it as `02.eml` (append, never renumber) and re-run `FreeWorkFixtureTest`.
### Known issues
- ~~Step 8 owed the drain cost below, the three-domain corrections, the LinkedIn register row and the
  `Core\Whitespace` and `php --ini` lines~~ — landed in `9eee7fa`. The drain cost itself STANDS; it is
  now stated in CLAUDE.md beside the car one. Kept below for the record:
- Stated cost of the drain: when a queued offer's snapshot will not
  decode, `JobScout::collectRollup()` skips the re-judge and announces the offer from its stored
  columns. If the stored score clears the gate, the offer is pushed again as a match. So an offer
  that today's criteria would REJECT (a new title reject term, a higher `salary_floor_eur`) can still be
  pushed. The shipped config has no `push_min_score`, so there every such offer is pushed. CLAUDE.md records the same cost for `CarScout::collectRollup()`. The job domain has no §1,
  so this pushes an offer the user does not want. It never pushes an offer the user cannot take.
- A LinkedIn message with no HTML part counts a miss on both `card_link_pattern` and `footer_marker`, and
  escalates only if every claimed message in the pass does — one such message among normal ones stays
  silent (the partial-miss gap). Documented in `docs/SOURCES-LIVE.md` by step 8; still untested.
- ~~`.claude/skills/add-source/SKILL.md` is scoped to the RENT domain and says nothing about the job
  domain~~ — extended in step 13 with the two job types, their params, and the two things Free-Work
  taught (a repeat inside a digest may be the template; check whether the portal states a company).
- **No cross-source matching, and Free-Work cannot have the ruled key** (step 13): its cards state no
  company, so company + title + commune cannot be built. An offer on both LinkedIn and Free-Work is
  pushed twice. Measured overlap on the first capture: 0 of 36. Revisit when a third source that
  states a company arrives, or if duplicate pushes are observed.
- Monthly pay shapes `JobPay` does not read, measured 2026-09-13 by probe: the unit BEFORE the figure
  (`Salaire mensuel : 4 500 €`, `Rémunération mensuelle brute de 4 500 €`), a leading currency sign
  (`€4,000 - €5,000 per month`), and a stated 13th month on a monthly figure (`… / mois sur 13 mois`,
  `x 13`), which is read but scored on ×12. All fail safe: an unread figure never rejects, and the
  13th month under-scores by 1/13. LinkedIn sent 0 monthly figures in 20 captures (8 € lines, all
  annual), so these are fixed against real slice 2 alerts (HelloWork, Indeed, APEC), never guessed.
- The CAR store still checks an id with `trim()` (`VehicleStore::dedupKey()`/`record()`), so an id of
  one no-break space passes and collapses a pass's cars onto one key. Found while building the job
  store, which uses `Core\Whitespace::trim()`. Not fixed in step 4: it changes a deployed domain. Reach
  is unmeasured — a content hash cannot be blank, but the Agorastore `ref` group is itself `trim()`med,
  and the link-basename and sitemap ids were not checked for a whitespace-only value.
- An empty `green` map scores the green component 0 for every offer, silently lowering the ceiling to
  85. The car scorer awards the share when no preference is configured, so an absolute threshold does
  not move. Step 6 shipped no `push_min_score`, so it was not settled there; settle it when the gate is
  calibrated from real rows: award the share when no group is configured. **The gate WAS calibrated
  on 2026-09-14 — at 40, on live scores whose green component is 0 for every offer.** Awarding the
  share lifts every score by 15 — measured 2026-09-14 over the 88 stored matches, 31 would clear 40
  instead of 12, about 2.6 times as many individual pushes — so fixing green must re-rule
  `push_min_score` in the same step.
- RESOLVED (step 10, 2026-09-14): the title filter rejected the live LinkedIn offer `Architecte Php`
  (`linkedin:4465457633`) as `intitulé hors métier`, silently, since a reject is logged only. Two role
  words fix it — an architect beside a scored stack word, either order, and `member of technical
  staff` — and `JobCriteriaTest` pins the must-pass titles beside the must-reject counterweights
  (`Architecte d'intérieur`, `réseau`, `cloud`, `DPLG`, `Low-Code Product Builder H/F`). Over all 93
  stored titles the gate changes for exactly two, and none is lost. **Stated cost:** the seed marked
  every stored row as notified (`notified_as = MATCH`), so the two admitted offers are re-judged as
  matches on the next pass and never pushed; only offers first seen after the restart benefit.
- `JobScoutTest` reads `config/job/` from the repo root, and so reads the gitignored
  `criteria.local.json` too. On the deploy machine, whose override sets `push_min_score: 40`, three of
  its no-gate tests go red (1 push against 15). CI and a clean clone have no override and stay green.
  Measured 2026-09-14 in a clean worktree: 29/29 without the override, the same 3 failures with it
  copied in. It is a test-isolation gap, not a product defect: the tests should copy the shipped
  config into their temp root, the way the gate tests already do. OPEN.
