---
paths:
  - "tests/**"
  - ".github/**"
  - "phpunit*.xml*"
---

# scout gotchas — tests

Moved verbatim from CLAUDE.md § "Gotchas & pitfalls" on 2026-09-28 (review-remediation 5.4, /rules-split). Scope: CI, the nightly, the sabotage ledger and how its cases fail, test bootstrap. New lessons for this area go HERE, not into CLAUDE.md. A § reference names a heading in CLAUDE.md or in docs/ENGINEERING-NOTES.md.

- **A GREEN TREE HERE IS NOT A GREEN CI, AND FOR TWO DAYS NOBODY LOOKED (row 45, 2026-09-05).**
  Twelve consecutive pushes were red from `46546bc` (2026-09-03 08:56) on, 113 errors + 22
  failures each, while every local run was green — found only when the developer asked to check
  `gh run list`. Two root causes, both about the RUNTIME differing from this one: **(1)** the
  coliving title exclusion used a variable-length lookbehind (`(?<![0-9]\s\w{1,14}\s)`), which
  PCRE2 accepts from 10.43; the PHP built here and in the deployed image bundles 10.44, the CI
  runner's `setup-php` links Ubuntu's libpcre2 10.42, so the loader's own compile check refused
  the whole `criteria.json` there and every test that loads it errored on `ConfigError` — a rule
  on one runtime, a syntax error on another. Rewritten as a negative lookahead from the string
  start, **measured identical over every stored title (73/73, 0 differences)**, and
  `tests/php/Repo/PortablePatternsTest.php` now refuses any configured lookbehind with a
  non-fixed quantifier. **(2)** `CredentialsNeverReachATraceTest` ASSUMED the development ini
  (trace arguments printed); the runner ships the production ini, so its premise assertion failed
  while the guarantee held for free. The test now SETS the printing runtime (`ini_set`, both
  directives are `PHP_INI_ALL`, restored in `finally`; the child probe likewise) instead of
  finding it. **Run `gh run list --limit 5` after every push** — the notification half of CI is
  the nightly ledger's issue, not the fast job's, and a red fast job is silent.
- **THE NIGHTLY DOES NOT START WHEN ITS CRON SAYS, AND THE DECLARATION IS THE WRONG THING TO READ
  (2026-09-10).** `ci.yml` declares `cron: '0 3 * * *'` (UTC); the last eight scheduled runs
  actually started between **07:26 and 07:58 UTC** — a consistent 4.5–5 hour queue delay, which is
  GitHub behaving as documented (`schedule` is best effort and is delayed at popular cron times).
  So a session that checks shortly after 03:00 UTC, finds nothing, and reports the nightly broken is
  reading the declaration rather than the history: `gh run list --event=schedule` settles it in one
  request, and it is the only thing that does — `gh run list` alone is dominated by `push` runs and
  shows no scheduled row at all. The delay is not free: it lands the six shards in GitHub's busiest
  runner window, competing for the same wall clock as the **240-minute job cap** that cancelled four
  of eight nightlies before the shard split, and no shard count can address that.
  **AND READ `date -u`, NEVER `uptime`, WHEN CHECKING THAT BAND (2026-09-10).** This box runs
  `CEST` (UTC+2) and `uptime` prints LOCAL time, so a session that reads its clock as UTC is two
  hours fast and concludes the nightly is late when it has not yet been created. That happened the
  day after the entry above was written: "09:45Z, nothing has fired" was really 07:45Z, and the run
  was created at **07:53:56Z** — inside the band, two seconds off the previous night's start. A
  true observation (no scheduled row listed) on an invented cause, which is this repo's named
  failure reached by misreading a timezone.
- **A REMAINDER ASSERTION MUST PIN THE DIGIT BOUNDARY (2026-09-07).** `assertStringContainsString('7
  autre(s) en attente')` is satisfied by `57 autre(s) en attente`. The suite was green with the
  number wrong by a factor of eight, on the line that tells an operator whether a capped batch
  drained. Anchor with `(?<![0-9])` — the same guard `ROOMS_PATTERN` already carries for the same
  reason one layer down.
