<?php

namespace App\Support;

use App\Models\Profile;
use Illuminate\Support\Carbon;

/**
 * Detects an ACTIVE post-meal glucose spike (CGM_INTEGRATION P3) — the trigger for the walk-after-spike
 * nudge. This is the genuinely novel move: because Titan can SEE the spike, the coach can suggest a 2-min
 * walk RIGHT NOW to blunt it (Dunstan et al. — a short walk cuts postprandial glucose ~24–30%; see
 * MovementBreaks). Wellness, not medical: a spike is normal physiology; the nudge is a gentle optimization.
 */
class GlucoseSpike
{
    /** How far above the in-range high counts as a real spike worth a nudge (not edge noise). */
    public const SPIKE_MARGIN = 15;
    /** Only fire while the spike is still UP — within this of the window's peak (not already recovering). */
    public const NEAR_PEAK_TOL = 15;
    private const WINDOW_MIN = 40;

    /**
     * Pure: is the recent glucose series an ACTIVE spike — elevated above range AND still near its peak
     * (so a walk now would help), rather than already falling back?
     *
     * @param  array<int,int|null>  $recent  the last ~40 min of mg/dL, oldest→newest
     */
    public static function isSpiking(array $recent, int $high): bool
    {
        $recent = array_values(array_filter($recent, fn ($v) => $v !== null));
        if (count($recent) < 2) {
            return false;
        }
        $latest = (int) end($recent);
        if ($latest < $high + self::SPIKE_MARGIN) {
            return false;   // not high enough to bother
        }

        // Still up (not clearly on the way back down): within tolerance of the window's peak.
        return $latest >= max($recent) - self::NEAR_PEAK_TOL;
    }

    /** The active spike for a profile from its recent readings, or null. (taken_at is app-tz; now() too.) */
    public static function active(Profile $profile): ?array
    {
        $rows = $profile->glucoseReadings()
            ->where('taken_at', '>=', Carbon::now()->subMinutes(self::WINDOW_MIN))
            ->orderBy('taken_at')->get(['taken_at', 'mg_dl']);
        if ($rows->count() < 2) {
            return null;   // no live data → nothing to nudge on
        }
        $vals = $rows->pluck('mg_dl')->map(fn ($v) => (int) $v)->all();
        if (! self::isSpiking($vals, GlucoseMetrics::RANGE_HIGH)) {
            return null;
        }
        $last = $rows->last();

        return ['mg_dl' => (int) $last->mg_dl, 'at' => $last->taken_at->toIso8601String()];
    }
}
