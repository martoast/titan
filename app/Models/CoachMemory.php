<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CoachMemory extends Model
{
    /** category => [label, emoji] — the shape of what a coach remembers about someone. */
    public const CATEGORIES = [
        'injury' => ['Injuries & limitations', '🩹'],
        'preference' => ['Preferences', '✅'],
        'dislike' => ['Dislikes', '🚫'],
        'equipment' => ['Equipment & access', '🏋️'],
        'schedule' => ['Schedule & availability', '📅'],
        'nutrition' => ['Food & nutrition', '🥗'],
        'response' => ["What's worked for them", '📈'],
        'goal' => ['Goals', '🎯'],
        'commitment' => ['Commitments', '🤝'],
        'life' => ['Life context', '🌍'],
        'misc' => ['Other', '💡'],
    ];

    protected $fillable = [
        'profile_id', 'category', 'content', 'importance', 'source', 'last_referenced_at', 'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'importance' => 'integer',
            'last_referenced_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function label(): string
    {
        return (self::CATEGORIES[$this->category] ?? self::CATEGORIES['misc'])[0];
    }

    public function emoji(): string
    {
        return (self::CATEGORIES[$this->category] ?? self::CATEGORIES['misc'])[1];
    }
}
