<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Community\LeaderboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The leaderboard: you + the people you follow, ranked by effort / distance / points over a window. */
class LeaderboardController extends Controller
{
    public function index(Request $request, LeaderboardService $boards): JsonResponse
    {
        $p = $request->user()->profile ?? $request->user()->ensureProfile();

        return response()->json($boards->build(
            $p,
            (string) $request->query('metric', 'effort'),
            (string) $request->query('window', 'week'),
        ));
    }
}
