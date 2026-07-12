<?php

namespace Tests\Feature;

use App\Models\ActivitySession;
use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Manual workout logging (POST /api/me/workouts): the band didn't record it, but a gym session or a
 * watch-less run should still count. Verifies it creates a source='manual' ActivitySession that is
 * training (streak-eligible), carries an sRPE-style TRIMP from duration × intensity (so it registers
 * on strain), and flows through the existing /me/runs reader unchanged.
 */
class ManualWorkoutLogTest extends TestCase
{
    use RefreshDatabase;

    private function auth(): array
    {
        $user = User::factory()->create();
        $user->ensureProfile();
        [, $token] = ApiToken::mint($user, 'ios', ['*']);

        return [$user, $this->withHeader('Authorization', "Bearer {$token}")];
    }

    public function test_logs_a_manual_workout(): void
    {
        [$user, $h] = $this->auth();

        $h->postJson('/api/me/workouts', [
            'activity_type' => 'strength',
            'duration_min' => 45,
            'perceived_intensity' => 'hard',
        ])->assertCreated()
            ->assertJsonPath('activity_type', 'strength')
            ->assertJsonPath('duration_min', 45)
            ->assertJsonPath('perceived_intensity', 'hard');

        $s = ActivitySession::where('profile_id', $user->profile->id)->firstOrFail();
        $this->assertSame('manual', $s->source);
        $this->assertSame('strength', $s->activity_type);
        $this->assertSame(45, (int) $s->duration_min);
        $this->assertTrue((bool) $s->is_training);          // counts for the streak
        $this->assertSame(108.0, (float) $s->trimp);          // 45 × 2.4 (hard) — registers on strain
        $this->assertNull($s->avg_hr);                        // no sensor data
    }

    public function test_intensity_defaults_to_moderate(): void
    {
        [$user, $h] = $this->auth();

        $h->postJson('/api/me/workouts', ['activity_type' => 'run', 'duration_min' => 30])
            ->assertCreated();

        $s = ActivitySession::where('profile_id', $user->profile->id)->firstOrFail();
        $this->assertSame('moderate', $s->perceived_intensity);
        $this->assertSame(36.0, (float) $s->trimp);           // 30 × 1.2 (moderate)
    }

    public function test_shows_in_runs_list_and_counts_the_streak(): void
    {
        [, $h] = $this->auth();

        $h->postJson('/api/me/workouts', ['activity_type' => 'walk', 'duration_min' => 25]);

        $body = $h->getJson('/api/me/runs')->assertOk()->json();
        $this->assertCount(1, $body['runs']);
        $this->assertSame('walk', $body['runs'][0]['activity_type']);
        $this->assertGreaterThanOrEqual(1, $body['streak']['current']);
    }

    public function test_future_started_at_is_clamped_to_now(): void
    {
        [$user, $h] = $this->auth();

        $h->postJson('/api/me/workouts', [
            'activity_type' => 'other',
            'duration_min' => 20,
            'started_at' => now()->addDays(3)->toIso8601String(),
        ])->assertCreated();

        // Read the RAW stored value as UTC — the activity_sessions convention (the Eloquent cast mislabels
        // the UTC-wall-clock value as app-tz; see WorkoutStreak / MobileRunsController).
        $s = ActivitySession::where('profile_id', $user->profile->id)->firstOrFail();
        $startedInstant = \Illuminate\Support\Carbon::parse($s->getRawOriginal('started_at'), 'UTC');
        $this->assertTrue($startedInstant->lessThanOrEqualTo(now()->addMinute()));
    }

    public function test_validation_rejects_bad_input(): void
    {
        [, $h] = $this->auth();

        $h->postJson('/api/me/workouts', ['duration_min' => 30])->assertStatus(422);              // no type
        $h->postJson('/api/me/workouts', ['activity_type' => 'yoga', 'duration_min' => 30])->assertStatus(422); // bad type
        $h->postJson('/api/me/workouts', ['activity_type' => 'run', 'duration_min' => 0])->assertStatus(422);   // zero duration
        $h->postJson('/api/me/workouts', ['activity_type' => 'run', 'duration_min' => 30, 'perceived_intensity' => 'brutal'])->assertStatus(422);
    }

    public function test_requires_auth(): void
    {
        $this->postJson('/api/me/workouts', ['activity_type' => 'run', 'duration_min' => 30])->assertStatus(401);
    }
}
