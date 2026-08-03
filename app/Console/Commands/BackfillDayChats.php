<?php

namespace App\Console\Commands;

use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\Profile;
use App\Services\Coach\CoachBriefingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Re-file every pre-existing coach message into the day chat it belongs to.
 *
 * Before day-chats a profile had a handful of ad-hoc threads plus one never-ending "Daily
 * Briefings" thread that eight proactive jobs appended to — so "what did my coach tell me on the
 * 12th?" was unanswerable. This walks every message, groups it by the LOCAL date it was created,
 * and moves it into that day's conversation.
 *
 * Deliberately a command and not a migration: migrations auto-run on the app container at boot, and
 * a data reshuffle should not fire itself in the middle of a blue-green deploy. Idempotent — a
 * second run finds everything already filed and does nothing.
 *
 *   php artisan coach:backfill-day-chats --dry-run    # report only
 *   php artisan coach:backfill-day-chats
 */
class BackfillDayChats extends Command
{
    protected $signature = 'coach:backfill-day-chats {--dry-run : Report what would change without writing}';

    protected $description = 'Re-file legacy coach conversations into per-day chats';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $tz = Conversation::tz();

        $legacy = Conversation::whereNull('day')->get();
        if ($legacy->isEmpty()) {
            $this->info('Nothing to backfill — every conversation is already day-keyed.');

            return self::SUCCESS;
        }

        $this->line(sprintf('%s %d legacy conversation(s), timezone %s', $dry ? 'Would re-file' : 'Re-filing', $legacy->count(), $tz));

        // The old briefings thread is the one whose messages are all proactive coach pushes. We
        // can't tell a morning briefing from a workout reaction after the fact, so they all land as
        // `reaction` — the distinction only ever drove the briefing lookup, and new writes are tagged
        // correctly from here on.
        $briefingThreadIds = $legacy
            ->where('title', CoachBriefingService::BRIEFINGS_TITLE)
            ->pluck('id')
            ->all();

        $moved = 0;
        $tagged = 0;
        $created = 0;
        $daysTouched = [];

        foreach ($legacy->groupBy('profile_id') as $profileId => $threads) {
            $profile = Profile::find($profileId);
            if (! $profile) {
                $this->warn("  profile {$profileId} is gone — skipping ".$threads->count().' thread(s)');

                continue;
            }

            $messages = ChatMessage::whereIn('conversation_id', $threads->pluck('id'))
                ->orderBy('id')
                ->get();

            // Group by the date the message was written. No timezone conversion happens here, and
            // that is deliberate: APP_TIMEZONE sets PHP's default zone, so Laravel already writes
            // and reads these timestamps as LOCAL wall-clock (verified: a 14:17 CST write stores
            // "14:17"). Converting would shift every message by the UTC offset and file a whole
            // evening's worth onto the wrong day.
            $byDay = $messages->groupBy(fn (ChatMessage $m) => $m->created_at->toDateString());

            foreach ($byDay as $day => $rows) {
                $daysTouched[$profileId.'|'.$day] = true;

                if ($dry) {
                    $created++;
                    $moved += $rows->count();
                    $tagged += $rows->filter(
                        fn (ChatMessage $m) => $m->role === 'assistant' && in_array($m->conversation_id, $briefingThreadIds, true)
                    )->count();

                    continue;
                }

                DB::transaction(function () use ($profile, $day, $rows, $briefingThreadIds, &$moved, &$tagged, &$created) {
                    $existing = Conversation::where('profile_id', $profile->id)->whereDate('day', $day)->exists();
                    $target = Conversation::forDay($profile, $day);
                    if (! $existing) {
                        $created++;
                    }

                    foreach ($rows->chunk(200) as $chunk) {
                        // A proactive push keeps its identity; an ordinary chat turn stays untagged.
                        $proactive = $chunk->filter(
                            fn (ChatMessage $m) => $m->role === 'assistant' && in_array($m->conversation_id, $briefingThreadIds, true)
                        );
                        if ($proactive->isNotEmpty()) {
                            ChatMessage::whereIn('id', $proactive->pluck('id'))
                                ->update(['kind' => ChatMessage::KIND_REACTION]);
                            $tagged += $proactive->count();
                        }

                        $moved += ChatMessage::whereIn('id', $chunk->pluck('id'))
                            ->update(['conversation_id' => $target->id]);
                    }
                });
            }
        }

        if (! $dry) {
            // Stale carry-over: a legacy thread's running summary described a thread that no longer
            // exists, and its summary_through_id points at messages now spread across many days.
            Conversation::whereNotNull('day')
                ->whereNotNull('summary_through_id')
                ->update(['summary' => null, 'summary_through_id' => null]);

            // The emptied legacy threads have no reason to exist — and while they do, they show up
            // in nothing, since the chat list is day-keyed.
            $removed = Conversation::whereNull('day')
                ->whereDoesntHave('messages')
                ->delete();
            $this->line("  removed {$removed} emptied legacy thread(s)");

            $orphans = Conversation::whereNull('day')->count();
            if ($orphans > 0) {
                $this->warn("  {$orphans} legacy thread(s) still hold messages — re-run to inspect");
            }
        }

        $this->newLine();
        $this->info(sprintf(
            '%s %d message(s) into %d day chat(s) across %d profile-day(s); %d tagged as proactive.',
            $dry ? 'Would move' : 'Moved',
            $moved,
            $created,
            count($daysTouched),
            $tagged,
        ));

        if ($dry) {
            $this->comment('Dry run — nothing was written.');
        }

        return self::SUCCESS;
    }
}
