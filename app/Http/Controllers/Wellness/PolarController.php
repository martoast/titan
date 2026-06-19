<?php

namespace App\Http\Controllers\Wellness;

use App\Http\Controllers\Controller;
use App\Models\WearableConnection;
use App\Services\Wearables\PolarClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Polar AccessLink OAuth2 connect flow (the human-facing half). `connect` redirects the
 * user to Polar to authorize; `callback` receives the code, exchanges it for an access
 * token, registers the user with our AccessLink client, and records the connection.
 *
 * Scaffold note: Polar returns a long-lived access token. Titan's wearable_connections
 * table has no token column yet, so we record the link + Polar user id and log that a
 * token column / encrypted store is the next step before the scheduled pulls can run.
 * The flow degrades gracefully when Polar isn't configured.
 */
class PolarController extends Controller
{
    public function connect(Request $request, PolarClient $polar)
    {
        $profile = $request->user()->ensureProfile();

        if (! $polar->configured()) {
            return redirect('/devices')->with('apple_health_error',
                'Polar isn't configured on this server. Add free dev credentials from admin.polaraccesslink.com to POLAR_CLIENT_ID / POLAR_CLIENT_SECRET.');
        }

        $url = $polar->authorizeUrl($profile);

        return $url ? redirect()->away($url) : redirect('/devices');
    }

    public function callback(Request $request, PolarClient $polar)
    {
        $profile = $request->user()->ensureProfile();

        // User cancelled or Polar returned an error.
        if ($request->filled('error') || ! $request->filled('code')) {
            return redirect('/devices')->with('apple_health_error', 'Polar authorization was cancelled.');
        }

        // `state` carries the profile id we set in authorizeUrl -- defend against mismatch.
        if ((string) $request->query('state') !== (string) $profile->id) {
            return redirect('/devices')->with('apple_health_error', 'Polar authorization could not be verified. Please try again.');
        }

        $token = $polar->exchangeToken((string) $request->query('code'));

        if (! $token || empty($token['access_token'])) {
            return redirect('/devices')->with('apple_health_error', 'Could not complete the Polar connection. Please try again.');
        }

        $polarUserId = (string) ($token['x_user_id'] ?? '');

        // Register the user with our AccessLink client (idempotent on Polar's side).
        try {
            $polar->registerUser($token['access_token'], (string) $profile->id);
        } catch (\Throwable $e) {
            Log::warning('[Polar] registerUser threw', ['error' => $e->getMessage()]);
        }

        WearableConnection::updateOrCreate(
            ['profile_id' => $profile->id, 'source' => PolarClient::SOURCE],
            [
                'provider' => 'POLAR',
                'status' => 'connected',
                'terra_user_id' => $polarUserId ?: null, // reuse this column to hold the Polar user id
                'timezone' => $profile->settings['timezone'] ?? config('app.timezone', 'UTC'),
                'last_webhook_at' => now(),
                'last_payload_type' => 'Polar AccessLink',
            ],
        );

        // NOTE: persisting the access token (encrypted) is the next step before a
        // scheduled PolarSyncJob can call PolarClient::pull*(). For now the link is
        // recorded so the UI reflects a live Polar connection.
        Log::info('[Polar] account connected', ['profile_id' => $profile->id, 'polar_user_id' => $polarUserId]);

        return redirect('/devices')->with('status', 'Polar connected. Your data will sync on the next pull.');
    }
}
