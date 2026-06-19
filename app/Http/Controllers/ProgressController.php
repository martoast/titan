<?php

namespace App\Http\Controllers;

use App\Support\PhysiqueProgress;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Progress & trends -- the visual longitudinal view, built around the north star (the dream physique).
 * Shows how close they are to the goal, their week-score trajectory, and their bodyweight trend over
 * time. The deep, scrollable companion to the in-chat `physique` and `review` cards.
 */
class ProgressController extends Controller
{
    public function index(Request $request)
    {
        $profile = $request->user()->ensureProfile();

        $physique = class_exists(\App\Support\PhysiqueProgress::class) ? PhysiqueProgress::assess($profile) : null;

        // Week-score trajectory (last ~12 weeks).
        $weeks = class_exists(\App\Models\WeeklySnapshot::class)
            ? $profile->weeklySnapshots()->whereNotNull('score')->orderByDesc('week_start')->limit(12)->get(['week_start', 'score'])->reverse()->values()
            : collect();
        $weekScores = $weeks->map(fn ($w) => ['label' => Carbon::parse($w->week_start)->format('M j'), 'score' => (int) $w->score])->all();

        // Bodyweight trajectory (last ~90 days).
        $imperial = ($profile->settings['units'] ?? 'metric') === 'imperial';
        $weights = class_exists(\App\Models\BodyMetric::class)
            ? $profile->bodyMetrics()->whereNotNull('weight_kg')->where('taken_at', '>=', Carbon::now()->subDays(90))->orderBy('taken_at')->get(['taken_at', 'weight_kg'])
            : collect();
        $weightSeries = $weights->map(fn ($b) => [
            'label' => Carbon::parse($b->taken_at)->format('M j'),
            'value' => round($imperial ? (float) $b->weight_kg * 2.2046226 : (float) $b->weight_kg, 1),
        ])->all();

        return view('progress.index', [
            'physique' => $physique,
            'weekScores' => $weekScores,
            'weightSeries' => $weightSeries,
            'weightUnit' => $imperial ? 'lb' : 'kg',
        ]);
    }
}
