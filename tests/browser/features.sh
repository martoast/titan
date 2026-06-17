#!/usr/bin/env bash
#
# Live feature regression — drives the coach through each thing we added this session and asserts the
# expected generative card renders (which implies the right tool fired) + streaming completes. Runs in
# one throwaway [browse-test] conversation, deleted at the end.
#
#   tests/browser/features.sh
#
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
H="$HERE/browse.sh"
SHOTS="${SHOTS:-/tmp/titan-browse}"; mkdir -p "$SHOTS"
CONTAINER="${TITAN_CONTAINER:-fitness-ai-laravel.test-1}"
pass=0; fail=0
ok()  { echo "    ✅ $1"; pass=$((pass+1)); }
bad() { echo "    ❌ $1 — $2"; fail=$((fail+1)); }

COMP='[...document.querySelectorAll("[x-data]")].find(function(e){try{return "openChat" in window.Alpine.$data(e)}catch(_){return false}})'

# Send a prompt, wait for the stream to finish, return the card types in the LAST assistant bubble.
ask() {
  local prompt="$1"
  bash "$H" js "(function(){var d=window.Alpine.\$data($COMP);d.send(\"$prompt\");return \"sent\";})()" >/dev/null 2>&1
  local done=0
  for _ in 1 2 3 4 5 6 7 8 9 10; do
    sleep 3
    local st
    st=$(bash "$H" js "(function(){var d=window.Alpine.\$data($COMP);return JSON.stringify({l:d.loading,s:d.streaming});})()" 2>/dev/null | tail -1)
    case "$st" in *'"l":false'*) done=1; break;; esac
  done
  [ "$done" = 1 ] || { echo "TIMEOUT"; return; }
  sleep 0.5
  bash "$H" js '(function(){var b=[...document.querySelectorAll(".coach-prose")];var last=b[b.length-1];if(!last)return "[]";return JSON.stringify([...last.querySelectorAll(".tcard")].map(function(c){return (c.className.match(/tcard-(\w+)/)||[])[1]}).filter(Boolean));})()' 2>/dev/null | tail -1
}

want() { # label  prompt  expected-card-substr
  echo "── $1"
  local cards; cards=$(ask "$2")
  echo "    cards: $cards"
  case "$cards" in *"$3"*) ok "$1 → $3 card";; TIMEOUT) bad "$1" "stream timed out";; *) bad "$1" "expected $3, got $cards";; esac
}

echo "Logging in + fresh conversation…"
bash "$H" login >/dev/null 2>&1; sleep 1
CID=$(docker exec "$CONTAINER" php artisan tinker --execute '$p=\App\Models\Profile::oldest("id")->first();$c=$p->conversations()->create(["title"=>"[browse-test] features"]);echo $c->id;' 2>/dev/null | tail -1 | tr -dc '0-9')
echo "  conversation #$CID"
bash "$H" goto "/coach?c=$CID" >/dev/null 2>&1; sleep 1

want "Athlete score / VO2max"        "Give me my overall athlete score and VO2max"            "fitness"
want "Mesocycle (glute focus)"       "Build me a 5 day program to bring up my glutes"         "program"
want "Autoregulation"                "Should I push hard today or back off"                   "autoreg"
want "Remember a fact"               "Remember that my left shoulder is injured and I hate RDLs" ""   # ack, no card expected
want "Memory book"                   "What do you remember about me"                          "memory"
want "Weekly review"                 "How was my week"                                        "review"
want "Dream physique progress"       "Am I on track to my dream physique"                     "phys"
want "Biological age"                "How is my biological age"                               "bioage"

bash "$H" screenshot "$SHOTS/features-last.png" >/dev/null 2>&1

echo "── Non-chat pages"
bash "$H" goto "/progress" >/dev/null 2>&1; sleep 1
pu=$(bash "$H" url 2>/dev/null); case "$pu" in *"/progress"*) ok "/progress loads";; *) bad "/progress" "$pu";; esac
bash "$H" goto "/notifications/settings" >/dev/null 2>&1; sleep 1
txt=$(bash "$H" text 2>/dev/null)
case "$txt" in *"How present should I be"*) ok "notification settings render";; *) bad "notification settings" "missing heading";; esac
bash "$H" screenshot "$SHOTS/features-notifications.png" >/dev/null 2>&1

echo "Cleaning up…"
docker exec "$CONTAINER" php artisan tinker --execute '$c=\App\Models\Conversation::where("title","like","[browse-test]%")->get();foreach($c as $x){$x->messages()->delete();$x->delete();}echo "removed ".$c->count();' 2>/dev/null | tail -1

echo "─────────────────────────────────────────────"
echo "  ${pass} passed, ${fail} failed."
[ "$fail" -eq 0 ]
