# scout gotchas — tooling

Moved verbatim from CLAUDE.md § "Gotchas & pitfalls" on 2026-09-28 (review-remediation 5.4, /rules-split). Scope: Claude Code, git, Composer and tenure-guard behaviour that bites from any directory. New lessons for this area go HERE, not into CLAUDE.md. A § reference names a heading in CLAUDE.md or in docs/ENGINEERING-NOTES.md.

- **`allow` rules in `.claude/settings.json` are inert in cloud sessions.** [Unverified 2026-09-28: Claude Code
  behaviour, not checkable from the repo.] They need an accepted
  workspace-trust dialog, which a cloud session never shows. `defaultMode` is what actually takes
  effect. Don't grow the allow list expecting cloud effect.
- **New skills need a session restart to appear.** [Unverified 2026-09-28: Claude Code behaviour, not checkable
  from the repo.] Claude Code watches an existing `.claude/skills/`
  directory live, but a newly-created one is not watched until the CLI restarts. The `CLAUDE.md`
  sections bind immediately; the slash commands appear next session.
- **Commit messages: always `git commit -F -` with a QUOTED heredoc (`<<'EOF'`), never `-m "…"`.**
  A double-quoted `-m` string runs backtick command substitution, so any `` `Identifier` `` in the message
  is executed and replaced with its (usually empty) output. Hit on 2026-08-06 in commit `7234550`:
  `` `using` `` was eaten, leaving *"Closable + for the connection"*, and `bash` reported
  `using: command not found`. History was **not** rewritten — force-push is unauthorised here and the loss
  was one word in a message — so the cause is fixed instead. A `<<'EOF'` heredoc is literal: no expansion,
  no substitution, backticks safe.
- **Composer cannot install anything here, and that shaped the toolchain.** The container's egress
  policy returns **403 on `codeload.github.com` and on `api.github.com/.../zipball`**, which is where
  Composer fetches dists from. `git clone` over HTTPS *is* allowed, so `--prefer-source` works — but
  it pulls full git histories, and installing PHPUnit that way produced a **2.6 GB `vendor/`** for a
  test runner. The project therefore has **zero Composer dependencies**; `vendor/` holds only the
  generated autoloader (56 KB) and the runner is PHPUnit's official PHAR at `tools/phpunit.phar`
  (6 MB, gitignored, fetched from `phar.phpunit.de`, which is not blocked). Do not "fix" this by
  adding a dev dependency. Per `/root/.ccr/README.md`, a 403 from the proxy is reported, not routed
  around.
- **`.claude/hooks/tenure-guard.sh` false-positives on ordinary PHP, and that is a known cost.** It
  fired five times while the first PHP was written, every time on prose or syntax: `$flat[] =`
  (PHP's array append, read as an empty-list literal — the pattern now enumerates the shapes that
  actually empty something: `= []`, `=> []`, `return []`, `: []`, `: null`, `= array()`), a
  `0.0001` float epsilon read as a lowered confidence threshold, and phrases like *"no tenure
  signal"*, *"clear the floor"* and *"must never be deleted"*. When it fires, check WHICH pattern
  matched before assuming a real problem — reproduce with
  `tr '[:upper:]' '[:lower:]' < file | grep -oE '<pattern from the hook>'`. Reword prose to keep the
  tripwire credible; never weaken a pattern without a matching case in `tests/test-tenure-guard.sh`.

  > **TWO THINGS THAT MAKE THE DIAGNOSIS ABOVE FAIL, both learned 2026-09-04 after three more
  > firings in one session.** First, **the hook matches the EDIT PAYLOAD, not the file** — so
  > re-running it against the file on disk can come back silent while the write was blocked, which
  > reads as a phantom and wastes the next ten minutes. Second, `[^.]{0,80}` **spans newlines**, so
  > the window reaches across a closing brace and a blank line into the NEXT function: one firing
  > was an assertion message ending on *"never"* immediately before a docblock beginning *"A DOUBT
  > IS CLEARED"*, two declarations apart. Neither is visible from the matched line.
  >
  > The reliable diagnosis is a SET DIFFERENCE of the pattern's matches over the whole file, before
  > and after the write — `git show HEAD:<file>` against the working copy, both lowercased, both run
  > through the hook's own regex in `python3` (`re.S`), printing only what is new. That names the
  > match in one step. The three firings that session were `array $fields = []` in a test helper, a
  > constant named `…CLEARING_CONFIDENCE` sitting inside the window of an `isExcluded()` call, and
  > the cross-declaration one above. All three were reworded; no pattern was touched.
- **`core.fileMode=false` makes a PATHSPEC commit drop a mode change (2026-10-03).** `tools/in-docker.sh` was staged `100755`
  (`git update-index --chmod=+x`), `git commit -- <paths>` still recorded `100644`, and `git ls-files -s` (the index) said
  `100755` the whole time — so a guard reading the index passed on a wrapper a fresh clone could not run. Commit a mode change
  from the index (`git diff --cached --name-only` must list ONLY that file, then a plain `git commit -F`), and have a guard read
  `git ls-tree HEAD -- <path>`, never `git ls-files -s`.
- **In the dev image an uncreated `HOME` silently downgrades `fetch-phpunit.sh` to "signature UNVERIFIED" (2026-10-03).** With
  `HOME=/tmp/home` not existing, gpg dies (`can't create directory '/tmp/home/.gnupg'`) and the script falls back to the pinned
  sha256 alone, still exiting 0. Found only by running the chain on a FRESH clone: every earlier run reused a host-fetched
  `tools/phpunit.phar`. `compose.dev.yaml` now mounts a per-container tmpfs home owned by the mapped uid, and
  `tests/test-in-docker.sh` asserts gpg can create its keyring. The same fresh clone showed Docker creating a missing `state/`
  mountpoint ON THE HOST as root, so `tools/in-docker.sh` runs `mkdir -p state` first.
- **`docker build` on this box uses the docker-container driver: pass `--load` or the image never reaches the daemon (2026-10-03).**
  Without it the build exits 0 and the next `docker run` reports `pull access denied`, which reads as a registry problem.
  `docker compose build` of the dev image needs no flag.
- **`tools/in-docker.sh` forwards NO host environment variable (2026-10-08).** `SABOTAGE_FILTER=… tools/in-docker.sh bash
  tests/sabotage-check.sh` drops the filter silently and starts the FULL ledger (hours) under the default 300 s per-case timeout,
  which the dockerised suite (~3 min) brushes against; written after the wrapper, `tools/in-docker.sh SABOTAGE_FILTER=… bash …`
  makes `docker` try to execute the assignment. Put `env` INSIDE: `tools/in-docker.sh env SABOTAGE_FILTER='…'
  SABOTAGE_SUITE_TIMEOUT=1500 bash tests/sabotage-check.sh`. The same holds for every variable a test or tool reads.
