<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;
use ZipArchive;

/**
 * F-DEV-04 — Apple Health "Export All Health Data" import at POST /devices/apple-health/import.
 */
class AppleHealthImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_import(): void
    {
        $this->post(route('devices.apple-health.import'))->assertRedirect('/login');
    }

    public function test_missing_file_returns_a_friendly_error(): void
    {
        $user = User::factory()->create();
        $user->ensureProfile();

        $resp = $this->actingAs($user)->post(route('devices.apple-health.import'), []);

        $resp->assertRedirect();
        $resp->assertSessionHas('apple_health_error', 'Choose your Apple Health export.zip first.');
    }

    public function test_non_zip_upload_is_rejected_with_guidance(): void
    {
        $user = User::factory()->create();
        $user->ensureProfile();

        $notZip = UploadedFile::fake()->createWithContent('notes.txt', 'just some text');

        $resp = $this->actingAs($user)->post(route('devices.apple-health.import'), ['export' => $notZip]);

        $resp->assertRedirect();
        $resp->assertSessionHas('apple_health_error', fn ($msg) => str_contains($msg, 'does not look like a .zip'));
    }

    public function test_valid_export_zip_is_imported_and_summarised(): void
    {
        $user = User::factory()->create();
        $profile = $user->ensureProfile();

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<HealthData>'."\n"
            .'<Record type="HKQuantityTypeIdentifierRestingHeartRate" unit="count/min" startDate="2026-06-01 07:00:00 +0000" endDate="2026-06-01 07:00:00 +0000" value="54"/>'."\n"
            .'<Record type="HKQuantityTypeIdentifierBodyMass" unit="kg" startDate="2026-06-01 07:05:00 +0000" endDate="2026-06-01 07:05:00 +0000" value="80.5"/>'."\n"
            .'</HealthData>';
        $zipPath = $this->makeExportZip($xml);

        $upload = new UploadedFile($zipPath, 'export.zip', 'application/zip', null, true);

        $resp = $this->actingAs($user)->post(route('devices.apple-health.import'), ['export' => $upload]);

        $resp->assertRedirect();
        $resp->assertSessionMissing('apple_health_error');
        $resp->assertSessionHas('apple_health_summary', fn ($summary) => is_array($summary) && $summary['error'] === null && $summary['samples'] >= 2);

        // The resting-HR + body-mass records should have landed in canonical tables.
        $this->assertDatabaseHas('body_metrics', ['profile_id' => $profile->id]);
    }

    /** Build a temp export.zip containing the given export.xml content. */
    private function makeExportZip(string $xml): string
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'ah_').'.zip';
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        // Apple nests export.xml under an "apple_health_export/" folder; the importer scans for it.
        $zip->addFromString('apple_health_export/export.xml', $xml);
        $zip->close();

        return $zipPath;
    }
}
