<?php

namespace App\Support;

use App\Models\Profile;
use Illuminate\Support\Carbon;

/**
 * Biological Age -- Titan's synthesis of everything we measure into "how old your body looks".
 *
 * Built on the literature's two strongest, independent anchors (see tasks/titan-wearable/
 * 10-biological-age.md):
 *   - BLOOD: PhenoAge (Levine/Liu 2018), a mortality-validated clock from routine CBC+CMP+CRP.
 *   - FITNESS: a VO2max "Fitness Age" (Nes 2011) -- cardiorespiratory fitness is the single steepest
 *     mortality gradient known (low-vs-elite aHR ~5.0, Mandsager 2018).
 * These two are orthogonal (one chemical, one functional), so we BLEND them as the anchor. The
 * wearable daily signals -- resting HR, HRV, sleep regularity, steps -- are layered on as BOUNDED,
 * modifiable LEVERS, not independent age axes: the research is clear they add real but modest,
 * partly-redundant signal, so each is capped and the total wearable nudge is capped (and steps is
 * down-weighted when fitness is present, since activity feeds VO2max -- no double counting).
 *
 * HONESTY: this composes individually-validated signals transparently; it is NOT itself calibrated
 * against mortality outcomes (we have no follow-up data -- a true clock needs an NHANES-style cohort).
 * PhenoAge IS mortality-validated; the blend and levers are evidence-weighted heuristics. We surface
 * confidence (blood+fitness = high), show every component, and frame it as a wellness trend with a
 * range -- never a clinical biological-age test or a mortality prediction.
 */
class BiologicalAge
{
    // VO2max population norms (Nes 2011 / ACSM): peak at 25, ~0.45 ml/kg/min/yr decline. Mirrors
    // biosignal/scripts/validate_bioage.py (anchor validated calibrated on 940 real treadmill tests).
    private const VO2_PEAK_M = 50.0;
    private const VO2_PEAK_F = 42.0;
    private const VO2_DECLINE = 0.45;

    // Anchor weights for the two strong, independent absolute-age estimates.
    private const W_BLOOD = 0.5;
    private const W_FITNESS = 0.5;

    private const MODIFIER_CAP = 8.0;     // total wearable nudge bound (yr)

    /**
     * @return array{biological_age:float,chronological_age:float,delta:float,band:string,label:string,
     *   confidence:string,components:array<int,array<string,mixed>>,fitness_age:?float,pheno_age:?float,
     *   missing_for_bloodwork:array<int,string>}|null
     */
    public static function assess(Profile $profile): ?array
    {
        $age = self::ageOf($profile);
        if ($age === null) {
            return null;                                      // no birthdate → can't anchor to chronological age
        }
        $female = self::isFemale($profile);
        $components = [];

        // --- Anchor 1: blood PhenoAge --------------------------------------------------------
        $blood = self::bloodValues($profile);
        $pheno = PhenoAge::compute($blood, $age);
        $phenoAge = $pheno['pheno_age'] ?? null;
        if ($phenoAge !== null) {
            $components[] = self::comp('blood', 'Bloodwork (PhenoAge)', 'anchor', $phenoAge,
                round($phenoAge - $age, 1), 'Mortality-validated clock from 9 blood markers.');
        }

        // --- Anchor 2: VO2max Fitness Age ----------------------------------------------------
        $vo2 = $profile->activitySessions()->whereNotNull('vo2max')->orderByDesc('started_at')->value('vo2max');
        $fitnessAge = $vo2 ? self::fitnessAge((float) $vo2, $female) : null;
        if ($fitnessAge !== null) {
            $components[] = self::comp('fitness', 'Cardio fitness (VO₂max)', 'anchor', $fitnessAge,
                round($fitnessAge - $age, 1), 'How your VO₂max compares to age norms -- the strongest single signal.');
        }

        // Blend the available anchors; fall back to chronological age if we have neither (low confidence).
        $anchor = self::blend($phenoAge, $fitnessAge, $age);

        // --- Wearable levers (bounded age offsets on the anchor) -----------------------------
        $recovery = $profile->recoveryLogs()->orderByDesc('logged_at')->orderByDesc('id')->first();
        $modifiers = 0.0;

        if ($recovery?->resting_hr) {
            $off = self::cap(($recovery->resting_hr - 60) / 10.0 * 2.0, 5.0);   // +2 yr per +10 bpm (Aune)
            $modifiers += $off;
            $components[] = self::comp('resting_hr', 'Resting HR', 'lever', (float) $recovery->resting_hr, round($off, 1));
        }
        if ($recovery?->resp_rate === null && $recovery?->hrv_ms) {
            $off = self::cap(-(($recovery->hrv_ms - 40) / 15.0) * 2.0, 3.0);    // lowest weight (noisy)
            $modifiers += $off;
            $components[] = self::comp('hrv', 'HRV (RMSSD)', 'lever', (float) $recovery->hrv_ms, round($off, 1));
        }

        $sri = SleepRegularity::compute($profile->sleepLogs()->nights()->where('slept_at', '>=', Carbon::today()->subDays(27))->get());
        if ($sri && ($sri['sri'] ?? null) !== null) {
            $off = self::cap(-(($sri['sri'] - 60) / 20.0) * 3.0, 4.0);          // irregular → older (Windred)
            $modifiers += $off;
            $components[] = self::comp('sleep_regularity', 'Sleep regularity', 'lever', (float) $sri['sri'], round($off, 1));
        }

        $stepDays = $profile->dailyActivity()->where('date', '>=', Carbon::today()->subDays(13))->get();
        if ($stepDays->count() >= 3) {
            $avg = (float) $stepDays->avg('steps');
            $scale = $fitnessAge !== null ? 1.0 : 2.0;                          // half weight if fitness already in
            $off = self::cap(-(($avg - 7000) / 3000.0) * $scale, 4.0);
            $modifiers += $off;
            $components[] = self::comp('steps', 'Daily activity', 'lever', round($avg), round($off, 1));
        }

        // --- Metabolic lever: glucose variability (only if a CGM is connected) ----------------
        // Glucose variability (CV) is an emerging metabolic-aging signal — steadier glucose tracks with
        // better metabolic health. The evidence in non-diabetics is real but modest, so this is the
        // lowest-weight lever, tightly capped, and only counts with two solid weeks of readings.
        if (class_exists(\App\Models\GlucoseReading::class)) {
            $gluCv = self::glucoseCv($profile);
            if ($gluCv !== null) {
                $off = self::cap(-((30.0 - $gluCv) / 10.0) * 1.5, 3.0);        // steadier (low CV) → younger
                $modifiers += $off;
                $components[] = self::comp('glucose_variability', 'Glucose variability', 'lever', round($gluCv, 1), round($off, 1),
                    'Steadier glucose (lower CV) tracks with metabolic health — emerging, low weight.');
            }
        }

        $modifiers = self::cap($modifiers, self::MODIFIER_CAP);

        // Need a real signal: an anchor, or at least two wearable levers.
        $hasAnchor = $phenoAge !== null || $fitnessAge !== null;
        $levers = count(array_filter($components, fn ($c) => $c['kind'] === 'lever'));
        if (! $hasAnchor && $levers < 2) {
            return null;
        }

        $bioAge = max(18.0, min(100.0, $anchor + $modifiers));
        $delta = $bioAge - $age;
        [$band, $label] = self::band($delta);

        $confidence = match (true) {
            $phenoAge !== null && $fitnessAge !== null => 'high',
            $hasAnchor => 'medium',
            default => 'low',
        };

        return [
            'biological_age' => round($bioAge, 1),
            'chronological_age' => round($age, 1),
            'delta' => round($delta, 1),
            'band' => $band,
            'label' => $label,
            'confidence' => $confidence,
            'components' => $components,
            'fitness_age' => $fitnessAge !== null ? round($fitnessAge, 1) : null,
            'pheno_age' => $phenoAge,
            'missing_for_bloodwork' => $pheno === null ? self::missingBlood($blood) : [],
        ];
    }

