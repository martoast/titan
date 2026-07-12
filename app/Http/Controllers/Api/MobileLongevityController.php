<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\LongevityIndex;
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
        $l = LongevityIndex::assess($profile);

        if ($l === null) {
            return response()->json([
                'available' => false,
                'reason' => 'Needs a blood panel or a VO₂max/fitness read to estimate your Titan Age.',
            ]);
        }

        return response()->json([
            'available' => true,
            'titan_age' => $l['titan_age'],
            'chronological_age' => $l['chronological_age'],
            'delta' => $l['delta'],
            'band' => $l['band'],
            'label' => $l['label'],
            'confidence' => $l['confidence'],
            'partial' => $l['partial'],
            'pace' => $l['pace'],
            'younger_levers' => $l['younger_levers'],
            'older_levers' => $l['older_levers'],
        ]);
    }
}
