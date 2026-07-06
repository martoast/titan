# Titan HR power — continuous low-power PPG: experiment + final solution

**Date:** 2026-07-06
**Scope:** Bangle.js 2 firmware (`firmware/banglejs/titan.app.js`), on-device measurement.
**Status:** Shipped. Experiment concluded; final architecture in place; one dimension (LED→power) deferred to a bench meter.

---

## Executive summary

**The blocker wasn't architecture — it was measurement.** We had competing theories about where the power
goes (LED current? sample rate? per-sample JS callbacks? FIFO wakeups?) and no on-device data to settle them.
So we built the instrumentation first, then used it to adopt the wins and kill the wrong theories with data
instead of argument.

| Optimization | Result |
|---|---|
| Remove duty-cycled HR | ✅ adopted |
| Continuous native HR | ✅ adopted |
| Raw-sample-listener gating | ✅ **largest measured win** |
| FIFO batching (reg 0x13) | ❌ rejected — raised CPU, near-overflow FIFO |
| Resting LED-current tuning | ⏳ pending — needs a bench current meter |

**Every result below is specific to this platform** (Bangle.js 2 · nRF52840 · Espruino JS runtime · VC31B
PPG). They are not inherent properties of PPG or of HRV capture in general.

---

## 1. Goal

Make Titan's heart-rate sensing **continuous and low-power** — inspired by the continuous-sensing architecture
used in premium wearables such as WHOOP, rather than a duty-cycled burst. (We have not reverse-engineered
WHOOP; this is a design goal, not a claim of parity.) HR should be published every second a good lock holds,
the sensor should never go fully dark at rest, and battery should survive multi-day wear on a 175 mAh cell —
without sacrificing the raw waveform where HRV genuinely needs it.

The blocker wasn't architecture — it was **measurement**. We had competing theories about where the power
goes (LED current? sample rate? per-sample JS callbacks? FIFO wakeups?) and no on-device data to settle them.
So the first deliverable was instrumentation; the second was using it to kill the wrong theories.

---

## 2. What we built (the final solution)

### 2.1 Continuous-HR model (the core)
- The HRM/PPG is **powered on continuously whenever streaming** — `reconcileHrm()` holds `setHRMPower(1)`;
  the old rest-burst machinery (`restModeActive`/`restDutyTick`/`restBurstClose`/`REST_DUTY_*`) is **deleted**.
- **Confidence gates *publishing*, not *sampling*.** `onHRM` emits a reading only when `conf >= confidenceTarget`
  (90); below that it **holds last-good** and publishes nothing. No more multi-minute "HR disappeared" gaps.
- **On the Bangle.js 2, delivering the raw waveform *into JavaScript* is the expensive path — so it's gated by a
  *listener*, not always on.** `setRawCapture(on)` attaches/removes the `HRM-raw` listener (idempotent
  `Bangle.on`/`removeListener`). It's on **only** during workouts (continuous) and accel-gated sleep HRV bursts —
  removed at 24/7 rest, so the per-sample event never fires into JS. This is the single biggest power lever and
  it's driven purely by `analysisDepth`.

**Why this is the right split (validated by the experiment + VC31 source):** the 1 Hz `HRM` algorithm runs in
native C (175-tap FIR + peak detect + median) and lets the CPU idle; the profiler attributes ≈0.7 mA to it (an
internal firmware estimate, see §2.3, not a direct electrical measurement). The `HRM-raw` per-sample JS callback
is the ~+3 mA cliff. So the cost is not the *waveform itself* — it's **exporting every raw sample into the
JavaScript runtime**. Native HR estimation is inexpensive on this platform; per-sample JS export is expensive.
Gate the export, keep the native HR.

### 2.2 Operating-point controller (adaptive, config-driven)
A closed loop that changes only **how hard the pipeline is driven**, expressed as data:

| Point | sampleRate | LED | analysisDepth | when |
|---|---|---|---|---|
| `REST` | 25 Hz | auto | `hr` (raw off) | light motion / weak lock at rest |
| `STILL` | 25 Hz | auto | `hr` (raw off) | dead-still desk / quiet sleep |
| `WORKOUT` | 50 Hz | 0xE0 | `full` (raw on) | in-motion: fast raw + bright LED |

`pickOperatingPoint()` chooses from **signal quality** — `motionThreshold` (0.05), `confidenceTarget` (90),
`batteryThreshold` (15%) — all config numbers, no literals in the code. `STILL` was moved 12.5 Hz → 25 Hz
because 80 ms polling sits below the FIR's 50 Hz design point and the 20–40 ms quality sweet spot.

### 2.3 Instrumentation (dormant in production, our measurement rig)
`CFG.PROFILE` (off by default, **zero cost when off**) logs one CSV row/sec to a capped 2-segment rolling
Storage file. 18 columns incl. **per-device microamp estimates from `E.getPowerUsage()`** (`pwrCPU`/`pwrHRM`/
`pwrBLE`/`pwrTotal`) — a *firmware power model*, not a direct electrical measurement — plus `confidence`, `bpm`,
`motionMag`, `env` (ambient light), `fifoDepth`, `dropped`. Rows buffer in RAM and flush every 30 s so the flash
write doesn't spike the CPU it's measuring. Experiments run via `setForcedOP({...})` — **config only, no reflash
per run**.

