<?php

namespace App\Services\Lab;

use App\Models\WearableConnection;
use App\Services\Simulator\BiosignalSimulator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * WORKOUT LAB · VirtualAthlete — the athlete's method actor (spec §2, sibling of {@see VirtualBand}).
 *
 * This is the ONE workout-wire renderer. It WRAPS {@see BiosignalSimulator} (the shared signal engine)
 * the same way VirtualBand does for sleep, and owns the two things the simulator does not: rendering an
 * effort block into the exact `kind=workout` wire JSON the band+bridge emit after frame decoding
 * (25 Hz 3-axis accel with cadence harmonics, 1 Hz HR, GPS speed/grade/track, per-30s accel counts —
 * the canonical shape {@see \App\Jobs\SealActivityJob::loadWindow} reads), and the HMAC-signed POST to
 * the REAL /api/devices/ingest. It renders a whole {@see WorkoutScript} into the sparse windows a real
 * workout streams as and fires the watch's confirmed End marker (Shape-C `workout_session`).
 *
 * {@see \App\Console\Commands\SimulateRun} and {@see \App\Console\Commands\SimulateWorkout} used to each
 * inline the workout-window builder + the HMAC signer; both now delegate to the static seams here
 * ({@see simulateRunWindow()}, {@see countsWindow()}, {@see signAndPost()}), so there is ONE wire contract
 * for workouts, not three. If the firmware and the VirtualAthlete ever disagree about the wire format,
 * that is a P0 in one of them.
 */
class VirtualAthlete
{
    public function __construct(
        private BiosignalSimulator $sim,
        private WearableConnection $device,
        private string $secret,
        private ?WorkoutCalibration $calibration = null,
        private ?string $ingestUrl = null,
    ) {
        $this->ingestUrl ??= rtrim((string) config('app.url', 'http://localhost'), '/').'/api/devices/ingest';
    }

    public function simulator(): BiosignalSimulator
    {
        return $this->sim;
    }

    public function device(): WearableConnection
    {
        return $this->device;
    }

    public function profileId(): int
    {
        return (int) $this->device->profile_id;
    }

    // ================================================================ canonical wire contract (static)

    /**
     * Format ONE `kind=workout` window — the canonical post-frame-decode shape the seal reads
     * (accel_xyz + accel_fs/unit + accel_counts + hr_bpm + gps{speed_kmh,grade,track}). This is the
     * single wire renderer the LAB, SimulateRun and SimulateWorkout all go through.
     *
     * @param  array{x:array<int,int>,y:array<int,int>,z:array<int,int>}|null  $accelXyz
     * @param  array<int,int>  $counts
     * @param  array<int,float>  $hr
     * @param  array{speed_kmh?:array<int,float>,grade?:array<int,float>,track?:array<int,array<string,mixed>>}|null  $gps
     * @return array<string,mixed>
     */
    public static function workoutWindow(
        string $startIso,
        string $endIso,
        ?array $accelXyz,
        int $fs,
        string $unit,
        array $counts,
        array $hr,
        ?array $gps,
        string $src = 'simulator',
        ?string $activityKind = null,
        bool $ended = false,
    ): array {
        return array_filter([
            'kind' => 'workout',
            'start' => $startIso,
            'end' => $endIso,
            'accel_xyz' => $accelXyz,
            'accel_fs' => $accelXyz ? $fs : null,
            'accel_unit' => $accelXyz ? $unit : null,
            'accel_counts' => $counts ?: null,
            'hr_bpm' => $hr ?: null,
            'gps' => $gps ?: null,
            'activity_kind' => $activityKind,
            'ended' => $ended ?: null,
            'src' => $src,
        ], fn ($v) => $v !== null);
    }

