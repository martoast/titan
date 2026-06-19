<?php

namespace App\Console\Commands;

use App\Jobs\SealActivityJob;
use App\Models\Profile;
use App\Models\WearableConnection;
use App\Services\Simulator\BiosignalSimulator;
use App\Services\Wearables\BiosignalClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Simulate a GPS-paced workout with the Titan virtual band and run it through the REAL biosignal
 * service -- proving the activity + fitness pipeline (workout classification, TRIMP, VO2max, HRR)
 * works end-to-end before the hardware arrives.
 *
 *   php artisan simulator:workout run --minutes=30
 *   php artisan simulator:workout cycle --fitness=0.8 --minutes=45
 *
 * Honesty: this PHP twin drives the FITNESS path (HR + GPS pace + baro grade → VO2max / HRR /
 * TRIMP), which doesn't depend on the raw accel signature. The classification-accurate twin --
 * which REPLAYS real PAMAP2 motion so the classifier behaves -- is the Python one
 * (biosignal/scripts/simulate_workout.py). If the biosignal service isn't up, this still prints
 * what the band produced and degrades gracefully.
 */
class SimulateWorkout extends Command
{
    protected $signature = 'simulator:workout
        {activity=run : walk | run | cycle}
        {--minutes=30 : Workout length in minutes}
        {--fitness=0.5 : Fitness 0..1 (fitter → lower HR at pace + faster)}
        {--hills=0.4 : Hilliness 0..1 (baro grade amplitude)}
        {--age=33 : Athlete age (HRmax + VO2max norms)}
        {--weight=78 : Weight kg}
        {--height=180 : Height cm}
        {--sex=M : M or F}
        {--seed= : Deterministic RNG seed}
        {--ingest : Stream the workout into the REAL ingestion pipeline (→ seal → Fitness page)}
        {--profile= : Profile id for --ingest (defaults to the first profile)}';

    protected $description = 'Simulate a GPS-paced workout and run it through the real biosignal activity + fitness pipeline.';

    public function handle(BiosignalClient $biosignal): int
    {
        $activity = (string) $this->argument('activity');
        if (! array_key_exists($activity, BiosignalSimulator::WORKOUTS)) {
            $this->error("Unknown activity '{$activity}'. Use: ".implode(', ', array_keys(BiosignalSimulator::WORKOUTS)));

            return self::FAILURE;
        }

        $minutes = (int) $this->option('minutes');
        $seed = $this->option('seed') !== null ? (int) $this->option('seed') : null;
        $sim = new BiosignalSimulator($seed);
        $w = $sim->generateWorkout($activity, $minutes, (float) $this->option('fitness'), (float) $this->option('hills'), (float) $this->option('age'));

        $start = Carbon::now()->subMinutes($minutes)->toIso8601ZuluString();
        $p = $w['profile'];
        $weight = (float) $this->option('weight');

        $this->line("<info>Titan virtual band</info> -- {$activity}, {$minutes} min, seed {$sim->seed()}");
        $this->table(['Sensor', 'Value'], [
            ['GPS distance', $w['distance_km'].' km'],
            ['Resting / max HR', $p['resting_hr'].' / '.$p['hr_max'].' bpm'],
            ['HRR-60s', ($w['run']['hrr60'] ?? '--').' bpm'],
        ]);

        // --- Stream into the REAL pipeline so it surfaces on the Fitness page ---
        if ($this->option('ingest')) {
            return $this->ingest($w, $activity, $minutes, $start);
        }

        // --- Drive the real biosignal service: classification + TRIMP, then VO2max + HRR ---
        try {
            $act = $biosignal->processActivity([
                'accel_counts' => $w['accel_counts'],
                'hr_bpm' => $w['hr_epoch_bpm'],
                'start' => $start,
                'hr_max' => $p['hr_max'],
                'hr_rest' => $p['resting_hr'],
                'weight_kg' => $weight,
            ])['metrics'] ?? [];

            $fit = $biosignal->processFitness([
                'age' => (float) $this->option('age'),
                'sex' => (string) $this->option('sex'),
                'weight_kg' => $weight,
                'height_cm' => (float) $this->option('height'),
                'resting_hr' => $p['resting_hr'],
                'hr_max' => $p['hr_max'],
                'run' => $w['run'],
                'workout_hr_bpm' => $w['run']['hr'],
                'hr_fs' => 1.0,
            ]);

            $s = $act['sessions'][0] ?? null;
            $this->newLine();
            if ($s) {
                $this->line("  <info>Session</info>   {$s['duration_min']} min · TRIMP {$s['trimp']} · ".round($s['calories_kcal'])." kcal");
                $type = $s['activity_type'] ?? '--';
                $this->line("  <info>Activity</info>  {$type}".(isset($s['activity_confidence']) ? " (conf {$s['activity_confidence']})" : ''));
            }
            $this->line("  <info>VO2max</info>    {$fit['vo2max']} ± {$fit['plusminus']} ml/kg/min ({$fit['fitness_level']}) via ".implode(', ', $fit['methods']));
            $hrr = $fit['hrr']['hrr_bpm'] ?? null;
            $this->line('  <info>HRR-60s</info>   '.($hrr ?? '--').' bpm');
            $this->newLine();
            $this->info('Workout processed by the biosignal service.');
        } catch (\Throwable $e) {
            $this->newLine();
            $this->warn('Biosignal service unreachable -- the workout was generated but not processed. '.
                'Bring it up with: docker compose up -d --build biosignal');
            $this->line('  ('.$e->getMessage().')');
        }

        return self::SUCCESS;
    }

