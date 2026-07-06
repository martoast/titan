<?php

namespace Tests\Feature;

use App\Models\ActivitySession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The REAL device→server path for a watch-confirmed WORKOUT that finished while the phone was out of
 * BLE range. The band persists the session + writes an explicit [start,end,kind] END envelope (TW),
 * which replays on reconnect; the phone delivers it as a signed `workout_session` summary carrying the
 * real bounds + chosen kind. Even with NO accel windows uploaded (the airplane / out-of-range case), the
 * seal must GUARANTEE a bounded activity_sessions row — never drop it, never infer the wrong bounds.
 *
 * Regression cover for the offline-workout durability fix (SealActivityJob::sealConfirmedSession +
 * DeviceIngestionService::triggerWorkoutSummary). Mirrors {@see NapSealEndToEndTest}.
 */
class WorkoutSessionSealEndToEndTest extends TestCase
{
    use RefreshDatabase;

    private function connect(User $user): array
    {
        $profile = $user->ensureProfile();
        $secret = 'test-device-secret';
        $sharedKey = hash('sha256', $secret);
        $profile->wearableConnections()->create([
            'provider' => 'device', 'source' => 'titan_band', 'status' => 'connected',
            'device_id' => 'band-1', 'device_token_hash' => $sharedKey, 'timezone' => 'UTC',
        ]);

        return [$profile, $sharedKey];
    }

    private function postSummary(array $summary, string $sharedKey): \Illuminate\Testing\TestResponse
    {
        $body = json_encode([
            'batch_uid' => substr(hash('sha256', json_encode($summary).microtime()), 0, 32),
            'summaries' => [$summary],
        ]);
        $t = (string) time();
        $v1 = hash_hmac('sha256', $t.'.'.$body, $sharedKey);

        return $this->call('POST', '/api/devices/ingest', [], [], [], [
            'HTTP_X_DEVICE_ID' => 'band-1',
            'HTTP_X_TITAN_SIGNATURE' => "t={$t},v1={$v1}",
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $body);
    }

    public function test_confirmed_lift_marker_guarantees_a_strength_session(): void
    {
        config(['services.biosignal.url' => 'http://biosignal:8000', 'services.biosignal.token' => 't']);
        Http::fake(['*' => Http::response([])]);

        $user = User::factory()->create();
        [$profile, $sharedKey] = $this->connect($user);

        $end = time();
        $start = $end - 45 * 60;
        $resp = $this->postSummary([
            'kind' => 'workout_session', 'confirmed' => true, 'start' => $start, 'end' => $end,
            'activity_kind' => 'strength', 'manual' => true,
        ], $sharedKey);
        $resp->assertStatus(202);

        $session = ActivitySession::where('profile_id', $profile->id)->first();
        $this->assertNotNull($session, 'a confirmed lift envelope must always seal a row (even airplane / no windows)');
        $this->assertSame('strength', $session->activity_type, 'the watch\'s chosen kind is authoritative');
        $this->assertEqualsWithDelta(45, (int) $session->duration_min, 1, 'duration comes from the envelope');
        $this->assertNotNull($session->ended_at, 'the row is bounded by the envelope end');
    }

    public function test_confirmed_run_marker_seals_a_run(): void
    {
        config(['services.biosignal.url' => 'http://biosignal:8000', 'services.biosignal.token' => 't']);
        Http::fake(['*' => Http::response([])]);

        $user = User::factory()->create();
        [$profile, $sharedKey] = $this->connect($user);

        $end = time();
        $start = $end - 30 * 60;
        $this->postSummary([
            'kind' => 'workout_session', 'confirmed' => true, 'start' => $start, 'end' => $end,
            'activity_kind' => 'run', 'manual' => true,
        ], $sharedKey)->assertStatus(202);

        $session = ActivitySession::where('profile_id', $profile->id)->first();
        $this->assertNotNull($session, 'a confirmed run envelope must seal a row');
        $this->assertSame('run', $session->activity_type, 'a run stays a run (run vs lift preserved)');
        $this->assertEqualsWithDelta(30, (int) $session->duration_min, 1);
    }

    public function test_a_sub_minimum_tap_does_not_seal(): void
    {
        config(['services.biosignal.url' => 'http://biosignal:8000', 'services.biosignal.token' => 't']);
        Http::fake(['*' => Http::response([])]);

        $user = User::factory()->create();
        [$profile, $sharedKey] = $this->connect($user);

        $end = time();
        $start = $end - 30;   // 30-second tap — not a workout
        $this->postSummary([
            'kind' => 'workout_session', 'confirmed' => true, 'start' => $start, 'end' => $end,
            'activity_kind' => 'strength',
        ], $sharedKey)->assertStatus(202);

        $this->assertSame(0, ActivitySession::where('profile_id', $profile->id)->count(),
            'a sub-60-s tap must not create workout noise');
    }
}
