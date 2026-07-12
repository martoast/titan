<?php

namespace App\Support;

use App\Models\MotionSample;
use App\Models\Profile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Real-time stress from signals we already stream — a Whoop-style 0–3 score computed server-side from
 * the continuous on-chip HR trend (`hr_samples`) against the personal resting/HRV baseline, MOTION-GATED
 * so a workout never reads as a panic attack.
 *
 * The one load-bearing correctness invariant: stress rises only when HR is elevated AND the user is NOT
 * moving (and, when we have a fresh HRV read, when HRV is suppressed). High HR + high motion = exercise,
 * not stress → suppressed. See {@see Strain} (the sibling load model this mirrors) and StressMonitorTest.
 *
 * Honesty ethos (like RecoveryConfidence): never a hard number on a thin baseline — an early user gets
 * "still learning your calm baseline", not a confident 2.7.
 */
class StressMonitor
{
    /** Minutes of recent samples that define "right now". */
    private const WINDOW_MIN = 10;

    /** Trailing days for the HRV baseline median — matches RecoveryMetrics' 30-day window. */
    private const BASELINE_DAYS = 30;

    /** Mean recent motion (milli-g, the T10 EMA scale) at/above which we call the user ACTIVE and refuse
     *  to read HR as stress. Resting/desk motion sits well below this; a walk or lift sits above it. */
    private const MOTION_ACTIVE = 55;

    /** HR elevation (Karvonen HRR) below which there's simply no arousal to interpret — keeps a calm,
     *  seated user pinned at 0 instead of drifting up on baseline noise. */
    private const HRR_FLOOR = 0.08;

    /**
     * The point-in-time stress read. `$at` defaults to now; `$tz` only labels the returned timestamp.
     *
     * @return array{
     *   stress: float, level: string, moving: bool, confidence: array{level:string,note:?string},
     *   drivers: ?string, hr: ?int, rest: int, hrv: ?float, hrv_baseline: ?float, at: string
     * }
     */
    public static function assess(Profile $profile, ?Carbon $at = null, ?string $tz = null): array
    {
        $at = $at ? $at->copy() : Carbon::now();
        $since = $at->copy()->subMinutes(self::WINDOW_MIN);

        $hr = self::recentHr($profile, $since, $at);
        $motion = self::recentMotion($profile, $since, $at);
        $rest = self::restingHr($profile);
        $max = self::maxHr($profile);

        // No HR in the window → we simply can't say. Calm-but-unknown, honestly labelled.
        if ($hr === null) {
            return self::result(0.0, false, $profile, $hr, $rest, null, null, null, $at, $tz);
        }

        // Karvonen heart-rate reserve — the same elevation measure Strain uses for load.
        $reserve = max(1.0, $max - $rest);
        $hrr = min(1.0, max(0.0, ($hr - $rest) / $reserve));

        // MOTION GATE (the correctness invariant): if they're moving, elevated HR is exercise, not stress.
        $moving = $motion !== null && $motion >= self::MOTION_ACTIVE;
        if ($moving || $hrr <= self::HRR_FLOOR) {
            return self::result(0.0, $moving, $profile, $hr, $rest, null, null, null, $at, $tz);
        }

        // HRV suppression vs the personal baseline — a second, stronger stress signal WHEN we have a
        // recent read (v1 has no daytime HRV micro-burst yet, so this is often null and HR carries it).
        [$hrv, $hrvBaseline] = self::hrv($profile);
        $hrvSuppression = 0.0;
        if ($hrv !== null && $hrvBaseline !== null && $hrvBaseline > 0 && $hrv < $hrvBaseline) {
            $hrvSuppression = min(1.0, ($hrvBaseline - $hrv) / $hrvBaseline);
        }

        // Combine on 0..1 then scale to 0..3. HR elevation leads; HRV sharpens it when present.
        $arousal = $hrv !== null
            ? 0.6 * $hrr + 0.4 * $hrvSuppression
            : $hrr;
        $stress = round(min(3.0, max(0.0, 3.0 * $arousal)), 2);

        return self::result($stress, false, $profile, $hr, $rest, $hrv, $hrvBaseline, $hrvSuppression, $at, $tz);
    }

