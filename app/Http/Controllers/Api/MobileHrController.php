<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The native all-day HR graph: the 24/7 heart-rate trend for a day (default today), plus the day's
 * resting HR (a low percentile), min/max/avg. Fed by the band's `hr_trend` ingest (see
 * {@see \App\Services\Wearables\DeviceIngestionService::writeHrTrend}).
 */
class MobileHrController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $profile = $request->user()->profile ?? $request->user()->ensureProfile();
        $tz = config('app.timezone');

        $day = $request->query('date')
            ? Carbon::parse($request->query('date'), $tz)
            : Carbon::now($tz);
        $start = $day->copy()->startOfDay();
        $end = $day->copy()->endOfDay();

        $samples = $profile->hrSamples()
            ->whereBetween('recorded_at', [$start, $end])
            ->orderBy('recorded_at')
            ->get(['recorded_at', 'bpm', 'confidence']);

        // Defense-in-depth: the phone pre-buckets to ~1/min (~1440 rows/day), but any bridge that ever
        // forwards denser (a web bridge, an old build) could balloon this to tens of thousands of points.
        // Collapse to one point per minute (median bpm, max confidence) so the endpoint is bounded and the
        // graph stays light regardless of source cadence.
        if ($samples->count() > 1500) {
            $samples = $samples
                ->groupBy(fn ($s) => $s->recorded_at->copy()->startOfMinute()->timestamp)
                ->map(function ($group) {
                    $sorted = $group->pluck('bpm')->sort()->values();
                    $first = $group->first();
                    $first->bpm = (int) $sorted[intdiv($sorted->count(), 2)];
                    $first->confidence = $group->max('confidence');

                    return $first;
                })
                ->values();
        }

        $bpms = $samples->pluck('bpm')->sort()->values();
        $n = $bpms->count();

        // Resting HR ≈ the 5th percentile of the day's HR — robust to the odd low spike, and the
        // standard "your floor today" read. Null until there's enough to be meaningful.
        $resting = $n >= 5 ? (int) round($bpms[(int) floor($n * 0.05)]) : null;

        return response()->json([
            'date' => $day->toDateString(),
            'points' => $samples->map(fn ($s) => [
                't' => $s->recorded_at->timestamp,
                'bpm' => $s->bpm,
                'conf' => $s->confidence,
            ])->values(),
            'resting_hr' => $resting,
            'min' => $n ? $bpms->first() : null,
            'max' => $n ? $bpms->last() : null,
            'avg' => $n ? (int) round($samples->avg('bpm')) : null,
            'count' => $n,
        ]);
    }
}
