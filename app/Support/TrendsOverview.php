<?php

namespace App\Support;

use App\Models\ActivitySession;
use App\Models\DailyActivity;
use App\Models\Meal;
use App\Models\Profile;
use App\Models\RecoveryLog;
use App\Models\SleepLog;
use Illuminate\Support\Carbon;

/**
 * The Whoop-style Overview history: a daily series of the three rings — Recovery, Sleep Performance and
 * Strain — plus HRV / RHR / sleep hours, over a week or month, with period averages. Everything is
 * loaded ONCE and the per-day scores recomputed in memory (Readiness::fromData over a growing history
 * slice, the same day-strain curve as Strain), so a 30-day overview is a handful of queries, not 30×.
 */
class TrendsOverview
{
    private const STRAIN_MAX = 21.0;
    private const STRAIN_K = 90.0;   // == Strain::K

    /** @return array<string,mixed> */
    public static function forProfile(Profile $profile, int $days): array
    {
        $days = max(2, min(90, $days));
        $today = Carbon::today();
        $from = $today->copy()->subDays($days - 1);

        // Recovery needs up to 60 prior days for the z-score baseline; sleep debt looks back ~5.
        $recovery = RecoveryLog::where('profile_id', $profile->id)
            ->whereDate('logged_at', '>=', $from->copy()->subDays(60))
            ->orderBy('logged_at')->get();
        $recByDay = $recovery->keyBy(fn (RecoveryLog $r) => $r->logged_at->toDateString());

        $sleepByDay = SleepLog::where('profile_id', $profile->id)->nights()->final()
            ->whereDate('slept_at', '>=', $from->copy()->subDays(2))
            ->get()->keyBy(fn (SleepLog $s) => $s->slept_at->toDateString());

        $actByDay = DailyActivity::where('profile_id', $profile->id)
            ->whereDate('date', '>=', $from)->get()
            ->keyBy(fn (DailyActivity $a) => Carbon::parse($a->date)->toDateString());

        $sessByDay = ActivitySession::where('profile_id', $profile->id)->training()
            ->whereDate('started_at', '>=', $from)->get()
            ->groupBy(fn (ActivitySession $s) => Carbon::parse($s->started_at)->toDateString());

        // Nutrition per day (MEAL_LOGGING_REVISION 2.2). Meals are app-tz wall-clocks — group by that frame
        // (same as $key below), never re-convert (the meal-timezone fault).
        $mealsByDay = Meal::where('profile_id', $profile->id)
            ->where('eaten_at', '>=', $from->copy()->startOfDay())->get()
            ->groupBy(fn (Meal $m) => $m->eaten_at->toDateString());

        $sleepBaseline = SleepCoach::baselineFor($profile);

        $points = [];
        for ($i = 0; $i < $days; $i++) {
            $d = $from->copy()->addDays($i);
            $key = $d->toDateString();
            $rec = $recByDay->get($key);
            $slp = $sleepByDay->get($key);

            // Recovery score: readiness computed from history up to and including this day.
            $hist = $recovery->filter(fn (RecoveryLog $r) => $r->logged_at->toDateString() <= $key)->values();
            $score = null;
            try {
                $score = Readiness::fromData($hist, $rec, $slp)['score'] ?? null;
            } catch (\Throwable) {
            }

            // Sleep performance vs the personal need baseline (same basis as the Sleep tab).
            $perf = ($slp && $slp->duration_min && $sleepBaseline > 0)
                ? (int) round(min(100.0, ($slp->duration_min / 60.0) / $sleepBaseline * 100.0)) : null;

            // Day strain from cumulative TRIMP + ambient load (same log curve as Strain).
            $trimp = (float) (($sessByDay->get($key) ?? collect())->sum('trimp'));
            $act = $actByDay->get($key);
            $ambient = max((float) ($act->mvpa_min ?? 0) * 0.8, (int) ($act->steps ?? 0) * 0.004);
            $strain = $trimp + $ambient > 0
                ? round(self::STRAIN_MAX * (1 - exp(-($trimp + $ambient) / self::STRAIN_K)), 1) : null;

            // Nutrition consumed that day (null when nothing was logged → the chart shows a gap, not a 0-dip).
            $meals = $mealsByDay->get($key);

            $points[] = [
                'date' => $key,
                'recovery' => $score,
                'sleep_performance' => $perf,
                'strain' => $strain,
                'hrv' => $rec?->hrv_ms !== null ? (int) round($rec->hrv_ms) : null,
                'rhr' => $rec?->resting_hr !== null ? (int) round($rec->resting_hr) : null,
                'sleep_h' => ($slp && $slp->duration_min) ? round($slp->duration_min / 60.0, 1) : null,
                'calories' => $meals ? (int) $meals->sum('calories') : null,
                'protein' => $meals ? (int) round((float) $meals->sum('protein_g')) : null,
                'carbs' => $meals ? (int) round((float) $meals->sum('carbs_g')) : null,
                'fat' => $meals ? (int) round((float) $meals->sum('fat_g')) : null,
            ];
        }

        return [
            'days' => $days,
            'points' => $points,
            'averages' => [
                'recovery' => self::avg($points, 'recovery'),
                'sleep_performance' => self::avg($points, 'sleep_performance'),
                'strain' => self::avg($points, 'strain'),
                'hrv' => self::avg($points, 'hrv'),
                'rhr' => self::avg($points, 'rhr'),
                'sleep_h' => self::avg($points, 'sleep_h', 1),
                'calories' => self::avg($points, 'calories'),
                'protein' => self::avg($points, 'protein'),
                'carbs' => self::avg($points, 'carbs'),
                'fat' => self::avg($points, 'fat'),
            ],
            // The unified daily targets, so the trend charts can show adherence vs goal (2.2).
            'targets' => Macros::goalTargets($profile),
        ];
    }

    /** @param array<int,array<string,mixed>> $points */
    private static function avg(array $points, string $key, int $decimals = 0): ?float
    {
        $vals = array_values(array_filter(array_column($points, $key), fn ($v) => $v !== null));
        if ($vals === []) {
            return null;
        }
        $mean = array_sum($vals) / count($vals);

        return $decimals ? round($mean, $decimals) : (float) round($mean);
    }
}
