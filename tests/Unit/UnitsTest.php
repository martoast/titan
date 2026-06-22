<?php

namespace Tests\Unit;

use App\Models\Profile;
use App\Support\Units;
use PHPUnit\Framework\TestCase;

class UnitsTest extends TestCase
{
    private function profile(string $units): Profile
    {
        $p = new Profile;
        $p->settings = ['units' => $units];

        return $p;
    }

    public function test_metric_passes_weight_through_unchanged(): void
    {
        $m = $this->profile('metric');
        $this->assertSame('kg', Units::weightUnit($m));
        $this->assertSame(80.5, Units::weightOut(80.5, $m));
        $this->assertSame(80.5, Units::weightIn(80.5, $m));
        $this->assertSame('80.5 kg', Units::weight(80.5, $m));
    }

    public function test_imperial_converts_weight_both_ways(): void
    {
        $i = $this->profile('imperial');
        $this->assertSame('lb', Units::weightUnit($i));
        // 82.6 kg → ~182.1 lb
        $this->assertSame(182.1, Units::weightOut(82.6, $i));
        // 182.1 lb → ~82.6 kg (round-trips)
        $this->assertEqualsWithDelta(82.6, Units::weightIn(182.1, $i), 0.05);
        $this->assertSame('182.1 lb', Units::weight(82.6, $i));
    }

    public function test_imperial_converts_length_both_ways(): void
    {
        $i = $this->profile('imperial');
        $this->assertSame('in', Units::lengthUnit($i));
        // 81 cm → ~31.9 in
        $this->assertSame(31.9, Units::lengthOut(81.0, $i));
        // 32 in → ~81.3 cm
        $this->assertEqualsWithDelta(81.3, Units::lengthIn(32.0, $i), 0.1);
        $this->assertSame('31.9 in', Units::length(81.0, $i));
    }

    public function test_metric_length_unchanged(): void
    {
        $m = $this->profile('metric');
        $this->assertSame('cm', Units::lengthUnit($m));
        $this->assertSame(81.0, Units::lengthOut(81.0, $m));
        $this->assertSame('81 cm', Units::length(81.0, $m));
    }

    public function test_nulls_pass_through_as_dash(): void
    {
        $i = $this->profile('imperial');
        $this->assertNull(Units::weightOut(null, $i));
        $this->assertNull(Units::weightIn(null, $i));
        $this->assertSame('—', Units::weight(null, $i));
        $this->assertSame('—', Units::length(null, $i));
    }

    public function test_num_trims_trailing_zeros(): void
    {
        $this->assertSame('182.1', Units::num(182.10));
        $this->assertSame('80', Units::num(80.0));
        $this->assertSame('60.5', Units::num(60.50));
    }

    public function test_null_profile_defaults_to_metric(): void
    {
        $this->assertFalse(Units::imperial(null));
        $this->assertSame('kg', Units::weightUnit(null));
        $this->assertSame(80.0, Units::weightOut(80.0, null));
    }
}