    /**
     * The full synthetic-run window SimulateRun used to build inline — moved here verbatim so there is
     * one copy. 25 Hz running accel with foot-strike harmonics + arm swing (the signature the classifier
     * learned), 1 Hz HR ramp, a GPS loop / out-and-back track with rolling elevation + grade.
     *
     * @return array<string,mixed>
     */
    public static function simulateRunWindow(float $lat0, float $lon0, float $km, int $durSec, int $startMs, string $startIso, string $endIso, bool $outBack): array
    {
        $Dm = $km * 1000;
        $r = $Dm / (2 * M_PI);
        $mLat = 111320.0;
        $mLon = 111320.0 * cos(deg2rad($lat0));

        $track = $speed = $grade = $hr = [];
        $prevAlt = null;
        for ($i = 0; $i < $durSec; $i++) {
            $f = $i / $durSec;                                   // 0..1 over the run
            if ($outBack) {                                     // straight out then back, with a slight bow
                $prog = $f < 0.5 ? $f * 2 : (1 - $f) * 2;        // 0→1→0
                $x = $prog * $Dm / 2;
                $y = 90 * sin($f * M_PI * 6);
            } else {                                            // a loop back to the start
                $th = 2 * M_PI * $f;
                $x = $r * sin($th) + 22 * sin($th * 6);
                $y = $r * (cos($th) - 1) + 22 * cos($th * 5);
            }
            $lat = $lat0 + $y / $mLat;
            $lon = $lon0 + $x / $mLon;
            $alt = 40 + 18 * sin($f * M_PI * 4);                // a couple of rolling hills
            $track[] = ['t' => $startMs + $i * 1000, 'lat' => round($lat, 6), 'lon' => round($lon, 6), 'alt' => round($alt, 1)];

            $speed[] = round(($Dm / $durSec) * 3.6 * (0.9 + 0.2 * sin($f * M_PI * 8)), 2);   // km/h, gently varying
            $dDist = $Dm / $durSec;
            $g = ($prevAlt !== null && $dDist > 0.5) ? max(-0.3, min(0.3, ($alt - $prevAlt) / $dDist)) : 0.0;
            $grade[] = round($g, 4);
            $prevAlt = $alt;
            $hr[] = (int) round(120 + 40 * $f + 6 * sin($f * M_PI * 20));                    // ramps 120→160 + drift
        }

        $fs = 25;
        $ax = $ay = $az = [];
        for ($i = 0, $n = $durSec * $fs; $i < $n; $i++) {
            $t = $i / $fs;
            $ph = 2 * M_PI * 2.9 * $t;                                   // ~174 spm — a clear run cadence
            $impact = 520 * sin($ph) + 120 * sin(2 * $ph) + 45 * sin(3 * $ph);
            $az[] = (int) round(1000 + $impact + mt_rand(-70, 70));
            $ax[] = (int) round(160 * sin($ph + 0.4) + mt_rand(-60, 60));
            $ay[] = (int) round(120 * sin(2 * M_PI * 1.45 * $t + 1.0) + mt_rand(-60, 60));   // arm swing at half-cadence
        }
        $counts = array_fill(0, max(1, (int) ceil($durSec / 30)), 70);   // per-30s activity (vigorous)

        return self::workoutWindow($startIso, $endIso, ['x' => $ax, 'y' => $ay, 'z' => $az], $fs, 'mg', $counts, $hr, [
            'speed_kmh' => $speed, 'grade' => $grade, 'track' => $track,
        ], 'simulator');
    }

    /**
     * The counts-based workout window SimulateWorkout's --ingest path used to inline (HR + accel counts +
     * GPS speed/grade, no 3-axis accel — the PHP fitness twin owns HR/pace, not the accel signature).
     *
     * @param  array<int,float>  $hr
     * @param  array<int,int>  $counts
     * @param  array<int,float>  $speedKmh
     * @param  array<int,float>  $grade
     * @return array<string,mixed>
     */
    public static function countsWindow(string $startIso, string $endIso, array $hr, array $counts, array $speedKmh, array $grade): array
    {
        return self::workoutWindow($startIso, $endIso, null, 0, 'mg', $counts, $hr, [
            'speed_kmh' => $speedKmh, 'grade' => $grade,
        ], 'simulator');
    }

    /**
     * HMAC-sign a batch and POST it — byte-identical to the firmware bridge: key = sha256(secret), signed
     * material = "<ts>.<rawBody>", header X-Titan-Signature: t=…,v1=…. Returns the HTTP status (0 on a
     * transport error) so callers can FAIL LOUDLY the instant a batch doesn't land (a LAB that can't reach
     * the API must abort in seconds, never spin on data that never arrived). The single workout signer.
     *
     * @param  array<string,mixed>  $payload
     */
    public static function postStatus(string $url, string $deviceId, string $secret, string $timezone, array $payload): int
    {
        $payload = array_merge([
            'batch_uid' => (string) Str::ulid(),
            'device_id' => $deviceId,
            'timezone' => $timezone,
        ], $payload);

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $ts = (string) time();
        $sig = 't='.$ts.',v1='.hash_hmac('sha256', $ts.'.'.$body, hash('sha256', $secret));

        try {
            return Http::withHeaders([
                'X-Device-Id' => $deviceId,
                'X-Titan-Signature' => $sig,
                'Content-Type' => 'application/json',
            ])->timeout(45)->withBody($body, 'application/json')->post($url)->status();
        } catch (\Throwable) {
            return 0;
        }
    }

