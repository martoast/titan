<?php

namespace App\Services\Lab;

use Carbon\CarbonImmutable;

/**
 * WORKOUT LAB · WorkoutScript — a workout described like a screenwriter (spec §2, sibling of {@see NightScript}).
 *
 * The GROUND TRUTH for one workout: the true effort architecture (ordered effort blocks, each with a
 * target HR + cadence), the GPS story (a real route, or indoor), any set-logging events, and the *device
 * story* layered on top (live stream vs offline buffer, BLE drop mid-run, strap dropout, cadence-lock
 * artifact, watch-kind hint present-or-absent, explicit End tap vs auto-detect, clock drift). The script
 * IS the expected outcome — {@see expected()} DERIVES every assertion from it; no magic numbers live in
 * the harness or the scorecard.
 *
 * Phase-1 note: the vocabulary here is deliberately rich enough to express the Phase-2 scars
 * (effort blocks, GPS track, timestamped set-log events, offline-buffer / BLE-drop / strap-dropout /
 * cadence-lock injection, hint present-or-absent, explicit-End vs auto-detect, clock drift) so the
 * framework generalises — but only {@see steadyRun()} + {@see gymLift()} are wired green this phase.
 */
class WorkoutScript
{
    // -- marker / end vocabulary --
    public const END_EXPLICIT = 'explicit_end';   // watch/user tapped End → confirmed workout_session marker (force seal)
    public const END_AUTO = 'auto';               // no End tap — the quiescence rule / cron auto-seals

    // -- stream vocabulary --
    public const STREAM_LIVE = 'live';            // windows arrive in real time (sample ≈ ingest)
    public const STREAM_OFFLINE = 'offline';      // out of BLE range — buffered on-watch, replayed later

    /**
     * @param  string  $scenario  scenario name (also the promise-bearing label on the scorecard)
     * @param  array<int,int>  $promises  the FIVE PROMISES this scenario defends (1..5)
     * @param  string  $tz  IANA timezone the workout is done in
     * @param  CarbonImmutable  $startAt  true workout start (UTC instant)
     * @param  string|null  $kind  the watch's ACTIVITY kind ('run'|'strength'|…); null = auto-detect
     * @param  bool  $hintPresent  whether the watch's kind hint is sent (on the windows + the End marker)
     * @param  array<int,array{label:string,minutes:float,hr:float,cadence_spm?:float}>  $blocks  effort architecture
     * @param  array{lat:float,lon:float,shape:string,pace_s_per_km:float,elevation_amp_m:float}|null  $gps  route (null = indoor)
     * @param  array<int,array{at_min:float,name:string,muscle_group?:string,sets:array<int,array{reps:int,weight_kg:float}>}>  $setEvents  logged sets
     * @param  array{window_sec:int}  $dutyCycle  band streaming cadence (how the run is chunked on the wire)
     * @param  string  $endSignal  END_EXPLICIT | END_AUTO
     * @param  string  $stream  STREAM_LIVE | STREAM_OFFLINE
     * @param  array<int,array{start_min:float,dur_min:float,replay_skew_sec?:int}>  $bleDrops  buffered-replay drops (Phase 2)
     * @param  array{start_min:float,dur_min:float}|null  $strapDropout  HR→0 stretch (Phase-2 strap-dropout scar)
     * @param  array{start_min:float,dur_min:float,bpm:float}|null  $cadenceLock  on-chip HR artifact (Phase-2 cadence-lock scar)
     * @param  int  $clockDriftSec  band RTC drift applied to every sample timestamp
     * @param  bool  $emitPpgRaw  also stream concurrent kind=ppg_raw (server recomputes in-motion HR)
     * @param  int  $ppgHz  PPG sample rate when $emitPpgRaw
     */
    public function __construct(
        public string $scenario,
        public array $promises,
        public string $tz,
        public CarbonImmutable $startAt,
        public ?string $kind,
        public bool $hintPresent,
        public array $blocks,
        public ?array $gps = null,
        public array $setEvents = [],
        public array $dutyCycle = ['window_sec' => 180],
        public string $endSignal = self::END_EXPLICIT,
        public string $stream = self::STREAM_LIVE,
        public array $bleDrops = [],
        public ?array $strapDropout = null,
        public ?array $cadenceLock = null,
        public int $clockDriftSec = 0,
        public bool $emitPpgRaw = false,
        public int $ppgHz = 25,
        private ?WorkoutCalibration $calibration = null,
    ) {}

