# 07 — Three-Signal Validation & Data-Capture Day

*The day the Bangles arrive, do two things at once: (1) validate the wearable on your own
body, and (2) capture **motion + HR + RMSSD** paired with a gold-standard reference — the
exact data that turns our sleep model from "literature-grade on public data" into
"better-than-benchmark on real wrists." Every night you capture makes the system smarter for
everyone who eventually uses it. (For the quick 12-minute validation alone, see
[`06-validation-day.md`](06-validation-day.md); this doc is the deeper protocol.)*

> **Why this exists.** We *proved* (real-ECG ablation, `biosignal/scripts/ablate_rmssd.py`) that
> per-epoch **RMSSD nearly doubles** 4-class sleep-staging accuracy. Our Bangle computes RMSSD;
> the public training data doesn't have it. The one missing ingredient is real **motion + HR +
> RMSSD from our wearable, in our users' distribution, with a reference**. These nights are that
> ingredient. This is the flywheel: your sleep → better model → helps the next person.

---

## 0. Gear
- [ ] 2× **Bangle.js 2**, charged to 100%, running `titan-stream` (see `../../firmware/banglejs/README.md`)
- [ ] 1× **Polar H10** chest strap — the **gold-standard reference**: chest ECG → true RR intervals
      → true HR **and** true RMSSD. This is what we measure the Bangle against.
- [ ] An **overnight RR recorder** for the Polar: *HRV Logger* (iOS/Android, has an all-night mode)
      or *EliteHRV* — anything that records and **exports per-beat RR intervals** as a file.
- [ ] **A Mac** on the same Wi-Fi as Titan.
- [ ] *(Big upgrade for sleep labels)* a **sleep-stage reference**, best-effort:
      Oura / Apple Watch / Whoop (silver standard), or a home EEG band (**Muse S**, **Dreem** —
      closest to true staging at home). Optional but it's what lets us *label* nights.
- [ ] The **results + log sheet** at the bottom of this doc.

> **iPhone note:** overnight capture uses **morning sync** — the watch logs to its own memory,
> you pull it onto the Mac in the morning. No phone by the bed. (Path B in `02-firmware.md`.)

---

