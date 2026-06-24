<?php

namespace App\Support;

/**
 * Small, dependency-free statistics for the behavior→outcome correlation engine. Robust-by-default
 * (rank-based) because recovery/HRV/sleep data is skewed and outlier-prone: Mann–Whitney U instead
 * of a t-test, Cliff's delta for effect size, Benjamini–Hochberg to tame multiple comparisons.
 */
class Stats
{
    /** Median of a numeric list. */
    public static function median(array $v): float
    {
        $v = array_values(array_filter($v, 'is_numeric'));
        if ($v === []) {
            return 0.0;
        }
        sort($v);
        $n = count($v);
        $m = intdiv($n, 2);

        return $n % 2 ? (float) $v[$m] : ((float) $v[$m - 1] + (float) $v[$m]) / 2;
    }

    /**
     * Two-sided Mann–Whitney U test p-value via the normal approximation, with tie + continuity
     * correction. Good for n ≥ ~5 per group (our surfacing floor).
     */
    public static function mannWhitneyP(array $a, array $b): float
    {
        $na = count($a);
        $nb = count($b);
        if ($na === 0 || $nb === 0) {
            return 1.0;
        }

        $all = [];
        foreach ($a as $v) {
            $all[] = [(float) $v, 0];
        }
        foreach ($b as $v) {
            $all[] = [(float) $v, 1];
        }
        usort($all, fn ($x, $y) => $x[0] <=> $y[0]);

        $n = $na + $nb;
        $ranks = array_fill(0, $n, 0.0);
        $ties = [];
        $i = 0;
        while ($i < $n) {
            $j = $i;
            while ($j + 1 < $n && $all[$j + 1][0] === $all[$i][0]) {
                $j++;
            }
            $avg = ($i + $j) / 2 + 1;       // 1-based, tie-averaged
            $t = $j - $i + 1;
            if ($t > 1) {
                $ties[] = $t;
            }
            for ($k = $i; $k <= $j; $k++) {
                $ranks[$k] = $avg;
            }
            $i = $j + 1;
        }

        $rankA = 0.0;
        for ($k = 0; $k < $n; $k++) {
            if ($all[$k][1] === 0) {
                $rankA += $ranks[$k];
            }
        }
        $uA = $rankA - $na * ($na + 1) / 2;
        $u = min($uA, $na * $nb - $uA);
        $mu = $na * $nb / 2;

        $tieSum = 0.0;
        foreach ($ties as $t) {
            $tieSum += $t * $t * $t - $t;
        }
        $denom = $n * ($n - 1);
        $sigma = $denom > 0
            ? sqrt(($na * $nb / 12.0) * (($n + 1) - $tieSum / $denom))
            : 0.0;
        if ($sigma <= 0) {
            return 1.0;
        }

        $z = (abs($u - $mu) - 0.5) / $sigma;   // continuity correction
        $p = 2 * (1 - self::normalCdf($z));

        return max(0.0, min(1.0, $p));
    }

    /** Cliff's delta effect size in [-1, 1] (how often a > b vs a < b). */
    public static function cliffsDelta(array $a, array $b): float
    {
        $na = count($a);
        $nb = count($b);
        if ($na === 0 || $nb === 0) {
            return 0.0;
        }
        $gt = 0;
        $lt = 0;
        foreach ($a as $x) {
            foreach ($b as $y) {
                if ($x > $y) {
                    $gt++;
                } elseif ($x < $y) {
                    $lt++;
                }
            }
        }

        return ($gt - $lt) / ($na * $nb);
    }

    /**
     * Benjamini–Hochberg FDR adjustment. Input + output are keyed p/q-values.
     *
     * @param  array<string,float>  $pvalues
     * @return array<string,float>  q-values (same keys)
     */
    public static function benjaminiHochberg(array $pvalues): array
    {
        $m = count($pvalues);
        if ($m === 0) {
            return [];
        }
        $keys = array_keys($pvalues);
        $vals = array_values($pvalues);
        array_multisort($vals, SORT_ASC, $keys);

        $q = [];
        $prev = 1.0;
        for ($i = $m - 1; $i >= 0; $i--) {
            $adj = min($prev, $vals[$i] * $m / ($i + 1));
            $q[$keys[$i]] = $adj;
            $prev = $adj;
        }

        return $q;
    }

    /** Standard normal CDF via an erf approximation (Abramowitz & Stegun 7.1.26). */
    public static function normalCdf(float $z): float
    {
        return 0.5 * (1 + self::erf($z / sqrt(2)));
    }

    private static function erf(float $x): float
    {
        $t = 1 / (1 + 0.3275911 * abs($x));
        $y = 1 - (((((1.061405429 * $t - 1.453152027) * $t) + 1.421413741) * $t - 0.284496736) * $t + 0.254829592) * $t * exp(-$x * $x);

        return $x >= 0 ? $y : -$y;
    }
}
