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
