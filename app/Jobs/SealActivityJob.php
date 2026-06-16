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
 * + GPS speed/grade — see firmware T1/T4). This job groups them into sessions, then runs each
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
                continue; // still streaming — let it finish
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
        $ax = $ay = $az = $hr1 = $counts = $speed = $grade = [];
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

        // Counts per 30-s epoch drive session detection; fall back to a magnitude proxy. HR for
        // the activity endpoint is per-epoch (downsampled from the 1 Hz workout HR).
        if ($counts === []) {
            $counts = $this->countsFromAccel($ax, $ay, $az, $fs);
        }
        $hrEpoch = $this->downsample($hr1, $fs * 30);
        $maxHr = $hr1 ? (int) round(max($hr1)) : null;

        $profileBits = $this->profileBits($profile, $maxHr);

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

        // Prefer the biosignal's GPS-integrated distance; fall back to per-second speed·time.
        $distance = $sess['distance_km'] ?? ($speed ? round(array_sum($speed) / 3600.0, 2) : null);

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
                'trimp' => $sess['trimp'] ?? null,
                'calories_kcal' => isset($sess['calories_kcal']) ? (int) round($sess['calories_kcal']) : null,
                'vo2max' => $fitness['vo2max'] ?? null,
                'fitness_level' => $fitness['fitness_level'] ?? null,
                'hrr_bpm' => $fitness['hrr']['hrr_bpm'] ?? null,
                'updated_via' => 'biosignal:sealed',
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

        $this->notify($profile, $log);

        Log::info('[Biosignal] activity sealed', [
            'profile_id' => $profile->id, 'activity_session_id' => $log->id,
            'type' => $log->activity_type, 'vo2max' => $log->vo2max,
        ]);
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
     * (the wrist's strongest VO2max signal); hr_max prefers the session's measured peak.
     *
     * @return array{age:float,sex:string,weight_kg:float,height_cm:float,resting_hr:?int,hr_max:?int}
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
            'hr_max' => $sessionMaxHr && $sessionMaxHr > 120 ? $sessionMaxHr : null,
        ];
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
