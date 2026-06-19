<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\MacroTargets;
use App\Support\MealCoach;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MacroTargetsTest extends TestCase
{
    use RefreshDatabase;

    private function profileWith(string $goal, float $kg)
    {
        $p = User::factory()->create()->ensureProfile();
        $p->update(['primary_goal' => $goal]);
        $p->bodyMetrics()->create(['taken_at' => now()->toDateString(), 'weight_kg' => $kg]);

        return $p->refresh();
    }

    public function test_muscle_building_targets_one_gram_per_pound(): void
    {
        // 80 kg ≈ 176 lb → ~176 g protein (1 g/lb), not the old timid ~168 g.
        $p = $this->profileWith('Build muscle', 80);
        $this->assertSame(176, MacroTargets::proteinTarget($p));
        $this->assertSame(176, MealCoach::targets($p)['protein_g']);
        $this->assertGreaterThanOrEqual(80 * 2.2, MacroTargets::proteinTarget($p));
    }

    public function test_fat_loss_targets_higher_protein_to_spare_muscle(): void
    {
        // In a deficit, protein goes higher (2.4 g/kg) to preserve lean mass.
        $p = $this->profileWith('Lose fat / get lean', 80);
        $this->assertSame(192, MacroTargets::proteinTarget($p));
    }

    public function test_recomposition_also_hits_one_gram_per_pound(): void
    {
        $p = $this->profileWith('Recomposition (lean + strong)', 70);
        $this->assertSame((int) round(70 * 2.2046226218), MacroTargets::proteinTarget($p));
    }

    public function test_general_health_is_solid_but_lower_than_muscle(): void
    {
        $p = $this->profileWith('General health & energy', 80);
        $this->assertSame((int) round(80 * 1.8), MacroTargets::proteinTarget($p));  // 144 g
        $this->assertLessThan(MacroTargets::proteinTarget($this->profileWith('Build muscle', 80)), 145);
    }

    public function test_protein_tracks_current_weight_not_a_frozen_value(): void
    {
        $p = $this->profileWith('Build muscle', 70);
        $this->assertSame(154, MacroTargets::proteinTarget($p));   // 70 kg

        // Log a heavier weight → the target follows it, no re-onboarding.
        $p->bodyMetrics()->create(['taken_at' => now()->addDay()->toDateString(), 'weight_kg' => 90]);
        $this->assertSame(198, MacroTargets::proteinTarget($p->refresh()));   // 90 kg
    }

    public function test_falls_back_to_stored_target_without_a_bodyweight(): void
    {
        $p = User::factory()->create()->ensureProfile();
        $p->update(['primary_goal' => 'Build muscle', 'settings' => ['macro_targets' => ['calories' => 2600, 'protein_g' => 150]]]);
        $this->assertNull(MacroTargets::proteinTarget($p));               // no bodyweight logged
        $this->assertSame(150, MealCoach::targets($p->refresh())['protein_g']);   // keeps the stored fallback
    }
}
