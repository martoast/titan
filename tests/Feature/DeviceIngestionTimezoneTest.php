<?php

namespace Tests\Feature;

use App\Models\DeviceIngestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Window bounds must round-trip to their TRUE instant regardless of the server's timezone.
 *
 * Eloquent's `datetime` cast reads columns back in config('app.timezone'). A window ingested with a
 * UTC (Zulu) start, if stored as-is, reads back as that wall-clock in LOCAL time on a non-UTC server —
 * e.g. 08:00Z → 08:00 local → a 6 h shift. That silently pushed the window out of the confirmed
 * sleep-seal's UTC [bed,wake] scope, so the night sealed with a duration but 0% on every stage.
 * The ingest normalises to the app tz on write so the read-back instant is always correct.
 */
class DeviceIngestionTimezoneTest extends TestCase
{
    use RefreshDatabase;

    private function connect(User $user): string
    {
        $secret = 'tz-test-secret';
        $user->ensureProfile()->wearableConnections()->create([
            'provider' => 'device', 'source' => 'titan_band', 'status' => 'connected',
            'device_id' => 'band-tz', 'device_token_hash' => hash('sha256', $secret), 'timezone' => 'UTC',
        ]);

        return hash('sha256', $secret);
    }

    private function postWindows(array $windows, string $sharedKey): \Illuminate\Testing\TestResponse
    {
        $body = json_encode([
            'batch_uid' => substr(hash('sha256', json_encode($windows).microtime()), 0, 32),
            'device_id' => 'band-tz',
            'timezone' => 'UTC',
            'windows' => $windows,
        ]);
        $t = (string) time();
        $sig = 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.$body, $sharedKey);

        return $this->call('POST', '/api/devices/ingest', [], [], [], [
            'HTTP_X_DEVICE_ID' => 'band-tz',
            'HTTP_X_TITAN_SIGNATURE' => $sig,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $body);
    }

    public function test_window_bounds_round_trip_to_true_utc_on_a_non_utc_server(): void
    {
        config(['app.timezone' => 'America/Mexico_City']);   // the shift only bites non-UTC servers
        Queue::fake();                                        // we only care about the stored window row

        $user = User::factory()->create();
        $sharedKey = $this->connect($user);

        // A window recorded 08:00–08:05 UTC, sent Zulu exactly as the band/bridge does. (An IBI window —
        // the tz normalisation is kind-agnostic, and this avoids the PPG flatline gate for a fixture.)
        $startZulu = '2026-07-07T08:00:00Z';
        $endZulu = '2026-07-07T08:05:00Z';
        $ibi = array_map(fn ($i) => 1000 + ($i % 7) * 15 - 45, range(0, 299));
        $this->postWindows([[
            'kind' => 'ibi', 'start' => $startZulu, 'end' => $endZulu,
            'ibi_ms' => $ibi, 'accel_counts' => array_fill(0, 10, 2), 'confidence' => 0.9,
        ]], $sharedKey)->assertStatus(202);

        $row = DeviceIngestion::where('profile_id', $user->profile->id)->whereNotNull('window_start')->first();
        $this->assertNotNull($row);

        // The read-back instant (via the datetime cast) must equal the true UTC instant that was sent —
        // not a tz-shifted one. Pre-fix this was off by the app-tz offset (−6 h).
        $this->assertSame(
            Carbon::parse($startZulu)->timestamp,
            $row->window_start->getTimestamp(),
            'window_start must round-trip to the true UTC instant on a non-UTC server',
        );
        $this->assertSame(
            Carbon::parse($endZulu)->timestamp,
            $row->window_end->getTimestamp(),
            'window_end must round-trip to the true UTC instant on a non-UTC server',
        );
    }
}
