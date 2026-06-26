<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivitySession;
use App\Models\Follow;
use App\Services\Community\WeeklyRecap;
use App\Support\Community\CommunityPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The community hub: opt-in settings, the followed-athletes feed, follow requests, recap, badges. */
class CommunityController extends Controller
{
    public function __construct(private CommunityPresenter $present) {}

    /** The viewer's own community settings (drives the settings sheet). */
    public function settings(Request $request): JsonResponse
    {
        $p = $request->user()->profile ?? $request->user()->ensureProfile();

        return response()->json($this->settingsPayload($p));
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $p = $request->user()->profile ?? $request->user()->ensureProfile();

        $data = $request->validate([
            'community_enabled' => ['sometimes', 'boolean'],
            'username' => ['sometimes', 'nullable', 'string', 'min:3', 'max:30', 'regex:/^[a-zA-Z0-9_]+$/',
                Rule::unique('profiles', 'username')->ignore($p->id)],
            'bio' => ['sometimes', 'nullable', 'string', 'max:280'],
            'followers_require_approval' => ['sometimes', 'boolean'],
            'default_activity_visibility' => ['sometimes', Rule::in(['private', 'followers', 'public'])],
        ]);

        $p->update($data);

        return response()->json($this->settingsPayload($p->fresh()));
    }

    /** Reverse-chron activities from the people you follow (+ your own) — the feed. */
    public function feed(Request $request): JsonResponse
    {
        $p = $request->user()->profile ?? $request->user()->ensureProfile();
        $limit = (int) min(50, max(1, $request->integer('limit', 30)));
        $offset = (int) max(0, $request->integer('offset', 0));

        $scopeIds = array_merge([$p->id], $p->acceptedFollowingIds());

        $items = ActivitySession::visibleTo($p)
            ->whereIn('activity_sessions.profile_id', $scopeIds)
            ->orderByDesc('activity_sessions.started_at')
            ->offset($offset)->limit($limit + 1)   // +1 to detect "has more" without a count
            ->get();

        $hasMore = $items->count() > $limit;

        return response()->json([
            'items' => $items->take($limit)->map(fn ($s) => $this->present->activityCard($s, $p))->values(),
            'next_offset' => $hasMore ? $offset + $limit : null,
        ]);
    }

    /** Pending follow requests waiting on the viewer's approval (private account). */
    public function requests(Request $request): JsonResponse
    {
        $p = $request->user()->profile ?? $request->user()->ensureProfile();

        $reqs = Follow::with('follower')
            ->where('followee_id', $p->id)->where('status', Follow::PENDING)
            ->orderByDesc('created_at')->get();

        return response()->json([
            'requests' => $reqs->map(fn (Follow $f) => [
                'follow_id' => $f->id,
                'requested_at' => $f->created_at?->toIso8601String(),
                'athlete' => $this->present->athlete($f->follower, $p),
            ])->values(),
        ]);
    }

    public function acceptRequest(Request $request, Follow $follow): JsonResponse
    {
        $p = $request->user()->profile ?? $request->user()->ensureProfile();
        abort_unless($follow->followee_id === $p->id, 404);

        $follow->update(['status' => Follow::ACCEPTED, 'accepted_at' => now()]);

        return response()->json(['ok' => true]);
    }

    public function declineRequest(Request $request, Follow $follow): JsonResponse
    {
        $p = $request->user()->profile ?? $request->user()->ensureProfile();
        abort_unless($follow->followee_id === $p->id, 404);

        $follow->delete();

        return response()->json(['ok' => true]);
    }

    public function recap(Request $request, WeeklyRecap $recap): JsonResponse
    {
        $p = $request->user()->profile ?? $request->user()->ensureProfile();

        return response()->json($recap->forProfile($p));
    }

    public function achievements(Request $request): JsonResponse
    {
        $p = $request->user()->profile ?? $request->user()->ensureProfile();

        return response()->json(['achievements' => $this->present->achievements($p)]);
    }

    private function settingsPayload($p): array
    {
        return [
            'community_enabled' => (bool) $p->community_enabled,
            'username' => $p->username,
            'bio' => $p->bio,
            'display_name' => $p->display_name,
            'followers_require_approval' => (bool) $p->followers_require_approval,
            'default_activity_visibility' => $p->default_activity_visibility ?? 'followers',
            'follower_count' => Follow::where('followee_id', $p->id)->where('status', Follow::ACCEPTED)->count(),
            'following_count' => Follow::where('follower_id', $p->id)->where('status', Follow::ACCEPTED)->count(),
            'pending_request_count' => Follow::where('followee_id', $p->id)->where('status', Follow::PENDING)->count(),
        ];
    }
}
