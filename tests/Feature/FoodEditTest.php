<?php

namespace Tests\Feature;

use App\Models\FoodFact;
use App\Models\User;
use App\Services\Coach\CoachTools;
use App\Support\FoodLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Correcting a food's macros by telling the coach (update_food). The correction is stored as a
 * user-authoritative FoodFact, so future lookups/logs use it instead of the web.
 */
class FoodEditTest extends TestCase
{
    use RefreshDatabase;

    private function tools(): CoachTools
    {
        $profile = User::factory()->create()->ensureProfile();

        return app(CoachTools::class, ['profile' => $profile]);
    }

    public function test_update_food_stores_user_macros_and_lookup_uses_them(): void
    {
        $tools = $this->tools();

        $res = $tools->dispatch('update_food', [
            'name' => 'Costco ground beef', 'basis' => '100g',
            'calories' => 250, 'protein_g' => 22, 'carbs_g' => 0, 'fat_g' => 18,
        ]);
        $this->assertTrue($res['ok']);
        $this->assertSame('user', FoodFact::first()->source);

        $lib = app(FoodLibrary::class)->lookup('Costco ground beef');
        $this->assertTrue($lib['ok']);
        $this->assertSame(250, (int) $lib['calories']);
        $this->assertEqualsWithDelta(22, (float) $lib['protein_g'], 0.1);
    }

    public function test_partial_correction_keeps_the_other_macros(): void
    {
        $tools = $this->tools();
        $tools->dispatch('update_food', [
            'name' => 'Costco ground beef', 'calories' => 250, 'protein_g' => 22, 'carbs_g' => 0, 'fat_g' => 18,
        ]);

        $tools->dispatch('update_food', ['name' => 'Costco ground beef', 'protein_g' => 25]);

        $lib = app(FoodLibrary::class)->lookup('Costco ground beef');
        $this->assertSame(250, (int) $lib['calories']);          // unchanged
        $this->assertEqualsWithDelta(25, (float) $lib['protein_g'], 0.1);   // corrected
        $this->assertSame(1, FoodFact::count());                  // updated in place, not duplicated
    }

    public function test_update_food_requires_a_name(): void
    {
        $this->assertArrayHasKey('error', $this->tools()->dispatch('update_food', ['calories' => 200]));
    }
}
