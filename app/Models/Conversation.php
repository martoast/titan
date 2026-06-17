<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A coach chat thread, profile-scoped. The coach page continues a profile's most
 * recent conversation or starts a fresh one. Owns an ordered list of ChatMessages.
 */
class Conversation extends Model
{
    use HasFactory;

    protected $fillable = ['profile_id', 'title', 'summary', 'summary_through_id'];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class)->orderBy('id');
    }

    /** A short label for the sidebar — its set title, else its first user message. */
    public function displayTitle(): string
    {
        if (filled($this->title)) {
            return $this->title;
        }

        $first = $this->messages()->where('role', 'user')->orderBy('id')->first();

        return $first ? \Illuminate\Support\Str::limit(trim((string) $first->content), 40) : 'New conversation';
    }
}
