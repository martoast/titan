<?php

namespace App\Jobs;

use App\Models\ActivitySession;
use App\Models\DeviceIngestion;
use App\Models\Exercise;
use App\Models\Profile;
use App\Models\Workout;
use App\Models\WorkoutExercise;
use App\Models\WorkoutSet;
use App\Services\Notifications\NotificationService;
use App\Services\Wearables\BiosignalClient;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
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

    /** A session is complete once no new window has arrived for this many minutes. */
    public const QUIET_MINUTES = 10;

    /** A gap larger than this between windows starts a new session. */
    public const SESSION_GAP_MINUTES = 20;

    /** Shortest run we bother sealing (filters stray motion blips). */
    public const MIN_SESSION_MIN = 5;

    /** Most raw-PPG windows to pull when recomputing in-motion HR (caps work on a long session). */
    public const MAX_PPG_WINDOWS = 240;

    /** Minimum in-motion coverage (fraction of trusted windows) to PREFER PPG HR over the on-chip bpm. */
    public const MIN_HR_COVERAGE = 0.2;

    /** Window hop (s) of the in-motion HR estimator (biosignal STEP_S) — used to expand it to 1 Hz. */
    public const HR_WINDOW_STEP_S = 2.0;

    public int $tries = 2;

    public int $backoff = 15;

    public function __construct(public int $profileId)
    {
        $this->onQueue('biosignal');
    }

    public function handle(BiosignalClient $biosignal): void
    {
        $profile = Profile::find($this->profileId);
        if (! $profile || ! $biosignal->configured()) {
            return;
        }

        $windows = DeviceIngestion::query()
            ->where('profile_id', $profile->id)
            ->where('kind', 'workout')
            ->where('status', '!=', DeviceIngestion::STATUS_SEALED)
            ->orderBy('window_start')
            ->get();

        if ($windows->isEmpty()) {
            return;
        }

        foreach ($this->groupIntoSessions($windows) as $session) {
            if (! $this->sessionIsComplete($session)) {
                continue; // still streaming -- let it finish
            }
            try {
                $this->sealSession($profile, $biosignal, $session);
            } catch (\Throwable $e) {
                Log::warning('[Biosignal] activity seal failed', [
                    'profile_id' => $profile->id, 'error' => $e->getMessage(),
                ]);
                // Seal-anyway so a persistently bad session never wedges the queue.
                $session->each(fn (DeviceIngestion $i) => $i->update(['status' => DeviceIngestion::STATUS_SEALED]));
            }
        }
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
        $sessions = [];
        $current = collect();
        $lastEnd = null;

        foreach ($windows as $w) {
            $start = CarbonImmutable::parse($w->window_start ?? $w->created_at);
            $newSession = $lastEnd !== null && $start->diffInMinutes($lastEnd) > self::SESSION_GAP_MINUTES;
            if ($newSession && $current->isNotEmpty()) {
                $sessions[] = $current;
                $current = collect();
            }
            $current->push($w);
            $lastEnd = CarbonImmutable::parse($w->window_end ?? $w->window_start ?? $w->created_at);
        }
        if ($current->isNotEmpty()) {
            $sessions[] = $current;
        }

        return $sessions;
    }

    /** Complete once quiescent (>QUIET_MINUTES since the last window) or it ended in the past. */
    private function sessionIsComplete(\Illuminate\Support\Collection $session): bool
    {
        $lastEnd = $session
            ->map(fn (DeviceIngestion $i) => $i->window_end ?? $i->window_start ?? $i->created_at)
            ->filter()->map(fn ($t) => CarbonImmutable::parse($t))->max();

        return $lastEnd && $lastEnd->lte(now()->subMinutes(self::QUIET_MINUTES));
    }

    /**
     * Concatenate one session's windows → /process/activity (+ classification) and
     * /process/fitness, write the activity_sessions row, seal the windows, notify.
     *
     * @param  \Illuminate\Support\Collection<int,DeviceIngestion>  $session
     */
    private function sealSession(Profile $profile, BiosignalClient $biosignal, \Illuminate\Support\Collection $session): void
    {
        $ax = $ay = $az = $hr1 = $counts = $speed = $grade = $track = [];
        $unit = 'ms2';
        $fs = 25;
        $start = $end = null;

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
            $start = $start ?? ($ingestion->window_start ?? null);
            $end = $ingestion->window_end ?? $end;
        }

        $startIso = $start ? CarbonImmutable::parse($start)->toIso8601ZuluString() : null;
        $durationMin = ($start && $end) ? CarbonImmutable::parse($start)->diffInMinutes(CarbonImmutable::parse($end)) : null;
        if ($durationMin !== null && $durationMin < self::MIN_SESSION_MIN) {
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
                }
            }
        }

        // Counts per 30-s epoch drive session detection; fall back to a magnitude proxy. HR for
        // the activity endpoint is per-epoch (downsampled from the 1 Hz workout HR).
        if ($counts === []) {
            $counts = $this->countsFromAccel($ax, $ay, $az, $fs);
        }
        $hrEpoch = $this->downsample($hr1, $fs * 30);
        $maxHr = $hr1 ? (int) round(max($hr1)) : null;

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

        $fitness = $biosignal->processFitness(array_filter([
            'age' => $profileBits['age'],
            'sex' => $profileBits['sex'],
            'weight_kg' => $profileBits['weight_kg'],
            'height_cm' => $profileBits['height_cm'],
            'resting_hr' => $profileBits['resting_hr'],
            'hr_max' => $profileBits['hr_max'],
            'run' => ($hr1 && $speed) ? array_filter([
                'hr' => $hr1, 'speed_kmh' => $speed, 'grade' => $grade ?: null,
            ], fn ($v) => $v !== null) : null,
            'workout_hr_bpm' => $hr1 ?: null,
            'hr_fs' => 1.0,
        ], fn ($v) => $v !== null));

        // Run route → the Strava-style summary (map polyline + splits + elevation + best efforts +
        // Relative Effort). Only when we actually have a GPS track; the geometry stands on its own,
        // so a failure here never blocks sealing the session.
        $route = $this->routeMetrics($biosignal, $track, $hr1, (int) ($profileBits['hr_max'] ?? 0));

        // Prefer the route's GPS-integrated distance, then the activity pass, then speed·time.
        $distance = ($route['distance_km'] ?? null)
            ?? $sess['distance_km']
            ?? ($speed ? round(array_sum($speed) / 3600.0, 2) : null);

        $log = ActivitySession::updateOrCreate(
            ['profile_id' => $profile->id, 'started_at' => $startIso ? CarbonImmutable::parse($startIso) : now()],
            array_filter([
                'source' => $session->first()->source ?? 'titan_band',
                'ended_at' => $end ? CarbonImmutable::parse($end) : null,
                'duration_min' => isset($sess['duration_min']) ? (int) round($sess['duration_min']) : $durationMin,
                'activity_type' => $sess['activity_type'] ?? null,
                'activity_confidence' => $sess['activity_confidence'] ?? null,
                'distance_km' => $distance,
                'avg_hr' => isset($sess['mean_hr']) ? (int) round($sess['mean_hr']) : ($hr1 ? (int) round(array_sum($hr1) / count($hr1)) : null),
                'max_hr' => $maxHr,
                'hr_source' => $hrSource,
                'hr_quality' => $hrQuality,
                'hr_zones' => $hrZones,
                'trimp' => $sess['trimp'] ?? null,
                'calories_kcal' => isset($sess['calories_kcal']) ? (int) round($sess['calories_kcal']) : null,
                'vo2max' => $fitness['vo2max'] ?? null,
                'fitness_level' => $fitness['fitness_level'] ?? null,
                'hrr_bpm' => $fitness['hrr']['hrr_bpm'] ?? null,
                'updated_via' => 'biosignal:sealed',
                // Run route + analytics (null-filtered → a routeless session keeps its existing values).
                'route_polyline' => $route['polyline'] ?? null,
                'route_bounds' => $route['bounds'] ?? null,
                'moving_time_s' => $route['moving_time_s'] ?? null,
                'avg_pace_s_per_km' => $route['avg_pace_s_per_km'] ?? null,
                'gap_s_per_km' => $route['gap_s_per_km'] ?? null,
                'elevation_gain_m' => $route['elevation_gain_m'] ?? null,
                'elevation_loss_m' => $route['elevation_loss_m'] ?? null,
                'elevation_profile' => $route['elevation_profile'] ?? null,
                'splits' => isset($route['splits_km']) ? ['km' => $route['splits_km'], 'mi' => $route['splits_mi'] ?? []] : null,
                'best_efforts' => $route['best_efforts'] ?? null,
                'relative_effort' => $route['relative_effort'] ?? null,
            ], fn ($v) => $v !== null),
        );

        // Strength path: if this ISN'T locomotion (run/walk/cycle/stairs) and we have 3-axis accel,
        // it's likely a lifting session → run the gym analyzer and log exercises + sets + reps.
        $isCardio = in_array($sess['activity_type'] ?? '', ['run', 'walk', 'cycle', 'stairs'], true);
        if (! $isCardio && $ax && $ay && $az) {
            $this->sealStrength($profile, $biosignal, $log, $ax, $ay, $az, $fs, $unit, $startIso, $durationMin);
        }

        $session->each(fn (DeviceIngestion $i) => $i->update([
            'status' => DeviceIngestion::STATUS_SEALED,
            'result_refs' => array_merge((array) $i->result_refs, ['activity_session_id' => $log->id, 'sealed' => true]),
        ]));

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

    /** The 10 MM-Fit exercises → catalog metadata (muscle group / category / equipment / label). */
    private const EXERCISE_META = [
        'squats' => ['Squats', 'legs', 'compound', 'bodyweight'],
        'pushups' => ['Push-ups', 'chest', 'compound', 'bodyweight'],
        'dumbbell_shoulder_press' => ['Dumbbell Shoulder Press', 'shoulders', 'compound', 'dumbbell'],
        'lunges' => ['Lunges', 'legs', 'compound', 'bodyweight'],
        'dumbbell_rows' => ['Dumbbell Rows', 'back', 'compound', 'dumbbell'],
        'situps' => ['Sit-ups', 'core', 'isolation', 'bodyweight'],
        'tricep_extensions' => ['Tricep Extensions', 'arms', 'isolation', 'dumbbell'],
        'bicep_curls' => ['Bicep Curls', 'arms', 'isolation', 'dumbbell'],
        'lateral_shoulder_raises' => ['Lateral Raises', 'shoulders', 'isolation', 'dumbbell'],
        'jumping_jacks' => ['Jumping Jacks', 'cardio', 'cardio', 'bodyweight'],
    ];

    /**
     * Analyse a non-locomotion session as STRENGTH: /process/gym detects sets (exercise + reps),
     * which we write to the workouts / workout_exercises / workout_sets tables (idempotent on the
     * session's started_at), and label the ActivitySession 'strength'. Reps are what the wrist
     * counts; weight is left 0 for the user to fill in (a wrist can't know the load).
     */
    private function sealStrength(Profile $profile, BiosignalClient $biosignal, ActivitySession $log,
        array $ax, array $ay, array $az, int $fs, string $unit, ?string $startIso, ?int $durationMin): void
    {
        try {
            $gym = $biosignal->processGym([
                'accel_xyz' => ['x' => $ax, 'y' => $ay, 'z' => $az],
                'accel_fs' => $fs, 'accel_unit' => $unit,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[Biosignal] gym analysis failed', ['profile_id' => $profile->id, 'error' => $e->getMessage()]);

            return;
        }

        $sets = $gym['sets'] ?? [];
        if (count($sets) === 0) {
            return; // no sets detected → not a (recognisable) strength session
        }

        $performedAt = $startIso ? CarbonImmutable::parse($startIso) : now();
        $workout = Workout::updateOrCreate(
            ['profile_id' => $profile->id, 'performed_at' => $performedAt],
            ['name' => 'Gym session', 'duration_min' => $durationMin, 'updated_via' => 'biosignal:sealed'],
        );
        // Idempotent re-seal: rebuild this workout's exercises from scratch.
        $workout->exercises()->delete();

        $order = 0;
        foreach ($this->groupSets($sets) as $slug => $exerciseSets) {
            $exercise = $this->resolveExercise($slug);
            if (! $exercise) {
                continue;
            }
            $we = WorkoutExercise::create([
                'workout_id' => $workout->id, 'exercise_id' => $exercise->id, 'order' => $order++,
            ]);
            foreach ($exerciseSets as $n => $s) {
                WorkoutSet::create([
                    'workout_exercise_id' => $we->id,
                    'set_number' => $n + 1,
                    'reps' => (int) ($s['reps'] ?? 0),
                    'weight_kg' => 0,
                ]);
            }
        }

        // Label the activity row so the Fitness page reads it as a lifting session, not "Workout".
        $log->update(['activity_type' => 'strength']);
    }

    /**
     * Group the detected sets by exercise, preserving first-seen order.
     *
     * @param  array<int,array<string,mixed>>  $sets
     * @return array<string,array<int,array<string,mixed>>>
     */
    private function groupSets(array $sets): array
    {
        $grouped = [];
        foreach ($sets as $s) {
            $grouped[$s['exercise'] ?? 'unknown'][] = $s;
        }

        return $grouped;
    }

    /** Find-or-create the catalog Exercise for a classifier slug. */
    private function resolveExercise(string $slug): ?Exercise
    {
        $meta = self::EXERCISE_META[$slug] ?? null;
        if (! $meta) {
            return null;
        }
        [$name, $muscle, $category, $equipment] = $meta;

        return Exercise::firstOrCreate(
            ['slug' => $slug],
            ['name' => $name, 'muscle_group' => $muscle, 'category' => $category, 'equipment' => $equipment],
        );
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

    /** Median-downsample a 1 Hz series to one value per `$stride` samples (≈ per epoch). */
    private function downsample(array $series, int $stride): array
    {
        if ($series === [] || $stride < 1) {
            return [];
        }
        $out = [];
        for ($i = 0; $i < count($series); $i += $stride) {
            $slice = array_slice($series, $i, $stride);
            $out[] = round(array_sum($slice) / max(count($slice), 1), 1);
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
