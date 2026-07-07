<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\User;
use App\Services\Ai\AiService;
use App\Services\Coach\CoachService;
use App\Services\Coach\CoachTools;
use App\Services\Coach\ScanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * The snap-to-log VISION pipeline (ScanService) — meal/bloodwork/physique classification + logging —
 * tested at the service + tool layer. Since the coach hybrid (the coach SEES the photo and calls
 * scan_photo to log it), classification lives behind the tool, so it's tested there; the /coach/scan
 * endpoint's job is now just to wire the photo turn into the hybrid coach (tested separately below).
 */
class CoachScanTest extends TestCase
{
    use RefreshDatabase;

    private function visionReturns(array $json): void
    {
        $vision = Mockery::mock(AiService::class);
        $vision->shouldReceive('vision')->once()->andReturn(json_encode($json));
        $this->app->instance(AiService::class, $vision);
    }

    public function test_a_meal_photo_is_logged_with_macros(): void
    {
        Storage::fake('public');
        $profile = User::factory()->create()->ensureProfile();
        $this->visionReturns([
            'kind' => 'meal',
            'meal' => ['name' => 'Chicken & rice', 'calories' => 620, 'protein_g' => 48, 'carbs_g' => 55, 'fat_g' => 18, 'confidence' => 'medium'],
        ]);

        $result = app(ScanService::class)->scan($profile, UploadedFile::fake()->image('plate.jpg'));

        $this->assertSame('meal', $result['kind']);
        $this->assertTrue($result['logged']);
        $this->assertDatabaseHas('meals', ['name' => 'Chicken & rice', 'calories' => 620, 'source' => 'photo']);
        // The reply leads with the updated macros card.
        $this->assertStringContainsString('titan-card', $result['reply']);
        $this->assertStringContainsString('macros', $result['reply']);
    }

    public function test_scan_photo_tool_is_offered_only_when_a_photo_is_attached(): void
    {
        $profile = User::factory()->create()->ensureProfile();
        $names = fn (array $schemas) => array_column(array_column($schemas, 'function'), 'name');

        // Hybrid: with a photo on the turn the coach gets the scan_photo logging tool; without one it doesn't.
        $withPhoto = (new CoachTools($profile, null, 'coach/scans/x.jpg'))->schemas();
        $noPhoto = (new CoachTools($profile, null, null))->schemas();

        $this->assertContains('scan_photo', $names($withPhoto));
        $this->assertNotContains('scan_photo', $names($noPhoto));
    }

    public function test_scan_photo_tool_runs_the_pipeline_and_logs_a_meal(): void
    {
        Storage::fake('public');
        $profile = User::factory()->create()->ensureProfile();
        $path = UploadedFile::fake()->image('plate.jpg')->store('coach/scans', 'public');
        $this->visionReturns([
            'kind' => 'meal',
            'meal' => ['name' => 'Oats', 'calories' => 320, 'protein_g' => 12, 'carbs_g' => 55, 'fat_g' => 6, 'confidence' => 'high'],
        ]);

        // The coach, having SEEN the photo, calls scan_photo to log it accurately via the pipeline.
        $result = (new CoachTools($profile, null, $path))->dispatch('scan_photo', []);

        $this->assertSame('meal', $result['kind']);
        $this->assertTrue($result['logged']);
        $this->assertDatabaseHas('meals', ['name' => 'Oats', 'calories' => 320, 'source' => 'photo']);
    }

    public function test_a_caption_sharpens_the_vision_read(): void
    {
        Storage::fake('public');
        $profile = User::factory()->create()->ensureProfile();

        $vision = Mockery::mock(AiService::class);
        $vision->shouldReceive('vision')->once()
            ->with(Mockery::on(fn ($p) => str_contains($p, 'a 12oz steak')), Mockery::any(), Mockery::any())
            ->andReturn(json_encode(['kind' => 'meal', 'meal' => ['name' => 'Steak', 'calories' => 700, 'protein_g' => 55, 'carbs_g' => 0, 'fat_g' => 50]]));
        $this->app->instance(AiService::class, $vision);

        $result = app(ScanService::class)->scan($profile, UploadedFile::fake()->image('dinner.jpg'), 'this is what I ate, a 12oz steak');
        $this->assertSame('meal', $result['kind']);
    }

