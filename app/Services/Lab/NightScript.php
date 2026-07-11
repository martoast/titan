<?php

namespace App\Services\Lab;

use Carbon\CarbonImmutable;

/**
 * SLEEP LAB · NightScript — a night described like a screenwriter (spec §2.1).
 *
 * This is the GROUND TRUTH for one night: the true sleep architecture (ordered stage blocks) plus the
 * *device story* layered on top (duty-cycle cadence, charge gaps, BLE drops with buffered replay, marker
 * timing, clock drift, timezone). The script IS the expected outcome — the LAB's assertions are DERIVED
 * from it (see {@see expected()}), never hand-written numbers. A scenario is a NightScript; the VirtualBand
 * renders it into wire reality and the harness proves the pipeline told the truth about it.
 *
 * Phase-1 note: the vocabulary here is deliberately rich enough to express the Phase-2 "scars"
 * (charge gaps, store-and-forward replay, marker live/delayed/replayed/absent, clock drift, tz) so the
 * framework generalises — but only {@see perfectNight()} is wired green this phase.
 */
class NightScript
{
    /** Canonical LAB stage tokens → BiosignalSimulator STATES keys (the shared signal presets). */
    public const STAGE_STATE = [
        'deep' => 'deep',
        'light' => 'sleep',
        'rem' => 'rem',
        'wake' => 'rest',   // in-bed awake / restlessness (low HR, some motion), NOT the run/walk presets
    ];

    /** The three asleep stages (everything else in a night is awake-in-bed). */
    public const ASLEEP = ['deep', 'light', 'rem'];

    /** Marker-timing vocabulary (spec §2.1). Phase 1 exercises `live`. */
    public const MARKER_LIVE = 'live';         // T9 fires the instant the user wakes (sample ≈ ingest)

    public const MARKER_DELAYED = 'delayed';   // marker fires some minutes after wake

    public const MARKER_REPLAYED = 'replayed'; // marker re-sent later (store-and-forward) — a no-op

    public const MARKER_ABSENT = 'absent';     // no marker at all — the cron auto-seals instead

    /**
     * @param  string  $scenario  the scenario name (also the promise-bearing label on the scorecard)
     * @param  array<int,int>  $promises  the FIVE PROMISES this scenario defends (1..5)
     * @param  string  $tz  IANA timezone the night is lived in
     * @param  CarbonImmutable  $bedAt  true lights-out (UTC instant)
     * @param  array<int,array{stage:string,minutes:int}>  $blocks  ordered true sleep architecture
     * @param  array{burst_sec:int,period_sec:int}  $dutyCycle  band sampling cadence
     * @param  array<int,array{start_min:int,dur_min:int}>  $chargeGaps  mid-night gaps (band off charging)
     * @param  array<int,array{start_min:int,dur_min:int,replay_skew_sec:int}>  $bleDrops  buffered-replay drops
     * @param  string  $markerTiming  one of MARKER_*
     * @param  int  $markerDelaySec  how late a `delayed`/`replayed` marker fires
     * @param  int  $clockDriftSec  band RTC drift applied to sample timestamps
     * @param  string  $wireKind  'ibi' (IBI+accel) or 'ppg_raw' (raw PPG, accel omitted)
     */
    public function __construct(
        public string $scenario,
        public array $promises,
        public string $tz,
        public CarbonImmutable $bedAt,
        public array $blocks,
        public array $dutyCycle = ['burst_sec' => 30, 'period_sec' => 180],
        public array $chargeGaps = [],
        public array $bleDrops = [],
        public string $markerTiming = self::MARKER_LIVE,
        public int $markerDelaySec = 0,
        public int $clockDriftSec = 0,
        public string $wireKind = 'ibi',
    ) {}

    /** Total scripted time-in-bed (minutes) = the sum of every block. */
    public function totalMinutes(): int
    {
        return array_sum(array_map(fn ($b) => (int) $b['minutes'], $this->blocks));
    }

