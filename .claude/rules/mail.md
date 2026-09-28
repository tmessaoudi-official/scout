---
paths:
  - "src/php/Adapters/**"
  - "src/php/*/Adapters/*Email*"
---

# scout gotchas — mail

Moved verbatim from CLAUDE.md § "Gotchas & pitfalls" on 2026-09-28 (review-remediation 5.4, /rules-split). Scope: the mailbox adapters: header parsing, masks, marking a processed alert `\Seen`. New lessons for this area go HERE, not into CLAUDE.md. A § reference names a heading in CLAUDE.md or in docs/ENGINEERING-NOTES.md.

- **STRICT IS NOT THE SAME AS NARROW, and for a month the `Date` parser was both (2026-09-05).**
  The round-trip below is right and stays; what was wrong is the MASK SET it round-tripped against.
  RFC 5322 writes the day as `1*2DIGIT` and makes the seconds optional, the four masks were all `d`
  with `H:i:s`, and PAP sends `Sat, 5 Sep 2026 09:19:13 +0200` — `createFromFormat` accepts `5` and
  re-formats it `05`, so the round-trip refused a legal header. **Five days a month, on any portal
  that does not zero-pad, and nothing anywhere said so.** Measured: every PAP row first seen 1–5
  September carried `observedAt = NULL` (36 of 86) while every row to 31 August carried one;
  `source_runs.feed_newest_at` for `pap` froze at `2026-08-31T15:24:29Z`; and the live `doctor`
  reported **`pap feed_silent` — *« rien envoyé depuis 4 jour(s) »* on a feed delivering daily**.
  Two silent failures from one line: the store's stale-sighting guard (the 429-history-row defect)
  had no instant to compare, and a health verdict was false — hard rule 2 from both ends. The masks
  are now the grammar itself (optional day name × 1-or-2-digit day × optional seconds × `O`/`T`),
  and widening cannot weaken anything because the round-trip applies to every mask — the
  counterweight (a mismatched weekday, `31 Sep`, `tomorrow`, `+2 days`) is asserted beside the
  widening. **It was found by an audit, not by a test**: per-source NULL rates over the stored
  snapshots, which is the same instrument that found the SeLoger subject-as-title defect. Run it
  after any source goes live, and again when a portal changes its template. **The live header was
  READ, not inferred** — two messages pulled through `tools/dump-eml.php` — because *a true number
  attached to an invented cause* is this repo's named failure and the dates alone were only
  circumstantial.
- **A `Date:` header needs a STRICT parser, and `new \DateTimeImmutable` is not one.** It is a
  *relative-expression* parser: it misparses far more often than it throws, and every misparse moves
  the instant FORWARD. `Date: Fri, 09 Aug 2026` — where 9 August is a Sunday — has `Fri` applied as a
  relative modifier and records **14 August**, five days on; `now`, `tomorrow` and `+2 days` all
  parse as literal dates. That silently closed `FEED_SILENT`, whose observable band is only four
  days. The fix is strictness **by round-trip** — parse, re-format with the same mask, require
  equality — which is what `Store::epoch()` has done since its own scar. `createFromFormat` alone is
  NOT sufficient: it also returns 14 August and reports no error.
- **A PROCESSED ALERT EMAIL IS MARKED `\Seen`, AND THE FLAG MEANS ONE THING (row 36, 2026-09-04
  — developer request: *"mark the emails in (rent|car)/portails as seen when you process them, so
  that way I know which email was processed and which not"*).** `ImapMailbox` READS under
  `EXAMINE` + `BODY.PEEK[]` exactly as before; the one write is `acknowledge()` — a second session,
  `SELECT`, one `UID STORE … +FLAGS.SILENT (\Seen)` — on the messages a source CLAIMED (passed its
  `params.from` and `subject_pattern`, whatever they then yielded) and that do not already carry
  the flag, so steady state opens no write session. It is called by the domain pipelines ONLY (rent, car, job), after
  the store has recorded the pass, through `Scout\Adapters\AcknowledgesMessages` (gated on the
  interface, forwarded by `PacedSource`, so `--watch` marks exactly what `--once` marks); `doctor`
  and `tools/dump-eml.php` never mark, pinned by `AcknowledgeCallSitesTest`. A refusal lands in
  `RunResult::$errors` and the pass carries on — the listings are on disk and the flag is for the
  human. **So an UNREAD message inside the window is a signal**: no configured source claims its
  sender or subject (a new template, a widened filter), or the `IMAP_MAX_MESSAGES` cap cut it —
  truncation is visible for the first time. Two things not to "improve": the `SEARCH` stays
  `SINCE` + `FROM`, never `UNSEEN` (the 7-day re-read is what lets a misread card self-heal and
  what `FEED_SILENT` measures — the store's seen-set is the dedup, the flag is not), and the client
  addresses messages by UID with `UIDVALIDITY` compared across the two sessions, because a
  sequence number is only meaningful inside one. `tests/php/Adapters/Mail/ImapMailboxWireTest.php`
  is this client's FIRST wire coverage — a scripted loopback server behind the `$connector` seam,
  consulted only after the offline refusal — and 23 ledger cases pin every direction above.
