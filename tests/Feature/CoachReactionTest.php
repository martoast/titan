<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Ai\AiService;
use App\Support\CoachReaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * COACH v2 · Phase 4 — proactive intelligence. A ReactTo* moment is grounded in the trajectory by the
 * model (card-led), but ALWAYS degrades to the caller's template when the model is unavailable or errors,
 * so a morning/workout note is never dropped.
 */
class CoachReactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_falls_back_to_template_when_ai_unconfigured_but_keeps_the_card(): void
    {
        $profile = User::factory()->create()->ensureProfile();
        $this->mock(AiService::class, fn ($m) => $m->shouldReceive('configured')->andReturn(false));

        $out = CoachReaction::ground($profile, "last night's sleep", 'facts', 'TEMPLATE BODY', ['type' => 'sleep', 'hours' => 7]);

        $this->assertStringContainsString('TEMPLATE BODY', $out);
        $this->assertStringContainsString('```titan-card', $out, 'card still leads even on the fallback path');
        $this->assertStringContainsString('"type":"sleep"', $out);
    }

    public function test_grounds_with_the_model_when_configured(): void
    {
        $profile = User::factory()->create()->ensureProfile();
        $this->mock(AiService::class, function ($m) {
            $m->shouldReceive('configured')->andReturn(true);
            $m->shouldReceive('chat')->once()->andReturn('Your HRV is trending up all week — keep the earlier bedtime going.');
        });

        $out = CoachReaction::ground($profile, "last night's sleep", 'facts', 'TEMPLATE', ['type' => 'sleep', 'hours' => 7]);

        $this->assertStringContainsString('trending up', $out);
        $this->assertStringNotContainsString('TEMPLATE', $out);
        $this->assertStringContainsString('```titan-card', $out);
    }

    public function test_falls_back_when_the_model_throws(): void
    {
        $profile = User::factory()->create()->ensureProfile();
        $this->mock(AiService::class, function ($m) {
            $m->shouldReceive('configured')->andReturn(true);
            $m->shouldReceive('chat')->andThrow(new \RuntimeException('model down'));
        });

        // No card this time → the fallback is returned verbatim, unbroken.
        $out = CoachReaction::ground($profile, "last night's sleep", 'facts', 'TEMPLATE BODY', null);
        $this->assertSame('TEMPLATE BODY', $out);
    }
}
