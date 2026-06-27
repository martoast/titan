# Chest-strap HR integration — scope & plan

> **STATUS: BUILT 2026-06-27.** All pieces below shipped. iOS BUILD SUCCEEDED + TitanCore 26 tests +
> ActivitySealTest 10/10 + WorkoutReaction/StableHrMax 7/7. Files: `StrapManager.swift` (new),
> `HrReading.source` + `WorkoutAssembler.addStrapHr`/`hr_source` (TitanCore), `FrameRouter.ingestStrapHr`,
> `AppModel` strap wiring + pairing, `DevicesView.strapCard`, `project.yml` permission copy,
> `SealActivityJob` chest-strap precedence, `WorkoutCoach` note. **In-workout HRV (RR intervals →
> RMSSD) now also built** (StrapManager parses RR, rides the window as `hr_rr_ms`, seal computes RMSSD
> via `/process/hrv` → `activity_sessions.workout_hrv_ms`, shown as an "HRV" tile on the run detail) —
> optional, null when the strap omits RR. iOS ships via your Xcode/TestFlight build; server auto-deploys.

**Date:** 2026-06-27
**Why:** Measured on real ECG data (`tasks/hr-accuracy/findings.md`), wrist PPG during running is ~24 bpm
MAE and during lifting is effectively unusable (grip + isometric occlusion). No wrist algorithm — ours,
BeliefPPG, or Whoop's — fixes this; it's physics. The honest path to accurate **running + lifting** HR is
a BLE chest strap. Wrist PPG stays the always-on **rest / sleep / recovery (HRV)** signal, where it's
genuinely accurate. This is the T3 path the firmware research already flagged.

## Key decision: the phone bridges the strap, NOT the watch

The iPhone app is already a capable BLE central (`BandManager`, CoreBluetooth, background, state
restore). A chest strap speaks the **standard BLE Heart Rate Service** — so the phone can talk to it
directly with the same machinery. Do NOT try to make the Bangle a second BLE central (Espruino central
role is flaky, and it would murder the watch battery). Architecture:

```
Polar H10 / Garmin HRM / Wahoo TICKR ──BLE HRS──▶ iPhone (StrapManager) ──▶ WorkoutAssembler ──▶ server
Bangle band ──NUS──▶ iPhone (BandManager) ──▶ rest/sleep PPG + HRV (unchanged)
```

Works with ANY standard strap (Polar H10/H9/OH1, Garmin HRM-Pro, Wahoo TICKR, Coospo, Magene…), not
just Polar — it's a generic profile, a real selling point for an open-source product.

## The BLE specifics (standard, well-documented)

- **Heart Rate Service** `0x180D`, **Heart Rate Measurement** characteristic `0x2A37` (notify).
- Measurement packet: flags byte, then HR.
  - bit0: HR format — 0 = uint8, 1 = uint16.
  - bit4: **RR-interval present** → one or more uint16 RR values in **1/1024 s** units after the HR.
  - (bits 1-2 sensor-contact, bit3 energy-expended — optional.)
- **Polar H10 also gives RR intervals** → real beat-to-beat data we can use for **in-workout HRV** and
  a far cleaner TRIMP/strain (a genuine bonus the wrist can't match under load).
- Optional: Battery Service `0x180F` / `0x2A19` (we already read this for the band).

## Where it plugs into the existing pipeline (minimal, reuses everything)

HR already flows: `FrameRouter.onHr` → `HrReading` → `WorkoutAssembler.addWorkoutHr` → workout window →
server seal. The strap rides the SAME rails.

1. **New `ios/Titan/Sources/BandSync/StrapManager.swift`** — a `CBCentralManager` mirroring BandManager:
   scan for `0x180D`, connect, subscribe `0x2A37`, parse flags/HR/RR, remember the chosen peripheral's
   `UUID` (so we reconnect only to the user's strap), background + state restore. ~1 file, ~150 lines,
   closely modeled on BandManager.
2. **Inject strap readings into the workout** — give `WorkoutAssembler`/`HrReading` an explicit source
   so the seal can prefer it. Cleanest: add `enum HrSource { case wristPpg, chestStrap }` to
   `HrReading` (default `.wristPpg`, keeps existing call sites) and have StrapManager push
   `HrReading(bpm:, conf: 100, sport: 1, source: .chestStrap)` into the assembler — exactly like
   `FrameRouter.ingestPhoneGps` injects phone GPS into the same assembler. RR intervals ride along for
   workout HRV.
3. **Precedence** — when a strap is connected during a workout, the workout window's HR is strap HR and
   carries `hr_source = 'chest_strap'`. The seal job (`SealActivityJob`) currently prefers
   `ppg_inmotion`; add `chest_strap` ABOVE it in precedence (strap > server-recomputed PPG > on-chip).
4. **Live UI** — `AppModel.handleHr` already drives the live bpm + run start-gate from `HrReading`;
   strap readings feed the same path, so the live workout panel "just works", with a small "chest strap"
   badge.

## Server changes (small)

- `app/Models/ActivitySession.php`: add `'chest_strap' => 'chest strap'` to the `hr_source` label map
  (next to `ppg_inmotion`).
- `app/Jobs/SealActivityJob.php`: if the workout windows are tagged chest-strap, set
  `$hrSource = 'chest_strap'` and skip/deprioritize the in-motion PPG recompute (strap wins outright;
  no coverage gate needed — it's reference-grade).
- (Bonus, later) if RR intervals were captured, run them through the existing `hrv.py` time-domain path
  for an accurate **in-workout HRV / parasympathetic-load** metric — something the wrist can't do under
  motion. Net-new value, not just parity.
- `WorkoutCoach.php`: extend the HR-source note ("HR from your chest strap") — already special-cases
  `ppg_inmotion` at line 74.

## Pairing UX

A "Pair a heart-rate strap" row in `DevicesView` (next to band pairing): tap → scan `0x180D` → list
strap names + RSSI → user taps theirs → store the `UUID`. Mirror the band's pairing card. On a workout,
if a paired strap is in range it auto-connects and takes over HR; if not, fall back to wrist PPG
(peaktrack). No firmware change, no reflash — this is entirely phone + server.

## Effort estimate

| Piece | Effort |
|---|---|
| `StrapManager.swift` (scan/connect/parse/restore) | ~0.5–1 day |
| `HrReading.source` + assembler/seal precedence | ~0.5 day |
| Pairing UI in DevicesView + live "strap" badge | ~0.5 day |
| Server: hr_source label + seal precedence | ~0.5 day |
| Bonus: in-workout HRV from RR intervals | ~0.5–1 day (optional, later) |

**Total ~2–2.5 days** for accurate running + lifting HR (the two activities wrist PPG can't do), with no
firmware/reflash and no new backend dependencies. This is dramatically less work and risk than
productionizing BeliefPPG (TensorFlow in the image + a fine-tuning data-collection campaign) for a
smaller, less reliable gain.

## Not doing / deferred
- Making the Bangle a BLE central to the strap (battery + Espruino central instability).
- ANT+ straps (BLE-only; ANT+ needs extra hardware on iPhone — not worth it, modern straps are BLE).
- BeliefPPG (see findings.md) — revisit only if a user refuses a strap AND we run a data campaign.
