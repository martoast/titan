# Titan Pre-Launch Checklist

Run this gate **before** flipping any `titan-*` repo public and before any Show HN /
Hackaday / r/QuantifiedSelf post. It exists to keep us on the two rails — **claim
discipline** and **stay on the information side** — and out of avoidable legal trouble.

Owner: stewards. Nothing goes public until every box below is checked or explicitly
waived in writing with a reason.

---

## 1. Claim-discipline review

The single highest-leverage review. Classification and disclaimer-validity are driven by
**what we claim the device does**, not what the sensor measures.

- [ ] Every public surface (README, landing page, app onboarding, build guide, marketing
      copy, screenshots, video) uses **wellness vocabulary only**: stress, recovery,
      readiness, sleep, activity, energy. No "diagnose / monitor / treat / detect /
      screen."
- [ ] **No prohibited features present or implied** anywhere: no ECG, no AFib/arrhythmia,
      no sleep-apnea detection, no blood-pressure estimation, no clinical pulse-ox.
      (FDA warned WHOOP over BP Insights on 2025-07-14 — disclaimers don't save a
      diagnostic function.)
- [ ] The **Recommended Titan disclaimer** appears in: Terms, app onboarding tap-accept,
      build guide, and every repo README. (See [`../DISCLAIMER.md`](../DISCLAIMER.md).)
- [ ] The **OpenAPS-style "DIY, at your own risk, you release all contributors"**
      acknowledgment is present and, where users interact, click-through.
- [ ] Accuracy is described **honestly** (limitations stated) — also our failure-to-warn
      protection. No "clinical-grade," "accurate," or "medical" superlatives.
- [ ] No language that moralizes about bodies or could induce health anxiety.
- [ ] Re-verify against the **current FDA "General Wellness" guidance (Jan 6, 2026
      revision** supersedes 2019 and now addresses HRV/SpO2/BP/glucose — cite that edition,
      pull verbatim from the live FDA PDF) and **EU MDR Recital 19** (lifestyle/well-being
      software is not a medical device).

## 2. FTO / prior-art scan

- [ ] **Freedom-to-operate / prior-art scan** completed against incumbents (Oura, Whoop,
      and the active-litigation pattern: Oura ITC vs Ultrahuman/RingConn, Whoop v. Bevel).
- [ ] **Designed around incumbents:** distinct UI + distinct metric vocabulary (dodge Whoop
      trade-dress); distinct form factor (dodge Oura ITC patents).
- [ ] Confirmed our **open publication is logged as defensive prior art** (timestamps,
      public repos, Codeberg mirror).
- [ ] Counsel reviewed anything uncertain. (Strategy synthesis here is **not legal
      advice** — qualified regulatory + IP counsel signs off before release.)

## 3. Licensing & OSHWA

- [ ] Every repo carries the correct license per the **license-by-role map**
      (see [`README.md`](README.md)): CERN-OHL-S-2.0 (hardware), Apache-2.0
      (firmware/algorithms/sdk/app/apps), AGPL-3.0 (server), CC-BY-4.0 (docs),
      CDLA-Permissive-2.0/CC0 (datasets). License files present + SPDX headers in source.
- [ ] **DCO** sign-off enforced (bot live) on every repo; **no CLA**.
- [ ] **OSHWA self-certification** obtained (free official OSHW mark + UID). Requires
      published BOM + schematics + design files — doubles as the reproducibility checklist:
      - [ ] Complete BOM with sourceable links + **certified part specs** (incl. UN 38.3
            cell, skin-safe materials), interactive HTML iBOM.
      - [ ] Schematics + PCB + gerbers (KiCad).
      - [ ] Mechanical STEP + STL + print settings.
      - [ ] Fab + assembly guide with photos.
      - [ ] Firmware flashing guide (OTA + wired fallback; ideally browser Web-BT/WebSerial).
      - [ ] Simulator available (app contributors need no hardware).
      - [ ] Calibration/validation notebooks + known-good reference measurements.
      - [ ] One-click self-host (Docker/Pi).
