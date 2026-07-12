<?php

namespace Tests\Feature;

use App\Models\SleepLog;
use App\Support\SleepStory;
use Tests\TestCase;

/**
 * "The story of your night" — derived from the hypnogram + metrics. Pure (no DB): builds a SleepLog in
 * memory with a crafted hypnogram and checks the structured bits + the honesty framing.
 */
class SleepStoryTest extends TestCase
{
    /** @param array<int,array{0:string,1:int}> $runs stage → epoch count */
    private function hyp(array $runs): array
    {
        $out = [];
        foreach ($runs as [$stage, $n]) {
            $out = array_merge($out, array_fill(0, $n, $stage));
        }

        return $out;
    }

    private function night(array $hyp, bool $low = false): SleepLog
    {
        return new SleepLog(['hypnogram' => $hyp, 'bedtime' => '23:00:00', 'low_confidence' => $low]);
    }

    public function test_narrates_a_front_loaded_night(): void
    {
        // 2 min onset, front-loaded deep, one 5-min wake mid-night, 3 REM runs.
        $hyp = $this->hyp([
            ['wake', 4], ['deep', 30], ['light', 30], ['rem', 20], ['light', 30],
            ['wake', 10], ['light', 20], ['rem', 20], ['light', 20], ['deep', 8], ['rem', 12], ['wake', 4],
        ]);
        $story = SleepStory::forNight($this->night($hyp));

        $this->assertNotNull($story);
        $this->assertSame(2, $story['onset_min']);
        $this->assertSame('front', $story['deep_distribution']);
        $this->assertSame(3, $story['rem_cycles']);
        $this->assertCount(1, $story['awakenings']);
        $this->assertSame(5, $story['awakenings'][0]['min']);
        $this->assertStringContainsStringIgnoringCase('REM', $story['text']);
        $this->assertNotEmpty($story['takeaway']);
        $this->assertFalse($story['low_confidence']);
    }

    public function test_low_confidence_night_reads_as_an_estimate_and_stays_qualitative(): void
    {
        $hyp = $this->hyp([['wake', 4], ['deep', 30], ['light', 40], ['rem', 20], ['light', 40], ['wake', 6]]);
        $story = SleepStory::forNight($this->night($hyp, low: true));

        $this->assertTrue($story['low_confidence']);
        $this->assertStringContainsStringIgnoringCase('estimate', $story['text']);
        $this->assertStringContainsStringIgnoringCase('fit', $story['takeaway']);
        // No precise deep-distribution framing on a thin night.
        $this->assertStringNotContainsStringIgnoringCase('deep sleep came', $story['text']);
    }

    public function test_back_loaded_deep_is_the_soft_spot(): void
    {
        // Deep concentrated in the LAST third → back-loaded → the takeaway flags it.
        $hyp = $this->hyp([
            ['wake', 4], ['light', 60], ['rem', 20], ['light', 40], ['rem', 20], ['light', 20], ['deep', 40], ['wake', 4],
        ]);
        $story = SleepStory::forNight($this->night($hyp));

        $this->assertSame('back', $story['deep_distribution']);
        $this->assertStringContainsStringIgnoringCase('deep sleep skewed late', $story['takeaway']);
    }

    public function test_no_hypnogram_returns_null(): void
    {
        $this->assertNull(SleepStory::forNight($this->night([])));
        $this->assertNull(SleepStory::forNight($this->night(array_fill(0, 20, 'wake'))));   // never asleep
    }
}
