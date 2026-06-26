<?php

namespace App\Support;

use App\Models\IntakeEvent;
use App\Models\Profile;
use App\Models\StackItem;
use Illuminate\Support\Carbon;

/**
 * Today's "What you take" as a ready-made card: the doses scheduled for today, grouped by
 * time-of-day, each with whether it's been taken yet. Shared by the Daily card (web + iOS)
 * and the coach's my_stack tool, so one tap to log and one glance to read always agree.
 *
 * Deliberately calm: progress is carried by the checkmarks, not a scoreboard — a missed dose
 * is never framed as a failure.
 */
class Stack
{
    public const SLOT_LABELS = [
        'morning' => 'Morning', 'midday' => 'Midday', 'evening' => 'Evening',
        'night' => 'Night', 'anytime' => 'Anytime',
    ];

    /** @return array<string,mixed> the `stack` titan-card payload */
    public static function today(Profile $profile): array
    {
        $appTz = config('app.timezone', 'UTC');
        $tz = $profile->settings['timezone'] ?? $appTz;
        $localToday = Carbon::now($tz)->startOfDay();
        $start = $localToday->copy()->setTimezone($appTz);
        $end = $start->copy()->addDay();

        $items = $profile->stackItems()->where('active', true)->orderBy('id')->get()
            ->filter(fn (StackItem $i) => $i->dueOn($localToday));

        // Today's taken events, indexed for matching against scheduled rows.
        $events = $profile->intakeEvents()
            ->where('taken_at', '>=', $start)->where('taken_at', '<', $end)
            ->get();
        $takenBySlot = [];   // "itemId:slot" => event
        $takenNoSlot = [];   // itemId => [events] with null slot
        foreach ($events->where('status', 'taken') as $e) {
            if ($e->stack_item_id === null) {
                continue;
            }
            if ($e->slot) {
                $takenBySlot[$e->stack_item_id.':'.$e->slot] ??= $e;
            } else {
                $takenNoSlot[$e->stack_item_id][] = $e;
            }
        }

        $slots = [];
        $total = 0;
        $taken = 0;
        foreach (StackItem::SLOTS as $slotKey) {
            $rows = [];
            foreach ($items as $item) {
                if (! in_array($slotKey, $item->slots(), true)) {
                    continue;
                }
                $event = $takenBySlot[$item->id.':'.$slotKey] ?? null;
                if (! $event && ! empty($takenNoSlot[$item->id])) {
                    $event = array_shift($takenNoSlot[$item->id]);   // a slot-less log satisfies the next slot
                }
                $total++;
                if ($event) {
                    $taken++;
                }
                $rows[] = [
                    'id' => $item->id,
                    'event_id' => $event?->id,
                    'name' => $item->name,
                    'dose' => $item->doseLabel(),
                    'kind' => $item->kind,
                    'with_food' => (bool) ($item->schedule['with_food'] ?? false),
                    'slot' => $slotKey,
                    'taken' => (bool) $event,
                ];
            }
            if ($rows !== []) {
                $slots[] = [
                    'key' => $slotKey,
                    'label' => self::SLOT_LABELS[$slotKey] ?? ucfirst($slotKey),
                    'items' => $rows,
                ];
            }
        }

        // Anything taken that wasn't on today's schedule (one-offs, "extra") — a quiet tally only.
        $extra = $events->whereNull('stack_item_id')->where('status', 'taken')->count();

        $flags = $profile->interactionFlags()->count();

        return [
            'type' => 'stack',
            'title' => 'What you take',
            'slots' => $slots,
            'counts' => ['taken' => $taken, 'total' => $total, 'extra' => $extra],
            'worth_knowing' => $flags,
            'footer' => self::footer($taken, $total),
        ];
    }

    /** A `stack` card as a ready-to-embed titan-card fenced block (for the coach chat). */
    public static function fenced(Profile $profile): string
    {
        return "```titan-card\n".json_encode(self::today($profile))."\n```";
    }

    private static function footer(int $taken, int $total): string
    {
        if ($total === 0) {
            return 'Nothing scheduled today';
        }
        if ($taken >= $total) {
            return 'All in for today';
        }

        return $taken.' of '.$total.' taken';
    }
}
