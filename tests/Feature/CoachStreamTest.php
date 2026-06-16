<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Profile;
use App\Models\User;
use App\Services\Coach\CoachService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class CoachStreamTest extends TestCase
{
    use RefreshDatabase;

    public function test_stream_emits_tool_delta_done_and_suggestion_events(): void
    {
        $user = User::factory()->create();
        $profile = $user->ensureProfile();

        // Fake the coach so we exercise the SSE plumbing without hitting OpenAI.
        $mock = Mockery::mock(CoachService::class);
        $mock->shouldReceive('replyStreaming')
            ->once()
            ->andReturnUsing(function (Conversation $c, Profile $p, string $text, $onDelta, $onTool) {
                $onTool('daily_summary', 'Reading your day');
                $onDelta('Your ');
                $onDelta('HRV looks great.');

                return $c->messages()->create(['role' => 'assistant', 'content' => 'Your HRV looks great.']);
            });
        $mock->shouldReceive('suggestFollowUps')->once()->andReturn(['Why is my HRV up?']);
        $this->app->instance(CoachService::class, $mock);

        $response = $this->actingAs($user)->post('/coach/stream', ['message' => 'How were my vitals today?']);
        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8');

        $body = $response->streamedContent();
        $this->assertStringContainsString('event: tool', $body);
        $this->assertStringContainsString('Reading your day', $body);
        $this->assertStringContainsString('event: delta', $body);
        $this->assertStringContainsString('HRV looks great.', $body);
        $this->assertStringContainsString('event: done', $body);
        $this->assertStringContainsString('event: suggestions', $body);
        $this->assertStringContainsString('Why is my HRV up?', $body);
    }

    public function test_stream_emits_an_error_event_when_the_coach_is_offline(): void
    {
        $user = User::factory()->create();
        $user->ensureProfile();

        $mock = Mockery::mock(CoachService::class);
        $mock->shouldReceive('replyStreaming')
            ->once()
            ->andThrow(new \App\Exceptions\AiException('offline'));
        $this->app->instance(CoachService::class, $mock);

        $response = $this->actingAs($user)->post('/coach/stream', ['message' => 'hi']);
        $response->assertOk();

        $body = $response->streamedContent();
        $this->assertStringContainsString('event: error', $body);
        $this->assertStringContainsString('offline', $body);
    }
}
