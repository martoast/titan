<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Cycle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The native Cycle view (women) — read + LOG, like a dedicated period app. Returns the full
 * {@see Cycle::status} (phase, fertile window, predicted period/ovulation, and the conception
 * likelihood the app shows Flo-style as "chance of pregnancy today"), and lets you log a period
 * start/end and a day's flow + symptoms right from the page. Awareness only, never contraception.
 */
class MobileCycleController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $profile = $request->user()->profile ?? $request->user()->ensureProfile();

        if (! Cycle::available($profile)) {
            return response()->json(['available' => false]);
        }

        return response()->json([
            'available' => true,
            'cycle' => Cycle::status($profile),
            'symptoms' => Cycle::SYMPTOMS,
            'flows' => Cycle::FLOWS,
        ]);
    }

    /** Log a period start (day 1) or end — the input the whole view needs. */
    public function period(Request $request): JsonResponse
    {
        $profile = $request->user()->profile ?? $request->user()->ensureProfile();
        $data = $request->validate([
            'event' => ['nullable', 'in:start,end'],
            'date' => ['required', 'date', 'before_or_equal:today'],
        ]);

        if (($data['event'] ?? 'start') === 'end') {
            Cycle::endPeriod($profile, Carbon::parse($data['date']));
        } else {
            Cycle::startPeriod($profile, Carbon::parse($data['date']));
        }

        return response()->json(['ok' => true, 'cycle' => Cycle::status($profile)]);
    }

    /** A projected per-day calendar for the calendar view (plan ahead). */
    public function calendar(Request $request): JsonResponse
    {
        $profile = $request->user()->profile ?? $request->user()->ensureProfile();
        if (! Cycle::available($profile)) {
            return response()->json(['available' => false, 'days' => []]);
        }
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'days' => ['nullable', 'integer', 'min:1', 'max:62'],
        ]);
        $from = isset($data['from']) ? Carbon::parse($data['from']) : Carbon::now()->startOfMonth();

        return response()->json([
            'available' => true,
            'days' => Cycle::calendar($profile, $from, $data['days'] ?? 42),
        ]);
    }

    /** Log a day: flow, symptoms, mood/energy, BBT. */
    public function logDay(Request $request): JsonResponse
    {
        $profile = $request->user()->profile ?? $request->user()->ensureProfile();
        $data = $request->validate([
            'date' => ['required', 'date', 'before_or_equal:today'],
            'flow' => ['nullable', 'in:none,spotting,light,medium,heavy'],
            'symptoms' => ['nullable', 'array'],
            'symptoms.*' => ['string'],
            'mood' => ['nullable', 'integer', 'min:1', 'max:5'],
            'energy' => ['nullable', 'integer', 'min:1', 'max:5'],
            'bbt_c' => ['nullable', 'numeric', 'min:34', 'max:40'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        Cycle::logDay($profile, Carbon::parse($data['date']), $data);

        return response()->json(['ok' => true, 'cycle' => Cycle::status($profile)]);
    }
}
