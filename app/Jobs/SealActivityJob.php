<?php

namespace App\Jobs;

use App\Models\ActivitySession;
use App\Models\DeviceIngestion;
use App\Models\Profile;
use App\Services\Notifications\NotificationService;
use App\Services\Wearables\BiosignalClient;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Seal completed WORKOUT windows for one profile into authoritative activity_sessions rows.
 *
 * The band streams a workout as one or more `kind=workout` windows (3-axis accel + on-device HR
 * + GPS speed/grade -- see firmware T1/T4). This job groups them into sessions, then runs each
 * session through the biosignal service twice: /process/activity classifies it (run/walk/cycle…)
 * and computes TRIMP + calories, and /process/fitness turns the GPS-paced run + the profile's
 * overnight resting HR into a run-calibrated VO2max + heart-rate recovery. One activity_sessions
 * row results, the contributing windows are marked sealed, and the profile is notified.
 *
 * Mirrors {@see SealNightJob}: idempotent (updateOrCreate on profile_id + started_at; re-running
 * on a sealed session is a no-op), runs on the `biosignal` queue, seals-anyway on bad data so a
 * single broken session can't wedge the queue.
 */
class SealActivityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** A gap larger than this between windows starts a new session — and a session is complete once it has
     *  been quiet for a full gap (no later window can still cluster in). */
    public const SESSION_GAP_MINUTES = 20;

    /** Shortest run we bother sealing (filters stray motion blips). */
    public const MIN_SESSION_MIN = 5;

    /** An AUTO-detected 'other' session below this classifier confidence is stray motion, not training —
     *  it counts nowhere (streak/strain) even past the length floor. A user-ended/manual session bypasses
     *  this entirely (it always counts). */
    public const LOW_CONF_OTHER = 0.5;

    /** Below this bounding-box span (metres) a GPS "run" never really left one spot — a drift candidate. */
    public const MIN_RUN_SPAN_M = 150;

    /** …and it's only drift if the claimed path is at least this many times that tiny span (scribble). */
    public const DRIFT_PATH_RATIO = 3.0;

    /** Most raw-PPG windows to pull when recomputing in-motion HR (caps work on a long session). */
    public const MAX_PPG_WINDOWS = 240;

    /** Minimum in-motion coverage (fraction of trusted windows) to PREFER PPG HR over the on-chip bpm. */
    public const MIN_HR_COVERAGE = 0.2;

    /** Window hop (s) of the in-motion HR estimator (biosignal STEP_S) — used to expand it to 1 Hz. */
    public const HR_WINDOW_STEP_S = 2.0;

    /** Shortest user-CONFIRMED workout (envelope [start,end]) worth a row — below this it's an accidental tap. */
    public const MIN_CONFIRMED_SEC = 60;

    /** Slack (seconds) around a confirmed session's [start,end] when scoping which workout windows belong to it. */
    public const SESSION_MARGIN_S = 300;

    /** How many times a session's seal may fail on a DETERMINISTIC (poison-payload / code-fault) error before
     *  its windows are PARKED in quarantine — recoverable via `activity:reopen-quarantine`, never destroyed.
     *  A transient service error (biosignal restart / 5xx) never counts toward this. Capped at the queue retry
     *  budget ($tries) so the final deterministic attempt PARKS in quarantine rather than dead-lettering — the
     *  same park-don't-destroy pattern as {@see SealNightJob::handleSessionFailure}. */
    public const MAX_SEAL_ATTEMPTS = 2;

    public int $tries = 2;

    public int $backoff = 15;

    /** Per-attempt wall-clock budget. WITHOUT this the job inherits the queue worker's default 60s — but a
     *  real strength seal runs ~2min (per-window biosignal gym/activity inference + route/HR passes), so
     *  every gym workout timed out, retried, timed out again, and was SILENTLY DROPPED (P1 "never lost"
     *  broke). 300s gives the seal room to finish; the underlying per-window cost still wants profiling
     *  (a much longer/denser session could re-breach even this). Must stay < the worker's --max-time (3600). */
    public int $timeout = 300;

    /**
     * @param  bool  $force  The most recent session ended explicitly (phone tagged the final window
     *                       `ended` — user/watch tapped End). Seal it NOW, bypassing the SESSION_GAP_MINUTES
     *                       quiescence wait and the MIN_SESSION_MIN floor, so a just-finished run appears at once.
     * @param  int|null  $sessionStartEpoch  A watch-CONFIRMED workout envelope's real [start,end] (epoch
     *                       seconds) + chosen kind. When present the seal is SCOPED to that window and a
     *                       bounded activity_sessions row is GUARANTEED even when the accel windows are
     *                       thin/late/never-arrived (the offline / out-of-range case). Mirrors
     *                       {@see SealNightJob::sealConfirmedSession}.
     */
    public function __construct(
        public int $profileId,
        public bool $force = false,
        public ?int $sessionStartEpoch = null,
        public ?int $sessionEndEpoch = null,
        public ?string $sessionKind = null,
        public bool $sessionManual = false,
        public bool $reopenQuarantine = false,
    ) {
        $this->onQueue('biosignal');
    }

    public function handle(BiosignalClient $biosignal): void
    {
        $profile = Profile::find($this->profileId);
        if (! $profile) {
            return;
        }

        // A user-CONFIRMED workout carries its own explicit [start,end,kind] (the watch's TW end marker).
        // Seal SCOPED to that — never inferred from window timestamps — so it lands even when the windows
        // are thin/late or arrived via the offline backlog flush. Runs BEFORE the biosignal gate so the
        // guaranteed row survives a biosignal outage (airplane mode).
        if ($this->sessionStartEpoch !== null && $this->sessionEndEpoch !== null
            && $this->sessionEndEpoch > $this->sessionStartEpoch) {
            $this->sealConfirmedSession($profile, $biosignal);

            return;
        }

        if (! $biosignal->configured()) {
            return;
        }

        // The routine seal SKIPS quarantined windows (a deterministic-failure park) so a poison workout can't
        // livelock; only an explicit `activity:reopen-quarantine` ($reopenQuarantine) re-includes them to
        // retry a repair once the cause is resolved. Mirrors SealNightJob's quarantine handling.
        $excluded = $this->reopenQuarantine
            ? [DeviceIngestion::STATUS_SEALED]
            : [DeviceIngestion::STATUS_SEALED, DeviceIngestion::STATUS_QUARANTINE];
        $windows = DeviceIngestion::query()
            ->where('profile_id', $profile->id)
            ->where('kind', 'workout')
            ->whereNotIn('status', $excluded)
            ->orderBy('window_start')
            ->get();

        if ($windows->isEmpty()) {
            return;
        }

        // An explicit end (force) seals only the LATEST session — the one whose final window carried
        // the `ended` flag. Older unsealed sessions still follow the normal quiet rule.
        $sessions = $this->groupIntoSessions($windows);
        $lastKey = array_key_last($sessions);
        foreach ($sessions as $idx => $session) {
            $forceThis = $this->force && $idx === $lastKey;
            if (! $this->sessionIsComplete($session, $forceThis)) {
                continue; // still streaming -- let it finish
            }
            try {
                $this->sealSession($profile, $biosignal, $session, $forceThis);
            } catch (\Throwable $e) {
                $this->handleSessionFailure($profile, $session, $e);
            }
        }
    }

    /**
     * One session's seal threw. Bound the damage the way {@see SealNightJob::handleSessionFailure} does:
     *  - A TRANSIENT failure (biosignal restarting mid-deploy, a 5xx, a DB deadlock, a blob-store hiccup) is
     *    an OPS state, not a data verdict — rethrow so the queue retries with backoff and NEVER burn an
     *    attempt or park/seal the workout away (a bad overnight deploy would otherwise silently destroy runs).
     *    The windows stay OPEN for the retry.
     *  - A DETERMINISTIC failure (a poison payload / code fault) fails identically on retry: count it, and at
     *    MAX_SEAL_ATTEMPTS PARK the windows in quarantine (recoverable via `activity:reopen-quarantine`)
     *    rather than seal-anyway, which DISCARDED the workout — the old "seal on the final attempt" bug that
     *    ate a run whenever a transient 5xx merely looked persistent after the retries ran out.
     *
     * @param  \Illuminate\Support\Collection<int,DeviceIngestion>  $session
     */
    private function handleSessionFailure(Profile $profile, \Illuminate\Support\Collection $session, \Throwable $e): void
    {
        if ($this->isTransientFailure($e)) {
            Log::warning('[Biosignal] activity seal deferred — transient service failure, will retry', [
                'profile_id' => $profile->id, 'windows' => $session->count(), 'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $attempt = 1 + (int) $session->max(fn (DeviceIngestion $i) => $i->result_refs['seal_attempts'] ?? 0);
        Log::warning('[Biosignal] activity seal failed', [
            'profile_id' => $profile->id, 'attempt' => $attempt, 'windows' => $session->count(), 'error' => $e->getMessage(),
        ]);

        if ($attempt >= self::MAX_SEAL_ATTEMPTS) {
            $session->each(fn (DeviceIngestion $i) => $i->update([
                'status' => DeviceIngestion::STATUS_QUARANTINE,
                'result_refs' => array_merge((array) $i->result_refs, [
                    'quarantined' => true, 'seal_attempts' => $attempt,
                    'seal_error' => substr($e->getMessage(), 0, 200),
                ]),
            ]));

            return;
        }

        $session->each(fn (DeviceIngestion $i) => $i->update([
            'result_refs' => array_merge((array) $i->result_refs, ['seal_attempts' => $attempt]),
        ]));
    }

    /**
     * Is this an OPS/transient failure (retry later) rather than a data-verdict one (count toward the cap)?
     * Ported from {@see SealNightJob::isTransientFailure}: ConnectionException / 5xx (+ auth-rotation /
     * rate-limit / timeout) / a QueryException that is NOT a data-or-constraint fault (SQLSTATE 22xxx/23xxx)
     * / a filesystem/S3 read-back failure are transient; a 4xx (notably a 422 poison payload) and any
     * unexpected fault are deterministic.
     */
    private function isTransientFailure(\Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }
        if ($e instanceof QueryException) {
            $sqlState = (string) $e->getCode();

            return ! (str_starts_with($sqlState, '22') || str_starts_with($sqlState, '23'));
        }
        if ($e instanceof \League\Flysystem\FilesystemException || is_a($e, 'Aws\\Exception\\AwsException')) {
            return true;
        }
        if ($e instanceof RequestException) {
            $status = $e->response?->status() ?? 0;

            return $status >= 500 || in_array($status, [401, 403, 408, 425, 429], true);
        }

        return false;
    }

    /**
     * Cluster windows into sessions: same explicit session_uid, or contiguous in time
     * (gap ≤ SESSION_GAP_MINUTES).
     *
     * @param  \Illuminate\Support\Collection<int,DeviceIngestion>  $windows
     * @return array<int,\Illuminate\Support\Collection<int,DeviceIngestion>>
     */
    private function groupIntoSessions(\Illuminate\Support\Collection $windows): array
    {
        // Sort by window_start first: a backlog flush (an offline run whose windows replay out of order)
        // must cluster by real chronology, not arrival order. Then advance $lastEnd as a running MAX
        // frontier and test the gap as a SIGNED forward difference (start after the frontier) in SECONDS —
        // never abs(). The old code set $lastEnd = the CURRENT window's end and used abs($start − $lastEnd):
        // a window whose end preceded a prior window's end dragged the frontier BACKWARD, and an
        // overlapping/earlier window read as a huge gap, false-splitting one run into two rows. This mirrors
        // SealNightJob::clusterSessions (which is left untouched) — max frontier + signed forward gap.
        $sorted = $windows
            ->sortBy(fn (DeviceIngestion $w) => CarbonImmutable::parse($w->window_start ?? $w->window_end ?? $w->created_at)->timestamp)
            ->values();

        $sessions = [];
        $current = collect();
        $lastEnd = null;   // UTC unix seconds — a running MAX of every window's end so far

        foreach ($sorted as $w) {
            $startTs = CarbonImmutable::parse($w->window_start ?? $w->window_end ?? $w->created_at)->timestamp;
            $endTs = CarbonImmutable::parse($w->window_end ?? $w->window_start ?? $w->created_at)->timestamp;
            $newSession = $lastEnd !== null && ($startTs - $lastEnd) > self::SESSION_GAP_MINUTES * 60;
            if ($newSession && $current->isNotEmpty()) {
                $sessions[] = $current;
                $current = collect();
            }
            $current->push($w);
            $lastEnd = max($lastEnd ?? $endTs, $endTs);
        }
        if ($current->isNotEmpty()) {
            $sessions[] = $current;
        }

        return $sessions;
    }

    /** Complete once quiescent for a FULL SESSION_GAP_MINUTES since the last window (so no later window
     *  could still cluster into it), or on an explicit end. The old gate was QUIET_MINUTES(10) — SHORTER
     *  than the 20-min cluster gap, so a mid-workout pause (e.g. a BLE drop) got the first half sealed as a
     *  whole workout before the stream resumed, splitting one run into two rows. */
    private function sessionIsComplete(\Illuminate\Support\Collection $session, bool $force = false): bool
    {
        if ($force) {
            return true; // explicit end signal — don't wait for the stream to fall quiet
        }

        $lastEnd = $session
            ->map(fn (DeviceIngestion $i) => $i->window_end ?? $i->window_start ?? $i->created_at)
            ->filter()->map(fn ($t) => CarbonImmutable::parse($t))->max();

        return $lastEnd && $lastEnd->lte(now()->subMinutes(self::SESSION_GAP_MINUTES));
    }

    /**
     * Concatenate one session's windows → /process/activity (+ classification) and
     * /process/fitness, write the activity_sessions row, seal the windows, notify.
     *
     * @param  \Illuminate\Support\Collection<int,DeviceIngestion>  $session
     */
    private function sealSession(Profile $profile, BiosignalClient $biosignal, \Illuminate\Support\Collection $session, bool $force = false, ?int $forceEndEpoch = null, ?string $forceKind = null): void
    {
        $confirmed = $forceEndEpoch !== null;   // sealed from a watch-confirmed envelope (bounds + kind authoritative)
        $ax = $ay = $az = $hr1 = $counts = $speed = $grade = $track = [];
        $unit = 'ms2';
        $fs = 25;
        $start = $end = null;
        $windowHrSource = null;   // 'chest_strap' if a paired strap drove HR (reference-grade)
        $rr = [];                 // strap beat-to-beat RR intervals (ms) → in-workout HRV (optional)
        $workoutHrv = null;
        $imReliableMean = null;   // in-motion estimator's mean/max over RELIABLE windows only (headline HR)
        $imReliableMax = null;
        $kindHint = null;         // the user's EXPLICIT choice on the watch: 'run' (running tab, GPS) vs
                                  // 'strength'/'lift' (heart-rate tab, no GPS). When present it is authoritative
                                  // over the post-hoc accel classifier — a gym session is never re-guessed as a run.

        foreach ($session as $ingestion) {
            $w = $this->loadWindow($ingestion);
            if ($w === null) {
                continue;
            }
            $xyz = $w['accel_xyz'] ?? [];
            $this->append($ax, $xyz['x'] ?? []);
            $this->append($ay, $xyz['y'] ?? []);
            $this->append($az, $xyz['z'] ?? []);
            $this->append($hr1, $w['hr_bpm'] ?? []);
            $this->append($counts, $w['accel_counts'] ?? []);
            $this->append($speed, $w['gps']['speed_kmh'] ?? []);
            $this->append($grade, $w['gps']['grade'] ?? []);
            // Raw coordinate fixes for the route map (windows carry their own ascending-time track).
            foreach (($w['gps']['track'] ?? []) as $pt) {
                $track[] = $pt;
            }
            $unit = $w['accel_unit'] ?? $unit;
            $fs = (int) ($w['accel_fs'] ?? $fs);
            $windowHrSource = $w['hr_source'] ?? $windowHrSource;
            $kindHint = $w['activity_kind'] ?? $kindHint;
            $this->append($rr, $w['hr_rr_ms'] ?? []);
            $start = $start ?? ($ingestion->window_start ?? null);
            $end = $ingestion->window_end ?? $end;
        }

        // A confirmed envelope's END + KIND are authoritative over the windows: the watch knows exactly
        // when you stopped and what you chose. This corrects an ended-tail that never became a full window
        // (the "lost tail" bug) and pins run-vs-lift to your choice. We keep $start = the first window's
        // start so this row shares its updateOrCreate key with any window-based seal of the SAME session
        // (no duplicate row); only the end/duration/kind are overridden.
        if ($confirmed) {
            $end = CarbonImmutable::createFromTimestamp($forceEndEpoch, 'UTC');
            if ($forceKind !== null && $forceKind !== '') {
                $kindHint = $forceKind;
            }
        }

        $startIso = $start ? CarbonImmutable::parse($start)->toIso8601ZuluString() : null;
        $durationMin = ($start && $end) ? abs(CarbonImmutable::parse($start)->diffInMinutes(CarbonImmutable::parse($end))) : null;
        // An explicitly-ended run is intentional — seal it even if short. The floor only filters stray
        // motion blips that auto-opened a session; a 1-min minimum still rejects a pure accidental tap.
        $minMinutes = $force ? 1 : self::MIN_SESSION_MIN;
        if ($durationMin !== null && $durationMin < $minMinutes) {
            $session->each(fn (DeviceIngestion $i) => $i->update(['status' => DeviceIngestion::STATUS_SEALED]));

            return;
        }

        // HEART RATE source. The on-chip bpm ($hr1) cadence-locks under load (it reports rep/stride
        // rhythm as HR). When the band streamed raw PPG live, recompute HR server-side from PPG +
        // accel with motion-artifact suppression (app/core/inmotion_hr.py) and PREFER it. We rebuild
        // $hr1 as a 1 Hz series from that estimate so every downstream metric (epochs, max, avg, VO2,
        // HRR) uses the accurate HR with no further changes. Offline workouts (no raw PPG) keep the
        // on-chip bpm — now far better itself, since the firmware forces sport mode during workouts.
        $hrSource = $hr1 ? 'onchip' : null;
        $hrQuality = null;
        if ($windowHrSource === 'chest_strap') {
            // A paired chest strap drove HR — reference-grade (immune to the motion/grip that wreck
            // wrist PPG). Trust the window's hr_bpm outright; skip the PPG recompute entirely.
            $hrSource = 'chest_strap';
            // Bonus: if the strap sent beat-to-beat RR intervals, compute in-workout HRV (RMSSD) — a
            // parasympathetic-load signal the wrist can't give under motion. Optional: straps that omit
            // RR (or too few beats) just leave it null.
            if (count($rr) >= 30) {
                try {
                    $h = $biosignal->processHrv(['ibi_ms' => array_map('floatval', $rr)]);
                    $rmssd = $h['metrics']['rmssd'] ?? null;
                    if ($rmssd !== null) {
                        $workoutHrv = round((float) $rmssd, 2);
                    }
                } catch (\Throwable $e) {
                    Log::warning('[Biosignal] workout HRV failed', ['profile_id' => $profile->id, 'error' => $e->getMessage()]);
                }
            }
        } else {
            $im = $this->inMotionHr($profile, $start, $end, $biosignal);
            if ($im !== null) {
                $cov = (float) ($im['summary']['coverage'] ?? 0.0);
                $mean = $im['summary']['hr_mean'] ?? null;
                if ($mean !== null && $cov >= self::MIN_HR_COVERAGE) {
                    $imSeries = $this->expandTo1Hz($im['bpm'] ?? [], self::HR_WINDOW_STEP_S);
                    if ($imSeries !== []) {
                        $hr1 = $imSeries;
                        $hrSource = 'ppg_inmotion';
                        $hrQuality = round($cov, 3);
                        // The estimator computed these over its RELIABLE windows only; keep them to
                        // headline avg/max instead of recomputing from the mixed (held-included) series.
                        $imReliableMean = is_numeric($mean) ? (float) $mean : null;
                        $imReliableMax = isset($im['summary']['hr_max']) && is_numeric($im['summary']['hr_max'])
                            ? (float) $im['summary']['hr_max'] : null;
                    }
                }
            }
        }

        // Counts per 30-s epoch drive session detection; fall back to a magnitude proxy. HR for
        // the activity endpoint is per-epoch (downsampled from the 1 Hz workout HR).
        if ($counts === []) {
            $counts = $this->countsFromAccel($ax, $ay, $az, $fs);
        }
        // $hr1 is a 1 Hz series (per-second HR), so a 30-s epoch is 30 samples — NOT $fs*30 (=750),
        // which confused "epoch = fs·30 accel samples" (true for accel_counts) with the HR cadence.
        // With the wrong stride hrEpoch came out ~25× too short, so activity.py's `len(hr) >= end`
        // gate always failed and HR was silently dropped from TRIMP/calories (they fell back to the
        // accel-only proxy — badly understating a low-motion, high-HR lift).
        $hrEpoch = $this->downsample($hr1, 30);
        // Peak HR: prefer the in-motion estimator's RELIABLE-window max; otherwise a robust high
        // percentile of the series — NOT a raw max(). A single PPG-spike or cadence-lock sample in
        // the on-chip series used to define max_hr, which stableHrMax() then RATCHETS into the
        // profile's permanent observed_hr_max — deflating %HRR (and thus intensity/TRIMP/calories/
        // VO2) for every future workout. The percentile rejects lone spikes.
        $maxHr = $imReliableMax !== null ? (int) round($imReliableMax)
               : ($hr1 ? (int) round($this->robustMax($hr1)) : null);

        $profileBits = $this->profileBits($profile, $maxHr);

        // Time in each HR zone (% of HRmax) — the stat that captures a hard LIFTING day, where the
        // average is dragged down by inter-set rest but the peak + minutes in the red tell the truth.
        $hrZones = $this->zonesFromHr($hr1, (int) ($profileBits['hr_max'] ?? 0));

        // GPS pace (+ baro grade) is per-second; the activity pass works on 30-s epochs aligned to
        // $counts → downsample so it can swap the MET calorie proxy for grade-aware cost-of-transport.
        $speedEpoch = $this->toEpochs($speed, count($counts));
        $gradeEpoch = $grade ? $this->toEpochs($grade, count($counts)) : null;

        $activity = $biosignal->processActivity(array_filter([
            'accel_counts' => $counts,
            'hr_bpm' => $hrEpoch ?: null,
            'start' => $startIso,
            'hr_max' => $profileBits['hr_max'],
            'hr_rest' => $profileBits['resting_hr'],
            'weight_kg' => $profileBits['weight_kg'],
            'accel_xyz' => ($ax && $ay && $az) ? ['x' => $ax, 'y' => $ay, 'z' => $az] : null,
            'accel_fs' => $fs,
            'accel_unit' => $unit,
            'accel_start' => $startIso,
            'speed_kmh' => $speedEpoch ?: null,
            'grade' => $gradeEpoch ?: null,
        ], fn ($v) => $v !== null))['metrics'] ?? [];

        $sess = $activity['sessions'][0] ?? [];
        // For the TYPE label only, prefer the LONGEST detected bout — with MIN_SESSION_MIN at 5, a brief
        // warm-up walk can be sessions[0] (chronological) and would otherwise mislabel the whole workout as
        // a "Walk". Everything else (totals, HR, route) is already aggregate/whole-workout, not sessions[0].
        $typeSess = $sess;
        foreach (($activity['sessions'] ?? []) as $s) {
            if (($s['duration_min'] ?? 0) > ($typeSess['duration_min'] ?? 0)) {
                $typeSess = $s;
            }
        }

        // The watch's explicit choice wins over the accel classifier. A 'strength'/'lift' session is
        // sealed as strength (no route, gym analysis) even if the motion briefly looked like a run; a
        // 'run' session stays cardio even if the classifier was unsure. No hint → trust the classifier.
        $liftHint = in_array($kindHint, ['lift', 'strength', 'hiit', 'yoga'], true);
        $runHint = in_array($kindHint, ['run', 'walk', 'hike', 'cycle', 'swim', 'row'], true);
        $cardioTypes = ['run', 'walk', 'cycle', 'stairs', 'hike'];
        $activityType = $typeSess['activity_type'] ?? null;
        if ($liftHint) {
            $activityType = 'strength';
        } elseif ($runHint) {
            // The user EXPLICITLY chose this cardio type on the watch (tapped Run, or coach-primed a
            // walk/hike/cycle). That choice is authoritative over the accel classifier — which readily
            // mislabels a treadmill/road run as 'stairs' or 'walk'. Honor the exact kind (was: only
            // overriding a NON-cardio guess, so a wrong 'stairs'/'walk' classification silently stuck).
            $activityType = in_array($kindHint, ['run', 'walk', 'hike', 'cycle'], true) ? $kindHint : 'run';
        }

        $fitness = $biosignal->processFitness(array_filter([
            'age' => $profileBits['age'],
            'sex' => $profileBits['sex'],
            'weight_kg' => $profileBits['weight_kg'],
            'height_cm' => $profileBits['height_cm'],
            'resting_hr' => $profileBits['resting_hr'],
            'hr_max' => $profileBits['hr_max'],
            // hr and speed_kmh MUST be equal length — the fitness endpoint's RunCapture validator
            // rejects a mismatch with a 422, which (via ->throw()) aborted the ENTIRE seal before the
            // session row was written, so a run with the accurate ppg_inmotion HR path (whose
            // expandTo1Hz length ≈ duration−6, vs speed's per-second duration) silently produced NO
            // session at all. Resample HR (and grade) onto the speed grid so the payload is always
            // well-formed.
            'run' => ($hr1 && $speed) ? array_filter([
                'hr' => $this->resampleTo($hr1, count($speed)),
                'speed_kmh' => $speed,
                'grade' => $grade ? $this->resampleTo($grade, count($speed)) : null,
            ], fn ($v) => $v !== null) : null,
            'workout_hr_bpm' => $hr1 ?: null,
            'hr_fs' => 1.0,
        ], fn ($v) => $v !== null));

        // Run route → the Strava-style summary (map polyline + splits + elevation + best efforts +
        // Relative Effort). Only when we actually have a GPS track; the geometry stands on its own,
        // so a failure here never blocks sealing the session. A lift never gets a route — even a stray
        // GPS fix that leaked in shouldn't paint a map on a gym session.
        $route = $liftHint ? [] : $this->routeMetrics($biosignal, $track, $hr1, (int) ($profileBits['hr_max'] ?? 0));

        // Prefer the route's GPS-integrated distance, then the activity pass, then speed·time.
        $distance = ($route['distance_km'] ?? null)
            ?? $sess['distance_km']
            ?? ($speed ? round(array_sum($speed) / 3600.0, 2) : null);
        $distanceSource = $distance !== null ? 'gps' : null;
        $estPace = null;   // s/km, only when we estimate from steps (no GPS pace to read)

        // No GPS distance at all (indoor / treadmill / never locked) → estimate it from the accel
        // cadence + the user's height, so an indoor run still gets distance + pace + all the HR stats
        // (just no map). Honest 'steps' source label. Failure here never blocks the seal.
        if ($distance === null && ! $liftHint && $ax && $ay && $az && ($profileBits['height_cm'] ?? 0) > 0 && $durationMin > 0) {
            try {
                $est = $biosignal->estimateStepDistance([
                    'accel_xyz' => ['x' => $ax, 'y' => $ay, 'z' => $az],
                    'accel_fs' => $fs,
                    'accel_unit' => $unit,
                    'duration_s' => $durationMin * 60,
                    'height_cm' => $profileBits['height_cm'],
                    'activity_type' => $activityType,
                ]);
                if (($est['estimated'] ?? false) && ($est['distance_km'] ?? 0) > 0) {
                    $distance = $est['distance_km'];
                    $distanceSource = 'steps';
                    $estPace = (int) round(($durationMin * 60) / max(0.01, $distance));
                }
            } catch (\Throwable $e) {
                Log::warning('[Biosignal] step-distance estimate failed', ['profile_id' => $profile->id, 'error' => $e->getMessage()]);
            }
        }

        // Reject a PHANTOM run: GPS drift while you stood still (tapped Run, then didn't move) scribbles
        // a long "path" that never leaves a small box — a real run always covers ground. If the whole
        // track fits inside a ~tennis-court box yet claims several times that box in distance, it's
        // jitter, not a run: seal the windows (so they don't re-process) but write NO session. Only for
        // GPS cardio — a step-estimated indoor run has no track and is handled by the duration floor.
        if (! $liftHint && ! $confirmed && $distanceSource === 'gps') {
            // Both measured from the RAW track geometry (never a computed/mocked distance), so the signal
            // is self-consistent: a scribble walks a long path inside a tiny box; a real run — even a loop —
            // leaves the box, and a real short run is a straight-ish line whose path ≈ its span.
            [$span, $pathM] = $this->trackGeometryMeters($track);
            if ($span < self::MIN_RUN_SPAN_M && $pathM > self::DRIFT_PATH_RATIO * max(1.0, $span)) {
                Log::info('[Seal] dropped phantom GPS-drift run', ['profile_id' => $profile->id, 'span_m' => round($span), 'path_m' => round($pathM)]);
                $session->each(fn (DeviceIngestion $i) => $i->update(['status' => DeviceIngestion::STATUS_SEALED]));

                return;
            }
        }

        // Merge onto an existing row for the SAME session if one is already there — e.g. a confirmed
        // envelope's GUARANTEED write (its button-press start can differ from the first window's start by
        // a few seconds, so the natural (profile_id, started_at) key would split one workout into two
        // rows). Match by start PROXIMITY (±3 min), comfortably under the 20-min gap that separates
        // genuinely distinct workouts, so a morning + evening run never merge.
        $startCarbon = $startIso ? CarbonImmutable::parse($startIso) : now();
        $mergeId = $this->overlappingSessionId($profile, $startCarbon);

        // CHUNK DEFENSE: a watch bug (or any over-eager auto-end) can split ONE workout into back-to-back
        // confirmed envelopes minutes apart. Those are the same session: if a just-ended row sits within
        // SESSION_GAP_MINUTES before this start, merge onto it instead of writing a sibling chunk. GPS
        // cardio is excluded — chunking is a lifting phenomenon, and route/splits must never be spliced.
        $adjacent = null;
        if ($mergeId === null && empty($track)) {
            $adjacent = $this->adjacentChunk($profile, $startCarbon);
            $mergeId = $adjacent?->id;
        }

        // WHOLE-workout mean HR from the raw series, but only over POSITIVE samples: the per-second builders
        // emit all-zero windows when HR loses lock (firmware publishes only at confidence ≥ 90), and averaging
        // those zeros deflates avg_hr (a single dropped ~10-min window drags 148→111). Same $v>0 guard
        // zonesFromHr already uses. Only reached when the in-motion reliable mean is absent (on-chip path).
        $hr1Positive = $hr1 ? array_values(array_filter($hr1, fn ($v) => $v > 0)) : [];
        $hr1Mean = $hr1Positive !== [] ? array_sum($hr1Positive) / count($hr1Positive) : null;

        // The watch chose the type → full confidence; otherwise the classifier's own score FOR THE BOUT WE
        // LABELLED (the longest one), so the confidence matches the type, not sessions[0].
        $confidence = ($liftHint || $runHint) ? 1.0 : ($typeSess['activity_confidence'] ?? null);

        // Honest-finisher: PERSIST whether this session "counts as training" instead of guessing from length
        // alone at read time. A user-ended / manual / watch-confirmed session ALWAYS counts (even a 4-min
        // max-effort finisher force-sealed on End); an AUTO-detected one counts only when it clears the
        // length floor AND isn't a low-confidence 'other' stray-motion blob. scopeTraining reads this and
        // falls back to the length rule for old rows (is_training null).
        // The duration that lands on the row (window span, else the biosignal pass's total). is_training must
        // gate on the SAME value — otherwise a windowless-but-real session gets duration_min≥5 yet is_training
        // is an explicit false, which scopeTraining excludes with no length fallback → a genuine workout drops.
        $rowDurationMin = $durationMin ?? (isset($sess['duration_min']) ? (int) round($sess['duration_min']) : null);
        $userEnded = $force || $this->sessionManual || $confirmed;
        $isTraining = $userEnded
            || ($rowDurationMin !== null && $rowDurationMin >= self::MIN_SESSION_MIN
                && ! ($activityType === 'other' && $confidence !== null && $confidence < self::LOW_CONF_OTHER));

        // This seal's own numbers (before any chunk folding).
        $newAvgHr = $imReliableMean !== null ? (int) round($imReliableMean)
            : ($hr1Mean !== null ? (int) round($hr1Mean)
            : (isset($sess['mean_hr']) ? (int) round($sess['mean_hr']) : null));
        $newTrimp = ($activity['total_trimp'] ?? 0) > 0 ? (float) $activity['total_trimp']
            : (isset($sess['trimp']) && $sess['trimp'] > 0 ? (float) $sess['trimp'] : null);
        $newKcal = ($activity['total_calories_kcal'] ?? 0) > 0 ? (int) round($activity['total_calories_kcal'])
            : (isset($sess['calories_kcal']) && $sess['calories_kcal'] > 0 ? (int) round($sess['calories_kcal']) : null);

        // Folding a chunk onto its adjacent predecessor: the row must describe the WHOLE workout, so
        // additive metrics sum, avg_hr weights by duration, max_hr maxes, and the span extends from the
        // prior row's start to this chunk's end. (Same-start merges keep replace semantics — they ARE
        // re-seals of the same window set, not a continuation.)
        if ($adjacent !== null) {
            $priorDur = max(0, (int) $adjacent->duration_min);
            $newDur = max(0, (int) ($rowDurationMin ?? 0));
            if ($newAvgHr !== null && $adjacent->avg_hr !== null && ($priorDur + $newDur) > 0) {
                $newAvgHr = (int) round(($adjacent->avg_hr * $priorDur + $newAvgHr * $newDur) / ($priorDur + $newDur));
            }
            $newAvgHr = $newAvgHr ?? $adjacent->avg_hr;
            $newTrimp = ($newTrimp ?? 0) + (float) ($adjacent->trimp ?? 0) ?: null;
            $newKcal = ($newKcal ?? 0) + (int) ($adjacent->calories_kcal ?? 0) ?: null;
            $rowDurationMin = $priorDur + $newDur;
            $maxHr = max((int) ($maxHr ?? 0), (int) ($adjacent->max_hr ?? 0)) ?: null;
            Log::info('[Seal] folded workout chunk onto adjacent session', [
                'profile_id' => $profile->id, 'into' => $adjacent->id, 'combined_min' => $rowDurationMin,
            ]);
        }

        $log = ActivitySession::updateOrCreate(
            $mergeId ? ['id' => $mergeId] : ['profile_id' => $profile->id, 'started_at' => $startCarbon],
            array_filter([
                'source' => $session->first()->source ?? 'titan_band',
                // Stored as UTC wall-clock — the activity_sessions convention (started_at is written from a
                // Zulu ISO; Strain/readers query with UTC bounds). $end here can be a naive app-tz window
                // string OR a UTC Carbon from a confirmed envelope; setTimezone normalizes both to the same
                // instant in UTC. Mixing conventions put ended_at 6h from started_at on one row (2026-07-10).
                'ended_at' => $end ? CarbonImmutable::parse($end)->setTimezone('UTC') : null,
                // The full elapsed workout, not the first detected sub-session (a run with a >1-min pause
                // splits into several — sessions[0] is only its first leg).
                'duration_min' => $rowDurationMin,
                'activity_type' => $activityType,
                'activity_confidence' => $confidence,
                'is_training' => $isTraining,
                'distance_km' => $distance,
                'distance_source' => $distanceSource,
                // WHOLE-workout mean HR: the in-motion estimator's reliable-window mean, else the full series
                // — never sessions[0]'s first-leg mean (wrong for a multi-segment run).
                'avg_hr' => $newAvgHr,
                'max_hr' => $maxHr,
                'hr_source' => $hrSource,
                'hr_quality' => $hrQuality,
                'workout_hrv_ms' => $workoutHrv,
                'hr_zones' => $hrZones,
                // TRIMP + calories summed across ALL detected sub-sessions (biosignal's total_*), so a run
                // with a mid-run stop isn't ~50% undercounted by reading only its first leg. 0 totals stay
                // "couldn't estimate" (null → array_filter preserves prior values); chunk folds add the
                // adjacent row's load in (computed above).
                'trimp' => $newTrimp,
                'calories_kcal' => $newKcal,
                'vo2max' => $fitness['vo2max'] ?? null,
                'fitness_level' => $fitness['fitness_level'] ?? null,
                'hrr_bpm' => $fitness['hrr']['hrr_bpm'] ?? null,
                'updated_via' => 'biosignal:sealed',
                // Run route + analytics (null-filtered → a routeless session keeps its existing values).
                'route_polyline' => $route['polyline'] ?? null,
                'route_bounds' => $route['bounds'] ?? null,
                'moving_time_s' => $route['moving_time_s'] ?? ($estPace !== null ? (int) round($durationMin * 60) : null),
                'avg_pace_s_per_km' => $route['avg_pace_s_per_km'] ?? $estPace,
                'gap_s_per_km' => $route['gap_s_per_km'] ?? null,
                'elevation_gain_m' => $route['elevation_gain_m'] ?? null,
                'elevation_loss_m' => $route['elevation_loss_m'] ?? null,
                'elevation_profile' => $route['elevation_profile'] ?? null,
                'splits' => isset($route['splits_km']) ? ['km' => $route['splits_km'], 'mi' => $route['splits_mi'] ?? []] : null,
                'best_efforts' => $route['best_efforts'] ?? null,
                'relative_effort' => $route['relative_effort'] ?? null,
            ], fn ($v) => $v !== null),
        );

        // Sets-belong: link any strength Workouts logged inside this session's span to it, deterministically,
        // so strengthDetail reads them by FK instead of a ±20-min proximity guess (which mis-attached sets
        // when two lifts sat close together).
        $this->linkWorkoutsToSession($profile, $log);

        // NOTE: we deliberately do NOT auto-detect exercises/sets from the accelerometer anymore. The gym
        // classifier only knew ~10 canned movements and would INVENT lifts (jumping jacks, sit-ups, bicep
        // curls…) for any low-motion session — e.g. a VO₂max cardio day started on the Lift face got a
        // fabricated set list. A strength session still seals with its real stats (HR zones, load,
        // calories, VO₂max, duration); actual exercises + weights are only ever recorded when the user
        // EXPLICITLY tells the coach during the workout (the log_set / log_workout tools write the same
        // workouts/exercises/sets tables, keyed on this session's started_at).

        $session->each(fn (DeviceIngestion $i) => $i->update([
            'status' => DeviceIngestion::STATUS_SEALED,
            'result_refs' => array_merge((array) $i->result_refs, ['activity_session_id' => $log->id, 'sealed' => true]),
        ]));

        // Community: award any newly-earned badges off this seal (never let it break the seal).
        try {
            app(\App\Services\Community\AchievementEngine::class)->evaluate($profile, $log);
        } catch (\Throwable $e) {
            Log::warning('[Community] achievement eval failed', ['profile_id' => $profile->id, 'error' => $e->getMessage()]);
        }

        // Celebrate it: a push summary + a coach message (congrats, recovery, a follow-up). The session
        // is already logged above, so the coach sees it and it counts. Guarded in tests (the reaction
        // is exercised directly in WorkoutReactionTest) to keep seal tests hermetic.
        if (! app()->runningUnitTests()) {
            ReactToWorkoutSealed::dispatch($log->id)->afterCommit();
        }

        Log::info('[Biosignal] activity sealed', [
            'profile_id' => $profile->id, 'activity_session_id' => $log->id,
            'type' => $log->activity_type, 'vo2max' => $log->vo2max,
        ]);
    }

    /**
     * Seal ONE watch-CONFIRMED workout scoped to the envelope's real [start, end, kind] — the fix for
     * a workout done while the phone was out of BLE range (started/stopped offline, or across a reboot).
     *
     * Unlike the window-timestamp-inferred seal, this:
     *   1. Enforces a minimum duration (a sub-60-s tap isn't a workout).
     *   2. Scopes to workout windows overlapping [start, end] (± margin) — live OR backlog-flushed, so a
     *      session split across the drop (some windows live, the tail from the ring) seals as ONE row.
     *   3. Is idempotent with the window-based `ended` seal: if that already made a row for these windows,
     *      it just corrects the bounds + kind (no duplicate); otherwise it seals the scoped windows.
     *   4. GUARANTEES a bounded activity_sessions row from the envelope alone even when NO windows arrived
     *      (airplane mode / biosignal down) — the workout still surfaces, never silently lost.
     *   5. Keeps the watch's chosen kind authoritative (run vs lift) and the run route/GPS handling.
     */
    /**
     * The id of an already-sealed session whose start is within ±3 min of $start for this profile, or
     * null. Lets a window-based seal MERGE onto a confirmed envelope's guaranteed-write row (whose
     * button-press start can be a few seconds off the first window's) instead of writing a duplicate.
     * The 3-min window is far under SESSION_GAP_MINUTES (20), so genuinely distinct workouts never merge.
     */
    private function overlappingSessionId(Profile $profile, CarbonImmutable $start): ?int
    {
        $id = ActivitySession::where('profile_id', $profile->id)
            ->whereBetween('started_at', [$start->subMinutes(3), $start->addMinutes(3)])
            ->orderByDesc('started_at')
            ->value('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * A session that ENDED within SESSION_GAP_MINUTES before this start is the same workout split into
     * chunks (an over-eager watch auto-end — every ~2-min lifting rest, 2026-07-10), not a new one: real
     * distinct workouts are separated by more than the gap by definition (it's the clustering constant).
     * Routeless only — callers exclude GPS cardio so routes/splits are never spliced across chunks.
     * ended_at is UTC-naive (table convention), matching $start's UTC clock.
     */
    private function adjacentChunk(Profile $profile, CarbonImmutable $start): ?ActivitySession
    {
        return ActivitySession::where('profile_id', $profile->id)
            ->whereNull('route_polyline')
            ->whereBetween('ended_at', [$start->subMinutes(self::SESSION_GAP_MINUTES), $start])
            ->orderByDesc('ended_at')
            ->first();
    }

    /**
     * Sets-belong (W-4): attach this profile's logged strength Workouts to the ActivitySession they were
     * performed in — DETERMINISTICALLY by containment, the one seal-time backfill. A workout whose
     * performed_at falls inside the sealed session's [start,end] (± a small clock-skew margin, well under
     * the 20-min gap between distinct sessions) is linked, and only if not already claimed by another
     * session (whereNull), so a set never doubles onto two lifts. strengthDetail then reads by this FK
     * instead of the old ±20-min proximity guess.
     */
    private function linkWorkoutsToSession(Profile $profile, ActivitySession $log): void
    {
        if ($log->started_at === null) {
            return;
        }
        // activity_sessions timestamps are UTC wall-clock (naive), but workouts.performed_at is written in
        // APP-TZ wall-clock (CoachTools::logSet uses now()); the Eloquent cast mislabels the UTC-stored
        // values as app-tz, so comparing casts directly aims the containment window ~tz-offset hours off.
        // Re-read the raw values as UTC and convert to app tz so both sides speak the same clock.
        $tz = config('app.timezone');
        $startLocal = CarbonImmutable::parse($log->getRawOriginal('started_at'), 'UTC')->setTimezone($tz);
        $endLocal = $log->getRawOriginal('ended_at')
            ? CarbonImmutable::parse($log->getRawOriginal('ended_at'), 'UTC')->setTimezone($tz)
            : null;
        $from = $startLocal->subSeconds(self::SESSION_MARGIN_S);
        $to = ($endLocal ?? $startLocal->addHours(4))->addSeconds(self::SESSION_MARGIN_S);

        $profile->workouts()
            ->whereNull('activity_session_id')
            ->whereBetween('performed_at', [$from, $to])
            ->update(['activity_session_id' => $log->id]);
    }

    private function sealConfirmedSession(Profile $profile, BiosignalClient $biosignal): void
    {
        $startEpoch = (int) $this->sessionStartEpoch;
        $endEpoch = (int) $this->sessionEndEpoch;
        $durSec = $endEpoch - $startEpoch;
        if ($durSec < self::MIN_CONFIRMED_SEC) {
            Log::info('[Biosignal] confirmed workout below minimum — not sealed', [
                'profile_id' => $profile->id, 'dur_sec' => $durSec,
            ]);

            return;
        }

        $startDt = CarbonImmutable::createFromTimestamp($startEpoch, 'UTC');
        $endDt = CarbonImmutable::createFromTimestamp($endEpoch, 'UTC');
        $durMin = (int) round($durSec / 60);
        $kind = ($this->sessionKind !== null && $this->sessionKind !== '') ? $this->sessionKind : null;

        // Workout windows whose span overlaps the session (± margin). Includes ALREADY-sealed ones so we
        // can reconcile with a prior window-based seal instead of writing a duplicate. Scoping is the
        // anti-vacuum guarantee: an unrelated earlier session's windows never leak into this one.
        $loEpoch = $startEpoch - self::SESSION_MARGIN_S;
        $hiEpoch = $endEpoch + self::SESSION_MARGIN_S;
        $scoped = DeviceIngestion::query()
            ->where('profile_id', $profile->id)
            ->where('kind', 'workout')
            ->get()
            ->filter(function (DeviceIngestion $i) use ($loEpoch, $hiEpoch) {
                $ws = $i->window_start ? CarbonImmutable::parse($i->window_start)->timestamp : null;
                $we = $i->window_end ? CarbonImmutable::parse($i->window_end)->timestamp : $ws;
                if ($ws === null && $we === null) {
                    return false;
                }

                return ($we ?? $ws) >= $loEpoch && ($ws ?? $we) <= $hiEpoch;
            });

        // Already sealed into a row (the window-based `ended` seal ran first)? Just correct the bounds +
        // kind authoritatively — don't create a second row.
        $existingId = $scoped
            ->map(fn (DeviceIngestion $i) => $i->result_refs['activity_session_id'] ?? null)
            ->filter()->first();
        if ($existingId && ($log = ActivitySession::find($existingId))) {
            $log->update(array_filter([
                'ended_at' => $endDt,
                'duration_min' => $durMin,
                'activity_type' => $this->confirmedActivityType($kind),
                'activity_confidence' => $kind ? 1.0 : null,
                'is_training' => true,   // a user-confirmed session always counts as training
                'updated_via' => 'biosignal:sealed-session',
            ], fn ($v) => $v !== null));
            $this->linkWorkoutsToSession($profile, $log);
            Log::info('[Biosignal] confirmed workout reconciled onto existing row', [
                'profile_id' => $profile->id, 'activity_session_id' => $log->id, 'dur_min' => $durMin,
            ]);

            return;
        }

        // Not yet sealed but we DO have the accel/HR/GPS windows → run the full scoped seal (classify,
        // TRIMP, VO2, route…), with the envelope's end + kind authoritative. force=true bypasses the
        // quiet wait + the min-duration floor + the phantom-drift reject (the user confirmed it).
        $unsealed = $scoped->filter(fn (DeviceIngestion $i) => $i->status !== DeviceIngestion::STATUS_SEALED);
        if ($unsealed->isNotEmpty() && $biosignal->configured()) {
            $this->sealSession($profile, $biosignal, $unsealed, true, $endEpoch, $kind);

            return;
        }

        // GUARANTEED write: no usable windows (airplane / never uploaded / biosignal down). Build the row
        // from the envelope itself so the workout still surfaces — bounded, with the user's chosen kind,
        // never dropped. Keyed on the envelope start so a later window-based seal merges rather than dupes.
        $log = ActivitySession::updateOrCreate(
            ['profile_id' => $profile->id, 'started_at' => $startDt],
            array_filter([
                'source' => 'titan_band',
                'ended_at' => $endDt,
                'duration_min' => $durMin,
                'activity_type' => $this->confirmedActivityType($kind),
                'activity_confidence' => $kind ? 1.0 : null,
                'is_training' => true,   // a user-confirmed session always counts as training
                'updated_via' => 'biosignal:sealed-session-marker',
            ], fn ($v) => $v !== null),
        );
        $this->linkWorkoutsToSession($profile, $log);

        // Mark any scoped-but-sealed-without-a-row windows onto this row (edge: sealed by a min-floor drop).
        $scoped->each(fn (DeviceIngestion $i) => $i->update([
            'status' => DeviceIngestion::STATUS_SEALED,
            'result_refs' => array_merge((array) $i->result_refs, ['activity_session_id' => $log->id, 'sealed' => true]),
        ]));

        if (! app()->runningUnitTests()) {
            ReactToWorkoutSealed::dispatch($log->id)->afterCommit();
        }

        Log::info('[Biosignal] confirmed workout sealed from marker (guaranteed row)', [
            'profile_id' => $profile->id, 'activity_session_id' => $log->id,
            'dur_min' => $durMin, 'kind' => $kind, 'scoped_windows' => $scoped->count(),
        ]);
    }

    /** Map the watch's chosen kind to an activity_type (lift family → 'strength'; else the kind, or 'other'). */
    private function confirmedActivityType(?string $kind): string
    {
        if ($kind === null || $kind === '') {
            return 'other';
        }

        return in_array($kind, ['lift', 'strength', 'hiit', 'yoga'], true) ? 'strength' : $kind;
    }

    /**
     * Run route → the Strava-style summary via biosignal /process/route. Sorts + dedupes the
     * cross-window coordinate fixes, then asks the service for distance/pace/splits/elevation/best-
     * efforts/Relative-Effort + the map polyline. Pure geometry, so a failure is logged and swallowed
     * — it never blocks sealing the session.
     *
     * @param  array<int,array<string,mixed>>  $track  [{t,lat,lon,alt?}]
     * @param  array<int,float>  $hr1  1 Hz HR aligned to the run start
     * @return array<string,mixed>
     */
    private function routeMetrics(BiosignalClient $biosignal, array $track, array $hr1, int $hrMax): array
    {
        if (count($track) < 2 || ! $biosignal->configured()) {
            return [];
        }
        // Order by time and drop duplicate timestamps (windows can overlap a fix at their boundary).
        usort($track, fn ($a, $b) => ($a['t'] ?? 0) <=> ($b['t'] ?? 0));
        $seen = [];
        $clean = [];
        foreach ($track as $pt) {
            $t = $pt['t'] ?? null;
            if ($t === null || isset($seen[$t]) || ! isset($pt['lat'], $pt['lon'])) {
                continue;
            }
            $seen[$t] = true;
            $clean[] = $pt;
        }
        if (count($clean) < 2) {
            return [];
        }

        try {
            $res = $biosignal->processRoute(array_filter([
                'track' => $clean,
                'hr_bpm' => $hr1 ?: null,
                'hr_max' => $hrMax > 0 ? $hrMax : null,
            ], fn ($v) => $v !== null));
        } catch (\Throwable $e) {
            Log::warning('[Biosignal] route analysis failed', ['error' => $e->getMessage()]);

            return [];
        }

        return ($res['valid'] ?? false) ? $res : [];
    }

    /**
     * A GPS track's geometry, in metres: [bounding-box diagonal (spatial EXTENT), total PATH length].
     * A real run covers ground so its extent is large; GPS jitter while standing still stays inside a
     * few dozen metres while its path (sum of jittery hops) can still be long. Both are measured from the
     * raw points so the drift test is self-consistent. Equirectangular approximation — plenty accurate at
     * the scale of one workout.
     *
     * @param  array<int,array<string,mixed>>  $track
     * @return array{0:float,1:float}  [spanMeters, pathMeters]
     */
    private function trackGeometryMeters(array $track): array
    {
        $pts = [];
        foreach ($track as $pt) {
            if (isset($pt['lat'], $pt['lon']) && is_numeric($pt['lat']) && is_numeric($pt['lon'])) {
                $pts[] = [(float) $pt['lat'], (float) $pt['lon']];
            }
        }
        if (count($pts) < 2) {
            return [0.0, 0.0];
        }

        $lats = array_column($pts, 0);
        $lons = array_column($pts, 1);
        $mPerLat = 111_320.0;
        $mPerLon = 111_320.0 * cos(deg2rad(array_sum($lats) / count($lats)));

        $latSpan = (max($lats) - min($lats)) * $mPerLat;
        $lonSpan = (max($lons) - min($lons)) * $mPerLon;
        $span = sqrt($latSpan * $latSpan + $lonSpan * $lonSpan);

        $path = 0.0;
        for ($i = 1, $n = count($pts); $i < $n; $i++) {
            $dLat = ($pts[$i][0] - $pts[$i - 1][0]) * $mPerLat;
            $dLon = ($pts[$i][1] - $pts[$i - 1][1]) * $mPerLon;
            $path += sqrt($dLat * $dLat + $dLon * $dLon);
        }

        return [$span, $path];
    }

    /**
     * Resolve the profile inputs the biosignal endpoints need. resting_hr is the OVERNIGHT RHR
     * (the wrist's strongest VO2max signal); hr_max is a STABLE ceiling (age estimate,
     * ratcheted up by genuine near-max efforts) -- never the raw session peak.
     *
     * @return array{age:float,sex:string,weight_kg:float,height_cm:float,resting_hr:?int,hr_max:int}
     */
    private function profileBits(Profile $profile, ?int $sessionMaxHr): array
    {
        $age = $profile->birthdate ? CarbonImmutable::parse($profile->birthdate)->diffInYears(now()) : 33.0;
        $weight = (float) ($profile->bodyMetrics()->latest('taken_at')->value('weight_kg') ?? 75.0);
        $restingHr = $profile->recoveryLogs()->whereNotNull('resting_hr')->latest('logged_at')->value('resting_hr');

        return [
            'age' => (float) $age,
            'sex' => (string) ($profile->sex ?? 'M'),
            'weight_kg' => $weight,
            'height_cm' => (float) ($profile->height_cm ?? 175),
            'resting_hr' => $restingHr !== null ? (int) $restingHr : null,
            'hr_max' => $this->stableHrMax($profile, (float) $age, $sessionMaxHr),
        ];
    }

    /**
     * A STABLE estimate of the users max HR -- NOT the session peak. HRmax is a physiological
     * ceiling; a session peak only reaches it on near-maximal efforts, so the raw peak inflates
     * %HRR -- and thus intensity, TRIMP, calories and VO2max -- on easy/moderate days. We floor it
     * with an age estimate (Tanaka 208 - 0.7*age, better than 220 - age) and a remembered observed
     * max, and let a genuine near-max effort ratchet the ceiling up (and persist it).
     */
    private function stableHrMax(Profile $profile, float $age, ?int $sessionMaxHr): int
    {
        $ageEstimate = (int) round(208 - 0.7 * $age);
        $stored = (int) (data_get($profile->settings, "observed_hr_max") ?? 0);
        // Ignore implausible peaks (PPG spikes); only a real effort defines a ceiling.
        $session = ($sessionMaxHr && $sessionMaxHr > 120 && $sessionMaxHr <= 215) ? $sessionMaxHr : 0;

        if ($session > $stored && $session > $ageEstimate) {
            $settings = $profile->settings ?? [];
            $settings["observed_hr_max"] = $session;
            $profile->update(["settings" => $settings]);
            $stored = $session;
        }

        return max($ageEstimate, $stored, $session);
    }

    /** Per-30-s actigraphy counts from raw accel magnitude (when the window omits accel_counts). */
    private function countsFromAccel(array $ax, array $ay, array $az, int $fs): array
    {
        $n = min(count($ax), count($ay), count($az));
        if ($n === 0) {
            return [];
        }
        $counts = [];
        $epoch = max(1, $fs * 30);
        $prevMag = null;
        $acc = 0.0;
        $i = 0;
        for ($k = 0; $k < $n; $k++) {
            $mag = sqrt($ax[$k] ** 2 + $ay[$k] ** 2 + $az[$k] ** 2);
            if ($prevMag !== null) {
                $acc += abs($mag - $prevMag);
            }
            $prevMag = $mag;
            if (++$i >= $epoch) {
                $counts[] = (int) round(min($acc * 2.0, 300));
                $acc = 0.0;
                $i = 0;
            }
        }
        if ($i > 0) {
            $counts[] = (int) round(min($acc * 2.0, 300));
        }

        return $counts;
    }

    /**
     * Downsample a 1 Hz series to one value per `$stride` samples (≈ per epoch), averaging the POSITIVE
     * samples in each epoch only. The per-second HR builders emit zeros where HR loses lock (firmware
     * publishes only at confidence ≥ 90); averaging those zeros IN deflates the epoch — 15 real 148-bpm
     * samples + 15 zeros collapse to a false 74, a positive-but-halved value that then slips PAST the
     * downstream `hr > 0` gate (activity.py) as if it were a genuine 74-bpm reading, deflating TRIMP /
     * calories / zones for a low-motion, strap-dropping lift. Filtering at the SOURCE keeps every consumer
     * on positive samples only (matching $hr1Positive, zonesFromHr, activity.py). A fully-zero epoch → 0.0,
     * i.e. a gap the activity pass skips — the epoch count (and thus accel/speed/grade alignment) is kept.
     */
    private function downsample(array $series, int $stride): array
    {
        if ($series === [] || $stride < 1) {
            return [];
        }
        $out = [];
        for ($i = 0; $i < count($series); $i += $stride) {
            $positive = array_filter(array_slice($series, $i, $stride), fn ($v) => is_numeric($v) && $v > 0);
            $out[] = $positive !== [] ? round(array_sum($positive) / count($positive), 1) : 0.0;
        }

        return $out;
    }

    /**
     * A spike-robust "peak" of a per-second HR series: the given high percentile (default 98th),
     * so a lone PPG-glitch / cadence-lock sample can't define max_hr (and thus ratchet the profile's
     * permanent HR-max). For a clean series this sits right at the true peak.
     *
     * @param  array<int,float>  $series
     */
    private function robustMax(array $series, float $pct = 0.98): float
    {
        $vals = array_values(array_filter($series, 'is_numeric'));
        if ($vals === []) {
            return 0.0;
        }
        sort($vals);
        $idx = (int) round($pct * (count($vals) - 1));

        return (float) $vals[$idx];
    }

    /**
     * Linearly resample a series to exactly $len points (endpoints preserved). Used to put the
     * in-motion HR series onto the GPS speed's per-second grid so the fitness endpoint's
     * equal-length requirement always holds.
     *
     * @param  array<int,float>  $series
     * @return array<int,float>
     */
    private function resampleTo(array $series, int $len): array
    {
        $series = array_values(array_map('floatval', $series));
        $n = count($series);
        if ($len <= 0 || $n === 0) {
            return [];
        }
        if ($n === $len) {
            return $series;
        }
        $out = [];
        for ($i = 0; $i < $len; $i++) {
            $pos = $len === 1 ? 0.0 : $i * ($n - 1) / ($len - 1);
            $lo = (int) floor($pos);
            $hi = min($n - 1, $lo + 1);
            $frac = $pos - $lo;
            $out[] = $series[$lo] * (1 - $frac) + $series[$hi] * $frac;
        }

        return $out;
    }

    /** @param array<int,mixed> $into */
    private function append(array &$into, mixed $values): void
    {
        foreach ((array) $values as $v) {
            if (is_numeric($v)) {
                $into[] = (float) $v;
            }
        }
    }

    /**
     * Average a per-second series down to $nEpochs 30-s epochs, aligned to the accel counts so the
     * activity pass can attach grade-aware EE. Empty/short input → []. Gaps average to 0 (no move).
     *
     * @param  array<int,float>  $perSecond
     * @return array<int,float>
     */
    private function toEpochs(array $perSecond, int $nEpochs): array
    {
        if (! $perSecond || $nEpochs < 1) {
            return [];
        }
        $per = max(1, (int) ceil(count($perSecond) / $nEpochs));
        $out = [];
        for ($e = 0; $e < $nEpochs; $e++) {
            $chunk = array_filter(array_slice($perSecond, $e * $per, $per), 'is_numeric');
            $out[] = $chunk ? array_sum($chunk) / count($chunk) : 0.0;
        }
        return $out;
    }

    /**
     * Recompute workout HR from the raw PPG the band streamed live (ppg_raw windows overlapping the
     * session), suppressing the accelerometer's motion frequencies so it doesn't cadence-lock the way
     * the on-chip bpm does. Returns the biosignal response ({bpm[], confidence[], reliable[], summary})
     * or null when there isn't enough raw PPG (e.g. an offline workout — those keep the on-chip bpm).
     *
     * @return array<string,mixed>|null
     */
    private function inMotionHr(Profile $profile, ?string $start, ?string $end, BiosignalClient $biosignal): ?array
    {
        if (! $start || ! $end) {
            return null;
        }
        $from = CarbonImmutable::parse($start)->subMinute();
        $to = CarbonImmutable::parse($end)->addMinute();

        $windows = DeviceIngestion::query()
            ->where('profile_id', $profile->id)
            ->where('kind', 'ppg_raw')
            ->where('window_start', '<', $to)
            ->where(function ($q) use ($from) {
                $q->whereNull('window_end')->orWhere('window_end', '>', $from);
            })
            ->orderBy('window_start')
            ->limit(self::MAX_PPG_WINDOWS)
            ->get();
        if ($windows->isEmpty()) {
            return null;
        }

        $ppg = $ax = $ay = $az = [];
        $fs = null;
        $accelAligned = true;
        foreach ($windows as $w) {
            $d = $this->loadWindow($w);
            $samples = $d['ppg'] ?? null;
            if (! is_array($samples) || $samples === []) {
                continue;
            }
            $this->append($ppg, $samples);
            $triples = (array) ($d['accel_xyz'] ?? []);
            if (count($triples) === count($samples)) {
                foreach ($triples as $t) {
                    $ax[] = (float) ($t[0] ?? 0);
                    $ay[] = (float) ($t[1] ?? 0);
                    $az[] = (float) ($t[2] ?? 0);
                }
            } else {
                $accelAligned = false; // this window had no per-sample accel → can't suppress motion
            }
            $fs = $fs ?? (float) ($d['sample_rate_hz'] ?? 25.0);
        }

        $fs = $fs ?: 25.0;
        if (count($ppg) < (int) ($fs * 16)) {       // need ~2 analysis windows of PPG to be meaningful
            return null;
        }
        if (! $accelAligned || count($ax) !== count($ppg)) {
            $ax = $ay = $az = [];                   // motion suppression off rather than misaligned
        }

        try {
            return $biosignal->processInMotionHr(array_filter([
                'ppg' => $ppg,
                'fs_ppg' => $fs,
                'accel_x' => $ax ?: null,
                'accel_y' => $ay ?: null,
                'accel_z' => $az ?: null,
                'fs_acc' => $fs,
            ], fn ($v) => $v !== null));
        } catch (\Throwable $e) {
            Log::warning('[Biosignal] in-motion HR failed', [
                'profile_id' => $profile->id, 'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Expand the estimator's per-window HR (one value every HR_WINDOW_STEP_S seconds, with last-good
     * hold-fill and possible leading nulls) into a 1 Hz series, so it drops into the rest of the seal
     * pipeline exactly where the 1 Hz on-chip series did. Leading nulls are back-filled from the first
     * trusted reading. Returns [] if there's no usable value at all.
     *
     * @param  array<int,float|null>  $bpm
     * @return array<int,float>
     */
    private function expandTo1Hz(array $bpm, float $stepS): array
    {
        $rep = max(1, (int) round($stepS));
        $firstFinite = null;
        foreach ($bpm as $v) {
            if (is_numeric($v)) {
                $firstFinite = (float) $v;
                break;
            }
        }
        if ($firstFinite === null) {
            return [];
        }
        $out = [];
        $last = $firstFinite;
        foreach ($bpm as $v) {
            $val = is_numeric($v) ? (float) $v : $last;
            $last = $val;
            for ($i = 0; $i < $rep; $i++) {
                $out[] = $val;
            }
        }

        return $out;
    }

    /**
     * Minutes spent in each HR zone (% of HRmax) over the ~1 Hz workout HR series. Zones: Z1 50-60,
     * Z2 60-70, Z3 70-80, Z4 80-90, Z5 90+ %. Below 50% counts as rest (not a zone). Returns
     * ['z1'..'z5' => minutes] or null when there's no usable HR / HRmax.
     *
     * @param  array<int,float>  $hr  per-second HR series
     * @return array<string,float>|null
     */
    private function zonesFromHr(array $hr, int $hrMax): ?array
    {
        if ($hr === [] || $hrMax < 100) {
            return null;
        }
        $sec = [0, 0, 0, 0, 0];           // seconds in Z1..Z5
        foreach ($hr as $v) {
            if (! is_numeric($v) || $v <= 0) {
                continue;
            }
            $pct = $v / $hrMax;
            if ($pct >= 0.90) {
                $sec[4]++;
            } elseif ($pct >= 0.80) {
                $sec[3]++;
            } elseif ($pct >= 0.70) {
                $sec[2]++;
            } elseif ($pct >= 0.60) {
                $sec[1]++;
            } elseif ($pct >= 0.50) {
                $sec[0]++;
            }
        }
        $mins = array_map(fn ($s) => round($s / 60, 1), $sec);
        if (array_sum($mins) <= 0) {
            return null;
        }

        return ['z1' => $mins[0], 'z2' => $mins[1], 'z3' => $mins[2], 'z4' => $mins[3], 'z5' => $mins[4]];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function loadWindow(DeviceIngestion $ingestion): ?array
    {
        if (! $ingestion->object_key || ! Storage::disk('raw')->exists($ingestion->object_key)) {
            return null;
        }
        $raw = Storage::disk('raw')->get($ingestion->object_key);
        $decoded = @gzdecode($raw);
        $body = $decoded !== false ? $decoded : $raw;
        foreach (preg_split('/\r?\n/', trim((string) $body)) ?: [] as $line) {
            if ($line === '') {
                continue;
            }
            $obj = json_decode($line, true);
            if (is_array($obj)) {
                return $obj;
            }
        }

        return null;
    }

    private function notify(Profile $profile, ActivitySession $log): void
    {
        try {
            $bits = [];
            if ($log->distance_km) {
                $bits[] = "{$log->distance_km} km";
            }
            if ($log->vo2max) {
                $bits[] = "VO₂max {$log->vo2max}";
            }
            $body = $log->title().' logged'.($bits ? ' · '.implode(' · ', $bits) : '').'. Tap to see it.';
            app(NotificationService::class)->notify($profile, $log->title().' recorded', $body, '/fitness', 'activity');
        } catch (\Throwable $e) {
            Log::warning('[Biosignal] activity notification failed', ['profile_id' => $profile->id, 'error' => $e->getMessage()]);
        }
    }
}
