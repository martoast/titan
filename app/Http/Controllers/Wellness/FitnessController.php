<?php

namespace App\Http\Controllers\Wellness;

use App\Http\Controllers\Controller;
use App\Models\ActivitySession;
use Illuminate\Http\Request;

/**
 * Cardio fitness for the current profile: workout sessions sealed from the band (run/ride/walk
 * with TRIMP + calories), the run-calibrated VO2max estimate + its trend, and heart-rate
 * recovery. Sealed by {@see \App\Jobs\SealActivityJob}; strength training lives under Workouts.
 */
class FitnessController extends Controller
{
    public function index(Request $request)
    {
        $profile = $request->user()->ensureProfile();

        $sessions = $profile->activitySessions()
            ->orderByDesc('started_at')
            ->limit(40)
            ->get();

        $latestVo2 = $sessions->firstWhere(fn (ActivitySession $s) => $s->vo2max !== null);
        $latestHrr = $sessions->firstWhere(fn (ActivitySession $s) => $s->hrr_bpm !== null)?->hrr_bpm;

        // VO2max trend (oldest → newest) for a sparkline.
        $vo2Trend = $sessions
            ->filter(fn (ActivitySession $s) => $s->vo2max !== null)
            ->sortBy('started_at')
            ->map(fn (ActivitySession $s) => [
                'date' => $s->started_at->format('M j'),
                'vo2max' => (float) $s->vo2max,
            ])->values();

        // This-week training load (sum of TRIMP).
        $weekTrimp = $profile->activitySessions()
            ->where('started_at', '>=', now()->startOfWeek())
            ->sum('trimp');

        return view('fitness.index', [
            'profile' => $profile,
            'sessions' => $sessions,
            'latestVo2' => $latestVo2,
            'latestHrr' => $latestHrr,
            'vo2Trend' => $vo2Trend,
            'weekTrimp' => (float) $weekTrimp,
        ]);
    }
}
