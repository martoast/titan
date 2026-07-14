<?php

namespace App\Support;

use App\Models\Fast;
use App\Models\Profile;
use Illuminate\Support\Carbon;

/**
 * Intermittent-fasting timer + the metabolic "body status" stage timeline. Each stage TEACHES the
 * survival pathway engaging (mTOR / AMPK / sirtuins / autophagy) and stays scrupulously honest where the
 * human evidence is thin — the Coach v3 educational stance, calibrated to docs/research/DAVID_SINCLAIR_
 * LONGEVITY.md (FASTING_EVIDENCE spec, Thrust 1). Benefits are real but mostly from eating fewer calories,
 * NOT clock magic, and NOT proven lifespan extension — never "reverse aging".
 */
class Fasting
{
    /** The non-negotiable framing, shown on the surface so the card never overstates the science. */
    public const DISCLAIMER = "Fasting's benefits are real but mostly come from eating less — not the clock. It's not proven to extend human lifespan.";

    /**
     * The body's estimated state through a fast. Each stage: `h` (min elapsed hours), `label`, `blurb`
     * (what's measurably happening), `pathway` (the survival pathway — teach the why), `why` (one plain
     * mechanism line), and `honesty` (a caveat where the HUMAN evidence is thin, or null when it's solid).
     *
     * @var list<array{h:int,label:string,blurb:string,pathway:string,why:string,honesty:?string}>
     */
    public const STAGES = [
        [
            'h' => 0, 'label' => 'Fed', 'blurb' => 'Digesting your last meal — insulin is high, in growth-and-store mode.',
            'pathway' => 'mTOR active (growth mode)',
            'why' => 'Nutrients — especially protein — switch ON mTOR, the growth sensor. Great after training; it also holds back cellular cleanup.',
            'honesty' => null,
        ],
        [
            'h' => 4, 'label' => 'Glycogen', 'blurb' => 'Blood sugar settles; you\'re tapping stored liver glycogen for fuel.',
            'pathway' => 'Insulin falling, AMPK rising',
            'why' => 'As insulin drops, the energy sensor AMPK begins to rise — the first nudge from "grow" toward "repair".',
            'honesty' => null,
        ],
        [
            'h' => 12, 'label' => 'Fat-burning', 'blurb' => 'Glycogen is running low, so you shift toward burning fat for energy.',
            'pathway' => 'AMPK↑, mTOR↓, lipolysis',
            'why' => 'Low fuel raises AMPK and lowers mTOR — the switch from growth to maintenance and repair. This part is well established.',
            'honesty' => null,
        ],
        [
            'h' => 16, 'label' => 'Ketosis onset', 'blurb' => 'Ketones are rising and appetite tends to ease as you become fat-adapted.',
            'pathway' => 'Fat-adaptation; sirtuin/NAD⁺ context',
            'why' => 'Running on fat makes ketones for your brain and muscles — the NAD⁺/sirtuin "scarcity" context too.',
            'honesty' => 'Ketone levels vary a lot by person and diet — your exact timing will differ.',
        ],
        [
            'h' => 24, 'label' => 'Deeper ketosis', 'blurb' => 'Mostly running on fat and ketones; growth hormone tends to rise.',
            'pathway' => 'Fat/ketone metabolism',
            'why' => 'Extended fasting nudges growth hormone up, which helps spare muscle while you fast.',
            'honesty' => 'The growth-hormone bump is short-term and modest — not a body-recomposition shortcut.',
        ],
        [
            'h' => 36, 'label' => 'Autophagy', 'blurb' => 'Cellular "cleanup" (autophagy) is believed to increase, clearing damaged parts.',
            'pathway' => 'Autophagy (AMPK↑/mTOR↓ → ULK1)',
            'why' => 'Sustained low mTOR + high AMPK is what triggers autophagy in the lab — the cell recycling worn-out components.',
            'honesty' => 'The fasting time needed to raise autophagy in HUMANS isn\'t well established — treat 36h as a rough marker, not a proven threshold.',
        ],
    ];

    public static function active(Profile $profile): ?Fast
    {
        return $profile->fasts()->whereNull('ended_at')->latest('id')->first();
    }

