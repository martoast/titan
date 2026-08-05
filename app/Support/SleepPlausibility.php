<?php

namespace App\Support;

/**
 * Physiological ceilings on a night's stage LAYOUT — the impossible, not the merely unusual.
 *
 * The older `stageSplitImplausible` rule (REM under 5%, or one stage over 70%) catches skewed splits, and
 * the seal deliberately gates it behind coverage so a well-measured but odd night isn't permanently
 * caveated. That gating is right for a skew and wrong for an impossibility: no amount of coverage makes a
 * two-hour unbroken deep bout a real night. Worse, the coverage it gates on is BRIDGED coverage, which the
 * band's duty-cycle sample-and-hold inflates toward 1.0 off roughly one-in-six epochs actually measured —
 * so the number certifying "the signal was strong" is the very number the holes inflate.
 *
 * Tester B, 2026-08-04: 219 min deep (40% of sleep) in a single unbroken 119.5-min block, REM at 6.8%. It
 * cleared the >5% REM trigger, no stage reached 70%, and 0.991 bridged coverage suppressed the soft tier
 * anyway — so it sealed `low_confidence = 0` and the coach told her she was well rested. This class is
 * what that night trips.
 *
 * Lives here rather than on the job so a live seal and a re-judged stored row (`sleep:recheck-confidence`)
 * apply the identical rule.
 */
class SleepPlausibility
{
    /** Adult N3 runs ~13-23% of a night; even a deep-heavy night stays under a third. */
    public const MAX_DEEP_FRAC = 0.35;

    /** N3 arrives in discrete bouts — 20-40 min typical, an hour already long. Hours are an artifact. */
    public const MAX_DEEP_BOUT_MIN = 90;

    /** Deep FRACTION is a whole-night norm. A short recovery nap really can be a third deep, so judging a
     *  40-minute nap by it is a false positive (it would have flagged sleep_log #55, a legitimate nap). The
     *  BOUT ceiling carries no such exemption — it holds at any sleep length. */
    public const MIN_SLEEP_FOR_DEEP_FRAC_MIN = 240;

    /** Staging epoch length (seconds) — the grid the biosignal stager works in. Kept in sync with staging.py. */
    private const EPOCH_SEC = 30;

    /**
     * True when the stage layout cannot be a real night.
     *
     * Accepts either a live stager payload (`hypnogram_30s`) or a stored `sleep_logs` row cast to an array
     * (`hypnogram`), so both callers judge on identical inputs.
     *
     * @param  array<string,mixed>  $metrics
     */
    public static function impossible(array $metrics): bool
    {
        $deep = (float) ($metrics['deep_min'] ?? 0);
        $sleep = $deep + (float) ($metrics['rem_min'] ?? 0) + (float) ($metrics['light_min'] ?? 0);

        if ($sleep >= self::MIN_SLEEP_FOR_DEEP_FRAC_MIN && $deep / $sleep > self::MAX_DEEP_FRAC) {
            return true;
        }

        return self::longestDeepBoutMin($metrics) > self::MAX_DEEP_BOUT_MIN;
    }

    /**
     * Longest unbroken deep bout, in minutes. Stage TOTALS can look ordinary while the way they're laid out
     * gives the artifact away, so this is checked independently of the fraction.
     *
     * @param  array<string,mixed>  $metrics
     */
    public static function longestDeepBoutMin(array $metrics): float
    {
        $hyp = $metrics['hypnogram_30s'] ?? $metrics['hypnogram'] ?? null;
        if (! is_array($hyp) || $hyp === []) {
            return 0.0;   // no timeline to judge — the fraction rule stands alone
        }

        $longest = 0;
        $run = 0;
        foreach ($hyp as $stage) {
            $run = $stage === 'deep' ? $run + 1 : 0;
            if ($run > $longest) {
                $longest = $run;
            }
        }

        return $longest * self::EPOCH_SEC / 60;
    }
}
