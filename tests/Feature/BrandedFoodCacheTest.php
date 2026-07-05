<?php

namespace Tests\Feature;

use App\Models\BrandedFood;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The shared branded-product cache: a packaged item's per-serving label is researched (or read off a
 * nutrition-facts photo) ONCE, then every later scan of the same product is an instant, free cache hit.
 */
class BrandedFoodCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_miss_then_remember_then_hit(): void
    {
        $this->assertNull(BrandedFood::lookup('Chobani', 'Non-Fat Greek Yogurt, Vanilla'));

        BrandedFood::remember('Chobani', 'Non-Fat Greek Yogurt, Vanilla', [
            'calories' => 120, 'protein_g' => 16, 'carbs_g' => 13, 'fat_g' => 0, 'serving' => '1 cup (150 g)',
        ], 'label');

        $hit = BrandedFood::lookup('Chobani', 'Non-Fat Greek Yogurt, Vanilla');
        $this->assertNotNull($hit);
        $this->assertSame(120, $hit->calories);
        $this->assertSame('1 cup (150 g)', $hit->serving);
        $this->assertSame(1, $hit->hits);   // the lookup bumped the counter
    }

    public function test_key_is_normalized_across_brand_and_capitalization(): void
    {
        BrandedFood::remember('MyProtein', 'Impact Whey Protein', [
            'calories' => 103, 'protein_g' => 21, 'carbs_g' => 1, 'fat_g' => 1.9,
        ], 'web');

        // Same product, different casing/spacing → same cached row (no duplicate research).
        $hit = BrandedFood::lookup('myprotein', 'impact  whey protein');
        $this->assertNotNull($hit);
        $this->assertSame(1, BrandedFood::count());
    }

    public function test_a_label_read_refreshes_a_prior_web_estimate(): void
    {
        BrandedFood::remember('Clif', 'Bar Chocolate Chip', ['calories' => 250, 'protein_g' => 9, 'carbs_g' => 45, 'fat_g' => 5], 'web');
        // Later, a clear nutrition-facts photo corrects it in place (same key).
        BrandedFood::remember('Clif', 'Bar Chocolate Chip', ['calories' => 260, 'protein_g' => 10, 'carbs_g' => 44, 'fat_g' => 6], 'label');

        $this->assertSame(1, BrandedFood::count());
        $this->assertSame(260, BrandedFood::lookup('Clif', 'Bar Chocolate Chip')->calories);
    }

    public function test_empty_or_calorieless_is_not_cached(): void
    {
        $this->assertNull(BrandedFood::remember('', '', ['calories' => 100, 'protein_g' => 1, 'carbs_g' => 1, 'fat_g' => 1]));
        $this->assertNull(BrandedFood::remember('Brand', 'Product', ['calories' => 0, 'protein_g' => 0, 'carbs_g' => 0, 'fat_g' => 0]));
        $this->assertSame(0, BrandedFood::count());
    }
}
