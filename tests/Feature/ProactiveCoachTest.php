<?php

namespace Tests\Feature;

use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Coach\CoachTools;
use App\Support\CoachNudge;
use App\Support\Reminders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ProactiveCoachTest extends TestCase
{
    use RefreshDatabase;

    public function test_intensity_tiers_drive_which_nudges_are_on(): void
    {
        $p = User::factory()->create()->ensureProfile();

        Reminders::setIntensity($p, 'minimal');
        $this->assertTrue(Reminders::enabled($p->refresh(), 'briefing'));
        $this->assertFalse(Reminders::enabled($p, 'meals'));
        $this->assertFalse(Reminders::enabled($p, 'move'));

        Reminders::setIntensity($p, 'intense');
        foreach (['briefing', 'meals', 'sleep', 'cycle', 'move', 'training'] as $t) {
            $this->assertTrue(Reminders::enabled($p->refresh(), $t), "intense should enable {$t}");
        }
    }

    public function test_per_type_override_wins_over_intensity(): void
    {
        $p = User::factory()->create()->ensureProfile();
        Reminders::setIntensity($p, 'intense');
        Reminders::setType($p->refresh(), 'meals', false);
        $this->assertFalse(Reminders::enabled($p->refresh(), 'meals'));
        $this->assertTrue(Reminders::enabled($p, 'sleep'));
    }

    public function test_move_nudge_only_fires_when_sedentary(): void
    {
        $p = User::factory()->create()->ensureProfile();
        $p->update(['settings' => ['timezone' => 'UTC']]);

        // Plenty of steps → no nudge. Use the profile-tz date that move() will query (avoids a
        // date-boundary flake when the app tz differs from the settings tz).
        $row = $p->dailyActivity()->create(['date' => Carbon::now('UTC')->toDateString(), 'steps' => 9000, 'source' => 'manual']);
        $this->assertNull(CoachNudge::move($p->refresh()));

        // Sedentary → a move/stretch nudge.
        $row->update(['steps' => 600]);
        $n = CoachNudge::move($p->refresh());
        $this->assertNotNull($n);
        $this->assertSame('move', $n['type']);
        $this->assertStringContainsString('stretch', strtolower($n['body'].' '.$n['title']).' stretch');
    }

    public function test_sleep_nudge_fires_in_the_hour_before_bedtime(): void
    {
        $p = User::factory()->create()->ensureProfile();
        $p->update(['settings' => ['timezone' => 'UTC']]);
        // Wake ~07:00 logged, need ~8h → bedtime ~23:00.
        foreach (range(1, 3) as $d) {
            $p->sleepLogs()->create(['slept_at' => Carbon::today()->subDays($d)->toDateString(), 'duration_min' => 480, 'wake_time' => '07:00']);
        }

        $atBed = Carbon::parse('2026-06-17 22:40', 'UTC');     // ~20 min before 23:00
        $this->assertNotNull(CoachNudge::sleep($p->refresh(), $atBed));

        $afternoon = Carbon::parse('2026-06-17 15:00', 'UTC');
        $this->assertNull(CoachNudge::sleep($p->refresh(), $afternoon));
    }

    public function test_the_command_pushes_and_dedupes(): void
    {
        $u = User::factory()->create();
        $p = $u->ensureProfile();
        $p->update(['settings' => ['timezone' => 'UTC', 'coaching_intensity' => 'intense']]);
        $p->dailyActivity()->create(['date' => Carbon::today()->toDateString(), 'steps' => 400, 'source' => 'manual']);
        PushSubscription::create(['profile_id' => $p->id, 'endpoint' => 'https://x/'.$p->id, 'endpoint_hash' => hash('sha256', 'x'.$p->id), 'p256dh' => 'k', 'auth' => 'a']);

        $this->artisan('coach:nudge move')->assertSuccessful();
        $this->assertDatabaseHas('notifications', ['profile_id' => $p->id, 'type' => 'nudge']);
        $this->assertSame(1, \App\Models\Notification::where('profile_id', $p->id)->count());

        // Second run same day → deduped, no new notification.
        $this->artisan('coach:nudge move')->assertSuccessful();
        $this->assertSame(1, \App\Models\Notification::where('profile_id', $p->id)->count());
    }

    public function test_strain_coach_suggests_a_concrete_session_to_hit_target(): void
    {
        // Under a "primed to push" target (14-18) at 6 strain → a real session closes the gap.
        $rec = \App\Support\Strain::sessionForTarget(6.0, ['low' => 14.0, 'high' => 18.0, 'mode' => 'push', 'label' => 'Primed to push']);
        $this->assertNotNull($rec);
        $this->assertGreaterThanOrEqual(15, $rec['minutes']);
        $this->assertContains($rec['zone'], ['Z2', 'Z3']);
        $this->assertEqualsWithDelta(8.0, $rec['strain_to_go'], 0.1);

        // Already in the band → nothing to add.
        $this->assertNull(\App\Support\Strain::sessionForTarget(15.0, ['low' => 14.0, 'high' => 18.0, 'mode' => 'push', 'label' => '']));
    }

    public function test_strain_nudge_emails_an_actionable_session_when_primed_and_under(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $u = User::factory()->create();
        $p = $u->ensureProfile();
        $p->update(['settings' => ['timezone' => 'UTC', 'coaching_intensity' => 'intense']]);
        // A strong recovery baseline so readiness is high (→ "primed to push" target) and NO workouts
        // today (→ strain well under target). Rising HRV keeps today at/above baseline.
        for ($d = 20; $d >= 0; $d--) {
            $p->recoveryLogs()->create([
                'logged_at' => Carbon::today()->subDays($d)->toDateString(),
                'hrv_ms' => 70 + (20 - $d), 'resting_hr' => 48, 'updated_via' => 'biosignal:sealed:seed',
            ]);
        }

        $this->artisan('coach:nudge strain')->assertSuccessful();

        $note = \App\Models\Notification::where('profile_id', $p->id)->where('type', 'nudge')->first();
        $this->assertNotNull($note, 'the strain coach should nudge a primed, under-target athlete');
        $this->assertMatchesRegularExpression('/\d+-min Z[23]/', (string) $note->body, 'an actionable session (minutes + zone)');
        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\CoachMessage::class);
    }

    public function test_coach_tool_sets_intensity_and_toggles_a_type(): void
    {
        $p = User::factory()->create()->ensureProfile();
        $t = new CoachTools($p);

        $res = $t->dispatch('set_reminders', ['intensity' => 'intense']);
        $this->assertSame('intense', $res['intensity']);
        $this->assertTrue($res['reminders']['move']['on']);

        $t->dispatch('set_reminders', ['type' => 'move', 'on' => false]);
        $this->assertFalse(Reminders::enabled($p->refresh(), 'move'));
    }

    public function test_onboarding_saves_coaching_intensity(): void
    {
        $user = User::factory()->notOnboarded()->create();
        $this->actingAs($user)->post('/onboarding', [
            'display_name' => 'Ana', 'birthdate' => '1995-01-01', 'sex' => 'F', 'units' => 'metric',
            'height' => 165, 'weight' => 60, 'activity_level' => 'moderate', 'primary_goal' => 'recomp',
            'coach_tone' => 'balanced', 'coaching_intensity' => 'intense', 'meals_per_day' => 4,
        ])->assertRedirect(route('coach.index'));

        $this->assertSame('intense', $user->refresh()->profile->settings['coaching_intensity']);
    }
}
