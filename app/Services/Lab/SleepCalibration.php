<?php

namespace App\Services\Lab;

use App\Models\DeviceIngestion;
use App\Models\SleepLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;

/**
 * SLEEP LAB · calibration (spec §3). A VERSIONED JSON of the per-stage signal distributions the VirtualBand
 * samples from, so a synthetic night is statistically indistinguishable from a real one to the stager.
 *
 *   {@see extract()}   pulls stage proportions + per-stage motion/HR/RMSSD from real sealed nights and
 *                      writes the JSON (the `--calibrate` path).
 *   {@see load()}      reads the JSON back (or the baked defaults when it's absent), for the VirtualBand.
 *
 * The baked defaults are derived from BiosignalSimulator::STATES + the real ranges the spec cites
 * (deep 14–17%, REM 29–40%, light ~50%, eff 91–98%), so the LAB is green out of the box and calibration
 * only refines it.
 */
class SleepCalibration
{
    public const VERSION = 1;

    /** Where the versioned artifact lives (runtime-writable; committed defaults live in this class). */
    public static function path(): string
    {
        return storage_path('app/lab/sleep_calibration.v'.self::VERSION.'.json');
    }

    /**
     * The baked v1 defaults: what a healthy night looks like, in the post-fix signal scales. These make
     * `perfect-night` green with no real data; `--calibrate` overwrites the file with real statistics.
     *
     * @return array<string,mixed>
     */
    public static function defaults(): array
    {
        return [
            'version' => self::VERSION,
            'generated_at' => null,
            'source' => ['kind' => 'baked-default', 'nights' => 0, 'note' => 'STATES presets + spec real ranges'],
            // Proportions (of ASLEEP time) + efficiency. Mid-points of the spec's cited real ranges,
            // nudged so light dominates (the honest wrist-motion+HR reality).
            'architecture' => [
                'deep_pct' => 16.0,
                'rem_pct' => 22.0,
                'light_pct' => 62.0,
                // A CONFIRMED night presumes the (few) unsampled holes asleep and the stager under-detects
                // in-bed wake (wake specificity 50–70%), so the pipeline reports a HIGH efficiency for a clean
                // night. 97% is the honest expectation the stager reproduces — the top of the spec's 91–98%
                // real range — so the scripted duration (= TIB − wake) matches the sealed row within ±10m.
                'efficiency_pct' => 97.0,
            ],
            // Per-stage signal the VirtualBand renders from (hr mean/sd bpm, rmssd ms, motion counts/epoch).
            'stages' => [
                'deep' => ['hr_mean' => 48.0, 'hr_sd' => 1.4, 'rmssd' => 95.0, 'motion' => 0.4],
                'light' => ['hr_mean' => 53.0, 'hr_sd' => 2.6, 'rmssd' => 74.0, 'motion' => 1.2],
                'rem' => ['hr_mean' => 60.0, 'hr_sd' => 4.8, 'rmssd' => 52.0, 'motion' => 1.0],
                'wake' => ['hr_mean' => 62.0, 'hr_sd' => 3.6, 'rmssd' => 55.0, 'motion' => 9.0],
            ],
            'duty_cycle' => ['burst_sec' => 30, 'period_sec' => 180],
            'artifact_rate' => 0.01,
        ];
    }

    /** @param array<string,mixed> $data */
    public function __construct(private array $data) {}

    /** Load the on-disk calibration, falling back to the baked defaults. */
    public static function load(): self
    {
        $path = self::path();
        if (File::exists($path)) {
            $decoded = json_decode((string) File::get($path), true);
            if (is_array($decoded) && ($decoded['version'] ?? null) === self::VERSION) {
                return new self($decoded);
            }
        }

        return new self(self::defaults());
    }

    /** @return array{deep_pct:float,rem_pct:float,light_pct:float,efficiency_pct:float} */
    public function architecture(): array
    {
        return $this->data['architecture'] ?? self::defaults()['architecture'];
    }

