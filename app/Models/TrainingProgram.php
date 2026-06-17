<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrainingProgram extends Model
{
    protected $fillable = [
        'profile_id', 'name', 'focus', 'days_per_week', 'weeks', 'split',
        'experience', 'plan', 'volume', 'started_on', 'current_week', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'focus' => 'array',
            'plan' => 'array',
            'volume' => 'array',
            'started_on' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    /** @return array<string,mixed>|null the plan for the current week */
    public function currentWeek(): ?array
    {
        $idx = max(0, min(count($this->plan) - 1, ((int) $this->current_week) - 1));

        return $this->plan[$idx] ?? null;
    }
}
