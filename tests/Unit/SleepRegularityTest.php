<?php

namespace Tests\Unit;

use App\Models\SleepLog;
use App\Support\SleepRegularity;
use Illuminate\Support\Collection;
use Tests\TestCase;

class SleepRegularityTest extends TestCase
{
    /** @param array<int,array{0:string,1:string,2:string}> $nights [slept_at, bedtime, wake_time] */
    private function logs(array $nights): Collection
    {
        return collect($nights)->map(fn ($n) => new SleepLog([
            'slept_at' => $n[0], 'bedtime' => $n[1], 'wake_time' => $n[2],
        ]));
    }

    public function test_identical_schedule_scores_near_100(): void
    {
        // 8 consecutive nights, all 23:00 → 07:00 — perfectly regular.
        $nights = [];
        for ($d = 1; $d <= 8; $d++) {
            $nights[] = [sprintf('2026-06-%02d', $d), '23:00', '07:00'];
        }
        $r = SleepRegularity::compute($this->logs($nights));

        $this->assertNotNull($r);
        $this->assertGreaterThanOrEqual(95, $r['sri']);
        $this->assertSame('excellent', $r['band']);
        $this->assertSame(8, $r['nights']);
    }

    public function test_alternating_schedule_scores_lower(): void
    {
        // Schedule swings ~4 h every other night → much less regular than the identical case.
        $nights = [];
        for ($d = 1; $d <= 8; $d++) {
            $nights[] = $d % 2
                ? [sprintf('2026-06-%02d', $d), '22:00', '06:00']
                : [sprintf('2026-06-%02d', $d), '02:00', '10:00'];
        }
        $r = SleepRegularity::compute($this->logs($nights));

        $this->assertNotNull($r);
        $this->assertLessThan(75, $r['sri']);              // clearly worse than the regular sleeper
        $regular = SleepRegularity::compute($this->logs(array_map(
            fn ($d) => [sprintf('2026-06-%02d', $d), '23:00', '07:00'], range(1, 8)
        )));
        $this->assertGreaterThan($r['sri'], $regular['sri']);
    }

    public function test_returns_null_below_minimum_nights(): void
    {
        $r = SleepRegularity::compute($this->logs([
            ['2026-06-01', '23:00', '07:00'],
            ['2026-06-02', '23:00', '07:00'],
        ]));
        $this->assertNull($r);
    }

    public function test_ignores_nights_without_timing(): void
    {
        // Only 3 nights have bed/wake times → below the minimum → null.
        $logs = $this->logs([
            ['2026-06-01', '23:00', '07:00'],
            ['2026-06-02', '23:00', '07:00'],
            ['2026-06-03', '23:00', '07:00'],
        ])->push(new SleepLog(['slept_at' => '2026-06-04', 'duration_min' => 420]))
          ->push(new SleepLog(['slept_at' => '2026-06-05', 'duration_min' => 420]));

        $this->assertNull(SleepRegularity::compute($logs));
    }
}
