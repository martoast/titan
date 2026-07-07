<?php

namespace Database\Seeders;

use App\Models\Exercise;
use App\Models\Profile;
use App\Models\Workout;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSet;
use Database\Seeders\Concerns\SeedsProfile;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Sample training history for profile 1 (Alex): three sessions over the last week
 * with a clear week-over-week progression on the main lifts, so the progressive-
 * overload suggestion + weekly volume summary have real data to work with.
 *
 * Depends on ExerciseLibrarySeeder having run first. Idempotent: skips if Alex
 * already has workouts seeded.
 */
class WorkoutsSeeder extends Seeder
{
    use SeedsProfile;

    public function run(): void
    {
        $profile = $this->targetProfile();
        if (! $profile) {
            return;
        }

        // Don't double-seed.
        if (Workout::where('profile_id', $profile->id)->exists()) {
            return;
        }

        // Ensure the library exists (in case this seeder is run standalone).
        if (Exercise::count() === 0) {
            (new ExerciseLibrarySeeder)->run();
        }

        $ex = fn (string $name) => Exercise::where('slug', Exercise::slugFor($name))->first();

        // --- Session blueprints. Sets: [reps, weight_kg, rpe, is_warmup] ---
        // Push Day appears twice (8 + 3 days ago) with +2.5kg progression on bench/OHP.
        $sessions = [
            [
                'name' => 'Push Day',
                'performed_at' => Carbon::now()->subDays(8)->setTime(7, 30),
                'duration_min' => 62,
                'notes' => 'Solid session. Bench felt strong at the top set.',
                'exercises' => [
                    ['Barbell Bench Press', [
                        [10, 40, 6, true],
                        [8, 80, 8, false],
                        [8, 80, 8.5, false],
                        [7, 80, 9, false],
                    ]],
                    ['Overhead Press', [
                        [8, 45, 8, false],
                        [8, 45, 8.5, false],
                        [6, 45, 9, false],
                    ]],
                    ['Incline Dumbbell Press', [
                        [12, 26, 7, false],
                        [11, 26, 8, false],
                    ]],
                    ['Tricep Pushdown', [
                        [15, 30, 8, false],
                        [13, 30, 9, false],
                    ]],
                ],
            ],
            [
                'name' => 'Pull Day',
                'performed_at' => Carbon::now()->subDays(6)->setTime(7, 15),
                'duration_min' => 58,
                'notes' => 'Deadlifts moving well.',
                'exercises' => [
                    ['Deadlift', [
                        [5, 60, 6, true],
                        [5, 140, 8, false],
                        [5, 140, 8.5, false],
                        [4, 140, 9, false],
                    ]],
                    ['Pull-Up', [
                        [10, 0, 8, false],
                        [8, 0, 9, false],
                        [7, 0, 9.5, false],
                    ]],
                    ['Barbell Row', [
                        [10, 60, 8, false],
                        [10, 60, 8.5, false],
                    ]],
                    ['Barbell Curl', [
                        [12, 30, 8, false],
                        [10, 30, 9, false],
                    ]],
                ],
            ],
            [
                'name' => 'Push Day',
                'performed_at' => Carbon::now()->subDays(3)->setTime(7, 40),
                'duration_min' => 64,
                'notes' => 'Hit the progression — bench up to 82.5kg.',
                'exercises' => [
                    ['Barbell Bench Press', [
                        [10, 42.5, 6, true],
                        [8, 82.5, 8.5, false],
                        [8, 82.5, 9, false],
                        [6, 82.5, 9.5, false],
                    ]],
                    ['Overhead Press', [
                        [8, 47.5, 8.5, false],
                        [7, 47.5, 9, false],
                        [6, 47.5, 9.5, false],
                    ]],
                    ['Incline Dumbbell Press', [
                        [12, 28, 8, false],
                        [10, 28, 9, false],
                    ]],
                    ['Lateral Raise', [
                        [15, 10, 8, false],
                        [15, 10, 8.5, false],
                        [12, 10, 9, false],
                    ]],
                ],
            ],
        ];

        foreach ($sessions as $s) {
            $workout = Workout::create([
                'profile_id' => $profile->id,
                'name' => $s['name'],
                'performed_at' => $s['performed_at'],
                'duration_min' => $s['duration_min'],
                'notes' => $s['notes'],
            ]);

            foreach ($s['exercises'] as $order => [$exName, $sets]) {
                $exercise = $ex($exName);
                if (! $exercise) {
                    continue;
                }

                $we = WorkoutExercise::create([
                    'workout_id' => $workout->id,
                    'exercise_id' => $exercise->id,
                    'order' => $order + 1,
                ]);

                foreach ($sets as $i => [$reps, $weight, $rpe, $isWarmup]) {
                    WorkoutSet::create([
                        'workout_exercise_id' => $we->id,
                        'set_number' => $i + 1,
                        'reps' => $reps,
                        'weight_kg' => $weight,
                        'rpe' => $rpe,
                        'is_warmup' => $isWarmup,
                    ]);
                }
            }
        }
    }
}
