<?php

namespace App\Http\Controllers\Workouts;

use App\Http\Controllers\Controller;
use App\Models\Exercise;
use App\Models\Profile;
use App\Models\Workout;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSet;
use App\Support\Units;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class WorkoutController extends Controller
{
    /** Workout history with derived volume + top sets, plus a weekly muscle-group summary. */
    public function index()
    {
        $profile = auth()->user()->ensureProfile();

        // Paginate so the history stays a light payload on mobile no matter how many
        // sessions pile up (each session eager-loads its exercises + sets).
        $workouts = $profile->workouts()
            ->with(['exercises.exercise', 'exercises.sets'])
            ->orderByDesc('performed_at')
            ->paginate(15);

        // Band-detected sessions awaiting their load — computed across ALL history (not just
        // the current page) from a focused query, so the nudge never goes missing on page 2+.
        $pending = $profile->workouts()
            ->where('updated_via', 'like', 'biosignal%')
            ->with('exercises.sets')
            ->orderByDesc('performed_at')
            ->get()
            ->filter(function ($w) {
                $sets = $w->exercises->flatMap->sets;

                return $sets->isNotEmpty() && $sets->every(fn ($s) => (float) $s->weight_kg === 0.0);
            })
            ->values();

        $weeklyVolume = $this->weeklyMuscleGroupVolume($profile->id);

        return view('workouts.index', [
            'profile' => $profile,
            'workouts' => $workouts,
            'pending' => $pending,
            'weeklyVolume' => $weeklyVolume,
            'weightUnit' => Units::weightUnit($profile),
        ]);
    }

    /** The log-a-workout form. Pre-computes each exercise's last performance + a suggestion. */
    public function create()
    {
        $profile = auth()->user()->ensureProfile();

        $exercises = Exercise::orderBy('muscle_group')->orderBy('name')->get();

        // Map exercise_id => ['last' => [...], 'suggestion' => [...]] for the whole library,
        // so the Alpine UI can surface a progressive-overload hint the moment one is picked.
        // Built from ONE query (was one query per exercise — an N+1 that scaled with the library).
        $suggestions = $this->progressionMap($profile->id, $profile);
        foreach ($exercises as $exercise) {
            $suggestions[$exercise->id] ??= [
                'last' => null,
                'suggestion' => null,
                'note' => 'No history yet -- log your first set to start tracking progression.',
            ];
        }

        return view('workouts.create', [
            'profile' => $profile,
            'exercises' => $exercises,
            'suggestions' => $suggestions,
            'weightUnit' => Units::weightUnit($profile),
        ]);
    }

    /** Persist a Workout + its WorkoutExercises + WorkoutSets in one transaction. */
    public function store(Request $request)
    {
        $profile = auth()->user()->ensureProfile();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'performed_at' => ['nullable', 'date'],
            'duration_min' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'exercises' => ['required', 'array', 'min:1'],
            'exercises.*.exercise_id' => ['required', 'integer', 'exists:exercises,id'],
            'exercises.*.notes' => ['nullable', 'string', 'max:500'],
            'exercises.*.sets' => ['required', 'array', 'min:1'],
            'exercises.*.sets.*.reps' => ['required', 'integer', 'min:0', 'max:1000'],
            'exercises.*.sets.*.weight_kg' => ['required', 'numeric', 'min:0', 'max:9999'],
            'exercises.*.sets.*.rpe' => ['nullable', 'numeric', 'min:0', 'max:10'],
            'exercises.*.sets.*.is_warmup' => ['nullable', 'boolean'],
        ]);

        $workout = DB::transaction(function () use ($profile, $data) {
            $workout = $profile->workouts()->create([
                'name' => $data['name'],
                'performed_at' => $data['performed_at'] ?? now(),
                'duration_min' => $data['duration_min'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            foreach (array_values($data['exercises']) as $i => $ex) {
                $we = WorkoutExercise::create([
                    'workout_id' => $workout->id,
                    'exercise_id' => $ex['exercise_id'],
                    'order' => $i + 1,
                    'notes' => $ex['notes'] ?? null,
                ]);

                foreach (array_values($ex['sets']) as $j => $set) {
                    WorkoutSet::create([
                        'workout_exercise_id' => $we->id,
                        'set_number' => $j + 1,
                        'reps' => $set['reps'],
                        // The form collects weight in the user's display units; store metric.
                        'weight_kg' => Units::weightIn((float) $set['weight_kg'], $profile),
                        'rpe' => $set['rpe'] ?? null,
                        'is_warmup' => (bool) ($set['is_warmup'] ?? false),
                    ]);
                }
            }

            return $workout;
        });

        return redirect('/workouts')->with('status', "Logged \"{$workout->name}\".");
    }

    public function show(Workout $workout)
    {
        $profile = auth()->user()->ensureProfile();
        abort_unless($workout->profile_id === $profile->id, 404);

        $workout->load(['exercises.exercise', 'exercises.sets']);

        return view('workouts.show', [
            'workout' => $workout,
            'profile' => $profile,
            'weightUnit' => Units::weightUnit($profile),
        ]);
    }

    /**
     * Log the load (and correct the auto-counted reps / RPE) against a workout's sets. Built for
     * band-detected strength sessions, where reps come from the wrist but weight is unknown -- the
     * user fills it in here. Bulk-updates every set in one form submit; only touches sets that
     * actually belong to this workout (and so to this profile).
     */
    public function updateSets(Request $request, Workout $workout)
    {
        $profile = auth()->user()->ensureProfile();
        abort_unless($workout->profile_id === $profile->id, 404);

        $data = $request->validate([
            'sets' => ['required', 'array'],
            'sets.*.weight_kg' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'sets.*.reps' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'sets.*.rpe' => ['nullable', 'numeric', 'min:1', 'max:10'],
        ]);

        // The set ids that legitimately belong to this workout (guards against tampered ids).
        $ownSetIds = WorkoutSet::whereHas('workoutExercise', fn ($q) => $q->where('workout_id', $workout->id))
            ->pluck('id')->flip();

        DB::transaction(function () use ($data, $ownSetIds, $profile) {
            foreach ($data['sets'] as $id => $fields) {
                if (! $ownSetIds->has((int) $id)) {
                    continue;
                }
                WorkoutSet::where('id', (int) $id)->update(array_filter([
                    // Weight comes in the user's display units; store metric.
                    'weight_kg' => isset($fields['weight_kg']) ? Units::weightIn((float) $fields['weight_kg'], $profile) : null,
                    'reps' => isset($fields['reps']) ? (int) $fields['reps'] : null,
                    'rpe' => isset($fields['rpe']) ? (float) $fields['rpe'] : null,
                ], fn ($v) => $v !== null));
            }
        });

        return redirect()->route('workouts.show', $workout)->with('status', 'Weights saved.');
    }

    /**
     * Progressive-overload hints for the WHOLE exercise library in ONE query.
     * Returns exercise_id => ['last' => ?array, 'suggestion' => ?array, 'note' => string]
     * for every exercise the user has working-set history on. Exercises with no history
     * are simply absent (the caller fills in the default note).
     *
     * Previously this was computed per-exercise (an N+1 that scaled with the library);
     * here a single joined query pulls every working set, then we reduce in PHP to each
     * exercise's most-recent-session top set.
     *
     * @return array<int, array{last:?array, suggestion:?array, note:string}>
     */
    private function progressionMap(int $profileId, Profile $profile): array
    {
        $rows = DB::table('workout_sets as ws')
            ->join('workout_exercises as we', 'we.id', '=', 'ws.workout_exercise_id')
            ->join('workouts as w', 'w.id', '=', 'we.workout_id')
            ->where('w.profile_id', $profileId)
            ->where('ws.is_warmup', false)
            ->whereNotNull('w.performed_at')
            ->orderBy('we.exercise_id')
            ->orderByDesc('w.performed_at')
            ->select('we.exercise_id', 'w.performed_at', 'ws.weight_kg', 'ws.reps', 'ws.rpe')
            ->get();

        $map = [];
        foreach ($rows as $r) {
            $exId = (int) $r->exercise_id;
            // Rows are date-desc, so the first date seen for an exercise is its latest session.
            if (! isset($map[$exId])) {
                $map[$exId] = ['date' => (string) $r->performed_at, 'top' => $r];

                continue;
            }
            // Still in the latest session? keep the heaviest working set.
            if ((string) $r->performed_at === $map[$exId]['date']
                && (float) $r->weight_kg > (float) $map[$exId]['top']->weight_kg) {
                $map[$exId]['top'] = $r;
            }
        }

        $out = [];
        foreach ($map as $exId => $entry) {
            $top = $entry['top'];
            $out[$exId] = $this->buildProgression(
                (float) $top->weight_kg,
                (int) $top->reps,
                $top->rpe !== null ? (float) $top->rpe : null,
                Carbon::parse($entry['date'])->toDateString(),
                $profile,
            );
        }

        return $out;
    }

    /**
     * Build the last/suggestion/note payload from a top working set. Suggests a small bump:
     *  - if last RPE was easy (<= 7) or reps already high (>= 12) → +2.5 kg, reset reps;
     *  - otherwise → +1 rep at the same load.
     *
     * The progression math runs in kg (sane plate increments); all weights handed to the
     * view are converted to the user's display units so the form + hints read consistently.
     *
     * @return array{last:array, suggestion:array, note:string}
     */
    private function buildProgression(float $lastWeight, int $lastReps, ?float $lastRpe, string $performedAt, Profile $profile): array
    {
        $easy = ($lastRpe !== null && $lastRpe <= 7) || $lastReps >= 12;

        $suggestion = $easy
            ? [
                'weight_kg' => Units::weightOut($lastWeight + 2.5, $profile),
                'reps' => max(5, min($lastReps, 8)),
                'reason' => 'last session looked manageable -- nudge the load up.',
            ]
            : [
                'weight_kg' => Units::weightOut($lastWeight, $profile),
                'reps' => $lastReps + 1,
                'reason' => '+1 rep at the same load -- earn the weight jump first.',
            ];

        return [
            'last' => [
                'weight_kg' => Units::weightOut($lastWeight, $profile),
                'reps' => $lastReps,
                'rpe' => $lastRpe,
                'performed_at' => $performedAt,
            ],
            'suggestion' => $suggestion,
            'note' => 'Last: '.$lastReps.' × '.Units::weight($lastWeight, $profile)
                .($lastRpe !== null ? ' @ RPE '.rtrim(rtrim(number_format($lastRpe, 1), '0'), '.') : ''),
        ];
    }

    /**
     * Working sets per muscle group over the last 7 days. Returns an ordered
     * collection of ['muscle_group' => ..., 'sets' => int] for the summary chart.
     *
     * @return array<int,array{muscle_group:string,sets:int}>
     */
    private function weeklyMuscleGroupVolume(int $profileId): array
    {
        $since = Carbon::now()->subDays(7);

        $rows = DB::table('workout_sets as ws')
            ->join('workout_exercises as we', 'we.id', '=', 'ws.workout_exercise_id')
            ->join('workouts as w', 'w.id', '=', 'we.workout_id')
            ->join('exercises as e', 'e.id', '=', 'we.exercise_id')
            ->where('w.profile_id', $profileId)
            ->where('ws.is_warmup', false)
            ->where('w.performed_at', '>=', $since)
            ->groupBy('e.muscle_group')
            ->selectRaw('e.muscle_group as muscle_group, COUNT(*) as sets')
            ->orderByDesc('sets')
            ->get();

        return $rows->map(fn ($r) => [
            'muscle_group' => $r->muscle_group,
            'sets' => (int) $r->sets,
        ])->all();
    }
}
