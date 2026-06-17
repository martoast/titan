<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Coach\CoachTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CoachBioAgeCardTest extends TestCase
{
    use RefreshDatabase;

    public function test_biological_age_returns_a_ready_made_bioage_card(): void
    {
        $p = User::factory()->create()->ensureProfile();
        // A 45-year-old with an excellent VO2max → biologically younger.
        $p->update(['birthdate' => Carbon::today()->subYears(45)->toDateString(), 'sex' => 'M']);
        $p->activitySessions()->create([
            'started_at' => Carbon::now()->subDay(), 'source' => 'titan_band', 'vo2max' => 52,
        ]);

        $res = (new CoachTools($p->refresh()))->dispatch('biological_age', []);

        $this->assertArrayHasKey('card', $res);
        $this->assertSame('bioage', $res['card']['type']);
        $this->assertEqualsWithDelta(45.0, (float) $res['card']['chrono_age'], 0.6);
        $this->assertLessThan($res['card']['chrono_age'], $res['card']['bio_age']);   // younger
        $this->assertNotEmpty($res['card']['drivers']);
        $this->assertStringContainsString('titan-card', $res['_show']);
    }

    public function test_biological_age_guides_when_no_anchor(): void
    {
        $p = User::factory()->create()->ensureProfile();
        $p->update(['birthdate' => Carbon::today()->subYears(30)->toDateString()]);

        $res = (new CoachTools($p->refresh()))->dispatch('biological_age', []);
        $this->assertArrayHasKey('note', $res);   // no bloodwork/VO2/wearable → helpful guidance, not a crash
    }

    public function test_the_tool_is_registered(): void
    {
        $names = array_map(fn ($t) => $t['function']['name'], (new CoachTools(User::factory()->create()->ensureProfile()))->schemas());
        $this->assertContains('biological_age', $names);
    }
}
