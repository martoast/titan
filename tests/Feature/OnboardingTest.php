<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_user_is_gated_into_onboarding(): void
    {
        $user = User::factory()->notOnboarded()->create();

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('onboarding'));
        $this->actingAs($user)->get('/coach')->assertRedirect(route('onboarding'));
        $this->actingAs($user)->get('/onboarding')->assertOk();   // the wizard itself is reachable
    }

    public function test_an_onboarded_user_passes_the_gate(): void
    {
        $user = User::factory()->create();   // factory onboards by default
        $this->actingAs($user)->get('/dashboard')->assertOk();
        // Visiting onboarding again bounces them home.
        $this->actingAs($user)->get('/onboarding')->assertRedirect(route('dashboard'));
    }

    public function test_completing_the_wizard_builds_the_profile_and_targets(): void
    {
        $user = User::factory()->notOnboarded()->create();

        $resp = $this->actingAs($user)->post('/onboarding', [
            'display_name' => 'Ava',
            'birthdate' => '1994-04-01',
            'sex' => 'F',
            'units' => 'metric',
            'height' => 168,
            'weight' => 62,
            'activity_level' => 'moderate',
            'primary_goal' => 'build_muscle',
            'coach_tone' => 'tough_love',
            'meals_per_day' => 4,
            'eat_start' => '07:30',
            'eat_end' => '20:30',
            'timezone' => 'America/Mexico_City',
            'cycle_enabled' => 1,
            'last_period' => now()->subDays(5)->toDateString(),
            'cycle_length' => 29,
            'birth_control' => 'none',
            'cycle_intent' => 'tracking',
        ]);

        $resp->assertRedirect(route('dashboard'));

        $p = $user->refresh()->profile;
        $this->assertNotNull($p->onboarded_at);
        $this->assertSame('Ava', $p->display_name);
        $this->assertSame('F', $p->sex);
        $this->assertSame('Build muscle', $p->primary_goal);
        $this->assertSame('tough_love', $p->coach_tone);
        $this->assertEqualsWithDelta(168.0, (float) $p->height_cm, 0.1);

        // Personalized macro targets were seeded.
        $this->assertGreaterThan(1200, $p->settings['macro_targets']['calories']);
        $this->assertGreaterThan(100, $p->settings['macro_targets']['protein_g']);
        $this->assertSame(4, $p->settings['meal_plan']['meals']);

        // Cycle was configured + anchored, and a starting weight logged.
        $this->assertTrue($p->settings['cycle']['enabled']);
        $this->assertSame(29, $p->settings['cycle']['avg_length']);
        $this->assertDatabaseHas('menstrual_cycles', ['profile_id' => $p->id]);
        $this->assertDatabaseHas('body_metrics', ['profile_id' => $p->id]);

        // Gate now lets them through.
        $this->actingAs($user)->get('/dashboard')->assertOk();
    }

    public function test_imperial_units_convert_to_metric(): void
    {
        $user = User::factory()->notOnboarded()->create();

        $this->actingAs($user)->post('/onboarding', [
            'display_name' => 'Max',
            'birthdate' => '1990-01-01',
            'sex' => 'M',
            'units' => 'imperial',
            'height' => 71,      // inches → ~180.3 cm
            'weight' => 185,     // lb → ~83.9 kg
            'activity_level' => 'active',
            'primary_goal' => 'lose_fat',
            'coach_tone' => 'balanced',
            'meals_per_day' => 3,
        ])->assertRedirect(route('dashboard'));

        $p = $user->refresh()->profile;
        $this->assertEqualsWithDelta(180.3, (float) $p->height_cm, 0.2);
        $this->assertEqualsWithDelta(83.9, (float) $p->bodyMetrics()->first()->weight_kg, 0.2);
        $this->assertSame('imperial', $p->settings['units']);
    }
}