## 1. Pre-flight (once per watch, ~15 min)
1. **Set the watch clock** (connect once to the [Espruino IDE](https://www.espruino.com/ide/) — it
   syncs time from the Mac). Wrong clock = data on the wrong day. **Non-negotiable.**
2. Install `titan-stream`, launch **Titan** on the watch.
3. In Titan → **Devices → Pair a device → Bangle.js**; copy the device ID + one-time secret.
4. Repeat for the second watch.

---

## 2. Part A — Resting validation (12 min) — *proves the RMSSD signal itself*
This is the rigorous, quantitative check that the Bangle's RMSSD matches the chest strap.

1. Wear the **Polar H10** + the **Bangle** (snug, bone side of the wrist). Sit still, breathe normally.
2. Mac → Titan → **Devices → Validation lab** (`/devices/validate`). Connect both. Capture **8–12 min**.
3. Read the verdict. **Pass criteria:**
   | Metric | Target |
   |---|---|
   | RMSSD mean bias (Bangle − Polar) | within **±10 ms** |
   | 95% limits of agreement | within **±20 ms** |
   | Correlation | **> 0.7** |
   | Resting HR | within **±3 bpm** |
4. **Record your personal RMSSD offset** (Bangle − Polar). A small consistent bias is normal — note it.

> If bias is large / limits wide → it's fit. Tighten the band, warm your hands, sit stiller, re-run.

---

## 3. Part B — Overnight three-signal capture (*the dataset-builder*)
**Before bed**
1. Charge both watches. Confirm the clock. Open Titan → press **BTN** to start (`REC`, `logged: …KB`).
2. Put on the **Polar H10** and start the **overnight RR recording** in your HRV app.
3. *(If you have one)* start the **sleep-stage reference** device.

**Sleep.** The Bangle logs **motion + HR + raw PPG → RMSSD** to its own flash; the Polar logs **RR** all night.

**In the morning**
4. **Sync the Bangle:** Titan → **Devices → Live stream** → **Connect**. The night uploads in seconds;
   motion + HR + RMSSD land in Titan. Seal runs hourly (or `docker compose exec laravel.test php artisan biosignal:seal-nights`).
5. **Export the Polar RR file** from your HRV app (a `.txt`/`.csv` of RR intervals) → save it, named `polar_<you>_<date>.txt`.
6. **Export the sleep reference** hypnogram (if used) → `sleep_<you>_<date>.csv`.
7. **Fill the log** (below) within a minute of waking — bedtime, wake time, awakenings, rested 1–10.

---

## 4. Part C — What each capture gives us (the payoff)
| You capture | It validates / unlocks |
|---|---|
| Bangle RMSSD **vs Polar RMSSD**, all night | Gold-standard validation of the Bangle's RMSSD on *your* skin (not just resting) — 🟢 rock-solid, the Polar is ECG |
| Bangle **motion + HR + RMSSD** + a sleep reference | A real, **Bangle-distribution, RMSSD-bearing labeled night** → retrains the combined model on *our* data → removes the apnea-population fragility that's keeping RMSSD out of production today |
| Subjective log (sleep/wake times) | A free sanity reference for sleep/wake even without a device |

**This is the whole point:** the combined RMSSD model is built and validated on public data, but it's
not safe to default yet because no public set has *our* three signals together. **A handful of your +
Tester C's nights closes that gap** — and because RMSSD nearly doubles staging accuracy, this is the single
biggest lever to push Titan *past* the public benchmark.

---

## 5. The model-improvement loop (what we do with the nights)
1. **Collect** N nights from you + Tester C: `{Bangle signals, polar_*.txt, sleep_*.csv, log}`.
2. **Parse** (small tool we'll add once we see the Polar export format — mirrors
   `biosignal/scripts/validate_on_physionet.py`): Polar RR → per-30 s HR + RMSSD; sleep reference → per-epoch labels.
3. **Retrain** with `biosignal/scripts/train_combined.py` adapted to our data (now every night has
   motion + HR + **real RMSSD** + labels — the combination no public dataset has).
4. **Validate leave-nights-out.** If it beats the Walch default (`SLEEP_MODEL_ENABLED` model), **flip it on.**
5. Repeat. More nights → better model → better sleep + recovery for everyone.

---

## 6. Honest notes (no overselling)
- **HR & RMSSD reference = gold.** The Polar H10 is chest ECG, so the RMSSD validation is rock-solid even
  at home — no lab needed. This part we can *prove*.
- **Sleep-stage labels = silver, not gold.** Without a sleep lab (PSG) the stage labels carry real
  uncertainty; a home EEG band is the best home option. We'll treat stage accuracy as *directional* and
  lead with sleep/wake + duration (which are solid).
- **Local-first / your data.** Every byte stays on your hardware and Titan — the whole Titan ethos. No
  one's selling your sleep.
- **Battery:** continuous overnight HR ≈ ~1 day on the Bangle — charge every evening.

---

## 7. Log sheet (fill in per night)
```
Date: ____   Subject: Alex / Tester C

PART A — resting (12 min):
  Polar RMSSD ___ ms   Bangle RMSSD ___ ms   bias ___ ms   LoA ___ to ___ ms   r ___
  Polar HR ___ bpm     Bangle HR ___ bpm     → Validated / Marginal
  Personal RMSSD offset (Bangle − Polar): ___ ms

PART B — overnight:
  Bangle synced? Y/N    Polar RR exported? Y/N    Sleep ref exported? Y/N (device: ____)
  Titan Recovery: HRV ___ ms   RHR ___ bpm
  Titan Sleep: total ___ m   bed ___   wake ___   deep ___  rem ___  light ___  awake ___
  Subjective: fell asleep ~___   woke ~___   # awakenings ___   slept ~___ h   rested 1-10 ___
  Sleep/wake match reality? Y/N

Files saved:  polar_<you>_<date>.txt   sleep_<you>_<date>.csv
```

**Bottom line:** Part A *proves* the Bangle's RMSSD is real and accurate (gold-standard, today). Part B
*builds the dataset* that lets us safely turn RMSSD on in the sleep model — the move that pushes Titan
past every public benchmark. Wear it, sync it, log it; the rest is ours to build. ❤️
