<?php

namespace Tests\Unit;

use App\Support\MealItems;
use PHPUnit\Framework\TestCase;

/**
 * Per-item meal math (MEAL_LOGGING_REVISION 3.2) — the sum that makes the editable-item photo flow
 * trustworthy, and the cleaner that turns raw vision/client lines into safe rows. Pure, no DB.
 */
class MealItemsTest extends TestCase
{
    public function test_totals_sum_the_lines(): void
    {
        $t = MealItems::totals([
            ['name' => 'Eggs', 'calories' => 140, 'protein_g' => 12, 'carbs_g' => 1, 'fat_g' => 10],
            ['name' => 'Oats', 'calories' => 150, 'protein_g' => 5, 'carbs_g' => 27, 'fat_g' => 3, 'fiber_g' => 4],
        ]);
        $this->assertSame(290, $t['calories']);
        $this->assertSame(17.0, $t['protein_g']);
        $this->assertSame(28.0, $t['carbs_g']);
        $this->assertSame(13.0, $t['fat_g']);
        $this->assertSame(4.0, $t['fiber_g']);
    }

    public function test_fiber_is_null_when_no_line_reports_it(): void
    {
        $t = MealItems::totals([['name' => 'Steak', 'calories' => 300, 'protein_g' => 40, 'carbs_g' => 0, 'fat_g' => 15]]);
        $this->assertNull($t['fiber_g']);
    }

    public function test_clean_drops_blank_names_and_clamps(): void
    {
        $rows = MealItems::clean([
            ['name' => '  Rice  ', 'quantity' => '1 cup', 'calories' => 200, 'protein_g' => 4, 'carbs_g' => 45, 'fat_g' => -1],
            ['name' => '', 'calories' => 999],          // blank name → dropped
            'just a string',                             // legacy string item → dropped (no macros)
        ]);
        $this->assertCount(1, $rows);
        $this->assertSame('Rice', $rows[0]['name']);
        $this->assertSame('1 cup', $rows[0]['quantity']);
        $this->assertSame(0.0, $rows[0]['fat_g']);       // negative clamped
        $this->assertNull($rows[0]['fiber_g']);
    }

    public function test_clean_of_non_array_is_empty(): void
    {
        $this->assertSame([], MealItems::clean(['Eggs', 'Toast']));   // all legacy strings → no editable lines
        $this->assertSame([], MealItems::clean(null));
    }
}
