---
name: domain-alert-email-ingestion
description: Use when a task touches scout's private-portal alert-email route - the IMAP window and MIME parsing, per-portal alert parsers (SeLoger, Bien'ici, leboncoin, PAP, ParuVendu, Apec...), `email_alert` source blocks, card separators and identity, fixture capture and scrubbing (`tools/dump-eml.php`, `tools/scrub-eml.php`), the Gmail MCP label query, `\Seen` marking, Q40 public-repo exposure; paths src/php/Adapters/Mail/**, src/php/Rent/Adapters/EmailAlertSource.php, src/php/Car/VehicleEmailSource.php, src/php/Job/Job*EmailSource.php, tests/fixtures/**/*.eml, docs/ALERT-CAPTURE.md. How an expert works here - procedure, traps, checks, evidence.
---

Review date: 2026-10-03 11:44   Validation mode: advisory   Core: .claude/rules/expertise-core.md

## Roles and mental models
- **Real-payload gatekeeper**: no `email_alert` block, separator or reader without a real captured message and a hand-counted listings number; a blind config cost four defects the day the first real mail arrived behind 1 886 green tests.  [Observed: 2026-08-25/26; Source: C ALERT-CAPTURE "four times"]
- **Corpus keeper and scrubber** (the repo is PUBLIC: a leak is world-readable, Q40): the mailbox IS the corpus; the awkward message is the most valuable.  [Ruled: developer 2026-08-26; Source: C ALERT-CAPTURE Part A]

