<?php

namespace App\Support\Lab;

/**
 * LAB PIPELINE FIDELITY · Part C — the gate that would have caught every "passed in sim, failed on the
 * watch" bug this session. It measures a rendered LAB night's ingested data (via {@see
 * PipelineFingerprint}, the SAME introspection the reference is built from) and asserts it reproduces the
 * canonical `pipeline_reference.json` within tolerance: the right kinds, the ppg_raw burst geometry, BOTH
 * motion channels at their real scales/coverage, populated HR/motion strips, and — when jitter is enabled
 * — the real HR-jitter distribution. If a check fails, the sim is lying about the wrist.
 */
class FidelityGate
{
    /**
     * @return array<int,array{label:string,pass:bool,detail:string}> most-severe order not required — the
     *   caller renders them all. `pass=false` on any means the LAB night doesn't match reality.
     */
    public static function check(int $profileId, bool $expectJitter = false, ?array $reference = null): array
    {
        $reference ??= self::reference();
        $fp = PipelineFingerprint::forProfile($profileId);
        $checks = [];

        $add = function (string $label, bool $pass, string $detail) use (&$checks) {
            $checks[] = ['label' => $label, 'pass' => $pass, 'detail' => $detail];
        };

        // 1) The overnight WINDOW channel (ppg_raw) is present in device_ingestions. The summary channels
        //    (hr_trend / motion_trend) don't create ingestion rows — they land in hr_samples/motion_samples,
        //    verified by the "populated" checks below.
        $seen = array_keys((array) ($fp['kinds'] ?? []));
        $add('ppg_raw ingested (not ibi)', in_array('ppg_raw', $seen, true) && ! in_array('ibi', $seen, true), 'kinds: '.implode(',', $seen ?: ['—']));

        // 2) ppg_raw burst geometry — a real overnight is ~29 s bursts, ONE epoch each.
        $len = $fp['ppg_raw']['window_len_sec']['p50'] ?? null;
        $add('ppg_raw ~29s bursts', $len !== null && $len >= 15 && $len <= 35, "window_len_sec p50={$len}");
        $ep = $fp['ppg_raw']['epochs_per_window']['p50'] ?? null;
        $add('one epoch per window', $ep !== null && (int) $ep === 1, "epochs_per_window p50={$ep}");

        // 3) BOTH motion channels present at their real (different) scales.
        $proxy = $fp['epoch_motion_proxy']['p50'] ?? null;
        $add('proxy motion (np.std scale)', $proxy !== null && $proxy > 0 && $proxy < 12, "epoch_motion p50={$proxy}");
        $t10p50 = $fp['t10_motion']['p50'] ?? null;
        $t10cov = $fp['t10_motion']['coverage_pct'] ?? null;
        $add('T10 dense motion (milli-g EMA)',
            $t10p50 !== null && $t10p50 >= 10 && $t10p50 <= 220 && ($fp['motion_samples']['count'] ?? 0) > 0,
            "t10 p50={$t10p50}, n=".($fp['motion_samples']['count'] ?? 0));
        $add('T10 coverage ≥50%', $t10cov !== null && $t10cov >= 50, "coverage={$t10cov}%");

        // 4) The strips actually populate.
        $add('hr_samples populated', ($fp['hr_samples']['count'] ?? 0) > 0, 'n='.($fp['hr_samples']['count'] ?? 0));
        $add('motion_samples populated', ($fp['motion_samples']['count'] ?? 0) > 0, 'n='.($fp['motion_samples']['count'] ?? 0));

        // 5) The real HR jitter — only asserted when the night was rendered WITH the jitter knob on.
        if ($expectJitter) {
            $j = $fp['hr_jitter_abs_dhr']['p50'] ?? null;
            $target = (float) ($reference['hr_jitter_abs_dhr']['p50'] ?? 7);
            $add('HR jitter matches real (median |ΔHR|)',
                $j !== null && $j >= max(2.0, $target - 5) && $j <= $target + 7,
                "median|ΔHR|={$j} (real ~{$target})");
        }

        return $checks;
    }

    /** True only if EVERY fidelity check passed. */
    public static function passes(array $checks): bool
    {
        foreach ($checks as $c) {
            if (! ($c['pass'] ?? false)) {
                return false;
            }
        }

        return $checks !== [];
    }

    /** @return array<string,mixed> the committed canonical reference (§4 of DATA_PIPELINE_REFERENCE). */
    public static function reference(): array
    {
        $path = base_path('biosignal/tests/fixtures/pipeline_reference.json');
        if (is_file($path)) {
            $ref = json_decode((string) file_get_contents($path), true);
            if (is_array($ref)) {
                return $ref;
            }
        }

        return ['expected_kinds' => ['ppg_raw', 'hr_trend', 'motion_trend', 'sleep_session']];
    }
}
