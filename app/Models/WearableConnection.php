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
        'last_webhook_at', 'last_sync_at', 'last_payload_type', 'battery_pct', 'firmware', 'pending_commands',
    ];

    protected $hidden = ['device_token_hash'];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'pending_commands' => 'array',
            'last_webhook_at' => 'datetime',
            'last_sync_at' => 'datetime',
        ];
    }

    /** Queue a coach → band command (buzz, sync…) for the band to pick up on its next check-in. */
    public function queueCommand(string $type, array $args = []): void
    {
        $cmds = $this->pending_commands ?? [];
        $cmds[] = array_filter(['type' => $type, 'args' => $args ?: null, 'at' => now()->toIso8601String()], fn ($v) => $v !== null);
        $this->forceFill(['pending_commands' => array_slice($cmds, -10)])->save();   // cap the backlog
    }

    /** Hand the band its pending commands and clear the queue. */
    public function drainCommands(): array
    {
        $cmds = $this->pending_commands ?? [];
        if ($cmds !== []) {
            $this->forceFill(['pending_commands' => []])->save();
        }

        return $cmds;
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
