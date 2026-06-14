<?php

namespace App\Http\Controllers\Wellness;

use App\Http\Controllers\Controller;
use App\Models\SleepLog;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Sleep tracking for the current profile: last-night summary, 14-day duration +
 * quality trends, a 7-day average, and manual logging.
 */
class SleepController extends Controller
{
    public function index(Request $request)
    {
        $profile = $request->user()->ensureProfile();

        $latest = $profile->sleepLogs()->orderByDesc('slept_at')->orderByDesc('id')->first();

        // Pull the last 14 nights for the trend, oldest -> newest for charting.
        $recent = $profile->sleepLogs()
            ->where('slept_at', '>=', Carbon::today()->subDays(13))
            ->orderBy('slept_at')
            ->get();

        $trend = $recent->map(fn (SleepLog $s) => [
            'date' => $s->slept_at->format('M j'),
            'hours' => round($s->duration_min / 60, 2),
            'quality' => $s->quality,
        ])->values();

        // 7-day average from the most recent 7 logged nights.
        $last7 = $profile->sleepLogs()->orderByDesc('slept_at')->orderByDesc('id')->limit(7)->get();
        $avgDuration = $last7->count() ? (int) round($last7->avg('duration_min')) : null;
        $qualityVals = $last7->whereNotNull('quality');
        $avgQuality = $qualityVals->count() ? (int) round($qualityVals->avg('quality')) : null;

        return view('sleep.index', [
            'profile' => $profile,
            'latest' => $latest,
            'trend' => $trend,
            'avgDuration' => $avgDuration,
            'avgDurationLabel' => $avgDuration !== null
                ? intdiv($avgDuration, 60).'h '.str_pad((string) ($avgDuration % 60), 2, '0', STR_PAD_LEFT).'m'
                : null,
            'avgQuality' => $avgQuality,
            'count7' => $last7->count(),
        ]);
    }

    public function store(Request $request)
    {
        $profile = $request->user()->ensureProfile();

        $data = $request->validate([
            'slept_at' => ['required', 'date', 'before_or_equal:today'],
            'hours' => ['required', 'integer', 'min:0', 'max:24'],
            'minutes' => ['nullable', 'integer', 'min:0', 'max:59'],
            'quality' => ['nullable', 'integer', 'min:1', 'max:100'],
            'deep_min' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'rem_min' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'light_min' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'awake_min' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'bedtime' => ['nullable', 'date_format:H:i'],
            'wake_time' => ['nullable', 'date_format:H:i'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $duration = (int) $data['hours'] * 60 + (int) ($data['minutes'] ?? 0);

        if ($duration < 1) {
            return back()->withErrors(['hours' => 'Sleep duration must be greater than zero.'])->withInput();
        }

        $profile->sleepLogs()->create([
            'slept_at' => $data['slept_at'],
            'duration_min' => $duration,
            'quality' => $data['quality'] ?? null,
            'deep_min' => $data['deep_min'] ?? null,
            'rem_min' => $data['rem_min'] ?? null,
            'light_min' => $data['light_min'] ?? null,
            'awake_min' => $data['awake_min'] ?? null,
            'bedtime' => $data['bedtime'] ?? null,
            'wake_time' => $data['wake_time'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        return redirect('/sleep')->with('status', 'Sleep logged.');
    }
}
