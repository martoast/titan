<?php

namespace Tests\Unit;

use App\Support\PhenoAge;
use PHPUnit\Framework\TestCase;

class PhenoAgeTest extends TestCase
{
    /** A healthy 50-year-old's nine markers (catalog/US units). */
    private function healthy(): array
    {
        return [
            'albumin' => 4.5, 'creatinine' => 0.9, 'fasting_glucose' => 90, 'hs_crp' => 0.5,
            'lymphocyte_percent' => 35, 'mcv' => 90, 'rdw' => 13, 'alkaline_phosphatase' => 60, 'wbc' => 5.5,
        ];
    }

    public function test_golden_value_healthy_fifty_year_old(): void
    {
        // Hand-computed from the Levine/Liu coefficients with US→SI unit conversion: PhenoAge ≈ 39.9.
        // This pins the WHOLE chain (units, log-CRP, Gompertz, inversion) — the #1 place to drift.
        $r = PhenoAge::compute($this->healthy(), 50);
        $this->assertNotNull($r);
        $this->assertEqualsWithDelta(39.9, $r['pheno_age'], 0.3, 'PhenoAge golden value drifted');
        $this->assertEqualsWithDelta(-10.1, $r['accel'], 0.3);
    }

    public function test_unhealthy_reads_older(): void
    {
        $r = PhenoAge::compute([
            'albumin' => 3.6, 'creatinine' => 1.2, 'fasting_glucose' => 140, 'hs_crp' => 5.0,
            'lymphocyte_percent' => 18, 'mcv' => 95, 'rdw' => 15.5, 'alkaline_phosphatase' => 120, 'wbc' => 9.0,
        ], 50);
        $this->assertNotNull($r);
        $this->assertGreaterThan(60, $r['pheno_age']);        // metabolic + inflammatory load → much older
    }

    public function test_crp_unit_and_log_handling(): void
    {
        // CRP enters as ln(mg/dL). A 10× higher CRP (mg/L) must move PhenoAge UP by a bounded, sane amount.
        $low = PhenoAge::compute([...$this->healthy(), 'hs_crp' => 0.5], 50)['pheno_age'];
        $high = PhenoAge::compute([...$this->healthy(), 'hs_crp' => 5.0], 50)['pheno_age'];
        $this->assertGreaterThan($low, $high);
        $this->assertLessThan(8, $high - $low);               // one marker shouldn't swing it wildly
    }

    public function test_missing_marker_returns_null(): void
    {
        $missing = $this->healthy();
        unset($missing['hs_crp']);                            // CRP is the commonly-absent one
        $this->assertNull(PhenoAge::compute($missing, 50));
    }

    public function test_implausible_value_returns_null(): void
    {
        $bad = [...$this->healthy(), 'fasting_glucose' => 9999];  // lab typo / unit error
        $this->assertNull(PhenoAge::compute($bad, 50));
    }
}
