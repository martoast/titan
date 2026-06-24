<?php

namespace App\Support;

use App\Models\BehaviorImpact;
use App\Models\Profile;
use App\Models\RecoveryLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The correlation engine — Titan's moat. For each journaled behavior, compares the user's next-day
 * physiology on days WITH the behavior vs days WITHOUT it (Mann–Whitney U + Cliff's δ + % median
 * change, Benjamini–Hochberg across the user's tests), and stores the significant findings: "alcohol
 * cuts your recovery ~14%." n-of-1, hardware-free, gets sharper as the journal grows.
 *
 * Method follows the research: gate ≥5 with & ≥5 without; rank-based test (robust to skew/outliers);
 * surface only q<0.10 with a meaningful effect; recomputed nightly. (Tier-2 ridge regression for
 * confounder isolation is the documented next step.)
 */
class BehaviorCorrelations
{
    private const WINDOW_DAYS = 120;
    private const MIN_GROUP = 5;

    /** outcome key => [label, better direction, day offset from the behavior day]. */
    public const OUTCOMES = [
        'hrv' => ['label' => 'HRV', 'better' => 'higher', 'offset' => 1],
        'rhr' => ['label' => 'resting HR', 'better' => 'lower', 'offset' => 1],
        'sleep_quality' => ['label' => 'sleep quality', 'better' => 'higher', 'offset' => 0],
        'sleep_hours' => ['label' => 'sleep', 'better' => 'higher', 'offset' => 0],
    ];

    /** Recompute every behavior→outcome impact for a profile. Returns the count of significant ones. */
    public static function compute(Profile $profile): int
    {
        $tz = $profile->settings['timezone'] ?? config('app.timezone', 'UTC');
        $from = Carbon::now($tz)->startOfDay()->subDays(self::WINDOW_DAYS)->toDateString();

        // journaled days → date => set of behavior keys
        $logs = $profile->behaviorLogs()->where('logged_on', '>=', $from)->get(['logged_on', 'key']);
        if ($logs->isEmpty()) {
            return 0;
        }
        $byDay = [];
        foreach ($logs as $l) {
            $byDay[$l->logged_on->toDateString()][$l->key] = true;
        }
        $journaledDays = array_keys($byDay);

        // outcome maps keyed by date
        $recMap = [];
        foreach (RecoveryLog::where('profile_id', $profile->id)->where('logged_at', '>=', $from)
            ->get(['logged_at', 'hrv_ms', 'resting_hr']) as $r) {
            $recMap[$r->logged_at->toDateString()] = ['hrv' => $r->hrv_ms, 'rhr' => $r->resting_hr];
        }
        $slpMap = [];
        foreach ($profile->sleepLogs()->where('slept_at', '>=', $from)
            ->get(['slept_at', 'quality', 'duration_min']) as $s) {
            $slpMap[$s->slept_at->toDateString()] = [
                'sleep_quality' => $s->quality,
                'sleep_hours' => $s->duration_min !== null ? $s->duration_min / 60 : null,
            ];
        }

        $keys = [];
        foreach ($byDay as $set) {
            foreach (array_keys($set) as $k) {
                $keys[$k] = true;
            }
        }

        $cands = [];
        $pmap = [];
        foreach (array_keys($keys) as $bk) {
            foreach (self::OUTCOMES as $ok => $meta) {
                $with = [];
                $without = [];
                foreach ($journaledDays as $d) {
                    $outDate = $meta['offset'] === 1 ? Carbon::parse($d)->addDay()->toDateString() : $d;
                    $val = $meta['offset'] === 1 ? ($recMap[$outDate][$ok] ?? null) : ($slpMap[$outDate][$ok] ?? null);
                    if ($val === null) {
                        continue;
                    }
                    isset($byDay[$d][$bk]) ? $with[] = (float) $val : $without[] = (float) $val;
                }
                if (count($with) < self::MIN_GROUP || count($without) < self::MIN_GROUP) {
                    continue;
                }
                $mw = Stats::median($with);
                $mwo = Stats::median($without);
                $idx = $bk.'|'.$ok;
                $cands[$idx] = [
                    'bk' => $bk, 'ok' => $ok, 'nw' => count($with), 'nwo' => count($without),
                    'mw' => $mw, 'mwo' => $mwo,
                    'pct' => $mwo != 0.0 ? ($mw - $mwo) / abs($mwo) : 0.0,
                    'p' => Stats::mannWhitneyP($with, $without),
                    'delta' => Stats::cliffsDelta($with, $without),
                ];
                $pmap[$idx] = $cands[$idx]['p'];
            }
        }

        $q = Stats::benjaminiHochberg($pmap);

        $seen = [];
        $sig = 0;
        foreach ($cands as $idx => $c) {
            $qv = $q[$idx] ?? 1.0;
            $nmin = min($c['nw'], $c['nwo']);
            $significant = $qv < 0.10 && abs($c['delta']) >= 0.20 && abs($c['pct']) >= 0.03;
            $confidence = ($nmin >= 20 && $qv < 0.05) ? 'high' : (($nmin >= 12 && $qv < 0.10) ? 'medium' : 'low');

            $row = BehaviorImpact::firstOrNew([
                'profile_id' => $profile->id, 'behavior_key' => $c['bk'], 'outcome_key' => $c['ok'],
            ]);
            $row->fill([
                'n_with' => $c['nw'], 'n_without' => $c['nwo'],
                'median_with' => round($c['mw'], 2), 'median_without' => round($c['mwo'], 2),
                'pct_change' => round($c['pct'], 4), 'effect_size' => round($c['delta'], 3),
                'p_value' => round($c['p'], 4), 'q_value' => round($qv, 4),
                'confidence' => $confidence, 'significant' => $significant,
            ]);
            $row->save();
            $seen[] = $row->id;
            if ($significant) {
                $sig++;
            }
        }

        // Drop stale impacts (behavior no longer logged enough, etc.).
        $profile->behaviorImpacts()->when($seen !== [], fn ($qq) => $qq->whereNotIn('id', $seen))
            ->when($seen === [], fn ($qq) => $qq)->delete();

        return $sig;
    }

    /** The `impacts` card for the coach / app: the user's significant behavior insights. */
    public static function card(Profile $profile): array
    {
        $rows = $profile->behaviorImpacts()->where('significant', true)
            ->orderByDesc(DB::raw('abs(pct_change)'))->limit(8)->get();

        $items = $rows->map(function (BehaviorImpact $r) {
            $meta = self::OUTCOMES[$r->outcome_key] ?? ['label' => $r->outcome_key, 'better' => 'higher'];
            $raised = $r->pct_change > 0;
            $good = $meta['better'] === 'higher' ? $raised : ! $raised;

            return [
                'behavior' => Journal::label($r->behavior_key),
                'outcome' => $meta['label'],
                'pct' => (int) round($r->pct_change * 100),
                'direction' => $good ? 'good' : 'bad',
                'confidence' => $r->confidence,
                'nights' => $r->n_with,
            ];
        })->values()->all();

        return [
            'type' => 'impacts',
            'title' => 'What moves your recovery & sleep',
            'items' => $items,
            'footer' => $items === []
                ? 'Keep journaling — insights unlock after ~5 days each of a behavior.'
                : count($items).' personal insight'.(count($items) === 1 ? '' : 's'),
        ];
    }
}