    /** Total scripted duration (seconds) = the sum of every block. */
    public function totalSeconds(): int
    {
        return (int) round(array_sum(array_map(fn ($b) => (float) $b['minutes'] * 60.0, $this->blocks)));
    }

    public function totalMinutes(): int
    {
        return (int) round($this->totalSeconds() / 60);
    }

    public function endAt(): CarbonImmutable
    {
        return $this->startAt->addSeconds($this->totalSeconds());
    }

    public function isRun(): bool
    {
        return $this->gps !== null;
    }

    /** The activity_type the seal should land on (a lift family → 'strength'; a cardio kind stays itself). */
    public function expectedActivityType(): string
    {
        if (in_array($this->kind, ['lift', 'strength', 'hiit', 'yoga'], true)) {
            return 'strength';
        }
        if (in_array($this->kind, ['run', 'walk', 'hike', 'cycle'], true)) {
            return $this->kind;
        }

        return $this->isRun() ? 'run' : 'strength';
    }

    /** The calibration family key ('run' for cardio, 'strength' otherwise). */
    public function family(): string
    {
        return $this->expectedActivityType() === 'strength' ? 'strength' : 'run';
    }

    /** The local calendar day the session's started_at falls on — the streak/reader bucket. */
    public function localDate(): string
    {
        return $this->startAt->setTimezone($this->tz)->toDateString();
    }

    /**
     * The EXPECTED outcome, derived purely from the script. The scorecard compares the real sealed row
     * against this within the §5 tolerances — no hand-written numbers.
     *
     * @return array<string,mixed>
     */
    public function expected(): array
    {
        $totalSec = $this->totalSeconds();
        $durationMin = (int) round($totalSec / 60);

        // Duration-weighted mean HR + peak, straight from the blocks (the seal derives avg over positive
        // samples + a 98th-pct robust max, which reproduce these within the ±5 bpm tolerance).
        $hrSum = 0.0;
        $peak = 0.0;
        foreach ($this->blocks as $b) {
            $hrSum += (float) $b['hr'] * (float) $b['minutes'] * 60.0;
            $peak = max($peak, (float) $b['hr']);
        }
        $avgHr = $totalSec > 0 ? (int) round($hrSum / $totalSec) : 0;
        $maxHr = (int) round($peak);

        $exp = [
            'scenario' => $this->scenario,
            'session_count' => 1,
            'activity_type' => $this->expectedActivityType(),
            'is_run' => $this->isRun(),
            'has_route' => $this->isRun(),
            'duration_min' => $durationMin,
            'avg_hr' => $avgHr,
            'max_hr' => $maxHr,
            'date' => $this->localDate(),
            'set_count' => array_sum(array_map(fn ($e) => count($e['sets']), $this->setEvents)),
        ];

        // Route metrics for a run: constant pace → distance = elapsed / pace; splits ≈ pace.
        if ($this->isRun()) {
            $pace = (float) $this->gps['pace_s_per_km'];
            $exp['avg_pace_s_per_km'] = (int) round($pace);
            $exp['distance_km'] = round($totalSec / $pace, 2);
        }

        // Metric envelopes from the calibration (membership checks, not ±tolerance-to-a-number).
        $cal = $this->calibration ?? WorkoutCalibration::load();
        [$tLo, $tHi] = $cal->trimpPerMin($this->family());
        $exp['trimp_min'] = round($tLo * $durationMin, 1);
        $exp['trimp_max'] = round($tHi * $durationMin, 1);
        if ($this->isRun()) {
            [$rLo, $rHi] = $cal->rePerMin($this->family());
            $exp['re_min'] = round($rLo * $durationMin, 1);
            $exp['re_max'] = round($rHi * $durationMin, 1);
        }

        return $exp;
    }

    // ---------------------------------------------------------------- golden-path factories

