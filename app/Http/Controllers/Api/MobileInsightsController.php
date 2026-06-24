<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\InsightFeed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The native app's insight feed (Today screen) — the same ranked cards the coach's `insights` tool
 * returns: anomalies, goal progress, wins, behavior correlations.
 */
class MobileInsightsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $profile = $request->user()->profile ?? $request->user()->ensureProfile();

        return response()->json(['insights' => InsightFeed::build($profile)]);
    }
}
