# 11 — Day-One Test Suite

The watch is built around a chain of **hypotheses**: that the Bangle.js can stream a clean enough PPG, that
the server can recover IBI/HRV from it, that activity priming reaches the watch, that GPS arms outdoors, that
an overnight log survives and syncs. This is the script to run **the day the band arrives** to confirm each
one — or to find out exactly which link is broken before building on top of it.

Two halves:
- **Tooling** — the *Day-1 test suite* panel in `firmware/banglejs/bridge.html` (prime buttons, frame monitor,
  signal sanity, one-click checks). Open it in Chrome (desktop — Web Bluetooth), fill the server fields, Connect.
- **This doc** — the full hypothesis list, each with a procedure, the expected result, and a clear pass bar.
  The automated checks cover the indoor/at-desk ones; the rest (GPS outdoors, a real night) are manual by nature.

> Honesty rule for the day: a green check means *we observed the expected signal*, not *it's validated*. Real
> validation is the leave-subjects-out work in `09-validation-results.md`. Today is "does the pipe carry water."

---

## 0. Pre-flight (5 min)

| # | Step | Pass bar |
|---|---|---|
| 0.1 | Flash `firmware/banglejs/titan.app.js` to the Bangle.js (App Loader / `E.setBootCode`) | Watch boots to the TITAN screen |
| 0.2 | `php artisan serve` (or the deployed URL); pair a device via the app → copy device id + the one-time secret | You have a `device_id` + 32-byte hex secret |
| 0.3 | Open `bridge.html` in Chrome; fill Ingest URL + Device ID + secret | Fields populated |
| 0.4 | Click **Connect watch**, pick the Bangle | BLE pill → `connected`; press the watch button → UI shows `REC` |

---

## 1. Automated checks (the bridge panel — "Run all checks")

Each maps to a hypothesis. Click **Run all checks** (or run them one at a time).

| Check | Hypothesis under test | How it's measured | Pass bar |
|---|---|---|---|
| **Activity endpoint** | The device can read the coach-set activity over HMAC | Signed `GET /api/devices/activity` | HTTP 200, JSON `{active,…}` |
| **Streaming?** | The watch streams live T1 frames over NUS | BLE frame count rises over 2.5 s | ≥ 1 frame (REC on) |
| **PPG signal?** | The optical front-end produces a *pulsatile* signal, not a flat line | Variance of the last ~150 PPG samples | var > 50 with a finger on the sensor |
| **Prime → HR?** | A `C1:` activity command reaches the watch and starts a workout | Send `run`, count new **T5** HR frames in 5 s | ≥ 1 T5 frame after priming |
| **Ingest round-trip** | The server accepts a signed biosignal batch | `flushBatch()` → POST `/ingest` | HTTP 202, batches counter +1 |

**Frame monitor** (always live): T1 live · T2 sleep · T4 gps · T5 hr · T6 offline-workout · T7 altitude. Use it
to *see* which capture paths are firing during any test below.

### Prime test (no server needed)
The **▶ Run / Cycle / Swim / Strength** buttons send a `C1:` straight to the watch; **■ Stand down** sends `C0:`.
Expected watch behaviour per the firmware (`applyPriming`):
- **Buzz** (haptic ack), workout starts (UI: `workout: GYM`/`auto`).
- **Run/Cycle** → GPS powers on (UI `·sat` searching, `·gps` on fix) and **T5 HR frames** begin.
- **Swim/Strength** → GPS stays **off** (UI `·indoor`), workout + HR still active.
- **Stand down** → workout ends, GPS off, back to everyday sensing.

---

## 2. Manual hypothesis checks (can't be automated)

| # | Hypothesis | Procedure | Pass bar |
|---|---|---|---|
| 2.1 | **Accel classifies movement** | Shake / walk in place 10 s; watch `accel var` in the panel | var jumps from ~0 to clearly non-zero |
| 2.2 | **GPS arms outdoors & gets a fix** | Prime **Run**, go outside, walk 60–90 s | UI reaches `·gps`; **T4** count rises; speed > 0 |
| 2.3 | **GPS gives up indoors (battery)** | Prime **Run** indoors, wait `GPS_FIX_TIMEOUT` (90 s) | UI drops to `·indoor`; GPS powers down, workout continues |
| 2.4 | **Auto-workout from locomotion** | *Without* priming, walk briskly ~30 s | Workout auto-starts (UI `auto`) after `GPS_ARM_SEC` |
| 2.5 | **Manual gym workout doesn't auto-end** | Long-press BTN to start a gym workout; stand still 2 min | Workout stays active (no motion-lull end) |
| 2.6 | **Offline workout logs (T6)** | Disconnect bridge, prime/long-press a workout, move 1 min, reconnect | On reconnect: **T6** frames flush; server classifies the session |
| 2.7 | **Overnight log survives + morning sync** | Wear overnight disconnected; connect in the morning | **T2** frames stream on connect, then the log erases; a sleep_log appears |
| 2.8 | **Floors from altitude (T7)** | Climb a flight of stairs during a connected session | **T7** count rises; server floor count increments |
| 2.9 | **End-to-end coach priming** | In the coach chat say "going for a run"; keep the bridge connected | Within ~20 s the bridge logs "Primed watch for Run"; watch buzzes |
| 2.10 | **Server recovers HRV from raw PPG** | Stream 2–3 min seated, finger steady; check the app | A recovery/HRV value appears for the window (sanity, not accuracy) |

---

## 3. What "good" looks like at the end of day 1

- ✅ All five automated checks PASS.
- ✅ Prime buttons visibly drive the watch (buzz + GPS + T5).
- ✅ At least one **T4** (outdoor GPS), one **T2** (a nap or night), and one **T6** (offline workout) observed.
- ✅ The coach's "going for a run" reaches the watch via the bridge (2.9).
- ✅ One real recovery/HRV number produced from streamed PPG (2.10).

If a link fails, the frame monitor localises it fast: **no T1** = capture/BLE; **T1 but flat PPG var** = optical
contact/AFE; **no T5 after prime** = command channel or HR power; **202 fails** = auth/clock skew (|now−t|>300 s);
**activity 200 but watch never buzzes** = bridge→watch NUS write (check `rxChar`, console moved off BLE).

---

## 4. Notes & gotchas

- **Web Bluetooth is desktop-Chrome only** — no iOS, no background. This panel is a *bench* tool; real wear uses
  Gadgetbridge or the companion app. (See `02-firmware.md` §6.)
- **Clock skew** breaks HMAC: the server rejects if the laptop clock is >300 s off. Sync time if `/ingest` 401s.
- **Console relocation**: the firmware moves the JS REPL to USB so NUS is the command channel. If you need the
  BLE REPL back for debugging, comment out the `E.setConsole("USB", …)` line — but then priming commands will be
  eval'd as code. Don't ship that.
- **PPG variance threshold (50)** is a coarse "is it flat?" gate, not a quality metric — raw VC31 values are
  unitless and vary by skin/contact. A clearly non-flat trace is the signal; the server does the real SQI.
