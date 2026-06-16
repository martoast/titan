<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CycleLog extends Model
{
    protected $fillable = [
        'profile_id', 'logged_on', 'flow', 'symptoms', 'mood', 'energy', 'bbt_c', 'intimacy', 'source', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'logged_on' => 'date',
            'symptoms' => 'array',
            'bbt_c' => 'float',
            'intimacy' => 'boolean',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }
}
