<?php

namespace Tests\Feature;

use App\Models\LongevitySnapshot;
use App\Models\User;
use App\Support\LongevityIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The Titan Longevity Index: fuse the existing biological-age ingredients into one Titan Age + a
 * pace-of-aging trend, degrading honestly with partial data.
 */
class LongevityIndexTest extends TestCase
{
    use RefreshDatabase;

    private function profileWithFitness(int $ageYears = 35, float $vo2 = 55): \App\Models\Profile
    {
        $p = User::factory()->create()->ensureProfile();
        $p->update(['sex' => 'M', 'birthdate' => Carbon::today()->subYears($ageYears)->toDateString()]);
        $p->activitySessions()->create(['started_at' => now()->subDay(), 'source' => 'titan_band', 'vo2max' => $vo2]);

        return $p->refresh();
    }

    public function test_assesses_a_titan_age_from_a_fitness_anchor(): void
    {
        $r = LongevityIndex::assess($this->profileWithFitness(35, 58));   // strong VO2max → younger

        $this->assertNotNull($r);
        $this->assertIsFloat($r['titan_age']);
        $this->assertEqualsWithDelta(35.0, $r['chronological_age'], 1.0);
        $this->assertContains($r['confidence'], ['high', 'medium', 'low']);
        // A partial (no-bloodwork) read is labeled partial, never presented as a confident full clock.
        $this->assertTrue($r['partial']);
        // Pace has no history yet → building, not a fabricated rate.
        $this->assertNull($r['pace']['value']);
        $this->assertSame('building your aging trend', $r['pace']['label']);
    }

    public function test_returns_null_without_enough_signal(): void
    {
        $p = User::factory()->create()->ensureProfile();   // no birthdate, no data
        $this->assertNull(LongevityIndex::assess($p));
    }

    public function test_snapshot_stores_history_and_pace_becomes_real_over_time(): void
    {
        $p = $this->profileWithFitness();

        // Two snapshots ~a year apart, titan age rising only ~half as fast as the calendar → pace ≈ 0.5×.
        LongevitySnapshot::create(['profile_id' => $p->id, 'captured_on' => Carbon::today()->subDays(365)->toDateString(),
            'titan_age' => 34.0, 'chronological_age' => 34.0, 'delta' => 0.0, 'confidence' => 'medium']);
        LongevitySnapshot::create(['profile_id' => $p->id, 'captured_on' => Carbon::today()->toDateString(),
            'titan_age' => 34.5, 'chronological_age' => 35.0, 'delta' => -0.5, 'confidence' => 'medium']);

        $r = LongevityIndex::assess($p);
        $this->assertNotNull($r['pace']['value']);
        $this->assertLessThan(1.0, $r['pace']['value']);          // aging slower than the clock
        $this->assertSame('younger', $r['pace']['direction']);
    }

    public function test_snapshot_is_idempotent_per_day(): void
    {
        $p = $this->profileWithFitness();

        $this->assertNotNull(LongevityIndex::snapshot($p));
        LongevityIndex::snapshot($p);   // same day again
        $this->assertSame(1, LongevitySnapshot::where('profile_id', $p->id)->count());
    }
}