    public static function start(Profile $profile, ?float $goalHours = null): Fast
    {
        // close any dangling fast first (one active at a time)
        $profile->fasts()->whereNull('ended_at')->update(['ended_at' => now()]);

        return $profile->fasts()->create([
            'started_at' => now(),
            'goal_hours' => $goalHours && $goalHours > 0 ? round($goalHours, 1) : 16,
        ]);
    }

    public static function end(Profile $profile): ?Fast
    {
        $fast = self::active($profile);
        $fast?->update(['ended_at' => now()]);

        return $fast;
    }

    /** The `fasting` card for the active fast, or a "not fasting" payload. Carries the recurring eating
     *  window (+ adherence streak) when one is configured — it stands whether or not a fast is running. */
    public static function card(Profile $profile): array
    {
        $window = EatingWindow::forProfile($profile);   // null when no window is set
        $proteinFlag = FastingProtein::check($profile); // null unless the window is squeezing protein
        $fast = self::active($profile);
        if (! $fast) {
            return array_filter([
                'type' => 'fasting',
                'active' => false,
                'window' => $window,
                'protein_flag' => $proteinFlag,
            ], fn ($v) => $v !== null);
        }
        $elapsed = $fast->started_at->diffInMinutes(now()) / 60;
        [$stage, $next] = self::stageFor($elapsed);

        return array_filter([
            'type' => 'fasting',
            'active' => true,
            'started_at' => $fast->started_at->toIso8601String(),
            'elapsed_h' => round($elapsed, 1),
            'goal_h' => $fast->goal_hours,
            'pct' => $fast->goal_hours > 0 ? min(100, (int) round($elapsed / $fast->goal_hours * 100)) : 0,
            'stage' => $stage['label'],
            'stage_blurb' => $stage['blurb'],
            'pathway' => $stage['pathway'],       // the survival pathway engaging now — teach the why
            'stage_why' => $stage['why'],
            'stage_honesty' => $stage['honesty'], // caveat where the human evidence is thin (nullable)
            'next_stage' => $next['label'] ?? null,
            'next_stage_in_h' => $next !== null ? round(max(0, $next['h'] - $elapsed), 1) : null,
            'window' => $window,
            'protein_flag' => $proteinFlag,
            'glucose' => self::fastingGlucose($fast),   // CGM: is the fast flattening glucose? (nullable)
            'disclaimer' => self::DISCLAIMER,
        ], fn ($v) => $v !== null);
    }

    /**
     * Glucose behaviour SINCE the fast began, when a CGM is connected — the honest confirmation that a fast
     * does what it's for: with no food coming in, glucose settles and flattens. Returns mean/min + a `flat`
     * flag (steady AND not elevated), or null without enough readings. taken_at and started_at are both
     * app-tz wall-clock, so they compare directly (see [[titan-meal-timezone-fault]]).
     */
    private static function fastingGlucose(Fast $fast): ?array
    {
        if (! class_exists(\App\Models\GlucoseReading::class)) {
            return null;
        }
        $vals = $fast->profile->glucoseReadings()
            ->where('taken_at', '>=', $fast->started_at)
            ->pluck('mg_dl')->map(fn ($v) => (int) $v)->all();
        if (count($vals) < 6) {
            return null;                                 // too few readings into the fast to say anything
        }
        $mean = GlucoseMetrics::mean($vals);
        $cv = GlucoseMetrics::cv($vals);

        return array_filter([
            'mean_mg_dl' => (int) round($mean),
            'min_mg_dl' => min($vals),
            'cv_pct' => $cv !== null ? round($cv, 1) : null,
            // Flattened = steady (low variability) and sitting in a calm fasting range, not still riding a meal.
            'flat' => $cv !== null && $cv < GlucoseMetrics::STABLE_CV && $mean <= GlucoseMetrics::RANGE_HIGH,
        ], fn ($v) => $v !== null);
    }

    /** The stage a fast is in at `$hours` elapsed (pure — used by the card + unit-tested). */
    public static function stageAt(float $hours): array
    {
        return self::stageFor($hours)[0];
    }

    /** @return array{0:array,1:?array} [current stage, next stage|null] */
    private static function stageFor(float $hours): array
    {
        $current = self::STAGES[0];
        $next = null;
        foreach (self::STAGES as $i => $s) {
            if ($hours >= $s['h']) {
                $current = $s;
                $next = self::STAGES[$i + 1] ?? null;
            }
        }

        return [$current, $next];
    }
}
