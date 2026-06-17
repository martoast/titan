#!/usr/bin/env bash
#
# Titan coach-chat browser smoke test. Exercises the things the PHP suite can't see: AJAX chat
# switching + the loading overlay, scroll-up pagination, and that the chat renders. Drives Alpine
# state directly (the gstack pattern) to assert the front-end behaviour, and saves screenshots.
#
#   tests/browser/smoke.sh            # run the full smoke; exits non-zero on any failure
#
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
H="$HERE/browse.sh"
SHOTS="${SHOTS:-/tmp/titan-browse}"; mkdir -p "$SHOTS"
pass=0; fail=0
ok()   { echo "  ✅ $1"; pass=$((pass+1)); }
bad()  { echo "  ❌ $1 — $2"; fail=$((fail+1)); }
has()  { case "$2" in *"$1"*) return 0;; *) return 1;; esac; }

# Find the coachChat Alpine root by a distinctive method and return a JSON probe of its state.
PROBE='(function(){var el=[...document.querySelectorAll("[x-data]")].find(function(e){try{return "openChat" in window.Alpine.$data(e)}catch(_){return false}});if(!el)return "NO_COMPONENT";var d=window.Alpine.$data(el);return JSON.stringify({msgs:d.messages.length,hasMore:d.hasMore,loadingChat:d.loadingChat,active:d.activeId});})()'
probe() { bash "$H" js "$PROBE" 2>/dev/null; }
drive() { bash "$H" js "(function(){var el=[...document.querySelectorAll(\"[x-data]\")].find(function(e){try{return \"openChat\" in window.Alpine.\$data(e)}catch(_){return false}});if(!el)return \"NO_COMPONENT\";var d=window.Alpine.\$data(el);$1;return \"ok\";})()" 2>/dev/null; }

echo "── 1. login ──────────────────────────────────"
bash "$H" login >/dev/null; sleep 1
url=$(bash "$H" url 2>/dev/null)
has "/coach" "$url" && ok "login lands on /coach" || bad "login" "url=$url"

echo "── 2. seed a long thread + open it ───────────"
CID=$(bash "$H" seed --messages=44 | tail -1 | tr -dc '0-9')
[ -n "$CID" ] && ok "seeded conversation #$CID" || bad "seed" "no id"
bash "$H" goto "/coach?c=$CID" >/dev/null; sleep 1.2

echo "── 3. initial page is capped (pagination) ────"
s=$(probe); echo "    state: $s"
has '"msgs":20' "$s" && ok "initial render capped at 20 messages" || bad "initial cap" "$s"
has '"hasMore":true' "$s" && ok "older pages available" || bad "hasMore" "$s"

echo "── 4. scroll-up loads older messages ─────────"
drive 'd.loadOlder()' >/dev/null; sleep 1.5
s2=$(probe); echo "    state: $s2"
has '"msgs":40' "$s2" && ok "loadOlder prepended the previous page (20→40)" || bad "loadOlder" "$s2"

echo "── 5. switching shows the loading overlay ON-SCREEN ──"
# Scroll to the bottom first so we'd catch an overlay mis-anchored to the scroll content.
drive 'if(d.$refs && d.$refs.scroll){d.$refs.scroll.scrollTop = d.$refs.scroll.scrollHeight}' >/dev/null; sleep 0.3
drive 'd.loadingChat = true' >/dev/null; sleep 0.4
ov=$(bash "$H" js '(function(){var n=[...document.querySelectorAll("span")].find(function(s){return s.textContent.trim()==="Loading conversation…"});if(!n)return "NOT_FOUND";var r=n.getBoundingClientRect();var inView=r.width>0&&r.top>=0&&r.bottom<=(window.innerHeight||99999);return JSON.stringify({inView:inView,top:Math.round(r.top)});})()' 2>/dev/null)
echo "    overlay: $ov"
has '"inView":true' "$ov" && ok "loading overlay is visible on-screen (even scrolled)" || bad "overlay on-screen" "$ov"
bash "$H" screenshot "$SHOTS/02-loading-overlay.png" >/dev/null 2>&1
drive 'd.loadingChat = false' >/dev/null

echo "── 6. chat renders ──────────────────────────"
bash "$H" screenshot "$SHOTS/01-coach.png" >/dev/null 2>&1 && ok "captured coach view → $SHOTS/01-coach.png" || bad "coach shot" "screenshot failed"
bash "$H" goto "/progress" >/dev/null; sleep 1
bash "$H" screenshot "$SHOTS/03-progress.png" >/dev/null 2>&1 && ok "captured progress view → $SHOTS/03-progress.png" || bad "progress shot" "screenshot failed"

echo "─────────────────────────────────────────────"
echo "  ${pass} passed, ${fail} failed.  Screenshots in $SHOTS"
[ "$fail" -eq 0 ]