    /**
     * Stream the workout as a signed `kind=workout` window into /api/devices/ingest, then seal it
     * inline → an activity_sessions row that shows on the Fitness page. Mirrors SimulateNight's
     * signing. The PHP twin omits 3-axis accel (the Python twin owns classification-accurate
     * replay), so this populates TRIMP / VO2max / HRR / distance; activity type stays unset.
     */
    private function ingest(array $w, string $activity, int $minutes, string $start): int
    {
        $profile = $this->option('profile') ? Profile::find($this->option('profile')) : Profile::query()->orderBy('id')->first();
        if (! $profile) {
            $this->error('No profile to attribute the workout to. Pass --profile=<id> or create a profile.');

            return self::FAILURE;
        }
        [$device, $secret] = $this->resolveDevice($profile);
        $end = Carbon::parse($start)->addMinutes($minutes);

        $payload = [
            'batch_uid' => (string) Str::ulid(),
            'device_id' => $device->device_id,
            'timezone' => $device->effectiveTimezone(),
            'windows' => [[
                'kind' => 'workout',
                'start' => $start,
                'end' => $end->toIso8601ZuluString(),
                'hr_bpm' => $w['run']['hr'],
                'accel_counts' => $w['accel_counts'],
                'gps' => ['speed_kmh' => $w['run']['speed_kmh'], 'grade' => $w['run']['grade']],
            ]],
        ];

        if (! $this->postSigned($device->device_id, $secret, $payload)) {
            $this->warn('Ingestion API unreachable -- workout not stored. (Is the app serving at '.config('app.url').'?)');

            return self::SUCCESS;
        }

        // Seal inline so it appears immediately (the scheduler would otherwise pick it up).
        dispatch_sync(new SealActivityJob($profile->id));
        $this->newLine();
        $this->info("Workout ingested + sealed for profile #{$profile->id}. Open /fitness to see it.");

        return self::SUCCESS;
    }

    /** @return array{0: WearableConnection, 1: string} */
    private function resolveDevice(Profile $profile): array
    {
        $device = WearableConnection::where('profile_id', $profile->id)
            ->where('source', 'titan_band')->where('device_id', 'like', 'tb_sim_%')->first();
        $secret = bin2hex(random_bytes(16));
        if (! $device) {
            $device = WearableConnection::create([
                'profile_id' => $profile->id, 'provider' => 'titan_band', 'source' => 'titan_band',
                'device_id' => 'tb_sim_'.Str::lower(Str::random(10)), 'device_token_hash' => hash('sha256', $secret),
                'timezone' => config('app.timezone', 'UTC'), 'status' => 'connected',
                'scopes' => ['ibi', 'accel', 'workout', 'sleep', 'recovery'],
            ]);
        } else {
            $device->update(['device_token_hash' => hash('sha256', $secret), 'status' => 'connected']);
        }

        return [$device, $secret];
    }

    private function postSigned(string $deviceId, string $secret, array $payload): bool
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $ts = (string) time();
        $sig = 't='.$ts.',v1='.hash_hmac('sha256', $ts.'.'.$body, hash('sha256', $secret));
        $url = rtrim(config('app.url', 'http://localhost'), '/').'/api/devices/ingest';
        try {
            return Http::withHeaders(['X-Device-Id' => $deviceId, 'X-Titan-Signature' => $sig, 'Content-Type' => 'application/json'])
                ->timeout(20)->withBody($body, 'application/json')->post($url)->successful();
        } catch (\Throwable) {
            return false;
        }
    }
}
