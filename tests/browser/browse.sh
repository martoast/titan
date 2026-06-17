#!/usr/bin/env bash
#
# Titan browser-test harness — wraps the gstack `browse` binary for the Titan Sail app.
#
# Always resolves the daemon from the Titan git root (this repo), so it runs an ISOLATED Chrome
# session for Titan — independent of any other project's browse daemon. Authentication is via the
# local-only /dev/login shortcut (no passwords).
#
#   tests/browser/browse.sh login [email]      # sign in (first user by default) → /coach
#   tests/browser/browse.sh goto /progress     # navigate to a Titan path
#   tests/browser/browse.sh seed --messages=44  # seed a long [browse-test] thread → prints its id
#   tests/browser/browse.sh url | text | screenshot /tmp/x.png | js '<expr>'   # pass-through to browse
#
set -uo pipefail

B="${BROWSE_BIN:-$HOME/.claude/skills/gstack/browse/dist/browse}"
APP="${TITAN_URL:-http://localhost:8088}"
CONTAINER="${TITAN_CONTAINER:-fitness-ai-laravel.test-1}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

cd "$ROOT" || { echo "cannot cd to Titan root"; exit 1; }   # daemon state resolves from here

art() { docker exec "$CONTAINER" php artisan "$@"; }

cmd="${1:-help}"; shift || true
case "$cmd" in
    login) "$B" goto "${APP}/dev/login${1:+?email=$1}" ;;
    goto)  "$B" goto "${APP}${1:-/}" ;;
    seed)  art titan:browse-seed "$@" ;;
    art)   art "$@" ;;
    help)  grep '^#' "$0" | sed 's/^# \{0,1\}//' ;;
    *)     "$B" "$cmd" "$@" ;;
esac
