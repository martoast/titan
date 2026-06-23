<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\RecoveryLog;
use App\Models\SleepLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The native app's Dashboard/Recovery/Sleep screens read one aggregate endpoint.
 */
class MobileDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_returns_readiness_recovery_and_sleep(): void
    {
        $user = User::factory()->create();
        $profile = $user->ensureProfile();
        [, $token] = ApiToken::mint($user, 'ios', ['*']);

        RecoveryLog::create([
            'profile_id' => $profile->id, 'logged_at' => today(),
            'hrv_ms' => 68, 'resting_hr' => 54, 'updated_via' => 'biosignal:test',
        ]);
        SleepLog::create([
            'profile_id' => $profile->id, 'slept_at' => today(),
            'duration_min' => 445, 'quality' => 82, 'deep_min' => 90, 'rem_min' => 110,
        ]);

        $res = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/me/dashboard');

        $res->assertOk()
            ->assertJsonStructure(['readiness', 'recovery', 'sleep', 'activity'])
            ->assertJsonPath('recovery.hrv_ms', 68)
            ->assertJsonPath('recovery.resting_hr', 54)
            ->assertJsonPath('sleep.duration_min', 445);
    }

    public function test_dashboard_is_graceful_with_no_data(): void
    {
        $user = User::factory()->create();
        $user->ensureProfile();
        [, $token] = ApiToken::mint($user, 'ios', ['*']);

        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/me/dashboard')
            ->assertOk()
            ->assertJsonPath('recovery', null)
            ->assertJsonPath('sleep', null);
    }

    public function test_dashboard_requires_auth(): void
    {
        $this->getJson('/api/me/dashboard')->assertStatus(401);
    }
}
