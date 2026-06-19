<?php

namespace App\Http\Controllers\Workouts;

use App\Http\Controllers\Controller;
use App\Models\Exercise;
use App\Models\Workout;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSet;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class WorkoutController extends Controller
{
    /** Workout history with derived volume + top sets, plus a weekly muscle-group summary. */
    public function index()
    {
        $profile = auth()->user()->ensureProfile();

        $workouts = $profile->workouts()
            ->with(['exercises.exercise', 'exercises.sets'])
            ->orderByDesc('performed_at')
            ->get();

        $weeklyVolume = $this->weeklyMuscleGroupVolume($profile->id);

        return view('workouts.index', [
            'profile' => $profile,
            'workouts' => $workouts,
            'weeklyVolume' => $weeklyVolume,
        ]);
    }

    /** The log-a-workout form. Pre-computes each exercise's last performance + a suggestion. */
    public function create()
    {
        $profile = auth()->user()->ensureProfile();

        $exercises = Exercise::orderBy('muscle_group')->orderBy('name')->get();

        // Map exercise_id => ['last' => [...], 'suggestion' => [...]] for the whole library,
        // so the Alpine UI can surface a progressive-overload hint the moment one is picked.
        $suggestions = [];
        foreach ($exercises as $exercise) {
            $suggestions[$exercise->id] = $this->progressionFor($profile->id, $exercise->id);
        }

        return view('workouts.create', [
            'profile' => $profile,
            'exercises' => $exercises,
            'suggestions' => $suggestions,
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
                        'weight_kg' => $set['weight_kg'],
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

        DB::transaction(function () use ($data, $ownSetIds) {
            foreach ($data['sets'] as $id => $fields) {
                if (! $ownSetIds->has((int) $id)) {
                    continue;
                }
                WorkoutSet::where('id', (int) $id)->update(array_filter([
                    'weight_kg' => isset($fields['weight_kg']) ? (float) $fields['weight_kg'] : null,
                    'reps' => isset($fields['reps']) ? (int) $fields['reps'] : null,
                    'rpe' => isset($fields['rpe']) ? (float) $fields['rpe'] : null,
                ], fn ($v) => $v !== null));
            }
        });

        return redirect()->route('workouts.show', $workout)->with('status', 'Weights saved.');
    }

    /**
     * Simple adaptive progressive-overload heuristic for one exercise.
     * Looks at the user's most recent working top set; suggests a small bump:
     *  - if last RPE was easy (<= 7) or reps already high (>= 12) → +2.5 kg, reset reps;
     *  - otherwise → +1 rep at the same load.
     * Returns ['last' => ?array, 'suggestion' => ?array, 'note' => string].
     */
    private function progressionFor(int $profileId, int $exerciseId): array
    {
        $lastSet = WorkoutSet::query()
            ->where('is_warmup', false)
            ->whereHas('workoutExercise', function ($q) use ($exerciseId, $profileId) {
                $q->where('exercise_id', $exerciseId)
                    ->whereHas('workout', fn ($w) => $w->where('profile_id', $profileId));
            })
            // Order by the parent workout's date, then heaviest set of that session.
            ->whereHas('workoutExercise.workout')
            ->with('workoutExercise.workout')
            ->get()
            ->sortByDesc(fn ($s) => optional($s->workoutExercise->workout)->performed_at)
            ->groupBy(fn ($s) => optional($s->workoutExercise->workout)->performed_at?->toDateTimeString())
            ->first(); // sets from the most recent session

        if (! $lastSet || $lastSet->isEmpty()) {
            return [
                'last' => null,
                'suggestion' => null,
                'note' => 'No history yet -- log your first set to start tracking progression.',
            ];
        }

        // Top working set of the most recent session (heaviest).
        $top = $lastSet->sortByDesc('weight_kg')->first();
        $lastWeight = (float) $top->weight_kg;
        $lastReps = (int) $top->reps;
        $lastRpe = $top->rpe !== null ? (float) $top->rpe : null;

        $easy = ($lastRpe !== null && $lastRpe <= 7) || $lastReps >= 12;

        if ($easy) {
            // Bump the load; drop reps back to a sensible working target to "earn" the new weight.
            $suggestion = [
                'weight_kg' => round($lastWeight + 2.5, 2),
                'reps' => max(5, min($lastReps, 8)),
                'reason' => '+2.5 kg -- last session looked manageable.',
            ];
        } else {
            $suggestion = [
                'weight_kg' => $lastWeight,
                'reps' => $lastReps + 1,
                'reason' => '+1 rep at the same load -- earn the weight jump first.',
            ];
        }

        return [
            'last' => [
                'weight_kg' => $lastWeight,
                'reps' => $lastReps,
                'rpe' => $lastRpe,
                'performed_at' => optional($top->workoutExercise->workout)->performed_at?->toDateString(),
            ],
            'suggestion' => $suggestion,
            'note' => 'Last: '.$lastReps.' × '.rtrim(rtrim(number_format($lastWeight, 1), '0'), '.').' kg'
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
