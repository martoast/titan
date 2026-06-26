<?php

namespace App\Console\Commands;

use App\Jobs\SealActivityJob;
use App\Models\ActivitySession;
use App\Models\DeviceIngestion;
use App\Models\User;
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

        $window = $this->buildWindow($lat0, $lon0, $km, $durSec, $startMs, $startAt, $endAt, $outBack);

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

    /** Build the kind=workout window (the band+bridge's post-decode output) for a synthetic run. */
    private function buildWindow(float $lat0, float $lon0, float $km, int $durSec, int $startMs, Carbon $startAt, Carbon $endAt, bool $outBack): array
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

        // 25 Hz running accel (milli-g): a ~2.7 Hz (162 spm) cadence with layered foot-strike harmonics
        // (→ real 3-8 Hz band energy + jerk), arm swing at half-cadence, and broadband noise — the
        // signature the trained classifier learned for "run" (vs a clean sine, which reads as "other").
        $fs = 25;
        $ax = $ay = $az = [];
        for ($i = 0, $n = $durSec * $fs; $i < $n; $i++) {
            $t = $i / $fs;
            $ph = 2 * M_PI * 2.9 * $t;                                   // ~174 spm — a clear run cadence
            $impact = 520 * sin($ph) + 120 * sin(2 * $ph) + 45 * sin(3 * $ph);   // dominant fundamental, light harmonics
            $az[] = (int) round(1000 + $impact + mt_rand(-70, 70));      // vertical foot-strike + gravity
            $ax[] = (int) round(160 * sin($ph + 0.4) + mt_rand(-60, 60));
            $ay[] = (int) round(120 * sin(2 * M_PI * 1.45 * $t + 1.0) + mt_rand(-60, 60));   // arm swing at half-cadence
        }
        $counts = array_fill(0, max(1, (int) ceil($durSec / 30)), 70);   // per-30s activity (vigorous)

        return [
            'kind' => 'workout',
            'start' => $startAt->toIso8601ZuluString(),
            'end' => $endAt->toIso8601ZuluString(),
            'accel_xyz' => ['x' => $ax, 'y' => $ay, 'z' => $az],
            'accel_fs' => $fs,
            'accel_unit' => 'mg',
            'accel_counts' => $counts,
            'hr_bpm' => $hr,
            'gps' => ['speed_kmh' => $speed, 'grade' => $grade, 'track' => $track],
            'src' => 'simulator',
        ];
    }
}
