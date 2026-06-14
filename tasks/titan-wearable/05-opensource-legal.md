# 05 — Open-Source, Legal & Community Strategy

*An open, subscription-free fitness/recovery wearable + platform anyone can build and own. NOT a regulated
medical device — a wellness/quantified-self project.*

> **Disclaimer:** strategy synthesis from primary sources, **not legal advice.** Engage qualified regulatory +
> privacy counsel before any public release or kit sale.

**The governing thesis:** classification, liability, and disclaimer-validity are driven by **what you claim the
device does**, not what the sensor measures. The same PPG/temp hardware is a harmless gadget or a regulated
device purely as a function of your words. Titan's posture = disciplined **claim control** + **local-first/self-
host architecture** + the **OpenAPS "you build it, you own the risk"** distribution model.

## 1. License map (license by component role)
| Layer | License | Why |
|---|---|---|
| **Hardware** (schematics, PCB, CAD, BOM) | **CERN-OHL-S-2.0** (W as fallback) | Hardware-native, strongly reciprocal (deters closed clones), **§6 names "product liability" + hold-harmless** (ideal for a body-worn LiPo device), patent grant. OpenBCI used copyleft (CC-BY-SA) for the same reason. |
| **Firmware** | **Apache-2.0** | **Patent grant + defensive termination** — critical in a patent-dense field (Oura ITC vs Ultrahuman 2025; Whoop v Bevel 2026). MIT gives no patent shield. PebbleOS open-sourced Apache-2.0 (2025). |
| **Client SDK / mobile app** | **Apache-2.0** | Frictionless third-party integration. |
| **Self-host server / web platform** | **AGPL-3.0** | **Network copyleft → no closed paid-SaaS fork** (the Nightscout model; Gadgetbridge precedent). Individuals self-host freely. |
| **Algorithms** | **Apache-2.0** | Maximize reuse (OpenAPS `oref0` model) + patent grant. |
| **Datasets** (opt-in, anonymized) | **CDLA-Permissive-2.0 / CC0** | Data license, research-friendly. Only release genuinely anonymized, explicit opt-in data (GDPR Art. 9). |
| **Docs / guides / site** | **CC-BY-4.0** | Content license; spread + translation (docs *are* the product in open hardware). |

**Patent posture:** Apache on all code (contributor patent grant + defensive termination); CERN-OHL-S on
hardware (patent grant + reciprocity); **design around incumbents** (distinct UI + metric vocabulary to dodge
Whoop trade-dress; distinct form factor to dodge Oura ITC patents; FTO/prior-art scan before launch);
**publishing designs openly is itself defensive prior art.** Also get **OSHWA self-certified** (free, official
OSHW mark + UID; requires you publish BOM/schematics/design files — doubles as a reproducibility checklist).