    /** True lights-out → true wake (UTC). */
    public function wakeAt(): CarbonImmutable
    {
        return $this->bedAt->addMinutes($this->totalMinutes());
    }

    /** The wake-date in the owner's local calendar — the key SealNightJob files a full night under. */
    public function nightDate(): string
    {
        return $this->wakeAt()->setTimezone($this->tz)->toDateString();
    }

    /**
     * The EXPECTED outcome, derived purely from the script. Everything the scorecard checks compares the
     * real pipeline's row against this — no magic numbers live in the harness.
     *
     * @return array{
     *   deep_min:int,rem_min:int,light_min:int,awake_min:int,asleep_min:int,tib_min:int,
     *   deep_pct:float,rem_pct:float,light_pct:float,efficiency_pct:float,
     *   duration_min:int,is_nap:bool,date:string
     * }
     */
    public function expected(): array
    {
        $mins = ['deep' => 0, 'rem' => 0, 'light' => 0, 'wake' => 0];
        foreach ($this->blocks as $b) {
            $mins[$b['stage']] = ($mins[$b['stage']] ?? 0) + (int) $b['minutes'];
        }
        $deep = $mins['deep'];
        $rem = $mins['rem'];
        $light = $mins['light'];
        $awake = $mins['wake'];
        $asleep = $deep + $rem + $light;
        $tib = $asleep + $awake;

        // Stage percentages are expressed as a fraction of ASLEEP time (the Whoop convention the app's
        // SleepDetail uses), so the scorecard's ±8pt gate compares like with like.
        $pct = fn (int $m) => $asleep > 0 ? round(100.0 * $m / $asleep, 1) : 0.0;

        return [
            'deep_min' => $deep,
            'rem_min' => $rem,
            'light_min' => $light,
            'awake_min' => $awake,
            'asleep_min' => $asleep,
            'tib_min' => $tib,
            'deep_pct' => $pct($deep),
            'rem_pct' => $pct($rem),
            'light_pct' => $pct($light),
            'efficiency_pct' => $tib > 0 ? round(100.0 * $asleep / $tib, 1) : 0.0,
            // The confirmed seal reports duration = observed-span − detected wake ≈ asleep time.
            'duration_min' => $asleep,
            'is_nap' => $tib < \App\Jobs\SealNightJob::NAP_MAX_MIN,
            'date' => $this->nightDate(),
        ];
    }

    /**
     * Epoch-index spans (30s grid, index 0 = bedAt — the SAME grid the persisted hr_series / motion_series
     * and hypnogram align to) for the SLEEP TIMELINE v2 movement/peaks LAYER assertions (spec §5). Derived
     * purely from the script: which epochs are true DEEP (the calm valleys — motion must be low there), which
     * are NON-DEEP (light/rem/wake — the restless teeth live here), the scripted restless WAKE bouts, and the
     * CHARGE-GAP holes (where the persisted series must have NO points).
     *
     * @return array{deep:list<array{0:int,1:int}>,nondeep:list<array{0:int,1:int}>,restless:list<array{0:int,1:int}>,gap:list<array{0:int,1:int}>}
     */
    public function layerSpans(): array
    {
        $epoch = 0;   // running epoch index from bedAt
        $deep = $nondeep = $restless = [];
        foreach ($this->blocks as $b) {
            $len = (int) round((int) $b['minutes'] * 60 / 30);
            $span = [$epoch, $epoch + $len];
            if ($b['stage'] === 'deep') {
                $deep[] = $span;
            } else {
                $nondeep[] = $span;
            }
            if ($b['stage'] === 'wake') {
                $restless[] = $span;
            }
            $epoch += $len;
        }
        $gap = [];
        foreach ($this->chargeGaps as $g) {
            $gap[] = [
                (int) round((int) $g['start_min'] * 60 / 30),
                (int) round(((int) $g['start_min'] + (int) $g['dur_min']) * 60 / 30),
            ];
        }

        return compact('deep', 'nondeep', 'restless', 'gap');
    }

