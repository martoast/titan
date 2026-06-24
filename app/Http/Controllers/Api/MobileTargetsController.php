<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Profile;
use App\Support\TargetSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Editable daily targets for the native app — macros + nightly sleep hours. The same TargetSettings
 * store the coach's set_targets tool writes, so editing here or by chat lands in one place and the
 * macro rings + protein/sleep nudges update together.
 */
class MobileTargetsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['targets' => TargetSettings::resolve($this->profile($request))]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'calories' => ['sometimes', 'integer', 'min:500', 'max:12000'],
            'protein_g' => ['sometimes', 'integer', 'min:0', 'max:500'],
            'carbs_g' => ['sometimes', 'integer', 'min:0', 'max:1500'],
            'fat_g' => ['sometimes', 'integer', 'min:0', 'max:500'],
            'sleep_h' => ['sometimes', 'numeric', 'min:4', 'max:12'],
        ]);

        return response()->json(['targets' => TargetSettings::update($this->profile($request), $data)]);
    }

    /** Reset to smart auto targets (recalculated from bodyweight / goal / age). */
    public function reset(Request $request): JsonResponse
    {
        return response()->json(['targets' => TargetSettings::reset($this->profile($request))]);
    }

    private function profile(Request $request): Profile
    {
        return $request->user()->profile ?? $request->user()->ensureProfile();
    }
}
