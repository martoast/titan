<?php

namespace Tests\Feature;

use App\Models\BodyMetric;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * F-PROG-01 — the longitudinal Progress & trends page at /progress.
 */
class ProgressPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/progress')->assertRedirect('/login');
    }

    public function test_renders_on_a_cold_start_with_no_data(): void
    {
        $user = User::factory()->create();
        $user->ensureProfile();

        $resp = $this->actingAs($user)->get('/progress');

        $resp->assertOk();
        $resp->assertViewIs('progress.index');
        $resp->assertViewHas('weekScores', []);
        $resp->assertViewHas('weightSeries', []);
    }

    public function test_bodyweight_series_respects_metric_units(): void
    {
        $user = User::factory()->create();
        $profile = $user->ensureProfile();
        $profile->update(['settings' => ['units' => 'metric']]);

        BodyMetric::create([
            'profile_id' => $profile->id,
            'weight_kg' => 80.0,
            'taken_at' => Carbon::now()->subDays(3),
        ]);

        $resp = $this->actingAs($user)->get('/progress');

        $resp->assertOk();
        $resp->assertViewHas('weightUnit', 'kg');
        $resp->assertViewHas('weightSeries', fn ($series) => count($series) === 1 && abs($series[0]['value'] - 80.0) < 0.01);
    }

    public function test_bodyweight_series_converts_to_pounds_for_imperial(): void
    {
        $user = User::factory()->create();
        $profile = $user->ensureProfile();
        $profile->update(['settings' => ['units' => 'imperial']]);

        BodyMetric::create([
            'profile_id' => $profile->id,
            'weight_kg' => 80.0,
            'taken_at' => Carbon::now()->subDays(3),
        ]);

        $resp = $this->actingAs($user)->get('/progress');

        $resp->assertOk();
        $resp->assertViewHas('weightUnit', 'lb');
        // 80 kg → ~176.4 lb
        $resp->assertViewHas('weightSeries', fn ($series) => count($series) === 1 && abs($series[0]['value'] - 176.4) < 0.2);
    }

    public function test_old_bodyweight_outside_90_days_is_excluded(): void
    {
        $user = User::factory()->create();
        $profile = $user->ensureProfile();

        BodyMetric::create([
            'profile_id' => $profile->id,
            'weight_kg' => 90.0,
            'taken_at' => Carbon::now()->subDays(120), // outside the 90-day window
        ]);

        $resp = $this->actingAs($user)->get('/progress');

        $resp->assertOk();
        $resp->assertViewHas('weightSeries', []);
    }
}
