<?php

namespace App\Support;

use App\Models\Profile;
use App\Models\RecoveryLog;
use App\Models\SleepLog;
use Illuminate\Support\Carbon;

/**
 * The Whoop-style recovery breakdown: each headline metric with its personal BASELINE and a trend vs
 * that baseline, so the app can render "HRV 65 / 92 ▼" the way Whoop does. Baseline = the median over
 * a recent window (robust to the odd bad night), excluding today. `good` tells the app which colour to
 * paint the arrow (a metric moving the healthy way is green, the other way amber) given that for HRV /
 * sleep higher is better while for resting HR / respiratory rate lower is better.
 *
 * Values come straight from the sealed overnight read (RecoveryLog) + the sleep coach's performance —
 * the same numbers the recovery SCORE (Readiness) is built from; this just adds the context.
 */
class RecoveryMetrics
{
    private const BASELINE_DAYS = 30;
    private const DEADBAND = 0.02;   // within ±2% of baseline reads as "flat" (no meaningful move)

    /**
     * @return array{score:?int, metrics:array<int,array<string,mixed>>}|null
     */
    public static function forProfile(Profile $profile, ?Carbon $day = null): ?array
    {
        $day = $day ?? Carbon::today();
        $today = RecoveryLog::where('profile_id', $profile->id)
            ->whereDate('logged_at', '<=', $day)
            ->orderByDesc('logged_at')->orderByDesc('id')->first();
        if (! $today) {
            return null;
        }

        $history = RecoveryLog::where('profile_id', $profile->id)
            ->whereDate('logged_at', '>=', $day->copy()->subDays(self::BASELINE_DAYS))
            ->whereDate('logged_at', '<', $today->logged_at)   // baseline EXCLUDES today
            ->get();

        $metrics = [];
        $metrics[] = self::metric('hrv', 'Heart Rate Variability', 'ms',
            $today->hrv_ms, $history->pluck('hrv_ms'), higherBetter: true);
        $metrics[] = self::metric('rhr', 'Resting Heart Rate', 'bpm',
            $today->resting_hr, $history->pluck('resting_hr'), higherBetter: false);
        $metrics[] = self::metric('resp', 'Respiratory Rate', 'br/min',
            $today->resp_rate, $history->pluck('resp_rate'), higherBetter: false);

        // Sleep performance = last night's hours vs the personal sleep-need baseline (SleepCoach).
        [$sleepVal, $sleepBase] = self::sleepPerformance($profile, $day);
        $metrics[] = self::metric('sleep', 'Sleep Performance', '%',
            $sleepVal, collect($sleepBase !== null ? [$sleepBase] : []), higherBetter: true, explicitBaseline: $sleepBase);

        $score = null;
        try {
            $score = Readiness::compute($profile, $day)['score'] ?? null;
        } catch (\Throwable) {
            // leave null
        }

        return ['score' => $score, 'metrics' => array_values(array_filter($metrics))];
    }

    /**
     * @param  \Illuminate\Support\Collection<int,mixed>  $baselineSeries
     * @return array<string,mixed>|null
     */
    private static function metric(string $key, string $label, string $unit, mixed $value,
        $baselineSeries, bool $higherBetter, ?float $explicitBaseline = null): ?array
    {
        if ($value === null || ! is_numeric($value)) {
            return null;   // no reading tonight → omit (the app shows nothing rather than a fake "—/—")
        }
        $value = (float) $value;
        $baseline = $explicitBaseline ?? self::median($baselineSeries);

        $trend = 'flat';
        $good = null;
        if ($baseline !== null && $baseline > 0) {
            $rel = ($value - $baseline) / $baseline;
            if ($rel > self::DEADBAND) {
                $trend = 'up';
            } elseif ($rel < -self::DEADBAND) {
                $trend = 'down';
            }
            // "good" = moved the healthy way (or flat). Higher-better up = good; lower-better down = good.
            $good = $trend === 'flat' ? true : (($trend === 'up') === $higherBetter);
        }

        return [
            'key' => $key,
            'label' => $label,
            'unit' => $unit,
            'value' => $unit === '%' ? (int) round($value) : round($value, $unit === 'br/min' ? 1 : 0),
            'baseline' => $baseline === null ? null : ($unit === '%' ? (int) round($baseline) : round($baseline, $unit === 'br/min' ? 1 : 0)),
            'trend' => $trend,               // 'up' | 'down' | 'flat' (direction vs baseline)
            'higher_better' => $higherBetter,
            'good' => $good,                 // null until there's a baseline; else true/false for colour
        ];
    }

    /** @return array{0:?float,1:?float} [last-night performance %, baseline performance %] */
    private static function sleepPerformance(Profile $profile, Carbon $day): array
    {
        try {
            $a = SleepCoach::assess($profile, $day);
        } catch (\Throwable) {
            $a = null;
        }
        if (! $a || ($a['performance_pct'] ?? null) === null) {
            return [null, null];
        }
        $baselineH = $a['baseline_h'] ?? null;
        // Baseline performance = median of recent nights' (hours / need) — i.e. how the user usually does.
        $baselinePct = null;
        if ($baselineH && $baselineH > 0) {
            $recent = SleepLog::where('profile_id', $profile->id)->nights()
                ->whereDate('slept_at', '>=', $day->copy()->subDays(self::BASELINE_DAYS))
                ->whereDate('slept_at', '<', $day)
                ->get()
                ->map(fn (SleepLog $s) => min(100.0, ($s->duration_min / 60.0) / $baselineH * 100.0));
            $baselinePct = self::median($recent);
        }

        return [(float) $a['performance_pct'], $baselinePct];
    }

    /** Median of a numeric collection, ignoring nulls/non-numerics; null if empty. */
    private static function median($series): ?float
    {
        $vals = collect($series)->filter(fn ($v) => is_numeric($v))->map(fn ($v) => (float) $v)->sort()->values();
        $n = $vals->count();
        if ($n === 0) {
            return null;
        }

        return $n % 2 ? $vals[intdiv($n, 2)] : ($vals[$n / 2 - 1] + $vals[$n / 2]) / 2;
    }
}
