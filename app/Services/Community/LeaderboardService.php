<?php

namespace App\Services\Community;

use App\Models\Profile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Ranks the viewer + the people they follow over a time window. The leaderboard scope is
 * intentionally social-graph-bounded (not global): you compete with your circle.
 *
 * Metrics:
 *   effort   — Σ relative_effort (HR-zone-weighted load). The headline: rewards how hard you
 *              worked, fair across fitness levels.
 *   distance — Σ distance_km.
 *   points   — a blended game score: distance·10 + elevation·0.1 + effort + 5/activity.
 *
 * Windows: week (since Monday) · month (since the 1st) · all.
 */
class LeaderboardService
{
    public const METRICS = ['effort', 'distance', 'points'];

    public const WINDOWS = ['week', 'month', 'all'];

    public function build(Profile $viewer, string $metric = 'effort', string $window = 'week', int $limit = 100): array
    {
        $metric = in_array($metric, self::METRICS, true) ? $metric : 'effort';
        $window = in_array($window, self::WINDOWS, true) ? $window : 'week';

        $scopeIds = array_values(array_unique(array_merge([$viewer->id], $viewer->acceptedFollowingIds())));
        $since = $this->since($window);

        $valueExpr = match ($metric) {
            'distance' => 'COALESCE(SUM(a.distance_km), 0)',
            'points' => 'COALESCE(SUM(a.distance_km * 10 + COALESCE(a.elevation_gain_m,0) * 0.1 + COALESCE(a.relative_effort,0) + 5), 0)',
            default => 'COALESCE(SUM(a.relative_effort), 0)',   // effort
        };

        $rows = DB::table('activity_sessions as a')
            ->join('profiles as owner', 'owner.id', '=', 'a.profile_id')
            ->whereIn('a.profile_id', $scopeIds)
            // The viewer's own activities always count for them; others count only if they're in the
            // community and the activity isn't private.
            ->where(function ($q) use ($viewer) {
                $q->where('a.profile_id', $viewer->id)
                    ->orWhere(function ($q2) {
                        $q2->where('owner.community_enabled', true)
                            ->whereRaw("COALESCE(a.visibility, owner.default_activity_visibility) <> 'private'");
                    });
            })
            ->when($since, fn ($q) => $q->where('a.started_at', '>=', $since))
            ->groupBy('a.profile_id', 'owner.display_name', 'owner.username', 'owner.avatar_path')
            ->havingRaw("$valueExpr > 0")
            ->orderByRaw("$valueExpr DESC")
            ->selectRaw('a.profile_id, owner.display_name, owner.username, owner.avatar_path,
                         COUNT(*) as activity_count, '.$valueExpr.' as value')
            ->limit(max(1, min(200, $limit)))
            ->get();

        $athletes = $rows->values()->map(fn ($r, $i) => [
            'rank' => $i + 1,
            'profile_id' => (int) $r->profile_id,
            'name' => $r->display_name ?: ($r->username ?: 'Athlete'),
            'username' => $r->username,
            'avatar_url' => $this->avatarUrl($r->avatar_path),
            'activity_count' => (int) $r->activity_count,
            'value' => round((float) $r->value, $metric === 'distance' ? 1 : 0),
            'is_you' => (int) $r->profile_id === $viewer->id,
        ])->all();

        // The viewer's own standing — surfaced even if they fall outside the returned slice.
        $you = collect($athletes)->firstWhere('is_you', true);

        return [
            'metric' => $metric,
            'window' => $window,
            'unit' => $metric === 'distance' ? 'km' : ($metric === 'points' ? 'pts' : 'effort'),
            'athletes' => $athletes,
            'you' => $you,
        ];
    }

    private function since(string $window): ?Carbon
    {
        return match ($window) {
            'week' => now()->startOfWeek(),
            'month' => now()->startOfMonth(),
            default => null,
        };
    }

    private function avatarUrl(?string $path): ?string
    {
        return $path ? asset('storage/'.$path) : null;
    }
}
