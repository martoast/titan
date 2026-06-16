<?php

namespace App\Support;

/**
 * Movement-breaks / "don't sit too long" assessment from a day's hourly activity profile.
 *
 * The science is unusually strong and acutely actionable: interrupting prolonged sitting with a
 * 2-minute walk every 20–30 min cut postprandial glucose and insulin ~24–30% (Dunstan et al.,
 * Diabetes Care 2012) — a benefit that's about WHEN you move, not just how much. A worn device
 * knows the "when", so we surface how many waking hours had real movement, the longest unbroken
 * sit, and nudge toward breaking it up (the highest-leverage, lowest-effort anti-obesity lever).
 *
 * Activity units are arbitrary, so "active" is judged RELATIVE to the day's own busiest hour.
 */
class MovementBreaks
{
    private const WAKE_START = 7;   // 07:00
    private const WAKE_END = 23;    // up to 22:59 → 16 waking hours
    private const GOAL_ACTIVE_HOURS = 10;

    /**
     * @param  array<int,int|float>|null  $hourly  24 per-hour activity counts (midnight→midnight)
     * @return array{active:int,waking:int,longest_sit:int,goal:int,met:bool}|null
     */
    public static function assess(?array $hourly): ?array
    {
        if (! is_array($hourly) || count($hourly) !== 24) {
            return null;
        }
        $vals = array_map('floatval', array_values($hourly));
        $peak = max($vals);
        if ($peak <= 0) {
            return null;
        }
        // An hour counts as "active" if it reaches a small fraction of the busiest hour.
        $threshold = max(3.0, 0.12 * $peak);

        $active = 0;
        $longestSit = 0;
        $run = 0;
        for ($h = self::WAKE_START; $h < self::WAKE_END; $h++) {
            if ($vals[$h] >= $threshold) {
                $active++;
                $run = 0;
            } else {
                $run++;
                $longestSit = max($longestSit, $run);
            }
        }

        return [
            'active' => $active,
            'waking' => self::WAKE_END - self::WAKE_START,
            'longest_sit' => $longestSit,
            'goal' => self::GOAL_ACTIVE_HOURS,
            'met' => $active >= self::GOAL_ACTIVE_HOURS,
        ];
    }
}
