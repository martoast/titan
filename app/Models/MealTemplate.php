<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * One entry in a profile's meal MEMORY — a distinct dish they've eaten, remembered so it can be
 * re-logged in one tap. Auto-upserted from every logged {@see Meal} (see {@see \App\Support\MealMemory}),
 * deduped on `key` (normalized name). `times_logged` + `last_eaten_at` rank the "your usuals" list.
 */
class MealTemplate extends Model
{
    protected $fillable = [
        'profile_id', 'key', 'name', 'photo_path',
        'calories', 'protein_g', 'carbs_g', 'fat_g',
        'times_logged', 'last_eaten_at', 'source', 'favorite',
    ];

    protected function casts(): array
    {
        return [
            'last_eaten_at' => 'datetime',
            'calories' => 'integer',
            'protein_g' => 'decimal:1',
            'carbs_g' => 'decimal:1',
            'fat_g' => 'decimal:1',
            'times_logged' => 'integer',
            'favorite' => 'boolean',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    /** Public URL of the representative photo, or null. */
    public function photoUrl(): ?string
    {
        return $this->photo_path ? Storage::disk('public')->url($this->photo_path) : null;
    }
}
