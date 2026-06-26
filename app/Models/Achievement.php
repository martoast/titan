<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An earned milestone/badge (first 10K, 100km month, weekly streak…). One row per (profile, key);
 * the catalog of keys + their display copy lives in {@see \App\Services\Community\AchievementEngine}.
 */
class Achievement extends Model
{
    protected $fillable = ['profile_id', 'key', 'activity_session_id', 'awarded_at', 'meta'];

    protected function casts(): array
    {
        return [
            'awarded_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function activitySession(): BelongsTo
    {
        return $this->belongsTo(ActivitySession::class);
    }
}
