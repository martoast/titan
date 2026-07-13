<?php

namespace App\Services\Glucose;

use App\Models\Profile;
use App\Support\GlucoseConnection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Nightscout CGM source (CGM_INTEGRATION P1, the flagship open path). Polls the user's own Nightscout
 * `GET /api/v1/entries.json` with their token, maps `sgv`/`date`/`direction` to normalized readings. The
 * mapping is pure (unit-tested); only the HTTP fetch touches the network. Token is never logged.
 */
class NightscoutProvider implements GlucoseProvider
{
    private const MAX_ENTRIES = 2000;   // ~a week of 5-min data in one incremental pull

    public function fetchSince(Profile $profile, Carbon $since): array
    {
        $cfg = GlucoseConnection::config($profile);
        if (($cfg['provider'] ?? null) !== 'nightscout' || empty($cfg['nightscout_url'])) {
            return [];
        }
        $url = rtrim($cfg['nightscout_url'], '/').'/api/v1/entries.json';
        $params = array_filter([
            'count' => self::MAX_ENTRIES,
            'find[dateString][$gte]' => $since->clone()->utc()->toIso8601String(),
            'token' => GlucoseConnection::token($profile),   // Nightscout accepts ?token= for read auth
        ], fn ($v) => $v !== null);

        try {
            $resp = Http::timeout(12)->acceptJson()->get($url, $params);
        } catch (\Throwable $e) {
            Log::warning('[glucose] nightscout fetch failed', ['profile' => $profile->id, 'error' => $e->getMessage()]);

            return [];
        }
        if (! $resp->ok()) {
            Log::warning('[glucose] nightscout non-200', ['profile' => $profile->id, 'status' => $resp->status()]);

            return [];
        }

        $out = [];
        foreach ((array) $resp->json() as $entry) {
            if (is_array($entry) && ($r = self::mapEntry($entry)) !== null) {
                $out[] = $r;
            }
        }

        return $out;
    }

    /**
     * A Nightscout entry → a normalized reading, or null when it isn't a usable sensor-glucose value.
     * Pure. `date` is ms-epoch (preferred); `dateString` is the ISO fallback. Values are sanity-bounded.
     *
     * @return array{taken_at:Carbon,mg_dl:int,trend:?string,device:?string,raw:array}|null
     */
    public static function mapEntry(array $e): ?array
    {
        $sgv = $e['sgv'] ?? null;
        if (! is_numeric($sgv)) {
            return null;   // not a sensor-glucose entry (e.g. a calibration/mbg row)
        }
        $mg = (int) round((float) $sgv);
        if ($mg <= 0 || $mg > 600) {
            return null;   // out of any real CGM range → drop
        }

        $takenAt = null;
        if (isset($e['date']) && is_numeric($e['date'])) {
            $takenAt = Carbon::createFromTimestampMs((int) $e['date'], 'UTC');
        } elseif (! empty($e['dateString'])) {
            try {
                $takenAt = Carbon::parse($e['dateString'])->utc();
            } catch (\Throwable) {
                $takenAt = null;
            }
        }
        if ($takenAt === null) {
            return null;
        }

        return [
            'taken_at' => $takenAt,
            'mg_dl' => $mg,
            'trend' => self::mapDirection($e['direction'] ?? null),
            'device' => $e['device'] ?? null,
            'raw' => $e,
        ];
    }

    /** Nightscout's `direction` arrow → our canonical trend vocabulary. */
    public static function mapDirection(?string $d): ?string
    {
        return match ($d) {
            'DoubleUp' => 'rising_fast',
            'SingleUp' => 'rising',
            'FortyFiveUp' => 'rising_slow',
            'Flat' => 'flat',
            'FortyFiveDown' => 'falling_slow',
            'SingleDown' => 'falling',
            'DoubleDown' => 'falling_fast',
            default => null,
        };
    }
}
