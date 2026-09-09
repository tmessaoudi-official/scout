#!/usr/bin/env bash
# sabotage-check.sh — does the suite actually CATCH a regression?
#
# A green test run proves the code passes the tests. It does not prove the tests would notice if the
# code stopped working — and for these modules that distinction is the whole ballgame, because every
# failure mode here is SILENT. A classifier that quietly rejects everything looks exactly like a
# quiet rental market, and a classifier that quietly admits social housing looks exactly like a
# productive one until an application is wasted. The store is the same shape: a seen-set that stops
# persisting, a price history that stops recording, a run log that reports a dead source as calm —
# none of them raise anything, and all of them are indistinguishable from working.
#
# So: break each guarantee on a scratch copy, one at a time, and require the suite to go red. A
# sabotage that leaves the suite green is a hole in the corpus, reported as FAIL.
#
# Run: bash tests/sabotage-check.sh

set -uo pipefail

repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
work="$(mktemp -d)"
# Checked, because this script runs `rm -rf "$work/repo"` and there is no `set -e`. An unchecked
# mktemp failure would leave $work empty and turn that into `rm -rf "/repo"`, as root.
[[ -n "$work" && -d "$work" ]] || { printf 'mktemp -d failed; refusing to continue\n' >&2; exit 1; }
trap 'rm -rf "$work"' EXIT

pass=0
fail=0
# Every failure's LABEL, repeated in the summary. Two reviewers independently saw this script report
# undetected sabotages once and could not say which, because both had piped it through `tail` and the
# inline FAIL lines had scrolled away. A gate whose verdict cannot be reconstructed from its own last
# ten lines is a gate nobody can act on.
failed_labels=()

# An optional regex over LABELS, so a newly-added case can be proven red on its own instead of
# behind a two-hour full run. It skips silently rather than reporting, because a filtered run is a
# development aid and must never be mistakable for the ledger: the summary counts only what ran, and
# `skipped` is printed at the end whenever the filter was in play. CI never sets it.
_filter="${SABOTAGE_FILTER:-}"

# ── SHARDING (2026-09-05, developer ruling) ──────────────────────────────────────────────────────
# `SABOTAGE_SHARD=<i>/<n>` runs only the cases whose INDEX falls in shard i of n. It exists because
# one CI job could not finish the ledger any more — 258 -> 527 -> 759 cases in three weeks, four of
# the last eight nightlies cut off at the 240-minute cap — and GitHub's hosted ceiling is 360, so a
# bigger budget had nowhere left to go.
#
# THE INDEX IS COUNTED BEFORE THE FILTER, deliberately: shard membership then depends only on a
# case's position in this file, so it is the same whether or not `SABOTAGE_FILTER` is set, and the
# two compose as an intersection rather than one silently re-partitioning the other.
#
# A MALFORMED OR EMPTY SPEC ABORTS. Both halves are the `--section=` typo defect one layer up: a
# shard spec that is ignored means one job runs everything and the rest run nothing, and a spec that
# selects NO case means six jobs report a clean ledger between them having proved nothing at all.
# Neither is visible from any shard's own output, which is what makes the refusal necessary rather
# than tidy.
_shard_i=1
_shard_n=1
if [[ -n "${SABOTAGE_SHARD:-}" ]]; then
  if [[ "$SABOTAGE_SHARD" =~ ^([0-9]+)/([0-9]+)$ ]]; then
    _shard_i="${BASH_REMATCH[1]}"
    _shard_n="${BASH_REMATCH[2]}"
  else
    printf '\n  \033[31mABORT\033[0m SABOTAGE_SHARD must read <i>/<n>; got "%s".\n\n' "$SABOTAGE_SHARD"
    exit 1
  fi
  if (( _shard_n < 1 || _shard_i < 1 || _shard_i > _shard_n )); then
    printf '\n  \033[31mABORT\033[0m SABOTAGE_SHARD "%s" names no shard of a %d-way split.\n\n' \
      "$SABOTAGE_SHARD" "$_shard_n"
    exit 1
  fi
fi
_case_index=0
_sharded_out=0
_ran=0

# Per case, not for the whole ledger — see the call site for what it is defending against.
readonly SUITE_TIMEOUT_SECONDS="${SABOTAGE_SUITE_TIMEOUT:-300}"
skipped=0

# Each entry: label :: file :: sed expression that breaks the guarantee
run_sabotage() {
  # A FOURTH ARGUMENT IS A SILENTLY DISCARDED MUTATION. `expr="$3"` reads no further, so a case
  # written as two quoted expressions seeds only the first and reports on a guarantee it never
  # touched. Two were in this file (2026-09-06): the quoted-printable case, which reported
  # `undetected` for four nightlies with half its mutation applied, and the fail-closed §1 case at
  # "the fail-closed rule stops requiring the detail page to have been read", which reported `ok`
  # on its first expression while the `mixedTenure` arm was never seeded at all. Neither is visible
  # to `tests/test-sabotage-applies.sh`, which is handed `$3` and so checks exactly what ran.
  # Compound mutations are joined with `;` into ONE argument. Refusing is the same rule this script
  # already applies to a shard spec that selects nothing: a silently-ignored instruction is worse
  # than a loud refusal, because it reports coverage it does not have.
  if (( $# > 3 )); then
    printf '\n  ABORT  %s: %d arguments — join compound sed expressions with `;` into one.\n\n' "$1" "$#"
    exit 1
  fi
  local label="$1" target="$2" expr="$3"

  # Counted for EVERY case, before any skip, so a shard is a stable slice of this file.
  _case_index=$((_case_index + 1))
  if (( _shard_n > 1 )) && (( (_case_index - 1) % _shard_n != _shard_i - 1 )); then
    _sharded_out=$((_sharded_out + 1))
    return
  fi

  if [[ -n "$_filter" ]] && ! grep -qE -- "$_filter" <<<"$label"; then
    skipped=$((skipped + 1))
    return
  fi

  _ran=$((_ran + 1))

  rm -rf "$work/repo"
  mkdir -p "$work/repo"
  # src/ and tests/ are copied because the sabotage edits them. vendor/ is COPIED TOO, and that is
  # load-bearing rather than wasteful: composer's `autoload_psr4.php` computes its base directory
  # from its own location, so a symlinked vendor/ resolves PSR-4 back to the PRISTINE src/ and every
  # sabotage silently reports as undetected. A reviewer hit exactly that and got 0/144. Only the
  # runner is symlinked — it is a self-contained PHAR with no path assumptions.
  # Checked: an unnoticed copy failure makes the scratch suite fail for the wrong reason, and the
  # detection assertion below cannot tell that apart from a caught sabotage.
  # config/ JOINED THIS LIST on 2026-08-07 and it is not optional. The config layer's tests read
  # `config/rent/criteria.json` and `config/rent/sources.json` through a repo-root constant, so without it
  # every one of them ERRORS in the scratch copy — and an errored suite is a red suite, which this
  # harness cannot tell apart from a caught sabotage. Every sabotage would have reported `ok` while
  # proving nothing at all, which is precisely the failure the whole script exists to detect.
  # `.env.example` JOINED THIS LIST on 2026-08-24, and its absence had made this ENTIRE SCRIPT
  # vacuous since 2026-08-22. `DotEnvTest::testTheShippedTemplateParsesCleanly` reads the template
  # through a repo-root constant, so without it that ONE test failed in every scratch run — and the
  # detection assertion below is `Failures: [1-9]`, which that single failure satisfies on its own.
  # Every case therefore reported `ok` whether or not the suite noticed the sabotage, `fail` could
  # never increment, the script always exited 0, and the nightly stayed permanently green. Worse,
  # per CLAUDE.md a green nightly CLOSES every open ledger issue, so the broken gate actively
  # retracted real alarms. Found by a review panel on 2026-08-24, which re-ran the in-range cases
  # against a corrected copy and found three of them genuinely undetected.
  #
  # The lesson generalises past this one file: ANY repo-root file a test reads must be in this list,
  # and the baseline check below runs in `$repo` rather than the scratch copy, so it cannot see the
  # difference. That gap is what `assert_scratch_baseline_green` now closes.
  #
  # `bin/` JOINED THE LIST 2026-09-04, and the reason is the list's own recorded failure repeating.
  # `bin/scout` is no longer only an entry point: it sets `zend.exception_ignore_args=1`, which is
  # the structural half of "no credential reaches a stack trace", and
  # `CredentialsNeverReachATraceTest` reads it. Without `bin/` in the copy, that test fails in every
  # scratch tree — which is exactly what `.env.example`'s absence did for 27 hours in 2026-08, when
  # ONE failing test satisfied the `Failures: [1-9]` detection assertion unconditionally and ~375
  # cases reported `ok` while proving nothing. `assert_scratch_baseline_green` caught it this time,
  # on its first run after the test landed, which is the whole reason that gate exists.
  if ! cp -a "$repo/src" "$repo/tests" "$repo/config" "$repo/phpunit.xml" "$repo/composer.json" "$repo/.env.example" "$repo/bin" "$work/repo/" \
    || ! cp -a "$repo/vendor" "$work/repo/vendor" \
    || ! ln -s "$repo/tools" "$work/repo/tools"; then
    printf '  \033[31mFAIL\033[0m %-58s (could not build the scratch copy)\n' "$label"
    fail=$((fail + 1))
    failed_labels+=("$label")
    return
  fi

  if ! sed -i "$expr" "$work/repo/$target"; then
    printf '  \033[31mFAIL\033[0m %-58s (sabotage could not be applied)\n' "$label"
    fail=$((fail + 1))
    failed_labels+=("$label")
    return
  fi

  # The sabotage must actually change the file, or we are testing nothing.
  if cmp -s "$repo/$target" "$work/repo/$target"; then
    printf '  \033[31mFAIL\033[0m %-58s (sabotage was a no-op — the pattern no longer matches)\n' "$label"
    fail=$((fail + 1))
    failed_labels+=("$label")
    return
  fi

  local out
  # NO `--do-not-cache-result`: it disables the result cache, which `executionOrder="defects"` in
  # phpunit.xml requires, so PHPUnit raises a runner warning and `failOnWarning="true"` turns a
  # perfectly green suite red. Cache isolation between cases needs no flag — `$work/repo` is
  # rm -rf'd and rebuilt above for every sabotage, so the cache PHPUnit writes under it dies with it.
  # `timeout`, because a sabotage can make the suite BLOCK rather than fail, and a gate that stalls
  # silently is worse than one that reports a failure. Observed on 2026-08-19: disabling the Q36
  # empty-database guard let `scout --domain=rent run --watch` enter its real fifteen-minute loop inside a test
  # that expected the run to be refused, and this script sat on its FIRST case for eleven minutes
  # printing nothing. The test-side fix is a bounded loop; this is the harness refusing to be held
  # hostage by the next one. The full suite runs in ~25 s, so five minutes is not a tight budget.
  out="$(cd "$work/repo" && timeout "$SUITE_TIMEOUT_SECONDS" php tools/phpunit.phar --colors=never 2>&1)"
  local rc=$?

  # 124 is `timeout`'s own verdict. It is NOT detection: the suite never finished, so it never said
  # anything about the guarantee. Loud, and counted as a failure, because an inconclusive case in a
  # gate that certifies §1 must not read as a pass.
  if [[ $rc -eq 124 ]]; then
    printf '  \033[31mFAIL\033[0m %-58s (the suite did not terminate within %ss — inconclusive,\n' "$label" "$SUITE_TIMEOUT_SECONDS"
    printf '        and a hang is not a detection)\n'
    fail=$((fail + 1))
    failed_labels+=("$label")
    return
  fi

  # A non-zero exit is NOT evidence of detection — a PHP parse error, a missing autoloader or a
  # failed `cd` produces one too, and asserting on `rc` alone would report those as successes.
  #
  # Nor is "PHPUnit reported errors" enough on its own, and that was the remaining hole: PHPUnit
  # turns an autoload-time SYNTAX error into N test errors, which match both greps below and would
  # print `ok`. Several sabotages here are line-deletes or pattern-pinned seds, so a refactor that
  # moves the targeted text can silently convert a sabotage into a syntax error — and the script
  # would then certify the guarantee as covered while the suite never exercised it. A green light
  # on the one check guarding §1.
  #
  # So: a parse/fatal error is never accepted, and the run must have actually executed tests.
  if grep -qE '(Parse error|Fatal error|syntax error, unexpected)' <<<"$out"; then
    printf '  \033[31mFAIL\033[0m %-58s (the sabotage produced a PHP parse error, so the suite never\n' "$label"
    printf '        ran — this proves nothing either way)\n'
    fail=$((fail + 1))
    failed_labels+=("$label")
    return
  fi

  if grep -qE '^(FAILURES!|ERRORS!|OK, but)' <<<"$out" \
    && grep -qE 'Failures: [1-9]|Errors: [1-9]' <<<"$out" \
    && grep -qE 'Tests: [1-9][0-9]{2,}' <<<"$out"; then
    printf '  \033[32mok\033[0m   %-58s (suite went red, as it must)\n' "$label"
    pass=$((pass + 1))
  elif [[ $rc -ne 0 ]]; then
    printf '  \033[31mFAIL\033[0m %-58s (suite exited %d but reported no assertion failure — the\n' "$label" "$rc"
    printf '        harness broke rather than the sabotage being caught)\n'
    printf '        %s\n' "$(tail -3 <<<"$out")"
    fail=$((fail + 1))
    failed_labels+=("$label")
  else
    printf '  \033[31mFAIL\033[0m %-58s (SUITE STAYED GREEN — this regression is undetected)\n' "$label"
    fail=$((fail + 1))
    failed_labels+=("$label")
  fi
}

printf '\n== sabotage-check: can the suite detect a broken classifier or store? ==\n\n'

# BASELINE FIRST, and this is not ceremony. Every sabotage below asserts "the suite went red". If
# the suite is ALREADY red — a missing autoloader, a syntax error, a half-applied edit — then every
# single sabotage reports success and the whole run is a green light that means nothing. That
# happened on 2026-08-06, and it is why this check exists before the loop rather than after it.
#
# The flags here are load-bearing in the other direction too: this gate must not be reddened by its
# OWN invocation. `--do-not-cache-result` was removed on 2026-08-19 for exactly that — see the note
# at the per-sabotage run above, and the satisfiability check in tests/test-ci-workflow.sh that now
# pins it. `--no-output` is kept: it is current in PHPUnit 13 and suppresses the progress dots.
if ! (cd "$repo" && php tools/phpunit.phar --no-output >/dev/null 2>&1); then
  printf '  \033[31mABORT\033[0m the suite is red BEFORE any sabotage — every result below would be a\n'
  printf '        false positive. Fix the suite first, then re-run.\n\n'
  exit 1
fi
printf '  baseline: suite is green — sabotage results are meaningful\n'

# AND THE SAME BASELINE IN THE SCRATCH COPY, which is the check that was missing for the ~27 hours
# between `4403e9d` (2026-08-22 20:43, the commit that made a test read `.env.example`) and
# `feb416d` (2026-08-23 23:31, which added it to the copy list).
#
# The baseline above runs in `$repo`, so it proves the REPO is green and says nothing about the
# throwaway tree every sabotage is actually judged in. Those two trees are not the same tree: the
# scratch copy is assembled from an explicit `cp -a` list, and any repo-root file a test reads but
# that list omits makes the scratch suite fail for a reason no sabotage caused. `.env.example` was
# omitted from 2026-08-22, so ONE test failed in every scratch run, the `Failures: [1-9]` detection
# assertion was satisfied unconditionally, and all ~375 cases reported `ok` while proving nothing —
# for that window. AT MOST ONE nightly ran inside it — an earlier version of this comment said
# "for a month, with the nightly green throughout and closing real ledger issues as it went", which
# was a true finding with an invented magnitude, the exact failure this session kept finding
# elsewhere. What makes the defect serious is not its duration but that it is UNDETECTABLE from the
# ledger's own output: every case prints `ok`.
#
# This builds one unsabotaged scratch copy and requires it GREEN. It is the difference between a
# gate that certifies §1 and a gate that certifies its own copy list, and it costs one suite run.
rm -rf "$work/baseline"
mkdir -p "$work/baseline"
if ! cp -a "$repo/src" "$repo/tests" "$repo/config" "$repo/phpunit.xml" "$repo/composer.json" "$repo/.env.example" "$repo/bin" "$work/baseline/" \
  || ! cp -a "$repo/vendor" "$work/baseline/vendor" \
  || ! ln -s "$repo/tools" "$work/baseline/tools"; then
  printf '  \033[31mABORT\033[0m could not build the unsabotaged scratch copy.\n\n'
  exit 1
fi
if ! (cd "$work/baseline" && php tools/phpunit.phar --no-output >/dev/null 2>&1); then
  printf '  \033[31mABORT\033[0m the suite is red in an UNSABOTAGED scratch copy, so every case below\n'
  printf '        would report `ok` whether or not it was detected. Something the tests read is\n'
  printf '        missing from the cp list in run_sabotage() — that is the bug, not the tests.\n'
  # The runner is named through a `%s` rather than written out, and that is not decoration:
  # tests/test-ci-workflow.sh extracts every literal runner invocation in this file and RUNS it, to
  # prove no invocation's flags redden a green suite. This line is a MESSAGE, not an invocation, and
  # writing the command out in full made the checker try to execute a printf fragment.
  printf '        Reproduce: cd %s && php %s\n\n' "$work/baseline" "tools/phpunit.phar"
  exit 1
fi
printf '  scratch baseline: green too — the copy list is complete\n'

# BEFORE the work, not after it. A dirty tree is not fatal — checking uncommitted changes is a
# legitimate use — but every sabotage copies src/ and tests/ wholesale, so an edit landing mid-run
# silently changes what is under test. Warning at the END put it ~130 lines above the verdict, past
# the `tail` that everyone reads.
if [[ -n "$(cd "$repo" && git status --porcelain 2>/dev/null)" ]]; then
  printf '  \033[33mNOTE\033[0m the working tree is dirty; each sabotage copies it as-is, so an edit\n'
  printf '       landing mid-run changes what is under test. Results are advisory until it is clean.\n'
fi

printf '\n'

run_sabotage "PLS dropped from the excluded set" \
  src/php/Rent/Core/Tenure.php \
  's/^            self::PLS,$//'

run_sabotage "PLAI dropped from the excluded set" \
  src/php/Rent/Core/Tenure.php \
  's/^            self::PLAI,$//'

run_sabotage "conflict rule removed (eligible verdict no longer withholds)" \
  src/php/Rent/Core/TenureClassifier.php \
  's/if ($winner->tenure->isEligible()) {/if (false) {/'

run_sabotage "collocation guard removed (bare 'plus' becomes a social label)" \
  src/php/Rent/Core/TenureClassifier.php \
  's/if (!$inContext) {/if (false) {/'

run_sabotage "comparative suppression removed ('LOGEMENT PLUS GRAND' reads as financing)" \
  src/php/Rent/Core/TenureClassifier.php \
  's|^    private const string COMPARATIVE_TAIL = .*$|    private const string COMPARATIVE_TAIL = "zzzz-no-such-word";|'

# `reasons[]` is the product's only user-facing output (spec §5) and had exactly two assertions on it
# — non-empty, and no blank entry — so reverting the tier-2 reason to the TABLE LITERAL, which makes a
# notification quote a phrase the listing does not contain, left the whole suite green.
run_sabotage "tier-2 reason quotes the table literal instead of the matched text" \
  src/php/Rent/Core/TenureClassifier.php \
  "s|self::oneLine(\$matched)|\$hit['literal']|"

# The doubt FLOOR in a structured tenure field. Round 5 found the prose branch answering silence when
# no COLLOCATION noun sat beside the acronym — a §1 breach on the strongest rung of the ladder.
run_sabotage "field prose branch goes silent again (no collocation noun => no signal, no doubt)" \
  src/php/Rent/Core/TenureClassifier.php \
  's/$doubtAt = $this->firstNonComparativeOccurrence($cased, $acronym);/$doubtAt = null;/'

# Addressed to firstNonComparativeOccurrence() alone — `if (!$this->matches` also appears in
# isCodeList(), and a sabotage that broke both could be "detected" by a fixture for the other one.
run_sabotage "the comparative escape stops firing (the adverb becomes a doubt)" \
  src/php/Rent/Core/TenureClassifier.php \
  '/private function firstNonComparativeOccurrence/,$ s@if (!$this->matches@if (true || !$this->matches@'

# The title/description boundary. Two independent halves — the fold that preserves the newline, and
# the anchor that stops PCRE matching `$` in front of it. Breaking EITHER restores the leak.
run_sabotage "folding flattens the title/description newline again" \
  src/php/Core/Text.php \
  's|\[^\\S\\n\]+|\\s+|'

run_sabotage "conventionne adjacency anchor reverts to \$ (which matches before a final newline)" \
  src/php/Rent/Core/TenureClassifier.php \
  's|\*\\z/u|*$/u|'

# ROUND 6: the doubt floor on the PROSE surface, and the four consumers of the boundary rule.
run_sabotage "prose branch goes silent again (no collocation noun => no signal, no doubt)" \
  src/php/Rent/Core/TenureClassifier.php \
  '/\$hit = \$this->financingAcronymPosition/,$ s|\$doubtAt = \$this->firstNonComparativeOccurrence(\$cased, \$acronym, caseInsensitive: false);|$doubtAt = null;|'

run_sabotage "prose doubt floor goes case-INsensitive (digests the adverb too)" \
  src/php/Rent/Core/TenureClassifier.php \
  's|caseInsensitive: false|caseInsensitive: true|'

run_sabotage "a newline stops ending the financing phrase" \
  src/php/Rent/Core/TenureClassifier.php \
  's@\^\[^\\S\\n\]\*(\\n|\$)@^[^\\S\\n]*($)@'

# The comparative escape exists in TWO methods, and an earlier note here claimed a sabotage was
# unnecessary for "the comparative escape" as though there were one. That is true only of the copy
# inside financingAcronymPosition(), which sits below a phrase-end test that returns first — verified
# inert. The copy in firstNonComparativeOccurrence() has NO phrase-end test above it and is the sole
# guard on the doubt floor, so reverting it to `\s*` silently reopened the boundary hole with the
# suite green. One note stretched over two call sites; each now has its own answer.
run_sabotage "the doubt floor's comparative escape reads across the boundary" \
  src/php/Rent/Core/TenureClassifier.php \
  '/private function firstNonComparativeOccurrence/,$ s@\[^\\S\\n\]\*(?i:@\\s*(?i:@'

run_sabotage "the collocation separator spans the title/description newline" \
  src/php/Rent/Core/TenureClassifier.php \
  "s|(?:\[^\\\\S\\\\n\]\|\[\\\\/\\\\-,:;()\]){1,3}|[\\\\s\\\\/\\\\-,:;()]{1,3}|"

run_sabotage "an eligible label may be assembled across a multi-line FIELD value" \
  src/php/Rent/Core/TenureClassifier.php \
  "s|isset(\$hit\['matched'\])|false|"

run_sabotage "the field NAME stops being read for excluded vocabulary" \
  src/php/Rent/Core/TenureClassifier.php \
  's|$nameSignal = $this->excludedVocabularyIn((string) $name);|$nameSignal = null;|'

run_sabotage "the unrecognised-surface scan sees LABELS only again (PLUS goes blind)" \
  src/php/Rent/Core/TenureClassifier.php \
  's|foreach (array_keys(self::AMBIGUOUS_LABELS) as $acronym) {|foreach ([] as $acronym) {|'

run_sabotage "an unreadable field value is silently dropped instead of digested" \
  src/php/Rent/Core/TenureClassifier.php \
  's|if (!is_scalar($value) \&\& !$value instanceof \\Stringable \&\& $value !== null) {|if (false) {|'

run_sabotage "procedural surfaces are concatenated again (literals assemble across field joins)" \
  src/php/Rent/Core/TenureClassifier.php \
  's|$surfaces\[\] = $folded;|$surfaces[0] .= "\\n" . $folded;|'

run_sabotage "incidental surfaces refuse instead of decoding (one entity kills the listing)" \
  src/php/Core/Text.php \
  's|return self::fold(self::decodeEntities($raw));|return self::fold($raw);|'

# The §1 direction of the tolerant fold: decoding must RESTORE a token an entity split, not paper
# over it. The first implementation substituted a space and `PL&shy;AI` folded to `pl ai` — no
# match, no doubt, NOTIFIED. All three reviewers reproduced it independently.
run_sabotage "the tolerant fold substitutes a space instead of decoding (an entity hides PLAI)" \
  src/php/Core/Text.php \
  "s|\$next = html_entity_decode(\$decoded, ENT_QUOTES \| ENT_HTML5, 'UTF-8');|\$next = (string) preg_replace('/\&[a-zA-Z0-9#]{1,10};/', ' ', \$decoded);|"

# The identifier split must run on the INVISIBLE-STRIPPED form. On the merely-decoded string a soft
# hyphen reads as a separator, so `plai<U+00AD>sir` splits into the word `plai` and invents a match
# inside *plaisir* — the silent drop Text::hasToken() exists to prevent.
run_sabotage "the identifier split runs before invisibles are stripped (plaisir becomes plai)" \
  src/php/Rent/Core/TenureClassifier.php \
  's|$cased = Text::foldTolerantPreserveCase((string) $value);|$cased = Text::decodeEntities((string) $value);|'

run_sabotage "the vocabulary filters to isExcluded() again (numero unique goes blind)" \
  src/php/Rent/Core/TenureClassifier.php \
  '/private static function vocabularyKeys/,$ s|if ($tenure->isEligible()) {|if (!$tenure->isExcluded()) {|'

run_sabotage "identifier spellings stop being split (demandeLogementSocial goes blind)" \
  src/php/Rent/Core/TenureClassifier.php \
  "s|foreach (\[\$folded, \$split\] as \$haystack) {|foreach ([\$folded] as \$haystack) {|"

run_sabotage "separator-free key containment removed (numeroUnique goes blind)" \
  src/php/Rent/Core/TenureClassifier.php \
  's|foreach (self::vocabularyKeys() as $normalised => $literal) {|foreach ([] as $normalised => $literal) {|'

run_sabotage "the listing url/commune/postcode/externalId stop being read" \
  src/php/Rent/Core/TenureClassifier.php \
  "s|'postcode' => \$listing->postcode, 'externalId' => \$listing->externalId\] as \$what => \$text) {|'postcode' => null, 'externalId' => null] as \$what => \$text) {|"

run_sabotage "an unreadable surface is read as empty again (breakage becomes absence)" \
  src/php/Rent/Core/TenureClassifier.php \
  's|if ($folded === null) {|if (false) {|'
run_sabotage "sans negates across the title/description boundary again" \
  src/php/Rent/Core/TenureClassifier.php \
  "s|'/\\\\bsans\[^\\\\S\\\\n\]+\\\\z/u'|'/\\\\bsans\\\\s+\$/u'|"

run_sabotage "procedural tells stop reading structured fields" \
  src/php/Rent/Core/TenureClassifier.php \
  's|foreach ($this->proceduralSurfaces($listing) as $folded) {|foreach ([Text::fold($listing->text())] as $folded) {|'

run_sabotage "an excluded label in an unrecognised field is ignored again" \
  src/php/Rent/Core/TenureClassifier.php \
  's|$unknownFieldDoubt = $this->excludedVocabularyIn($value);|$unknownFieldDoubt = null;|'

run_sabotage "an eligible tell may be assembled across a phrase boundary" \
  src/php/Rent/Core/TenureClassifier.php \
  's|if (\$tenure->isEligible() \&\& str_contains(\$matched, "\\n")) {|if (false) {|'

run_sabotage "an eligible LABEL may be assembled across a phrase boundary" \
  src/php/Rent/Core/TenureClassifier.php \
  "s|\$hit\['tenure'\]->isEligible() && str_contains|false \&\& str_contains|"

run_sabotage "reasons[] stop being collapsed to one line" \
  src/php/Rent/Core/TenureClassifier.php \
  's|return trim((string) preg_replace(./\\s+/u., . ., \$fragment));|return $fragment;|'

# The two folded surfaces must stay byte-aligned: label positions come from fold(), the ambiguous
# acronym's from foldPreserveCase(), and the resolver compares them directly. mb_strtolower breaks
# that for 27 codepoints (İ, ẞ, the Kelvin sign …) — which is what this code did until round 4.
run_sabotage "fold() lowercases multibyte again (byte offsets diverge between the two surfaces)" \
  src/php/Core/Text.php \
  's/return strtolower(self::foldPreserveCase($raw));/return mb_strtolower(self::foldPreserveCase($raw), "UTF-8");/'

run_sabotage "word boundaries dropped (substring match: 'plaine' becomes PLAI)" \
  src/php/Core/Text.php \
  "s|'/(?<!\[a-z0-9\])' . preg_quote(\$needle, '/') . '(?!\[a-z0-9\])/u'|'/' . preg_quote(\$needle, '/') . '/u'|"

run_sabotage "source default raised above the fail-closed floor" \
  src/php/Rent/Core/TenureClassifier.php \
  's/5 => 50\]/5 => 95]/'

run_sabotage "fail-closed floor lowered" \
  src/php/Rent/Core/TenureClassifier.php \
  's/public const int FLOOR_BP = 60;/public const int FLOOR_BP = 10;/'

run_sabotage "mixedTenure defaults to false (config omission opens the gate)" \
  src/php/Rent/Core/SourceProfile.php \
  's/public bool $mixedTenure = true,/public bool $mixedTenure = false,/'

run_sabotage "UNKNOWN routed to the notification channel" \
  src/php/Rent/Core/TenureClassifier.php \
  's/if ($tenure === Tenure::UNKNOWN) {/if (false) {/'

run_sabotage "fail-closed downgrade removed (mixed source keeps an eligible tenure)" \
  src/php/Rent/Core/TenureClassifier.php \
  's/if ($tenure->isEligible()$/if (false \&\& $tenure->isEligible()/'

# The gate's THIRD term, added 2026-08-23 when In'li turned out to publish PLS. Dropping it makes a
# weakly-labelled listing on a mixed source match before its detail page has ever been read — which
# is the disarmed state In'li shipped in, now reachable by deleting four words instead of by
# believing a source's own description of itself.
# BOTH LAYERS MEASURED INDIVIDUALLY (2026-09-06). The second expression's site — `route()`'s
# `mixedTenure && !$detailRead ? DIGEST` mirror — is MASKED by the rule the first one mutates:
# every Tenure is exactly one of eligible, excluded or UNKNOWN, so reaching that line means
# eligible-and-below-floor, which is the rule's own condition, and the rule has already rewritten
# the tenure to UNKNOWN. Isolated, that expression left the whole suite green — a joined case
# reporting `ok` on its first expression says nothing about the second, which is why each was run
# alone rather than inferred from the pair. `TenureClassifierTest::testTheRoutingMirrorDigests...`
# now pins the mirror through reflection, and the isolated expression reddens. The mirror is kept
# rather than deleted: it goes live exactly when someone weakens the rule above it.
run_sabotage "the fail-closed rule stops requiring the detail page to have been read" \
  src/php/Rent/Core/TenureClassifier.php \
  's/\&\& !$detailRead) {/) {/;s/$source->mixedTenure \&\& !$detailRead ? Outcome::DIGEST/$source->mixedTenure \&\& false ? Outcome::DIGEST/'

# And its mirror: hydration must not become a licence. If `detailRead` were allowed to short-circuit
# the EXCLUSION rules rather than only the source-default floor, reading a page would turn an
# explicit PLS into a match — the exact inversion §1 exists to prevent.
run_sabotage "reading the detail page licenses an excluded listing" \
  src/php/Rent/Core/TenureClassifier.php \
  's/if ($tenure->isExcluded()) {/if ($tenure->isExcluded() \&\& !$detailRead) {/'

# BOTH encoding guards at once, deliberately — and the reason is worth recording.
# The malformed-UTF-8 refusal is defence in depth: the `mb_check_encoding` gate at the top of
# foldPreserveCase() and the null-check on the preg results below it each catch the same inputs, so
# removing EITHER ONE leaves the suite correctly green. Sabotaging one alone would report an
# undetected regression that is nothing of the sort. What must be load-bearing is the pair, so the
# pair is what gets broken here.
run_sabotage "both encoding guards removed (malformed UTF-8 accepted again)" \
  src/php/Core/Text.php \
  's/!mb_check_encoding(/!true \&\& mb_check_encoding(/; s/if ($collapsed === null) {/if (false) {/'

run_sabotage "undecoded HTML entities accepted as text" \
  src/php/Core/Text.php \
  's/throw MalformedText::undecodedEntities($entity\[0\]);/;/'

run_sabotage "combining marks no longer stripped (NFD text stops matching)" \
  src/php/Core/Text.php \
  '/p{Mn}/d'

run_sabotage "conventionne exception widened back to any eligible tenure" \
  src/php/Rent/Core/TenureClassifier.php \
  's/if ($other->tenure !== Tenure::LLI) {/if (!$other->tenure->isEligible()) {/'

run_sabotage "ambiguous uppercase acronym guessed instead of digested" \
  src/php/Rent/Core/TenureClassifier.php \
  's/return $ambiguousAt === null ? null : \[$ambiguousAt, false\];/return $ambiguousAt === null ? null : [$ambiguousAt, true];/'

run_sabotage "French inflection dropped (labels matched exactly again)" \
  src/php/Core/Text.php \
  's/\$parts\[\] = preg_quote(\$word, .\/.) . .(?:es|e|s|x)?.;/$parts[] = preg_quote($word, "\/");/'

run_sabotage "the -al\/-aux branch dropped (logements sociaux stops matching)" \
  src/php/Core/Text.php \
  "s/} elseif (str_ends_with(\$word, 'al')) {/} elseif (false) {/"

run_sabotage "doubts compete positionally again (indecidable marker resolved as a tenure)" \
  src/php/Rent/Core/TenureClassifier.php \
  's/if ($s->tenure === Tenure::UNKNOWN) {/if (false) {/'

run_sabotage "doubts no longer withhold an otherwise-eligible verdict" \
  src/php/Rent/Core/TenureClassifier.php \
  's/if ($objections !== \[\] || $doubts !== \[\]) {/if ($objections !== []) {/'

run_sabotage "prose field values bypass the collocation guard again" \
  src/php/Rent/Core/TenureClassifier.php \
  's/preg_quote($acronym, .\/.)/mb_strtolower(preg_quote($acronym, "\/"))/'

# The adjacency test is the whole exception. Three separate ways to defeat it, because the first two
# versions of this code failed in three separate ways and each fix was written blind to the next.
# The `$between <= $s->position` half alone is not the guard — it only says the label ENDS before the
# word. Deleting the whitespace-only test is what makes any LLI anywhere in the text qualify a
# `conventionné`, which is v1 of this code and the shape that MATCHED a mixed résidence.
run_sabotage "conventionne exception unbounded again (any LLI anywhere deletes it)" \
  src/php/Rent/Core/TenureClassifier.php \
  's/^\( *\)&& $this->matches(.*substr($folded, $between, $s->position - $between))) {$/\1\&\& true) {/'

# NOTE: there is no "direction-aware" sabotage here. One was written, and it proved the explicit
# `$other->position > $s->position` skip was UNREACHABLE — `$between` is `position + length`, so a
# label starting after `conventionné` always ends after it and fails `$between <= $s->position`
# regardless. The dead clause was deleted rather than fixture-covered; direction is now asserted
# directly by TenureClassifierTest::testConventionneIsOnlyExcusedByALabelThatPrecedesIt().

run_sabotage "conventionne adjacency measures the table literal, not the matched text" \
  src/php/Rent/Core/TenureClassifier.php \
  's/$between = $other->position + $other->length;/$between = $other->position + strlen($other->evidence);/'

run_sabotage "invisible non-Cf characters no longer stripped" \
  src/php/Core/Text.php \
  "s/self::INVISIBLE/''/"

run_sabotage "numero unique alone becomes a determinate rejection again" \
  src/php/Rent/Core/TenureClassifier.php \
  "s/'numero unique' => Tenure::UNKNOWN,/'numero unique' => Tenure::SOCIAL,/"

# NOT SABOTAGED, and the reason is worth recording rather than quietly omitting.
# `if ($folded === '') { continue; }` in structuredFieldSignals() is defence in depth, not a
# load-bearing guard: Text::tokenPosition() already returns null for an empty haystack, so removing
# the check changes no behaviour and the suite correctly stays green. The BEHAVIOUR is covered by
# TenureClassifierTest::testEmptyStructuredFieldDoesNotFireTierOne(); it simply cannot be broken by
# deleting one of two redundant guards. Listing it as a sabotage would report a hole that is not one.

run_sabotage "tier 5 consulted even when higher tiers fired" \
  src/php/Rent/Core/TenureClassifier.php \
  's/array_filter($signals) === \[\] \&\& $doubts === \[\]/true/'

run_sabotage "SOCIAL stops corroborating the excluded tenures" \
  src/php/Rent/Core/Tenure.php \
  's/return $other->isExcluded();/return false;/'

run_sabotage "tier tie-break ignores position (first table entry wins)" \
  src/php/Rent/Core/TenureClassifier.php \
  's/return $tierSignals\[0\];/return array_values($tierSignals)[count($tierSignals) - 1];/'

run_sabotage "'sans' negation lookbehind removed" \
  src/php/Rent/Core/TenureClassifier.php \
  's/&& $this->isPrecededBySans($folded, $position)/\&\& false/'

run_sabotage "conventionne exception removed entirely (genuine LLI stock digests)" \
  src/php/Rent/Core/TenureClassifier.php \
  's/if ($s->tenure !== Tenure::CONVENTIONNE || $s->evidence !== .conventionne.) {/if (true) {/'

# ── The store ─────────────────────────────────────────────────────────────────────────────────────
#
# Same argument, different subsystem. The store's failure modes are silent in the direction that
# hurts most: a seen-set that stops persisting re-notifies the entire market at once, a price history
# that stops recording loses evidence that cannot be reconstructed (a listing only ever shows its
# CURRENT rent), and a run log that mis-derives health reports a broken selector as a quiet market —
# `CLAUDE.md` hard rule 2, the defect this whole project is shaped around.
#
# None of those raise an exception. Every one of them leaves the suite green unless a test is
# actually looking, so "the store suite passes" proves nothing on its own.

run_sabotage "unknown rent reads as a drop to zero (null treated as 0)" \
  src/php/Rent/Store/Store.php \
  's%($rentCc !== null && $previousRentCc !== null) ? $rentCc - $previousRentCc : null%($rentCc ?? 0) - ($previousRentCc ?? 0)%'

run_sabotage "last known rent erased when a source stops publishing it" \
  src/php/Rent/Store/Store.php \
  's%COALESCE(:rent, rent_cc)%:rent%'

run_sabotage "price history records unchanged rents (no longer changes-only)" \
  src/php/Rent/Store/Store.php \
  's%$rentCc !== null && $rentCc !== $chronoBefore%$rentCc !== null%'

run_sabotage "seen-set stops persisting (every run re-notifies everything)" \
  src/php/Rent/Store/Store.php \
  "s%'sqlite:' . \$path%'sqlite::memory:'%"

run_sabotage "notified flag never read (a digested listing counts as notified)" \
  src/php/Rent/Store/Store.php \
  "s%\$row !== false && \$row\['notified_at'\] !== null%true%"

run_sabotage "marking an unknown listing notified is a silent no-op" \
  src/php/Rent/Store/Store.php \
  's%if ($statement->rowCount() === 0) {%if (false) {%'

run_sabotage "dedup key no longer scoped to the source (two feeds collide on id 17)" \
  src/php/Rent/Store/Store.php \
  "s%return \$source . ':id:' . rawurlencode(\$externalId);%return ':id:' . rawurlencode(\$externalId);%"

run_sabotage "URL normalisation bypassed (a #fragment forks the identity)" \
  src/php/Rent/Store/Store.php \
  's%if ($parts === false || !isset($parts\[.host.\])) {%if (true) {%'

run_sabotage "URL path folded with the host (two distinct listings over-merge)" \
  src/php/Rent/Store/Store.php \
  's%return $rebuilt;%return strtolower($rebuilt);%'

run_sabotage "unparseable timestamp silently becomes the epoch" \
  src/php/Core/RunStore.php \
  's@throw new .InvalidArgumentException(sprintf(.horodatage ISO-8601 illisible : %s., $iso));@return 0;@'

run_sabotage "a database from a NEWER schema is operated on anyway" \
  src/php/Rent/Store/Store.php \
  's%if ($recorded > self::SCHEMA_VERSION) {%if (false) {%'

run_sabotage "a source that never ran is reported healthy" \
  src/php/Core/RunStore.php \
  's%status: SourceStatus::NEVER_RUN,%status: SourceStatus::OK,%'

run_sabotage "a failed last run no longer reports BROKEN" \
  src/php/Core/RunStore.php \
  's%if (!$lastOk) {%if (false) {%'

run_sabotage "a failed run extends the empty streak (failure read as 'nothing found')" \
  src/php/Core/RunStore.php \
  "s%(int) \$run\['ok'\] !== 1 || %%"

run_sabotage "run threshold raised out of reach — empty AND failed (a dead source stays OK)" \
  src/php/Core/RunStore.php \
  's%self::EMPTY_RUNS_BEFORE_BROKEN%99%'

# ---- a single failed run is not a broken source (2026-09-07) -------------------------------------
#
# in'li sent 29 broken + 30 retablie in four days, out of 428 runs, while returning 165 annonces on
# the passes either side. Every guarantee below is one the flap walked through.

run_sabotage "an isolated failure is announced again (the in'li flap returns)" \
  src/php/Core/RunStore.php \
  's%$observed = self::observedRuns($runs);%$observed = $runs;%'

run_sabotage "every failure is tolerated for ever, however long the streak (a dead source stays OK)" \
  src/php/Core/RunStore.php \
  's%($episode) >= self::EMPTY_RUNS_BEFORE_BROKEN%($episode) >= 99%'

# The trailing-only version of the rule, which is what shipped first and what the LIVE car store
# refuted: leboncoin's failure sat three runs from the end, so a 308-run empty streak still
# truncated to 3 — and a streak rebuilding through 1 and 2 reports OK, which is *retablie* plus a
# wiped cooldown. The flap survived its own fix on the one source the fix was not about.
# `$emptyStreak` is counted in `$observed`, so the streak's start index must be too. Mixing them
# lands one row early and averages a run the streak already contains — a 25-listing baseline was
# reported as 12.5, and it was an existing test that caught it, not review.
run_sabotage "the empty streak is counted in one array and indexed in the other" \
  src/php/Core/RunStore.php \
  's%($observed) - $emptyStreak%($runs) - $emptyStreak%'

run_sabotage "the strip is trailing-only again (an interior hiccup truncates a dead feed's streak)" \
  src/php/Core/RunStore.php \
  's%self::observedRuns($runs)%array_slice($runs, 0, count($runs) - $failedStreak)%'

run_sabotage "a tolerated failure is no longer named, so another verdict buries the exception" \
  src/php/Core/RunStore.php \
  's%detail: $detail . $tolerated,%detail: $detail,%'

run_sabotage "one hiccup resets a dead feed's empty streak, buying it three more silent passes" \
  src/php/Core/RunStore.php \
  's%self::trailingEmptyRuns($observed)%self::trailingEmptyRuns($runs)%'

# THE COUNTERWEIGHT THE WHOLE TOLERANCE RESTS ON. Isolated failures are only safe to tolerate
# because a source failing at a FLAKY RATE still alerts, however isolated each failure is — so the
# flaky windows deliberately count ATTEMPTS and read the whole log. Pointing them at `$observed` is
# the obvious "consistency" edit, and it silences every flaky verdict on exactly the sources this
# rule tolerates. Round 9 finding: nothing asserted it, because RunStoreFlakyWindowTest seeds its
# failures FIRST, where they are never tolerated.
run_sabotage "the flaky windows count observations instead of attempts (a tolerated tail hides the rate)" \
  src/php/Core/RunStore.php \
  's%windowCounts($runs, $edge%windowCounts($observed, $edge%'

run_sabotage "the drop warning reads the failed run's zero again (the flap under a new subject)" \
  src/php/Core/RunStore.php \
  "s%\$lastCount = (int) \$last\['item_count'\];%\$lastCount = (int) \$runs[array_key_last(\$runs)]['item_count'];%"

run_sabotage "zero-baseline check removed (a genuinely quiet source is cried wolf on)" \
  src/php/Core/RunStore.php \
  's%if ($baseline > 0.0) {%if (true) {%'

run_sabotage "drop-below-mean warning threshold neutralised" \
  src/php/Core/RunStore.php \
  's%$rollingMean \* self::DROP_WARNING_RATIO%0.0%'

# ── The store, round two ──────────────────────────────────────────────────────────────────────────
#
# Everything below was added after a three-lens review panel found 25 defects in the first cut,
# including two P0s that were hard rule 2's own failure shape reintroduced by the module written to
# prevent it. Five of these guarantees were ALSO shown to be untested by a reviewer running this very
# script against them — the suite stayed green on all five. That is the argument for this file in one
# sentence: the tests looked thorough and were not, and only sabotage said so.

run_sabotage "unknown baseline treated as a zero baseline (broken-after-a-gap reads OK)" \
  src/php/Core/RunStore.php \
  's%$baseline = self::lastProductiveCount($observed, $streakStart);%$baseline = 0.0;%'

run_sabotage "trailing Z no longer normalised (parsed in the host timezone)" \
  src/php/Core/RunStore.php \
  's%$normalised = str_ends_with($iso, .Z.) ? substr($iso, 0, -1) . .+00:00. : $iso;%$normalised = $iso;%'

run_sabotage "timestamp round-trip check dropped (2026-02-30 rolls forward to 2 March)" \
  src/php/Core/RunStore.php \
  's%&& $parsed->format($format) === $normalised%%'

run_sabotage "Unicode trim reverts to ASCII trim (an nbsp id collapses the whole run)" \
  src/php/Rent/Store/Store.php \
  's%trim($value, ".*")%trim($value)%'

run_sabotage "no-information floor removed (id-less, url-less, title-less listings share one key)" \
  src/php/Rent/Store/Store.php \
  's%if ($url === .. && $title === ..) {%if (false) {%'

run_sabotage "previous rent read by write order, not chronological order" \
  src/php/Rent/Store/Store.php \
  's%WHERE dedup_key = :key AND at_epoch <= :epoch%WHERE dedup_key = :key%'

run_sabotage "changes-only invariant drops its forward check (duplicate consecutive rents)" \
  src/php/Rent/Store/Store.php \
  's%&& $rentCc !== $this->rentAfter($key, $epoch)%%'

run_sabotage "a stale sighting overwrites the current state" \
  src/php/Rent/Store/Store.php \
  's%} elseif (!$isCurrent) {%} elseif (false) {%'

run_sabotage "a partial re-parse erases the stored URL" \
  src/php/Rent/Store/Store.php \
  's%url          = COALESCE(NULLIF(:url, ....), url),%url          = :url,%'

run_sabotage "a partial re-parse erases the stored title" \
  src/php/Rent/Store/Store.php \
  's%title        = COALESCE(NULLIF(:title, ....), title),%title        = :title,%'

run_sabotage "price history ordered by insertion id rather than by time" \
  src/php/Rent/Store/Store.php \
  's%SELECT rent_cc FROM price_history WHERE dedup_key = :key ORDER BY at_epoch ASC, id ASC%SELECT rent_cc FROM price_history WHERE dedup_key = :key ORDER BY id ASC%'

# This slot used to hold a "NOT SABOTAGED" note claiming the run-log ordering was redundant because
# `recordRun()` refused out-of-order writes. A reviewer proved the excuse false on two counts, and
# the refusal itself turned out to be the worse bug — it deleted the runs it refused. Both the
# ordering and its opposite are now real, tested guarantees:

run_sabotage "run recency read from the timestamp again (one skewed clock hides every later run)" \
  src/php/Core/RunStore.php \
  's%WHERE source = :source ORDER BY id ASC%WHERE source = :source ORDER BY at_epoch ASC, id ASC%'

run_sabotage "never-productive source hides behind OK again" \
  src/php/Core/RunStore.php \
  's%if (!$everProduced && $successfulRuns >= self::EMPTY_RUNS_BEFORE_BROKEN%if (false%'

run_sabotage "a source failing half its fetches reads as healthy" \
  src/php/Core/RunStore.php \
  's%if ($runsInWindow >= self::MIN_RUNS_FOR_FLAKY%if (false%'

run_sabotage "adapter error text persisted and shown unredacted" \
  src/php/Core/RunStore.php \
  's%Redact::text($error)%$error%'

run_sabotage "only BROKEN alerts (NEVER_RUN and NEVER_PRODUCED go quiet)" \
  src/php/Core/SourceStatus.php \
  's%return $this !== self::OK;%return $this === self::BROKEN;%'

run_sabotage "the secret-name list is emptied (key= and token= pass through)" \
  src/php/Core/Redact.php \
  "s%'signature', 'token',%'zzz-no-such-name',%"

run_sabotage "mailbox masking neutralised (the IMAP address reaches the notification)" \
  src/php/Core/Redact.php \
  's%{2,}%{99,}%'

# THE PERCENT-ENCODED HALF (2026-09-09). A URL writes `@` as `%40`, and a source's own URL can be
# the disclosure: PAP puts the subscriber's address in the link it emails, on all 98 of its stored
# rows, while not one stored URL carries a literal `@`. The case above cannot see this — it degrades
# the TLD quantifier, which both separators share — so the literal-`@` case stayed green for as long
# as this gap was open. Delimiter `#`, because the pattern under test contains `%`.
run_sabotage "the percent-encoded mailbox separator is dropped (email=x%40y.com leaks)" \
  src/php/Core/Redact.php \
  's#(?:@|%40)#@#'

run_sabotage "redaction fails OPEN on a PCRE error (raw text returned)" \
  src/php/Core/Redact.php \
  's%return self::MASK;%return $message;%'


# ── The store, round three ────────────────────────────────────────────────────────────────────────
#
# Round 10's panel returned 30 findings against round 9's fixes, four of them P0 — every one a case
# where the repair was one step shallower than the defect. That is the pattern this file exists to
# interrupt, so each of those repairs now has a sabotage of its own.

run_sabotage "schema version stops tracking the schema (an older database opens and then throws)" \
  src/php/Rent/Store/Store.php \
  's%public const int SCHEMA_VERSION = [0-9]\+;%public const int SCHEMA_VERSION = 1;%'

run_sabotage "the v1 upgrade never runs (seen_epoch column missing forever)" \
  src/php/Rent/Store/Store.php \
  's%if ($recorded < 2) {%if (false) {%'

run_sabotage "the upgrade forgets to re-stamp the version (every open re-migrates)" \
  src/php/Rent/Store/Store.php \
  "s%\$stamp->execute(\['value' => (string) self::SCHEMA_VERSION\]);%%"

run_sabotage "seen_epoch backfilled to zero (every stored listing reads as older than anything)" \
  src/php/Rent/Store/Store.php \
  's%$epoch = self::epoch((string) $row\[.last_seen_at.\]);%$epoch = 0;%'

run_sabotage "baseline falls back to the last SUCCESSFUL run again (one quiet day zeroes it)" \
  src/php/Core/RunStore.php \
  "s%&& (int) \$runs\[\$i\]\['item_count'\] > 0%%"

run_sabotage "the rolling window loses its upper bound (a 2036 run inflates every later mean)" \
  src/php/Core/RunStore.php \
  's%if ($at < $cutoff || $at > $reference) {%if ($at < $cutoff) {%'

run_sabotage "the window scan stops at the first out-of-range row instead of skipping it" \
  src/php/Core/RunStore.php \
  's%                continue;%                break;%'

run_sabotage "a superseded sighting counts as a price drop again" \
  src/php/Rent/Store/Store.php \
  's%isPriceDrop: $isCurrent && $delta !== null && $delta < 0,%isPriceDrop: $delta !== null \&\& $delta < 0,%'

run_sabotage "the rent comparison reads the changes-only history again (a real drop is swallowed)" \
  src/php/Rent/Store/Store.php \
  's%$previousRentCc = $isCurrent%$previousRentCc = false%'

run_sabotage "a stale sighting can no longer fill a missing URL" \
  src/php/Rent/Store/Store.php \
  's%SET url     = COALESCE(NULLIF(url, ....), NULLIF(:url, ....), url),%SET url     = url,%'

run_sabotage "a stale sighting overwrites the URL instead of filling it" \
  src/php/Rent/Store/Store.php \
  's%SET url     = COALESCE(NULLIF(url, ....), NULLIF(:url, ....), url),%SET url     = :url,%'

run_sabotage "the rollback is unguarded again (disk-full reports 'no active transaction')" \
  src/php/Rent/Store/Store.php \
  's%} catch (\\Throwable) {%} catch (\\LogicException) {%'

run_sabotage "STALE never fires (a source whose schedule stopped reads OK forever)" \
  src/php/Core/RunStore.php \
  's%if ($silentFor > self::ROLLING_WINDOW_DAYS \* 86400) {%if (false) {%'

run_sabotage "NEVER_PRODUCED loses its time floor (a source is accused 45 minutes after onboarding)" \
  src/php/Core/RunStore.php \
  's%&& $span >= self::MIN_SPAN_FOR_NEVER_PRODUCED%%'

run_sabotage "millisecond timestamps refused again (what every JSON API emits)" \
  src/php/Core/RunStore.php \
  's%str_pad($m\[1\], 6, .0.)%$m[1]%'

run_sabotage "IMAP LOGIN / POP3 PASS credentials pass through unmasked" \
  src/php/Core/Redact.php \
  "s%(LOGIN)%(zzz-no-such-verb)%"

run_sabotage "the Telegram bot token in a URL path passes through" \
  src/php/Core/Redact.php \
  's%bot%zzz-no-such-verb%g'

run_sabotage "the ntfy topic in a URL path passes through" \
  src/php/Core/Redact.php \
  's%(ntfy\\.\[A-Za-z0-9.\\-\]+/)%(zzz-no-such-host/)%'

run_sabotage "the RFR income figure passes through" \
  src/php/Core/Redact.php \
  's%(RFR)\[%(zzz-no-such-name)[%'

run_sabotage "'pass' dropped from the secret names (what imap_open emits)" \
  src/php/Core/Redact.php \
  "s%'mot de passe', 'motdepasse', 'pass', %%"

run_sabotage "a URL value is masked as if it were a secret (the failing endpoint is destroyed)" \
  src/php/Core/Redact.php \
  "s%(?!https?://)%%"

run_sabotage "ambiguous names accept ':' again ('Erreur auth: <url>' eats the url)" \
  src/php/Core/Redact.php \
  "s%'signature', 'token',%'signature', 'token', 'auth', 'key',%"


# ── The store, round four ─────────────────────────────────────────────────────────────────────────
#
# Round 11's panel returned 35 findings against round 10's fixes, four P0 — and one of them, again,
# was a repair that made things worse (the monotonic run log). It also caught something process-level
# that no sabotage can: the tree was NOT frozen while the panel ran, so three findings were being
# repaired concurrently and the round could not be scored. Freeze first.

run_sabotage "the changes-only guard reads the delta baseline again (duplicate rents)" \
  src/php/Rent/Store/Store.php \
  's%$rentCc !== $chronoBefore%$rentCc !== $previousRentCc%'

run_sabotage "recency ignores the clock (a late-committed run erases BROKEN)" \
  src/php/Core/RunStore.php \
  's%if ($nowIso !== null) {%if (false) {%'

run_sabotage "a future-stamped run is trusted even when a clock is available" \
  src/php/Core/RunStore.php \
  's%$at <= $now%true%'

run_sabotage "an unstamped legacy database is stamped current instead of upgraded" \
  src/php/Rent/Store/Store.php \
  's%$recorded = 1;%return;%'

run_sabotage "an undateable row brings the whole upgrade down again" \
  src/php/Rent/Store/Store.php \
  's%} catch (\\InvalidArgumentException) {%} catch (\\RangeException) {%'

run_sabotage "an empty-string URL is written over a known one" \
  src/php/Rent/Store/Store.php \
  's%url          = COALESCE(NULLIF(:url, ....), url),%url          = COALESCE(:url, url),%'

run_sabotage "WAL is never requested (two processes contend instead of sharing)" \
  src/php/Rent/Store/Store.php \
  "s%PRAGMA journal_mode = WAL%PRAGMA journal_mode = delete%"

run_sabotage "the busy timeout is dropped (a second writer fails instantly)" \
  src/php/Rent/Store/Store.php \
  "s%PRAGMA busy_timeout = %PRAGMA cache_size = %"

run_sabotage "fractional seconds narrow again (a Go feed's .1Z is refused)" \
  src/php/Core/RunStore.php \
  's%str_pad($m\[1\], 6, .0.)%$m[1]%'

run_sabotage "secret names stop matching inside an env-var (IMAP_PASSWORD leaks)" \
  src/php/Core/Redact.php \
  "s%^        \$before = .*%        \$before = '(?<![A-Za-z0-9_])';%"

run_sabotage "a single-quoted secret value stops being masked" \
  src/php/Core/Redact.php \
  "s%\$quoted = .*;%\$quoted = '\"[^\"]*\"';%"

run_sabotage "the mask can be re-masked (orphan brackets accumulate)" \
  src/php/Core/Redact.php \
  "s%(?!' . preg_quote(self::MASK, '~') . ')%%"

run_sabotage "the bare Telegram token pattern is removed" \
  src/php/Core/Redact.php \
  's%\\b.d{8,12}:\[A-Za-z0-9_\\-\]{30,}%zzz-no-such-token%'

run_sabotage "SASL AUTH PLAIN base64 passes through" \
  src/php/Core/Redact.php \
  's%(AUTH|AUTHENTICATE)%(zzz-no-such-verb)%'

run_sabotage "the padded-base64 blob pattern is removed" \
  src/php/Core/Redact.php \
  's%{16,}%{999,}%g'

run_sabotage "the LOGIN stoplist is bypassed (French prose is eaten)" \
  src/php/Core/Redact.php \
  's%(LOGIN)\[ .t\]+. . $notProse . .%(LOGIN)[ \\t]+\x27 . \x27%'


# ── The store, round five ─────────────────────────────────────────────────────────────────────────
#
# Round 12 returned 27 findings, four P0, and the two worst were BOTH repairs from round 11 that
# went one step too far: a clock-aware recency that discarded real runs when the CLOCK was wrong,
# and a Redact affix that turned every secret name into a substring match. Each now has a sabotage.

run_sabotage "STALE measured from the last-inserted row instead of the newest credible one" \
  src/php/Core/RunStore.php \
  "s%\$silentFor = \$now - max(\$credible);%\$silentFor = \$now - (int) \$last['at_epoch'];%"

run_sabotage "the secret-name affix matches mid-word again (project vocabulary is eaten)" \
  src/php/Core/Redact.php \
  "s%^        \$after = .*%        \$after = '';%"

run_sabotage "the base64 blob drops its padding requirement (identifiers are eaten)" \
  src/php/Core/Redact.php \
  's%\[A-Za-z0-9+/\]{16,}==%[A-Za-z0-9+/]{999,}==%'

run_sabotage "the whole-line base64 rule is removed (SMTP AUTH blobs pass through)" \
  src/php/Core/Redact.php \
  's%(?:\[A-Za-z0-9+/\]{4}){4,}%zzz-no-such-blob%'

run_sabotage "a value of pure punctuation is masked again (JSON parse errors are eaten)" \
  src/php/Core/Redact.php \
  "s%^        \$hasAlnum = .*%        \$hasAlnum = '';%"

run_sabotage "the base64 blob pattern eats long camelCase identifiers again" \
  src/php/Core/Redact.php \
  "s%(?=\[A-Za-z0-9+/\]\*\[0-9+/\])%%"

run_sabotage "record() reverts to a deferred transaction (the busy handler is skipped)" \
  src/php/Rent/Store/Store.php \
  "s%\$this->pdo->exec('BEGIN IMMEDIATE');%\$this->pdo->beginTransaction();%"

# NOT SABOTAGED, and recorded rather than quietly omitted: `upgradeFrom()`'s own `BEGIN IMMEDIATE`.
# The `record()` sabotage uses an unanchored sed that rewrites BOTH sites, so it goes red on
# `record()`'s half alone — reverting only the migration site leaves the suite green. Covering it
# honestly needs two processes racing to open the same v1 database, and the wait it would assert is
# BUSY_TIMEOUT_MS = five seconds, which is not a price this suite should pay on every run. The risk
# is bounded: a migration runs once per database, and losing that race fails loudly.

run_sabotage "journalMode() reports what was asked for, not what was given" \
  src/php/Rent/Store/Store.php \
  "s%\$journalMode = (string) \$mode->fetchColumn();%\$journalMode = 'wal';%"

run_sabotage "the span reverts to last-minus-first (a skewed first run disables the detector)" \
  src/php/Core/RunStore.php \
  "s%\$span = max(\$epochs) - min(\$epochs);%\$span = (int) \$last['at_epoch'] - (int) \$runs[0]['at_epoch'];%"

run_sabotage "fractional seconds are capped at six digits again (Go nanoseconds refused)" \
  src/php/Core/RunStore.php \
  's%(.d+)(?=%(\\d{1,6})(?=%'

# ── The store, round six ──────────────────────────────────────────────────────────────────────────
#
# Round 13 returned 23 findings, two P0, and both were round-12 repairs overshooting: the name affix
# went from matching too much to missing camelCase entirely, and the verb rule went from leaking
# all-letter passwords to leaking any line the adapter had wrapped with its own context.

run_sabotage "the camelCase left boundary is removed (clientSecret stops matching)" \
  src/php/Core/Redact.php \
  's%|(?-i:(?<=\[a-z0-9\])(?=\[A-Z\]))%%'

run_sabotage "the camelCase right boundary is removed (clientSecretKey stops matching)" \
  src/php/Core/Redact.php \
  's%|(?-i:(?=\[A-Z\]\[a-z\]))%%'

run_sabotage "the PASS stoplist is emptied (protocol responses are eaten)" \
  src/php/Core/Redact.php \
  "s%'command|commande|failed%'zzz-no-such-word|failed%"

run_sabotage "the ntfy topic drops back to the ambiguous list (a JSON body leaks it)" \
  src/php/Core/Redact.php \
  "s%^        'topic',\$%%"

run_sabotage "the counting window has no upper edge at all" \
  src/php/Core/RunStore.php \
  's%$edge = $now ?? (int) $runs\[array_key_last($runs)\]\[.at_epoch.\];%$edge = PHP_INT_MAX;%'

# ── The store, round seven ────────────────────────────────────────────────────────────────────────
#
# Round 14 returned 25 findings. Two were the LOGIN rule and the counting window overshooting AGAIN,
# in the opposite direction each time; the rest were guarantees this file was not watching. Every
# sub-part of the two camel boundaries now has its own case, because "remove the whole alternative"
# turned out to pass while three of its four pieces could be degraded silently.

run_sabotage "boundaryL loses its (?-i:) wrapper (bypass= is eaten)" \
  src/php/Core/Redact.php \
  's%(?-i:(?<=\[a-z0-9\])(?=\[A-Z\]))%(?<=[a-z0-9])(?=[A-Z])%'

run_sabotage "boundaryR loses its (?-i:) wrapper (keyword= and signal= are eaten)" \
  src/php/Core/Redact.php \
  's%(?-i:(?=\[A-Z\]\[a-z\]))%(?=[A-Z][a-z])%'

run_sabotage "boundaryR drops the real-hump requirement (PASSAGE= is eaten)" \
  src/php/Core/Redact.php \
  's%(?=\[A-Z\]\[a-z\])%(?=[A-Z])%'

run_sabotage "the AUTHENTICATE rule is removed entirely (a short SASL argument leaks)" \
  src/php/Core/Redact.php \
  's%(AUTHENTICATE)\[ .t\]+(\[A-Za-z0-9%(zzz-no-such-verb)[ \\t]+([A-Za-z0-9%'

run_sabotage "the whole-line base64 rule loses its CRLF tolerance" \
  src/php/Core/Redact.php \
  's%){4,}={0,2})\[ .t\]\*.r?\$~m%){4,}={0,2})[ \\t]*$~m%'

run_sabotage "the whole-line base64 rule drops its multiple-of-four requirement" \
  src/php/Core/Redact.php \
  's%(?:\[A-Za-z0-9+/\]{4}){4,}%[A-Za-z0-9+/]{16,}%'

run_sabotage "the whole-line base64 rule drops its mixed-case requirement" \
  src/php/Core/Redact.php \
  's%(?=\[A-Za-z0-9+/\]\*\[A-Z\])%%'

run_sabotage "the whole-line base64 rule loses its trace-prefix allowance" \
  src/php/Core/Redact.php \
  "s%'~(^|\[:>\]\[ .t\])%'~(^)%"

run_sabotage "the PASS stoplist loses its French half" \
  src/php/Core/Redact.php \
  "s%|erreur|unknown|inconnu%|zzz-no-word-1|unknown|zzz-no-word-2%"

run_sabotage "the stoplist loses its case-insensitivity" \
  src/php/Core/Redact.php \
  "s%(?i:' . self::PROSE_AFTER_VERB%(?:' . self::PROSE_AFTER_VERB%"

run_sabotage "the stoplist boundary reverts to \\b (accented entries go dead)" \
  src/php/Core/Redact.php \
  "s%')(?!\[A-Za-z0-9_\]))'%')..b)'%"

run_sabotage "the byte-fallback trim loses \\x85 and \\xAD" \
  src/php/Rent/Store/Store.php \
  's%.x85.xA0.xAD%\\xA0%'

run_sabotage "the counting window loses its upper edge (a future-stamped row alerts forever)" \
  src/php/Core/RunStore.php \
  's%if ($at < $cutoff || $at > $edge) {%if ($at < $cutoff) {%'

run_sabotage "the counting window ignores the clock (a stale writer hides failures)" \
  src/php/Core/RunStore.php \
  "s%\$edge = \$now ?? (int)%\$edge = (int)%"

# ── The config, adapter and criteria layers, added 2026-08-07 ─────────────────────
# Every failure mode below is SILENT in the same way the classifier's are: a listing that is
# wrongly rejected is indistinguishable from a listing that was never published.

run_sabotage "an unknown room count starts disqualifying (the prototype's bug)" \
  src/php/Rent/Core/CriteriaEngine.php \
  's%\$listing->rooms !== null \&\& \$listing->rooms <%(\$listing->rooms ?? 0) <%'

run_sabotage "an unknown surface starts disqualifying" \
  src/php/Rent/Core/CriteriaEngine.php \
  's%\$listing->surfaceM2 !== null \&\& \$listing->surfaceM2 <%(\$listing->surfaceM2 ?? 0.0) <%'

run_sabotage "charges comprises stops being derived from HC + charges" \
  src/php/Rent/Core/RawListing.php \
  's%if (\$this->rentHc !== null \&\& \$this->charges !== null) {%if (false) {%'

run_sabotage "the title-only exclusion starts matching the description too" \
  src/php/Rent/Config/Criteria.php \
  's%\$foldedTitle = Text::fold(\$title);%\$foldedTitle = Text::fold(\$title . "\\n" . \$description);%'

run_sabotage "a DIGEST verdict gets promoted into scoring" \
  src/php/Rent/Core/CriteriaEngine.php \
  's%if (\$classification->outcome === Outcome::DIGEST) {%if (false) {%'

run_sabotage "an excluded tenure stops being rejected by the criteria engine" \
  src/php/Rent/Core/CriteriaEngine.php \
  's%if (\$classification->outcome === Outcome::REJECT) {%if (false) {%'

run_sabotage "the score stops being clamped (a penalty can drive it negative)" \
  src/php/Rent/Core/CriteriaEngine.php \
  's%(int) round(max(0, min(\$total, \$earned)) \* 100 / \$total)%(int) round(\$earned * 100 / \$total)%'

run_sabotage "a listing with no location evidence stops being rejected" \
  src/php/Rent/Core/CriteriaEngine.php \
  's%if (!\$listing->hasLocationEvidence()) {%if (false) {%'

run_sabotage "the high-floor penalty starts firing on an UNMENTIONED lift" \
  src/php/Rent/Core/CriteriaEngine.php \
  's%\$listing->hasElevator === false \&\& \$listing->floor !== null%\$listing->hasElevator !== true \&\& \$listing->floor !== null%'

run_sabotage "an unknown config key becomes silently ignored" \
  src/php/Config/Reader.php \
  's%if (\$this->remaining === \[\]) {%if (true) {%'

run_sabotage "mixed_tenure stops being required in a source block" \
  src/php/Rent/Config/ConfigLoader.php \
  "s%if (!\\\$r->has('mixed_tenure')) {%if (false) {%"

run_sabotage "an excluded default_tenure becomes acceptable in config" \
  src/php/Rent/Config/ConfigLoader.php \
  's%if (\$defaultTenure->isExcluded()) {%if (false) {%'

run_sabotage "an enabled source may carry an unverified REMPLACER url" \
  src/php/Rent/Config/ConfigLoader.php \
  's%if (str_contains(\$url, self::UNVERIFIED_URL)) {%if (false) {%'

run_sabotage "any underscore-prefixed key becomes a comment again" \
  src/php/Config/Reader.php \
  "s%if (str_starts_with(\\\$name, '_') \&\& array_key_exists(substr(\\\$name, 1), \\\$data)) {%if (str_starts_with(\\\$name, '_')) {%"

run_sabotage "a missing items_path yields an empty list instead of throwing" \
  src/php/Rent/Adapters/FixtureSource.php \
  's%if (\$items === null) {%if (false) { \$items = [];%'

run_sabotage "an item with no stable id gets skipped instead of failing the run" \
  src/php/Rent/Adapters/ListingMapper.php \
  "s%if (\\\$ref === null || \\\$ref === '') {%if (false) {%"

run_sabotage "the thousands-separator rule is dropped (1.450 becomes 1)" \
  src/php/Rent/Adapters/Payload.php \
  's%if (\$trailing === 3) {%if (false) {%'

# Restores the pre-2026-08-19 parser: strip every non-digit, then read what is left as ONE number.
# It reads as a tidy simplification and it silently FUSES two quantities into one — `55,32 m2`
# became 55322 m², `3 pièces · 55.32 m²` became 355.32, `Réf 12 — 1 450 €` became 121450. Every
# fused value is plausible as a rent, a surface or a room count, so nothing downstream can reject
# it and the criteria engine simply scores an invented number. Found against a real In'li capture.
#
# NOTE the anchor: the token regex itself is unmatchable from a BRE without a pile of escapes (`\d`
# has no BRE meaning and `[` opens a bracket expression), and an over-escaped pattern silently
# matches nothing — which is a sabotage that reports `ok` while testing NOTHING. Both first attempts
# here did exactly that and were caught by the harness's own `cmp -s` no-op guard. Anchor on the
# plain assignment underneath instead; it says the same thing and cannot rot into a no-op.
run_sabotage "a unit's own digit fuses into the number again (55,32 m2 -> 55322)" \
  src/php/Rent/Adapters/Payload.php \
  's%\$raw = \$token\[0\];%\$raw = preg_replace("~[^0-9,.-]~u", "", \$raw) ?? "";%'

# The other half: the token is found, but the LAST one wins instead of the first. Reads as an
# equally arbitrary choice and is worse — `3 pièces · 55.32 m²` yields 55 rooms, and a single-number
# string (every rent this project has ever parsed) is unaffected, so the change looks harmless in
# every test that does not deliberately put two quantities in one string.
run_sabotage "the LAST numeric token wins instead of the first" \
  src/php/Rent/Adapters/Payload.php \
  's%\$raw = \$token\[0\];%preg_match_all("~-?[0-9][0-9.,]*[0-9]|-?[0-9]~u", \$raw, \$all); \$raw = end(\$all[0]);%'

run_sabotage "an unrecognised boolean spelling becomes false instead of null" \
  src/php/Rent/Adapters/Payload.php \
  's%default => null,%default => false,%'

run_sabotage "zero and false start counting as absent values" \
  src/php/Rent/Adapters/Payload.php \
  's%if (\$value === null) {%if (!\$value) {%'

run_sabotage "an accented exclude pattern stops being refused" \
  src/php/Rent/Config/ConfigLoader.php \
  's%x80-%xFE-%'

run_sabotage "SourceError stops masking credentials before they are persisted" \
  src/php/Adapters/SourceError.php \
  's%Redact::text(\$message)%\$message%'

run_sabotage "a fixture path may escape the repo" \
  src/php/Rent/Adapters/FixtureSource.php \
  "s%if (str_contains(\\\$relative, '..')) {%if (false) {%"

# ── Dedup and the notification layer, added 2026-08-07 ────────────────────────────
# Over-merge HIDES a flat and under-merge only notifies twice, so the dedup sabotages attack the
# first direction. A notification that is computed and not delivered is worse than none at all
# (hard rule 2), which is what the notify sabotages attack.

run_sabotage "dedup merges on ONE corroborating fact (every T4 in a commune collapses)" \
  src/php/Rent/Core/Dedup.php \
  's%MIN_CORROBORATING_FACTS = 2%MIN_CORROBORATING_FACTS = 1%'

run_sabotage "dedup treats two UNKNOWN communes as the same commune" \
  src/php/Rent/Core/Dedup.php \
  's%if (\$communeA === null || \$communeB === null || \$communeA === .. || \$communeA !== \$communeB) {%if (false) {%'

run_sabotage "dedup stops respecting the track boundary (In'li merges with SeLoger)" \
  src/php/Rent/Core/Dedup.php \
  's%if (\$familyA !== \$familyB) {%if (false) {%'

run_sabotage "dedup ignores a stated rent disagreement" \
  src/php/Rent/Core/Dedup.php \
  's%RENT_TOLERANCE_RATIO = 0.03%RENT_TOLERANCE_RATIO = 9.99%'

run_sabotage "dedup ignores a stated surface disagreement" \
  src/php/Rent/Core/Dedup.php \
  's%SURFACE_TOLERANCE_RATIO = 0.03%SURFACE_TOLERANCE_RATIO = 9.99%'

run_sabotage "dedup starts fuzzy-matching two listings from the SAME source" \
  src/php/Rent/Core/Dedup.php \
  's%if (\$a->sourceName === \$b->sourceName) {%if (false) {%'

run_sabotage "a broken channel stops being reported (a send failure goes silent)" \
  src/php/Core/Notify/Notifier.php \
  's%\$failures\[\] = \$e;%%'

run_sabotage "delivery is reported as successful when no channel is usable" \
  src/php/Core/Notify/Notifier.php \
  's%^        return false;$%        return true;%'

run_sabotage "the process starts with no usable channel at all" \
  src/php/Core/Notify/Notifier.php \
  's%if (\$this->usable !== \[\]) {%if (true) {%'

run_sabotage "console alone starts counting as a remote channel" \
  src/php/Core/Notify/Notifier.php \
  's%^        return \$this->counting !== \[\];$%        return true;%'

run_sabotage "a channel throwing an unexpected error escapes and aborts the run" \
  src/php/Core/Notify/Notifier.php \
  "s%Throwable %DomainException %"

run_sabotage "a rent drop crossing the ceiling loses its new-match announcement" \
  src/php/Rent/Notify/Formatter.php \
  "s%(\\\$nowQualifies ? 'PASSE SOUS LE PLAFOND' : 'Baisse de loyer')%'Baisse de loyer'%"

run_sabotage "an hors-charges rent is shown as though it were charges comprises" \
  src/php/Rent/Notify/Formatter.php \
  "s% € HC% € CC%"

run_sabotage "the literal-secret mask is dropped (a self-hosted ntfy topic leaks)" \
  src/php/Core/Redact.php \
  's%\$message = str_replace(\$literal, self::MASK, \$message);%%'

run_sabotage "a known duplicate is silently dropped instead of shown" \
  src/php/Rent/Notify/Formatter.php \
  "s%if (\\\$duplicates !== \[\]) {%if (false) {%"

# ── The run loop, the CLI and schema v3, added 2026-08-07 ─────────────────────────
# The CLI is the only surface the developer sees, so a defect here is a defect in the product's
# entire output. Every case below was written against a specific ruling.

# The Q36 empty-database guard USED to be tested here, against `Store::wasCreated()`. That guard was
# replaced on 2026-08-19 by `Store::isSeenSetEmpty()` — the old one asked whether `open()` had created
# the file, which any earlier command that merely opened the database answered away. The case is not
# re-pointed here because its replacement already exists, written against the current code and against
# both halves of the ruling: see "the empty-database guard stops firing", "--seed no longer gets past
# the empty-database guard" and "the seen-set emptiness answer comes from the process, not the rows"
# in the Q36 block below. Two cases for one guarantee is not twice the coverage; it is one case that
# nobody notices has gone stale. This one HAD gone stale and reported `sabotage was a no-op` in the
# 2026-08-19 full ledger — which is the harness working, and the reason it is removed rather than left.

run_sabotage "--seed stops marking listings notified (the flood moves one run later)" \
  src/php/Rent/Cli/Pipeline.php \
  's%^                \$this->store->markNotified(\$sighting->dedupKey, \$nowIso, .MATCH.);$%%'
# NOT A CASE: the first half of this expression used to rewrite the COMMENT above that line
# (`MARKED NOTIFIED WITHOUT SENDING` -> `DISABLED`). A comment cannot redden a suite, and unlike the
# genuinely paired compounds in this ledger it did not enable its sibling either — the sibling is an
# anchored match on the call itself. Measured on 2026-09-07 by running all 26 sub-expressions of the
# 15 compound cases ALONE: it was the only one of the eleven undetected halves that was inert rather
# than shadowed. Removed, because an expression that cannot fail reports work it does not do.

run_sabotage "the digest re-emits everything on every pass (Q34)" \
  src/php/Rent/Cli/Pipeline.php \
  's%if (!\$this->store->wasNotified(\$sighting->dedupKey)) {%if (true) {%'

run_sabotage "a match is marked notified even when no channel confirmed" \
  src/php/Rent/Cli/Pipeline.php \
  's%if (\$this->notifier->delivered(\$failures)) {%if (true) {%'

# --- schema v8: a promotion must survive the digest that preceded it ------------------------------

run_sabotage "the match gate forgets WHAT the listing was announced as (a promotion is swallowed)" \
  src/php/Rent/Cli/Pipeline.php \
  's%wasNotifiedAs(\$sighting->dedupKey, .MATCH.)%wasNotified(\$sighting->dedupKey)%'

run_sabotage "an announcement may be DOWNGRADED (a match reopens for re-announcement)" \
  src/php/Rent/Store/Store.php \
  's%WHEN notified_at IS NOT NULL AND COALESCE%WHEN 0 = 1 AND COALESCE%'

run_sabotage "a pre-v8 announcement reads as a DOUBT (the historic backlog re-announces as matches)" \
  src/php/Rent/Store/Store.php \
  "s%\\\$row\\['notified_as'\\] === null ? 'MATCH' :%\\\$row['notified_as'] === null ? 'DIGEST' :%"

# --- §1 across a cross-portal cluster --------------------------------------------------------------

# The durable veto reads the group, so the group must EXIST before anything is judged. This pins
# the ordering rather than the scan: `assignGroup()` runs in the recording loop, and moving or
# dropping it leaves `groupExcludedTenure()` with nothing to find on the very pass that formed the
# cluster. Replaced the old in-pass-scan case, which became dead when that scan was removed.
run_sabotage "the cluster is never recorded as a group (the durable veto has nothing to read)" \
  src/php/Rent/Cli/Pipeline.php \
  's%\$this->store->assignGroup(\$memberKeys);%%'

# --- every announcement path is bounded AND says what it left behind ------------------------------

run_sabotage "promotions stop being capped (a resolved backlog empties onto the phone at once)" \
  src/php/Rent/Cli/RentScout.php \
  's%\$promotions = \\array_slice(\$promotions, 0, Store::DIGEST_BATCH);%%'

run_sabotage "a capped pipeline digest stops naming its remainder (the rest is invisible)" \
  src/php/Rent/Cli/Pipeline.php \
  's%\$digestOverflow = max%\$digestOverflow = 0; \$dead = max%'

# --- the beat's three contributors, each pinned on its own ----------------------------------------

run_sabotage "a delivered MATCH stops counting as an announcement" \
  src/php/Rent/Cli/Pipeline.php \
  's%^                ++\$notified;$%%'

run_sabotage "a delivered digest entry stops counting as an announcement" \
  src/php/Rent/Cli/Pipeline.php \
  's%\$notified += \\count(\$batch);%%'

# NOT A CASE: the over-fire direction at the PIPELINE layer. It was written against a caller-side
# re-check of `Store::groupExcludedTenure()`'s result, and the ledger showed it undetected — because
# that method returns nothing but an excluded tenure, so no input can reach the branch. The re-check
# was removed and the method now returns `?Tenure`, putting the contract in the type. The over-fire
# guarantee lives one layer down, at "the group veto reads eligible tenures as excluded", which is
# real and detected. Recorded so nobody re-adds a case here.

# --- Q27: the beat's own figures ------------------------------------------------------------------

run_sabotage "the beat's notified count goes back to a hard-coded 0 (constant at any traffic level)" \
  src/php/Rent/Cli/RentScout.php \
  's%\$this->beat(\$notifier, \$store, \$passes, \$notified, \$this->pendingRefusal(), \$watched, \$failedPasses);%\$this->beat(\$notifier, \$store, \$passes, 0, \$this->pendingRefusal(), \$watched, \$failedPasses);%'

run_sabotage "the pass result is discarded again (nothing feeds the beat's count)" \
  src/php/Rent/Cli/RentScout.php \
  's%\$notified += \$pushed ?? 0;%%'

# --- the digest backlog must DRAIN, not harden -----------------------------------------------------

run_sabotage "the digest batch loses its cap (one all-or-nothing send that grows on every failure)" \
  src/php/Rent/Store/Store.php \
  "s%bindValue(':limit', max(1, \\\$limit)%bindValue(':limit', max(1, 100000)%"

run_sabotage "a capped digest stops naming the remainder (the bin reads as empty)" \
  src/php/Rent/Cli/RentScout.php \
  's%if ($remaining > 0) {%if (false) {%'

# --- the cluster veto must survive every path -----------------------------------------------------

# THE FOURTH PERSISTED ROUTE ON THE THIRD ANNOUNCING SURFACE (C2 milestone panel, P0 — all three
# lenses reproduced it independently). `reclassify` FORMS a verdict, promotes DIGEST -> MATCH and
# PUSHES it, and it read three of the four persisted §1 readings: the dwelling route — the same flat
# on record under another ad id — was the one it could not see, and by construction that row has no
# group edge and no twin. Terminal once it fires: the pushed row holds a resolved tenure and
# outcome = MATCH, so nothing returns it again.
#
# COMPOUND SINCE 2026-09-09, and the reason is structural rather than a fixture that could be
# sharpened. Measured, whole suite: this expression ALONE is green (3126 tests, 12109 assertions) —
# `SectionOneGate` re-reads all four routes at the caller's filter and again above the send, so the
# only thing the mutation can change is whether a promotion is OFFERED. And a promotion WRITES
# NOTHING until it is announced — `$changed` deliberately excludes them, in as many words — so
# `testAFlatOnRecordAsExcludedUnderAnotherAdIdIsNotPromoted` already asserts this row's outcome and
# that assertion is satisfied either way. There is nothing observable left for a sharper fixture to
# assert; removing the two gates beside it is what makes the per-row check answerable at all. The
# label's "it pushes" is then literal — that test fails on a MATCH notification actually delivered,
# with `testReopenNamesTheSameDwellingRouteAndDoesNotClearIt` beside it.
#
# NOT INDEPENDENT of the case below it, and saying so is cheaper than someone rediscovering it: this
# script is `D;F;G` and that one is `F;G`, so the two tests named above are the DELTA over the pair,
# and the case below would keep this one red on its own. Structural rather than sloppy — the filter
# reads the dwelling route, so measuring the route means removing the filter — and the Pipeline trio
# already has the same property through the empty candidate set the three of them share. Measured:
# 3 changed lines here, 2 in the case below, 1 in the group case, so each part reaches ONE guard.
run_sabotage "reclassify ignores the same dwelling on record under another ad id (§1, and it pushes)" \
  src/php/Rent/Cli/RentScout.php \
  '/private function reclassify(/,$ s%\$dwellingVeto = ExcludedDwellings::match(\$evidence, \$excludedDwellings, \$dwellingDedup);%\$dwellingVeto = null;%; /private function reclassify(/,$ s%if (\$sectionOne->refuses(\$promotion\[.listing.\], \$promotion\[.key.\]) === null) {%if (true) {%; /private function announcePromotions/,/^    }/ s%if (\$refusal !== null) {%if (false) {%'

# The counterweight half: --reopen must still NAME the route it does not clear. Without this the
# repair verb silently declines on exactly the population the veto above is for.
run_sabotage "--reopen stops reporting the same-dwelling route (the repair verb goes silent)" \
  src/php/Rent/Store/Store.php \
  's%: ExcludedDwellings::match(\$evidence, \$this->excludedDwellings(), new Dedup()),%: null,%'

# THE CAR DAILY FLOOR kept the round-7 guard while its value took the round-8 fix, so any refused
# retry silenced the backlog clause under a summary reading `N véhicule(s) émis` (C2 milestone
# panel, P1 on all three lenses). The verb's own case is above; this is the deployed surface.
run_sabotage "the car FLOOR reports a refused retry as drained (the deployed rollup goes quiet)" \
  src/php/Car/Cli/CarScout.php \
  '/private function floorRollup/,/^    }/ s%\$this->remainingAfterDrain(\$waiting, \$entries, \$drained, true) > 0%false%'

# A REFUSED DIGEST MAIL DRAINED NOTHING, and `digest` reported the whole batch as gone: the
# remainder was printed BEFORE the send and subtracted every announced and rolled-up row
# unconditionally (C2 milestone panel, P2). The rent floor was already right; only the verb was not.
run_sabotage "the rent digest remainder counts a refused mail as delivered" \
  src/php/Rent/Cli/DigestBatch.php \
  's%\$announced = \$batchAccountedFor ? \\count(\$this->entries) : 0;%$announced = \\count($this->entries);%'

# THE SEAM: the real verdict feeding the real alert loop. Every other health case proves one side —
# `RunStoreFailureStreakTest` the verdict, the Pipeline health tests the loop with an INJECTED
# SourceHealth — and the live flap lived exactly between them (C2 milestone panel, P2).
run_sabotage "one failed run is a broken source again (the flap, through the real verdict)" \
  src/php/Core/RunStore.php \
  's%\$observed = self::observedRuns(\$runs);%$observed = $runs;%'

# THE EXCLUSION THIS RUN RESOLVES MUST VETO THE SIBLING THIS RUN PROMOTES (C2 milestone panel round
# 2, P0 — two lenses, each with an executed push). Round 1 hoisted `excludedDwellings()` above the
# loop; `reclassify` WRITES tenure inside that loop, so an exclusion it resolves itself never enters
# the set. `staleVerdicts()` orders `seen_epoch DESC`, so the NEW ad is judged FIRST in the natural
# re-advertising case — which is why the veto is re-applied to the PROMOTION, the last moment at
# which every verdict of the run is on disk.
#
# COMPOUND SINCE 2026-09-09: this filter and the gate inside `announcePromotions()` are the SAME
# refusal at two heights, so removing one leaves the other standing and the suite green — measured,
# whole suite, 3126 tests / 12110 assertions with only the first expression applied. Together they
# redden `testAClusterSiblingThatResolvesLaterInTheRunStillVetoesThePromotion` and
# `testAnExclusionResolvedInThisRunVetoesItsOwnSibling`.
#
# THE SEND GATE HAS NO CASE OF ITS OWN AND NEEDS NONE, which is worth writing down because its
# absence reads like a gap. Disabling it alone is green too, and cannot be otherwise: the filter
# above removes every refused promotion before `announcePromotions()` is reached, so its `if` is
# unreachable while the filter stands. `SectionOneGateCallSitesTest` pins its PRESENCE — the second
# expression here keeps `->refuses(` precisely so that guard stays green and the reddening is
# behavioural — and this pair is what pins its EFFECT.
run_sabotage "reclassify promotes a sibling of an exclusion it resolved seconds earlier (§1)" \
  src/php/Rent/Cli/RentScout.php \
  '/private function reclassify(/,$ s%if (\$sectionOne->refuses(\$promotion\[.listing.\], \$promotion\[.key.\]) === null) {%if (true) {%; /private function announcePromotions/,/^    }/ s%if (\$refusal !== null) {%if (false) {%'

# THE CAR VERB against a refused ROLLUP — round 1 fixed the RENT verb and wrote "only the verb was
# wrong", which was true of rent and left this standing (C2 round 2, P1 on all three lenses).
run_sabotage "the car rollup VERB counts a refused mail as drained" \
  src/php/Car/Cli/CarScout.php \
  's%return \$waiting - (\$announced ? count(\$entries) : 0) - \$drained;%return $waiting - count($entries) - $drained;%'

# A DRY RUN LISTS THE BATCH, so the batch is accounted for. Round 1 passed `false` here — true by
# the parameter's old name and wrong: a one-row bin printed the row and then called it an *other*.
run_sabotage "digest --dry-run reports the batch it just listed as a backlog beyond itself" \
  src/php/Rent/Cli/RentScout.php \
  's%\$this->reportRemainder(\$batch, \$batch->retryKeyCount(), true, \$lowScore);%$this->reportRemainder($batch, 0, false, $lowScore);%'

# `--reopen` IS THE DOCUMENTED ONE WAY BACK, and round 1 made it the only unguarded `evidence()`
# caller in the tree — above the loop, so a damaged snapshot took the whole command down.
run_sabotage "--reopen throws on a corrupt snapshot instead of skipping the dwelling route" \
  src/php/Rent/Store/Store.php \
  '/public function reopen/,/^    }/ s%} catch (\\JsonException | \\InvalidArgumentException) {%} catch (\\LogicException) {%'

# THE PRECEDENT `reopen()` CITES, and it had ZERO coverage of its own (C2 round 3, P2 on two
# lenses): mutating it alone left all 3000 tests green. One corrupt `evidence_json` on an excluded
# row would then throw out of `excludedDwellings()`, which `Pipeline`, the drain, `reclassify` and
# `reopen` all call — aborting every pass for as long as the row exists. The case that looked like
# it covered this was the `reopen` one, whose unscoped sed hit BOTH blocks while its detection came
# from reopen's alone.
run_sabotage "excludedDwellings() throws on a corrupt snapshot instead of skipping the row" \
  src/php/Rent/Store/Store.php \
  '/public function excludedDwellings/,/^    }/ s%} catch (\\JsonException | \\InvalidArgumentException) {%} catch (\\LogicException) {%'

# THE §1 GATE — one implementation, four routes, read FRESH at every send (2026-09-07). It exists
# because three certification rounds each found a route checked on one announcing surface and not
# another, or checked when the state it read was already stale. One case per route, plus the wiring.
run_sabotage "the §1 gate stops reading the row's own durable exclusion" \
  src/php/Rent/Cli/SectionOneGate.php \
  's%\$own = \$this->store->tenure(\$dedupKey);%$own = null;%'

run_sabotage "the §1 gate stops reading the cluster veto" \
  src/php/Rent/Cli/SectionOneGate.php \
  's%\$group = \$this->store->groupExcludedTenure(\$dedupKey);%$group = null;%'

run_sabotage "the §1 gate stops reading the cross-track twin" \
  src/php/Rent/Cli/SectionOneGate.php \
  's%\$twin = \$this->store->twinTenure(\$dedupKey);%$twin = null;%'

run_sabotage "the §1 gate stops reading the same dwelling under another ad id" \
  src/php/Rent/Cli/SectionOneGate.php \
  's%\$dwelling = ExcludedDwellings::match(\$listing, \$this->store->excludedDwellings(), \$this->dedup);%$dwelling = null;%'

# THE WIRING, per announcing surface. A gate nothing calls is the dead safety code this milestone
# has already produced twice.
run_sabotage "the live push path stops consulting the §1 gate" \
  src/php/Rent/Cli/Pipeline.php \
  's%\$refusal = \$sectionOne->refuses(\$listing, \$sighting->dedupKey);%$refusal = null;%'

# RESTORED (C2 round 4). Round 3 made this a documented NON-case on the reasoning that
# `collectDigest()` refuses such a row upstream through all four routes, so nothing reaching
# `pushRetries()` could be refused here — and a test written for it stayed green with the guard
# bypassed, which was read as proof. **Both halves were wrong**, and a lens showed it two ways:
#
#   - `digest` calls `collectDigest()`, then builds the notifier and runs its checks, and only then
#     `pushRetries()`. A concurrent `run --watch` in a SEPARATE PROCESS writes `recordTwin()` in that
#     window; the store is WAL with a documented concurrent-writer contract. Demonstrated with two
#     `Store::open()` handles: all four routes NULL at collect, `jumeau / PLS` at push.
#   - The dwelling route sits BELOW the snapshot-less `continue`, so a row whose payload will not
#     encode enters `$retries` never having been dwelling-checked at all.
#
# The single-process seed could not produce the interleave. **That was the test failing to reach the
# branch, not the branch being unreachable** — and turning that into a non-case removed the one
# thing that would have said so.
run_sabotage "the retry push stops consulting the §1 gate" \
  src/php/Rent/Cli/RentScout.php \
  's%\$refusal = \$sectionOne->refuses(\$entry\[.listing.\], \$entry\[.key.\]);%$refusal = null;%'

# THE GATE READS FRESH — hoisting its state is what both round-3 P0s were, and a cached candidate
# list would pass every route test above while re-opening the defect the gate exists to close.
# READING FRESH IS THE GUARANTEE, not a detail: a hoisted set is precisely what both round-3 P0s
# were, and a cached one passes every per-route case above while re-opening the hole. `static` here
# memoises across calls within the process, which is what "cached" means.
run_sabotage "the §1 gate caches the excluded-dwelling set instead of reading it fresh" \
  src/php/Rent/Cli/SectionOneGate.php \
  's%\$dwelling = ExcludedDwellings::match(\$listing, \$this->store->excludedDwellings(), \$this->dedup);%static $cached = null; $cached ??= $this->store->excludedDwellings(); $dwelling = ExcludedDwellings::match($listing, $cached, $this->dedup);%'

# THE RENT-DROP SEND — the round-4 P0. Two lenses executed a HIGH-priority PLS push from a send 98
# lines ABOVE the gate. An announcing surface is a SEND, not a notification KIND.
run_sabotage "the rent-drop push escapes the §1 gate (a PLS flat pushed as PASSE SOUS LE PLAFOND)" \
  src/php/Rent/Cli/Pipeline.php \
  's%\$refusal = \$sectionOne->refuses(\$listing, \$sighting->dedupKey);%$refusal = $sighting->isPriceDrop ? null : $sectionOne->refuses($listing, $sighting->dedupKey);%'

# THE SILENCE. `$sectionOneRefused` was incremented and read nowhere, on the one announcing surface
# that runs unattended — while the refusal is terminal by query and nothing printed the key that
# reverses it.
run_sabotage "a §1 refusal on the live pass is counted and never voiced" \
  src/php/Rent/Cli/Pipeline.php \
  's%\$warnings = \[...\$warnings, ...\$sectionOneRefused\];%$warnings = [...$warnings];%'

# THE ROLLUP HALF of both digest drains: those are MATCHES held back by the score gate, and they
# reached the wire on collect-time reads alone.
run_sabotage "the digest verb announces a rolled-up match the §1 gate refuses" \
  src/php/Rent/Cli/RentScout.php \
  '/private function digest(/,/private function pushRetries/ s%\$refusal = \$sectionOne->refuses(\$entry\[.listing.\], \$entry\[.key.\]);%$refusal = null;%'

run_sabotage "the DAILY FLOOR announces a rolled-up match the §1 gate refuses (the deployed drain)" \
  src/php/Rent/Cli/RentScout.php \
  '/private function floorDigest/,/^    }/ s%\$refusal = \$sectionOne->refuses(\$entry\[.listing.\], \$entry\[.key.\]);%$refusal = null;%'

run_sabotage "announcePromotions sends without re-reading §1 in the sending method" \
  src/php/Rent/Cli/RentScout.php \
  '/private function announcePromotions/,/^    }/ s%\$refusal = \$sectionOne->refuses(\$promotion\[.listing.\], \$promotion\[.key.\]);%$refusal = null;%'

# THE DIGEST BIN IS AN ANNOUNCEMENT TOO — the round-5 P0, and the last surface §1 was not judged on.
# §1 was built as "never a MATCH", so `DIGEST` was treated as a DESTINATION rather than as something
# that reaches the phone. The bin `continue`s 42 lines ABOVE the match gate, so an excluded dwelling
# went out under a headline asserting *« au régime indéterminé »* while the store held `PLS` for the
# same dwelling one row away, written by that same pass. `$digestRefusal` is a DIFFERENT variable
# from the match gate's `$refusal` deliberately: this route drops the row from the bin and says so,
# and does NOT write a derived `REJECT` — a doubt the pipeline could not resolve is not a regime it
# read. So the two cannot share one expression, and the match gate's case above never covered this.
run_sabotage "the digest bin escapes the §1 gate (an excluded dwelling announced as a tenure doubt)" \
  src/php/Rent/Cli/Pipeline.php \
  's%\$digestRefusal = \$sectionOne->refuses(\$listing, \$sighting->dedupKey);%$digestRefusal = null;%'

# THE REMEDY NAMED MUST WORK FOR THE ROUTE THAT REFUSED. `--reopen` clears the row's OWN reading and
# its TWIN's; the GROUP and SAME-DWELLING vetoes live on ANOTHER row's reading and are deliberately
# not cleared. Promising it unconditionally is a closed loop dressed as a way out — re-refused and
# rewritten every pass, under an instruction saying there is an exit.
run_sabotage "a §1 refusal promises --reopen for a veto --reopen cannot clear" \
  src/php/Rent/Cli/Pipeline.php \
  's%\$reversible = \\in_array(\$refusal\[.route.\], \[.lecture propre., .jumeau.\], true);%$reversible = true;%'

# THE REMAINDER LINE COUNTS WHAT WAS ANNOUNCED, NEVER WHAT WAS QUEUED. A row the §1 gate removed
# from the mail was still counted as drained, so the line went silent while that row sat in
# `pendingLowScore()` for ever — this method's own documented guarantee, read backwards.
run_sabotage "overflow() counts the queued rollup instead of the announced one (a gated row goes silent)" \
  src/php/Rent/Cli/DigestBatch.php \
  's%foreach (\$lowScoreAnnounced as \$entry) {%foreach ($this->lowScore as $entry) {%'

# THE GUARD THAT PREVENTS RECURRENCE, SABOTAGED AGAINST ITSELF — and the only case in this ledger
# that targets a test file, because this test IS a guarantee rather than a check of one. Its
# discovery matched a method's BODY while its gate check read the whole `$code` (signature and
# docblock included), so a gate COMMENTED OUT satisfied it: the announcing-surface guard could be
# switched off one `//` at a time. Nothing but its own counterweight would notice.
run_sabotage "the call-site guard accepts a COMMENTED-OUT §1 gate" \
  tests/php/Repo/SectionOneGateCallSitesTest.php \
  's%\$out\[\] = \[basename(\$file, ..php.), \$name, \$code\];%$out[] = [basename($file, ".php"), $name, $body];%'

# SCOPED to reclassify(), for the reason on the drain's own pair above.
#
# DELIBERATELY NOT COMPOUND, and it was one measurement away from becoming one. The two reclassify
# cases above are shadowed by `SectionOneGate`; this one was shadowed by a FIXTURE.
# `testAListingItsClusterVetoedIsNotResurrected` seeds a sibling that is a cluster member AND the
# same dwelling under another ad id at once, so `excludedDwellings()` refuses the survivor whatever
# the group veto does, and the shared `$vetoed` counter leaves even the `écartée` line
# byte-identical — measured: group + dwelling nulled together is what reddens that test, with both
# gates INTACT. Compounding on that reading would have pinned this expression to the dwelling
# route's guarantee: a second label on one measurement, which is what the note above the Pipeline
# trio warns against when it says the reason is written down rather than replaced.
#
# What separates them is that a DEMOTED verdict is written where a promotion is not, so route 2 is
# observable on its own once route 4 cannot answer.
# `RentScoutReclassifyTest::testTheClusterVetoHoldsWhenTheDwellingScanCanNoLongerMatch` puts the
# store in that state — the state `PipelineRunTest::testThePersistedGroupVetoHoldsWhenTheDwelling
# ScanCanNoLongerMatch` already uses one surface over: the cluster earned while the rents agree,
# then one copy re-advertised 300 € away, outside `Dedup`'s tolerance. Measured 2026-09-09: green
# at HEAD, RED on this single expression with both gates intact, and green when the DWELLING route
# is nulled instead — the counterweight that proves it isolates route 2 and nothing else.
run_sabotage "reclassify stops consulting the group (it resurrects a listing the cluster vetoed)" \
  src/php/Rent/Cli/RentScout.php \
  '/private function reclassify(/,$ s%\$groupVeto = \$store->groupExcludedTenure(\$key);%\$groupVeto = null;%'

run_sabotage "the group veto reads eligible tenures as excluded (every clustered row is skipped)" \
  src/php/Rent/Store/Store.php \
  's%if (\$tenure !== null && \$tenure->isExcluded()) {%if (\$tenure !== null) {%'

# THESE THREE ARE COMPOUND, AND THE SECOND EXPRESSION IS WHY (C2 round 7 follow-up). §1 is defended
# in DEPTH — the persisted group veto, the twin reading resolved across a component, the durable own
# reading and `excludedDwellings()` all refuse the same flat — so nulling any ONE of them leaves the
# suite green and the nightly ledger reported all three as *undetected*. That was true, and it did
# not mean the guarantee was gone: measured at HEAD, each of the three goes RED the moment
# `excludedDwellings()` — the fourth route, added last and the broadest — is disabled beside it.
# The same shape as the shared decode cascade's percent passes: a layer whose isolation no longer
# means anything is pinned WITH the layer that shadows it, and the reason is written down rather
# than replaced by a scenario invented to make one mutation observable.
# `PipelineRunTest::testTheTwinVetoTravelsTheWholeChainNotJustTheDirectLink` and
# `…testThePersistedGroupVetoHoldsWhenTheDwellingScanCanNoLongerMatch` are the tests that go red.
run_sabotage "the pipeline veto reads only THIS pass's harvest (a missing sibling launders the flat)" \
  src/php/Rent/Cli/Pipeline.php \
  's%\$this->store->groupExcludedTenure(\$sighting->dedupKey),%null,%; s%\$excludedDwellings = \$this->store->excludedDwellings();%\$excludedDwellings = [];%'

# NOT A CASE: `confidenceBp` on the durable veto's synthetic Classification. `CriteriaEngine`
# branches on `$classification->outcome` alone, never on tenure or confidence, so mutating that
# number changes no verdict — which the ledger proved by leaving the suite green. It was written
# against a docblock claiming the value "must clear the fail-closed floor on its own"; the claim was
# invented and both it and the case are gone. Recorded here so nobody adds it back.

# --- the beat reports DELIVERIES, and every announcement path is bounded ---------------------------

run_sabotage "the beat counts verdicts again instead of deliveries (steady state reads as busy)" \
  src/php/Rent/Cli/RentScout.php \
  's%\$matchesOut = \$result->notified;%\$matchesOut = \$result->matches;%'

run_sabotage "the pipeline digest loses its cap (the unattended path grows on every failure)" \
  src/php/Rent/Cli/Pipeline.php \
  's%\$batch = \\array_slice(\$digestEntries, 0, Store::DIGEST_BATCH);%\$batch = \$digestEntries;%'

run_sabotage "a failed fetch stops being recorded as a failed run" \
  src/php/Rent/Cli/Pipeline.php \
  's%\$this->store->recordRun(\$source->name(), 0, false, \$e->getMessage(), \$nowIso, \$durationMs);%%'

run_sabotage "an adapter exception aborts the whole pass instead of one source" \
  src/php/Rent/Cli/Pipeline.php \
  's%catch (SourceError %catch (\\UnexpectedValueException %'

run_sabotage "item_count starts counting MATCHES instead of parsed items (Q30)" \
  src/php/Rent/Cli/Pipeline.php \
  's%\$this->store->recordRun(\$source->name(), count(\$listings), true%\$this->store->recordRun($source->name(), 0, true%'

run_sabotage "health alerting narrows back to BROKEN alone (Q29)" \
  src/php/Rent/Cli/Pipeline.php \
  's%!\$health->status->isAlerting()%\$health->status->value !== "broken"%'

run_sabotage "the alert cooldown is ignored (a broken source pushes every run)" \
  src/php/Core/RunStore.php \
  's%return (\$now - \$last) >= \$cooldownHours \* 3600;%return true;%'

run_sabotage "the cooldown keys on the source alone, so an escalation is swallowed" \
  src/php/Core/RunStore.php \
  "s%WHERE source = :source AND status = :status'%WHERE source = :source'%"

run_sabotage "a source that recovers sends no recovery notice" \
  src/php/Rent/Cli/Pipeline.php \
  's%if (\$this->store->clearAlerts(\$source->name())) {%if (false) {%'

run_sabotage "the tenure verdict stops being persisted (Q24)" \
  src/php/Rent/Cli/Pipeline.php \
  's%\$this->store->recordVerdict(%$this->store->schemaVersion(); $unused = array(%'

run_sabotage "run duration stops being measured (Q25: doctor's fourth column)" \
  src/php/Rent/Cli/RentScout.php \
  's%\$durationMs = (int) round((hrtime(true) - \$startedAt) / 1_000_000);%$durationMs = null;%'

run_sabotage "doctor stops printing the journal mode (a silent WAL refusal)" \
  src/php/Rent/Cli/RentScout.php \
  "s%. ', journal ' . \\\$store->journalMode() . ')')%. ')')%"

# RETIRED, with the reason recorded rather than the case quietly deleted: "doctor stops passing the
# clock to health()" could never go red, and a sabotage that cannot fail reports nothing for its whole
# life. `doctor` records its own successful run IMMEDIATELY BEFORE asking for health, so the clock and
# the newest stamp always agree and no verdict can differ — the argument is defensive there, not
# load-bearing. Where it IS load-bearing is any health read not preceded by a run, and StoreTest
# covers that directly. Do not re-add this case without first making doctor's output depend on it.

run_sabotage "the scraping opt-in gate is removed (hard rule 4 / Q26)" \
  src/php/Rent/Cli/RentScout.php \
  's%if (\$definition->requiresScrapingOptIn() \&\& !\$this->scrapingAllowed(\$argv)) {%if (false) {%'

run_sabotage "an unknown notification channel name is silently dropped" \
  src/php/Cli/ChannelFactory.php \
  's%if (\$channel === null) {%if (false) {%'

run_sabotage "the freshness bonus is given to every listing forever" \
  src/php/Rent/Cli/Pipeline.php \
  's%\$this->ageSeconds(\$sighting->dedupKey, \$nowIso)%null%'

# ── The network adapters, added 2026-08-07 ────────────────────────────────────────
# The transport is testable even though no endpoint is verified: hard rule 1 governs the URL, not
# the adapter. Every case below breaks a guarantee that is otherwise silent.

run_sabotage "robots.txt stops being consulted before a fetch (hard rule 5)" \
  src/php/Rent/Adapters/HttpJsonSource.php \
  's%if (!\$this->robots->allows(Robots::pathOf(\$url))) {%if (false) {%'

run_sabotage "an unreadable robots.txt starts allowing everything (fails OPEN)" \
  src/php/Adapters/Http/Robots.php \
  's%if (!\$this->parsed) {%if (false) {%'

run_sabotage "an empty Disallow starts meaning disallow-everything" \
  src/php/Adapters/Http/Robots.php \
  "s%if (\\\$value !== '') {%if (true) { \\\$value = \\\$value ?: '/';%"

run_sabotage "a non-2xx response becomes an empty result instead of a failure" \
  src/php/Rent/Adapters/HttpJsonSource.php \
  's%if (!\$response->isSuccess()) {%if (false) {%'

run_sabotage "a moved items_path yields an empty list instead of throwing" \
  src/php/Rent/Adapters/HttpJsonSource.php \
  's%if (\$items === null) {%if (false) { $items = [];%'

run_sabotage "the REMPLACER guard is removed from the adapter itself" \
  src/php/Rent/Adapters/HttpJsonSource.php \
  "s%if (str_contains(\\\$url, 'REMPLACER')) {%if (false) {%"

run_sabotage "the honest User-Agent is dropped for a browser disguise (hard rule 5)" \
  src/php/Adapters/Http/CurlHttpClient.php \
  "s%USER_AGENT = 'scout%USER_AGENT = 'Mozilla/5.0 rent-watch%"

run_sabotage "the honest User-Agent constant is BYPASSED at the wiring point" \
  src/php/Adapters/Http/CurlHttpClient.php \
  "s%CURLOPT_USERAGENT => self::USER_AGENT,%CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0)',%"

run_sabotage "a request header is allowed to override the honest User-Agent" \
  src/php/Adapters/Http/CurlHttpClient.php \
  "s%if (strtolower(\\\$name) === 'user-agent') {%if (false) {%"

run_sabotage "config regains the power to disguise a source's User-Agent" \
  src/php/Rent/Config/ConfigLoader.php \
  "s%if (strtolower((string) \\\$headerName) === 'user-agent') {%if (false) {%"

run_sabotage "a colon-smuggled header NAME slips past the funnel's token check" \
  src/php/Adapters/Http/CurlHttpClient.php \
  's%if (preg_match(self::HEADER_NAME_TOKEN, \$name) !== 1) {%if (false) {%'

run_sabotage "a colon-smuggled header NAME slips past config validation" \
  src/php/Rent/Config/ConfigLoader.php \
  's%if (preg_match(self::HEADER_NAME_TOKEN, (string) \$headerName) !== 1) {%if (false) {%'

run_sabotage "a line break in a header VALUE reaches the wire (header injection)" \
  src/php/Adapters/Http/CurlHttpClient.php \
  's%if (preg_match(.~\[\\r\\n\]~., (string) \$value) === 1) {%if (false) {%'

run_sabotage "a line break in a config header VALUE passes validation" \
  src/php/Rent/Config/ConfigLoader.php \
  's%if (preg_match(.~\[\\r\\n\]~., \$headerValue) === 1) {%if (false) {%'

run_sabotage "the funnel token anchor stops rejecting a trailing newline in a name" \
  src/php/Adapters/Http/CurlHttpClient.php \
  "s%+\$/D';%+\$/';%"

run_sabotage "config's token anchor stops rejecting a trailing newline in a name" \
  src/php/Rent/Config/ConfigLoader.php \
  "s%+\$/D';%+\$/';%"

run_sabotage "the ntfy Click url stops being sanitised (header injection from a listing)" \
  src/php/Core/Notify/NtfyChannel.php \
  's%.Click: . . self::headerSafe(\$n->url)%"Click: " . $n->url%'

run_sabotage "the email Subject stops being header-safed (Bcc injection from a listing)" \
  src/php/Core/Notify/EmailChannel.php \
  's%\$subject = self::headerSafe(\$this->subjectPrefix . . . . \$n->title);%$subject = $this->subjectPrefix . " " . $n->title;%'

run_sabotage "an IMAP argument stops refusing an embedded CR/LF" \
  src/php/Adapters/Mail/ImapMailbox.php \
  's%if (preg_match(.~\[\\r\\n\]~., \$value) === 1) {%if (false) {%'

run_sabotage "the ntfy Title stops being header-safed (injection from a listing title)" \
  src/php/Core/Notify/NtfyChannel.php \
  's%.Title: . . self::headerSafe(\$title)%"Title: " . $title%'

run_sabotage "the email sender check loses its D anchor (a trailing newline passes)" \
  src/php/Core/Notify/EmailChannel.php \
  "s%@\[^@\\\\s\]+\$~D%@[^@\\\\s]+\$~%"

run_sabotage "the shared transport CR/LF guard stops refusing (all mail transports)" \
  src/php/Core/Notify/Headers.php \
  's%if (preg_match(.~\[\\r\\n\]~., \$value) === 1) {%if (false) {%'

run_sabotage "SmtpTransport stops calling the shared CR/LF guard" \
  src/php/Core/Notify/SmtpTransport.php \
  's%Headers::assertNoCrlf(.recipient., \$to);%%'

run_sabotage "SendmailTransport stops calling the shared CR/LF guard" \
  src/php/Core/Notify/SendmailTransport.php \
  's%Headers::assertNoCrlf(.header . . \$name, (string) \$value);%%'

run_sabotage "FileTransport stops calling the shared CR/LF guard" \
  src/php/Core/Notify/FileTransport.php \
  's%Headers::assertNoCrlf(.header . . \$name, (string) \$value);%%'

run_sabotage "ISO-8859-1 stops being read as CP1252 (the euro sign vanishes)" \
  src/php/Adapters/Mail/EmailMessage.php \
  "s%? 'CP1252'%? 'ISO-8859-1'%"

run_sabotage "folded header continuation lines stop being joined" \
  src/php/Adapters/Mail/EmailMessage.php \
  's%\$value .= . . . trim(\$line);%%'

run_sabotage "the text/plain part stops being preferred over HTML" \
  src/php/Adapters/Mail/EmailMessage.php \
  's%return \$plain ?? \$html ?? ..;%return $html ?? $plain ?? "";%'

run_sabotage "block tags stop becoming newlines when HTML is stripped" \
  src/php/Adapters/Mail/EmailMessage.php \
  's%(p|div|br|li|tr|h%(XX|div|br|li|tr|h%'

run_sabotage "the closing </br> form drops out of the block-tag alternation" \
  src/php/Adapters/Mail/EmailMessage.php \
  's%|br|li%|li%'

run_sabotage "tracking parameters stop being stripped from an alert link id" \
  src/php/Rent/Adapters/EmailAlertSource.php \
  's%return \$scheme . .://. . \$host . \$path;%return $link;%'

run_sabotage "unsubscribe links start becoming listings" \
  src/php/Rent/Adapters/EmailAlertSource.php \
  's%if (stripos(\$link, \$noise) !== false) {%if (false) {%'

run_sabotage "a mailbox from the wrong sender stops being filtered out" \
  src/php/Rent/Adapters/EmailAlertSource.php \
  's%if (!\$this->isFrom(\$message)) {%if (false) {%'

run_sabotage "a mailbox failure becomes an empty list instead of a source failure" \
  src/php/Adapters/Mail/FileMailbox.php \
  's%if (!is_dir(\$this->directory)) {%if (false) {%'

# RETARGETED 2026-09-04, NOT REWRITTEN: the band moved from `EmailAlertSource` into
# `Payload::plausibleRent()`, and these three kept `sed`ing the vanished literal — three expressions
# reporting coverage they did not have, on exactly the guarantees the move was about. The code is
# byte-identical in its new home, so each expression still matches there and nowhere else. This is
# the SECOND time in two commits (see `081ab28`, the escalation extraction); the gate
# `tests/test-sabotage-applies.sh` is what catches it, and it must be run after any code MOVE.
# THE NUMBERS BECAME NAMED CONSTANTS in the same move, so retargeting the FILE was not enough —
# a first pass did exactly that and all three stayed inert. An expression follows the code's shape,
# not only its address.
run_sabotage "the rent plausibility band is removed (a postcode parses as a rent)" \
  src/php/Rent/Adapters/Payload.php \
  's%\$value >= self::RENT_FLOOR \&\& \$value <= self::RENT_CEILING%$value !== null%'

run_sabotage "only the band's 200 FLOOR is removed (an agency fee parses as a rent)" \
  src/php/Rent/Adapters/Payload.php \
  's%\$value >= self::RENT_FLOOR \&\& %%'

run_sabotage "only the band's 20000 CEILING is removed (a sale price parses as a rent)" \
  src/php/Rent/Adapters/Payload.php \
  's% \&\& \$value <= self::RENT_CEILING%%'

run_sabotage "SMTP permits plaintext credentials to a remote host" \
  src/php/Core/Notify/SmtpTransport.php \
  's%!Offline::isLoopbackHost(\$this->host)%false%'

run_sabotage "SMTP continues without STARTTLS when the server does not offer it" \
  src/php/Core/Notify/SmtpTransport.php \
  "s%if (stripos(\\\$capabilities, 'STARTTLS') === false) {%if (false) {%"

run_sabotage "SMTP stops masking the base64 form of the password" \
  src/php/Core/Notify/SmtpTransport.php \
  's%\$out\[\] = base64_encode(\$value);%%'

run_sabotage "a body line starting with a dot stops being stuffed (RFC 5321)" \
  src/php/Core/Notify/SmtpTransport.php \
  "s%'\\.\\.'%'.'%"

# ── Q37 pacing (hard rule 5) ─────────────────────────────────────────────────────────────────────
# Hard rule 5 forbids CAPTCHA solving, proxy rotation and fingerprint spoofing, which leaves polite
# rate limiting as the ENTIRE strategy for not being blocked. Every failure below is silent in the
# worst way this project has: a banned IP presents as every source going quiet at once, which is
# indistinguishable from a slow rental market — the exact shape hard rule 2 exists to prevent.

run_sabotage "the gap between two requests to the same host is dropped" \
  src/php/Core/Pacer.php \
  's%public const float SAME_HOST_GAP_SECONDS = 60.0;%public const float SAME_HOST_GAP_SECONDS = 0.0;%'

run_sabotage "the gap between requests to distinct hosts is dropped" \
  src/php/Core/Pacer.php \
  's%public const float DISTINCT_HOST_GAP_SECONDS = 5.0;%public const float DISTINCT_HOST_GAP_SECONDS = 0.0;%'

run_sabotage "the same-host window is only enforced against the LAST request" \
  src/php/Core/Pacer.php \
  's%if (isset(\$this->lastByHost\[\$key\])) {%if (false) {%'

run_sabotage "the poll cadence collapses to a tight loop" \
  src/php/Core/Pacer.php \
  's%public const int PASS_INTERVAL_SECONDS = 900;%public const int PASS_INTERVAL_SECONDS = 0;%'

run_sabotage "the jitter band is widened past the ruling" \
  src/php/Core/Pacer.php \
  's%public const int JITTER_SECONDS = 300;%public const int JITTER_SECONDS = 600;%'

run_sabotage "a fast pass is allowed to immediately start another" \
  src/php/Core/Pacer.php \
  's%public const float MIN_SECONDS_BETWEEN_PASSES = 60.0;%public const float MIN_SECONDS_BETWEEN_PASSES = 0.0;%'

run_sabotage "the host is compared case-sensitively (one site becomes two)" \
  src/php/Core/Pacer.php \
  's%\$key = strtolower(\$host);%\$key = \$host;%'

run_sabotage "the source order stops being shuffled each pass" \
  src/php/Core/Pacer.php \
  's%\$j = (\$this->rand)(0, \$i);%\$j = \$i;%'

# Records the slot on the way out instead of skipping it, rather than deleting the guard: dropping
# the guard would reach `strtolower(null)` and the suite would go red on a PHP deprecation rather
# than on the assertion, which proves the runtime noticed and nothing about the test.
run_sabotage "a hostless source consumes the distinct-host slot" \
  src/php/Core/Pacer.php \
  's%if (\$host === null || \$host === '"''"') {%if ($host === null || $host === '"''"') { $this->lastRequestAt = ($this->clock)(); return; } if (false) {%'

run_sabotage "the pacer records the time it INTENDED to wait, not the clock" \
  src/php/Core/Pacer.php \
  's%\$issuedAt = (\$this->clock)();%\$issuedAt = \$readyAt;%'

run_sabotage "the decorator does not pace at all (every request goes out unthrottled)" \
  src/php/Rent/Adapters/PacedSource.php \
  's%\$this->pacer->beforeFetch(\$this->inner->host());%%'

# A DIFFERENT regression from the one above, and the more plausible of the two: the pacing is all
# still there, it just lands on the wrong side of the request. Such a decorator fires its first two
# requests back to back and only then starts behaving, so a short pass is never throttled at all.
# The `finally` leaves the original `return` below unreachable, which PHP accepts.
run_sabotage "the decorator waits AFTER the request instead of before it" \
  src/php/Rent/Adapters/PacedSource.php \
  's%\$this->pacer->beforeFetch(\$this->inner->host());%try { return $this->inner->fetch(); } finally { $this->pacer->beforeFetch($this->inner->host()); }%'

run_sabotage "the decorator swallows a source failure into an empty list (rule 3)" \
  src/php/Rent/Adapters/PacedSource.php \
  's%return \$this->inner->fetch();%try { return \$this->inner->fetch(); } catch (\\Throwable) { return []; }%'

run_sabotage "wrapAll stops sharing one pacer (each source gets a private window)" \
  src/php/Rent/Adapters/PacedSource.php \
  's%static fn (Source \$s): Source => new self(\$s, \$pacer),%static fn (Source $s): Source => new self($s, clone $pacer),%'

run_sabotage "PacedSource::health drops the clock, so STALE can never fire" \
  src/php/Rent/Adapters/PacedSource.php \
  's%return \$this->inner->health(\$nowIso);%return \$this->inner->health();%'

# ── Row 36 (2026-09-04): processed alert emails are marked \Seen — after the store recorded them ──
#
# Developer request: "mark the emails … as seen when you process them, so that way I know which
# email was processed and which not". Every failure mode below is SILENT in the same direction: the
# flag stops meaning "processed" while every pass still reports ok. A flag that is set too early
# vouches for a pass that may not write; one set on unclaimed mail hides the unmatched sender the
# developer wants to see; one never set sends them looking for a broken source that is fine; and a
# fetch that opens the folder read-write is the invariant the class docblock promises away.

run_sabotage "the rent pipeline never acknowledges (processed mail stays unread for ever)" \
  src/php/Rent/Cli/Pipeline.php \
  's%\$source->acknowledge();%%'

run_sabotage "the rent pipeline acknowledges BEFORE the store recorded the pass" \
  src/php/Rent/Cli/Pipeline.php \
  's%\$fetched\[\] = \$source;%\$fetched[] = \$source; if (\$source instanceof AcknowledgesMessages) { \$source->acknowledge(); }%'

run_sabotage "the rent pipeline acknowledges a source whose fetch FAILED" \
  src/php/Rent/Cli/Pipeline.php \
  's%foreach (\$fetched as \$source) {%foreach (\$sources as \$source) {%'

run_sabotage "an acknowledgement refusal fails the whole rent pass" \
  src/php/Rent/Cli/Pipeline.php \
  's%\$errors\[\] = Redact::text(\$e->getMessage());%throw \$e;%'

run_sabotage "the car pipeline never acknowledges" \
  src/php/Car/VehiclePipeline.php \
  's%\$source->acknowledge();%%'

run_sabotage "the car pipeline acknowledges BEFORE the store recorded the pass" \
  src/php/Car/VehiclePipeline.php \
  's%++\$sourcesRun;%++\$sourcesRun; if (\$source instanceof AcknowledgesMessages) { \$source->acknowledge(); }%'

run_sabotage "an acknowledgement refusal fails the whole car pass" \
  src/php/Car/VehiclePipeline.php \
  's%\$errors\[\] = Redact::text(\$e->getMessage());%throw \$e;%'

# The deployed path: under --watch every rent source is wrapped. A decorator that drops the
# capability makes `--once` mark mail and the watcher never — the FeedFreshness scar, third time.
run_sabotage "PacedSource drops the acknowledgement (the watcher never marks, --once does)" \
  src/php/Rent/Adapters/PacedSource.php \
  's%\$this->inner->acknowledge();%%'

run_sabotage "the rent email source claims nothing (every message reads as unprocessed)" \
  src/php/Rent/Adapters/EmailAlertSource.php \
  's%\$this->mailbox->claim(\$position);%%'

run_sabotage "the rent email source claims a message that failed its own filters" \
  src/php/Rent/Adapters/EmailAlertSource.php \
  's%if (!\$this->isFrom(\$message)) {%\$this->mailbox->claim(\$position); if (!\$this->isFrom(\$message)) {%'

run_sabotage "the rent email source swallows a mailbox refusal (the flag silently stops being set)" \
  src/php/Rent/Adapters/EmailAlertSource.php \
  's%throw new SourceError(\$this->name(), .marquage des courriers traités refusé — . . \$e->getMessage(), \$e);%return;%'

run_sabotage "the car email source claims nothing" \
  src/php/Car/VehicleEmailSource.php \
  's%\$this->mailbox->claim(\$position);%%'

run_sabotage "the car email source swallows a mailbox refusal" \
  src/php/Car/VehicleEmailSource.php \
  's%throw new SourceError(\$this->name(), .marquage des courriers traités refusé — . . \$e->getMessage(), \$e);%return;%'

# On the wire, against the scripted loopback server (tests/php/Adapters/Mail/scripted-imap-server.php).
run_sabotage "a fetch opens the folder READ-WRITE (EXAMINE becomes SELECT)" \
  src/php/Adapters/Mail/ImapMailbox.php \
  "s%'EXAMINE ' . self::quote(\\\$this->folder)%'SELECT ' . self::quote(\\\$this->folder)%"

run_sabotage "READING sets \\Seen as a side effect (BODY.PEEK[] becomes BODY[])" \
  src/php/Adapters/Mail/ImapMailbox.php \
  's%(UID FLAGS BODY.PEEK\[\])%(UID FLAGS BODY[])%'

run_sabotage "the store REPLACES the flag list instead of adding to it (the developer's own flags stripped)" \
  src/php/Adapters/Mail/ImapMailbox.php \
  's%+FLAGS.SILENT (\\Seen)%FLAGS.SILENT (\\Seen)%'

run_sabotage "the store adds \\Deleted beside \\Seen" \
  src/php/Adapters/Mail/ImapMailbox.php \
  's%+FLAGS.SILENT (\\Seen)%+FLAGS.SILENT (\\Seen \\Deleted)%'

run_sabotage "claims survive the next fetch (a stale claim is stored on the next pass)" \
  src/php/Adapters/Mail/ImapMailbox.php \
  's%\$this->claimed = \[\];%%'

run_sabotage "UIDVALIDITY is not compared across the two sessions" \
  src/php/Adapters/Mail/ImapMailbox.php \
  's%if (\$this->uidValidity !== null \&\& \$validity !== \$this->uidValidity) {%if (false) {%'

run_sabotage "UNCLAIMED messages are stored too (the unmatched sender the developer wants to see is hidden)" \
  src/php/Adapters/Mail/ImapMailbox.php \
  's%foreach (array_keys(\$this->claimed) as \$position) {%foreach (array_keys(\$this->uids) as \$position) {%'

run_sabotage "already-seen messages are stored again on every pass (a login per pass for nothing)" \
  src/php/Adapters/Mail/ImapMailbox.php \
  's%if (isset(\$this->unseen\[\$uid\])) {%if (true) {%'

run_sabotage "the test connector is consulted BEFORE the offline refusal (a way round SCOUT_OFFLINE)" \
  src/php/Adapters/Mail/ImapMailbox.php \
  "s%\\\$refusal = \\\\Scout\\\\Core\\\\Offline::refusalForHost(%\\\$refusal = \\\$this->connector !== null ? null : \\\\Scout\\\\Core\\\\Offline::refusalForHost(%"

run_sabotage "the search asks for SEQUENCE NUMBERS, which the second session cannot use" \
  src/php/Adapters/Mail/ImapMailbox.php \
  "s%'UID SEARCH SINCE '%'SEARCH SINCE '%"

# ── Rows 37 + 38 (2026-09-05): content identity and the labelled-card reader on the car adapter ──
#
# Every direction below is silent. A floor deleted makes every stripped card of one model ONE car;
# a price in the key makes every drop a NEW car; a fold dropped or the source name dropped splits
# or merges identities nobody can see; the facts title ignored puts `Kilométrage : 24409` on the
# developer's phone as the car's name; the sentinel skipped on the facts path hands `Autres` the
# whole brand share (Track 6-A4 again); a refusal removed lets two providers for one fact load
# with one of them inert; the furniture precondition narrowed lets a footer link become a car; the
# last-link rule restored hands a push an unsubscribe redirect. ParuVendu and leboncoin fixture
# tests are the counterweight that the link path stayed byte-identical.

run_sabotage "the content floor is deleted (a title-only card is an identity every Clio shares)" \
  src/php/Car/VehicleEmailSource.php \
  "s%if (\\\$title === '' || (\\\$year === null \\&\\& \\\$km === null)) {%if (false) {%"

run_sabotage "the price enters the content key (every price drop is a brand-new car)" \
  src/php/Car/VehicleEmailSource.php \
  's%\$km === null ? '"''"' : (string) \$km,%\$km === null ? '"''"' : (string) \$km, (string) \$price,%'

run_sabotage "the content key stops folding the title (LEXUS UX and Lexus UX are two cars)" \
  src/php/Car/VehicleEmailSource.php \
  's%(string) self::foldOrNull(\$title),%\$title,%'

run_sabotage "the content key drops the source name (two portals, one row)" \
  src/php/Car/VehicleEmailSource.php \
  's%^\s*\$this->name(),$%%'

run_sabotage "the facts title is ignored (the positional fallback names the car after its mileage line)" \
  src/php/Car/VehicleEmailSource.php \
  's%\$fromFacts = \$factsTitle !== null || \$factsMake !== null || \$factsModel !== null;%\$fromFacts = false;%'

run_sabotage "the unknown-make sentinel skips the facts path (Autres earns the whole brand share)" \
  src/php/Car/VehicleEmailSource.php \
  's%if (\$make !== null \&\& preg_match(\$sentinel, \$make) === 1) {%if (\$factsMake === null \&\& \$make !== null \&\& preg_match(\$sentinel, \$make) === 1) {%'

run_sabotage "a stated lot reference is ignored (an auction card with no year and no km is dropped, the rest re-keyed on a hash)" \
  src/php/Car/VehicleEmailSource.php \
  "s%if (\\\$this->definition->param('id_from') === 'content' \\&\\& \\\$ref !== '') {%if (false) {%"

# ── Row 6 / A5 (2026-09-05): the push gate, the rollup queue and the third announcement kind ──
#
# Every direction here changes WHAT REACHES THE PHONE while every pass still reports ok: a gate
# that never holds back floods the phone again; a rollup that marks nothing re-announces every day;
# one that marks MATCH hides the promotion a later rent drop earns; a ROLLUP rank equal to MATCH
# does the same one layer down; a floor marker written before delivery consumes the day's window on
# a failed send. And the rent digest's title must never claim a tenure doubt for a settled match.

run_sabotage "the rent push gate is deleted (every match is pushed again, the queue never fills)" \
  src/php/Rent/Cli/Pipeline.php \
  's%if (\$pushMin !== null \&\& (\$verdict->score ?? 0) < \$pushMin) {%if (false) {%'

run_sabotage "a ROLLUP announcement ranks as a MATCH (the promotion over the gate becomes unreachable)" \
  src/php/Rent/Store/Store.php \
  "s%'ROLLUP' => 2,%'ROLLUP' => 3,%"

run_sabotage "a MATCH write no longer sticks against a later ROLLUP write (a pushed flat is demoted)" \
  src/php/Rent/Store/Store.php \
  's%WHEN notified_at IS NOT NULL AND COALESCE(notified_as, %WHEN false AND COALESCE(notified_as, %'

run_sabotage "the low-score queue also returns rows already announced (the rollup repeats itself daily)" \
  src/php/Rent/Store/Store.php \
  "s%              WHERE outcome = 'MATCH' AND notified_at IS NULL%              WHERE outcome = 'MATCH'%"

run_sabotage "the rent digest marks a rolled-up match as DIGEST (a settled LLI recorded as a tenure doubt)" \
  src/php/Rent/Cli/RentScout.php \
  "s%\\\$store->markNotified(\\\$key, \\\$now, 'ROLLUP');%\\\$store->markNotified(\\\$key, \\\$now, 'DIGEST');%"

run_sabotage "the rent digest drops the low-score section from the mail (queued matches are marked and never shown)" \
  src/php/Rent/Cli/RentScout.php \
  's%\$notification = (new Formatter())->digest(\$entries, \$lowScore);%$notification = (new Formatter())->digest($entries);%'

run_sabotage "the formatter attaches the regime clause to a rollup-only digest" \
  src/php/Rent/Notify/Formatter.php \
  "s%title: 'Vérifié, score bas : ' . count(\\\$lowScore) . ' annonce(s)',%title: 'À vérifier : ' . count(\\\$lowScore) . ' annonce(s) au régime indéterminé',%"

run_sabotage "the car push gate is deleted" \
  src/php/Car/VehiclePipeline.php \
  's%if (\$pushMin !== null \&\& (\$verdict->score ?? 0) < \$pushMin) {%if (false) {%'

run_sabotage "the car rollup queue returns announced cars too (the rollup repeats itself daily)" \
  src/php/Car/VehicleStore.php \
  "s%WHERE outcome = 'MATCH' AND notified_at IS NULL ORDER BY seen_epoch%WHERE outcome = 'MATCH' ORDER BY seen_epoch%"

run_sabotage "the car rollup verb marks nothing on delivery" \
  src/php/Car/Cli/CarScout.php \
  "s%\\\$store->markNotified(\\\$entry\\['key'\\], \\\$this->now());%%"

run_sabotage "the car rollup floor writes its marker BEFORE delivery is confirmed" \
  src/php/Car/Cli/CarScout.php \
  "s%\\\$this->warn('récapitulatif quotidien non délivré — rien marqué, nouvel essai au prochain passage.');%@file_put_contents(\\\$this->stateFile('car-rollup.txt'), \\\$now); \\\$this->warn('récapitulatif quotidien non délivré');%"

run_sabotage "the car rollup floor marks nothing on delivery (the same cars roll up every morning)" \
  src/php/Car/Cli/CarScout.php \
  "s%\\\$store->markNotified(\\\$entry\\['key'\\], \\\$now);%%"

run_sabotage "the car rollup is emitted as a MATCH kind (a rollup filed as a push)" \
  src/php/Car/VehicleFormatter.php \
  's%kind: NotificationKind::ROLLUP,%kind: NotificationKind::MATCH,%'

# The remainder line, both domains — the round-7 P2 shape again: `queuedLowScore` was asserted as a
# RunResult field on both pipelines while the LINE the operator reads was asserted on one CLI only,
# so the rent one could be deleted with the suite green (6C round 1, 2026-09-05).
run_sabotage "the rent pass stops saying how many matches it held back for the rollup" \
  src/php/Rent/Cli/RentScout.php \
  's%if (\$result->queuedLowScore > 0) {%if (false) {%'

run_sabotage "the car pass stops saying how many matches it held back for the rollup" \
  src/php/Car/Cli/CarScout.php \
  's%if (\$r->queuedLowScore > 0) {%if (false) {%'

# And `doctor`, rent side — the car side's `rollup :` line had a test from the start; the rent
# side had none until the deployed image was asked and answered nothing (6C round 1, 2026-09-05).
run_sabotage "rent doctor stops naming the push gate and the low-score queue" \
  src/php/Rent/Cli/RentScout.php \
  's%if (\$criteria->notify->pushMinScore !== null) {%if (false) {%'

# ── C2 round 6 (2026-09-05): a queued row AT the line is a failed push, not a low score ─────────
#
# The queue cannot tell a match HELD BACK by the gate from one whose push FAILED — both leave a
# MATCH outcome with no notified_at. Every direction below is silent: a split that never retries
# files a real match under « score bas » for ever; a drain that never collapses twins announces one
# flat twice; a collapse that marks one key re-announces the other tomorrow; and a source config
# the drain cannot read must not take §1's only landing zone down with it. The retry call is
# DUPLICATED on purpose — verb and floor — so each site gets its own case: a fix landing on one of
# two symmetric surfaces is this repo's named recurring defect.

run_sabotage "the rent drain files every queued row as « score bas » (a failed push never retried)" \
  src/php/Rent/Cli/RentScout.php \
  's%if (\$pushMin === null || (\$entry\['"'"'verdict'"'"'\]->score ?? 0) >= \$pushMin) {%if (false) {%'

run_sabotage "the rent digest VERB stops re-pushing the retries" \
  src/php/Rent/Cli/RentScout.php \
  '/private function digest(/,/private function collectDigest/ s%\$this->pushRetries(\$notifier, \$store, \$batch->retries, \$now);%%'

run_sabotage "the rent daily FLOOR stops re-pushing the retries" \
  src/php/Rent/Cli/RentScout.php \
  '/private function floorDigest(/,$ s%\$this->pushRetries(\$notifier, \$store, \$batch->retries, \$now);%%'

run_sabotage "a rent retry marks the row even though the channel refused it" \
  src/php/Rent/Cli/RentScout.php \
  '/private function pushRetries/,/^    }/ s%if (!\$notifier->delivered(\$failures)) {%if (false) {%'

run_sabotage "the rent drain stops collapsing cross-track twins (one flat announced twice)" \
  src/php/Rent/Cli/RentScout.php \
  's%\$queued = \$this->collapseTwins(\$queued, \$warnings);%%'

run_sabotage "a collapsed twin marks only the survivor (the other route is announced again tomorrow)" \
  src/php/Rent/Cli/RentScout.php \
  "s%'keys' => array_values(array_unique(\\[...\\\$kept\\['keys'\\], ...\\\$entry\\['keys'\\]\\])),%'keys' => \\\$winner['keys'],%"

run_sabotage "an unreadable sources.json degrades the twin collapse SILENTLY" \
  src/php/Rent/Cli/RentScout.php \
  "s%\\\$warnings\\[\\] = 'familles des sources illisibles.*%%"

run_sabotage "a rollup-only rent mail is emitted under the DIGEST kind (a settled match filed as a tenure doubt)" \
  src/php/Rent/Notify/Formatter.php \
  's%kind: NotificationKind::ROLLUP,%kind: NotificationKind::DIGEST,%'

run_sabotage "the car drain files every queued car as « score bas » (a failed push never retried)" \
  src/php/Car/Cli/CarScout.php \
  's%if (\$pushMin === null || (\$score !== null \&\& \$score >= \$pushMin)) {%if (false) {%'

run_sabotage "the car rollup VERB stops re-pushing the retries" \
  src/php/Car/Cli/CarScout.php \
  's%\$this->pushRetries(\$notifier, \$store, \$retries, \$this->now());%%'

run_sabotage "the car rollup FLOOR stops re-pushing the retries" \
  src/php/Car/Cli/CarScout.php \
  's%\$this->pushRetries(\$notifier, \$store, \$retries, \$now);%%'

run_sabotage "a car retry marks the row even though the channel refused it" \
  src/php/Car/Cli/CarScout.php \
  '/private function pushRetries/,/^    }/ s%if (!\$notifier->delivered(\$failures)) {%if (false) {%'

# ── ParuVendu writes its facts line in THREE shapes (2026-09-05) ────────────────────────────────
#
# The pattern required `body - fuel - Année YYYY - N km`, so `Essence - Année …` and a bare
# `Année …` failed the WHOLE match and year, mileage, fuel and body went null TOGETHER on 17 of 160
# stored cards (11 %; live doctor: 15/132). Year and mileage are the two heaviest score inputs, so
# those cars were judged `année inconnue / kilométrage inconnu` with the facts on their own card —
# and nothing read as a fault, because a card that extracts nothing looks exactly like a card that
# says nothing. Each direction below is one of the three shapes the trial rejected.

run_sabotage "the ParuVendu body becomes required again (a lone fuel loses year and mileage)" \
  config/car/sources.json \
  's%)??(?:%)(?:%'

run_sabotage "the ParuVendu fuel becomes required again (a bare Année line loses year and mileage)" \
  config/car/sources.json \
  's%)?Année%)Année%'

run_sabotage "the ParuVendu body turns greedy (a lone fuel lands in the BODY slot)" \
  config/car/sources.json \
  's%)??(?:%)?(?:%'

run_sabotage "GPL ou GNL leaves the closed fuel list (a stated fuel becomes part of the body)" \
  config/car/sources.json \
  's%GPL ou GNL|%%'

# ── C2 round 7 (2026-09-05): §1 in the drain, and the answers a drain must not discard ──────────
#
# The drain is the THIRD verdict surface and the one that reaches the phone. `Pipeline` and
# `reclassify` both refuse on the PERSISTED twin and group readings; the drain judged §1 from the
# row's own `tenure` column alone, so a flat whose twin on the other track was judged `PLS` — the
# exact state schema v12 persists the veto for — was pushed as an individual MATCH and marked
# MATCH, which cannot be demoted. The car twin is the same shape one domain over: a re-judge that
# says REJECT, discarded, and the row announced on the stored score with a reason line that lies.

# SCOPED to collectDigest(), because `reclassify()` carries a byte-identical line and `sed` without
# /g still applies to EVERY matching line — so this case and its reclassify twin below were ONE
# mutation wearing two labels, and neither proved the surface on its label (C2 milestone panel, P3).
run_sabotage "the rent drain ignores the OTHER TRACK's excluded reading (a PLS twin is pushed)" \
  src/php/Rent/Cli/RentScout.php \
  '/private function collectDigest/,/private function reclassify(/ s%\$twin = \$store->twinTenure(\$key);%$twin = null;%'

# SCOPED to collectDigest() — see the note above; `reclassify()` carries the same line.
run_sabotage "the rent drain ignores its cluster's excluded member (§1 across the group)" \
  src/php/Rent/Cli/RentScout.php \
  '/private function collectDigest/,/private function reclassify(/ s%\$groupVeto = \$store->groupExcludedTenure(\$key);%$groupVeto = null;%'

run_sabotage "the car drain announces a car today's rules REJECT (the excluded vehicle set)" \
  src/php/Car/Cli/CarScout.php \
  's%if (\$judged->outcome !== VehicleOutcome::MATCH) {%if (false) {%'

run_sabotage "a snapshot-less queued row is filed under a threshold that may not exist" \
  src/php/Rent/Cli/RentScout.php \
  '/if (\$listing === null) {/,/^                }$/ s%\$queued\[\]%$lowScore[]%'

run_sabotage "the remainder line counts a drained retry as still pending (a phantom backlog)" \
  src/php/Rent/Cli/DigestBatch.php \
  's%\$drained += .count(\$entry\['"'"'keys'"'"'\]);%$drained += 0;%'

run_sabotage "the car remainder line counts a drained retry as still pending" \
  src/php/Car/Cli/CarScout.php \
  's%if ($remaining > 0) {%if (false) {%'

# ── C2 round 6 (2026-09-05): the drain a run mode ACTUALLY has ─────────────────────────────────
#
# The daily floors live inside the watch loop. Telling a cron-driven `--once` deployment to wait for
# a *récapitulatif quotidien* names a drain that will never run there: the queue grows for ever and
# the line reads as reassurance. Both the pass line and `doctor` say the scope, on both domains.

run_sabotage "the rent pass promises a daily floor a --once deployment does not have" \
  src/php/Rent/Cli/RentScout.php \
  's%^                \$watching$%                true%'

run_sabotage "the car pass promises a daily floor a --once deployment does not have" \
  src/php/Car/Cli/CarScout.php \
  's%^                \$watching$%                true%'

run_sabotage "rent doctor stops saying the daily floor is --watch only" \
  src/php/Rent/Cli/RentScout.php \
  's% ; le plancher quotidien ne tourne que sous --watch%%'

run_sabotage "car doctor stops saying the daily floor is --watch only" \
  src/php/Car/Cli/CarScout.php \
  's% sous --watch UNIQUEMENT%%'

# ── C2 round 6 (2026-09-05): the ONE decode cascade ──────────────────────────────────────────────
#
# `FixtureSecretsTest::allForms()` and `tools/scrub-eml.php` were two copies of one cascade, and the
# copy had drifted one decode short: the guard never percent-decoded a freshly decoded base64 layer,
# so base64(percent-encoded(address)) passed CI while the tool refused it. Both now delegate to
# `Scout\Core\RecoverableForms`. The shape is caught by EITHER the percent-decode inside the depth
# loop OR the final percent pass over every form (measured: removing one leaves the test green),
# so this case removes both — the planted-address test's fourth shape must then go red.
run_sabotage "the shared decode cascade stops percent-decoding decoded forms" \
  src/php/Core/RecoverableForms.php \
  's%^                \$text = rawurldecode(\$text);$%                // mutated%;s%\$percent = rawurldecode(\$form);%$percent = $form;%'


# ── Rows 40 + 41 (2026-09-05): the reopen repair, and the same-filter warning ──
#
# `--reopen` is the ONE way back for a durably-excluded row, and a half-done reopen is worse than
# none: clearing the own reading but not the twin's leaves the twin veto in force and the operator
# believing the row was re-opened. A dry run that clears is the opposite failure. The same-filter
# warning is silent in every direction it can break: not returned, floored at one card, or counting
# §1 rejections — which would fire on a source of social housing and dilute the one honest signal.

run_sabotage "reopen clears the row's own reading but not the twin's (the twin veto stays in force)" \
  src/php/Rent/Store/Store.php \
  's%UPDATE listings SET tenure = NULL, twin_tenure = NULL, twin_source = NULL WHERE dedup_key = :key%UPDATE listings SET tenure = NULL WHERE dedup_key = :key%'

run_sabotage "a dry-run reopen clears the reading anyway" \
  src/php/Rent/Store/Store.php \
  's%if (!\$dryRun) {%if (true) {%'

run_sabotage "reopen on an unknown key is silently a no-op with exit 0" \
  src/php/Rent/Cli/RentScout.php \
  's%return \$this->fail(.--reopen : aucune annonce ne porte la clé . . \$reopen . . — rien n..a été touché.);%$this->line("");%'

run_sabotage "the rent pipeline computes the same-filter warnings and returns none" \
  src/php/Rent/Cli/Pipeline.php \
  's%rejected: \$rejected, warnings: \$warnings%rejected: $rejected, warnings: []%'

run_sabotage "the car pipeline computes the same-filter warnings and returns none" \
  src/php/Car/VehiclePipeline.php \
  's%warnings: SameFilterWarning::warnings(\$filterTally),%warnings: [],%'

run_sabotage "§1 and vehicle-set rejections count toward the same-filter warning (it fires on a source of social housing)" \
  src/php/Core/SameFilterWarning.php \
  "s%if (str_starts_with(\\\$lower, 'tenure:') || str_starts_with(\\\$lower, 'exclu :') || str_starts_with(\\\$lower, 'exclu:')) {%if (false) {%"

run_sabotage "the same-filter floor drops to one card (one bad card is a warning)" \
  src/php/Core/SameFilterWarning.php \
  's%public const int FLOOR = 3;%public const int FLOOR = 1;%'

run_sabotage "the same-filter warning fires when MOST cards, not every card, fail one filter" \
  src/php/Core/SameFilterWarning.php \
  "s%if (\\\$count === \\\$t\\['judged'\\]) {%if (\\\$count * 2 >= \\\$t['judged']) {%"

run_sabotage "the facts gearbox is ignored (a labelled Automatique scores as unstated)" \
  src/php/Car/VehicleEmailSource.php \
  's%gearbox: \$factsGearbox ?? self::gearboxFromTitle(\$title),%gearbox: self::gearboxFromTitle(\$title),%'

run_sabotage "the furniture check is keyed on price_pattern alone again (a footer link becomes a car under link identity)" \
  src/php/Car/VehicleEmailSource.php \
  's%\$priceConfigured = \$pricePattern !== null || \$factsHasPrice;%\$priceConfigured = \$pricePattern !== null;%'

run_sabotage "link_after is ignored — the LAST host link wins again (a push carries the footer's unsubscribe redirect)" \
  src/php/Car/VehicleEmailSource.php \
  's%foreach (\$after !== null ? \$m\[0\] : array_reverse(\$m\[0\]) as \$candidate) {%foreach (array_reverse(\$m[0]) as \$candidate) {%'

run_sabotage "an unknown id_from value is accepted (a typo falls to link identity on a tracking-link portal)" \
  src/php/Car/VehicleSourceLoader.php \
  "s%!in_array(\\\$params\\['id_from'\\], \\['link', 'content'\\], true)%false%"

run_sabotage "two providers for one fact load together (one of them inert)" \
  src/php/Car/VehicleSourceLoader.php \
  's%foreach (self::FACTS_GROUP_REPLACES as \$group => \$replaced) {%foreach ([] as \$group => \$replaced) {%'

run_sabotage "both car separators load together (one ignored, in silence)" \
  src/php/Car/VehicleSourceLoader.php \
  "s%(\\\$params\\['card_separator'\\] ?? '') !== '' \\&\\& (\\\$params\\['card_separator_pattern'\\] ?? '') !== ''%false%"

# Narrows the caught type instead of rethrowing: `\LogicException` is a real class, so the file still
# parses, and every exception the loop is meant to survive (`SourceError`, `\RuntimeException`) now
# escapes `run()`. NOTE the single backslashes — `\\` in a BRE is one literal backslash, and an
# earlier `\\\\` here matched two, making this whole case a silent no-op.
run_sabotage "a throwing pass kills the watch loop" \
  src/php/Cli/WatchLoop.php \
  's%} catch (\\Exception \$e) {%} catch (\\LogicException $e) {%'

run_sabotage "a failing pass is survived in SILENCE (nothing is reported)" \
  src/php/Cli/WatchLoop.php \
  's%(\$this->onError)(\$e);%%'

# Only the `stopping` half is degraded, and the `$maxPasses` half is deliberately left standing.
#
# Blanking the whole condition to `if (false)` also disables the bound `SCOUT_MAX_PASSES` relies
# on, and that bound is the ONLY thing stopping `ScoutTest`'s bounded `--watch` tests from entering a
# real `betweenPasses()` wait — 600 to 1200 seconds of genuine sleep. The suite then does not go red,
# it BLOCKS: measured on 2026-08-19, the full ledger reported this case as
# `the suite did not terminate within 300s — inconclusive`. A sabotage that takes out the test
# harness's own anti-hang seam along with the guarantee is measuring the seam, not the guarantee.
#
# Degrading only `$this->stopping` reproduces exactly the regression the case is named for — a stop
# request no longer interrupting the loop, so the process naps for a quarter of an hour before
# exiting — while leaving every bounded test bounded. Verified: the suite goes red in 16 s on
# `WatchLoopTest::testStopDoesNotSleepBeforeExiting`, "exiting must be prompt, not after a
# fifteen-minute nap", with the trailing 600.0 s sleep printed in the diff.
run_sabotage "the loop stops mid-pass instead of finishing the pass in flight" \
  src/php/Cli/WatchLoop.php \
  's%if (\$this->stopping || (\$maxPasses !== null \&\& \$completed >= \$maxPasses)) {%if (\$maxPasses !== null \&\& \$completed >= \$maxPasses) {%'

run_sabotage "the inter-pass wait stops being interruptible by a signal" \
  src/php/Cli/WatchLoop.php \
  's%if (\$shouldStop()) {%if (false) {%'

run_sabotage "the wait is served as one long sleep, ignoring signals for 20 min" \
  src/php/Cli/WatchLoop.php \
  's%\$slice = min(self::TICK_SECONDS, \$remaining);%$slice = $remaining;%'

# ── The html adapter (In'li, the first real source), added 2026-08-19 ─────────────────────────────
#
# Every case here is a SILENT failure by construction. An html adapter that stops extracting does
# not error, does not slow down and does not look different — it reports a healthy run with no
# listings, which is indistinguishable from a rental market that went quiet. That is the whole
# reason this source's adapter throws where the obvious code would `return []`.

# The one that matters most. A redesign renaming `featured-item` leaves a 200, valid HTML and zero
# cards; returning them as an empty list reports calm forever.
run_sabotage "a selector matching nothing returns an empty list instead of throwing" \
  src/php/Rent/Adapters/HtmlSource.php \
  's%if (\$items->count() === 0) {%if (false) {%'

# Drops the capture and hands the whole text node to the number parser. `3 pièces · 55.32 m²` then
# yields the FIRST token for the surface — 3 m² instead of 55.32 — and 3 m² is a number, so nothing
# downstream can tell it apart from a very small studio.
run_sabotage "the field map's regex capture is ignored (surface reads the room count)" \
  src/php/Rent/Adapters/Html/Selector.php \
  's%if ($this->capture === null) {%if (true) {%'

# Walking pages until one comes back empty is a TERMINATION rule, not a correctness proof: a page=N
# that quietly 404s or redirects to page one ends the walk exactly like a genuine last page. Without
# the declared-total check, 24 of 92 listings is reported as a complete pass.
run_sabotage "pagination stops checking the total the page declares" \
  src/php/Rent/Adapters/HtmlSource.php \
  's%if (\$total !== null \&\& count(\$out) + \$tolerance < \$total) {%if (false) {%'

# Unbounded pagination against a site that ignores the page parameter is an infinite request loop
# on somebody else's server — the one bug in this adapter that could actually get an IP banned,
# which under hard rule 5 is the thing polite pacing exists to prevent.
run_sabotage "the pagination page bound stops being enforced" \
  src/php/Rent/Adapters/HtmlSource.php \
  's%if (!\$finished \&\& \$page >= \$this->definition->maxPages) {%if (false) {%'

# A pattern that does not match yields the UNPARSED text instead of null. Hard rule 9's neighbour:
# the field is then a string that happens to contain a number somewhere, and the parser will find
# one — so an unknown becomes a confident wrong value rather than an honest absence.
run_sabotage "a regex that does not match returns the raw text instead of null" \
  src/php/Rent/Adapters/Html/Selector.php \
  's%return \$value === .. ? null : \$value;%return $value;%'

# The loader stops refusing an enabled html source with no item_selector, so the refusal moves from
# load time to fetch time — after a poll has been scheduled and a run logged against a source that
# was never going to work.
run_sabotage "an enabled html source may ship with no item_selector" \
  src/php/Rent/Config/ConfigLoader.php \
  "s%if (\\\$type === 'html' \&\& (\\\$itemSelector === null || trim(\\\$itemSelector) === '')) {%if (false) {%"

# ── the Q36 flood guard ───────────────────────────────────────────────────────────────────────
#
# Every failure here is silent in the same direction: the guard still exists, still reads a
# plausible fact, and lets the run through. Nobody sees a stack trace — they see one push per
# listing in the back catalogue, which on a source like In'li is ninety-two of them at once.

# There is deliberately NO case for `SCOUT_MAX_PASSES` itself. Removing the bound does not make
# a test fail, it makes one BLOCK — which the timeout above reports as inconclusive, correctly, since
# a suite that never finished never said anything. The hang is the signal; it just is not detection.

# The guard is disarmed outright.
run_sabotage "the empty-database guard stops firing" \
  src/php/Rent/Cli/RentScout.php \
  's%if (\$store->isSeenSetEmpty() \&\& !\$seed) {%if (false) {%'

# It fires, but `--seed` is no longer the way past it, so the ONLY documented route through is shut
# and the guard becomes a wall: the tool can never make its first real run.
run_sabotage "--seed no longer gets past the empty-database guard" \
  src/php/Rent/Cli/RentScout.php \
  's%if (\$store->isSeenSetEmpty() \&\& !\$seed) {%if (true) {%'

# The regression this whole change fixes, reintroduced: the store answers "has anything been
# recorded?" from a flag about THIS process instead of from the rows. Any earlier command that
# opened the file — `scout --domain=rent doctor` is the first one a new machine invites you to type — then
# answers it for good.
run_sabotage "the seen-set emptiness answer comes from the process, not the rows" \
  src/php/Rent/Store/Store.php \
  's%return (int) \$this->pdo->query(.SELECT EXISTS (SELECT 1 FROM listings).)->fetchColumn() === 0;%return false;%'

# ── schema v4, the cross-portal group ─────────────────────────────────────────────────────────────
# Every guarantee below fails SILENTLY. A group that stops being assigned looks exactly like a market
# with no cross-portal duplicates; a history that reports nothing looks like a flat whose rent never
# moved. Neither shows up as an error, and both are invisible in a green suite.

run_sabotage "a cluster's members are no longer tied into a group" \
  src/php/Rent/Store/Store.php \
  's%\$members = array_values(array_unique(\$memberKeys));%$members = [];%'

# The one that survives a shuffle. Minting from whoever survived THIS pass renames the group every
# time source order changes, and orphans any member that delisted in between.
run_sabotage "the group key is minted fresh each pass instead of adopted" \
  src/php/Rent/Store/Store.php \
  's%\$adopted ??= \$members\[0\];%$adopted = $members[0];%'

# NULL never equals NULL in SQL, so taking the group branch for an ungrouped listing reports "no
# price history" for most of the database, silently.
run_sabotage "the joined history compares a NULL group against itself" \
  src/php/Rent/Store/Store.php \
  's%if (\$group === null) {%if (false) {%'

# The §1-adjacent one: a group-scoped notification gate hides a real flat the moment an over-merge
# happens, and hides it permanently.
run_sabotage "grouping also marks its members notified" \
  src/php/Rent/Store/Store.php \
  's%UPDATE listings SET group_key = :group WHERE dedup_key = :key%UPDATE listings SET group_key = :group, notified_at = first_seen_at WHERE dedup_key = :key%'

run_sabotage "an older database is opened without ever adding group_key" \
  src/php/Rent/Store/Store.php \
  's%if (\$recorded < 4) {%if (false) {%'

# Back to the pre-v4 shape: cluster first, record only survivors. The overlay then ships inert —
# every group is a group of one and nothing anywhere says so.
run_sabotage "only the cluster survivor is recorded" \
  src/php/Rent/Cli/Pipeline.php \
  "s%foreach (\\\$cluster\\['members'\\] as \\\$member) {%foreach ([\$cluster['listing']] as \$member) {%"

run_sabotage "--seed marks only the survivor, leaving members to be announced later" \
  src/php/Rent/Cli/Pipeline.php \
  "s%foreach (\\\$clusterKeys\\[spl_object_id(\\\$listing)\\] as \\\$memberKey) {%foreach ([\$sighting->dedupKey] as \$memberKey) {%"

# ── the second source: CDC Habitat, path pagination, and prose that is not an acronym ─────────────
#
# Every one of these is silent. A source that classifies everything UNKNOWN looks like a quiet
# market; a floor parser that loses RDC looks like ads that omit the floor; a robots check that
# only covers page one keeps returning 200 while breaking hard rule 5.

run_sabotage "the card's own prose is scanned as an identifier field again (the adverb PLUS returns)" \
  src/php/Rent/Core/TenureClassifier.php \
  "s%if (\\\$name === '_text') {%if (false) {%"

run_sabotage "RawListing::text stops returning the adapter's card-text surface" \
  src/php/Rent/Core/RawListing.php \
  's%\$cardText = \$this->fields\[._text.\] ?? null;%$cardText = null;%'

run_sabotage "the config's own tenure_field stops being read as a financing field" \
  src/php/Rent/Core/TenureClassifier.php \
  "s%'tenurefield',%%"

run_sabotage "RDC stops meaning the ground floor (null is not zero, hard rule 9)" \
  src/php/Rent/Adapters/Payload.php \
  's%return 0;%return null;%'

# RETARGETED 2026-09-02: this matched the WHOLE assignment line, and Track 6-A3 wrapped the call in
# `$this->noted(...)` to count extraction misses — so the expression went INERT, reporting coverage
# it did not have, and `tests/test-sabotage-applies.sh` is what said so. It now targets the CALL
# rather than the line, which survives a wrapper. Same class as the `Core/RunStore` extraction that
# orphaned 39 expressions: code moving or being wrapped is invisible to a green suite.
run_sabotage "a floor is read with the generic number parser (the room count becomes the floor)" \
  src/php/Rent/Adapters/ListingMapper.php \
  's%Payload::floor(\$item, \$map->floor)%Payload::int($item, $map->floor)%'

run_sabotage "robots is checked for the index only, never for the pages the walk visits" \
  src/php/Rent/Adapters/HtmlSource.php \
  's%if (!\$this->robots->allows(Robots::pathOf(\$pageUrl))) {%if (false) {%'

run_sabotage "page_path silently falls back to appending a query parameter" \
  src/php/Rent/Adapters/HtmlSource.php \
  's%\$pageBody = \$this->get(\$pageUrl, \[\]);%$pageBody = $this->get($url, ["page" => (string) $page]);%'

run_sabotage "a page_path with no {page} placeholder is accepted, so the walk never advances" \
  src/php/Rent/Config/ConfigLoader.php \
  's%if (\$pagePath !== null \&\& !str_contains(\$pagePath, .{page}.)) {%if (false) {%'


# ── source #3: Cityloger, detail-page hydration, and prose that is not a tenure ───────────────────
#
# Every one of these is silent. A gate that stops narrowing turns one pass into 51 requests against
# somebody else's site; a detail map that reads the whole page classifies a real logement
# intermédiaire as UNKNOWN and digests it forever; and a merge that lets an absent field win erases
# what the card already knew.

# This case used to read `the detail gate stops narrowing`, targeting `$this->detailGate`. That
# symbol was renamed to `$detailPriority` on 2026-08-23 when novelty replaced the predicate, so the
# expression matched NOTHING and the ledger scored it `unapplied` — a case that reports as a covered
# guarantee while testing nothing at all, which is worse than one that fails. What bounds requests
# now is the budget, not the ordering, so the sabotage targets the budget.
run_sabotage "the per-pass detail budget stops bounding (every novel listing costs a request)" \
  src/php/Rent/Adapters/DetailHydrator.php \
  's%if ($spent >= $budget) {%if (false) {%'

# ── Phase 2b: prose readers, and the two facts they manufacture if read carelessly ────────────────
#
# Every one of these is hard rule 9 inverted -- a fact invented out of its own negation. None is
# caught by a green suite, because each produces a plausible-looking value rather than an error: a
# floor that is really the building's height, a lift on a flat that has none, a field that stops
# being collected the day its map changes, and a correct match demoted to the digest by an adverb.

run_sabotage "Payload::floor reads a plural count, so a building height becomes the tenant's floor" \
  src/php/Rent/Adapters/Payload.php \
  's%etage[\]b/%etage/%'

run_sabotage "Prose::floor reads a plural count (de 18 etages) as a position" \
  src/php/Rent/Core/Prose.php \
  's%etage[\]b/%etage/%'

run_sabotage "Prose::elevator stops reading the negation first (Aucun ascenseur becomes a lift)" \
  src/php/Rent/Core/Prose.php \
  's%if ($negation !== null && ($assertion === null || $negation > $assertion)) {%if (false) {%'

run_sabotage "the detail cache stops being keyed on the map, so a widened map serves stale rows" \
  src/php/Rent/Adapters/DetailHydrator.php \
  's%$listing->externalId, $detailMap->fingerprint());%$listing->externalId);%'

run_sabotage "prose fields are scanned as identifiers again, so the adverb plus reads as PLUS" \
  src/php/Rent/Core/TenureClassifier.php \
  "s%(\$name === 'title' || \$name === 'description')%(\$name === 'never-a-real-field')%"

# The successor to "a detail_map with no gate refuses", which retired on 2026-08-23 when novelty
# became the gate. What that invariant protected is unchanged: a detail map that can never run
# leaves its source resolving UNKNOWN for ever while health stays green. The refusal moved up a
# layer, to config load, so the sabotage moved with it rather than being deleted.
run_sabotage "a detail_map with a zero budget is accepted, so it can never run" \
  src/php/Rent/Config/ConfigLoader.php \
  's%if ($detailMap !== null \&\& $detailBudget === 0) {%if (false) {%'

run_sabotage "a failed detail fetch becomes an unhydrated listing (rule 3)" \
  src/php/Rent/Adapters/DetailHydrator.php \
  's%} catch (SourceError $e) {%} catch (SourceError $e) { return $listing;%'

run_sabotage "the detail extractor emits the whole PAGE as the description" \
  src/php/Rent/Adapters/DetailHydrator.php \
  "/private function detailFields/,\$ s%        return \$out;%        \$out['description'] = Selector::normalise(\$root->textContent); return \$out;%"

# --- the prose-absent caveat (PAP colocation ruling, 2026-09-01) -------------------------------
#
# A source that publishes no listing text cannot have `exclude_patterns` or `exclude_title_patterns`
# run against it, and on PAP that is every push. Nothing available can stop the colocations arriving
# — the detail page is behind a bot challenge (hard rule 5) and rent-per-room has no gap to
# threshold on — so the notification SAYS the check could not be made. Delete the line and every PAP
# push silently claims a clean bill of health it never had.
run_sabotage "the prose-absent caveat stops being reported, so a push claims a check it never made" \
  src/php/Rent/Core/CriteriaEngine.php \
  's%if ($listing->proseAbsent) {%if (false) {%'

# THE COUNTERWEIGHT, and it matters as much as the guarantee: a caveat on every push everywhere is
# furniture, and furniture stops being read. The line means something only where it is true.
run_sabotage "the caveat fires on every source, not only the ones that publish no text" \
  src/php/Rent/Core/CriteriaEngine.php \
  's%if ($listing->proseAbsent) {%if (true) {%'

# The declaration must not survive a detail merge being forgotten, nor the snapshot round trip —
# `mergedWith()` rebuilds field by field and `reclassify` re-judges from the snapshot alone, so
# either omission drops the caveat silently on exactly the rows that need it.
run_sabotage "a detail merge drops the prose-absent declaration" \
  src/php/Rent/Core/RawListing.php \
  's%proseAbsent: $this->proseAbsent,%proseAbsent: false,%'

run_sabotage "the prose-absent declaration is not persisted, so reclassify loses the caveat" \
  src/php/Rent/Core/ListingSnapshot.php \
  "s%'proseAbsent' => \$listing->proseAbsent,%%"

# A DECLARATION CONTRADICTED BY THE CONFIG BESIDE IT is worse than none: it reads as considered, and
# every push on the source would carry a caveat that is false.
run_sabotage "a source may declare prose_absent while mapping a description" \
  src/php/Rent/Config/ConfigLoader.php \
  's%if ($proseAbsent && $map->description !== \[\]) {%if (false) {%'

# THE MERGE WAS TESTED IN ONE DIRECTION ONLY, and production runs the other (C2 round 4).
# `DetailHydrator` calls `$card->mergedWith($detail)`; the guard merged a RICH card with an EMPTY
# detail, so every `$any($mine, $theirs)` took `$mine` and a field rewritten to `$this->x` left all
# 2 753 tests green. Two live `detail_map` fields are covered by fixtures; every other one — and
# every field added tomorrow — was exposed. Two arms rather than one: the fixture-covered field
# would have gone red anyway and would have hidden that the guard itself was blind.
run_sabotage "a merged field is taken from the card only, so the detail page is never read" \
  src/php/Rent/Core/RawListing.php \
  's%bedrooms: $any($this->bedrooms, $detail->bedrooms),%bedrooms: $this->bedrooms,%'

run_sabotage "a live detail_map field is taken from the card only" \
  src/php/Rent/Core/RawListing.php \
  's%hasElevator: $any($this->hasElevator, $detail->hasElevator),%hasElevator: $this->hasElevator,%'

# THE COUNTERWEIGHT to the guard above, which DERIVES the merged set from this method's own body:
# emptying the set would satisfy the direction assertion vacuously, on a method merging nothing.
# THE MUTATION MUST PARSE. A first version cut the expression mid-argument-list, leaving
# `rentCc: $this->NOPE_rentCc, $detail->rentCc),` — a stray argument and an unbalanced paren, so
# `RawListing` failed to LOAD and the run reported `Errors, Assertions: 0`. That is a parse error
# wearing a detection's clothes, and the ledger would rightly call it "proves nothing either way".
# This form rewrites each line whole and leaves valid code that merges nothing.
run_sabotage "mergedWith merges nothing, so the derived guard passes vacuously" \
  src/php/Rent/Core/RawListing.php \
  's%\([a-zA-Z]*\): $any($this->[a-zA-Z]*, $detail->[a-zA-Z]*),%\1: $this->\1,%g'

# WHICH SIDE WINS WHEN BOTH HOLD A VALUE. Swapping the arguments is invisible to a merge with one
# side empty — the guard tested exactly that shape and a round-5 lens proved the swap passed. The
# rule is `$theirs ?? $mine`: the detail page is the better evidence, and it is what a `detail_map`
# is fetched for.
run_sabotage "the CARD beats the detail page when both state a value" \
  src/php/Rent/Core/RawListing.php \
  's%\$any = static fn (mixed \$mine, mixed \$theirs): mixed => \$theirs ?? \$mine;%$any = static fn (mixed $mine, mixed $theirs): mixed => $mine ?? $theirs;%'

run_sabotage "the CARD's text beats the detail page's when both are non-empty" \
  src/php/Rent/Core/RawListing.php \
  "s%\$str = static fn (string \$mine, string \$theirs): string => \$theirs !== '' ? \$theirs : \$mine;%\$str = static fn (string \$mine, string \$theirs): string => \$mine !== '' ? \$mine : \$theirs;%"

run_sabotage "an absent detail value overwrites what the card knew (rule 9)" \
  src/php/Rent/Core/RawListing.php \
  's%static fn (mixed $mine, mixed $theirs): mixed => $theirs ?? $mine%static fn (mixed $mine, mixed $theirs): mixed => $theirs%'

run_sabotage "an empty detail string overwrites the card's own text (rule 9)" \
  src/php/Rent/Core/RawListing.php \
  's%static fn (string $mine, string $theirs): string => $theirs !== .. ? $theirs : $mine%static fn (string $mine, string $theirs): string => $theirs%'

run_sabotage "the detail page re-identifies the listing, so it re-notifies forever" \
  src/php/Rent/Core/RawListing.php \
  's%externalId: $this->externalId,%externalId: $detail->externalId !== '"'"''"'"' ? $detail->externalId : $this->externalId,%'

run_sabotage "robots is checked for the search page only, never for the detail pages" \
  src/php/Rent/Adapters/DetailHydrator.php \
  '/private function withDetail/,$ s%if (!$this->robots->allows(Robots::pathOf($url))) {%if (false) {%'

run_sabotage "detail fetches stop being paced (a per-listing burst, hard rule 5)" \
  src/php/Rent/Adapters/DetailHydrator.php \
  '/private function withDetail/,$ s%($this->sleeper ?? static fn (int $us): mixed => usleep($us))($this->definition->rateLimitMs \* 1000);%%'

# The SIBLING of the case above, and it went unwritten for a day while that one was fixed: the
# hydration sleep gained a seam and a test while this class's own page-walk sleep, thirty lines from
# it, kept a bare `usleep` no test and no case could reach. A fix landing on one of two symmetric
# surfaces is this repo's named recurring defect. The mutation flips the guard rather than deleting
# the call, so it stays valid code and the walk simply never pauses.
# ── C2 round 8 (2026-09-06): §1 from EVERY persisted reading, on the drain too ──────────────────
# Round 7 lifted the group and twin vetoes above the retry/rollup split and left two things behind:
# the row's OWN reading (below the snapshot arm's `continue`, so two arms of one loop answered the
# same row differently) and `excludedDwellings()` — the FOURTH route `Pipeline` reads, and the only
# one that catches a portal re-advertising a flat under a new ad id.

run_sabotage "the drain stops reading the row's own excluded tenure (the snapshot-less arm)" \
  src/php/Rent/Cli/RentScout.php \
  's%if ($tenure === null || $tenure->isExcluded() || $tenure === Tenure::UNKNOWN) {%if (false) {%'

run_sabotage "the drain stops reading the re-advertised-flat veto (§1's fourth persisted route)" \
  src/php/Rent/Cli/RentScout.php \
  's%$dwellingVeto = ExcludedDwellings::match($listing, $excludedDwellings, $dwellingDedup);%$dwellingVeto = null;%'

run_sabotage "a refused retry is counted as drained, so the rent remainder line goes silent" \
  src/php/Rent/Cli/DigestBatch.php \
  's%$drained = $retriesDrained;%$drained = \\count($this->retries);%'

run_sabotage "a refused retry is counted as drained, so the car remainder line goes silent" \
  src/php/Car/Cli/CarScout.php \
  '/if (\$entries === \[\]) {/,/return 0;/ s%\$this->reportRollupRemainder(\$waiting, \$entries, \$drained, false);%$this->reportRollupRemainder($waiting, $entries, count($retries), false);%'

run_sabotage "the page walk stops pausing between pages (a burst against one host, hard rule 5)" \
  src/php/Rent/Adapters/HtmlSource.php \
  's%$this->definition->rateLimitMs > 0%$this->definition->rateLimitMs < 0%'

run_sabotage "a {page} url template is fetched literally, so page one is never real" \
  src/php/Rent/Adapters/HtmlSource.php \
  's%$firstUrl = $urlTemplate ? str_replace(.{page}., .1., $url) : $url;%$firstUrl = $url;%'

run_sabotage "the walk stops substituting {page}, so every page is page one" \
  src/php/Rent/Adapters/HtmlSource.php \
  "s%? str_replace('{page}', (string) \$page, \$url)%? \$url%"

run_sabotage "a detail map may redefine ref, so identity comes from the wrong page" \
  src/php/Rent/Config/FieldMap.php \
  's%if ($map->ref !== \[\]) {%if (false) {%'


# ── Q27: the watcher's liveness signal ────────────────────────────────────────
# Every case here is silent by construction. A heartbeat that stops arriving looks exactly like a
# quiet rental market, which is the observation the whole feature exists to make distinguishable —
# so a regression in it removes the ONE guard against a dead watcher going unnoticed for days.

run_sabotage "the cold start stops beating (a watcher that dies in hour one is invisible for a day)" \
  src/php/Core/Heartbeat.php \
  's%if ($lastSentIso === null || trim($lastSentIso) === %if (false \&\& %'

run_sabotage "the heartbeat marker is never written (so every restart beats)" \
  src/php/Rent/Cli/RentScout.php \
  's%@file_put_contents($this->stateFile(.rent-heartbeat.txt.), $now%@file_put_contents("/dev/null", $now%'

run_sabotage "an unreadable heartbeat marker suppresses the beat instead of forcing one" \
  src/php/Core/Heartbeat.php \
  's%if ($last === null || $now === null) {%if (false) {%'

run_sabotage "a marker in the future suppresses liveness until the clock catches up" \
  src/php/Core/Heartbeat.php \
  's%if ($last > $now) {%if (false) {%'

run_sabotage "RENT_HEARTBEAT_HOURS=0 silently disables liveness instead of refusing" \
  src/php/Core/Heartbeat.php \
  's%if ($intervalHours < 1) {%if (false) {%'

# Retargeted in round 4: the read was split into `pendingRefusal()` (non-consuming, for `doctor`)
# and `clearLastRefusal()` (consuming, and only once a beat has DELIVERED), so the unlink moved
# and named its own path.
run_sabotage "the previous startup refusal is never cleared (reported forever)" \
  src/php/Rent/Cli/RentScout.php \
  "s%@unlink(\\\$this->stateFile('rent-last-refusal.txt'));%%"

run_sabotage "a startup refusal is written to disk unredacted (a credential leak)" \
  src/php/Rent/Cli/RentScout.php \
  's%Redact::text($text)%$text%'

# The beat's health figure counts what the run WATCHES. Reverting it to every enabled source is the
# bug this replaced, and it is invisible from inside: the number is plausible, the watcher is
# healthy, and the line simply reports faults that do not exist. Observed on a real container as
# "1/5 source(s) en bon état" while one source was deliberately scoped and four were never polled.
run_sabotage "the beat counts CONFIGURED sources again (a scoped watcher invents faults)" \
  src/php/Rent/Cli/RentScout.php \
  "s%count(\$watched) . ' source(s) en bon état';%count(\$this->sourceNames()) . ' source(s) en bon état';%"

# And the other direction, which is why the fix is not just "count fewer". Drop the disclosure and a
# deployment carrying a forgotten `--source` reports a flawless 1/1 for ever while the landlords it
# is not watching go unwatched. The beat is what reaches the phone; the startup banner is a log line
# read once.
run_sabotage "the beat stops disclosing that it is scoped (a forgotten --source looks perfect)" \
  src/php/Rent/Cli/RentScout.php \
  's%if ($configured !== \\count($watched)) {%if (false) {%'

# The in-loop beat — the one that fires on day two — lives inside a closure, so an argument that is
# not captured is `null` at the call site and the watcher dies on its first due beat, 24 hours into
# an unattended run. This is not hypothetical: it was written that way, and every existing heartbeat
# test missed it because the fixed clock makes the in-loop beat unreachable. The case that catches
# it had to reach that call site deliberately.
run_sabotage "the in-loop beat loses an argument to the closure boundary (dies on day two)" \
  src/php/Rent/Cli/RentScout.php \
  's%$heartbeat, $digestSchedule, $digestZone, $watched%$digestSchedule, $digestZone, $watched%'

# ── Q34: the daily digest floor ───────────────────────────────────────────────
# The bin this drains is §1's ONLY landing zone — every listing the classifier could not resolve
# confidently. Both other emission paths are event-driven (end of a pass that produced new entries,
# or a human typing `scout --domain=rent digest`), so before the floor existed a backlog that failed to send simply
# sat there. Every case below is silent in the same way the Q27 ones are: the bin quietly stops
# draining, and an operator seeing no rollup reads it as "nothing to check" — which is exactly what
# the ruled empty-day behaviour also looks like.

# THE ZONE. Not because PHP ignores `TZ` in the app — `bin/scout:44` sets the default from it, and
# an earlier version of this comment claimed otherwise from a `php -r` measurement that never read
# the entrypoint. What breaks here is narrower and real: the window is computed against whatever
# offset the incoming instant carries rather than the CONFIGURED zone, so the floor's hour drifts
# with the caller's clock instead of meaning 08:00 where the operator lives.
run_sabotage "the floor computes its window in the instant's own offset, not the configured zone" \
  src/php/Rent/Core/DigestSchedule.php \
  's%$local = $now->setTimezone($zone);%$local = $now;%'

# And the resolution itself: reverting this makes every deployment run on the default zone whatever
# `TZ` says, which is silent — a watcher in another zone simply emits at the wrong hour for ever.
run_sabotage "TZ is ignored entirely and everything runs in the default zone" \
  src/php/Rent/Core/DigestSchedule.php \
  's%if ($raw === null || trim($raw) === %if (true || trim($raw) === %'

# THE MARKER is what makes this a floor rather than a second pipeline. Without its write the bin
# drains on EVERY pass — once per Q37 cadence, all day.
run_sabotage "the digest floor never records its window (drains every pass, not daily)" \
  src/php/Rent/Cli/RentScout.php \
  's%@file_put_contents($this->stateFile(.rent-digest.txt.), $now%@file_put_contents("/dev/null", $now%'

run_sabotage "a marker in the future suppresses the floor until the clock catches up" \
  src/php/Rent/Core/DigestSchedule.php \
  's%if (self::isAfter($last, $now)) {%if (false) {%'

run_sabotage "an unreadable digest marker suppresses the floor instead of forcing one" \
  src/php/Rent/Core/DigestSchedule.php \
  's%if ($now === null || $last === null) {%if (false) {%'

run_sabotage "the cold start stops draining (a backlog surviving a restart is never rescued)" \
  src/php/Rent/Core/DigestSchedule.php \
  's%if ($lastEmittedIso === null || trim($lastEmittedIso) === %if (false \&\& %'

# THE EMPTY-DAY RULING. Removing the early return makes the floor emit a rollup with nothing in it
# every day — a second scheduled push saying nothing the heartbeat did not, and the fastest way to
# train its reader to swipe the channel away. (The expression also bites the `scout --domain=rent digest` copy of
# the same guard, which is the point of them sharing one collector: neither may drift alone.)
run_sabotage "an empty digest bin is announced anyway (a daily push with nothing in it)" \
  src/php/Rent/Cli/RentScout.php \
  's%if ($batch->isEmpty()) {%if (false) {%'

# THE FAILED-SEND ASYMMETRY: marking before delivery consumes the day's floor with nothing having
# reached anyone, and these entries have no other route to the developer.
# SCOPED to floorDigest(). Unscoped, this sed hit FOUR guards — the digest verb, pushRetries(), the
# reclassify promotion and this one — so both this case and its on-demand twin below passed on the
# OTHER three while the floor's own guard was covered by nothing at all (C2 round 2, P2). Mutating
# line 2580 alone left the entire suite green until `testARefusedFloorEmissionMarksNothingAnd
# WritesNoMarker` was written. The ledger's own convention already knew the fix: :2056 and :2088
# scope the identical pattern the same way.
run_sabotage "the DAILY FLOOR marks its digest entries before the channel confirms (the deployed drain)" \
  src/php/Rent/Cli/RentScout.php \
  '/private function floorDigest/,/^    }/ s%if (!\$notifier->delivered(\$failures)) {%if (false) {%'

run_sabotage "the digest floor is never checked at all (the bin only ever drains by hand)" \
  src/php/Rent/Cli/RentScout.php \
  's%$digestSchedule->isDue(%false \&\& $digestSchedule->isDue(%'

# THE IN-LOOP CALL SITE, uniquely — the same trap the beat fell into and for the same reason: under
# a fixed clock the startup emission writes the marker and every later check is false, so this call
# site is unreachable by any ordinary test. Dropping the schedule from the closure's `use` list
# leaves it null INSIDE the loop while the startup check keeps working, so only a test that forces a
# second due-check can see it — which is what the unwritable-marker case does.
run_sabotage "the floor loses its schedule to the closure boundary (dies on the first due day)" \
  src/php/Rent/Cli/RentScout.php \
  's%$heartbeat, $digestSchedule, $digestZone, $watched%$heartbeat, $watched%'

# A snapshot-less row is a LIVE SOURCE FAULT — a listing whose payload could not be JSON-encoded, not
# an old row (the query filters on `outcome`, a v7 column that is not backfilled, so a pre-v7 row is
# never returned at all). Draining it silently loses the one signal that says a source is emitting
# payloads nothing can encode.
run_sabotage "the floor drains snapshot-less rows without naming the source fault" \
  src/php/Rent/Cli/RentScout.php \
  's%if ($batch->withoutSnapshot > 0) {%if (false) {%'

# `doctor` promising a daily floor to a cron-driven `--once` deployment is the hard-rule-2 shape that
# line has already been rewritten twice to stop repeating, one scope narrower.
run_sabotage "doctor stops saying the floor is --watch only (an --once deployment reads a promise)" \
  src/php/Rent/Cli/RentScout.php \
  's%h en `--watch` (Q34) %h (Q34) %'

# ── region mode (2026-08-22) ─────────────────────────────────────────────────────────────────────
# `communes: []` means "the postcode prefixes are the whole location filter". It is the first
# LOOSENING this config has taken, so all four cases below are about the two directions it can fail
# in, and both are silent.
#
# Dead: the filter rejects everything, which is indistinguishable from a quiet rental market — the
# exact shape hard rule 2 exists for, arriving through config rather than a selector.
run_sabotage "region mode reverts to matching nothing (reads as a quiet market for ever)" \
  src/php/Rent/Config/Criteria.php \
  's%return $this->postcodeMatchesPrefix($postcode);%return false;%'

# Open, direction one: the prefix check is skipped along with the name check, so `communes: []`
# quietly becomes "anywhere in France". Over-matching does not look broken — it looks busy. Same
# line as the case above, mutated the other way, because that one line IS the region-mode decision.
run_sabotage "region mode stops checking the postcode prefix (anywhere in France matches)" \
  src/php/Rent/Config/Criteria.php \
  's%return $this->postcodeMatchesPrefix($postcode);%return true;%'

# Open, direction two: the loader stops refusing the one combination that has no filter left at all.
# The refusal is what makes the loosening safe, so it is load-bearing, not defensive decoration.
run_sabotage "the empty-communes + empty-prefixes refusal is removed (config fails open)" \
  src/php/Rent/Config/ConfigLoader.php \
  's%if ($communes === \[\] && $prefixes === \[\]) {%if (false) {%'

# And the regression region mode actually caused, which no test of region mode itself would find:
# `communeLabels` is the vocabulary EmailAlertSource reads a commune out of an alert body with.
# Built from `communes` alone it is empty in region mode, and every emailed listing silently loses
# its commune — no S1 score, nothing to name in the notification, a weaker dedup key — while still
# matching on its postcode, so nothing looks wrong.
run_sabotage "a ranked commune stops being alert-parser vocabulary (emails lose their commune)" \
  src/php/Rent/Config/ConfigLoader.php \
  's%$communeLabels\[$key\] ??= $label;%%'

# ── `--source=<name>` force-run, and the demo source that must not ship enabled (2026-08-22) ──────
#
# Every one of these four is silent in the direction that matters. A demo source running in a real
# deployment does not error: it adds ten flats that do not exist to the store, the pass totals, the
# `doctor` table and the heartbeat's source count, and it looks exactly like a landlord with a small
# portfolio. It shipped that way for two weeks.

# Direction one: the force-run is removed, so `--source=<name>` silently runs NOTHING for a disabled
# source. The failure mode is the one this repo already names — a debugging flag that reports a
# clean, fast, empty pass — arriving now on the onboarding path `/add-source` step 5 prescribes.
run_sabotage "--source stops force-running a disabled source (named source runs nothing)" \
  src/php/Rent/Cli/RentScout.php \
  's%if ($only === null) {%if (true) {%'

# Direction two, and the over-correction: the enabled check goes away entirely, so every disabled
# source runs on every ordinary pass — including the placeholder blocks kept `enabled: false`
# precisely because nobody has verified their endpoint (hard rule 1).
run_sabotage "the enabled check is dropped, so every disabled source runs unasked" \
  src/php/Rent/Cli/RentScout.php \
  's%if (!$definition->enabled) {%if (false) {%'

# Hard rule 1 at the funnel. Force-running brought REMPLACER back within reach of a real fetch, so
# the refusal is what makes force-running safe rather than defensive decoration.
run_sabotage "a REMPLACER endpoint is polled instead of refused (hard rule 1 at the funnel)" \
  src/php/Rent/Cli/RentScout.php \
  's%if ($definition->url === ConfigLoader::UNVERIFIED_URL) {%if (false) {%'

# And the config itself. This is the state the tree was actually in, so the case is a regression
# test for a shipped defect rather than a hypothetical: the guard must notice the flag going back.
run_sabotage "the demo fixture ships enabled again (fake listings in a real deployment)" \
  config/rent/sources.json \
  '/"fixture_demo"/,/^    },/ s%"enabled": false%"enabled": true%'

# ── `.env` is parsed, never executed (2026-08-22) ──────────────────────────────────────────────────
#
# The bug that produced this class was silent in the worst way: the CLI reported "SMTP_PASSWORD is
# empty" while the file plainly contained one, because bash had read `KEY=a b c` as a one-command
# prefix and never exported it -- and had EXECUTED `b c`, printing part of a live credential.

# Precedence. `RENT_SCOUT_DB=/tmp/throwaway bin/scout --domain=rent run` is how a live source is measured without
# touching the real seen-set; a file that could override the environment would silently point that
# at the real database, and the run would look completely normal.
run_sabotage "the file overrides the real environment (a throwaway run hits the real store)" \
  src/php/Config/DotEnv.php \
  's%if (self::alreadySet($name)) {%if (false) {%'

# A malformed line stops being a refusal. The line is then skipped in silence, so a fat-fingered
# credential means a channel that is simply absent, with nothing said about why.
run_sabotage "a malformed .env line is skipped instead of refused" \
  src/php/Config/DotEnv.php \
  's%throw self::malformed($index + 1);%continue;%'

# Quote stripping. Quotes are the only way to express a value with meaningful trailing whitespace,
# so keeping them makes every quoted password wrong by exactly two characters -- an authentication
# failure that looks like a wrong password rather than a parser bug.
run_sabotage "wrapping quotes are kept, so every quoted secret is wrong by two characters" \
  src/php/Config/DotEnv.php \
  's%return substr($value, 1, -1);%return $value;%'

# ── the notification's facts line (2026-08-22) ─────────────────────────────────────────────────────
#
# This is the product's ONLY user-facing surface -- there is no web UI by design -- so a defect here
# is not cosmetic: it is the whole output. All three below are hard rule 9 at the display layer,
# where `null` and `0` and `false` are three different facts about a building.

# `floor === 0` is RDC and REAL. Read as falsy it vanishes, which is the display twin of rejecting a
# listing for not stating a floor.
run_sabotage "the ground floor is treated as no floor at all (RDC vanishes)" \
  src/php/Rent/Notify/Formatter.php \
  's%if ($listing->floor !== null) {%if (!empty($listing->floor)) {%'

# An UNMENTIONED lift becomes an absent one -- the notification asserting something no source said,
# about the one feature that makes a 5th-floor flat unlivable for some people.
run_sabotage "a lift nobody mentioned is reported as absent (a fact the tool invented)" \
  src/php/Rent/Notify/Formatter.php \
  's%if ($listing->hasElevator !== null) {%if (true) {%'

# ── the context line reaching the DIGEST and the ROLLUP (2026-09-09) ─────────────────────────────
#
# Until this ruling `factsLine()` had one call site, so at `push_min_score: 55` the line travelled on
# about one match in ten. The two bins below carry most of the volume, and both render through ONE
# helper on purpose: two byte-identical loops is `a fix landing on one of two symmetric surfaces`
# waiting to be committed, and on a display line both halves would keep printing something plausible.
#
# Each expression is scoped to text that exists only in `digestLine()`. The `floor`/`hasElevator`
# cases above target `contextBits()`, which the push and both bins now share -- so those two guard
# all three surfaces at once and are NOT duplicated here.

# THE WIDENING ITSELF. Delete the context clause and every digest and rollup entry falls back to a
# headline and a reason -- the pre-ruling output, which no title, count or kind assertion can see.
run_sabotage "the digest entry line loses the floor, the lift and the amenities again" \
  src/php/Rent/Notify/Formatter.php \
  "s%(\\\$context === \[\] ? '' : ' — ' . implode(' · ', \\\$context))%''%"

# THE COUNTERWEIGHT, failing the other way, and it is the half a display feature always risks
# losing. Appended unconditionally, an ad that said nothing gets a dangling ` — ` -- an absence of
# information announced as though it were information. Most stored rows state no floor and five of
# the eight sources carry no prose, so this is the COMMON row, not an edge case.
run_sabotage "a digest entry whose ad said nothing gains a dangling separator" \
  src/php/Rent/Notify/Formatter.php \
  "s%(\\\$context === \[\] ? '' : ' — ' . implode(' · ', \\\$context))%' — ' . implode(' · ', \\\$context)%"

# THE DEPARTEMENT IS THE HALF THAT MUST NOT TRAVEL. Calling `factsLine()` here is the tidy-looking
# change someone makes to remove an apparent duplication, and it restates the postcode the headline
# already prints two fields to its left on 61-74% of digest rows.
run_sabotage "the departement is restated on the digest line beside the postcode" \
  src/php/Rent/Notify/Formatter.php \
  's%$context = $this->contextBits(\(.*\));%$context = explode(" · ", (string) $this->factsLine(\1));%'

# The postcode leaves the headline. On In'li -- 54 of 83 matches, and it ships NO title -- the
# headline is the only text in the notification, so this returns it to a bare commune and a price.
run_sabotage "the postcode is dropped from the headline (ambiguous commune, no title)" \
  src/php/Rent/Notify/Formatter.php \
  "s%\$where = \$where === '' ? \$postcode : \$where . ' ' . \$postcode;%%"

# ── Detail hydration, schema v5 (2026-08-23) ─────────────────────────────────────────────────────
#
# Every failure here is silent by construction. A cache that stops being read is a crawl of somebody
# else's site, visible only in their access log. A budget that stops being enforced is the same
# thing wearing a retry for a costume. A failure that stops being recorded is the swallow hard rule
# 3 names, and it presents as a listing that merely has no title.

run_sabotage "a hydrated page is re-fetched every pass anyway (the crawl)" \
  src/php/Rent/Adapters/DetailHydrator.php \
  "s%if (\$cached !== null \&\& \$cached->fields !== null) {%if (false) {%"

run_sabotage "the per-pass hydration budget stops being enforced" \
  src/php/Rent/Adapters/DetailHydrator.php \
  "s%if (\$spent >= \$budget) {%if (false) {%"

run_sabotage "a failed detail fetch is swallowed and never recorded" \
  src/php/Rent/Adapters/DetailHydrator.php \
  "s%\$this->store->recordDetailFailure(%(static fn (...\$a) => null)(%"

run_sabotage "the detail cache key loses its source, so two landlords share a row" \
  src/php/Rent/Store/Store.php \
  "s%FROM listing_detail WHERE source = :source AND external_id = :id%FROM listing_detail WHERE external_id = :id OR :source IS NULL%"

run_sabotage "a failed detail page is retried on every pass, for ever" \
  src/php/Rent/Adapters/DetailHydrator.php \
  "s%if (\$cached->attempts >= self::DETAIL_ATTEMPT_CAP) {%if (false) {%"

run_sabotage "hydration priority is inverted, so the worst candidate takes the slot" \
  src/php/Rent/Adapters/DetailHydrator.php \
  's%return $owed;%return array_reverse($owed, true);%'

# ── the letterless fast path, and the one thing that makes it safe ────────────────────────────────
#
# The skip rests on "no vocabulary literal is letterless", and it is applied AFTER decoding. Both
# halves are sabotaged here because both are silent when wrong: a letterless literal makes the skip
# stop scanning a surface it is supposed to scan, and testing the RAW string instead of the decoded
# one skips `&#80;&#76;&#65;&#73;` — which is `PLAI` — on nothing worse than a source that
# numeric-entity-encodes its own payload. The two placements are indistinguishable in review.

run_sabotage "the letterless skip reads the RAW value, so an entity-encoded PLAI is never scanned" \
  src/php/Rent/Core/TenureClassifier.php \
  "s|if (!\\\$this->matches('/\\\\p{L}/u', \\\$folded)) {|if (!\\\$this->matches('/\\\\p{L}/u', (string) \\\$value)) {|"

run_sabotage "a letterless literal enters the vocabulary, making the skip unsound" \
  src/php/Rent/Core/TenureClassifier.php \
  "s|    private const array PROCEDURAL = \\[|    private const array PROCEDURAL = ['2026' => Tenure::SOCIAL,|"

# ── Schema v7: the evidence a verdict was formed from, and the outcome it was judged to ──────────
# Every case here is silent by construction, and they share one shape: the damage is not visible
# when it is done, only when `scout --domain=rent reclassify` runs months later on evidence that quietly shrank.
#
# The invariant under all of them is `reclassify runs on evidence ⊇ original, never ⊂`. A card whose
# field says PLS while its title says `logement intermédiaire` classifies UNKNOWN today BY CONFLICT;
# re-run on the title alone it becomes a MATCH. So a snapshot that loses a field does not make a
# worse improvement — it manufactures the one outcome §1 forbids, and it does it to the listings
# most likely to be social, because those are the ones whose evidence conflicts.

# The three falsy-but-real values of hard rule 9, one case each. All three decode to something a
# careless reader calls "empty", and all three mean something specific.
run_sabotage "a rez-de-chaussee floor decodes as an unknown floor" \
  src/php/Rent/Core/ListingSnapshot.php \
  "s%floor: self::nullableInt(\$data\['floor'\] ?? null),%floor: self::nullableInt(\$data['floor'] ?? null) ?: null,%"

# `false` is "there is no lift" and only the explicit false may drive the high-floor penalty.
# Decoded as null it becomes "the listing did not mention one", and the penalty silently stops
# firing — a scoring change nobody ordered, visible only as listings ranking slightly too high.
run_sabotage "an explicit 'no lift' decodes as never mentioned" \
  src/php/Rent/Core/ListingSnapshot.php \
  's%return is_bool($value) ? $value : null;%return is_bool($value) \&\& $value ? $value : null;%'

# `detailRead` is what the fail-closed rule reads to decide whether weak evidence on a mixed source
# digests or matches. Lost, every hydrated listing re-judges as unread — which is the SAFE
# direction, and still wrong: it silently re-digests listings whose pages were read and found clean.
run_sabotage "a hydrated listing is snapshotted as though its page was never read" \
  src/php/Rent/Core/ListingSnapshot.php \
  "s%'detailRead' => \$listing->detailRead,%'detailRead' => false,%"

# THE GUARD ON THE GUARD. A field added to RawListing and forgotten in the encoder is dropped from
# every snapshot written afterwards, silently, because decode still succeeds and the gap reads as
# "the source did not say". The reflection test is the only thing standing between that and a
# reclassify running on less than the original — this proves it actually fires.
run_sabotage "a field silently leaves the snapshot (the reflection guard must catch it)" \
  src/php/Rent/Core/ListingSnapshot.php \
  "s%'commune' => \$listing->commune,%%"

# The verdict and its evidence are written in ONE statement precisely so they cannot diverge.
# Storing the verdict alone leaves every row unreclassifiable, and nothing says so until months
# later when reclassify skips the lot.
run_sabotage "a verdict is stored with no evidence beside it" \
  src/php/Rent/Store/Store.php \
  "s%'evidence' => \$snapshot,%'evidence' => null,%"

# Hard rule 3, in the place it costs most. A corrupt snapshot degraded to `null` is indistinguishable
# from a pre-v7 row that never had one — so reclassify would skip it as "never captured" instead of
# reporting a database that is losing data.
run_sabotage "a corrupt snapshot degrades to nothing instead of being refused" \
  src/php/Rent/Store/Store.php \
  's%return ListingSnapshot::decode($json);%try { return ListingSnapshot::decode($json); } catch (\\Throwable) { return null; }%'

# `pendingDigest()` becoming "everything not yet notified" would surface listings the criteria
# REJECTED — into the one channel §1 uses as its landing zone, which is the worst possible place for
# a rejected listing to reappear looking merely doubtful.
run_sabotage "the pending digest stops filtering on the outcome" \
  src/php/Rent/Store/Store.php \
  "s%WHERE outcome = 'DIGEST' AND notified_at IS NULL%WHERE notified_at IS NULL%"

# And its mirror: forgetting delivery makes `scout --domain=rent digest` repeat its whole contents every time,
# which is the alert fatigue Q34 exists to prevent. A digest the developer has learned to skip costs
# the fail-closed rule its only landing zone just as surely as never sending one.
run_sabotage "the pending digest forgets an entry was already delivered" \
  src/php/Rent/Store/Store.php \
  "s%WHERE outcome = 'DIGEST' AND notified_at IS NULL%WHERE outcome = 'DIGEST'%"

# THE PLACEMENT, not the call. `recordOutcome()` runs before the REJECT and DIGEST branches, both of
# which `continue` — moved inside the digest branch it would still look right in review, and a
# listing promoted DIGEST -> MATCH would keep its stale DIGEST for ever while `scout --domain=rent digest` went on
# announcing as doubtful something already notified as a match.
run_sabotage "the judged outcome is never recorded" \
  src/php/Rent/Cli/Pipeline.php \
  's%$this->store->recordOutcome($sighting->dedupKey, $verdict->outcome->value);%%'

# ── `scout --domain=rent digest` on demand, Q34's other half (2026-08-23) ───────────────────────────────────────
# This command's whole reason to exist is a listing the pipeline's retry cannot reach: an entry
# judged doubtful, undelivered, and then delisted, so no later pass ever re-offers it. Every failure
# below leaves that listing exactly where it was — unannounced, with nothing anywhere saying so.

# THE ONE THAT MATTERS. An evidence-less row in the standing backlog is a listing whose own payload
# could not be encoded — a live source fault, not an old row. A digest that skips those skips
# precisely what it was ruled to rescue, and reports "aucune annonce en attente" while doing it,
# which is the silent failure this whole command is the fix for.
#
# **This comment used to say those rows "predate schema v7", and that is impossible** —
# `pendingDigest()` filters on `outcome`, itself a v7 column that is not backfilled, so a genuine
# pre-v7 row has `outcome = NULL` and is never returned at all. The premise was refuted in round 1
# and corrected in `Scout.php`, then in `CLAUDE.md` and `RunResult.php` in round 4; this THIRD copy
# survived to round 5. It matters most here of all three: this is where a future session reads to
# decide whether a red case should be retargeted or deleted, and believing it would lead to
# widening `pendingDigest()` to reach pre-v7 rows — a §1 risk that was explicitly refused, since
# nothing stored distinguishes a pre-v7 digest from a pre-v7 rejection.
run_sabotage "the on-demand digest skips the very rows it exists to rescue" \
  src/php/Rent/Cli/RentScout.php \
  's%\$listing ??= new RawListing(%if (\$listing === null) { continue; }\n            \$listing ??= new RawListing(%'

# Marking before the channel confirms consumes the batch permanently on a failed send. A digest
# entry, unlike a match, has no second chance from anywhere: nothing else will ever surface it.
# SCOPED to the digest VERB — see the floor's note above; the same sed hit four guards.
run_sabotage "the on-demand digest marks its entries before the channel confirms" \
  src/php/Rent/Cli/RentScout.php \
  '/private function digest(/,/private function pushRetries/ s%if (!\$notifier->delivered(\$failures)) {%if (false) {%'

# The count is what tells the reader a backlog was announced WITHOUT its full detail. Removed, a set
# of degraded entries is indistinguishable from a set of sources that publish nothing but titles.
run_sabotage "the degraded-row count stops being reported" \
  src/php/Rent/Cli/RentScout.php \
  's%if (\$withoutSnapshot > 0) {%if (false) {%'

# Hard rule 3, in this command's own shape. A corrupt snapshot swallowed silently means a database
# losing data reads as a quiet one — and the entry would still be announced, so nothing looks wrong.
run_sabotage "an unreadable snapshot is swallowed instead of counted" \
  src/php/Rent/Cli/RentScout.php \
  's%++\$unreadable;%%'

# A placeholder outranking a stated fact. `commune inconnue` is a label for the ABSENCE of
# information; printed while a real title sits unused it turns every rescued row into an entry
# nobody can identify — the display twin of the miss the command was built to fix.
run_sabotage "an unlocated listing loses its title to the placeholder" \
  src/php/Rent/Notify/Formatter.php \
  "s%\$where = \$where === '' ? trim(\$listing->title) : \$where;%%"

# ── `scout --domain=rent reclassify`, Q35 — the §1 surface of the two (2026-08-23) ──────────────────────────────
# Every case here ends in a social listing being announced as a match, which is the one outcome this
# project exists to prevent. None of them is visible at the moment of damage: the command reports a
# promotion, the promotion looks like the recovered miss Q35 promised, and only an application to a
# flat the user is not eligible for reveals it.

# THE CROWN JEWEL. `reclassify runs on evidence ⊇ original, never ⊂`. A card whose structured field
# says PLS while its title says `logement intermédiaire` classifies UNKNOWN today BY CONFLICT; judged
# on the title alone it becomes a MATCH. A row with no snapshot has exactly that shape — the field is
# gone, the title is not — so degrading instead of skipping manufactures the breach, preferentially
# on the listings most likely to be social, because those are the ones whose evidence conflicts.
run_sabotage "an evidence-less row is judged on its title instead of being skipped" \
  src/php/Rent/Cli/RentScout.php \
  's%if (\$evidence === null) {%if (false) {%'

# The same hole reached from the other side: the skip stops being reported. The rows are still not
# judged, so nothing is unsafe — but a backlog silently unexamined is a backlog nobody knows to fix,
# and `reclassify` reporting "0 promotions" over a thousand skipped rows reads as a classifier with
# nothing left to find.
run_sabotage "the evidence-less skip count stops being reported" \
  src/php/Rent/Cli/RentScout.php \
  's%if (\$skipped > 0) {%if (false) {%'

# Notifying on any transition, not just DIGEST -> MATCH. Under §1 the interesting direction is the
# one that ANNOUNCES: a row demoted to REJECT pushed as a match is a rejected listing arriving in the
# match channel wearing a match's formatting.
run_sabotage "reclassify announces transitions that are not promotions" \
  src/php/Rent/Cli/RentScout.php \
  "s%if (\$after === 'MATCH' \&\& \$before !== 'MATCH') {%if (\$after !== \$before) {%"

# Marking before the channel confirms. A promotion is the ONE announcement this listing will ever
# get — it was already carried in a digest, so nothing else will surface it again.
# RETARGETED 2026-08-24. This used to flip `if ($notifier->delivered($failures))` to `if (true)`,
# and a review panel showed it was UNDETECTED — the guarantee it names had no test at all. The line
# it targeted no longer exists either: the write itself now happens after delivery, not just the
# mark, because writing the verdict first removed the row from `staleVerdicts()` AND from
# `pendingDigest()` at once, leaving a MATCH nobody was told about that no command could reach.
# Short-circuiting the failure branch makes every promotion write regardless of the channel.
run_sabotage "a promotion is written to the store before the channel confirms" \
  src/php/Rent/Cli/RentScout.php \
  's%^            if (!\$notifier->delivered(\$failures)) {$%            if (false) {%'

# And the ordering that makes the refusal cheap. Building the notifier AFTER the loop means a deploy
# whose RENT_NTFY_TOPIC is not yet set re-judges everything, then refuses — consuming the whole promotable
# backlog in one run while printing a message about an environment variable.
run_sabotage "the notifier is not checked until after every row has been re-judged" \
  src/php/Rent/Cli/RentScout.php \
  's%^            \$fatal = \$notifier->fatalProblem();$%            \$fatal = null;%'

# A row the criteria engine never judged is a dedup MEMBER, and `NULL` outcome is what distinguishes
# "never judged" from "judged and rejected". Manufacturing an outcome for it destroys that
# distinction permanently, and the next reclassify then treats a member as a survivor.
run_sabotage "an unjudged dedup member is given a manufactured outcome" \
  src/php/Rent/Cli/RentScout.php \
  's%if (\$before === null) {%if (false) {%'

# The fail-closed profile for a source that has since been removed from sources.json — the
# forgotten-config failure `SourceProfile`'s own default exists to prevent, rebuilt one layer up.
#
# RETARGETED 2026-08-24, from the `mixedTenure` half to the `defaultTenure` half. A review panel
# found the original UNDETECTED and the reason is worth keeping: with `defaultTenure: null`,
# `mixedTenure` is INERT. It is read in exactly two places, both requiring an ELIGIBLE tenure below
# the 60bp floor; tier 5 is the only sub-floor tier and it needs a non-null default, and every other
# route to eligible-below-floor is forced to UNKNOWN by the conflict rule. So flipping it changes no
# verdict today. The default is the half that bites: a vanished landlord's stock would resolve as
# eligible by omission, at tier 5, on the strength of nothing.
#
# BOTH FIELDS IN ONE EXPRESSION, and that is the finding rather than a convenience. Each half is
# masked by the other: with `mixedTenure: true` a tier-5 default is dragged back to UNKNOWN by the
# fail-closed rule, and with `defaultTenure: null` tier 5 never fires at all, so flipping either
# line ALONE changes no verdict and no single-line sabotage can express the guarantee. That is
# defence in depth working, and it is exactly why the pair has to be sabotaged together — a careless
# "simplify the fallback" edit rewrites the whole constructor call, not one argument of it.
run_sabotage "a vanished source's listings inherit an eligible tenure from nothing" \
  src/php/Rent/Cli/RentScout.php \
  's%^                null,$%                \\Scout\\Rent\\Core\\Tenure::LLI,%; s%^                true,$%                false,%'

# One damaged row voiding the whole run. Loud is right; global is not — this is the blast-radius
# mistake detail hydration already made once, where a single unreadable page stopped every other
# listing being processed.
run_sabotage "one unreadable snapshot voids the entire reclassify run" \
  src/php/Rent/Cli/RentScout.php \
  's%^            } catch (.*InvalidArgumentException \$e) {$%            } catch (\\RuntimeException \$e) {%'

# ── The snapshot's own losslessness, found by a review panel 2026-08-24 ───────────────────────────
# The invariant `evidence ⊇ original` was FALSE for as long as schema v7 existed, and every test
# passed. `decode()` kept a field only `if (is_scalar($item))`, so an array-, null- or object-valued
# field was written by the encoder and dropped by the decoder — and those are exactly the values the
# classifier raises its tier-1 DOUBT on, the doubt being the only thing withholding a match. Driven
# through the real CLI it went UNKNOWN/DIGEST -> LLI/MATCH -> pushed, on a listing whose own field
# named two excluded regimes. The reflection guard could not see it: it checks that every
# CONSTRUCTOR PARAMETER is encoded, and `fields` was — it was the VALUE TYPE inside that nothing
# exercised.
run_sabotage "a non-scalar field value is dropped on the way out of the snapshot" \
  src/php/Rent/Core/ListingSnapshot.php \
  's%\$fields\[(string) \$key\] = is_scalar(\$item) ? (string) \$item : \$item;%if (is_scalar($item)) { $fields[(string) $key] = (string) $item; }%'

# The key alone is evidence: `numeroUnique` and `demandeLogementSocial` are literal PROCEDURAL
# entries, so dropping a key because its VALUE is empty throws away the strongest social
# discriminator the domain offers while looking like tidying.
run_sabotage "a field whose value is null loses its key, and the key was the signal" \
  src/php/Rent/Core/ListingSnapshot.php \
  's%\$fields\[(string) \$key\] = is_scalar(\$item) ? (string) \$item : \$item;%if ($item !== null) { $fields[(string) $key] = is_scalar($item) ? (string) $item : $item; }%'

# The counterweight to both: a decoder that stopped normalising scalars would satisfy the two cases
# above while changing what every real adapter's listing looks like to the classifier.
run_sabotage "an ordinary scalar field stops being normalised to a string" \
  src/php/Rent/Core/ListingSnapshot.php \
  's%\$fields\[(string) \$key\] = is_scalar(\$item) ? (string) \$item : \$item;%$fields[(string) $key] = $item;%'

# Corruption degraded to sparseness. A `fields` that is not a map at all, silently emptied, hands the
# classifier a listing with no structured evidence and no way to tell that from one that had none.
run_sabotage "a non-object fields map is emptied instead of refused" \
  src/php/Rent/Core/ListingSnapshot.php \
  "s%throw new \\\\InvalidArgumentException('listing snapshot has a non-object \`fields\`');%return [];%"

# ── One unreadable listing must not take the pass with it (2026-08-24) ────────────────────────────
# cp1252 under a UTF-8 declaration is an anticipated real input: `Text` refuses it, the classifier
# has a branch that turns it into UNKNOWN naming the encoding, and the store then threw from the
# per-listing loop — which sits OUTSIDE the per-source try/catch. Every later listing in the pass
# went unclassified and unnotified, health stayed green because `recordRun` had already committed
# `ok = 1`, and the offending row was left with `tenure = NULL`, whose documented meaning is
# "stored before schema v3".
run_sabotage "an unencodable listing throws instead of being stored without a snapshot" \
  src/php/Rent/Store/Store.php \
  's%} catch (.*JsonException) {%} catch (\\RuntimeException) {%'

# And the same failure in the criteria engine, which is reached one loop later. `Text::fold()`
# refuses non-UTF-8 rather than degrading it — correct — but that refusal escaping `excludedBy()`
# aborted the judging loop for every listing after the bad one.
#
# It RETHROWS rather than narrowing the caught class, and the obvious mutation is the instructive
# one: `catch (MalformedText)` -> `catch (\RuntimeException)` reads like a narrowing and is a NO-OP,
# because `MalformedText extends \RuntimeException`. It applied cleanly, parsed, changed the file,
# and the suite stayed green — which the ledger reported as `undetected` when nothing was undetected
# at all. A mutation has to actually mutate the behaviour, or its verdict is about the mutation.
run_sabotage "an unfoldable title aborts the judging loop instead of being inconclusive" \
  src/php/Rent/Config/Criteria.php \
  's%} catch (MalformedText) {%} catch (MalformedText $e) { throw $e;%'

# The count is what makes the state visible on the pass that causes it. Without it, a row that can
# never be re-judged is discovered months later from a skip counter, if at all.
run_sabotage "a verdict stored without its snapshot is not reported by the pass" \
  src/php/Rent/Cli/Pipeline.php \
  's%++\$unencodable;%%'

# ── the offline tripwire covers EVERY outbound path, not just the funnel (2026-08-24) ─────────────
# `tests/bootstrap.php` calls SCOUT_OFFLINE the backstop for anything not given a fake, and
# `NtfyChannel` drives libcurl directly, so it never passed CurlHttpClient's guard. Its default
# server is a third party and its topic is a documented secret. A review panel set the flag and
# watched the channel resolve and dial a non-loopback host.
run_sabotage "the ntfy channel escapes the offline tripwire again" \
  src/php/Core/Notify/NtfyChannel.php \
  's%\$refusal = .*refusalForHost.*;%$refusal = null;%'

# The counterweight, and it protects real evidence: a scripted server on 127.0.0.1 is how this
# project proves what only a socket can. Refusing loopback would delete those tests to enforce a
# rule they do not break.
run_sabotage "the offline tripwire starts refusing loopback too" \
  src/php/Core/Offline.php \
  's%|| self::isLoopback(\$target)) {%) {%'

# ── the surfaces the FIRST malformed-text fix missed (round 2, 2026-08-24) ────────────────────────
# A commit titled "one listing nobody can decode must not take the whole pass with it" hardened
# `excludedBy()` and left `communeKey()` unguarded — reached twice per pass, from `rankOf()` inside
# `score()` and from `Dedup::duplicateReason()` inside `cluster()`, neither inside the per-source
# try/catch. `ListingMapper` takes `commune` straight from `Payload::string()`, which validates
# neither UTF-8 nor HTML entities, and accented commune names are ubiquitous in Île-de-France. Both
# leave `source_runs.ok = 1` with a full item count and nothing notified; the Dedup one before
# anything is stored at all. That is a correct rule applied to a subset of its surfaces — the
# failure class CLAUDE.md names — arriving through the fix for that same class.
# ADDRESSED to `communeKey()`'s own catch. The bare `s%} catch (MalformedText) {%` form matched all
# THREE catches in this file — one here and two in `excludedBy()` — so its red came from the
# `excludedBy` tests and said nothing about the guarantee named in the label. A review panel caught
# that on 2026-08-24, in the same round it caught the two tests for this guard passing input they
# never supplied. Both lines of defence were vacuous for the same guarantee, in different ways.
run_sabotage "an unfoldable commune aborts the pass from rankOf and from Dedup" \
  src/php/Rent/Config/Criteria.php \
  '/\$folded = Text::fold(\$raw);/,+1 s%catch (MalformedText)%catch (\\LogicException)%'

# The two-surface split in `excludedBy()`. Folding both in ONE try silently disabled
# `exclude_title_patterns` on a perfectly READABLE title whenever the description was unfoldable —
# and `Text` refuses any undecoded HTML entity, commoner in a scraped payload than cp1252. Measured:
# title `Parking en sous-sol`, description `Belle vue,&nbsp;calme.` stopped being rejected and landed
# in the *à vérifier* channel, which is §1's landing zone.
run_sabotage "a readable title stops being checked when the description will not fold" \
  src/php/Rent/Config/Criteria.php \
  's%        if (\$foldedTitle !== null) {%        if (false) {%'

# ── the other two egress points (round 2, 2026-08-24) ─────────────────────────────────────────────
# `Core\Offline` claimed to refuse "every outbound request" while covering two of FOUR. SMTP and
# IMAP open raw sockets, so neither passed the funnel — and IMAP is the PRIMARY ingestion path under
# hard rule 4, sending a cleartext password to a host read from `.env`.
run_sabotage "the IMAP mailbox escapes the offline tripwire" \
  src/php/Adapters/Mail/ImapMailbox.php \
  's%\$refusal = .*refusalForHost.*;%$refusal = null;%'

run_sabotage "the SMTP transport escapes the offline tripwire" \
  src/php/Core/Notify/SmtpTransport.php \
  's%\$refusal = .*refusalForHost.*;%$refusal = null;%'

# The ntfy refusal names the SERVER because the url ends in the TOPIC, which is the secret. Masking
# it afterwards is a race against every transformation the string undergoes: `Redact` matches
# literals, so `rawurlencode` touching one character defeated it, and it ignores literals under four
# characters, so a short topic leaked too. Both measured.
run_sabotage "the ntfy refusal puts the topic back in the message" \
  src/php/Core/Notify/NtfyChannel.php \
  "s%Offline::refusalForHost(\$this->server, 'the ntfy server')%Offline::refusal(\$url)%"

# The heartbeat must survive a throwing pass. It sat in the same closure as the pass, which
# `WatchLoop` wraps in its own try, so any throw skipped the one signal that says the watcher is
# alive — while a comment two lines up claimed it was "outside its try/catch by construction".
run_sabotage "the heartbeat stops being emitted from a finally" \
  src/php/Rent/Cli/RentScout.php \
  's%                } finally {%                } catch (\\Throwable) {%'

# ── round 3: the beat must not lie, and the config must not widen (2026-08-24) ────────────────────
# Round 2 moved the heartbeat into a `finally` so a throwing pass could not silence it, and the beat
# it then emitted was byte-identical to a healthy startup beat: `++$passes` stays inside the try, so
# `$passes` was 0 and rendered "démarrage de la surveillance", while the health figure read a run log
# the per-source loop had already committed `ok = 1` to. Two review lenses found it independently.
# Silence was the DESIGNED alarm here — Q27's banner says so — and round 2 replaced it with an
# affirmative false-healthy on the one channel the repo says can be believed.
run_sabotage "the beat stops naming a failed pass, so it reads as healthy" \
  src/php/Rent/Cli/RentScout.php \
  's%\$reasons\[\] = \$failedPasses . . passe(s) EN ÉCHEC.*;%%'

# And the counter behind it. Never incremented, the beat has nothing to name.
run_sabotage "a failing pass is not counted, so the beat cannot know" \
  src/php/Rent/Cli/RentScout.php \
  's%                        ++\$failedPasses;%%'

# The loader asymmetry a listing-side fix opened. `communeKey()` returning `''` instead of throwing
# made `commune_rank['']` constructible in REGION MODE, where the "ranked but not in communes" check
# is deliberately skipped — and `rankOf()` has no `''` guard, so every listing with an unfoldable
# commune was awarded that rank. Measured at rank 1: "commune de premier choix", with the raw bytes
# in `reasons[]`. Config is the input that must fail loudly.
run_sabotage "an unnormalisable commune_rank label is accepted, ranking every unreadable commune" \
  src/php/Rent/Config/ConfigLoader.php \
  's%^                if (\$key === .\{2\}) {$%                if (false) {%'

# The audit trail. A row that classified LLI and failed to encode is not SKIPPED by reclassify — it
# is invisible to it, because `staleVerdicts()` selects undetermined verdicts only. `doctor` is the
# one place it can be seen, and schema v7 exists so a verdict can be re-examined at all.
run_sabotage "verdicts with no evidence stop being countable" \
  src/php/Rent/Store/Store.php \
  "s%WHERE tenure IS NOT NULL AND evidence_json IS NULL%WHERE 0%"

# ── console is not a delivery (round 7 P0) ────────────────────────────────────────────────────
#
# `Notifier` and `ConsoleChannel` both said in prose that `console` does not count as a channel;
# the constructor did not implement it. `ConsoleChannel::check()` returns null, so console landed
# in `$usable`, and `delivered()` asked whether fewer channels FAILED than were usable — so one
# print to a container log satisfied `markNotified()`, the 24 h alert cooldown, the heartbeat
# marker and `test-notify`'s exit code. A transient ntfy outage therefore announced a flat to a
# log, wrote `notified_as = 'MATCH'`, and suppressed it permanently once the network returned.
#
# Two independent halves, so each is its own case: the SET console is kept out of, and the
# QUESTION delivered() asks of that set. Either one alone restores the defect.
run_sabotage "console re-enters the set that decides whether a listing was delivered" \
  src/php/Core/Notify/Notifier.php \
  's%static fn (Channel \$c): bool => \$c->reachesRecipient(),%static fn (Channel $c): bool => true,%'

# The arithmetic form is the original. It counts, rather than asking which channel accepted — and
# a count cannot tell a console print from a delivery.
run_sabotage "delivered() goes back to counting channels instead of naming them" \
  src/php/Core/Notify/Notifier.php \
  's%        if (\$this->counting === \[\]) {%        return count($failures) < count($this->usable) \&\& $this->usable !== []; if (false) {%'

# The console-only run is NOT refused at startup — `run --once` at a terminal is exactly that — so
# this warning is the only thing between a misconfigured deployment and a watcher that announces to
# a log for ever while marking nothing notified.
run_sabotage "a run with no remote channel stops saying so" \
  src/php/Rent/Cli/RentScout.php \
  's%        if (\$notifier->hasRemoteChannel()) {%        if (true) {%'

# ── group-scoped suppression is forbidden, on BOTH delivery paths ─────────────────────────────
#
# Recorded ruling: a delivered announcement marks the SURVIVOR, never every clustered member —
# "an over-merge would then hide a real flat permanently and silently". Nothing pinned it until
# round 7, when a reviewer made exactly the forbidden change on each path in turn and the whole
# suite stayed green both times. It is also the likeliest change a future session makes: `--seed`
# twelve lines above marks every member and IS pinned, so the asymmetry reads as an oversight.
run_sabotage "a delivered match marks the whole cluster, silencing an over-merged flat for ever" \
  src/php/Rent/Cli/Pipeline.php \
  "s%\\\$this->store->markNotified(\\\$sighting->dedupKey, \\\$nowIso, 'MATCH');%foreach (\\\$clusterKeys[spl_object_id(\\\$listing)] as \\\$mk) { \\\$this->store->markNotified(\\\$mk, \\\$nowIso, 'MATCH'); }%"

# The digest entry carries `keys` as well as `key`, so the forbidden loop is easier to write here.
run_sabotage "a delivered digest marks every clustered member instead of the entry key" \
  src/php/Rent/Cli/Pipeline.php \
  "s%\\\$this->store->markNotified(\\\$entry\\['key'\\], \\\$nowIso, 'DIGEST');%foreach (\\\$entry['keys'] as \\\$mk) { \\\$this->store->markNotified(\\\$mk, \\\$nowIso, 'DIGEST'); }%"

# ── round 7 P1s: guarantees that were real and pinned by nothing ──────────────────────────────
#
# `Offline` is the single funnel for all five egress points, and each of the five guards IS pinned.
# What nothing asked was whether the predicate underneath them decides correctly. Weakened to a
# substring search, `https://evil.test/?x=127.0.0.1` becomes a permitted request — and the class's
# own docblock says the guarantee is "two putenv lines away from a public POST".
run_sabotage "the offline tripwire searches the url instead of parsing its host" \
  src/php/Core/Offline.php \
  's%return self::isLoopbackHost(\$host);%return true;%'

# An UNFOLDABLE commune is an unknown one, not a shared one. `communeKey()` was changed this session
# to return '' rather than throw, and what made that safe is this clause. The ledger's older Dedup
# case collapses all four clauses at once, so its detection comes from the `null` half and this one
# could rot alone — the compound-rot failure test-sabotage-applies.sh was rewritten to prevent.
run_sabotage "two unfoldable communes are treated as the same commune" \
  src/php/Rent/Core/Dedup.php \
  "s%|| \$communeA === '' %%"

# "A liveness signal that can replace the diagnosis is worse than one that is late." The beat runs
# in the pass's finally, so a throwing beat propagates INSTEAD of the pass's own exception and
# WatchLoop::onError reports the wrong cause on the one channel this repo says can be believed.
run_sabotage "the beat's own failure masks the pass's diagnosis" \
  src/php/Rent/Cli/RentScout.php \
  's%\$this->warn(.battement de cœur non émis.*$%throw \$beatFailure;%'

# The highest-traffic of the three caps — it runs unattended every fifteen minutes — and the only
# one whose remainder could be deleted with the whole suite green. Both siblings assert their line
# by string; this one asserted only the RunResult field.
run_sabotage "the pipeline stops naming the digest remainder to the operator" \
  src/php/Rent/Cli/RentScout.php \
  's%if (\$result->digestOverflow > 0) {%if (false) {%'

# ── round 7 P2s ───────────────────────────────────────────────────────────────────────────────
#
# An unrecognised announcement kind must rank as the STRONGEST, so a value nobody understands
# suppresses rather than re-announces — the quiet direction, in the one place §1 wants it. The
# sibling rule (a pre-v8 NULL reading as MATCH) was pinned; this arm was not. Retargeted 2026-09-05
# when A5 inserted ROLLUP at rank 2 and the default moved to 3 — the applies gate caught it inert.
run_sabotage "an unrecognised announcement kind re-announces instead of staying quiet" \
  src/php/Rent/Store/Store.php \
  "s%            default => 3,%            default => 0,%"

# A denial that FOLLOWS the noun. `Ascenseur : non` is an ordinary French spec-block row and read
# as `true` — a bonus awarded for a lift that does not exist, the direction Prose's own docblock
# forbids. The backward-only window structurally could not see it.
run_sabotage "a lift denial that follows the noun is read as a lift" \
  src/php/Rent/Core/Prose.php \
  's%self::LIFT_TRAILING_WINDOW);%0);%'

# The `au|en` anchor is what keeps a bare COUNT out of the floor even when the noun is singular.
# The ledger pinned only the `\b` on `etage`; made optional, a building's height becomes the
# tenant's floor — the exact defect this reader was written to fix.
run_sabotage "the floor anchor goes optional, so a building height answers for the flat" \
  src/php/Rent/Core/Prose.php \
  's%(?:au|en)\\s+(?:le%(?:au|en)?\\s+(?:le%'

# ── round 8: a delivery is a CAPABILITY, not a name ───────────────────────────────────────────
#
# Round 7 filtered `console` out of the counting set BY NAME. Round 8 found the same hole one door
# along: `email` over SMTP_TRANSPORT=file writes an `.eml` into a directory the container destroys
# on rebuild, is not called `console`, and so voted as a delivery — `test-notify`, the documented
# proof that a deployed image can reach the user, returned 0 for a message that went to a file.
# The property is `Channel::reachesRecipient()` now, on the interface, once.
run_sabotage "a file transport starts counting as a delivered notification" \
  src/php/Core/Notify/FileTransport.php \
  's%^        return false;$%        return true;%'

# The other half of the same rule, and the one round 7 fixed by name.
run_sabotage "the console channel starts counting as a delivered notification" \
  src/php/Core/Notify/ConsoleChannel.php \
  's%        return false;%        return true;%'

# The §1 structural control must GROW WITH THE MODEL. Its expansion guard carried a second clause
# that was always false — `$covered` is built from `surfaces()`, which hard-codes a key called
# `title` — so `$missing` could never be non-empty and a new unread property passed unnoticed.
# This is the src-side mutation the guard exists to catch; it must go red.
run_sabotage "a new listing field reaches no matrix surface and nothing says so" \
  src/php/Rent/Core/RawListing.php \
  "s%        public string \$description = '',%        public string \$description = '', public ?string \$agencyBlurb = null,%"


# ---------------------------------------------------------------- the email-alert path (2026-08-25)
#
# Every case below was written after the FIRST REAL portal alert was run through the parser and
# returned `body len: 0, links: 0`. Zero listings, no exception -- hard rule 3's shape reached
# without a catch, and 1886 green tests said nothing, because the committed email_demo fixtures
# happen to omit every structure a real mailer emits.

# The `''`-is-not-an-answer guard. A blank text/plain alternative claiming the answer means the HTML
# alternative carrying the whole listing is never reached, and the source reports a quiet market.
run_sabotage "an empty MIME part claims the answer again" \
  src/php/Adapters/Mail/EmailMessage.php \
  's%if ($plain === null && $trimmed [^)]*) {%if ($plain === null) {%'

# The structural half. A preamble with a blank line in it parses to a real body -- not empty, not
# the message -- so the skip is RFC 2046, not a check on emptiness.
run_sabotage "the MIME preamble is read as a body part again" \
  src/php/Adapters/Mail/EmailMessage.php \
  's%            if ($index === 0) {%            if (false) {%'

# RFC 2047 §6.2. Collapsing AFTER the decode is collapsing nothing: no `?= =?` sequence survives it.
# The subject becomes a listing's title, which exclude_title_patterns filters on.
run_sabotage "adjacent encoded words are joined after decoding, which is never" \
  src/php/Adapters/Mail/EmailMessage.php \
  's%            $joined,%            $value,%'

# §1 THROUGH A TEXT ENCODING. `logement conventionn&eacute;` folds to `logement conventionn` -- the
# label destroyed, the listing apparently unlabelled. Text::fold refuses entities precisely so this
# cannot pass silently, and the adapter is what must decode them.
run_sabotage "html entities reach the classifier undecoded" \
  src/php/Adapters/Mail/EmailMessage.php \
  's%        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, .UTF-8.);%        return $text;%'

# A rent assembled across a line break reads whatever sat above it. `ref 850` over `1 450 EUR` gives
# 850: inside the plausibility band, six hundred euros low, clearing a ceiling the real rent does not.
run_sabotage "the rent separator admits newlines again" \
  src/php/Rent/Adapters/EmailAlertSource.php \
  "s%(\\\\d\[\\\\d\\\\h.,\]{2,})%(\\\\d[\\\\d\\\\s.,]{2,})%g"

# THE NO-INFORMATION FLOOR. Without it every card whose extraction failed hashes to the same key --
# the store's "nothing collapses onto a shared key" guarantee violated where the store cannot see it.
run_sabotage "the content identity is minted with no locating evidence" \
  src/php/Rent/Adapters/EmailAlertSource.php \
  's%        if ($locating === null || $locating === ..) {%        if (false) {%'

# Rent back in the identity, which turns every price drop into a brand-new listing with no history.
run_sabotage "the rent joins the content identity, so a price cut mints a new flat" \
  src/php/Rent/Adapters/EmailAlertSource.php \
  "s%identityFor(\\\$commune, \\\$postcode, \\\$rooms, \\\$surface, \\\$residence)%identityFor(\\\$commune, \\\$postcode, \\\$rooms, \\\$surface, \\\$residence . '|' . \\\$rent)%"

# Two cards in one message sharing an identity: one is kept, the rest dropped, and the drop is
# ANNOUNCED (ruling 2026-08-26, replacing a throw that took the whole source down for seven passes
# over three indistinguishable coliving rooms). Not detecting the collision at all re-emits the
# duplicate under an identity another card already owns.
run_sabotage "duplicate identities within one message stop being noticed" \
  src/php/Rent/Adapters/EmailAlertSource.php \
  's%            if (isset($seenIds\[$listing->externalId\])) {%            if (false) {%'

# The half the ruling did NOT relax. Dropping a card without a word is exactly the silence the old
# throw existed to prevent, and it is the shape that would make the change a regression rather than
# a fix — a source quietly under-reporting, which is indistinguishable from a quiet market.
run_sabotage "a dropped duplicate card is dropped in silence" \
  src/php/Rent/Adapters/EmailAlertSource.php \
  's%                if ($this->warn !== null) {%                if (false) {%'

# Hard rule 2. A message that plainly carries cards and yields none is a changed template, and it is
# indistinguishable from a quiet market unless it is loud.
run_sabotage "a message full of cards that yields none returns quietly" \
  src/php/Rent/Adapters/EmailAlertSource.php \
  's%        if ($out === \[\] && count($segments) > 1) {%        if (false) {%'

# §1 AT LOAD. Segmenting removes a batch-level regime mention from every card; on a mixed-tenure
# source that is a decision nobody has made against a real payload.
run_sabotage "a segmented mixed-tenure source is allowed to load" \
  src/php/Rent/Config/ConfigLoader.php \
  's%if (\$segmented && \$mixedTenure) {%if (false) {%'

# BOTH SEPARATORS SEGMENT, AND FOR A MONTH ONLY ONE OF THEM WAS GUARDED (C2 round-1 correctness
# lens, 2026-09-02). `card_separator_pattern` is the regex form, honoured by `segments()` IN
# PREFERENCE to the literal, and both §1-adjacent guards read the literal alone -- so either could
# be defeated by writing the same configuration in the other form, with the whole suite and the
# whole ledger green: all 16 occurrences of `card_separator` in tests and here were the literal.
#
# TWO CASES, ONE PER GUARD, and deliberately not one mutation of `$separatorKey` -- that would
# redden both new tests at once, so deleting either test would leave the case still detected. Each
# mutation reverts ONE guard to the literal-only condition, which is the exact regression.
run_sabotage "the mixed-tenure refusal reads the literal separator only, so the regex form loads" \
  src/php/Rent/Config/ConfigLoader.php \
  's%if (\$segmented && \$mixedTenure) {%if ((\$params["card_separator"] ?? "") !== "" \&\& \$mixedTenure) {%'

run_sabotage "the link-host refusal reads the literal separator only, so the regex form skips it" \
  src/php/Rent/Config/ConfigLoader.php \
  's%if (\$segmented$%if ((\$params["card_separator"] ?? "") !== ""%'

# RETIRED 2026-08-25, and retired rather than repaired because the GUARANTEE changed.
#
# This case used to sabotage a blanket rule: "a source segmented by card_separator needs
# `id_from: content`". That rule was true only while link identity meant the whole MESSAGE's links
# -- SeLoger's sixteen cards behind one opaque redirect. The segmented path now keys on the card's
# own last qualifying link, so `link` is a real answer for a portal that publishes listing URLs,
# and Bien'ici is one. Its own comment had said so: "`link` is a real answer for a portal whose
# alerts DO carry listing URLs".
#
# What replaced it is narrower and guards the shape whose failure is SILENT rather than loud, and
# it has its own case below: "a segmented link-keyed source may ship without a link host". The
# expression is deleted rather than left rotting, because a sabotage that matches nothing reports
# coverage it does not have -- which is what tests/test-sabotage-applies.sh exists to catch, and
# what it caught here.

# matchParam() reads these with @preg_match, so a pattern that does not compile never warns and
# never throws -- it returns false and the field is null for ever. On residence_pattern that
# silently narrows the identity floor, since the residence is one of the three facts it accepts.
run_sabotage "an uncompilable email pattern is accepted and never matches anything" \
  src/php/Rent/Config/ConfigLoader.php \
  "s%, '') === false) {%, '') === false \&\& false) {%"

# THE NOTIFICATION LINKED TO THE SAVED SEARCH, NOT THE FLAT. Taking the FIRST qualifying link in a
# card reads whatever furniture the portal put above it -- measured on a live five-card alert, that
# is alert management on card one, a third-party advert on card two and the photo on card three. The
# link is the one thing a push exists to deliver, and a wrong one is not visibly wrong.
run_sabotage "the card links to the portal's furniture instead of the flat" \
  src/php/Rent/Adapters/EmailAlertSource.php \
  's%foreach (array_reverse(self::linksIn($segment)) as $candidate) {%foreach (self::linksIn($segment) as $candidate) {%'

# "RECENT" READ AS THE TAIL OF THE FOLDER. Measured 2026-08-25 on a 1436-message Gmail label:
# SEARCH SINCE matched 124 messages at sequence numbers starting at 6, so the last 50 by sequence
# held NONE of the day's alerts. The source read 0 listings against a 7-day mean of 9 while the
# portal published normally -- a silent outage with a plausible explanation sitting next to it.
run_sabotage "the IMAP window stops being a date query" \
  src/php/Adapters/Mail/ImapMailbox.php \
  "s%'UID SEARCH SINCE ' . \$since%'UID SEARCH ALL'%"

# One mailbox serves every email source, so an unscoped window is a shared budget -- and it already
# held 124 messages against a limit of 50. Drop the per-source sender and a busy portal starves a
# quiet one, silently, worsening with every source added.
run_sabotage "the IMAP search stops being scoped to the source's sender" \
  src/php/Adapters/Mail/ImapMailbox.php \
  "s%\$command .= ' FROM ' . self::quote(\$fromFilter);%\$command .= '';%"

# A zero-day window matches nothing and reads as a quiet market for ever.
run_sabotage "a non-positive IMAP window is obeyed rather than clamped" \
  src/php/Adapters/Mail/ImapMailbox.php \
  's%\$days = max(1, \$sinceDays);%$days = $sinceDays;%'

# THE PRICE-DROP CARD. It quotes the reduction, then the rent, then the old price; only the rent
# carries a period. Without that pattern the reader returned the DISCOUNT -- 100 EUR here, which is
# below the plausibility floor so the card was merely dropped, but a 300 EUR reduction would have
# been returned as a rent that clears a ceiling the flat comes nowhere near.
run_sabotage "a periodic rent no longer outranks a bare figure (the discount wins)" \
  src/php/Rent/Adapters/EmailAlertSource.php \
  "s%'~(\\\\d\[\\\\d\\\\h.,\]{2,})\\\\h\*(?:€|EUR|euros?)\\\\h\*(?:/\\\\h\*mois|par mois|mensuel)~iu',%%"

# And the other half: preg_match stops at the first hit, so one implausible figure hid a perfectly
# readable rent three lines below it.
run_sabotage "only the first rent-shaped figure in a card is considered" \
  src/php/Rent/Adapters/EmailAlertSource.php \
  's%foreach ($matches\[1\] ?? \[\] as $candidate) {%foreach (array_slice($matches[1] ?? [], 0, 1) as $candidate) {%'

# THE COMMUNE READ FROM THE PORTAL'S LAYOUT. Dropping this branch falls back to the ranked-vocabulary
# scan, which on a region-mode config knows almost no commune names -- so every SeLoger listing loses
# its town while its postcode still parses, and the notification cannot say where the flat is. It is
# the shape of failure that has an alibi: `null` reads as "an unranked town", not as "broken".
run_sabotage "the laid-out commune is ignored in favour of the vocabulary scan" \
  src/php/Rent/Adapters/EmailAlertSource.php \
  "s%            return \$this->matchParam('commune_pattern', \$body);%            return null;%"

# The reverse cut, and the one that has an alibi (2026-08-29): a configured pattern that MISSES
# falling back to the vocabulary scan reads as "a listing in an unranked town", never as a broken
# extraction — and restores the prototype's "proche Dourdan" over-match on the way. Every other
# positional reader already yields null on a miss; this one was the last to fall back.
run_sabotage "a configured commune_pattern that misses falls back to the vocabulary scan again" \
  src/php/Rent/Adapters/EmailAlertSource.php \
  "s%            return \$this->matchParam('commune_pattern', \$body);%            if ((\$c = \$this->matchParam('commune_pattern', \$body)) !== null) { return \$c; }%"

# The other direction: the vocabulary fallback deleted. A source with no commune_pattern -- every
# email source that existed before this one -- silently stops naming any commune at all.
run_sabotage "the vocabulary fallback is dropped, so an unconfigured source names no commune" \
  src/php/Rent/Adapters/EmailAlertSource.php \
  "s%            if (\$key !== '' \&\& str_contains(\$folded, \$key)) {%            if (false) {%"

# THE FAIL-CLOSED POSTURE DEFEATED BY A 200. An SPA catch-all answers /robots.txt with its app
# shell; parsed as robots that yields zero directives, which reads as allow-everything. Measured on
# al-in.fr [2026-08-25]: Angular shell, Content-Type text/html, HTTP 200. Both signals get a case,
# because either alone is insufficient -- some catch-alls are labelled text/plain.
run_sabotage "a robots.txt answering html is parsed as permission" \
  src/php/Adapters/Http/RobotsResolver.php \
  "s%'text/html', 'application/xhtml+xml', 'application/json', 'application/xml', 'text/xml'%'x-none/x-none'%"

run_sabotage "a robots body starting with a markup character is trusted" \
  src/php/Adapters/Http/RobotsResolver.php \
  "s%if (\$firstByte === '<' || \$firstByte === '{') {%if (false) {%"

# COLIVING ROOMS ADVERTISED WITH THE WHOLE FLAT'S FIGURES. SeLoger markets a bedroom in a shared
# flat as `Chambre a <quartier>` beside `4 pieces . 90 m2`, and quotes the rent for the ROOM -- so
# the criteria engine reads an extraordinary family flat. Four of nine real listings in the first
# live pass were these, and all four matched. The existing colocation patterns cannot fire:
# neither `coloc` nor `colocation` appears anywhere in them [measured 2026-08-25].
run_sabotage "the coliving-room title pattern is dropped from the criteria" \
  config/rent/criteria.json \
  's%^    "^(?!\.\*.*chambres?.*$%%'

# ── Bien'ici: the second email portal, and the first keyed on a real listing id ──────────────────
#
# THE SEPARATOR. Splitting on the call to action -- which is what SeLoger does -- puts the alert's
# own criteria line (`1 200 EUR max - 3 pieces min - 45 m2 min`) inside segment 0, so the first card
# of every message reports 45 m2. Under min_surface_m2 that is a silent rejection of a real match.
# Measured over four live messages: 3 of 13 surfaces and 1 of 13 room counts wrong, every one of
# them under-reported, which is the direction nothing ever notices.
run_sabotage "the Bien'ici card separator stops matching (every card merges into one listing)" \
  config/rent/sources.json \
  's%(?:Photo|Pas de photo)%(?:ZZZ_NEVER_MATCHES)%'

# Track 5b, F-A. Two guards, and both failures are silent in the way this repo keeps paying for.
#
# The subject filter decides which messages a source reads AT ALL. Rent and car leboncoin share
# sender, link host and card separator; without it a vehicle alert parses as a flat, is rejected for
# having no commune, and still counts toward the housing source's health.
run_sabotage "an email source accepts every subject from its sender (a vehicle alert is ingested as housing)" \
  src/php/Rent/Adapters/EmailAlertSource.php \
  's%if (!\$this->subjectMatches(\$message)) {%if (false) {%'

# And the param guard itself: without it a misspelt key loads cleanly and does nothing, which is the
# PAP failure exactly — pattern absent, generic scan answering, wrong values stored, no fault shown.
run_sabotage "a params key no adapter reads is accepted again (a misspelt pattern silently does nothing)" \
  src/php/Rent/Config/ConfigLoader.php \
  's%\\in_array(\$key, self::EMAIL_ALERT_PARAMS, true)%true%'

# F27b / Track 6-A3: the extraction-miss signal reaches the four rent html/json sources and the
# detail hydrator, through `ListingMapper` — the one funnel every structured extraction passes. It
# shipped on ONE adapter of five, so a silently-null CSS selector or JSON path was exactly as
# invisible as the missed PAP regex that motivated the whole signal.
run_sabotage "a mapped field that extracts nothing is no longer counted as a miss" \
  src/php/Rent/Adapters/ListingMapper.php \
  's%\$value !== null \&\& \$value !== ..%true%'

# The guard that makes the signal USABLE rather than permanent noise: only a CONFIGURED field can
# miss. Remove it and every unmapped field reports a 100 % miss for ever — measured, logirep maps
# no floor and no elevator and its 123 rows are 123/123 null on both, which is exactly the F30
# shape (a signal demanding a field the source structurally does not carry).
run_sabotage "an UNMAPPED field is counted as a miss (every source reports a permanent 100 % on fields nobody mapped)" \
  src/php/Rent/Adapters/ListingMapper.php \
  's%\$this->misses === null || \$paths === \[\]%$this->misses === null%'

# The card map and the detail map must count SEPARATELY. Pool them and a field mapped on both —
# In'li's `cp`, card slug plus detail `<title>` — reports `171/342` when one whole side is dead, and
# `total()` speaks only at 100 %, so the WARN is unreachable. Found on the deployed image's first
# pass, hours after the signal shipped: the seven-day flaky window's dilution, one layer down.
run_sabotage "detail-map misses are pooled with the card map's field of the same name (a dead map reads as half-working)" \
  src/php/Rent/Adapters/DetailHydrator.php \
  "s%\\\$this->patternMisses, 'detail.'%\\\$this->patternMisses%"

# audit N5: `tenure_field` acts on the html path (via `flatMapped()`'s renaming) and did nothing on
# the json path. No verdict changes today — the classifier's unknown-field path already scans any
# value for excluded vocabulary — but the asymmetry is the defect, and this is what keeps it closed.
run_sabotage "a mapped tenure_field stops being declared under the key the classifier knows" \
  src/php/Rent/Adapters/ListingMapper.php \
  "s%\\\$fields\['tenureField'\] = \\\$declared;%%"

# A failure rate that CLIMBS is invisible against a seven-day denominator (Track 6-A1). Measured on
# the live watcher: In'li failed 23 of 100 passes in a day while the seven-day figure read 8.2 %, so
# WARN_FLAKY could not fire and `doctor` said `ok` for four days. No fixture in the ordinary suite
# reaches this branch except the one test written for it — the shape that turns a guarantee into
# dead safety code.
run_sabotage "the short flaky window can never fire (a source degrading today reads as a fine week)" \
  src/php/Core/RunStore.php \
  's%FLAKY_SHORT_FAILURE_RATIO = 0.2%FLAKY_SHORT_FAILURE_RATIO = 0.99%'

# And the window itself: collapse it back onto the long one and the rule still EXISTS, still
# computes, still reads as configured — and detects exactly nothing the long rule did not already.
run_sabotage "the short flaky window is widened to the long one (the dilution it exists to defeat is back)" \
  src/php/Core/RunStore.php \
  's%FLAKY_SHORT_WINDOW_DAYS = 1%FLAKY_SHORT_WINDOW_DAYS = 7%'

# The COUNTERWEIGHT half, and it fails in the opposite direction: a cron-driven `--once` deployment
# polls four times a day, where ONE failure is 25 % and means nothing. Drop the minimum and the
# verdict starts alarming on sources doing nothing wrong — which is how a real signal becomes noise
# the operator learns to ignore.
run_sabotage "the short window's minimum run count is dropped (a sparse deployment alarms on one failure)" \
  src/php/Core/RunStore.php \
  's%MIN_RUNS_FOR_SHORT_FLAKY = 20%MIN_RUNS_FOR_SHORT_FLAKY = 1%'

# The CAR twin of that guard (Track 6-A2). The rent side closed this class on 2026-08-26; the car
# loader refused two keys BY NAME and claimed in its own docblock to have closed it, while every
# misspelling — `link_hosts`, a `commune_pattern` carried over from a rent block — loaded cleanly
# and did nothing. No shipped car config reaches this branch, so nothing but this case and
# `VehicleSourceLoaderRefusalsTest` stands between the guard and a silent deletion.
run_sabotage "a car params key no vehicle adapter reads is accepted again (a misspelt pattern silently does nothing)" \
  src/php/Car/VehicleSourceLoader.php \
  's%\\in_array(\$key, \$read, true)%true%'

# Cityloger writes the unit BOTH ways — `80 m2` on one frozen page, `63m²` on the other — and the
# selector required the ASCII form. Measured on the live store before the fix: 56 of 61 cached
# detail rows held `elevator, description, tenureField` and NO surface, so `min_surface_m2` could
# not act on 93 % of the source (hard rule 9 — unknown is not below the minimum) and one such row
# was notified. The failing fixture was ALREADY COMMITTED; only the passing spelling was asserted.
run_sabotage "the Cityloger surface selector accepts only the ASCII unit (the m² pages read as stating no surface)" \
  config/rent/sources.json \
  's%m\[2[^]]*\]%m2%'

# The identity falls back to the card's own link ONLY on the segmented path. Remove the fallback and
# every Bien'ici card is refused an identity, the zero-cards guard fires, and the source reports a
# template change that has not happened.
run_sabotage "a segmented card can no longer be identified by its own link" \
  src/php/Rent/Adapters/EmailAlertSource.php \
  's%?? (\$link === null ? null : self::stableId(\$link))%?? null%'

# The no-information floor moved OUT of identityFor so it guards the card rather than the content
# key. Link identity does not collapse, so a floor living only in the content path would stop
# applying the moment a portal published a real id -- and a segment yielding a rent and nothing else
# is an extraction failure, whatever key it would have got.
run_sabotage "the no-information floor stops guarding the card" \
  src/php/Rent/Adapters/EmailAlertSource.php \
  's%if (!self::locatable(\$commune, \$postcode, \$rooms, \$surface, \$residence)) {%if (false) {%'

# THE HEADER MUST NOT BECOME A LISTING. It carries a rent, a room count and a surface belonging to
# no flat; what it lacks is a listing link. Widen link_host to the bare domain and `/mon-alerte/`
# qualifies again -- which is also how the notification went back to opening the saved search.
run_sabotage "Bien'ici's link host widens from the listing path to the domain" \
  config/rent/sources.json \
  's%"link_host": "bienici.com/annonce/"%"link_host": "bienici.com"%'

# One mailbox serves every portal, so `from` is the source's scope and not a nicety. Without the
# refusal an enabled email source reads every message in the label within the window and ingests
# other portals' alerts as its own, reporting a plausible count throughout.
run_sabotage "an enabled email source may ship without naming its sender" \
  src/php/Rent/Config/ConfigLoader.php \
  's%\$from = \$params\[.from.\] ?? null;%\$from = \$params["from"] ?? "sabotage";%'

# A segmented source keyed on its links must say WHICH links are listings. Two cards ending on the
# same stray link is caught loudly at fetch; two cards ending on different ROTATING advert links is
# caught by nothing -- plausible unique ids that change with the next campaign, so the whole source
# renotifies for ever and reads as a busy market.
run_sabotage "a segmented link-keyed source may ship without a link host" \
  src/php/Rent/Config/ConfigLoader.php \
  's%&& (!\\is_string(\$linkHost) || trim(\$linkHost) === ..)) {%\&\& false) {%'

# THE SAME RULE ON THE FORCE-RUN PATH. The loader refuses a `from`-less email_alert only when it is
# `enabled: true`, and `--source=<name>` force-runs a disabled one -- the exact gap that sent hard
# rule 1's REMPLACER refusal into buildSource(). A drafted block force-run without a sender reads
# EVERY message in the shared label and ingests other portals' alerts as its own.
run_sabotage "a force-run email source may skip naming its sender" \
  src/php/Rent/Cli/RentScout.php \
  's%\$from = \$definition->params\[.from.\] ?? null;%\$from = \$definition->params["from"] ?? "sabotage";%'

# An empty link_host satisfies an isset() check and then makes EVERY link qualify -- stringParam()
# treats '' as unset. The silent shape the refusal describes, reached by a different mistake.
run_sabotage "an empty link host passes for a named one" \
  src/php/Rent/Config/ConfigLoader.php \
  's%(!\\is_string(\$linkHost) || trim(\$linkHost) === ..)%(!isset(\$params["link_host"]))%'

# ── the miss signal reaching health() on every counting adapter (C2 round 1, F-R1 + C-3) ─────
#
# Four adapters of five COUNTED extraction misses and nothing read the count: HtmlSource,
# HttpJsonSource and FixtureSource each returned the store's verdict untouched, and
# SitemapVehicleSource counted nothing at all. Under `run --watch` a field map going 100% null on
# inli/cdc_habitat/cityloger/logirep produced no status change, no isAlerting(), no alert -- only a
# `doctor` printout. Hard rule 2: an alert computed and never sent is worse than none, because
# someone believes the green. In'li's card `cp` went 171/171 dead on the deployed image exactly like
# that, and a HUMAN found it.
run_sabotage "the miss escalation goes deaf (counted on every adapter, reported by none)" \
  src/php/Core/PatternMissLog.php \
  's%        \$blind = \$this->total();%        \$blind = [];%'

# ONE ADAPTER REVERTS TO THE DEAF ONE-LINER. The escalation was inline in two adapters and absent
# from three; extracting it is what makes a sixth adapter's omission FAIL rather than go dark, and
# the reflection test over the CountsPatternMisses implementors is what enforces that.
run_sabotage "an html source stops routing health() through the miss escalation" \
  src/php/Rent/Adapters/HtmlSource.php \
  's%        return \$this->patternMisses->escalate(\$this->store->health(\$this->name(), \$nowIso));%        return \$this->store->health(\$this->name(), \$nowIso);%'

# THE SITEMAP SOURCE STOPS COUNTING. autohero is enabled, and a renamed JSON-LD key would go null on
# every lot with item_count unmoved, no run failed and `ok` reported.
run_sabotage "the sitemap source stops recording its map-key misses" \
  src/php/Car/SitemapVehicleSource.php \
  "s%            \\\$this->patternMisses->record(\\\$key, \\\$value !== null);%            \\\$this->patternMisses->record(\\\$key, true);%"

# A COUNT THAT SPANS TWO FETCHES. RentScout builds its sources ONCE and the watch loop closes over
# them, so without the reset a template already fixed keeps warning for ever -- which sends an
# operator to read a capture that is fine and teaches them to ignore the signal. Worse than silence,
# because it is credible.
run_sabotage "a counting source's miss log accumulates across passes" \
  src/php/Car/SitemapVehicleSource.php \
  's%        \$this->patternMisses->reset();%        \/\/ reset removed%'

# ── §1: the re-advertised flat (C2 round 1, F1 — 2026-09-02) ─────────────────
#
# The veto travelled three ways -- the persisted cluster group_key, the cross-track twin_tenure, and
# the row's own durable reading -- and ALL THREE need an edge. A portal re-advertising an excluded
# flat under a NEW AD ID has none: a new external_id is a fresh row, and Dedup refuses a same-source
# edge because the source's own id is authoritative for IDENTITY. Correct for identity, wrong for
# §1, which is a fact about the DWELLING. Proven end to end through the real pipeline: one pass, one
# portal, one flat -- the first copy rejected because the store says PLS, the second pushed as a
# MATCH with that PLS one row away on disk.
run_sabotage "the shared dwelling matcher stops matching — BOTH surfaces lose the re-advertised-flat veto" \
  src/php/Rent/Core/ExcludedDwellings.php \
  's%$reason = $dedup->sameDwellingReason($listing, $candidate\[.listing.\]);%$reason = null;%'

# THE SAME GUARANTEE FROM THE STORE END. An emptied candidate set is the shape a well-meaning
# performance edit takes, and it leaves the veto present, called, and permanently silent.
run_sabotage "the stored excluded-dwelling set comes back empty" \
  src/php/Rent/Store/Store.php \
  "s%WHERE tenure IN (%WHERE 0 AND tenure IN (%"

# THE COUNTERWEIGHT, and without it the fix is satisfied by rejecting everything. Ignoring the
# positive-evidence bar makes every stored exclusion veto every listing -- §1 satisfied by switching
# the tool off, which is the In'li lesson and the direction this repo keeps having to re-learn.
run_sabotage "the stored-dwelling veto ignores its positive-evidence bar (rejects a different flat)" \
  src/php/Rent/Core/ExcludedDwellings.php \
  "s%if (\\\$reason === null) {%if (false) {%"

# ── tier 4: the income-ceiling band (2026-08-26) ──────────────────────────────
# The tier answers SOCIAL or nothing, and every case here is silent. Over-firing rejects an eligible
# flat and nothing arrives to say so; under-firing loses a social listing into the digest, which is
# the safe direction but still a regression worth catching.

# THE BOUNDARY IS STRICT because the boundary itself is an intermediate ceiling: 36 144 EUR is zone
# B1's one-person figure, so `<=` socialises a genuine LLI listing quoting its own ceiling. The
# scaffolding shipped with `<=`.
run_sabotage "the ceiling boundary goes back to inclusive (an LLI ad quoting its own ceiling rejects)" \
  src/php/Rent/Core/PlafondBands.php \
  's%return $ceilingEur < $band\[.max.\]%return $ceilingEur <= $band["max"]%'

# THE THRESHOLD IS DERIVED from the committed table. A literal drifts from the figures beside it at
# the next January revaluation, and the tier keeps applying last year's boundary.
run_sabotage "the unknown-zone threshold is written rather than derived from the table" \
  src/php/Rent/Core/PlafondBands.php \
  's%min(array_map(min(...), self::LLI_2026))%40000%'

# §1: tier 4 may never assert eligibility from a number. The overlap means such a reading would be
# wrong across a 73 451 EUR range, and it is the direction that puts a social listing in a push.
run_sabotage "a band may assert an intermediate tenure again (eligibility manufactured from a number)" \
  src/php/Rent/Core/PlafondBands.php \
  's%if ($band\[.tenure.\] !== Tenure::SOCIAL) {%if (false) {%'

# THE ANCHOR must keep `de ressources`. An intermediate ad quoting its own RENT ceiling as an annual
# figure is the shape that defeats the other two guards: the word is `plafond` exactly, so folding
# does not save it, and the figure is plausible, so the floor does not either. Only the anchor stops
# a rent ceiling being read as an income ceiling -- and it lands below the threshold, so the tier
# would contradict the listing's own intermediate label and digest a real match.
run_sabotage "the ceiling reader drops the de-ressources half (a RENT ceiling reads as an income one)" \
  src/php/Rent/Core/TenureClassifier.php \
  's%\$anchor = .*;%\$anchor = "plafon[a-z]*";%'

# THE NEGATION, read first -- `sans plafond de ressources` is ordinary private-market copy.
run_sabotage "the ceiling reader ignores its own negation (a signal made from its absence)" \
  src/php/Rent/Core/TenureClassifier.php \
  "s%if ((\\\$match\['neg'\]\[0\] ?? '') !== '') {%if (false) {%"

# THE PLAUSIBILITY FLOOR, twin of the rent band: a rent or charge near the anchor is below every
# threshold, so without it the tier answers SOCIAL to noise.
run_sabotage "the ceiling reader drops its plausibility floor (a rent becomes an income ceiling)" \
  src/php/Rent/Core/TenureClassifier.php \
  's%if ($amount < self::PLAFOND_PLAUSIBLE_MIN_EUR) {%if (false) {%'

# THE TIER ITSELF.
run_sabotage "tier 4 stops reading the listing (the rung goes inert again)" \
  src/php/Rent/Core/TenureClassifier.php \
  's%4 => $this->plafondSignals($listing),%4 => [],%'

run_sabotage "the classifier ships with tier 4 disarmed again" \
  src/php/Rent/Core/TenureClassifier.php \
  's%$bands ?? PlafondBands::ileDeFrance2026()%$bands ?? new PlafondBands()%'

# ── leboncoin: HTML-only alert mail (2026-08-26) ──────────────────────────────
# The failure this guards is hard rule 2's exact shape reached without a catch: leboncoin sends no
# text/plain alternative, so before the harvest the parser produced a perfect body carrying all
# three listings and ZERO links -- a source that yields no listings and reports a quiet market for
# ever, while `doctor` says `ok`.

run_sabotage "an HTML-only alert loses its links again (a source that reports a quiet market for ever)" \
  src/php/Adapters/Mail/EmailMessage.php \
  's%strip_tags(self::harvestHrefs($spaced))%strip_tags($spaced)%'

# RETIRED 2026-08-26: "the harvested URL is emitted before its anchor text".
# The rule is real -- the URL is emitted AFTER the anchor text so reading order matches the rendered
# one -- but the leboncoin payload CANNOT DISTINGUISH the two orders, measured: each card's own URL
# appears twice (image anchor and CTA anchor), so the last qualifying link in a segment is the same
# either way, and the mutation left the suite green. A sabotage case whose guarantee no test can
# separate reports coverage it does not have, which is precisely what this ledger exists to catch,
# so it is retired rather than left green. The ordering therefore stands as a REASONED default, not
# a measured one; a portal whose card links only once would distinguish them, and that payload is
# the case to write when it arrives.

# Tracking parameters are machine furniture, and harvesting URLs into the body fed them to the
# tenure scan. A campaign string carrying `lli` and `plai` conflicts a correct verdict into the
# digest -- the CDC-tooltip class on a surface no listing copy controls.
run_sabotage "URL tracking parameters are classified as prose again (a campaign string vetoes a flat)" \
  src/php/Rent/Core/RawListing.php \
  's%\[?#\]\[^%[?#]ZZZ[^%'

# And the §1 half of that rule: the PATH is kept on purpose, because `/logement-social/` is real
# evidence and losing a social signal is the dangerous direction.
run_sabotage "the whole URL is blanked, not just its parameters (a social path signal is lost)" \
  src/php/Rent/Core/RawListing.php \
  's%, .\$1., \$text)%, "", $text)%'

# A CONFIGURED `title_pattern` THAT MISSES MUST NOT WEAR THE SUBJECT LINE AS AN ALIBI.
# Measured 2026-08-26: SeLoger's vocabulary-based pattern missed 27 of 72 live cards and every one
# stored `4 nouvelles annonces : Ile-de-France` as a flat's title. `Criteria::excludedBy()` matches
# `exclude_title_patterns` against the TITLE ONLY -- deliberately, `3 chambres` in a description is
# the family flat the criteria want -- so `^\s*chambre\b` and the parking/box/garage family were
# unreachable on 37.5% of the source, which is the exclusion added BECAUSE four of its first nine
# matches were coliving rooms passing every numeric filter.
#
# This case exists because the obvious sabotage does not detect it: with the positional pattern in
# place every frozen card extracts, so the fallback branch is never entered and all six fixture
# suites stay green while the safety is deleted. `EmailAlertSegmentationTest` enters it on purpose.
run_sabotage "an unread title falls back to the message subject again (a title-only exclusion goes unreachable)" \
  src/php/Rent/Adapters/EmailAlertSource.php \
  "s%?? ''%?? \$message->subject()%"

# And the half that keeps the asymmetry honest: a source configuring NO pattern must still take the
# subject, because there it is the documented answer rather than a substitute for one. Blanking both
# would silently strip the title from every single-flat alert.
run_sabotage "a source with no title_pattern loses its subject title" \
  src/php/Rent/Adapters/EmailAlertSource.php \
  's%return \$message->subject();%return "";%'

# A TITLE IS A POSITION, NEVER A VOCABULARY. The replacement anchors on the layout SeLoger emits --
# rent line, agency free text, `<n> pièces . <s> m²` -- rather than on a guess at what an agency
# types. `APARTMENT` is English and `T5` is two characters; neither starts with a French dwelling
# noun, and the old list read all four cards of the 003 fixture as the alert's subject.
# The POSITIONAL ANCHOR is what makes the pattern structural: the title is the line above
# `<n> pièces . <s> m²`. Break the anchor and nothing matches, so every card falls back — which is
# the state the source shipped in for a month.
#
# RETARGETED 2026-09-01 (F24), and the reason is worth more than the case. This expression anchored
# on `pi[eè]ces?\b~mu` — the room-count landmark sitting at the very END of the pattern. Adding the
# surface branch moved it into the middle of an alternation, so the expression stopped matching
# ANYTHING and went silently inert, reporting a guarantee it no longer touched. It names no method
# and no symbol, only a neighbouring byte sequence, which is exactly why `git grep` for the changed
# thing would never have found it: `tests/test-sabotage-applies.sh` did. Same class as the
# `Core/RunStore` split, where grepping for the moved names answered 1 and running the gate answered
# 39. **Retarget at the guarantee that still exists; never delete.**
#
# The guarantee is unchanged — kill the anchor, every card falls back — so BOTH branches must die
# now. Corrupting only `pièces` leaves the surface branch answering, which is a different (and
# genuine) defect covered by its own case below.
run_sabotage "the seloger title loses its positional anchor (every card falls back again)" \
  config/rent/sources.json \
  's%(?:\\\\d+\\\\h\*pi\[eè\]ces?\\\\b|\[\\\\d.,\]+\\\\h\*m(?:²|2)\\\\b)%(?:ZZZNOANCHOR)%'

# And the capture floor is 2 characters, not 3, because `T5` and `T3` are real SeLoger titles. A
# floor of 3 looks harmless and silently drops exactly the shortest titles the portal emits.
run_sabotage "the seloger title capture floor rises to 3 (T5 and T3 stop being titles)" \
  config/rent/sources.json \
  's%{2,80}%{3,80}%'

# --- PAP: the search criteria quoted above the listing (2026-08-26) --------------------------
#
# PAP's alert prints the SUBSCRIBER'S OWN search criteria above the flat — "jusqu'a 1.200 EUR a
# partir de 45 m2" — and every generic reader in EmailAlertSource is a first-match-wins preg_match.
# Measured on the real capture: the surface read 45 (the search FLOOR) instead of the flat's 50, and
# 45 is below min_surface_m2, so the first PAP alert ever sent was rejected as too small. Silently.
#
# The fix is a POSITIONAL anchor on the (NNNNN) postcode line. These four cases are the guarantees.

# A configured pattern that MISSES must yield null, never the generic scan. Falling back restores
# the defect AND gives it an alibi: the row reads as a small flat, not a broken extraction.
# NO FIXTURE ENTERS THIS BRANCH — with the anchors in place every frozen card extracts — so this is
# pinned by EmailAlertSegmentationTest, which enters it on purpose. Same dead-safety-code trap the
# seloger title walked into on 2026-08-26.
run_sabotage "a missed surface_pattern falls back to the first m² in the body again" \
  src/php/Rent/Adapters/EmailAlertSource.php \
  's%\$owned = \$this->stringParam(.surface_pattern.) !== null;%\$owned = \$this->matchParam("surface_pattern", \$body) !== null;%'

run_sabotage "a missed rooms_pattern falls back to the first digit in the body again" \
  src/php/Rent/Adapters/EmailAlertSource.php \
  's%\$owned = \$this->stringParam(.rooms_pattern.) !== null;%\$owned = \$this->matchParam("rooms_pattern", \$body) !== null;%'

# link_host CARRIES THE PATH here. PAP's message has two links, both on www.pap.fr: the annonce and
# the unsubscribe page, whose wording matches none of the noise words looksLikeAListing() rejects. A
# non-segmented source builds one listing PER ACCEPTED LINK, so a host-only value yields a phantom
# second listing carrying the real flat's rent, commune and surface under its own identity —
# notified as a separate flat, and never delisted because an unsubscribe page never goes away.
run_sabotage "the pap link filter loses its path (the unsubscribe page becomes a listing)" \
  config/rent/sources.json \
  's%"link_host": "www.pap.fr/annonces/"%"link_host": "www.pap.fr"%'

# title_pattern was INERT on every non-segmented source: listingsIn() hardcoded the subject and only
# the segmented path consulted cardTitle(). A configured pattern doing nothing, which on this source
# makes exclude_title_patterns unreachable — the In'li and SeLoger lesson a third time.
run_sabotage "a non-segmented source takes the message subject as its title again" \
  src/php/Rent/Adapters/EmailAlertSource.php \
  's%title: \$this->cardTitle(\$message, \$body),%title: \$message->subject(),%'

# --- Commute enrichment (2026-08-26) ---------------------------------------------------------
#
# The heaviest component in the score (30, ahead of commune's 25). Every failure mode here is
# SILENT: a commute read as far demotes a flat nobody then looks at, and one read as near promotes
# one nobody should.

# Past the ceiling the component decays to ZERO and stops -- it never goes negative. A negative
# share PUNISHES a long commute instead of merely not rewarding it, which is a disqualifier wearing
# a score's clothes: the developer's ruling forbids it in as many words ("keep showing even those
# with more anyway"), and hard rule 8 keeps the two mechanisms apart.
run_sabotage "a commute past the ceiling is punished rather than merely unrewarded" \
  src/php/Rent/Core/CriteriaEngine.php \
  's%: max(0.0, min(1.0, (2 \* \$ceiling - \$minutes) / \$ceiling));%: (\$minutes > \$ceiling ? -1.0 : 1.0);%'

# UNKNOWN IS NOT NEAR. Scoring an absent commute as if it were zero minutes hands every listing the
# full 30 points on the strength of an API that did not answer -- and it looks like a healthy score.
run_sabotage "an unknown commute scores as if the flat were on the doorstep" \
  src/php/Rent/Core/CriteriaEngine.php \
  's%\$reasons\[\] = .trajet inconnu — hors score.;%\$earned += \$w->commute; \$reasons[] = "trajet inconnu";%'

# THE CACHE IS READ. A commune resolves once and then costs no request ever again -- without that
# the tree spends two requests per listing per pass against a 20 000/day quota, and the same commune
# spelled two ways spends them twice over.
run_sabotage "the commute cache is never read (every pass re-requests every commune)" \
  src/php/Rent/Enrich/NavitiaCommute.php \
  's%if (\$cached !== null) {%if (false) {%'

# LONGITUDE FIRST. Reversed, the request still succeeds and returns a plausible journey between two
# entirely different places -- there is no error to notice, only wrong minutes.
run_sabotage "the journey coordinates are swapped to latitude first" \
  src/php/Rent/Enrich/NavitiaCommute.php \
  "s%'from' => \\\$from\[0\] . ';' . \\\$from\[1\],%'from' => \\\$from[1] . ';' . \\\$from[0],%"

# SECONDS, not minutes. Verified against the live API: 2148 for a 35-minute trip.
run_sabotage "the journey duration is read as minutes rather than seconds" \
  src/php/Rent/Enrich/NavitiaCommute.php \
  's%return \$best === null ? null : (int) round(\$best / 60);%return \$best;%'

# A geocode is checked against the postcode it was asked for. Commune names repeat across
# departements, and a wrong coordinate is cached for ever and mis-scores a whole town in silence.
run_sabotage "a geocoded place is accepted without checking its postcode" \
  src/php/Rent/Enrich/NavitiaCommute.php \
  's%if (\$postcode !== null \&\& !\$this->matchesPostcode(\$candidate, \$postcode)) {%if (false) {%'

# Enrichment must never void a pass that has already fetched real listings -- the blast-radius
# mistake detail hydration made once already.
run_sabotage "an unreachable commute API takes the whole pass down with it" \
  src/php/Rent/Enrich/NavitiaCommute.php \
  's%} catch (\\Throwable) {%} catch (\\JsonException) {%'

# A COMMUTE IS MINUTES BETWEEN TWO PLACES. Drop the destination from the cache lookup and every
# cached commune answers with the journey to a PREVIOUS address for ever the day the destination
# changes -- plausible numbers, confident reasons, nothing expiring, and failures deliberately not
# cached. Schema v6's detail-map fingerprint exists for exactly this failure on a different surface.
# Targeted at the FINGERPRINT rather than at the SQL: removing the WHERE clause would leave `:d`
# bound and unused, so the suite would go red on a PDO error rather than on the guarantee, which
# proves nothing. A constant key is the same defect with no crash to hide behind.
run_sabotage "the commute cache forgets which destination its minutes are to" \
  src/php/Rent/Enrich/NavitiaCommute.php \
  's%\$destinationKey = sha1(\$destination);%\$destinationKey = "any-destination";%'

# ── The IMAP window cap ─────────────────────────────────────────────────────────────────────────
#
# The cap is a COST ceiling and `SEARCH SINCE` is a correctness one, so when the cap bites it is the
# catch-up window that shrinks — silently, because the newest messages are still read and the count
# still looks healthy. Measured live 2026-08-26: 108 SeLoger messages in the window, 50 read.

# Deleting the notice entirely. This is the shape the defect actually had for a month.
run_sabotage "a truncated IMAP window stops saying it was truncated" \
  src/php/Adapters/Mail/ImapMailbox.php \
  's%if (\$matched <= \$limit) {%if (true) {%'

# Computed and never emitted — hard rule 2's exact wording, one layer down: an alert that is worked
# out and then dropped is worse than one that was never computed, because it reads as covered.
run_sabotage "the truncation notice is computed and then never sent" \
  src/php/Adapters/Mail/ImapMailbox.php \
  's%if (\$notice !== null && \$this->warn !== null) {%if (false) {%'

# A zero or negative IMAP_MAX_MESSAGES reading as "read nothing" — a source that reports a quiet
# market for ever, which is the failure the whole clamp exists to refuse.
run_sabotage "a nonsense message cap is allowed to mean read nothing" \
  src/php/Adapters/Mail/ImapMailbox.php \
  's%return max(1, (int) trim(\$configured));%return (int) trim(\$configured);%'

# The uncapped count is what carries the news; counting AFTER the slice can never exceed the limit,
# so the notice could never fire again and every test above would still be reading real code.
run_sabotage "the window is measured after the cap instead of before it" \
  src/php/Adapters/Mail/ImapMailbox.php \
  's%\$notice = self::truncationNotice(\\count(\$matched), \$limit, \$this->fromFilter);%\$notice = self::truncationNotice(\\count(\\array_slice(\$matched, 0, max(0, \$limit))), \$limit, \$this->fromFilter);%'

# THE `!!` MARKER'S CONFIDENCE FLOOR. `high_priority_score` dropped 70 -> 50 on 2026-08-26, and the
# floor is the whole reason that is a tightening rather than a loosening: `!!` needs a high score AND
# a tenure verdict confident to 80/100. Deleting the second clause left the WHOLE SUITE GREEN until
# `HighPriorityMarkerTest` was written, so a "drop what you're doing" marker could have started
# appearing on listings whose regime was the source default -- exactly the private-portal cards that
# score highest, and exactly the ones §1's residual lives in.
run_sabotage "a guessed tenure is allowed to carry the high-priority marker" \
  src/php/Rent/Core/CriteriaEngine.php \
  's%&& \$classification->confidenceBp >= self::HIGH_PRIORITY_MIN_CONFIDENCE_BP;%;%'

# And the withheld marker must SAY it was withheld. A demotion in silence is indistinguishable from
# a scoring bug, and it is the one line that tells the reader the tenure -- not the flat -- is what
# held it back.
# The apostrophe in the PHP string is why this expression captures rather than rewrites: an earlier
# draft spliced a quote in and produced a PARSE ERROR, which the ledger reported as proving nothing
# either way — correctly. A mutation that does not compile tests nothing.
run_sabotage "a marker withheld for low confidence is withheld in silence" \
  src/php/Rent/Core/CriteriaEngine.php \
  's%\$reasons\[\] = \(.priorité normale malgré\)%\$_unused = \1%'

# THE `!!` GLYPH ITSELF. Every other link in that chain had a test — the engine producing
# `highPriority`, and `Formatter` mapping it to `Priority::HIGH` — and the RENDERING, the only part
# the developer ever sees, had none. A marker that prints like an ordinary match is worse than one
# switched off: it reports a discrimination it is not making, and the listing that most deserved
# attention arrives looking like the other sixty that day.
run_sabotage "the high-priority marker renders like an ordinary match" \
  src/php/Core/Notify/ConsoleChannel.php \
  "s%Priority::HIGH => '!!',%Priority::HIGH => ' >',%"

# ntfy's scale is 1–5 and only 4 and 5 break through a phone's quiet hours, which is the entire
# behavioural difference the marker buys. `NtfyChannelWireTest` asserts a `Priority:` header EXISTS,
# which is true of every level — so this silent demotion would have passed it unchanged.
run_sabotage "the high-priority push is demoted to an ordinary ntfy level" \
  src/php/Core/Notify/Priority.php \
  's%self::HIGH => 5,%self::HIGH => 3,%'

# THE SAME GUARANTEE ON ITS OTHER SURFACE. The case above mutates the enum; this one leaves the enum
# correct and hardcodes the CALL SITE, which is what actually reaches the phone. It survived both
# `PriorityRenderingTest` (which pins the enum) and the wire test (which asserted only that a
# `Priority:` header existed) until that assertion was strengthened to check the value — a correct
# rule covering one of its two surfaces, found inside the test that names the failure class.
run_sabotage "the ntfy wire hardcodes a level instead of sending the notification's own" \
  src/php/Core/Notify/NtfyChannel.php \
  "s%'Priority: ' \\. \\\$n->priority->ntfyLevel()%'Priority: 3'%"

# ── round 9: a feed can stop DELIVERING while the source keeps REPORTING (2026-08-28) ─────────
#
# Measured on the live watcher: `leboncoin` reported item_count = 3 on 263 consecutive passes, every
# one of them re-reading ONE email dated 26 August. Every existing verdict was correct and every one
# said healthy. These five cases pin the parts of the fix whose failure is, as usual here, silent.

run_sabotage "a silent feed stops being reported at all (the leboncoin case returns)" \
  src/php/Core/RunStore.php \
  's%if ($silentFor >= $feedSilentDays \* 86400) {%if (false) {%'

# UNKNOWN MUST NOT BECOME OLD. Reading a null feed date as ancient turns the entire pre-v11 run log,
# every html/json source and the documented MAILBOX_DIR fixture workflow into a permanent alert --
# hard rule 9 at the health layer, and the noisy direction, which is how an alert becomes furniture.
run_sabotage "an unknown feed date is read as an ancient one" \
  src/php/Core/RunStore.php \
  's%\$feedDates\[\] = \$reported;%\$feedDates[] = \$reported ?? "1970-01-01T00:00:00Z";%; s%if (\$reported !== null \&\& \$reported !== ..) {%if (true) {%'

# A FUTURE DATE MUST NOT MASK AN AGEING FEED. The verdict reduces reported dates to their maximum,
# so one portal with a fast clock wins that maximum and reports the feed fresh for ever. Removing the
# credibility filter is the mutation; the suite must notice.
run_sabotage "a future-dated message is trusted, masking a silent feed for ever" \
  src/php/Core/RunStore.php \
  's%static fn (string \$at): bool => self::epoch(\$at) <= \$cutoff,%static fn (string $at): bool => true,%'

# THE THRESHOLD/WINDOW RELATION MUST STILL BE REPORTED. This was a hard refusal until 2026-08-29,
# when a panel showed its premise was false (SEARCH SINCE filters INTERNALDATE, not the Date header)
# and that it locked every store-opening verb out at IMAP_SINCE_DAYS=1. It is a `doctor` diagnostic
# now -- but a diagnostic nobody prints is the dead config the refusal was trying to prevent, one
# layer over. Silencing it must go red.
run_sabotage "doctor stops warning that the threshold is at or above the IMAP window" \
  src/php/Adapters/Mail/ImapMailbox.php \
  's%if (\$days === null || \$days < \$window) {%if (true) {%'

# The check MOVED to ImapMailbox on 2026-09-01 because IMAP_SINCE_DAYS is shared between the two
# domains and it lived only on the rent side — so config/car/sources.json shipped `leboncoin: 7`
# against the default 7-day window, an empty observable band, and nothing said a word. The case
# above now covers the shared helper; this one covers the CAR doctor actually printing it, because
# a helper nobody calls is the dead diagnostic the refusal was trying to prevent, one layer over.
run_sabotage "the CAR doctor stops printing the threshold/window band advice" \
  src/php/Car/Cli/CarScout.php \
  's%if (\$windowNote !== null) {%if (false) {%'

# THE DECORATOR MUST FORWARD IT. `wrapAll()` wraps every source under --watch, which is the ONLY
# mode in which a feed can go silent unnoticed for days -- so a decorator that drops the capability
# makes the detection unreachable in exactly the mode it was built for, while every unit test on the
# inner source still passes.
# THE DEFAULT MUST REACH THE STORE. This is the third dead-config shape inside the change built to
# kill it: the CLI refusal tests pin only that feedSilentDays() is CALLED, and every Store test
# passes the threshold EXPLICITLY -- so dropping the merge left feed_silent unreachable in
# production under default config while the whole suite stayed green.
run_sabotage "the configured threshold never reaches health() (feed_silent dead by default)" \
  src/php/Core/RunStore.php \
  's%\$feedSilentDays ??= \$this->feedSilentDays;%%'

run_sabotage "PacedSource stops forwarding feed freshness (dead under --watch, green in tests)" \
  src/php/Rent/Adapters/PacedSource.php \
  's%return $this->inner instanceof FeedFreshness ? $this->inner->newestFeedItemAt() : null;%return null;%'

# ── round 9b: the links three reviewers each cut with the suite green (2026-08-29) ────────────
#
# Every case below was proven UNDETECTED by a certification panel. Together they were the whole
# production path that PRODUCES a feed date -- the store side was pinned and the source side was not,
# so `FEED_SILENT` could be judged perfectly on rows nothing would ever write.

# THE MASTER SWITCH. One line, and FEED_SILENT dies for seloger, bienici, leboncoin and pap at once,
# in production only. The store tests all pass the date by hand, so none of them noticed.
run_sabotage "EmailAlertSource stops delegating freshness to its mailbox (all email sources)" \
  src/php/Rent/Adapters/EmailAlertSource.php \
  's%return \$this->mailbox->newestMessageAt();%return null;%'

# THE PARSER ITSELF, which no test had ever executed. `new \DateTimeImmutable` is a RELATIVE
# expression parser: `Fri, 09 Aug 2026` (a Sunday) advances five days, past the four-day observable
# band, closing the verdict on that source for ever.
run_sabotage "the Date header is parsed permissively again (a bad weekday shifts it forward)" \
  src/php/Adapters/Mail/ImapMailbox.php \
  's%$iso = EmailMessage::parse($raw)->sentAt();%$iso = (new \\DateTimeImmutable((string) EmailMessage::parse($raw)->header(\x27Date\x27)))->setTimezone(new \\DateTimeZone(\x27UTC\x27))->format(\x27c\x27);%'

# THE MASK SET IS THE RFC'S GRAMMAR, not one portal's habit. It was two-digit-day-only for a month
# and refused `Thu, 3 Sep 2026`, which RFC 5322 writes as `1*2DIGIT`: measured in production on
# 2026-09-05, every PAP alert of 1–5 September stored NO observation time (disarming the
# stale-sighting guard) while feed_newest_at froze on 31 August and doctor reported `feed_silent` on
# a feed delivering daily. Five days a month, on any portal that does not zero-pad.

run_sabotage "the date parse refuses an RFC-legal single-digit day again" \
  src/php/Adapters/Mail/EmailMessage.php \
  "s%foreach (\['d', 'j'\] as \\\$day) {%foreach (['d'] as \\\$day) {%"

run_sabotage "the date parse refuses an RFC-legal time without seconds again" \
  src/php/Adapters/Mail/EmailMessage.php \
  "s%foreach (\['H:i:s', 'H:i'\] as \\\$time) {%foreach (['H:i:s'] as \\\$time) {%"

# THE ROUND-TRIP is what makes the parse strict; createFromFormat alone accepts the bad weekday and
# reports no error.
run_sabotage "the date parse drops its round-trip check (strictness becomes theatre)" \
  src/php/Adapters/Mail/EmailMessage.php \
  's%if (\$parsed !== false \&\& \$parsed->format(\$mask) === \$value) {%if ($parsed !== false) {%'

# FileMailbox reporting a real date reddens the documented MAILBOX_DIR workflow as the calendar
# advances -- a gate that goes red with no code change.
run_sabotage "FileMailbox starts reporting fixture dates as feed freshness" \
  src/php/Adapters/Mail/FileMailbox.php \
  's%^        return null;$%        return "2026-08-25T00:00:00Z";%'

# THE PIPELINE and THE DOCTOR are the two writers. Either one silently stops populating the column.
run_sabotage "the pipeline stops recording the feed date it just read" \
  src/php/Rent/Cli/Pipeline.php \
  's%\$feedNewestAt = FeedDate::of(\$source);%$feedNewestAt = null;%'

# BOTH WRITERS, in one place. `Pipeline` and `doctor` each had their own copy of this expression and
# both were replaceable with `null` while the suite stayed green. Only the pipeline's could be
# pinned: observing doctor's needs an email_alert source whose mailbox reports a date, and the only
# offline mailbox is FileMailbox, which returns null on purpose and must keep doing so. A test that
# cannot exist is not a reason to leave code untested -- it is a reason not to duplicate it, so the
# two sites were collapsed onto FeedDate::of() and the pipeline's coverage now covers both.
run_sabotage "the shared feed-date reader stops asking the source (both writers at once)" \
  src/php/Rent/Adapters/FeedDate.php \
  's%return \$source instanceof FeedFreshness ? \$source->newestFeedItemAt() : null;%return null;%'

# THE ZERO-COUNT GATE. Widening it lets FEED_SILENT preempt the empty-streak BROKEN verdict that
# owns the zero case.
run_sabotage "the zero-count gate is widened, letting a silent feed preempt broken" \
  src/php/Core/RunStore.php \
  's%\&\& \$feedSilentDays !== null \&\& \$lastCount > 0)%\&\& $feedSilentDays !== null \&\& $lastCount >= 0)%'

# WRITE-TIME VALIDATION. Deferring it turns an unreadable date into a permanent ABSENCE of verdict:
# the source looks watched and is unwatched.
run_sabotage "recordRun drops write-time validation of the feed date" \
  src/php/Core/RunStore.php \
  's%            self::epoch(\$feedNewestAt);%            ;%'

# INSTANTS, NOT STRINGS. The store accepts any RFC 3339 offset, so a lexical max picks the wrong
# element across mixed offsets and over-states silence.
run_sabotage "the newest feed date is chosen lexically instead of by instant" \
  src/php/Core/RunStore.php \
  's%if (\$best === null || self::epoch(\$date) > self::epoch(\$best)) {%if ($best === null || $date > $best) {%'

# THE PER-FETCH RESET. Without it a pass that fetched NOTHING reports the previous pass's date as
# its own -- the invariant Pipeline states and, until this case, nothing enforced.
run_sabotage "ImapMailbox keeps a stale feed date across a fetch that returned nothing" \
  src/php/Adapters/Mail/ImapMailbox.php \
  's%^        \$this->newestMessageAt = null;$%%'

# THE TALLY LIVES HERE, BELOW EVERY CASE, and that position is load-bearing rather than tidy.
# It sat mid-file twice: once on 2026-08-20 (295 printed for 303 cases) and again from 2026-08-23,
# when 21 schema-v7 / digest / reclassify cases were appended past it and the headline read 354 for
# 375. A tally that excludes the newest cases is worse than no tally — those are exactly the ones
# nobody has confidence in yet, and CLAUDE.md and the plan files quote this number as authoritative.
# `tests/test-ci-workflow.sh` pins the position, so appending below it is now a red build.
# ── per-source feed_silent_days and `scout --domain=rent replay --file` (2026-08-29) ──────────────────────────
#
# THE PER-SOURCE THRESHOLD RIDES THROUGH ONE FUNNEL. `EmailAlertSource::health()` is the only place
# a source's own `feed_silent_days` reaches the store, and doctor, the pipeline and the heartbeat
# all read health through the source. Dropping the argument there re-installs the global threshold
# for every caller at once, and the suite must say so.
#
# RETARGETED 2026-09-02, same cause as the car case: this matched the whole `$health = ...`
# assignment, and the C2 round-1 fix collapsed that assignment into the ARGUMENT of `escalate(...)`.
# The GUARANTEE is untouched and is the one that matters -- the source's own feed_silent_days must
# reach `health()`, the ONE funnel doctor, the pipeline and the heartbeat all read -- so the
# expression now names the CALL rather than the assignment that used to hold it.
run_sabotage "a per-source feed_silent_days is ignored by the source's own health()" \
  src/php/Rent/Adapters/EmailAlertSource.php \
  's%\$this->store->health(\$this->name(), \$nowIso, \$this->definition->feedSilentDays)%\$this->store->health(\$this->name(), \$nowIso)%'

# Both load-time refusals share one guard: a threshold on a source that cannot act on it (html/json
# report no feed date), and a threshold of 0, which disables the verdict. Disabling the guard accepts
# both — a configured feature that never runs, and a switched-off one that looks configured.
run_sabotage "feed_silent_days is accepted on a source that can never act on it, and at 0" \
  src/php/Rent/Config/ConfigLoader.php \
  's%        if ($feedSilentDays !== null) {%        if (false) {%'

# `scout --domain=rent replay --file` — three guarantees, each a silent failure without its case.
#
# The replay's store is a THROWAWAY. `dump` hydrates through the detail cache, so against the real
# database a replay records one fetch-failure row per listing, for pages nobody fetched, in the
# store it was diagnosing.
run_sabotage "scout replay --file writes its simulated detail failures into the real store" \
  src/php/Rent/Cli/RentScout.php \
  "s%            Store::open(':memory:'),%            \$this->store(),%"

# The replay client, not the real one. The suite runs SCOUT_OFFLINE=1, so this one is caught by the
# offline tripwire — but only because a test runs replay against a real source block at all.
run_sabotage "scout replay --file polls the real host instead of the frozen file" \
  src/php/Rent/Cli/RentScout.php \
  's%new self($this->rootDir, $this->out, $this->err, $this->nowIso, $client, $this->notifier)%new self($this->rootDir, $this->out, $this->err, $this->nowIso, $this->http, $this->notifier)%'

# `/robots.txt` must be ABSENT (404 = allow), never the payload: HTML handed to the robots parser
# fails closed under the 2026-08-25 SPA rule, and the replay refuses itself.
run_sabotage "the replay client serves the payload as robots.txt, so the replay refuses itself" \
  src/php/Adapters/Http/ReplayHttpClient.php \
  "s%        if (str_ends_with(\$path, '/robots.txt')) {%        if (false) {%"

# And a detail page is NOT the search page. The fallthrough 404 is what stops a detail_map from
# selecting a listing's title out of the results page — plausible, and wrong.
run_sabotage "the replay client answers a detail-page URL with the search payload" \
  src/php/Adapters/Http/ReplayHttpClient.php \
  "s%        if (str_starts_with(\$request->url, \$this->prefix)) {%        if (true) {%"

# ── the four production defects of 2026-08-29 ──────────────────────────────────────────────────
#
# THE PHANTOM-DROP LOOP. One Bien'ici flat re-sent a day later at a HIGHER rent, both messages in
# the window, both stamped at the pass time: 1146 then 1122, "a drop", every fifteen minutes — 429
# history rows, 128 emails. The observation time travels adapter → listing → snapshot → pipeline →
# store, and each hop is a place it can be quietly dropped.
run_sabotage "an email listing is observed at the pass time again (the adapter drops the message date)" \
  src/php/Rent/Adapters/EmailAlertSource.php \
  's%            observedAt: $message->sentAt(),%            observedAt: null,%'

run_sabotage "the pipeline records every sighting at the pass time (the observation time is ignored)" \
  src/php/Rent/Cli/Pipeline.php \
  's%$member->observedAt ?? $nowIso%$nowIso%'

run_sabotage "the snapshot drops the observation time (a re-judged row would be re-dated to now)" \
  src/php/Rent/Core/ListingSnapshot.php \
  "s%            'observedAt' => \$listing->observedAt,%%"

# THE HOP THE FIRST FIX MISSED: enrichment rebuilt the listing and dropped the observation time, on
# the one machine where commute is enabled — production. Green tests, phantom drop on the first
# live pass (2026-08-29 21:20).
run_sabotage "enrichment drops the observation time again (the production hop of the phantom-drop loop)" \
  src/php/Rent/Core/RawListing.php \
  "s%return clone(\$this, \['commuteMinutes' => \$minutes\]);%return clone(\$this, ['commuteMinutes' => \$minutes, 'observedAt' => null]);%"

# Lenient parsing moves a mismatched weekday FORWARD, and forward is the wrong direction here: a
# stale card reading as the newest one is the loop with the arithmetic reversed.
run_sabotage "sentAt() parses leniently (a mismatched weekday is advanced instead of refused)" \
  src/php/Adapters/Mail/EmailMessage.php \
  's%return self::parseRfc2822($header)?->setTimezone%return (new \\DateTimeImmutable($header))->setTimezone%'

# TWO TRACKS, ONE PUSH. Identities stay per track (the 2026-08-06 ruling); only the push is shared.
run_sabotage "cross-track copies are MERGED into one identity (the two-tracks ruling reversed)" \
  src/php/Rent/Core/Dedup.php \
  's%        if ($familyA !== $familyB) {%        if (false) {%'

run_sabotage "a same-track pair is reported as a twin (a duplicate wearing the twin label)" \
  src/php/Rent/Core/Dedup.php \
  's%        if ($a->sourceName === $b->sourceName || $familyA === $familyB) {%        if ($a->sourceName === $b->sourceName) {%'

run_sabotage "the agency copy is pushed as well as the direct route (43 flats pushed twice again)" \
  src/php/Rent/Cli/Pipeline.php \
  's%            if ($announcedByTwin \&\& !$isDirect) {%            if (false) {%'

run_sabotage "the direct route is no longer judged first, so source order decides which copy is pushed" \
  src/php/Rent/Cli/Pipeline.php \
  "s%usort(\$clustered, static fn (array \$a, array \$b): int => (\$a\['family'\] === 'institutional' ? 0 : 1) <=> (\$b\['family'\] === 'institutional' ? 0 : 1));%usort(\$clustered, static fn (array \$a, array \$b): int => 0);%"

# A ROOM IS A NOUN, NOT A POSITION. The anchored form let three live titles through in a week.
run_sabotage "the room pattern is anchored at the start of the title again (an emoji defeats it)" \
  config/rent/criteria.json \
  's%\\\\bchambres?\\\\b(?!%^\\\\s*chambre\\\\b(?!%'

# THE SOURCE LEADS THE TITLE — the developer's own ordering signal.
run_sabotage "the source no longer leads a match title" \
  src/php/Rent/Notify/Formatter.php \
  "s%\$listing->sourceName . ' · ' . (\$score === null%'' . (\$score === null%"

run_sabotage "the source no longer leads a rent-drop title" \
  src/php/Rent/Notify/Formatter.php \
  "s%title: \$listing->sourceName . ' · ' . (\$nowQualifies%title: '' . (\$nowQualifies%"

# ── THE CAR DOMAIN (2026-08-29) ────────────────────────────────────────────────────────────────
#
# The vehicle §1 set arrives NEGATED in honest copy. Cutting the negation window rejects every good
# ad that says "jamais accidenté" — over-rejection, invisible by definition.
run_sabotage "the vehicle classifier stops reading negations (every honest ad rejects itself)" \
  src/php/Car/VehicleClassifier.php \
  "s%private const string NEGATION_BEFORE = '~(?:\\\\b(?:jamais|non|pas|aucun|aucune|sans|ni|zero|0)\\\\b)%private const string NEGATION_BEFORE = '~(?:\\\\bzzznever\\\\b)%"

run_sabotage "accidenté leaves the vehicle exclusion set (decision 9 undone without a commit saying so)" \
  src/php/Car/VehicleClassifier.php \
  "s%        'accidenté' => '~\\\\baccident(?:e|ee|es|ees|s)?\\\\b~u',%        'accidenté' => '~\\\\bzzznever\\\\b~u',%"

run_sabotage "the price ceiling stops being a hard line (a 30 001 € car is pushed)" \
  src/php/Car/VehicleScorer.php \
  's%if ($car->priceEur !== null \&\& $car->priceEur > $criteria->maxPriceEur) {%if (false) {%'

run_sabotage "an unknown location rejects a car (hard rule 9 inverted on the car side)" \
  src/php/Car/VehicleCriteria.php \
  "s%        if (\$this->postcodePrefixes === \[\] || \$postcode === null || trim(\$postcode) === '') {%        if (\$this->postcodePrefixes === []) {%"

# The phantom-drop loop, rebuilt on the car side: every hop of the observation time.
run_sabotage "the car pipeline records every sighting at the pass time (a re-read older card is a drop again)" \
  src/php/Car/VehiclePipeline.php \
  's%$this->store->record($car, $car->observedAt ?? $nowIso);%$this->store->record($car, $nowIso);%'

run_sabotage "the car store treats an older sighting as current" \
  src/php/Car/VehicleStore.php \
  "s%            \$isCurrent = \$isNew || \$epoch >= (int) \$row\['seen_epoch'\];%            \$isCurrent = true;%"

run_sabotage "the car snapshot drops the observation time" \
  src/php/Car/VehicleSnapshot.php \
  "s%            'observedAt' => \$listing->observedAt,%%"

run_sabotage "the car email adapter stamps cards at the pass time (the message date is dropped)" \
  src/php/Car/VehicleEmailSource.php \
  's%            observedAt: $message->sentAt(),%            observedAt: null,%'

# Q36, the car analog, and the seed that makes it safe.
run_sabotage "the car seed no longer marks the market as notified (the next pass pushes it all)" \
  src/php/Car/VehiclePipeline.php \
  's%                    $this->store->markNotified($sighting->dedupKey, $nowIso);%                    /* seed marks nothing */%'

run_sabotage "the car CLI runs on an empty seen-set (the whole Autohero catalogue would push at once)" \
  src/php/Car/Cli/CarScout.php \
  's%        if (!$seed \&\& $store->isSeenSetEmpty()) {%        if (false) {%'

# The sitemap source's whole economics: fetch only what the seen-set does not know.
run_sabotage "the sitemap source ignores the seen-set (every pass re-fetches the whole catalogue)" \
  src/php/Car/SitemapVehicleSource.php \
  's%            if (isset($known\[$id\])) {%            if (false) {%'

# Health baselines on the FEED. Recording the novel slice made the first live pass a false warn_drop.
run_sabotage "the car pipeline baselines a sitemap source's health on its novel lots, not its index" \
  src/php/Car/VehiclePipeline.php \
  's%$itemCount = $source instanceof SitemapVehicleSource ? ($source->lastIndexSize() ?? count($listings)) : count($listings);%$itemCount = count($listings);%'

run_sabotage "a furniture segment with no price and no facts is read as a card again" \
  src/php/Car/VehicleEmailSource.php \
  's%if (\$priceConfigured \&\& \$factsPattern !== null%if (false \&\& \$factsPattern !== null%'

run_sabotage "the sitemap source stops checking robots.txt for lot pages" \
  src/php/Car/SitemapVehicleSource.php \
  's%            $this->refuseUnlessAllowed($url);%            /* unchecked */%'

# The replay runs the source UNTHROTTLED. With the throttle kept, In'li's 2 s × 20 simulated detail
# fetches is 40+ s of sleeping to answer a file (43 s measured) — a repair tool nobody reaches for.
run_sabotage "scout replay --file keeps the adapter's rate limit and sleeps through simulated fetches" \
  src/php/Rent/Cli/RentScout.php \
  's%            $definition->unthrottled(),%            $definition,%'

# ── The generic entry point (2026-08-29) ─────────────────────────────────────────────────────────
# `bin/scout` dispatches on `--domain=` and NEVER defaults. The shape it replaced had rent implicit
# and `--domain=car` special-cased inside the rent CLI, so a deployment that forgot the flag watched
# the wrong domain against the wrong database with a green heartbeat. Both fall-throughs below run
# a real `doctor` — which is why ScoutDispatchTest dispatches into an EMPTY temporary root: there the
# fall-through surfaces as a config refusal carrying the wrong message, and the assertion on the
# message is what distinguishes "refused because no domain" from "ran the rent doctor and refused".
run_sabotage "a missing --domain quietly falls through to the first registered domain" \
  src/php/Cli/Scout.php \
  's%if (\$slugs === \[\]) {%if ($slugs === [] \&\& $rest === []) {%; s%Domains::get(\$slugs\[0\])%Domains::get($slugs[0] ?? Domains::slugs()[0])%'

run_sabotage "an unknown --domain falls through to the first registered one instead of being named" \
  src/php/Cli/Domains.php \
  's%return self::all()\[\$slug\] ?? null;%return self::all()[$slug] ?? array_values(self::all())[0];%'

# The state markers became per-domain in the same change (`rent-heartbeat.txt`). A deployment that
# still carries `heartbeat.txt` is renamed ONCE before anything reads it; without that the first
# start after the redeploy beats immediately and re-serves a digest window it already served.
run_sabotage "the pre-split state markers are never migrated (so the first start after redeploy beats)" \
  src/php/Rent/Cli/RentScout.php \
  's%^        \$this->migrateLegacyMarkers();$%        // migrateLegacyMarkers() disabled%'

run_sabotage "a legacy marker overwrites a newer one already under the per-domain name" \
  src/php/Rent/Cli/RentScout.php \
  's%if (is_file(\$from) \&\& !is_file(\$to)) {%if (is_file($from)) {%'

# Two of the three migrated markers were unpinned: a review panel deleted the `digest.txt` entry of
# the map and the whole suite stayed green.
run_sabotage "the digest window marker is not migrated (re-serves today's window after redeploy)" \
  src/php/Rent/Cli/RentScout.php \
  "s%'digest.txt' => 'rent-digest.txt', %%"

run_sabotage "the startup-refusal note is not migrated (a pre-split refusal is never reported)" \
  src/php/Rent/Cli/RentScout.php \
  "s%, 'last-refusal.txt' => 'rent-last-refusal.txt'%%"

# ── §1 across the two tracks (2026-08-30) ────────────────────────────────────────────────────────
# The cross-track link named a REJECTED (PLS) direct route as the *voie directe* in its agency copy's
# match push. The twin's verdict now feeds the same funnel as the persisted group veto.
run_sabotage "an excluded or undetermined twin on the other track no longer vetoes the flat" \
  src/php/Rent/Cli/Pipeline.php \
  's%if (\$survivor->tenure->isExcluded()) {%if (true) {%'

# The car heartbeat read health WITHOUT the clock, so FEED_SILENT and STALE could never reach the
# beat — the rent side's 2026-08-29 defect, on the twin.
run_sabotage "the car heartbeat reads health without the clock (a silent feed counts as healthy)" \
  src/php/Car/Cli/CarScout.php \
  's%\$s->health(\$this->now())%$s->health()%'

# The fixture-secrets guard decodes quoted-printable before it looks; without that, a JWT in any real
# .eml fixture reads `=3DeyJ…` and `\beyJ` never matches.
# THE EXPRESSION USED TO MUTATE THE ASSERTION, NOT THE CASCADE (2026-09-06). It read
# `s%quoted_printable_decode($content)%$content%` against FixtureSecretsTest, which turned that
# test's own assertion into `assertContains($content, $forms)` — trivially true, since the raw
# bytes are always scanned. So it seeded nothing and reported `undetected` on four nightlies.
# `tests/test-sabotage-applies.sh` could not see it: the string still MATCHED, it had simply
# stopped naming the guarantee once the cascade moved to `Core\RecoverableForms` (2026-09-05) —
# the "extracting a class orphans its sabotage" lesson, in the shape that survives the check for it.
#
# BOTH decodes must go, JOINED BY `;` INTO ONE ARGUMENT — `run_sabotage` binds `expr="$3"` and
# reads no further, so a second quoted expression is silently DISCARDED and the case reports
# `undetected` with half the mutation applied. Measured while writing this: two arguments left the
# suite green for exactly that reason.
# `$headersUnfolded` equals `$message` for any content with no folded
# headers — which is exactly the self-test's content — so removing line 41 alone leaves the
# identical quoted-printable form in `$forms` via line 42, and the assertion stays green.
run_sabotage "the fixture-secrets guard is blind to quoted-printable again" \
  src/php/Core/RecoverableForms.php \
  's%quoted_printable_decode(\$message)%$message%;s%quoted_printable_decode(\$headersUnfolded)%$headersUnfolded%'

# ── The twin fact is PERSISTED (schema v12, 2026-08-30, panel round 2) ─────────────────────────
# A veto living only in the pass's harvest lapsed the moment the twin was not fetched: the pass
# that saw the agency copy alone pushed the PLS flat. The fact is written whenever a twin is in
# hand and read back when it is not.
run_sabotage "the persisted twin fact is never read back (a veto lapses when the twin is not fetched)" \
  src/php/Rent/Cli/Pipeline.php \
  's%foreach (\$memberKeys as \$readKey) {%foreach ([] as $readKey) {%'

run_sabotage "an excluded twin fact is overwritten by a later eligible reading (the veto is not durable)" \
  src/php/Rent/Store/Store.php \
  's%if (\$current !== null \&\& \$current\[.tenure.\]->isExcluded()) {%if (false) {%'

# SCOPED to reclassify() — see the drain's pair above.
run_sabotage "reclassify re-judges a row its twin vetoed, on a snapshot the twin cannot appear in" \
  src/php/Rent/Cli/RentScout.php \
  '/private function reclassify(/,$ s%\$twin = \$store->twinTenure(\$key);%$twin = null;%'

# The encoding after quoted-printable: a base64 BODY hides the token in opaque 76-column lines.
# Retargeted 2026-09-05 (C2 round 6): the block scan moved into the shared cascade.
run_sabotage "the fixture-secrets guard is blind to a base64 body again" \
  src/php/Core/RecoverableForms.php \
  's%, \$text, \$blocks) > 0) {%, $text, $blocks) > 0 \&\& false) {%'

# ── Round 3 (2026-08-30): the fact on every member, the own reading durable ───────────────────
run_sabotage "the twin fact is written on the survivor's row only (an absorbed copy never learns it)" \
  src/php/Rent/Cli/Pipeline.php \
  's%foreach (\$memberKeys as \$memberKey) {%foreach ([$dedupKey] as $memberKey) {%'

run_sabotage "a row's own excluded reading is forgotten when today's evidence is thinner" \
  src/php/Rent/Cli/Pipeline.php \
  's%\$previousTenure = \$this->store->tenure(\$sighting->dedupKey);%$previousTenure = null;%'

run_sabotage "the row records its own reading, not the judged verdict (the drain cannot say what to verify)" \
  src/php/Rent/Cli/Pipeline.php \
  's%if (\$judged !== \$classification) {%if (false) {%'

# Round 4, 2026-08-31. The durable own reading was restored for the SURVIVOR only, so an absorbed
# member's stored tenure was torn down and never rebuilt — and `groupExcludedTenure()` reads that
# same column. Putting the raw reading back in either place is the exact regression.
run_sabotage "an absorbed member's durable excluded reading is torn down by today's raw one" \
  src/php/Rent/Cli/Pipeline.php \
  's%\$own = \$this->durableOwnReading(\$classification, \$previousTenure);%$own = $classification;%'

run_sabotage "the twin scan reads the twin's raw reading instead of its durable one" \
  src/php/Rent/Cli/Pipeline.php \
  "s%'classification' => \\\$own\\]%'classification' => \$classification]%"

# 2026-08-31, found in production by the developer reading a notification. Bien'ici cards start with
# their photo line, and a card with NO photo starts `Pas de photo [...]` — so a literal `\nPhoto\n`
# merged it into the card above and ONE listing came out carrying the previous card's commune, rent
# and surface under this card's link. Going back to a literal split is the regression.
run_sabotage "a regex card separator falls back to a literal split (a photo-less card merges upward)" \
  src/php/Rent/Adapters/EmailAlertSource.php \
  's%\$segments = preg_split(\$separator, \$message->body);%$segments = explode("\\nPhoto\\n", $message->body);%'

# Track 1f. A rent implausible for its claimed surface — a room advertised with the whole flat's
# size, or a surface read off a garden — passes every numeric filter, because each number is
# individually plausible and only their RATIO is not. Disabling the check is the regression.
run_sabotage "an implausible price per m² stops routing to the digest" \
  src/php/Rent/Core/CriteriaEngine.php \
  's%if (\$ppm !== null \&\& \$this->criteria->minPricePerM2 !== null \&\& \$ppm < \$this->criteria->minPricePerM2) {%if (false) {%'

# Track 1f, the other half: the guard is `> 0`, not `!== null`. Fifteen stored Logirep rows carry
# surface 0 and rooms 0 for parkings, so a null-check alone divides by zero and takes down the pass.
run_sabotage "the price-per-m² guard accepts a zero surface and divides by zero" \
  src/php/Rent/Core/CriteriaEngine.php \
  's%\$surface === null || \$surface <= 0.0%$surface === null%'

# Round 6. The twin veto was not TRANSITIVE: each twin contributed its own durable reading plus its
# own group veto, but never its own TWIN-derived verdict — so an exclusion that reached a listing
# THROUGH a twin never reached that listing's OTHER twins, and a second copy of a flat rejected as
# PLS was pushed as a match naming the rejected route. The graph is resolved to a fixed point before
# any judging; disabling the propagation is the regression.
# THE SECOND HALF DISABLES `excludedDwellings()`, and that is why it is here: the broadest route
# shadows this one, so mutating the propagation alone leaves the suite green — measured 2026-09-07.
# Same shape as the pipeline-veto and twin-group-veto cases above, which already said so; this one
# did not, and a reader could not tell a shadow-remover from a second guarantee.
run_sabotage "the twin veto stops being transitive (a third copy of a rejected flat is pushed)" \
  src/php/Rent/Cli/Pipeline.php \
  's%if (\$twinRank(\$other\['"'"'tenure'"'"'\]) > \$twinRank(\$mine\['"'"'tenure'"'"'\])) {%if (false) {%; s%\$excludedDwellings = \$this->store->excludedDwellings();%\$excludedDwellings = [];%'

# Round 5, RETARGETED in round 6: the twin's own GROUP veto moved into the graph-resolution
# pass, where each node's BASE reading is computed. Same guarantee, new home. It is the only thing
# reaching an excluded tenure held on an absorbed sibling OF THE TWIN's cluster, and nothing covered
# it — mutating it to `null` left the whole suite green while a PLS flat's agency copy was pushed.
run_sabotage "the twin scan ignores the twin's own group veto (an absorbed PLS sibling stops vetoing)" \
  src/php/Rent/Cli/Pipeline.php \
  's%\$key === null ? null : \$this->store->groupExcludedTenure(\$key),%null,%; s%\$excludedDwellings = \$this->store->excludedDwellings();%\$excludedDwellings = [];%'

# Round 5. All three §1 vetoes decode a stored `Tenure`, and `tryFrom()` returns null for a value it
# cannot parse exactly as for a genuinely NULL column — so one case-flip or enum rename released the
# row's own durable reading, the group veto and the twin veto together, silently and in the direction
# §1 forbids. Going back to a silent null is the regression.
run_sabotage "a corrupt stored tenure reads as 'nothing was ever said' and releases the §1 veto" \
  src/php/Rent/Store/Store.php \
  's%if (\$raw === null || trim(\$raw) === \x27\x27) {%if (true) {%'

# The READ side of the same claim. The existing case mutates this loop to `foreach ([] as $readKey)`,
# which proves the fact is read at ALL — not that it is read ACROSS the cluster. Reducing it to the
# survivor's own key left the whole suite green until round 4.
run_sabotage "the twin fact is read off the survivor's own row instead of across the cluster" \
  src/php/Rent/Cli/Pipeline.php \
  's%foreach (\$memberKeys as \$readKey) {%foreach ([$dedupKey] as $readKey) {%'

# Round 4. The refusal note was read AND DELETED above the `isDue()` test, so a restart inside the
# heartbeat interval — the ordinary state of a fix-and-redeploy — destroyed it with no beat to carry
# it. Anchored on the 8-space indent, which is the STARTUP site; the in-loop one is deeper.
run_sabotage "a startup refusal is consumed before anything can report it" \
  src/php/Rent/Cli/RentScout.php \
  's%^        if (\$heartbeat->isDue(\$this->lastHeartbeat(), \$this->now())) {%        $_sab = $this->pendingRefusal(); $this->clearLastRefusal();\n        if ($heartbeat->isDue($this->lastHeartbeat(), $this->now())) {%'

# Round 4. The car beat sat after the work INSIDE the pass closure, which `WatchLoop` wraps in its
# own try — so a throwing pass took the liveness signal with it. Anchored on the 28-space indent,
# which is the IN-LOOP call; the startup one is at 12.
run_sabotage "a car pass that throws takes the heartbeat down with it" \
  src/php/Car/Cli/CarScout.php \
  's%^                            \$beat();%                            /* silenced */;%'

# Retargeted in round 4: the per-line WIDTH floor is gone (a 19-column fold slipped past it), so the
# guarantee is now "no floor at all". Reintroducing one is the regression.
# Retargeted again in C2 round 6: the block scan moved out of the test and into the ONE shared
# cascade, `Scout\Core\RecoverableForms`, which the guard and `tools/scrub-eml.php` both call.
run_sabotage "the shared cascade reintroduces a per-line width floor on a base64 body" \
  src/php/Core/RecoverableForms.php \
  's%9+/]+={0,2}%9+/]{40,}={0,2}%'

run_sabotage "a car startup refusal is not recorded for the next beat" \
  src/php/Car/Cli/CarScout.php \
  's%@file_put_contents(\$this->stateFile(.car-last-refusal.txt.), \$this->now()%@file_put_contents("/dev/null", $this->now()%'

# F23 — the title pattern's price guard. Refusing a candidate that merely CONTAINS a € makes
# exclude_title_patterns inert across SeLoger's whole `Baisse de prix` template; refusing NOTHING
# makes the rent line itself the title, which is the worse half. Both directions must redden.
# R6-1 — the commune reader's digit rule. Forbidding digits in the name loses every Paris
# arrondissement while the listing still matches on its postcode: a null commune, no S1 score, a
# weaker dedup key and a push that cannot say where the flat is. Silent, which is why it needs this.
run_sabotage "the commune reader forbids digits again (every Paris arrondissement loses its name)" \
  config/rent/sources.json \
  's%(\[^\\\\n\\\\d(\]\[^\\\\n(\]{1,59}?)%([^\\\\n\\\\d(]{2,60}?)%'

run_sabotage "the title pattern refuses any candidate carrying a euro sign again (a whole template goes untitled)" \
  config/rent/sources.json \
  's%(?!\[\\\\h\\\\d.,\]\*€\[\\\\h/a-zA-Zéè\]\*\$)(\[^\\\\n\]{2,80}?)%([^\\\\n€]{2,80}?)%'

# Track 5b, F-B. The detail page is what supplies In'li's postcode on the 46 % of its listings whose
# URL slug omits one, and in region mode the postcode is the ENTIRE location filter — so dropping it
# from the merge takes 212 live rows straight back to 212 rejections and 0 matches, with the source
# still reporting a healthy count. The map still reads the page; the value simply never arrives.
run_sabotage "a detail page's postcode never reaches the listing (region mode rejects what it filled)" \
  src/php/Rent/Core/RawListing.php \
  's%postcode: \$any(\$this->postcode, \$detail->postcode),%postcode: \$this->postcode,%'

# The FUEL preference had no ledger coverage at all until 2026-09-01, when the developer noticed
# diesels in their pushes and the weight was deepened 10→20. Like the brand penalty below it, its
# whole value is an ORDERING: every score stays plausible, the notification still lists a reason,
# and only the ranking is wrong. Giving diesel the preferred share is the way that fails.
run_sabotage "diesel earns the full fuel share (the preference dissolved, every ranking still plausible)" \
  src/php/Car/VehicleScorer.php \
  "s%'essence', 'hybride', 'electrique' => 1.0,%'essence', 'hybride', 'electrique', 'diesel' => 1.0,%"

# TRACK 1d — the brand penalty. Its whole value is an ORDERING, and an ordering fails silently:
# every score stays plausible, the notification still lists a reason, and only the ranking is wrong.
run_sabotage "an avoided make earns the brand share anyway (the penalty inverted back into a reward)" \
  src/php/Car/VehicleScorer.php \
  "s%        } elseif (\$criteria->isAvoidedBrand(\$car->make)) {%        } elseif (\$criteria->isAvoidedBrand(\$car->make)) {\n            \$score += \$w['brand'];%"

run_sabotage "an unextracted make is rewarded the full brand share (a fact manufactured from its absence)" \
  src/php/Car/VehicleScorer.php \
  "s%            \$reasons\[\] = 'marque inconnue — hors score';%            \$score += \$w['brand']; \$reasons[] = 'marque inconnue — hors score';%"

run_sabotage "no brand preference configured silently shrinks the scale to 90 (high_priority_score unreachable)" \
  src/php/Car/VehicleScorer.php \
  "s%            \$score += \$w\['brand'\]; // unique on purpose: the ledger addresses this arm by this line%%"

# TRACK 1j — the rooms reader's LEFT anchor. Losing it reads hex out of a photo URL's UUID as a
# room count: measured over the store, 20 rows read a number the listing never stated, 4 genuine
# 3-pièces flats were rejected by min_rooms and 6 were notified with a fabricated count.
run_sabotage "the T3/F4 rooms branch loses its left anchor (hex in a photo UUID becomes a room count)" \
  src/php/Rent/Adapters/EmailAlertSource.php \
  "s%(?<!\[A-Za-z0-9\])(?:T|F)%(?:T|F)%"

run_sabotage "a brand that folds to nothing is accepted (a configured preference that matches no make)" \
  src/php/Car/VehicleCriteriaLoader.php \
  "s%            if (\$folded === '') {%            if (false) {%"

# The stem boundary, both directions. Reverting to exact equality is the defect that was MEASURED:
# `ds` caught leboncoin's row and silently missed autohero's `ds automobiles`, so a Stellantis car
# outranked a Toyota by 10 points on one source only. Dropping the boundary check is the opposite
# failure — the stem then reaches any longer word beginning the same way, penalising a make nobody
# listed. Both are silent: every score stays plausible and only the ordering is wrong.
run_sabotage "an avoided marque is matched by exact equality again (a suffixed spelling escapes the list)" \
  src/php/Car/VehicleCriteria.php \
  "s%            if (!str_starts_with(\$folded, \$stem)) {%            if (true) {%"

run_sabotage "the stem loses its boundary check (it reaches any longer word that merely starts the same)" \
  src/php/Car/VehicleCriteria.php \
  "s%            if (!ctype_alpha(\$folded\[\\\\strlen(\$stem)\])) {%            if (true) {%"

# The pattern-miss ratio's denominator. A furniture segment counted as a card dilutes it, and the
# WARN fires only at 100 %% — so a pattern that has genuinely stopped matching every real card can
# report short of it and say nothing, which is the silence Track 1h exists to end.
run_sabotage "a segment that never became a card still counts toward the pattern-miss ratio" \
  src/php/Rent/Adapters/EmailAlertSource.php \
  "s%        \$this->patternMisses->resolve(\$listing !== null);%        \$this->patternMisses->resolve(true);%"

# ── Track 5a: WHO advertises decides which profile judges the flat (2026-09-01) ────────────────
#
# 23 SeLoger rows advertised by an institutional landlord were judged LIBRE at 50bp and 21 were
# pushed as a MATCH, while the same flat on that landlord's own site digests. Every case below is a
# way of quietly restoring that, and each is silent in production: nothing throws, nothing is
# logged, and a listing simply arrives that should not have.

# The substitution itself. Removing it is the whole defect back, and it is one line.
run_sabotage "the advertiser never substitutes the profile (a landlord's flat keeps the portal's lax default)" \
  src/php/Rent/Core/TenureClassifier.php \
  "s%        \$source = LandlordRegistry::effectiveProfile(\$source, \$listing->advertiser, \$this->profileFor);%        \$source = \$source;%"

# The tightening guarantee. Without it a resolver returning the loosest profile the schema permits
# LOOSENS a strict source — §1 calls such a reachable path a P0 even when nothing sets it.
run_sabotage "the substitution takes the landlord's profile wholesale, so it can LOOSEN a strict source" \
  src/php/Rent/Core/LandlordRegistry.php \
  "s%        return self::stricterOf(\$source, \$profileFor(\$key) ?? self::unknownLandlordProfile((string) \$advertiser));%        return \$profileFor(\$key) ?? self::unknownLandlordProfile((string) \$advertiser);%"

# ── F27: the extraction-miss signal on the CAR domain (2026-09-01) ─────────────────────────────
#
# The rent adapter counted misses from 2026-08-31; the other four adapters counted nothing, so the
# fix for "PAP ran four days dark" reproduced that exact state on three car sources. Measured before
# these existed: 13 of 99 stored ParuVendu rows carry body+fuel+year+mileageKm all null — one
# facts_pattern miss, four fields dark — while `scout --domain=car doctor` reported `ok`.
#
# Every case below is silent in production. Nothing throws, no count moves, the feed keeps arriving,
# and the fields simply come back null.

# The recording itself, on the pattern that proved the gap was real.
run_sabotage "the car facts_pattern stops being counted (four fields go dark and nothing says so)" \
  src/php/Car/VehicleEmailSource.php \
  "s%            \$this->missed('facts_pattern', \$hit);%%"

# The report. Counting a miss and never surfacing it is hard rule 2's own shape — an alert computed
# and never sent is worse than none, because someone believes the green.
#
# RETARGETED 2026-09-02: the decoration this mutated was an INLINE COPY here, and the C2 round-1 fix
# extracted it to `PatternMissLog::escalate()` -- it was duplicated verbatim in the rent twin and
# absent from three more adapters. The expression named nothing that MOVED, so it went INERT and
# `test-sabotage-applies.sh` caught it: the `4d49eda` failure class, hit one commit after the commit
# message describing it. The guarantee is unchanged -- this adapter ROUTES through the shared
# escalation -- and deafening the escalation itself is a separate case on that class.
run_sabotage "a car pattern that matched nothing never reaches health() (counted, never reported)" \
  src/php/Car/VehicleEmailSource.php \
  's%        return \$this->patternMisses->escalate(%        return (fn (\$h) => \$h)(%'

# Per-pass, never cumulative. Without the reset a template already fixed keeps warning, which sends
# an operator to read a capture that is fine and teaches them to ignore the signal — a failure worse
# than silence, because it is credible.
run_sabotage "the car miss count accumulates across passes (a fixed template keeps warning)" \
  src/php/Car/VehicleEmailSource.php \
  "s%        \$this->patternMisses->reset();%%"

# The denominator, car twin of the rent case above. This source has a DOCUMENTED furniture segment —
# the tail carrying the last card's CTA link — so counting it adds one permanent miss per message,
# and the WARN fires only at 100 %%.
run_sabotage "a car segment that never became a card still counts toward the pattern-miss ratio" \
  src/php/Car/VehicleEmailSource.php \
  "s%                \$this->patternMisses->resolve(\$card !== null);%                \$this->patternMisses->resolve(true);%"

# ── F24 / T5B-9: a SeLoger card that states no room count (2026-09-01) ─────────────────────────
#
# The title anchor was the `pièces` line ALONE, so a card stating no room count had no anchor at all
# and the title came back ''. Nothing matches an empty string, so every `exclude_title_patterns`
# entry was inert — and the anchored `chambre` rule and the parking/box/garage family have NO second
# surface, unlike `colocation`, which fires through the description. Both stored victims are room
# rentals; the Clamart one, quoting the whole house's 140 m² for a single room, was PUSHED AS A
# MATCH on 2026-08-27. Reverting to the single anchor is the defect, exactly.
run_sabotage "the SeLoger title anchor loses its surface branch (a card with no room count goes untitled)" \
  config/rent/sources.json \
  's%(?:\\\\d+\\\\h\*pi\[eè\]ces?\\\\b|\[\\\\d.,\]+\\\\h\*m(?:²|2)\\\\b)%\\\\d+\\\\h*pi[eè]ces?\\\\b%'

# The fail-closed posture for a landlord with no source block. RIVP is predominantly social and has
# no block; guessing LIBRE for an unmeasured bailleur is the §1-dangerous direction.
#
# BOTH fields are flipped, and that is the whole point of this case. Measured 2026-09-01 over the four
# combinations, on a bare card with no tenure statement:
#
#   defaultTenure=null  mixedTenure=true  -> UNKNOWN/DIGEST      defaultTenure=LIBRE mixedTenure=true  -> UNKNOWN/DIGEST
#   defaultTenure=null  mixedTenure=false -> UNKNOWN/DIGEST      defaultTenure=LIBRE mixedTenure=false -> LIBRE/MATCH
#
# The two fields are belt-and-braces: exactly ONE cell reaches a notification, so flipping either
# alone degrades defence in depth without breaching §1 and the suite correctly stays green. A first
# version of this case flipped `mixedTenure` only, reported UNDETECTED, and read as a missing test —
# it was a sabotage that did not sabotage. Never bend a test around a mutation that is not a
# regression; make the mutation reach the unsafe cell.
run_sabotage "an unmeasured landlord is assumed private-market (the one cell that reaches MATCH)" \
  src/php/Rent/Core/LandlordRegistry.php \
  's%^            defaultTenure: null,$%            defaultTenure: Tenure::LIBRE,%; s%^            mixedTenure: true,$%            mixedTenure: false,%'

# CONTAINMENT, not equality. SeLoger writes `IN'LI PARIS EST`; an equality test recognises none of
# the regional spellings, so the whole registry silently stops matching real subject lines.
run_sabotage "advertiser matching becomes exact-equality (every regional spelling stops being recognised)" \
  src/php/Rent/Core/LandlordRegistry.php \
  "s%            if (!str_contains(\$folded, \$name)) {%            if (\$folded !== \$name) {%"

# The snapshot. Without it `reclassify` re-judges on less evidence than the original verdict saw —
# the §1 breach schema v7 exists to prevent, applied to the field that decides the profile.
run_sabotage "the advertiser is dropped from the v7 snapshot (reclassify restores the lax default)" \
  src/php/Rent/Core/ListingSnapshot.php \
  "s%            'advertiser' => \$listing->advertiser,%%"

# The adapter half. A pattern read but never attached is a configured mechanism doing nothing —
# the inert-param defect class this repo has now found on title_pattern twice.
#
# TWO cases, one per attachment site, and both `^`-ANCHORED. `EmailAlertSource` attaches the
# advertiser in `listingsIn()` (non-segmented, 16 spaces) and in `buildCardListing()` (segmented,
# 12 spaces). A single unanchored `s%            advertiser: …%` matches BOTH — sed matches an
# unanchored substring, so the 12-space pattern also matches inside the 16-space line — which is
# what the first version of this case did. It was fully applied, both paths were nulled, and the
# suite stayed green because NEITHER path was tested; a first reading of that mistook it for a
# half-applied expression. Anchored per site, a regression now names the path it broke.
run_sabotage "the advertiser is never attached on the SEGMENTED path (seloger, bienici)" \
  src/php/Rent/Adapters/EmailAlertSource.php \
  's%^            advertiser: \$this->advertiserOf(\$message),$%            advertiser: null,%'

run_sabotage "the advertiser is never attached on the NON-SEGMENTED path (one listing per link)" \
  src/php/Rent/Adapters/EmailAlertSource.php \
  's%^                advertiser: \$this->advertiserOf(\$message),$%                advertiser: null,%'

# The 2026-09-01 store split. The car database carried four EMPTY rent tables and the rent schema
# version, because `VehicleStore` composed the housing store wholesale to reach six generic methods.
# Skipping the cleanup leaves the live machine differing from every fresh deployment — which is how a
# bug reproduces in one place and not another.
run_sabotage "the car database keeps the housing tables an older build left in it" \
  src/php/Car/VehicleStore.php \
  "s%        \$this->dropOrphanedHousingTables();%%"

# The guard that makes the drop safe to ship. Housing rows in a car database mean something this
# design does not understand; dropping data to make a refactor tidy is not a trade available here.
run_sabotage "a NON-EMPTY housing table is dropped instead of refusing" \
  src/php/Car/VehicleStore.php \
  "s%            if (\$rows > 0) {%            if (false) {%"

# The composing store must run the generic store's MIGRATION, not merely its DDL. Calling `ddl()`
# creates the tables and skips `run_meta`, so the database ends up with the generic tables and no
# record of their version — and a future RunStore v2 never migrates it. This shipped exactly once,
# green across 2536 tests, and was found by querying production after the deploy.
run_sabotage "the composing store creates the generic tables but records no version for them" \
  src/php/Rent/Store/Store.php \
  "s%        \$this->runs->migrate();%        RunStore::ddl(\$this->pdo);%"


# ── Track 6-A6: a surface read out of a base64url TRACKING TOKEN (2026-09-02) ──────────────────
#
# SEVENTH instance of *URLs are classified text*, and the SECOND poisoning of the same
# first-match-wins scan: Track 1j anchored the rooms branch against hex photo UUIDs with
# `(?<![A-Za-z0-9])`, and base64url walks straight past it because `-` and `_` are not alphanumeric.
# The live row of 2026-09-02T05:24:51Z stored 7 m² for a flat whose own card says `64,25 m²`,
# because a `click.by.seloger.com/?qs=` token reads `…zaw7m29jtx…` at offset 1029 and the real
# figure sits at 1948. Measured over 2 043 stored bodies: 26 surfaces and 4 room counts wrong, all
# seloger, SEVEN of them matches that were never notified at all and six pushed with no surface.
run_sabotage "the generic readers scan a URL's query again (a tracking token becomes the surface)" \
  src/php/Rent/Adapters/EmailAlertSource.php \
  's%return preg_replace(self::URL_NOISE, .\$1., \$body) ?? \$body;%return $body;%'

# THE SCOPE GUARD, and it fails in the opposite direction. `RawListing::text()` already rules this
# split for the classifier — drop the query and fragment, KEEP THE PATH — because `?c=plai_plus` is
# a campaign string nobody can rewrite while a `plai` path SEGMENT is a real social signal. Widen
# the strip to blank whole URLs and a surface stated in a path is lost.
run_sabotage "the URL strip widens from query-and-fragment to the whole URL (the path is prose)" \
  src/php/Rent/Adapters/EmailAlertSource.php \
  's%self::URL_NOISE, .$1., $body%self::URL_NOISE, "", $body%'

# THE COUNTERWEIGHT THAT MATTERS MOST: the LINK readers never see the stripped body. For seloger the
# whole URL *is* the query, so stripping it there empties every notification's link — and on bienici
# and leboncoin, whose identity IS the link, it re-keys the entire stored backlog and re-notifies
# every flat already seen.
run_sabotage "the URL strip leaks into the link reader (every notification loses its link)" \
  src/php/Rent/Adapters/EmailAlertSource.php \
  's%, $text, $matches);%, self::prose($text), $matches);%'

# ── Track 6-A4: a portal's "I don't know" token stored as a MAKE (2026-09-02) ──────────────────
#
# ParuVendu writes `/voiture-occasion/autres/autres/` when it cannot name the marque. The capture
# succeeds, so nothing reads as a fault — and `autres` then matches no `brand_avoid` stem, so the
# car earns the whole 10-point brand share. The live row was `Ds Ds4 E-tense 225ch Performance
# Line`: a DS, which IS on the avoid list. Not the unknown-make arm; a wrong answer wearing one.
run_sabotage "a portal's unknown-marque token is stored as a make again (the DS4 keeps the brand share)" \
  src/php/Car/VehicleEmailSource.php \
  "s%\$sentinel = \$this->definition->param('make_model_unknown_pattern');%\$sentinel = null;%"

# THE EMPTY DECLARATION. `PATTERN_PARAMS` skips an empty value, so without this refusal an empty
# sentinel compile-checks clean and nulls nothing at all — a disabled feature dressed as a
# configured one, and the `detail_budget_per_pass: 0` precedent.
run_sabotage "an empty sentinel loads instead of being refused (configured, and inert)" \
  src/php/Car/VehicleSourceLoader.php \
  "s%if (\$params\['make_model_unknown_pattern'\] === '') {%if (false) {%"

# THE COUNTERWEIGHT, and it fails in the opposite direction. The sentinel is subtracted from
# `VehicleEmailPatternMissTest`'s reflection guard, so a `missed()` call added here would pass that
# test — and put every CORRECTLY-read card in the denominator, holding the ratio near 100 % for
# ever. F30's shape: a signal that fires always says nothing.
run_sabotage "the sentinel is counted as an extraction miss (every named marque becomes a miss)" \
  src/php/Car/VehicleEmailSource.php \
  "s%if (\$sentinel !== null) {%if (\$sentinel !== null) { \$this->missed('make_model_unknown_pattern', false);%"

# ── Track 6-C2 round 1, the P2 batch (2026-09-04) ─────────────────────────────────────────────
#
# COR-F4 — the surface reader had no LEFT ANCHOR, so a digit in the middle of a token beat the real
# figure below it. The query strip closed one alphabet; the PATH is deliberately kept, and that is
# where it still bit. Not hypothetical: Bien'ici's own photo host is `d2m2j20yzublln.cloudfront.net`
# — `2m2` reads as 2 m² — and four stored flats of 41, 54, 65 and 59 m² are held at 2 m², three of
# them silently rejected by `min_surface_m2: 50`. Silent over-rejection is the failure nothing can
# see, because nothing arrives.
run_sabotage "the surface reader loses its left anchor (a CDN host id becomes the surface)" \
  src/php/Rent/Adapters/EmailAlertSource.php \
  "s%private const string SURFACE_PATTERN = '~(?<!\[A-Za-z0-9\])%private const string SURFACE_PATTERN = '~%"

# COR-F3 — the *à vérifier* rollup is §1's only landing zone, and its title spoke for every entry
# while the bin had grown a SECOND entrance. A listing digested for an implausible rent-to-surface
# ratio is typically `LLI` at full confidence: announcing it "au régime indéterminé" asserts as
# doubtful a regime the classifier settled. The clause is earned by every entry or by none.
run_sabotage "the digest title claims a regime the batch determined (the clause stops being earned)" \
  src/php/Rent/Notify/Formatter.php \
  "s%(\$everyEntryIsATenureDoubt ? ' au régime indéterminé' : '')%' au régime indéterminé'%"

# THE COUNTERWEIGHT, failing the other way. Dropping the clause unconditionally is not the fix — it
# is a quieter digest, not a truer one, and it would leave the §1 bin unnamed on the phone screen
# where the decision to open it is made.
run_sabotage "the regime clause is dropped even from a batch that earned it (quieter, not truer)" \
  src/php/Rent/Notify/Formatter.php \
  "s%(\$everyEntryIsATenureDoubt ? ' au régime indéterminé' : '')%''%"

# AND THE DRAIN'S HALF. `scout digest` reads the store rather than the pass, so the cause has to
# come off the stored verdict. Read every row as a tenure doubt and the title is confidently wrong
# again on exactly the rows the price branch put there.
run_sabotage "the digest drain calls every stored row a tenure doubt (the store's verdict ignored)" \
  src/php/Rent/Cli/RentScout.php \
  "s%\$cause = (\$storedTenure === null || \$storedTenure === Tenure::UNKNOWN->value)%\$cause = (true)%"

# CMP-1 — the ntfy badge test used single-quoted `\\u{}`, so it asserted a 21-byte ASCII stand-in no
# production path can emit. Now that it runs the real emoji, breaking `headerSafe()` must redden it.
# Before the fix this sabotage was undetectable: the ASCII stand-in survives any header sanitiser.
run_sabotage "headerSafe strips non-ASCII from a title (the shipped emoji badge never reaches the phone)" \
  src/php/Core/Notify/NtfyChannel.php \
  "s%return trim(preg_replace('~\[\\\\r\\\\n\]+~', ' ', \$value) ?? '');%return trim(preg_replace('~[^\\\\x20-\\\\x7E]+~', ' ', \$value) ?? '');%"

# ── COR-F5: a doubt is resolved by positive evidence, never by a source default (2026-09-04) ────
#
# "Otherwise the last reading wins" was too generous in ONE direction: a third route that never saw
# the route which raised the doubt could erase a recorded `UNKNOWN` with the weakest signal the
# classifier has — the tier-5 source default, whose own documented property is that an ABSENT signal
# must lower confidence rather than inherit `default_tenure`. Proven against In'li, which CLAUDE.md
# records as NOT pure LLI.
run_sabotage "a source default resolves a persisted twin doubt again (absence read as evidence)" \
  src/php/Rent/Store/Store.php \
  "s%            && \$confidenceBp < self::TWIN_DOUBT_MIN_CONFIDENCE) {%            \&\& false) {%"

# THE COUNTERWEIGHT, and it fails in the §1-dangerous direction. Gating every direction rather than
# only the resolving one turns the fix into a general "ignore weak readings" rule — and a weak PLS
# then never lands at all, which is the exclusion being dropped on the floor.
#
# ONE LINE, not two: `sed` does not match across lines, and the two-line form this was first
# written as applied to nothing at all while reporting no error — the shape
# `tests/test-sabotage-applies.sh` exists to catch, caught here by trying it first instead.
run_sabotage "the confidence gate is applied to an EXCLUDED reading too (a weak PLS never lands)" \
  src/php/Rent/Store/Store.php \
  "s%            && !\$tenure->isExcluded()%            \&\& true%"

# AND THE PIPELINE'S HALF. The gate is only as good as the number reaching it: the fixed point
# copies whole entries so the confidence belongs to the tenure that actually won. Send a constant
# instead and every reading looks like evidence — the store's guard intact and inert.
run_sabotage "the pipeline sends a fixed confidence with the twin fact (the gate goes inert)" \
  src/php/Rent/Cli/Pipeline.php \
  "s%\$seen\['source'\], \$seen\['bp'\]);%\$seen['source'], 100);%"

# ── The MAPPED path carries NO band, and the guarantee INVERTED on 2026-09-04 ──────────────────
#
# A band was added here that morning and removed the same day after failing on BOTH bounds. Every
# downstream guard reads a rent as `!== null`, so on a single labelled value "outside the band" and
# "not stated" are the same input: nulling does not reject the listing, it deletes the evidence the
# guard needed.
#
#   - the CEILING skipped `max_rent_cc` (guarded `$rentCc !== null`): 25 000 € REJECT became MATCH,
#     with the push saying "loyer non communiqué" about a rent the portal communicated. SHIPPED.
#   - the FLOOR skipped the price-per-m² branch (`pricePerM2()` returns null on a null rent):
#     {119 €, 60 m²} DIGEST became MATCH. Caught in review.
#
# So these two cases REINTRODUCE a band rather than remove one — the previous pair sabotaged the
# band's presence and went inert when it was deleted. A guarantee that inverts needs its ledger
# cases inverted with it; retargeting the file alone would have pinned the defect.
run_sabotage "a FLOOR is reintroduced on the mapped path (the price-per-m2 branch is skipped)" \
  src/php/Rent/Adapters/ListingMapper.php \
  "s%\$rent = \$this->noted('rent', \$map->rent, Payload::int(\$item, \$map->rent));%\$rent = \$this->noted('rent', \$map->rent, Payload::int(\$item, \$map->rent)); \$rent = (\$rent !== null \&\& \$rent >= 200) ? \$rent : null;%"

run_sabotage "a CEILING is reintroduced on the mapped path (max_rent_cc is skipped)" \
  src/php/Rent/Adapters/ListingMapper.php \
  "s%\$rent = \$this->noted('rent', \$map->rent, Payload::int(\$item, \$map->rent));%\$rent = \$this->noted('rent', \$map->rent, Payload::int(\$item, \$map->rent)); \$rent = (\$rent !== null \&\& \$rent <= 20000) ? \$rent : null;%"

# THE TIER-1 §1 SIGNAL, and the last configured key in `ListingMapper` counting nothing until C2
# round 5 found it — independently, by two lenses. A dead `tenure_field` selector does not fail: the
# key is absent, the classifier falls through to the SOURCE DEFAULT, and a mixed-stock portal judges
# every listing by its most optimistic assumption while `SourceHealth` stays `ok`.
run_sabotage "a dead tenure_field selector is counted by nobody (the tier-1 signal goes dark)" \
  src/php/Rent/Adapters/ListingMapper.php \
  "s%\$declared = \$this->noted('tenure_field', \$map->tenureField, Payload::string(\$item, \$map->tenureField));%\$declared = Payload::string(\$item, \$map->tenureField);%"

run_sabotage "a dead rent selector is counted by nobody (max_rent_cc goes off for the source)" \
  src/php/Rent/Adapters/ListingMapper.php \
  "s%\$rent = \$this->noted('rent', \$map->rent, Payload::int(\$item, \$map->rent));%\$rent = Payload::int(\$item, \$map->rent);%"

# ONE IMPLEMENTATION, not two copies of the same numbers. Give the email reader its own band back
# and the two are free to drift — silently, on whichever side is not edited next.
run_sabotage "the email reader carries its own copy of the band again (the two can drift)" \
  src/php/Rent/Adapters/EmailAlertSource.php \
  "s%\$banded = Payload::plausibleRent(\$value);%\$banded = (\$value !== null \&\& \$value >= 1 \&\& \$value <= 20000) ? \$value : null;%"

# ── C2 round 2: every accepted `*_pattern` param compiles at load (2026-09-04) ──────────────────
#
# The eight keys were an inline literal and six had no test at all, so deleting `advertiser_pattern`
# — the §1-relevant one, feeding `Core\LandlordRegistry` — left the whole suite and this whole
# ledger green. `matchParam()` reads them with `@preg_match`: one that does not compile never
# matches and never says so, and on that key the silence re-opens exactly the hole the registry
# closes.
#
# THE PER-KEY TESTS ALONE CANNOT CATCH THIS. Their data provider reads the very list being deleted
# from, so removing an entry removes its own case and seven of seven pass — measured. The guard that
# bites is the SET assertion against `EMAIL_ALERT_PARAMS`, two independent lists neither derived
# from the other.
run_sabotage "a regex param drops off the compile-check list (a broken one then matches nothing, silently)" \
  src/php/Rent/Config/ConfigLoader.php \
  "0,/^        'advertiser_pattern',\$/s%^        'advertiser_pattern',\$%%"

# ── C2 round 2: two eligible twins must not be separated by harvest order (2026-09-04) ──────────
#
# `twinClassification()`'s `$seen` loop replaced only on a STRICT rank increase, so two eligible
# twins tied and the first ITERATED won — and that order is `Core\Pacer`'s shuffle. Cosmetic until
# COR-F5 made the confidence decide whether the store writes at all; after it, the tie decided the
# outcome on identical input. It refutes the claim COR-F5 shipped under, that the change could only
# make the store more careful: it also made it non-deterministic, which is the failure the fixed
# point above that method exists to remove.
run_sabotage "an equal-rank twin tie stops breaking on confidence (the pacer decides again)" \
  src/php/Rent/Cli/Pipeline.php \
  "s%&& \$resolved\['bp'\] > \$seen\['bp'\]%\&\& false%"

# ── C2 round 2: no credential may reach a stack trace (2026-09-04) ──────────────────────────────
#
# PHP prints the first 15 characters of every string ARGUMENT of every live frame. `dump-eml.php`
# was fixed for this and BOTH production paths were left standing — the recurring defect of a
# correct rule applied to a subset of its surfaces, committed by the change that documented the
# threat model. A review panel found the IMAP one; the SMTP one was found by asking what else the
# same question reached, and is worse: the credential was the only string argument, so the whole
# budget went to it whatever the username.
run_sabotage "the IMAP login goes back through the generic command helper (password on a frame)" \
  src/php/Adapters/Mail/ImapMailbox.php \
  "s%            \$this->login();%            \$this->command('LOGIN ' . self::quote(\$this->user) . ' ' . self::quote(\$this->password));%"

run_sabotage "the SMTP password goes back to say() as an argument (base64, 11 chars recoverable)" \
  src/php/Core/Notify/SmtpTransport.php \
  "s%        \$this->sayPassword(\$socket);%        \$this->say(\$socket, base64_encode(\$this->password));%"

# THE SECOND LEVEL, which the first draft of the fix missed. Moving the credential out of the
# helper's parameter list leaves it in `fwrite`'s, and a trace prints built-in frames too; `@`
# suppresses warnings and does nothing to the TypeError a closed stream raises. Remove the wrapper
# and a closed socket puts the base64 credential straight back on the trace.
#
# THE MUTATION IS THE CATCH, NOT THE WRITE, and the first version of this case got that wrong — it
# replaced only the line INSIDE the `try`, so the `catch` survived, the `TypeError` was still
# swallowed and the code was still safe. The case FAILED as an undetected regression against code
# that had not regressed, and `ci.yml` opens a GitHub issue on a red nightly ledger: a scheduled
# alarm for a non-defect, which is hard rule 2 read backwards. Found by a review panel; the author's
# own run never saw it, because that case was one of two the machine SIGKILLed at exit 137 under the
# load of three concurrent reviewers.
#
# `test-sabotage-applies.sh` was green throughout, and correctly: an expression that APPLIES is not
# one that MODELS the defect. Only running the ledger answers that.
run_sabotage "the SMTP credential write stops swallowing the raise (fwrite's own frame carries it)" \
  src/php/Core/Notify/SmtpTransport.php \
  "s%        } catch (\\\\Throwable) {%        } catch (\\\\Throwable \$e) { throw \$e;%"

# ── F20: the durable reading may not claim a provenance the row does not carry (2026-09-04) ─────
#
# `listings.tenure` holds ONE value and no note of where it came from, while the judging loop writes
# the JUDGED classification back onto it — so a group veto's or a twin's excluded tenure is
# laundered into the row's own column, indistinguishable afterwards. The old sentence claimed the
# PLS had been "relevé lors d'une lecture précédente de cette annonce", and a reviewer acted on it:
# they cleared `group_key`, removed the excluded stranger, and the flat stayed rejected by a message
# pointing at a reading that did not take place. Nothing stores the provenance; the fix is to stop
# asserting it. Hard rule 9 at the reason layer.
run_sabotage "the durable reading claims the exclusion was read on this listing again" \
  src/php/Rent/Cli/Pipeline.php \
  "s%') retenu pour cette annonce — '%') relevé lors d\\\\'une lecture précédente de cette annonce — '%"

# ── The car domain's MalformedText arms, and the ORDER they depend on (2026-09-04) ──────────────
#
# Five places in `src/php/Car/` catch `Text::fold()` refusing, and until now not one had a test, so
# nothing said which way each fails — and they do not all fail the same way. The classifier fails
# CLOSED (unfoldable text is REJECT); `VehicleCriteria::excludedBy()` fails OPEN (it returns null,
# which reads as "no user exclusion fired").
run_sabotage "unfoldable car text stops being rejected (the fail-closed arm inverted)" \
  src/php/Car/VehicleClassifier.php \
  "s%return new VehicleClassification(VehicleOutcome::REJECT, \['texte illisible%return new VehicleClassification(VehicleOutcome::MATCH, ['texte illisible%"

# THE ORDER IS THE FINDING. The fail-open arm above is unreachable ONLY because `judge()` returns on
# the classification's REJECT before it ever calls `excludedBy()`. That dependency is real,
# undocumented until today, and one edit from being false: drop the early return and every user
# exclusion silently stops firing on exactly the listings nobody can read.
run_sabotage "the scorer stops rejecting on the classification first (the exclusions go blind)" \
  src/php/Car/VehicleScorer.php \
  "s%if (\$class->outcome === VehicleOutcome::REJECT) {%if (false) {%"

# ── TRACK 7 (developer ruling, 2026-09-08): the heating penalty, the amenity line and the car
#    brand/body model. EVERY guarantee below fails SILENTLY — a score that is 20 points wrong and a
#    display line that says one thing too many both look exactly like a working watcher.

# 7-A. THE NEGATION IS READ FIRST. `sans chauffage individuel` is an ad DENYING the thing; read as a
# statement it costs a real flat 20 points of ordering — off the individual push and into the
# digest — for a fact the copy explicitly refuses. The lift-negation lesson on a new surface.
run_sabotage "a denied heating is read as a stated one (sans chauffage individuel penalised)" \
  src/php/Rent/Core/Heating.php \
  "s%            if (self::isNegated(\$folded, \$at)) {%            if (false) {%"

# 7-A. AN UNSTATED ENERGY IS NOT ELECTRICITY (ruled). The largest class in the store — 101 rows, 24
# matched — states the mode and no energy. Reading it as electric manufactures a fact from an
# absence and applies a surcharge nobody earned; every score stays plausible.
run_sabotage "an unstated heating energy is treated as electric (the surcharge on a silence)" \
  src/php/Rent/Core/Heating.php \
  "s%            return new self(\$mode, \$energy, \$energy === self::ELECTRIC);%            return new self(\$mode, \$energy, true);%"

# 7-A. THE MODE WINDOW BOUNDS THE GAP, NOT THE TEXT. Truncating at MODE_GAP characters cuts
# `individuels` in half and the commonest gas shape (` et eau chaude individuels`, 9 rows) reads
# null — a reader that reads nothing looks exactly like a flat that says nothing. This is the defect
# the first implementation actually shipped with, caught by the store rather than by review.
run_sabotage "the heating mode window truncates the text instead of bounding the gap" \
  src/php/Rent/Core/Heating.php \
  "s%            \$mode = self::modeIn(\$after);%            \$mode = self::modeIn(substr(\$after, 0, self::MODE_GAP));%"

# 7-A. A PENALTY IS NOT PART OF WHAT A LISTING CAN EARN. Enrolling it in the denominator makes it
# SMALLER the larger it is set — the opposite of the intent, and arithmetic nobody re-derives once
# it ships. Scoped to `positiveTotal()` by address so it cannot land on the constructor instead.
run_sabotage "a heating penalty is enrolled in the normalising total (bigger becomes weaker)" \
  src/php/Rent/Config/Weights.php \
  "/function positiveTotal/,/^    }/ s%            + max(0, \$this->freshness);%            + max(0, \$this->freshness) + \$this->heatingIndividual;%"

# 7-B. THE AMENITY LINE MUST BE SILENT WHEN THE AD WAS. A line that always claims a parking is
# furniture, and worse than furniture — it is a fact invented about a flat, the display twin of
# `sans ascenseur` about a building nobody described. Five of the eight sources carry no prose, so
# this fires on most of the tree.
run_sabotage "the amenity line claims a parking on every flat (a fact invented from silence)" \
  src/php/Rent/Core/Amenities.php \
  "s%        return \$seen ? 'parking' : null;%        return 'parking';%"

# 7-B. A MENTION IS NOT AN INCLUSION. Claiming `parking inclus` on a mere mention tells the developer
# the rent covers a space it does not — 37 of the 149 parking-mentioning matches carry the word
# within the inclusion window (93 carry one anywhere in the description; this comment said 79, which
# was neither), and the rest say nothing, so the claim must rest on the word and on nothing else.
run_sabotage "a mentioned parking is announced as included (the claim without the word)" \
  src/php/Rent/Core/Amenities.php \
  "s%        return \$seen ? 'parking' : null;%        return \$seen ? 'parking inclus' : null;%"

# 7-B. URLS ARE CLASSIFIED TEXT — the tenth instance, and `cave` was measured inside a real SeLoger
# tracking token. Nobody can rewrite a portal's analytics parameters, so this is not self-healing.
run_sabotage "the amenity reader scans a URL's tracking parameters again (cave from a token)" \
  src/php/Rent/Core/Amenities.php \
  "s%            \$folded = Text::fold(RawListing::withoutUrlParameters(\$text));%            \$folded = Text::fold(\$text);%"

# 7-C. THE BRAND SHARE IS A THREE-WAY. Giving an unlisted make the share restores the pre-Track-7
# binary and re-opens the gate to the 47 makes the developer ruled out — measured: split C, which
# gave them HALF, pushed 16 of them individually.
run_sabotage "an unlisted make earns the brand share again (the ruled three-way collapsed to two)" \
  src/php/Car/VehicleScorer.php \
  "s%            \$reasons\[\] = trim(\$car->make) . ' — marque hors des listes';%            \$score += \$w['brand']; \$reasons[] = trim(\$car->make) . ' — marque hors des listes';%"

# 7-C. THE NO-PREFERENCE ARM NEEDS BOTH LISTS EMPTY. Keyed on `brandAvoid` alone, a deployment
# configuring ONLY `brand_favour` — the natural way to write *these are the makes I want* — takes
# that arm and awards the share to every make, silently disabling the preference it just wrote down.
run_sabotage "the no-preference arm is keyed on one list (a favour-only config disables itself)" \
  src/php/Car/VehicleScorer.php \
  "s%        if (\$criteria->brandAvoid === \[\] \&\& \$criteria->brandFavour === \[\]) {%        if (\$criteria->brandAvoid === []) {%"

# 7-C. THE FAVOURED SIDE USES THE NON-LETTER-BOUNDARY MATCHER. Exact equality catches `mercedes` and
# silently misses `mercedes-benz` — the `ds` / `ds automobiles` defect facing the other way, worth 25
# points of ordering on a make the developer named, with nothing anywhere reading as a fault.
run_sabotage "the favoured brand list is matched by exact equality (mercedes-benz stops being a mercedes)" \
  src/php/Car/VehicleCriteria.php \
  "/function isFavouredBrand/,/^    }/ s%        return self::matchesStem(\$make, \$this->brandFavour);%        return \$make !== null \&\& in_array(\\\\Scout\\\\Core\\\\Text::fold(\$make), \$this->brandFavour, true);%"

# 7-C. THE BODY SHARE IS FLAT, and that is TWO guarantees needing two mutations. The share must be
# WHOLE (this case) and it must be EQUAL across every listed body (the next). A single case cannot
# reach both: paying every wanted body a third keeps them equal, and paying only the first keeps the
# share whole. A ladder is invisible in any one score, which is why neither is left to a fixture.
run_sabotage "a wanted body earns only part of its share (the share silently shrunk)" \
  src/php/Car/VehicleScorer.php \
  "s%            \$score += \$w\['body'\];%            \$score += (int) (\$w['body'] / 3);%"

run_sabotage "only the FIRST body earns the share (the ruled equality back to a rank)" \
  src/php/Car/VehicleCriteria.php \
  "/function isFavouredBody/,/^    }/ s%        foreach (\$this->bodyFavour as \$wanted) {%        foreach (array_slice(\$this->bodyFavour, 0, 1) as \$wanted) {%"

# 7-C. A MAKE ON BOTH LISTS IS REFUSED AT LOAD. Without the refusal the scorer's avoid-first order
# resolves the clash quietly to *avoided*, and the favoured entry sits in the config doing nothing —
# a configured preference inert while every score stays plausible.
run_sabotage "a make on both brand lists is accepted at load (the favoured entry silently inert)" \
  src/php/Car/VehicleCriteriaLoader.php \
  "s%        \$both = array_values(array_intersect(\$brandAvoid, \$brandFavour));%        \$both = [];%"

# 7-D. THE MEUBLÉ NEGATION GUARD. It changes nothing on today's store by construction — 152 rows
# match the rule, 13 carry a negation, and the sets are disjoint — so no fixture can reach it and
# only this case can. `appartement meublé ou non` is a flat that is NOT furnished being rejected as
# one that is: dead safety code the day it is deleted, exactly the class this ledger exists for.
#
# The mutation replaces the lookahead with one that can never fire, rather than deleting it: an
# always-true lookahead is VALID PCRE and restores the pre-Track-7 behaviour exactly, where a
# malformed one would be refused at load and "detected" for the wrong reason. A mutation that fails
# to parse tests nothing.
run_sabotage "the meublé rule loses its negation lookahead (an unfurnished flat rejected as furnished)" \
  config/rent/criteria.json \
  "s%meuble(?!(?:es|e|s)?\\\\\\\\s\*(?:ou\\\\\\\\s+non|:\\\\\\\\s\*non)\\\\\\\\b)%meuble(?!zzzz)%"

# THE ABORT COMES BEFORE THE TALLY, and that ordering is the finding rather than a nicety (C2
# round 7, resilience P3). The alert job harvests the `N sabotage(s) detected, M undetected` line;
# printed AFTER it, a shard that selected no case ended its log with a clean-looking `0 / 0` and the
# refusal never reached the issue body — a human alerted to a failure whose text says nothing failed.
if (( _shard_n > 1 )) && (( _ran == 0 )) && [[ -z "$_filter" ]]; then
  # ONLY when no filter is in play. With a filter, a shard holding none of the filtered cases is the
  # expected arithmetic of an intersection, not a hole in the ledger — and aborting there would make
  # the development aid unusable beside the mechanism it is helping to test.
  printf '\n  \033[31mABORT\033[0m this shard selected NO case. Six jobs reporting a clean ledger\n'
  printf '        between them, having proved nothing, is the failure this refusal exists for.\n\n'
  exit 1
fi

printf '\n  %d sabotage(s) detected, %d undetected\n' "$pass" "$fail"

if (( _shard_n > 1 )); then
  # THE SCOPE TRAVELS WITH THE NUMBER. A shard result printed without saying it is one reads as the
  # whole ledger, which is the same lie the PARTIAL RUN line below exists to prevent.
  printf '  \033[33mSHARD %d/%d\033[0m — %d case(s) ran here, %d belong to other shards.\n' \
    "$_shard_i" "$_shard_n" "$_ran" "$_sharded_out"
fi

if [[ -n "$_filter" ]]; then
  # Loud, because a filtered run that looked like a full one would be the ledger lying about its own
  # coverage — the same class of defect as the baseline gate that reddened itself for six days.
  printf '  \033[33mPARTIAL RUN\033[0m — SABOTAGE_FILTER=%s skipped %d case(s). NOT a ledger result.\n' \
    "$_filter" "$skipped"
fi

if (( fail > 0 )); then
  printf '\n  undetected or unapplied:\n'
  printf '    - %s\n' "${failed_labels[@]}"
fi

printf '\n'

# An explicit exit, not a trailing test expression. The positional form was correct only while
# nothing followed it, and on 2026-08-20 eight appended cases followed it: with no `set -e` they
# ran on past it and left their own 0 as the script's status, so a FAIL exited 0 and the nightly
# job could not go red. Anything appended below is now dead code, which
# tests/test-ci-workflow.sh reports rather than the ledger absorbing it.
if (( fail > 0 )); then
  exit 1
fi

exit 0
