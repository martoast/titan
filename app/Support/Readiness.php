<?php

namespace App\Support;

use App\Models\Profile;
use App\Models\RecoveryLog;
use App\Models\SleepLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The recovery / readiness heuristic (03-algorithms §5).
 *
 * All validated commercial scores converge on the same shape: overnight ln(RMSSD) vs a
 * personal rolling baseline (the dominant term) + inverted resting-HR vs baseline + a
 * sleep contribution, mapped through a logistic to 0-100. We implement exactly that:
 *
 *     ln_rmssd_today = ln(rmssd_ms)                      # always log-transform
 *     ln_rmssd_7d    = mean(ln_rmssd[t-6..t])            # 7-day smoothing (Plews/Buchheit)
 *     base_mean,sd   = mean/std(ln_rmssd[t-60..t-1])     # rolling baseline
 *     z_hrv = (ln_rmssd_7d - base_mean) / base_sd
 *     z_rhr = -(rhr_today - base_mean_rhr) / base_sd_rhr # inverted (lower RHR = better)
 *     z→score(z) = 100 / (1 + e^(-1.1 z))                # logistic: z=0 → 50
 *     recovery = 0.50·hrv + 0.25·rhr + 0.25·sleep
 *     # fatigue overlay: chronically suppressed + rising variability → ×0.85
 *
 * Honesty (03-algorithms §5, §9): the SENSORS are validated; the COMPOSITE score is an
 * informed nudge, not a verdict. We require a minimum baseline before scoring with the
 * full statistical method and otherwise fall back to a gentler ratio estimate so a fresh
 * user still sees something useful (clearly labelled as provisional).
 */
class Readiness
{
    /** Logistic steepness from §5 (z=0 → 50, z=±1 → ~75/25). */
    private const K = 1.1;

    /** Nights of history before the full z-score baseline is trustworthy (§5 cold-start). */
    private const MIN_BASELINE = 14;

    /**
     * Compute readiness for a profile on a given date.
     *
     * @return array{score:int|null, label:string, note:string, components:array<string,int>, provisional:bool}
     */
    public static function compute(Profile $profile, Carbon|string|null $date = null): array
    {
        $date = $date ? Carbon::parse($date)->toDateString() : Carbon::today()->toDateString();

        // Up to 61 days ending on $date, oldest→newest: today + a 60-day baseline window.
        $history = $profile->recoveryLogs()
            ->whereDate('logged_at', '<=', $date)
            ->orderBy('logged_at')
            ->limit(61)
            ->get();

        $today = $history->firstWhere(fn (RecoveryLog $r) => $r->logged_at->toDateString() === $date)
            ?? $history->last();

        $sleep = $profile->sleepLogs()
            ->whereDate('slept_at', '<=', $date)
            ->orderByDesc('slept_at')
            ->orderByDesc('id')
            ->first();

        return self::fromData($history, $today, $sleep);
    }

