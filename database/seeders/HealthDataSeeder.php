<?php

namespace Database\Seeders;

use App\Models\BiomarkerReading;
use App\Models\BodyMetric;
use App\Models\Profile;
use App\Support\Biomarkers;
use Database\Seeders\Concerns\SeedsProfile;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeds ~6 months of realistic, improving health data for profile 1 (Alex) so the
 * biomarker + body charts look alive on first load. Idempotent: clears Alex's prior
 * seeded rows first. Called by the orchestrator (NOT registered in DatabaseSeeder).
 */
class HealthDataSeeder extends Seeder
{
    use SeedsProfile;

    public function run(): void
    {
        $profile = $this->targetProfile();
        if (! $profile) {
            $this->command?->warn('HealthDataSeeder: profile 1 not found — skipping.');

            return;
        }

        $profile->biomarkerReadings()->delete();
        $profile->bodyMetrics()->delete();

        $this->seedBiomarkers($profile);
        $this->seedBodyMetrics($profile);

        $this->command?->info('HealthDataSeeder: seeded biomarkers + body metrics for Alex.');
    }

    /**
     * 4 quarterly-ish blood panels over 6 months, each trending toward optimal as
     * Alex dials in training/nutrition. start → end values per marker.
     */
    private function seedBiomarkers(Profile $profile): void
    {
        // [startValue, endValue] — chosen so the journey crosses from "off" to "optimal".
        $journey = [
            'testosterone' => [480, 720],     // low → optimal band
            'free_testosterone' => [11, 19],
            'apob' => [108, 78],              // high → optimal (lower better)
            'ldl' => [132, 96],
            'hdl' => [42, 58],               // low → optimal (higher better)
            'triglycerides' => [140, 82],
            'hba1c' => [5.7, 5.2],
            'fasting_glucose' => [99, 88],
            'insulin' => [9.5, 5.2],
            'vitamin_d' => [28, 48],
            'ferritin' => [180, 120],
            'hs_crp' => [2.4, 0.7],
            'alt' => [42, 26],
            'ast' => [38, 24],
            'ggt' => [40, 24],
            'creatinine' => [1.05, 1.0],
            'egfr' => [88, 98],
            'bun' => [18, 14],
        ];

        // Draw dates: ~5 months ago, ~3.5, ~2, and ~3 weeks ago.
        $dates = [
            Carbon::now()->subDays(160),
            Carbon::now()->subDays(105),
            Carbon::now()->subDays(55),
            Carbon::now()->subDays(20),
        ];
        $steps = count($dates) - 1;

        foreach ($dates as $i => $date) {
            $t = $steps === 0 ? 1 : $i / $steps;       // 0..1 progress
            foreach ($journey as $marker => [$from, $to]) {
                // Ease toward target with a touch of deterministic wobble so lines aren't ruler-straight.
                $base = $from + ($to - $from) * $this->ease($t);
                $wobble = sin($i * 1.7 + crc32($marker) % 7) * abs($to - $from) * 0.04;
                $value = round($base + $wobble, $this->precision($marker));

                BiomarkerReading::create([
                    'profile_id' => $profile->id,
                    'marker' => $marker,
                    'value' => $value,
                    'unit' => Biomarkers::unit($marker),
                    'taken_at' => $date->toDateString(),
                    'source' => $i === 0 ? 'manual' : 'lab_upload',
                    // flag auto-computed in model booted() hook
                ]);
            }
        }
    }

    /** Weekly weigh-ins + monthly measurements over ~6 months: recomposition trend. */
    private function seedBodyMetrics(Profile $profile): void
    {
        $weeks = 26;
        $start = Carbon::now()->subWeeks($weeks);

        // Lean recomp: weight drifts down slightly, BF% down more, muscle measures up.
        $weightFrom = 86.0; $weightTo = 83.5;
        $bfFrom = 19.5;     $bfTo = 13.0;
        $waistFrom = 88.0;  $waistTo = 81.0;
        $chestFrom = 104.0; $chestTo = 108.0;
        $armFrom = 37.0;    $armTo = 39.5;
        $thighFrom = 58.0;  $thighTo = 60.5;

        for ($w = 0; $w <= $weeks; $w++) {
            $date = (clone $start)->addWeeks($w);
            $t = $w / $weeks;

            // Weekly weight + BF every week; full tape measurements monthly.
            $weight = round($weightFrom + ($weightTo - $weightFrom) * $t + sin($w * 0.9) * 0.4, 1);
            $bf = round($bfFrom + ($bfTo - $bfFrom) * $this->ease($t) + sin($w * 1.3) * 0.25, 1);

            $row = [
                'profile_id' => $profile->id,
                'weight_kg' => $weight,
                'body_fat_pct' => $bf,
                'taken_at' => $date->toDateString(),
            ];

            if ($w % 4 === 0) {
                $row['waist_cm'] = round($waistFrom + ($waistTo - $waistFrom) * $this->ease($t), 1);
                $row['chest_cm'] = round($chestFrom + ($chestTo - $chestFrom) * $t, 1);
                $row['arm_cm'] = round($armFrom + ($armTo - $armFrom) * $t, 1);
                $row['thigh_cm'] = round($thighFrom + ($thighTo - $thighFrom) * $t, 1);
            }

            BodyMetric::create($row);
        }
    }

    /** Smooth ease-in-out so progress feels natural, not linear. */
    private function ease(float $t): float
    {
        return $t < 0.5 ? 2 * $t * $t : 1 - pow(-2 * $t + 2, 2) / 2;
    }

    private function precision(string $marker): int
    {
        return in_array($marker, ['hba1c', 'hs_crp', 'creatinine', 'insulin'], true) ? 1 : 0;
    }
}
