<?php

namespace App\Support\Coach;

use App\Models\Profile;

/**
 * COACH v3 · situational intelligence. The menstrual-cycle lens proved the thesis: a coach reads the
 * whole SITUATION — not just stats — when it gets a digest PLUS a connected, cross-domain "weave this in"
 * directive (cycle shifts training AND nutrition AND recovery AND tone). This generalises that: it
 * assembles the person's ACTIVE lenses — only the ones that matter right now, each a short read + a
 * cross-domain instruction — into the coach's system prompt, alongside the trajectory digest (numbers →
 * where it's heading; lenses → what the situation MEANS).
 *
 * Each lens is relevance-gated (silent when not notable, like cycle only shows for female profiles) and
 * the whole block is budgeted, so it stays tight riding in every prompt. Honesty ethos: a lens built on
 * thin-confidence data hedges — it never states a soft number as fact. Cycle stays in CoachService (its
 * own exemplar); this covers recovery, stress, training-phase and metabolic.
 */
class PersonalContext
{
    /** Return the assembled lens block (already ≤ $budget chars), or '' when no lens is active. */
    public static function digest(Profile $profile, int $budget = 1200): string
    {
        $lenses = array_filter([
            rescue(fn () => self::recoveryLens($profile), null, false),
            rescue(fn () => self::stressLens($profile), null, false),
            rescue(fn () => self::trainingPhaseLens($profile), null, false),
            rescue(fn () => self::metabolicLens($profile), null, false),
        ], fn ($v) => is_string($v) && $v !== '');

        if ($lenses === []) {
            return '';
        }

        $block = implode("\n", array_map(fn ($l) => '- '.$l, $lenses));
        if (strlen($block) > $budget) {
            $block = rtrim(mb_strcut($block, 0, $budget));
        }

        return $block;
    }

    /** RECOVERY / sleep-debt — gates training intensity + protects sleep. */
    private static function recoveryLens(Profile $profile): ?string
    {
        if (! class_exists(\App\Support\SleepCoach::class)) {
            return null;
        }
        $sleep = \App\Support\SleepCoach::assess($profile);
        $ready = class_exists(\App\Support\Readiness::class)
            ? (\App\Support\Readiness::compute($profile)['score'] ?? null) : null;
        $debt = (float) ($sleep['debt_h'] ?? 0);

        $lowReady = $ready !== null && $ready < 50;
        if ($debt < 1.5 && ! $lowReady) {
            return null;   // rested enough — no lens
        }

        $bits = [];
        if ($debt >= 1.5) {
            $bits[] = 'carrying ~'.rtrim(rtrim(number_format($debt, 1), '0'), '.').'h of sleep debt';
        }
        if ($lowReady) {
            $bits[] = "recovery is low (readiness ~{$ready})";
        }

        return 'RECOVERY: '.implode(', ', $bits).' → today favor technique/volume over max intensity (no PR '
            .'attempt), and protect tonight\'s sleep — an earlier bedtime chips the debt. Connect it to their '
            .'training AND nutrition (more protein/carbs to recover), not as a siloed status line.';
    }

    /** STRESS — don't stack training load on life stress; offer down-regulation. */
    private static function stressLens(Profile $profile): ?string
    {
        if (! class_exists(\App\Support\StressMonitor::class)) {
            return null;
        }
        $s = \App\Support\StressMonitor::assess($profile);
        if (($s['moving'] ?? false) || ! in_array($s['level'] ?? 'calm', ['medium', 'high'], true)) {
            return null;
        }
        if (($s['confidence']['level'] ?? 'low') === 'low') {
            return null;   // thin baseline — don't assert stress
        }

        return 'STRESS: running '.$s['level'].' right now → don\'t add training stress on top of life stress; '
            .'suggest a down-regulating session and a 90-second physiological sigh, and keep your tone calmer '
            .'and more supportive than usual.';
    }

    /** TRAINING PHASE — where the block is (push / hold / deload) reshapes volume + fueling, like cycle phases. */
    private static function trainingPhaseLens(Profile $profile): ?string
    {
        if (! class_exists(\App\Support\Autoregulator::class)) {
            return null;
        }
        $a = \App\Support\Autoregulator::assess($profile);
        $verdict = $a['verdict'] ?? null;
        if ($verdict === null || $verdict === 'insufficient') {
            return null;
        }
        $vol = $a['adjustment']['volume'] ?? null;

        return 'TRAINING PHASE: '.$verdict.($vol ? " ({$vol})" : '').' → let this shape today\'s volume and '
            .'intensity AND the fueling around it (more carbs on a push day, less on a deload), the same way a '
            .'cycle phase would — and teach why the phase calls for it.';
    }

    /** METABOLIC — a weak metabolic link becomes actionable food/training context. */
    private static function metabolicLens(Profile $profile): ?string
    {
        if (! class_exists(\App\Support\MetabolicHealth::class)) {
            return null;
        }
        $mh = \App\Support\MetabolicHealth::assess($profile);
        if ($mh === null || ! in_array($mh['band'] ?? '', ['fair', 'low'], true) || empty($mh['weakest_label'])) {
            return null;
        }

        return 'METABOLIC: '.$mh['weakest_label'].' is the weak link (metabolic score '.$mh['score'].') → '
            .'weave a concrete fix into food/training advice when it\'s relevant, and teach why it moves their '
            .'healthspan — don\'t just report the score.';
    }
}
