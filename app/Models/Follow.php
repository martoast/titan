<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A directed follow edge: {@see $follower} wants {@see $followee}'s activities in their feed.
 * `status` is `accepted` immediately for open accounts, or `pending` until the followee approves
 * a private account's request.
 */
class Follow extends Model
{
    public const PENDING = 'pending';

    public const ACCEPTED = 'accepted';

    protected $fillable = ['follower_id', 'followee_id', 'status', 'accepted_at'];

    protected function casts(): array
    {
        return ['accepted_at' => 'datetime'];
    }

    public function follower(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'follower_id');
    }

    public function followee(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'followee_id');
    }
}
