<?php

namespace App\Services\Simulator;

/**
 * Synthetic physiology engine -- the digital twin of the Titan band's signal output.
 *
 * Generates physiologically-plausible heart-rate, beat-to-beat IBI/RR series, HRV
 * (RMSSD), resting HR, accelerometer activity counts and a full night of sleep
 * stages, so the real Titan ingestion pipeline can be exercised end-to-end before
 * hardware exists. The same logic runs in the browser (see resources/views/simulator)
 * for the live "wear it" view; this PHP copy drives the headless artisan command and
 * the /simulator POST endpoints.
 *
 * Design references (tasks/titan-wearable/03-algorithms.md):
 *   - HR by activity state: deep sleep ~48, rest ~58, walk ~95, run ~150 bpm.
 *   - RMSSD higher at rest/sleep, lower in slow-wave + under load; report whole-night.
 *   - RHR = min of windowed medians over sleep (§4).
 *   - Sleep stages cycle awake→light→deep→REM over ~90-min cycles (§3).
 *
 * Everything is deterministic given a seed so a night can be reproduced/audited.
 */
class BiosignalSimulator
{
    /** Per-state physiology presets: target HR, beat-to-beat SD, motion level. */
    public const STATES = [
        'deep'  => ['label' => 'Deep sleep', 'hr' => 48,  'hr_sd' => 1.4, 'rmssd' => 95, 'motion' => 0.2],
        'sleep' => ['label' => 'Sleep',      'hr' => 52,  'hr_sd' => 2.6, 'rmssd' => 78, 'motion' => 0.6],
        'rem'   => ['label' => 'REM',        'hr' => 60,  'hr_sd' => 4.5, 'rmssd' => 55, 'motion' => 1.2],
        'rest'  => ['label' => 'Rest',       'hr' => 58,  'hr_sd' => 3.2, 'rmssd' => 65, 'motion' => 1.5],
        'walk'  => ['label' => 'Walk',       'hr' => 95,  'hr_sd' => 2.2, 'rmssd' => 28, 'motion' => 14],
        'run'   => ['label' => 'Run',        'hr' => 150, 'hr_sd' => 1.6, 'rmssd' => 9,  'motion' => 42],
    ];

    /**
     * Workout presets for the Bangle.js 2's GPS + barometer. `speed` is steady-state GPS pace
     * (km/h), `hrr_frac` the HR-reserve fraction the effort sits at. Mirrors the Python twin
     * (biosignal/app/sim/workout.py); the Python side owns the accel-classification-accurate
     * replay, this PHP twin drives the FITNESS path (VO2max / HRR / TRIMP), which depends on HR +
     * pace + grade, not the raw accel signature -- so synthetic physiology is faithful here.
     */
    public const WORKOUTS = [
        'walk'  => ['speed' => 5.2,  'hrr_frac' => 0.45],
        'run'   => ['speed' => 10.5, 'hrr_frac' => 0.78],
        'cycle' => ['speed' => 22.0, 'hrr_frac' => 0.68],
    ];

    private int $seed;

    private float $z2 = 0.0;

    private bool $haveZ2 = false;

    public function __construct(?int $seed = null)
    {
        $this->seed = $seed ?? random_int(1, PHP_INT_MAX);
        mt_srand($this->seed);
    }

    public function seed(): int
    {
        return $this->seed;
    }

    /**
     * A single seeded standard-normal draw — the public seam the WORKOUT LAB's {@see \App\Services\Lab\VirtualAthlete}
     * uses to add physiologically-plausible noise to a rendered workout while staying deterministic per seed
     * (so there is still ONE signal engine, not two). Same generator {@see generateWindowFromCfg} uses internally.
     */
    public function gaussPublic(): float
    {
        return $this->gauss();
    }

