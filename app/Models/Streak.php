<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A consistency streak for one profile and one kind (e.g. "overall",
 * "meals_logged", "workouts"). Powers the brother-vs-brother duo dashboard.
 *
 * Profile does not pre-declare a streaks() relation, so query by profile_id
 * directly (Streak::where('profile_id', $id)...) and traverse back via profile().
 */
class Streak extends Model
{
    use HasFactory;

    protected $fillable = [
        'profile_id', 'kind', 'current_count', 'longest_count',
        'last_active_on', 'freezes_available',
    ];

    protected function casts(): array
    {
        return [
            'current_count' => 'integer',
            'longest_count' => 'integer',
            'freezes_available' => 'integer',
            'last_active_on' => 'date',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }
}
