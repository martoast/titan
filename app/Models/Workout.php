<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A single training session. Profile-scoped. Owns ordered workout_exercises, each
 * of which owns its sets. Volume metrics are derived from the loaded set rows.
 */
class Workout extends Model
{
    use HasFactory;

    protected $fillable = ['profile_id', 'performed_at', 'name', 'notes', 'duration_min', 'updated_via'];

    protected function casts(): array
    {
        return ['performed_at' => 'datetime'];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function exercises(): HasMany
    {
        return $this->hasMany(WorkoutExercise::class)->orderBy('order');
    }

    /** Total working volume: sum of reps × weight across all non-warmup sets. */
    public function totalVolume(): float
    {
        return $this->exercises->flatMap->sets
            ->where('is_warmup', false)
            ->sum(fn ($set) => $set->reps * (float) $set->weight_kg);
    }

    /** Count of working (non-warmup) sets across the session. */
    public function workingSetCount(): int
    {
        return $this->exercises->flatMap->sets->where('is_warmup', false)->count();
    }

    /** The heaviest working set per exercise — the session's "top sets". */
    public function topSets(): \Illuminate\Support\Collection
    {
        return $this->exercises->map(function ($we) {
            $top = $we->sets->where('is_warmup', false)
                ->sortByDesc('weight_kg')->first();

            return $top ? [
                'exercise' => $we->exercise?->name,
                'reps' => $top->reps,
                'weight_kg' => (float) $top->weight_kg,
            ] : null;
        })->filter()->values();
    }
}
