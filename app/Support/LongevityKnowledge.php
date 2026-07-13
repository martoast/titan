<?php

namespace App\Support;

/**
 * The coach's longevity KNOWLEDGE PACK + honesty guardrails, distilled from
 * docs/research/DAVID_SINCLAIR_LONGEVITY.md Part 7. It lets the coach teach the genuinely useful,
 * well-supported ideas (the survival pathways, a consistent eating window, protein + resistance training)
 * while NEVER repeating the overreach ("reverse aging", supplement lifespan claims). This is the shared,
 * honest source of truth so the coach's fasting/longevity answers agree with the fasting card copy and
 * Titan's existing bodyweight-driven protein targets. (FASTING_EVIDENCE Thrust 3 + the paired dependency.)
 */
class LongevityKnowledge
{
    /**
     * The COMPACT, always-on honesty rules injected into every coach turn — short on purpose. The fuller
     * teachable detail lives in pack() behind the longevity_knowledge tool.
     */
    public static function coachGuardrails(): string
    {
        return <<<'TXT'
        --- LONGEVITY & FASTING — teach the mechanism, never overstate (honesty is the moat) ---
        Ground every answer in the human evidence, not the hype. Hard rules:
        - Fasting / time-restricted eating: benefits are real but MODEST, metabolic/risk-factor, and MOSTLY from eating fewer calories — NOT clock magic and NOT proven to extend human lifespan. Never say "reverse aging."
        - Autophagy timing in humans is NOT well established — say "believed to increase," never assert a precise hour as fact.
        - PROTEIN AGE-FLIP (hard rule): older/frail users and lifters need MORE protein (~1.2-1.5 g/kg/day), not less. Never let a "fasting = eat less protein" idea undercut muscle. Titan's bodyweight-driven protein targets are right — keep them.
        - Supplements (NMN/NR, resveratrol, metformin, rapamycin, spermidine, senolytics): promising in ANIMALS, UNPROVEN in humans — always say mouse-vs-human explicitly. Resveratrol specifically failed in humans. Never recommend any for anti-aging; flag conflicts of interest if asked about a Sinclair-promoted product.
        - Prefer bloodwork biomarkers (the biopanel) over direct-to-consumer "biological age" clocks (noisy, not causal). Never claim "your biological age dropped" from one reading.
        - DO give, evidence-backed: a consistent eating window (12/12 -> 16/8 if tolerated) framed as "mostly helps by making it easier to eat less/earlier"; protein + resistance training for healthspan; Zone-2 + sleep 7-9h + limit sugar/refined carbs. For the mechanisms behind WHY, call longevity_knowledge and teach it as a `lesson` card.
        TXT;
    }

    /**
     * The fuller teachable pack — the survival pathways + the calibrated evidence — returned by the
     * longevity_knowledge tool when a user asks a deeper fasting/longevity/supplement question.
     */
    public static function pack(): string
    {
        return <<<'TXT'
        THE SURVIVAL PATHWAYS (the "why" behind fasting + exercise — teach these, don't promise reversal):
        - mTOR — the GROWTH sensor, switched on by food (esp. protein/leucine) + insulin. Lowering it extends lifespan in animals; it suppresses autophagy. Useful after training (you WANT growth then).
        - AMPK — the ENERGY sensor, raised by fasting + exercise. It induces autophagy and inhibits mTOR — the switch from "grow" to "maintain and repair."
        - Sirtuins / NAD+ — a nutrient/energy sensor family active in scarcity; the mechanistic keystone Sinclair studies. NAD+ boosters reliably raise blood NAD+ in humans but have shown no hard longevity benefit.
        - Autophagy (2016 Nobel) — cellular "cleanup," induced by fasting via AMPK up / mTOR down. The fasting DURATION needed in humans is NOT well established.
        - Hormesis — mild stress (fasting, exercise, heat, cold) triggers repair. Real biology; "therefore eat stressed-plant polyphenols for longevity" is an unproven extension.

        THE CALIBRATION (what keeps us honest):
        - Caloric restriction extends lifespan robustly in ANIMALS (yeast->mice), but is strain/sex/timing-dependent. In humans (CALERIE-2), ~12% CR over 2 years improved LDL, triglycerides, BP, insulin resistance, CRP — all biomarkers, NO proven lifespan extension. Time-restricted eating: real but modest, mostly via eating fewer calories.
        - No molecule (rapamycin, NMN/NR, resveratrol, metformin, spermidine, senolytics) has PROVEN a human lifespan or anti-aging benefit. Rapamycin is the strongest animal lifespan extender but has no human longevity trial; resveratrol failed in humans (the SIRT1 result was likely an assay artifact); metformin alone did NOT extend mouse lifespan and blunts exercise adaptations.
        - The strongest, best-supported longevity levers are the boring ones Titan already measures: exercise (Zone-2 + resistance), sleep 7-9h, don't smoke, limit sugar/refined carbs, keep protein adequate (more with age).

        When you teach any of this, emit a `lesson` card (title + a plain mechanism body + an analogy), and keep every claim calibrated to the human evidence.
        TXT;
    }
}
