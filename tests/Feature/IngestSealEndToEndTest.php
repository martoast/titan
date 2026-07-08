<?php

namespace Tests\Feature;

use App\Models\ActivitySession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The REAL device→server path for a finished lift: a signed POST to /api/devices/ingest carrying an
 * `ended` strength workout window must flow controller → DeviceIngestionService → ProcessWindowJob →
 * SealActivityJob and produce an activity_sessions row the app's /api/me/runs returns. This is exactly
 * what the watch+phone do when you finish a lift, so it proves the deployed server code saves it.
 *
 * It must NOT fabricate exercises/sets from the accelerometer: that auto-gym-detection was removed in
 * 9b9f4d6 ("stop fabricating lifts on a workout seal") because the ~10-movement classifier invented
 * lifts (jumping jacks, sit-ups…) for any low-motion session. Real sets are only ever written when the
 * user explicitly tells the coach (log_set / log_workout). So a sealed lift = a strength session with
 * its stats, and ZERO auto-created workouts.
 */
class IngestSealEndToEndTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_ended_lift_window_seals_into_an_activity_session(): void
    {
        Storage::fake('raw');
        config(['services.biosignal.url' => 'http://biosignal:8000', 'services.biosignal.token' => 't']);
        Http::fake([
            '*/process/activity' => Http::response(['metrics' => ['sessions' => [[
                'duration_min' => 30.0, 'mean_hr' => 130.0, 'trimp' => 40.0, 'calories_kcal' => 250,
                'activity_type' => 'other', 'activity_confidence' => 0.5,
            ]], 'session_count' => 1]]),
            '*/process/fitness' => Http::response(['vo2max' => 50.0, 'plusminus' => 5.6,
                'methods' => ['demographic'], 'fitness_level' => 'high', 'fitness_percentile_band' => 3, 'hrr' => null]),
            // Note: /process/gym is intentionally NOT called on a seal anymore (see class docblock) — if it
            // ever is again, this catch-all returns an empty set list so nothing gets fabricated silently.
            '*' => Http::response([]),
        ]);

        $user = User::factory()->create();
        $profile = $user->ensureProfile();
        $profile->update(['birthdate' => '1991-01-01', 'sex' => 'M', 'height_cm' => 180]);

        $secret = 'test-device-secret';
        $sharedKey = hash('sha256', $secret);
        $profile->wearableConnections()->create([
            'provider' => 'device', 'source' => 'titan_band', 'status' => 'connected',
            'device_id' => 'band-1', 'device_token_hash' => $sharedKey,
        ]);

        // A 30-min lift window exactly as the phone builds it: ended=true + activity_kind=strength.
        $fs = 25;
        $secs = 30 * 60;
        $hr = array_fill(0, $secs, 130);
        $n = $secs * $fs;
        $ax = $ay = array_fill(0, $n, 0);
        $az = array_fill(0, $n, 1000);
        $end = now();
        $start = $end->copy()->subMinutes(30);
        $window = [
            'kind' => 'workout',
            'start' => $start->toIso8601ZuluString(),
            'end' => $end->toIso8601ZuluString(),
            'accel_xyz' => ['x' => $ax, 'y' => $ay, 'z' => $az],
            'accel_fs' => $fs, 'accel_unit' => 'mg',
            'hr_bpm' => $hr, 'accel_counts' => array_fill(0, 60, 30),
            'gps' => ['speed_kmh' => [], 'grade' => [], 'track' => []],
            'src' => 'banglejs2',
            'ended' => true,
            'activity_kind' => 'strength',
        ];
        // batch_uid exactly as the iOS app sends it: a STABLE 32-hex sha256 prefix (IngestClient
        // .stableUID), NOT a ULID. The server must accept it or every phone upload is 422-dropped.
        $body = json_encode([
            'batch_uid' => substr(hash('sha256', json_encode($window)), 0, 32),
            'windows' => [$window],
        ]);

        $t = (string) time();
        $v1 = hash_hmac('sha256', $t.'.'.$body, $sharedKey);

        // Raw call(): headers must go in as server vars (withHeaders() only applies to the json/get
        // helpers), and the body must stay byte-identical to what was signed.
        $resp = $this->call('POST', '/api/devices/ingest', [], [], [], [
            'HTTP_X_DEVICE_ID' => 'band-1',
            'HTTP_X_TITAN_SIGNATURE' => "t={$t},v1={$v1}",
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $body);

        $resp->assertStatus(202);
        $this->assertFalse($resp->json('duplicate'));
        $this->assertSame(1, $resp->json('windows_queued'));

        // The full chain ran (sync queue in tests): a strength session exists and the app's list returns it.
        $session = ActivitySession::where('profile_id', $profile->id)->first();
        $this->assertNotNull($session, 'an ended lift window must seal into an activity_sessions row');
        $this->assertSame('strength', $session->activity_type);
        // The seal must NOT fabricate a gym workout from the accelerometer — sets are only ever written
        // when the user explicitly logs them via the coach (removed in 9b9f4d6). See class docblock.
        $this->assertNull(\App\Models\Workout::where('profile_id', $profile->id)->first(),
            'a sealed lift must not auto-fabricate a workout/sets');
    }
}