    private static function fitnessAge(float $vo2max, bool $female): float
    {
        $peak = $female ? self::VO2_PEAK_F : self::VO2_PEAK_M;
        return max(18.0, min(90.0, 25.0 + ($peak - $vo2max) / self::VO2_DECLINE));
    }

    private static function blend(?float $pheno, ?float $fitness, float $age): float
    {
        $num = 0.0;
        $den = 0.0;
        if ($pheno !== null) {
            $num += self::W_BLOOD * $pheno;
            $den += self::W_BLOOD;
        }
        if ($fitness !== null) {
            $num += self::W_FITNESS * $fitness;
            $den += self::W_FITNESS;
        }
        return $den > 0 ? $num / $den : $age;                 // no anchor → chronological age
    }

    /** Latest value per PhenoAge marker (catalog units). */
    private static function bloodValues(Profile $profile): array
    {
        $needed = PhenoAge::requiredMarkers();
        $out = [];
        $profile->biomarkerReadings()->whereIn('marker', $needed)
            ->orderByDesc('taken_at')->orderByDesc('id')->get()
            ->each(function ($r) use (&$out) {
                $out[$r->marker] ??= (float) $r->value;       // first seen = most recent
            });
        return $out;
    }

    /** @return array<int,string> catalog labels for the PhenoAge markers the user hasn't uploaded */
    private static function missingBlood(array $have): array
    {
        return array_values(array_map(
            fn ($m) => Biomarkers::label($m),
            array_filter(PhenoAge::requiredMarkers(), fn ($m) => ! isset($have[$m]))
        ));
    }

    /** Mean glucose CV (%) over the last 14 days, or null without two solid weeks of CGM readings. */
    private static function glucoseCv(Profile $profile): ?float
    {
        $vals = $profile->glucoseReadings()
            ->where('taken_at', '>=', Carbon::today()->subDays(13))
            ->pluck('mg_dl')->map(fn ($v) => (int) $v)->all();
        if (count($vals) < 200) {
            return null;                                          // too sparse to trust the variability
        }
        return GlucoseMetrics::cv($vals);
    }

    private static function comp(string $key, string $label, string $kind, float $value, float $years, ?string $note = null): array
    {
        return array_filter([
            'key' => $key, 'label' => $label, 'kind' => $kind,
            'value' => $value, 'years' => $years, 'note' => $note,
        ], fn ($v) => $v !== null);
    }

    private static function cap(float $v, float $cap): float
    {
        return max(-$cap, min($cap, $v));
    }

    private static function ageOf(Profile $profile): ?float
    {
        return $profile->birthdate
            ? Carbon::parse($profile->birthdate)->diffInDays(now()) / 365.25
            : null;
    }

    private static function isFemale(Profile $profile): bool
    {
        return in_array(strtolower((string) ($profile->sex ?? '')), ['f', 'female', 'woman', 'w'], true);
    }

    /** @return array{0:string,1:string} */
    private static function band(float $delta): array
    {
        return match (true) {
            $delta <= -7 => ['much_younger', 'Much younger than your age'],
            $delta <= -2 => ['younger', 'Younger than your age'],
            $delta < 2 => ['on_par', 'On par with your age'],
            $delta < 7 => ['older', 'Older than your age'],
            default => ['much_older', 'Older than your age -- room to turn it around'],
        };
    }
}
