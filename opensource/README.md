# Titan — Open-Source Plan

**An open, subscription-free fitness & recovery wearable + platform that anyone can
build, self-host, and own.**

> Titan exists so anyone, anywhere can build an honest fitness-and-recovery wearable,
> keep their own physiological data on their own hardware, and improve their everyday
> wellbeing — without a subscription, without surrendering their data, and without
> anyone telling them what they're allowed to see about their own body.

Titan is a **DIY reference design**, not a product. It is **not a medical device**.
Read [`../DISCLAIMER.md`](../DISCLAIMER.md) and [`SAFETY.md`](SAFETY.md) first.

---

## Three pillars

1. **Data ownership** — local-first, self-hostable, full export. This is the one thing
   subscription incumbents structurally cannot match, and it's also the privacy and
   regulatory strategy: if there's no company-side data controller, whole categories of
   liability simply never apply.
2. **No subscriptions / accessibility** — buildable at maker-board cost, with no fees,
   ever, to see your own data.
3. **Open, honest, auditable** — open algorithms mean inspectable scores, not a black
   box. We never overclaim, never give medical advice, and are honest about accuracy.

**Non-negotiable guardrails:** wellness vocabulary only (no diagnose / monitor / treat,
no ECG / AFib / apnea / BP); stay on the information side (publish designs, don't sell
finished units, don't charge for data, keep it local-first).

---

## Multi-repo layout & license-by-role

Titan is split across multiple repos under a GitHub org (`titan-wearable`), because the
layers have different licenses, toolchains, audiences, and release cadences. We mirror
on Codeberg for takedown-resistance.

| Repo | License | Contents | Why this license |
|---|---|---|---|
| **`titan-hardware`** | **CERN-OHL-S-2.0** | Schematics, PCB, gerbers, CAD/STEP, BOM, OSHWA cert | Hardware-native, strongly reciprocal (deters closed clones); §6 allocates product liability + hold-harmless; patent grant |
| **`titan-firmware`** | **Apache-2.0** | Device firmware + BLE protocol spec + simulator | Patent grant + defensive termination in a patent-dense field; PebbleOS precedent |
| **`titan-algorithms`** | **Apache-2.0** | HRV / sleep / recovery scoring + validation notebooks | Maximize reuse (OpenAPS oref0 model) + patent grant |
| **`titan-server`** | **AGPL-3.0** | Self-hostable sync + web platform | Network copyleft → no closed paid-SaaS fork (Nightscout / Gadgetbridge model); individuals self-host freely |
| **`titan-app`** | **Apache-2.0** | Companion mobile app (local-first) | Frictionless integration + patent grant |
| **`titan-sdk`** | **Apache-2.0** | Client libs + documented local/HTTP API | Frictionless third-party integration |
| **`titan-apps`** | **Apache-2.0** | Static-site watch-app catalog (PR-to-publish) | Permissive so the app ecosystem can flourish |
| **`titan-docs`** | **CC-BY-4.0** | Build, flash, self-host, governance docs | Content license; docs *are* the product — spread + translation |
| **Datasets** (opt-in) | **CDLA-Permissive-2.0 / CC0** | Anonymized, opt-in real-world-evidence data | Data license; research-friendly; only genuinely anonymized GDPR Art. 9 opt-in data |
| **`.github`** | — | CoC, GOVERNANCE, SECURITY, CONTRIBUTING, issue/PR templates | Org-wide community health files |

Full canonical license texts (each with a "why this layer" header) live in
[`LICENSES/`](LICENSES/).

**Patent posture:** Apache on all code (contributor patent grant + defensive
termination); CERN-OHL-S on hardware (patent grant + reciprocity); design around
incumbents (distinct UI + metric vocabulary, distinct form factor) and run an
FTO/prior-art scan before launch. Publishing designs openly is itself defensive prior
art. We also get **OSHWA self-certified** (free official OSHW mark + UID; requires
publishing BOM/schematics/design files — doubles as a reproducibility checklist).

---

## Reproducibility artifacts (the OSHWA + OpenAPS standard)

A stranger must be able to rebuild Titan from the repos alone:

- Complete BOM with sourceable links + certified part specs (interactive HTML iBOM).
- Schematics + PCB + gerbers (KiCad).
- Mechanical STEP + STL + print settings.
- Fab + assembly guide with photos.
- Firmware flashing guide — OTA + wired fallback, ideally **browser Web-BT / WebSerial
  flashing** to lower the floor.
- A **simulator** (InfiniSim-style model) so app contributors need no hardware.
- Calibration / validation notebooks + known-good reference measurements.
- **One-click self-host** (Docker / Raspberry Pi).
- Safety section front-and-center; troubleshooting guide.

---

## Governance & community health

- [`CONTRIBUTING.md`](CONTRIBUTING.md) — contribution flow with **DCO sign-off** (not a CLA).
- [`GOVERNANCE.md`](GOVERNANCE.md) — BDFL-lite stewards → maintainer-council path.
- [`CODE_OF_CONDUCT.md`](CODE_OF_CONDUCT.md) — Contributor Covenant 2.1.
- [`SECURITY.md`](SECURITY.md) — coordinated disclosure.
- [`SAFETY.md`](SAFETY.md) — battery + skin-contact builder warnings.
- [`launch-checklist.md`](launch-checklist.md) — pre-launch gate.

---

## Phased open-source roadmap

### Phase 0 — Private foundation (build to reproducibility) — ~mo 0–4
Reach a known-good prototype a stranger could replicate. Lock claim discipline +
disclaimers. Run FTO/prior-art scan vs Oura/Whoop. Pick licenses. Reserve org +
trademark. Source UN 38.3 cell + skin-safe materials.
**Milestone:** one external person builds a working unit from draft docs.

### Phase 1 — Public reference design — ~mo 4–7
Open `titan-hardware` (CERN-OHL-S) + `titan-firmware` (Apache) + `titan-docs` (CC-BY)
with complete BOM, gerbers, flashing, simulator, and safety pack. Stand up Open
Collective. Publish governance docs. Get **OSHWA self-cert**. Apply for NLnet/NGI.
**Show HN launch:** *"open, subscription-free recovery wearable — build it yourself,
own your data"* + r/QuantifiedSelf + Hackaday.
**Milestone:** first 3–5 external builds.

### Phase 2 — Platform + app ecosystem — ~mo 7–12
Open `titan-server` (AGPL) + self-host + export, `titan-sdk` + local API, `titan-apps`
catalog, `titan-algorithms` + notebooks. Launch opt-in anonymized dataset. Optional
parts-kit (parts, never finished units).
**Milestone:** non-experts self-host; >100 builders; community watch apps.

### Phase 3 — Sustainability + optional legitimacy on-ramp — ~mo 12–24
Stand up a maintainer council / lightweight foundation. Fund 1–2 maintainers. Publish
validation/outcomes (OpenAPS → CREATE moat, wellness-framed). Optional commercial
on-ramp via a *separate* entity/partner — the free DIY version persists forever. Any
regulated feature becomes a *separate* cleared product, never merged into the wellness
device.

---

**The two rails that never change:** (1) **claim discipline** — wellness vocabulary
only; (2) **stay on the information side** — publish designs, don't sell finished units,
don't charge for data, keep it local-first.

*This plan is a strategy synthesis, not legal advice. Engage qualified regulatory and
privacy counsel before any public release or kit sale.*
