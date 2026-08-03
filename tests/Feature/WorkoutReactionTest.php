<?php

namespace Tests\Feature;

use App\Jobs\ReactToWorkoutSealed;
use App\Models\ActivitySession;
use App\Models\User;
use App\Support\WorkoutCoach;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * When a workout seals, the coach drops a celebration message into the chat (congrats + recovery +
 * a follow-up) and the session is an authoritative row the coach can see. See ReactToWorkoutSealed.
 */
class WorkoutReactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_seal_drops_a_coach_message_and_is_idempotent(): void
    {
        $user = User::factory()->create();
        $profile = $user->ensureProfile();
        $session = ActivitySession::create([
            'profile_id' => $profile->id, 'source' => 'titan_band',
            'started_at' => now()->subMinutes(50), 'ended_at' => now(),
            'duration_min' => 48, 'activity_type' => 'strength',
            'avg_hr' => 132, 'max_hr' => 168, 'trimp' => 110, 'calories_kcal' => 410,
            'hr_zones' => ['z1' => 5, 'z2' => 10, 'z3' => 8, 'z4' => 4, 'z5' => 2],  // 6 min hard (Z4+Z5)
            'hr_source' => 'ppg_inmotion', 'updated_via' => 'biosignal:sealed',
        ]);

        ReactToWorkoutSealed::dispatchSync($session->id);

        $convo = $profile->conversations()->days()->first();
        $this->assertNotNull($convo, 'a briefings conversation should hold the coach note');
        $msg = $convo->messages()->where('role', 'assistant')->get();
        $this->assertCount(1, $msg);
        $body = $msg->first()->content;
        $this->assertStringContainsString('Strength logged', $body);   // congrats + type
        $this->assertStringContainsString('48 min', $body);            // the summary
        $this->assertStringContainsString('peak 168', $body);          // PEAK leads, not just average
        $this->assertStringContainsString('in the red', $body);        // minutes in the hard zones
        $this->assertStringContainsString('your average alone would hide', $body); // the lifting insight
        $this->assertStringContainsString('progress it', $body);       // the strength-specific follow-up

        // Re-running must NOT post a second message.
        ReactToWorkoutSealed::dispatchSync($session->id);
        $this->assertSame(1, $convo->messages()->where('role', 'assistant')->count());
    }

    public function test_celebrate_scales_and_adapts_by_type(): void
    {
        $user = User::factory()->create();
        $profile = $user->ensureProfile();

        $easyRun = ActivitySession::make([
            'activity_type' => 'run', 'duration_min' => 22, 'distance_km' => 4.0, 'avg_hr' => 128, 'trimp' => 40,
        ]);
        $easyRun->setRelation('profile', $profile);
        $run = WorkoutCoach::celebrate($easyRun);
        $this->assertStringContainsString('🏃', $run['title']);
        $this->assertStringContainsString('zones', $run['body']);        // cardio follow-up
        $this->assertStringContainsString('bounce back fast', $run['body']); // light-load recovery

        $hardLift = ActivitySession::make([
            'activity_type' => 'strength', 'duration_min' => 75, 'avg_hr' => 140, 'max_hr' => 172, 'trimp' => 130,
        ]);
        $hardLift->setRelation('profile', $profile);
        $lift = WorkoutCoach::celebrate($hardLift);
        $this->assertStringContainsString('protect your sleep', $lift['body']); // intense-load recovery
    }
}
