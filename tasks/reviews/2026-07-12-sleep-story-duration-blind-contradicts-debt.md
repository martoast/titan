# Review — "Story of your night" is duration-blind and contradicts the debt ledger

**Date:** 2026-07-12 · **Reviewer:** Henry (field-test on Alex, profile 1, real nights)
**Verdict on fefbd4a (SleepStory):** ✅ Reads beautifully, honesty framing + mechanism-teaching are
there, awakenings/onset are accurate. ❌ But it judges only sleep *architecture* and ignores
*sufficiency*, so on a short-sleep week it praises every night while the debt ledger + week view
correctly call the same week run-down. One product, three surfaces — this one is out of sync.

## What I saw (5 real consecutive nights)

| date | dur | deep | rem | rem_cycles | story takeaway |
|------|-----|------|-----|-----------|----------------|
| 07-12 | 333m (5.6h) | 77 | 71 | 5 | "textbook night — keep the rhythm" |
| 07-11 | 269m (4.5h) | **27** | 61 | **6** | "textbook night — keep the rhythm" |
| 07-10 | 317m (5.3h) | 100 | **4** | 1 | "Solid, well-structured night" |
| 07-09 | 272m (4.5h) | 72 | 57 | 5 | "textbook night — keep the rhythm" |
| 07-08 | 352m (5.9h) | 62 | 100 | 6 | "textbook night — keep the rhythm" |

Every night is **4.5–5.9h — all under Alex's ~8h need**, and these are the exact nights that built
the **9.5h "heavy" debt** the ledger reports and the **"Building/Run down" week**. Yet the story
celebrates all five. A user reading "textbook, keep the rhythm" five nights running, while his debt
card screams heavy, learns to trust neither.

## Findings

### 1. Duration-blind — the story never mentions the night was SHORT (the big one)
`SleepStory` weighs onset + deep distribution + REM cycles but not **duration vs need**. A
beautifully-architected 4.5h night is still a 4.5h night. The takeaway must factor sufficiency:
"great *structure*, but only 4.5h — well short of your ~8h need, which is why your debt's climbing."
Pull `need_h`/`debt_h` (already on the detail payload, already in SleepCoach) into the takeaway so
the story agrees with the debt ledger + Sleep Week instead of contradicting them. **This is the
honesty-moat consistency rule applied across surfaces.**

### 2. Takeaway ignores stage ADEQUACY, only distribution
"front-loaded deep … textbook" fires when the deep *amount* is low (07-11: **27 min** deep) and
"solid, well-structured" fires on a **4 min REM** night (07-10 — near-zero REM is a real gap, not
"solid"). Key the takeaway on whether deep/REM *minutes* are adequate (deep ~13–23%, REM ~20–25% of
sleep), not just where they sat. A night can be front-loaded AND deep-deficient.

### 3. REM-cycle count looks inflated
6 REM cycles in a 4.5h night (07-11) is physiologically implausible — true ultradian cycles run
~90 min, so ~3 in 4.5h. The counter is almost certainly counting every REM *run* (incl. fragmented
micro-REM) as a "cycle," which then feeds a falsely glowing "full REM cycles — textbook" read.
Count cycles as ~90-min NREM→REM completions (or rename it "REM periods" and stop treating a high
count as automatically good).

### 4. Repetition (polish)
The identical "textbook night — keep the rhythm" on 4 of 5 nights reads canned and kills the
"specific, human" goal. Once (1)–(3) land the takeaways will naturally diverge, but also vary the
phrasing and lead with what's actually *distinct* about each night.

## The fix in one line
Make the takeaway a function of **sufficiency (duration vs need) AND stage adequacy**, not just
architecture — then a short night reads as "good structure, too short," matching the debt ledger.
The story should be able to say a night was *well-built but insufficient*; right now it can't.

## Repro
`SleepStory::forNight($log)` on profile 1's 07-08…07-12 nights → all celebrate; none mention the
sub-need duration that's driving the heavy debt.
