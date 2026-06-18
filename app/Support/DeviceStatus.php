<?php

namespace App\Support;

use App\Models\Profile;
use Illuminate\Support\Carbon;

/**
 * The coach's window into the WEARABLE itself — is the band paired, connected, and syncing; how long
 * since it last phoned home; its battery and firmware; what it's primed to sense; and which data
 * streams are currently flowing. This is what lets the AI oversee the device, not just its numbers:
 * "is my band synced?", "why don't I have sleep today?", "what's my battery?".
 */
class DeviceStatus
{
    /** @return array<string,mixed> */
    public static function assess(Profile $profile, ?Carbon $now = null): array
    {
        $now = $now ?: Carbon::now();

        $conn = class_exists(\App\Models\WearableConnection::class)
            ? $profile->wearableConnections()->orderByDesc('last_sync_at')->orderByDesc('id')->first()
            : null;

        if (! $conn) {
            return [
                'paired' => false, 'connected' => false, 'verdict' => 'unpaired',
                'verdict_label' => 'No band paired', 'source' => null,
                'last_sync_ago' => null, 'battery_pct' => null, 'firmware' => null,
                'primed' => self::primed($profile), 'streams' => self::streams($profile, $now),
                'guidance' => 'Pair the Titan band from Devices to start streaming your vitals into the coach.',
            ];
        }

        $sync = $conn->last_sync_at;
        $mins = $sync ? $sync->diffInMinutes($now) : null;
        $verdict = match (true) {
            $sync === null => 'never',
            $mins <= 90 => 'live',
            $mins <= 60 * 26 => 'today',
            $mins <= 60 * 72 => 'stale',
            default => 'offline',
        };
        $label = [
            'live' => 'Live — syncing', 'today' => 'Synced today', 'stale' => "Hasn't synced in a while",
            'offline' => 'Offline', 'never' => 'Paired, not synced yet',
        ][$verdict];

        $guidance = match ($verdict) {
            'never' => 'Wear the band and open the Titan app on it to kick off its first sync.',
            'stale', 'offline' => 'Open the Titan app on the band and bring it near your phone to sync.',
            default => null,
        };

        return [
            'paired' => true,
            'connected' => $conn->isConnected() && in_array($verdict, ['live', 'today'], true),
            'verdict' => $verdict,
            'verdict_label' => $label,
            'source' => self::sourceLabel($conn),
            'last_sync_at' => $sync?->toIso8601String(),
            'last_sync_ago' => $sync?->diffForHumans($now),
            'battery_pct' => $conn->battery_pct,
            'firmware' => $conn->firmware,
            'last_data' => $conn->last_payload_type,
            'primed' => self::primed($profile),
            'streams' => self::streams($profile, $now),
            'guidance' => $guidance,
        ];
    }

    public static function sourceLabel($conn): string
    {
        $s = strtolower((string) ($conn->source ?: $conn->provider ?: ''));

        return match (true) {
            str_contains($s, 'titan') => 'Titan band',
            str_contains($s, 'bangle') => 'Bangle.js',
            str_contains($s, 'polar') => 'Polar',
            str_contains($s, 'apple') => 'Apple Health',
            str_contains($s, 'terra') => 'Connected device',
            default => $conn->source ?: 'Wearable',
        };
    }

    /** The activity the band is currently primed to sense (set by start_activity), if any. */
    private static function primed(Profile $profile): ?array
    {
        $a = data_get($profile->settings, 'active_activity');
        if (! is_array($a) || empty($a['type'])) {
            return null;
        }

        return ['type' => $a['type'], 'since' => isset($a['started']) ? rescue(fn () => Carbon::parse($a['started'])->diffForHumans(), null, false) : null];
    }

    /** Which data streams have flowed in the last ~36h (so the coach knows what the band is delivering). */
    private static function streams(Profile $profile, Carbon $now): array
    {
        $since = $now->copy()->subHours(36);
        $out = [];
        if (class_exists(\App\Models\RecoveryLog::class) && $profile->recoveryLogs()->where('logged_at', '>=', $since->toDateString())->exists()) {
            $out[] = 'recovery';
        }
        if (class_exists(\App\Models\SleepLog::class) && $profile->sleepLogs()->where('slept_at', '>=', $since->toDateString())->exists()) {
            $out[] = 'sleep';
        }
        if (class_exists(\App\Models\DailyActivity::class) && $profile->dailyActivity()->where('date', '>=', $since->toDateString())->exists()) {
            $out[] = 'activity';
        }

        return $out;
    }
}