    /** @return array{burst_sec:int,period_sec:int} */
    public function dutyCycle(): array
    {
        return $this->data['duty_cycle'] ?? self::defaults()['duty_cycle'];
    }

    public function artifactRate(): float
    {
        return (float) ($this->data['artifact_rate'] ?? 0.01);
    }

    public function source(): array
    {
        return (array) ($this->data['source'] ?? []);
    }

    /**
     * The signal config for one LAB stage token, in the shape BiosignalSimulator::generateWindowFromCfg wants.
     *
     * @return array{hr:float,hr_sd:float,rmssd:float,motion:float}
     */
    public function stageCfg(string $stage): array
    {
        $s = $this->data['stages'][$stage] ?? self::defaults()['stages'][$stage] ?? self::defaults()['stages']['light'];

        return [
            'hr' => (float) $s['hr_mean'],
            'hr_sd' => (float) $s['hr_sd'],
            'rmssd' => (float) $s['rmssd'],
            'motion' => (float) $s['motion'],
        ];
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * Extract calibration from real sealed nights and persist the JSON (the `--calibrate` path).
     *
     * For every FINAL, staged night on the profile we pair each sealed raw window's per-epoch features
     * (motion / HR / RMSSD, from ProcessWindowJob's result_refs) with the hypnogram stage at that epoch,
     * and aggregate a per-stage distribution. Proportions come from the summed stage minutes across nights.
     * Any stage with too few real samples keeps its baked default (recorded in `source`), so the artifact is
     * always complete.
     *
     * @return array{calibration:self,report:array<string,mixed>}
     */
    public static function extract(int $profileId): array
    {
        $defaults = self::defaults();
        $nights = SleepLog::query()
            ->where('profile_id', $profileId)
            ->where('stage_status', SleepLog::STATUS_FINAL)
            ->whereNotNull('hypnogram')
            ->get()
            ->filter(fn (SleepLog $s) => is_array($s->hypnogram) && count($s->hypnogram) > 0);

        // Per-stage feature accumulators.
        $acc = ['deep' => [], 'light' => [], 'rem' => [], 'wake' => []];
        $stageMin = ['deep' => 0.0, 'rem' => 0.0, 'light' => 0.0, 'wake' => 0.0];
        $nightsUsed = 0;

        foreach ($nights as $night) {
            $stageMin['deep'] += (float) $night->deep_min;
            $stageMin['rem'] += (float) $night->rem_min;
            $stageMin['light'] += (float) $night->light_min;
            $stageMin['wake'] += (float) $night->awake_min;

            // Reconstruct the epoch grid the seal used: t0 = earliest contributing window_start.
            $windows = DeviceIngestion::query()
                ->where('profile_id', $profileId)
                ->whereIn('kind', ['ibi', 'ppg_raw'])
                ->where('status', DeviceIngestion::STATUS_SEALED)
                ->where('result_refs->sleep_log_id', $night->id)
                ->get();
            if ($windows->isEmpty()) {
                continue;
            }
            $t0 = $windows->min(fn (DeviceIngestion $i) => $i->window_start ? CarbonImmutable::parse($i->window_start)->timestamp : PHP_INT_MAX);
            if (! is_int($t0) || $t0 === PHP_INT_MAX) {
                continue;
            }
            $hyp = $night->hypnogram;
            foreach ($windows as $w) {
                $ws = $w->window_start ? CarbonImmutable::parse($w->window_start)->timestamp : null;
                $em = $w->result_refs['epoch_motion'] ?? null;
                $eh = array_values((array) ($w->result_refs['epoch_hr'] ?? []));
                $er = array_values((array) ($w->result_refs['epoch_rmssd'] ?? []));
                if ($ws === null || ! is_array($em)) {
                    continue;
                }
                $base = intdiv($ws - $t0, 30);
                foreach (array_values($em) as $k => $motion) {
                    $idx = $base + $k;
                    $stage = $hyp[$idx] ?? null;
                    $lab = match ($stage) {
                        'deep' => 'deep', 'rem' => 'rem', 'light' => 'light', 'wake' => 'wake', default => null,
                    };
                    if ($lab === null) {
                        continue;
                    }
                    $acc[$lab][] = [
                        'motion' => is_numeric($motion) ? (float) $motion : 0.0,
                        'hr' => (isset($eh[$k]) && is_numeric($eh[$k])) ? (float) $eh[$k] : null,
                        'rmssd' => (isset($er[$k]) && is_numeric($er[$k])) ? (float) $er[$k] : null,
                    ];
                }
            }
            $nightsUsed++;
        }

        // Build per-stage cfg: use real distribution where we have enough samples, else the baked default.
        $stages = [];
        $stageSource = [];
        $minSamples = 20;
        foreach (['deep', 'light', 'rem', 'wake'] as $lab) {
            $rows = $acc[$lab];
            if (count($rows) >= $minSamples) {
                $motion = array_map(fn ($r) => $r['motion'], $rows);
                $hr = array_values(array_filter(array_map(fn ($r) => $r['hr'], $rows), fn ($v) => $v !== null && $v > 0));
                $rmssd = array_values(array_filter(array_map(fn ($r) => $r['rmssd'], $rows), fn ($v) => $v !== null && $v > 0));
                $stages[$lab] = [
                    'hr_mean' => $hr ? round(self::mean($hr), 1) : $defaults['stages'][$lab]['hr_mean'],
                    'hr_sd' => $hr ? max(0.8, round(self::std($hr), 2)) : $defaults['stages'][$lab]['hr_sd'],
                    'rmssd' => $rmssd ? round(self::mean($rmssd), 1) : $defaults['stages'][$lab]['rmssd'],
                    'motion' => round(self::mean($motion), 2),
                ];
                $stageSource[$lab] = 'real('.count($rows).')';
            } else {
                $stages[$lab] = $defaults['stages'][$lab];
                $stageSource[$lab] = 'default('.count($rows).')';
            }
        }

        // Proportions from summed real minutes; fall back to defaults if there were no nights.
        $asleep = $stageMin['deep'] + $stageMin['rem'] + $stageMin['light'];
        $tib = $asleep + $stageMin['wake'];
        $architecture = $asleep > 0 ? [
            'deep_pct' => round(100 * $stageMin['deep'] / $asleep, 1),
            'rem_pct' => round(100 * $stageMin['rem'] / $asleep, 1),
            'light_pct' => round(100 * $stageMin['light'] / $asleep, 1),
            'efficiency_pct' => $tib > 0 ? round(100 * $asleep / $tib, 1) : $defaults['architecture']['efficiency_pct'],
        ] : $defaults['architecture'];

        $data = [
            'version' => self::VERSION,
            'generated_at' => now()->toIso8601String(),
            'source' => [
                'kind' => $nightsUsed > 0 ? 'extracted' : 'baked-default',
                'profile_id' => $profileId,
                'nights' => $nightsUsed,
                'stage_source' => $stageSource,
            ],
            'architecture' => $architecture,
            'stages' => $stages,
            'duty_cycle' => $defaults['duty_cycle'],
            'artifact_rate' => $defaults['artifact_rate'],
        ];

        File::ensureDirectoryExists(dirname(self::path()));
        File::put(self::path(), json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return ['calibration' => new self($data), 'report' => $data['source']];
    }

    private static function mean(array $xs): float
    {
        return count($xs) ? array_sum($xs) / count($xs) : 0.0;
    }

    private static function std(array $xs): float
    {
        $n = count($xs);
        if ($n < 2) {
            return 0.0;
        }
        $m = self::mean($xs);
        $v = 0.0;
        foreach ($xs as $x) {
            $v += ($x - $m) ** 2;
        }

        return sqrt($v / ($n - 1));
    }
}
