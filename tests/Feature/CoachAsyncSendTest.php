<?php

namespace Tests\Feature;

use App\Jobs\GenerateCoachReply;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\Profile;
use App\Models\User;
use App\Services\Coach\CoachService;
use App\Services\Coach\ScanService;
use App\Services\Notifications\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * The durable, survive-the-phone-suspending coach send: the request only persists the turn + a
 * pending placeholder and queues generation; a background job produces the reply independent of the
 * client connection, and the client polls the placeholder for it.
 */
class CoachAsyncSendTest extends TestCase
{
    use RefreshDatabase;

    public function test_send_async_persists_the_turn_and_queues_generation(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $user->ensureProfile();

        $res = $this->actingAs($user)->postJson('/api/coach/send-async', ['message' => 'How did I sleep?']);

        $res->assertStatus(202)
            ->assertJsonPath('ok', true)
            ->assertJsonStructure(['conversation_id', 'pending_message_id', 'user_message' => ['id', 'content']]);

        $pendingId = $res->json('pending_message_id');

        // The user message is saved up-front, and the assistant reply exists as a PENDING placeholder.
        $this->assertDatabaseHas('chat_messages', ['role' => 'user', 'content' => 'How did I sleep?']);
        $this->assertDatabaseHas('chat_messages', ['id' => $pendingId, 'role' => 'assistant', 'status' => 'pending']);

        // Generation is queued (off the request path), keyed to the placeholder — NOT run inline.
        Queue::assertPushed(GenerateCoachReply::class, fn ($job) => $job->assistantMessageId === $pendingId
            && $job->userText === 'How did I sleep?'
            && $job->imagePath === null);
    }

    public function test_send_async_rejects_an_empty_turn(): void
    {
        $user = User::factory()->create();
        $user->ensureProfile();

        $this->actingAs($user)->postJson('/api/coach/send-async', ['message' => '   '])
            ->assertStatus(422);
    }

    public function test_generate_job_fills_the_pending_placeholder_and_completes_it(): void
    {
        $user = User::factory()->create();
        $profile = $user->ensureProfile();
        $conversation = $profile->conversations()->create();
        $conversation->messages()->create(['role' => 'user', 'content' => 'hi']);
        $assistant = $conversation->messages()->create([
            'role' => 'assistant', 'content' => '', 'status' => ChatMessage::STATUS_PENDING,
        ]);

        // Stand in for the AI: the coach writes the answer into the placeholder + marks it complete.
        $coach = Mockery::mock(CoachService::class);
        $coach->shouldReceive('generate')->once()
            ->andReturnUsing(fn (Conversation $c, Profile $p, ChatMessage $m, string $text) => $m->update([
                'content' => 'You slept 7h 40m — solid.', 'status' => ChatMessage::STATUS_COMPLETE,
            ]));
        $notifications = Mockery::mock(NotificationService::class);
        $notifications->shouldReceive('notify')->once();

        (new GenerateCoachReply($conversation->id, $assistant->id, 'hi'))
            ->handle($coach, app(ScanService::class), $notifications);

        $assistant->refresh();
        $this->assertSame(ChatMessage::STATUS_COMPLETE, $assistant->status);
        $this->assertStringContainsString('7h 40m', (string) $assistant->content);
    }

    public function test_message_poll_endpoint_returns_status_and_is_owner_scoped(): void
    {
        $owner = User::factory()->create();
        $profile = $owner->ensureProfile();
        $conversation = $profile->conversations()->create();
        $msg = $conversation->messages()->create([
            'role' => 'assistant', 'content' => 'Partial…', 'status' => ChatMessage::STATUS_STREAMING,
        ]);

        $this->actingAs($owner)->getJson("/api/coach/messages/{$msg->id}")
            ->assertOk()
            ->assertJsonPath('status', 'streaming')
            ->assertJsonPath('content', 'Partial…');

        // Someone else's message is invisible.
        $this->actingAs(User::factory()->create())->getJson("/api/coach/messages/{$msg->id}")
            ->assertNotFound();
    }
}
