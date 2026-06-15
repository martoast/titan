<?php

namespace App\Http\Controllers\Wellness;

use App\Http\Controllers\Controller;
use App\Models\WearableConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The /devices page: the owner's view of their connected biosignal sources. Pair a new
 * device (issuing a device_id + one-time secret), see each connection's status + last
 * sync, and disconnect. The actual signed data flows through the API (routes/api.php);
 * this controller is purely the human-facing management surface.
 */
class DeviceController extends Controller
{
    /** Sources a user can pair from the UI. (Terra has its own connect widget.) */
    private const PAIRABLE = [
        'titan_band' => 'Titan Band',
        'bangle' => 'Bangle.js',
        'polar' => 'Polar',
        'apple_health' => 'Apple Health',
    ];

    public function index(Request $request)
    {
        $profile = $request->user()->ensureProfile();

        $connections = $profile->wearableConnections()
            ->where('source', '!=', 'terra')
            ->orderByDesc('created_at')
            ->get();

        $lastIngestion = $profile->deviceIngestions()->orderByDesc('created_at')->first();

        // The Apple Health connection (if any), for the import card's "last import" line.
        $appleHealth = $connections->firstWhere('source', 'apple_health');

        return view('devices.index', [
            'connections' => $connections,
            'pairable' => self::PAIRABLE,
            'lastIngestion' => $lastIngestion,
            // One-time secret surfaced right after pairing (flashed, never persisted).
            'justPaired' => session('just_paired'),
            // Apple Health import: last-import summary (flashed) + existing connection.
            'appleHealth' => $appleHealth,
            'appleHealthSummary' => session('apple_health_summary'),
            'appleHealthError' => session('apple_health_error'),
            // Polar connect: only offer the button when dev creds are present.
            'polarConfigured' => (bool) config('services.polar.client_id'),
            'polarConnection' => $connections->firstWhere('source', 'polar'),
        ]);
    }

    /**
     * The live Bluetooth bridge: a Web Bluetooth page that connects directly to a
     * Bangle.js running titan-stream.js, reassembles its raw-PPG windows, signs each
     * batch with the device's HMAC secret, and POSTs to /api/devices/ingest — the
     * desktop/Android "prove the loop" path (iOS needs a native companion later).
     *
     * The device_id + one-time secret are held client-side (localStorage), seeded from
     * the just-paired flash. They never round-trip back to the server in the clear.
     */
    public function bridge(Request $request)
    {
        $profile = $request->user()->ensureProfile();

        $bangles = $profile->wearableConnections()
            ->where('source', 'bangle')
            ->where('status', 'connected')
            ->orderByDesc('created_at')
            ->get(['id', 'device_id', 'last_payload_type', 'last_sync_at']);

        return view('devices.bridge', [
            'bangles' => $bangles,
            'ingestUrl' => url('/api/devices/ingest'),
            // One-time secret if the user just paired and clicked through to the bridge.
            'justPaired' => session('just_paired'),
        ]);
    }

    /**
     * The validation lab: capture a Bangle.js and a Polar H10 (reference) over the same
     * window, then compare their HRV beat-for-beat. The Polar's RR intervals are computed
     * client-side (they're already gold-standard IBI); the Bangle's raw PPG is run through
     * the real server pipeline via hrvPreview() so we validate what Titan actually ships.
     */
    public function validate(Request $request)
    {
        $request->user()->ensureProfile();

        return view('devices.validate');
    }

    /**
     * Synchronous HRV compute for the validation lab: a logged-in user POSTs one raw
     * window ({ppg, sample_rate_hz}) and gets the REAL biosignal-service metrics straight
     * back (no queue, no HMAC — session-auth'd). Only used for live validation/preview,
     * never the ingestion path.
     */
    public function hrvPreview(Request $request, \App\Services\Wearables\BiosignalClient $biosignal)
    {
        $request->user()->ensureProfile();

        $data = $request->validate([
            'ppg' => ['required', 'array', 'min:30', 'max:60000'],
            'ppg.*' => ['numeric'],
            'sample_rate_hz' => ['required', 'integer', 'min:1', 'max:1000'],
            'start' => ['nullable', 'string'],
            'end' => ['nullable', 'string'],
        ]);

        if (! $biosignal->configured()) {
            return response()->json(['error' => 'biosignal service not configured'], 503);
        }

        try {
            $result = $biosignal->processHrv([
                'kind' => 'ppg_raw',
                'ppg' => array_map('floatval', $data['ppg']),
                'sample_rate_hz' => $data['sample_rate_hz'],
                'start' => $data['start'] ?? null,
                'end' => $data['end'] ?? null,
            ]);

            return response()->json($result['metrics'] ?? $result);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'compute failed: '.$e->getMessage()], 502);
        }
    }

    /**
     * Pair a device. Mirrors the API pair endpoint but redirects back to the page with
     * the one-time secret flashed so it can be shown once. After this, only the sha256
     * hash remains server-side.
     */
    public function pair(Request $request)
    {
        $profile = $request->user()->ensureProfile();

        $data = $request->validate([
            'source' => ['required', 'string', 'in:'.implode(',', array_keys(self::PAIRABLE))],
            'label' => ['nullable', 'string', 'max:80'],
        ]);

        $deviceId = $data['source'].'_'.strtolower((string) Str::ulid());
        $secret = bin2hex(random_bytes(32));

        $connection = WearableConnection::create([
            'profile_id' => $profile->id,
            'provider' => strtoupper($data['source']),
            'source' => $data['source'],
            'device_id' => $deviceId,
            'device_token_hash' => hash('sha256', $secret),
            'timezone' => $profile->settings['timezone'] ?? config('app.timezone', 'UTC'),
            'status' => 'connected',
            'last_payload_type' => $data['label'] ?: null,
        ]);

        return redirect('/devices')->with('just_paired', [
            'device_id' => $deviceId,
            'secret' => $secret,
            'source' => self::PAIRABLE[$data['source']],
            'connection_id' => $connection->id,
        ]);
    }

    public function destroy(Request $request, WearableConnection $connection)
    {
        $profile = $request->user()->ensureProfile();
        abort_unless($connection->profile_id === $profile->id, 403);

        $connection->update(['status' => 'disconnected', 'device_token_hash' => null]);

        return redirect('/devices')->with('status', 'Device disconnected.');
    }
}
