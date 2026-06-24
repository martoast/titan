<?php

namespace App\Support;

use App\Models\Profile;
use Illuminate\Support\Carbon;

/**
 * Hydration: a bodyweight-based daily target (≈35 ml/kg) and today's running total. Simple, but a
 * real adherence + metabolic lever (thirst is often mistaken for hunger).
 */
class Hydration
{
    private const ML_PER_KG = 35;
    private const DEFAULT_TARGET_ML = 2500;

    public static function targetMl(Profile $profile): int
    {
        $kg = rescue(fn () => MacroTargets::currentWeightKg($profile), null, false);

        return $kg ? (int) round($kg * self::ML_PER_KG / 50) * 50 : self::DEFAULT_TARGET_ML;
    }

    public static function today(Profile $profile): array
    {
        $tz = $profile->settings['timezone'] ?? config('app.timezone', 'UTC');
        $date = Carbon::now($tz)->toDateString();
        $total = (int) $profile->hydrationLogs()->whereDate('logged_on', $date)->sum('amount_ml');
        $target = self::targetMl($profile);

        return [
            'type' => 'hydration',
            'total_ml' => $total,
            'target_ml' => $target,
            'pct' => $target > 0 ? min(100, (int) round($total / $target * 100)) : 0,
            'date' => $date,
        ];
    }

    public static function add(Profile $profile, int $ml): array
    {
        $tz = $profile->settings['timezone'] ?? config('app.timezone', 'UTC');
        $profile->hydrationLogs()->create([
            'logged_on' => Carbon::now($tz)->toDateString(),
            'amount_ml' => max(0, $ml),
            'source' => 'app',
        ]);

        return self::today($profile);
    }
}
