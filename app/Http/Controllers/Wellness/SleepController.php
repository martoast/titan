<?php

namespace App\Http\Controllers\Wellness;

use App\Http\Controllers\Controller;
use App\Models\SleepLog;
use App\Support\CircadianRhythm;
use App\Support\SleepRegularity;
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

        // "Nights" and naps are different beasts: nights drive the summary / trends / averages / SRI;
        // naps are bounded daytime sessions that only add to today's total. Keep them separate.
        $latest = $profile->sleepLogs()->where('is_nap', false)->orderByDesc('slept_at')->orderByDesc('id')->first();

        // Pull the last 14 nights for the trend, oldest -> newest for charting.
        $recent = $profile->sleepLogs()
            ->where('is_nap', false)
            ->where('slept_at', '>=', Carbon::today()->subDays(13))
            ->orderBy('slept_at')
            ->get();

        // --- Today, at a glance: last night's manual entry + any naps, which add to the total ---
        $todayNight = $profile->sleepLogs()->where('is_nap', false)
            ->whereDate('slept_at', Carbon::today())->orderByDesc('id')->first();
        $todayNaps = $profile->sleepLogs()->where('is_nap', true)
            ->whereDate('slept_at', Carbon::today())->orderBy('session_start')->orderBy('id')->get();
        $napMin = (int) $todayNaps->sum('duration_min');
        $totalTodayMin = (int) ($todayNight?->duration_min ?? 0) + $napMin;

        $trend = $recent->map(fn (SleepLog $s) => [
            'date' => $s->slept_at->format('M j'),
            'hours' => round($s->duration_min / 60, 2),
            'quality' => $s->quality,
        ])->values();

        // 7-day average from the most recent 7 logged nights.
        $last7 = $profile->sleepLogs()->where('is_nap', false)->orderByDesc('slept_at')->orderByDesc('id')->limit(7)->get();
        $avgDuration = $last7->count() ? (int) round($last7->avg('duration_min')) : null;
        $qualityVals = $last7->whereNotNull('quality');
        $avgQuality = $qualityVals->count() ? (int) round($qualityVals->avg('quality')) : null;

        // Sleep Regularity Index over the last ~4 weeks of timed nights (mortality predictor; §08 research).
        $month = $profile->sleepLogs()->where('is_nap', false)->where('slept_at', '>=', Carbon::today()->subDays(27))->get();
        $regularity = SleepRegularity::compute($month);

        // Circadian rest-activity rhythm over the last ~2 weeks of hourly-profiled days (Feng 2023).
        $activityDays = $profile->dailyActivity()->where('date', '>=', Carbon::today()->subDays(13))->get();
        $circadian = CircadianRhythm::compute($activityDays);

        return view('sleep.index', [
            'profile' => $profile,
            'latest' => $latest,
            'trend' => $trend,
            'regularity' => $regularity,
            'circadian' => $circadian,
            'avgDuration' => $avgDuration,
            'avgDurationLabel' => $avgDuration !== null
                ? intdiv($avgDuration, 60).'h '.str_pad((string) ($avgDuration % 60), 2, '0', STR_PAD_LEFT).'m'
                : null,
            'avgQuality' => $avgQuality,
            'count7' => $last7->count(),
            'fromWearable' => $latest && str_starts_with((string) $latest->updated_via, 'biosignal'),
            // Effortless manual logging (for people without a band): last night + today's naps.
            'todayNight' => $todayNight,
            'todayNaps' => $todayNaps,
            'napMin' => $napMin,
            'totalTodayMin' => $totalTodayMin,
            'baselineH' => round(\App\Support\SleepCoach::baselineFor($profile), 1),
        ]);
    }

    /** Quick-log last night's hours (water-style). Upserts so re-tapping just adjusts today's night. */
    public function night(Request $request)
    {
        $data = $request->validate(['hours' => ['required', 'numeric', 'min:0', 'max:24']]);
        $duration = (int) round((float) $data['hours'] * 60);

        if ($duration < 1) {
            return back()->withErrors(['hours' => 'Enter how long you slept.']);
        }

        $request->user()->ensureProfile()->sleepLogs()->updateOrCreate(
            ['slept_at' => Carbon::today()->toDateString(), 'is_nap' => false],
            ['duration_min' => $duration, 'updated_via' => 'manual'],
        );

        return back()->with('status', 'Sleep updated.');
    }

    /** Add a nap — a bounded daytime session that adds to today's sleep total. */
    public function nap(Request $request)
    {
        $data = $request->validate(['minutes' => ['required', 'integer', 'min:5', 'max:360']]);

        $request->user()->ensureProfile()->sleepLogs()->create([
            'slept_at' => Carbon::today()->toDateString(),
            'is_nap' => true,
            'session_start' => now(),
            'duration_min' => $data['minutes'],
            'updated_via' => 'manual',
        ]);

        return back()->with('status', 'Nap added.');
    }

    /** Remove one of today's naps (only your own naps). */
    public function removeNap(Request $request, SleepLog $sleepLog)
    {
        abort_unless(
            $sleepLog->is_nap && $sleepLog->profile_id === $request->user()->ensureProfile()->id,
            403,
        );
        $sleepLog->delete();

        return back()->with('status', 'Nap removed.');
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
