# Titan Wearable — Open-Source, Subscription-Free Recovery Wearable

> **This is research, not a product.** Titan does not manufacture, sell or supply hardware.
> This folder is an open reference design so that anyone who wants to can build their own device,
> in the tradition of OpenAPS and Nightscout (see [`05-opensource-legal.md`](05-opensource-legal.md)).
> The Titan app itself runs on an off-the-shelf, third-party open-source smartwatch that the user
> buys and owns.

> **Mission:** anyone, anywhere can build an honest fitness/recovery wearable, keep their own
> physiological data on their own hardware, and optimize their wellbeing — **no subscription,
> no data surrender, no one gatekeeping access to your own body's data.**

This folder is the complete, researched blueprint for building it — for Alex & Tester C first, then
open-sourced for the world. Every layer was deep-researched (with citations) before any code.

## The core idea
A band you own streams **raw PPG (optical heart rate) + motion** over BLE → your phone → **Titan**,
where **open, validated algorithms** (NeuroKit2 for HRV, Walch model for sleep) turn it into real
**HRV, recovery, sleep, and strain** — the exact metrics Whoop/Oura/Polar charge a subscription for.
The "secret sauce" is mostly open; the device is a dumb sensor, Titan is the brain. **Device-agnostic**
ingestion means a Bangle.js, a Polar, Apple Health, or our own custom band all plug into the same pipe.

## The documents
| Doc | What it covers |
|---|---|
| [`STATE-OF-TITAN.md`](STATE-OF-TITAN.md) | **What's built and live, in one glance.** The full feature inventory across firmware → biosignal → app → agent, each with where it lives, its real-data grade, and how it surfaces — plus the honest "no"s and the two rails. **Read this first to see what Titan does today.** |
| [`00-master-roadmap.md`](00-master-roadmap.md) | **Start here.** The synthesized cross-layer program: vision, architecture, the unified phase plan (P0→open-source launch), what we build first, key decisions, risks, immediate next actions. |
| [`01-hardware.md`](01-hardware.md) | The band: Bangle.js/PineTime vs DIY PCB, full BOM (nRF52840 + MAXM86161/MAX86141 PPG + BMI270 + BQ25120A), optical design, power budget, phased hardware path. |
| [`02-firmware.md`](02-firmware.md) | Embedded: Zephyr/NCS vs Espruino, sensor sampling, BLE streaming, phone bridge, OTA, power management. |
| [`03-algorithms.md`](03-algorithms.md) | The metrics: NeuroKit2 (HRV), Walch/YASA (sleep), recovery-score formula, strain, validation protocol vs Polar H10, honest accuracy limits. |
| [`04-platform-pipeline.md`](04-platform-pipeline.md) | Titan integration: device-agnostic ingestion API, raw-signal storage (MinIO), the FastAPI/NeuroKit2 worker, self-host docker-compose. |
| [`05-opensource-legal.md`](05-opensource-legal.md) | Licensing (CERN-OHL-S + Apache + AGPL), OpenAPS/Nightscout playbook, wellness-vs-medical legal line, safety, community + funding. |
| [`06-validation-day.md`](06-validation-day.md) | **The day the hardware arrives.** One-page runbook: install firmware, pair, resting HRV validation vs Polar H10 (Bland–Altman, pass criteria), overnight sleep+recovery validation, troubleshooting, results template. |
| [`07-three-signal-capture.md`](07-three-signal-capture.md) | **The deeper protocol.** Capture motion + HR + **RMSSD** + a gold-standard reference per night → validate the wearable AND build the Bangle-distribution RMSSD dataset that unlocks the combined sleep model (RMSSD nearly doubles staging accuracy). The model-improvement flywheel + log sheet. |
| [`08-sensor-research.md`](08-sensor-research.md) | **The research roadmap.** Deep academic-literature review (~60 papers) of *everything else* we can honestly extract from our sensors for longevity/CVD/metabolic health + the pipeline upgrades that make it trustworthy. Prioritized Tier-1/2/3 build backlog, honest ✅/🟡/❌ feasibility, and the "do NOT ship" honesty firewall. **Start here for what to build next.** |
| [`09-validation-results.md`](09-validation-results.md) | **What we've actually proven.** The single source of truth for every metric's real-data accuracy: HRV, sleep, activity, gym (exercise + reps), VO₂max, in-motion HR — each with dataset, leave-subjects-out protocol, honest result, and grade. Plus the *negative results we keep* and the methodology rules. **Start here for "how good is it, really."** |
| [`10-biological-age.md`](10-biological-age.md) | **Biological age — the synthesis.** How Titan turns uploaded **bloodwork** + wearable + VO₂max into one "how old is your body" number: the mortality-validated **PhenoAge** blood clock (exact formula), the VO₂max **Fitness Age**, and the wearable **levers** — plus the combination logic, the honest negative (a data-driven clock needs NHANES-style data), and the path to a real Titan clock. |
| [`11-day-one-test-suite.md`](11-day-one-test-suite.md) | **The day-one hypothesis suite.** Every assumption the watch rests on (clean PPG, HRV recovery, activity priming reaching the watch, GPS arming outdoors, overnight log + sync) turned into a runnable check: the *Day-1 test suite* panel in `bridge.html` (prime buttons, frame monitor T1–T7, signal sanity, one-click checks) plus the manual procedures and pass bars. **Run this the day the band arrives.** |

## The two rails that never change
1. **Claim discipline** — wellness vocabulary only (recovery/sleep/fitness). Never diagnose/monitor/treat;
   never ship ECG/AFib/apnea/blood-pressure. This keeps us out of FDA/MDR device regulation.
2. **Stay on the information side** — publish designs, don't sell finished units, don't charge for data,
   local-first. This keeps liability low and the mission honest (the OpenAPS model).

## Immediate next action (when we start)
Buy **2× Bangle.js 2** (~$125 ea) + **1× Polar H10** chest strap (ground truth). Build Titan's
**`POST /devices/ingest`** endpoint + a **NeuroKit2 worker**, and get real validated overnight HRV from
day one — zero custom hardware required. See `00-master-roadmap.md` § "Phase 0".
