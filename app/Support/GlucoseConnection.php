<?php

namespace App\Support;

use App\Models\Profile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Per-profile CGM connection config, stored under settings['glucose'] (CGM_INTEGRATION P1). The Nightscout
 * token is ENCRYPTED at rest (the settings column is plain JSON) and NEVER logged — same discipline as the
 * BIOSIGNAL_TOKEN / device secrets.
 */
class GlucoseConnection
{
    /** The non-secret config for a profile, or null when glucose isn't set up. Token is NOT included. */
    public static function config(Profile $profile): ?array
    {
        $g = $profile->settings['glucose'] ?? null;
        if (! is_array($g) || empty($g['provider'])) {
            return null;
        }

        return [
            'provider' => $g['provider'],
            'nightscout_url' => $g['nightscout_url'] ?? null,
            'enabled' => (bool) ($g['enabled'] ?? false),
            'has_token' => ! empty($g['nightscout_token']),
        ];
    }

    /** The decrypted Nightscout token, or null. Never log the return value. */
    public static function token(Profile $profile): ?string
    {
        $enc = $profile->settings['glucose']['nightscout_token'] ?? null;
        if (! $enc) {
            return null;
        }
        try {
            return Crypt::decryptString($enc);
        } catch (\Throwable $e) {
            Log::warning('[glucose] token decrypt failed', ['profile' => $profile->id]);   // never log the value

            return null;
        }
    }

    /**
     * Set (or update) the connection. A provided $token is encrypted; pass null to keep the existing one.
     * @return array the non-secret config after the change
     */
    public static function set(Profile $profile, string $provider, ?string $nightscoutUrl, ?string $token, bool $enabled): array
    {
        $settings = $profile->settings ?? [];
        $glucose = $settings['glucose'] ?? [];
        $glucose['provider'] = $provider;
        $glucose['enabled'] = $enabled;
        if ($nightscoutUrl !== null) {
            $glucose['nightscout_url'] = rtrim(trim($nightscoutUrl), '/');
        }
        if ($token !== null && trim($token) !== '') {
            $glucose['nightscout_token'] = Crypt::encryptString(trim($token));
        }
        $settings['glucose'] = $glucose;
        $profile->update(['settings' => $settings]);

        return self::config($profile->fresh()) ?? [];
    }

    /** Profiles with glucose sync enabled (for the scheduled poll). */
    public static function enabledProfiles()
    {
        return Profile::whereNotNull('settings')->get()
            ->filter(fn (Profile $p) => ($p->settings['glucose']['enabled'] ?? false) === true)
            ->values();
    }
}
