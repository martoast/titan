<?php

namespace App\Console\Commands;

use App\Models\WearableConnection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * End-to-end smoke test of the ingestion pipeline: takes a fixture batch, signs it with
 * a device's secret exactly as the firmware would, and POSTs it to /api/devices/ingest.
 *
 *   php artisan devices:simulate                 # Shape-C summary (no Python needed)
 *   php artisan devices:simulate --shape=ibi     # Shape-A IBI → biosignal service
 *   php artisan devices:simulate --device=titan_band_fixture --secret=…
 *
 * The HMAC scheme matches TerraClient::verifyDeviceSignature(): the device hashes its
 * 32-byte secret (sha256) to derive the shared key, then signs "<ts>.<body>" with it —
 * keeping the plaintext secret off the server while both sides share a stable key. By
 * default it uses the fixture devices/secrets that WearableSeeder pairs.
 */
class SimulateDeviceBatch extends Command
{
    protected $signature = 'devices:simulate
        {--shape=summary : ibi | summary}
        {--device= : device_id to sign as (defaults to the matching fixture device)}
        {--secret= : the device secret (defaults to the matching fixture secret)}
        {--url= : base URL of the app (defaults to APP_URL)}';

    protected $description = 'POST a signed sample biosignal batch through the ingestion pipeline.';

    public function handle(): int
    {
        $shape = $this->option('shape') === 'ibi' ? 'ibi' : 'summary';

        [$fixtureFile, $defaultDevice, $defaultSecret] = $shape === 'ibi'
            ? ['night_ibi.json', 'titan_band_fixture', 'titan-band-fixture-secret']
            : ['summary_shape_c.json', 'apple_health_fixture', 'apple-health-fixture-secret'];

        $path = database_path("fixtures/wearable/{$fixtureFile}");
        if (! is_file($path)) {
            $this->error("Fixture not found: {$path}");

            return self::FAILURE;
        }

        $payload = json_decode((string) file_get_contents($path), true);
        unset($payload['_comment']);
        // Fresh batch_uid each run so we exercise the real (non-duplicate) path.
        $payload['batch_uid'] = (string) Str::ulid();

        $deviceId = $this->option('device') ?: $defaultDevice;
        $secret = $this->option('secret') ?: $defaultSecret;

        // The shared HMAC key is sha256(secret) — exactly the device_token_hash the
        // server stores. So we sign with sha256(secret); the plaintext secret never
        // leaves the device.
        $sharedKey = hash('sha256', $secret);

        $connection = WearableConnection::where('device_id', $deviceId)->first();
        if (! $connection) {
            $this->warn("No paired device '{$deviceId}'. Run the WearableSeeder first (or pass --device/--secret).");
        }

        $body = json_encode($payload);
        $ts = (string) time();
        $hmac = hash_hmac('sha256', $ts.'.'.$body, $sharedKey);
        $signature = "t={$ts},v1={$hmac}";

        $base = rtrim((string) ($this->option('url') ?: config('app.url', 'http://localhost')), '/');

        $this->line("→ POST {$base}/api/devices/ingest  (shape={$shape}, device={$deviceId})");

        $response = Http::withHeaders([
            'X-Device-Id' => $deviceId,
            'X-Titan-Signature' => $signature,
            'Content-Type' => 'application/json',
        ])->withBody($body, 'application/json')
            ->post("{$base}/api/devices/ingest");

        $this->line("← {$response->status()} ".$response->body());

        return $response->successful() ? self::SUCCESS : self::FAILURE;
    }
}
