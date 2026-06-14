<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An AI physique read on a progress photo. Body-fat is a confidence-aware RANGE
 * (low/high) — never fake precision — with per-muscle-group ratings (1-10) and a
 * supportive, non-medical summary. `pct_to_goal` is set when an active dream-physique
 * goal exists and the latest photo is compared against the goal image.
 */
class PhysiqueAnalysis extends Model
{
    use HasFactory;

    public const MUSCLE_GROUPS = ['chest', 'back', 'shoulders', 'arms', 'legs', 'core'];

    protected $fillable = [
        'profile_id', 'progress_photo_id', 'body_fat_pct_low', 'body_fat_pct_high',
        'muscle_ratings', 'pct_to_goal', 'summary',
    ];

    protected function casts(): array
    {
        return [
            'body_fat_pct_low' => 'decimal:2',
            'body_fat_pct_high' => 'decimal:2',
            'muscle_ratings' => 'array',
            'pct_to_goal' => 'integer',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function progressPhoto(): BelongsTo
    {
        return $this->belongsTo(ProgressPhoto::class);
    }

    /** Human-friendly body-fat range, e.g. "14–17%" — or null if not estimated. */
    public function bodyFatRange(): ?string
    {
        if ($this->body_fat_pct_low === null || $this->body_fat_pct_high === null) {
            return null;
        }

        return rtrim(rtrim((string) $this->body_fat_pct_low, '0'), '.').'–'
            .rtrim(rtrim((string) $this->body_fat_pct_high, '0'), '.').'%';
    }
}
