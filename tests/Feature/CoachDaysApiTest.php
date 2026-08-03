<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The native app's day picker: GET /api/coach/days, and the stale-id tolerance on
 * GET /api/coach/{id}/messages that keeps a cached conversation from blanking the app.
 */
class CoachDaysApiTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function freeze(string $local): void
    {
        Carbon::setTestNow(Carbon::parse($local));
    }

    public function test_it_lists_days_newest_first_with_counts(): void
    {
        $this->freeze('2026-08-01 10:00');
        $user = User::factory()->create();
        $profile = $user->ensureProfile();

        $old = Conversation::forDay($profile, '2026-07-28');
        $old->messages()->create(['role' => 'user', 'content' => 'older']);

        $today = Conversation::forDay($profile);
        $today->messages()->create(['role' => 'user', 'content' => 'hi']);
        $today->messages()->create(['role' => 'assistant', 'content' => 'hey']);

        $resp = $this->actingAs($user)->getJson('/api/coach/days')->assertOk();

        $resp->assertJsonPath('today', '2026-08-01');
        $resp->assertJsonPath('days.0.label', 'Today');
        $resp->assertJsonPath('days.0.message_count', 2);
        $resp->assertJsonPath('days.0.is_today', true);
        $resp->assertJsonPath('days.1.day', '2026-07-28');
        $resp->assertJsonPath('days.1.is_today', false);
        $resp->assertJsonCount(2, 'days');
    }

    public function test_empty_days_are_omitted(): void
    {
        $this->freeze('2026-08-01 10:00');
        $user = User::factory()->create();
        $profile = $user->ensureProfile();

        Conversation::forDay($profile, '2026-07-20');   // created, never spoken in

        $this->actingAs($user)->getJson('/api/coach/days')->assertOk()->assertJsonCount(0, 'days');
    }

    public function test_another_profiles_days_are_not_listed(): void
    {
        $this->freeze('2026-08-01 10:00');
        $mine = User::factory()->create();
        $mine->ensureProfile();
        $theirs = User::factory()->create()->ensureProfile();

        Conversation::forDay($theirs, '2026-07-30')->messages()->create(['role' => 'user', 'content' => 'theirs']);

        $this->actingAs($mine)->getJson('/api/coach/days')->assertOk()->assertJsonCount(0, 'days');
    }

    /**
     * The bug this fixes: the app caches a conversation id, seeding/cleanup deletes it, and the
     * old route-model binding 404'd — which CoachView's `try?` swallowed, leaving a blank screen.
     */
    public function test_a_stale_conversation_id_falls_back_to_today(): void
    {
        $this->freeze('2026-08-01 10:00');
        $user = User::factory()->create();
        $profile = $user->ensureProfile();

        $today = Conversation::forDay($profile);
        $today->messages()->create(['role' => 'assistant', 'kind' => ChatMessage::KIND_BRIEFING, 'content' => 'Morning briefing']);

        $resp = $this->actingAs($user)->getJson('/api/coach/999999/messages')->assertOk();

        $resp->assertJsonPath('day', '2026-08-01');
        $resp->assertJsonPath('read_only', false);
        $resp->assertJsonPath('messages.0.content', 'Morning briefing');
        $resp->assertJsonPath('messages.0.kind', ChatMessage::KIND_BRIEFING);
    }

    public function test_another_profiles_conversation_id_falls_back_rather_than_leaking(): void
    {
        $this->freeze('2026-08-01 10:00');
        $mine = User::factory()->create();
        $profile = $mine->ensureProfile();
        Conversation::forDay($profile)->messages()->create(['role' => 'user', 'content' => 'mine']);

        $theirs = User::factory()->create()->ensureProfile();
        $secret = Conversation::forDay($theirs, '2026-07-30');
        $secret->messages()->create(['role' => 'user', 'content' => 'their secret']);

        $resp = $this->actingAs($mine)->getJson('/api/coach/'.$secret->id.'/messages')->assertOk();

        $resp->assertJsonPath('messages.0.content', 'mine');
        $resp->assertJsonMissing(['content' => 'their secret']);
    }

    public function test_a_valid_past_day_still_loads_and_is_read_only(): void
    {
        $this->freeze('2026-08-01 10:00');
        $user = User::factory()->create();
        $profile = $user->ensureProfile();

        $past = Conversation::forDay($profile, '2026-07-25');
        $past->messages()->create(['role' => 'user', 'content' => 'back then']);

        $resp = $this->actingAs($user)->getJson('/api/coach/'.$past->id.'/messages')->assertOk();

        $resp->assertJsonPath('day', '2026-07-25');
        $resp->assertJsonPath('read_only', true);
        $resp->assertJsonPath('messages.0.content', 'back then');
    }

    public function test_it_returns_an_empty_shape_when_nothing_has_been_said_at_all(): void
    {
        $this->freeze('2026-08-01 10:00');
        $user = User::factory()->create();
        $user->ensureProfile();

        $this->actingAs($user)->getJson('/api/coach/12345/messages')
            ->assertOk()
            ->assertJsonPath('read_only', false)
            ->assertJsonCount(0, 'messages');
    }
}
