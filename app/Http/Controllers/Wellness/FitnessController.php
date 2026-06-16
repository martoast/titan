<?php

namespace App\Http\Controllers\Wellness;

use App\Http\Controllers\Controller;
use App\Models\ActivitySession;
use App\Models\DailyActivity;
use App\Support\MovementBreaks;
use App\Support\StepGoal;
use App\Support\TrainingLoad;
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

        // Training-load guardrail: acute:chronic workload ratio over the last ~6 weeks.
        $trainingLoad = TrainingLoad::assess(
            $profile->activitySessions()->where('started_at', '>=', now()->subDays(42))->get()
        );

        // --- Daily movement: today's steps vs the evidence-based personalized target ---
        $today = $profile->dailyActivity()->whereDate('date', Carbon::today())->first();
        $stepTarget = StepGoal::targetFor($profile);
        $steps = (int) ($today->steps ?? 0);
        $stepGoal = StepGoal::assess($steps, $stepTarget);
        $movement = MovementBreaks::assess($today?->hourly);

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
            'trainingLoad' => $trainingLoad,
            'steps' => $steps,
            'floorsToday' => (int) ($today->floors ?? 0),
            'stepGoal' => $stepGoal,
            'stepTrend' => $stepTrend,
            'weekAvgSteps' => $weekAvgSteps,
            'movement' => $movement,
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

    /** Score a guided 30-second chair-stand test (reps entered or wearable-counted) against age norms. */
    public function chairStand(Request $request)
    {
        $profile = $request->user()->ensureProfile();
        $data = $request->validate(['reps' => ['required', 'integer', 'min:0', 'max:60']]);

        $score = \App\Support\ChairStand::scoreFor($profile, $data['reps']);
        if ($score === null) {
            return redirect()->route('fitness.index')
                ->withErrors(['reps' => 'Add your birthdate in your profile to score against age norms.']);
        }

        return redirect()->route('fitness.index')->with('chairStand', $score);
    }
}
