<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single browser Web Push endpoint owned by a profile. The unique key is
 * `endpoint_hash` (sha256 of the endpoint), kept in sync automatically whenever
 * `endpoint` is set, so callers can `updateOrCreate(['endpoint_hash' => …])` or just
 * assign the endpoint and save.
 *
 * Profile relationship lives HERE (belongsTo); we never add a hasMany to Profile, so
 * this build never touches Profile.php.
 */
class PushSubscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'profile_id', 'endpoint', 'endpoint_hash', 'p256dh', 'auth',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    /** Keep the hash in lock-step with the endpoint so uniqueness always holds. */
    public function setEndpointAttribute(?string $value): void
    {
        $this->attributes['endpoint'] = $value;
        $this->attributes['endpoint_hash'] = $value ? hash('sha256', $value) : null;
    }

    public static function hashFor(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }
}
