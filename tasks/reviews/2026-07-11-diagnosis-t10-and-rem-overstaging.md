# DEEP DIAGNOSIS: T10 never fires + REM over-staging — both root-caused & fixes proven

**Reviewer:** Henry (server) · **From:** Alex's first reflashed-watch night (#54, 2026-07-11)
**Two separate bugs, both empirically confirmed. Fixes below were DEMONSTRATED, not guessed.**

---

## BUG 1 — T10 dense motion never reaches the server (motion_samples = 0 all-time)

**Root cause: the firmware emit is gated OFFLINE-ONLY, and Alex slept with the band connected.**

The whole software chain (iOS decode/route/upload + server `writeMotionTrend`) is complete and correct —
field names + the `motion_trend` kind match end to end. The failure is a firmware EMIT GATE:

- `bankMotionEpoch()` (`titan.app.js:758-765`): `if (state.connected) return;` — **T10 emits ONLY when
  the band is offline** (it's `appendLog`-flash-only, no live path; drains on morning sync). Also requires
  `sleepModeActive()` = an explicitly-started on-watch Sleep session (`:1367`, set only by the Sleep-face
  START, `startSleepSession` `:2262`).
- **Proven on night #54:** windows arrived ~3 min after recording (`lag -3m`) → the band **streamed LIVE
  all night (connected)**. He DID start a Sleep session (`via=biosignal:sealed-session` = T9 present), so
  that gate passed — the **connection gate is what killed it.** Sleeping with the phone by the bed = zero
  T10, forever, for basically everyone.
- **Deeper flaw:** the firmware assumes "connected → dense accel rides the T1 frames instead." But overnight
  the raw capture is **duty-cycled to ~17%** (battery), so even connected the accel is sparse, AND it never
  lands in `motion_samples`. So the dense-motion channel is effectively dead in the normal case.

### Fix direction (BUG 1)
Make continuous motion flow in BOTH states, not just offline:
- **Preferred:** drop the `if (state.connected) return;` gate so T10 banks the always-on `motionEMA` every
  30s regardless of connection (it's free — the accel is always on for gating; the whole T10 premise). When
  connected, emit it live (`Bluetooth.println`) in addition to the offline `appendLog`, so it flows same-night.
- Keep the Sleep-session gate OR extend to auto-detected sleep so it doesn't depend on the user tapping the
  Sleep face every night (worth a product call — silent no-data is bad UX).
- No iOS/server change needed — that half is already correct and deployed.

---

## BUG 2 — REM over-staged (49%!) / deep under-called — HR NOISE, not RMSSD

**Root cause: the band's duty-cycled PPG HR is jittery, and the trained model reads HR jitter as REM.**
Proven by re-running night #54's real inputs through the production model.

- Active stager = trained `HistGradientBoostingClassifier` (`sleep_stager.joblib`, physionet-walch2019,
  28 subjects, 4-class, `class_weight="balanced"`), `staging.py:232-239`, raw per-epoch `predict()`.
- Its REM signature is **multi-scale HR variability** — rolling HR std at 5/11/21 + HR gradients
  (`sleep_features.py:62-66`). The model uses **motion + HR only; NOT RMSSD** — so **the RMSSD fix had zero
  effect here (hypothesis REFUTED).** The distribution shift that matters is **HR noise**: the band's raw
  PPG HR jumps 58→80→86→49→88 (median |ΔHR| 5 bpm, p90 16 bpm) — noise, not autonomic signal — and the
  model was trained on cleaner PhysioNet/watch HR.

### Proven fixes (one-variable-at-a-time on the real night)
| Change | REM | Deep | Light |
|---|---|---|---|
| Production (raw HR) | 48% | 5% | 47% |
| **Median-filter HR k=5 before features** | **20%** | **17%** | **63%** ← textbook normal |
| Median-filter HR k=9 | 15% | 15% | 71% |
| Re-impose realistic REM prior (undo balanced) | 30% (deep unchanged) | — | — |

1. **PRIMARY — denoise HR before `extract_features`** (`staging.py`, just before `:236`): median/Hampel
   filter `hr_e` (k≈5–9), ideally gated on HR-sample confidence / motion artifact. This SINGLE change turned
   the pathological night into normal (20% REM / 17% deep). Biggest win, smallest change.
2. **Retrain or prior-correct `class_weight="balanced"`** (`train_real.py:151`) — balanced weighting strips
   REM's ~20% base rate; applying a realistic prior to `predict_proba` before argmax drops REM another ~15pt.
   Best long-term: retrain on PhysioNet HR augmented with band-like jitter so train/inference distributions match.
3. **Apply the saved `logT` Viterbi at inference** — the model was VALIDATED with temporal smoothing and
   ships `logT` in the bundle, but `staging.py:237` uses raw argmax and ignores it (a real train/inference
   mismatch). Enforces realistic bout durations (kills a 69-min continuous-REM block — impossible; real REM
   bouts cap ~40 min).
4. **Sanity-clamp/flag** REM > ~35% or any single REM bout > ~45 min as low-confidence.

---

## How the two bugs relate (and the strategic order)
They compound but are independent. Fixing HR-denoising (Bug 2 #1) is the **highest-leverage, lowest-risk**
change and fixes staging on the data we ALREADY collect — do it FIRST; it needs no device changes and would
immediately correct Alex's stored nights (re-seal). Bug 1 (T10) then adds dense *motion* which further
sharpens deep/light and powers the movement-strip viz — but note the stager currently reads sparse window
`accel`, not `motion_samples`, so once T10 flows, ALSO feed the dense motion to the stager (`stageSparse`
already builds a continuous series for the viz — reuse it as the stager's accel input).

**Suggested sequence:** (1) HR denoise + Viterbi + REM prior in staging.py → re-seal Alex's nights and verify
REM drops to ~20% · (2) fix T10 emit gate (firmware) so dense motion flows connected-or-offline · (3) feed
dense motion to the stager, not just the timeline · (4) retrain HR-jitter-matched model as the durable fix.

*The RMSSD saturation fix was still correct (recovery data). But the staging bug was hiding behind it the
whole time: the stager reads HR jitter as REM, and our band's HR is jittery. Denoise the HR and the night
reads normal — proven on Alex's own data.*

— Henry