    /**
     * Generate an IBI (inter-beat interval) series, in ms, for a span of `$seconds`
     * spent in a given activity `$state`. Models a mean HR with natural beat-to-beat
     * variation plus respiratory sinus arrhythmia (the ~0.25 Hz oscillation that *is*
     * HRV) and occasional ectopic-ish jitter, mirroring what a clean PPG peak-detector
     * would emit on the band's edge.
     *
     * @return array{ibi_ms: array<int,int>, accel_counts: array<int,int>}
     */
    public function generateWindow(string $state, int $seconds): array
    {
        return $this->generateWindowFromCfg(self::STATES[$state] ?? self::STATES['rest'], $seconds);
    }

    /**
     * Same physiology engine as {@see generateWindow()} but driven by an EXPLICIT per-state config
     * (`hr`, `hr_sd`, `rmssd`, `motion`) instead of a fixed STATES preset. This is the single seam the
     * SLEEP LAB's VirtualBand uses to render a stage from a *calibrated* distribution (per-stage HR / HRV /
     * motion extracted from real sealed nights) rather than the hard-coded presets — so there is still ONE
     * signal engine, not two. `generateWindow()` is just this with a STATES lookup.
     *
     * @param  array{hr:float|int,hr_sd?:float|int,rmssd:float|int,motion:float|int}  $cfg
     * @return array{ibi_ms: array<int,int>, accel_counts: array<int,int>}
     */
    public function generateWindowFromCfg(array $cfg, int $seconds): array
    {
        $cfg += ['hr_sd' => 2.6, 'motion' => 0.6, 'rmssd' => 70];
        $meanIbi = 60000.0 / max(20, (float) $cfg['hr']);
        // RMSSD target → per-beat sd of successive differences. RMSSD ≈ sd * sqrt(2),
        // so sd ≈ rmssd / sqrt(2). Keep it bounded relative to the mean.
        $sd = min($cfg['rmssd'] / 1.4142, $meanIbi * 0.18);

        $ibi = [];
        $elapsed = 0.0;
        $rsaPhase = $this->frand() * M_PI * 2;
        $prev = $meanIbi;

        while ($elapsed < $seconds * 1000.0) {
            // Respiratory sinus arrhythmia: breathing modulates IBI ±. Stronger at rest.
            $rsaPhase += 2 * M_PI * 0.25 * ($prev / 1000.0); // ~0.25 Hz (15 brpm)
            $rsa = sin($rsaPhase) * $sd * 0.6;

            // AR(1) successive-difference noise gives a realistic RMSSD without drift.
            $beat = $meanIbi + $rsa + $this->gauss() * $sd * 0.9;
            // Rare ectopic / missed-beat artifact (more likely in motion).
            if ($this->frand() < ($cfg['motion'] > 5 ? 0.012 : 0.002)) {
                $beat += ($this->frand() < 0.5 ? -1 : 1) * $meanIbi * 0.35;
            }
            // Clamp to physiological + the pipeline's documented IBI gate (300-2000 ms).
            $beat = max(320, min(1900, $beat));
            $ibi[] = (int) round($beat);
            $elapsed += $beat;
            $prev = $beat;
        }

        return [
            'ibi_ms' => $ibi,
            'accel_counts' => $this->accelCounts($cfg['motion'], max(1, (int) round($seconds / 30))),
        ];
    }

