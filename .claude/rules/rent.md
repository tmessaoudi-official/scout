---
paths:
  - "src/php/Rent/**"
  - "config/rent/**"
---

# scout gotchas — rent

Moved verbatim from CLAUDE.md § "Gotchas & pitfalls" on 2026-09-28 (review-remediation 5.4, /rules-split). Scope: the rent domain: §1 gate call-sites, dedup and twins, durable exclusions, email-alert readers, titles. New lessons for this area go HERE, not into CLAUDE.md. A § reference names a heading in CLAUDE.md or in docs/ENGINEERING-NOTES.md.

- **AN EMAIL LISTING IS OBSERVED WHEN ITS MESSAGE WAS SENT, not when the pass read it** — and for a
  month it was not, which produced the loudest defect this tool has had. Measured 2026-08-29 from
  the notification folder (825 mails in 30 days): Bien'ici re-sent one Ozoir flat a day later at a
  HIGHER rent (1122 → 1146); both messages stayed in the 7-day IMAP window, every pass re-read both
  and stamped both at the pass time, and the store — whose *"a stale sighting manufactures no
  drop"* guard keys on the observation instant — recorded "1146 then 1122, a drop" every fifteen
  minutes: **429 alternating history rows, 128 *Baisse de loyer* emails** for one flat, 53 rows for
  a SeLoger one. Every guard for this existed; it never received the date. `RawListing::observedAt`
  (an email's `Date`, strict-parsed by `EmailMessage::sentAt()`, `null` for polling adapters) is
  now what the pipeline hands `Store::record()`, the snapshot round-trips it, and a detail merge
  keeps the CARD's. Ten ledger cases pin the chain hop by hop, because every hop is a place it can
  be quietly dropped. **And one hop WAS dropped after the fix shipped green:** `Pipeline::enrich()`
  rebuilt the listing field by field and forgot the new property — on the one machine where commute
  is ON, production — so the deployed fix fired the same phantom drop on its first live pass while
  2 192 tests (commute OFF) passed. Now `RawListing::withCommute()` is a clone-with and a reflection
  guard asserts enrichment changes nothing else. **Two rules from it: never copy a `RawListing`
  field by field (clone-with inside the class carries tomorrow's property too), and a pipeline fix
  is not done until the DEPLOYED watcher's first live pass says so — commute is the production-only
  path, and a test that reproduces the sequence on a path production does not take proves the
  sequence, not the production.**
- **§1 HAS ONE GATE NOW, AND THE REASON IT EXISTS IS THE SHAPE OF THREE ROUNDS OF FAILURE
  (`Rent/Cli/SectionOneGate`, 2026-09-07).** §1 is judged from FOUR persisted routes — the row's own
  durable reading, the group veto, the cross-track twin, the same dwelling under another ad id —
  across THREE announcing surfaces (`Pipeline`, the digest drain, `reclassify`). A certification
  panel ran three rounds against that matrix and found 9, then 8, then 17; in every round the
  majority of defects were in the PREVIOUS round's fixes, and *a fix landing on one of two symmetric
  surfaces* was committed **five times, three of them inside the fix for the one before**. The defect
  was never the keystroke: patching one cell of a 4×3 matrix leaves eleven.
  **The gate reads ALL four routes, FRESH, at the LAST moment before every send.** Nothing hoisted,
  nothing cached — a hoist is exactly what both round-3 P0s were — and `Store::excludedDwellings()`
  is ~19 ms, run once per ANNOUNCEMENT rather than per listing, so a 90-match pass spends under two
  seconds against a 15-minute cadence. The per-route checks upstream STAY: they shape the verdict
  (an excluded reading is recorded, a doubt becomes a digest) and short-circuit work. The gate does
  not replace them; it makes their ordering stop mattering for §1.
  **THE DIGEST BIN IS AN ANNOUNCEMENT, AND FOR FIVE ROUNDS IT WAS NOT TREATED AS ONE (round 5).**
  §1 was implemented as *"never a MATCH"*, so `Outcome::DIGEST` read as a DESTINATION rather than as
  something that reaches the phone. It reaches the phone. The digest branch `continue`s 42 lines
  ABOVE the match gate, so an excluded dwelling was announced under a headline asserting *« au régime
  indéterminé »* while the store held `PLS` for the same dwelling one row away, written by that same
  pass — reachable on ordinary in-pass ordering, no concurrency, via the non-transitive tolerance
  chain. The bin is §1's ONLY landing zone, so what it announces has to be trustworthy.
  **Its refusal is deliberately DIFFERENT from the match gate's**, and copying one to the other would
  be a defect: a row refused at the bin is DROPPED and said out loud, never written `REJECT` with a
  derived durable reading — a doubt the pipeline could not resolve is not a regime it read, and the
  match gate's write is terminal by query. `$digestRefusal` is a separate variable for that reason,
  so the two cannot share one sabotage expression either.
  **`tests/php/Repo/SectionOneGateCallSitesTest.php` is the half that prevents recurrence**: it
  discovers every METHOD under `src/php/Rent` that SENDS A NOTIFICATION (a `->send(` beside a formatter or
  `Notification` — the literal `notifier->send(` it first keyed on hid a renamed receiver, `8055943` the same
  evening) and fails when one does not consult the gate in that same method. Enumerating surfaces in prose failed twice —
  `ExcludedDwellings`'s docblock said "TWO callers", then "THREE", and each time the commit editing
  that line added the uncounted one.
  **ITS FIRST VERSION FAILED TOO, and how is the rule worth keeping.** It was FILE-granular and
  keyed on `->match(`, so `Pipeline` mentioning the gate once covered a SECOND ungated send in the
  same file — which is the round-4 P0, a `Priority::HIGH` rent-drop push of a `PLS` flat from a send
  98 lines above the gate — and a new surface using a local `$fmt` was never counted at all. That is
  the same class granularity its sibling guard was rewritten to remove IN THE SAME COMMIT: the
  lesson landed on one of the two. **An announcing surface is a SEND, not a notification KIND**, and
  the gate belongs above every send in its scope, not immediately above one of them. Rewriting the
  guard that way found THREE more ungated methods the lenses had not reached — both digest drains'
  rollup half, and `announcePromotions`, whose gate sat one level up in the caller.
  **Scope, stated because the sentence above invites the wrong reading:** the gate and the guard are
  RENT-only. The car domain persists no §1 route — verified against `VehicleStore`'s schema, which
  has no tenure, group or twin column — with ONE stated cost: when a car's snapshot will not decode,
  **`CarScout::collectRollup()`** leaves `$car` null, skips the re-judge block, rebuilds the listing
  from the stored columns and pushes it as an individual match if the stored score clears the gate,
  so an excluded car can be announced — and, for an AUCTION lot, announced WITHOUT ITS CLOSING
  TIME, because the columns carry none (auction rule 2's one uncovered path, ruled 2026-09-24:
  written down rather than patched with a column). (This paragraph named `VehiclePipeline`, which contains no
  snapshot handling at all — *a true cost attached to an invented site*, this repo's own named
  failure, in the sentence written to close a gap. Found by the C2 round-5 panel.) Structural,
  not patchable: the rent drain has a stored `tenure` column to
  check for exactly that row and the car domain has no persisted fact to substitute.
- **A TOLERANCE BAND IS NOT AN EQUIVALENCE RELATION, and assuming it is cost a reverted fix
  (2026-09-07).** `Dedup::within()` matches on ±30 € / 3 %, so it is NOT TRANSITIVE: three ad ids of
  one flat 30 € apart chain, and the third sits inside the second's band and OUTSIDE the first's. A
  session investigated the `Pipeline` staleness, could not build a reaching case, concluded the hole
  was unreachable on the reasoning that *"every route that excludes one copy excludes the other"*,
  **reverted a correct fix and wrote the reasoning into the plan**. A panel lens then executed the
  push on the live path. **Failing to find a reaching case is not evidence that none exists** — and
  when the argument turns on a predicate, check whether that predicate is transitive before relying
  on it. `SectionOneGateTest::testANonTransitiveChainStillRefuses` pins the chain.
- **A HOISTED READ IS STALE THE MOMENT THE LOOP IT GUARDS WRITES TO WHAT IT READ (2026-09-07, §1
  P0).** `reclassify` loaded `excludedDwellings()` once above its judging loop, with a comment
  borrowed from `Pipeline`: *"the candidate set is a property of the store, not of the row being
  judged"*. True there, false here, and the difference is ORDERING — `Pipeline` persists every
  member's reading in a recording loop BEFORE it loads the set; `reclassify` writes `tenure` INSIDE
  the loop. So an exclusion the command resolved itself was invisible to every row judged after it,
  and the same flat re-advertised under a new ad id was promoted and PUSHED in the same invocation.
  Two panel lenses reached it independently, each with an executed push, on rows that both start
  `UNKNOWN` — the population the command exists for, not a forged state.
  **A second judging pass would NOT have fixed it**: `staleVerdicts()` orders `seen_epoch DESC`, so
  in the natural case the NEW ad is judged FIRST. The veto belongs on the PROMOTION — the last
  moment at which every verdict of the run is on disk, and the only one that is ordering-independent.
  **And the rationale for the hoist was never measured**: on a copy of the live store
  `excludedDwellings()` is 47 candidates in 42 ms and matching every stale row costs 331 ms. The
  cost it avoided did not exist. *Borrowing a justification from a sibling call site is not the same
  as checking it holds at this one.*
- **A COUNT A HUMAN MAINTAINS IS DECORATIVE; ENUMERATE AND PIN IT (2026-09-07).**
  `ExcludedDwellings`'s docblock said *"TWO callers that must never disagree"*, then *"THREE"* — and
  **each time, the very commit editing that line added a caller it did not count**, on a line
  reading *"the count is load-bearing, so keep it right"*. Twice in two rounds. It now NAMES its
  callers and `tests/php/Repo/ExcludedDwellingsCallersTest.php` discovers the real call sites and
  fails when the list is stale. Its own first draft was vacuous — matching the whole docblock, where
  the word `Store` also appears in prose above the list — so it scopes to the enumeration bullets
  and both directions are sabotage-verified.
- **A DURABLY-EXCLUDED ROW HAS ONE WAY BACK, AND IT IS A NAMED COMMAND (row 40, 2026-09-05).**
  `scout --domain=rent reclassify --reopen=<dedup_key>` prints where the exclusion came from
  (*lecture propre / jumeau / groupe / même logement* — FOUR routes since 2026-09-07; it printed
  three until then, which read as *"nothing links this row"* on exactly the population the fourth
  exists for), clears the row's OWN and TWIN readings, and re-judges it
  on its own evidence in the same invocation — a row that then judges MATCH is notified, so the
  run needs a delivering channel like every promotion. **TWO routes are reported and deliberately
  NOT cleared** — the GROUP veto and the SAME DWELLING under another ad id — for one reason: each
  lives on ANOTHER row's own reading, and a listing that really says `PLS` keeps saying it, so the
  command tells you the next pass will reject again while that holds. **`--reopen` is therefore not
  a universal undo**, and a row held by either of those two is not reversible by this command alone.
  **What it can name differs by route, and the difference is not cosmetic**: the SAME-DWELLING
  warning names the offending listing's source and external id, while the GROUP warning names no
  listing at all — a cluster veto comes from the siblings' readings collectively. Neither prints the
  `dedup_key` that `--reopen=` actually takes, so "reopen it too" is guidance, not a command line.
  Never a
  pattern, never "all" — the cost of a wrong re-open is a social-housing flat pushed as a match.
  `--dry-run` reports and clears nothing; an unknown key is refused and touches nothing. This
  closes F20's *"repair route still owed"*; the *"this row said PLS vs something linked to it said
  PLS"* distinction was offered and declined — the provenance is printed instead of stored.
- **A room is a NOUN, not a POSITION.** `^\s*chambre\b` was the first cut of the coliving
  exclusion and three live titles defeated it in one week — a leading emoji (`✅ Chambre 10 min RER
  B`, pushed as a match at 20:04 on 2026-08-29), an adjective (`Confortable chambre individuelle`),
  a plural mid-title. The pattern now matches `chambre(s)` anywhere UNLESS a count precedes it (a
  digit or un/deux/trois/quatre/cinq/six): a flat COUNTS its bedrooms, a room rental NAMES one.
  Measured over all 1 593 stored titles before shipping: 48 room rentals caught (anchored: 36),
  zero flats. **Trial a pattern over the store before shipping it** — `SELECT title FROM listings`
  is one query and it is the only corpus of real titles this project has.
- **A GENERIC READER MUST NOT SCAN A URL'S QUERY — seventh instance of *URLs are classified text*,
  and the second poisoning of the SAME scan (2026-09-02).** Track 1j anchored the rooms branch
  against hexadecimal photo UUIDs with `(?<![A-Za-z0-9])`; base64url walks straight past that,
  because `-` and `_` are not alphanumeric. SeLoger wraps every link as
  `click.by.seloger.com/?qs=<per-recipient token>`, and one token reading `…zaw7m29jtx…` stored
  **7 m²** for a flat whose own card says `3 pièces . 64,25 m²` — offset 1029 beating offset 1948.
  The repair is a rule this repo had ALREADY RULED one layer up and never applied here:
  `RawListing::text()` drops a URL's query and fragment and KEEPS its path before the classifier
  reads it, because `?c=plai_plus` is a campaign string nobody can rewrite while a `plai` path
  SEGMENT is a real social signal. `EmailAlertSource::prose()` makes the same cut for the generic
  readers, and **its scope is load-bearing at both ends**: a CONFIGURED pattern owns its answer and
  is left untouched (pap is bit-for-bit unchanged), and the LINK readers never see it — for seloger
  the whole URL *is* the query, so stripping it there empties every notification's link and re-keys
  the entire backlog on the portals whose identity IS the link. **Measured over 2 043 stored card
  bodies before shipping**: 26 surfaces and 4 room counts recovered, all seloger; rent, postcode and
  commune 0; the other six sources 0 each. Seven of the 26 were matches that cleared every filter at
  their true size and were never notified. **Found by a scheduled query, not by a test** — Track
  6-A6, on the first day it was answerable — and the plan predicted the wrong cause (it expected the
  positional repair the rooms reader got), which is why the query says *run it*, not *reason about
  it*.
- **AND THE PATH HALF IS THE EIGHTH INSTANCE, closed 2026-09-04.** The query strip above closed one
  alphabet; the PATH is deliberately KEPT — a `plai` path SEGMENT is a real social signal while a
  campaign string is not — and `SURFACE_PATTERN` had no left anchor at all, so a digit in the middle
  of a path token still beat the real figure below it (`preg_match` is first-match-wins).
  `ROOMS_PATTERN` had carried `(?<![A-Za-z0-9])` since Track 1j; the surface branch had neither
  guard. A review lens graded it the softest finding in its report, on the grounds that no real
  payload carries `m2` in a path — **and the store disagreed**: Bien'ici's own photo host is
  `d2m2j20yzublln.cloudfront.net`, `2m2` reads as 2 m², and four stored flats of 41, 54, 65 and
  59 m² are held at **2 m²**, three of them silently rejected by `min_surface_m2: 50`. The
  distribution id is on every photo URL from that CDN. **Measure a repair through the CURRENT
  pipeline, never against the raw payload**: applied to the raw stored body the same anchor changes
  41 rows, but 37 are SeLoger tokens the query strip already closed — a true number attached to a
  cause that had already been fixed, which is the repo's named failure pointing backwards.
- **Two tracks, ONE push (developer ruling, 2026-08-29).** The 2026-08-06 rule that a landlord's
  listing and its agency copy on SeLoger/Bien'ici are two findings STANDS — identities, groups and
  histories stay per track, `Dedup::duplicateReason()` still refuses across families — but 43 flats
  had been pushed twice, so `Dedup::twinReason()` links cross-track twins for NOTIFICATION only,
  with the same positive-evidence bar. Clusters are judged direct-route first; the push names the
  other route with its link; the agency copy is marked, not pushed; and a direct route arriving
  AFTER the agency copy is still pushed once, saying whose push it follows — the better route is
  never hidden. **The source now leads every listing title** (`seloger · 44/100 — …`), because the
  developer prioritises by source and the title is what a phone shows first.
  **AND THE LINK CARRIES §1 (2026-08-30, two panel rounds):** the twin's judged tenure feeds the
  same funnel as the persisted group veto — an EXCLUDED twin on the other track REJECTS the flat
  whichever route is being judged, an UNDETERMINED twin turns the match into a DIGEST — and the
  fact is PERSISTED on the row (schema v12 `twin_tenure`/`twin_source`, `Store::recordTwin()`),
  because a veto living only in the pass's harvest lapsed the moment the twin was not fetched: a
  pass seeing the agency copy alone pushed the PLS flat. Precedence is the group veto's: excluded
  sticks for the row's life (developer ruling), otherwise the last reading wins, so a doubt clears
  only when both routes are judged together again. `reclassify` reads it beside the group veto.
  **Round 3 added two more halves**: the fact is written on EVERY member of the cluster and read
  as the most restrictive across it (a second private-portal copy absorbed on one pass and surviving
  on another had been pushed twice), and a row's OWN excluded reading is durable too (a hydration
  fingerprint mismatch served the card alone, and a PLS row was re-judged LIBRE and pushed). The
  row records the JUDGED verdict, so `scout digest` announces the doubt's cause.
  **Round 4 (2026-08-31) found that last half landed on ONE of its two surfaces, and the sentence
  above was true only while the protected row kept surviving its cluster.** `durableOwnReading()`
  repaired the survivor's JUDGEMENT; the recording loop still overwrote EVERY member's stored
  tenure with today's raw reading, and only the survivor's was rebuilt. Survivorship follows the
  harvest order and `Core\Pacer` shuffles it every pass, so a row holding yesterday's `PLS` lost it
  the moment a sibling was polled first — before `groupExcludedTenure()`, which reads that same live
  column, was ever consulted — and the flat was pushed. **The same read also fed the twin scan**,
  which took the other track's tenure from the pass's RAW classification, so a twin held excluded
  only by its own durable reading contributed an ELIGIBLE one, `recordTwin()` persisted it, and the
  agency copy was pushed naming the PLS route as the *voie directe* with its URL. Two lenses found
  it independently. The durable reading is now applied **in the recording loop, per member, before
  anything is written** — the same refuse-to-downgrade rule `recordTwin()` already used, so there is
  no window in which the excluded reading is off disk, and it holds for a member that is never
  judged at all. **It is PERMANENT to every automatic path**: nothing re-opens it (`staleVerdicts()` skips an excluded
  tenure, `pendingDigest()` skips a non-DIGEST outcome, `replay` writes no verdicts). The docblock's old
  *"until an explicit command"* named a route that did not exist when this was written (2026-08-31); since
  2026-09-05 (`2553c94`) it does — see *"A DURABLY-EXCLUDED ROW HAS ONE WAY BACK"*: `reclassify --reopen`
  clears the row's own and twin reading. The code comment in `Pipeline.php` beside `durableOwnReading()` still
  says "There is no such command" [re-checked 2026-09-28].

- **`detailRead` means the detail page YIELDED evidence, not that it was fetched** (audit 2026-10-02, P0-2). `RawListing::mergedWith()` used to set it unconditionally, so an HTTP-200 maintenance page, or a description selector that stopped matching, licensed a weak tenure signal on a mixed source as "examined, nothing excluding". Measured on the live store: 25 of 1036 In'li rows and 1 of 87 Cityloger rows were hydrated with only a title and a postcode, eight were judged LLI/50 MATCH on the source default alone and one was announced. It is now true only if the detail carries prose (`description`) or the structured declaration (`fields['tenureField']`) — NOT "any field": `fields` is the whole flattened extract and always holds `ref`. A later empty fetch never un-reads a listing. The total-failure case (a selector that misses on every hydrated page of a pass) already reaches source health through `PatternMissLog::escalate()`; a PARTIAL miss rate does not, by design. Stored snapshots keep their old flag. A row that is STILL PUBLISHED is re-judged on its next sighting (`Pipeline` runs `classify`, `recordVerdict` — which rewrites the snapshot — and `recordOutcome` for every sighted member; only an EXCLUDED reading is held durably), and the cache-hit path re-merges the stored title-and-postcode flat, so it fails closed to UNKNOWN/DIGEST [Verified 2026-10-02: the eight affected stored rows, re-classified with `detailRead=false`, all give UNKNOWN/0 DIGEST]. A row that is NO LONGER published is never re-sighted, so it is never re-judged, and `reclassify` reads the snapshot's stale `detailRead=true` too: of the eight, one was already announced, one was still sighted and six were delisted between 09-24 and 10-01 and sit as unnotified LLI/50 MATCH in the low-score queue until a rollup announces them as « vérifié, score bas » (a doubt presented as verified). Not fixed here — re-filing a verdict is §1-adjacent and was put to the developer.
