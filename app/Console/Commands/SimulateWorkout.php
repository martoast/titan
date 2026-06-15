<?php

namespace App\Console\Commands;

use App\Services\Simulator\BiosignalSimulator;
use App\Services\Wearables\BiosignalClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Simulate a GPS-paced workout with the Titan virtual band and run it through the REAL biosignal
 * service — proving the activity + fitness pipeline (workout classification, TRIMP, VO2max, HRR)
 * works end-to-end before the hardware arrives.
 *
 *   php artisan simulator:workout run --minutes=30
 *   php artisan simulator:workout cycle --fitness=0.8 --minutes=45
 *
 * Honesty: this PHP twin drives the FITNESS path (HR + GPS pace + baro grade → VO2max / HRR /
 * TRIMP), which doesn't depend on the raw accel signature. The classification-accurate twin —
 * which REPLAYS real PAMAP2 motion so the classifier behaves — is the Python one
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
        {--seed= : Deterministic RNG seed}';

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

        $this->line("<info>Titan virtual band</info> — {$activity}, {$minutes} min, seed {$sim->seed()}");
        $this->table(['Sensor', 'Value'], [
            ['GPS distance', $w['distance_km'].' km'],
            ['Resting / max HR', $p['resting_hr'].' / '.$p['hr_max'].' bpm'],
            ['HRR-60s', ($w['run']['hrr60'] ?? '—').' bpm'],
        ]);

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
                $type = $s['activity_type'] ?? '—';
                $this->line("  <info>Activity</info>  {$type}".(isset($s['activity_confidence']) ? " (conf {$s['activity_confidence']})" : ''));
            }
            $this->line("  <info>VO2max</info>    {$fit['vo2max']} ± {$fit['plusminus']} ml/kg/min ({$fit['fitness_level']}) via ".implode(', ', $fit['methods']));
            $hrr = $fit['hrr']['hrr_bpm'] ?? null;
            $this->line('  <info>HRR-60s</info>   '.($hrr ?? '—').' bpm');
            $this->newLine();
            $this->info('Workout processed by the biosignal service.');
        } catch (\Throwable $e) {
            $this->newLine();
            $this->warn('Biosignal service unreachable — the workout was generated but not processed. '.
                'Bring it up with: docker compose up -d --build biosignal');
            $this->line('  ('.$e->getMessage().')');
        }

        return self::SUCCESS;
    }
}
