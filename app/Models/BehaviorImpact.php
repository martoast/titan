<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A learned "behavior X → outcome Y by Z%" relationship for one profile (the correlation engine's
 * output row). Written by App\Support\BehaviorCorrelations; surfaced by the coach's my_impacts tool.
 */
class BehaviorImpact extends Model
{
    use HasFactory;

    protected $fillable = [
        'profile_id', 'behavior_key', 'outcome_key',
        'n_with', 'n_without', 'median_with', 'median_without',
        'pct_change', 'effect_size', 'p_value', 'q_value',
        'confidence', 'significant', 'discovered_at',
    ];

    protected function casts(): array
    {
        return [
            'median_with' => 'float', 'median_without' => 'float',
            'pct_change' => 'float', 'effect_size' => 'float',
            'p_value' => 'float', 'q_value' => 'float',
            'significant' => 'boolean', 'discovered_at' => 'datetime',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }
}
