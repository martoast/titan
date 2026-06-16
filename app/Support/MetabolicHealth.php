<?php

namespace App\Support;

use App\Models\Profile;
use Illuminate\Support\Carbon;

/**
 * Metabolic-health forecast — a transparent composite of the wearable signals that independently
 * predict incident type-2 diabetes / metabolic disease in large cohorts. This is our strongest
 * PREVENTION play: it flags risk while it's still modifiable, not after diagnosis.
 *
 * Evidence per input (all from data we already compute):
 *   - Resting HR ↑      → T2D RR 1.20 per +10 bpm (meta-analysis, 119,915 people)
 *   - Cardiorespiratory fitness (VO2max) ↑ → T2D HR 0.72 per +1 SD (UK Biobank)
 *   - HRV ↓             → higher incident T2D (younger adults esp.; JCEM 2023)
 *   - Sleep duration    → U-shaped, nadir 7–8 h; short sleep HR up to 1.45 (Diabetes Care 2015)
 *   - Daily steps ↑     → strong inverse activity dose-response (Paluch/Saint-Maurice)
 *
 * HONESTY: this is a wellness ESTIMATE, never a diagnosis or a risk percentage. Each component is
 * scored against coarse population-healthy ranges (HRV/VO2max are age/sex-confounded — surfaced as
 * a caveat), evidence-weighted, and renormalised over whatever inputs are present. The combined
 * score is NOT validated against outcomes; it composes individually-validated signals transparently.
 */
class MetabolicHealth
{
    /** Need at least this many of the five inputs present to say anything. */
    public const MIN_INPUTS = 3;

    /** Evidence × measurement-fidelity weights (renormalised over present inputs). */
    private const WEIGHTS = ['fitness' => 0.25, 'resting_hr' => 0.25, 'sleep' => 0.20, 'steps' => 0.20, 'hrv' => 0.10];

    private const LABELS = [
        'fitness' => 'Cardio fitness', 'resting_hr' => 'Resting HR', 'sleep' => 'Sleep',
        'steps' => 'Daily activity', 'hrv' => 'HRV',
    ];

    /**
     * @return array{score:int,band:string,label:string,components:array<string,int>,weakest:?string,weakest_label:?string,inputs:int}|null
     */
    public static function assess(Profile $profile): ?array
    {
        $sub = [];   // component => 0..100 (higher = healthier)

        $recovery = $profile->recoveryLogs()->orderByDesc('logged_at')->orderByDesc('id')->first();
        if ($recovery?->resting_hr) {
            $sub['resting_hr'] = self::clamp((85 - $recovery->resting_hr) / (85 - 55) * 100);
        }
        if ($recovery?->hrv_ms) {
            $sub['hrv'] = self::clamp(($recovery->hrv_ms - 20) / (70 - 20) * 100);
        }

        $vo2 = $profile->activitySessions()->whereNotNull('vo2max')->orderByDesc('started_at')->value('vo2max');
        if ($vo2) {
            $sub['fitness'] = self::clamp(((float) $vo2 - 30) / (55 - 30) * 100);
        }

        $nights = $profile->sleepLogs()->where('slept_at', '>=', Carbon::today()->subDays(6))->get();
        if ($nights->count()) {
            $hours = $nights->avg('duration_min') / 60;
            $sub['sleep'] = self::clamp(100 - abs($hours - 7.5) * 30);   // U-shaped, optimum 7.5 h
        }

        $stepDays = $profile->dailyActivity()->where('date', '>=', Carbon::today()->subDays(6))->get();
        if ($stepDays->count()) {
            $sub['steps'] = self::clamp($stepDays->avg('steps') / 8000 * 100);
        }

        if (count($sub) < self::MIN_INPUTS) {
            return null;
        }

        // Evidence-weighted mean over present inputs.
        $wsum = 0.0;
        $acc = 0.0;
        foreach ($sub as $k => $v) {
            $w = self::WEIGHTS[$k];
            $acc += $w * $v;
            $wsum += $w;
        }
        $score = (int) round($acc / $wsum);

        // Biggest opportunity = the lowest-scoring present component.
        $weakest = null;
        foreach ($sub as $k => $v) {
            if ($weakest === null || $v < $sub[$weakest]) {
                $weakest = $k;
            }
        }

        [$band, $label] = self::band($score);

        return [
            'score' => $score,
            'band' => $band,
            'label' => $label,
            'components' => array_map(fn ($v) => (int) round($v), $sub),
            'weakest' => $weakest,
            'weakest_label' => $weakest ? self::LABELS[$weakest] : null,
            'inputs' => count($sub),
        ];
    }

    private static function clamp(float $v): float
    {
        return max(0.0, min(100.0, $v));
    }

    /** @return array{0:string,1:string} */
    private static function band(int $score): array
    {
        return match (true) {
            $score >= 75 => ['strong', 'Strong'],
            $score >= 60 => ['good', 'Healthy'],
            $score >= 45 => ['fair', 'Room to improve'],
            default => ['low', 'Needs attention'],
        };
    }
}
