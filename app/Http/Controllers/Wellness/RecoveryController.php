<?php

namespace App\Http\Controllers\Wellness;

use App\Http\Controllers\Controller;
use App\Models\RecoveryLog;
use App\Support\Readiness;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

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

        // §5 readiness: ln-RMSSD vs rolling baseline + inverted RHR + sleep.
        $readiness = Readiness::compute($profile, $latest?->logged_at);

        // HRV / RHR baselines (excluding today) so the cards can show "vs baseline" deltas.
        $hrvHistory = $recent->whereNotNull('hrv_ms');
        $rhrHistory = $recent->whereNotNull('resting_hr');
        $hrvBaseline = $hrvHistory->count() ? (int) round($hrvHistory->avg('hrv_ms')) : null;
        $rhrBaseline = $rhrHistory->count() ? (int) round($rhrHistory->avg('resting_hr')) : null;

        // Provenance: did the wearable pipeline write the latest objective row?
        $fromWearable = $latest && str_starts_with((string) $latest->updated_via, 'biosignal');
        $sealed = $latest && $latest->updated_via === 'biosignal:sealed';

        return view('recovery.index', [
            'profile' => $profile,
            'latest' => $latest,
            'trend' => $trend,
            'readiness' => $readiness['score'],
            'readinessLabel' => $readiness['label'],
            'readinessNote' => $readiness['note'],
            'readinessProvisional' => $readiness['provisional'],
            'readinessComponents' => $readiness['components'],
            'lastSleep' => $lastSleep,
            'hrvBaseline' => $hrvBaseline,
            'rhrBaseline' => $rhrBaseline,
            'fromWearable' => $fromWearable,
            'sealed' => $sealed,
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
}