The distinction that makes the model usable, verified from Espruino source: `E.getPowerUsage()`'s **CPU term is
awake-time-derived** (`CPU = 3 + 4000·273152/sysTickTime`, ∝ 1/awake-gap) — so while it's still a model, its
input is a *real measured quantity* (CPU awake time), and it genuinely tracks per-sample wakeup load. Its **HRM
term, by contrast, is a fixed constant (700 µA)** — a hardcoded firmware estimate, not derived from anything
measured, and blind to LED current. That asymmetry is why the CPU axis of the experiment is trustworthy on-device
and the LED axis is not (§3).

---

## 3. The experiment: does FIFO batching cut the raw-capture cost?

**Hypothesis.** The VC31B FIFO IRQ divisor (`fifoIntDiv`, reg 0x13, pinned to 1 by the driver) could be raised
so the HRM IRQ fires every N samples; the C driver drains all N per wake and the FIR still sees each sample →
**N× fewer per-sample CPU wakeups, no waveform loss.**

**Method.** On-wrist, `setForcedOP` stepped a matrix at 50 Hz / raw-on: `m_20_1` (fifoBatch 1), `m_20_2` (2),
`m_20_4` (4), ~3 min each, profiler logging. Analysis = median `pwrCPU` per group in a like-for-like state.

**Result — the hypothesis is wrong.** Comparing the only apples-to-apples state (still / on-table, raw on):

| Cell | fifoBatch | median pwrCPU | fifoDepth range |
|---|---|---|---|
| `m_20_1` | 1 | **~900 µA** | 0–2 |
| `m_20_4` | 4 | **~1680 µA** | 0–**62** |
| `m_20_2` | 2 | **~2400 µA** | 0–**62** |

1. **Batching *raised* CPU, not lowered it** — both batched cells sit above the unbatched ~900 µA floor, and
   non-monotonically (4 < 2, but both > 1).
2. **fifoDepth climbed to 58–62 against the 63 ceiling** — writing 0x13 desynced the driver's drain and let the
   FIFO nearly overflow (the documented "0x13 also gates the env/wear-detect IRQ cadence" risk, on real silicon).
3. **Motion was not the confounder** — `m_20_4` had *more* motion than `m_20_2` yet *lower* CPU, so the ordering
   tracks fifoBatch, not wrist activity.

**Observed vs. inferred.** What the measurements *establish*: batching did not reduce CPU awake time — the
awake-time-derived `pwrCPU` held or rose across `fifoBatch` 1→2→4, and `fifoDepth` approached overflow. What
they do *not* establish is the mechanism. A likely explanation, consistent with the VC31 driver source, is that
the same number of `HRM-raw` JavaScript callbacks are ultimately executed (batching only clusters them into one
wake rather than eliminating any), while draining N FIFO entries per interrupt adds overhead — so total awake
work is unchanged or slightly higher. That is an inference, not a measured fact; the retirement decision rests on
the *observed* result, not the proposed cause.

**Caveats (honest).** (a) The state that matters most — worn, mid-HRV-capture — couldn't be cleanly compared
because the batched cells drifted onto the table (bpm held, confidence ~0); but batching failing in the easy
still case *plus* the mechanism means there's no reason to expect a worn win. (b) The `dropped` column is a weak
heuristic (`fifoDepth ≥ 4 → ++` once/sec), so its climbing under batching is expected-by-design, **not** proof
of sample loss — the real signal is the near-ceiling `fifoDepth`.

**Decision: RETIRE FIFO batching.** It is not a viable lever for cutting raw-capture cost. The real lever is
gating the raw **listener** (§2.1), which the continuous design already does.

**Also confirmed / still open.** `pwrHRM` read a fixed 700 µA in every single row regardless of LED current —
confirming that the CPU axis of the model responds to real changes on-device (its input is measured awake time)
but the **LED-current → power axis does not exist in the model at all** (a hardcoded constant). Settling whether
a dimmer resting LED saves meaningful energy therefore requires a bench meter (e.g. Nordic PPK2 on the HRM rail).
That is the one remaining open experiment; the dormant profiler is retained precisely for it.

---

## 4. What changed in the code

- **Removed** the `fifoBatch` write-knob from `applyOperatingPoint()` and its OperatingPoint docs — a legacy
  `fifoBatch:N` field on an OP is now silently ignored (reg 0x13 untouched).
- **Kept** the continuous-HR model, the auto operating-point controller, and the profiler (off by default).
- **Kept** the `fifoDepth`/`dropped` telemetry columns as a general sensor-health signal (in production the
  driver drains every sample, so `fifoDepth` sits ~0).
- **Sim:** scenario 38 rewritten from a batching-knob test into a **regression guard** that `fifoBatch:N` is
  ignored; orphaned sim helpers removed. **147/147 checks pass.**

Wire protocol unchanged (T5/T1 byte-identical) → **firmware reflash only, no iOS/server change.**

---

## 5. Bottom line

We replaced a duty-cycled burst with **one continuous PPG pipeline** whose only real cost lever is *when the
raw-sample listener is attached* — native HR estimation is inexpensive on this platform and runs always-on,
while exporting every raw sample into the JavaScript runtime is expensive, so it's captured only for workouts and
sleep HRV. We built the instrumentation to prove it, and used it to kill the one plausible-but-wrong optimization
(FIFO batching) with data instead of argument. The sole unresolved question — how much the resting LED current
actually costs — cannot be answered by the on-device power model (its HRM term is a hardcoded constant) and needs
a bench current meter; it's queued for that session. Everything else is shipped.
