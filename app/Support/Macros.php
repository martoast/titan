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
    // Atwater energy per gram — the arithmetic every macro↔calorie reconciliation uses.
    private const KCAL_P = 4;
    private const KCAL_C = 4;
    private const KCAL_F = 9;

    /**
     * Keep a logged meal's macros consistent with its calories — the fast-logger is an ESTIMATE tracker,
     * so a turn can capture "470 kcal, 37g protein" and leave carbs/fat at 0, which then shows broken
     * 0C/0F chips on the meal card AND undercounts the day's carbs/fat (review d987a9d). This is a soft
     * guardrail, not a blocker: it never rejects a log, it just refuses to STORE macros that contradict
     * the meal's own calorie number.
     *
     * Rules (only when there's a real, >tolerance mismatch — normal rounding passes through untouched):
     *  - No calorie anchor (calories ≤ 0): derive calories from the macros (4P+4C+9F).
     *  - Calories set but the macro energy falls short AND a macro is sitting at 0: distribute the
     *    unaccounted energy into the zero macro(s), split evenly by energy (deterministic + reconciles).
     *    All three at 0 → a balanced 30/40/30-by-energy default so the day total stays honest.
     *  - Macros fully specified but still off: trust the detailed breakdown, derive calories from it.
     *
     * The exact split of a 2-missing case isn't knowable server-side (peanut butter vs milk), so this
     * guarantees the CALORIES and the daily total are honest and the chips aren't self-contradicting; the
     * user can always tap Edit for the precise split.
     *
     * @return array{calories:int,protein_g:float,carbs_g:float,fat_g:float}
     */
    public static function reconcile(int $calories, float $protein, float $carbs, float $fat): array
    {
        $p = max(0.0, $protein);
        $c = max(0.0, $carbs);
        $f = max(0.0, $fat);
        $macroKcal = self::KCAL_P * $p + self::KCAL_C * $c + self::KCAL_F * $f;

        // No calorie anchor → the macros define the energy.
        if ($calories <= 0) {
            return self::macroRow((int) round($macroKcal), $p, $c, $f);
        }

        $gap = $calories - $macroKcal;
        $tol = max(120.0, 0.25 * $calories);   // within normal rounding/estimate noise → leave as logged
        if (abs($gap) <= $tol) {
            return self::macroRow($calories, $p, $c, $f);
        }

        // Which macros are effectively missing (a bare "0")?
        $zeros = array_keys(array_filter(['p' => $p, 'c' => $c, 'f' => $f], fn ($v) => $v < 0.5));

        // Calories exceed the accounted macro energy, with a macro left at 0 → fill the missing macro(s).
        if ($gap > 0 && $zeros !== []) {
            if (count($zeros) === 3) {
                // Only calories were given — a balanced default split keeps the daily total honest.
                return self::macroRow($calories,
                    0.30 * $calories / self::KCAL_P,
                    0.40 * $calories / self::KCAL_C,
                    0.30 * $calories / self::KCAL_F);
            }
            $share = $gap / count($zeros);   // split the unaccounted energy evenly by kcal
            foreach ($zeros as $z) {
                match ($z) {
                    'p' => $p = $share / self::KCAL_P,
                    'c' => $c = $share / self::KCAL_C,
                    default => $f = $share / self::KCAL_F,
                };
            }

            return self::macroRow($calories, $p, $c, $f);
        }

        // Macros are fully specified but contradict the calories (over, or under with none at 0):
        // trust the detailed breakdown and let it define the energy.
        return self::macroRow((int) round($macroKcal), $p, $c, $f);
    }

    /** @return array{calories:int,protein_g:float,carbs_g:float,fat_g:float} */
    private static function macroRow(int $cal, float $p, float $c, float $f): array
    {
        return ['calories' => $cal, 'protein_g' => round($p, 1), 'carbs_g' => round($c, 1), 'fat_g' => round($f, 1)];
    }

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
        $t = self::resolveTargets($profile);
        $calT = $t['calories'];
        $proT = $t['protein_g'];
        $carbT = $t['carbs_g'];
        $fatT = $t['fat_g'];

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

    /**
     * The ONE macro-target resolver every surface shares — mobile card, coach card, web meals page, and
     * the home dashboard. Previously `today()` used TargetSettings::resolve while goalTargets()/forHome()
     * read raw `macro_targets`, so the screens could disagree (MEAL_LOGGING_REVISION 1.3). All now route
     * through TargetSettings::resolve (which honours custom overrides + derives carb/fat from the budget).
     *
     * @return array{calories:int,protein_g:int,carbs_g:int,fat_g:int}
     */
    private static function resolveTargets(Profile $profile): array
    {
        $t = rescue(fn () => TargetSettings::resolve($profile), null, false) ?? self::DEFAULT_TARGETS;

        return [
            'calories' => (int) $t['calories'],
            'protein_g' => (int) $t['protein_g'],
            'carbs_g' => (int) $t['carbs_g'],
            'fat_g' => (int) $t['fat_g'],
        ];
    }

    public static function goalTargets(Profile $profile): array
    {
        return self::resolveTargets($profile);
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
