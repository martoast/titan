<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileOnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_reports_onboarded_state(): void
    {
        $user = User::factory()->notOnboarded()->create(['email' => 'new@titan.test', 'password' => bcrypt('secret123')]);

        $this->postJson('/api/login', ['email' => 'new@titan.test', 'password' => 'secret123'])
            ->assertOk()->assertJsonPath('user.onboarded', false);
    }

    public function test_status_and_store_build_the_profile(): void
    {
        $user = User::factory()->notOnboarded()->create();
        [, $token] = ApiToken::mint($user, 'ios', ['*']);
        $h = $this->withHeader('Authorization', "Bearer {$token}");

        $h->getJson('/api/me/onboarding')->assertOk()
            ->assertJsonPath('onboarded', false)
            ->assertJsonStructure(['goals']);

        $h->postJson('/api/me/onboarding', [
            'display_name' => 'Ava', 'birthdate' => '1994-04-01', 'sex' => 'F', 'units' => 'metric',
            'height' => 168, 'weight' => 62, 'activity_level' => 'moderate', 'primary_goal' => 'build_muscle',
            'coach_tone' => 'tough_love', 'meals_per_day' => 4, 'timezone' => 'America/Mexico_City',
            'experience' => 'intermediate', 'diet' => 'omnivore',
            'cycle_enabled' => true, 'last_period' => now()->subDays(5)->toDateString(), 'cycle_length' => 29,
        ])->assertOk()->assertJsonPath('onboarded', true)->assertJsonPath('route', 'coach');

        $p = $user->refresh()->profile;
        $this->assertNotNull($p->onboarded_at);
        $this->assertSame('Ava', $p->display_name);
        $this->assertSame('Build muscle', $p->primary_goal);
        $this->assertGreaterThan(1200, $p->settings['macro_targets']['calories']);
        $this->assertSame(1, $p->bodyMetrics()->count());                 // starting weight seeded
        $this->assertGreaterThan(0, $p->knowledgePages()->count());        // coach memory seeded
    }

    public function test_routes_to_pairing_when_band_in_hand(): void
    {
        $user = User::factory()->notOnboarded()->create();
        [, $token] = ApiToken::mint($user, 'ios', ['*']);

        $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/me/onboarding', [
            'display_name' => 'Leo', 'birthdate' => '1990-01-01', 'sex' => 'M', 'units' => 'imperial',
            'height' => 70, 'weight' => 180, 'activity_level' => 'active', 'primary_goal' => 'lose_fat',
            'coach_tone' => 'balanced', 'meals_per_day' => 3, 'has_wearable' => true,
        ])->assertOk()->assertJsonPath('route', 'pairing');
    }
}
