<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Coach\CoachTools;
use App\Support\MesocycleGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MesocycleGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_builds_a_periodized_block_with_a_deload(): void
    {
        $b = MesocycleGenerator::build(['focus' => ['chest'], 'days_per_week' => 4, 'weeks' => 5, 'experience' => 'intermediate']);

        $this->assertCount(5, $b['plan']);
        $this->assertSame(4, count($b['plan'][0]['days']));        // 4 training days
        $this->assertFalse($b['plan'][0]['deload']);
        $this->assertTrue($b['plan'][4]['deload']);                // last week is a deload
        $this->assertStringContainsString('Chest', $b['name']);
    }

    public function test_focus_muscle_gets_more_volume_and_is_trained_first(): void
    {
        $b = MesocycleGenerator::build(['focus' => ['chest'], 'days_per_week' => 4, 'weeks' => 5]);

        // Chest weekly sets exceed a non-focus muscle's in week 1.
        $chestWk1 = $b['volume']['chest'][0];
        $backWk1 = $b['volume']['back'][0];
        $this->assertGreaterThan($backWk1, $chestWk1);

        // Volume ramps then deloads.
        $this->assertGreaterThan($b['volume']['chest'][0], $b['volume']['chest'][3]);   // week 4 > week 1
        $this->assertLessThan($b['volume']['chest'][3], $b['volume']['chest'][4]);       // deload < peak

        // On every day chest is trained, it's the FIRST exercise's muscle.
        foreach ($b['plan'][0]['days'] as $day) {
            $muscles = array_column($day['exercises'], 'muscle');
            if (in_array('chest', $muscles, true)) {
                $this->assertSame('chest', $day['exercises'][0]['muscle'], "chest should lead {$day['name']}");
            }
        }
    }

    public function test_rir_tightens_across_the_block(): void
    {
        $b = MesocycleGenerator::build(['focus' => [], 'days_per_week' => 3, 'weeks' => 5]);
        $this->assertGreaterThanOrEqual($b['plan'][3]['rir'], $b['plan'][0]['rir']);   // week 1 RIR >= peak week RIR
        $this->assertSame(4, $b['plan'][4]['rir']);                                     // deload backs off
    }

    public function test_legs_focus_expands_to_the_three_leg_muscles(): void
    {
        $focus = MesocycleGenerator::normalizeFocus(['legs', 'side delts']);
        $this->assertContains('quads', $focus);
        $this->assertContains('hamstrings', $focus);
        $this->assertContains('shoulders', $focus);
    }

    public function test_focus_areas_from_onboarding_translate_to_muscles(): void
    {
        // The plain-language dream-physique chips map to training-focus muscles, most-implied first.
        $f = MesocycleGenerator::focusFromGoals(['Rounder glutes', 'Flat tummy', 'Hourglass waist', 'Strong core', 'Toned arms']);
        $this->assertSame('abs', $f[0]);                 // implied 3× (tummy + waist + core)
        $this->assertContains('glutes', $f);
        $this->assertContains('biceps', $f);             // "arms" → biceps + triceps

        $m = MesocycleGenerator::focusFromGoals(['V-taper back', 'Visible abs', 'Bigger shoulders']);
        $this->assertContains('back', $m);
        $this->assertContains('abs', $m);
        $this->assertContains('shoulders', $m);
    }

    public function test_mesocycle_inherits_experience_days_and_focus_from_onboarding_intake(): void
    {
        $p = User::factory()->create()->ensureProfile();
        $p->update(['settings' => array_merge($p->settings ?? [], [
            'intake' => [
                'experience' => 'beginner',
                'train_days' => 3,
                'focus_areas' => ['Rounder glutes', 'Lean legs', 'Flat tummy'],
            ],
        ])]);

        // No explicit args → everything comes from the intake.
        $gen = (new CoachTools($p->refresh()))->dispatch('generate_mesocycle', []);

        $this->assertTrue($gen['ok']);
        $program = $p->trainingPrograms()->where('is_active', true)->first();
        $this->assertSame('beginner', $program->experience);
        $this->assertSame(3, $program->days_per_week);
        $this->assertContains('glutes', $program->focus);   // derived from "Rounder glutes" + "Lean legs"
    }

    public function test_explicit_args_override_the_onboarding_intake(): void
    {
        $p = User::factory()->create()->ensureProfile();
        $p->update(['settings' => array_merge($p->settings ?? [], [
            'intake' => ['experience' => 'beginner', 'train_days' => 3, 'focus_areas' => ['Rounder glutes']],
        ])]);

        $gen = (new CoachTools($p->refresh()))->dispatch('generate_mesocycle', [
            'focus' => ['chest'], 'days_per_week' => 5, 'experience' => 'advanced',
        ]);

        $this->assertTrue($gen['ok']);
        $program = $p->trainingPrograms()->where('is_active', true)->first();
        $this->assertSame('advanced', $program->experience);
        $this->assertSame(5, $program->days_per_week);
        $this->assertSame(['chest'], $program->focus);      // intake glutes ignored
    }

    public function test_the_coach_generates_saves_and_reads_a_program(): void
    {
        $p = User::factory()->create()->ensureProfile();
        $t = new CoachTools($p);

        $gen = $t->dispatch('generate_mesocycle', ['focus' => ['chest', 'arms'], 'days_per_week' => 5, 'weeks' => 6]);
        $this->assertTrue($gen['ok']);
        $this->assertSame('program', $gen['card']['type']);
        $this->assertContains('Chest', $gen['card']['focus']);
        $this->assertDatabaseHas('training_programs', ['profile_id' => $p->id, 'is_active' => true, 'weeks' => 6]);

        // current_program returns the active one + readable week detail.
        $cur = $t->dispatch('current_program', []);
        $this->assertSame(1, $cur['week']);
        $this->assertNotEmpty($cur['week_detail']);

        // advance_program moves to week 2.
        $adv = $t->dispatch('advance_program', []);
        $this->assertSame(2, $adv['week']);
    }
}
