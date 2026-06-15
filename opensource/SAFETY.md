# Titan Build Safety

**Read this completely before you build, charge, or wear a Titan device.**

Titan involves a **lithium-polymer (LiPo) battery** and a device in **prolonged contact
with your skin**. Both can hurt you if you cut corners. This page is mandatory reading.
By building Titan you accept the risks described in [`../DISCLAIMER.md`](../DISCLAIMER.md).

Titan is a DIY wellness project, **not a medical device**, and nothing here is certified
for safety. **You build and wear it at your own risk.**

---

## 1. Battery safety (the one hard gate)

Lithium-polymer cells store a lot of energy in a small soft pouch. Abuse, defects, or
bad charging can cause swelling, venting, fire, or explosion. **Do not improvise here.**

### Use only a certified, protected cell
- **Source a pre-certified protected cell.** The BOM specifies the certified part — for
  example a **protected 503035 LiPo, UN 38.3 + IEC 62133-2**. Buy *that* part (or an
  equivalent named certified part). **Do not** substitute a random unlabeled cell.
- **Never use a bare pouch cell.** Use a cell with an integrated **protection circuit
  (PCM/BMS)** — over-charge, over-discharge, and over-current protection — or add one.
- **UN 38.3** is the legal transport standard (8 abuse tests; a Test Summary is required
  to ship cells, especially by air). **Do not test cells yourself** — source cells that
  already carry UN 38.3, IEC 62133-2, and where relevant UL 1642 / UL 2054.

### Charge it correctly
- Use a **proper charge IC**. The BOM specifies a **TP4056 *with* protection** — i.e. the
  module with the **DW01A + dual-MOSFET** protection stage — **not** the bare TP4056
  charge-only module. Match the charge current to your cell.
- **Never charge unattended. Never charge while wearing the device.**
- Stop immediately and isolate the cell (in a fireproof container / LiPo bag, outdoors if
  possible) if it gets hot, swells, smells, or is physically damaged. Do not puncture,
  bend, or short a cell.

### Protect it mechanically
- The enclosure must give the pouch **mechanical protection** so it can't flex, crush, or
  get punctured in daily wear. A creased or pierced pouch can vent or ignite.
- Provide strain relief on battery leads; avoid solder joints that can fatigue and short.

### Ship it legally
- If you ever ship a cell (e.g. in a parts kit), lithium-battery transport rules apply —
  **UN 38.3 Test Summary**, correct packaging, labeling, and air-transport restrictions.
  Comply with your carrier and jurisdiction.

---

## 2. Skin-contact safety

Titan is worn against the skin for long periods, which counts as **"prolonged contact"**
under materials-safety rules. Pick the right materials or risk irritation, sensitization,
and allergic reactions.

### Biocompatibility
- Skin-contact materials should be evaluated against **ISO 10993** — in particular:
  - **ISO 10993-5** (cytotoxicity),
  - **ISO 10993-10** (sensitization),
  - **ISO 10993-23** (irritation).
- Prefer materials with established skin-contact use.

### Nickel (REACH)
- A worn wearable triggers "prolonged contact," so **REACH Entry 27** applies: nickel
  release must stay **below 0.5 µg/cm²/week** (test method **EN 1811**).
- **Avoid bare nickel** in any part that touches skin (some plated parts, clasps, and
  cheap hardware release nickel).

### Specify skin-safe materials in the BOM
- **Straps:** platinum-cured **medical-grade silicone**. Avoid cheap silicone and avoid
  **uncured / incompletely cured resin** (3D-printed parts touching skin should be a
  skin-safe, fully cured material — bare uncured resin is a sensitizer).
- **Metal contacts / housing:** **316L stainless steel** or **titanium**. Avoid bare
  nickel and unknown alloys against skin.
- Name the specific certified/biocompatible part in the BOM, not just a generic category.

### Use sense
- If you develop redness, itching, a rash, or any skin reaction, **stop wearing the
  device** and remove it. Keep the device and skin clean and dry. Loosen the strap if you
  see irritation from pressure or trapped moisture.

---

## 3. General

- Keep the device away from children and pets (small parts, battery).
- Don't modify the charging or protection circuitry unless you understand exactly what
  you're doing.
- When in doubt, **don't** — open an issue or ask in the community chat first.

---

*This is builder-facing safety guidance, not legal or safety certification. You are
responsible for sourcing certified parts and complying with the rules in your
jurisdiction.*
