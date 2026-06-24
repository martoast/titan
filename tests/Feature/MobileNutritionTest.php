<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The native app's Fuel tab: macro card + meal CRUD, and the progress-photo gallery. (The AI
 * photo→macros scan path is exercised via ScanService elsewhere; it needs OpenAI, so it's not
 * hit here.)
 */
class MobileNutritionTest extends TestCase
{
    use RefreshDatabase;

    private function auth(): array
    {
        $user = User::factory()->create();
        $profile = $user->ensureProfile();
        [, $token] = ApiToken::mint($user, 'ios', ['*']);

        return [$profile, $token];
    }

    public function test_nutrition_returns_macro_card_and_meals(): void
    {
        [$profile, $token] = $this->auth();
        $profile->meals()->create([
            'name' => 'Chicken & rice', 'eaten_at' => now(),
            'calories' => 600, 'protein_g' => 50, 'carbs_g' => 60, 'fat_g' => 15, 'source' => 'manual',
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/me/nutrition')
            ->assertOk()
            ->assertJsonStructure([
                'macros' => ['calories' => ['value', 'target'], 'protein' => ['value', 'target'], 'carbs', 'fat'],
                'meals' => [['id', 'name', 'calories', 'protein_g', 'photo_url']],
            ])
            ->assertJsonPath('macros.calories.value', 600)
            ->assertJsonPath('macros.protein.value', 50)
            ->assertJsonPath('meals.0.name', 'Chicken & rice');
    }

    public function test_can_add_update_and_delete_a_meal(): void
    {
        [, $token] = $this->auth();
        $h = $this->withHeader('Authorization', "Bearer {$token}");

        $created = $h->postJson('/api/me/meals', [
            'name' => 'Steak', 'calories' => 700, 'protein_g' => 55, 'carbs_g' => 0, 'fat_g' => 50,
        ])->assertOk()->assertJsonPath('meal.name', 'Steak')->json('meal.id');

        $h->patchJson("/api/me/meals/{$created}", ['name' => 'Ribeye', 'calories' => 800, 'protein_g' => 60, 'carbs_g' => 0, 'fat_g' => 60])
            ->assertOk()->assertJsonPath('meal.name', 'Ribeye')->assertJsonPath('macros.calories.value', 800);

        $h->deleteJson("/api/me/meals/{$created}")->assertOk()->assertJsonPath('macros.calories.value', 0);
    }

    public function test_cannot_touch_another_users_meal(): void
    {
        [$mine, $token] = $this->auth();
        $other = User::factory()->create()->ensureProfile();
        $theirMeal = $other->meals()->create(['name' => 'Theirs', 'eaten_at' => now(), 'calories' => 100, 'source' => 'manual']);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/me/meals/{$theirMeal->id}")->assertStatus(404);
    }

    public function test_progress_photo_upload_list_and_delete(): void
    {
        Storage::fake('public');
        [, $token] = $this->auth();
        $h = $this->withHeader('Authorization', "Bearer {$token}");

        $id = $h->post('/api/me/progress-photos', [
            'photo' => UploadedFile::fake()->image('me.jpg', 600, 800),
            'pose' => 'front', 'weight_kg' => 82.5,
        ])->assertStatus(201)->assertJsonPath('photo.pose', 'front')->json('photo.id');

        $h->getJson('/api/me/progress-photos')
            ->assertOk()->assertJsonCount(1, 'photos')->assertJsonPath('photos.0.id', $id);

        $h->deleteJson("/api/me/progress-photos/{$id}")->assertOk();
        $h->getJson('/api/me/progress-photos')->assertOk()->assertJsonCount(0, 'photos');
    }

    public function test_endpoints_require_auth(): void
    {
        $this->getJson('/api/me/nutrition')->assertStatus(401);
        $this->getJson('/api/me/progress-photos')->assertStatus(401);
    }
}
