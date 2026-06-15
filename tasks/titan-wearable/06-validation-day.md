# 06 — Validation Day Runbook

*One page to follow the day the Bangle.js 2 + Polar H10 arrive. Goal: prove, on **your own
skin**, that the band tracks HRV / resting HR / sleep well enough to trust. Two people (Alex
+ Tester C) — do it together, one reads steps, one drives.*

> **What you're proving.** The pipeline is already verified end-to-end on synthetic data.
> What you *can't* know until now is the **error band on a real wrist** — yours. The Polar
> H10 (chest ECG) is the reference: it gives true beat-to-beat timing. We compare the Bangle
> against it.

---

## 0. Gear checklist
- [ ] 2× **Bangle.js 2**, charged to 100% (overnight HR ≈ ~1 day of battery)
- [ ] 1× **Polar H10** chest strap, electrodes wetted, paired-ready
- [ ] A **Mac** (or any desktop/Android Chrome) on the same Wi-Fi as Titan
- [ ] Titan running and reachable (login works)
- [ ] ~20 minutes for the resting test; one night for the sleep test

> **iPhone note:** the overnight path is **morning sync** — the watch logs to its own memory,
> you pull it off in the morning on the Mac. No phone-by-the-bed, no app. (Web Bluetooth
> can't run in the background on iOS; that's expected.)

---

