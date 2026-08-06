<?php

namespace App\Services\Lab;

use App\Models\DeviceIngestion;
use App\Models\Profile;
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

    /** Night-selection quality floor — calibrate only from HONEST nights, never a degenerate/doze row. */
    private const MIN_ASLEEP_MIN = 90;      // a real night, not a 7-minute smear

    private const MIN_EFFICIENCY = 0.50;    // drops the "7min sleep / 1280min awake" pollution

    private const MIN_COVERAGE = 0.30;      // enough real signal to trust the stages

    /** Per-stage sample floor to accept a REAL distribution over the baked default. Duty-cycled real nights
     *  are SPARSE (a 400-minute night may carry only tens of epoch samples), so this is deliberately low and
     *  leans on aggregating across nights via the robust (tag OR time-overlap) window join. */
    private const MIN_STAGE_SAMPLES = 12;

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

        // Quality floor: only calibrate from HONEST nights. A degenerate row (e.g. 7min asleep / 1280min
        // awake) would otherwise poison the proportions and drag efficiency down to nonsense.
        $nights = SleepLog::query()
            ->where('profile_id', $profileId)
            ->where('is_nap', false)
            ->where('stage_status', SleepLog::STATUS_FINAL)
            ->whereNotNull('hypnogram')
            ->get()
            ->filter(function (SleepLog $s) {
                if (! is_array($s->hypnogram) || count($s->hypnogram) === 0) {
                    return false;
                }
                $asleep = (int) $s->deep_min + (int) $s->rem_min + (int) $s->light_min;
                $tib = $asleep + (int) $s->awake_min;
                $eff = $tib > 0 ? $asleep / $tib : 0.0;
                // coverage was added later and is NULL on many real/older nights — only gate on it when it's
                // actually present, or every pre-coverage night is silently excluded (→ "0 nights used").
                $covOk = $s->coverage === null || (float) $s->coverage >= self::MIN_COVERAGE;

                return $asleep >= self::MIN_ASLEEP_MIN && $eff >= self::MIN_EFFICIENCY && $covOk;
            })
            ->values();

        // Per-stage feature accumulators.
        $acc = ['deep' => [], 'light' => [], 'rem' => [], 'wake' => []];
        $stageMin = ['deep' => 0.0, 'rem' => 0.0, 'light' => 0.0, 'wake' => 0.0];
        $nightsUsed = 0;
        $nightsWithSignal = 0;
        $nightsDetail = [];

        // The seal writes bedtime/wake in the PROFILE timezone (SealNightJob::timezoneFor →
        // Profile::effectiveTimezone), NOT app-tz. Resolve through the SAME method so the clock-time window
        // join lines up; a UTC misread shifts the overlap window hours off and matches nothing (silent no-op
        // extraction). Resolving it independently here is what let the two drift: both used to scrape
        // wearable_connections with an unordered `value('timezone')`, so on a profile whose rows disagree
        // (Alex: 15 × America/Mexico_City + 1 × America/Tijuana) the join could pick the hour the seal didn't.
        $tz = Profile::find($profileId)?->effectiveTimezone() ?: config('app.timezone', 'UTC');
        // The tz device_ingestions.window_start is STORED in — the Eloquent datetime cast writes/reads in the
        // app timezone, so the clock-overlap bounds must be serialized in this tz (NOT the profile's wearable
        // tz, which may differ, and NOT UTC). Used only to format the window_start query bounds below.
        $colTz = config('app.timezone', 'UTC');

        foreach ($nights as $night) {
            // Proportions come from EVERY quality night's sealed stage minutes (authoritative even when the raw
            // epoch features are sparse); the signal distributions only from nights that actually carry epochs.
            $stageMin['deep'] += (float) $night->deep_min;
            $stageMin['rem'] += (float) $night->rem_min;
            $stageMin['light'] += (float) $night->light_min;
            $stageMin['wake'] += (float) $night->awake_min;
            $nightsUsed++;

            [$start, $end] = self::nightBounds($night, $tz);
            if ($start === null) {
                continue; // no clock bounds → can't time-join windows for signal (proportions already counted)
            }

            // Robust window join: the sleep_log_id TAG *or* clock-time OVERLAP, unioned. Real nights are sparse
            // and the seal doesn't tag every epoch-bearing window (some carry only recovery refs), so a
            // tag-only join under-collects — or misses a whole night — and drops the stage samples to zero.
            $windows = DeviceIngestion::query()
                ->where('profile_id', $profileId)
                ->whereIn('kind', ['ibi', 'ppg_raw'])
                ->where('status', DeviceIngestion::STATUS_SEALED)
                ->where(function ($q) use ($night, $start, $end, $colTz) {
                    // $start/$end are absolute epochs. device_ingestions.window_start is stored in the Eloquent
                    // cast tz (app timezone — verified: the raw column is app-local wall-clock, NOT UTC), so the
                    // bounds MUST be formatted in that same tz. Forcing UTC here shifted the window by the tz
                    // offset and matched only the post-midnight slice of each night — dropping the early,
                    // deep-heavy hours entirely (deep → default, acceptance REJECT).
                    $q->where('result_refs->sleep_log_id', $night->id)
                        ->orWhereBetween('window_start', [
                            CarbonImmutable::createFromTimestamp($start - 300, $colTz),
                            CarbonImmutable::createFromTimestamp($end + 300, $colTz),
                        ]);
                })
                ->get();

            $motionWindows = $windows->filter(fn (DeviceIngestion $w) => $w->window_start !== null
                && is_array($w->result_refs['epoch_motion'] ?? null));
            if ($motionWindows->isEmpty()) {
                continue; // proportions counted; no epoch features to sample this night
            }

            // Anchor the epoch index EXACTLY as the seal did — t0 = earliest contributing window_start, NOT
            // bedtime (SealNightJob::stageSparse builds the grid as base = floor((window_start − t0)/30), with
            // t0 = the min window_start of the sealed session). Prefer the tagged (sleep_log_id) windows so the
            // anchor equals the seal's; only a fully-untagged night falls back to the overlap set.
            $tagged = $motionWindows->filter(fn (DeviceIngestion $w) => ($w->result_refs['sleep_log_id'] ?? null) == $night->id);
            $anchorSet = $tagged->isNotEmpty() ? $tagged : $motionWindows;
            $t0 = (int) $anchorSet->min(fn (DeviceIngestion $w) => $w->window_start->timestamp);
            $hyp = $night->hypnogram;
            $gotSignal = false;
            $nc = ['deep' => 0, 'light' => 0, 'rem' => 0, 'wake' => 0];
            $oob = 0;       // epoch samples whose mapped index fell outside the hypnogram (anchor/length skew)
            $nodata = 0;    // mapped onto a NODATA / non-stage hypnogram epoch
            foreach ($motionWindows as $w) {
                $ws = (int) $w->window_start->timestamp;
                $rr = (array) $w->result_refs;
                $em = $rr['epoch_motion'];
                $eh = array_values((array) ($rr['epoch_hr'] ?? []));
                $er = array_values((array) ($rr['epoch_rmssd'] ?? []));
                $base = intdiv($ws - $t0, 30);
                foreach (array_values($em) as $k => $motion) {
                    $idx = $base + $k;
                    if ($idx < 0 || $idx >= count($hyp)) {
                        $oob++;
                        continue;
                    }
                    $lab = match ($hyp[$idx] ?? null) {
                        'deep' => 'deep', 'rem' => 'rem', 'light' => 'light', 'wake' => 'wake', default => null,
                    };
                    if ($lab === null) {
                        $nodata++;
                        continue;
                    }
                    $acc[$lab][] = [
                        'motion' => is_numeric($motion) ? (float) $motion : 0.0,
                        'hr' => (isset($eh[$k]) && is_numeric($eh[$k])) ? (float) $eh[$k] : null,
                        'rmssd' => (isset($er[$k]) && is_numeric($er[$k])) ? (float) $er[$k] : null,
                    ];
                    $nc[$lab]++;
                    $gotSignal = true;
                }
            }
            if ($gotSignal) {
                $nightsWithSignal++;
            }
            // Per-night diagnostic — surfaces WHY a stage under-samples (sparsity vs misalignment): the epochs
            // each stage HAS in the hypnogram vs how many got a real motion sample, plus out-of-bounds/nodata
            // mapping counts and whether the anchor came from the sleep_log_id tag.
            $hypCounts = array_count_values(array_map('strval', $hyp));
            $nightsDetail[] = [
                'date' => $night->slept_at?->toDateString(),
                'tagged' => $tagged->isNotEmpty(),
                'scope_windows' => $windows->count(),         // ibi+ppg_raw sealed windows joined for this night
                'motion_windows' => $motionWindows->count(),  // …of those, the ones carrying epoch_motion (accel)
                'oob' => $oob,
                'nodata' => $nodata,
                'sampled' => $nc,
                'hyp_epochs' => [
                    'deep' => (int) ($hypCounts['deep'] ?? 0),
                    'light' => (int) ($hypCounts['light'] ?? 0),
                    'rem' => (int) ($hypCounts['rem'] ?? 0),
                    'wake' => (int) ($hypCounts['wake'] ?? 0),
                ],
            ];
        }

        // Build per-stage cfg: use real distribution where we have enough samples, else the baked default.
        $stages = [];
        $stageSource = [];
        $minSamples = self::MIN_STAGE_SAMPLES;
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

        // Motion is the DOMINANT signal the stager keys on, and deep is physiologically the STILLEST stage.
        // Deep is also the hardest to sample: epoch_motion needs accel, which only the sparse ibi windows
        // carry (the overnight is mostly ppg_raw), and those movement-bearing windows bias AWAY from deep — so
        // deep routinely falls back to the flat baked default. Real light/REM wrist motion can sit BELOW that
        // default, inverting the order so the generator renders light/REM stiller than deep and the stager
        // mislabels them deep (the field report's rendered deep 46% vs 12% scripted). When deep is defaulted
        // but light/REM are real, clamp deep's motion CLEARLY below the lightest sampled sleep stage so the
        // separation the stager needs always holds. Never raises deep; a real deep distribution is untouched.
        $sampledSleep = array_values(array_filter(['light', 'rem'], fn ($s) => str_starts_with($stageSource[$s], 'real')));
        if (! str_starts_with($stageSource['deep'], 'real') && $sampledSleep !== []) {
            $lightestSampled = min(array_map(fn ($s) => $stages[$s]['motion'], $sampledSleep));
            $stages['deep']['motion'] = round(min($stages['deep']['motion'], $lightestSampled * 0.5), 2);
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
                'nights_with_signal' => $nightsWithSignal,
                'stage_source' => $stageSource,
            ],
            'architecture' => $architecture,
            'stages' => $stages,
            'duty_cycle' => $defaults['duty_cycle'],
            'artifact_rate' => $defaults['artifact_rate'],
        ];

        File::ensureDirectoryExists(dirname(self::path()));
        File::put(self::path(), json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return ['calibration' => new self($data), 'report' => $data['source'], 'diagnostics' => $nightsDetail];
    }

    /**
     * A night's absolute [start, end] epoch bounds from its stored clock times. bedtime/wake_time are
     * "H:i(:s)" strings in the PROFILE/device timezone ($tz, resolved by the caller to match the seal), on the
     * slept_at date; a bedtime clock AFTER the wake clock means bed was the prior day.
     *
     * @return array{0:?int,1:?int}
     */
    private static function nightBounds(SleepLog $n, string $tz): array
    {
        $bed = $n->bedtime;
        $wake = $n->wake_time;
        $date = $n->slept_at?->toDateString();
        if (! $bed || ! $wake || ! $date) {
            return [null, null];
        }
        $wakeAt = CarbonImmutable::parse($date.' '.$wake, $tz);
        $bedAt = CarbonImmutable::parse($date.' '.$bed, $tz);
        if ($bedAt->greaterThan($wakeAt)) {
            $bedAt = $bedAt->subDay();
        }

        return [$bedAt->timestamp, $wakeAt->timestamp];
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
