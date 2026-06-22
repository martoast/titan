<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Body page collects + displays in the user's units, but always stores metric.
 */
class BodyUnitsTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $units): User
    {
        $user = User::factory()->create();
        $p = $user->ensureProfile();
        $p->settings = array_merge($p->settings ?? [], ['units' => $units]);
        $p->save();

        return $user;
    }

    public function test_imperial_weight_is_stored_as_kg_and_shown_back_as_lb(): void
    {
        $user = $this->user('imperial');

        // User enters 182 lb and 32 in waist.
        $this->actingAs($user)->post('/body', [
            'weight_kg' => 182,   // lb on the wire
            'waist_cm' => 32,     // in on the wire
            'taken_at' => now()->toDateString(),
        ])->assertRedirect();

        $row = $user->profile->bodyMetrics()->first();
        $this->assertEqualsWithDelta(82.55, (float) $row->weight_kg, 0.1);   // 182 lb → ~82.55 kg
        $this->assertEqualsWithDelta(81.28, (float) $row->waist_cm, 0.1);    // 32 in → ~81.3 cm

        // And the page reads it back in lb / in.
        $resp = $this->actingAs($user)->get('/body');
        $resp->assertOk();
        $resp->assertSee('Weight (lb)');
        $resp->assertSee('182');           // ~182 lb back
        $resp->assertDontSee('Weight (kg)');
    }

    public function test_metric_weight_is_stored_and_shown_as_kg(): void
    {
        $user = $this->user('metric');

        $this->actingAs($user)->post('/body', [
            'weight_kg' => 80.5,
            'taken_at' => now()->toDateString(),
        ])->assertRedirect();

        $row = $user->profile->bodyMetrics()->first();
        $this->assertEqualsWithDelta(80.5, (float) $row->weight_kg, 0.01);

        $resp = $this->actingAs($user)->get('/body');
        $resp->assertSee('Weight (kg)');
        $resp->assertSee('80.5');
    }
}
