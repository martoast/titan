<?php

namespace Tests\Unit;

use App\Models\DailyActivity;
use App\Support\CircadianRhythm;
use Illuminate\Support\Collection;
use Tests\TestCase;

class CircadianRhythmTest extends TestCase
{
    /** @param array<int,int> $hourly */
    private function days(array $hourly, int $count = 8): Collection
    {
        $out = collect();
        for ($d = 1; $d <= $count; $d++) {
            $out->push(new DailyActivity([
                'date' => sprintf('2026-06-%02d', $d),
                'hourly' => $hourly,
            ]));
        }

        return $out;
    }

    /** Strong day (active 8am-10pm), still nights → high RA, high IS, low IV. */
    private function strongProfile(): array
    {
        $h = [];
        for ($i = 0; $i < 24; $i++) {
            $h[] = ($i >= 8 && $i <= 21) ? 80 : 2;
        }

        return $h;
    }

    public function test_strong_rhythm_scores_high(): void
    {
        $r = CircadianRhythm::compute($this->days($this->strongProfile()));

        $this->assertNotNull($r);
        $this->assertGreaterThanOrEqual(85, $r['ra']);          // big day/night contrast
        $this->assertGreaterThan(0.9, $r['is']);                // identical days → very stable
        $this->assertSame('excellent', $r['band']);
        $this->assertSame(8, $r['days']);
        // Most-active block starts in the morning; deep-rest block overnight.
        $this->assertGreaterThanOrEqual(6, $r['m10_onset']);
        $this->assertTrue($r['l5_onset'] >= 22 || $r['l5_onset'] <= 3);
    }

    public function test_flat_profile_scores_low_ra(): void
    {
        // Nearly constant activity all day → almost no day/night contrast.
        $flat = array_fill(0, 24, 50);
        $flat[12] = 52; // a hair of variance so it isn't undefined
        $r = CircadianRhythm::compute($this->days($flat));

        $this->assertNotNull($r);
        $this->assertLessThan(20, $r['ra']);
        $strong = CircadianRhythm::compute($this->days($this->strongProfile()));
        $this->assertGreaterThan($r['ra'], $strong['ra']);
    }

    public function test_fragmented_day_has_higher_iv(): void
    {
        $smooth = $this->strongProfile();
        $choppy = [];
        for ($i = 0; $i < 24; $i++) {
            $choppy[] = ($i % 2 === 0) ? 90 : 5;   // alternating hour-to-hour = fragmented
        }

        $ivSmooth = CircadianRhythm::compute($this->days($smooth))['iv'];
        $ivChoppy = CircadianRhythm::compute($this->days($choppy))['iv'];
        $this->assertGreaterThan($ivSmooth, $ivChoppy);
    }

    public function test_returns_null_without_enough_profiled_days(): void
    {
        $this->assertNull(CircadianRhythm::compute($this->days($this->strongProfile(), 3)));
        // Days lacking an hourly profile don't count.
        $noHourly = collect([
            new DailyActivity(['date' => '2026-06-01', 'steps' => 8000]),
            new DailyActivity(['date' => '2026-06-02', 'steps' => 8000]),
        ]);
        $this->assertNull(CircadianRhythm::compute($noHourly));
    }
}
