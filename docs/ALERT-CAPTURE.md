# Capturing an alert email — the runbook

> **Why this file exists.** Six saved-search alerts are live across two domains and **not one of
> their payloads has been read**. Every source in this project is built against a real message, never
> against a guess, and that is not caution — it is a measured price.
>
> *That premise is dated: it was written when six alerts across the rent and car domains were live.
> A seventh — LinkedIn, the job domain's first — was captured and read on 2026-09-13 (checklist).*

## The four times this repo paid for writing config blind

All four were behind a green test suite at the time.

| Source | What blind config actually cost |
|---|---|
| **SeLoger** | **Four MIME-parser defects in one day**, behind 1 886 passing tests. The parser returned `body len: 0, links: 0` — zero listings, no exception, a source that would have looked like a quiet market for ever |
| **Bien'ici** | Copying SeLoger's card separator would have mis-read **3 of 13 surfaces and 1 of 13 room counts**, all *under*-reported — so real matches silently rejected for being too small |
| **leboncoin** | The obvious separator matched nothing and the whole message parsed as **one card**: card 3's URL carrying card 1's rent, commune and surface. One plausible-looking listing, nothing reading as a fault |
| **PAP** | The surface reader returned **45** — the *search criteria floor* the portal quotes above the ad — instead of the flat's 50. The first PAP alert ever sent would have been rejected for being too small |

**A real payload is the input. A green suite is not a substitute for one.**

---

## What is owed, and what each capture has to ANSWER

A capture that answers an open question outranks one that confirms a path already known to work.
That is why Autohero is last: its polling payload was confirmed complete on 2026-08-27, so its
alert is a convenience rather than the route.

| # | Source | Domain | Status | The question this capture answers |
|---|---|---|---|---|
| 1 | ~~**Alcopa Auction**~~ | car | **EMAIL ROUTE REFUSED 2026-08-28** | **Does the alert carry a CLOSING TIME?** The lot page does not. Rule 2 of the auction ruling refuses a source that publishes none — so this capture decides whether the email route is admissible at all |
| 2 | **leboncoin — `Voitures : …`** | car | alert live, **NOT ROUTING** — 0 leboncoin messages among 52 in the label on 2026-08-29 | **Does the message carry all the cards its subject counts, or only the first few?** The subject said `58 nouveaux résultats`. This decides how much `card_separator` work it needs |
| 3 | **leboncoin — `… vous propose …`** | car | sender measured (`no.reply@leboncoin.fr`, n=2), `.eml` still in the INBOX | ~~Who sends it~~ **is it one ad per message?** The subject shape (n=2) says yes — `<seller> vous propose <title> à <price> € à <commune> (<CP>)` — so it is its own source block, PAP-shaped. The `.eml` confirms or refutes that |
| 4 | **ParuVendu** | rent | alert created 2026-08-27 | **Is the saved-search name in the subject?** If yes, the Gmail filter matches a discriminator you control and is self-tripwiring. If no, it falls back to the sender |
| 5 | **Agorastore** | car | alert live, **vehicles-only confirmed 2026-08-28** | **Is the feed all categories or vehicles only?** Decides whether an ingest-side category discriminator is needed at all |
| 6 | **Autohero** | car | **CAPTURED 2026-08-29** — two alerts, ONE car per message, the car named in the subject (*"Est-ce bien la MG ZS que vous cherchiez ?"*) | Answered by the capture: the alert is a single-vehicle suggestion, not a digest — structurally the PAP shape, and the only car alert with that shape. Polling stays the route; the alert is corpus |

**CORRECTED 2026-08-28 — La Centrale and AutoScout24 alerts DO exist.** Reading the mailbox found La
Centrale firing (`904 nouveaux véhicules correspondent à votre recherche`, with real prices) and
AutoScout24's saved search confirmed (no alert fired yet). Still not created:
CapCar (blocked on whether its make selector is multi-select), Interencheres (needs no
alert — its route is polling), Carizy (offers none, and is refused anyway).

---

## Part A — exporting a message you already have

**Do this on the desktop web client.** The mobile apps cannot export a raw message; if you only have
a phone to hand, forward the message *as an attachment* to yourself and export that on a desktop
later — a plain forward rewrites the headers and destroys exactly what we need.

