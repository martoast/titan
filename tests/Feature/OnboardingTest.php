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
        $this->actingAs($user)->get('/onboarding')->assertRedirect(route('coach.index'));
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

        $resp->assertRedirect(route('coach.index'));

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

    public function test_deep_intake_is_stored_and_seeds_coach_core_memory(): void
    {
        $user = User::factory()->notOnboarded()->create();

        $this->actingAs($user)->post('/onboarding', [
            'display_name' => 'Nadia',
            'birthdate' => '1996-09-12',
            'sex' => 'F',
            'units' => 'metric',
            'height' => 165,
            'weight' => 60,
            'activity_level' => 'light',
            'primary_goal' => 'recomp',
            'coach_tone' => 'balanced',
            'coaching_intensity' => 'balanced',
            'meals_per_day' => 3,
            // Deep intake — chip arrays arrive '|'-joined from the wizard.
            'injuries' => 'Lower back|Knee',
            'health_notes' => 'Recovering from a sprained ankle',
            'experience' => 'intermediate',
            'train_at' => 'home_weights',
            'train_days' => 4,
            'diet' => 'vegetarian',
            'allergies' => 'Peanuts',
            'avoid_foods' => 'Mushrooms',
            'motivation' => 'Feel strong at my sister\'s wedding',
            'event_date' => now()->addMonths(3)->toDateString(),
            'focus_areas' => 'Rounder glutes|Toned arms|Flat tummy',
        ])->assertRedirect(route('coach.index'));

        $p = $user->refresh()->profile;

        // Structured intake landed in settings (feeds mesocycle generator + meal logic).
        $intake = $p->settings['intake'];
        $this->assertSame('intermediate', $intake['experience']);
        $this->assertSame('home_weights', $intake['train_at']);
        $this->assertSame(4, $intake['train_days']);
        $this->assertSame('vegetarian', $intake['diet']);
        $this->assertSame(['Lower back', 'Knee'], $intake['injuries']);
        $this->assertSame(['Rounder glutes', 'Toned arms', 'Flat tummy'], $intake['focus_areas']);
        $this->assertSame('Peanuts', $intake['allergies']);

        // Coach core memory was seeded as pinned wiki pages it sees from message one.
        $pinned = $p->knowledgePages()->pinned()->pluck('title');
        $this->assertTrue($pinned->contains('Nadia — goals & focus'));
        $this->assertTrue($pinned->contains('Nadia — training profile'));
        $this->assertTrue($pinned->contains('Nadia — nutrition profile'));
        $this->assertTrue($pinned->contains('Nadia — health & limitations'));

        // The goals page actually carries the focus areas in its body.
        $goals = $p->knowledgePages()->where('title', 'Nadia — goals & focus')->first();
        $this->assertStringContainsString('Rounder glutes', $goals->content);
        $this->assertStringContainsString('wedding', $goals->content);
    }

    public function test_having_a_band_routes_to_device_pairing_after_setup(): void
    {
        $user = User::factory()->notOnboarded()->create();

        $base = [
            'display_name' => 'Theo', 'birthdate' => '1992-02-02', 'sex' => 'M', 'units' => 'metric',
            'height' => 178, 'weight' => 78, 'activity_level' => 'moderate', 'primary_goal' => 'build_muscle',
            'coach_tone' => 'balanced', 'meals_per_day' => 3,
        ];

        // Band in hand → straight to the devices page to connect it.
        $this->actingAs($user)->post('/onboarding', $base + ['has_wearable' => 1])
            ->assertRedirect(route('devices.index'));

        // No band → the usual coach landing.
        $user2 = User::factory()->notOnboarded()->create();
        $this->actingAs($user2)->post('/onboarding', $base + ['display_name' => 'Mia', 'has_wearable' => 0])
            ->assertRedirect(route('coach.index'));
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
        ])->assertRedirect(route('coach.index'));

        $p = $user->refresh()->profile;
        $this->assertEqualsWithDelta(180.3, (float) $p->height_cm, 0.2);
        $this->assertEqualsWithDelta(83.9, (float) $p->bodyMetrics()->first()->weight_kg, 0.2);
        $this->assertSame('imperial', $p->settings['units']);
    }
}
