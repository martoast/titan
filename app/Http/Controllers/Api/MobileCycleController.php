<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Cycle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The native Cycle view (women): the full {@see Cycle::status} snapshot — cycle day, phase, the
 * fertile window, predicted period/ovulation, and the conception likelihood that the app surfaces
 * Flo-style as "chance of pregnancy today". Wellness/awareness only, never a contraceptive method.
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
        ]);
    }
}
