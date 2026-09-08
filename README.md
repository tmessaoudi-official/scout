# scout

A self-hosted watcher for **rental listings in Île-de-France**. It polls institutional landlords,
ingests private-portal alert emails over IMAP, classifies every listing by French housing **tenure
type**, filters and scores it against personal criteria, and pushes a notification within minutes of
publication.

Single language, single user, single machine. CLI plus push notifications — **no web UI**, by design.

> **The one non-negotiable rule:** `logement social` (PLAI, PLUS) is never surfaced as a match. The
> target is **LLI** — *logement locatif intermédiaire*. This is an eligibility fact, not a filter
> preference, and it is enforced by a fail-closed classifier with its own test suite. See
> [`CLAUDE.md`](CLAUDE.md) §1.

## Documentation map

Start here, then follow the one that matches what you are doing.

| You want | Read |
|---|---|
| **the shape of the program** — layers, one pass end to end, the §1 gate, the stores, the health model | [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) |
| **to bring it up, or fix something that is down** — zero-to-running, every verb and flag, symptom→fix | [`docs/RUNBOOK.md`](docs/RUNBOOK.md) |
| **what is polling today**, per domain, and what each source cannot tell us | [`docs/SOURCES-LIVE.md`](docs/SOURCES-LIVE.md) |
| **why a landlord was or was not adopted** — the measurement history | [`docs/SOURCES.md`](docs/SOURCES.md) |
| **how it got here**, dated, and the five failure patterns it kept repeating | [`docs/HISTORY.md`](docs/HISTORY.md) |
| every filter dimension considered, kept or refused | [`docs/FILTERS.md`](docs/FILTERS.md) |
| capturing a portal alert as a committable fixture | [`docs/ALERT-CAPTURE.md`](docs/ALERT-CAPTURE.md) |
| every decision, and the one line that reverses each | [`docs/OPEN-QUESTIONS.md`](docs/OPEN-QUESTIONS.md) |
| the product ruling set | [`spec/PROJECT_BRIEF.md`](spec/PROJECT_BRIEF.md) |
| the rules Claude works under here | [`CLAUDE.md`](CLAUDE.md) |

The rest of this file is the **narrative**: what each deployment step is for and which incident
produced it. The runbook is the checklist; this is the reasoning behind it.

## Status

**It runs. Eight sources are live: four institutional landlords and four private portals.**

