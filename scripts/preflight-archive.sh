#!/usr/bin/env bash
# Get this checkout ready to archive an App Store build — and refuse if it isn't.
#
# Written after a near-miss: `git pull` failed with "divergent branches" (the history was
# rewritten), XcodeGen happily regenerated from the STALE tree, and the next step would have
# archived the exact build Apple had already rejected. Every check below is one that would
# have caught something real.
#
#   ./scripts/preflight-archive.sh            # check + sync + regenerate
#   ./scripts/preflight-archive.sh --check    # check only, change nothing
set -uo pipefail
cd "$(dirname "$0")/.."
CHECK_ONLY=0; [ "${1:-}" = "--check" ] && CHECK_ONLY=1
FAIL=0
ok(){ printf '  \033[32mok\033[0m    %s\n' "$1"; }
bad(){ printf '  \033[31mFAIL\033[0m  %s\n' "$1"; FAIL=1; }
note(){ printf '        %s\n' "$1"; }

echo "== 1. is there local work that a sync would destroy? =="
DIRTY="$(git status --porcelain)"
if [ -n "$DIRTY" ]; then
  bad "working tree is dirty — commit, stash or discard before syncing"
  note "$(echo "$DIRTY" | head -5)"
else ok "working tree clean"; fi
STASH="$(git stash list)"
[ -n "$STASH" ] && { bad "you have stashes — they will NOT be reset, but check them"; note "$(echo "$STASH" | head -3)"; } || ok "no stashes"

echo
echo "== 2. sync to the published history =="
git fetch --quiet origin || bad "git fetch failed"
LOCAL="$(git rev-parse HEAD)"; REMOTE="$(git rev-parse origin/master)"
if [ "$LOCAL" = "$REMOTE" ]; then
  ok "already at origin/master ($(git rev-parse --short HEAD))"
elif [ "$FAIL" = "1" ]; then
  bad "cannot sync while the tree is dirty — fix section 1 first"
elif [ "$CHECK_ONLY" = "1" ]; then
  bad "local ($(git rev-parse --short HEAD)) != origin/master ($(git rev-parse --short origin/master)) — rerun without --check"
else
  # The history was rewritten upstream, so merge/rebase cannot work: take origin verbatim.
  git reset --hard --quiet origin/master && ok "reset to origin/master ($(git rev-parse --short HEAD))" \
    || bad "reset failed"
fi

echo
echo "== 3. the build Apple will see =="
VER="$(grep -oE 'CURRENT_PROJECT_VERSION: *"[0-9]+"' ios/Titan/project.yml | grep -oE '[0-9]+')"
MKT="$(grep -oE 'MARKETING_VERSION: *"[^"]+"' ios/Titan/project.yml | sed 's/.*"\(.*\)"/\1/')"
LAST_SUBMITTED=11
if [ -z "$VER" ]; then bad "could not read CURRENT_PROJECT_VERSION"
elif [ "$VER" -le "$LAST_SUBMITTED" ]; then bad "build $VER was already submitted — bump CURRENT_PROJECT_VERSION"
else ok "version $MKT ($VER)"; fi

echo
echo "== 4. nothing calls the watch a Titan product (the 5.2.1 rejection) =="
HITS="$(grep -rniE 'titan[ -]band' \
        ios/Titan/Sources resources/js resources/views 2>/dev/null \
        | grep -viE 'titan_band|titan-band|__titanBand|TITAN_BAND')"
[ -z "$HITS" ] && ok "no user-visible \"Titan band\" in app, JS or views" || { bad "found user-visible mentions"; note "$(echo "$HITS" | head -5)"; }

echo
echo "== 5. localisation won't silently fall back to English =="
python3 - <<'PY'
import json,sys,pathlib,re
c=json.load(open("ios/Titan/Sources/Localizable.xcstrings",encoding="utf-8"))
keys=c["strings"]
want=["Your band","Got your band?","Pair your band","Pair your band to begin.","My band is in hand"]
bad=[]
for k in want:
    e=keys.get(k)
    if e is None: bad.append(f"key missing from catalogue: {k!r}"); continue
    if not e.get("localizations",{}).get("es-MX",{}).get("stringUnit",{}).get("value"):
        bad.append(f"no es-MX translation: {k!r}")
src="".join(p.read_text(encoding="utf-8") for p in pathlib.Path("ios/Titan/Sources").rglob("*.swift"))
for k in want:
    if k not in src: bad.append(f"key not used in any Swift file (orphan): {k!r}")
stale=[k for k in keys if re.search(r"\bTitan band\b",k)]
if stale: bad.append(f"stale keys still present: {stale}")
if bad:
    for b in bad: print(f"  \033[31mFAIL\033[0m  {b}")
    sys.exit(1)
print("  \033[32mok\033[0m    5 renamed keys present, translated to es-MX, all used, none stale")
PY
[ $? -ne 0 ] && FAIL=1

echo
echo "== 6. the capabilities 2.5.4 was cleared on must not have moved =="
for k in "bluetooth-central" "NSBluetoothAlwaysUsageDescription" "ITSAppUsesNonExemptEncryption"; do
  grep -q "$k" ios/Titan/project.yml && ok "$k present" || bad "$k MISSING from project.yml"
done

echo
echo "== 7. regenerate the Xcode project =="
if [ "$CHECK_ONLY" = "1" ]; then note "skipped (--check)"
elif [ "$FAIL" = "1" ]; then bad "not regenerating while checks are failing"
elif ! command -v xcodegen >/dev/null; then bad "xcodegen not installed (brew install xcodegen)"
else
  ( cd ios/Titan && xcodegen generate ) >/dev/null 2>&1 \
    && ok "Titan.xcodeproj regenerated from project.yml" || bad "xcodegen failed"
fi

echo
if [ "$FAIL" = "0" ]; then
  printf '\033[32m== READY TO ARCHIVE ==\033[0m\n'
  echo "   Open ios/Titan/Titan.xcodeproj → Product → Archive"
  echo "   Confirm the Organizer shows $MKT ($VER) before you upload."
  echo
  echo "   Then, in App Store Connect — neither is checked by this script:"
  echo "     • screenshots must not show a \"Titan band\" or any hardware render"
  echo "     • App Review Information → Notes needs the evidence + demo password"
else
  printf '\033[31m== NOT READY — fix the FAILs above ==\033[0m\n'
fi
exit $FAIL
