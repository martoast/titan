<?php

namespace App\Http\Controllers\Workouts;

use App\Exceptions\AiException;
use App\Http\Controllers\Controller;
use App\Models\Workout;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSet;
use App\Services\Workouts\ExerciseIdentifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Real-time "snap the machine" workout logging. Instead of naming each exercise,
 * the user photographs the machine / its placard; OpenAI vision identifies it and
 * matches the library. Sets are persisted the moment they're entered, so a session
 * is logged live as it happens. All writes are scoped to the current profile.
 */
class LiveWorkoutController extends Controller
{
    public function __construct(protected ExerciseIdentifier $identifier) {}

    /** The live-session page. Continues an unfinished session from today if one exists. */
    public function page()
    {
        $profile = auth()->user()->ensureProfile();

        $active = $profile->workouts()
            ->whereDate('performed_at', now()->toDateString())
            ->where('name', 'like', 'Live session%')
            ->with('exercises.exercise', 'exercises.sets')
            ->latest('id')
            ->first();

        return view('workouts.live', [
            'profile' => $profile,
            'active' => $active,
            'aiReady' => $this->identifier->configured(),
        ]);
    }

    /** Vision-identify the exercise in an uploaded photo. Stateless — no DB write. */
    public function identify(Request $request)
    {
        $request->validate(['photo' => ['required', 'image', 'max:15360']]);

        try {
            $bytes = file_get_contents($request->file('photo')->getRealPath());
            $mime = $request->file('photo')->getMimeType() ?: 'image/jpeg';
            $dataUrl = 'data:'.$mime.';base64,'.base64_encode($bytes);

            $identification = $this->identifier->identify($dataUrl);
        } catch (AiException $e) {
            return response()->json(['ok' => false, 'message' => 'Could not identify the photo: '.$e->getMessage()], 200);
        }

        return response()->json(['ok' => true, 'identification' => $identification]);
    }