    /** Back-compat boolean wrapper for the simulate commands (2xx = delivered). */
    public static function signAndPost(string $url, string $deviceId, string $secret, string $timezone, array $payload): bool
    {
        $s = self::postStatus($url, $deviceId, $secret, $timezone, $payload);

        return $s >= 200 && $s < 300;
    }

    /** POST one signed batch through this athlete's device → HTTP status (0 = transport error). */
    public function post(array $payload): int
    {
        return self::postStatus($this->ingestUrl, $this->device->device_id, $this->secret, $this->device->effectiveTimezone(), $payload);
    }

    /** @param array<string,mixed> $payload */
    public function postSigned(array $payload): bool
    {
        $s = $this->post($payload);

        return $s >= 200 && $s < 300;
    }

    public function ingestUrl(): string
    {
        return (string) $this->ingestUrl;
    }

    // ================================================================ render a whole WorkoutScript

    /**
     * Stream a whole WorkoutScript → the real ingest API in signed batches, then fire the confirmed End
     * marker. Returns delivery stats + the pure render (for assertions).
     *
     * @return array{windows:int,batches:int,delivered:int,ppg_windows:int,marker:bool,render:array<string,mixed>}
     */
    public function streamWorkout(WorkoutScript $script, int $batchWindows = 4): array
    {
        $render = $this->renderWorkout($script);
        $live = $render['live'];

        $batches = 0;
        foreach (array_merge(array_chunk($live, $batchWindows), array_chunk($render['ppg'], $batchWindows)) as $chunk) {
            $batches++;
            // FAIL LOUDLY: a batch that doesn't land means the seal will never fire — abort the run in
            // seconds with the HTTP status instead of spinning for minutes in "waiting for seal".
            $status = $this->post(['windows' => array_values($chunk)]);
            if ($status < 200 || $status >= 300) {
                throw new \RuntimeException("ingest POST failed (HTTP {$status}) at {$this->ingestUrl} — is the app reachable? try --ingest-url=");
            }
        }

        $markerStatus = $this->emitEndMarkerStatus($script);
        if ($markerStatus !== null && ($markerStatus < 200 || $markerStatus >= 300)) {
            throw new \RuntimeException("End-marker POST failed (HTTP {$markerStatus}) at {$this->ingestUrl}");
        }

        return [
            'windows' => count($live),
            'batches' => $batches,
            'delivered' => $batches,
            'ppg_windows' => count($render['ppg']),
            'marker' => $markerStatus !== null,
            'render' => $render,
        ];
    }

