<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\BodyMetric;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileProfileEditTest extends TestCase
{
    use RefreshDatabase;

    private function auth(): array
    {
        $user = User::factory()->create();           // onboarded by default
        $p = $user->ensureProfile();
        $p->update([
            'display_name' => 'Sam', 'sex' => 'M', 'primary_goal' => 'Build muscle', 'coach_tone' => 'balanced',
            'birthdate' => '1992-02-02', 'height_cm' => 178,
            'settings' => ['units' => 'metric', 'activity_level' => 'moderate', 'meal_plan' => ['meals' => 4, 'start' => '08:00', 'end' => '21:00']],
        ]);
        BodyMetric::create(['profile_id' => $p->id, 'taken_at' => now(), 'weight_kg' => 80]);
        [, $token] = ApiToken::mint($user, 'ios', ['*']);
        return [$user, $this->withHeader('Authorization', "Bearer {$token}")];
    }

    public function test_show_returns_a_prefill_snapshot(): void
    {
        [, $h] = $this->auth();
        $h->getJson('/api/me/profile')->assertOk()
            ->assertJsonPath('profile.display_name', 'Sam')
            ->assertJsonPath('profile.primary_goal', 'build_muscle')   // mapped back to the key
            ->assertJsonStructure(['profile' => ['display_name', 'sex', 'units', 'activity_level', 'coach_tone'], 'goals']);
    }

    public function test_partial_update_changes_only_what_is_sent(): void
    {
        [$user, $h] = $this->auth();

        $h->patchJson('/api/me/profile', ['primary_goal' => 'lose_fat', 'coach_tone' => 'tough_love', 'diet' => 'vegan'])
            ->assertOk()->assertJsonPath('profile.primary_goal', 'lose_fat');

        $p = $user->refresh()->profile;
        $this->assertSame('Lose fat / get lean', $p->primary_goal);
        $this->assertSame('tough_love', $p->coach_tone);
        $this->assertSame('vegan', $p->settings['intake']['diet']);
        $this->assertSame('Sam', $p->display_name);                    // untouched
        $this->assertSame(1, $p->bodyMetrics()->count());              // no duplicate starting weight
    }

    public function test_custom_targets_are_not_clobbered_by_a_goal_change(): void
    {
        [$user, $h] = $this->auth();
        // user set custom macro targets earlier
        $p = $user->profile;
        $s = $p->settings; $s['macro_targets'] = ['calories' => 3000, 'protein_g' => 220, 'source' => 'custom'];
        $p->update(['settings' => $s]);

        $h->patchJson('/api/me/profile', ['primary_goal' => 'lose_fat'])->assertOk();

        $this->assertSame(3000, $user->refresh()->profile->settings['macro_targets']['calories']);   // preserved
    }

    public function test_requires_auth(): void
    {
        $this->getJson('/api/me/profile')->assertStatus(401);
        $this->patchJson('/api/me/profile', ['coach_tone' => 'gentle'])->assertStatus(401);
    }
}
