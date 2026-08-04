<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Coach\CoachTools;
use App\Support\ActivityPriming;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A gym spin bike must prime as `spin`, not `cycle`.
 *
 * The firmware picks the HR model from the primed type, using `gps` to tell a road ride
 * (SPORT_TYPE_RIDE_BIKE) from a gym spin bike (SPORT_TYPE_SPINNING) — see hrmAlgoSportFor(). Until
 * 2026-08-03 every indoor word (`spin`, `spinning`, `peloton`) aliased to `cycle`, which ships
 * `gps: true` — so the words that most clearly mean "spin bike" were exactly the ones that
 * guaranteed the ROAD model, and the firmware's SPINNING branch was unreachable from the coach.
 * It also powered the GPS receiver indoors for a fix it would never get.
 */
class SpinBikePrimingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The ordering trap: normalize() falls back to a str_contains scan in INSERTION ORDER, and every
     * one of these phrases contains "bike" or "cycling". If the generic outdoor aliases are listed
     * first, all of them resolve to road cycling — which is exactly the bug this pins.
     */
    public function test_indoor_bike_phrasing_primes_spin_with_gps_off(): void
    {
        $phrases = [
            'spin', 'spinning', 'spin bike', 'stationary bike', 'exercise bike',
            'indoor bike', 'indoor cycling', 'assault bike', 'peloton',
            '30 min on the spin bike', 'hopping on the stationary bike', 'peloton class',
        ];
        foreach ($phrases as $phrase) {
            $this->assertSame('spin', ActivityPriming::normalize($phrase), "\"{$phrase}\" must normalize to spin");
        }
        $this->assertFalse(ActivityPriming::profile('spin')['gps'],
            'a gym bike must not power the GPS receiver indoors — and gps is the firmware discriminator');
    }

    /**
     * The other half of the contract: a bare "bike" must stay ROAD. The wrong guess is asymmetric —
     * a road model on a spin bike costs some HR accuracy; spin on a real ride costs the route.
     */
    public function test_outdoor_bike_phrasing_still_primes_road_cycle_with_gps(): void
    {
        foreach (['bike', 'biking', 'cycling', 'ride', 'cycle', 'going for a bike ride', 'road ride'] as $phrase) {
            $this->assertSame('cycle', ActivityPriming::normalize($phrase), "\"{$phrase}\" must stay road cycling");
        }
        $this->assertTrue(ActivityPriming::profile('cycle')['gps'], 'a road ride wants a route');
    }

    public function test_the_coach_can_actually_start_a_spin_session(): void
    {
        $p = User::factory()->create()->ensureProfile();
        $tools = new CoachTools($p);

        $res = $tools->dispatch('start_activity', ['type' => 'jumping on the spin bike']);

        $this->assertTrue($res['ok']);
        $this->assertFalse($res['sampling']['gps'], 'spin must prime with GPS off — that is the firmware discriminator');

        // The band is PRIMED as spin (so the firmware picks SPORT_TYPE_SPINNING), but the SESSION is
        // stored as `cycle` — the vocabulary the rest of the app knows, and what the watch's own SPIN
        // face declares. Storing `spin` would make ActivitySession::title() read "Workout" instead of
        // "Ride" and split one activity across two buckets by how it was started.
        $this->assertSame('spin', $p->settings['active_activity']['type'], 'the BAND must be primed as spin');
        $this->assertSame('cycle', $p->fresh()->activitySessions()->first()->activity_type,
            'the SESSION must store the known vocabulary, matching the SPIN face');
        $this->assertSame('Ride', $p->fresh()->activitySessions()->first()->title());
    }

    public function test_a_road_ride_is_unaffected(): void
    {
        $p = User::factory()->create()->ensureProfile();
        $tools = new CoachTools($p);

        $res = $tools->dispatch('start_activity', ['type' => 'going for a bike ride']);

        $this->assertTrue($res['ok']);
        $this->assertTrue($res['sampling']['gps']);
        $this->assertSame('cycle', $p->activitySessions()->first()->activity_type);
    }
}
