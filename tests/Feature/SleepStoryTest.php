<?php

namespace Tests\Feature;

use App\Models\SleepLog;
use App\Support\SleepStory;
use Tests\TestCase;

/**
 * "The story of your night" — must judge SUFFICIENCY (duration vs need) + stage ADEQUACY, not just
 * architecture, so it agrees with the debt ledger + Sleep Week (review fefbd4a). Pure (no DB).
 */
class SleepStoryTest extends TestCase
{
    /** @param array<int,array{0:string,1:int}> $runs */
    private function hyp(array $runs): array
    {
        $out = [];
        foreach ($runs as [$stage, $n]) {
            $out = array_merge($out, array_fill(0, $n, $stage));
        }

        return $out;
    }

    private function night(array $hyp, int $deep, int $rem, int $light, bool $low = false, bool $stagesLow = false): SleepLog
    {
        return new SleepLog([
            'hypnogram' => $hyp, 'bedtime' => '23:00:00', 'low_confidence' => $low,
            'stages_low_confidence' => $stagesLow,
            'deep_min' => $deep, 'rem_min' => $rem, 'light_min' => $light,
        ]);
    }

    public function test_an_unreadable_stage_split_withholds_stage_claims_but_keeps_the_duration(): void
    {
        // Tester B 2026-08-04 shape: a long, well-measured night whose split is an artifact (40% deep in one
        // block). The story must still speak to the hours — that part is real — and must NOT narrate deep
        // distribution, REM periods, or land on the "well-built night" win.
        $hyp = $this->hyp([['wake', 4], ['deep', 239], ['light', 300], ['rem', 20], ['light', 300]]);
        $story = SleepStory::forNight($this->night($hyp, deep: 219, rem: 37, light: 296, stagesLow: true), 8.0);

        $this->assertTrue($story['stages_low_confidence']);
        $this->assertStringContainsStringIgnoringCase('stage', $story['takeaway']);
        $this->assertStringNotContainsStringIgnoringCase('well-built', $story['takeaway']);
        $this->assertStringNotContainsStringIgnoringCase('solid deep and REM', $story['takeaway']);
        // No stage-derived claims anywhere in the narrative.
        $this->assertStringNotContainsStringIgnoringCase('deep sleep came', $story['text']);
        $this->assertStringNotContainsStringIgnoringCase('REM period', $story['text']);
        // ...but the duration IS stated, because it's trustworthy.
        $this->assertStringContainsString((string) $story['asleep_h'], $story['text']);
        // And it must NOT be confused with a thin-signal night — no band-fit advice.
        $this->assertStringNotContainsStringIgnoringCase('band fit', $story['takeaway']);
    }

    public function test_a_thin_signal_night_still_leads_with_the_fit_check(): void
    {
        // low_confidence (the sensor problem) keeps its own, different framing — the split's own flag must
        // not have displaced it.
        $hyp = $this->hyp([['wake', 4], ['deep', 30], ['light', 200], ['rem', 20]]);
        $story = SleepStory::forNight($this->night($hyp, deep: 15, rem: 10, light: 100, low: true), 8.0);

        $this->assertStringContainsStringIgnoringCase('band fit', $story['takeaway']);
    }

    public function test_a_short_but_well_built_night_reads_as_SHORT_not_textbook(): void
    {
        // Alex's real 07-12: 5.6h asleep, front-loaded, good stages — but well under an 8h need.
        $hyp = $this->hyp([['wake', 4], ['deep', 30], ['light', 60], ['rem', 20], ['light', 60], ['rem', 20], ['light', 40]]);
        $story = SleepStory::forNight($this->night($hyp, deep: 77, rem: 71, light: 185), 8.0);

        $this->assertSame(5.6, $story['asleep_h']);
        $this->assertSame(2.4, $story['short_by_h']);
        // The takeaway must name the shortfall + tie it to debt — NOT celebrate.
        $this->assertStringContainsStringIgnoringCase('5.6h', $story['takeaway']);
        $this->assertStringContainsStringIgnoringCase('debt', $story['takeaway']);
        $this->assertStringNotContainsStringIgnoringCase('textbook', $story['takeaway']);
        // Uses the BASELINE need (8h) passed in — never a debt-inflated ~10h (review c683a67).
        $this->assertStringContainsString('8h need', $story['takeaway']);
        $this->assertStringNotContainsString('10h', $story['takeaway']);
        $this->assertStringContainsString('5.6h', $story['text']);
    }

