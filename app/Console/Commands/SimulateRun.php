<?php

namespace App\Console\Commands;

use App\Jobs\SealActivityJob;
use App\Models\ActivitySession;
use App\Models\DeviceIngestion;
use App\Models\User;
use App\Services\Lab\VirtualAthlete;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Synthesize a full GPS run and push it through the REAL pipeline — the seal job, the live
 * biosignal /process/route, storage, and the Mapbox map URL — so the whole run feature can be
 * verified end-to-end without a band. The window it builds is exactly the shape the band+bridge
 * produce after frame decoding (see resources/js/bridge-decode.js buildWorkoutWindow).
 *
 *   php artisan titan:simulate-run --km=5 --pace=5.5
 */
class SimulateRun extends Command
{
    protected $signature = 'titan:simulate-run
        {--email= : User to attach the run to (default: the first user)}
        {--km=5 : Distance in km}
        {--pace=5.5 : Pace in minutes per km}
        {--lat=37.7694 : Start latitude (default: Golden Gate Park, SF)}
        {--lon=-122.4862 : Start longitude}
        {--shape=loop : loop | out-and-back}';

    protected $description = 'Simulate a full GPS run through the real seal → route → map pipeline';

    public function handle(): int
    {
        $user = $this->option('email')
            ? User::where('email', $this->option('email'))->first()
            : User::query()->oldest('id')->first();
        if (! $user) {
            $this->error('No user found. Pass --email= or create a user first.');

            return self::FAILURE;
        }
        $profile = $user->ensureProfile();

        $km = (float) $this->option('km');
        $paceMin = (float) $this->option('pace');
        $lat0 = (float) $this->option('lat');
        $lon0 = (float) $this->option('lon');
        $outBack = $this->option('shape') === 'out-and-back';

        $durSec = max(60, (int) round($km * $paceMin * 60));   // 1 Hz GPS
        $endAt = Carbon::now()->subMinutes(60);                 // > QUIET_MINUTES ago → sealable now
        $startAt = $endAt->copy()->subSeconds($durSec);
        $startMs = $startAt->timestamp * 1000;

        $this->info("Simulating a {$km} km run @ {$paceMin} min/km (".gmdate('i:s', $durSec).") for {$user->email}…");

        // The workout-window builder lives once, in the WORKOUT LAB's VirtualAthlete (the single wire
        // renderer both simulate commands + the lab go through), instead of an inline copy here.
        $window = VirtualAthlete::simulateRunWindow(
            $lat0, $lon0, $km, $durSec, $startMs,
            $startAt->toIso8601ZuluString(), $endAt->toIso8601ZuluString(), $outBack,
        );

        // Store the raw window where the seal job reads it (gzipped NDJSON on the `raw` disk), then a
        // QUEUED workout ingestion — exactly what DeviceIngestionService writes for a real band batch.
        $key = "raw/{$profile->id}/sim-run-".Str::random(8).'.ndjson.gz';
        Storage::disk('raw')->put($key, gzencode(json_encode($window)));

        $ingestion = DeviceIngestion::create([
            'batch_uid' => 'simrun-'.Str::random(12),
            'profile_id' => $profile->id,
            'source' => 'simulator',
            'kind' => 'workout',
            'object_key' => $key,
            'window_start' => $startAt,
            'window_end' => $endAt,
            'status' => DeviceIngestion::STATUS_QUEUED,
        ]);

        $this->line('  → window stored, sealing (hits the live biosignal /process/route)…');
        dispatch_sync(new SealActivityJob($profile->id));

        // The seal stamps the session id onto the ingestion's result_refs (timezone-proof lookup).
        $sessionId = data_get($ingestion->fresh()->result_refs, 'activity_session_id');
        $session = $sessionId ? ActivitySession::find($sessionId) : null;

        if (! $session) {
            $this->error('No ActivitySession was created — check the queue/biosignal logs.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('✓ Run sealed — ActivitySession #'.$session->id);
        $this->table(['Field', 'Value'], [
            ['type', $session->activity_type ?? '—'],
            ['distance', $session->distance_km ? $session->distance_km.' km' : '—'],
            ['moving time', $session->moving_time_s ? gmdate('i:s', $session->moving_time_s) : '—'],
            ['avg pace', $session->avg_pace_s_per_km ? gmdate('i:s', $session->avg_pace_s_per_km).' /km' : '—'],
            ['GAP', $session->gap_s_per_km ? gmdate('i:s', $session->gap_s_per_km).' /km' : '—'],
            ['elevation gain', $session->elevation_gain_m !== null ? $session->elevation_gain_m.' m' : '—'],
            ['relative effort', $session->relative_effort ?? '—'],
            ['avg / max HR', ($session->avg_hr ?? '—').' / '.($session->max_hr ?? '—')],
            ['km splits', is_array($session->splits) ? count($session->splits['km'] ?? []) : 0],
            ['best efforts', is_array($session->best_efforts) ? implode(', ', array_keys($session->best_efforts)) : '—'],
            ['has route', $session->hasRoute() ? 'yes ('.strlen((string) $session->route_polyline).'-char polyline)' : 'NO'],
        ]);
        $this->newLine();
        $this->line('  View:  '.url(route('fitness.run', $session, false)));
        $map = $session->staticMapUrl(900, 600);
        $this->line('  Map:   '.($map ? $map : '(no MAPBOX_API_TOKEN set)'));

        return self::SUCCESS;
    }
}
