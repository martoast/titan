<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\MetabolicHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MetabolicHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_null_until_enough_inputs(): void
    {
        $user = User::factory()->create();
        $profile = $user->ensureProfile();
        // Only one signal present (resting HR) → below the 3-input minimum.
        $profile->recoveryLogs()->create(['logged_at' => now()->toDateString(), 'resting_hr' => 58]);

        $this->assertNull(MetabolicHealth::assess($profile));
    }

    public function test_healthy_profile_scores_higher_than_unhealthy(): void
    {
        $healthy = User::factory()->create();
        $hp = $healthy->ensureProfile();
        $hp->recoveryLogs()->create(['logged_at' => now()->toDateString(), 'resting_hr' => 52, 'hrv_ms' => 65]);
        $hp->sleepLogs()->create(['slept_at' => now()->toDateString(), 'duration_min' => 460, 'bedtime' => '23:00', 'wake_time' => '07:00']);
        $hp->dailyActivity()->create(['date' => now()->toDateString(), 'steps' => 9500, 'source' => 'manual']);

        $unhealthy = User::factory()->create();
        $up = $unhealthy->ensureProfile();
        $up->recoveryLogs()->create(['logged_at' => now()->toDateString(), 'resting_hr' => 82, 'hrv_ms' => 24]);
        $up->sleepLogs()->create(['slept_at' => now()->toDateString(), 'duration_min' => 320, 'bedtime' => '01:00', 'wake_time' => '06:20']);
        $up->dailyActivity()->create(['date' => now()->toDateString(), 'steps' => 2500, 'source' => 'manual']);

        $h = MetabolicHealth::assess($hp);
        $u = MetabolicHealth::assess($up);

        $this->assertNotNull($h);
        $this->assertNotNull($u);
        $this->assertGreaterThan(70, $h['score']);
        $this->assertLessThan(45, $u['score']);
        $this->assertSame('strong', $h['band']);
        // The unhealthy profile's biggest lever is one of its weak inputs.
        $this->assertContains($u['weakest'], ['resting_hr', 'hrv', 'sleep', 'steps', 'fitness']);
    }

    public function test_recovery_page_renders_metabolic_card(): void
    {
        $user = User::factory()->create();
        $p = $user->ensureProfile();
        $p->recoveryLogs()->create(['logged_at' => now()->toDateString(), 'resting_hr' => 55, 'hrv_ms' => 60]);
        $p->sleepLogs()->create(['slept_at' => now()->toDateString(), 'duration_min' => 450, 'bedtime' => '23:00', 'wake_time' => '06:30']);
        $p->dailyActivity()->create(['date' => now()->toDateString(), 'steps' => 8800, 'source' => 'manual']);

        $this->actingAs($user)->get('/recovery')->assertOk()
            ->assertSee('Metabolic health')
            ->assertSee('Biggest lever');
    }
}
