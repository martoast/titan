<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Coach\CoachTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CoachUniversalToolsTest extends TestCase
{
    use RefreshDatabase;

    private function tools(): CoachTools
    {
        return new CoachTools(User::factory()->create()->ensureProfile());
    }

    public function test_the_chat_can_log_across_modules(): void
    {
        $p = User::factory()->create()->ensureProfile();
        $t = new CoachTools($p);

        $this->assertTrue($t->dispatch('log_meal', ['name' => 'Chicken bowl', 'calories' => 600, 'protein_g' => 50])['ok']);
        $this->assertTrue($t->dispatch('log_weight', ['weight_kg' => 80.5])['ok']);
        $this->assertTrue($t->dispatch('log_recovery', ['resting_hr' => 54, 'hrv_ms' => 70])['ok']);
        $this->assertTrue($t->dispatch('log_sleep', ['hours' => 7.5])['ok']);
        $this->assertTrue($t->dispatch('log_biomarker', ['marker' => 'LDL', 'value' => 110, 'unit' => 'mg/dL'])['ok']);
        $this->assertTrue($t->dispatch('log_cardio', ['type' => 'run', 'duration_min' => 30])['ok']);
        $this->assertTrue($t->dispatch('set_goal', ['goal' => 'get lean for summer'])['ok']);

        $this->assertDatabaseHas('meals', ['profile_id' => $p->id, 'name' => 'Chicken bowl', 'source' => 'coach']);
        $this->assertDatabaseHas('body_metrics', ['profile_id' => $p->id]);
        $this->assertDatabaseHas('recovery_logs', ['profile_id' => $p->id, 'resting_hr' => 54]);
        $this->assertDatabaseHas('sleep_logs', ['profile_id' => $p->id]);
        $this->assertDatabaseHas('biomarker_readings', ['profile_id' => $p->id, 'marker' => 'ldl', 'source' => 'coach']);
        $this->assertSame('get lean for summer', $p->refresh()->primary_goal);
    }

    public function test_pantry_round_trip(): void
    {
        $p = User::factory()->create()->ensureProfile();
        $t = new CoachTools($p);

        $t->dispatch('update_pantry', ['items' => 'ground beef, eggs, tuna', 'mode' => 'add']);
        $pantry = $t->dispatch('get_pantry', []);
        $this->assertSame(3, $pantry['count']);
        $this->assertContains('eggs', $pantry['items']);
    }

    public function test_show_trend_returns_points_for_a_sparkline(): void
    {
        $p = User::factory()->create()->ensureProfile();
        foreach ([82, 81.5, 81, 80.2, 79.8] as $i => $w) {
            $p->bodyMetrics()->create(['taken_at' => Carbon::today()->subDays(20 - $i * 4)->toDateString(), 'weight_kg' => $w]);
        }
        $res = (new CoachTools($p))->dispatch('show_trend', ['metric' => 'weight', 'days' => 60]);
        $this->assertSame('Weight', $res['label']);
        $this->assertCount(5, $res['points']);
        $this->assertStringContainsString('titan-card', $res['_show']);
    }

    public function test_render_dream_physique_guides_when_no_photo(): void
    {
        $res = $this->tools()->dispatch('render_dream_physique', []);
        // No photo (or no image key) → a helpful error, never a crash.
        $this->assertArrayHasKey('error', $res);
    }

    public function test_new_tools_are_registered(): void
    {
        $names = array_map(fn ($t) => $t['function']['name'], $this->tools()->withAllTools()->schemas());
        foreach (['log_meal', 'log_weight', 'log_recovery', 'log_sleep', 'log_biomarker', 'log_cardio', 'set_goal', 'get_pantry', 'update_pantry', 'show_trend', 'render_dream_physique'] as $n) {
            $this->assertContains($n, $names, "missing tool: {$n}");
        }
    }
}
