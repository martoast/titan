<?php

namespace App\Console\Commands;

use App\Models\Profile;
use App\Models\WearableConnection;
use App\Services\Simulator\BiosignalSimulator;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Headlessly simulate a full night with the Titan virtual band and stream it into the
 * REAL ingestion pipeline -- exactly like the hardware would, HMAC-signed.
 *
 *   php artisan simulator:night --profile=1
 *   php artisan simulator:night --profile=1 --speed=0     # generate, don't sleep between batches
 *   php artisan simulator:night --profile=1 --dry         # generate + print, skip the HTTP POST
 *
 * It generates a hypnogram, emits Shape-A IBI+accel windows (one per sleep segment)
 * plus a Shape-C nightly sleep + recovery summary, and POSTs them to
 * /api/devices/ingest with `X-Device-Id` + `X-Titan-Signature` (see
 * tasks/titan-wearable/04-platform-pipeline.md §2.2). If the API isn't reachable yet
 * (the ingestion build lands in parallel), it degrades gracefully and still reports
 * what the band produced.
 */
class SimulateNight extends Command
{
    protected $signature = 'simulator:night
        {--profile= : Profile id to attribute the night to (defaults to the first profile)}
        {--speed=1 : Time compression factor; 0 = no inter-batch delay (used for visual pacing only)}
        {--minutes=480 : Total night length in minutes}
        {--seed= : Deterministic RNG seed for a reproducible night}
        {--confirmed : Simulate the WATCH-ENDED flow — stream raw windows + a confirmed sleep_session marker (real bedtime/wake) and let the SERVER stage from the windows (exercises SealNightJob::sealConfirmedSession) instead of shipping pre-baked stages}
        {--raw-ppg : Stream the REAL Titan-band overnight shape — kind=ppg_raw with raw PPG samples (server peak-detects → IBI → stages), accel omitted like the offline T2 log — instead of pre-detected IBI windows}
        {--ppg-hz=25 : PPG sample rate for --raw-ppg (offline T2 runs ~12–25 Hz)}
        {--dry : Generate + print the night but do not POST to the ingestion API}';

    protected $description = 'Simulate a night with the Titan virtual band and stream it into the real ingestion pipeline.';

