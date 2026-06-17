<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Coach\CoachTools;
use App\Services\Coach\ToolDocs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ToolDocsTest extends TestCase
{
    use RefreshDatabase;

    public function test_tool_docs_tool_is_registered_and_returns_the_full_manual(): void
    {
        $p = User::factory()->create()->ensureProfile();
        $tools = new CoachTools($p);

        $names = array_map(fn ($t) => $t['function']['name'], $tools->schemas());
        $this->assertContains('tool_docs', $names);

        $res = $tools->dispatch('tool_docs', ['tool' => 'log_set']);
        $this->assertStringContainsString('TOTAL load', $res['docs']);   // the plate-math caveat is recoverable
    }

    public function test_unknown_tool_gets_a_sensible_fallback(): void
    {
        $this->assertStringContainsString('No extended docs', ToolDocs::get('nonexistent_tool'));
    }

    public function test_descriptions_stay_compact(): void
    {
        // Guard against re-bloat: every tool description should be a tight one-liner.
        $p = User::factory()->create()->ensureProfile();
        foreach ((new CoachTools($p))->withAllTools()->schemas() as $s) {
            $len = strlen($s['function']['description']);
            $this->assertLessThanOrEqual(260, $len, "{$s['function']['name']} description is {$len} chars — keep it terse, move detail to ToolDocs");
        }
    }
}
