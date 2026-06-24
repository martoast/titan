<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Onboarding\OnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Native-app first-run onboarding — same intake → profile engine as the web wizard
 * (OnboardingService), so the coach knows the user from message one regardless of platform.
 */
class MobileOnboardingController extends Controller
{
    /** Whether the user still needs onboarding + the option lists for the wizard UI. */
    public function status(Request $request): JsonResponse
    {
        $profile = $request->user()->profile ?? $request->user()->ensureProfile();

        return response()->json([
            'onboarded' => $profile->isOnboarded(),
            'goals' => OnboardingService::GOALS,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $profile = $request->user()->profile ?? $request->user()->ensureProfile();
        $data = $request->validate(OnboardingService::rules());

        $res = app(OnboardingService::class)->apply($request->user(), $profile, $data);

        return response()->json(['ok' => true, 'onboarded' => true, 'route' => $res['route'], 'name' => $res['name']]);
    }
}
