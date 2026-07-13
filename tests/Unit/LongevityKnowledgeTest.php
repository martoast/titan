<?php

namespace Tests\Unit;

use App\Support\LongevityKnowledge;
use PHPUnit\Framework\TestCase;

/**
 * The coach's longevity knowledge pack (FASTING_EVIDENCE T3) is the honest source of truth. It must carry
 * the non-negotiable guardrails from the research doc and never overstate the science. Pure (no DB).
 */
class LongevityKnowledgeTest extends TestCase
{
    public function test_guardrails_carry_the_hard_rules(): void
    {
        $g = LongevityKnowledge::coachGuardrails();
        // The protein age-flip (hard rule) with the number.
        $this->assertStringContainsString('1.2-1.5 g/kg', $g);
        $this->assertStringContainsStringIgnoringCase('more protein', $g);
        // Fasting framed honestly (mostly eating less, not lifespan).
        $this->assertStringContainsStringIgnoringCase('eating fewer calories', $g);
        // Supplements are animal-not-human.
        $this->assertStringContainsStringIgnoringCase('unproven in humans', $g);
    }

    public function test_guardrails_never_overstate(): void
    {
        $g = LongevityKnowledge::coachGuardrails();
        // The forbidden claim appears only inside a "never say" instruction, never as an assertion — check
        // the doc explicitly forbids it.
        $this->assertStringContainsStringIgnoringCase('never say "reverse aging', $g);
        // And it must not promise human lifespan extension.
        $this->assertStringContainsStringIgnoringCase('not proven to extend human lifespan', $g);
    }

    public function test_pack_teaches_the_pathways_and_stays_calibrated(): void
    {
        $p = LongevityKnowledge::pack();
        foreach (['mTOR', 'AMPK', 'autophagy', 'sirtuin'] as $pathway) {
            $this->assertStringContainsStringIgnoringCase($pathway, $p, "pack should teach $pathway");
        }
        // Calibration: no molecule has a proven human benefit; resveratrol failed.
        $this->assertStringContainsStringIgnoringCase('no molecule', $p);
        $this->assertStringContainsStringIgnoringCase('resveratrol failed in humans', $p);
        // Points the coach at a lesson card.
        $this->assertStringContainsString('lesson', $p);
    }
}
