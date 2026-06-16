<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * An AI-suggested meal: a name, macros that fit the user's next-meal target, a simple recipe
 * (ingredients + steps), and a generated photo. Tap a suggestion → see the recipe → log it.
 */
class MealSuggestion extends Model
{
    use HasFactory;

    protected $fillable = [
        'profile_id', 'name', 'description', 'calories', 'protein_g', 'carbs_g', 'fat_g',
        'ingredients', 'steps', 'image_path', 'context',
    ];

    protected function casts(): array
    {
        return [
            'calories' => 'integer',
            'protein_g' => 'decimal:1',
            'carbs_g' => 'decimal:1',
            'fat_g' => 'decimal:1',
            'ingredients' => 'array',
            'steps' => 'array',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    /** Public URL of the generated meal photo, or null if none yet. */
    public function imageUrl(): ?string
    {
        return $this->image_path ? Storage::disk('public')->url($this->image_path) : null;
    }
}
