<?php

namespace App\Support;

use App\Models\Profile;

/**
 * Evidence-based daily macro targets -- the single source of truth for protein, so the meal card,
 * the coach's advice and the onboarding seed all agree.
 *
 * PROTEIN is bodyweight-driven (not a frozen number). For resistance-trained adults building muscle,
 * the dose-response plateaus around 1.6 g/kg/day and the defensible upper bound is ~2.2 g/kg/day --
 * i.e. about 1 GRAM PER POUND of bodyweight (Morton et al. 2018, Br J Sports Med, n=1,863). In a
 * caloric deficit, higher intakes (2.3-3.1 g/kg) better preserve lean mass (Helms et al.; ISSN
 * position stand, Jäger et al. 2017). So we target the high end for muscle/recomp goals (1 g/lb) and
 * go a touch higher when cutting. This is intentionally at the upper, muscle-building end -- never the
 * timid RDA -- because under-shooting protein is the most common way people stall on a build.
 *
 * @see https://www.ncbi.nlm.nih.gov/pmc/articles/PMC5477153/  ISSN position stand
 */
class MacroTargets
{
    private const G_PER_LB = 2.2046226218; // 1 g/lb == 2.2 g/kg -- the muscle-building anchor

    /** Protein grams per kg of bodyweight, by goal. The muscle-building goals sit at ~1 g/lb. */
    public static function proteinPerKg(?string $goal): float
    {
        $g = strtolower(trim((string) $goal));

        return match (true) {
            str_contains($g, 'muscle') => self::G_PER_LB,                 // build muscle → 1 g/lb
            str_contains($g, 'recomp') => self::G_PER_LB,                 // recomposition → 1 g/lb
            str_contains($g, 'fat') => 2.4,                              // fat loss (deficit) → spare muscle
            str_contains($g, 'lean') => self::G_PER_LB,                  // "lean + strong" recomp wording
            str_contains($g, 'performance') => 2.0,
            str_contains($g, 'longevity') => 1.8,
            default => 1.8,                                              // general health -- still well above RDA
        };
    }

    /**
     * The protein target in grams from the profile's CURRENT bodyweight + goal. Returns null only
     * when we have no bodyweight to work from (caller keeps its fallback). Always recomputed, so it
     * self-corrects as the user's weight is logged -- never a stale onboarding value.
     */
    public static function proteinTarget(Profile $profile): ?int
    {
        $kg = self::currentWeightKg($profile);
        if ($kg === null || $kg <= 0) {
            return null;
        }

        return (int) round($kg * self::proteinPerKg($profile->primary_goal));
    }

    /** Latest logged bodyweight (kg), or null if none on file. */
    public static function currentWeightKg(Profile $profile): ?float
    {
        if (! method_exists($profile, 'bodyMetrics')) {
            return null;
        }
        $bm = $profile->bodyMetrics()->whereNotNull('weight_kg')->latest('taken_at')->first();

        return $bm && $bm->weight_kg ? (float) $bm->weight_kg : null;
    }
}
