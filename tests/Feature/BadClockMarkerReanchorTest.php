<?php

namespace Tests\Feature;

use App\Models\ActivitySession;
use App\Models\SleepLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * After a dead-battery reboot the band's RTC restarts at 1970 and stays wrong until a C2 time-sync
 * lands. Raw windows are re-anchored on ingest (reanchorIfClockBad), but confirmed session markers
 * (T9 "I'm awake" / TW workout end) used to pass their epoch bounds through RAW — the scoped seal then
 * looked for windows in 1970, found nothing, and wrote a phantom 1970-dated marker-only row while the
 * real night waited hours for the slow auto pass. Bit Tester B four mornings running (2026-07-23..26).
 *
 * These tests pin the guard: epoch-garbage marker bounds are re-anchored to the upload time with their
 * span preserved, so the row lands on TODAY and no pre-2020 row is ever written.
 */
class BadClockMarkerReanchorTest extends TestCase
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

    public function test_a_1970_stamped_wake_marker_seals_todays_night_not_a_phantom_1970_row(): void
    {
        config(['services.biosignal.url' => 'http://biosignal:8000', 'services.biosignal.token' => 't']);
        Http::fake(['*' => Http::response([])]);   // no windows → duration-only marker path

        $user = User::factory()->create();
        [$profile, $sharedKey] = $this->connect($user);

        // The band rebooted ~1.5 days ago (RTC at epoch 0): an 8h night stamped on day 2 of 1970.
        $bed = 86400 + 22 * 3600;           // 1970-01-02 22:00Z
        $wake = $bed + 8 * 3600;            // 1970-01-03 06:00Z
        $this->postSummary([
            'kind' => 'sleep_session', 'confirmed' => true, 'bedtime' => $bed, 'wake' => $wake,
        ], $sharedKey)->assertStatus(202);

        $this->assertSame(0, SleepLog::where('slept_at', '<', '2020-01-01')->count(),
            'a bad-clock marker must never write a pre-2020 sleep row');

        $night = SleepLog::where('profile_id', $profile->id)->where('is_nap', false)->first();
        $this->assertNotNull($night, 'the confirmed marker must still seal a night row');
        $this->assertSame(now()->toDateString(), $night->slept_at->toDateString(),
            're-anchored night lands on the upload date');
        $this->assertEqualsWithDelta(8 * 60, (int) $night->duration_min, 2,
            're-anchoring preserves the marker\'s declared span');
    }

    public function test_a_1970_stamped_workout_marker_seals_today_not_a_phantom_1970_session(): void
    {
        config(['services.biosignal.url' => 'http://biosignal:8000', 'services.biosignal.token' => 't']);
        Http::fake(['*' => Http::response([])]);

        $user = User::factory()->create();
        [$profile, $sharedKey] = $this->connect($user);

        // A 97-minute lift stamped on day 1 of 1970 (Tester B's real phantom, activity_sessions id 37).
        $start = 19 * 3600 + 40 * 60;
        $end = $start + 97 * 60;
        $this->postSummary([
            'kind' => 'workout_session', 'confirmed' => true, 'start' => $start, 'end' => $end,
            'activity_kind' => 'strength', 'manual' => true,
        ], $sharedKey)->assertStatus(202);

        $this->assertSame(0, ActivitySession::where('started_at', '<', '2020-01-01')->count(),
            'a bad-clock workout marker must never write a pre-2020 session');

        $session = ActivitySession::where('profile_id', $profile->id)->first();
        $this->assertNotNull($session, 'the confirmed workout marker must still seal a session row');
        $this->assertSame(now()->toDateString(), $session->started_at->toDateString(),
            're-anchored workout lands on the upload date');
        $this->assertEqualsWithDelta(97, (int) $session->duration_min, 2,
            're-anchoring preserves the marker\'s declared span');
    }
}
