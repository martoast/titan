<?php

namespace Database\Seeders;

use App\Models\Exercise;
use Illuminate\Database\Seeder;

/**
 * Seeds the shared, global exercise library (~30 common movements across muscle
 * groups). Idempotent: upserts by slug so re-running won't duplicate rows.
 */
class ExerciseLibrarySeeder extends Seeder
{
    public function run(): void
    {
        $exercises = [
            // Chest
            ['Barbell Bench Press', 'chest', 'compound', 'barbell'],
            ['Incline Dumbbell Press', 'chest', 'compound', 'dumbbell'],
            ['Cable Fly', 'chest', 'isolation', 'cable'],
            ['Push-Up', 'chest', 'compound', 'bodyweight'],

            // Back
            ['Deadlift', 'back', 'compound', 'barbell'],
            ['Pull-Up', 'back', 'compound', 'bodyweight'],
            ['Barbell Row', 'back', 'compound', 'barbell'],
            ['Lat Pulldown', 'back', 'compound', 'machine'],
            ['Seated Cable Row', 'back', 'compound', 'cable'],

            // Legs
            ['Back Squat', 'legs', 'compound', 'barbell'],
            ['Front Squat', 'legs', 'compound', 'barbell'],
            ['Romanian Deadlift', 'legs', 'compound', 'barbell'],
            ['Leg Press', 'legs', 'compound', 'machine'],
            ['Walking Lunge', 'legs', 'compound', 'dumbbell'],
            ['Leg Extension', 'legs', 'isolation', 'machine'],
            ['Lying Leg Curl', 'legs', 'isolation', 'machine'],
            ['Standing Calf Raise', 'legs', 'isolation', 'machine'],

            // Shoulders
            ['Overhead Press', 'shoulders', 'compound', 'barbell'],
            ['Dumbbell Shoulder Press', 'shoulders', 'compound', 'dumbbell'],
            ['Lateral Raise', 'shoulders', 'isolation', 'dumbbell'],
            ['Face Pull', 'shoulders', 'isolation', 'cable'],
            ['Rear Delt Fly', 'shoulders', 'isolation', 'dumbbell'],

            // Arms
            ['Barbell Curl', 'arms', 'isolation', 'barbell'],
            ['Dumbbell Hammer Curl', 'arms', 'isolation', 'dumbbell'],
            ['Tricep Pushdown', 'arms', 'isolation', 'cable'],
            ['Skull Crusher', 'arms', 'isolation', 'barbell'],
            ['Dips', 'arms', 'compound', 'bodyweight'],

            // Core
            ['Hanging Leg Raise', 'core', 'isolation', 'bodyweight'],
            ['Plank', 'core', 'isolation', 'bodyweight'],
            ['Cable Crunch', 'core', 'isolation', 'cable'],

            // Cardio
            ['Treadmill Run', 'cardio', 'cardio', 'machine'],
            ['Rowing Machine', 'cardio', 'cardio', 'machine'],
            ['Assault Bike', 'cardio', 'cardio', 'machine'],
        ];

        foreach ($exercises as [$name, $group, $category, $equipment]) {
            Exercise::updateOrCreate(
                ['slug' => Exercise::slugFor($name)],
                [
                    'name' => $name,
                    'muscle_group' => $group,
                    'category' => $category,
                    'equipment' => $equipment,
                ],
            );
        }
    }
}