## 1. Pre-flight (≈15 min, once per watch)
1. **Set the watch clock.** Connect the Bangle to the [Espruino Web IDE](https://www.espruino.com/ide/)
   once — it syncs time from your Mac. **Critical:** wrong clock → recovery/sleep land on the
   wrong day. Confirm the watch shows the right time.
2. **Install the firmware.** Follow [`../../firmware/banglejs/README.md`](../../firmware/banglejs/README.md)
   (Web IDE "Send to RAM" for a quick test, or the App Loader to make it stick). Launch **Titan**
   on the watch — the screen should show `TITAN`, `idle`.
3. **Pair in Titan.** Titan → **Devices → Pair a device → Bangle.js**. Copy the **device ID** +
   the **one-time secret** (shown once). Keep them — the Live-stream page needs both.
4. Repeat 1–3 for the second watch (Tester C's). Pair it separately → its own device ID + secret.

---

## 2. Part A — Resting HRV validation (the quantitative test, ≈12 min)
This is the rigorous one. Both devices stream live; Titan compares them beat-for-beat.

1. Put on the **Polar H10** (chest) **and** the **Bangle** (wrist, snug, bone side, same arm is fine).
2. On the Mac: Titan → **Devices → Validation lab** (`/devices/validate`).
3. Paste the Bangle's device ID + secret if prompted. Click **Connect Polar H10**, then
   **Connect Bangle** (pick each in the Bluetooth chooser).
4. **Sit still, relax, breathe normally.** Pick **8 or 12 min** and hit **Start capture**.
5. When it finishes, read the verdict card:
   - **Mean bias** (Bangle − Polar RMSSD) and the **95% limits of agreement**
   - **Correlation** and the per-minute time-series + **Bland–Altman** plot

### Pass criteria (resting RMSSD)
| | Target | Notes |
|---|---|---|
| Mean bias | within **±10 ms** | the lab's "Validated" verdict uses this |
| 95% limits of agreement | within **±20 ms** | tighter = better |
| Correlation | **> 0.7** | should track the chest strap minute-to-minute |
| Resting HR | within **±3 bpm** of the H10 | wrist HR at rest is excellent |

> **Set expectations honestly:** on synthetic data the Bangle read **~20% under** the true
> RMSSD (a known wrist-PPG effect — peak timing slightly under-measures high HRV). A small
> consistent bias is fine and expected; what matters is that it **tracks** (high correlation,
> tight limits). If bias is large or limits are wide → it's almost always **fit**: tighten the
> band, warm your hands, sit stiller, re-run. Record the bias as *your* personal offset.

---

## 3. Part B — Overnight sleep + recovery validation (one night)
1. **Before bed:** make sure both watches are **charged** and the clock is set. Open **Titan**
   on the watch, press **BTN** to start — the screen shows `REC`, `log`, and a growing
   `logged: …KB`. Optionally wear the **Polar H10** too (an overnight HRV app like *HRV Logger*
   / *EliteHRV* on the phone gives an overnight HRV cross-check — optional).
2. **Wear it to sleep.** The watch logs all night to its own memory. Nothing else in the room.
3. **In the morning:** on the Mac, Titan → **Devices → Live stream** (`/devices/bridge`),
   **Connect**. The watch dumps the whole night in seconds; you'll see windows climbing in the
   ingest log.
4. The whole-night **seal** runs automatically (hourly). To force it now:
   `docker compose exec laravel.test php artisan biosignal:seal-nights`
5. Read the results:
   - **Recovery** (`/recovery`) — whole-night **HRV (RMSSD)** + **resting HR**
   - **Sleep** (`/sleep`) — **total sleep**, **bedtime/wake**, **deep/REM/light/awake**

### What to check overnight
| Metric | How to judge | Confidence |
|---|---|---|
| Resting HR | vs the H10 overnight (if logged), and your usual | 🟢 high |
| Whole-night HRV (RMSSD) | vs the H10 overnight HRV, ±~20% | 🟡 trend-grade |
| **Total sleep + sleep/wake** | vs when you actually fell asleep / woke | 🟢 good (accel-driven) |
| Bedtime / wake time | should match within ~15 min | 🟢 good |
| **Deep / REM / light split** | sanity-check only | 🟠 rough — see below |

> **Honest limit on stages:** without a sleep lab (PSG) there's no ground truth for
> deep/REM/light — and the open stager is weak at the split (deep can read as REM). The Polar
> H10 doesn't stage sleep either. So **validate sleep/wake + duration quantitatively**
> (accel-driven, accurate) and treat the **stage breakdown as directional** for now. The
> upgrade path is the trained `ojwalch` model (see [`03-algorithms.md`](03-algorithms.md) §3).

---

## 4. Troubleshooting
| Symptom | Fix |
|---|---|
| Bluetooth chooser shows nothing | Use desktop/Android **Chrome/Edge** (not Safari/iOS). Watch must be running Titan + not connected elsewhere. |
| "unauthorized" on upload | Re-check device ID + secret. Re-pair if you lost the secret (it's shown once). |
| Lab bias large / limits wide | Fit: snug band, bone side, warm hands, sit stiller. Re-run. |
| No sleep/recovery after sync | Run the seal command (step 3.4). Check the watch clock was correct. |
| Watch died overnight | Charge to 100% first; overnight HR ≈ ~1 day. Charge every evening. |
| Recovery on the wrong day | Watch clock was off — re-sync time (step 1.1). |

---

## 5. Record the results (fill this in)
```
Date: ____   Subject: Alex / Tester C
RESTING (Part A):
  Polar RMSSD ___ ms   Bangle RMSSD ___ ms   bias ___ ms   LoA ___ to ___ ms   r ___
  Polar HR ___ bpm     Bangle HR ___ bpm     → verdict: Validated / Marginal
OVERNIGHT (Part B):
  Recovery: HRV ___ ms   RHR ___ bpm   (Polar overnight HRV, if logged: ___ ms)
  Sleep: total ___ m   bed ___   wake ___   deep ___  rem ___  light ___  awake ___
  Felt like: fell asleep ~___, woke ~___, slept ~___ h  → sleep/wake match? Y / N
Personal RMSSD offset (Bangle − Polar): ___ ms   ← apply mentally to single readings
```

**Bottom line for the day:** if Part A lands **Validated** (or a small consistent bias) and
Part B's **total sleep + wake times** match how you actually slept, the band is doing its job —
trust the *trends*, note your personal RMSSD offset, and you've got a subscription-free Whoop.
