<?php

namespace Tests\Feature;

use App\Models\WearableConnection;
use App\Services\Lab\VirtualAthlete;
use App\Services\Lab\WorkoutCalibration;
use App\Services\Lab\WorkoutScript;
use App\Services\Simulator\BiosignalSimulator;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * WORKOUT LAB · the deterministic subset (spec §6.1, sibling of {@see SleepLabTest}). These pin the pure,
 * HTTP-free core — WorkoutScript expectation-derivation + VirtualAthlete wire rendering — so CI catches a
 * drift in the ground-truth math or the workout wire contract without needing the live biosignal service
 * (the full `workout:lab` E2E owns that).
 */
class WorkoutLabTest extends TestCase
{
    private function athlete(int $seed = 1, ?WorkoutCalibration $cal = null): VirtualAthlete
    {
        // An unsaved connection is enough: renderWorkout/wireWindow never touch the DB (only post() does).
        $device = new WearableConnection(['device_id' => 'tb_wtest', 'timezone' => 'UTC', 'profile_id' => 1]);

        return new VirtualAthlete(new BiosignalSimulator($seed), $device, 'secret', $cal ?? $this->calibration());
    }

    private function calibration(): WorkoutCalibration
    {
        return new WorkoutCalibration(WorkoutCalibration::defaults());
    }

    public function test_steady_run_script_derives_its_own_expected_outcome(): void
    {
        $start = CarbonImmutable::parse('2026-07-10T14:30:00-06:00');
        $script = WorkoutScript::steadyRun('America/Mexico_City', $this->calibration(), $start);
        $exp = $script->expected();

        $this->assertSame('run', $exp['activity_type']);
        $this->assertTrue($exp['is_run']);
        $this->assertTrue($exp['has_route']);
        $this->assertSame(1, $exp['session_count']);
        $this->assertSame(0, $exp['set_count']);
        // Distance = elapsed / pace; avg/max HR are the block-weighted mean / peak.
        $this->assertEqualsWithDelta($script->totalSeconds() / 330.0, $exp['distance_km'], 0.01);
        $this->assertSame(330, $exp['avg_pace_s_per_km']);
        $this->assertGreaterThan($exp['avg_hr'], $exp['max_hr']);
        // The local wake/finish date (the streak/reader bucket).
        $this->assertSame($start->setTimezone('America/Mexico_City')->toDateString(), $exp['date']);
    }

    public function test_gym_lift_script_is_indoor_strength_with_logged_sets(): void
    {
        $script = WorkoutScript::gymLift('UTC', $this->calibration(), CarbonImmutable::parse('2026-07-10T18:00:00Z'));
        $exp = $script->expected();

        $this->assertSame('strength', $exp['activity_type']);
        $this->assertFalse($exp['is_run']);
        $this->assertFalse($exp['has_route']);
        $this->assertSame(5, $exp['set_count']);          // 3 squat + 2 bench
        $this->assertArrayNotHasKey('distance_km', $exp); // indoor: no route metrics
    }

    public function test_workout_window_matches_the_ingest_contract(): void
    {
        $win = VirtualAthlete::workoutWindow(
            '2026-07-10T14:00:00Z', '2026-07-10T14:03:00Z',
            ['x' => [1, 2], 'y' => [3, 4], 'z' => [5, 6]], 25, 'mg', [70], [150.0],
            ['speed_kmh' => [11.0], 'grade' => [0.0], 'track' => [['t' => 1, 'lat' => 1.0, 'lon' => 2.0]]],
            'simulator', 'run',
        );

        $this->assertSame('workout', $win['kind']);
        $this->assertSame(25, $win['accel_fs']);
        $this->assertSame('mg', $win['accel_unit']);
        $this->assertArrayHasKey('accel_xyz', $win);
        $this->assertArrayHasKey('hr_bpm', $win);
        $this->assertArrayHasKey('track', $win['gps']);
        $this->assertSame('run', $win['activity_kind']);   // the watch's kind hint rides on the window
        $this->assertStringEndsWith('Z', $win['start']);

        // A hint-absent, indoor window omits the optional fields rather than nulling them.
        $indoor = VirtualAthlete::workoutWindow('2026-07-10T14:00:00Z', '2026-07-10T14:03:00Z', null, 0, 'mg', [10], [96.0], null);
        $this->assertArrayNotHasKey('gps', $indoor);
        $this->assertArrayNotHasKey('accel_xyz', $indoor);
        $this->assertArrayNotHasKey('activity_kind', $indoor);
    }

    public function test_render_workout_places_contiguous_windows_covering_the_run(): void
    {
        $script = WorkoutScript::steadyRun('UTC', $this->calibration(), CarbonImmutable::parse('2026-07-10T15:00:00Z'));
        $render = $this->athlete()->renderWorkout($script);

        // A 28-min run at a 180s window cadence ≈ 10 windows, all kind=workout, all carrying a GPS track.
        $this->assertGreaterThanOrEqual(9, count($render['live']));
        foreach ($render['live'] as $w) {
            $this->assertSame('workout', $w['kind']);
            $this->assertNotEmpty($w['gps']['track']);
            $this->assertSame('run', $w['activity_kind']);
        }
        // Windows are contiguous + ordered: each start == the previous end.
        for ($i = 1; $i < count($render['live']); $i++) {
            $this->assertSame($render['live'][$i - 1]['end'], $render['live'][$i]['start']);
        }
        // No raw PPG unless the script asks for it (golden paths keep HR on-chip).
        $this->assertSame([], $render['ppg']);
    }

    public function test_render_workout_gym_lift_is_indoor(): void
    {
        $script = WorkoutScript::gymLift('UTC', $this->calibration(), CarbonImmutable::parse('2026-07-10T18:00:00Z'));
        $render = $this->athlete()->renderWorkout($script);

        $this->assertNotEmpty($render['live']);
        foreach ($render['live'] as $w) {
            $this->assertArrayNotHasKey('gps', $w);           // a lift never fabricates GPS
            $this->assertSame('strength', $w['activity_kind']);
        }
    }

    public function test_render_workout_is_deterministic_by_seed(): void
    {
        $script = fn () => WorkoutScript::steadyRun('UTC', $this->calibration(), CarbonImmutable::parse('2026-07-10T15:00:00Z'));

        $a = $this->athlete(seed: 7)->renderWorkout($script())['live'];
        $b = $this->athlete(seed: 7)->renderWorkout($script())['live'];

        $this->assertSame($a[0]['hr_bpm'], $b[0]['hr_bpm'], 'same seed must render an identical workout');
        $this->assertSame($a[0]['accel_xyz'], $b[0]['accel_xyz']);
    }
}
