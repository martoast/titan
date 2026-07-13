<?php

namespace Tests\Unit;

use App\Services\Glucose\NightscoutProvider;
use PHPUnit\Framework\TestCase;

/**
 * The Nightscout entry → normalized reading mapping (CGM_INTEGRATION P1). Pure (no network/DB).
 */
class NightscoutProviderTest extends TestCase
{
    public function test_maps_a_sensor_glucose_entry(): void
    {
        // date is ms-epoch (1720000000000 → 2024-07-03T09:46:40Z).
        $r = NightscoutProvider::mapEntry(['sgv' => 112, 'date' => 1720000000000, 'direction' => 'Flat', 'device' => 'xDrip']);
        $this->assertNotNull($r);
        $this->assertSame(112, $r['mg_dl']);
        $this->assertSame('flat', $r['trend']);
        $this->assertSame('xDrip', $r['device']);
        $this->assertSame('2024-07-03T09:46:40+00:00', $r['taken_at']->toIso8601String());
    }

    public function test_falls_back_to_dateString(): void
    {
        $r = NightscoutProvider::mapEntry(['sgv' => 95, 'dateString' => '2026-07-13T08:00:00Z', 'direction' => 'SingleUp']);
        $this->assertSame(95, $r['mg_dl']);
        $this->assertSame('rising', $r['trend']);
        $this->assertSame('2026-07-13T08:00:00+00:00', $r['taken_at']->toIso8601String());
    }

    public function test_drops_non_sgv_and_out_of_range(): void
    {
        $this->assertNull(NightscoutProvider::mapEntry(['mbg' => 100, 'date' => 1720000000000])); // no sgv (calibration)
        $this->assertNull(NightscoutProvider::mapEntry(['sgv' => 0, 'date' => 1720000000000]));    // 0 → drop
        $this->assertNull(NightscoutProvider::mapEntry(['sgv' => 900, 'date' => 1720000000000]));  // impossible → drop
        $this->assertNull(NightscoutProvider::mapEntry(['sgv' => 100]));                            // no timestamp → drop
    }

    public function test_direction_vocabulary(): void
    {
        $this->assertSame('rising_fast', NightscoutProvider::mapDirection('DoubleUp'));
        $this->assertSame('falling', NightscoutProvider::mapDirection('SingleDown'));
        $this->assertSame('flat', NightscoutProvider::mapDirection('Flat'));
        $this->assertNull(NightscoutProvider::mapDirection('NONE'));   // unknown → null, not a bad guess
        $this->assertNull(NightscoutProvider::mapDirection(null));
    }
}