- **AN UNSCOPED `sed` IN THE LEDGER CAN LEAVE THE LABELLED SURFACE COVERED BY NOTHING (2026-09-07).**
  Two cases labelled for the digest verb and the daily floor shared
  `s%if (!$notifier->delivered($failures)) {%if (false) {%`, which hits **four** guards — the verb,
  `pushRetries()`, the reclassify promotion and `floorDigest()`. Both passed on the other three, and
  mutating `floorDigest()`'s guard ALONE left the entire suite green: the DEPLOYED drain marking its
  digest entries before the channel confirms — a backlog whose own comment says *"these entries have
  no other route to the developer"* — was dead safety code. `test-sabotage-applies.sh` cannot see
  this: it proves an expression MATCHES, never that it matches ONE thing. When two cases share a
  pattern, scope each with a function address range and measure the changed-line count.
- **A LEDGER CASE CAN FAIL *GREEN*, AND NOTHING IN THE GATE CAN SEE IT (2026-09-09).** One commit —
  `422e27a`, which gave every `pushRetries()` call an assignment — rotted FOUR cases at once, and
  the four did not fail the same way. Three left `$drainedKeys = ;` and became PARSE ERRORS, which
  the ledger reports as *"this proves nothing either way"*. The fourth, the rent daily FLOOR,
  truncated into the FOLLOWING statement and made a valid assignment chain
  (`$drainedKeys = $sectionOne = new SectionOneGate(…)`): it parsed, the suite went red on a **type
  scramble** rather than on its own label, and the case reported **`ok`** in every nightly for three
  days. `test-sabotage-applies.sh` is blind to it — the expression still MATCHED — and so is the
  nightly, which only ever names cases that FAILED. Two rules: replace rather than delete
  (`$drained = 0;` keeps the assignment), and after any refactor that changes a call's SHAPE rather
  than its address, re-measure the changed-line count and read WHICH test goes red, because a case
  reddening the wrong test is indistinguishable from a case working.
