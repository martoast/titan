<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Coach\CoachTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CycleCoachToolsTest extends TestCase
{
    use RefreshDatabase;

    public function test_cycle_tools_are_offered_to_women_not_men(): void
    {
        $woman = User::factory()->create()->ensureProfile();
        $woman->update(['sex' => 'F']);
        $names = array_map(fn ($t) => $t['function']['name'], (new CoachTools($woman->refresh()))->withAllTools()->schemas());
        $this->assertContains('cycle_status', $names);
        $this->assertContains('log_period', $names);

        $man = User::factory()->create()->ensureProfile();
        $man->update(['sex' => 'M']);
        $mnames = array_map(fn ($t) => $t['function']['name'], (new CoachTools($man->refresh()))->withAllTools()->schemas());
        $this->assertNotContains('cycle_status', $mnames);
    }

    public function test_coach_can_log_a_period_and_read_status(): void
    {
        $p = User::factory()->create()->ensureProfile();
        $p->update(['sex' => 'F', 'settings' => ['timezone' => 'UTC']]);
        $tools = new CoachTools($p->refresh());

        $start = $tools->dispatch('log_period', ['event' => 'start', 'date' => '2026-06-01']);
        $this->assertTrue($start['ok']);
        $this->assertDatabaseHas('menstrual_cycles', ['profile_id' => $p->id, 'source' => 'coach']);

        $log = $tools->dispatch('log_cycle', ['date' => '2026-06-01', 'flow' => 'medium', 'symptoms' => ['cramps', 'fatigue']]);
        $this->assertTrue($log['ok']);
        $this->assertDatabaseHas('cycle_logs', ['profile_id' => $p->id, 'flow' => 'medium']);

        $status = $tools->dispatch('cycle_status', []);
        $this->assertTrue($status['has_data']);
        $this->assertArrayHasKey('phase', $status);
        $this->assertArrayHasKey('disclaimer', $status);  // the wellness rail travels with it
    }
}
