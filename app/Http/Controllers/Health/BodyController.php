<?php

namespace App\Http\Controllers\Health;

use App\Http\Controllers\Controller;
use App\Models\BodyMetric;
use Illuminate\Http\Request;

/**
 * Body composition: weight + body-fat trends, latest tape measurements, manual entry.
 */
class BodyController extends Controller
{
    /** /body — trend charts, latest snapshot, and the add form. */
    public function index(Request $request)
    {
        $profile = $request->user()->ensureProfile();

        $metrics = $profile->bodyMetrics()
            ->orderBy('taken_at')
            ->orderBy('id')
            ->get();

        // Latest non-null value for each measure (rows may be partial).
        $latest = [];
        foreach (['weight_kg', 'body_fat_pct', 'waist_cm', 'chest_cm', 'arm_cm', 'thigh_cm'] as $field) {
            $row = $metrics->whereNotNull($field)->last();
            $latest[$field] = $row?->{$field};
        }

        $series = [
            'weight' => $metrics->whereNotNull('weight_kg')->map(fn ($m) => [
                'date' => $m->taken_at->format('Y-m-d'),
                'value' => (float) $m->weight_kg,
            ])->values()->all(),
            'bodyfat' => $metrics->whereNotNull('body_fat_pct')->map(fn ($m) => [
                'date' => $m->taken_at->format('Y-m-d'),
                'value' => (float) $m->body_fat_pct,
            ])->values()->all(),
        ];

        return view('body.index', [
            'profile' => $profile,
            'metrics' => $metrics->sortByDesc('taken_at')->values(),
            'latest' => $latest,
            'series' => $series,
        ]);
    }

    public function store(Request $request)
    {
        $profile = $request->user()->ensureProfile();

        $data = $request->validate([
            'weight_kg' => ['nullable', 'numeric', 'min:0', 'max:500'],
            'body_fat_pct' => ['nullable', 'numeric', 'min:0', 'max:75'],
            'waist_cm' => ['nullable', 'numeric', 'min:0', 'max:300'],
            'chest_cm' => ['nullable', 'numeric', 'min:0', 'max:300'],
            'arm_cm' => ['nullable', 'numeric', 'min:0', 'max:150'],
            'thigh_cm' => ['nullable', 'numeric', 'min:0', 'max:200'],
            'taken_at' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        // Require at least one measure so we don't store empty rows.
        $measures = ['weight_kg', 'body_fat_pct', 'waist_cm', 'chest_cm', 'arm_cm', 'thigh_cm'];
        if (! collect($measures)->contains(fn ($f) => filled($data[$f] ?? null))) {
            return back()->withErrors(['weight_kg' => 'Enter at least one measurement.'])->withInput();
        }

        $profile->bodyMetrics()->create($data);

        return back()->with('status', 'Measurement saved.');
    }

    public function destroy(Request $request, BodyMetric $metric)
    {
        abort_unless($metric->profile_id === $request->user()->ensureProfile()->id, 403);
        $metric->delete();

        return back()->with('status', 'Measurement deleted.');
    }
}
