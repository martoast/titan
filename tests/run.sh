#!/usr/bin/env bash
#
# Titan — single test entrypoint. Runs the PHP suite (unit + feature) and the real-browser suites
# (smoke + live feature regression) and reports one combined result.
#
#   tests/run.sh             # everything (browser suites skipped automatically if unavailable)
#   tests/run.sh --php       # PHP suite only
#   tests/run.sh --browser   # browser suites only
#
# Browser suites need the Sail app running (http://localhost:8088) and the gstack `browse` binary;
# if either is missing they're skipped with a warning rather than failing the run (CI-friendly).
#
set -uo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

APP="${TITAN_URL:-http://localhost:8088}"
BROWSE_BIN="${BROWSE_BIN:-$HOME/.claude/skills/gstack/browse/dist/browse}"
rc=0

php_suite() {
    echo "════════ PHP suite (unit + feature) ════════"
    DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan test --compact || rc=1
    echo
}

browser_suites() {
    echo "════════ Browser suites ════════"
    if ! curl -s -o /dev/null --max-time 4 "$APP/coach"; then
        echo "  ⚠ app not reachable at $APP — skipping browser suites (run: ./vendor/bin/sail up -d)"; echo; return
    fi
    if [ ! -x "$BROWSE_BIN" ]; then
        echo "  ⚠ browse binary not found at $BROWSE_BIN — skipping browser suites"; echo; return
    fi
    echo "── smoke (switching, pagination, loading overlay)"
    bash "$ROOT/tests/browser/smoke.sh" || rc=1
    echo
    echo "── features (every generative card + pages)"
    bash "$ROOT/tests/browser/features.sh" || rc=1
    echo
}

case "${1:-all}" in
    --php|php)         php_suite ;;
    --browser|browser) browser_suites ;;
    all|"")            php_suite; browser_suites ;;
    *) echo "usage: tests/run.sh [all|--php|--browser]"; exit 2 ;;
esac

echo "════════════════════════════════════════════"
if [ "$rc" -eq 0 ]; then echo "  ✅ ALL GREEN"; else echo "  ❌ FAILURES — see output above"; fi
exit "$rc"