    /**
     * `steady-run` (spec §4 · defends P1 P2 P3 P5). A clean ~28-min tempo run outdoors with a live
     * stream and an explicit End tap: warmup → steady tempo → cooldown, constant pace, rolling
     * elevation. Asserts a route with splits ±2s/km of the script, GAP, elevation, RE in envelope, ONE
     * session, type 'run'. HR targets come from the calibration so generator + classifier agree.
     */
    public static function steadyRun(string $tz, WorkoutCalibration $cal, ?CarbonImmutable $startAt = null): self
    {
        $start = ($startAt ?? CarbonImmutable::now('UTC'))->setTimezone('UTC');
        // Anchor so the workout just ENDED (end ≈ now) — the confirmed End marker seals it at once, and
        // the live windows are never falsely "quiescent" for the auto-seal to grab first.
        $totalMin = 28;
        $start = $start->subMinutes($totalMin);

        $blocks = [
            ['label' => 'warmup', 'minutes' => 5, 'hr' => $cal->hr('run', 'warmup', 132), 'cadence_spm' => 158],
            ['label' => 'steady', 'minutes' => 18, 'hr' => $cal->hr('run', 'steady', 156), 'cadence_spm' => 174],
            ['label' => 'cooldown', 'minutes' => 5, 'hr' => $cal->hr('run', 'cooldown', 120), 'cadence_spm' => 150],
        ];

        return new self(
            scenario: 'steady-run',
            promises: [1, 2, 3, 5],
            tz: $tz,
            startAt: $start,
            kind: 'run',
            hintPresent: true,
            blocks: $blocks,
            gps: ['lat' => 37.7694, 'lon' => -122.4862, 'shape' => 'out-and-back', 'pace_s_per_km' => 330.0, 'elevation_amp_m' => 12.0],
            endSignal: self::END_EXPLICIT,
            stream: self::STREAM_LIVE,
            calibration: $cal,
        );
    }

    /**
     * `gym-lift` (spec §4 · defends P1 P2 P4 P5). A ~40-min strength session indoors (no GPS), streamed
     * live with an explicit End tap and the 'strength' kind hint: burst-rest effort, HR climbing under
     * load and recovering between sets. Asserts the kind hint is honoured (type 'strength', NO route),
     * HR zones + a positive TRIMP, and that logged sets attach to THIS session (P4).
     */
    public static function gymLift(string $tz, WorkoutCalibration $cal, ?CarbonImmutable $startAt = null): self
    {
        $start = ($startAt ?? CarbonImmutable::now('UTC'))->setTimezone('UTC');
        $totalMin = 40;
        $start = $start->subMinutes($totalMin);

        $work = $cal->hr('strength', 'work', 138);
        $peak = $cal->hr('strength', 'peak', 150);
        $recover = $cal->hr('strength', 'recover', 96);
        $warm = $cal->hr('strength', 'recover', 96) + 12;

        // Six work/recover couplets bracketed by a warmup + a cooldown → the burst-rest signature.
        $blocks = [['label' => 'warmup', 'minutes' => 4, 'hr' => $warm, 'cadence_spm' => 0]];
        for ($i = 0; $i < 6; $i++) {
            $blocks[] = ['label' => 'work', 'minutes' => 2.5, 'hr' => $i >= 3 ? $peak : $work, 'cadence_spm' => 0];
            $blocks[] = ['label' => 'recover', 'minutes' => 2.5, 'hr' => $recover, 'cadence_spm' => 0];
        }
        $blocks[] = ['label' => 'cooldown', 'minutes' => 6, 'hr' => $recover - 6, 'cadence_spm' => 0];

        // Two exercises logged mid-session — they must attach to THIS session (P4), never null/doubled.
        $setEvents = [
            ['at_min' => 8, 'name' => 'Back Squat', 'muscle_group' => 'legs', 'sets' => [
                ['reps' => 8, 'weight_kg' => 100.0], ['reps' => 8, 'weight_kg' => 100.0], ['reps' => 6, 'weight_kg' => 110.0],
            ]],
            ['at_min' => 22, 'name' => 'Bench Press', 'muscle_group' => 'chest', 'sets' => [
                ['reps' => 10, 'weight_kg' => 70.0], ['reps' => 8, 'weight_kg' => 75.0],
            ]],
        ];

        return new self(
            scenario: 'gym-lift',
            promises: [1, 2, 4, 5],
            tz: $tz,
            startAt: $start,
            kind: 'strength',
            hintPresent: true,
            blocks: $blocks,
            gps: null,
            setEvents: $setEvents,
            endSignal: self::END_EXPLICIT,
            stream: self::STREAM_LIVE,
            calibration: $cal,
        );
    }
}
