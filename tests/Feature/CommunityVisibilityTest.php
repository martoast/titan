<?php

namespace Tests\Feature;

use App\Models\ActivitySession;
use App\Models\Follow;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommunityVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function profile(array $attrs = []): Profile
    {
        $p = User::factory()->create()->ensureProfile();
        $p->update(array_merge(['community_enabled' => true, 'default_activity_visibility' => 'followers'], $attrs));

        return $p->fresh();
    }

    private int $seq = 0;

    private function activity(Profile $p, ?string $visibility = null): ActivitySession
    {
        $this->seq++;   // distinct start per activity (profile_id+started_at is unique)

        return ActivitySession::create([
            'profile_id' => $p->id, 'source' => 'titan_band', 'visibility' => $visibility,
            'started_at' => now()->subHours(2)->addMinutes($this->seq), 'ended_at' => now()->subMinutes(30),
            'duration_min' => 30, 'activity_type' => 'run', 'distance_km' => 5.0,
        ]);
    }

    public function test_visibility_scope_respects_follow_graph_and_per_activity_visibility(): void
    {
        $alice = $this->profile(['default_activity_visibility' => 'public']);
        $bob = $this->profile();                                  // followers-default
        $carol = $this->profile();                               // a stranger (community on, follows no one)

        // Alice follows Bob (accepted); Carol follows nobody.
        Follow::create(['follower_id' => $alice->id, 'followee_id' => $bob->id, 'status' => Follow::ACCEPTED, 'accepted_at' => now()]);

        $bobFollowers = $this->activity($bob, 'followers');      // inherits/sets followers
        $bobPublic = $this->activity($bob, 'public');
        $bobPrivate = $this->activity($bob, 'private');
        $aliceOwn = $this->activity($alice);                     // alice default = public

        // Alice (follows Bob): sees her own + bob's followers + bob's public; NOT bob's private.
        $aliceVisible = ActivitySession::visibleTo($alice)->pluck('activity_sessions.id')->all();
        $this->assertContains($aliceOwn->id, $aliceVisible);
        $this->assertContains($bobFollowers->id, $aliceVisible);
        $this->assertContains($bobPublic->id, $aliceVisible);
        $this->assertNotContains($bobPrivate->id, $aliceVisible, 'private must never leak');

        // Carol (follows nobody): sees bob's PUBLIC only; not his followers-scoped one.
        $carolVisible = ActivitySession::visibleTo($carol)->pluck('activity_sessions.id')->all();
        $this->assertContains($bobPublic->id, $carolVisible);
        $this->assertNotContains($bobFollowers->id, $carolVisible);
        $this->assertNotContains($bobPrivate->id, $carolVisible);
    }

    public function test_disabling_community_hides_even_public_activities(): void
    {
        $owner = $this->profile(['community_enabled' => false]);
        $viewer = $this->profile();
        Follow::create(['follower_id' => $viewer->id, 'followee_id' => $owner->id, 'status' => Follow::ACCEPTED, 'accepted_at' => now()]);
        $pub = $this->activity($owner, 'public');

        $visible = ActivitySession::visibleTo($viewer)->pluck('activity_sessions.id')->all();
        $this->assertNotContains($pub->id, $visible, 'community off ⇒ nothing shared');
    }

    public function test_pending_follow_does_not_grant_access(): void
    {
        $owner = $this->profile();
        $viewer = $this->profile();
        Follow::create(['follower_id' => $viewer->id, 'followee_id' => $owner->id, 'status' => Follow::PENDING]);
        $a = $this->activity($owner, 'followers');

        $visible = ActivitySession::visibleTo($viewer)->pluck('activity_sessions.id')->all();
        $this->assertNotContains($a->id, $visible, 'a pending request is not yet a follower');
    }
}
