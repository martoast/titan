<?php

namespace Database\Seeders;

use App\Models\Profile;
use App\Models\WearableConnection;
use App\Services\Wearables\DeviceIngestionService;
use Illuminate\Database\Seeder;

/**
 * Seeds the Titan Wearable platform for profile 1: a paired Titan band + a paired
 * Apple Health source, and runs the Shape-C summary fixture through the real ingestion
 * service so recovery_logs / sleep_logs / body_metrics gain a wearable-sourced row
 * (updated_via=device:summary) — with NO Python dependency.
 *
 * The Shape-A IBI fixture is NOT auto-run here (it needs the biosignal service); use
 * `php artisan devices:simulate --shape=ibi` once that's up. Idempotent: re-pairing is
 * skipped when a fixture device already exists.
 *
 * Called by the orchestrator — does NOT touch DatabaseSeeder.
 */
class WearableSeeder extends Seeder
{
    public function run(): void
    {
        $profile = Profile::find(1) ?? Profile::orderBy('id')->first();
        if (! $profile) {
            return;
        }

        // A paired Titan band. device_token_hash = sha256(secret) — the same value the
        // device signs with (it never sends the plaintext secret). devices:simulate signs
        // the band fixture with sha256('titan-band-fixture-secret').
        WearableConnection::updateOrCreate(
            ['device_id' => 'titan_band_fixture'],
            [
                'profile_id' => $profile->id,
                'provider' => 'TITAN_BAND',
                'source' => 'titan_band',
                'device_token_hash' => hash('sha256', 'titan-band-fixture-secret'),
                'timezone' => 'America/Mexico_City',
                'status' => 'connected',
                'last_payload_type' => 'Fixture band',
            ],
        );

        // A paired Apple Health source, used to ingest the Shape-C fixture below.
        $appleConnection = WearableConnection::updateOrCreate(
            ['device_id' => 'apple_health_fixture'],
            [
                'profile_id' => $profile->id,
                'provider' => 'APPLE_HEALTH',
                'source' => 'apple_health',
                'device_token_hash' => hash('sha256', 'apple-health-fixture-secret'),
                'timezone' => 'America/Mexico_City',
                'status' => 'connected',
                'last_payload_type' => 'Fixture export',
            ],
        );

        // Run the Shape-C summary fixture through the real pipeline (no Python needed).
        $fixture = database_path('fixtures/wearable/summary_shape_c.json');
        if (is_file($fixture)) {
            $payload = json_decode((string) file_get_contents($fixture), true);
            if (is_array($payload)) {
                app(DeviceIngestionService::class)->ingest($appleConnection, $payload);
            }
        }
    }
}
