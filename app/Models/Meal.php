<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * A logged meal, profile-scoped. Macros live on the row (sum of its items, or entered
 * directly). The killer flow: snap a photo -> OpenAI vision estimates an ingredient
 * breakdown -> user corrects items on an editable screen -> save. `source` records how
 * it was logged (photo | manual | text). Daily totals + the 7-day trend read off these.
 */
class Meal extends Model
{
    use HasFactory;

    // Every way a meal is actually born. `coach` (chat log_meal) and `barcode` (Open Food Facts scan)
    // were written but missing here, so a validated `source` would reject them (MEAL_LOGGING_REVISION 1.3).
    public const SOURCES = ['photo', 'manual', 'text', 'coach', 'memory', 'barcode'];

    protected $fillable = [
        'profile_id', 'eaten_at', 'name', 'photo_path',
        'calories', 'protein_g', 'carbs_g', 'fat_g', 'fiber_g', 'macros_estimated', 'source', 'meal_type', 'notes',
    ];

    /** The meal's group for the sectioned day list — the stored override, or inferred from its time. */
    public function mealType(): string
    {
        return \App\Support\MealType::valid($this->meal_type)
            ? $this->meal_type
            : \App\Support\MealType::infer($this->eaten_at ?? now());
    }

    protected function casts(): array
    {
        return [
            'eaten_at' => 'datetime',
            'calories' => 'integer',
            'protein_g' => 'decimal:1',
            'carbs_g' => 'decimal:1',
            'fat_g' => 'decimal:1',
            'fiber_g' => 'decimal:1',   // secondary stat — not part of the energy reconcile
            'macros_estimated' => 'array',
        ];
    }

    protected static function booted(): void
    {
        // However a meal gets logged (photo scan, manual entry, coach tool, web), let the coach
        // decide whether to nudge on protein. afterCommit so it never fires inside a rolled-back
        // test transaction; the job self-gates (once/day, evening-only, behind-pace, opt-in).
        static::created(function (Meal $meal): void {
            // Fold every logged meal into the profile's reusable meal memory (one-tap re-log later).
            // Runs inline (incl. under tests) — it's a cheap deduped upsert, and the feature is tested.
            app(\App\Support\MealMemory::class)->remember($meal);

            // Skip the proactive protein NUDGE under tests — meal fixtures shouldn't fire it; the job's
            // logic is covered directly in MealProteinNudgeTest.
            if (app()->runningUnitTests() || ! class_exists(\App\Jobs\ReactToMealLogged::class)) {
                return;
            }
            \App\Jobs\ReactToMealLogged::dispatch($meal->id)->afterCommit();
        });
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(MealItem::class);
    }

    /** Public URL of the meal photo, or null if none / disk missing. */
    public function photoUrl(): ?string
    {
        return $this->photo_path ? Storage::disk('public')->url($this->photo_path) : null;
    }

    /** Recompute this meal's macros from the sum of its items and persist. */
    public function recalcFromItems(): void
    {
        $items = $this->items()->get();
        $this->calories = (int) $items->sum('calories');
        $this->protein_g = round((float) $items->sum('protein_g'), 1);
        $this->carbs_g = round((float) $items->sum('carbs_g'), 1);
        $this->fat_g = round((float) $items->sum('fat_g'), 1);
        // Fiber is a secondary stat: sum it only if at least one item reports it, else leave null
        // (unknown, not a misleading 0).
        $this->fiber_g = $items->whereNotNull('fiber_g')->isNotEmpty()
            ? round((float) $items->sum('fiber_g'), 1)
            : null;
        $this->macros_estimated = null;   // macros now come from real per-item data — nothing invented
        $this->save();
    }
}
