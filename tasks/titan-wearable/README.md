# Titan Wearable — Open-Source, Subscription-Free Recovery Wearable

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
| [`00-master-roadmap.md`](00-master-roadmap.md) | **Start here.** The synthesized cross-layer program: vision, architecture, the unified phase plan (P0→open-source launch), what we build first, key decisions, risks, immediate next actions. |
| [`01-hardware.md`](01-hardware.md) | The band: Bangle.js/PineTime vs DIY PCB, full BOM (nRF52840 + MAXM86161/MAX86141 PPG + BMI270 + BQ25120A), optical design, power budget, phased hardware path. |
| [`02-firmware.md`](02-firmware.md) | Embedded: Zephyr/NCS vs Espruino, sensor sampling, BLE streaming, phone bridge, OTA, power management. |
| [`03-algorithms.md`](03-algorithms.md) | The metrics: NeuroKit2 (HRV), Walch/YASA (sleep), recovery-score formula, strain, validation protocol vs Polar H10, honest accuracy limits. |
| [`04-platform-pipeline.md`](04-platform-pipeline.md) | Titan integration: device-agnostic ingestion API, raw-signal storage (MinIO), the FastAPI/NeuroKit2 worker, self-host docker-compose. |
| [`05-opensource-legal.md`](05-opensource-legal.md) | Licensing (CERN-OHL-S + Apache + AGPL), OpenAPS/Nightscout playbook, wellness-vs-medical legal line, safety, community + funding. |

## The two rails that never change
1. **Claim discipline** — wellness vocabulary only (recovery/sleep/fitness). Never diagnose/monitor/treat;
   never ship ECG/AFib/apnea/blood-pressure. This keeps us out of FDA/MDR device regulation.
2. **Stay on the information side** — publish designs, don't sell finished units, don't charge for data,
   local-first. This keeps liability low and the mission honest (the OpenAPS model).

## Immediate next action (when we start)
Buy **2× Bangle.js 2** (~$125 ea) + **1× Polar H10** chest strap (ground truth). Build Titan's
**`POST /devices/ingest`** endpoint + a **NeuroKit2 worker**, and get real validated overnight HRV from
day one — zero custom hardware required. See `00-master-roadmap.md` § "Phase 0".
