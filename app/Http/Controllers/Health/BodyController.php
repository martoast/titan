<?php

namespace App\Http\Controllers\Health;

use App\Http\Controllers\Controller;
use App\Models\BodyMetric;
use App\Support\Units;
use Illuminate\Http\Request;

/**
 * Body composition: weight + body-fat trends, latest tape measurements, manual entry.
 */
class BodyController extends Controller
{
    /** /body -- trend charts, latest snapshot, and the add form. */
    public function index(Request $request)
    {
        $profile = $request->user()->ensureProfile();

        $metrics = $profile->bodyMetrics()
            ->orderBy('taken_at')
            ->orderBy('id')
            ->get();

        // Stored metric → the user's display units, so Body reads the same as Dashboard/Progress.
        $isLength = ['waist_cm' => true, 'chest_cm' => true, 'arm_cm' => true, 'thigh_cm' => true];

        // Latest non-null value for each measure (rows may be partial), converted for display.
        $latest = [];
        foreach (['weight_kg', 'body_fat_pct', 'waist_cm', 'chest_cm', 'arm_cm', 'thigh_cm'] as $field) {
            $row = $metrics->whereNotNull($field)->last();
            $raw = $row?->{$field};
            $latest[$field] = $field === 'weight_kg' ? Units::weightOut($raw, $profile)
                : (isset($isLength[$field]) ? Units::lengthOut($raw, $profile) : $raw);
        }

        $series = [
            'weight' => $metrics->whereNotNull('weight_kg')->map(fn ($m) => [
                'date' => $m->taken_at->format('Y-m-d'),
                'value' => Units::weightOut((float) $m->weight_kg, $profile),
            ])->values()->all(),
            'bodyfat' => $metrics->whereNotNull('body_fat_pct')->map(fn ($m) => [
                'date' => $m->taken_at->format('Y-m-d'),
                'value' => (float) $m->body_fat_pct,
            ])->values()->all(),
        ];

        // History rows, each measure converted to display units (raw models stay metric).
        $history = $metrics->sortByDesc('taken_at')->values()->map(fn ($m) => [
            'id' => $m->id,
            'date' => $m->taken_at->format('M j, Y'),
            'weight' => Units::weightOut($m->weight_kg, $profile),
            'body_fat_pct' => $m->body_fat_pct !== null ? (float) $m->body_fat_pct : null,
            'waist' => Units::lengthOut($m->waist_cm, $profile),
            'chest' => Units::lengthOut($m->chest_cm, $profile),
            'arm' => Units::lengthOut($m->arm_cm, $profile),
            'thigh' => Units::lengthOut($m->thigh_cm, $profile),
        ]);

        return view('body.index', [
            'profile' => $profile,
            'history' => $history,
            'latest' => $latest,
            'series' => $series,
            'weightUnit' => Units::weightUnit($profile),
            'lengthUnit' => Units::lengthUnit($profile),
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

        // The form collects display units; convert back to metric before storing.
        $data['weight_kg'] = Units::weightIn(isset($data['weight_kg']) ? (float) $data['weight_kg'] : null, $profile);
        foreach (['waist_cm', 'chest_cm', 'arm_cm', 'thigh_cm'] as $f) {
            $data[$f] = Units::lengthIn(isset($data[$f]) ? (float) $data[$f] : null, $profile);
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
