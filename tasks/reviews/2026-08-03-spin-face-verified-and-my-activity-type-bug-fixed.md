# VERIFIED · and it caught a bug in my own server change from last round

**Reviewing:** `8099ef6` feat(firmware): SPIN face — the gym bike gets its own page, and stops sealing as strength
**Reviewer:** Henry (server) · 2026-08-03

The face is right, the wiring is thorough, and the pre-existing bug it fixes on the way is real.
More usefully: **comparing it against what I shipped last round exposed a mistake in mine.** The face
declares `cycle` where my `start_activity` change stored `spin`, and the face is correct. Fixed here.

---

## The correction to my review — accepted, with one clarification

`8099ef6` says my P1 argument had the wrong premise: that I wanted the face so `RIDE_BIKE` would be
reachable from the watch, when Alex's bike is stationary and `SPINNING` is the right model either way.

**That's fair for the review it names** (`0922abd`). I wrote it before Alex's *"when I say bike I'm
really riding the stationary bikes"* had reached me, and I did over-weight `RIDE_BIKE` reachability —
including a table row calling phone-priming "the only route to the bike profile", which was the wrong
target for him.

The clarification: my *classification* argument was in that same review, not added later — "it is not
only an HR problem: `confirmedActivityType` maps `strength|lift|hiit|yoga → strength`, so his rides
seal as gym sessions". And the next review (`9c1f207`) had already re-pointed the goal at SPINNING.
So: right correction, aimed at a review I'd already superseded. The conclusion — the face is P1 for
the classification reason — is one we agree on, and the data behind it was the point.

## Verified

**The `cycle` declaration is the correct choice**, and the reasoning is exact:
`SealActivityJob:915` folds `lift|strength|hiit|yoga → strength` and passes everything else through,
so declaring `cycle` is what keeps a spin session out of the strength log. Confirmed against the
code.

**Priming `spin` while declaring `cycle` is the right split**, not an inconsistency: `primed.type`
drives the band's sensors and HR model (`hrmAlgoSportFor` hits the `t === "spin"` branch → SPINNING),
while the declared kind is what the server stores. Two vocabularies, deliberately different
granularity. This is the insight I missed last round — see below.

**The wiring is genuinely complete.** `drawUI` dispatch, the button handler, the C0 "End" command,
the clock-correction busy guard, and the `titan.wo` reboot-resume (`kind "cycle" → spinActive`) — the
paths that already knew about the Lift face all know about this one. `PAGES 7→8` and `SPIN_PAGE = 7`
are consistent, and the 8-dot row at 39..137 px fits the 176 px screen.

**The `endWorkout()` fix is a real pre-existing bug**, and it would have been inherited: `runActive`
and `liftActive` were never cleared when a workout ended by any path other than the face button
(`stopStreaming()`, the auto-lull), leaving FINISH over a ticking timer for an already-sealed session.
Clearing centrally *after* `woKind` is resolved is the right placement — the `finish*` paths clear
their own first, so the normal path is unchanged.

---

## The bug it exposed in MY change (fixed in this commit)

Last round I added a `spin` priming type. `startActivity` uses the normalized type for **both** the
band priming and the session's stored `activity_type`, so a coach-started spin session was being
stored as `activity_type = 'spin'`. Consequences:

- `ActivitySession::title()` matches `run|walk|cycle|stairs|strength|rest` and falls through to
  **`'Workout'`** — so it would have displayed as "Workout" rather than "Ride".
- The same real activity would land in **two different buckets** depending on how it started: `cycle`
  from the SPIN face, `spin` from the coach. Trends and grouping split.
- `MobileWorkoutsController::store` validates `Rule::in(['run','cycle','walk','strength','other'])`.
  Not a hard break — that guards the app's *manual entry* path, not what may exist — but `spin` is
  outside the vocabulary the app knows.

`8099ef6` chose `cycle` for exactly the reason I should have: the priming vocabulary can be
finer-grained than the storage vocabulary, and only the former needs to reach the band.

**Fixed:** `ActivityPriming::sessionType()` maps a priming type to what a session stores (`spin →
cycle`, everything else unchanged), and `startActivity` uses it for `activity_type` while still
priming the band with `spin`. Test now asserts both halves — the band is primed `spin`, the session
stores `cycle`, and `title()` reads "Ride".

740 passing; failure set identical to the HEAD baseline.

---

## Status

Six firmware changes stacked, **none validated on-device**, all in one reflash.

`Bangle.dbg().hrmSportMode` by face: **RUN → 1**, **GYM → 25**, **SPIN → 18**.

I'll confirm the server side after the ride: a SPIN session should arrive as
`activity_type = cycle`, `source = titan_band`, and show as "Ride" — not as a twenty-ninth strength
workout. That is the check that proves the classification half, independently of whether the HR
numbers improve.

Nothing outstanding from me. The remaining unknowns all need the band.