    /**
     * PURE render of a WorkoutScript into its wire windows (no HTTP) — the deterministic core of
     * {@see streamWorkout()}, split out so it can be exercised in a unit test. Generates the whole 1 Hz
     * HR / GPS series + 25 Hz accel, then slices them into the sparse duty-cycle windows a real workout
     * arrives as.
     *
     * @return array{live:array<int,array<string,mixed>>,ppg:array<int,array<string,mixed>>,seconds:int}
     */
    public function renderWorkout(WorkoutScript $script): array
    {
        $total = $script->totalSeconds();
        $drift = $script->clockDriftSec;
        $isRun = $script->isRun();

        // ---- per-second physiology from the effort blocks ----
        [$hr, $blockOf] = $this->renderHrSeries($script, $total);
        [$speed, $grade, $track] = $isRun ? $this->renderGps($script, $total, $drift) : [[], [], []];
        $accel = $this->renderAccel($script, $blockOf, $total);
        $counts = $this->renderCounts($script, $blockOf, $total);

        // ---- slice into duty-cycle windows ----
        $winSec = max(30, (int) $script->dutyCycle['window_sec']);
        $fs = 25;
        $unit = 'mg';
        $live = [];
        $ppg = [];
        for ($w0 = 0; $w0 < $total; $w0 += $winSec) {
            $w1 = min($total, $w0 + $winSec);
            $secs = $w1 - $w0;
            if ($secs < 1) {
                break;
            }
            $startTs = $script->startAt->addSeconds($w0 + $drift);
            $endTs = $script->startAt->addSeconds($w1 + $drift);
            $startIso = $startTs->toIso8601ZuluString();
            $endIso = $endTs->toIso8601ZuluString();

            $hrSlice = array_slice($hr, $w0, $secs);
            $countsSlice = $this->countsForSpan($counts, $w0, $w1, $winSec);
            $gps = null;
            if ($isRun) {
                $gps = array_filter([
                    'speed_kmh' => array_slice($speed, $w0, $secs),
                    'grade' => array_slice($grade, $w0, $secs),
                    'track' => array_slice($track, $w0, $secs),
                ], fn ($v) => $v !== []);
            }
            $accelSlice = ['x' => array_slice($accel['x'], $w0 * $fs, $secs * $fs),
                'y' => array_slice($accel['y'], $w0 * $fs, $secs * $fs),
                'z' => array_slice($accel['z'], $w0 * $fs, $secs * $fs)];

            $live[] = self::workoutWindow(
                $startIso, $endIso, $accelSlice, $fs, $unit, $countsSlice, $hrSlice, $gps, 'simulator',
                $script->hintPresent ? $script->kind : null,
            );

            // Concurrent raw PPG (server recomputes in-motion HR) — Phase-2 cadence-lock scar uses this.
            if ($script->emitPpgRaw) {
                $ibi = $this->ibiFromHr($hrSlice);
                $ppg[] = [
                    'kind' => 'ppg_raw',
                    'start' => $startIso,
                    'end' => $endIso,
                    'sample_rate_hz' => $script->ppgHz,
                    'ppg' => BiosignalSimulator::ppgFromIbi($ibi, $script->ppgHz),
                    'src' => 'banglejs2',
                ];
            }
        }

        return ['live' => $live, 'ppg' => $ppg, 'seconds' => $total];
    }

    /**
     * Fire the watch's confirmed workout End marker (Shape-C `workout_session`) → the real
     * DeviceIngestionService::triggerWorkoutSummary → a force-scoped SealActivityJob. `explicit_end`
     * sends it; `auto` sends nothing (the quiescence / cron rule seals instead). The activity kind rides
     * only when the watch's hint is present.
     */
    public function emitEndMarker(WorkoutScript $script): bool
    {
        $s = $this->emitEndMarkerStatus($script);

        return $s !== null && $s >= 200 && $s < 300;
    }

    /** Fire the End marker → the HTTP status, or null when the script sends no marker (`auto` end). */
    public function emitEndMarkerStatus(WorkoutScript $script): ?int
    {
        if ($script->endSignal !== WorkoutScript::END_EXPLICIT) {
            return null;
        }
        $start = $script->startAt->timestamp + $script->clockDriftSec;
        $end = $script->endAt()->timestamp + $script->clockDriftSec;

        return $this->post(['summaries' => [array_filter([
            'kind' => 'workout_session',
            'confirmed' => true,
            'start' => $start,
            'end' => $end,
            'activity_kind' => $script->hintPresent ? $script->kind : null,
            'manual' => false,
        ], fn ($v) => $v !== null)]]);
    }

    // ---------------------------------------------------------------- signal generators

