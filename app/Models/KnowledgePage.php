<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * One page in a profile's long-term-memory health wiki. Profile-scoped. Content is
 * markdown and may cross-reference other pages with [[Page Title]] wikilinks. Pinned
 * pages are the profile's "core memory" -- injected into the coach every turn.
 */
class KnowledgePage extends Model
{
    use HasFactory, SoftDeletes;

    public const TYPES = ['note', 'entity', 'concept', 'overview', 'research'];

    protected $fillable = ['profile_id', 'updated_by_user_id', 'title', 'slug', 'type', 'content', 'is_pinned', 'embedding', 'embed_hash'];

    protected function casts(): array
    {
        return ['is_pinned' => 'boolean', 'embedding' => 'array'];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /** The text we embed for semantic search (title carries strong signal). */
    public function embedText(): string
    {
        return trim($this->title."\n\n".(string) $this->content);
    }

    /** Hash of the embed source -- lets us skip re-embedding unchanged pages. */
    public function contentHash(): string
    {
        return md5($this->embedText());
    }

    public function scopePinned($query)
    {
        return $query->where('is_pinned', true);
    }

    public static function slugFor(string $title): string
    {
        return Str::slug($title) ?: 'page-'.Str::random(6);
    }

    /** Titles this page links to via [[wikilinks]]. @return array<int,string> */
    public function linkedTitles(): array
    {
        preg_match_all('/\[\[([^\]]+)\]\]/', (string) $this->content, $m);

        return array_values(array_unique(array_map('trim', $m[1] ?? [])));
    }

    /** Pages (within the same profile) that link TO this page (incoming wikilinks). */
    public function backlinks(): Collection
    {
        return static::query()
            ->where('profile_id', $this->profile_id)
            ->where('id', '!=', $this->id)
            ->where('content', 'like', '%[['.$this->title.']]%')
            ->orderBy('title')
            ->get();
    }
}
