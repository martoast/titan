<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * A dated progress photo, profile-scoped. The raw material of the physique loop:
 * gallery grid, side-by-side comparison, source for AI analysis, and the frame the
 * living goal image morphs forward. `pose` (front|side|back) keeps comparisons honest.
 */
class ProgressPhoto extends Model
{
    use HasFactory;

    public const POSES = ['front', 'side', 'back'];

    protected $fillable = [
        'profile_id', 'photo_path', 'taken_at', 'pose', 'weight_kg', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'taken_at' => 'date',
            'weight_kg' => 'decimal:2',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function analyses(): HasMany
    {
        return $this->hasMany(PhysiqueAnalysis::class);
    }

    /** Public URL of the photo, or null if none / disk missing. */
    public function photoUrl(): ?string
    {
        return $this->photo_path ? Storage::disk('public')->url($this->photo_path) : null;
    }
}