- [ ] **Milestone met:** at least one external person has built a working unit from the
      docs (Phase 0 exit criterion); ideally 3–5 external builds queued for Phase 1.

## 4. Safety pack

- [ ] [`SAFETY.md`](SAFETY.md) front-and-center; battery + skin warnings in the build guide.
- [ ] BOM specifies a **protected, UN 38.3 + IEC 62133-2 certified cell**, **TP4056-with-
      protection** charger, mechanical battery protection; **never bare pouch / never
      charge unattended or while worn**.
- [ ] BOM specifies **skin-safe materials**: platinum-cured medical silicone, 316L/titanium;
      **no bare nickel (REACH Entry 27 / EN 1811)**, no uncured resin; ISO 10993 considered.
- [ ] If shipping any cells (parts kit), UN 38.3 Test Summary + transport rules handled.

## 5. Privacy / data path

- [ ] **Zero advertising/analytics SDKs on the health-data path.** Verified, not assumed.
      (FTC enforcement pattern is ad-tech/SDK leakage: GoodRx, BetterHelp, Premom.)
- [ ] **Local-first by default;** cloud sync **off by default** behind an explicit
      GDPR Art. 9 opt-in; full export; E2E encryption with user-held keys.
- [ ] Any opt-in dataset is **genuinely anonymized**, explicit opt-in, released under
      CDLA-Permissive-2.0/CC0.

## 6. Governance & community health

- [ ] `CODE_OF_CONDUCT.md`, `GOVERNANCE.md`, `SECURITY.md`, `CONTRIBUTING.md`, and issue/PR
      templates live in `.github` and are linked from each repo.
- [ ] Governance written down **before** launch (Rebble-vs-Core lesson): BDFL-lite stewards
      + documented path to a maintainer council; `titan-apps` PR-to-publish + trademark
      held for the community.
- [ ] Placeholder contacts (conduct@, security@) replaced with real reporting addresses
      + security PGP key.
- [ ] `good first issue` / `help wanted` labels seeded; "Add a new device" / "Add a new
      app" tutorials published.

## 7. Sustainability setup

- [ ] **Open Collective** set up under the **Open Source Collective (501(c)(6))** fiscal
      host (no separate legal entity needed initially — Nightscout Foundation precedent);
      transparent ledger live.
- [ ] Recurring-donation channels ready (GitHub Sponsors / Open Collective / Liberapay).
- [ ] **Grant applications** drafted/submitted: **NLnet / NGI** (€5k–50k+, open-hardware /
      data-sovereignty fit), with Sovereign Tech Fund and health philanthropies
      (OpenAPS → Helmsley precedent) as follow-ups.
- [ ] **No finished-unit sales, no subscriptions, no data monetization.** (Crossing any of
      these flips us to "manufacturer/seller," draws FDA attention, loses the EU-PLD OSS
      carve-out, and betrays the mission.) Optional **parts kits** only — parts, not
      assembled devices; cost-recovery/mission, never the value prop; ship UN 38.3 cells +
      disclaimers.

## 8. Launch framing (Show HN)

- [ ] **Show HN framing locked:** *"Show HN: Titan — an open, subscription-free recovery
      wearable you build yourself and own your data."* Honest, mission-first, no
      overclaiming, no medical language.
- [ ] Positions on **data ownership** (the thing incumbents structurally can't match — the
      open documented local API), **no subscriptions**, and **open/auditable algorithms**.
- [ ] Cross-posts prepared for **r/QuantifiedSelf** and **Hackaday**; community chat
      (Discord/Matrix) live so users can onboard users.
- [ ] **Codeberg mirror** live if takedown-resistance matters.

---

**Final gate:** stewards confirm the two rails hold end-to-end — (1) claim discipline
(wellness only, no diagnose/monitor/treat, no ECG/AFib/apnea/BP); (2) stay on the
information side (publish designs, don't sell finished units, don't charge for data,
keep it local-first). Counsel has signed off. **Then** launch.

*This checklist is a strategy/operations tool, not legal advice.*