## 2. Precedents
- **OpenAPS / Nightscout (#WeAreNotWaiting) — the gold standard.** An open **reference design**, explicitly
  **NOT a product, not sold** — each build is an "(n=1) experiment individuals have a right to do to themselves,"
  keeping them *outside FDA jurisdiction* (publishing info ≠ selling a device). Total, explicit risk-ownership
  click-through. Engaged FDA **collaboratively**. On-ramp: DIY Loop → nonprofit **Tidepool** → **510(k) cleared
  Jan 2023** using real-world evidence (1,000+ DIY loopers), funded $6M Helmsley/JDRF; legitimized by the
  **CREATE RCT (NEJM 2022)**. **License split:** `oref0` MIT (algorithm spread), Nightscout AGPL-3.0 (hosted-app
  protection). Thrived on: acute motivation, docs-as-product, social-first onboarding, real-world-evidence moat,
  constructive regulatory engagement.
- **Gadgetbridge** — cloudless, all-local, AGPLv3, ~489 devices, no C&Ds (clean RE hygiene: documents BLE
  sniffing, *warns against* decompiling APKs, works around DRM via official pairing; ships on F-Droid).
- **OpenBCI** — open designs as marketing, sells assembled+QA'd boards. HW = CC-BY-SA-4.0 (deter clones), SW = MIT.
- **Oura/Whoop RE communities** — the grievance Titan exploits ("paying to see my own data"); incumbents don't
  sue hobbyists but **weaponize IP against commercial rivals** → stay distinct + clear the patent landscape; an
  open documented local API is the one thing they structurally can't match.
- **PineTime/InfiniTime/WASP-OS/Bangle.js** — one hardware platform hosts multiple firmwares (resilience);
  **InfiniSim simulator** lets contributors build UI without hardware; Bangle **App Loader** = static GitHub
  catalog, install via Web BT, submit via PR (near-zero ops).
- **Pebble/Rebble** — PebbleOS Apache-2.0 (2025), 100% open incl. mechanical; **Core-vs-Rebble app-store friction
  = governance cautionary tale** (write the rules down early). **openwearables.io** = MIT data-normalization API.

**Thrive factors:** acute motivation; docs-as-product; lower the contributor floor (simulators, browser
flashing, high-level languages); license-by-role; sell convenience not secrecy; social-first distribution;
real-world-evidence moat + regulatory on-ramp; static-site app stores; designed commercial on-ramp that doesn't
kill the free version. **Stall modes:** premature "manufacturer" status; closed/thin APIs; IP litigation from
copying UI/form; single-vendor SPOF; governance vacuum → conflict; RE legal sloppiness; tribal knowledge.

## 3. Legal / regulatory / safety
**The wellness vs medical line (controls everything).** **FDA "General Wellness: Policy for Low Risk Devices"**
— a **Jan 6, 2026 revision** now supersedes the 2019 version and expressly addresses **noninvasive physiologic
trackers (HRV, SpO2, BP, glucose)** (cite the 2026 edition; pull verbatim from the live FDA PDF). Two-part test:
escapes regulation only if BOTH (1) intended for **general wellness only** AND (2) **low risk** (noninvasive).
A wrist PPG + accel + skin-temp device clears the low-risk prong → the whole question collapses onto **claims.**
- HRV for stress/recovery/readiness = wellness ✅; HRV to *detect a condition* = device ❌.
- **ECG/AFib** (Apple De Novo DEN180042) and **sleep-apnea detection** (Apple, FDA-cleared Sept 2024) = devices.
  **Do not ship these.** SpO2 as a wellness sleep metric may be OK under 2026 guidance; clinical pulse-ox = device.
- **EU MDR 2017/745:** "medical device" needs a **medical purpose**; **Recital 19** — *"software for lifestyle
  and well-being is not a medical device."* MDCG 2019-11 decision tree; a fitness/recovery wearable fails the
  qualifying-medical-purpose node. Crossing the line → Rule 11 → **Class IIa/IIb** (Notified Body, clinical
  eval) — the strongest reason to stay in the lifestyle lane.

**Disclaimers don't save a diagnostic function.** FDA sent **WHOOP a warning letter (Jul 14, 2025)** over Blood
Pressure Insights — wellness disclaimers "ineffective" because BP estimation "is inherently associated with the
diagnosis of hypo/hypertension." HR/HRV/sleep/temp/activity = where the playbook works; BP/arrhythmia/disease-
screening = where it doesn't. **Recommended Titan disclaimer** (Terms + onboarding tap-accept + build guide +
README):
> "Titan is not a medical device. It is for general wellness and informational purposes only and is not
> intended to diagnose, treat, cure, mitigate, or prevent any disease. It is not a substitute for professional
> medical advice. Always consult a qualified healthcare professional before changing your health regimen.
> Measurements have limitations and are not guaranteed accurate. Any action you take is at your own risk."

**Liability framing (DIY).** License clauses (MIT/Apache §7-8 "AS IS", CERN-OHL §6 product-liability + indemnity)
defeat contract/warranty claims, **not all tort claims** (negligence, failure-to-warn survive) → conservative,
accurate docs matter as much as license text. **The line you must not cross:** assembling/selling finished
units, charging for warranty/support, or making medical claims — each flips you to "seller in the business of
selling." **EU PLD 2024/2853** (after Dec 2026): strict liability for software + digital files; OSS carve-out
exempts non-commercial FOSS **but evaporates if you charge/monetize data, and is software-only — open *hardware*
files are NOT exempt.** → another reason not to sell finished units. **Adopt the OpenAPS click-through:** "you
assume full responsibility, release all contributors, DIY at your own risk, experimental, not a medical device."

