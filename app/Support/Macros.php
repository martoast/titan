<?php

namespace App\Support;

use App\Models\Profile;
use Illuminate\Support\Carbon;

/**
 * Today's macros as a ready-made chat card: calories + protein/carbs/fat vs targets.
 * Shared by the coach's macros_today / log_meal tools and the meal-photo scan, so logging
 * food -- by text or by photo -- always shows the same "your day filling up" card.
 */
class Macros
{
    /** @return array<string,mixed> the `macros` titan-card payload */
    public static function today(Profile $profile): array
    {
        // The user's local "today", expressed in the storage (app) timezone so it matches
        // how meals are saved (Eloquent stores the wall-clock in the app timezone).
        $appTz = config('app.timezone', 'UTC');
        $tz = $profile->settings['timezone'] ?? $appTz;
        $start = Carbon::now($tz)->startOfDay()->setTimezone($appTz);
        $end = $start->copy()->addDay();
        $meals = $profile->meals()->where('eaten_at', '>=', $start)->where('eaten_at', '<', $end)->get();

        $calT = 2800;
        $proT = 200;
        if (class_exists(MealCoach::class)) {
            $t = rescue(fn () => MealCoach::targets($profile), null, false);
            if ($t) {
                $calT = (int) $t['calories'];
                $proT = (int) $t['protein_g'];
            }
        }
        // Sensible carb/fat targets from the calorie budget (fat ~27% of kcal, the rest carbs).
        $fatT = (int) round($calT * 0.27 / 9);
        $carbT = (int) max(0, round(($calT - $proT * 4 - $fatT * 9) / 4));

        $logged = $meals->count();
        $next = null;
        if (class_exists(MealCoach::class)) {
            $mc = rescue(fn () => MealCoach::assess($profile), null, false);
            $next = match ($mc['status'] ?? null) {
                'done' => 'all meals in',
                'overdue' => 'eat now',
                'soon' => 'time to eat',
                'upcoming' => ! empty($mc['next_in_min']) ? 'next in '.self::humanMin((int) $mc['next_in_min']) : null,
                default => null,
            };
        }

        return [
            'type' => 'macros',
            'title' => "Today's fuel",
            'calories' => ['value' => (int) $meals->sum('calories'), 'target' => $calT],
            'protein' => ['value' => (int) round((float) $meals->sum('protein_g')), 'target' => $proT],
            'carbs' => ['value' => (int) round((float) $meals->sum('carbs_g')), 'target' => $carbT],
            'fat' => ['value' => (int) round((float) $meals->sum('fat_g')), 'target' => $fatT],
            'footer' => $logged.' meal'.($logged === 1 ? '' : 's').' logged'.($next ? ' · '.$next : ''),
        ];
    }

    /** A `macros` card as a ready-to-embed titan-card fenced block. */
    public static function fenced(Profile $profile): string
    {
        return "```titan-card\n".json_encode(self::today($profile))."\n```";
    }

    private static function humanMin(int $m): string
    {
        return $m >= 60 ? (intdiv($m, 60).'h'.($m % 60 ? ' '.($m % 60).'m' : '')) : $m.'m';
    }
}