    /**
     * Pure computation over already-loaded data (used by compute() and directly testable).
     *
     * @param  Collection<int,RecoveryLog>  $history  recovery logs up to & including the target date, oldest→newest
     * @return array{score:int|null, label:string, note:string, components:array<string,int>, provisional:bool}
     */
    public static function fromData(Collection $history, ?RecoveryLog $today, ?SleepLog $sleep): array
    {
        $history = $history->sortBy('logged_at')->values();
        $components = [];
        $provisional = false;

        // ln(RMSSD) series in chronological order (uses hrv_ms as RMSSD proxy — §2 primary).
        $lnSeries = $history
            ->filter(fn (RecoveryLog $r) => ($r->hrv_ms ?? 0) > 0)
            ->map(fn (RecoveryLog $r) => log((float) $r->hrv_ms))
            ->values();

        // --- HRV term (50%) — the dominant contributor ---
        if ($today && ($today->hrv_ms ?? 0) > 0 && $lnSeries->count() >= 2) {
            $ln7d = $lnSeries->slice(-7)->avg();                 // 7-day smoothed ln-RMSSD
            $baseline = $lnSeries->slice(0, max(0, $lnSeries->count() - 1)); // exclude today

            if ($baseline->count() >= self::MIN_BASELINE) {
                $baseMean = $baseline->avg();
                $baseSd = self::std($baseline, $baseMean);
                $zHrv = $baseSd > 1e-6 ? ($ln7d - $baseMean) / $baseSd : 0.0;
                $hrvScore = self::logistic($zHrv);
            } else {
                // Cold start: not enough baseline for a z-score. Use a gentle ratio of
                // today's ln-RMSSD vs whatever baseline we have, centred at 50.
                $provisional = true;
                $baseMean = $baseline->count() ? $baseline->avg() : $ln7d;
                $zHrv = ($ln7d - $baseMean) / 0.20;              // 0.20 ≈ typical ln-RMSSD CV
                $hrvScore = self::logistic($zHrv);
            }
            $components['hrv'] = ['score' => $hrvScore, 'weight' => 0.50];
        }

        // --- RHR term (25%) — inverted: lower resting HR vs baseline = better ---
        $rhrSeries = $history->filter(fn (RecoveryLog $r) => ($r->resting_hr ?? 0) > 0)->map(fn (RecoveryLog $r) => (float) $r->resting_hr)->values();
        if ($today && ($today->resting_hr ?? 0) > 0 && $rhrSeries->count() >= 2) {
            $base = $rhrSeries->slice(0, max(0, $rhrSeries->count() - 1));
            $baseMean = $base->count() ? $base->avg() : $rhrSeries->avg();
            $baseSd = self::std($base->count() ? $base : $rhrSeries, $baseMean);
            $zRhr = $baseSd > 1e-6 ? -((float) $today->resting_hr - $baseMean) / $baseSd : 0.0;
            $components['rhr'] = ['score' => self::logistic($zRhr), 'weight' => 0.25];
        }

        // --- Sleep term (25%) — duration-quality blend, peak at ~8h ---
        if ($sleep && $sleep->duration_min) {
            $hours = $sleep->duration_min / 60;
            $durScore = max(0.0, 100 - abs($hours - 8) * 12.5);    // 8h ≈ 100, ±1h ≈ −12.5
            $sleepScore = $sleep->quality !== null
                ? 0.6 * $durScore + 0.4 * (float) $sleep->quality
                : $durScore;
            $components['sleep'] = ['score' => self::clamp($sleepScore), 'weight' => 0.25];
        }

        if (empty($components)) {
            return [
                'score' => null,
                'label' => 'No data',
                'note' => 'Connect a device or run the Simulator to compute your readiness from overnight HRV, resting HR and sleep.',
                'components' => [],
                'provisional' => false,
            ];
        }

        // Re-normalise weights over whatever components we actually have.
        $totalWeight = array_sum(array_column($components, 'weight'));
        $score = 0.0;
        $breakdown = [];
        foreach ($components as $name => $c) {
            $score += $c['score'] * ($c['weight'] / $totalWeight);
            $breakdown[$name] = (int) round(self::clamp($c['score']));
        }

        // --- Fatigue overlay (§5): chronically suppressed ln-RMSSD + rising variability ---
        if ($lnSeries->count() >= self::MIN_BASELINE) {
            $ln7d = $lnSeries->slice(-7)->avg();
            $baseline = $lnSeries->slice(0, max(0, $lnSeries->count() - 1));
            $baseMean = $baseline->avg();
            $baseSd = self::std($baseline, $baseMean);
            $recentCv = self::cv($lnSeries->slice(-7));
            $olderCv = self::cv($lnSeries->slice(-14, 7));
            if ($baseSd > 1e-6 && $ln7d < $baseMean - 0.5 * $baseSd && $recentCv > $olderCv) {
                $score *= 0.85;
            }
        }

        $score = (int) round(self::clamp($score));
        [$label, $note] = self::interpret($score, $provisional);

        return [
            'score' => $score,
            'label' => $label,
            'note' => $note,
            'components' => $breakdown,
            'provisional' => $provisional,
        ];
    }

    /** Logistic map z → 0-100 (§5): z=0 → 50. */
    private static function logistic(float $z): float
    {
        return 100 / (1 + exp(-self::K * $z));
    }

    /** Population standard deviation of a numeric collection. */
    private static function std(Collection $values, ?float $mean = null): float
    {
        $n = $values->count();
        if ($n < 2) {
            return 0.0;
        }
        $mean ??= $values->avg();
        $var = $values->reduce(fn ($carry, $v) => $carry + ($v - $mean) ** 2, 0.0) / $n;

        return sqrt($var);
    }

    /** Coefficient of variation (std / |mean|). */
    private static function cv(Collection $values): float
    {
        if ($values->count() < 2) {
            return 0.0;
        }
        $mean = $values->avg();

        return abs($mean) > 1e-6 ? self::std($values, $mean) / abs($mean) : 0.0;
    }

    /** @return array{0:string,1:string} */
    private static function interpret(int $score, bool $provisional): array
    {
        [$label, $note] = match (true) {
            $score >= 80 => ['Primed', 'Your body is well recovered — a good day to push hard training.'],
            $score >= 60 => ['Ready', 'Solid recovery. Train as planned and stay on top of sleep.'],
            $score >= 40 => ['Moderate', 'Partial recovery. Keep intensity in check or favour technique work.'],
            $score >= 20 => ['Strained', 'Recovery is low. Prioritise sleep, nutrition and a lighter session.'],
            default => ['Depleted', 'Your body needs rest. Consider an active-recovery or off day.'],
        };

        if ($provisional) {
            $note .= ' Still building your baseline — readiness sharpens after ~2 weeks of nights.';
        }

        return [$label, $note];
    }

    private static function clamp(float $n): float
    {
        return max(0.0, min(100.0, $n));
    }
}
