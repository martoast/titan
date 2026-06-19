<?php

namespace App\Support;

use App\Models\Profile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Are we on track to the DREAM PHYSIQUE? This is Titan's north star -- the goal everything is working
 * toward. It reads the active physique goal + the living-render progression (step_pct = % of the way
 * there, driven by adherence), the bodyweight trajectory and the week score, and produces an honest
 * "on track / behind / ahead" read with a rough ETA. Surfaced as a card AND injected into the coach's
 * context every turn so the goal stays front and centre.
 */
class PhysiqueProgress
{
    private const MAX_STEP_PER_WEEK = 12;   // mirrors LivingGoalService: max % progress per consistent week

    /** @return array<string,mixed>|null  null when no dream physique has been set yet */
    public static function assess(Profile $profile): ?array
    {
        if (! class_exists(\App\Models\PhysiqueGoal::class)) {
            return null;
        }
        $goal = $profile->physiqueGoals()->where('is_active', true)->latest('id')->first()
            ?? $profile->physiqueGoals()->latest('id')->first();
        if (! $goal) {
            return null;
        }

        $renders = class_exists(\App\Models\LivingGoalRender::class)
            ? $profile->livingGoalRenders()->orderBy('id')->get(['step_pct', 'adherence', 'image_path', 'created_at'])
            : collect();
        $latest = $renders->last();
        $stepPct = (int) ($latest->step_pct ?? 0);
        $adherence = $latest && $latest->adherence !== null ? (float) $latest->adherence : null;

        // On-track verdict -- adherence is what actually moves the picture toward the goal.
        [$verdict, $verdictLabel] = match (true) {
            $adherence === null => ['just_started', 'Just getting started'],
            $adherence >= 0.8 => ['ahead', 'Ahead of pace'],
            $adherence >= 0.6 => ['on_track', 'On track'],
            $adherence >= 0.4 => ['steady', 'Holding steady'],
            default => ['behind', 'Falling behind'],
        };

        $pacePct = $adherence !== null ? (int) round($adherence * self::MAX_STEP_PER_WEEK) : null;
        $etaWeeks = ($adherence !== null && $adherence > 0.05 && $stepPct < 100)
            ? (int) ceil((100 - $stepPct) / max(1, $adherence * self::MAX_STEP_PER_WEEK))
            : null;

        return [
            'goal_id' => $goal->id,
            'description' => $goal->description ?: 'Your dream physique',
            'goal_image' => method_exists($goal, 'goalUrl') ? $goal->goalUrl() : null,
            'render_image' => $latest && $latest->image_path ? Storage::disk('public')->url($latest->image_path) : ($goal->goalUrl() ?? null),
            'step_pct' => $stepPct,
            'verdict' => $verdict,
            'verdict_label' => $verdictLabel,
            'adherence_pct' => $adherence !== null ? (int) round($adherence * 100) : null,
            'pace_pct' => $pacePct,
            'eta_weeks' => $etaWeeks,
            'weight' => self::weight($profile),
            'week_score' => self::weekScore($profile),
            'trend' => $renders->pluck('step_pct')->map(fn ($s) => (int) $s)->values()->all(),
            'renders' => $renders->count(),
        ];
    }

    /** A one-line north-star digest for the system prompt. */
    public static function digest(Profile $profile): string
    {
        $a = self::assess($profile);
        if (! $a) {
            return '';
        }
        $bits = "Goal: {$a['description']}. They're ~{$a['step_pct']}% of the way there ({$a['verdict_label']})";
        if ($a['adherence_pct'] !== null) {
            $bits .= "; recent consistency {$a['adherence_pct']}%";
        }
        if ($a['eta_weeks'] !== null) {
            $bits .= "; ~{$a['eta_weeks']} weeks to goal at this pace";
        }

        return $bits.'.';
    }

    private static function weight(Profile $profile): ?array
    {
        if (! class_exists(\App\Models\BodyMetric::class)) {
            return null;
        }
        $latest = $profile->bodyMetrics()->whereNotNull('weight_kg')->orderByDesc('taken_at')->first();
        if (! $latest) {
            return null;
        }
        $prior = $profile->bodyMetrics()->whereNotNull('weight_kg')
            ->where('taken_at', '<', Carbon::parse($latest->taken_at)->subDays(21))->orderByDesc('taken_at')->first();

        $imperial = ($profile->settings['units'] ?? 'metric') === 'imperial';
        $conv = fn ($kg) => $imperial ? $kg * 2.2046226 : $kg;
        $unit = $imperial ? 'lb' : 'kg';
        $cur = round($conv((float) $latest->weight_kg), 1);
        $delta = $prior ? round($conv((float) $latest->weight_kg - (float) $prior->weight_kg), 1) : null;

        return ['value' => $cur, 'unit' => $unit, 'delta' => $delta];
    }

    private static function weekScore(Profile $profile): ?int
    {
        if (! class_exists(\App\Models\WeeklySnapshot::class)) {
            return null;
        }

        return $profile->weeklySnapshots()->whereNotNull('score')->orderByDesc('week_start')->value('score');
    }
}
