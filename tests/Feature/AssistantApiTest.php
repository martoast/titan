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

    public function test_overview_and_longevity_compose_the_whole_account(): void
    {
        $user = User::factory()->create(['name' => 'Jordan']);
        $token = $this->token($user);
        $p = $user->ensureProfile();
        $p->update(['primary_goal' => 'Lean recomposition', 'birthdate' => now()->subYears(33)->toDateString()]);
        $p->dailyActivity()->create(['date' => now()->toDateString(), 'steps' => 9100, 'source' => 'manual']);

        $this->postJson('/api/tool', ['tool' => 'get_overview'], $this->auth($token))->assertOk()
            ->assertJsonPath('result.name', 'Jordan')
            ->assertJsonPath('result.primary_goal', 'Lean recomposition')
            ->assertJsonPath('result.activity.steps', 9100);

        $this->postJson('/api/tool', ['tool' => 'get_longevity'], $this->auth($token))->assertOk()
            ->assertJsonStructure(['result' => ['sleep_regularity', 'circadian_rhythm', 'metabolic_health', 'vo2max']]);
    }

    public function test_new_write_tools_persist(): void
    {
        $user = User::factory()->create();
        $token = $this->token($user);
        $pid = $user->ensureProfile()->id;

        $this->postJson('/api/tool', ['tool' => 'log_meal', 'args' => ['name' => 'Chicken & rice', 'calories' => 650, 'protein_g' => 55]], $this->auth($token))
            ->assertOk()->assertJsonPath('result.ok', true);
        $this->assertDatabaseHas('meals', ['profile_id' => $pid, 'name' => 'Chicken & rice', 'calories' => 650]);

        $this->postJson('/api/tool', ['tool' => 'log_cardio', 'args' => ['type' => 'run', 'duration_min' => 32, 'distance_km' => 5.4, 'avg_hr' => 150]], $this->auth($token))
            ->assertOk()->assertJsonPath('result.type', 'run');
        $this->assertDatabaseHas('activity_sessions', ['profile_id' => $pid, 'activity_type' => 'run', 'duration_min' => 32]);

        $this->postJson('/api/tool', ['tool' => 'log_biomarker', 'args' => ['marker' => 'ldl', 'value' => 95, 'unit' => 'mg/dL']], $this->auth($token))
            ->assertOk()->assertJsonPath('result.ok', true);
        $this->assertDatabaseHas('biomarker_readings', ['profile_id' => $pid, 'marker' => 'ldl']);

        $this->postJson('/api/tool', ['tool' => 'update_profile', 'args' => ['height_cm' => 181, 'primary_goal' => 'Run a sub-20 5k']], $this->auth($token))
            ->assertOk()->assertJsonPath('result.ok', true);
        $this->assertSame('Run a sub-20 5k', $user->profile->fresh()->primary_goal);
    }

    public function test_full_tool_catalog_is_exposed(): void
    {
        $user = User::factory()->create();
        $names = collect($this->getJson('/api/tools', $this->auth($this->token($user)))->json('tools'))->pluck('name');
        // A representative slice across every domain.
        foreach (['get_overview', 'get_longevity', 'get_trends', 'get_nutrition', 'get_physique', 'search_knowledge',
            'log_meal', 'log_cardio', 'log_biomarker', 'update_profile', 'save_knowledge', 'unpair_device'] as $t) {
            $this->assertContains($t, $names->all(), "missing tool: {$t}");
        }
        $this->assertGreaterThanOrEqual(28, $names->count());
    }

    public function test_connect_page_mints_a_token_shown_once(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/connect/tokens', ['name' => 'My agent', 'scope' => 'full'])
            ->assertRedirect('/connect')->assertSessionHas('plain_token');
        $this->assertSame(1, $user->apiTokens()->count());
    }
}
