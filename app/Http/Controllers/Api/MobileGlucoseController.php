<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\GlucoseConnection;
use App\Support\GlucoseDay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Native-app continuous-glucose surface (CGM_INTEGRATION P1): a day's curve + headline metrics + the
 * connection status, and a connect endpoint (Nightscout URL+token / HealthKit). Wellness, not medical —
 * Titan visualizes the user's own device data. The Nightscout token is stored encrypted, never returned.
 */
class MobileGlucoseController extends Controller
{
    /** GET /me/glucose?date=yyyy-MM-dd — the day's glucose curve + summary + status. */
    public function show(Request $request): JsonResponse
    {
        $profile = $request->user()->profile ?? $request->user()->ensureProfile();
        $date = $request->query('date');

        return response()->json(GlucoseDay::forProfile($profile, is_string($date) ? $date : null));
    }

    /** POST /me/glucose/connect — set the CGM source (Nightscout URL+token, or HealthKit). */
    public function connect(Request $request): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['required', Rule::in(['nightscout', 'healthkit'])],
            'nightscout_url' => ['nullable', 'url', 'max:255', 'required_if:provider,nightscout'],
            'nightscout_token' => ['nullable', 'string', 'max:255'],
            'enabled' => ['nullable', 'boolean'],
        ]);
        $profile = $request->user()->profile ?? $request->user()->ensureProfile();

        GlucoseConnection::set(
            $profile,
            $data['provider'],
            $data['nightscout_url'] ?? null,
            $data['nightscout_token'] ?? null,   // encrypted inside; never echoed back
            $data['enabled'] ?? true,
        );

        return response()->json(GlucoseDay::forProfile($profile));
    }
}
