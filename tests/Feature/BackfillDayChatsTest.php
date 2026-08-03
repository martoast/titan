<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Coach\CoachBriefingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The one-shot re-file of pre-day-chats history. This runs against real data, so it is pinned
 * hard: messages land on their LOCAL day, nothing is lost, the old proactive thread keeps its
 * identity, stale summaries are dropped, and a second run is a no-op.
 */
class BackfillDayChatsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Write a message stamped at a local wall-clock time. No ->utc() conversion: APP_TIMEZONE sets
     * PHP's default zone, so Laravel stores these timestamps as local wall-clock already.
     */
    private function messageAt(Conversation $convo, string $role, string $content, string $localTime): ChatMessage
    {
        $m = $convo->messages()->create(['role' => $role, 'content' => $content]);
        $m->forceFill(['created_at' => Carbon::parse($localTime)])->saveQuietly();

        return $m->refresh();
    }

    public function test_it_files_legacy_messages_onto_their_local_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01 09:00'));
        $profile = User::factory()->create()->ensureProfile();

        $legacy = $profile->conversations()->create(['title' => 'I wanna get a bigger chest']);
        $this->messageAt($legacy, 'user', 'day one question', '2026-07-20 08:00');
        $this->messageAt($legacy, 'assistant', 'day one answer', '2026-07-20 08:01');
        $this->messageAt($legacy, 'user', 'day two question', '2026-07-22 19:00');

        $this->artisan('coach:backfill-day-chats')->assertSuccessful();

        $days = $profile->conversations()->days()->get();
        $this->assertCount(2, $days, 'two distinct days of messages → two day chats');

        $jul20 = $profile->conversations()->whereDate('day', '2026-07-20')->firstOrFail();
        $jul22 = $profile->conversations()->whereDate('day', '2026-07-22')->firstOrFail();

        $this->assertSame(2, $jul20->messages()->count());
        $this->assertSame(1, $jul22->messages()->count());
        $this->assertSame('day one question', $jul20->messages()->first()->content);
        $this->assertSame(3, ChatMessage::count(), 'nothing may be lost or duplicated');
    }

    /**
     * A late-evening message must stay on ITS day. Timestamps are stored as local wall-clock, so
     * the correct grouping applies no conversion — a stray ->utc() here would push a whole
     * evening's messages onto the next day, the most damaging way this command could be wrong.
     */
    public function test_a_late_evening_message_stays_on_its_own_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01 09:00'));
        $profile = User::factory()->create()->ensureProfile();

        $legacy = $profile->conversations()->create(['title' => 'late night']);
        $late = $this->messageAt($legacy, 'user', 'burning the midnight oil', '2026-07-20 22:30');

        $this->assertSame('2026-07-20 22:30:00', $late->created_at->format('Y-m-d H:i:s'), 'precondition: stored as local wall-clock');

        $this->artisan('coach:backfill-day-chats')->assertSuccessful();

        $this->assertNotNull(
            $profile->conversations()->whereDate('day', '2026-07-20')->first(),
            'a 22:30 message belongs to that day, not the next'
        );
        $this->assertNull($profile->conversations()->whereDate('day', '2026-07-21')->first());
    }

    public function test_the_old_briefings_thread_keeps_its_proactive_identity(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01 09:00'));
        $profile = User::factory()->create()->ensureProfile();

        $briefings = $profile->conversations()->create(['title' => CoachBriefingService::BRIEFINGS_TITLE]);
        $this->messageAt($briefings, 'assistant', 'Your band just synced.', '2026-07-20 07:00');

        $chat = $profile->conversations()->create(['title' => 'a normal chat']);
        $this->messageAt($chat, 'user', 'hey', '2026-07-20 10:00');
        $this->messageAt($chat, 'assistant', 'hey back', '2026-07-20 10:01');

        $this->artisan('coach:backfill-day-chats')->assertSuccessful();

        $day = $profile->conversations()->whereDate('day', '2026-07-20')->firstOrFail();
        $this->assertSame(3, $day->messages()->count(), 'both threads merge into the one day');

        $proactive = $day->messages()->whereNotNull('kind')->get();
        $this->assertCount(1, $proactive);
        $this->assertSame('Your band just synced.', $proactive->first()->content);

        // An ordinary reply must NOT be mislabelled as a proactive push.
        $this->assertNull($day->messages()->where('content', 'hey back')->first()->kind);
    }

    public function test_it_drops_stale_summaries_and_removes_emptied_threads(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01 09:00'));
        $profile = User::factory()->create()->ensureProfile();

        $legacy = $profile->conversations()->create(['title' => 'long one']);
        $first = $this->messageAt($legacy, 'user', 'one', '2026-07-20 08:00');
        $this->messageAt($legacy, 'user', 'two', '2026-07-21 08:00');
        $legacy->update(['summary' => 'a summary of a thread that no longer exists', 'summary_through_id' => $first->id]);

        $this->artisan('coach:backfill-day-chats')->assertSuccessful();

        $this->assertSame(0, Conversation::whereNull('day')->count(), 'emptied legacy threads are removed');
        foreach ($profile->conversations()->days()->get() as $day) {
            $this->assertNull($day->summary, 'a summary describing the old thread must not carry over');
            $this->assertNull($day->summary_through_id);
        }
    }

    public function test_it_is_idempotent(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01 09:00'));
        $profile = User::factory()->create()->ensureProfile();

        $legacy = $profile->conversations()->create(['title' => 'thread']);
        $this->messageAt($legacy, 'user', 'hello', '2026-07-20 08:00');

        $this->artisan('coach:backfill-day-chats')->assertSuccessful();
        $after = [Conversation::count(), ChatMessage::count()];

        $this->artisan('coach:backfill-day-chats')->assertSuccessful();

        $this->assertSame($after, [Conversation::count(), ChatMessage::count()], 'a second run must change nothing');
    }

    public function test_dry_run_writes_nothing(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01 09:00'));
        $profile = User::factory()->create()->ensureProfile();

        $legacy = $profile->conversations()->create(['title' => 'thread']);
        $this->messageAt($legacy, 'user', 'hello', '2026-07-20 08:00');

        $this->artisan('coach:backfill-day-chats', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(1, Conversation::count());
        $this->assertNull(Conversation::first()->day, 'dry run must not create day chats');
    }

    public function test_it_merges_into_a_day_chat_that_already_exists(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01 09:00'));
        $profile = User::factory()->create()->ensureProfile();

        // A day chat written by the new code path…
        $existing = Conversation::forDay($profile, '2026-07-20');
        $this->messageAt($existing, 'assistant', 'already here', '2026-07-20 06:00');

        // …plus a legacy thread with messages from the same day.
        $legacy = $profile->conversations()->create(['title' => 'old']);
        $this->messageAt($legacy, 'user', 'from the old thread', '2026-07-20 11:00');

        $this->artisan('coach:backfill-day-chats')->assertSuccessful();

        $existing->refresh();
        $this->assertSame(2, $existing->messages()->count());
        $this->assertSame(1, $profile->conversations()->whereDate('day', '2026-07-20')->count(), 'no duplicate day chat');
    }
}
