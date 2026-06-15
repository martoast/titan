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
     * Voice command on the live session. The phone transcribes on-device; we classify INTENT and
     * act in real time. Beyond adding a set ("bench press 80 kilos 8 reps") the user can correct
     * the log by talking to it: "change the last set to 10 reps", "make it 85 kilos", "delete that
     * set", "remove the squats", "undo". Always returns the full session snapshot so the live UI
     * (and its running stats) re-render from the truth.
     */
    public function voiceLog(Request $request)
    {
        $profile = auth()->user()->ensureProfile();
        $data = $request->validate([
            'transcript' => ['required', 'string', 'max:300'],
            'workout_id' => ['nullable', 'integer'],
        ]);

        $cmd = $this->parseVoiceCommand($data['transcript']);
        $workout = ! empty($data['workout_id']) ? $profile->workouts()->find($data['workout_id']) : null;

        // Edits need something already logged.
        $isEdit = in_array($cmd['intent'], ['update_set', 'delete_set', 'undo', 'delete_exercise'], true);
        if ($isEdit && (! $workout || $workout->exercises()->count() === 0)) {
            return $this->voiceFail($data['transcript'], 'Nothing logged yet to change.');
        }

        switch ($cmd['intent']) {
            case 'finish':
                if (! $workout || $workout->exercises()->count() === 0) {
                    return $this->voiceFail($data['transcript'], 'No active session to finish.');
                }
                $workout->update(['duration_min' => max(1, $workout->performed_at->diffInMinutes(now()))]);

                return response()->json([
                    'ok' => true,
                    'heard' => $data['transcript'],
                    'action' => 'finish',
                    'spoken' => 'Session complete — nice work.',
                    'redirect' => route('workouts.show', $workout),
                ]);

            case 'undo':
            case 'delete_set':
                $set = $this->targetSet($workout, $cmd['exercise']);
                if (! $set) {
                    return $this->voiceFail($data['transcript'], "Couldn't find that set to remove.");
                }
                $we = $set->workoutExercise;
                $name = $we->exercise?->name ?? 'set';
                $set->delete();
                if ($we->sets()->count() === 0) {
                    $we->delete();
                }
                $spoken = "Removed the last {$name} set.";
                break;

            case 'update_set':
                $set = $this->targetSet($workout, $cmd['exercise']);
                if (! $set) {
                    return $this->voiceFail($data['transcript'], "Couldn't find a set to change.");
                }
                $set->update(array_filter([
                    'reps' => $cmd['reps'],
                    'weight_kg' => $cmd['weight_kg'],
                    'rpe' => $cmd['rpe'],
                ], fn ($v) => $v !== null));
                $w = rtrim(rtrim(number_format((float) $set->weight_kg, 1), '0'), '.');
                $spoken = "Updated to {$set->reps} reps × {$w} kg.";
                break;

            case 'delete_exercise':
                $we = $this->targetExercise($workout, $cmd['exercise']);
                if (! $we) {
                    return $this->voiceFail($data['transcript'], "Couldn't find that exercise.");
                }
                $name = $we->exercise?->name ?? 'exercise';
                $we->delete();
                $spoken = "Removed {$name}.";
                break;

            default: // add_set
                if (! $cmd['exercise'] || $cmd['reps'] === null) {
                    return $this->voiceFail($data['transcript'], "Didn't catch a set — try \"bench press, 80 kilos, 8 reps\".");
                }
                $workout ??= $profile->workouts()->create([
                    'name' => 'Live session · '.now()->format('M j'), 'performed_at' => now(),
                ]);
                [$exercise] = $this->identifier->matchOrCreate(['name' => $cmd['exercise'], 'muscle_group' => 'full body', 'category' => 'compound']);
                $we = $workout->exercises()->where('exercise_id', $exercise->id)->latest('id')->first()
                    ?? WorkoutExercise::create([
                        'workout_id' => $workout->id, 'exercise_id' => $exercise->id, 'order' => $workout->exercises()->count() + 1,
                    ]);
                $set = WorkoutSet::create([
                    'workout_exercise_id' => $we->id,
                    'set_number' => $we->sets()->count() + 1,
                    'reps' => $cmd['reps'],
                    'weight_kg' => $cmd['weight_kg'] ?? 0,
                    'rpe' => $cmd['rpe'],
                ]);
                $w = rtrim(rtrim(number_format((float) $set->weight_kg, 1), '0'), '.');
                $spoken = "Added {$exercise->name} — {$w} kg × {$set->reps}.";
                break;
        }

        return response()->json([
            'ok' => true,
            'heard' => $data['transcript'],
            'action' => $cmd['intent'],
            'spoken' => $spoken,
            'session' => $this->sessionSnapshot($workout->fresh()),
        ]);
    }

    private function voiceFail(string $heard, string $message)
    {
        return response()->json(['ok' => false, 'heard' => $heard, 'message' => $message], 200);
    }

    /** The most recent set in the workout — or in a named exercise if the user said one. */
    private function targetSet(Workout $workout, ?string $exerciseName): ?WorkoutSet
    {
        $we = $this->targetExercise($workout, $exerciseName);

        return $we ? $we->sets()->latest('id')->first() : null;
    }

    /** The named exercise's card (plural-tolerant fuzzy match) — or the most recent one. */
    private function targetExercise(Workout $workout, ?string $exerciseName): ?WorkoutExercise
    {
        $exercises = $workout->exercises()->with('exercise')->latest('id')->get();
        if (! $exerciseName) {
            return $exercises->first();
        }
        $needle = rtrim(mb_strtolower($exerciseName), 's');

        return $exercises->first(function (WorkoutExercise $we) use ($needle) {
            $name = rtrim(mb_strtolower($we->exercise?->name ?? ''), 's');

            return $name !== '' && ($name === $needle || str_contains($name, $needle) || str_contains($needle, $name));
        });
    }

    /** The session as the live UI consumes it (mirrors the page's initial hydration). */
    private function sessionSnapshot(Workout $workout): array
    {
        $workout->load('exercises.exercise', 'exercises.sets');

        return [
            'workout_id' => $workout->id,
            'exercises' => $workout->exercises->map(fn ($we) => [
                'id' => $we->id,
                'name' => $we->exercise?->name ?? 'Exercise',
                'muscle_group' => $we->exercise?->muscle_group,
                'sets' => $we->sets->map(fn ($s) => [
                    'reps' => $s->reps, 'weight' => (float) $s->weight_kg, 'rpe' => $s->rpe,
                ])->values(),
            ])->values(),
        ];
    }

    /**
     * Classify a spoken command → {intent, exercise, weight_kg, reps, rpe}. Intents: add_set
     * (default), update_set (correct the last set), delete_set / undo, delete_exercise.
     *
     * @return array{intent:string,exercise:?string,weight_kg:?float,reps:?int,rpe:?float}
     */
    private function parseVoiceCommand(string $raw): array
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

        $finish = (bool) preg_match('/\b(finish|end|complete|wrap ?up)\b[ a-z]*\b(workout|session|training|lifting|gym)\b/', $t)
            || (bool) preg_match("/\b(i'?m done|im done|all done|that'?s it|that'?s all|done for (the day|today|now))\b/", $t);
        if ($finish) {
            return ['intent' => 'finish', 'exercise' => null, 'weight_kg' => null, 'reps' => null, 'rpe' => null];
        }

        $undo = (bool) preg_match('/\b(undo|scratch that|never ?mind|cancel that|forget that)\b/', $t);
        $delete = (bool) preg_match('/\b(delete|remove|drop|scrap|get rid of)\b/', $t);
        $correct = (bool) preg_match('/\b(change|correct|fix|update|adjust|edit)\b/', $t)
            || (bool) preg_match('/\bmake (it|that|the last)\b/', $t)
            || (bool) preg_match('/\bthat (was|should|is) /', $t)
            || (bool) preg_match('/\bset it to\b/', $t)
            || (bool) preg_match('/\bactually\b/', $t);
        $refLast = (bool) preg_match('/\b(last|that|it|previous|this)\b/', $t);

        $metrics = $this->extractMetrics($t);

        if ($undo) {
            return ['intent' => 'undo', 'exercise' => null, 'weight_kg' => null, 'reps' => null, 'rpe' => null];
        }
        if ($delete) {
            $intent = (preg_match('/\bset\b/', $t) || $refLast || ! $metrics['exercise']) ? 'delete_set' : 'delete_exercise';

            return ['intent' => $intent] + $metrics;
        }
        // Correction → update the last set, unless they named a NEW exercise (then it's an add).
        if ($correct && ($refLast || ! $metrics['exercise'])) {
            $upd = $this->extractMetrics($t, true);

            return ['intent' => 'update_set'] + $upd;
        }

        return ['intent' => 'add_set'] + $metrics;
    }

    /**
     * Pull weight/reps/rpe + the leftover exercise phrase out of a normalised utterance. In
     * `$update` mode (a correction) a lone unit-less number is read as weight if it looks like a
     * load (>25) else reps, instead of the add-mode bigger=weight/smaller=reps pairing.
     *
     * @return array{exercise:?string,weight_kg:?float,reps:?int,rpe:?float}
     */
    private function extractMetrics(string $t, bool $update = false): array
    {
        $weight = $reps = $rpe = null;
        if (preg_match('/\brpe\s*(\d+(?:\.\d+)?)/', $t, $m)) {
            $rpe = (float) $m[1];
            $t = str_replace($m[0], ' ', $t);
        }
        if (preg_match('/(\d+(?:\.\d+)?)\s*(kilograms?|kilos?|kgs?|kg)\b/', $t, $m)) {
            $weight = (float) $m[1];
            $t = str_replace($m[0], ' ', $t);
        } elseif (preg_match('/(\d+(?:\.\d+)?)\s*(pounds?|lbs?|lb)\b/', $t, $m)) {
            $weight = round((float) $m[1] * 0.453592 * 2) / 2;
            $t = str_replace($m[0], ' ', $t);
        }
        if (preg_match('/(\d+)\s*(reps?|times)\b/', $t, $m)) {
            $reps = (int) $m[1];
            $t = str_replace($m[0], ' ', $t);
        } elseif (preg_match('/\b(?:for|x|by)\s*(\d+)\b/', $t, $m)) {
            $reps = (int) $m[1];
            $t = str_replace($m[0], ' ', $t);
        }

        preg_match_all('/\b(\d+(?:\.\d+)?)\b/', $t, $nums);
        $bare = array_map('floatval', $nums[1]);
        if ($update) {
            // A correction usually changes one number; read a lone bare value by magnitude.
            if ($weight === null && $reps === null && count($bare) === 1) {
                if ($bare[0] > 25) {
                    $weight = $bare[0];
                } else {
                    $reps = (int) $bare[0];
                }
            } elseif ($weight !== null && $reps === null && $bare) {
                $reps = (int) $bare[0];
            } elseif ($reps !== null && $weight === null && $bare) {
                $weight = (float) $bare[0];
            }
        } else {
            if ($weight === null && $reps === null && count($bare) >= 2) {
                $weight = max($bare[0], $bare[1]);
                $reps = (int) min($bare[0], $bare[1]);
            } elseif ($weight !== null && $reps === null && $bare) {
                $reps = (int) $bare[0];
            } elseif ($reps !== null && $weight === null && $bare) {
                $weight = (float) $bare[0];
            }
        }

        $ex = preg_replace('/\b\d+(?:\.\d+)?\b/', ' ', $t);
        $ex = preg_replace('/\b(kilograms?|kilos?|kgs?|kg|pounds?|lbs?|lb|reps?|rpe|for|x|by|at|and|times|of|the|a|to|set|sets|change|make|correct|fix|update|adjust|edit|it|that|last|this|previous|actually|delete|remove|drop|scrap|scratch|undo|never|nevermind|mind|cancel|forget|get|rid|off|my)\b/', ' ', $ex);
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
