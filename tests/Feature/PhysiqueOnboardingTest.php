<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Ai\NanoBananaClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class PhysiqueOnboardingTest extends TestCase
{
    use RefreshDatabase;

    /** A fake Nano Banana that records the angle each prompt was built for, no network. */
    private function fakeNano(): array
    {
        $calls = new \ArrayObject;
        $fake = new class($calls) extends NanoBananaClient
        {
            public function __construct(public \ArrayObject $calls) {}

            public function imageFromDisk(string $path, string $disk = 'public'): array
            {
                return ['bytes' => 'x', 'mime' => 'image/jpeg'];
            }

            public function generateToDisk(string $prompt, string $dir = 'physique', array $inputImages = []): array
            {
                $this->calls[] = $prompt;
                $n = count($this->calls);

                return ['path' => "physique/goal/test-{$n}.png", 'url' => "/storage/physique/goal/test-{$n}.png", 'mime' => 'image/png'];
            }
        };
        // The physique flow resolves the ImageGenerator contract (bound to OpenAI by default).
        $this->app->instance(\App\Services\Ai\ImageGenerator::class, $fake);
        $this->app->instance(NanoBananaClient::class, $fake);

        return [$fake, $calls];
    }

    public function test_front_creates_the_goal_then_back_appends_to_the_same_goal(): void
    {
        [$fake, $calls] = $this->fakeNano();
        $user = User::factory()->create();
        $user->ensureProfile();

        // 1 · Front → creates the active goal, sets it as the primary shot.
        $front = $this->actingAs($user)->postJson('/onboarding/physique', [
            'photo' => UploadedFile::fake()->image('front.jpg'),
            'angle' => 'front',
            'sex' => 'F',
            'description' => 'Goal: Get lean. Focus: Rounder glutes',
        ])->assertOk()->assertJson(['ok' => true, 'angle' => 'front'])->json();

        $goalId = $front['goal_id'];
        $goal = $user->profile->physiqueGoals()->find($goalId);
        $this->assertTrue($goal->is_active);
        $this->assertNotNull($goal->goal_image_path);                 // primary set from front
        $this->assertCount(1, $goal->shots);
        $this->assertSame('front', $goal->shots[0]['angle']);

        // 2 · Back → appends to the SAME goal, doesn't create a new one.
        $this->actingAs($user)->postJson('/onboarding/physique', [
            'photo' => UploadedFile::fake()->image('back.jpg'),
            'angle' => 'back',
            'sex' => 'F',
            'goal_id' => $goalId,
        ])->assertOk()->assertJson(['ok' => true, 'goal_id' => $goalId, 'angle' => 'back']);

        $this->assertSame(1, $user->profile->physiqueGoals()->count());   // still one goal
        $goal->refresh();
        $this->assertCount(2, $goal->shots);

        // shotUrls() is ordered front → back and exposes both renders.
        $urls = $goal->shotUrls();
        $this->assertSame(['front', 'back'], array_column($urls, 'angle'));
        $this->assertNotNull($urls[1]['goal_url']);

        // The back prompt was angle-specific (mentions a back view), proving the angle steers it.
        $this->assertStringContainsString('back view', strtolower($calls[1]));
    }

    public function test_primary_shot_stays_the_front_after_a_back_render(): void
    {
        $this->fakeNano();
        $user = User::factory()->create();
        $user->ensureProfile();

        $front = $this->actingAs($user)->postJson('/onboarding/physique', [
            'photo' => UploadedFile::fake()->image('front.jpg'), 'angle' => 'front', 'sex' => 'M',
        ])->json();
        $primary = $user->profile->physiqueGoals()->find($front['goal_id'])->goal_image_path;

        $this->actingAs($user)->postJson('/onboarding/physique', [
            'photo' => UploadedFile::fake()->image('back.jpg'), 'angle' => 'back', 'sex' => 'M', 'goal_id' => $front['goal_id'],
        ])->assertOk();

        // The primary (front) image the rest of the app reads is unchanged by the back render.
        $this->assertSame($primary, $user->profile->physiqueGoals()->find($front['goal_id'])->goal_image_path);
    }
}