## Hard rules (delta over CLAUDE.md / mail.md)
| Rule | Source |
|---|---|
| READ is read-only at protocol level: `EXAMINE` + `UID FETCH ... BODY.PEEK[]` (`BODY[]` sets `\Seen`). The ONE write is `ImapMailbox::acknowledge()` (second session, `SELECT`, `UID STORE +FLAGS.SILENT (\Seen)`), called only by the domain pipelines after the store recorded the pass; `doctor` and `tools/dump-eml.php` never mark (pinned by `tests/php/Repo/AcknowledgeCallSitesTest.php`). | [Source: mail.md; T 5.1] |
| The search stays `SINCE` + `FROM`, never `UNSEEN`: the 7-day re-read lets a misread card self-heal and is what `FEED_SILENT` measures; the seen-set is the dedup, the flag is not. | [Ruled: developer 2026-09-04; Source: mail.md] |
| Window: `IMAP_SINCE_DAYS` default 7, `IMAP_MAX_MESSAGES` default 50, both CLAMPED never refused; per-source `params.from` is pushed into the `SEARCH`. An UNREAD message in the window means no source claims it or the cap cut it. | [Source: ImapMailbox.php; mail.md] |
| Messages are addressed by UID; a `UIDVALIDITY` mismatch across the two sessions refuses the STORE. | [Source: T 5.1, ImapMailbox.php] |
| Raw captures are UNSCRUBBED: never print bodies (shell or Gmail MCP); if the scrubber refuses, do not hand-edit. | [Source: C Part A'/B] |
| Fixture names `tests/fixtures/<domain>/<source>/YYYY-MM-DD-NNN-<slug>.eml`; captures APPENDED never renumbered (the number is an identity assertions name); send `From:`, subject and a hand-read count with each. | [Source: C Part C] |
| The email alert is the PRIMARY private-portal route (within ToS, no bot to defeat). | [Ruled: 2026-08-26; Source: R7, core s3] |

## Identity and parsing rules per portal (perishable: which portals alert, templates drift)
- **SeLoger**: links are opaque `click.by.seloger.com/?qs=` tokens -> `id_from: content`, `card_separator` = the terminal CTA, rent NOT in the key (a price drop is an event), a no-information floor on the card (else every failure hashes to `sha1('seloger|||||')`); `card_separator` with `mixed_tenure: true` is REFUSED at load; pin `params.from` to the alert subdomain (12 of 50 messages were contact receipts, unfilled template `870,00 €cc`); commune is null on every card, so `commune_pattern` anchors on the postcode below; coliving rooms carry the whole flat's counts (title anchored `^\s*chambre\b`); `title_pattern` anchors on the `pieces`/surface line (`exclude_title_patterns` unreachable on 37.5 % of cards for a month).  [Observed: 2026-08-25/26, 2026-09-01; Source: D I]
- **Bien'ici**: identity = link (`/annonce/<agency>-<id>`); `params.from` REFUSED at load for an enabled `email_alert`; a link-keyed segmented source must name `link_host`; separator `\nPhoto\n` (a copied one under-read 3/13 surfaces). Offline proof: `RENT_SCOUT_DB=$(mktemp -u) MAILBOX_DIR=tests/fixtures/rent/bienici scout --domain=rent doctor --source=bienici`.  [Observed: 2026-08-25/30; Source: D I]
- **leboncoin**: first HTML-ONLY alert; `EmailMessage::harvestHrefs()` (private static) moves each anchor URL into the body, HTML path only (a message with a plain alternative would re-key the Bien'ici backlog); separator literal `Voir l'annonce` (the `\nVoir l'annonce\n` form matched nothing: ONE card with card 3's URL and card 1's rent).  [Observed: 2026-08-26/29; Source: D I, F]
- **ParuVendu**: subject carries CRITERIA, not the search name; sender `info@paruvendu.fr` serves BOTH rent and car alerts: a sender-keyed Gmail filter misroutes (rent in the car label).  [Observed: 2026-08-29; perishable]
- Also: `Job/JobDigestEmailSource.php`; parsing `Adapters/Mail/EmailMessage.php`; offline replay `FileMailbox`; blocks in `config/rent/sources.json`.  [Observed: ls 2026-10-02]

## Capture and scrub procedure (fixture rules)
1. **Capture**: `tools/in-docker.sh php tools/dump-eml.php <from> [max] [out-dir] [folder]` (or export on the DESKTOP Gmail web client, forward-as-attachment; a plain forward rewrites headers). FOLDER defaults to INBOX; an alert in a Gmail label reports "aucun message" and looks like a silent portal: pass the label folder (`RENT_IMAP_MAILBOX` / `CAR_IMAP_MAILBOX` / `JOB_IMAP_MAILBOX`). Window `DUMP_SINCE_DAYS` (default 7, `all` for history). Until 2026-09-25 it kept the last N SEQUENCE numbers (wrong in re-labelled Gmail).  [Observed: 2026-09-25]
2. **Scrub**: `tools/in-docker.sh php tools/scrub-eml.php <in> <out> <address> [needle ...]` (needs `vendor/autoload.php`; shares `Scout\Core\RecoverableForms` with the CI guard). PASS YOUR OWN NAME (and pseudo) AS NEEDLES: a name in the `To:` display name or the body's "ce message est destine a" line is unreachable by address replacement. `To:`/`Cc:` are dropped structurally. `0 named identifier(s) replaced` on a portal that greets you by name means a wrong needle, not a clean message. Base64 bodies can only be REFUSED (capture text/plain or quoted-printable). It decodes base64url/QP/base64 first ("address absent" is the wrong test: every Bien'ici link carries a JWT decoding to the address).  [Ruled: developer 2026-08-31; Source: C Part B]
3. **One placeholder per DISTINCT value.** One constant (`FIXTURE`) for every per-link id made an Apec card's four links identical; the pattern read 45 offers from the fixture, 0 live (the tell, 221 links became 53, was explained away). A link count that DROPS after scrubbing is a defect.  [Observed: 2026-09-24; Source: M C6; D F]
4. **Raw-twin check**: run the adapter over the RAW capture (via `FileMailbox`, kept in `var/claude/`, never committed) and over the scrubbed fixture; require the SAME offers (count, ids, fields).  [Observed: 2026-09-24; Source: M C6]
5. **Ground truth**: assert the hand-read listings number in the test; one plausible card passed everything until a hand count exposed it.  [Observed: 2026-08-26]
6. Commit the fixture only after `tests/php/Repo/FixtureSecretsTest.php` and `tools/in-docker.sh bash tests/test-scrub-eml.sh` are green. Its JWT pattern began with `\b` and never matched QP `=3DeyJ` tokens until 2026-08-30: test the QP spelling.  [Observed: 2026-08-30]

## Gmail MCP (developer/agent-side instrument; the product speaks raw IMAP)
- `search_threads` `label:` takes the hyphenated NAME (`label:rent-watch-portails`, `/` becomes `-`), not the ID; 2026-09-13 the ID form returned `{}` while `list_labels` said 6 unread, the NAME returned the 6.  [Observed: 2026-09-13; Source: M B7, T 6.2]
- A wrong query form silently certifies a clean folder: cross-check `{}` against `list_labels` counts and a positive control.  [Observed: 2026-09-13; positive-control advice Speculative]

## Alert creation rules (when a new portal alert is requested)
On the WEB, never the mobile app (push only, mailbox silent forever); highest frequency; distinctive search name (`rent-watch-pv-idf`); portal filters WIDER than criteria (3 rooms/45 m2/1 300 vs 3/50/1 200 CC). An expired alert looks like a silent feed.  [Ruled: ALERT-CAPTURE Part D]

## Traps with the failing symptom
| Trap | Failing symptom | Source |
|---|---|---|
| Four SeLoger MIME defects: preamble read as a part (`$plain ??= ''`), RFC 2047 collapse after decode (`exclusivit és`), rent across a line break (`ref 850` over `1 450 EUR`; separator `\h` not `\s`), undecoded entities (`conventionn&eacute;`; `Text::fold()` refuses them) | `body len: 0, links: 0` behind 1 886 green tests | [Observed: 2026-08-25] |
| Lenient `Date:` (`Fri, 09 Aug 2026`, a Sunday) | recorded as 14 Aug, closing `FEED_SILENT` (4-day band) | [Observed: mail.md] |
| Duplicate ids inside one SeLoger message threw | seloger returned ZERO for SEVEN passes; a throw is for a STATE, an event is warned | [Observed: 2026-08-26] |
| Non-zero-padded day (PAP `Sat, 5 Sep 2026`) | 36 of 86 PAP rows `observedAt NULL`, `feed_newest_at` frozen at 2026-08-31, doctor said `pap feed_silent` on a daily feed; found by a per-source NULL-rate audit, NOT by a test | [Observed: 2026-09-05] |
| Shared window starvation | 124 messages in the window vs cap 50: a busy portal starves a quiet one (SeLoger truncated, core s9) | [Observed: 2026-10-01; perishable; [Unverified] here] |

## Q40: public-repo exposure
- Public on 2026-09-07 (`gh api /repos/tmessaoudi-official/scout`) [perishable, re-run before quoting]. Committed-then-scrubbed incidents stay readable by `git show <old-sha>:<path>`: two ParuVendu fixtures with the real name, three Bien'ici ones with the address under double base64 (pushed, found 2026-08-31), Cityloger's browser-side Maps key (`25d8839`; hygiene, not a credential).  [Observed: CLAUDE.md Hard rule 7]
- The asset is the LINKAGE of a person to a subscription and its criteria, not the name.  [Ruled: developer 2026-08-31, 2026-09-07]
- Q40 default ACCEPT; a session never rewrites history; a forward fix is the most it lands. Scrub BEFORE the first commit: a pushed blob cannot be recalled.  [Ruled: CLAUDE.md Git autonomy; Source: Q40]

## Before-you-start checks per path
- **`src/php/Adapters/Mail/{ImapMailbox,EmailMessage,FileMailbox}.php`**: read `.claude/rules/mail.md` (auto-loads) and the ENGINEERING-NOTES email section first; keep READ on `EXAMINE`+`BODY.PEEK[]`; a new `STORE` goes through `AcknowledgeCallSitesTest`, not around it; a new date/charset/MIME case needs a fixture from a REAL message; rerun `tests/php/Adapters/Mail/ImapMailboxWireTest.php` (loopback server behind the `$connector` seam; 23 ledger cases).  [Source: mail.md, T 5.4]
- **the `*EmailSource.php` adapters, `config/*/sources.json` `email_alert` blocks**: real capture and hand count first; choose identity (`id_from`, `card_separator`, `link_host`, `params.from`, `subject_pattern`) before enabling; trial a new pattern over ALL stored cards (F24: 617 unchanged, 2 gained, 0 lost); after go-live run `SELECT title, COUNT(*) FROM listings GROUP BY title` and a per-source NULL-rate audit; a configured pattern must reach `PatternMissLog` and `health()`.  [Observed: 2026-08-26, 2026-09-01]
- **`tools/dump-eml.php`**: run `tools/in-docker.sh bash tests/test-dump-eml.sh` (never writes under `tests/`, never leaks the IMAP password in a stack trace); `.env` is denied to the agent: capture runs are the developer's.  [Source: CLAUDE.md; D F]
- **`tools/scrub-eml.php`**: run `tools/in-docker.sh bash tests/test-scrub-eml.sh`; never loosen a refusal to land a fixture.  [Source: CLAUDE.md file map]
- **`tests/fixtures/**/*.eml`**: append-only numbering; check `git status --porcelain | grep '^A'` and `git diff --cached --stat` before a pathspec commit; grep the fixture for address, name and pseudo in plain, QP and base64 spellings.  [Observed: 2026-08-31]
- **`docs/ALERT-CAPTURE.md`**: the runbook the developer follows on a phone/desktop; edit it when a tool's behaviour changes. Its Part D table and "what is owed" list are PERISHABLE.  [Observed: ALERT-CAPTURE 2026-08-29]

## Evidence table (what certifies a change here)
| Change touches | Certified by | Sabotage shape that matters | Stays uncertified unless run |
|---|---|---|---|
| `EmailMessage` MIME/date/charset parse | `EmailMessageTest`, `tests/php/Adapters/Mail/EmailMessageSentAtTest.php`, a fixture from a real message | accept `new \DateTimeImmutable`; `\h` -> `\s`; read WHICH test goes red | the next real template from a portal |
| `ImapMailbox` window/UID/ack | `ImapMailboxWireTest` (loopback), `AcknowledgeCallSitesTest`, ledger cases in `tests/sabotage-check.sh` | `BODY.PEEK[]` -> `BODY[]`; drop the `UIDVALIDITY` compare | a real Gmail IMAP session (real server, labels, 500+ messages) |
| Per-portal parser / `email_alert` block | fixture from a REAL payload + hand-counted number + `doctor --source=X` on a throwaway `RENT_SCOUT_DB` + `EmailAlertClaimTest`/`EmailAlertSegmentationTest` | drop the separator / change the identity scheme; force the claim filter off | the first DEPLOYED pass on a real message (template drift, commute hop OFF in tests) |
| Scrubber / `FixtureSecretsTest` / a new fixture | `tools/in-docker.sh bash tests/test-scrub-eml.sh`, `FixtureSecretsTest`, the raw-twin same-offers check, a grep for name/address in three encodings | feed a QP `=3DeyJ` JWT; drop the name needle; require refusal | exposure of anything already pushed (Q40) |
Never certified by gates (declare each by name): the first deployed pass on a real message, a portal's NEXT template, expired or never-fired alerts, Gmail IMAP under relabels, a Gmail MCP query form (positive control only).  [Source: core s4; Observed: 2026-08-25, 2026-08-29]

## Reviewer lenses
1. **Privacy**: does any committed file carry the subscriber's address, name, a JWT in QP or double-base64 spelling, or a key?
2. **Real-payload fidelity**: do fixture, raw twin and hand count agree; was the config written from a real message, not a copied separator?
3. **Silent-zero**: can this make the source return zero cards (or `observedAt NULL`) while `health()` stays green; who reads that count?
