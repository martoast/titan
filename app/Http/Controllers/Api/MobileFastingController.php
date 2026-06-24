<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Fasting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileFastingController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(Fasting::card($this->profile($request)));
    }

    public function start(Request $request): JsonResponse
    {
        $data = $request->validate(['goal_hours' => ['sometimes', 'numeric', 'min:1', 'max:72']]);
        Fasting::start($this->profile($request), $data['goal_hours'] ?? null);

        return response()->json(Fasting::card($this->profile($request)));
    }

    public function end(Request $request): JsonResponse
    {
        Fasting::end($this->profile($request));

        return response()->json(Fasting::card($this->profile($request)));
    }

    private function profile(Request $request)
    {
        return $request->user()->profile ?? $request->user()->ensureProfile();
    }
}
