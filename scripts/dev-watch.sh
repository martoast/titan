#!/usr/bin/env bash
# dev-watch.sh — the dev agent's half of the review loop. Mirror of Henry's ~/deploy/henry-watch.sh.
#
# Henry's catcher watches origin/master for OUR pushes. This watches it for HIS reviews, so the loop
# closes without Alex having to relay anything by hand. See tasks/REVIEW_LOOP.md for the contract.
#
#   ./scripts/dev-watch.sh              # one-shot: what has Henry posted that we haven't acted on?
#   ./scripts/dev-watch.sh --watch      # poll every 45s (Ctrl-C to stop)
#   ./scripts/dev-watch.sh --seen [sha] # mark reviews read through <sha> (default: current origin tip)
#
# The marker lives in .git/ rather than the working tree on purpose: it is per-checkout state, and a
# committed marker file would collide on every round trip — the exact kind of merge noise this loop
# exists to avoid.
set -euo pipefail

BRANCH="${BRANCH:-master}"
REMOTE="${REMOTE:-origin}"
INTERVAL="${INTERVAL:-45}"
MARKER="$(git rev-parse --git-dir)/titan-dev-seen"

# A review is identified by what the contract says makes one — the commit SUBJECT or a file under
# tasks/reviews/ — never by author. Both sides push as the same git identity, so author cannot
# discriminate (this is the trap that made Henry's first catcher announce its own commits).
is_review() {
  local sha="$1" subject
  subject="$(git log -1 --format=%s "$sha")"
  case "$subject" in
    review\(*|docs\(review*|spec\(*) return 0 ;;
  esac
  git diff-tree --no-commit-id --name-only -r "$sha" | grep -q '^tasks/reviews/'
}

# The verdict line the contract asks Henry to open with, so a glance (or an agent) can act without
# reading prose. Falls back to the subject when a commit has no review file.
verdict_of() {
  local sha="$1" f
  f="$(git diff-tree --no-commit-id --name-only -r "$sha" | grep '^tasks/reviews/' | head -1)"
  if [ -n "$f" ]; then
    git show "$sha:$f" 2>/dev/null | grep -m1 -E 'VERIFIED|CHANGES REQUESTED|BLOCKED' || echo "(no verdict line)"
  else
    git log -1 --format=%s "$sha"
  fi
}

git fetch -q "$REMOTE" "$BRANCH"
TIP="$(git rev-parse "$REMOTE/$BRANCH")"

case "${1:-}" in
  --seen)
    TARGET="${2:-$TIP}"
    TARGET="$(git rev-parse "$TARGET")"
    # Refuse to advance to something that isn't actually on the branch. Henry lost a whole review to
    # a detached-HEAD commit that pushed as "Everything up-to-date"; the same mistake on this side
    # would silently skip a review we never read.
    if ! git merge-base --is-ancestor "$TARGET" "$TIP"; then
      echo "REFUSING: $TARGET is not an ancestor of $REMOTE/$BRANCH — it was never pushed." >&2
      exit 1
    fi
    echo "$TARGET" > "$MARKER"
    echo "marker → $(git log -1 --format='%h %s' "$TARGET")"
    exit 0
    ;;
esac

if [ ! -f "$MARKER" ]; then
  echo "$TIP" > "$MARKER"
  echo "No marker yet — armed at $(git log -1 --format='%h %s' "$TIP")"
  exit 0
fi

scan() {
  local seen found=0
  seen="$(cat "$MARKER")"
  git fetch -q "$REMOTE" "$BRANCH"
  TIP="$(git rev-parse "$REMOTE/$BRANCH")"
  [ "$seen" = "$TIP" ] && return 0

  # Review the RANGE, not the tip: a burst of pushes between polls must not hide a review underneath.
  for sha in $(git rev-list --reverse "$seen..$TIP"); do
    if is_review "$sha"; then
      found=1
      echo
      echo "─── $(git log -1 --format='%h  %s' "$sha")"
      echo "    $(verdict_of "$sha")"
      git diff-tree --no-commit-id --name-only -r "$sha" | sed 's/^/    /'
    fi
  done
  if [ "$found" = 1 ]; then
    echo
    echo "Read them, then: ./scripts/dev-watch.sh --seen $TIP"
  fi
  return 0
}

if [ "${1:-}" = "--watch" ]; then
  echo "Watching $REMOTE/$BRANCH for reviews every ${INTERVAL}s (Ctrl-C to stop)…"
  while true; do scan; sleep "$INTERVAL"; done
else
  scan
fi
