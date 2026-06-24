<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Onboarding\OnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Edit the profile info collected at onboarding (identity, goal, coaching style, training/nutrition
 * profile, cycle, meals). Reuses OnboardingService — partial edits, no duplicate starting weight.
 */
class MobileProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $profile = $request->user()->profile ?? $request->user()->ensureProfile();

        return response()->json([
            'profile' => OnboardingService::snapshot($profile),
            'goals' => OnboardingService::GOALS,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $profile = $request->user()->profile ?? $request->user()->ensureProfile();
        $data = $request->validate(OnboardingService::partialRules());

        app(OnboardingService::class)->updateProfile($request->user(), $profile, $data);

        return response()->json(['ok' => true, 'profile' => OnboardingService::snapshot($profile->fresh())]);
    }
}
