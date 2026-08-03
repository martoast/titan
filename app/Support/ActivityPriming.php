<?php

namespace App\Support;

/**
 * Activity priming: maps a spoken activity ("run", "bike ride", "swim") to a canonical
 * type and the sensing profile the Titan band should switch to for it -- sample rates and
 * whether GPS is worth powering on. The coach writes the active activity onto the profile
 * when the user says they're starting; the band reads it (GET /api/devices/activity) on its
 * next connection and adapts its sampling, instead of running one generic mode all day.
 *
 * Rates are conservative on purpose -- this is a battery-constrained DIY wearable, so we only
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
        // A gym spin bike is a DIFFERENT activity from a road ride, to the sensor and to the battery.
        // The firmware picks SPORT_TYPE_SPINNING (algo.h 0x12) over RIDE_BIKE (0x02) for it, and uses
        // `gps` as the discriminator — so priming a spin session with gps:true would silently request
        // the road model AND power the receiver indoors for a fix it will never get. Same low accel
        // rate as cycling: the wrist is static on the bars either way.
        'spin' => ['label' => 'Spin bike', 'hr_hz' => 1.0, 'accel_hz' => 6.25, 'gps' => false],
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
        // ORDER MATTERS HERE. normalize() falls back to a str_contains scan in insertion order, and
        // every one of these phrases CONTAINS "bike" or "cycling" — so the specific indoor forms must
        // be listed BEFORE the generic outdoor ones or "spin bike" resolves to road cycling.
        // These used to alias to 'cycle', which meant the words that most clearly mean "spin bike"
        // were the ones that guaranteed the ROAD model; the firmware's SPINNING branch was
        // unreachable from the coach entirely.
        'spin bike' => 'spin', 'stationary bike' => 'spin', 'exercise bike' => 'spin',
        'indoor bike' => 'spin', 'indoor cycling' => 'spin', 'assault bike' => 'spin',
        'spinning' => 'spin', 'peloton' => 'spin', 'stationary' => 'spin',
        // Outdoor riding. A bare "bike" stays ROAD: it is the general meaning, and the wrong guess is
        // asymmetric — a road model on a spin bike costs some HR accuracy, while spin on a real ride
        // costs the route entirely. Say "spin bike" (or prime it in the app) for the gym.
        'bike' => 'cycle', 'biking' => 'cycle', 'cycling' => 'cycle', 'ride' => 'cycle',
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
