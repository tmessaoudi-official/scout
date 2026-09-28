#!/usr/bin/env bash
# Guard for .claude/hooks/lint-on-write.sh. Run it after any edit there: bash tests/test-lint-on-write.sh
#
# The contract: advisory (exit 0 always), silent on a clean file, and a finding reaches the MODEL.
# With exit 0 the only channel that does is stdout JSON `hookSpecificOutput.additionalContext`;
# stderr (this hook's first channel), plain stdout and `systemMessage` never reach it (measured
# 2026-09-28 with random markers, 5 variants x 2 models — ~/.claude review-remediation row 33).
# ruff and yamllint are optional here; the JSON leg (python3) and the shell leg (shellcheck, when
# installed) carry the assertions.
set -uo pipefail
SCRIPT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/.claude/hooks/lint-on-write.sh"
TMP=$(mktemp -d); trap 'rm -rf "$TMP"' EXIT
export OBS_LOG="$TMP/obs.log"
PASS=0; FAIL=0
ok()  { PASS=$((PASS+1)); echo "  ok   $1"; }
bad() { FAIL=$((FAIL+1)); echo "  FAIL $1"; }

run() {  # run <file> → RC, OUT (stdout), ERR (stderr)
  RC=0
  OUT=$(printf '{"tool_input":{"file_path":"%s"}}' "$1" | bash "$SCRIPT" 2>"$TMP/err") || RC=$?
  ERR=$(cat "$TMP/err")
}
ctx() { printf '%s' "$OUT" | python3 -c 'import json,sys
try: print(json.load(sys.stdin)["hookSpecificOutput"]["additionalContext"])
except Exception: pass'; }

echo "lint-on-write.sh — advisory contract"

printf '{"a": 1,,}\n' > "$TMP/bad.json"
run "$TMP/bad.json"
[[ $RC == 0 ]] && ok "exit 0 on invalid JSON (never blocks)" || bad "exit $RC on invalid JSON"
[[ "$(ctx)" == *"invalid JSON"* ]] && ok "JSON finding reaches the model (additionalContext)" \
  || bad "no additionalContext for invalid JSON; stdout='${OUT:0:80}'"
[[ "$ERR" == *"invalid JSON"* ]] && ok "finding also on stderr" || bad "stderr lacks the finding"

if command -v shellcheck >/dev/null 2>&1; then
  printf '#!/bin/bash\necho $1\n' > "$TMP/warn.sh"
  run "$TMP/warn.sh"
  [[ $RC == 0 && "$(ctx)" == *"SC2086"* ]] && ok "shellcheck finding reaches the model" \
    || bad "shellcheck finding: rc=$RC stdout='${OUT:0:80}'"
else
  echo "  skip shellcheck leg (not installed)"
fi

printf '{"a": 1}\n' > "$TMP/good.json"; echo x > "$TMP/n.txt"
for f in good.json n.txt; do
  run "$TMP/$f"
  [[ $RC == 0 && -z "$OUT" && -z "$ERR" ]] && ok "silent on $f" || bad "noise on $f: rc=$RC out='$OUT' err='$ERR'"
done

echo "$PASS passed, $FAIL failed"
[[ $FAIL -eq 0 ]]