    /**
     * A 1 Hz HR series painted from the effort blocks, with a short cross-block ramp + gentle cardiac
     * drift inside steady blocks + seeded noise, so the seal's positive-sample mean / 98th-pct max
     * reproduce the script's block-weighted mean / peak. Returns [hrSeries, blockIndexPerSecond].
     *
     * @return array{0:array<int,float>,1:array<int,int>}
     */
    private function renderHrSeries(WorkoutScript $script, int $total): array
    {
        // Expand blocks → per-second target + block index.
        $target = [];
        $blockOf = [];
        $s = 0;
        foreach ($script->blocks as $bi => $b) {
            $secs = (int) round((float) $b['minutes'] * 60.0);
            for ($k = 0; $k < $secs && $s < $total; $k++, $s++) {
                // Gentle upward cardiac drift within a sustained-effort block.
                $drift = ($b['hr'] >= 120) ? min(2.5, 2.5 * $k / max(1, $secs)) : 0.0;
                $target[$s] = (float) $b['hr'] + $drift;
                $blockOf[$s] = $bi;
            }
        }
        while ($s < $total) {                       // pad any rounding remainder with the last value
            $target[$s] = $target[$s - 1] ?? 90.0;
            $blockOf[$s] = $blockOf[$s - 1] ?? 0;
            $s++;
        }

        // Smooth block transitions with a short EMA so HR doesn't teleport between efforts, then add noise.
        $hr = [];
        $prev = $target[0];
        $strap = $script->strapDropout;
        $lock = $script->cadenceLock;
        for ($i = 0; $i < $total; $i++) {
            $v = $prev + 0.12 * ($target[$i] - $prev);      // ~8s time-constant approach
            $prev = $v;
            $noisy = $v + $this->sim->gaussPublic() * 1.4;

            // Phase-2 device story (no-op for the golden paths): a strap dropout zeroes HR; a cadence-lock
            // artifact pins it to a bogus high bpm.
            $minute = $i / 60.0;
            if ($strap && $minute >= $strap['start_min'] && $minute < $strap['start_min'] + $strap['dur_min']) {
                $hr[] = 0.0;

                continue;
            }
            if ($lock && $minute >= $lock['start_min'] && $minute < $lock['start_min'] + $lock['dur_min']) {
                $hr[] = (float) $lock['bpm'];

                continue;
            }
            $hr[] = max(40.0, round($noisy, 1));
        }

        return [$hr, $blockOf];
    }

    /**
     * A 1 Hz GPS series for a run: near-constant pace (so per-km splits ≈ the scripted pace within
     * ±2s/km), an out-and-back / loop ground track that actually covers ground (never a phantom
     * drift box), rolling elevation + the grade it implies.
     *
     * @return array{0:array<int,float>,1:array<int,float>,2:array<int,array<string,mixed>>}
     */
    private function renderGps(WorkoutScript $script, int $total, int $drift): array
    {
        $pace = (float) $script->gps['pace_s_per_km'];          // s per km
        $baseSpeed = 3600.0 / $pace;                            // km/h at pace
        $lat0 = (float) $script->gps['lat'];
        $lon0 = (float) $script->gps['lon'];
        $amp = (float) $script->gps['elevation_amp_m'];
        $outBack = ($script->gps['shape'] ?? 'out-and-back') !== 'loop';
        $totalM = $baseSpeed * 1000.0 / 3600.0 * $total;        // metres covered at pace
        $mLat = 111320.0;
        $mLon = 111320.0 * cos(deg2rad($lat0));
        $startMs = ($script->startAt->timestamp + $drift) * 1000;

        $speed = $grade = $track = [];
        $cum = 0.0;
        $prevAlt = null;
        for ($i = 0; $i < $total; $i++) {
            $sp = max(0.5, $baseSpeed + $this->sim->gaussPublic() * 0.12);    // km/h, tight around pace
            $speed[] = round($sp, 2);
            $cum += $sp * 1000.0 / 3600.0;                                    // metres this second
            $f = $total > 1 ? $i / ($total - 1) : 0.0;
            // Out-and-back: run out to the far point, then retrace — a real path that leaves the box.
            $x = $outBack ? ($cum <= $totalM / 2 ? $cum : $totalM - $cum) : $totalM / (2 * M_PI) * sin(2 * M_PI * $cum / max(1.0, $totalM));
            $y = 30.0 * sin($f * M_PI * 4);                                   // a gentle side bow
            $alt = 40.0 + $amp * sin($f * M_PI * 4);
            $track[] = ['t' => $startMs + $i * 1000, 'lat' => round($lat0 + $y / $mLat, 6), 'lon' => round($lon0 + $x / $mLon, 6), 'alt' => round($alt, 1)];
            $dDist = $sp * 1000.0 / 3600.0;
            $grade[] = round($prevAlt !== null && $dDist > 0.5 ? max(-0.3, min(0.3, ($alt - $prevAlt) / $dDist)) : 0.0, 4);
            $prevAlt = $alt;
        }

        return [$speed, $grade, $track];
    }

