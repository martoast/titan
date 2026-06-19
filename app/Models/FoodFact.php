<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FoodFact extends Model
{
    protected $fillable = ['name', 'basis', 'calories', 'protein_g', 'carbs_g', 'fat_g', 'source', 'hits'];

    protected function casts(): array
    {
        return [
            'calories' => 'integer',
            'protein_g' => 'float',
            'carbs_g' => 'float',
            'fat_g' => 'float',
            'hits' => 'integer',
        ];
    }

    /** Find a cached food by normalized name -- exact first, then a loose contains-match. */
    public static function findFuzzy(string $name): ?self
    {
        if ($name === '') {
            return null;
        }
        $exact = static::where('name', $name)->first();
        if ($exact) {
            return $exact;
        }

        return static::where('name', 'like', '%'.$name.'%')
            ->orWhere(fn ($q) => $q->whereRaw('? like concat("%", name, "%")', [$name]))
            ->orderByRaw('length(name)')   // prefer the most specific (shortest containing) match
            ->first();
    }
}
