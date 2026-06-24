<?php

namespace App\Support;

use App\Models\Profile;

/**
 * The user's editable daily targets — macros (calories/protein/carbs/fat) and nightly sleep hours.
 * Stored in profile.settings. The moment a macro target is set explicitly we mark it 'custom', so
 * the user's number becomes authoritative (we stop auto-deriving protein from bodyweight and
 * carbs+fat from calories). Editable two ways — the app's Targets sheet and the coach's set_targets
 * tool — both call update(), so the macro rings and the protein/sleep nudges all stay in sync.
 */
class TargetSettings
{
    private const MACRO_KEYS = ['calories', 'protein_g', 'carbs_g', 'fat_g'];

    /**
     * Effective targets for display/use.
     *
     * @return array{calories:int,protein_g:int,carbs_g:int,fat_g:int,sleep_h:float,custom:bool}
     */
    public static function resolve(Profile $profile): array
    {
        $t = MealCoach::targets($profile);           // honours 'custom' for calories + protein
        $calories = (int) $t['calories'];
        $protein = (int) $t['protein_g'];

        $set = $profile->settings['macro_targets'] ?? [];
        $custom = ($set['source'] ?? null) === 'custom';

        $fat = ($custom && isset($set['fat_g'])) ? (int) $set['fat_g'] : (int) round($calories * 0.27 / 9);
        $carbs = ($custom && isset($set['carbs_g']))
            ? (int) $set['carbs_g']
            : (int) max(0, round(($calories - $protein * 4 - $fat * 9) / 4));

        return [
            'calories' => $calories,
            'protein_g' => $protein,
            'carbs_g' => $carbs,
            'fat_g' => $fat,
            'sleep_h' => round(SleepCoach::targetHours($profile), 1),
            'custom' => $custom,
        ];
    }

    /**
     * Apply a partial set of targets (only the keys provided change).
     *
     * @param  array<string,mixed>  $fields  any of calories, protein_g, carbs_g, fat_g, sleep_h
     * @return array the resolved targets after the change
     */
    public static function update(Profile $profile, array $fields): array
    {
        $current = self::resolve($profile);
        $settings = $profile->settings ?? [];

        $macroProvided = false;
        foreach (self::MACRO_KEYS as $k) {
            if (isset($fields[$k]) && is_numeric($fields[$k])) {
                $macroProvided = true;
            }
        }
        if ($macroProvided) {
            // Keep all four coherent — unspecified macros hold their current effective value.
            $settings['macro_targets'] = [
                'calories' => (int) round($fields['calories'] ?? $current['calories']),
                'protein_g' => (int) round($fields['protein_g'] ?? $current['protein_g']),
                'carbs_g' => (int) round($fields['carbs_g'] ?? $current['carbs_g']),
                'fat_g' => (int) round($fields['fat_g'] ?? $current['fat_g']),
                'source' => 'custom',
            ];
        }
        if (isset($fields['sleep_h']) && is_numeric($fields['sleep_h'])) {
            $settings['sleep_target_h'] = round((float) $fields['sleep_h'], 1);
        }

        $profile->update(['settings' => $settings]);

        return self::resolve($profile->fresh());
    }

    /** Drop the custom overrides → back to smart auto targets (protein from bodyweight, age-based sleep). */
    public static function reset(Profile $profile): array
    {
        $settings = $profile->settings ?? [];
        unset($settings['macro_targets'], $settings['sleep_target_h']);
        $profile->update(['settings' => $settings]);

        return self::resolve($profile->fresh());
    }
}
