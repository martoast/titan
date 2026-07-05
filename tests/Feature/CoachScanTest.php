<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Ai\AiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class CoachScanTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_meal_photo_is_logged_with_macros(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $user->ensureProfile();

        $vision = Mockery::mock(AiService::class);
        $vision->shouldReceive('vision')->once()->andReturn(json_encode([
            'kind' => 'meal',
            'meal' => ['name' => 'Chicken & rice', 'calories' => 620, 'protein_g' => 48, 'carbs_g' => 55, 'fat_g' => 18, 'confidence' => 'medium'],
        ]));
        $this->app->instance(AiService::class, $vision);

        $response = $this->actingAs($user)->post('/coach/scan', [
            'photo' => UploadedFile::fake()->image('plate.jpg'),
        ]);

        $response->assertOk()->assertJson(['ok' => true, 'kind' => 'meal', 'logged' => true]);
        $this->assertDatabaseHas('meals', ['name' => 'Chicken & rice', 'calories' => 620, 'source' => 'photo']);
        // The photo + the coach's confirmation both land in the conversation.
        $this->assertDatabaseCount('chat_messages', 2);
        // The reply leads with the updated macros card.
        $this->assertStringContainsString('titan-card', $response->json('reply'));
        $this->assertStringContainsString('macros', $response->json('reply'));
    }

    public function test_a_caption_is_passed_to_the_vision_read_and_kept_on_the_turn(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $user->ensureProfile();

        $vision = Mockery::mock(AiService::class);
        $vision->shouldReceive('vision')
            ->once()
            ->with(Mockery::on(fn ($p) => str_contains($p, 'a 12oz steak')), Mockery::any(), Mockery::any())
            ->andReturn(json_encode(['kind' => 'meal', 'meal' => ['name' => 'Steak', 'calories' => 700, 'protein_g' => 55, 'carbs_g' => 0, 'fat_g' => 50]]));
        $this->app->instance(AiService::class, $vision);

        $this->actingAs($user)->post('/coach/scan', [
            'photo' => UploadedFile::fake()->image('dinner.jpg'),
            'message' => 'this is what I ate, a 12oz steak',
        ])->assertOk()->assertJson(['kind' => 'meal']);

        // The caption is preserved on the user's turn (with the photo).
        $this->assertDatabaseHas('chat_messages', ['role' => 'user']);
        $userTurn = $user->profile->conversations()->latest('id')->first()->messages()->where('role', 'user')->first();
        $this->assertStringContainsString('12oz steak', $userTurn->content);
    }

    public function test_a_label_with_save_intent_is_remembered_not_logged(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $profile = $user->ensureProfile();

        // A nutrition-facts label + "add this to the pastas I make" → SAVE as a reference, don't log.
        $vision = Mockery::mock(AiService::class);
        $vision->shouldReceive('vision')->once()->andReturn(json_encode([
            'kind' => 'meal',
            'intent' => 'save',
            'meal' => ['name' => 'De Cecco Fusilli', 'calories' => 300, 'protein_g' => 12, 'carbs_g' => 61,
                'fat_g' => 1.5, 'is_label' => true, 'brand' => 'De Cecco', 'product' => 'Fusilli no. 34', 'serving_hint' => '1/5 package (85 g)'],
        ]));
        $this->app->instance(AiService::class, $vision);

        $response = $this->actingAs($user)->post('/coach/scan', [
            'photo' => UploadedFile::fake()->image('fusilli.jpg'),
            'message' => 'Add this to one of the kinds of pasta that I make',
        ]);

        $response->assertOk()->assertJson(['ok' => true, 'kind' => 'meal_saved', 'logged' => false]);

        // It's saved to the reference library (your foods) …
        $this->assertDatabaseHas('meal_templates', ['profile_id' => $profile->id, 'name' => 'De Cecco Fusilli', 'calories' => 300]);
        // … and NOT logged to today.
        $this->assertDatabaseCount('meals', 0);
        $this->assertStringContainsString('Saved', $response->json('reply'));
        $this->assertStringContainsString('De Cecco Fusilli', $response->json('reply'));
        // A saved reference isn't marked as eaten.
        $this->assertSame(0, (int) $profile->mealTemplates()->first()->times_logged);
    }

    public function test_an_explicit_eat_caption_still_logs_even_with_a_label(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $user->ensureProfile();

        // Vision says "save" but the user clearly ate it — the caption wins back to logging.
        $vision = Mockery::mock(AiService::class);
        $vision->shouldReceive('vision')->once()->andReturn(json_encode([
            'kind' => 'meal', 'intent' => 'save',
            'meal' => ['name' => 'Fusilli bowl', 'calories' => 300, 'protein_g' => 12, 'carbs_g' => 61, 'fat_g' => 1.5],
        ]));
        $this->app->instance(AiService::class, $vision);

        $this->actingAs($user)->post('/coach/scan', [
            'photo' => UploadedFile::fake()->image('lunch.jpg'),
            'message' => 'just had this for lunch',
        ])->assertOk()->assertJson(['kind' => 'meal', 'logged' => true]);

        $this->assertDatabaseHas('meals', ['name' => 'Fusilli bowl']);
    }

    public function test_a_bloodwork_photo_logs_each_marker(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $user->ensureProfile();

        $vision = Mockery::mock(AiService::class);
        $vision->shouldReceive('vision')->once()->andReturn(json_encode([
            'kind' => 'bloodwork',
            'bloodwork' => [
                ['marker' => 'LDL', 'value' => 130, 'unit' => 'mg/dL'],
                ['marker' => 'hba1c', 'value' => 5.4, 'unit' => '%'],
            ],
        ]));
        $this->app->instance(AiService::class, $vision);

        $response = $this->actingAs($user)->post('/coach/scan', [
            'photo' => UploadedFile::fake()->image('labs.jpg'),
        ]);

        $response->assertOk()->assertJson(['ok' => true, 'kind' => 'bloodwork', 'logged' => true]);
        $this->assertDatabaseHas('biomarker_readings', ['marker' => 'ldl', 'source' => 'photo']);
        $this->assertDatabaseHas('biomarker_readings', ['marker' => 'hba1c', 'source' => 'photo']);
    }

    public function test_a_body_photo_is_saved_as_a_progress_photo_for_the_physique_render(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $user->ensureProfile();

        $vision = Mockery::mock(AiService::class);
        $vision->shouldReceive('vision')->once()->andReturn(json_encode(['kind' => 'physique', 'note' => 'A front-facing physique photo.']));
        $this->app->instance(AiService::class, $vision);

        $response = $this->actingAs($user)->post('/coach/scan', [
            'photo' => UploadedFile::fake()->image('me.jpg'),
        ]);

        $response->assertOk()->assertJson(['ok' => true, 'kind' => 'physique', 'logged' => true]);
        $this->assertDatabaseHas('progress_photos', ['profile_id' => $user->profile->id]);
    }

    public function test_a_missing_or_invalid_photo_gets_a_friendly_reply(): void
    {
        $user = User::factory()->create();
        $user->ensureProfile();

        // No raw 422 / redirect — a clear, in-chat message the composer can show.
        $this->actingAs($user)->postJson('/coach/scan', [])
            ->assertOk()
            ->assertJson(['ok' => false])
            ->assertJsonStructure(['reply']);
    }
}
