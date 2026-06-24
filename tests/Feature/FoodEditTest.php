<?php

namespace Tests\Feature;

use App\Models\FoodFact;
use App\Models\Profile;
use App\Models\User;
use App\Services\Coach\CoachTools;
use App\Support\FoodLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Correcting a food's macros by telling the coach (update_food). Corrections are PER-PROFILE — one
 * user's "Costco ground beef" never overrides another's — and win over the shared web cache.
 */
class FoodEditTest extends TestCase
{
    use RefreshDatabase;

    private function profile(): Profile
    {
        return User::factory()->create()->ensureProfile();
    }

    private function tools(Profile $profile): CoachTools
    {
        return app(CoachTools::class, ['profile' => $profile]);
    }

    public function test_update_food_stores_user_macros_and_lookup_uses_them(): void
    {
        $p = $this->profile();
        $res = $this->tools($p)->dispatch('update_food', [
            'name' => 'Costco ground beef', 'basis' => '100g',
            'calories' => 250, 'protein_g' => 22, 'carbs_g' => 0, 'fat_g' => 18,
        ]);
        $this->assertTrue($res['ok']);
        $this->assertSame('user', FoodFact::first()->source);
        $this->assertSame($p->id, FoodFact::first()->profile_id);

        $lib = app(FoodLibrary::class)->lookup('Costco ground beef', $p->fresh());
        $this->assertTrue($lib['ok']);
        $this->assertSame(250, (int) $lib['calories']);
        $this->assertEqualsWithDelta(22, (float) $lib['protein_g'], 0.1);
    }

    public function test_correction_is_scoped_to_the_profile(): void
    {
        $a = $this->profile();
        $b = $this->profile();
        $this->tools($a)->dispatch('update_food', [
            'name' => 'Costco ground beef', 'calories' => 250, 'protein_g' => 22, 'carbs_g' => 0, 'fat_g' => 18,
        ]);

        // Model-level (deterministic, no web): A has their own correction, B sees nothing.
        $key = FoodLibrary::normalize('Costco ground beef');
        $this->assertSame(250, FoodFact::findForProfile($key, $a->id)?->calories);
        $this->assertNull(FoodFact::findForProfile($key, $b->id));
    }

    public function test_correction_wins_over_the_shared_cache(): void
    {
        $p = $this->profile();
        // Pretend the web cached a shared fact earlier.
        FoodFact::create(['profile_id' => null, 'name' => 'oatmeal', 'basis' => '100g',
            'calories' => 389, 'protein_g' => 17, 'carbs_g' => 66, 'fat_g' => 7, 'source' => 'web', 'hits' => 3]);

        $this->tools($p)->dispatch('update_food', ['name' => 'oatmeal', 'calories' => 350, 'protein_g' => 13]);

        $lib = app(FoodLibrary::class);
        $this->assertSame(350, (int) $lib->lookup('oatmeal', $p->fresh())['calories']);      // their override
        $this->assertSame(66, (int) round($lib->lookup('oatmeal', $p->fresh())['carbs_g'])); // seeded from shared
        $this->assertSame(389, (int) $lib->lookup('oatmeal')['calories']);                   // shared cache untouched
    }

    public function test_partial_correction_keeps_the_other_macros(): void
    {
        $p = $this->profile();
        $t = $this->tools($p);
        $t->dispatch('update_food', ['name' => 'Costco ground beef', 'calories' => 250, 'protein_g' => 22, 'carbs_g' => 0, 'fat_g' => 18]);
        $t->dispatch('update_food', ['name' => 'Costco ground beef', 'protein_g' => 25]);

        $lib = app(FoodLibrary::class)->lookup('Costco ground beef', $p->fresh());
        $this->assertSame(250, (int) $lib['calories']);
        $this->assertEqualsWithDelta(25, (float) $lib['protein_g'], 0.1);
        $this->assertSame(1, FoodFact::where('profile_id', $p->id)->count());   // updated in place
    }

    public function test_update_food_requires_a_name(): void
    {
        $this->assertArrayHasKey('error', $this->tools($this->profile())->dispatch('update_food', ['calories' => 200]));
    }
}