    /**
     * A 25 Hz 3-axis accel series matching the modality: stride cadence harmonics for a run (the
     * signature the classifier learned), burst-rest bouts for a lift (big amplitude during work blocks,
     * near-still between).
     *
     * @param  array<int,int>  $blockOf
     * @return array{x:array<int,int>,y:array<int,int>,z:array<int,int>}
     */
    private function renderAccel(WorkoutScript $script, array $blockOf, int $total): array
    {
        $fs = 25;
        $ax = $ay = $az = [];
        $isRun = $script->isRun();
        for ($i = 0, $n = $total * $fs; $i < $n; $i++) {
            $t = $i / $fs;
            $sec = min($total - 1, (int) $t);
            $block = $script->blocks[$blockOf[$sec] ?? 0] ?? $script->blocks[0];
            $cadence = (float) ($block['cadence_spm'] ?? 0);

            if ($isRun && $cadence > 0) {
                $hz = $cadence / 60.0;
                $ph = 2 * M_PI * $hz * $t;
                $impact = 520 * sin($ph) + 120 * sin(2 * $ph) + 45 * sin(3 * $ph);
                $az[] = (int) round(1000 + $impact + $this->sim->gaussPublic() * 55);
                $ax[] = (int) round(160 * sin($ph + 0.4) + $this->sim->gaussPublic() * 45);
                $ay[] = (int) round(120 * sin(2 * M_PI * ($hz / 2) * $t + 1.0) + $this->sim->gaussPublic() * 45);
            } else {
                // Lift: work blocks jerk hard (~0.6 Hz reps), rest blocks are near-still.
                $working = str_starts_with((string) ($block['label'] ?? ''), 'work');
                $amp = $working ? 380.0 : 40.0;
                $rep = 2 * M_PI * 0.6 * $t;
                $az[] = (int) round(1000 + $amp * sin($rep) + $this->sim->gaussPublic() * ($working ? 90 : 20));
                $ax[] = (int) round($amp * 0.5 * sin($rep + 0.8) + $this->sim->gaussPublic() * ($working ? 70 : 15));
                $ay[] = (int) round($amp * 0.4 * sin($rep * 0.5) + $this->sim->gaussPublic() * ($working ? 70 : 15));
            }
        }

        return ['x' => $ax, 'y' => $ay, 'z' => $az];
    }

    /**
     * Per-30s accel counts derived from each epoch's dominant block: a run is uniformly vigorous; a lift
     * is high during work bouts, low between. Drives the biosignal session detector + TRIMP proxy.
     *
     * @param  array<int,int>  $blockOf
     * @return array<int,int>
     */
    private function renderCounts(WorkoutScript $script, array $blockOf, int $total): array
    {
        $epochs = max(1, (int) ceil($total / 30));
        $out = [];
        for ($e = 0; $e < $epochs; $e++) {
            $sec = min($total - 1, $e * 30 + 15);
            $label = (string) ($script->blocks[$blockOf[$sec] ?? 0]['label'] ?? '');
            $out[] = match (true) {
                $script->isRun() && str_starts_with($label, 'warmup') => 48,
                $script->isRun() && str_starts_with($label, 'cooldown') => 44,
                $script->isRun() => 72,
                str_starts_with($label, 'work') => 55,
                str_starts_with($label, 'warmup') => 22,
                default => 10,               // recover / cooldown between lifts
            };
        }

        return $out;
    }

    /**
     * The accel counts (per-30s epochs) that fall inside a window span [w0,w1) seconds.
     *
     * @param  array<int,int>  $counts
     * @return array<int,int>
     */
    private function countsForSpan(array $counts, int $w0, int $w1, int $winSec): array
    {
        $lo = (int) floor($w0 / 30);
        $hi = (int) ceil($w1 / 30);

        return array_values(array_slice($counts, $lo, max(1, $hi - $lo)));
    }

    /**
     * A crude IBI series from a 1 Hz HR window, for synthesizing concurrent raw PPG. Zero-HR (dropout)
     * seconds are skipped. Only used when a script asks for ppg_raw (Phase-2 scars).
     *
     * @param  array<int,float>  $hr
     * @return array<int,int>
     */
    private function ibiFromHr(array $hr): array
    {
        $ibi = [];
        foreach ($hr as $bpm) {
            if ($bpm <= 0) {
                continue;
            }
            $ms = (int) round(60000.0 / $bpm);
            $beats = max(1, (int) round($bpm / 60.0));           // ~this many beats this second
            for ($b = 0; $b < $beats; $b++) {
                $ibi[] = max(320, min(1900, $ms + (int) round($this->sim->gaussPublic() * 12)));
            }
        }

        return $ibi;
    }
}
