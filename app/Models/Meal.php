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

    public const SOURCES = ['photo', 'manual', 'text'];

    protected $fillable = [
        'profile_id', 'eaten_at', 'name', 'photo_path',
        'calories', 'protein_g', 'carbs_g', 'fat_g', 'source', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'eaten_at' => 'datetime',
            'calories' => 'integer',
            'protein_g' => 'decimal:1',
            'carbs_g' => 'decimal:1',
            'fat_g' => 'decimal:1',
        ];
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
        $this->save();
    }
}
