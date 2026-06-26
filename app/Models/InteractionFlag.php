<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A cached "worth knowing" finding about a profile's active stack. INFORMATIONAL only —
 * literature/label data with a severity and a source, never a recommendation. Recomputed by
 * InteractionChecker whenever the stack changes.
 */
class InteractionFlag extends Model
{
    use HasFactory;

    /** Display order / severity ranking (high → low). */
    public const SEVERITIES = ['major', 'moderate', 'timing', 'info'];

    protected $fillable = [
        'profile_id', 'a_item_id', 'b_item_id', 'a_name', 'b_name',
        'severity', 'summary', 'source', 'checked_at',
    ];

    protected function casts(): array
    {
        return ['checked_at' => 'datetime'];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    /** Numeric rank for sorting (0 = most serious). */
    public function rank(): int
    {
        $i = array_search($this->severity, self::SEVERITIES, true);

        return $i === false ? count(self::SEVERITIES) : $i;
    }
}