1. Open the message in Gmail on the web
2. Top-right of the message, the **⋮** menu (not the one at the top of the window)
3. **Download message** — this saves a `.eml`
   *(If your interface shows only "Show original": click it, then* **Download Original** *on the page that opens.)*
4. The file lands in your Downloads folder, usually named after the subject

**One rule, and it is the whole corpus rule: never delete an alert email.** Until a parser exists the
mailbox *is* the corpus, and the awkward messages are the valuable ones — the message that reads
strangely is the one that finds the defect.

### Part A′ — several messages at once: `tools/dump-eml.php`

The route above is the documented one and stays that way: it is the only one that works for a
mailbox this repo's credentials cannot reach, and it needs nothing installed. `tools/dump-eml.php`
exists for the case it makes expensive — several messages from one sender, needed together, to
shape a source against.

```bash
php tools/dump-eml.php <from-address> [max] [out-dir] [folder]
php tools/dump-eml.php no.reply@leboncoin.fr 5
php tools/dump-eml.php support@agorastore.fr 2 var/claude/captures 'car-watch/portails'
DUMP_SINCE_DAYS=all php tools/dump-eml.php ops@collective.work 50 var/claude/captures 'job-watch/portails'
```

It reads `IMAP_HOST` / `IMAP_USER` / `IMAP_PASSWORD` / `IMAP_PORT` from `.env`, and it is **read-only
at the protocol level** — `EXAMINE` rather than `SELECT`, `BODY.PEEK[]` rather than `BODY[]` — the
same two choices `ImapMailbox` makes while it READS, so no defect on this side can mark the
developer's mail as read or otherwise modify a real mailbox. (Since 2026-09-04 a `run` pass does
mark the messages it processed `\Seen`, in a separate session after the store recorded them — that
is the pipeline's doing, never this tool's, and `tests/php/Repo/AcknowledgeCallSitesTest.php` pins
the tool to `EXAMINE` + `BODY.PEEK[]` with no `STORE`. A capture therefore never changes what the
label shows as processed.)

Four things to know before using it:

- **Its output is RAW and therefore UNSCRUBBED.** It carries the subscriber's address and usually
  their name. Part B is not optional afterwards; this tool is a faster Part A, never a shortcut past
  Part B.
- **It refuses to write anywhere under `tests/`**, and that refusal is the reason it can be used at
  all — the one-step path from a mailbox to a committed fixture is how both of this repo's leaks
  would happen again. The refusal covers the bare `tests`, a trailing slash, an absolute path, and
  anything reaching back in through `..`; and an out-dir it cannot resolve is refused rather than
  guessed at. `tests/test-dump-eml.sh` is the sabotage test for exactly that, and the tool had
  neither docs nor test for its first weeks, which is what this section closes.
- **The FOLDER argument matters and defaults to `INBOX`.** An alert routed to a Gmail label has been
  archived out of the inbox, so a search there finds nothing and reports `aucun message` — which
  reads exactly like a portal that has sent nothing. `RENT_IMAP_MAILBOX` / `CAR_IMAP_MAILBOX` /
  `JOB_IMAP_MAILBOX` in `.env` name the folders the sources themselves read.