> **Corrected 2026-08-29.** This section said *six* sources and *schema v8* from 2026-08-25, and
> listed the transit layer and classifier tier 4 as "genuinely absent" — all four claims had been
> false since 2026-08-26, when leboncoin and PAP went live (sources #7 and #8), `src/php/Rent/Enrich/`
> landed with the IDFM/PRIM commute component, and the `plafonds` figures were fetched and armed.
> The store is at **schema v12** (v9 commute cache, v10 destination fingerprint, v11 feed
> freshness, v12 the cross-track twin fact). The per-source detail lives in `CLAUDE.md`; this page
> is the summary and it drifted.

> That paragraph replaced one saying *"the pure core and the store are built, nothing else is —
> there is no adapter, no notification channel and no CLI yet"*, which had been false for weeks.
> `.env.example`'s own header carries the ruling: a stale *"this is only a sketch"* notice on a live
> thing is worse than no notice at all.

`scout --domain=rent run --watch` polls **In'li, CDC Habitat, Cityloger, Logirep, SeLoger, Bien'ici, leboncoin
and PAP** on the Q37 cadence, hydrates detail pages behind a novelty gate, classifies every listing
by tenure, enriches it with a door-to-door commute, scores it, and pushes what matches. `doctor`,
`dump`, `run --once/--seed/--watch`, `test-notify`, `digest` and `reclassify` all work end to end.
The store is at schema v12 (v12: the cross-track twin fact), and `doctor` now also says when a portal's feed has gone silent behind a
steady count (`FEED_SILENT`, 2026-08-28).

**SeLoger went live on 2026-08-25** — the first Tier B portal and the first source that is not a
landlord. It ingests alert emails over IMAP rather than scraping, which is hard rule 4's primary
path and not a workaround: SeLoger answers **403 with a DataDome challenge on every route**, the
bare homepage included. `scout --domain=rent doctor --source=seloger` returns 9 annonces against the live mailbox;
`MAILBOX_DIR=tests/fixtures/rent/seloger scout --domain=rent doctor --source=seloger` runs the same path against two
real frozen alerts with no credentials at all.

> Pointing it at a real mailbox found two defects that 1 900 green tests had not. Four of the first
> nine matches were **coliving rooms** advertised with the whole flat's rooms and surface, so every
> numeric filter passed; and every listing arrived with **no commune name** while its postcode parsed
> fine, because the only reader was a scan over the *ranked* communes — a region-wide watch knows
> almost none of them. Both are fixed, and both are recorded in `docs/SOURCES.md` § B1 as rules for
> the next portal rather than as one source's bugs.

**Bien'ici followed hours later**, and it is much the easier of the two: it publishes a real listing
id in the URL path, so identity is the link and none of SeLoger's content-addressing is needed.
`scout --domain=rent doctor --source=bienici` returns **13 annonces in 731 ms**, and a seeded pass matches **10 of
13** — by far the best hit rate in the tree, because the portal applies the saved search's criteria
before sending and those criteria mirror `criteria.json`.

> It also cost a P0 in `tools/scrub-eml.php`, and the lesson is general: **"the address is absent"
> is the wrong test — the right one is "the address is not RECOVERABLE".** Every Bien'ici link
> carries a JWT whose payload base64url-decodes to the subscriber's address, so the scrubber
> verified an absence that was true and wrote a file from which the address was one `base64 -d`
> away. It now decodes before it looks. `tests/test-scrub-eml.sh` is the must-strip / must-refuse /
> must-stay-quiet proof.

**What is genuinely absent, as of 2026-08-29:** AL'in (needs an authenticated capture, and is parked
on a §1 question — see `docs/plans/archive/scout-rename-and-car-domain.plan.md`), a VPS host to deploy to
(the watcher runs on the developer's machine), the second domain — a car watcher, designed and ruled
in that same plan but not built — and `src/phorj/` (on indefinite hold). The transit layer and tier 4
were absent when this paragraph was first written and are not any more.

| Path | What it is |
|---|---|
| [`spec/PROJECT_BRIEF.md`](spec/PROJECT_BRIEF.md) | The full specification — the source of truth |
| [`docs/OPEN-QUESTIONS.md`](docs/OPEN-QUESTIONS.md) | **Start here.** Every filter enumerated, and the decisions still owed |
| `src/php/Core/` | The tenure classifier, the models it works on, and the source-health verdict |
| `src/php/Rent/Store/` | The SQLite seen-set, price history and run log. Deleting the database re-notifies the entire market on the next run, and the price history cannot be reconstructed — a listing only ever shows its *current* rent |
| `tests/fixtures/rent/tenure/corpus.json` | 130 hand-labelled listing texts — the classifier's ground truth, and language-neutral so the phorj port reads the same file. **122 synthetic + 8 captured**: `spec/PROJECT_BRIEF.md` §4 asks for *real* texts, and they arrived from 2026-08-20 with the live sources (CDC Habitat card text, Cityloger detail prose — including the corpus's first captured SOCIAL case — and Logirep, whose second capture is the site's own FILTER FACET STRIP, containing `PLAI` inside `Plain-pied`, `LLI` inside `Ce·lli·er` and `PLUS` inside `plusieurs`). Every case declares its `provenance` and a test asserts the counts, so the remaining gap is data rather than a promise |
| `prototype/` | A pre-existing single-file prototype, kept as reference. **Not** the shipping implementation — it has no tenure classifier at all |
| [`CLAUDE.md`](CLAUDE.md) | How code gets delivered here: rules, gates, the eligibility boundary |
| `.claude/` | Claude Code configuration ([details](CLAUDE.md#claude-config-in-this-repo)) |

## One program, several domains

`scout` is one watcher with a domain switch. Every verb takes `--domain=<slug>` — there is NO
implicit domain, on purpose: the shape this replaced had rent implicit and `--domain=car` as a
special case, so a deployment that forgot the flag watched the wrong domain against the wrong
database with a green heartbeat. `bin/scout help` lists the registry; `bin/scout --domain=rent help`
lists that domain's verbs.

Everything a domain owns follows ONE scheme, so the next domain is one registry entry
(`src/php/Cli/Domains.php`) plus its own tree:

| What | rent | car | the rule |
|---|---|---|---|
| namespace | `Scout\Rent\…` | `Scout\Car\…` | `Scout\<Slug>\…` over a generic `Scout\Core` / `Scout\Adapters` / `Scout\Cli` |
| CLI | `Scout\Rent\Cli\RentScout` | `Scout\Car\Cli\CarScout` | `--domain=<slug>` dispatches to it |
| config | `config/rent/` | `config/car/` | `criteria.json` + `sources.json` (+ gitignored `criteria.local.json`) |
| fixtures | `tests/fixtures/rent/<source>/` | `tests/fixtures/car/<source>/` | under the domain that reads them |
| env keys | `RENT_SCOUT_DB`, `RENT_IMAP_MAILBOX`, `RENT_NTFY_TOPIC`, `RENT_HEARTBEAT_HOURS`, `RENT_FEED_SILENT_DAYS` | `CAR_*` | `<SLUG>_*`; the IMAP/SMTP account, `NTFY_SERVER`, `IMAP_SINCE_DAYS`, `IMAP_MAX_MESSAGES`, `TZ` are shared |
| database | `state/rent-watch.sqlite3` | `state/car-watch.sqlite3` | `state/<slug>-watch.sqlite3` |
| markers | `state/rent-heartbeat.txt`, `rent-digest.txt`, `rent-last-refusal.txt` | `state/car-heartbeat.txt`, `car-rollup.txt`, `car-last-refusal.txt` | `state/<slug>-*.txt` |
| mailbox label | `rent-watch/portails` | `car-watch/portails` | `<slug>-watch/portails` |
| push label | `rent-watch` | `car-watch` | `<slug>-watch` leads every subject and title |
| ntfy topic | `rw-<32 hex>` | `cw-<32 hex>` | `<initial>w-<32 hex>`, `openssl rand -hex 16` — the topic IS the secret |
| compose service | `rent-scout` | `car-scout` | the flag sits in the service's ENTRYPOINT, so `docker compose run --rm car-scout doctor` is a car verb |

The generic layer is what no domain owns: `Text`, `Redact`, `Pacer`, `Heartbeat`, source health,
the notification channels and transports, the HTTP and IMAP clients, `WatchLoop`, `ChannelFactory`.
Its CODE names no domain (no `use` of a `Scout\Rent\…` or `Scout\Car\…` class), and
`ScoutDispatchTest` pins that the usage text is generated from the registry rather than typed beside
it; its user-facing messages speak of `<SLUG>_*` keys and `config/<domain>/` paths rather than
enumerating domains by hand.
## Getting started

```bash
composer install            # generates the PSR-4 autoloader. There are no runtime dependencies.
php tools/phpunit.phar      # the core suite
```

`tools/phpunit.phar` is gitignored; fetch it once with:

```bash
bash tools/fetch-phpunit.sh    # downloads and checks a pinned SHA-256; refuses to install on a mismatch
```

**Why a PHAR rather than a Composer dev dependency.** This project runs in a container whose egress
policy blocks GitHub dist downloads (`codeload.github.com` → 403), so Composer falls back to full
`git clone`s. Installing PHPUnit that way produced a **2.6 GB `vendor/`** for a test runner. With the
PHAR, `vendor/` is 56 KB of generated autoloader and the runner is one 6 MB file. The project has
**zero Composer dependencies** by design.

`fetch-phpunit.sh` pins the SHA-256 and refuses to install on a mismatch. It ALSO pins the signing
key and verifies the published PGP signature — **but only where a keyserver is reachable, which it is
not from this container**, so in practice the SHA-256 pin is what runs and the script says so on
stdout. Read its header before trusting it: the pin was established trust-on-first-use, making it a
continuity check rather than a root of trust.

Two checks that are not the test suite, and matter more than it does here:

```bash
bash tests/sabotage-check.sh       # breaks the classifier and the store many ways; the suite must catch every one
bash tests/test-tenure-guard.sh    # the §1 tripwire still fires, and stays quiet on ordinary PHP
```

Every failure mode in the tenure module is *silent* — a classifier that over-rejects is
indistinguishable from a quiet rental market. The store is the same shape: a seen-set that stops
persisting, a price history that stops recording, a run log that reports a dead source as calm. A
green suite proves the code passes the tests; only the sabotage run proves the tests would notice if
the code stopped working. It has earned that twice over — a three-lens review panel found 25 defects
in the store's first cut, and five of its guarantees were shown untested by running this very script
against them.

## Deploying it

Ruled by Q8: **Docker on a VPS, `state/` on a mounted volume, `scout --domain=rent run --watch` owning its own
schedule.** Not cron — the process keeps its jitter and a continuous run log. Not GitHub Actions,
explicitly: no persistent disk means no seen-set, which means re-notifying the entire market on
every run.

```bash
cp .env.example .env && $EDITOR .env      # a push channel — or nothing ever reaches you
docker compose run --rm rent-scout doctor      # sources reachable? journal WAL? channels usable?
docker compose run --rm rent-scout run --once --seed
docker compose up -d
```

**The seed step is not optional.** An empty seen-set makes `run` refuse (Q36), because an empty
seen-set is exactly what a forgotten volume mount looks like and the alternative is notifying the
entire back catalogue at once. With `restart: unless-stopped` that refusal becomes a restart loop —
visible, since Q27 records it to `state/rent-last-refusal.txt` and reports it on the next successful
start, but still a loop. Seed first and it starts clean.

**If you drive it with cron instead — `--once` — you owe two scheduled verbs.** The daily floors
live inside the watch loop, so a `--once` deployment has the event-driven paths and no floor at all:
the *à vérifier* bin and the *vérifié, score bas* queue both fill and nothing empties them. Add them
to the same cron that runs the pass:

```cron
30 8 * * *  cd /srv/scout && docker compose run --rm rent-scout digest
35 8 * * *  cd /srv/scout && docker compose run --rm car-scout  rollup
```

Both are safe to run when there is nothing pending — they say so and send nothing. Every `--once`
pass that holds a match back names the verb it is waiting for, and `doctor` says the floor is
`--watch` only, on both domains.

**File ownership is the one thing that bites on a first deploy.** `state/` is bind-mounted from the
host, so it belongs to whoever created it, while the container runs as its own uid. Compose defaults
to `1000:1000` — the ordinary first user on a Debian/Ubuntu VPS. If yours differs:

```bash
SCOUT_UID=$(id -u) SCOUT_GID=$(id -g) docker compose up -d
```

Get it wrong and the refusal now says so by name (`base de données inutilisable (…) : le volume
est-il monté et accessible en écriture…`) instead of dying with a stack trace, which is what it did
before this was measured against a real container.

**Redeploying after a code change — `src/` is baked into the image, `config/` is not.** The compose
file mounts `./config` read-only and `./state` read-write; everything else the watcher runs comes
from the image. So a `git pull` on the host changes the criteria and the source definitions
immediately, and changes **no code at all** until the image is rebuilt. Measured 2026-08-23: a
watcher was still running the previous day's build seventeen hours after its replacement was
committed and pushed, and the tree was clean and green throughout. Green, pushed and deployed are
three different things.

```bash
docker tag scout:local scout:pre-<what-you-are-leaving>   # rollback, one retag away
docker compose build                                                # BEFORE stopping: a failed
                                                                    #   build must not leave you down
sqlite3 state/rent-watch.sqlite3 ".backup /tmp/mig-rehearse.sqlite3"
php -r 'require "vendor/autoload.php"; $s=Scout\Rent\Store\Store::open("/tmp/mig-rehearse.sqlite3");
        echo $s->schemaVersion()," ",$s->journalMode(),PHP_EOL;'    # rehearse the migration
docker compose stop                                                 # graceful; finishes the pass
sqlite3 state/rent-watch.sqlite3 ".backup state/rent-watch.sqlite3.pre-<v>.$(date +%s).bak"
docker compose up -d --remove-orphans   # --remove-orphans matters ONCE after 2026-08-30: the rent service was
                                        # renamed scout → rent-scout, and compose leaves the old container RUNNING
                                        # beside the new one otherwise — two rent watchers, every push twice
bash tools/verify-deploy.sh             # and this is the step that says whether any of it landed
```

**`up -d` printing `Started` is not a deployment, so check it.** Both watchers set
`stop_grace_period: 5m` and the loop stops only after the pass in flight finishes, so a recreate can
sit for minutes; compose renames the old container while it waits, and twice on 2026-08-31 that
wedged — once failing outright on `Conflict. The container name … is already in use` with rent-scout
left in `Created`, once sitting about thirteen minutes. **Neither announced anything**, because
`docker compose ps` without `-a` omits a service that is not running: the failure renders as a
shorter list. `tools/verify-deploy.sh` asserts the four things that output cannot show you — every
declared service has a container and it is running, that container runs the *current* image rather
than one from three deploys ago, **that the image itself was built after the newest `src/` commit**,
and no hex-prefixed leftover is still holding a name that will kill the next recreate. It is
read-only, and `tests/test-verify-deploy.sh` is its sabotage test.

The third of those is a different question from the second, with the same comforting output. The
second asks whether the containers run the image you built; the third asks whether that image was
built from the code you committed. On 2026-09-04 a §1 fix was committed, pushed, CI-green and
unarmed in production for a day and a half while every container reported *running, image courante*
throughout — and an earlier instance of the same shape ran seventeen hours.

The rehearsal step is there because the seen-set is the one file this project documents as
unrecoverable, and a schema migration is the only routine operation that rewrites it. `.backup` is
safe against a running writer and checkpoints the WAL, so the copy is a real one; opening it with
the new code offline proves the migration chain runs against *this* data rather than against a test
fixture. Compare row counts on both sides — a migration that silently drops the price history looks
exactly like a successful one.

**A redeploy that adds a hydration gate reads as a collapse in matches, and that is the gate
working.** The same 478 listings went from `83 correspondance(s), 9 à vérifier` to
`29 correspondance(s), 63 à vérifier` across the 2026-08-23 upgrade, because a source-default match
is now withheld until the listing's own page has been read. The backlog drains at
`detail_budget_per_pass` per source per pass; matches climb back over the following passes. Nothing
already notified is re-notified — that is the seen-set's job and it survives the migration.

**What is in the image, and what is not.** No test suite, no `tools/phpunit.phar`, no `.env` — an
image layer is a distributable artifact and a baked-in credential survives any later layer that
deletes it. The demo fixture *is* shipped, and it lets a fresh VPS prove the whole
pipeline before a single landlord is polled — but it is `enabled: false` as of 2026-08-22, so it
takes an explicit `scout --domain=rent doctor --source=fixture_demo` to run. It shipped ENABLED for two weeks,
from before any real endpoint existed, and a real pass therefore counted ten flats that do not
exist in its totals, its `doctor` table and its heartbeat.

**There is deliberately no Docker `HEALTHCHECK`.** Q27's heartbeat is the liveness mechanism and it
reports through a channel you actually read; a container healthcheck polling the marker file would
fight the 24-hour interval and report to a dashboard nobody is watching.

**Back up `state/rent-watch.sqlite3`.** Deleting it re-notifies everything, and the price history
cannot be reconstructed — a listing only ever shows its *current* rent.

```bash
tools/backup-state.sh                       # → state/backups/rent-watch.<stamp>.sqlite3
0 4 * * *  cd /srv/scout && tools/backup-state.sh   # a daily crontab line
```

**Do not use `cp`, and the reason is silent.** The watcher holds the database open in WAL mode, so a
byte copy taken mid-transaction is torn — and a torn SQLite file **opens without complaint and
reports a plausible row count**. You find out at restore, which is the one moment you cannot afford
to. `tools/backup-state.sh` uses SQLite's own online-backup API, which is safe against a live writer
and checkpoints the WAL, then **reads the copy back** (`integrity_check` plus a row count) before
reporting success — a backup nobody opened is a file, not a backup. It keeps 7
(`SCOUT_BACKUP_KEEP`), pruning oldest-first, because an unbounded backup directory fills the
VPS disk and takes the live seen-set down with it. Restoring is a move: stop the container, put the
file at `state/rent-watch.sqlite3`, start it.

### Moving the watcher to another host

Three files carry the deployment, and only one of them is in git.

| Carry across | Why |
|---|---|
| `state/rent-watch.sqlite3` | The seen-set. **Without it the new host re-notifies the entire market on its first pass** — hundreds of pushes for flats you have already read and dismissed. |
| `.env` | Credentials: IMAP, the push channel, the IDFM key. Gitignored, so a `git clone` does not bring it. |
| `config/rent/criteria.local.json` | Gitignored too, and it is what turns commute scoring on — a clone without it silently scores every listing without the heaviest component in the tree. |

```bash
tools/backup-state.sh                                     # take a verified copy FIRST
rsync -av state/backups/rent-watch.<stamp>.sqlite3 \
          .env config/rent/criteria.local.json  <host>:/srv/scout/…
ssh <host> 'cd /srv/scout && docker compose build && docker compose run --rm rent-scout doctor'
```

**`doctor` before `up -d`, always.** It is the one command that proves the new host can reach the
sources *and* the notification channel before anything is scheduled — and `docker compose run --rm
scout --domain=rent test-notify` proves the channel specifically, which `doctor` alone does not.

**Do NOT re-run `--seed` on the new host if you carried the seen-set.** Seeding marks everything
currently published as already seen, so a genuine new listing that appeared during the move would be
swallowed silently. Seed only on a genuinely fresh start.

> **One trap, paid for on 2026-08-26.** `Config\DotEnv` applies the **first** occurrence of a key and
> skips every later one, and an empty string counts as set. Appending `IDFM_API_KEY=…` to a `.env`
> that already carries the empty template line leaves the real key permanently unread, and the API
> answers `{"message":"No API key found in request"}`. **Edit the line in place; never append a key
> that already has a template line.**

## Notifications — turning a channel on

Q9 rules every channel optional and `console` always available, so the stack starts and says what it
can and cannot reach rather than refusing to parse. A channel is turned on in **two** places and
neither alone is enough: it is listed under `notify.channels`, and its credentials are in `.env`. A
channel listed without its credentials is **disabled loudly** at startup (`⚠ canal ntfy désactivé :
the ntfy topic is not set (RENT_NTFY_TOPIC or CAR_NTFY_TOPIC, per domain)…`) — never silently, because hard rule 2 counts an alert computed and never
sent as worse than no alert at all.

> **⚠ `console` is not a channel, and neither is `email` over `SMTP_TRANSPORT=file`.** Both write
> to this machine and reach nobody, so **neither counts as a delivery**: a run whose only channels
> are those announces every match to the terminal and marks NOTHING notified, which means it
> re-announces the same listings on every pass and never writes the heartbeat marker. It is not
> refused — `run --once` at a terminal is exactly that shape — but it warns at startup, `doctor`
> says `AUCUN canal n'atteint de destinataire`, and **`scout --domain=rent test-notify` exits 1**.
>
> This matters because the SHIPPED `config/rent/criteria.json` is `channels: ["console"]`. A deployment
> that never adds a real channel in `criteria.local.json` looks healthy and delivers nothing. Run
> `scout --domain=rent doctor` after any deploy: it now lists every channel, what it is, and whether it counts.
>
> Two review rounds were spent on this. Round 7 found `console` satisfying every "did it reach the
> user" gate; the fix filtered it out **by name**, and round 8 found `email` over a file transport
> walking through the same hole. It is a capability now — `Channel::reachesRecipient()` — asked
> once, on the interface.

Put the channel list in **`config/rent/criteria.local.json`**, which is gitignored, rather than in the
committed `config/rent/criteria.json`. Compose mounts `./config` into the container, so it reaches the
deployment; and the committed file stays free of anything that would make a fresh clone, a CI job or
the test suite try to push somewhere real.

```json
{
  "notify": { "channels": ["console", "ntfy", "email"] }
}
```

### ntfy — push to a phone, no account

Install the ntfy app, subscribe to a topic, and put the same topic in `.env`:

```bash
RENT_NTFY_TOPIC=rw-<something long and random>     # openssl rand -hex 16
NTFY_SERVER=https://ntfy.sh
```

**The topic IS the credential.** ntfy has no accounts: anyone who knows the string reads every
notification, so generate it rather than choosing it, and keep it out of anything committed.
`Redact` masks it in error text on the way to the store.

### email — the SMTP keys, and what they mean

`SMTP_TRANSPORT` chooses how mail leaves, and the interesting value is the one that sends nothing:

| value | behaviour |
|---|---|
| *(empty)* | `smtp` when `SMTP_HOST` is set, `sendmail` otherwise |
| `smtp` | speak SMTP directly, with the credentials below |
| `file` | write real `.eml` files to `MAIL_OUTBOX` and send NOTHING |

`file` is not a stub. The messages are complete and readable, which is how `scout --domain=rent test-notify`
proves the whole email path offline with no server and no credential — and it is the right setting
to leave configured while you have not got round to the rest.

**With Gmail** (any provider works; Gmail is the one with an extra step). Google refuses a plain
account password over SMTP, so it needs an *app password*, and an app password needs 2-step
verification switched on first:

1. **myaccount.google.com** → **Sécurité** → turn on **Validation en deux étapes**.
2. Same page, or `myaccount.google.com/apppasswords` → create one, name it `scout`.
3. Google shows a **16-character password once**. Copy it before closing the box — it is not
   retrievable afterwards, only replaceable.

Then in `.env`:

```bash
SMTP_TRANSPORT=smtp
SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_SECURITY=starttls
SMTP_USER=you@gmail.com
SMTP_PASSWORD=<the 16 characters, spaces removed>
SMTP_FROM=you@gmail.com
SMTP_TO=you@gmail.com
```

`SMTP_SECURITY=starttls` is not a preference: the transport **refuses to authenticate on a
connection the server did not upgrade**, and that refusal is proven by a sabotage case against a
scripted loopback server and its wire transcript. Sending yourself mail from your own address is
normal and Gmail delivers it.

Then verify. **The exit code is now the guarantee**, and this section said the opposite for as
long as it existed — *"verify the delivery, not the exit code"* was written when a console print
satisfied `test-notify`, so the exit code meant nothing. It means something now: 0 only when a
channel that can actually reach you accepted the message.

```bash
scout --domain=rent test-notify              # 0 = a real channel accepted it. 1 = nothing that counts did.
scout --domain=rent doctor                   # lists every channel, what it is, and whether it counts
ls -t var/outbox | head -1     # `file` transport: the message that was written and NOT sent
```

> **`bin/scout` loads `.env` itself** (2026-08-22), so the host and the container read the same
> file the same way. Do **not** use `set -a; . ./.env; set +a`, which this file recommended for one
> afternoon: that is the SHELL, and it executes the file rather than parsing it. A Gmail app
> password pasted with the spaces Google displays it with — `abcd efgh ijkl mnop` — is not an
> assignment to bash but a one-command environment prefix, so the variable is never exported *and*
> bash runs the rest as a command. Observed: the CLI reported `SMTP_PASSWORD is empty` while the
> file plainly held one, and four characters of a live credential were printed to the terminal by
> `command not found`. A value containing backticks would have been executed.
>
> The parser takes values literally — no expansion, no substitution — and **the real environment
> still wins**, so `RENT_SCOUT_DB=/tmp/throwaway bin/scout --domain=rent run` works as before. A line that is not
> `KEY=VALUE` is a startup refusal naming the line NUMBER and never the line, because this file
> holds credentials.

## Why this exists

Nothing on the market aggregates institutional LLI stock, and most of these landlords do not even
aggregate their own. In'li, CDC Habitat and Cityloger (the Immobilière 3F group's lettings platform)
publish real feeds, two of the three mixing social and intermediate stock on the same page — and good
LLI units are gone within hours. Seqens, 3F's corporate site, ICF Novedis, 1001 Vies and Batigère
publish **no availability at all** on their own domains: they route applicants to AL'in or to the SNE.
`docs/SOURCES.md` carries the measurement behind each row. The private portals
(SeLoger, Leboncoin, Bien'ici, PAP, Logic-Immo) are well covered by their own alert emails, so this
tool ingests those rather than scraping them.

## Roadmap

Per `spec/PROJECT_BRIEF.md` §13. Each milestone ships working — no big-bang integration.

Built out of order on purpose: the classifier needs nothing from anyone, while every other milestone
is blocked on a mailbox, an endpoint capture or a phorj module that does not exist yet.

1. **Core skeleton** — models ✅, SQLite store ✅ (seen-set, price history, run log, source health),
   config loading, CLI, one notification channel. Proven end-to-end with a fake source.
2. **Tenure classifier + tests.** ✅ **Done in PHP**, against a 130-case corpus (122 synthetic, 8
   captured — the captures arrived with the live sources from 2026-08-20; append, never renumber).
   Tier 4 (`plafonds` bands) armed 2026-08-26. The phorj port waits on `Core.Imap`, an HTML parser
   and `sleep` (see `docs/PHORJ-REQUIREMENTS.md`). Before any real source; everything depends on it.
3. **In'li adapter** ✅ live 2026-08-19 — and hydrating its detail pages proved it is NOT pure LLI.
4. **Health monitoring + `scout --domain=rent doctor`.** ✅ — `SourceHealth`, the Q27 heartbeat (2026-08-22) and
   `FEED_SILENT` (2026-08-28), which is the one verdict the original design could not express.
5. **CDC Habitat** ✅ live 2026-08-20 — the first mixed-tenure source.
6. **Email-alert adapter + one private portal.** ✅ SeLoger 2026-08-25, then Bien'ici, leboncoin
   and PAP within a day of each other. Every one was shaped by a real message, never by a guess.
7. **Remaining institutional sources.** ✅ as far as the catalogue allows: Cityloger and Logirep are
   live; every other row in `docs/SOURCES.md` Track 1 is measured dead or refused, and AL'in is the
   one input still owed.
8. **Cross-portal dedup, transit enrichment, scoring refinement.** ✅ schema v4 group (2026-08-19),
   `Enrich/NavitiaCommute` (2026-08-26), commute curve + `high_priority_score` calibration the same
   day.

## Planned CLI

```
scout --domain=rent doctor                  # health-check every source: status, timing, item counts
scout --domain=rent dump <source>           # raw payload of the first item — for building field maps
scout --domain=rent run --once [-v]         # single pass
scout --domain=rent run --watch [-v]        # loop: every 15 min ± 5 of jitter, paced per host (Q37)
scout --domain=rent test-notify             # verify the notification channel
scout --domain=rent digest [--dry-run]      # emit the pending "à vérifier" rollup AND the "vérifié, score bas"
                                            #   section (matches under push_min_score), on demand
scout --domain=rent reclassify [--dry-run]  # re-judge stored UNKNOWN verdicts against today's classifier
scout --domain=rent reclassify --reopen=<dedup_key>   # the ONE way back for a durably-excluded row: prints where
                                            #   the exclusion came from (4 routes), clears the row's own + twin
                                            #   readings, re-judges it. The group veto AND the same dwelling
                                            #   under another ad id are reported, never cleared — each lives on
                                            #   another row, so this is not a universal undo
scout --domain=rent replay <source>         # alias of `dump` — takes a SOURCE NAME
scout --domain=rent replay <source> --file=<payload>   # a frozen page through that source's own field map, offline
```

`scout --domain=rent replay <source>` bare is an ALIAS OF `dump`, and takes a SOURCE NAME — not a fixture path.
This line said `<fixture>` for as long as the verb existed, and `scout --domain=rent replay
tests/fixtures/rent/inli/search.html` answers *"source inconnue"* and exits 2. A three-way disagreement,
since the verb was also absent from `scout help` entirely: the spec asked for one thing, the code
did another, and the tool's own help denied the verb existed.

**`--file=<payload>` is the half the spec actually asked for, built 2026-08-29.** It runs a frozen
page through a NETWORK source's own adapter and field map — gate, hydration, classifier, the same
path a real `dump` takes — with **no request made**: the client answers the search URL (and its
paginated forms) with the file, `/robots.txt` with 404 (= allow), and everything else with 404, so
a `detail_map` is never handed the search page as a listing's detail page. It runs against a
**throwaway database** — `dump` hydrates through the detail cache, so a replay against the real store
would record one fetch-failure row per listing for pages nobody fetched — and **unthrottled**, since
there is no host to protect (with `rate_limit_ms` kept, In'li's replay spent 43 s asleep). An
`email_alert` source is refused with the seam that already exists for it: `MAILBOX_DIR=<dir of .eml>
scout --domain=rent dump <source>`.

`scout --domain=rent digest` reads the STORE, not the last pass, and that is the difference that makes it worth
having: the pipeline already re-offers an undelivered digest entry next run, but only while the ad
is still published, so an entry whose listing is delisted in between is lost with nothing saying so.

There are **three** emission paths for that rollup, and the third landed 2026-08-26. The pipeline
emits at the end of any pass that produced NEW entries; `scout --domain=rent digest` emits on demand; and
`scout --domain=rent run --watch` carries a **daily floor** at `digest_hour` local, which drains whatever is still
pending. The floor is what reaches a backlog that failed to send, or one the batch cap left behind,
on a day that produced nothing new — before it existed, such a backlog sat in the bin until somebody
thought to look, and that bin is where every listing the classifier could not resolve lands.

**Since 2026-09-05 the digest has a SECOND SECTION, and the two are never mixed.** A listing that
MATCHED but scored under `notify.push_min_score` (rent: 55, the measured p90 of 1 046 stored
matches) is not pushed on its own: it waits in the store, and **two** drains empty that queue —
`scout --domain=rent digest` and the daily floor, which is `--watch` only. The end-of-pass emission
is NOT one of them: a pass announces the *à vérifier* entries it produced and QUEUES the low-score
matches, so a cron-driven `--once` deployment must schedule the verb itself (see § Deploying it) or
nothing ever announces them. Both drains announce the queue under its own heading, *« vérifié, score
bas »*, below the *« à vérifier »* tenure doubts. The title claims the regime clause only when a
tenure doubt is present; a rollup-only mail is titled *« Vérifié, score bas : N annonce(s) »*. The
store records the announcement kind (`DIGEST < ROLLUP < MATCH`, monotone), so a rent drop that
lifts a rolled-up flat over the line is pushed once, as a promotion, and a flat already pushed is
never demoted. Remove the key to push every match individually again. Every pass says how many
matches it held back — and names the drain that will empty it, which differs by run mode — and
`doctor` prints the gate, the queue and the floor's `--watch` scope as `rollup :`, on both domains.

**A queued row is not always a low score.** The queue is *matched, and nobody was told* — which is
also what a push that FAILED leaves behind, and on a deployment with no gate configured it is the
only thing it can be. So both drains re-score each row and split it: at or over the line (or with
no gate at all) it is pushed as the individual match it is and recorded as `MATCH`; only a row that
really fell short is rolled up. A retry the channel refuses is left exactly where it was.

**On a day with nothing pending the floor says nothing at all**, and records no window as served.
The heartbeat already proves the watcher is alive every 24 h, so a daily "rien à vérifier" push
would carry no new information and would train you to swipe the channel away; and leaving the window
open means a send that failed at 08:05 is retried on the next pass rather than tomorrow. It runs
under `--watch` only — a cron-driven `--once` deployment gets the two event-driven paths and no
floor — and `scout --domain=rent doctor` prints the hour, the resolved local zone and that scope.

`scout --domain=rent reclassify` re-runs the classifier AND the criteria engine over stored `UNKNOWN` verdicts,
using the schema-v7 snapshot of the listing the verdict was formed from. A row stored before v7 has
no snapshot and is **skipped, not judged on whatever text remains** — re-judging on less evidence
than the original saw is how a social listing becomes a match. `--reopen=<dedup_key>` (2026-09-05)
is the one way back for a row whose excluded reading came from an over-link or an over-merge: it
names the provenance, clears the row's own and twin readings, and re-judges on the same
invocation — never a pattern, never "all". `--since` is deliberately refused;
see `docs/plans/archive/finish-everything.plan.md` for why.

A listing whose dedup CLUSTER holds an excluded tenure is skipped for the same reason and counted
out loud. The pipeline judges a cluster on its most restrictive member but stores each member's own
tenure and own snapshot, so a vetoed survivor looks merely undetermined — and re-judging it on its
own snapshot alone is exactly the `⊂` this command forbids.

`scout --domain=rent dump` is what makes onboarding a new source take five minutes instead of an hour, so it lands in
milestone 1.

Two obligations on `doctor`, both of which are silent when forgotten: it must pass the current time
to `Store::health()` — without a clock the `STALE` verdict cannot fire at all — and it must print
`Store::journalMode()`, because WAL can be refused on a network mount and a store in rollback-journal
mode makes two processes contend instead of share.

## Adding a source

Adding a source is **config-only** in the common case — a block in `config/rent/sources.json`, no code. The
[`/add-source`](.claude/skills/add-source/SKILL.md) skill walks the whole workflow: live-endpoint
discovery, field-map building with `scout --domain=rent dump`, fixture capture, tenure labelling, and the health
baseline.

Two rules that matter more than the mechanics:

- **Never write an endpoint from memory.** Verify it against the live site. Unverified URLs read
  `REMPLACER` and their source stays `enabled: false`.
- **`mixed_tenure: true` is the default.** Only a provably pure source (In'li) may be `false`. Getting
  it wrong disables the fail-closed rule for that source.

### Adding an EMAIL source

Same idea, different pre-checks — and an HTTP status tells you nothing here, because the route is a
mailbox rather than a page. Subscribe a dedicated mailbox to the portal's own alert, save one
message, and capture it:

```bash
php tools/dump-eml.php <sender@portal> 5 var/claude/captures '<gmail label>'   # optional: several at once
php tools/scrub-eml.php captured.eml tests/fixtures/rent/<portal>/alert.eml you@example.com
RENT_SCOUT_DB=$(mktemp -u) MAILBOX_DIR=tests/fixtures/rent/<portal> \
  php bin/scout --domain=rent doctor --source=<portal>   # force-runs a disabled source
```

The **throwaway `RENT_SCOUT_DB` is not optional**: `MAILBOX_DIR` swaps the mailbox and not the
database, so a fixture-backed run otherwise writes its item count into the live store, where it
joins the 7-day baseline every real run is judged against. That produced a `SOURCE_BROKEN` alert on
a premise made entirely of fixture data.

`tools/dump-eml.php` is the faster route to Gmail's *Download original* when several messages from
one sender are needed at once. It is read-only at the protocol level (`EXAMINE`, `BODY.PEEK[]`), it
**refuses to write anywhere under `tests/`** — its output is raw and carries the subscriber's
identity, so the scrubber below is still owed — and its folder argument matters: an alert routed to
a Gmail label is archived out of `INBOX`, where a search finds nothing and says so in words that
read exactly like a portal that has sent nothing.

`tools/scrub-eml.php` removes the subscriber's identity — the address, the bounce and reply tokens,
the ESP feedback ids, the one-click unsubscribe token, every `qs=` tracking value — and **refuses to
write** while any of them survives. It deliberately keeps the message *ugly*: the MIME preamble, the
awkward boundary, the folded headers and the RFC 2047 subject split mid-word are the ground truth,
and three of them were live parser defects until a real alert exposed them.

Then check four things, in this order, before writing a field map:

1. **Does the alert carry a real listing URL?** SeLoger's does not — every link is a tracking
   redirect with an opaque per-recipient token, and stripping the query collapses every listing in
   the message onto one identity. That answer decides the whole block (`id_from: content` vs the
   default `link`).
2. **Does the `text/plain` part carry undecoded HTML entities?** If so the parser must decode them,
   or `logement conventionn&eacute;` folds to `logement conventionn` and the label is destroyed.
3. **Does each card end in a consistent call to action?** That string is `card_separator`. Without
   one, every link becomes a listing carrying the first rent in the whole message.
4. **Do not follow the tracking redirect to recover a URL.** It is a request per listing on a
   subscriber-bound token, and it registers a click nobody made. Carry the link unresolved.

Worked example with all four answered: `docs/SOURCES.md` § *B1 SeLoger*.

## The car domain — `scout --domain=car`

**Built 2026-08-29, first slice.** A second domain inside the same tool: used cars in Île-de-France,
on its own database (`state/car-watch.sqlite3`), its own config (`config/car/`), its own heartbeat
marker and its own push topic (`CAR_NTFY_TOPIC`), with the generic machinery — mailboxes, HTTP
client, robots, channels, pacer, watch loop, the run-log/health half of the store — shared
byte-identical. Every ruling it implements is in
`docs/plans/archive/scout-rename-and-car-domain.plan.md`; the build record is
`docs/plans/archive/car-domain-first-slice.plan.md`.

Six sources, each built against a real payload:

- **ParuVendu** (`email_alert`) — the portal's saved-search mail, ~every two hours. **It samples its
  feed**: each message carries THREE cards for the tens or hundreds its subject counts (stated
  cost). Every reader is positional on the card's own layout, because the alert quotes the
  subscriber's search criteria above the cards — the PAP trap.
- **Autohero** (`sitemap_jsonld`) — a reseller whose sitemap indexes ~3 400 lot pages carrying a
  schema.org `Vehicle` block. The sitemap is the index; a lot page is fetched only for an id not
  yet in the seen-set, behind `lot_budget_per_pass`. **Seed before watching**: the seed records the
  whole index without fetching a lot, and an unseeded run refuses (Q36's car analog).
- **leboncoin** (`email_alert`, source #3, 2026-08-31) — one vehicle per message, no
  `card_separator`: the portal sends a mail per hit rather than a digest. **Everything that matters
  is in the SUBJECT**, which is what made it need code rather than config alone — the body above the
  price line carries only the dealer's name, rating and stock count, so a positional title reader
  would have titled every car with a marketing sentence. It shares its sender, its link host and
  even its `Voir l'annonce` string with the RENT leboncoin source; `subject_pattern` is the only
  thing separating the two domains' alerts.
- **CapCar** (`email_alert`, source #4, 2026-09-05) — a mandated-sale intermediary, one alert a
  day at 18:00 carrying four cars as a LABELLED BLOCK each (`Marque : … / Modèle : … / … /
  Prix : …`). The first source whose links carry no id — every one is a per-recipient Brevo
  redirect — so its identity is **content** (`title | year | km`, the price deliberately out so a
  drop stays a drop), and its whole card is read by one `facts_pattern` with named groups, the title
  composed from make + model + version. n=3 from day one: three captures are frozen and
  `CapCarFixtureTest` hand-reads all twelve cards. Same offline proof as the others:
  `MAILBOX_DIR=tests/fixtures/car/capcar CAR_SCOUT_DB=$(mktemp -u) php bin/scout --domain=car doctor --source=capcar`.
- **La Centrale** (`email_alert`, source #5, 2026-09-05) — the saved-search alert, ~twice a day in
  two subject templates, three cards each for the hundreds the subject counts: **~99.7 % blind to
  its own feed by construction** (F7, a stated cost; polling is refused by ruling — DataDome). A
  card is `TITLE / La Centrale N km / P €` with **no year** and a title truncated at ~28 characters,
  so its content identity is `title | km` and the mileage is all that tells two same-titled cars
  apart. Content identity is what makes a car re-sent the next morning ONE car: the three frozen
  captures carry nine cards for six cars, and `LaCentraleFixtureTest` asserts both numbers. Same
  offline proof: `MAILBOX_DIR=tests/fixtures/car/lacentrale CAR_SCOUT_DB=$(mktemp -u) php bin/scout --domain=car doctor --source=lacentrale`.
- **Agorastore** (`email_alert`, source #6, 2026-09-05) — the first **auction** source: public bodies'
  fleet vehicles, one daily alert listing the matching lots. **No price on any card** (an auction has
  none until it closes), no year or mileage except inside a free-text title, and a real per-lot
  reference (`011218-259`) that IS the identity — the facts `ref` group, which waives the year/km
  floor because a stated id is evidence enough. Stated costs: the price ceiling never fires and the
  age and mileage components are unscored on most lots. Three captures, twelve lots hand-read in
  `AgorastoreFixtureTest`; offline proof:
  `MAILBOX_DIR=tests/fixtures/car/agorastore CAR_SCOUT_DB=$(mktemp -u) php bin/scout --domain=car doctor --source=agorastore`.

What never surfaces: `VEI`, `VGE` / procédure VE, gagé / opposition, pour pièces, épave, sans carte
grise, CT non fourni / non roulant — and `accidenté`, réparé or not, a risk-appetite ruling and the
first line to relax, by a commit. **The classifier reads negations first**: *jamais accidenté*,
*non gagé*, *aucun accident* are what an honest ad says, and a bare scan would reject the good ads
and keep the silent ones. Price (≤ 30 000 €, the one hard ceiling) and a STATED location outside
Île-de-France reject; age, mileage, gearbox, fuel, body and **brand** are score components and never
reject. `brand_avoid` is 10 points of the existing 100 — flat and equal, a list of **stems** rather
than of makes (`alfa`, never `alfa romeo`), matched to a non-letter boundary because the live store
carries one marque under two spellings. An unlisted make earns the share; a listed one earns none;
an **unextracted** make earns none either and says `marque inconnue — hors score`, which is the arm
every other unknown component here takes.
No email source states a location and Autohero delivers nationally, so the geography filter is
inert on all six by measurement.

```bash
docker compose run --rm car-scout doctor                  # sources, seen-set, channels
docker compose run --rm car-scout run --once --seed       # mandatory before --watch
docker compose up -d car-scout
docker compose run --rm car-scout rollup [--dry-run]     # the "vérifié, score bas" rollup, on demand
MAILBOX_DIR=tests/fixtures/car/paruvendu CAR_SCOUT_DB=:memory: php bin/scout --domain=car dump paruvendu   # offline
```

**The car side has a push gate and a rollup too (2026-09-05), and the rollup is deliberately NOT a
digest**: the car domain has no tenure doubt, so there is nothing for a digest to mean. A MATCH
scoring under `notify.push_min_score` (73 — the same bar as the `!!` marker, measured over 646
stored matches: about one in four arrives individually) waits in the store; `rollup` announces the
queue on demand, and under `--watch` a daily floor at `notify.rollup_hour` (8, local `TZ`) drains
it, marking on delivery only — the marker `state/car-rollup.txt` is written after the channel
confirms, so a refused send leaves the window open. `doctor` prints the queue as `rollup :`.

## Legal posture

- Email-alert (IMAP) ingestion is the **primary** path for private portals — within ToS, no bot to
  detect, faster than polling, and far steadier than a search page — though NOT "immune to markup
  churn", which this file claimed until 2026-08-25. An email template is markup too, and a real
  SeLoger alert broke the parser four ways the first time it was fed one. The honest claim: email
  templates change far less often than search pages, and the zero-cards guard makes a break loud
  instead of silent.
- Direct scraping of those portals is opt-in, disabled by default, `legal_risk: true`, and refuses to
  run without an explicit flag.
- No CAPTCHA solving, no proxy rotation, no fingerprint spoofing. `robots.txt` respected, honest
  User-Agent, low request rates.
- No auto-application to landlords.

## Secrets

IMAP credentials, the notification token, the IDFM/PRIM API key and the RFR income figure live in
`.env`, which is gitignored. `.env.example` is the committed template. Never commit personal financial
data; never log credentials; scrub any fixture captured from a live payload before committing it.

⚠️ **This repo is currently public**, and `config/rent/criteria.json` is now committed. Making the repo
private is still recommended and is the developer's action; the mitigation that makes it survivable
either way ships regardless (Q11, ruled 2026-08-07):

- The committed `config/rent/criteria.json` carries the eight Île-de-France prefixes (75, 77, 78, 91, 92,
  93, 94, 95), `min_rooms: 3`, `min_surface_m2: 50`, `max_rent_cc: 1200`, and an EMPTY `communes`
  list — region mode, ruled 2026-08-22, where the prefixes are the whole location filter. Those
  numbers moved twice on that day; if this list and the file ever disagree, the file is right and
  this bullet is stale. **No name, no employer, no income
  figure, no address**, which is the part of this that matters.

  Two corrections to what this bullet used to claim, both dated 2026-08-22. It said *"only values
  that were already public in `prototype/sources.yaml`"*, and that stopped being true the moment
  `min_rooms` went from the prototype's `4` to a measured `3` — one committed value is now the
  developer's own decision rather than an inherited default. It is a preference about flat size, not
  personal data, so the Q11 mitigation still holds; but "already public" was load-bearing wording
  and is no longer accurate, and a privacy claim that quietly drifts is worse than a narrower one
  stated plainly. The ten communes are also no longer a filter — they survive as `commune_rank`
  (which orders results) and as a note in the file recording what to restore.
- **`config/rent/criteria.local.json` is gitignored** and overrides the committed file field by field, so
  real budget and real preferences never have to enter git. That claim was written in four documents
  before anything enforced it; `/config/*.local.json` is now in `.gitignore`, and
  `git check-ignore -v config/rent/criteria.local.json` is how to confirm it rather than believe it.
