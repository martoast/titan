<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\BrandedFood;
use App\Models\FoodFact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Barcode scanning: a GTIN → Open Food Facts → a draft to confirm. Cached by barcode (instant 2nd scan)
 * and seeded into the coach's food knowledge (so "I made a shake with that whey" needs no lookup).
 */
class BarcodeScanTest extends TestCase
{
    use RefreshDatabase;

    private function auth(): array
    {
        $user = User::factory()->create();
        $profile = $user->ensureProfile();
        [, $token] = ApiToken::mint($user, 'ios', ['*']);

        return [$profile, $token];
    }

    private function fakeOff(): void
    {
        Http::fake(['*world.openfoodfacts.org*' => Http::response([
            'status' => 1,
            'product' => [
                'product_name' => 'Impact Whey Protein, Chocolate',
                'brands' => 'MyProtein, THE Whey',
                'serving_size' => '25 g',
                'image_front_small_url' => 'https://images.openfoodfacts.org/x.jpg',
                'nutriments' => [
                    'energy-kcal_100g' => 412, 'proteins_100g' => 82, 'carbohydrates_100g' => 6, 'fat_100g' => 7.5,
                    'energy-kcal_serving' => 103, 'proteins_serving' => 20.5, 'carbohydrates_serving' => 1.5, 'fat_serving' => 1.9,
                ],
            ],
        ], 200)]);
    }

    public function test_scan_returns_a_confirm_draft_from_open_food_facts(): void
    {
        [, $token] = $this->auth();
        $this->fakeOff();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/me/nutrition/barcode', ['code' => '5055534303881'])
            ->assertOk()
            ->assertJsonPath('kind', 'meal')
            ->assertJsonPath('draft.name', 'Impact Whey Protein, Chocolate')
            ->assertJsonPath('draft.brand', 'MyProtein')          // first listed brand
            ->assertJsonPath('draft.source', 'barcode')
            ->assertJsonPath('draft.calories', 103)               // per-serving preferred
            ->assertJsonPath('draft.protein_g', 20.5);
    }

    public function test_scan_caches_by_barcode_and_seeds_coach_food_knowledge(): void
    {
        [, $token] = $this->auth();
        $this->fakeOff();
        $h = $this->withHeader('Authorization', "Bearer {$token}");

        $h->postJson('/api/me/nutrition/barcode', ['code' => '5055534303881'])->assertOk();

        // Cached by barcode (per-serving) + a shared FoodFact (per-100g) the coach can look up.
        $this->assertSame(1, BrandedFood::where('barcode', '5055534303881')->count());
        $fact = FoodFact::whereNull('profile_id')->where('name', 'like', '%whey%')->first();
        $this->assertNotNull($fact);
        $this->assertSame(412, $fact->calories);                 // per-100g seeded for grounding

        // Second scan is a cache hit — no further HTTP to Open Food Facts.
        Http::fake(['*openfoodfacts*' => Http::response([], 500)]);   // would fail if it hit the network
        $h->postJson('/api/me/nutrition/barcode', ['code' => '5055534303881'])
            ->assertOk()->assertJsonPath('draft.calories', 103);
        $this->assertSame(1, BrandedFood::where('barcode', '5055534303881')->first()->hits);   // one cache hit
    }

    public function test_unknown_barcode_falls_back(): void
    {
        [, $token] = $this->auth();
        Http::fake(['*openfoodfacts*' => Http::response(['status' => 0], 200)]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/me/nutrition/barcode', ['code' => '0000000000000'])
            ->assertOk()
            ->assertJsonPath('kind', 'other')
            ->assertJsonPath('draft', null);
    }

    public function test_invalid_code_is_rejected(): void
    {
        [, $token] = $this->auth();
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/me/nutrition/barcode', ['code' => 'not-a-barcode'])
            ->assertStatus(422);
    }
}
