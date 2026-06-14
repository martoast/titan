<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * The product's signature artifact: an identity-preserved "dream physique" image.
 * `source_photo_path` is the original upload; `goal_image_path` is the Nano Banana
 * render of the same person with ~10 lbs more lean muscle. The active goal anchors
 * the before/after display, the "% to goal" progress bar, and the living-image morph.
 */
class PhysiqueGoal extends Model
{
    use HasFactory;

    protected $fillable = [
        'profile_id', 'source_photo_path', 'goal_image_path',
        'prompt', 'description', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    /** Public URL of the original uploaded photo. */
    public function sourceUrl(): ?string
    {
        return $this->source_photo_path ? Storage::disk('public')->url($this->source_photo_path) : null;
    }

    /** Public URL of the AI-generated dream physique. */
    public function goalUrl(): ?string
    {
        return $this->goal_image_path ? Storage::disk('public')->url($this->goal_image_path) : null;
    }
}
