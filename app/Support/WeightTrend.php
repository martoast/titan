<?php

namespace App\Support;

use App\Models\Goal;
use App\Models\Profile;
use Illuminate\Support\Carbon;

/**
 * "True weight" — the EWMA trend that strips the daily water/food noise (≈¾ of scale movement) to
 * reveal the real fat-loss signal, plus the weekly rate and an honest projection to a goal. This is
 * the single highest-ROI, hardware-free weight feature (MacroFactor / TrendWeight / Hacker's Diet).
 *
 *   trend_t = trend_{t-1} + α·(weight_t − trend_{t-1}),  α = 0.10  (≈19-day SMA, ~6.6-day half-life)
 *
 * Missing days are linearly interpolated, then EWMA runs over the daily series. Weekly rate = least-
 * squares slope over the last 14 trend points × 7. Projection is linear from the current rate (honest
 * "at this rate"); long-horizon deceleration (Hall) is left to the coach's narrative.
 */
class WeightTrend
{
    public const ALPHA = 0.10;
    private const KCAL_PER_KG = 7700;   // ≈ 3500 kcal/lb

    /**
     * Daily series with interpolation + EWMA trend, over the readings' span (capped to ~$days).
     *
     * @return array<int,array{date:string,weight:?float,trend:float}>
     */
    public static function series(Profile $profile, int $days = 180): array
    {
        // First reading per day (kg), ascending.
        $rows = $profile->bodyMetrics()->whereNotNull('weight_kg')
            ->where('taken_at', '>=', Carbon::today()->subDays($days)->toDateString())
            ->orderBy('taken_at')->get(['taken_at', 'weight_kg']);

        $byDay = [];
        foreach ($rows as $r) {
            $d = $r->taken_at instanceof Carbon ? $r->taken_at->toDateString() : (string) $r->taken_at;
            $byDay[substr($d, 0, 10)] ??= (float) $r->weight_kg;   // first of the day
        }
        if ($byDay === []) {
            return [];
        }

        $dates = array_keys($byDay);
        $start = Carbon::parse($dates[0]);
        $end = Carbon::parse(end($dates));

        // Build daily, linear-interpolating gaps between known readings.
        $series = [];
        $prevDate = null;
        $prevVal = null;
        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            $key = $d->toDateString();
            if (isset($byDay[$key])) {
                $series[] = ['date' => $key, 'weight' => $byDay[$key]];
                $prevDate = $d->copy();
                $prevVal = $byDay[$key];
            } else {
                // interpolate to the next known reading
                $next = null;
                $nextDate = null;
                foreach ($dates as $cand) {
                    if ($cand > $key) {
                        $next = $byDay[$cand];
                        $nextDate = Carbon::parse($cand);
                        break;
                    }
                }
                $interp = $prevVal;
                if ($prevVal !== null && $next !== null && $nextDate && $prevDate) {
                    $span = $prevDate->diffInDays($nextDate);
                    $into = $prevDate->diffInDays($d);
                    $interp = $span > 0 ? $prevVal + ($next - $prevVal) * ($into / $span) : $prevVal;
                }
                $series[] = ['date' => $key, 'weight' => null, '_interp' => $interp];
            }
        }

        // EWMA over the (filled) daily values.
        $trend = null;
        foreach ($series as $i => &$pt) {
            $val = $pt['weight'] ?? $pt['_interp'];
            $trend = $trend === null ? $val : $trend + self::ALPHA * ($val - $trend);
            $pt['trend'] = round($trend, 2);
            unset($pt['_interp']);
        }

        return $series;
    }

    /** @return array{date:string,trend:float,weight:?float}|null current trend point */
    public static function current(Profile $profile): ?array
    {
        $s = self::series($profile);

        return $s === [] ? null : $s[count($s) - 1];
    }

    /** Weekly rate (kg/week) = least-squares slope over the last $window trend points × 7. */
    public static function weeklyRateKg(Profile $profile, int $window = 14): ?float
    {
        $s = self::series($profile);
        $n = count($s);
        if ($n < 4) {
            return null;
        }
        $tail = array_slice($s, max(0, $n - $window));
        $m = count($tail);
        $sx = $sy = $sxy = $sxx = 0.0;
        foreach ($tail as $i => $pt) {
            $sx += $i;
            $sy += $pt['trend'];
            $sxy += $i * $pt['trend'];
            $sxx += $i * $i;
        }
        $denom = $m * $sxx - $sx * $sx;
        if ($denom == 0.0) {
            return null;
        }
        $slope = ($m * $sxy - $sx * $sy) / $denom;   // kg/day

        return round($slope * 7, 3);
    }

    /**
     * Projection toward a target weight.
     *
     * @return array{on_track:bool,eta_days:?int,projected_date:?string,rate_kg_wk:?float,daily_kcal:?int}
     */
    public static function projection(Profile $profile, float $targetKg): array
    {
        $cur = self::current($profile);
        $rate = self::weeklyRateKg($profile);
        if ($cur === null || $rate === null || abs($rate) < 0.02) {
            return ['on_track' => false, 'eta_days' => null, 'projected_date' => null, 'rate_kg_wk' => $rate, 'daily_kcal' => null];
        }
        $trend = $cur['trend'];
        $losing = $targetKg < $trend;
        $progressing = $losing ? $rate < 0 : $rate > 0;
        $dailyKcal = (int) round(($rate / 7) * self::KCAL_PER_KG);   // signed: deficit if negative
        if (! $progressing) {
            return ['on_track' => false, 'eta_days' => null, 'projected_date' => null, 'rate_kg_wk' => $rate, 'daily_kcal' => $dailyKcal];
        }
        $days = (int) ceil(abs(($trend - $targetKg) / ($rate / 7)));

        return [
            'on_track' => true,
            'eta_days' => $days,
            'projected_date' => Carbon::today()->addDays($days)->toDateString(),
            'rate_kg_wk' => $rate,
            'daily_kcal' => $dailyKcal,
        ];
    }

    /** The active weight goal, if any. */
    public static function activeGoal(Profile $profile): ?Goal
    {
        return $profile->goals()->where('metric', 'weight')->where('status', 'active')->latest('id')->first();
    }

    /**
     * The `weight` card: current trend, weekly rate, and (if a goal is set) the projection.
     * Shared by the coach's weight_progress tool and the mobile /me/weight endpoint.
     *
     * @return array<string,mixed>
     */
    public static function card(Profile $profile): array
    {
        $cur = self::current($profile);
        $card = [
            'type' => 'weight',
            'trend_kg' => $cur['trend'] ?? null,
            'latest_kg' => $cur['weight'] ?? ($cur['trend'] ?? null),
            'rate_kg_wk' => self::weeklyRateKg($profile),
            'goal' => null,
        ];
        if ($goal = self::activeGoal($profile)) {
            $proj = self::projection($profile, $goal->target_value);
            $vsGoal = null;
            if ($goal->target_date && $proj['projected_date']) {
                $vsGoal = Carbon::parse($proj['projected_date'])
                    ->diffInDays(Carbon::parse($goal->target_date), false);   // <0 = ahead, >0 = behind
            }
            $card['goal'] = [
                'target_kg' => $goal->target_value,
                'target_date' => optional($goal->target_date)->toDateString(),
                'on_track' => $proj['on_track'],
                'projected_date' => $proj['projected_date'],
                'eta_days' => $proj['eta_days'],
                'daily_kcal' => $proj['daily_kcal'],
                'vs_goal_days' => $vsGoal,
            ];
        }

        return $card;
    }
}