    public function test_a_label_with_save_intent_is_remembered_not_logged(): void
    {
        Storage::fake('public');
        $profile = User::factory()->create()->ensureProfile();

        // A nutrition-facts label + "add this to the pastas I make" → SAVE as a reference, don't log.
        $this->visionReturns([
            'kind' => 'meal',
            'intent' => 'save',
            'meal' => ['name' => 'De Cecco Fusilli', 'calories' => 300, 'protein_g' => 12, 'carbs_g' => 61,
                'fat_g' => 1.5, 'is_label' => true, 'brand' => 'De Cecco', 'product' => 'Fusilli no. 34', 'serving_hint' => '1/5 package (85 g)'],
        ]);

        $result = app(ScanService::class)->scan($profile, UploadedFile::fake()->image('fusilli.jpg'), 'Add this to one of the kinds of pasta that I make');

        $this->assertSame('meal_saved', $result['kind']);
        $this->assertFalse($result['logged']);
        $this->assertDatabaseHas('meal_templates', ['profile_id' => $profile->id, 'name' => 'De Cecco Fusilli', 'calories' => 300]);
        $this->assertDatabaseCount('meals', 0);                 // NOT logged to today
        $this->assertStringContainsString('Saved', $result['reply']);
        $this->assertSame(0, (int) $profile->mealTemplates()->first()->times_logged);
    }

    public function test_an_explicit_eat_caption_still_logs_even_with_a_label(): void
    {
        Storage::fake('public');
        $profile = User::factory()->create()->ensureProfile();

        // Vision says "save" but the user clearly ate it — the caption wins back to logging.
        $this->visionReturns([
            'kind' => 'meal', 'intent' => 'save',
            'meal' => ['name' => 'Fusilli bowl', 'calories' => 300, 'protein_g' => 12, 'carbs_g' => 61, 'fat_g' => 1.5],
        ]);

        $result = app(ScanService::class)->scan($profile, UploadedFile::fake()->image('lunch.jpg'), 'just had this for lunch');

        $this->assertSame('meal', $result['kind']);
        $this->assertTrue($result['logged']);
        $this->assertDatabaseHas('meals', ['name' => 'Fusilli bowl']);
    }

    public function test_a_bloodwork_photo_logs_each_marker(): void
    {
        Storage::fake('public');
        $profile = User::factory()->create()->ensureProfile();
        $this->visionReturns([
            'kind' => 'bloodwork',
            'bloodwork' => [
                ['marker' => 'LDL', 'value' => 130, 'unit' => 'mg/dL'],
                ['marker' => 'hba1c', 'value' => 5.4, 'unit' => '%'],
            ],
        ]);

        $result = app(ScanService::class)->scan($profile, UploadedFile::fake()->image('labs.jpg'));

        $this->assertSame('bloodwork', $result['kind']);
        $this->assertTrue($result['logged']);
        $this->assertDatabaseHas('biomarker_readings', ['marker' => 'ldl', 'source' => 'photo']);
        $this->assertDatabaseHas('biomarker_readings', ['marker' => 'hba1c', 'source' => 'photo']);
    }

    public function test_a_body_photo_is_saved_as_a_progress_photo_for_the_physique_render(): void
    {
        Storage::fake('public');
        $profile = User::factory()->create()->ensureProfile();
        $this->visionReturns(['kind' => 'physique', 'note' => 'A front-facing physique photo.']);

        $result = app(ScanService::class)->scan($profile, UploadedFile::fake()->image('me.jpg'));

        $this->assertSame('physique', $result['kind']);
        $this->assertTrue($result['logged']);
        $this->assertDatabaseHas('progress_photos', ['profile_id' => $profile->id]);
    }

    public function test_scan_endpoint_runs_the_hybrid_coach_and_persists_the_exchange(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $profile = $user->ensureProfile();

        // scan() now wires the photo turn into the HYBRID coach (which SEES the image). Mock the coach so
        // the test doesn't hit the model; assert scan() banks the photo, runs it, and returns its reply.
        $coach = Mockery::mock(CoachService::class);
        $coach->shouldReceive('generate')->once()
            ->andReturnUsing(fn ($c, $p, ChatMessage $m, $text, $img = null) => $m->update([
                'content' => 'That looks like grilled salmon and greens — about 480 kcal. Logged it.',
                'status' => ChatMessage::STATUS_COMPLETE,
            ]));
        $this->app->instance(CoachService::class, $coach);

        $res = $this->actingAs($user)->post('/coach/scan', [
            'photo' => UploadedFile::fake()->image('dinner.jpg'),
            'message' => 'what is this?',
        ]);

        $res->assertOk()->assertJson(['ok' => true]);
        $this->assertStringContainsString('grilled salmon', $res->json('reply'));
        $conv = $profile->conversations()->latest('id')->first();
        $this->assertStringContainsString('![photo](', (string) $conv->messages()->where('role', 'user')->first()->content);
        $this->assertDatabaseHas('chat_messages', ['role' => 'assistant', 'status' => ChatMessage::STATUS_COMPLETE]);
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
