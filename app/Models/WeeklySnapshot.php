<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WeeklySnapshot extends Model
{
    protected $fillable = ['profile_id', 'week_start', 'score', 'metrics', 'headline'];

    protected function casts(): array
    {
        return [
            'week_start' => 'date',
            'metrics' => 'array',
            'score' => 'integer',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }
}
