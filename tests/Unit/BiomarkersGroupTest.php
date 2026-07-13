<?php

namespace Tests\Unit;

use App\Support\Biomarkers;
use PHPUnit\Framework\TestCase;

/**
 * The `biopanel` card groups markers by body system. Every catalog marker must map to a REAL section
 * (never the "Other" fallback), and the section order must cover them — otherwise a marker silently
 * drops off the panel. Pure (no DB), like SleepStoryTest.
 */
class BiomarkersGroupTest extends TestCase
{
    public function test_every_catalog_marker_maps_to_a_known_group(): void
    {
        foreach (Biomarkers::keys() as $key) {
            $this->assertNotSame('Other', Biomarkers::group($key), "Marker '$key' fell through to the Other group — add it to Biomarkers::GROUPS.");
        }
    }

    public function test_group_order_contains_every_group_used(): void
    {
        $order = Biomarkers::groupOrder();
        foreach (Biomarkers::keys() as $key) {
            $this->assertContains(Biomarkers::group($key), $order, "Group for '$key' is missing from groupOrder().");
        }
        // "Other" is the documented catch-all and must stay last so an uncatalogued marker still renders.
        $this->assertSame('Other', end($order));
    }

    public function test_known_markers_land_in_the_expected_sections(): void
    {
        $this->assertSame('Lipids', Biomarkers::group('apob'));
        $this->assertSame('Hormones', Biomarkers::group('testosterone'));
        $this->assertSame('Metabolic', Biomarkers::group('hba1c'));
        $this->assertSame('Liver', Biomarkers::group('alt'));
        // An unknown key is never dropped — it falls to Other.
        $this->assertSame('Other', Biomarkers::group('some_unlisted_marker'));
    }
}
