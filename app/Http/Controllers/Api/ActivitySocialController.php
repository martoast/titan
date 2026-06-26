<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityComment;
use App\Models\ActivitySession;
use App\Models\Kudo;
use App\Models\Profile;
use App\Support\Community\CommunityPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Kudos, comments, and per-activity visibility — the social actions on someone's activity. */
class ActivitySocialController extends Controller
{
    public function __construct(private CommunityPresenter $present) {}

    public function kudos(Request $request, ActivitySession $session): JsonResponse
    {
        $p = $request->user()->profile ?? $request->user()->ensureProfile();
        $this->assertViewable($session, $p);

        Kudo::firstOrCreate(['profile_id' => $p->id, 'activity_session_id' => $session->id]);

        return $this->kudosState($session, $p);
    }

    public function unkudos(Request $request, ActivitySession $session): JsonResponse
    {
        $p = $request->user()->profile ?? $request->user()->ensureProfile();
        Kudo::where('profile_id', $p->id)->where('activity_session_id', $session->id)->delete();

        return $this->kudosState($session, $p);
    }

    public function comments(Request $request, ActivitySession $session): JsonResponse
    {
        $p = $request->user()->profile ?? $request->user()->ensureProfile();
        $this->assertViewable($session, $p);

        $comments = $session->comments()->with('profile')->orderBy('created_at')->get();

        return response()->json([
            'comments' => $comments->map(fn (ActivityComment $c) => $this->present->comment($c, $p))->values(),
        ]);
    }

    public function comment(Request $request, ActivitySession $session): JsonResponse
    {
        $p = $request->user()->profile ?? $request->user()->ensureProfile();
        $this->assertViewable($session, $p);

        $data = $request->validate(['body' => ['required', 'string', 'min:1', 'max:1000']]);

        $c = $session->comments()->create(['profile_id' => $p->id, 'body' => trim($data['body'])]);

        return response()->json($this->present->comment($c->load('profile'), $p), 201);
    }

    public function deleteComment(Request $request, ActivityComment $comment): JsonResponse
    {
        $p = $request->user()->profile ?? $request->user()->ensureProfile();
        // The comment's author OR the activity's owner can remove it.
        $isOwner = $comment->activitySession()->value('profile_id') === $p->id;
        abort_unless($comment->profile_id === $p->id || $isOwner, 403);

        $comment->delete();

        return response()->json(['ok' => true]);
    }

    /** Owner-only: change one activity's visibility (overrides the profile default). */
    public function updateVisibility(Request $request, ActivitySession $session): JsonResponse
    {
        $p = $request->user()->profile ?? $request->user()->ensureProfile();
        abort_unless($session->profile_id === $p->id, 403);

        $data = $request->validate(['visibility' => ['required', Rule::in(['private', 'followers', 'public'])]]);
        $session->update(['visibility' => $data['visibility']]);

        return response()->json(['visibility' => $session->visibility]);
    }

    /** 404 unless the viewer is allowed to see this activity (own, or shared to them). */
    private function assertViewable(ActivitySession $session, Profile $viewer): void
    {
        $ok = $session->profile_id === $viewer->id
            || ActivitySession::visibleTo($viewer)->where('activity_sessions.id', $session->id)->exists();
        abort_unless($ok, 404);
    }

    private function kudosState(ActivitySession $session, Profile $viewer): JsonResponse
    {
        return response()->json([
            'kudos_count' => $session->kudos()->count(),
            'did_kudos' => $session->kudos()->where('profile_id', $viewer->id)->exists(),
        ]);
    }
}
