# Titan watch simulator

An end-to-end, on-my-machine replica of the **band → phone** live-workout path, so the lift/run
start→hold→end sequence can be tested without real hardware.

```bash
node firmware/sim/scenarios.js
```

## Scenarios covered

1. Single connected lift — opens live, summarizes on end, seals an `ended` window.
2. GPS run — opens live, summarizes, seals a run window.
3. Drop-at-end — the band dropping at the end (fixed: confirm→end + summary; pre-fix: reproduces the "no summary, hangs live" symptom).
4. Transient BLE blip mid-lift — reconnects fast, exactly one summary (no premature end).
5. Consecutive lifts — both summarize + seal.
6. Phone-free lift — recovered from the ring on sync, seals `ended` strength + fires the catch-up signal, no phantom live run.
7. Run route — live distance from GPS + fixes on the sealed run window.
8. Sport→0 fallback — the `TA:end` frame is lost, the sport tag dropping to 0 still ends the run.
9. Phone-free run — recovered on sync as a run **with** a route.
10–11. Sleep — a session tracked live, and tracked offline then recovered on the morning sync (confirmed `T9` marker).

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

## Phone-free workouts (scenario 6)

A lift/run done with the phone **left behind or backgrounded** is logged to the band's ring and
recovered on the next sync. The firmware emits `TS:done` when the ring is fully drained; the phone
seals the recovered workout as an `ended` window (so the server seals it in seconds) and fires a
`checkForSyncedWorkout()` catch-up that shows the summary on the same sync — without popping a phantom
live run. The workout's kind is logged to the ring offline too (`emitActivityKind` appends `TA:` when
disconnected), so a phone-free lift still seals as *strength*, not a guessed accel classification.
