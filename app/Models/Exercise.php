<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A movement in the shared, global exercise library. NOT profile-scoped -- every
 * profile's workouts draw from the same library. Seeded by ExerciseLibrarySeeder.
 */
class Exercise extends Model
{
    use HasFactory;

    public const CATEGORIES = ['compound', 'isolation', 'cardio'];

    protected $fillable = ['name', 'slug', 'muscle_group', 'category', 'equipment'];

    public function workoutExercises(): HasMany
    {
        return $this->hasMany(WorkoutExercise::class);
    }

    public static function slugFor(string $name): string
    {
        return Str::slug($name) ?: 'exercise-'.Str::random(6);
    }
}