    public function test_deep_deficient_night_flags_the_deep_not_a_win(): void
    {
        // Enough hours (~7.9h) but only 27 min deep (< 11%) — a real gap, must not read "solid".
        $hyp = $this->hyp([['wake', 4], ['light', 120], ['deep', 20], ['light', 200], ['rem', 60], ['light', 100]]);
        $story = SleepStory::forNight($this->night($hyp, deep: 27, rem: 100, light: 350), 8.0);

        $this->assertNull($story['short_by_h']);   // not short
        $this->assertStringContainsStringIgnoringCase('deep sleep came up light', $story['takeaway']);
        $this->assertStringContainsString('27 min', $story['takeaway']);
    }

    public function test_rem_periods_are_capped_to_physiology(): void
    {
        // Six REM runs but only ~4.5h asleep → capped at floor(270/80)=3 REM periods, not 6.
        $hyp = $this->hyp([
            ['wake', 4], ['light', 30], ['rem', 8], ['light', 30], ['rem', 8], ['light', 30], ['rem', 8],
            ['light', 30], ['rem', 8], ['light', 30], ['rem', 8], ['light', 30], ['rem', 8], ['light', 30],
        ]);
        $story = SleepStory::forNight($this->night($hyp, deep: 60, rem: 48, light: 180), 8.0);
        $this->assertLessThanOrEqual(3, $story['rem_periods']);
        // The timeline draws markers from cycle_boundaries — its count MUST equal the narrated REM-period
        // count (review 04e14c5: the graph and the sentence must agree), and the kept set is the earliest.
        $this->assertCount($story['rem_periods'], $story['cycle_boundaries']);
        $this->assertSame([42, 80, 118], $story['cycle_boundaries']);
    }

    public function test_cycle_boundaries_match_rem_count_and_filter_microREM(): void
    {
        // Two real REM runs (≥3 min) with a 1-min micro-REM between them that must NOT become a boundary.
        $hyp = $this->hyp([
            ['wake', 4], ['deep', 30], ['light', 40], ['rem', 12], ['light', 40],
            ['rem', 2],  // micro-REM (1 min) — filtered, no boundary
            ['light', 40], ['rem', 10], ['light', 30],
        ]);
        $story = SleepStory::forNight($this->night($hyp, deep: 90, rem: 90, light: 180), 8.0);

        $this->assertSame(2, $story['rem_periods']);
        $this->assertSame([86, 178], $story['cycle_boundaries']);   // the two real runs' ends, micro excluded
        $this->assertCount($story['rem_periods'], $story['cycle_boundaries']);
    }

    public function test_a_full_well_built_night_is_the_win(): void
    {
        $hyp = $this->hyp([['wake', 4], ['deep', 40], ['light', 80], ['rem', 30], ['light', 80], ['rem', 30], ['light', 60], ['deep', 10]]);
        $story = SleepStory::forNight($this->night($hyp, deep: 95, rem: 105, light: 280), 8.0);   // 8h, adequate

        $this->assertNull($story['short_by_h']);
        $this->assertStringContainsStringIgnoringCase('well-built', $story['takeaway']);
    }

    public function test_low_confidence_stays_an_estimate(): void
    {
        $hyp = $this->hyp([['wake', 4], ['deep', 30], ['light', 60], ['rem', 20], ['light', 40], ['wake', 6]]);
        $story = SleepStory::forNight($this->night($hyp, deep: 40, rem: 30, light: 90, low: true), 8.0);

        $this->assertStringContainsStringIgnoringCase('estimate', $story['text']);
        $this->assertStringContainsStringIgnoringCase('fit', $story['takeaway']);
    }

    public function test_no_hypnogram_returns_null(): void
    {
        $this->assertNull(SleepStory::forNight($this->night([], 0, 0, 0), 8.0));
    }
}
