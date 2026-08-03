<?php

namespace Tests\Feature;

use App\Jobs\ReactToSleepLogged;
use App\Models\Profile;
use App\Models\SleepLog;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The morning sleep reaction: a win on a well-rested night, a heads-up when debt is building, and
 * silence on the middling/opted-out cases. (Baseline need is 8 h for a profile with no birthdate.)
 */
class SleepReactionTest extends TestCase
{
    use RefreshDatabase;

    private function profileSleeping(int $durationMin, string $intensity = 'balanced'): Profile
    {
        $profile = User::factory()->create()->ensureProfile();
        $profile->update(['settings' => ['timezone' => 'UTC', 'coaching_intensity' => $intensity]]);
        $profile->sleepLogs()->create([
            'slept_at' => '2026-06-24', 'duration_min' => $durationMin, 'quality' => 80, 'updated_via' => 'test',
        ]);

        return $profile->fresh();
    }

    private function fireSleep(Profile $profile): void
    {
        $log = $profile->sleepLogs()->latest('id')->first();
        (new ReactToSleepLogged($log->id))->handle(app(NotificationService::class));
    }

    private function briefingBody(Profile $profile): ?string
    {
        return $profile->conversations()->days()->first()
            ?->messages()->latest('id')->first()?->content;
    }

    public function test_celebrates_a_full_night(): void
    {
        $this->travelTo(Carbon::parse('2026-06-24 07:30:00', 'UTC'));
        $profile = $this->profileSleeping(480);   // 8 h = full

        $this->fireSleep($profile);

        $body = $this->briefingBody($profile);
        $this->assertNotNull($body, 'expected a sleep win');
        $this->assertStringContainsString('Well rested', $body);
        $this->assertSame('2026-06-24', data_get($profile->fresh()->settings, 'sleep_reacted'));
    }

    public function test_flags_sleep_debt(): void
    {
        $this->travelTo(Carbon::parse('2026-06-24 07:30:00', 'UTC'));
        $profile = $this->profileSleeping(300);   // 5 h → ~3 h deficit → debt

        $this->fireSleep($profile);

        $this->assertStringContainsString('debt', strtolower($this->briefingBody($profile) ?? ''));
    }

    public function test_quiet_on_a_middling_night(): void
    {
        $this->travelTo(Carbon::parse('2026-06-24 07:30:00', 'UTC'));
        $profile = $this->profileSleeping(420);   // 7 h → "good", not notable either way

        $this->fireSleep($profile);

        $this->assertNull($this->briefingBody($profile));
    }

    public function test_respects_sleep_opt_out(): void
    {
        $this->travelTo(Carbon::parse('2026-06-24 07:30:00', 'UTC'));
        $profile = $this->profileSleeping(480, intensity: 'minimal');   // sleep nudges off

        $this->fireSleep($profile);

        $this->assertNull($this->briefingBody($profile));
    }

    public function test_reacts_at_most_once_per_night(): void
    {
        $this->travelTo(Carbon::parse('2026-06-24 07:30:00', 'UTC'));
        $profile = $this->profileSleeping(480);

        $this->fireSleep($profile);
        $this->fireSleep($profile->fresh());

        $count = $profile->conversations()->days()->first()->messages()->count();
        $this->assertSame(1, $count);
    }
}
