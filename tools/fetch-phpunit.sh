#!/usr/bin/env bash
# fetch-phpunit.sh — fetch the pinned PHPUnit runner. A wrapper since 2026-10-08: the verification,
# the pin and its history live in tools/fetch-phar.sh, the one verifier every PHAR tool shares.
#
# Run: bash tools/fetch-phpunit.sh

set -euo pipefail

exec bash "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/fetch-phar.sh" phpunit "$@"
