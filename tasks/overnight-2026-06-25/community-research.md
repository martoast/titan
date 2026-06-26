# Bangle.js 2 community research — how to be more Whoop-like

_Four parallel research agents swept the Espruino forums, BangleApps GitHub, and the DIY-wearable
community (24/7 HR apps · sleep apps · BLE/firmware power · recovery/HRV parity). This is the
synthesis: **what we already do right, what's worth borrowing, and what's a dead end.** Every claim is
sourced in the per-agent briefs; key citations inlined._

## Headline: we're already ahead of the community on the things that matter most
The stock ecosystem only varies the HRM *interval* (the Health app samples every 10 min, no early
exit). **We do short-burst duty-cycling + confidence gating + a hard burst timeout + motion-gating** —
which minimizes mA-seconds per reading, the higher-value axis. The community independently reached two
of our own conclusions: (1) continuous HRM is ~85-90% of overnight drain, and (2) auto-activity-detect
is unreliable — set the sport mode explicitly (which is why our `AUTO_DETECT` is off).

## What we audited against the research and PASS
- **HRM poll rate:** rest/sleep at 40 ms, only workouts at 20 ms — we are *not* paying the ~60% HRM
  current penalty of 20 ms 24/7. ✓ ([#7232](https://github.com/orgs/espruino/discussions/7232))
- **Sport mode:** `hrmSportFor()` returns 0 (normal) whenever not in a workout, so sleep/rest never use
  the HR-inflating sportMode 1. ✓
- **Stable appID + paired on/off:** every call is `setHRMPower(_, "titan")`. The firmware ref-counts on
  this string; we never leak a reference. ✓
- **Hard burst timeout:** fixed ON windows force power-off regardless of confidence — a never-confident
  reading can't pin the sensor on. ✓
- **No compass:** we never power the magnetometer (it's as expensive as the HRM). ✓
- **wakeOnTwist off overnight:** just shipped (commit 99f08d9). ✓ The community calls wakeOnTwist a
  known overnight battery killer. ([#2026](https://github.com/orgs/espruino/discussions/2026))

## Fixes already shipped tonight, validated by this research
1. **Sleep HRM burst duty-cycle** — community: continuous HRM is THE overnight killer; `sleeplog` never
   streams HRM overnight, only brief bursts with `setHRMPower(false,id)` between. Exactly our fix.
2. **Screen-dark overnight** — backlight is +16 mA ("flattens battery in ~12h if it comes on
   frequently"); the touchscreen unlocked is +2.5 mA. We now `setLocked(true)` + kill twist/touch wake.
3. **Motion-gated daytime cadence** — relax when still, tighten on motion.
4. **Rest-burst HRM mode** (commit just now) — research caught that a rest burst after a workout
   inherited stale sportMode 1 + 20 ms; now forced to normal + 40 ms.

## Worth borrowing (ranked) — not yet done
| # | Borrow | Why / source | Effort | Verdict |
|---|---|---|---|---|
| 1 | **Extend `bthrm` to parse RR from the BLE chest-strap (`0x2A37`)** → true RMSSD/HRV | The community's clearest unfinished edge. The Bangle's closed VC31B algo **hides beat timing** — real wrist HRV is build-it-yourself and noisy. A strap (Polar H10/OH1) gives ECG-grade RR *and* lets the wrist LED power down — a double win. bthrm already re-emits strap data as native `HRM` events; it just discards the RR bytes. ([bthrm](https://github.com/espruino/BangleApps/tree/master/apps/bthrm), [#6615](https://github.com/orgs/espruino/discussions/6615)) | Med | **Premium/power-user path** for serious recovery |
| 2 | **Overnight accel auto-downshift (0.3 → 0.15 mA)** | We hold a persistent `Bangle.on("accel")` listener + call `setPollInterval`, both of which **disable the firmware `powerSave` auto-drop** to 1.25 Hz. During sleep, staging only needs coarse motion — we could drop to `setPollInterval(800)`. ([#2026](https://github.com/orgs/espruino/discussions/2026), [#1921](https://github.com/espruino/Espruino/issues/1921)) | Low | Worth doing — small (~0.15 mA) but free |
| 3 | **Use firmware `e.rr` on the `HRM` event** instead of our own RR extraction, where confidence is high | Stock firmware emits RR intervals directly (gated on confidence). Cheaper than rolling our own — *if* it's good enough for our HRV pipeline. ([Espruino-HRV](https://github.com/jabituyaben/Espruino-HRV)) Caveat: agent 4 notes VC31B may *not* expose RR reliably post-2023 — **verify on-device before trusting**. | Low | Verify, then maybe |
| 4 | **Confirm DC/DC + LSE build flags in our custom firmware** (`ESPR_DCDC_ENABLE`, `ESPR_LSE_ENABLE`) | DC/DC regulator ≈ halves active-CPU draw (3.1 vs 6.3 mA). Stock builds have it; confirm our custom build didn't drop it. ([#6562](https://github.com/orgs/espruino/discussions/6562)) | Low | Audit item |
| 5 | **Port recovery/HRV *math* from open-source DIY-Whoop repos** (`whoof`, `openwhoop-algos`) | They drain real Whoops and recompute locally: RMSSD, SDNN, pNN50, Poincaré SD1/SD2, 1-min LF/HF, RSA respiratory rate, strain. Good reference once we have a clean RR source (#1). Design principle worth copying: show **"Unavailable" rather than a fabricated metric**. ([whoof](https://github.com/madhursatija/whoof)) | Med | Feeds the correlation engine |

## Dead ends (so we don't chase them)
- **Reliable standard-RMSSD HRV from on-device *wrist* PPG** — only one weak community attempt exists;
  the closed VC31B algo deliberately hides beat timing; the only workaround (custom firmware re-enabling
  the old open HRM) sacrifices the motion-accuracy the binary algo bought. Use a strap for true HRV.
- **BLE micro-tuning beyond "park the link"** — BLE is near the *bottom* of the drain hierarchy
  (GPS ≫ backlight ≫ CPU ≫ HRM ≈ compass ≈ BLE). Our store-and-forward + 2-min foreground-drop already
  parks it. Not worth more effort.
- **`E.setClock` / MCU deep-sleep tuning** — no community evidence it helps; the MCU already idles
  between polls. The wins are peripherals (HRM, screen), not the clock.
- **Fully disabling the accel** (`accelWr` nuclear option) — we need it for actigraphy + motion-gating.

## Net
Three battery fixes shipped tonight put us in **Whoop-4.0-class territory** (~multi-day) once validated.
The path to **Whoop-5.0-class** recovery quality is the **chest-strap RR → real HRV** track (#1 + #5),
which is a feature, not a power fix. The only remaining pure-battery item worth a look is the overnight
accel downshift (#2). _Per-agent briefs retained in the workflow output; this file is the synthesis._
