# Bangle.js 2 Community — Feature Inspiration for Titan

> Deep web research, 2026-06-25. Sweep (5 angles) → deep-read/verify against source URLs → synthesize. 11 agents, every item source-backed.

The Bangle.js 2 community has quietly built much of the recovery-wearable stack Titan needs — HRV biofeedback, chest-strap fusion, sleep-phase alarms, on-device exercise ML, validated step/HR pipelines — but always bounded by 256KB of RAM and no server. That boundary is exactly Titan's advantage: we have a server-side AI coach, Python biosignal models, and an always-streaming band. The community proves the signal is *capturable on this hardware*; Titan's job is to do the heavy lifting in the cloud and close the loop with coaching. This report maps what they built and what we should steal.

---

## 1. Landscape — what the community has built

### Health / recovery (the biohacker cluster)

- **HRV suite — HRV / HRV Guide / HRV Log** (cck33) — Three MIT-licensed apps on the watch's PPG via `Bangle.on('HRM')` + `e.rr` RR-intervals. **HRV Guide** is the standout: it fires a haptic (`Bangle.buzz(33)`) every 7th heartbeat as a resonance-breathing pacer ("breathe in 7 beats, out 7 beats") — on-wrist HRV biofeedback with zero external hardware. HRV Log writes timestamped `hrv-data-n.log` sessions for offline Python analysis. https://www.hackster.io/cck33/heart-rate-variability-hrv-and-biohacking-the-bangle-js-2-fae7f1
- **Espruino-HRV** (jabituyaben) — The reference DIY HRV implementation. Captures ~30s raw PPG → rolling-average denoise → Bézier upsample → slope-inversion peak detection → SDNN-style stdev of inter-peak gaps. Ships an on-watch `HRV.js` *and* a Node.js desktop variant to escape the watch's RAM limit — benchmarked against a Polar H10 (reads slightly low). https://github.com/jabituyaben/Espruino-HRV
- **"HRM statistics that we don't have yet"** (Discussion #6309) — The canonical roadmap doc for beyond-HR metrics (HRV, SpO2, BP) and *why they're hard*: VC31B's LED auto-gain injects low-frequency drift, raw values are 16-bit and clip, SpO2 math lives in a vendor binary blob and needs red+IR (green is insufficient), BP would key off PPG pulse rise/fall edge timing. https://github.com/orgs/espruino/discussions/6309
- **"Dealing with noisy PPG signals"** (Major Input) — A concrete embedded DSP recipe deliberately avoiding Butterworth bandpass for speed: **raw PPG → first-order differencing (cheap high-pass) → Gaussian convolution smoother → peak detection → 90th-percentile interval outlier rejection.** https://www.majorinput.co.uk/post/bangle-js-2-dealing-with-noisy-ppg-signals
- **BTHRM / bthrmlite** — Override wrist PPG with a BLE chest strap (`0x180D`/`0x2A37`), with internal-HRM fallback if no strap data. `bthrmlite` is the lean, low-RAM version that re-emits strap readings as standard HRM events so all apps work transparently. https://github.com/espruino/BangleApps/tree/master/apps/bthrm
- **CoreTemp + ANT+ HRM bridge** (PR #4255, open) — The most sensor-fusion-relevant recent work: a CORE body-temp sensor pairing/monitoring an **ANT+** heart-rate strap (tested vs Polar H10 + Verity Sense), with reconnect backoff and status events. https://github.com/espruino/BangleApps/pull/4255

### Activity / workout

- **Classify Exercise Activities** (Edge Impulse) — Collects accelerometer training data on-watch, trains a TFLite model in Edge Impulse, deploys back, and classifies exercises + durations live on-device at >95% accuracy. Bangle natively runs `.tfmodel` and fires `aiGesture` events. https://www.edgeimpulse.com/blog/this-smartwatch-doesnt-let-you-skip-leg-day/ · https://docs.edgeimpulse.com/experts/accelerometer-and-activity-projects/classify-exercise-activities-banglejs-smartwatch
- **Recorder** — The core activity logger (GPS/HR/steps/baro → CSV → Gadgetbridge → GPX). PR #4212 adds on-device track previews and accelerometer charts. https://github.com/espruino/BangleApps/pull/4212
- **C25K (Couch-to-5K)** — Guided run program with day/week state persistence (PR #4262). https://github.com/espruino/BangleApps/pull/4262
- **accel\* family** (accellog / accelrec / accelgraph / accelsender) — Raw 3-axis tools to log, record, live-graph, and BLE-stream accelerometer data — the substrate for any custom activity/sleep DSP. https://github.com/espruino/BangleApps/tree/master/apps

### Sleep

- **SleepPhaseAlarm** — Smart wake within a user-set window during light sleep, fully on-device via accelerometer movement detection — genuinely uncommon as a no-cloud feature (Discussion #6703). https://github.com/orgs/espruino/discussions/6703
- **SleepLog** — All-day sleep/wake inference from overnight movement; the Gadgetbridge sleep-stage sync gap is still open (Issue #3275). https://github.com/espruino/BangleApps/issues/3275
- **Sleep as Android integration** — *Shipped*: Bangle.js works as a Sleep-as-Android wearable via Gadgetbridge ≥0.81.0 + `accelsender` + the "DIY" sensor option. https://github.com/orgs/espruino/discussions/7217
- **SteelBall** (jabituyaben) — Edison's steel-ball trick: HR-threshold-based (not accel) — fires an alarm when running-average HR drops to a set limit to catch sleep onset / hypnagogic states, logging session times to CSV. https://github.com/jabituyaben/SteelBall

### Sensors / novel

- **Gesture Recognition with Edge Impulse** — Train→deploy TFLite gesture models running natively on-watch. https://docs.edgeimpulse.com/experts/accelerometer-and-activity-projects/gesture-recognition-banglejs-smartwatch
- **EspruinoHRMTestHarness** (gfwilliams) — Desktop C harness replaying recorded accel+raw-PPG CSVs against an external BLE ECG strap (CooSpo HRM808S) as ground truth, ≥2000 samples/file. Capture via the `hrmaccevents` app. https://github.com/gfwilliams/EspruinoHRMTestHarness
- **hrmmar** (Motion Artifact Removal) — FFT on raw PPG to suppress motion artifacts for HR-during-movement (experimental). https://github.com/orgs/espruino/discussions/5820
- **New VC31B HR algorithm** (Discussion #7232) — Firmware swapped to the vendor binary; `Bangle.setOptions({hrmSportMode:N})` presets (normal/running/cycling/spinning), runtime LED current via `Bangle.hrmWr(0x17,X)`, accel-fed motion rejection. Known ~10 BPM resting offset on some units. https://github.com/orgs/espruino/discussions/7232
- **Barometer / altimeter** — On-board pressure sensor for elevation-gain work. https://github.com/orgs/espruino/discussions/6612
- **PMC12074211 validation study** (*Sensors*, May 2025) — Research-grade evidence: steps CCC 0.96 over 24h vs Fitbit Charge 5; HR CCC 0.78 vs Polar H10 (0.76 sedentary → 0.54 during activity); ~56% step undercount at 2mph → 6.1% at 5mph. https://pmc.ncbi.nlm.nih.gov/articles/PMC12074211/

### UX / watch-face

- **Clock Info (clkinfo)** — The shared framework turning any face into a swappable glanceable dashboard. `require("clock_info").addInteractive()` wires gesture handling (tap to focus, swipe up/down within a list, left/right between categories) and supports 2–4 independent slots per face (`slopeclockpp` is the showcase). https://www.espruino.com/Bangle.js+Clock+Info
- **clkinfosunrise** — Reference add-on for writing your own info card (`*.clkinfo.js` + `"type":"clkinfo"`). https://www.espruino.com/Bangle.js+Clock+Info
- **"Loading… is the enemy" / launcher-fusion** — Fusing the launcher into the clock face (BW Clock + Desktop Launcher POC) made app switching "feel instant" vs the usual 2–2.5s. https://github.com/orgs/espruino/discussions/5398
- **Widget bar** — Always-on micro-indicators (steps/battery/BT) above any app via `Bangle.drawWidgets()`. https://www.espruino.com/Bangle.js+Widgets

---

## 2. Build ideas for Titan

Prioritized. Titan's edge is consistent: the community has to do everything *on a 256KB watch*; we stream raw signal to a server with Python models and an AI coach. Anything they hack on-device, we can do better in the cloud — and *coach on*.

| # | Idea | Inspired by | Surface | Effort | Why it matters |
|---|------|-------------|---------|--------|----------------|
| 1 | **Coach-led resonance breathing** — band buzzes a paced-breathing protocol (7-in/7-out or coach-personalized), streams the session's RR/HRV to the server, and the coach scores coherence + tells you if it moved your recovery | HRV Guide's every-7th-beat haptic pacer; Espruino-HRV SDNN | firmware (haptic pacer) + biosignal (coherence scoring) + coach (debrief) + iOS (guided UI) | M | A flagship "do something with your recovery score" loop. Whoop *shows* HRV; we'd let users *train* it and quantify the result. Pure differentiation, low hardware cost. |
| 2 | **Server-side HRV/PPG pipeline that beats on-watch DIY** — stream raw PPG batches; run the differencing→Gaussian→peak-detect→90th-pct-outlier recipe in the Python service with no RAM limit | Espruino-HRV (~30s RAM-bound), Major Input DSP recipe, #6309 | biosignal | M | The community is forced to subsample and approximate on-device. We get full-resolution overnight HRV with proper artifact rejection. This is our biosignal moat made concrete. |
| 3 | **Chest-strap fusion for workouts** — pair a BLE/ANT+ strap; when motion confidence is low, prefer strap HR, stream both, and let the coach gate training-load math on confidence | BTHRM/bthrmlite internal-HRM fallback; CoreTemp ANT+ PR #4255; PMC study (HR CCC 0.54 under activity) | firmware (BLE strap) + biosignal (fusion + confidence) + coach (load gating) | M | Directly fixes our known "wrist HR fails under load" finding with hard validation evidence. Strap-grade RR also unlocks trustworthy workout HRV. |
| 4 | **Smart sleep-phase wake** — server computes the optimal light-sleep wake moment from streamed overnight accel+HR and pushes the alarm to the band within the user's window | SleepPhaseAlarm (on-device), SteelBall (HR-onset detection) | biosignal (phase model) + firmware (alarm) + iOS (window UI) + coach | M | A beloved feature the community can only approximate on-watch. Our server-side sleep staging (already in scope) makes it materially better. High daily-touch retention hook. |
| 5 | **On-device exercise auto-classification → coach** — deploy a TFLite movement classifier to the band, fire `aiGesture`-style events, and let the coach name the exercise, count sets, and log it | Edge Impulse "skip leg day" (>95% on-device); accel\* tooling | firmware (TFLite) + coach (logging) + iOS | L | Auto-detected strength sessions feeding the coach = effortless training logs. The community proved feasibility; we add the brain that interprets and coaches. |
| 6 | **Recovery clkinfo / glanceable Titan card** — a Titan watch-face info card showing today's recovery score + one coach nudge, synced from server | clkinfo framework, addInteractive slots, widget bar | firmware (clkinfo provider) + web/iOS (sync) | S | Cheap, high-frequency surface for the score and a single coach line. Meets users where they already glance. Learn-once swipe grammar already exists. |
| 7 | **Elevation-aware load & VO₂ work** — stream barometer altitude; server computes elevation gain and folds it into training-load/VO₂max | barometer/altimeter apps, Recorder baro logging | biosignal + firmware | S | Low effort, improves the accuracy of metrics we already compute. Hikers/cyclists get credit for vertical. |
| 8 | **Offline-capture → server replay validation harness** — adopt the `hrmaccevents`→CSV→harness methodology to validate *our* pipeline against a chest-strap ground truth | EspruinoHRMTestHarness, PMC study methodology | biosignal (test infra) | S | Turns our HR/HRV claims into defensible, regression-tested numbers. Cheap credibility; reuses our existing QA tracking. |
| 9 | **Sport-mode + LED-tuning awareness** — have the band set `hrmSportMode` per detected activity and correct the known ~10 BPM resting offset server-side | Discussion #7232 (sport modes, `hrmWr 0x17`, rest bias) | firmware + biosignal | S | Squeezes free accuracy out of the existing sensor; the resting-offset correction is a known, documented bias we can calibrate away in the cloud. |

**Opinionated take:** ideas 1–4 are the slate. #1 is the marketing-grade differentiator, #2 is the moat, #3 is the credibility fix for a known weakness, #4 is the daily-retention hook. Everything below is low-effort polish that compounds.

---

## 3. Wild cards

1. **HRV biofeedback as a coach-gated "earn it" mechanic.** Extend #1: the coach prescribes a breathing session *when the band detects a stress spike* (HR up, HRV down mid-day), buzzes you into a paced session, and confirms recovery in real time. The community has the pacer; nobody has the *trigger + verification* loop. This is the playful, sticky version of HRV training.

2. **"Sleep-onset journaling" / hypnagogic capture.** Borrow SteelBall's HR-drop onset detection (https://github.com/jabituyaben/SteelBall) to timestamp the exact moment you fall asleep, then have the coach correlate sleep-onset latency with the day's caffeine/training/stack (we already track supplements). A creativity/insight angle no mainstream wearable touches.

3. **DIY SpO2 / respiration experiment.** #6309 says SpO2 needs faster sampling + red/IR and a vendor blob, and BP could key off PPG pulse rise/fall edge timing. Prototype *respiration rate* (very feasible from PPG baseline modulation) server-side first as the achievable win, and run a labeled SpO2 data-collection experiment honestly flagged as research-grade. Even a validated respiration-rate metric would be a meaningful "beyond HR" milestone.

4. **Auto-coached workout from raw motion, no app tap.** Combine on-device exercise classification (#5) with always-streaming: the band silently detects "you started lifting," the server segments sets/reps from the accel stream, and the coach DMs you a finished, editable workout log post-session. The promise: never log a workout again.

5. **Personalized motion-artifact model per user.** Use our streamed accel+PPG corpus to train a *user-specific* artifact-rejection model (their gait, their wrist) in the Python service — going beyond the community's generic FFT/Gaussian approaches (hrmmar, Major Input). A wearable that gets *more accurate the longer you wear it* is a genuinely novel, defensible story.

---

*Cross-cutting thesis: every interesting community project hits the same wall — 256KB of RAM, no server, no AI. Titan removes that wall. We don't need to out-hack the watch firmware; we need to be the cloud brain that turns their proven on-device signal capture into coached, validated, personalized recovery.*
