# The Titan review loop — how the dev agent and Henry work together

> **Both sides read this.** It is the contract. Until now it lived only in Henry's memory, which is
> why the loop stalled the first time it was tested (see [Failure modes](#failure-modes-that-have-actually-happened)).

Two agents share this repo:

| | who | where it runs | what it does |
|---|---|---|---|
| **Dev agent** | Claude on `github.com/martoast/titan` | Alex's machine | implements features and fixes, pushes to `master` |
| **Henry** | Claude on the HP server (`~/projects/titan`) | the box that hosts prod | reviews each push, field-tests on Alex's REAL data, writes specs/reviews |

Henry has something the dev agent does not: **the production database, the real band data, the raw
waveforms in MinIO, and the running containers.** That is the whole point of the loop — the dev agent
writes the code, Henry checks it against reality.

---

## The cycle

```
dev agent pushes to master
        │
        ▼
Henry's catcher fires  (~/deploy/henry-watch.sh, polls origin/master every 45s)
        │
        ▼
Henry reviews the FULL range since his marker — reads the diff, greps the claims,
field-tests against real prod data where the change is testable
        │
        ▼
Henry COMMITS a review to tasks/reviews/YYYY-MM-DD-<slug>.md  ← the dev agent's signal
        │
        ▼
Henry advances the marker (~/deploy/henry-reviewed.sh)
        │
        ▼
dev agent reads the review commit and continues to the next increment
```

**The commit is the channel.** Neither side can see the other's chat. A review that exists only as a
message reaches nobody.

---

## Rules for Henry

1. **Always commit a review — even when the work is clean.** A clean pass gets
   `review(<area>): T<n> VERIFIED · proceed to <next>`, recording what was verified *on real data*
   and explicitly greenlighting the next step. Never end a verification with no commit.
2. **Review the range from the marker, not the tip.** A burst of pushes between polls must not hide
   commits underneath. `henry-watch.sh` prints the full range; inspect all of it.
3. **Scope the review to what changed.** For a fix-verification pass, read the diff directly and
   check each item against the code. Do not spin up agent fleets — that burned two session budgets
   on 2026-07-10. Reserve a full audit for new features or when Alex asks for one.
4. **Advance the marker only after the review is pushed** (`~/deploy/henry-reviewed.sh`).
5. **State facts and hypotheses differently.** Anything Henry verified against prod data is a fact
   and should say so; anything inferred is a hypothesis and must be labelled, with the test that
   would settle it. See `tasks/reviews/2026-08-03-workout-hr-dead-ppg.md` for the intended shape —
   including its retraction section, which exists because Henry got a root cause wrong.
6. **Fix it directly when Alex says so.** Small, self-contained, server-side/PHP/prompt changes that
   Henry can lint and field-test: just do it, commit, push, advance the marker. Delegate iOS/SwiftUI
   (Henry cannot compile Swift) and large multi-file features.

## Rules for the dev agent

1. **Push to `master`.** That is what the catcher watches, and what auto-deploys.
2. **One increment per push**, with a subject line that says what it is. Henry reviews the range;
   small coherent commits get better reviews than one large one.
3. **Say what you could not verify.** Especially: firmware (needs a reflash), iOS (needs Xcode), and
   anything requiring real band data. Henry has the data and can close those gaps — but only if the
   commit message says which gaps exist.
4. **Wait for the review commit before the next increment**, unless Alex says otherwise. Poll
   `tasks/reviews/` on `origin/master`.
5. **Do not leave uncommitted work in `~/projects/titan` on the server** — that is Henry's checkout
   AND the deploy checkout; see the reset hazard below.

---

## Commit conventions

| prefix | meaning | who |
|---|---|---|
| `feat(...)`, `fix(...)`, `perf(...)`, `chore(...)` | implementation | dev agent (or Henry when fixing directly) |
| `review(...)` | a verdict on a previous push — **the dev agent's go signal** | Henry |
| `docs(review)` / `spec(...)` | a review handoff or a spec for work not yet done | Henry |

Henry's commits carry a `Claude-Session:` trailer, which is how the catcher skips its own work —
both sides push as `martoast <alexmartos96@gmail.com>`, so the author field cannot tell them apart.

A review commit should open with a one-line verdict so the dev agent can act on it without parsing
prose. Use one of:

```
VERIFIED · proceed to <next>
CHANGES REQUESTED · <n> items, see below
BLOCKED · <what Henry needs before this can be judged>
```

---

## The mechanics on the server

```bash
~/deploy/henry-watch.sh [branch]   # the catcher (default master); prints each unreviewed push
~/deploy/henry-reviewed.sh [sha]   # advance the marker after pushing a review
cat ~/deploy/.henry-seen           # what Henry has reviewed through
```

The catcher does not exit on the first hit — the loop is bidirectional and a session normally needs
several round trips.

## The mechanics on the dev side

The other half, added 2026-08-03 — until then the loop only ran one way, and Alex had to relay
Henry's reviews by hand.

```bash
./scripts/dev-watch.sh              # one-shot: reviews pushed since our marker, with the verdict line
./scripts/dev-watch.sh --watch      # poll every 45s (mirrors henry-watch.sh)
./scripts/dev-watch.sh --seen [sha] # advance the marker once the review has been acted on
cat "$(git rev-parse --git-dir)/titan-dev-seen"   # what the dev agent has read through
```

Two deliberate choices, both mirroring mistakes already made on the server side:

- **A review is identified by subject prefix or a `tasks/reviews/` file, never by author.** Both
  sides push as `martoast <alexmartos96@gmail.com>`, so the author field cannot discriminate.
- **`--seen` refuses a commit that isn't an ancestor of `origin/master`**, for the same reason
  `henry-reviewed.sh` does — a marker advanced past a commit that was never pushed silently skips a
  review nobody ever reads.

The marker lives in `.git/` rather than the working tree: it is per-checkout state, and a committed
marker would conflict on every round trip.

**The dev agent should also state its own verdict line** when a push answers a review — e.g. a commit
subject or body opening `ADDRESSES <review-file> · <n> items` — so Henry can tell a response from a
new increment without diffing.

---

## Failure modes that have actually happened

- **Silence is not approval (2026-07-13).** Henry verified an increment, said "clean, no review
  needed" in chat, and committed nothing. The dev agent waited. Alex had to notice and intervene.
  → Rule 1 for Henry exists because of this.
- **A deploy wiped uncommitted work (2026-07-10).** Every push to `master` runs
  `git reset --hard origin/master` on `~/projects/titan` — the same directory Henry edits in. A
  deploy fired mid-edit and silently destroyed firmware changes; only the file touched after the
  reset survived. → Commit immediately after each logical change, and if the catcher fires while
  Henry is mid-edit, check `git status` before assuming the edits are still there.
  It nearly recurred on 2026-08-03 when Alex pushed from his Mac during an edit here.
- **Agent fleets exhausted the session budget (2026-07-10).** ~15–20 subagents per review pass, twice.
  → Rule 3 for Henry.
- **A review committed onto a detached HEAD (2026-08-03).** Reviewing the range meant
  `git checkout <sha>`; the review was then committed there, `git push` said *"Everything
  up-to-date"*, and the review existed only as a dangling local commit — invisible to the dev agent,
  while looking completely successful. → Review with `git show` / `git diff <range>`, never
  `git checkout`. `henry-reviewed.sh` now refuses to advance the marker unless the commit is an
  ancestor of `origin/master`, and prints the cherry-pick recovery.
- **Two timestamp conventions, one wrong fix (2026-08-03).** `activity_sessions` stores UTC
  wall-clock; `device_ingestions` stores app-local. Both are deliberate and documented in their own
  files. Henry "fixed" the first to match the second, broke the guaranteed-write/late-window merge,
  and reverted. → Before changing a timestamp convention, check how READERS query the table
  (`Support/Strain.php` uses UTC bounds) and check real rows against the windows that fed them.
