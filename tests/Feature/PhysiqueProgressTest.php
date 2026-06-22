<?php

namespace Tests\Feature;

use App\Models\PhysiqueGoal;
use App\Models\User;
use App\Services\Coach\CoachTools;
use App\Support\PhysiqueProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhysiqueProgressTest extends TestCase
{
    use RefreshDatabase;

    private function withGoal(float $adherence = 0.7, int $stepPct = 35): User
    {
        $u = User::factory()->create();
        $p = $u->ensureProfile();
        $p->update(['primary_goal' => 'Build muscle']);
        $goal = $p->physiqueGoals()->create([
            'description' => 'Lean and muscular, visible abs', 'is_active' => true, 'prompt' => 'x',
            'source_photo_path' => 'physique/src.png', 'goal_image_path' => 'physique/goal.png',
        ]);
        $p->livingGoalRenders()->create(['physique_goal_id' => $goal->id, 'image_path' => 'physique/living/r.png', 'step_pct' => $stepPct, 'adherence' => $adherence, 'adherence_breakdown' => []]);

        return $u->refresh();
    }

    public function test_assess_reads_step_pct_and_an_on_track_verdict(): void
    {
        $a = PhysiqueProgress::assess($this->withGoal(0.7, 40)->profile);

        $this->assertSame(40, $a['step_pct']);
        $this->assertSame('on_track', $a['verdict']);
        $this->assertSame(70, $a['adherence_pct']);
        $this->assertNotNull($a['eta_weeks']);          // (100-40)/(0.7*12) ≈ 8
    }

    public function test_low_adherence_reads_as_behind(): void
    {
        $a = PhysiqueProgress::assess($this->withGoal(0.3, 10)->profile);
        $this->assertSame('behind', $a['verdict']);
    }

    public function test_assess_is_null_without_a_goal(): void
    {
        $u = User::factory()->create();
        $this->assertNull(PhysiqueProgress::assess($u->ensureProfile()));
    }

    public function test_digest_is_the_north_star_line(): void
    {
        $digest = PhysiqueProgress::digest($this->withGoal(0.7, 40)->profile);
        $this->assertStringContainsString('40%', $digest);
        $this->assertStringContainsString('Lean and muscular', $digest);
    }

    public function test_tool_returns_a_physique_card(): void
    {
        $res = (new CoachTools($this->withGoal()->profile))->dispatch('physique_progress', []);
        $this->assertSame('physique', $res['card']['type']);
        $this->assertArrayHasKey('step_pct', $res['card']);
    }

    public function test_tool_guides_when_no_goal(): void
    {
        $u = User::factory()->create();
        $res = (new CoachTools($u->ensureProfile()))->dispatch('physique_progress', []);
        $this->assertArrayHasKey('note', $res);
    }

    public function test_progress_page_renders(): void
    {
        $u = $this->withGoal();
        $this->actingAs($u)->get('/progress')->assertOk()->assertSee('Progress photos');
    }
}
