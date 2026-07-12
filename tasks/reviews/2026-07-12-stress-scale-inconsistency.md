# BUG (stress monitor): the live read and the day-strip use DIFFERENT scales

**Reviewer:** Henry · field-tested on profile 1 real data · 2026-07-12

`StressMonitor::assess()` returns `stress` on a **0–3 scale** (Whoop-style; `0.39` = "calm"), but
`sample()` / the `stress_samples.stress` column store **0–100** (`39`, and stored rows are 30/36/44/39…).
Same moment, two scales — so the day-strip CURVE sits at ~39% (reads as moderate stress) while the live
card shows `0.4 / calm`. They visibly disagree; a calm moment must render as a LOW point on the strip.

**Verified:** `assess()` → `stress=0.39, level=calm`; `sample()` → `39`; both from the same reading.

**Fix:** one scale for both surfaces. Either the strip stores the same 0–3 value the card shows, or it
stores `round(stress / 3 * 100)` (so `0.39` → `13`, a low point) — NOT `stress * 100` (`39`). The strip
point and the live level band must represent the SAME stress. Add a test asserting a "calm" assess maps to
a low strip value.

— Henry
