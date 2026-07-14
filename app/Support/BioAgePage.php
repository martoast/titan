<?php

namespace App\Support;

use App\Models\Profile;

/**
 * The full, transparent Bio Age page payload (BIO_AGE_PAGE spec) — the screenshot surface. Builds ON
 * LongevityIndex::assess (which already returns titan_age / chronological_age / delta / band / pace /
 * confidence / partial / missing[] / components[] in years / younger+older levers). This adds only the
 * PRESENTATION transparency: per-component unit + plain-language "how it maps to years", data-driven
 * coach tips, and a methodology footer. No new age math.
 *
 * Honesty (hard rules, same as LongevityKnowledge): these are HEALTHSPAN levers — a wellness estimate
 * from the user's data, NEVER "reverse aging" or a promise of years back, and never medical.
 */
class BioAgePage
{
    /** Per-marker unit + one-line methodology (the "how this maps to years" the page expands to show). */
    private const META = [
        'blood' => ['unit' => '', 'how' => 'A PhenoAge-style model (Levine) turns 9 blood markers into a clinical-grade biological age — the strongest anchor when a clean draw is available.'],
        'fitness' => ['unit' => 'ml/kg/min', 'how' => 'VO₂max is among the best predictors of longevity; your cardio fitness sets a "fitness age" we blend into the estimate.'],
        'resting_hr' => ['unit' => 'bpm', 'how' => 'A lower resting heart rate reflects a more efficient, fitter heart; each beat below your age norm maps to biological youth.'],
        'hrv' => ['unit' => 'ms', 'how' => 'Higher HRV signals a resilient nervous system; we compare yours to age norms and credit the biological youth it implies.'],
        'sleep_regularity' => ['unit' => '', 'how' => 'A consistent sleep–wake schedule (SRI) tracks with slower aging; irregular nights add years.'],
        'steps' => ['unit' => '/day', 'how' => 'Daily movement volume is a robust healthspan signal — more steps, fewer biological years.'],
    ];

    public static function forProfile(Profile $profile): ?array
    {
        $a = LongevityIndex::assess($profile);
        if ($a === null) {
            return null;
        }
        $components = array_map([self::class, 'enrichComponent'], $a['components'] ?? []);

        return array_merge($a, [
            'components' => $components,
            'tips' => self::tipsFor($a),
            'methodology' => self::methodology(),
            'disclaimer' => 'A wellness estimate from your own data — not a medical diagnosis. Healthspan levers, not "reverse aging".',
        ]);
    }

    /** Add unit + methodology `how` to a component; carry its existing `note` through as `context`. Pure. */
    public static function enrichComponent(array $c): array
    {
        $meta = self::META[$c['key'] ?? ''] ?? ['unit' => '', 'how' => null];
        $out = $c;
        if (isset($out['value']) && is_numeric($out['value'])) {
            $out['value'] = round((float) $out['value'], 1);   // e.g. VO₂max 29.2222 → 29.2 (review 1020d59)
        }
        $out['unit'] = $meta['unit'];
        if ($meta['how'] !== null) {
            $out['how'] = $meta['how'];
        }
        if (! empty($c['note'])) {
            $out['context'] = $c['note'];
        }

        return $out;
    }

    /**
     * Data-driven, honest tips from the user's levers: PROTECT the youth drivers, IMPROVE what's aging
     * them. Voiced as healthspan actions tied to THEIR numbers — never a promise of years back. Pure.
     *
     * @return array<int,array{key:string,label:string,years:float,kind:string,action:string}>
     */
    public static function tipsFor(array $assess): array
    {
        $tips = [];
        foreach (($assess['younger_levers'] ?? []) as $l) {
            if ($action = self::protectAction($l['label'] ?? '')) {
                $tips[] = ['key' => 'protect', 'label' => $l['label'], 'years' => (float) ($l['years'] ?? 0), 'kind' => 'protect', 'action' => $action];
            }
        }
        foreach (($assess['older_levers'] ?? []) as $l) {
            if ($action = self::improveAction($l['label'] ?? '')) {
                $tips[] = ['key' => 'improve', 'label' => $l['label'], 'years' => (float) ($l['years'] ?? 0), 'kind' => 'improve', 'action' => $action];
            }
        }
        // Nothing aging them faster than the clock → celebrate it (rare, worth naming).
        if (($assess['older_levers'] ?? []) === [] && $tips !== []) {
            $tips[] = ['key' => 'celebrate', 'label' => 'No older levers', 'years' => 0.0, 'kind' => 'celebrate',
                'action' => 'Nothing is aging you faster than your years right now — rare. The game is to protect what you have.'];
        }

        return $tips;
    }

    /** "Protect it" copy for a youth-driving lever (matched loosely by label). */
    private static function protectAction(string $label): ?string
    {
        $l = strtolower($label);

        return match (true) {
            str_contains($l, 'hrv') => 'Your HRV is one of your biggest youth drivers — protect it with consistent sleep and easy Zone-2 days; late nights and alcohol tank it fastest.',
            str_contains($l, 'resting hr') => 'Your resting heart rate is keeping you young — hold it there with regular Zone-2 cardio and good sleep.',
            str_contains($l, 'vo') || str_contains($l, 'fitness') => 'Your cardio fitness is a top youth driver — keep it with a weekly long Zone-2 session plus one harder effort.',
            str_contains($l, 'sleep') => 'Your steady sleep schedule is buying you biological youth — keep bedtime consistent, even on weekends.',
            str_contains($l, 'activity') || str_contains($l, 'step') => 'Your daily movement is on your side — protect the streak; consistency beats the occasional big day.',
            default => null,
        };
    }

    /** "Improve it" copy for an aging lever. */
    private static function improveAction(string $label): ?string
    {
        $l = strtolower($label);

        return match (true) {
            str_contains($l, 'hrv') => 'Lifting your HRV is the highest-leverage fix here — steady sleep, Zone-2 cardio, and stress down-regulation (slow breathing, sauna) move it most.',
            str_contains($l, 'resting hr') => 'Zone-2 cardio 2–3×/week is the most reliable way to bring your resting heart rate down over a few weeks.',
            str_contains($l, 'vo') || str_contains($l, 'fitness') => 'Build cardio fitness with mostly easy Zone-2 volume plus one weekly VO₂-max interval session.',
            str_contains($l, 'sleep') => 'Tighten your sleep schedule — a fixed wake time (even after a bad night) is what steadies the rhythm that\'s adding years.',
            str_contains($l, 'activity') || str_contains($l, 'step') => 'More daily movement is the fix — aim to lift your weekly step average; short walks after meals add up.',
            default => null,
        };
    }

    /** The plain-language "how Titan Age works" footer. */
    public static function methodology(): string
    {
        return 'Titan Age is your chronological age adjusted by validated markers — cardio fitness (VO₂max), resting HR, HRV, sleep regularity and daily activity, and when available a PhenoAge-style bloodwork model (Levine). It\'s a wellness estimate from your data, not a medical diagnosis; bloodwork markers like fasting glucose and CRP must come from a clean draw.';
    }
}
