# 01 — Hardware Plan

*A Whoop/Polar alternative: a wrist/forearm band that streams raw PPG + motion over BLE; all heavy
HRV/sleep/strain algorithms run server-side. Optimized for overnight + resting HRV, sleep, RHR, steps.
Target: 2 units first, open-sourced later.*

> **Architecture decision that shapes everything:** because algorithms run on the server, the device's only
> job is to **capture clean raw PPG + accelerometer, timestamp it, and stream/log it over BLE.** Optimize for
> *signal quality and battery*, not on-device compute. The on-chip "BPM register" of any sensor is useless to
> us — we need the **raw FIFO samples** (the averaged BPM has already discarded the ms-level inter-beat timing
> that HRV is made of).

## 0. TL;DR
- **P0 (now):** Buy a **Bangle.js 2** (~$125). Streams **raw PPG (`HRM-raw`, 25 Hz) + raw accel over BLE in
  JavaScript, no firmware toolchain.** Prove the whole server-side HRV pipeline on real raw data immediately.
- **P1 (prototype):** **Seeed XIAO nRF52840 ($9.90) + SparkFun MAX30101 (SEN-16474, $34) + Adafruit LSM6DSOX
  ($12)** — ~$70 to validate your own sensor stack.
- **P2/P3 (custom band):** **nRF52840 (Raytac MDBT50Q module) + MAXM86161 (or MAX86141) PPG + BMI270 IMU +
  BQ25120A PMIC + 150 mAh LiPo**, SLA-resin case with a **dual-window optical stack** (separate LED/PD windows,
  black divider).
