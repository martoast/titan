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

    public function test_editing_profile_to_female_with_last_period_unlocks_the_cycle(): void
    {
        $user = User::factory()->create();
        $user->ensureProfile()->update(['birthdate' => '1995-01-01', 'height_cm' => 165]);

        // The exact edit a tester does in You → Edit profile.
        $this->auth($user)->patchJson('/api/me/profile', [
            'sex' => 'F', 'cycle_enabled' => true, 'last_period' => now()->subDays(13)->toDateString(),
        ])->assertOk();

        $this->auth($user)->getJson('/api/me/cycle')
            ->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('cycle.cycle_day', 14)              // 13 days in → day 14 (near ovulation)
            ->assertJsonPath('cycle.conception.likelihood', fn ($l) => is_string($l) && $l !== '');
    }

    public function test_logging_a_period_from_the_cycle_page_unlocks_it(): void
    {
        $user = User::factory()->create();
        $user->ensureProfile()->update(['sex' => 'F']);

        // Log period right from the Cycle page — no profile-settings detour.
        $this->auth($user)->postJson('/api/me/cycle/period', ['date' => now()->subDays(13)->toDateString()])
            ->assertOk()->assertJsonPath('ok', true)->assertJsonPath('cycle.cycle_day', 14);

        // And a day's flow + symptoms.
        $this->auth($user)->postJson('/api/me/cycle/day', ['date' => now()->toDateString(), 'flow' => 'medium', 'symptoms' => ['cramps', 'fatigue']])
            ->assertOk()->assertJsonPath('ok', true);

        $this->auth($user)->getJson('/api/me/cycle')
            ->assertOk()->assertJsonPath('available', true)
            ->assertJsonStructure(['symptoms', 'flows', 'cycle' => ['conception' => ['likelihood']]]);
    }

    public function test_cycle_calendar_projects_period_and_fertile_days(): void
    {
        $user = User::factory()->create();
        $profile = $user->ensureProfile();
        $profile->update(['sex' => 'F']);
        $profile->menstrualCycles()->create(['start_date' => now()->startOfMonth()->toDateString()]);

        $res = $this->auth($user)->getJson('/api/me/cycle/calendar?from=' . now()->startOfMonth()->toDateString() . '&days=28')
            ->assertOk()->assertJsonPath('available', true);

        $days = $res->json('days');
        $this->assertCount(28, $days);
        $this->assertTrue($days[0]['period']);                                  // day 1 = period
        $this->assertTrue(collect($days)->contains(fn ($d) => $d['fertile']));  // a fertile window exists
        $this->assertTrue(collect($days)->contains(fn ($d) => $d['ovulation'])); // and an ovulation day
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
