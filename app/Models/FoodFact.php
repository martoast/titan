<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FoodFact extends Model
{
    protected $fillable = ['profile_id', 'name', 'basis', 'calories', 'protein_g', 'carbs_g', 'fat_g', 'source', 'hits'];

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

    /** Non-null only for a user's personal correction; null = the shared web cache. */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    /**
     * Find a food for a profile: their OWN correction wins, else the shared (profile_id null) cache.
     * Each scope matches exact-first, then a loose contains-match.
     */
    public static function findForProfile(string $name, ?int $profileId): ?self
    {
        if ($profileId !== null && ($own = self::fuzzyScoped($name, $profileId))) {
            return $own;
        }

        return self::fuzzyScoped($name, null);
    }

    /** Back-compat: the shared cache only. */
    public static function findFuzzy(string $name): ?self
    {
        return self::fuzzyScoped($name, null);
    }

    protected static function fuzzyScoped(string $name, ?int $profileId): ?self
    {
        if ($name === '') {
            return null;
        }

        $base = static::query();
        $profileId === null ? $base->whereNull('profile_id') : $base->where('profile_id', $profileId);

        $exact = (clone $base)->where('name', $name)->first();
        if ($exact) {
            return $exact;
        }

        return (clone $base)
            ->where(function ($q) use ($name) {
                $q->where('name', 'like', '%'.$name.'%')
                    ->orWhereRaw('? like concat("%", name, "%")', [$name]);
            })
            ->orderByRaw('length(name)')   // prefer the most specific (shortest containing) match
            ->first();
    }
}