    /**
     * Synthesize a raw PPG waveform (Int16 samples at $fs Hz) from an IBI series — so the simulator
     * can stream the REAL Titan-band overnight shape (`ppg_raw`, server does peak-detect → IBI →
     * stages) instead of pre-detected IBI. Each beat is a narrow Gaussian pulse; we only touch the
     * few samples around each beat, so a whole night synthesizes in O(beats) not O(beats × samples).
     *
     * @param  array<int,int>  $ibiMs
     * @return array<int,int>  Int16-range PPG samples
     */
    public static function ppgFromIbi(array $ibiMs, int $fs): array
    {
        $beatT = [];
        $t = 0.0;
        foreach ($ibiMs as $ms) {
            $t += $ms / 1000.0;
            $beatT[] = $t;
        }
        $dur = $t;
        $n = max(1, (int) floor($dur * $fs));
        $ppg = array_fill(0, $n, 0.0);

        $sigma = 0.04;                       // ~40 ms systolic pulse width
        $twoSig2 = 2 * $sigma * $sigma;
        $halfW = (int) ceil(4 * $sigma * $fs);  // ±4σ of samples per beat
        foreach ($beatT as $bt) {
            $center = ($bt + 0.05) * $fs;
            $lo = max(0, (int) floor($center) - $halfW);
            $hi = min($n - 1, (int) ceil($center) + $halfW);
            for ($i = $lo; $i <= $hi; $i++) {
                $dt = $i / $fs - $bt - 0.05;
                $ppg[$i] += exp(-($dt * $dt) / $twoSig2);
            }
        }

        // Scale into a comfortable Int16 range (deterministic; peak detection doesn't need noise).
        return array_map(fn ($v) => (int) round($v * 6000), $ppg);
    }

    /**
     * Accelerometer activity counts (Actigraph-style epoch counts) for `$epochs`
     * 30-second epochs at the given motion level. Sleep ≈ near-zero with occasional
     * micro-movements; running ≈ high, steady.
     *
     * @return array<int,int>
     */
    public function accelCounts(float $motion, int $epochs): array
    {
        $out = [];
        for ($i = 0; $i < $epochs; $i++) {
            $base = $motion + $this->gauss() * $motion * 0.25;
            // Sleep occasionally twitches.
            if ($motion < 2 && $this->frand() < 0.08) {
                $base += $this->frand() * 8;
            }
            $out[] = max(0, (int) round($base));
        }

        return $out;
    }

    /** RMSSD (ms) of an IBI series -- the primary vagal HRV metric (§2). */
    public static function rmssd(array $ibi): float
    {
        $n = count($ibi);
        if ($n < 2) {
            return 0.0;
        }
        $sum = 0.0;
        for ($i = 1; $i < $n; $i++) {
            $d = $ibi[$i] - $ibi[$i - 1];
            $sum += $d * $d;
        }

        return round(sqrt($sum / ($n - 1)), 1);
    }

    /** SDNN (ms) -- standard deviation of NN intervals. */
    public static function sdnn(array $ibi): float
    {
        $n = count($ibi);
        if ($n < 2) {
            return 0.0;
        }
        $mean = array_sum($ibi) / $n;
        $var = 0.0;
        foreach ($ibi as $v) {
            $var += ($v - $mean) ** 2;
        }

        return round(sqrt($var / ($n - 1)), 1);
    }

    /** Mean instantaneous HR (bpm) from an IBI series. */
    public static function meanHr(array $ibi): int
    {
        if (empty($ibi)) {
            return 0;
        }

        return (int) round(60000.0 / (array_sum($ibi) / count($ibi)));
    }

