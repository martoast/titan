<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Ai\AiService;
use App\Services\Coach\CoachService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ChatPaginationCompactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_messages_endpoint_paginates_newest_first_then_older(): void
    {
        $u = User::factory()->create();
        $p = $u->ensureProfile();
        $convo = $p->conversations()->create(['title' => 'Long chat']);
        foreach (range(1, 50) as $i) {
            $convo->messages()->create(['role' => $i % 2 ? 'user' : 'assistant', 'content' => "msg {$i}"]);
        }

        // Latest page: 20 most recent, has_more true.
        $first = $this->actingAs($u)->getJson("/coach/{$convo->id}/messages");
        $first->assertOk()->assertJsonPath('has_more', true);
        $this->assertCount(20, $first->json('messages'));
        $this->assertSame('msg 50', $first->json('messages.19.content'));   // chronological, newest last
        $this->assertSame('msg 31', $first->json('messages.0.content'));

        // Older page via ?before.
        $oldest = $first->json('oldest_id');
        $second = $this->actingAs($u)->getJson("/coach/{$convo->id}/messages?before={$oldest}");
        $this->assertSame('msg 11', $second->json('messages.0.content'));
        $this->assertSame('msg 30', $second->json('messages.19.content'));
    }

    public function test_messages_endpoint_is_scoped_to_the_owner(): void
    {
        $owner = User::factory()->create();
        $convo = $owner->ensureProfile()->conversations()->create(['title' => 'Private']);
        $this->actingAs(User::factory()->create())->getJson("/coach/{$convo->id}/messages")->assertNotFound();
    }

    public function test_long_conversation_gets_compacted_into_a_summary(): void
    {
        // Stub the AI: tool-calling reply returns a canned answer; the compaction summary call is asserted.
        $ai = Mockery::mock(AiService::class);
        $ai->shouldReceive('configured')->andReturn(true);
        $ai->shouldReceive('chat')->once()
            ->andReturn('SUMMARY: user is bulking, hates RDLs, hit chest PR.');
        $ai->shouldReceive('chatWithTools')->andReturn('Got it — keep going.');
        $this->app->instance(AiService::class, $ai);

        $u = User::factory()->create();
        $p = $u->ensureProfile();
        $convo = $p->conversations()->create(['title' => 'Marathon chat']);
        // Pre-seed 30 prior turns so the next reply crosses the compaction threshold (28).
        foreach (range(1, 30) as $i) {
            $convo->messages()->create(['role' => $i % 2 ? 'user' : 'assistant', 'content' => "old msg {$i}"]);
        }

        app(CoachService::class)->reply($convo->refresh(), $p, 'one more thing');

        $convo->refresh();
        $this->assertNotNull($convo->summary);
        $this->assertNotNull($convo->summary_through_id);
        $this->assertStringContainsString('bulking', $convo->summary);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
