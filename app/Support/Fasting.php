<?php

namespace App\Support;

use App\Models\Fast;
use App\Models\Profile;
use Illuminate\Support\Carbon;

/**
 * Intermittent-fasting timer + the metabolic "body status" stage timeline (educational/estimated).
 * Start/stop are editable; the stage table is keyed on elapsed hours.
 */
class Fasting
{
    /** [minHours, label, blurb] — the body's estimated state through a fast. */
    public const STAGES = [
        [0, 'Fed', 'Digesting and absorbing your last meal.'],
        [4, 'Glycogen', 'Blood sugar settling; tapping liver glycogen for fuel.'],
        [12, 'Fat-burning', 'Glycogen low — shifting to burning fat for energy.'],
        [16, 'Ketosis onset', 'Ketones rising; appetite tends to ease.'],
        [24, 'Deep ketosis', 'Growth-hormone elevated; running largely on fat/ketones.'],
        [36, 'Autophagy', 'Cellular clean-up (autophagy) more active.'],
    ];

    public static function active(Profile $profile): ?Fast
    {
        return $profile->fasts()->whereNull('ended_at')->latest('id')->first();
    }

    public static function start(Profile $profile, ?float $goalHours = null): Fast
    {
        // close any dangling fast first (one active at a time)
        $profile->fasts()->whereNull('ended_at')->update(['ended_at' => now()]);

        return $profile->fasts()->create([
            'started_at' => now(),
            'goal_hours' => $goalHours && $goalHours > 0 ? round($goalHours, 1) : 16,
        ]);
    }

    public static function end(Profile $profile): ?Fast
    {
        $fast = self::active($profile);
        $fast?->update(['ended_at' => now()]);

        return $fast;
    }

    /** The `fasting` card for the active fast, or a "not fasting" payload. */
    public static function card(Profile $profile): array
    {
        $fast = self::active($profile);
        if (! $fast) {
            return ['type' => 'fasting', 'active' => false];
        }
        $elapsed = $fast->started_at->diffInMinutes(now()) / 60;
        [$stage, $next] = self::stageFor($elapsed);

        return [
            'type' => 'fasting',
            'active' => true,
            'started_at' => $fast->started_at->toIso8601String(),
            'elapsed_h' => round($elapsed, 1),
            'goal_h' => $fast->goal_hours,
            'pct' => $fast->goal_hours > 0 ? min(100, (int) round($elapsed / $fast->goal_hours * 100)) : 0,
            'stage' => $stage[1],
            'stage_blurb' => $stage[2],
            'next_stage_in_h' => $next !== null ? round(max(0, $next[0] - $elapsed), 1) : null,
        ];
    }

    /** @return array{0:array,1:?array} [current stage, next stage|null] */
    private static function stageFor(float $hours): array
    {
        $current = self::STAGES[0];
        $next = null;
        foreach (self::STAGES as $i => $s) {
            if ($hours >= $s[0]) {
                $current = $s;
                $next = self::STAGES[$i + 1] ?? null;
            }
        }

        return [$current, $next];
    }
}
