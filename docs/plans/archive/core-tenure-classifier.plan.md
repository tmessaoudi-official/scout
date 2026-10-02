> SUPERSEDED (2026-08-31) by docs/plans/scout-unified-execution.plan.md. Kept for its
> Decisions Log and measurements; do not execute from this file.

# core/tenure + core/models Plan

The first application code in this repo. Builds the **pure core** — the part that needs nothing from
phorj, no mailbox, no endpoint and no secret — while the phorj team analyses
[`docs/PHORJ-REQUIREMENTS.md`](../PHORJ-REQUIREMENTS.md).

Scope: `models` (the value objects the whole pipeline passes around) and `tenure` (THE classifier,
spec §4, and the module that carries `CLAUDE.md` §1). Nothing else — no adapters, no criteria, no
store, no notify.

---

## Decisions Log

- [2026-08-06 22:30] AGREED: **Start the pure core without waiting for phorj.** Developer: *"let's
  start without phorj and then we will do it when it's ready"*. The classifier is pure text→verdict,
  so it needs none of phorj's three missing modules (`Core.Imap`, an HTML parser, `sleep`).
- [2026-08-06 22:32] AGREED: **PHP 8.5, latest of everything.** Developer: *"use php 8.5"* / *"latest
  of everything"*. Installed PHP 8.5.9 from the ondrej PPA and made it the default `php`; Composer
  self-updated to 2.10.2. PHPUnit pulled at its latest release.
- [2026-08-06 22:35] AGREED: **Two-language layout is `src/<lang>/`.** The spec's tree says
  `src/core/`, written when the project was single-language. The developer has since ruled *"do it in
  both phorj and php so i can test phorj lift and transpile"*, so the tree becomes `src/php/Core/` and
  later `src/phorj/core/`. `spec/PROJECT_BRIEF.md` §3's tree is amended by this, not violated.
- [2026-08-06 22:35] AGREED: **The classifier corpus is language-neutral JSON**, at
  `tests/fixtures/tenure/corpus.json`, read by BOTH implementations. That single shared file is what
  makes the phorj-vs-PHP differential test meaningful — if the two implementations disagree on one
  fixture, the corpus says which is wrong. JSON rather than YAML because `Core.Json` is a confirmed
  **default** phorj feature and no YAML module is confirmed.
- [2026-08-06 22:36] AGREED: **Confidence is computed in integer basis points (0–100), exposed as a
  float.** Float arithmetic is not guaranteed bit-identical across two language runtimes; integer
  arithmetic is. Since the whole point of the dual implementation is byte-identical differential
  testing, the internal representation must be exact. `confidence()` divides by 100 only at the
  boundary.
- [2026-08-06 22:38] AGREED: **`mixedTenure` defaults to `true`.** A source added without declaring
  itself must be treated as capable of carrying social stock, so the fail-closed rule engages. The
  opposite default would let a config omission silently disable the §1 protection.
- [2026-08-06 22:40] AGREED: **PLS is in the excluded set in code**, per the Q4 answer of 2026-08-06.
  `CLAUDE.md`'s glossary and `spec/PROJECT_BRIEF.md` §2 still say `OPEN — Q4` / `ASK USER (Q4)`; both
  are stale and are corrected in this change.
- [2026-08-06 22:41] AGREED: **The corpus ships as `synthetic` and says so in the data.** The spec asks
  for 30 *real* listing texts. Real texts cannot be captured until an endpoint or a browser session
  exists (blocked on U3). Every fixture therefore carries a `provenance` field, and the suite asserts
  the corpus knows how many of its own entries are synthetic — so the gap is visible as data, not as a
  comment nobody reads.
- [2026-08-07 07:50] AGREED: **`PLS` moves from the collocation-guarded table into plain `LABELS`.**
  No French word is spelled `pls`, so the guard's closed noun list could only ever fail open on it:
  `Appartements PLS disponibles`, `programme agréé PLS` and `financé en PLS` all produced no signal
  and no doubt, and on a pure source such a listing was notified at confidence 50 with `reasons[]`
  reading *"aucun signal dans l'annonce"*. Only `PLUS` is genuinely ambiguous.
- [2026-08-07 07:50] AGREED: **A structured tenure field is read by the SHAPE of its value, never by
  its case.** Case is a feed's house style: `financement: plus` went silent on an explicitly social
  code, while `PINEL PLUS` — a real 2023 scheme, routinely shouted in a `categorie` — became a silent
  REJECT. A value made only of acronyms and short uppercase LETTER-ONLY fragments is a code list;
  anything else is prose. Three earlier answers are recorded in the method's docblock as wrong.
- [2026-08-07 07:50] AGREED: **The tier-1 field exemption on the `conventionné` rule is removed
  outright**, and `regress-003` is relabelled `UNKNOWN`/`DIGEST`. Two attempts at bounding it both
  leaked, and every leak was toward notifying. A field is not adjacent to anything in the text, and
  adjacency is the whole content of that exception.
- [2026-08-07 07:58] AGREED: **`Text::fold()` lowercases byte-wise, and the byte-offset invariant
  between the two folded surfaces is load-bearing.** Label positions come from `fold()` and the
  ambiguous acronym's from `foldPreserveCase()`; the resolver compares them directly and the
  adjacency rule measures a span across them. `mb_strtolower` broke that for 27 codepoints. This is a
  contract the phorj port must reproduce — see `docs/PHORJ-REQUIREMENTS.md`.
