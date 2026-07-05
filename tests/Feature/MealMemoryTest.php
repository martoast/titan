<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\MealTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Meal memory ("Your meals"): every logged meal is auto-remembered + deduped so a dish can be
 * re-logged in one tap (no camera, no AI), and the list ranks by how often + how recently it's eaten.
 */
class MealMemoryTest extends TestCase
{
    use RefreshDatabase;

    private function auth(): array
    {
        $user = User::factory()->create();
        $profile = $user->ensureProfile();
        [, $token] = ApiToken::mint($user, 'ios', ['*']);

        return [$profile, $token];
    }

    public function test_logging_a_meal_remembers_it_and_dedupes_by_name(): void
    {
        [$profile] = $this->auth();

        $profile->meals()->create(['name' => 'Chicken & Rice', 'eaten_at' => now()->subDay(),
            'calories' => 600, 'protein_g' => 50, 'carbs_g' => 60, 'fat_g' => 15, 'source' => 'photo']);
        // Same dish, different capitalization/punctuation → SAME memory, macros refreshed to the latest.
        $profile->meals()->create(['name' => 'chicken and rice', 'eaten_at' => now(),
            'calories' => 640, 'protein_g' => 52, 'carbs_g' => 62, 'fat_g' => 16, 'source' => 'photo']);

        $this->assertSame(1, $profile->mealTemplates()->count());
        $tpl = $profile->mealTemplates()->first();
        $this->assertSame(2, $tpl->times_logged);
        $this->assertSame(640, (int) $tpl->calories);           // latest instance wins
    }

    public function test_trivial_names_are_not_remembered(): void
    {
        [$profile] = $this->auth();
        $profile->meals()->create(['name' => 'Meal', 'eaten_at' => now(),
            'calories' => 100, 'protein_g' => 1, 'carbs_g' => 1, 'fat_g' => 1, 'source' => 'manual']);

        $this->assertSame(0, $profile->mealTemplates()->count());
    }

    public function test_library_endpoint_ranks_favorites_and_frequency(): void
    {
        [$profile, $token] = $this->auth();
        // Eaten 3× recently.
        foreach (range(1, 3) as $_) {
            $profile->meals()->create(['name' => 'Oatmeal', 'eaten_at' => now(),
                'calories' => 300, 'protein_g' => 10, 'carbs_g' => 50, 'fat_g' => 5, 'source' => 'photo']);
        }
        // Eaten once.
        $profile->meals()->create(['name' => 'Pizza', 'eaten_at' => now(),
            'calories' => 800, 'protein_g' => 30, 'carbs_g' => 90, 'fat_g' => 35, 'source' => 'photo']);

        $out = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/me/meals-library')
            ->assertOk()->assertJsonStructure(['meals' => [['id', 'name', 'calories', 'photo_url', 'times_logged', 'favorite']]]);
        $this->assertSame('Oatmeal', $out->json('meals.0.name'));   // most-eaten first
    }

    public function test_relog_creates_a_new_meal_from_memory_with_portion(): void
    {
        [$profile, $token] = $this->auth();
        $profile->meals()->create(['name' => 'Protein Shake', 'eaten_at' => now()->subDays(2),
            'calories' => 200, 'protein_g' => 40, 'carbs_g' => 5, 'fat_g' => 2, 'source' => 'photo']);
        $tpl = $profile->mealTemplates()->first();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/me/meals/relog', ['template_id' => $tpl->id, 'portion' => 1.5])
            ->assertOk()
            ->assertJsonPath('meal.name', 'Protein Shake')
            ->assertJsonPath('meal.calories', 300)          // 200 × 1.5
            ->assertJsonPath('meal.source', 'memory')
            ->assertJsonPath('macros.calories.value', 300); // it counts toward today

        // Re-logging bumped the memory's frequency + recency.
        $this->assertSame(2, $tpl->fresh()->times_logged);
    }

    public function test_favorite_and_forget(): void
    {
        [$profile, $token] = $this->auth();
        $profile->meals()->create(['name' => 'Salad', 'eaten_at' => now(),
            'calories' => 250, 'protein_g' => 8, 'carbs_g' => 20, 'fat_g' => 15, 'source' => 'photo']);
        $tpl = $profile->mealTemplates()->first();
        $h = $this->withHeader('Authorization', "Bearer {$token}");

        $h->patchJson("/api/me/meals-library/{$tpl->id}/favorite", ['favorite' => true])
            ->assertOk()->assertJsonPath('template.favorite', true);

        $h->deleteJson("/api/me/meals-library/{$tpl->id}")->assertOk();
        $this->assertNull(MealTemplate::find($tpl->id));
    }
}
