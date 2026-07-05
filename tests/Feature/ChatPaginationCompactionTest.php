<?php

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\User;
use App\Services\Ai\AiService;
use App\Services\Brain\KnowledgeIngestor;
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

    public function test_reply_dispatches_compaction_off_the_request_path_over_threshold(): void
    {
        \Illuminate\Support\Facades\Bus::fake();

        $ai = Mockery::mock(AiService::class);
        $ai->shouldReceive('configured')->andReturn(true);
        $ai->shouldReceive('chatWithTools')->andReturn('Got it.');
        $this->app->instance(AiService::class, $ai);

        $u = User::factory()->create();
        $p = $u->ensureProfile();
        $convo = $p->conversations()->create(['title' => 'Marathon chat']);
        foreach (range(1, 30) as $i) {
            $convo->messages()->create(['role' => $i % 2 ? 'user' : 'assistant', 'content' => "old msg {$i}"]);
        }

        app(CoachService::class)->reply($convo->refresh(), $p, 'one more thing');
        \Illuminate\Support\Facades\Bus::assertDispatched(\App\Jobs\CompactConversation::class);
    }

    public function test_short_conversation_does_not_dispatch_compaction(): void
    {
        \Illuminate\Support\Facades\Bus::fake();

        $ai = Mockery::mock(AiService::class);
        $ai->shouldReceive('configured')->andReturn(true);
        $ai->shouldReceive('chatWithTools')->andReturn('Hi.');
        $this->app->instance(AiService::class, $ai);

        $u = User::factory()->create();
        $p = $u->ensureProfile();
        $convo = $p->conversations()->create(['title' => 'Short']);
        app(CoachService::class)->reply($convo, $p, 'hello');

        \Illuminate\Support\Facades\Bus::assertNotDispatched(\App\Jobs\CompactConversation::class);
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

    public function test_compaction_consolidates_durable_facts_into_long_term_memory(): void
    {
        // The compaction pass harvests durable facts from the turns being archived → the memory book,
        // so they survive summary compression (recallable later via search_knowledge).
        $ai = Mockery::mock(AiService::class);
        $ai->shouldReceive('configured')->andReturn(true);
        $ai->shouldReceive('chatWithTools')->andReturn('Got it — keep going.');
        $ai->shouldReceive('json')->andReturn(['facts' => [
            ['category' => 'dislike', 'content' => 'Hates Romanian deadlifts', 'importance' => 2],
            ['category' => 'injury', 'content' => 'Left shoulder impingement — avoid overhead press', 'importance' => 3],
            ['category' => 'not_a_category', 'content' => 'should be dropped', 'importance' => 2],
        ]]);
        $ai->shouldReceive('chat')->andReturn('SUMMARY: user is bulking.');
        $this->app->instance(AiService::class, $ai);

        $u = User::factory()->create();
        $p = $u->ensureProfile();
        $convo = $p->conversations()->create(['title' => 'Long chat']);
        foreach (range(1, 30) as $i) {
            $convo->messages()->create(['role' => $i % 2 ? 'user' : 'assistant', 'content' => "old msg {$i}"]);
        }

        app(CoachService::class)->reply($convo->refresh(), $p, 'one more thing');

        $mems = $p->coachMemories()->pluck('content')->all();
        $this->assertContains('Hates Romanian deadlifts', $mems);
        $this->assertContains('Left shoulder impingement — avoid overhead press', $mems);
        $this->assertNotContains('should be dropped', $mems);        // invalid category rejected
        $this->assertNotNull($convo->refresh()->summary);            // summary still produced
    }

    public function test_compaction_routes_richer_knowledge_into_wiki_pages(): void
    {
        // Narrative, multi-part knowledge is routed to the wiki via the Brain ingestor (which
        // merges into existing pages), not just the atomic memory book.
        $ai = Mockery::mock(AiService::class);
        $ai->shouldReceive('configured')->andReturn(true);
        $ai->shouldReceive('chatWithTools')->andReturn('Noted.');
        $ai->shouldReceive('json')->andReturn([
            'facts' => [],
            'wiki' => "## Training plan\nUpper/lower split, 4×/week, progressive overload on the main lifts.",
        ]);
        $ai->shouldReceive('chat')->andReturn('SUMMARY: planning training.');
        $this->app->instance(AiService::class, $ai);

        // The ingestor is called with the wiki dump → verified by the ->once() expectation at close().
        $ingestor = Mockery::mock(KnowledgeIngestor::class);
        $ingestor->shouldReceive('ingest')->once()
            ->with(Mockery::type(Profile::class), Mockery::type(User::class), Mockery::pattern('/Training plan/'))
            ->andReturn(['created' => [], 'updated' => [], 'message' => 'ok']);
        $this->app->instance(KnowledgeIngestor::class, $ingestor);

        $u = User::factory()->create();
        $p = $u->ensureProfile();
        $convo = $p->conversations()->create(['title' => 'Plan chat']);
        foreach (range(1, 30) as $i) {
            $convo->messages()->create(['role' => $i % 2 ? 'user' : 'assistant', 'content' => "old msg {$i}"]);
        }

        app(CoachService::class)->reply($convo->refresh(), $p, 'one more thing');

        $this->assertNotNull($convo->refresh()->summary);   // summary still produced alongside the wiki route
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
