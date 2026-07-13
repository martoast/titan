<?php

namespace App\Services\Glucose;

use App\Models\GlucoseReading;
use App\Models\Profile;
use App\Support\GlucoseConnection;
use Illuminate\Support\Carbon;

/**
 * Pulls a profile's latest glucose from its configured provider and upserts into `glucose_readings`
 * (CGM_INTEGRATION P1). Incremental (since the last stored reading), dedup by (profile_id, taken_at) so
 * backfill / out-of-order / overlap are all safe.
 */
class GlucoseSync
{
    /** Sync one profile → number of readings written. 0 if not configured/enabled or nothing new. */
    public function syncProfile(Profile $profile): int
    {
        $cfg = GlucoseConnection::config($profile);
        if ($cfg === null || ! $cfg['enabled']) {
            return 0;
        }
        $provider = $this->providerFor($cfg['provider']);
        if ($provider === null) {
            return 0;
        }

        // Incremental: from just after the last reading (small overlap absorbs out-of-order); else last 24h.
        $last = $profile->glucoseReadings()->max('taken_at');
        $since = $last ? Carbon::parse($last)->subMinutes(10) : Carbon::now()->subDay();

        $written = 0;
        foreach ($provider->fetchSince($profile, $since) as $r) {
            GlucoseReading::updateOrCreate(
                ['profile_id' => $profile->id, 'taken_at' => $r['taken_at']],
                [
                    'mg_dl' => $r['mg_dl'],
                    'trend' => $r['trend'],
                    'source' => $cfg['provider'],
                    'device' => $r['device'] ?? null,
                    'raw' => $r['raw'] ?? null,
                ],
            );
            $written++;
        }

        return $written;
    }

    private function providerFor(string $provider): ?GlucoseProvider
    {
        return match ($provider) {
            'nightscout' => new NightscoutProvider(),
            // 'dexcom' => new DexcomProvider(),   // later; HealthKit ingests via HealthIngestService, not here
            default => null,
        };
    }
}
