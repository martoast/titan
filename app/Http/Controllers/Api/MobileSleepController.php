<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\SleepCoach;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The native Sleep view: last night's stages + the need/debt/performance read (SleepCoach) and a
 * short history of recent nights for the trend.
 */
class MobileSleepController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $profile = $request->user()->profile ?? $request->user()->ensureProfile();

        $nights = $profile->sleepLogs()->nights()->orderByDesc('slept_at')->orderByDesc('id')->limit(14)->get()
            ->map(fn ($s) => [
                'date' => $s->slept_at?->toDateString(),
                'duration_min' => $s->duration_min,
                'quality' => $s->quality,
                'deep_min' => $s->deep_min,
                'rem_min' => $s->rem_min,
                'light_min' => $s->light_min,
                'awake_min' => $s->awake_min,
                // Progressive summary: 'computing' ⇒ render the loading card (duration/bed/wake are already
                // real; stages fill in when it flips to 'final'). coverage = how complete the sampling was.
                'stage_status' => $s->stage_status,
                'coverage' => $s->coverage,
                // Low-signal night (mostly-NODATA coverage or an implausible stage split): the app shows this
                // as an ESTIMATE + a fit-check hint, never a confident number.
                'low_confidence' => (bool) $s->low_confidence,
                'bedtime' => $s->bedtime,
                'wake_time' => $s->wake_time,
            ])->values();

        return response()->json([
            'assess' => SleepCoach::assess($profile),   // need_h, debt_h, last_h, performance_pct, band, label, advice
            // The Whoop-style breakdown: performance %, hours vs need, stages (min + %), efficiency,
            // restorative (deep+REM), debt, respiratory rate, consistency.
            'detail' => \App\Support\SleepDetail::forProfile($profile),
            // Sleep-as-sessions: TODAY's every sleep session (overnight + each nap), each with its own full
            // detail + v2 timeline, above a daily aggregate (total asleep + combined stages). Grouped on the
            // user's local day so a nap isn't dropped or mis-dated. This is what the sessions list + today
            // card render; `detail`/`nights` above stay for the existing single-night surfaces.
            'today' => \App\Support\SleepDetail::sessionsForDay($profile),
            // The most recent night's compute state — the app shows a loading card while this is 'computing'
            // and swaps to the full stats in place when it becomes 'final'.
            'last_status' => $nights->first()['stage_status'] ?? null,
            'nights' => $nights,
            // The sleep-debt ledger (accrues + pays down) — the Debt card's balance gauge, trend line, and
            // payback plan. One source of truth (same SleepDebt the coach + week view read).
            'debt' => class_exists(\App\Support\SleepDebt::class) ? \App\Support\SleepDebt::forProfile($profile) : null,
            // The week-at-a-glance: 7-night row + cumulative score + streak + heat strip + the weekly tip.
            // Resilient — a week-agg failure must never blank the Sleep screen.
            'week' => class_exists(\App\Support\SleepWeek::class)
                ? rescue(fn () => \App\Support\SleepWeek::forProfile($profile, (string) $request->query('tz', config('app.timezone', 'UTC'))), null, false)
                : null,
        ]);
    }

    /**
     * One night's full detail by local date (the Sleep Week per-night tap → the hero timeline). Reuses the
     * same SleepDetail the main screen renders — story (with cycle_boundaries), hypnogram, and the v2 HR /
     * motion series — so the tapped night looks identical to "last night". `SleepDetail::forProfile` picks
     * the most recent night on/before the date, which for a real logged night IS that night.
     */
    public function night(Request $request): JsonResponse
    {
        $profile = $request->user()->profile ?? $request->user()->ensureProfile();
        $day = null;
        if ($date = $request->query('date')) {
            $day = rescue(fn () => Carbon::parse($date), null, false);
        }

        return response()->json([
            'detail' => \App\Support\SleepDetail::forProfile($profile, $day),
        ]);
    }
}