    public function handle(): int
    {
        $profile = $this->resolveProfile();
        if (! $profile) {
            $this->error('No profile found. Pass --profile=<id> or create a profile first.');

            return self::FAILURE;
        }

        $minutes = (int) $this->option('minutes');
        $seed = $this->option('seed') !== null ? (int) $this->option('seed') : null;
        $sim = new BiosignalSimulator($seed);

        [$device, $secret] = $this->resolveDevice($profile);
        $tz = $device->effectiveTimezone();

        $this->line("<info>Titan virtual band</info> -- profile #{$profile->id}, device <comment>{$device->device_id}</comment>, seed {$sim->seed()}");
        $this->newLine();

        // Anchor the night to last night: bedtime ~23:30, waking after `$minutes`.
        $wake = Carbon::now($tz)->startOfDay()->addHours(7);
        $bedtime = $wake->copy()->subMinutes($minutes);

        $segments = $sim->hypnogram($minutes);
        $summary = BiosignalSimulator::summariseSleep($segments);

        // Build Shape-A windows (one per hypnogram segment) + collect IBI for whole-night HRV/RHR.
        $cursor = $bedtime->copy();
        $windows = [];
        $allIbi = [];
        $rhrMedians = [];
        $bar = $this->output->createProgressBar(count($segments));
        $bar->start();

        $rawPpg = (bool) $this->option('raw-ppg');
        $ppgHz = max(8, (int) $this->option('ppg-hz'));
        foreach ($segments as $seg) {
            $w = $sim->generateWindow($seg['state'], $seg['minutes'] * 60);
            $start = $cursor->copy();
            $end = $cursor->copy()->addMinutes($seg['minutes']);

            $windows[] = $rawPpg
                // The REAL Titan-band overnight shape: raw PPG, server peak-detects → IBI → stages.
                // Accel is OMITTED (like the offline T2 log) so the server uses its PPG-quality motion
                // proxy — matching PpgWindow (ios/TitanCore Windowing.swift).
                ? [
                    'kind' => 'ppg_raw',
                    'start' => $start->toIso8601ZuluString(),
                    'end' => $end->toIso8601ZuluString(),
                    'sample_rate_hz' => $ppgHz,
                    'ppg' => BiosignalSimulator::ppgFromIbi($w['ibi_ms'], $ppgHz),
                    'src' => 'banglejs2',
                ]
                : [
                    'kind' => 'ibi',
                    'start' => $start->toIso8601ZuluString(),
                    'end' => $end->toIso8601ZuluString(),
                    'ibi_ms' => $w['ibi_ms'],
                    'accel_counts' => $w['accel_counts'],
                    'confidence' => $seg['state'] === 'rest' ? 0.7 : 0.95,
                ];
            $allIbi = array_merge($allIbi, $w['ibi_ms']);
            if ($seg['state'] !== 'rest' && count($w['ibi_ms']) > 5) {
                // 5-min median HR proxy → RHR is the min of these (§4).
                $rhrMedians[] = BiosignalSimulator::meanHr($w['ibi_ms']);
            }

            $cursor = $end;
            $bar->advance();
            $speed = (float) $this->option('speed');
            if ($speed > 0 && ! app()->runningUnitTests()) {
                usleep((int) (20000 / max(0.1, $speed)));
            }
        }
        $bar->finish();
        $this->newLine(2);

        $rmssd = BiosignalSimulator::rmssd($allIbi);
        $rhr = $rhrMedians ? min($rhrMedians) : BiosignalSimulator::meanHr($allIbi);

        // --- Report what the band produced ---
        $this->table(['Metric', 'Value'], [
            ['Bedtime → wake', $bedtime->format('H:i').' → '.$wake->format('H:i')." ({$tz})"],
            ['Time in bed', $summary['duration_min'].' min'],
            ['Deep / REM / Light / Awake', "{$summary['deep_min']} / {$summary['rem_min']} / {$summary['light_min']} / {$summary['awake_min']} min"],
            ['Sleep quality', $summary['quality'].'/100'],
            ['Whole-night RMSSD (HRV)', $rmssd.' ms'],
            ['Resting HR', $rhr.' bpm'],
            ['IBI beats captured', number_format(count($allIbi))],
            ['Shape-A windows', count($windows)],
        ]);

        if ($this->option('dry')) {
            $this->warn('Dry run -- not POSTing to the ingestion API.');

            return self::SUCCESS;
        }

        // --- Drive the REAL pipeline: signed Shape-A + Shape-C to /api/devices/ingest ---
        $date = $wake->toDateString();
        $payloadA = [
            'batch_uid' => (string) Str::ulid(),
            'device_id' => $device->device_id,
            'timezone' => $tz,
            'windows' => $windows,
        ];
        $payloadC = [
            'batch_uid' => (string) Str::ulid(),
            'device_id' => $device->device_id,
            'timezone' => $tz,
            'summaries' => [
                // The watch-ended flow sends a confirmed sleep_session marker (no stages — the SERVER
                // stages from the raw windows). Otherwise ship the pre-baked Shape-C sleep summary.
                $this->option('confirmed')
                    ? ['kind' => 'sleep_session', 'confirmed' => true, 'bedtime' => $bedtime->timestamp, 'wake' => $wake->timestamp]
                    : [
                        'kind' => 'sleep',
                        'date' => $date,
                        'duration_min' => $summary['duration_min'],
                        'deep_min' => $summary['deep_min'],
                        'rem_min' => $summary['rem_min'],
                        'light_min' => $summary['light_min'],
                        'awake_min' => $summary['awake_min'],
                        'bedtime' => $bedtime->format('H:i'),
                        'wake_time' => $wake->format('H:i'),
                        'quality' => $summary['quality'],
                    ],
                ['kind' => 'recovery', 'date' => $date, 'hrv_ms' => (int) round($rmssd), 'resting_hr' => $rhr],
                // A plausible day of ambient movement (steps + a realistic hourly profile) so the
                // steps goal AND circadian rhythm populate in the demo.
                ['kind' => 'activity', 'date' => $date, 'steps' => random_int(5200, 11500),
                    'mvpa_min' => random_int(18, 55), 'floors' => random_int(3, 16),
                    'hourly' => $this->hourlyActivityProfile()],
            ],
        ];

        $okA = $this->postSigned($device->device_id, $secret, $payloadA, 'Shape-A IBI windows');
        $okC = $this->postSigned($device->device_id, $secret, $payloadC, 'Shape-C nightly summary');

        if ($okA || $okC) {
            $this->newLine();
            $this->info('Night streamed to Titan. Check Recovery / Sleep / Coach to see the worn device reflected.');
        } else {
            $this->newLine();
            $this->warn('Ingestion API unreachable -- the night was generated but not stored. (The ingestion build may not be merged yet.)');
        }

        return self::SUCCESS;
    }

