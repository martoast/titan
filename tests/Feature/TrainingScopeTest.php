<?php

namespace Tests\Feature;

use App\Models\ActivitySession;
use App\Models\Profile;
use App\Models\User;
use App\Support\WorkoutStreak;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The `training()` scope is the single definition of "a session that counts" for streaks/strain/trends.
 * It must screen the passively-imported ambient micro-walk WITHOUT dropping real, untyped workouts —
 * the earlier `activity_type != 'other'` axis dropped watch-confirmed and HealthKit-imported sessions
 * (both legitimately carry 'other'). Length is the only axis; a NULL duration is an unknown-length real
 * workout and is kept.
 */
class TrainingScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Prod runs UTC; pin it so local-day bucketing in WorkoutStreak matches the seeded instants.
        config(['app.timezone' => 'UTC']);
        date_default_timezone_set('UTC');
    }

    private function profile(): Profile
    {
        return User::factory()->create()->ensureProfile();
    }

    private function mkSession(Profile $p, array $attrs): ActivitySession
    {
        return ActivitySession::create(array_merge([
            'profile_id' => $p->id,
            'source' => 'titan_band',
            'started_at' => now(),
            'ended_at' => now()->addMinutes(30),
        ], $attrs));
    }

    public function test_training_scope_keeps_real_untyped_but_drops_micro_walk_and_null_placeholder(): void
    {
        $p = $this->profile();

        // A real 30-min imported workout with no mapped kind → 'other'. MUST count (the type axis is gone).
        $realOther = $this->mkSession($p, ['started_at' => now()->subHours(6), 'activity_type' => 'other', 'duration_min' => 30]);
        // The coach's startActivity leaves a NULL-duration, unfinished placeholder → MUST NOT light a streak.
        $placeholder = $this->mkSession($p, ['started_at' => now()->subHours(4), 'ended_at' => null, 'activity_type' => 'run', 'duration_min' => null]);
        // A passively-imported 2-minute walk → ambient noise. MUST be dropped.
        $microWalk = $this->mkSession($p, ['started_at' => now()->subHours(2), 'activity_type' => 'walk', 'duration_min' => 2]);

        $ids = ActivitySession::query()->training()->pluck('id')->all();

        $this->assertContains($realOther->id, $ids, "a real 30-min 'other' workout must count as training");
        $this->assertNotContains($placeholder->id, $ids, 'an unfinished NULL-duration placeholder must not count');
        $this->assertNotContains($microWalk->id, $ids, 'a 2-min ambient walk must not count as training');
    }

    public function test_streak_counts_an_other_typed_workout(): void
    {
        $p = $this->profile();
        $this->mkSession($p, ['activity_type' => 'other', 'duration_min' => 40, 'started_at' => now(), 'ended_at' => now()->addMinutes(40)]);

        $streak = WorkoutStreak::forProfile($p, 'UTC');

        $this->assertTrue($streak['worked_out_today'], "an 'other'-typed real workout should count toward the streak");
        $this->assertSame(1, $streak['current']);
    }
}
