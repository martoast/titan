<?php

namespace App\Support;

use App\Models\Profile;
use App\Models\RecoveryLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The insight feed — 3–5 ranked, glanceable cards surfacing the few things worth the user's
 * attention right now: an anomaly/early-warning, goal progress, a win, the strongest behavior
 * correlation. Computed on demand (always fresh). Less is more (Fitbit got +30% DAU from fewer
 * metrics) — we rank by signal and cap the list.
 */
class InsightFeed
{
    /** @return array<int,array<string,mixed>> ranked `insight` cards */
    public static function build(Profile $profile, int $limit = 5): array
    {
        $cards = [];
        foreach (['anomaly', 'goal', 'win', 'correlation'] as $gen) {
            $card = rescue(fn () => self::{$gen}($profile), null, false);
            if (is_array($card)) {
                $card['type'] = 'insight';
                $cards[] = $card;
            }
        }
        usort($cards, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($cards, 0, $limit);
    }

    private static function correlation(Profile $p): ?array
    {
        $r = $p->behaviorImpacts()->where('significant', true)
            ->orderByDesc(DB::raw('abs(pct_change)'))->first();
        if (! $r) {
            return null;
        }
        $meta = BehaviorCorrelations::OUTCOMES[$r->outcome_key] ?? ['label' => $r->outcome_key, 'better' => 'higher'];
        $raised = $r->pct_change > 0;
        $good = $meta['better'] === 'higher' ? $raised : ! $raised;
        $pct = abs((int) round($r->pct_change * 100));

        return [
            'kind' => 'correlation', 'tone' => $good ? 'good' : 'bad', 'icon' => 'sparkles',
            'title' => Journal::label($r->behavior_key).' '.($good ? 'lifts' : 'lowers').' your '.$meta['label'],
            'detail' => "~{$pct}% across {$r->n_with} days you logged it. ".($good ? 'Keep it up.' : 'Worth cutting back.'),
            'score' => 0.70 + min(0.20, abs($r->pct_change)),
        ];
    }

    private static function goal(Profile $p): ?array
    {
        $goal = WeightTrend::activeGoal($p);
        if (! $goal) {
            return null;
        }
        $proj = WeightTrend::projection($p, $goal->target_value);
        if ($proj['projected_date'] === null) {
            return [
                'kind' => 'goal', 'tone' => 'neutral', 'icon' => 'target',
                'title' => 'Weight goal set', 'detail' => 'Log a few weigh-ins and I’ll project your finish date.',
                'score' => 0.50,
            ];
        }
        $date = Carbon::parse($proj['projected_date'])->isoFormat('MMM D');
        $tone = 'good';
        $detail = "On your current trend you'll hit ".round($goal->target_value, 1)." kg around {$date}.";
        if ($goal->target_date) {
            $vs = Carbon::parse($proj['projected_date'])->diffInDays(Carbon::parse($goal->target_date), false);
            if ($vs >= 0) {
                $detail .= " ~{$vs} days ahead of your goal. 🔥";
            } else {
                $detail .= ' ~'.abs($vs).' days behind — want to tighten the plan?';
                $tone = 'bad';
            }
        }

        return [
            'kind' => 'goal', 'tone' => $tone, 'icon' => 'target',
            'title' => $tone === 'good' ? 'On track to your goal' : 'Behind your goal', 'detail' => $detail,
            'score' => 0.75,
        ];
    }

    private static function anomaly(Profile $p): ?array
    {
        $rows = RecoveryLog::where('profile_id', $p->id)->whereNotNull('hrv_ms')
            ->orderByDesc('logged_at')->orderByDesc('id')->limit(31)->get(['hrv_ms']);
        if ($rows->count() < 8) {
            return null;
        }
        $latest = (float) $rows->first()->hrv_ms;
        $hist = $rows->slice(1)->map(fn ($r) => (float) $r->hrv_ms)->all();
        $mean = array_sum($hist) / count($hist);
        $var = array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $hist)) / max(1, count($hist) - 1);
        $sd = sqrt($var);
        if ($sd <= 0 || $mean <= 0) {
            return null;
        }
        $z = ($latest - $mean) / $sd;

        if ($z <= -1.5) {
            $pct = abs((int) round(($latest - $mean) / $mean * 100));

            return [
                'kind' => 'anomaly', 'tone' => 'alert', 'icon' => 'exclamationmark.triangle.fill',
                'title' => 'HRV below your baseline',
                'detail' => "Today's HRV is ~{$pct}% under your normal — you may be under-recovered or fighting something. Consider an easy day and an early night.",
                'score' => 0.95,
            ];
        }
        if ($z >= 1.5) {
            return [
                'kind' => 'win', 'tone' => 'good', 'icon' => 'bolt.heart.fill',
                'title' => 'Primed — HRV running high',
                'detail' => "Your HRV is well above baseline. You're well recovered — a good day to push.",
                'score' => 0.80,
            ];
        }

        return null;
    }

    private static function win(Profile $p): ?array
    {
        $sleeps = $p->sleepLogs()->whereNotNull('quality')->orderByDesc('slept_at')->orderByDesc('id')
            ->limit(30)->get(['quality']);
        if ($sleeps->count() < 14) {
            return null;
        }
        $latest = (int) $sleeps->first()->quality;
        if ($latest >= 70 && $latest === (int) $sleeps->max('quality')) {
            return [
                'kind' => 'win', 'tone' => 'good', 'icon' => 'trophy.fill',
                'title' => 'Best sleep in weeks',
                'detail' => "Last night ({$latest}) was your best in ".$sleeps->count()." nights. Whatever you did — repeat it.",
                'score' => 0.72,
            ];
        }

        return null;
    }
}
