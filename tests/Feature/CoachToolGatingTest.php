<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Coach\CoachTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CoachToolGatingTest extends TestCase
{
    use RefreshDatabase;

    private function names(CoachTools $t): array
    {
        return array_map(fn ($s) => $s['function']['name'], $t->schemas());
    }

    public function test_default_toolset_is_core_only(): void
    {
        $names = $this->names(new CoachTools(User::factory()->create()->ensureProfile()));

        // Core is always present…
        $this->assertContains('daily_summary', $names);
        $this->assertContains('lookup_food', $names);
        $this->assertContains('autoregulate', $names);   // common + ambiguous → kept core
        $this->assertContains('load_tools', $names);
        // …specialized tools are gated out until needed.
        $this->assertNotContains('log_set', $names);
        $this->assertNotContains('generate_mesocycle', $names);
        $this->assertNotContains('research_topic', $names);
    }

    public function test_routing_pre_loads_the_relevant_group(): void
    {
        $p = User::factory()->create()->ensureProfile();

        $lifting = $this->names((new CoachTools($p))->route('I just benched 8 reps at 135'));
        $this->assertContains('log_set', $lifting);
        $this->assertNotContains('research_topic', $lifting);   // unrelated group stays gated

        $program = $this->names((new CoachTools($p))->route('build me a program to grow my chest'));
        $this->assertContains('generate_mesocycle', $program);

        $research = $this->names((new CoachTools($p))->route('can you research creatine for me'));
        $this->assertContains('research_topic', $research);
    }

    public function test_load_tools_unlocks_everything(): void
    {
        $p = User::factory()->create()->ensureProfile();
        $tools = new CoachTools($p);

        $this->assertNotContains('log_set', $this->names($tools));
        $tools->dispatch('load_tools', []);   // model calls it mid-loop
        $names = $this->names($tools);
        $this->assertContains('log_set', $names);
        $this->assertContains('generate_mesocycle', $names);
    }

    public function test_core_toolset_is_meaningfully_smaller(): void
    {
        $p = User::factory()->create()->ensureProfile();
        $core = count((new CoachTools($p))->schemas());
        $all = count((new CoachTools($p))->withAllTools()->schemas());
        $this->assertLessThan($all, $core);
        $this->assertLessThanOrEqual(36, $core);   // keep the per-turn set tight
    }
}
