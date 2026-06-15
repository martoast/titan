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

        return view('devices.index', [
            'connections' => $connections,
            'pairable' => self::PAIRABLE,
            'lastIngestion' => $lastIngestion,
            // One-time secret surfaced right after pairing (flashed, never persisted).
            'justPaired' => session('just_paired'),
        ]);
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
