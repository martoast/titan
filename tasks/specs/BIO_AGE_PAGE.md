# BIO AGE — the full, shareable, transparent Titan Age page

**Status:** BUILD NOW · **Requested by:** Alex, 2026-07-13
**One line:** Turn the Biological Age tile into a dedicated, screenshot-worthy page — tap it on Today
and land on a full page that shows your Titan Age, **exactly how every marker was combined to get
there**, and personalized coach tips to lower it. This is the single most-screenshotted surface in
Whoop; it deserves the Jobs/Chesky treatment and the highest craft in the app.

**Grounded in what exists:** `LongevityIndex::assess` already returns `titan_age`,
`chronological_age`, `delta`, `band`, `pace`, `confidence`, `partial`, `missing[]`, `components[]`
(each marker's contribution in **years**), and `younger_levers`/`older_levers`. So the data is ~80%
there — this is a presentation + transparency + tips build, not new math (with small server enrichment).

**Real data to design against (Alex, live):**
- Titan Age **22.6** vs chronological **30.2** → **−7.6y**, confidence **medium**, partial (no bloodwork)
- Components: HRV −3.0y · Resting HR −2.2y · VO₂max −1.0y · Sleep regularity −1.5y · Daily activity 0y
- Younger levers: HRV, Resting HR · Older levers: none · Missing: 9 bloodwork markers

---

## The page, top to bottom

### 1. The hero (must be beautiful + shareable)
- **Titan Age as the centerpiece** — big number, with chronological age and the delta framed as the
  headline ("**7.6 years younger** than your age"). Band color + the **pace-of-aging** trend arrow.
- A **confidence badge** shown honestly ("medium · estimated from fitness — add bloodwork to sharpen").
- A **Share button** — screenshots are the entire point; make a clean shareable card (age + delta +
  the Titan mark). This is what spreads the app.

### 2. "How we got your age" — the transparency breakdown (the core of the ask)
A **contribution waterfall**: start at chronological age (30.2), then each marker pushes the number
up or down to the Titan Age, as a labeled +/- bar:
```
Chronological 30.2
  HRV (RMSSD)        −3.0y  ▇▇▇
  Resting HR         −2.2y  ▇▇
  Sleep regularity   −1.5y  ▇
  VO₂max             −1.0y  ▇
  Daily activity      0.0y
= Titan Age 22.6
```
- **Every row is tappable → expands** to: the **underlying value** ("HRV 65 ms — better than ~80% of
  people your age"), and a **plain-language "how this maps to years"** (the methodology for THAT
  marker). This is the "see all the markers and how it was computed" Alex asked for — full
  transparency, no black box.
- Server enrichment: add to each `component` a `value` (+unit), an optional `percentile`/`context`
  string, and a one-line `how` (methodology). Keep `years` as the contribution.

### 3. Your youth drivers vs what's aging you
- **`younger_levers`** as "what's keeping you young" (HRV, Resting HR) and **`older_levers`** as
  "what's adding years" — each with its year cost/credit. If `older_levers` is empty, celebrate it
  ("nothing is aging you faster than your years — rare, keep it up").

### 4. Sharpen it (honesty + drives bloodwork)
- When `partial`/confidence < high: an honest panel — "Your Titan Age is estimated from your fitness
  data. Add a blood panel (**9 markers**: Albumin, Creatinine, Fasting Glucose, hs-CRP…) and it
  sharpens to a full clinical-grade age." CTA → the biopanel / scan-a-labs flow. This is the honesty
  moat *and* a growth loop into bloodwork.

### 5. Coach tips — "How to get younger" (the second half of the ask)
A personalized, **data-driven** tips section built from the components + levers, voiced by the coach
and calibrated by the longevity knowledge pack (`LongevityKnowledge`):
- For each meaningful lever, one specific, honest action tied to THEIR data:
  - youth driver → "protect it" ("Your HRV is your biggest youth driver — the fastest way to lose it
    is inconsistent sleep; hold your bedtime.")
  - older lever → "improve it" ("Your resting HR adds ~Xy — Zone-2 cardio 2–3×/wk is the highest-
    leverage fix.")
- **Honest framing (hard rules, same as the longevity guardrails):** these are *healthspan* levers,
  not "reverse aging"; never promise years back; wellness estimate, not medical. Reuse
  `LongevityKnowledge::coachGuardrails`. Offer a "Ask the coach how to improve this" action → opens
  chat pre-seeded, so it flows into the real coach (which already has the honest knowledge pack).

### 6. Methodology footer (plain-language "how Titan Age works")
A short, honest explainer: Titan Age = chronological age adjusted by validated markers — **cardio
fitness (VO₂max), resting HR, HRV, sleep, activity**, and **when available, bloodwork via a
PhenoAge-style model (Levine)**. One line on the science + the honest scope: "a wellness estimate
from your data, not a medical diagnosis; fasting-glucose/CRP must be a clean draw." Links to the
longevity research doc concepts.

---

## Navigation
The **Today/dashboard Biological Age tile** becomes a tap target → pushes `BioAgePage`
(`titanDetail` chrome, like the sleep/recovery detail screens). Keep the tile as the glanceable
summary; the page is the deep dive.

## Design bar (this is THE screenshot page — highest craft)
- The hero is a thesis: the age + delta must read instantly and look share-worthy. Consider a subtle
  ring or an age dial; make the delta the emotional hit.
- The waterfall is the star of the transparency section — a real contribution visual (Canvas/bars),
  not a table. Each marker in its own accent, deltas labeled, tabular numerals.
- Honesty is visible, not hidden: the "medium confidence / partial" state looks like an honest
  estimate with a clear path to sharpen, never a fake-precise number.
- Reuse the design system (rings, `TrendMetricChart`, `GlassCard`, the coach `lesson`/tips infra,
  `PillSwitch`). Theme-aware, reduced-motion honored.

## Server enrichment (small)
- Enrich `LongevityIndex` `components[]` with `value`(+unit), `context`/`percentile`, and a one-line
  `how` per marker (the methodology). Add a `tips[]` (data-driven, from levers + knowledge pack) and
  a `methodology` blurb. A `bioage_page` payload (or extend the existing bioage tool/card) so the app
  + coach share one source.

## Acceptance
- [ ] Tapping the Today Biological Age tile opens a dedicated page.
- [ ] The page shows the hero (Titan Age vs chrono, delta, pace, confidence) with a working Share.
- [ ] "How we got your age" waterfall lists every component's year contribution; each row expands to
      its value + plain methodology.
- [ ] Youth-drivers/older-levers section; the "add bloodwork to sharpen" honest CTA when partial.
- [ ] A personalized, honest coach-tips section (calibrated by `LongevityKnowledge` — no "reverse
      aging"), with an "ask the coach" hand-off.
- [ ] Methodology footer; wellness-not-medical framing throughout.
- [ ] Field check on Alex's real data: the waterfall sums chronological + components → Titan Age
      (30.2 − 7.6 = 22.6), the missing-bloodwork list is right, and the tips reference his actual
      levers (HRV, resting HR). (Henry verifies.)
