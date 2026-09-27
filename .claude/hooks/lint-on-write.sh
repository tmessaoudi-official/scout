#!/usr/bin/env bash
# lint-on-write.sh — PostToolUse (Edit|Write)
#
# Lints only the file that was just written. Advisory: never blocks, exit code is
# always 0. Findings go to stderr (the transcript, for a human) AND to stdout as
# hookSpecificOutput.additionalContext, the only exit-0 channel that reaches the model.
#
# Python  -> ruff check
# YAML    -> yamllint (if installed)
# Shell   -> shellcheck (if installed)
# JSON    -> python3 -m json.tool

set -uo pipefail

# Rule 13 observability (global CLAUDE.md): a hook
# that runs unattended logs state-changing actions and errors to var/claude/logs/ IN THE REPO. Sourced
# rather than reimplemented so there is one log format and one destination. Never fatal — a logging
# failure must not take down the hook that is logging, hence the `|| true` and the no-op fallback.
_HELPERS="$HOME/.claude/hooks/log-helpers.sh"
# shellcheck disable=SC1090
[[ -f "$_HELPERS" ]] && source "$_HELPERS" 2>/dev/null || true
declare -F log_obs >/dev/null 2>&1 || log_obs() { :; }

file_path="$(python3 -c '
import json, sys
try:
    d = json.load(sys.stdin)
except Exception:
    raise SystemExit
i = d.get("tool_input", {}) or {}
print(i.get("file_path") or i.get("notebook_path") or "")
' 2>/dev/null)" || exit 0

[[ -z "$file_path" || ! -f "$file_path" ]] && exit 0

out=""
case "$file_path" in
  *.py)
    command -v ruff >/dev/null 2>&1 && out="$(ruff check "$file_path" 2>&1)" || true
    ;;
  *.yaml|*.yml)
    command -v yamllint >/dev/null 2>&1 && out="$(yamllint -f parsable "$file_path" 2>&1)" || true
    ;;
  *.sh|*.bash)
    command -v shellcheck >/dev/null 2>&1 && out="$(shellcheck -f gcc "$file_path" 2>&1)" || true
    ;;
  *.json)
    out="$(python3 -m json.tool "$file_path" >/dev/null 2>&1 || echo "invalid JSON: $file_path")"
    ;;
  *)
    exit 0
    ;;
esac

[[ -z "${out// }" ]] && exit 0

log_obs INFO lint-on-write "findings in ${file_path##*/}" || true
msg="lint-on-write: findings in $file_path"$'\n'"$(printf '%s\n' "$out" | head -40)"
printf '%s\n' "$msg" >&2
# stderr lands in the transcript for a human, but with exit 0 it never reaches the MODEL; the only
# channel that does is hookSpecificOutput.additionalContext on stdout (measured 2026-09-28, ~/.claude
# review-remediation row 33). Guard: test-lint-on-write.sh beside this file.
printf '%s' "$msg" | python3 -c 'import json,sys
print(json.dumps({"hookSpecificOutput": {"hookEventName": "PostToolUse", "additionalContext": sys.stdin.read()}}))'

exit 0
