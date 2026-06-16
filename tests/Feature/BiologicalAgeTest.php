<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\BiologicalAge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BiologicalAgeTest extends TestCase
{
    use RefreshDatabase;

    private function profileAged(int $years, string $sex = 'M')
    {
        $p = User::factory()->create()->ensureProfile();
        $p->update(['birthdate' => now()->subYears($years)->toDateString(), 'sex' => $sex]);

        return $p;
    }

    private function uploadBloodwork($profile, array $markers): void
    {
        foreach ($markers as $marker => $value) {
            $profile->biomarkerReadings()->create(['marker' => $marker, 'value' => $value, 'taken_at' => now()->toDateString()]);
        }
    }

    private const HEALTHY_BLOOD = [
        'albumin' => 4.6, 'creatinine' => 0.9, 'fasting_glucose' => 85, 'hs_crp' => 0.4,
        'lymphocyte_percent' => 38, 'mcv' => 89, 'rdw' => 12.6, 'alkaline_phosphatase' => 55, 'wbc' => 5.0,
    ];

    public function test_null_without_birthdate(): void
    {
        $p = User::factory()->create()->ensureProfile();   // no birthdate
        $this->assertNull(BiologicalAge::assess($p));
    }

    public function test_fit_person_with_healthy_bloodwork_reads_younger(): void
    {
        $p = $this->profileAged(50);
        $this->uploadBloodwork($p, self::HEALTHY_BLOOD);                 // PhenoAge ≈ low 40s
        $p->activitySessions()->create(['started_at' => now(), 'vo2max' => 48, 'source' => 'titan_band']); // fit → young fitness age
        $p->recoveryLogs()->create(['logged_at' => now()->toDateString(), 'resting_hr' => 50, 'hrv_ms' => 70]);

        $r = BiologicalAge::assess($p);
        $this->assertNotNull($r);
        $this->assertSame('high', $r['confidence']);                    // blood + fitness both present
        $this->assertLessThan(50, $r['biological_age']);                // reads younger than 50
        $this->assertNotNull($r['pheno_age']);
        $this->assertNotNull($r['fitness_age']);
        $this->assertContains($r['band'], ['younger', 'much_younger']);
    }

    public function test_unfit_person_with_poor_bloodwork_reads_older(): void
    {
        $p = $this->profileAged(50);
        $this->uploadBloodwork($p, [
            'albumin' => 3.6, 'creatinine' => 1.2, 'fasting_glucose' => 135, 'hs_crp' => 4.5,
            'lymphocyte_percent' => 19, 'mcv' => 95, 'rdw' => 15.2, 'alkaline_phosphatase' => 115, 'wbc' => 8.8,
        ]);
        $p->activitySessions()->create(['started_at' => now(), 'vo2max' => 26, 'source' => 'titan_band']); // unfit
        $p->recoveryLogs()->create(['logged_at' => now()->toDateString(), 'resting_hr' => 80, 'hrv_ms' => 22]);

        $r = BiologicalAge::assess($p);
        $this->assertNotNull($r);
        $this->assertGreaterThan(50, $r['biological_age']);             // reads older than 50
        $this->assertContains($r['band'], ['older', 'much_older']);
    }

    public function test_wearable_only_is_medium_or_low_confidence_and_lists_missing_bloodwork(): void
    {
        $p = $this->profileAged(40);
        $p->activitySessions()->create(['started_at' => now(), 'vo2max' => 45, 'source' => 'titan_band']); // fitness anchor only
        $p->recoveryLogs()->create(['logged_at' => now()->toDateString(), 'resting_hr' => 55]);

        $r = BiologicalAge::assess($p);
        $this->assertNotNull($r);
        $this->assertSame('medium', $r['confidence']);                  // fitness anchor, no blood
        $this->assertNull($r['pheno_age']);
        $this->assertNotEmpty($r['missing_for_bloodwork']);             // tells the user which labs to add
    }
}
