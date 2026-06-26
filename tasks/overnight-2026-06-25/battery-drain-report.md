# Overnight battery drain — root cause, fix shipped, and the rest of the plan

_You wore the band all night on "recording + sleep" and it lost >50% in ~8 h. Here's why,
what I changed, and what's left. The firmware change is on branch
`overnight/hardening-and-research` — it needs ONE night of on-device validation because I
can't flash/test the watch myself._

## TL;DR
- **Root cause: sleep mode kept the optical HRM powered on continuously at 25 Hz all night.** That's
  the single most expensive thing a Bangle.js 2 can do (~5 mA — the CPU never deep-sleeps to run the
  PPG algorithm). 175 mAh / ~5-6 mA ≈ **~29-36 h to empty → ~50% in ~8-14 h.** That math lands exactly
  on what you saw. The HRM alone is **~85-90% of the drain.**
- **This was by design, not a bug.** The firmware comment literally said _"sleep → continuous 25 Hz +
  raw PPG (HRV needs it), no duty-cycle."_ It traded battery for beat-by-beat overnight HRV. That trade
  was too expensive.
- **Fix shipped:** sleep now **bursts** the HRM — ~30 s of clean 25 Hz raw PPG every 3 min (~17% duty)
  instead of running it flat-out. HRV is still captured all night (each burst logs raw PPG + RR to
  flash exactly as before), just sampled in windows. Expected: **~50%/night → roughly ~8-10%/night**,
  i.e. the band survives the night with margin to spare. Numbers are CFG-tunable.

## Why Whoop lasts 14 days and we couldn't survive a night
Whoop runs on a ~195 mAh cell — basically the same chemistry/capacity as our 175 mAh — yet lasts ~14×
longer. It's **architecture, not battery**. Whoop does the structural opposite of what we were doing:
1. **No display / backlight** (our backlight is 17 mA — 57× idle — if it wakes on a pillow all night).
2. **Duty-cycled, motion-gated PPG.** Whoop samples ~1 Hz at rest and bursts higher only under motion.
   Notably it does **not** raise the sample rate during sleep — it just weights HRV toward deep sleep.
3. **Store-and-forward, not continuous streaming.** It buffers days on-device and bulk-syncs on
   reconnect; out of range it stops retrying instead of burning the radio hunting for the phone.
4. **Ultra-low-power MCU that sleeps between bursts.**

We were violating #2 (HRM always on) and, when connected, #3 (continuous stream link). The fix below
addresses #2 — the dominant cost. #3 only bites when the phone is in range overnight; when you sleep
with the phone away the band is already offline and logging to flash.

## What I changed (firmware) — `firmware/banglejs/titan.app.js`
A **sleep HRM duty-cycle**, mirroring the proven rest-mode duty-cycle the firmware already had (and
which sleep mode explicitly bypassed):
- New CFG knobs: `SLEEP_DUTY: true`, `SLEEP_DUTY_ON_MS: 30000` (30 s burst), `SLEEP_DUTY_PERIOD_MS:
  180000` (every 3 min). ~17% duty → roughly 6× the HRM battery life.
- New `sleepModeActive()` + `sleepDutyTick / startSleepDuty / stopSleepDuty`. The tick powers the HRM
  on, applies the 25 Hz rest cadence (clean RR + raw PPG for HRV), then powers down after the window.
- `reconcileHrm()` now routes: **sleep → burst duty-cycle**, rest+offline → per-minute duty-cycle,
  everything else (live/workout) → continuous. **Fail-safe:** if `SLEEP_DUTY` is flipped off, sleep
  falls back to the old continuous behaviour, so a night is never left un-sampled.
- `stopStreaming()` tears the sleep timers down; the stale "no duty-cycle" comment is corrected.
- Why HRV still works: during sleep `restModeActive()` is false, so each ON window logs **raw PPG (T2)
  + HR (T1)** to flash through the normal path — the server gets per-burst nocturnal HRV across the
  whole night, just windowed instead of continuous (which is how Whoop computes it anyway).

`node --check` passes. **Cannot be tested without flashing the watch** — please run one night on it.
If overnight HRV looks thin in the morning report, widen `SLEEP_DUTY_ON_MS` (e.g. 45-60 s) or shorten
the period; both are one-line CFG edits.

## What to validate tomorrow morning (the victory condition)
- **Battery:** a full sleep session should drop **≤ ~6-10 percentage points**, not 50%. If it's still
  high, the screen is probably waking overnight (see #3 below).
- **Sleep report:** confirm the morning sleep summary still has HRV / staging — i.e. the bursts gave
  the server enough raw PPG. If HRV is missing or noisy, bump the ON window.

## The rest of the plan (not yet done — your call)
Ranked by impact. #1 is shipped above; these are the follow-ons that take us from "survives the night"
to "Whoop-class ~3-6%/night."

| # | Change | Saving | Effort | Status |
|---|---|---|---|---|
| 1 | **Duty-cycle the sleep HRM** | ~50% → ~8-10%/night | Med | **✅ shipped (needs device test)** |
| 2 | **Don't hold a live BLE stream link overnight** — log to flash, burst-sync on reconnect. Let the link park (~0.5 mA → ~0.01-0.08 mA). | up to ~50× the link cost | Med-High | proposal — only matters when phone is in range at night |
| 3 | **Guarantee LCD/backlight OFF + disable wake-on-twist during a sleep session.** A screen waking against the pillow is up to 17 mA in bursts. | several %/night | **Low** | proposal — worth doing next, cheap |
| 4 | **Drop accel rate while still + keep firmware auto power-save on** (don't pin `setPollInterval`; poll on a coarse timer). | 0.3 → 0.15 mA | Low-Med | proposal |
| 5 | **HRM ref-count hygiene** — stable appID, pair every `setHRMPower(1)` with a `(0)`. One leaked ref silently pins the HRM on and re-creates this drain. | prevents regressions | Low | recommend as a standing rule |

**Optional premium path:** a BLE chest strap (`bthrm`, e.g. Polar H10) offloads HR entirely — far more
motion-robust and avoids the ~5 mA on-watch optical cost. Power-user option, not the default.

## Target
A well-optimized Bangle.js 2 night should cost **~3-6%** (≤ ~0.75%/h). Fix #1 alone should get us from
~50% to roughly **~8-10%/night**; #2-#4 close the rest of the gap. Order of operations is unambiguous:
**the always-on HRM was ~85% of the problem and is now duty-cycled** — that's the one that mattered.

_Full sourced research (Whoop teardown numbers, the Bangle power table, every claim verified against
primary sources — Espruino docs, firmware source, forum) is in the workflow output; key citations
inlined above._
