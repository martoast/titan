<?php

namespace App\Services\Community;

use App\Models\Profile;

/**
 * The "your week vs the group" summary — drives the recap card in-app and the Sunday push.
 * Built from the effort leaderboard so the number on the card matches the board exactly.
 */
class WeeklyRecap
{
    public function __construct(private LeaderboardService $boards) {}

    public function forProfile(Profile $profile): array
    {
        $board = $this->boards->build($profile, 'effort', 'week', 200);
        $you = $board['you'];

        $weekSessions = $profile->activitySessions()->where('started_at', '>=', now()->startOfWeek())->get();
        $top = $board['athletes'][0] ?? null;

        return [
            'window' => 'week',
            'starts_on' => now()->startOfWeek()->toDateString(),
            'your_activities' => $weekSessions->count(),
            'your_distance_km' => round((float) $weekSessions->sum('distance_km'), 1),
            'your_effort' => (int) $weekSessions->sum('relative_effort'),
            'your_rank' => $you['rank'] ?? null,
            'group_size' => count($board['athletes']),
            'top_performer' => $top ? ['name' => $top['name'], 'value' => $top['value'], 'is_you' => $top['is_you']] : null,
        ];
    }
}
