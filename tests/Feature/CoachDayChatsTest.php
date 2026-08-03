<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Day-scoped coach chats: one conversation per local day, the date as its identity, past days
 * readable but not writable, and the coach's memory rolling across the day boundary.
 */
class CoachDayChatsTest extends TestCase
{
    use RefreshDatabase;

    private function tz(): string
    {
        return Conversation::tz();
    }

    /** Freeze the clock at a local wall-clock time in the app's timezone. */
    private function freezeLocal(string $localDateTime): void
    {
        Carbon::setTestNow(Carbon::parse($localDateTime, $this->tz())->utc());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_days_chat_is_found_or_created_once_per_profile(): void
    {
        $this->freezeLocal('2026-08-01 09:00');
        $profile = User::factory()->create()->ensureProfile();

        $a = Conversation::forDay($profile);
        $b = Conversation::forDay($profile);

        $this->assertSame($a->id, $b->id, 'the same day must resolve to the same chat');
        $this->assertSame('2026-08-01', $a->day->toDateString());
        $this->assertSame(1, Conversation::where('profile_id', $profile->id)->count());
    }

    public function test_crossing_midnight_starts_a_new_chat(): void
    {
        $this->freezeLocal('2026-08-01 23:59');
        $profile = User::factory()->create()->ensureProfile();
        $yesterday = Conversation::forDay($profile);

        $this->freezeLocal('2026-08-02 00:01');
        $today = Conversation::forDay($profile);

        $this->assertNotSame($yesterday->id, $today->id);
        $this->assertSame('2026-08-02', $today->day->toDateString());
    }

    /**
     * The day is decided in LOCAL time. Late evening in America/Mexico_City is already the next
     * date in UTC — grouping on the raw stored timestamp would file the message under tomorrow.
     */
    public function test_the_day_boundary_is_local_not_utc(): void
    {
        $this->freezeLocal('2026-08-01 20:00');   // 2026-08-02 02:00 UTC
        $profile = User::factory()->create()->ensureProfile();

        $this->assertSame('2026-08-01', Conversation::forDay($profile)->day->toDateString());
        $this->assertSame('2026-08-02', Carbon::now()->utc()->toDateString(), 'precondition: UTC has already rolled over');
    }

    public function test_a_send_always_lands_in_todays_chat_even_from_an_old_day(): void
    {
        $this->freezeLocal('2026-08-01 10:00');
        $user = User::factory()->create();
        $profile = $user->ensureProfile();

        // A chat that already exists for a past day, with something in it.
        $past = Conversation::forDay($profile, '2026-07-20');
        $past->messages()->create(['role' => 'user', 'content' => 'old message']);

        // Post explicitly AT the old day's conversation — the client may have been sitting on it.
        $this->actingAs($user)
            ->postJson('/coach/'.$past->id.'/send', ['message' => 'today message'])
            ->assertOk();

        $this->assertSame(1, $past->messages()->count(), 'a past day must never be appended to');

        $today = Conversation::where('profile_id', $profile->id)->whereDate('day', '2026-08-01')->first();
        $this->assertNotNull($today);
        $this->assertSame('today message', $today->messages()->where('role', 'user')->first()->content);
    }

    public function test_the_chat_page_lists_days_and_opens_a_past_one_read_only(): void
    {
        $this->freezeLocal('2026-08-01 10:00');
        $user = User::factory()->create();
        $profile = $user->ensureProfile();

        $past = Conversation::forDay($profile, '2026-07-30');
        $past->messages()->create(['role' => 'user', 'content' => 'what did we say']);

        $today = Conversation::forDay($profile);
        $today->messages()->create(['role' => 'user', 'content' => 'hello']);

        // Default view = today, writable.
        $resp = $this->actingAs($user)->get('/coach');
        $resp->assertOk();
        $resp->assertViewHas('readOnly', false);
        $resp->assertSee('Thu, Jul 30');   // the past day appears in the list

        // Opening the past day makes it read-only.
        $this->actingAs($user)->get('/coach?c='.$past->id)
            ->assertOk()
            ->assertViewHas('readOnly', true);

        // …and it's reachable by date, not just by id.
        $this->actingAs($user)->get('/coach?d=2026-07-30')
            ->assertOk()
            ->assertViewHas('conversation', fn ($c) => $c->id === $past->id);
    }

    public function test_an_empty_day_is_not_listed(): void
    {
        $this->freezeLocal('2026-08-01 10:00');
        $user = User::factory()->create();
        $profile = $user->ensureProfile();

        Conversation::forDay($profile, '2026-07-25');   // created but never spoken in

        $resp = $this->actingAs($user)->get('/coach')->assertOk();
        $this->assertCount(0, $resp->viewData('conversations'), 'a day with no messages is noise in the list');
    }

    public function test_the_messages_endpoint_carries_the_day_and_per_message_stamps(): void
    {
        $this->freezeLocal('2026-08-01 14:30');
        $user = User::factory()->create();
        $profile = $user->ensureProfile();

        $convo = Conversation::forDay($profile);
        $convo->messages()->create(['role' => 'user', 'content' => 'hi']);
        $convo->messages()->create([
            'role' => 'assistant',
            'kind' => ChatMessage::KIND_BRIEFING,
            'content' => 'Morning briefing',
        ]);

        $resp = $this->actingAs($user)->getJson('/coach/'.$convo->id.'/messages')->assertOk();

        $resp->assertJsonPath('day', '2026-08-01');
        $resp->assertJsonPath('day_label', 'Today');
        $resp->assertJsonPath('read_only', false);
        $resp->assertJsonPath('messages.0.at', '2:30 PM');
        $resp->assertJsonPath('messages.1.kind', ChatMessage::KIND_BRIEFING);
        $resp->assertJsonPath('messages.0.kind', null);
    }

    public function test_day_labels_are_relative_then_dated(): void
    {
        $this->freezeLocal('2026-08-01 10:00');
        $profile = User::factory()->create()->ensureProfile();

        $this->assertSame('Today', Conversation::forDay($profile)->dayLabel());
        $this->assertSame('Yesterday', Conversation::forDay($profile, '2026-07-31')->dayLabel());
        $this->assertSame('Wed, Jul 29', Conversation::forDay($profile, '2026-07-29')->dayLabel());
        $this->assertSame('Dec 30, 2025', Conversation::forDay($profile, '2025-12-30')->dayLabel());
    }

    public function test_a_proactive_job_writes_into_todays_chat(): void
    {
        $this->freezeLocal('2026-08-01 07:15');
        $profile = User::factory()->create()->ensureProfile();

        // Stand in for the eight reaction jobs, which all resolve their target the same way.
        $convo = Conversation::forDay($profile);
        $convo->messages()->create([
            'role' => 'assistant',
            'kind' => ChatMessage::KIND_REACTION,
            'content' => 'Your band just synced.',
        ]);

        $today = Conversation::where('profile_id', $profile->id)->whereDate('day', '2026-08-01')->firstOrFail();
        $this->assertSame(1, $today->messages()->count());
        $this->assertTrue($today->messages()->first()->isProactive());
    }
}
