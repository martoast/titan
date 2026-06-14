<?php

namespace App\Http\Controllers\Wellness;

use App\Http\Controllers\Controller;
use App\Models\RecoveryLog;
use App\Models\SleepLog;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Recovery / stress tracking for the current profile: latest HRV / resting HR /
 * subjective signals, 14-day trends, manual logging, and a computed readiness score.
 */
class RecoveryController extends Controller
{
    public function index(Request $request)
    {
        $profile = $request->user()->ensureProfile();

        $latest = $profile->recoveryLogs()->orderByDesc('logged_at')->orderByDesc('id')->first();

        $recent = $profile->recoveryLogs()
            ->where('logged_at', '>=', Carbon::today()->subDays(13))
            ->orderBy('logged_at')
            ->get();

        $trend = $recent->map(fn (RecoveryLog $r) => [
            'date' => $r->logged_at->format('M j'),
            'hrv' => $r->hrv_ms,
            'rhr' => $r->resting_hr,
            'stress' => $r->stress,
            'soreness' => $r->soreness,
            'mood' => $r->mood,
            'energy' => $r->energy,
        ])->values();

        $lastSleep = $profile->sleepLogs()->orderByDesc('slept_at')->orderByDesc('id')->first();

        $readiness = $this->readiness($profile->recoveryLogs()->orderByDesc('logged_at')->limit(14)->get(), $latest, $lastSleep);

        return view('recovery.index', [
            'profile' => $profile,
            'latest' => $latest,
            'trend' => $trend,
            'readiness' => $readiness['score'],
            'readinessLabel' => $readiness['label'],
            'readinessNote' => $readiness['note'],
            'lastSleep' => $lastSleep,
        ]);
    }

    public function store(Request $request)
    {
        $profile = $request->user()->ensureProfile();

        $data = $request->validate([
            'logged_at' => ['required', 'date', 'before_or_equal:today'],
            'hrv_ms' => ['nullable', 'integer', 'min:1', 'max:400'],
            'resting_hr' => ['nullable', 'integer', 'min:20', 'max:200'],
            'stress' => ['nullable', 'integer', 'min:1', 'max:10'],
            'soreness' => ['nullable', 'integer', 'min:1', 'max:10'],
            'mood' => ['nullable', 'integer', 'min:1', 'max:10'],
            'energy' => ['nullable', 'integer', 'min:1', 'max:10'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $profile->recoveryLogs()->create([
            'logged_at' => $data['logged_at'],
            'hrv_ms' => $data['hrv_ms'] ?? null,
            'resting_hr' => $data['resting_hr'] ?? null,
            'stress' => $data['stress'] ?? null,
            'soreness' => $data['soreness'] ?? null,
            'mood' => $data['mood'] ?? null,
            'energy' => $data['energy'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        return redirect('/recovery')->with('status', 'Recovery logged.');
    }

    /**
     * Basic readiness heuristic (0-100). Blends:
     *  - today's HRV vs the recent (up to 14-day) baseline (higher = better),
     *  - last night's sleep duration (8h ≈ ideal),
     *  - subjective soreness and stress (lower = better).
     * Each component degrades gracefully when its data is missing.
     *
     * @param  Collection<int,RecoveryLog>  $history
     */
    private function readiness(Collection $history, ?RecoveryLog $latest, ?SleepLog $lastSleep): array
    {
        $components = [];

        // --- HRV relative to baseline (40% weight) ---
        $hrvVals = $history->whereNotNull('hrv_ms')->pluck('hrv_ms');
        if ($latest && $latest->hrv_ms && $hrvVals->count() >= 2) {
            $baseline = $hrvVals->avg();
            // Ratio of today vs baseline, mapped so 1.0 == 70, +/-30% spans the range.
            $ratio = $baseline > 0 ? $latest->hrv_ms / $baseline : 1;
            $hrvScore = 70 + ($ratio - 1) * 100;
            $components['hrv'] = ['score' => $this->clamp($hrvScore), 'weight' => 0.40];
        }

        // --- Sleep duration (35% weight) ---
        if ($lastSleep && $lastSleep->duration_min) {
            $hours = $lastSleep->duration_min / 60;
            // Peak at 8h; lose ~12.5 points per hour away from ideal.
            $sleepScore = 100 - abs($hours - 8) * 12.5;
            $components['sleep'] = ['score' => $this->clamp($sleepScore), 'weight' => 0.35];
        }

        // --- Subjective soreness + stress (25% weight) ---
        $subjParts = [];
        if ($latest && $latest->soreness) {
            $subjParts[] = (10 - $latest->soreness) / 9 * 100; // 1 sore -> 100, 10 -> 0
        }
        if ($latest && $latest->stress) {
            $subjParts[] = (10 - $latest->stress) / 9 * 100;
        }
        if ($subjParts) {
            $components['subjective'] = ['score' => $this->clamp(array_sum($subjParts) / count($subjParts)), 'weight' => 0.25];
        }

        if (empty($components)) {
            return ['score' => null, 'label' => 'No data', 'note' => 'Log HRV, sleep or soreness to compute readiness.'];
        }

        // Re-normalise weights over whatever components we actually have.
        $totalWeight = array_sum(array_column($components, 'weight'));
        $score = 0;
        foreach ($components as $c) {
            $score += $c['score'] * ($c['weight'] / $totalWeight);
        }
        $score = (int) round($this->clamp($score));

        [$label, $note] = match (true) {
            $score >= 80 => ['Primed', 'Your body is well recovered — a good day to push hard training.'],
            $score >= 60 => ['Ready', 'Solid recovery. Train as planned and stay on top of sleep.'],
            $score >= 40 => ['Moderate', 'Partial recovery. Keep intensity in check or favour technique work.'],
            $score >= 20 => ['Strained', 'Recovery is low. Prioritise sleep, nutrition and a lighter session.'],
            default => ['Depleted', 'Your body needs rest. Consider an active-recovery or off day.'],
        };

        return ['score' => $score, 'label' => $label, 'note' => $note];
    }

    private function clamp(float $n): float
    {
        return max(0, min(100, $n));
    }
}
