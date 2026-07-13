<?php

namespace Tests\Unit;

use App\Support\Macros;
use PHPUnit\Framework\TestCase;

/**
 * A logged meal's macros must reconcile with its calories (review d987a9d) — a fast log that captured
 * "470 kcal, 37g protein" and left carbs/fat at 0 both broke the meal card's chips and undercounted the
 * day's carbs/fat. `Macros::reconcile` is a soft guardrail: never blocks a log, just refuses to STORE
 * macros that contradict the calories. Pure (no DB), like SleepStoryTest.
 */
class MacrosReconcileTest extends TestCase
{
    private function kcal(array $m): float
    {
        return 4 * $m['protein_g'] + 4 * $m['carbs_g'] + 9 * $m['fat_g'];
    }

    public function test_henrys_bad_shake_gets_its_missing_carbs_and_fat_filled(): void
    {
        // 470 kcal, 37P, 0C, 0F → 148 kcal accounted, +322 gap. Must fill C+F so it reconciles.
        $r = Macros::reconcile(470, 37, 0, 0);

        $this->assertSame(470, $r['calories']);          // the calorie anchor is kept
        $this->assertSame(37.0, $r['protein_g']);        // the given macro is untouched
        $this->assertGreaterThan(0, $r['carbs_g']);      // no more bare 0
        $this->assertGreaterThan(0, $r['fat_g']);
        $this->assertEqualsWithDelta(470, $this->kcal($r), 1.0);   // reconciles with calories
    }

    public function test_a_consistent_meal_passes_through_untouched(): void
    {
        // 3 pork tacos: 720 kcal, 36/54/36 → 684, within rounding tolerance. Leave exactly as logged.
        $r = Macros::reconcile(720, 36, 54, 36);
        $this->assertSame(720, $r['calories']);
        $this->assertSame(36.0, $r['protein_g']);
        $this->assertSame(54.0, $r['carbs_g']);
        $this->assertSame(36.0, $r['fat_g']);
    }

    public function test_a_single_missing_macro_is_back_solved_exactly(): void
    {
        // Only fat missing: 500 kcal, 40P (160) + 40C (160) = 320 → the 180 gap is exactly 20g fat.
        $r = Macros::reconcile(500, 40, 40, 0);
        $this->assertSame(500, $r['calories']);
        $this->assertEqualsWithDelta(20.0, $r['fat_g'], 0.2);
        $this->assertEqualsWithDelta(500, $this->kcal($r), 1.0);
    }

    public function test_only_calories_given_falls_back_to_a_balanced_split(): void
    {
        // A bare "500 kcal snack" with no macros → a default split, still reconciling + no zeros.
        $r = Macros::reconcile(500, 0, 0, 0);
        $this->assertSame(500, $r['calories']);
        $this->assertGreaterThan(0, $r['protein_g']);
        $this->assertGreaterThan(0, $r['carbs_g']);
        $this->assertGreaterThan(0, $r['fat_g']);
        $this->assertEqualsWithDelta(500, $this->kcal($r), 2.0);
    }

    public function test_no_calorie_anchor_derives_calories_from_macros(): void
    {
        // calories 0 but macros present → energy comes from the macros (30*4 + 20*4 + 10*9 = 290).
        $r = Macros::reconcile(0, 30, 20, 10);
        $this->assertSame(290, $r['calories']);
        $this->assertSame(30.0, $r['protein_g']);
    }

    public function test_fully_specified_but_contradictory_calories_trust_the_macros(): void
    {
        // All macros given (none at 0) but calories way off → derive calories from the breakdown.
        // 40P + 30C + 20F = 4·40 + 4·30 + 9·20 = 160 + 120 + 180 = 460, not the claimed 900.
        $r = Macros::reconcile(900, 40, 30, 20);
        $this->assertSame(460, $r['calories']);
        $this->assertSame(30.0, $r['carbs_g']);   // macros left intact
    }
}
