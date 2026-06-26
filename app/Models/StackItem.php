<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * A thing the user takes regularly — a supplement or a medication — with its dose and
 * schedule. Profile-scoped. The persistent half of "What you take"; the dated taken/skipped
 * log is IntakeEvent. `schedule` is { frequency, times[], days[], with_food } and the daily
 * checklist + adherence read off it. `dsld_id` / `rxcui` link to the NIH DSLD / RxNorm
 * catalogs so interaction checks have a stable identity to match on.
 */
class StackItem extends Model
{
    use HasFactory;

    public const KINDS = ['supplement', 'medication', 'other'];

    /** Time-of-day slots, in display order. */
    public const SLOTS = ['morning', 'midday', 'evening', 'night', 'anytime'];

    protected $fillable = [
        'profile_id', 'name', 'kind', 'brand', 'dose_amount', 'dose_unit', 'form',
        'schedule', 'dsld_id', 'rxcui', 'active', 'started_on', 'ended_on', 'photo_path', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'schedule' => 'array',
            'dose_amount' => 'decimal:2',
            'active' => 'boolean',
            'started_on' => 'date',
            'ended_on' => 'date',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function intakeEvents(): HasMany
    {
        return $this->hasMany(IntakeEvent::class);
    }

    /** "5000 IU", "10 mg", "2 caps" — or '' if no dose recorded. */
    public function doseLabel(): string
    {
        if ($this->dose_amount === null) {
            return '';
        }
        $amt = rtrim(rtrim(number_format((float) $this->dose_amount, 2, '.', ''), '0'), '.');

        return trim($amt.' '.(string) $this->dose_unit);
    }

    /** The time-of-day slots this item is scheduled for (defaults to anytime). @return array<int,string> */
    public function slots(): array
    {
        $times = $this->schedule['times'] ?? [];
        $times = array_values(array_intersect(self::SLOTS, is_array($times) ? $times : []));

        return $times ?: ['anytime'];
    }

    /** Is this item due on the given weekday? `days` empty/null = every day; 'as_needed' = never on the checklist. */
    public function dueOn(\Illuminate\Support\Carbon $day): bool
    {
        if (! $this->active) {
            return false;
        }
        $freq = $this->schedule['frequency'] ?? 'daily';
        if ($freq === 'as_needed') {
            return false;
        }
        if ($this->started_on && $day->lt($this->started_on->copy()->startOfDay())) {
            return false;
        }
        if ($this->ended_on && $day->gt($this->ended_on->copy()->endOfDay())) {
            return false;
        }
        $days = $this->schedule['days'] ?? null;
        if (is_array($days) && $days !== []) {
            return in_array(strtolower($day->format('D')), array_map('strtolower', $days), true);
        }

        return true;
    }

    public function photoUrl(): ?string
    {
        return $this->photo_path ? Storage::disk('public')->url($this->photo_path) : null;
    }

    /**
     * Rough adherence over the last N days: taken doses ÷ scheduled doses, capped at 100.
     * null for as-needed items (nothing to be "on track" against).
     */
    public function adherencePct(int $days = 14, ?int $takenCount = null): ?int
    {
        if (($this->schedule['frequency'] ?? 'daily') === 'as_needed') {
            return null;
        }
        $tz = $this->profile?->settings['timezone'] ?? config('app.timezone', 'UTC');
        $today = Carbon::now($tz)->startOfDay();
        $slotsPerDay = max(1, count($this->slots()));

        $expected = 0;
        for ($d = 0; $d < $days; $d++) {
            if ($this->dueOn($today->copy()->subDays($d))) {
                $expected += $slotsPerDay;
            }
        }
        if ($expected === 0) {
            return null;
        }

        // When the caller batched the counts (see takenCounts), use the injected value instead of
        // firing a per-item COUNT — avoids the N+1 when rendering a whole protocol.
        if ($takenCount === null) {
            $start = $today->copy()->subDays($days - 1)->setTimezone(config('app.timezone', 'UTC'));
            $takenCount = $this->intakeEvents()->where('status', 'taken')->where('taken_at', '>=', $start)->count();
        }

        return (int) round(min(100, $takenCount / $expected * 100));
    }

    /**
     * Taken-dose counts over the last $days for a whole set of items in ONE grouped query, keyed by
     * item id — feed each into adherencePct($days, $count) to render a protocol without an N+1.
     *
     * @param  \Illuminate\Support\Collection<int,StackItem>  $items
     * @return array<int,int>
     */
    public static function takenCounts(\Illuminate\Support\Collection $items, int $days = 14): array
    {
        if ($items->isEmpty()) {
            return [];
        }
        $tz = $items->first()->profile?->settings['timezone'] ?? config('app.timezone', 'UTC');
        $start = Carbon::now($tz)->startOfDay()->subDays($days - 1)->setTimezone(config('app.timezone', 'UTC'));

        return IntakeEvent::query()
            ->whereIn('stack_item_id', $items->pluck('id'))
            ->where('status', 'taken')
            ->where('taken_at', '>=', $start)
            ->groupBy('stack_item_id')
            ->selectRaw('stack_item_id, count(*) as c')
            ->pluck('c', 'stack_item_id')
            ->all();
    }
}
