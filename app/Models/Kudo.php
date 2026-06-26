<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A cheer on an activity — the like/motivation loop. One per (profile, activity). */
class Kudo extends Model
{
    protected $table = 'kudos';

    protected $fillable = ['profile_id', 'activity_session_id'];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function activitySession(): BelongsTo
    {
        return $this->belongsTo(ActivitySession::class);
    }
}
