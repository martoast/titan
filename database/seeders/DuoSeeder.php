<?php

namespace Database\Seeders;

use App\Models\Streak;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeds a couple of streaks for each brother (profiles 1 & 2) so the duo
 * dashboard has something to race with out of the box. The orchestrator calls
 * this; it does NOT touch DatabaseSeeder.
 */
class DuoSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $today = Carbon::today();

        $rows = [
            // Alex (profile 1) — on a tear.
            ['profile_id' => 1, 'kind' => 'overall',       'current_count' => 12, 'longest_count' => 21, 'freezes_available' => 2],
            ['profile_id' => 1, 'kind' => 'workouts',      'current_count' => 5,  'longest_count' => 9,  'freezes_available' => 2],
            ['profile_id' => 1, 'kind' => 'meals_logged',  'current_count' => 8,  'longest_count' => 14, 'freezes_available' => 1],

            // Bro (profile 2) — chasing.
            ['profile_id' => 2, 'kind' => 'overall',       'current_count' => 9,  'longest_count' => 16, 'freezes_available' => 2],
            ['profile_id' => 2, 'kind' => 'workouts',      'current_count' => 7,  'longest_count' => 7,  'freezes_available' => 2],
            ['profile_id' => 2, 'kind' => 'meals_logged',  'current_count' => 3,  'longest_count' => 11, 'freezes_available' => 2],
        ];

        foreach ($rows as $row) {
            Streak::updateOrCreate(
                ['profile_id' => $row['profile_id'], 'kind' => $row['kind']],
                array_merge($row, ['last_active_on' => $today]),
            );
        }
    }
}
