<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MealSuggestionTest extends TestCase
{
    use RefreshDatabase;

    private function suggestion($profile, array $attrs = [])
    {
        return $profile->mealSuggestions()->create(array_merge([
            'name' => 'Teriyaki Salmon Bowl',
            'description' => 'Glazed salmon over rice with greens.',
            'calories' => 620, 'protein_g' => 48, 'carbs_g' => 55, 'fat_g' => 20,
            'ingredients' => ['150g salmon', '1 cup rice', 'broccoli'],
            'steps' => ['Cook rice', 'Pan-sear salmon', 'Steam broccoli', 'Plate and glaze'],
            'context' => 'next meal · ~48g protein',
        ], $attrs));
    }

    public function test_recipe_page_renders(): void
    {
        $user = User::factory()->create();
        $s = $this->suggestion($user->ensureProfile());

        $resp = $this->actingAs($user)->get(route('meals.recipe', $s));
        $resp->assertOk();
        $resp->assertSee('Teriyaki Salmon Bowl');
        $resp->assertSee('Ingredients');
        $resp->assertSee('Pan-sear salmon');
        $resp->assertSee('48g');                              // protein macro
    }

    public function test_logging_a_suggestion_creates_a_meal(): void
    {
        $user = User::factory()->create();
        $p = $user->ensureProfile();
        $s = $this->suggestion($p);

        $resp = $this->actingAs($user)->post(route('meals.suggestion.log', $s));
        $resp->assertRedirect(route('meals.index'));

        $meal = $p->meals()->latest('id')->first();
        $this->assertNotNull($meal);
        $this->assertSame('Teriyaki Salmon Bowl', $meal->name);
        $this->assertSame(48.0, (float) $meal->protein_g);
        $this->assertSame('suggestion', $meal->source);
    }

    public function test_recipe_is_owner_scoped(): void
    {
        $owner = User::factory()->create();
        $s = $this->suggestion($owner->ensureProfile());
        $other = User::factory()->create();
        $other->ensureProfile();

        $this->actingAs($other)->get(route('meals.recipe', $s))->assertForbidden();
    }

    public function test_suggest_degrades_gracefully_without_ai(): void
    {
        // No OPENAI_API_KEY in tests → the service throws AiException → controller flashes an error.
        config(['services.openai.key' => null]);
        $user = User::factory()->create();
        $user->ensureProfile();

        $resp = $this->actingAs($user)->post(route('meals.suggest'));
        $resp->assertSessionHasErrors('suggest');
    }

    public function test_meals_page_shows_the_suggestion_section(): void
    {
        $user = User::factory()->create();
        $this->suggestion($user->ensureProfile());

        $resp = $this->actingAs($user)->get('/meals');
        $resp->assertOk();
        $resp->assertSee('Suggest meals');
        $resp->assertSee('Teriyaki Salmon Bowl');             // the existing suggestion card
    }
}
