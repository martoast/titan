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

    public function test_a_photo_requires_an_image(): void
    {
        $user = User::factory()->create();
        $user->ensureProfile();

        $this->actingAs($user)->post('/coach/scan', [])->assertSessionHasErrors('photo');
    }
}
