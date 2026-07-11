<?php

namespace App\Support\Lab;

use App\Models\ActivitySession;
use App\Models\DeviceIngestion;
use App\Models\HrSample;
use App\Models\MotionSample;
use Illuminate\Support\Carbon;

/**
 * LAB PIPELINE FIDELITY (Part A/C) — the statistical fingerprint of what actually reached the server for
 * one profile: the shapes + measured characteristics from `docs/DATA_PIPELINE_REFERENCE.md §4`. ONE
 * introspection used two ways: `lab:pipeline-reference` emits it as the canonical reference from REAL
 * data, and the SLEEP LAB fidelity gate measures a rendered LAB night the SAME way and asserts it matches
 * the reference within tolerance. If the sim doesn't reproduce these numbers, it's lying about the wrist.
 */
class PipelineFingerprint
{
    /**
     * Measure the pipeline fingerprint for a profile from its ingested data (device_ingestions +
     * hr_samples + motion_samples + activity_sessions). Works for both a real profile and a freshly
     * rendered LAB night (they're just rows).
     *
     * @return array<string,mixed>
     */
    public static function forProfile(int $profileId): array
    {
        $ppg = DeviceIngestion::where('profile_id', $profileId)->where('kind', 'ppg_raw')
            ->orderBy('window_start')->get(['window_start', 'window_end', 'result_refs']);

        // Per-kind inventory: count + result_refs key schema (what shapes the server actually received).
        $kinds = DeviceIngestion::where('profile_id', $profileId)->get(['kind', 'result_refs'])
            ->groupBy('kind')->map(fn ($rows) => [
                'count' => $rows->count(),
                'result_refs_keys' => collect($rows)->flatMap(fn ($r) => array_keys((array) $r->result_refs))
                    ->unique()->sort()->values()->all(),
            ])->all();

        // ppg_raw window geometry — a real overnight is ~100-190 sparse 29 s bursts, ONE epoch each.
        $winLens = $ppg->map(fn ($w) => $w->window_start && $w->window_end
            ? Carbon::parse($w->window_end)->timestamp - Carbon::parse($w->window_start)->timestamp : null)
            ->filter()->values()->all();
        $epochsPer = $ppg->map(fn ($w) => is_array($w->result_refs['epoch_hr'] ?? null) ? count($w->result_refs['epoch_hr']) : null)
            ->filter()->values()->all();

        // The per-epoch feature series (one value per burst), in night order — the model's inputs.
        $epochHr = $ppg->flatMap(fn ($w) => array_values((array) ($w->result_refs['epoch_hr'] ?? [])))
            ->map(fn ($v) => is_numeric($v) ? (float) $v : null)->filter(fn ($v) => $v !== null && $v > 0)->values()->all();
        $epochMotion = $ppg->flatMap(fn ($w) => array_values((array) ($w->result_refs['epoch_motion'] ?? [])))
            ->map(fn ($v) => is_numeric($v) ? (float) $v : null)->filter(fn ($v) => $v !== null)->values()->all();
        $epochRmssd = $ppg->flatMap(fn ($w) => array_values((array) ($w->result_refs['epoch_rmssd'] ?? [])))
            ->map(fn ($v) => is_numeric($v) ? (float) $v : null)->filter(fn ($v) => $v !== null && $v > 0)->values()->all();

        // THE thing that over-staged REM: the epoch-to-epoch |ΔHR| jitter of the band's duty-cycled HR.
        $dHr = [];
        for ($i = 1; $i < count($epochHr); $i++) {
            $dHr[] = abs($epochHr[$i] - $epochHr[$i - 1]);
        }

        // T10 dense motion (milli-g EMA) — a DIFFERENT channel/scale/coverage from the epoch_motion proxy.
        $motion = MotionSample::where('profile_id', $profileId)->orderBy('recorded_at')->get(['recorded_at', 'motion']);
        $motionVals = $motion->pluck('motion')->map(fn ($v) => (float) $v)->all();
        $t10Coverage = null;
        if ($motion->count() >= 2) {
            $span = Carbon::parse($motion->last()->recorded_at)->timestamp - Carbon::parse($motion->first()->recorded_at)->timestamp;
            $expected = max(1, (int) round($span / 30) + 1);
            $t10Coverage = round(100 * $motion->count() / $expected, 1);
        }

        $workouts = ActivitySession::where('profile_id', $profileId)->get(['source']);

        return [
            'generated_at' => Carbon::now()->toIso8601String(),
            'profile_id' => $profileId,
            'kinds' => $kinds,
            'ppg_raw' => [
                'window_count' => $ppg->count(),
                'window_len_sec' => self::stats($winLens),
                'epochs_per_window' => self::stats($epochsPer),
            ],
            'hr_jitter_abs_dhr' => self::stats($dHr),         // §4: median ~7, p90 ~18
            'epoch_hr' => self::stats($epochHr),              // §4: ~40-90
            'epoch_rmssd' => self::stats($epochRmssd),        // §4: ~40-180, p50 ~120
            'epoch_motion_proxy' => self::stats($epochMotion),// §4: np.std ~1.5-3
            't10_motion' => self::stats($motionVals) + ['coverage_pct' => $t10Coverage], // §4: ~14-199, ~80%
            'hr_samples' => ['count' => HrSample::where('profile_id', $profileId)->count()],
            'motion_samples' => ['count' => $motion->count()],
            'activity_sessions' => [
                'count' => $workouts->count(),
                'sources' => $workouts->pluck('source')->unique()->values()->all(),
            ],
        ];
    }

    /**
     * min / p50 / p90 / max of a numeric list (null-safe). The tolerance-friendly summary the reference
     * stores and the gate compares against.
     *
     * @param  array<int,int|float>  $vals
     * @return array{n:int,min:float|null,p50:float|null,p90:float|null,max:float|null}
     */
    public static function stats(array $vals): array
    {
        $vals = array_values(array_filter($vals, fn ($v) => is_numeric($v)));
        sort($vals);
        $n = count($vals);
        $pct = fn (float $p) => $n === 0 ? null : round((float) $vals[min($n - 1, (int) floor($p * $n))], 2);

        return [
            'n' => $n,
            'min' => $n ? round((float) $vals[0], 2) : null,
            'p50' => $pct(0.5),
            'p90' => $pct(0.9),
            'max' => $n ? round((float) $vals[$n - 1], 2) : null,
        ];
    }
}