- [2026-08-07 07:58] AGREED: **Unreachable safety code is deleted, not kept as decoration** — the
  `conventionné` direction guard, proved unreachable by sabotage. The property it expressed is
  asserted by a test instead. Distinguish this from code that is unreachable only because a table
  currently has one entry (`isCodeList()`'s `$sawAcronym`), which is kept and labelled.
- [2026-08-07 09:20] AGREED: **In a structured tenure field, an acronym the collocation guard cannot
  place is a DOUBT, never silence.** Review round 5 found the §1 breach this closes: `financement:
  "Prêt PLUS"` on In'li reached MATCH at confidence 50, because the guard's noun list is closed and
  `prêt` is not in it — the same closed-list failure that moved `pls` out of the guard one commit
  earlier, left in place for `PLUS` on the strongest rung of the ladder. Enumerating the missing
  nouns was rejected: the review listed 66. Inside a tenure field the field NAME is already the
  collocation, so the floor is the third answer. The cost is that `Pinel Plus` and `T3 PLUS` now
  digest instead of matching — one glance each, against an application for the alternative.
- [2026-08-07 09:20] AGREED: **A newline survives folding, because it is the title/description
  boundary.** `RawListing::text()` joins with one, and collapsing it to an ordinary space let a
  title's `Logement intermédiaire` excuse a description's opening `Conventionné` — while a mere comma
  correctly blocked the same exception. Note the second half, which is easy to miss: PCRE's `$`
  matches before a final newline, so narrowing the adjacency character class changed nothing until
  the anchor became `\z`.
- [2026-08-07 07:58] AGREED: **Every value object in `src/php/Core/` is `final readonly`**, and the
  test that pins it sweeps the namespace by reflection rather than naming one class. `TenureSignal`
  lost the keyword when `$length` gained a computed default, silently making the §1 evidence trail
  writable; the fix was pinned by a test naming `TenureSignal` alone, while the argument for it was
  about `Classification`. Round 6 showed all five other core classes could lose it with the suite
  green. Recorded here on 2026-08-07 after round 6 pointed out it was the one ruling of that batch
  living only in commit prose.
- [2026-08-07 11:40] AGREED: **The prose surface gets the same doubt floor as the fields, but
  CASE-SENSITIVELY.** Round 5's floor was scoped to structured fields, so `Logements financés en
  PLUS` still MATCHED at confidence 50 on a pure source while the identical sentence with `PLS`
  rejected. The COLLOCATION noun list is exactly as closed in prose as in a field. Case is the one
  thing that differs between the surfaces and it is kept: in a field capitalisation is the feed's
  house style, in prose it is evidence, and a case-insensitive floor would digest most of the Paris
  market on `plus de 3 chambres`. Cost: `trap-005b` (`PLUS UN BUREAU`, shouted) relabelled to DIGEST;
  `trap-010` is its lowercase sibling and stops the rule being widened.
- [2026-08-07 11:40] AGREED: **A newline is a phrase boundary in ALL FOUR rules that consume
  whitespace adjacency, not just the one round 5 updated.** The ruling was made and applied to the
  `conventionné` adjacency rule alone; the phrase-end test, the comparative escape and
  `isPrecededBySans()` still read it as ordinary space. The comparative one deleted determinate
  labels: the FIRST WORD OF THE DESCRIPTION decided whether a shouted `LOGEMENT PLUS` title was a
  rejection or a notification. A newline now ENDS a financing phrase, and neither the comparative
  escape nor the `sans` negation may cross one.
- [2026-08-07 11:40] AGREED: **Multi-word literals may be assembled across a phrase boundary only
  when the tenure is EXCLUDED.** `Text::inflectedTokenPosition()` joins words with `\s*` so a
  line-wrapped phrase still matches — necessary for `logement social` in a `text/plain` alert body,
  which hard rule 4 makes the primary ingestion path, and wrong for an eligible one, where it
  manufactures eligibility from two unrelated fragments (`T3 Cergy sans` + `Commission
  d'attribution…` assembled into the intermediate tell). Asymmetric exactly like the conflict rule.
- [2026-08-07 11:40] AGREED: **Procedural tells are evaluated over structured field values, and an
  excluded label in an UNRECOGNISED field raises a doubt.** Both were closed-list failures of the
  same shape the COLLOCATION nouns produced twice: `proceduralSignals()` read only
  `RawListing::text()`, so `dispositif: "commission d'attribution"` was invisible; and
  `TENURE_FIELDS` is exact-match, so `typeFinancement` rejected a `PLAI` at 97 while
  `financementType` notified at 50. The unknown-field case is a DOUBT rather than the tenure itself,
  because such a field may be prose and `commentaire: "pas de PLAI ici"` must not become a silent
  REJECT.
- [2026-08-07 11:40] AGREED: **A §1 tripwire suppression is judged per line or per matched span,
  never over the whole write — and pattern 2 has none at all.** All three suppressions added in
  round 5 were whole-blob, because the hook flattens newlines before grepping: an exempting docblock
  silenced a real breach three lines below it, and one occurrence of the word `source` anywhere
  disarmed the excluded-set pattern entirely. The hook now carries both a flattened and a
  line-preserving view. Pattern 2's non-tenure suppression was removed rather than narrowed: no
  proximity window separates `communes:` before a match from `source` in a docblock just as close,
  so it fires on a communes block and that noise is asserted as the accepted cost.
- [2026-08-07 11:40] AGREED: **`reasons[]` may never contain a raw newline.** It is the product's
  only user-facing output and it quotes the text that actually matched; once folding preserved
  newlines, a label straddling the title/description join rendered as two lines on a phone. Fixed at
  the formatting site (`oneLine()`), not in the fold — the newline is load-bearing for matching and
  inert for rendering. Recorded 2026-08-07 after round 7 found it living only in commit prose.
- [2026-08-07 11:40] AGREED: **The runner config is a §1 surface.** `phpunit.xml` is inside the
  tripwire's path filter, because one `<exclude>` or `<file>` line there drops the whole corpus
  suite with every other automated control still reporting green — measured at 193 tests → 90, exit
  0, drift-scan clean. A `bootstrap=` retarget is deliberately NOT covered: it appears in every
  valid config and a grep cannot tell the real one from a stub.
- [2026-08-07 14:10] AGREED: **The surface matrix is a TEST, not a discipline.** Seven consecutive
  review rounds each found a P0, and every one was the same shape — a correct rule applied to a
  subset of the surfaces it belongs on. A per-fixture corpus cannot find those: it covers the cells
  someone thought to write. `tests/php/Core/SurfaceMatrixTest.php` takes the cross product of the
  classifier's own excluded vocabulary, read out of its three tables by reflection, and every
  surface a listing presents, on the worst-case source. An empty cell is now a failing test. It
  found 45 on its first run, in two surfaces nothing had ever read: field NAMES and non-scalar
  values.
- [2026-08-07 14:10] AGREED: **The suite needs a counterweight against over-rejection.** Every
  corpus-level invariant asserted only that MATCH is not reached, so nothing could notice a
  classifier that simply stopped matching — and across three commits the corpus went MATCH 33.3% →
  30.1% → 28.0%, with every relabel running MATCH→DIGEST and none ever the other way. A
  one-directional suite makes moving a fixture to DIGEST the cheapest way to pass any change.
  `testAnOrdinaryEligibleListingStillMatchesOnEverySurface()` is the assertion that points the
  other way. CORRECTION (round 8): the commit that added it credited it with catching the `$sep`
  over-rejection. It did not — `regress-042` did. It is load-bearing for BLANKET over-rejection
  (forcing every route to DIGEST fails it on every surface) and blind to the one-rule-over-reaching
  kind, which is the shape that has actually occurred. Its payload is one benign string; widening
  that is open work, not a solved problem.
  **Widened 2026-08-29** — `SurfaceMatrixTest::testACapturedEligibleTextStillMatchesOnEveryProseSurface`
  crosses every CAPTURED corpus case whose expected verdict is MATCH (real listing copy carrying
  `au plus près`, `plusieurs`, `bailleur social`, `En savoir plus →` as ordinary French) with the four
  PROSE surfaces, read from the corpus so a new capture widens it unasked. Prose surfaces only, by
  design: a structured field reads with the identifier discipline on purpose. **What it detects was
  MEASURED, not assumed, and a first draft of this note got it wrong** (it claimed the collocation
  cut turned the cells red before the run came back green). Seven of the ledger's prose-rule cuts
  were applied one at a time in scratch copies: six leave all twenty cells green — they are held by
  other tests — and ONE turns four cells red, *the prose doubt floor goes case-INsensitive*, the cut
  that digests the adverb `plus` in real copy (`au plus près`, `En savoir plus`). So the net this
  adds is the adverb class on captured text, on every prose surface; it is breadth beside the
  ledger, not a replacement for it.

  The metric itself, measured at each commit in the sequence — this is the durable record, because
  commit `457446e` states the last figure as 29.6% and that is wrong:

  | commit | MATCH | cases | share |
  |---|---|---|---|
  | `906dfcc` | 30 | 90 | 33.3% |
  | `c1cf5c3` | 28 | 93 | 30.1% |
  | `9c40b8c` | 28 | 100 | 28.0% |
  | `021d60b` | 30 | 105 | 28.6% |
  | `457446e` | 31 | 108 | **28.7%** |

  Arrested rather than reversed: absolute MATCH has risen 28 → 31 across the last three commits and
  the share is flat, which is what "no longer sliding" looks like. No relabels since `9c40b8c`.
- [2026-08-07 14:10] AGREED: **Incidental surfaces are folded TOLERANTLY, tenure-bearing ones
  strictly.** Reading every field ran `Text::fold()`'s entity gate on URLs and surface cells, so one
  `&amp;` in an href — ordinary scrape output — digested the whole listing and even softened a
  determinate REJECT. `Text::foldTolerant()` substitutes a space for an entity or a bad byte: the
  one repair that can neither invent a match nor hide one, since every literal is matched with `\s*`
  between its words. The strict gate stays on the title, the description and declared tenure fields.
- [2026-08-07 14:10] AGREED: **Each procedural surface is scanned separately, never concatenated.**
  Appending field values after a newline let a literal be assembled from two unrelated surfaces —
  a description ending `aucune commission` and a field opening `Attribution directe` became the
  social `commission attribution` and hard-REJECTED, silently. Scanning per surface removes the
  class rather than choosing a better separator.
- [2026-08-07 17:30] AGREED: **Incidental surfaces are DECODED, not space-substituted and not
  refused.** Round 7's tolerant fold replaced each entity with a space, and its docblock claimed that
  could neither hide nor invent a match "since every literal is matched with `\s*` between its
  words". `\s*` joins the WORDS of a literal, not characters within one: `PL&shy;AI` folded to
  `pl ai` and was NOTIFIED, and `plai&shy;sir` folded to `plai sir` and invented a token inside
  *plaisir*. All three reviewers reproduced the first independently. Decoding has neither failure
  mode because the existing machinery handles the result — the decoded soft hyphen is stripped by
  `INVISIBLE`, `&nbsp;` collapses, `&#39;` normalises. The strict fold still guards the title, the
  description and declared tenure fields.
- [2026-08-07 17:30] AGREED: **Unreadable is a distinguishable answer, not an empty string.** The
  first tolerant fold returned `''` on failure and both callers read `''` as "said nothing", so an
  unreadable field became a silent one — hard rule 3 in its literal form. `null` now means
  unreadable and the caller raises a doubt.
- [2026-08-07 17:30] AGREED: **The listing's `url`, `commune`, `postcode` and `externalId` are
  scanned surfaces.** No rule read any of them and all 21 excluded literals reached MATCH through
  each; `/logement-social/plai/t3-cergy` is the ordinary slug shape of a landlord portal. A DOUBT
  rather than a verdict, because a slug is not a declaration. The corpus reader now gives fixtures an
  OPAQUE `externalId` (`ANN-2024-000017`) — feeding it the descriptive fixture id made a dozen
  fixtures fail on their own names, which is a property of the test data and of no real listing.
- [2026-08-07 17:30] AGREED: **Identifier spellings need two passes, and the vocabulary is
  everything NOT ELIGIBLE.** `demandeLogementSocial` and `numeroUnique` — the two keys the
  field-name rule was written for — matched NEITHER, because folding leaves the connectives out and
  the scan filtered on `isExcluded()` while `numero unique` maps to UNKNOWN. Splitting at case and
  separator transitions covers `typePlai`; separator-free containment covers
  `demandelogementsocial`; the second is restricted to multi-word literals because a bare `plai`
  compared as a substring matches inside *plaisir*.
- [2026-08-07 17:30] AGREED: **A test derived from the same mental model as the code inherits its
  blind spots.** The surface matrix shipped with the same `isExcluded()` filter and the same
  hand-written surface list as the code it polices, so it reproduced both gaps faithfully — its
  field-name cell was fed a JSON key containing spaces and passed while the two keys that rule was
  written for still matched. The matrix now derives its surface coverage from `RawListing`'s own
  constructor by reflection, and asserts that coverage in a test of its own.

---

## The design

### Why the classifier is not just a keyword match

Three things make this module harder than it looks, and each has fixtures:

1. **`PLUS` is an extremely common French word.** *"plus de 3 chambres"*, *"au plus tard"*, *"plus
   lumineux"*. A naive `str_contains($text, 'plus')` classifies most of the Paris rental market as
   social housing. `PLAI` is worse in a different direction: as a bare substring it matches
   *plaisant*, *plaine*, *plaisir*. Every acronym is therefore matched **word-boundaried**, and the
   ONE genuinely ambiguous one — `PLUS` — is additionally held behind a financing collocation,
   tested on the SAME occurrence rather than anywhere in the document. What follows that occurrence
   then decides between three answers, not two: a phrase-ending token means the label, a known
   comparative means the adverb, and **anything else means indécidable** and digests. See the
   round-2 section below for why a two-answer version could not be made correct.

   This paragraph named `PLS` alongside `PLUS` and required an UPPERCASE spelling, and review round 5
   caught both as stale. `PLS` moved into the plain label table in round 4: no French word is spelled
   `pls`, so the guard's closed noun list could only ever fail open on it — *"Appartements PLS
   disponibles"* was notified at confidence 50. And case stopped being the discriminator for
   structured fields in the same round; `financement: plus` is a determinate `PLUS` whatever its
   case, because a feed's capitalisation is a house style, not evidence.

2. **Signal priority is a ladder, not a vote.** The highest tier that fires decides the tenure. Lower
   tiers may only adjust confidence. That is `CLAUDE.md`'s *"a lower-priority signal must never
   override a higher one"*, implemented rather than restated.

3. **The ladder alone is not fail-closed enough.** If a structured field says `LLI` and the body text
   says `PLAI`, the ladder keeps `LLI` — and a 0.97 confidence sails past the 0.6 floor into a match.
   So there is a **conflict rule** on top of the ladder: an eligible verdict contradicted by any
   excluded-tenure signal collapses to `UNKNOWN`, and `UNKNOWN` never matches. It does not assert the
   listing is social; it withholds. The reverse is deliberately **not** symmetric — an excluded
   verdict contradicted by an eligible signal stays excluded. Softening an exclusion is the one
   direction that costs the user a wasted application.

### Signal ladder and confidence

| Tier | Signal | Base confidence |
|---|---|---|
| 1 | Explicit structured field (`financement`, `typeProduit`, `categorie`, …) | 97 |
| 2 | Explicit label in text (`logement intermédiaire`, `LLI`, `PLAI`, `logement social`, …) | 90 |
| 3 | Procedural tell (`SNE`, `numéro unique **d'enregistrement**`, `commission d'attribution` ⇒ social) | 80 |
| 3d | Procedural tell that is only *probably* one — bare `numéro unique` — ⇒ **doubt, not a verdict** | — |
| 4 | Plafonds de ressources band | 70 — **ships with no band data**, see below |
| 5 | Source default | **50** |

Row **3d** was added on 2026-08-06 after review round 3 and this table went four commits without it.
`numéro unique` on its own is overwhelmingly the SNE in this domain, but it is also ordinary CRM
boilerplate — and as a determinate tier-3 SOCIAL signal it cleared the floor unaided, making that one
phrase a hard, silent reject. Nothing arrives, and nothing arriving is indistinguishable from a quiet
market (`CLAUDE.md` hard rule 8). The discriminating form, *with* `d'enregistrement`, stays
determinate. See `regress-024` and `social-002`.

Tier 5 sits **below the 0.6 floor on purpose**. A mixed source that emits a listing with no tenure
signal at all lands in the digest, never in a notification. That is `CLAUDE.md`'s *"an absent signal
must lower confidence, never silently inherit `default_tenure` at full confidence"* made structural.

Corroboration from a lower tier: **+3** each, capped at 99. Contradiction: **−15**, floored at 10.

Tier 4 is **wired but empty**. Real 2026 plafond figures per zone and household size are not in this
repo and must not be written from memory (`CLAUDE.md` hard rule 1). The tier exists in the ladder, the
band table is injectable, and it ships with zero bands — so it produces no signal until real figures
are sourced. A test asserts exactly that. Invented numbers would be worse than an honest gap.

> **SUPERSEDED 2026-08-26 — tier 4 is ARMED.** The figures were fetched from two dated official
> publications and committed in `Core/PlafondBands`; see `docs/plans/plafonds-tier-4.plan.md`. What
> they showed is the part worth reading there: the assumed two-sided rule does not survive the real
> tables, so the tier concludes in ONE direction only. [Noted here 2026-08-29 — this paragraph had
> described the empty scaffold as current for three days after it was filled.]

### Outcome

`Outcome` is derived from tenure + confidence + the source profile, and is the only thing the rest of
the pipeline should branch on:

```
tenure is excluded         → REJECT   (always — confidence is irrelevant to an exclusion)
tenure is UNKNOWN          → DIGEST
eligible, confidence ≥ 60  → MATCH
eligible, < 60, mixed src  → tenure becomes UNKNOWN → DIGEST   ← the §1 fail-closed rule, verbatim
eligible, < 60, pure src   → MATCH    (a pure source publishes no social stock to be confused with)
```

`Tenure::isExcluded()` is a method on the enum with a hard-coded set. There is no config key, flag or
constructor argument that can change it — per `CLAUDE.md` §1, *"not user-overridable"*.

---

## Files

| Path | What |
|---|---|
| `composer.json` | PSR-4 `Scout\` → `src/php/`. **Zero dependencies** — the runner is `tools/phpunit.phar` |
| `src/php/Core/Tenure.php` | The `Tenure` enum + excluded/eligible sets |
| `src/php/Core/Outcome.php` | `MATCH` / `DIGEST` / `REJECT` |
| `src/php/Core/RawListing.php` | What an adapter emits — spec §3 `models` |
| `src/php/Core/SourceProfile.php` | The subset of `Source` the classifier needs |
| `src/php/Core/TenureSignal.php` | One fired signal: tier, tenure, reason, evidence |
| `src/php/Core/Classification.php` | tenure + confidence + signals + outcome |
| `src/php/Core/TenureClassifier.php` | The ladder, the conflict rule, the fail-closed rule |
| `src/php/Core/Text.php` | Deterministic normalisation shared by both languages |
| `tests/fixtures/tenure/corpus.json` | The shared, language-neutral labelled corpus |
| `tests/php/Core/*Test.php` | PHPUnit suites, corpus-driven + unit |
| `tests/bootstrap.php` | Fails loudly when the dev autoloader is missing, instead of erroring per-test |
| `tests/sabotage-check.sh` | Breaks the classifier many ways; the suite must catch every one |
| `src/php/Core/MalformedText.php` | Text the classifier refuses to reason about, rather than folding to `''` |
| `src/php/Core/PlafondBands.php` | Signal tier 4 — wired, and inert until real figures exist |
| `tools/fetch-phpunit.sh` | Fetches the runner; pinned SHA-256 + signature, refuses on mismatch |
| `tests/test-fetch-phpunit.sh` | Proves that refusal actually happens |
| `tests/test-tenure-guard.sh` | Sabotage test for the §1 tripwire itself — must-fire and must-stay-silent |

## Blast radius

- `CLAUDE.md` glossary: PLS `OPEN — Q4` → `NEVER`; architecture table `src/core/` → `src/php/core/`.
- `spec/PROJECT_BRIEF.md` §2: PLS/LIBRE `ASK USER (Q4)` → the Q4 answer.
- `.claude/hooks/tenure-guard.sh`: add `pls` to the excluded-term patterns.
- `.claude/skills/scout-repair/drift-scan.sh` S6: add `PLS` to `TERMS`, so the guard can never lose it again.
- `docs/OPEN-QUESTIONS.md`: two new questions surfaced by building this (PLI, plafond bands).
- `.gitignore`: `/vendor`.
- `README.md` + `CLAUDE.md` § "Common workflows": the repo now has something to run.

---

## What building it actually found

Recorded because each was found by the machinery rather than by review, and each is the kind of thing
that would otherwise have shipped looking fine.

- **`route()`'s undetermined-tenure arm was unreachable dead code.** Two paths — no evidence at all,
  and the conflict rule — each built their own `Classification` with a hard-coded DIGEST, so the
  safety arm in `route()` was never executed and could be deleted with the suite green. Found by
  `tests/sabotage-check.sh`. Fixed by funnelling every exit through one `verdict()` helper, which is
  what the `Outcome` docblock already claimed was happening.
- **The collocation guard was untested; the comparative suppression was doing all the work.** Every
  `plus` trap in the corpus happened to be followed by a comparative (`plus de`, `plus tard`,
  `plus lumineux`), so deleting the collocation test left the suite green. Fixed with a SHOUTED
  fixture — `PLUS UN BUREAU` — because uppercase is enforced by the acronym pattern itself, so a
  lowercase trap never reaches the guard at all and proves nothing.
- **The suppression was evaluated per document, not per occurrence.** `Logement PLUS. Plus grand que
  la moyenne.` would have had its genuine financing label suppressed by an unrelated adverb later in
  the description — a social listing reaching a notification. Rewritten to test each occurrence in
  its own local context.
- **The corpus miscounted itself.** `declared_counts` said 47 where there were 52. The provenance
  test caught it, which is the whole reason that test exists.
- **`tests/sabotage-check.sh` had the classic self-verification bug**: it reported 13/13 detected
  while the baseline suite was *already red* from a missing autoloader, so every sabotage trivially
  "passed". It now asserts the baseline is green before running anything.
- **`tenure-guard.sh`'s `[]` pattern collided with PHP syntax.** `$flat[] = …` is an array append,
  which the excluded-set-emptying pattern read as an empty-list literal. Narrowed to `= []` / `: []`,
  which still catches every real emptying — proven by the new `tests/test-tenure-guard.sh`, which
  tests both halves of the tripwire's contract: 10 writes it must catch, 7 it must ignore.
- **PLS was excluded in the Q4 answer but absent from the tripwire's patterns**, and `drift-scan.sh`
  S6 could not notice because its `TERMS` table did not list PLS either. Both fixed.

## Decisions Log (continued)

- [2026-08-06 23:10] AGREED: **zero Composer dependencies; PHPUnit via the official PHAR.** The
  container's egress policy 403s `codeload.github.com` and GitHub zipballs, so Composer falls back to
  full `git clone`s — a PHPUnit dev dependency produced a **2.6 GB `vendor/`** for a test runner.
  `phar.phpunit.de` is not blocked. `vendor/` is now 56 KB of generated autoloader. Per
  `/root/.ccr/README.md` a proxy 403 is reported, not routed around; this is a different channel, not
  a workaround.
- [2026-08-06 23:15] AGREED: **sabotage-verification is part of this module's test contract.** Not a
  one-off. Every failure mode here is silent, so a green suite is not evidence that the tests would
  catch a regression. Wired as `tests/sabotage-check.sh`, documented in `CLAUDE.md` § Common
  workflows, and it must be run after any change to the classifier, `Text.php` or the corpus.
- [2026-08-06 23:20] AGREED: **the corpus declares its own provenance and the suite checks it.** The
  spec asks for real listing texts; all 132 are synthetic until a payload can be captured. Making that
  machine-checked keeps the gap visible instead of letting it decay into a stale comment.

---

## Round 1 of the certification panel — what it found

Three fresh-context adversarial reviewers, MAXIMAL tier. **28 findings, 3 of them P0.** Every one is
fixed below. Recorded in full because the interesting part is not that they were found but that the
suite, the sabotage run and my own reading all passed the code first.

### The round did not count, and that is a finding about me

Two reviewers independently flagged it: I committed `54fb014` while the panel was in flight, and
added an 81-line security-relevant shell script after dispatch. `CLAUDE.md` § Certification ladder
says a MAXIMAL round runs **against a frozen commit** precisely so this cannot happen. So round 1 is
advisory only; the two-consecutive-clean requirement restarts from a frozen tree.

### P0 — three ways a social listing could reach a notification

1. **`sans commission` read as an allocation tell.** In the wild that string is almost always
   `sans commission d'agence` — a FEE disclaimer that says nothing about how a flat is allocated, and
   which bailleurs sociaux advertise too. Tier 3 clears the floor unaided, so one commercial sentence
   converted a fail-closed digest into a notification. Removed; the two literals that carry the
   attribution sense explicitly remain. Fixture `regress-001`.
2. **`logement libre` read as tenure LIBRE.** It is not a tenure term at all — it is the standard
   French *vacancy* phrase (`libre au 1er août`, `libre de suite`). It fired tier 2 at 90 on a move-in
   date. Compounding it, `dropConventionneWhenIntermediateIsStated()` tested `isEligible()` rather
   than "is an intermediate label", so the spurious LIBRE **disarmed the conventionné exclusion** — a
   listing whose own title read *"Logement conventionné"* reached MATCH with the word absent from its
   reasons. Two independently reasonable-looking table entries composing into a §1 breach. Both
   fixed; fixtures `regress-002` and `regress-003b`.
3. **Malformed UTF-8 folded to an empty string.** `preg_replace('/\s+/u', …)` returns `null` on
   malformed input and a `(string)` cast turned that into `''`. The classifier then reported *"aucun
   signal dans l'annonce"* — a false statement about the listing, on the developer's phone — and
   matched on the source default. A cp1252 body carrying `conventionné PLAI … numéro unique
   d'enregistrement` classified as LLI/MATCH. This is `CLAUDE.md` hard rule 3 in its purest form: an
   error became an absence. Now a typed `MalformedText` refusal that routes to the digest.

### P1

- **`COMPARATIVE_TAIL` was an uncompletable denylist.** Any French adjective not on it turned
  `LOGEMENT PLUS MODERNE` into tenure PLUS and a silent REJECT. Replaced with the closed question:
  does a financing label *end* the phrase (punctuation, end of text, another acronym)? A known
  comparative means adverb; anything else is **indecidable and digests** rather than being guessed in
  either direction. Fixtures `regress-004`, `trap-003b`, `trap-003c`.
- **NFD-decomposed accents deleted the social tells.** The fold tables carried precomposed
  codepoints only, so an NFD `numéro unique d'enregistrement` never matched while an unaccented `LLI`
  in the same listing still did — the social side vanished and the eligible side survived. One line
  strips combining marks. Fixture `regress-005`.
- **Undecoded HTML entities were worse than silent.** An entity inside one label deleted that label
  and left the others standing: `logement&nbsp;social … loyer intermediaire` classified as LLI. Now
  refused as evidence that the ADAPTER stopped short, which is where the bug actually is. Fixtures
  `case-005`, `case-006`.
- **The tripwire's own narrowing lost detection, and its comment denied it.** Anchoring the
  empty-list pattern to `= []` silenced `public static function excluded(): array { return []; }` —
  exactly how you would empty an accessor in the language this repo now uses. `return []` and `=> []`
  are listed explicitly, and `!== []` no longer trips it.
- **The guard's own test poisoned the observability log.** Every run appended ten synthetic
  `FIRED on …` lines to the real `var/claude/logs/hooks-errors.log`, byte-identical in shape to a
  genuine §1 firing. `OBS_LOG` now points at a scratch file.
- **The whole certification surface still said the application did not exist.** 11 `SKILL.md` files,
  3 reviewer agents and `scripts/claude-bootstrap/CLAUDE-global.md` — the last of which `install.sh`
  ships as the NEXT session's system prompt. Worst of them: `tenure-correctness-reviewer.md` told the
  reviewer to return `PANEL VERDICT: CLEAN` when the diff did not touch `src/core/tenure.py`, a path
  that never existed here. A scripted route to CLEAN on the one module §1 exists to protect.

### P2/P3 worth naming

- `resolve()` implemented the **opposite** of its own docblock — transposed `strlen` terms made the
  shorter evidence win a tie, so `{financement: LLI, categorie: PLAI}` was decided by `lli` being
  three characters. A phorj port written from the docblock would have disagreed on exactly that
  input, which is the differential the shared corpus exists to expose. Fixture `regress-006`.
- The **fail-closed rule changed only the `Outcome`**, leaving `tenure` as LLI. Spec §4 requires the
  verdict itself to become UNKNOWN; the two halves of the object disagreed. No fixture reached the
  branch because every mixed source in the corpus declared no default. Fixture `regress-007`.
- `sabotage-check.sh` trusted a **non-zero exit code** as proof of detection — which a PHP parse
  error or a failed `cp` also produces. It now requires the suite to *say* it failed. It also ran
  `rm -rf "$work/repo"` with `$work` unchecked, and carried one sed expression that had silently
  been a no-op.
- `composer.json` committed `preferred-install: source`, which makes the git-clone fallback
  unconditional rather than a consequence of the egress policy — i.e. it would reproduce the 2.6 GB
  `vendor/` on hosts where dists work fine. Removed.

## Blast radius — corrected

The original list below was incomplete, and that incompleteness is what let the stale-scope
findings through. The `.claude/**` surface is part of the blast radius of any change that makes an
absent thing present.

- `CLAUDE.md`: PLS glossary, the excluded set, architecture table, status, workflows, gotchas, counts.
- `spec/PROJECT_BRIEF.md` §2: the Q4 answer.
- `.claude/hooks/tenure-guard.sh` + `tests/test-tenure-guard.sh`.
- `.claude/skills/scout-repair/drift-scan.sh` S6.
- **`.claude/skills/*/SKILL.md` — 11 files carrying a shared "Absent: `src/`" banner.**
- **`.claude/agents/*.md` — all 3 reviewer charters.**
- **`scripts/claude-bootstrap/CLAUDE-global.md` — shipped as the next session's system prompt.**
- `docs/OPEN-QUESTIONS.md`: Q18, Q19, Q20.
- `.gitignore`: `/vendor/`, `/composer.lock`, `/tools/*.phar`.
- `README.md`, and this plan.

---

## Round 2 of the panel — 20 findings, 3 more P0

Run against frozen `f00f86c`. It also did not count: I modified the tree while two of the three
reviewers were still running, so two of them declared the round void on arrival. That is twice.
The lesson is now mechanical rather than remembered — **no edits between dispatching a panel and
receiving every report**.

### P0 — French inflection, and it is the best finding the panel has produced

Every literal was matched exactly. French tenure vocabulary is inflected: the adjective agrees and
the noun phrase pluralises. So `conventionnée`, `logements sociaux` and `prêts locatifs sociaux`
were all silent non-matches while their singular masculine forms matched.

The reason it survived my reading, the suite AND the sabotage run is worth keeping: the acronyms
(`PLAI`, `ANRU`, `ANAH`, `HLM`) are **invariant**. The terms anyone checks first are precisely the
ones that could not break. A listing whose own description read *« logements sociaux et
intermédiaires »* was notified at full tier-2 confidence, because no excluded signal existed for the
conflict rule to see.

`plai` had to be exempted from inflection **by name**: the generic rule generates `plaie` (a wound)
and `plais` (from *plaire*), both real French words, and every listing containing one would have
been classified as social housing and dropped in silence.

### P0 — a doubt was competing positionally

The "indécidable" acronym marker was emitted as a tier-2 signal and resolved by byte offset against
real labels. Wrong in both directions, and neither was visible to the suite:

- **Losing** the race made it vanish — not an objection (UNKNOWN is not excluded), not a
  contradiction (`score()` skips same-tier signals). `Loyer intermédiaire … LOGEMENT PLS MODERNE`
  was notified; **the same two sentences in the opposite order digested.** Identical facts.
- **Winning** it masked a determinate PLAI, turning a hard disqualifier into a digest entry.

A doubt now competes with nothing: it cannot beat evidence and cannot be beaten by it.

### P0 — invisible characters split labels, exactly like entities did

`\p{Cf}` — U+00AD soft hyphen, U+200B ZWSP, U+FEFF BOM, U+2060 word joiner — inside `logement social`
deleted that label and left `loyer intermediaire` standing: LLI at confidence 90, above the floor, so
the fail-closed rule never engaged. The same asymmetry as undecoded entities, one Unicode category
over.

The sharp part: **my own doctrine produces the attack input.** `MalformedText::undecodedEntities()`
tells the adapter that decoding is its job; an adapter that obeys turns `&shy;` — ordinary
hyphenation markup in justified French CMS output — into U+00AD, which passes both the UTF-8 gate
and the entity gate.

Stripping alone was not enough either: removing a zero-width character between two words JOINS them
(`logementsocial`), so multi-word literals now join on `\s*` rather than `\s+`.

### Also fixed

- Tier-1 field values bypassed the collocation guard on the argument that "a field is not French
  prose". True of `financement: PLUS`, false of `categorie` / `dispositif` / `typelogement`, which
  carry prose in real feeds — `Pinel Plus` (a real 2023 scheme) was tenure PLUS at 97 and a silent
  REJECT. A value is now read as a code only when it is nothing but financing tokens and separators.
- `sabotage-check.sh` reported `ok` for a PHP **parse error**: PHPUnit turns an autoload-time syntax
  error into test errors, which matched both greps. Several sabotages are line-deletes pinned to
  exact source text, so a refactor could silently convert one into a syntax error and have the
  script certify the guarantee as covered. Parse/fatal output is now rejected outright.
- The tripwire's alternation carried `= none` — a **Python** idiom, in a repo whose only Python is
  the superseded prototype — while missing the YAML/JSON nulls that `config/*.yaml` will be written
  in, and PHP's `array()`. Seven shapes added, each with a test case.
- The 11 skill banners were made **self-contradictory** by round 1's fix: "Present since 2026-08-06:
  … a PHPUnit runner" and "Still absent: … a test runner" in the same paragraph, with
  `/pre-commit` offering *"no test runner in the tree yet — N/A with reason"* as an accepted Coverage
  answer. The ambiguity resolved toward the wrong branch. Rewritten so both halves agree.
- `.claude/agents/tenure-correctness-reviewer.md`'s **frontmatter** still routed on
  `src/core/tenure.*`. Round 1 fixed the body and missed the dispatch trigger — the same defect
  class the round-1 commit message singles out as "the worst", on the same file.

### Reported as P0 but NOT reproducible — recorded so it is not re-litigated

`tools/fetch-phpunit.sh` was reported to accept a BAD signature, because
`gpg --verify … | grep -q "$KEY"` returns grep's status and gpg prints the fingerprint even on a bad
signature. **The bypass was never live here:** the script has `set -euo pipefail`, which makes the
pipeline fail. Verified both ways — vulnerable without `pipefail`, safe with it — and
`tests/test-fetch-phpunit.sh` now asserts both facts so the disagreement cannot recur. Round 1's
resilience reviewer had this right and round 2's completeness reviewer tested the line in isolation.

The rewrite was kept anyway: correctness should not depend on an action-at-a-distance shell option
five lines away. It now checks gpg's exit status and `--status-fd` `VALIDSIG` explicitly, and refuses
a stale SHA pin unless a signature actually verifies.

### The durable fix for a recurring class

Three separate rounds caught a stale count in `CLAUDE.md` or `README.md`. Counting is not something
to remember, so `drift-scan.sh` grew **S7**: every prose count of corpus cases and open decisions is
now checked against the artefact it describes. Its first run found a false positive (the spec's
`≥30` minimum) which is now excluded, and a deliberate drift was re-introduced to confirm it fires.

---

## Round 3 — the first valid round. 25 findings, 3 P0

Run against frozen `95a9720`, and all three reviewers confirmed the tree never moved. Two P0s were
holes that my OWN round-1/2 fixes opened, which is the pattern worth naming: each fix was correct
about the case it was written for and wrong about the case next to it.

### P0 — the `conventionné` exception was scope-blind

Round 1 narrowed it from "any eligible signal" to "an LLI signal". It still deleted the evidence
whenever ANY LLI label appeared **anywhere in the listing**:

```
"Résidence mixte de logements sociaux et intermédiaires…"        → SOCIAL / REJECT  ✓
"Résidence mixte de logements conventionnés et intermédiaires…"  → LLI / MATCH      ✗
```

Same sentence shape, opposite answer — and the corpus already guarded the first one. **Deleting an
excluded signal biases toward notifying**, the one direction §1 forbids, and it does it invisibly
because the word never reaches `reasons[]`. The glossary's exception is for a conventionné that
QUALIFIES an intermediate label — the same noun phrase — so the rule is now adjacency-bounded.

### P0 — the field-value guard failed OPEN

Fixing `Pinel Plus` (a real 2023 scheme name read as tenure PLUS), round 2 required the whole field
value to be financing tokens. `financement: "PLUS CD"` — a real financing code — then matched
nothing and produced **no signal and no doubt**. Fields are not in `RawListing::text()`, so there was
no prose fallback either, and the listing matched on its description. The strongest rung of the
ladder was blinder than the weakest. **Case** settles both: an uppercase acronym in a financing
field is a code, a lowercase one is a word.

### P0 — the invisible-character fix was one Unicode category short

Round 2 closed `\p{Cf}`. `\p{Cc}` controls and invisible LETTERS (U+3164, U+115F, U+FFA0, U+2800)
produce the identical failure. U+0091–U+009F matter most: they are the ordinary product of CP1252
bytes decoded as Latin-1. `\p{Cc}` could not be widened wholesale — it contains tab, newline and
carriage return, which the whitespace collapse depends on, and the naive version broke nine tests.

### The test that should have caught two of them

`testNoExcludedTenureEverReachesAMatch` branches on the **verdict** being excluded. Both breaches
had the verdict `LLI`, so it never fired — and 26/26 sabotages passed against live defects. §1 is
about what the **listing** says. `testNoListingNamingAnExcludedTenureEverReachesAMatch` now asserts
that, with an explicit exemption table (fixture id → reason) rather than a silent allowance.

### The trap that explains why three rounds missed the stale paths

`/repair`'s SKILL.md told future sessions that `src/core/tenure.py` references were **correct and
must not be flagged**, citing `CLAUDE.md` — which says the opposite. The one skill whose job is
finding stale references was instructing sessions to suppress this exact class. That is why
`/pre-commit`'s MAXIMAL routing trigger — keyed on that path, so it never fired for the real
classifier — survived two rounds of "fix the stale paths".

### Corrected, not accepted

Round 2's completeness reviewer reported `fetch-phpunit.sh` as accepting a bad signature. **It never
did**: the script sets `pipefail`, which makes the reported pipeline fail. The round-3 reviewer
re-tested with a real tampered signature and retracted the finding. `tests/test-fetch-phpunit.sh`
asserts both directions so it cannot be re-litigated.

But the round-3 resilience lens found a real defect in the *rewrite*: under `set -e`, the assignment
`gpg_out="$(gpg …)"` aborts the script on a bad signature, so the REFUSING diagnostic never printed.
Fail-closed, but silent — and the test's helper differed from the shipped script in exactly that
dimension, so it passed for a reason the shipped code did not share.

### Three fixtures that did not test what they claimed

- `regress-018` carried `numero unique exige`, a tier-3 tell strong enough to reject on its own, so
  the U+FEFF split it claims to guard never mattered. It passed against the pre-fix classifier.
- `regress-019` used PRECOMPOSED accents (categories Lu/Ll), so it exercised neither strip. It is
  now NFD-decomposed.
- `lli-008` became byte-identical to `regress-007` after the round-2 relabel, so the corpus count
  overstated distinct coverage. Repurposed to cover what `regress-007` cannot: a mixed source with
  real evidence still matching.

Found by reverting the classifier and observing which fixtures went red — 9 of 12 did. That check is
now part of how a fixture gets accepted.

### Standing hazards retired mechanically

- `drift-scan`'s six python heredocs failed **silently**: any exception wrote nothing to `$FINDINGS`
  and the gate reported clean. A truncated `corpus.json` produced `P0=0 P1=0 P2=0` and exit 0.
- S7 checked `declared_counts['synthetic']` and never `['captured']` — the half that will actually
  change as sources come online.
- S7 also missed `all 56 are synthetic` (no bold, lowercase `a`), which is why one stale count
  survived three commits in the plan.
- `sabotage-check` reported `ok` for a PHP **parse error**. Now rejected outright — and it caught one
  of my own new sabotage expressions the same day.
- The tenure guard fired on `corpus.json` because round-2 fixture prose used the word "skips", on the
  one file `CLAUDE.md` says to append to forever. Zero false positives across all 41 tracked files now.
