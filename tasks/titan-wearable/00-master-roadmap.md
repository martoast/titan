# Titan Wearable — Master Roadmap

The synthesis across all five layer plans (hardware, firmware, algorithms, platform, open-source).
This is the program plan: one set of phases that braid every layer together, sequenced so each step
de-risks the next. Read the five detailed docs for the depth behind each claim.

---

## 1. The vision in one paragraph
Whoop/Oura/Polar sell a subscription to see your own HRV, sleep, and recovery. But the **ingredients are
open**: HRV math is a 1996 standard (NeuroKit2), sleep staging from wrist HR+motion is published with code
(Walch 2019), recovery scores are just heuristics. The only genuinely hard parts are **motion-robust PPG
during the day** (which doesn't matter for recovery, computed overnight when you're still) and **battery +
comfort**. So we build a **dumb sensor that streams raw PPG + motion**, and run the open validated algorithms
**server-side in Titan**. The device becomes swappable; Titan owns the data; and the whole thing is
**self-hostable and open-source** so anyone can build their own and never pay a subscription or surrender
their data again.

## 2. The architecture (one diagram, every layer)
```
   BAND (dumb sensor)              PHONE BRIDGE            TITAN PLATFORM (self-hosted)
 ┌────────────────────┐        ┌────────────────┐     ┌───────────────────────────────────────┐
 │ nRF52840           │  BLE   │ companion app  │HTTPS│ POST /devices/ingest (HMAC-signed)     │
 │ MAX86141/MAXM86161 │═══════▶│ (or Gadgetbridge│════▶│   → MinIO (raw PPG/IBI/accel)          │
 │   PPG (raw)        │ IBI +  │  / Web BT P0)  │     │   → device_ingestions ledger          │
 │ BMI270 accel       │ accel  └────────────────┘     │   → Redis queue                        │
 │ littlefs buffer    │                                │        │                              │
 └────────────────────┘                                │        ▼                              │
        EDGE: peak-detect → IBI,                        │  FastAPI biosignal worker (Python)    │
        motion-gate, store-and-forward                  │   NeuroKit2 (HRV) · Walch (sleep) ·   │
                                                        │   TRIMP (strain)                      │
   GROUND TRUTH (validation)                            │        │                              │
   Polar H10 chest strap ─────────────────────────────▶│        ▼  idempotent upsert           │
   (raw RR, clinical-grade)                             │  recovery_logs · sleep_logs · workouts│
                                                        │        │                              │
                                                        │        ▼                              │
                                                        │  AI Coach (existing) → morning brief, │
                                                        │  nudges, the living goal image        │
                                                        └───────────────────────────────────────┘
```
**North-star metric: overnight RMSSD-based recovery.** Everything sequences around it because overnight PPG
(you're still) is the *easy, accurate* regime — we ship the highest-value metric first and hardest (daytime
in-motion strain) last.

## 3. The unified phase plan
Each program phase pulls the matching phase from each layer doc. Difficulty (1–10) shown per workstream.

### ▶ PHASE 0 — Prove the whole loop with off-the-shelf hardware (software-first)
*Goal: real, validated overnight HRV/RHR for both brothers — zero custom hardware.*
| Layer | Work | Diff |
|---|---|---|
| Hardware | Buy **2× Bangle.js 2** (streams raw PPG+accel over BLE in JS) + **1× Polar H10** (ground truth) | 2 |
| Firmware | Bangle.js JS app: `Bangle.setHRMPower(1)` → stream `HRM-raw` + accel over Nordic UART | 2 |
| Platform | **`POST /api/devices/ingest`** (reuse existing `wearable_connections` + HMAC verifier), idempotency, MinIO raw store, simulated + real payloads | 4 |
| Algorithms | **Overnight RMSSD + RHR** via NeuroKit2 (`ppg_process` → SQI gate → Kubios artifact fix → whole-night RMSSD); validate vs Polar H10 (Bland-Altman) | 3 |
| Open-source | Stay **private**; lock claim discipline; FTO/prior-art scan; reserve org + trademark | — |
**Exit criteria:** Bangle.js → Titan → correct overnight ln-RMSSD & RHR for Alex + Tester C, agreeing with the
Polar H10 (target: RHR bias ±2 bpm, ln-RMSSD CCC > 0.80). *This validates the entire thesis cheaply.*

### ▶ PHASE 1 — The Titan biosignal platform (the real moat)
*Goal: full recovery + sleep + strain flowing automatically into Titan, powering the coach.*
| Layer | Work | Diff |
|---|---|---|
| Platform | **FastAPI biosignal service** (Docker) + Laravel `BiosignalClient` + Redis `ProcessWindowJob`; `SealNightJob` for sleep; idempotent writes; `MetricsProcessed` event → coach | 6→8 |
| Algorithms | **Recovery score** (ln-RMSSD z vs 60-day baseline, 7-day smooth, +RHR +sleep) → **sleep/wake → 3-4 stage** (Walch) → **respiratory rate** → **strain** (Edwards eTRIMP, Strain 0-21, CTL/ATL/TSB) | 4→8 |
| Coach | Nightly recovery compute + **morning briefing** (per-profile timezone) consuming the new metrics | 5 |
**Exit criteria:** every morning, Titan auto-shows recovery, sleep stages, RHR, strain — from the Bangle.js
feed — and the coach references them. *Now Titan is a Whoop replacement on borrowed hardware.*

