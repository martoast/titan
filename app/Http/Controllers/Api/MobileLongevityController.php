<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\BioAgePage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Native app: the Titan Longevity Index — biological (Titan) age, pace of aging, and the levers pulling
 * the user younger/older. Synthesis by {@see LongevityIndex} from data we already hold; null-safe when
 * there isn't enough for an honest estimate (the app shows an "unlock it" state, not a fake number).
 */
class MobileLongevityController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $profile = $request->user()->profile ?? $request->user()->ensureProfile();

        // The full transparent Bio Age page payload (superset of the old fields — the glanceable card still
        // reads titan_age/delta/levers; the page reads the enriched components + tips + methodology).
        $page = BioAgePage::forProfile($profile);

        if ($page === null) {
            return response()->json([
                'available' => false,
                'reason' => 'Needs a blood panel or a VO₂max/fitness read to estimate your Titan Age.',
            ]);
        }

        return response()->json(['available' => true] + $page);
    }
}