    /**
     * A full night's hypnogram: a list of {state, minutes} segments progressing
     * through realistic NREM/REM cycles. Early night is deep-sleep-heavy; later
     * cycles lengthen REM -- the textbook architecture (§3).
     *
     * @return array<int,array{state:string,minutes:int}>
     */
    public function hypnogram(int $totalMinutes = 480): array
    {
        $segments = [];
        $remaining = $totalMinutes;

        // Sleep onset: a short awake/settling block then light sleep.
        $segments[] = ['state' => 'rest', 'minutes' => $onset = $this->randInt(4, 12)];
        $remaining -= $onset;

        $cycle = 0;
        while ($remaining > 12) {
            $cycle++;
            // Light sleep entry every cycle.
            $light = $this->randInt(15, 28);
            $segments[] = ['state' => 'sleep', 'minutes' => min($light, $remaining)];
            $remaining -= $light;
            if ($remaining <= 0) {
                break;
            }

            // Deep (slow-wave) dominates the first ~2 cycles, fades later.
            $deep = max(0, (int) round($this->randInt(18, 35) * max(0.15, 1 - $cycle * 0.28)));
            if ($deep > 2 && $remaining > 0) {
                $segments[] = ['state' => 'deep', 'minutes' => min($deep, $remaining)];
                $remaining -= $deep;
            }
            if ($remaining <= 0) {
                break;
            }

            // A little more light before REM.
            $light2 = $this->randInt(6, 14);
            $segments[] = ['state' => 'sleep', 'minutes' => min($light2, $remaining)];
            $remaining -= $light2;
            if ($remaining <= 0) {
                break;
            }

            // REM grows across the night.
            $rem = (int) round($this->randInt(8, 18) * min(2.0, 0.4 + $cycle * 0.4));
            $segments[] = ['state' => 'rem', 'minutes' => min($rem, $remaining)];
            $remaining -= $rem;

            // Brief awakening between cycles (low wake specificity in real data).
            if ($remaining > 6 && $this->frand() < 0.4) {
                $wake = $this->randInt(1, 4);
                $segments[] = ['state' => 'rest', 'minutes' => min($wake, $remaining)];
                $remaining -= $wake;
            }
        }
        if ($remaining > 0) {
            $segments[] = ['state' => 'sleep', 'minutes' => $remaining];
        }

        return $segments;
    }

    /**
     * Summarise a hypnogram into the Shape-C sleep summary fields the pipeline expects
     * (deep/rem/light/awake minutes, quality). "rest" segments count as awake-in-bed.
     *
     * @param  array<int,array{state:string,minutes:int}>  $segments
     * @return array{duration_min:int,deep_min:int,rem_min:int,light_min:int,awake_min:int,quality:int}
     */
    public static function summariseSleep(array $segments): array
    {
        $deep = $rem = $light = $awake = 0;
        foreach ($segments as $s) {
            match ($s['state']) {
                'deep' => $deep += $s['minutes'],
                'rem' => $rem += $s['minutes'],
                'sleep' => $light += $s['minutes'],
                default => $awake += $s['minutes'],
            };
        }
        $asleep = $deep + $rem + $light;
        $duration = $asleep + $awake;
        // Quality heuristic: rewards deep + REM proportion and sleep efficiency.
        $efficiency = $duration > 0 ? $asleep / $duration : 0;
        $restorative = $asleep > 0 ? ($deep + $rem) / $asleep : 0;
        $quality = (int) round(min(100, max(0, ($efficiency * 0.6 + $restorative * 0.9) * 100)));

        return [
            'duration_min' => $duration,
            'deep_min' => $deep,
            'rem_min' => $rem,
            'light_min' => $light,
            'awake_min' => $awake,
            'quality' => $quality,
        ];
    }

