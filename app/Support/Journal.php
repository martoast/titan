<?php

namespace App\Support;

use App\Models\BehaviorLog;
use App\Models\Profile;
use Illuminate\Support\Carbon;

/**
 * The behavior journal: a curated catalog of the lifestyle factors that most move recovery, sleep
 * and weight, plus helpers to log them per-day. This is the input layer for BehaviorCorrelations —
 * log it here, learn "what helps/hurts YOU" there. Catalog keys are stable (used in stored rows).
 */
class Journal
{
    /** key => [label, polarity(good|bad|neutral), category] */
    public const CATALOG = [
        // Typically harmful to recovery/sleep
        'alcohol' => ['Alcohol', 'bad', 'substances'],
        'caffeine_late' => ['Caffeine (afternoon/evening)', 'bad', 'substances'],
        'nicotine' => ['Nicotine', 'bad', 'substances'],
        'late_meal' => ['Late meal', 'bad', 'nutrition'],
        'large_dinner' => ['Large dinner', 'bad', 'nutrition'],
        'ate_out' => ['Ate out / takeout', 'bad', 'nutrition'],
        'sugary' => ['Sugary / processed food', 'bad', 'nutrition'],
        'screens_late' => ['Screens in bed', 'bad', 'sleep'],
        'high_stress' => ['Stressful day', 'bad', 'mind'],
        'anxious' => ['Felt anxious', 'bad', 'mind'],
        'travel' => ['Travel', 'bad', 'context'],
        'sick' => ['Feeling sick', 'bad', 'context'],
        'noisy_room' => ['Noisy / bright room', 'bad', 'sleep'],
        // Typically helpful
        'meditated' => ['Meditated', 'good', 'mind'],
        'sunlight_am' => ['Morning sunlight', 'good', 'circadian'],
        'walk_after_meal' => ['Walked after meals', 'good', 'movement'],
        'hydrated' => ['Well hydrated', 'good', 'nutrition'],
        'sauna' => ['Sauna / heat', 'good', 'recovery'],
        'cold_plunge' => ['Cold exposure', 'good', 'recovery'],
        'read_before_bed' => ['Read before bed', 'good', 'sleep'],
        'magnesium' => ['Magnesium', 'good', 'supplements'],
        'early_dinner' => ['Early dinner', 'good', 'nutrition'],
        'social' => ['Social time', 'good', 'mind'],
        'stretched' => ['Stretched / mobility', 'good', 'movement'],
        'outdoors' => ['Time outdoors', 'good', 'context'],
        // Neutral / contextual
        'nap' => ['Napped', 'neutral', 'sleep'],
        'fasted' => ['Fasted', 'neutral', 'nutrition'],
    ];

    /** Valid keys only, de-duped. @param array<int,string> $keys @return array<int,string> */
    public static function clean(array $keys): array
    {
        return array_values(array_unique(array_filter($keys, fn ($k) => isset(self::CATALOG[$k]))));
    }

    public static function label(string $key): string
    {
        return self::CATALOG[$key][0] ?? $key;
    }

    /** The user's local "today" as a date string. */
    public static function today(Profile $profile): string
    {
        $tz = $profile->settings['timezone'] ?? config('app.timezone', 'UTC');

        return Carbon::now($tz)->toDateString();
    }

    /** Behavior keys logged on a given day. @return array<int,string> */
    public static function forDate(Profile $profile, string $date): array
    {
        return $profile->behaviorLogs()->whereDate('logged_on', $date)->pluck('key')->all();
    }

    /**
     * Add and/or remove behaviors for a day. Idempotent.
     *
     * @param  array<int,string>  $add
     * @param  array<int,string>  $remove
     * @return array<int,string>  the keys logged for that day afterwards
     */
    public static function log(Profile $profile, string $date, array $add, array $remove = []): array
    {
        foreach (self::clean($add) as $key) {
            $profile->behaviorLogs()->updateOrCreate(
                ['logged_on' => $date, 'key' => $key],
                ['source' => 'coach'],
            );
        }
        if ($remove !== []) {
            $profile->behaviorLogs()->whereDate('logged_on', $date)
                ->whereIn('key', self::clean($remove))->delete();
        }

        return self::forDate($profile, $date);
    }
}
