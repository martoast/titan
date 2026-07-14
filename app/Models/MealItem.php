<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a meal's ingredient breakdown -- the unit the user edits to correct the
 * AI estimate. A meal's macros are the sum of these.
 */
class MealItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'meal_id', 'name', 'quantity',
        'calories', 'protein_g', 'carbs_g', 'fat_g', 'fiber_g',
    ];

    protected function casts(): array
    {
        return [
            'calories' => 'integer',
            'protein_g' => 'decimal:1',
            'carbs_g' => 'decimal:1',
            'fat_g' => 'decimal:1',
            'fiber_g' => 'decimal:1',   // secondary stat — not part of the energy reconcile
        ];
    }

    public function meal(): BelongsTo
    {
        return $this->belongsTo(Meal::class);
    }
}
