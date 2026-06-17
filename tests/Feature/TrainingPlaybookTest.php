<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Coach\CoachTools;
use App\Support\TrainingPlaybook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrainingPlaybookTest extends TestCase
{
    use RefreshDatabase;

    public function test_lookup_matches_intent_to_topics(): void
    {
        $cut = TrainingPlaybook::lookup('how do I cut to single digit body fat for a photoshoot');
        $this->assertSame('nutrition_cutting', $cut['matched'][0]['topic']);

        $plateau = TrainingPlaybook::lookup('intensity techniques to break a chest plateau');
        $this->assertSame('intensity_techniques', $plateau['matched'][0]['topic']);

        $prog = TrainingPlaybook::lookup('program a hypertrophy mesocycle with deloads');
        $topics = array_column($prog['matched'], 'topic');
        $this->assertTrue(in_array('programming', $topics, true) || in_array('hypertrophy', $topics, true));
    }

    public function test_every_lookup_carries_the_natural_only_rail(): void
    {
        foreach (['build muscle', 'peak week', 'random gibberish xyz'] as $q) {
            $res = TrainingPlaybook::lookup($q);
            $this->assertNotEmpty($res['matched']);
            $this->assertStringContainsString('never prescribes', $res['disclaimer']);
        }
    }

    public function test_the_coach_tool_is_registered_and_returns_principles(): void
    {
        $p = User::factory()->create()->ensureProfile();
        $t = new CoachTools($p);

        $names = array_map(fn ($x) => $x['function']['name'], $t->schemas());
        $this->assertContains('coaching_playbook', $names);

        $res = $t->dispatch('coaching_playbook', ['topic' => 'lean gaining and protein for a natural lifter']);
        $this->assertNotEmpty($res['matched']);
        $this->assertSame('nutrition_muscle', $res['matched'][0]['topic']);
        $this->assertArrayHasKey('_show', $res);
    }
}
