<?php

namespace App\Support;

use App\Models\DailyActivity;
use Illuminate\Support\Collection;

/**
 * Non-parametric circadian rest-activity rhythm (RAR) metrics -- Van Someren et al. 1999.
 *
 * Computed from the hourly activity profile across several days:
 *   - IS  (interdaily stability)   0..1  -- how reproducible the 24 h pattern is day-to-day (higher = stronger clock)
 *   - IV  (intradaily variability) 0..2  -- how fragmented activity is within a day (higher = choppier, more napping/waking)
 *   - M10 -- average activity in the most-active 10 h block; L5 -- average in the least-active 5 h block
 *   - RA  (relative amplitude) = (M10 − L5)/(M10 + L5)  0..1 -- the day/night contrast; the headline metric
 *
 * Why RA is the headline: in UK Biobank (Feng et al., Lancet Healthy Longevity 2023, n≈92k) a blunted
 * (low) relative amplitude predicted all-cause mortality HR 1.54, CVD 1.73, cancer 1.32 -- a robust,
 * recent, large-cohort signal. A strong rhythm (active days, still nights) is protective.
 *
 * Everything is computed from data we already stream 24/7; the absolute activity unit is irrelevant
 * (RA is a ratio, IS/IV are variance-normalised), so any consistent per-hour activity proxy works.
 */
class CircadianRhythm
{
    /** Need a handful of full days before the rhythm metrics mean anything (literature uses ≥7). */
    public const MIN_DAYS = 5;

    private const HOURS = 24;

    /**
     * @param  Collection<int,DailyActivity>  $days  recent days (any order)
     * @return array{ra:int,is:float,iv:float,m10:float,l5:float,m10_onset:int,l5_onset:int,days:int,band:string,label:string}|null
     */
    public static function compute(Collection $days): ?array
    {
        // Keep only days with a complete, non-empty 24-value hourly profile, chronological.
        $profiles = $days
            ->filter(fn (DailyActivity $d) => is_array($d->hourly) && count($d->hourly) === self::HOURS && array_sum(array_map('floatval', $d->hourly)) > 0)
            ->sortBy(fn (DailyActivity $d) => $d->date->timestamp)
            ->values();

        if ($profiles->count() < self::MIN_DAYS) {
            return null;
        }

        // Flat chronological series x[0..N) and the per-hour-of-day stacks.
        $x = [];
        $byHour = array_fill(0, self::HOURS, []);
        foreach ($profiles as $day) {
            foreach (array_values($day->hourly) as $h => $v) {
                $v = (float) $v;
                $x[] = $v;
                $byHour[$h][] = $v;
            }
        }

        $n = count($x);
        $mean = array_sum($x) / $n;
        $totalVar = 0.0;
        foreach ($x as $v) {
            $totalVar += ($v - $mean) ** 2;
        }
        if ($totalVar <= 0.0) {
            return null; // no variability → rhythm undefined
        }

        // IS = N·Σ_h (mean_h − mean)² / (24·Σ_i (x_i − mean)²)
        $hourVar = 0.0;
        $hourMeans = [];
        foreach ($byHour as $h => $vals) {
            $mh = $vals ? array_sum($vals) / count($vals) : 0.0;
            $hourMeans[$h] = $mh;
            $hourVar += ($mh - $mean) ** 2;
        }
        $is = ($n * $hourVar) / (self::HOURS * $totalVar);

        // IV = N·Σ (x_i − x_{i-1})² / ((N−1)·Σ (x_i − mean)²)
        $diffSq = 0.0;
        for ($i = 1; $i < $n; $i++) {
            $diffSq += ($x[$i] - $x[$i - 1]) ** 2;
        }
        $iv = ($n * $diffSq) / (($n - 1) * $totalVar);

        // M10 / L5 on the average 24 h profile, windows may wrap past midnight.
        [$m10, $m10Onset] = self::extremeWindow($hourMeans, 10, true);
        [$l5, $l5Onset] = self::extremeWindow($hourMeans, 5, false);
        $ra = ($m10 + $l5) > 0 ? ($m10 - $l5) / ($m10 + $l5) : 0.0;

        $raScore = (int) round($ra * 100);
        [$band, $label] = self::band($raScore);

        return [
            'ra' => $raScore,
            'is' => round($is, 2),
            'iv' => round($iv, 2),
            'm10' => round($m10, 1),
            'l5' => round($l5, 1),
            'm10_onset' => $m10Onset,
            'l5_onset' => $l5Onset,
            'days' => $profiles->count(),
            'band' => $band,
            'label' => $label,
        ];
    }

    /**
     * Highest (or lowest) average over `$len` consecutive hours on a circular 24 h profile.
     *
     * @param  array<int,float>  $hourMeans
     * @return array{0:float,1:int}  [windowMean, onsetHour]
     */
    private static function extremeWindow(array $hourMeans, int $len, bool $max): array
    {
        $best = null;
        $bestStart = 0;
        for ($s = 0; $s < self::HOURS; $s++) {
            $sum = 0.0;
            for ($k = 0; $k < $len; $k++) {
                $sum += $hourMeans[($s + $k) % self::HOURS];
            }
            $avg = $sum / $len;
            if ($best === null || ($max ? $avg > $best : $avg < $best)) {
                $best = $avg;
                $bestStart = $s;
            }
        }

        return [$best ?? 0.0, $bestStart];
    }

    /** @return array{0:string,1:string} */
    private static function band(int $ra): array
    {
        return match (true) {
            $ra >= 85 => ['excellent', 'Strong rhythm'],
            $ra >= 75 => ['good', 'Healthy rhythm'],
            $ra >= 60 => ['fair', 'Somewhat blunted'],
            default => ['low', 'Blunted rhythm'],
        };
    }
}
