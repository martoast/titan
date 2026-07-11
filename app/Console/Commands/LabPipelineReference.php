<?php

namespace App\Console\Commands;

use App\Support\Lab\PipelineFingerprint;
use Illuminate\Console\Command;

/**
 * LAB PIPELINE FIDELITY · Part A — introspect a REAL profile's ingested data and emit the canonical
 * pipeline reference (the statistical fingerprint of docs/DATA_PIPELINE_REFERENCE.md §4) as JSON. This is
 * the LAB's acceptance target; the SLEEP LAB fidelity gate measures a rendered night the SAME way (via
 * PipelineFingerprint) and asserts it matches. Re-run on prod as real data grows to keep the target current.
 *
 *   php artisan lab:pipeline-reference --profile=1
 *   php artisan lab:pipeline-reference --profile=1 --out=biosignal/tests/fixtures/pipeline_reference.json
 */
class LabPipelineReference extends Command
{
    protected $signature = 'lab:pipeline-reference {--profile= : Profile id to fingerprint}
        {--out=biosignal/tests/fixtures/pipeline_reference.json : Where to write the reference JSON}';

    protected $description = 'Emit the canonical watch→server pipeline fingerprint from a real profile\'s ingested data.';

    public function handle(): int
    {
        $profileId = (int) $this->option('profile');
        if ($profileId <= 0) {
            $this->error('Pass --profile=<id> (a profile with real ingested data).');

            return self::FAILURE;
        }

        $fp = PipelineFingerprint::forProfile($profileId);

        if (($fp['ppg_raw']['window_count'] ?? 0) === 0 && ($fp['motion_samples']['count'] ?? 0) === 0) {
            $this->warn("Profile {$profileId} has no ppg_raw windows or motion_samples — nothing to fingerprint.");
        }

        $out = base_path((string) $this->option('out'));
        @mkdir(dirname($out), 0755, true);
        file_put_contents($out, json_encode($fp, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        $this->info("Pipeline reference written → {$out}");
        $this->line(sprintf('  ppg_raw windows: %d · HR jitter median|ΔHR|: %s · T10 motion p50: %s (%s%% cov) · motion_samples: %d',
            $fp['ppg_raw']['window_count'] ?? 0,
            $fp['hr_jitter_abs_dhr']['p50'] ?? 'n/a',
            $fp['t10_motion']['p50'] ?? 'n/a',
            $fp['t10_motion']['coverage_pct'] ?? 'n/a',
            $fp['motion_samples']['count'] ?? 0,
        ));

        return self::SUCCESS;
    }
}
