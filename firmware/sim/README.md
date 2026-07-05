# Titan watch simulator

An end-to-end, on-my-machine replica of the **band → phone** live-workout path, so the lift/run
start→hold→end sequence can be tested without real hardware.

```bash
node firmware/sim/scenarios.js
```

## What it actually runs

- **The real firmware.** `watch.js` loads `firmware/banglejs/titan.app.js` verbatim into a sandboxed
  VM with a mocked Espruino runtime (`Bangle`/`Bluetooth`/`NRF`/`Storage`/`g`/`setWatch`/…). Button
  presses, swipes, HRM/accel/GPS readings, and connect/disconnect are driven through the same events
  the hardware fires, so a firmware bug reproduces faithfully. Nothing about the workout logic is
  re-implemented on the watch side.
- **A faithful phone model.** `phone.js` mirrors the iOS live path — `FrameDecoder` (T1/T4/T5/TA +
  the frame-liveness gate), `WorkoutAssembler` (open/periodic/flush/build incl. the `ended` min-skip),
  `FrameRouter` (live vs replayed-backlog routing), and `AppModel` (the sport-edge start/end state
  machine, `endRun`, the summary gate, and the disconnect-confirm). **Keep it in sync** with those
  Swift files — the header comment lists them.
- **A shared virtual clock** (`clock.js`) drives both sides, so time-based logic on the watch
  (`setInterval`/`getTime`) and the phone (4s sport-loss, 12s suppress grace, 60s frame-liveness,
  the disconnect confirm) advances together, deterministically.

## Why it exists

It was built after a run of "I ended the workout on the watch and got nothing on my phone" reports.
The sim reproduced the real cause — **the band dropping at the end of a workout**, so neither the
`TA:{"k":"end"}` frame nor the `sport→0` frames reach the phone, leaving the run hanging live forever
(no summary, no seal). Scenario 3 asserts both the pre-fix symptom and the fix (a durable disconnect
is confirmed, then ends the run so the summary shows and the window seals as `ended`). It also caught
that an earlier hypothesis of mine (`lastSportWas1`) was **not** the reliable cause — the watch's
rest-duty `sport=0` frame resets that flag anyway.

## Known gap tracked here (not yet fixed)

Scenario 6: a lift **started while the phone link was released/offline** logs to the band's ring and
replays as backlog on the next sync — so there's no live sheet and no instant summary, only a delayed
`ended=false` seal. The retro-summary for a synced-after-the-fact workout is future work.
