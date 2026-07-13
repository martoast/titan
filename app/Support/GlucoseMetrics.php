<?php

namespace App\Support;

/**
 * The headline continuous-glucose metrics (CGM_INTEGRATION P1) — average, time-in-range, variability
 * (SD + CV), and the estimated GMI ("estimated A1c"). Pure math over mg/dL values so every glucose
 * surface reads one honest source. WELLNESS, not medical: these frame metabolic *optimization* (a
 * non-diabetic's normal postprandial spikes are fine), never diagnosis or insulin guidance.
 */
class GlucoseMetrics
{
    /** Non-diabetic wellness "in-range" band (mg/dL). Diabetic targets differ — this is optimization framing. */
    public const RANGE_LOW = 70;
    public const RANGE_HIGH = 140;

    /** CV below this reads as STABLE glucose (the standard variability threshold). */
    public const STABLE_CV = 36.0;

    public const DISCLAIMER = 'Wellness insights from your own CGM — not medical advice, diagnosis, or insulin guidance. Talk to your doctor for anything clinical.';

    public static function mean(array $mgdl): ?float
    {
        $mgdl = array_values(array_filter($mgdl, fn ($v) => $v !== null));

        return $mgdl === [] ? null : array_sum($mgdl) / count($mgdl);
    }

    /** Population standard deviation of the readings. */
    public static function sd(array $mgdl): ?float
    {
        $mgdl = array_values(array_filter($mgdl, fn ($v) => $v !== null));
        $n = count($mgdl);
        if ($n === 0) {
            return null;
        }
        $mean = array_sum($mgdl) / $n;
        $var = array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $mgdl)) / $n;

        return sqrt($var);
    }

    /** Coefficient of variation (%) = SD / mean × 100 — the standard glucose-variability metric. */
    public static function cv(array $mgdl): ?float
    {
        $mean = self::mean($mgdl);
        $sd = self::sd($mgdl);

        return ($mean === null || $sd === null || $mean == 0.0) ? null : $sd / $mean * 100;
    }

    /** Fraction (0–100) of readings inside [low, high]. */
    public static function timeInRange(array $mgdl, int $low = self::RANGE_LOW, int $high = self::RANGE_HIGH): ?float
    {
        $mgdl = array_values(array_filter($mgdl, fn ($v) => $v !== null));
        $n = count($mgdl);
        if ($n === 0) {
            return null;
        }
        $inRange = count(array_filter($mgdl, fn ($v) => $v >= $low && $v <= $high));

        return $inRange / $n * 100;
    }

    /** Glucose Management Indicator (%) — the standard "estimated A1c" from mean glucose (ADA formula). */
    public static function gmi(array|float|null $mgdlOrMean): ?float
    {
        $mean = is_array($mgdlOrMean) ? self::mean($mgdlOrMean) : $mgdlOrMean;

        return $mean === null ? null : round(3.31 + 0.02392 * $mean, 1);
    }

    /**
     * The full headline summary for a set of readings.
     *
     * @param  array<int,int|null>  $mgdl
     * @return array<string,mixed>
     */
    public static function summary(array $mgdl, int $low = self::RANGE_LOW, int $high = self::RANGE_HIGH): array
    {
        $clean = array_values(array_filter($mgdl, fn ($v) => $v !== null));
        if ($clean === []) {
            return ['n' => 0];
        }
        $mean = self::mean($clean);
        $cv = self::cv($clean);

        return [
            'n' => count($clean),
            'average_mg_dl' => (int) round($mean),
            'time_in_range_pct' => (int) round(self::timeInRange($clean, $low, $high)),
            'range_low' => $low,
            'range_high' => $high,
            'sd' => round(self::sd($clean), 1),
            'cv_pct' => round($cv, 1),
            'stable' => $cv !== null && $cv < self::STABLE_CV,
            'gmi_pct' => self::gmi($mean),
            'min_mg_dl' => min($clean),
            'max_mg_dl' => max($clean),
        ];
    }
}