    /**
     * The clean golden-path night (spec §4 · defends P2 + P5). A well-covered duty-cycle night with a live
     * wake marker: stages ≈ script, coverage ≈ 1.0, one row, one summary. Architecture proportions come from
     * the calibration (real sealed-night statistics) so the stager reproduces them within tolerance.
     */
    public static function perfectNight(string $tz, SleepCalibration $cal, ?CarbonImmutable $wakeAt = null): self
    {
        // A "just woke up" live band: wake ≈ now, so sample-time ≈ ingest-time (no store-and-forward skew).
        $wake = ($wakeAt ?? CarbonImmutable::now('UTC'))->setTimezone('UTC');
        $arch = $cal->architecture();
        $blocks = self::composeArchitecture($arch, 462); // ~7h42m in bed
        $tib = array_sum(array_map(fn ($b) => $b['minutes'], $blocks));
        $bed = $wake->subMinutes($tib);

        return new self(
            scenario: 'perfect-night',
            promises: [2, 5],
            tz: $tz,
            bedAt: $bed,
            blocks: $blocks,
            dutyCycle: $cal->dutyCycle(),
            markerTiming: self::MARKER_LIVE,
            wireKind: 'ibi',
        );
    }

    /**
     * Compose an ordered, textbook-realistic block list (deep-heavy early cycles → REM-heavy late) that hits
     * the target proportions. Deterministic: same architecture in, same blocks out.
     *
     * @param  array{deep_pct:float,rem_pct:float,light_pct:float,efficiency_pct:float}  $arch
     * @return array<int,array{stage:string,minutes:int}>
     */
    private static function composeArchitecture(array $arch, int $tibMin): array
    {
        $awake = (int) round($tibMin * (1 - $arch['efficiency_pct'] / 100));
        $asleep = $tibMin - $awake;
        $deepTot = (int) round($asleep * $arch['deep_pct'] / 100);
        $remTot = (int) round($asleep * $arch['rem_pct'] / 100);
        $lightTot = max(0, $asleep - $deepTot - $remTot);

        $cycles = 5;
        // Deep weighting fades across the night; REM grows. Weights per cycle.
        $deepW = [0.34, 0.28, 0.20, 0.12, 0.06];
        $remW = [0.08, 0.15, 0.22, 0.27, 0.28];
        $lightW = [0.20, 0.20, 0.20, 0.20, 0.20];

        $blocks = [];
        // Sleep onset: a short settling wake block, then the cycles.
        $onset = min($awake, 8);
        if ($onset > 0) {
            $blocks[] = ['stage' => 'wake', 'minutes' => $onset];
        }
        $awakeLeft = $awake - $onset;

        for ($c = 0; $c < $cycles; $c++) {
            $light = (int) round($lightTot * $lightW[$c]);
            $deep = (int) round($deepTot * $deepW[$c]);
            $rem = (int) round($remTot * $remW[$c]);
            // Cycle shape: light → deep → light → REM (with a brief mid-night wake between later cycles).
            if ($light > 0) {
                $blocks[] = ['stage' => 'light', 'minutes' => max(1, (int) round($light * 0.6))];
            }
            if ($deep > 0) {
                $blocks[] = ['stage' => 'deep', 'minutes' => $deep];
            }
            if ($light > 0) {
                $blocks[] = ['stage' => 'light', 'minutes' => max(1, $light - (int) round($light * 0.6))];
            }
            if ($c >= 2 && $awakeLeft > 0) {
                $w = min($awakeLeft, 3);
                $blocks[] = ['stage' => 'wake', 'minutes' => $w];
                $awakeLeft -= $w;
            }
            if ($rem > 0) {
                $blocks[] = ['stage' => 'rem', 'minutes' => $rem];
            }
        }
        if ($awakeLeft > 0) {
            $blocks[] = ['stage' => 'light', 'minutes' => $awakeLeft]; // fold any remainder back to light
        }

        return array_values(array_filter($blocks, fn ($b) => $b['minutes'] > 0));
    }
}
