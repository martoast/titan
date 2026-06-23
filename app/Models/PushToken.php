<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A native-app push token (APNs/FCM) for one of a user's devices. Registered by the iOS app
 * after login; consumed by the notification layer to reach the device. See tasks/native-ios/.
 */
class PushToken extends Model
{
    protected $fillable = ['user_id', 'platform', 'token', 'environment', 'last_seen_at'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
