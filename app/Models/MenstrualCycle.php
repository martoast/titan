<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MenstrualCycle extends Model
{
    protected $fillable = [
        'profile_id', 'start_date', 'period_end_date', 'length_days', 'source', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'period_end_date' => 'date',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }
}
