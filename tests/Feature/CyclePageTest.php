<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Cycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CyclePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_state_renders_for_a_woman(): void
    {
        $u = User::factory()->create();
        $u->ensureProfile()->update(['sex' => 'F']);

        $resp = $this->actingAs($u)->get('/cycle');
        $resp->assertOk();
        $resp->assertSee('Log your period to begin');
    }

    public function test_period_logging_and_ring_render(): void
    {
        $u = User::factory()->create();
        $u->ensureProfile()->update(['sex' => 'F', 'settings' => ['timezone' => 'UTC']]);

        $this->actingAs($u)->post('/cycle/period', ['event' => 'start', 'date' => Carbon::today()->subDays(6)->toDateString()])
            ->assertRedirect('/cycle');

        $this->assertDatabaseHas('menstrual_cycles', ['profile_id' => $u->profile->id]);

        $resp = $this->actingAs($u)->get('/cycle');
        $resp->assertOk();
        $resp->assertSee('Day');              // the ring centre
        $resp->assertSee('Next period');
        $resp->assertSee('Fertile window');
    }

    public function test_logging_a_day_with_symptoms(): void
    {
        $u = User::factory()->create();
        $u->ensureProfile()->update(['sex' => 'F', 'settings' => ['timezone' => 'UTC']]);

        $this->actingAs($u)->post('/cycle/day', [
            'date' => Carbon::today()->toDateString(),
            'flow' => 'medium',
            'symptoms' => ['cramps', 'fatigue'],
            'mood' => 3,
        ])->assertRedirect('/cycle');

        $this->assertDatabaseHas('cycle_logs', ['profile_id' => $u->profile->id, 'flow' => 'medium', 'mood' => 3]);
    }

    public function test_dashboard_shows_a_cycle_tile_for_a_woman_with_data(): void
    {
        $u = User::factory()->create();
        $u->ensureProfile()->update(['sex' => 'F', 'settings' => ['timezone' => 'UTC']]);
        $this->actingAs($u)->post('/cycle/period', ['event' => 'start', 'date' => Carbon::today()->subDays(3)->toDateString()]);

        $resp = $this->actingAs($u)->get('/dashboard');
        $resp->assertOk();
        $resp->assertSee('phase');   // the cycle tile
    }

    public function test_settings_save(): void
    {
        $u = User::factory()->create();
        $u->ensureProfile()->update(['sex' => 'F']);

        $this->actingAs($u)->post('/cycle/settings', ['avg_length' => 30, 'birth_control' => 'pill', 'intent' => 'avoiding'])
            ->assertRedirect('/cycle');

        $cfg = Cycle::config($u->profile->refresh());
        $this->assertSame(30, $cfg['avg_length']);
        $this->assertSame('pill', $cfg['birth_control']);
    }
}
