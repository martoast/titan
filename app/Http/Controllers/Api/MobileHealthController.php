<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Health\HealthIngestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Live Apple HealthKit sync from the native app. The phone reads the user's Health data, aggregates
 * per-day, and POSTs it here → upserted into Titan's models so the whole engine works without a band.
 */
class MobileHealthController extends Controller
{
    public function __construct(protected HealthIngestService $health) {}

    public function status(Request $request): JsonResponse
    {
        return response()->json($this->health->status($this->profile($request)));
    }

    public function ingest(Request $request): JsonResponse
    {
        $request->validate([
            'recovery' => ['sometimes', 'array'],
            'sleep' => ['sometimes', 'array'],
            'activity' => ['sometimes', 'array'],
            'body' => ['sometimes', 'array'],
            'workouts' => ['sometimes', 'array'],
            'vo2max' => ['sometimes', 'numeric'],
        ]);

        $counts = $this->health->ingest($this->profile($request), $request->all());

        return response()->json(['ok' => true, 'counts' => $counts, 'synced_at' => now()->toIso8601String()]);
    }

    private function profile(Request $request)
    {
        return $request->user()->profile ?? $request->user()->ensureProfile();
    }
}