    /**
     * A realistic 24 h activity profile (counts per hour, midnight→midnight): near-zero overnight,
     * a morning and an evening peak, moderate daytime -- gives a strong day/night contrast (high RA).
     *
     * @return array<int,int>
     */
    private function hourlyActivityProfile(): array
    {
        $shape = [2, 1, 1, 1, 1, 3, 25, 80, 95, 60, 55, 70, 75, 50, 45, 55, 70, 90, 85, 60, 40, 20, 8, 3];
        $out = [];
        foreach ($shape as $base) {
            $out[] = max(0, (int) round($base * (0.85 + mt_rand(0, 30) / 100)));
        }

        return $out;
    }

    private function resolveProfile(): ?Profile
    {
        if ($id = $this->option('profile')) {
            return Profile::find($id);
        }

        return Profile::query()->orderBy('id')->first();
    }

    /**
     * Find-or-create this profile's simulator band connection. Mirrors the documented
     * pairing model: a `device_id` + a 32-byte secret whose sha256 is stored. We keep
     * the plaintext secret cached locally (config/runtime) only for the simulator so it
     * can sign -- the real hardware shows it once. For a freshly minted device we know
     * the secret; for an existing one we re-mint so signing still works headlessly.
     *
     * @return array{0: WearableConnection, 1: string}
     */
    private function resolveDevice(Profile $profile): array
    {
        $device = WearableConnection::where('profile_id', $profile->id)
            ->where('source', 'titan_band')
            ->where('device_id', 'like', 'tb_sim_%')
            ->first();

        // Always (re)mint a secret we can sign with; store its hash. (Local sim only.)
        $secret = bin2hex(random_bytes(16));

        if (! $device) {
            $device = WearableConnection::create([
                'profile_id' => $profile->id,
                'provider' => 'titan_band',
                'source' => 'titan_band',
                'device_id' => 'tb_sim_'.Str::lower(Str::random(10)),
                'device_token_hash' => hash('sha256', $secret),
                'timezone' => config('app.timezone', 'UTC'),
                'status' => 'connected',
                'scopes' => ['ibi', 'accel', 'sleep', 'recovery'],
            ]);
            $this->line("Paired a new simulator band: <comment>{$device->device_id}</comment>");
        } else {
            $device->update(['device_token_hash' => hash('sha256', $secret), 'status' => 'connected']);
        }

        return [$device, $secret];
    }

    /**
     * POST a signed batch to /api/devices/ingest. Signature scheme is identical to
     * TerraClient::verifySignature(): X-Titan-Signature: t=<ts>,v1=HMAC_SHA256("<ts>.<body>", secret).
     */
    private function postSigned(string $deviceId, string $secret, array $payload, string $label): bool
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $ts = (string) time();
        // Server verifies with device_token_hash = sha256(secret) as the HMAC key.
        $sig = 't='.$ts.',v1='.hash_hmac('sha256', $ts.'.'.$body, hash('sha256', $secret));
        $url = rtrim(config('app.url', 'http://localhost'), '/').'/api/devices/ingest';

        try {
            $res = Http::withHeaders([
                'X-Device-Id' => $deviceId,
                'X-Titan-Signature' => $sig,
                'Content-Type' => 'application/json',
            ])->timeout(20)->withBody($body, 'application/json')->post($url);

            if ($res->successful()) {
                $this->line("  <info>✓</info> {$label} → {$res->status()} ".trim($res->body()));

                return true;
            }
            $this->line("  <fg=red>✗</> {$label} → {$res->status()} ".Str::limit($res->body(), 120));
        } catch (\Throwable $e) {
            $this->line("  <fg=yellow>…</> {$label} → not delivered ({$e->getMessage()})");
        }

        return false;
    }
}
