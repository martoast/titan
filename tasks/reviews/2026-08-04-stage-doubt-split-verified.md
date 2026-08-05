# VERIFIED end to end on Tester B's real night — the split is the right correction

**Reviewing:** `763590d` split stage-split doubt from night trust · `ec8d648` say the stage caveat once
**Reviewer:** Henry (server) · 2026-08-04 · follows my review of `ecc0cd8` in
`tasks/reviews/2026-08-04-sleep-plausibility-verified.md`

`ecc0cd8` was right about the defect and wrong about the lever. This corrects it, and I confirmed
both halves against production: the night is now caveated where it should be, and still counts where
it should.

---

## The correction is justified — verified from code, not just argued

The claim is that routing an impossible layout into `low_confidence` would unmeasure a night that
was measured fine. That holds:

- `SleepDebt.php:51` — `if ($n->low_confidence || …) continue;` — the night leaves the ledger entirely.
- `SleepWeek.php:47` — `$low = (bool) $l->low_confidence || …`, and a low night isn't scored.

So flagging Tester B's 2026-08-04 would have taken a night she genuinely slept **9h12m** of and dropped it
from her debt, weekly score and streak — trading a wrong deep number for a wrong debt number. Two
different doubts, correctly given two different columns.

## End-to-end on the real night

Migration ran (`stages_low_confidence` present), and both nights are now flagged
(`updated_at 21:43:22`). Night #188: **`stages_low_confidence = 1`, `low_confidence = 0`.**

What Tester B actually gets now, from `SleepStory::forNight(188)`:

> "You fell asleep quickly — within about 11 minutes for 9.2h total. **The 9.2h is solid, but the
> stage breakdown didn't read cleanly — treat the deep/REM split as rough.** You slept essentially
> straight through. You got 9.2h in, which is the part that counts — **but the stage read was off
> tonight, so I won't call the deep/REM split either way.**"

- The real duration is still stated. ✅
- Every stage-derived claim is withheld, and the "full, well-built night" win — the exact path that
  told her she was well rested off a 40%-deep artifact — is no longer reachable. ✅
- `SleepDebt` balance is **0.0h, unchanged** by the flag. The night still counts. ✅

That is the complete behaviour change, observed on the night that motivated it rather than inferred.

### `ec8d648` — and a miss of mine

Read that quote again: the caveat is stated **twice**, once in the narrative and once in the
takeaway. I pasted it into this review as evidence the fix worked and did not notice. `ec8d648`
caught it and fixed it; verified on the deployed build, the same night now reads:

> "You fell asleep quickly — within about 11 minutes for 9.2h total. You slept essentially straight
> through. You got 9.2h in, which is the part that counts — but the stage read was off tonight, so I
> won't call the deep/REM split either way."

One statement of the caveat, duration intact. The conditional it uses is the right shape too: the
narrative sentence steps in only when a shortfall takes the takeaway slot, so a night that is both
short *and* unreadable still says so. Both directions pinned by test.

Worth naming the failure mode on my side: I verified the *mechanism* (is the flag set, is the stage
claim withheld, does debt survive) and stopped reading once each box was ticked — I checked the
output against my checklist instead of reading it as a sentence someone receives. The duplication was
in the first line of evidence I quoted.

## The two stale tests

`763590d` kills them, and reaches the same reading I did independently: red since `dc8f1fb` because
they asserted the pre-gate behaviour. The resolution is better than the deletion I suggested — the
intent (a stager-flagged split, or a dominant stage, should flag) was never wrong, it just belonged
to the *stages* column all along, so the assertions move there rather than disappearing. Plus an
explicit assertion that the gate still holds for the soft tier while the impossible tier pierces it,
which is the distinction the whole change rests on.

Leaving "does the soft tier's coverage gate still earn its keep" as a marked open call in the test is
the right call too. My view, for whoever picks it up: **it probably doesn't.** The gate existed so a
well-measured odd night wasn't permanently caveated — but that cost was the night's debt and streak,
and this commit just removed that cost. Now that flagging stages only softens the narration, the
argument for suppressing a genuine stage doubt at high coverage is much weaker — especially since the
coverage doing the suppressing is the bridged number the holes inflate. Not a request; the open call
is correctly parked.

## Note on process, not code

While reviewing `ecc0cd8` I found this work uncommitted in the shared checkout — 11 modified files
growing as I read them — and held my review rather than push, because a push fires the deploy and
`git reset --hard`s that tree. Snapshots are in `~/deploy/uncommitted-backups/` (both verified to
apply cleanly against a clean tree); they can be deleted now that the work is committed.

`tasks/REVIEW_LOOP.md` warns that a deploy resets **Henry's** edits. It does not cover two agents
editing the same working tree, which is what happened here and is a sharper hazard: the loss is
silent, and the window is however long the other agent takes to commit. If this is going to be
routine, the second agent should work in its own clone — `~/projects/titan` is the deploy checkout
and can be reset at any moment by anyone's push.

## Status

Both commits verified. No new test failures; the two stale reds are gone.

Nothing outstanding from me on sleep. The durable fix remains the retrain on duty-cycle-matched data
— everything here is honest caveating over a model being fed an ~86%-held grid it never saw in
training.
