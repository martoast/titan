<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One set of an exercise within a workout. Warmup sets are excluded from
 * working-volume and progression calculations.
 */
class WorkoutSet extends Model
{
    use HasFactory;

    protected $fillable = ['workout_exercise_id', 'set_number', 'reps', 'weight_kg', 'rpe', 'is_warmup'];

    protected function casts(): array
    {
        return [
            'weight_kg' => 'decimal:2',
            'rpe' => 'decimal:1',
            'is_warmup' => 'boolean',
        ];
    }

    public function workoutExercise(): BelongsTo
    {
        return $this->belongsTo(WorkoutExercise::class);
    }

    /** This set's contribution to working volume. */
    public function volume(): float
    {
        return $this->is_warmup ? 0.0 : $this->reps * (float) $this->weight_kg;
    }
}
