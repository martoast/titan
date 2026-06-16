<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\User;
use App\Models\Workout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssistantApiTest extends TestCase
{
    use RefreshDatabase;

    private function token(User $user, array $abilities = ['*']): string
    {
        [, $plain] = ApiToken::mint($user, 'test', $abilities);

        return $plain;
    }

    private function auth(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    public function test_rejects_missing_or_invalid_token(): void
    {
        $this->getJson('/api/me')->assertStatus(401);
        $this->getJson('/api/me', ['Authorization' => 'Bearer titan_nope'])->assertStatus(401);
    }

    public function test_me_identifies_the_owner(): void
    {
        $user = User::factory()->create(['name' => 'Alex']);
        $this->getJson('/api/me', $this->auth($this->token($user)))->assertOk()
            ->assertJsonPath('user.name', 'Alex')
            ->assertJsonPath('token.abilities', ['*']);
    }

    public function test_lists_tools_and_runs_a_read(): void
    {
        $user = User::factory()->create();
        $token = $this->token($user);

        $this->getJson('/api/tools', $this->auth($token))->assertOk()
            ->assertJsonFragment(['name' => 'get_today'])
            ->assertJsonFragment(['name' => 'log_workout']);

        $this->postJson('/api/tool', ['tool' => 'get_today'], $this->auth($token))->assertOk()
            ->assertJsonPath('tool', 'get_today')
            ->assertJsonStructure(['result' => ['steps', 'step_goal']]);
    }

    public function test_write_tool_persists_and_is_scoped_to_owner(): void
    {
        $user = User::factory()->create();
        $token = $this->token($user);

        // Log steps via the API → stored on this user's profile.
        $this->postJson('/api/tool', ['tool' => 'log_steps', 'args' => ['steps' => 9100]], $this->auth($token))
            ->assertOk()->assertJsonPath('result.ok', true);
        $this->assertDatabaseHas('daily_activity', ['profile_id' => $user->profile->id, 'steps' => 9100]);

        // Log a strength workout.
        $this->postJson('/api/tool', ['tool' => 'log_workout', 'args' => [
            'name' => 'Push day',
            'exercises' => [['name' => 'Bench Press', 'sets' => [['reps' => 8, 'weight_kg' => 80], ['reps' => 7, 'weight_kg' => 80]]]],
        ]], $this->auth($token))->assertOk()->assertJsonPath('result.sets', 2);

        $workout = Workout::where('profile_id', $user->profile->id)->first();
        $this->assertNotNull($workout);
        $this->assertSame('Push day', $workout->name);
    }

    public function test_read_only_token_cannot_write(): void
    {
        $user = User::factory()->create();
        $readToken = $this->token($user, ['read']);

        // A read still works…
        $this->postJson('/api/tool', ['tool' => 'get_activity'], $this->auth($readToken))->assertOk();
        // …but a write is forbidden.
        $this->postJson('/api/tool', ['tool' => 'log_steps', 'args' => ['steps' => 5000]], $this->auth($readToken))
            ->assertStatus(403)->assertJsonPath('required', 'write');
        $this->assertDatabaseMissing('daily_activity', ['profile_id' => $user->profile->id]);
    }

    public function test_pair_device_returns_a_one_time_secret(): void
    {
        $user = User::factory()->create();
        $r = $this->postJson('/api/tool', ['tool' => 'pair_device', 'args' => []], $this->auth($this->token($user)))
            ->assertOk()->json('result');

        $this->assertTrue($r['ok']);
        $this->assertStringStartsWith('tb_', $r['device_id']);
        $this->assertNotEmpty($r['secret']);
        $this->assertDatabaseHas('wearable_connections', ['profile_id' => $user->profile->id, 'device_id' => $r['device_id']]);
    }

    public function test_connect_page_mints_a_token_shown_once(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/connect/tokens', ['name' => 'My agent', 'scope' => 'full'])
            ->assertRedirect('/connect')->assertSessionHas('plain_token');
        $this->assertSame(1, $user->apiTokens()->count());
    }
}
