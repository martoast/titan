<?php

namespace App\Support;

/**
 * Activity priming: maps a spoken activity ("run", "bike ride", "swim") to a canonical
 * type and the sensing profile the Titan band should switch to for it — sample rates and
 * whether GPS is worth powering on. The coach writes the active activity onto the profile
 * when the user says they're starting; the band reads it (GET /api/devices/activity) on its
 * next connection and adapts its sampling, instead of running one generic mode all day.
 *
 * Rates are conservative on purpose — this is a battery-constrained DIY wearable, so we only
 * spend power (faster HR, GPS) where the activity actually needs it.
 */
class ActivityPriming
{
    /**
     * Canonical type => sensing profile.
     * hr_hz: heart-rate samples/sec · accel_hz: accelerometer samples/sec · gps: power the GPS.
     *
     * @var array<string,array{label:string,hr_hz:float,accel_hz:float,gps:bool}>
     */
    private const PROFILES = [
        'run' => ['label' => 'Run', 'hr_hz' => 1.0, 'accel_hz' => 12.5, 'gps' => true],
        'walk' => ['label' => 'Walk', 'hr_hz' => 0.5, 'accel_hz' => 12.5, 'gps' => true],
        'hike' => ['label' => 'Hike', 'hr_hz' => 0.5, 'accel_hz' => 12.5, 'gps' => true],
        'cycle' => ['label' => 'Cycle', 'hr_hz' => 1.0, 'accel_hz' => 6.25, 'gps' => true],
        'swim' => ['label' => 'Swim', 'hr_hz' => 1.0, 'accel_hz' => 12.5, 'gps' => false],
        'row' => ['label' => 'Row', 'hr_hz' => 1.0, 'accel_hz' => 12.5, 'gps' => false],
        'hiit' => ['label' => 'HIIT', 'hr_hz' => 1.0, 'accel_hz' => 25.0, 'gps' => false],
        'strength' => ['label' => 'Strength', 'hr_hz' => 1.0, 'accel_hz' => 12.5, 'gps' => false],
        'yoga' => ['label' => 'Yoga / mobility', 'hr_hz' => 0.5, 'accel_hz' => 6.25, 'gps' => false],
        'other' => ['label' => 'Workout', 'hr_hz' => 1.0, 'accel_hz' => 12.5, 'gps' => false],
    ];

    /** Spoken word/synonym => canonical type. */
    private const ALIASES = [
        'jog' => 'run', 'jogging' => 'run', 'running' => 'run', 'sprint' => 'run', 'sprints' => 'run', 'treadmill' => 'run',
        'walking' => 'walk', 'stroll' => 'walk', 'rucking' => 'hike', 'ruck' => 'hike', 'hiking' => 'hike', 'trek' => 'hike',
        'bike' => 'cycle', 'biking' => 'cycle', 'cycling' => 'cycle', 'ride' => 'cycle', 'spin' => 'cycle', 'spinning' => 'cycle', 'peloton' => 'cycle',
        'swimming' => 'swim', 'laps' => 'swim',
        'rowing' => 'row', 'erg' => 'row', 'ergometer' => 'row',
        'interval' => 'hiit', 'intervals' => 'hiit', 'circuit' => 'hiit', 'crossfit' => 'hiit', 'metcon' => 'hiit',
        'lift' => 'strength', 'lifting' => 'strength', 'weights' => 'strength', 'gym' => 'strength', 'resistance' => 'strength',
        'pilates' => 'yoga', 'mobility' => 'yoga', 'stretch' => 'yoga', 'stretching' => 'yoga',
    ];

    public static function normalize(string $raw): string
    {
        $key = strtolower(trim($raw));
        if (isset(self::PROFILES[$key])) {
            return $key;
        }
        if (isset(self::ALIASES[$key])) {
            return self::ALIASES[$key];
        }
        // Fall back to a contained keyword (e.g. "morning trail run").
        foreach (self::ALIASES as $word => $canon) {
            if (str_contains($key, $word)) {
                return $canon;
            }
        }
        foreach (array_keys(self::PROFILES) as $canon) {
            if ($canon !== 'other' && str_contains($key, $canon)) {
                return $canon;
            }
        }

        return 'other';
    }

    /** @return array{label:string,hr_hz:float,accel_hz:float,gps:bool} */
    public static function profile(string $type): array
    {
        return self::PROFILES[self::normalize($type)] ?? self::PROFILES['other'];
    }
}
