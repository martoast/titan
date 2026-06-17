<?php

namespace Tests\Feature;

use App\Models\FoodFact;
use App\Models\User;
use App\Services\Ai\AiService;
use App\Services\Coach\CoachTools;
use App\Support\FoodLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class FoodLibraryTest extends TestCase
{
    use RefreshDatabase;

    public function test_normalize_strips_portions_keeps_the_food(): void
    {
        $this->assertSame('grilled chicken breast', FoodLibrary::normalize('8 oz of grilled chicken breast'));
        $this->assertSame('banana', FoodLibrary::normalize('a medium banana'));
        $this->assertSame('cooked white rice', FoodLibrary::normalize('1 cup cooked white rice'));
        $this->assertSame('eggs', FoodLibrary::normalize('2 large eggs'));
    }

    public function test_cache_hit_returns_without_touching_web_or_ai(): void
    {
        FoodFact::create(['name' => 'banana', 'basis' => '100g', 'calories' => 89, 'protein_g' => 1.1, 'carbs_g' => 23, 'fat_g' => 0.3, 'source' => 'usda', 'hits' => 2]);

        // AI must NOT be called on a cache hit; web is faked to fail loudly if hit.
        $ai = Mockery::mock(AiService::class);
        $ai->shouldReceive('configured')->andReturn(true);
        $ai->shouldNotReceive('json');
        $this->app->instance(AiService::class, $ai);
        Http::fake(['serpapi.com/*' => Http::response([], 500)]);
        config(['services.serpapi.key' => 'k']);

        $r = app(FoodLibrary::class)->lookup('a medium banana');

        $this->assertTrue($r['ok']);
        $this->assertTrue($r['cached']);
        $this->assertSame(89, $r['calories']);
        $this->assertSame(3, FoodFact::where('name', 'banana')->first()->hits);   // hit counter bumped
    }

    public function test_cache_miss_researches_once_then_caches(): void
    {
        config(['services.serpapi.key' => 'k']);
        Http::fake([
            'serpapi.com/*' => Http::response([
                'answer_box' => ['answer' => 'Oats: 389 calories per 100g'],
                'organic_results' => [['title' => 'USDA', 'link' => 'https://usda.gov/oats', 'snippet' => 'Oats per 100g: 389 cal, 16.9g protein, 66g carbs, 6.9g fat']],
            ]),
        ]);
        $ai = Mockery::mock(AiService::class);
        $ai->shouldReceive('configured')->andReturn(true);
        $ai->shouldReceive('json')->once()
            ->andReturn(['calories' => 389, 'protein_g' => 16.9, 'carbs_g' => 66, 'fat_g' => 6.9, 'source' => 'USDA']);
        $this->app->instance(AiService::class, $ai);

        $first = app(FoodLibrary::class)->lookup('1 cup of oats');
        $this->assertTrue($first['ok']);
        $this->assertFalse($first['cached']);
        $this->assertSame(389, $first['calories']);
        $this->assertDatabaseHas('food_facts', ['name' => 'oats', 'calories' => 389]);

        // Second lookup is a cache hit — the AI ->json was only allowed once.
        $second = app(FoodLibrary::class)->lookup('a bowl of oats');
        $this->assertTrue($second['cached']);
    }

    public function test_lookup_food_tool_returns_per_basis_macros(): void
    {
        FoodFact::create(['name' => 'almonds', 'basis' => '100g', 'calories' => 579, 'protein_g' => 21, 'carbs_g' => 22, 'fat_g' => 50, 'source' => 'usda', 'hits' => 0]);
        config(['services.serpapi.key' => 'k']);

        $res = (new CoachTools(User::factory()->create()->ensureProfile()))->dispatch('lookup_food', ['food' => '30g almonds']);
        $this->assertSame(579, $res['calories']);
        $this->assertSame('100g', $res['per']);
        $this->assertStringContainsString('cached', $res['source']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
