# PRIORITY NOW — build in this order (from Alex, 2026-07-12)

After finishing the Stress Monitor (incl. the 0-3 vs 0-100 scale fix, review b0e9bed), build these
two next, IN ORDER. Both are already fully specced.

## ① FIRST — Low-coverage-night honesty fix (TRUST-CRITICAL)
**Spec:** `tasks/reviews/2026-07-12-low-coverage-night-truncated-misleading.md`

Real users are hitting this NOW. Tester B's first night (band on all night, but poor PPG contact → ~60%
NODATA) sealed as a confident **"4h 11m, 1:00–3:40"** when she actually slept ~8h — because the seal
truncated the night to its cleanest cluster and stated a hard duration as fact. Nothing loses a new
user's trust faster.

**Two fixes (see the review for detail):**
1. **Coverage-confidence gate** — a night with coverage `< ~0.5` must NOT present a confident duration.
   Flag it (`low_confidence` / a `partial` status) so the app + coach caveat it: *"We could only confirm
   ~4h — sensor contact was low, check your band fit."* Extend the d85f377 flag to COVERAGE + SPAN, not
   just stage plausibility.
2. **Don't truncate the span** — when clusters separated by NODATA gaps clearly belong to one night, span
   bed→wake across the WHOLE night with the gaps as honest NODATA holes; don't seal one fragment as the
   entire night.
3. Auto coach fit-check nudge on a low-coverage night (fit is the #1 cause and it's user-fixable).

**Acceptance:** re-seal Tester B's night #62 (profile 6) — it must NOT show a confident 4h11m; it must span her
real ~23:08→10:00 window (holes shown) and/or be flagged low-confidence with a fit-check nudge. This is the
regression fixture. Honesty over a fabricated confident number — the whole app's ethos.

## ② THEN — Longevity Index (marquee Whoop-parity feature)
**Spec:** `tasks/specs/LONGEVITY_INDEX_AND_SLEEP_PLANNER.md` (Part A)

Whoop's "WHOOP Age / pace of aging" as one score. Every ingredient already exists (BiologicalAge/PhenoAge,
AthleteScore, MetabolicHealth, VO₂max, ChairStand) — this is synthesis, not new sensing. Fuse them into a
**Titan Age + pace-of-aging** with a contributor breakdown, honest partial-data degradation, a
`longevity_status` coach tool + trajectory line, and a first-class native card (the coach v2 widget system
is live — use it). Store history so pace is a real trend. (Part B, the Sleep Planner, can follow.)

**Acceptance:** produces a Titan Age + pace with a contributor breakdown on real data; degrades honestly
with partial inputs (labeled, never over-confident); renders as a real card + `longevity_status` tool.

---
Sequence is deliberate: ship the trust fix before adding features — new users (Tester C, Tester B) are onboarding
and the low-signal night is the first thing that'll bite them. Then the Longevity Index is the biggest
remaining new-feature win.
