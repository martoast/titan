<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The "Daily" hub's data endpoints: /me/sleep (breakdown + need/debt) and /me/cycle (phase + the
 * Flo-style pregnancy chance), the latter gated to women.
 */
class MobileDailyEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private function auth(User $user): self
    {
        [, $token] = ApiToken::mint($user, 'ios', ['*']);

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    public function test_sleep_endpoint_returns_assess_and_nights(): void
    {
        $user = User::factory()->create();
        $profile = $user->ensureProfile();
        $profile->sleepLogs()->create(['slept_at' => '2026-06-24', 'duration_min' => 462, 'quality' => 88, 'deep_min' => 95, 'rem_min' => 110, 'updated_via' => 'test']);

        $this->auth($user)->getJson('/api/me/sleep')
            ->assertOk()
            ->assertJsonPath('nights.0.deep_min', 95)
            ->assertJsonStructure(['assess' => ['need_h', 'debt_h', 'band'], 'nights']);
    }

    public function test_cycle_endpoint_is_available_for_women_with_the_pregnancy_chance(): void
    {
        $user = User::factory()->create();
        $profile = $user->ensureProfile();
        $profile->update(['sex' => 'F']);
        $profile->menstrualCycles()->create(['start_date' => now()->subDays(13)->toDateString()]); // ~ovulation

        $this->auth($user)->getJson('/api/me/cycle')
            ->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonStructure(['cycle' => ['cycle_day', 'phase', 'conception' => ['likelihood'], 'fertile_window', 'ovulation']]);
    }

    public function test_cycle_endpoint_unavailable_for_men(): void
    {
        $user = User::factory()->create();
        $profile = $user->ensureProfile();
        $profile->update(['sex' => 'M']);

        $this->auth($user)->getJson('/api/me/cycle')
            ->assertOk()
            ->assertJsonPath('available', false);
    }
}