    /**
     * Add an exercise to the session (lazily creating today's live workout), matching
     * the given name to the library or creating a new library entry.
     */
    public function addExercise(Request $request)
    {
        $profile = auth()->user()->ensureProfile();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'muscle_group' => ['nullable', 'string', 'max:40'],
            'category' => ['nullable', 'string', 'max:40'],
            'equipment' => ['nullable', 'string', 'max:60'],
            'workout_id' => ['nullable', 'integer'],
        ]);

        // Resolve or lazily start the live workout (always profile-owned).
        $workout = null;
        if (! empty($data['workout_id'])) {
            $workout = $profile->workouts()->find($data['workout_id']);
        }
        $workout ??= $profile->workouts()->create([
            'name' => 'Live session · '.now()->format('M j'),
            'performed_at' => now(),
        ]);

        [$exercise, $created] = $this->identifier->matchOrCreate([
            'name' => $data['name'],
            'muscle_group' => $data['muscle_group'] ?? 'full body',
            'category' => $data['category'] ?? 'compound',
            'equipment' => $data['equipment'] ?? null,
        ]);

        $we = WorkoutExercise::create([
            'workout_id' => $workout->id,
            'exercise_id' => $exercise->id,
            'order' => $workout->exercises()->count() + 1,
        ]);

        return response()->json([
            'ok' => true,
            'workout_id' => $workout->id,
            'workout_exercise_id' => $we->id,
            'exercise_name' => $exercise->name,
            'muscle_group' => $exercise->muscle_group,
            'created_in_library' => $created,
        ]);
    }

    /** Persist one set immediately (the "real-time" part). */
    public function addSet(Request $request)
    {
        $profile = auth()->user()->ensureProfile();

        $data = $request->validate([
            'workout_exercise_id' => ['required', 'integer'],
            'reps' => ['required', 'integer', 'min:0', 'max:1000'],
            'weight_kg' => ['required', 'numeric', 'min:0', 'max:9999'],
            'rpe' => ['nullable', 'numeric', 'min:0', 'max:10'],
            'is_warmup' => ['nullable', 'boolean'],
        ]);

        // Ownership: the workout_exercise must belong to one of this profile's workouts.
        $we = WorkoutExercise::whereHas('workout', fn ($q) => $q->where('profile_id', $profile->id))
            ->findOrFail($data['workout_exercise_id']);

        $set = WorkoutSet::create([
            'workout_exercise_id' => $we->id,
            'set_number' => $we->sets()->count() + 1,
            'reps' => $data['reps'],
            'weight_kg' => $data['weight_kg'],
            'rpe' => $data['rpe'] ?? null,
            'is_warmup' => (bool) ($data['is_warmup'] ?? false),
        ]);

        return response()->json(['ok' => true, 'set_number' => $set->set_number]);
    }

    /**
     * Voice-log a set: the phone records "bench press, 80 kilos, 8 reps", we parse it and persist
     * the set in real time — resolving (or creating) the exercise and reusing its card if it's
     * already in this session. The speech→text happens on-device in the browser; we receive the
     * transcript and turn it into a structured set.
     */
    public function voiceLog(Request $request)
    {
        $profile = auth()->user()->ensureProfile();
        $data = $request->validate([
            'transcript' => ['required', 'string', 'max:300'],
            'workout_id' => ['nullable', 'integer'],
        ]);

        $parsed = $this->parseSpokenSet($data['transcript']);
        if (! $parsed['exercise'] || $parsed['reps'] === null) {
            return response()->json([
                'ok' => false,
                'heard' => $data['transcript'],
                'message' => "Didn't catch a set — try \"bench press, 80 kilos, 8 reps\".",
            ], 200);
        }

        // Resolve / lazily start the live workout (always profile-owned).
        $workout = ! empty($data['workout_id']) ? $profile->workouts()->find($data['workout_id']) : null;
        $workout ??= $profile->workouts()->create([
            'name' => 'Live session · '.now()->format('M j'),
            'performed_at' => now(),
        ]);

        [$exercise] = $this->identifier->matchOrCreate(['name' => $parsed['exercise'], 'muscle_group' => 'full body', 'category' => 'compound']);

        // Reuse the exercise's existing card in this session, or add one — so repeated sets stack.
        $we = $workout->exercises()->where('exercise_id', $exercise->id)->latest('id')->first();
        $isNewExercise = $we === null;
        $we ??= WorkoutExercise::create([
            'workout_id' => $workout->id, 'exercise_id' => $exercise->id, 'order' => $workout->exercises()->count() + 1,
        ]);

        $set = WorkoutSet::create([
            'workout_exercise_id' => $we->id,
            'set_number' => $we->sets()->count() + 1,
            'reps' => $parsed['reps'],
            'weight_kg' => $parsed['weight_kg'] ?? 0,
            'rpe' => $parsed['rpe'],
        ]);

        return response()->json([
            'ok' => true,
            'heard' => $data['transcript'],
            'workout_id' => $workout->id,
            'workout_exercise_id' => $we->id,
            'is_new_exercise' => $isNewExercise,
            'exercise_name' => $exercise->name,
            'muscle_group' => $exercise->muscle_group,
            'reps' => $set->reps,
            'weight_kg' => (float) $set->weight_kg,
            'rpe' => $set->rpe,
            'set_number' => $set->set_number,
        ]);
    }

    /**
     * Parse a spoken set into {exercise, weight_kg, reps, rpe}. Deterministic + forgiving of how
     * people actually talk: "bench press 80 kilos for 8", "squat 100 by 5 rpe 8", "pull ups 12
     * reps", "deadlift 140 5". Numbers may be words or digits; pounds convert to kg.
     *
     * @return array{exercise:?string,weight_kg:?float,reps:?int,rpe:?float}
     */
    private function parseSpokenSet(string $raw): array
    {
        $t = ' '.strtolower(trim($raw)).' ';
        $words = ['zero' => 0, 'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6,
            'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10, 'eleven' => 11, 'twelve' => 12, 'thirteen' => 13,
            'fourteen' => 14, 'fifteen' => 15, 'sixteen' => 16, 'seventeen' => 17, 'eighteen' => 18, 'nineteen' => 19,
            'twenty' => 20, 'thirty' => 30, 'forty' => 40, 'fifty' => 50, 'sixty' => 60, 'seventy' => 70,
            'eighty' => 80, 'ninety' => 90, 'hundred' => 100];
        foreach ($words as $w => $n) {
            $t = preg_replace('/\b'.$w.'\b/', (string) $n, $t);
        }

        $weight = $reps = $rpe = null;
        if (preg_match('/\brpe\s*(\d+(?:\.\d+)?)/', $t, $m)) {
            $rpe = (float) $m[1];
            $t = str_replace($m[0], ' ', $t);
        }
        if (preg_match('/(\d+(?:\.\d+)?)\s*(kilograms?|kilos?|kgs?|kg)\b/', $t, $m)) {
            $weight = (float) $m[1];
            $t = str_replace($m[0], ' ', $t);
        } elseif (preg_match('/(\d+(?:\.\d+)?)\s*(pounds?|lbs?|lb)\b/', $t, $m)) {
            $weight = round((float) $m[1] * 0.453592 * 2) / 2; // → kg, nearest 0.5
            $t = str_replace($m[0], ' ', $t);
        }
        if (preg_match('/(\d+)\s*(reps?|times)\b/', $t, $m)) {
            $reps = (int) $m[1];
            $t = str_replace($m[0], ' ', $t);
        } elseif (preg_match('/\b(?:for|x|by)\s*(\d+)\b/', $t, $m)) {
            $reps = (int) $m[1];
            $t = str_replace($m[0], ' ', $t);
        }

        // Leftover bare numbers fill the gaps: bigger = weight, smaller = reps.
        preg_match_all('/\b(\d+(?:\.\d+)?)\b/', $t, $nums);
        $bare = array_map('floatval', $nums[1]);
        if ($weight === null && $reps === null && count($bare) >= 2) {
            $weight = max($bare[0], $bare[1]);
            $reps = (int) min($bare[0], $bare[1]);
        } elseif ($weight !== null && $reps === null && count($bare) >= 1) {
            $reps = (int) $bare[0];
        } elseif ($reps !== null && $weight === null && count($bare) >= 1) {
            $weight = (float) $bare[0];
        }

        $ex = preg_replace('/\b\d+(?:\.\d+)?\b/', ' ', $t);
        $ex = preg_replace('/\b(kilograms?|kilos?|kgs?|kg|pounds?|lbs?|lb|reps?|rpe|for|x|by|at|and|times|of|the|a)\b/', ' ', $ex);
        $ex = trim(preg_replace('/\s+/', ' ', $ex));

        return ['exercise' => $ex ? ucwords($ex) : null, 'weight_kg' => $weight, 'reps' => $reps, 'rpe' => $rpe];
    }

    /** End the session — optionally rename it — and go to the summary. */
    public function finish(Request $request)
    {
        $profile = auth()->user()->ensureProfile();
        $data = $request->validate([
            'workout_id' => ['required', 'integer'],
            'name' => ['nullable', 'string', 'max:120'],
            'duration_min' => ['nullable', 'integer', 'min:0', 'max:1440'],
        ]);

        $workout = $profile->workouts()->findOrFail($data['workout_id']);
        if (! empty($data['name'])) {
            $workout->name = $data['name'];
        }
        if (! empty($data['duration_min'])) {
            $workout->duration_min = $data['duration_min'];
        }
        $workout->save();

        return redirect('/workouts/'.$workout->id)->with('status', "Logged \"{$workout->name}\".");
    }
}