- **AND THERE IS A SECOND SPECIES: THE RED COMES FROM A GUARD THAT IS NOT ABOUT THE LABEL
  (2026-09-09).** The species above truncates code; this one runs perfectly and still proves
  nothing. The §1 cases for `pushRetries()`, the `digest()` rollup filter, the `floorDigest()`
  rollup filter and `announcePromotions()` mutate `$refusal = null;`, which removes the `->refuses(`
  token — so what reddens is `SectionOneGateCallSitesTest`, the STRUCTURAL guard. Suite red, case
  `ok`, §1 tested by nothing. **That guard's needle is `->send(` PLUS a `Formatter|formatter->|
  Notification` term, never the literal `notifier->send(`** — its own docblock records the single
  literal as the defeated predecessor, because renaming `$notifier` to `$channel` hid a whole
  announcing method; cite it by behaviour, not by that string. The four are exactly the methods
  holding ONE `->refuses(`, which is why deleting it leaves only the guard to answer;
  `Pipeline::runOnce` holds TWO (the match gate and `$digestRefusal`), so its case is genuinely
  behavioural. `announcePromotions()` is a knowing member — the plan rules it can have no fixture of
  its own, the caller's filter removing every refused promotion before it is reached — so the
  gap was the other three, CLOSED by row 70 (2026-09-13, below). Measured in a scratch tree whose own baseline was green (`OK 3128 /
  12118`), mutating the
  CONSEQUENCE instead so the call survives: `pushRetries()`, the `digest()` rollup filter and the
  `floorDigest()` rollup filter each go **changed=1, rc=0 — GREEN**. The shape was already ruled
  (`docs/plans/scout-unified-execution.plan.md`, the `de2a81e` entry): mutate the CONSEQUENCE,
  `if ($refusal !== null)` → `if (false)` scoped to one method. **The rule that generalises: when a
  case reddens, read WHICH test — a structural or repo-level guard answering for a behavioural one
  is a case that has measured its own scaffolding.** And the obvious repair is not always available:
  the reaching case proposed for these three (a snapshot-less row entering `$retries`) cannot fire
  at the gate either, because that row's rebuilt listing carries no commune and
  `Dedup::sameFlatReason()` requires both communes to agree positively — verified by executing it
  against a hydrated copy, which does match. **This sentence then said two of the three had no
  in-process seam, and that was wrong: a claim about ORDER made without reading the order.** Row 70
  (2026-09-13) read it. In `floorDigest()` the retries are pushed BEFORE the rollup filter, and in
  `pushRetries()` one retry's send comes before the next retry's gate read. So a concurrent writer
  simulated on the send (`DeliveringChannel::$onSend` recording a PLS twin) reaches both. The real
  finding was the VERB: it read the gate before its retries and then mailed that stale list, which
  breaks the gate's own *last moment* contract. It now reads the gate again after the retries.
  All three cases mutate the consequence, and each one reddens a behavioural test. **Before
  declaring a seam absent, read the send order: any send that comes before a gate read is a seam.**
  **THE SPECIES WAS THEN AUDITED ACROSS ALL THREE NEEDLES, AND IT IS NARROW (2026-09-10).** The
  finding above came from the `->refuses(` needle alone; the other two literal tokens those guards
  grep are `$source->acknowledge()` and `ExcludedDwellings::match(`, so every case whose `sed`
  removes one was a candidate. Eight were measured one at a time against a green scratch baseline
  (`OK 3128 / 12118`), partitioning the failing classes on `Scout\Tests\Repo\`: **seven carry
  behavioural coverage and ONE does not** — `RentScout::collectDigest()`'s `$dwellingVeto`. **And
  that one is a guarantee defended TWICE rather than a hole**, which the single mutation cannot
  show: nulling the drain's read alone is behaviourally green because `SectionOneGate` re-reads the
  route fresh before every send, while nulling BOTH reds nine behavioural tests including
  `RentScoutDigestTest::testAFlatRecordedExcludedUnderAnotherAdIdVetoesTheDrain`. It cannot be
  compounded into one case — the layers are in different FILES and `run_sabotage` takes one target
  — so it is annotated in place. **The audit's real yield was the opposite defect**: the matcher has
  FIVE call sites and the ledger covered four, `Pipeline::storedDwellingClassification()` — the one
  that shapes a live pass's verdict rather than refusing at a send — having none. A case for it was
  added and measured genuine (changed=1, behavioural red). **When auditing a needle, enumerate the
  call sites too: a case that mismeasures and a case that is absent look identical from the tally.**
- **AND THE TALLY CANNOT TELL YOU WHICH KIND YOU HAVE.** `$fail` counts copy failures, refused seds,
  inert expressions, timeouts, parse errors, harness breaks and genuine undetected cases alike,
  while the tally line and the `undetected or unapplied:` heading call all of them *undetected* — so
  issue #16's seven bare labels read as seven undetected regressions when three were parse errors
  and one was reporting `ok`. Since 2026-09-09 every label carries its kind in brackets
  (`[UNDETECTED]`, `[inconclusive-parse-error]`, `[inert-expression]`, `[inconclusive-timeout]`,
  `[harness-*]`) and the red-ledger issue enumerates them; only `[UNDETECTED]` is a finding about
  the TESTS. **The two headings are deliberately unchanged** — `tests/test-ci-workflow.sh` pins the
  `%d undetected` printf positionally against the shard ABORT, and pins `undetected or unapplied:`
  both as the proof the issue names which cases were not caught and as the regex `ci.yml` harvests
  the block with. Renaming either is a separate change that must move its pin with it.
- **EXTRACTING A CLASS ORPHANS SABOTAGE EXPRESSIONS THAT NEVER NAMED IT, and the obvious
  measurement says otherwise.** The 2026-09-01 `Core/RunStore` split moved 625 lines out of the rent
  `Store`. Asking which ledger expressions mention one of the six moving METHOD NAMES answered
  **1**; the expressions target code *inside* those bodies, which names no method, and
  `tests/test-sabotage-applies.sh` found **39 of 607 gone INERT** — reporting coverage they did not
  have. `grep -c "Rent/Store/Store.php" tests/sabotage-check.sh` answers 94 and is the number to
  ignore; only running the gate answers the question. Retarget the file path, never the expression:
  the code moved verbatim, so each must match in the new file AND not in the old, checked one at a
  time. Four other things this split is worth remembering for, each a silent failure:
  **(a)** a generic store must NOT adopt `schema_meta` — the live rent file records `12` there, and
  a v1 store reading it would refuse to open the database that produces the matches, so the run log
  owns its own `run_meta`; **(b)** `VehicleStore::migrate()` RETURNS EARLY at the current version, so
  a cleanup for existing files placed in the migration transaction never runs on the one file it
  exists for — it goes above the return; **(c)** copying a table's ORIGINAL `CREATE` rather than its
  CURRENT shape passes `php -l` and reflection and throws on the first write (`source_runs` had
  gained `feed_newest_at` by `ALTER` at v11) — only running it finds that; **(d)** `backup-state.sh`
  counts the rent `listings` table, so every car backup had reported *"0 annonces"* while holding
  3 544 vehicles. A backup that reports itself empty is one nobody checks.
- **`composer dump-autoload` WITHOUT `--dev` silently breaks the corpus suite.** It omits the
  `Scout\Tests\` PSR-4 entry; PHPUnit still loads the test *files* itself, so the unit tests keep
  passing while every corpus test errors `Class ... not found`. It reads as a code regression and is
  a build state. `tests/bootstrap.php` now checks this and prints the fix, but if you see that error,
  run `composer dump-autoload --dev`.
- **A LEDGER CASE THAT TIMES OUT READS AS "UNDETECTED", AND ON A LOADED BOX THAT IS THE COMMONEST
  FALSE RED.** Each case runs under `timeout` (`SABOTAGE_SUITE_TIMEOUT`, default 300 s) and a suite
  that never finished is counted as a loud FAILURE — correctly, since a hang is not a detection. But
  the full suite takes ~88 s on an idle box, and this machine is shared: on 2026-09-04 a PHPUnit run
  from an unrelated project (`/stack/projects/invoiceninja`) pushed the load average to **27** while
  the ledger was running, and a case sat at 256 s of a 300 s budget with nothing wrong. Check
  `uptime` and `ps -eo pid,etimes,args --sort=-etimes | grep phpunit` before believing a lone
  timeout; re-run that case alone on a quiet box, or raise `SABOTAGE_SUITE_TIMEOUT`. Same class as
  the JIT entry below — the harness broke, the guarantee did not.
- **The local PHP's tracing JIT crashes the sabotage ledger nondeterministically.** [HOST CHANGED —
  re-checked 2026-09-28: `php` is now the distro `/usr/bin/php8.5` and `~/.phpbrew` is gone, so the phpbrew
  recipe below is historical; whether the distro build crashes the same way is untested. What still holds:
  a crash exit is *harness broke*, not a detection.] `php` here was
  phpbrew's `8.5.9 (ZTS DEBUG)` with `opcache.jit=tracing` and `opcache.enable_cli=1`; under the
  ledger the suite dies mid-run with `zend_jit_trace.c … Assertion !p->op_array failed` (exit 134),
  which `tests/sabotage-check.sh` rightly counts as *harness broke*, not as a detection — observed on
  three different cases across two runs on 2026-08-30, each detecting fine on the other run. CI is
  unaffected. For a local run, disable the JIT WITHOUT dropping the original ini scan dir:
  `PHP_INI_SCAN_DIR="<phpbrew var/db/cli>:<a dir holding opcache.jit=off>"` — dropping the original
  loses `iconv` and reddens the baseline for an unrelated reason (measured: 18 errors). Take the
  phpbrew dir from `php --ini`, and **strip the double quotes it prints around the scan dir**: pasted
  with them the path matches nothing, twelve extensions drop and the ledger baseline aborts red
  (measured 2026-09-13).