    /**
     * Simulate a GPS-paced workout (Bangle.js 2 GPS + barometer) → the request shapes the
     * biosignal /process/fitness and /process/activity endpoints accept. `fitness` 0..1 (fitter
     * → lower HR + faster pace), `hills` 0..1 scales the baro grade. HR runs a warmup ramp →
     * cardiac-drift steady → exponential cooldown decay (so HRR is real). Deterministic per seed.
     *
     * @return array{activity:string,profile:array,accel_counts:array,hr_epoch_bpm:array,run:array,distance_km:float}
     */
    public function generateWorkout(string $activity, int $minutes = 30, float $fitness = 0.5, float $hills = 0.4, float $age = 33.0): array
    {
        $w = self::WORKOUTS[$activity] ?? self::WORKOUTS['run'];
        $hrRest = 46 + (1 - $fitness) * 18;
        $hrMax = 208 - 0.7 * $age;
        $target = $hrRest + $w['hrr_frac'] * ($hrMax - $hrRest) * (1.05 - 0.10 * $fitness);
        $spdTarget = $w['speed'] * (0.9 + 0.25 * $fitness);

        $dur = $minutes * 60;
        $warm = min(120.0, $dur * 0.2);
        $tCool = $dur - min(180.0, $dur * 0.25);   // exercise stops; the tail is recovery
        $tau = 150.0;                               // HR recovery time constant (s)
        $peak = $hrRest + ($target - $hrRest) * 1.06;

        $hr1 = [];          // 1 Hz HR
        $speed = [];        // 1 Hz GPS speed (km/h)
        $grade = [];        // 1 Hz baro grade (fraction)
        $distanceKm = 0.0;
        for ($s = 0; $s < $dur; $s++) {
            $ramp = min($s / max($warm, 1), 1.0);
            $g = $hills * 0.05 * sin(2 * M_PI * $s / max($dur / 2.5, 1)) + $this->gauss() * 0.004;
            if ($s > $tCool) {
                $hr = $hrRest + ($peak - $hrRest) * exp(-($s - $tCool) / $tau);
                $effort = $ramp * exp(-($s - $tCool) / 90.0);
            } else {
                $drift = 1 + 0.06 * min(max(($s - $warm) / max($tCool - $warm, 1), 0), 1);
                $hr = $hrRest + ($target - $hrRest) * $ramp * $drift;
                $effort = $ramp;
            }
            $hr += $this->gauss() * 1.2;
            $sp = max($spdTarget * $effort * (1 - 1.5 * max($g, 0)) + $this->gauss() * 0.3, 0);
            $hr1[] = round($hr, 1);
            $speed[] = round($sp, 2);
            $grade[] = round($g, 4);
            $distanceKm += $sp / 3600.0;            // km/h × 1 s
        }

        // Per-30s-epoch HR + accel counts (drive the counts-based session detector + TRIMP).
        $epochs = max(1, (int) floor($dur / 30));
        $hrEpoch = [];
        $accelCounts = [];
        for ($e = 0; $e < $epochs; $e++) {
            $hrEpoch[] = $hr1[min($e * 30, $dur - 1)];
            // active while moving; counts scale with the effort envelope at that epoch.
            $ramp = min(($e * 30) / max($warm, 1), 1.0);
            $env = ($e * 30 > $tCool) ? $ramp * exp(-(($e * 30) - $tCool) / 90.0) : $ramp;
            $accelCounts[] = max(0, (int) round($w['speed'] * 2.5 * $env + $this->gauss() * 3));
        }

        // HRR: HR drop in the 60 s after exercise ends (measured from the cooldown onset, the
        // stable reference -- a noisy global max would understate the drop).
        $onset = (int) round($tCool);
        $hrr60 = ($onset + 60 < count($hr1)) ? round($hr1[$onset] - $hr1[$onset + 60], 1) : null;

        return [
            'activity' => $activity,
            'profile' => ['age' => $age, 'resting_hr' => (int) round($hrRest), 'hr_max' => (int) round($hrMax)],
            'accel_counts' => $accelCounts,
            'hr_epoch_bpm' => $hrEpoch,
            'run' => ['hr' => $hr1, 'speed_kmh' => $speed, 'grade' => $grade, 'hrr60' => $hrr60],
            'distance_km' => round($distanceKm, 2),
        ];
    }

    // --- deterministic RNG helpers (seeded mt_rand) ---

    private function frand(): float
    {
        return mt_rand() / mt_getrandmax();
    }

    private function randInt(int $min, int $max): int
    {
        return $min + (int) floor($this->frand() * ($max - $min + 1));
    }

    /** Box-Muller standard normal. */
    private function gauss(): float
    {
        if ($this->haveZ2) {
            $this->haveZ2 = false;

            return $this->z2;
        }
        $u1 = max(1e-9, $this->frand());
        $u2 = $this->frand();
        $r = sqrt(-2 * log($u1));
        $this->z2 = $r * sin(2 * M_PI * $u2);
        $this->haveZ2 = true;

        return $r * cos(2 * M_PI * $u2);
    }
}
