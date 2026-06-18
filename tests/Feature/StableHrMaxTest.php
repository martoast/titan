<?php

namespace Tests\Feature;

use App\Jobs\SealActivityJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HRmax is a physiological CEILING, not a session's peak. Using the raw session peak made
 * sub-maximal days score as near-maximal (inflated %HRR → intensity, TRIMP, calories, VO2max).
 * stableHrMax() floors with a Tanaka age estimate and ratchets up only on genuine efforts.
 */
class StableHrMaxTest extends TestCase
{
    use RefreshDatabase;

    /** Invoke the private stableHrMax (no setAccessible — PHP 8.1+ reflection allows it). */
    private function hrMax(\App\Models\Profile $p, float $age, ?int $sessionMaxHr): int
    {
        return (new \ReflectionMethod(SealActivityJob::class, 'stableHrMax'))
            ->invoke(new SealActivityJob($p->id), $p, $age, $sessionMaxHr);
    }

    public function test_sub_maximal_session_uses_the_age_floor_not_the_low_peak(): void
    {
        $p = User::factory()->create()->ensureProfile();
        $age = 33.0;
        $ageFloor = (int) round(208 - 0.7 * $age);   // Tanaka ≈ 185

        // A Zone-2 jog peaking at 140 must NOT define HRmax.
        $this->assertSame($ageFloor, $this->hrMax($p, $age, 140));
        // …and a mere easy session is not "remembered" as the ceiling.
        $this->assertNull(data_get($p->refresh()->settings, 'observed_hr_max'));
    }

    public function test_genuine_near_max_effort_ratchets_and_persists(): void
    {
        $p = User::factory()->create()->ensureProfile();

        // A real effort above the age estimate reveals a higher true ceiling.
        $this->assertSame(196, $this->hrMax($p, 33.0, 196));
        $this->assertSame(196, (int) data_get($p->refresh()->settings, 'observed_hr_max'));
    }

    public function test_remembered_ceiling_floors_later_easy_sessions(): void
    {
        $p = User::factory()->create()->ensureProfile();
        $p->update(['settings' => array_merge($p->settings ?? [], ['observed_hr_max' => 196])]);

        // A later easy session (peak 138) is scored against the remembered 196, not 138.
        $this->assertSame(196, $this->hrMax($p->refresh(), 33.0, 138));
    }

    public function test_implausible_peak_is_ignored(): void
    {
        $p = User::factory()->create()->ensureProfile();
        $p->update(['settings' => array_merge($p->settings ?? [], ['observed_hr_max' => 196])]);

        // A PPG-spike artifact (230 bpm) must not poison the stored ceiling.
        $this->assertSame(196, $this->hrMax($p->refresh(), 33.0, 230));
        $this->assertSame(196, (int) data_get($p->refresh()->settings, 'observed_hr_max'));
    }

    public function test_no_session_hr_falls_back_to_the_age_estimate(): void
    {
        $p = User::factory()->create()->ensureProfile();
        $this->assertSame((int) round(208 - 0.7 * 40), $this->hrMax($p, 40.0, null));
    }
}
