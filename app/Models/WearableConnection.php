<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A profile's connected biosignal source — device-agnostic. May be a Terra link, the
 * open-source Titan band, a Bangle.js, a Polar account, or an Apple Health export.
 *
 * Two resolution paths back to a profile:
 *   - Terra:  webhooks matched via `terra_user_id`.
 *   - Devices: signed requests matched via `device_id`; HMAC verified against the
 *     per-device secret whose sha256 lives in `device_token_hash` (secret shown once
 *     at pairing, never stored). Revocation = null the hash.
 */
class WearableConnection extends Model
{
    protected $fillable = [
        'profile_id', 'provider', 'source', 'device_token_hash', 'device_id',
        'timezone', 'terra_user_id', 'scopes', 'status',
        'last_webhook_at', 'last_sync_at', 'last_payload_type',
    ];

    protected $hidden = ['device_token_hash'];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'last_webhook_at' => 'datetime',
            'last_sync_at' => 'datetime',
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

    /** Effective timezone for this device's owner, falling back to the app tz. */
    public function effectiveTimezone(): string
    {
        return $this->timezone ?: (string) config('app.timezone', 'UTC');
    }
}
