<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A coach chat, profile-scoped and keyed to ONE local calendar day (`day`). Everything the coach
 * and the user said to each other on a given day lives in that day's row — typed messages and the
 * coach's proactive briefings/reactions alike — so the chat list reads as a date-indexed journal
 * you scroll back through, instead of one endless thread.
 *
 * The day IS the key: there is no "new chat" button and no titles. Today's chat is resolved with
 * {@see forDay()}, which every write path funnels through.
 *
 * Legacy rows (created before day-chats) may still carry a NULL `day` and a `title`; the
 * `coach:backfill-day-chats` command re-files them.
 */
class Conversation extends Model
{
    use HasFactory;

    protected $fillable = ['profile_id', 'day', 'title', 'summary', 'summary_through_id'];

    protected function casts(): array
    {
        return ['day' => 'date'];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class)->orderBy('id');
    }

    /**
     * The timezone that defines a "day" here. Titan has one global zone (there is no per-profile
     * timezone yet) — this is the single place to widen that when there is one.
     */
    public static function tz(): string
    {
        return config('app.timezone') ?: 'UTC';
    }

    /** The current local date — what "today's chat" means. */
    public static function today(): Carbon
    {
        return Carbon::now(self::tz())->startOfDay();
    }

    /**
     * Find-or-create a profile's chat for one local day (default: today). This is the ONLY way a
     * conversation should be created — the web chat, the native app and all eight proactive coach
     * jobs funnel through it, which is exactly why it must be race-safe: the unique
     * (profile_id, day) index turns a concurrent double-create into a duplicate-key error, and we
     * re-read the winner instead of surfacing it.
     */
    public static function forDay(Profile $profile, CarbonInterface|string|null $when = null): self
    {
        $day = $when === null
            ? self::today()
            : Carbon::parse($when instanceof CarbonInterface ? $when->toDateString() : $when, self::tz())->startOfDay();

        return self::resolve($profile->id, $day->toDateString());
    }

    /**
     * The find-or-create itself. Reads go through whereDate rather than a plain equality match:
     * the `date` cast hands the driver a full datetime, which MySQL truncates into its DATE column
     * but SQLite stores verbatim as "2026-08-01 00:00:00" — so an equality lookup for "2026-08-01"
     * silently misses the row it just wrote, and every call creates a duplicate.
     */
    private static function resolve(int $profileId, string $day): self
    {
        $find = fn () => self::where('profile_id', $profileId)->whereDate('day', $day)->first();

        if ($found = $find()) {
            return $found;
        }

        try {
            return self::create(['profile_id' => $profileId, 'day' => $day]);
        } catch (QueryException $e) {
            // Lost the race to a concurrent job — the other writer's row is the one true row.
            return $find() ?? throw $e;
        }
    }

    /** Day chats only, newest day first — the chat list. */
    public function scopeDays(Builder $q): Builder
    {
        return $q->whereNotNull('day')->orderByDesc('day');
    }

    /** Is this the chat for the current local day (i.e. still open for sending)? */
    public function isToday(): bool
    {
        return $this->day !== null && $this->day->isSameDay(self::today());
    }

    /**
     * The chat-list label: "Today", "Yesterday", then weekday + date ("Wed, Jul 30"), adding the
     * year once we're outside the current one.
     */
    public function dayLabel(): string
    {
        if ($this->day === null) {
            return $this->displayTitle();
        }

        $today = self::today();

        if ($this->day->isSameDay($today)) {
            return 'Today';
        }
        if ($this->day->isSameDay($today->copy()->subDay())) {
            return 'Yesterday';
        }

        return $this->day->year === $today->year
            ? $this->day->format('D, M j')
            : $this->day->format('M j, Y');
    }

    /** The full unambiguous date — day dividers, tooltips, and the model's context. */
    public function dayFull(): string
    {
        return $this->day?->format('l, F j, Y') ?? $this->displayTitle();
    }

    /** A short label for a LEGACY (pre-day-chats) thread — its title, else its first user message. */
    public function displayTitle(): string
    {
        if (filled($this->title)) {
            return $this->title;
        }

        $first = $this->messages()->where('role', 'user')->orderBy('id')->first();

        return $first ? Str::limit(trim((string) $first->content), 40) : 'New conversation';
    }
}
