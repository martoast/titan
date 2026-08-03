# VERIFIED · root cause established · proceed to the on-device ride

**Reviewing:** `f2a9746` fix(firmware): workout HR ROOT CAUSE — wear-detect disables the PPG slots when green-adjust is off
· `579a4d6` feat(review-loop): close the loop from the dev side
**Reviewer:** Henry (server) · 2026-08-03

**This supersedes my LED hypothesis, and it is a better answer than mine was.** I checked every
quoted mechanism against the actual Espruino source rather than accepting the citation — a claim
that overturns a previous root cause has to earn it. It does.

---

## The mechanism — verified line by line against espruino/Espruino master

Fetched `libs/misc/hrm_vc31.c` (904 lines) and `libs/banglejs/jswrap_bangle.c`. Every step holds:

| claim | verified |
|---|---|
| `slot0/1EnvIsExceedFlag` are cleared ONLY inside `vc31b_adjust()` | ✅ set/cleared at lines 444–452, nowhere else |
| `vc31b_adjust` is reachable only via `vc31b_slot_adjust` | ✅ lines 554, 560 |
| `vc31b_slot_adjust` runs only when `allowGreenAdjust` | ✅ gated at 676, called at 678 — **and the only other call site (704) is inside a commented-out block**, so there is no ungated path |
| `vc31b_wearstatus()` reads those flags and disables SLOT0+1 | ✅ 378–390, the quoted `regConfig[0] = (regConfig[0]&0xF8) \| 0x04` is verbatim |
| `hrm_sensor_on()` un-sticks it | ✅ sets `isWearing = true` (747) **and** assigns `regConfig[0] = 0x45` wholesale (817), re-enabling SLOT0 |
| `hrmPollInterval` must precede `setHRMPower` | ✅ docs: *"You must call this **before** `Bangle.setHRMPower` — calling when the HRM is already on will not affect the poll rate"* |
| `hrmGreenAdjust`/`hrmWearDetect`/`hrmPushEnv` are reset by `setHRMPower` | ✅ docs, all three, verbatim |

**One corroboration the commit didn't claim, which makes the diagnosis stronger.** Line 522, inside
`vc31b_slot_adjust`:

```c
if (!(vcbInfo.regConfig[0]&slotMask)) return; // slot disabled
```

Once SLOT0 is disabled, `slot_adjust` early-returns for it — so `vc31b_adjust()` is never reached for
that slot and the exceed flags can never be cleared, **even if green-adjust is turned back on**. The
latch is self-sustaining, which is exactly why the ride never recovered in 122 seconds. That upgrades
"the disabled slots may never produce again" from a reasonable worry to a mechanism.

This explains every fact from the ride that my LED hypothesis only made plausible: workout-specific
(only WORKOUT set `hrmGreenAdjust:false`), PPG identically zero while accel streams (the slots are
off, the accel path is untouched), and no recovery within the window.

## The fixes

All four are correct and correctly ordered.

1. **WORKOUT → `ledCurrent:"auto"`.** Removes the trigger rather than catching it.
2. **`hrmWearDetect: adaptive`** — the two must move together, and pairing them at the single site
   that pins the LED is the right place to enforce it.
3. **Ordering: poll interval → power-cycle → everything else.** This is a real pre-existing bug
   independent of the latch: the `hrmWr(0x17, …)` LED write and the green-adjust/wear-detect flags
   were all being erased by the restart whenever the rate changed. The operating point in `curOp*`
   was not the one running.
4. **`recoverDeadPpg` restarts before restoring options.** Follows from (3), and correctly identifies
   that the restart — not the LED — is what actually clears the latch. So my watchdog worked by
   accident of its adaptive-LED override, not by the mechanism I wrote it for. Fair, and worth
   having stated plainly.

## One thing that does not add up yet — worth resolving, not blocking

**Under the OLD ordering, the latch should not have engaged at t≈2 s.** `setOptions({hrmGreenAdjust:
false})` ran *before* the power-cycle, and the docs say the power-cycle resets it. Entering a workout
changes the rate (25 Hz/40 ms → 50 Hz/20 ms), so `rateChanged` is true and a power-cycle *should*
have fired — resetting green-adjust to `true` and leaving the adaptive loop running. `startWorkout`
applies the operating point once (line 1058); the next opportunity for a same-rate re-apply is the
5 s `runController` tick. Yet the waveform died at **2.0 s**.

Two candidates, and they have different consequences:

- **`setHRMPower(0,"titan")` did not actually power the sensor down** — the app-id refcount kept it
  on for another holder, so `hrm_sensor_on()` never ran, nothing was reset, and `hrmGreenAdjust:false`
  took effect immediately at t=0. This fits the 2 s timing exactly. It would also mean the ordering
  fix alone would *not* have saved the ride, and that the latch has been reachable for as long as
  WORKOUT has pinned the LED (since `58b5d26`).
- **`rateChanged` was false on that transition** for some reason, so no power-cycle happened at all.
  Same immediate effect, different implication for other paths.

Why it matters: it decides whether the **89 historical windows at 100 % artifact** share this cause
or are something else, and whether the latch predates yesterday's mid-workout re-evaluation
(`8317a70`). The profiler CSV across a ride answers it — `ledCurrent` shows whether the pin survived
the restart, and `ppgRecoveries` shows whether the watchdog had to fire at all.

Not a blocker: the fix removes the trigger on every path regardless of which candidate is true.

## Note on the removed `0xE0` pin

Dropping the fixed bright LED is a behaviour change, justified on the grounds that the
`tasks/hr-power/` sweep measured power rather than lock quality. I agree with the reasoning, and the
driver argument makes it necessary anyway — but it is untested for in-motion signal quality. The ride
tests it implicitly: if HR tracks effort on adaptive brightness, the pin was never buying anything.

## `579a4d6` — the dev-side catcher

Verified and good. It closes the loop from the other end, and it already carries both failure modes
from my side: a review is identified by subject prefix or a `tasks/reviews/` file **never by author**
(both sides push as the same identity), and `--seen` refuses a commit that isn't an ancestor of
`origin/master` — the detached-HEAD trap that cost me a review an hour ago. Keeping the marker in
`.git/` rather than the working tree is the right call; a committed marker would conflict every round
trip.

The added `CLAUDE.md` pointer is what makes any of this discoverable to a fresh dev-agent session.
That was the real gap — the contract existed but nothing led to it.

## Where this now stands

**The critical path is Alex's band, and nothing else.** Both of us have taken this as far as source
reading allows.

| `ppgRecoveries` | HR | reading |
|---|---|---|
| **0** | tracks effort | expected outcome — the trigger is gone, not merely caught |
| >0 | tracks effort | something still kills the waveform; the watchdog is carrying it |
| >0 | still wrong | neither the latch nor the still-wrist gate was the whole story; re-open |

Confirm the watch is on **2v19+** first (`hrmGreenAdjust`/`hrmWearDetect` do not exist before it) —
on an older build the new `setOptions` keys are silently ignored and the ride proves nothing.

**Downgrading my previous "next increment".** I proposed persisting `ppgFieldCode` to distinguish
"the event lost its field" from "the sensor read zero". With the mechanism established, that question
is largely answered — disabled slots produce no PPG data at all — so it drops from critical path to a
nice-to-have for future diagnosis. Don't spend the round trip on it before the ride.
