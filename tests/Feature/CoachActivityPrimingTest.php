<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WearableConnection;
use App\Services\Coach\CoachTools;
use App\Support\ActivityPriming;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CoachActivityPrimingTest extends TestCase
{
    use RefreshDatabase;

    public function test_starting_a_run_opens_a_session_and_primes_the_wearable(): void
    {
        $p = User::factory()->create()->ensureProfile();
        $tools = new CoachTools($p);

        $res = $tools->dispatch('start_activity', ['type' => 'going for a run']);

        $this->assertTrue($res['ok']);
        $this->assertSame('Run', $res['activity']);
        $this->assertTrue($res['wearable_primed']);
        $this->assertTrue($res['sampling']['gps']);   // a run powers GPS

        // An open session exists, typed as a run.
        $this->assertSame(1, $p->activitySessions()->whereNull('ended_at')->count());
        $this->assertSame('run', $p->activitySessions()->first()->activity_type);

        // The priming is stashed on the profile for the band to read.
        $p->refresh();
        $this->assertSame('run', $p->settings['active_activity']['type']);
    }

    public function test_finishing_clears_the_priming_and_closes_the_session(): void
    {
        $p = User::factory()->create()->ensureProfile();
        $tools = new CoachTools($p);

        $tools->dispatch('start_activity', ['type' => 'bike ride']);
        $done = $tools->dispatch('finish_activity', ['distance_km' => 20.5, 'avg_hr' => 142]);

        $this->assertTrue($done['ok']);
        $this->assertFalse($done['wearable_primed']);

        $p->refresh();
        $this->assertArrayNotHasKey('active_activity', $p->settings ?? []);
        $session = $p->activitySessions()->first();
        $this->assertNotNull($session->ended_at);
        $this->assertSame('cycle', $session->activity_type);   // "bike ride" normalized
        $this->assertEqualsWithDelta(20.5, (float) $session->distance_km, 0.01);
    }

    public function test_priming_normalizes_synonyms(): void
    {
        $this->assertSame('run', ActivityPriming::normalize('jog'));
        // Was 'cycle' until 2026-08-03. A Peloton is a stationary bike, and the firmware now picks a
        // different HR model for it (SPORT_TYPE_SPINNING vs RIDE_BIKE) using `gps` as the
        // discriminator — so priming it as road cycling both requested the wrong model and powered
        // the GPS indoors. See SpinBikePrimingTest.
        $this->assertSame('spin', ActivityPriming::normalize('Peloton spin'));
        $this->assertSame('row', ActivityPriming::normalize('erg'));
        $this->assertSame('other', ActivityPriming::normalize('underwater basket weaving'));
        $this->assertFalse(ActivityPriming::profile('swim')['gps']);   // no GPS underwater
    }

    public function test_device_can_read_the_active_activity_over_hmac(): void
    {
        $p = User::factory()->create()->ensureProfile();
        (new CoachTools($p))->dispatch('start_activity', ['type' => 'run']);

        // Pair a device the way the controller does (sha256(secret) is the shared HMAC key).
        $secret = bin2hex(random_bytes(32));
        $conn = WearableConnection::create([
            'profile_id' => $p->id,
            'provider' => 'TITAN_BAND',
            'source' => 'titan_band',
            'device_id' => 'titan_band_test',
            'device_token_hash' => hash('sha256', $secret),
            'status' => 'connected',
        ]);

        $sharedKey = hash('sha256', $secret);
        $t = (string) time();
        $sig = 't='.$t.',v1='.hash_hmac('sha256', $t.'.', $sharedKey);   // verifier signs "{t}.{body}", empty GET body

        // Use get() (not getJson) so the body is truly empty — what a real device GET sends,
        // and what the signature is computed over.
        $res = $this->withHeaders([
            'X-Device-Id' => $conn->device_id,
            'X-Titan-Signature' => $sig,
            'Accept' => 'application/json',
        ])->get('/api/devices/activity');

        $res->assertOk()
            ->assertJson(['active' => true, 'type' => 'run'])
            ->assertJsonPath('sampling.gps', true);
    }
}
