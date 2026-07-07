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

        // One source of truth for all four targets (honours the user's custom overrides; carb/fat
        // default to a sensible split of the calorie budget when not customised).
        $t = rescue(fn () => TargetSettings::resolve($profile), null, false)
            ?? ['calories' => 2800, 'protein_g' => 200, 'carbs_g' => 280, 'fat_g' => 84];
        $calT = (int) $t['calories'];
        $proT = (int) $t['protein_g'];
        $carbT = (int) $t['carbs_g'];
        $fatT = (int) $t['fat_g'];

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

    /** The macro budget the user configures on the Meals page (settings override, else defaults). */
    public const DEFAULT_TARGETS = ['calories' => 2800, 'protein_g' => 200, 'carbs_g' => 280, 'fat_g' => 80];

    public static function goalTargets(Profile $profile): array
    {
        $set = $profile->settings['macro_targets'] ?? [];

        return [
            'calories' => (int) ($set['calories'] ?? self::DEFAULT_TARGETS['calories']),
            'protein_g' => (int) ($set['protein_g'] ?? self::DEFAULT_TARGETS['protein_g']),
            'carbs_g' => (int) ($set['carbs_g'] ?? self::DEFAULT_TARGETS['carbs_g']),
            'fat_g' => (int) ($set['fat_g'] ?? self::DEFAULT_TARGETS['fat_g']),
        ];
    }

    /**
     * Today's fuel for the home dashboard's Fuel card — consumed vs the SAME targets the Meals
     * page shows (so the two screens never disagree). Shape mirrors today()'s value/target pairs.
     */
    public static function forHome(Profile $profile): array
    {
        $appTz = config('app.timezone', 'UTC');
        $tz = $profile->settings['timezone'] ?? $appTz;
        $start = Carbon::now($tz)->startOfDay()->setTimezone($appTz);
        $end = $start->copy()->addDay();
        $meals = $profile->meals()->where('eaten_at', '>=', $start)->where('eaten_at', '<', $end)->get();

        $t = self::goalTargets($profile);
        $logged = $meals->count();

        return [
            'calories' => ['value' => (int) $meals->sum('calories'), 'target' => $t['calories']],
            'protein' => ['value' => (int) round((float) $meals->sum('protein_g')), 'target' => $t['protein_g']],
            'carbs' => ['value' => (int) round((float) $meals->sum('carbs_g')), 'target' => $t['carbs_g']],
            'fat' => ['value' => (int) round((float) $meals->sum('fat_g')), 'target' => $t['fat_g']],
            'footer' => $logged.' meal'.($logged === 1 ? '' : 's').' logged today',
        ];
    }

    private static function humanMin(int $m): string
    {
        return $m >= 60 ? (intdiv($m, 60).'h'.($m % 60 ? ' '.($m % 60).'m' : '')) : $m.'m';
    }
}
