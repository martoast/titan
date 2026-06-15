<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * One weekly "step" of the living goal image. The user's latest progress photo, re-rendered
 * a calibrated increment toward their dream physique — the increment scaled by how consistent
 * they were (`adherence`). A chronological run of these rows IS the week-by-week progression
 * strip: the visible proof that the rendered "you" advances as you stay the course.
 */
class LivingGoalRender extends Model
{
    use HasFactory;

    protected $fillable = [
        'profile_id', 'physique_goal_id', 'progress_photo_id',
        'image_path', 'step_pct', 'adherence', 'adherence_breakdown',
    ];

    protected function casts(): array
    {
        return [
            'step_pct' => 'integer',
            'adherence' => 'decimal:3',
            'adherence_breakdown' => 'array',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function goal(): BelongsTo
    {
        return $this->belongsTo(PhysiqueGoal::class, 'physique_goal_id');
    }

    public function progressPhoto(): BelongsTo
    {
        return $this->belongsTo(ProgressPhoto::class);
    }

    /** Public URL of the rendered step image, or null if missing. */
    public function imageUrl(): ?string
    {
        return $this->image_path ? Storage::disk('public')->url($this->image_path) : null;
    }

    /** Adherence as a 0-100 percentage for display. */
    public function adherencePct(): ?int
    {
        return $this->adherence === null ? null : (int) round((float) $this->adherence * 100);
    }
}
