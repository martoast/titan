<?php

namespace Tests\Unit;

use App\Support\FoodSearch;
use PHPUnit\Framework\TestCase;

/**
 * Native food-search ranking (MEAL_LOGGING_REVISION 3.1) — the match-quality + popularity ordering that
 * turns the caches into a useful search. Pure, no DB.
 */
class FoodSearchTest extends TestCase
{
    public function test_match_score_tiers(): void
    {
        $this->assertSame(1.0, FoodSearch::matchScore('Chicken breast', 'chicken breast'));   // exact (norm)
        $this->assertSame(0.85, FoodSearch::matchScore('Chicken breast grilled', 'chicken'));  // prefix
        $this->assertSame(0.7, FoodSearch::matchScore('Grilled chicken breast', 'chicken'));    // contains
        $this->assertSame(0.0, FoodSearch::matchScore('Salmon fillet', 'chicken'));             // miss
    }

    public function test_token_overlap_partial(): void
    {
        // "greek yogurt" vs "yogurt, greek nonfat" — both tokens present, not a substring → token score.
        $s = FoodSearch::matchScore('Yogurt nonfat greek', 'greek yogurt');
        $this->assertGreaterThan(0.0, $s);
        $this->assertLessThan(0.7, $s);
    }

    public function test_rank_drops_non_matches_and_orders_best_first(): void
    {
        $rows = [
            ['name' => 'Salmon', 'popularity' => 100],           // no match → dropped
            ['name' => 'Chicken thigh', 'popularity' => 1],       // contains
            ['name' => 'Chicken', 'popularity' => 3],             // exact
        ];
        $out = FoodSearch::rank($rows, 'chicken');
        $this->assertCount(2, $out);
        $this->assertSame('Chicken', $out[0]['name']);            // exact beats contains
    }

    public function test_popularity_breaks_ties(): void
    {
        // Same match tier (both prefix) → the more-logged one ranks first.
        $rows = [
            ['name' => 'Chicken rare', 'popularity' => 0],
            ['name' => 'Chicken common', 'popularity' => 500],
        ];
        $out = FoodSearch::rank($rows, 'chicken');
        $this->assertSame('Chicken common', $out[0]['name']);
    }

    public function test_short_query_via_rank_still_works_but_forProfile_guards(): void
    {
        // rank itself doesn't guard length (forProfile does); a 1-char query still scores structurally.
        $this->assertSame(0.85, FoodSearch::matchScore('Apple', 'a'));
    }
}
