# Review — Fasting T4 protein guardrail VERIFIED (one copy fix) · proceed to T3/T5

**Date:** 2026-07-13 · **Reviewer:** Henry (field-test on Alex, real protein data)
**On:** 23202db (FASTING_EVIDENCE Thrust 4 — protein guardrail)
**Verdict:** ✅ **Logic correct & verified.** One minor copy glitch to fix; then proceed.

## Verified on real data
- Achievable rate ~20 g/h (palm every ~1.5h): 1h→20g, 4h→80g, 8h→160g. Sensible & sustainable.
- Narrow/closed window with ~138g protein still needed → **flags**, with the exact research framing
  (protein protects muscle; older adults/lifters need MORE ~1.2–1.5 g/kg; front-load or widen the
  window, don't fast harder). This is the hard rule from DAVID_SINCLAIR_LONGEVITY.md landing right.
- Wide 12h window → no flag. Correct.

## Fix — one copy glitch
When `window_hours_left == 0`, the message reads:
> "…but have **no of** eating window left — that's a lot to fit."
Dangling "of". Fix the template so the 0-hours case reads cleanly (e.g. "…but your eating window is
already closed for today — …" or "…but have no eating window left today — …").

## Consideration (tunable, not a blocker)
At ~20 g/h, an 8h (16:8) window caps at ~160g achievable. Alex's target is 176g, so the guardrail may
flag 16:8 at the *start* of the day for high-protein users. That's arguably correct (176g in 8h IS
tight — front-load), but watch that it doesn't fire so eagerly it becomes noise; consider a small
margin (flag only when remaining exceeds achievable by, say, >15–20g) so it nudges on genuinely tight
days, not every 16:8 morning.

## Proceed
- **Thrust 3 — Education layer + coach** ("what's happening now" → `lesson` card; honest fasting Q&A).
- **Thrust 5 — History/week view + enriched card.**
- The paired **longevity knowledge pack** (DAVID_SINCLAIR_LONGEVITY.md Part 7).

Henry will field-test each on Alex's real account.
