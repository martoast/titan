<?php

namespace App\Support;

use App\Models\Meal;
use App\Models\Profile;
use Illuminate\Support\Carbon;

/**
 * A meal's glucose RESPONSE (CGM_INTEGRATION P2 — the killer loop). Given a meal time and the CGM readings
 * around it, computes the baseline, the peak rise (Δ), time-to-peak, time-to-baseline, and the 2-hour
 * incremental AUC — the Levels/Nutrisense core metric — so the meal card can say "spiked you +55, back to
 * baseline in 1h40." Wellness, not medical: a spike is normal physiology, framed as optimization.
 *
 * The math is PURE (compute()) + unit-tested; forMeal() only does the windowing against stored readings.
 */
class GlucoseResponse
{
    private const BASELINE_MIN = 15;   // baseline = mean of readings in the 15 min before the meal
    private const AUC_WINDOW_MIN = 120; // incremental AUC integrated over 2 h
    private const RESPONSE_WINDOW_MIN = 180; // look up to 3 h out for the return-to-baseline
    private const BASELINE_TOL = 10;   // "back to baseline" = within 10 mg/dL of it

    // Peak-rise bands for the spikiest/steadiest framing (non-diabetic wellness).
    public const SPIKE_SMALL = 30;
    public const SPIKE_LARGE = 50;

    /**
     * The pure response math.
     *
     * @param  float  $baseline  pre-meal glucose (mg/dL)
     * @param  array<int,array{min:int,mg:int}>  $samples  minutes-since-meal + mg/dL, sorted, min ≥ 0
     * @return array{peak_mg_dl:int,peak_delta:int,time_to_peak_min:int,time_to_baseline_min:?int,auc_2h:int,spike:string}|null
     */
    public static function compute(float $baseline, array $samples): ?array
    {
        $samples = array_values(array_filter($samples, fn ($s) => ($s['min'] ?? -1) >= 0));
        usort($samples, fn ($a, $b) => $a['min'] <=> $b['min']);
        if ($samples === []) {
            return null;
        }

        // Peak (first max wins) within the response window.
        $inWindow = array_values(array_filter($samples, fn ($s) => $s['min'] <= self::RESPONSE_WINDOW_MIN));
        if ($inWindow === []) {
            return null;
        }
        $peak = $inWindow[0];
        foreach ($inWindow as $s) {
            if ($s['mg'] > $peak['mg']) {
                $peak = $s;
            }
        }
        $peakDelta = (int) round($peak['mg'] - $baseline);

        // Time to baseline: first sample AFTER the peak that falls back within tolerance of baseline.
        $ttb = null;
        foreach ($inWindow as $s) {
            if ($s['min'] > $peak['min'] && $s['mg'] <= $baseline + self::BASELINE_TOL) {
                $ttb = $s['min'];
                break;
            }
        }

        return [
            'peak_mg_dl' => (int) round($peak['mg']),
            'peak_delta' => $peakDelta,
            'time_to_peak_min' => (int) $peak['min'],
            'time_to_baseline_min' => $ttb,
            'auc_2h' => (int) round(self::incrementalAuc($baseline, $samples)),
            'spike' => self::spikeBand($peakDelta),
        ];
    }

    /** Incremental AUC (area of glucose ABOVE baseline) over the 2-h window, trapezoidal. mg/dL·min. Pure. */
    public static function incrementalAuc(float $baseline, array $samples): float
    {
        $pts = array_values(array_filter($samples, fn ($s) => $s['min'] >= 0 && $s['min'] <= self::AUC_WINDOW_MIN));
        usort($pts, fn ($a, $b) => $a['min'] <=> $b['min']);
        $area = 0.0;
        for ($i = 1; $i < count($pts); $i++) {
            $v0 = max(0.0, $pts[$i - 1]['mg'] - $baseline);
            $v1 = max(0.0, $pts[$i]['mg'] - $baseline);
            $dt = $pts[$i]['min'] - $pts[$i - 1]['min'];
            $area += ($v0 + $v1) / 2 * $dt;
        }

        return $area;
    }

    public static function spikeBand(int $peakDelta): string
    {
        return match (true) {
            $peakDelta < self::SPIKE_SMALL => 'small',
            $peakDelta < self::SPIKE_LARGE => 'moderate',
            default => 'large',
        };
    }

    /**
     * The response for one logged meal, from the profile's stored readings, or null if there isn't enough
     * glucose around it (no baseline / no post-meal data). $readings may be pre-loaded (a day's worth) to
     * avoid a query per meal.
     *
     * @param  \Illuminate\Support\Collection|null  $readings  pre-fetched GlucoseReading rows (taken_at, mg_dl)
     */
    public static function forMeal(Profile $profile, Meal $meal, $readings = null): ?array
    {
        $at = $meal->eaten_at instanceof Carbon ? $meal->eaten_at->copy() : Carbon::parse($meal->eaten_at);

        // TZ FRAME (review 5876613): glucose `taken_at` is stored in APP-TZ (same frame as meals.eaten_at) —
        // NOT UTC. So the SQL bounds must be app-tz too, or `whereBetween('taken_at', …)` searches ~offset
        // hours off and finds nothing. Mirror GlucoseDay (which queries app-tz and works). The instant
        // comparisons below (lte/gte/betweenIncluded/diffInMinutes) are tz-agnostic and already correct.
        $from = $at->copy()->subMinutes(self::BASELINE_MIN);
        $to = $at->copy()->addMinutes(self::RESPONSE_WINDOW_MIN);

        $rows = $readings
            ? collect($readings)->filter(fn ($r) => $r->taken_at->betweenIncluded($from, $to))
            : $profile->glucoseReadings()->whereBetween('taken_at', [$from, $to])->orderBy('taken_at')->get();

        if ($rows->isEmpty()) {
            return null;
        }

        // Baseline = mean of the pre-meal readings; if none, use the first reading at/after the meal.
        $pre = $rows->filter(fn ($r) => $r->taken_at->lte($at));
        $baseline = $pre->isNotEmpty() ? $pre->avg('mg_dl') : (float) $rows->first()->mg_dl;

        $samples = $rows->filter(fn ($r) => $r->taken_at->gte($at))
            ->map(fn ($r) => ['min' => (int) round($at->diffInMinutes($r->taken_at)), 'mg' => (int) $r->mg_dl])
            ->values()->all();

        $resp = self::compute($baseline, $samples);
        if ($resp === null) {
            return null;
        }

        return ['baseline_mg_dl' => (int) round($baseline)] + $resp;
    }
}
