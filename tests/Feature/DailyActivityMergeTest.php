<?php

namespace Tests\Feature;

use App\Models\DailyActivity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Per-day step merge: the band and the phone both measure the same day, so DailyActivity::mergeDaily
 * takes the per-field MAX across sources — it can never double-count, and the band's count survives a
 * phone-free walk. (See the step-counting research: don't sum sources.)
 */
class DailyActivityMergeTest extends TestCase
{
    use RefreshDatabase;

    public function test_max_per_day_across_sources_never_double_counts(): void
    {
        $profile = User::factory()->create()->ensureProfile();
        $date = '2026-06-24';

        // Phone syncs 5000 first.
        DailyActivity::mergeDaily($profile->id, $date, ['steps' => 5000], ['source' => 'apple_health']);
        // Then the band reports 8000 — a walk taken with the phone left behind. Band wins (max).
        DailyActivity::mergeDaily($profile->id, $date, ['steps' => 8000, 'floors' => 4], ['source' => 'titan_band']);
        // The phone re-syncs a still-stale 3000. Must NOT clobber down, and must NOT sum to 11000/13000.
        DailyActivity::mergeDaily($profile->id, $date, ['steps' => 3000], ['source' => 'apple_health']);

        $this->assertSame(1, DailyActivity::where('profile_id', $profile->id)->whereDate('date', $date)->count());
        $row = DailyActivity::where('profile_id', $profile->id)->whereDate('date', $date)->first();
        $this->assertSame(8000, $row->steps);        // the larger source, not the sum, not clobbered down
        $this->assertSame(4, $row->floors);          // other cumulative fields merge the same way
    }

    public function test_later_higher_total_from_same_source_grows(): void
    {
        $profile = User::factory()->create()->ensureProfile();
        $date = '2026-06-24';

        DailyActivity::mergeDaily($profile->id, $date, ['steps' => 4000], ['source' => 'apple_health']);
        DailyActivity::mergeDaily($profile->id, $date, ['steps' => 9000], ['source' => 'apple_health']); // day grew

        $this->assertSame(9000, DailyActivity::where('profile_id', $profile->id)->value('steps'));
    }

    public function test_manual_set_still_overwrites(): void
    {
        // A deliberate manual/coach set is a correction and must win, even downward — it does NOT go
        // through mergeDaily (the FitnessController / coach tool use updateOrCreate). Guard that contract.
        $profile = User::factory()->create()->ensureProfile();
        $date = '2026-06-24';

        DailyActivity::mergeDaily($profile->id, $date, ['steps' => 8000], ['source' => 'titan_band']);
        $profile->dailyActivity()->updateOrCreate(
            ['date' => $date],
            ['steps' => 2000, 'source' => 'manual', 'updated_via' => 'manual'],
        );

        $this->assertSame(2000, DailyActivity::where('profile_id', $profile->id)->value('steps'));
    }
}
