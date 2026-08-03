# VERIFIED · and the "known gap" is not hypothetical — it already mis-modelled the ride under investigation

**Reviewing:** `26ba236` feat(firmware): the workout faces now say what they're FOR
**Reviewer:** Henry (server) · 2026-08-03

The widened map closes the gap I raised, the hint arithmetic checks out, and the UX reasoning is
sound. But the limitation the commit files under *"Known gap, NOT addressed"* turns out to be the
most consequential thing in this whole HR investigation, and I can show that from the data.

---

## Verified

**The widened `hrmAlgoSportFor` closes my walk/hike/row/swim finding.** `row`, `swim`, `yoga`, `hiit`
and anything else declared now land on FREE_TRAINING via `if (k || t)`. Rowing — the case I called
sharp, because a fixed stroke rate is exactly the failure class `d70d462` fixed — is handled.

`walk`/`hike` deliberately keep RUNNING, on the reasoning that a stride paces arms and effort
together. I'll accept that: it is a real distinction and the right question to be asking ("is wrist
cadence informative about heart rate here?"). Noting only that `SPORT_TYPE_WALKING = 0x09` and
`SPORT_TYPE_CLIMBING = 0x08` exist and remain untried — worth revisiting if a hike ever reads oddly,
not worth a round trip now.

The `|| ""` on both reads is a genuine robustness fix, not noise: `primed` is cleared at `endWorkout`,
so the old `t = primed && primed.type` could yield `null`/`undefined` into the comparisons.

**Hint widths confirmed.** Both strings are 17 chars; at 6 px/char that is 102 px of a 176 px screen,
exactly as claimed. ASCII-only, so no missing-glyph risk on the 6x8 bitmap font.

**The UX reasoning is right.** The face selects the HR model, nothing said so, and "LIFT" reading as
weights-only is precisely what routes boxing to the Run face. Renaming to GYM plus naming activities
fixes it at the only moment it matters. Internal names and the server kind `strength` unchanged, so
there is no data migration — correct call.

---

## The ride face is not a nice-to-have. It already corrupted the investigation.

The commit notes there is no RIDE face, so a band-started ride falls through to the RUNNING fallback.
The reality is worse, and it is in my database:

```
session 111  — THE 2026-08-03 bike ride —
   activity_type = strength   activity_confidence = 1.00   via = biosignal:sealed-session-marker

every activity_type profile 1 has ever recorded:
   strength   n=20   last 2026-08-03
   run        n=1    last 2026-07-11
   (no rides. ever.)
```

Alex started his bike ride from the LIFT face — the only no-GPS option — so the band **declared**
`kind = strength` with full confidence. Which means:

- **Under the old code**, that ride ran the RUNNING model (`hrmSportFor` → 1 for every non-bike). A
  bike ride modelled as running, on top of the wear-detect latch. That is a *third* contributor to
  the original symptom, independent of the two already fixed.
- **Under `d70d462` and `26ba236`**, it gets FREE_TRAINING. Better — but `SPORT_TYPE_RIDE_BIKE` is
  now **unreachable from the watch**. The one profile actually built for his stated activity can only
  be selected by priming from the phone.
- **It is not only an HR problem.** `confirmedActivityType()` maps `strength|lift|hiit|yoga → strength`
  and passes everything else through, so his rides seal as strength sessions — no route, no pace, no
  VO₂max, and they land in the training log as gym work. 20 of 21 sessions are `strength`; at least
  some of those are rides.

So the third face (or a long-press activity picker) moves from *polish* to **the highest-value
remaining increment** — it is the difference between his most-used cardio getting the right HR model
and never getting it. I'd take it before the cadence-harmonic work.

## Correction to my own previous acceptance test

My last two reviews said "HR tracks effort on a **ride**" tests the wear-detect and still-wrist
fixes, and implied `Bangle.dbg().hrmSportMode` would confirm the profile. With no ride face that is
ambiguous, so pinning it down — expected `dbg().hrmSportMode` **by the face actually used**:

| started from | declared kind | expected mode | note |
|---|---|---|---|
| GYM face | `strength` | **25** (FREE_TRAINING) | this is what a ride will report today — **correct behaviour, not a failure** |
| RUN face | `run` | **1** (RUNNING) | |
| phone-primed `bike` | `cycle` | **2** (BIKE) | the only route to the bike profile right now |
| auto-detected | none | **1** (RUNNING) | algo.h fallback |

Reading **25 during a ride started from GYM does not mean the fix failed.** It means the ride face
doesn't exist yet. The wear-detect and still-wrist fixes are still exercised by that ride —
`ppgRecoveries` staying 0 with HR tracking effort is the signal — they are just being tested under
FREE_TRAINING rather than BIKE.

## Status

Four firmware changes now stacked, still **none validated on-device**: wear-detect root cause
(`f2a9746`), still-wrist gate (`0bf129a`), sport-model map (`d70d462`), faces + widened map
(`26ba236`). Independent, all in one reflash.

If Alex wants the bike profile on this ride, prime it from the phone (`applyPriming` type `bike`)
rather than starting from the watch — that is the only path today, and it also gets the session
sealed as a ride instead of a twenty-first strength workout.
