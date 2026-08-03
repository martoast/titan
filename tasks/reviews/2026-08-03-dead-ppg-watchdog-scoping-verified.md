# VERIFIED · proceed to persisting `ppgFieldCode`

**Reviewing:** `3e45fd2` fix(firmware): dead-PPG watchdog — scope the run to a capture window, the LED latch to a workout
**Reviewer:** Henry (server) · 2026-08-03 · follow-up to `0bf129a` and its request in
`tasks/reviews/2026-08-03-workout-hr-dead-ppg.md`

All three defects are real, the fixes are correct, and two of them would have made the watchdog
actively harmful. Verified against the code, not taken on the commit message's word. No on-device
run — see [Still blocked](#still-blocked-on-alex).

---

## Verified, claim by claim

**1. The zero-run wasn't scoped to a capture window — CONFIRMED, and the worst of the three.**
`setRawCapture(false)` drops the listener without clearing `ppgDeadSinceMs`, and my reset lived only
in `startStreaming()`. Sleep duty-cycles that listener every period. So a run left open at the close
of one burst keeps accumulating in *wall-clock* across a minutes-long gap, and the first zero sample
of the next burst satisfies `ppgDeadMs` immediately — power-cycling a healthy sensor, overnight,
repeatedly. `if (on) ppgDeadSinceMs = 0;` in `setRawCapture` is the right place and the minimal fix.

This also answers, from an angle I had not considered, my open question *"can a healthy sensor read
0 for 12 s?"* — it never needed to. The clock ran across the gap.

**2. The LED latch leaked past its session — CONFIRMED, premise checked independently.**
I verified the claim that capture is effectively permanent rather than accepting it: `titan.run` is
persisted by `setStreamPref`, and `f3a029d` added `if (!state.streaming) startStreaming();` on every
phone connect. So `startStreaming()` runs approximately never, and `ledAdaptiveOverride` would have
survived until a reboot.

The consequence the commit message draws is the important one and I had missed it: every *later*
workout would have run on the adaptive LED, which would have **silently destroyed the confirming
experiment** for the pinned-`0xE0` hypothesis. A latch that makes its own root cause unreproducible
is worse than no latch.

`resetPpgWatchdog()` is called from `startWorkout()`, the reboot-resume path, and `startStreaming()`.
I checked `startWorkout` for re-entry: it early-returns when `state.workout` is already true, so the
counter cannot be zeroed mid-session. Its four call sites (auto-detect, two manual face presses, the
C0 command) are all genuine starts.

**3. The cooldown path never cleared the run — CONFIRMED.** With `ppgDeadSinceMs` left satisfied,
`recoverDeadPpg` was re-entered on every zero sample for the full 60 s — 1,400–3,000 calls in the hot
raw callback. Re-arming the window is correct, and it also gives the retry sane semantics: a genuinely
dead sensor now retries once per cooldown rather than spinning.

**The `setTimeout(…, 0)` deferral is the right call.** It answers my open question 1 (is
power-cycling safe from inside the HRM-raw handler?) by making it moot instead of arguing it, and the
`state.streaming` guard covers capture stopping while the callback is queued. Rate-limited to one per
60 s, so the allocation is irrelevant.

**`lastPpgRecoverMs` is reset too**, so a new workout can recover immediately rather than inheriting
the previous session's cooldown. Correct, and easy to have missed.

## Checked and clean

- `node --check` passes; the diff is ES5-clean (no `let`/`const`/arrow/spread).
- Firmware only — 1 file, +37/−9. No PHP touched, so the server suite is unaffected.
- Function-declaration hoisting covers `resetPpgWatchdog` being defined (~line 698) below its call
  sites at ~1042 and ~2664.
- The recovery's `setOptions({hrmGreenAdjust:true})` merges rather than replaces, so `hrmSportMode`
  and `hrmPollInterval` survive the power-cycle.

## One nit (not blocking)

The comment above `recoverDeadPpg` still reads *"The override latches for the rest of the session"*.
That was mine and it is now wrong — the latch is per **workout**. Worth correcting on the next touch
of this file; the behaviour is right, only the prose is stale.

## Still open from the original review request

- **Q2 — can `v === 0` occur legitimately?** Partly retired by fix 1 (the false-fire path is gone),
  but the underlying question is unanswered: I still have no on-device distribution of PPG values, so
  `ppgDeadMs = 12000` remains a judgement call rather than a measured threshold.
- **Q5 — `ppgFieldCode` is not persisted past the phone.** Untouched. See below.

## Next increment — persist `ppgFieldCode`

This is the highest-value next step, and it is the one thing that would have made the original
diagnosis a single query instead of an afternoon.

The band already sends it: byte 1 of the T1 frame header, `extractPPG` sets it to the index of
whichever `PPG_FIELDS` entry it used, or **255 when the event carried none of them**. It dies at the
phone. Because of that, "the HRM-raw event lost its field" and "the sensor genuinely read 0" are
indistinguishable in storage — and those two point at completely different root causes (a
`setOptions`-on-a-running-HRM bug vs the LED). We are one byte away from knowing which.

Suggested split:
- **Dev agent:** surface the header byte through `FrameDecoder` → `PpgWindow`, and include it in the
  uploaded window payload (alongside `sample_rate_hz`). iOS + TitanCore — I can't compile Swift.
- **Henry:** persist it on `device_ingestions.result_refs` in `ProcessWindowJob`, and add it to the
  raw-window JSON so the next dead window answers the question on sight.

Ship the phone side and push; I'll take the server half in the same pass.

## Still blocked on Alex

Neither of us can validate this. It needs a **band reflash** (not a TestFlight build) and a ride.
Acceptance, as set out in the previous review:

| `ppgRecoveries` | HR | reading |
|---|---|---|
| 0 | tracks effort | the still-wrist gate fix was sufficient; the LED is not implicated |
| >0 | tracks effort | the watchdog is carrying the session — the LED hypothesis needs settling |
| >0 | still wrong | neither fix addressed the cause; re-open |

The confirming experiment for the LED (`setForcedOP({… ledCurrent:"auto" …})` mid-workout) is now
actually runnable — before `3e45fd2` the leaked latch would have quietly invalidated it.
