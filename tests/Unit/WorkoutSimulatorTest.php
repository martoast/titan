<?php

namespace Tests\Unit;

use App\Services\Simulator\BiosignalSimulator;
use PHPUnit\Framework\TestCase;

class WorkoutSimulatorTest extends TestCase
{
    public function test_generates_the_endpoint_request_shapes(): void
    {
        $sim = new BiosignalSimulator(42);
        $w = $sim->generateWorkout('run', 30, 0.6);

        // /process/activity shape
        $this->assertCount(60, $w['accel_counts']);          // 30 min / 30 s epochs
        $this->assertCount(60, $w['hr_epoch_bpm']);
        $this->assertGreaterThan(0, max($w['accel_counts']));

        // /process/fitness `run` shape — 1 Hz HR + GPS pace + baro grade, same length
        $this->assertCount(1800, $w['run']['hr']);           // 30 min × 60 s
        $this->assertCount(1800, $w['run']['speed_kmh']);
        $this->assertCount(1800, $w['run']['grade']);
        $this->assertGreaterThan(3.0, $w['distance_km']);    // a 30-min run covers real distance
    }

    public function test_hrr_is_physiological(): void
    {
        $sim = new BiosignalSimulator(7);
        $w = $sim->generateWorkout('run', 30, 0.6);
        // A real heart-rate-recovery drop in the 60 s after exercise ends.
        $this->assertGreaterThan(10, $w['run']['hrr60']);
        $this->assertLessThan(60, $w['run']['hrr60']);
    }

    public function test_fitter_athlete_runs_faster_at_lower_hr(): void
    {
        $sim = new BiosignalSimulator(3);
        $unfit = $sim->generateWorkout('run', 30, 0.2);
        $fit = $sim->generateWorkout('run', 30, 0.9);

        $this->assertGreaterThan(max($unfit['run']['speed_kmh']), max($fit['run']['speed_kmh']));
        $this->assertLessThan(max($unfit['run']['hr']), max($fit['run']['hr']));
    }
}
