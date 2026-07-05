<?php

namespace Tests\Feature;

use App\Models\SleepLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The REAL device→server path for a watch-confirmed NAP. The user starts a sleep session on the band,
 * naps ~45 min, presses stop — the phone delivers a confirmed `sleep_session` marker (T9) carrying the
 * real [bedtime, wake]. Even with NO overnight PPG windows uploaded (the airplane-mode / out-of-range
 * case), the seal must GUARANTEE a bounded nap sleep_logs row from the marker — never drop it, never
 * present a whole-day "Awake 100%" phantom.
 *
 * Regression cover for the lost-nap bug (SealNightJob::sealConfirmedSession + triggerSleepSummary).
 */
class NapSealEndToEndTest extends TestCase
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

    public function test_confirmed_nap_marker_seals_a_bounded_nap_sleep_log(): void
    {
        config(['services.biosignal.url' => 'http://biosignal:8000', 'services.biosignal.token' => 't']);
        Http::fake(['*' => Http::response([])]);   // no windows → staging isn't reached; duration-only path

        $user = User::factory()->create();
        [$profile, $sharedKey] = $this->connect($user);

        // A 45-minute nap this afternoon.
        $wake = time();
        $bed = $wake - 45 * 60;
        $resp = $this->postSummary([
            'kind' => 'sleep_session', 'confirmed' => true, 'bedtime' => $bed, 'wake' => $wake,
        ], $sharedKey);
        $resp->assertStatus(202);

        $nap = SleepLog::where('profile_id', $profile->id)->where('is_nap', true)->first();
        $this->assertNotNull($nap, 'a confirmed nap marker must always seal a sleep_logs row');
        $this->assertEqualsWithDelta(45, (int) $nap->duration_min, 1, 'duration comes from the marker');
        $this->assertNotNull($nap->session_start, 'a nap is keyed by its session_start so it never clobbers a night');
        // Phantom guard: no fabricated all-awake block.
        $this->assertTrue((int) ($nap->awake_min ?? 0) === 0, 'nap must not be a fake 100%-awake block');
    }

    public function test_a_confirmed_nap_does_not_overwrite_the_night_on_the_same_date(): void
    {
        config(['services.biosignal.url' => 'http://biosignal:8000', 'services.biosignal.token' => 't']);
        Http::fake(['*' => Http::response([])]);

        $user = User::factory()->create();
        [$profile, $sharedKey] = $this->connect($user);

        // Last night already sealed for today's date.
        $night = SleepLog::create([
            'profile_id' => $profile->id, 'slept_at' => now()->toDateString(), 'is_nap' => false,
            'duration_min' => 452, 'deep_min' => 90, 'rem_min' => 100, 'light_min' => 262, 'awake_min' => 20,
            'updated_via' => 'biosignal:sealed',
        ]);

        // An afternoon nap the same day.
        $wake = time();
        $bed = $wake - 50 * 60;
        $this->postSummary(['kind' => 'sleep_session', 'confirmed' => true, 'bedtime' => $bed, 'wake' => $wake], $sharedKey)
            ->assertStatus(202);

        $this->assertDatabaseHas('sleep_logs', ['id' => $night->id, 'duration_min' => 452, 'is_nap' => false]);
        $this->assertSame(2, SleepLog::where('profile_id', $profile->id)->count(), 'nap + night coexist, no clobber');
        $this->assertSame(1, SleepLog::where('profile_id', $profile->id)->where('is_nap', true)->count());
    }

    public function test_a_sub_minimum_tap_does_not_seal(): void
    {
        config(['services.biosignal.url' => 'http://biosignal:8000', 'services.biosignal.token' => 't']);
        Http::fake(['*' => Http::response([])]);

        $user = User::factory()->create();
        [$profile, $sharedKey] = $this->connect($user);

        $wake = time();
        $bed = $wake - 12 * 60;   // 12-minute tap — not a nap
        $this->postSummary(['kind' => 'sleep_session', 'confirmed' => true, 'bedtime' => $bed, 'wake' => $wake], $sharedKey)
            ->assertStatus(202);

        $this->assertSame(0, SleepLog::where('profile_id', $profile->id)->count(), 'a 12-min tap must not create sleep noise');
    }
}