**Data privacy.** **HIPAA almost certainly does NOT apply** (DTC wellness app, not a covered entity/BA). What
governs in the US: **FTC** (Act §5 + Health Breach Notification Rule, up to $50,120/violation) — enforcement
pattern is **ad-tech/SDK leakage** (GoodRx $1.5M, BetterHelp $7.8M, Premom). **Mandate: zero advertising/
analytics SDKs on the health-data path; affirmative consent before any disclosure.** Watch state laws (WA My
Health My Data, CCPA/CPRA, NV, CT). **GDPR** (any EU-facing cloud): wearable health data = **Art. 9 special
category** → explicit consent; reaches a US company serving EU residents. **The architecture is the privacy
strategy:** local-first/self-hosted → no company-side controller (shrinks GDPR), no PHR vendor (GoodRx fact
pattern impossible), HIPAA never in scope, GDPR household exemption shields the user's local copy. Build: on-
device default; cloud sync **off by default** behind Art. 9 opt-in; full export; E2E encryption with user-held
keys; no trackers on the health path.

**Physical safety.** **Battery transport = the one hard legal gate: UN 38.3** (8 abuse tests; Test Summary
required for shipping, esp. air) — **source pre-certified protected cells, don't test yourself.** Also IEC
62133-2, UL 1642/2054. DIY mitigations: **never a bare pouch cell** — protected cell or BMS/PCM; proper charge
IC (**TP4056-*with-protection*** = DW01A + dual-MOSFET, not the bare module); mechanical protection so the pouch
can't flex/crush; never charge unattended/while worn. **Skin contact:** ISO 10993 (-5 cytotoxicity, -10
sensitization, -23 irritation); **REACH nickel Entry 27** (<0.5 µg/cm²/week, EN 1811) — a worn wearable triggers
"prolonged contact." **Specify skin-safe materials in the BOM:** platinum-cured medical silicone straps, 316L/
titanium, **avoid bare nickel + uncured resin.** Name the *certified* part in the BOM ("protected 503035 LiPo,
UN 38.3 + IEC 62133-2"), ship battery + skin warnings in the build guide.

## 4. Repo & docs structure
**Multi-repo** under a GitHub org `titan-wearable` (different licenses/toolchains/audiences/cadences). Mirror on
Codeberg if takedown-resistance matters.
```
titan-hardware/   CERN-OHL-S   schematics, PCB, gerbers, CAD/STEP, BOM, OSHWA cert
titan-firmware/   Apache-2.0   device firmware + BLE protocol spec + simulator
titan-algorithms/ Apache-2.0   HRV/sleep/recovery scoring + validation notebooks
titan-server/     AGPL-3.0     self-hostable sync + web app
titan-app/        Apache-2.0   companion mobile app (local-first)
titan-sdk/        Apache-2.0   client libs + documented local/HTTP API
titan-apps/       Apache-2.0   static-site watch-app catalog (PR-to-publish)
titan-docs/       CC-BY-4.0    build-it-yourself, flashing, self-host, governance
.github/                       CoC, GOVERNANCE, SECURITY, CONTRIBUTING, templates
```
**Reproducibility artifacts (OSHWA + OpenAPS standard):** complete BOM with sourceable links + certified specs
(interactive HTML iBOM); schematics + PCB + gerbers (KiCad); mechanical STEP+STL + print settings; fab +
assembly guide with photos; firmware flashing guide (OTA + wired fallback, ideally **browser Web-BT/WebSerial
flashing**); a **simulator** (InfiniSim model) so app contributors need no hardware; calibration/validation
notebooks; **one-click self-host** (Docker/Pi); safety section front-and-center; troubleshooting + known-good
reference measurements.
**Governance:** CONTRIBUTING + **DCO sign-off** (not a heavyweight CLA); GOVERNANCE start **BDFL-lite/stewards**
(the two brothers) with a published path to a maintainer council (pre-empt Rebble-vs-Core); CoC (Contributor
Covenant); SECURITY (coordinated disclosure); "good first issue" + New-Device/New-App tutorials (Gadgetbridge
model).

## 5. Community & sustainability (mission-driven, without crossing the lines)
**Trap:** selling finished units / subscriptions / monetizing data → "manufacturer"/"seller" liability + FDA
attention + loses EU-PLD OSS carve-out + betrays the mission. Sustain **without** crossing.
- **Open Collective + Open Source Collective (501(c)(6))** fiscal host — accept donations/grants, pay
  contributors, transparent ledger, **no legal entity needed** (cf. Nightscout Foundation).
- Recurring donations (GitHub Sponsors, Open Collective, Liberapay). **Grants: NLnet/NGI** (€5k-50k+, open
  hardware/data-sovereignty fit), Sovereign Tech Fund, health philanthropies (OpenAPS→Helmsley precedent).
- **Optional kits — carefully:** sell **parts kits** (hard-to-source PCBs/components, *not* finished assembled
  devices) and/or route assembled sales through a **separate community manufacturer/partner** under trademark
  license. Always cost-recovery/mission, never the value prop; ship UN-38.3 cells + disclaimers.
- **Growth:** social-first (Discord/Matrix → users onboard users); lower the contributor floor (simulator,
  browser flashing); docs-as-product; opt-in anonymized real-world-evidence loop (improves algorithms + builds
  the credibility moat); static-site app store.

## 6. Mission framing ("good for humanity," responsibly)
> **Titan exists so anyone, anywhere can build an honest fitness-and-recovery wearable, keep their own
> physiological data on their own hardware, and improve their everyday wellbeing — without a subscription,
> without surrendering their data, and without anyone telling them what they're allowed to see about their own
> body.**

Three pillars: **(1) data ownership** (local-first, self-host, full export — the one thing subscription
incumbents structurally can't match; this is also the privacy/regulatory strategy); **(2) no subscriptions /
accessibility** (buildable at maker-board cost); **(3) open, honest, auditable** (open algorithms = inspectable
scores, not a black box). **Guardrails (non-negotiable):** never overclaim health benefits; never give medical
advice (route to professionals); be honest about accuracy (also failure-to-warn protection); don't moralize
about bodies (empower, don't induce health anxiety).

## 7. Phased open-source roadmap
- **Phase 0 — Private foundation (build to reproducibility), ~mo 0-4:** reach a known-good prototype a stranger
  could replicate; lock claim discipline + disclaimers; FTO/prior-art scan vs Oura/Whoop; pick licenses; reserve
  org + trademark; source UN-38.3 cell + skin-safe materials. *Milestone:* one external person builds a working
  unit from draft docs.
- **Phase 1 — Public reference design, ~mo 4-7:** open titan-hardware (CERN-OHL-S) + titan-firmware (Apache) +
  titan-docs (CC-BY) with complete BOM, gerbers, flashing, simulator, safety pack; Open Collective; governance
  docs; **OSHWA self-cert**; apply NLnet/NGI; **Show HN launch** ("open, subscription-free recovery wearable —
  build it yourself, own your data") + r/QuantifiedSelf + Hackaday. *Milestone:* first 3-5 external builds.
- **Phase 2 — Platform + app ecosystem, ~mo 7-12:** open titan-server (AGPL) + self-host + export, titan-sdk +
  local API, titan-apps catalog, titan-algorithms + notebooks; opt-in anonymized dataset; optional parts-kit.
  *Milestone:* non-experts self-host; >100 builders; community watch apps.
- **Phase 3 — Sustainability + optional legitimacy on-ramp, ~mo 12-24:** maintainer council/lightweight
  foundation; fund 1-2 maintainers; publish validation/outcomes (OpenAPS→CREATE moat, wellness-framed); optional
  commercial on-ramp via a *separate* entity/partner (free DIY persists forever); any regulated feature → a
  *separate* cleared product, never merged into the wellness device.

**The two rails that never change:** (1) **claim discipline** — wellness vocabulary only, no diagnose/monitor/
treat, no ECG/AFib/apnea/BP; (2) **stay on the information side** — publish designs, don't sell finished units,
don't charge for data, keep it local-first.

## Currency caveats to verify before publishing legal copy
1. **FDA:** the Jan 6, 2026 "General Wellness" revision supersedes 2019 and now addresses HRV/SpO2/BP/glucose —
   cite that edition, pull verbatim from the live FDA PDF.
2. OpenBCI hardware = CC-BY-SA-4.0 (not MIT); there's no direct Whoop-v-Oura suit — incumbent IP litigation runs
   against smaller rivals (Whoop-v-Bevel, Oura-ITC-v-Ultrahuman/RingConn).