    /** Shape the return + attach confidence, level and human drivers. */
    private static function result(
        float $stress, bool $moving, Profile $profile, ?int $hr, int $rest,
        ?float $hrv, ?float $hrvBaseline, ?float $hrvSuppression, Carbon $at, ?string $tz
    ): array {
        $confidence = self::confidence($profile);
        // A thin baseline can't carry a confident number — report the read but soften the level.
        $level = $confidence['level'] === 'low' ? self::level(min($stress, 1.0)) : self::level($stress);

        $drivers = null;
        if ($hr !== null && ! $moving && $stress > 0) {
            $drivers = "HR {$hr} vs {$rest} rest";
            if ($hrv !== null && $hrvBaseline !== null) {
                $drivers .= ' · HRV '.round($hrv).' vs '.round($hrvBaseline);
            }
        } elseif ($moving) {
            $drivers = 'moving — not stress';
        }

        return [
            'stress' => $stress,
            'level' => $level,
            'moving' => $moving,
            'confidence' => $confidence,
            'drivers' => $drivers,
            'hr' => $hr,
            'rest' => $rest,
            'hrv' => $hrv,
            'hrv_baseline' => $hrvBaseline,
            'at' => ($tz ? $at->copy()->setTimezone($tz) : $at)->toIso8601String(),
        ];
    }

    /** 0–3 → calm / low / medium / high. */
    private static function level(float $stress): string
    {
        return match (true) {
            $stress >= 2.25 => 'high',
            $stress >= 1.5 => 'medium',
            $stress >= 0.75 => 'low',
            default => 'calm',
        };
    }

    /** Mean bpm over the recent window (local-wall-clock recorded_at, per the hr_samples convention). */
    private static function recentHr(Profile $profile, Carbon $since, Carbon $at): ?int
    {
        $avg = $profile->hrSamples()
            ->whereBetween('recorded_at', [$since, $at])
            ->avg('bpm');

        return $avg !== null ? (int) round($avg) : null;
    }

    /** Mean motion (milli-g) over the recent window. NULL when no motion coverage — then we can't gate,
     *  so an elevated HR is treated cautiously as possible stress (motion absent ≠ known-still). */
    private static function recentMotion(Profile $profile, Carbon $since, Carbon $at): ?int
    {
        $avg = MotionSample::query()
            ->where('profile_id', $profile->id)
            ->whereBetween('recorded_at', [$since, $at])
            ->avg('motion');

        return $avg !== null ? (int) round($avg) : null;
    }

    /** Latest sealed resting HR, else 60 — identical to Strain::hrZoneLoad. */
    private static function restingHr(Profile $profile): int
    {
        return (int) ($profile->recoveryLogs()->whereNotNull('resting_hr')->latest('logged_at')->value('resting_hr') ?: 60);
    }

    /** Tanaka max HR — identical to Strain::hrZoneLoad. */
    private static function maxHr(Profile $profile): float
    {
        $age = $profile->birthdate ? (int) $profile->birthdate->age : 35;

        return 208.0 - 0.7 * $age;
    }

    /**
     * Latest HRV read + the 30-day baseline median (RMSSD proxy on RecoveryLog.hrv_ms), matching
     * RecoveryMetrics. Returns [current, baseline] — either may be null.
     *
     * @return array{0: ?float, 1: ?float}
     */
    private static function hrv(Profile $profile): array
    {
        $latest = $profile->recoveryLogs()->whereNotNull('hrv_ms')->where('hrv_ms', '>', 0)
            ->latest('logged_at')->value('hrv_ms');

        $series = $profile->recoveryLogs()->whereNotNull('hrv_ms')->where('hrv_ms', '>', 0)
            ->where('logged_at', '>=', Carbon::now()->subDays(self::BASELINE_DAYS))
            ->orderByDesc('logged_at')->limit(self::BASELINE_DAYS)
            ->pluck('hrv_ms')->map(fn ($v) => (float) $v)->sort()->values();

        $baseline = null;
        if ($series->count() >= 3) {
            $n = $series->count();
            $baseline = $n % 2 ? $series[intdiv($n, 2)] : ($series[$n / 2 - 1] + $series[$n / 2]) / 2;
        }

        return [$latest !== null ? (float) $latest : null, $baseline];
    }

    /**
     * Baseline confidence — reuses RecoveryConfidence's night-count gate so a stress number is never
     * stated flatly on a thin calm-baseline. Full at RecoveryConfidence::FULL_BASELINE nights.
     *
     * @return array{level:string,note:?string}
     */
    private static function confidence(Profile $profile): array
    {
        $nights = $profile->recoveryLogs()->whereNotNull('hrv_ms')->where('hrv_ms', '>', 0)
            ->where('logged_at', '>=', Carbon::now()->subDays(60))
            ->distinct()->count(DB::raw('DATE(logged_at)'));

        if ($nights >= RecoveryConfidence::FULL_BASELINE) {
            return ['level' => 'high', 'note' => null];
        }
        if ($nights >= 4) {
            return ['level' => 'building', 'note' => 'Still learning your calm baseline — read this loosely.'];
        }

        return ['level' => 'low', 'note' => 'Not enough baseline yet to call your stress confidently.'];
    }
}