- **"Recent" is a DATE window, `DUMP_SINCE_DAYS` (default 7, the watcher's own).** The tool prints
  the query it sends (`recherche : SEARCH SINCE … FROM …`) — built by `ImapMailbox::searchCommand()`,
  the watcher's own — then keeps the newest N by sequence inside that window. Until 2026-09-25 it
  kept the last N sequence numbers of the whole folder, and in a re-labelled Gmail label sequence
  order is not date order: `ops@collective.work` came back 2025-09 → 2026-02 with nothing from that
  year. Set `DUMP_SINCE_DAYS=all` for a deliberate history pull, which brings that caveat back.

---

## Part B — scrubbing it

A raw alert contains your email address, often several times and often encoded. It must be scrubbed
before it can be committed as a fixture.

**It needs `vendor/autoload.php`** — the scrubber shares its decode cascade with the CI guard
(`Scout\Core\RecoverableForms`), so run `composer install` first on a fresh clone. It refuses loudly
with the remedy rather than scrubbing less thoroughly, which is the right direction for a tool whose
success message is what lets a fixture be committed.

```bash
php tools/scrub-eml.php <in.eml> <out.eml> <your-subscriber-address> [needle …]
```

The address argument is optional but pass it explicitly — it is what the tool searches for.

**PASS YOUR NAME AS A NEEDLE TOO, and this is the step whose absence has already cost a leak**
(round-5 panel, 2026-08-31). An alert identifies you two ways: the address, which the tool finds on
its own, and your NAME — in the `To:` display name and in the body's *"ce message est destiné à …"*
line. A display name is not the local part of an address, so `str_replace` can never reach it, and
until 2026-08-31 the tool did not drop `To:` either. Two ParuVendu fixtures shipped a real full name
in plaintext while the tool printed `scrubbed … 0 named identifier(s) replaced` and exited 0.

The `To:`/`Cc:` headers are dropped structurally now, so the header half no longer depends on you
remembering. **The body half still does** — a portal that greets you by name, or states who the
message is for, is only caught by a needle:

```bash
php tools/scrub-eml.php in.eml out.eml me@example.com Prénom NOM monpseudo
```

Needles are matched case-insensitively and each one that fires is counted in the summary line, so
`0 named identifier(s) replaced` on a portal you know greets you by name means the needle was wrong,
not that the message was clean.

**If the tool REFUSES to write, that is it working, not failing.** It decodes every long base64url
run, the quoted-printable form and every base64-encoded body *before* it looks (a base64 body it
can only refuse, never rewrite — such an alert cannot become a fixture with this tool; capture the
`text/plain` or quoted-printable form instead), because *"the address is absent"* is the wrong
test: every Bien'ici link carries a JWT whose payload base64-decodes to your address, and an earlier
version of this tool reported `scrubbed` on a file the address was one `base64 -d` away from. If it
refuses, send me the message it printed — do not edit the file by hand.

---

## Part C — where to put it

Anywhere you like; tell me the path. `var/claude/` inside the repo is gitignored scratch and is the
natural place:

```bash
mkdir -p var/claude/captures
php tools/scrub-eml.php ~/Downloads/whatever.eml var/claude/captures/alcopa-01.eml
```

I will place the scrubbed file into `tests/fixtures/rent/<source>/` under the existing convention —
`YYYY-MM-DD-NNN-<short-slug>.eml`, e.g. `2026-08-26-002-meulan-en-yvelines.eml`. **Captures are
appended, never renumbered**: the number is an identity, and a renumbered fixture silently
invalidates every assertion that names it.

**Send these three things with it**, because two of them are gone once the file is scrubbed:

1. The **`From:` address**, exactly as shown
2. The **subject line**, exactly as shown
3. Roughly **how many listings you can see** in the message when you read it

The third is ground truth. Every fixture in this repo is asserted against a hand-read count, which
is what turns "the parser ran" into "the parser is right" — leboncoin's one-card bug produced a
perfectly plausible listing and only a hand-read count exposed it.

---

## Part D — alerts that do not exist yet

Five rules, each of which silently breaks the pipeline if missed. Rules 1, 2 and 5 fail *invisibly*:
nothing errors, the source simply looks like a quiet market.

1. **Create it on the WEB, never in the portal's mobile app.** An app alert delivers a push
   notification — it reaches your phone and never reaches the mailbox. The watcher then reports a
   calm market for ever. If the app is the only route to the setting, verify the email-delivery box
   is ticked.
2. **Set the frequency to the HIGHEST the portal offers** (AutoScout24 offers 1 h / 12 h / 24 h /
   7 d — take 1 h). A daily digest costs a full day of latency on a market where an underpriced car
   or a good flat is gone in hours. This project's premise is minutes.
3. **Give the search a DISTINCTIVE NAME** — `car-watch-lc`, `rent-watch-pv-idf`. If the portal puts
   the name in the subject, the Gmail filter can match a discriminator **you** control instead of a
   French phrase the portal may stop using — and a narrow filter means anything else from that
   sender lands in your inbox instead of being silently mis-routed. This started as a fallback for
   portals refusing `+tag` addresses; it is now the preferred mechanism.
4. **Never delete an alert email.** See Part A.
5. **Set the portal's filters WIDER than the criteria**, never tighter. The scorer can only rank what
   the portal already accepted; anything the portal rejects is invisible and nothing reports it.

| | Portal search | Our criteria |
|---|---|---|
| **Cars** — price | ≤ 30 000 € | ceiling 30 000, score rewards lower |
| **Cars** — age | ≤ **7** years | score *peaks* at ≤ 5 |
| **Cars** — mileage | ≤ **100 000** km | score *peaks* at ≤ 80 000 |
| **Cars** — fuel / gearbox / body | **leave unset** | score components (decision 11) |
| **Rent** — rooms / surface / rent | 3 / 45 m² / 1 300 € | 3 / 50 m² / 1 200 € CC |
| **Both** — geography | Île-de-France | 8 departement prefixes |

---

## Checklist

- [x] **Alcopa** — captured and read 2026-08-28. **It does NOT carry a closing time**, nor a price, nor a per-lot link, and it shows 3 of 108 results. **Email route REFUSED** under rule 2 of the auction ruling. ~~Polling route still UNRESOLVED~~ **Polling route REFUSED too, 2026-08-29** — the sale pages and the calendar are JavaScript shells; nothing server-rendered carries a price or a closing time (`docs/plans/archive/scout-rename-and-car-domain.plan.md`, 22:00 entry)
- [x] **Alcopa** — calendar reminder ~24/09 to renew the alert before it expires on 27/09 — **set 2026-08-28**
- [ ] **leboncoin `Voitures`** — capture; how many cards for a subject counting 58?
- [ ] **leboncoin `vous propose`** — `From:` header first, then capture
- [x] **ParuVendu** — read 2026-08-28. **The search name is NOT in the subject** — it carries the CRITERIA instead (`🚗 25 nouvelles annonces - Voiture d'occasion / Jusqu'à 30 000 € / A partir de 2019 / Jusqu'à 100 000 km`), so the filter falls back to the sender `info@paruvendu.fr` — which serves the RENT alert too, and the rent alert is currently landing in the car label because of it
- [x] **Agorastore** — captured and read 2026-08-28. **Vehicles only** (`Votre recherche : Voiture`), so no scoping work is needed. But zero prices and zero closing times — same rule-2 refusal as Alcopa — but for the EMAIL ROUTE ONLY: its API host api.auctelia.com is open, so a hydration route could still supply the closing time. It does carry real lot references
- [x] **LinkedIn** (job domain) — captured 2026-09-13 (n=3): cards read from the HTML part, scrubbed into `tests/fixtures/job/linkedin/`
- [x] **Autohero** — captured 2026-08-29 (n=2): one car per message, subject names it. Raw in `var/claude/captures/car-watch-portails/`, not yet scrubbed into `tests/fixtures/`
- [ ] **ParuVendu RENT → car label** — still misrouted on 2026-08-29 (07:30 on the 28th AND the 29th, n=3). The mechanism is ruled (filter on the saved-search NAME, which the subject does not carry — so on the RENT subject `🏠 Nouvelles annonces location` instead); the Gmail filter is an operator action and is not written
- [ ] **AutoScout24** — no alert has fired as of 2026-08-29; only the confirmation (27 Aug) and a newsletter from a different sender (`mails.autoscout24.fr`). Worth one look at the saved search's frequency setting (Part D rule 2)
- [ ] **CapCar** — is the make selector multi-select? (browser only; robots disallows the path)
- [x] **La Centrale** — read 2026-08-28. Richest fields of any car alert (price, mileage, seller type, departement) but **3 cards for a stated 904**, one opaque link host shared with the furniture, an ellipsised title, and no commune. Polling is refused by ruling, so there is no second route
- [ ] Confirm the two leboncoin filters exist as **filters**, not just labels — **PARTLY ANSWERED 2026-08-28: no leboncoin car alert is in `car-watch/portails` over 30 days, so the `Voitures` filter is not routing there.** Remaining candidates are the INBOX and two personal folders, which were not read
- [ ] Verify every alert against Part D rules 1, 2 and 4
