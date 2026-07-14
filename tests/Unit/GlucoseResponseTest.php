<?php

namespace Tests\Unit;

use App\Models\GlucoseReading;
use App\Models\Meal;
use App\Models\Profile;
use App\Support\GlucoseResponse;
use Illuminate\Support\Carbon;
use Tests\TestCase;   // Laravel base (boots the app) — the forMeal test instantiates Eloquent models

/**
 * A meal's glucose response math (CGM_INTEGRATION P2) — baseline → peak Δ, time-to-peak, time-to-baseline,
 * and the 2-h incremental AUC. Pure, no DB.
 */
class GlucoseResponseTest extends TestCase
{
    /** A classic post-meal curve: baseline 90, rises to a 145 peak at 45 min, back to baseline by 100 min. */
    private array $curve = [
        ['min' => 0, 'mg' => 90],
        ['min' => 15, 'mg' => 110],
        ['min' => 30, 'mg' => 135],
        ['min' => 45, 'mg' => 145],
        ['min' => 60, 'mg' => 130],
        ['min' => 90, 'mg' => 105],
        ['min' => 100, 'mg' => 92],
        ['min' => 120, 'mg' => 88],
    ];

    public function test_peak_delta_and_timing(): void
    {
        $r = GlucoseResponse::compute(90, $this->curve);
        $this->assertSame(145, $r['peak_mg_dl']);
        $this->assertSame(55, $r['peak_delta']);        // 145 − 90
        $this->assertSame(45, $r['time_to_peak_min']);
        $this->assertSame(100, $r['time_to_baseline_min']);  // first ≤ 100 (baseline+10) after the peak
        $this->assertSame('large', $r['spike']);        // Δ55 ≥ 50
    }

    public function test_incremental_auc_is_area_above_baseline(): void
    {
        // AUC only counts glucose ABOVE baseline, trapezoidal over 2 h. A flat-at-baseline curve → 0.
        $flat = [['min' => 0, 'mg' => 90], ['min' => 60, 'mg' => 90], ['min' => 120, 'mg' => 90]];
        $this->assertSame(0.0, GlucoseResponse::incrementalAuc(90, $flat));
        // A simple triangle: 0→+60 over 0–120 min back to 0 → area = ½·base·height = ½·120·60 = 3600.
        $tri = [['min' => 0, 'mg' => 90], ['min' => 60, 'mg' => 150], ['min' => 120, 'mg' => 90]];
        $this->assertEqualsWithDelta(3600.0, GlucoseResponse::incrementalAuc(90, $tri), 0.001);
    }

    public function test_spike_bands(): void
    {
        $this->assertSame('small', GlucoseResponse::spikeBand(20));
        $this->assertSame('moderate', GlucoseResponse::spikeBand(40));
        $this->assertSame('large', GlucoseResponse::spikeBand(60));
    }

    public function test_no_return_to_baseline_is_null(): void
    {
        // Rises and stays elevated within the window → time_to_baseline_min null (honest: didn't recover).
        $r = GlucoseResponse::compute(90, [['min' => 0, 'mg' => 90], ['min' => 30, 'mg' => 150], ['min' => 120, 'mg' => 140]]);
        $this->assertSame(60, $r['peak_delta']);
        $this->assertNull($r['time_to_baseline_min']);
    }

    public function test_empty_returns_null(): void
    {
        $this->assertNull(GlucoseResponse::compute(90, []));
        $this->assertNull(GlucoseResponse::compute(90, [['min' => -5, 'mg' => 90]]));   // only pre-meal
    }

    /** forMeal windows a readings collection around eaten_at — baseline from pre-meal, response after.
     *  Regression for review 5876613 (the query frame must match app-tz storage; the collection path is
     *  tz-agnostic and correct). */
    public function test_forMeal_windows_a_readings_collection(): void
    {
        $meal = new Meal(['eaten_at' => Carbon::parse('2026-07-14 12:00:00')]);
        $readings = collect([
            new GlucoseReading(['taken_at' => Carbon::parse('2026-07-14 11:50:00'), 'mg_dl' => 90]),
            new GlucoseReading(['taken_at' => Carbon::parse('2026-07-14 12:00:00'), 'mg_dl' => 92]),
            new GlucoseReading(['taken_at' => Carbon::parse('2026-07-14 12:45:00'), 'mg_dl' => 146]),
            new GlucoseReading(['taken_at' => Carbon::parse('2026-07-14 13:40:00'), 'mg_dl' => 95]),
        ]);
        $r = GlucoseResponse::forMeal(new Profile(), $meal, $readings);

        $this->assertNotNull($r);
        $this->assertSame(91, $r['baseline_mg_dl']);      // mean of the two pre-meal readings (90, 92)
        $this->assertSame(146, $r['peak_mg_dl']);
        $this->assertSame(55, $r['peak_delta']);          // 146 − 91
        $this->assertSame(45, $r['time_to_peak_min']);
    }
}
