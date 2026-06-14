<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A dated body-composition snapshot: weight, body-fat %, and tape measurements.
 * Every measure is optional so a row may hold only what was logged that day.
 */
class BodyMetric extends Model
{
    use HasFactory;

    protected $fillable = [
        'profile_id', 'weight_kg', 'body_fat_pct',
        'waist_cm', 'chest_cm', 'arm_cm', 'thigh_cm',
        'taken_at', 'note',
    ];

    protected function casts(): array
    {
        return [
            'weight_kg' => 'decimal:2',
            'body_fat_pct' => 'decimal:2',
            'waist_cm' => 'decimal:2',
            'chest_cm' => 'decimal:2',
            'arm_cm' => 'decimal:2',
            'thigh_cm' => 'decimal:2',
            'taken_at' => 'date',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }
}
