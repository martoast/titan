<?php

namespace Tests\Feature;

use App\Jobs\ResearchTopic;
use App\Models\KnowledgePage;
use App\Models\User;
use App\Services\Ai\AiService;
use App\Services\Coach\CoachTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\TestCase;

class ResearchTopicTest extends TestCase
{
    use RefreshDatabase;

    public function test_tool_dispatches_a_research_job_with_the_conversation(): void
    {
        Bus::fake();
        $u = User::factory()->create();
        $p = $u->ensureProfile();
        $convo = $p->conversations()->create(['title' => 'chat']);

        $res = (new CoachTools($p, $convo))->dispatch('research_topic', ['topic' => 'the 5/3/1 strength program', 'focus' => 'for my bench']);
        $this->assertTrue($res['ok']);

        Bus::assertDispatched(ResearchTopic::class, function (ResearchTopic $job) use ($p, $convo) {
            return $job->profileId === $p->id
                && str_contains($job->topic, '5/3/1')
                && $job->conversationId === $convo->id;
        });
    }

    public function test_empty_topic_is_rejected(): void
    {
        $p = User::factory()->create()->ensureProfile();
        $res = (new CoachTools($p))->dispatch('research_topic', ['topic' => '   ']);
        $this->assertArrayHasKey('error', $res);
    }

    public function test_job_writes_a_brain_page_notifies_and_posts_to_chat(): void
    {
        // Stub the research service's AI calls: plan (json) → sections (chat) → applied (json).
        $ai = Mockery::mock(AiService::class);
        $ai->shouldReceive('configured')->andReturn(true);
        $ai->shouldReceive('embed')->andReturn([]);   // KnowledgeSearch best-effort embed
        $ai->shouldReceive('embedOne')->andReturn([]);
        $ai->shouldReceive('json')->once()->ordered()
            ->andReturn(['title' => 'Carb Cycling for Fat Loss', 'sections' => [['heading' => 'What it is', 'question' => 'define it']]]);
        $ai->shouldReceive('chat')->andReturn('Carb cycling alternates high- and low-carb days...');
        $ai->shouldReceive('json')->once()->ordered()
            ->andReturn(['applies' => '- Pair high-carb days with leg sessions.', 'summary' => 'Carb cycling times carbs to training. Useful in a cut.']);
        $this->app->instance(AiService::class, $ai);

        $u = User::factory()->create();
        $p = $u->ensureProfile();
        $convo = $p->conversations()->create(['title' => 'chat']);

        (new ResearchTopic($p->id, 'carb cycling for fat loss', null, $convo->id))
            ->handle(app(\App\Services\Coach\ResearchService::class), app(\App\Services\Notifications\NotificationService::class));

        // Filed in the Brain wiki…
        $page = KnowledgePage::where('profile_id', $p->id)->where('title', 'Carb Cycling for Fat Loss')->first();
        $this->assertNotNull($page);
        $this->assertStringContainsString('What it is', $page->content);
        $this->assertStringContainsString('How this applies to you', $page->content);

        // …notified…
        $this->assertDatabaseHas('notifications', ['profile_id' => $p->id, 'type' => 'research']);

        // …and dropped back into the chat thread.
        $last = $convo->messages()->where('role', 'assistant')->latest('id')->first();
        $this->assertStringContainsString('Carb Cycling for Fat Loss', $last->content);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
