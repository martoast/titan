<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\StressMonitor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Native app: the current real-time stress read + the stress-over-day strip. The `now` block powers the
 * live gauge + the "take a minute to breathe" CTA; `strip` draws the day curve (like the sleep movement
 * strip). All derived by {@see StressMonitor} from hr_samples + motion_samples — no new hardware.
 */
class MobileStressController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $profile = $request->user()->profile ?? $request->user()->ensureProfile();
        $tz = (string) $request->query('tz', config('app.timezone', 'UTC'));

        $now = StressMonitor::assess($profile, null, $tz);
        $strip = StressMonitor::dayStrip($profile, null, $tz);

        return response()->json([
            'now' => [
                'stress' => $now['stress'],
                'level' => $now['level'],
                'moving' => $now['moving'],
                'drivers' => $now['drivers'],
                'confidence' => $now['confidence']['level'],
                'note' => $now['confidence']['note'],
                'hr' => $now['hr'],
                'rest' => $now['rest'],
                'at' => $now['at'],
            ],
            'strip' => $strip['points'],
            'peak' => $strip['peak'],
            'high_minutes' => $strip['high_minutes'],
        ]);
    }
}
