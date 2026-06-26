<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A comment left on an activity. */
class ActivityComment extends Model
{
    protected $fillable = ['profile_id', 'activity_session_id', 'body'];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function activitySession(): BelongsTo
    {
        return $this->belongsTo(ActivitySession::class);
    }
}
