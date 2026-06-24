<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Journal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The native app's behavior journal — quick toggles that feed the correlation engine. GET the
 * catalog + today's logged factors; POST to add/remove. Same store the coach's log_behavior writes.
 */
class MobileJournalController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $profile = $request->user()->profile ?? $request->user()->ensureProfile();
        $date = $this->date($request, $profile);

        return response()->json([
            'date' => $date,
            'catalog' => $this->catalog(),
            'logged' => Journal::forDate($profile, $date),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'add' => ['sometimes', 'array'],
            'add.*' => ['string'],
            'remove' => ['sometimes', 'array'],
            'remove.*' => ['string'],
            'date' => ['sometimes', 'date'],
        ]);
        $profile = $request->user()->profile ?? $request->user()->ensureProfile();
        $date = $this->date($request, $profile);

        $logged = Journal::log($profile, $date, $data['add'] ?? [], $data['remove'] ?? []);

        return response()->json(['date' => $date, 'logged' => $logged]);
    }

    private function date(Request $request, $profile): string
    {
        return $request->input('date')
            ? \Illuminate\Support\Carbon::parse($request->input('date'))->toDateString()
            : Journal::today($profile);
    }

    /** @return array<int,array<string,string>> */
    private function catalog(): array
    {
        $out = [];
        foreach (Journal::CATALOG as $key => [$label, $polarity, $category]) {
            $out[] = ['key' => $key, 'label' => $label, 'polarity' => $polarity, 'category' => $category];
        }

        return $out;
    }
}
