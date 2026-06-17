<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Coach\CoachTools;
use App\Support\FoodDiary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class FoodDiaryTest extends TestCase
{
    use RefreshDatabase;

    private function logFood(\App\Models\Profile $p, string $name, int $cal, float $pro, Carbon $when): void
    {
        $p->meals()->create(['eaten_at' => $when, 'name' => $name, 'calories' => $cal, 'protein_g' => $pro, 'source' => 'manual']);
    }

    public function test_top_foods_groups_by_normalized_name_and_ranks_by_frequency(): void
    {
        $p = User::factory()->create()->ensureProfile();
        // "chicken and rice" eaten 3 times (with portion variations), oatmeal twice, salmon once.
        $this->logFood($p, '8 oz chicken and rice', 600, 50, Carbon::now()->subDays(1));
        $this->logFood($p, 'chicken and rice', 620, 52, Carbon::now()->subDays(2));
        $this->logFood($p, 'a plate of chicken and rice', 590, 49, Carbon::now()->subDays(4));
        $this->logFood($p, 'oatmeal', 300, 10, Carbon::now()->subDays(1));
        $this->logFood($p, 'oatmeal', 310, 11, Carbon::now()->subDays(3));
        $this->logFood($p, 'grilled salmon', 400, 40, Carbon::now()->subDays(5));

        $top = FoodDiary::topFoods($p);

        $this->assertSame('chicken and rice', $top[0]['food']);   // most-common spelling
        $this->assertSame(3, $top[0]['count']);                   // grouped across portion phrasings
        $this->assertSame('oatmeal', $top[1]['food']);
        $this->assertSame(2, $top[1]['count']);
        $this->assertGreaterThan(0, $top[0]['avg_protein_g']);
        $this->assertNotNull($top[0]['last_eaten']);
    }

    public function test_tool_returns_top_foods_else_guides(): void
    {
        $p = User::factory()->create()->ensureProfile();
        $this->assertArrayHasKey('note', (new CoachTools($p))->dispatch('my_foods', []));   // empty → guidance

        $this->logFood($p, 'eggs', 150, 12, Carbon::now());
        $res = (new CoachTools($p))->dispatch('my_foods', []);
        $this->assertSame('eggs', $res['top_foods'][0]['food']);
        $this->assertStringContainsString('reference', $res['_show']);
    }

    public function test_foods_page_renders(): void
    {
        $u = User::factory()->create();
        $this->logFood($u->ensureProfile(), 'protein shake', 200, 30, Carbon::now());
        $this->actingAs($u)->get('/foods')->assertOk()->assertSee('protein shake');
    }
}
