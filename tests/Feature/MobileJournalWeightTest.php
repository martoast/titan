<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\BodyMetric;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileJournalWeightTest extends TestCase
{
    use RefreshDatabase;

    private function auth(): array
    {
        $user = User::factory()->create();
        $user->ensureProfile();
        [, $token] = ApiToken::mint($user, 'ios', ['*']);
        return [$user, $this->withHeader('Authorization', "Bearer {$token}")];
    }

    public function test_journal_get_and_post(): void
    {
        [, $h] = $this->auth();
        $h->getJson('/api/me/journal')->assertOk()
            ->assertJsonStructure(['date', 'catalog' => [['key', 'label', 'polarity']], 'logged']);

        $h->postJson('/api/me/journal', ['add' => ['alcohol', 'late_meal']])->assertOk()
            ->assertJsonPath('logged', fn ($l) => in_array('alcohol', $l) && in_array('late_meal', $l));

        $h->postJson('/api/me/journal', ['remove' => ['late_meal']])->assertOk()
            ->assertJsonPath('logged', fn ($l) => $l === ['alcohol']);
    }

    public function test_weight_get_and_post(): void
    {
        [$user, $h] = $this->auth();
        $h->postJson('/api/me/weight', ['weight_kg' => 88.4])->assertOk()->assertJsonPath('type', 'weight');
        $this->assertSame(1, BodyMetric::where('profile_id', $user->profile->id)->count());

        $h->getJson('/api/me/weight')->assertOk()
            ->assertJsonStructure(['type', 'trend_kg', 'rate_kg_wk', 'series']);
    }

    public function test_requires_auth(): void
    {
        $this->getJson('/api/me/journal')->assertStatus(401);
        $this->getJson('/api/me/weight')->assertStatus(401);
    }
}
