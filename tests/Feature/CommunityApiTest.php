<?php

namespace Tests\Feature;

use App\Models\ActivitySession;
use App\Models\ApiToken;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The community API: settings, follow graph, feed, kudos, comments, leaderboard, badges. */
class CommunityApiTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    /** @return array{0: Profile, 1: string} the profile + a bearer token */
    private function athlete(array $attrs = []): array
    {
        $user = User::factory()->create();
        $p = $user->ensureProfile();
        $p->update(array_merge([
            'community_enabled' => true,
            'default_activity_visibility' => 'followers',
            'followers_require_approval' => false,
        ], $attrs));
        [, $token] = ApiToken::mint($user, 'ios', ['*']);

        return [$p->fresh(), $token];
    }

    private function as_(string $token): self
    {
        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    private function seedRun(Profile $p, array $attrs = []): ActivitySession
    {
        $this->seq++;

        return ActivitySession::create(array_merge([
            'profile_id' => $p->id, 'source' => 'titan_band',
            'started_at' => now()->subHours(3)->addMinutes($this->seq), 'ended_at' => now()->subHours(2),
            'duration_min' => 30, 'activity_type' => 'run', 'distance_km' => 5.0, 'relative_effort' => 50,
        ], $attrs));
    }

    public function test_settings_opt_in_and_username(): void
    {
        [, $token] = $this->athlete(['community_enabled' => false]);

        $this->as_($token)->patchJson('/api/me/community/settings', [
            'community_enabled' => true, 'username' => 'runner_jane', 'bio' => 'love trails',
            'default_activity_visibility' => 'followers',
        ])->assertOk()
            ->assertJsonPath('community_enabled', true)
            ->assertJsonPath('username', 'runner_jane');

        // Username uniqueness is enforced.
        [, $other] = $this->athlete();
        $this->as_($other)->patchJson('/api/me/community/settings', ['username' => 'runner_jane'])
            ->assertStatus(422);
    }

    public function test_follow_feed_kudos_and_comment(): void
    {
        [$me, $myToken] = $this->athlete();
        [$friend] = $this->athlete(['display_name' => 'Maria']);

        // Follow (instant — friend doesn't require approval).
        $this->as_($myToken)->postJson("/api/me/athletes/{$friend->id}/follow")
            ->assertOk()->assertJsonPath('follow_state', 'accepted');

        $act = $this->seedRun($friend, ['visibility' => 'followers', 'distance_km' => 8.0]);

        // Feed shows the friend's activity.
        $this->as_($myToken)->getJson('/api/me/community/feed')->assertOk()
            ->assertJsonPath('items.0.id', $act->id)
            ->assertJsonPath('items.0.athlete.name', 'Maria')
            ->assertJsonPath('items.0.distance_km', 8)
            ->assertJsonPath('items.0.did_kudos', false);

        // Kudos it.
        $this->as_($myToken)->postJson("/api/me/activities/{$act->id}/kudos")->assertOk()
            ->assertJsonPath('kudos_count', 1)->assertJsonPath('did_kudos', true);

        // Comment on it.
        $this->as_($myToken)->postJson("/api/me/activities/{$act->id}/comments", ['body' => 'nice splits!'])
            ->assertStatus(201)->assertJsonPath('body', 'nice splits!');
        $this->as_($myToken)->getJson("/api/me/activities/{$act->id}/comments")->assertOk()
            ->assertJsonPath('comments.0.body', 'nice splits!');
    }

    public function test_follow_requiring_approval_then_accept(): void
    {
        [$me, $myToken] = $this->athlete();
        [$priv, $privToken] = $this->athlete(['followers_require_approval' => true]);

        // Request → pending.
        $this->as_($myToken)->postJson("/api/me/athletes/{$priv->id}/follow")
            ->assertOk()->assertJsonPath('follow_state', 'pending');

        $act = $this->seedRun($priv, ['visibility' => 'followers']);

        // Not visible yet (still pending).
        $this->as_($myToken)->postJson("/api/me/activities/{$act->id}/kudos")->assertNotFound();

        // The private athlete sees the request and accepts it.
        $reqs = $this->as_($privToken)->getJson('/api/me/community/requests')->assertOk();
        $followId = $reqs->json('requests.0.follow_id');
        $this->as_($privToken)->postJson("/api/me/community/requests/{$followId}/accept")->assertOk();

        // Now the activity is in my feed.
        $this->as_($myToken)->getJson('/api/me/community/feed')->assertOk()
            ->assertJsonPath('items.0.id', $act->id);
    }

    public function test_private_activity_never_leaks(): void
    {
        [$me, $myToken] = $this->athlete();
        [$friend] = $this->athlete();
        $this->as_($myToken)->postJson("/api/me/athletes/{$friend->id}/follow")->assertOk();

        $secret = $this->seedRun($friend, ['visibility' => 'private']);

        $this->as_($myToken)->postJson("/api/me/activities/{$secret->id}/kudos")->assertNotFound();
        $this->as_($myToken)->getJson('/api/me/community/feed')->assertOk()
            ->assertJsonMissing(['id' => $secret->id]);
    }

    public function test_leaderboard_ranks_you_and_following_by_effort(): void
    {
        [$me, $myToken] = $this->athlete();
        [$friend] = $this->athlete(['display_name' => 'Big Effort']);
        $this->as_($myToken)->postJson("/api/me/athletes/{$friend->id}/follow")->assertOk();

        // Friend out-efforts me this week.
        $this->seedRun($me, ['relative_effort' => 100, 'visibility' => 'followers']);
        $this->seedRun($friend, ['relative_effort' => 300, 'visibility' => 'followers']);

        $board = $this->as_($myToken)->getJson('/api/me/community/leaderboard?metric=effort&window=week')->assertOk();
        $board->assertJsonPath('metric', 'effort')
            ->assertJsonPath('athletes.0.name', 'Big Effort')
            ->assertJsonPath('athletes.0.value', 300)
            ->assertJsonPath('athletes.1.is_you', true)
            ->assertJsonPath('athletes.1.value', 100);
        // My own standing is surfaced.
        $this->assertSame(2, $board->json('you.rank'));
    }

    public function test_achievements_endpoint_lists_catalog_with_earned_flags(): void
    {
        [$me, $myToken] = $this->athlete();
        // A 12 km run earns first_run, first_5k, first_10k.
        $this->seedRun($me, ['distance_km' => 12.0]);
        app(\App\Services\Community\AchievementEngine::class)->evaluate($me->fresh());

        $res = $this->as_($myToken)->getJson('/api/me/achievements')->assertOk();
        $byKey = collect($res->json('achievements'))->keyBy('key');
        $this->assertTrue($byKey['first_10k']['earned']);
        $this->assertTrue($byKey['first_5k']['earned']);
        $this->assertFalse($byKey['first_half']['earned']);
    }
}
