<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An in-app notification for a profile -- surfaced in the header bell + notification
 * center. Created alongside (and independently of) a Web Push by NotificationService,
 * so the user sees it whether or not push is granted. `read_at` null = unread.
 *
 * We deliberately keep the profile relationship HERE (belongsTo) and query by
 * profile_id rather than adding a hasMany to Profile, so this build never touches
 * Profile.php.
 */
class Notification extends Model
{
    use HasFactory;

    protected $fillable = [
        'profile_id', 'type', 'title', 'body', 'url', 'read_at',
    ];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function isUnread(): bool
    {
        return $this->read_at === null;
    }
}
