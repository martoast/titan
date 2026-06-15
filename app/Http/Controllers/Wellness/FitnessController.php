<?php

namespace App\Http\Controllers\Wellness;

use App\Http\Controllers\Controller;
use App\Models\ActivitySession;
use App\Models\DailyActivity;
use App\Support\StepGoal;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

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

        // --- Daily movement: today's steps vs the evidence-based personalized target ---
        $today = $profile->dailyActivity()->whereDate('date', Carbon::today())->first();
        $stepTarget = StepGoal::targetFor($profile);
        $steps = (int) ($today->steps ?? 0);
        $stepGoal = StepGoal::assess($steps, $stepTarget);

        $stepWeek = $profile->dailyActivity()
            ->where('date', '>=', Carbon::today()->subDays(6))
            ->orderBy('date')->get();
        $stepTrend = $stepWeek->map(fn (DailyActivity $d) => [
            'date' => $d->date->format('D'),
            'steps' => (int) $d->steps,
        ])->values();
        $weekAvgSteps = $stepWeek->count() ? (int) round($stepWeek->avg('steps')) : null;

        return view('fitness.index', [
            'profile' => $profile,
            'sessions' => $sessions,
            'latestVo2' => $latestVo2,
            'latestHrr' => $latestHrr,
            'vo2Trend' => $vo2Trend,
            'weekTrimp' => (float) $weekTrimp,
            'steps' => $steps,
            'stepGoal' => $stepGoal,
            'stepTrend' => $stepTrend,
            'weekAvgSteps' => $weekAvgSteps,
        ]);
    }

    /** Manual quick-log of today's step count (for users without a device feeding it). */
    public function logSteps(Request $request)
    {
        $profile = $request->user()->ensureProfile();
        $data = $request->validate(['steps' => ['required', 'integer', 'min:0', 'max:200000']]);

        $profile->dailyActivity()->updateOrCreate(
            ['date' => Carbon::today()],
            ['steps' => $data['steps'], 'source' => 'manual', 'updated_via' => 'manual'],
        );

        return redirect()->route('fitness.index')->with('status', 'Steps updated.');
    }
}
