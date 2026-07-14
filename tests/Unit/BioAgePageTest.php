<?php

namespace Tests\Unit;

use App\Support\BioAgePage;
use PHPUnit\Framework\TestCase;

/**
 * The Bio Age page's presentation enrichment (BIO_AGE_PAGE) — per-component methodology + data-driven
 * honest tips. Pure (the full forProfile is DB-backed; Henry field-tests it on Alex's real data).
 */
class BioAgePageTest extends TestCase
{
    public function test_component_gets_unit_and_how_and_context(): void
    {
        $c = BioAgePage::enrichComponent(['key' => 'hrv', 'label' => 'HRV (RMSSD)', 'kind' => 'lever', 'value' => 65.0, 'years' => -3.0, 'note' => 'HRV 65 ms']);
        $this->assertSame('ms', $c['unit']);
        $this->assertStringContainsStringIgnoringCase('HRV', $c['how']);
        $this->assertSame('HRV 65 ms', $c['context']);   // note carried through
        $this->assertSame(-3.0, $c['years']);            // contribution untouched
    }

    public function test_value_is_rounded_to_one_dp(): void
    {
        // VO₂max came through as an unrounded float — round it for clean display (review 1020d59).
        $c = BioAgePage::enrichComponent(['key' => 'fitness', 'label' => 'Cardio fitness (VO₂max)', 'value' => 29.22222222, 'years' => -1.0]);
        $this->assertSame(29.2, $c['value']);
    }

    public function test_unknown_component_is_tolerated(): void
    {
        $c = BioAgePage::enrichComponent(['key' => 'mystery', 'label' => 'X', 'value' => 1, 'years' => 0]);
        $this->assertSame('', $c['unit']);
        $this->assertArrayNotHasKey('how', $c);
    }

    public function test_tips_protect_youth_drivers_and_improve_agers(): void
    {
        $tips = BioAgePage::tipsFor([
            'younger_levers' => [['label' => 'HRV (RMSSD)', 'years' => -3.0]],
            'older_levers' => [['label' => 'Resting HR', 'years' => 1.5]],
        ]);
        $protect = collect($tips)->firstWhere('kind', 'protect');
        $improve = collect($tips)->firstWhere('kind', 'improve');
        $this->assertNotNull($protect);
        $this->assertStringContainsStringIgnoringCase('HRV', $protect['action']);
        $this->assertNotNull($improve);
        $this->assertStringContainsStringIgnoringCase('zone-2', $improve['action']);
    }

    public function test_no_older_levers_gets_a_celebrate_tip(): void
    {
        $tips = BioAgePage::tipsFor(['younger_levers' => [['label' => 'HRV (RMSSD)', 'years' => -3.0]], 'older_levers' => []]);
        $this->assertNotNull(collect($tips)->firstWhere('kind', 'celebrate'));
    }

    public function test_tips_and_methodology_never_promise_reversal(): void
    {
        // Honesty moat — the copy is healthspan levers, not "reverse aging".
        $all = BioAgePage::methodology().' '.json_encode(BioAgePage::tipsFor([
            'younger_levers' => [['label' => 'HRV', 'years' => -3]],
            'older_levers' => [['label' => 'Resting HR', 'years' => 2]],
        ]));
        $this->assertStringNotContainsStringIgnoringCase('reverse aging', $all);
        $this->assertStringNotContainsStringIgnoringCase('years back', $all);
        $this->assertStringContainsStringIgnoringCase('wellness estimate', BioAgePage::methodology());
    }
}
