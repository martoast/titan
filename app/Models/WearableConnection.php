<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A profile's connected wearable (via Terra). Webhooks are resolved back to a
 * profile through `terra_user_id`.
 */
class WearableConnection extends Model
{
    protected $fillable = [
        'profile_id', 'provider', 'terra_user_id', 'scopes',
        'status', 'last_webhook_at', 'last_payload_type',
    ];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'last_webhook_at' => 'datetime',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function isConnected(): bool
    {
        return $this->status === 'connected';
    }
}
