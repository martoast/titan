<?php

namespace App\Support;

use App\Models\Profile;
use Carbon\CarbonImmutable;

/**
 * Evidence-based personalized daily step target.
 *
 * The dose-response is among the most robust in all of wearable epidemiology: each +1,000 steps/day
 * ≈ −15% all-cause mortality (Paluch et al., Lancet Public Health 2022, 15 cohorts n≈47k), and
 * 8,000 vs 4,000 steps/day → mortality HR 0.49 (Saint-Maurice et al., JAMA 2020, NHANES). Crucially
 * the benefit PLATEAUS -- ~6,000-8,000 for adults ≥60 and ~8,000-10,000 for under-60s (optimal
 * ~8,700, JACC 2023). The famous "10,000 steps" is a marketing number, not science -- so we set an
 * honest, age-personalized target at the plateau, not above it.
 */
class StepGoal
{
    /** Daily step target where most of the mortality benefit is reached, by age band. */
    public static function targetFor(?Profile $profile): int
    {
        $age = $profile?->birthdate
            ? CarbonImmutable::parse($profile->birthdate)->diffInYears(now())
            : null;

        if ($age === null) {
            return 8000;            // sensible default when age is unknown
        }

        return $age >= 60 ? 7000 : 8500;   // benefit plateaus earlier with age (Paluch 2022)
    }

    /**
     * @return array{pct:int,band:string,label:string,to_go:int,target:int}
     */
    public static function assess(int $steps, int $target): array
    {
        $pct = $target > 0 ? min(100, (int) round($steps / $target * 100)) : 0;
        [$band, $label] = match (true) {
            $steps >= $target => ['excellent', 'Goal reached'],
            $steps >= 7000 => ['good', 'Strong day'],
            $steps >= 4000 => ['fair', 'Building'],
            default => ['low', 'Get moving'],
        };

        return ['pct' => $pct, 'band' => $band, 'label' => $label, 'to_go' => max(0, $target - $steps), 'target' => $target];
    }
}
