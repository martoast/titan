<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivitySession;
use App\Models\Follow;
use App\Models\Profile;
use App\Support\Community\CommunityPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Discover athletes, view a profile, follow / unfollow. */
class AthleteController extends Controller
{
    public function __construct(private CommunityPresenter $present) {}

    /** Find people to follow by @username or display name (community-enabled only, excluding you). */
    public function search(Request $request): JsonResponse
    {
        $p = $request->user()->profile ?? $request->user()->ensureProfile();
        $q = trim((string) $request->query('q', ''));

        $matches = Profile::query()
            ->where('community_enabled', true)
            ->where('id', '!=', $p->id)
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w
                ->where('username', 'like', "%{$q}%")->orWhere('display_name', 'like', "%{$q}%")))
            ->orderBy('display_name')
            ->limit(25)->get();

        return response()->json([
            'athletes' => $matches->map(fn (Profile $a) => $this->present->athlete($a, $p))->values(),
        ]);
    }

    /** An athlete's profile + their recent activities visible to the viewer. */
    public function show(Request $request, Profile $profile): JsonResponse
    {
        $p = $request->user()->profile ?? $request->user()->ensureProfile();
        // You can always view yourself; otherwise the athlete must be in the community.
        abort_unless($profile->id === $p->id || $profile->community_enabled, 404);

        $recent = ActivitySession::visibleTo($p)
            ->where('activity_sessions.profile_id', $profile->id)
            ->orderByDesc('activity_sessions.started_at')->limit(20)->get();

        return response()->json([
            'athlete' => $this->present->athlete($profile, $p),
            'activities' => $recent->map(fn ($s) => $this->present->activityCard($s, $p))->values(),
            'achievements' => $this->present->achievements($profile),
        ]);
    }

    /** Follow an athlete — instantly accepted unless they require approval (then pending). */
    public function follow(Request $request, Profile $profile): JsonResponse
    {
        $p = $request->user()->profile ?? $request->user()->ensureProfile();
        abort_if($profile->id === $p->id, 422, "You can't follow yourself.");
        abort_unless($profile->community_enabled, 404);

        $accepted = ! $profile->followers_require_approval;
        $follow = Follow::firstOrCreate(
            ['follower_id' => $p->id, 'followee_id' => $profile->id],
            ['status' => $accepted ? Follow::ACCEPTED : Follow::PENDING, 'accepted_at' => $accepted ? now() : null],
        );

        return response()->json([
            'follow_state' => $follow->status,
            'athlete' => $this->present->athlete($profile->fresh(), $p),
        ]);
    }

    public function unfollow(Request $request, Profile $profile): JsonResponse
    {
        $p = $request->user()->profile ?? $request->user()->ensureProfile();
        Follow::where('follower_id', $p->id)->where('followee_id', $profile->id)->delete();

        return response()->json([
            'follow_state' => null,
            'athlete' => $this->present->athlete($profile->fresh(), $p),
        ]);
    }
}
