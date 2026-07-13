<?php

namespace Tests\Unit;

use App\Support\Fasting;
use PHPUnit\Framework\TestCase;

/**
 * FASTING v2 · Thrust 1 — the stage timeline must TEACH the pathway and stay honest where the human
 * evidence is thin (grounded in docs/research/DAVID_SINCLAIR_LONGEVITY.md). The "Autophagy at 36h"
 * assertion is softened; benefits are never overstated. Pure (no DB).
 */
class FastingStagesTest extends TestCase
{
    public function test_stage_lookup_by_elapsed_hours(): void
    {
        $this->assertSame('Fed', Fasting::stageAt(0)['label']);
        $this->assertSame('Glycogen', Fasting::stageAt(6)['label']);
        $this->assertSame('Fat-burning', Fasting::stageAt(13)['label']);
        $this->assertSame('Ketosis onset', Fasting::stageAt(17)['label']);
        $this->assertSame('Deeper ketosis', Fasting::stageAt(30)['label']);
        $this->assertSame('Autophagy', Fasting::stageAt(40)['label']);
    }

    public function test_every_stage_teaches_a_pathway_and_a_why(): void
    {
        foreach (Fasting::STAGES as $s) {
            $this->assertNotEmpty($s['pathway'], "Stage {$s['label']} is missing its pathway.");
            $this->assertNotEmpty($s['why'], "Stage {$s['label']} is missing its 'why'.");
            $this->assertArrayHasKey('honesty', $s);   // present (may be null where the evidence is solid)
        }
    }

    public function test_autophagy_is_evidence_honest_not_a_hard_claim(): void
    {
        $autophagy = Fasting::stageAt(40);
        // The blurb hedges ("believed"), not "autophagy happens at 36h".
        $this->assertStringContainsStringIgnoringCase('believed', $autophagy['blurb']);
        // And it carries an explicit caveat that the human timing isn't established.
        $this->assertNotNull($autophagy['honesty']);
        $this->assertStringContainsStringIgnoringCase('well established', $autophagy['honesty']);
        $this->assertStringContainsStringIgnoringCase('rough marker', $autophagy['honesty']);
    }

    public function test_uncertain_stages_carry_a_caveat_solid_ones_do_not(): void
    {
        $this->assertNull(Fasting::stageAt(13)['honesty']);        // fat-burning is solid
        $this->assertNotNull(Fasting::stageAt(17)['honesty']);     // ketosis timing varies
        $this->assertNotNull(Fasting::stageAt(30)['honesty']);     // GH claim is modest
    }

    public function test_disclaimer_never_promises_lifespan_or_reversal(): void
    {
        $d = Fasting::DISCLAIMER;
        $this->assertNotEmpty($d);
        $this->assertStringContainsStringIgnoringCase('eating less', $d);
        $this->assertStringNotContainsStringIgnoringCase('reverse aging', $d);
    }
}
