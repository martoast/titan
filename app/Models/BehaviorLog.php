<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One lifestyle factor logged for one day (the behavior journal). Joined to next-day physiology by
 * the correlation engine. See App\Support\Journal for the catalog + logging helpers.
 */
class BehaviorLog extends Model
{
    use HasFactory;

    protected $fillable = ['profile_id', 'logged_on', 'key', 'value', 'source'];

    protected function casts(): array
    {
        return ['logged_on' => 'date', 'value' => 'float'];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }
}
