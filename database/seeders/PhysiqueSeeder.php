<?php

namespace Database\Seeders;

use App\Models\PhysiqueAnalysis;
use App\Models\Profile;
use Illuminate\Database\Seeder;

/**
 * Physique seeder. The signature features (dream-physique image, progress photos,
 * living goal image) all hinge on REAL uploaded/generated image files, which we will
 * NOT fabricate here. So this seeder only plants a single text-only PhysiqueAnalysis
 * placeholder for Alex (profile 1) — no image rows, no fake files — so the page has a
 * little narrative content out of the box. ProgressPhoto and PhysiqueGoal are left
 * empty intentionally and are created through the UI once a user uploads a photo.
 *
 * Idempotent and a near no-op; safe to run repeatedly. Called by the orchestrator —
 * do NOT register this in DatabaseSeeder.
 */
class PhysiqueSeeder extends Seeder
{
    public function run(): void
    {
        $alex = Profile::orderBy('id')->first();
        if (! $alex) {
            return; // No profiles yet — nothing to hang sample data off.
        }

        // Only seed if this profile has no physique analysis yet.
        if (PhysiqueAnalysis::where('profile_id', $alex->id)->exists()) {
            return;
        }

        PhysiqueAnalysis::create([
            'profile_id' => $alex->id,
            'progress_photo_id' => null,
            'body_fat_pct_low' => 14.0,
            'body_fat_pct_high' => 17.0,
            'muscle_ratings' => [
                'chest' => 6, 'back' => 6, 'shoulders' => 5,
                'arms' => 6, 'legs' => 5, 'core' => 6,
            ],
            'pct_to_goal' => null,
            'summary' => 'Solid foundation to build on — upper body is developing well and your '
                .'frame carries muscle nicely. The biggest opportunity is bringing your legs and '
                .'shoulders up to match. Upload a progress photo to get a fresh, personalized read.',
        ]);
    }
}
