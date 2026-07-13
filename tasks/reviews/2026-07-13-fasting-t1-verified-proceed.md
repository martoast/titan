# Review — Fasting v2 Thrust 1 VERIFIED · proceed to the next thrusts

**Date:** 2026-07-13 · **Reviewer:** Henry (field-tested on live deploy)
**On:** 282ddcf (FASTING_EVIDENCE Thrust 1 — evidence-grounded, pathway-teaching stages)
**Verdict:** ✅ **Approved — no changes needed. Continue with Thrusts 2–5.**

## Verified on real data
Ran `Fasting::stageFor` across the timeline (2/8/14/18/36h). Every stage carries the survival
pathway + a plain-language *why*, with honest confidence tags exactly as the spec (and the research
doc) demand:
- 2h Fed → "mTOR active" · teaches protein switches on mTOR, "great after training; holds back cleanup"
- 14h Fat-burning → "AMPK↑, mTOR↓" · tagged *"this part is well established"*
- 18h Ketosis → "sirtuin/NAD⁺ context" · honesty: *"ketone levels vary by person and diet"*
- 36h Autophagy → "AMPK↑/mTOR↓ → ULK1 … triggers autophagy **in the lab**" · honesty: *"the time
  needed to raise autophagy in HUMANS isn't well established — 36h is a rough marker, not a proven
  threshold"*

No "reverse aging" anywhere; each claim is calibrated to the human evidence. This is the honesty moat
landing correctly. Tests (FastingStagesTest) added. iOS carries the new fields.

## Proceed — next thrusts (from FASTING_EVIDENCE.md)
Build these next, in order:
1. **Thrust 2 — Eating-window mode:** recurring window (12/12 → 16/8 → OMAD), **integrated with meal
   logging** (show the window; note meals logged outside it), + a **window-adherence streak** (reuse
   the streak infra). Frame honestly ("mostly helps by making it easier to eat less / eat earlier").
2. **Thrust 3 — Education layer + coach:** "what's happening now" → a `lesson` card pulling from the
   longevity knowledge pack; coach answers fasting questions with the calibrated (non-hype) framing.
3. **Thrust 4 — Protein guardrail (hard rule):** when a fasting window squeezes the user's protein
   target (older users/lifters need MORE, ~1.2–1.5 g/kg/day — see the research doc), the coach flags
   it. Fasting must not undercut muscle.
4. **Thrust 5 — History + week view + enriched card.**

Also (paired dependency): stand up the **coach longevity knowledge pack** from
`docs/research/DAVID_SINCLAIR_LONGEVITY.md` Part 7, so the fasting copy and the coach share one honest
source of truth.

Henry will field-test each thrust on Alex's real account as it lands (start a real fast; verify window
adherence + the protein-guardrail flag + the coach's honesty).
