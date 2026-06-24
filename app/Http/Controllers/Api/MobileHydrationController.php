<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Hydration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileHydrationController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(Hydration::today($this->profile($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['ml' => ['required', 'integer', 'min:1', 'max:5000']]);

        return response()->json(Hydration::add($this->profile($request), $data['ml']));
    }

    private function profile(Request $request)
    {
        return $request->user()->profile ?? $request->user()->ensureProfile();
    }
}
