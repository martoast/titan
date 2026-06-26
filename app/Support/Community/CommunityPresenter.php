<?php

namespace App\Support\Community;

use App\Models\ActivityComment;
use App\Models\ActivitySession;
use App\Models\Follow;
use App\Models\Profile;

/**
 * One source of truth for the JSON shapes the community API returns (athlete, activity card,
 * comment), so every endpoint and both platforms agree on field names. `$viewer` context drives
 * the relational flags (is_you, did_kudos, follow_state).
 */
class CommunityPresenter
{
    /** A person, with the viewer's relationship to them + their public totals. */
    public function athlete(Profile $p, Profile $viewer): array
    {
        return [
            'id' => $p->id,
            'name' => $p->communityName(),
            'username' => $p->username,
            'bio' => $p->bio,
            'avatar_url' => $p->avatar_path ? asset('storage/'.$p->avatar_path) : null,
            'community_enabled' => (bool) $p->community_enabled,
            'is_you' => $p->id === $viewer->id,
            // The viewer's relationship to this athlete.
            'follow_state' => $p->id === $viewer->id ? null : $viewer->followStateToward($p),   // accepted|pending|null
            'follows_you' => $p->id === $viewer->id ? null : $p->isFollowing($viewer),
            'follower_count' => Follow::where('followee_id', $p->id)->where('status', Follow::ACCEPTED)->count(),
            'following_count' => Follow::where('follower_id', $p->id)->where('status', Follow::ACCEPTED)->count(),
            'total_activities' => $p->activitySessions()->count(),
            'total_distance_km' => round((float) $p->activitySessions()->sum('distance_km'), 1),
        ];
    }

    /** A feed/list card for one activity, with social counts + the viewer's kudos state. */
    public function activityCard(ActivitySession $s, Profile $viewer): array
    {
        $s->loadMissing('profile');

        return [
            'id' => $s->id,
            'title' => $s->title(),
            'activity_type' => $s->activity_type,
            'started_at' => $s->started_at?->toIso8601String(),
            'duration_min' => $s->duration_min,
            'distance_km' => $s->distance_km !== null ? (float) $s->distance_km : null,
            'avg_pace_s_per_km' => $s->avg_pace_s_per_km,
            'elevation_gain_m' => $s->elevation_gain_m,
            'relative_effort' => $s->relative_effort,
            'has_route' => $s->hasRoute(),
            'map_thumb_url' => $s->staticMapUrl(640, 360),
            'athlete' => [
                'id' => $s->profile_id,
                'name' => $s->profile?->communityName() ?? 'Athlete',
                'username' => $s->profile?->username,
                'avatar_url' => $s->profile?->avatar_path ? asset('storage/'.$s->profile->avatar_path) : null,
                'is_you' => $s->profile_id === $viewer->id,
            ],
            'kudos_count' => $s->kudos()->count(),
            'comment_count' => $s->comments()->count(),
            'did_kudos' => $s->kudos()->where('profile_id', $viewer->id)->exists(),
        ];
    }

    public function comment(ActivityComment $c, Profile $viewer): array
    {
        $c->loadMissing('profile');

        return [
            'id' => $c->id,
            'body' => $c->body,
            'created_at' => $c->created_at?->toIso8601String(),
            'is_mine' => $c->profile_id === $viewer->id,
            'athlete' => [
                'id' => $c->profile_id,
                'name' => $c->profile?->communityName() ?? 'Athlete',
                'username' => $c->profile?->username,
                'avatar_url' => $c->profile?->avatar_path ? asset('storage/'.$c->profile->avatar_path) : null,
            ],
        ];
    }

    /** The badge catalog joined with what the profile has earned — the badge wall. */
    public function achievements(Profile $p): array
    {
        $earned = $p->achievements()->get()->keyBy('key');

        $out = [];
        foreach (\App\Services\Community\AchievementEngine::CATALOG as $key => [$title, $blurb, $icon, $emoji]) {
            $a = $earned->get($key);
            $out[] = [
                'key' => $key,
                'title' => $title,
                'blurb' => $blurb,
                'icon' => $icon,
                'emoji' => $emoji,
                'earned' => $a !== null,
                'awarded_at' => $a?->awarded_at?->toIso8601String(),
                'meta' => $a?->meta,
            ];
        }

        return $out;
    }
}
