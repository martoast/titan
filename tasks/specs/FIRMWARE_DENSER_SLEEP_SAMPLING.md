# Firmware — densify overnight sleep sampling (battery headroom confirmed)

**Spec for the dev agent · from Alex + Henry · 2026-07-10 · unblocks SLEEP_TIMELINE_V2**

## Why now

Alex confirms the Bangle.js 2 comfortably lasts a full night + day on the current draw — **we have
battery headroom to spend on data density.** The v2 sleep timeline's one soft spot was that movement/HR
are sparse (~17% overnight duty). We can largely remove that constraint. Two levers, VERY different cost.

## The key realization — movement is nearly free, HR costs battery

- The **accelerometer is already always-on**: `motionEMA` is updated on every accel event
  (`titan.app.js:717`, `motionEMA = motionEMA*0.92 + d*0.08`) to gate the HRV bursts. The firmware itself
  notes "motionEMA is the always-on accel signal (**gating is free**)" (`:146`). The KX022 accel draw is
  negligible next to the VC31B PPG LED.
- Movement data is sparse overnight **only because accel is LOGGED just during the 17% HRM bursts**
  (and workouts, T6). The wrist's tossing is being *sensed* the whole night; it's just not *recorded*.
- HR/HRV, by contrast, needs the PPG LED on — that's the real battery cost, duty-cycled at
  `SLEEP_DUTY_ON_MS 30000 / SLEEP_DUTY_PERIOD_MS 180000` (~17%, `:140-141`).

## Lever 1 (do fully — near-zero battery) · continuous overnight motion

Log a lightweight per-epoch motion value **continuously** overnight, independent of the HRM burst.
There's already a natural home + cadence: the **T5 rest HR-trend** frame banks one point every 30 s at
offline rest (`HR_TREND_MS: 30000`, `:129`). Add the current `motionEMA` (and/or a 30 s accel-std) to
that same 30 s trend point — or add a tiny dedicated per-30s motion frame if T5 is awkward.

Result: **near-continuous movement coverage** (every 30 s = one epoch) at essentially no extra battery,
because the accel is already spinning for gating. This is THE change that makes the v2 "movement strip"
dense instead of dotted — the literal "where was I tossing" band Alex asked to see, all night, not just
the 17% of it that happened to coincide with an HRV burst.

**Server side:** the seal/`SleepDetail` motion_series (see SLEEP_TIMELINE_V2 §3) should prefer this
continuous per-epoch motion channel when present, falling back to the burst-window accel/PPG-quality
proxy for older data. epoch grid stays 30 s, so it drops straight into the existing hypnogram alignment.

## Lever 2 (now affordable — costs PPG battery) · widen the HRV duty for denser HR

With headroom, raise the overnight PPG duty so the HR-peaks overlay + nocturnal HRV get denser. Tune
`:140-141`. Suggested first step (conservative ~2×): `SLEEP_DUTY_PERIOD_MS 180000 → 120000` (~25% duty),
keep `SLEEP_DUTY_ON_MS 30000`. Aggressive (~33%): period → 90000. Keep the still-gate
(`SLEEP_STILL_MOTION`) — a burst fired mid-toss is wasted LED on signal the server rejects, so denser
bursts should still skip when moving.

**Measure, don't guess:** ship one step (e.g. period 120000), have Alex wear it 2–3 nights, check the
morning battery % and the new HRV/HR coverage, then decide whether to push to 90000. HRV quality also
matters — more short bursts isn't strictly better than fewer clean ones; watch the server's artifact_pct
and valid-beat counts, not just raw coverage.

## Ordering & guardrails

1. **Lever 1 first** — it's near-free and delivers the movement density that carries the v2 timeline.
2. **Lever 2 second, measured** — one increment, verify battery over real nights before going further.
3. Firmware ships on-device (watch reload) — these are `titan.app.js` constant changes, not server
   deploys. Keep the changes to the CFG block so they're one-line tunables.
4. Watch flash budget: the overnight log sizing math is at `:65-70` (~2 MB/night at PPG-only). Adding a
   4-byte-ish motion sample per 30 s (~960 samples/night) is trivial; a denser PPG duty grows the T2 log
   proportionally — sanity-check it still fits the 8 MB flash across a worst-case unsynced multi-night gap.

*The battery was the reason we sampled in bursts. The battery's fine. Let's record the whole night — the
movement for free, the HR for a little, and turn the honest-but-dotted timeline into an honest-and-dense
one.*
