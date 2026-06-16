<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BiomarkerPhenoAgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_shows_pheno_age_progress_when_markers_missing(): void
    {
        $user = User::factory()->create();
        $p = $user->ensureProfile();
        $p->update(['birthdate' => now()->subYears(45)->toDateString(), 'sex' => 'M']);
        // Only two of the nine PhenoAge markers present.
        $p->biomarkerReadings()->create(['marker' => 'albumin', 'value' => 4.5, 'taken_at' => now()->toDateString()]);
        $p->biomarkerReadings()->create(['marker' => 'wbc', 'value' => 5.5, 'taken_at' => now()->toDateString()]);

        $resp = $this->actingAs($user)->get(route('biomarkers.index'));
        $resp->assertOk();
        $resp->assertSee('Biological age clock');
        $resp->assertSee('markers to unlock');            // PhenoAge not yet computable
        $resp->assertSee('22% complete');                 // 2 of 9 markers in
    }

    public function test_page_shows_computed_pheno_age_when_panel_complete(): void
    {
        $user = User::factory()->create();
        $p = $user->ensureProfile();
        $p->update(['birthdate' => now()->subYears(50)->toDateString(), 'sex' => 'M']);
        foreach ([
            'albumin' => 4.5, 'creatinine' => 0.9, 'fasting_glucose' => 90, 'hs_crp' => 0.5,
            'lymphocyte_percent' => 35, 'mcv' => 90, 'rdw' => 13, 'alkaline_phosphatase' => 60, 'wbc' => 5.5,
        ] as $m => $v) {
            $p->biomarkerReadings()->create(['marker' => $m, 'value' => $v, 'taken_at' => now()->toDateString()]);
        }

        $resp = $this->actingAs($user)->get(route('biomarkers.index'));
        $resp->assertOk();
        $resp->assertSee('blood biological age');         // computed, not the "unlock" state
        $resp->assertSee('40');                           // healthy 50yo → PhenoAge ≈ 40
    }
}
