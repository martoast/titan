<?php

namespace Tests\Unit;

use App\Models\ActivitySession;
use App\Support\TrainingLoad;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Tests\TestCase;

class TrainingLoadTest extends TestCase
{
    private CarbonImmutable $asOf;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asOf = CarbonImmutable::parse('2026-06-15');
    }

    /** Build sessions: one per given day offset (days BEFORE asOf) with a TRIMP value. */
    private function sessions(array $dayTrimp): Collection
    {
        $out = collect();
        foreach ($dayTrimp as $daysAgo => $trimp) {
            $out->push(new ActivitySession([
                'started_at' => $this->asOf->subDays($daysAgo)->setTime(8, 0),
                'trimp' => $trimp,
            ]));
        }
        return $out;
    }

    public function test_steady_load_sits_in_the_sweet_spot(): void
    {
        // 42 days of identical daily load → acute ≈ chronic → ACWR ≈ 1.0.
        $days = [];
        for ($d = 0; $d < 42; $d++) {
            $days[$d] = 50.0;
        }
        $r = TrainingLoad::assess($this->sessions($days), $this->asOf);

        $this->assertNotNull($r);
        $this->assertTrue($r['sufficient']);
        $this->assertEqualsWithDelta(1.0, $r['acwr'], 0.08);
        $this->assertSame('optimal', $r['band']);
    }

    public function test_load_spike_flags_high_risk(): void
    {
        // A month of modest load, then a big spike in the last week → ACWR well above 1.5.
        $days = [];
        for ($d = 7; $d < 42; $d++) {
            $days[$d] = 20.0;          // steady low base for weeks
        }
        for ($d = 0; $d < 7; $d++) {
            $days[$d] = 120.0;         // sudden hard week
        }
        $r = TrainingLoad::assess($this->sessions($days), $this->asOf);

        $this->assertNotNull($r);
        $this->assertGreaterThan(1.5, $r['acwr']);
        $this->assertSame('high', $r['band']);
    }

    public function test_tapering_reads_as_detraining(): void
    {
        // Strong base for weeks, then near-rest in the last week → ACWR below 0.8.
        $days = [];
        for ($d = 7; $d < 42; $d++) {
            $days[$d] = 80.0;
        }
        for ($d = 0; $d < 7; $d++) {
            $days[$d] = 5.0;
        }
        $r = TrainingLoad::assess($this->sessions($days), $this->asOf);

        $this->assertNotNull($r);
        $this->assertLessThan(0.8, $r['acwr']);
        $this->assertSame('detraining', $r['band']);
    }

    public function test_too_little_history_is_building_state(): void
    {
        // Only a few recent days of data → not enough baseline for a trustworthy ACWR.
        $r = TrainingLoad::assess($this->sessions([0 => 60.0, 1 => 55.0, 2 => 50.0]), $this->asOf);

        $this->assertNotNull($r);
        $this->assertFalse($r['sufficient']);
        $this->assertNull($r['acwr']);
        $this->assertSame('building', $r['band']);
    }

    public function test_no_sessions_returns_null(): void
    {
        $this->assertNull(TrainingLoad::assess(collect(), $this->asOf));
    }
}
