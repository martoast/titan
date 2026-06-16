<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Pantry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PantryTest extends TestCase
{
    use RefreshDatabase;

    public function test_add_parses_dedupes_and_remove_works(): void
    {
        $p = User::factory()->create()->ensureProfile();

        Pantry::add($p, 'ground beef, eggs, milk, tuna');
        Pantry::add($p, 'Eggs, rice');                         // 'Eggs' dedupes against 'eggs'
        $items = Pantry::get($p->fresh());
        $this->assertEqualsCanonicalizing(['ground beef', 'eggs', 'milk', 'tuna', 'rice'], $items);

        Pantry::remove($p, 'MILK');                            // case-insensitive
        $this->assertNotContains('milk', Pantry::get($p->fresh()));
    }

    public function test_pantry_route_adds_and_removes(): void
    {
        $user = User::factory()->create();
        $user->ensureProfile();

        $this->actingAs($user)->post(route('meals.pantry'), ['items' => 'chicken breast, oats'])->assertRedirect();
        $this->assertEqualsCanonicalizing(['chicken breast', 'oats'], Pantry::get($user->profile->fresh()));

        $this->actingAs($user)->post(route('meals.pantry'), ['remove' => 'oats'])->assertRedirect();
        $this->assertSame(['chicken breast'], Pantry::get($user->profile->fresh()));
    }

    public function test_meals_page_shows_the_kitchen(): void
    {
        $user = User::factory()->create();
        $p = $user->ensureProfile();
        Pantry::add($p, 'salmon, broccoli');

        $resp = $this->actingAs($user)->get('/meals');
        $resp->assertOk();
        $resp->assertSee('Your kitchen');
        $resp->assertSee('salmon');
        $resp->assertSee('Cook from my kitchen');             // button reflects a stocked kitchen
    }

    public function test_mcp_update_and_read_pantry(): void
    {
        $user = User::factory()->create();
        $user->ensureProfile();
        [, $plain] = \App\Models\ApiToken::mint($user, 'test', ['*']);

        $this->withToken($plain)->postJson('/api/tool', ['tool' => 'update_pantry', 'args' => ['items' => 'ground beef, eggs, tuna']])
            ->assertOk()->assertJsonPath('result.count', 3);

        $this->withToken($plain)->postJson('/api/tool', ['tool' => 'get_pantry'])
            ->assertOk()->assertJsonPath('result.count', 3);
    }
}