- **Fork, don't start from scratch:** [`uqjwy/whoop-alternative`](https://github.com/uqjwy/whoop-alternative)
  (MIT) is almost the exact target (nRF52840 + MAX86141 + IMU + Zephyr + Flutter).
  [ProtoCentral HealthyPi Move](https://github.com/Protocentral/healthypi-move-hw) (CERN-OHL-P) is the best
  open wrist-PPG hardware reference.

## 1. Three build paths

### (a) Hackable off-the-shelf watches
| | **Bangle.js 2** | **PineTime** |
|---|---|---|
| MCU | nRF52840 (M4F, 256KB/1MB + 8MB ext) | nRF52832 (M4, 512KB/64KB) |
| PPG | Vcare **VC31B** (I2C, 25 Hz raw) | **HRS3300** (green) |
| Accel | Kionix **KX022** (≤25 Hz) | Bosch **BMA421/425** |
| **Raw PPG over BLE** | ✅ native (`HRM-raw` → `e.vcPPG`, `Bangle.setHRMPower(1)`, stream over NUS in JS) | ⚠️ custom firmware fork only |
| Battery / waterproof | 175 mAh / IP67 | 170-180 mAh / IP67 |
| Price | ~$125 | ~$27 |
| Stream-raw difficulty | **2/10** | 6/10 |

**Caveat:** Bangle.js NUS/Web-BT link ≈ **2,500 B/s** — 25 Hz raw PPG fits; PPG+3-axis accel together needs
binary packing. Precedents: [jabituyaben/Espruino-HRV](https://github.com/jabituyaben/Espruino-HRV),
[gfwilliams/EspruinoHRMTestHarness](https://github.com/gfwilliams/EspruinoHRMTestHarness).
**Verdict: Bangle.js 2 is the right P0** — real raw PPG+accel over BLE in an afternoon of JS, worn overnight.

### (b) Dev-board prototype
- **MCU:** Seeed **XIAO nRF52840** ($9.90) / Sense ($16) / Adafruit Feather nRF52840 Express ($25, onboard LiPo charger).
- **PPG:** **SparkFun MAX30101 Qwiic (SEN-16474, $34)** — green LED, raw 18-bit FIFO over I2C.
- **IMU:** **Adafruit LSM6DSOX STEMMA QT ($12)** — 9 KB FIFO.
- ~$70 total, difficulty **4/10**.
> ⚠️ **No cheap in-stock MAX86141/MAXM86161 breakout exists.** Prototype with **MAX30101**; for MAXM86161 the
> off-the-shelf option is **MikroE Heart Rate 2 Click (MIKROE-4037, ~$28)**.

### (c) Custom PCB from scratch
Full board: nRF52840 module + PPG AFE + IMU + PMIC + LiPo in a 3D-printed case. **Destination, not start.**
Difficulty **8-9/10** (RF layout, optics, SPI level-shift, sealing). De-risk by forking HealthyPi Move's KiCad
and using a **pre-certified RF module** to skip FCC/CE intentional-radiator testing.

## 2. Recommended custom-band BOM

**MCU — nRF52840** (winner). Sleep ~1.5 µA (vs ESP32 ~8-15 µA — a wearable is >99% asleep, so sleep floor =
battery life). Use a **pre-certified module** to skip RF cert: **Raytac MDBT50Q-1MV2** / Fanstel BT840 / Minew
MS88SF3. nRF5340 only if you want on-device DSP later. **Avoid ESP32** unless you need WiFi.

**PPG AFE:**
| | MAX86141 | MAX86150 | MAX30101 | MAXM86161 |
|---|---|---|---|---|
| Type | AFE only (ext LED+PD) | PPG **+ ECG** | integrated module | integrated module |
| LEDs | you choose | Red+IR (no green) | Green+Red+IR | Green+Red+IR |
| ADC/FIFO | 19-bit / 128 | 19-bit / 32 | 18-bit / 32 | 19-bit / 128 |
| Bus | **SPI** | I2C | I2C | I2C |
| Supply | 1.8V + VLED | 1.8V + VLED | 1.8V + VLED | **single 3.0-5.5V** |
| Lifecycle | Active | **EOL 2026 — avoid** | Active | Active |

- **Green (~525 nm)** = right primary for wrist HR/HRV (best SNR, motion-robust). Red/IR penetrate deeper,
  needed for SpO2, better for darker skin tones + low-perfusion overnight. A multi-wavelength part runs green
  primary + red/IR fallback.
- **Sample rate sets the HRV timing floor.** Per Béres & Hejjel (*Sensors* 2022): un-interpolated IBI error
  ±101 ms @ 8 Hz vs ±15.8 ms @ 64 Hz; **with parabolic interpolation 32 Hz ≈ 256 Hz.** → **Sample raw ≥100 Hz
  (250 ideal), never <16 Hz, interpolate peaks server-side.**
- **LED→photodiode crosstalk/saturation is the #1 optical failure.**
- **Recommendation:** **MAXM86161AEFD+** (~$12, green+red+IR + integrated optical barrier, single supply) for
  a compact band; or **MAX86141** (~$11) + external OSRAM SFH7050 for full optical control (SPI, you design
  optics). Prototype with MAX30101. Skip MAX86150 (EOL).

**IMU — BMI270** (winner, ~$3-4): purpose-built for wrist wearables, ~10 µA accel + ~30 µA always-on step/
activity/wrist-wake engine (keeps the MCU asleep). LSM6DSOX = easier prototyping (Adafruit board). ICM-42688-P
only if >6 kHz raw motion needed. Accel rate **25-50 Hz** is ample for sleep/actigraphy.

**Battery:** small LiPo, **thickness is the real constraint.** Adafruit #1317 **150 mAh** (26×19.75×3.8 mm,
$6) is the sweet spot. Reference teardowns: Whoop 4.0 band ≈ **195 mAh**, Garmin vívosmart 5 = 110 mAh,
Fitbit Charge 6 = 65 mAh — so 100-150 mAh DIY is commercial-normal.

**PMIC — BQ25120A** (winner, ~$1-1.50): charger + high-efficiency buck (efficient to 10 µA loads) + LDO +
battery ADC, **~700 nA Iq, <50 nA ship mode.** Prototype with MCP73831 + LDO (simpler). The real
whoop-alternative repo uses BQ24074 + TPS62740 + MAX17048 as a discrete equivalent.

**Enclosure:** **SLA resin (not FDM)** — watertight, smooth, O-ring grooves. Skin-safe (ISO 10993 / USP Class
VI) resin; full UV post-cure (under-curing causes "resin rash"). **Optical window = make-or-break:** separate
clear windows over LED vs photodiode + **opaque black divider** + matte-black cavity, flush to skin (mirrors
Apple Watch). Window: glass/sapphire (best) / acrylic / clear UV-resin potting. Strap: 20/22 mm silicone
quick-release. **Water: target IPX4/splash, NOT swim** (O-ring + conformal coat + RTV).

**Indicative cost/unit (qty 2-5): ~$55-80** (module $8-12, PPG $12, IMU $3-4, PMIC $1.50, battery $6, flash/
passives $5-8, PCB $5-15, enclosure $10-20).

## 3. Power budget (150 mAh cell, 120 mAh usable)
Active "dim" ≈ 4 mA (LEDs ~1.5 + IMU 0.6 + BLE/CPU ~1.5); active "bright" ≈ 15 mA; idle ≈ 0.1 mA.
| Scenario | Avg | Life |
|---|---|---|
| **Overnight-only (8h active), dim** | 1.4 mA | **~3.6 days** |
| Overnight-only, bright | 5.1 mA | ~1 day |
| **24/7 continuous streaming, dim** | 4 mA | **~30 h** |
| 24/7 mid/bright | 8/15 mA | 15/8 h |

**Bottom line:** overnight-only is comfortable (~2-3.6 days); **24/7 raw streaming on 150 mAh = daily charge**
— exactly why continuous motion-robust strain is scoped late. **Duty-cycling levers (ranked):** (1) IMU-gated
PPG (PPG fires only when needed); (2) burst-then-sleep; (3) lower LED current + sealed optics; (4) longer BLE
interval; (5) **log-to-flash-then-sync** (whoop-alternative hits ~1.2 mA, 7-day target on 200-250 mAh); (6)
reserve continuous streaming for tethered workouts.

## 4. Reference open-hardware to fork
- **[uqjwy/whoop-alternative](https://github.com/uqjwy/whoop-alternative)** (MIT) — closest match: nRF52840
  (MDBT50Q) + MAX86141 + SFH7050 + BMA400 + 8 MB flash, Zephyr FW + Flutter app + Python analysis. **The skeleton.**
- **[ProtoCentral HealthyPi Move](https://github.com/Protocentral/healthypi-move-hw)** — open clinical watch,
  nRF5340 + MAX86141 + MAXM86161, full KiCad. Mine its optical layout.
- **[HeartyPatch](https://github.com/Protocentral/protocentral_heartypatch)** — ESP32 + MAX30003 ECG/HRV patch.
- AFE drivers: [joshbrew/MAX86141_Arduino](https://github.com/joshbrew/MAX86141_Arduino),
  [Passoll/Maxm86161_Driver](https://github.com/Passoll/Maxm86161_Driver).
- Raw-PPG-over-BLE: **[OpenEarable 2.0](https://open-earable.teco.edu/)** (streams PPG up to 400 Hz),
  [Gadgetbridge](https://codeberg.org/Freeyourgadget/Gadgetbridge) (logs raw for reinterpretation).
- Server HRV: [NeuroKit2](https://github.com/neuropsychology/NeuroKit), [HeartPy](https://github.com/paulvangentcom/heartrate_analysis_python),
  [ElliotY-ML/Heart_Rate_Estimation_PPG_Acc](https://github.com/ElliotY-ML/Heart_Rate_Estimation_PPG_Acc) (HR from PPG **+ accel**).

## 5. Phased hardware roadmap
| Phase | Goal | Deliverable | Cost | Diff |
|---|---|---|---|---|
| **P0** | Prove the loop | 2× Bangle.js 2 streaming `HRM-raw`+accel → server computes HRV/sleep | ~$250 | **2** |
| **P1** | Validate own sensor stack | XIAO nRF52840 + MAX30101 + LSM6DSOX + LiPo; Zephyr streams raw | ~$80 | **4** |
| **P2** | Custom PCB Rev A | 4-layer: MDBT50Q + MAXM86161 + BMI270 + BQ25120A + 8 MB flash; bench bring-up | ~$150-250 | **8** |
| **P3** | Wearable form factor | Rev B in SLA case + dual optical window, 150 mAh, 20 mm strap, ~2-day battery | ~$80/unit | **9** |

Gate each phase on the prior working: no PCB until the sensor stack streams clean HRV-grade PPG on the dev board.

## 6. Honest risks
| Risk | Why hard | Mitigation |
|---|---|---|
| PPG in motion | arm swing overlaps cardiac band; one bad beat poisons RMSSD | **overnight-first**; stream accel for server cancellation; defer 24/7 strain |
| Optical crosstalk | LED→PD leak saturates AFE | dual windows + black divider + matte cavity; MAXM86161 integrated barrier |
| Battery vs comfort | 24/7 streaming needs daily charge; bigger cell = thicker | overnight duty cycle, IMU-gated PPG, log-then-sync |
| HRV timing fidelity | HRV is ms-scale | ≥100 Hz raw, timestamp on-device, interpolate server-side |
| BLE antenna/RF cert | custom RF is expensive/hard | **pre-certified module** (MDBT50Q) |
| Water resistance | DIY sealing rarely survives submersion | target IPX4; O-ring + conformal coat; "not for swimming" |
| Part obsolescence/stock | MAX86150 EOL; IMU lead times | avoid MAX86150; order early; second-source BMI323 |
| SPI level-shift (MAX86141) | 1.8V SPI, easy to miswire | prefer MAXM86161 (I2C, single supply) unless custom optics needed |

**Research-corrected premises:** MAX86141 is **SPI not I2C**; no cheap MAX86141/MAXM86161 breakout exists;
MAX86150 (chip+breakout) is **EOL 2026**; Whoop 4.0 band ≈ **195 mAh** (not the 638 mAh charger figure).
