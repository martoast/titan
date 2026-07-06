<?php

namespace App\Http\Controllers;

use App\Models\ActivitySession;
use App\Services\Community\LeaderboardService;
use App\Support\Community\CommunityPresenter;
use Illuminate\Http\Request;

/**
 * The web Community tab — the browser twin of the iOS CommunityView. Reuses the same
 * services as the mobile API (CommunityPresenter, LeaderboardService, the visibleTo scope)
 * so the feed + leaderboard show identical data. Opt-in gated like iOS.
 */
class CommunityWebController extends Controller
{
    public function index(Request $request, CommunityPresenter $present, LeaderboardService $leaders)
    {
        $p = $request->user()->ensureProfile();

        // Not opted in yet → the view shows the join card (mirrors iOS CommunityOptInCard).
        if (! $p->community_enabled) {
            return view('community.index', ['enabled' => false, 'profile' => $p]);
        }

        $tab = $request->query('view', 'feed');   // feed | leaders
        $metric = in_array($request->query('metric'), ['effort', 'distance', 'points'], true)
            ? $request->query('metric') : 'effort';
        $window = in_array($request->query('window'), ['week', 'month', 'all'], true)
            ? $request->query('window') : 'week';

        $scopeIds = array_merge([$p->id], $p->acceptedFollowingIds());
        $feed = ActivitySession::visibleTo($p)
            ->whereIn('activity_sessions.profile_id', $scopeIds)
            ->orderByDesc('activity_sessions.started_at')
            ->limit(30)->get()
            ->map(fn ($s) => $present->activityCard($s, $p))->values();

        return view('community.index', [
            'enabled' => true,
            'profile' => $p,
            'tab' => $tab,
            'feed' => $feed,
            'leaderboard' => $leaders->build($p, $metric, $window),
            'metric' => $metric,
            'window' => $window,
        ]);
    }
}