### ▶ PHASE 2 — Build the custom band
*Goal: 2 sealed, comfortable, multi-day bands that are ours.*
| Layer | Work | Diff |
|---|---|---|
| Hardware | Dev-board prototype (**XIAO nRF52840 + MAX30101 + LSM6DSOX**) → custom **PCB Rev A** (Raytac MDBT50Q + MAXM86161 + BMI270 + BQ25120A) → **wearable form factor** (SLA case, dual optical window, 150 mAh, ~2-3 day battery) | 4→8→9 |
| Firmware | NCS/Zephyr bring-up on DK + real AFE → custom GATT streaming + littlefs buffer → **power optimization** (IMU-gated PPG, burst-and-sleep) → MCUboot OTA + **companion app** (iOS background BLE) → custom-board firmware | 6→7→7→8→9 |
| Algorithms | **Tuning + multi-night validation** of our sensor vs Polar H10; per-user baseline calibration | 7 |
**Exit criteria:** both brothers wearing custom Titan bands nightly, metrics validated against the H10, ~2-3
day battery. *Fork [`uqjwy/whoop-alternative`](https://github.com/uqjwy/whoop-alternative) and
[HealthyPi Move](https://github.com/Protocentral/healthypi-move-hw) rather than starting from scratch.*

### ▶ PHASE 3 — Open-source it for the world
*Goal: a stranger can build their own Titan and own their data.*
| Layer | Work |
|---|---|
| Open-source | License map (CERN-OHL-S hardware / Apache firmware+algos+SDK / AGPL server / CC-BY docs); multi-repo `titan-wearable` org; **OSHWA self-cert**; Open Collective fiscal host; **NLnet/NGI grant**; reproducible build docs (iBOM, browser flashing, simulator, self-host compose); **Show HN launch** leading with the data-ownership grievance |
**Exit criteria:** first 3-5 external successful builds documented; contributors arriving; sustainable funding.

## 4. Key cross-cutting decisions (locked)
- **Dumb sensor + server-side algorithms.** Don't compute HRV/sleep on the watch — stream raw IBI+accel, run
  NeuroKit2/Walch in the cloud. Lets us improve algorithms without reflashing and reprocess history.
- **Device-agnostic ingestion.** One `POST /devices/ingest` accepts raw IBI, raw PPG, *or* summaries — so
  Bangle.js, Polar AccessLink (free), Apple Health export, and our band all feed the same tables. **Never
  vendor-locked.** (This supersedes the abandoned paid-Terra approach — the `wearable_connections` scaffold is reused.)
- **Overnight-first.** Recovery is computed from resting/overnight HRV → the motion-artifact problem mostly
  disappears for the metric that matters. 24/7 strain (motion-robust PPG) is explicitly the *last*, optional piece.
- **nRF52840** as the SoC (1.5 µA sleep — battery life lives or dies here; avoid ESP32's ~8-15 µA).
- **MAXM86161** PPG for the band (integrated optics + single supply, dodges MAX86141's SPI + external-optics
  complexity) — or MAX86141 if we want full optical control. **Avoid MAX86150 (EOL 2026).**
- **Polar H10** is the validation ground truth (99.6% RR accuracy) — buy it in Phase 0.
- **Storage:** MinIO object store for raw waveforms + MySQL `device_ingestions` ledger. Not a TSDB.

## 5. The biggest risks (and where they're handled)
| Risk | Severity | Mitigation / doc |
|---|---|---|
| **iOS background BLE** (can't sync overnight reliably) | High | Native companion app w/ State Restoration; design firmware for persistent notifying connection. `02-firmware §6` |
| **Multi-day battery** (PPG LEDs dominate draw) | High | IMU-gated PPG, burst-and-sleep, overnight-only duty cycle, rail gating. `01-hardware §3`, `02-firmware §5` |
| **Optical window design** (LED→photodiode crosstalk kills signal) | High | Dual separate windows + black divider + matte cavity; or a part with integrated barrier (MAXM86161). `01-hardware §2` |
| **Sleep staging accuracy** without EEG | Medium | Lead with sleep/wake + duration (solid); present stages with low confidence. `03-algorithms §3,§9` |
| **Crossing into a regulated medical device** | High (legal) | Claim discipline rail; no ECG/AFib/apnea/BP; wellness vocabulary only. `05-opensource-legal §3` |
| **Incumbent patent litigation** (Oura ITC, Whoop trade-dress) | Medium | Distinct UI/form factor; FTO scan; Apache patent grant + CERN-OHL; open = defensive prior art. `05-opensource-legal §1f` |

## 6. Cost to get going (Phase 0 + early Phase 2)
- Phase 0: 2× Bangle.js 2 (~$250) + Polar H10 (~$90) = **~$340** and you have a working validated system.
- Phase 2 prototype: ~$80 dev-board BOM; custom PCB Rev A ~$150-250; wearable units ~$55-80 each.
- Software/cloud: self-hosted, ~free (runs on the existing Titan Docker stack + a Pi/cheap VPS).

## 7. What "done" looks like
Two brothers wearing custom, open, subscription-free bands that feed Titan real recovery/sleep/strain every
morning, narrated by the AI coach and tied into the living goal-physique loop — and a public, reproducible,
OSHWA-certified open-source project that lets anyone in the world do the same and own their data. 🌍

---
*Next: skim `01`–`05` for the deep detail. When we're ready to build, Phase 0 is a weekend away.*
